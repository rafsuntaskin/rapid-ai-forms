<?php
/**
 * Provider that delegates to the WordPress core AI Client (WP 7.0+).
 *
 * Site owner configures credentials in Settings → Connectors. This provider
 * has no api_key / base_url of its own — `verify()` simply checks that the
 * core AI Client is loaded, environment-enabled, and reports the prompt is
 * supported for text generation.
 *
 * @package WP_AI_Forms
 */

namespace WP_AI_Forms\Ai\Providers;

use WP_AI_Forms\Ai\Provider;
use WP_AI_Forms\Ai\Schema_Prompt;

defined( 'ABSPATH' ) || exit;

class Wp_Ai_Client implements Provider {

	public function key() {
		return 'wp_ai_client';
	}

	public function label() {
		return __( 'WordPress AI Client (site connector)', 'wp-ai-forms' );
	}

	/**
	 * Whether the host supports this provider at runtime.
	 *
	 * Used by Provider_Manager to decide whether to expose the provider in
	 * the Settings UI. Returning false hides it on older WP versions.
	 */
	public static function is_available() {
		return function_exists( 'wp_ai_client_prompt' )
			&& function_exists( 'wp_supports_ai' )
			&& wp_supports_ai();
	}

	public function generate_form_schema( $prompt, array $options = array() ) {
		if ( ! self::is_available() ) {
			return new \WP_Error(
				'wpaif_wp_ai_client_unavailable',
				__( 'The WordPress AI Client is not available on this site. Configure a connector under Settings → Connectors, or pick a different provider.', 'wp-ai-forms' )
			);
		}

		$builder = wp_ai_client_prompt( $prompt )
			->using_system_instruction( Schema_Prompt::system() )
			->as_json_response();

		if ( ! $builder->is_supported_for_text_generation() ) {
			return new \WP_Error(
				'wpaif_wp_ai_client_not_supported',
				__( 'No configured connector supports text generation. Add one under Settings → Connectors.', 'wp-ai-forms' )
			);
		}

		$text = $builder->generate_text();
		if ( is_wp_error( $text ) ) {
			return $text;
		}

		return Schema_Prompt::extract_schema( (string) $text );
	}

	public function verify( array $options = array() ) {
		if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
			return new \WP_Error(
				'wpaif_wp_ai_client_missing',
				__( 'WordPress 7.0 or later is required to use the AI Client provider.', 'wp-ai-forms' )
			);
		}
		if ( function_exists( 'wp_supports_ai' ) && ! wp_supports_ai() ) {
			return new \WP_Error(
				'wpaif_wp_ai_client_disabled',
				__( 'AI features are disabled in this environment (WP_AI_SUPPORT is false or filtered off).', 'wp-ai-forms' )
			);
		}

		$builder = wp_ai_client_prompt( 'ping' )->as_json_response();
		if ( ! $builder->is_supported_for_text_generation() ) {
			return new \WP_Error(
				'wpaif_wp_ai_client_no_connector',
				__( 'No connector is configured for text generation. Open Settings → Connectors and add one.', 'wp-ai-forms' )
			);
		}
		return true;
	}
}
