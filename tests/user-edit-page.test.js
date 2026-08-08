/**
 * GH #8: full-page user editor at /minn-admin/users/{id}.
 *
 * Admins edit another user's identity AND Minn experience (color scheme,
 * default-admin, toolbar) from one deep-linkable page. The critical
 * property: appearance edits save to the TARGET user's meta and never
 * repaint the admin's own session — proven by a second browser context
 * logging in as the target and seeing the scheme applied.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'user-edit-page' );
	const { browser, page, errors } = await launch();
	await login( page );

	const rest = ( route, opts ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.route, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		}, a.opts || {} ) );
		if ( ! r.ok ) throw new Error( 'HTTP ' + r.status );
		return r.json();
	}, { route, opts } );

	// Target: the minn-editor role-test account.
	const targets = await rest( 'wp/v2/users?search=minn-editor&context=edit' );
	const target = targets.find( ( x ) => x.username === 'minn-editor' ) || targets[ 0 ];
	t.check( 'target user resolved', !! target, JSON.stringify( ( targets || [] ).map( ( x ) => x.username ) ) );
	const uid = target.id;
	const origAppearance = await rest( `minn-admin/v1/users/${ uid }/appearance` );
	const origName = target.name;

	try {
		// Users list row click deep-links to the page.
		await page.goto( BASE + '/minn-admin/users', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-view', { timeout: 15000 } );
		await page.waitForSelector( `[data-user="${ uid }"]`, { timeout: 15000 } );
		await page.click( `[data-user="${ uid }"]` );
		await page.waitForFunction( ( id ) => location.pathname.includes( '/minn-admin/users/' + id ), uid, { timeout: 10000 } )
			.catch( () => {} );
		const deepLinked = await page.evaluate( () => location.pathname );
		t.check( 'users list opens the edit page', deepLinked.includes( '/minn-admin/users/' + uid ), deepLinked );

		// Direct deep link renders all cards.
		await page.goto( `${ BASE }/minn-admin/users/${ uid }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-ue-name', { timeout: 15000 } );
		const cards = await page.evaluate( () => ( {
			name: document.querySelector( '#minn-ue-name' ).value,
			swatches: document.querySelectorAll( '#minn-ue-appearance .minn-scheme-swatch' ).length,
			sessions: !! Array.from( document.querySelectorAll( '.minn-panel-title' ) ).find( ( el ) => /Sessions/.test( el.textContent ) ),
			danger: !! document.querySelector( '#minn-ue-delete' ),
			topTitle: document.querySelector( '#minn-title' ).textContent,
		} ) );
		t.check( 'account card populated', cards.name === origName, cards.name );
		t.check( 'scheme swatches render (9 presets + custom)', cards.swatches === 10, String( cards.swatches ) );
		t.check( 'sessions card renders', cards.sessions );
		t.check( 'danger zone renders', cards.danger );
		t.check( 'topbar says Edit user', /Edit user/.test( cards.topTitle ), cards.topTitle );

		// "← Users" head link returns to the list (the order-page pattern).
		await page.click( '#minn-ue-back' );
		await page.waitForFunction( () => /\/minn-admin\/users\/?$/.test( location.pathname ), null, { timeout: 8000 } );
		t.check( 'back link returns to Users', true );
		await page.goto( `${ BASE }/minn-admin/users/${ uid }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-ue-name', { timeout: 15000 } );

		// Scheme pick saves to the TARGET, not this session.
		const myScheme = await page.evaluate( () => document.documentElement.getAttribute( 'data-scheme' ) );
		await page.click( '#minn-ue-appearance .minn-scheme-swatch[data-scheme="ocean"]' );
		await page.waitForTimeout( 1200 );
		const savedAp = await rest( `minn-admin/v1/users/${ uid }/appearance` );
		t.check( 'target scheme saved as ocean', savedAp.scheme === 'ocean', savedAp.scheme );
		const myAfter = await page.evaluate( () => document.documentElement.getAttribute( 'data-scheme' ) );
		t.check( 'admin session scheme untouched', myAfter === myScheme, `${ myScheme } -> ${ myAfter }` );

		// Toolbar toggle round-trips core's string meta.
		const tbBefore = ( target.meta && target.meta.show_admin_bar_front ) !== 'false';
		await page.click( '#minn-ue-toolbar' );
		await page.waitForTimeout( 1200 );
		const afterMeta = await rest( `wp/v2/users/${ uid }?context=edit&_fields=meta` );
		t.check( 'toolbar meta flipped', ( afterMeta.meta.show_admin_bar_front !== 'false' ) === ! tbBefore, JSON.stringify( afterMeta.meta.show_admin_bar_front ) );
		await page.click( '#minn-ue-toolbar' );
		await page.waitForTimeout( 900 );

		// Display-name save.
		await page.fill( '#minn-ue-name', 'Minn Editor Probe' );
		await page.click( '[data-ue-save]' );
		await page.waitForTimeout( 1800 );
		const renamed = await rest( `wp/v2/users/${ uid }?context=edit&_fields=name,roles` );
		t.check( 'display name saved', renamed.name === 'Minn Editor Probe', renamed.name );
		t.check( 'role untouched by save', ( renamed.roles || [] )[ 0 ] === 'editor', JSON.stringify( renamed.roles ) );

		// The white-label proof: the target sees the scheme on THEIR login.
		const ctx2 = await browser.newContext( { ignoreHTTPSErrors: true, viewport: { width: 1280, height: 800 } } );
		const p2 = await ctx2.newPage();
		await p2.goto( BASE + '/wp-login.php', { waitUntil: 'domcontentloaded' } );
		await p2.fill( '#user_login', 'minn-editor' );
		await p2.fill( '#user_pass', 'minn-editor-pass-1' );
		await Promise.all( [ p2.waitForNavigation( { waitUntil: 'domcontentloaded' } ), p2.click( '#wp-submit' ) ] );
		await p2.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await p2.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 15000 } );
		await p2.waitForTimeout( 800 );
		const theirScheme = await p2.evaluate( () => document.documentElement.getAttribute( 'data-scheme' ) );
		t.check( 'target session wears the admin-set scheme', theirScheme === 'ocean', theirScheme );
		await ctx2.close();
	} finally {
		// Restore everything.
		await rest( `minn-admin/v1/users/${ uid }/appearance`, { method: 'POST', body: JSON.stringify( origAppearance ) } ).catch( () => {} );
		await rest( `wp/v2/users/${ uid }`, { method: 'POST', body: JSON.stringify( { name: origName } ) } ).catch( () => {} );
	}

	await t.done( browser, errors );
} )();
