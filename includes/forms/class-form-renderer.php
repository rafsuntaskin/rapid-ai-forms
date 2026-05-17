<?php
/**
 * Renders a form schema to HTML for frontend output.
 *
 * @package WP_AI_Forms
 */

namespace WP_AI_Forms\Forms;

defined( 'ABSPATH' ) || exit;

class Form_Renderer {

	public function render( array $form ) {
		$schema = isset( $form['schema'] ) && is_array( $form['schema'] ) ? $form['schema'] : [];
		$fields = isset( $schema['fields'] ) && is_array( $schema['fields'] ) ? $schema['fields'] : [];

		$nonce      = wp_create_nonce( 'wp_rest' );
		$form_uuid  = esc_attr( $form['uuid'] );
		$form_title = esc_html( $form['title'] );

		ob_start();
		?>
		<form class="wpaif-form" data-form-uuid="<?php echo $form_uuid; ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<?php if ( ! empty( $schema['show_title'] ) ) : ?>
				<h3 class="wpaif-form__title"><?php echo $form_title; ?></h3>
			<?php endif; ?>

			<?php foreach ( $fields as $field ) : ?>
				<?php $this->render_field( $field ); ?>
			<?php endforeach; ?>

			<div class="wpaif-form__actions">
				<button type="submit" class="wpaif-form__submit">
					<?php echo esc_html( $schema['submit_label'] ?? __( 'Submit', 'wp-ai-forms' ) ); ?>
				</button>
			</div>
			<div class="wpaif-form__message" aria-live="polite"></div>
		</form>
		<?php
		return ob_get_clean();
	}

	private function render_field( array $field ) {
		$type     = isset( $field['type'] ) ? sanitize_key( $field['type'] ) : 'text';
		$name     = isset( $field['name'] ) ? sanitize_key( $field['name'] ) : '';
		$label    = isset( $field['label'] ) ? esc_html( $field['label'] ) : '';
		$required = ! empty( $field['required'] );
		$id       = 'wpaif-' . $name . '-' . wp_rand( 1000, 9999 );

		if ( ! $name ) {
			return;
		}

		echo '<div class="wpaif-field wpaif-field--' . esc_attr( $type ) . '">';
		if ( $label ) {
			echo '<label for="' . esc_attr( $id ) . '">' . $label;
			if ( $required ) {
				echo ' <span class="wpaif-required">*</span>';
			}
			echo '</label>';
		}

		$attrs = sprintf(
			'id="%s" name="%s"%s',
			esc_attr( $id ),
			esc_attr( $name ),
			$required ? ' required' : ''
		);

		switch ( $type ) {
			case 'textarea':
				echo '<textarea ' . $attrs . '></textarea>';
				break;
			case 'select':
				echo '<select ' . $attrs . '>';
				foreach ( (array) ( $field['options'] ?? [] ) as $opt ) {
					echo '<option value="' . esc_attr( $opt['value'] ?? '' ) . '">' . esc_html( $opt['label'] ?? '' ) . '</option>';
				}
				echo '</select>';
				break;
			case 'checkbox':
				echo '<input type="checkbox" ' . $attrs . ' value="1" />';
				break;
			case 'radio':
				foreach ( (array) ( $field['options'] ?? [] ) as $i => $opt ) {
					echo '<label><input type="radio" name="' . esc_attr( $name ) . '" value="' . esc_attr( $opt['value'] ?? '' ) . '"' . ( $required ? ' required' : '' ) . ' /> ' . esc_html( $opt['label'] ?? '' ) . '</label>';
				}
				break;
			case 'email':
			case 'number':
			case 'tel':
			case 'url':
			case 'date':
			case 'text':
			default:
				echo '<input type="' . esc_attr( $type ) . '" ' . $attrs . ' />';
		}

		echo '</div>';
	}
}
