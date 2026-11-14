// Tests the plain-language rule parser in src/Rules.jsx ("seo, backlinks → promotional").
import * as esbuild from 'esbuild';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname( fileURLToPath( import.meta.url ) );
const out = await esbuild.build( {
	entryPoints: [ join( here, '../src/Rules.jsx' ) ],
	bundle: true,
	write: false,
	format: 'esm',
	jsx: 'transform',
	jsxFactory: 'h',
	jsxFragment: 'F',
	banner: { js: 'const h = () => null; const F = null;' },
	plugins: [
		{
			name: 'stub',
			setup( b ) {
				b.onResolve( { filter: /^@wordpress\/|^\.\/ui$/ }, ( a ) => ( { path: a.path, namespace: 'stub' } ) );
				b.onLoad( { filter: /.*/, namespace: 'stub' }, () => ( { contents: 'export const useState = (v) => [v, () => {}]; export const Button = () => null;', loader: 'js' } ) );
			},
		},
	],
} );
const mod = await import( 'data:text/javascript,' + encodeURIComponent( out.outputFiles[ 0 ].text ) );

const cases = [
	[ 'seo, backlinks → promotional', { field: 'any', operator: 'contains', value: 'seo, backlinks', action: 'set_promotional' } ],
	[ 'wedding, bridal = genuine', { field: 'any', operator: 'contains', value: 'wedding, bridal', action: 'set_genuine' } ],
	[ '@mailinator.com, @tempmail.com → spam', { field: 'email', operator: 'ends_with', action: 'set_spam' } ],
	[ 'form: careers → genuine', { field: 'form', operator: 'contains', value: 'careers', action: 'set_genuine' } ],
	[ 'lean: discount, special offer → promotional', { action: 'score_promotional', value: 'discount, special offer' } ],
	[ 'not: price, rate → promotional', { operator: 'not_contains', action: 'set_promotional' } ],
	[ 'crypto -> junk', { action: 'set_spam' } ],
	[ 'mujhe chahiye => real', { action: 'set_genuine', value: 'mujhe chahiye' } ],
	[ 'free audit => lean promo', { action: 'score_promotional' } ],
	[ 'seo backlinks promotional', 'error' ],
	[ '→ spam', 'error' ],
	[ 'hello → banana', 'error' ],
];
let ok = 0;
for ( const [ text, expect ] of cases ) {
	const r = mod.parseRule( text );
	let pass;
	if ( 'error' === expect ) {
		pass = !! r.error;
	} else {
		pass = !! r.rule && Object.entries( expect ).every( ( [ k, v ] ) => r.rule[ k ] === v );
	}
	ok += pass ? 1 : 0;
	const shown = r.rule ? `${ mod.sentence( r.rule ).cond } → ${ mod.sentence( r.rule ).then }` : `error: ${ r.error }`;
	console.log( `${ pass ? 'ok ' : 'BAD' } ${ text.padEnd( 44 ) } ${ shown }` );
}
console.log( `${ ok } / ${ cases.length } parser cases pass` );
process.exit( ok === cases.length ? 0 : 1 );
