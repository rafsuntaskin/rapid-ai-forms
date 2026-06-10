<?php
/**
 * @package Rapid_Ai_Forms
 */

use Rapid_Ai_Forms\Forms\Form_Renderer;

class Test_Form_Renderer extends WP_UnitTestCase {

	private function form( array $overrides = array() ) {
		return array_merge(
			array(
				'id'       => 1,
				'uuid'     => 'aaaa-bbbb',
				'title'    => 'Test',
				'schema'   => array(
					'fields' => array(
						array(
							'name'     => 'email',
							'label'    => 'Email',
							'type'     => 'email',
							'required' => true,
						),
					),
				),
				'settings' => array(),
			),
			$overrides
		);
	}

	public function test_required_fields_emit_required_and_aria_required() {
		$html = ( new Form_Renderer() )->render( $this->form() );
		$this->assertStringContainsString( 'required aria-required="true"', $html );
	}

	public function test_custom_css_renders_scoped_style_block() {
		$html = ( new Form_Renderer() )->render(
			$this->form( array( 'settings' => array( 'custom_css' => 'label { color: red; }' ) ) )
		);

		$this->assertStringContainsString( 'id="raif-css-aaaa-bbbb"', $html );
		$this->assertStringContainsString( '.raif-form[data-form-uuid="aaaa-bbbb"] {', $html );
		$this->assertStringContainsString( 'label { color: red; }', $html );
	}

	public function test_no_style_block_without_custom_css() {
		$html = ( new Form_Renderer() )->render( $this->form() );
		$this->assertStringNotContainsString( 'raif-css-', $html );
	}

	public function test_malicious_custom_css_is_neutralized_at_render() {
		$html = ( new Form_Renderer() )->render(
			$this->form( array( 'settings' => array( 'custom_css' => 'label{a:b} </style><script>alert(1)</script>' ) ) )
		);
		$this->assertStringNotContainsString( '<script', $html );
	}
}
