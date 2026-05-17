import { useEffect, useState } from '@wordpress/element';
import { Button, Card, CardBody, Spinner, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import PageHeader from '../../shared/components/PageHeader';

export default function FormsList( { api } ) {
	const [ forms, setForms ] = useState( null );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		api.get( 'forms' )
			.then( setForms )
			.catch( ( e ) => setError( e.message || 'Failed to load forms' ) );
	}, [ api ] );

	const createBlank = async () => {
		const form = await api.post( 'forms', {
			title: __( 'Untitled form', 'wp-ai-forms' ),
			status: 'draft',
			schema: { fields: [], submit_label: 'Submit' },
		} );
		window.location.hash = `#/forms/${ form.id }`;
	};

	return (
		<div className="wpaif-page">
			<PageHeader
				title={ __( 'Forms', 'wp-ai-forms' ) }
				description={ __( 'Create AI-generated forms and embed them with shortcodes.', 'wp-ai-forms' ) }
				actions={
					<Button variant="primary" onClick={ createBlank }>
						{ __( 'New form', 'wp-ai-forms' ) }
					</Button>
				}
			/>

			{ error && <Notice status="error" isDismissible={ false }>{ error }</Notice> }
			{ forms === null && ! error && <Spinner /> }

			{ forms && forms.length === 0 && (
				<Card>
					<CardBody>{ __( 'No forms yet. Create your first AI-powered form.', 'wp-ai-forms' ) }</CardBody>
				</Card>
			) }

			{ forms && forms.length > 0 && (
				<table className="wp-list-table widefat striped">
					<thead>
						<tr>
							<th>{ __( 'Title', 'wp-ai-forms' ) }</th>
							<th>{ __( 'Status', 'wp-ai-forms' ) }</th>
							<th>{ __( 'Shortcode', 'wp-ai-forms' ) }</th>
							<th>{ __( 'Updated', 'wp-ai-forms' ) }</th>
							<th />
						</tr>
					</thead>
					<tbody>
						{ forms.map( ( f ) => (
							<tr key={ f.id }>
								<td>
									<a href={ `#/forms/${ f.id }` }>{ f.title || `(#${ f.id })` }</a>
								</td>
								<td>{ f.status }</td>
								<td>
									<code>{ `[wp_ai_form id="${ f.id }"]` }</code>
								</td>
								<td>{ f.updated_at }</td>
								<td>
									<Button variant="link" href={ `#/forms/${ f.id }` }>
										{ __( 'Edit', 'wp-ai-forms' ) }
									</Button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }
		</div>
	);
}
