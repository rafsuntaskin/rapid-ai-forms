<?php
/**
 * Registers plugin capabilities with the WordPress Abilities API (WP 6.9+).
 *
 * Abilities make this plugin's verbs discoverable to AI agents and other tools
 * via a standardized registry. Guarded with function_exists so the plugin still
 * works on WP < 6.9.
 *
 * @package WP_AI_Forms
 */

namespace WP_AI_Forms\Abilities;

use WP_AI_Forms\Ai\Provider_Manager;
use WP_AI_Forms\Forms\Form_Repository;
use WP_AI_Forms\Ai\Schema_Prompt;

defined( 'ABSPATH' ) || exit;

class Abilities {
	const CATEGORY = 'wp-ai-forms';

	public function register() {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
	}

	public function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'AI Forms', 'wp-ai-forms' ),
				'description' => __( 'Build, fetch, and submit AI-generated forms.', 'wp-ai-forms' ),
			)
		);
	}

	public function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$form_schema_shape = array(
			'type'       => 'object',
			'properties' => array(
				'title'        => array( 'type' => 'string' ),
				'submit_label' => array( 'type' => 'string' ),
				'show_title'   => array( 'type' => 'boolean' ),
				'fields'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'name'        => array( 'type' => 'string' ),
							'label'       => array( 'type' => 'string' ),
							'type'        => array(
								'type' => 'string',
								'enum' => array( 'text', 'email', 'tel', 'url', 'number', 'date', 'textarea', 'select', 'radio', 'checkbox' ),
							),
							'required'    => array( 'type' => 'boolean' ),
							'placeholder' => array( 'type' => 'string' ),
							'options'     => array(
								'type'  => 'array',
								'items' => array(
									'type'       => 'object',
									'properties' => array(
										'label' => array( 'type' => 'string' ),
										'value' => array( 'type' => 'string' ),
									),
								),
							),
						),
					),
				),
			),
		);

		$can_manage = static fn() => current_user_can( 'manage_options' );

		wp_register_ability(
			'wp-ai-forms/generate-form-schema',
			array(
				'label'               => __( 'Generate or edit a form schema from a prompt', 'wp-ai-forms' ),
				'description'         => __( 'Use the configured AI provider to produce a form schema. If a current_schema is supplied, the model edits it in place, preserving fields the user did not ask to change.', 'wp-ai-forms' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'required'   => array( 'prompt' ),
					'properties' => array(
						'prompt'         => array(
							'type'        => 'string',
							'description' => 'Natural-language description or edit instruction.',
						),
						'current_schema' => array_merge( $form_schema_shape, array( 'description' => 'Optional existing schema to edit. Omit for a from-scratch generation.' ) ),
					),
				),
				'output_schema'       => $form_schema_shape,
				'permission_callback' => $can_manage,
				'execute_callback'    => function ( $input ) {
					$manager = new Provider_Manager();
					$current = isset( $input['current_schema'] ) && is_array( $input['current_schema'] ) ? $input['current_schema'] : null;
					return $manager->generate_form_schema( (string) ( $input['prompt'] ?? '' ), $current );
				},
				'meta'                => array( 'plugin' => 'wp-ai-forms' ),
			)
		);

		wp_register_ability(
			'wp-ai-forms/create-form',
			array(
				'label'               => __( 'Create a form', 'wp-ai-forms' ),
				'description'         => __( 'Persist a form (with an optional pre-generated schema).', 'wp-ai-forms' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'title'     => array( 'type' => 'string' ),
						'status'    => array(
							'type' => 'string',
							'enum' => array( 'draft', 'published' ),
						),
						'schema'    => $form_schema_shape,
						'ai_prompt' => array( 'type' => 'string' ),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'   => array( 'type' => 'integer' ),
						'uuid' => array( 'type' => 'string' ),
					),
				),
				'permission_callback' => $can_manage,
				'execute_callback'    => function ( $input ) {
					$repo = new Form_Repository();
					$form = $repo->create( (array) $input );
					return $form ? array(
						'id'   => $form['id'],
						'uuid' => $form['uuid'],
					) : new \WP_Error( 'wpaif_create_failed', __( 'Could not create form.', 'wp-ai-forms' ) );
				},
				'meta'                => array( 'plugin' => 'wp-ai-forms' ),
			)
		);

		wp_register_ability(
			'wp-ai-forms/list-forms',
			array(
				'label'               => __( 'List forms', 'wp-ai-forms' ),
				'description'         => __( 'Return a paginated list of forms with their shortcodes.', 'wp-ai-forms' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'page'     => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'per_page' => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 100,
						),
					),
				),
				'output_schema'       => array(
					'type'  => 'array',
					'items' => array(
						'type'       => 'object',
						'properties' => array(
							'id'        => array( 'type' => 'integer' ),
							'uuid'      => array( 'type' => 'string' ),
							'title'     => array( 'type' => 'string' ),
							'status'    => array( 'type' => 'string' ),
							'shortcode' => array( 'type' => 'string' ),
						),
					),
				),
				'permission_callback' => $can_manage,
				'execute_callback'    => function ( $input ) {
					$repo  = new Form_Repository();
					$forms = $repo->list(
						array(
							'page'     => (int) ( $input['page'] ?? 1 ),
							'per_page' => (int) ( $input['per_page'] ?? 20 ),
						)
					);
					return array_map(
						static fn( $f ) => array(
							'id'        => (int) $f['id'],
							'uuid'      => $f['uuid'],
							'title'     => $f['title'],
							'status'    => $f['status'],
							'shortcode' => sprintf( '[wp_ai_form id="%d"]', (int) $f['id'] ),
						),
						$forms
					);
				},
				'meta'                => array( 'plugin' => 'wp-ai-forms' ),
			)
		);

		wp_register_ability(
			'wp-ai-forms/get-form',
			array(
				'label'               => __( 'Get a form', 'wp-ai-forms' ),
				'description'         => __( 'Fetch a single form (and its schema) by id or uuid.', 'wp-ai-forms' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id'   => array( 'type' => 'integer' ),
						'uuid' => array( 'type' => 'string' ),
					),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'id'     => array( 'type' => 'integer' ),
						'uuid'   => array( 'type' => 'string' ),
						'title'  => array( 'type' => 'string' ),
						'status' => array( 'type' => 'string' ),
						'schema' => $form_schema_shape,
					),
				),
				'permission_callback' => $can_manage,
				'execute_callback'    => function ( $input ) {
					$repo = new Form_Repository();
					$form = ! empty( $input['uuid'] )
						? $repo->get_by_uuid( (string) $input['uuid'] )
						: $repo->get( (int) ( $input['id'] ?? 0 ) );
					return $form ?: new \WP_Error( 'wpaif_not_found', __( 'Form not found.', 'wp-ai-forms' ) );
				},
				'meta'                => array( 'plugin' => 'wp-ai-forms' ),
			)
		);

		/**
		 * Fires after WP AI Forms registers its abilities. Other plugins can
		 * register related abilities or extend the category here.
		 */
		do_action( 'wp_ai_forms_abilities_registered' );
	}
}
