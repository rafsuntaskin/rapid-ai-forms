import { useEffect, useMemo, useState } from '@wordpress/element';
import { TabPanel } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { createApiClient } from '../shared/api/createApiClient';
import FormsList from './pages/FormsList';
import FormEditor from './pages/FormEditor';
import Settings from './pages/Settings';

export default function App() {
	const config = window.WP_AI_FORMS_ADMIN || {};
	const api = useMemo(
		() => createApiClient( { restUrl: config.restUrl, nonce: config.nonce } ),
		[ config.restUrl, config.nonce ]
	);

	// Hash-based routing: #/forms/:id, #/settings, #/ (list).
	const [ route, setRoute ] = useState( window.location.hash || '#/' );

	useEffect( () => {
		const handler = () => setRoute( window.location.hash || '#/' );
		window.addEventListener( 'hashchange', handler );
		return () => window.removeEventListener( 'hashchange', handler );
	}, [] );

	const editMatch = route.match( /^#\/forms\/(\d+)/ );
	if ( editMatch ) {
		return <FormEditor api={ api } formId={ parseInt( editMatch[ 1 ], 10 ) } />;
	}

	return (
		<TabPanel
			className="wpaif-tabs"
			activeClass="is-active"
			tabs={ [
				{ name: 'forms', title: __( 'Forms', 'wp-ai-forms' ) },
				{ name: 'settings', title: __( 'Settings', 'wp-ai-forms' ) },
			] }
			initialTabName={ route.startsWith( '#/settings' ) ? 'settings' : 'forms' }
		>
			{ ( tab ) =>
				tab.name === 'settings' ? <Settings api={ api } /> : <FormsList api={ api } />
			}
		</TabPanel>
	);
}
