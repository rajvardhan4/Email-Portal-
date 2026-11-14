import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { api, perms, CATEGORIES, sourceLabel, timeAgo, formatDate, downloadText } from './api';
import { CategoryBadge, ScoreMeter, Button, Spinner, Empty } from './ui';
import Drawer from './Drawer';

const PER_PAGE = 20;

function filterParams( category, formFilter ) {
	const params = category === 'review' ? { review: 1 } : { category: category === 'all' ? '' : category };
	if ( formFilter.startsWith( 'src|' ) ) {
		params.source = formFilter.slice( 4 );
	} else if ( formFilter.startsWith( 'form|' ) ) {
		params.form = formFilter.slice( 5 );
	}
	return params;
}

export default function Inquiries( { category, setCategory, formFilter, setFormFilter, stats, onChange, liveTick = 0, newSince = 0 } ) {
	const [ search, setSearch ] = useState( '' );
	const [ query, setQuery ] = useState( '' );
	const [ forms, setForms ] = useState( [] );
	const [ unread, setUnread ] = useState( false );
	const [ page, setPage ] = useState( 1 );
	const [ data, setData ] = useState( { items: [], total: 0 } );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ selected, setSelected ] = useState( [] );
	const [ openId, setOpenId ] = useState( null );
	const [ notice, setNotice ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const timer = useRef();

	// Debounce the search box.
	useEffect( () => {
		clearTimeout( timer.current );
		timer.current = setTimeout( () => {
			setQuery( search );
			setPage( 1 );
		}, 300 );
		return () => clearTimeout( timer.current );
	}, [ search ] );

	useEffect( () => {
		setPage( 1 );
	}, [ category, formFilter, unread ] );

	useEffect( () => {
		api( 'forms' )
			.then( setForms )
			.catch( () => {} );
	}, [] );

	// quiet = live refresh in the background: no spinner, keeps your ticked rows.
	const load = useCallback( ( quiet = false ) => {
		if ( ! quiet ) {
			setLoading( true );
			setError( '' );
		}
		return api( 'inquiries', {
			query: { ...filterParams( category, formFilter ), search: query, unread: unread ? 1 : '', page, per_page: PER_PAGE },
		} )
			.then( ( res ) => {
				setData( res );
				setSelected( ( sel ) => ( quiet ? sel.filter( ( id ) => res.items.some( ( i ) => i.id === id ) ) : [] ) );
			} )
			.catch( ( e ) => ! quiet && setError( e.message || 'Could not load inquiries.' ) )
			.finally( () => ! quiet && setLoading( false ) );
	}, [ category, query, formFilter, unread, page ] );

	useEffect( () => {
		load();
	}, [ load ] );

	// Something changed on the server (new inquiry, AI verdict…) → refresh quietly.
	useEffect( () => {
		if ( liveTick ) {
			load( true );
			api( 'forms' ).then( setForms ).catch( () => {} );
		}
	}, [ liveTick ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const flash = ( msg ) => {
		setNotice( msg );
		setTimeout( () => setNotice( '' ), 3500 );
	};

	const refresh = () => {
		load();
		onChange();
	};

	const bulk = ( action, label ) => {
		if ( action === 'delete' && ! window.confirm( `Delete ${ selected.length } inquiries permanently?` ) ) {
			return;
		}
		setBusy( true );
		api( 'inquiries/bulk', { method: 'POST', data: { ids: selected, action } } )
			.then( ( res ) => {
				flash( `${ res.updated } ${ label }` );
				refresh();
			} )
			.catch( ( e ) => flash( e.message ) )
			.finally( () => setBusy( false ) );
	};

	const exportCsv = () => {
		setBusy( true );
		api( 'export', { query: { ...filterParams( category, formFilter ), search: query } } )
			.then( ( res ) => {
				downloadText( res.filename, res.csv );
				flash( `Exported ${ res.count } inquiries` );
			} )
			.catch( ( e ) => flash( e.message ) )
			.finally( () => setBusy( false ) );
	};

	const rescan = async () => {
		if ( ! window.confirm( 'Re-check every inquiry with the current settings? Inquiries you corrected by hand are kept as they are.' ) ) {
			return;
		}
		setBusy( true );
		let after = 0;
		let processed = 0;
		let changed = 0;
		try {
			do {
				const res = await api( 'rescan', { method: 'POST', data: { after } } );
				processed += res.processed;
				changed += res.changed;
				after = res.next;
			} while ( after );
			flash( `Re-checked ${ processed } inquiries · ${ changed } updated` );
			refresh();
		} catch ( e ) {
			flash( e.message );
		}
		setBusy( false );
	};

	const allChecked = data.items.length > 0 && selected.length === data.items.length;
	const toggleAll = () => setSelected( allChecked ? [] : data.items.map( ( i ) => i.id ) );
	const toggle = ( id ) => setSelected( selected.includes( id ) ? selected.filter( ( x ) => x !== id ) : [ ...selected, id ] );

	const pages = Math.max( 1, Math.ceil( data.total / PER_PAGE ) );
	const counts = stats ? stats.totals : null;
	const allCount = counts ? counts.genuine.total + counts.promotional.total + counts.spam.total : null;
	const reviewCount = stats && typeof stats.review === 'number' ? stats.review : null;

	// Group forms by plugin for the dropdown.
	const groups = {};
	forms.forEach( ( f ) => {
		( groups[ f.source ] = groups[ f.source ] || { label: f.source_label, forms: [] } ).forms.push( f );
	} );
	const activeForm = formFilter.startsWith( 'form|' ) ? forms.find( ( f ) => f.key === formFilter.slice( 5 ) ) : null;
	const activeSource = formFilter.startsWith( 'src|' ) ? formFilter.slice( 4 ) : '';
	const pills = [
		[ 'all', 'All', allCount ],
		...Object.keys( CATEGORIES ).map( ( k ) => [ k, CATEGORIES[ k ].label, counts ? counts[ k ].total : null ] ),
		[ 'review', '⚠ Needs a look', reviewCount ],
	];

	return (
		<section className="ls-panel">
			<div className="ls-filters">
				<div className="ls-pills" role="tablist" aria-label="Filter by category">
					{ pills.map( ( [ key, label, count ] ) => (
						<button
							key={ key }
							type="button"
							className={ `ls-pill ls-pill--${ key }${ category === key ? ' is-active' : '' }` }
							onClick={ () => setCategory( key ) }
							title={ key === 'review' ? 'Inquiries where LeadSift is not fully sure – please check these by hand' : undefined }
						>
							{ label }
							{ count !== null && <span className="ls-pill__count">{ count }</span> }
						</button>
					) ) }
				</div>
				{ ( activeForm || activeSource ) && (
					<div className="ls-active-filter">
						Showing inquiries from <b>{ activeForm ? `${ activeForm.name } (${ activeForm.source_label })` : `all ${ sourceLabel( activeSource ) } forms` }</b>
						<button type="button" className="ls-link" onClick={ () => setFormFilter( '' ) }>
							Show all forms
						</button>
					</div>
				) }
				<div className="ls-toolbar">
					<input type="search" className="ls-input ls-search" placeholder="Search name, email, phone, message…" value={ search } onChange={ ( e ) => setSearch( e.target.value ) } />
					<select className="ls-input ls-form-select" value={ formFilter } onChange={ ( e ) => setFormFilter( e.target.value ) } aria-label="Filter by form">
						<option value="">All forms ({ forms.length })</option>
						{ Object.keys( groups ).map( ( src ) => (
							<optgroup key={ src } label={ groups[ src ].label }>
								<option value={ `src|${ src }` }>All { groups[ src ].label } forms</option>
								{ groups[ src ].forms.map( ( f ) => (
									<option key={ f.key } value={ `form|${ f.key }` }>
										{ f.name } ({ f.total })
									</option>
								) ) }
							</optgroup>
						) ) }
					</select>
					<label className="ls-check">
						<input type="checkbox" checked={ unread } onChange={ ( e ) => setUnread( e.target.checked ) } /> Unread only
					</label>
					<span className="ls-toolbar__spacer" />
					{ perms.canEdit && (
						<Button onClick={ rescan } disabled={ busy } title="Re-check all inquiries with current settings">
							Re-scan
						</Button>
					) }
					<Button onClick={ exportCsv } disabled={ busy }>
						Export CSV
					</Button>
				</div>
			</div>

			{ selected.length > 0 && (
				<div className="ls-bulkbar">
					<strong>{ selected.length } selected</strong>
					<Button variant="genuine" onClick={ () => bulk( 'mark_genuine', 'marked as genuine' ) } disabled={ busy }>
						Mark Genuine
					</Button>
					<Button variant="promotional" onClick={ () => bulk( 'mark_promotional', 'marked as promotional' ) } disabled={ busy }>
						Mark Promotional
					</Button>
					<Button variant="spam" onClick={ () => bulk( 'mark_spam', 'marked as spam' ) } disabled={ busy }>
						Mark Spam
					</Button>
					<Button onClick={ () => bulk( 'read', 'marked as read' ) } disabled={ busy }>
						Mark read
					</Button>
					<Button variant="danger" onClick={ () => bulk( 'delete', 'deleted' ) } disabled={ busy }>
						Delete
					</Button>
				</div>
			) }

			{ notice && <div className="ls-notice">{ notice }</div> }
			{ error && <div className="ls-notice ls-notice--error">{ error }</div> }

			<div className="ls-table-wrap">
				<table className="ls-table">
					<thead>
						<tr>
							<th className="ls-col-check">
								<input type="checkbox" checked={ allChecked } onChange={ toggleAll } aria-label="Select all" />
							</th>
							<th>Sender</th>
							<th>Message</th>
							<th className="ls-hide-sm">Form</th>
							<th>Verdict</th>
							<th className="ls-hide-sm">Risk</th>
							<th className="ls-col-date">Received</th>
						</tr>
					</thead>
					<tbody>
						{ data.items.map( ( item ) => (
							<tr key={ item.id } className={ `${ item.is_read ? '' : 'is-unread' }${ openId === item.id ? ' is-open' : '' }${ newSince && item.id > newSince ? ' is-new' : '' }` } onClick={ () => setOpenId( item.id ) }>
								<td className="ls-col-check" onClick={ ( e ) => e.stopPropagation() }>
									<input type="checkbox" checked={ selected.includes( item.id ) } onChange={ () => toggle( item.id ) } aria-label={ `Select inquiry ${ item.id }` } />
								</td>
								<td>
									<div className="ls-sender">
										<span className="ls-avatar">{ ( item.name || item.email || '?' ).trim().charAt( 0 ).toUpperCase() }</span>
										<div>
											<div className="ls-sender__name">{ item.name || <em>No name</em> }</div>
											<div className="ls-sender__email">{ item.email || item.phone || '—' }</div>
										</div>
									</div>
								</td>
								<td className="ls-msg">
									{ item.subject && <strong>{ item.subject } · </strong> }
									{ ( item.message || '' ).slice( 0, 140 ) || <em>(no message)</em> }
								</td>
								<td className="ls-hide-sm">
									<div className="ls-form-name">{ item.form_name || '—' }</div>
									<div className="ls-muted">{ sourceLabel( item.source ) }</div>
								</td>
								<td>
									<div className="ls-verdict">
										<CategoryBadge category={ item.category } manual={ item.is_manual } />
										{ item.ai_category && (
											<span className="ls-ai-chip" title={ `AI: ${ item.ai_category } (${ item.ai_confidence }% sure)` }>
												AI ✓
											</span>
										) }
										{ item.needs_review && (
											<span className="ls-review-chip" title="LeadSift is not fully sure about this one – please check">
												⚠ Check
											</span>
										) }
									</div>
								</td>
								<td className="ls-hide-sm">
									<ScoreMeter score={ item.score } compact />
								</td>
								<td className="ls-col-date" title={ formatDate( item.created_at ) }>
									{ timeAgo( item.created_at ) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
				{ loading && (
					<div className="ls-loading">
						<Spinner />
					</div>
				) }
				{ ! loading && data.items.length === 0 && (
					<Empty title={ query || formFilter || unread || category !== 'all' ? 'No inquiries match these filters' : 'No inquiries yet' }>
						{ query || formFilter || unread || category !== 'all'
							? 'Try another filter or clear the search.'
							: 'Submissions from your contact forms will appear here automatically and get sorted into Genuine, Promotional and Spam.' }
					</Empty>
				) }
			</div>

			<div className="ls-pager">
				<span className="ls-muted">
					{ data.total ? `Showing ${ ( page - 1 ) * PER_PAGE + 1 }–${ Math.min( page * PER_PAGE, data.total ) } of ${ data.total }` : '' }
				</span>
				<div>
					<Button onClick={ () => setPage( page - 1 ) } disabled={ page <= 1 || loading }>
						‹ Prev
					</Button>
					<span className="ls-pager__num">
						{ page } / { pages }
					</span>
					<Button onClick={ () => setPage( page + 1 ) } disabled={ page >= pages || loading }>
						Next ›
					</Button>
				</div>
			</div>

			{ openId && (
				<Drawer
					id={ openId }
					onClose={ () => setOpenId( null ) }
					onUpdated={ ( row ) => {
						setData( ( d ) => ( { ...d, items: d.items.map( ( i ) => ( i.id === row.id ? row : i ) ) } ) );
						onChange();
					} }
					onDeleted={ () => {
						setOpenId( null );
						refresh();
						flash( 'Inquiry deleted' );
					} }
				/>
			) }
		</section>
	);
}
