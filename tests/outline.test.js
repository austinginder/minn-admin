/**
 * Outline panel: headings as a clickable ToC in the editor sidebar — rows
 * appear/update live on the stats cadence, indent rides heading depth, click
 * scrolls to the heading with an overlay ping that must NEVER touch the
 * typing surface (no classes/styles that could serialize), and the card
 * hides when a post has no headings.
 */
const { launch, login, createPost, deletePost, openEditor, freshParagraph, reporter } = require( './helpers' );

const CONTENT = '<!-- wp:heading --><h2 class="wp-block-heading">First section</h2><!-- /wp:heading -->'
	+ '<!-- wp:paragraph --><p>Some prose under the first section heading.</p><!-- /wp:paragraph -->'
	+ '<!-- wp:heading {"level":3} --><h3 class="wp-block-heading">Nested subsection</h3><!-- /wp:heading -->'
	+ '<!-- wp:paragraph --><p>' + 'Long filler text to make the editor scroll well past the sidebar cards. '.repeat( 400 ) + '</p><!-- /wp:paragraph -->'
	+ '<!-- wp:heading --><h2 class="wp-block-heading">Second section</h2><!-- /wp:heading -->'
	+ '<!-- wp:paragraph --><p>Closing prose.</p><!-- /wp:paragraph -->';

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'outline' );
	await login( page );

	const id = await createPost( page, { title: 'Outline probe', content: CONTENT, status: 'draft' } );
	await openEditor( page, id );
	await page.waitForSelector( '#minn-outline-card:not([hidden])', { timeout: 10000 } );

	/* ===== Initial rows ===== */
	const rows = await page.$$eval( '.minn-outline-row', ( els ) => els.map( ( e ) => ( {
		text: e.textContent.trim(), lvl: e.style.getPropertyValue( '--olvl' ), tag: e.title,
	} ) ) );
	t.check( 'three headings listed in order', rows.map( ( r ) => r.text ).join( '|' ) === 'First section|Nested subsection|Second section', JSON.stringify( rows.map( ( r ) => r.text ) ) );
	t.check( 'h3 indents one level deeper than h2', rows[ 0 ].lvl === '0' && rows[ 1 ].lvl === '1' && rows[ 2 ].lvl === '0', JSON.stringify( rows.map( ( r ) => r.lvl ) ) );

	/* ===== Live update: type a new markdown heading ===== */
	await freshParagraph( page );
	await page.keyboard.type( '## Brand new heading', { delay: 20 } );
	await page.waitForFunction( () => document.querySelectorAll( '.minn-outline-row' ).length === 4, { timeout: 8000 } );
	const last = await page.$$eval( '.minn-outline-row', ( els ) => els[ els.length - 1 ].textContent.trim() );
	t.check( 'typed heading appears in the outline live', last === 'Brand new heading', last );

	/* ===== Click scrolls + pings without touching the surface ===== */
	const beforeTop = await page.evaluate( () => document.querySelector( '#minn-editor-body h2' ).getBoundingClientRect().top );
	await page.click( '.minn-outline-row' ); // first section — we're at the bottom now
	await page.waitForSelector( '.minn-outline-ping', { timeout: 4000 } );
	// Mid-smooth-scroll, the ping must stay GLUED to the heading (constant
	// offset) — it lives in the scroller's coordinate space, it never chases.
	const glue = await page.evaluate( () => new Promise( ( resolve ) => {
		const offset = () => {
			const ping = document.querySelector( '.minn-outline-ping' );
			const h = document.querySelector( '#minn-editor-body h2' );
			return ping && h ? Math.round( ping.getBoundingClientRect().top - h.getBoundingClientRect().top ) : null;
		};
		const samples = [ offset() ];
		let n = 0;
		const tick = () => {
			samples.push( offset() );
			if ( ++n < 20 ) requestAnimationFrame( tick );
			else resolve( { inScroller: document.querySelector( '.minn-outline-ping' ).parentElement.classList.contains( 'minn-scroll' ), samples: [ ...new Set( samples.filter( ( s ) => s !== null ) ) ] } );
		};
		requestAnimationFrame( tick );
	} ) );
	t.check( 'ping rides the scroll glued to the heading (no chase lag)', glue.inScroller && glue.samples.length === 1, JSON.stringify( glue ) );
	await page.waitForTimeout( 1200 );
	const afterTop = await page.evaluate( () => document.querySelector( '#minn-editor-body h2' ).getBoundingClientRect().top );
	t.check( 'click scrolls the heading into view', Math.abs( afterTop ) < Math.abs( beforeTop ) && afterTop > 0, `before=${ Math.round( beforeTop ) } after=${ Math.round( afterTop ) }` );
	const clean = await page.evaluate( () => {
		const h = document.querySelector( '#minn-editor-body h2' );
		return { cls: h.className, style: h.getAttribute( 'style' ) || '' };
	} );
	t.check( 'heading untouched by the ping (no class/style leak)', clean.cls === 'wp-block-heading' && clean.style === '', JSON.stringify( clean ) );
	await page.waitForFunction( () => ! document.querySelector( '.minn-outline-ping' ), { timeout: 4000 } );
	t.check( 'ping overlay removes itself', true, '' );

	/* ===== Sticky: deep scroll keeps the outline pinned in view ===== */
	// The app scrolls in .minn-scroll — scrollIntoView on sticky chrome is a
	// no-op. Mid-scroll the card pins near the scrollport top; at the very
	// bottom it correctly parks at its container's end (still visible).
	const pin = await page.evaluate( () => {
		const sc = document.querySelector( '.minn-scroll' );
		sc.scrollTop = Math.round( ( sc.scrollHeight - sc.clientHeight ) * 0.8 );
		const r = document.querySelector( '#minn-outline-card' ).getBoundingClientRect();
		const side = document.querySelector( '.minn-editor-side' );
		sc.scrollTop = 999999;
		const r2 = document.querySelector( '#minn-outline-card' ).getBoundingClientRect();
		return {
			midTop: Math.round( r.top ), midVisible: r.bottom > 0 && r.top < innerHeight,
			endVisible: r2.bottom > 0 && r2.top < innerHeight,
			last: side.lastElementChild.id === 'minn-outline-card',
		};
	} );
	t.check( 'outline is the last sidebar card (sticky-safe)', pin.last, '' );
	t.check( 'outline pins near the top mid-scroll', pin.midVisible && pin.midTop >= 0 && pin.midTop < 200, JSON.stringify( pin ) );
	t.check( 'outline still visible at the very bottom', pin.endVisible, JSON.stringify( pin ) );

	/* ===== Outline mode: nav gone, only the Outline card survives ===== */
	await page.keyboard.press( 'Meta+Shift+O' );
	await page.waitForTimeout( 400 );
	const om = await page.evaluate( () => ( {
		cls: document.body.classList.contains( 'minn-outline-mode' ),
		nav: document.querySelector( '.minn-sidebar' ).offsetWidth,
		visibleCards: [ ...document.querySelectorAll( '#minn-editor-side > *' ) ]
			.filter( ( el ) => el.checkVisibility && el.checkVisibility() )
			.map( ( el ) => el.id || el.className.split( ' ' )[ 0 ] ),
	} ) );
	t.check( 'outline mode hides nav and every card but the Outline', om.cls && om.nav < 10 && om.visibleCards.length === 1 && om.visibleCards[ 0 ] === 'minn-outline-card', JSON.stringify( om ) );
	await openEditor( page, id );
	t.check( 'outline mode persists across loads', await page.evaluate( () => document.body.classList.contains( 'minn-outline-mode' ) ), '' );
	await page.keyboard.press( 'Meta+Shift+O' );
	await page.waitForTimeout( 400 );
	t.check( 'toggle off restores the full sidebar', await page.evaluate( () =>
		! document.body.classList.contains( 'minn-outline-mode' )
		&& document.querySelector( '.minn-sidebar' ).offsetWidth > 100
		&& [ ...document.querySelectorAll( '#minn-editor-side > .minn-side-card' ) ].filter( ( el ) => el.checkVisibility() ).length > 1 ), '' );

	/* ===== No headings → card hidden ===== */
	const bare = await createPost( page, { title: 'No headings', content: '<!-- wp:paragraph --><p>Just prose.</p><!-- /wp:paragraph -->', status: 'draft' } );
	await openEditor( page, bare );
	await page.waitForTimeout( 800 );
	t.check( 'card hidden when no headings', await page.$eval( '#minn-outline-card', ( el ) => el.hidden ), '' );

	await deletePost( page, id );
	await deletePost( page, bare );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
