import { useEffect, useState } from '@wordpress/element';

/**
 * Tracks whether the active AI provider is ready to use.
 *
 * Returns { ready, configured, providerLabel }:
 *  - ready: settings have been fetched
 *  - configured: the active provider reports it can be used (server-side
 *    flag — true for BYOK providers when api_key is saved, true for
 *    wp_ai_client when core connectors are available)
 *  - providerLabel: human label for messaging
 */
export function useAiConfigured( api ) {
	const [ state, setState ] = useState( { ready: false, configured: false, providerLabel: '' } );

	useEffect( () => {
		let cancelled = false;
		api.get( 'settings' )
			.then( ( s ) => {
				if ( cancelled ) return;
				const key = s.active_provider;
				const cfg = ( s.providers || {} )[ key ] || {};
				const label = ( s.available_providers || [] ).find( ( p ) => p.key === key )?.label || key;
				// `configured` is the new server-side flag; fall back to api_key_set
				// for older servers running an upgraded UI bundle.
				const configured = cfg.configured !== undefined ? !! cfg.configured : !! cfg.api_key_set;
				setState( { ready: true, configured, providerLabel: label } );
			} )
			.catch( () => {
				if ( cancelled ) return;
				setState( { ready: true, configured: false, providerLabel: '' } );
			} );
		return () => {
			cancelled = true;
		};
	}, [ api ] );

	return state;
}
