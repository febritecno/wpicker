<?php

declare( strict_types = 1 );

namespace WPicker\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Static guards on the pairing routes. The routes need a whole WordPress to run,
 * so these read the source for the properties that must never regress.
 */
final class TestDeviceRoutes extends TestCase {

	private function src( string $rel ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $rel );
	}

	private function body( string $rel, string $name ): string {
		$src = $this->src( $rel );
		$this->assertSame( 1, preg_match( '/function\s+' . preg_quote( $name, '/' ) . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE ), "no $name() in $rel" );
		$start = (int) $m[0][1];
		$open  = strpos( $src, '{', $start );
		$depth = 0;
		for ( $i = $open, $n = strlen( $src ); $i < $n; $i++ ) {
			if ( '{' === $src[ $i ] ) {
				++$depth;
			} elseif ( '}' === $src[ $i ] && 0 === --$depth ) {
				return substr( $src, $start, $i - $start + 1 );
			}
		}
		$this->fail( "unterminated $name()" );
	}

	public function test_the_anonymous_routes_never_trust_the_requests_own_identity(): void {
		foreach ( array( 'start', 'poll' ) as $m ) {
			$b = $this->body( 'includes/REST/Device.php', $m );
			foreach ( array( 'get_current_user_id', 'wp_get_current_user', 'rest_get_authenticated_app_password', 'is_user_logged_in', 'wp_set_current_user' ) as $forbidden ) {
				$this->assertStringNotContainsString( $forbidden, $b, "$m() is anonymous and must not use $forbidden" );
			}
		}
	}

	public function test_credentials_go_to_the_user_who_approved_and_nobody_else(): void {
		$b = $this->body( 'includes/REST/Device.php', 'poll' );
		$this->assertStringContainsString( "get_userdata( (int) \$result['user_id'] )", $b, 'the user comes from the recorded decision' );
		$this->assertStringContainsString( 'user_can( $user, Capability::SYNC_FILES )', $b, 'the capability is re-checked when credentials are released' );
		$this->assertStringContainsString( 'is_user_member_of_blog', $b );
		$this->assertMatchesRegularExpression( '/mint_device\(\s*\(int\)\s*\$user->ID/', $b );
		$this->assertStringNotContainsString( 'get_current_user_id', $this->body( 'includes/REST/Device.php', 'mint_device' ) );
	}

	public function test_every_route_that_returns_a_secret_or_a_status_sends_no_store(): void {
		foreach ( array( 'start', 'poll' ) as $m ) {
			$b = $this->body( 'includes/REST/Device.php', $m );
			$this->assertStringNotContainsString( 'return $this->ok(', $b, "$m() returns without no-store" );
			$this->assertStringNotContainsString( 'return $this->fail(', $b, "$m() returns an error without no-store" );
		}
		$this->assertStringContainsString( 'no-store', $this->body( 'includes/REST/Device.php', 'nostore' ) );
	}

	public function test_the_poll_gives_unknown_used_and_malformed_requests_one_answer(): void {
		$b = $this->body( 'includes/REST/Device.php', 'poll' );
		$this->assertSame( 1, substr_count( $b, "'wpicker_code_invalid'" ) );
		$this->assertStringContainsString( "'miss'", $b, 'a wrong device_code counts against its source' );
	}

	public function test_both_anonymous_routes_are_declared_anonymous_and_nothing_else_is(): void {
		$src = $this->src( 'includes/REST/Device.php' );
		preg_match_all( "#'path'\s*=>\s*'([^']+)',.*?'permission_callback'\s*=>\s*([^,]+),#s", $src, $m, PREG_SET_ORDER );
		$open = array();
		foreach ( $m as $row ) {
			if ( false !== strpos( $row[2], '__return_true' ) ) {
				$open[] = $row[1];
			}
		}
		sort( $open );
		$this->assertSame( array( '/device/poll', '/device/start' ), $open, 'only the two pairing routes may be open to anyone' );
	}

	public function test_there_is_no_bearer_code_route_left(): void {
		$src = $this->src( 'includes/REST/Device.php' );
		// Exact route names: '/device/pairing' (the WP-Admin status route) is a different, legitimate one.
		$this->assertDoesNotMatchRegularExpression( "#'/device/(pair|code)'#", $src, 'the rejected bearer-code routes are back' );
		foreach ( array( 'PairingCode', 'issue_code' ) as $gone ) {
			$this->assertStringNotContainsString( $gone, $src, "$gone belonged to the rejected bearer-code design" );
		}
		$this->assertFileDoesNotExist( dirname( __DIR__, 2 ) . '/includes/Auth/PairingCode.php' );
	}

	public function test_the_approval_screen_changes_nothing_on_get(): void {
		$render = $this->body( 'includes/Admin/Approve.php', 'render' );
		$this->assertStringNotContainsString( 'decide(', $render, 'opening a link must never approve anything' );
		$view = $this->src( 'admin/views/approve.php' );
		$this->assertStringNotContainsString( '->decide(', $view );
		$this->assertMatchesRegularExpression( '/<form method="post"[^>]*admin-post\.php/', $view, 'approving is a POST' );
		$this->assertStringContainsString( 'wp_nonce_field', $view );
	}

	public function test_the_approval_post_checks_who_when_and_what(): void {
		$h = $this->body( 'includes/Admin/Approve.php', 'handle' );
		$this->assertStringContainsString( 'current_user_can( Capability::SYNC_FILES )', $h );
		$this->assertStringContainsString( "check_admin_referer( 'wpicker_dc_' . \$hash )", $h );
		$this->assertStringContainsString( 'hash_equals', $h, 'the typed code is compared in constant time' );
		$this->assertStringContainsString( 'get_current_user_id()', $h, 'the decision is recorded against the logged-in user' );
		$this->assertMatchesRegularExpression( '/bucket.*?client_ip|client_ip.*?bucket/s', $h, 'a request from another network must be confirmed' );
	}

	public function test_what_a_stranger_supplied_is_always_escaped_on_the_approval_screen(): void {
		$view = $this->src( 'admin/views/approve.php' );
		foreach ( array( "\$req['name']", "\$req['agent']", "\$req['ip']" ) as $field ) {
			$this->assertMatchesRegularExpression( '/esc_html\(\s*\(string\)\s*\(\s*' . preg_quote( $field, '/' ) . '/', $view, "$field is shown unescaped" );
		}
	}

	public function test_the_link_is_built_from_the_sites_own_admin_address(): void {
		$b = $this->body( 'includes/REST/Device.php', 'start' );
		$this->assertStringContainsString( "admin_url( 'admin.php' )", $b );
		$this->assertStringNotContainsString( 'HTTP_HOST', $b, 'the link must not be built from the Host header' );
	}

	public function test_the_site_name_is_sent_as_text_not_as_html(): void {
		$src = $this->src( 'includes/REST/Device.php' );
		$this->assertSame( 2, substr_count( $src, "wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES )" ), 'start() and poll() both send the name; get_bloginfo() returns it HTML-encoded' );
		$this->assertStringNotContainsString( "'site_name'        => (string) get_bloginfo", $src );
	}

	public function test_on_multisite_a_super_admin_may_be_the_approver(): void {
		// edit_themes is super-admin-only there, and a super admin is often not a member of the sub-site.
		$b = $this->body( 'includes/REST/Device.php', 'poll' );
		$this->assertStringContainsString( '! is_super_admin( $user->ID )', $b );
	}

	public function test_a_proxy_never_makes_the_different_network_check_pass_by_default(): void {
		foreach ( array( array( 'includes/Admin/Approve.php', 'render' ), array( 'includes/Admin/Approve.php', 'handle' ) ) as $m ) {
			$this->assertStringContainsString( 'behind_proxy()', $this->body( $m[0], $m[1] ), $m[1] . '() must treat an unknown network as a different one' );
		}
	}

	public function test_the_code_is_not_shown_to_the_person_who_has_to_type_it(): void {
		$view = $this->src( 'admin/views/approve.php' );
		$this->assertMatchesRegularExpression( "#if \\( \\\$same_network \\) :.{0,300}esc_html\\( \\\\WPicker\\\\Auth\\\\DeviceFlow::format#s", $view, 'the code may only be printed when the network matches' );
		$this->assertStringContainsString( 'Read it in your terminal', $view );
	}

	public function test_the_password_and_pin_pairing_is_gone_everywhere(): void {
		$root = dirname( __DIR__, 2 );
		$rest = $this->src( 'includes/REST/Device.php' );
		foreach ( array( '/device/challenge', '/device/register', '/device/pairing' ) as $route ) {
			$this->assertStringNotContainsString( "'" . $route . "'", $rest, "$route belonged to the password + PIN pairing" );
		}
		foreach ( array( 'wp_authenticate', 'register_device', 'pins->', 'via_credentials' ) as $word ) {
			$this->assertStringNotContainsString( $word, $rest, "$word belonged to the password + PIN pairing" );
		}
		// The one place the word legitimately appears is the Application Password that is HANDED OUT.
		$this->assertDoesNotMatchRegularExpression( "/get_param\\(\\s*'(password|username|pin)'/", $rest, 'no route may read a password, username or PIN from the request' );
		$this->assertFileDoesNotExist( $root . '/includes/Auth/Pin.php' );
		$this->assertStringNotContainsString( 'rest_challenge', $this->src( 'includes/Auth/Capability.php' ) );
		foreach ( array( 'wpicker.php', 'includes/Admin/Dashboard.php', 'admin/views/settings.php', 'admin/views/devices.php', 'admin/js/admin.js' ) as $f ) {
			$this->assertDoesNotMatchRegularExpression( '/pin_ttl|pin_length|wpicker-pin|initPairing|\/device\/challenge/', $this->src( $f ), "$f still mentions the PIN pairing" );
		}
	}

	public function test_pairing_can_only_be_approved_from_a_logged_in_browser_session(): void {
		// A paired device (Application Password) cannot approve anything: approval is wp-admin
		// (cookie) only, never a REST route.
		$rest = $this->src( 'includes/REST/Device.php' );
		$this->assertStringNotContainsString( 'decide(', $rest, 'no REST route may record an approval' );
		$this->assertStringContainsString( 'admin_post_', $this->src( 'includes/Admin/Approve.php' ) );
	}
}
