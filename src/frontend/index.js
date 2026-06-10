/**
 * Frontend submission handler.
 *
 * Uses no React on the frontend output (PHP renders the form), but reuses
 * the shared API client for AJAX submission. If you want React-rendered
 * frontend forms later, swap the PHP renderer for a mount point here.
 */
import { createApiClient } from '../shared/api/createApiClient';
import './frontend.scss';

const config = window.RAPID_AI_FORMS || {};
const api = createApiClient( { restUrl: config.restUrl, nonce: config.nonce } );

const clearFieldErrors = ( form ) => {
	form.querySelectorAll( '.raif-field-error' ).forEach( ( el ) => el.remove() );
};

const showFieldErrors = ( form, fields ) => {
	Object.entries( fields ).forEach( ( [ name, msg ] ) => {
		const input = form.querySelector(
			`[name="${ name }"], [name="${ name }[]"]`
		);
		const wrapper = input && input.closest( '.raif-field' );
		if ( ! wrapper ) return;
		const error = document.createElement( 'span' );
		error.className = 'raif-field-error';
		error.textContent = msg;
		wrapper.appendChild( error );
	} );
};

const onSubmit = async ( event ) => {
	event.preventDefault();
	const form = event.currentTarget;
	const uuid = form.dataset.formUuid;
	const message = form.querySelector( '.raif-form__message' );
	const submit = form.querySelector( '.raif-form__submit' );

	const data = {};
	new FormData( form ).forEach( ( value, rawKey ) => {
		// `name[]` inputs (checkbox_group) → always treat as array, strip the suffix.
		const isArray = rawKey.endsWith( '[]' );
		const key = isArray ? rawKey.slice( 0, -2 ) : rawKey;
		if ( isArray || key in data ) {
			data[ key ] = [].concat( data[ key ] || [], value );
		} else {
			data[ key ] = value;
		}
	} );

	submit.disabled = true;
	message.textContent = '';
	message.className = 'raif-form__message';
	clearFieldErrors( form );

	try {
		await api.post( `submissions/${ uuid }`, data );
		message.textContent = 'Thank you! Your submission was received.';
		message.classList.add( 'is-success' );
		form.reset();
	} catch ( err ) {
		message.textContent = err.message || 'Submission failed.';
		message.classList.add( 'is-error' );
		if ( err.data && err.data.fields ) {
			showFieldErrors( form, err.data.fields );
		}
	} finally {
		submit.disabled = false;
	}
};

const bind = () => {
	document.querySelectorAll( 'form.raif-form' ).forEach( ( form ) => {
		if ( form.dataset.eaifBound ) return;
		form.dataset.eaifBound = '1';
		form.addEventListener( 'submit', onSubmit );
	} );
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', bind );
} else {
	bind();
}
