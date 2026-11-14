import { useState, useEffect } from '@wordpress/element';
import { api, perms } from './api';
import { Button, Spinner, Empty } from './ui';

function copy( text ) {
	try {
		window.navigator.clipboard.writeText( text );
		return true;
	} catch ( e ) {
		return false;
	}
}

/**
 * wp-admin → LeadSift → "Dashboard page & login".
 * The page link, and the login ID / password your client uses on that page.
 */
export function PortalAdmin() {
	const [ data, setData ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ form, setForm ] = useState( { name: '', username: '', password: '', access: 'manage' } );
	const [ busy, setBusy ] = useState( false );
	const [ created, setCreated ] = useState( null ); // { name, username, password } shown once.
	const [ copied, setCopied ] = useState( '' );
	const [ changing, setChanging ] = useState( null ); // login id whose password is being changed.
	const [ newPass, setNewPass ] = useState( '' );

	const load = () =>
		api( 'portal/users' )
			.then( setData )
			.catch( ( e ) => setError( e.message ) );

	useEffect( () => {
		load();
	}, [] );

	const flashCopy = ( what, text ) => {
		copy( text );
		setCopied( what );
		setTimeout( () => setCopied( '' ), 2000 );
	};

	const details = ( u ) => `Dashboard: ${ data.url }\nLogin ID: ${ u.username }\nPassword: ${ u.password }`;

	const add = ( e ) => {
		e.preventDefault();
		setBusy( true );
		setError( '' );
		api( 'portal/users', { method: 'POST', data: form } )
			.then( ( u ) => {
				setCreated( u );
				setForm( { name: '', username: '', password: '', access: 'manage' } );
				load();
			} )
			.catch( ( err ) => setError( err.message ) )
			.finally( () => setBusy( false ) );
	};

	const savePassword = ( u ) => {
		setError( '' );
		const body = newPass ? { password: newPass } : { new_password: true };
		api( `portal/users/${ u.id }`, { method: 'POST', data: body } )
			.then( ( r ) => {
				setCreated( r );
				setChanging( null );
				setNewPass( '' );
			} )
			.catch( ( err ) => setError( err.message ) );
	};

	const setAccess = ( u, access ) => {
		api( `portal/users/${ u.id }`, { method: 'POST', data: { access } } )
			.then( load )
			.catch( ( err ) => setError( err.message ) );
	};

	const remove = ( u ) => {
		if ( ! window.confirm( `Delete the login "${ u.username }"? It stops working right away.` ) ) {
			return;
		}
		api( `portal/users/${ u.id }`, { method: 'DELETE' } )
			.then( load )
			.catch( ( err ) => setError( err.message ) );
	};

	if ( ! data ) {
		return (
			<div className="ls-panel">
				{ error ? <div className="ls-notice ls-notice--error">{ error }</div> : <Spinner /> }
			</div>
		);
	}

	return (
		<section>
			{ /* 1. The page */ }
			<div className="ls-panel ls-portal-admin">
				<h3 className="ls-h3">Your dashboard page</h3>
				<p className="ls-muted">
					LeadSift created this page on your website. It shows every inquiry, sorted into Genuine, Promotional and Spam, as a dashboard – without your theme&apos;s header or footer. Only people with a login (below) can open it.
				</p>
				<div className="ls-portal-link">
					<div>
						<div className="ls-block__label">Page name</div>
						<b className="ls-portal-link__title">{ data.title || 'Client Portal' }</b>
						<div className="ls-block__label ls-mt">Link</div>
						<div className="ls-portal-link__url">
							<a href={ data.url } target="_blank" rel="noreferrer">
								{ data.url }
							</a>
						</div>
					</div>
					<div className="ls-row">
						<Button onClick={ () => flashCopy( 'link', data.url ) }>{ 'link' === copied ? 'Copied ✓' : 'Copy link' }</Button>
						<a className="ls-btn ls-btn--primary" href={ data.url } target="_blank" rel="noreferrer">
							Open dashboard ↗
						</a>
					</div>
				</div>
				<p className="ls-muted ls-tiny-note">The page never opens without a Login ID &amp; password – not even for WordPress admins. Create a login for yourself below too.</p>
			</div>

			{ /* 2. Logins */ }
			<div className="ls-panel">
				<h3 className="ls-h3">Login ID &amp; password for the page</h3>

				{ error && <div className="ls-notice ls-notice--error">{ error }</div> }

				{ created && (
					<div className="ls-created">
						<b>Saved.</b> Send these details to { created.name || created.username }. The password is shown only now.
						<pre>{ details( created ) }</pre>
						<div className="ls-row">
							<Button variant="primary" onClick={ () => flashCopy( 'details', details( created ) ) }>
								{ 'details' === copied ? 'Copied ✓' : 'Copy login details' }
							</Button>
							<Button onClick={ () => setCreated( null ) }>Done</Button>
						</div>
					</div>
				) }

				<form className="ls-add-login" onSubmit={ add }>
					<div className="ls-add-login__grid">
						<label className="ls-field">
							<span>Login ID</span>
							<input className="ls-input" placeholder="e.g. maeve" autoComplete="off" required value={ form.username } onChange={ ( e ) => setForm( { ...form, username: e.target.value } ) } />
						</label>
						<label className="ls-field">
							<span>Password</span>
							<input className="ls-input" placeholder="At least 8 characters – or leave empty to generate" autoComplete="new-password" value={ form.password } onChange={ ( e ) => setForm( { ...form, password: e.target.value } ) } />
						</label>
						<label className="ls-field">
							<span>Name (optional)</span>
							<input className="ls-input" placeholder="e.g. Salon owner" value={ form.name } onChange={ ( e ) => setForm( { ...form, name: e.target.value } ) } />
						</label>
						<label className="ls-field">
							<span>Access</span>
							<select className="ls-input" value={ form.access } onChange={ ( e ) => setForm( { ...form, access: e.target.value } ) }>
								<option value="manage">Manage – see everything, change form setup &amp; settings</option>
								<option value="view">View only – see everything, change nothing</option>
							</select>
						</label>
					</div>
					<p className="ls-muted ls-tiny-note">Every login sees the whole dashboard. Rules, the AI key and logins can only be changed here in WordPress.</p>
					<Button variant="primary" type="submit" disabled={ busy }>
						{ busy ? 'Saving…' : 'Create login' }
					</Button>
				</form>

				<div className="ls-block__label ls-mt">Logins ({ data.users.length })</div>
				{ data.users.length === 0 ? (
					<Empty title="No logins yet">Create one above, then send the link and the login details to whoever should see the dashboard.</Empty>
				) : (
					<div className="ls-table-wrap">
						<table className="ls-table ls-logins">
							<thead>
								<tr>
									<th>Login ID</th>
									<th className="ls-hide-sm">Name</th>
									<th>Access</th>
									<th className="ls-hide-sm">Last sign-in</th>
									<th />
								</tr>
							</thead>
							<tbody>
								{ data.users.map( ( u ) => (
									<tr key={ u.id }>
										<td>
											<b>{ u.username }</b>
											{ changing === u.id && (
												<div className="ls-change-pass">
													<input className="ls-input" placeholder="New password (empty = generate)" autoComplete="new-password" value={ newPass } onChange={ ( e ) => setNewPass( e.target.value ) } />
													<Button variant="primary" onClick={ () => savePassword( u ) }>
														Save
													</Button>
													<Button
														onClick={ () => {
															setChanging( null );
															setNewPass( '' );
														} }
													>
														Cancel
													</Button>
												</div>
											) }
										</td>
										<td className="ls-hide-sm">{ u.name }</td>
										<td>
											<select className="ls-input ls-input--sm" value={ u.access } onChange={ ( e ) => setAccess( u, e.target.value ) }>
												<option value="manage">Manage</option>
												<option value="view">View only</option>
											</select>
										</td>
										<td className="ls-hide-sm ls-muted">{ u.last_login || 'Never' }</td>
										<td className="ls-logins__actions">
											<Button
												onClick={ () => {
													setChanging( u.id );
													setNewPass( '' );
												} }
											>
												Change password
											</Button>
											<Button variant="danger" onClick={ () => remove( u ) }>
												Delete
											</Button>
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) }
			</div>
		</section>
	);
}

/**
 * Settings → Earlier form entries: import and filter entries received before LeadSift.
 */
export function ImportPanel() {
	const [ st, setSt ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	useEffect( () => {
		api( 'import' ).then( setSt ).catch( () => {} );
	}, [] );

	// While an import is running, keep it moving and show progress.
	const running = st && 'running' === st.status;
	useEffect( () => {
		if ( ! running ) {
			return undefined;
		}
		let alive = true;
		const step = () =>
			api( 'import/step', { method: 'POST' } )
				.then( ( next ) => {
					if ( alive ) {
						setSt( next );
						if ( 'running' === next.status ) {
							setTimeout( step, 600 );
						}
					}
				} )
				.catch( () => alive && setTimeout( step, 4000 ) );
		step();
		return () => {
			alive = false;
		};
	}, [ running ] );

	const start = () => {
		setBusy( true );
		api( 'import', { method: 'POST', data: { restart: false } } )
			.then( setSt )
			.finally( () => setBusy( false ) );
	};

	const pct = st && st.total ? Math.min( 100, Math.round( ( ( st.imported + st.skipped ) / st.total ) * 100 ) ) : 0;

	return (
		<div className="ls-panel">
			<h3 className="ls-h3">Earlier form entries</h3>
			<p className="ls-muted">
				LeadSift also imports and filters the entries your form plugins stored before it was installed (WPForms Pro, Gravity Forms, Fluent Forms, Ninja Forms, Formidable, Elementor, Contact Form 7 via Flamingo, and the “Contact Form Entries” plugin). Nothing is imported twice and no emails are sent.
			</p>
			{ ! st && <Spinner /> }
			{ st && (
				<>
					{ st.found.length > 0 ? (
						<ul className="ls-import-found">
							{ st.found.map( ( f ) => (
								<li key={ f.reader }>
									{ f.label }: <b>{ f.count }</b>
								</li>
							) ) }
						</ul>
					) : (
						<p className="ls-muted">No stored entries were found in your form plugins.</p>
					) }
					{ running && (
						<div className="ls-import-bar">
							<b>Importing…</b> { st.imported + st.skipped } of { st.total } checked · { st.imported } added
							<div className="ls-import-bar__track">
								<div style={ { width: `${ pct }%` } } />
							</div>
						</div>
					) }
					{ 'done' === st.status && st.total > 0 && (
						<p>
							Done: <b>{ st.imported }</b> imported and filtered, { st.skipped } skipped (already in LeadSift or empty).
						</p>
					) }
					{ perms.canEdit && (
						<Button onClick={ start } disabled={ busy || running }>
							{ running ? 'Importing…' : 'Look for earlier entries again' }
						</Button>
					) }
				</>
			) }
		</div>
	);
}
