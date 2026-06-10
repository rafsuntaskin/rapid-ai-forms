<?php
/**
 * Renders a form schema to HTML for frontend output.
 *
 * @package Rapid_Ai_Forms
 */

namespace Rapid_Ai_Forms\Forms;

defined( 'ABSPATH' ) || exit;

class Form_Renderer {

	public function render( array $form ) {
		$schema = isset( $form['schema'] ) && is_array( $form['schema'] ) ? $form['schema'] : array();
		$fields = isset( $schema['fields'] ) && is_array( $schema['fields'] ) ? $schema['fields'] : array();

		$nonce = wp_create_nonce( 'wp_rest' );

		// Re-sanitized at render time as defense in depth (it is also
		// sanitized on every write in Form_Repository).
		$custom_css = Css_Sanitizer::sanitize( $form['settings']['custom_css'] ?? '' );

		ob_start();
		if ( '' !== $custom_css ) :
			// The CSS-nesting wrapper scopes even un-prefixed rules to this
			// one form instance, so one form's CSS can't leak into another.
			?>
		<style id="raif-css-<?php echo esc_attr( $form['uuid'] ); ?>">
			.raif-form[data-form-uuid="<?php echo esc_attr( $form['uuid'] ); ?>"] {
				<?php echo $custom_css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Css_Sanitizer strips style/script breakout tokens; HTML-escaping would corrupt valid CSS selectors. ?>
			}
		</style>
			<?php
		endif;
		?>
		<form class="raif-form" data-form-uuid="<?php echo esc_attr( $form['uuid'] ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">
			<?php if ( ! empty( $schema['show_title'] ) ) : ?>
				<h3 class="raif-form__title"><?php echo esc_html( $form['title'] ); ?></h3>
			<?php endif; ?>

			<?php foreach ( $fields as $field ) : ?>
				<?php $this->render_field( $field ); ?>
			<?php endforeach; ?>

			<div class="raif-form__actions wp-block-button">
				<button type="submit" class="raif-form__submit wp-block-button__link wp-element-button">
					<?php echo esc_html( $schema['submit_label'] ?? __( 'Submit', 'rapid-ai-forms' ) ); ?>
				</button>
			</div>
			<div class="raif-form__message" aria-live="polite"></div>
		</form>
		<?php
		return ob_get_clean();
	}

	private function render_field( array $field ) {
		$type     = isset( $field['type'] ) ? sanitize_key( $field['type'] ) : 'text';
		$name     = isset( $field['name'] ) ? sanitize_key( $field['name'] ) : '';
		$label    = isset( $field['label'] ) ? $field['label'] : '';
		$required = ! empty( $field['required'] );
		$id       = 'raif-' . $name . '-' . wp_rand( 1000, 9999 );

		if ( ! $name ) {
			return;
		}

		// Hidden fields render bare — no wrapper, no label.
		if ( 'hidden' === $type ) {
			printf(
				'<input type="hidden" name="%s" value="%s" />',
				esc_attr( $name ),
				esc_attr( $field['default_value'] ?? '' )
			);
			return;
		}

		echo '<div class="raif-field raif-field--' . esc_attr( $type ) . '">';
		if ( $label ) {
			printf( '<label for="%s">%s', esc_attr( $id ), esc_html( $label ) );
			if ( $required ) {
				echo ' <span class="raif-required">*</span>';
			}
			echo '</label>';
		}

		$placeholder      = isset( $field['placeholder'] ) ? (string) $field['placeholder'] : '';
		$placeholder_attr = '';
		/**
		 * Field types that render the HTML `placeholder` attribute.
		 *
		 * @param string[] $types Default list of placeholder-capable field types.
		 */
		$placeholder_types = (array) apply_filters(
			'rapid_ai_forms_placeholder_field_types',
			array( 'text', 'textarea', 'email', 'number', 'tel', 'url', 'password' )
		);
		if ( '' !== $placeholder && in_array( $type, $placeholder_types, true ) ) {
			$placeholder_attr = ' placeholder="' . esc_attr( $placeholder ) . '"';
		}

		// Attribute string built from pre-escaped values; safe to echo as-is.
		$attrs = sprintf(
			'id="%s" name="%s"%s%s',
			esc_attr( $id ),
			esc_attr( $name ),
			$required ? ' required aria-required="true"' : '',
			$placeholder_attr
		);

		switch ( $type ) {
			case 'textarea':
				echo '<textarea ' . $attrs . '></textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $attrs is a sprintf of esc_attr()ed values.
				break;
			case 'select':
				echo '<select ' . $attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $attrs is a sprintf of esc_attr()ed values.
				foreach ( (array) ( $field['options'] ?? array() ) as $opt ) {
					printf(
						'<option value="%s">%s</option>',
						esc_attr( $opt['value'] ?? '' ),
						esc_html( $opt['label'] ?? '' )
					);
				}
				echo '</select>';
				break;
			case 'checkbox':
				echo '<input type="checkbox" ' . $attrs . ' value="1" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $attrs is a sprintf of esc_attr()ed values.
				break;
			case 'checkbox_group':
				echo '<div class="raif-checkbox-group">';
				foreach ( (array) ( $field['options'] ?? array() ) as $opt ) {
					printf(
						'<label><input type="checkbox" name="%s[]" value="%s" /> %s</label>',
						esc_attr( $name ),
						esc_attr( $opt['value'] ?? '' ),
						esc_html( $opt['label'] ?? '' )
					);
				}
				echo '</div>';
				break;
			case 'radio':
				foreach ( (array) ( $field['options'] ?? array() ) as $opt ) {
					printf(
						'<label><input type="radio" name="%s" value="%s"%s /> %s</label>',
						esc_attr( $name ),
						esc_attr( $opt['value'] ?? '' ),
						$required ? ' required aria-required="true"' : '',
						esc_html( $opt['label'] ?? '' )
					);
				}
				break;
			case 'password':
			case 'email':
			case 'number':
			case 'tel':
			case 'url':
			case 'date':
			case 'text':
			default:
				echo '<input type="' . esc_attr( $type ) . '" ' . $attrs . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $attrs is a sprintf of esc_attr()ed values.
		}

		echo '</div>';
	}
}
