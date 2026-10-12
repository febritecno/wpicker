<?php
/**
 * Restore — rebuilds THIS site from a backup archive, a few seconds at a time.
 *
 * The danger is the database: WordPress has to keep booting for every step
 * request, so the live tables cannot be dropped and refilled in place. The
 * dump is loaded into shadow tables (prefix TEMP_PREFIX) while the site keeps
 * running on the real ones, and only at the very end are the two sets
 * exchanged in a single RENAME TABLE.
 *
 * Phases:
 *   safety   back up the current site first (optional)
 *   scan     read the archive's index: manifest, where the dump is, file count
 *   db       load the dump into shadow tables
 *   rewrite  replace the backup's URLs and paths with this site's
 *   files    unpack wp-content (existing files are overwritten; this plugin is
 *            never touched)
 *   prune    exact copy only: delete files the backup does not have
 *   swap     carry over what must survive, then exchange shadow and live tables
 *   done
 *
 * After `swap` the users are the backup's users, so the administrator's own
 * session no longer exists. Steps are therefore authorised by a one-time
 * token issued when the restore starts, not by the WordPress login.
 *
 * @package WPicker\Backup
 */

declare( strict_types = 1 );

namespace WPicker\Backup;

defined( 'ABSPATH' ) || exit;

/**
 * Class Restore
 */
final class Restore {

	/**
	 * Option holding the one restore that may be in progress.
	 */
	public const OPTION = 'wpicker_restore';

	/**
	 * Prefix of the shadow tables the dump is loaded into.
	 */
	private const TEMP_PREFIX = 'wpkr_';

	/**
	 * Prefix the replaced live tables get for the instant before they are dropped.
	 */
	private const OLD_PREFIX = 'wpko_';

	/**
	 * Seconds of work per step() call.
	 */
	private const STEP_SECONDS = 6;

	/**
	 * Rows examined per query in the rewrite phase.
	 */
	private const REWRITE_BATCH = 200;

	/**
	 * Options of this plugin that belong to the site being restored INTO and
	 * must survive the database being replaced: its settings, its list of
	 * backups (including the safety backup just taken), its snapshot history
	 * (the snapshot files are not part of any backup), and this job itself.
	 */
	private const KEEP_OPTIONS = array(
		'wpicker_settings',
		Job::OPTION,
		self::OPTION,
		'wpicker_vault_manifests',
	);

	/**
	 * Plugins that redirect all traffic to HTTPS. Switched off when the site
	 * being restored INTO is not on HTTPS (see step_swap()).
	 */
	private const FORCE_SSL_PLUGINS = array(
		'really-simple-ssl/rlrsssl-really-simple-ssl.php',
		'wordpress-https/wordpress-https.php',
		'wp-force-ssl/wp-force-ssl.php',
	);

	/**
	 * wp-content sub-directories each backup part fills.
	 */
	private const PARTS = array(
		'plugins' => array( 'plugins', 'mu-plugins' ),
		'themes'  => array( 'themes' ),
		'uploads' => array( 'uploads' ),
	);

	/**
	 * Job state, persisted in the OPTION and (mirrored) in state.json.
	 *
	 * @var array<string,mixed>
	 */
	private $state;

	/**
	 * Use start() or current().
	 *
	 * @param array<string,mixed> $state State.
	 */
	private function __construct( array $state ) {
		$this->state = $state;
	}

	/**
	 * Begin restoring a finished backup onto this site.
	 *
	 * @param Job                $backup  Backup to restore.
	 * @param array<string,bool> $options db / plugins / themes / uploads (which parts to restore), safety, exact.
	 * @return array{ restore: self, token: string } The token authorises step(); it is shown once.
	 *
	 * @throws \RuntimeException If a restore is already running or the backup is unusable.
	 */
	public static function start( Job $backup, array $options ): array {
		$running = self::current();
		if ( null !== $running && ! in_array( $running->state['phase'], array( 'done', 'failed' ), true ) ) {
			throw new \RuntimeException( 'Another restore is already in progress.' );
		}
		if ( ! $backup->done() ) {
			throw new \RuntimeException( 'This backup is incomplete and cannot be restored.' );
		}

		$token = bin2hex( random_bytes( 24 ) );
		$state = array(
			'id'            => gmdate( 'Ymd-His' ) . '-' . bin2hex( random_bytes( 3 ) ),
			'token_hash'    => hash( 'sha256', $token ),
			'started'       => time(),
			'backup_id'     => $backup->id(),
			'archive'       => $backup->archive(),
			'want'          => array(
				'db'      => ! empty( $options['db'] ),
				'plugins' => ! empty( $options['plugins'] ),
				'themes'  => ! empty( $options['themes'] ),
				'uploads' => ! empty( $options['uploads'] ),
			),
			'exact'         => ! empty( $options['exact'] ),
			'files_removed' => 0,
			'phase'         => empty( $options['safety'] ) ? 'scan' : 'safety',
			'error'         => '',
			'safety_id'     => '',
			'manifest'      => array(),
			'dump_offset'   => 0,
			'dump_size'     => 0,
			'dump_pos'      => 0,
			'statements'    => 0,
			'skipped_sql'   => 0,
			'files_total'   => 0,
			'files_done'    => 0,
			'files_skipped' => 0,
			'file_offset'   => 0,
			'rewrite'       => array(
				'table'  => 0,
				'column' => 0,
				'last'   => null,
				'rows'   => 0,
			),
			// This site's identity, captured before its database is replaced.
			'site_url'      => untrailingslashit( site_url() ),
			'home_url'      => untrailingslashit( home_url() ),
			'login_url'     => wp_login_url(),
		);

		$restore = new self( $state );
		$restore->save();
		return array(
			'restore' => $restore,
			'token'   => $token,
		);
	}

	/**
	 * The restore in progress (or the last one), if any.
	 *
	 * @return self|null
	 */
	public static function current(): ?self {
		$state = get_option( self::OPTION, null );
		return is_array( $state ) && isset( $state['id'] ) ? new self( $state ) : null;
	}

	/**
	 * Does this token authorise stepping this restore?
	 *
	 * @param string $token Token presented by the client.
	 * @return bool
	 */
	public function authorises( string $token ): bool {
		return '' !== $token && hash_equals( (string) $this->state['token_hash'], hash( 'sha256', $token ) );
	}

	/**
	 * Forget a finished or failed restore.
	 *
	 * @return void
	 */
	public static function clear(): void {
		delete_option( self::OPTION );
	}

	/**
	 * Do a few seconds of work.
	 *
	 * @return array<string,mixed> Public progress.
	 */
	public function step(): array {
		$deadline = microtime( true ) + self::STEP_SECONDS;
		try {
			while ( ! in_array( $this->state['phase'], array( 'done', 'failed' ), true ) && microtime( true ) < $deadline ) {
				$method = 'step_' . $this->state['phase'];
				$this->$method( $deadline );
			}
		} catch ( \Throwable $e ) {
			$this->state['error'] = $e->getMessage();
			$this->state['phase'] = 'failed';
			$this->drop_tables( self::TEMP_PREFIX );
		}
		$this->save();
		return $this->progress();
	}

	/**
	 * Phase: back up the current site, so the restore can be undone.
	 *
	 * @param float $deadline Unix time to stop at.
	 * @return void
	 */
	private function step_safety( float $deadline ): void {
		if ( '' === $this->state['safety_id'] ) {
			// Retention (see Job::apply_retention()) leaves both the backup
			// being restored and this safety copy alone while we are running.
			$job = Job::create( array(), 'Before restore of ' . $this->state['backup_id'] );
			$this->state['safety_id'] = $job->id();
			$this->save();
		} else {
			$job = Job::load( $this->state['safety_id'] );
			if ( null === $job ) {
				throw new \RuntimeException( 'The safety backup disappeared.' );
			}
		}
		while ( ! $job->done() && microtime( true ) < $deadline ) {
			$job->step();
		}
		if ( $job->done() ) {
			$this->state['phase'] = 'scan';
		}
	}

	/**
	 * Phase: read every header of the archive once — the manifest, where the
	 * database dump sits, and how many files there are to unpack.
	 *
	 * @return void
	 */
	private function step_scan(): void {
		$tar = $this->open();
		$top = $this->wanted_dirs();

		while ( null !== ( $entry = self::next_entry( $tar ) ) ) {
			if ( 'wpicker-backup.json' === $entry['name'] ) {
				$manifest = json_decode( (string) fread( $tar, min( $entry['size'], 1048576 ) ), true );
				if ( ! is_array( $manifest ) || 1 !== (int) ( $manifest['format'] ?? 0 ) ) {
					throw new \RuntimeException( 'This file is not a WPicker backup, or its format is not supported.' );
				}
				$this->state['manifest'] = $manifest;
			} elseif ( 'database.sql' === $entry['name'] ) {
				$this->state['dump_offset'] = $entry['offset'];
				$this->state['dump_size']   = $entry['size'];
			} elseif ( null !== self::content_target( $entry['name'], $top ) ) {
				++$this->state['files_total'];
			}
			self::skip_entry( $tar, $entry );
		}
		fclose( $tar );

		if ( array() === $this->state['manifest'] ) {
			throw new \RuntimeException( 'This file is not a WPicker backup (no manifest).' );
		}
		if ( ! empty( $this->state['manifest']['is_multisite'] ) || is_multisite() ) {
			throw new \RuntimeException( 'Multisite networks are not supported.' );
		}
		if ( 0 === $this->state['dump_size'] ) {
			$this->state['want']['db'] = false;
		}

		$this->drop_tables( self::TEMP_PREFIX );
		$this->state['phase'] = $this->state['want']['db'] ? 'db' : 'files';
	}

	/**
	 * Phase: execute the dump into shadow tables.
	 *
	 * Only the statements the Dumper writes are accepted (SET, DROP TABLE,
	 * CREATE TABLE, INSERT), and only for tables carrying the backup's prefix;
	 * each is redirected to the shadow table. Anything else in the file is
	 * skipped — an uploaded archive is untrusted input.
	 *
	 * @param float $deadline Unix time to stop at.
	 * @return void
	 */
	private function step_db( float $deadline ): void {
		$this->session_settings();
		$source = (string) ( $this->state['manifest']['table_prefix'] ?? '' );
		$tar    = $this->open();
		fseek( $tar, (int) $this->state['dump_offset'] + (int) $this->state['dump_pos'] );

		while ( microtime( true ) < $deadline && $this->state['dump_pos'] < $this->state['dump_size'] ) {
			$line = fgets( $tar );
			if ( false === $line ) {
				break;
			}
			$this->state['dump_pos'] += strlen( $line );
			$sql = rtrim( $line, "\r\n" );
			if ( ';' === substr( $sql, -1 ) ) {
				$sql = substr( $sql, 0, -1 );
			}
			if ( '' === trim( $sql ) ) {
				continue;
			}

			$sql = $this->redirect( $sql, $source );
			if ( null === $sql ) {
				++$this->state['skipped_sql'];
				continue;
			}
			$this->query( $sql );
			++$this->state['statements'];
		}
		fclose( $tar );

		if ( $this->state['dump_pos'] >= $this->state['dump_size'] ) {
			if ( ! in_array( self::TEMP_PREFIX . 'options', $this->tables( self::TEMP_PREFIX ), true ) ) {
				throw new \RuntimeException( 'The backup\'s database has no options table for prefix "' . $source . '".' );
			}
			$this->state['phase'] = 'rewrite';
		}
	}

	/**
	 * Point one dump statement at the shadow table, or reject it.
	 *
	 * @param string $sql    Statement without the trailing semicolon.
	 * @param string $source The backup's table prefix.
	 * @return string|null Null if the statement is not one we accept.
	 */
	private function redirect( string $sql, string $source ): ?string {
		if ( preg_match( '/^SET (NAMES utf8mb4|FOREIGN_KEY_CHECKS=0|SQL_MODE=\'NO_AUTO_VALUE_ON_ZERO\')$/', $sql ) ) {
			return null; // applied per request by session_settings()
		}
		if ( ! preg_match( '/^(DROP TABLE IF EXISTS|CREATE TABLE|INSERT INTO) `([^`]+)`/', $sql, $m ) ) {
			return null;
		}
		if ( '' === $source || 0 !== strpos( $m[2], $source ) ) {
			return null;
		}
		$shadow = self::TEMP_PREFIX . substr( $m[2], strlen( $source ) );
		if ( strlen( $shadow ) > 64 ) {
			return null;
		}
		return $m[1] . ' `' . $shadow . '`' . substr( $sql, strlen( $m[0] ) );
	}

	/**
	 * Phase: replace the backup's URLs and paths in the shadow tables.
	 *
	 * Walks table by table, text column by text column, in primary-key order
	 * so it can stop at the deadline and pick up exactly where it left off.
	 *
	 * @param float $deadline Unix time to stop at.
	 * @return void
	 */
	private function step_rewrite( float $deadline ): void {
		global $wpdb;
		$manifest = $this->state['manifest'];
		$rewriter = new Rewriter(
			array( (string) ( $manifest['site_url'] ?? '' ), (string) ( $manifest['home_url'] ?? '' ) ),
			(string) $this->state['site_url'],
			(string) ( $manifest['abspath'] ?? '' ),
			untrailingslashit( ABSPATH ),
			(string) ( $manifest['content_dir'] ?? '' ),
			untrailingslashit( WP_CONTENT_DIR )
		);
		if ( ! $rewriter->needed() ) {
			$this->state['phase'] = 'files';
			return;
		}

		$this->session_settings();
		$tables = $this->tables( self::TEMP_PREFIX );
		$cursor = &$this->state['rewrite'];

		while ( microtime( true ) < $deadline && $cursor['table'] < count( $tables ) ) {
			$table = $tables[ $cursor['table'] ];
			list( $key, $columns ) = $this->text_columns( $table );

			// Without a single-column primary key rows cannot be walked in key
			// order; such a table is rewritten in one go, value by value.
			if ( null === $key ) {
				$cursor['rows'] += $this->rewrite_keyless( $table, $columns, $rewriter );
			}
			if ( null === $key || $cursor['column'] >= count( $columns ) ) {
				++$cursor['table'];
				$cursor['column'] = 0;
				$cursor['last']   = null;
				continue;
			}
			$column = $columns[ $cursor['column'] ];

			$like = array();
			foreach ( $rewriter->needles() as $needle ) {
				$like[] = '`' . $column . "` LIKE '%" . $wpdb->remove_placeholder_escape( esc_sql( $wpdb->esc_like( $needle ) ) ) . "%'";
			}
			$after = null === $cursor['last'] ? '' : '`' . $key . "` > '" . $wpdb->remove_placeholder_escape( esc_sql( (string) $cursor['last'] ) ) . "' AND ";
			$rows  = $this->select( "SELECT `{$key}` AS k, `{$column}` AS v FROM `{$table}` WHERE {$after}(" . implode( ' OR ', $like ) . ") ORDER BY `{$key}` LIMIT " . self::REWRITE_BATCH );

			foreach ( $rows as $row ) {
				$new = $rewriter->value( (string) $row['v'] );
				if ( $new !== $row['v'] ) {
					$this->query(
						"UPDATE `{$table}` SET `{$column}` = '" . $wpdb->remove_placeholder_escape( esc_sql( $new ) ) . "' WHERE `{$key}` = '" . $wpdb->remove_placeholder_escape( esc_sql( (string) $row['k'] ) ) . "'"
					);
					++$cursor['rows'];
				}
				$cursor['last'] = $row['k'];
			}
			if ( count( $rows ) < self::REWRITE_BATCH ) {
				++$cursor['column'];
				$cursor['last'] = null;
			}
		}
		unset( $cursor );

		if ( $this->state['rewrite']['table'] >= count( $tables ) ) {
			$this->state['phase'] = 'files';
		}
	}

	/**
	 * Rewrite a table that has no single-column primary key.
	 *
	 * Rows cannot be addressed individually, so each distinct value that
	 * needs changing is replaced wherever it occurs. All matching values are
	 * read before any is written: otherwise a value whose replacement still
	 * contains the old address (example.com → dev.example.com) would be
	 * picked up and rewritten again.
	 *
	 * @param string            $table    Table.
	 * @param array<int,string> $columns  Text columns.
	 * @param Rewriter          $rewriter Rewriter.
	 * @return int Rows changed.
	 */
	private function rewrite_keyless( string $table, array $columns, Rewriter $rewriter ): int {
		global $wpdb;
		$changed = 0;
		foreach ( $columns as $column ) {
			$like = array();
			foreach ( $rewriter->needles() as $needle ) {
				$like[] = '`' . $column . "` LIKE '%" . $wpdb->remove_placeholder_escape( esc_sql( $wpdb->esc_like( $needle ) ) ) . "%'";
			}
			$values = $this->select( "SELECT DISTINCT `{$column}` AS v FROM `{$table}` WHERE " . implode( ' OR ', $like ) );
			foreach ( $values as $row ) {
				$old = (string) $row['v'];
				$new = $rewriter->value( $old );
				if ( $new === $old ) {
					continue;
				}
				$this->query(
					"UPDATE `{$table}` SET `{$column}` = '" . $wpdb->remove_placeholder_escape( esc_sql( $new ) ) . "' WHERE BINARY `{$column}` = '" . $wpdb->remove_placeholder_escape( esc_sql( $old ) ) . "'"
				);
				$changed += (int) mysqli_affected_rows( $wpdb->dbh );
			}
		}
		return $changed;
	}

	/**
	 * Phase: unpack wp-content from the archive.
	 *
	 * @param float $deadline Unix time to stop at.
	 * @return void
	 */
	private function step_files( float $deadline ): void {
		$top = $this->wanted_dirs();
		if ( array() === $top ) {
			$this->state['phase'] = $this->state['want']['db'] ? 'swap' : 'done';
			return;
		}

		$tar = $this->open();
		fseek( $tar, (int) $this->state['file_offset'] );
		$finished = false;

		while ( microtime( true ) < $deadline ) {
			$entry = self::next_entry( $tar );
			if ( null === $entry ) {
				$finished = true;
				break;
			}
			$target = self::content_target( $entry['name'], $top );
			if ( null !== $target ) {
				if ( $this->write_file( $tar, $entry, $target ) ) {
					++$this->state['files_done'];
				} else {
					++$this->state['files_skipped'];
				}
				// Recorded even when the write failed: a file the backup has
				// must never be pruned as "not in the backup".
				if ( $this->state['exact'] ) {
					file_put_contents( $this->kept_list(), substr( $entry['name'], strlen( 'wp-content/' ) ) . "\n", FILE_APPEND );
				}
			}
			self::skip_entry( $tar, $entry );
			$this->state['file_offset'] = ftell( $tar );
		}
		fclose( $tar );

		if ( $finished ) {
			$this->state['phase'] = $this->state['exact'] ? 'prune' : ( $this->state['want']['db'] ? 'swap' : 'done' );
		}
	}

	/**
	 * Phase (exact copy only): delete files the backup does not have, so the
	 * restored directories end up identical to the backup.
	 *
	 * Never touched: this plugin's own directory, its storage under uploads
	 * (snapshots and backups, which no backup contains), and symbolic links.
	 *
	 * @return void
	 */
	private function step_prune(): void {
		$content = untrailingslashit( WP_CONTENT_DIR );
		$keep    = is_readable( $this->kept_list() )
			? array_flip( file( $this->kept_list(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) )
			: array();
		$own     = 'plugins/' . dirname( WPICKER_BASENAME ) . '/';

		foreach ( $this->wanted_dirs() as $top ) {
			$base = $content . '/' . $top;
			if ( ! is_dir( $base ) || is_link( $base ) ) {
				continue;
			}
			$filter = new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ),
				static function ( \SplFileInfo $entry ): bool {
					return ! $entry->isLink() && ! ( $entry->isDir() && 'wpicker-vault' === $entry->getFilename() );
				}
			);
			$entries = new \RecursiveIteratorIterator( $filter, \RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $entries as $entry ) {
				$relative = str_replace( DIRECTORY_SEPARATOR, '/', substr( $entry->getPathname(), strlen( $content ) + 1 ) );
				if ( 0 === strpos( $relative . '/', $own ) ) {
					continue;
				}
				if ( $entry->isDir() ) {
					@rmdir( $entry->getPathname() ); // only succeeds once empty
				} elseif ( ! isset( $keep[ $relative ] ) && @unlink( $entry->getPathname() ) ) {
					++$this->state['files_removed'];
				}
			}
		}
		@unlink( $this->kept_list() );
		$this->state['phase'] = $this->state['want']['db'] ? 'swap' : 'done';
	}

	/**
	 * Work file: relative paths of every file the backup contains.
	 *
	 * @return string
	 */
	private function kept_list(): string {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . 'wpicker-vault/restore-' . $this->state['id'] . '.list';
	}

	/**
	 * Copy one archive entry's body to its place under wp-content.
	 *
	 * @param resource                                        $tar    Archive, positioned at the entry body.
	 * @param array{name:string,size:int,mtime:int,type:string,offset:int} $entry  Entry.
	 * @param string                                          $target Absolute destination.
	 * @return bool
	 */
	private function write_file( $tar, array $entry, string $target ): bool {
		if ( ! wp_mkdir_p( dirname( $target ) ) ) {
			return false;
		}
		$tmp = $target . '.wpicker-tmp';
		$out = @fopen( $tmp, 'wb' );
		if ( false === $out ) {
			return false;
		}
		$left = $entry['size'];
		while ( $left > 0 ) {
			$chunk = fread( $tar, (int) min( 1048576, $left ) );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			fwrite( $out, $chunk );
			$left -= strlen( $chunk );
		}
		fclose( $out );
		if ( $left > 0 || ! @rename( $tmp, $target ) ) {
			@unlink( $tmp );
			return false;
		}
		// Keep the original modification time: themes and plugins commonly use
		// it as the ?ver= of their assets, so a changed time changes the HTML.
		if ( $entry['mtime'] > 0 ) {
			@touch( $target, $entry['mtime'] );
		}
		if ( '.php' === substr( $target, -4 ) && function_exists( 'opcache_invalidate' ) ) {
			@opcache_invalidate( $target, true );
		}
		return true;
	}

	/**
	 * Phase: make the shadow tables the live ones.
	 *
	 * Everything that must survive is written into the SHADOW tables first, so
	 * the moment they go live the site is already consistent — including the
	 * record of this job, which the next step request needs to find.
	 *
	 * @return void
	 */
	private function step_swap(): void {
		global $wpdb;
		$this->session_settings();
		$options = self::TEMP_PREFIX . 'options';
		$source  = (string) ( $this->state['manifest']['table_prefix'] ?? '' );

		// 1. This site keeps its own address.
		$this->put_option( $options, 'siteurl', (string) $this->state['site_url'] );
		$this->put_option( $options, 'home', (string) $this->state['home_url'] );

		// 2. This plugin stays active, whatever the backup's plugin list says.
		$active = $this->select( "SELECT option_value AS v FROM `{$options}` WHERE option_name = 'active_plugins'" );
		$list   = isset( $active[0] ) ? @unserialize( (string) $active[0]['v'], array( 'allowed_classes' => false ) ) : array();
		$list   = is_array( $list ) ? array_values( array_filter( $list, 'is_string' ) ) : array();
		if ( ! in_array( WPICKER_BASENAME, $list, true ) ) {
			$list[] = WPICKER_BASENAME;
		}
		// A plugin that forces HTTPS, restored onto a site without HTTPS,
		// redirects every request to an address that does not answer: nobody
		// could get in to switch it off again.
		if ( 0 !== stripos( (string) $this->state['site_url'], 'https://' ) ) {
			$list = array_values( array_diff( $list, self::FORCE_SSL_PLUGINS ) );
		}
		$this->put_option( $options, 'active_plugins', serialize( $list ) );

		// Cached values and rewrite rules computed on the other site.
		// WordPress rebuilds both on the next request.
		$this->query( "DELETE FROM `{$options}` WHERE option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%' OR option_name = 'rewrite_rules'" );

		// 3. A different table prefix is also baked into some option and
		// user-meta names (roles, capabilities).
		if ( $source !== $wpdb->prefix ) {
			$from = $wpdb->remove_placeholder_escape( esc_sql( $source ) );
			$to   = $wpdb->remove_placeholder_escape( esc_sql( $wpdb->prefix ) );
			$len  = strlen( $source ) + 1;
			$like = $wpdb->remove_placeholder_escape( esc_sql( $wpdb->esc_like( $source ) ) );
			$this->query( "UPDATE `{$options}` SET option_name = '{$to}user_roles' WHERE option_name = '{$from}user_roles'" );
			if ( in_array( self::TEMP_PREFIX . 'usermeta', $this->tables( self::TEMP_PREFIX ), true ) ) {
				$this->query( 'UPDATE `' . self::TEMP_PREFIX . "usermeta` SET meta_key = CONCAT('{$to}', SUBSTRING(meta_key, {$len})) WHERE meta_key LIKE '{$like}%'" );
			}
		}

		// 4. What belongs to this site, not to the backup. Done last so the
		// job record already says "done" when it goes live.
		$this->state['phase'] = 'done';
		foreach ( self::KEEP_OPTIONS as $name ) {
			$value = self::OPTION === $name ? $this->state : get_option( $name, null );
			if ( null === $value ) {
				$this->query( "DELETE FROM `{$options}` WHERE option_name = '" . esc_sql( $name ) . "'" );
				continue;
			}
			$this->put_option( $options, $name, maybe_serialize( $value ), 'no' );
		}

		// 5. Exchange, in one statement: for the site this is atomic.
		$this->drop_tables( self::OLD_PREFIX );
		$live    = $this->tables( $wpdb->prefix );
		$renames = array();
		foreach ( $this->tables( self::TEMP_PREFIX ) as $shadow ) {
			$suffix = substr( $shadow, strlen( self::TEMP_PREFIX ) );
			$target = $wpdb->prefix . $suffix;
			if ( in_array( $target, $live, true ) ) {
				$renames[] = "`{$target}` TO `" . self::OLD_PREFIX . "{$suffix}`";
			}
			$renames[] = "`{$shadow}` TO `{$target}`";
		}
		$this->query( 'RENAME TABLE ' . implode( ', ', $renames ) );
		$this->drop_tables( self::OLD_PREFIX );

		wp_cache_flush();
	}

	/**
	 * Insert or replace one row of a (shadow) options table.
	 *
	 * @param string $table    Options table.
	 * @param string $name     Option name.
	 * @param string $value    Raw option value.
	 * @param string $autoload Autoload flag for a new row.
	 * @return void
	 */
	private function put_option( string $table, string $name, string $value, string $autoload = 'yes' ): void {
		global $wpdb;
		$n = $wpdb->remove_placeholder_escape( esc_sql( $name ) );
		$v = $wpdb->remove_placeholder_escape( esc_sql( $value ) );
		$this->query( "INSERT INTO `{$table}` (option_name, option_value, autoload) VALUES ('{$n}', '{$v}', '{$autoload}') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)" );
	}

	/**
	 * Top-level wp-content directories this restore writes to.
	 *
	 * @return array<int,string>
	 */
	private function wanted_dirs(): array {
		$dirs = array();
		foreach ( self::PARTS as $part => $names ) {
			if ( ! empty( $this->state['want'][ $part ] ) ) {
				$dirs = array_merge( $dirs, $names );
			}
		}
		return $dirs;
	}

	/**
	 * Where an archive entry belongs under wp-content, or null if it must not
	 * be written: outside the wanted directories, an unsafe path, or part of
	 * this plugin (replacing the code that is running the restore would end it).
	 *
	 * @param string            $name Entry name.
	 * @param array<int,string> $top  Allowed top-level directories.
	 * @return string|null Absolute path.
	 */
	private static function content_target( string $name, array $top ): ?string {
		if ( 0 !== strpos( $name, 'wp-content/' ) ) {
			return null;
		}
		$relative = substr( $name, strlen( 'wp-content/' ) );
		$parts    = explode( '/', $relative );
		if ( count( $parts ) < 2 || ! in_array( $parts[0], $top, true ) ) {
			return null;
		}
		foreach ( $parts as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part || false !== strpbrk( $part, "\\\0" ) ) {
				return null;
			}
		}
		if ( 'plugins' === $parts[0] && dirname( WPICKER_BASENAME ) === $parts[1] ) {
			return null;
		}
		return untrailingslashit( WP_CONTENT_DIR ) . '/' . $relative;
	}

	/**
	 * Read the next entry header from a tar stream.
	 *
	 * @param resource $tar Archive handle, positioned at a header.
	 * @return array{name:string,size:int,mtime:int,type:string,offset:int}|null Null at the end of the archive.
	 */
	private static function next_entry( $tar ): ?array {
		$long = null;
		while ( true ) {
			$header = fread( $tar, 512 );
			if ( false === $header || strlen( $header ) < 512 || '' === trim( $header, "\0" ) ) {
				return null;
			}
			$name = rtrim( substr( $header, 0, 100 ), "\0" );
			$size  = (int) octdec( trim( substr( $header, 124, 12 ), " \0" ) );
			$mtime = (int) octdec( trim( substr( $header, 136, 12 ), " \0" ) );
			$type  = $header[156];

			if ( 'L' === $type ) {
				// GNU long name: the body is the real path of the next entry.
				$long = rtrim( (string) fread( $tar, $size ), "\0" );
				$pad  = ( 512 - $size % 512 ) % 512;
				if ( $pad > 0 ) {
					fseek( $tar, $pad, SEEK_CUR );
				}
				continue;
			}
			$prefix = rtrim( substr( $header, 345, 155 ), "\0" );
			if ( null !== $long ) {
				$name = $long;
			} elseif ( '' !== $prefix ) {
				$name = $prefix . '/' . $name;
			}
			return array(
				'name'   => $name,
				'size'   => $size,
				'mtime'  => $mtime,
				'type'   => $type,
				'offset' => (int) ftell( $tar ),
			);
		}
	}

	/**
	 * Move past an entry's body (and padding) to the next header, wherever
	 * the handle currently is inside that body.
	 *
	 * @param resource                                        $tar   Archive handle.
	 * @param array{name:string,size:int,mtime:int,type:string,offset:int} $entry Entry.
	 * @return void
	 */
	private static function skip_entry( $tar, array $entry ): void {
		fseek( $tar, $entry['offset'] + $entry['size'] + ( 512 - $entry['size'] % 512 ) % 512 );
	}

	/**
	 * Open the archive.
	 *
	 * @return resource
	 *
	 * @throws \RuntimeException If it cannot be read.
	 */
	private function open() {
		$tar = @fopen( (string) $this->state['archive'], 'rb' );
		if ( false === $tar ) {
			throw new \RuntimeException( 'The backup archive can no longer be read.' );
		}
		return $tar;
	}

	/**
	 * Connection settings the dump expects. A new request is a new database
	 * session, so they are applied at the start of every step that writes.
	 *
	 * @return void
	 */
	private function session_settings(): void {
		$this->query( 'SET NAMES utf8mb4' );
		$this->query( 'SET FOREIGN_KEY_CHECKS=0' );
		$this->query( "SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO'" );
	}

	/**
	 * Run a statement on WordPress's own connection, bypassing $wpdb's query
	 * rewriting; a failure aborts the restore.
	 *
	 * @param string $sql Statement.
	 * @return void
	 *
	 * @throws \RuntimeException On a database error.
	 */
	private function query( string $sql ): void {
		global $wpdb;
		if ( false === mysqli_query( $wpdb->dbh, $sql ) ) {
			throw new \RuntimeException( 'Database error: ' . mysqli_error( $wpdb->dbh ) . ' — in: ' . substr( $sql, 0, 80 ) );
		}
	}

	/**
	 * Run a SELECT and return its rows.
	 *
	 * @param string $sql Query.
	 * @return array<int,array<string,mixed>>
	 *
	 * @throws \RuntimeException On a database error.
	 */
	private function select( string $sql ): array {
		global $wpdb;
		$result = mysqli_query( $wpdb->dbh, $sql );
		if ( false === $result ) {
			throw new \RuntimeException( 'Database error: ' . mysqli_error( $wpdb->dbh ) );
		}
		$rows = mysqli_fetch_all( $result, MYSQLI_ASSOC );
		mysqli_free_result( $result );
		return $rows;
	}

	/**
	 * Tables whose name starts with a prefix.
	 *
	 * @param string $prefix Prefix.
	 * @return array<int,string>
	 */
	private function tables( string $prefix ): array {
		global $wpdb;
		$like = $wpdb->remove_placeholder_escape( esc_sql( $wpdb->esc_like( $prefix ) ) );
		$out  = array();
		foreach ( $this->select( "SHOW TABLES LIKE '{$like}%'" ) as $row ) {
			$out[] = (string) reset( $row );
		}
		sort( $out );
		return $out;
	}

	/**
	 * Drop every table with a prefix (shadow or just-replaced tables).
	 *
	 * @param string $prefix Prefix.
	 * @return void
	 */
	private function drop_tables( string $prefix ): void {
		try {
			foreach ( $this->tables( $prefix ) as $table ) {
				$this->query( "DROP TABLE IF EXISTS `{$table}`" );
			}
		} catch ( \Throwable $e ) {
			// Best effort: leftovers are dropped again when the next restore starts.
			unset( $e );
		}
	}

	/**
	 * A table's single-column primary key and its text-like columns.
	 *
	 * @param string $table Table.
	 * @return array{0:?string,1:array<int,string>}
	 */
	private function text_columns( string $table ): array {
		$keys = array();
		$text = array();
		foreach ( $this->select( "SHOW COLUMNS FROM `{$table}`" ) as $column ) {
			if ( 'PRI' === $column['Key'] ) {
				$keys[] = (string) $column['Field'];
			}
			if ( preg_match( '/char|text|json/i', (string) $column['Type'] ) ) {
				$text[] = (string) $column['Field'];
			}
		}
		return array( 1 === count( $keys ) ? $keys[0] : null, $text );
	}

	/**
	 * What the client may know about the restore.
	 *
	 * @return array<string,mixed>
	 */
	public function progress(): array {
		$s = $this->state;
		return array(
			'id'            => $s['id'],
			'backup_id'     => $s['backup_id'],
			'phase'         => $s['phase'],
			'done'          => 'done' === $s['phase'],
			'failed'        => 'failed' === $s['phase'],
			'error'         => $s['error'],
			'want'          => $s['want'],
			'safety_id'     => $s['safety_id'],
			'source_url'    => (string) ( $s['manifest']['site_url'] ?? '' ),
			'dump_percent'  => $s['dump_size'] > 0 ? (int) floor( 100 * $s['dump_pos'] / $s['dump_size'] ) : 0,
			'statements'    => $s['statements'],
			'skipped_sql'   => $s['skipped_sql'],
			'rows_rewritten' => $s['rewrite']['rows'],
			'files_total'   => $s['files_total'],
			'files_done'    => $s['files_done'],
			'files_skipped' => $s['files_skipped'],
			'files_removed' => (int) ( $s['files_removed'] ?? 0 ),
			'exact'         => ! empty( $s['exact'] ),
			'login_url'     => $s['login_url'],
		);
	}

	/**
	 * Persist the state.
	 *
	 * @return void
	 */
	private function save(): void {
		update_option( self::OPTION, $this->state, false );
	}
}
