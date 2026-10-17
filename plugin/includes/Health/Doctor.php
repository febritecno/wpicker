<?php
/**
 * Doctor — a checklist of what could stop WPicker working on this site.
 *
 * Split in two on purpose: facts() asks WordPress and the server what is the
 * case; evaluate() turns those facts into checks and is a pure function, so
 * the rules can be tested without WordPress.
 *
 * Read-only. Nothing returned is a secret: statuses, sizes, counts, and the
 * names of paired devices.
 *
 * @package WPicker\Health
 */

declare( strict_types = 1 );

namespace WPicker\Health;

defined( 'ABSPATH' ) || exit;

/**
 * Class Doctor
 */
final class Doctor {

	public const OK   = 'ok';
	public const WARN = 'warn';
	public const FAIL = 'fail';
	public const INFO = 'info';
	public const SKIP = 'skip';

	/**
	 * Gather the facts. $probe is the loopback result, or null if not run.
	 *
	 * @param array{ok:bool|null,status:int,url:string}|null $probe Result of Probe::check().
	 * @param int|null                                       $web   Result of vault_web_status().
	 * @return array<string,mixed>
	 */
	public static function facts( ?array $probe, ?int $web = null ): array {
		$settings = (array) get_option( 'wpicker_settings', array() );
		$uploads  = wp_upload_dir( null, false );
		$basedir  = (string) ( $uploads['basedir'] ?? '' );
		$vault    = $basedir . '/wpicker-vault';
		$free     = '' !== $basedir && is_dir( $basedir ) ? @disk_free_space( $basedir ) : false;

		$devices = array();
		foreach ( (array) get_option( 'wpicker_devices', array() ) as $device ) {
			$seen      = isset( $device['last_seen_gmt'] ) ? strtotime( (string) $device['last_seen_gmt'] ) : false;
			$devices[] = array(
				'name'      => (string) ( $device['name'] ?? '' ),
				'days_idle' => false === $seen || null === $seen ? null : (int) floor( ( time() - $seen ) / 86400 ),
			);
		}

		$theme = wp_get_theme();
		$dir   = get_stylesheet_directory();

		return array(
			'plugin_version'  => defined( 'WPICKER_VERSION' ) ? (string) WPICKER_VERSION : '',
			'php'             => PHP_VERSION,
			'wp'              => get_bloginfo( 'version' ),
			'https'           => 0 === strpos( (string) home_url(), 'https://' ),
			'env'             => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
			'app_passwords'   => function_exists( 'wp_is_application_passwords_available' ) ? (bool) wp_is_application_passwords_available() : false,
			'server'          => isset( $_SERVER['SERVER_SOFTWARE'] ) ? (string) $_SERVER['SERVER_SOFTWARE'] : '', // phpcs:ignore WordPress.Security
			'vault_exists'    => is_dir( $vault ),
			'vault_writable'  => is_dir( $vault ) ? is_writable( $vault ) : ( '' !== $basedir && is_writable( $basedir ) ),
			'vault_htaccess'  => is_file( $vault . '/.htaccess' ),
			'disk_free'       => false === $free ? null : (int) $free,
			'snapshots'       => count( (array) get_option( 'wpicker_vault_manifests', array() ) ),
			'vault_keep'      => (int) ( $settings['vault_keep'] ?? 30 ),
			'memory'          => function_exists( 'wp_convert_hr_to_bytes' ) ? (int) wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) ) : 0,
			'max_exec'        => (int) ini_get( 'max_execution_time' ),
			'child_theme'     => $theme->get_stylesheet() !== $theme->get_template(),
			'theme_name'      => $theme->get_stylesheet(),
			'theme_writable'  => is_dir( $dir ) && is_writable( $dir ),
			'lint'            => (int) ( $settings['lint_enabled'] ?? 1 ) === 1,
			'health_check'    => (int) ( $settings['health_check'] ?? 1 ) === 1,
			'allow_inspect'   => (int) ( $settings['allow_inspect'] ?? 0 ) === 1,
			'allow_backup'    => (int) ( $settings['allow_backup'] ?? 1 ) === 1,
			'debug_log'       => (int) ( $settings['debug_log'] ?? 0 ) === 1,
			'push_mode'       => (string) ( $settings['push_mode'] ?? 'direct' ),
			'devices'         => $devices,
			'probe'           => $probe,
			'proxied'         => \WPicker\Auth\PairRateLimit::behind_proxy(),
			'vault_web'       => $web,
			'file_mods_off'   => defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS,
		);
	}

	/**
	 * Is the storage folder served by the web server? Asks the site for one of
	 * the empty index.html files WPicker puts in its sub-folders.
	 *
	 * Returns the HTTP status when the answer is conclusive (200 with an EMPTY
	 * body = served; any other status = closed), or null when it cannot be
	 * told: no such file yet, loopback blocked, or a 200 that carries a page
	 * (a soft 404), which proves nothing.
	 *
	 * @return int|null
	 */
	public static function vault_web_status(): ?int {
		$uploads = wp_upload_dir( null, false );
		$dir     = (string) ( $uploads['basedir'] ?? '' ) . '/wpicker-vault';
		$files   = '' !== $dir ? (array) glob( $dir . '/*/index.html' ) : array();
		if ( array() === $files ) {
			return null;
		}
		$url      = trailingslashit( (string) $uploads['baseurl'] ) . 'wpicker-vault/' . basename( dirname( (string) $files[0] ) ) . '/index.html';
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 5,
				'redirection' => 0,
				'sslverify'   => false,
				'cookies'     => array(),
			)
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 === $status && '' !== trim( (string) wp_remote_retrieve_body( $response ) ) ) {
			return null;
		}
		return $status;
	}

	/**
	 * Turn facts into a checklist. Pure.
	 *
	 * @param array<string,mixed> $f Facts from facts().
	 * @return array<int,array{id:string,group:string,status:string,title:string,detail:string,fix:string}>
	 */
	public static function evaluate( array $f ): array {
		$out = array();
		$add = static function ( string $id, string $group, string $status, string $title, string $detail = '', string $fix = '' ) use ( &$out ): void {
			$out[] = array(
				'id'     => $id,
				'group'  => $group,
				'status' => $status,
				'title'  => $title,
				'detail' => $detail,
				'fix'    => $fix,
			);
		};

		$local = in_array( (string) ( $f['env'] ?? '' ), array( 'local', 'development' ), true );

		// --- Site.
		$php = (string) ( $f['php'] ?? '' );
		if ( version_compare( $php, '7.4', '<' ) ) {
			$add( 'php', 'Site', self::FAIL, "PHP $php is too old", 'WPicker needs PHP 7.4 or newer.', 'Ask your host to switch the site to PHP 7.4+ (8.x is better).' );
		} else {
			$add( 'php', 'Site', self::OK, "PHP $php · WordPress " . (string) ( $f['wp'] ?? '' ) );
		}

		if ( ! empty( $f['https'] ) ) {
			$add( 'https', 'Site', self::OK, 'Site is served over HTTPS' );
		} elseif ( $local ) {
			$add( 'https', 'Site', self::INFO, 'Site is on plain HTTP (local environment, fine)' );
		} else {
			$add( 'https', 'Site', self::WARN, 'Site is not on HTTPS', 'Pairing sends an admin password, and WordPress disables Application Passwords on plain-HTTP sites.', 'Serve the site over HTTPS.' );
		}

		if ( ! empty( $f['app_passwords'] ) ) {
			$add( 'app_passwords', 'Site', self::OK, 'Application Passwords are available (the CLI needs them)' );
		} else {
			$add( 'app_passwords', 'Site', self::FAIL, 'Application Passwords are unavailable', 'The CLI cannot pair or authenticate without them. Usual causes: plain HTTP, a security plugin, or the wp_is_application_passwords_available filter.', 'Use HTTPS and allow Application Passwords in your security plugin.' );
		}

		// --- Storage.
		if ( empty( $f['vault_exists'] ) && empty( $f['vault_writable'] ) ) {
			$add( 'vault', 'Storage', self::FAIL, 'Cannot create the WPicker storage folder', 'wp-content/uploads/wpicker-vault holds snapshots and backups, and cannot be created.', 'Make wp-content/uploads writable by the web server.' );
		} elseif ( empty( $f['vault_writable'] ) ) {
			$add( 'vault', 'Storage', self::FAIL, 'WPicker storage folder is not writable', 'Pushes cannot take a snapshot, so they are refused.', 'Make wp-content/uploads/wpicker-vault writable by the web server.' );
		} else {
			$add( 'vault', 'Storage', self::OK, 'Storage folder exists and is writable' );
		}

		$server = strtolower( (string) ( $f['server'] ?? '' ) );
		$web    = $f['vault_web'] ?? null;
		if ( 200 === $web ) {
			$add( 'vault_web', 'Storage', self::WARN, 'Storage folder is served by the web server (HTTP 200)', 'A file inside wp-content/uploads/wpicker-vault was downloadable. Snapshots and backups contain your site\'s code and data, and are then protected only by hard-to-guess names.', 'Deny /wp-content/uploads/wpicker-vault/ in the server configuration.' );
		} elseif ( is_int( $web ) ) {
			$add( 'vault_web', 'Storage', self::OK, "Storage folder is closed to the web (HTTP $web)" );
		} elseif ( ! empty( $f['vault_htaccess'] ) && ( false !== strpos( $server, 'apache' ) || false !== strpos( $server, 'litespeed' ) ) ) {
			$add( 'vault_web', 'Storage', self::OK, 'Storage folder is closed to the web (.htaccess)' );
		} elseif ( ! empty( $f['vault_exists'] ) ) {
			$add( 'vault_web', 'Storage', self::WARN, 'Could not confirm the storage folder is closed to the web', 'This server does not read .htaccess (' . ( '' === $server ? 'unknown server' : $server ) . ') and the folder could not be tested from here. Snapshots and backups contain your site\'s code and data.', 'Check that /wp-content/uploads/wpicker-vault/ is denied in the server configuration.' );
		}

		$free = $f['disk_free'] ?? null;
		if ( is_int( $free ) ) {
			$mb   = (int) floor( $free / 1048576 );
			$nice = $mb >= 10240 ? (int) floor( $mb / 1024 ) . ' GB' : $mb . ' MB';
			if ( $free < 50 * 1048576 ) {
				$add( 'disk', 'Storage', self::FAIL, "Only $nice of disk space left", 'Snapshots and backups will fail.', 'Free up disk space.' );
			} elseif ( $free < 200 * 1048576 ) {
				$add( 'disk', 'Storage', self::WARN, "Low disk space: $nice left", 'Full-site backups need room for a copy of the site.', 'Free up disk space or lower the number of kept backups.' );
			} else {
				$add( 'disk', 'Storage', self::OK, "$nice of disk space free" );
			}
		}
		$add( 'snapshots', 'Storage', self::INFO, (int) ( $f['snapshots'] ?? 0 ) . ' snapshots kept (limit ' . (int) ( $f['vault_keep'] ?? 30 ) . ')' );

		// --- Pushing code.
		$theme = (string) ( $f['theme_name'] ?? '' );
		if ( empty( $f['child_theme'] ) ) {
			$add( 'child_theme', 'Pushing code', self::WARN, "The active theme ($theme) is not a child theme", 'WPicker only ever writes inside a child theme, so pull and push do not work. Reading and diagnosing still do.', 'Activate a child theme, or create one.' );
		} elseif ( empty( $f['theme_writable'] ) ) {
			$add( 'theme_writable', 'Pushing code', self::FAIL, "The child theme folder ($theme) is not writable", 'A push cannot apply files.', 'Make the theme folder writable by the web server.' );
		} else {
			$add( 'child_theme', 'Pushing code', self::OK, "Child theme $theme is active and writable" );
		}

		if ( empty( $f['lint'] ) ) {
			$add( 'lint', 'Pushing code', self::WARN, 'PHP syntax check before applying is OFF', 'A broken file can take the site down.', 'Turn it on in WP-Admin → WPicker → Settings.' );
		} else {
			$add( 'lint', 'Pushing code', self::OK, 'PHP syntax check runs before every push' );
		}

		$probe = $f['probe'] ?? null;
		if ( empty( $f['health_check'] ) ) {
			$add( 'loopback', 'Pushing code', self::INFO, 'Post-push health check is switched off', 'A push that breaks the site will not be rolled back automatically.' );
		} elseif ( ! is_array( $probe ) ) {
			$add( 'loopback', 'Pushing code', self::SKIP, 'Loopback test not run' );
		} elseif ( true === ( $probe['ok'] ?? null ) ) {
			$add( 'loopback', 'Pushing code', self::OK, 'Post-push health check works (the site can reach itself)' );
		} elseif ( false === ( $probe['ok'] ?? null ) ) {
			$add( 'loopback', 'Pushing code', self::FAIL, 'The site is returning an error right now (HTTP ' . (int) ( $probe['status'] ?? 0 ) . ')', 'Fix that first: a push would be judged against a site that is already broken.', 'Run `wpicker logs --since 15m --level error`.' );
		} else {
			$add( 'loopback', 'Pushing code', self::WARN, 'The site cannot request itself (loopback blocked)', 'The post-push health check cannot see a broken push, so it will not roll back automatically. Pushes still work.', 'Allow loopback requests on the host, or keep an eye on `wpicker logs` after each push.' );
		}

		if ( 'review' === ( $f['push_mode'] ?? 'direct' ) ) {
			$add( 'push_mode', 'Pushing code', self::INFO, 'Push mode is "review": pushes wait for approval in WP-Admin' );
		}

		// --- Backups.
		if ( empty( $f['allow_backup'] ) ) {
			$add( 'backup', 'Backups', self::INFO, 'Backups from the CLI are switched off' );
		} else {
			$mem = (int) ( $f['memory'] ?? 0 );
			if ( $mem > 0 && $mem < 128 * 1048576 ) {
				$add( 'memory', 'Backups', self::WARN, 'PHP memory limit is ' . (int) ( $mem / 1048576 ) . ' MB', 'Backups and restores of larger sites may run out of memory.', 'Raise memory_limit to 256M or more.' );
			} else {
				$add( 'memory', 'Backups', self::OK, 'PHP memory limit is enough for backups' );
			}
			$exec = (int) ( $f['max_exec'] ?? 0 );
			if ( $exec > 0 && $exec < 60 ) {
				$add( 'max_exec', 'Backups', self::WARN, "PHP time limit is $exec seconds", 'Backup steps are cut short on larger sites.', 'Raise max_execution_time to 120 or more.' );
			}
		}

		// --- Access.
		if ( ! empty( $f['allow_inspect'] ) ) {
			$add( 'inspect', 'Access', self::INFO, 'Diagnostic access is ON (logs, code and database can be read by paired devices)' );
		} else {
			$add( 'inspect', 'Access', self::INFO, 'Diagnostic access is OFF: `wpicker logs`, `find`, `files` and `db` are unavailable', '', 'Turn it on in WP-Admin → WPicker → Settings when you need it.' );
		}
		if ( ! empty( $f['debug_log'] ) ) {
			$add( 'debug_log', 'Access', self::WARN, 'WordPress debug logging is left ON', 'It logs every notice and deprecation, grows a file, and slows the site slightly.', 'Run `wpicker debug off` when you are done.' );
		}

		if ( ! empty( $f['proxied'] ) ) {
			$add( 'proxy', 'Access', self::WARN, 'The site is behind a proxy or CDN that WPicker cannot see past', 'Every visitor looks like the same address. Pairing limits then apply to everyone at once, and the check "this request came from another network than your browser" cannot work, so approving a device always asks you to type the code.', 'Tell WPicker the real address: add_filter( \'wpicker_client_ip\', fn() => $_SERVER[\'HTTP_X_FORWARDED_FOR\'] ?? $_SERVER[\'REMOTE_ADDR\'] ); (only if your proxy sets that header and strips it from outside requests).' );
		}

		$devices = (array) ( $f['devices'] ?? array() );
		if ( array() === $devices ) {
			$add( 'devices', 'Access', self::INFO, 'No paired devices', '', 'Run `wpicker login`.' );
		} else {
			$idle = array();
			foreach ( $devices as $d ) {
				if ( null === ( $d['days_idle'] ?? null ) || (int) $d['days_idle'] >= 90 ) {
					$idle[] = (string) $d['name'];
				}
			}
			if ( array() !== $idle ) {
				$add( 'devices', 'Access', self::WARN, count( $idle ) . ' of ' . count( $devices ) . ' paired devices unused for 90+ days (or never)', implode( ', ', $idle ), 'Revoke the ones you no longer use: `wpicker device revoke <id>`.' );
			} else {
				$add( 'devices', 'Access', self::OK, count( $devices ) . ' paired device(s), all used recently' );
			}
		}

		return $out;
	}

	/**
	 * Summarise a checklist.
	 *
	 * @param array<int,array<string,mixed>> $checks Checks.
	 * @return array<string,int>
	 */
	public static function tally( array $checks ): array {
		$n = array(
			self::OK   => 0,
			self::WARN => 0,
			self::FAIL => 0,
			self::INFO => 0,
			self::SKIP => 0,
		);
		foreach ( $checks as $c ) {
			if ( isset( $n[ $c['status'] ] ) ) {
				++$n[ $c['status'] ];
			}
		}
		return $n;
	}
}
