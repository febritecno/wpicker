<?php

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use WPicker\Inspect\Logs;

final class TestLogs extends TestCase {

	public static function setUpBeforeClass(): void {
		if ( ! function_exists( 'untrailingslashit' ) ) {
			function untrailingslashit( $value ) {
				return rtrim( (string) $value, '/\\' );
			}
		}
	}

	public function test_parses_woocommerce_entries_with_continuation_lines(): void {
		$text    = "2026-01-02T10:00:00+00:00 ERROR Payment failed for order 123\n"
			. "CONTEXT: {\"source\":\"stripe\"}\n"
			. "2026-01-02T10:05:00+00:00 INFO Webhook received\n";
		$entries = Logs::parse_wc( $text, 'stripe' );
		$this->assertCount( 2, $entries );
		$this->assertSame( 'error', $entries[0]['level'] );
		$this->assertSame( 'Payment failed for order 123', $entries[0]['message'] );
		$this->assertSame( array( 'CONTEXT: {"source":"stripe"}' ), $entries[0]['trace'] );
		$this->assertSame( 'info', $entries[1]['level'] );
	}

	public function test_declined_cards_are_errors_not_fatals(): void {
		$entries = Logs::parse_wc( "2026-01-02T10:00:00+00:00 CRITICAL gateway down\n", 'paypal' );
		$this->assertSame( 'error', $entries[0]['level'] );
	}

	public function test_only_the_fatal_errors_log_yields_fatal(): void {
		$entries = Logs::parse_wc( "2026-01-02T10:00:00+00:00 ERROR Uncaught Error\n", 'fatal-errors' );
		$this->assertSame( 'fatal', $entries[0]['level'] );
	}

	public function test_secrets_in_a_gateway_log_never_leave(): void {
		$text    = "2026-01-02T10:00:00+00:00 ERROR Request with " . "sk_" . "live_4eC39HqLyjWDarjtT1zdp7dc card 4242 4242 4242 4242 cvc: 123\n";
		$message = Logs::parse_wc( $text, 'stripe' )[0]['message'];
		$this->assertStringNotContainsString( 'sk_live_', $message );
		$this->assertStringNotContainsString( '4242 4242', $message );
		$this->assertStringNotContainsString( '123', $message );
	}

	public function test_long_lines_are_cut(): void {
		$entries = Logs::parse_wc( '2026-01-02T10:00:00+00:00 ERROR ' . str_repeat( 'x', 5000 ) . "\n", 'x' );
		$this->assertLessThan( 600, strlen( $entries[0]['message'] ) );
	}

	public function test_group_key_ignores_order_ids_and_hashes(): void {
		$a = Logs::group_key( 'Order 1001 declined, req_1a2b3c4d5e6f7a8b9c0d amount 19.99' );
		$b = Logs::group_key( 'Order 2002 declined, req_9f8e7d6c5b4a39281706 amount 5.00' );
		$this->assertSame( $a, $b );
		$this->assertNotSame( $a, Logs::group_key( 'Order 1001 refunded' ) );
	}

	public function test_handle_strips_date_and_hash(): void {
		$this->assertSame( 'stripe', Logs::wc_handle( 'stripe-2026-01-02-' . str_repeat( 'a1', 16 ) . '.log' ) );
		$this->assertSame( 'fatal-errors', Logs::wc_handle( 'fatal-errors-2026-01-02-' . str_repeat( 'b2', 16 ) . '.log' ) );
		$this->assertSame( 'paypal', Logs::wc_handle( 'paypal.log' ) );
	}

	public function test_a_secret_at_the_cut_boundary_does_not_leak(): void {
		// The closing quote lies beyond the 500-byte cut: scrubbing must see the whole line first.
		$line    = '{"secret_key":"abcSECRETxyz' . str_repeat( 'x', 600 ) . '"}';
		$entries = Logs::parse_wc( '2026-01-02T10:00:00+00:00 ERROR ' . $line . "\n", 'stripe' );
		$this->assertStringNotContainsString( 'abcSECRET', $entries[0]['message'] );
	}

	public function test_cut_does_not_split_a_utf8_character(): void {
		$entries = Logs::parse_wc( '2026-01-02T10:00:00+00:00 ERROR ' . str_repeat( 'é', 400 ) . "\n", 'x' );
		$this->assertTrue( mb_check_encoding( $entries[0]['message'], 'UTF-8' ) );
	}
}
