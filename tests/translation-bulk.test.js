/**
 * Language-pack batch — Update translations runs every pending pack in ONE
 * request with the same panel the plugin batch uses (packages fetched side
 * by side, then installed one after another, each row moving Queued →
 * fetched → Extracting → Installing → Updated). The mu-fixture
 * minn_test_translation_pack offers an xx_XX pack for Minn Admin whose zip
 * this suite serves from a Node server on 127.0.0.1 (never a loopback into
 * the site), so one row is deterministic; whatever real packs the site has
 * pending ride along, so nothing here asserts on their outcome. Cleanup
 * removes the xx_XX locale through the translations/remove route.
 */
const http = require( 'http' );
const fs = require( 'fs' );
const path = require( 'path' );
const { BASE, launch, login, reporter } = require( './helpers' );

const ZIP = fs.readFileSync( path.join( __dirname, 'fixtures', 'minn-admin-xx_XX.zip' ) );
const ROW = 'Minn Admin · xx_XX';

const serve = () => new Promise( ( resolve ) => {
	let hits = 0;
	const server = http.createServer( ( req, res ) => {
		hits++;
		res.setHeader( 'Content-Type', 'application/zip' );
		res.setHeader( 'Content-Length', ZIP.length );
		res.end( ZIP );
	} );
	server.listen( 0, '127.0.0.1', () => resolve( { server, url: `http://127.0.0.1:${ server.address().port }/minn-admin-xx_XX.zip`, hits: () => hits } ) );
} );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'translation-bulk' );
	const fake = await serve();
	await login( page );

	const rest = ( method, p, body ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.p + ( a.p.includes( '?' ) ? '&' : '?' ) + '_cb=' + Math.random(), {
			method: a.method, credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: a.body ? JSON.stringify( a.body ) : undefined,
		} );
		return { status: r.status, json: await r.json().catch( () => null ) };
	}, { method, p, body } );
	const setOpt = async ( v ) => {
		for ( let attempt = 1; attempt <= 5; attempt++ ) {
			await rest( 'POST', 'wp/v2/settings', { minn_test_translation_pack: v } ).catch( () => null );
			const r = await rest( 'GET', 'wp/v2/settings' ).catch( () => null );
			if ( r && r.json && r.json.minn_test_translation_pack === v ) return true;
			await page.waitForTimeout( 800 );
		}
		return false;
	};
	const panel = () => page.evaluate( ( n ) => {
		const m = document.querySelector( '.minn-bulk-modal' );
		if ( ! m ) return null;
		const rows = [ ...m.querySelectorAll( '.minn-bulk-row' ) ];
		const mine = rows.find( ( r ) => r.textContent.includes( n ) );
		return {
			title: ( m.querySelector( '.minn-modal-title' ) || {} ).textContent || '',
			phase: ( m.querySelector( '.minn-bulk-phase' ) || {} ).textContent || '',
			rows: rows.length,
			mine: mine ? { cls: mine.className, text: mine.textContent.replace( /\s+/g, ' ' ).trim() } : null,
		};
	}, ROW );

	try {
		// A leftover from a crashed run would hide the offer (the fixture
		// stops offering once the .mo exists).
		await rest( 'POST', 'minn-admin/v1/translations/remove', { locale: 'xx_XX' } ).catch( () => {} );
		t.check( 'Fixture on (write verified)', await setOpt( fake.url ), fake.url );

		const before = await rest( 'GET', 'minn-admin/v1/translations' );
		const xx = ( before.json && before.json.groups || [] ).find( ( g ) => g.locale === 'xx_XX' );
		t.check( 'fixture pack is offered for xx_XX (Minn Admin)', !! xx && ( xx.components || [] ).some( ( c ) => c.slug === 'minn-admin' ), JSON.stringify( xx && xx.components ) );
		const pending = ( before.json && before.json.count ) || 0;

		await page.goto( BASE + '/minn-admin/extensions', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-xtab="translations"]', { timeout: 20000 } );
		await page.click( '[data-xtab="translations"]' );
		await page.waitForSelector( '#minn-update-translations', { timeout: 30000 } );
		await page.click( '#minn-update-translations' );

		await page.waitForSelector( '.minn-bulk-modal', { timeout: 15000 } );
		const opened = await panel();
		t.check( 'the batch panel opens with one row per pending pack', opened && opened.rows === pending, JSON.stringify( opened && { rows: opened.rows, pending, title: opened.title } ) );
		t.check( 'panel title counts language packs, not plugins', /language pack/i.test( opened.title ), opened.title );
		t.check( 'the fixture row carries its own label (component · language)', !! opened.mine, JSON.stringify( opened.mine ) );

		// Follow the fixture row through the phases the record reports.
		const seen = new Set();
		const finished = await page.waitForFunction( ( n ) => {
			const m = document.querySelector( '.minn-bulk-modal' );
			if ( ! m ) return false;
			const mine = [ ...m.querySelectorAll( '.minn-bulk-row' ) ].find( ( r ) => r.textContent.includes( n ) );
			if ( mine ) {
				const st = ( mine.className.match( /is-([a-z]+)/ ) || [] )[ 1 ];
				window.__minnSeen = window.__minnSeen || [];
				if ( st && ! window.__minnSeen.includes( st ) ) window.__minnSeen.push( st );
			}
			return /Updated|failed/.test( ( m.querySelector( '.minn-bulk-phase' ) || {} ).textContent || '' );
		}, ROW, { timeout: 600000, polling: 100 } ).then( () => true ).catch( () => false );
		const states = await page.evaluate( () => window.__minnSeen || [] );
		states.forEach( ( s ) => seen.add( s ) );
		t.check( 'batch reaches its closing line', finished );
		const end = await panel();
		t.check( 'fixture row ends Updated', !! end.mine && /is-done/.test( end.mine.cls ), JSON.stringify( end.mine ) );
		t.check( 'row moved through fetch and install states', seen.has( 'done' ) && ( seen.has( 'fetched' ) || seen.has( 'fetching' ) || seen.has( 'installing' ) || seen.has( 'unpacking' ) ), [ ...seen ].join( ',' ) );
		t.check( 'closing line reports totals and timings', /Updated .* in \d/.test( end.phase ) && /fetched .* installed in/.test( end.phase ), end.phase );
		t.check( 'the fake pack was fetched by the batch, not the browser', fake.hits() >= 1, `hits=${ fake.hits() }` );

		await page.click( '#minn-modal-close2' );
		const after = await rest( 'GET', 'minn-admin/v1/translations' );
		const xxAfter = ( after.json && after.json.groups || [] ).find( ( g ) => g.locale === 'xx_XX' );
		t.check( 'xx_XX no longer pending once its .mo is installed', ! xxAfter, JSON.stringify( xxAfter ) );
		const installed = await rest( 'GET', 'minn-admin/v1/translations/installed' );
		const has = ( installed.json && installed.json.languages || [] ).some( ( l ) => l.locale === 'xx_XX' );
		t.check( 'xx_XX shows in the installed languages list', has );
		const chip = await page.evaluate( () => document.querySelector( '#minn-upd-chip' ).hidden );
		t.check( 'top-bar chip clears when the batch is done', chip === true, String( chip ) );
	} finally {
		await rest( 'POST', 'minn-admin/v1/translations/remove', { locale: 'xx_XX' } ).catch( () => {} );
		await setOpt( '' ).catch( () => {} );
		fake.server.close();
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
