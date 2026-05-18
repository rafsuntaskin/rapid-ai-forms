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

	public function verify( array $options = [] ) {
		$api_key = $options['api_key'] ?? '';
		$model   = trim( (string) ( $options['model'] ?? '' ) );

		if ( ! $api_key ) {
			return new \WP_Error( 'wpaif_missing_key', __( 'API key is required.', 'wp-ai-forms' ) );
		}

		$response = wp_remote_get(
			'https://generativelanguage.googleapis.com/v1beta/models?key=' . rawurlencode( $api_key ),
			[ 'timeout' => 15 ]
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 ) {
			$msg = $body['error']['message'] ?? __( 'Google rejected this API key.', 'wp-ai-forms' );
			return new \WP_Error( 'wpaif_verify_failed', $msg, [ 'http_status' => $code ] );
		}

		if ( '' !== $model ) {
			// Gemini returns names like "models/gemini-2.0-flash"; users may enter
			// either form. Normalize to the bare id for comparison.
			$ids = array_map(
				static function ( $m ) {
					$name = $m['name'] ?? '';
					return 0 === strpos( $name, 'models/' ) ? substr( $name, 7 ) : $name;
				},
				(array) ( $body['models'] ?? [] )
			);
			$short = 0 === strpos( $model, 'models/' ) ? substr( $model, 7 ) : $model;
			if ( ! in_array( $short, $ids, true ) ) {
				return new \WP_Error(
					'wpaif_model_unavailable',
					sprintf(
						/* translators: %s: model id */
						__( 'Model "%s" is not available to this API key. Check the model id at ai.google.dev.', 'wp-ai-forms' ),
						$model
					)
				);
			}
		}
		return true;
	}
}
