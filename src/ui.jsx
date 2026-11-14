import { CATEGORIES } from './api';

const ICONS = {
	genuine: 'M5 10.5l3 3 7-7',
	promotional: 'M4 8v4h3l5 4V4L7 8H4zm11 0a3 3 0 010 4',
	spam: 'M6 6l8 8M14 6l-8 8',
};

export function CategoryIcon( { category } ) {
	return (
		<svg className="ls-icon" viewBox="0 0 20 20" aria-hidden="true">
			<path d={ ICONS[ category ] || ICONS.genuine } fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" />
		</svg>
	);
}

export function CategoryBadge( { category, manual } ) {
	const meta = CATEGORIES[ category ] || { label: category };
	return (
		<span className={ `ls-badge ls-badge--${ category }` } title={ manual ? 'Set manually by you' : meta.hint }>
			<CategoryIcon category={ category } />
			{ meta.label }
			{ manual && <span className="ls-badge__manual">✎</span> }
		</span>
	);
}

export function ScoreMeter( { score, compact } ) {
	const level = score >= 60 ? 'high' : score >= 30 ? 'mid' : 'low';
	return (
		<div className={ `ls-score ls-score--${ level }${ compact ? ' ls-score--compact' : '' }` } title={ `Risk score ${ score } / 100` }>
			<div className="ls-score__track">
				<div className="ls-score__fill" style={ { width: `${ Math.max( 3, score ) }%` } } />
			</div>
			<span className="ls-score__num">{ score }</span>
		</div>
	);
}

/** One line of "why": +points push to promotional/spam, −points to genuine. */
export function Reason( { r } ) {
	const kind = r.type === 'ai' ? 'ai' : r.type === 'rule' || r.type === 'manual' ? 'rule' : r.weight > 0 ? 'bad' : r.weight < 0 ? 'good' : 'info';
	const tag = { ai: 'AI', rule: 'Rule' }[ kind ];
	let weight = '•';
	if ( tag ) {
		weight = tag;
	} else if ( r.weight > 0 ) {
		weight = `+${ r.weight }`;
	} else if ( r.weight < 0 ) {
		weight = r.weight;
	}
	return (
		<li className={ `ls-reason ls-reason--${ kind }` }>
			<span className="ls-reason__weight">{ weight }</span>
			{ r.label }
		</li>
	);
}

export function Button( { variant = 'default', children, ...props } ) {
	return (
		<button type="button" className={ `ls-btn ls-btn--${ variant }` } { ...props }>
			{ children }
		</button>
	);
}

export function Spinner() {
	return <span className="ls-spinner" aria-label="Loading" />;
}

export function Empty( { title, children } ) {
	return (
		<div className="ls-empty">
			<svg viewBox="0 0 48 48" aria-hidden="true">
				<path d="M6 10h36l-14 16v12l-8 4V26z" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinejoin="round" />
			</svg>
			<strong>{ title }</strong>
			{ children && <p>{ children }</p> }
		</div>
	);
}

export function Logo() {
	return (
		<svg className="ls-logo" viewBox="0 0 40 40" aria-hidden="true">
			<rect width="40" height="40" rx="10" fill="#14213d" />
			<path d="M9 12h22l-8.5 10v7l-5 3V22z" fill="none" stroke="#fff" strokeWidth="2.4" strokeLinejoin="round" />
			<circle cx="29" cy="28" r="4.5" fill="#0e8f7e" stroke="#14213d" strokeWidth="2" />
		</svg>
	);
}
