import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Flex,
	FlexItem,
	Notice,
	TextareaControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useAsync } from '../../shared/hooks/useAsync';

/**
 * Per-form custom CSS editor with a real-theme iframe preview.
 *
 * The iframe loads /?rapid_ai_form_preview={id} (admin-only route) so the
 * form renders with the active theme's CSS. CSS edits are injected into the
 * iframe's scoped <style> element after a short debounce — no reload needed;
 * a full reload happens when the form is saved.
 */
export default function StylingPanel( {
	api,
	form,
	css,
	onChange,
	savedAt,
	aiConfigured,
} ) {
	const iframeRef = useRef( null );
	const [ prompt, setPrompt ] = useState( '' );

	const style = useAsync( ( p ) =>
		api.post( 'ai/style', {
			form_id: form.id,
			prompt: p,
			current_css: css,
		} )
	);

	const onApply = async () => {
		const res = await style.run( prompt );
		if ( res && typeof res.css === 'string' ) {
			onChange( res.css );
			setPrompt( '' );
		}
	};

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
							label={ __( 'Describe the look you want', 'rapid-ai-forms' ) }
							help={ __(
								'Example: "Full-width inputs, the theme accent color on focus, and 8px rounded corners." The AI edits the CSS below — review and Save to keep it.',
								'rapid-ai-forms'
							) }
							rows={ 3 }
							value={ prompt }
							onChange={ setPrompt }
							disabled={ aiConfigured === false }
						/>
						<Flex justify="flex-start" gap={ 2 } className="raif-mt-sm">
							<FlexItem>
								<Button
									variant="secondary"
									onClick={ onApply }
									isBusy={ style.loading }
									disabled={
										! prompt.trim() ||
										style.loading ||
										aiConfigured === false
									}
								>
									{ __( 'Apply with AI', 'rapid-ai-forms' ) }
								</Button>
							</FlexItem>
						</Flex>
						{ style.error && (
							<Notice
								status="error"
								isDismissible={ false }
								className="raif-mt-sm"
							>
								{ style.error.message }
							</Notice>
						) }
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
