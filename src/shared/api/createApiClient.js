/**
 * Portable REST client factory. Wraps @wordpress/api-fetch with a base URL and nonce.
 * No plugin-specific globals — pass restUrl and nonce at creation time.
 */
import apiFetch from '@wordpress/api-fetch';

export function createApiClient( { restUrl, nonce } ) {
	const trimmed = restUrl.replace( /\/$/, '' );

	const request = ( path, options = {} ) =>
		apiFetch( {
			url: `${ trimmed }/${ path.replace( /^\//, '' ) }`,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce,
				...( options.headers || {} ),
			},
			...options,
		} );

	return {
		get: ( path ) => request( path, { method: 'GET' } ),
		post: ( path, data ) => request( path, { method: 'POST', data } ),
		put: ( path, data ) => request( path, { method: 'PUT', data } ),
		del: ( path ) => request( path, { method: 'DELETE' } ),
	};
}
