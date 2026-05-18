import { useEffect, useMemo, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	Flex,
	Modal,
	Notice,
	Spinner,
	TextControl,
} from '@wordpress/components';
import { __, sprintf, _n } from '@wordpress/i18n';
import PageHeader from '../../shared/components/PageHeader';
import ShortcodeCopy from '../components/ShortcodeCopy';

function formatRelative( iso ) {
	if ( ! iso ) return '';
	const d = new Date( iso.replace( ' ', 'T' ) + 'Z' );
	const diff = ( Date.now() - d.getTime() ) / 1000;
	if ( diff < 60 ) return __( 'just now', 'wp-ai-forms' );
	if ( diff < 3600 ) return sprintf( _n( '%d minute ago', '%d minutes ago', Math.floor( diff / 60 ), 'wp-ai-forms' ), Math.floor( diff / 60 ) );
	if ( diff < 86400 ) return sprintf( _n( '%d hour ago', '%d hours ago', Math.floor( diff / 3600 ), 'wp-ai-forms' ), Math.floor( diff / 3600 ) );
	if ( diff < 604800 ) return sprintf( _n( '%d day ago', '%d days ago', Math.floor( diff / 86400 ), 'wp-ai-forms' ), Math.floor( diff / 86400 ) );
	return d.toLocaleDateString();
}

function fieldCount( form ) {
	return Array.isArray( form.schema && form.schema.fields ) ? form.schema.fields.length : 0;
}

export default function FormsList( { api } ) {
	const [ forms, setForms ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ creating, setCreating ] = useState( false );
	const [ search, setSearch ] = useState( '' );
	const [ deleting, setDeleting ] = useState( null );

	useEffect( () => {
		api.get( 'forms' )
			.then( setForms )
			.catch( ( e ) => setError( e.message || 'Failed to load forms' ) );
	}, [ api ] );

	const filtered = useMemo( () => {
		if ( ! forms ) return [];
		const q = search.trim().toLowerCase();
		if ( ! q ) return forms;
		return forms.filter( ( f ) => {
			const hay = `${ f.title || '' } ${ f.uuid || '' } ${ f.id }`.toLowerCase();
			return hay.includes( q );
		} );
	}, [ forms, search ] );

	const createBlank = async () => {
		setError( null );
		setCreating( true );
		try {
			const form = await api.post( 'forms', {
				title: __( 'Untitled form', 'wp-ai-forms' ),
				status: 'draft',
				schema: { fields: [], submit_label: 'Submit' },
			} );
			if ( ! form || ! form.id ) {
				throw new Error( __( 'Form was created but no id was returned.', 'wp-ai-forms' ) );
			}
			setForms( ( prev ) => ( prev ? [ form, ...prev ] : [ form ] ) );
			window.location.hash = `#/forms/${ form.id }`;
		} catch ( e ) {
			setError( e.message || __( 'Could not create form.', 'wp-ai-forms' ) );
		} finally {
			setCreating( false );
		}
	};

	const confirmDelete = async () => {
		const target = deleting;
		setDeleting( null );
		if ( ! target ) return;
		try {
			await api.del( `forms/${ target.id }` );
			setForms( ( prev ) => prev.filter( ( f ) => f.id !== target.id ) );
		} catch ( e ) {
			setError( e.message || __( 'Could not delete form.', 'wp-ai-forms' ) );
		}
	};

	return (
		<div className="wpaif-page wpaif-list">
			<PageHeader
				title={ __( 'Forms', 'wp-ai-forms' ) }
				description={ __( 'Create AI-generated forms and embed them anywhere with a shortcode.', 'wp-ai-forms' ) }
				actions={
					<Button variant="primary" onClick={ createBlank } isBusy={ creating } disabled={ creating }>
						{ __( '+ New form', 'wp-ai-forms' ) }
					</Button>
				}
			/>

			{ error && (
				<Notice status="error" isDismissible onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			{ forms !== null && forms.length > 0 && (
				<div className="wpaif-list__toolbar">
					<TextControl
						label={ __( 'Search forms', 'wp-ai-forms' ) }
						hideLabelFromVision
						placeholder={ __( 'Search by title, id, or uuid…', 'wp-ai-forms' ) }
						value={ search }
						onChange={ setSearch }
					/>
					<span className="wpaif-list__count">
						{ sprintf(
							_n( '%d form', '%d forms', filtered.length, 'wp-ai-forms' ),
							filtered.length
						) }
					</span>
				</div>
			) }

			{ forms === null && ! error && (
				<div className="wpaif-list__loading"><Spinner /></div>
			) }

			{ forms && forms.length === 0 && (
				<Card className="wpaif-list__empty">
					<CardBody>
						<h2>{ __( 'No forms yet', 'wp-ai-forms' ) }</h2>
						<p>{ __( 'Create your first AI-powered form. Describe it in plain language and the editor will build the fields for you.', 'wp-ai-forms' ) }</p>
						<Button variant="primary" onClick={ createBlank } isBusy={ creating }>
							{ __( 'Create your first form', 'wp-ai-forms' ) }
						</Button>
					</CardBody>
				</Card>
			) }

			{ forms && forms.length > 0 && filtered.length === 0 && (
				<Card><CardBody>
					{ __( 'No forms match your search.', 'wp-ai-forms' ) }
				</CardBody></Card>
			) }

			{ filtered.length > 0 && (
				<div className="wpaif-list__grid">
					{ filtered.map( ( f ) => (
						<article key={ f.id } className="wpaif-card">
							<header className="wpaif-card__head">
								<a href={ `#/forms/${ f.id }` } className="wpaif-card__title">
									{ f.title || sprintf( __( 'Untitled form #%d', 'wp-ai-forms' ), f.id ) }
								</a>
								<span className={ `wpaif-status wpaif-status--${ f.status }` }>{ f.status }</span>
							</header>
							<dl className="wpaif-card__meta">
								<div>
									<dt>{ __( 'Fields', 'wp-ai-forms' ) }</dt>
									<dd>{ fieldCount( f ) }</dd>
								</div>
								<div>
									<dt>{ __( 'Updated', 'wp-ai-forms' ) }</dt>
									<dd title={ f.updated_at }>{ formatRelative( f.updated_at ) }</dd>
								</div>
							</dl>
							<ShortcodeCopy shortcode={ `[wp_ai_form id="${ f.id }"]` } />
							<footer className="wpaif-card__actions">
								<Button variant="primary" href={ `#/forms/${ f.id }` }>
									{ __( 'Edit', 'wp-ai-forms' ) }
								</Button>
								<Button
									variant="tertiary"
									isDestructive
									onClick={ () => setDeleting( f ) }
								>
									{ __( 'Delete', 'wp-ai-forms' ) }
								</Button>
							</footer>
						</article>
					) ) }
				</div>
			) }

			{ deleting && (
				<Modal
					title={ __( 'Delete form?', 'wp-ai-forms' ) }
					onRequestClose={ () => setDeleting( null ) }
					className="wpaif-delete-modal"
				>
					<p>
						{ sprintf(
							__( 'Delete "%s"? Submissions are kept but the form will stop working anywhere it is embedded. This cannot be undone.', 'wp-ai-forms' ),
							deleting.title || `#${ deleting.id }`
						) }
					</p>
					<Flex justify="flex-end" gap={ 2 }>
						<Button variant="tertiary" onClick={ () => setDeleting( null ) }>
							{ __( 'Cancel', 'wp-ai-forms' ) }
						</Button>
						<Button variant="primary" isDestructive onClick={ confirmDelete }>
							{ __( 'Delete form', 'wp-ai-forms' ) }
						</Button>
					</Flex>
				</Modal>
			) }
		</div>
	);
}
