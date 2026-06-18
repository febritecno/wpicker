<?php
/**
 * REST Page controller — POST /wpicker/v1/page.
 *
 * Handles remote creation of pages for specific Page Builders.
 *
 * @package WPicker\REST
 */

declare( strict_types = 1 );

namespace WPicker\REST;

use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

/**
 * Class Page
 */
final class Page extends Base {

	/**
	 * Declare routes.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	protected function routes(): array {
		return array(
			array(
				'path'                => '/page',
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_page' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(),
			),
			array(
				'path'                => '/page/(?P<id>\d+)',
				'methods'             => WP_REST_Server::EDITABLE, // PUT/PATCH/POST
				'callback'            => array( $this, 'update_page' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(),
			),
		);
	}

	/**
	 * POST /page handler.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_page( WP_REST_Request $request ) {
		$title   = $request->get_param( 'title' );
		$builder = $request->get_param( 'builder' );
		$payload = $request->get_param( 'payload' );

		if ( empty( $title ) || empty( $builder ) || empty( $payload ) ) {
			return $this->error( 'missing_args', 'Missing required arguments: title, builder, payload.', 400 );
		}

		$post_data = array(
			'post_title'   => wp_strip_all_tags( $title ),
			'post_type'    => 'page',
			'post_status'  => 'publish',
			'post_content' => '',
		);

		// Route payload based on builder.
		if ( 'divi' === $builder ) {
			$post_data['post_content'] = $payload; // Divi uses shortcodes in post_content.
		} elseif ( 'gutenberg' === $builder ) {
			$post_data['post_content'] = $payload; // Gutenberg uses block HTML in post_content.
		} elseif ( 'elementor' === $builder ) {
			$post_data['post_content'] = '<!-- Elementor Managed Page -->'; // Elementor data lives in meta.
		} else {
			return $this->error( 'invalid_builder', 'Unsupported builder type. Must be divi, elementor, or gutenberg.', 400 );
		}

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $this->error( 'post_creation_failed', $post_id->get_error_message(), 500 );
		}

		// Inject Builder-specific post meta.
		if ( 'divi' === $builder ) {
			update_post_meta( $post_id, '_et_pb_use_builder', 'on' );
		} elseif ( 'elementor' === $builder ) {
			$elementor_data = json_decode( $payload, true );
			if ( json_last_error() === JSON_ERROR_NONE ) {
				// Elementor expects the parsed array structure so it can serialize it.
				update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $elementor_data ) ) );
				// Wait, if it's already an array, update_post_meta handles serialization.
				// However Elementor technically expects it stored precisely, but passing the raw slashed json string is often safest.
				update_post_meta( $post_id, '_elementor_data', wp_slash( $payload ) );
				update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
				update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );
			} else {
				return $this->error( 'invalid_json', 'Elementor payload must be valid JSON.', 400 );
			}
		}

		return $this->ok(
			array(
				'post_id' => $post_id,
				'url'     => get_permalink( $post_id ),
			)
		);
	}

	/**
	 * PUT/POST /page/{id} handler.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_page( WP_REST_Request $request ) {
		$post_id = (int) $request->get_param( 'id' );
		$title   = $request->get_param( 'title' );
		$builder = $request->get_param( 'builder' );
		$payload = $request->get_param( 'payload' );

		if ( empty( $builder ) || empty( $payload ) ) {
			return $this->error( 'missing_args', 'Missing required arguments: builder, payload.', 400 );
		}

		$post = get_post( $post_id );
		if ( ! $post || 'page' !== $post->post_type ) {
			return $this->error( 'invalid_post', 'Page not found.', 404 );
		}

		$post_data = array(
			'ID' => $post_id,
		);

		if ( ! empty( $title ) ) {
			$post_data['post_title'] = wp_strip_all_tags( $title );
		}

		// Route payload based on builder.
		if ( 'divi' === $builder ) {
			$post_data['post_content'] = $payload;
		} elseif ( 'gutenberg' === $builder ) {
			$post_data['post_content'] = $payload;
		} elseif ( 'elementor' === $builder ) {
			// Do not overwrite existing post_content if updating Elementor unless necessary.
		} else {
			return $this->error( 'invalid_builder', 'Unsupported builder type.', 400 );
		}

		$updated_id = wp_update_post( $post_data, true );

		if ( is_wp_error( $updated_id ) ) {
			return $this->error( 'post_update_failed', $updated_id->get_error_message(), 500 );
		}

		// Inject Builder-specific post meta.
		if ( 'divi' === $builder ) {
			update_post_meta( $post_id, '_et_pb_use_builder', 'on' );
		} elseif ( 'elementor' === $builder ) {
			$elementor_data = json_decode( $payload, true );
			if ( json_last_error() === JSON_ERROR_NONE ) {
				update_post_meta( $post_id, '_elementor_data', wp_slash( $payload ) );
				update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
				update_post_meta( $post_id, '_elementor_template_type', 'wp-page' );
			} else {
				return $this->error( 'invalid_json', 'Elementor payload must be valid JSON.', 400 );
			}
		}

		return $this->ok(
			array(
				'post_id' => $post_id,
				'url'     => get_permalink( $post_id ),
			)
		);
	}
}
