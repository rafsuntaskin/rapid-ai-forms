<?php
/**
 * Frontend asset registration.
 *
 * @package WP_AI_Forms
 */

namespace WP_AI_Forms\Frontend;

defined( 'ABSPATH' ) || exit;

class Frontend {

	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	public function register_assets() {
		$asset_file = WP_AI_FORMS_PATH . 'build/frontend.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array( 'wp-element' ),
			'version'      => WP_AI_FORMS_VERSION,
		);

		wp_register_script(
			'wp-ai-forms-frontend',
			WP_AI_FORMS_URL . 'build/frontend.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_localize_script(
			'wp-ai-forms-frontend',
			'WP_AI_FORMS',
			array(
				'restUrl' => esc_url_raw( rest_url( 'wp-ai-forms/v1/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);

		wp_register_style(
			'wp-ai-forms-frontend',
			WP_AI_FORMS_URL . 'build/frontend.css',
			array(),
			$asset['version']
		);
	}
}
