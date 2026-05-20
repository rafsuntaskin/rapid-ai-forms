<?php
/**
 * Sends an admin email when a submission is received.
 *
 * Behavior mirrors Contact Form 7's "Mail" tab at a basic level: per-form
 * To / Subject / Body templates with mail-tag substitution. Falls back to
 * sensible defaults when fields are left blank.
 *
 * @package WP_AI_Forms
 */

namespace WP_AI_Forms\Notifications;

defined( 'ABSPATH' ) || exit;

class Email_Notifier {

	public function register() {
		add_action( 'wp_ai_forms_submission_created', array( $this, 'maybe_send' ), 10, 3 );
	}

	public function maybe_send( $submission_id, array $form, array $data ) {
		$notifications = isset( $form['schema']['notifications'] ) && is_array( $form['schema']['notifications'] )
			? $form['schema']['notifications']
			: array();

		if ( ! array_key_exists( 'enabled', $notifications ) || empty( $notifications['enabled'] ) ) {
			return;
		}

		/**
		 * Short-circuit notification sending.
		 *
		 * @param bool  $send
		 * @param int   $submission_id
		 * @param array $form
		 * @param array $data
		 */
		if ( ! apply_filters( 'wp_ai_forms_send_notification_email', true, $submission_id, $form, $data ) ) {
			return;
		}

		$to_raw     = isset( $notifications['to'] ) ? trim( (string) $notifications['to'] ) : '';
		$to_raw     = '' !== $to_raw ? $to_raw : (string) get_option( 'admin_email' );
		$recipients = array_values(
			array_filter(
				array_map( 'sanitize_email', array_map( 'trim', explode( ',', $to_raw ) ) )
			)
		);
		if ( empty( $recipients ) ) {
			return;
		}

		$tags = $this->build_tags( $form, $data );

		$subject = trim( (string) ( $notifications['subject'] ?? '' ) );
		if ( '' === $subject ) {
			/* translators: %s: form title */
			$subject = sprintf( __( 'New submission: %s', 'wp-ai-forms' ), $form['title'] ?: __( 'Untitled form', 'wp-ai-forms' ) );
		}
		$subject = $this->render_template( $subject, $tags );

		$body = (string) ( $notifications['body'] ?? '' );
		if ( '' === trim( $body ) ) {
			$body = '{all_fields}';
		}
		$body = $this->render_template( $body, $tags );

		$headers     = array();
		$reply_field = isset( $notifications['reply_to_field'] ) ? sanitize_key( $notifications['reply_to_field'] ) : '';
		if ( '' !== $reply_field && ! empty( $data[ $reply_field ] ) ) {
			$candidate = is_array( $data[ $reply_field ] ) ? '' : sanitize_email( $data[ $reply_field ] );
			if ( $candidate && is_email( $candidate ) ) {
				$headers[] = 'Reply-To: ' . $candidate;
			}
		}

		/** Filters mirror CF7's hooks so site owners can adjust without forking. */
		$recipients = (array) apply_filters( 'wp_ai_forms_notification_recipients', $recipients, $form, $data );
		$subject    = (string) apply_filters( 'wp_ai_forms_notification_subject', $subject, $form, $data );
		$body       = (string) apply_filters( 'wp_ai_forms_notification_body', $body, $form, $data );
		$headers    = (array) apply_filters( 'wp_ai_forms_notification_headers', $headers, $form, $data );

		wp_mail( $recipients, wp_strip_all_tags( $subject ), $body, $headers );
	}

	private function build_tags( array $form, array $data ) {
		$tags = array(
			'{form_title}'  => isset( $form['title'] ) ? (string) $form['title'] : '',
			'{site_name}'   => (string) get_bloginfo( 'name' ),
			'{site_url}'    => (string) site_url(),
			'{admin_email}' => (string) get_option( 'admin_email' ),
		);

		$lines  = array();
		$fields = $form['schema']['fields'] ?? array();
		foreach ( $fields as $field ) {
			$name = $field['name'] ?? '';
			if ( '' === $name ) {
				continue;
			}
			$label = ! empty( $field['label'] ) ? $field['label'] : $name;
			$value = $data[ $name ] ?? '';
			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			}
			$value = (string) $value;

			$tags[ '{' . $name . '}' ] = $value;
			$lines[]                   = $label . ': ' . $value;
		}
		$tags['{all_fields}'] = implode( "\n", $lines );

		return $tags;
	}

	private function render_template( $template, array $tags ) {
		return strtr( (string) $template, $tags );
	}
}
