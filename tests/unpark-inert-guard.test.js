/**
 * Parked content is only ever put back on a copy in the inert document (no
 * browser, <1 s).
 *
 * The editor keeps stored markup inert on screen by parking what runs
 * (rtNeutralizeInto), and the serializers put it back with rtUnpark so the
 * database gets the writer's bytes. A copy made with cloneNode still belongs
 * to the live page: putting a parked src and onerror back on it loads the
 * image and runs the handler in the app's origin. Every rtUnpark call must
 * take a copy made by rtInertCopy (the inert document's importNode), either
 * directly or through a variable assigned from it in the same function.
 */
const fs = require( 'fs' );
const path = require( 'path' );

const lines = fs.readFileSync( path.join( __dirname, '..', 'assets', 'js', 'app.js' ), 'utf8' ).split( '\n' );

const results = [];
const check = ( label, ok, detail = '' ) => {
	results.push( ok );
	console.log( `${ ok ? 'PASS' : 'FAIL' }  ${ label }${ detail ? ' — ' + detail : '' }` );
};

const bad = [];
let calls = 0;
lines.forEach( ( line, i ) => {
	const m = line.match( /rtUnpark\(\s*([^)]*?)\s*\)/ );
	if ( ! m || /function rtUnpark\(/.test( line ) ) return;
	const arg = m[ 1 ];
	// The template recursion inside rtUnpark itself: a template's content
	// fragment belongs to its own inert owner document.
	if ( 'el.content' === arg ) return;
	calls++;
	if ( /^rtInertCopy\(/.test( arg ) ) return;
	// A tree parsed straight into the inert document (inertParse) is already
	// a copy there (the Details block's untouched-rebuild path).
	if ( /^(rtNeutralize\(\s*)?inertParse\(/.test( arg ) ) return;
	const name = ( arg.match( /^([A-Za-z_$][\w$]*)$/ ) || [] )[ 1 ];
	const before = lines.slice( Math.max( 0, i - 40 ), i ).join( '\n' );
	if ( name && new RegExp( `const ${ name } = rtInertCopy\\(` ).test( before ) ) return;
	bad.push( `app.js:${ i + 1 }  ${ line.trim() }` );
} );
check( 'app.js unparks somewhere (the guard is not reading an empty file)', calls > 0, String( calls ) );
check( 'every rtUnpark takes an inert-document copy', 0 === bad.length, bad.join( ' | ' ) );
check( 'rtInertCopy imports into the inert document',
	/const rtInertCopy = \( node \) => stripTagsDoc\.importNode\( node, true \);/.test( lines.join( '\n' ) ) );

const failed = results.filter( ( r ) => ! r ).length;
console.log( `\nunpark-inert-guard: ${ results.length - failed }/${ results.length } passed` );
process.exitCode = failed ? 1 : 0;
