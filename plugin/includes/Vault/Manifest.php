<?php
/**
 * Vault Manifest store — persistence for snapshot manifests.
 *
 * Stores an ordered list of manifests in the `wpicker_vault_manifests` option.
 * Each manifest describes one push (or rollback safety snapshot): id, time,
 * device, affected files, status, optional error log.
 *
 * NOTE: option-based storage is chosen deliberately — manifest records are
 * small (metadata only; file contents live on disk in the vault dir) and
 * autoloaded access is cheap. This is plugin-owned storage, not CLI DB write.
 *
 * @package WPicker\Vault
 */

declare( strict_types = 1 );

namespace WPicker\Vault;

defined( 'ABSPATH' ) || exit;

/**
 * Class Manifest
 */
final class Manifest {

	/**
	 * Option key.
	 */
	public const OPTION = 'wpicker_vault_manifests';

	/**
	 * Get all manifests, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function all(): array {
		$manifests = get_option( self::OPTION, array() );
		if ( ! is_array( $manifests ) ) {
			return array();
		}
		return array_values( $manifests );
	}

	/**
	 * Get a single manifest by id.
	 *
	 * @param string $id Manifest id.
	 * @return array<string,mixed>|null
	 */
	public function get( string $id ): ?array {
		foreach ( $this->all() as $m ) {
			if ( ( $m['id'] ?? '' ) === $id ) {
				return $m;
			}
		}
		return null;
	}

	/**
	 * Add a manifest and persist.
	 *
	 * @param array<string,mixed> $manifest Manifest record.
	 * @return void
	 */
	public function add( array $manifest ): void {
		$manifests   = $this->all();
		$manifests[] = $manifest;
		$this->save( $manifests );
	}

	/**
	 * Replace an existing manifest by id (used to update status/error log).
	 *
	 * @param string               $id       Manifest id.
	 * @param array<string,mixed>  $manifest New manifest data.
	 * @return void
	 */
	public function update( string $id, array $manifest ): void {
		$manifests = $this->all();
		foreach ( $manifests as $i => $m ) {
			if ( ( $m['id'] ?? '' ) === $id ) {
				$manifest['id'] = $id;
				$manifests[ $i ] = $manifest;
				break;
			}
		}
		$this->save( $manifests );
	}

	/**
	 * Remove a manifest by id.
	 *
	 * @param string $id Manifest id.
	 * @return void
	 */
	public function delete( string $id ): void {
		$manifests = $this->all();
		$manifests = array_filter(
			$manifests,
			static function ( $m ) use ( $id ): bool {
				return ( $m['id'] ?? '' ) !== $id;
			}
		);
		$this->save( array_values( $manifests ) );
	}

	/**
	 * Trim the store to the most recent N records.
	 *
	 * @param int $keep Number to retain.
	 * @return void
	 */
	public function prune( int $keep ): void {
		$manifests = $this->all();
		if ( count( $manifests ) <= $keep ) {
			return;
		}
		// Newest are appended last; drop from the front.
		$manifests = array_slice( $manifests, count( $manifests ) - $keep );
		$this->save( array_values( $manifests ) );
	}

	/**
	 * Persist the manifest list.
	 *
	 * @param array<int,array<string,mixed>> $manifests Manifests.
	 * @return void
	 */
	private function save( array $manifests ): void {
		update_option( self::OPTION, $manifests, false );
	}

	/**
	 * Generate a unique, sortable manifest id.
	 *
	 * Format: YYYYMMDD-HHMMSS-<6 hex>. Sortable as a string, globally unique.
	 *
	 * @return string
	 */
	public function generate_id(): string {
		return gmdate( 'Ymd-His' ) . '-' . substr( bin2hex( random_bytes( 3 ) ), 0, 6 );
	}

	/**
	 * Build a default manifest record for a new push.
	 *
	 * @param string              $id        Manifest id.
	 * @param array<string,mixed> $device    Acting device record.
	 * @param array<int,string>   $files     Relative file paths affected.
	 * @param string              $kind      'push' | 'rollback_safety'.
	 * @return array<string,mixed>
	 */
	public function build_record( string $id, array $device, array $files, string $kind = 'push' ): array {
		return array(
			'id'             => $id,
			'kind'           => $kind,
			'created_at_gmt' => gmdate( 'c' ),
			'device_id'      => $device['id'] ?? '',
			'device_name'    => $device['name'] ?? '',
			'files'          => array_values( $files ),
			'count'          => count( $files ),
			'status'         => 'pending', // pending|applied|aborted|restored
			'restore_count'  => 0,
			'note'           => '',
			'error'          => null,
		);
	}
}
