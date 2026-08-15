/**
 * ACF editor panel through the shared form engine: the true_false switch,
 * the select's "—" clear option, and the false-sentinel regression.
 *
 * ACF answers `false` over REST for ANY field with no value — including
 * selects and text fields. Panel saves round-trip the whole values object,
 * so before the seed normalization that sentinel made ACF's own REST schema
 * reject every panel save on the post with a 400 ("acf[layout] must contain
 * at least 1 item"). The suite drives a post whose select is untouched and
 * empty, which is exactly the shape that used to fail.
 *
 * Fixture: ACF free with the group_minn_test "Post details" group
 * (subtitle text, layout select, featured_story true_false, editor_notes
 * textarea, photo_gallery gallery — the gallery only feeds the locked count)
 * PLUS group_minn_norest "Slideshow settings" (slideshow_caption text,
 * slideshow_arrows true_false) with "Show in REST API" OFF — the default on
 * real sites. Values ride the dedicated `minn_acf` REST field now, so the
 * no-REST group must render and save like any other while staying absent
 * from ACF's own `acf` REST object.
 */
const { launch, login, loginAs, createPost, deletePost, openEditor, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'acf-panel' );
	const { browser, page, errors } = await launch();
	await login( page );

	const id = await createPost( page, { title: 'ACF panel test', content: '<p>x</p>' } );

	// Watch the post saves — the sentinel bug surfaced as a 400 here while
	// the UI still looked fine.
	const saves = [];
	page.on( 'response', ( res ) => {
		if ( res.request().method() === 'POST' && new RegExp( 'wp/v2/posts/' + id ).test( res.url() ) ) {
			saves.push( res.status() );
		}
	} );

	const readAcf = () => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?_fields=acf', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		return ( await r.json() ).acf;
	}, id );
	// Wait on the real save response, not a flat delay — a slow worker makes
	// a fixed wait race the request (the rule-51 shortcuts lesson).
	const save = async () => {
		const wait = page.waitForResponse( ( res ) =>
			res.request().method() === 'POST' && new RegExp( 'wp/v2/posts/' + id ).test( res.url() ), { timeout: 20000 } );
		await page.keyboard.press( 'Meta+s' );
		await wait;
		await page.waitForTimeout( 400 );
	};

	try {
		// Object authorization regression: field groups can carry internal
		// workflow labels/choices, so another Author's draft must be opaque.
		const { ctx: authorCtx, page: authorPage } = await loginAs( browser, 'minn-author', 'minn-author-pass-1' );
		const denied = await authorPage.evaluate( async ( postId ) => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/acf/fields?post_type=posts&post_id=' + postId, {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return { status: r.status, body: await r.json() };
		}, id );
		t.check( 'Author cannot inspect another user\'s draft ACF schema', denied.status === 403 && denied.body.code === 'rest_forbidden', JSON.stringify( denied ) );
		await authorCtx.close();

		await openEditor( page, id );
		await page.waitForSelector( '[data-side-door="panel:acf"]', { timeout: 15000 } );
		await page.click( '[data-side-door="panel:acf"]' );
		const toggleSel = '[data-pf$=":featured_story"][data-ftype="toggle"]';
		const selectSel = '[data-pf$=":layout"][data-ftype="select"]';
		await page.waitForSelector( toggleSel, { timeout: 15000 } );

		t.check( 'select renders with the "—" clear option',
			await page.$eval( selectSel, ( e ) => e.options[ 0 ].value === '' ) );

		// Toggle on → save with the untouched empty select in the payload.
		await page.click( toggleSel );
		t.check( 'switch flips on with aria state', await page.$eval( toggleSel,
			( e ) => e.classList.contains( 'on' ) && e.getAttribute( 'aria-checked' ) === 'true' ) );
		await save();
		t.check( 'save with the empty-select sentinel is not rejected',
			saves.length > 0 && saves.every( ( s ) => s < 400 ), saves.join( ',' ) );
		let acf = await readAcf();
		t.check( 'toggle=on persisted', acf && acf.featured_story === true, JSON.stringify( acf ) );

		// Set the select, save, verify.
		await page.selectOption( selectSel, 'wide' );
		await save();
		acf = await readAcf();
		t.check( 'select value persisted', acf && acf.layout === 'wide', JSON.stringify( acf ) );

		// Clear the select via "—" and toggle back off in one save.
		await page.selectOption( selectSel, '' );
		await page.click( toggleSel );
		await save();
		acf = await readAcf();
		t.check( 'select cleared via "—"', acf && ( acf.layout === false || acf.layout === '' || acf.layout === null ), JSON.stringify( acf ) );
		t.check( 'toggle=off persisted', acf && acf.featured_story === false, JSON.stringify( acf ) );

		// The no-REST group (show_in_rest OFF, the real-site default): its
		// fields render and save through minn_acf like any other group.
		const capSel = '[data-pf$=":slideshow_caption"]';
		t.check( 'no-REST group renders in the panel', !! ( await page.$( capSel ) ) );
		await page.fill( capSel, 'Around the shop' );
		await save();
		const minnAcf = await page.evaluate( async ( pid ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=minn_acf', {
				headers: { 'X-WP-Nonce': window.MINN.nonce },
			} );
			return ( await r.json() ).minn_acf;
		}, id );
		t.check( 'no-REST group value persisted via minn_acf', minnAcf && minnAcf.slideshow_caption === 'Around the shop', JSON.stringify( minnAcf ) );
		// Minn widening its own read path must not widen ACF's REST surface.
		acf = await readAcf();
		t.check( 'no-REST field stays absent from ACF\'s own `acf` object', acf && ! ( 'slideshow_caption' in acf ), JSON.stringify( acf ) );

		const readMinnAcf = () => page.evaluate( async ( pid ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=minn_acf', {
				headers: { 'X-WP-Nonce': window.MINN.nonce },
			} );
			return ( await r.json() ).minn_acf;
		}, id );

		// color_picker rides the color control: swatch + hex text, synced.
		const colorSel = '[data-pf$=":slideshow_accent"][data-ftype="color"]';
		t.check( 'color_picker renders the color control (swatch + text)',
			await page.$eval( colorSel, ( e ) =>
				!! e.querySelector( 'input[type="color"]' ) && !! e.querySelector( 'input[type="text"]' ) ) );
		await page.fill( `${ colorSel } input[type="text"]`, '#ff6600' );
		t.check( 'typing a hex syncs the swatch', await page.$eval( colorSel,
			( e ) => e.querySelector( 'input[type="color"]' ).value === '#ff6600' ) );
		await save();
		let mv = await readMinnAcf();
		t.check( 'color value persisted', mv && mv.slideshow_accent === '#ff6600', JSON.stringify( mv ) );

		// checkbox field: the multicheck control (one tick row per choice,
		// value = the checked choice keys in choice order).
		const mcSel = '[data-pf$=":slideshow_tags"][data-ftype="multicheck"]';
		t.check( 'checkbox field renders the multicheck control',
			await page.$eval( mcSel, ( e ) => e.querySelectorAll( 'input[type="checkbox"]' ).length === 3 ).catch( () => false ) );
		await page.click( `${ mcSel } input[value="new"]` );
		await page.click( `${ mcSel } input[value="featured"]` );
		await save();
		mv = await readMinnAcf();
		t.check( 'checked choices persisted as an ordered list',
			Array.isArray( mv.slideshow_tags ) && mv.slideshow_tags.join() === 'new,featured',
			JSON.stringify( mv && mv.slideshow_tags ) );

		// button_group is a styled radio — it rides the select control.
		t.check( 'button_group renders as a select',
			!! ( await page.$( '[data-pf$=":slideshow_size"][data-ftype="select"]' ) ) );
		await page.selectOption( '[data-pf$=":slideshow_size"]', 'lg' );
		await save();
		mv = await readMinnAcf();
		t.check( 'button_group choice persisted', mv.slideshow_size === 'lg', JSON.stringify( mv && mv.slideshow_size ) );

		// date field: the app's own date-picker popover in date-only mode
		// (no time row; machine value YYYY-MM-DD, stored by ACF as Ymd).
		const dtSel = '[data-pf$=":slideshow_starts"][data-ftype="date"]';
		t.check( 'date field renders the picker input', !! ( await page.$( dtSel ) ) );
		await page.click( dtSel );
		await page.waitForSelector( '.minn-dp-pop .minn-dp-day', { timeout: 10000 } );
		t.check( 'date-only popover hides the time row', ! ( await page.$( '.minn-dp-pop .minn-dp-time' ) ) );
		await page.click( '.minn-dp-pop .minn-dp-day:not(.out)' );
		await page.click( '.minn-dp-pop [data-dp-done]' );
		await save();
		mv = await readMinnAcf();
		t.check( 'picked date persisted as YYYY-MM-DD',
			/^\d{4}-\d{2}-\d{2}$/.test( mv.slideshow_starts ), JSON.stringify( mv && mv.slideshow_starts ) );

		// time field: lenient text ("7:30 pm") normalized to HH:mm.
		const tmSel = '[data-pf$=":slideshow_daily_at"][data-ftype="time"]';
		t.check( 'time field renders', !! ( await page.$( tmSel ) ) );
		await page.fill( tmSel, '7:30 pm' );
		await save();
		mv = await readMinnAcf();
		t.check( 'time persisted normalized', mv.slideshow_daily_at === '19:30', JSON.stringify( mv && mv.slideshow_daily_at ) );

		// image field: the form engine's { id, url } control with the media picker.
		const imgSel = '[data-pf$=":slideshow_cover"][data-ftype="image"]';
		t.check( 'image field renders the image control', !! ( await page.$( imgSel ) ) );
		await page.click( `${ imgSel } [data-img-pick]` );
		await page.waitForSelector( '.minn-picker-item[data-pick]', { timeout: 15000 } );
		await page.click( '.minn-picker-item[data-pick]' );
		await page.waitForTimeout( 800 );
		await save();
		mv = await readMinnAcf();
		t.check( 'picked image persisted as { id, url }',
			mv && mv.slideshow_cover && mv.slideshow_cover.id > 0 && typeof mv.slideshow_cover.url === 'string',
			JSON.stringify( mv && mv.slideshow_cover ) );

		// file field: the any-attachment picker (it also replaces the panel
		// modal, so re-enter through the door first).
		await page.waitForSelector( '[data-side-door="panel:acf"]', { timeout: 15000 } );
		await page.click( '[data-side-door="panel:acf"]' );
		const flSel = '[data-pf$=":slideshow_manual"][data-ftype="file"]';
		await page.waitForSelector( flSel, { timeout: 15000 } );
		t.check( 'file field renders the file control', true );
		await page.click( `${ flSel } [data-file-pick]` );
		await page.waitForSelector( '.minn-picker-item[data-pick]', { timeout: 15000 } );
		t.check( 'file picker lists non-image attachments', await page.evaluate( () =>
			Array.from( document.querySelectorAll( '.minn-picker-item' ) ).some( ( el ) => /manual/i.test( el.textContent ) ) ) );
		await page.click( '.minn-picker-item[data-pick]' );
		await page.waitForTimeout( 600 );
		await save();
		mv = await readMinnAcf();
		t.check( 'picked file persisted as { id, url, name }',
			mv && mv.slideshow_manual && mv.slideshow_manual.id > 0 && typeof mv.slideshow_manual.name === 'string' && mv.slideshow_manual.name.length > 0,
			JSON.stringify( mv && mv.slideshow_manual ) );

		// gallery field: the islands images editor in items mode. Opening it
		// closes the panel modal by design (the media picker must be able to
		// stack above the images editor), so the flow ends back in the editor
		// with the value dirty — ⌘S saves it like any panel edit. The image
		// pick above ALSO closed the modal (the picker replaces it), so
		// re-enter through the door first.
		await page.waitForSelector( '[data-side-door="panel:acf"]', { timeout: 15000 } );
		await page.click( '[data-side-door="panel:acf"]' );
		const galSel = '[data-pf$=":photo_gallery"][data-ftype="gallery"]';
		await page.waitForSelector( galSel, { timeout: 15000 } );
		t.check( 'gallery field renders the gallery control', !! ( await page.$( galSel ) ) );
		await page.click( `${ galSel } [data-gal-edit]` );
		await page.waitForSelector( '#minn-imgedit-add', { timeout: 10000 } );
		await page.click( '#minn-imgedit-add' );
		await page.waitForSelector( '.minn-picker-item[data-pick]', { timeout: 15000 } );
		await page.click( '.minn-picker-item[data-pick="0"]' );
		await page.click( '.minn-picker-item[data-pick="1"]' );
		await page.click( '#minn-picker-done' );
		await page.waitForFunction( () => document.querySelectorAll( '.minn-imgedit-tile' ).length === 2, null, { timeout: 10000 } );
		await page.click( '#minn-imgedit-apply' );
		await page.waitForTimeout( 600 );
		await save();
		mv = await readMinnAcf();
		t.check( 'picked gallery persisted as ordered { id, url } items',
			mv && Array.isArray( mv.photo_gallery ) && mv.photo_gallery.length === 2 && mv.photo_gallery.every( ( x ) => x.id > 0 ),
			JSON.stringify( mv && mv.photo_gallery ) );

		// wysiwyg field: the rich-text modal (opens after the panel modal
		// closes, same one-way flow), typed marks round-trip as HTML.
		await page.waitForSelector( '[data-side-door="panel:acf"]', { timeout: 15000 } );
		await page.click( '[data-side-door="panel:acf"]' );
		const rtSel = '[data-pf$=":slideshow_notes"][data-ftype="wysiwyg"]';
		await page.waitForSelector( rtSel, { timeout: 15000 } );
		t.check( 'wysiwyg field renders the rich-text control', !! ( await page.$( rtSel ) ) );
		await page.click( `${ rtSel } [data-rt-edit]` );
		await page.waitForSelector( '.minn-rt-body', { timeout: 10000 } );
		await page.click( '.minn-rt-body' );
		await page.keyboard.type( 'Notes with ' );
		await page.keyboard.press( 'Meta+b' );
		await page.keyboard.type( 'bold' );
		await page.keyboard.press( 'Meta+b' );
		await page.click( '#minn-rt-apply' );
		await page.waitForTimeout( 500 );
		await save();
		mv = await readMinnAcf();
		t.check( 'wysiwyg round-trips paragraphs and marks',
			typeof mv.slideshow_notes === 'string' && /<p>Notes with <(b|strong)>bold<\/(b|strong)><\/p>/.test( mv.slideshow_notes ),
			JSON.stringify( mv && mv.slideshow_notes ) );

		// Emptying the gallery is a legitimate apply in items mode.
		await page.waitForSelector( '[data-side-door="panel:acf"]', { timeout: 15000 } );
		await page.click( '[data-side-door="panel:acf"]' );
		await page.waitForSelector( `${ galSel } [data-gal-edit]`, { timeout: 15000 } );
		await page.click( `${ galSel } [data-gal-edit]` );
		await page.waitForSelector( '.minn-imgedit-x', { timeout: 10000 } );
		// One at a time: each removal re-renders the grid, detaching the other
		// × buttons (the rule-31 class).
		await page.waitForFunction( () => {
			const x = document.querySelector( '.minn-imgedit-x' );
			if ( x ) x.click();
			return ! document.querySelector( '.minn-imgedit-x' );
		}, null, { timeout: 10000, polling: 200 } );
		await page.click( '#minn-imgedit-apply' );
		await page.waitForTimeout( 600 );
		await save();
		mv = await readMinnAcf();
		t.check( 'emptied gallery persisted as an empty list',
			mv && Array.isArray( mv.photo_gallery ) && mv.photo_gallery.length === 0, JSON.stringify( mv && mv.photo_gallery ) );

		t.check( 'no save was rejected across the run', saves.every( ( s ) => s < 400 ), saves.join( ',' ) );
	} finally {
		await deletePost( page, id );
	}

	await t.done( browser, errors );
} )();
