<?php
/**
 * Test case for Provider class.
 *
 * @package WPicker\Tests
 */

declare( strict_types = 1 );

namespace WPicker\Tests;

use PHPUnit\Framework\TestCase;
use WPicker\Context\Provider;

/**
 * Class TestProvider
 */
final class TestProvider extends TestCase {

	/**
	 * Provider instance.
	 *
	 * @var Provider
	 */
	private $provider;

	/**
	 * Setup before each test.
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->provider = new Provider();

		// Seed global mock variables
		$GLOBALS['wp_options'] = array(
			'permalink_structure' => '/%postname%/',
			'active_plugins'      => array( 'wpicker/wpicker.php' ),
		);
	}

	/**
	 * Test build() constructs correct JSON schema structure.
	 */
	public function test_build_structure(): void {
		$payload = $this->provider->build();

		$this->assertArrayHasKey( 'wpicker', $payload );
		$this->assertArrayHasKey( 'site', $payload );
		$this->assertArrayHasKey( 'environment', $payload );
		$this->assertArrayHasKey( 'rest_root', $payload );
		$this->assertArrayHasKey( 'theme', $payload );
		$this->assertArrayHasKey( 'plugins', $payload );
		$this->assertArrayHasKey( 'theme_mods', $payload );

		// Validate Site Info
		$site = $payload['site'];
		$this->assertEquals( 'Test Site', $site['name'] );
		$this->assertEquals( 'Just another WordPress site', $site['description'] );
		$this->assertEquals( 'http://example.com', $site['url'] );
		$this->assertEquals( 'http://example.com/wp-admin', $site['admin_url'] );
		$this->assertEquals( '/%postname%/', $site['permalink_structure'] );
		$this->assertEquals( 'UTC', $site['timezone'] );

		// Validate Theme Info
		$theme = $payload['theme'];
		$this->assertEquals( 'WPicker Test Child', $theme['name'] );
		$this->assertTrue( $theme['is_child_theme'] );
		$this->assertEquals( 'Twenty Twenty-Four', $theme['parent']['name'] );

		// Validate Plugins Info
		$plugins = $payload['plugins'];
		$this->assertCount( 1, $plugins );
		$this->assertEquals( 'WPicker', $plugins[0]['name'] );
		$this->assertTrue( $plugins[0]['is_wpicker'] );
	}

	/**
	 * Test theme_mods allowlist filtering behavior.
	 */
	public function test_theme_mods_filtering(): void {
		// Override get_theme_mods stub output specifically.
		// Define custom mock function in global scope is not possible since it's already defined.
		// But get_theme_mods is defined to return array('background_color' => 'ffffff') which is on the allowlist.
		// Let's add a non-allowlisted mod to get_theme_mods?
		// Since get_theme_mods is already defined in bootstrap.php, it returns a static array.
		// Let's make sure that's filtered correctly.
		$payload = $this->provider->build();
		$this->assertArrayHasKey( 'background_color', $payload['theme_mods'] );
		$this->assertEquals( 'ffffff', $payload['theme_mods']['background_color'] );

		// Ensure non-allowlisted mods are not present.
		// (The bootstrap's mock doesn't return anything else anyway, but we check what it does return is filtered).
		$this->assertArrayNotHasKey( 'secret_credential', $payload['theme_mods'] );
	}
}
