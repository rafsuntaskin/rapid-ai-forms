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

const FIELD_TYPES = [
	{ label: 'Text', value: 'text' },
	{ label: 'Email', value: 'email' },
	{ label: 'Number', value: 'number' },
	{ label: 'Textarea', value: 'textarea' },
	{ label: 'Select', value: 'select' },
	{ label: 'Radio', value: 'radio' },
	{ label: 'Checkbox', value: 'checkbox' },
	{ label: 'Date', value: 'date' },
	{ label: 'Phone', value: 'tel' },
	{ label: 'URL', value: 'url' },
];

export default function FormEditor( { api, formId } ) {
	const [ form, setForm ] = useState( null );
	const [ loadError, setLoadError ] = useState( null );
	const [ savedAt, setSavedAt ] = useState( null );
	const [ prompt, setPrompt ] = useState( '' );

	const generate = useAsync( ( p, currentSchema ) =>
		api.post( 'ai/generate', { prompt: p, current_schema: currentSchema } )
	);
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
				description={ `[wp_ai_form id="${ form.id }"]` }
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
					<TextareaControl
						label={ __( 'Prompt', 'wp-ai-forms' ) }
						help={
							hasExistingFields
								? __( 'Describe a change. The AI keeps existing fields and applies only what you ask. Example: "Add a phone field after email" or "Make the message field optional".', 'wp-ai-forms' )
								: __( 'Describe the form you want. Example: "Contact form with name, email, phone, and a message field."', 'wp-ai-forms' )
						}
						value={ prompt }
						onChange={ setPrompt }
					/>
					<Button variant="secondary" onClick={ onGenerate } isBusy={ generate.loading } disabled={ ! prompt }>
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
								<ToggleControl
									label={ __( 'Required', 'wp-ai-forms' ) }
									checked={ !! f.required }
									onChange={ ( v ) => updateField( i, { required: v } ) }
								/>
								<Button variant="link" isDestructive onClick={ () => removeField( i ) }>
									{ __( 'Remove', 'wp-ai-forms' ) }
								</Button>
							</CardBody>
						</Card>
					) ) }
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
