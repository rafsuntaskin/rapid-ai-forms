<?php
/**
 * Managed (credit-based) AI provider — calls our own backend service.
 *
 * @package WP_AI_Forms
 */

namespace WP_AI_Forms\Ai\Providers;

use WP_AI_Forms\Ai\Provider;
use WP_AI_Forms\Ai\Schema_Prompt;

defined( 'ABSPATH' ) || exit;

class Managed implements Provider {

	const ENDPOINT_FILTER = 'wp_ai_forms_managed_endpoint';
	const DEFAULT_ENDPOINT = 'https://api.example.com/v1/generate-form';

	public function key() {
		return 'managed';
	}

	public function label() {
		return __( 'Managed (credit-based)', 'wp-ai-forms' );
	}

	public function generate_form_schema( $prompt, array $options = [] ) {
		$license = $options['license_key'] ?? '';
		if ( ! $license ) {
			return new \WP_Error( 'wpaif_missing_license', __( 'A license key is required for the managed service.', 'wp-ai-forms' ) );
		}

		$endpoint = apply_filters( self::ENDPOINT_FILTER, self::DEFAULT_ENDPOINT );

		$response = wp_remote_post(
			$endpoint,
			[
				'timeout' => 60,
				'headers' => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $license,
					'X-Site-URL'    => home_url(),
				],
				'body'    => wp_json_encode( [ 'prompt' => $prompt ] ),
			]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 400 ) {
			$code = $status === 402 ? 'wpaif_no_credits' : 'wpaif_managed_error';
			return new \WP_Error( $code, $body['message'] ?? __( 'Managed service error.', 'wp-ai-forms' ) );
		}

		// Managed service returns either a schema directly or wrapped text.
		if ( isset( $body['schema'] ) && is_array( $body['schema'] ) ) {
			return $body['schema'];
		}
		return Schema_Prompt::extract_schema( $body['text'] ?? '' );
	}
}
