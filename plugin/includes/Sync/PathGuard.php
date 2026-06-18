<?php
/**
 * PathGuard — confines all file operations to the active child theme.
 *
 * Guardrail: NO path returned by this class can escape the child theme
 * directory. Every relative path is resolved through realpath() and verified
 * to be inside get_stylesheet_directory(). Traversal attempts (../), absolute
 * paths, and symlink escapes are rejected.
 *
 * @package WPicker\Sync
 */

declare( strict_types = 1 );

namespace WPicker\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Class PathGuard
 */
final class PathGuard {

	/**
	 * Sensitive files/basenames that must never be written even inside the theme.
	 *
	 * Kept intentionally small; the confinement to the child theme is the
	 * primary guard. Extend if you discover additional footguns.
	 */
	private const BLOCKED_BASENAMES = array(
		'.env',
		'wp-config.php',
	);

	/**
	 * Get the absolute path of the active child theme root.
	 *
	 * @return string
	 */
	public function theme_root(): string {
		return (string) get_stylesheet_directory();
	}

	/**
	 * Is there actually a child theme active (different from parent)?
	 *
	 * If no child theme is active, sync is a no-op and must be refused
	 * loudly rather than writing into the parent theme.
	 *
	 * @return bool
	 */
	public function has_child_theme(): bool {
		$theme = wp_get_theme();
		return $theme->get_template() !== $theme->get_stylesheet();
	}

	/**
	 * Resolve a relative path to an absolute one inside the child theme,
	 * verifying it is confined. Returns null on any escape attempt.
	 *
	 * @param string $relative Relative path (POSIX separators, e.g. "inc/foo.php").
	 * @return string|null Absolute path, or null if it would escape the theme.
	 */
	public function resolve( string $relative ): ?string {
		$relative = $this->normalize( $relative );
		if ( '' === $relative ) {
			return null;
		}

		// Reject absolute paths and any traversal segments outright.
		if ( preg_match( '#^(/|\\\\|[a-zA-Z]:)#', $relative ) ) {
			return null;
		}
		$parts = explode( '/', $relative );
		foreach ( $parts as $part ) {
			if ( '..' === $part || '' === $part ) {
				return null;
			}
		}

		// Block sensitive filenames.
		$basename = strtolower( basename( $relative ) );
		if ( in_array( $basename, self::BLOCKED_BASENAMES, true ) ) {
			return null;
		}

		$root = trailingslashit( $this->theme_root() );
		$absolute = $root . $relative;

		// Final confinement check via realpath (for existing files) or
		// path normalization (for files we are about to create).
		$resolved_root = realpath( $root );
		if ( false === $resolved_root ) {
			return null;
		}

		if ( is_file( $absolute ) ) {
			$resolved_file = realpath( $absolute );
			if ( false === $resolved_file ) {
				return null;
			}
			if ( strpos( $resolved_file, $resolved_root . DIRECTORY_SEPARATOR ) !== 0
				&& $resolved_file !== $resolved_root ) {
				return null;
			}
			return $resolved_file;
		}

		// For not-yet-existing files, normalize lexically and verify prefix.
		$normalized = $this->normalize_absolute( $absolute );
		if ( strpos( $normalized, $resolved_root . DIRECTORY_SEPARATOR ) !== 0 ) {
			return null;
		}
		return $normalized;
	}

	/**
	 * Verify that an already-absolute path stays inside the child theme.
	 *
	 * Used by the Vault when restoring from a snapshot directory.
	 *
	 * @param string $absolute Absolute path to verify.
	 * @return bool
	 */
	public function is_inside_theme( string $absolute ): bool {
		$resolved_root = realpath( $this->theme_root() );
		if ( false === $resolved_root ) {
			return false;
		}
		$normalized = $this->normalize_absolute( $absolute );

		return strpos( $normalized, $resolved_root . DIRECTORY_SEPARATOR ) === 0
			|| $normalized === $resolved_root;
	}

	/**
	 * Strip a theme-root prefix to get the relative path, or null if outside.
	 *
	 * @param string $absolute Absolute path.
	 * @return string|null Relative path (forward slashes), or null.
	 */
	public function relative_to_theme( string $absolute ): ?string {
		$resolved_root = realpath( $this->theme_root() );
		if ( false === $resolved_root ) {
			return null;
		}
		$normalized = $this->normalize_absolute( $absolute );
		$prefix = $resolved_root . DIRECTORY_SEPARATOR;
		if ( strpos( $normalized, $prefix ) !== 0 ) {
			return null;
		}
		$rel = substr( $normalized, strlen( $prefix ) );

		return str_replace( DIRECTORY_SEPARATOR, '/', $rel );
	}

	/**
	 * Normalize a relative path: forward-slashes, no leading/trailing slash.
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private function normalize( string $path ): string {
		$path = trim( $path );
		$path = str_replace( '\\', '/', $path );
		$path = ltrim( $path, '/' );

		return $path;
	}

	/**
	 * Lexical normalization of an absolute path (resolves . and ..).
	 *
	 * Falls back to realpath() when the file exists.
	 *
	 * @param string $absolute Absolute path.
	 * @return string Normalized absolute path.
	 */
	private function normalize_absolute( string $absolute ): string {
		if ( is_link( $absolute ) || is_file( $absolute ) || is_dir( $absolute ) ) {
			$real = realpath( $absolute );
			if ( false !== $real ) {
				return $real;
			}
		}
		// Purely lexical: collapse . and .. segments.
		$parts     = explode( DIRECTORY_SEPARATOR, str_replace( array( '/', '\\' ), DIRECTORY_SEPARATOR, $absolute ) );
		$stack     = array();
		$is_abs    = ( isset( $parts[0] ) && '' === $parts[0] ); // leading slash on POSIX.
		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				if ( ! empty( $stack ) && end( $stack ) !== '..' ) {
					array_pop( $stack );
				}
				continue;
			}
			$stack[] = $part;
		}
		$normalized = implode( DIRECTORY_SEPARATOR, $stack );

		return $is_abs ? ( DIRECTORY_SEPARATOR . $normalized ) : $normalized;
	}
}
