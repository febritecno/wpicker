<?php
/**
 * REST Vault controller — history & rollback endpoints.
 *
 * Routes:
 *   GET  /history       list recent manifests
 *   GET  /history/{id}  inspect one manifest (file list, error log)
 *   POST /rollback      restore a snapshot by id
 *
 * @package WPicker\REST
 */

declare( strict_types = 1 );

namespace WPicker\REST;

use WPicker\Vault\Manager;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Class Vault
 */
final class Vault extends Base {

	/**
	 * Vault manager.
	 *
	 * @var Manager
	 */
	private $vault;

	/**
	 * Constructor.
	 *
	 * @param Manager $vault Vault manager.
	 */
	public function __construct( Manager $vault ) {
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
				'path'                => '/history',
				'methods'             => 'GET',
				'callback'            => array( $this, 'history' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'limit' => array(
						'type'    => 'integer',
						'default' => 30,
						'minimum' => 1,
						'maximum' => 200,
					),
				),
			),
			array(
				'path'                => '/history/(?P<id>[0-9a-zA-Z\-]+)',
				'methods'             => 'GET',
				'callback'            => array( $this, 'one' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'id' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				),
			),
			array(
				'path'                => '/rollback',
				'methods'             => 'POST',
				'callback'            => array( $this, 'rollback' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'manifest_id' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'device'      => array( 'type' => 'object', 'required' => false, 'default' => array() ),
				),
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
	 * GET /history — list recent manifests (newest first).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function history( WP_REST_Request $request ): WP_REST_Response {
		$limit = (int) $request->get_param( 'limit' );
		$all   = wpicker()->manifests->all();
		$all   = array_slice( array_reverse( $all ), 0, $limit );

		// Slim each record for the list view.
		$slim = array_map(
			static function ( array $m ): array {
				return array(
					'id'             => $m['id'] ?? '',
					'kind'           => $m['kind'] ?? 'push',
					'created_at_gmt' => $m['created_at_gmt'] ?? '',
					'device_name'    => $m['device_name'] ?? '',
					'count'          => $m['count'] ?? 0,
					'status'         => $m['status'] ?? '',
					'restore_count'  => $m['restore_count'] ?? 0,
				);
			},
			$all
		);

		return $this->ok( array( 'manifests' => $slim, 'count' => count( $slim ) ) );
	}

	/**
	 * GET /history/{id} — full manifest including file list and error log.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function one( WP_REST_Request $request ): WP_REST_Response {
		$id   = (string) $request->get_param( 'id' );
		$m    = wpicker()->manifests->get( $id );
		if ( null === $m ) {
			return $this->fail( 'wpicker_manifest_not_found', 'Manifest not found.', 404 );
		}
		$m['snapshot_dir'] = $this->vault->dir_for( $id );
		$m['snapshot_exists'] = is_dir( $m['snapshot_dir'] );
		return $this->ok( $m );
	}
	/**
	 * POST /rollback — restore a snapshot.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function rollback( WP_REST_Request $request ): WP_REST_Response {
		$id     = (string) $request->get_param( 'manifest_id' );
		$device = (array) $request->get_param( 'device' );
		$device = $device + array( 'name' => 'unknown' );

		try {
			$result = $this->vault->restore( $id, $device );
		} catch ( \Throwable $e ) {
			return $this->fail( 'wpicker_rollback_failed', $e->getMessage(), 500, array( 'manifest_id' => $id ) );
		}

		return $this->ok( $result );
	}
}
