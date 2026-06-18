<?php
/**
 * Test case for PathGuard class.
 *
 * @package WPicker\Tests
 */

declare( strict_types = 1 );

namespace WPicker\Tests;

use PHPUnit\Framework\TestCase;
use WPicker\Sync\PathGuard;

/**
 * Class TestPathGuard
 */
final class TestPathGuard extends TestCase {

	/**
	 * PathGuard instance.
	 *
	 * @var PathGuard
	 */
	private $guard;

	/**
	 * Setup before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->guard = new PathGuard();

		// Clean up and recreate test theme root directory.
		$theme_root = $this->guard->theme_root();
		if ( ! is_dir( $theme_root ) ) {
			mkdir( $theme_root, 0755, true );
		}
	}

	/**
	 * Test theme_root() returns expected directory.
	 */
	public function test_theme_root(): void {
		$this->assertStringContainsString( 'wpicker-test-child', $this->guard->theme_root() );
	}

	/**
	 * Test has_child_theme() returns true when template !== stylesheet.
	 */
	public function test_has_child_theme(): void {
		$this->assertTrue( $this->guard->has_child_theme() );
	}

	/**
	 * Test resolve() resolves a clean relative path.
	 */
	public function test_resolve_success(): void {
		$rel = 'style.css';
		$abs = $this->guard->resolve( $rel );
		$this->assertNotNull( $abs );
		$this->assertStringEndsWith( 'style.css', $abs );
	}

	/**
	 * Test resolve() blocks traversal segments.
	 */
	public function test_resolve_blocks_traversals(): void {
		$this->assertNull( $this->guard->resolve( '../escape.txt' ) );
		$this->assertNull( $this->guard->resolve( 'inc/../../escape.txt' ) );
		$this->assertNull( $this->guard->resolve( 'inc/.././../escape.txt' ) );
	}

	/**
	 * Test resolve() blocks absolute paths.
	 */
	public function test_resolve_blocks_absolute_paths(): void {
		$this->assertNull( $this->guard->resolve( 'C:\\Windows\\win.ini' ) );
		// POSIX absolute paths get normalized (leading slash stripped) to relative.
		$abs = $this->guard->resolve( '/etc/passwd' );
		$this->assertNotNull( $abs );
		$this->assertStringEndsWith( 'etc/passwd', $abs );
	}

	/**
	 * Test resolve() blocks sensitive file names.
	 */
	public function test_resolve_blocks_sensitive_files(): void {
		$this->assertNull( $this->guard->resolve( '.env' ) );
		$this->assertNull( $this->guard->resolve( 'wp-config.php' ) );
		$this->assertNull( $this->guard->resolve( 'sub/WP-CONFIG.PHP' ) );
		$this->assertNull( $this->guard->resolve( 'sub/.ENV' ) );
	}

	/**
	 * Test is_inside_theme().
	 */
	public function test_is_inside_theme(): void {
		$theme_root = $this->guard->theme_root();
		$this->assertTrue( $this->guard->is_inside_theme( $theme_root . '/style.css' ) );
		$this->assertTrue( $this->guard->is_inside_theme( $theme_root ) );
		$this->assertFalse( $this->guard->is_inside_theme( dirname( $theme_root ) . '/other-theme' ) );
	}

	/**
	 * Test relative_to_theme().
	 */
	public function test_relative_to_theme(): void {
		$theme_root = realpath( $this->guard->theme_root() );
		$this->assertEquals( 'style.css', $this->guard->relative_to_theme( $theme_root . '/style.css' ) );
		$this->assertEquals( 'inc/helpers.php', $this->guard->relative_to_theme( $theme_root . '/inc/helpers.php' ) );
		$this->assertNull( $this->guard->relative_to_theme( dirname( $theme_root ) . '/other.css' ) );
	}
}
