<?php
/**
 * Page Builder Detector.
 *
 * @package WPicker\Builder
 */

declare( strict_types = 1 );

namespace WPicker\Builder;

/**
 * Class Detector
 */
class Detector {

	/**
	 * Get the active page builders.
	 *
	 * @return array List of active builder identifiers.
	 */
	public function get_active_builders(): array {
		$active = array();

		// Detect Divi: Check if theme is Divi/Extra or Divi Builder plugin is active.
		$theme = wp_get_theme();
		if ( 'Divi' === $theme->name || 'Divi' === $theme->template || 'Extra' === $theme->name || class_exists( 'ET_Builder_Plugin' ) ) {
			$active[] = 'divi';
		}

		// Detect Elementor: Check if main Elementor class exists.
		if ( class_exists( '\Elementor\Plugin' ) ) {
			$active[] = 'elementor';
		}

		// If no major third-party builder is active, fallback to native Gutenberg.
		if ( empty( $active ) ) {
			$active[] = 'gutenberg';
		}

		return $active;
	}
}
