<?php
/**
 * Sanitizes per-form custom CSS.
 *
 * Same trust model as the Customizer's Additional CSS: admins may write
 * arbitrary CSS, but tokens that could break out of the <style> element
 * or execute script are stripped. Runs on every write (human or AI) and
 * again at render time as defense in depth.
 *
 * @package Rapid_Ai_Forms
 */

namespace Rapid_Ai_Forms\Forms;

defined( 'ABSPATH' ) || exit;

class Css_Sanitizer {

	const MAX_BYTES = 51200; // 50 KB hard cap.

	/**
	 * Tokens removed case-insensitively. `javascript:` also covers
	 * `url(javascript:...)`; `@import` keeps the CSS self-contained.
	 */
	const BANNED = array( '</style', '<script', 'javascript:', 'expression(', '@import', 'behavior:' );

	/**
	 * @param string $css Raw CSS.
	 * @return string Sanitized CSS (possibly empty).
	 */
	public static function sanitize( $css ) {
		$css = substr( (string) $css, 0, self::MAX_BYTES );

		// Strip until stable so overlapping tokens can't reassemble
		// (e.g. "java<scriptscript:" → "javascript:").
		do {
			$before = $css;
			$css    = str_ireplace( self::BANNED, '', $css );
		} while ( $before !== $css );

		return trim( $css );
	}

	/**
	 * Cheap structural check — rejects obvious garbage (e.g. prose from an
	 * AI response) without a full CSS parser.
	 *
	 * @param string $css Sanitized CSS.
	 * @return true|\WP_Error
	 */
	public static function validate( $css ) {
		if ( substr_count( $css, '{' ) !== substr_count( $css, '}' ) ) {
			return new \WP_Error( 'raif_invalid_css', __( 'The CSS has unbalanced braces.', 'rapid-ai-forms' ) );
		}
		return true;
	}
}
