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
	public const NAME = 'pass|secret|token|api_?key|apikey|private_?key|auth_?key|recovery_?key|_salt|signature|credential';

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
	public static function scrub( string $text ): string {
		if ( '' === $text || 1 !== preg_match( '/' . self::NAME . '/i', $text ) ) {
			return $text;
		}
		$name = '[^"]{0,60}(?:' . self::NAME . ')[^"]{0,60}';
		$mask = self::MASK;

		$out = self::scrub_serialized( $text, $name, $mask );

		// "api_key":"abc"  →  "api_key":"[redacted]"
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
