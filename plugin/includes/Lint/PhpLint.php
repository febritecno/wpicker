<?php
/**
 * PHP linter — runs `php -l` on incoming theme files before applying a push.
 *
 * Guardrail: the auto-lint gate. If any .php payload fails `php -l`, the
 * entire push is rejected (preventive rollback) and a structured error is
 * returned to the CLI so the AI agent can self-heal.
 *
 * @package WPicker\Lint
 */

declare( strict_types = 1 );

namespace WPicker\Lint;

defined( 'ABSPATH' ) || exit;

/**
 * Class PhpLint
 */
final class PhpLint {

	/**
	 * Lint a single PHP source string.
	 *
	 * Writes the source to a temp file (in the system temp dir, never inside
	 * the theme) and runs `php -l` against it. Returns a structured result.
	 *
	 * @param string $source PHP source code.
	 * @return array{ ok: bool, code?: string, message?: string, file?: string, line?: int }
	 */
	public function lint_source( string $source ): array {
		// Non-PHP files are always "clean" — lint only applies to .php.
		if ( '' === trim( $source ) ) {
			return array( 'ok' => true );
		}

		$tmp = tmpfile();
		if ( false === $tmp ) {
			return array(
				'ok'      => false,
				'code'    => 'wpicker_lint_temp',
				'message' => 'Could not create temp file for linting.',
			);
		}
		$tmp_path = stream_get_meta_data( $tmp )['uri'];
		fwrite( $tmp, $source );

		$result = $this->run_phpl( $tmp_path );
		fclose( $tmp );
		return $result;
	}

	/**
	 * Lint a file already on disk (e.g. an existing theme file).
	 *
	 * @param string $absolute_path Absolute file path.
	 * @return array{ ok: bool, code?: string, message?: string, file?: string, line?: int }
	 */
	public function lint_file( string $absolute_path ): array {
		if ( ! is_readable( $absolute_path ) ) {
			return array(
				'ok'      => false,
				'code'    => 'wpicker_lint_unreadable',
				'message' => 'File is not readable: ' . basename( $absolute_path ),
			);
		}
		return $this->run_phpl( $absolute_path );
	}

	/**
	 * Lint many files at once; returns the first failure encountered.
	 *
	 * @param array<int,string> $absolute_paths Absolute paths.
	 * @return array{ ok: bool, code?: string, message?: string, file?: string, line?: int, checked?: int }
	 */
	public function lint_files( array $absolute_paths ): array {
		$checked = 0;
		foreach ( $absolute_paths as $path ) {
			$result = $this->lint_file( $path );
			$checked++;
			if ( ! $result['ok'] ) {
				$result['checked'] = $checked;
				return $result;
			}
		}
		return array( 'ok' => true, 'checked' => $checked );
	}

	/**
	 * Locate a usable PHP binary.
	 *
	 * Tries WPICKER_PHP_BIN, then common paths. Falls back to null if none found.
	 *
	 * @return string|null
	 */
	private function php_binary(): ?string {
		if ( defined( 'WPICKER_PHP_BIN' ) && is_executable( WPICKER_PHP_BIN ) ) {
			return WPICKER_PHP_BIN;
		}
		foreach ( array( '/usr/local/bin/php', '/usr/bin/php', '/opt/homebrew/bin/php' ) as $candidate ) {
			if ( is_executable( $candidate ) ) {
				return $candidate;
			}
		}
		// Last resort: rely on $PATH.
		$which = trim( (string) shell_exec( 'command -v php' ) );
		return '' !== $which && is_executable( $which ) ? $which : null;
	}

	/**
	 * Run `php -l` on a path and parse the output.
	 *
	 * @param string $path Absolute path to lint.
	 * @return array{ ok: bool, code?: string, message?: string, file?: string, line?: int }
	 */
	private function run_phpl( string $path ): array {
		$binary = $this->php_binary();
		if ( null === $binary ) {
			// No PHP binary on host — cannot enforce the gate. Fail safe.
			return array(
				'ok'      => false,
				'code'    => 'wpicker_lint_no_php',
				'message' => 'No PHP binary available to lint; refusing push (set WPICKER_PHP_BIN).',
			);
		}

		$cmd   = escapeshellarg( $binary ) . ' -l ' . escapeshellarg( $path ) . ' 2>&1';
		$out   = (string) shell_exec( $cmd );
		$clean = ( false !== strpos( $out, 'No syntax errors detected' ) );

		if ( $clean ) {
			return array( 'ok' => true );
		}

		// Parse: "PHP Parse error:  ... in /path on line 12" or "...: ... in /path on line 12".
		$message = trim( strtok( $out, "\n" ) ) ?: $out;
		$line    = null;
		if ( preg_match( '/on line (\d+)/', $out, $m ) ) {
			$line = (int) $m[1];
		}
		// Strip the temp path from the message for cleanliness.
		$message = str_replace( $path, basename( $path ), $message );

		return array(
			'ok'      => false,
			'code'    => 'wpicker_lint_syntax',
			'message' => $message,
			'file'    => basename( $path ),
			'line'    => $line,
		);
	}
}
