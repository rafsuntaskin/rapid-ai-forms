<?php
/**
 * Rapid AI Cloud — hosted free-quota provider.
 *
 * No plugin-held API keys: the site proves domain ownership through a
 * one-time handshake (see Rest_Controller managed routes) and receives a
 * site-bound bearer token. All later calls send that token plus the site
 * URL so the backend can enforce the domain<->token binding.
 *
 * Paid/credit language lives only on the website, never here (wp.org rule).
 *
 * @package Rapid_Ai_Forms
 */

namespace Rapid_Ai_Forms\Ai\Providers;

use Rapid_Ai_Forms\Ai\Provider;
use Rapid_Ai_Forms\Ai\Schema_Prompt;

defined( 'ABSPATH' ) || exit;

class Managed implements Provider {

	/**
	 * Whether the hosted provider is exposed. Gated off by default until the
	 * post-launch rollout; the dev mu-plugin and the future managed UI flip
	 * `rapid_ai_forms_managed_enabled` on. Keeps the provider, its Settings
	 * card, and its REST routes out of the MVP per the wp.org launch plan.
	 */
	public static function is_enabled() {
		return (bool) apply_filters( 'rapid_ai_forms_managed_enabled', false );
	}

	public function key() {
		return 'managed';
	}

	public function label() {
		return __( 'Rapid AI Cloud (Free)', 'rapid-ai-forms' );
	}

	/**
	 * Backend base URL. Filterable so a dev/mock backend can be targeted
	 * (the wooDev mu-plugin points this at host.docker.internal:8787).
	 */
	public function endpoint() {
		return untrailingslashit(
			apply_filters( 'rapid_ai_forms_managed_endpoint', 'https://api.rapidaiforms.com' )
		);
	}

	public function generate_form_schema( $prompt, array $options = array() ) {
		$body = $this->request( 'POST', '/v1/generate-form', array( 'prompt' => (string) $prompt ), $options );
		if ( is_wp_error( $body ) ) {
			return $body;
		}

		// The backend returns a parsed `schema` when the model produced valid
		// JSON, else raw `text` for our own extractor. Either way schema
		// validation stays centralized in Schema_Prompt.
		if ( isset( $body['schema'] ) && is_array( $body['schema'] ) ) {
			return Schema_Prompt::sanitize_schema( $body['schema'] );
		}
		if ( isset( $body['text'] ) ) {
			return Schema_Prompt::extract_schema( (string) $body['text'] );
		}
		return new \WP_Error( 'raif_empty_response', __( 'Rapid AI Cloud returned an empty response.', 'rapid-ai-forms' ) );
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
		return (string) ( $body['text'] ?? '' );
	}

	public function verify( array $options = array() ) {
		if ( empty( $options['site_token'] ) ) {
			return new \WP_Error( 'raif_not_connected', __( 'Not connected to Rapid AI Cloud.', 'rapid-ai-forms' ) );
		}
		$status = $this->status( $options );
		if ( is_wp_error( $status ) ) {
			return $status;
		}
		return true;
	}

	/**
	 * Exchange a one-time domain-verification nonce for a site-bound token.
	 * Called by the REST /managed/register handler; unauthenticated (the
	 * token does not exist yet).
	 *
	 * @param string $nonce Single-use nonce also stored in a 5-min transient.
	 * @return string|\WP_Error The bearer token, or an error.
	 */
	public function register( $nonce ) {
		$body = $this->request(
			'POST',
			'/v1/register',
			array(
				'home_url'       => home_url(),
				'nonce'          => (string) $nonce,
				'plugin_version' => RAPID_AI_FORMS_VERSION,
			),
			array() // no auth yet
		);
		if ( is_wp_error( $body ) ) {
			return $body;
		}
		$token = isset( $body['token'] ) ? (string) $body['token'] : '';
		if ( '' === $token ) {
			return new \WP_Error( 'raif_register_failed', __( 'Rapid AI Cloud did not return a token.', 'rapid-ai-forms' ) );
		}
		return $token;
	}

	/**
	 * Current quota/account state for the connected site.
	 *
	 * @param array $options Provider options (needs site_token).
	 * @return array|\WP_Error
	 */
	public function status( array $options = array() ) {
		return $this->request( 'GET', '/v1/status', null, $options );
	}

	/**
	 * Shared HTTP helper. Attaches auth headers when a site_token is present,
	 * decodes the JSON envelope, and maps backend error codes to friendly,
	 * wp.org-safe WP_Errors.
	 *
	 * @param string     $method  HTTP method.
	 * @param string     $path    Path appended to endpoint().
	 * @param array|null $payload JSON body, or null for GET.
	 * @param array      $options Provider options (site_token).
	 * @return array|\WP_Error Decoded response body or error.
	 */
	private function request( $method, $path, $payload, array $options ) {
		$headers = array( 'Content-Type' => 'application/json' );

		if ( ! empty( $options['site_token'] ) ) {
			$headers['Authorization']    = 'Bearer ' . $options['site_token'];
			$headers['X-Site-URL']       = home_url();
			$headers['X-Plugin-Version'] = RAPID_AI_FORMS_VERSION;
		}

		$args = array(
			'method'  => $method,
			'timeout' => 60,
			'headers' => $headers,
		);
		if ( null !== $payload ) {
			$args['body'] = wp_json_encode( $payload );
		}

		$response = wp_remote_request( $this->endpoint() . $path, $args );
		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'raif_managed_unreachable',
				__( 'Could not reach Rapid AI Cloud. Please try again.', 'rapid-ai-forms' )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		if ( $code >= 200 && $code < 300 ) {
			return $body;
		}

		return $this->map_error( $code, $body );
	}

	/**
	 * Translate an HTTP status + backend error envelope into a WP_Error.
	 * The 402/429 quota copy is shown to the user verbatim and must stay
	 * neutral (no "buy"/"upgrade" language) per the wp.org guidelines.
	 */
	private function map_error( $code, array $body ) {
		switch ( $code ) {
			case 401:
				return new \WP_Error(
					'raif_reconnect',
					__( 'Your Rapid AI Cloud connection expired. Please reconnect.', 'rapid-ai-forms' ),
					array( 'http_status' => 401 )
				);
			case 402:
			case 429:
				return new \WP_Error(
					'raif_quota_reached',
					__( "You've reached this month's free usage limit.", 'rapid-ai-forms' ),
					array( 'http_status' => $code )
				);
		}

		if ( $code >= 500 ) {
			return new \WP_Error(
				'raif_managed_server',
				__( 'Rapid AI Cloud is temporarily unavailable. Please try again.', 'rapid-ai-forms' ),
				array( 'http_status' => $code )
			);
		}

		// 4xx (bad request, site mismatch, unreachable during register, etc.):
		// surface the backend message when present.
		$message = isset( $body['message'] ) && is_string( $body['message'] )
			? $body['message']
			: __( 'Rapid AI Cloud could not complete the request.', 'rapid-ai-forms' );
		return new \WP_Error( 'raif_managed_error', $message, array( 'http_status' => $code ) );
	}
}
