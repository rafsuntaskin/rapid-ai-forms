import { useEffect, useMemo, useRef } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Flex,
	FlexItem,
	TextareaControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

/**
 * Per-form custom CSS editor with a real-theme iframe preview.
 *
 * The iframe loads /?rapid_ai_form_preview={id} (admin-only route) so the
 * form renders with the active theme's CSS. CSS edits are injected into the
 * iframe's scoped <style> element after a short debounce — no reload needed;
 * a full reload happens when the form is saved.
 */
export default function StylingPanel( { form, css, onChange, savedAt } ) {
	const iframeRef = useRef( null );

	const previewSrc = useMemo( () => {
		const base = ( window.RAPID_AI_FORMS_ADMIN || {} ).previewUrl;
		if ( ! base ) return '';
		const url = new URL( base );
		url.searchParams.set( 'rapid_ai_form_preview', form.id );
		return url.toString();
	}, [ form.id ] );

	// Live-inject CSS edits into the iframe's scoped style block (same
	// origin, so we can reach into its document).
	useEffect( () => {
		const t = setTimeout( () => {
			const doc =
				iframeRef.current && iframeRef.current.contentDocument;
			if ( ! doc ) return;
			const id = `raif-css-${ form.uuid }`;
			let style = doc.getElementById( id );
			if ( ! style ) {
				style = doc.createElement( 'style' );
				style.id = id;
				doc.head.appendChild( style );
			}
			style.textContent = `.raif-form[data-form-uuid="${ form.uuid }"] {\n${ css }\n}`;
		}, 600 );
		return () => clearTimeout( t );
	}, [ css, form.uuid ] );

	// A successful save may have changed more than CSS (fields, labels…) —
	// reload so the preview reflects the persisted form.
	useEffect( () => {
		if ( ! savedAt ) return;
		reload();
	}, [ savedAt ] );

	const reload = () => {
		const frame = iframeRef.current;
		if ( frame && frame.contentWindow ) {
			frame.contentWindow.location.reload();
		}
	};

	return (
		<Card className="raif-mt raif-styling">
			<CardHeader>
				<Flex>
					<FlexItem>
						<strong>{ __( 'Styling', 'rapid-ai-forms' ) }</strong>
					</FlexItem>
					<FlexItem>
						<Button variant="tertiary" size="small" onClick={ reload }>
							{ __( 'Refresh preview', 'rapid-ai-forms' ) }
						</Button>
					</FlexItem>
				</Flex>
			</CardHeader>
			<CardBody>
				<div className="raif-styling__columns">
					<div className="raif-styling__editor">
						<TextareaControl
							className="raif-css-editor"
							label={ __( 'Custom CSS', 'rapid-ai-forms' ) }
							help={ __(
								'Applies to this form only. Selectors are scoped automatically — write rules against .raif-field, label, input, etc. Save to apply on the live site.',
								'rapid-ai-forms'
							) }
							rows={ 14 }
							value={ css }
							onChange={ onChange }
						/>
					</div>
					<div className="raif-styling__preview">
						{ previewSrc ? (
							<iframe
								ref={ iframeRef }
								src={ previewSrc }
								title={ __(
									'Form preview with theme styles',
									'rapid-ai-forms'
								) }
							/>
						) : null }
						<p className="raif-styling__hint">
							{ __(
								'Rendered with your active theme — this is what visitors see.',
								'rapid-ai-forms'
							) }
						</p>
					</div>
				</div>
			</CardBody>
		</Card>
	);
}
