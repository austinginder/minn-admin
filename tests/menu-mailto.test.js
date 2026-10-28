/**
 * A right-click menu's mail link is a mail link. Menu entries went through
 * navHref, which keeps web addresses and paths only, so a customer's
 * "Email …" entry rendered with an empty href and did nothing. A mailto: or
 * tel: entry now passes as itself and opens in place; a scheme that runs
 * code still does not pass.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'menu-mailto' );
	await login( page );
	await page.goto( BASE + '/minn-admin/customers', { waitUntil: 'domcontentloaded' } );
	const row = await page.waitForSelector( '[data-customer]', { timeout: 30000 } ).catch( () => null );
	if ( ! row ) {
		t.check( 'a customer is listed (skipped when the store has none)', true, 'skipped' );
		await t.done( browser, errors );
		return;
	}
	// A customer with an email address.
	const target = await page.evaluateHandle( () => [ ...document.querySelectorAll( '[data-customer]' ) ].find( ( r ) => /@/.test( r.textContent ) ) || null );
	if ( ! await target.evaluate( ( n ) => !! n ) ) {
		t.check( 'a customer with an email is listed (skipped when none)', true, 'skipped' );
		await t.done( browser, errors );
		return;
	}
	await target.asElement().click( { button: 'right' } );
	await page.waitForTimeout( 400 );
	const link = await page.evaluate( () => {
		const a = [ ...document.querySelectorAll( 'a' ) ].find( ( x ) => /^Email /.test( x.textContent.trim() ) );
		return a ? { href: a.getAttribute( 'href' ), target: a.getAttribute( 'target' ), text: a.textContent.trim() } : null;
	} );
	t.check( "the customer's Email entry is a mailto: link", !! link && /^mailto:[^@\s]+@/.test( link.href || '' ), JSON.stringify( link ) );
	t.check( 'and opens in place, not in an empty tab', !! link && ! link.target, JSON.stringify( link ) );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
