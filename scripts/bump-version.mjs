// Bumps the plugin version everywhere it appears and adds a CHANGELOG entry.
//
//   npm run version:bump -- patch        1.0.0 → 1.0.1  (bug fixes)
//   npm run version:bump -- minor        1.0.0 → 1.1.0  (new features)
//   npm run version:bump -- major        1.0.0 → 2.0.0  (breaking changes)
//   npm run version:bump -- 1.4.2        set an exact version
//
// Files kept in sync: leadsift.php (header + LEADSIFT_VERSION), readme.txt (Stable tag),
// package.json, CHANGELOG.md, readme.txt changelog.
import { readFileSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const file = ( p ) => join( root, p );
const read = ( p ) => readFileSync( file( p ), 'utf8' );
const write = ( p, s ) => writeFileSync( file( p ), s );

const pkg = JSON.parse( read( 'package.json' ) );
const current = pkg.version;
const arg = process.argv[ 2 ] || 'patch';

let next;
if ( /^\d+\.\d+\.\d+$/.test( arg ) ) {
	next = arg;
} else {
	const [ major, minor, patch ] = current.split( '.' ).map( Number );
	next = {
		major: `${ major + 1 }.0.0`,
		minor: `${ major }.${ minor + 1 }.0`,
		patch: `${ major }.${ minor }.${ patch + 1 }`,
	}[ arg ];
}
if ( ! next ) {
	console.error( `Unknown bump "${ arg }". Use patch, minor, major or x.y.z` );
	process.exit( 1 );
}

const cmp = ( a, b ) => {
	const pa = a.split( '.' ).map( Number );
	const pb = b.split( '.' ).map( Number );
	for ( let i = 0; i < 3; i++ ) {
		if ( pa[ i ] !== pb[ i ] ) {
			return pa[ i ] - pb[ i ];
		}
	}
	return 0;
};
if ( cmp( next, current ) <= 0 ) {
	console.error( `New version ${ next } must be higher than ${ current }` );
	process.exit( 1 );
}

// leadsift.php
let php = read( 'leadsift.php' );
php = php.replace( /(\*\s+Version:\s+)[\d.]+/, `$1${ next }` );
php = php.replace( /(define\(\s*'LEADSIFT_VERSION',\s*')[\d.]+(')/, `$1${ next }$2` );
write( 'leadsift.php', php );

// readme.txt
let readme = read( 'readme.txt' );
readme = readme.replace( /(Stable tag:\s+)[\d.]+/, `$1${ next }` );
readme = readme.replace( '== Changelog ==\n', `== Changelog ==\n\n= ${ next } =\n* Describe the changes in this release.\n` );
write( 'readme.txt', readme );

// package.json
pkg.version = next;
write( 'package.json', JSON.stringify( pkg, null, 2 ) + '\n' );

// CHANGELOG.md
const today = new Date().toISOString().slice( 0, 10 );
let changelog = read( 'CHANGELOG.md' );
changelog = changelog.replace( '## [', `## [${ next }] - ${ today }\n\n### Changed\n- Describe the changes in this release.\n\n## [` );
write( 'CHANGELOG.md', changelog );

console.log( `LeadSift version ${ current } → ${ next }` );
console.log( 'Next: fill in CHANGELOG.md + readme.txt notes, then run "npm run zip".' );
console.log( 'If the database table changed, also raise LEADSIFT_DB_VERSION in leadsift.php and add a migration in includes/class-leadsift-upgrader.php.' );
