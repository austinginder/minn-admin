/**
 * Themed date-time picker: the schedule field is a readonly display input
 * (machine value on data-dp, same YYYY-MM-DDTHH:mm shape datetime-local
 * produced) opening a Minn-styled popover — month grid, lenient time field,
 * Now/Clear. Scheduling round-trips through a real Publish.
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'date-picker' );
	await login( page );

	const id = await createPost( page, { title: 'Picker probe', content: '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->', status: 'draft' } );
	await openEditor( page, id );

	const field = '#minn-schedule-input';
	t.check( 'schedule field is the themed display input', await page.$eval( field, ( i ) => i.readOnly && i.type === 'text' && i.classList.contains( 'minn-dp-input' ) ), '' );

	/* ===== Open: month grid renders ===== */
	await page.click( field );
	await page.waitForSelector( '.minn-dp-pop', { timeout: 5000 } );
	const grid = await page.evaluate( () => ( {
		days: document.querySelectorAll( '.minn-dp-day' ).length,
		wds: document.querySelectorAll( '.minn-dp-wd' ).length,
		month: document.querySelector( '.minn-dp-month' ).textContent,
	} ) );
	t.check( 'popover renders a full 6-week grid', grid.days === 42 && grid.wds === 7 && /\d{4}/.test( grid.month ), JSON.stringify( grid ) );

	/* ===== Pick day 15 of NEXT month + a time ===== */
	await page.click( '.minn-dp-nav[data-nav="1"]' );
	await page.waitForTimeout( 150 );
	await page.locator( '.minn-dp-day:not(.out)', { hasText: /^15$/ } ).first().click();
	await page.waitForTimeout( 150 );
	await page.fill( '.minn-dp-time-input', '8:45 pm' );
	await page.keyboard.press( 'Enter' );
	await page.waitForFunction( () => /T20:45$/.test( document.querySelector( '#minn-schedule-input' ).dataset.dp ), { timeout: 5000 } );
	const picked = await page.$eval( field, ( i ) => ( { dp: i.dataset.dp, display: i.value } ) );
	t.check( 'picked day + time land in the machine value', /-15T20:45$/.test( picked.dp ), JSON.stringify( picked ) );
	t.check( 'display reads as prose, not ISO', /15, \d{4} · 8:45 PM/.test( picked.display ), picked.display );
	t.check( 'publish button flips to Schedule', ( await page.$eval( '#minn-publish-btn', ( b ) => b.textContent.trim() ) ) === 'Schedule', '' );

	/* ===== Real scheduling round-trip ===== */
	await page.keyboard.press( 'Escape' );
	await page.click( '#minn-publish-btn' );
	await page.waitForTimeout( 1800 );
	const saved = await page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=status,date', { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return await r.json();
	}, id );
	t.check( 'post schedules with the picked datetime', saved.status === 'future' && saved.date.startsWith( picked.dp ), JSON.stringify( saved ) );

	/* ===== Reopen: selection survives and highlights ===== */
	await openEditor( page, id );
	const display = await page.$eval( field, ( i ) => i.value );
	t.check( 'reopened editor shows the scheduled date', /8:45 PM/.test( display ), display );
	await page.click( field );
	await page.waitForSelector( '.minn-dp-pop' );
	t.check( 'popover highlights the scheduled day', ( await page.$eval( '.minn-dp-day.sel', ( d ) => d.textContent.trim() ) ) === '15', '' );

	await page.keyboard.press( 'Escape' );

	/* ===== Clear empties the value (fresh draft — a scheduled post's stored
	   date re-seeds the field on any sidebar refresh, same as the old native
	   input) ===== */
	const id2 = await createPost( page, { title: 'Picker clear probe', content: '<!-- wp:paragraph --><p>y</p><!-- /wp:paragraph -->', status: 'draft' } );
	await openEditor( page, id2 );
	await page.click( field );
	await page.waitForSelector( '.minn-dp-pop' );
	await page.click( '[data-dp-clear]' );
	await page.waitForTimeout( 200 );
	const cleared = await page.$eval( field, ( i ) => ( { dp: i.dataset.dp, v: i.value, ph: i.placeholder, popGone: ! document.querySelector( '.minn-dp-pop' ) } ) );
	t.check( 'Clear empties value and shows the placeholder', cleared.dp === '' && cleared.v === '' && cleared.ph === 'Immediately' && cleared.popGone, JSON.stringify( cleared ) );

	/* ===== Done commits the visible (unblurred) time and closes ===== */
	await page.click( field );
	await page.waitForSelector( '.minn-dp-pop' );
	await page.locator( '.minn-dp-day:not(.out)', { hasText: /^20$/ } ).first().click();
	await page.waitForTimeout( 150 );
	await page.fill( '.minn-dp-time-input', '6:15 am' );
	await page.click( '[data-dp-done]' ); // no Enter, no blur — Done reads the field
	await page.waitForTimeout( 200 );
	const done = await page.$eval( field, ( i ) => ( { dp: i.dataset.dp, popGone: ! document.querySelector( '.minn-dp-pop' ) } ) );
	t.check( 'Done commits the visible time and closes', /-20T06:15$/.test( done.dp ) && done.popGone, JSON.stringify( done ) );

	/* ===== Calendar marks other published/scheduled posts ===== */
	// Seed a published post on a past day of the current month, then open a
	// fresh draft's picker and wait for the async mark paint.
	const now = new Date();
	const day = Math.max( 1, Math.min( 28, now.getDate() - 2 ) );
	const y = now.getFullYear();
	const mo = String( now.getMonth() + 1 ).padStart( 2, '0' );
	const dd = String( day ).padStart( 2, '0' );
	const dayKey = `${ y }-${ mo }-${ dd }`;
	const marker = await createPost( page, {
		title: 'Calendar Mark Fixture ZQX',
		content: '<!-- wp:paragraph --><p>mark</p><!-- /wp:paragraph -->',
		status: 'publish',
		date: `${ dayKey }T10:00:00`,
	} );
	const id3 = await createPost( page, { title: 'Picker mark probe', content: '<!-- wp:paragraph --><p>z</p><!-- /wp:paragraph -->', status: 'draft' } );
	await openEditor( page, id3 );
	await page.click( field );
	await page.waitForSelector( '.minn-dp-pop', { timeout: 5000 } );
	await page.waitForFunction( ( key ) => {
		const btn = document.querySelector( `.minn-dp-day[data-day="${ key }"]` );
		return btn && btn.classList.contains( 'has-posts' );
	}, dayKey, { timeout: 12000 } );
	const mark = await page.evaluate( ( key ) => {
		const btn = document.querySelector( `.minn-dp-day[data-day="${ key }"]` );
		const leg = document.querySelector( '[data-dp-legend]' );
		return btn ? {
			has: btn.classList.contains( 'has-posts' ),
			title: btn.title || '',
			legend: leg && ! leg.hidden ? leg.textContent : '',
		} : null;
	}, dayKey );
	t.check( 'days with other published posts wear a mark', mark && mark.has, JSON.stringify( mark ) );
	t.check( 'mark tooltip names the other post', mark && /Calendar Mark Fixture ZQX/.test( mark.title ), JSON.stringify( mark ) );
	t.check( 'legend explains the highlights', mark && /other posts/.test( mark.legend ), JSON.stringify( mark ) );

	await deletePost( page, id );
	await deletePost( page, id2 );
	await deletePost( page, id3 );
	await deletePost( page, marker );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
