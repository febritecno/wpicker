<?php
/**
 * DeviceRequestStore — where pending device-pairing requests live.
 *
 * The three primitives below carry the whole security argument of the device
 * flow, so they are small and exact:
 *
 *   insert()  succeeds for the FIRST caller only (INSERT IGNORE);
 *   delete()  is true for the ONE caller whose statement really removed a row;
 *   get()     reads straight from storage.
 *
 * They deliberately do not rely on the object cache: a Redis or Memcached
 * drop-in may answer "true" to every delete, which would let two parallel polls
 * both be handed credentials.
 *
 * @package WPicker\Auth
 */

declare( strict_types = 1 );

namespace WPicker\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Interface DeviceRequestStore
 */
interface DeviceRequestStore {

	/**
	 * Create a row. True only for the first caller; false if it already exists.
	 *
	 * @param string              $name  Row name.
	 * @param array<string,mixed> $value Row value.
	 * @return bool
	 */
	public function insert( string $name, array $value ): bool;

	/**
	 * Read a row.
	 *
	 * @param string $name Row name.
	 * @return array<string,mixed>|null
	 */
	public function get( string $name ): ?array;

	/**
	 * Remove a row. True only if THIS call removed it.
	 *
	 * @param string $name Row name.
	 * @return bool
	 */
	public function delete( string $name ): bool;

	/**
	 * Rows whose name starts with a prefix (bounded).
	 *
	 * @param string $prefix Name prefix.
	 * @return array<string,array<string,mixed>> name => value.
	 */
	public function all( string $prefix ): array;
}
