/**
 * Widget drag handles: the grip reorders within a sidebar (drop above/below
 * the target's midpoint). Order persists through the sidebar shape save.
 * The fixture sidebar is restored at the end.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'widget-drag' );
	await login( page );

	await page.goto( `${ BASE }/minn-admin/widgets`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-widget-area[data-sidebar] .minn-widget-row[data-widget]', { timeout: 15000 } );

	// Prefer a multi-widget sidebar (dev fixtures: minn-dev-sidebar / footer).
	const sidebarId = await page.evaluate( () => {
		const areas = [ ...document.querySelectorAll( '.minn-widget-area[data-sidebar]' ) ];
		const hit = areas.find( ( a ) => a.querySelectorAll( '.minn-widget-row[data-widget]' ).length >= 2 );
		return hit ? hit.dataset.sidebar : null;
	} );
	if ( ! sidebarId ) {
		t.check( 'a sidebar with ≥2 widgets is available (skipped when absent)', true, 'skipped' );
		await t.done( browser, errors );
		return;
	}

	const sel = `.minn-widget-area[data-sidebar="${ sidebarId }"]`;
	const order = async () => page.$$eval(
		`${ sel } .minn-widget-row[data-widget]`,
		( els ) => els.map( ( e ) => e.dataset.widget )
	);
	const before = await order();
	t.check( 'sidebar has drag grips on multi-widget rows', ( await page.$$( `${ sel } .minn-menu-grip` ) ).length === before.length && before.length >= 2, JSON.stringify( before ) );

	/* ===== Drag the first widget below the last ===== */
	// Playwright's dragTo fires real HTML5 drag events (plain mouse down/move/up
	// does not start a draggable grip drag). Drop toward the bottom of the last
	// row so the "below midpoint" branch runs.
	const grips = page.locator( `${ sel } .minn-menu-grip` );
	const rows = page.locator( `${ sel } .minn-widget-row[data-widget]` );
	const lastCount = before.length;
	const lastBox = await rows.nth( lastCount - 1 ).boundingBox();
	// Drop near the bottom so the "below midpoint" branch runs (center is ambiguous).
	await grips.nth( 0 ).dragTo( rows.nth( lastCount - 1 ), { targetPosition: { x: 50, y: Math.max( 8, lastBox.height - 3 ) } } );
	await page.waitForFunction( ( { s, first } ) => {
		const ids = [ ...document.querySelectorAll( `.minn-widget-area[data-sidebar="${ s }"] .minn-widget-row[data-widget]` ) ].map( ( e ) => e.dataset.widget );
		return ids.length && ids[ ids.length - 1 ] === first;
	}, { s: sidebarId, first: before[ 0 ] }, { timeout: 10000 } );
	const after = await order();
	t.check( 'dragged widget lands after the drop target', after[ after.length - 1 ] === before[ 0 ] && after[ 0 ] === before[ 1 ], JSON.stringify( after ) );

	/* ===== Persists across a reload ===== */
	await page.reload( { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( `${ sel } .minn-widget-row[data-widget]`, { timeout: 15000 } );
	const reloaded = await order();
	t.check( 'new order persists', reloaded[ reloaded.length - 1 ] === before[ 0 ], JSON.stringify( reloaded ) );

	/* ===== Restore the fixture: drag last back above the first ===== */
	const grips2 = page.locator( `${ sel } .minn-menu-grip` );
	const rows2 = page.locator( `${ sel } .minn-widget-row[data-widget]` );
	const firstBox = await rows2.nth( 0 ).boundingBox();
	await grips2.nth( lastCount - 1 ).dragTo( rows2.nth( 0 ), { targetPosition: { x: 50, y: Math.min( 6, firstBox.height * 0.15 ) } } );
	await page.waitForFunction( ( { s, first } ) => {
		const ids = [ ...document.querySelectorAll( `.minn-widget-area[data-sidebar="${ s }"] .minn-widget-row[data-widget]` ) ].map( ( e ) => e.dataset.widget );
		return ids.length && ids[ 0 ] === first;
	}, { s: sidebarId, first: before[ 0 ] }, { timeout: 10000 } );
	t.check( 'fixture restored to original order', JSON.stringify( await order() ) === JSON.stringify( before ), JSON.stringify( await order() ) );

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
