<?php
/**
 * Registers REST API routes for the plugin.
 *
 * @package WP_AI_Forms
 */

namespace WP_AI_Forms\Api;

use WP_AI_Forms\Ai\Provider_Manager;
use WP_AI_Forms\Ai\Schema_Prompt;
use WP_AI_Forms\Forms\Form_Repository;
use WP_AI_Forms\Forms\Submission_Repository;

defined( 'ABSPATH' ) || exit;

class Rest_Controller {
	const NAMESPACE = 'wp-ai-forms/v1';

	public function register() {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/forms',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'list_forms' ],
					'permission_callback' => [ $this, 'can_manage' ],
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_form' ],
					'permission_callback' => [ $this, 'can_manage' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/forms/(?P<id>\d+)',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_form' ],
					'permission_callback' => [ $this, 'can_manage' ],
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_form' ],
					'permission_callback' => [ $this, 'can_manage' ],
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_form' ],
					'permission_callback' => [ $this, 'can_manage' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/ai/verify',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'ai_verify' ],
				'permission_callback' => [ $this, 'can_manage' ],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/ai/generate',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'ai_generate' ],
				'permission_callback' => [ $this, 'can_manage' ],
				'args'                => [
					'prompt' => [ 'type' => 'string', 'required' => true ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/settings',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_settings' ],
					'permission_callback' => [ $this, 'can_manage' ],
				],
				[
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_settings' ],
					'permission_callback' => [ $this, 'can_manage' ],
				],
			]
		);

		register_rest_route(
			self::NAMESPACE,
			'/submissions/(?P<uuid>[a-f0-9\-]+)',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'submit' ],
				'permission_callback' => '__return_true',
			]
		);
	}

	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	public function list_forms( $req ) {
		$repo  = new Form_Repository();
		$forms = $repo->list(
			[
				'page'     => (int) $req->get_param( 'page' ) ?: 1,
				'per_page' => (int) $req->get_param( 'per_page' ) ?: 20,
			]
		);
		return rest_ensure_response( $forms );
	}

	public function get_form( $req ) {
		$repo = new Form_Repository();
		$form = $repo->get( (int) $req['id'] );
		if ( ! $form ) {
			return new \WP_Error( 'wpaif_not_found', __( 'Form not found.', 'wp-ai-forms' ), [ 'status' => 404 ] );
		}
		return rest_ensure_response( $form );
	}

	public function create_form( $req ) {
		$repo = new Form_Repository();
		$form = $repo->create( $req->get_json_params() ?: [] );
		return rest_ensure_response( $form );
	}

	public function update_form( $req ) {
		$repo = new Form_Repository();
		$form = $repo->update( (int) $req['id'], $req->get_json_params() ?: [] );
		if ( ! $form ) {
			return new \WP_Error( 'wpaif_not_found', __( 'Form not found.', 'wp-ai-forms' ), [ 'status' => 404 ] );
		}
		return rest_ensure_response( $form );
	}

	public function delete_form( $req ) {
		$repo = new Form_Repository();
		$ok   = $repo->delete( (int) $req['id'] );
		return rest_ensure_response( [ 'deleted' => $ok ] );
	}

	public function ai_verify( $req ) {
		$provider_key = sanitize_key( (string) $req->get_param( 'provider' ) );
		$manager      = new Provider_Manager();
		$provider     = $manager->get( $provider_key );
		if ( ! $provider ) {
			return new \WP_Error( 'wpaif_unknown_provider', __( 'Unknown provider.', 'wp-ai-forms' ), [ 'status' => 400 ] );
		}

		// Merge incoming form values over the saved options so the user can verify
		// before saving. Empty fields fall back to the stored value (so partial
		// edits still test the right thing).
		$settings = $manager->settings();
		$saved    = isset( $settings['providers'][ $provider_key ] ) ? $settings['providers'][ $provider_key ] : [];
		$options  = [];
		foreach ( [ 'api_key', 'base_url', 'model' ] as $field ) {
			$incoming = $req->get_param( $field );
			if ( is_string( $incoming ) && '' !== $incoming ) {
				$options[ $field ] = sanitize_text_field( $incoming );
			} elseif ( isset( $saved[ $field ] ) ) {
				$options[ $field ] = $saved[ $field ];
			}
		}

		$start  = microtime( true );
		$result = $provider->verify( $options );
		$ms     = (int) round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $result ) ) {
			$result->add_data( [ 'status' => 400, 'latency_ms' => $ms ] );
			return $result;
		}
		return rest_ensure_response( [ 'ok' => true, 'latency_ms' => $ms ] );
	}

	public function ai_generate( $req ) {
		$prompt         = (string) $req->get_param( 'prompt' );
		$current_schema = $req->get_param( 'current_schema' );
		$manager        = new Provider_Manager();
		$schema         = $manager->generate_form_schema( $prompt, is_array( $current_schema ) ? $current_schema : null );
		if ( is_wp_error( $schema ) ) {
			$schema->add_data( [ 'status' => 400 ] );
			return $schema;
		}
		return rest_ensure_response( $schema );
	}

	public function get_settings() {
		$manager  = new Provider_Manager();
		$settings = $manager->settings();
		// Mask secrets for safety on the wire.
		foreach ( $settings['providers'] as $key => $cfg ) {
			if ( ! empty( $cfg['api_key'] ) ) {
				$settings['providers'][ $key ]['api_key_set'] = true;
				$settings['providers'][ $key ]['api_key']     = '';
			} else {
				$settings['providers'][ $key ]['api_key_set'] = false;
			}
		}
		$providers = [];
		foreach ( $manager->all() as $p ) {
			$providers[] = [ 'key' => $p->key(), 'label' => $p->label() ];
		}
		$settings['available_providers'] = $providers;
		return rest_ensure_response( $settings );
	}

	public function update_settings( $req ) {
		$manager  = new Provider_Manager();
		$current  = $manager->settings();
		$incoming = $req->get_json_params() ?: [];

		if ( isset( $incoming['active_provider'] ) ) {
			$current['active_provider'] = sanitize_key( $incoming['active_provider'] );
		}
		if ( isset( $incoming['providers'] ) && is_array( $incoming['providers'] ) ) {
			foreach ( $incoming['providers'] as $key => $cfg ) {
				if ( ! isset( $current['providers'][ $key ] ) ) {
					continue;
				}
				foreach ( $cfg as $field => $value ) {
					// Empty api_key means "leave as is"; non-empty replaces.
					if ( 'api_key' === $field && '' === $value ) {
						continue;
					}
					$current['providers'][ $key ][ $field ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
				}
			}
		}

		$manager->save_settings( $current );
		return $this->get_settings();
	}

	public function submit( $req ) {
		$uuid = sanitize_text_field( $req['uuid'] );
		$repo = new Form_Repository();
		$form = $repo->get_by_uuid( $uuid );
		if ( ! $form ) {
			return new \WP_Error( 'wpaif_not_found', __( 'Form not found.', 'wp-ai-forms' ), [ 'status' => 404 ] );
		}

		$payload = $req->get_json_params() ?: $req->get_body_params();
		$data    = $this->sanitize_submission( $form, (array) $payload );

		$submissions = new Submission_Repository();
		$id          = $submissions->create( $form['id'], $data );

		/**
		 * Fires after a form submission is stored.
		 *
		 * @param int   $submission_id
		 * @param array $form
		 * @param array $data
		 */
		do_action( 'wp_ai_forms_submission_created', $id, $form, $data );

		return rest_ensure_response( [ 'ok' => true, 'id' => $id ] );
	}

	private function sanitize_submission( array $form, array $payload ) {
		$out    = [];
		$fields = $form['schema']['fields'] ?? [];
		foreach ( $fields as $field ) {
			$name = $field['name'];
			$type = $field['type'] ?? 'text';

			// Hidden fields take their value from the schema, never from the client.
			if ( 'hidden' === $type ) {
				$out[ $name ] = sanitize_text_field( $field['default_value'] ?? '' );
				continue;
			}

			if ( ! array_key_exists( $name, $payload ) ) {
				continue;
			}
			$value = $payload[ $name ];
			switch ( $type ) {
				case 'email':
					$value = sanitize_email( $value );
					break;
				case 'url':
					$value = esc_url_raw( $value );
					break;
				case 'textarea':
					$value = sanitize_textarea_field( $value );
					break;
				case 'number':
					$value = is_numeric( $value ) ? $value + 0 : null;
					break;
				case 'checkbox_group':
					$allowed = array_column( (array) ( $field['options'] ?? [] ), 'value' );
					$value   = array_values( array_intersect( array_map( 'sanitize_text_field', (array) $value ), $allowed ) );
					break;
				default:
					$value = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : sanitize_text_field( $value );
			}
			$out[ $name ] = $value;
		}
		return $out;
	}
}
