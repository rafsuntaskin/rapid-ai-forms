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
	const [ verifying, setVerifying ] = useState( false );
	const [ verifyResult, setVerifyResult ] = useState( null );

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

	const verify = async () => {
		setVerifying( true );
		setVerifyResult( null );
		const cfg = settings.providers[ settings.active_provider ] || {};
		try {
			const res = await api.post( 'ai/verify', {
				provider: settings.active_provider,
				api_key: cfg.api_key || '',
				base_url: cfg.base_url || '',
				model: cfg.model || '',
			} );
			setVerifyResult( {
				status: 'success',
				text: res.latency_ms
					? __( 'Connection works.', 'rapid-ai-forms' ) + ` (${ res.latency_ms }ms)`
					: __( 'Connection works.', 'rapid-ai-forms' ),
			} );
		} catch ( e ) {
			setVerifyResult( { status: 'error', text: e.message || __( 'Verification failed.', 'rapid-ai-forms' ) } );
		} finally {
			setVerifying( false );
		}
	};

	const save = async () => {
		setSaving( true );
		setMessage( null );
		setVerifyResult( null );

		const cfg = settings.providers[ settings.active_provider ] || {};
		const payload = {
			provider: settings.active_provider,
			api_key: cfg.api_key || '',
			base_url: cfg.base_url || '',
			model: cfg.model || '',
		};

		try {
			// Step 1: verify against the live API. Aborts the save if it fails.
			const v = await api.post( 'ai/verify', payload );
			setVerifyResult( {
				status: 'success',
				text: v.latency_ms
					? __( 'Connection verified.', 'rapid-ai-forms' ) + ` (${ v.latency_ms }ms)`
					: __( 'Connection verified.', 'rapid-ai-forms' ),
			} );
		} catch ( e ) {
			setVerifyResult( {
				status: 'error',
				text: __( 'Save blocked — verification failed: ', 'rapid-ai-forms' ) + ( e.message || '' ),
			} );
			setSaving( false );
			return;
		}

		try {
			// Step 2: persist.
			const next = await api.put( 'settings', {
				active_provider: settings.active_provider,
				providers: settings.providers,
			} );
			setSettings( next );
			setMessage( { type: 'success', text: __( 'Settings saved.', 'rapid-ai-forms' ) } );
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
		<div className="raif-page raif-settings">
			<PageHeader
				title={ __( 'AI Settings', 'rapid-ai-forms' ) }
				description={ __( 'Configure the AI provider used to generate form schemas.', 'rapid-ai-forms' ) }
			/>

			{ message && (
				<Notice status={ message.type } isDismissible onRemove={ () => setMessage( null ) }>
					{ message.text }
				</Notice>
			) }

			<Card className="raif-mt">
				<CardHeader><strong>{ __( 'AI provider', 'rapid-ai-forms' ) }</strong></CardHeader>
				<CardBody>
					<SelectControl
						label={ __( 'Active provider', 'rapid-ai-forms' ) }
						help={ __( 'Choose which provider to use. Credentials below apply to the selected provider only.', 'rapid-ai-forms' ) }
						value={ activeKey }
						options={ providers.map( ( p ) => ( { label: p.label, value: p.key } ) ) }
						onChange={ ( v ) => setSettings( { ...settings, active_provider: v } ) }
					/>
				</CardBody>
			</Card>

			{ activeProvider && (
				<Card className="raif-mt">
					<CardHeader><strong>{ activeProvider.label }</strong></CardHeader>
					<CardBody>
						{ activeKey === 'wp_ai_client' ? (
							<Notice status="info" isDismissible={ false }>
								{ __(
									'This provider uses your site’s WordPress AI Connectors. Configure your API keys under Settings → Connectors. No additional configuration is needed here.',
									'rapid-ai-forms'
								) }
							</Notice>
						) : (
							<>
								<TextControl
									label={ __( 'API Key', 'rapid-ai-forms' ) }
									type="password"
									value={ activeCfg.api_key || '' }
									placeholder={ activeCfg.api_key_set ? __( 'Saved — leave blank to keep', 'rapid-ai-forms' ) : '' }
									onChange={ ( v ) => {
										updateProvider( activeKey, { api_key: v } );
										setVerifyResult( null );
									} }
								/>
								{ 'model' in activeCfg && (
									<TextControl
										label={ __( 'Model', 'rapid-ai-forms' ) }
										value={ activeCfg.model || '' }
										onChange={ ( v ) => {
											updateProvider( activeKey, { model: v } );
											setVerifyResult( null );
										} }
									/>
								) }
								{ 'base_url' in activeCfg && (
									<TextControl
										label={ __( 'Base URL', 'rapid-ai-forms' ) }
										value={ activeCfg.base_url || '' }
										onChange={ ( v ) => {
											updateProvider( activeKey, { base_url: v } );
											setVerifyResult( null );
										} }
									/>
								) }
							</>
						) }
						<div className="raif-verify">
							<Button
								variant="secondary"
								onClick={ verify }
								isBusy={ verifying }
								disabled={
									verifying ||
									( activeKey !== 'wp_ai_client' && ! activeCfg.api_key && ! activeCfg.api_key_set )
								}
							>
								{ __( 'Verify connection', 'rapid-ai-forms' ) }
							</Button>
							{ verifyResult && (
								<p className={ `raif-verify__result raif-verify__result--${ verifyResult.status }` }>
									{ verifyResult.status === 'success' ? '✓ ' : '✕ ' }
									{ verifyResult.text }
								</p>
							) }
						</div>
					</CardBody>
				</Card>
			) }

			<div className="raif-settings__footer">
				<Button variant="primary" onClick={ save } isBusy={ saving }>
					{ __( 'Save settings', 'rapid-ai-forms' ) }
				</Button>
			</div>
		</div>
	);
}
