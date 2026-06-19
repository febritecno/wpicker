<?php
/**
 * Plugin auto-updater via GitHub Releases.
 *
 * Checks https://api.github.com/repos/febritecno/wpicker/releases/latest
 * and hooks into WordPress core to allow one-click updates if a newer version is found.
 *
 * @package WPicker\System
 */

declare( strict_types = 1 );

namespace WPicker\System;

/**
 * Class Updater
 */
final class Updater {

	/**
	 * GitHub Repository (user/repo).
	 *
	 * @var string
	 */
	private $repo = 'febritecno/wpicker';

	/**
	 * Plugin basename.
	 *
	 * @var string
	 */
	private $plugin_slug;

	/**
	 * Current version.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Transient cache key.
	 *
	 * @var string
	 */
	private $cache_key = 'wpicker_github_release_cache';

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->plugin_slug = WPICKER_BASENAME;
		$this->version     = WPICKER_VERSION;
	}

	/**
	 * Register update hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_action( 'upgrader_process_complete', array( $this, 'purge_cache' ), 10, 2 );
	}

	/**
	 * Hook into the plugin update check.
	 *
	 * @param object $transient Update transient object.
	 * @return object
	 */
	public function check_update( $transient ) {
		if ( empty( $transient->checked ) ) {
			return $transient;
		}

		$release = $this->get_github_release();
		if ( ! $release ) {
			return $transient;
		}

		$latest_version = ltrim( $release['tag_name'], 'v' );

		if ( version_compare( $this->version, $latest_version, '<' ) ) {
			$plugin              = new \stdClass();
			$plugin->id          = $this->plugin_slug;
			$plugin->slug        = 'wpicker';
			$plugin->plugin      = $this->plugin_slug;
			$plugin->new_version = $latest_version;
			$plugin->url         = $release['html_url'];

			$download_url = $release['zipball_url'];
			if ( ! empty( $release['assets'] ) ) {
				foreach ( $release['assets'] as $asset ) {
					if ( 'wpicker.zip' === $asset['name'] ) {
						$download_url = $asset['browser_download_url'];
						break;
					}
				}
			}

			$plugin->package      = $download_url;
			$plugin->icons        = array();
			$plugin->banners      = array();
			$plugin->tested       = '6.5';
			$plugin->requires_php = '7.4';

			$transient->response[ $this->plugin_slug ] = $plugin;
		}

		return $transient;
	}

	/**
	 * Hook into the plugin details popup (View version x.y.z details).
	 *
	 * @param false|object|array $res    The result object or array.
	 * @param string             $action The API action being performed.
	 * @param object             $args   Plugin API arguments.
	 * @return false|object|array
	 */
	public function plugin_info( $res, string $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $res;
		}

		if ( isset( $args->slug ) && 'wpicker' !== $args->slug ) {
			return $res;
		}

		$release = $this->get_github_release();
		if ( ! $release ) {
			return $res;
		}

		$latest_version = ltrim( $release['tag_name'], 'v' );

		$download_url = $release['zipball_url'];
		if ( ! empty( $release['assets'] ) ) {
			foreach ( $release['assets'] as $asset ) {
				if ( 'wpicker.zip' === $asset['name'] ) {
					$download_url = $asset['browser_download_url'];
					break;
				}
			}
		}

		$res               = new \stdClass();
		$res->name         = 'WPicker';
		$res->slug         = 'wpicker';
		$res->version      = $latest_version;
		$res->author       = 'WPicker';
		$res->homepage     = 'https://github.com/' . $this->repo;
		$res->requires     = '6.2';
		$res->tested       = '6.5';
		$res->requires_php = '7.4';
		$res->download_link = $download_url;
		$res->trunk        = $download_url;
		$res->last_updated = $release['published_at'];
		$res->sections     = array(
			'description' => 'WPicker is an AI-friendly CLI bridge for WordPress child-theme development.',
			'changelog'   => nl2br( esc_html( $release['body'] ?? '' ) ),
		);

		return $res;
	}

	/**
	 * Purge the release cache when the plugin is successfully updated.
	 *
	 * @param \WP_Upgrader $upgrader WP_Upgrader instance.
	 * @param array        $options  Array of bulk item update data.
	 * @return void
	 */
	public function purge_cache( $upgrader, array $options ): void {
		if ( isset( $options['action'], $options['type'] ) && 'update' === $options['action'] && 'plugin' === $options['type'] ) {
			delete_transient( $this->cache_key );
		}
	}

	/**
	 * Fetch the latest GitHub release. Results are cached for 12 hours.
	 *
	 * @return array|null
	 */
	private function get_github_release(): ?array {
		$cache = get_transient( $this->cache_key );
		if ( false !== $cache ) {
			return $cache;
		}

		$url      = 'https://api.github.com/repos/' . $this->repo . '/releases/latest';
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept' => 'application/vnd.github.v3+json',
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			set_transient( $this->cache_key, null, 12 * HOUR_IN_SECONDS );
			return null;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( empty( $data['tag_name'] ) ) {
			set_transient( $this->cache_key, null, 12 * HOUR_IN_SECONDS );
			return null;
		}

		set_transient( $this->cache_key, $data, 12 * HOUR_IN_SECONDS );
		return $data;
	}
}
