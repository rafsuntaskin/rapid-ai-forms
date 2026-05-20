import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	Spinner,
	TextControl,
	TextareaControl,
	SelectControl,
	ToggleControl,
	Flex,
	FlexItem,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import PageHeader from '../../shared/components/PageHeader';
import { useAsync } from '../../shared/hooks/useAsync';
import FormPreview from '../components/FormPreview';
import ShortcodeCopy from '../components/ShortcodeCopy';
import { useAiConfigured } from '../hooks/useAiConfigured';

const FIELD_TYPES = [
	{ label: 'Text', value: 'text' },
	{ label: 'Email', value: 'email' },
	{ label: 'Password', value: 'password' },
	{ label: 'Number', value: 'number' },
	{ label: 'Textarea', value: 'textarea' },
	{ label: 'Select', value: 'select' },
	{ label: 'Radio', value: 'radio' },
	{ label: 'Checkbox (single)', value: 'checkbox' },
	{ label: 'Checkbox group', value: 'checkbox_group' },
	{ label: 'Date', value: 'date' },
	{ label: 'Phone', value: 'tel' },
	{ label: 'URL', value: 'url' },
	{ label: 'Hidden', value: 'hidden' },
];

const TYPES_WITH_OPTIONS = [ 'select', 'radio', 'checkbox_group' ];

export default function FormEditor( { api, formId } ) {
	const [ form, setForm ] = useState( null );
	const [ loadError, setLoadError ] = useState( null );
	const [ savedAt, setSavedAt ] = useState( null );
	const [ prompt, setPrompt ] = useState( '' );

	const generate = useAsync( ( p, currentSchema ) =>
		api.post( 'ai/generate', { prompt: p, current_schema: currentSchema } )
	);
	const ai = useAiConfigured( api );
	const settingsUrl = ( window.WP_AI_FORMS_ADMIN || {} ).settingsUrl || '';
	const save = useAsync( ( payload ) => api.put( `forms/${ formId }`, payload ) );

	useEffect( () => {
		api.get( `forms/${ formId }` )
			.then( setForm )
			.catch( ( e ) => setLoadError( e.message || 'Failed to load form' ) );
	}, [ api, formId ] );

	if ( loadError ) {
		return <Notice status="error" isDismissible={ false }>{ loadError }</Notice>;
	}
	if ( ! form ) {
		return <Spinner />;
	}

	const updateSchema = ( patch ) => {
		setForm( { ...form, schema: { ...form.schema, ...patch } } );
	};

	const notifications = form.schema.notifications || {
		enabled: true,
		to: '',
		subject: '',
		body: '',
		reply_to_field: '',
	};

	const updateNotifications = ( patch ) => {
		updateSchema( { notifications: { ...notifications, ...patch } } );
	};

	const emailFieldOptions = [
		{ label: __( '— None —', 'wp-ai-forms' ), value: '' },
		...( form.schema.fields || [] )
			.filter( ( f ) => f.type === 'email' )
			.map( ( f ) => ( { label: `${ f.label || f.name } (${ f.name })`, value: f.name } ) ),
	];

	const updateField = ( idx, patch ) => {
		const fields = [ ...( form.schema.fields || [] ) ];
		fields[ idx ] = { ...fields[ idx ], ...patch };
		updateSchema( { fields } );
	};

	const removeField = ( idx ) => {
		const fields = [ ...( form.schema.fields || [] ) ];
		fields.splice( idx, 1 );
		updateSchema( { fields } );
	};

	const addField = () => {
		const fields = [ ...( form.schema.fields || [] ) ];
		fields.push( { name: `field_${ fields.length + 1 }`, label: 'New field', type: 'text', required: false } );
		updateSchema( { fields } );
	};

	const updateOption = ( fieldIdx, optIdx, patch ) => {
		const options = [ ...( form.schema.fields[ fieldIdx ].options || [] ) ];
		options[ optIdx ] = { ...options[ optIdx ], ...patch };
		updateField( fieldIdx, { options } );
	};

	const addOption = ( fieldIdx ) => {
		const options = [ ...( form.schema.fields[ fieldIdx ].options || [] ) ];
		const n = options.length + 1;
		options.push( { label: `Option ${ n }`, value: `option_${ n }` } );
		updateField( fieldIdx, { options } );
	};

	const removeOption = ( fieldIdx, optIdx ) => {
		const options = [ ...( form.schema.fields[ fieldIdx ].options || [] ) ];
		options.splice( optIdx, 1 );
		updateField( fieldIdx, { options } );
	};

	const hasExistingFields = ( form.schema.fields || [] ).length > 0;

	const onGenerate = async () => {
		const currentSchema = hasExistingFields ? form.schema : null;
		const schema = await generate.run( prompt, currentSchema );
		setForm( {
			...form,
			schema,
			// Only adopt the AI's title on a from-scratch generation; otherwise keep the user's title.
			title: hasExistingFields ? form.title : schema.title || form.title,
			ai_prompt: prompt,
		} );
		setPrompt( '' );
	};

	const onSave = async () => {
		const updated = await save.run( {
			title: form.title,
			status: form.status,
			schema: form.schema,
			ai_prompt: form.ai_prompt,
		} );
		setForm( updated );
		setSavedAt( new Date().toLocaleTimeString() );
	};

	return (
		<div className="wpaif-page wpaif-editor">
			<PageHeader
				title={ __( 'Edit form', 'wp-ai-forms' ) }
				description={
					<ShortcodeCopy
						shortcode={ `[wp_ai_form id="${ form.id }"]` }
						label={ __( 'Embed:', 'wp-ai-forms' ) }
					/>
				}
				actions={
					<Flex>
						<FlexItem>
							<Button href="#/" variant="tertiary">{ __( 'Back', 'wp-ai-forms' ) }</Button>
						</FlexItem>
						<FlexItem>
							<Button variant="primary" onClick={ onSave } isBusy={ save.loading }>
								{ __( 'Save', 'wp-ai-forms' ) }
							</Button>
						</FlexItem>
					</Flex>
				}
			/>
			{ savedAt && <Notice status="success" isDismissible>{ __( 'Saved at ', 'wp-ai-forms' ) + savedAt }</Notice> }
			{ save.error && <Notice status="error" isDismissible={ false }>{ save.error.message }</Notice> }

			<div className="wpaif-editor__columns">
				<div className="wpaif-editor__main">
			<Card>
				<CardHeader>
					<strong>
						{ hasExistingFields
							? __( 'Edit with AI', 'wp-ai-forms' )
							: __( 'Generate with AI', 'wp-ai-forms' ) }
					</strong>
				</CardHeader>
				<CardBody>
					{ ai.ready && ! ai.configured && (
						<Notice status="warning" isDismissible={ false }>
							{ __( 'No AI key set. Add one in', 'wp-ai-forms' ) }{ ' ' }
							<a href={ settingsUrl }>{ __( 'AI Forms → Settings', 'wp-ai-forms' ) }</a>{ ' ' }
							{ __( 'to enable AI generation. You can still add and edit fields below by hand.', 'wp-ai-forms' ) }
						</Notice>
					) }
					<TextareaControl
						label={ __( 'Prompt', 'wp-ai-forms' ) }
						help={
							hasExistingFields
								? __( 'Describe a change. The AI keeps existing fields and applies only what you ask. Example: "Add a phone field after email" or "Make the message field optional".', 'wp-ai-forms' )
								: __( 'Describe the form you want. Example: "Contact form with name, email, phone, and a message field."', 'wp-ai-forms' )
						}
						value={ prompt }
						onChange={ setPrompt }
						disabled={ ai.ready && ! ai.configured }
					/>
					<Button
						variant="secondary"
						onClick={ onGenerate }
						isBusy={ generate.loading }
						disabled={ ! prompt || ( ai.ready && ! ai.configured ) }
					>
						{ hasExistingFields
							? __( 'Apply changes', 'wp-ai-forms' )
							: __( 'Generate fields', 'wp-ai-forms' ) }
					</Button>
					{ generate.error && (
						<Notice status="error" isDismissible={ false } className="wpaif-mt">
							{ generate.error.message }
						</Notice>
					) }
				</CardBody>
			</Card>

			<Card className="wpaif-mt">
				<CardHeader><strong>{ __( 'Form details', 'wp-ai-forms' ) }</strong></CardHeader>
				<CardBody>
					<TextControl
						label={ __( 'Title', 'wp-ai-forms' ) }
						value={ form.title || '' }
						onChange={ ( v ) => setForm( { ...form, title: v } ) }
					/>
					<TextControl
						label={ __( 'Submit button label', 'wp-ai-forms' ) }
						value={ form.schema.submit_label || 'Submit' }
						onChange={ ( v ) => updateSchema( { submit_label: v } ) }
					/>
					<SelectControl
						label={ __( 'Status', 'wp-ai-forms' ) }
						value={ form.status }
						options={ [
							{ label: 'Draft', value: 'draft' },
							{ label: 'Published', value: 'published' },
						] }
						onChange={ ( v ) => setForm( { ...form, status: v } ) }
					/>
				</CardBody>
			</Card>

			<Card className="wpaif-mt">
				<CardHeader>
					<Flex>
						<FlexItem><strong>{ __( 'Fields', 'wp-ai-forms' ) }</strong></FlexItem>
						<FlexItem>
							<Button variant="secondary" onClick={ addField }>
								{ __( 'Add field', 'wp-ai-forms' ) }
							</Button>
						</FlexItem>
					</Flex>
				</CardHeader>
				<CardBody>
					{ ( form.schema.fields || [] ).length === 0 && (
						<p>{ __( 'No fields yet. Generate with AI or add manually.', 'wp-ai-forms' ) }</p>
					) }
					{ ( form.schema.fields || [] ).map( ( f, i ) => (
						<Card key={ i } className="wpaif-field-card">
							<CardBody>
								<Flex align="flex-start" gap={ 3 }>
									<FlexItem isBlock>
										<TextControl
											label={ __( 'Label', 'wp-ai-forms' ) }
											value={ f.label || '' }
											onChange={ ( v ) => updateField( i, { label: v } ) }
										/>
									</FlexItem>
									<FlexItem isBlock>
										<TextControl
											label={ __( 'Name (snake_case)', 'wp-ai-forms' ) }
											value={ f.name || '' }
											onChange={ ( v ) => updateField( i, { name: v } ) }
										/>
									</FlexItem>
									<FlexItem isBlock>
										<SelectControl
											label={ __( 'Type', 'wp-ai-forms' ) }
											value={ f.type || 'text' }
											options={ FIELD_TYPES }
											onChange={ ( v ) => updateField( i, { type: v } ) }
										/>
									</FlexItem>
								</Flex>
								{ f.type !== 'hidden' && (
									<ToggleControl
										label={ __( 'Required', 'wp-ai-forms' ) }
										checked={ !! f.required }
										onChange={ ( v ) => updateField( i, { required: v } ) }
									/>
								) }
								{ f.type === 'hidden' && (
									<TextControl
										label={ __( 'Default value', 'wp-ai-forms' ) }
										help={ __( 'This value is submitted with the form. Not visible or editable to visitors.', 'wp-ai-forms' ) }
										value={ f.default_value || '' }
										onChange={ ( v ) => updateField( i, { default_value: v } ) }
									/>
								) }
								{ TYPES_WITH_OPTIONS.includes( f.type ) && (
									<div className="wpaif-options">
										<div className="wpaif-options__header">
											<strong>{ __( 'Options', 'wp-ai-forms' ) }</strong>
											<Button variant="secondary" size="small" onClick={ () => addOption( i ) }>
												{ __( 'Add option', 'wp-ai-forms' ) }
											</Button>
										</div>
										{ ( f.options || [] ).length === 0 && (
											<p className="wpaif-options__empty">
												{ __( 'No options yet. Add at least 2 for the field to render.', 'wp-ai-forms' ) }
											</p>
										) }
										{ ( f.options || [] ).map( ( opt, oi ) => (
											<Flex key={ oi } align="flex-end" gap={ 2 } className="wpaif-options__row">
												<FlexItem isBlock>
													<TextControl
														label={ oi === 0 ? __( 'Label', 'wp-ai-forms' ) : '' }
														hideLabelFromVision={ oi !== 0 }
														value={ opt.label || '' }
														onChange={ ( v ) => updateOption( i, oi, { label: v } ) }
													/>
												</FlexItem>
												<FlexItem isBlock>
													<TextControl
														label={ oi === 0 ? __( 'Value', 'wp-ai-forms' ) : '' }
														hideLabelFromVision={ oi !== 0 }
														value={ opt.value || '' }
														onChange={ ( v ) => updateOption( i, oi, { value: v } ) }
													/>
												</FlexItem>
												<FlexItem>
													<Button
														variant="tertiary"
														size="small"
														isDestructive
														onClick={ () => removeOption( i, oi ) }
														aria-label={ __( 'Remove option', 'wp-ai-forms' ) }
													>
														×
													</Button>
												</FlexItem>
											</Flex>
										) ) }
									</div>
								) }
								<Button variant="link" isDestructive onClick={ () => removeField( i ) }>
									{ __( 'Remove', 'wp-ai-forms' ) }
								</Button>
							</CardBody>
						</Card>
					) ) }
				</CardBody>
			</Card>

			<Card className="wpaif-mt">
				<CardHeader><strong>{ __( 'Email notifications', 'wp-ai-forms' ) }</strong></CardHeader>
				<CardBody>
					<ToggleControl
						label={ __( 'Send an email when this form is submitted', 'wp-ai-forms' ) }
						checked={ !! notifications.enabled }
						onChange={ ( v ) => updateNotifications( { enabled: v } ) }
					/>
					{ notifications.enabled && (
						<>
							<TextControl
								label={ __( 'To', 'wp-ai-forms' ) }
								help={ __( 'Comma-separated email addresses. Leave blank to use the site admin email.', 'wp-ai-forms' ) }
								value={ notifications.to || '' }
								onChange={ ( v ) => updateNotifications( { to: v } ) }
							/>
							<TextControl
								label={ __( 'Subject', 'wp-ai-forms' ) }
								help={ __( 'Mail-tags allowed. Leave blank for "New submission: <form title>".', 'wp-ai-forms' ) }
								value={ notifications.subject || '' }
								onChange={ ( v ) => updateNotifications( { subject: v } ) }
							/>
							<TextareaControl
								label={ __( 'Body', 'wp-ai-forms' ) }
								help={ __( 'Mail-tags: {all_fields}, {field_name}, {form_title}, {site_name}, {site_url}, {admin_email}. Leave blank to send all field values.', 'wp-ai-forms' ) }
								value={ notifications.body || '' }
								onChange={ ( v ) => updateNotifications( { body: v } ) }
								rows={ 6 }
							/>
							<SelectControl
								label={ __( 'Reply-To field', 'wp-ai-forms' ) }
								help={ __( 'When set, replies to the notification go to the submitter’s email.', 'wp-ai-forms' ) }
								value={ notifications.reply_to_field || '' }
								options={ emailFieldOptions }
								onChange={ ( v ) => updateNotifications( { reply_to_field: v } ) }
							/>
						</>
					) }
				</CardBody>
			</Card>
				</div>
				<aside className="wpaif-editor__side">
					<FormPreview form={ form } />
				</aside>
			</div>
		</div>
	);
}
