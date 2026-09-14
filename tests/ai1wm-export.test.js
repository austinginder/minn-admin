/**
 * All-in-One WP Migration exports as a Minn background job. The Backups
 * status card's Export site action (job: true) starts the plugin's own
 * export pipeline through its ai1wm/v1 REST controller; Minn shows a
 * topbar pill with the live percent, a modal with the step and Stop, and
 * lists the archive when the job ends. The suite starts an export with
 * every skip toggle on, proves the toggles reached the request, proves the
 * pill survives a navigation and the job is persisted, then STOPS that job
 * through the plugin's own cancel. It never waits for the end: with media,
 * plugins and themes skipped this site still exports its language packs and
 * backup sets, twenty minutes and over a gigabyte, so a finished archive is
 * not something a suite can wait for or leave behind.
 */
const { BASE, launch, login, reporter } = require( './helpers' );
const { apiFor } = require( './_jet-common' );
( async () => {
	const t = reporter( 'ai1wm-export' );
	const { browser, page, errors } = await launch();
	await login( page );
	const api = apiFor( page );
	const madeIds = async ( before ) => ( ( ( await api( 'minn-admin/v1/ai1wm/exports' ) ).body || {} ).items || [] ).filter( ( i ) => ! before.has( i.id ) );
	let before = new Set();
	try {
		await page.evaluate( () => { try { localStorage.removeItem( 'minn-job' ); } catch ( e ) {} } );
		before = new Set( ( ( ( await api( 'minn-admin/v1/ai1wm/exports' ) ).body || {} ).items || [] ).map( ( i ) => i.id ) );
		const st = await api( 'minn-admin/v1/ai1wm/status' );
		const act = ( st.body.actions || [] ).find( ( a ) => /Export site/.test( a.label ) );
		t.check( 'the status card offers Export site as a job action with skip toggles', !! act && act.job === true && ( act.fields || [] ).some( ( f ) => f.key === 'no_media' ), JSON.stringify( act ) );

		/* ===== Start from the real UI ===== */
		await page.goto( `${ BASE }/minn-admin/ai1wm`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-surface-status [data-sstatact]', { timeout: 30000 } );
		await page.evaluate( () => { const b = [ ...document.querySelectorAll( '.minn-surface-status [data-sstatact]' ) ].find( ( x ) => /Export site/.test( x.textContent ) ); if ( b ) b.click(); } );
		await page.waitForSelector( '.minn-surface-status [data-actgo]', { timeout: 10000 } );
		// Flip every skip toggle on so the run stays small; the armed inline
		// form renders each field as [data-actfield], toggles as switches.
		const flipped = await page.evaluate( () => {
			const form = document.querySelector( '.minn-surface-status .minn-action-fields' );
			const sw = [ ...form.querySelectorAll( '[data-actfield][data-ftype="toggle"], [data-actfield][role="switch"]' ) ];
			let n = 0;
			sw.forEach( ( s ) => { if ( s.getAttribute( 'aria-checked' ) !== 'true' && ! s.classList.contains( 'on' ) ) { s.click(); n++; } } );
			return { switches: sw.length, flipped: n };
		} );
		t.check( 'the action arms an inline form with the skip toggles', flipped.switches >= 5, JSON.stringify( flipped ) );
		// The skip toggles must reach the request itself. Archive size is no
		// proof on this site: the language packs and UpdraftPlus sets survive
		// every skip, so the export stays large however the toggles are set.
		const reqP = page.waitForRequest( ( r ) => r.method() === 'POST' && /minn-admin\/v1\/ai1wm\/export(\?|$)/.test( r.url() ), { timeout: 30000 } );
		await page.click( '.minn-surface-status [data-actgo]' );
		const req = await reqP;
		let sent = {};
		try { sent = JSON.parse( req.postData() || '{}' ); } catch ( e ) { sent = {}; }
		t.check( 'the skip toggles ride the export request', [ 'no_media', 'no_plugins', 'no_themes' ].every( ( k ) => sent[ k ] === true || sent[ k ] === 1 || sent[ k ] === '1' ), JSON.stringify( sent ) );
		await page.waitForFunction( () => { const c = document.querySelector( '#minn-job-chip' ); return c && ! c.hidden && /Exporting site/.test( c.textContent ); }, null, { timeout: 60000 } );
		t.check( 'the topbar pill appears for the running export', true );
		await page.click( '#minn-job-chip' );
		await page.waitForSelector( '.minn-job-modal', { timeout: 10000 } );
		t.check( 'clicking the pill opens the job modal with a progress bar and Stop', await page.evaluate( () => !! document.querySelector( '.minn-job-modal .minn-job-bar' ) && ! document.querySelector( '#minn-job-stop' ).hidden ) );
		await page.click( '#minn-modal-close-2' );

		/* ===== The pill follows the job to another route ===== */
		await page.goto( `${ BASE }/minn-admin/overview`, { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () => { const c = document.querySelector( '#minn-job-chip' ); return c && ! c.hidden; }, null, { timeout: 30000 } );
		t.check( 'the pill is still there after navigating (the job resumed from storage)', true );
		const saved = await page.evaluate( () => { try { return JSON.parse( localStorage.getItem( 'minn-job' ) || 'null' ); } catch ( e ) { return null; } } );
		t.check( 'the running job is persisted with its status and stop routes', !! saved && !! saved.statusRoute && !! saved.stopRoute, JSON.stringify( saved ) );
		const st1 = saved && saved.statusRoute ? await api( saved.statusRoute ) : { status: 0, body: null };
		t.check( 'the status route reports the export running', st1.status === 200 && !! st1.body && st1.body.status === 'running', JSON.stringify( st1.body ) );

		/* ===== Stop ===== */
		const stop = saved && saved.stopRoute ? await api( saved.stopRoute, { method: saved.stopMethod || 'DELETE' } ) : { status: 0, body: null };
		await page.waitForTimeout( 1500 );
		const after = saved && saved.statusRoute ? await api( saved.statusRoute ) : { status: 0, body: null };
		t.check( 'Stop cancels through the plugin and the status reads canceled', stop.status === 200 && !! after.body && after.body.status === 'canceled', JSON.stringify( { stop: stop.body, after: after.body } ) );
		await page.waitForFunction( () => { const c = document.querySelector( '#minn-job-chip' ); return ! c || c.hidden || /stopped|canceled|failed|done/.test( c.textContent ); }, null, { timeout: 30000 } ).catch( () => null );
		const chip = await page.$eval( '#minn-job-chip', ( c ) => ( c.hidden ? 'hidden' : c.textContent ) ).catch( () => 'gone' );
		t.check( 'the pill lets go of the stopped job', /hidden|gone|stopped|canceled/.test( chip ), chip );
	} finally {
		// Stop whatever is still running so no archive lands after the suite.
		const left = await page.evaluate( () => { try { return JSON.parse( localStorage.getItem( 'minn-job' ) || 'null' ); } catch ( e ) { return null; } } ).catch( () => null );
		if ( left && left.stopRoute ) await api( left.stopRoute, { method: left.stopMethod || 'DELETE' } ).catch( () => {} );
		for ( const i of await madeIds( before ) ) {
			await api( `minn-admin/v1/ai1wm/exports/${ i.id }`, { method: 'DELETE' } ).catch( () => {} );
		}
		await page.evaluate( () => { try { localStorage.removeItem( 'minn-job' ); } catch ( e ) {} } ).catch( () => {} );
		await t.done( browser, errors );
	}
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
