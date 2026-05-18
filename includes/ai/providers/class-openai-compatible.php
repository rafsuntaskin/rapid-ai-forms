<?php
/**
 * Generic OpenAI-compatible Chat Completions provider.
 *
 * Works with OpenAI, OpenRouter, local LLMs (Ollama, LM Studio), Groq, etc.
 *
 * @package WP_AI_Forms
 */

namespace WP_AI_Forms\Ai\Providers;

use WP_AI_Forms\Ai\Provider;
use WP_AI_Forms\Ai\Schema_Prompt;

defined( 'ABSPATH' ) || exit;

class Openai_Compatible implements Provider {

	public function key() {
		return 'openai_compatible';
	}

	public function label() {
		return __( 'OpenAI-compatible', 'wp-ai-forms' );
	}

	public function generate_form_schema( $prompt, array $options = [] ) {
		$api_key  = $options['api_key'] ?? '';
		$base_url = untrailingslashit( $options['base_url'] ?? 'https://api.openai.com/v1' );
		$model    = $options['model'] ?? 'gpt-4o-mini';

		$response = wp_remote_post(
			$base_url . '/chat/completions',
			[
				'timeout' => 60,
				'headers' => array_filter(
					[
						'Content-Type'  => 'application/json',
						'Authorization' => $api_key ? 'Bearer ' . $api_key : null,
					]
				),
				'body'    => wp_json_encode(
					[
						'model'           => $model,
						'response_format' => [ 'type' => 'json_object' ],
						'messages'        => [
							[ 'role' => 'system', 'content' => Schema_Prompt::system() ],
							[ 'role' => 'user', 'content' => $prompt ],
						],
					]
				),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( wp_remote_retrieve_response_code( $response ) >= 400 ) {
			return new \WP_Error( 'wpaif_openai_error', $body['error']['message'] ?? __( 'AI API error.', 'wp-ai-forms' ) );
		}

		$text = $body['choices'][0]['message']['content'] ?? '';
		return Schema_Prompt::extract_schema( $text );
	}

	public function verify( array $options = [] ) {
		$api_key  = $options['api_key'] ?? '';
		$base_url = untrailingslashit( $options['base_url'] ?? 'https://api.openai.com/v1' );

		if ( ! $api_key ) {
			return new \WP_Error( 'wpaif_missing_key', __( 'API key is required.', 'wp-ai-forms' ) );
		}

		// GET /models is cheap (no token usage) and proves both auth and endpoint reachability.
		$response = wp_remote_get(
			$base_url . '/models',
			[
				'timeout' => 15,
				'headers' => [ 'Authorization' => 'Bearer ' . $api_key ],
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$msg  = $body['error']['message'] ?? __( 'The endpoint rejected this API key.', 'wp-ai-forms' );
			return new \WP_Error( 'wpaif_verify_failed', $msg, [ 'http_status' => $code ] );
		}
		return true;
	}
}
