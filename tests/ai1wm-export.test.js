/**
 * All-in-One WP Migration exports as a Minn background job. The Backups
 * status card's Export site action (job: true) starts the plugin's own
 * export pipeline through its ai1wm/v1 REST controller; Minn shows a
 * topbar pill with the live percent, a modal with the step and Stop, and
 * lists the archive when the job ends. The suite runs a SMALL export
 * (media, plugins, themes and mu-plugins skipped) so the archive is a few
 * MB, deletes it after, and proves Stop on a second job.
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
		await page.click( '.minn-surface-status [data-actgo]' );
		await page.waitForFunction( () => { const c = document.querySelector( '#minn-job-chip' ); return c && ! c.hidden && /Exporting site/.test( c.textContent ); }, null, { timeout: 60000 } );
		t.check( 'the topbar pill appears for the running export', true );
		await page.click( '#minn-job-chip' );
		await page.waitForSelector( '.minn-job-modal', { timeout: 10000 } );
		t.check( 'clicking the pill opens the job modal with a progress bar and Stop', await page.evaluate( () => !! document.querySelector( '.minn-job-modal .minn-job-bar' ) && ! document.querySelector( '#minn-job-stop' ).hidden ) );
		await page.click( '#minn-modal-close-2' );

		/* ===== The pill follows the job to the end, on another route ===== */
		await page.goto( `${ BASE }/minn-admin/overview`, { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () => { const c = document.querySelector( '#minn-job-chip' ); return c && ! c.hidden; }, null, { timeout: 30000 } );
		t.check( 'the pill is still there after navigating (the job resumed from storage)', true );
		await page.waitForFunction( () => { const c = document.querySelector( '#minn-job-chip' ); return c && /done|failed|stopped/.test( c.textContent ); }, null, { timeout: 300000 } );
		const chip = await page.$eval( '#minn-job-chip', ( c ) => c.textContent );
		t.check( 'the pill reports the export finished', /done/.test( chip ), chip );
		const made = await madeIds( before );
		// The skip toggles must have reached the request: without media,
		// plugins and themes this site's archive is a few hundred MB at most,
		// against ~5 GB for a full export.
		const gb = ( size ) => { const m = /([\d.]+)\s*(GB|MB|KB|B)/.exec( size || '' ); if ( ! m ) return 0; const n = parseFloat( m[ 1 ] ); return { GB: n, MB: n / 1024, KB: n / 1048576, B: 0 }[ m[ 2 ] ]; };
		t.check( 'a new archive is in the exports list, well under a full export because the skip toggles applied', made.length >= 1 && made.every( ( i ) => gb( i.size ) < 3 ), JSON.stringify( made.map( ( i ) => [ i.title, i.size ] ) ) );

		/* ===== Stop ===== */
		const s2 = await api( 'minn-admin/v1/ai1wm/export', { method: 'POST', body: { no_media: true, no_plugins: true, no_themes: true, no_muplugins: true } } );
		t.check( 'a second export starts through the route with a job descriptor', s2.status === 200 && s2.body.job && s2.body.job.statusRoute, JSON.stringify( s2.body ) );
		const stop = await api( s2.body.job.stopRoute, { method: 'DELETE' } );
		await page.waitForTimeout( 1500 );
		const after = await api( s2.body.job.statusRoute );
		t.check( 'Stop cancels through the plugin and the status reads canceled', stop.status === 200 && after.body && after.body.status === 'canceled', JSON.stringify( { stop: stop.body, after: after.body } ) );
	} finally {
		for ( const i of await madeIds( before ) ) {
			await api( `minn-admin/v1/ai1wm/exports/${ i.id }`, { method: 'DELETE' } ).catch( () => {} );
		}
		await page.evaluate( () => { try { localStorage.removeItem( 'minn-job' ); } catch ( e ) {} } ).catch( () => {} );
		await t.done( browser, errors );
	}
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
