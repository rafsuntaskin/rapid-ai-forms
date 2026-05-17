import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import PageHeader from '../../shared/components/PageHeader';

export default function Settings( { api } ) {
	const [ settings, setSettings ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ message, setMessage ] = useState( null );

	useEffect( () => {
		api.get( 'settings' ).then( setSettings );
	}, [ api ] );

	if ( ! settings ) {
		return <Spinner />;
	}

	const updateProvider = ( key, patch ) => {
		setSettings( {
			...settings,
			providers: {
				...settings.providers,
				[ key ]: { ...settings.providers[ key ], ...patch },
			},
		} );
	};

	const save = async () => {
		setSaving( true );
		setMessage( null );
		try {
			const next = await api.put( 'settings', {
				active_provider: settings.active_provider,
				providers: settings.providers,
			} );
			setSettings( next );
			setMessage( { type: 'success', text: __( 'Settings saved.', 'wp-ai-forms' ) } );
		} catch ( e ) {
			setMessage( { type: 'error', text: e.message } );
		} finally {
			setSaving( false );
		}
	};

	const providers = settings.available_providers || [];

	return (
		<div className="wpaif-page">
			<PageHeader
				title={ __( 'AI Settings', 'wp-ai-forms' ) }
				description={ __( 'Configure the AI provider used to generate form schemas.', 'wp-ai-forms' ) }
				actions={
					<Button variant="primary" onClick={ save } isBusy={ saving }>
						{ __( 'Save settings', 'wp-ai-forms' ) }
					</Button>
				}
			/>

			{ message && (
				<Notice status={ message.type } isDismissible onRemove={ () => setMessage( null ) }>
					{ message.text }
				</Notice>
			) }

			<Card className="wpaif-mt">
					<CardHeader><strong>{ __( 'AI provider', 'wp-ai-forms' ) }</strong></CardHeader>
					<CardBody>
						<SelectControl
							label={ __( 'Active provider', 'wp-ai-forms' ) }
							value={ settings.active_provider }
							options={ providers.map( ( p ) => ( { label: p.label, value: p.key } ) ) }
							onChange={ ( v ) => setSettings( { ...settings, active_provider: v } ) }
						/>

						{ providers.map( ( p ) => {
							const cfg = settings.providers[ p.key ] || {};
							return (
								<Card key={ p.key } className="wpaif-mt">
									<CardHeader>{ p.label }</CardHeader>
									<CardBody>
										<TextControl
											label={ __( 'API Key', 'wp-ai-forms' ) }
											type="password"
											value={ cfg.api_key || '' }
											placeholder={ cfg.api_key_set ? __( 'Saved — leave blank to keep', 'wp-ai-forms' ) : '' }
											onChange={ ( v ) => updateProvider( p.key, { api_key: v } ) }
										/>
										{ 'model' in cfg && (
											<TextControl
												label={ __( 'Model', 'wp-ai-forms' ) }
												value={ cfg.model || '' }
												onChange={ ( v ) => updateProvider( p.key, { model: v } ) }
											/>
										) }
										{ 'base_url' in cfg && (
											<TextControl
												label={ __( 'Base URL', 'wp-ai-forms' ) }
												value={ cfg.base_url || '' }
												onChange={ ( v ) => updateProvider( p.key, { base_url: v } ) }
											/>
										) }
									</CardBody>
								</Card>
							);
						} ) }
				</CardBody>
			</Card>
		</div>
	);
}
