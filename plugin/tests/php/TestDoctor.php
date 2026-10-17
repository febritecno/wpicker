<?php

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use WPicker\Health\Doctor;

final class TestDoctor extends TestCase {

	/**
	 * A healthy site; each test changes one fact.
	 *
	 * @param array<string,mixed> $over Facts to override.
	 * @return array<string,mixed>
	 */
	private function facts( array $over = array() ): array {
		return array_merge(
			array(
				'plugin_version' => '1.1.0',
				'php'            => '8.2.0',
				'wp'             => '6.7',
				'https'          => true,
				'env'            => 'production',
				'app_passwords'  => true,
				'server'         => 'Apache/2.4',
				'vault_exists'   => true,
				'vault_writable' => true,
				'vault_htaccess' => true,
				'disk_free'      => 5 * 1073741824,
				'snapshots'      => 3,
				'vault_keep'     => 30,
				'memory'         => 256 * 1048576,
				'max_exec'       => 120,
				'child_theme'    => true,
				'theme_name'     => 'my-child',
				'theme_writable' => true,
				'lint'           => true,
				'health_check'   => true,
				'allow_inspect'  => true,
				'allow_backup'   => true,
				'debug_log'      => false,
				'push_mode'      => 'direct',
				'devices'        => array( array( 'name' => 'laptop', 'days_idle' => 2 ) ),
				'probe'          => array( 'ok' => true, 'status' => 0, 'url' => '' ),
			),
			$over
		);
	}

	/**
	 * @param array<string,mixed> $over Facts to override.
	 * @return array<string,string> id => status
	 */
	private function statuses( array $over = array() ): array {
		$out = array();
		foreach ( Doctor::evaluate( $this->facts( $over ) ) as $c ) {
			$out[ $c['id'] ] = $c['status'];
		}
		return $out;
	}

	public function test_a_healthy_site_has_no_warnings_or_failures(): void {
		$tally = Doctor::tally( Doctor::evaluate( $this->facts() ) );
		$this->assertSame( 0, $tally['warn'] );
		$this->assertSame( 0, $tally['fail'] );
		$this->assertGreaterThan( 5, $tally['ok'] );
	}

	public function test_every_problem_carries_a_way_to_fix_it(): void {
		$bad = $this->facts(
			array(
				'php'            => '7.2.0',
				'https'          => false,
				'app_passwords'  => false,
				'vault_writable' => false,
				'disk_free'      => 1048576,
				'child_theme'    => false,
				'lint'           => false,
				'debug_log'      => true,
				'memory'         => 64 * 1048576,
				'max_exec'       => 30,
				'probe'          => array( 'ok' => null, 'status' => 0, 'url' => '' ),
				'devices'        => array( array( 'name' => 'old', 'days_idle' => 400 ) ),
			)
		);
		$seen = 0;
		foreach ( Doctor::evaluate( $bad ) as $c ) {
			if ( in_array( $c['status'], array( 'warn', 'fail' ), true ) ) {
				++$seen;
				$this->assertNotSame( '', $c['fix'], $c['id'] . ' is a problem but says nothing about fixing it' );
				$this->assertNotSame( '', $c['title'] );
			}
		}
		$this->assertGreaterThan( 8, $seen );
	}

	public function test_old_php_fails(): void {
		$this->assertSame( 'fail', $this->statuses( array( 'php' => '7.3.9' ) )['php'] );
		$this->assertSame( 'ok', $this->statuses( array( 'php' => '7.4.0' ) )['php'] );
	}

	public function test_plain_http_is_only_fine_on_a_local_site(): void {
		$this->assertSame( 'warn', $this->statuses( array( 'https' => false ) )['https'] );
		$this->assertSame( 'info', $this->statuses( array( 'https' => false, 'env' => 'local' ) )['https'] );
	}

	public function test_application_passwords_are_required(): void {
		$this->assertSame( 'fail', $this->statuses( array( 'app_passwords' => false ) )['app_passwords'] );
	}

	public function test_storage_rules(): void {
		$this->assertSame( 'fail', $this->statuses( array( 'vault_writable' => false ) )['vault'] );
		$this->assertSame( 'fail', $this->statuses( array( 'vault_exists' => false, 'vault_writable' => false ) )['vault'] );
		$this->assertSame( 'warn', $this->statuses( array( 'server' => 'nginx/1.25' ) )['vault_web'] );
		$this->assertSame( 'ok', $this->statuses( array( 'server' => 'LiteSpeed' ) )['vault_web'] );
	}

	public function test_disk_thresholds(): void {
		$this->assertSame( 'fail', $this->statuses( array( 'disk_free' => 10 * 1048576 ) )['disk'] );
		$this->assertSame( 'warn', $this->statuses( array( 'disk_free' => 100 * 1048576 ) )['disk'] );
		$this->assertSame( 'ok', $this->statuses( array( 'disk_free' => 500 * 1048576 ) )['disk'] );
		$this->assertArrayNotHasKey( 'disk', $this->statuses( array( 'disk_free' => null ) ), 'unknown free space must not be reported as a number' );
	}

	public function test_a_parent_theme_blocks_pushing_but_not_reading(): void {
		$s = $this->statuses( array( 'child_theme' => false ) );
		$this->assertSame( 'warn', $s['child_theme'] );
		$this->assertSame( 'fail', $this->statuses( array( 'theme_writable' => false ) )['theme_writable'] );
	}

	public function test_loopback_outcomes_are_told_apart(): void {
		$this->assertSame( 'ok', $this->statuses()['loopback'] );
		$this->assertSame( 'fail', $this->statuses( array( 'probe' => array( 'ok' => false, 'status' => 500, 'url' => 'x' ) ) )['loopback'] );
		$this->assertSame( 'warn', $this->statuses( array( 'probe' => array( 'ok' => null, 'status' => 0, 'url' => '' ) ) )['loopback'] );
		$this->assertSame( 'skip', $this->statuses( array( 'probe' => null ) )['loopback'] );
		$this->assertSame( 'info', $this->statuses( array( 'health_check' => false ) )['loopback'] );
	}

	public function test_blocked_loopback_is_not_reported_as_a_broken_site(): void {
		foreach ( Doctor::evaluate( $this->facts( array( 'probe' => array( 'ok' => null, 'status' => 0, 'url' => '' ) ) ) ) as $c ) {
			if ( 'loopback' === $c['id'] ) {
				$this->assertStringNotContainsString( 'error right now', $c['title'] );
				$this->assertStringContainsString( 'loopback', strtolower( $c['title'] ) );
			}
		}
	}

	public function test_debug_logging_left_on_is_flagged(): void {
		$this->assertSame( 'warn', $this->statuses( array( 'debug_log' => true ) )['debug_log'] );
		$this->assertArrayNotHasKey( 'debug_log', $this->statuses() );
	}

	public function test_backup_limits_only_matter_when_backups_are_allowed(): void {
		$low = array( 'memory' => 64 * 1048576, 'max_exec' => 30 );
		$this->assertSame( 'warn', $this->statuses( $low )['memory'] );
		$this->assertSame( 'warn', $this->statuses( $low )['max_exec'] );
		$off = $this->statuses( $low + array( 'allow_backup' => false ) );
		$this->assertArrayNotHasKey( 'memory', $off );
		$this->assertSame( 'info', $off['backup'] );
	}

	public function test_unlimited_php_time_is_not_a_warning(): void {
		$this->assertArrayNotHasKey( 'max_exec', $this->statuses( array( 'max_exec' => 0 ) ) );
	}

	public function test_idle_and_never_used_devices_are_flagged(): void {
		$s = $this->statuses( array( 'devices' => array( array( 'name' => 'a', 'days_idle' => 89 ), array( 'name' => 'b', 'days_idle' => 90 ) ) ) );
		$this->assertSame( 'warn', $s['devices'] );
		$s = $this->statuses( array( 'devices' => array( array( 'name' => 'new', 'days_idle' => null ) ) ) );
		$this->assertSame( 'warn', $s['devices'] );
		$this->assertSame( 'info', $this->statuses( array( 'devices' => array() ) )['devices'] );
	}

	public function test_diagnostic_access_off_is_information_not_a_fault(): void {
		$this->assertSame( 'info', $this->statuses( array( 'allow_inspect' => false ) )['inspect'] );
	}

	public function test_tally_counts_each_status(): void {
		$t = Doctor::tally(
			array(
				array( 'status' => 'ok' ),
				array( 'status' => 'ok' ),
				array( 'status' => 'fail' ),
				array( 'status' => 'bogus' ),
			)
		);
		$this->assertSame( 2, $t['ok'] );
		$this->assertSame( 1, $t['fail'] );
		$this->assertSame( 0, $t['warn'] );
	}

	public function test_a_measured_answer_beats_the_guess(): void {
		// nginx would be a warning by guess; a measured 403 shows it is closed.
		$this->assertSame( 'ok', $this->statuses( array( 'server' => 'nginx/1.26', 'vault_htaccess' => true, 'vault_web' => 403 ) )['vault_web'] );
		$this->assertSame( 'ok', $this->statuses( array( 'server' => 'nginx/1.26', 'vault_web' => 404 ) )['vault_web'] );
		// ...and a measured 200 is a warning even where .htaccess exists.
		$this->assertSame( 'warn', $this->statuses( array( 'server' => 'Apache/2.4', 'vault_htaccess' => true, 'vault_web' => 200 ) )['vault_web'] );
	}

	public function test_without_a_measurement_it_falls_back_to_the_server_type(): void {
		$this->assertSame( 'ok', $this->statuses( array( 'server' => 'Apache/2.4', 'vault_htaccess' => true, 'vault_web' => null ) )['vault_web'] );
		$s = $this->statuses( array( 'server' => 'nginx/1.26', 'vault_web' => null ) );
		$this->assertSame( 'warn', $s['vault_web'] );
		foreach ( Doctor::evaluate( $this->facts( array( 'server' => 'nginx/1.26', 'vault_web' => null ) ) ) as $c ) {
			if ( 'vault_web' === $c['id'] ) {
				$this->assertStringContainsString( 'Could not confirm', $c['title'], 'an untested folder must not be called reachable' );
			}
		}
	}

	public function test_disk_space_reads_naturally(): void {
		foreach ( Doctor::evaluate( $this->facts( array( 'disk_free' => 450 * 1073741824 ) ) ) as $c ) {
			if ( 'disk' === $c['id'] ) {
				$this->assertSame( '450 GB of disk space free', $c['title'] );
			}
		}
	}
}
