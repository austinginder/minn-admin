/**
 * Record the live app for the static demo on minnadmin.com.
 *
 * Signs in to a dev site through the test harness, captures the boot payload
 * (window.MINN), then visits the overview, a few core views and every surface
 * the catalog links that is active on this site. On each it walks what a
 * visitor would click: every view, tab and filter, the first rows of a list
 * (each opens its own page), and the next page. Every GET the app makes to
 * the REST API or admin-ajax is kept, plus the upload images it shows. Writes
 * are refused while recording, so the walk never changes the site.
 *
 * Nothing is written unless the scrubbed recording is clean: the site's host
 * and filesystem path, Gravatar hashes, nonces, every stored credential and
 * every email outside the example domains must be gone. MINN_DEMO_SCRUB may
 * name a JSON file of extra literal replacements ({ "find": "replace" }) for
 * fixture data that carries a real person's details; keep that file outside
 * the repository. A refused run leaves its unscrubbed capture at
 * <out-dir>.raw.json, so a new mapping can be tried with --from-raw instead
 * of recording again.
 *
 * Usage (from the plugin root):
 *   MINN_TEST_PASS=… node bin/demo/record.js <out-dir>
 *   node bin/demo/record.js <out-dir> --from-raw    (scrub a refused capture)
 *   node bin/demo/record.js <out-dir> --only=gravity-forms,content
 *                                                    (try the walk on a few views)
 *   --jobs=N sets how many browsers walk at once (3); MINN_DEMO_TRACE=1
 *   prints each step.
 * then bin/demo/build.js <out-dir> <theme-dir>. See bin/demo/README.md.
 */
'use strict';

const fs = require( 'fs' );
const https = require( 'https' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const ROOT = path.resolve( __dirname, '..', '..' );
const { BASE, WP, launch, login, closeBrowser } = require( path.join( ROOT, 'tests', 'helpers.js' ) );

const OUT = process.argv[ 2 ] && ! process.argv[ 2 ].startsWith( '--' ) ? path.resolve( process.argv[ 2 ] ) : '';
const RAW = OUT + '.raw.json';
const FROM_RAW = process.argv.includes( '--from-raw' );
const ONLY = ( process.argv.find( ( a ) => a.startsWith( '--only=' ) ) || '' ).slice( 7 ).split( ',' ).filter( Boolean );
const JOBS = Math.max( 1, Number( ( process.argv.find( ( a ) => a.startsWith( '--jobs=' ) ) || '' ).slice( 7 ) ) || 3 );
if ( ! OUT ) {
	console.error( 'Usage: node bin/demo/record.js <out-dir> [--from-raw | --only=<route>,…] [--jobs=N]' );
	process.exit( 2 );
}

// Core views worth a visit beside the plugin surfaces, so the sidebar of the
// demo leads somewhere. The overview loads with the app itself.
const CORE_ROUTES = [ 'content', 'media', 'comments', 'orders', 'products', 'customers', 'users' ];
// How far the walk goes on each view: the rows opened in each view's own
// list (so a page's next and previous lead somewhere; the core lists, which
// a visitor opens first, go further), how many tab combinations of two
// groups are tried before tabs go one at a time, and a time limit per view
// on a slow dev box.
const FIRST_ROWS = 4;
const CORE_ROWS = 10;
const MAX_COMBOS = 12;
const WALK_MS = 5 * 60000;
// A POST that only renders (an email preview) reads like a GET: it is
// recorded, and replay.js answers it. Keep the two patterns the same.
const READ_POST = /\/preview$/;
// MINN_DEMO_TRACE=1 prints each step of the walk.
const T0 = Date.now();
const trace = process.env.MINN_DEMO_TRACE ? ( ...m ) => console.log( `   ${ ( ( Date.now() - T0 ) / 1000 ).toFixed( 1 ) }s`, ...m ) : () => {};
// Domains reserved for documentation and testing (RFC 2606): no real inbox.
const EXAMPLE_DOMAINS = /@([a-z0-9-]+\.)*(example\.(com|org|net)|[a-z0-9-]+\.(test|example|invalid))$/i;
const AVATAR = 'data:image/svg+xml,' + encodeURIComponent( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96"><rect width="96" height="96" fill="#8b8b96"/><circle cx="48" cy="38" r="17" fill="#d4d4da"/><path d="M14 92c4-20 18-30 34-30s30 10 34 30z" fill="#d4d4da"/></svg>' );

const catalog = JSON.parse( fs.readFileSync( path.join( __dirname, 'catalog.json' ), 'utf8' ) );
const catalogSurfaces = [ ...new Set( catalog.categories.flatMap( ( c ) => c.plugins.map( ( p ) => p.surface ).filter( Boolean ) ) ) ];
const extra = process.env.MINN_DEMO_SCRUB ? JSON.parse( fs.readFileSync( process.env.MINN_DEMO_SCRUB, 'utf8' ) ) : {};

const escapeRe = ( s ) => s.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
// A URL or path as it may appear inside a string: JSON inside HTML inside
// JSON (an SEO plugin's schema in a preview) escapes each slash once per
// level, so every "/" may come with any run of backslashes before it.
const slashy = ( s ) => escapeRe( s ).replace( /\//g, '\\\\*/' );

// Every stored value that reads like a credential: option values, and the
// leaves of serialized or JSON options, under a name that says password,
// token, secret, key or licence, plus the keys and salts in wp-config.php.
// A Disembark view prints its connect command with the site's token, for
// one. The scrub hides each value and the audit refuses any that survives.
// They stay in memory: never in a file, never in a message.
function siteSecrets() {
	const php = `
		global $wpdb;
		$named = '/(pass(word|wd|phrase)?|secret|token|licen[cs]e|api[_-]?key|apikey|auth[_-]?key|access[_-]?key|private[_-]?key|salt|credential|signature|bearer|webhook|(^|[_-])key$)/i';
		$found = array();
		$take  = function ( $where, $v, $any ) use ( &$found ) {
			if ( ! is_string( $v ) && ! is_int( $v ) ) return;
			$v = trim( (string) $v );
			if ( strlen( $v ) < 8 || strlen( $v ) > 4096 ) return;
			if ( ! $any ) {
				if ( preg_match( '/\\s|@|:\\/\\//', $v ) ) return;
				// Slugs, paths, versions, dates and product names (JetSmartFilters)
				// are words and numbers between separators. A key mixes letters
				// and digits in one run, or upper and lower case about evenly,
				// or comes in blocks (ABCD-1234-EFGH-5678).
				$keyish = (bool) preg_match( '/^[A-Z0-9]{4,}(-[A-Z0-9]{4,}){2,}$/', $v );
				foreach ( preg_split( '/[^A-Za-z0-9]+/', $v ) as $run ) {
					$upper = strlen( preg_replace( '/[^A-Z]/', '', $run ) ) / max( 1, strlen( $run ) );
					if ( ( strlen( $run ) >= 6 && preg_match( '/[a-z]/i', $run ) && preg_match( '/\\d/', $run ) ) || ( strlen( $run ) >= 16 && $upper >= 0.3 && $upper <= 0.7 ) ) $keyish = true;
				}
				if ( ! $keyish ) return;
			}
			$found[ $v ] = $where;
		};
		$walk = function ( $where, $v, $hit, $depth ) use ( &$walk, $take, $named ) {
			if ( is_string( $v ) && $depth < 6 && preg_match( '/^\\s*[\\[{]/', $v ) ) {
				$j = json_decode( $v, true );
				if ( is_array( $j ) ) $v = $j;
			}
			if ( is_object( $v ) ) $v = get_object_vars( $v );
			if ( is_array( $v ) ) {
				if ( $depth > 8 ) return;
				foreach ( $v as $k => $x ) $walk( $where, $x, $hit || preg_match( $named, (string) $k ), $depth + 1 );
				return;
			}
			if ( $hit ) $take( $where, $v, false );
		};
		foreach ( $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE LENGTH(option_value) < 2000000" ) as $row ) {
			$walk( $row->option_name, maybe_unserialize( $row->option_value ), (bool) preg_match( $named, $row->option_name ), 0 );
		}
		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT', 'DB_PASSWORD' ) as $c ) {
			if ( defined( $c ) ) $take( $c, constant( $c ), true );
		}
		echo wp_json_encode( $found );`;
	const out = execFileSync( 'wp', [ '--path=' + WP, '--skip-plugins', '--skip-themes', 'eval', php ], { encoding: 'utf8', maxBuffer: 256 * 1024 * 1024 } );
	return Object.entries( JSON.parse( out.slice( out.indexOf( '{' ) ) ) ).map( ( [ value, where ] ) => ( { value, where } ) );
}

function scrubber( boot, secrets ) {
	const origin = new URL( BASE ).origin;
	const host = new URL( BASE ).host;
	const root = fs.realpathSync( WP ).replace( /\/$/, '' );
	const rules = [
		// First, before any other rule can change a value around one. One
		// pattern for all of them (longest first), since every string of the
		// recording passes through each rule.
		...( secrets.length ? [ [ new RegExp( [ ...new Set( secrets.flatMap( ( { value } ) => [ value, encodeURIComponent( value ) ] ) ) ]
			.sort( ( a, b ) => b.length - a.length ).map( slashy ).join( '|' ), 'g' ), '••••••••' ] ] : [] ),
		[ new RegExp( slashy( root ), 'g' ), '/var/www/html' ],
		[ new RegExp( slashy( WP.replace( /\/$/, '' ) ), 'g' ), '/var/www/html' ],
		[ new RegExp( slashy( origin + '/wp-content/uploads/' ), 'g' ), '{{uploads}}/' ],
		[ new RegExp( slashy( origin ), 'g' ), 'https://example.com' ],
		[ new RegExp( escapeRe( host ), 'g' ), 'example.com' ],
		// The tail stops at a backslash too: inside an escaped string it is the
		// escape of the closing quote, and eating it would break that string.
		[ /https?:(\\*\/){2}(secure\.|[0-9]\.)?gravatar\.com\\*\/avatar\\*\/[^"'\s)<>\\]*/g, AVATAR ],
		// PDF Invoices & Packing Slips signs its links with a nonce of its own.
		[ /(_wpnonce=|access_key=)[0-9a-f]{10}/g, '$1demo' ],
		// Only a real nonce: an empty one would match between every character.
		...( /^[0-9a-f]{10}$/.test( boot.nonce || '' ) ? [ [ new RegExp( boot.nonce, 'g' ), 'demo' ] ] : [] ),
		...Object.entries( extra ).map( ( [ find, to ] ) => [ new RegExp( escapeRe( find ), 'g' ), to ] ),
	];
	const str = ( s ) => rules.reduce( ( acc, [ re, to ] ) => acc.replace( re, to ), s );
	const walk = ( v ) => {
		if ( typeof v === 'string' ) return str( v );
		if ( Array.isArray( v ) ) return v.map( walk );
		if ( v && typeof v === 'object' ) {
			const o = {};
			for ( const [ k, val ] of Object.entries( v ) ) o[ str( k ) ] = walk( val );
			return o;
		}
		return v;
	};
	// JSON bodies are scrubbed value by value, so an escaped "https:\/\/" URL
	// in the raw text cannot slip past a pattern written for the plain form.
	const body = ( text ) => {
		try {
			return JSON.stringify( walk( JSON.parse( text ) ) );
		} catch ( e ) {
			return str( text.replace( /\\\//g, '/' ) );
		}
	};
	return { walk, body, host, root };
}

// Upload thumbnails keep the camera's metadata (model, time, GPS position):
// the demo needs the pixels only. JPEG loses its Exif/XMP, IPTC, comment and
// multi-picture segments (the ICC profile and the Adobe colour segment stay,
// they change how it looks); PNG its text, time and eXIf chunks; WebP its
// EXIF and XMP chunks and the header flags that announce them. Any other
// format answers null and stays out of the demo.
function stripMeta( buf ) {
	if ( buf[ 0 ] === 0xff && buf[ 1 ] === 0xd8 ) {
		const out = [ buf.subarray( 0, 2 ) ];
		let i = 2;
		while ( i + 4 <= buf.length && buf[ i ] === 0xff ) {
			const marker = buf[ i + 1 ];
			if ( 0xda === marker ) {
				out.push( buf.subarray( i ) );
				return Buffer.concat( out );
			}
			const len = buf.readUInt16BE( i + 2 );
			const seg = buf.subarray( i, i + 2 + len );
			const icc = 0xe2 === marker && 'ICC_PROFILE' === seg.subarray( 4, 15 ).toString( 'latin1' );
			if ( ! ( 0xe1 === marker || 0xed === marker || 0xfe === marker || ( 0xe2 === marker && ! icc ) ) ) out.push( seg );
			i += 2 + len;
		}
		return null;
	}
	if ( buf.subarray( 0, 8 ).equals( Buffer.from( [ 0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a ] ) ) ) {
		const out = [ buf.subarray( 0, 8 ) ];
		let i = 8;
		while ( i + 12 <= buf.length ) {
			const len = buf.readUInt32BE( i );
			const type = buf.subarray( i + 4, i + 8 ).toString( 'latin1' );
			if ( ! [ 'eXIf', 'tEXt', 'iTXt', 'zTXt', 'tIME' ].includes( type ) ) out.push( buf.subarray( i, i + 12 + len ) );
			i += 12 + len;
			if ( 'IEND' === type ) return Buffer.concat( out );
		}
		return null;
	}
	if ( 'RIFF' === buf.subarray( 0, 4 ).toString( 'latin1' ) && 'WEBP' === buf.subarray( 8, 12 ).toString( 'latin1' ) ) {
		const chunks = [];
		let i = 12;
		while ( i + 8 <= buf.length ) {
			const type = buf.subarray( i, i + 4 ).toString( 'latin1' );
			const len = buf.readUInt32LE( i + 4 );
			const end = i + 8 + len + ( len % 2 );
			const chunk = Buffer.from( buf.subarray( i, end ) );
			// VP8X flag bits: 0x08 EXIF present, 0x04 XMP present.
			if ( 'VP8X' === type ) chunk[ 8 ] &= ~0x0c;
			if ( 'EXIF' !== type && 'XMP ' !== type ) chunks.push( chunk );
			i = end;
		}
		const body = Buffer.concat( chunks );
		const head = Buffer.alloc( 12 );
		head.write( 'RIFF', 0, 'latin1' );
		head.writeUInt32LE( body.length + 4, 4 );
		head.write( 'WEBP', 8, 'latin1' );
		return Buffer.concat( [ head, body ] );
	}
	return null;
}

function audit( text, { host, root, nonce, secrets } ) {
	const found = [];
	const look = ( label, re ) => {
		const m = text.match( re );
		if ( m ) found.push( label + ': ' + [ ...new Set( m ) ].slice( 0, 8 ).join( ', ' ) );
	};
	// Named by where it is stored, never by its value.
	const left = secrets.filter( ( { value } ) => text.includes( value ) || text.includes( encodeURIComponent( value ) ) || text.includes( JSON.stringify( value ).slice( 1, -1 ) ) );
	if ( left.length ) found.push( 'a stored credential from ' + [ ...new Set( left.map( ( s ) => s.where ) ) ].slice( 0, 12 ).join( ', ' ) );
	look( 'site host', new RegExp( escapeRe( host ), 'g' ) );
	look( 'a .localhost host', /[a-z0-9-]+\.localhost\b/gi );
	look( 'filesystem path', new RegExp( slashy( root ) + '|' + slashy( '/Users/' ) + '[^/"\\s\\\\]+', 'g' ) );
	look( 'gravatar', /gravatar\.com\\*\/avatar\\*\/[0-9a-f]{8,}/g );
	look( 'nonce', new RegExp( '_wpnonce=(?!demo)[0-9a-f]{10}' + ( /^[0-9a-f]{10}$/.test( nonce || '' ) ? '|' + nonce : '' ), 'g' ) );
	const emails = ( text.match( /[a-z0-9._%+-]+@[a-z0-9-]+(\.[a-z0-9-]+)*\.[a-z]{2,}/gi ) || [] ).filter( ( e ) => ! EXAMPLE_DOMAINS.test( e ) );
	if ( emails.length ) found.push( 'email outside the example domains: ' + [ ...new Set( emails ) ].slice( 0, 12 ).join( ', ' ) );
	return found;
}

// The server answers again: a dev box under load restarts its PHP server, and
// a request made during that window fails at once for no reason of the view's.
function answers() {
	return new Promise( ( resolve ) => {
		const req = https.get( BASE + '/wp-login.php', { rejectUnauthorized: false, timeout: 20000 }, ( res ) => {
			res.resume();
			resolve( res.statusCode === 200 );
		} );
		req.on( 'timeout', () => req.destroy() );
		req.on( 'error', () => resolve( false ) );
	} );
}
async function serverBack() {
	const until = Date.now() + 300000;
	while ( Date.now() < until ) {
		if ( await answers() ) return;
		await new Promise( ( r ) => setTimeout( r, 5000 ) );
	}
}

async function capture() {
	const origin = new URL( BASE ).origin;
	const responses = {};
	// Which views asked for each response ('' for the app's own start): the
	// build keeps what one view alone asked for in a file of that view's.
	const askedBy = {};
	const images = new Map();
	const errors = [];
	let restUrl = origin + '/wp-json/';
	let ajaxUrl = origin + '/wp-admin/admin-ajax.php';

	const api = ( req ) => req.url().startsWith( restUrl ) || req.url().startsWith( ajaxUrl );
	const readPost = ( req ) => 'POST' === req.method() && req.url().startsWith( restUrl ) && READ_POST.test( req.url().slice( restUrl.length ).split( '?' )[ 0 ] );
	const keep = async ( res, view ) => {
		const u = res.url();
		const req = res.request();
		if ( req.method() !== 'GET' && ! readPost( req ) ) return false;
		try {
			if ( req.resourceType() === 'image' && u.startsWith( origin + '/wp-content/uploads/' ) && res.ok() ) {
				images.set( u.slice( ( origin + '/wp-content/uploads/' ).length ).split( '?' )[ 0 ], await res.body() );
				return false;
			}
			let key = null;
			if ( u.startsWith( restUrl ) ) key = 'rest:' + u.slice( restUrl.length );
			else if ( u.startsWith( ajaxUrl ) ) key = 'ajax:' + u.slice( ajaxUrl.length );
			if ( ! key ) return false;
			if ( readPost( req ) ) key = 'POST ' + key;
			( askedBy[ key ] = askedBy[ key ] || new Set() ).add( view );
			// List totals and page counts ride in headers, not the body.
			const h = res.headers();
			const headers = {};
			for ( const name of [ 'x-wp-total', 'x-wp-totalpages' ] ) {
				if ( undefined !== h[ name ] ) headers[ name ] = h[ name ];
			}
			const fresh = ! responses[ key ];
			responses[ key ] = { status: res.status(), body: await res.text(), ...( Object.keys( headers ).length ? { headers } : {} ) };
			return fresh;
		} catch ( e ) { /* the page navigated away mid-read */ }
		return false;
	};
	// One browser of the walk. Several walk at once from one queue of
	// views, each with its own page and its own count of the API calls in
	// flight; what they record goes into one recording.
	const recorder = ( name ) => {
		let browser = null;
		let page = null;
		let crashed = false;
		// A view is recorded once its own API calls have answered. networkidle
		// never comes on a busy dev site (the app polls), so count in-flight API
		// requests instead and wait for a quiet spell, up to a ceiling.
		const inflight = new Set();
		const note = ( ...m ) => trace( `[${ name }]`, ...m );
		let added = 0;
		let current = '';
		// Chrome can die under memory pressure on a loaded dev box, and every
		// later view would then fail at once: open a fresh browser instead.
		const open = async () => {
			if ( browser ) await closeBrowser( browser );
			await serverBack();
			const l = await launch();
			browser = l.browser;
			page = l.page;
			inflight.clear();
			crashed = false;
			page.on( 'response', async ( res ) => {
				if ( await keep( res, current ) ) added++;
			} );
			page.on( 'request', ( req ) => api( req ) && inflight.add( req ) );
			page.on( 'requestfinished', ( req ) => inflight.delete( req ) );
			page.on( 'requestfailed', ( req ) => inflight.delete( req ) );
			page.on( 'crash', () => {
				crashed = true;
				console.warn( '  the page crashed' );
			} );
			page.on( 'pageerror', ( e ) => errors.push( e.message ) );
			// A reload asks before leaving a page that thinks it has changes:
			// leave. Any other dialog is turned down.
			page.on( 'dialog', ( d ) => ( 'beforeunload' === d.type() ? d.accept() : d.dismiss() ).catch( () => {} ) );
			// The walk clicks through real views: refuse every write the app
			// tries (opening an entry may mark it read), so the site stays as is.
			await page.route( ( u ) => u.href.startsWith( restUrl ) || u.href.startsWith( ajaxUrl ), ( route ) => {
				const req = route.request();
				if ( 'GET' === req.method() || 'HEAD' === req.method() || readPost( req ) ) return route.continue();
				return route.fulfill( { status: 403, contentType: 'application/json', body: JSON.stringify( { code: 'minn_demo', message: 'Not saved while recording the demo.', data: { status: 403 } } ) } );
			} );
			await login( page );
		};
		// The app has drawn the view: its script can take seconds to start on
		// a loaded box, and until then no API call is in flight to wait on.
		const ready = () => page.waitForFunction( () => {
			const v = document.querySelector( '#minn-view' );
			return !! v && v.children.length > 0 && ! v.querySelector( '.minn-loading' );
		}, null, { timeout: 90000 } ).catch( () => {} );
		const settle = async ( quiet = 1500 ) => {
			const until = Date.now() + 45000;
			let calm = Date.now();
			while ( Date.now() < until ) {
				await page.waitForTimeout( 250 );
				if ( inflight.size ) calm = Date.now();
				else if ( Date.now() - calm >= quiet ) return;
			}
			console.warn( '  still waiting after 45s on ' + [ ...inflight ].map( ( r ) => r.url().replace( origin, '' ) ).join( ', ' ) + '; moving on' );
		};

		// The walk. Tabs come in groups (.minn-tabs, a settings page's section
		// list): a surface's first group switches its views (Entries, Forms,
		// Notifications…), the others narrow a list (a form, a status). A row
		// opens a page of its own, or a dialog over the list.
		const TAB = '.minn-tab, .minn-settings-nav-item';
		const ROW = '.minn-table-row, .minn-media-card';
		const label = ( sig ) => ( sig.a ? `${ sig.a }=${ sig.v }` : sig.t );
		const groups = () => page.evaluate( ( sel ) => {
			const main = document.querySelector( 'main.minn-main' ) || document.body;
			const boxes = new Map();
			for ( const el of main.querySelectorAll( sel ) ) {
				if ( ! el.offsetParent || el.disabled || el.closest( '[aria-modal="true"]' ) ) continue;
				const box = el.closest( '.minn-tabs, .minn-settings-nav' ) || el.parentElement;
				if ( ! boxes.has( box ) ) boxes.set( box, { view: box.classList.contains( 'minn-view-switch' ), tabs: [] } );
				const data = [ ...el.attributes ].find( ( a ) => a.name.startsWith( 'data-' ) );
				boxes.get( box ).tabs.push( {
					sig: data ? { a: data.name, v: data.value } : { t: el.textContent.trim() },
					active: el.classList.contains( 'active' ) || 'true' === el.getAttribute( 'aria-selected' ),
				} );
			}
			return [ ...boxes.values() ].filter( ( g ) => g.tabs.length > 1 );
		}, TAB );
		// Click a tab unless it is already where it should be (on, or off for
		// a toggle such as Media's "Mine"): 'clicked', 'already', or null when
		// this view has no such tab.
		const press = async ( sig, on = true ) => {
			const r = await page.evaluate( ( [ sel, s, want ] ) => {
				const main = document.querySelector( 'main.minn-main' ) || document.body;
				const el = [ ...main.querySelectorAll( sel ) ].find( ( e ) => e.offsetParent && ! e.closest( '[aria-modal="true"]' ) && ( s.a ? e.getAttribute( s.a ) === s.v : e.textContent.trim() === s.t ) );
				if ( ! el ) return null;
				if ( want === ( el.classList.contains( 'active' ) || 'true' === el.getAttribute( 'aria-selected' ) ) ) return 'already';
				el.click();
				return 'clicked';
			}, [ TAB, sig, on ] );
			note( on ? 'tab' : 'tab off', label( sig ), r );
			if ( 'clicked' === r ) await settle( 900 );
			return r;
		};
		const back = async ( to ) => {
			for ( let n = 0; n < 4 && page.url() !== to; n++ ) {
				await page.goBack( { waitUntil: 'domcontentloaded', timeout: 30000 } ).catch( () => {} );
				await settle( 900 );
			}
			if ( page.url() !== to ) note( 'back did not reach', to.replace( BASE, '' ), 'at', page.url().replace( BASE, '' ) );
			return page.url() === to;
		};
		// Open the first row of the list that this view's walk has not opened
		// yet (a form's tab lists rows "All entries" already showed), then come
		// back to the list. Only the list is walked: a detail page's own tabs
		// are mostly form controls (a notification's "Send to"), and changing
		// one leaves the page asking to save. Answers false when no row is left,
		// or when a row opens nothing.
		const openRow = async ( seen ) => {
			const list = page.url();
			const hit = await page.evaluate( ( [ sel, done ] ) => {
				const main = document.querySelector( 'main.minn-main' ) || document.body;
				const row = [ ...main.querySelectorAll( sel ) ].find( ( r ) => r.offsetParent && ! r.closest( '[aria-modal="true"]' )
					&& [ ...r.attributes ].some( ( a ) => a.name.startsWith( 'data-' ) ) && ! done.includes( r.textContent.trim() ) );
				if ( ! row ) return null;
				row.click();
				return row.textContent.trim();
			}, [ ROW, [ ...seen ] ] );
			if ( null === hit ) return false;
			seen.add( hit );
			const opened = await page.waitForFunction( ( u ) => location.href !== u || !! document.querySelector( '[aria-modal="true"], #minn-overlays .minn-modal' ), list, { timeout: 8000 } ).then( () => true, () => false );
			note( 'row', opened ? page.url().replace( BASE, '' ) : 'opened nothing' );
			if ( ! opened ) return false;
			await settle( 900 );
			if ( page.url() === list ) {
				await page.keyboard.press( 'Escape' );
				await settle( 600 );
				return true;
			}
			return back( list );
		};
		const turnPage = async () => {
			const turned = await page.evaluate( () => {
				const nav = [ ...document.querySelectorAll( 'main.minn-main .minn-pager-btn.nav' ) ];
				const next = nav[ nav.length - 1 ];
				if ( ! next || next.disabled ) return false;
				next.click();
				return true;
			} );
			if ( ! turned ) return;
			note( 'next page' );
			await settle( 900 );
			await page.evaluate( () => {
				const prev = document.querySelector( 'main.minn-main .minn-pager-btn.nav' );
				if ( prev && ! prev.disabled ) prev.click();
			} );
			await settle( 900 );
		};
		const walk = async ( route ) => {
			const home = BASE + '/minn-admin/' + route;
			const until = Date.now() + WALK_MS;
			const reenter = async ( view ) => {
				if ( page.url() !== home && ! ( await back( home ) ) ) {
					note( 'reload', route );
					// The app may still be routing when the reload starts, and
					// Chrome reports the superseded one as aborted.
					await page.goto( home, { waitUntil: 'domcontentloaded', timeout: 120000 } ).catch( ( e ) => {
						if ( ! /ERR_ABORTED/.test( e.message ) ) throw e;
					} );
					await ready();
					await settle();
					if ( page.url() !== home ) throw new Error( 'could not return to ' + route );
				}
				if ( view ) await press( view );
			};
			// On a busy box the view switcher can draw after the list: look
			// again after a pause before walking a view without one.
			let first = await groups();
			if ( ! first.some( ( g ) => g.view ) ) {
				await page.waitForTimeout( 2500 );
				await settle();
				first = await groups();
			}
			const switcher = first.find( ( g ) => g.view );
			const views = switcher ? switcher.tabs.map( ( t ) => t.sig ) : [ null ];
			for ( const view of views ) {
				if ( Date.now() > until ) break;
				const seen = new Set();
				await reenter( view );
				const rows = CORE_ROUTES.includes( route ) ? CORE_ROWS : FIRST_ROWS;
				for ( let n = 0; n < rows && Date.now() < until && await openRow( seen ); n++ );
				await reenter( view );
				await turnPage();
				// Each other tab of the view, and both of its first two groups
				// together when that stays small (a form under Spam), in an order
				// that changes one group at a time. Groups a step doesn't set go
				// back to the view's own defaults first.
				const inner = ( await groups() ).filter( ( g ) => ! g.view );
				// A group with no tab on at first is a set of toggles: its
				// default is all of them off.
				const defaults = inner.map( ( g ) => ( g.tabs.find( ( t ) => t.active ) || {} ).sig );
				const others = inner.map( ( g, gi ) => g.tabs.filter( ( t ) => ! t.active ).map( ( t ) => ( { gi, sig: t.sig } ) ) );
				const combine = inner.length > 1 && inner[ 0 ].tabs.length * inner[ 1 ].tabs.length <= MAX_COMBOS;
				const plans = [];
				for ( const a of others[ 0 ] || [] ) {
					plans.push( [ a ] );
					if ( combine ) for ( const b of others[ 1 ] ) plans.push( [ a, b ] );
				}
				for ( const g of others.slice( 1 ) ) for ( const t of g ) plans.push( [ t ] );
				for ( const plan of plans ) {
					if ( Date.now() > until ) break;
					await reenter( view );
					for ( let gi = 0; gi < defaults.length; gi++ ) {
						if ( plan.some( ( p ) => p.gi === gi ) ) continue;
						if ( defaults[ gi ] ) await press( defaults[ gi ] );
						else for ( const t of inner[ gi ].tabs ) await press( t.sig, false );
					}
					let reached = true;
					for ( const p of plan ) if ( ! ( await press( p.sig ) ) ) reached = false;
					if ( reached ) await openRow( seen );
				}
			}
			if ( Date.now() > until ) console.warn( `  ${ route }: the walk stopped at its ${ WALK_MS / 60000 } minute limit` );
		};

		// One slow page on a loaded dev box must not cost the whole run: retry
		// a view once, then leave it out (a failed surface gets no demo
		// link). A walk cut short by the box keeps what it recorded.
		const visit = async ( route ) => {
			current = route;
			const before = added;
			let done = false;
			for ( let attempt = 1; attempt <= 2 && ! done; attempt++ ) {
				try {
					if ( crashed || page.isClosed() || ! browser.isConnected() ) await open();
					// A navigation abandons the last view's requests without always
					// reporting them finished; only this view's calls count.
					inflight.clear();
					await page.goto( BASE + '/minn-admin/' + route, { waitUntil: 'domcontentloaded', timeout: 120000 } );
					await ready();
					await settle();
					done = true;
				} catch ( e ) {
					console.warn( `  ${ route }: ${ e.message.split( '\n' )[ 0 ] }${ attempt === 1 ? '; retrying' : '; leaving it out' }` );
					if ( attempt === 1 && /ERR_CONNECTION|ERR_EMPTY_RESPONSE|Timeout/.test( e.message ) ) await serverBack();
				}
			}
			if ( ! done ) return false;
			try {
				await walk( route );
			} catch ( e ) {
				console.warn( `  ${ route }: the walk stopped early (${ e.message.split( '\n' )[ 0 ] })` );
			}
			console.log( `recorded ${ route } (${ added - before } responses)` );
			return true;
		};
		// Closing can hang (and abort a reply mid-flush): bounded, as the suites do.
		return { open, settle, visit, page: () => page, close: () => ( browser ? closeBrowser( browser ) : null ) };
	};

	const lead = recorder( 1 );
	await lead.open();
	const page = lead.page();
	await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded', timeout: 90000 } );
	await page.waitForFunction( () => window.MINN && document.querySelector( '.minn-nav-btn' ), null, { timeout: 180000 } );
	await lead.settle();
	const boot = await page.evaluate( () => JSON.parse( JSON.stringify( window.MINN ) ) );
	restUrl = boot.restUrl;
	ajaxUrl = boot.ajaxUrl;

	const active = new Set( ( boot.surfaces || [] ).map( ( s ) => s.id ) );
	const skipped = catalogSurfaces.filter( ( id ) => ! active.has( id ) );
	const visits = [ ...CORE_ROUTES, ...catalogSurfaces.filter( ( id ) => active.has( id ) ) ]
		.filter( ( r ) => ! ONLY.length || ONLY.includes( r ) );
	const queue = [ ...visits ];
	const failed = [];
	const crew = [ lead ];
	for ( let n = 2; n <= Math.min( JOBS, visits.length ); n++ ) crew.push( recorder( n ) );
	await Promise.all( crew.map( async ( r, i ) => {
		try {
			if ( i ) await r.open();
		} catch ( e ) {
			console.warn( `  browser ${ i + 1 } did not start (${ e.message.split( '\n' )[ 0 ] }); the others walk on` );
			return;
		}
		for ( let route = queue.shift(); route; route = queue.shift() ) {
			if ( ! ( await r.visit( route ) ) ) failed.push( route );
		}
		await r.close();
	} ) );
	if ( errors.length ) console.warn( 'page errors while recording (kept going):\n  ' + [ ...new Set( errors ) ].join( '\n  ' ) );
	// A run that lost most of its views is the environment failing, not a
	// smaller demo: keep the last good recording instead of shrinking it.
	if ( failed.length > visits.length / 4 ) {
		console.error( `\nNOT WRITTEN: ${ failed.length } of ${ visits.length } views failed to load (${ failed.join( ', ' ) }). Let the site settle and record again.` );
		process.exit( 1 );
	}
	return {
		boot,
		responses,
		askedBy: Object.fromEntries( Object.entries( askedBy ).map( ( [ k, v ] ) => [ k, [ ...v ] ] ) ),
		images: [ ...images ].map( ( [ rel, buf ] ) => [ rel, buf.toString( 'base64' ) ] ),
		recordedAt: new Date().toISOString(),
		routes: CORE_ROUTES.filter( ( r ) => visits.includes( r ) && ! failed.includes( r ) ),
		surfaces: catalogSurfaces.filter( ( id ) => visits.includes( id ) && ! failed.includes( id ) ),
		skipped,
		failed,
	};
}

function finish( raw ) {
	const { boot, responses, routes, surfaces, skipped, failed } = raw;
	const askedBy = raw.askedBy || {};
	const secrets = siteSecrets();
	const s = scrubber( boot, secrets );
	const images = [];
	for ( const [ rel, b64 ] of raw.images ) {
		const clean = stripMeta( Buffer.from( b64, 'base64' ) );
		if ( clean ) images.push( [ rel, clean ] );
		else console.warn( `  left out ${ rel }: not a JPEG, PNG or WebP the metadata strip understands` );
	}
	const cleanBoot = s.walk( boot );
	const cleanResponses = {};
	for ( const [ k, r ] of Object.entries( responses ) ) {
		cleanResponses[ s.walk( k ) ] = { status: r.status, body: s.body( r.body ), ...( r.headers ? { headers: r.headers } : {} ), from: askedBy[ k ] || [ '' ] };
	}

	const findings = audit( JSON.stringify( cleanBoot ) + JSON.stringify( cleanResponses ), { host: s.host, root: s.root, nonce: boot.nonce, secrets } );
	const tagged = images.filter( ( [ , buf ] ) => buf.includes( 'Exif\0\0' ) || buf.includes( '<x:xmpmeta' ) ).map( ( [ rel ] ) => rel );
	if ( tagged.length ) findings.push( 'image metadata: ' + tagged.join( ', ' ) );
	if ( findings.length ) {
		console.error( '\nNOT WRITTEN: the scrubbed recording still carries\n  ' + findings.join( '\n  ' ) );
		console.error( 'Fix the fixture data, or map the value in a MINN_DEMO_SCRUB file, then rerun with --from-raw.' );
		return false;
	}

	fs.rmSync( OUT, { recursive: true, force: true } );
	fs.mkdirSync( path.join( OUT, 'uploads' ), { recursive: true } );
	for ( const [ rel, buf ] of images ) {
		const dest = path.join( OUT, 'uploads', rel );
		if ( ! dest.startsWith( path.join( OUT, 'uploads' ) + path.sep ) ) continue;
		fs.mkdirSync( path.dirname( dest ), { recursive: true } );
		fs.writeFileSync( dest, buf );
	}
	fs.writeFileSync( path.join( OUT, 'boot.json' ), JSON.stringify( cleanBoot ) );
	fs.writeFileSync( path.join( OUT, 'responses.json' ), JSON.stringify( cleanResponses ) );
	fs.writeFileSync( path.join( OUT, 'meta.json' ), JSON.stringify( {
		version: boot.version,
		recordedAt: raw.recordedAt,
		routes,
		surfaces,
		skipped,
		failed,
	}, null, '\t' ) + '\n' );
	console.log( `\n${ surfaces.length } surfaces, ${ routes.length } core views, ${ Object.keys( cleanResponses ).length } responses, ${ images.length } images → ${ OUT }` );
	console.log( `not active on this site (no demo yet): ${ skipped.join( ', ' ) || 'none' }` );
	if ( failed.length ) console.log( `failed to load, left out: ${ failed.join( ', ' ) }` );
	return true;
}

( async () => {
	const raw = FROM_RAW ? JSON.parse( fs.readFileSync( RAW, 'utf8' ) ) : await capture();
	if ( finish( raw ) ) {
		fs.rmSync( RAW, { force: true } );
		// A closed browser can leave its pipe open, which keeps node waiting.
		process.exit( 0 );
	}
	if ( ! FROM_RAW ) {
		fs.writeFileSync( RAW, JSON.stringify( raw ) );
		console.error( `The unscrubbed capture is at ${ RAW }. It holds the site's real data: delete it when done.` );
	}
	process.exit( 1 );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
