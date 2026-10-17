<?php

declare( strict_types = 1 );

use PHPUnit\Framework\TestCase;
use WPicker\Inspect\Map;
use WPicker\Inspect\Symbols;

final class TestSymbols extends TestCase {

	/**
	 * @param array<int,array> $rows Rows.
	 * @return array<int,string> "kind:name" strings.
	 */
	private function names( array $rows ): array {
		return array_map( static fn( $r ) => $r[0] . ':' . $r[1], $rows );
	}

	public function test_functions_classes_and_methods(): void {
		$code = <<<'PHP'
<?php
function shop_total( $a ) {}
class Cart extends Base {
	public function add( $x ) {}
	private static function &ref() {}
}
interface Gateway {}
trait Loggable {}
PHP;
		$names = $this->names( Symbols::extract( $code ) );
		$this->assertContains( 'function:shop_total', $names );
		$this->assertContains( 'class:Cart', $names );
		$this->assertContains( 'method:Cart::add', $names );
		$this->assertContains( 'method:Cart::ref', $names );
		$this->assertContains( 'class:Gateway', $names );
		$this->assertContains( 'class:Loggable', $names );
	}

	public function test_function_after_a_class_is_not_a_method(): void {
		$names = $this->names( Symbols::extract( "<?php\nclass A { function m() { if (1) { } } }\nfunction free() {}\n" ) );
		$this->assertContains( 'method:A::m', $names );
		$this->assertContains( 'function:free', $names );
	}

	public function test_closures_are_not_functions(): void {
		$names = $this->names( Symbols::extract( "<?php\n\$f = function () {};\n\$g = function &( \$x ) {};\n" ) );
		$this->assertSame( array(), $names );
	}

	public function test_hooks_and_what_they_do(): void {
		$code  = "<?php\nadd_action( 'init', 'f' );\nadd_filter( \"the_content\", 'g' );\ndo_action( 'my_event' );\napply_filters( 'my_filter', \$x );\nremove_action( 'wp_head', 'h' );\n";
		$rows  = Symbols::extract( $code );
		$by    = array();
		foreach ( $rows as $r ) {
			$by[ $r[1] ] = $r[3];
		}
		$this->assertSame( 'listens', $by['init'] );
		$this->assertSame( 'listens', $by['the_content'] );
		$this->assertSame( 'fires', $by['my_event'] );
		$this->assertSame( 'fires', $by['my_filter'] );
		$this->assertSame( 'removes', $by['wp_head'] );
	}

	public function test_shortcodes_rest_routes_post_types_options_constants(): void {
		$code  = "<?php\nadd_shortcode( 'cart', 'f' );\nregister_rest_route( 'shop/v1', '/orders/(?P<id>\\d+)', array() );\nregister_post_type( 'book', array() );\nregister_taxonomy( 'genre', 'book' );\nget_option( 'shop_settings' );\nupdate_option( 'shop_settings', 1 );\ndefine( 'SHOP_VERSION', '1' );\n";
		$names = $this->names( Symbols::extract( $code ) );
		$this->assertContains( 'shortcode:cart', $names );
		$this->assertContains( 'rest:/shop/v1/orders/(?P<id>\d+)', $names );
		$this->assertContains( 'post_type:book', $names );
		$this->assertContains( 'taxonomy:genre', $names );
		$this->assertContains( 'option:shop_settings', $names );
		$this->assertContains( 'constant:SHOP_VERSION', $names );
	}

	public function test_strings_and_comments_produce_no_symbols(): void {
		$code = "<?php\n// function fake() {}\n/* add_action( 'ghost', 'x' ); */\n\$s = \"function nope() {} add_action('also_ghost')\";\n";
		$this->assertSame( array(), Symbols::extract( $code ) );
	}

	public function test_a_method_named_like_a_wordpress_function_is_ignored(): void {
		$code  = "<?php\n\$this->add_action( 'not_a_hook', 'x' );\nFoo::get_option( 'not_an_option' );\n";
		$this->assertSame( array(), Symbols::extract( $code ) );
	}

	public function test_dynamic_names_are_skipped_not_guessed(): void {
		$this->assertSame( array(), Symbols::extract( "<?php\nadd_action( \$hook, 'f' );\nadd_action( 'a_' . \$x, 'f' );\n" ) );
	}

	public function test_lines_are_reported(): void {
		$rows = Symbols::extract( "<?php\n\n\nfunction third() {}\n" );
		$this->assertSame( 4, $rows[0][2] );
	}

	public function test_files_without_php_tags_yield_nothing(): void {
		$this->assertSame( array(), Symbols::extract( 'function x() {}' ) );
	}

	public function test_origin_from_update_data(): void {
		$this->assertSame( 'wordpress.org', Map::origin_from_id( 'w.org/plugins/akismet' ) );
		$this->assertSame( 'wordpress.org', Map::origin_from_id( 'https://wordpress.org/themes/twentytwentyfour/' ) );
		$this->assertSame( 'external', Map::origin_from_id( 'https://example.com/my-plugin' ) );
	}

	public function test_fully_qualified_wordpress_calls_are_indexed(): void {
		// PHP 8 lexes \add_action as a single token; PHP 7 as two.
		$names = $this->names( Symbols::extract( "<?php\n\\add_action( 'init', 'f' );\n\\apply_filters( 'my_filter', 1 );\n" ) );
		$this->assertContains( 'hook:init', $names );
		$this->assertContains( 'hook:my_filter', $names );
	}

	public function test_nullsafe_method_call_is_not_a_hook(): void {
		if ( PHP_VERSION_ID < 80000 ) {
			$this->markTestSkipped( 'The ?-> operator needs PHP 8.' );
		}
		$this->assertSame( array(), Symbols::extract( "<?php\n\$o?->add_action( 'nope', 'x' );\n" ) );
	}

	public function test_enum_methods_belong_to_the_enum(): void {
		if ( PHP_VERSION_ID < 80100 ) {
			$this->markTestSkipped( 'enum needs PHP 8.1.' );
		}
		$names = $this->names( Symbols::extract( "<?php\nenum Suit { public function label() {} }\n" ) );
		$this->assertContains( 'method:Suit::label', $names );
	}
}
