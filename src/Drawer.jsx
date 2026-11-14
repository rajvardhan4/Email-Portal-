import { useState, useEffect } from '@wordpress/element';
import { api, CATEGORIES, sourceLabel, formatDate, perms } from './api';
import { CategoryBadge, ScoreMeter, Button, Spinner, CategoryIcon, Reason } from './ui';

export default function Drawer( { id, onClose, onUpdated, onDeleted } ) {
	const [ row, setRow ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ learn, setLearn ] = useState( true );

	useEffect( () => {
		let alive = true;
		setRow( null );
		setError( '' );
		api( `inquiries/${ id }` )
			.then( ( r ) => {
				if ( ! alive ) {
					return;
				}
				setRow( r );
				if ( ! r.is_read ) {
					api( `inquiries/${ id }`, { method: 'POST', data: { is_read: true } } ).then( ( u ) => alive && onUpdated( u ) );
				}
			} )
			.catch( ( e ) => alive && setError( e.message ) );
		return () => {
			alive = false;
		};
	}, [ id ] ); // eslint-disable-line react-hooks/exhaustive-deps

	useEffect( () => {
		const onKey = ( e ) => e.key === 'Escape' && onClose();
		window.addEventListener( 'keydown', onKey );
		return () => window.removeEventListener( 'keydown', onKey );
	}, [ onClose ] );

	const setCategory = ( category ) => {
		setBusy( true );
		api( `inquiries/${ id }`, { method: 'POST', data: { category, learn } } )
			.then( ( u ) => {
				setRow( u );
				onUpdated( u );
			} )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( false ) );
	};

	const toggleRead = () => {
		api( `inquiries/${ id }`, { method: 'POST', data: { is_read: ! row.is_read } } ).then( ( u ) => {
			setRow( u );
			onUpdated( u );
		} );
	};

	const askAi = () => {
		setBusy( true );
		setError( '' );
		api( `inquiries/${ id }/ai`, { method: 'POST' } )
			.then( ( u ) => {
				setRow( u );
				onUpdated( u );
			} )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setBusy( false ) );
	};

	const remove = () => {
		if ( ! window.confirm( 'Delete this inquiry permanently?' ) ) {
			return;
		}
		api( `inquiries/${ id }`, { method: 'DELETE' } ).then( onDeleted );
	};

	const digits = row ? ( row.phone || '' ).replace( /\D/g, '' ) : '';
	const waNumber = digits.length === 10 ? '91' + digits : digits;

	return (
		<div className="ls-drawer-backdrop" onClick={ onClose }>
			<aside className="ls-drawer" onClick={ ( e ) => e.stopPropagation() } role="dialog" aria-label="Inquiry details">
				<div className="ls-drawer__head">
					<span className="ls-muted">Inquiry #{ id }</span>
					<button type="button" className="ls-close" onClick={ onClose } aria-label="Close">
						×
					</button>
				</div>

				{ error && <div className="ls-notice ls-notice--error">{ error }</div> }
				{ ! row && ! error && (
					<div className="ls-loading">
						<Spinner />
					</div>
				) }

				{ row && (
					<div className="ls-drawer__body">
						<div className="ls-drawer__title">
							<h2>{ row.name || row.email || 'Unknown sender' }</h2>
							<CategoryBadge category={ row.category } manual={ row.is_manual } />
						</div>

						<div className="ls-contact">
							{ row.email && (
								<a className="ls-btn ls-btn--default" href={ `mailto:${ row.email }${ row.subject ? `?subject=Re: ${ encodeURIComponent( row.subject ) }` : '' }` }>
									✉ { row.email }
								</a>
							) }
							{ row.phone && (
								<a className="ls-btn ls-btn--default" href={ `tel:${ row.phone }` }>
									☎ { row.phone }
								</a>
							) }
							{ waNumber.length >= 10 && (
								<a className="ls-btn ls-btn--default" href={ `https://wa.me/${ waNumber }` } target="_blank" rel="noreferrer">
									WhatsApp
								</a>
							) }
						</div>

						<div className="ls-block">
							<div className="ls-block__label">Message</div>
							{ row.subject && <div className="ls-subject">{ row.subject }</div> }
							<div className="ls-message">{ row.message || <em>(no message)</em> }</div>
						</div>

						<div className="ls-block">
							<div className="ls-block__label">Why LeadSift decided this</div>
							<div className="ls-risk">
								<span>Risk score</span>
								<ScoreMeter score={ row.score } />
							</div>
							<ul className="ls-reasons">
								{ row.reasons.map( ( r, i ) => (
									<Reason key={ i } r={ r } />
								) ) }
							</ul>
						</div>

						{ row.needs_review && (
							<div className="ls-review-box">
								⚠ LeadSift is not fully sure about this one. Please read it and move it if the verdict is wrong.
							</div>
						) }

						<div className="ls-block">
							<div className="ls-block__label">AI second opinion</div>
							{ row.ai_category ? (
								<div className="ls-ai-box">
									<div className="ls-ai-box__head">
										<CategoryBadge category={ row.ai_category } />
										<span className="ls-muted">{ row.ai_confidence }% sure</span>
									</div>
									<p>{ row.ai_reason }</p>
								</div>
							) : (
								<p className="ls-muted">{ row.ai_reason ? row.ai_reason : 'Not checked by AI yet.' }</p>
							) }
							{ perms.canEdit && (
								<Button onClick={ askAi } disabled={ busy }>
									{ busy ? 'Asking AI…' : row.ai_category ? 'Ask AI again' : 'Ask AI now' }
								</Button>
							) }
						</div>

						<div className="ls-block">
							<div className="ls-block__label">Wrong verdict? Move it</div>
							<div className="ls-move">
								{ Object.keys( CATEGORIES ).map( ( k ) => (
									<Button key={ k } variant={ k } disabled={ busy || row.category === k } onClick={ () => setCategory( k ) }>
										<CategoryIcon category={ k } /> { CATEGORIES[ k ].label }
									</Button>
								) ) }
							</div>
							{ row.email && (
								<label className="ls-check ls-check--small">
									<input type="checkbox" checked={ learn } onChange={ ( e ) => setLearn( e.target.checked ) } /> Remember this for future inquiries from { row.email }
								</label>
							) }
						</div>

						<div className="ls-block">
							<div className="ls-block__label">All submitted fields</div>
							<table className="ls-fields">
								<tbody>
									{ Object.entries( row.form_data || {} ).map( ( [ k, v ] ) => (
										<tr key={ k }>
											<th>{ k }</th>
											<td>{ String( v ) }</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>

						<div className="ls-block">
							<div className="ls-block__label">Details</div>
							<dl className="ls-meta">
								<dt>Received</dt>
								<dd>{ formatDate( row.created_at ) }</dd>
								<dt>Form</dt>
								<dd>
									{ row.form_name || '—' } ({ sourceLabel( row.source ) })
								</dd>
								{ row.page_url && (
									<>
										<dt>Page</dt>
										<dd>
											<a href={ row.page_url } target="_blank" rel="noreferrer">
												{ row.page_url }
											</a>
										</dd>
									</>
								) }
								{ row.ip && (
									<>
										<dt>IP address</dt>
										<dd>{ row.ip }</dd>
									</>
								) }
							</dl>
						</div>

						<div className="ls-drawer__foot">
							<Button onClick={ toggleRead }>{ row.is_read ? 'Mark unread' : 'Mark read' }</Button>
							<Button variant="danger" onClick={ remove }>
								Delete
							</Button>
						</div>
					</div>
				) }
			</aside>
		</div>
	);
}
