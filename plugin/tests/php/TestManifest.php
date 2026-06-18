<?php
/**
 * Test case for Manifest class.
 *
 * @package WPicker\Tests
 */

declare( strict_types = 1 );

namespace WPicker\Tests;

use PHPUnit\Framework\TestCase;
use WPicker\Vault\Manifest;

/**
 * Class TestManifest
 */
final class TestManifest extends TestCase {

	/**
	 * Manifest store instance.
	 *
	 * @var Manifest
	 */
	private $manifests;

	/**
	 * Setup before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->manifests = new Manifest();

		// Reset options mock state.
		$GLOBALS['wp_options'] = array();
	}

	/**
	 * Test all() returns empty array by default.
	 */
	public function test_all_default(): void {
		$this->assertEquals( array(), $this->manifests->all() );
	}

	/**
	 * Test CRUD operations (add, get, update, delete).
	 */
	public function test_crud(): void {
		$id = $this->manifests->generate_id();
		$device = array( 'id' => 'dev1', 'name' => 'CLI 1' );
		$files = array( 'style.css', 'functions.php' );

		$record = $this->manifests->build_record( $id, $device, $files );
		$this->manifests->add( $record );

		// Test get
		$retrieved = $this->manifests->get( $id );
		$this->assertNotNull( $retrieved );
		$this->assertEquals( $id, $retrieved['id'] );
		$this->assertEquals( 'pending', $retrieved['status'] );
		$this->assertEquals( 2, $retrieved['count'] );

		// Test update
		$record['status'] = 'applied';
		$this->manifests->update( $id, $record );
		$updated = $this->manifests->get( $id );
		$this->assertEquals( 'applied', $updated['status'] );

		// Test delete
		$this->manifests->delete( $id );
		$this->assertNull( $this->manifests->get( $id ) );
	}

	/**
	 * Test generate_id() format.
	 */
	public function test_generate_id(): void {
		$id = $this->manifests->generate_id();
		$this->assertMatchesRegularExpression( '/^\d{8}-\d{6}-[0-9a-f]{6}$/', $id );
	}

	/**
	 * Test prune() trims older manifests.
	 */
	public function test_prune(): void {
		$device = array( 'id' => 'dev1', 'name' => 'CLI' );
		$files = array();

		// Add 5 manifests.
		for ( $i = 0; $i < 5; $i++ ) {
			$id = 'id-' . $i;
			$record = $this->manifests->build_record( $id, $device, $files );
			$this->manifests->add( $record );
		}

		$this->assertCount( 5, $this->manifests->all() );

		// Prune to keep only the 3 most recent
		$this->manifests->prune( 3 );
		$remaining = $this->manifests->all();

		$this->assertCount( 3, $remaining );
		// Should keep id-2, id-3, id-4 (newest first inside option is stored in append order)
		$this->assertEquals( 'id-2', $remaining[0]['id'] );
		$this->assertEquals( 'id-3', $remaining[1]['id'] );
		$this->assertEquals( 'id-4', $remaining[2]['id'] );
	}
}
