<?php

declare( strict_types = 1 );

namespace WPicker\Tests;

use WPicker\Auth\DeviceRequestStore;

/**
 * In-memory DeviceRequestStore with the same contract as the SQL one: insert is
 * first-wins, delete is true only for the call that removed the row.
 */
final class ArrayStore implements DeviceRequestStore {

	/** @var array<string,array<string,mixed>> */
	public $rows = array();

	/**
	 * Called with the row name just BEFORE a delete runs: lets a test make "another
	 * request got there first" happen at exactly the right moment.
	 *
	 * @var callable|null
	 */
	public $before_delete = null;

	public function insert( string $name, array $value ): bool {
		if ( isset( $this->rows[ $name ] ) ) {
			return false;
		}
		$this->rows[ $name ] = $value;
		return true;
	}

	public function get( string $name ): ?array {
		return $this->rows[ $name ] ?? null;
	}

	public function delete( string $name ): bool {
		if ( null !== $this->before_delete ) {
			$hook                = $this->before_delete;
			$this->before_delete = null; // once
			$hook( $name );
		}
		$had = isset( $this->rows[ $name ] );
		unset( $this->rows[ $name ] );
		return $had;
	}

	public function all( string $prefix ): array {
		$out = array();
		foreach ( $this->rows as $name => $value ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				$out[ $name ] = $value;
			}
		}
		return $out;
	}
}
