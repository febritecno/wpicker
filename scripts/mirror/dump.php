<?php
/**
 * Writes a normalised dump of this site's database for the mirror test.
 * Run with: WPK_DUMP_OUT=/path/out.json wp eval-file scripts/mirror/dump.php
 *
 * "Normalised" means every spelling of THIS site's address and install path
 * is replaced by a placeholder, so the dumps of two sites that are true
 * copies of each other come out identical.
 *
 * This deliberately does not use the plugin's Rewriter: it unserializes each
 * value with PHP itself and walks the result. A value the migration damaged
 * fails to unserialize here and is reported as BROKEN.
 */

global $wpdb;
$url  = untrailingslashit( home_url() );
$host = preg_replace( '#^https?://#', '', $url );
$path = untrailingslashit( ABSPATH );
$esc  = static function ( $s ) {
	return str_replace( '/', '\\/', $s );
};
$enc  = static function ( $s ) {
	return str_replace( array( ':', '/' ), array( '%3A', '%2F' ), $s );
};
$map = array();
foreach ( array( $host, 0 === stripos( $host, 'www.' ) ? substr( $host, 4 ) : 'www.' . $host ) as $h ) {
	foreach ( array( 'https://', 'http://' ) as $scheme ) {
		$map[ $scheme . $h ]                = '{{URL}}';
		$map[ $esc( $scheme . $h ) ]        = '{{URL_JSON}}';
		$map[ urlencode( $scheme . $h ) ]   = '{{URL_ENC}}';
	}
	$map[ '//' . $h ]         = '//{{HOST}}';
	$map[ $esc( '//' . $h ) ] = '\\/\\/{{HOST}}';
	$map[ '="' . $h ]         = '="{{HOST}}';
	$map[ "='" . $h ]         = "='{{HOST}}";
}
$map[ $path ]              = '{{PATH}}';
$map[ $esc( $path ) ]      = '{{PATH_JSON}}';
$map[ urlencode( $path ) ] = '{{PATH_ENC}}';

// Longest first, and only where the address really ends — "host.evil.example"
// is somebody else's site on both sides and must compare as itself.
uksort(
	$map,
	static function ( $a, $b ) {
		return strlen( $b ) <=> strlen( $a );
	}
);
$pattern = '~(?:' . implode( '|', array_map( static function ( $k ) {
	return preg_quote( $k, '~' );
}, array_keys( $map ) ) ) . ')(?![A-Za-z0-9_\\-]|\\.[A-Za-z0-9])~';
$norm    = static function ( $s ) use ( $map, $pattern ) {
	return preg_replace_callback(
		$pattern,
		static function ( $m ) use ( $map ) {
			return $map[ $m[0] ];
		},
		$s
	);
};

$broken = array();
$walk   = static function ( $value ) use ( &$walk, $norm, &$broken ) {
	if ( is_string( $value ) ) {
		if ( is_serialized( $value ) ) {
			$inner = @unserialize( trim( $value ) );
			if ( false === $inner && 'b:0;' !== trim( $value ) ) {
				$broken[] = substr( $value, 0, 120 );
				return array( 'BROKEN' => $norm( $value ) );
			}
			return array( 'SERIALIZED' => $walk( $inner ) );
		}
		return $norm( $value );
	}
	if ( is_array( $value ) ) {
		$out = array();
		foreach ( $value as $k => $v ) {
			$out[ is_string( $k ) ? $norm( $k ) : $k ] = $walk( $v );
		}
		return $out;
	}
	if ( is_object( $value ) ) {
		return array( 'OBJECT:' . get_class( $value ) => $walk( get_object_vars( $value ) ) );
	}
	return $value; // int, float, bool, null keep their type
};

// Rows that legitimately differ between two live sites, or that belong to
// the site being restored INTO by design. Everything else must match.
// rewrite_rules is a cache: a restore drops it and WordPress rebuilds it.
$skip_options  = '/^(_transient_|_site_transient_|cron$|rewrite_rules$|recently_edited$|auto_updater\.lock$|wpicker_(settings|backups|restore|vault_manifests)$)/';
$skip_usermeta = '/^(session_tokens|.*user-settings-time|.*dashboard_quick_press_last_post_id)$/';

$tables = array();
foreach ( $wpdb->get_col( 'SHOW TABLES LIKE \'' . esc_sql( $wpdb->esc_like( $wpdb->prefix ) ) . '%\'' ) as $table ) {
	$short = substr( $table, strlen( $wpdb->prefix ) );
	$rows  = array();
	foreach ( $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A ) as $row ) {
		if ( 'options' === $short && preg_match( $skip_options, $row['option_name'] ) ) {
			continue;
		}
		if ( 'usermeta' === $short && preg_match( $skip_usermeta, $row['meta_key'] ) ) {
			continue;
		}
		$norm = array();
		foreach ( $row as $column => $value ) {
			$norm[ $column ] = null === $value ? null : $walk( (string) $value );
		}
		$rows[] = wp_json_encode( $norm, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR );
	}
	sort( $rows ); // tables without a key have no natural order
	$tables[ $short ] = $rows;
}

file_put_contents(
	getenv( 'WPK_DUMP_OUT' ),
	wp_json_encode(
		array(
			'site'   => $url,
			'name'   => get_option( 'blogname' ),
			'broken' => $broken,
			'tables' => $tables,
		)
	)
);
echo 'dumped ' . count( $tables ) . ' tables, ' . array_sum( array_map( 'count', $tables ) ) . ' rows, ' . count( $broken ) . " broken\n";
