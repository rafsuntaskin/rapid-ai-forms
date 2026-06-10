import { Card, CardBody, CardHeader, TextareaControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Per-form custom CSS editor. Rules are scoped server-side to this form's
 * data-form-uuid wrapper, so un-prefixed selectors are safe.
 */
export default function StylingPanel( { css, onChange } ) {
	return (
		<Card className="raif-mt">
			<CardHeader>
				<strong>{ __( 'Styling', 'rapid-ai-forms' ) }</strong>
			</CardHeader>
			<CardBody>
				<TextareaControl
					className="raif-css-editor"
					label={ __( 'Custom CSS', 'rapid-ai-forms' ) }
					help={ __(
						'Applies to this form only. Selectors are scoped automatically — write rules against .raif-field, label, input, etc.',
						'rapid-ai-forms'
					) }
					rows={ 10 }
					value={ css }
					onChange={ onChange }
				/>
			</CardBody>
		</Card>
	);
}
