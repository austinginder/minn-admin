/**
 * The app shell's inline script survives an HTML minifier.
 *
 * Cache plugins, "minify HTML" snippets and host optimizers collapse the
 * document's whitespace before it leaves the server. A // comment in the
 * inline script then comments out the rest of the script, window.MINN
 * included, and app.js dies on a blank page. This runs the script from
 * includes/template.php twice, as written and collapsed onto one line, and
 * each run must leave window.MINN set and the theme attributes applied.
 *
 * Run: node tests/inline-script.test.js
 */
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );
const vm = require( 'vm' );

const ROOT = path.resolve( __dirname, '..' );
const checks = [];
const check = ( label, ok, detail = '' ) => {
	checks.push( !! ok );
	console.log( `${ ok ? 'PASS' : 'FAIL' }  ${ label }${ ok || ! detail ? '' : ' — ' + detail }` );
};

const template = fs.readFileSync( path.join( ROOT, 'includes', 'template.php' ), 'utf8' );
const inline = [ ...template.matchAll( /<script>([\s\S]*?)<\/script>/g ) ].map( ( m ) => m[ 1 ] );
check( 'template.php has one inline script', inline.length === 1, `found ${ inline.length }` );

// PHP that echoes stands in as an empty object (the boot payload, the site's
// mode); PHP that only computes or comments prints nothing.
const js = ( inline[ 0 ] || '' ).replace( /<\?php[\s\S]*?\?>\n?/g, ( php ) => ( /\becho\b/.test( php ) ? '{}' : '' ) );

const run = ( source ) => {
	const attrs = {};
	const root = {
		setAttribute: ( k, v ) => { attrs[ k ] = String( v ); },
		getAttribute: ( k ) => ( k in attrs ? attrs[ k ] : null ),
		removeAttribute: ( k ) => { delete attrs[ k ]; },
		style: { setProperty() {}, removeProperty() {} },
	};
	const sandbox = {
		document: { documentElement: root, dispatchEvent() {} },
		localStorage: { getItem: () => 'dark' },
		CustomEvent: function () {},
	};
	sandbox.window = sandbox;
	vm.runInNewContext( source, sandbox );
	return { minn: sandbox.MINN, attrs };
};

for ( const [ label, source ] of [ [ 'as written', js ], [ 'collapsed onto one line', js.replace( /\s+/g, ' ' ) ] ] ) {
	let out = null;
	try {
		out = run( source );
	} catch ( e ) {
		check( `${ label }: runs`, false, e.message );
		continue;
	}
	check( `${ label }: window.MINN is set`, out.minn && 'object' === typeof out.minn, String( out.minn ) );
	check( `${ label }: theme and scheme applied`, 'dark' === out.attrs[ 'data-theme' ] && 'minn' === out.attrs[ 'data-scheme' ], JSON.stringify( out.attrs ) );
}

const failed = checks.filter( ( ok ) => ! ok ).length;
console.log( failed ? `\n${ failed } FAILED` : `\nAll ${ checks.length } checks passed` );
process.exit( failed ? 1 : 0 );
