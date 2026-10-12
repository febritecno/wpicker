<?php
/**
 * Health Probe — loopback check that the site still boots after a deploy.
 *
 * `php -l` only catches syntax errors. A file that parses but dies at runtime
 * (undefined function, redeclared class, ...) takes the whole site down,
 * including the REST API the CLI would need to roll back. The probe requests
 * the site from itself right after files are applied so the push handler can
 * restore the snapshot while it still has control.
 *
 * @package WPicker\Health
 */

declare( strict_types = 1 );

namespace WPicker\Health;

use WPicker\Sync\PathGuard;

defined( 'ABSPATH' ) || exit;

/**
 * Class Probe
 */
final class Probe {

	/**
	 * Per-request timeout in seconds.
	 */
	private const TIMEOUT = 8;

	/**
	 * How much of the error log tail to scan for the fatal message.
	 */
	private const LOG_TAIL_BYTES = 65536;

	/**
	 * Path guard (used to make error paths theme-relative).
	 *
	 * @var PathGuard
	 */
	private $guard;

	/**
	 * Constructor.
	 *
	 * @param PathGuard $guard Path guard.
	 */
	public function __construct( PathGuard $guard ) {
		$this->guard = $guard;
	}

	/**
	 * Is the post-deploy health check enabled?
	 *
	 * @return bool
	 */
	public function enabled(): bool {
		$settings = get_option( 'wpicker_settings', array() );
		return (int) ( $settings['health_check'] ?? 1 ) === 1;
	}

	/**
	 * Request the front page and the REST namespace from the site itself.
	 *
	 * `ok` is true when every reachable target answered below 500, false when
	 * any answered 5xx, and null when no target could be reached at all
	 * (loopback blocked) — in which case the caller must not draw conclusions.
	 *
	 * @return array{ ok: bool|null, status: int, url: string }
	 */
	public function check(): array {
		$targets = array(
			home_url( '/' ),
			rest_url( 'wpicker/v1' ),
		);

		$reached = 0;
		foreach ( $targets as $target ) {
			$url      = add_query_arg( 'wpicker_health', wp_generate_password( 8, false ), $target );
			$response = wp_remote_get(
				$url,
				array(
					'timeout'     => self::TIMEOUT,
					'redirection' => 2,
					'sslverify'   => false,
					'cookies'     => array(),
					'headers'     => array( 'Cache-Control' => 'no-cache' ),
				)
			);
			if ( is_wp_error( $response ) ) {
				continue;
			}
			++$reached;
			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( $status >= 500 ) {
				return array(
					'ok'     => false,
					'status' => $status,
					'url'    => $target,
				);
			}
		}

		return array(
			'ok'     => $reached > 0 ? true : null,
			'status' => 0,
			'url'    => '',
		);
	}

	/**
	 * Remember where the PHP error log currently ends, so that after a failed
	 * check only the lines written by this deploy are inspected.
	 *
	 * @return array{ path: string, offset: int }
	 */
	public function log_mark(): array {
		$path = (string) ini_get( 'error_log' );
		if ( '' === $path || ! is_readable( $path ) ) {
			return array(
				'path'   => '',
				'offset' => 0,
			);
		}
		clearstatcache( true, $path );
		return array(
			'path'   => $path,
			'offset' => (int) filesize( $path ),
		);
	}

	/**
	 * Find the last fatal/parse error logged since the given mark.
	 *
	 * @param array{ path: string, offset: int } $mark Result of log_mark().
	 * @return array{ message: string, file: string|null, line: int|null }|null
	 */
	public function fatal_since( array $mark ): ?array {
		$path = $mark['path'];
		if ( '' === $path || ! is_readable( $path ) ) {
			return null;
		}
		clearstatcache( true, $path );
		$size   = (int) filesize( $path );
		$offset = max( (int) $mark['offset'], $size - self::LOG_TAIL_BYTES );
		if ( $size <= $offset ) {
			return null;
		}
		$chunk = (string) file_get_contents( $path, false, null, $offset, $size - $offset );

		$found = null;
		foreach ( preg_split( '/\R/', $chunk ) as $line ) {
			if ( preg_match( '/PHP (?:Fatal|Parse) error:\s*(.+)$/', $line, $m ) ) {
				$found = trim( $m[1] );
			}
		}
		if ( null === $found ) {
			return null;
		}

		$file = null;
		$line = null;
		if ( preg_match( '#\sin (/.+?\.php)(?::| on line )(\d+)#', $found, $m ) ) {
			$file = $this->guard->relative_to_theme( $m[1] ) ?? $m[1];
			$line = (int) $m[2];
		}

		// The message is shown to the agent and in WP-Admin: a path inside the
		// theme reads better, and leaks less, as "functions.php" than as the
		// server's absolute path.
		$found = str_replace( trailingslashit( $this->guard->theme_root() ), '', $found );

		return array(
			'message' => $found,
			'file'    => $file,
			'line'    => $line,
		);
	}
}
