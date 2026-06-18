<?php
/**
 * REST Context controller — GET /wpicker/v1/context.
 *
 * Returns the Global Context payload (site metadata + theme_mods) to ground
 * AI agents and reduce hallucination. Read-only.
 *
 * @package WPicker\REST
 */

declare( strict_types = 1 );

namespace WPicker\REST;

use WPicker\Context\Provider;

defined( 'ABSPATH' ) || exit;

/**
 * Class Context
 */
final class Context extends Base {

	/**
	 * Context provider.
	 *
	 * @var Provider
	 */
	private $provider;

	/**
	 * Constructor.
	 *
	 * @param Provider $provider Context provider.
	 */
	public function __construct( Provider $provider ) {
		$this->provider = $provider;
	}

	/**
	 * Declare routes.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function routes(): array {
		return array(
			array(
				'path'                => '/context',
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_get' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(),
			),
		);
	}

	/**
	 * Permission callback: require context-read capability.
	 *
	 * @return bool|\WP_Error
	 */
	public function permission() {
		return wpicker()->caps->rest_read_context();
	}

	/**
	 * GET /context handler.
	 *
	 * @return \WP_REST_Response
	 */
	public function handle_get(): \WP_REST_Response {
		return $this->ok( $this->provider->build() );
	}
}
