import { useState, useEffect } from '@wordpress/element';
import { api, cfg, rescanAll, perms } from './api';
import { Button, Spinner } from './ui';
import { ImportPanel } from './PortalAdmin';

const SENSITIVITY = [
	[ 'relaxed', 'Relaxed', 'Only clear-cut pitches and spam are filtered. Fewer mistakes, more noise.' ],
	[ 'balanced', 'Balanced', 'Recommended for most websites.' ],
	[ 'strict', 'Strict', 'Filters anything that looks like a pitch. Check the Promotional tab now and then.' ],
];

export default function Settings( { onSaved } ) {
	const [ s, setS ] = useState( null );
	const [ learned, setLearned ] = useState( 0 );
	const [ saving, setSaving ] = useState( false );
	const [ notice, setNotice ] = useState( '' );

	useEffect( () => {
		api( 'settings' ).then( ( r ) => {
			setS( r.settings );
			setLearned( r.learned );
		} );
	}, [] );

	if ( ! s ) {
		return (
			<section className="ls-panel">
				<div className="ls-loading">
					<Spinner />
				</div>
			</section>
		);
	}

	const set = ( key, value ) => setS( { ...s, [ key ]: value } );
	const setSource = ( key, value ) => setS( { ...s, sources: { ...s.sources, [ key ]: value } } );

	const save = ( extra = {} ) => {
		setSaving( true );
		api( 'settings', { method: 'POST', data: { ...s, ...extra } } )
			.then( ( r ) => {
				setS( r.settings );
				setLearned( r.learned );
				setNotice( 'Settings saved. Re-checking your existing inquiries…' );
				rescanAll()
					.then( ( r ) => setNotice( `Settings saved. ${ r.processed } existing inquiries re-checked, ${ r.changed } changed.` ) )
					.catch( () => setNotice( 'Settings saved.' ) );
				onSaved();
				setTimeout( () => setNotice( '' ), 5000 );
			} )
			.catch( ( e ) => setNotice( e.message ) )
			.finally( () => setSaving( false ) );
	};

	const locked = ! perms.canEdit;

	return (
		<section className="ls-settings">
			{ notice && <div className="ls-notice">{ notice }</div> }
			{ locked && <div className="ls-notice">Your login can view these settings but not change them. Ask the website manager for “Manage” access.</div> }

			<ImportPanel />

			<fieldset className="ls-fieldset" disabled={ locked }>

			<div className="ls-panel">
				<h3 className="ls-h3">Filter strength</h3>
				<div className="ls-radio-cards">
					{ SENSITIVITY.map( ( [ key, label, help ] ) => (
						<label key={ key } className={ `ls-radio-card${ s.sensitivity === key ? ' is-active' : '' }` }>
							<input type="radio" name="ls-sensitivity" checked={ s.sensitivity === key } onChange={ () => set( 'sensitivity', key ) } />
							<strong>{ label }</strong>
							<span>{ help }</span>
						</label>
					) ) }
				</div>
			</div>

			<div className="ls-two-col">
				<div className="ls-panel">
					<h3 className="ls-h3">Capture inquiries from</h3>
					<p className="ls-muted">LeadSift hooks into these form plugins automatically when they are active.</p>
					{ Object.keys( cfg.sources || {} ).map( ( k ) => (
						<label key={ k } className="ls-toggle">
							<input type="checkbox" checked={ !! s.sources[ k ] } onChange={ ( e ) => setSource( k, e.target.checked ) } />
							<span>{ cfg.sources[ k ] }</span>
						</label>
					) ) }
				</div>

				<div className="ls-panel">
					<h3 className="ls-h3">Emails & behaviour</h3>
					<label className="ls-toggle">
						<input type="checkbox" checked={ !! s.notify_genuine } onChange={ ( e ) => set( 'notify_genuine', e.target.checked ) } />
						<span>Email me when a genuine inquiry arrives</span>
					</label>
					{ s.notify_genuine && (
						<input type="email" className="ls-input ls-input--full" value={ s.notify_email } onChange={ ( e ) => set( 'notify_email', e.target.value ) } placeholder="you@example.com" />
					) }
					<label className="ls-toggle">
						<input type="checkbox" checked={ !! s.suppress_emails } onChange={ ( e ) => set( 'suppress_emails', e.target.checked ) } />
						<span>
							Stop the form's own notification email for promotional & spam inquiries
							<small>They are still saved here. Works with Contact Form 7, WPForms, Gravity Forms, Elementor and Fluent Forms.</small>
						</span>
					</label>
					<label className="ls-toggle">
						<input type="checkbox" checked={ !! s.learn_from_actions } onChange={ ( e ) => set( 'learn_from_actions', e.target.checked ) } />
						<span>
							Learn from my corrections
							<small>When you move an inquiry, future ones from that email go to the same place. { learned } senders remembered.</small>
						</span>
					</label>
					<label className="ls-toggle">
						<input type="checkbox" checked={ !! s.flag_foreign_scripts } onChange={ ( e ) => set( 'flag_foreign_scripts', e.target.checked ) } />
						<span>Treat Russian / Chinese script messages as likely spam</span>
					</label>
					<label className="ls-toggle">
						<input type="checkbox" checked={ !! s.store_ip } onChange={ ( e ) => set( 'store_ip', e.target.checked ) } />
						<span>Store visitor IP address (helps catch repeated bot submissions)</span>
					</label>
					<label className="ls-field">
						<span>Phone number check</span>
						<select className="ls-input" value={ s.phone_country || '' } onChange={ ( e ) => set( 'phone_country', e.target.value ) }>
							<option value="IN">India – mobile numbers must be 10 digits starting with 6, 7, 8 or 9</option>
							<option value="">Any country – only catch obviously fake numbers</option>
						</select>
					</label>
				</div>
			</div>

			<div className="ls-panel">
				<h3 className="ls-h3">Data</h3>
				<label className="ls-field ls-field--inline">
					<span>Auto-delete promotional & spam inquiries older than</span>
					<input type="number" min="0" className="ls-input ls-input--num" value={ s.retention_days } onChange={ ( e ) => set( 'retention_days', e.target.value ) } />
					<span>days (0 = keep everything)</span>
				</label>
				<label className="ls-toggle">
					<input type="checkbox" checked={ !! s.delete_on_uninstall } onChange={ ( e ) => set( 'delete_on_uninstall', e.target.checked ) } />
					<span>Delete all LeadSift data when the plugin is deleted</span>
				</label>
				{ learned > 0 && (
					<Button
						onClick={ () => {
							if ( window.confirm( 'Forget all remembered senders?' ) ) {
								save( { clear_learned: true } );
							}
						} }
					>
						Forget { learned } remembered senders
					</Button>
				) }
			</div>

			{ ! locked && (
				<div className="ls-savebar">
					<Button variant="primary" onClick={ () => save() } disabled={ saving }>
						{ saving ? 'Saving…' : 'Save settings' }
					</Button>
				</div>
			) }
			</fieldset>

			<p className="ls-muted ls-center">Your own rules, keywords, sender lists and the AI key are set by the website manager in WordPress → LeadSift → Rules &amp; AI.</p>
		</section>
	);
}

export function Developers() {
	const snippet = `fetch('${ cfg.submitUrl || '/wp-json/leadsift/v1/submit' }', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    form_name: 'Landing page form',
    name: 'Visitor name',
    email: 'visitor@example.com',
    phone: '9876543210',
    message: 'Inquiry text'
  })
});`;
	return (
		<details className="ls-panel ls-dev">
			<summary className="ls-h3">Using a custom HTML form? Connect it here</summary>
			<p className="ls-muted">Send any form to this endpoint (the "Custom form (REST API)" source must be on):</p>
			<pre>{ snippet }</pre>
			<p className="ls-muted">From PHP code you can also call:</p>
			<pre>{ "do_action( 'leadsift_capture', $fields, 'my-form', array( 'form_name' => 'Quote form' ) );" }</pre>
			<p className="ls-muted">
				Shortcode for any page: <code>[leadsift_dashboard]</code> (shows the same dashboard, behind the same Login ID &amp; password).
			</p>
		</details>
	);
}
