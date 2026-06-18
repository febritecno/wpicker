<?php
/**
 * Vault Manager — atomic snapshot, restore, and prune operations.
 *
 * The Deployment Vault. Before any push applies files, the entire child theme
 * is copied to wp-content/uploads/wpicker-vault/<manifest_id>/. On rollback,
 * the snapshot is copied back (after taking a fresh safety snapshot).
 *
 * Atomicity: the snapshot directory is fully written before any live file is
 * touched. Restore overwrites live files only after a safety snapshot completes.
 *
 * @package WPicker\Vault
 */

declare( strict_types = 1 );

namespace WPicker\Vault;

use WPicker\Sync\PathGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Class Manager
 */
final class Manager {

	/**
	 * Manifest store.
	 *
	 * @var Manifest
	 */
	private $manifests;

	/**
	 * Path guard.
	 *
	 * @var PathGuard
	 */
	private $guard;

	/**
	 * Constructor.
	 *
	 * @param Manifest  $manifests Manifest store.
	 * @param PathGuard $guard     Path guard.
	 */
	public function __construct( Manifest $manifests, PathGuard $guard ) {
		$this->manifests = $manifests;
		$this->guard     = $guard;
	}

	/**
	 * Root directory for all snapshots.
	 *
	 * @return string Absolute path.
	 */
	public function root(): string {
		$uploads = wp_upload_dir();
		$base    = trailingslashit( $uploads['basedir'] ) . 'wpicker-vault';
		if ( ! is_dir( $base ) ) {
			wp_mkdir_p( $base );
		}
		// Guard the vault from web access.
		$htaccess = $base . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			@file_put_contents( $htaccess, "Order deny,allow\nDeny from all\n" );
		}
		return $base;
	}

	/**
	 * Directory for a specific manifest id.
	 *
	 * @param string $manifest_id Manifest id.
	 * @return string
	 */
	public function dir_for( string $manifest_id ): string {
		return $this->root() . '/' . $manifest_id;
	}

	/**
	 * Create a snapshot of the current child theme and record a manifest.
	 *
	 * @param array<string,mixed> $device Acting device.
	 * @param array<int,string>   $files  Relative files about to be affected.
	 * @param string              $kind   'push' | 'rollback_safety'.
	 * @return array{ id: string, path: string, manifest: array<string,mixed> }
	 *
	 * @throws \RuntimeException If the child theme dir is unreadable.
	 */
	public function snapshot( array $device, array $files, string $kind = 'push' ): array {
		$id           = $this->manifests->generate_id();
		$target       = $this->dir_for( $id );
		$theme_root   = $this->guard->theme_root();

		if ( ! is_dir( $theme_root ) ) {
			throw new \RuntimeException( 'Child theme directory not found.' );
		}

		if ( ! $this->copy_dir( $theme_root, $target ) ) {
			throw new \RuntimeException( 'Failed to create snapshot.' );
		}

		$manifest = $this->manifests->build_record( $id, $device, $files, $kind );
		$this->manifests->add( $manifest );

		return array(
			'id'       => $id,
			'path'     => $target,
			'manifest' => $manifest,
		);
	}

	/**
	 * Mark a manifest as applied (push succeeded) and prune old snapshots.
	 *
	 * @param string $id Manifest id.
	 * @return void
	 */
	public function mark_applied( string $id ): void {
		$manifest = $this->manifests->get( $id );
		if ( null === $manifest ) {
			return;
		}
		$manifest['status'] = 'applied';
		$this->manifests->update( $id, $manifest );

		$keep = (int) ( get_option( 'wpicker_settings', array() )['vault_keep'] ?? 30 );
		if ( $keep > 0 ) {
			$this->prune_to( $keep );
		}
	}

	/**
	 * Mark a manifest as aborted with an error log (lint/runtime failure).
	 *
	 * @param string              $id    Manifest id.
	 * @param array<string,mixed> $error Structured error.
	 * @return void
	 */
	public function mark_aborted( string $id, array $error ): void {
		$manifest = $this->manifests->get( $id );
		if ( null === $manifest ) {
			return;
		}
		$manifest['status'] = 'aborted';
		$manifest['error']  = $error;
		$this->manifests->update( $id, $manifest );
	}

	/**
	 * Restore files from a snapshot directory back into the child theme.
	 *
	 * Takes a fresh safety snapshot first (kind=rollback_safety) so the current
	 * (broken) state can itself be rolled back to.
	 *
	 * @param string              $manifest_id Snapshot manifest id to restore from.
	 * @param array<string,mixed> $device      Acting device.
	 * @return array{ restored: string, safety_manifest_id: string, files: array<int,string> }
	 *
	 * @throws \RuntimeException If the snapshot is missing.
	 */
	public function restore( string $manifest_id, array $device ): array {
		$source = $this->dir_for( $manifest_id );
		if ( ! is_dir( $source ) ) {
			throw new \RuntimeException( 'Snapshot not found: ' . $manifest_id );
		}

		$theme_root = $this->guard->theme_root();
		$files      = $this->list_relative_files( $source );

		// Safety snapshot of the current state.
		$safety = $this->snapshot( $device, $files, 'rollback_safety' );

		// Overwrite live theme with snapshot contents.
		if ( ! $this->copy_dir( $source, $theme_root ) ) {
			throw new \RuntimeException( 'Failed to restore snapshot.' );
		}

		// Bump restore count on the restored manifest.
		$m = $this->manifests->get( $manifest_id );
		if ( null !== $m ) {
			$m['restore_count'] = (int) ( $m['restore_count'] ?? 0 ) + 1;
			$m['status']        = 'restored';
			$this->manifests->update( $manifest_id, $m );
		}

		return array(
			'restored'           => $manifest_id,
			'safety_manifest_id' => $safety['id'],
			'files'              => $files,
		);
	}

	/**
	 * Prune to the N most recent snapshots, deleting both the manifest record
	 * and the on-disk directory. Always preserves safety/aborted snapshots
	 * less aggressively: aborted entries are kept for forensics.
	 *
	 * @param int $keep Number to retain.
	 * @return void
	 */
	public function prune_to( int $keep ): void {
		$all = $this->manifests->all();
		if ( count( $all ) <= $keep ) {
			return;
		}

		// Oldest first.
		$to_drop = array_slice( $all, 0, max( 0, count( $all ) - $keep ) );
		foreach ( $to_drop as $m ) {
			$id = $m['id'] ?? '';
			if ( '' === $id ) {
				continue;
			}
			// Never silently drop aborted (failed) snapshots — valuable for forensics.
			if ( ( $m['status'] ?? '' ) === 'aborted' ) {
				continue;
			}
			$this->remove_dir( $this->dir_for( $id ) );
			$this->manifests->delete( $id );
		}
	}

	/**
	 * Recursively copy a directory.
	 *
	 * @param string $source Source dir.
	 * @param string $dest   Destination dir.
	 * @return bool
	 */
	private function copy_dir( string $source, string $dest ): bool {
		if ( ! is_dir( $source ) ) {
			return false;
		}
		if ( ! is_dir( $dest ) && ! wp_mkdir_p( $dest ) ) {
			return false;
		}

		$dir = @opendir( $source );
		if ( false === $dir ) {
			return false;
		}

		while ( false !== ( $entry = readdir( $dir ) ) ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$s = $source . '/' . $entry;
			$d = $dest . '/' . $entry;
			if ( is_dir( $s ) ) {
				if ( ! $this->copy_dir( $s, $d ) ) {
					closedir( $dir );
					return false;
				}
			} else {
				if ( ! @copy( $s, $d ) ) {
					closedir( $dir );
					return false;
				}
			}
		}
		closedir( $dir );
		return true;
	}

	/**
	 * Recursively remove a directory (snapshot cleanup).
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		if ( false === $items ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->remove_dir( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
	}

	/**
	 * Recursively list files in a directory, returned as relative paths
	 * (forward slashes), excluding dotfiles.
	 *
	 * @param string $root Root directory.
	 * @return array<int,string>
	 */
	private function list_relative_files( string $root ): array {
		if ( ! is_dir( $root ) ) {
			return array();
		}
		$out = array();
		$it  = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $it as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$path = $file->getPathname();
			$rel  = ltrim( substr( $path, strlen( $root ) ), DIRECTORY_SEPARATOR );
			$out[] = str_replace( DIRECTORY_SEPARATOR, '/', $rel );
		}
		sort( $out );
		return $out;
	}
}
