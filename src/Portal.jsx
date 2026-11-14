import { useState, useEffect } from '@wordpress/element';
import { api, cfg, setToken, getToken, perms } from './api';
import { Logo, Spinner, Button } from './ui';
import App from './App';

/**
 * Client Portal: sign-in screen, then the dashboard full-screen.
 * Everyone – WordPress admins too – needs a Login ID & password.
 */
export default function Portal() {
	const [ user, setUser ] = useState( null );
	const [ checking, setChecking ] = useState( true );

	useEffect( () => {
		if ( ! getToken() ) {
			setChecking( false );
		} else {
			api( 'portal/me' )
				.then( setUser )
				.catch( () => {
					setToken( '' );
					setUser( null );
				} )
				.finally( () => setChecking( false ) );
		}

		const signedOut = () => {
			setToken( '' );
			setUser( null );
		};
		window.addEventListener( 'leadsift-signed-out', signedOut );
		return () => window.removeEventListener( 'leadsift-signed-out', signedOut );
	}, [] );

	const logout = () => {
		api( 'portal/logout', { method: 'POST' } ).catch( () => {} );
		setToken( '' );
		setUser( null );
	};

	if ( checking ) {
		return (
			<div className="ls-portal-center">
				<Spinner />
			</div>
		);
	}
	if ( ! user ) {
		return <Login onDone={ setUser } />;
	}
	perms.canEdit = !! user.manage;
	return (
		<div className="ls-portal">
			<App portalUser={ user } onLogout={ logout } />
		</div>
	);
}

function Login( { onDone } ) {
	const [ username, setUsername ] = useState( '' );
	const [ password, setPassword ] = useState( '' );
	const [ show, setShow ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const submit = ( e ) => {
		e.preventDefault();
		setBusy( true );
		setError( '' );
		api( 'portal/login', { method: 'POST', data: { username, password } } )
			.then( ( r ) => {
				setToken( r.token );
				onDone( { ...r.user, manage: 'manage' === r.user.access } );
			} )
			.catch( ( err ) => setError( err.message || 'Could not sign in.' ) )
			.finally( () => setBusy( false ) );
	};

	return (
		<div className="ls-portal-center ls-login-page">
			<form className="ls-login" onSubmit={ submit }>
				<div className="ls-login__brand">
					<Logo />
					<div>
						<div className="ls-login__site">{ cfg.siteName || 'Client Portal' }</div>
						<h1>Client Portal</h1>
					</div>
				</div>
				<p className="ls-muted">Sign in with your Login ID and password to see the inquiries from your website, sorted into Genuine, Promotional and Spam.</p>

				{ error && <div className="ls-notice ls-notice--error">{ error }</div> }

				<label className="ls-field">
					<span>Login ID</span>
					<input className="ls-input" autoComplete="username" autoCapitalize="none" spellCheck="false" value={ username } onChange={ ( e ) => setUsername( e.target.value ) } required />
				</label>
				<label className="ls-field">
					<span>Password</span>
					<div className="ls-row">
						<input className="ls-input ls-grow" type={ show ? 'text' : 'password' } autoComplete="current-password" value={ password } onChange={ ( e ) => setPassword( e.target.value ) } required />
						<button type="button" className="ls-icon-btn ls-show-pass" onClick={ () => setShow( ! show ) } aria-label={ show ? 'Hide password' : 'Show password' }>
							{ show ? 'Hide' : 'Show' }
						</button>
					</div>
				</label>
				<button type="submit" className="ls-btn ls-btn--primary ls-login__submit" disabled={ busy }>
					{ busy ? 'Signing in…' : 'Sign in' }
				</button>
				<p className="ls-login__help ls-muted">Forgot your login? Ask the person who manages your website.</p>
			</form>
			<p className="ls-login__foot">
				Powered by LeadSift · <a href={ cfg.siteUrl || '/' }>Back to website</a>
			</p>
		</div>
	);
}

/* Re-export for the "Sign out" button style. */
export { Button };
