<?php
/**
 * Approve — the browser half of pairing a device.
 *
 * `wpicker login` prints a link to this screen. It lives in wp-admin, so
 * WordPress's own login applies first (password, and any two-factor plugin), and
 * only then does a person see which site and which device are asking and decide.
 * The decision is recorded against the logged-in user; the CLI collects the
 * credentials with its own secret, never from this page.
 *
 * Approving is a POST with a nonce (GET never changes anything), and asks the
 * person to type the code from their terminal when the request came from a
 * different network than this browser: that is where phishing happens.
 *
 * @package WPicker\Admin
 */

declare( strict_types = 1 );

namespace WPicker\Admin;

use WPicker\Auth\Capability;
use WPicker\Auth\DeviceFlow;
use WPicker\Auth\PairRateLimit;
use WPicker\Auth\WpdbRequestStore;

defined( 'ABSPATH' ) || exit;

/**
 * Class Approve
 */
final class Approve {

	public const SLUG   = 'wpicker-approve';
	public const ACTION = 'wpicker_device_decide';

	/**
	 * @var DeviceFlow
	 */
	private $flow;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->flow = new DeviceFlow( new WpdbRequestStore() );
	}

	/**
	 * Hook in.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
	}

	/**
	 * A page with no menu entry: it is reached from the link the CLI prints. Its
	 * own capability is the one pairing needs, so a user who may pair devices but
	 * not manage WPicker can still approve their own request.
	 *
	 * @return void
	 */
	public function menu(): void {
		add_submenu_page(
			'', // Hidden.
			__( 'Approve a device', 'wpicker' ),
			'',
			Capability::SYNC_FILES,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * The screen.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Capability::SYNC_FILES ) ) {
			wp_die( esc_html__( 'You are not allowed to approve devices.', 'wpicker' ), '', array( 'response' => 403 ) );
		}
		$typed = isset( $_GET['user_code'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['user_code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$msg   = isset( $_GET['wpicker_msg'] ) ? sanitize_key( wp_unslash( (string) $_GET['wpicker_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification

		$found        = '' !== $typed ? $this->flow->lookup( $typed ) : null;
		$here         = PairRateLimit::bucket( PairRateLimit::client_ip() );
		$proxied      = PairRateLimit::behind_proxy();
		// Behind a proxy every visitor looks like one address, so "same network" would always be
		// true and the check would never fire: treat the network as unknown and ask for the code.
		$same_network = null !== $found && ! $proxied && ( $found['request']['bucket'] ?? '' ) === $here;

		require WPICKER_DIR . 'admin/views/approve.php';
	}

	/**
	 * The form post: approve or deny.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! current_user_can( Capability::SYNC_FILES ) ) {
			wp_die( esc_html__( 'You are not allowed to approve devices.', 'wpicker' ), '', array( 'response' => 403 ) );
		}
		$hash = isset( $_POST['hash'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['hash'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		check_admin_referer( 'wpicker_dc_' . $hash );

		$decision = ( isset( $_POST['decision'] ) && 'approve' === $_POST['decision'] ) ? 'approved' : 'denied';
		$req      = $this->flow->request( $hash );
		if ( null === $req ) {
			$this->back( '', 'expired' );
		}
		$code = (string) $req['user_code'];

		if ( 'approved' === $decision ) {
			$same = ! PairRateLimit::behind_proxy() && ( $req['bucket'] ?? '' ) === PairRateLimit::bucket( PairRateLimit::client_ip() );
			if ( ! $same ) {
				// A request from somewhere else: the person must read the code off the terminal that started it.
				$typed = DeviceFlow::normalize_user_code( isset( $_POST['typed_code'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['typed_code'] ) ) : '' );
				if ( ! hash_equals( $code, $typed ) ) {
					$this->back( $code, 'mismatch' );
				}
			}
		}

		$won = $this->flow->decide( $hash, $decision, get_current_user_id(), PairRateLimit::client_ip() );
		$this->back( $code, $won ? $decision : 'already' );
	}

	/**
	 * Return to the screen with a message.
	 *
	 * @param string $code User code to keep in the link ('' = none).
	 * @param string $msg  Message key.
	 * @return void
	 */
	private function back( string $code, string $msg ): void {
		$args = array(
			'page'        => self::SLUG,
			'wpicker_msg' => $msg,
		);
		if ( '' !== $code ) {
			$args['user_code'] = DeviceFlow::format( $code );
		}
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}
}
