/**
 * Links built from adapter or vendor data go through navHref (no browser, ~1s).
 *
 * Admin screen URLs, settings pages, author sites and status links arrive in
 * adapter payloads and vendor metadata; escaping alone leaves a javascript:
 * or data: scheme clickable. navHref allows http(s) and scheme-less
 * references (admin.php?page=…), and refuses every other scheme and the
 * //host forms that leave the origin.
 */
const fs = require( 'fs' );
const path = require( 'path' );

const src = fs.readFileSync( path.join( __dirname, '../assets/js/app.js' ), 'utf8' );
let pass = 0;
let fail = 0;
const check = ( label, ok, detail = '' ) => {
	ok ? pass++ : fail++;
	console.log( `${ ok ? 'PASS' : 'FAIL' }  ${ label }${ detail ? ' — ' + detail : '' }` );
};

const m = src.match( /const navHref = \( u \) => \{[\s\S]*?\n\t\};/ );
check( 'navHref exists', !! m );
if ( m ) {
	// eslint-disable-next-line no-new-func
	const navHref = new Function( `${ m[ 0 ] }; return navHref;` )();
	const cases = [
		[ 'https://example.com/wp-admin/admin.php?page=x', true ],
		[ 'admin.php?page=x', true ],
		[ '/wp-admin/options-general.php', true ],
		[ 'javascript:alert(1)', false ],
		[ 'JaVaScRiPt:alert(1)', false ],
		[ 'java\nscript:alert(1)', false ],
		[ 'java\tscript:alert(1)', false ],
		[ 'data:text/html,<b>x</b>', false ],
		[ '//evil.example', false ],
		[ '/\\evil.example', false ],
		[ '\\\\evil.example', false ],
		[ '\x01javascript:alert(1)', false ],
		[ '\x01//evil.example', false ],
		[ ' \x00https://example.com', false ],
	];
	for ( const [ url, allowed ] of cases ) {
		const out = navHref( url );
		check( `navHref ${ allowed ? 'keeps' : 'refuses' } ${ JSON.stringify( url ) }`, allowed ? out !== '' : out === '', JSON.stringify( out ) );
	}
}

// Every href fed from adapter or vendor data, by the expression that carries
// it. A new one belongs here, and in navHref.
const fed = [ 'c.credentialsUrl', 'activityAdmin', 'r.adminUrl', 'txn.url', 'a.href', 'setup.href', 'data.adminUrl', 'sec.adminUrl', 'first.href', 'p.author_uri', 't.author_uri', 'g.settingsUrl', 'c.adminUrl', 'd.adminUrl', 'p.adminUrl', 'en.href', 'l.url', 'fgb.group.adminUrl' ];
for ( const e of fed ) {
	const bare = src.split( `href="\${ esc( ${ e } ) }` ).length - 1;
	check( `${ e } never reaches an href unchecked`, 0 === bare, bare ? `${ bare } bare` : '' );
}
check( 'button links in the editor go through authorHref', /aOpen \+= ` href="\$\{ esc\( authorHref\( url \) \) \}"`/.test( src ) );
check( 'descriptor action hrefs go through navHref (surfaceFillHref)', /function surfaceFillHref[\s\S]{0,600}return navHref\( tpl\.replace/.test( src ) );

// Author links: anything but a scheme that runs code.
const a = src.match( /const authorHref = \( u \) => \{[\s\S]*?\n\t\};/ );
check( 'authorHref exists', !! a );
if ( a ) {
	// eslint-disable-next-line no-new-func
	const authorHref = new Function( `${ a[ 0 ] }; return authorHref;` )();
	for ( const [ url, allowed ] of [
		[ 'contact/', true ], [ '?add-to-cart=12', true ], [ 'sms:+15555550100', true ], [ 'https://example.com', true ], [ 'mailto:a@example.com', true ],
		[ 'javascript:alert(1)', false ], [ 'java\nscript:alert(1)', false ], [ '\x01javascript:alert(1)', false ], [ 'data:text/html,x', false ], [ 'VBScript:x', false ],
	] ) {
		const out = authorHref( url );
		check( `authorHref ${ allowed ? 'keeps' : 'refuses' } ${ JSON.stringify( url ) }`, allowed ? out !== '' : out === '', JSON.stringify( out ) );
	}
}

console.log( `\nsafe-links: ${ pass }/${ pass + fail } passed` );
process.exit( fail ? 1 : 0 );
