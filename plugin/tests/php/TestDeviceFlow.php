<?php

declare( strict_types = 1 );

namespace WPicker\Tests;

use PHPUnit\Framework\TestCase;
use WPicker\Auth\DeviceFlow;
use WPicker\Auth\PairRateLimit;

final class TestDeviceFlow extends TestCase {

	/** @var ArrayStore */
	private $store;

	/** @var DeviceFlow */
	private $flow;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wp_options']    = array();
		$GLOBALS['wp_transients'] = array();
		$this->store              = new ArrayStore();
		$this->flow               = new DeviceFlow( $this->store );
	}

	private function begin( string $ip = '203.0.113.5', string $name = 'laptop' ): array {
		$s = $this->flow->start( $name, $ip, 'wpicker-cli/1.3' );
		$this->assertNotNull( $s );
		return $s;
	}

	private function hash_of( array $started ): string {
		return DeviceFlow::hash( $started['device_code'] );
	}

	// --- shapes ---------------------------------------------------------------------------

	public function test_start_returns_a_long_secret_and_a_short_human_code(): void {
		$s = $this->begin();
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{48}$/', $s['device_code'] );
		$this->assertMatchesRegularExpression( '/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{4}-[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{4}$/', $s['user_code'] );
		$this->assertSame( DeviceFlow::TTL, $s['expires_in'] );
		$this->assertGreaterThan( 0, $s['interval'] );
	}

	public function test_the_secret_is_never_stored(): void {
		$s    = $this->begin();
		$dump = json_encode( $this->store->rows );
		$this->assertStringNotContainsString( $s['device_code'], $dump );
	}

	public function test_codes_are_not_repeated(): void {
		$seen = array();
		for ( $i = 0; $i < 40; $i++ ) {
			$this->store = new ArrayStore();
			$this->flow  = new DeviceFlow( $this->store );
			$s           = $this->begin( '198.51.100.' . $i );
			$seen[ $s['device_code'] ] = $s['user_code'];
		}
		$this->assertCount( 40, $seen );
	}

	public function test_a_label_from_a_stranger_is_made_harmless(): void {
		$this->assertSame( 'Your laptop', DeviceFlow::clean_label( "Your\nlaptop" ) );
		$this->assertSame( 'a b', DeviceFlow::clean_label( "a\x00\x07b" ) );
		$this->assertSame( 80, mb_strlen( DeviceFlow::clean_label( str_repeat( 'é', 500 ) ) ) );
		$s = $this->flow->start( "  \n\t ", '203.0.113.9', "UA\r\nInjected: header" );
		$req = $this->store->get( DeviceFlow::REQ . $this->hash_of( $s ) );
		$this->assertSame( 'WPicker CLI', $req['name'] );
		$this->assertStringNotContainsString( "\n", $req['agent'] );
	}

	// --- limits ---------------------------------------------------------------------------

	public function test_one_source_gets_three_pending_requests_and_is_then_refused(): void {
		for ( $i = 0; $i < DeviceFlow::MAX_PENDING_PER_IP; $i++ ) {
			$this->assertNotNull( $this->flow->start( 'a', '203.0.113.7', '' ) );
		}
		$this->assertNull( $this->flow->start( 'a', '203.0.113.7', '' ) );
		$this->assertNotNull( $this->flow->start( 'a', '203.0.113.8', '' ), 'another address is unaffected' );
	}

	public function test_an_ipv6_source_is_limited_by_its_64(): void {
		for ( $i = 0; $i < DeviceFlow::MAX_PENDING_PER_IP; $i++ ) {
			$this->flow->start( 'a', '2001:db8:1:2::' . ( $i + 1 ), '' );
		}
		$this->assertNull( $this->flow->start( 'a', '2001:db8:1:2:ffff::9', '' ) );
		$this->assertNotNull( $this->flow->start( 'a', '2001:db8:1:3::1', '' ) );
	}

	public function test_when_the_site_is_full_new_requests_are_refused_not_old_ones_evicted(): void {
		$first = $this->begin( '10.0.0.1' );
		for ( $i = 2; $i <= DeviceFlow::MAX_PENDING_SITE; $i++ ) {
			$this->assertNotNull( $this->flow->start( 'x', '10.' . intdiv( $i, 250 ) . '.' . ( $i % 250 ) . '.9', '' ), "request $i" );
		}
		$this->assertNull( $this->flow->start( 'x', '192.0.2.200', '' ) );
		$this->assertSame( 'pending', $this->flow->poll( $first['device_code'] )['status'], 'the real request must survive the flood' );
	}

	public function test_expired_requests_make_room_and_are_cleaned_up(): void {
		for ( $i = 0; $i < DeviceFlow::MAX_PENDING_PER_IP; $i++ ) {
			$this->flow->start( 'a', '203.0.113.7', '' );
		}
		foreach ( $this->store->rows as $name => $row ) {
			if ( 0 === strpos( $name, DeviceFlow::REQ ) ) {
				$this->store->rows[ $name ]['expires'] = time() - 1;
			}
		}
		$this->assertNotNull( $this->flow->start( 'a', '203.0.113.7', '' ) );
		$this->assertCount( 1, $this->store->all( DeviceFlow::REQ ), 'the old ones were pruned' );
		$this->assertCount( 1, $this->store->all( DeviceFlow::USR ), 'and their index rows with them' );
	}

	// --- the person's side -----------------------------------------------------------------

	public function test_the_code_on_the_terminal_finds_its_request_however_it_is_typed(): void {
		$s = $this->begin();
		foreach ( array( $s['user_code'], strtolower( $s['user_code'] ), str_replace( '-', '', $s['user_code'] ), ' ' . $s['user_code'] . ' ' ) as $typed ) {
			$found = $this->flow->lookup( $typed );
			$this->assertNotNull( $found, $typed );
			$this->assertSame( $this->hash_of( $s ), $found['hash'] );
			$this->assertSame( 'laptop', $found['request']['name'] );
			$this->assertFalse( $found['decided'] );
		}
	}

	public function test_lookup_refuses_junk_unknown_and_expired(): void {
		$s = $this->begin();
		foreach ( array( '', 'ABC', 'AAAA-AAAAA', 'IIII-OOOO', '0000-1111', '../../etc' ) as $bad ) {
			$this->assertNull( $this->flow->lookup( $bad ), $bad );
		}
		$this->assertNull( $this->flow->lookup( 'ZZZZ-ZZZZ' ) );
		$this->store->rows[ DeviceFlow::REQ . $this->hash_of( $s ) ]['expires'] = time() - 1;
		$this->assertNull( $this->flow->lookup( $s['user_code'] ) );
	}

	public function test_the_human_code_alone_gets_nothing_from_the_poll(): void {
		$s = $this->begin();
		$this->flow->decide( $this->hash_of( $s ), 'approved', 5, '203.0.113.5' );
		foreach ( array( $s['user_code'], str_replace( '-', '', $s['user_code'] ), bin2hex( $s['user_code'] ), DeviceFlow::hash( $s['user_code'] ), $this->hash_of( $s ) ) as $not_the_secret ) {
			$this->assertSame( 'invalid', $this->flow->poll( $not_the_secret )['status'], 'only the device_code may be accepted' );
		}
		$this->assertSame( 'approved', $this->flow->poll( $s['device_code'] )['status'] );
	}

	public function test_the_first_decision_wins(): void {
		$s = $this->begin();
		$h = $this->hash_of( $s );
		$this->assertTrue( $this->flow->decide( $h, 'approved', 5, '203.0.113.5' ) );
		$this->assertFalse( $this->flow->decide( $h, 'approved', 6, '203.0.113.6' ), 'a second approve' );
		$this->assertFalse( $this->flow->decide( $h, 'denied', 5, '203.0.113.5' ), 'a deny after an approve' );
		$r = $this->flow->poll( $s['device_code'] );
		$this->assertSame( 5, $r['user_id'], 'the credentials belong to the first approver' );
	}

	public function test_deny_after_deny_and_approve_after_deny_do_not_flip_the_outcome(): void {
		$s = $this->begin();
		$h = $this->hash_of( $s );
		$this->assertTrue( $this->flow->decide( $h, 'denied', 5, '1.1.1.1' ) );
		$this->assertFalse( $this->flow->decide( $h, 'approved', 5, '1.1.1.1' ) );
		$this->assertSame( 'denied', $this->flow->poll( $s['device_code'] )['status'] );
	}

	public function test_decide_refuses_nonsense(): void {
		$s = $this->begin();
		$h = $this->hash_of( $s );
		$this->assertFalse( $this->flow->decide( $h, 'maybe', 5, 'x' ) );
		$this->assertFalse( $this->flow->decide( $h, 'approved', 0, 'x' ), 'no user, no approval' );
		$this->assertFalse( $this->flow->decide( str_repeat( 'a', 40 ), 'approved', 5, 'x' ), 'unknown request' );
		$this->store->rows[ DeviceFlow::REQ . $h ]['expires'] = time() - 1;
		$this->assertFalse( $this->flow->decide( $h, 'approved', 5, 'x' ), 'expired request' );
	}

	// --- the CLI's side --------------------------------------------------------------------

	public function test_undecided_requests_are_pending_and_an_eager_client_is_told_to_slow_down(): void {
		$s = $this->begin();
		$this->assertSame( 'pending', $this->flow->poll( $s['device_code'] )['status'] );
		$this->assertSame( 'slow_down', $this->flow->poll( $s['device_code'] )['status'], 'asking again at once' );
	}

	public function test_an_approved_request_hands_over_exactly_once(): void {
		$s = $this->begin();
		$this->flow->decide( $this->hash_of( $s ), 'approved', 7, '203.0.113.5' );
		$r = $this->flow->poll( $s['device_code'] );
		$this->assertSame( 'approved', $r['status'] );
		$this->assertSame( 7, $r['user_id'] );
		$this->assertSame( 'laptop', $r['request']['name'] );
		$this->assertSame( 'invalid', $this->flow->poll( $s['device_code'] )['status'], 'the second time there is nothing' );
		$this->assertSame( array(), $this->store->rows, 'nothing is left behind' );
	}

	public function test_the_loser_of_two_parallel_polls_gets_nothing(): void {
		$s = $this->begin();
		$this->flow->decide( $this->hash_of( $s ), 'approved', 7, '203.0.113.5' );
		$inner = null;
		// While the first poll is deleting the request row, a second poll gets in and wins.
		$this->store->before_delete = function () use ( &$inner, $s ) {
			$inner = $this->flow->poll( $s['device_code'] );
		};
		$outer = $this->flow->poll( $s['device_code'] );
		$this->assertSame( 'approved', $inner['status'], 'one of them is given the credentials' );
		$this->assertSame( 'invalid', $outer['status'], 'and the other one is not' );
	}

	public function test_a_denied_request_says_so_once(): void {
		$s = $this->begin();
		$this->flow->decide( $this->hash_of( $s ), 'denied', 7, '203.0.113.5' );
		$this->assertSame( 'denied', $this->flow->poll( $s['device_code'] )['status'] );
		$this->assertSame( 'invalid', $this->flow->poll( $s['device_code'] )['status'] );
	}

	public function test_an_expired_request_is_expired_even_if_it_was_approved(): void {
		$s = $this->begin();
		$h = $this->hash_of( $s );
		$this->flow->decide( $h, 'approved', 7, '203.0.113.5' );
		$this->store->rows[ DeviceFlow::REQ . $h ]['expires'] = time() - 1;
		$this->assertSame( 'expired', $this->flow->poll( $s['device_code'] )['status'], 'too late: no credentials' );
		$this->assertSame( array(), $this->store->rows );
	}

	public function test_garbage_device_codes_are_invalid(): void {
		$this->begin();
		foreach ( array( '', 'abc', str_repeat( 'g', 48 ), str_repeat( 'a', 48 ), str_repeat( 'A', 48 ), "../../x" ) as $junk ) {
			$this->assertSame( 'invalid', $this->flow->poll( $junk )['status'], $junk );
		}
	}

	public function test_prune_removes_orphans_but_not_live_requests(): void {
		$live = $this->begin( '10.0.0.1' );
		$this->store->insert( DeviceFlow::USR . 'orphan', array( 'h' => str_repeat( '0', 40 ) ) );
		$this->store->insert( DeviceFlow::DEC . str_repeat( '1', 40 ), array( 'status' => 'approved', 'user_id' => 1, 'at' => time() ) );
		$this->flow->prune();
		$this->assertNull( $this->store->get( DeviceFlow::USR . 'orphan' ) );
		$this->assertNull( $this->store->get( DeviceFlow::DEC . str_repeat( '1', 40 ) ) );
		$this->assertSame( 'pending', $this->flow->poll( $live['device_code'] )['status'] );
	}

	public function test_requests_do_not_interfere(): void {
		$a = $this->begin( '10.0.0.1', 'a' );
		$b = $this->begin( '10.0.0.2', 'b' );
		$this->flow->decide( $this->hash_of( $b ), 'approved', 9, '10.0.0.2' );
		$this->assertSame( 'pending', $this->flow->poll( $a['device_code'] )['status'] );
		$this->assertSame( 9, $this->flow->poll( $b['device_code'] )['user_id'] );
	}

	// --- per-source counting ---------------------------------------------------------------

	public function test_the_rate_limit_counts_per_source_and_has_no_site_wide_switch(): void {
		$l = new PairRateLimit();
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->assertSame( $i, $l->hit( 'start', '203.0.113.5', 900 ) );
		}
		$this->assertSame( 1, $l->hit( 'start', '203.0.113.6', 900 ), 'another source starts from zero' );
		$this->assertSame( 0, $l->count( 'poll', '203.0.113.5' ), 'kinds are counted separately' );
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/Auth/PairRateLimit.php' );
		$this->assertStringNotContainsString( 'paused', strtolower( (string) preg_replace( '#/\*.*?\*/#s', '', $src ) ), 'a site-wide pause would let anyone switch pairing off' );
	}

	public function test_an_ipv6_source_is_counted_by_its_64(): void {
		$this->assertSame( PairRateLimit::bucket( '2001:db8:1:2:aaaa::1' ), PairRateLimit::bucket( '2001:db8:1:2:ffff::9' ) );
		$this->assertNotSame( PairRateLimit::bucket( '2001:db8:1:2::1' ), PairRateLimit::bucket( '2001:db8:1:3::1' ) );
		$this->assertSame( '192.0.2.1', PairRateLimit::bucket( '192.0.2.1' ) );
	}

	public function test_one_ipv6_customer_cannot_multiply_the_cap_by_using_many_64s(): void {
		// 256 different /64s of one /48: each is under its own limit, together they are one customer.
		for ( $i = 0; $i < DeviceFlow::MAX_PENDING_PER_WIDE; $i++ ) {
			$this->assertNotNull( $this->flow->start( 'a', sprintf( '2001:db8:aaaa:%x::1', $i + 1 ), '' ), "request $i" );
		}
		$this->assertNull( $this->flow->start( 'a', '2001:db8:aaaa:ffff::1', '' ), 'the /48 is full' );
		$this->assertNotNull( $this->flow->start( 'a', '2001:db8:bbbb:1::1', '' ), 'another customer is unaffected' );
		$this->assertSame( PairRateLimit::wide_bucket( '2001:db8:aaaa:1::1' ), PairRateLimit::wide_bucket( '2001:db8:aaaa:ffff::9' ) );
		$this->assertSame( '192.0.2.5', PairRateLimit::wide_bucket( '192.0.2.5' ) );
	}

	public function test_a_refused_attempt_does_not_push_the_end_of_the_window_further_away(): void {
		$l = new PairRateLimit();
		$l->hit( 'start', '203.0.113.5', 900 );
		$key = null;
		foreach ( array_keys( $GLOBALS['wp_transients'] ) as $k ) {
			if ( 0 === strpos( (string) $k, 'wpicker_rl_start_' ) ) {
				$key = $k;
			}
		}
		$this->assertNotNull( $key );
		$first_end = $GLOBALS['wp_transients'][ $key ]['value']['reset'];
		// The window is almost over; more attempts (even refused ones) must not renew it.
		$GLOBALS['wp_transients'][ $key ]['value']['reset'] = time() + 5;
		$GLOBALS['wp_transients'][ $key ]['expires']        = time() + 5;
		for ( $i = 0; $i < 10; $i++ ) {
			$l->hit( 'start', '203.0.113.5', 900 );
		}
		$this->assertLessThanOrEqual( time() + 5, $GLOBALS['wp_transients'][ $key ]['value']['reset'] );
		$this->assertLessThanOrEqual( time() + 5, $GLOBALS['wp_transients'][ $key ]['expires'], 'the storage lifetime must not be renewed either' );
		$this->assertGreaterThan( 0, $first_end );
		// and once it is over, counting starts again from one
		$GLOBALS['wp_transients'][ $key ]['value']['reset'] = time() - 1;
		$this->assertSame( 1, $l->hit( 'start', '203.0.113.5', 900 ) );
	}

	public function test_a_site_behind_a_proxy_is_recognised_unless_it_says_otherwise(): void {
		foreach ( array( 'HTTP_X_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP', 'HTTP_FORWARDED', 'HTTP_X_REAL_IP' ) as $h ) {
			unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_FORWARDED'], $_SERVER['HTTP_X_REAL_IP'] );
			$this->assertFalse( PairRateLimit::behind_proxy() );
			$_SERVER[ $h ] = '198.51.100.7';
			$this->assertTrue( PairRateLimit::behind_proxy(), $h );
		}
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_SERVER['HTTP_FORWARDED'], $_SERVER['HTTP_X_REAL_IP'] );
	}
}
