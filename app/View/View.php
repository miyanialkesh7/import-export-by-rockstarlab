<?php
/**
 * View Class
 *
 * Handles loading and rendering of view templates.
 *
 * @package RockStarLab\ImportExport\View
 */

namespace RockStarLab\ImportExport\View;

defined( 'ABSPATH' ) or exit;

/**
 * Loads PHP view templates from the plugin's View directory.
 */
class View {

	/**
	 * Load view. Used on back-end side.
	 *
	 * @param string      $path   View path without the .php extension, relative to the base directory.
	 * @param array       $data   Variables made available to the view.
	 * @param bool        $return Whether to return the rendered output instead of printing it.
	 * @param string|null $base   Base directory relative to the plugin root, or null for app/View/.
	 * @return string|void Rendered output when $return is true.
	 * @throws \Exception When the view file does not exist.
	 */
	public function load( $path = '', $data = array(), $return = false, $base = null ) {

		if ( is_null( $base ) ) {
			$base = RSL_IE_PATH . '/app/View/';
		} else {
			$base = wp_normalize_path( RSL_IE_PATH . '/' . $base );
		}

		$full_path = $base . $path . '.php';

		if ( $return ) {
			ob_start();
		}

		if ( file_exists( $full_path ) ) {
			require $full_path;
		} else {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $full_path is a server-side path, not user output.
			throw new \Exception( 'The view path ' . esc_html( $full_path ) . ' can not be found.' );
		}

		if ( $return ) {
			return ob_get_clean();
		}
	}
}
