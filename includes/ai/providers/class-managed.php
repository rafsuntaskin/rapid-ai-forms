<?php
/**
 * Rapid AI Cloud — our hosted provider with a free monthly quota.
 *
 * The plugin never holds an LLM key for this provider; it holds a per-site
 * bearer token issued through a domain-verification handshake (see
 * docs/PLAN-rapid-ai-cloud.md). The backend authenticates the token,
 * debits the site's quota, and proxies the LLM call with server-held keys.
 *
 * @package Rapid_Ai_Forms
 */

namespace Rapid_Ai_Forms\Ai\Providers;

use Rapid_Ai_Forms\Ai\Provider;
use Rapid_Ai_Forms\Ai\Provider_Manager;
use Rapid_Ai_Forms\Ai\Schema_Prompt;

defined( 'ABSPATH' ) || exit;

class Managed implements Provider {

	const STATUS_TRANSIENT = 'rapid_ai_forms_managed_status';

	/**
	 * Whether the hosted provider is offered on this site.
	 *
	 * Off by default until the backend is live — flip the default to true in
	 * the release that launches Rapid AI Cloud. A site that already holds a
	 * connection keeps the provider so generation doesn't silently break.
	 * Dev/test environments enable it via the filter (alongside
	 * rapid_ai_forms_managed_endpoint).
	 */
	public static function is_enabled() {
		$default = defined( 'RAPID_AI_FORMS_CLOUD_ENABLED' ) && RAPID_AI_FORMS_CLOUD_ENABLED;

		if ( ! $default ) {
			$settings = get_option( Provider_Manager::OPTION_KEY, array() );
			$default  = ! empty( $settings['providers']['managed']['site_token'] );
		}

		/**
		 * Offer the Rapid AI Cloud provider.
		 *
		 * @param bool $enabled
		 */
		return (bool) apply_filters( 'rapid_ai_forms_managed_enabled', $default );
	}

	public function key() {
		return 'managed';
	}

	public function label() {
		return __( 'Rapid AI Cloud (Free)', 'rapid-ai-forms' );
	}

	/**
	 * Backend base URL. Filterable so dev/test environments can point at a
	 * stub or staging deployment.
	 */
	public function endpoint() {
		return untrailingslashit( (string) apply_filters( 'rapid_ai_forms_managed_endpoint', 'https://api.rapidaiforms.com' ) );
	}

	/**
	 * Begin the connect handshake: ask the backend to verify this site by
	 * calling back our public /managed/verify route with the nonce.
	 *
	 * @param string $nonce Single-use registration nonce (already stored in a transient by the caller).
	 * @return array{token: string}|\WP_Error
	 */
	public function register( $nonce ) {
		$response = wp_remote_post(
			$this->endpoint() . '/v1/register',
			array(
				'timeout' => 30,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'home_url'       => home_url( '/' ),
						'nonce'          => (string) $nonce,
						'plugin_version' => RAPID_AI_FORMS_VERSION,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code >= 400 || empty( $body['token'] ) ) {
			if ( 'site_unreachable' === ( $body['code'] ?? '' ) ) {
				return new \WP_Error(
					'raif_site_unreachable',
					__( 'Rapid AI Cloud could not reach this site to verify it. Sites that are local, behind a login, or on an intranet cannot use the free tier — add your own API key under one of the other providers instead.', 'rapid-ai-forms' )
				);
			}
			return new \WP_Error(
				'raif_managed_error',
				$body['message'] ?? __( 'Could not connect to Rapid AI Cloud. Please try again.', 'rapid-ai-forms' )
			);
		}

		return array( 'token' => (string) $body['token'] );
	}

	/**
	 * Quota / connection status. Callers cache the result (see Rest_Controller).
	 *
	 * @param array $options Provider options ({ site_token, site_url }).
	 * @return array|\WP_Error
	 */
	public function status( array $options = array() ) {
		$result = $this->request( 'GET', '/v1/status', null, $options );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return $result;
	}

	public function generate_form_schema( $prompt, array $options = array() ) {
		$body = $this->request( 'POST', '/v1/generate-form', array( 'prompt' => (string) $prompt ), $options );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$this->remember_usage( $body );

		// Backends SHOULD return a ready schema; raw text is accepted as a fallback.
		if ( isset( $body['schema'] ) && is_array( $body['schema'] ) ) {
			return Schema_Prompt::sanitize_schema( $body['schema'] );
		}
		if ( isset( $body['text'] ) && is_string( $body['text'] ) ) {
			return Schema_Prompt::extract_schema( $body['text'] );
		}
		return new \WP_Error( 'raif_managed_error', __( 'Rapid AI Cloud returned an unexpected response.', 'rapid-ai-forms' ) );
	}

	public function generate_text( $system, $prompt, array $options = array() ) {
		$body = $this->request(
			'POST',
			'/v1/generate-text',
			array(
				'system' => (string) $system,
				'prompt' => (string) $prompt,
			),
			$options
		);
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		$this->remember_usage( $body );

		if ( isset( $body['text'] ) && is_string( $body['text'] ) ) {
			return $body['text'];
		}
		return new \WP_Error( 'raif_managed_error', __( 'Rapid AI Cloud returned an unexpected response.', 'rapid-ai-forms' ) );
	}

	public function verify( array $options = array() ) {
		if ( empty( $options['site_token'] ) ) {
			return new \WP_Error( 'raif_not_connected', __( 'Not connected to Rapid AI Cloud yet. Click Connect to set it up.', 'rapid-ai-forms' ) );
		}
		$status = $this->status( $options );
		return is_wp_error( $status ) ? $status : true;
	}

	/**
	 * Authenticated request with the shared error mapping from SPEC §5A.
	 *
	 * @return array|\WP_Error Decoded response body.
	 */
	private function request( $method, $path, $body, array $options ) {
		$token = (string) ( $options['site_token'] ?? '' );
		if ( '' === $token ) {
			return new \WP_Error( 'raif_not_connected', __( 'Not connected to Rapid AI Cloud yet. Click Connect to set it up.', 'rapid-ai-forms' ) );
		}

		$args = array(
			'method'  => $method,
			'timeout' => 60,
			'headers' => array(
				'Content-Type'     => 'application/json',
				'Authorization'    => 'Bearer ' . $token,
				'X-Site-URL'       => home_url( '/' ),
				'X-Plugin-Version' => RAPID_AI_FORMS_VERSION,
			),
		);
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $this->endpoint() . $path, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code    = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );
		$decoded = is_array( $decoded ) ? $decoded : array();

		if ( $code < 400 ) {
			return $decoded;
		}

		// Error mapping per docs/SPEC.md §5A. Free-tier copy must stay
		// neutral — no purchase language in the plugin.
		switch ( $code ) {
			case 401:
				delete_transient( self::STATUS_TRANSIENT );
				return new \WP_Error(
					'raif_managed_reconnect',
					__( 'Your Rapid AI Cloud connection is no longer valid. Open Settings and reconnect.', 'rapid-ai-forms' )
				);
			case 402:
			case 429:
				return new \WP_Error(
					'raif_quota_reached',
					$decoded['message'] ?? __( 'You’ve reached this month’s free usage limit for Rapid AI Cloud.', 'rapid-ai-forms' )
				);
			case 403:
				return new \WP_Error(
					'raif_managed_error',
					__( 'This connection belongs to a different site URL. Disconnect and reconnect from this site.', 'rapid-ai-forms' )
				);
			default:
				return new \WP_Error(
					'raif_managed_error',
					$decoded['message'] ?? __( 'Rapid AI Cloud is temporarily unavailable. Please try again shortly.', 'rapid-ai-forms' )
				);
		}
	}

	/**
	 * Generate responses carry a usage block; fold it into the cached status
	 * so the Settings quota meter counts down without re-polling.
	 */
	private function remember_usage( array $body ) {
		if ( empty( $body['usage'] ) || ! is_array( $body['usage'] ) ) {
			return;
		}
		$status = get_transient( self::STATUS_TRANSIENT );
		$status = is_array( $status ) ? $status : array();
		set_transient( self::STATUS_TRANSIENT, array_merge( $status, $body['usage'] ), 5 * MINUTE_IN_SECONDS );
	}
}
