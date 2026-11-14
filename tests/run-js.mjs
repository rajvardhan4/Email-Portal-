// LeadSift classifier test runner in Node (for machines without PHP).
//
//   node tests/run-js.mjs       – summary + every wrong verdict
//   node tests/run-js.mjs -v    – also print reasons for every case
//
// Every regex and rule weight is READ FROM includes/class-leadsift-classifier.php,
// so rule changes in PHP are tested automatically. The scoring logic below mirrors
// LeadSift_Classifier::classify() / quality_checks() / phone_problem() / is_mash() –
// keep them in sync when the PHP logic changes. tests/run.php runs the real PHP.
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname( fileURLToPath( import.meta.url ) );
const php = readFileSync( join( here, '../includes/class-leadsift-classifier.php' ), 'utf8' );
const verbose = process.argv.includes( '-v' );

// ---------- read rules from PHP ----------
const unq = ( s ) => s.replace( /\\'/g, "'" ).replace( /\\\\/g, '\\' );
const toJs = ( p ) => p.replace( /\\x\{([0-9A-Fa-f]+)\}/g, '\\u{$1}' );
const re = ( p, flags = 'iu' ) => new RegExp( toJs( p ), flags );

function body( fn ) {
	const start = php.indexOf( `function ${ fn }(` );
	if ( start < 0 ) throw new Error( 'missing ' + fn );
	const next = php.indexOf( '\n\t}\n', start );
	return php.slice( start, next );
}
function rulesOf( fn ) {
	const out = [];
	const r = /array\(\s*'((?:[^'\\]|\\.)*)',\s*(\d+),\s*'((?:[^'\\]|\\.)*)'\s*\)/g;
	let m;
	const b = body( fn );
	while ( ( m = r.exec( b ) ) ) out.push( [ re( unq( m[ 1 ] ) ), +m[ 2 ], unq( m[ 3 ] ) ] );
	return out;
}
function stringsOf( src, varName ) {
	const start = src.indexOf( `$${ varName } = array(` );
	const end = src.indexOf( '\n\t\t);', start );
	const chunk = src.slice( start, end );
	const out = [];
	const r = /'((?:[^'\\]|\\.)*)'/g;
	let m;
	while ( ( m = r.exec( chunk ) ) ) out.push( re( unq( m[ 1 ] ) ) );
	return out;
}
function singleString( src, varName ) {
	const m = new RegExp( '\\$' + varName + "\\s*=\\s*'((?:[^'\\\\]|\\\\.)*)';" ).exec( src );
	if ( ! m ) throw new Error( 'missing ' + varName );
	return re( unq( m[ 1 ] ) );
}

const PROMO = rulesOf( 'promo_rules' );
const SPAM = rulesOf( 'spam_rules' );
const GENUINE = rulesOf( 'genuine_rules' );
const voiceSrc = body( 'voice_analysis' );
const V = {
	seller: stringsOf( voiceSrc, 'seller_patterns' ),
	target: stringsOf( voiceSrc, 'target_patterns' ),
	cta: stringsOf( voiceSrc, 'cta_patterns' ),
	ask: stringsOf( voiceSrc, 'ask_patterns' ),
	opener: singleString( voiceSrc, 'opener' ),
	critique: singleString( voiceSrc, 'critique' ),
};
const qSrc = body( 'quality_checks' );
const PLACEHOLDER = ( () => {
	const m = /\$placeholder = '\/(.*)\/u';/.exec( qSrc );
	return new RegExp( unq( m[ 1 ] ), 'u' );
} )();
const DISPOSABLE = [ ...body( 'disposable_domains' ).matchAll( /'([a-z0-9.-]+)'/g ) ].map( ( m ) => m[ 1 ] );

// ---------- logic (mirror of PHP) ----------
const R = ( label, weight, type ) => ( { label, weight, type } );
const sum = ( b ) => Object.values( b ).reduce( ( a, r ) => a + r.weight, 0 );
const lower = ( s ) => s.toLowerCase();

function applyRules( rules, text, bucket, type ) {
	for ( const [ rx, w, label ] of rules ) {
		if ( bucket[ label ] ) continue;
		if ( rx.test( text ) ) bucket[ label ] = R( label, w, type );
	}
}
function voice( text ) {
	const t = text.toLowerCase();
	const c = ( arr ) => arr.filter( ( r ) => r.test( t ) ).length;
	return { seller: c( V.seller ), target: c( V.target ), cta: c( V.cta ), ask: c( V.ask ), opener: V.opener.test( t ), critique: V.critique.test( t ) };
}
function isRandomWord( word ) {
	const clean = word.replace( /[^a-z]/gi, '' );
	if ( clean.length < 6 || /^(https?|www)/i.test( word ) ) return false;
	const l = clean.toLowerCase();
	if ( ! /[aeiouy]/.test( l ) ) return true;
	if ( /[bcdfghjklmnpqrstvwxz]{6,}/.test( l ) ) return true;
	let s = 0;
	for ( let i = 1; i < clean.length; i++ ) if ( ( clean[ i ] === clean[ i ].toUpperCase() ) !== ( clean[ i - 1 ] === clean[ i - 1 ].toUpperCase() ) ) s++;
	return s >= 4;
}
function looksGibberish( text, isName ) {
	if ( /[^\x00-\x7F]/.test( text ) ) return false;
	const words = text.trim().split( /\s+/ ).filter( Boolean );
	if ( ! words.length ) return false;
	if ( ! isName && words.length === 1 && words[ 0 ].length > 25 && ! words[ 0 ].includes( '://' ) ) return true;
	const bad = words.filter( isRandomWord ).length;
	if ( isName ) return bad > 0;
	return words.length >= 3 ? bad / words.length > 0.3 : bad === words.length;
}
function isMash( text ) {
	const t = String( text ).toLowerCase().replace( /[^a-z]/g, '' );
	if ( t.length < 4 ) return false;
	const runs = [ 'qwert', 'asdfg', 'sdfgh', 'dfghj', 'fghjk', 'ghjkl', 'zxcvb', 'xcvbn', 'cvbnm', 'yuiop', 'tyuio', 'asdf', 'qwer', 'zxcv', 'hjkl', 'lkjh', 'fdsa', 'poiu', 'mnbv' ];
	for ( const w of String( text ).toLowerCase().split( /[^a-z]+/ ) ) {
		if ( runs.some( ( r ) => w.includes( r ) ) ) return true;
		if ( w.length >= 4 && /^([a-z]{1,3})\1{2,}[a-z]?$/.test( w ) && ! [ 'hahaha', 'hehehe', 'hihihi', 'lololol' ].includes( w ) ) return true;
	}
	return false;
}
function phoneProblem( phone, country ) {
	const d = String( phone ).replace( /\D/g, '' );
	const len = d.length;
	if ( ! len ) return [ 'Phone field has no number in it', 25 ];
	if ( len < 7 ) return [ `Phone number is too short (${ len } digits)`, 25 ];
	if ( len > 15 ) return [ 'Phone number is too long', 20 ];
	if ( /^(\d)\1+$/.test( d ) ) return [ 'Phone number is one digit repeated', 30 ];
	const tail = d.slice( -Math.min( 10, len ) );
	if ( '01234567890123456789'.includes( tail ) || '98765432109876543210'.includes( tail ) ) return [ 'Phone number is a 1-2-3-4 style sequence', 30 ];
	if ( /(\d)\1{6,}/.test( d ) ) return [ 'Phone number has the same digit 7+ times in a row', 25 ];
	if ( /^(\d{1,3})\1{2,}\d{0,2}$/.test( tail ) ) return [ 'Phone number is a repeated pattern', 25 ];
	if ( country === 'IN' && ! /^\s*(\+|00)\s*(?!91)\d/.test( String( phone ) ) ) {
		let local = d;
		if ( len === 12 && d.startsWith( '91' ) ) local = d.slice( 2 );
		else if ( len === 13 && d.startsWith( '091' ) ) local = d.slice( 3 );
		else if ( len === 11 && d[ 0 ] === '0' ) {
			local = d.slice( 1 );
			if ( ! /^[6-9]/.test( local ) ) return null;
		}
		if ( local.length !== 10 ) return [ `Not a valid Indian phone number (${ local.length } digits)`, 25 ];
		if ( d[ 0 ] !== '0' && ! /^[6-9]/.test( local ) ) return [ 'Not a valid Indian mobile number', 12 ];
	}
	return null;
}
function allVowelless( words ) {
	const ok = [ 'pls', 'plz', 'thx', 'thnx', 'msg', 'sms', 'cctv', 'gst', 'bhk', 'hr', 'dm', 'ph', 'mr', 'mrs', 'dr', 'st', 'rd', 'kg', 'km', 'ltr', 'pcs', 'qty', 'nd', 'th', 'pvt', 'ltd', 'pdf', 'hrs', 'wtsp', 'whtsp', 'cnfrm', 'sry', 'bht', 'kr', 'krn', 'krna', 'mjhe', 'mjh' ];
	let found = 0;
	for ( const w of words ) {
		if ( ! /^[a-z]+$/.test( w ) ) {
			if ( /\p{L}/u.test( w ) ) return false;
			continue;
		}
		if ( /[aeiouy]/.test( w ) || ok.includes( w ) || w.length < 2 ) return false;
		found++;
	}
	return found > 0;
}
function quality( name, email, domain, phone, subject, message, msgOptional, country ) {
	const issues = {};
	let junk = false;
	const norm = lower( message ).replace( /[^\p{L}\p{N}\s]+/gu, ' ' ).replace( /\s+/gu, ' ' ).trim();
	const words = norm === '' ? [] : norm.split( ' ' );
	const wcount = words.filter( ( w ) => /\p{L}/u.test( w ) ).length;
	let short = null;
	if ( message.trim() === '' ) {
		if ( ! msgOptional && subject.trim() === '' ) issues.msg_empty = R( 'No message written', 30, 'spam' );
	} else if ( norm === '' ) {
		issues.msg_symbols = R( 'Message is only symbols / dots', 45, 'spam' ); junk = true;
	} else if ( wcount === 0 ) {
		issues.msg_digits = R( 'Message is only numbers', 45, 'spam' ); junk = true;
	} else if ( PLACEHOLDER.test( norm ) || PLACEHOLDER.test( [ ...new Set( words ) ].join( ' ' ) ) ) {
		issues.msg_placeholder = R( `Message is only "${ norm.slice( 0, 30 ) }"`, 50, 'spam' ); junk = true;
	} else if ( isMash( norm ) ) {
		issues.msg_mash = R( 'Message is keyboard mashing / random letters', 50, 'spam' ); junk = true;
	} else if ( wcount <= 2 && allVowelless( words ) ) {
		issues.msg_mash = R( 'Message is random letters', 50, 'spam' ); junk = true;
	} else if ( words.length >= 3 && new Set( words ).size / words.length <= 0.34 ) {
		issues.msg_repeat = R( 'Same word repeated again and again', 45, 'spam' ); junk = true;
	} else if ( looksGibberish( message, false ) ) {
		issues.msg_gibberish = R( 'Message looks like random characters', 30, 'spam' ); junk = true;
	} else if ( wcount < 3 && ! msgOptional ) {
		short = R( `Message is too short (${ wcount } words)`, 20, 'spam' );
	}
	const letters = message.replace( /[^a-z]/gi, '' );
	if ( letters.length > 25 && letters.replace( /[^A-Z]/g, '' ).length / letters.length > 0.6 ) issues.caps = R( 'Mostly CAPITAL LETTERS', 10, 'spam' );
	if ( /(.)\1{5,}/u.test( message ) ) issues.msg_chars = R( 'Same character typed many times', 10, 'spam' );

	let phoneOk = false;
	if ( phone.trim() !== '' ) {
		const p = phoneProblem( phone, country );
		if ( p ) { issues.phone = R( p[ 0 ], p[ 1 ], 'spam' ); junk = junk || p[ 1 ] >= 25; } else phoneOk = true;
	}
	if ( short && ! phoneOk ) issues.msg_short = short;

	if ( email ) {
		const local = email.split( '@' )[ 0 ];
		const valid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( email );
		if ( ! valid ) issues.email = R( 'Email address is not valid', 20, 'spam' );
		else if ( DISPOSABLE.includes( domain ) ) { issues.email = R( 'Uses a temporary / disposable email', 25, 'spam' ); junk = true; }
		else if ( /^(test|example|domain|abc|xyz|asdf|qwerty|a|aa|aaa|email|fake|sample)\.(com|net|org|in|test)$|\.(test|invalid|example)$/.test( domain ) || /^(test\d*|testing|asdf\w*|qwerty|abc|abcd|xyz|demo|sample|fake|spam|user|a|aa+|x+|no-?reply)$/.test( local ) || isMash( local ) ) {
			issues.email = R( 'Looks like a test / fake email address', 25, 'spam' ); junk = true;
		}
	}
	if ( name ) {
		const n = lower( name.trim() );
		if ( /(https?:\/\/|www\.|\.com\b|\.net\b)/i.test( name ) ) issues.name = R( 'Name field contains a website', 30, 'spam' );
		else if ( /^(test\w*|asdf\w*|qwerty|abc|xyz|demo|fake|user|admin|none|null|na|n\/a|no name|name|x+|a+)$/u.test( n ) ) { issues.name = R( 'Name is a placeholder', 25, 'spam' ); junk = true; }
		else if ( [ ...n ].length < 2 ) issues.name = R( 'Name is a single letter', 20, 'spam' );
		else if ( isMash( n ) || looksGibberish( name, true ) || ( [ ...n ].length >= 4 && ! /[aeiouy\u{0900}-\u{097F}]/u.test( n ) && /^[a-z ]+$/.test( n ) ) ) { issues.name = R( 'Name looks random / bot-generated', 25, 'spam' ); junk = true; }
		else if ( ( n.match( /\d/g ) || [] ).length >= 2 ) issues.name = R( 'Name contains numbers', 12, 'spam' );
	}
	return { issues, phoneOk, junk, words: wcount };
}

function classify( input, settings, context ) {
	const name = ( input.name || '' ).trim();
	const email = ( input.email || '' ).trim().toLowerCase();
	const phone = ( input.phone || '' ).trim();
	const subject = ( input.subject || '' ).trim();
	const message = ( input.message || '' ).trim();
	const extra = ( input.extra_text || '' ).trim();
	const domain = email.includes( '@' ) ? email.split( '@' ).pop() : '';
	const threshold = { relaxed: 55, balanced: 40, strict: 28 }[ settings.sensitivity ] || 40;
	const text = `${ subject }\n${ message }\n${ extra }`.trim();
	const promo = {}, spam = {}, genuine = {};
	const msgOptional = [ 'newsletter', 'job', 'booking' ].includes( input.form_type || '' );

	const said = `${ subject }
${ message }`.trim();
	applyRules( PROMO, said, promo, 'promotional' );
	applyRules( SPAM, text, spam, 'spam' );
	const scam = Object.values( spam ).some( ( h ) => h.weight >= 30 );
	applyRules( GENUINE, said, genuine, 'genuine' );
	const promoHits = Object.keys( promo ).length;

	const v = voice( said );
	if ( v.seller ) promo.v_seller = R( 'Writes as a seller', Math.min( 25, 15 + 5 * ( v.seller - 1 ) ), 'promotional' );
	if ( v.target ) promo.v_target = R( 'Talks about YOUR website / business', 8, 'promotional' );
	if ( v.cta ) promo.v_cta = R( 'Sales call-to-action', 10, 'promotional' );
	if ( v.ask ) promo.v_ask = R( 'Asks whether YOU need something', 15, 'promotional' );
	if ( v.opener ) promo.v_opener = R( 'Cold-outreach opener', 6, 'promotional' );
	if ( v.critique ) promo.v_critique = R( 'Points out problems with your website', 12, 'promotional' );
	let pitch = ( v.seller > 0 && ( v.target || v.cta || v.ask || v.opener || v.critique || promoHits >= 1 ) )
		|| ( v.ask > 0 && ( v.target || v.cta || promoHits >= 1 ) )
		|| ( v.critique && ( v.cta || v.seller || v.opener ) )
		|| ( promoHits >= 2 && ( v.cta || v.target ) )
		|| v.seller >= 2
		|| promoHits >= 3;
	const buyerHits = Object.keys( genuine ).length;
	if ( pitch && buyerHits >= 2 && ! v.target && ! v.cta && ! v.ask && ! v.critique && promoHits < 2 ) pitch = false;
	if ( pitch ) promo.v_pitch = R( 'Reads like a sales pitch', 20, 'promotional' );

	const links = ( text.match( /(https?:\/\/|www\.)\S+/gi ) || [] ).length;
	if ( /\[url=|<a\s+href/i.test( text ) ) spam.bbcode = R( 'HTML / BBCode links', 30, 'spam' );
	if ( links >= 3 ) spam.links = R( `Contains ${ links } links`, 30, 'spam' );
	else if ( links === 2 ) promo.links = R( 'Contains 2 links', 15, 'promotional' );
	else if ( links === 1 ) promo.links = R( 'Contains a link', 7, 'promotional' );

	const q = quality( name, email, domain, phone, subject, message, msgOptional, ( settings.phone_country || '' ).toUpperCase() );
	Object.assign( spam, q.issues );
	if ( q.phoneOk ) genuine.phone = R( 'Gave a valid phone number', 8, 'genuine' );

	if ( settings.flag_foreign_scripts ) {
		const all = text + name;
		const letters = ( all.match( /\p{L}/gu ) || [] ).length;
		const foreign = ( all.match( /[Ѐ-ӿ一-鿿฀-๿؀-ۿ가-힯]/gu ) || [] ).length;
		if ( foreign > 0 ) {
			const most = letters > 0 && foreign / letters > 0.5;
			spam.script = R( most ? 'Written in foreign script' : 'Contains foreign script', most ? 45 : 20, 'spam' );
		}
	}
	let formHard = false;
	( context.form_issues || [] ).forEach( ( iss, i ) => { spam[ 'form' + i ] = R( iss.label, iss.weight, 'spam' ); formHard = formHard || iss.weight >= 25; } );

	if ( /[ऀ-ॿ]/u.test( text ) ) genuine.hindi = R( 'Written in Hindi', 10, 'genuine' );
	if ( links === 0 && ! pitch && q.words >= 3 && message.length <= 700 && message.includes( '?' ) ) genuine.question = R( 'Short, direct question', 4, 'genuine' );

	const promoPts = sum( promo ), spamPts = sum( spam );
	const cap = pitch || q.junk || formHard || scam ? 6 : 35;
	const genuinePts = Math.min( cap, sum( genuine ) );
	const score = Math.max( 0, Math.min( 100, promoPts + spamPts - genuinePts ) );
	const category = score >= threshold ? ( spamPts > promoPts ? 'spam' : 'promotional' ) : 'genuine';
	const reasons = [ ...Object.values( spam ), ...Object.values( promo ), ...Object.values( genuine ).map( ( g ) => ( { ...g, weight: -g.weight } ) ) ].sort( ( a, b ) => Math.abs( b.weight ) - Math.abs( a.weight ) );
	return { category, score, reasons };
}

// ---------- run ----------
const cases = JSON.parse( readFileSync( join( here, process.argv.find( ( a ) => a.endsWith( '.json' ) ) || 'cases.json' ), 'utf8' ) );
const settings = { sensitivity: 'balanced', flag_foreign_scripts: true, phone_country: 'IN' };
const stats = {};
const failed = [];
for ( const c of cases ) {
	const expect = [].concat( c.expect );
	const r = classify( { name: c.name, email: c.email, phone: c.phone, subject: c.subject, message: c.message, extra_text: c.extra, form_type: c.form_type || 'contact' }, settings, { form_issues: c.form_issues || [] } );
	const ok = expect.includes( r.category );
	const g = expect[ 0 ];
	stats[ g ] = stats[ g ] || { ok: 0, total: 0 };
	stats[ g ].total++;
	if ( ok ) stats[ g ].ok++;
	if ( ! ok || verbose ) {
		const s = `${ ok ? 'ok ' : 'BAD' } ${ c.id.padEnd( 4 ) } expected ${ expect.join( '/' ).padEnd( 12 ) } got ${ r.category.padEnd( 12 ) } score ${ String( r.score ).padStart( 3 ) } | ${ ( c.message || '' ).slice( 0, 70 ) }\n        ${ r.reasons.map( ( x ) => `${ x.weight > 0 ? '+' : '' }${ x.weight } ${ x.label }` ).join( '\n        ' ) }\n`;
		if ( ok ) console.log( s );
		else failed.push( s );
	}
}
console.log( failed.join( '\n' ) );
console.log( `rules read from PHP: promo ${ PROMO.length }, spam ${ SPAM.length }, genuine ${ GENUINE.length }, voice ${ V.seller.length }/${ V.target.length }/${ V.cta.length }/${ V.ask.length }` );
let all = 0, good = 0;
for ( const [ g, s ] of Object.entries( stats ) ) {
	console.log( `${ g.padEnd( 12 ) } ${ s.ok } / ${ s.total }  (${ Math.round( ( 100 * s.ok ) / s.total ) }%)` );
	all += s.total; good += s.ok;
}
console.log( `${ 'TOTAL'.padEnd( 12 ) } ${ good } / ${ all }  (${ Math.round( ( 100 * good ) / all ) }%)` );
process.exit( good === all ? 0 : 1 );
