/**
 * Row-layout containers: a group with layout:{"type":"flex"} flows its
 * children horizontally on the front end, so the writing slot mirrors the
 * DIRECTION (display:flex + justification) while keeping Minn typography.
 * Vertical-orientation flex groups stay in normal block flow. The slot
 * classes are editor chrome only — saves stay byte-identical.
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

const FLEX = '<!-- wp:group {"fontSize":"small","layout":{"type":"flex","flexWrap":"wrap","justifyContent":"center"}} -->\n<div class="wp-block-group has-small-font-size"><!-- wp:paragraph -->\n<p>Type:</p>\n<!-- /wp:paragraph -->\n\n<!-- wp:paragraph -->\n<p>Residential</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->';
const COL = '<!-- wp:group {"layout":{"type":"flex","orientation":"vertical"}} -->\n<div class="wp-block-group"><!-- wp:paragraph -->\n<p>Stacked</p>\n<!-- /wp:paragraph --></div>\n<!-- /wp:group -->';
const CONTENT = FLEX + '\n\n' + COL;

( async () => {
	const t = reporter( 'flex-slot' );
	const { browser, page, errors } = await launch();
	await login( page );

	const readRaw = ( id ) => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_cb=' + Math.random(), {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		const j = await r.json();
		return ( j.content && j.content.raw ) || '';
	}, id );

	let id = 0;
	try {
		id = await createPost( page, { title: 'Flex slot probe', content: CONTENT } );
		t.check( 'fixture post created', id > 0, String( id ) );
		await openEditor( page, id );
		await page.waitForSelector( '.minn-slot.minn-slot-flex', { timeout: 20000 } );
		await page.waitForTimeout( 1500 );

		const st = await page.evaluate( () => {
			const flex = document.querySelector( '.minn-slot.minn-slot-flex' );
			const cs = getComputedStyle( flex );
			const ps = [ ...flex.querySelectorAll( ':scope > p' ) ];
			const slots = [ ...document.querySelectorAll( '.minn-slot' ) ];
			return {
				display: cs.display,
				justify: cs.justifyContent,
				sameRow: ps.length === 2 && Math.abs( ps[ 0 ].offsetTop - ps[ 1 ].offsetTop ) < 4,
				verticalPlain: slots.some( ( s ) => ! s.classList.contains( 'minn-slot-flex' ) && /Stacked/.test( s.textContent ) ),
			};
		} );
		t.check( 'flex slot renders as a row', st.display === 'flex' && st.sameRow, JSON.stringify( st ) );
		t.check( 'justification carries over', st.justify === 'center', st.justify );
		t.check( 'vertical-orientation group stays plain', st.verticalPlain );

		// Untouched save round-trips byte-identical through the flex slot.
		await page.click( '#minn-editor-body' );
		await page.keyboard.press( 'Meta+s' );
		await page.waitForTimeout( 3000 );
		let raw = await readRaw( id );
		t.check( 'untouched flex group saves byte-identical', raw.indexOf( FLEX ) !== -1 );

		// Typing inside a row child saves just the text change.
		await page.evaluate( () => {
			const p = [ ...document.querySelectorAll( '.minn-slot-flex > p' ) ].find( ( n ) => n.textContent === 'Residential' );
			const r = document.createRange();
			r.selectNodeContents( p );
			r.collapse( false );
			const sel = getSelection();
			sel.removeAllRanges();
			sel.addRange( r );
			p.closest( '[contenteditable="true"]' ).focus( { preventScroll: true } );
		} );
		await page.keyboard.type( ' home', { delay: 40 } );
		await page.keyboard.press( 'Meta+s' );
		await page.waitForTimeout( 3000 );
		raw = await readRaw( id );
		const expected = FLEX.replace( '<p>Residential</p>', '<p>Residential home</p>' );
		t.check( 'row-child edit saves byte-exact', raw.indexOf( expected ) !== -1 );
	} finally {
		await deletePost( page, id );
	}

	await t.done( browser, errors );
} )();
