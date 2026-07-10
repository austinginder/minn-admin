/**
 * Email Log family — the FluentSMTP shim surface (the active mail provider
 * on the dev site; Gravity SMTP, WP Mail SMTP and Post SMTP share the
 * contract and the 'mail' family). List, tabs, detail and Resend, riding
 * the seeded Mailpit-delivered fixtures.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'mail-log' );

	await login( page );

	// --- REST shim ----------------------------------------------------------
	const list = await page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/fluent-smtp/emails', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return await r.json();
	} );
	t.check( 'Shim returns {items,total}', Array.isArray( list.items ) && typeof list.total === 'number' );
	t.check( 'Seeded emails present', list.total >= 2, `total=${ list.total }` );
	const first = list.items[ 0 ];
	t.check(
		'Rows carry subject/to/status/date',
		!! first && !! first.subject && first.to.includes( '@' ) && !! first.status && !! first.created_at,
		JSON.stringify( first )
	);

	const detail = await page.evaluate( async ( id ) => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/fluent-smtp/emails/' + id, {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return await r.json();
	}, first.id );
	t.check( 'Detail carries the message body', !! detail.message && detail.message.length > 5 );
	t.check( 'Recipients extracted without unserializing', detail.to.includes( '@' ) && ! detail.to.includes( 'a:1:' ) );

	// --- Surface UI -----------------------------------------------------------
	await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN && document.querySelector( '.minn-sidebar' ), null, { timeout: 15000 } );
	const navLabel = await page.evaluate( () => {
		const btn = Array.from( document.querySelectorAll( '.minn-nav-btn' ) )
			.find( ( b ) => b.textContent.includes( 'Email Log' ) );
		return btn ? btn.textContent.trim() : '';
	} );
	t.check( 'Email Log appears in the nav', navLabel.includes( 'Email Log' ), navLabel );
	await page.evaluate( () => {
		Array.from( document.querySelectorAll( '.minn-nav-btn' ) )
			.find( ( b ) => b.textContent.includes( 'Email Log' ) ).click();
	} );
	await page.waitForFunction(
		() => document.body.textContent.includes( 'Minn mail test' ),
		null, { timeout: 15000 }
	);
	t.check( 'List renders the seeded emails', true );

	// Detail opens with the message body.
	await page.evaluate( () => {
		const row = Array.from( document.querySelectorAll( '.minn-table-row, [data-id]' ) )
			.find( ( r ) => r.textContent.includes( 'Minn mail test (HTML)' ) );
		row.click();
	} );
	await page.waitForFunction(
		() => /Hello .*HTML.* from the seed|Resend/.test( document.body.textContent ),
		null, { timeout: 15000 }
	);
	t.check( 'Detail view shows the email', true );

	// --- Resend (real send through FluentSMTP → Mailpit) ---------------------
	const before = list.total;
	const resendBtn = await page.evaluate( () => {
		const b = Array.from( document.querySelectorAll( 'button' ) ).find( ( x ) => x.textContent.trim() === 'Resend' );
		return !! b;
	} );
	t.check( 'Resend action offered', resendBtn );
	page.once( 'dialog', ( d ) => d.accept() );
	await page.evaluate( () => {
		Array.from( document.querySelectorAll( 'button' ) ).find( ( x ) => x.textContent.trim() === 'Resend' ).click();
	} );
	await page.waitForFunction(
		() => Array.from( document.querySelectorAll( '.minn-toast' ) ).some( ( x ) => /Resend — done|resent/i.test( x.textContent ) ),
		null, { timeout: 20000 }
	);
	const after = await page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/fluent-smtp/emails', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return ( await r.json() ).total;
	} );
	t.check( 'Resend logged a new sent email', after === before + 1, `before=${ before } after=${ after }` );

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
