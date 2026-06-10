<?php
/**
 * Google Gemini provider.
 *
 * @package Rapid_Ai_Forms
 */

namespace Rapid_Ai_Forms\Ai\Providers;

use Rapid_Ai_Forms\Ai\Provider;
use Rapid_Ai_Forms\Ai\Schema_Prompt;

defined( 'ABSPATH' ) || exit;

class Gemini implements Provider {

	public function key() {
		return 'gemini';
	}

	public function label() {
		return __( 'Google Gemini', 'rapid-ai-forms' );
	}

	public function generate_form_schema( $prompt, array $options = array() ) {
		$text = $this->request_text( Schema_Prompt::system(), $prompt, $options, true );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		return Schema_Prompt::extract_schema( $text );
	}

	public function generate_text( $system, $prompt, array $options = array() ) {
		return $this->request_text( $system, $prompt, $options, false );
	}

	/**
	 * @param bool $json_mode Ask for a JSON response (schema generation).
	 */
	private function request_text( $system, $prompt, array $options, $json_mode ) {
		$api_key = $options['api_key'] ?? '';
		$model   = $options['model'] ?? 'gemini-2.0-flash';

		if ( ! $api_key ) {
			return new \WP_Error( 'raif_missing_key', __( 'Gemini API key is not set.', 'rapid-ai-forms' ) );
		}

		$url = sprintf( 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s', rawurlencode( $model ), rawurlencode( $api_key ) );

		$payload = array(
			'systemInstruction' => array( 'parts' => array( array( 'text' => $system ) ) ),
			'contents'          => array(
				array(
					'role'  => 'user',
					'parts' => array( array( 'text' => $prompt ) ),
				),
			),
		);
		if ( $json_mode ) {
			$payload['generationConfig'] = array( 'responseMimeType' => 'application/json' );
		}

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 60,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( wp_remote_retrieve_response_code( $response ) >= 400 ) {
			return new \WP_Error( 'raif_gemini_error', $body['error']['message'] ?? __( 'Gemini API error.', 'rapid-ai-forms' ) );
		}

		return (string) ( $body['candidates'][0]['content']['parts'][0]['text'] ?? '' );
	}

	public function verify( array $options = array() ) {
		$api_key = $options['api_key'] ?? '';
		$model   = trim( (string) ( $options['model'] ?? '' ) );

		if ( ! $api_key ) {
			return new \WP_Error( 'raif_missing_key', __( 'API key is required.', 'rapid-ai-forms' ) );
		}

		$response = wp_remote_get(
			'https://generativelanguage.googleapis.com/v1beta/models?key=' . rawurlencode( $api_key ),
			array( 'timeout' => 15 )
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 ) {
			$msg = $body['error']['message'] ?? __( 'Google rejected this API key.', 'rapid-ai-forms' );
			return new \WP_Error( 'raif_verify_failed', $msg, array( 'http_status' => $code ) );
		}

		if ( '' !== $model ) {
			// Gemini returns names like "models/gemini-2.0-flash"; users may enter
			// either form. Normalize to the bare id for comparison.
			$ids   = array_map(
				static function ( $m ) {
					$name = $m['name'] ?? '';
					return 0 === strpos( $name, 'models/' ) ? substr( $name, 7 ) : $name;
				},
				(array) ( $body['models'] ?? array() )
			);
			$short = 0 === strpos( $model, 'models/' ) ? substr( $model, 7 ) : $model;
			if ( ! in_array( $short, $ids, true ) ) {
				return new \WP_Error(
					'raif_model_unavailable',
					sprintf(
						/* translators: %s: model id */
						__( 'Model "%s" is not available to this API key. Check the model id at ai.google.dev.', 'rapid-ai-forms' ),
						$model
					)
				);
			}
		}
		return true;
	}
}
