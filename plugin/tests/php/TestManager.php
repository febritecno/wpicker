<?php
/**
 * Test case for Manager class.
 *
 * @package WPicker\Tests
 */

declare( strict_types = 1 );

namespace WPicker\Tests;

use PHPUnit\Framework\TestCase;
use WPicker\Vault\Manager;
use WPicker\Vault\Manifest;
use WPicker\Sync\PathGuard;

/**
 * Class TestManager
 */
final class TestManager extends TestCase {

	/**
	 * Manifest store.
	 *
	 * @var Manifest
	 */
	private $manifests;

	/**
	 * Path guard.
	 *
	 * @var PathGuard
	 */
	private $guard;

	/**
	 * Vault manager.
	 *
	 * @var Manager
	 */
	private $manager;

	/**
	 * Setup before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->manifests = new Manifest();
		$this->guard     = new PathGuard();
		$this->manager   = new Manager( $this->manifests, $this->guard );

		// Reset options and transients mock.
		$GLOBALS['wp_options'] = array();
		$GLOBALS['wp_transients'] = array();

		// Clean up old directories.
		$this->clean_dir( $this->manager->root() );
		$this->clean_dir( $this->guard->theme_root() );

		// Recreate theme root.
		mkdir( $this->guard->theme_root(), 0755, true );
	}

	/**
	 * Clean up directories after tests.
	 */
	protected function tearDown(): void {
		$this->clean_dir( $this->manager->root() );
		$this->clean_dir( $this->guard->theme_root() );
		parent::tearDown();
	}

	/**
	 * Recursively remove directory.
	 */
	private function clean_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = scandir( $dir );
		if ( false === $items ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->clean_dir( $path );
			} else {
				@unlink( $path );
			}
		}
		@rmdir( $dir );
	}

	/**
	 * Test root() directory initialization and htaccess creation.
	 */
	public function test_root_dir_init(): void {
		$root = $this->manager->root();
		$this->assertDirectoryExists( $root );
		$this->assertFileExists( $root . '/.htaccess' );
	}

	/**
	 * Test snapshot creation, copying, and manifest entry.
	 */
	public function test_snapshot(): void {
		$theme_root = $this->guard->theme_root();
		file_put_contents( $theme_root . '/style.css', 'body { color: red; }' );
		file_put_contents( $theme_root . '/functions.php', '<?php // hello' );

		$device = array( 'id' => 'cli-123', 'name' => 'WPicker CLI' );
		$files = array( 'style.css', 'functions.php' );

		$snap = $this->manager->snapshot( $device, $files );

		$this->assertDirectoryExists( $snap['path'] );
		$this->assertFileExists( $snap['path'] . '/style.css' );
		$this->assertFileExists( $snap['path'] . '/functions.php' );

		$record = $this->manifests->get( $snap['id'] );
		$this->assertNotNull( $record );
		$this->assertEquals( 'pending', $record['status'] );
		$this->assertEquals( 'WPicker CLI', $record['device_name'] );
	}

	/**
	 * Test status transition helpers (mark_applied, mark_aborted).
	 */
	public function test_mark_status(): void {
		$device = array( 'id' => 'cli-123', 'name' => 'CLI' );
		$snap = $this->manager->snapshot( $device, array() );

		// mark_applied
		$this->manager->mark_applied( $snap['id'] );
		$record = $this->manifests->get( $snap['id'] );
		$this->assertEquals( 'applied', $record['status'] );

		// mark_aborted
		$snap2 = $this->manager->snapshot( $device, array() );
		$err = array( 'code' => 'wpicker_lint_syntax', 'message' => 'parse error' );
		$this->manager->mark_aborted( $snap2['id'], $err );
		$record2 = $this->manifests->get( $snap2['id'] );
		$this->assertEquals( 'aborted', $record2['status'] );
		$this->assertEquals( $err, $record2['error'] );
	}

	/**
	 * Test restore.
	 */
	public function test_restore(): void {
		$theme_root = $this->guard->theme_root();

		// 1. Setup initial style.css in theme root.
		file_put_contents( $theme_root . '/style.css', 'body { color: blue; }' );
		$device = array( 'id' => 'cli-123', 'name' => 'CLI' );

		// 2. Take first snapshot (which has blue color).
		$snap1 = $this->manager->snapshot( $device, array( 'style.css' ) );
		$this->manager->mark_applied( $snap1['id'] );

		// 3. Change theme files locally (now color is green).
		file_put_contents( $theme_root . '/style.css', 'body { color: green; }' );

		// 4. Restore back to snap1.
		$res = $this->manager->restore( $snap1['id'], $device );

		$this->assertEquals( $snap1['id'], $res['restored'] );
		$this->assertNotEmpty( $res['safety_manifest_id'] ); // safety snapshot of green state

		// 5. Verify the content of style.css is restored back to blue!
		$this->assertEquals( 'body { color: blue; }', file_get_contents( $theme_root . '/style.css' ) );

		// 6. Verify safety snapshot recorded green state.
		$safety_dir = $this->manager->dir_for( $res['safety_manifest_id'] );
		$this->assertFileExists( $safety_dir . '/style.css' );
		$this->assertEquals( 'body { color: green; }', file_get_contents( $safety_dir . '/style.css' ) );
	}

	/**
	 * Test pruning on-disk directories.
	 */
	public function test_prune(): void {
		$device = array( 'id' => 'cli-123', 'name' => 'CLI' );
		$snaps = array();

		// Create 4 snapshots
		for ( $i = 0; $i < 4; $i++ ) {
			$snap = $this->manager->snapshot( $device, array() );
			$this->manager->mark_applied( $snap['id'] );
			$snaps[] = $snap;
		}

		// Prune to keep only 2.
		$this->manager->prune_to( 2 );

		// First two snapshots should be deleted on disk and manifest.
		$this->assertDirectoryDoesNotExist( $snaps[0]['path'] );
		$this->assertNull( $this->manifests->get( $snaps[0]['id'] ) );

		$this->assertDirectoryDoesNotExist( $snaps[1]['path'] );
		$this->assertNull( $this->manifests->get( $snaps[1]['id'] ) );

		// Last two should be preserved.
		$this->assertDirectoryExists( $snaps[2]['path'] );
		$this->assertNotNull( $this->manifests->get( $snaps[2]['id'] ) );

		$this->assertDirectoryExists( $snaps[3]['path'] );
		$this->assertNotNull( $this->manifests->get( $snaps[3]['id'] ) );
	}

	/**
	 * Test that aborted snapshots are preserved from pruning.
	 */
	public function test_prune_preserves_aborted(): void {
		$device = array( 'id' => 'cli-123', 'name' => 'CLI' );

		// 1. Create an aborted snapshot.
		$snap_aborted = $this->manager->snapshot( $device, array() );
		$this->manager->mark_aborted( $snap_aborted['id'], array( 'code' => 'err' ) );

		// 2. Create 3 applied snapshots.
		$snaps = array();
		for ( $i = 0; $i < 3; $i++ ) {
			$snap = $this->manager->snapshot( $device, array() );
			$this->manager->mark_applied( $snap['id'] );
			$snaps[] = $snap;
		}

		// Prune to keep 2.
		$this->manager->prune_to( 2 );

		// The aborted snapshot (which is older than the oldest kept) should NOT be deleted!
		$this->assertDirectoryExists( $snap_aborted['path'] );
		$this->assertNotNull( $this->manifests->get( $snap_aborted['id'] ) );
	}
}
