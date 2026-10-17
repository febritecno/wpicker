<?php
/**
 * WpdbRequestStore — DeviceRequestStore on the options table.
 *
 * Rows are options with autoload off. The primitives use single SQL statements
 * whose affected-row count is the answer, so they hold under parallel requests
 * and with any object-cache drop-in.
 *
 * @package WPicker\Auth
 */

declare( strict_types = 1 );

namespace WPicker\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Class WpdbRequestStore
 */
final class WpdbRequestStore implements DeviceRequestStore {

	private const MAX_ROWS = 500;

	/**
	 * @param string              $name  Row name.
	 * @param array<string,mixed> $value Row value.
	 * @return bool
	 */
	public function insert( string $name, array $value ): bool {
		global $wpdb;
		$json = wp_json_encode( $value );
		if ( ! is_string( $json ) ) {
			return false;
		}
		$rows = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", // phpcs:ignore WordPress.DB.PreparedSQL
				$name,
				$json
			)
		);
		return 1 === (int) $rows;
	}

	/**
	 * @param string $name Row name.
	 * @return array<string,mixed>|null
	 */
	public function get( string $name ): ?array {
		global $wpdb;
		$raw = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) // phpcs:ignore WordPress.DB.PreparedSQL
		);
		if ( ! is_string( $raw ) ) {
			return null;
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * @param string $name Row name.
	 * @return bool
	 */
	public function delete( string $name ): bool {
		global $wpdb;
		$rows = $wpdb->delete( $wpdb->options, array( 'option_name' => $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( $name, 'options' );
		return 1 === (int) $rows;
	}

	/**
	 * @param string $prefix Name prefix.
	 * @return array<string,array<string,mixed>>
	 */
	public function all( string $prefix ): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL
				$wpdb->esc_like( $prefix ) . '%',
				self::MAX_ROWS
			)
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$data = json_decode( (string) $row->option_value, true );
			if ( is_array( $data ) ) {
				$out[ (string) $row->option_name ] = $data;
			}
		}
		return $out;
	}
}
