<?php
/**
 * Shared system prompt and JSON extraction helpers for AI providers.
 *
 * @package WP_AI_Forms
 */

namespace WP_AI_Forms\Ai;

defined( 'ABSPATH' ) || exit;

class Schema_Prompt {

	public static function system() {
		return <<<EOT
You are a form schema generator for a WordPress plugin. Given a natural-language
description, output a JSON object describing the form. Only output JSON — no
prose, no markdown fences.

Schema:
{
  "title": "string",
  "submit_label": "string",
  "show_title": true,
  "fields": [
    {
      "name": "snake_case_id",
      "label": "Human label",
      "type": "text|email|tel|url|number|date|textarea|select|radio|checkbox",
      "required": true,
      "placeholder": "optional",
      "options": [ { "label": "Yes", "value": "yes" } ]
    }
  ]
}

Always include a "submit_label". Use snake_case for "name". Only include "options"
when type is select or radio.
EOT;
	}

	/**
	 * Extract a schema object from an LLM text response.
	 *
	 * @param string $text Raw model output.
	 * @return array|\WP_Error
	 */
	public static function extract_schema( $text ) {
		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return new \WP_Error( 'wpaif_empty_response', __( 'AI returned an empty response.', 'wp-ai-forms' ) );
		}

		// Strip markdown fences if present.
		$text = trim( $text );
		$text = preg_replace( '/^```(?:json)?\s*/i', '', $text );
		$text = preg_replace( '/\s*```$/', '', $text );

		// If model wrapped in prose, find the first JSON object.
		if ( $text[0] !== '{' ) {
			$start = strpos( $text, '{' );
			$end   = strrpos( $text, '}' );
			if ( false !== $start && false !== $end && $end > $start ) {
				$text = substr( $text, $start, $end - $start + 1 );
			}
		}

		$decoded = json_decode( $text, true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'wpaif_invalid_json', __( 'AI response was not valid JSON.', 'wp-ai-forms' ) );
		}

		return self::sanitize_schema( $decoded );
	}

	public static function sanitize_schema( array $schema ) {
		$out = [
			'title'        => isset( $schema['title'] ) ? sanitize_text_field( $schema['title'] ) : '',
			'submit_label' => isset( $schema['submit_label'] ) ? sanitize_text_field( $schema['submit_label'] ) : __( 'Submit', 'wp-ai-forms' ),
			'show_title'   => ! empty( $schema['show_title'] ),
			'fields'       => [],
		];

		$allowed_types = [ 'text', 'email', 'tel', 'url', 'number', 'date', 'textarea', 'select', 'radio', 'checkbox' ];

		foreach ( (array) ( $schema['fields'] ?? [] ) as $field ) {
			if ( empty( $field['name'] ) ) {
				continue;
			}
			$type = sanitize_key( $field['type'] ?? 'text' );
			if ( ! in_array( $type, $allowed_types, true ) ) {
				$type = 'text';
			}
			$entry = [
				'name'        => sanitize_key( $field['name'] ),
				'label'       => sanitize_text_field( $field['label'] ?? '' ),
				'type'        => $type,
				'required'    => ! empty( $field['required'] ),
				'placeholder' => sanitize_text_field( $field['placeholder'] ?? '' ),
			];
			if ( in_array( $type, [ 'select', 'radio' ], true ) && ! empty( $field['options'] ) ) {
				$entry['options'] = array_values(
					array_filter(
						array_map(
							function ( $opt ) {
								if ( ! is_array( $opt ) ) {
									return null;
								}
								return [
									'label' => sanitize_text_field( $opt['label'] ?? '' ),
									'value' => sanitize_text_field( $opt['value'] ?? $opt['label'] ?? '' ),
								];
							},
							(array) $field['options']
						)
					)
				);
			}
			$out['fields'][] = $entry;
		}

		return $out;
	}
}
