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
use WPicker\Auth\DeviceFlow;
use WPicker\Auth\PairRateLimit;
use WPicker\Auth\WpdbRequestStore;
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
	 * Pairing requests from the CLI, approved in a browser.
	 *
	 * @var DeviceFlow
	 */
	private $flow;

	/**
	 * Per-source request counting for the anonymous routes.
	 *
	 * @var PairRateLimit
	 */
	private $limit;

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
	 * Pairing requests one source may start in 15 minutes, polls it may make, and
	 * wrong device_codes it may send. The device_code is 192 bits: guessing is not
	 * the worry, noise is.
	 */
	private const MAX_STARTS = 5;
	private const MAX_POLLS  = 600;
	private const MAX_MISSES = 20;

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
		$this->caps  = $caps;
		$this->pins  = new Pin();
		$this->flow  = new DeviceFlow( new WpdbRequestStore() );
		$this->limit = new PairRateLimit();
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
				// CLI: ask to pair. ANONYMOUS on purpose; it only creates a pending
				// request that a logged-in administrator must approve in a browser.
				'path'                => '/device/start',
				'methods'             => 'POST',
				'callback'            => array( $this, 'start' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'device_name' => array( 'type' => 'string', 'required' => false, 'default' => '', 'maxLength' => 200 ),
				),
			),
			array(
				// CLI: has it been approved yet? The device_code is the credential;
				// nothing about the request's own identity is ever used.
				'path'                => '/device/poll',
				'methods'             => 'POST',
				'callback'            => array( $this, 'poll' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'device_code' => array( 'type' => 'string', 'required' => true, 'maxLength' => 128 ),
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
		// A paired device (an Application Password) must not mint more devices: revoking a
		// stolen token would otherwise not end access, because it could have made siblings.
		if ( function_exists( 'rest_get_authenticated_app_password' ) && null !== rest_get_authenticated_app_password() ) {
			return new \WP_Error(
				'wpicker_forbidden_device',
				__( 'A paired device cannot pair more devices. Do it in WP-Admin or from a computer you are logged in on.', 'wpicker' ),
				array( 'status' => 403 )
			);
		}
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

		return $this->nostore( $this->ok( $data ) );
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
			return $this->nostore( $this->fail( 'wpicker_pin_invalid', 'PIN is invalid, expired, or already used.', 401 ) );
		}

		$minted = $this->mint_device( $user_id, $name, 'password', PairRateLimit::client_ip() );
		if ( is_wp_error( $minted ) ) {
			return $this->nostore( $this->fail( $minted->get_error_code(), $minted->get_error_message(), 500 ) );
		}
		return $this->nostore( $this->ok( $minted, 201 ) );
	}

	/**
	 * Create the Application Password and the device record for a user. The one
	 * place a device is minted, so both pairing paths behave the same.
	 *
	 * @param int    $user_id User the device belongs to. Always passed in, never read
	 *                        from the request: the code path is anonymous.
	 * @param string $name    Device name ('' = generated).
	 * @param string $method  How it was paired: password | code.
	 * @param string $ip      Address that paired it.
	 * @return array<string,mixed>|\WP_Error
	 */
	private function mint_device( int $user_id, string $name, string $method, string $ip ) {
		if ( $user_id <= 0 || ! class_exists( 'WP_Application_Passwords' ) ) {
			return new \WP_Error( 'wpicker_no_app_passwords', 'Application Passwords are not available on this site.' );
		}

		$device_name = '' !== $name ? $name : 'WPicker CLI ' . gmdate( 'Y-m-d H:i' );
		$created     = \WP_Application_Passwords::create_new_application_password(
			$user_id,
			array( 'name' => $device_name )
		);
		if ( is_wp_error( $created ) ) {
			return new \WP_Error( 'wpicker_app_password_failed', $created->get_error_message() );
		}

		// Core returns array( $raw_password, $item ).
		list( $raw_password, $new_item ) = $created;

		$record = array(
			'id'             => (string) $new_item['uuid'],
			'user_id'        => $user_id,
			'name'           => $device_name,
			'created_at_gmt' => gmdate( 'c' ),
			'last_seen_gmt'  => null,
			'method'         => $method,
			'paired_from'    => $ip,
		);
		$this->store_device( $record );

		return array(
			'user_id'      => $user_id,
			'device_id'    => $record['id'],
			'app_password' => $raw_password, // surfaced ONCE here; never retrievable again.
			'device_name'  => $device_name,
			'note'         => 'Store this app password securely; it cannot be retrieved again.',
		);
	}

	/**
	 * Responses that carry a secret must never be cached by a proxy or CDN.
	 * WordPress only sends no-cache headers for logged-in requests, and these
	 * routes are reached anonymously.
	 *
	 * @param WP_REST_Response $response Response.
	 * @return WP_REST_Response
	 */
	private function nostore( WP_REST_Response $response ): WP_REST_Response {
		$response->header( 'Cache-Control', 'no-store, private' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	/**
	 * POST /device/start — the CLI asks to pair.
	 *
	 * Anonymous: it creates a pending request and hands back the CLI's secret
	 * device_code plus a link for a person. Nothing is granted until a logged-in
	 * administrator approves that request in a browser.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function start( WP_REST_Request $request ): WP_REST_Response {
		$ip = PairRateLimit::client_ip();

		if ( ! is_ssl() && 'local' !== wp_get_environment_type() ) {
			return $this->nostore( $this->fail( 'wpicker_insecure_transport', 'Pairing needs HTTPS. Use the https:// address of this site.', 403 ) );
		}
		if ( $this->limit->hit( 'start', $ip, 900 ) > self::MAX_STARTS ) {
			return $this->nostore( $this->fail( 'wpicker_busy', 'Too many pairing requests from this address. Try again in a few minutes.', 429 ) );
		}

		$agent   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : ''; // phpcs:ignore WordPress.Security
		$started = $this->flow->start( (string) $request->get_param( 'device_name' ), $ip, $agent );
		if ( null === $started ) {
			return $this->nostore( $this->fail( 'wpicker_busy', 'Too many pairing requests are waiting on this site. Try again in a few minutes, or pair with the admin password: wpicker login <site> --password', 429 ) );
		}

		// admin_url() so the link points at THIS site's own admin, whatever address the request used.
		$link = add_query_arg(
			array(
				'page'      => 'wpicker-approve',
				'user_code' => $started['user_code'],
			),
			admin_url( 'admin.php' )
		);
		return $this->nostore(
			$this->ok(
				array(
					'device_code'      => $started['device_code'],
					'user_code'        => $started['user_code'],
					'verification_url' => $link,
					'expires_in'       => $started['expires_in'],
					'interval'         => $started['interval'],
					'site_name'        => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
				),
				201
			)
		);
	}

	/**
	 * POST /device/poll — has the request been approved?
	 *
	 * Anonymous: the device_code is the credential. The user comes ONLY from the
	 * decision a logged-in administrator recorded; the request's own identity (a
	 * stray cookie, a Basic header for someone else) is never used.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function poll( WP_REST_Request $request ): WP_REST_Response {
		$ip = PairRateLimit::client_ip();

		if ( ! is_ssl() && 'local' !== wp_get_environment_type() ) {
			return $this->nostore( $this->fail( 'wpicker_insecure_transport', 'Pairing needs HTTPS. Use the https:// address of this site.', 403 ) );
		}
		if ( $this->limit->hit( 'poll', $ip, 900 ) > self::MAX_POLLS || $this->limit->count( 'miss', $ip ) > self::MAX_MISSES ) {
			return $this->nostore( $this->fail( 'wpicker_busy', 'Too many requests from this address. Try again in a few minutes.', 429 ) );
		}

		$result = $this->flow->poll( (string) $request->get_param( 'device_code' ) );
		switch ( $result['status'] ) {
			case 'pending':
				return $this->nostore( $this->fail( 'authorization_pending', 'Waiting for approval in the browser.', 400 ) );
			case 'slow_down':
				return $this->nostore( $this->fail( 'slow_down', 'Asking too often; wait a little longer between requests.', 400 ) );
			case 'denied':
				return $this->nostore( $this->fail( 'access_denied', 'The request was denied in the browser.', 403 ) );
			case 'expired':
				return $this->nostore( $this->fail( 'expired_token', 'The request expired before it was approved. Start again.', 400 ) );
			case 'approved':
				break;
			default:
				// Unknown, used, or malformed: one answer for all, and it counts against the source.
				$this->limit->hit( 'miss', $ip, 900 );
				return $this->nostore( $this->fail( 'wpicker_code_invalid', 'Unknown or already used pairing request.', 401 ) );
		}

		// The person may have been demoted or removed between approving and now.
		$user = get_userdata( (int) $result['user_id'] );
		if ( ! $user || ! user_can( $user, Capability::SYNC_FILES ) || ( is_multisite() && ! is_user_member_of_blog( $user->ID ) && ! is_super_admin( $user->ID ) ) ) {
			return $this->nostore( $this->fail( 'access_denied', 'The approving user is no longer allowed to pair devices.', 403 ) );
		}

		$req    = (array) $result['request'];
		$minted = $this->mint_device( (int) $user->ID, (string) ( $req['name'] ?? '' ), 'device', (string) ( $req['ip'] ?? $ip ) );
		if ( is_wp_error( $minted ) ) {
			return $this->nostore( $this->fail( $minted->get_error_code(), $minted->get_error_message(), 500 ) );
		}
		$this->notify_paired( $user, (string) $minted['device_name'], (string) ( $req['ip'] ?? '' ) );

		$minted['plugin_version'] = defined( 'WPICKER_VERSION' ) ? WPICKER_VERSION : '';
		$minted['site_name']      = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$minted['user_login']     = (string) $user->user_login;
		return $this->nostore( $this->ok( $minted, 201 ) );
	}

	/**
	 * Tell the approving user that a device now has access, so an approval they did
	 * not mean to give is noticed.
	 *
	 * @param \WP_User $user        Who approved.
	 * @param string   $device_name Device.
	 * @param string   $ip          Address that asked.
	 * @return void
	 */
	private function notify_paired( $user, string $device_name, string $ip ): void {
		if ( ! function_exists( 'wp_mail' ) || empty( $user->user_email ) ) {
			return;
		}
		$site = (string) get_bloginfo( 'name' );
		$body = sprintf(
			/* translators: 1: site name, 2: device name, 3: IP address, 4: Devices page URL. */
			__( "A device was just paired with %1\$s using your account.\n\nDevice: %2\$s\nRequested from: %3\$s\n\nIf this was not you, revoke it now: %4\$s\n", 'wpicker' ),
			$site,
			$device_name,
			'' === $ip ? __( 'unknown', 'wpicker' ) : $ip,
			admin_url( 'admin.php?page=wpicker-devices' )
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		@wp_mail( $user->user_email, sprintf( __( '[%s] A device was paired with WPicker', 'wpicker' ), $site ), $body );
	}

	/**
	 * Permission for the pairing controls in WP-Admin: an existing session
	 * that may pair devices. Username + password in the body does not count —
	 * that caller must not be able to read the PIN it is supposed to be shown.
	 *
	 * @return bool|\WP_Error
	 */
	public function permission_session() {
		if ( function_exists( 'rest_get_authenticated_app_password' ) && null !== rest_get_authenticated_app_password() ) {
			return new \WP_Error(
				'wpicker_forbidden_device',
				__( 'A paired device cannot read or change pairing. Use WP-Admin.', 'wpicker' ),
				array( 'status' => 403 )
			);
		}
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

		$recent = null;
		foreach ( $this->all_devices() as $device ) {
			$made = isset( $device['created_at_gmt'] ) ? strtotime( (string) $device['created_at_gmt'] ) : false;
			if ( (int) ( $device['user_id'] ?? 0 ) === $user_id && false !== $made && time() - $made < 600 ) {
				if ( null === $recent || $made > strtotime( (string) $recent['created_at_gmt'] ) ) {
					$recent = $device;
				}
			}
		}

		$data = array(
			'active'       => null !== $current,
			'device_count' => $mine,
			'last_paired'  => null === $recent ? null : array(
				'id'             => (string) ( $recent['id'] ?? '' ),
				'name'           => (string) ( $recent['name'] ?? '' ),
				'method'         => (string) ( $recent['method'] ?? '' ),
				'paired_from'    => (string) ( $recent['paired_from'] ?? '' ),
				'created_at_gmt' => (string) ( $recent['created_at_gmt'] ?? '' ),
			),
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
				$prev = isset( $d['last_seen_gmt'] ) ? strtotime( (string) $d['last_seen_gmt'] ) : false;
				if ( false !== $prev && time() - $prev < 300 ) {
					return; // seen minutes ago: not worth rewriting the whole list on every request
				}
				$devices[ $i ]['last_seen_gmt'] = gmdate( 'c' );
				update_option( self::DEVICES_OPTION, $devices, false );
				return;
			}
		}
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
