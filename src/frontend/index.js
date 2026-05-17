/**
 * Frontend submission handler.
 *
 * Uses no React on the frontend output (PHP renders the form), but reuses
 * the shared API client for AJAX submission. If you want React-rendered
 * frontend forms later, swap the PHP renderer for a mount point here.
 */
import { createApiClient } from '../shared/api/createApiClient';
import './frontend.scss';

const config = window.WP_AI_FORMS || {};
const api = createApiClient( { restUrl: config.restUrl, nonce: config.nonce } );

const onSubmit = async ( event ) => {
	event.preventDefault();
	const form = event.currentTarget;
	const uuid = form.dataset.formUuid;
	const message = form.querySelector( '.wpaif-form__message' );
	const submit = form.querySelector( '.wpaif-form__submit' );

	const data = {};
	new FormData( form ).forEach( ( value, key ) => {
		if ( key in data ) {
			data[ key ] = [].concat( data[ key ], value );
		} else {
			data[ key ] = value;
		}
	} );

	submit.disabled = true;
	message.textContent = '';
	message.className = 'wpaif-form__message';

	try {
		await api.post( `submissions/${ uuid }`, data );
		message.textContent = 'Thank you! Your submission was received.';
		message.classList.add( 'is-success' );
		form.reset();
	} catch ( err ) {
		message.textContent = err.message || 'Submission failed.';
		message.classList.add( 'is-error' );
	} finally {
		submit.disabled = false;
	}
};

const bind = () => {
	document.querySelectorAll( 'form.wpaif-form' ).forEach( ( form ) => {
		if ( form.dataset.wpaifBound ) return;
		form.dataset.wpaifBound = '1';
		form.addEventListener( 'submit', onSubmit );
	} );
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', bind );
} else {
	bind();
}
