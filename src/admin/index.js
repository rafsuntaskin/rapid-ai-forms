import { createRoot, StrictMode } from '@wordpress/element';
import App from './App';
import './admin.scss';

const mount = () => {
	const root = document.getElementById( 'wp-ai-forms-admin-root' );
	if ( ! root ) {
		return;
	}
	createRoot( root ).render(
		<StrictMode>
			<App />
		</StrictMode>
	);
};

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', mount );
} else {
	mount();
}
