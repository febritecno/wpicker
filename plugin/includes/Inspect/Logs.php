<?php
/**
 * Logs — the site's PHP and WordPress error logs, parsed for diagnosis.
 *
 * Sources:
 *   php   wherever PHP's `error_log` setting points
 *   wp    wp-content/debug.log (written when WP_DEBUG_LOG is on)
 *   diag  this plugin's own private log, written while "WordPress debug
 *         logging" is switched on in Settings (see debug_boot())
 *
 * Entries are grouped: the same message at the same place is returned once,
 * with a count and the first and last time it happened — what a person or
 * an agent needs, instead of ten thousand identical lines.
 *
 * @package WPicker\Inspect
 */

declare( strict_types = 1 );

namespace WPicker\Inspect;

defined( 'ABSPATH' ) || exit;

/**
 * Class Logs
 */
final class Logs {

	/**
	 * How much of the end of each log file is read.
	 */
	private const TAIL_BYTES = 2097152;

	/**
	 * The private log is trimmed to its last KEEP_BYTES once it passes MAX_BYTES.
	 */
	private const MAX_BYTES  = 5242880;
	private const KEEP_BYTES = 1048576;

	/**
	 * Severity order, most severe first.
	 */
	private const LEVELS = array( 'fatal', 'warning', 'notice', 'deprecated', 'info' );

	/**
	 * Where PHP was logging before debug_boot() redirected it. Older errors
	 * are still there and still worth reading.
	 *
	 * @var string
	 */
	private static $server_log = '';

	/**
	 * Is "WordPress debug logging" switched on in Settings?
	 *
	 * @return bool
	 */
	public static function debug_enabled(): bool {
		$settings = get_option( 'wpicker_settings', array() );
		return (int) ( $settings['debug_log'] ?? 0 ) === 1;
	}

	/**
	 * Path of this plugin's private log. The file name carries a random
	 * token: a log can contain paths and data, and a web server that ignores
	 * .htaccess (nginx) would otherwise serve it to anyone who knew the name.
	 *
	 * @return string
	 */
	public static function private_log(): string {
		$token = (string) get_option( 'wpicker_log_token', '' );
		if ( '' === $token ) {
			$token = bin2hex( random_bytes( 16 ) );
			update_option( 'wpicker_log_token', $token, false );
		}
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . 'wpicker-vault/logs';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			@file_put_contents( $dir . '/index.html', '' );
		}
		return $dir . '/debug-' . $token . '.log';
	}

	/**
	 * Turn on full error logging for this request, if the setting says so.
	 *
	 * WP_DEBUG is a constant fixed in wp-config.php; it cannot be changed
	 * while the site runs, and rewriting wp-config.php from a plugin is not a
	 * risk worth taking. This does what WP_DEBUG + WP_DEBUG_LOG do for
	 * diagnosis: report everything, never to the screen, into a log file —
	 * and make WordPress raise its "deprecated" and "doing it wrong" notices,
	 * which it otherwise only does under WP_DEBUG.
	 *
	 * Called as early as the plugin loads. Errors raised before plugins load
	 * (in wp-config.php or in a must-use plugin) are not captured.
	 *
	 * @return void
	 */
	public static function debug_boot(): void {
		if ( ! self::debug_enabled() ) {
			return;
		}
		self::$server_log = (string) ini_get( 'error_log' );
		error_reporting( E_ALL );
		@ini_set( 'log_errors', '1' );
		@ini_set( 'display_errors', '0' );
		// If the site already logs to wp-content/debug.log, leave it there.
		if ( ! ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) ) {
			$log = self::private_log();
			clearstatcache( true, $log );
			if ( is_file( $log ) && filesize( $log ) > self::MAX_BYTES ) {
				self::trim( $log );
			}
			@ini_set( 'error_log', $log );
		}
		// WordPress only raises its "deprecated" and "doing it wrong" notices
		// as PHP errors when the WP_DEBUG constant is true. It always fires
		// these actions, though — so write the same lines to the log ourselves.
		$deprecated = static function ( string $what ): \Closure {
			return static function ( $name, $replacement = '', $version = '' ) use ( $what ): void {
				$message = sprintf( '%s %s is deprecated since version %s', $what, (string) $name, (string) $version );
				if ( is_string( $replacement ) && '' !== $replacement ) {
					$message .= '! Use ' . $replacement . ' instead';
				}
				self::write( 'Deprecated', $message . '.' );
			};
		};
		add_action( 'deprecated_function_run', $deprecated( 'Function' ), 10, 3 );
		add_action( 'deprecated_constructor_run', $deprecated( 'Constructor of' ), 10, 3 );
		add_action( 'deprecated_class_run', $deprecated( 'Class' ), 10, 3 );
		add_action( 'deprecated_file_included', $deprecated( 'File' ), 10, 3 );
		add_action(
			'deprecated_hook_run',
			static function ( $hook, $replacement = '', $version = '' ) use ( $deprecated ): void {
				$deprecated( 'Hook' )( $hook, $replacement, $version );
			},
			10,
			3
		);
		add_action(
			'deprecated_argument_run',
			static function ( $function, $message = '', $version = '' ): void {
				self::write( 'Deprecated', sprintf( 'Function %s was called with an argument that is deprecated since version %s. %s', (string) $function, (string) $version, wp_strip_all_tags( (string) $message ) ) );
			},
			10,
			3
		);
		add_action(
			'doing_it_wrong_run',
			static function ( $function, $message = '', $version = '' ): void {
				self::write( 'Notice', sprintf( 'Function %s was called incorrectly. %s (since %s)', (string) $function, wp_strip_all_tags( (string) $message ), (string) $version ) );
			},
			10,
			3
		);
	}

	/**
	 * Write one line to the PHP error log in PHP's own format, pointing at
	 * the code that caused it — the first caller outside WordPress core.
	 *
	 * @param string $kind    "Deprecated" or "Notice".
	 * @param string $message Message.
	 * @return void
	 */
	private static function write( string $kind, string $message ): void {
		$core  = wp_normalize_path( ABSPATH . WPINC ) . '/';
		$where = '';
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS, 25 ) as $frame ) {
			$file = wp_normalize_path( (string) ( $frame['file'] ?? '' ) );
			if ( '' === $file || 0 === strpos( $file, $core ) || __FILE__ === ( $frame['file'] ?? '' ) ) {
				continue;
			}
			$where = ' in ' . $frame['file'] . ' on line ' . (int) ( $frame['line'] ?? 0 );
			break;
		}
		error_log( 'PHP ' . $kind . ':  ' . $message . $where ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	/**
	 * Keep only the end of an oversized log.
	 *
	 * @param string $log Log file.
	 * @return void
	 */
	private static function trim( string $log ): void {
		$size = (int) filesize( $log );
		$tail = (string) file_get_contents( $log, false, null, max( 0, $size - self::KEEP_BYTES ) );
		$cut  = strpos( $tail, "\n" );
		@file_put_contents( $log, false === $cut ? $tail : substr( $tail, $cut + 1 ), LOCK_EX );
	}

	/**
	 * Log files that exist and can be read, by source name.
	 *
	 * @return array<string,string>
	 */
	public static function sources(): array {
		$found = array();
		$add   = static function ( string $name, string $path ) use ( &$found ): void {
			if ( '' !== $path && is_file( $path ) && is_readable( $path ) && ! in_array( realpath( $path ), array_map( 'realpath', $found ), true ) ) {
				$found[ $name ] = $path;
			}
		};
		$add( 'diag', self::private_log() );
		$add( 'wp', untrailingslashit( WP_CONTENT_DIR ) . '/debug.log' );
		$add( 'php', '' !== self::$server_log ? self::$server_log : (string) ini_get( 'error_log' ) );
		return $found;
	}

	/**
	 * Read, parse, filter and group log entries.
	 *
	 * @param array<string,mixed> $args since (minutes, 0 = all read), level
	 *                                  (least severe level to include), grep,
	 *                                  source (php|wp|diag|all), limit.
	 * @return array<string,mixed>
	 */
	public static function read( array $args ): array {
		$since  = max( 0, (int) ( $args['since'] ?? 0 ) );
		$grep   = (string) ( $args['grep'] ?? '' );
		$source = (string) ( $args['source'] ?? 'all' );
		$limit  = max( 1, min( 500, (int) ( $args['limit'] ?? 50 ) ) );
		$worst  = array_search( (string) ( $args['level'] ?? 'info' ), self::LEVELS, true );
		$worst  = false === $worst ? count( self::LEVELS ) - 1 : $worst;
		$after  = $since > 0 ? time() - 60 * $since : 0;

		$groups  = array();
		$sources = array();
		foreach ( self::sources() as $name => $path ) {
			if ( 'all' !== $source && $source !== $name ) {
				continue;
			}
			$sources[ $name ] = array(
				'file'  => self::short_path( $path ),
				'bytes' => (int) filesize( $path ),
			);
			foreach ( self::entries( $path ) as $entry ) {
				if ( $entry['time'] < $after || array_search( $entry['level'], self::LEVELS, true ) > $worst ) {
					continue;
				}
				if ( '' !== $grep && false === stripos( $entry['message'] . ' ' . $entry['file'], $grep ) ) {
					continue;
				}
				$key = $entry['level'] . '|' . $entry['message'] . '|' . $entry['file'] . '|' . $entry['line'];
				if ( ! isset( $groups[ $key ] ) ) {
					$groups[ $key ] = array(
						'level'   => $entry['level'],
						'message' => $entry['message'],
						'file'    => $entry['file'],
						'line'    => $entry['line'],
						'count'   => 0,
						'first'   => $entry['time'],
						'last'    => $entry['time'],
						'trace'   => $entry['trace'],
						'source'  => $name,
					);
				}
				++$groups[ $key ]['count'];
				$groups[ $key ]['first'] = min( $groups[ $key ]['first'], $entry['time'] );
				$groups[ $key ]['last']  = max( $groups[ $key ]['last'], $entry['time'] );
			}
		}

		// Most recent first.
		usort(
			$groups,
			static function ( array $a, array $b ): int {
				return $b['last'] <=> $a['last'];
			}
		);
		$total  = count( $groups );
		$groups = array_slice( $groups, 0, $limit );
		foreach ( $groups as &$group ) {
			$group['first_gmt'] = gmdate( 'c', $group['first'] );
			$group['last_gmt']  = gmdate( 'c', $group['last'] );
			unset( $group['first'], $group['last'] );
		}
		unset( $group );

		return array(
			'entries'       => $groups,
			'count'         => count( $groups ),
			'total'         => $total,
			'sources'       => (object) $sources, // {} rather than [] when empty
			'debug_logging' => self::debug_enabled(),
			'wp_debug'      => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'wp_debug_log'  => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
		);
	}

	/**
	 * Parse the end of one log file into entries.
	 *
	 * A PHP log entry starts with "[date] " and may continue over following
	 * lines (stack trace). Lines that do not start an entry belong to the
	 * one before.
	 *
	 * @param string $path Log file.
	 * @return array<int,array<string,mixed>>
	 */
	private static function entries( string $path ): array {
		$size = (int) filesize( $path );
		$text = (string) file_get_contents( $path, false, null, max( 0, $size - self::TAIL_BYTES ) );
		if ( $size > self::TAIL_BYTES ) {
			$text = (string) substr( $text, (int) strpos( $text, "\n" ) + 1 ); // drop the partial first line
		}

		$entries = array();
		$current = null;
		foreach ( preg_split( '/\R/', $text ) as $line ) {
			if ( 1 === preg_match( '/^\[([^\]]+)\]\s?(.*)$/', $line, $m ) && false !== ( $time = strtotime( $m[1] ) ) ) {
				if ( null !== $current ) {
					$entries[] = $current;
				}
				$current = self::entry( $time, $m[2] );
			} elseif ( null !== $current && '' !== trim( $line ) && count( $current['trace'] ) < 12 ) {
				$current['trace'][] = self::clean( trim( $line ) );
			}
		}
		if ( null !== $current ) {
			$entries[] = $current;
		}
		return $entries;
	}

	/**
	 * Build one entry from the text after the timestamp.
	 *
	 * @param int    $time Unix time.
	 * @param string $text Rest of the first line.
	 * @return array<string,mixed>
	 */
	private static function entry( int $time, string $text ): array {
		$level = 'info';
		if ( 1 === preg_match( '/^PHP (Fatal error|Parse error|Recoverable fatal error|Warning|Notice|Deprecated|Strict Standards):\s*(.*)$/s', $text, $m ) ) {
			$map   = array(
				'Fatal error'             => 'fatal',
				'Parse error'             => 'fatal',
				'Recoverable fatal error' => 'fatal',
				'Warning'                 => 'warning',
				'Notice'                  => 'notice',
				'Deprecated'              => 'deprecated',
				'Strict Standards'        => 'notice',
			);
			$level = $map[ $m[1] ];
			$text  = $m[2];
		} elseif ( false !== stripos( $text, 'WordPress database error' ) ) {
			$level = 'warning';
		}

		$file = '';
		$line = 0;
		if ( 1 === preg_match( '#\sin (/.+?\.php)(?::| on line )(\d+)#', $text, $m ) ) {
			$file = self::short_path( $m[1] );
			$line = (int) $m[2];
			$text = trim( str_replace( $m[0], '', $text ) );
		}

		return array(
			'time'    => $time,
			'level'   => $level,
			'message' => self::clean( $text ),
			'file'    => $file,
			'line'    => $line,
			'trace'   => array(),
		);
	}

	/**
	 * Make a log line fit to leave the server: site-relative paths, no
	 * embedded credentials.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function clean( string $text ): string {
		$text = str_replace( untrailingslashit( ABSPATH ) . '/', '', $text );
		return Redactor::scrub( $text );
	}

	/**
	 * A path relative to the WordPress directory when it is inside it.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	private static function short_path( string $path ): string {
		$root = untrailingslashit( ABSPATH ) . '/';
		return 0 === strpos( $path, $root ) ? substr( $path, strlen( $root ) ) : $path;
	}
}
