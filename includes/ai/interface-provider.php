<?php
/**
 * AI provider contract.
 *
 * @package WP_AI_Forms
 */

namespace WP_AI_Forms\Ai;

defined( 'ABSPATH' ) || exit;

interface Provider {
	/**
	 * Unique provider key, e.g. "openai", "anthropic", "gemini", "managed".
	 */
	public function key();

	/**
	 * Human-readable label.
	 */
	public function label();

	/**
	 * Generate a form schema from a natural-language prompt.
	 *
	 * @param string $prompt  User request describing the form.
	 * @param array  $options Provider options (api_key, model, etc.)
	 * @return array|\WP_Error Form schema array or WP_Error on failure.
	 */
	public function generate_form_schema( $prompt, array $options = [] );
}
