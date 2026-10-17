<?php
/**
 * Files — read-only access to wp-content, for reading code while diagnosing.
 *
 * Confined to wp-content: wp-config.php and everything else above it is out
 * of reach by construction. Inside it, a few things are still withheld:
 * this plugin's storage (snapshots, backups, logs), other backup tools'
 * archives, and files that are credentials or database dumps by their name.
 *
 * @package WPicker\Inspect
 */

declare( strict_types = 1 );

namespace WPicker\Inspect;

defined( 'ABSPATH' ) || exit;

/**
 * Class Files
 */
final class Files {

	/**
	 * Largest file returned by read(), and searched by grep().
	 */
	private const MAX_READ = 524288;
	private const MAX_GREP = 1048576;

	/**
	 * Directory names withheld at any depth.
	 */
	private const DENIED_DIRS = array( 'wpicker-vault', 'ai1wm-backups', 'updraft', 'backups-dup-lite', 'backupbuddy_backups', 'wp-clone', '.git', '.ssh' );

	/**
	 * File names withheld: keys, credential files, dumps, logs.
	 */
	private const DENIED_FILE = '/^(\.env.*|\.htpasswd|\.netrc|id_(rsa|dsa|ecdsa|ed25519).*|wp-config.*\.php|.*\.(pem|key|p12|pfx|crt|sql|sql\.gz|sqlite|bak|log))$/i';

	/**
	 * Directories skipped when listing deeply or searching: large and not code.
	 */
	private const NOISE_DIRS = array( 'node_modules', 'cache', 'upgrade', 'upgrade-temp-backup' );

	/**
	 * Resolve a path relative to wp-content, refusing anything outside it or
	 * withheld.
	 *
	 * @param string $relative Path relative to wp-content ("" = wp-content itself).
	 * @return string|null Absolute path.
	 */
	public static function resolve( string $relative ): ?string {
		$relative = trim( str_replace( '\\', '/', $relative ), '/' );
		if ( false !== strpos( $relative, "\0" ) ) {
			return null;
		}
		$root = realpath( WP_CONTENT_DIR );
		if ( false === $root ) {
			return null;
		}
		$path = realpath( '' === $relative ? $root : $root . '/' . $relative );
		// realpath() resolves ".." and symbolic links, so this one prefix
		// test covers traversal and links that point elsewhere.
		if ( false === $path || ( $path !== $root && 0 !== strpos( $path, $root . DIRECTORY_SEPARATOR ) ) ) {
			return null;
		}
		return self::withheld( substr( $path, strlen( $root ) ) ) ? null : $path;
	}

	/**
	 * Why resolve() said no, in words an agent can act on. It never says where
	 * a link points: only that it leaves wp-content.
	 *
	 * @param string $relative Path relative to wp-content.
	 * @return string
	 */
	public static function why_unavailable( string $relative ): string {
		$relative = trim( str_replace( '\\', '/', $relative ), '/' );
		$root     = realpath( WP_CONTENT_DIR );
		if ( false === $root || false !== strpos( $relative, "\0" ) ) {
			return 'not available for reading';
		}
		$walk = '';
		foreach ( explode( '/', $relative ) as $part ) {
			if ( '' === $part ) {
				continue;
			}
			$walk .= '/' . $part;
			if ( is_link( $root . $walk ) ) {
				$real = realpath( $root . $walk );
				if ( false === $real || ( $real !== $root && 0 !== strpos( $real, $root . DIRECTORY_SEPARATOR ) ) ) {
					return ltrim( $walk, '/' ) . ' is a symbolic link that points outside wp-content; WPicker does not follow those; read the code from where it really lives';
				}
			}
		}
		// Only say "withheld" for something that really is inside wp-content:
		// a path that climbs out of it must look exactly like a missing one.
		$real = realpath( $root . '/' . $relative );
		if ( false !== $real && ( $real === $root || 0 === strpos( $real, $root . DIRECTORY_SEPARATOR ) ) ) {
			return 'withheld: credentials, backups, logs and WPicker\'s own storage are not readable';
		}
		return 'no such path under wp-content';
	}

	/**
	 * Is this path (relative to wp-content) one we do not hand out?
	 *
	 * @param string $relative Path below wp-content.
	 * @return bool
	 */
	private static function withheld( string $relative ): bool {
		$parts = array_values( array_filter( explode( '/', str_replace( DIRECTORY_SEPARATOR, '/', $relative ) ), 'strlen' ) );
		foreach ( $parts as $i => $part ) {
			if ( in_array( strtolower( $part ), self::DENIED_DIRS, true ) ) {
				return true;
			}
			if ( count( $parts ) - 1 === $i && 1 === preg_match( self::DENIED_FILE, $part ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * List a directory.
	 *
	 * @param string $relative Directory relative to wp-content.
	 * @param int    $depth    Levels to descend (1 = just this directory).
	 * @return array<string,mixed>|null Null if the path is not an allowed directory.
	 */
	public static function listing( string $relative, int $depth ): ?array {
		$base = self::resolve( $relative );
		if ( null === $base || ! is_dir( $base ) ) {
			return null;
		}
		$depth   = max( 1, min( 6, $depth ) );
		$root    = (string) realpath( WP_CONTENT_DIR );
		$entries = array();
		$capped  = false;

		$walk = static function ( string $dir, int $level ) use ( &$walk, &$entries, &$capped, $root, $depth ): void {
			$names = @scandir( $dir );
			if ( false === $names ) {
				return;
			}
			foreach ( $names as $name ) {
				if ( '.' === $name || '..' === $name ) {
					continue;
				}
				if ( count( $entries ) >= 2000 ) {
					$capped = true;
					return;
				}
				$path     = $dir . '/' . $name;
				$relative = ltrim( substr( $path, strlen( $root ) ), '/' );
				if ( self::withheld( '/' . $relative ) ) {
					continue;
				}
				$is_dir    = is_dir( $path ) && ! is_link( $path );
				$entries[] = array(
					'path'     => $relative,
					'type'     => is_link( $path ) ? 'link' : ( $is_dir ? 'dir' : 'file' ),
					'size'     => $is_dir || is_link( $path ) ? 0 : (int) @filesize( $path ),
					'modified' => gmdate( 'c', (int) @filemtime( $path ) ),
				);
				if ( $is_dir && $level < $depth && ! in_array( $name, self::NOISE_DIRS, true ) ) {
					$walk( $path, $level + 1 );
				}
			}
		};
		$walk( $base, 1 );

		return array(
			'path'    => trim( str_replace( '\\', '/', $relative ), '/' ),
			'entries' => $entries,
			'count'   => count( $entries ),
			'capped'  => $capped,
		);
	}

	/**
	 * Read a text file, whole or a range of lines.
	 *
	 * @param string $relative File relative to wp-content.
	 * @param int    $from     First line (1-based).
	 * @param int    $to       Last line (0 = to the end).
	 * @return array<string,mixed>|array{ error: string }
	 */
	public static function read( string $relative, int $from = 1, int $to = 0 ): array {
		$path = self::resolve( $relative );
		if ( null === $path || ! is_file( $path ) ) {
			return array( 'error' => 'Cannot read this file: ' . ( null === $path ? self::why_unavailable( $relative ) : 'it is not a regular file' ) . '.' );
		}
		$size = (int) filesize( $path );
		$base = array(
			'path'     => trim( str_replace( '\\', '/', $relative ), '/' ),
			'size'     => $size,
			'modified' => gmdate( 'c', (int) filemtime( $path ) ),
		);
		if ( $size > self::MAX_READ && 0 === $to ) {
			return array( 'error' => 'File is ' . size_format( $size ) . '; ask for a range of lines (at most ' . size_format( self::MAX_READ ) . ' is returned at once).' );
		}

		$contents = (string) file_get_contents( $path );
		if ( false !== strpos( $contents, "\0" ) || ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $contents, 'UTF-8' ) ) ) {
			return $base + array(
				'binary'   => true,
				'contents' => '',
				'lines'    => 0,
			);
		}

		$lines = preg_split( '/\R/', $contents );
		$total = count( $lines );
		$from  = max( 1, $from );
		$to    = 0 === $to ? $total : min( $total, $to );
		$slice = implode( "\n", array_slice( $lines, $from - 1, max( 0, $to - $from + 1 ) ) );
		if ( strlen( $slice ) > self::MAX_READ ) {
			return array( 'error' => 'That range is larger than ' . size_format( self::MAX_READ ) . '; ask for fewer lines.' );
		}

		return $base + array(
			'binary'   => false,
			'lines'    => $total,
			'from'     => $from,
			'to'       => $to,
			'contents' => $slice,
		);
	}

	/**
	 * Search text files below a directory.
	 *
	 * @param string $pattern  Text to find (or a regular expression).
	 * @param string $relative Directory or file relative to wp-content.
	 * @param array<string,mixed> $options regex (bool), case (bool, case-sensitive), ext (comma-separated extensions), limit.
	 * @return array<string,mixed>|array{ error: string }
	 */
	public static function grep( string $pattern, string $relative, array $options ): array {
		$base = self::resolve( $relative );
		if ( null === $base ) {
			return array( 'error' => 'Cannot read this path: ' . self::why_unavailable( $relative ) . '.' );
		}
		if ( '' === $pattern ) {
			return array( 'error' => 'Nothing to search for.' );
		}
		$regex = '~' . ( empty( $options['regex'] ) ? preg_quote( $pattern, '~' ) : str_replace( '~', '\~', $pattern ) ) . '~' . ( empty( $options['case'] ) ? 'i' : '' );
		if ( false === @preg_match( $regex, '' ) ) {
			return array( 'error' => 'Invalid regular expression.' );
		}
		$ext      = array_filter( array_map( 'trim', explode( ',', strtolower( (string) ( $options['ext'] ?? 'php,js,css,json,html,htm,twig,txt,md,xml,scss,less,ini,yml,yaml' ) ) ) ) );
		$limit    = max( 1, min( 500, (int) ( $options['limit'] ?? 100 ) ) );
		$root     = (string) realpath( WP_CONTENT_DIR );
		$deadline = microtime( true ) + 10;

		$files = array();
		if ( is_file( $base ) ) {
			$files[] = $base;
		} else {
			$filter = new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ),
				static function ( \SplFileInfo $entry ) use ( $root ): bool {
					if ( $entry->isLink() ) {
						return false;
					}
					if ( $entry->isDir() && in_array( $entry->getFilename(), self::NOISE_DIRS, true ) ) {
						return false;
					}
					return ! self::withheld( substr( $entry->getPathname(), strlen( $root ) ) );
				}
			);
			$files = new \RecursiveIteratorIterator( $filter );
		}

		$matches  = array();
		$searched = 0;
		$stopped  = '';
		foreach ( $files as $file ) {
			$path = $file instanceof \SplFileInfo ? $file->getPathname() : (string) $file;
			if ( ! is_file( $path ) ) {
				continue;
			}
			if ( array() !== $ext && ! in_array( strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ), $ext, true ) ) {
				continue;
			}
			if ( filesize( $path ) > self::MAX_GREP ) {
				continue;
			}
			if ( microtime( true ) > $deadline ) {
				$stopped = 'time limit';
				break;
			}
			++$searched;
			$lines = @file( $path, FILE_IGNORE_NEW_LINES );
			if ( false === $lines ) {
				continue;
			}
			foreach ( $lines as $n => $line ) {
				if ( 1 === @preg_match( $regex, $line ) ) {
					$matches[] = array(
						'path' => ltrim( str_replace( DIRECTORY_SEPARATOR, '/', substr( $path, strlen( $root ) ) ), '/' ),
						'line' => $n + 1,
						'text' => strlen( $line ) > 300 ? substr( trim( $line ), 0, 300 ) . '…' : trim( $line ),
					);
					if ( count( $matches ) >= $limit ) {
						$stopped = 'match limit';
						break 2;
					}
				}
			}
		}

		return array(
			'matches'  => $matches,
			'count'    => count( $matches ),
			'searched' => $searched,
			'stopped'  => $stopped,
		);
	}
}
