<?php
/**
 * Tar — append-only writer for the site backup archive.
 *
 * A tar file is a plain sequence of entries, so a backup can be resumed in a
 * later request just by opening the file for append — unlike zip, whose
 * central directory has to be rewritten every time it is closed. That is what
 * lets a large site be backed up in many short steps.
 *
 * Writes ustar headers, with a GNU "LongLink" entry for paths over 100 bytes.
 * The archive is finished with finish(), not on every append.
 *
 * @package WPicker\Backup
 */

declare( strict_types = 1 );

namespace WPicker\Backup;

defined( 'ABSPATH' ) || exit;

/**
 * Class Tar
 */
final class Tar {

	/**
	 * Tar block size in bytes.
	 */
	private const BLOCK = 512;

	/**
	 * Archive handle, opened for append.
	 *
	 * @var resource
	 */
	private $handle;

	/**
	 * Open (or create) an archive for appending.
	 *
	 * @param string $path Archive path.
	 *
	 * @throws \RuntimeException If the file cannot be opened.
	 */
	public function __construct( string $path ) {
		$handle = fopen( $path, 'ab' );
		if ( false === $handle ) {
			throw new \RuntimeException( 'Could not open the backup archive for writing.' );
		}
		$this->handle = $handle;
	}

	/**
	 * Append a file from disk under the given archive name.
	 *
	 * @param string $source Absolute path of the file to read.
	 * @param string $name   Path inside the archive (forward slashes).
	 * @return bool False if the file could not be read (nothing is written).
	 */
	public function add_file( string $source, string $name ): bool {
		$size = @filesize( $source );
		$in   = false === $size ? false : @fopen( $source, 'rb' );
		if ( false === $in ) {
			return false;
		}

		$this->header( $name, (int) $size, (int) @filemtime( $source ) );
		$written = 0;
		while ( $written < $size && ! feof( $in ) ) {
			$chunk = fread( $in, (int) min( 1048576, $size - $written ) );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			fwrite( $this->handle, $chunk );
			$written += strlen( $chunk );
		}
		fclose( $in );

		// The header already promised $size bytes: pad if the file shrank
		// while we were reading it, so the archive stays well-formed.
		if ( $written < $size ) {
			fwrite( $this->handle, str_repeat( "\0", $size - $written ) );
		}
		$this->pad( (int) $size );
		return true;
	}

	/**
	 * Append an entry from a string.
	 *
	 * @param string $name     Path inside the archive.
	 * @param string $contents Entry contents.
	 * @return void
	 */
	public function add_string( string $name, string $contents ): void {
		$this->header( $name, strlen( $contents ), time() );
		fwrite( $this->handle, $contents );
		$this->pad( strlen( $contents ) );
	}

	/**
	 * Write the end-of-archive marker (two zero blocks) and close.
	 *
	 * @return void
	 */
	public function finish(): void {
		fwrite( $this->handle, str_repeat( "\0", self::BLOCK * 2 ) );
		$this->close();
	}

	/**
	 * Close without finishing, to continue appending in a later request.
	 *
	 * @return void
	 */
	public function close(): void {
		if ( is_resource( $this->handle ) ) {
			fclose( $this->handle );
		}
	}

	/**
	 * Write the header block(s) for a regular file.
	 *
	 * @param string $name  Path inside the archive.
	 * @param int    $size  Size in bytes.
	 * @param int    $mtime Modification time.
	 * @return void
	 */
	private function header( string $name, int $size, int $mtime ): void {
		if ( strlen( $name ) > 100 ) {
			// GNU long name: a pseudo-entry whose body is the real path.
			$long = $name . "\0";
			fwrite( $this->handle, $this->block( '././@LongLink', strlen( $long ), 0, 'L' ) );
			fwrite( $this->handle, $long );
			$this->pad( strlen( $long ) );
			$name = substr( $name, 0, 100 );
		}
		fwrite( $this->handle, $this->block( $name, $size, $mtime, '0' ) );
	}

	/**
	 * Build one 512-byte ustar header block.
	 *
	 * @param string $name  Entry name (at most 100 bytes).
	 * @param int    $size  Entry size.
	 * @param int    $mtime Modification time.
	 * @param string $type  Type flag.
	 * @return string
	 */
	private function block( string $name, int $size, int $mtime, string $type ): string {
		$fields = pack( 'a100a8a8a8a12a12', $name, '0000644', '0000000', '0000000', sprintf( '%011o', $size ), sprintf( '%011o', max( 0, $mtime ) ) );
		$tail   = pack( 'a1a100a6a2a32a32a8a8a155a12', $type, '', 'ustar', '00', '', '', '', '', '', '' );

		// The checksum is computed with its own field filled with spaces.
		$sum = 0;
		foreach ( str_split( $fields . '        ' . $tail ) as $char ) {
			$sum += ord( $char );
		}
		return $fields . sprintf( "%06o\0 ", $sum ) . $tail;
	}

	/**
	 * Pad an entry body up to the next block boundary.
	 *
	 * @param int $size Bytes just written.
	 * @return void
	 */
	private function pad( int $size ): void {
		$rest = $size % self::BLOCK;
		if ( $rest > 0 ) {
			fwrite( $this->handle, str_repeat( "\0", self::BLOCK - $rest ) );
		}
	}
}
