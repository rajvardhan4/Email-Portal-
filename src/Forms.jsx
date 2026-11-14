import { useState, useEffect, useCallback } from '@wordpress/element';
import { api, perms, isPortal, CATEGORIES, timeAgo } from './api';
import { Button, Spinner, Empty, CategoryIcon } from './ui';

export const FORM_TYPES = {
	contact: 'Contact / enquiry',
	quote: 'Quote / order',
	booking: 'Booking / appointment',
	job: 'Job application',
	newsletter: 'Newsletter / subscribe',
	support: 'Support / complaint',
	other: 'Other',
};

const ROLES = [
	[ 'name', 'Name' ],
	[ 'email', 'Email' ],
	[ 'phone', 'Phone' ],
	[ 'subject', 'Subject' ],
	[ 'message', 'Message' ],
	[ 'company', 'Company / website' ],
	[ 'other', 'Other info (still checked)' ],
	[ 'ignore', 'Ignore (not checked)' ],
];

export default function Forms( { onOpen } ) {
	const [ forms, setForms ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ open, setOpen ] = useState( null );
	const [ loading, setLoading ] = useState( false );

	const load = useCallback( () => {
		setLoading( true );
		return api( 'forms' )
			.then( setForms )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setLoading( false ) );
	}, [] );

	useEffect( () => {
		load();
	}, [ load ] );

	if ( error ) {
		return <div className="ls-notice ls-notice--error">{ error }</div>;
	}
	if ( ! forms ) {
		return (
			<section className="ls-panel">
				<div className="ls-loading">
					<Spinner />
				</div>
			</section>
		);
	}
	if ( forms.length === 0 ) {
		return (
			<section className="ls-panel">
				<Empty title="No forms found yet">
					Install a supported form plugin (Contact Form 7, WPForms, Gravity Forms, Elementor Pro, Fluent Forms, Ninja Forms or Formidable) or send a test inquiry. Elementor forms appear here after their first inquiry.
				</Empty>
			</section>
		);
	}

	const groups = {};
	forms.forEach( ( f ) => {
		( groups[ f.source_label ] = groups[ f.source_label ] || [] ).push( f );
	} );
	const totalFields = forms.reduce( ( a, f ) => a + ( f.field_count || 0 ), 0 );
	const totalWarnings = forms.reduce( ( a, f ) => a + ( f.warnings || 0 ), 0 );

	return (
		<section>
			<div className="ls-panel ls-forms-summary">
				<div>
					<h3 className="ls-h3">LeadSift read your forms</h3>
					<p className="ls-muted">
						{ forms.length } form{ forms.length === 1 ? '' : 's' } · { totalFields } fields · { Object.keys( groups ).length } form plugin{ Object.keys( groups ).length === 1 ? '' : 's' }
						{ totalWarnings > 0 && <b className="ls-warn-text"> · ⚠ { totalWarnings } thing{ totalWarnings === 1 ? '' : 's' } to check</b> }
						<br />
						For every form LeadSift knows which field is the name, email, phone and message, and what the form is for. Click <b>Fields</b> to check it{ perms.canEdit ? ' or correct it' : '' }, or <b>Inquiries</b> to see only that form&apos;s inquiries.
					</p>
				</div>
				<Button onClick={ load } disabled={ loading }>
					{ loading ? 'Reading…' : 'Read forms again' }
				</Button>
			</div>

			{ Object.keys( groups ).map( ( label ) => (
				<div key={ label } className="ls-panel">
					<h3 className="ls-h3">
						{ label } <span className="ls-muted">· { groups[ label ].length } form{ groups[ label ].length === 1 ? '' : 's' }</span>
					</h3>
					<div className="ls-table-wrap">
						<table className="ls-table ls-forms-table">
							<thead>
								<tr>
									<th>Form</th>
									<th className="ls-num">Total</th>
									{ Object.keys( CATEGORIES ).map( ( k ) => (
										<th key={ k } className="ls-num">
											{ CATEGORIES[ k ].label }
										</th>
									) ) }
									<th className="ls-hide-sm">Genuine rate</th>
									<th className="ls-hide-sm">Last inquiry</th>
									<th />
								</tr>
							</thead>
							<tbody>
								{ groups[ label ].map( ( f ) => {
									const rate = f.total ? Math.round( ( f.genuine / f.total ) * 100 ) : null;
									const isOpen = open === f.key;
									return [
										<tr key={ f.key } className={ isOpen ? 'is-open' : '' } onClick={ () => ( onOpen && f.total ? onOpen( f ) : setOpen( isOpen ? null : f.key ) ) }>
											<td>
												<div className="ls-sender__name">{ f.name }</div>
												<div className="ls-form-meta">
													<span className="ls-type-chip">{ FORM_TYPES[ f.type ] || f.type }</span>
													<span className="ls-muted">
														{ f.field_count } field{ f.field_count === 1 ? '' : 's' } · ID { f.form_id || '—' }
													</span>
													{ f.warnings > 0 && <span className="ls-review-chip">⚠ { f.warnings }</span> }
													{ f.mapped && <span className="ls-ai-chip">Checked by you</span> }
													{ f.unread > 0 && <b className="ls-unread-pill ls-unread-pill--inline">{ f.unread } unread</b> }
												</div>
											</td>
											<td className="ls-num">
												<b>{ f.total }</b>
											</td>
											{ Object.keys( CATEGORIES ).map( ( k ) => (
												<td key={ k } className="ls-num">
													<span className={ `ls-count ls-count--${ k }${ f[ k ] ? '' : ' is-zero' }` }>
														<CategoryIcon category={ k } />
														{ f[ k ] }
													</span>
												</td>
											) ) }
											<td className="ls-hide-sm">
												{ rate === null ? (
													<span className="ls-muted">No inquiries yet</span>
												) : (
													<div className="ls-rate">
														<div className="ls-rate__track">
															<div style={ { width: `${ rate }%` } } />
														</div>
														<span>{ rate }%</span>
													</div>
												) }
											</td>
											<td className="ls-hide-sm ls-col-date">{ f.last_at ? timeAgo( f.last_at ) : '—' }</td>
											<td className="ls-forms-actions" onClick={ ( e ) => e.stopPropagation() }>
												<Button onClick={ () => setOpen( isOpen ? null : f.key ) }>{ isOpen ? 'Close' : 'Fields' }</Button>
												{ onOpen && (
													<Button onClick={ () => onOpen( f ) } disabled={ ! f.total }>
														Inquiries
													</Button>
												) }
											</td>
										</tr>,
										isOpen && (
											<tr key={ f.key + '-fields' } className="ls-fields-row">
												<td colSpan={ 9 }>
													<FormFields form={ f } onSaved={ load } />
												</td>
											</tr>
										),
									];
								} ) }
							</tbody>
						</table>
					</div>
				</div>
			) ) }
		</section>
	);
}

/** Fields of one form and what LeadSift reads each one as. */
function FormFields( { form, onSaved } ) {
	const [ data, setData ] = useState( null );
	const [ roles, setRoles ] = useState( {} );
	const [ type, setType ] = useState( 'contact' );
	const [ required, setRequired ] = useState( {} );
	const [ checks, setChecks ] = useState( { check_required: true, check_options: true, check_same: true, min_words: 0 } );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState( '' );

	const apply = ( r ) => {
		setData( r );
		setType( r.type );
		const map = {};
		const req = {};
		r.fields.forEach( ( fl ) => {
			map[ fl.key ] = fl.role;
			req[ fl.key ] = !! fl.required;
		} );
		setRoles( map );
		setRequired( req );
		if ( r.checks ) {
			setChecks( r.checks );
		}
	};

	useEffect( () => {
		api( 'forms/fields', { query: { key: form.key, name: form.name } } )
			.then( apply )
			.catch( ( e ) => setNotice( e.message ) );
	}, [ form.key ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const save = () => {
		setBusy( true );
		api( 'forms/fields', { method: 'POST', data: { key: form.key, name: form.name, roles, type, required, checks } } )
			.then( ( r ) => {
				apply( r );
				setNotice( 'Saved. New inquiries from this form are read and checked with these rules.' );
				onSaved();
			} )
			.catch( ( e ) => setNotice( e.message ) )
			.finally( () => setBusy( false ) );
	};

	const reset = () => {
		setBusy( true );
		api( 'forms/fields', { method: 'DELETE', query: { key: form.key, name: form.name } } )
			.then( ( r ) => {
				apply( r );
				setNotice( 'Back to automatic detection.' );
				onSaved();
			} )
			.catch( ( e ) => setNotice( e.message ) )
			.finally( () => setBusy( false ) );
	};

	if ( ! data ) {
		return notice ? <div className="ls-notice ls-notice--error">{ notice }</div> : <div className="ls-loading"><Spinner /></div>;
	}

	return (
		<div className="ls-form-fields">
			<div className="ls-form-fields__top">
				<label className="ls-field ls-field--inline">
					<span>This form is for</span>
					<select className="ls-input" value={ type } onChange={ ( e ) => setType( e.target.value ) }>
						{ Object.keys( FORM_TYPES ).map( ( k ) => (
							<option key={ k } value={ k }>
								{ FORM_TYPES[ k ] }
								{ k === data.type_auto ? ' (detected)' : '' }
							</option>
						) ) }
					</select>
				</label>
				<span className="ls-muted">
					{ data.from === 'plugin' && 'Fields read directly from the form.' }
					{ data.from === 'inquiries' && 'Fields learned from received inquiries.' }
				</span>
			</div>

			{ data.warnings.length > 0 && (
				<ul className="ls-form-warnings">
					{ data.warnings.map( ( w ) => (
						<li key={ w }>⚠ { w }</li>
					) ) }
				</ul>
			) }

			{ data.fields.length > 0 && (
				<table className="ls-fields ls-fields--map">
					<thead>
						<tr>
							<th>Field</th>
							<th className="ls-hide-sm">Input type</th>
							<th>LeadSift reads it as</th>
							<th title="Must be filled in. An empty required field means a bot skipped the form.">Required</th>
						</tr>
					</thead>
					<tbody>
						{ data.fields.map( ( fl ) => (
							<tr key={ fl.key }>
								<td>
									<b>{ fl.label }</b>
									{ fl.required && <span className="ls-required" title="Required field">*</span> }
									{ fl.label !== fl.key && <div className="ls-muted">{ fl.key }</div> }
								</td>
								<td className="ls-hide-sm ls-muted">
									{ fl.type || '—' }
									{ fl.options && fl.options.length > 0 && <div title={ fl.options.join( ', ' ) }>{ fl.options.length } options</div> }
								</td>
								<td>
									<select className="ls-input" value={ roles[ fl.key ] || fl.role } onChange={ ( e ) => setRoles( { ...roles, [ fl.key ]: e.target.value } ) }>
										{ ROLES.map( ( [ v, l ] ) => (
											<option key={ v } value={ v }>
												{ l }
												{ v === fl.role_auto ? ' (detected)' : '' }
											</option>
										) ) }
									</select>
								</td>
								<td className="ls-center">
									<input type="checkbox" checked={ !! required[ fl.key ] } onChange={ ( e ) => setRequired( { ...required, [ fl.key ]: e.target.checked } ) } aria-label={ `${ fl.label } is required` } />
									{ fl.required_form && <div className="ls-muted ls-tiny">in form</div> }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			<div className="ls-form-checks">
				<div className="ls-block__label">Strict checks for this form</div>
				<label className="ls-check">
					<input type="checkbox" checked={ !! checks.check_required } onChange={ ( e ) => setChecks( { ...checks, check_required: e.target.checked } ) } /> Required fields must be filled in
				</label>
				<label className="ls-check">
					<input type="checkbox" checked={ !! checks.check_options } onChange={ ( e ) => setChecks( { ...checks, check_options: e.target.checked } ) } /> Dropdown / radio answers must be one of the form's options
				</label>
				<label className="ls-check">
					<input type="checkbox" checked={ !! checks.check_same } onChange={ ( e ) => setChecks( { ...checks, check_same: e.target.checked } ) } /> Same text typed into every field = junk
				</label>
				<label className="ls-check">
					Message must have at least{ ' ' }
					<input type="number" min="0" max="50" className="ls-input ls-input--num" value={ checks.min_words } onChange={ ( e ) => setChecks( { ...checks, min_words: e.target.value } ) } />{ ' ' }
					words <span className="ls-muted">(0 = default: 3 words, and no "hi" / "test" / keyboard mashing)</span>
				</label>
			</div>

			{ data.rules && data.rules.length > 0 && (
				<details className="ls-form-rules" open>
					<summary>Rules LeadSift applies to this form ({ data.rules.length })</summary>
					<ul>
						{ data.rules.map( ( r ) => (
							<li key={ r }>✓ { r }</li>
						) ) }
					</ul>
				</details>
			) }

			{ notice && <div className="ls-notice">{ notice }</div> }
			{ ! perms.canEdit && <p className="ls-muted">Only the website manager can change these settings.</p> }
			<div className="ls-form-fields__actions" hidden={ ! perms.canEdit }>
				<Button variant="primary" onClick={ save } disabled={ busy || data.fields.length === 0 }>
					Save for this form
				</Button>
				{ data.mapped && (
					<Button onClick={ reset } disabled={ busy }>
						Reset to automatic
					</Button>
				) }
			</div>
		</div>
	);
}
