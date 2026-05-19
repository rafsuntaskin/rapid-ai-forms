<?php
/**
 * Anthropic Claude provider.
 *
 * @package WP_AI_Forms
 */

namespace WP_AI_Forms\Ai\Providers;

use WP_AI_Forms\Ai\Provider;
use WP_AI_Forms\Ai\Schema_Prompt;

defined( 'ABSPATH' ) || exit;

class Anthropic implements Provider {
	const API_URL = 'https://api.anthropic.com/v1/messages';

	public function key() {
		return 'anthropic';
	}

	public function label() {
		return __( 'Anthropic (Claude)', 'wp-ai-forms' );
	}

	public function generate_form_schema( $prompt, array $options = array() ) {
		$api_key = $options['api_key'] ?? '';
		$model   = $options['model'] ?? 'claude-sonnet-4-6';

		if ( ! $api_key ) {
			return new \WP_Error( 'wpaif_missing_key', __( 'Anthropic API key is not set.', 'wp-ai-forms' ) );
		}

		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 60,
				'headers' => array(
					'Content-Type'      => 'application/json',
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => $model,
						'max_tokens' => 2048,
						'system'     => Schema_Prompt::system(),
						'messages'   => array(
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
			return new \WP_Error( 'wpaif_anthropic_error', $body['error']['message'] ?? __( 'Anthropic API error.', 'wp-ai-forms' ) );
		}

		$text = $body['content'][0]['text'] ?? '';
		return Schema_Prompt::extract_schema( $text );
	}

	public function verify( array $options = array() ) {
		$api_key = $options['api_key'] ?? '';
		if ( ! $api_key ) {
			return new \WP_Error( 'wpaif_missing_key', __( 'API key is required.', 'wp-ai-forms' ) );
		}

		// Anthropic has no public /models list endpoint, so we send the smallest
		// possible messages call (1 output token) to validate auth + model access.
		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type'      => 'application/json',
					'x-api-key'         => $api_key,
					'anthropic-version' => '2023-06-01',
				),
				'body'    => wp_json_encode(
					array(
						'model'      => $options['model'] ?? 'claude-haiku-4-5',
						'max_tokens' => 1,
						'messages'   => array(
							array(
								'role'    => 'user',
								'content' => 'hi',
							),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		if ( $code >= 400 ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			$msg  = $body['error']['message'] ?? __( 'Anthropic rejected this API key.', 'wp-ai-forms' );
			return new \WP_Error( 'wpaif_verify_failed', $msg, array( 'http_status' => $code ) );
		}
		return true;
	}
}
