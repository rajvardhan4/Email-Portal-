// Creates dist/leadsift-<version>.zip, ready for Plugins → Add New → Upload.
// Only runtime files go in; src/, scripts/, node_modules/ stay out.
import { readFileSync, mkdirSync, existsSync, rmSync, cpSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const { version } = JSON.parse( readFileSync( join( root, 'package.json' ), 'utf8' ) );
const php = readFileSync( join( root, 'leadsift.php' ), 'utf8' );
if ( ! php.includes( `'LEADSIFT_VERSION', '${ version }'` ) ) {
	console.error( 'Version mismatch between package.json and leadsift.php. Run "npm run version:bump".' );
	process.exit( 1 );
}

const FILES = [ 'leadsift.php', 'uninstall.php', 'readme.txt', 'CHANGELOG.md', 'includes', 'templates', 'build' ];
const dist = join( root, 'dist' );
const stage = join( dist, 'leadsift' );
rmSync( stage, { recursive: true, force: true } );
mkdirSync( stage, { recursive: true } );
for ( const f of FILES ) {
	if ( ! existsSync( join( root, f ) ) ) {
		console.error( `Missing ${ f } — run "npm run build" first.` );
		process.exit( 1 );
	}
	cpSync( join( root, f ), join( stage, f ), { recursive: true } );
}

const zipName = `leadsift-${ version }.zip`;
const zipPath = join( dist, zipName );
rmSync( zipPath, { force: true } );

if ( process.platform === 'win32' ) {
	// Windows' own bsdtar writes forward-slash paths (Compress-Archive uses backslashes, which breaks on Linux hosts).
	const tar = join( process.env.SystemRoot || 'C:\\Windows', 'System32', 'tar.exe' );
	execFileSync( tar, [ '-a', '-cf', zipName, 'leadsift' ], { cwd: dist, stdio: 'inherit' } );
} else {
	execFileSync( 'zip', [ '-rq', zipName, 'leadsift' ], { cwd: dist, stdio: 'inherit' } );
}
rmSync( stage, { recursive: true, force: true } );
console.log( `Created dist/${ zipName }` );
