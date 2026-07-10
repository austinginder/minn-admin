/**
 * Sidebar nav groups (Workspace / Tools / Manage).
 *
 * The act-on-it set stays in Workspace (Overview, Content, Media, Orders,
 * Forms via the surface `group: "workspace"` opt-in); site plumbing
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
		const labels = await page.$$eval( 'button.minn-nav-label', ( els ) =>
			els.map( ( e ) => e.dataset.navgroup ) );
		t.check( 'three collapsible group labels render',
			JSON.stringify( labels ) === JSON.stringify( [ 'workspace', 'tools', 'manage' ] ), labels.join( ', ' ) );

		const groupNavs = ( key ) => page.$$eval( `#minn-nav-${ key } .minn-nav-btn`, ( els ) =>
			els.map( ( e ) => e.dataset.nav + ( e.dataset.family ? ':' + e.dataset.family : '' ) ) );
		const ws = await groupNavs( 'workspace' );
		t.check( 'workspace holds the act-on-it set incl. Forms',
			ws.some( ( x ) => x.startsWith( 'overview' ) ) && ws.some( ( x ) => x.includes( ':forms' ) ), ws.join( ', ' ) );
		t.check( 'workspace has no Extensions and no plumbing',
			! ws.some( ( x ) => x.startsWith( 'extensions' ) ) && ! ws.some( ( x ) => x.includes( ':mail' ) ) );
		const tools = await groupNavs( 'tools' );
		t.check( 'tools holds the plumbing families',
			[ ':mail', ':activity-log', ':snippets', ':redirects', ':backups' ].every( ( f ) => tools.some( ( x ) => x.includes( f ) ) ),
			tools.join( ', ' ) );
		const manage = await groupNavs( 'manage' );
		t.check( 'Extensions moved to Manage', manage.some( ( x ) => x.startsWith( 'extensions' ) ), manage.join( ', ' ) );

		const iconCount = await page.$$eval( '#minn-nav-tools .minn-nav-btn svg', ( els ) => els.length );
		t.check( 'tools rows keep their icons', iconCount === tools.length, `${ iconCount }/${ tools.length }` );

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
