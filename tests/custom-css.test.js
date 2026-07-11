/**
 * Custom CSS (Settings → Design) — core's per-theme custom_css post over
 * minn-admin/v1/custom-css. Saves real CSS through the UI, verifies it
 * reaches the FRONT END (the #wp-custom-css style tag), asserts the
 * unbalanced-brace refusal keeps the typed text in the editor (the
 * early-return rule), and restores the original CSS in the finally.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'custom-css' );
	const { browser, page, errors } = await launch();
	await login( page );

	const restCss = ( method, css ) => page.evaluate( async ( args ) => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/custom-css', {
			method: args.method,
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			body: args.method === 'POST' ? JSON.stringify( { css: args.css } ) : undefined,
		} );
		return r.json();
	}, { method, css } );

	await page.goto( BASE + '/minn-admin/settings', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-settings-nav-item', { timeout: 20000 } );
	const original = ( await restCss( 'GET' ) ).css || '';

	try {
		const hasDesign = await page.evaluate( () =>
			[ ...document.querySelectorAll( '.minn-settings-nav-item' ) ].some( ( b ) => b.textContent.trim() === 'Design' ) );
		t.check( 'Settings nav shows a Design section', hasDesign );

		await page.evaluate( () => { [ ...document.querySelectorAll( '.minn-settings-nav-item' ) ].find( ( b ) => b.textContent.trim() === 'Design' ).click(); } );
		await page.waitForSelector( '#minn-custom-css', { timeout: 8000 } );
		t.check( 'Design tab shows the CSS editor', true );
		t.check( 'note names the active theme', await page.evaluate( () =>
			/active theme \(.+\)/.test( document.querySelector( '.minn-settings-body' ).textContent ) ) );

		// Tab indents instead of leaving the field.
		await page.click( '#minn-custom-css' );
		await page.evaluate( () => { const el = document.querySelector( '#minn-custom-css' ); el.value = ''; el.focus(); } );
		await page.keyboard.press( 'Tab' );
		t.check( 'Tab inserts an indent inside the editor', await page.$eval( '#minn-custom-css', ( el ) => el.value === '  ' && document.activeElement === el ) );

		// Real keystrokes for the CSS itself, then save through the UI.
		await page.evaluate( () => { document.querySelector( '#minn-custom-css' ).value = ''; } );
		await page.type( '#minn-custom-css', '.minn-suite-css { color: rgb(1, 2, 3); }' );
		await page.click( '#minn-save-settings' );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-toast' ) ).some( ( x ) => /Settings saved/.test( x.textContent ) ),
		null, { timeout: 15000 } );
		t.check( 'save succeeds through the Settings button', true );

		// Front-end truth: the CSS rides the #wp-custom-css style tag.
		const front = await page.evaluate( async () => {
			const r = await fetch( window.MINN.site.url + '?minn_css_probe=' + Math.random(), { credentials: 'same-origin' } );
			return r.text();
		} );
		t.check( 'front end serves the saved CSS', front.includes( '.minn-suite-css' ) && front.includes( 'wp-custom-css' ) );

		// Broken CSS is refused and the typed text SURVIVES the failed save.
		await page.evaluate( () => { document.querySelector( '#minn-custom-css' ).value = ''; } );
		await page.type( '#minn-custom-css', '.broken { color: red;' );
		await page.click( '#minn-save-settings' );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-toast' ) ).some( ( x ) => /unbalanced/.test( x.textContent ) ),
		null, { timeout: 15000 } );
		t.check( 'unbalanced CSS is refused with a clear toast', true );
		t.check( 'typed CSS survives the refusal', await page.$eval( '#minn-custom-css', ( el ) => el.value === '.broken { color: red;' ) );
		t.check( 'server kept the last valid CSS', ( await restCss( 'GET' ) ).css.includes( '.minn-suite-css' ) );
	} finally {
		await restCss( 'POST', original ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
