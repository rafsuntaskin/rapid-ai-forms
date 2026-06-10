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

/**
 * Rapid AI Cloud connection card. No credentials to type — a Connect click
 * runs the domain-verification handshake; the quota meter states facts only
 * (wp.org: no purchase language in the plugin).
 */
function ManagedConnect( { api, connected, onConnectionChange } ) {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( null );
	const [ status, setStatus ] = useState( null );

	useEffect( () => {
		if ( ! connected ) {
			setStatus( null );
			return;
		}
		let cancelled = false;
		api.get( 'managed/status' )
			.then( ( s ) => ! cancelled && setStatus( s ) )
			.catch( ( e ) => ! cancelled && setError( e.message ) );
		return () => {
			cancelled = true;
		};
	}, [ api, connected ] );

	const run = async ( path ) => {
		setBusy( true );
		setError( null );
		try {
			await api.post( path, {} );
			await onConnectionChange();
		} catch ( e ) {
			setError( e.message || __( 'Something went wrong. Please try again.', 'rapid-ai-forms' ) );
		} finally {
			setBusy( false );
		}
	};

	if ( ! connected ) {
		return (
			<>
				<p>
					{ __(
						'Connect this site to Rapid AI Cloud to use AI form generation without an API key. Free monthly usage included — no account needed.',
						'rapid-ai-forms'
					) }
				</p>
				<Button variant="primary" isBusy={ busy } disabled={ busy } onClick={ () => run( 'managed/register' ) }>
					{ __( 'Connect', 'rapid-ai-forms' ) }
				</Button>
				{ error && (
					<Notice status="error" isDismissible={ false } className="raif-mt-sm">
						{ error }
					</Notice>
				) }
			</>
		);
	}

	const free = status && typeof status.free_remaining === 'number' ? status.free_remaining : null;
	const allowance = status && typeof status.free_allowance === 'number' ? status.free_allowance : null;
	const purchased = status && typeof status.purchased_remaining === 'number' ? status.purchased_remaining : 0;
	const renews = status && status.free_renews_at ? new Date( status.free_renews_at ).toLocaleDateString() : null;
	const pct = free !== null && allowance ? Math.max( 0, Math.min( 100, ( free / allowance ) * 100 ) ) : null;

	return (
		<>
			<Notice status="success" isDismissible={ false }>
				{ __( 'Connected to Rapid AI Cloud.', 'rapid-ai-forms' ) }
			</Notice>
			{ status === null && ! error && <Spinner /> }
			{ free !== null && (
				<div className={ `raif-quota${ pct !== null && pct <= 20 ? ' raif-quota--low' : '' }` }>
					<p className="raif-quota__label">
						{ allowance
							? sprintf(
									/* translators: 1: generations remaining, 2: monthly allowance */
									__( '%1$d of %2$d free generations left this month', 'rapid-ai-forms' ),
									free,
									allowance
							  )
							: sprintf(
									/* translators: %d: generations remaining */
									__( '%d free generations left this month', 'rapid-ai-forms' ),
									free
							  ) }
						{ renews &&
							' — ' +
								sprintf(
									/* translators: %s: renewal date */
									__( 'renews %s', 'rapid-ai-forms' ),
									renews
								) }
					</p>
					{ pct !== null && (
						<div className="raif-quota__bar">
							<span style={ { width: `${ pct }%` } } />
						</div>
					) }
					{ purchased > 0 && (
						<p className="raif-quota__purchased">
							{ sprintf(
								/* translators: %d: additional generations available */
								__( '+%d additional generations available', 'rapid-ai-forms' ),
								purchased
							) }
						</p>
					) }
				</div>
			) }
			{ error && (
				<Notice status="error" isDismissible onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }
			<div className="raif-managed-actions">
				<Button
					variant="secondary"
					isBusy={ busy }
					onClick={ () => {
						setStatus( null );
						api.get( 'managed/status' ).then( setStatus ).catch( ( e ) => setError( e.message ) );
					} }
				>
					{ __( 'Refresh', 'rapid-ai-forms' ) }
				</Button>
				{ status && status.manage_url && (
					<Button variant="tertiary" href={ status.manage_url } target="_blank" rel="noreferrer">
						{ __( 'Manage account ↗', 'rapid-ai-forms' ) }
					</Button>
				) }
				<Button variant="tertiary" isDestructive isBusy={ busy } onClick={ () => run( 'managed/disconnect' ) }>
					{ __( 'Disconnect', 'rapid-ai-forms' ) }
				</Button>
			</div>
		</>
	);
}

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
						{ activeKey === 'managed' ? (
							<ManagedConnect
								api={ api }
								connected={ !! activeCfg.connected }
								onConnectionChange={ () => api.get( 'settings' ).then( setSettings ) }
							/>
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
						<div className="raif-verify">
							<Button
								variant="secondary"
								onClick={ verify }
								isBusy={ verifying }
								disabled={
									verifying ||
									( activeKey === 'managed'
										? ! activeCfg.connected
										: activeKey !== 'wp_ai_client' &&
										  ! activeCfg.api_key &&
										  ! activeCfg.api_key_set )
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
