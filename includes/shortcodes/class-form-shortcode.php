<?php
/**
 * [rapid_ai_form] shortcode.
 *
 * @package Rapid_Ai_Forms
 */

namespace Rapid_Ai_Forms\Shortcodes;

use Rapid_Ai_Forms\Forms\Form_Repository;
use Rapid_Ai_Forms\Forms\Form_Renderer;

defined( 'ABSPATH' ) || exit;

class Form_Shortcode {
	const TAG = 'rapid_ai_form';

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

		wp_enqueue_script( 'rapid-ai-forms-frontend' );
		wp_enqueue_style( 'rapid-ai-forms-frontend' );

		$renderer = new Form_Renderer();
		return $renderer->render( $form );
	}
}
