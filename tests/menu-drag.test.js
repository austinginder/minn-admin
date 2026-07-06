/**
 * Menu drag handles: the grip drags a row; dropping on another row makes the
 * dragged item that row's sibling above/below its midpoint. Order persists
 * through the shape save. The fixture menu is restored at the end.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'menu-drag' );
	await login( page );

	await page.goto( `${ BASE }/minn-admin/menus`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-menu-row[data-mi]', { timeout: 15000 } );

	const order = () => page.$$eval( '.minn-menu-row[data-mi] .minn-row-title', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
	const before = await order();
	t.check( 'menu rows carry drag grips', ( await page.$$( '.minn-menu-grip' ) ).length === before.length && before.length >= 2, JSON.stringify( before ) );

	/* ===== Drag the first item below the last ===== */
	const rows = await page.$$( '.minn-menu-row[data-mi]' );
	const grip = await rows[ 0 ].$( '.minn-menu-grip' );
	const lastBox = await rows[ rows.length - 1 ].boundingBox();
	const gripBox = await grip.boundingBox();
	await page.mouse.move( gripBox.x + gripBox.width / 2, gripBox.y + gripBox.height / 2 );
	await page.mouse.down();
	await page.mouse.move( lastBox.x + 60, lastBox.y + lastBox.height * 0.8, { steps: 8 } );
	await page.mouse.move( lastBox.x + 60, lastBox.y + lastBox.height * 0.8 + 1 );
	await page.mouse.up();
	await page.waitForFunction( ( first ) => {
		const titles = [ ...document.querySelectorAll( '.minn-menu-row[data-mi] .minn-row-title' ) ].map( ( e ) => e.textContent.trim() );
		return titles.length && titles[ titles.length - 1 ] === first;
	}, before[ 0 ], { timeout: 10000 } );
	const after = await order();
	t.check( 'dragged item lands after the drop target', after[ after.length - 1 ] === before[ 0 ] && after[ 0 ] === before[ 1 ], JSON.stringify( after ) );

	/* ===== Persists across a reload ===== */
	await page.reload( { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-menu-row[data-mi]', { timeout: 15000 } );
	const reloaded = await order();
	t.check( 'new order persists', reloaded[ reloaded.length - 1 ] === before[ 0 ], JSON.stringify( reloaded ) );

	/* ===== Restore the fixture: drag it back above the current first ===== */
	const rows2 = await page.$$( '.minn-menu-row[data-mi]' );
	const grip2 = await rows2[ rows2.length - 1 ].$( '.minn-menu-grip' );
	const firstBox = await rows2[ 0 ].boundingBox();
	const grip2Box = await grip2.boundingBox();
	await page.mouse.move( grip2Box.x + grip2Box.width / 2, grip2Box.y + grip2Box.height / 2 );
	await page.mouse.down();
	await page.mouse.move( firstBox.x + 60, firstBox.y + firstBox.height * 0.2, { steps: 8 } );
	await page.mouse.move( firstBox.x + 60, firstBox.y + firstBox.height * 0.2 + 1 );
	await page.mouse.up();
	await page.waitForFunction( ( first ) => {
		const titles = [ ...document.querySelectorAll( '.minn-menu-row[data-mi] .minn-row-title' ) ].map( ( e ) => e.textContent.trim() );
		return titles.length && titles[ 0 ] === first;
	}, before[ 0 ], { timeout: 10000 } );
	t.check( 'fixture restored to original order', JSON.stringify( await order() ) === JSON.stringify( before ), JSON.stringify( await order() ) );

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
