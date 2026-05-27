/**
 * Client-side preview that mirrors how Form_Renderer outputs the form
 * on the frontend. Read-only — submission is intercepted with preventDefault.
 */
import { __ } from '@wordpress/i18n';

const PLAIN_INPUT_TYPES = [ 'text', 'email', 'tel', 'url', 'number', 'date', 'password' ];

function PreviewField( { field } ) {
	if ( ! field.name ) {
		return null;
	}
	const id = `raif-preview-${ field.name }`;
	const required = !! field.required;
	const placeholder = field.placeholder || '';
	const type = field.type || 'text';
	const options = Array.isArray( field.options ) ? field.options : [];

	// Hidden fields are not visible — show a compact dev-only indicator instead.
	if ( type === 'hidden' ) {
		return (
			<div className="raif-field raif-field--hidden raif-preview-hidden">
				<small>
					{ __( 'Hidden: ', 'rapid-ai-forms' ) }
					<code>{ field.name }</code>
					{ field.default_value ? ` = ${ field.default_value }` : '' }
				</small>
			</div>
		);
	}

	const label = field.label ? (
		<label htmlFor={ id }>
			{ field.label }
			{ required && <span className="raif-required"> *</span> }
		</label>
	) : null;

	let control = null;
	if ( PLAIN_INPUT_TYPES.includes( type ) ) {
		control = (
			<input
				type={ type }
				id={ id }
				name={ field.name }
				placeholder={ placeholder }
				required={ required }
				disabled
			/>
		);
	} else if ( type === 'textarea' ) {
		control = (
			<textarea
				id={ id }
				name={ field.name }
				placeholder={ placeholder }
				required={ required }
				disabled
			/>
		);
	} else if ( type === 'select' ) {
		control = (
			<select id={ id } name={ field.name } required={ required } disabled>
				<option value="">{ __( '— Select —', 'rapid-ai-forms' ) }</option>
				{ options.map( ( opt, i ) => (
					<option key={ i } value={ opt.value || opt.label || '' }>
						{ opt.label || opt.value || '' }
					</option>
				) ) }
			</select>
		);
	} else if ( type === 'radio' ) {
		control = (
			<div className="raif-radio-group">
				{ options.length === 0 && (
					<em className="raif-preview-empty">
						{ __( 'No options yet — add some to the field.', 'rapid-ai-forms' ) }
					</em>
				) }
				{ options.map( ( opt, i ) => (
					<label key={ i }>
						<input type="radio" name={ field.name } value={ opt.value || '' } disabled />{ ' ' }
						{ opt.label || opt.value || '' }
					</label>
				) ) }
			</div>
		);
	} else if ( type === 'checkbox' ) {
		control = (
			<input type="checkbox" id={ id } name={ field.name } value="1" disabled />
		);
	} else if ( type === 'checkbox_group' ) {
		control = (
			<div className="raif-checkbox-group">
				{ options.length === 0 && (
					<em className="raif-preview-empty">
						{ __( 'No options yet — add some to the field.', 'rapid-ai-forms' ) }
					</em>
				) }
				{ options.map( ( opt, i ) => (
					<label key={ i }>
						<input type="checkbox" name={ `${ field.name }[]` } value={ opt.value || '' } disabled />{ ' ' }
						{ opt.label || opt.value || '' }
					</label>
				) ) }
			</div>
		);
	} else {
		control = (
			<input type="text" id={ id } name={ field.name } placeholder={ placeholder } disabled />
		);
	}

	return (
		<div className={ `raif-field raif-field--${ type }` }>
			{ label }
			{ control }
		</div>
	);
}

export default function FormPreview( { form } ) {
	const schema = form && form.schema ? form.schema : {};
	const fields = Array.isArray( schema.fields ) ? schema.fields : [];
	const submitLabel = schema.submit_label || __( 'Submit', 'rapid-ai-forms' );
	const showTitle = !! schema.show_title;

	return (
		<div className="raif-preview">
			<div className="raif-preview__header">
				<strong>{ __( 'Live preview', 'rapid-ai-forms' ) }</strong>
				<span className="raif-preview__hint">
					{ __( 'How the form will appear to visitors.', 'rapid-ai-forms' ) }
				</span>
			</div>
			<form
				className="raif-form raif-preview__form"
				onSubmit={ ( e ) => e.preventDefault() }
			>
				{ showTitle && form.title && (
					<h3 className="raif-form__title">{ form.title }</h3>
				) }
				{ fields.length === 0 ? (
					<p className="raif-preview-empty">
						{ __( 'No fields yet. Generate with AI or add fields to see them here.', 'rapid-ai-forms' ) }
					</p>
				) : (
					fields.map( ( field, i ) => (
						<PreviewField key={ field.name || i } field={ field } />
					) )
				) }
				<div className="raif-form__actions">
					<button type="submit" className="raif-form__submit" disabled>
						{ submitLabel }
					</button>
				</div>
			</form>
		</div>
	);
}
