/**
 * Builder banner (GH #10 + the hover report): the whole notice is ONE link
 * (a nested anchor used to let the note's generic link rules recolor the
 * pill text invisible on hover), the Edit-in-builder pill stays readable,
 * and a builder page with NO previewable content drops the misleading
 * "Keep writing…" placeholder for an honest nothing-to-preview line.
 */
const { BASE, WP, launch, login, createPost, deletePost, reporter } = require( './helpers' );
const { execSync } = require( 'child_process' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'builder-note' );
	await login( page );

	// A dynamic-only builder page: Elementor meta, empty content.
	const id = await createPost( page, { title: 'Builder note fixture', content: '', status: 'draft' } );
	execSync( `wp --path=${ WP } post meta update ${ id } _elementor_data "[]"`, { stdio: 'ignore' } );
	execSync( `wp --path=${ WP } post meta update ${ id } _elementor_edit_mode builder`, { stdio: 'ignore' } );

	try {
		await page.goto( `${ BASE }/minn-admin/editor/posts/${ id }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-builder-note', { timeout: 20000 } );
		await page.waitForTimeout( 1200 );

		const shape = await page.evaluate( () => {
			const a = document.querySelector( 'a.minn-builder-note' );
			const pill = a && a.querySelector( '.minn-builder-open' );
			const pillCs = pill && getComputedStyle( pill );
			return {
				isLink: !! a,
				hrefOk: !! ( a && /post\.php|elementor/.test( a.getAttribute( 'href' ) || '' ) ),
				pillText: pill ? pill.textContent.trim() : '',
				pillIsSpan: !! ( pill && 'SPAN' === pill.tagName ),
				pillContrast: !! ( pillCs && pillCs.color !== pillCs.backgroundColor ),
			};
		} );
		t.check( 'the whole banner is one link to the builder', shape.isLink && shape.hrefOk, JSON.stringify( shape ) );
		t.check( 'the pill is a span with readable text', shape.pillIsSpan && /Edit in Elementor/.test( shape.pillText ) && shape.pillContrast, JSON.stringify( shape ) );

		// Hover: the pill text must STAY readable (the report's bug — the
		// note's link rules painted accent-on-accent).
		const box = await page.evaluate( () => {
			const r = document.querySelector( 'a.minn-builder-note' ).getBoundingClientRect();
			return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
		} );
		await page.mouse.move( box.x, box.y );
		await page.waitForTimeout( 250 );
		const hover = await page.evaluate( () => {
			const pill = document.querySelector( '.minn-builder-open' );
			const cs = getComputedStyle( pill );
			return { color: cs.color, bg: cs.backgroundColor, contrast: cs.color !== cs.backgroundColor };
		} );
		t.check( 'pill text stays readable on hover', hover.contrast, JSON.stringify( hover ) );

		const placeholder = await page.evaluate( () => {
			const body = document.querySelector( '#minn-editor-body' );
			return {
				locked: body.classList.contains( 'locked' ),
				empty: ! body.childNodes.length,
				before: getComputedStyle( body, '::before' ).content,
			};
		} );
		t.check( 'dynamic-only builder page shows the honest placeholder, not Keep writing',
			placeholder.locked && placeholder.empty
			&& /Nothing to preview/.test( placeholder.before ) && ! /Keep writing/.test( placeholder.before ),
			JSON.stringify( placeholder ) );
	} finally {
		await deletePost( page, id ).catch( () => {} );
	}

	// A page the builder itself refuses to open: Elementor's editor skips the
	// WooCommerce Shop page (the listing replaces its content), so an "Edit
	// in Elementor" link there dead-ends on the plain edit screen. The note
	// names the reason and offers no button.
	let shop = 0;
	let hadMeta = '';
	try {
		shop = parseInt( execSync( `wp --path=${ WP } eval 'echo function_exists( "wc_get_page_id" ) ? (int) wc_get_page_id( "shop" ) : 0;' 2>/dev/null` ).toString().trim().split( '\n' ).pop(), 10 ) || 0;
	} catch ( e ) {}
	if ( shop > 0 ) {
		hadMeta = execSync( `wp --path=${ WP } post meta get ${ shop } _elementor_edit_mode 2>/dev/null || true` ).toString().trim();
		execSync( `wp --path=${ WP } post meta update ${ shop } _elementor_edit_mode builder`, { stdio: 'ignore' } );
		try {
			await page.goto( `${ BASE }/minn-admin/editor/pages/${ shop }`, { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( '.minn-builder-note', { timeout: 20000 } );
			const blocked = await page.evaluate( () => {
				const n = document.querySelector( '.minn-builder-note' );
				return {
					tag: n.tagName,
					button: !! n.querySelector( '.minn-builder-open' ),
					text: n.textContent.replace( /\s+/g, ' ' ).trim(),
				};
			} );
			t.check( 'Shop page: note names why Elementor cannot edit it and offers no dead link',
				'DIV' === blocked.tag && ! blocked.button && /WooCommerce Shop page/.test( blocked.text ),
				JSON.stringify( blocked ) );
		} finally {
			if ( hadMeta ) {
				execSync( `wp --path=${ WP } post meta update ${ shop } _elementor_edit_mode ${ JSON.stringify( hadMeta ) }`, { stdio: 'ignore' } );
			} else {
				execSync( `wp --path=${ WP } post meta delete ${ shop } _elementor_edit_mode`, { stdio: 'ignore' } );
			}
		}
	} else {
		console.log( 'SKIP  Shop-page refusal (WooCommerce inactive)' );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
