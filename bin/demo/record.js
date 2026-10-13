/**
 * Record the live app for the static demo on minnadmin.com.
 *
 * Signs in to a dev site through the test harness, captures the boot payload
 * (window.MINN), then visits the overview, a few core views and every surface
 * the catalog links that is active on this site. Every GET the app makes to
 * the REST API or admin-ajax is kept, plus the upload images it shows.
 *
 * Nothing is written unless the scrubbed recording is clean: the site's host
 * and filesystem path, Gravatar hashes, nonces and every email outside the
 * example domains must be gone. MINN_DEMO_SCRUB may name a JSON file of extra
 * literal replacements ({ "find": "replace" }) for fixture data that carries
 * a real person's details; keep that file outside the repository. A refused
 * run leaves its unscrubbed capture at <out-dir>.raw.json, so a new mapping
 * can be tried with --from-raw instead of recording again.
 *
 * Usage (from the plugin root):
 *   MINN_TEST_PASS=… node bin/demo/record.js <out-dir>
 *   node bin/demo/record.js <out-dir> --from-raw    (scrub a refused capture)
 * then bin/demo/build.js <out-dir> <theme-dir>. See bin/demo/README.md.
 */
'use strict';

const fs = require( 'fs' );
const https = require( 'https' );
const path = require( 'path' );
const ROOT = path.resolve( __dirname, '..', '..' );
const { BASE, WP, launch, login } = require( path.join( ROOT, 'tests', 'helpers.js' ) );

const OUT = process.argv[ 2 ] ? path.resolve( process.argv[ 2 ] ) : '';
const RAW = OUT + '.raw.json';
const FROM_RAW = process.argv.includes( '--from-raw' );
if ( ! OUT ) {
	console.error( 'Usage: node bin/demo/record.js <out-dir> [--from-raw]' );
	process.exit( 2 );
}

// Core views worth a visit beside the plugin surfaces, so the sidebar of the
// demo leads somewhere. The overview loads with the app itself.
const CORE_ROUTES = [ 'content', 'media', 'comments', 'orders', 'products', 'customers', 'users' ];
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

function scrubber( boot ) {
	const origin = new URL( BASE ).origin;
	const host = new URL( BASE ).host;
	const root = fs.realpathSync( WP ).replace( /\/$/, '' );
	const rules = [
		[ new RegExp( slashy( root ), 'g' ), '/var/www/html' ],
		[ new RegExp( slashy( WP.replace( /\/$/, '' ) ), 'g' ), '/var/www/html' ],
		[ new RegExp( slashy( origin + '/wp-content/uploads/' ), 'g' ), '{{uploads}}/' ],
		[ new RegExp( slashy( origin ), 'g' ), 'https://example.com' ],
		[ new RegExp( escapeRe( host ), 'g' ), 'example.com' ],
		// The tail stops at a backslash too: inside an escaped string it is the
		// escape of the closing quote, and eating it would break that string.
		[ /https?:(\\*\/){2}(secure\.|[0-9]\.)?gravatar\.com\\*\/avatar\\*\/[^"'\s)<>\\]*/g, AVATAR ],
		[ /(_wpnonce=)[0-9a-f]{10}/g, '$1demo' ],
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

function audit( text, { host, root, nonce } ) {
	const found = [];
	const look = ( label, re ) => {
		const m = text.match( re );
		if ( m ) found.push( label + ': ' + [ ...new Set( m ) ].slice( 0, 8 ).join( ', ' ) );
	};
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
	const images = new Map();
	const errors = [];
	let restUrl = origin + '/wp-json/';
	let ajaxUrl = origin + '/wp-admin/admin-ajax.php';
	let browser = null;
	let page = null;
	let crashed = false;

	// A view is recorded once its own API calls have answered. networkidle
	// never comes on a busy dev site (the app polls), so count in-flight API
	// requests instead and wait for a quiet spell, up to a ceiling.
	const inflight = new Set();
	const api = ( req ) => req.url().startsWith( restUrl ) || req.url().startsWith( ajaxUrl );
	const keep = async ( res ) => {
		const u = res.url();
		const req = res.request();
		if ( req.method() !== 'GET' ) return;
		try {
			if ( req.resourceType() === 'image' && u.startsWith( origin + '/wp-content/uploads/' ) && res.ok() ) {
				images.set( u.slice( ( origin + '/wp-content/uploads/' ).length ).split( '?' )[ 0 ], await res.body() );
				return;
			}
			let key = null;
			if ( u.startsWith( restUrl ) ) key = 'rest:' + u.slice( restUrl.length );
			else if ( u.startsWith( ajaxUrl ) ) key = 'ajax:' + u.slice( ajaxUrl.length );
			if ( ! key ) return;
			// List totals and page counts ride in headers, not the body.
			const h = res.headers();
			const headers = {};
			for ( const name of [ 'x-wp-total', 'x-wp-totalpages' ] ) {
				if ( undefined !== h[ name ] ) headers[ name ] = h[ name ];
			}
			responses[ key ] = { status: res.status(), body: await res.text(), ...( Object.keys( headers ).length ? { headers } : {} ) };
		} catch ( e ) { /* the page navigated away mid-read */ }
	};
	// Chrome can die under memory pressure on a loaded dev box, and every
	// later view would then fail at once: open a fresh browser instead.
	const open = async () => {
		if ( browser ) await browser.close().catch( () => {} );
		await serverBack();
		const l = await launch();
		browser = l.browser;
		page = l.page;
		inflight.clear();
		crashed = false;
		page.on( 'response', keep );
		page.on( 'request', ( req ) => api( req ) && inflight.add( req ) );
		page.on( 'requestfinished', ( req ) => inflight.delete( req ) );
		page.on( 'requestfailed', ( req ) => inflight.delete( req ) );
		page.on( 'crash', () => {
			crashed = true;
			console.warn( '  the page crashed' );
		} );
		page.on( 'pageerror', ( e ) => errors.push( e.message ) );
		await login( page );
	};
	const settle = async () => {
		const quiet = 1500;
		const until = Date.now() + 45000;
		let calm = Date.now();
		while ( Date.now() < until ) {
			await page.waitForTimeout( 250 );
			if ( inflight.size ) calm = Date.now();
			else if ( Date.now() - calm >= quiet ) return;
		}
		console.warn( '  still waiting after 45s on ' + [ ...inflight ].map( ( r ) => r.url().replace( origin, '' ) ).join( ', ' ) + '; moving on' );
	};

	await open();
	await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded', timeout: 90000 } );
	await page.waitForFunction( () => window.MINN && document.querySelector( '.minn-nav-btn' ), null, { timeout: 180000 } );
	await settle();
	const boot = await page.evaluate( () => JSON.parse( JSON.stringify( window.MINN ) ) );
	restUrl = boot.restUrl;
	ajaxUrl = boot.ajaxUrl;

	const active = new Set( ( boot.surfaces || [] ).map( ( s ) => s.id ) );
	const skipped = catalogSurfaces.filter( ( id ) => ! active.has( id ) );
	const visits = [ ...CORE_ROUTES, ...catalogSurfaces.filter( ( id ) => active.has( id ) ) ];
	// One slow page on a loaded dev box must not cost the whole run: retry a
	// view once, then leave it out (a failed surface gets no demo link).
	const failed = [];
	for ( const route of visits ) {
		let done = false;
		for ( let attempt = 1; attempt <= 2 && ! done; attempt++ ) {
			try {
				if ( crashed || page.isClosed() || ! browser.isConnected() ) await open();
				// A navigation abandons the last view's requests without always
				// reporting them finished; only this view's calls count.
				inflight.clear();
				await page.goto( BASE + '/minn-admin/' + route, { waitUntil: 'domcontentloaded', timeout: 120000 } );
				await settle();
				done = true;
			} catch ( e ) {
				console.warn( `  ${ route }: ${ e.message.split( '\n' )[ 0 ] }${ attempt === 1 ? '; retrying' : '; leaving it out' }` );
				if ( attempt === 1 && /ERR_CONNECTION|ERR_EMPTY_RESPONSE|Timeout/.test( e.message ) ) await serverBack();
			}
		}
		if ( done ) console.log( 'recorded ' + route );
		else failed.push( route );
	}
	await browser.close().catch( () => {} );
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
		images: [ ...images ].map( ( [ rel, buf ] ) => [ rel, buf.toString( 'base64' ) ] ),
		recordedAt: new Date().toISOString(),
		routes: CORE_ROUTES.filter( ( r ) => ! failed.includes( r ) ),
		surfaces: catalogSurfaces.filter( ( id ) => active.has( id ) && ! failed.includes( id ) ),
		skipped,
		failed,
	};
}

function finish( raw ) {
	const { boot, responses, routes, surfaces, skipped, failed } = raw;
	const s = scrubber( boot );
	const images = [];
	for ( const [ rel, b64 ] of raw.images ) {
		const clean = stripMeta( Buffer.from( b64, 'base64' ) );
		if ( clean ) images.push( [ rel, clean ] );
		else console.warn( `  left out ${ rel }: not a JPEG, PNG or WebP the metadata strip understands` );
	}
	const cleanBoot = s.walk( boot );
	const cleanResponses = {};
	for ( const [ k, r ] of Object.entries( responses ) ) {
		cleanResponses[ s.walk( k ) ] = { status: r.status, body: s.body( r.body ), ...( r.headers ? { headers: r.headers } : {} ) };
	}

	const findings = audit( JSON.stringify( cleanBoot ) + JSON.stringify( cleanResponses ), { host: s.host, root: s.root, nonce: boot.nonce } );
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
		return;
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
