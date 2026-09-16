/**
 * Extensions → plugin cards link to where each plugin lives: its Minn views
 * (surfaces via the descriptor `plugin` key, settings sections it provides,
 * its license row) and the "Settings" link it declares to wp-admin, which
 * Minn harvests from the plugin_action_links filters on a hidden plugins.php
 * pageload (minn-admin/v1/plugin-links + ?minn_links=1).
 *
 * Fixtures: Gravity Forms (active, surface `gravity-forms`) and Antispam Bee
 * (active resident spam provider, declares a Settings action link).
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'plugin-doorways' );
	const { browser, page, errors } = await launch();
	await login( page );

	const rest = ( path ) => page.evaluate( async ( p ) => {
		const r = await fetch( window.MINN.restUrl + p, { headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' } );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, path );

	try {
		await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 30000 } );

		/* ===== Route + harvest ===== */
		let r = await rest( 'minn-admin/v1/plugin-links' );
		t.check( 'plugin-links answers with minn + links maps', r.status === 200 && r.body && typeof r.body.minn === 'object' && typeof r.body.links === 'object', `status ${ r.status }` );
		const gf = ( r.body.minn[ 'gravityforms' ] || [] );
		t.check( 'Gravity Forms resolves to its Minn surface', gf.some( ( l ) => l.go === 'gravity-forms' && l.label === 'Forms' ), JSON.stringify( gf ) );
		const bee = ( r.body.minn[ 'antispam-bee' ] || [] );
		t.check( 'Antispam Bee resolves to the Spam settings section', bee.some( ( l ) => l.go === 'settings' && l.section === 'Comments' ), JSON.stringify( bee ) );
		if ( r.body.stale ) {
			const cap = await page.evaluate( async ( u ) => { const x = await fetch( u, { credentials: 'same-origin' } ); return x.json().catch( () => null ); }, r.body.capture );
			t.check( 'hidden plugins.php capture harvests declared links', cap && cap.ok && cap.count > 0, JSON.stringify( cap ) );
			r = await rest( 'minn-admin/v1/plugin-links' );
		} else {
			t.check( 'hidden plugins.php capture harvests declared links', true, 'fresh capture on record' );
		}
		const beeLinks = r.body.links[ 'antispam-bee' ] || [];
		t.check( 'Antispam Bee\'s own Settings link is harvested and classified', beeLinks.some( ( l ) => l.kind === 'settings' && /options-general\.php\?page=antispam_bee/.test( l.href ) ), JSON.stringify( beeLinks ) );
		t.check( 'no core verbs leak into the harvest', ! Object.values( r.body.links ).flat().some( ( l ) => /plugins\.php\?.*action=(de)?activate|action=delete/.test( l.href ) ) );
		t.check( 'not stale after a capture', r.body.stale === false, `stale ${ r.body.stale }` );

		/* ===== Cards ===== */
		await page.goto( BASE + '/minn-admin/extensions', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-plugin', { timeout: 60000 } );
		await page.waitForSelector( '.minn-plugin[data-plugin="antispam-bee/antispam_bee"] .minn-plugin-doors', { timeout: 60000 } );
		const beeCard = await page.evaluate( () => {
			const c = document.querySelector( '.minn-plugin[data-plugin="antispam-bee/antispam_bee"]' );
			return { chips: [ ...c.querySelectorAll( '[data-mdoor]' ) ].map( ( b ) => b.textContent.trim() ), wp: [ ...c.querySelectorAll( '.minn-plugin-door.is-wp' ) ].map( ( a ) => a.textContent.trim() + ' ' + a.href ) };
		} );
		t.check( 'card shows the Minn chip and the wp-admin settings link', beeCard.chips.includes( 'Spam settings' ) && beeCard.wp.some( ( w ) => /^Settings ↗ .*antispam_bee/.test( w ) ), JSON.stringify( beeCard ) );
		const inactive = await page.evaluate( () => [ ...document.querySelectorAll( '.minn-plugin' ) ].filter( ( c ) => ! c.querySelector( '.minn-state-label.on' ) && c.querySelector( '.minn-plugin-doors' ) ).length );
		t.check( 'inactive plugins carry no doorways', inactive === 0, `${ inactive } inactive cards with doors` );

		// Chip → Settings → Spam section.
		await page.click( '.minn-plugin[data-plugin="antispam-bee/antispam_bee"] [data-mdoor]' );
		await page.waitForSelector( '.minn-settings-nav-item.active', { timeout: 30000 } );
		const sec = await page.evaluate( () => ( { path: location.pathname, active: document.querySelector( '.minn-settings-nav-item.active' ).textContent.trim() } ) );
		t.check( 'Spam settings chip lands on the Comments section, where spam lives', /\/settings$/.test( sec.path ) && /Comments/.test( sec.active ), JSON.stringify( sec ) );

		// Chip → a surface route.
		await page.goto( BASE + '/minn-admin/extensions', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-plugin[data-plugin="gravityforms/gravityforms"] [data-mdoor]', { timeout: 60000 } );
		await page.click( '.minn-plugin[data-plugin="gravityforms/gravityforms"] [data-mdoor]' );
		await page.waitForFunction( () => /\/gravity-forms$/.test( location.pathname ), null, { timeout: 30000 } );
		t.check( 'Forms chip opens the Gravity Forms surface', true );

		// Context menu carries the same doorways.
		await page.goto( BASE + '/minn-admin/extensions', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-plugin[data-plugin="gravityforms/gravityforms"] [data-mdoor]', { timeout: 60000 } );
		await page.evaluate( () => {
			const card = document.querySelector( '.minn-plugin[data-plugin="gravityforms/gravityforms"]' );
			const r = card.getBoundingClientRect();
			card.dispatchEvent( new MouseEvent( 'contextmenu', { bubbles: true, cancelable: true, clientX: r.left + 40, clientY: r.top + 20 } ) );
		} );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
		const entries = await page.evaluate( () => [ ...document.querySelectorAll( '.minn-ctx-menu button, .minn-ctx-menu a' ) ].map( ( b ) => b.textContent.trim() ) );
		t.check( 'context menu offers Open Forms in Minn and the plugin\'s own screens', entries.includes( 'Open Forms in Minn' ) && entries.some( ( e ) => /↗$/.test( e ) ), JSON.stringify( entries ) );
		await page.keyboard.press( 'Escape' );
		await page.waitForTimeout( 200 );

		// A plugin with no declared action link still gets its admin-menu page.
		const nf = await page.evaluate( () => {
			const c = document.querySelector( '.minn-plugin[data-plugin="ninja-forms/ninja-forms"]' );
			const a = c && c.querySelector( '.minn-plugin-door.is-wp' );
			return a ? a.textContent.trim() + ' ' + a.href : '';
		} );
		t.check( 'menu-page doorway fills in where no Settings link is declared', /admin\.php\?page=ninja-forms/.test( nf ), nf );

		// Reverse doorway: the surface's topbar chip opens the plugin menu.
		await page.goto( BASE + '/minn-admin/gravity-forms', { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () => document.querySelector( '#minn-sub' ) && document.querySelector( '#minn-sub' ).title, null, { timeout: 60000 } );
		const sub = await page.$( '#minn-sub' );
		const box = await sub.boundingBox();
		if ( await page.$( '#minn-surface-switch' ) ) await page.mouse.click( box.x + 10, box.y + box.height / 2, { button: 'right' } );
		else await page.mouse.click( box.x + 10, box.y + box.height / 2 );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 30000 } );
		const rev = await page.evaluate( () => [ ...document.querySelectorAll( '.minn-ctx-menu button, .minn-ctx-menu a' ) ].map( ( b ) => b.textContent.trim() ) );
		t.check( 'topbar chip menu links back to the plugin', rev.includes( 'Changelog' ) && rev.includes( 'Open plugin card' ) && rev.some( ( e ) => /Settings|Forms/.test( e ) ), JSON.stringify( rev ) );
		await page.keyboard.press( 'Escape' );

		// ⌘K knows the plugin's own screens.
		await page.keyboard.press( process.platform === 'darwin' ? 'Meta+k' : 'Control+k' );
		await page.waitForSelector( '#minn-palette-input', { timeout: 5000 } );
		await page.type( '#minn-palette-input', 'antispam bee' );
		await page.waitForTimeout( 500 );
		const pal = await page.evaluate( () => [ ...document.querySelectorAll( '.minn-palette-item .minn-palette-label' ) ].map( ( n ) => n.textContent.trim() ) );
		t.check( 'palette lists the plugin\'s settings screen', pal.some( ( l ) => /Antispam Bee: Settings ↗/.test( l ) ), JSON.stringify( pal ) );
		await page.keyboard.press( 'Escape' );
	} catch ( e ) {
		t.check( 'suite ran without throwing', false, e.message );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
