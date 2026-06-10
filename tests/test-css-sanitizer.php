<?php
/**
 * @package Rapid_Ai_Forms
 */

use Rapid_Ai_Forms\Forms\Css_Sanitizer;

class Test_Css_Sanitizer extends WP_UnitTestCase {

	public function test_passes_plain_css_through() {
		$css = "label { color: red; }\n.raif-field input { border-radius: 8px; }";
		$this->assertSame( $css, Css_Sanitizer::sanitize( $css ) );
	}

	public function test_strips_style_breakout_and_script() {
		$out = Css_Sanitizer::sanitize( 'label { color: red; } </style><script>alert(1)</script>' );
		$this->assertStringNotContainsString( '</style', $out );
		$this->assertStringNotContainsString( '<script', $out );
		$this->assertStringContainsString( 'color: red', $out );
	}

	public function test_strips_dangerous_tokens() {
		$out = Css_Sanitizer::sanitize( "@import url(evil.css);\na { background: url(javascript:alert(1)); }\nb { width: expression(alert(1)); }\nc { behavior: url(x.htc); }" );
		$this->assertStringNotContainsString( '@import', $out );
		$this->assertStringNotContainsStringIgnoringCase( 'javascript:', $out );
		$this->assertStringNotContainsString( 'expression(', $out );
		$this->assertStringNotContainsString( 'behavior:', $out );
	}

	public function test_strips_tokens_that_reassemble_after_one_pass() {
		// Removing the inner "<script" once would leave "javascript:" behind.
		$out = Css_Sanitizer::sanitize( 'a { background: url(java<scriptscript:alert(1)) }' );
		$this->assertStringNotContainsStringIgnoringCase( 'javascript:', $out );
	}

	public function test_strips_tokens_case_insensitively() {
		$out = Css_Sanitizer::sanitize( 'a { width: EXPRESSION(alert(1)); } @IMPORT url(x);' );
		$this->assertStringNotContainsStringIgnoringCase( 'expression(', $out );
		$this->assertStringNotContainsStringIgnoringCase( '@import', $out );
	}

	public function test_caps_length_at_50kb() {
		$out = Css_Sanitizer::sanitize( str_repeat( 'a', 100000 ) );
		$this->assertLessThanOrEqual( Css_Sanitizer::MAX_BYTES, strlen( $out ) );
	}

	public function test_validate_accepts_balanced_braces() {
		$this->assertTrue( Css_Sanitizer::validate( 'a { b { color: red; } }' ) );
	}

	public function test_validate_rejects_unbalanced_braces() {
		$this->assertWPError( Css_Sanitizer::validate( 'a { color: red;' ) );
	}
}
