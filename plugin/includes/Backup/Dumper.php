<?php
/**
 * Dumper — writes the site's database tables to a SQL file, a slice at a time.
 *
 * Output format: exactly one SQL statement per line. String values are
 * escaped by the MySQL driver, which turns real newlines into the two
 * characters "\n", so a statement never spans lines. The importer can then
 * execute the file line by line without parsing SQL.
 *
 * @package WPicker\Backup
 */

declare( strict_types = 1 );

namespace WPicker\Backup;

defined( 'ABSPATH' ) || exit;

/**
 * Class Dumper
 */
final class Dumper {

	/**
	 * Rows fetched per query.
	 */
	private const ROWS_PER_QUERY = 500;

	/**
	 * Start a new INSERT statement once the current one reaches this size.
	 */
	private const STATEMENT_BYTES = 262144;

	/**
	 * Base tables that belong to this site (its table prefix).
	 *
	 * @return array<int,string>
	 */
	public function tables(): array {
		global $wpdb;
		$tables = array();
		foreach ( (array) $wpdb->get_results( 'SHOW FULL TABLES', ARRAY_N ) as $row ) {
			if ( 'BASE TABLE' === ( $row[1] ?? '' ) && 0 === strpos( (string) $row[0], $wpdb->prefix ) ) {
				$tables[] = (string) $row[0];
			}
		}
		sort( $tables );
		return $tables;
	}

	/**
	 * Lines every dump starts with: connection settings the importer needs.
	 *
	 * NO_AUTO_VALUE_ON_ZERO replaces strict mode for the session, which is
	 * what lets WordPress's '0000-00-00 00:00:00' defaults load on MySQL 8.
	 *
	 * @return string
	 */
	public function preamble(): string {
		return "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n";
	}

	/**
	 * Statements that drop and recreate one table.
	 *
	 * @param string $table Table name.
	 * @return string
	 */
	public function structure( string $table ): string {
		global $wpdb;
		$name   = self::quote_name( $table );
		$row    = $wpdb->get_row( "SHOW CREATE TABLE {$name}", ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$create = preg_replace( '/\s*\R\s*/', ' ', (string) ( $row[1] ?? '' ) );
		return "DROP TABLE IF EXISTS {$name};\n{$create};\n";
	}

	/**
	 * INSERT statements for one slice of a table's rows.
	 *
	 * @param string $table  Table name.
	 * @param int    $offset Row offset to start at.
	 * @return array{ sql: string, rows: int } rows < ROWS_PER_QUERY means the table is finished.
	 */
	public function rows( string $table, int $offset ): array {
		global $wpdb;
		$name = self::quote_name( $table );
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$name} LIMIT %d, %d", $offset, self::ROWS_PER_QUERY ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		$sql       = '';
		$statement = '';
		foreach ( $rows as $row ) {
			$values = array();
			foreach ( $row as $value ) {
				$values[] = null === $value
					? 'NULL'
					: "'" . $wpdb->remove_placeholder_escape( esc_sql( (string) $value ) ) . "'";
			}
			$tuple = '(' . implode( ',', $values ) . ')';

			if ( '' === $statement ) {
				$statement = "INSERT INTO {$name} VALUES {$tuple}";
			} elseif ( strlen( $statement ) + strlen( $tuple ) > self::STATEMENT_BYTES ) {
				$sql      .= $statement . ";\n";
				$statement = "INSERT INTO {$name} VALUES {$tuple}";
			} else {
				$statement .= ',' . $tuple;
			}
		}
		if ( '' !== $statement ) {
			$sql .= $statement . ";\n";
		}

		return array(
			'sql'  => $sql,
			'rows' => count( $rows ),
		);
	}

	/**
	 * Has this slice reached the end of the table?
	 *
	 * @param int $rows Rows returned by rows().
	 * @return bool
	 */
	public function finished( int $rows ): bool {
		return $rows < self::ROWS_PER_QUERY;
	}

	/**
	 * Backtick-quote a table name.
	 *
	 * @param string $name Table name.
	 * @return string
	 */
	private static function quote_name( string $name ): string {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}
}
