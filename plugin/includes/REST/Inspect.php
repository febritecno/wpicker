<?php
/**
 * REST Inspect controller — read-only diagnostics for a person or an agent.
 *
 * Routes:
 *   GET  /inspect/status       what is switched on, which logs exist
 *   POST /inspect/debug        { enabled } turn WordPress debug logging on / off
 *   GET  /inspect/logs         parsed, grouped PHP + WordPress log entries
 *   GET  /inspect/files        list a directory under wp-content
 *   GET  /inspect/file         read a text file under wp-content
 *   GET  /inspect/grep         search text files under wp-content
 *   GET  /inspect/db/tables    this site's tables and how readable each is
 *   POST /inspect/db/query     { sql, limit } one read-only statement
 *
 * Everything here reads; the one exception is /inspect/debug, which flips a
 * setting of this plugin. All of it is off until an administrator enables
 * "Diagnostic access" in WPicker → Settings, and every call is recorded
 * (Inspect\Audit).
 *
 * @package WPicker\REST
 */

declare( strict_types = 1 );

namespace WPicker\REST;

use WPicker\Inspect\Audit;
use WPicker\Inspect\Database;
use WPicker\Inspect\Files;
use WPicker\Inspect\Logs;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Class Inspect
 */
final class Inspect extends Base {

	/**
	 * Declare routes.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function routes(): array {
		$route = function ( string $path, string $method, string $callback, array $args = array() ): array {
			return array(
				'path'                => $path,
				'methods'             => $method,
				'callback'            => array( $this, $callback ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => $args,
			);
		};
		$string = array( 'type' => 'string', 'default' => '' );
		$int    = static function ( int $default ): array {
			return array( 'type' => 'integer', 'default' => $default );
		};
		$bool   = array( 'type' => 'boolean', 'default' => false );

		return array(
			$route( '/inspect/status', 'GET', 'status' ),
			$route( '/inspect/debug', 'POST', 'debug', array( 'enabled' => array( 'type' => 'boolean', 'required' => true ) ) ),
			$route(
				'/inspect/logs',
				'GET',
				'logs',
				array(
					'since'  => $int( 0 ),
					'level'  => array( 'type' => 'string', 'default' => 'info', 'enum' => array( 'fatal', 'warning', 'notice', 'deprecated', 'info' ) ),
					'grep'   => $string,
					'source' => array( 'type' => 'string', 'default' => 'all', 'enum' => array( 'all', 'php', 'wp', 'diag' ) ),
					'limit'  => $int( 50 ),
				)
			),
			$route( '/inspect/files', 'GET', 'files', array( 'path' => $string, 'depth' => $int( 1 ) ) ),
			$route( '/inspect/file', 'GET', 'file', array( 'path' => array( 'type' => 'string', 'required' => true ), 'from' => $int( 1 ), 'to' => $int( 0 ) ) ),
			$route(
				'/inspect/grep',
				'GET',
				'grep',
				array(
					'pattern' => array( 'type' => 'string', 'required' => true ),
					'path'    => $string,
					'regex'   => $bool,
					'case'    => $bool,
					'ext'     => $string,
					'limit'   => $int( 100 ),
				)
			),
			$route( '/inspect/db/tables', 'GET', 'tables' ),
			$route( '/inspect/db/query', 'POST', 'query', array( 'sql' => array( 'type' => 'string', 'required' => true ), 'limit' => $int( Database::DEFAULT_ROWS ) ) ),
		);
	}

	/**
	 * Permission callback: administrators, and only while diagnostic access
	 * is enabled for this site.
	 *
	 * @return bool|\WP_Error
	 */
	public function permission() {
		$allowed = wpicker()->caps->rest_manage();
		if ( true !== $allowed ) {
			return $allowed;
		}
		$settings = get_option( 'wpicker_settings', array() );
		if ( (int) ( $settings['allow_inspect'] ?? 0 ) !== 1 ) {
			return new \WP_Error(
				'wpicker_inspect_disabled',
				__( 'Diagnostic access is switched off. An administrator can enable it in WP-Admin → WPicker → Settings.', 'wpicker' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * GET /inspect/status.
	 *
	 * @return WP_REST_Response
	 */
	public function status(): WP_REST_Response {
		$sources = array();
		foreach ( Logs::sources() as $name => $path ) {
			$sources[ $name ] = (int) filesize( $path );
		}
		return $this->ok(
			array(
				'debug_logging' => Logs::debug_enabled(),
				'wp_debug'      => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'wp_debug_log'  => defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG,
				'log_sources'   => (object) $sources, // {} rather than [] when empty
				'php_version'   => PHP_VERSION,
				'content_dir'   => 'wp-content',
			)
		);
	}

	/**
	 * POST /inspect/debug — turn WordPress debug logging on or off.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function debug( WP_REST_Request $request ): WP_REST_Response {
		$enabled               = (bool) $request->get_param( 'enabled' );
		$settings              = (array) get_option( 'wpicker_settings', array() );
		$settings['debug_log'] = $enabled ? 1 : 0;
		update_option( 'wpicker_settings', $settings, false );
		Audit::record( 'debug', $enabled ? 'on' : 'off' );
		return $this->ok( array( 'debug_logging' => $enabled ) );
	}

	/**
	 * GET /inspect/logs.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function logs( WP_REST_Request $request ): WP_REST_Response {
		$args = array(
			'since'  => $request->get_param( 'since' ),
			'level'  => $request->get_param( 'level' ),
			'grep'   => $request->get_param( 'grep' ),
			'source' => $request->get_param( 'source' ),
			'limit'  => $request->get_param( 'limit' ),
		);
		Audit::record( 'logs', 'level=' . $args['level'] . ' since=' . $args['since'] . ( '' !== $args['grep'] ? ' grep=' . $args['grep'] : '' ) );
		return $this->ok( Logs::read( $args ) );
	}

	/**
	 * GET /inspect/files.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function files( WP_REST_Request $request ): WP_REST_Response {
		$path    = (string) $request->get_param( 'path' );
		$listing = Files::listing( $path, (int) $request->get_param( 'depth' ) );
		if ( null === $listing ) {
			return $this->fail( 'wpicker_path_unavailable', 'Not a directory under wp-content, or not available for reading.', 404, array( 'file' => $path ) );
		}
		Audit::record( 'files.list', $path );
		return $this->ok( $listing );
	}

	/**
	 * GET /inspect/file.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function file( WP_REST_Request $request ): WP_REST_Response {
		$path = (string) $request->get_param( 'path' );
		$file = Files::read( $path, (int) $request->get_param( 'from' ), (int) $request->get_param( 'to' ) );
		if ( isset( $file['error'] ) ) {
			return $this->fail( 'wpicker_path_unavailable', $file['error'], 404, array( 'file' => $path ) );
		}
		Audit::record( 'file.read', $path );
		return $this->ok( $file );
	}

	/**
	 * GET /inspect/grep.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function grep( WP_REST_Request $request ): WP_REST_Response {
		$pattern = (string) $request->get_param( 'pattern' );
		$path    = (string) $request->get_param( 'path' );
		$options = array(
			'regex' => (bool) $request->get_param( 'regex' ),
			'case'  => (bool) $request->get_param( 'case' ),
			'limit' => (int) $request->get_param( 'limit' ),
		);
		if ( '' !== (string) $request->get_param( 'ext' ) ) {
			$options['ext'] = (string) $request->get_param( 'ext' );
		}
		$result = Files::grep( $pattern, $path, $options );
		if ( isset( $result['error'] ) ) {
			return $this->fail( 'wpicker_grep_failed', $result['error'], 400 );
		}
		Audit::record( 'files.grep', $pattern . ' in ' . ( '' === $path ? 'wp-content' : $path ) );
		return $this->ok( $result );
	}

	/**
	 * GET /inspect/db/tables.
	 *
	 * @return WP_REST_Response
	 */
	public function tables(): WP_REST_Response {
		Audit::record( 'db.tables' );
		return $this->ok( array( 'tables' => Database::tables() ) );
	}

	/**
	 * POST /inspect/db/query.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function query( WP_REST_Request $request ): WP_REST_Response {
		$sql = (string) $request->get_param( 'sql' );
		Audit::record( 'db.query', $sql );
		$result = Database::query( $sql, (int) $request->get_param( 'limit' ) );
		if ( isset( $result['error'] ) ) {
			return $this->fail( 'wpicker_query_refused', $result['error'], 400 );
		}
		return $this->ok( $result );
	}
}
