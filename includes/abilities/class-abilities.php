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
		add_action( 'wp_abilities_api_categories_init', [ $this, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ $this, 'register_abilities' ] );
	}

	public function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			self::CATEGORY,
			[
				'label'       => __( 'AI Forms', 'wp-ai-forms' ),
				'description' => __( 'Build, fetch, and submit AI-generated forms.', 'wp-ai-forms' ),
			]
		);
	}

	public function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$form_schema_shape = [
			'type'       => 'object',
			'properties' => [
				'title'        => [ 'type' => 'string' ],
				'submit_label' => [ 'type' => 'string' ],
				'show_title'   => [ 'type' => 'boolean' ],
				'fields'       => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'name'        => [ 'type' => 'string' ],
							'label'       => [ 'type' => 'string' ],
							'type'        => [ 'type' => 'string', 'enum' => [ 'text', 'email', 'tel', 'url', 'number', 'date', 'textarea', 'select', 'radio', 'checkbox' ] ],
							'required'    => [ 'type' => 'boolean' ],
							'placeholder' => [ 'type' => 'string' ],
							'options'     => [
								'type'  => 'array',
								'items' => [
									'type'       => 'object',
									'properties' => [
										'label' => [ 'type' => 'string' ],
										'value' => [ 'type' => 'string' ],
									],
								],
							],
						],
					],
				],
			],
		];

		$can_manage = static fn() => current_user_can( 'manage_options' );

		wp_register_ability(
			'wp-ai-forms/generate-form-schema',
			[
				'label'               => __( 'Generate form schema from a prompt', 'wp-ai-forms' ),
				'description'         => __( 'Use the configured AI provider to turn a natural-language description into a form schema.', 'wp-ai-forms' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'required'   => [ 'prompt' ],
					'properties' => [
						'prompt' => [ 'type' => 'string', 'description' => 'Natural-language description of the form.' ],
					],
				],
				'output_schema'       => $form_schema_shape,
				'permission_callback' => $can_manage,
				'execute_callback'    => function ( $input ) {
					$manager = new Provider_Manager();
					$schema  = $manager->generate_form_schema( (string) ( $input['prompt'] ?? '' ) );
					return is_wp_error( $schema ) ? $schema : $schema;
				},
				'meta'                => [ 'plugin' => 'wp-ai-forms' ],
			]
		);

		wp_register_ability(
			'wp-ai-forms/create-form',
			[
				'label'               => __( 'Create a form', 'wp-ai-forms' ),
				'description'         => __( 'Persist a form (with an optional pre-generated schema).', 'wp-ai-forms' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'title'     => [ 'type' => 'string' ],
						'status'    => [ 'type' => 'string', 'enum' => [ 'draft', 'published' ] ],
						'schema'    => $form_schema_shape,
						'ai_prompt' => [ 'type' => 'string' ],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'id'   => [ 'type' => 'integer' ],
						'uuid' => [ 'type' => 'string' ],
					],
				],
				'permission_callback' => $can_manage,
				'execute_callback'    => function ( $input ) {
					$repo = new Form_Repository();
					$form = $repo->create( (array) $input );
					return $form ? [ 'id' => $form['id'], 'uuid' => $form['uuid'] ] : new \WP_Error( 'wpaif_create_failed', __( 'Could not create form.', 'wp-ai-forms' ) );
				},
				'meta'                => [ 'plugin' => 'wp-ai-forms' ],
			]
		);

		wp_register_ability(
			'wp-ai-forms/list-forms',
			[
				'label'               => __( 'List forms', 'wp-ai-forms' ),
				'description'         => __( 'Return a paginated list of forms with their shortcodes.', 'wp-ai-forms' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'page'     => [ 'type' => 'integer', 'minimum' => 1 ],
						'per_page' => [ 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ],
					],
				],
				'output_schema'       => [
					'type'  => 'array',
					'items' => [
						'type'       => 'object',
						'properties' => [
							'id'        => [ 'type' => 'integer' ],
							'uuid'      => [ 'type' => 'string' ],
							'title'     => [ 'type' => 'string' ],
							'status'    => [ 'type' => 'string' ],
							'shortcode' => [ 'type' => 'string' ],
						],
					],
				],
				'permission_callback' => $can_manage,
				'execute_callback'    => function ( $input ) {
					$repo  = new Form_Repository();
					$forms = $repo->list(
						[
							'page'     => (int) ( $input['page'] ?? 1 ),
							'per_page' => (int) ( $input['per_page'] ?? 20 ),
						]
					);
					return array_map(
						static fn( $f ) => [
							'id'        => (int) $f['id'],
							'uuid'      => $f['uuid'],
							'title'     => $f['title'],
							'status'    => $f['status'],
							'shortcode' => sprintf( '[wp_ai_form id="%d"]', (int) $f['id'] ),
						],
						$forms
					);
				},
				'meta'                => [ 'plugin' => 'wp-ai-forms' ],
			]
		);

		wp_register_ability(
			'wp-ai-forms/get-form',
			[
				'label'               => __( 'Get a form', 'wp-ai-forms' ),
				'description'         => __( 'Fetch a single form (and its schema) by id or uuid.', 'wp-ai-forms' ),
				'category'            => self::CATEGORY,
				'input_schema'        => [
					'type'       => 'object',
					'properties' => [
						'id'   => [ 'type' => 'integer' ],
						'uuid' => [ 'type' => 'string' ],
					],
				],
				'output_schema'       => [
					'type'       => 'object',
					'properties' => [
						'id'     => [ 'type' => 'integer' ],
						'uuid'   => [ 'type' => 'string' ],
						'title'  => [ 'type' => 'string' ],
						'status' => [ 'type' => 'string' ],
						'schema' => $form_schema_shape,
					],
				],
				'permission_callback' => $can_manage,
				'execute_callback'    => function ( $input ) {
					$repo = new Form_Repository();
					$form = ! empty( $input['uuid'] )
						? $repo->get_by_uuid( (string) $input['uuid'] )
						: $repo->get( (int) ( $input['id'] ?? 0 ) );
					return $form ?: new \WP_Error( 'wpaif_not_found', __( 'Form not found.', 'wp-ai-forms' ) );
				},
				'meta'                => [ 'plugin' => 'wp-ai-forms' ],
			]
		);

		/**
		 * Fires after WP AI Forms registers its abilities. Other plugins can
		 * register related abilities or extend the category here.
		 */
		do_action( 'wp_ai_forms_abilities_registered' );
	}
}
