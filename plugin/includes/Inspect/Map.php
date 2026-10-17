<?php
/**
 * Map — what is installed on the site, in one compact answer.
 *
 * The first thing an agent needs when it meets an unknown site: which
 * plugins and themes exist, which are active, which come from wordpress.org
 * and which are the site's own (where its bugs live), and which well-known
 * systems are present (WooCommerce and its payment gateways, email plugins,
 * page builders, caching drop-ins).
 *
 * Read-only. Origin comes from the update data WordPress already holds; no
 * request is made to wordpress.org.
 *
 * @package WPicker\Inspect
 */

declare( strict_types = 1 );

namespace WPicker\Inspect;

defined( 'ABSPATH' ) || exit;

/**
 * Class Map
 */
final class Map {

	/**
	 * Plugins that deliver or log email, by slug (directory name).
	 */
	private const EMAIL = array(
		'wp-mail-smtp'               => 'WP Mail SMTP',
		'wp-mail-smtp-pro'           => 'WP Mail SMTP Pro',
		'fluent-smtp'                => 'FluentSMTP',
		'post-smtp'                  => 'Post SMTP',
		'easy-wp-smtp'               => 'Easy WP SMTP',
		'mailgun'                    => 'Mailgun',
		'sendgrid-email-delivery-simplified' => 'SendGrid',
		'mailin'                     => 'Brevo',
		'woocommerce-sendinblue-newsletter-subscription' => 'Brevo',
		'mailpoet'                   => 'MailPoet',
		'wp-ses'                     => 'WP SES',
		'wp-mail-logging'            => 'WP Mail Logging',
		'mailchimp-for-wp'           => 'Mailchimp for WP',
	);

	/**
	 * Drop-ins WordPress loads from wp-content and what they mean.
	 */
	private const DROPINS = array(
		'advanced-cache.php' => 'page cache',
		'object-cache.php'   => 'object cache',
		'db.php'             => 'custom database layer',
		'maintenance.php'    => 'custom maintenance page',
		'sunrise.php'        => 'multisite domain mapping',
	);

	/**
	 * Where a plugin or theme comes from, judged from its update-data id/url.
	 * Pure.
	 *
	 * @param string $id Update-data `id` or `url`.
	 * @return string wordpress.org | external
	 */
	public static function origin_from_id( string $id ): string {
		return 1 === preg_match( '#^(w\.org/|https?://wordpress\.org/)#i', $id ) ? 'wordpress.org' : 'external';
	}

	/**
	 * Is this plugin directory a wordpress.org plugin?
	 *
	 * @param string $slug Plugin directory name.
	 * @return bool
	 */
	public static function from_wordpress_org( string $slug ): bool {
		return 'wordpress.org' === ( self::plugin_origins()[ $slug ] ?? '' );
	}

	/**
	 * Is this theme a wordpress.org theme?
	 *
	 * @param string $slug Theme directory name.
	 * @return bool
	 */
	public static function theme_from_wordpress_org( string $slug ): bool {
		return 'wordpress.org' === ( self::theme_origins()[ $slug ] ?? '' );
	}

	/**
	 * @return array<string,string> Plugin directory => origin.
	 */
	private static function plugin_origins(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$cache = array();
		$data  = get_site_transient( 'update_plugins' );
		if ( is_object( $data ) ) {
			foreach ( array_merge( (array) ( $data->response ?? array() ), (array) ( $data->no_update ?? array() ) ) as $file => $info ) {
				$info = (object) $info;
				$id   = (string) ( $info->id ?? ( $info->url ?? '' ) );
				$slug = false !== strpos( (string) $file, '/' ) ? strtok( (string) $file, '/' ) : '';
				if ( '' !== $slug && '' !== $id ) {
					$cache[ $slug ] = self::origin_from_id( $id );
				}
			}
		}
		return $cache;
	}

	/**
	 * @return array<string,string> Theme directory => origin.
	 */
	private static function theme_origins(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$cache = array();
		$data  = get_site_transient( 'update_themes' );
		if ( is_object( $data ) ) {
			foreach ( array_merge( (array) ( $data->response ?? array() ), (array) ( $data->no_update ?? array() ) ) as $slug => $info ) {
				$info = (array) $info;
				$url  = (string) ( $info['url'] ?? '' );
				if ( '' !== $url ) {
					$cache[ (string) $slug ] = self::origin_from_id( $url );
				}
			}
		}
		return $cache;
	}

	/**
	 * The map.
	 *
	 * @return array<string,mixed>
	 */
	public static function site(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$active  = (array) get_option( 'active_plugins', array() );
		$network = is_multisite() ? array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) : array();
		$updates = get_site_transient( 'update_plugins' );
		$pending = is_object( $updates ) ? array_keys( (array) ( $updates->response ?? array() ) ) : array();
		$origins = self::plugin_origins();

		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			$slug      = false !== strpos( $file, '/' ) ? (string) strtok( $file, '/' ) : basename( $file, '.php' );
			$is_active = in_array( $file, $active, true ) || in_array( $file, $network, true );
			$plugins[] = array(
				'slug'    => $slug,
				'name'    => (string) $data['Name'],
				'version' => (string) $data['Version'],
				'active'  => $is_active,
				'origin'  => $origins[ $slug ] ?? 'custom-or-unknown',
				'update'  => in_array( $file, $pending, true ),
				'file'    => 'plugins/' . $file,
				'linked'  => is_link( WP_PLUGIN_DIR . '/' . $slug ),
			);
		}

		$mu = array();
		foreach ( get_mu_plugins() as $file => $data ) {
			$mu[] = array(
				'name'    => (string) $data['Name'],
				'version' => (string) $data['Version'],
				'file'    => 'mu-plugins/' . $file,
			);
		}

		$dropins = array();
		foreach ( array_keys( get_dropins() ) as $file ) {
			$dropins[] = array(
				'file' => $file,
				'is'   => self::DROPINS[ $file ] ?? 'drop-in',
			);
		}

		$current = wp_get_theme();
		$themes  = array();
		$t_orig  = self::theme_origins();
		foreach ( wp_get_themes() as $slug => $theme ) {
			$themes[] = array(
				'slug'    => (string) $slug,
				'name'    => (string) $theme->get( 'Name' ),
				'version' => (string) $theme->get( 'Version' ),
				'active'  => $theme->get_stylesheet() === $current->get_stylesheet(),
				'parent'  => $theme->parent() ? $theme->get_template() : '',
				'origin'  => $t_orig[ (string) $slug ] ?? 'custom-or-unknown',
				'file'    => 'themes/' . $slug,
			);
		}

		return array(
			'wordpress'    => array(
				'version'   => get_bloginfo( 'version' ),
				'php'       => PHP_VERSION,
				'multisite' => is_multisite(),
				'debug'     => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'wp_cron'   => ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ),
			),
			'plugins'      => $plugins,
			'mu_plugins'   => $mu,
			'dropins'      => $dropins,
			'themes'       => $themes,
			'integrations' => self::integrations( $plugins ),
			'note'         => 'origin "custom-or-unknown" = not matched to wordpress.org by the update data WordPress holds (it may simply not have checked yet).',
		);
	}

	/**
	 * Well-known systems present on the site.
	 *
	 * @param array<int,array<string,mixed>> $plugins Plugin rows from site().
	 * @return array<string,mixed>
	 */
	private static function integrations( array $plugins ): array {
		$out = array();

		$email = array();
		foreach ( $plugins as $p ) {
			if ( $p['active'] && isset( self::EMAIL[ $p['slug'] ] ) ) {
				$email[] = self::EMAIL[ $p['slug'] ];
			}
		}
		$out['email'] = $email;

		$out['builders'] = ( new \WPicker\Builder\Detector() )->get_active_builders();

		if ( class_exists( 'WooCommerce' ) || defined( 'WC_VERSION' ) ) {
			$woo = array(
				'version'  => defined( 'WC_VERSION' ) ? WC_VERSION : '',
				'gateways' => array(),
				'hpos'     => 'yes' === get_option( 'woocommerce_custom_orders_table_enabled' ),
				'log_dir'  => defined( 'WC_LOG_DIR' ),
			);
			if ( function_exists( 'WC' ) && is_object( WC() ) && is_object( WC()->payment_gateways() ) ) {
				foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
					if ( 'yes' === ( $gateway->enabled ?? '' ) ) {
						$woo['gateways'][] = (string) $id;
					}
				}
			}
			$out['woocommerce'] = $woo;
		}
		return $out;
	}
}
