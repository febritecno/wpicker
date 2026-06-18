<?php
/**
 * REST Base — shared controller for all WPicker routes.
 *
 * Each concrete controller declares its routes in routes() and is registered
 * by Core::register_rest_routes() under the shared namespace.
 *
 * @package WPicker\REST
 */

declare( strict_types = 1 );

namespace WPicker\REST;

defined( 'ABSPATH' ) || exit;

/**
 * Class Base
 *
 * Provides register() to iterate a controller's declared routes and call
 * register_rest_route() for each with sensible defaults.
 */
abstract class Base {

	/**
	 * Declare the routes this controller owns.
	 *
	 * Each item is an array shaped like register_rest_route()'s third arg,
	 * plus a 'path' key for the route URI.
	 *
	 * @return array<int, array<string,mixed>>
	 */
	abstract protected function routes(): array;

	/**
	 * Register all of this controller's routes under the given namespace.
	 *
	 * @param string $namespace REST namespace (e.g. "wpicker/v1").
	 * @return void
	 */
	public function register( string $namespace ): void {
		foreach ( $this->routes() as $route ) {
			if ( empty( $route['path'] ) ) {
				continue;
			}
			$path = $route['path'];
			unset( $route['path'] );

			// Always default to the namespace if no permission_callback set.
			if ( ! isset( $route['permission_callback'] ) ) {
				$route['permission_callback'] = '__return_false';
			}

			register_rest_route( $namespace, $path, $route );
		}
	}

	/**
	 * Build a uniform success response.
	 *
	 * @param mixed       $data    Payload.
	 * @param int         $status  HTTP status code.
	 * @param array<string,mixed> $headers Headers.
	 * @return \WP_REST_Response
	 */
	protected function ok( $data, int $status = 200, array $headers = array() ): \WP_REST_Response {
		$response = new \WP_REST_Response(
			array(
				'ok'   => true,
				'data' => $data,
			),
			$status
		);
		foreach ( $headers as $name => $value ) {
			$response->header( $name, (string) $value );
		}
		return $response;
	}

	/**
	 * Build a uniform error response for the self-healing contract.
	 *
	 * Shape: { ok:false, error:{ code, message, file?, line?, manifest_id? } }.
	 *
	 * @param string $code    Machine-readable error code.
	 * @param string $message Human-readable message.
	 * @param int    $status  HTTP status code.
	 * @param array<string,mixed> $extra Extra error fields (file, line, manifest_id, ...).
	 * @return \WP_REST_Response
	 */
	protected function fail( string $code, string $message, int $status = 400, array $extra = array() ): \WP_REST_Response {
		$error = array_merge(
			array(
				'code'    => $code,
				'message' => $message,
			),
			$extra
		);
		return new \WP_REST_Response(
			array(
				'ok'    => false,
				'error'  => $error,
			),
			$status
		);
	}
}
