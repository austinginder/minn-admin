/**
 * External-link honesty (v1.0 gate G4).
 *
 * Every plugin-supplied href that leaves the site renders with the ↗
 * affordance and opens in a new tab, so a descriptor cannot make an off-site
 * link look like an app action. Same-host links (wp-admin deep links) stay
 * unmarked. The minn-dev-fixtures mu-plugin arms a surface behind the
 * REST-exposed minn_test_offsite_surface option carrying off-site AND
 * same-site hrefs on collection actions and on its status card; the
 * Integrations card must flag the descriptor's off-site hrefs informationally
 * (`offsite`, class .minn-sys-int-offsite — never a contract problem).
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'offsite-links' );
	const { browser, page, errors } = await launch();
	await page.context().grantPermissions( [ 'clipboard-read', 'clipboard-write' ] );
	await login( page );

	// Write-then-verify with retries (REST settings writes can race the
	// app's parallel boot requests — site-kit suite rule).
	const setOpt = async ( v ) => {
		for ( let attempt = 1; attempt <= 5; attempt++ ) {
			const stored = await page.evaluate( async ( val ) => {
				const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST', headers: h, credentials: 'same-origin',
					body: JSON.stringify( { minn_test_offsite_surface: val } ),
				} );
				const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), {
					headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} );
				return ( await r.json() ).minn_test_offsite_surface;
			}, v );
			if ( stored === v ) return true;
			await page.waitForTimeout( 800 );
		}
		return false;
	};

	const linkInfo = ( els ) => els.map( ( a ) => ( {
		text: a.textContent.trim(),
		href: a.getAttribute( 'href' ) || '',
		target: a.getAttribute( 'target' ) || '',
	} ) );

	try {
		if ( ! await setOpt( true ) ) throw new Error( 'could not enable minn_test_offsite_surface' );

		/* ===== Status card actions ===== */
		await page.goto( BASE + '/minn-admin/minn-offsite-fixture', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-surface-status .minn-sstat-actions a', { timeout: 20000 } );
		const stat = await page.$$eval( '.minn-sstat-actions a', linkInfo );
		const statUp = stat.find( ( a ) => a.href.includes( 'example.com' ) );
		const statSame = stat.find( ( a ) => ! a.href.includes( 'example.com' ) );
		t.check( 'status card off-site action carries ↗', !! statUp && /↗$/.test( statUp.text ), JSON.stringify( stat ) );
		t.check( 'status card off-site action opens a new tab', !! statUp && statUp.target === '_blank' );
		t.check( 'status card same-site action stays unmarked', !! statSame && ! /↗/.test( statSame.text ), JSON.stringify( statSame ) );

		/* ===== Row ⋯ menu ===== */
		await page.waitForSelector( '[data-sitem] .minn-row-more' );
		await page.click( '[data-sitem] .minn-row-more' );
		await page.waitForSelector( '.minn-ctx-menu a', { timeout: 10000 } );
		const menu = await page.$$eval( '.minn-ctx-menu a', linkInfo );
		const menuUp = menu.find( ( a ) => a.href.includes( 'example.com' ) );
		const menuSame = menu.find( ( a ) => ! a.href.includes( 'example.com' ) );
		t.check( 'row menu off-site link carries ↗', !! menuUp && /↗$/.test( menuUp.text ), JSON.stringify( menu ) );
		t.check( 'row menu off-site href filled the {id} placeholder', !! menuUp && menuUp.href.includes( 'item=1' ), menuUp && menuUp.href );
		t.check( 'row menu same-site link stays unmarked', !! menuSame && ! /↗/.test( menuSame.text ), JSON.stringify( menuSame ) );
		t.check( 'row menu links open a new tab', !! menuUp && menuUp.target === '_blank' && !! menuSame && menuSame.target === '_blank' );
		await page.mouse.click( 4, 4 ); // dismiss the menu
		await page.waitForTimeout( 200 );

		/* ===== Detail modal actions (raw-item modal, empty detail array) ===== */
		await page.$eval( '[data-sitem]', ( el ) => el.click() );
		await page.waitForSelector( '.minn-modal .minn-modal-actions a', { timeout: 10000 } );
		const modal = await page.$$eval( '.minn-modal-actions a', linkInfo );
		const modalUp = modal.find( ( a ) => a.href.includes( 'example.com' ) );
		const modalSame = modal.find( ( a ) => ! a.href.includes( 'example.com' ) );
		t.check( 'detail modal off-site action carries ↗', !! modalUp && /↗$/.test( modalUp.text ), JSON.stringify( modal ) );
		t.check( 'detail modal same-site action stays unmarked', !! modalSame && ! /↗/.test( modalSame.text ) );
		await page.keyboard.press( 'Escape' );

		/* ===== Integrations card flags the descriptor, informationally ===== */
		await page.goto( BASE + '/minn-admin/system', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-sys-integrations', { timeout: 20000 } );
		const off = await page.$$eval( '.minn-sys-int-offsite', ( els ) => els.map( ( e ) => e.textContent ) );
		t.check( 'Integrations card flags the off-site descriptor href',
			off.some( ( p ) => p.includes( 'Vendor docs' ) && p.includes( 'example.com' ) ), off.join( ' | ' ) );
		t.check( 'same-site descriptor href is not flagged', ! off.some( ( p ) => p.includes( 'Open dashboard' ) ) );
		const probs = await page.$$eval( '.minn-sys-int-problem', ( els ) => els.length );
		t.check( 'off-site flag is informational, not a contract problem', probs === 0, String( probs ) );

		/* ===== Copy report carries the flag ===== */
		await page.click( '#minn-sys-copy' );
		await page.waitForTimeout( 400 );
		const clip = await page.evaluate( () => navigator.clipboard.readText() );
		t.check( 'copy report lists OFF-SITE LINKS', /OFF-SITE LINKS:[^\n]*example\.com/.test( clip ) );

		/* ===== Cleanup restores a flag-free registry ===== */
		await setOpt( false );
		await page.goto( BASE + '/minn-admin/system', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-sys-integrations', { timeout: 20000 } );
		const after = await page.$$eval( '.minn-sys-int-offsite', ( els ) => els.length );
		t.check( 'registry flag-free again after disabling the fixture', after === 0, String( after ) );
	} finally {
		await setOpt( false );
	}

	await t.done( browser, errors );
} )();
