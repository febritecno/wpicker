<?php
/**
 * PHPUnit bootstrap for WPicker plugin tests.
 *
 * Loads a minimal WordPress test framework stub so that plugin classes
 * referencing WP functions/constants can be tested in isolation without a
 * full WordPress installation.
 *
 * For integration tests that need the DB (Vault, REST), run via wp-env:
 *   make test-php
 */

declare( strict_types = 1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}

// Composer autoloader.
require_once __DIR__ . '/../../vendor/autoload.php';

class WP_Theme_Mock {
	private $stylesheet;
	private $template;
	private $is_parent;

	public function __construct( $stylesheet = 'wpicker-test-child', $template = 'twentytwentyfour', $is_parent = false ) {
		$this->stylesheet = $stylesheet;
		$this->template   = $template;
		$this->is_parent  = $is_parent;
	}

	public function get_template() {
		return $this->template;
	}

	public function get_stylesheet() {
		return $this->stylesheet;
	}

	public function parent() {
		if ( $this->is_parent ) {
			return null;
		}
		return new self( 'twentytwentyfour', 'twentytwentyfour', true );
	}

	public function get( $header ) {
		switch ( $header ) {
			case 'Name':
				return $this->is_parent ? 'Twenty Twenty-Four' : 'WPicker Test Child';
			case 'Version':
				return '1.0.0';
			case 'Template':
				return $this->is_parent ? '' : $this->template;
			case 'TextDomain':
				return $this->is_parent ? 'twentytwentyfour' : 'wpicker-test-child';
		}
		return '';
	}
}

// Minimal WP function stubs for unit tests that don't need a real WP instance.
if ( ! function_exists( 'get_stylesheet_directory' ) ) {
	function get_stylesheet_directory(): string {
		$temp = sys_get_temp_dir();
		$real = realpath( $temp );
		return ( $real ?: $temp ) . '/wpicker-test-child';
	}
}
if ( ! function_exists( 'get_stylesheet' ) ) {
	function get_stylesheet(): string {
		return 'wpicker-test-child';
	}
}
if ( ! function_exists( 'get_template' ) ) {
	function get_template(): string {
		return 'twentytwentyfour';
	}
}
if ( ! function_exists( 'wp_get_theme' ) ) {
	function wp_get_theme( $stylesheet = null ) {
		return new WP_Theme_Mock();
	}
}
if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( string $url ): string {
		return $url;
	}
}
if ( ! function_exists( 'wp_parse_args' ) ) {
	function wp_parse_args( $args, $defaults = '' ) {
		if ( is_object( $args ) ) {
			$args = (array) $args;
		}
		if ( is_array( $args ) && is_array( $defaults ) ) {
			return array_merge( $defaults, $args );
		}
		return $defaults;
	}
}

// Global states for options and transients mock storage.
$GLOBALS['wp_options'] = array();
$GLOBALS['wp_transients'] = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default = false ) {
		return $GLOBALS['wp_options'][ $option ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $option, $value, $autoload = null ): bool {
		$GLOBALS['wp_options'][ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( string $option, $value = '', $deprecated = '', $autoload = 'yes' ): bool {
		if ( isset( $GLOBALS['wp_options'][ $option ] ) ) {
			return false;
		}
		$GLOBALS['wp_options'][ $option ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( string $option ): bool {
		unset( $GLOBALS['wp_options'][ $option ] );
		return true;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( string $transient, $value, int $expiration = 0 ): bool {
		$GLOBALS['wp_transients'][ $transient ] = array(
			'value' => $value,
			'expires' => $expiration > 0 ? time() + $expiration : 0,
		);
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( string $transient ) {
		if ( ! isset( $GLOBALS['wp_transients'][ $transient ] ) ) {
			return false;
		}
		$data = $GLOBALS['wp_transients'][ $transient ];
		if ( $data['expires'] > 0 && time() > $data['expires'] ) {
			unset( $GLOBALS['wp_transients'][ $transient ] );
			return false;
		}
		return $data['value'];
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( string $transient ): bool {
		unset( $GLOBALS['wp_transients'][ $transient ] );
		return true;
	}
}
if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $target ): bool {
		if ( is_dir( $target ) ) {
			return true;
		}
		return mkdir( $target, 0755, true );
	}
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir(): array {
		$dir = sys_get_temp_dir() . '/wpicker-test-uploads';
		return array(
			'path'    => $dir,
			'url'     => 'http://example.com/wp-content/uploads',
			'subdir'  => '',
			'basedir' => $dir,
			'baseurl' => 'http://example.com/wp-content/uploads',
			'error'   => false,
		);
	}
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( string $value ): string {
		return rtrim( $value, '/\\' ) . '/';
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id(): int {
		return 1;
	}
}
if ( ! function_exists( 'is_multisite' ) ) {
	function is_multisite(): bool {
		return false;
	}
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( string $show = '', string $filter = 'raw' ): string {
		switch ( $show ) {
			case 'name':
				return 'Test Site';
			case 'description':
				return 'Just another WordPress site';
			case 'language':
				return 'en-US';
		}
		return '';
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url( string $path = '', ?string $scheme = null ): string {
		return 'http://example.com' . ( '' !== $path ? '/' . ltrim( $path, '/' ) : '' );
	}
}
if ( ! function_exists( 'admin_url' ) ) {
	function admin_url( string $path = '', string $scheme = 'admin' ): string {
		return 'http://example.com/wp-admin' . ( '' !== $path ? '/' . ltrim( $path, '/' ) : '' );
	}
}
if ( ! function_exists( 'wp_timezone_string' ) ) {
	function wp_timezone_string(): string {
		return 'UTC';
	}
}
if ( ! function_exists( 'get_stylesheet_directory_uri' ) ) {
	function get_stylesheet_directory_uri(): string {
		return 'http://example.com/wp-content/themes/wpicker-test-child';
	}
}
if ( ! function_exists( 'get_template_directory' ) ) {
	function get_template_directory(): string {
		return sys_get_temp_dir() . '/twentytwentyfour';
	}
}
if ( ! function_exists( 'get_template_directory_uri' ) ) {
	function get_template_directory_uri(): string {
		return 'http://example.com/wp-content/themes/twentytwentyfour';
	}
}
if ( ! function_exists( 'get_plugins' ) ) {
	function get_plugins(): array {
		return array(
			'wpicker/wpicker.php' => array(
				'Name'    => 'WPicker',
				'Version' => '1.1.0',
			),
		);
	}
}
if ( ! function_exists( 'get_theme_mods' ) ) {
	function get_theme_mods() {
		return array(
			'background_color' => 'ffffff',
		);
	}
}
if ( ! function_exists( 'rest_url' ) ) {
	function rest_url( string $path = '', string $scheme = 'json' ): string {
		return 'http://example.com/wp-json/' . ltrim( $path, '/' );
	}
}

// Ensure the child theme test directory exists for PathGuard tests.
$test_theme = get_stylesheet_directory();
if ( ! is_dir( $test_theme ) ) {
	mkdir( $test_theme, 0755, true );
}

