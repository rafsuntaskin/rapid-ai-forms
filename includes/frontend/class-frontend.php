<?php
/**
 * Frontend asset registration.
 *
 * @package Rapid_Ai_Forms
 */

namespace Rapid_Ai_Forms\Frontend;

defined( 'ABSPATH' ) || exit;

class Frontend {

	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	public function register_assets() {
		$asset_file = RAPID_AI_FORMS_PATH . 'build/frontend.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array( 'wp-element' ),
			'version'      => RAPID_AI_FORMS_VERSION,
		);

		wp_register_script(
			'rapid-ai-forms-frontend',
			RAPID_AI_FORMS_URL . 'build/frontend.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_localize_script(
			'rapid-ai-forms-frontend',
			'RAPID_AI_FORMS',
			array(
				'restUrl' => esc_url_raw( rest_url( 'rapid-ai-forms/v1/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);

		wp_register_style(
			'rapid-ai-forms-frontend',
			RAPID_AI_FORMS_URL . 'build/frontend.css',
			array(),
			$asset['version']
		);
	}
}
