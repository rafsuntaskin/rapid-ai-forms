<?php
/**
 * Frontend asset registration.
 *
 * @package Easy_Ai_Forms
 */

namespace Easy_Ai_Forms\Frontend;

defined( 'ABSPATH' ) || exit;

class Frontend {

	public function register() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	public function register_assets() {
		$asset_file = EASY_AI_FORMS_PATH . 'build/frontend.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array( 'wp-element' ),
			'version'      => EASY_AI_FORMS_VERSION,
		);

		wp_register_script(
			'easy-ai-forms-frontend',
			EASY_AI_FORMS_URL . 'build/frontend.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_localize_script(
			'easy-ai-forms-frontend',
			'EASY_AI_FORMS',
			array(
				'restUrl' => esc_url_raw( rest_url( 'easy-ai-forms/v1/' ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);

		wp_register_style(
			'easy-ai-forms-frontend',
			EASY_AI_FORMS_URL . 'build/frontend.css',
			array(),
			$asset['version']
		);
	}
}
