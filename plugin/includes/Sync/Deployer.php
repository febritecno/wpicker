<?php
/**
 * Deployer — turns a push payload into applied files, safely.
 *
 * Shared by the two ways a push reaches the theme: straight from the CLI
 * (push mode "direct") and from an admin approving a held push in WP-Admin
 * (push mode "review").
 *
 * Failures are returned, not thrown, as
 *   array( 'code' => string, 'message' => string, 'status' => int, 'extra' => array )
 * so REST controllers can hand them to Base::fail() unchanged.
 *
 * @package WPicker\Sync
 */

declare( strict_types = 1 );

namespace WPicker\Sync;

use WPicker\Health\Probe;
use WPicker\Lint\PhpLint;
use WPicker\Vault\Manager;
use WPicker\Vault\Manifest;

defined( 'ABSPATH' ) || exit;

/**
 * Class Deployer
 */
final class Deployer {

	/**
	 * Path guard.
	 *
	 * @var PathGuard
	 */
	private $guard;

	/**
	 * Linter.
	 *
	 * @var PhpLint
	 */
	private $lint;

	/**
	 * Vault manager.
	 *
	 * @var Manager
	 */
	private $vault;

	/**
	 * Manifest store.
	 *
	 * @var Manifest
	 */
	private $manifests;

	/**
	 * Post-deploy health probe.
	 *
	 * @var Probe
	 */
	private $probe;

	/**
	 * Constructor.
	 *
	 * @param PathGuard $guard     Path guard.
	 * @param PhpLint   $lint      Linter.
	 * @param Manager   $vault     Vault manager.
	 * @param Manifest  $manifests Manifest store.
	 * @param Probe     $probe     Health probe.
	 */
	public function __construct( PathGuard $guard, PhpLint $lint, Manager $vault, Manifest $manifests, Probe $probe ) {
		$this->guard     = $guard;
		$this->lint      = $lint;
		$this->vault     = $vault;
		$this->manifests = $manifests;
		$this->probe     = $probe;
	}

	/**
	 * Push mode from settings: "direct" applies immediately, "review" holds
	 * the push until an administrator approves it in WP-Admin.
	 *
	 * @return string
	 */
	public function mode(): string {
		$settings = get_option( 'wpicker_settings', array() );
		return 'review' === ( $settings['push_mode'] ?? 'direct' ) ? 'review' : 'direct';
	}

	/**
	 * Validate a push body and resolve every path, without touching anything.
	 *
	 * @param array<string,mixed> $body Decoded request body.
	 * @return array{ files: array<int,array{rel:string,abs:string,contents:string}>, remove: array<int,array{rel:string,abs:string}> }|array{ error: array<string,mixed> }
	 */
	public function plan( array $body ): array {
		if ( ! $this->guard->has_child_theme() ) {
			return self::error( 'wpicker_no_child_theme', 'No active child theme.', 400 );
		}

		$files   = $body['files'] ?? array();
		$deletes = $body['delete'] ?? array();
		if ( ! is_array( $files ) || ! is_array( $deletes ) || ( array() === $files && array() === $deletes ) ) {
			return self::error( 'wpicker_no_files', 'No files supplied.', 400 );
		}

		$plan = array();
		foreach ( $files as $entry ) {
			$path     = (string) ( $entry['path'] ?? '' );
			$contents = (string) ( $entry['contents'] ?? '' );
			if ( 'base64' === ( $entry['encoding'] ?? '' ) ) {
				$contents = base64_decode( $contents, true );
				if ( false === $contents ) {
					return self::error( 'wpicker_bad_encoding', 'Contents are not valid base64.', 400, array( 'file' => $path ) );
				}
			}
			$abs = $this->guard->resolve( $path );
			if ( null === $abs ) {
				return self::blocked( $path );
			}
			$plan[] = array(
				'rel'      => $path,
				'abs'      => $abs,
				'contents' => $contents,
			);
		}

		$remove = array();
		foreach ( $deletes as $path ) {
			$path = (string) $path;
			$abs  = $this->guard->resolve( $path );
			if ( null === $abs ) {
				return self::blocked( $path );
			}
			$remove[] = array(
				'rel' => $path,
				'abs' => $abs,
			);
		}

		return array(
			'files'  => $plan,
			'remove' => $remove,
		);
	}

	/**
	 * Run `php -l` over the PHP files of a plan.
	 *
	 * @param array<int,array{rel:string,abs:string,contents:string}> $files Planned files.
	 * @return array{ error: array<string,mixed> }|null Null when clean (or linting is off).
	 */
	public function lint( array $files ): ?array {
		$settings = get_option( 'wpicker_settings', array() );
		if ( (int) ( $settings['lint_enabled'] ?? 1 ) !== 1 ) {
			return null;
		}
		foreach ( $files as $f ) {
			if ( '.php' !== substr( $f['rel'], -4 ) ) {
				continue;
			}
			$result = $this->lint->lint_source( $f['contents'] );
			if ( ! $result['ok'] ) {
				return self::error(
					$result['code'] ?? 'wpicker_lint_syntax',
					$result['message'] ?? 'Lint failure.',
					422,
					array(
						'file' => $f['rel'],
						'line' => $result['line'] ?? null,
					)
				);
			}
		}
		return null;
	}

	/**
	 * What each planned path does to the theme as it is right now.
	 *
	 * @param array<int,array{rel:string,abs:string,contents:string}> $files  Planned files.
	 * @param array<int,array{rel:string,abs:string}>                $remove Planned deletions.
	 * @return array<int,array{path:string,action:string}>
	 */
	public function describe( array $files, array $remove ): array {
		$changes = array();
		foreach ( $files as $f ) {
			$changes[] = array(
				'path'   => $f['rel'],
				'action' => is_file( $f['abs'] ) ? 'modified' : 'added',
			);
		}
		foreach ( $remove as $r ) {
			$changes[] = array(
				'path'   => $r['rel'],
				'action' => 'deleted',
			);
		}
		return $changes;
	}

	/**
	 * Hold a linted plan for review instead of applying it.
	 *
	 * @param array<int,array{rel:string,abs:string,contents:string}> $files  Planned files.
	 * @param array<int,array{rel:string,abs:string}>                $remove Planned deletions.
	 * @param array<string,mixed>                                    $device Acting device.
	 * @return array{ data: array<string,mixed> }|array{ error: array<string,mixed> }
	 */
	public function hold( array $files, array $remove, array $device ): array {
		// A newer submission from the same device replaces its older one, so
		// the reviewer is never asked to approve something already outdated.
		$device_id = (string) ( $device['id'] ?? '' );
		foreach ( $this->manifests->all() as $m ) {
			if ( '' !== $device_id && 'review' === ( $m['status'] ?? '' ) && $device_id === ( $m['device_id'] ?? '' ) ) {
				$m['status'] = 'superseded';
				$this->manifests->update( $m['id'], $m );
				$this->manifests->log( $m['id'], 'superseded', 'Replaced by a newer push from the same device.' );
				$this->vault->discard_staged( $m['id'] );
			}
		}

		try {
			$manifest = $this->vault->stage( $device, $files, $remove );
		} catch ( \Throwable $e ) {
			return self::error( 'wpicker_stage_failed', $e->getMessage(), 500 );
		}
		$id                  = $manifest['id'];
		$manifest['changes'] = $this->describe( $files, $remove );
		$this->manifests->update( $id, $manifest );
		$this->manifests->log( $id, 'submitted', 'Push submitted for review by ' . ( $device['name'] ?? 'unknown' ) . '.' );

		return array(
			'data' => array(
				'manifest_id' => $id,
				'status'      => 'review',
				'applied'     => array(),
				'deleted'     => array(),
				'count'       => count( $manifest['changes'] ),
				'message'     => 'Held for review. An administrator must approve it in WP-Admin → WPicker → Snapshots.',
			),
		);
	}

	/**
	 * Apply a plan: snapshot → write/delete → health check, reverting to the
	 * snapshot if anything after the snapshot goes wrong.
	 *
	 * @param array<int,array{rel:string,abs:string,contents:string}> $files       Planned files.
	 * @param array<int,array{rel:string,abs:string}>                $remove      Planned deletions.
	 * @param array<string,mixed>                                    $device      Acting device.
	 * @param string|null                                            $manifest_id Existing manifest to complete (approved review).
	 * @param string                                                 $kind        Entry kind: 'push', or 'revert' when undoing an earlier push.
	 * @return array{ data: array<string,mixed> }|array{ error: array<string,mixed> }
	 */
	public function execute( array $files, array $remove, array $device, ?string $manifest_id = null, string $kind = 'push' ): array {
		// Is the site healthy right now? If it is already broken we must not
		// blame (and revert) this push — it may be the fix.
		$health_on = $this->probe->enabled();
		$baseline  = $health_on ? $this->probe->check() : array( 'ok' => null );
		$changes   = $this->describe( $files, $remove );

		// Snapshot the current child theme (the safety net for everything below).
		try {
			$shot = $this->vault->snapshot( $device, array_column( $changes, 'path' ), $kind, $manifest_id );
		} catch ( \Throwable $e ) {
			return self::error( 'wpicker_snapshot_failed', $e->getMessage(), 500 );
		}
		$id                  = $shot['id'];
		$manifest            = $shot['manifest'];
		$manifest['changes'] = $changes;
		$this->manifests->update( $id, $manifest );

		// Apply: write files (temp + rename), then delete.
		$log_mark = $this->probe->log_mark();
		$applied  = array();
		foreach ( $files as $f ) {
			$dir = dirname( $f['abs'] );
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				return $this->abort( $id, 'wpicker_mkdir_failed', 'Could not create directory: ' . dirname( $f['rel'] ), $f['rel'] );
			}
			$tmp = $f['abs'] . '.wpicker-tmp';
			if ( false === file_put_contents( $tmp, $f['contents'] ) ) {
				return $this->abort( $id, 'wpicker_write_failed', 'Could not write file.', $f['rel'] );
			}
			@chmod( $tmp, 0644 & ~umask() );
			if ( ! @rename( $tmp, $f['abs'] ) ) {
				@unlink( $tmp );
				return $this->abort( $id, 'wpicker_rename_failed', 'Could not apply file.', $f['rel'] );
			}
			Manager::invalidate( $f['abs'] );
			$this->vault->remember_after( $id, $f['rel'], $f['contents'] );
			$applied[] = $f['rel'];
		}

		$deleted = array();
		foreach ( $remove as $r ) {
			if ( is_file( $r['abs'] ) && ! @unlink( $r['abs'] ) ) {
				return $this->abort( $id, 'wpicker_delete_failed', 'Could not delete file.', $r['rel'] );
			}
			Manager::invalidate( $r['abs'] );
			$deleted[] = $r['rel'];
		}
		if ( array() !== $deleted ) {
			$this->vault->remove_empty_dirs( $this->guard->theme_root() );
		}

		// Health check: does the site still boot with the new files?
		$health = 'skipped';
		if ( $health_on ) {
			if ( false === $baseline['ok'] ) {
				$health = 'already_failing';
			} else {
				$after = $this->probe->check();
				if ( false === $after['ok'] ) {
					return $this->unhealthy( $id, $after, $this->probe->fatal_since( $log_mark ) );
				}
				$health = true === $after['ok'] ? 'ok' : 'unreachable';
			}
		}

		$this->vault->mark_applied( $id );
		$this->manifests->log( $id, 'applied', sprintf( '%d written, %d deleted. Health: %s.', count( $applied ), count( $deleted ), $health ) );

		return array(
			'data' => array(
				'manifest_id' => $id,
				'status'      => 'applied',
				'applied'     => $applied,
				'deleted'     => $deleted,
				'count'       => count( $applied ) + count( $deleted ),
				'health'      => $health,
			),
		);
	}

	/**
	 * Build the error shape.
	 *
	 * @param string              $code    Error code.
	 * @param string              $message Error message.
	 * @param int                 $status  HTTP status.
	 * @param array<string,mixed> $extra   Extra error fields.
	 * @return array{ error: array<string,mixed> }
	 */
	public static function error( string $code, string $message, int $status, array $extra = array() ): array {
		return array(
			'error' => array(
				'code'    => $code,
				'message' => $message,
				'status'  => $status,
				'extra'   => $extra,
			),
		);
	}

	/**
	 * Uniform "path refused" error.
	 *
	 * @param string $path Offending relative path.
	 * @return array{ error: array<string,mixed> }
	 */
	private static function blocked( string $path ): array {
		return self::error(
			'wpicker_path_blocked',
			'Refused path outside child theme or blocked basename.',
			400,
			array( 'file' => $path )
		);
	}

	/**
	 * A filesystem step failed part-way: put the theme back as it was, record
	 * the failure on the manifest, and report it.
	 *
	 * @param string $manifest_id Snapshot taken before applying.
	 * @param string $code        Error code.
	 * @param string $message     Error message.
	 * @param string $file        Relative file that failed.
	 * @return array{ error: array<string,mixed> }
	 */
	private function abort( string $manifest_id, string $code, string $message, string $file ): array {
		$rolled_back = $this->vault->revert( $manifest_id );
		$this->vault->mark_aborted(
			$manifest_id,
			array(
				'code'    => $code,
				'message' => $message,
				'file'    => $file,
			)
		);
		$this->manifests->log( $manifest_id, 'aborted', $message );
		return self::error(
			$code,
			$message,
			500,
			array(
				'file'        => $file,
				'manifest_id' => $manifest_id,
				'rolled_back' => $rolled_back,
			)
		);
	}

	/**
	 * The site stopped responding after the files were applied: revert to the
	 * pre-push snapshot and hand the agent the fatal error to fix.
	 *
	 * @param string                                                           $manifest_id Snapshot taken before applying.
	 * @param array{ ok: bool|null, status: int, url: string }                 $probe       Failed probe result.
	 * @param array{ message: string, file: string|null, line: int|null }|null $fatal       Fatal error from the PHP log, if found.
	 * @return array{ error: array<string,mixed> }
	 */
	private function unhealthy( string $manifest_id, array $probe, ?array $fatal ): array {
		$rolled_back = $this->vault->revert( $manifest_id );

		$message = sprintf( 'Site returned HTTP %d after applying the push.', $probe['status'] );
		if ( null !== $fatal ) {
			$message .= ' ' . $fatal['message'];
		}
		$message .= $rolled_back
			? ' The previous files were restored.'
			: ' Automatic restore FAILED — run `wpicker rollback ' . $manifest_id . '`.';

		$extra = array(
			'manifest_id' => $manifest_id,
			'rolled_back' => $rolled_back,
			'http_status' => $probe['status'],
			'url'         => $probe['url'],
		);
		if ( null !== $fatal && null !== $fatal['file'] ) {
			$extra['file'] = $fatal['file'];
			$extra['line'] = $fatal['line'];
		}

		$this->vault->mark_aborted(
			$manifest_id,
			array_merge(
				array(
					'code'    => 'wpicker_health_failed',
					'message' => $message,
				),
				$extra
			)
		);
		$this->manifests->log( $manifest_id, 'aborted', $message );

		return self::error( 'wpicker_health_failed', $message, 422, $extra );
	}
}
