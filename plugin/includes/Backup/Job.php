<?php
/**
 * Job — one full-site backup: a single tar file, built a few seconds at a time.
 *
 * Backups are kept (up to the `backup_keep` setting) and listed in
 * WP-Admin → WPicker → Backups and by `wpicker backup list`; a device restores
 * one onto a local WordPress with `wpicker backup restore`.
 *
 * Archive layout:
 *   wpicker-backup.json   what was backed up and from where (see manifest())
 *   database.sql          one SQL statement per line (see Dumper)
 *   wp-content/...        plugins, themes, uploads, mu-plugins
 *
 * A request may only run for a few seconds on shared hosting, so the work is
 * cut into steps: the client calls step() until `done` is true. Everything a
 * step needs to continue is in state.json inside the job's directory.
 *
 * Phases: db → list → files → done.
 *
 * @package WPicker\Backup
 */

declare( strict_types = 1 );

namespace WPicker\Backup;

defined( 'ABSPATH' ) || exit;

/**
 * Class Job
 */
final class Job {

	/**
	 * Option mapping job id => private directory name.
	 */
	public const OPTION = 'wpicker_backups';

	/**
	 * Seconds of work per step() call.
	 */
	private const STEP_SECONDS = 6;

	/**
	 * Unfinished jobs older than this are abandoned and removed.
	 */
	private const STALE_SECONDS = 86400;

	/**
	 * Directory names never backed up, at any depth: this plugin's own storage,
	 * development leftovers, and other tools' backups (which would nest whole
	 * copies of the site). Kept short on purpose — a generic name like "cache"
	 * is also a source folder inside some plugins.
	 */
	private const SKIP_DIRS = array(
		'wpicker-vault',
		'node_modules',
		'.git',
		'ai1wm-backups',
		'updraft',
	);

	/**
	 * Parts of wp-content that can be included, by request key.
	 */
	private const PARTS = array(
		'plugins' => array( 'plugins', 'mu-plugins' ),
		'themes'  => array( 'themes' ),
		'uploads' => array( 'uploads' ),
	);

	/**
	 * Job id.
	 *
	 * @var string
	 */
	private $id;

	/**
	 * Job directory (absolute).
	 *
	 * @var string
	 */
	private $dir;

	/**
	 * Job state, persisted in state.json.
	 *
	 * @var array<string,mixed>
	 */
	private $state;

	/**
	 * Use create() or load().
	 *
	 * @param string              $id    Job id.
	 * @param string              $dir   Job directory.
	 * @param array<string,mixed> $state Job state.
	 */
	private function __construct( string $id, string $dir, array $state ) {
		$this->id    = $id;
		$this->dir   = $dir;
		$this->state = $state;
	}

	/**
	 * Directory that holds all backups.
	 *
	 * @return string
	 */
	private static function root(): string {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . 'wpicker-vault/backups';
	}

	/**
	 * Start a new backup.
	 *
	 * The job directory name carries a long random token that is never sent
	 * to the client: the archive contains the full database, and a web server
	 * that ignores .htaccess (nginx) would otherwise serve it to anyone who
	 * could guess the path.
	 *
	 * @param array<string,bool> $include Which parts to back up: db, plugins, themes, uploads.
	 * @param string             $label   Free-text note shown in the list.
	 * @return self
	 *
	 * @throws \RuntimeException If the job directory cannot be created.
	 */
	public static function create( array $include, string $label = '' ): self {
		self::purge_abandoned();

		list( $id, $dir ) = self::allocate();

		$include = array(
			'db'      => ! isset( $include['db'] ) || (bool) $include['db'],
			'plugins' => ! isset( $include['plugins'] ) || (bool) $include['plugins'],
			'themes'  => ! isset( $include['themes'] ) || (bool) $include['themes'],
			'uploads' => ! isset( $include['uploads'] ) || (bool) $include['uploads'],
		);

		$job = new self(
			$id,
			$dir,
			array(
				'id'           => $id,
				'created'      => time(),
				'label'        => $label,
				// Empty when a restore takes its safety backup: by then the
				// request is authorised by the restore token, not a login.
				'created_by'   => wp_get_current_user()->user_login ?: 'restore',
				'include'      => $include,
				'phase'        => $include['db'] ? 'db' : 'list',
				'tables'       => array(),
				'table_index'  => 0,
				'row_offset'   => 0,
				'rows_done'    => 0,
				'files_total'  => 0,
				'files_done'   => 0,
				'files_skipped' => 0,
				'list_offset'  => 0,
				'bytes'        => 0,
			)
		);

		$tar = new Tar( $job->archive() );
		$tar->add_string( 'wpicker-backup.json', (string) wp_json_encode( $job->manifest(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		$tar->close();

		if ( $include['db'] ) {
			$dumper                = new Dumper();
			$job->state['tables'] = $dumper->tables();
			file_put_contents( $job->dump(), $dumper->preamble() );
		}

		$job->save();
		return $job;
	}

	/**
	 * Reserve an id and a private directory for a new backup.
	 *
	 * @return array{0:string,1:string} Id and absolute directory.
	 *
	 * @throws \RuntimeException If the directory cannot be created.
	 */
	private static function allocate(): array {
		$id  = gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 3 ) );
		$dir = self::root() . '/' . $id . '-' . bin2hex( random_bytes( 16 ) );
		if ( ! wp_mkdir_p( $dir ) ) {
			throw new \RuntimeException( 'Could not create the backup directory.' );
		}
		@file_put_contents( $dir . '/index.html', '' );

		$jobs        = self::jobs();
		$jobs[ $id ] = basename( $dir );
		update_option( self::OPTION, $jobs, false );
		return array( $id, $dir );
	}

	/**
	 * Start receiving a backup archive made elsewhere (another site, or this
	 * one earlier). Chunks follow with receive(); finish_upload() validates it.
	 *
	 * @param string $name Original file name, for the label.
	 * @param int    $size Expected size in bytes.
	 * @return self
	 *
	 * @throws \RuntimeException If the directory cannot be created.
	 */
	public static function begin_upload( string $name, int $size ): self {
		self::purge_abandoned();
		list( $id, $dir ) = self::allocate();
		$job = new self(
			$id,
			$dir,
			array(
				'id'            => $id,
				'created'       => time(),
				'label'         => 'Uploaded: ' . $name,
				'created_by'    => wp_get_current_user()->user_login,
				'origin'        => 'upload',
				'source_url'    => '',
				'expected'      => max( 0, $size ),
				'include'       => array(),
				'phase'         => 'upload',
				'tables'        => array(),
				'table_index'   => 0,
				'row_offset'    => 0,
				'rows_done'     => 0,
				'files_total'   => 0,
				'files_done'    => 0,
				'files_skipped' => 0,
				'list_offset'   => 0,
				'bytes'         => 0,
			)
		);
		$job->save();
		return $job;
	}

	/**
	 * Append one chunk of an upload.
	 *
	 * @param int    $offset Byte offset this chunk starts at.
	 * @param string $data   Chunk.
	 * @return int Bytes received so far.
	 *
	 * @throws \RuntimeException If the chunk does not continue the file.
	 */
	public function receive( int $offset, string $data ): int {
		if ( 'upload' !== $this->state['phase'] ) {
			throw new \RuntimeException( 'This backup is not waiting for an upload.' );
		}
		clearstatcache( true, $this->archive() );
		$have = is_file( $this->archive() ) ? (int) filesize( $this->archive() ) : 0;
		// A repeated chunk (the client retried after a lost response) is fine;
		// anything else out of order would corrupt the archive.
		if ( $offset + strlen( $data ) === $have ) {
			return $have;
		}
		if ( $offset !== $have ) {
			throw new \RuntimeException( 'Upload out of order: expected offset ' . $have . '.' );
		}
		if ( $have + strlen( $data ) > (int) $this->state['expected'] ) {
			throw new \RuntimeException( 'Upload is larger than announced.' );
		}
		if ( false === file_put_contents( $this->archive(), $data, FILE_APPEND ) ) {
			throw new \RuntimeException( 'Could not write the upload.' );
		}
		return $have + strlen( $data );
	}

	/**
	 * Close an upload: check it is a WPicker backup and list it.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If the file is incomplete or not a backup.
	 */
	public function finish_upload(): void {
		clearstatcache( true, $this->archive() );
		$have = is_file( $this->archive() ) ? (int) filesize( $this->archive() ) : 0;
		if ( $have !== (int) $this->state['expected'] || 0 === $have ) {
			throw new \RuntimeException( 'Upload incomplete: ' . $have . ' of ' . $this->state['expected'] . ' bytes.' );
		}

		// The manifest is the first entry: a 512-byte header, then JSON.
		$in     = fopen( $this->archive(), 'rb' );
		$header = (string) fread( $in, 512 );
		$name   = rtrim( substr( $header, 0, 100 ), "\0" );
		$size   = (int) octdec( trim( substr( $header, 124, 12 ), " \0" ) );
		$body   = 'wpicker-backup.json' === $name && $size > 0 && $size < 1048576 ? (string) fread( $in, $size ) : '';
		fclose( $in );
		$manifest = json_decode( $body, true );
		if ( ! is_array( $manifest ) || 1 !== (int) ( $manifest['format'] ?? 0 ) ) {
			throw new \RuntimeException( 'This file is not a WPicker backup.' );
		}

		$this->state['include']    = array_map( 'boolval', (array) ( $manifest['include'] ?? array() ) );
		$this->state['source_url'] = (string) ( $manifest['site_url'] ?? '' );
		$this->state['phase']      = 'done';
		$this->state['bytes']      = $have;
		$this->save();
		self::apply_retention();
	}

	/**
	 * Load an existing job.
	 *
	 * @param string $id Job id.
	 * @return self|null
	 */
	public static function load( string $id ): ?self {
		$jobs = self::jobs();
		if ( ! isset( $jobs[ $id ] ) ) {
			return null;
		}
		$dir   = self::root() . '/' . $jobs[ $id ];
		$state = is_readable( $dir . '/state.json' ) ? json_decode( (string) file_get_contents( $dir . '/state.json' ), true ) : null;
		return is_array( $state ) ? new self( $id, $dir, $state ) : null;
	}

	/**
	 * Do a few seconds of work.
	 *
	 * @return array<string,mixed> Public progress (see progress()).
	 */
	public function step(): array {
		$deadline = microtime( true ) + self::STEP_SECONDS;

		while ( ! in_array( $this->state['phase'], array( 'done', 'upload' ), true ) && microtime( true ) < $deadline ) {
			switch ( $this->state['phase'] ) {
				case 'db':
					$this->step_db( $deadline );
					break;
				case 'list':
					$this->step_list();
					break;
				case 'files':
					$this->step_files( $deadline );
					break;
			}
		}

		$this->save();
		if ( 'done' === $this->state['phase'] ) {
			self::apply_retention();
		}
		return $this->progress();
	}

	/**
	 * Dump tables until the deadline, then put the finished dump in the archive.
	 *
	 * @param float $deadline Unix time to stop at.
	 * @return void
	 */
	private function step_db( float $deadline ): void {
		$dumper = new Dumper();
		$out    = fopen( $this->dump(), 'ab' );

		while ( microtime( true ) < $deadline && $this->state['table_index'] < count( $this->state['tables'] ) ) {
			$table = $this->state['tables'][ $this->state['table_index'] ];
			if ( 0 === $this->state['row_offset'] ) {
				fwrite( $out, $dumper->structure( $table ) );
			}
			$slice = $dumper->rows( $table, (int) $this->state['row_offset'] );
			fwrite( $out, $slice['sql'] );
			$this->state['rows_done'] += $slice['rows'];

			if ( $dumper->finished( $slice['rows'] ) ) {
				++$this->state['table_index'];
				$this->state['row_offset'] = 0;
			} else {
				$this->state['row_offset'] += $slice['rows'];
			}
		}
		fclose( $out );

		if ( $this->state['table_index'] >= count( $this->state['tables'] ) ) {
			$tar = new Tar( $this->archive() );
			$tar->add_file( $this->dump(), 'database.sql' );
			$tar->close();
			@unlink( $this->dump() );
			$this->state['phase'] = 'list';
		}
	}

	/**
	 * Write the list of files to archive (one relative path per line).
	 *
	 * @return void
	 */
	private function step_list(): void {
		$content = untrailingslashit( WP_CONTENT_DIR );
		$list    = fopen( $this->list_file(), 'wb' );
		$total   = 0;

		foreach ( self::PARTS as $key => $dirs ) {
			if ( empty( $this->state['include'][ $key ] ) ) {
				continue;
			}
			foreach ( $dirs as $dir ) {
				$total += $this->list_dir( $list, $content, $dir );
			}
		}
		fclose( $list );

		$this->state['files_total'] = $total;
		$this->state['phase']       = 'files';
	}

	/**
	 * Append the regular files below one wp-content sub-directory to the list.
	 *
	 * Symbolic links are not followed: a linked plugin or theme lives outside
	 * the site and is not ours to copy.
	 *
	 * @param resource $list    Open list file.
	 * @param string   $content wp-content directory.
	 * @param string   $dir     Sub-directory name.
	 * @return int Files listed.
	 */
	private function list_dir( $list, string $content, string $dir ): int {
		$base = $content . '/' . $dir;
		if ( ! is_dir( $base ) || is_link( $base ) ) {
			return 0;
		}

		$filter = new \RecursiveCallbackFilterIterator(
			new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ),
			static function ( \SplFileInfo $entry ): bool {
				if ( $entry->isLink() ) {
					return false;
				}
				return ! $entry->isDir() || ! in_array( $entry->getFilename(), self::SKIP_DIRS, true );
			}
		);

		$count = 0;
		foreach ( new \RecursiveIteratorIterator( $filter ) as $entry ) {
			if ( ! $entry->isFile() ) {
				continue;
			}
			$relative = str_replace( DIRECTORY_SEPARATOR, '/', substr( $entry->getPathname(), strlen( $content ) + 1 ) );
			// A newline in a file name would corrupt the list; such files are skipped.
			if ( false === strpos( $relative, "\n" ) ) {
				fwrite( $list, $relative . "\n" );
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Append listed files to the archive until the deadline.
	 *
	 * @param float $deadline Unix time to stop at.
	 * @return void
	 */
	private function step_files( float $deadline ): void {
		$content = untrailingslashit( WP_CONTENT_DIR );
		$list    = fopen( $this->list_file(), 'rb' );
		fseek( $list, (int) $this->state['list_offset'] );
		$tar = new Tar( $this->archive() );

		$finished = false;
		while ( microtime( true ) < $deadline ) {
			$line = fgets( $list );
			if ( false === $line ) {
				$finished = true;
				break;
			}
			$relative = rtrim( $line, "\n" );
			if ( '' !== $relative ) {
				if ( $tar->add_file( $content . '/' . $relative, 'wp-content/' . $relative ) ) {
					++$this->state['files_done'];
				} else {
					++$this->state['files_skipped'];
				}
			}
			$this->state['list_offset'] = ftell( $list );
		}
		fclose( $list );

		if ( $finished ) {
			$tar->finish();
			@unlink( $this->list_file() );
			$this->state['phase'] = 'done';
		} else {
			$tar->close();
		}
		clearstatcache( true, $this->archive() );
		$this->state['bytes'] = (int) filesize( $this->archive() );
	}

	/**
	 * What the client may know about the job.
	 *
	 * @return array<string,mixed>
	 */
	public function progress(): array {
		clearstatcache( true, $this->archive() );
		return array(
			'id'            => $this->id,
			'created_gmt'   => gmdate( 'c', (int) $this->state['created'] ),
			'label'         => (string) ( $this->state['label'] ?? '' ),
			'created_by'    => (string) ( $this->state['created_by'] ?? '' ),
			'origin'        => (string) ( $this->state['origin'] ?? 'site' ),
			'source_url'    => (string) ( $this->state['source_url'] ?? untrailingslashit( site_url() ) ),
			'phase'         => $this->state['phase'],
			'done'          => 'done' === $this->state['phase'],
			'tables_total'  => count( $this->state['tables'] ),
			'tables_done'   => (int) $this->state['table_index'],
			'rows_done'     => (int) $this->state['rows_done'],
			'files_total'   => (int) $this->state['files_total'],
			'files_done'    => (int) $this->state['files_done'],
			'files_skipped' => (int) $this->state['files_skipped'],
			'bytes'         => is_file( $this->archive() ) ? (int) filesize( $this->archive() ) : 0,
			'include'       => $this->state['include'],
		);
	}

	/**
	 * Backup id.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Is the archive complete?
	 *
	 * @return bool
	 */
	public function done(): bool {
		return 'done' === $this->state['phase'];
	}

	/**
	 * Absolute path of the archive.
	 *
	 * @return string
	 */
	public function archive(): string {
		return $this->dir . '/site.tar';
	}

	/**
	 * Remove the backup and everything it wrote.
	 *
	 * @return void
	 */
	public function delete(): void {
		self::remove_dir( $this->dir );
		$jobs = self::jobs();
		unset( $jobs[ $this->id ] );
		update_option( self::OPTION, $jobs, false );
	}

	/**
	 * Facts about the source site the importer needs to rewrite URLs and paths.
	 *
	 * @return array<string,mixed>
	 */
	private function manifest(): array {
		global $wpdb, $wp_version;
		return array(
			'format'         => 1,
			'created_gmt'    => gmdate( 'c' ),
			'wpicker'        => WPICKER_VERSION,
			'site_url'       => untrailingslashit( site_url() ),
			'home_url'       => untrailingslashit( home_url() ),
			'abspath'        => untrailingslashit( ABSPATH ),
			'content_dir'    => untrailingslashit( WP_CONTENT_DIR ),
			'table_prefix'   => $wpdb->prefix,
			'wp_version'     => $wp_version,
			'php_version'    => PHP_VERSION,
			'db_charset'     => $wpdb->charset,
			'is_multisite'   => is_multisite(),
			'stylesheet'     => get_stylesheet(),
			'template'       => get_template(),
			'active_plugins' => array_values( (array) get_option( 'active_plugins', array() ) ),
			'include'        => $this->state['include'],
		);
	}

	/**
	 * Work file: the SQL dump while it is being written.
	 *
	 * @return string
	 */
	private function dump(): string {
		return $this->dir . '/database.sql';
	}

	/**
	 * Work file: the list of files still to archive.
	 *
	 * @return string
	 */
	private function list_file(): string {
		return $this->dir . '/files.list';
	}

	/**
	 * Persist the state.
	 *
	 * @return void
	 */
	private function save(): void {
		file_put_contents( $this->dir . '/state.json', (string) wp_json_encode( $this->state ) );
	}

	/**
	 * Registered jobs: id => directory name.
	 *
	 * @return array<string,string>
	 */
	private static function jobs(): array {
		$jobs = get_option( self::OPTION, array() );
		return is_array( $jobs ) ? $jobs : array();
	}

	/**
	 * All backups, newest first.
	 *
	 * @return array<int,self>
	 */
	public static function all(): array {
		$out = array();
		foreach ( array_keys( self::jobs() ) as $id ) {
			$job = self::load( (string) $id );
			if ( null !== $job ) {
				$out[] = $job;
			}
		}
		usort(
			$out,
			static function ( self $a, self $b ): int {
				return strcmp( $b->id, $a->id ); // ids start with a timestamp
			}
		);
		return $out;
	}

	/**
	 * Keep the newest `backup_keep` finished backups and delete older ones.
	 *
	 * Runs when a backup FINISHES, never when one starts: a backup that then
	 * fails, or an upload that turns out not to be a backup, must not have
	 * cost a good one. The backup a restore is reading — and the safety copy
	 * it took — are never removed while that restore is under way.
	 *
	 * Each archive is a full copy of the site and holds the whole database,
	 * so they must not pile up.
	 *
	 * @return void
	 */
	private static function apply_retention(): void {
		$settings = get_option( 'wpicker_settings', array() );
		$keep     = max( 1, (int) ( $settings['backup_keep'] ?? 3 ) );

		$restore = get_option( Restore::OPTION, null );
		$protect = array();
		if ( is_array( $restore ) && ! in_array( $restore['phase'] ?? '', array( 'done', 'failed' ), true ) ) {
			$protect = array( (string) ( $restore['backup_id'] ?? '' ), (string) ( $restore['safety_id'] ?? '' ) );
		}

		$finished = 0;
		foreach ( self::all() as $job ) {
			if ( ! $job->done() || in_array( $job->id, $protect, true ) ) {
				continue;
			}
			if ( ++$finished > $keep ) {
				$job->delete();
			}
		}
	}

	/**
	 * Remove unfinished jobs nobody is working on any more, and registry
	 * entries whose directory is gone.
	 *
	 * @return void
	 */
	private static function purge_abandoned(): void {
		foreach ( self::all() as $job ) {
			if ( ! $job->done() && time() - (int) $job->state['created'] > self::STALE_SECONDS ) {
				$job->delete();
			}
		}

		$jobs = self::jobs();
		foreach ( $jobs as $id => $name ) {
			if ( ! is_dir( self::root() . '/' . $name ) ) {
				unset( $jobs[ $id ] );
			}
		}
		update_option( self::OPTION, $jobs, false );
	}

	/**
	 * Recursively remove a directory.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private static function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$entries = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $entries as $entry ) {
			if ( $entry->isDir() && ! $entry->isLink() ) {
				@rmdir( $entry->getPathname() );
			} else {
				@unlink( $entry->getPathname() );
			}
		}
		@rmdir( $dir );
	}
}
