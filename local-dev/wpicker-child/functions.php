<?php
/**
 * Dev stub child theme for WPicker development.
 *
 * This theme exists so that WPicker's pull/push/Vault features have a real
 * child-theme directory to operate against inside wp-env. It does nothing
 * except enqueue the parent stylesheet.
 *
 * @package WPicker\DevTheme
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enqueue parent theme styles.
 */
function wpicker_child_enqueue_styles() {
	wp_enqueue_style(
		'twentytwentyfour-style',
		get_template_directory_uri() . '/style.css',
		array(),
		wp_get_theme()->get( 'Version' )
	);
}
add_action( 'wp_enqueue_scripts', 'wpicker_child_enqueue_styles' );
