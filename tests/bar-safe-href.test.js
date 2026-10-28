/**
 * The front-end bar's navigation guard matches app.js's: a browser drops
 * tabs and newlines from a URL, so "/<TAB>/host" passed a leading-slash test
 * and navigated to another site from a palette command or a notification.
 * Static, no browser: both guards are read from source and run here, and
 * must answer every case alike.
 *
 * Run: node tests/bar-safe-href.test.js
 */
'use strict';
const fs = require( 'fs' );
const path = require( 'path' );

const results = [];
const check = ( label, ok, detail = '' ) => {
	results.push( !! ok );
	console.log( `${ ok ? 'PASS' : 'FAIL' }  ${ label }${ detail ? ' — ' + detail : '' }` );
};
const guard = ( file ) => {
	const src = fs.readFileSync( path.join( __dirname, '..', 'assets', 'js', file ), 'utf8' );
	const m = src.match( /const safeHref = (\( u \) => (?:\{[\s\S]*?\n\t\}|[^\n]*?));\n/ );
	return m ? eval( m[ 1 ] ) : null; // eslint-disable-line no-eval
};
const bar = guard( 'bar.js' );
const app = guard( 'app.js' );
check( 'both guards are found in source', bar && app );

const origin = 'https://site.example';
const cases = [ '/\t/evil.example/x', '/\n/evil.example', '/\r\\evil.example', '//evil.example', '/\\evil.example', '/\u0000x', ' /\t/evil.example', 'javascript:alert(1)', '/ok/path', 'https://site.example/x', 'http://other.example/' ];
for ( const v of cases ) {
	const out = bar( v );
	const host = out ? new URL( out, origin ).host : '';
	const offsite = out && ! /^https?:\/\//i.test( out ) && host !== 'site.example';
	check( `bar: ${ JSON.stringify( v ) } never resolves a path to another site`, ! offsite, JSON.stringify( out ) + ' ' + host );
	check( `bar and app answer ${ JSON.stringify( v ) } alike`, out === app( v ), JSON.stringify( [ out, app( v ) ] ) );
}
check( 'control: a same-site path passes', '/ok/path' === bar( '/ok/path' ) );
check( 'control: an absolute https URL passes', 'https://site.example/x' === bar( 'https://site.example/x' ) );

const failed = results.filter( ( r ) => ! r ).length;
console.log( `\nbar-safe-href: ${ results.length - failed }/${ results.length } passed` );
process.exit( failed ? 1 : 0 );
