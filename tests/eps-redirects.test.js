/**
 * 301 Redirects (eps-301-redirects) in the Redirects family — a table shim
 * over {prefix}redirects (adapters/eps-301-redirects.php). Drives the full
 * UI loop: Add redirect (with the status select), row renders with the
 * status pill and hit count, detail edit flips the status, and delete
 * confirms. A post-ID target must display as its permalink. The plugin is
 * installed-but-deactivated at rest (redirects family convention), so the
 * suite activates it first and restores inactive in the finally.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'eps-redirects' );
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
		return r.ok;
	}, { plugin, status } );
	const listRest = () => page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/eps301/redirects?_cb=' + Math.random(), {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return r.json();
	} );

	try {
		t.check( 'plugin activates over REST', await setPlugin( 'eps-301-redirects/eps-301-redirects', 'active' ) );

		// Fresh boot so the surface registers; deep-link to the surface route.
		await page.goto( BASE + '/minn-admin/eps-301-redirects', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-surface-add', { timeout: 20000 } );
		t.check( 'surface renders with the Add redirect button', true );

		/* ===== Create through the modal (status select included) ===== */
		await page.click( '#minn-surface-add' );
		await page.waitForSelector( '[data-createfield="from"]', { timeout: 8000 } );
		await page.type( '[data-createfield="from"]', '/eps-suite-old' );
		await page.type( '[data-createfield="to"]', '/eps-suite-new' );
		t.check( 'status field renders as a themed combobox', await page.$eval( '[data-createfield="status"]', ( el ) => el.dataset.ftype === 'combobox' ) );
		await page.click( '[data-createfield="status"] .minn-ac-input' );
		await page.waitForSelector( '[data-createfield="status"] .minn-ac-item[data-acv="302"]', { timeout: 5000 } );
		await page.click( '[data-createfield="status"] .minn-ac-item[data-acv="302"]' );
		await page.click( '#minn-surface-create' );
		await page.waitForFunction( () =>
			! document.querySelector( '[data-createfield="from"]' )
			&& Array.from( document.querySelectorAll( '.minn-surface-row, .minn-table-row' ) ).some( ( r ) => r.textContent.includes( '/eps-suite-old' ) ),
		null, { timeout: 15000 } );
		t.check( 'created rule appears in the list', true );
		const rowText = await page.evaluate( () =>
			( Array.from( document.querySelectorAll( '.minn-surface-row, .minn-table-row' ) ).find( ( r ) => r.textContent.includes( '/eps-suite-old' ) ) || { textContent: '' } ).textContent );
		t.check( 'row carries the 302 status', /302/.test( rowText ), rowText.slice( 0, 120 ) );

		let list = await listRest();
		const rule = ( list.items || [] ).find( ( r ) => r.from === '/eps-suite-old' );
		t.check( 'rule stored in the redirects table', !! rule && rule.to === '/eps-suite-new' && rule.status === '302', JSON.stringify( rule ) );

		/* ===== Detail edit: retarget to a post ID, flip status ===== */
		await page.evaluate( () => {
			Array.from( document.querySelectorAll( '.minn-surface-row, .minn-table-row' ) )
				.find( ( r ) => r.textContent.includes( '/eps-suite-old' ) ).click();
		} );
		await page.waitForSelector( '[data-editfield="to"]', { timeout: 10000 } );
		await page.evaluate( () => { document.querySelector( '[data-editfield="to"]' ).value = ''; } );
		await page.type( '[data-editfield="to"]', '1' );
		await page.click( '[data-editfield="status"] .minn-ac-input' );
		await page.waitForSelector( '[data-editfield="status"] .minn-ac-item[data-acv="301"]', { timeout: 5000 } );
		await page.click( '[data-editfield="status"] .minn-ac-item[data-acv="301"]' );
		await page.click( '#minn-surface-save' );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-toast' ) ).some( ( x ) => /Saved|saved/.test( x.textContent ) ),
		null, { timeout: 15000 } );
		list = await listRest();
		const edited = ( list.items || [] ).find( ( r ) => r.from === '/eps-suite-old' );
		t.check( 'edit persists: post-ID target + 301', !! edited && edited.to === '1' && edited.status === '301', JSON.stringify( edited ) );
		t.check( 'post-ID target displays as its permalink', !! edited && /^https?:\/\//.test( edited.target ), edited && edited.target );

		/* ===== The rule actually redirects on the front end ===== */
		const redirect = await page.evaluate( async () => {
			const r = await fetch( window.MINN.site.url.replace( /\/$/, '' ) + '/eps-suite-old', { redirect: 'manual', credentials: 'omit' } );
			return { status: r.status, type: r.type };
		} );
		// fetch with redirect:manual reports opaqueredirect for a live redirect.
		t.check( 'front end serves the redirect', redirect.type === 'opaqueredirect' || redirect.status === 301, JSON.stringify( redirect ) );

		/* ===== Delete with confirm (save closed the modal; re-open the row) ===== */
		await page.waitForFunction( () =>
			! document.querySelector( '[data-editfield="to"]' )
			&& Array.from( document.querySelectorAll( '.minn-table-row' ) ).some( ( r ) => r.textContent.includes( '/eps-suite-old' ) ),
		null, { timeout: 15000 } );
		await page.evaluate( () => {
			Array.from( document.querySelectorAll( '.minn-table-row' ) )
				.find( ( r ) => r.textContent.includes( '/eps-suite-old' ) ).click();
		} );
		await page.waitForSelector( '[data-saction]', { timeout: 10000 } );
		page.once( 'dialog', ( d ) => d.accept() );
		await page.click( '[data-saction]' );
		await page.waitForFunction( () =>
			! document.querySelector( '[data-saction]' )
			&& ! Array.from( document.querySelectorAll( '.minn-table-row' ) ).some( ( r ) => r.textContent.includes( '/eps-suite-old' ) ),
		null, { timeout: 15000 } );
		list = await listRest();
		t.check( 'delete removes the rule', ! ( list.items || [] ).some( ( r ) => r.from === '/eps-suite-old' ), String( list.total ) );
	} finally {
		// Sweep any leftover suite rules, then back to the resting state.
		await page.evaluate( async () => {
			const h = { 'X-WP-Nonce': window.MINN.nonce };
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/eps301/redirects?search=eps-suite', { headers: h, credentials: 'same-origin' } );
			const data = await r.json();
			for ( const it of ( data.items || [] ) ) {
				await fetch( window.MINN.restUrl + 'minn-admin/v1/eps301/redirects/' + it.id, { method: 'DELETE', headers: h, credentials: 'same-origin' } );
			}
		} ).catch( () => {} );
		await setPlugin( 'eps-301-redirects/eps-301-redirects', 'inactive' ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
