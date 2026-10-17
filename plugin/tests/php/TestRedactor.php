<?php

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use WPicker\Inspect\Redactor;

final class TestRedactor extends TestCase {

	/**
	 * A Stripe-shaped key, assembled here so the repository never holds one whole.
	 */
	private static function stripe( string $kind, string $mode ): string {
		return $kind . '_' . $mode . '_' . '4eC39HqLyjWDarjtT1zdp7dc';
	}

	public function test_masks_stripe_keys_without_a_label(): void {
		$out = Redactor::scrub( 'Request failed using ' . self::stripe( 'sk', 'live' ) . ' and ' . self::stripe( 'pk', 'test' ) );
		$this->assertStringNotContainsString( 'sk_live_', $out );
		$this->assertStringNotContainsString( 'pk_test_', $out );
		$this->assertStringContainsString( Redactor::MASK, $out );
	}

	public function test_masks_webhook_and_client_secrets(): void {
		$out = Redactor::scrub( 'whsec' . '_abcdefghij1234567890 and pi_3Abc123' . '_secret_XyZ987abc' );
		$this->assertStringNotContainsString( 'whsec_', $out );
		$this->assertStringNotContainsString( '_secret_', $out );
	}

	public function test_masks_bearer_and_jwt(): void {
		$out = Redactor::scrub( 'Authorization: Bearer abcDEF123456789 jwt ' . 'eyJhbGciOiJIUzI1NiJ9' . '.eyJzdWIiOiIxMjMifQ.sig_nature-1' );
		$this->assertStringNotContainsString( 'abcDEF123456789', $out );
		$this->assertStringNotContainsString( 'eyJhbGci', $out );
	}

	public function test_masks_a_card_number_that_passes_luhn(): void {
		$this->assertSame( 'card [redacted] declined', Redactor::scrub( 'card 4242 4242 4242 4242 declined' ) );
		$this->assertSame( 'card [redacted] declined', Redactor::scrub( 'card 4242-4242-4242-4242 declined' ) );
		$this->assertSame( 'pan [redacted]', Redactor::scrub( 'pan 5555555555554444' ) );
	}

	public function test_keeps_order_ids_and_timestamps_readable(): void {
		$text = 'Order #123456 failed at 1767225600 amount 1999 request 4242424242424241';
		$this->assertSame( $text, Redactor::scrub( $text ) );
	}

	public function test_luhn(): void {
		$this->assertTrue( Redactor::luhn( '4242424242424242' ) );
		$this->assertFalse( Redactor::luhn( '4242424242424241' ) );
		$this->assertFalse( Redactor::luhn( '12345' ) );
	}

	public function test_named_secrets_still_masked(): void {
		$this->assertStringNotContainsString( 'hunter2hunter2', Redactor::scrub( 'password=hunter2hunter2' ) );
		$this->assertStringNotContainsString( '123', Redactor::scrub( 'cvc: 123' ) );
	}

	public function test_plain_text_is_untouched(): void {
		$this->assertSame( 'Undefined variable $x', Redactor::scrub( 'Undefined variable $x' ) );
	}

	public function test_print_r_dumps_are_masked(): void {
		$out = Redactor::scrub( "[api_key] => abcd1234secretvalue\n[password] => hunter22\n[cvc] => 123\n[amount] => 1999" );
		$this->assertStringNotContainsString( 'abcd1234secretvalue', $out );
		$this->assertStringNotContainsString( 'hunter22', $out );
		$this->assertStringNotContainsString( '=> 123', $out );
		$this->assertStringContainsString( '[amount] => 1999', $out );
	}

	public function test_var_export_dumps_are_masked(): void {
		$out = Redactor::scrub( "'client_secret' => 'zzzzzzzzzzzz', 'currency' => 'usd'" );
		$this->assertStringNotContainsString( 'zzzzzzzzzzzz', $out );
		$this->assertStringContainsString( "'currency' => 'usd'", $out );
	}

	public function test_hyphenated_header_names_are_masked(): void {
		$this->assertStringNotContainsString( 'abcd1234secretvalue', Redactor::scrub( 'x-api-key=abcd1234secretvalue' ) );
	}

	public function test_unterminated_json_string_is_masked(): void {
		$this->assertStringNotContainsString( 'abcxxxxxxxxxx', Redactor::scrub( '{"secret_key":"abcxxxxxxxxxx' ) );
	}
}
