<?php

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use WPicker\Inspect\Files;

/**
 * Files::why_unavailable explains a refusal without revealing what lies
 * outside wp-content.
 */
final class TestFilesWhy extends TestCase {

	/** @var string */
	private static $base;

	public static function setUpBeforeClass(): void {
		self::$base = realpath( sys_get_temp_dir() ) . '/wpicker-why-' . bin2hex( random_bytes( 4 ) );
		mkdir( self::$base . '/content/plugins/real', 0777, true );
		mkdir( self::$base . '/elsewhere', 0777, true );
		file_put_contents( self::$base . '/wp-config.php', '<?php // secret' );
		file_put_contents( self::$base . '/content/wp-config-sample.php', '<?php' );
		symlink( self::$base . '/elsewhere', self::$base . '/content/plugins/linked' );
		if ( ! defined( 'WP_CONTENT_DIR' ) ) {
			define( 'WP_CONTENT_DIR', self::$base . '/content' );
		}
	}

	public function setUp(): void {
		if ( realpath( WP_CONTENT_DIR ) !== self::$base . '/content' ) {
			$this->markTestSkipped( 'WP_CONTENT_DIR was already defined elsewhere.' );
		}
	}

	public function test_a_link_out_of_wp_content_is_named_as_such_without_its_target(): void {
		$why = Files::why_unavailable( 'plugins/linked/x.php' );
		$this->assertStringContainsString( 'symbolic link', $why );
		$this->assertStringNotContainsString( 'elsewhere', $why );
	}

	public function test_climbing_out_looks_exactly_like_a_missing_path(): void {
		$this->assertSame( Files::why_unavailable( 'nope.php' ), Files::why_unavailable( '../wp-config.php' ) );
	}

	public function test_a_withheld_file_inside_wp_content_is_called_withheld(): void {
		$this->assertStringContainsString( 'withheld', Files::why_unavailable( 'wp-config-sample.php' ) );
	}

	public function test_resolve_still_refuses_all_of_them(): void {
		$this->assertNull( Files::resolve( 'plugins/linked' ) );
		$this->assertNull( Files::resolve( '../wp-config.php' ) );
		$this->assertNull( Files::resolve( 'wp-config-sample.php' ) );
	}
}
