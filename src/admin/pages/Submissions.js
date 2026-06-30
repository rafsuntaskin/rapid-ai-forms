import { useEffect, useMemo, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	Dropdown,
	MenuGroup,
	MenuItem,
	Modal,
	Notice,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { __, sprintf, _n } from '@wordpress/i18n';
import PageHeader from '../../shared/components/PageHeader';

const PER_PAGE = 20;

// Pull the download filename out of Content-Disposition, with a sane default.
function filenameFromResponse( res, fallback ) {
	const cd = res.headers.get( 'Content-Disposition' ) || '';
	const match = cd.match( /filename="?([^"]+)"?/i );
	return ( match && match[ 1 ] ) || fallback;
}

// "3h ago" style within 24h, locale date beyond that.
function formatSubmitted( iso ) {
	if ( ! iso ) return '';
	const d = new Date( iso.replace( ' ', 'T' ) + 'Z' );
	const diff = ( Date.now() - d.getTime() ) / 1000;
	if ( diff < 60 ) return __( 'just now', 'rapid-ai-forms' );
	if ( diff < 3600 ) {
		return sprintf(
			// translators: %d: number of minutes elapsed
			__( '%dm ago', 'rapid-ai-forms' ),
			Math.floor( diff / 60 )
		);
	}
	if ( diff < 86400 ) {
		return sprintf(
			// translators: %d: number of hours elapsed
			__( '%dh ago', 'rapid-ai-forms' ),
			Math.floor( diff / 3600 )
		);
	}
	return d.toLocaleDateString( undefined, {
		year: 'numeric',
		month: 'short',
		day: 'numeric',
	} );
}

function formatValue( value ) {
	if ( Array.isArray( value ) ) return value.join( ', ' );
	if ( value === null || value === undefined ) return '';
	return String( value );
}

// Best-effort one-line summary: prefer a textarea ("message") field from the
// form schema, then a field literally named message/comment. Forms without a
// message-style field get a labeled digest of the first few fields instead
// ("Name: Jane · Guests: 2"), so a bare value never appears without context.
const DIGEST_FIELDS = 3;

function messageExcerpt( row, schema ) {
	const fields = ( schema && schema.fields ) || [];
	const textarea = fields.find(
		( f ) => f.type === 'textarea' && formatValue( row.data[ f.name ] )
	);
	if ( textarea ) return formatValue( row.data[ textarea.name ] );
	for ( const name of [ 'message', 'comment', 'comments' ] ) {
		if ( formatValue( row.data[ name ] ) )
			return formatValue( row.data[ name ] );
	}
	const digest = fields
		.filter(
			( f ) =>
				f.type !== 'hidden' &&
				f.type !== 'password' &&
				formatValue( row.data[ f.name ] )
		)
		.slice( 0, DIGEST_FIELDS )
		.map(
			( f ) =>
				`${ f.label || f.name }: ${ formatValue( row.data[ f.name ] ) }`
		);
	if ( digest.length ) return digest.join( ' · ' );
	// No schema match at all (e.g. deleted form) — fall back to raw data values.
	return Object.values( row.data ).map( formatValue ).filter( Boolean ).join( ' · ' );
}

export default function Submissions( { api } ) {
	const [ forms, setForms ] = useState( [] );
	const [ rows, setRows ] = useState( null );
	const [ error, setError ] = useState( null );
	const [ loading, setLoading ] = useState( false );
	const [ page, setPage ] = useState( 1 );
	const [ total, setTotal ] = useState( 0 );
	const [ viewing, setViewing ] = useState( null );
	const [ dateFrom, setDateFrom ] = useState( '' );
	const [ dateTo, setDateTo ] = useState( '' );
	const [ exporting, setExporting ] = useState( false );
	const [ formId, setFormId ] = useState( () => {
		// Deep link: admin.php?page=rapid-ai-forms-submissions&form_id=6
		const param = new URLSearchParams( window.location.search ).get(
			'form_id'
		);
		return param && /^\d+$/.test( param ) ? param : '';
	} );

	// Shared form_id + date-range query string for both list and export.
	const filterQuery = useMemo( () => {
		const params = new URLSearchParams();
		if ( formId ) params.set( 'form_id', formId );
		if ( dateFrom ) params.set( 'date_from', dateFrom );
		if ( dateTo ) params.set( 'date_to', dateTo );
		return params.toString();
	}, [ formId, dateFrom, dateTo ] );

	// Forms power both the filter dropdown and the field-label lookup.
	useEffect( () => {
		api.get( 'forms?per_page=100' )
			.then( ( data ) => setForms( Array.isArray( data ) ? data : [] ) )
			.catch( () => setForms( [] ) );
	}, [ api ] );

	const schemaByFormId = useMemo( () => {
		const map = {};
		forms.forEach( ( f ) => {
			map[ f.id ] = f.schema || {};
		} );
		return map;
	}, [ forms ] );

	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		const url =
			`submissions?page=${ page }&per_page=${ PER_PAGE }` +
			( filterQuery ? `&${ filterQuery }` : '' );
		api.getWithHeaders( url )
			.then( ( { data, headers } ) => {
				if ( cancelled ) return;
				setRows( data );
				const t = parseInt( headers.get( 'X-WP-Total' ) || '0', 10 );
				setTotal( Number.isFinite( t ) ? t : 0 );
			} )
			.catch( ( e ) => {
				if ( ! cancelled )
					setError(
						e.message ||
							__(
								'Failed to load submissions.',
								'rapid-ai-forms'
							)
					);
			} )
			.finally( () => {
				if ( ! cancelled ) setLoading( false );
			} );
		return () => {
			cancelled = true;
		};
	}, [ api, page, filterQuery ] );

	// Download the current selection as CSV or JSON. Fetched as a blob (keeps
	// header-based nonce auth) then saved via a transient object URL.
	const handleExport = async ( format ) => {
		setExporting( true );
		setError( null );
		try {
			const path =
				`submissions/export?format=${ format }` +
				( filterQuery ? `&${ filterQuery }` : '' );
			const res = await api.getResponse( path );
			const blob = await res.blob();
			const url = URL.createObjectURL( blob );
			const a = document.createElement( 'a' );
			a.href = url;
			a.download = filenameFromResponse(
				res,
				`submissions.${ format }`
			);
			document.body.appendChild( a );
			a.click();
			a.remove();
			URL.revokeObjectURL( url );
		} catch ( e ) {
			setError(
				e.message ||
					__( 'Export failed.', 'rapid-ai-forms' )
			);
		} finally {
			setExporting( false );
		}
	};

	const totalPages = Math.max( 1, Math.ceil( total / PER_PAGE ) );
	const viewingSchema = viewing
		? schemaByFormId[ viewing.form_id ] || {}
		: {};
	const viewingFields = ( viewingSchema.fields || [] ).filter(
		( f ) => f.type !== 'hidden' && f.type !== 'password'
	);
	// Values present in the data but absent from the (possibly since-edited) schema.
	const viewingExtras = viewing
		? Object.keys( viewing.data ).filter(
				( name ) =>
					! ( viewingSchema.fields || [] ).some(
						( f ) => f.name === name
					)
		  )
		: [];

	return (
		<div className="raif-page raif-submissions">
			<PageHeader
				title={ __( 'Submissions', 'rapid-ai-forms' ) }
				description={ sprintf(
					// translators: %d: total number of submissions matching the current filter
					_n(
						'%d submission',
						'%d submissions',
						total,
						'rapid-ai-forms'
					),
					total
				) }
			/>

			{ error && (
				<Notice
					status="error"
					isDismissible
					onRemove={ () => setError( null ) }
				>
					{ error }
				</Notice>
			) }

			<div className="raif-submissions__toolbar">
				<SelectControl
					label={ __( 'Filter by form', 'rapid-ai-forms' ) }
					hideLabelFromVision
					value={ formId }
					options={ [
						{
							value: '',
							label: __( 'All forms', 'rapid-ai-forms' ),
						},
						...forms.map( ( f ) => ( {
							value: String( f.id ),
							label: f.title || `#${ f.id }`,
						} ) ),
					] }
					onChange={ ( value ) => {
						setFormId( value );
						setPage( 1 );
					} }
					__nextHasNoMarginBottom
				/>

				<div className="raif-submissions__dates">
					<label className="raif-submissions__date">
						<span>{ __( 'From', 'rapid-ai-forms' ) }</span>
						<input
							type="date"
							value={ dateFrom }
							max={ dateTo || undefined }
							onChange={ ( e ) => {
								setDateFrom( e.target.value );
								setPage( 1 );
							} }
						/>
					</label>
					<label className="raif-submissions__date">
						<span>{ __( 'To', 'rapid-ai-forms' ) }</span>
						<input
							type="date"
							value={ dateTo }
							min={ dateFrom || undefined }
							onChange={ ( e ) => {
								setDateTo( e.target.value );
								setPage( 1 );
							} }
						/>
					</label>
					{ ( dateFrom || dateTo ) && (
						<Button
							variant="tertiary"
							size="small"
							onClick={ () => {
								setDateFrom( '' );
								setDateTo( '' );
								setPage( 1 );
							} }
						>
							{ __( 'Clear dates', 'rapid-ai-forms' ) }
						</Button>
					) }
				</div>

				<div className="raif-submissions__spacer" />

				<Dropdown
					className="raif-submissions__export"
					popoverProps={ { placement: 'bottom-end' } }
					renderToggle={ ( { isOpen, onToggle } ) => (
						<Button
							variant="secondary"
							onClick={ onToggle }
							aria-expanded={ isOpen }
							disabled={
								exporting || ! rows || rows.length === 0
							}
							isBusy={ exporting }
						>
							{ exporting
								? __( 'Exporting…', 'rapid-ai-forms' )
								: __( 'Export', 'rapid-ai-forms' ) }
						</Button>
					) }
					renderContent={ ( { onClose } ) => (
						<MenuGroup>
							<MenuItem
								onClick={ () => {
									onClose();
									handleExport( 'csv' );
								} }
							>
								{ __( 'Download CSV', 'rapid-ai-forms' ) }
							</MenuItem>
							<MenuItem
								onClick={ () => {
									onClose();
									handleExport( 'json' );
								} }
							>
								{ __( 'Download JSON', 'rapid-ai-forms' ) }
							</MenuItem>
						</MenuGroup>
					) }
				/>
			</div>

			{ ( rows === null || loading ) && ! error && (
				<div className="raif-list__loading">
					<Spinner />
				</div>
			) }

			{ ! loading && rows && rows.length === 0 && (
				<Card className="raif-submissions__empty">
					<CardBody>
						{ formId
							? __(
									'No submissions for this form yet.',
									'rapid-ai-forms'
							  )
							: __(
									'No submissions yet. They will appear here as visitors submit your forms.',
									'rapid-ai-forms'
							  ) }
					</CardBody>
				</Card>
			) }

			{ ! loading && rows && rows.length > 0 && (
				<div className="raif-submissions__list" role="table">
					<div className="raif-submissions__head" role="row">
						<span role="columnheader">
							{ __( 'Submission', 'rapid-ai-forms' ) }
						</span>
						<span role="columnheader">
							{ __( 'Summary', 'rapid-ai-forms' ) }
						</span>
						<span role="columnheader">
							{ __( 'Submitted', 'rapid-ai-forms' ) }
						</span>
						<span role="columnheader">
							<span className="screen-reader-text">
								{ __( 'Actions', 'rapid-ai-forms' ) }
							</span>
						</span>
					</div>
					{ rows.map( ( row ) => (
						<div
							key={ row.id }
							className="raif-submissions__row"
							role="row"
							tabIndex={ 0 }
							onClick={ () => setViewing( row ) }
							onKeyDown={ ( e ) => {
								if ( e.key === 'Enter' || e.key === ' ' ) {
									e.preventDefault();
									setViewing( row );
								}
							} }
						>
							<span className="raif-submissions__id" role="cell">
								<span className="raif-submissions__number">
									#{ row.id }
								</span>
								{ ! formId && (
									<span className="raif-submissions__form-pill">
										{ row.form_title ||
											`#${ row.form_id }` }
									</span>
								) }
							</span>
							<span
								className="raif-submissions__excerpt"
								role="cell"
							>
								{ messageExcerpt(
									row,
									schemaByFormId[ row.form_id ]
								) || (
									<em>
										{ __(
											'(no content)',
											'rapid-ai-forms'
										) }
									</em>
								) }
							</span>
							<span
								className="raif-submissions__time"
								role="cell"
								title={ row.created_at }
							>
								{ formatSubmitted( row.created_at ) }
							</span>
							<span
								className="raif-submissions__view"
								role="cell"
							>
								<Button
									variant="secondary"
									size="small"
									onClick={ () => setViewing( row ) }
								>
									{ __( 'View', 'rapid-ai-forms' ) }
								</Button>
							</span>
						</div>
					) ) }
				</div>
			) }

			{ totalPages > 1 && (
				<nav
					className="raif-list__pagination"
					aria-label={ __(
						'Submissions pagination',
						'rapid-ai-forms'
					) }
				>
					<Button
						variant="secondary"
						disabled={ page <= 1 || loading }
						onClick={ () =>
							setPage( ( p ) => Math.max( 1, p - 1 ) )
						}
					>
						{ __( '← Previous', 'rapid-ai-forms' ) }
					</Button>
					<span className="raif-list__pagination-status">
						{ sprintf(
							// translators: 1: current page number, 2: total number of pages
							__( 'Page %1$d of %2$d', 'rapid-ai-forms' ),
							page,
							totalPages
						) }
					</span>
					<Button
						variant="secondary"
						disabled={ page >= totalPages || loading }
						onClick={ () =>
							setPage( ( p ) => Math.min( totalPages, p + 1 ) )
						}
					>
						{ __( 'Next →', 'rapid-ai-forms' ) }
					</Button>
				</nav>
			) }

			{ viewing && (
				<Modal
					title={ sprintf(
						// translators: %d: submission id
						__( 'Submission #%d', 'rapid-ai-forms' ),
						viewing.id
					) }
					onRequestClose={ () => setViewing( null ) }
					className="raif-submission-modal"
				>
					<div className="raif-submission-modal__meta">
						<span className="raif-submissions__form-pill">
							{ viewing.form_title || `#${ viewing.form_id }` }
						</span>
						<span title={ viewing.created_at }>
							{ formatSubmitted( viewing.created_at ) }
						</span>
					</div>
					<dl className="raif-submission-modal__fields">
						{ viewingFields.map( ( f ) => (
							<div key={ f.name }>
								<dt>{ f.label || f.name }</dt>
								<dd>
									{ formatValue( viewing.data[ f.name ] ) ||
										'—' }
								</dd>
							</div>
						) ) }
						{ viewingExtras.map( ( name ) => (
							<div key={ name }>
								<dt>{ name }</dt>
								<dd>
									{ formatValue( viewing.data[ name ] ) ||
										'—' }
								</dd>
							</div>
						) ) }
						{ viewing.ip_address && (
							<div>
								<dt>
									{ __( 'IP address', 'rapid-ai-forms' ) }
								</dt>
								<dd>{ viewing.ip_address }</dd>
							</div>
						) }
						{ viewing.user_agent && (
							<div>
								<dt>
									{ __( 'User agent', 'rapid-ai-forms' ) }
								</dt>
								<dd>{ viewing.user_agent }</dd>
							</div>
						) }
					</dl>
					{ viewing.email && (
						<div className="raif-submission-modal__email">
							<h3>
								{ __(
									'Email notification',
									'rapid-ai-forms'
								) }
							</h3>
							<div className="raif-submission-modal__email-subject">
								{ viewing.email.subject }
							</div>
							<pre className="raif-submission-modal__email-body">
								{ viewing.email.body }
							</pre>
						</div>
					) }
				</Modal>
			) }
		</div>
	);
}
