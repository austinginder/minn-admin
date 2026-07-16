/**
 * Soft-reload (v0.16.0): tab, filter, and search changes keep list chrome
 * (toolbar + tabs) painted and dim the body with .minn-busy instead of
 * replacing the view with the full "Loading…" shell. Covers the Content
 * type tabs and search, Media type tabs, surface tabs (Gravity Forms),
 * the Extensions cold-shell first visit (tab strip never unmounts), and
 * softListReload's route guard (a slow response never clobbers a view the
 * user already navigated away from).
 *
 * Network delays are injected with page.route so the busy window is
 * deterministic instead of racing the local server.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'soft-reload' );
	await login( page );

	const delayRoute = ( pattern, ms ) =>
		page.route( pattern, async ( route ) => {
			await new Promise( ( r ) => setTimeout( r, ms ) );
			await route.continue().catch( () => {} );
		} );

	// Tag the chrome node that owns the given element so we can prove the
	// node survives (is not re-created) while the list body reloads.
	const tagChrome = ( sel ) => page.evaluate( ( s ) => {
		const el = document.querySelector( s );
		const bar = el && el.closest( '.minn-toolbar' );
		if ( bar ) bar._minnProbe = true;
		return !! bar;
	}, sel );
	const chromeProbeAlive = ( sel ) => page.evaluate( ( s ) => {
		const el = document.querySelector( s );
		const bar = el && el.closest( '.minn-toolbar' );
		return !! ( bar && bar._minnProbe );
	}, sel );
	const busyGone = async () => {
		await page.waitForFunction(
			() => ! document.querySelector( '#minn-view .minn-busy' ),
			{ timeout: 15000 }
		);
		await page.waitForTimeout( 250 );
	};

	// Content's type control is tabs on small sites and a combobox when the
	// site has many post types (minnadmin: Woo + CPT UI). Handle both.
	const typeChromeSel = '[data-typecombo], .minn-tab[data-filter]';
	const pickType = async ( value ) => {
		if ( await page.$( `.minn-tab[data-filter="${ value }"]` ) ) {
			await page.click( `.minn-tab[data-filter="${ value }"]` );
			return 'tab';
		}
		await page.click( '[data-typecombo] .minn-ac-input' );
		await page.waitForSelector( `[data-typecombo] .minn-ac-item[data-acv="${ value }"]`, { timeout: 5000 } );
		await page.click( `[data-typecombo] .minn-ac-item[data-acv="${ value }"]` );
		return 'combo';
	};

	/* ===== Content: type filter soft reload ===== */
	await page.goto( `${ BASE }/minn-admin/content`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-table-row', { timeout: 15000 } );
	t.check( 'content chrome tagged', await tagChrome( typeChromeSel ) );

	await delayRoute( '**/wp/v2/pages*', 900 );
	const typeMode = await pickType( 'pages' );
	const mid = await page.evaluate( () => ( {
		tabActive: !! document.querySelector( '.minn-tab[data-filter="pages"].active' ),
		busy: !! document.querySelector( '#minn-view .minn-table.minn-busy' ),
		fullShell: !! document.querySelector( '#minn-view > .minn-loading' ),
	} ) );
	if ( typeMode === 'tab' ) t.check( 'clicked tab paints active before the data arrives', mid.tabActive );
	t.check( 'list body dims with .minn-busy while loading', mid.busy );
	t.check( 'no full Loading… shell replaces the view', ! mid.fullShell );
	t.check( 'toolbar node survives the reload (not re-created mid-load)', await chromeProbeAlive( typeChromeSel ) );
	await page.unroute( '**/wp/v2/pages*' );
	await busyGone();
	t.check( 'pages render after the soft reload', !! ( await page.$( '.minn-table-row' ) ) && ! ( await page.$( '.minn-table.minn-busy' ) ) );
	if ( typeMode === 'combo' ) {
		t.check( 'type combobox reflects the pick after render', await page.evaluate( () => {
			const i = document.querySelector( '[data-typecombo] .minn-ac-input' );
			return !! i && ( i.placeholder === 'Pages' || i.value === 'Pages' );
		} ) );
	}

	/* ===== Content: search keeps chrome and regains focus ===== */
	await delayRoute( '**/wp/v2/pages*', 700 );
	await tagChrome( '#minn-content-search' );
	await page.click( '#minn-content-search' );
	await page.keyboard.type( 'a' );
	await page.waitForTimeout( 500 ); // 350ms debounce + handler start
	t.check( 'search keeps the toolbar painted mid-load', await chromeProbeAlive( '#minn-content-search' ) );
	await page.unroute( '**/wp/v2/pages*' );
	await busyGone();
	t.check( 'search field regains focus after the re-render', await page.evaluate(
		() => document.activeElement && document.activeElement.id === 'minn-content-search'
	) );

	/* ===== Media: type tab soft reload ===== */
	await page.goto( `${ BASE }/minn-admin/media`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '[data-mtype]', { timeout: 15000 } );
	await page.waitForFunction( () => ! document.querySelector( '#minn-view > .minn-loading' ), { timeout: 15000 } );
	await tagChrome( '[data-mtype]' );
	await delayRoute( '**/wp/v2/media*', 900 );
	await page.click( '[data-mtype="image"]' );
	const midMedia = await page.evaluate( () => ( {
		active: !! document.querySelector( '[data-mtype="image"].active' ),
		busy: !! document.querySelector( '#minn-view .minn-media-grid.minn-busy, #minn-view .minn-media-list.minn-busy, #minn-view .minn-table.minn-busy' ),
	} ) );
	t.check( 'media type tab paints active immediately', midMedia.active );
	t.check( 'media body dims while the grid refetches', midMedia.busy );
	t.check( 'media toolbar survives the reload', await chromeProbeAlive( '[data-mtype]' ) );
	await page.unroute( '**/wp/v2/media*' );
	await busyGone();

	/* ===== Surface tabs: Gravity Forms entries =====
	 * Form tabs render as pills on short lists and as a [data-stabcombo]
	 * combobox past 6 tabs (minnadmin is past it) — handle both. */
	await page.goto( `${ BASE }/minn-admin/gravity-forms`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '[data-stab], [data-stabcombo]', { timeout: 20000 } );
	await page.waitForFunction( () => ! document.querySelector( '#minn-view > .minn-loading' ), { timeout: 20000 } );
	const stabChromeSel = '[data-stab], [data-stabcombo]';
	await tagChrome( stabChromeSel );
	await delayRoute( '**/gf/v2/**', 900 );
	const pillTab = await page.evaluate( () => {
		const btn = Array.from( document.querySelectorAll( '[data-stab]' ) )
			.find( ( b ) => ! b.classList.contains( 'active' ) );
		return btn ? btn.dataset.stab : null;
	} );
	if ( pillTab != null ) {
		await page.click( `[data-stab="${ pillTab }"]` );
		t.check( 'surface tab paints active immediately', await page.evaluate(
			( tab ) => !! document.querySelector( `[data-stab="${ tab }"].active` ), pillTab
		) );
	} else {
		await page.click( '[data-stabcombo] .minn-ac-input' );
		await page.waitForSelector( '[data-stabcombo] .minn-ac-item', { timeout: 5000 } );
		const comboVal = await page.evaluate( () => {
			const items = Array.from( document.querySelectorAll( '[data-stabcombo] .minn-ac-item' ) );
			const pick = items.find( ( i ) => ! i.classList.contains( 'current' ) && i.dataset.acv !== '' );
			return pick ? pick.dataset.acv : null;
		} );
		t.check( 'form tab combobox offers another form', !! comboVal, String( comboVal ) );
		await page.click( `[data-stabcombo] .minn-ac-item[data-acv="${ comboVal }"]` );
	}
	const midGf = await page.evaluate( () => ( {
		busy: !! document.querySelector( '#minn-view .minn-busy' ),
		fullShell: !! document.querySelector( '#minn-view > .minn-loading' ),
	} ) );
	t.check( 'surface list dims instead of a full Loading… swap', midGf.busy && ! midGf.fullShell );
	t.check( 'surface tabs survive the reload', await chromeProbeAlive( stabChromeSel ) );
	await page.unroute( '**/gf/v2/**' );
	await busyGone();
	t.check( 'surface list renders after the soft reload', !! ( await page.$( '.minn-table-row, .minn-empty' ) ) );

	/* ===== Extensions: cold first visit keeps the tab strip ===== */
	await page.goto( `${ BASE }/minn-admin/extensions`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '[data-xtab="themes"]', { timeout: 20000 } );
	await page.waitForFunction( () => ! document.querySelector( '#minn-view > .minn-loading' ), { timeout: 20000 } );
	await delayRoute( '**/minn-admin/v1/themes*', 900 );
	await page.click( '[data-xtab="themes"]' );
	const midExt = await page.evaluate( () => ( {
		strip: !! document.querySelector( '[data-xtab="plugins"]' ),
		active: !! document.querySelector( '[data-xtab="themes"].active' ),
		loading: ( ( document.querySelector( '#minn-view .minn-loading' ) || {} ).textContent || '' ).trim(),
	} ) );
	t.check( 'cold Themes visit keeps the Plugins/Themes/Licenses strip', midExt.strip && midExt.active );
	t.check( 'cold Themes visit shows a scoped loading note under the strip', /^Loading themes/.test( midExt.loading ), midExt.loading );
	await page.unroute( '**/minn-admin/v1/themes*' );
	await page.waitForSelector( '.minn-card.minn-theme', { timeout: 15000 } );
	t.check( 'themes render after the cold soft load', !! ( await page.$( '.minn-theme-grid' ) ) );

	/* ===== Route guard: a slow list response never clobbers another view ===== */
	await page.click( '.minn-nav-btn[data-nav="content"]' );
	await page.waitForSelector( '.minn-table-row', { timeout: 15000 } );
	await delayRoute( '**/wp/v2/posts*', 1500 );
	await pickType( 'posts' );
	await page.click( '.minn-nav-btn[data-nav="media"]' );
	await page.waitForSelector( '[data-mtype]', { timeout: 15000 } );
	await page.waitForTimeout( 1800 ); // let the stale content response resolve
	const guard = await page.evaluate( () => ( {
		mediaChrome: !! document.querySelector( '#minn-view [data-mtype]' ),
		contentTabs: !! document.querySelector( '#minn-view .minn-tab[data-filter]' ),
	} ) );
	t.check( 'stale content response never clobbers the Media view', guard.mediaChrome && ! guard.contentTabs, JSON.stringify( guard ) );
	await page.unroute( '**/wp/v2/posts*' );

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
