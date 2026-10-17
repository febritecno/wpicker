<?php
/**
 * PairRateLimit — counts requests per source, so one address cannot hammer the
 * anonymous pairing routes.
 *
 * There is deliberately NO site-wide switch here: a limit that pauses pairing for
 * everyone once enough garbage arrives would let any anonymous visitor turn
 * pairing off. The device_code is 192 bits; counting per source is enough.
 *
 * @package WPicker\Auth
 */

declare( strict_types = 1 );

namespace WPicker\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Class PairRateLimit
 */
final class PairRateLimit {

	/**
	 * Who is asking. REMOTE_ADDR, never X-Forwarded-For, which anyone can forge;
	 * a site behind a proxy supplies the real address with the
	 * `wpicker_client_ip` filter.
	 *
	 * @return string
	 */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : ''; // phpcs:ignore WordPress.Security
		if ( function_exists( 'apply_filters' ) ) {
			$ip = (string) apply_filters( 'wpicker_client_ip', $ip );
		}
		return $ip;
	}

	/**
	 * Is this request likely to have come through a proxy or CDN that WPicker cannot see past?
	 * Then every visitor looks like the same address: per-source limits would apply to everyone
	 * at once, and "this request came from another network than your browser" could never be
	 * told. A site that knows better says so by hooking `wpicker_client_ip`.
	 *
	 * @return bool
	 */
	public static function behind_proxy(): bool {
		if ( function_exists( 'has_filter' ) && has_filter( 'wpicker_client_ip' ) ) {
			return false;
		}
		foreach ( array( 'HTTP_X_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP', 'HTTP_FORWARDED', 'HTTP_X_REAL_IP' ) as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) { // phpcs:ignore WordPress.Security
				return true;
			}
		}
		return false;
	}

	/**
	 * A coarser group for caps that one household or office could otherwise multiply:
	 * an IPv6 customer owns a whole /48 (65 536 different /64s).
	 *
	 * @param string $ip Address.
	 * @return string
	 */
	public static function wide_bucket( string $ip ): string {
		if ( false === strpos( $ip, ':' ) ) {
			return $ip;
		}
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $packed || 16 !== strlen( $packed ) ) {
			return $ip;
		}
		return bin2hex( substr( $packed, 0, 6 ) ) . '/48';
	}

	/**
	 * Group addresses so that rotating inside one IPv6 /64 does not reset the count.
	 *
	 * @param string $ip Address.
	 * @return string
	 */
	public static function bucket( string $ip ): string {
		if ( false === strpos( $ip, ':' ) ) {
			return $ip;
		}
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $packed || 16 !== strlen( $packed ) ) {
			return $ip;
		}
		return bin2hex( substr( $packed, 0, 8 ) ) . '/64';
	}

	/**
	 * Count one more event of a kind from this source, and say how many there have
	 * been in the window (including this one).
	 *
	 * @param string $scope  What is being counted (start | poll | miss).
	 * @param string $ip     Address.
	 * @param int    $window Seconds the count lasts.
	 * @return int
	 */
	public function hit( string $scope, string $ip, int $window ): int {
		$key   = $this->key( $scope, $ip );
		$now   = time();
		$state = get_transient( $key );
		if ( is_array( $state ) && (int) ( $state['reset'] ?? 0 ) > $now ) {
			$n     = (int) $state['n'] + 1;
			$reset = (int) $state['reset'];
		} else {
			$n     = 1;
			$reset = $now + $window;
		}
		// The window ends when it first started: counting a refused attempt must not push the end
		// further away, or someone who keeps retrying would stay locked out for ever.
		set_transient( $key, array( 'n' => $n, 'reset' => $reset ), max( 1, $reset - $now ) );
		return $n;
	}

	/**
	 * @param string $scope What is counted.
	 * @param string $ip    Address.
	 * @return int
	 */
	public function count( string $scope, string $ip ): int {
		$state = get_transient( $this->key( $scope, $ip ) );
		return is_array( $state ) && (int) ( $state['reset'] ?? 0 ) > time() ? (int) $state['n'] : 0;
	}

	/**
	 * @param string $scope What is counted.
	 * @param string $ip    Address.
	 * @return string
	 */
	private function key( string $scope, string $ip ): string {
		return 'wpicker_rl_' . $scope . '_' . md5( self::bucket( $ip ) );
	}
}
