<?php
/**
 * Generic OpenAI-compatible Chat Completions provider.
 *
 * Works with OpenAI, OpenRouter, local LLMs (Ollama, LM Studio), Groq, etc.
 *
 * @package Easy_Ai_Forms
 */

namespace Easy_Ai_Forms\Ai\Providers;

use Easy_Ai_Forms\Ai\Provider;
use Easy_Ai_Forms\Ai\Schema_Prompt;

defined( 'ABSPATH' ) || exit;

class Openai_Compatible implements Provider {

	public function key() {
		return 'openai_compatible';
	}

	public function label() {
		return __( 'OpenAI-compatible', 'easy-ai-forms' );
	}

	public function generate_form_schema( $prompt, array $options = array() ) {
		$api_key  = $options['api_key'] ?? '';
		$base_url = untrailingslashit( $options['base_url'] ?? 'https://api.openai.com/v1' );
		$model    = $options['model'] ?? 'gpt-4o-mini';

		$response = wp_remote_post(
			$base_url . '/chat/completions',
			array(
				'timeout' => 60,
				'headers' => array_filter(
					array(
						'Content-Type'  => 'application/json',
						'Authorization' => $api_key ? 'Bearer ' . $api_key : null,
					)
				),
				'body'    => wp_json_encode(
					array(
						'model'           => $model,
						'response_format' => array( 'type' => 'json_object' ),
						'messages'        => array(
							array(
								'role'    => 'system',
								'content' => Schema_Prompt::system(),
							),
							array(
								'role'    => 'user',
								'content' => $prompt,
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( wp_remote_retrieve_response_code( $response ) >= 400 ) {
			return new \WP_Error( 'eaif_openai_error', $body['error']['message'] ?? __( 'AI API error.', 'easy-ai-forms' ) );
		}

		$text = $body['choices'][0]['message']['content'] ?? '';
		return Schema_Prompt::extract_schema( $text );
	}

	public function verify( array $options = array() ) {
		$api_key  = $options['api_key'] ?? '';
		$base_url = untrailingslashit( $options['base_url'] ?? 'https://api.openai.com/v1' );
		$model    = trim( (string) ( $options['model'] ?? '' ) );

		if ( ! $api_key ) {
			return new \WP_Error( 'eaif_missing_key', __( 'API key is required.', 'easy-ai-forms' ) );
		}

		// GET /models is cheap (no token usage) and proves auth, endpoint, and model availability.
		$response = wp_remote_get(
			$base_url . '/models',
			array(
				'timeout' => 15,
				'headers' => array( 'Authorization' => 'Bearer ' . $api_key ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 ) {
			$msg = $body['error']['message'] ?? __( 'The endpoint rejected this API key.', 'easy-ai-forms' );
			return new \WP_Error( 'eaif_verify_failed', $msg, array( 'http_status' => $code ) );
		}

		if ( '' !== $model ) {
			$ids = array_column( (array) ( $body['data'] ?? array() ), 'id' );
			if ( ! in_array( $model, $ids, true ) ) {
				return new \WP_Error(
					'eaif_model_unavailable',
					sprintf(
						/* translators: %s: model id */
						__( 'Model "%s" is not available to this API key. Check the model id or pick one your account can access.', 'easy-ai-forms' ),
						$model
					)
				);
			}
		}
		return true;
	}
}
