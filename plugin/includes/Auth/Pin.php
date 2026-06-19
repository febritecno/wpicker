<?php
/**
 * PIN helper — single-use 6-digit challenge codes for device registration.
 *
 * Flow:
 *   1. Admin (or any user with edit_themes) calls /device/challenge.
 *   2. A 6-digit PIN is generated, stored as a transient (default 10 min, 1 use),
 *      keyed by user id. It is surfaced in the admin dashboard for the user to
 *      read and paste into the CLI.
 *   3. The CLI submits the PIN via /device/register; the transient is verified
 *      and consumed, and a new Application Password is minted for that user.
 *
 * @package WPicker\Auth
 */

declare( strict_types = 1 );

namespace WPicker\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Class Pin
 */
final class Pin {

	/**
	 * Transient key prefix.
	 */
	public const TRANSIENT_PREFIX = 'wpicker_pin_';

	/**
	 * Default PIN TTL in seconds.
	 */
	public const DEFAULT_TTL = 10;

	/**
	 * Default PIN length (digits).
	 */
	public const DEFAULT_LENGTH = 6;

	/**
	 * Issue a fresh PIN for a user. Replaces any existing PIN for that user.
	 *
	 * @param int      $user_id User id.
	 * @param int|null $ttl     TTL seconds (null = settings default).
	 * @param int|null $length  PIN length (null = settings default).
	 * @return array{ pin: string, expires_at_gmt: string, ttl: int }
	 */
	public function issue( int $user_id, ?int $ttl = null, ?int $length = null ): array {
		$settings = get_option( 'wpicker_settings', array() );
		$ttl      = $ttl ?? (int) ( $settings['pin_ttl'] ?? self::DEFAULT_TTL );
		$length   = $length ?? (int) ( $settings['pin_length'] ?? self::DEFAULT_LENGTH );
		$length   = max( 4, min( 10, $length ) );

		$pin = '';
		for ( $i = 0; $i < $length; $i++ ) {
			$pin .= (string) random_int( 0, 9 );
		}

		$payload = array(
			'pin'      => $pin,
			'user_id'  => $user_id,
			'issued_at_gmt' => time(),
			'used'     => false,
		);

		set_transient( self::key( $user_id ), $payload, $ttl );

		return array(
			'pin'             => $pin,
			'ttl'             => $ttl,
			'expires_at_gmt'  => gmdate( 'c', time() + $ttl ),
		);
	}

	/**
	 * Verify a PIN for a user. On success, consumes it (one-shot).
	 *
	 * @param int    $user_id User id.
	 * @param string $pin     Submitted PIN.
	 * @return bool
	 */
	public function verify( int $user_id, string $pin ): bool {
		$payload = get_transient( self::key( $user_id ) );
		if ( ! is_array( $payload ) || empty( $payload['pin'] ) ) {
			return false;
		}
		if ( ! empty( $payload['used'] ) ) {
			return false;
		}
		if ( ! hash_equals( (string) $payload['pin'], (string) $pin ) ) {
			return false;
		}
		// Consume: mark used and delete.
		delete_transient( self::key( $user_id ) );
		return true;
	}

	/**
	 * Peek at the current PIN for a user (admin display) without consuming it.
	 *
	 * @param int $user_id User id.
	 * @return array{ pin: string, expires_at_gmt: string }|null
	 */
	public function current( int $user_id ): ?array {
		$payload = get_transient( self::key( $user_id ) );
		if ( ! is_array( $payload ) || empty( $payload['pin'] ) ) {
			return null;
		}
		$issued = (int) ( $payload['issued_at_gmt'] ?? time() );
		$settings = get_option( 'wpicker_settings', array() );
		$ttl      = (int) ( $settings['pin_ttl'] ?? self::DEFAULT_TTL );
		return array(
			'pin'            => (string) $payload['pin'],
			'expires_at_gmt' => gmdate( 'c', $issued + $ttl ),
		);
	}

	/**
	 * Clear any current PIN for a user.
	 *
	 * @param int $user_id User id.
	 * @return void
	 */
	public function clear( int $user_id ): void {
		delete_transient( self::key( $user_id ) );
	}

	/**
	 * Transient key for a user.
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	private function key( int $user_id ): string {
		return self::TRANSIENT_PREFIX . $user_id;
	}
}
