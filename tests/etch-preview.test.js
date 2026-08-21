/**
 * Etch pages in the Minn editor.
 *
 * Etch keeps a page's CSS in its own store and prints it on wp_head, which a
 * REST render never reaches, so previews used to arrive with the right words
 * and none of the design: white-on-dark headings rendered dark-on-dark and
 * sections lost their backgrounds. Its blocks register their style ids while
 * they render, so the adapter compiles them through Etch's own renderer.
 *
 * Also pins the half that needs no adapter: every line of copy lives in a
 * wp:etch/text `content` attribute and must arm as an in-place run.
 *
 * SKIPS unless the site under test runs Etch. Point it at an Etch site with
 * MINN_TEST_URL / MINN_TEST_USER / MINN_TEST_PASS.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'etch-preview' );
	await login( page );
	await page.goto( `${ BASE }/minn-admin/content`, { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN, null, { timeout: 20000 } );

	// Find a page whose stored markup is Etch.
	const target = await page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/pages?per_page=20&status=any&context=edit&_fields=id,content', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		if ( ! r.ok ) return null;
		const list = await r.json();
		const hit = ( Array.isArray( list ) ? list : [] ).find(
			( p ) => p.content && typeof p.content.raw === 'string' && p.content.raw.includes( '<!-- wp:etch/' ) );
		if ( ! hit ) return null;
		const raw = hit.content.raw;
		// Text inside an SVG illustration is deliberately not editable in
		// place: SVG labels are positioned, not laid out, so retyping one
		// would break the drawing rather than reword the page. Those lines
		// are stored as etch/text like any other, so discount them.
		const svgLabels = ( raw.match( /"tag":"text"/g ) || [] ).length;
		return {
			id: hit.id,
			texts: ( raw.match( /<!--\s*wp:etch\/text\s/g ) || [] ).length,
			svgLabels,
			components: ( raw.match( /<!--\s*wp:etch\/component\s/g ) || [] ).length,
		};
	} );

	if ( ! target ) {
		t.check( 'an Etch page is available to test', true, 'no Etch content on this site — skipped' );
		await t.done( browser, errors );
		return;
	}
	t.check( 'an Etch page is available to test', true, `page ${ target.id }, ${ target.texts } text blocks` );

	await page.goto( `${ BASE }/minn-admin/editor/pages/${ target.id }`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-editor-body .minn-block-island', { timeout: 30000 } );
	// Island previews render async, and the styles arrive with them.
	await page.waitForFunction(
		() => [ ...document.querySelectorAll( '.minn-block-island' ) ].some( ( el ) => !! el._minnRuns ),
		null, { timeout: 30000 } );
	await page.waitForTimeout( 2500 );

	/* ===== Every line of copy is editable ===== */
	const runs = await page.evaluate( () => {
		const islands = [ ...document.querySelectorAll( '#minn-editor-body .minn-block-island' ) ];
		return {
			islands: islands.length,
			armed: islands.filter( ( el ) => !! el._minnRuns ).length,
			runs: islands.reduce( ( n, el ) => n + ( el._minnRuns ? el._minnRuns.runs.length : 0 ), 0 ),
			spans: document.querySelectorAll( '.minn-island-run[contenteditable="true"]' ).length,
			etchSplice: islands.some( ( el ) => el._minnRuns && el._minnRuns.splice
				&& el._minnRuns.splice.name === 'spliceEtchTextRuns' ),
			componentRuns: islands
				.filter( ( el ) => 'etch/component' === el.dataset.block )
				.reduce( ( n, el ) => n + ( el._minnRuns ? el._minnRuns.runs.length : 0 ), 0 ),
		};
	} );
	t.check( 'the Etch copy path is the one in use', runs.etchSplice, JSON.stringify( runs ) );
	// Page copy = every stored line, less the ones drawn inside an SVG.
	// Components add their own per-instance copy on top, so the armed count
	// is at least the page-copy figure.
	const pageCopy = target.texts - target.svgLabels;
	t.check( 'every line of page copy is armed as editable',
		runs.runs >= pageCopy,
		`${ runs.runs } armed vs ${ pageCopy } editable lines (${ target.texts } stored, ${ target.svgLabels } inside SVG)` );
	t.check( 'each armed run is a real editable span', runs.spans === runs.runs, JSON.stringify( runs ) );
	if ( target.components ) {
		t.check( 'component copy is editable too, not just elements',
			runs.componentRuns > 0, JSON.stringify( { components: target.components, componentRuns: runs.componentRuns } ) );
	}

	/* ===== The preview carries Etch's own CSS ===== */
	const css = await page.evaluate( () => {
		const sheets = [ ...document.querySelectorAll( 'style.minn-preview-css' ) ];
		return { count: sheets.length, bytes: sheets.reduce( ( n, s ) => n + s.textContent.length, 0 ) };
	} );
	t.check( 'Etch page styles reach the preview', css.count > 0 && css.bytes > 200, JSON.stringify( css ) );

	/* ===== Which is what makes the design legible ===== */
	const visual = await page.evaluate( () => {
		const preview = document.querySelector( '.minn-island-preview' );
		if ( ! preview ) return null;
		const heading = preview.querySelector( 'h1, h2' );
		const section = preview.querySelector( 'section' ) || preview.firstElementChild;
		const parse = ( c ) => {
			const m = String( c ).match( /oklch\(\s*([\d.]+)/ );
			if ( m ) return parseFloat( m[ 1 ] );
			const rgb = String( c ).match( /rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)/ );
			if ( ! rgb ) return null;
			return ( 0.299 * +rgb[ 1 ] + 0.587 * +rgb[ 2 ] + 0.114 * +rgb[ 3 ] ) / 255;
		};
		return {
			headingColor: heading ? getComputedStyle( heading ).color : null,
			headingLightness: heading ? parse( getComputedStyle( heading ).color ) : null,
			sectionBg: section ? getComputedStyle( section ).backgroundColor : null,
			sectionBgLightness: section ? parse( getComputedStyle( section ).backgroundColor ) : null,
			headingSize: heading ? parseFloat( getComputedStyle( heading ).fontSize ) : null,
		};
	} );
	t.check( 'the preview renders a heading', !! ( visual && visual.headingColor ), JSON.stringify( visual ) );
	// The failure this fixes: heading and background collapse to the same
	// darkness, so the copy is invisible even though it is there.
	const contrast = visual && visual.headingLightness !== null && visual.sectionBgLightness !== null
		? Math.abs( visual.headingLightness - visual.sectionBgLightness ) : null;
	t.check( 'heading and section background are not the same darkness',
		contrast === null || contrast > 0.25, JSON.stringify( { ...visual, contrast } ) );
	t.check( 'the section keeps a painted background',
		!! ( visual && visual.sectionBg && visual.sectionBg !== 'rgba(0, 0, 0, 0)' ), JSON.stringify( visual ) );

	/* ===== The image label sits on the image, not on the copy =====
	   A card that holds a whole page section is mostly text, so a label
	   centred on the card lands on someone's words and over the place they
	   are clicked to edit. */
	const imgIsland = await page.$( '.minn-block-island[data-imgtool] img' );
	if ( imgIsland ) {
		await imgIsland.hover();
		await page.waitForTimeout( 600 );
		const badge = await page.evaluate( () => {
			const island = document.querySelector( '.minn-block-island[data-imgtool]' );
			const b = island && island.querySelector( ':scope > .minn-imgtool-badge' );
			const img = island && island.querySelector( ':scope > .minn-island-preview img' );
			if ( ! b || ! img ) return null;
			const br = b.getBoundingClientRect();
			const ir = img.getBoundingClientRect();
			const hits = [ ...island.querySelectorAll( '.minn-island-run' ) ].filter( ( r ) => {
				const rr = r.getBoundingClientRect();
				return ! ( br.right < rr.left || br.left > rr.right || br.bottom < rr.top || br.top > rr.bottom );
			} ).length;
			return {
				dx: Math.abs( ( br.left + br.right ) / 2 - ( ir.left + ir.right ) / 2 ),
				dy: Math.abs( ( br.top + br.bottom ) / 2 - ( ir.top + ir.bottom ) / 2 ),
				overRuns: hits,
			};
		} );
		t.check( 'the image label is anchored to its image', !! badge && badge.dx < 4 && badge.dy < 4, JSON.stringify( badge ) );
		t.check( 'the image label covers no editable copy', !! badge && badge.overRuns === 0, JSON.stringify( badge ) );
		// The hover wash marks what the click acts on. On a card that is a
		// whole page section it used to grey the copy out as well.
		const wash = await page.evaluate( () => {
			const island = document.querySelector( '.minn-block-island[data-imgtool]' );
			const img = island && island.querySelector( ':scope > .minn-island-preview img' );
			if ( ! img ) return null;
			const after = getComputedStyle( island, '::after' );
			const ir = img.getBoundingClientRect();
			const br = island.getBoundingClientRect();
			return {
				washW: parseFloat( after.width ), washH: parseFloat( after.height ),
				imgW: Math.round( ir.width ), imgH: Math.round( ir.height ),
				islandH: Math.round( br.height ),
			};
		} );
		t.check( 'the hover wash covers the image, not the whole card',
			!! wash && Math.abs( wash.washH - wash.imgH ) < 4 && Math.abs( wash.washW - wash.imgW ) < 4,
			JSON.stringify( wash ) );

		// Pressing the picture opens the picker. The label sits on the
		// picture now, so a real click lands on the label, which used to
		// carry no image of its own and quietly did nothing.
		const box = await ( await page.$( '.minn-block-island[data-imgtool] .minn-island-preview img' ) ).boundingBox();
		if ( box ) {
			await page.mouse.click( box.x + box.width / 2, box.y + box.height / 2 );
			await page.waitForTimeout( 1500 );
			const opened = await page.evaluate( () => !! document.querySelector( '.minn-modal' ) );
			t.check( 'clicking the picture opens the replace picker', opened, '' );
			await page.keyboard.press( 'Escape' );
			await page.waitForTimeout( 400 );
		}
	}

	/* ===== A button's destination is editable ===== */
	const links = await page.evaluate( () => {
		const island = [ ...document.querySelectorAll( '.minn-block-island' ) ]
			.find( ( el ) => el._minnRuns && /"href"\s*:\s*"/.test( el._minnRuns.base ) );
		if ( ! island ) return { none: true };
		const stored = ( island._minnRuns.base.match( /"href"\s*:\s*"([^"]+)"/g ) || [] ).length;
		const chip = island.querySelector( '.minn-island-chip' );
		if ( chip ) chip.click();
		return { none: false, stored };
	} );
	if ( links.none ) {
		t.check( 'link destinations are offered', true, 'no links on this page — skipped' );
	} else {
		await page.waitForTimeout( 1200 );
		const shown = await page.evaluate( () => [ ...document.querySelectorAll( '[data-insplink]' ) ].map( ( i ) => i.value ) );
		t.check( 'link destinations are offered for editing', shown.length > 0,
			JSON.stringify( { stored: links.stored, shown } ) );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
