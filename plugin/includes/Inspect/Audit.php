<?php
/**
 * Audit — who looked at what through the diagnostic endpoints.
 *
 * A short ring buffer in an option, shown in WP-Admin → WPicker → Settings.
 * Reading a site's database and files is a privilege; the owner should be
 * able to see it being used.
 *
 * @package WPicker\Inspect
 */

declare( strict_types = 1 );

namespace WPicker\Inspect;

defined( 'ABSPATH' ) || exit;

/**
 * Class Audit
 */
final class Audit {

	/**
	 * Option holding the entries, newest last.
	 */
	public const OPTION = 'wpicker_audit';

	/**
	 * Entries kept.
	 */
	private const KEEP = 200;

	/**
	 * Record one access.
	 *
	 * @param string $action What was done (logs, file.read, db.query, ...).
	 * @param string $detail The path, query or filter used.
	 * @return void
	 */
	public static function record( string $action, string $detail = '' ): void {
		$entries   = self::entries();
		$entries[] = array(
			'time_gmt' => gmdate( 'c' ),
			'user'     => wp_get_current_user()->user_login,
			'device'   => self::device_name(),
			'action'   => $action,
			'detail'   => function_exists( 'mb_substr' ) ? mb_substr( $detail, 0, 300 ) : substr( $detail, 0, 300 ),
		);
		update_option( self::OPTION, array_slice( $entries, -self::KEEP ), false );
	}

	/**
	 * Entries, oldest first.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function entries(): array {
		$entries = get_option( self::OPTION, array() );
		return is_array( $entries ) ? $entries : array();
	}

	/**
	 * Name of the paired device making this request, if it is one.
	 *
	 * @return string
	 */
	private static function device_name(): string {
		$uuid = function_exists( 'rest_get_authenticated_app_password' ) ? rest_get_authenticated_app_password() : null;
		if ( null === $uuid ) {
			return 'wp-admin';
		}
		foreach ( (array) get_option( 'wpicker_devices', array() ) as $device ) {
			if ( ( $device['id'] ?? '' ) === $uuid ) {
				return (string) ( $device['name'] ?? $uuid );
			}
		}
		return (string) $uuid;
	}
}
