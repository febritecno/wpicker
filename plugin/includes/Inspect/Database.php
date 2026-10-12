<?php
/**
 * Database — read-only queries for diagnosis, with credentials kept out.
 *
 * Layers, outermost first:
 *
 *  1. Statement shape. One statement, no comments, starting with SELECT,
 *     WITH, SHOW, DESCRIBE or EXPLAIN. No INTO (files, variables), no
 *     functions that read files, sleep or take locks, no other schemas'
 *     system tables.
 *  2. Credentials never enter the query. Every reference to the users,
 *     usermeta and options tables is replaced by a sub-select that leaves
 *     out the password columns and the rows holding keys, tokens and salts.
 *     Because the data is excluded at the source, it cannot be reached
 *     through an expression, an alias, a sub-query or a UNION.
 *  3. Tables whose name says they hold credentials (API keys, sessions,
 *     tokens, 2FA secrets) cannot be queried at all.
 *  4. The statement runs inside START TRANSACTION READ ONLY: the database
 *     itself refuses writes, whatever the text says.
 *  5. Results: columns with credential-like names are masked, credentials
 *     embedded in values (serialized, JSON) are masked, rows and cell sizes
 *     are capped.
 *
 * What this cannot do: recognise a secret a plugin stores in its own table
 * under an innocent name. Layers 3 and 5 are name-based and best effort.
 *
 * @package WPicker\Inspect
 */

declare( strict_types = 1 );

namespace WPicker\Inspect;

defined( 'ABSPATH' ) || exit;

/**
 * Class Database
 */
final class Database {

	/**
	 * Default and maximum number of rows returned.
	 */
	public const DEFAULT_ROWS = 100;
	public const MAX_ROWS     = 1000;

	/**
	 * Longest cell returned; longer values are cut (and say so).
	 */
	private const MAX_CELL = 4000;

	/**
	 * Statement time limit in milliseconds.
	 */
	private const TIMEOUT_MS = 8000;

	/**
	 * First keywords accepted.
	 */
	private const STATEMENTS = array( 'SELECT', 'WITH', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN' );

	/**
	 * Words that may not appear anywhere outside a string literal.
	 * INTO covers OUTFILE, DUMPFILE and variable assignment.
	 */
	private const FORBIDDEN = array(
		'INTO', 'LOAD_FILE', 'SLEEP', 'BENCHMARK', 'GET_LOCK', 'RELEASE_LOCK', 'IS_FREE_LOCK', 'IS_USED_LOCK', 'MASTER_POS_WAIT', 'HANDLER', 'PROCEDURE',
		// The read-only transaction already refuses these; naming them gives a
		// clear message instead of a database error. (A statement cannot START
		// with them at all, which is what keeps DDL — not covered by a
		// read-only transaction — out.)
		'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'DROP', 'ALTER', 'GRANT', 'REVOKE', 'RENAME', 'CALL', 'LOAD', 'KILL', 'FLUSH', 'RESET', 'PURGE', 'SHUTDOWN', 'INSTALL', 'UNINSTALL',
	);

	/**
	 * What may follow SHOW. Leaves out GRANTS, PROCESSLIST and CREATE USER,
	 * which expose database accounts and other sessions' queries.
	 */
	private const SHOW_ALLOWED = array( 'TABLES', 'FULL', 'COLUMNS', 'FIELDS', 'INDEX', 'INDEXES', 'KEYS', 'CREATE', 'TABLE', 'VARIABLES', 'STATUS', 'GLOBAL', 'SESSION', 'COLLATION', 'CHARACTER', 'CHARSET', 'ENGINES', 'WARNINGS', 'ERRORS', 'TRIGGERS', 'EXTENDED' );

	/**
	 * Schemas whose tables hold server credentials or internals.
	 */
	private const FORBIDDEN_SCHEMAS = array( 'mysql', 'performance_schema', 'sys' );

	/**
	 * Table names (after the prefix) that hold credentials by design.
	 */
	private const DENIED_TABLE = '/(api_?keys?|sessions?|tokens?|passwords?|oauth|secrets?|wfconfig|2fa|otp|credentials?)/i';

	/**
	 * Words that end a table reference (so what follows is not an alias).
	 */
	private const AFTER_TABLE = array( 'WHERE', 'JOIN', 'LEFT', 'RIGHT', 'INNER', 'OUTER', 'CROSS', 'NATURAL', 'STRAIGHT_JOIN', 'ON', 'USING', 'GROUP', 'ORDER', 'LIMIT', 'HAVING', 'UNION', 'EXCEPT', 'INTERSECT', 'WINDOW', 'FOR', 'LOCK', 'PARTITION', 'USE', 'IGNORE', 'FORCE' );

	/**
	 * The sub-selects that stand in for the three core tables that mix
	 * ordinary data with credentials.
	 *
	 * @return array<string,string> Table name => sub-select (without alias).
	 */
	private static function safe_sources(): array {
		global $wpdb;
		$secret = "'" . Redactor::NAME . "'";
		return array(
			$wpdb->users    => "(SELECT ID, user_login, user_nicename, user_email, user_url, user_registered, user_status, display_name FROM `{$wpdb->users}`)",
			$wpdb->usermeta => "(SELECT * FROM `{$wpdb->usermeta}` WHERE meta_key NOT IN ('session_tokens', '_application_passwords') AND LOWER(meta_key) NOT REGEXP {$secret})",
			// wpicker_* options hold device records, backup locations and
			// the restore token; none of it is the reader's business.
			$wpdb->options  => "(SELECT * FROM `{$wpdb->options}` WHERE LOWER(option_name) NOT REGEXP {$secret} AND option_name NOT REGEXP '^(auth|secure_auth|logged_in|nonce)_key\$' AND option_name NOT LIKE 'wpicker\\_%' AND option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%')",
		);
	}

	/**
	 * Check a statement and rewrite it so credentials are out of reach.
	 *
	 * @param string $sql Statement as typed.
	 * @return array{ sql: string }|array{ error: string }
	 */
	public static function guard( string $sql ): array {
		$sql = trim( $sql );
		$sql = rtrim( $sql, "; \t\r\n" );
		if ( '' === $sql ) {
			return array( 'error' => 'Empty query.' );
		}
		if ( strlen( $sql ) > 20000 ) {
			return array( 'error' => 'Query too long.' );
		}

		$tokens = self::tokenize( $sql );
		if ( null === $tokens ) {
			return array( 'error' => 'Unterminated string or identifier.' );
		}

		$words = array();
		foreach ( $tokens as $i => $token ) {
			if ( 'other' === $token['type'] ) {
				if ( ';' === $token['text'] ) {
					return array( 'error' => 'Only one statement at a time.' );
				}
				$pair = $token['text'] . ( $tokens[ $i + 1 ]['text'] ?? '' );
				if ( '#' === $token['text'] || 0 === strpos( $pair, '--' ) || 0 === strpos( $pair, '/*' ) ) {
					return array( 'error' => 'Comments are not allowed in a query.' );
				}
			}
			if ( 'word' === $token['type'] ) {
				$words[] = strtoupper( $token['text'] );
			}
		}

		$first = $words[0] ?? '';
		if ( ! in_array( $first, self::STATEMENTS, true ) ) {
			return array( 'error' => 'Only SELECT, WITH, SHOW, DESCRIBE and EXPLAIN are allowed.' );
		}
		foreach ( self::FORBIDDEN as $word ) {
			if ( in_array( $word, $words, true ) ) {
				return array( 'error' => $word . ' is not allowed in a diagnostic query.' );
			}
		}
		if ( 'SHOW' === $first ) {
			$second = $words[1] ?? '';
			$third  = $words[2] ?? '';
			if ( ! in_array( $second, self::SHOW_ALLOWED, true ) || ( 'CREATE' === $second && 'TABLE' !== $third ) ) {
				return array( 'error' => 'SHOW ' . $second . ' is not available. Try SHOW TABLES, SHOW COLUMNS FROM <table>, SHOW CREATE TABLE <table>, SHOW INDEX FROM <table>.' );
			}
		}

		global $wpdb;
		// Table names are matched without regard to case: on servers with
		// case-insensitive table names WP_USERS is the same table as wp_users.
		$sources = array_change_key_case( self::safe_sources(), CASE_LOWER );
		$prefix  = strtolower( $wpdb->prefix );
		$rewrite = in_array( $first, array( 'SELECT', 'WITH' ), true );
		$count   = count( $tokens );
		$out     = '';

		for ( $i = 0; $i < $count; $i++ ) {
			$token = $tokens[ $i ];
			// With the ANSI_QUOTES SQL mode a double-quoted token is an
			// identifier, not a string. Never let one name a guarded table.
			if ( 'string' === $token['type'] && '"' === $token['text'][0] ) {
				$inside = strtolower( substr( $token['text'], 1, -1 ) );
				if ( isset( $sources[ $inside ] ) || ( 0 === strpos( $inside, $prefix ) && 1 === preg_match( self::DENIED_TABLE, substr( $inside, strlen( $prefix ) ) ) ) ) {
					return array( 'error' => 'Quote table names with backticks, not double quotes.' );
				}
			}
			if ( 'word' !== $token['type'] && 'quoted' !== $token['type'] ) {
				$out .= $token['text'];
				continue;
			}
			$shown = 'quoted' === $token['type'] ? str_replace( '``', '`', substr( $token['text'], 1, -1 ) ) : $token['text'];
			$name  = strtolower( $shown );
			$prev = self::neighbour( $tokens, $i, -1 );
			$next = self::neighbour( $tokens, $i, 1 );

			// schema.table — only this database and information_schema.
			if ( null !== $next && '.' === $next['text'] && in_array( strtolower( $name ), self::FORBIDDEN_SCHEMAS, true ) ) {
				return array( 'error' => 'The ' . strtolower( $name ) . ' schema is not available.' );
			}

			$is_ours = 0 === strpos( $name, $prefix );
			if ( $is_ours && ! isset( $sources[ $name ] ) && 1 === preg_match( self::DENIED_TABLE, substr( $name, strlen( $prefix ) ) ) ) {
				return array( 'error' => 'Table ' . $shown . ' holds credentials by its name and cannot be queried.' );
			}

			if ( ! $rewrite || ! isset( $sources[ $name ] ) ) {
				$out .= $token['text'];
				continue;
			}
			// table.column — a qualifier, not a table reference. The
			// reference it points at carries the same name as its alias.
			if ( null !== $next && '.' === $next['text'] ) {
				$out .= $token['text'];
				continue;
			}
			if ( null !== $prev && '.' === $prev['text'] ) {
				return array( 'error' => 'Refer to ' . $shown . ' without a database name.' );
			}

			$out .= $sources[ $name ];
			$has_alias = null !== $next
				&& ( 'quoted' === $next['type'] || ( 'word' === $next['type'] && ! in_array( strtoupper( $next['text'] ), self::AFTER_TABLE, true ) ) );
			if ( ! $has_alias ) {
				$out .= ' AS `' . str_replace( '`', '``', $shown ) . '`';
			}
		}

		return array( 'sql' => $out );
	}

	/**
	 * Run a guarded statement.
	 *
	 * @param string $sql   Statement as typed.
	 * @param int    $limit Maximum rows to return.
	 * @return array<string,mixed>|array{ error: string }
	 */
	public static function query( string $sql, int $limit ): array {
		global $wpdb;
		$guarded = self::guard( $sql );
		if ( isset( $guarded['error'] ) ) {
			return $guarded;
		}
		$limit = max( 1, min( self::MAX_ROWS, $limit ) );
		$dbh   = $wpdb->dbh;

		// Best effort: MySQL and MariaDB spell the statement timeout differently.
		@mysqli_query( $dbh, 'SET SESSION MAX_EXECUTION_TIME=' . self::TIMEOUT_MS );
		@mysqli_query( $dbh, 'SET SESSION max_statement_time=' . ( self::TIMEOUT_MS / 1000 ) );

		if ( false === mysqli_query( $dbh, 'START TRANSACTION READ ONLY' ) ) {
			return array( 'error' => 'This database server cannot open a read-only transaction; diagnostic queries are unavailable.' );
		}
		$started = microtime( true );
		$result  = mysqli_query( $dbh, $guarded['sql'] );
		$error   = false === $result ? mysqli_error( $dbh ) : '';

		$columns   = array();
		$rows      = array();
		$truncated = false;
		if ( $result instanceof \mysqli_result ) {
			$mask = array();
			foreach ( mysqli_fetch_fields( $result ) as $field ) {
				$columns[] = $field->name;
				$mask[]    = Redactor::sensitive_name( $field->name ) || Redactor::sensitive_name( (string) $field->orgname );
			}
			while ( $row = mysqli_fetch_row( $result ) ) {
				if ( count( $rows ) >= $limit ) {
					$truncated = true;
					break;
				}
				foreach ( $row as $i => $cell ) {
					$row[ $i ] = self::cell( $cell, $mask[ $i ] );
				}
				$rows[] = $row;
			}
			mysqli_free_result( $result );
		}
		mysqli_query( $dbh, 'ROLLBACK' );

		if ( '' !== $error ) {
			return array( 'error' => 'Database error: ' . $error );
		}
		return array(
			'columns'   => $columns,
			'rows'      => $rows,
			'count'     => count( $rows ),
			'truncated' => $truncated,
			'limit'     => $limit,
			'ms'        => (int) round( 1000 * ( microtime( true ) - $started ) ),
		);
	}

	/**
	 * Tables of this site, with what a reader needs to know about each.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function tables(): array {
		global $wpdb;
		$sources = self::safe_sources();
		$out     = array();
		$rows    = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME AS name, TABLE_ROWS AS approx_rows, ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024) AS kb FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE %s ORDER BY TABLE_NAME',
				$wpdb->esc_like( $wpdb->prefix ) . '%'
			),
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$name   = (string) $row['name'];
			$access = 'full';
			if ( isset( $sources[ $name ] ) ) {
				$access = 'filtered'; // credentials left out
			} elseif ( 1 === preg_match( self::DENIED_TABLE, substr( $name, strlen( $wpdb->prefix ) ) ) ) {
				$access = 'denied';
			}
			$out[] = array(
				'name'        => $name,
				'approx_rows' => (int) $row['approx_rows'],
				'kb'          => (int) $row['kb'],
				'access'      => $access,
			);
		}
		return $out;
	}

	/**
	 * Prepare one result cell for output.
	 *
	 * @param string|null $cell Raw value.
	 * @param bool        $mask Whether the column is credential-named.
	 * @return string|null
	 */
	private static function cell( $cell, bool $mask ) {
		if ( null === $cell ) {
			return null;
		}
		if ( $mask ) {
			return '' === $cell ? '' : Redactor::MASK;
		}
		$cell = (string) $cell;
		if ( false !== strpos( $cell, "\0" ) || ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $cell, 'UTF-8' ) ) ) {
			return '[binary, ' . strlen( $cell ) . ' bytes]';
		}
		$cell = Redactor::scrub( $cell );
		if ( strlen( $cell ) > self::MAX_CELL ) {
			$cut  = function_exists( 'mb_strcut' ) ? mb_strcut( $cell, 0, self::MAX_CELL, 'UTF-8' ) : substr( $cell, 0, self::MAX_CELL );
			$cell = $cut . '… [' . ( strlen( $cell ) - strlen( $cut ) ) . ' more bytes]';
		}
		return $cell;
	}

	/**
	 * The nearest token that is not whitespace, before or after position $i.
	 *
	 * @param array<int,array{type:string,text:string}> $tokens Tokens.
	 * @param int                                        $i      Position.
	 * @param int                                        $step   -1 or 1.
	 * @return array{type:string,text:string}|null
	 */
	private static function neighbour( array $tokens, int $i, int $step ): ?array {
		for ( $j = $i + $step; isset( $tokens[ $j ] ); $j += $step ) {
			if ( 'space' !== $tokens[ $j ]['type'] ) {
				return $tokens[ $j ];
			}
		}
		return null;
	}

	/**
	 * Split a statement into words, quoted identifiers, string literals,
	 * whitespace and single other characters — enough to tell a table name
	 * from the same letters inside a string.
	 *
	 * @param string $sql Statement.
	 * @return array<int,array{type:string,text:string}>|null Null if a quote is never closed.
	 */
	private static function tokenize( string $sql ): ?array {
		$tokens = array();
		$len    = strlen( $sql );
		$i      = 0;
		while ( $i < $len ) {
			$c = $sql[ $i ];

			if ( "'" === $c || '"' === $c || '`' === $c ) {
				$j = $i + 1;
				while ( true ) {
					if ( $j >= $len ) {
						return null;
					}
					if ( '\\' === $sql[ $j ] && '`' !== $c ) {
						$j += 2; // backslash escape inside a string
						continue;
					}
					if ( $sql[ $j ] === $c ) {
						if ( ( $sql[ $j + 1 ] ?? '' ) === $c ) {
							$j += 2; // doubled quote
							continue;
						}
						break;
					}
					++$j;
				}
				$tokens[] = array(
					'type' => '`' === $c ? 'quoted' : 'string',
					'text' => substr( $sql, $i, $j - $i + 1 ),
				);
				$i = $j + 1;
				continue;
			}

			if ( 1 === preg_match( '/\G[A-Za-z0-9_$\x80-\xff]+/', $sql, $m, 0, $i ) ) {
				$tokens[] = array(
					'type' => 'word',
					'text' => $m[0],
				);
				$i += strlen( $m[0] );
				continue;
			}
			if ( 1 === preg_match( '/\G\s+/', $sql, $m, 0, $i ) ) {
				$tokens[] = array(
					'type' => 'space',
					'text' => $m[0],
				);
				$i += strlen( $m[0] );
				continue;
			}
			$tokens[] = array(
				'type' => 'other',
				'text' => $c,
			);
			++$i;
		}
		return $tokens;
	}
}
