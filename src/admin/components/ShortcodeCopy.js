import { useState, useRef, useEffect } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Pill-shaped shortcode chip with a click-to-copy affordance.
 */
export default function ShortcodeCopy( { shortcode, label } ) {
	const [ copied, setCopied ] = useState( false );
	const inputRef = useRef( null );
	const timerRef = useRef( null );

	useEffect( () => () => clearTimeout( timerRef.current ), [] );

	const copy = async () => {
		try {
			if ( navigator.clipboard && window.isSecureContext ) {
				await navigator.clipboard.writeText( shortcode );
			} else if ( inputRef.current ) {
				inputRef.current.select();
				document.execCommand( 'copy' );
			}
			setCopied( true );
			clearTimeout( timerRef.current );
			timerRef.current = setTimeout( () => setCopied( false ), 1500 );
		} catch ( e ) {
			// Fall back to selecting so the user can ctrl-c manually.
			if ( inputRef.current ) {
				inputRef.current.select();
			}
		}
	};

	return (
		<div className="wpaif-shortcode" role="group" aria-label={ label || __( 'Embed shortcode', 'wp-ai-forms' ) }>
			{ label && <span className="wpaif-shortcode__label">{ label }</span> }
			<input
				ref={ inputRef }
				type="text"
				className="wpaif-shortcode__input"
				value={ shortcode }
				readOnly
				onFocus={ ( e ) => e.target.select() }
				aria-label={ __( 'Shortcode', 'wp-ai-forms' ) }
			/>
			<Button
				variant="secondary"
				size="small"
				onClick={ copy }
				className="wpaif-shortcode__btn"
			>
				{ copied ? __( '✓ Copied', 'wp-ai-forms' ) : __( 'Copy', 'wp-ai-forms' ) }
			</Button>
		</div>
	);
}
