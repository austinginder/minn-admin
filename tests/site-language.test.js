/**
 * Site language (Settings → Site): a combobox of installed + downloadable
 * locales that saves through minn-admin/v1/site/language, never the
 * wp/v2/settings sweep (an uninstalled pick downloads its pack on save).
 * de_DE is an installed fixture pack on minnadmin, so the round trip needs
 * no network. Restores the site default (English) in finally.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'site-language' );
	await login( page );

	const siteLocale = () => page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/languages', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		} );
		return ( await r.json() ).site;
	} );
	const resetLocale = () => page.evaluate( async () => {
		await fetch( window.MINN.restUrl + 'minn-admin/v1/site/language', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			body: JSON.stringify( { locale: '' } ),
		} );
	} );

	try {
		t.check( 'baseline site locale is the default', ( await siteLocale() ) === '', '' );

		await page.goto( BASE + '/minn-admin/settings', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-key="minn_language"]', { timeout: 20000 } );
		const seed = await page.evaluate( () => document.querySelector( '[data-key="minn_language"]' ).value );
		t.check( 'field seeds with the default label', /English \(United States\)/.test( seed ), seed );

		/* ===== Pick German, save, WPLANG follows ===== */
		await page.click( '[data-key="minn_language"]' );
		await page.evaluate( () => { document.querySelector( '[data-key="minn_language"]' ).value = ''; } );
		await page.type( '[data-key="minn_language"]', 'Deutsch' );
		await page.waitForSelector( '.minn-ac-item[data-acv="de_DE"]', { timeout: 10000 } );
		await page.click( '.minn-ac-item[data-acv="de_DE"]' );
		await page.click( '#minn-save-settings' );
		await page.waitForFunction( () =>
			[ ...document.querySelectorAll( '.minn-toast' ) ].some( ( x ) => /saved/i.test( x.textContent ) ),
		null, { timeout: 20000 } );
		t.check( 'save lands de_DE as the site language', ( await siteLocale() ) === 'de_DE', await siteLocale() );

		/* ===== The field re-seeds with the saved pick ===== */
		await page.goto( BASE + '/minn-admin/settings', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-key="minn_language"]', { timeout: 20000 } );
		const seeded = await page.evaluate( () => document.querySelector( '[data-key="minn_language"]' ).value );
		t.check( 'field re-seeds with Deutsch after save', /Deutsch/.test( seeded ), seeded );

		/* ===== Back to the default through the same flow ===== */
		await page.click( '[data-key="minn_language"]' );
		await page.evaluate( () => { document.querySelector( '[data-key="minn_language"]' ).value = ''; } );
		await page.type( '[data-key="minn_language"]', 'English (United' );
		await page.waitForSelector( '.minn-ac-item[data-acv=""]', { timeout: 10000 } );
		await page.click( '.minn-ac-item[data-acv=""]' );
		await page.click( '#minn-save-settings' );
		await page.waitForFunction( () =>
			[ ...document.querySelectorAll( '.minn-toast' ) ].some( ( x ) => /saved/i.test( x.textContent ) ),
		null, { timeout: 20000 } );
		t.check( 'saving English restores the default', ( await siteLocale() ) === '', await siteLocale() );
	} finally {
		await resetLocale().catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
