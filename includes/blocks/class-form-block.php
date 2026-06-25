<?php
/**
 * Gutenberg block: rapid-ai-forms/form.
 *
 * A thin, server-rendered wrapper around the existing Form_Renderer (same
 * output as the [rapid_ai_form] shortcode). block.json drives registration;
 * the dynamic render_callback keeps a single source of truth for the markup.
 *
 * @package Rapid_Ai_Forms
 */

namespace Rapid_Ai_Forms\Blocks;

use Rapid_Ai_Forms\Forms\Form_Repository;
use Rapid_Ai_Forms\Forms\Form_Renderer;

defined( 'ABSPATH' ) || exit;

class Form_Block {

	public function register() {
		add_action( 'init', array( $this, 'register_block' ) );
	}

	public function register_block() {
		$asset_file = RAPID_AI_FORMS_PATH . 'build/block.asset.php';
		$asset      = file_exists( $asset_file ) ? require $asset_file : array(
			'dependencies' => array(),
			'version'      => RAPID_AI_FORMS_VERSION,
		);

		wp_register_script(
			'rapid-ai-forms-block',
			RAPID_AI_FORMS_URL . 'build/block.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);
		if ( function_exists( 'wp_set_script_translations' ) ) {
			wp_set_script_translations( 'rapid-ai-forms-block', 'rapid-ai-forms' );
		}

		register_block_type(
			RAPID_AI_FORMS_PATH . 'blocks/form/block.json',
			array( 'render_callback' => array( $this, 'render' ) )
		);
	}

	/**
	 * Server render. Reuses Form_Renderer so block and shortcode output match.
	 *
	 * @param array $attributes Block attributes (formId).
	 * @return string
	 */
	public function render( $attributes ) {
		$form_id = isset( $attributes['formId'] ) ? (int) $attributes['formId'] : 0;
		if ( $form_id <= 0 ) {
			return '';
		}

		$form = ( new Form_Repository() )->get( $form_id );
		if ( ! $form ) {
			return '';
		}

		wp_enqueue_script( 'rapid-ai-forms-frontend' );
		wp_enqueue_style( 'rapid-ai-forms-frontend' );

		$wrapper = get_block_wrapper_attributes();
		return sprintf( '<div %s>%s</div>', $wrapper, ( new Form_Renderer() )->render( $form ) );
	}
}
