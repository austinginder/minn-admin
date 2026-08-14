/**
 * i18n decay guard — static, no browser, runs in about a second.
 *
 * The v0.30.0 sweep wrapped roughly 2,800 strings across app.js and the PHP
 * side. Without a guard that work rots: every new view adds a literal or
 * two, and three releases later the catalog is half the interface again.
 * This suite is what makes the sweep a one-time cost.
 *
 * It checks four things:
 *
 *   1. No user-visible ATTRIBUTE literal (placeholder / title / aria-label /
 *      alt) sits unwrapped in template text.
 *   2. No user-visible TEXT NODE that is the whole content of its element
 *      sits unwrapped in template text.
 *   3. Every gettext string carrying a %s / %d placeholder has a
 *      /* translators: … *​/ comment on the line above it. Translators cannot
 *      guess what %s holds, and a wrong guess reorders the wrong things.
 *   4. Nothing compares against, or keys off, a TRANSLATED display label.
 *      That is the bug class that made every overview and System card stop
 *      being a door the moment a locale shipped: __() returns the source
 *      text only in en_US.
 *
 * Run: node tests/i18n-static.test.js
 */
'use strict';
const fs = require( 'fs' );
const path = require( 'path' );
const { classify, TMPL, SQ, DQ } = require( './lib/js-lex.js' );

const ROOT = path.resolve( __dirname, '..' );
const APP = path.join( ROOT, 'assets/js/app.js' );

const results = [];
const check = ( label, ok, detail = '' ) => {
	results.push( ok );
	console.log( `${ ok ? 'PASS' : 'FAIL' }  ${ label }${ detail ? '\n      ' + detail : '' }` );
};

const src = fs.readFileSync( APP, 'utf8' );
const st = classify( src );
const lineOf = ( o ) => src.slice( 0, o ).split( '\n' ).length;

/* ---------------------------------------------------------------------------
 * 0. The scanner itself has to be right, or every check below is vacuous.
 *    A regex-vs-division slip once made a third of the file look like one
 *    template literal, which silently hid real findings.
 * ------------------------------------------------------------------------ */
{
	const lines = src.split( '\n' );
	let off = 0, decls = 0, bad = 0;
	for ( const l of lines ) {
		if ( /^\t(function |const .* = \( |async function )/.test( l ) ) {
			const col = l.length - l.replace( /^\t+/, '' ).length;
			decls++;
			if ( st[ off + col ] !== 0 ) bad++;
		}
		off += l.length + 1;
	}
	check( `Lexer sane (${ decls } top-level declarations read as code)`, decls > 500 && bad === 0, bad ? `${ bad } misclassified` : '' );
}

/* ---------------------------------------------------------------------------
 * 1. Attribute literals.
 * ------------------------------------------------------------------------ */
// Technical examples a translator cannot improve. Keep this list SHORT and
// justify every addition: it is the escape hatch that lets the guard rot.
const ATTR_ALLOW = new Set( [
	'US', 'customer@example.com', 'https://example.com/file.pdf',
	'https://example.com/product', 'https://…',
] );
{
	const bad = [];
	const re = /(placeholder|title|aria-label|aria-description|alt|aria-placeholder)="([^"]*)"/g;
	let m;
	while ( ( m = re.exec( src ) ) ) {
		if ( st[ m.index ] !== TMPL ) continue;
		const v = m[ 2 ];
		if ( ! v || v.includes( '${' ) || ! /[A-Za-z]/.test( v ) ) continue;
		if ( ATTR_ALLOW.has( v ) ) continue;
		bad.push( `app.js:${ lineOf( m.index ) }  ${ m[ 1 ] }="${ v }"` );
	}
	check( 'No unwrapped user-visible attribute literals', bad.length === 0, bad.slice( 0, 8 ).join( '\n      ' ) );
}

/* ---------------------------------------------------------------------------
 * 2. Text nodes that are the whole content of an element.
 * ------------------------------------------------------------------------ */
const TEXT_ALLOW = new Set( [
	'.zip', '/minn-admin/', 'Aa', 'esc', '\\n', '×N',
	'⌘K', '⌘S', '⌘⇧D', '⌘⇧F', '⌘⇧O', '⌥click', '⇧⌥click', '\\u00d7',
] );
{
	const bad = [];
	const re = />([^<>`]*)</g;
	let m;
	while ( ( m = re.exec( src ) ) ) {
		const gt = m.index, raw = m[ 1 ];
		if ( ! raw.trim() || ! /[A-Za-z]/.test( raw ) ) continue;
		if ( st[ gt ] !== TMPL ) continue;
		let all = true;
		for ( let k = gt + 1; all && k <= gt + raw.length; k++ ) if ( st[ k ] !== TMPL ) all = false;
		if ( ! all ) continue;
		const t = raw.trim();
		if ( TEXT_ALLOW.has( t ) ) continue;
		// Block serialization is stored post content, never interface text.
		if ( /<!--\s*\/?wp:/.test( src.slice( Math.max( 0, gt - 1200 ), gt + 400 ) ) ) continue;
		const tagOpen = src.lastIndexOf( '<', gt );
		const endBefore = tagOpen >= 0 && src[ tagOpen + 1 ] === '/';
		const endAfter = src[ gt + m[ 0 ].length - 1 + 1 ] === '/';
		if ( endBefore || ! endAfter ) continue; // fragment: reported by eye, not here
		bad.push( `app.js:${ lineOf( gt ) }  >${ t }<` );
	}
	check( 'No unwrapped visible text nodes', bad.length === 0, bad.slice( 0, 8 ).join( '\n      ' ) );
}

/* ---------------------------------------------------------------------------
 * 3. translators: comments on every placeholder-bearing string.
 * ------------------------------------------------------------------------ */
{
	const bad = [];
	const scanForComments = ( file, text, callRe ) => {
		const lines = text.split( '\n' );
		let m;
		while ( ( m = callRe.exec( text ) ) ) {
			const body = m[ 2 ] || '';
			// Strip WordPress permalink tags (%year%, %postname%, %day%) BEFORE
			// looking for printf placeholders: "%day%" contains a literal "%d"
			// and would otherwise demand a translators comment for a string
			// that has no placeholders at all.
			if ( ! /%(\d+\$)?[sd]/.test( body.replace( /%[a-z_]+%/g, '' ) ) ) continue;
			const ln = text.slice( 0, m.index ).split( '\n' ).length;
			// Same line, or the line DIRECTLY above. This matches what
			// `wp i18n make-pot` accepts: a comment two lines up is silently
			// not attached to the call, so allowing it here would let the
			// guard pass while the extractor still warns.
			const window = lines.slice( Math.max( 0, ln - 2 ), ln ).join( '\n' );
			if ( /translators\s*:/i.test( window ) ) continue;
			bad.push( `${ file }:${ ln }  ${ body.slice( 0, 60 ) }` );
		}
	};
	scanForComments( 'app.js', src, /\b(__|_n)\(\s*'((?:[^'\\]|\\.)*)'/g );
	scanForComments( 'app.js', src, /\b(__|_n)\(\s*"((?:[^"\\]|\\.)*)"/g );
	// The PLURAL argument counts too: wp i18n make-pot warns on it, and a
	// placeholder can appear only in the plural form ("%d tables").
	scanForComments( 'app.js', src, /\b(_n)\(\s*'(?:[^'\\]|\\.)*'\s*,\s*'((?:[^'\\]|\\.)*)'/g );
	for ( const f of walkPhp( path.join( ROOT, 'includes' ) ) ) {
		const t = fs.readFileSync( f, 'utf8' );
		scanForComments( path.relative( ROOT, f ), t, /\b(__|_n|esc_html__|esc_attr__)\(\s*'((?:[^'\\]|\\.)*)'/g );
		scanForComments( path.relative( ROOT, f ), t, /\b(_n)\(\s*'(?:[^'\\]|\\.)*'\s*,\s*'((?:[^'\\]|\\.)*)'/g );
	}
	check( `Placeholder strings carry a translators comment`, bad.length === 0,
		bad.length ? `${ bad.length } missing, first few:\n      ` + bad.slice( 0, 6 ).join( '\n      ' ) : '' );
}

/* ---------------------------------------------------------------------------
 * 4. Nothing routes off a translated label.
 * ------------------------------------------------------------------------ */
{
	const bad = [];
	// `x.label === 'Something'` and `{ 'Some Label': … }[ x.label ]`
	const cmp = /\.label\s*[=!]==?\s*['"]/g;
	let m;
	while ( ( m = cmp.exec( src ) ) ) bad.push( `app.js:${ lineOf( m.index ) }  compares .label against a literal` );
	const keyed = /\}\s*\[\s*\w+\.label\s*\]/g;
	while ( ( m = keyed.exec( src ) ) ) bad.push( `app.js:${ lineOf( m.index ) }  looks a value up BY .label` );
	check( 'Nothing routes off a translated display label', bad.length === 0, bad.slice( 0, 6 ).join( '\n      ' ) );
}

/* ---------------------------------------------------------------------------
 * 5. _n() takes PLAIN singular and plural sources, never a nested __().
 *    A bulk sweep once wrapped the plural argument, which registers the
 *    plural as its own msgid and feeds _n() an already-translated string to
 *    look up. It still runs, and it is still wrong in every locale.
 * ------------------------------------------------------------------------ */
{
	const bad = [];
	const files = [ [ 'app.js', src ] ].concat(
		walkPhp( path.join( ROOT, 'includes' ) ).map( ( f ) => [ path.relative( ROOT, f ), fs.readFileSync( f, 'utf8' ) ] )
	);
	for ( const [ name, text ] of files ) {
		const re = /\b_n\(\s*(['"])(?:[^'"\\]|\\.)*\1\s*,\s*(?:__|esc_html__|esc_attr__)\s*\(/g;
		let m;
		while ( ( m = re.exec( text ) ) ) {
			bad.push( `${ name }:${ text.slice( 0, m.index ).split( '\n' ).length }  _n() plural wrapped in __()` );
		}
	}
	check( 'No _n() plural argument wrapped in __()', bad.length === 0, bad.slice( 0, 6 ).join( '\n      ' ) );
}

/* ---------------------------------------------------------------------------
 * 6. No literal \uXXXX inside a gettext string.
 *    A unicode escape inside a template literal resolves to its character;
 *    moved verbatim into a quoted string by an extractor, the backslash gets
 *    escaped and the user reads "‹ Back to content" instead of
 *    "‹ Back to content". It also poisons the catalog, because the msgid the
 *    translator is handed can never match the string the app renders.
 * ------------------------------------------------------------------------ */
{
	const bad = [];
	const re = /\b(__|_n|esc_html__|esc_attr__)\(\s*(['"])((?:[^'"\\]|\\.)*?)\2/g;
	let m;
	while ( ( m = re.exec( src ) ) ) {
		if ( /\\\\u[0-9a-fA-F]{4}/.test( m[ 3 ] ) ) {
			bad.push( `app.js:${ lineOf( m.index ) }  ${ m[ 3 ].slice( 0, 50 ) }` );
		}
	}
	check( 'No literal \\uXXXX escape inside a translatable string', bad.length === 0, bad.slice( 0, 6 ).join( '\n      ' ) );
}

function walkPhp( dir ) {
	const out = [];
	for ( const e of fs.readdirSync( dir, { withFileTypes: true } ) ) {
		const p = path.join( dir, e.name );
		if ( e.isDirectory() ) out.push( ...walkPhp( p ) );
		else if ( e.name.endsWith( '.php' ) ) out.push( p );
	}
	return out;
}

const failed = results.filter( ( r ) => ! r ).length;
console.log( `\ni18n-static: ${ results.length - failed }/${ results.length } passed` );
process.exit( failed ? 1 : 0 );
