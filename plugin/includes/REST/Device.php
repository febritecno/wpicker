<?php
/**
 * REST Device controller — device registration & management.
 *
 * Routes:
 *   GET    /device/challenge   issue a PIN (rendered in admin for the user)
 *   POST   /device/register    verify PIN → mint Application Password
 *   GET    /device             list registered devices
 *   DELETE /device/(?P<id>...) revoke a device
 *
 * Authentication model:
 *   - challenge/register are reached with HTTP Basic (username) — the caller
 *     already proves they are that user via WP auth. The PIN additionally
 *     binds the registration to a human-in-the-loop confirmation.
 *   - list/revoke require manage_options.
 *
 * @package WPicker\REST
 */

declare( strict_types = 1 );

namespace WPicker\REST;

use WPicker\Auth\Capability;
use WPicker\Auth\Pin;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Class Device
 */
final class Device extends Base {

	/**
	 * Option key under which we store WPicker device metadata (name, last-seen).
	 *
	 * NOTE: the Application Password records themselves are stored by core in
	 * user meta `WP_Application_Passwords`; this option only holds our display
	 * metadata (friendly name, last-seen timestamps).
	 */
	public const DEVICES_OPTION = 'wpicker_devices';

	/**
	 * Capability helper.
	 *
	 * @var Capability
	 */
	private $caps;

	/**
	 * PIN helper.
	 *
	 * @var Pin
	 */
	private $pins;

	/**
	 * Constructor.
	 *
	 * @param Capability $caps Capability helper.
	 */
	public function __construct( Capability $caps ) {
		$this->caps = $caps;
		$this->pins = new Pin();
	}

	/**
	 * Declare routes.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function routes(): array {
		return array(
			array(
				'path'                => '/device/challenge',
				'methods'             => 'GET',
				'callback'            => array( $this, 'challenge' ),
				'permission_callback' => array( $this, 'permission_challenge' ),
			),
			array(
				'path'                => '/device/register',
				'methods'             => 'POST',
				'callback'            => array( $this, 'register' ),
				'permission_callback' => array( $this, 'permission_register' ),
				'args'                => array(
					'pin'         => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'device_name' => array( 'type' => 'string', 'required' => false, 'default' => 'WPicker CLI', 'sanitize_callback' => 'sanitize_text_field' ),
				),
			),
			array(
				'path'                => '/device',
				'methods'             => 'GET',
				'callback'            => array( $this, 'list_devices' ),
				'permission_callback' => array( $this, 'permission_manage' ),
			),
			array(
				'path'                => '/device/(?P<id>[0-9a-f\-]+)',
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'revoke' ),
				'permission_callback' => array( $this, 'permission_manage' ),
				'args'                => array(
					'id' => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				),
			),
		);
	}

	/**
	 * Permission for issuing a challenge (must be the user himself, logged in).
	 *
	 * @return bool|\WP_Error
	 */
	public function permission_challenge() {
		return $this->caps->rest_challenge();
	}

	/**
	 * Permission for registering a device: caller is the user (Basic auth),
	 * and must be capable of edit_themes. The PIN is checked separately.
	 *
	 * @return bool|\WP_Error
	 */
	public function permission_register() {
		return $this->caps->rest_challenge();
	}

	/**
	 * Permission for device management.
	 *
	 * @return bool|\WP_Error
	 */
	public function permission_manage() {
		return $this->caps->rest_manage();
	}

	/**
	 * GET /device/challenge — issue a PIN for the current user.
	 *
	 * @return WP_REST_Response
	 */
	public function challenge(): WP_REST_Response {
		$user_id = get_current_user_id();
		$issued  = $this->pins->issue( $user_id );

		// Surface the PIN in the response ONLY because the caller is the
		// authenticated user themselves (admin). It is also rendered in admin.
		return $this->ok(
			array(
				'user_id'        => $user_id,
				'pin'            => $issued['pin'],
				'expires_at_gmt' => $issued['expires_at_gmt'],
				'ttl'            => $issued['ttl'],
				'plugin_version' => WPICKER_VERSION,
				'hint'           => 'Enter this PIN into the CLI prompt, or read it in WP-Admin → WPicker → Devices.',
			)
		);
	}

	/**
	 * POST /device/register — verify PIN and mint an Application Password.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function register( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$pin     = (string) $request->get_param( 'pin' );
		$name    = (string) $request->get_param( 'device_name' );

		if ( ! $this->pins->verify( $user_id, $pin ) ) {
			return $this->fail( 'wpicker_pin_invalid', 'PIN is invalid, expired, or already used.', 401 );
		}

		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return $this->fail( 'wpicker_no_app_passwords', 'Application Passwords are not available on this site.', 500 );
		}

		$device_name = '' !== $name ? $name : 'WPicker CLI ' . gmdate( 'Y-m-d H:i' );
		list( $new_item, $raw_password ) = \WP_Application_Passwords::create_new_application_password(
			$user_id,
			array( 'name' => $device_name )
		);

		if ( is_wp_error( $new_item ) ) {
			return $this->fail( 'wpicker_app_password_failed', $new_item->get_error_message(), 500 );
		}

		// Track our own metadata for display.
		$record = array(
			'id'            => (string) $new_item['uuid'],
			'user_id'       => $user_id,
			'name'          => $device_name,
			'created_at_gmt' => gmdate( 'c' ),
			'last_seen_gmt' => null,
		);
		$this->store_device( $record );

		return $this->ok(
			array(
				'user_id'      => $user_id,
				'device_id'    => $record['id'],
				'app_password' => $raw_password, // surfaced ONCE here; never retrievable again.
				'device_name'  => $device_name,
				'note'         => 'Store this app password securely; it cannot be retrieved again.',
			),
			201
		);
	}

	/**
	 * GET /device — list registered devices across all users (admin only).
	 *
	 * @return WP_REST_Response
	 */
	public function list_devices(): WP_REST_Response {
		return $this->ok( array( 'devices' => $this->all_devices() ) );
	}

	/**
	 * DELETE /device/{id} — revoke a device (deletes its Application Password).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function revoke( WP_REST_Request $request ): WP_REST_Response {
		$id = (string) $request->get_param( 'id' );
		$devices = $this->all_devices();
		$target  = null;
		foreach ( $devices as $d ) {
			if ( ( $d['id'] ?? '' ) === $id ) {
				$target = $d;
				break;
			}
		}
		if ( null === $target ) {
			return $this->fail( 'wpicker_device_not_found', 'Device not found.', 404 );
		}

		if ( class_exists( 'WP_Application_Passwords' ) ) {
			\WP_Application_Passwords::delete_application_password( (int) $target['user_id'], $id );
		}

		$this->delete_device( $id );
		return $this->ok( array( 'revoked' => $id ) );
	}

	/**
	 * Mark a device as seen (called on every authenticated CLI request via a
	 * rest_dispatch_request hook wired by Core, see below).
	 *
	 * @param string $device_id Device id (app-password uuid).
	 * @return void
	 */
	public function touch( string $device_id ): void {
		if ( '' === $device_id ) {
			return;
		}
		$devices = $this->all_devices();
		foreach ( $devices as $i => $d ) {
			if ( ( $d['id'] ?? '' ) === $device_id ) {
				$devices[ $i ]['last_seen_gmt'] = gmdate( 'c' );
				break;
			}
		}
		update_option( self::DEVICES_OPTION, $devices, false );
	}

	/**
	 * Get all device records.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function all_devices(): array {
		$devices = get_option( self::DEVICES_OPTION, array() );
		return is_array( $devices ) ? array_values( $devices ) : array();
	}

	/**
	 * Store/append a device record.
	 *
	 * @param array<string,mixed> $record Device record.
	 * @return void
	 */
	private function store_device( array $record ): void {
		$devices   = $this->all_devices();
		$devices[] = $record;
		update_option( self::DEVICES_OPTION, $devices, false );
	}

	/**
	 * Delete a device record by id.
	 *
	 * @param string $id Device id.
	 * @return void
	 */
	private function delete_device( string $id ): void {
		$devices = $this->all_devices();
		$devices = array_filter(
			$devices,
			static function ( $d ) use ( $id ): bool {
				return ( $d['id'] ?? '' ) !== $id;
			}
		);
		update_option( self::DEVICES_OPTION, array_values( $devices ), false );
	}
}
