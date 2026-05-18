import { createElement as h } from '@wordpress/element';

export default function PageHeader( { title, description, actions } ) {
	return h(
		'div',
		{ className: 'wpaif-page-header' },
		h( 'div', null,
			h( 'h1', null, title ),
			description ? h( 'div', { className: 'wpaif-page-header__desc' }, description ) : null
		),
		actions ? h( 'div', { className: 'wpaif-page-header__actions' }, actions ) : null
	);
}
