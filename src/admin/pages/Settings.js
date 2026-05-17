import { useEffect, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	RadioControl,
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
				mode: settings.mode,
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

	const byokProviders = ( settings.available_providers || [] ).filter( ( p ) => p.key !== 'managed' );

	return (
		<div className="wpaif-page">
			<PageHeader
				title={ __( 'AI Settings', 'wp-ai-forms' ) }
				description={ __( 'Choose how AI is powered: bring your own key or use our managed credit-based service.', 'wp-ai-forms' ) }
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

			<Card>
				<CardHeader><strong>{ __( 'Mode', 'wp-ai-forms' ) }</strong></CardHeader>
				<CardBody>
					<RadioControl
						selected={ settings.mode }
						options={ [
							{ label: __( 'Bring your own key (BYOK)', 'wp-ai-forms' ), value: 'byok' },
							{ label: __( 'Managed service (credit-based)', 'wp-ai-forms' ), value: 'managed' },
						] }
						onChange={ ( v ) => setSettings( { ...settings, mode: v } ) }
					/>
				</CardBody>
			</Card>

			{ settings.mode === 'byok' && (
				<Card className="wpaif-mt">
					<CardHeader><strong>{ __( 'BYOK provider', 'wp-ai-forms' ) }</strong></CardHeader>
					<CardBody>
						<SelectControl
							label={ __( 'Active provider', 'wp-ai-forms' ) }
							value={ settings.active_provider }
							options={ byokProviders.map( ( p ) => ( { label: p.label, value: p.key } ) ) }
							onChange={ ( v ) => setSettings( { ...settings, active_provider: v } ) }
						/>

						{ byokProviders.map( ( p ) => {
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
			) }

			{ settings.mode === 'managed' && (
				<Card className="wpaif-mt">
					<CardHeader><strong>{ __( 'Managed service', 'wp-ai-forms' ) }</strong></CardHeader>
					<CardBody>
						<TextControl
							label={ __( 'License key', 'wp-ai-forms' ) }
							type="password"
							value={ settings.providers.managed.license_key || '' }
							placeholder={ settings.providers.managed.license_key_set ? __( 'Saved — leave blank to keep', 'wp-ai-forms' ) : '' }
							onChange={ ( v ) => updateProvider( 'managed', { license_key: v } ) }
							help={ __( 'Credits will be deducted per AI generation.', 'wp-ai-forms' ) }
						/>
					</CardBody>
				</Card>
			) }
		</div>
	);
}
