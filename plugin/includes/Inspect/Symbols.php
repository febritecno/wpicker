<?php
/**
 * Symbols — an index of what the site's PHP code defines and hooks into.
 *
 * Answers "where is X defined / who hooks Y" without grepping the whole
 * tree: functions, classes and methods, hooks (added and fired), shortcodes,
 * REST routes, post types, taxonomies, options and constants, each with the
 * file and line.
 *
 * Files are tokenised (not pattern-matched), so strings and comments never
 * produce false hits. Rows are cached per file keyed by modification time and
 * size, so after the first scan only changed files are read again.
 *
 * Read-only with respect to the site; the only thing written is the cache,
 * inside this plugin's own storage directory.
 *
 * @package WPicker\Inspect
 */

declare( strict_types = 1 );

namespace WPicker\Inspect;

defined( 'ABSPATH' ) || exit;

/**
 * Class Symbols
 */
final class Symbols {

	public const KINDS = array( 'function', 'class', 'method', 'hook', 'shortcode', 'rest', 'post_type', 'taxonomy', 'option', 'constant' );

	private const MAX_FILE   = 1048576;
	private const MAX_CACHE  = 20971520;
	private const MAX_ROWS   = 500;
	private const DEADLINE_S = 8.0;

	/**
	 * Calls whose first argument names something worth indexing:
	 * function => array( kind, detail ).
	 */
	private const CALLS = array(
		'add_action'                => array( 'hook', 'listens' ),
		'add_filter'                => array( 'hook', 'listens' ),
		'remove_action'             => array( 'hook', 'removes' ),
		'remove_filter'             => array( 'hook', 'removes' ),
		'do_action'                 => array( 'hook', 'fires' ),
		'do_action_ref_array'       => array( 'hook', 'fires' ),
		'apply_filters'             => array( 'hook', 'fires' ),
		'apply_filters_ref_array'   => array( 'hook', 'fires' ),
		'add_shortcode'             => array( 'shortcode', '' ),
		'register_post_type'        => array( 'post_type', '' ),
		'register_taxonomy'         => array( 'taxonomy', '' ),
		'get_option'                => array( 'option', 'reads' ),
		'update_option'             => array( 'option', 'writes' ),
		'add_option'                => array( 'option', 'writes' ),
		'delete_option'             => array( 'option', 'deletes' ),
		'define'                    => array( 'constant', '' ),
	);

	/**
	 * Extract symbols from PHP source. Pure: takes code, not a path.
	 *
	 * @param string $code PHP source.
	 * @return array<int,array{0:string,1:string,2:int,3:string}> Rows of kind, name, line, detail.
	 */
	public static function extract( string $code ): array {
		if ( false === strpos( $code, '<?' ) ) {
			return array();
		}
		$tokens = @token_get_all( $code );
		// PHP 8 lexes \add_action as one T_NAME_FULLY_QUALIFIED token, PHP 7 as
		// T_NS_SEPARATOR + T_STRING; enum and ?-> only exist on 8.
		$fq       = defined( 'T_NAME_FULLY_QUALIFIED' ) ? (int) constant( 'T_NAME_FULLY_QUALIFIED' ) : -1;
		$enum     = defined( 'T_ENUM' ) ? (int) constant( 'T_ENUM' ) : -1;
		$nullsafe = defined( 'T_NULLSAFE_OBJECT_OPERATOR' ) ? (int) constant( 'T_NULLSAFE_OBJECT_OPERATOR' ) : -1;
		$no_call  = array( T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, $nullsafe );
		$rows   = array();
		$count  = count( $tokens );
		$depth  = 0;
		$class  = null; // array( name, depth at which it was opened ).
		$pending_class = null;

		$next = static function ( int $i ) use ( $tokens, $count ): ?int {
			for ( $j = $i + 1; $j < $count; $j++ ) {
				$t = $tokens[ $j ];
				if ( is_array( $t ) && in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
					continue;
				}
				return $j;
			}
			return null;
		};
		// A string literal's value, or null. Only the escapes PHP itself
		// applies are undone, so a regex such as "\d+" stays intact.
		$literal = static function ( $t ): ?string {
			if ( ! is_array( $t ) || T_CONSTANT_ENCAPSED_STRING !== $t[0] ) {
				return null;
			}
			$body = substr( $t[1], 1, -1 );
			if ( "'" === $t[1][0] ) {
				return str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $body );
			}
			return str_replace( array( '\\"', '\\\\' ), array( '"', '\\' ), $body );
		};

		for ( $i = 0; $i < $count; $i++ ) {
			$t = $tokens[ $i ];
			if ( ! is_array( $t ) ) {
				if ( '{' === $t ) {
					++$depth;
					if ( null !== $pending_class ) {
						$class         = array( $pending_class, $depth );
						$pending_class = null;
					}
				} elseif ( '}' === $t ) {
					if ( null !== $class && $depth === $class[1] ) {
						$class = null;
					}
					--$depth;
				}
				continue;
			}
			if ( T_CURLY_OPEN === $t[0] || T_DOLLAR_OPEN_CURLY_BRACES === $t[0] ) {
				++$depth; // Closed by a plain "}".
				continue;
			}

			$line = (int) $t[2];

			if ( in_array( $t[0], array( T_CLASS, T_INTERFACE, T_TRAIT, $enum ), true ) ) {
				$prev = $i - 1;
				while ( $prev >= 0 && is_array( $tokens[ $prev ] ) && T_WHITESPACE === $tokens[ $prev ][0] ) {
					--$prev;
				}
				if ( $prev >= 0 && is_array( $tokens[ $prev ] ) && T_DOUBLE_COLON === $tokens[ $prev ][0] ) {
					continue; // Foo::class
				}
				$n = $next( $i );
				if ( null !== $n && is_array( $tokens[ $n ] ) && T_STRING === $tokens[ $n ][0] ) {
					$name = $tokens[ $n ][1];
					$detail = T_INTERFACE === $t[0] ? 'interface' : ( T_TRAIT === $t[0] ? 'trait' : ( $enum === $t[0] ? 'enum' : 'class' ) );
					$rows[] = array( 'class', $name, $line, $detail );
					$pending_class = $name;
				}
				continue;
			}

			if ( T_FUNCTION === $t[0] ) {
				$n = $next( $i );
				// "function &name": the & is a plain string on PHP 7 to 8.0 and an array token from 8.1.
				if ( null !== $n && ( '&' === $tokens[ $n ] || ( is_array( $tokens[ $n ] ) && '&' === $tokens[ $n ][1] ) ) ) {
					$n = $next( $n );
				}
				if ( null !== $n && is_array( $tokens[ $n ] ) && T_STRING === $tokens[ $n ][0] ) {
					$name = $tokens[ $n ][1];
					if ( null !== $class ) {
						$rows[] = array( 'method', $class[0] . '::' . $name, $line, '' );
					} else {
						$rows[] = array( 'function', $name, $line, '' );
					}
				}
				continue;
			}

			if ( T_STRING === $t[0] || $fq === $t[0] ) {
				$call = strtolower( ltrim( $t[1], '\\' ) );
				if ( 'register_rest_route' === $call || isset( self::CALLS[ $call ] ) ) {
					$open = $next( $i );
					if ( null === $open || '(' !== $tokens[ $open ] ) {
						continue;
					}
					// A method or static call of the same name is not the WordPress function.
					$prev = $i - 1;
					while ( $prev >= 0 && is_array( $tokens[ $prev ] ) && T_WHITESPACE === $tokens[ $prev ][0] ) {
						--$prev;
					}
					if ( $prev >= 0 && is_array( $tokens[ $prev ] ) && in_array( $tokens[ $prev ][0], $no_call, true ) ) {
						continue;
					}
					$arg = $next( $open );
					if ( null === $arg ) {
						continue;
					}
					$first = $literal( $tokens[ $arg ] );
					if ( null !== $first ) {
						// 'a_' . $x is a dynamic name: skip it rather than index a fragment.
						$after = $next( $arg );
						if ( null === $after || ! in_array( $tokens[ $after ], array( ',', ')' ), true ) ) {
							continue;
						}
					}
					if ( 'register_rest_route' === $call ) {
						$comma = $next( $arg );
						$route = null !== $comma && ',' === $tokens[ $comma ] ? $next( $comma ) : null;
						$path  = null !== $route ? $literal( $tokens[ $route ] ) : null;
						if ( null !== $first && null !== $path ) {
							$rows[] = array( 'rest', '/' . trim( $first, '/' ) . '/' . ltrim( $path, '/' ), $line, '' );
						}
						continue;
					}
					if ( null !== $first && '' !== $first ) {
						$rows[] = array( self::CALLS[ $call ][0], $first, $line, self::CALLS[ $call ][1] );
					}
				}
			}
		}
		return $rows;
	}

	/**
	 * Search the index.
	 *
	 * @param array<string,mixed> $args q (substring, case-insensitive), kind
	 *                                  (comma list), in (scope), limit,
	 *                                  vendor (bool).
	 * @return array<string,mixed>
	 */
	public static function search( array $args ): array {
		$q      = strtolower( trim( (string) ( $args['q'] ?? '' ) ) );
		$kinds  = array_values( array_intersect( self::KINDS, array_map( 'trim', explode( ',', strtolower( (string) ( $args['kind'] ?? '' ) ) ) ) ) );
		$scope  = (string) ( $args['in'] ?? 'custom' );
		$limit  = max( 1, min( self::MAX_ROWS, (int) ( $args['limit'] ?? 50 ) ) );
		$vendor = ! empty( $args['vendor'] );

		$started = microtime( true );
		$roots   = self::roots( $scope );
		$cache   = self::load_cache();
		$dirty   = false;
		$seen    = array();
		$files   = 0;
		$parsed  = 0;
		$hits    = array();
		$skipped = array();
		$total   = 0;
		$done    = true;

		foreach ( $roots as $root_rel ) {
			$base = Files::resolve( $root_rel );
			if ( null === $base || ! is_dir( $base ) ) {
				// Say so: "0 files" would read as "an empty plugin".
				$skipped[] = array(
					'path'   => $root_rel,
					'reason' => Files::why_unavailable( $root_rel ),
				);
				continue;
			}
			foreach ( self::php_files( $base, $vendor ) as $path ) {
				if ( microtime( true ) - $started > self::DEADLINE_S ) {
					$done = false;
					break 2;
				}
				$rel = $root_rel . substr( $path, strlen( $base ) );
				++$files;
				$seen[ $rel ] = true;
				$stat         = @stat( $path );
				if ( false === $stat || $stat['size'] > self::MAX_FILE ) {
					continue;
				}
				$entry = $cache[ $rel ] ?? null;
				if ( null === $entry || $entry[0] !== $stat['mtime'] || $entry[1] !== $stat['size'] ) {
					$code  = (string) @file_get_contents( $path );
					$entry = array( $stat['mtime'], $stat['size'], self::extract( $code ) );
					$cache[ $rel ] = $entry;
					$dirty         = true;
					++$parsed;
				}
				foreach ( $entry[2] as $row ) {
					if ( array() !== $kinds && ! in_array( $row[0], $kinds, true ) ) {
						continue;
					}
					if ( '' !== $q && false === strpos( strtolower( $row[1] ), $q ) ) {
						continue;
					}
					++$total;
					if ( count( $hits ) < $limit ) {
						$hits[] = array(
							'kind'   => $row[0],
							'name'   => $row[1],
							'file'   => 'wp-content/' . $rel,
							'line'   => $row[2],
							'detail' => $row[3],
						);
					}
				}
			}
		}

		if ( $dirty && $done ) {
			// Drop entries of files that no longer exist, within the scanned roots.
			foreach ( array_keys( $cache ) as $rel ) {
				foreach ( $roots as $root_rel ) {
					if ( 0 === strpos( $rel, $root_rel . '/' ) && ! isset( $seen[ $rel ] ) ) {
						unset( $cache[ $rel ] );
					}
				}
			}
		}
		if ( $dirty ) {
			self::save_cache( $cache );
		}

		return array(
			'results'  => $hits,
			'count'    => count( $hits ),
			'total'    => $total,
			'files'    => $files,
			'parsed'   => $parsed,
			'complete' => $done,
			'skipped'  => $skipped,
			'scope'    => $scope,
			'ms'       => (int) round( ( microtime( true ) - $started ) * 1000 ),
		);
	}

	/**
	 * Directories (relative to wp-content) a scope covers.
	 *
	 * `custom` (default) = mu-plugins, themes and plugins that do not come from
	 * wordpress.org — where a site's own bugs live. `all` = everything.
	 * Otherwise a plugin slug, `theme:<slug>`, `plugins`, `themes` or `mu`.
	 *
	 * @param string $scope Scope.
	 * @return array<int,string>
	 */
	public static function roots( string $scope ): array {
		$plugins = array();
		$dirs    = (array) glob( WP_PLUGIN_DIR . '/*', GLOB_ONLYDIR );
		foreach ( $dirs as $dir ) {
			$plugins[] = 'plugins/' . basename( $dir );
		}
		$themes = array();
		foreach ( (array) glob( get_theme_root() . '/*', GLOB_ONLYDIR ) as $dir ) {
			$themes[] = 'themes/' . basename( $dir );
		}
		switch ( $scope ) {
			case 'all':
				return array_merge( array( 'mu-plugins' ), $plugins, $themes );
			case 'plugins':
				return $plugins;
			case 'themes':
				return $themes;
			case 'mu':
				return array( 'mu-plugins' );
			case 'custom':
				$custom = array( 'mu-plugins' );
				foreach ( $plugins as $rel ) {
					if ( ! Map::from_wordpress_org( basename( $rel ) ) ) {
						$custom[] = $rel;
					}
				}
				foreach ( $themes as $rel ) {
					if ( ! Map::theme_from_wordpress_org( basename( $rel ) ) ) {
						$custom[] = $rel;
					}
				}
				return $custom;
		}
		if ( 0 === strpos( $scope, 'theme:' ) ) {
			$slug = basename( substr( $scope, 6 ) );
			return in_array( 'themes/' . $slug, $themes, true ) ? array( 'themes/' . $slug ) : array();
		}
		$slug = basename( $scope );
		return in_array( 'plugins/' . $slug, $plugins, true ) ? array( 'plugins/' . $slug ) : array();
	}

	/**
	 * PHP files below a directory, skipping vendored and non-code folders.
	 *
	 * @param string $base   Absolute directory.
	 * @param bool   $vendor Include vendor/ and tests/.
	 * @return \Generator<int,string>
	 */
	private static function php_files( string $base, bool $vendor ): \Generator {
		$skip  = array( 'node_modules', '.git', 'wpicker-vault', 'cache', 'upgrade' );
		$skip  = $vendor ? $skip : array_merge( $skip, array( 'vendor', 'tests', 'test', 'languages', 'assets' ) );
		$stack = array( $base );
		while ( array() !== $stack ) {
			$dir   = array_pop( $stack );
			$names = @scandir( $dir );
			if ( false === $names ) {
				continue;
			}
			sort( $names );
			foreach ( $names as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				$path = $dir . '/' . $name;
				if ( is_link( $path ) ) {
					continue;
				}
				if ( is_dir( $path ) ) {
					if ( ! in_array( strtolower( $name ), $skip, true ) ) {
						$stack[] = $path;
					}
				} elseif ( '.php' === strtolower( substr( $name, -4 ) ) && null !== Files::resolve( ltrim( substr( $path, strlen( (string) realpath( WP_CONTENT_DIR ) ) ), '/' ) ) ) {
					yield $path;
				}
			}
		}
	}

	/**
	 * Cache file. The name carries a random token for the same reason the
	 * private log's does.
	 *
	 * @return string
	 */
	private static function cache_path(): string {
		$token = (string) get_option( 'wpicker_index_token', '' );
		if ( '' === $token ) {
			$token = bin2hex( random_bytes( 16 ) );
			update_option( 'wpicker_index_token', $token, false );
		}
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . 'wpicker-vault/index';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			@file_put_contents( $dir . '/index.html', '' );
		}
		return $dir . '/symbols-' . $token . '.json';
	}

	/**
	 * @return array<string,array>
	 */
	private static function load_cache(): array {
		$path = self::cache_path();
		if ( ! is_file( $path ) || filesize( $path ) > self::MAX_CACHE ) {
			return array();
		}
		$data = json_decode( (string) file_get_contents( $path ), true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * @param array<string,array> $cache Cache.
	 * @return void
	 */
	private static function save_cache( array $cache ): void {
		$json = wp_json_encode( $cache );
		if ( ! is_string( $json ) || strlen( $json ) > self::MAX_CACHE ) {
			return; // Too big to keep: next scan just parses again.
		}
		$path = self::cache_path();
		$tmp  = $path . '.' . bin2hex( random_bytes( 4 ) ) . '.tmp';
		if ( false !== @file_put_contents( $tmp, $json, LOCK_EX ) ) {
			@rename( $tmp, $path );
		}
	}
}
