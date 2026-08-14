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
		bad.length ? `${ bad.length } missing, all:\n      ` + bad.slice( 0, 40 ).join( '\n      ' ) : '' );
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

/* ---------------------------------------------------------------------------
 * 7. PHP: no unwrapped prose literals in the REST/adapter layer.
 *    make-pot only sees wrapped strings, so an unwrapped PHP sentence is
 *    invisible to every other layer of the pipeline: the greeting shipped
 *    English into thirteen locales this way. Heuristics keep the noise out —
 *    Title Case Throughout reads as a product name ('WP Super Cache'), and
 *    those stay English on purpose — and what remains must be justified in
 *    the allowlist below.
 * ------------------------------------------------------------------------ */
// Technical values a translator cannot improve. Keep SHORT, justify each.
const PHP_PROSE_ALLOW = new Set( [
	'noindex, nofollow',            // robots meta content value
	'English (United States)',      // locale pickers show native names
	'7 days ago',                   // date_query relative-date value
	'Unknown User',                 // WSAL's own DB sentinel, matched by value
	// stripos() needles against VENDOR API response text (English by the
	// vendor's choice): translating them breaks the classification.
	'No route was found',
	'activation limit',
	'another site',
	'in use',
] );
{
	const SQL = new Set( [ 'SELECT', 'FROM', 'WHERE', 'AND', 'ORDER', 'BY', 'LIMIT', 'DESC', 'ASC', 'JOIN', 'COUNT', 'DISTINCT', 'NOT', 'NULL', 'IN', 'AS', 'SET', 'UPDATE', 'DELETE', 'INSERT', 'INTO', 'SHOW', 'TABLES', 'LIKE', 'ON', 'IF', 'EXISTS', 'CREATE', 'TABLE', 'KEY', 'INT', 'VARCHAR' ] );
	const CONNECT = new Set( [ 'and', 'of', 'for', 'the', 'to', 'in', 'with', 'on', 'by', 'a', 'at' ] );
	// Every word capitalized (connectors aside) = a proper name, not a sentence.
	const titleCase = ( v ) => {
		const words = v.trim().split( /\s+/ );
		if ( words.length < 2 || ! /^[A-Z0-9(]/.test( v.trim() ) ) return false;
		return words.every( ( w ) => /^[A-Z0-9(&…]/.test( w ) || CONNECT.has( w.replace( /[^a-z]/gi, '' ).toLowerCase() ) );
	};
	const prose = ( v ) => {
		if ( ! v.includes( ' ' ) ) return false;
		const core = v.replace( /%[sd]|%\d\$s/g, '' );
		if ( ! /^[A-Za-z0-9 ,.'’…!?%\-—:()&"]+$/.test( core ) ) return false;
		const words = v.match( /[A-Za-z]{2,}/g ) || [];
		if ( words.length < 2 ) return false;
		if ( words.some( ( w ) => SQL.has( w ) && w === w.toUpperCase() ) ) return false;
		return true;
	};
	const skip = ( v ) => {
		const t = v.trim();
		if ( PHP_PROSE_ALLOW.has( t ) ) return true;
		if ( titleCase( t ) ) return true;                       // product name
		if ( /^\(/.test( t ) ) return true;                      // css media query shape
		if ( /^(wp|disembark|composer|git|npm) /.test( t ) ) return true; // CLI command
		if ( /^[A-Za-z-]+: ?(\S+)?$/.test( t ) ) return true;    // header line / prefix
		if ( t.includes( '://' ) ) return true;                  // URL
		if ( /^[a-z_]+(, ?[a-z_]+)+$/.test( t ) ) return true;   // column/identifier list
		if ( t.includes( '-' ) && /^[a-z][a-z0-9-]*( [a-z][a-z0-9-]*)+$/.test( t ) ) return true; // class list
		return false;
	};
	const WRAP = /\b(__|_n|_x|esc_html__|esc_attr__|esc_html_e|_e)\s*\(/;
	// Surface-contract validation diagnostics are developer-facing by design:
	// they quote literal descriptor keys at extension authors.
	const DIAG = /^[a-z_]+(\[\])?:? ?[a-z]/;
	const bad = [];
	for ( const f of walkPhp( path.join( ROOT, 'includes' ) ).concat( [ path.join( ROOT, 'minn-admin.php' ) ] ) ) {
		const rel = path.relative( ROOT, f );
		let text = fs.readFileSync( f, 'utf8' );
		text = text.replace( /\/\*[\s\S]*?\*\//g, ( c ) => c.replace( /[^\n]/g, ' ' ) );
		const lines = text.split( '\n' );
		for ( let i = 0; i < lines.length; i++ ) {
			const line = lines[ i ].replace( /(^|\s)\/\/.*$/, '$1' ).replace( /(^|\s)#.*$/, '$1' );
			if ( WRAP.test( line ) ) continue;
			// Multi-line gettext: an open __()/_n() on a recent line owns this literal.
			const back = lines.slice( Math.max( 0, i - 3 ), i ).join( '\n' );
			if ( /\b(__|_n|_x|esc_html__|esc_attr__)\s*\([^)]*$/.test( back ) ) continue;
			const re = /'((?:[^'\\]|\\.)*)'|"((?:[^"\\]|\\.)*)"/g;
			let m;
			while ( ( m = re.exec( line ) ) ) {
				const v = m[ 1 ] != null ? m[ 1 ] : m[ 2 ];
				if ( ! prose( v ) || skip( v ) ) continue;
				if ( 'includes/class-minn-admin-surfaces.php' === rel && DIAG.test( v ) ) continue;
				bad.push( `${ rel }:${ i + 1 }  '${ v.slice( 0, 60 ) }'` );
			}
		}
	}
	check( 'No unwrapped prose literals in PHP', bad.length === 0,
		bad.length ? `${ bad.length } found:\n      ` + bad.slice( 0, 12 ).join( '\n      ' ) : '' );
}

/* ---------------------------------------------------------------------------
 * 8. app.js: no unwrapped prose in CODE-context string literals.
 *    Check 2 owns template TEXT nodes; this owns the blind spots that shipped
 *    English into thirteen locales: literals in ${ } interpolation slots,
 *    data-array labels, string concatenation, and toast() arguments. A
 *    single-quoted literal that reads as prose (a space, two alphabetic
 *    words, prose-safe characters) is display text unless justified below.
 * ------------------------------------------------------------------------ */
// Keep SHORT, justify each: this is the escape hatch that lets the guard rot.
const JS_PROSE_ALLOW = new Set( [
	' MB', ' KB', ' B',             // fmtBytes unit suffixes; units stay untranslated
	'add column',                   // slash-menu MATCH keyword (data), its label is wrapped
	// q: search terms sent to the wp.org plugin API, which indexes English.
	'contact form', 'code snippets', 'gutenberg blocks', 'custom fields',
	// One line of the REST cheat sheet: a developer document composed in
	// English by design (its sibling lines are template text, also English).
	'- (nothing beyond core detected)',
] );
{
	const CONNECT = new Set( [ 'and', 'of', 'for', 'the', 'to', 'in', 'with', 'on', 'by', 'a', 'at' ] );
	const titleCase = ( v ) => {
		const words = v.trim().split( /\s+/ );
		if ( words.length < 2 || ! /^[A-Z0-9(]/.test( v.trim() ) ) return false;
		return words.every( ( w ) => /^[A-Z0-9(&…]/.test( w ) || CONNECT.has( w.replace( /[^a-z]/gi, '' ).toLowerCase() ) );
	};
	const prose = ( v ) => {
		if ( ! v.includes( ' ' ) ) return false;
		const core = v.replace( /%[sd]|%\d\$s/g, '' );
		if ( ! /^[A-Za-z0-9 ,.'’…!?%\-—:()&]+$/.test( core ) ) return false;
		return ( v.match( /[A-Za-z]{2,}/g ) || [] ).length >= 2;
	};
	const noise = ( v ) => {
		const t = v.trim();
		if ( ! t || JS_PROSE_ALLOW.has( v ) || JS_PROSE_ALLOW.has( t ) ) return true;
		if ( titleCase( t ) ) return true;                            // proper name
		if ( /^[.#(\[]/.test( t ) ) return true;                      // selector / media query
		if ( v.includes( 'minn-' ) || /^(?:is|has|with|wp|tag)-/.test( t ) ) return true; // class fragment
		if ( v.includes( ',' ) && /^(?:[a-z0-9]+(?:-[a-z0-9]+)*)(?:,\s*[a-z0-9]+(?:-[a-z0-9]+)*)*$/i.test( t ) ) return true; // tag/selector list
		if ( t === 'use strict' || t === 'noreferrer noopener' ) return true;
		return false;
	};
	// Literal is the first (or plural) argument of __()/_n() already.
	const wrapped = ( at ) => {
		const before = src.slice( Math.max( 0, at - 240 ), at );
		return /(?:\b__|\b_n)\(\s*$/.test( before ) || /\b_n\(\s*'(?:[^'\\]|\\.)*'\s*,\s*$/.test( before );
	};
	const bad = [];
	let i = 0;
	while ( i < src.length ) {
		if ( ( st[ i ] === SQ || st[ i ] === DQ ) && ( i === 0 || st[ i - 1 ] !== st[ i ] ) ) {
			let j = i;
			while ( j < src.length && st[ j ] === st[ i ] ) j++;
			const v = src.slice( i + 1, j - 1 );
			if ( prose( v ) && ! noise( v ) && ! wrapped( i ) ) bad.push( `app.js:${ lineOf( i ) }  '${ v.slice( 0, 60 ) }'` );
			i = j;
		} else i++;
	}
	// Ternary pairs of capitalized single words in code context are display
	// branches ('Activating' : 'Deactivating') unless both are proper names.
	const tern = /\?\s*'([A-Z][A-Za-z]+)'\s*:\s*'([A-Z][A-Za-z]+)'/g;
	let m;
	while ( ( m = tern.exec( src ) ) ) {
		if ( st[ m.index ] !== 0 || wrapped( m.index ) ) continue;
		bad.push( `app.js:${ lineOf( m.index ) }  ? '${ m[ 1 ] }' : '${ m[ 2 ] }'` );
	}
	check( 'No unwrapped prose in app.js code context', bad.length === 0,
		bad.length ? `${ bad.length } found:\n      ` + bad.slice( 0, 12 ).join( '\n      ' ) : '' );
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
