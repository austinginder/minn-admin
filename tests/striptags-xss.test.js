/**
 * stripTags() must parse in an INERT document.
 *
 * The helper turns markup into plain text for ~20 call sites (surface table
 * cells, menu labels, plugin descriptions, toasts, comment excerpts). It used
 * to do `document.createElement('div').innerHTML = html`. A detached element
 * created from the LIVE document still runs the resource-loading side of HTML
 * parsing, so `<img src=x onerror=…>` fired its handler DURING the parse —
 * before the `esc()` wrapped around the return value could matter. The escaped
 * result was even the empty string, so the cell rendered blank while the
 * payload had already run.
 *
 * Two layers here:
 *   1. End-to-end — a nav menu label carrying an event-handler payload (admins
 *      hold unfiltered_html, so the label stores and REST-renders verbatim)
 *      reaches stripTags when the Menus view loads. Nothing may execute.
 *   2. In-page unit — run the shipped helper's exact shape against four payload
 *      families, and confirm entity decoding still works (decodeEntities is the
 *      same function; a "fix" that broke it would break menu labels and titles).
 *
 * `<script>` inserted via innerHTML never executes either way — testing with a
 * script tag would pass against the vulnerable version. Event-handler
 * attributes are the vector, so every payload here uses one.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

const PAYLOAD = '<img src=x onerror=window.__minnXss=1>';
const LABEL = 'XSS probe ' + Date.now();

( async () => {
	const t = reporter( 'striptags-xss' );
	const { browser, page, errors } = await launch();
	await login( page );

	let itemId = 0;
	let menuId = 0;
	try {
		/* ===== Seed a poisoned menu label over REST ===== */
		const seeded = await page.evaluate( async ( args ) => {
			const nonce = window.MINN.nonce;
			const menus = await ( await fetch( `${ args.base }/wp-json/wp/v2/menus?per_page=1`, {
				headers: { 'X-WP-Nonce': nonce },
			} ) ).json();
			if ( ! menus.length ) return { menuId: 0, itemId: 0 };
			const res = await fetch( `${ args.base }/wp-json/wp/v2/menu-items`, {
				method: 'POST',
				headers: { 'X-WP-Nonce': nonce, 'Content-Type': 'application/json' },
				body: JSON.stringify( {
					title: args.payload + args.label,
					url: 'https://example.com/xss-probe',
					menus: menus[ 0 ].id,
					status: 'publish',
				} ),
			} );
			const j = await res.json();
			return { menuId: menus[ 0 ].id, itemId: j.id || 0, rendered: j.title && j.title.rendered };
		}, { base: BASE, payload: PAYLOAD, label: LABEL } );

		menuId = seeded.menuId;
		itemId = seeded.itemId;
		t.check( 'poisoned menu item created', itemId > 0, String( itemId ) );
		t.check(
			'payload survives REST rendering verbatim (the vector is real)',
			!! seeded.rendered && seeded.rendered.indexOf( 'onerror' ) !== -1,
			String( seeded.rendered )
		);

		/* ===== 1. End-to-end: loading Menus must not execute it ===== */
		await page.goto( `${ BASE }/minn-admin/menus`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-menu-row[data-mi]', { timeout: 15000 } );
		// The label rides an async menu-items fetch; give any handler a window.
		await page.waitForFunction(
			( label ) => [ ...document.querySelectorAll( '.minn-menu-row[data-mi] .minn-row-title' ) ]
				.some( ( e ) => e.textContent.indexOf( label ) !== -1 ),
			LABEL,
			{ timeout: 15000 }
		).catch( () => {} );
		await page.waitForTimeout( 600 );

		const fired = await page.evaluate( () => !! window.__minnXss );
		t.check( 'no handler fired while rendering the poisoned label', fired === false );

		const rowText = await page.$$eval( '.minn-menu-row[data-mi] .minn-row-title', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		const row = rowText.find( ( s ) => s.indexOf( LABEL ) !== -1 );
		t.check( 'label renders as text, tags stripped', !! row && row.indexOf( '<img' ) === -1, String( row ) );

		const injected = await page.$$eval( '.minn-menu-row img[src="x"]', ( els ) => els.length );
		t.check( 'no img element materialised from the label', injected === 0, String( injected ) );

		/* ===== 2. In-page unit sweep over payload families ===== */
		const unit = await page.evaluate( () => new Promise( ( resolve ) => {
			window.__unitFired = 0;
			window.__mark = () => { window.__unitFired++; };
			// The shipped shape (assets/js/app.js).
			const doc = document.implementation.createHTMLDocument( '' );
			const stripTags = ( html ) => {
				doc.body.innerHTML = html || '';
				return doc.body.textContent || '';
			};
			const payloads = [
				'<img src=x onerror="window.__mark()">',
				'<svg onload="window.__mark()"></svg>',
				'<video><source onerror="window.__mark()"></video>',
				'<input autofocus onfocus="window.__mark()">',
			];
			const out = payloads.map( ( p ) => stripTags( p ) );
			setTimeout( () => resolve( {
				fired: window.__unitFired,
				out,
				entity: stripTags( 'Ampersand &amp; entity &#8217;s' ),
				text: stripTags( 'plain <b>bold</b> text' ),
			} ), 500 );
		} ) );

		t.check( 'no payload family executes in an inert document', unit.fired === 0, String( unit.fired ) );
		t.check( 'all payloads reduce to empty text', unit.out.every( ( s ) => s === '' ), JSON.stringify( unit.out ) );
		t.check( 'entity decoding still works', unit.entity === 'Ampersand & entity ’s', unit.entity );
		t.check( 'tag stripping still works', unit.text === 'plain bold text', unit.text );

		/* ===== 3. The live helper is the inert shape, not a live-document div ===== */
		const src = await ( await fetch( `${ BASE }/wp-content/plugins/minn-admin/assets/js/app.js` ) ).text();
		// Search the WHOLE source, never a leading slice: this used to read
		// the first 4000 characters, so the guard on a security invariant
		// turned red the moment the file's preamble grew past the helper
		// rather than when the helper changed.
		t.check(
			'shipped helper uses createHTMLDocument',
			/stripTags[\s\S]{0,400}?createHTMLDocument|createHTMLDocument[\s\S]{0,400}?stripTags/.test( src ),
			'helper region'
		);
		t.check(
			'shipped helper no longer builds a live-document div',
			! /const stripTags = \([^)]*\) => \{\s*const d = document\.createElement/.test( src ),
			'old shape absent'
		);
	} finally {
		if ( itemId ) {
			await page.evaluate( async ( args ) => {
				await fetch( `${ args.base }/wp-json/wp/v2/menu-items/${ args.id }?force=true`, {
					method: 'DELETE',
					headers: { 'X-WP-Nonce': window.MINN.nonce },
				} );
			}, { base: BASE, id: itemId } ).catch( () => {} );
		}
	}

	await t.done( browser, errors );
} )();
