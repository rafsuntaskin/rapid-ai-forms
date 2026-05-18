import { useEffect, useState } from '@wordpress/element';

/**
 * Tracks whether the active AI provider has its API key saved.
 *
 * Returns { ready, configured, providerLabel }:
 *  - ready: settings have been fetched
 *  - configured: api_key_set is true for the active provider
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
				setState( { ready: true, configured: !! cfg.api_key_set, providerLabel: label } );
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
