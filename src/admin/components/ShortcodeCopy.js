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
		<div className="raif-shortcode" role="group" aria-label={ label || __( 'Embed shortcode', 'rapid-ai-forms' ) }>
			{ label && <span className="raif-shortcode__label">{ label }</span> }
			<input
				ref={ inputRef }
				type="text"
				className="raif-shortcode__input"
				value={ shortcode }
				readOnly
				onFocus={ ( e ) => e.target.select() }
				aria-label={ __( 'Shortcode', 'rapid-ai-forms' ) }
			/>
			<Button
				variant="secondary"
				size="small"
				onClick={ copy }
				className="raif-shortcode__btn"
			>
				{ copied ? __( '✓ Copied', 'rapid-ai-forms' ) : __( 'Copy', 'rapid-ai-forms' ) }
			</Button>
		</div>
	);
}
