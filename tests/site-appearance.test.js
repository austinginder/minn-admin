/**
 * The site's default appearance (Settings → Appearance, option
 * minn_admin_site_appearance): a palette everyone whose scheme is 'site'
 * follows (the default for anyone who never picked), and the light/dark mode
 * a device starts in until its person picks. Admins edit it; a person can
 * pick their own palette and go back to the site default from Your profile;
 * "Move them to the site default" resets everyone who picked their own while
 * keeping their other preferences.
 */
const { BASE, launch, login, loginAs, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'site-appearance' );
	await login( page );

	const rest = ( pg, path, opts ) => pg.evaluate( async ( [ p, o ] ) => {
		const r = await fetch( window.MINN.restUrl + p, Object.assign( {
			headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
		}, o || {} ) );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, [ path, opts ] );

	const original = ( await rest( page, 'minn-admin/v1/site-appearance' ) ).body;
	const myOriginal = ( await rest( page, 'minn-admin/v1/me/appearance' ) ).body;
	t.check( 'admins can read the site default', !! ( original && original.appearance && original.appearance.scheme ), JSON.stringify( original ) );

	// A fresh account: never picked a palette.
	const login_ = 'minn-siteap-' + Date.now();
	const pass = 'minn-siteap-pass-' + Math.random().toString( 36 ).slice( 2 );
	const made = await rest( page, 'wp/v2/users', {
		method: 'POST',
		body: JSON.stringify( { username: login_, email: login_ + '@example.org', password: pass, roles: [ 'editor' ] } ),
	} );
	const uid = made.body && made.body.id;
	t.check( 'test account created', !! uid, JSON.stringify( made.body && made.body.code ) );

	let other = null;
	try {
		// Settings → Appearance edits the site default.
		await page.goto( `${ BASE }/minn-admin/settings`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-settings-nav-item[data-section="Appearance"]', { timeout: 20000 } );
		await page.click( '.minn-settings-nav-item[data-section="Appearance"]' );
		await page.waitForSelector( '#minn-site-appearance', { timeout: 10000 } );
		t.check( 'the site editor offers no Site default swatch of its own', ! ( await page.$( '#minn-site-appearance [data-scheme="site"]' ) ) );
		t.check( 'starting mode offers System, Light and Dark', ( await page.$$eval( '#minn-site-appearance [data-site-mode]', ( els ) => els.map( ( e ) => e.dataset.siteMode ).join( ',' ) ) ) === 'system,light,dark' );
		await page.click( '#minn-site-appearance .minn-scheme-swatch[data-scheme="rose"]' );
		await page.click( '#minn-site-appearance [data-site-mode="dark"]' );
		await page.click( '#minn-save-site-appearance' );
		let saved = null;
		for ( let i = 0; i < 20; i++ ) {
			await page.waitForTimeout( 400 );
			saved = ( await rest( page, 'minn-admin/v1/site-appearance' ) ).body;
			if ( saved && saved.appearance.scheme === 'rose' ) break;
		}
		t.check( 'saving stores the palette and starting mode', saved && saved.appearance.scheme === 'rose' && saved.appearance.mode === 'dark', JSON.stringify( saved && saved.appearance && { s: saved.appearance.scheme, m: saved.appearance.mode } ) );

		// The fresh account follows it.
		other = await loginAs( browser, login_, pass );
		const op = other.page;
		const boot = await op.evaluate( () => ( {
			scheme: window.MINN.user.appearance.scheme,
			site: window.MINN.siteAppearance && window.MINN.siteAppearance.scheme,
			painted: document.documentElement.getAttribute( 'data-scheme' ),
			theme: document.documentElement.getAttribute( 'data-theme' ),
			stored: localStorage.getItem( 'minn-theme' ),
		} ) );
		t.check( 'a new account follows the site default', boot.scheme === 'site' && boot.site === 'rose' && boot.painted === 'rose', JSON.stringify( boot ) );
		t.check( 'a device with no pick starts in the site mode, saving nothing', boot.theme === 'dark' && ! boot.stored, JSON.stringify( boot ) );
		t.check( 'editors cannot change the site default', ( await rest( op, 'minn-admin/v1/site-appearance', { method: 'POST', body: JSON.stringify( { scheme: 'teal' } ) } ) ).status === 403 );

		// Your profile: the site default is a choice, first in the row.
		await op.goto( `${ BASE }/minn-admin/profile`, { waitUntil: 'domcontentloaded' } );
		await op.waitForSelector( '.minn-scheme-swatch[data-scheme="site"]', { timeout: 20000 } );
		t.check( 'the profile shows Site default selected', await op.$eval( '.minn-scheme-swatch[data-scheme="site"]', ( el ) => el.classList.contains( 'sel' ) ) );
		await op.click( '.minn-scheme-swatch[data-scheme="teal"]' );
		let mine = null;
		for ( let i = 0; i < 20; i++ ) {
			await op.waitForTimeout( 300 );
			mine = ( await rest( op, 'minn-admin/v1/me/appearance' ) ).body;
			if ( mine && mine.scheme === 'teal' ) break;
		}
		t.check( 'picking a scheme makes it their own', mine && mine.scheme === 'teal' && await op.evaluate( () => document.documentElement.getAttribute( 'data-scheme' ) ) === 'teal' );
		const count = ( await rest( page, 'minn-admin/v1/site-appearance' ) ).body;
		t.check( 'the site default counts people with their own palette', count && count.ownPalette >= 1, JSON.stringify( count && count.ownPalette ) );
		await op.click( '.minn-scheme-swatch[data-scheme="site"]' );
		for ( let i = 0; i < 20; i++ ) {
			await op.waitForTimeout( 300 );
			mine = ( await rest( op, 'minn-admin/v1/me/appearance' ) ).body;
			if ( mine && mine.scheme === 'site' ) break;
		}
		t.check( 'Site default goes back to following the site', mine && mine.scheme === 'site' && await op.evaluate( () => document.documentElement.getAttribute( 'data-scheme' ) ) === 'rose' );

		// Move everyone back: their other preferences stay.
		await rest( op, 'minn-admin/v1/me/appearance', { method: 'POST', body: JSON.stringify( { scheme: 'amber', font: 'wordpress', frontBar: true } ) } );
		await page.goto( `${ BASE }/minn-admin/settings`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-settings-nav-item[data-section="Appearance"]', { timeout: 20000 } );
		await page.click( '.minn-settings-nav-item[data-section="Appearance"]' );
		await page.waitForSelector( '[data-site-ap-reset]', { timeout: 10000 } );
		await page.click( '[data-site-ap-reset]' );
		await page.waitForSelector( '.minn-confirm-modal [data-ok]', { timeout: 5000 } );
		await page.click( '.minn-confirm-modal [data-ok]' );
		let theirs = null;
		for ( let i = 0; i < 20; i++ ) {
			await page.waitForTimeout( 400 );
			theirs = ( await rest( page, `minn-admin/v1/users/${ uid }/appearance` ) ).body;
			if ( theirs && theirs.scheme === 'site' ) break;
		}
		t.check( 'moving everyone resets their palette', theirs && theirs.scheme === 'site', JSON.stringify( theirs && theirs.scheme ) );
		t.check( 'and keeps their other preferences', theirs && theirs.font === 'wordpress' && theirs.frontBar === true, JSON.stringify( theirs && { f: theirs.font, b: theirs.frontBar } ) );
		t.check( 'anonymous requests are refused', ( await page.evaluate( async ( u ) => ( await fetch( u + 'minn-admin/v1/site-appearance', { credentials: 'omit' } ) ).status, await page.evaluate( () => window.MINN.restUrl ) ) ) === 401 );
	} finally {
		if ( other ) await other.ctx.close().catch( () => {} );
		if ( original && original.appearance ) {
			await rest( page, 'minn-admin/v1/site-appearance', { method: 'POST', body: JSON.stringify( original.appearance ) } );
		}
		if ( myOriginal ) await rest( page, 'minn-admin/v1/me/appearance', { method: 'POST', body: JSON.stringify( myOriginal ) } );
		if ( uid ) await rest( page, `wp/v2/users/${ uid }?force=true&reassign=1`, { method: 'DELETE' } );
	}

	await t.done( browser, errors );
} )();
