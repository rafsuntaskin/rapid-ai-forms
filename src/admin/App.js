import { useEffect, useMemo, useState } from '@wordpress/element';
import { createApiClient } from '../shared/api/createApiClient';
import FormsList from './pages/FormsList';
import FormEditor from './pages/FormEditor';
import Settings from './pages/Settings';
import Submissions from './pages/Submissions';

export default function App() {
	const config = window.RAPID_AI_FORMS_ADMIN || {};
	const api = useMemo(
		() => createApiClient( { restUrl: config.restUrl, nonce: config.nonce } ),
		[ config.restUrl, config.nonce ]
	);

	// Hash-based routing for sub-views within the Forms page (e.g. #/forms/42).
	const [ route, setRoute ] = useState( window.location.hash || '#/' );
	useEffect( () => {
		const handler = () => setRoute( window.location.hash || '#/' );
		window.addEventListener( 'hashchange', handler );
		return () => window.removeEventListener( 'hashchange', handler );
	}, [] );

	// The WP admin page slug determines top-level view.
	if ( config.page === 'rapid-ai-forms-settings' ) {
		return <Settings api={ api } />;
	}
	if ( config.page === 'rapid-ai-forms-submissions' ) {
		return <Submissions api={ api } />;
	}

	const editMatch = route.match( /^#\/forms\/(\d+)/ );
	if ( editMatch ) {
		return <FormEditor api={ api } formId={ parseInt( editMatch[ 1 ], 10 ) } />;
	}

	return <FormsList api={ api } />;
}
