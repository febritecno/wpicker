<?php
/**
 * Capability helper — centralizes authorization checks for WPicker.
 *
 * Guardrail: every mutating REST route MUST pass through here. We use real
 * capability checks (edit_themes / manage_options), never `__return_true`.
 *
 * @package WPicker\Auth
 */

declare( strict_types = 1 );

namespace WPicker\Auth;

defined( 'ABSPATH' ) || exit;

/**
 * Class Capability
 *
 * Thin wrapper around current_user_can() with intent-specific defaults and
 * a permission_callback factory for REST routes.
 */
final class Capability {

	/**
	 * Capability required to read site context (versions, theme_mods).
	 *
	 * Reading the context still exposes theme configuration, so we require
	 * `edit_theme_options` rather than making it fully public.
	 */
	public const READ_CONTEXT = 'edit_theme_options';

	/**
	 * Capability required to read/modify child-theme files (pull/push).
	 */
	public const SYNC_FILES = 'edit_themes';

	/**
	 * Capability required to manage devices and settings (admin dashboard).
	 */
	public const MANAGE = 'manage_options';

	/**
	 * Check a capability for the current user.
	 *
	 * @param string $cap Capability to check.
	 * @return bool
	 */
	public function can( string $cap ): bool {
		return is_user_logged_in() && current_user_can( $cap );
	}

	/**
	 * REST permission_callback for context reads.
	 *
	 * @return bool|\WP_Error True if allowed, WP_Error with 401 otherwise.
	 */
	public function rest_read_context() {
		if ( ! $this->can( self::READ_CONTEXT ) ) {
			return new \WP_Error(
				'wpicker_rest_forbidden',
				__( 'Insufficient permissions to read WPicker context.', 'wpicker' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * REST permission_callback for theme sync (pull/push/lint).
	 *
	 * @return bool|\WP_Error
	 */
	public function rest_sync_files() {
		if ( ! $this->can( self::SYNC_FILES ) ) {
			return new \WP_Error(
				'wpicker_rest_forbidden',
				__( 'Insufficient permissions to sync theme files.', 'wpicker' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * REST permission_callback for device management & settings.
	 *
	 * @return bool|\WP_Error
	 */
	public function rest_manage() {
		if ( ! $this->can( self::MANAGE ) ) {
			return new \WP_Error(
				'wpicker_rest_forbidden',
				__( 'Insufficient permissions to manage WPicker.', 'wpicker' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * REST permission_callback for the PIN challenge endpoint.
	 *
	 * Issuing a PIN requires being able to log in as that user (basic auth
	 * with username + the user's existing credentials, e.g. app password of
	 * an admin). We require `edit_themes` for the target user so that only
	 * eligible operators can mint a new device.
	 *
	 * @return bool|\WP_Error
	 */
	public function rest_challenge() {
		if ( ! $this->can( self::SYNC_FILES ) ) {
			return new \WP_Error(
				'wpicker_rest_forbidden',
				__( 'Insufficient permissions to issue a WPicker PIN.', 'wpicker' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}
}
