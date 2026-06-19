<?php
/**
 * Core loader — registers all REST routes, hooks, and admin UI.
 *
 * Booted from wpicker() on `plugins_loaded`. Acts as the single composition root:
 * it instantiates each subsystem once and wires their hooks.
 *
 * @package WPicker
 */

declare( strict_types = 1 );

namespace WPicker;

use WPicker\Auth\Capability;
use WPicker\REST\Base;
use WPicker\REST\Context;
use WPicker\REST\Device;
use WPicker\REST\Theme;
use WPicker\REST\Vault;

defined( 'ABSPATH' ) || exit;

/**
 * Class Core
 *
 * Composition root. Holds shared service instances and registers hooks.
 */
final class Core {

	/**
	 * REST namespace shared by all routes.
	 *
	 * @var string
	 */
	public const REST_NAMESPACE = 'wpicker/v1';

	/**
	 * Capability helper.
	 *
	 * @var Capability
	 */
	public $caps;

	/**
	 * Context provider (site metadata assembler).
	 *
	 * @var \WPicker\Context\Provider
	 */
	public $context;

	/**
	 * Theme file sync helper (path confinement + IO).
	 *
	 * @var \WPicker\Sync\PathGuard
	 */
	public $path_guard;

	/**
	 * PHP linter.
	 *
	 * @var \WPicker\Lint\PhpLint
	 */
	public $lint;

	/**
	 * Vault manifest store.
	 *
	 * @var \WPicker\Vault\Manifest
	 */
	public $manifests;

	/**
	 * Vault snapshot manager.
	 *
	 * @var \WPicker\Vault\Manager
	 */
	public $vault;

	/**
	 * Admin dashboard controller.
	 *
	 * @var \WPicker\Admin\Dashboard|null
	 */
	public $admin;

	/**
	 * Boot the plugin.
	 *
	 * @return void
	 */
	public function boot(): void {
		require_once WPICKER_DIR . 'includes/Auth/Capability.php';

		$this->caps       = new Capability();
		$this->context    = new \WPicker\Context\Provider();
		$this->path_guard = new \WPicker\Sync\PathGuard();
		$this->lint       = new \WPicker\Lint\PhpLint();
		$this->manifests  = new \WPicker\Vault\Manifest();
		$this->vault      = new \WPicker\Vault\Manager( $this->manifests, $this->path_guard );

		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Track device last-seen: when an Application Password is used against
		// our namespace, bump the matching device's last_seen_gmt.
		add_filter( 'rest_pre_dispatch', array( $this, 'touch_device' ), 10, 4 );

		if ( is_admin() ) {
			$this->admin = new \WPicker\Admin\Dashboard( $this->caps );
			$this->admin->register();
		}
	}

	/**
	 * Register all REST routes under wpicker/v1.
	 *
	 * Each controller is instantiated with the shared services it needs; they
	 * declare their own permission/sanitize callbacks.
	 *
	 * @return void
	 */
	public function register_rest_routes(): void {
		$controllers = array(
			new Context( $this->context ),
			new Device( $this->caps ),
			new Theme( $this->path_guard, $this->lint, $this->vault ),
			new Vault( $this->vault ),
			new Vuln(),
			new Page(),
		);

		foreach ( $controllers as $controller ) {
			if ( $controller instanceof Base ) {
				$controller->register( self::REST_NAMESPACE );
			}
		}

		// Register System auto-updater
		$updater = new System\Updater();
		$updater->register();
	}

	/**
	 * Update last_seen on the device used for the current REST request.
	 *
	 * Fired on rest_pre_dispatch. When the request was authenticated with an
	 * Application Password, core exposes its uuid via the REST server; we use
	 * that to find our device record and stamp it.
	 *
	 * @param mixed           $result  Precomputed result (null by default).
	 * @param \WP_REST_Server $server  REST server.
	 * @param \WP_REST_Request $request Request.
	 * @param string          $route   Matched route.
	 * @return mixed Unchanged $result.
	 */
	public function touch_device( $result, $server, $request, $route ) {
		if ( ! is_string( $route ) || 0 !== strpos( $route, '/' . self::REST_NAMESPACE . '/' ) ) {
			return $result;
		}
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return $result;
		}
		// Application Password uuid is surfaced on the request when used.
		$ap_uuid = '';
		if ( $request instanceof \WP_REST_Request ) {
			$ap_uuid = (string) ( $request->get_param( '_wpicker_ap_uuid' ) ?? '' );
		}
		// Best-effort: derive from app password meta on the user (most recent).
		if ( '' === $ap_uuid && class_exists( 'WP_Application_Passwords' ) ) {
			$aps = \WP_Application_Passwords::get_user_application_passwords( $user_id );
			if ( is_array( $aps ) ) {
				foreach ( $aps as $ap ) {
					if ( ! empty( $ap['last_used'] ) && ( $ap['last_used'] ?? 0 ) > ( time() - 5 ) ) {
						$ap_uuid = (string) ( $ap['uuid'] ?? '' );
						break;
					}
				}
			}
		}
		if ( '' !== $ap_uuid ) {
			$device = new Device( $this->caps );
			$device->touch( $ap_uuid );
		}
		return $result;
	}

	/**
	 * Singleton access (also exposed via wpicker() helper).
	 *
	 * @return self
	 */
	public static function instance(): self {
		return wpicker();
	}
}
