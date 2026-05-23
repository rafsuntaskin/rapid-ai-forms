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
	const generateBody = useAsync( ( currentSchema ) =>
		api.post( 'ai/generate', {
			prompt:
				'Rewrite ONLY the notifications.body field of this schema. Write a friendly, multi-line email body that a site owner would want to receive when this form is submitted — short intro line naming the form purpose, then the key fields referenced by mail-tags using each field\'s exact "name" attribute, and end with "{all_fields}" on its own line. Do not change any fields, the title, or any other notification setting.',
			current_schema: currentSchema,
		} )
	);
	const ai = useAiConfigured( api );
	const settingsUrl = ( window.EASY_AI_FORMS_ADMIN || {} ).settingsUrl || '';
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

	const onGenerateBody = async () => {
		const schema = await generateBody.run( form.schema );
		const newBody = schema?.notifications?.body || '';
		if ( newBody ) {
			updateNotifications( { body: newBody } );
		}
	};

	const emailFieldOptions = [
		{ label: __( '— None —', 'easy-ai-forms' ), value: '' },
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

	const moveField = ( idx, delta ) => {
		const fields = [ ...( form.schema.fields || [] ) ];
		const target = idx + delta;
		if ( target < 0 || target >= fields.length ) {
			return;
		}
		[ fields[ idx ], fields[ target ] ] = [ fields[ target ], fields[ idx ] ];
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

	const actionBar = ( position ) => (
		<div className={ `eaif-editor__actions eaif-editor__actions--${ position }` }>
			<Flex justify="space-between" align="center" gap={ 3 }>
				<FlexItem>
					<Button href="#/" variant="tertiary">{ __( '← Back to forms', 'easy-ai-forms' ) }</Button>
				</FlexItem>
				<FlexItem>
					<Flex gap={ 2 } align="center">
						{ position === 'top' && savedAt && (
							<FlexItem>
								<span className="eaif-editor__saved-status">
									{ __( 'Saved at ', 'easy-ai-forms' ) + savedAt }
								</span>
							</FlexItem>
						) }
						<FlexItem>
							<Button variant="primary" onClick={ onSave } isBusy={ save.loading }>
								{ __( 'Save', 'easy-ai-forms' ) }
							</Button>
						</FlexItem>
					</Flex>
				</FlexItem>
			</Flex>
		</div>
	);

	return (
		<div className="eaif-page eaif-editor">
			<PageHeader
				title={ __( 'Edit form', 'easy-ai-forms' ) }
				description={
					<ShortcodeCopy
						shortcode={ `[easy_ai_form id="${ form.id }"]` }
						label={ __( 'Embed:', 'easy-ai-forms' ) }
					/>
				}
			/>
			{ save.error && <Notice status="error" isDismissible={ false }>{ save.error.message }</Notice> }

			<div className="eaif-editor__columns">
				<div className="eaif-editor__main">
			{ actionBar( 'top' ) }
			<Card>
				<CardHeader>
					<strong>
						{ hasExistingFields
							? __( 'Edit with AI', 'easy-ai-forms' )
							: __( 'Generate with AI', 'easy-ai-forms' ) }
					</strong>
				</CardHeader>
				<CardBody>
					{ ai.ready && ! ai.configured && (
						<Notice status="warning" isDismissible={ false }>
							{ __( 'No AI key set. Add one in', 'easy-ai-forms' ) }{ ' ' }
							<a href={ settingsUrl }>{ __( 'AI Forms → Settings', 'easy-ai-forms' ) }</a>{ ' ' }
							{ __( 'to enable AI generation. You can still add and edit fields below by hand.', 'easy-ai-forms' ) }
						</Notice>
					) }
					<TextareaControl
						label={ __( 'Prompt', 'easy-ai-forms' ) }
						help={
							hasExistingFields
								? __( 'Describe a change. The AI keeps existing fields and applies only what you ask. Example: "Add a phone field after email" or "Make the message field optional".', 'easy-ai-forms' )
								: __( 'Describe the form you want. Example: "Contact form with name, email, phone, and a message field."', 'easy-ai-forms' )
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
							? __( 'Apply changes', 'easy-ai-forms' )
							: __( 'Generate fields', 'easy-ai-forms' ) }
					</Button>
					{ generate.error && (
						<Notice status="error" isDismissible={ false } className="eaif-mt">
							{ generate.error.message }
						</Notice>
					) }
				</CardBody>
			</Card>

			<Card className="eaif-mt">
				<CardHeader><strong>{ __( 'Form details', 'easy-ai-forms' ) }</strong></CardHeader>
				<CardBody>
					<TextControl
						label={ __( 'Title', 'easy-ai-forms' ) }
						value={ form.title || '' }
						onChange={ ( v ) => setForm( { ...form, title: v } ) }
					/>
					<TextControl
						label={ __( 'Submit button label', 'easy-ai-forms' ) }
						value={ form.schema.submit_label || 'Submit' }
						onChange={ ( v ) => updateSchema( { submit_label: v } ) }
					/>
					<SelectControl
						label={ __( 'Status', 'easy-ai-forms' ) }
						value={ form.status }
						options={ [
							{ label: 'Draft', value: 'draft' },
							{ label: 'Published', value: 'published' },
						] }
						onChange={ ( v ) => setForm( { ...form, status: v } ) }
					/>
				</CardBody>
			</Card>

			<Card className="eaif-mt">
				<CardHeader>
					<Flex>
						<FlexItem><strong>{ __( 'Fields', 'easy-ai-forms' ) }</strong></FlexItem>
						<FlexItem>
							<Button variant="secondary" onClick={ addField }>
								{ __( 'Add field', 'easy-ai-forms' ) }
							</Button>
						</FlexItem>
					</Flex>
				</CardHeader>
				<CardBody>
					{ ( form.schema.fields || [] ).length === 0 && (
						<p>{ __( 'No fields yet. Generate with AI or add manually.', 'easy-ai-forms' ) }</p>
					) }
					{ ( form.schema.fields || [] ).map( ( f, i ) => (
						<Card key={ i } className="eaif-field-card">
							<CardBody>
								<Flex align="flex-start" gap={ 3 }>
									<FlexItem isBlock>
										<TextControl
											label={ __( 'Label', 'easy-ai-forms' ) }
											value={ f.label || '' }
											onChange={ ( v ) => updateField( i, { label: v } ) }
										/>
									</FlexItem>
									<FlexItem isBlock>
										<TextControl
											label={ __( 'Name (snake_case)', 'easy-ai-forms' ) }
											value={ f.name || '' }
											onChange={ ( v ) => updateField( i, { name: v } ) }
										/>
									</FlexItem>
									<FlexItem isBlock>
										<SelectControl
											label={ __( 'Type', 'easy-ai-forms' ) }
											value={ f.type || 'text' }
											options={ FIELD_TYPES }
											onChange={ ( v ) => updateField( i, { type: v } ) }
										/>
									</FlexItem>
								</Flex>
								{ f.type !== 'hidden' && (
									<ToggleControl
										label={ __( 'Required', 'easy-ai-forms' ) }
										checked={ !! f.required }
										onChange={ ( v ) => updateField( i, { required: v } ) }
									/>
								) }
								{ f.type === 'hidden' && (
									<TextControl
										label={ __( 'Default value', 'easy-ai-forms' ) }
										help={ __( 'This value is submitted with the form. Not visible or editable to visitors.', 'easy-ai-forms' ) }
										value={ f.default_value || '' }
										onChange={ ( v ) => updateField( i, { default_value: v } ) }
									/>
								) }
								{ TYPES_WITH_OPTIONS.includes( f.type ) && (
									<div className="eaif-options">
										<div className="eaif-options__header">
											<strong>{ __( 'Options', 'easy-ai-forms' ) }</strong>
											<Button variant="secondary" size="small" onClick={ () => addOption( i ) }>
												{ __( 'Add option', 'easy-ai-forms' ) }
											</Button>
										</div>
										{ ( f.options || [] ).length === 0 && (
											<p className="eaif-options__empty">
												{ __( 'No options yet. Add at least 2 for the field to render.', 'easy-ai-forms' ) }
											</p>
										) }
										{ ( f.options || [] ).map( ( opt, oi ) => (
											<Flex key={ oi } align="flex-end" gap={ 2 } className="eaif-options__row">
												<FlexItem isBlock>
													<TextControl
														label={ oi === 0 ? __( 'Label', 'easy-ai-forms' ) : '' }
														hideLabelFromVision={ oi !== 0 }
														value={ opt.label || '' }
														onChange={ ( v ) => updateOption( i, oi, { label: v } ) }
													/>
												</FlexItem>
												<FlexItem isBlock>
													<TextControl
														label={ oi === 0 ? __( 'Value', 'easy-ai-forms' ) : '' }
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
														aria-label={ __( 'Remove option', 'easy-ai-forms' ) }
													>
														×
													</Button>
												</FlexItem>
											</Flex>
										) ) }
									</div>
								) }
								<Flex justify="space-between" align="center" className="eaif-field-actions">
									<FlexItem>
										<Flex gap={ 1 }>
											<FlexItem>
												<Button
													variant="tertiary"
													size="small"
													onClick={ () => moveField( i, -1 ) }
													disabled={ i === 0 }
													aria-label={ __( 'Move field up', 'easy-ai-forms' ) }
												>
													↑
												</Button>
											</FlexItem>
											<FlexItem>
												<Button
													variant="tertiary"
													size="small"
													onClick={ () => moveField( i, 1 ) }
													disabled={ i === ( form.schema.fields || [] ).length - 1 }
													aria-label={ __( 'Move field down', 'easy-ai-forms' ) }
												>
													↓
												</Button>
											</FlexItem>
										</Flex>
									</FlexItem>
									<FlexItem>
										<Button variant="link" isDestructive onClick={ () => removeField( i ) }>
											{ __( 'Remove', 'easy-ai-forms' ) }
										</Button>
									</FlexItem>
								</Flex>
							</CardBody>
						</Card>
					) ) }
				</CardBody>
			</Card>

			<Card className="eaif-mt">
				<CardHeader><strong>{ __( 'Email notifications', 'easy-ai-forms' ) }</strong></CardHeader>
				<CardBody>
					<ToggleControl
						label={ __( 'Send an email when this form is submitted', 'easy-ai-forms' ) }
						checked={ !! notifications.enabled }
						onChange={ ( v ) => updateNotifications( { enabled: v } ) }
					/>
					{ notifications.enabled && (
						<>
							<TextControl
								label={ __( 'To', 'easy-ai-forms' ) }
								help={ __( 'Comma-separated email addresses. Leave blank to use the site admin email.', 'easy-ai-forms' ) }
								value={ notifications.to || '' }
								onChange={ ( v ) => updateNotifications( { to: v } ) }
							/>
							<TextControl
								label={ __( 'Subject', 'easy-ai-forms' ) }
								help={ __( 'Mail-tags allowed. Leave blank for "New submission: <form title>".', 'easy-ai-forms' ) }
								value={ notifications.subject || '' }
								onChange={ ( v ) => updateNotifications( { subject: v } ) }
							/>
							<TextareaControl
								label={ __( 'Body', 'easy-ai-forms' ) }
								help={ __( 'Mail-tags: {all_fields}, {field_name}, {form_title}, {site_name}, {site_url}, {admin_email}. Leave blank to send all field values.', 'easy-ai-forms' ) }
								value={ notifications.body || '' }
								onChange={ ( v ) => updateNotifications( { body: v } ) }
								rows={ 6 }
							/>
							<Flex justify="flex-start" gap={ 2 } className="eaif-mt-sm">
								<FlexItem>
									<Button
										variant="secondary"
										onClick={ onGenerateBody }
										isBusy={ generateBody.loading }
										disabled={
											generateBody.loading ||
											( ai.ready && ! ai.configured ) ||
											( form.schema.fields || [] ).length === 0
										}
									>
										{ __( 'Generate with AI', 'easy-ai-forms' ) }
									</Button>
								</FlexItem>
							</Flex>
							{ generateBody.error && (
								<Notice status="error" isDismissible={ false } className="eaif-mt-sm">
									{ generateBody.error.message }
								</Notice>
							) }
							<SelectControl
								label={ __( 'Reply-To field', 'easy-ai-forms' ) }
								help={ __( 'When set, replies to the notification go to the submitter’s email.', 'easy-ai-forms' ) }
								value={ notifications.reply_to_field || '' }
								options={ emailFieldOptions }
								onChange={ ( v ) => updateNotifications( { reply_to_field: v } ) }
							/>
						</>
					) }
				</CardBody>
			</Card>
			{ actionBar( 'bottom' ) }
				</div>
				<aside className="eaif-editor__side">
					<FormPreview form={ form } />
				</aside>
			</div>
		</div>
	);
}
