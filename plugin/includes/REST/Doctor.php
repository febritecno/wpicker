<?php
/**
 * REST Doctor controller — a checklist of what could stop WPicker working here.
 *
 * Route:
 *   GET /doctor   ?loopback=1|0   (default 1: also ask the site to request itself)
 *
 * Administrators only. It is about WPicker's own health, so it works while
 * "Diagnostic access" is off.
 *
 * @package WPicker\REST
 */

declare( strict_types = 1 );

namespace WPicker\REST;

use WPicker\Health\Doctor as Checklist;
use WPicker\Health\Probe;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Class Doctor
 */
class Doctor extends Base {

	/**
	 * Declare routes.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function routes(): array {
		return array(
			array(
				'path'                => '/doctor',
				'methods'             => 'GET',
				'callback'            => array( $this, 'run' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'loopback' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			),
		);
	}

	/**
	 * Permission callback.
	 *
	 * @return bool|\WP_Error
	 */
	public function permission() {
		return wpicker()->caps->rest_manage();
	}

	/**
	 * GET /doctor.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function run( WP_REST_Request $request ): WP_REST_Response {
		$probe = null;
		$web   = null;
		if ( (bool) $request->get_param( 'loopback' ) ) {
			$web = Checklist::vault_web_status();
			$settings = (array) get_option( 'wpicker_settings', array() );
			if ( (int) ( $settings['health_check'] ?? 1 ) === 1 ) {
				$probe = ( new Probe( wpicker()->path_guard ) )->check();
			}
		}
		$checks = Checklist::evaluate( Checklist::facts( $probe, $web ) );
		return $this->ok(
			array(
				'plugin_version' => defined( 'WPICKER_VERSION' ) ? (string) WPICKER_VERSION : '',
				'checks'         => $checks,
				'summary'        => Checklist::tally( $checks ),
			)
		);
	}
}
