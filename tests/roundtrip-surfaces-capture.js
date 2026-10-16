/**
 * Capture pass for the surface edit round trip (roundtrip-surfaces.test.php).
 *
 * The surface detail modal sends every edit field it shows plus its
 * `preserve` keys, seeded through the form engine (combobox seeding,
 * number parsing, clearable selects). Rather than reimplement that in the
 * harness, this opens each surface's first item in the real app, clicks
 * Save without touching anything, and records the exact request the client
 * would send. The request itself is answered here and never reaches the
 * server. The PHP harness then replays the bodies.
 *
 * With MINN_RT_SEED_FILE (lib/roundtrip-seed.php's state), each surface
 * opens the item the seed wrote into, wherever the list now sorts it, and
 * the capture records what was seeded.
 *
 * Run: node roundtrip-surfaces-capture.js [out.json]   (default: $TMPDIR/minn-surface-saves.json)
 */
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { BASE, launch, login } = require( './helpers' );

( async () => {
	const out = process.argv[ 2 ] || path.join( os.tmpdir(), 'minn-surface-saves.json' );
	const seedFile = process.env.MINN_RT_SEED_FILE;
	const seed = seedFile && fs.existsSync( seedFile ) ? JSON.parse( fs.readFileSync( seedFile, 'utf8' ) ) : null;
	const { browser, page } = await launch();
	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );
	await login( page );

	const surfaces = await page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/surfaces', { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		const d = await r.json();
		return ( d.surfaces || d ).map( ( s ) => ( {
			id: s.id,
			edit: s.collection && s.collection.detail && s.collection.detail.edit ? s.collection.detail.edit : null,
			detailRoute: s.collection && s.collection.detail ? s.collection.detail.detailRoute || null : null,
			listRoute: s.collection ? s.collection.route || null : null,
			actions: ( ( s.collection && s.collection.actions ) || [] ).filter( ( a ) => a.route && a.body ).map( ( a ) => ( { label: a.label, method: a.method || 'POST', route: a.route, body: a.body, when: a.when || null } ) ),
		} ) ).filter( ( s ) => s.edit );
	} );

	const captures = [];
	for ( const s of surfaces ) {
		const rec = { surface: s.id, route: s.edit.route, method: s.edit.method || 'POST', preserve: s.edit.preserve || [], detailRoute: s.detailRoute, actions: s.actions };
		const seeded = seed && seed.surfaces && seed.surfaces[ s.id ] && Object.keys( seed.surfaces[ s.id ].fields || {} ).length ? seed.surfaces[ s.id ] : null;
		if ( seed && ! seeded ) {
			rec.skip = 'nothing seeded' + ( seed.surfaces && seed.surfaces[ s.id ] && seed.surfaces[ s.id ].note ? ': ' + seed.surfaces[ s.id ].note : '' );
			captures.push( rec );
			continue;
		}
		if ( seeded ) rec.seeded = { set: seed.set, id: String( seeded.id ), fields: seeded.fields };
		try {
			await page.goto( BASE + '/minn-admin/' + s.id, { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( '.minn-table-row, .minn-empty', { timeout: 30000 } );
			// The seeded item, where the list now sorts it (its name may have
			// moved it); the list route answers in the order the table shows.
			let index = 0;
			if ( seeded ) {
				index = await page.evaluate( async ( { route, id } ) => {
					const r = await fetch( window.MINN.restUrl + route.replace( /^\/+/, '' ), { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
					const d = await r.json();
					const items = Array.isArray( d ) ? d : ( d.items || [] );
					return items.findIndex( ( it ) => String( it.id ) === id );
				}, { route: s.listRoute, id: String( seeded.id ) } );
				if ( index < 0 ) {
					rec.skip = 'the seeded item is not on the first page';
					captures.push( rec );
					continue;
				}
			}
			const row = await page.$( `.minn-table-row[data-sitem="${ index }"]` );
			if ( ! row ) {
				rec.skip = 'no items';
				captures.push( rec );
				continue;
			}
			await row.click();
			await page.waitForSelector( '#minn-modal-overlay', { timeout: 15000 } );
			await page.waitForFunction( () => {
				const m = document.querySelector( '#minn-modal-overlay' );
				return m && ! m.querySelector( '.minn-loading' );
			}, null, { timeout: 15000 } );
			if ( ! await page.$( '#minn-surface-save' ) ) {
				rec.skip = 'no Save on the first item';
				captures.push( rec );
				continue;
			}
			let caught = null;
			const handler = async ( route ) => {
				const req = route.request();
				if ( [ 'POST', 'PUT', 'PATCH' ].includes( req.method() ) && ! caught ) {
					caught = { url: req.url(), method: req.method(), body: req.postData() };
					await route.fulfill( { status: 200, contentType: 'application/json', body: '{}' } );
					return;
				}
				await route.continue();
			};
			await page.route( '**/wp-json/**', handler );
			await page.route( '**/?rest_route=**', handler );
			await page.click( '#minn-surface-save' );
			await page.waitForFunction( () => true, null, { timeout: 500 } ).catch( () => {} );
			// Save reads the item fresh before it writes, which can take
			// seconds on a loaded lab: wait up to 10 s for the request.
			for ( let i = 0; i < 67 && ! caught; i++ ) await page.waitForTimeout( 150 );
			await page.unroute( '**/wp-json/**', handler );
			await page.unroute( '**/?rest_route=**', handler );
			if ( ! caught ) {
				rec.skip = 'no request on Save';
			} else {
				const u = new URL( caught.url );
				rec.path = u.searchParams.get( 'rest_route' ) || u.pathname.replace( /^.*\/wp-json/, '' );
				rec.method = caught.method;
				rec.body = JSON.parse( caught.body || '{}' );
			}
			captures.push( rec );
		} catch ( e ) {
			rec.skip = 'error: ' + e.message.split( '\n' )[ 0 ];
			captures.push( rec );
		}
	}
	fs.writeFileSync( out, JSON.stringify( captures, null, 1 ) );
	captures.forEach( ( c ) => console.log( `${ c.skip ? 'SKIP' : 'CAUGHT' }  ${ c.surface }${ c.skip ? ' — ' + c.skip : ' ' + c.method + ' ' + c.path + ' keys=' + Object.keys( c.body || {} ).join( ',' ) }` ) );
	console.log( 'wrote ' + out );
	await browser.close();
	process.exit( 0 );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
