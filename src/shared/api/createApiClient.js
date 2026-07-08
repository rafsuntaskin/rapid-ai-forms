/**
 * Portable REST client factory. Wraps @wordpress/api-fetch with a base URL and nonce.
 * No plugin-specific globals — pass restUrl and nonce at creation time.
 */
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';

export function createApiClient( { restUrl, nonce } ) {
	const trimmed = restUrl.replace( /\/$/, '' );

	// Build a full REST URL from a `path` that may carry its own query string
	// (e.g. "submissions?form_id=6"). Merge the query via addQueryArgs so it
	// works with BOTH REST roots: pretty permalinks ("…/v1/submissions?form_id=6")
	// and plain permalinks ("…/?rest_route=/…/v1/submissions&form_id=6"). Naive
	// string concatenation breaks the latter — the second "?" collides with the
	// rest_route query arg and the request 404s.
	const buildUrl = ( path ) => {
		const [ pathPart, queryString = '' ] = path
			.replace( /^\//, '' )
			.split( '?' );
		const base = `${ trimmed }/${ pathPart }`;
		if ( ! queryString ) {
			return base;
		}
		return addQueryArgs(
			base,
			Object.fromEntries( new URLSearchParams( queryString ) )
		);
	};

	const request = ( path, options = {} ) =>
		apiFetch( {
			url: buildUrl( path ),
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
			url: buildUrl( path ),
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
			url: buildUrl( path ),
			headers: { 'X-WP-Nonce': nonce, ...( options.headers || {} ) },
			parse: false,
			method: 'GET',
			...options,
		} );

	return {
		get: ( path ) => request( path, { method: 'GET' } ),
		getWithHeaders: ( path ) =>
			requestWithHeaders( path, { method: 'GET' } ),
		getResponse,
		post: ( path, data ) => request( path, { method: 'POST', data } ),
		put: ( path, data ) => request( path, { method: 'PUT', data } ),
		del: ( path ) => request( path, { method: 'DELETE' } ),
	};
}
