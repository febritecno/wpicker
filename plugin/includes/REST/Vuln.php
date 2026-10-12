<?php
/**
 * REST Vuln controller — GET /wpicker/v1/vuln.
 *
 * @package WPicker\REST
 */

declare( strict_types = 1 );

namespace WPicker\REST;

use WPicker\Security\Scanner;

defined( 'ABSPATH' ) || exit;

/**
 * Class Vuln
 */
final class Vuln extends Base {

	/**
	 * Declare routes.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function routes(): array {
		return array(
			array(
				'path'                => '/vuln',
				'methods'             => 'GET',
				'callback'            => array( $this, 'get_vuln_report' ),
				'permission_callback' => array( $this, 'check_permission' ),
			),
		);
	}

	/**
	 * Permission callback: scanning requires manage_options.
	 *
	 * @return bool|\WP_Error
	 */
	public function check_permission() {
		return wpicker()->caps->rest_manage();
	}

	/**
	 * GET /vuln handler.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_vuln_report(): \WP_REST_Response {
		$report = ( new Scanner() )->scan();

		return $this->ok(
			array(
				'count'   => count( $report ),
				'plugins' => $report,
			)
		);
	}
}
