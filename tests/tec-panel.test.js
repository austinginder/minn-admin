/**
 * The Events Calendar editor panel — Event details on tribe_events posts
 * (adapters/the-events-calendar.php), including the async-suggest panel
 * field's first real outing (venue + organizer pickers searching
 * minn-admin/v1/tec/suggest as you type). Writes ride TEC's own
 * saveEventMeta, so the suite verifies stored values through the panel's
 * REST field after real UI interaction.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'tec-panel' );
	const { browser, page, errors } = await launch();
	await login( page );
	await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN, null, { timeout: 20000 } );

	// Fixtures over core REST: a venue, an organizer, a bare draft event.
	const fx = await page.evaluate( async () => {
		const jhead = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
		const mk = async ( type, title ) => ( await ( await fetch( window.MINN.restUrl + 'wp/v2/' + type, {
			method: 'POST', headers: jhead, credentials: 'same-origin',
			body: JSON.stringify( { title, status: type === 'tribe_events' ? 'draft' : 'publish' } ),
		} ) ).json() ).id;
		return {
			venue: await mk( 'tribe_venue', 'Minn Suite Hall' ),
			organizer: await mk( 'tribe_organizer', 'Minn Suite Presenters' ),
			event: await mk( 'tribe_events', 'Minn Suite Event' ),
		};
	} );
	t.check( 'fixtures created', !! ( fx.venue && fx.organizer && fx.event ), JSON.stringify( fx ) );

	const readTec = () => page.evaluate( async ( id ) => {
		const r = await fetch( window.MINN.restUrl + `wp/v2/tribe_events/${ id }?context=edit&_fields=minn_tec&_cb=` + Math.random(), {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return ( await r.json() ).minn_tec;
	}, fx.event );

	try {
		await page.goto( BASE + '/minn-admin/editor/tribe_events/' + fx.event, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-side-door="panel:tec"]', { timeout: 20000 } );
		const door = await page.$eval( '[data-side-door="panel:tec"]', ( el ) => el.textContent );
		t.check( 'Event details door renders with the TEC badge', /Event details/.test( door ) && /The Events Calendar/.test( door ), door.trim().replace( /\s+/g, ' ' ) );

		await page.click( '[data-side-door="panel:tec"]' );
		await page.waitForSelector( '.minn-editor-side-modal [data-pf="tec:start"]', { timeout: 10000 } );
		t.check( 'suggest fields render for venue and organizer', !! ( await page.$( '[data-pf="tec:venue"][data-ftype="suggest"]' ) ) && !! ( await page.$( '[data-pf="tec:organizer"][data-ftype="suggest"]' ) ) );

		await page.fill( '[data-pf="tec:start"]', '2026-09-04 19:00' );
		await page.fill( '[data-pf="tec:end"]', '2026-09-04 21:30' );
		await page.fill( '[data-pf="tec:cost"]', 'Free' );

		// The async-suggest flow, for real: type, wait for the fetched rows,
		// click the venue.
		await page.click( '[data-pf="tec:venue"] .minn-ac-input' );
		await page.type( '[data-pf="tec:venue"] .minn-ac-input', 'Minn Suite' );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '[data-pf="tec:venue"] .minn-ac-item' ) ).some( ( x ) => /Minn Suite Hall/.test( x.textContent ) ),
		null, { timeout: 10000 } );
		await page.evaluate( () =>
			[ ...document.querySelectorAll( '[data-pf="tec:venue"] .minn-ac-item' ) ].find( ( x ) => /Minn Suite Hall/.test( x.textContent ) )
				.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true } ) ) );
		t.check( 'venue pick lands in the field', await page.$eval( '[data-pf="tec:venue"]', ( el, vid ) => el.dataset.sgval === String( vid ), fx.venue ) );

		await page.click( '[data-pf="tec:organizer"] .minn-ac-input' );
		await page.type( '[data-pf="tec:organizer"] .minn-ac-input', 'Presenters' );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '[data-pf="tec:organizer"] .minn-ac-item' ) ).some( ( x ) => /Minn Suite Presenters/.test( x.textContent ) ),
		null, { timeout: 10000 } );
		await page.evaluate( () =>
			[ ...document.querySelectorAll( '[data-pf="tec:organizer"] .minn-ac-item' ) ].find( ( x ) => /Minn Suite Presenters/.test( x.textContent ) )
				.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true } ) ) );

		await page.keyboard.press( 'Meta+s' );
		let tec = null;
		for ( let i = 0; i < 20; i++ ) {
			tec = await readTec();
			if ( tec && tec.start === '2026-09-04 19:00' && tec.venue && String( tec.venue.value ) === String( fx.venue ) ) break;
			await page.waitForTimeout( 500 );
		}
		t.check( 'event details persisted through TEC saveEventMeta',
			!! tec && tec.start === '2026-09-04 19:00' && tec.end === '2026-09-04 21:30' && tec.cost === 'Free',
			JSON.stringify( tec ) );
		t.check( 'venue and organizer linked through TEC',
			!! tec && tec.venue && String( tec.venue.value ) === String( fx.venue )
				&& tec.organizer && String( tec.organizer.value ) === String( fx.organizer ),
			JSON.stringify( tec && { v: tec.venue, o: tec.organizer } ) );

		// All-day flip through TEC's own day-bounds logic.
		await page.click( '[data-pf="tec:all_day"]' );
		await page.keyboard.press( 'Meta+s' );
		let allday = null;
		for ( let i = 0; i < 20; i++ ) {
			allday = await readTec();
			if ( allday && allday.all_day === true ) break;
			await page.waitForTimeout( 500 );
		}
		t.check( 'all-day flip snaps to TEC day bounds', !! allday && allday.all_day === true && /00:00$/.test( allday.start ), JSON.stringify( allday && { a: allday.all_day, s: allday.start } ) );
	} finally {
		await page.evaluate( async ( fx2 ) => {
			for ( const [ type, id ] of [ [ 'tribe_events', fx2.event ], [ 'tribe_venue', fx2.venue ], [ 'tribe_organizer', fx2.organizer ] ] ) {
				await fetch( window.MINN.restUrl + `wp/v2/${ type }/${ id }?force=true`, {
					method: 'DELETE', headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} ).catch( () => {} );
			}
		}, fx ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
