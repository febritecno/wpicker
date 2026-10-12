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
	 * @param string|null         $id     Reuse this manifest id (approved review) instead of a new one.
	 * @return array{ id: string, path: string, manifest: array<string,mixed> }
	 *
	 * @throws \RuntimeException If the child theme dir is unreadable.
	 */
	public function snapshot( array $device, array $files, string $kind = 'push', ?string $id = null ): array {
		$id           = $id ?? $this->manifests->generate_id();
		$target       = $this->dir_for( $id );
		$theme_root   = $this->guard->theme_root();

		if ( ! is_dir( $theme_root ) ) {
			throw new \RuntimeException( 'Child theme directory not found.' );
		}

		if ( ! $this->copy_dir( $theme_root, $target ) ) {
			throw new \RuntimeException( 'Failed to create snapshot.' );
		}

		// A push approved from review already has its manifest record.
		$manifest = $this->manifests->get( $id );
		if ( null === $manifest ) {
			$manifest = $this->manifests->build_record( $id, $device, $files, $kind );
			$this->manifests->add( $manifest );
		}

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

		$files = $this->list_relative_files( $source );

		// Safety snapshot of the current state.
		$safety = $this->snapshot( $device, $files, 'rollback_safety' );

		if ( ! $this->mirror_into_theme( $source ) ) {
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
	 * Put the child theme back exactly as it was in a snapshot, without taking
	 * a safety snapshot. Used to undo a push that failed mid-way or broke the
	 * site, where the snapshot being restored IS the pre-push state.
	 *
	 * @param string $manifest_id Snapshot manifest id.
	 * @return bool
	 */
	public function revert( string $manifest_id ): bool {
		$source = $this->dir_for( $manifest_id );
		return is_dir( $source ) && $this->mirror_into_theme( $source );
	}

	/**
	 * Make the child theme an exact copy of a snapshot directory: copy every
	 * snapshot file over, then delete theme files the snapshot does not have.
	 *
	 * @param string $source Snapshot directory.
	 * @return bool
	 */
	private function mirror_into_theme( string $source ): bool {
		$theme_root = $this->guard->theme_root();
		if ( ! $this->copy_dir( $source, $theme_root ) ) {
			return false;
		}

		$keep = array_flip( $this->list_relative_files( $source ) );
		foreach ( $this->list_relative_files( $theme_root ) as $rel ) {
			if ( ! isset( $keep[ $rel ] ) ) {
				@unlink( $theme_root . '/' . $rel );
			}
		}
		$this->remove_empty_dirs( $theme_root );

		foreach ( array_keys( $keep ) as $rel ) {
			self::invalidate( $theme_root . '/' . $rel );
		}
		return true;
	}

	/**
	 * Drop a file from opcache so the next request sees what is on disk
	 * instead of bytecode cached up to opcache.revalidate_freq seconds ago.
	 *
	 * @param string $absolute Absolute file path.
	 * @return void
	 */
	public static function invalidate( string $absolute ): void {
		if ( '.php' === substr( $absolute, -4 ) && function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $absolute, true );
		}
	}

	/**
	 * Remove empty sub-directories below a root (the root itself is kept).
	 *
	 * @param string $root Root directory.
	 * @return void
	 */
	public function remove_empty_dirs( string $root ): void {
		if ( ! is_dir( $root ) ) {
			return;
		}
		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $entry ) {
			if ( $entry->isDir() ) {
				@rmdir( $entry->getPathname() );
			}
		}
	}

	/**
	 * Directory holding the files of a push that is waiting for review.
	 *
	 * @param string $manifest_id Manifest id.
	 * @return string
	 */
	public function staged_dir( string $manifest_id ): string {
		return $this->root() . '/' . $manifest_id . '.staged';
	}

	/**
	 * Directory holding copies of the files a push wrote, so the admin can
	 * diff "before" (the snapshot) against "after" later on.
	 *
	 * @param string $manifest_id Manifest id.
	 * @return string
	 */
	public function after_dir( string $manifest_id ): string {
		return $this->root() . '/' . $manifest_id . '.after';
	}

	/**
	 * Hold a validated push for review instead of applying it.
	 *
	 * Writes the incoming files under staged_dir() together with a plan that
	 * remembers what each target looked like at submission time, so a later
	 * approval can tell whether the site moved on in the meantime.
	 *
	 * @param array<string,mixed>                                  $device Acting device.
	 * @param array<int,array{rel:string,abs:string,contents:string}> $files  Files to write.
	 * @param array<int,array{rel:string,abs:string}>               $remove Files to delete.
	 * @return array<string,mixed> The manifest record.
	 *
	 * @throws \RuntimeException If the staging area cannot be written.
	 */
	public function stage( array $device, array $files, array $remove ): array {
		$id  = $this->manifests->generate_id();
		$dir = $this->staged_dir( $id );

		$plan = array(
			'files'  => array(),
			'delete' => array(),
		);
		foreach ( $files as $f ) {
			$target = $dir . '/files/' . $f['rel'];
			if ( ! wp_mkdir_p( dirname( $target ) ) || false === file_put_contents( $target, $f['contents'] ) ) {
				$this->remove_dir( $dir );
				throw new \RuntimeException( 'Could not stage ' . $f['rel'] );
			}
			$plan['files'][] = array(
				'path'   => $f['rel'],
				'before' => is_file( $f['abs'] ) ? hash_file( 'sha256', $f['abs'] ) : null,
			);
		}
		foreach ( $remove as $r ) {
			$plan['delete'][] = array(
				'path'   => $r['rel'],
				'before' => is_file( $r['abs'] ) ? hash_file( 'sha256', $r['abs'] ) : null,
			);
		}
		if ( ! wp_mkdir_p( $dir ) || false === file_put_contents( $dir . '/plan.json', wp_json_encode( $plan ) ) ) {
			$this->remove_dir( $dir );
			throw new \RuntimeException( 'Could not write the staged plan.' );
		}

		$affected           = array_merge( array_column( $files, 'rel' ), array_column( $remove, 'rel' ) );
		$manifest           = $this->manifests->build_record( $id, $device, $affected, 'push' );
		$manifest['status'] = 'review';
		$this->manifests->add( $manifest );

		return $manifest;
	}

	/**
	 * Read back a staged push.
	 *
	 * @param string $manifest_id Manifest id.
	 * @return array{ files: array<int,array{path:string,before:?string,contents:string}>, delete: array<int,array{path:string,before:?string}> }|null
	 */
	public function staged( string $manifest_id ): ?array {
		$dir  = $this->staged_dir( $manifest_id );
		$plan = is_readable( $dir . '/plan.json' ) ? json_decode( (string) file_get_contents( $dir . '/plan.json' ), true ) : null;
		if ( ! is_array( $plan ) ) {
			return null;
		}
		$files = array();
		foreach ( (array) ( $plan['files'] ?? array() ) as $f ) {
			$contents = @file_get_contents( $dir . '/files/' . $f['path'] );
			if ( false === $contents ) {
				return null;
			}
			$files[] = array(
				'path'     => (string) $f['path'],
				'before'   => $f['before'] ?? null,
				'contents' => $contents,
			);
		}
		return array(
			'files'  => $files,
			'delete' => (array) ( $plan['delete'] ?? array() ),
		);
	}

	/**
	 * Throw away a staged push (approved, rejected, or replaced).
	 *
	 * @param string $manifest_id Manifest id.
	 * @return void
	 */
	public function discard_staged( string $manifest_id ): void {
		$this->remove_dir( $this->staged_dir( $manifest_id ) );
	}

	/**
	 * Keep a copy of one file as a push left it (see after_dir()).
	 *
	 * @param string $manifest_id Manifest id.
	 * @param string $rel         Theme-relative path.
	 * @param string $contents    File contents written.
	 * @return void
	 */
	public function remember_after( string $manifest_id, string $rel, string $contents ): void {
		$target = $this->after_dir( $manifest_id ) . '/' . $rel;
		if ( wp_mkdir_p( dirname( $target ) ) ) {
			@file_put_contents( $target, $contents );
		}
	}

	/**
	 * Permanently delete one entry: its manifest record, its snapshot, and any
	 * staged or after-copies. The theme itself is not touched.
	 *
	 * @param string $manifest_id Manifest id.
	 * @return bool False if there is no such entry.
	 */
	public function delete( string $manifest_id ): bool {
		if ( null === $this->manifests->get( $manifest_id ) ) {
			return false;
		}
		$this->remove_dir( $this->dir_for( $manifest_id ) );
		$this->remove_dir( $this->after_dir( $manifest_id ) );
		$this->remove_dir( $this->staged_dir( $manifest_id ) );
		$this->manifests->delete( $manifest_id );
		return true;
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
			// Nor a push that is still waiting for someone to review it.
			if ( in_array( $m['status'] ?? '', array( 'aborted', 'review' ), true ) ) {
				continue;
			}
			$this->remove_dir( $this->dir_for( $id ) );
			$this->remove_dir( $this->after_dir( $id ) );
			$this->remove_dir( $this->staged_dir( $id ) );
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
