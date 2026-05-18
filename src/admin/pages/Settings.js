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
	const activeKey = settings.active_provider;
	const activeProvider = providers.find( ( p ) => p.key === activeKey );
	const activeCfg = settings.providers[ activeKey ] || {};

	return (
		<div className="wpaif-page wpaif-settings">
			<PageHeader
				title={ __( 'AI Settings', 'wp-ai-forms' ) }
				description={ __( 'Configure the AI provider used to generate form schemas.', 'wp-ai-forms' ) }
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
						help={ __( 'Choose which provider to use. Credentials below apply to the selected provider only.', 'wp-ai-forms' ) }
						value={ activeKey }
						options={ providers.map( ( p ) => ( { label: p.label, value: p.key } ) ) }
						onChange={ ( v ) => setSettings( { ...settings, active_provider: v } ) }
					/>
				</CardBody>
			</Card>

			{ activeProvider && (
				<Card className="wpaif-mt">
					<CardHeader><strong>{ activeProvider.label }</strong></CardHeader>
					<CardBody>
						<TextControl
							label={ __( 'API Key', 'wp-ai-forms' ) }
							type="password"
							value={ activeCfg.api_key || '' }
							placeholder={ activeCfg.api_key_set ? __( 'Saved — leave blank to keep', 'wp-ai-forms' ) : '' }
							onChange={ ( v ) => updateProvider( activeKey, { api_key: v } ) }
						/>
						{ 'model' in activeCfg && (
							<TextControl
								label={ __( 'Model', 'wp-ai-forms' ) }
								value={ activeCfg.model || '' }
								onChange={ ( v ) => updateProvider( activeKey, { model: v } ) }
							/>
						) }
						{ 'base_url' in activeCfg && (
							<TextControl
								label={ __( 'Base URL', 'wp-ai-forms' ) }
								value={ activeCfg.base_url || '' }
								onChange={ ( v ) => updateProvider( activeKey, { base_url: v } ) }
							/>
						) }
					</CardBody>
				</Card>
			) }

			<div className="wpaif-settings__footer">
				<Button variant="primary" onClick={ save } isBusy={ saving }>
					{ __( 'Save settings', 'wp-ai-forms' ) }
				</Button>
			</div>
		</div>
	);
}
