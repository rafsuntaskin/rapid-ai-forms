<?php
/**
 * Prompt assembly + output extraction for the AI CSS editor.
 *
 * @package Rapid_Ai_Forms
 */

namespace Rapid_Ai_Forms\Ai;

use Rapid_Ai_Forms\Forms\Css_Sanitizer;
use Rapid_Ai_Forms\Forms\Form_Renderer;

defined( 'ABSPATH' ) || exit;

class Css_Prompt {

	public static function system() {
		return <<<'PROMPT'
You are a CSS editor for a single WordPress form. Output ONLY CSS — no prose, no markdown fences, no comments explaining your work.

Your output is inserted verbatim inside this wrapper (CSS nesting):

  .raif-form[data-form-uuid="…"] {
    /* YOUR OUTPUT HERE */
  }

Therefore:
- Write nested rules relative to the form root, e.g. `label { … }`, `.raif-field input { … }`, or bare declarations / `&` for the form element itself.
- NEVER write selectors that try to escape the wrapper, and never repeat the data-form-uuid wrapper yourself.
- Do not use @import, expression(), behavior:, or javascript: — they are stripped.
- Prefer the theme's CSS custom properties (listed in the user message) when they fit the request, so the form matches the site's design.
- If current CSS is supplied, EDIT it: preserve rules the user didn't ask to change; modify or add only what's needed. Return the complete resulting CSS, not a diff.
PROMPT;
	}

	/**
	 * Assemble the user message: request + current CSS + real form markup +
	 * selector documentation + theme design tokens.
	 *
	 * @param array  $form        Form row including schema/settings.
	 * @param string $prompt      The user's styling request.
	 * @param string $current_css CSS currently in the editor (may differ from saved).
	 */
	public static function context( array $form, $prompt, $current_css = '' ) {
		$selectors = array(
			'& / bare declarations'               => 'the <form> element itself',
			'.raif-form__title'                   => 'form title (h3)',
			'.raif-field'                         => 'wrapper around each field (label + input)',
			'.raif-field--{type}'                 => 'type-specific wrapper, e.g. .raif-field--email, .raif-field--textarea',
			'.raif-field label'                   => 'field labels',
			'.raif-field input, select, textarea' => 'inputs',
			'.raif-required'                      => 'the * marker on required labels',
			'.raif-checkbox-group'                => 'checkbox group wrapper',
			'.raif-form__actions'                 => 'submit button row',
			'.raif-form__submit'                  => 'submit button',
			'.raif-form__message'                 => 'result message (.is-success / .is-error)',
			'.raif-field-error'                   => 'inline validation error text',
		);

		$parts   = array();
		$parts[] = "User request:\n" . trim( (string) $prompt );

		$current_css = trim( (string) $current_css );
		if ( '' !== $current_css ) {
			$parts[] = "Current CSS (edit this, preserve what wasn't asked to change):\n" . $current_css;
		}

		$selector_lines = array();
		foreach ( $selectors as $sel => $desc ) {
			$selector_lines[] = $sel . ' — ' . $desc;
		}
		$parts[] = "Available selectors (relative to the form root):\n" . implode( "\n", $selector_lines );

		$tokens = self::theme_tokens();
		if ( $tokens ) {
			$parts[] = "Theme design tokens (use as var(--name)):\n" . implode( "\n", $tokens );
		}

		$parts[] = "Rendered form HTML (for reference):\n" . ( new Form_Renderer() )->render( $form );

		return implode( "\n\n---\n\n", $parts );
	}

	/**
	 * Pull usable CSS custom properties out of the active theme's
	 * theme.json (block themes). Classic themes simply yield nothing.
	 *
	 * @return string[] Lines like "--wp--preset--color--primary: #123456".
	 */
	private static function theme_tokens() {
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return array();
		}
		$settings = wp_get_global_settings();
		$lines    = array();

		foreach ( (array) ( $settings['color']['palette']['theme'] ?? array() ) as $color ) {
			if ( ! empty( $color['slug'] ) && ! empty( $color['color'] ) ) {
				$lines[] = sprintf( '--wp--preset--color--%s: %s (%s)', $color['slug'], $color['color'], $color['name'] ?? $color['slug'] );
			}
		}
		foreach ( (array) ( $settings['typography']['fontFamilies']['theme'] ?? array() ) as $font ) {
			if ( ! empty( $font['slug'] ) ) {
				$lines[] = sprintf( '--wp--preset--font-family--%s: %s', $font['slug'], $font['fontFamily'] ?? '' );
			}
		}
		foreach ( (array) ( $settings['spacing']['spacingSizes']['theme'] ?? array() ) as $size ) {
			if ( ! empty( $size['slug'] ) && ! empty( $size['size'] ) ) {
				$lines[] = sprintf( '--wp--preset--spacing--%s: %s', $size['slug'], $size['size'] );
			}
		}
		if ( ! empty( $settings['border']['radius'] ) && is_scalar( $settings['border']['radius'] ) ) {
			$lines[] = 'border radius preference: ' . $settings['border']['radius'];
		}

		return $lines;
	}

	/**
	 * Extract clean CSS from a model response: strip markdown fences, then
	 * sanitize and structurally validate.
	 *
	 * @param string $text Raw model output.
	 * @return string|\WP_Error
	 */
	public static function extract_css( $text ) {
		$text = trim( (string) $text );

		if ( preg_match( '/```(?:css)?\s*(.*?)```/s', $text, $m ) ) {
			$text = $m[1];
		}

		$css = Css_Sanitizer::sanitize( $text );
		if ( '' === $css ) {
			return new \WP_Error( 'raif_empty_css', __( 'The AI did not return any usable CSS. Try rephrasing the request.', 'rapid-ai-forms' ) );
		}

		$valid = Css_Sanitizer::validate( $css );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		return $css;
	}
}
