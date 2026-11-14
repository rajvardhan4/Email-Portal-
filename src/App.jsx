import { useState, useEffect, useCallback, useRef } from '@wordpress/element';
import { api, cfg, CATEGORIES, isPortal } from './api';
import { Logo, CategoryIcon } from './ui';
import Inquiries from './Inquiries';
import Insights from './Insights';
import Forms from './Forms';
import Settings from './Settings';
import AiUsage from './AiUsage';
import Guide from './Guide';

const LIVE_EVERY = 10000; // ms between live checks.

// The dashboard (shown only on the Client Portal page). wp-admin only has setup (AdminApp).
const TABS = [
	[ 'inquiries', 'Inquiries' ],
	[ 'forms', 'Forms' ],
	[ 'insights', 'Insights' ],
	[ 'usage', 'AI usage' ],
	[ 'settings', 'Settings' ],
	[ 'guide', 'How it works' ],
];

export default function App( { portalUser = null, onLogout = null } ) {
	const tabs = TABS;
	const [ tab, setTabState ] = useState( () => {
		const h = ( window.location.hash || '' ).replace( '#', '' );
		return TABS.some( ( [ k ] ) => k === h ) ? h : 'inquiries';
	} );
	const setTab = ( t ) => {
		setTabState( t );
		try {
			window.history.replaceState( null, '', '#' + t );
		} catch ( e ) {}
		window.scrollTo( 0, 0 );
	};
	const [ stats, setStats ] = useState( null );
	const [ category, setCategory ] = useState( 'all' );
	// '' = all forms, 'src|cf7' = one form plugin, 'form|cf7:12' = one form.
	const [ formFilter, setFormFilter ] = useState( '' );

	const refreshStats = useCallback( () => {
		api( 'stats', { query: { days: 30 } } )
			.then( setStats )
			.catch( () => {} );
	}, [] );

	useEffect( () => {
		refreshStats();
	}, [ refreshStats ] );

	// ---- Live updates: a tiny check every 10 s (and when you come back to the tab). ----
	const [ liveTick, setLiveTick ] = useState( 0 );
	const [ newSince, setNewSince ] = useState( 0 );
	const [ toast, setToast ] = useState( '' );
	const [ lastSync, setLastSync ] = useState( 0 );
	const [ , setClock ] = useState( 0 );
	const pulse = useRef( null );
	const toastTimer = useRef();

	const checkPulse = useCallback( () => {
		if ( typeof document !== 'undefined' && document.visibilityState === 'hidden' ) {
			return;
		}
		api( 'pulse' )
			.then( ( p ) => {
				const prev = pulse.current;
				pulse.current = p;
				setLastSync( Date.now() );
				if ( ! prev || prev.signature === p.signature ) {
					return;
				}
				refreshStats();
				setLiveTick( ( t ) => t + 1 );
				if ( p.latest_id > prev.latest_id ) {
					const n = Math.max( 1, p.total - prev.total );
					setNewSince( prev.latest_id );
					setToast( `${ n } new inquir${ n === 1 ? 'y' : 'ies' } just arrived` );
					clearTimeout( toastTimer.current );
					toastTimer.current = setTimeout( () => setToast( '' ), 6000 );
				}
			} )
			.catch( () => {} );
	}, [ refreshStats ] );

	useEffect( () => {
		checkPulse();
		const every = setInterval( checkPulse, LIVE_EVERY );
		const clock = setInterval( () => setClock( ( c ) => c + 1 ), 5000 );
		const onBack = () => document.visibilityState === 'visible' && checkPulse();
		document.addEventListener( 'visibilitychange', onBack );
		window.addEventListener( 'focus', onBack );
		return () => {
			clearInterval( every );
			clearInterval( clock );
			document.removeEventListener( 'visibilitychange', onBack );
			window.removeEventListener( 'focus', onBack );
		};
	}, [ checkPulse ] );

	// ---- Import of earlier entries: push it along while the dashboard is open. ----
	const importState = stats && stats.import;
	const importing = importState && 'running' === importState.status;
	useEffect( () => {
		if ( ! importing ) {
			return undefined;
		}
		let alive = true;
		const run = () => {
			api( 'import/step', { method: 'POST' } )
				.then( ( st ) => {
					if ( ! alive ) {
						return;
					}
					setStats( ( prev ) => ( prev ? { ...prev, import: st } : prev ) );
					if ( 'running' === st.status ) {
						setTimeout( run, 800 );
					} else {
						refreshStats();
						setLiveTick( ( t ) => t + 1 );
					}
				} )
				.catch( () => alive && setTimeout( run, 5000 ) );
		};
		run();
		return () => {
			alive = false;
		};
	}, [ importing ] ); // eslint-disable-line react-hooks/exhaustive-deps

	const secondsAgo = lastSync ? Math.round( ( Date.now() - lastSync ) / 1000 ) : null;

	const openCategory = ( cat ) => {
		setCategory( cat );
		setTab( 'inquiries' );
	};

	return (
		<div className={ `ls-app ls-app--${ cfg.context || 'admin' }` }>
			{ isPortal && portalUser && (
				<div className="ls-portal-bar">
					<span className="ls-portal-bar__site">{ cfg.siteName } · Client Portal</span>
					<span className="ls-portal-bar__user">
						<span className="ls-avatar ls-avatar--sm">{ ( portalUser.name || '?' ).charAt( 0 ).toUpperCase() }</span>
						<span className="ls-portal-bar__name">
							{ portalUser.name }
							<small className="ls-portal-bar__role">{ portalUser.manage ? 'Manage' : 'View only' }</small>
						</span>
						{ onLogout && (
							<button type="button" className="ls-btn ls-btn--default ls-btn--sm" onClick={ onLogout }>
								Sign out
							</button>
						) }
					</span>
				</div>
			) }
			<header className="ls-header">
				<div className="ls-brand">
					<Logo />
					<div>
						<h1>LeadSift</h1>
						<p>Smart Inquiry Filter</p>
					</div>
					{ ! isPortal && <span className="ls-version">v{ cfg.version }</span> }
					<button type="button" className={ `ls-live${ secondsAgo !== null && secondsAgo < 30 ? ' is-on' : '' }` } onClick={ checkPulse } title="Dashboard checks for new inquiries every 10 seconds. Click to check now.">
						<i />
						{ secondsAgo === null ? 'Connecting…' : `Live · ${ secondsAgo < 5 ? 'just now' : secondsAgo + 's ago' }` }
					</button>
				</div>
				<nav className="ls-tabs" role="tablist">
					{ tabs.map( ( [ key, label ] ) => (
						<button
							key={ key }
							type="button"
							role="tab"
							aria-selected={ tab === key }
							className={ tab === key ? 'is-active' : '' }
							onClick={ () => setTab( key ) }
						>
							{ label }
						</button>
					) ) }
				</nav>
			</header>

			{ toast && (
				<button type="button" className="ls-toast" onClick={ () => { setToast( '' ); setCategory( 'all' ); setTab( 'inquiries' ); } }>
					🔔 { toast } – <u>view</u>
				</button>
			) }

			{ importState && importState.total > 0 && 'running' === importState.status && (
				<div className="ls-import-bar">
					<div className="ls-import-bar__text">
						<b>Importing earlier form entries…</b> { importState.imported + importState.skipped } of { importState.total } checked · { importState.imported } added and filtered
					</div>
					<div className="ls-import-bar__track">
						<div style={ { width: `${ Math.min( 100, Math.round( ( ( importState.imported + importState.skipped ) / importState.total ) * 100 ) ) }%` } } />
					</div>
				</div>
			) }

			{ [ 'inquiries', 'forms', 'insights' ].includes( tab ) && <StatCards stats={ stats } onPick={ openCategory } active={ tab === 'inquiries' ? category : null } /> }

			{ tab === 'inquiries' && (
				<Inquiries category={ category } setCategory={ setCategory } formFilter={ formFilter } setFormFilter={ setFormFilter } stats={ stats } onChange={ refreshStats } liveTick={ liveTick } newSince={ newSince } />
			) }
			{ tab === 'forms' && (
				<Forms
					onOpen={ ( f ) => {
						setFormFilter( `form|${ f.key }` );
						setCategory( 'all' );
						setTab( 'inquiries' );
					} }
				/>
			) }
			{ tab === 'insights' && <Insights stats={ stats } /> }
			{ tab === 'usage' && <AiUsage liveTick={ liveTick } /> }
			{ tab === 'settings' && (
				<Settings
					onSaved={ () => {
						refreshStats();
						setLiveTick( ( t ) => t + 1 );
					} }
				/>
			) }
			{ tab === 'guide' && <Guide onTab={ setTab } /> }

			<footer className="ls-footer">
				LeadSift v{ cfg.version } · Built by { cfg.author || 'Rajvardhan Singh Rathore' }
				{ ! isPortal && cfg.portalUrl && (
					<>
						{ ' · ' }
						<a href={ cfg.portalUrl } target="_blank" rel="noreferrer">
							Open Client Portal
						</a>
					</>
				) }
			</footer>
		</div>
	);
}

function StatCards( { stats, onPick, active } ) {
	const t = stats ? stats.totals : null;
	const total = t ? t.genuine.total + t.promotional.total + t.spam.total : 0;
	const pct = ( n ) => ( total ? Math.round( ( n / total ) * 100 ) : 0 );

	return (
		<div className="ls-cards">
			<button type="button" className={ `ls-card ls-card--all${ active === 'all' ? ' is-active' : '' }` } onClick={ () => onPick( 'all' ) }>
				<span className="ls-card__label">All inquiries</span>
				<span className="ls-card__value">{ t ? total : '–' }</span>
				<span className="ls-card__sub">Everything captured from your forms</span>
			</button>
			{ Object.keys( CATEGORIES ).map( ( key ) => (
				<button key={ key } type="button" className={ `ls-card ls-card--${ key }${ active === key ? ' is-active' : '' }` } onClick={ () => onPick( key ) }>
					<span className="ls-card__label">
						<span className={ `ls-dot ls-dot--${ key }` }>
							<CategoryIcon category={ key } />
						</span>
						{ CATEGORIES[ key ].label }
					</span>
					<span className="ls-card__value">{ t ? t[ key ].total : '–' }</span>
					<span className="ls-card__sub">
						{ t ? `${ pct( t[ key ].total ) }% of total` : CATEGORIES[ key ].hint }
						{ t && key === 'genuine' && t.genuine.unread > 0 && <b className="ls-unread-pill">{ t.genuine.unread } new</b> }
					</span>
				</button>
			) ) }
		</div>
	);
}
