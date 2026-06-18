<?php
/**
 * Context Provider — assembles AI-friendly site metadata.
 *
 * The Global Context API: returns a stable JSON describing WordPress, PHP,
 * active plugins, the active theme (and parent), and selected theme_mods.
 * This is what `wpicker context` consumes to ground an AI agent and reduce
 * hallucination. Read-only — never writes.
 *
 * @package WPicker\Context
 */

declare( strict_types = 1 );

namespace WPicker\Context;

defined( 'ABSPATH' ) || exit;

/**
 * Class Provider
 */
final class Provider {

	/**
	 * Keys of theme_mods we expose. Extended carefully to avoid leaking secrets.
	 *
	 * NOTE: `theme_mods` hold only theme configuration (header text, colors,
	 * nav menu locations, custom logo id, etc.) — not credentials. We still
	 * filter to a known-safe allowlist to keep the payload small and predictable.
	 */
	private const MODS_ALLOWLIST = array(
		'custom_logo',
		'site_icon',
		'header_textcolor',
		'background_color',
		'background_image',
		'nav_menu_locations',
		'page_for_posts',
		'page_on_front',
		'show_on_front',
	);

	/**
	 * Build the context payload.
	 *
	 * @return array<string,mixed>
	 */
	public function build(): array {
		global $wp_version;

		// Top-level always-known fields.
		$payload = array(
			'wpicker'    => array( 'version' => WPICKER_VERSION ),
			'site'       => $this->site_info(),
			'environment' => array(
				'wp_version'   => $wp_version,
				'php_version'  => PHP_VERSION,
				'is_multisite' => is_multisite(),
				'language'     => (string) get_bloginfo( 'language' ),
				'debug'        => array(
					'WP_DEBUG'          => (bool) ( defined( 'WP_DEBUG' ) && WP_DEBUG ),
					'script_debug'      => (bool) ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ),
				),
			),
			'rest_root'  => esc_url_raw( rest_url( 'wpicker/v1' ) ),
			'generated_at_gmt' => gmdate( 'c' ),
		);

		$payload['theme']    = $this->theme_info();
		$payload['plugins']  = $this->plugins_info();
		$payload['theme_mods'] = $this->theme_mods();
		
		$detector = new \WPicker\Builder\Detector();
		$payload['active_builders'] = $detector->get_active_builders();

		return $payload;
	}

	/**
	 * Site-level metadata.
	 *
	 * @return array<string,mixed>
	 */
	private function site_info(): array {
		return array(
			'name'            => (string) get_bloginfo( 'name' ),
			'description'     => (string) get_bloginfo( 'description' ),
			'url'             => esc_url_raw( home_url() ),
			'admin_url'       => esc_url_raw( admin_url() ),
			'permalink_structure' => (string) get_option( 'permalink_structure' ),
			'timezone'        => (string) wp_timezone_string(),
			'active_theme_stylesheet' => (string) get_stylesheet(),
		);
	}

	/**
	 * Active theme + parent chain.
	 *
	 * @return array<string,mixed>
	 */
	private function theme_info(): array {
		$theme   = wp_get_theme();
		$parent  = $theme->parent();

		$theme_info = array(
			'name'           => $theme->get( 'Name' ),
			'stylesheet'     => $theme->get_stylesheet(),
			'version'        => $theme->get( 'Version' ),
			'template'       => $theme->get( 'Template' ) ?: null,
			'text_domain'    => $theme->get( 'TextDomain' ),
			'is_child_theme' => (bool) $parent,
			'directory'      => array(
				'stylesheet' => get_stylesheet_directory(),
				'template'   => get_template_directory(),
			),
			'uris' => array(
				'stylesheet' => get_stylesheet_directory_uri(),
				'template'   => get_template_directory_uri(),
			),
		);

		if ( $parent ) {
			$theme_info['parent'] = array(
				'name'       => $parent->get( 'Name' ),
				'stylesheet' => $parent->get_stylesheet(),
				'version'    => $parent->get( 'Version' ),
			);
		}

		return $theme_info;
	}

	/**
	 * Active plugins (name, version, plugin path, network_active).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function plugins_info(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins = get_plugins();
		$active      = (array) get_option( 'active_plugins', array() );

		$out = array();
		foreach ( $active as $plugin_path ) {
			if ( ! isset( $all_plugins[ $plugin_path ] ) ) {
				continue;
			}
			$meta     = $all_plugins[ $plugin_path ];
			$out[]    = array(
				'name'         => $meta['Name'] ?? '',
				'plugin_uri'   => $meta['PluginURI'] ?? '',
				'version'      => $meta['Version'] ?? '',
				'text_domain'  => $meta['TextDomain'] ?? '',
				'requires_php' => $meta['RequiresPHP'] ?? '',
				'path'         => $plugin_path,
				'is_wpicker'   => 'wpicker/wpicker.php' === $plugin_path,
			);
		}

		return $out;
	}

	/**
	 * Filtered theme_mods snapshot (allowlisted keys only).
	 *
	 * @return array<string,mixed>
	 */
	private function theme_mods(): array {
		$mods = get_theme_mods();
		if ( ! is_array( $mods ) ) {
			return array();
		}

		$out = array();
		foreach ( self::MODS_ALLOWLIST as $key ) {
			if ( array_key_exists( $key, $mods ) ) {
				$out[ $key ] = $mods[ $key ];
			}
		}
		return $out;
	}
}
