/**
 * Official WooCommerce Gift Cards surface: list, filter bar, detail, create
 * and the three verbs, over Minn's shim (minn-admin/v1/wcgc/…).
 *
 * Hand-issue is the fixture (their REST is the official create path). SKIPs
 * when WooCommerce Gift Cards is not active.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'woocommerce-gift-cards' );
	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );

	await login( page );
	await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN && window.MINN.nonce, { timeout: 30000 } );

	const api = ( path, opts ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		}, a.opts || {} ) );
		const text = await r.text();
		let body = null;
		try { body = JSON.parse( text ); } catch ( e ) { body = text; }
		return { status: r.status, body };
	}, { path, opts } );

	const probe = await api( 'minn-admin/v1/wcgc/status' );
	if ( 404 === probe.status ) {
		console.log( 'SKIP woocommerce-gift-cards: WooCommerce Gift Cards is not active on this site.' );
		await browser.close();
		process.exit( 0 );
	}
	t.check( 'WooCommerce Gift Cards available', true, '' );

	const suffix = Date.now().toString( 36 );
	const mail = `wcgc-${ suffix }@example.com`;
	let id = 0;

	try {
		t.check( 'status card names outstanding balance',
			200 === probe.status && /Outstanding balance/i.test( JSON.stringify( probe.body || {} ) ),
			JSON.stringify( probe.body ) );

		const created = await api( 'minn-admin/v1/wcgc/gift-cards', {
			method: 'POST',
			body: JSON.stringify( { amount: 25, recipient: mail, sender_name: 'Minn suite', message: 'hello' } ),
		} );
		id = ( created.body || {} ).id || 0;
		t.check( 'a card can be issued by hand', 200 === created.status && id > 0, JSON.stringify( created.body ) );

		const list = await api( 'minn-admin/v1/wcgc/gift-cards?per_page=100&search=' + encodeURIComponent( mail ) );
		const row = ( ( list.body || {} ).items || [] ).find( ( r ) => r.id === id );
		t.check( 'the list finds the new card by recipient', !! row && /25/.test( String( row.balance ) ), JSON.stringify( row ) );

		const view = await api( `minn-admin/v1/wcgc/gift-cards/${ id }/view` );
		t.check( 'the detail names the code and recipient',
			200 === view.status
				&& ( ( view.body || {} ).title || '' )
				&& JSON.stringify( view.body ).includes( mail ),
			JSON.stringify( view.body && view.body.title ) );

		const off = await api( `minn-admin/v1/wcgc/gift-cards/${ id }/status`, {
			method: 'POST', body: JSON.stringify( { enabled: false } ),
		} );
		t.check( 'disable goes through', 200 === off.status, JSON.stringify( off.body ) );
		const stillOff = await api( `minn-admin/v1/wcgc/gift-cards/${ id }/view` );
		t.check( 'a disabled card stays disabled', /disabled/i.test( JSON.stringify( stillOff.body ) ), '' );

		const on = await api( `minn-admin/v1/wcgc/gift-cards/${ id }/status`, {
			method: 'POST', body: JSON.stringify( { enabled: true } ),
		} );
		t.check( 'enable goes through', 200 === on.status, JSON.stringify( on.body ) );

		const bal = await api( `minn-admin/v1/wcgc/gift-cards/${ id }/balance`, {
			method: 'POST', body: JSON.stringify( { balance: 12.5 } ),
		} );
		t.check( 'balance can be set', 200 === bal.status, JSON.stringify( bal.body ) );

		const resend = await api( `minn-admin/v1/wcgc/gift-cards/${ id }/resend`, { method: 'POST' } );
		t.check( 'resend answers 200', 200 === resend.status, JSON.stringify( resend.body ) );

		const zero = await api( 'minn-admin/v1/wcgc/gift-cards', {
			method: 'POST', body: JSON.stringify( { amount: 0, recipient: mail } ),
		} );
		t.check( 'a card worth nothing is refused', 400 === zero.status, JSON.stringify( zero.body ) );

		const huge = await api( 'minn-admin/v1/wcgc/gift-cards', {
			method: 'POST', body: JSON.stringify( { amount: '1e20', recipient: mail } ),
		} );
		t.check( 'a scientifically huge amount is refused', 400 === huge.status, JSON.stringify( huge.body ) );

		const noMail = await api( 'minn-admin/v1/wcgc/gift-cards', {
			method: 'POST', body: JSON.stringify( { amount: 10 } ),
		} );
		t.check( 'a card with no recipient is refused', 400 === noMail.status, JSON.stringify( noMail.body ) );

		const markup = await api( 'minn-admin/v1/wcgc/gift-cards', {
			method: 'POST', body: JSON.stringify( { amount: 10, recipient: mail, code: '<script>x</script>' } ),
		} );
		t.check( 'a code that is not their pattern is refused', 400 === markup.status, JSON.stringify( markup.body ) );

		await page.goto( BASE + '/minn-admin/woocommerce-gift-cards', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-surface-status, .minn-table, .minn-empty', { timeout: 20000 } );
		const statusText = await page.evaluate( () => ( document.querySelector( '.minn-surface-status' ) || {} ).textContent || '' );
		t.check( 'the surface paints an outstanding-balance card',
			/Outstanding balance/i.test( statusText ), statusText.slice( 0, 120 ) );
		const family = await page.evaluate( () => {
			const members = ( window.MINN.surfaces || [] ).filter( ( s ) => s.family === 'gift-cards' );
			return { n: members.length, ids: members.map( ( s ) => s.id ), hasSwitch: !! document.querySelector( '#minn-surface-switch' ) };
		} );
		await page.waitForSelector( '#minn-surface-switch', { timeout: 8000 } ).catch( () => {} );
		const hasSwitch = await page.evaluate( () => !! document.querySelector( '#minn-surface-switch' ) );
		t.check( 'Gift cards shares a family switcher with YITH',
			family.n > 1 && hasSwitch, JSON.stringify( family ) );

		const forbidden = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/wcgc/gift-cards' );
			return r.status;
		} );
		t.check( 'the list route refuses an unauthenticated request', 401 === forbidden || 403 === forbidden, String( forbidden ) );
	} catch ( e ) {
		t.check( 'suite ran without throwing', false, String( e && e.message ? e.message : e ) );
	} finally {
		await t.done( browser, errors );
	}
} )();
