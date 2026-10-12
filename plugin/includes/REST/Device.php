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
	 * True when the caller proved identity with username + password in the
	 * request body (CLI pairing), rather than an existing session.
	 *
	 * @var bool
	 */
	private $via_credentials = false;

	/**
	 * Authorization decisions already made, keyed by request object id.
	 *
	 * @var array<int,bool|\WP_Error>
	 */
	private $decisions = array();

	/**
	 * Failed pairing attempts allowed per username + IP before lockout.
	 */
	private const MAX_ATTEMPTS = 5;

	/**
	 * Lockout window in seconds.
	 */
	private const LOCKOUT_TTL = 900;

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
				// CLI pairing: username + password travel in the JSON body.
				'path'                => '/device/challenge',
				'methods'             => 'POST',
				'callback'            => array( $this, 'challenge' ),
				'permission_callback' => array( $this, 'permission_challenge' ),
			),
			array(
				'path'                => '/device/register',
				'methods'             => 'POST',
				'callback'            => array( $this, 'register_device' ),
				'permission_callback' => array( $this, 'permission_register' ),
				'args'                => array(
					'pin'         => array( 'type' => 'string', 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
					'device_name' => array( 'type' => 'string', 'required' => false, 'default' => 'WPicker CLI', 'sanitize_callback' => 'sanitize_text_field' ),
				),
			),
			array(
				// WP-Admin: is a pairing open right now, and has a device joined?
				'path'                => '/device/pairing',
				'methods'             => 'GET',
				'callback'            => array( $this, 'pairing_status' ),
				'permission_callback' => array( $this, 'permission_session' ),
			),
			array(
				// WP-Admin: stop the open pairing by discarding its PIN.
				'path'                => '/device/pairing',
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'stop_pairing' ),
				'permission_callback' => array( $this, 'permission_session' ),
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
	 * Permission for issuing a challenge.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function permission_challenge( WP_REST_Request $request ) {
		return $this->authorize( $request );
	}

	/**
	 * Permission for registering a device. The PIN is checked separately.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	public function permission_register( WP_REST_Request $request ) {
		return $this->authorize( $request );
	}

	/**
	 * Identify the caller for pairing.
	 *
	 * Two ways in:
	 *   - an existing session (WP-Admin cookie + nonce, or an Application
	 *     Password) that can edit themes;
	 *   - `username` + `password` in the request body, verified here. Core's
	 *     REST Basic auth only understands Application Passwords, which a
	 *     first-time user does not have yet — pairing is how they get one.
	 *
	 * The password path is refused over plain HTTP outside local environments
	 * and is rate limited per username + IP.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	private function authorize( WP_REST_Request $request ) {
		// Core calls permission callbacks more than once per request (dispatch,
		// then again for every handler on the route to build the Allow
		// header). Decide once, or one bad password counts as several.
		$key = spl_object_id( $request );
		if ( ! array_key_exists( $key, $this->decisions ) ) {
			$this->decisions[ $key ] = $this->decide( $request );
		}
		return $this->decisions[ $key ];
	}

	/**
	 * Uncached body of authorize().
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|\WP_Error
	 */
	private function decide( WP_REST_Request $request ) {
		if ( $this->caps->can( Capability::SYNC_FILES ) ) {
			return true;
		}

		$username = trim( (string) $request->get_param( 'username' ) );
		$password = (string) $request->get_param( 'password' );
		if ( '' === $username || '' === $password ) {
			return $this->caps->rest_challenge();
		}

		if ( ! is_ssl() && 'local' !== wp_get_environment_type() ) {
			return new \WP_Error(
				'wpicker_insecure_transport',
				__( 'Pairing with a password requires HTTPS.', 'wpicker' ),
				array( 'status' => 403 )
			);
		}

		if ( $this->is_locked( $username ) ) {
			return new \WP_Error(
				'wpicker_locked',
				__( 'Too many failed attempts. Try again in 15 minutes.', 'wpicker' ),
				array( 'status' => 429 )
			);
		}

		$user = wp_authenticate( $username, $password );
		if ( is_wp_error( $user ) || ! user_can( $user, Capability::SYNC_FILES ) ) {
			// One answer for "wrong password" and "not allowed": no user enumeration.
			$this->record_failure( $username );
			return new \WP_Error(
				'wpicker_auth_failed',
				__( 'Invalid credentials, or this user cannot edit themes.', 'wpicker' ),
				array( 'status' => 401 )
			);
		}

		wp_set_current_user( $user->ID );
		$this->via_credentials = true;
		return true;
	}

	/**
	 * Transient key for the attempt counter of a username from this IP.
	 *
	 * @param string $username Submitted username.
	 * @return string
	 */
	private function attempts_key( string $username ): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		return 'wpicker_fail_' . md5( strtolower( $username ) . '|' . $ip );
	}

	/**
	 * Has this username + IP used up its attempts?
	 *
	 * @param string $username Submitted username.
	 * @return bool
	 */
	private function is_locked( string $username ): bool {
		return (int) get_transient( $this->attempts_key( $username ) ) >= self::MAX_ATTEMPTS;
	}

	/**
	 * Count a failed password or PIN attempt.
	 *
	 * @param string $username Submitted username.
	 * @return void
	 */
	private function record_failure( string $username ): void {
		$key = $this->attempts_key( $username );
		set_transient( $key, (int) get_transient( $key ) + 1, self::LOCKOUT_TTL );
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
	 * GET|POST /device/challenge — issue a PIN for the current user.
	 *
	 * @return WP_REST_Response
	 */
	public function challenge(): WP_REST_Response {
		$user_id = get_current_user_id();
		$issued  = $this->pins->issue( $user_id );

		$data = array(
			'user_id'        => $user_id,
			'expires_at_gmt' => $issued['expires_at_gmt'],
			'ttl'            => $issued['ttl'],
			'plugin_version' => WPICKER_VERSION,
			'hint'           => 'Read the PIN in WP-Admin → WPicker → Devices and enter it into the CLI prompt.',
		);

		// The PIN is the second factor: it proves the caller can also see
		// WP-Admin. Only an existing session gets it back in the response;
		// a caller who merely knows the password has to go and read it.
		if ( ! $this->via_credentials ) {
			$data['pin'] = $issued['pin'];
		}

		return $this->ok( $data );
	}

	/**
	 * POST /device/register — verify PIN and mint an Application Password.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function register_device( WP_REST_Request $request ): WP_REST_Response {
		$user_id = get_current_user_id();
		$pin     = (string) $request->get_param( 'pin' );
		$name    = (string) $request->get_param( 'device_name' );

		if ( ! $this->pins->verify( $user_id, $pin ) ) {
			if ( $this->via_credentials ) {
				$this->record_failure( trim( (string) $request->get_param( 'username' ) ) );
			}
			return $this->fail( 'wpicker_pin_invalid', 'PIN is invalid, expired, or already used.', 401 );
		}

		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			return $this->fail( 'wpicker_no_app_passwords', 'Application Passwords are not available on this site.', 500 );
		}

		$device_name = '' !== $name ? $name : 'WPicker CLI ' . gmdate( 'Y-m-d H:i' );
		$created = \WP_Application_Passwords::create_new_application_password(
			$user_id,
			array( 'name' => $device_name )
		);

		if ( is_wp_error( $created ) ) {
			return $this->fail( 'wpicker_app_password_failed', $created->get_error_message(), 500 );
		}

		// Core returns array( $raw_password, $item ).
		list( $raw_password, $new_item ) = $created;

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
	 * Permission for the pairing controls in WP-Admin: an existing session
	 * that may pair devices. Username + password in the body does not count —
	 * that caller must not be able to read the PIN it is supposed to be shown.
	 *
	 * @return bool|\WP_Error
	 */
	public function permission_session() {
		return $this->caps->rest_challenge();
	}

	/**
	 * GET /device/pairing — the current user's open pairing, if any.
	 *
	 * Lets the Devices screen show a PIN the CLI just asked for, count it
	 * down, and notice when a device used it.
	 *
	 * @return WP_REST_Response
	 */
	public function pairing_status(): WP_REST_Response {
		$user_id = get_current_user_id();
		$current = $this->pins->current( $user_id );
		$mine    = 0;
		foreach ( $this->all_devices() as $device ) {
			if ( (int) ( $device['user_id'] ?? 0 ) === $user_id ) {
				++$mine;
			}
		}

		$data = array(
			'active'       => null !== $current,
			'device_count' => $mine,
		);
		if ( null !== $current ) {
			$data['pin']        = $current['pin'];
			$data['expires_in'] = max( 0, strtotime( $current['expires_at_gmt'] ) - time() );
		}
		return $this->ok( $data );
	}

	/**
	 * DELETE /device/pairing — discard the current user's PIN, so a pairing
	 * that was started (here or by the CLI) can no longer be completed.
	 *
	 * @return WP_REST_Response
	 */
	public function stop_pairing(): WP_REST_Response {
		$this->pins->clear( get_current_user_id() );
		return $this->ok( array( 'active' => false ) );
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
