<?php
/**
 * AI provider contract.
 *
 * @package Rapid_Ai_Forms
 */

namespace Rapid_Ai_Forms\Ai;

defined( 'ABSPATH' ) || exit;

interface Provider {
	/**
	 * Unique provider key, e.g. "anthropic", "gemini", "openai_compatible".
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
	public function generate_form_schema( $prompt, array $options = array() );

	/**
	 * Generate free-form text from a system + user prompt. Used for
	 * non-schema tasks (e.g. the AI CSS editor).
	 *
	 * @param string $system  System instruction.
	 * @param string $prompt  User message.
	 * @param array  $options Provider options (api_key, model, etc.)
	 * @return string|\WP_Error Raw model text or WP_Error on failure.
	 */
	public function generate_text( $system, $prompt, array $options = array() );

	/**
	 * Lightweight credential check. Should make the cheapest possible
	 * round-trip that proves the API key + endpoint work.
	 *
	 * @param array $options Provider options (api_key, model, base_url, etc.)
	 * @return true|\WP_Error
	 */
	public function verify( array $options = array() );
}
