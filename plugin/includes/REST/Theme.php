<?php
/**
 * REST Theme controller — file sync endpoints.
 *
 * Routes (full implementation in M4):
 *   GET    /theme/files         list child-theme files
 *   GET    /theme/file          read a single file
 *   POST   /theme/push          upload + lint + snapshot + apply
 *
 * Skeleton present now so the plugin boots; logic completed in milestone 4.
 *
 * @package WPicker\REST
 */

declare( strict_types = 1 );

namespace WPicker\REST;

use WPicker\Sync\PathGuard;
use WPicker\Lint\PhpLint;
use WPicker\Vault\Manager;
use WP_REST_Request;

defined( 'ABSPATH' ) || exit;

/**
 * Class Theme
 */
final class Theme extends Base {

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
	 * Constructor.
	 *
	 * @param PathGuard $guard Path guard.
	 * @param PhpLint   $lint  Linter.
	 * @param Manager   $vault Vault manager.
	 */
	public function __construct( PathGuard $guard, PhpLint $lint, Manager $vault ) {
		$this->guard = $guard;
		$this->lint  = $lint;
		$this->vault = $vault;
	}

	/**
	 * Declare routes.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function routes(): array {
		return array(
			array(
				'path'                => '/theme/files',
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_files' ),
				'permission_callback' => array( $this, 'permission' ),
			),
			array(
				'path'                => '/theme/file',
				'methods'             => 'GET',
				'callback'            => array( $this, 'read_file' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'path' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			),
			array(
				'path'                => '/theme/push',
				'methods'             => 'POST',
				'callback'            => array( $this, 'push' ),
				'permission_callback' => array( $this, 'permission' ),
			),
		);
	}

	/**
	 * Permission callback: require edit_themes.
	 *
	 * @return bool|\WP_Error
	 */
	public function permission() {
		return wpicker()->caps->rest_sync_files();
	}

	/**
	 * GET /theme/files — recursive list of child-theme files.
	 *
	 * @return \WP_REST_Response
	 */
	public function list_files(): \WP_REST_Response {
		if ( ! $this->guard->has_child_theme() ) {
			return $this->fail( 'wpicker_no_child_theme', 'No active child theme.', 400 );
		}

		$root  = $this->guard->theme_root();
		$files = array();
		$it    = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS )
		);
		foreach ( $it as $f ) {
			if ( ! $f->isFile() ) {
				continue;
			}
			$rel = $this->guard->relative_to_theme( $f->getPathname() );
			if ( null === $rel ) {
				continue;
			}
			$files[] = array(
				'path'    => $rel,
				'size'    => $f->getSize(),
				'mtime'   => gmdate( 'c', (int) $f->getMTime() ),
				'sha256'  => hash_file( 'sha256', $f->getPathname() ) ?: '',
			);
		}
		usort(
			$files,
			static function ( $a, $b ) {
				return strcmp( $a['path'], $b['path'] );
			}
		);
		return $this->ok(
			array(
				'stylesheet' => (string) get_stylesheet(),
				'count'      => count( $files ),
				'files'      => $files,
			)
		);
	}

	/**
	 * GET /theme/file?path=... — read one file.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function read_file( WP_REST_Request $request ): \WP_REST_Response {
		$rel  = (string) $request->get_param( 'path' );
		$abs  = $this->guard->resolve( $rel );
		if ( null === $abs || ! is_readable( $abs ) ) {
			return $this->fail( 'wpicker_file_not_found', 'File not found or outside child theme.', 404 );
		}
		$contents = file_get_contents( $abs );
		if ( false === $contents ) {
			return $this->fail( 'wpicker_file_unreadable', 'Could not read file.', 500 );
		}
		return $this->ok(
			array(
				'path'     => $rel,
				'size'     => strlen( $contents ),
				'sha256'   => hash( 'sha256', $contents ),
				'contents' => $contents,
			)
		);
	}

	/**
	 * POST /theme/push — snapshot → lint → apply, with preventive rollback.
	 *
	 * Body: { files: [ { path, contents }, ... ], device?: {...} }
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function push( WP_REST_Request $request ): \WP_REST_Response {
		if ( ! $this->guard->has_child_theme() ) {
			return $this->fail( 'wpicker_no_child_theme', 'No active child theme.', 400 );
		}

		$body  = $request->get_json_params();
		$files = $body['files'] ?? array();
		if ( ! is_array( $files ) || array() === $files ) {
			return $this->fail( 'wpicker_no_files', 'No files supplied.', 400 );
		}

		// 1) Validate + resolve every path FIRST. Abort before touching anything.
		$plan = array();
		foreach ( $files as $entry ) {
			$path     = (string) ( $entry['path'] ?? '' );
			$contents = (string) ( $entry['contents'] ?? '' );
			$abs      = $this->guard->resolve( $path );
			if ( null === $abs ) {
				return $this->fail(
					'wpicker_path_blocked',
					'Refused path outside child theme or blocked basename.',
					400,
					array( 'file' => $path )
				);
			}
			$plan[] = array(
				'rel'      => $path,
				'abs'      => $abs,
				'contents' => $contents,
				'is_php'   => ( substr( $path, -4 ) === '.php' ),
			);
		}

		// 2) Lint every incoming PHP file to a temp location before writing.
		$settings = get_option( 'wpicker_settings', array() );
		$lint_on  = (int) ( $settings['lint_enabled'] ?? 1 ) === 1;
		if ( $lint_on ) {
			foreach ( $plan as $p ) {
				if ( ! $p['is_php'] ) {
					continue;
				}
				$result = $this->lint->lint_source( $p['contents'] );
				if ( ! $result['ok'] ) {
					return $this->fail(
						$result['code'] ?? 'wpicker_lint_syntax',
						$result['message'] ?? 'Lint failure.',
						422,
						array(
							'file' => $p['rel'],
							'line' => $result['line'] ?? null,
						)
					);
				}
			}
		}

		// 3) Snapshot the current child theme (atomic safety net).
		$device = (array) ( $body['device'] ?? array( 'name' => 'unknown' ) );
		try {
			$shot = $this->vault->snapshot( $device, array_column( $plan, 'rel' ) );
		} catch ( \Throwable $e ) {
			return $this->fail( 'wpicker_snapshot_failed', $e->getMessage(), 500 );
		}

		// 4) Apply files atomically (write to temp then rename).
		$applied = array();
		foreach ( $plan as $p ) {
			$dir = dirname( $p['abs'] );
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				$this->vault->mark_aborted(
					$shot['id'],
					array(
						'code'    => 'wpicker_mkdir_failed',
						'message' => 'Could not create directory: ' . dirname( $p['rel'] ),
						'file'    => $p['rel'],
					)
				);
				return $this->fail( 'wpicker_mkdir_failed', 'Could not create directory.', 500, array( 'file' => $p['rel'], 'manifest_id' => $shot['id'] ) );
			}
			$tmp = $p['abs'] . '.wpicker-tmp';
			if ( false === file_put_contents( $tmp, $p['contents'] ) ) {
				$this->vault->mark_aborted( $shot['id'], array( 'code' => 'wpicker_write_failed', 'message' => 'Could not write file.', 'file' => $p['rel'] ) );
				return $this->fail( 'wpicker_write_failed', 'Could not write file.', 500, array( 'file' => $p['rel'], 'manifest_id' => $shot['id'] ) );
			}
			@chmod( $tmp, 0644 & ~umask() );
			if ( ! @rename( $tmp, $p['abs'] ) ) {
				@unlink( $tmp );
				$this->vault->mark_aborted( $shot['id'], array( 'code' => 'wpicker_rename_failed', 'message' => 'Could not apply file.', 'file' => $p['rel'] ) );
				return $this->fail( 'wpicker_rename_failed', 'Could not apply file.', 500, array( 'file' => $p['rel'], 'manifest_id' => $shot['id'] ) );
			}
			$applied[] = $p['rel'];
		}

		$this->vault->mark_applied( $shot['id'] );

		return $this->ok(
			array(
				'manifest_id' => $shot['id'],
				'applied'     => $applied,
				'count'       => count( $applied ),
			),
			201
		);
	}
}
