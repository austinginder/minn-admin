/**
 * Mobile surface tables (2026-08-08 round): at phone widths the shared
 * surface list renderer stacks rows as cards — header hidden, primary cell
 * a full-width wrapping title, meta cells below, no horizontal scroll.
 * Generic via the inline-grid [style] hook, so one check per family proves
 * the treatment for every surface adapter.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'mobile-surfaces' );
	const { browser, page, errors } = await launch();
	await login( page );
	await page.setViewportSize( { width: 390, height: 800 } );

	// Resolve one surface per family from the boot payload (ids vary by
	// which provider is resident).
	const targets = await page.evaluate( () => {
		const pick = ( fam ) => ( ( window.MINN.surfaces || [] ).find( ( s ) => s.family === fam ) || {} ).id;
		return [ pick( 'snippets' ), pick( 'activity-log' ), pick( 'redirects' ) ].filter( Boolean );
	} );
	t.check( 'three surface families resolved', targets.length === 3, JSON.stringify( targets ) );

	for ( const id of targets ) {
		await page.goto( `${ BASE }/minn-admin/${ id }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-table-row[style]', { timeout: 20000 } );
		await page.waitForTimeout( 600 );
		const m = await page.evaluate( () => {
			const head = document.querySelector( '.minn-table-head[style]' );
			const row = document.querySelector( '.minn-table-row[style]' );
			const primary = row.querySelector( '[data-primary]' );
			const table = document.querySelector( '.minn-table' );
			return {
				headHidden: ! head || getComputedStyle( head ).display === 'none',
				rowFlex: getComputedStyle( row ).display === 'flex',
				primaryWide: primary ? primary.offsetWidth > row.clientWidth * 0.5 : false,
				noHScroll: table.scrollWidth <= table.clientWidth + 1
					&& document.documentElement.scrollWidth <= window.innerWidth + 1,
			};
		} );
		t.check( `${ id }: header hidden, card rows, wide title, no horizontal scroll`,
			m.headHidden && m.rowFlex && m.primaryWide && m.noHScroll, JSON.stringify( m ) );
	}

	// Desktop stays a grid (the card treatment is scoped to the breakpoint).
	await page.setViewportSize( { width: 1280, height: 800 } );
	await page.waitForTimeout( 500 );
	const desktop = await page.evaluate( () => {
		const head = document.querySelector( '.minn-table-head[style]' );
		const row = document.querySelector( '.minn-table-row[style]' );
		return {
			headShown: !! head && getComputedStyle( head ).display === 'grid',
			rowGrid: getComputedStyle( row ).display === 'grid',
		};
	} );
	t.check( 'desktop keeps the header + grid rows', desktop.headShown && desktop.rowGrid, JSON.stringify( desktop ) );

	await t.done( browser, errors );
} )();
