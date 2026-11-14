// Builds src/ → build/index.js + build/index.css.
// React comes from WordPress itself (wp.element), so nothing big is bundled.
import * as esbuild from 'esbuild';

const WP_GLOBALS = {
	'@wordpress/element': 'wp.element',
	'@wordpress/api-fetch': 'wp.apiFetch',
};

const wpGlobals = {
	name: 'wp-globals',
	setup( build ) {
		build.onResolve( { filter: /^@wordpress\// }, ( args ) => ( { path: args.path, namespace: 'wp-global' } ) );
		build.onLoad( { filter: /.*/, namespace: 'wp-global' }, ( args ) => {
			const global = WP_GLOBALS[ args.path ];
			if ( ! global ) {
				throw new Error( `No WordPress global mapped for ${ args.path }` );
			}
			return { contents: `module.exports = window.${ global };`, loader: 'js' };
		} );
	},
};

const options = {
	entryPoints: [ 'src/index.js' ],
	bundle: true,
	outdir: 'build',
	entryNames: 'index',
	format: 'iife',
	target: [ 'es2018' ],
	minify: true,
	sourcemap: false,
	loader: { '.js': 'jsx', '.jsx': 'jsx' },
	jsx: 'transform',
	jsxFactory: 'wp.element.createElement',
	jsxFragment: 'wp.element.Fragment',
	plugins: [ wpGlobals ],
	logLevel: 'info',
};

if ( process.argv.includes( '--watch' ) ) {
	const ctx = await esbuild.context( { ...options, minify: false } );
	await ctx.watch();
	console.log( 'Watching src/ …' );
} else {
	await esbuild.build( options );
}
