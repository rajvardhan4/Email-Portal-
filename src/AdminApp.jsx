import { useState } from '@wordpress/element';
import { cfg } from './api';
import { Logo } from './ui';
import { PortalAdmin } from './PortalAdmin';
import RulesAI from './RulesAI';
import { Developers } from './Settings';

/**
 * wp-admin → LeadSift: SETUP ONLY – the dashboard page's link and logins, rules and the AI.
 * Everything else (inquiries, forms, insights, AI usage, settings) is on the dashboard page.
 */
const TABS = [
	[ 'portal', 'Dashboard page & login' ],
	[ 'rules', 'Rules & AI' ],
];

export default function AdminApp() {
	const start = TABS.some( ( [ k ] ) => k === cfg.startTab ) ? cfg.startTab : 'portal';
	const [ tab, setTab ] = useState( start );

	return (
		<div className="ls-app ls-app--admin ls-setup">
			<header className="ls-header">
				<div className="ls-brand">
					<Logo />
					<div>
						<h1>LeadSift</h1>
						<p>Setup</p>
					</div>
					<span className="ls-version">v{ cfg.version }</span>
				</div>
				{ cfg.portalUrl && (
					<a className="ls-btn ls-btn--primary ls-open-dashboard" href={ cfg.portalUrl } target="_blank" rel="noreferrer">
						Open dashboard ↗
					</a>
				) }
			</header>

			<div className="ls-setup-summary">
				<span className="ls-muted">
					Here you only set things up. Inquiries, forms, insights, AI usage and settings are on the <b>dashboard page</b> – it opens with a Login ID &amp; password.
				</span>
			</div>

			<nav className="ls-tabs ls-setup-tabs" role="tablist">
				{ TABS.map( ( [ key, label ] ) => (
					<button key={ key } type="button" role="tab" aria-selected={ tab === key } className={ tab === key ? 'is-active' : '' } onClick={ () => setTab( key ) }>
						{ label }
					</button>
				) ) }
			</nav>

			{ tab === 'portal' && <PortalAdmin /> }
			{ tab === 'rules' && (
				<>
					<RulesAI onSaved={ () => {} } />
					<Developers />
				</>
			) }

			<footer className="ls-footer">LeadSift v{ cfg.version } · Built by { cfg.author || 'Rajvardhan Singh Rathore' }</footer>
		</div>
	);
}
