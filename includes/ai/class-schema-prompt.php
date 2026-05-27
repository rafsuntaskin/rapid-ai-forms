<?php
/**
 * Shared system prompt and JSON extraction helpers for AI providers.
 *
 * @package Rapid_Ai_Forms
 */

namespace Rapid_Ai_Forms\Ai;

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
  ],
  "notifications": {
    "enabled": true,
    "to": "",
    "subject": "",
    "body": "",
    "reply_to_field": ""
  }
}

Rules:
- Always include a "submit_label".
- "name" must be snake_case and unique within the form.
- Only include "options" when type is select, radio, or checkbox_group. Those fields MUST have at least 2 options.
- "checkbox" is a single yes/no toggle. Use "checkbox_group" when the user can select multiple values from a list.
- Use "hidden" only when you need to carry a server-side value (campaign tag, referrer, etc.); include a "default_value".
- Use "password" for password input fields.
- Always include a "notifications" object. Defaults:
  - "enabled": true
  - "to": "" (empty → the site falls back to the site admin email)
  - "subject": a short line like "New <form-purpose> submission" (e.g. "New contact form submission")
  - "body": a friendly multi-line email body that reads like a real notification message, not a raw dump. Write a short intro line that names the form's purpose, then summarize the submission. Reference fields with mail-tags using their exact "name" — e.g. {full_name}, {email}, {message}. End the body with "{all_fields}" on its own line as a complete fallback. Other tags: {form_title}, {site_name}, {site_url}, {admin_email}. Example body for a contact form with name/email/message:
    "You received a new contact form submission on {site_name}.\n\nFrom: {name} <{email}>\n\nMessage:\n{message}\n\n---\nAll fields:\n{all_fields}"
  - "reply_to_field": the "name" of an email-type field in this form when one exists, otherwise "".
- If the user message includes "Current form schema:", treat the request as an
  EDIT. Return the full updated schema, preserving every existing field, option,
  label, name, type, order, and the existing "notifications" block EXACTLY
  unless the user explicitly asked you to change them. Do not invent extra
  fields, rename existing ones, or reorder unless asked. New fields you add
  should be appended at the end unless the user specifies a position.
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
			return new \WP_Error( 'raif_empty_response', __( 'AI returned an empty response.', 'rapid-ai-forms' ) );
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
			return new \WP_Error( 'raif_invalid_json', __( 'AI response was not valid JSON.', 'rapid-ai-forms' ) );
		}

		return self::sanitize_schema( $decoded );
	}

	public static function sanitize_schema( array $schema ) {
		$out = array(
			'title'         => isset( $schema['title'] ) ? sanitize_text_field( $schema['title'] ) : '',
			'submit_label'  => isset( $schema['submit_label'] ) ? sanitize_text_field( $schema['submit_label'] ) : __( 'Submit', 'rapid-ai-forms' ),
			'show_title'    => ! empty( $schema['show_title'] ),
			'fields'        => array(),
			'notifications' => self::sanitize_notifications( $schema['notifications'] ?? array() ),
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

	private static function sanitize_notifications( $value ) {
		if ( ! is_array( $value ) ) {
			$value = array();
		}
		return array(
			'enabled'        => array_key_exists( 'enabled', $value ) ? (bool) $value['enabled'] : true,
			'to'             => isset( $value['to'] ) ? sanitize_text_field( (string) $value['to'] ) : '',
			'subject'        => isset( $value['subject'] ) ? sanitize_text_field( (string) $value['subject'] ) : '',
			'body'           => isset( $value['body'] ) ? sanitize_textarea_field( (string) $value['body'] ) : '',
			'reply_to_field' => isset( $value['reply_to_field'] ) ? sanitize_key( (string) $value['reply_to_field'] ) : '',
		);
	}
}
