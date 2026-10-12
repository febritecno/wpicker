<?php
/**
 * REST Theme controller — file sync endpoints.
 *
 * Routes:
 *   GET    /theme/files         list child-theme files
 *   GET    /theme/file          read a single file
 *   POST   /theme/push          upload + lint + snapshot + apply
 *
 * @package WPicker\REST
 */

declare( strict_types = 1 );

namespace WPicker\REST;

use WPicker\Sync\PathGuard;
use WPicker\Sync\Deployer;
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
	 * Deployer.
	 *
	 * @var Deployer
	 */
	private $deployer;

	/**
	 * Constructor.
	 *
	 * @param PathGuard $guard    Path guard.
	 * @param Deployer  $deployer Deployer.
	 */
	public function __construct( PathGuard $guard, Deployer $deployer ) {
		$this->guard    = $guard;
		$this->deployer = $deployer;
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
					'path'     => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'encoding' => array(
						'type'     => 'string',
						'required' => false,
						'enum'     => array( 'base64' ),
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
	 * GET /theme/file?path=...[&encoding=base64] — read one file.
	 *
	 * Without `encoding` the contents are returned as a plain string, which
	 * only round-trips for UTF-8 text. Clients that need binary-safe transfer
	 * (images, fonts) must ask for base64.
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
		$base64 = 'base64' === $request->get_param( 'encoding' );
		return $this->ok(
			array(
				'path'     => $rel,
				'size'     => strlen( $contents ),
				'sha256'   => hash( 'sha256', $contents ),
				'encoding' => $base64 ? 'base64' : 'utf8',
				'contents' => $base64 ? base64_encode( $contents ) : $contents,
			)
		);
	}

	/**
	 * POST /theme/push — validate + lint, then either apply right away
	 * (push mode "direct") or hold for approval in WP-Admin ("review").
	 *
	 * Body: {
	 *   files:  [ { path, contents, encoding?: "base64" }, ... ],
	 *   delete: [ path, ... ],
	 *   device?: {...}
	 * }
	 *
	 * Responds 201 when applied, 202 when held for review.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function push( WP_REST_Request $request ): \WP_REST_Response {
		$body = (array) $request->get_json_params();
		$plan = $this->deployer->plan( $body );
		if ( isset( $plan['error'] ) ) {
			return $this->fail_from( $plan['error'] );
		}

		// Nothing is written — not even to the review queue — unless it parses.
		$lint = $this->deployer->lint( $plan['files'] );
		if ( null !== $lint ) {
			return $this->fail_from( $lint['error'] );
		}

		$device = (array) ( $body['device'] ?? array( 'name' => 'unknown' ) );
		$review = 'review' === $this->deployer->mode();
		$result = $review
			? $this->deployer->hold( $plan['files'], $plan['remove'], $device )
			: $this->deployer->execute( $plan['files'], $plan['remove'], $device );

		if ( isset( $result['error'] ) ) {
			return $this->fail_from( $result['error'] );
		}
		return $this->ok( $result['data'], $review ? 202 : 201 );
	}
}
