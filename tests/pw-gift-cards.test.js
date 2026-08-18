/**
 * PW WooCommerce Gift Cards surface: list, detail, create and the three
 * verbs over Minn's shim (minn-admin/v1/pwgc/…). One adapter serves free
 * and Pro. The suite activates Pro when it is installed-inactive so the
 * recipient/send fields get a real pass, then restores.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'pw-gift-cards' );
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

	const toggle = async ( slug, on ) => {
		const id = slug.replace( /\//g, '%2F' );
		return api( `wp/v2/plugins/${ id }`, {
			method: 'PUT',
			body: JSON.stringify( { status: on ? 'active' : 'inactive' } ),
		} );
	};

	let restored = false;
	const probe0 = await api( 'minn-admin/v1/pwgc/status' );
	if ( 404 === probe0.status ) {
		const on = await toggle( 'pw-gift-cards/pw-gift-cards', true );
		if ( 200 !== on.status ) {
			console.log( 'SKIP pw-gift-cards: PW WooCommerce Gift Cards is not installed on this site.' );
			await browser.close();
			process.exit( 0 );
		}
		restored = true;
		await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () => window.MINN && window.MINN.nonce, { timeout: 30000 } );
	}

	const probe = await api( 'minn-admin/v1/pwgc/status' );
	if ( 404 === probe.status ) {
		console.log( 'SKIP pw-gift-cards: PW WooCommerce Gift Cards is not active on this site.' );
		if ( restored ) await toggle( 'pw-gift-cards/pw-gift-cards', false );
		await browser.close();
		process.exit( 0 );
	}
	t.check( 'PW Gift Cards available', true, '' );

	const suffix = Date.now().toString( 36 );
	const mail = `pwgc-${ suffix }@example.com`;
	let id = 0;

	try {
		t.check( 'status card names outstanding balance',
			200 === probe.status && /Outstanding balance/i.test( JSON.stringify( probe.body || {} ) ),
			JSON.stringify( probe.body ) );

		const created = await api( 'minn-admin/v1/pwgc/gift-cards', {
			method: 'POST',
			body: JSON.stringify( { amount: 20, recipient: mail, sender_name: 'Minn suite', message: 'hello' } ),
		} );
		id = ( created.body || {} ).id || 0;
		t.check( 'a card can be issued by hand', 200 === created.status && id > 0, JSON.stringify( created.body ) );

		const list = await api( 'minn-admin/v1/pwgc/gift-cards?per_page=100&search=' + encodeURIComponent( mail ) );
		const row = ( ( list.body || {} ).items || [] ).find( ( r ) => r.id === id )
			|| ( ( list.body || {} ).items || [] ).find( ( r ) => ( r.recipient || '' ) === mail );
		t.check( 'the list finds the new card', !! row, JSON.stringify( list.body && list.body.items && list.body.items[ 0 ] ) );

		const view = await api( `minn-admin/v1/pwgc/gift-cards/${ id }/view` );
		t.check( 'the detail names the code',
			200 === view.status && ( view.body || {} ).title, JSON.stringify( view.body && view.body.title ) );

		const off = await api( `minn-admin/v1/pwgc/gift-cards/${ id }/status`, {
			method: 'POST', body: JSON.stringify( { enabled: false } ),
		} );
		t.check( 'disable goes through', 200 === off.status, JSON.stringify( off.body ) );

		const on = await api( `minn-admin/v1/pwgc/gift-cards/${ id }/status`, {
			method: 'POST', body: JSON.stringify( { enabled: true } ),
		} );
		t.check( 'enable goes through', 200 === on.status, JSON.stringify( on.body ) );

		const bal = await api( `minn-admin/v1/pwgc/gift-cards/${ id }/balance`, {
			method: 'POST', body: JSON.stringify( { balance: 7 } ),
		} );
		t.check( 'balance can be set', 200 === bal.status, JSON.stringify( bal.body ) );

		const resend = await api( `minn-admin/v1/pwgc/gift-cards/${ id }/resend`, { method: 'POST' } );
		t.check( 'resend answers 200 when a recipient is stored',
			200 === resend.status, JSON.stringify( resend.body && resend.body.message ) );

		const zero = await api( 'minn-admin/v1/pwgc/gift-cards', {
			method: 'POST', body: JSON.stringify( { amount: 0 } ),
		} );
		t.check( 'a card worth nothing is refused', 400 === zero.status, JSON.stringify( zero.body ) );

		const huge = await api( 'minn-admin/v1/pwgc/gift-cards', {
			method: 'POST', body: JSON.stringify( { amount: '1e20' } ),
		} );
		t.check( 'a scientifically huge amount is refused', 400 === huge.status, JSON.stringify( huge.body ) );

		await page.goto( BASE + '/minn-admin/pw-gift-cards', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-surface-status, .minn-table, .minn-empty', { timeout: 20000 } );
		const statusText = await page.evaluate( () => ( document.querySelector( '.minn-surface-status' ) || {} ).textContent || '' );
		t.check( 'the surface paints an outstanding-balance card',
			/Outstanding balance/i.test( statusText ), statusText.slice( 0, 120 ) );

		const forbidden = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/pwgc/gift-cards' );
			return r.status;
		} );
		t.check( 'the list route refuses an unauthenticated request', 401 === forbidden || 403 === forbidden, String( forbidden ) );
	} catch ( e ) {
		t.check( 'suite ran without throwing', false, String( e && e.message ? e.message : e ) );
	} finally {
		if ( restored ) await toggle( 'pw-gift-cards/pw-gift-cards', false );
		await t.done( browser, errors );
	}
} )();
