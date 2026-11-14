import { useState } from '@wordpress/element';
import { Button } from './ui';

export const FIELDS = [
	[ 'any', 'Any field' ],
	[ 'message', 'Message' ],
	[ 'subject', 'Subject' ],
	[ 'name', 'Name' ],
	[ 'email', 'Email' ],
	[ 'email_domain', 'Email domain' ],
	[ 'phone', 'Phone' ],
	[ 'form', 'Form name' ],
	[ 'form_type', 'Form type (contact, quote, booking, job, newsletter, support)' ],
	[ 'source', 'Form plugin' ],
];
export const OPERATORS = [
	[ 'contains', 'contains' ],
	[ 'not_contains', 'does not contain' ],
	[ 'equals', 'is exactly' ],
	[ 'starts_with', 'starts with' ],
	[ 'ends_with', 'ends with' ],
	[ 'regex', 'matches pattern (regex)' ],
	[ 'is_empty', 'is empty' ],
];
export const ACTIONS = [
	[ 'set_genuine', 'Always Genuine' ],
	[ 'set_promotional', 'Always Promotional' ],
	[ 'set_spam', 'Always Spam' ],
	[ 'score_genuine', 'Lean Genuine' ],
	[ 'score_promotional', 'Lean Promotional' ],
	[ 'score_spam', 'Lean Spam' ],
];

/* Simple "If the inquiry … then …" choices and how they map to a rule. */
const KINDS = [
	[ 'mentions', 'mentions any of these words', { field: 'any', operator: 'contains' } ],
	[ 'not_mentions', 'does NOT mention any of these words', { field: 'any', operator: 'not_contains' } ],
	[ 'email', 'comes from this email / domain', { field: 'email', operator: 'ends_with' } ],
	[ 'form', 'comes from a form named', { field: 'form', operator: 'contains' } ],
];

const CATEGORY_WORDS = {
	genuine: [ 'genuine', 'real', 'customer', 'lead', 'good', 'valid', 'asli', 'sahi' ],
	promotional: [ 'promotional', 'promo', 'promotion', 'marketing', 'sales', 'ad', 'ads', 'advert', 'advertisement', 'pitch', 'seller' ],
	spam: [ 'spam', 'junk', 'bot', 'fake', 'scam', 'fraud', 'bekar' ],
};

export const newRule = ( base = {} ) => ( {
	id: 'r' + Math.random().toString( 36 ).slice( 2, 10 ),
	enabled: true,
	name: '',
	field: 'any',
	operator: 'contains',
	value: '',
	action: 'set_promotional',
	points: 25,
	...base,
} );

const kindOf = ( r ) => {
	const k = KINDS.find( ( [ , , m ] ) => m.field === r.field && m.operator === r.operator );
	return k ? k[ 0 ] : 'custom';
};

/** "If the inquiry mentions “seo” or “backlinks” → always Promotional" */
export function sentence( r ) {
	const where = {
		any: 'the inquiry',
		message: 'the message',
		subject: 'the subject',
		name: 'the name',
		email: 'the email',
		email_domain: 'the email domain',
		phone: 'the phone',
		form: 'the form name',
		form_type: 'the form type',
		source: 'the form plugin',
	}[ r.field ] || r.field;
	const verb = {
		contains: 'any' === r.field ? 'mentions' : 'contains',
		not_contains: 'any' === r.field ? 'does not mention' : 'does not contain',
		equals: 'is',
		starts_with: 'starts with',
		ends_with: 'email' === r.field ? 'is / ends with' : 'ends with',
		regex: 'matches',
		is_empty: 'is empty',
	}[ r.operator ] || r.operator;
	const words = 'is_empty' === r.operator ? '' : ( r.value || '' ).split( ',' ).map( ( w ) => w.trim() ).filter( Boolean ).map( ( w ) => `“${ w }”` ).join( ' or ' );
	const [ mode, cat ] = String( r.action ).split( '_' );
	const label = cat ? cat.charAt( 0 ).toUpperCase() + cat.slice( 1 ) : '';
	const then = 'set' === mode ? `always ${ label }` : `lean ${ label } (${ 'genuine' === cat ? '−' : '+' }${ r.points })`;
	return { cond: `If ${ where } ${ verb } ${ words }`.trim(), then, cat };
}

/**
 * Turns a typed rule into a rule object.
 *   "seo, backlinks → promotional"
 *   "lean: discount, offer = promotional"
 *   "@mailinator.com → spam"
 *   "form: careers → genuine"
 *   "not: price, rate → promotional"
 */
export function parseRule( text ) {
	const raw = String( text || '' ).trim();
	let idx = -1;
	let sep = '';
	for ( const s of [ '→', '=>', '->', '=' ] ) {
		const i = raw.lastIndexOf( s );
		if ( i > idx ) {
			idx = i;
			sep = s;
		}
	}
	if ( idx < 0 ) {
		return { error: 'Add → (or =) and a category at the end, e.g.  seo, backlinks → promotional' };
	}
	let left = raw.slice( 0, idx ).trim();
	let right = raw.slice( idx + sep.length ).trim().toLowerCase();

	let mode = 'set';
	if ( /^(lean|maybe|soft)\b/.test( right ) ) {
		mode = 'score';
		right = right.replace( /^(lean|maybe|soft)\s*/, '' );
	}
	const cat = Object.keys( CATEGORY_WORDS ).find( ( c ) => CATEGORY_WORDS[ c ].some( ( w ) => right === w || right.startsWith( w + ' ' ) ) );
	if ( ! cat ) {
		return { error: 'After the arrow write Genuine, Promotional or Spam.' };
	}

	let field = 'any';
	let operator = 'contains';
	for ( let guard = 0; guard < 4; guard++ ) {
		const m = left.match( /^(lean|maybe|not|form|email|from)\s*:\s*/i );
		if ( ! m ) {
			break;
		}
		const p = m[ 1 ].toLowerCase();
		if ( 'lean' === p || 'maybe' === p ) {
			mode = 'score';
		} else if ( 'not' === p ) {
			operator = 'not_contains';
		} else if ( 'form' === p ) {
			field = 'form';
		} else {
			field = 'email';
			operator = 'ends_with';
		}
		left = left.slice( m[ 0 ].length );
	}
	const values = left.split( ',' ).map( ( w ) => w.trim() ).filter( Boolean );
	if ( ! values.length ) {
		return { error: 'Write at least one word before the arrow.' };
	}
	if ( 'any' === field && values.every( ( v ) => v.startsWith( '@' ) || /^[^\s@]+@[^\s@]+$/.test( v ) ) ) {
		field = 'email';
		operator = 'ends_with';
	}
	return {
		rule: newRule( {
			field,
			operator,
			value: values.join( ', ' ),
			action: `${ mode }_${ cat }`,
			points: 25,
		} ),
	};
}

const QUICK_EXAMPLES = [ 'seo, backlinks, guest post → promotional', 'wedding, bridal, bulk order → genuine', '@mailinator.com, @tempmail.com → spam', 'form: careers → genuine', 'lean: discount, special offer → promotional' ];

/**
 * "My rules": type a rule in plain words, or edit it with two dropdowns.
 */
export function MyRules( { rules, setRules } ) {
	const [ text, setText ] = useState( '' );
	const [ editing, setEditing ] = useState( null );
	const parsed = text.trim() ? parseRule( text ) : null;

	const add = () => {
		if ( parsed && parsed.rule ) {
			setRules( [ ...rules, parsed.rule ] );
			setText( '' );
		}
	};
	const update = ( id, patch ) => setRules( rules.map( ( r ) => ( r.id === id ? { ...r, ...patch } : r ) ) );
	const remove = ( id ) => setRules( rules.filter( ( r ) => r.id !== id ) );
	const move = ( i, dir ) => {
		const next = [ ...rules ];
		const j = i + dir;
		if ( j < 0 || j >= next.length ) {
			return;
		}
		[ next[ i ], next[ j ] ] = [ next[ j ], next[ i ] ];
		setRules( next );
	};

	return (
		<div className="ls-panel">
			<h3 className="ls-h3">My rules</h3>
			<p className="ls-muted">
				Write a rule the way you would say it: some words, an arrow, and where those inquiries should go. Your rules are checked first. <b>Always</b> decides on the spot; <b>Lean</b> only nudges the score.
			</p>

			<div className="ls-quick-rule">
				<input
					className="ls-input ls-grow"
					placeholder="Type a rule, e.g.  seo, backlinks → promotional"
					value={ text }
					onChange={ ( e ) => setText( e.target.value ) }
					onKeyDown={ ( e ) => {
						if ( 'Enter' === e.key ) {
							e.preventDefault();
							add();
						}
					} }
				/>
				<Button variant="primary" onClick={ add } disabled={ ! parsed || ! parsed.rule }>
					Add rule
				</Button>
			</div>
			{ parsed && parsed.rule && (
				<div className="ls-quick-preview">
					✓ { sentence( parsed.rule ).cond } → <b>{ sentence( parsed.rule ).then }</b>
				</div>
			) }
			{ parsed && parsed.error && <div className="ls-quick-preview ls-quick-preview--error">{ parsed.error }</div> }
			<div className="ls-quick-examples">
				<span className="ls-muted">Try:</span>
				{ QUICK_EXAMPLES.map( ( ex ) => (
					<button key={ ex } type="button" className="ls-chip" onClick={ () => setText( ex ) }>
						{ ex }
					</button>
				) ) }
			</div>

			{ rules.length === 0 && <p className="ls-muted ls-rules-empty">No rules yet.</p> }

			<ul className="ls-simple-rules">
				{ rules.map( ( r, i ) => {
					const s = sentence( r );
					const open = editing === r.id;
					return (
						<li key={ r.id } className={ `ls-simple-rule${ r.enabled ? '' : ' is-off' }${ r.invalid ? ' is-invalid' : '' }` }>
							<div className="ls-simple-rule__row">
								<label className="ls-switch" title={ r.enabled ? 'Rule is on' : 'Rule is off' }>
									<input type="checkbox" checked={ !! r.enabled } onChange={ ( e ) => update( r.id, { enabled: e.target.checked } ) } />
									<span />
								</label>
								<div className="ls-simple-rule__text">
									{ r.name && <b className="ls-simple-rule__name">{ r.name.replace( /^Auto:\s*/, '' ) }</b> }
									<span>
										{ s.cond } → <span className={ `ls-count ls-count--${ s.cat }` }>{ s.then }</span>
									</span>
									{ r.invalid && <span className="ls-rule__error">This rule is skipped – please fix it.</span> }
								</div>
								<span className="ls-rule__tools">
									<button type="button" className="ls-icon-btn" onClick={ () => move( i, -1 ) } disabled={ i === 0 } aria-label="Move up">
										↑
									</button>
									<button type="button" className="ls-icon-btn" onClick={ () => move( i, 1 ) } disabled={ i === rules.length - 1 } aria-label="Move down">
										↓
									</button>
									<button type="button" className="ls-icon-btn" onClick={ () => setEditing( open ? null : r.id ) } aria-label="Edit rule">
										✎
									</button>
									<button type="button" className="ls-icon-btn ls-icon-btn--danger" onClick={ () => remove( r.id ) } aria-label="Delete rule">
										✕
									</button>
								</span>
							</div>
							{ open && <RuleEditor rule={ r } onChange={ ( patch ) => update( r.id, { ...patch, invalid: false } ) } onDone={ () => setEditing( null ) } /> }
						</li>
					);
				} ) }
			</ul>

			<Button
				onClick={ () => {
					const r = newRule();
					setRules( [ ...rules, r ] );
					setEditing( r.id );
				} }
			>
				+ Add rule with dropdowns
			</Button>
		</div>
	);
}

function RuleEditor( { rule, onChange, onDone } ) {
	const kind = kindOf( rule );
	const [ advanced, setAdvanced ] = useState( 'custom' === kind );

	return (
		<div className="ls-rule-editor">
			<div className="ls-rule-editor__line">
				<span className="ls-rule__word">If the inquiry</span>
				<select
					className="ls-input"
					value={ 'custom' === kind ? '' : kind }
					onChange={ ( e ) => {
						const k = KINDS.find( ( x ) => x[ 0 ] === e.target.value );
						if ( k ) {
							onChange( k[ 2 ] );
						}
					} }
				>
					{ 'custom' === kind && <option value="">(advanced rule)</option> }
					{ KINDS.map( ( [ v, l ] ) => (
						<option key={ v } value={ v }>
							{ l }
						</option>
					) ) }
				</select>
			</div>
			<input
				className="ls-input ls-rule-editor__words"
				placeholder={ 'email' === kind ? '@example.com, someone@gmail.com' : 'word, another word, a short phrase' }
				value={ rule.value }
				onChange={ ( e ) => onChange( { value: e.target.value } ) }
			/>
			<div className="ls-rule-editor__line">
				<span className="ls-rule__word">then put it in</span>
				<select className="ls-input" value={ rule.action } onChange={ ( e ) => onChange( { action: e.target.value } ) }>
					{ ACTIONS.map( ( [ v, l ] ) => (
						<option key={ v } value={ v }>
							{ l }
						</option>
					) ) }
				</select>
			</div>

			<button type="button" className="ls-link" onClick={ () => setAdvanced( ! advanced ) }>
				{ advanced ? 'Hide advanced options' : 'Advanced options' }
			</button>
			{ advanced && (
				<div className="ls-rule-editor__advanced">
					<label className="ls-field">
						<span>Look in</span>
						<select className="ls-input" value={ rule.field } onChange={ ( e ) => onChange( { field: e.target.value } ) }>
							{ FIELDS.map( ( [ v, l ] ) => (
								<option key={ v } value={ v }>
									{ l }
								</option>
							) ) }
						</select>
					</label>
					<label className="ls-field">
						<span>Condition</span>
						<select className="ls-input" value={ rule.operator } onChange={ ( e ) => onChange( { operator: e.target.value } ) }>
							{ OPERATORS.map( ( [ v, l ] ) => (
								<option key={ v } value={ v }>
									{ l }
								</option>
							) ) }
						</select>
					</label>
					{ String( rule.action ).startsWith( 'score_' ) && (
						<label className="ls-field">
							<span>Points</span>
							<input type="number" min="0" max="100" className="ls-input ls-input--num" value={ rule.points } onChange={ ( e ) => onChange( { points: e.target.value } ) } />
						</label>
					) }
					<label className="ls-field">
						<span>Name (optional)</span>
						<input className="ls-input" value={ rule.name } onChange={ ( e ) => onChange( { name: e.target.value } ) } />
					</label>
				</div>
			) }
			<div>
				<Button variant="primary" onClick={ onDone }>
					Done
				</Button>
			</div>
		</div>
	);
}
