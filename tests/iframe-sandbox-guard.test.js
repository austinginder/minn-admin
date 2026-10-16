/**
 * Every frame the app creates is sandboxed without same-origin (no browser,
 * <1 s).
 *
 * A frame the app makes shares the app's origin unless it is sandboxed, and
 * whatever runs in it can then read parent.MINN.nonce and act as the person
 * signed in. Post content, a post's front end and an email body are all
 * written by someone else, so every iframe app.js creates, by
 * createElement or in markup, must carry a sandbox attribute that leaves out
 * allow-same-origin. A new frame fails here until it does, or is listed with
 * the reason it needs the app's origin.
 */
const fs = require( 'fs' );
const path = require( 'path' );

const src = fs.readFileSync( path.join( __dirname, '..', 'assets', 'js', 'app.js' ), 'utf8' );
const lines = src.split( '\n' );

// Trimmed source line of a frame that may run in the app's origin => why.
const ALLOWED = {};

const results = [];
const check = ( label, ok, detail = '' ) => {
	results.push( ok );
	console.log( `${ ok ? 'PASS' : 'FAIL' }  ${ label }${ detail ? ' — ' + detail : '' }` );
};

const bad = [];
let made = 0;
lines.forEach( ( line, i ) => {
	const t = line.trim();
	if ( /^\s*(\/\/|\*|\/\*)/.test( line ) ) return;
	if ( /createElement\(\s*['"]iframe['"]\s*\)/.test( t ) ) {
		made++;
		if ( Object.prototype.hasOwnProperty.call( ALLOWED, t ) ) return;
		// The sandbox has to be set before the frame gets a source: look at
		// the lines that follow, up to where it is given one.
		const after = lines.slice( i + 1, i + 12 ).join( '\n' );
		const sb = after.match( /setAttribute\(\s*'sandbox',\s*'([^']*)'\s*\)/ );
		if ( ! sb || /allow-same-origin/.test( sb[ 1 ] ) ) bad.push( `app.js:${ i + 1 }  ${ t }` );
	}
	// A frame in markup carries its sandbox on the same line as its tag.
	t.split( /<iframe\b/ ).slice( 1 ).forEach( ( rest ) => {
		made++;
		if ( Object.prototype.hasOwnProperty.call( ALLOWED, t ) ) return;
		const sb = rest.match( /\bsandbox="([^"]*)"/ );
		if ( ! sb || /allow-same-origin/.test( sb[ 1 ] ) ) bad.push( `app.js:${ i + 1 }  <iframe${ rest.slice( 0, 110 ) }` );
	} );
} );
check( 'app.js creates frames (the guard is not reading an empty file)', made > 0, String( made ) );
check( 'every frame app.js creates is sandboxed without allow-same-origin', 0 === bad.length, bad.join( ' | ' ) );
const stale = Object.keys( ALLOWED ).filter( ( k ) => ! lines.some( ( l ) => l.trim() === k ) );
check( 'every allowed line still exists', 0 === stale.length, stale.join( ' | ' ) );
// The page-run frame's sandbox is set before its document is.
const run = src.slice( src.indexOf( 'function runPageIsolated(' ), src.indexOf( 'function runPageIsolated(' ) + 4000 );
check( 'runPageIsolated sets its sandbox before the frame gets its document',
	run.indexOf( "setAttribute( 'sandbox', 'allow-scripts' )" ) !== -1
	&& run.indexOf( "setAttribute( 'sandbox', 'allow-scripts' )" ) < run.indexOf( 'frame.srcdoc' )
	&& run.indexOf( 'frame.src =' ) === -1 );

const failed = results.filter( ( r ) => ! r ).length;
console.log( `\niframe-sandbox-guard: ${ results.length - failed }/${ results.length } passed` );
process.exitCode = failed ? 1 : 0;
