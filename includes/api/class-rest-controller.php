<?php
/**
 * Registers REST API routes for the plugin.
 *
 * @package Rapid_Ai_Forms
 */

namespace Rapid_Ai_Forms\Api;

use Rapid_Ai_Forms\Ai\Css_Prompt;
use Rapid_Ai_Forms\Ai\Provider_Manager;
use Rapid_Ai_Forms\Ai\Schema_Prompt;
use Rapid_Ai_Forms\Forms\Form_Repository;
use Rapid_Ai_Forms\Forms\Submission_Repository;
use Rapid_Ai_Forms\Notifications\Email_Notifier;

defined( 'ABSPATH' ) || exit;

class Rest_Controller {
	const NAMESPACE = 'rapid-ai-forms/v1';

	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/forms',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_forms' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_form' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/forms/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_form' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_form' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_form' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		// Admin collection — distinct from the public POST /submissions/{uuid} below.
		register_rest_route(
			self::NAMESPACE,
			'/submissions',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_submissions' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ai/verify',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ai_verify' ),
				'permission_callback' => array( $this, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ai/generate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ai_generate' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'prompt' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/ai/style',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ai_style' ),
				'permission_callback' => array( $this, 'can_manage' ),
				'args'                => array(
					'form_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
					'prompt'  => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'can_manage' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/submissions/(?P<uuid>[a-f0-9\-]+)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'submit' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function can_manage() {
		return current_user_can( 'manage_options' );
	}

	public function list_forms( $req ) {
		$repo     = new Form_Repository();
		$page     = max( 1, (int) $req->get_param( 'page' ) ?: 1 );
		$per_page = max( 1, min( 100, (int) $req->get_param( 'per_page' ) ?: 20 ) );
		$search   = sanitize_text_field( (string) $req->get_param( 'search' ) );

		$args = array(
			'page'     => $page,
			'per_page' => $per_page,
			'search'   => $search,
		);

		$forms = $repo->list( $args );
		$total = $repo->count( array( 'search' => $search ) );

		// Mirrors WP core's wp/v2 collection convention so the admin UI can
		// read totals without parsing a custom envelope.
		$response = rest_ensure_response( $forms );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $total / $per_page ) ) );
		return $response;
	}

	public function get_form( $req ) {
		$repo = new Form_Repository();
		$form = $repo->get( (int) $req['id'] );
		if ( ! $form ) {
			return new \WP_Error( 'raif_not_found', __( 'Form not found.', 'rapid-ai-forms' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $form );
	}

	public function create_form( $req ) {
		$repo = new Form_Repository();
		$form = $repo->create( $req->get_json_params() ?: array() );
		return rest_ensure_response( $form );
	}

	public function update_form( $req ) {
		$repo = new Form_Repository();
		$form = $repo->update( (int) $req['id'], $req->get_json_params() ?: array() );
		if ( ! $form ) {
			return new \WP_Error( 'raif_not_found', __( 'Form not found.', 'rapid-ai-forms' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $form );
	}

	public function delete_form( $req ) {
		$repo = new Form_Repository();
		$ok   = $repo->delete( (int) $req['id'] );
		return rest_ensure_response( array( 'deleted' => $ok ) );
	}

	public function list_submissions( $req ) {
		$page     = max( 1, (int) $req->get_param( 'page' ) ?: 1 );
		$per_page = max( 1, min( 100, (int) $req->get_param( 'per_page' ) ?: 20 ) );
		$form_id  = max( 0, (int) $req->get_param( 'form_id' ) );

		$submissions = new Submission_Repository();
		$rows        = $submissions->list(
			array(
				'form_id'  => $form_id,
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
		$total       = $submissions->count( $form_id );

		// Attach the rendered notification email (using the form's current
		// template) so the admin view can show what was sent. Null when
		// notifications are disabled for the form.
		$form_repo = new Form_Repository();
		$notifier  = new Email_Notifier();
		$forms     = array();
		foreach ( $rows as &$row ) {
			$fid = (int) $row['form_id'];
			if ( ! array_key_exists( $fid, $forms ) ) {
				$forms[ $fid ] = $form_repo->get( $fid );
			}
			$row['email'] = $forms[ $fid ] ? $notifier->compose( $forms[ $fid ], $row['data'] ) : null;
		}
		unset( $row );

		$response = rest_ensure_response( $rows );
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $total / $per_page ) ) );
		return $response;
	}

	public function ai_verify( $req ) {
		$provider_key = sanitize_key( (string) $req->get_param( 'provider' ) );
		$manager      = new Provider_Manager();
		$provider     = $manager->get( $provider_key );
		if ( ! $provider ) {
			return new \WP_Error( 'raif_unknown_provider', __( 'Unknown provider.', 'rapid-ai-forms' ), array( 'status' => 400 ) );
		}

		// Merge incoming form values over the saved options so the user can verify
		// before saving. Empty fields fall back to the stored value (so partial
		// edits still test the right thing).
		$settings = $manager->settings();
		$saved    = isset( $settings['providers'][ $provider_key ] ) ? $settings['providers'][ $provider_key ] : array();
		$options  = array();
		foreach ( array( 'api_key', 'base_url', 'model' ) as $field ) {
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
			$result->add_data(
				array(
					'status'     => 400,
					'latency_ms' => $ms,
				)
			);
			return $result;
		}
		return rest_ensure_response(
			array(
				'ok'         => true,
				'latency_ms' => $ms,
			)
		);
	}

	public function ai_style( $req ) {
		$form = ( new Form_Repository() )->get( (int) $req->get_param( 'form_id' ) );
		if ( ! $form ) {
			return new \WP_Error( 'raif_not_found', __( 'Form not found.', 'rapid-ai-forms' ), array( 'status' => 404 ) );
		}

		$prompt      = (string) $req->get_param( 'prompt' );
		$current_css = (string) $req->get_param( 'current_css' );

		$manager = new Provider_Manager();
		$text    = $manager->generate_text(
			Css_Prompt::system(),
			Css_Prompt::context( $form, $prompt, $current_css )
		);
		if ( is_wp_error( $text ) ) {
			$text->add_data( array( 'status' => 400 ) );
			return $text;
		}

		$css = Css_Prompt::extract_css( $text );
		if ( is_wp_error( $css ) ) {
			$css->add_data( array( 'status' => 400 ) );
			return $css;
		}

		return rest_ensure_response( array( 'css' => $css ) );
	}

	public function ai_generate( $req ) {
		$prompt         = (string) $req->get_param( 'prompt' );
		$current_schema = $req->get_param( 'current_schema' );
		$manager        = new Provider_Manager();
		$schema         = $manager->generate_form_schema( $prompt, is_array( $current_schema ) ? $current_schema : null );
		if ( is_wp_error( $schema ) ) {
			$schema->add_data( array( 'status' => 400 ) );
			return $schema;
		}
		return rest_ensure_response( $schema );
	}

	public function get_settings() {
		$manager  = new Provider_Manager();
		$settings = $manager->settings();
		// Mask secrets for safety on the wire and expose a generic
		// `configured` flag so the admin UI doesn't have to special-case
		// each provider's storage scheme.
		foreach ( $settings['providers'] as $key => $cfg ) {
			$has_key = ! empty( $cfg['api_key'] );
			if ( $has_key ) {
				$settings['providers'][ $key ]['api_key']     = '';
				$settings['providers'][ $key ]['api_key_set'] = true;
			} else {
				$settings['providers'][ $key ]['api_key_set'] = false;
			}

			// wp_ai_client routes through core connectors; it's "configured"
			// when the host supports it (which means at least one connector
			// is reachable). For everything else, configured ≡ api key saved.
			if ( 'wp_ai_client' === $key ) {
				$settings['providers'][ $key ]['configured'] =
					class_exists( 'Rapid_Ai_Forms\\Ai\\Providers\\Wp_Ai_Client' )
					&& \Rapid_Ai_Forms\Ai\Providers\Wp_Ai_Client::is_available();
			} else {
				$settings['providers'][ $key ]['configured'] = $has_key;
			}
		}
		$providers = array();
		foreach ( $manager->all() as $p ) {
			$providers[] = array(
				'key'   => $p->key(),
				'label' => $p->label(),
			);
		}
		$settings['available_providers'] = $providers;
		return rest_ensure_response( $settings );
	}

	public function update_settings( $req ) {
		$manager  = new Provider_Manager();
		$current  = $manager->settings();
		$incoming = $req->get_json_params() ?: array();

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

	const MAX_SUBMISSION_BYTES = 64 * 1024;

	public function submit( $req ) {
		$uuid = sanitize_text_field( $req['uuid'] );
		$repo = new Form_Repository();
		$form = $repo->get_by_uuid( $uuid );
		if ( ! $form ) {
			return new \WP_Error( 'raif_not_found', __( 'Form not found.', 'rapid-ai-forms' ), array( 'status' => 404 ) );
		}

		// Reject oversized payloads before doing any work. Public endpoint, no auth — guard the DB.
		$raw_body = $req->get_body();
		if ( is_string( $raw_body ) && strlen( $raw_body ) > self::MAX_SUBMISSION_BYTES ) {
			return new \WP_Error(
				'raif_payload_too_large',
				__( 'Submission exceeds the size limit.', 'rapid-ai-forms' ),
				array( 'status' => 413 )
			);
		}

		$payload = $req->get_json_params() ?: $req->get_body_params();
		$data    = $this->sanitize_submission( $form, (array) $payload );

		$validation = $this->validate_required( $form, $data );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		$submissions = new Submission_Repository();
		$id          = $submissions->create( $form['id'], $data );

		/**
		 * Fires after a form submission is stored.
		 *
		 * @param int   $submission_id
		 * @param array $form
		 * @param array $data
		 */
		do_action( 'rapid_ai_forms_submission_created', $id, $form, $data );

		return rest_ensure_response(
			array(
				'ok' => true,
				'id' => $id,
			)
		);
	}

	/**
	 * Enforce `required: true` schema fields on the sanitized submission.
	 *
	 * Runs after sanitize_submission(), so emptiness is judged on what would
	 * actually be stored (e.g. an invalid email already sanitized to '').
	 *
	 * @param array $form Form row including schema.
	 * @param array $data Sanitized submission data.
	 * @return true|\WP_Error True when valid; 422 WP_Error with per-field messages otherwise.
	 */
	private function validate_required( array $form, array $data ) {
		$errors = array();
		$fields = $form['schema']['fields'] ?? array();
		foreach ( $fields as $field ) {
			if ( empty( $field['required'] ) ) {
				continue;
			}
			$type = $field['type'] ?? 'text';
			// Hidden fields are populated from the schema, not the client.
			if ( 'hidden' === $type ) {
				continue;
			}
			$name  = $field['name'];
			$value = $data[ $name ] ?? null;

			$empty = null === $value
				|| ( is_string( $value ) && '' === trim( $value ) )
				|| ( is_array( $value ) && array() === $value );

			if ( $empty ) {
				$label = '' !== (string) ( $field['label'] ?? '' ) ? $field['label'] : $name;
				/* translators: %s: field label. */
				$errors[ $name ] = sprintf( __( '%s is required.', 'rapid-ai-forms' ), $label );
			}
		}

		if ( $errors ) {
			return new \WP_Error(
				'raif_validation',
				__( 'Please fill in the required fields.', 'rapid-ai-forms' ),
				array(
					'status' => 422,
					'fields' => $errors,
				)
			);
		}
		return true;
	}

	private function sanitize_submission( array $form, array $payload ) {
		$out    = array();
		$fields = $form['schema']['fields'] ?? array();
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
					$allowed = array_column( (array) ( $field['options'] ?? array() ), 'value' );
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
