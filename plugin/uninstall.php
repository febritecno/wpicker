<?php
/**
 * Uninstall handler — removes ALL plugin-owned data.
 *
 * Called by WordPress only when the user deletes the plugin via wp-admin.
 * Removes: settings option, manifests option, devices metadata, and the
 * on-disk vault directory. Does NOT touch Application Passwords (those are
 * user-owned; revoke them manually from Users → Application Passwords).
 *
 * @package WPicker
 */

declare( strict_types = 1 );

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Options.
delete_option( 'wpicker_settings' );
delete_option( 'wpicker_vault_manifests' );
delete_option( 'wpicker_devices' );

// Transients (best-effort; PIN transients expire naturally).
global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_wpicker\_pin\_%'
	    OR option_name LIKE '\_transient\_timeout\_wpicker\_pin\_%'"
);

// On-disk vault directory.
$uploads = wp_upload_dir();
if ( ! empty( $uploads['basedir'] ) ) {
	$vault = trailingslashit( $uploads['basedir'] ) . 'wpicker-vault';
	if ( is_dir( $vault ) ) {
		wpicker_uninstall_rrmdir( $vault );
	}
}

/**
 * Recursively remove a directory during uninstall.
 *
 * @param string $dir Directory.
 * @return void
 */
function wpicker_uninstall_rrmdir( string $dir ): void {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( (array) scandir( $dir ) as $entry ) {
		if ( '.' === $entry || '..' === $entry ) {
			continue;
		}
		$path = $dir . '/' . $entry;
		if ( is_dir( $path ) ) {
			wpicker_uninstall_rrmdir( $path );
		} else {
			@unlink( $path );
		}
	}
	@rmdir( $dir );
}
