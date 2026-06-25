/**
 * Rapid AI Form block — pick a form, preview it server-rendered.
 *
 * Dynamic block: `save` returns null and the PHP render_callback emits the
 * markup (reusing Form_Renderer), so the editor preview and the front end
 * stay identical.
 */
import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl, Placeholder, Spinner } from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import ServerSideRender from '@wordpress/server-side-render';
import metadata from '../../blocks/form/block.json';

function Edit( { attributes, setAttributes } ) {
	const { formId } = attributes;
	const blockProps = useBlockProps();
	const [ forms, setForms ] = useState( null );

	useEffect( () => {
		apiFetch( { path: 'rapid-ai-forms/v1/forms-list' } )
			.then( ( res ) => setForms( Array.isArray( res ) ? res : [] ) )
			.catch( () => setForms( [] ) );
	}, [] );

	const options = [
		{ label: __( '— Select a form —', 'rapid-ai-forms' ), value: 0 },
		...( forms || [] ).map( ( f ) => ( {
			label: f.title || `#${ f.id }`,
			value: f.id,
		} ) ),
	];

	const selector =
		forms === null ? (
			<Spinner />
		) : (
			<SelectControl
				label={ __( 'Form', 'rapid-ai-forms' ) }
				value={ formId }
				options={ options }
				onChange={ ( value ) => setAttributes( { formId: parseInt( value, 10 ) || 0 } ) }
				__nextHasNoMarginBottom
			/>
		);

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody title={ __( 'Form settings', 'rapid-ai-forms' ) }>{ selector }</PanelBody>
			</InspectorControls>

			{ ! formId ? (
				<Placeholder
					icon="feedback"
					label={ __( 'Rapid AI Form', 'rapid-ai-forms' ) }
					instructions={ __( 'Choose which form to display.', 'rapid-ai-forms' ) }
				>
					{ selector }
				</Placeholder>
			) : (
				<ServerSideRender block={ metadata.name } attributes={ { formId } } />
			) }
		</div>
	);
}

registerBlockType( metadata.name, {
	...metadata,
	edit: Edit,
	save: () => null,
} );
