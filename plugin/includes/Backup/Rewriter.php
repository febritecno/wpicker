<?php
/**
 * Rewriter — replaces a site's URLs and paths inside database values.
 *
 * WordPress stores many values PHP-serialized, where each string carries its
 * byte length: s:20:"http://example.com/";. A plain search-and-replace that
 * changes the length corrupts the value. Serialized values are therefore
 * walked and re-emitted with corrected lengths.
 *
 * The walk is done on the text itself. Values from a backup are untrusted
 * input, and unserialize() on them could instantiate arbitrary classes.
 *
 * Mirrors cli/internal/migrate/phpserial.go — keep the two in step.
 *
 * @package WPicker\Backup
 */

declare( strict_types = 1 );

namespace WPicker\Backup;

defined( 'ABSPATH' ) || exit;

/**
 * Class Rewriter
 */
final class Rewriter {

	/**
	 * Replacement pairs: old => new, longest keys first (see strtr()).
	 *
	 * @var array<string,string>
	 */
	private $pairs = array();

	/**
	 * Substrings whose presence means a value needs rewriting.
	 *
	 * @var array<int,string>
	 */
	private $needles = array();

	/**
	 * Input being parsed.
	 *
	 * @var string
	 */
	private $in = '';

	/**
	 * Read position in $in.
	 *
	 * @var int
	 */
	private $pos = 0;

	/**
	 * One pattern matching every old spelling, longest first.
	 *
	 * @var string
	 */
	private $pattern = '';

	/**
	 * Constructor.
	 *
	 * @param array<int,string> $old_urls    Source site URLs (site URL, home URL).
	 * @param string            $new_url     This site's URL.
	 * @param string            $old_path    Source ABSPATH, no trailing slash.
	 * @param string            $new_path    This site's ABSPATH, no trailing slash.
	 * @param string            $old_content Source wp-content directory, if it is not simply under ABSPATH.
	 * @param string            $new_content This site's wp-content directory.
	 */
	public function __construct( array $old_urls, string $new_url, string $old_path, string $new_path, string $old_content = '', string $new_content = '' ) {
		$new_url  = untrailingslashit( $new_url );
		$new_host = self::strip_scheme( $new_url );
		$json     = static function ( string $s ): string {
			return str_replace( '/', '\\/', $s );
		};

		// A site is often linked both with and without "www.".
		$old_hosts = array();
		foreach ( $old_urls as $url ) {
			$host = self::strip_scheme( $url );
			if ( '' === $host ) {
				continue;
			}
			$old_hosts[] = $host;
			$old_hosts[] = 0 === stripos( $host, 'www.' ) ? substr( $host, 4 ) : 'www.' . $host;
		}

		foreach ( array_unique( $old_hosts ) as $old_host ) {
			foreach ( array( 'https://', 'http://' ) as $scheme ) {
				$this->pair( $scheme . $old_host, $new_url );
				$this->pair( $json( $scheme . $old_host ), $json( $new_url ) );
				$this->pair( urlencode( $scheme . $old_host ), urlencode( $new_url ) );
				$this->pair( rawurlencode( $scheme . $old_host ), rawurlencode( $new_url ) );
			}
			$this->pair( '//' . $old_host, '//' . $new_host );
			$this->pair( $json( '//' . $old_host ), $json( '//' . $new_host ) );
			// Scheme-less attribute values: href="example.com/page".
			$this->pair( '="' . $old_host, '="' . $new_host );
			$this->pair( "='" . $old_host, "='" . $new_host );
			$this->needles[] = $old_host;
			$this->needles[] = $json( $old_host );
			$this->needles[] = urlencode( $old_host );
		}

		$paths = array( array( $old_path, $new_path ) );
		// wp-content can live outside the install directory.
		if ( '' !== $old_content && $old_content !== $old_path . '/wp-content' ) {
			$paths[] = array( $old_content, $new_content );
		}
		foreach ( $paths as $pair ) {
			list( $from, $to ) = $pair;
			if ( '' === $from || $from === $to ) {
				continue;
			}
			$this->pair( $from, $to );
			$this->pair( $json( $from ), $json( $to ) );
			$this->pair( urlencode( $from ), urlencode( $to ) );
			$this->needles[] = $from;
			$this->needles[] = $json( $from );
			$this->needles[] = urlencode( $from );
		}
		$this->needles = array_values( array_unique( $this->needles ) );

		// Longest first, so "https://host" wins over "//host" at the same spot.
		$keys = array_keys( $this->pairs );
		usort(
			$keys,
			static function ( string $a, string $b ): int {
				return strlen( $b ) <=> strlen( $a );
			}
		);
		if ( array() !== $keys ) {
			// The match must END where the old address ends. Without this
			// boundary, a site at example.com would also rewrite links to
			// example.com.au or example.community — other people's sites.
			// Not followed by a name character, nor by a dot that continues
			// into a longer name.
			$this->pattern = '~(?:' . implode( '|', array_map( static function ( string $k ): string {
				return preg_quote( $k, '~' );
			}, $keys ) ) . ')(?![A-Za-z0-9_\-]|\.[A-Za-z0-9])~';
		}
	}

	/**
	 * Is there anything to replace at all?
	 *
	 * @return bool
	 */
	public function needed(): bool {
		return array() !== $this->pairs;
	}

	/**
	 * Substrings to search the database for.
	 *
	 * @return array<int,string>
	 */
	public function needles(): array {
		return $this->needles;
	}

	/**
	 * Rewrite one database value.
	 *
	 * @param string $value Value.
	 * @return string
	 */
	public function value( string $value ): string {
		if ( self::looks_serialized( $value ) ) {
			$saved     = array( $this->in, $this->pos );
			$this->in  = $value;
			$this->pos = 0;
			$out       = $this->parse();
			$complete  = null !== $out && strlen( $value ) === $this->pos;
			list( $this->in, $this->pos ) = $saved;
			if ( $complete ) {
				return $out;
			}
		}
		return $this->plain( $value );
	}

	/**
	 * Replace every old spelling in plain text, in a single pass — so a new
	 * URL that contains the old one (example.com → dev.example.com) is not
	 * replaced twice.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function plain( string $text ): string {
		if ( '' === $this->pattern ) {
			return $text;
		}
		$pairs = $this->pairs;
		$out   = preg_replace_callback(
			$this->pattern,
			static function ( array $m ) use ( $pairs ): string {
				return $pairs[ $m[0] ];
			},
			$text
		);
		// preg fails on pathological input (backtrack limit); never lose data.
		return null === $out ? $text : $out;
	}

	/**
	 * Register one replacement.
	 *
	 * @param string $from Search.
	 * @param string $to   Replacement.
	 * @return void
	 */
	private function pair( string $from, string $to ): void {
		if ( '' !== $from && $from !== $to ) {
			$this->pairs[ $from ] = $to;
		}
	}

	/**
	 * Cheap test before attempting a parse.
	 *
	 * @param string $s Value.
	 * @return bool
	 */
	private static function looks_serialized( string $s ): bool {
		if ( 'N;' === $s ) {
			return true;
		}
		return strlen( $s ) >= 4 && ':' === $s[1] && false !== strpos( 'aOsbidCE', $s[0] );
	}

	/**
	 * Consume one serialized value at the current position.
	 *
	 * @return string|null The rewritten value, or null if the input is malformed.
	 */
	private function parse(): ?string {
		if ( $this->pos >= strlen( $this->in ) ) {
			return null;
		}
		switch ( $this->in[ $this->pos ] ) {
			case 'N':
				return $this->skip( 'N;' ) ? 'N;' : null;

			case 'b':
			case 'i':
			case 'd':
			case 'r':
			case 'R':
				$end = strpos( $this->in, ';', $this->pos );
				if ( false === $end || ':' !== ( $this->in[ $this->pos + 1 ] ?? '' ) ) {
					return null;
				}
				$scalar    = substr( $this->in, $this->pos, $end - $this->pos + 1 );
				$this->pos = $end + 1;
				return $scalar;

			case 's':
				++$this->pos;
				$raw = $this->counted();
				if ( null === $raw || ! $this->skip( ';' ) ) {
					return null;
				}
				// A string may itself hold serialized data (WordPress does this).
				$new = $this->value( $raw );
				return 's:' . strlen( $new ) . ':"' . $new . '";';

			case 'E':
				++$this->pos;
				$raw = $this->counted();
				if ( null === $raw || ! $this->skip( ';' ) ) {
					return null;
				}
				return 'E:' . strlen( $raw ) . ':"' . $raw . '";';

			case 'a':
				++$this->pos;
				return $this->skip( ':' ) ? $this->members( 'a:' ) : null;

			case 'O':
				++$this->pos;
				$class = $this->counted();
				if ( null === $class || ! $this->skip( ':' ) ) {
					return null;
				}
				return $this->members( 'O:' . strlen( $class ) . ':"' . $class . '":' );

			case 'C':
				// Custom-serialized object: its payload has a format of its
				// own, so it is copied untouched.
				++$this->pos;
				$class = $this->counted();
				if ( null === $class || ! $this->skip( ':' ) ) {
					return null;
				}
				$n = $this->number( ':' );
				if ( null === $n || ! $this->skip( '{' ) || $this->pos + $n > strlen( $this->in ) ) {
					return null;
				}
				$payload    = substr( $this->in, $this->pos, $n );
				$this->pos += $n;
				if ( ! $this->skip( '}' ) ) {
					return null;
				}
				return 'C:' . strlen( $class ) . ':"' . $class . '":' . $n . ':{' . $payload . '}';
		}
		return null;
	}

	/**
	 * Consume COUNT:{ key value ... }.
	 *
	 * @param string $head Already-decided prefix ("a:" or "O:len:"class":").
	 * @return string|null
	 */
	private function members( string $head ): ?string {
		$n = $this->number( ':' );
		if ( null === $n || ! $this->skip( '{' ) ) {
			return null;
		}
		$out = $head . $n . ':{';
		for ( $i = 0; $i < $n * 2; $i++ ) {
			$part = $this->parse();
			if ( null === $part ) {
				return null;
			}
			$out .= $part;
		}
		return $this->skip( '}' ) ? $out . '}' : null;
	}

	/**
	 * Consume :LEN:"BYTES" and return BYTES.
	 *
	 * @return string|null
	 */
	private function counted(): ?string {
		if ( ! $this->skip( ':' ) ) {
			return null;
		}
		$n = $this->number( ':' );
		if ( null === $n || ! $this->skip( '"' ) || $this->pos + $n > strlen( $this->in ) ) {
			return null;
		}
		$raw        = substr( $this->in, $this->pos, $n );
		$this->pos += $n;
		return $this->skip( '"' ) ? $raw : null;
	}

	/**
	 * Consume digits followed by a terminator.
	 *
	 * @param string $term Terminator character.
	 * @return int|null
	 */
	private function number( string $term ): ?int {
		$len = strspn( $this->in, '0123456789', $this->pos );
		if ( 0 === $len || $len > 18 || ( $this->in[ $this->pos + $len ] ?? '' ) !== $term ) {
			return null;
		}
		$n          = (int) substr( $this->in, $this->pos, $len );
		$this->pos += $len + 1;
		return $n;
	}

	/**
	 * Consume a literal.
	 *
	 * @param string $s Literal.
	 * @return bool
	 */
	private function skip( string $s ): bool {
		if ( 0 !== substr_compare( $this->in, $s, $this->pos, strlen( $s ) ) ) {
			return false;
		}
		$this->pos += strlen( $s );
		return true;
	}

	/**
	 * "https://example.com/" → "example.com".
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function strip_scheme( string $url ): string {
		$url = rtrim( $url, '/' );
		$at  = strpos( $url, '://' );
		return false === $at ? $url : substr( $url, $at + 3 );
	}
}
