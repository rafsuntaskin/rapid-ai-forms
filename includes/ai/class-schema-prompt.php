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
		return <<<'EOT'
You are a form schema editor for a WordPress plugin. You output JSON only — no
prose, no markdown fences.

Schema shape:
{
  "title": "string",
  "submit_label": "string",
  "show_title": true,
  "fields": [
    {
      "name": "snake_case_id",
      "label": "Human label",
      "type": "text|email|tel|url|number|date|password|hidden|textarea|select|radio|checkbox|checkbox_group",
      "required": true,
      "placeholder": "optional",
      "default_value": "optional, used by hidden fields",
      "options": [ { "label": "Yes", "value": "yes" } ]
    }
  ]
}

Rules:
- Always include a "submit_label".
- "name" must be snake_case and unique within the form.
- Only include "options" when type is select, radio, or checkbox_group. Those fields MUST have at least 2 options.
- "checkbox" is a single yes/no toggle. Use "checkbox_group" when the user can select multiple values from a list.
- Use "hidden" only when you need to carry a server-side value (campaign tag, referrer, etc.); include a "default_value".
- Use "password" for password input fields.
- If the user message includes "Current form schema:", treat the request as an
  EDIT. Return the full updated schema, preserving every existing field, option,
  label, name, type, and order EXACTLY unless the user explicitly asked you to
  change them. Do not invent extra fields, rename existing ones, or reorder
  unless asked. New fields you add should be appended at the end unless the user
  specifies a position.
- If no "Current form schema:" block is present, generate a new schema from
  scratch matching the user's description.
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
		$out = array(
			'title'        => isset( $schema['title'] ) ? sanitize_text_field( $schema['title'] ) : '',
			'submit_label' => isset( $schema['submit_label'] ) ? sanitize_text_field( $schema['submit_label'] ) : __( 'Submit', 'wp-ai-forms' ),
			'show_title'   => ! empty( $schema['show_title'] ),
			'fields'       => array(),
		);

		$allowed_types = array( 'text', 'email', 'tel', 'url', 'number', 'date', 'password', 'hidden', 'textarea', 'select', 'radio', 'checkbox', 'checkbox_group' );

		foreach ( (array) ( $schema['fields'] ?? array() ) as $field ) {
			if ( empty( $field['name'] ) ) {
				continue;
			}
			$type = sanitize_key( $field['type'] ?? 'text' );
			if ( ! in_array( $type, $allowed_types, true ) ) {
				$type = 'text';
			}
			$entry = array(
				'name'        => sanitize_key( $field['name'] ),
				'label'       => sanitize_text_field( $field['label'] ?? '' ),
				'type'        => $type,
				'required'    => ! empty( $field['required'] ),
				'placeholder' => sanitize_text_field( $field['placeholder'] ?? '' ),
			);
			if ( 'hidden' === $type && isset( $field['default_value'] ) ) {
				$entry['default_value'] = sanitize_text_field( $field['default_value'] );
			}
			if ( in_array( $type, array( 'select', 'radio', 'checkbox_group' ), true ) && ! empty( $field['options'] ) ) {
				$entry['options'] = array_values(
					array_filter(
						array_map(
							function ( $opt ) {
								if ( ! is_array( $opt ) ) {
									return null;
								}
								return array(
									'label' => sanitize_text_field( $opt['label'] ?? '' ),
									'value' => sanitize_text_field( $opt['value'] ?? $opt['label'] ?? '' ),
								);
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
