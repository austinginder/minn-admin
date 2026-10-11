/**
 * Capture pass for Minn's own pages (settings sections, builders, the
 * Gravity Forms / CF7 / Fluent / WPForms email pages, membership plans,
 * orders, products, users, store settings): opens each on a real item,
 * presses Save without touching anything and records every write request
 * the client sends, answering them here so nothing reaches the server.
 * A save button that is off while the page is clean is switched on first:
 * what the client sends then is what an unrelated edit would carry.
 * roundtrip-surfaces.test.php replays the result (MINN_RT_CAPTURE).
 *
 * Run: node roundtrip-pages-capture.js [out.json]   (default: $TMPDIR/minn-page-saves.json)
 */
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const { BASE, WP, launch, login } = require( './helpers' );

const PAGES = [
	{ id: 'settings', url: () => '/minn-admin/settings', save: '#minn-save-settings', sections: '.minn-settings-nav-item[data-section]' },
	{ id: 'store-settings', url: () => '/minn-admin/store-settings', save: '#minn-sset-save, #minn-save-settings' },
	{ id: 'acf-field-group', fx: 'acf_group', url: ( f ) => `/minn-admin/field-groups/${ encodeURIComponent( f.acf_group ) }`, save: '#minn-fgb-save' },
	{ id: 'gf-builder', fx: 'gf_form', url: ( f ) => `/minn-admin/gravity-forms/form/${ f.gf_form }`, save: '#minn-gfb-save' },
	{ id: 'gf-notification', fx: 'gf_notification', url: ( f ) => `/minn-admin/gravity-forms/notification/${ encodeURIComponent( f.gf_notification ) }`, save: '#minn-gfn-save' },
	{ id: 'gf-confirmation', fx: 'gf_confirmation', url: ( f ) => `/minn-admin/gravity-forms/confirmation/${ encodeURIComponent( f.gf_confirmation ) }`, save: '#minn-gfc-save' },
	{ id: 'cf7-form', fx: 'cf7_form', url: ( f ) => `/minn-admin/cf7/form/${ f.cf7_form }`, save: '#minn-c7m-save' },
	{ id: 'fluent-emails', fx: 'fluent_form', url: ( f ) => `/minn-admin/fluent-forms/form/${ f.fluent_form }`, save: '#minn-ffe-save' },
	{ id: 'wpforms-emails', fx: 'wpforms_form', url: ( f ) => `/minn-admin/wpforms/form/${ f.wpforms_form }`, save: '#minn-wpfe-save' },
	{ id: 'membership-plan', fx: 'plan', url: ( f ) => `/minn-admin/membership-plans/${ f.plan }`, save: '#minn-wcmp-save' },
	{ id: 'membership', fx: 'membership', url: ( f ) => `/minn-admin/memberships/${ f.membership }`, save: '#minn-wcm-save' },
	{ id: 'order', fx: 'order', url: ( f ) => `/minn-admin/orders/${ f.order }`, save: '#minn-order-save' },
	{ id: 'product', fx: 'product', url: ( f ) => `/minn-admin/products/${ f.product }`, save: '#minn-product-save' },
];

( async () => {
	const out = process.argv[ 2 ] || path.join( os.tmpdir(), 'minn-page-saves.json' );
	const raw = execFileSync( 'wp', [ 'eval-file', path.join( __dirname, 'lib/roundtrip-fixtures.php' ), '--user=admin', '--path=' + WP ], { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } );
	const fx = JSON.parse( raw.trim().split( '\n' ).pop() );
	const { browser, page } = await launch();
	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );
	await login( page );

	const captures = [];
	const capture = async ( id, saveSel ) => {
		const writes = [];
		const handler = async ( route ) => {
			const req = route.request();
			if ( [ 'POST', 'PUT', 'PATCH', 'DELETE' ].includes( req.method() ) ) {
				const u = new URL( req.url() );
				writes.push( {
					method: req.method(),
					path: u.searchParams.get( 'rest_route' ) || u.pathname.replace( /^.*\/wp-json/, '' ),
					query: Object.fromEntries( [ ...u.searchParams ].filter( ( [ k ] ) => 'rest_route' !== k && '_locale' !== k ) ),
					body: req.postData() ? JSON.parse( req.postData() ) : null,
				} );
				await route.fulfill( { status: 200, contentType: 'application/json', body: '{}' } );
				return;
			}
			await route.continue();
		};
		await page.route( '**/wp-json/**', handler );
		await page.route( '**/?rest_route=**', handler );
		try {
			const clicked = await page.evaluate( ( sel ) => {
				const all = [ ...document.querySelectorAll( sel ) ];
				const b = all.find( ( x ) => x.offsetParent !== null ) || all[ 0 ];
				if ( ! b ) return false;
				b.disabled = false;
				b.click();
				return true;
			}, saveSel );
			if ( ! clicked ) {
				captures.push( { surface: id, skip: 'no visible Save' } );
				return;
			}
			const ok = await page.waitForSelector( '.minn-confirm-modal [data-ok]', { timeout: 1200 } ).catch( () => null );
			if ( ok ) await ok.click();
			for ( let i = 0; i < 20; i++ ) await page.waitForTimeout( 150 );
		} finally {
			await page.unroute( '**/wp-json/**', handler );
			await page.unroute( '**/?rest_route=**', handler );
		}
		if ( ! writes.length ) {
			captures.push( { surface: id, skip: 'Save sent nothing (clean page)' } );
			return;
		}
		captures.push( { surface: id, method: writes[ 0 ].method, path: writes[ 0 ].path, body: writes[ 0 ].body || {}, writes, route: writes[ 0 ].path, preserve: [], actions: [], detailRoute: null } );
	};

	for ( const p of PAGES ) {
		if ( p.fx && ! fx[ p.fx ] ) {
			captures.push( { surface: p.id, skip: 'no ' + p.fx + ' on this site' } );
			continue;
		}
		try {
			await page.goto( BASE + p.url( fx ), { waitUntil: 'domcontentloaded' } );
			// A page's save bar can stay hidden until something changes.
			await page.waitForSelector( p.save, { timeout: 30000, state: 'attached' } );
			await page.waitForLoadState( 'networkidle' ).catch( () => {} );
			await page.waitForTimeout( 800 );
			if ( p.sections ) {
				const labels = await page.$$eval( p.sections, ( els ) => els.map( ( e ) => e.dataset.section ) );
				for ( const label of labels ) {
					// A fresh load per section: the stand-in answer to the last
					// section's save would otherwise become the page's state.
					await page.goto( BASE + p.url( fx ), { waitUntil: 'domcontentloaded' } );
					await page.waitForSelector( p.sections, { timeout: 30000 } );
					await page.click( `${ p.sections.split( '[' )[ 0 ] }[data-section="${ label }"]` );
					// A section loads its lists after it paints; saving before
					// they land sends defaults no person could have saved.
					await page.waitForLoadState( 'networkidle' ).catch( () => {} );
					await page.waitForTimeout( 1500 );
					if ( await page.$( p.save ) ) await capture( `${ p.id }:${ label }`, p.save );
				}
			} else {
				await capture( p.id, p.save );
			}
		} catch ( e ) {
			captures.push( { surface: p.id, skip: 'error: ' + e.message.split( '\n' )[ 0 ] } );
		}
	}
	fs.writeFileSync( out, JSON.stringify( captures, null, 1 ) );
	captures.forEach( ( c ) => console.log( `${ c.skip ? 'SKIP' : 'CAUGHT' }  ${ c.surface }${ c.skip ? ' — ' + c.skip : ' ' + c.writes.map( ( w ) => w.method + ' ' + w.path ).join( ' + ' ) }` ) );
	console.log( 'wrote ' + out );
	// A clean save sends nothing: every field it carries is a value the page
	// loaded, which puts back whatever changed since (another tab, a
	// payment, an import). Pages that send the whole object on purpose prove
	// they saw the latest with a stamp and answer 409 instead.
	const STAMPED = { 'acf-field-group': 'field group stamp, 409 when stale', 'gf-builder': 'form stamp, 409 when stale' };
	const resent = [];
	captures.filter( ( c ) => ! c.skip && ! STAMPED[ c.surface ] ).forEach( ( c ) => {
		c.writes.forEach( ( w ) => {
			if ( /\/preview$/.test( w.path ) ) return; // renders, saves nothing
			let b = w.body;
			try { b = typeof b === 'string' ? JSON.parse( b ) : b; } catch ( e ) { /* raw body */ }
			const full = b && typeof b === 'object'
				? Object.keys( b ).filter( ( k ) => k !== 'known_rules' && ! [ null, '' ].includes( b[ k ] ) && JSON.stringify( b[ k ] ) !== '[]' && JSON.stringify( b[ k ] ) !== '{}' )
				: ( b ? [ '(raw body)' ] : [] );
			if ( full.length ) resent.push( `${ c.surface } ${ w.method } ${ w.path }: ${ full.join( ', ' ) }` );
		} );
	} );
	console.log( resent.length
		? 'FAIL  a clean save sends loaded values back:\n      ' + resent.join( '\n      ' )
		: 'PASS  every clean save sends nothing (stamped pages aside)' );
	await browser.close();
	process.exit( resent.length ? 1 : 0 );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
