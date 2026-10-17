<?php
/**
 * Redactor — what counts as a credential, in one place.
 *
 * Diagnostic output (log lines, database cells) leaves the server and ends
 * up in front of a person or an AI model. Anything that would let someone
 * act as the site or its users is removed first.
 *
 * This is pattern matching on names, so it is best effort: a plugin that
 * keeps a secret under an innocent name is not caught. The database guard
 * (Database::guard()) does the structural part — core credential columns and
 * rows are excluded inside the query itself, not here.
 *
 * @package WPicker\Inspect
 */

declare( strict_types = 1 );

namespace WPicker\Inspect;

defined( 'ABSPATH' ) || exit;

/**
 * Class Redactor
 */
final class Redactor {

	/**
	 * Marker written in place of a removed value.
	 */
	public const MASK = '[redacted]';

	/**
	 * A name (column, option, array key, variable) that holds a credential.
	 * Used as a PCRE fragment and as a MySQL REGEXP, so it sticks to syntax
	 * both understand (no groups).
	 */
	public const NAME = 'pass|secret|token|api[_-]?key|apikey|private_?key|auth_?key|recovery_?key|_salt|signature|credential|cvc|cvv|card_?number|authorization|iban';

	/**
	 * Does this name look like it holds a credential?
	 *
	 * @param string $name Column, option or key name.
	 * @return bool
	 */
	public static function sensitive_name( string $name ): bool {
		return 1 === preg_match( '/' . self::NAME . '/i', $name );
	}

	/**
	 * Remove credentials embedded in a piece of text: values of
	 * credential-named keys in PHP-serialized data, in JSON, and in
	 * key=value / key: value pairs.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	/**
	 * Secrets that look like secrets whatever they are called: payment keys,
	 * bearer tokens, JWTs. Matched by shape, so they are caught inside free
	 * text where no "key =" label gives them away.
	 */
	private const VALUE_PATTERNS = array(
		'/\b(?:sk|rk|pk)_(?:live|test)_[A-Za-z0-9]{10,}/',
		'/\bwhsec_[A-Za-z0-9]{10,}/',
		'/\b(?:pi|seti)_[A-Za-z0-9]+_secret_[A-Za-z0-9]+/',
		'/\bA21AA[A-Za-z0-9_-]{20,}/',
		'/\bBearer\s+[A-Za-z0-9._~+\/=-]{8,}/i',
		'/\bBasic\s+[A-Za-z0-9+\/=]{12,}/',
		'/\beyJ[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]{5,}\.[A-Za-z0-9_-]*/',
	);

	/**
	 * Mask secrets recognisable by their value: see VALUE_PATTERNS, plus card
	 * numbers (13–19 digits that pass the Luhn check — order IDs and
	 * timestamps usually do not, and must stay readable).
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function scrub_values( string $text ): string {
		if ( '' === $text ) {
			return $text;
		}
		foreach ( self::VALUE_PATTERNS as $pattern ) {
			$out  = preg_replace( $pattern, self::MASK, $text );
			$text = null === $out ? $text : $out;
		}
		// A card security code is only 3–4 digits, below the length the generic
		// "name = value" rule needs before it masks anything. Keep the label.
		$cv   = preg_replace( '/\b((?:cvc|cvv)2?\w*["\']?\s*[=:]\s*["\']?)\d{3,4}\b/i', '$1' . self::MASK, $text );
		$text = null === $cv ? $text : $cv;
		$out  = preg_replace_callback(
			'/(?<![\d])\d(?:[ -]?\d){12,18}(?![\d])/',
			static function ( array $m ): string {
				$digits = preg_replace( '/\D/', '', $m[0] );
				return self::luhn( (string) $digits ) ? self::MASK : $m[0];
			},
			$text
		);
		return null === $out ? $text : $out;
	}

	/**
	 * Luhn checksum.
	 *
	 * @param string $digits Digits only.
	 * @return bool
	 */
	public static function luhn( string $digits ): bool {
		$len = strlen( $digits );
		if ( $len < 13 || $len > 19 ) {
			return false;
		}
		$sum = 0;
		for ( $i = 0; $i < $len; $i++ ) {
			$d = (int) $digits[ $len - 1 - $i ];
			if ( 1 === $i % 2 ) {
				$d *= 2;
				if ( $d > 9 ) {
					$d -= 9;
				}
			}
			$sum += $d;
		}
		return 0 === $sum % 10;
	}

	public static function scrub( string $text ): string {
		// Value patterns first: they must not depend on a secret-looking name
		// being present in the text.
		$text = self::scrub_values( $text );
		if ( '' === $text || 1 !== preg_match( '/' . self::NAME . '/i', $text ) ) {
			return $text;
		}
		$name = '[^"]{0,60}(?:' . self::NAME . ')[^"]{0,60}';
		$mask = self::MASK;

		$out = self::scrub_serialized( $text, $name, $mask );

		// "api_key":"abc"  →  "api_key":"[redacted]"
		// print_r() dumps:  [api_key] => value   (value runs to the end of the line)
		$dump = preg_replace( '/(\[[^\]\n]{0,60}(?:' . self::NAME . ')[^\]\n]{0,60}\]\s*=>\s*)[^\n]*/i', '$1' . $mask, $out );
		$out  = null === $dump ? $out : $dump;
		// var_export() dumps:  'api_key' => 'value'
		$export = preg_replace( '/(\'[^\'\n]{0,60}(?:' . self::NAME . ')[^\'\n]{0,60}\'\s*=>\s*)\'(?:[^\'\\\\]|\\\\.)*\'/i', '$1\'' . $mask . '\'', $out );
		$out    = null === $export ? $out : $export;

		// A JSON string value cut off before its closing quote: mask to the end of the line.
		$open = preg_replace( '/("' . $name . '"\s*:\s*)"(?:[^"\\\\\n]|\\\\.)*$/im', '$1"' . $mask . '"', $out );
		$out  = null === $open ? $out : $open;

		$json = preg_replace( '/("' . $name . '"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/i', '$1"' . $mask . '"', $out );
		$out  = null === $json ? $out : $json;

		// password=abc, token: abc
		$pair = preg_replace( '/\b((?:' . self::NAME . ')\w*\s*[=:]\s*)(?!\[redacted\])[^\s,;&"\']{4,}/i', '$1' . $mask, $out );
		return null === $pair ? $out : $pair;
	}

	/**
	 * Mask the values of credential-named keys in PHP-serialized text:
	 *   s:8:"password";s:6:"hunter";  →  s:8:"password";s:10:"[redacted]";
	 *
	 * The value is skipped by its announced byte length, not by pattern,
	 * because it may itself contain quotes and semicolons.
	 *
	 * @param string $text Text.
	 * @param string $name PCRE fragment matching a credential-named key.
	 * @param string $mask Replacement value.
	 * @return string
	 */
	private static function scrub_serialized( string $text, string $name, string $mask ): string {
		$pattern = '/s:\d+:"' . $name . '";s:(\d+):"/i';
		$out     = '';
		$pos     = 0;
		while ( 1 === preg_match( $pattern, $text, $m, PREG_OFFSET_CAPTURE, $pos ) ) {
			$start = $m[0][1];
			$value = $start + strlen( $m[0][0] );
			$len   = (int) $m[1][0];
			$end   = $value + $len;
			if ( $end > strlen( $text ) ) {
				break; // malformed: leave the rest alone
			}
			// Everything up to and including the key, then a masked value.
			$key_end = $value - strlen( 's:' . $m[1][0] . ':"' );
			$out    .= substr( $text, $pos, $key_end - $pos ) . 's:' . strlen( $mask ) . ':"' . $mask;
			$pos     = $end;
		}
		return $out . substr( $text, $pos );
	}
}
