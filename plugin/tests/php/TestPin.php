<?php
/**
 * Test case for Pin class.
 *
 * @package WPicker\Tests
 */

declare( strict_types = 1 );

namespace WPicker\Tests;

use PHPUnit\Framework\TestCase;
use WPicker\Auth\Pin;

/**
 * Class TestPin
 */
final class TestPin extends TestCase {

	/**
	 * Pin helper instance.
	 *
	 * @var Pin
	 */
	private $pins;

	/**
	 * Setup before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->pins = new Pin();

		// Reset mocks
		$GLOBALS['wp_options'] = array();
		$GLOBALS['wp_transients'] = array();
	}

	/**
	 * Test issue() generates correct PIN structure and transient.
	 */
	public function test_issue(): void {
		$user_id = 42;
		$res = $this->pins->issue( $user_id, 300, 6 );

		$this->assertEquals( 6, strlen( $res['pin'] ) );
		$this->assertEquals( 300, $res['ttl'] );
		$this->assertNotEmpty( $res['expires_at_gmt'] );

		// Check global transient state
		$transient_key = Pin::TRANSIENT_PREFIX . $user_id;
		$this->assertArrayHasKey( $transient_key, $GLOBALS['wp_transients'] );
		$this->assertEquals( $res['pin'], $GLOBALS['wp_transients'][ $transient_key ]['value']['pin'] );
		$this->assertEquals( $user_id, $GLOBALS['wp_transients'][ $transient_key ]['value']['user_id'] );
	}

	/**
	 * Test verify() consumes PIN on success and rejects on failure.
	 */
	public function test_verify_success_and_consumption(): void {
		$user_id = 42;
		$res = $this->pins->issue( $user_id );

		// Incorrect PIN
		$this->assertFalse( $this->pins->verify( $user_id, '000000' ) );

		// Correct PIN
		$this->assertTrue( $this->pins->verify( $user_id, $res['pin'] ) );

		// Already consumed (verify should fail now)
		$this->assertFalse( $this->pins->verify( $user_id, $res['pin'] ) );
	}

	/**
	 * Test current() returns active PIN metadata without consuming it.
	 */
	public function test_current(): void {
		$user_id = 99;
		$this->assertNull( $this->pins->current( $user_id ) );

		$res = $this->pins->issue( $user_id );
		$curr = $this->pins->current( $user_id );

		$this->assertNotNull( $curr );
		$this->assertEquals( $res['pin'], $curr['pin'] );

		// Should still verify because current does not consume
		$this->assertTrue( $this->pins->verify( $user_id, $res['pin'] ) );
	}

	/**
	 * Test clear() removes transient.
	 */
	public function test_clear(): void {
		$user_id = 55;
		$this->pins->issue( $user_id );

		$this->pins->clear( $user_id );
		$this->assertNull( $this->pins->current( $user_id ) );
	}
}
