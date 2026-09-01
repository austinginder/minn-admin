/**
 * Design → Styles (/minn-admin/styles): the theme's style variations as
 * swatch cards, applied with a real Undo.
 *
 * The load-bearing checks go through to the FRONT END: applying Midnight must
 * change the site's rendered base color for a logged-out visitor, Undo must
 * put the previous config back verbatim, and the picker must list the
 * Browse-styles set (top-level variations only — never the color/typography
 * partials that duplicate titles).
 *
 * Activates twentytwentyfive for the run; snapshots and restores the user
 * global-styles config and the previous theme in finally.
 */
const { launch, login, reporter, BASE, autoConfirm } = require( './helpers' );

( async () => {
	const t = reporter( 'styles' );
	const { browser, page, errors } = await launch();
	await login( page );
	await autoConfirm( page );

	const rest = ( path, opts = {} ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, {
			method: a.method || 'GET',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			...( a.body ? { body: JSON.stringify( a.body ) } : {} ),
		} );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, { path, method: opts.method, body: opts.body } );
	// The site as a visitor sees it — cache-busted, no cookies.
	const frontBaseColor = () => page.evaluate( async () => {
		const r = await fetch( '/?minnstyleprobe=' + Date.now(), { credentials: 'omit' } );
		const m = ( await r.text() ).match( /--wp--preset--color--base:\s*([^;]+);/ );
		return m ? m[ 1 ].trim() : 'none';
	} );

	const open = async () => {
		await page.goto( BASE + '/minn-admin/styles', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-style], .minn-empty', { timeout: 20000 } );
		await page.waitForTimeout( 300 );
	};
	const cards = () => page.evaluate( () => [ ...document.querySelectorAll( '[data-style]' ) ].map( ( c ) => ( {
		id: c.dataset.style,
		title: c.querySelector( '.minn-style-name' ).textContent.trim(),
		active: c.classList.contains( 'is-active' ),
		swatches: c.querySelectorAll( '.minn-style-swatches span' ).length,
	} ) ) );

	let prevTheme = '';
	let gsId = 0;
	let snapshot = null;

	try {
		prevTheme = ( await rest( 'wp/v2/themes?status=active&_fields=stylesheet' ) ).body[ 0 ].stylesheet;

		/* ===== Classic theme explains itself ===== */
		await page.goto( BASE + '/minn-admin/styles', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-empty', { timeout: 20000 } );
		t.check( 'a classic theme explains that variations do not apply',
			await page.evaluate( () => /classic theme/i.test( document.querySelector( '#minn-view' ).textContent ) ) );

		await rest( 'minn-admin/v1/themes/activate', { method: 'POST', body: { stylesheet: 'twentytwentyfive' } } );

		// Snapshot the user config so finally can put everything back.
		const meta = ( await rest( 'minn-admin/v1/styles/variations' ) ).body;
		gsId = meta.userStylesId;
		snapshot = meta.current;
		t.check( 'the endpoint names the user global-styles post', gsId > 0, String( gsId ) );

		/* ===== The Browse-styles set, deduped ===== */
		await open();
		const list = await cards();
		const titles = list.map( ( c ) => c.title.replace( /Active$/, '' ).trim() );
		t.check( 'the Design item hosts a Styles tab',
			await page.evaluate( () => !! document.querySelector( '[data-designtab="styles"].active' )
				&& !! document.querySelector( '.minn-nav-btn[data-nav="templates"].active' ) ) );
		t.check( 'Twenty Twenty-Five lists its eight variations plus Default',
			list.length === 9, `${ list.length } cards` );
		t.check( 'no duplicate titles leak in from the partial variations',
			new Set( titles ).size === titles.length, JSON.stringify( titles ) );
		t.check( 'no typography preset is offered as a whole site style',
			! titles.some( ( x ) => /&/.test( x ) ), JSON.stringify( titles ) );
		t.check( 'cards carry swatches', list.every( ( c ) => c.swatches > 0 ) );
		t.check( 'Default is first and marked active on an uncustomized site',
			list[ 0 ].id === 'default' && list[ 0 ].active, JSON.stringify( list[ 0 ] ) );

		/* ===== Apply changes the real site ===== */
		const before = await frontBaseColor();
		const midnight = list.find( ( c ) => /Midnight/.test( c.title ) );
		t.check( 'Midnight is one of the offered styles', !! midnight );
		await page.evaluate( ( id ) => document.querySelector( `[data-style="${ CSS.escape( id ) }"]` ).click(), midnight.id );
		await page.waitForFunction( () =>
			[ ...document.querySelectorAll( '[data-style].is-active' ) ].some( ( c ) => /Midnight/.test( c.textContent ) ),
		null, { timeout: 20000 } );
		const after = await frontBaseColor();
		t.check( 'the front end changed for visitors', after !== before && after !== 'none', `${ before } → ${ after }` );
		t.check( 'the applied card is marked active and Default no longer is',
			( await cards() ).filter( ( c ) => c.active ).length === 1 );

		/* ===== Undo restores the previous look ===== */
		await page.evaluate( () => {
			const b = document.querySelector( '.minn-toast button, [data-toast-action]' );
			if ( b ) b.click();
		} );
		await page.waitForFunction( () => {
			const first = document.querySelector( '[data-style="default"]' );
			return first && first.classList.contains( 'is-active' );
		}, null, { timeout: 20000 } );
		const undone = await frontBaseColor();
		t.check( 'Undo puts the visitor-facing site back', undone === before, `${ undone } vs ${ before }` );
		const cfg = ( await rest( `wp/v2/global-styles/${ gsId }?context=edit&_fields=settings,styles` ) ).body;
		t.check( 'Undo restored the stored config, not an approximation',
			JSON.stringify( cfg.settings || {} ) === JSON.stringify( snapshot.settings || {} )
			&& JSON.stringify( cfg.styles || {} ) === JSON.stringify( snapshot.styles || {} ) );
	} catch ( e ) {
		t.check( 'suite ran without throwing', false, e.message );
	} finally {
		if ( gsId && snapshot ) {
			await rest( `wp/v2/global-styles/${ gsId }`, { method: 'POST', body: snapshot } ).catch( () => {} );
		}
		if ( prevTheme ) await rest( 'minn-admin/v1/themes/activate', { method: 'POST', body: { stylesheet: prevTheme } } ).catch( () => {} );
	}
	await t.done( browser, errors );
} )();
