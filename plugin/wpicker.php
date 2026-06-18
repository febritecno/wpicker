<?php
/**
 * Plugin Name:       WPicker
 * Plugin URI:        https://example.com/wpicker
 * Description:       The Eyes — exposes AI-friendly site context, child-theme sync, and a Deployment Vault (snapshot/rollback) for the WPicker CLI.
 * Version:           1.1.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            WPicker
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wpicker
 * Domain Path:       /languages
 *
 * @package WPicker
 */

declare( strict_types = 1 );

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap constants.
 */
function _wpicker_define( string $key, $value ): void {
	if ( ! defined( $key ) ) {
		define( $key, $value );
	}
}

_wpicker_define( 'WPICKER_VERSION', '1.1.0' );
_wpicker_define( 'WPICKER_FILE', __FILE__ );
_wpicker_define( 'WPICKER_DIR', plugin_dir_path( __FILE__ ) );
_wpicker_define( 'WPICKER_URL', plugin_dir_url( __FILE__ ) );
_wpicker_define( 'WPICKER_BASENAME', plugin_basename( __FILE__ ) );

/**
 * PSR-4 autoloader for the WPicker namespace.
 *
 * Maps WPicker\Foo\Bar to includes/Foo/Bar.php. Avoids a hard Composer
 * dependency in production; the same paths also satisfy composer's PSR-4 rule
 * if Composer's autoloader is present (it is loaded first below).
 *
 * @param string $class Fully-qualified class name.
 */
function wpicker_autoload( string $class ): void {
	if ( strpos( $class, 'WPicker\\' ) !== 0 ) {
		return;
	}
	$relative = substr( $class, strlen( 'WPicker\\' ) );
	$relative = str_replace( array( '\\', '_' ), array( '/', '-' ), $relative );
	$path     = WPICKER_DIR . 'includes/' . $relative . '.php';
	if ( is_readable( $path ) ) {
		require_once $path;
	}
}
spl_autoload_register( 'wpicker_autoload' );

// Use Composer's autoloader when present (dev / tests).
if ( is_readable( WPICKER_DIR . 'vendor/autoload.php' ) ) {
	require_once WPICKER_DIR . 'vendor/autoload.php';
}

/**
 * Activation: ensure required storage exists, default options seeded.
 * No DB writes from CLI ever, but plugin-owned storage is fine.
 */
function wpicker_activate(): void {
	$defaults = get_option( 'wpicker_settings', array() );
	if ( ! is_array( $defaults ) ) {
		$defaults = array();
	}
	$defaults = wp_parse_args(
		$defaults,
		array(
			'vault_keep'      => 30,     // snapshots to retain
			'pin_ttl'         => 600,    // seconds
			'pin_length'      => 6,
			'lint_enabled'    => 1,
		)
	);
	update_option( 'wpicker_settings', $defaults, false );

	if ( false === get_option( 'wpicker_vault_manifests', false ) ) {
		add_option( 'wpicker_vault_manifests', array() );
	}
}
register_activation_hook( __FILE__, 'wpicker_activate' );

/**
 * Boot the plugin on `plugins_loaded`.
 *
 * @return WPicker\Core
 */
function wpicker(): WPicker\Core {
	static $core = null;
	if ( null === $core ) {
		$core = new WPicker\Core();
		$core->boot();
	}
	return $core;
}
add_action( 'plugins_loaded', 'wpicker', 5 );
