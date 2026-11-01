/**
 * The overlay and modal binders look up their controls inside their own
 * root, never document-wide (no browser, <1 s).
 *
 * Stored post content sits in the same document as the notification panel
 * and every modal, and post kses keeps class and data-* for every role. A
 * document-wide $$( '[data-nupd]' ) in the panel's binder attached the
 * panel's click handler to a Contributor's paragraph wearing that attribute
 * too, so a later click on the post text started a plugin update or replayed
 * a vendor notice action, and wrote "Working…" into the post. Inside these
 * binders every $$() takes a root, and every $() does unless it looks up an
 * id (content cannot carry minn- ids: the neutralizer parks them).
 */
const fs = require( 'fs' );
const path = require( 'path' );

const src = fs.readFileSync( path.join( __dirname, '..', 'assets', 'js', 'app.js' ), 'utf8' );

const results = [];
const check = ( label, ok, detail = '' ) => {
	results.push( ok );
	console.log( `${ ok ? 'PASS' : 'FAIL' }  ${ label }${ detail ? ' — ' + detail : '' }` );
};
const fnBody = ( name ) => {
	const at = src.indexOf( `\tfunction ${ name }(` );
	if ( at < 0 ) return '';
	const end = src.indexOf( '\n\t}\n', at );
	return src.slice( at, end < 0 ? at + 3000 : end );
};
const lineOf = ( offset ) => src.slice( 0, offset ).split( '\n' ).length;

// A call whose only argument is a selector: a string, a template or a name.
const BARE_ALL = /\$\$\(\s*(?:'[^']*'|`[^`]*`|[A-Za-z_$][\w$.]*)\s*\)/g;
const BARE_ONE = /(?<![\w$])\$\(\s*(?:'(?!#)[^']*'|`(?!#)[^`]*`|[A-Za-z_$][\w$.]*)\s*\)/g;

[ 'renderOverlays', 'bindModal', 'bindStoreFormModal', 'bindStoreSecretModal', 'bindThemeInstallModal', 'bindPluginInstallModal', 'bindPluginInstallModalRest', 'bindRevisionModal', 'paintPluginUpdateQueue' ].forEach( ( fn ) => {
	const body = fnBody( fn );
	check( `${ fn } exists (the guard is reading the right file)`, body.length > 0 );
	const base = src.indexOf( body );
	const bad = [];
	for ( const re of [ BARE_ALL, BARE_ONE ] ) {
		re.lastIndex = 0;
		let m;
		while ( ( m = re.exec( body ) ) ) bad.push( `app.js:${ lineOf( base + m.index ) }  ${ m[ 0 ].slice( 0, 100 ) }` );
	}
	check( `${ fn } looks up nothing document-wide`, 0 === bad.length, bad.length ? '\n      ' + bad.join( '\n      ' ) : '' );
} );
check( 'bindModal\'s root is the overlays container, found by id', /const ovr = \$\( '#minn-overlays' \);/.test( fnBody( 'bindModal' ) ) );
check( 'renderOverlays\' root is the overlays container, found by id', /const root = \$\( '#minn-overlays' \);/.test( fnBody( 'renderOverlays' ) ) );

// A document-wide lookup of Minn's chrome by class also finds stored
// content wearing that class (post kses keeps class): the tab-strip pass
// wrapped a content .minn-tabs in Minn's buttons and the next save stored
// them. Each such sweep keeps only chrome (isChrome) or Minn's previews
// (isMinnPreview), or is listed here with why no content can be there.
const CHROME_REVIEWED = {
	"$$( '.minn-refund-qty' ).forEach( ( q ) => {": 'order refund modal inputs; the order page shows no post content',
	"$$( '.minn-refund-qty' ).forEach( ( inp ) =>": 'order refund modal inputs; the order page shows no post content',
	"$$( '.minn-order-body .minn-switch, .minn-order-page .minn-switch' ).forEach( ( sw ) => {": 'order page switches; no post content on that route',
	"$$( '.minn-coupon-page .minn-switch' ).forEach( ( sw ) => sw.addEventListener( 'click', () => {": 'coupon page switches; no post content on that route',
};
const chromeSeen = new Set();
const chromeBad = [];
src.split( '\n' ).forEach( ( line, i ) => {
	const m = line.match( /\$\$\(\s*['`]\.minn-[^'`]*['`]\s*\)(\.(?:filter|some)\(\s*(isChrome|isMinnPreview|isRealIsland)\s*\))?/ );
	if ( ! m || m[ 1 ] ) return;
	const t = line.trim();
	if ( Object.prototype.hasOwnProperty.call( CHROME_REVIEWED, t ) ) {
		chromeSeen.add( t );
		return;
	}
	chromeBad.push( `app.js:${ i + 1 }  ${ t.slice( 0, 120 ) }` );
} );
check( 'every document-wide sweep of Minn chrome by class skips content hosts', 0 === chromeBad.length, chromeBad.length ? '\n      ' + chromeBad.join( '\n      ' ) : '' );
check( 'every reviewed chrome sweep still exists', Object.keys( CHROME_REVIEWED ).every( ( k ) => chromeSeen.has( k ) ), Object.keys( CHROME_REVIEWED ).filter( ( k ) => ! chromeSeen.has( k ) ).join( ' | ' ) );
check( 'isChrome excludes every host of stored content',
	/const CONTENT_HOSTS = '#minn-editor-body, \.minn-rt-body, \.minn-revision-preview, \.minn-diff, \.minn-island-preview, \.minn-email-frame';/.test( src ) );

const failed = results.filter( ( r ) => ! r ).length;
console.log( `\noverlay-scope-guard: ${ results.length - failed }/${ results.length } passed` );
process.exitCode = failed ? 1 : 0;
