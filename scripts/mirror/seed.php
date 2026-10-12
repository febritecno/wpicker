<?php
/**
 * Seeds a site with data that is hard to migrate, for the mirror test
 * (scripts/mirror/run.sh). Run with: wp eval-file scripts/mirror/seed.php
 *
 * Every place WordPress is known to keep its own address is covered, in
 * every spelling: plain, https, protocol-relative, JSON-escaped, URL-encoded,
 * inside PHP-serialized data (nested, objects, multibyte), and absolute paths.
 */

$url  = untrailingslashit( home_url() );
$host = preg_replace( '#^https?://#', '', $url );
$path = untrailingslashit( ABSPATH );

// 1. Site identity with characters that stress SQL escaping and utf8mb4.
update_option( 'blogname', "Toko Kopi “Sénja” & Co ☕ — 100% 'asli' \\ <b>ok</b>" );
update_option( 'blogdescription', "Baris satu\nBaris dua\ttab %s %d" );

// 2. Options: every spelling of the address.
update_option(
	'wpk_mirror_matrix',
	array(
		'plain'      => $url . '/a?x=1&y=2#frag',
		'https'      => 'https://' . $host . '/secure',
		'relative'   => '//' . $host . '/cdn.js',
		'json'       => wp_json_encode( array( 'u' => $url . '/j', 'list' => array( $url . '/1', $url . '/2' ) ) ),
		'encoded'    => rawurlencode( $url . '/enc' ),
		'path'       => $path . '/wp-content/uploads/x.png',
		'multibyte'  => 'é ☕ ' . $url . '/é ☕',
		'email'      => 'admin@' . $host,
		'not_ours'   => 'https://example.org/keep/' . $host . '.html',
		'nested'     => serialize( array( 'deep' => serialize( array( 'deeper' => $url . '/deep' ) ) ) ),
		'object'     => (object) array( 'link' => $url . '/obj', 'n' => 7, 'f' => 1.5, 't' => true, 'z' => null ),
		'www'        => 'http://www.' . $host . '/with-www',
		'attribute'  => '<a href="' . $host . '/no-scheme">x</a> <img src=\'' . $host . '/i.png\'>',
		'urlencode'  => urlencode( $url . '/p q?a=1&b=2' ),
		'rawurl'     => rawurlencode( $url . '/p q?a=1' ),
		'path_enc'   => urlencode( $path . '/wp-content/x' ),
		// Other people's addresses that merely START with ours: must survive untouched.
		'lookalikes' => 'http://' . $host . 'host/x http://' . $host . '.evil.example/y //' . $host . '-cdn.example/z ' . $path . '2/sibling ' . $path . '.bak/old',
		'sentence'   => 'Kunjungi ' . $url . '. Atau ' . $url . ', ya. (' . $url . ')',
		'port'       => $url . ':8080/dev',
		'empty'      => '',
		'numbers'    => array( 0, -1, 3.14, '007' ),
	)
);
update_option( 'wpk_mirror_big', str_repeat( $url . "/x'\"\\\n", 30000 ), false ); // ~700 KB in one row
update_option( 'wpk_mirror_plain_text', "Kunjungi $url hari ini, atau https://$host/promo." );

// 3. Theme mods and widgets (serialized options WordPress itself writes).
set_theme_mod( 'background_image', $url . '/wp-content/uploads/bg.jpg' );
set_theme_mod( 'wpk_logo', array( 'url' => $url . '/logo.svg', 'width' => 120 ) );
update_option( 'widget_text', array( 2 => array( 'title' => 'Info', 'text' => '<a href="' . $url . '/kontak">Kontak</a>' ), '_multiwidget' => 1 ) );

// 4. Content: block markup with JSON attributes, backslashes, quotes.
$content  = '<!-- wp:image {"id":9,"url":"' . $url . '/wp-content/uploads/2026/10/logo-uji.jpg","meta":{"href":"' . str_replace( '/', '\\/', $url ) . '\\/x"}} -->';
$content .= '<figure><img src="' . $url . '/wp-content/uploads/2026/10/logo-uji.jpg" srcset="//' . $host . '/a.jpg 1x"/></figure><!-- /wp:image -->';
$content .= "\n<p>It's 100% \"quoted\" \\ backslash, regex \\d+, path C:\\temp, email admin@$host</p>";
$page = wp_insert_post(
	array(
		'post_type'    => 'page',
		'post_status'  => 'publish',
		'post_title'   => 'Uji Mirror ☕',
		'post_name'    => 'uji-mirror',
		'post_content' => wp_slash( $content ),
	)
);
update_post_meta( $page, '_wpk_builder', wp_slash( array( 'sections' => array( array( 'bg' => $url . '/bg.png', 'cta' => array( 'href' => 'https://' . $host . '/beli' ) ) ) ) ) );
update_post_meta( $page, '_wpk_json', wp_slash( wp_json_encode( array( 'image' => $url . '/i.png' ) ) ) );

// 5. Menu with a custom link, a comment, and a user profile URL.
$menu = wp_create_nav_menu( 'Menu Mirror ' . wp_generate_password( 4, false ) );
if ( ! is_wp_error( $menu ) ) {
	wp_update_nav_menu_item( $menu, 0, array( 'menu-item-title' => 'Promo', 'menu-item-url' => $url . '/promo/', 'menu-item-status' => 'publish' ) );
}
wp_insert_comment( array( 'comment_post_ID' => $page, 'comment_author' => 'Budi', 'comment_author_url' => $url . '/budi', 'comment_content' => 'Lihat ' . $url . '/x', 'comment_approved' => 1 ) );
wp_update_user( array( 'ID' => 1, 'user_url' => $url . '/author' ) );
update_user_meta( 1, 'wpk_profile', array( 'avatar' => $url . '/avatar.png' ) );

// 6. Plugin-style tables: one with a primary key, one WITHOUT any key.
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpk_keyed" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpk_nokey" );
$wpdb->query( "CREATE TABLE {$wpdb->prefix}wpk_keyed (id INT AUTO_INCREMENT PRIMARY KEY, label VARCHAR(190), data LONGTEXT, flag TINYINT NULL, made DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00') DEFAULT CHARSET=utf8mb4" );
$wpdb->query( "CREATE TABLE {$wpdb->prefix}wpk_nokey (label VARCHAR(190), data TEXT) DEFAULT CHARSET=utf8mb4" );
$wpdb->insert( "{$wpdb->prefix}wpk_keyed", array( 'label' => 'serialized', 'data' => serialize( array( 'u' => $url . '/k' ) ) ) );
$wpdb->insert( "{$wpdb->prefix}wpk_keyed", array( 'label' => 'null flag', 'data' => $url . '/n', 'flag' => null ) );
$wpdb->insert( "{$wpdb->prefix}wpk_keyed", array( 'label' => 'empty', 'data' => '', 'flag' => 0 ) );
foreach ( array( 1, 2, 3 ) as $i ) {
	$wpdb->insert( "{$wpdb->prefix}wpk_nokey", array( 'label' => 'row ' . $i, 'data' => $url . '/same-value' ) ); // identical values
}
$wpdb->insert( "{$wpdb->prefix}wpk_nokey", array( 'label' => 'ser', 'data' => serialize( array( 'u' => $url . '/nk' ) ) ) );

echo "seeded: page $page, url $url\n";
