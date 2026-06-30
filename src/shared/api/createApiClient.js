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

	// Returns { data, headers } so callers can read response headers
	// (e.g. X-WP-Total for paginated collections).
	const requestWithHeaders = async ( path, options = {} ) => {
		const response = await apiFetch( {
			url: `${ trimmed }/${ path.replace( /^\//, '' ) }`,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': nonce,
				...( options.headers || {} ),
			},
			parse: false,
			...options,
		} );
		const data = await response.json();
		return { data, headers: response.headers };
	};

	// Raw Response (unparsed) — for non-JSON endpoints like file downloads.
	const getResponse = ( path, options = {} ) =>
		apiFetch( {
			url: `${ trimmed }/${ path.replace( /^\//, '' ) }`,
			headers: { 'X-WP-Nonce': nonce, ...( options.headers || {} ) },
			parse: false,
			method: 'GET',
			...options,
		} );

	return {
		get: ( path ) => request( path, { method: 'GET' } ),
		getWithHeaders: ( path ) => requestWithHeaders( path, { method: 'GET' } ),
		getResponse,
		post: ( path, data ) => request( path, { method: 'POST', data } ),
		put: ( path, data ) => request( path, { method: 'PUT', data } ),
		del: ( path ) => request( path, { method: 'DELETE' } ),
	};
}
