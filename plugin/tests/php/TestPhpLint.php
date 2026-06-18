<?php
/**
 * Test case for PhpLint class.
 *
 * @package WPicker\Tests
 */

declare( strict_types = 1 );

namespace WPicker\Tests;

use PHPUnit\Framework\TestCase;
use WPicker\Lint\PhpLint;

/**
 * Class TestPhpLint
 */
final class TestPhpLint extends TestCase {

	/**
	 * PhpLint helper.
	 *
	 * @var PhpLint
	 */
	private $lint;

	/**
	 * Setup before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->lint = new PhpLint();
	}

	/**
	 * Test lint_source() with valid PHP.
	 */
	public function test_lint_source_valid(): void {
		$source = '<?php echo "Hello World";';
		$res = $this->lint->lint_source( $source );
		$this->assertTrue( $res['ok'] );
	}

	/**
	 * Test lint_source() with invalid PHP syntax.
	 */
	public function test_lint_source_invalid(): void {
		$source = '<?php echo "Hello World"'; // Missing semicolon
		$res = $this->lint->lint_source( $source );

		$this->assertFalse( $res['ok'] );
		$this->assertEquals( 'wpicker_lint_syntax', $res['code'] );
		$this->assertStringContainsString( 'syntax error', strtolower( $res['message'] ) );
		$this->assertEquals( 1, $res['line'] );
	}

	/**
	 * Test lint_file() with valid and invalid files.
	 */
	public function test_lint_file(): void {
		$temp = sys_get_temp_dir();
		$valid_file = $temp . '/wpicker_lint_valid_test.php';
		$invalid_file = $temp . '/wpicker_lint_invalid_test.php';

		file_put_contents( $valid_file, '<?php $x = 10;' );
		file_put_contents( $invalid_file, '<?php function test() { // missing brace' );

		try {
			$res_valid = $this->lint->lint_file( $valid_file );
			$this->assertTrue( $res_valid['ok'] );

			$res_invalid = $this->lint->lint_file( $invalid_file );
			$this->assertFalse( $res_invalid['ok'] );
			$this->assertEquals( 'wpicker_lint_syntax', $res_invalid['code'] );
		} finally {
			@unlink( $valid_file );
			@unlink( $invalid_file );
		}
	}

	/**
	 * Test lint_files() lists first syntax error.
	 */
	public function test_lint_files(): void {
		$temp = sys_get_temp_dir();
		$f1 = $temp . '/wpicker_lint_f1.php';
		$f2 = $temp . '/wpicker_lint_f2.php';

		file_put_contents( $f1, '<?php $x = 10;' );
		file_put_contents( $f2, '<?php function test() {' ); // syntax error

		try {
			$res = $this->lint->lint_files( array( $f1, $f2 ) );
			$this->assertFalse( $res['ok'] );
			$this->assertEquals( 'wpicker_lint_syntax', $res['code'] );
			$this->assertEquals( 'wpicker_lint_f2.php', $res['file'] );
			$this->assertEquals( 2, $res['checked'] );
		} finally {
			@unlink( $f1 );
			@unlink( $f2 );
		}
	}
}
