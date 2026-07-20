/**
 * Licenses card "Turn on" — an inactive licensed component's row carries a
 * small power button that activates the plugin (or switches to the theme,
 * behind a confirm) in place, and the card re-renders from fresh server
 * state so the vendor's real license controls appear (action callables only
 * attach while the vendor code is loaded).
 *
 * Drives Gravity Perks (installed-inactive paid plugin whose provider
 * declares activate) end to end, and proves the theme path's confirm can be
 * dismissed without switching the site theme (Avada row). Gravity Perks is
 * restored inactive in the finally.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'license-turnon' );
	const { browser, page, errors } = await launch();
	await login( page );
	await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN, null, { timeout: 20000 } );

	const setPlugin = ( plugin, status ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + a.plugin, {
			method: 'PUT', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: JSON.stringify( { status: a.status } ),
		} );
		return ( await r.json() ).status;
	}, { plugin, status } );
	const activeTheme = () => page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/themes?status=active&_fields=stylesheet', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return ( await r.json() )[ 0 ].stylesheet;
	} );
	const gpRow = () => page.evaluate( () => {
		const row = Array.from( document.querySelectorAll( '.minn-lic-item' ) )
			.find( ( r ) => r.textContent.includes( 'Gravity Perks' ) );
		if ( ! row ) return null;
		return {
			off: row.classList.contains( 'off' ),
			turnOn: !! row.querySelector( '[data-lic="turnon"]' ),
			activate: !! row.querySelector( '[data-lic="activate"], [data-lic="href"]' ),
			meta: ( row.querySelector( '.minn-sys-lic-meta' ) || { textContent: '' } ).textContent,
		};
	} );

	try {
		// Baseline: Gravity Perks inactive (rule: seed, never assume).
		await setPlugin( 'gravityperks/gravityperks', 'inactive' ).catch( () => {} );

		await page.goto( BASE + '/minn-admin/extensions', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-xtab="licenses"]', { timeout: 20000 } );
		await page.click( '[data-xtab="licenses"]' );
		await page.waitForSelector( '#minn-sys-licenses .minn-lic-item', { timeout: 30000 } );
		{
			const off = await page.$( '#minn-lic-off-toggle' );
			if ( off && await page.$eval( '#minn-lic-off-toggle', ( el ) => el.getAttribute( 'aria-expanded' ) !== 'true' ) ) {
				await page.click( '#minn-lic-off-toggle' );
				await page.waitForTimeout( 250 );
			}
		}

		let row = await gpRow();
		t.check( 'inactive row is dimmed with a Turn on button', !! row && row.off && row.turnOn, JSON.stringify( row ) );
		t.check( 'meta shortens to "not active" when Turn on is offered', !! row && /not active/.test( row.meta ) && ! /manage its license/.test( row.meta ), row && row.meta );
		t.check( 'no license controls before the vendor code loads', !! row && ! row.activate );

		/* ===== Theme path: confirm can be dismissed, nothing switches ===== */
		const before = await activeTheme();
		const avadaBtn = await page.evaluate( () => {
			const row = Array.from( document.querySelectorAll( '.minn-lic-item' ) )
				.find( ( r ) => r.textContent.includes( 'Avada' ) );
			const btn = row && row.querySelector( '[data-lic="turnon"]' );
			return btn ? { component: btn.dataset.component, title: btn.title } : null;
		} );
		t.check( 'theme row offers Turn on with switch-the-theme copy', !! avadaBtn && avadaBtn.component === 'theme:Avada' && /theme/i.test( avadaBtn.title ), JSON.stringify( avadaBtn ) );
		await page.evaluate( () => {
			const row = Array.from( document.querySelectorAll( '.minn-lic-item' ) )
				.find( ( r ) => r.textContent.includes( 'Avada' ) );
			row.querySelector( '[data-lic="turnon"]' ).click();
		} );
		await page.waitForSelector( '.minn-confirm-overlay', { timeout: 10000 } );
		const themeConfirm = await page.evaluate( () => document.querySelector( '.minn-confirm-modal' ).textContent );
		t.check( 'theme turn-on asks with switch-the-theme copy', /theme/i.test( themeConfirm ) && /stays installed/i.test( themeConfirm ), themeConfirm );
		await page.click( '.minn-confirm-overlay [data-cancel]' );
		await new Promise( ( r ) => setTimeout( r, 800 ) );
		t.check( 'cancelling the theme confirm leaves the site theme alone', ( await activeTheme() ) === before, before );

		/* ===== Plugin path: turn on, controls appear on the fresh render ===== */
		await page.evaluate( () => {
			const row = Array.from( document.querySelectorAll( '.minn-lic-item' ) )
				.find( ( r ) => r.textContent.includes( 'Gravity Perks' ) );
			row.querySelector( '[data-lic="turnon"]' ).click();
		} );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-toast' ) ).some( ( x ) => /Gravity Perks turned on/.test( x.textContent ) ),
		null, { timeout: 30000 } );
		// The card repaints from a fresh /system fetch; poll the row state.
		await page.waitForFunction( () => {
			const row = Array.from( document.querySelectorAll( '.minn-lic-item' ) )
				.find( ( r ) => r.textContent.includes( 'Gravity Perks' ) );
			return row && ! row.classList.contains( 'off' ) && ! row.querySelector( '[data-lic="turnon"]' );
		}, null, { timeout: 30000 } );
		row = await gpRow();
		t.check( 'row un-dims and the Turn on button is gone', !! row && ! row.off && ! row.turnOn );
		t.check( 'the vendor\'s license controls are revealed', !! row && row.activate, JSON.stringify( row ) );
		const status = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/gravityperks/gravityperks', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return ( await r.json() ).status;
		} );
		t.check( 'plugin is really active server-side', status === 'active', status );
	} finally {
		await setPlugin( 'gravityperks/gravityperks', 'inactive' ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
