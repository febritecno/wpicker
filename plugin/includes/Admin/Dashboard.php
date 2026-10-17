<?php
/**
 * Admin Dashboard controller — WPicker menu, Devices & Snapshots screens.
 *
 * Wired by Core::boot() when is_admin(). Renders two pages:
 *   - Devices: list registered devices, generate PIN, revoke.
 *   - Snapshots: list manifests, drill-down, restore, view aborted error logs.
 *
 * @package WPicker\Admin
 */

declare( strict_types = 1 );

namespace WPicker\Admin;

use WPicker\Auth\Capability;

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
	 * Slug for the Snapshots screen (slug predates the rename; kept so links keep working).
	 */
	public const SLUG_VAULT = 'wpicker-vault';

	/**
	 * Slug for the vulnerability scan screen.
	 */
	public const SLUG_VULN = 'wpicker-vuln';

	/**
	 * Slug for the Backup & Restore screen.
	 */
	public const SLUG_BACKUPS = 'wpicker-backups';

	/**
	 * Slug for the settings screen.
	 */
	public const SLUG_SETTINGS = 'wpicker-settings';

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
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_head', array( $this, 'menu_icon_style' ) );
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
			WPICKER_URL . 'admin/img/menu-icon.png',
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
			__( 'Snapshots', 'wpicker' ),
			__( 'Snapshots', 'wpicker' ),
			Capability::MANAGE,
			self::SLUG_VAULT,
			array( $this, 'render_vault' )
		);

		add_submenu_page(
			self::SLUG_DEVICES,
			__( 'Backup & Restore', 'wpicker' ),
			__( 'Backup & Restore', 'wpicker' ),
			Capability::MANAGE,
			self::SLUG_BACKUPS,
			array( $this, 'render_backups' )
		);

		add_submenu_page(
			self::SLUG_DEVICES,
			__( 'Vulnerability Scan', 'wpicker' ),
			__( 'Vulnerability Scan', 'wpicker' ),
			Capability::MANAGE,
			self::SLUG_VULN,
			array( $this, 'render_vuln' )
		);

		add_submenu_page(
			self::SLUG_DEVICES,
			__( 'Settings', 'wpicker' ),
			__( 'Settings', 'wpicker' ),
			Capability::MANAGE,
			self::SLUG_SETTINGS,
			array( $this, 'render_settings' )
		);
	}

	/**
	 * Register the settings form (WPicker → Settings).
	 *
	 * @return void
	 */
	public function register_settings(): void {
		register_setting(
			'wpicker_settings_group',
			'wpicker_settings',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
			)
		);
	}

	/**
	 * Sanitize the settings form, keeping keys the form does not own.
	 *
	 * @param mixed $input Submitted values.
	 * @return array<string,mixed>
	 */
	public function sanitize_settings( $input ): array {
		$settings = get_option( 'wpicker_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();
		$input    = is_array( $input ) ? $input : array();

		$settings['push_mode']    = 'review' === ( $input['push_mode'] ?? '' ) ? 'review' : 'direct';
		$settings['lint_enabled'] = empty( $input['lint_enabled'] ) ? 0 : 1;
		$settings['health_check'] = empty( $input['health_check'] ) ? 0 : 1;
		$settings['allow_backup'] = empty( $input['allow_backup'] ) ? 0 : 1;
		$settings['backup_keep']  = max( 1, min( 20, (int) ( $input['backup_keep'] ?? 3 ) ) );
		$settings['allow_inspect'] = empty( $input['allow_inspect'] ) ? 0 : 1;
		$settings['debug_log']     = empty( $input['debug_log'] ) ? 0 : 1;
		$settings['vault_keep']   = max( 1, min( 200, (int) ( $input['vault_keep'] ?? 30 ) ) );

		return $settings;
	}

	/**
	 * Shared page header: product name, tagline and section tabs.
	 *
	 * @param string $active Slug of the current screen.
	 * @return void
	 */
	public function render_header( string $active ): void {
		$in_review = 0;
		foreach ( wpicker()->manifests->all() as $m ) {
			if ( 'review' === ( $m['status'] ?? '' ) ) {
				++$in_review;
			}
		}
		$tabs = array(
			self::SLUG_DEVICES  => __( 'Devices', 'wpicker' ),
			self::SLUG_VAULT    => __( 'Snapshots', 'wpicker' ),
			self::SLUG_BACKUPS  => __( 'Backup & Restore', 'wpicker' ),
			self::SLUG_VULN     => __( 'Security scan', 'wpicker' ),
			self::SLUG_SETTINGS => __( 'Settings', 'wpicker' ),
		);
		require WPICKER_DIR . 'admin/views/header.php';
	}

	/**
	 * Size the sidebar menu icon. WordPress shows an image icon at its
	 * natural size; ours is 40px so it stays sharp on high-density screens,
	 * and has to be told to display at 20px. The sidebar is on every admin
	 * screen, so this cannot live in the stylesheet loaded only on ours.
	 *
	 * @return void
	 */
	public function menu_icon_style(): void {
		echo '<style>#adminmenu .toplevel_page_' . esc_attr( self::SLUG_DEVICES ) . ' .wp-menu-image img{width:20px;height:20px;padding-top:7px;opacity:1}</style>';
	}

	/**
	 * Enqueue admin assets on our screens only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function assets( string $hook ): void {
		// Every WPicker screen's hook suffix contains "wpicker-".
		if ( false === strpos( $hook, 'wpicker-' ) ) {
			return;
		}
		wp_enqueue_style(
			'wpicker-admin',
			WPICKER_URL . 'admin/css/admin.css',
			array(),
			WPICKER_VERSION . '.' . (string) filemtime( WPICKER_DIR . 'admin/css/admin.css' )
		);
		wp_enqueue_script(
			'wpicker-admin',
			WPICKER_URL . 'admin/js/admin.js',
			array(),
			WPICKER_VERSION . '.' . (string) filemtime( WPICKER_DIR . 'admin/js/admin.js' ),
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
	/**
	 * Render the Snapshots screen.
	 *
	 * @return void
	 */
	public function render_vault(): void {
		$manifests = wpicker()->manifests->all();
		$manifests = array_reverse( $manifests ); // newest first.
		$settings  = get_option( 'wpicker_settings', array() );
		$push_mode = 'review' === ( $settings['push_mode'] ?? 'direct' ) ? 'review' : 'direct';

		require WPICKER_DIR . 'admin/views/vault.php';
	}

	/**
	 * Render the Backup & Restore screen.
	 *
	 * @return void
	 */
	public function render_backups(): void {
		$backups = array();
		foreach ( \WPicker\Backup\Job::all() as $job ) {
			$backups[] = $job->progress();
		}
		$settings = get_option( 'wpicker_settings', array() );
		$enabled  = (int) ( $settings['allow_backup'] ?? 1 ) === 1;
		$keep     = max( 1, (int) ( $settings['backup_keep'] ?? 3 ) );
		$restore  = \WPicker\Backup\Restore::current();
		$restore  = null === $restore ? null : $restore->progress();

		require WPICKER_DIR . 'admin/views/backups.php';
	}

	/**
	 * Render the Settings screen.
	 *
	 * @return void
	 */
	public function render_settings(): void {
		$settings = wp_parse_args(
			(array) get_option( 'wpicker_settings', array() ),
			array(
				'push_mode'    => 'direct',
				'lint_enabled' => 1,
				'health_check' => 1,
				'allow_backup' => 1,
				'backup_keep'  => 3,
				'allow_inspect' => 0,
				'debug_log'     => 0,
				'vault_keep'   => 30,
			)
		);

		$audit = array_reverse( array_slice( \WPicker\Inspect\Audit::entries(), -15 ) );

		require WPICKER_DIR . 'admin/views/settings.php';
	}
}
