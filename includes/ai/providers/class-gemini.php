<?php
/**
 * Google Gemini provider.
 *
 * @package WP_AI_Forms
 */

namespace WP_AI_Forms\Ai\Providers;

use WP_AI_Forms\Ai\Provider;
use WP_AI_Forms\Ai\Schema_Prompt;

defined( 'ABSPATH' ) || exit;

class Gemini implements Provider {

	public function key() {
		return 'gemini';
	}

	public function label() {
		return __( 'Google Gemini', 'wp-ai-forms' );
	}

	public function generate_form_schema( $prompt, array $options = [] ) {
		$api_key = $options['api_key'] ?? '';
		$model   = $options['model'] ?? 'gemini-2.0-flash';

		if ( ! $api_key ) {
			return new \WP_Error( 'wpaif_missing_key', __( 'Gemini API key is not set.', 'wp-ai-forms' ) );
		}

		$url = sprintf( 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s', rawurlencode( $model ), rawurlencode( $api_key ) );

		$response = wp_remote_post(
			$url,
			[
				'timeout' => 60,
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode(
					[
						'systemInstruction' => [ 'parts' => [ [ 'text' => Schema_Prompt::system() ] ] ],
						'contents'          => [
							[ 'role' => 'user', 'parts' => [ [ 'text' => $prompt ] ] ],
						],
						'generationConfig'  => [ 'responseMimeType' => 'application/json' ],
					]
				),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( wp_remote_retrieve_response_code( $response ) >= 400 ) {
			return new \WP_Error( 'wpaif_gemini_error', $body['error']['message'] ?? __( 'Gemini API error.', 'wp-ai-forms' ) );
		}

		$text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';
		return Schema_Prompt::extract_schema( $text );
	}
}
