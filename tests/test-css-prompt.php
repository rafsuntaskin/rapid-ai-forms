<?php
/**
 * @package Rapid_Ai_Forms
 */

use Rapid_Ai_Forms\Ai\Css_Prompt;

class Test_Css_Prompt extends WP_UnitTestCase {

	public function test_extract_css_returns_bare_css() {
		$this->assertSame( 'label { color: red; }', Css_Prompt::extract_css( "label { color: red; }\n" ) );
	}

	public function test_extract_css_strips_markdown_fences() {
		$text = "Here is your CSS:\n```css\nlabel { color: red; }\n```\nLet me know!";
		$this->assertSame( 'label { color: red; }', Css_Prompt::extract_css( $text ) );
	}

	public function test_extract_css_strips_plain_fences() {
		$this->assertSame( 'a { b: c; }', Css_Prompt::extract_css( "```\na { b: c; }\n```" ) );
	}

	public function test_extract_css_sanitizes_model_output() {
		$out = Css_Prompt::extract_css( 'label { color: red; } @import url(evil);' );
		$this->assertStringNotContainsString( '@import', $out );
	}

	public function test_extract_css_rejects_empty_output() {
		$this->assertWPError( Css_Prompt::extract_css( '' ) );
	}

	public function test_extract_css_rejects_unbalanced_braces() {
		$this->assertWPError( Css_Prompt::extract_css( 'label { color: red;' ) );
	}

	public function test_context_includes_request_selectors_and_markup() {
		$form    = array(
			'id'       => 1,
			'uuid'     => 'abc-123',
			'title'    => 'Test',
			'schema'   => array(
				'fields' => array(
					array(
						'name'  => 'email',
						'label' => 'Email',
						'type'  => 'email',
					),
				),
			),
			'settings' => array(),
		);
		$context = Css_Prompt::context( $form, 'make it pretty', '.old { a: b; }' );

		$this->assertStringContainsString( 'make it pretty', $context );
		$this->assertStringContainsString( '.old { a: b; }', $context );
		$this->assertStringContainsString( '.raif-form__submit', $context );
		$this->assertStringContainsString( 'data-form-uuid="abc-123"', $context );
	}
}
