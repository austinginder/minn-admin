/**
 * Capture pass for the form entry answer editors (the entry page's Edit
 * answers; roundtrip-surfaces.test.php replays it). Opens each forms
 * plugin's first editable entry (lib/roundtrip-fixtures.php) in the real app
 * and records every write the page sends, answering them here so nothing
 * reaches the server:
 *
 *   clean   Edit answers, then Save answers with nothing changed;
 *   text    one free-text answer changed by real keystrokes (a word typed at
 *           its end): the first one the seed did not fill;
 *   pick    one box of a multi-choice answer clicked, not the pick the seed
 *           retired;
 *   action  the title's Star, where the plugin has one.
 *
 * It also records the edit block as the page loaded it, and builds from it,
 * by the client's own rules (entryEditStart and the save), the body a save
 * sends when every answer goes back as loaded.
 *
 * With MINN_RT_SEED_FILE (lib/roundtrip-seed.php's `answers` state) it opens
 * the seeded entries and records what was seeded.
 *
 * Run: node roundtrip-entries-capture.js [out.json]   (default: $TMPDIR/minn-entry-saves.json)
 */
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { execFileSync } = require( 'child_process' );
const { BASE, WP, launch, login } = require( './helpers' );

const ENTRIES = [
	{ id: 'gf-entry', fx: 'gf_entry', sid: 'gravity-forms' },
	{ id: 'elementor-entry', fx: 'elementor_entry', sid: 'elementor-forms' },
	{ id: 'ninja-forms-entry', fx: 'nf_entry', sid: 'ninja-forms' },
	{ id: 'wpforms-entry', fx: 'wpforms_entry', sid: 'wpforms' },
];
// What the text step types at the end of the answer: plain words every
// plugin's own cleaning leaves alone, so the answer must read back as typed.
const TYPED = ' minnrt';

( async () => {
	const out = process.argv[ 2 ] || path.join( os.tmpdir(), 'minn-entry-saves.json' );
	const seedFile = process.env.MINN_RT_SEED_FILE;
	const seed = seedFile && fs.existsSync( seedFile ) ? JSON.parse( fs.readFileSync( seedFile, 'utf8' ) ) : null;
	const raw = execFileSync( 'wp', [ 'eval-file', path.join( __dirname, 'lib/roundtrip-fixtures.php' ), '--user=admin', '--path=' + WP ], { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } );
	const fx = JSON.parse( raw.trim().split( '\n' ).pop() );
	const { browser, page } = await launch();
	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );
	await login( page );

	let writes = [];
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
	// Up to 10 s for a write: Save may read the entry first on a loaded lab.
	const sent = async () => {
		for ( let i = 0; i < 67 && ! writes.length; i++ ) await page.waitForTimeout( 150 );
		await page.waitForTimeout( 300 );
		return writes.slice();
	};
	const openEditor = async () => {
		await page.waitForSelector( '#minn-ep-edit', { timeout: 30000 } );
		await page.click( '#minn-ep-edit' );
		await page.waitForSelector( '.minn-ep-edit', { timeout: 15000 } );
	};
	// After a save the page reloads the entry and shows the answers again.
	const editorClosed = () => page.waitForSelector( '#minn-ep-edit', { timeout: 30000 } );

	const captures = [];
	for ( const e of ENTRIES ) {
		const seeded = seed && seed.entries ? seed.entries[ e.sid ] || null : null;
		if ( seed && ! seeded ) {
			captures.push( { surface: e.id, skip: 'nothing seeded' } );
			continue;
		}
		const id = seeded ? String( seeded.id ) : ( fx[ e.fx ] ? String( fx[ e.fx ] ) : '' );
		if ( ! id ) {
			captures.push( { surface: e.id, skip: 'no ' + e.fx + ' on this site' } );
			continue;
		}
		const rec = { surface: e.id, entry: { sid: e.sid, id }, edits: [], actions: [] };
		if ( seeded ) rec.seeded = { set: seed.set, id, inputs: seeded.inputs || {}, retired: seeded.retired || {} };
		await page.route( '**/wp-json/**', handler );
		await page.route( '**/?rest_route=**', handler );
		try {
			await page.goto( BASE + `/minn-admin/${ e.sid }/entry/${ encodeURIComponent( id ) }`, { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( '.minn-entry-page .minn-order-main', { timeout: 60000 } );
			const info = await page.evaluate( async ( { sid, id } ) => {
				const s = ( window.MINN.surfaces || [] ).find( ( x ) => x.id === sid );
				const route = s && s.collection && s.collection.detail ? s.collection.detail.sectionsRoute : '';
				if ( ! route ) return null;
				const r = await fetch( window.MINN.restUrl + route.replace( '{id}', encodeURIComponent( id ) ), { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
				const d = await r.json();
				return { sectionsRoute: route, edit: d && d.edit ? d.edit : null };
			}, { sid: e.sid, id } );
			if ( ! info || ! info.edit || ! ( info.edit.fields || [] ).length ) {
				captures.push( { surface: e.id, skip: 'the entry offers no answers to edit' } );
				continue;
			}
			const fields = info.edit.fields;
			rec.entry.sectionsRoute = info.sectionsRoute;
			rec.entry.route = info.edit.route;
			rec.loaded = info.edit;
			rec.route = info.edit.route;
			rec.path = '/' + info.edit.route.replace( '{id}', encodeURIComponent( id ) ).replace( /^\/+/, '' );
			rec.method = 'POST';
			rec.detailRoute = null;
			rec.preserve = [];

			// Every answer sent back as loaded, by the client's own rules: a
			// checkbox input as checked or not, each with its stored value.
			const values = {};
			const original = {};
			fields.forEach( ( f ) => f.inputs.forEach( ( i ) => {
				values[ i.id ] = 'checks' === f.kind ? '' !== String( i.value ) : i.value;
				original[ i.id ] = i.value;
			} ) );
			rec.asLoaded = { method: 'POST', path: rec.path, body: { values, original } };

			// clean
			await openEditor();
			writes = [];
			await page.click( '#minn-ep-save' );
			await page.waitForTimeout( 1500 );
			rec.clean = writes.slice();
			await editorClosed();

			// text
			const filled = Object.keys( rec.seeded ? rec.seeded.inputs : {} );
			let target = null;
			for ( const kinds of [ [ 'text', 'parts' ], [ 'textarea' ] ] ) {
				for ( const f of fields ) {
					if ( ! kinds.includes( f.kind ) ) continue;
					for ( const i of f.inputs ) {
						if ( ! target && ! filled.includes( String( i.id ) ) ) target = { f, i };
					}
				}
			}
			if ( target ) {
				await openEditor();
				const sel = `.minn-ep-edit [data-epf="${ String( target.i.id ).replace( /["\\]/g, '\\$&' ) }"]`;
				await page.click( sel );
				await page.$eval( sel, ( el ) => el.setSelectionRange( el.value.length, el.value.length ) );
				await page.keyboard.type( TYPED );
				writes = [];
				await page.click( '#minn-ep-save' );
				const w = await sent();
				rec.edits.push( { what: 'text', input: String( target.i.id ), label: target.f.label, kind: target.f.kind, typed: String( target.i.value ) + TYPED, writes: w } );
				await editorClosed();
			}

			// pick
			const retired = rec.seeded ? rec.seeded.retired : {};
			let pick = null;
			for ( const f of fields ) {
				if ( pick ) break;
				if ( 'checks' === f.kind ) {
					const box = [ ...f.inputs ].reverse().find( ( i ) => ! ( String( i.id ) in retired ) );
					if ( box ) pick = { f, input: String( box.id ), attr: 'data-epc', match: String( box.id ) };
				} else if ( 'multi' === f.kind && ( f.choices || [] ).length ) {
					const i = f.inputs[ 0 ];
					const c = [ ...f.choices ].reverse().find( ( [ v ] ) => String( v ) !== String( retired[ i.id ] ) );
					if ( c ) pick = { f, input: String( i.id ), attr: 'data-epm', match: String( i.id ), value: String( c[ 0 ] ) };
				}
			}
			if ( pick ) {
				await openEditor();
				const boxes = page.locator( `.minn-ep-edit [${ pick.attr }]` );
				const n = await boxes.count();
				let at = -1;
				for ( let k = 0; k < n && at < 0; k++ ) {
					const el = boxes.nth( k );
					if ( await el.getAttribute( pick.attr ) === pick.match && ( undefined === pick.value || await el.getAttribute( 'value' ) === pick.value ) ) at = k;
				}
				if ( at >= 0 ) {
					await boxes.nth( at ).click();
					writes = [];
					await page.click( '#minn-ep-save' );
					const w = await sent();
					rec.edits.push( { what: 'pick', input: pick.input, label: pick.f.label, kind: pick.f.kind, writes: w } );
					await editorClosed();
				}
			}

			// action: the title's Star
			const star = await page.$( '.minn-ep-star[data-epact]' );
			if ( star ) {
				const label = ( await star.getAttribute( 'aria-label' ) ) || 'Star';
				writes = [];
				await star.click();
				const asked = await page.waitForSelector( '.minn-confirm-modal [data-ok]', { timeout: 1200 } ).catch( () => null );
				if ( asked ) await asked.click();
				const w = await sent();
				if ( w.length ) rec.actions.push( { label, writes: w } );
			}
			captures.push( rec );
		} catch ( err ) {
			captures.push( { surface: e.id, skip: 'error: ' + err.message.split( '\n' )[ 0 ] } );
		} finally {
			await page.unroute( '**/wp-json/**', handler );
			await page.unroute( '**/?rest_route=**', handler );
		}
	}
	fs.writeFileSync( out, JSON.stringify( captures, null, 1 ) );
	captures.forEach( ( c ) => console.log( c.skip
		? `SKIP  ${ c.surface } — ${ c.skip }`
		: `CAUGHT  ${ c.surface } #${ c.entry.id }${ c.seeded ? ' [' + c.seeded.set + ']' : '' }: clean save sent ${ c.clean.length }; `
			+ c.edits.map( ( x ) => `${ x.what } ${ x.input } → ${ x.writes.map( ( w ) => w.method + ' ' + w.path ).join( ' + ' ) || 'nothing' }` ).join( '; ' )
			+ ( c.actions.length ? '; ' + c.actions.map( ( a ) => `${ a.label } → ${ a.writes.map( ( w ) => w.method + ' ' + w.path ).join( ' + ' ) }` ).join( '; ' ) : '' ) ) );
	console.log( 'wrote ' + out );
	await browser.close();
	process.exit( 0 );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
