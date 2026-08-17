/**
 * Sidebar nav groups (Workspace / Commerce / Tools / Manage).
 *
 * Publishing and inboxes stay in Workspace; store operations use the
 * conditional Commerce group in stable workflow order. Site plumbing
 * families (Email Log, Activity Log, Snippets, Redirects, Backups and any
 * future surface without a group claim) land in Tools; Extensions joins
 * Manage. Group labels collapse/expand with localStorage persistence, and
 * Tools rows keep their icons since they stay ordinary nav buttons.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'nav-groups' );
	const { browser, page, errors } = await launch();
	await login( page );

	try {
		// The Network group's wrapper is always in the markup (an empty group
		// keeps a hidden home so a mid-session change has somewhere to land),
		// but off multisite it must never be VISIBLE. The dev box has commerce
		// fixtures, so its four site-level groups show.
		const labels = await page.$$eval( 'button.minn-nav-label', ( els ) =>
			els.filter( ( e ) => e.offsetParent !== null ).map( ( e ) => e.dataset.navgroup ) );
		t.check( 'four collapsible group labels render (network hidden off multisite)',
			JSON.stringify( labels ) === JSON.stringify( [ 'workspace', 'commerce', 'tools', 'manage' ] ), labels.join( ', ' ) );

		const groupNavs = ( key ) => page.$$eval( `#minn-nav-${ key } .minn-nav-btn`, ( els ) =>
			els.map( ( e ) => e.dataset.nav + ( e.dataset.family ? ':' + e.dataset.family : '' ) ) );
		const ws = await groupNavs( 'workspace' );
		t.check( 'workspace holds the act-on-it set incl. Forms',
			ws.some( ( x ) => x.startsWith( 'overview' ) ) && ws.some( ( x ) => x.includes( ':forms' ) ), ws.join( ', ' ) );
		t.check( 'workspace has no Extensions and no plumbing',
			! ws.some( ( x ) => x.startsWith( 'extensions' ) ) && ! ws.some( ( x ) => x.includes( ':mail' ) ) );
		t.check( 'workspace has no store operations',
			[ 'orders', 'subscriptions', 'products', 'coupons', 'customers' ].every( ( id ) => ! ws.some( ( x ) => x.startsWith( id ) ) )
				&& ! ws.some( ( x ) => x.includes( ':bookings' ) ), ws.join( ', ' ) );
		const commerce = await groupNavs( 'commerce' );
		const commerceIds = commerce.map( ( x ) => x.split( ':' )[ 0 ] );
		const positions = [
			commerceIds.indexOf( 'orders' ),
			commerceIds.indexOf( 'subscriptions' ),
			commerce.findIndex( ( x ) => x.includes( ':bookings' ) ),
			commerceIds.indexOf( 'customers' ),
			commerceIds.indexOf( 'products' ),
			commerceIds.indexOf( 'coupons' ),
		];
		t.check( 'commerce follows the operational workflow order',
			positions.every( ( p ) => p >= 0 ) && positions.every( ( p, i ) => i === 0 || p > positions[ i - 1 ] ), commerce.join( ', ' ) );
		const tools = await groupNavs( 'tools' );
		t.check( 'tools holds the plumbing families',
			[ ':mail', ':activity-log', ':snippets', ':redirects', ':backups' ].every( ( f ) => tools.some( ( x ) => x.includes( f ) ) ),
			tools.join( ', ' ) );
		const manage = await groupNavs( 'manage' );
		t.check( 'Extensions moved to Manage', manage.some( ( x ) => x.startsWith( 'extensions' ) ), manage.join( ', ' ) );

		const iconCount = await page.$$eval( '#minn-nav-tools .minn-nav-btn svg', ( els ) => els.length );
		t.check( 'tools rows keep their icons', iconCount === tools.length, `${ iconCount }/${ tools.length }` );
		const commerceIconCount = await page.$$eval( '#minn-nav-commerce .minn-nav-btn svg', ( els ) => els.length );
		t.check( 'commerce rows keep their icons', commerceIconCount === commerce.length, `${ commerceIconCount }/${ commerce.length }` );

		/* ===== Collapse persists ===== */
		await page.click( 'button.minn-nav-label[data-navgroup="tools"]' );
		t.check( 'tools collapses on label click', await page.$eval( '#minn-nav-tools', ( el ) => el.hidden ) );
		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-nav-tools', { state: 'attached', timeout: 20000 } );
		t.check( 'collapse persists across reload', await page.$eval( '#minn-nav-tools', ( el ) => el.hidden ) );
		await page.click( 'button.minn-nav-label[data-navgroup="tools"]' );
		t.check( 'expands again', ! await page.$eval( '#minn-nav-tools', ( el ) => el.hidden ) );

		/* ===== Tools surfaces still route ===== */
		await page.$$eval( '#minn-nav-tools .minn-nav-btn', ( els ) => {
			const b = els.find( ( e ) => e.dataset.family === 'backups' );
			if ( b ) b.click();
		} );
		await page.waitForFunction( () =>
			/Backups/.test( ( document.querySelector( '#minn-title' ) || {} ).textContent || '' ), null, { timeout: 15000 } );
		t.check( 'tools surface routes with active highlight', await page.$$eval( '#minn-nav-tools .minn-nav-btn', ( els ) =>
			els.some( ( e ) => e.dataset.family === 'backups' && e.classList.contains( 'active' ) ) ) );
	} finally {
		await page.evaluate( () => localStorage.removeItem( 'minn-nav-collapsed' ) ).catch( () => {} );
	}

	await t.done( browser, errors );
} )();
