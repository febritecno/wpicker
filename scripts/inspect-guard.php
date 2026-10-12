<?php
/**
 * Attack-style checks for the diagnostic query guard (Inspect\Database).
 *
 * Needs a real database: run inside a site with
 *   wp eval-file scripts/inspect-guard.php
 * It creates and removes a few wpk_test_* options and user-meta rows.
 */
use WPicker\Inspect\Database as D;
global $wpdb;
$p    = $wpdb->prefix;
$fail = 0;
$hash = $wpdb->get_var( "SELECT user_pass FROM {$wpdb->users} LIMIT 1" );
// a secret option and secret user meta that must never come back
update_option( 'wpk_test_api_key', 'SECRET-OPTION-VALUE' );
update_option( 'wpk_test_plain', 'visible-option' );
update_user_meta( 1, 'wpk_test_token', 'SECRET-META-VALUE' );
update_user_meta( 1, 'wpk_test_nick', 'visible-meta' );
update_option( 'wpk_test_nested', array( 'label' => 'ok', 'client_secret' => 'SECRET-NESTED' ) );

$leaks = array( $hash, 'SECRET-OPTION-VALUE', 'SECRET-META-VALUE', 'SECRET-NESTED' );
$run   = static function ( $sql ) use ( $leaks, &$fail ) {
	$r    = D::query( $sql, 500 );
	$blob = wp_json_encode( $r );
	foreach ( $leaks as $leak ) {
		if ( '' !== (string) $leak && false !== strpos( $blob, (string) $leak ) ) {
			++$fail;
			echo "LEAK  $sql\n";
			return $r;
		}
	}
	return $r;
};
$expect_rows = static function ( $label, $r, $min = 1 ) use ( &$fail ) {
	if ( isset( $r['error'] ) || $r['count'] < $min ) { ++$fail; echo "FAIL  $label: " . ( $r['error'] ?? 'rows=' . $r['count'] ) . "\n"; }
};
$expect_err = static function ( $label, $r ) use ( &$fail ) {
	if ( ! isset( $r['error'] ) ) { ++$fail; echo "FAIL  $label: was allowed\n"; }
};

// ---- must work
$expect_rows( 'plain select', $run( "SELECT ID, post_title FROM {$p}posts LIMIT 3" ) );
$expect_rows( 'users star', $run( "SELECT * FROM {$p}users" ) );
$expect_rows( 'users alias + join', $run( "SELECT u.user_login, m.meta_key, m.meta_value FROM {$p}users u JOIN {$p}usermeta AS m ON m.user_id = u.ID" ) );
$expect_rows( 'users qualified, no alias', $run( "SELECT {$p}users.user_login FROM {$p}users WHERE {$p}users.ID = 1" ) );
$expect_rows( 'backticks', $run( "SELECT `ID` FROM `{$p}users`" ) );
$expect_rows( 'options visible row', $run( "SELECT option_value FROM {$p}options WHERE option_name = 'wpk_test_plain'" ) );
$expect_rows( 'comma join', $run( "SELECT COUNT(*) FROM {$p}users, {$p}posts" ) );
$expect_rows( 'cte', $run( "WITH t AS (SELECT ID FROM {$p}posts) SELECT COUNT(*) FROM t" ) );
$expect_rows( 'show tables', $run( 'SHOW TABLES' ) );
$expect_rows( 'describe users', $run( "DESCRIBE {$p}users" ) );
$expect_rows( 'show create', $run( "SHOW CREATE TABLE {$p}options" ) );
$expect_rows( 'explain', $run( "EXPLAIN SELECT * FROM {$p}users" ) );
$expect_rows( 'string that names a table', $run( "SELECT '{$p}users; DROP TABLE x -- ' AS s" ) );
$expect_rows( 'replace() function', $run( "SELECT REPLACE(post_title, 'a', 'b') FROM {$p}posts LIMIT 1" ) );
$expect_rows( 'information_schema', $run( "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$p}users'" ) );

// ---- credentials must not come back, whatever the route
$run( "SELECT user_pass FROM {$p}users" );
$run( "SELECT CONCAT(user_pass, '') FROM {$p}users" );
$run( "SELECT * FROM (SELECT * FROM {$p}users) t" );
$run( "SELECT ID, post_title, post_name, post_content, post_excerpt, guid, post_type, post_status FROM {$p}posts UNION SELECT * FROM {$p}users" );
$run( "SELECT * FROM " . strtoupper( $p ) . "USERS" );
$run( "SELECT * FROM {$p}options WHERE option_name LIKE 'wpk_test%'" );
$run( "SELECT GROUP_CONCAT(option_value) FROM {$p}options" );
$run( "SELECT * FROM {$p}usermeta" );
$run( "SELECT meta_value FROM {$p}usermeta WHERE meta_key IN ('session_tokens', '_application_passwords', 'wpk_test_token')" );
$run( "SELECT (SELECT user_pass FROM {$p}users LIMIT 1) AS x" );
$run( "SELECT u.* FROM {$p}posts p LEFT JOIN {$p}users u ON u.ID = p.post_author" );
$run( "SELECT option_value FROM {$p}options WHERE option_name = 'wpk_test_nested'" );
$r = D::query( "SELECT option_value FROM {$p}options WHERE option_name = 'wpk_test_api_key'", 10 );
if ( ! isset( $r['count'] ) || 0 !== $r['count'] ) { ++$fail; echo "FAIL  secret option row should not exist for the reader\n"; }
$r = D::query( "SELECT option_value FROM {$p}options WHERE option_name = 'wpk_test_nested'", 10 );
if ( false === strpos( (string) ( $r['rows'][0][0] ?? '' ), 'ok' ) ) { ++$fail; echo "FAIL  nested option should keep its non-secret part\n"; }

// ---- must be refused
$expect_err( 'update', D::query( "UPDATE {$p}options SET option_value = 'x' WHERE option_name = 'wpk_test_plain'", 1 ) );
$expect_err( 'delete', D::query( "DELETE FROM {$p}posts", 1 ) );
$expect_err( 'drop', D::query( "DROP TABLE {$p}posts", 1 ) );
$expect_err( 'two statements', D::query( "SELECT 1; DELETE FROM {$p}posts", 1 ) );
$expect_err( 'into outfile', D::query( "SELECT * FROM {$p}posts INTO OUTFILE '/tmp/x'", 1 ) );
$expect_err( 'load_file', D::query( "SELECT LOAD_FILE('/etc/passwd')", 1 ) );
$expect_err( 'sleep', D::query( 'SELECT SLEEP(5)', 1 ) );
$expect_err( 'comment', D::query( "SELECT 1 /* x */", 1 ) );
$expect_err( 'dash comment', D::query( "SELECT 1 -- x", 1 ) );
$expect_err( 'mysql schema', D::query( 'SELECT * FROM mysql.user', 1 ) );
$expect_err( 'show grants', D::query( 'SHOW GRANTS', 1 ) );
$expect_err( 'show processlist', D::query( 'SHOW PROCESSLIST', 1 ) );
$expect_err( 'double-quoted table', D::query( "SELECT * FROM \"{$p}users\"", 1 ) );
$expect_err( 'db-qualified users', D::query( 'SELECT * FROM ' . DB_NAME . ".{$p}users", 1 ) );
$expect_err( 'denied table name', D::query( "SELECT * FROM {$p}woocommerce_api_keys", 1 ) );
$expect_err( 'cte write', D::query( "WITH t AS (SELECT 1) DELETE FROM {$p}posts", 1 ) );
$expect_err( 'unterminated', D::query( "SELECT 'abc", 1 ) );

// the read-only transaction is the last line of defence: prove it holds on its own
mysqli_query( $wpdb->dbh, 'START TRANSACTION READ ONLY' );
$ok = mysqli_query( $wpdb->dbh, "UPDATE {$p}options SET option_value = 'CHANGED' WHERE option_name = 'wpk_test_plain'" );
$err = mysqli_error( $wpdb->dbh );
mysqli_query( $wpdb->dbh, 'ROLLBACK' );
wp_cache_delete( 'wpk_test_plain', 'options' );
if ( false !== $ok || 'visible-option' !== $wpdb->get_var( "SELECT option_value FROM {$wpdb->options} WHERE option_name = 'wpk_test_plain'" ) ) { ++$fail; echo "FAIL  read-only transaction let a write through\n"; }
echo 'read-only transaction refuses writes: ' . $err . "\n";

foreach ( array( 'wpk_test_api_key', 'wpk_test_plain', 'wpk_test_nested' ) as $o ) { delete_option( $o ); }
delete_user_meta( 1, 'wpk_test_token' ); delete_user_meta( 1, 'wpk_test_nick' );
echo $fail ? "$fail PROBLEM(S)\n" : "database guard: all checks pass\n";
