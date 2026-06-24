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
import { __, sprintf } from '@wordpress/i18n';
import PageHeader from '../../shared/components/PageHeader';

export default function Settings( { api } ) {
	const [ settings, setSettings ] = useState( null );
	const [ saving, setSaving ] = useState( false );
	const [ message, setMessage ] = useState( null );
	const [ verifying, setVerifying ] = useState( false );
	const [ verifyResult, setVerifyResult ] = useState( null );
	// Hosted-provider (Rapid AI Cloud) connection/quota state.
	const [ managed, setManaged ] = useState( null );
	const [ managedBusy, setManagedBusy ] = useState( false );
	const [ managedError, setManagedError ] = useState( null );

	useEffect( () => {
		api.get( 'settings' ).then( ( s ) => {
			setSettings( s );
			// The provider only appears when enabled; fetch its live status.
			if ( s.providers && s.providers.managed ) {
				api.get( 'managed/status' ).then( setManaged ).catch( () => {} );
			}
		} );
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

	const runManaged = async ( fn ) => {
		setManagedBusy( true );
		setManagedError( null );
		try {
			setManaged( await fn() );
		} catch ( e ) {
			setManagedError( e.message || __( 'Something went wrong. Please try again.', 'rapid-ai-forms' ) );
		} finally {
			setManagedBusy( false );
		}
	};

	const connect = () => runManaged( () => api.post( 'managed/register', {} ) );
	const refreshManaged = () => runManaged( () => api.get( 'managed/status' ) );
	const disconnect = () => runManaged( () => api.post( 'managed/disconnect', {} ) );

	const save = async () => {
		setSaving( true );
		setMessage( null );
		setVerifyResult( null );

		// The hosted provider has no key to verify here — its connection is
		// proven by the Connect handshake — so skip the verify gate for it.
		if ( settings.active_provider !== 'managed' ) {
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

	// Derived quota meter values for the connected hosted provider.
	let quota = null;
	if ( managed && managed.connected ) {
		const allowance = managed.free_allowance || 0;
		const remaining = Math.max( 0, managed.free_remaining ?? 0 );
		quota = {
			allowance,
			remaining,
			pct: allowance > 0 ? Math.round( ( remaining / allowance ) * 100 ) : 0,
			low: allowance > 0 && remaining / allowance <= 0.2,
			renews: managed.free_renews_at ? new Date( managed.free_renews_at ).toLocaleDateString() : null,
			purchased: managed.purchased_remaining || 0,
		};
	}

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
						{ activeKey === 'managed' ? (
							<div className="raif-managed">
								{ managedError && (
									<Notice status="error" isDismissible onRemove={ () => setManagedError( null ) }>
										{ managedError }
									</Notice>
								) }
								{ managed && managed.connected ? (
									<>
										<div className={ `raif-quota${ quota.low ? ' raif-quota--low' : '' }` }>
											<p className="raif-quota__label">
												{ sprintf(
													/* translators: 1: remaining count, 2: monthly allowance. */
													__( '%1$d of %2$d free generations left', 'rapid-ai-forms' ),
													quota.remaining,
													quota.allowance
												) }
												{ quota.renews &&
													' — ' +
														sprintf(
															/* translators: %s: renewal date. */
															__( 'renews %s', 'rapid-ai-forms' ),
															quota.renews
														) }
											</p>
											<div className="raif-quota__bar">
												<div
													className="raif-quota__fill"
													style={ { width: `${ quota.pct }%` } }
												/>
											</div>
											{ quota.purchased > 0 && (
												<p className="raif-quota__purchased">
													{ sprintf(
														/* translators: %d: number of additional (purchased) generations. */
														__( '+ %d additional generations', 'rapid-ai-forms' ),
														quota.purchased
													) }
												</p>
											) }
										</div>
										<div className="raif-managed__actions">
											<Button
												variant="secondary"
												onClick={ refreshManaged }
												isBusy={ managedBusy }
												disabled={ managedBusy }
											>
												{ __( 'Refresh', 'rapid-ai-forms' ) }
											</Button>
											<Button
												variant="tertiary"
												isDestructive
												onClick={ disconnect }
												disabled={ managedBusy }
											>
												{ __( 'Disconnect', 'rapid-ai-forms' ) }
											</Button>
											{ managed.manage_url && (
												<Button
													variant="link"
													href={ managed.manage_url }
													target="_blank"
													rel="noreferrer"
												>
													{ __( 'Manage account ↗', 'rapid-ai-forms' ) }
												</Button>
											) }
										</div>
									</>
								) : (
									<>
										<p>
											{ __(
												'Connect this site to Rapid AI Cloud to generate forms with a free monthly quota — no API key required.',
												'rapid-ai-forms'
											) }
										</p>
										<Button
											variant="primary"
											onClick={ connect }
											isBusy={ managedBusy }
											disabled={ managedBusy }
										>
											{ __( 'Connect', 'rapid-ai-forms' ) }
										</Button>
									</>
								) }
							</div>
						) : activeKey === 'wp_ai_client' ? (
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
						{ activeKey !== 'managed' && (
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
						) }
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
