import { useEffect, useState } from '@wordpress/element';
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
import { useAiConfigured } from '../hooks/useAiConfigured';

function formatRelative( iso ) {
	if ( ! iso ) return '';
	const d = new Date( iso.replace( ' ', 'T' ) + 'Z' );
	const diff = ( Date.now() - d.getTime() ) / 1000;
	if ( diff < 60 ) return __( 'just now', 'easy-ai-forms' );
	// translators: %d: number of minutes elapsed
	if ( diff < 3600 ) return sprintf( _n( '%d minute ago', '%d minutes ago', Math.floor( diff / 60 ), 'easy-ai-forms' ), Math.floor( diff / 60 ) );
	// translators: %d: number of hours elapsed
	if ( diff < 86400 ) return sprintf( _n( '%d hour ago', '%d hours ago', Math.floor( diff / 3600 ), 'easy-ai-forms' ), Math.floor( diff / 3600 ) );
	// translators: %d: number of days elapsed
	if ( diff < 604800 ) return sprintf( _n( '%d day ago', '%d days ago', Math.floor( diff / 86400 ), 'easy-ai-forms' ), Math.floor( diff / 86400 ) );
	return d.toLocaleDateString();
}

function fieldCount( form ) {
	return Array.isArray( form.schema && form.schema.fields ) ? form.schema.fields.length : 0;
}

const PER_PAGE = 20;

export default function FormsList( { api } ) {
	const [ forms, setForms ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ creating, setCreating ] = useState( false );
	const [ search, setSearch ] = useState( '' );
	const [ deleting, setDeleting ] = useState( null );
	const [ page, setPage ] = useState( 1 );
	const [ total, setTotal ] = useState( 0 );
	const [ debouncedSearch, setDebouncedSearch ] = useState( '' );
	const [ loading, setLoading ] = useState( false );
	const ai = useAiConfigured( api );
	const settingsUrl = ( window.EASY_AI_FORMS_ADMIN || {} ).settingsUrl || '';

	// Debounce keystrokes so we don't fire a REST query on every character.
	useEffect( () => {
		const t = setTimeout( () => setDebouncedSearch( search.trim() ), 250 );
		return () => clearTimeout( t );
	}, [ search ] );

	// New search query → back to page 1.
	useEffect( () => {
		setPage( 1 );
	}, [ debouncedSearch ] );

	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		const url =
			`forms?page=${ page }&per_page=${ PER_PAGE }` +
			( debouncedSearch ? `&search=${ encodeURIComponent( debouncedSearch ) }` : '' );
		api.getWithHeaders( url )
			.then( ( { data, headers } ) => {
				if ( cancelled ) return;
				setForms( data );
				const t = parseInt( headers.get( 'X-WP-Total' ) || '0', 10 );
				setTotal( Number.isFinite( t ) ? t : 0 );
			} )
			.catch( ( e ) => {
				if ( ! cancelled ) setError( e.message || 'Failed to load forms' );
			} )
			.finally( () => {
				if ( ! cancelled ) setLoading( false );
			} );
		return () => {
			cancelled = true;
		};
	}, [ api, page, debouncedSearch ] );

	const totalPages = Math.max( 1, Math.ceil( total / PER_PAGE ) );
	const visibleForms = forms || [];

	const createBlank = async () => {
		setError( null );
		setCreating( true );
		try {
			const form = await api.post( 'forms', {
				title: __( 'Untitled form', 'easy-ai-forms' ),
				schema: { fields: [], submit_label: 'Submit' },
			} );
			if ( ! form || ! form.id ) {
				throw new Error( __( 'Form was created but no id was returned.', 'easy-ai-forms' ) );
			}
			setForms( ( prev ) => ( prev ? [ form, ...prev ] : [ form ] ) );
			setTotal( ( t ) => t + 1 );
			window.location.hash = `#/forms/${ form.id }`;
		} catch ( e ) {
			setError( e.message || __( 'Could not create form.', 'easy-ai-forms' ) );
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
			setForms( ( prev ) => {
				const next = prev.filter( ( f ) => f.id !== target.id );
				// If the current page is now empty and we're past page 1, step back
				// so the user doesn't stare at "No forms" when more pages exist.
				if ( next.length === 0 && page > 1 ) {
					setPage( ( p ) => p - 1 );
				}
				return next;
			} );
			setTotal( ( t ) => Math.max( 0, t - 1 ) );
		} catch ( e ) {
			setError( e.message || __( 'Could not delete form.', 'easy-ai-forms' ) );
		}
	};

	return (
		<div className="eaif-page eaif-list">
			<PageHeader
				title={ __( 'Forms', 'easy-ai-forms' ) }
				description={ __( 'Create AI-generated forms and embed them anywhere with a shortcode.', 'easy-ai-forms' ) }
				actions={
					<Button variant="primary" onClick={ createBlank } isBusy={ creating } disabled={ creating }>
						{ __( '+ New form', 'easy-ai-forms' ) }
					</Button>
				}
			/>

			{ ai.ready && ! ai.configured && (
				<Notice status="warning" isDismissible={ false }>
					{ __( 'AI is not connected yet — set an API key in', 'easy-ai-forms' ) }{ ' ' }
					<a href={ settingsUrl }>{ __( 'AI Forms → Settings', 'easy-ai-forms' ) }</a>{ ' ' }
					{ __( 'to enable form generation. You can still build forms manually.', 'easy-ai-forms' ) }
				</Notice>
			) }

			{ error && (
				<Notice status="error" isDismissible onRemove={ () => setError( null ) }>
					{ error }
				</Notice>
			) }

			{ forms !== null && ( forms.length > 0 || debouncedSearch !== '' || search !== '' ) && (
				<div className="eaif-list__toolbar">
					<TextControl
						label={ __( 'Search forms', 'easy-ai-forms' ) }
						hideLabelFromVision
						placeholder={ __( 'Search by title, id, or uuid…', 'easy-ai-forms' ) }
						value={ search }
						onChange={ setSearch }
					/>
					<span className="eaif-list__count">
						{ debouncedSearch === ''
							? sprintf(
									// translators: %d: total number of forms on the site
									_n( '%d form', '%d forms', total, 'easy-ai-forms' ),
									total
								)
							: sprintf(
									// translators: %d: number of forms matching the search across the whole site
									_n( '%d match', '%d matches', total, 'easy-ai-forms' ),
									total
								) }
					</span>
				</div>
			) }

			{ ( forms === null || loading ) && ! error && (
				<div className="eaif-list__loading"><Spinner /></div>
			) }

			{ ! loading && forms && forms.length === 0 && debouncedSearch === '' && (
				<Card className="eaif-list__empty">
					<CardBody>
						<h2>{ __( 'No forms yet', 'easy-ai-forms' ) }</h2>
						<p>{ __( 'Create your first AI-powered form. Describe it in plain language and the editor will build the fields for you.', 'easy-ai-forms' ) }</p>
						<Button variant="primary" onClick={ createBlank } isBusy={ creating }>
							{ __( 'Create your first form', 'easy-ai-forms' ) }
						</Button>
					</CardBody>
				</Card>
			) }

			{ ! loading && forms && forms.length === 0 && debouncedSearch !== '' && (
				<Card><CardBody>
					{ __( 'No forms match your search.', 'easy-ai-forms' ) }
				</CardBody></Card>
			) }

			{ visibleForms.length > 0 && (
				<div className="eaif-list__grid">
					{ visibleForms.map( ( f ) => (
						<article key={ f.id } className="eaif-card">
							<header className="eaif-card__head">
								<a href={ `#/forms/${ f.id }` } className="eaif-card__title">
									{ f.title || sprintf(
										// translators: %d: form id number used as a placeholder title
										__( 'Untitled form #%d', 'easy-ai-forms' ),
										f.id
									) }
								</a>
							</header>
							<dl className="eaif-card__meta">
								<div>
									<dt>{ __( 'Fields', 'easy-ai-forms' ) }</dt>
									<dd>{ fieldCount( f ) }</dd>
								</div>
								<div>
									<dt>{ __( 'Updated', 'easy-ai-forms' ) }</dt>
									<dd title={ f.updated_at }>{ formatRelative( f.updated_at ) }</dd>
								</div>
							</dl>
							<ShortcodeCopy shortcode={ `[easy_ai_form id="${ f.id }"]` } />
							<footer className="eaif-card__actions">
								<Button variant="primary" href={ `#/forms/${ f.id }` }>
									{ __( 'Edit', 'easy-ai-forms' ) }
								</Button>
								<Button
									variant="tertiary"
									isDestructive
									onClick={ () => setDeleting( f ) }
								>
									{ __( 'Delete', 'easy-ai-forms' ) }
								</Button>
							</footer>
						</article>
					) ) }
				</div>
			) }

			{ totalPages > 1 && (
				<nav className="eaif-list__pagination" aria-label={ __( 'Forms pagination', 'easy-ai-forms' ) }>
					<Button
						variant="secondary"
						disabled={ page <= 1 || forms === null }
						onClick={ () => setPage( ( p ) => Math.max( 1, p - 1 ) ) }
					>
						{ __( '← Previous', 'easy-ai-forms' ) }
					</Button>
					<span className="eaif-list__pagination-status">
						{ sprintf(
							// translators: 1: current page number, 2: total number of pages
							__( 'Page %1$d of %2$d', 'easy-ai-forms' ),
							page,
							totalPages
						) }
					</span>
					<Button
						variant="secondary"
						disabled={ page >= totalPages || forms === null }
						onClick={ () => setPage( ( p ) => Math.min( totalPages, p + 1 ) ) }
					>
						{ __( 'Next →', 'easy-ai-forms' ) }
					</Button>
				</nav>
			) }

			{ deleting && (
				<Modal
					title={ __( 'Delete form?', 'easy-ai-forms' ) }
					onRequestClose={ () => setDeleting( null ) }
					className="eaif-delete-modal"
				>
					<p>
						{ sprintf(
							// translators: %s: form title (or "#id" fallback for untitled forms)
							__( 'Delete "%s"? Submissions are kept but the form will stop working anywhere it is embedded. This cannot be undone.', 'easy-ai-forms' ),
							deleting.title || `#${ deleting.id }`
						) }
					</p>
					<Flex justify="flex-end" gap={ 2 }>
						<Button variant="tertiary" onClick={ () => setDeleting( null ) }>
							{ __( 'Cancel', 'easy-ai-forms' ) }
						</Button>
						<Button variant="primary" isDestructive onClick={ confirmDelete }>
							{ __( 'Delete form', 'easy-ai-forms' ) }
						</Button>
					</Flex>
				</Modal>
			) }
		</div>
	);
}
