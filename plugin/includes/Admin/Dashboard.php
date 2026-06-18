<?php
/**
 * Admin Dashboard controller — WPicker menu, Devices & Vault/History screens.
 *
 * Wired by Core::boot() when is_admin(). Renders two pages:
 *   - Devices: list registered devices, generate PIN, revoke.
 *   - Vault: list manifests, drill-down, restore, view aborted error logs.
 *
 * @package WPicker\Admin
 */

declare( strict_types = 1 );

namespace WPicker\Admin;

use WPicker\Auth\Capability;
use WPicker\Auth\Pin;

defined( 'ABSPATH' ) || exit;

/**
 * Class Dashboard
 */
final class Dashboard {

	/**
	 * Slug for the Devices screen.
	 */
	public const SLUG_DEVICES = 'wpicker-devices';

	/**
	 * Slug for the Vault / History screen.
	 */
	public const SLUG_VAULT = 'wpicker-vault';

	/**
	 * Capability helper.
	 *
	 * @var Capability
	 */
	private $caps;

	/**
	 * Constructor.
	 *
	 * @param Capability $caps Capability helper.
	 */
	public function __construct( Capability $caps ) {
		$this->caps = $caps;
	}

	/**
	 * Register admin hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Build the menu structure.
	 *
	 * @return void
	 */
	public function menu(): void {
		if ( ! $this->caps->can( Capability::MANAGE ) ) {
			return;
		}

		add_menu_page(
			__( 'WPicker', 'wpicker' ),
			__( 'WPicker', 'wpicker' ),
			Capability::MANAGE,
			self::SLUG_DEVICES,
			array( $this, 'render_devices' ),
			'dashicons-shield-alt',
			80
		);

		add_submenu_page(
			self::SLUG_DEVICES,
			__( 'Devices', 'wpicker' ),
			__( 'Devices', 'wpicker' ),
			Capability::MANAGE,
			self::SLUG_DEVICES,
			array( $this, 'render_devices' )
		);

		add_submenu_page(
			self::SLUG_DEVICES,
			__( 'Vault / History', 'wpicker' ),
			__( 'Vault / History', 'wpicker' ),
			Capability::MANAGE,
			self::SLUG_VAULT,
			array( $this, 'render_vault' )
		);

		add_submenu_page(
			self::SLUG_DEVICES,
			__( 'Vulnerability Scan', 'wpicker' ),
			__( 'Vulnerability Scan', 'wpicker' ),
			Capability::MANAGE,
			'wpicker-vuln',
			array( $this, 'render_vuln' )
		);
	}

	/**
	 * Enqueue admin assets on our screens only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function assets( string $hook ): void {
		if ( false === strpos( $hook, self::SLUG_DEVICES ) && false === strpos( $hook, self::SLUG_VAULT ) ) {
			return;
		}
		wp_enqueue_style(
			'wpicker-admin',
			WPICKER_URL . 'admin/css/admin.css',
			array(),
			WPICKER_VERSION
		);
		wp_enqueue_script(
			'wpicker-admin',
			WPICKER_URL . 'admin/js/admin.js',
			array(),
			WPICKER_VERSION,
			true
		);
		wp_localize_script(
			'wpicker-admin',
			'WPickerAdmin',
			array(
				'rest_root' => esc_url_raw( rest_url( 'wpicker/v1' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Render the Devices screen.
	 *
	 * @return void
	 */
	public function render_devices(): void {
		$user_id = get_current_user_id();
		$pin     = ( new Pin() )->current( $user_id );

		require WPICKER_DIR . 'admin/views/devices.php';
	}

	/**
	 * Render the Vulnerability Scan screen.
	 *
	 * @return void
	 */
	public function render_vuln(): void {
		require WPICKER_DIR . 'admin/views/vuln.php';
	}
	public function render_vault(): void {
		$manifests = wpicker()->manifests->all();
		$manifests = array_reverse( $manifests ); // newest first.

		require WPICKER_DIR . 'admin/views/vault.php';
	}
}
