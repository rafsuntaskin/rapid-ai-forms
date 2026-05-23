<?php
/**
 * [easy_ai_form] shortcode.
 *
 * @package Easy_Ai_Forms
 */

namespace Easy_Ai_Forms\Shortcodes;

use Easy_Ai_Forms\Forms\Form_Repository;
use Easy_Ai_Forms\Forms\Form_Renderer;

defined( 'ABSPATH' ) || exit;

class Form_Shortcode {
	const TAG = 'easy_ai_form';

	public function register() {
		add_shortcode( self::TAG, array( $this, 'render' ) );
	}

	public function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'id'   => 0,
				'uuid' => '',
			),
			$atts,
			self::TAG
		);

		$repo = new Form_Repository();
		$form = $atts['uuid'] ? $repo->get_by_uuid( $atts['uuid'] ) : $repo->get( (int) $atts['id'] );

		if ( ! $form ) {
			return '';
		}

		wp_enqueue_script( 'easy-ai-forms-frontend' );
		wp_enqueue_style( 'easy-ai-forms-frontend' );

		$renderer = new Form_Renderer();
		return $renderer->render( $form );
	}
}
