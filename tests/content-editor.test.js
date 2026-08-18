/**
 * Content editor modal (nested blocks — GitHub #12).
 *
 * A multi-child island used to unroll in the 320px ⚙ popover as text plus a
 * dozen schema inputs PER CHILD. Now the popover shows a "Content · N" summary
 * with an Edit content… doorway, the card itself opens the modal (real mouse,
 * like the images editor), and the modal puts text first with each child's
 * schema form tucked behind a Settings disclosure.
 *
 * Two island shapes, because their write paths differ:
 * - acme/stats (unregistered wrapper, core paragraph children): a GENERIC
 *   island — Apply rides replaceIsland, expectations are byte-exact strings
 *   the test builds from its own constants.
 * - core/group with a prose child + styled children: a SLOT island — Apply
 *   must REBUILD the container DOM (patching stored raw alone gets re-flushed
 *   away by the next slot edit; the regression check types in the slot after
 *   a modal apply and expects BOTH edits to survive the save).
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

const P = ( txt ) => `<!-- wp:paragraph {"fontSize":"small"} -->\n<p class="has-small-font-size">${ txt }</p>\n<!-- /wp:paragraph -->`;
const STATS = ( a, b, c ) => `<!-- wp:acme/stats -->\n<div class="wp-block-acme-stats">\n${ P( a ) }\n\n${ P( b ) }\n\n${ P( c ) }\n</div>\n<!-- /wp:acme/stats -->`;

const PLAIN = '<!-- wp:paragraph -->\n<p>A plain intro line.</p>\n<!-- /wp:paragraph -->';
const GRP = ( a, b, c ) => `<!-- wp:group {"layout":{"type":"constrained"}} -->\n<div class="wp-block-group">${ PLAIN }\n\n${ P( a ) }\n\n${ P( b ) }\n\n${ P( c ) }</div>\n<!-- /wp:group -->`;

// Gallery with the safe-settings form (columns/crop regenerate figure
// classes on Apply; expectations are byte-exact strings built here).
const GIMG = ( id ) => `<!-- wp:image {"id":${ id },"sizeSlug":"large","linkDestination":"none"} -->\n<figure class="wp-block-image size-large"><img src="/wp-content/uploads/gal-red.png" alt="" class="wp-image-${ id }"/></figure>\n<!-- /wp:image -->`;
const GAL = ( attrs, cls ) => `<!-- wp:gallery ${ attrs } -->\n<figure class="${ cls }">${ GIMG( 901 ) }\n\n${ GIMG( 902 ) }</figure>\n<!-- /wp:gallery -->`;

const CONTENT = STATS( 'Type:', 'Residential', 'Location:' ) + '\n\n' + GRP( 'Completed:', '2024', 'Size:' ) + '\n\n' + GAL( '{"linkTo":"none"}', 'wp-block-gallery has-nested-images columns-default is-cropped' ) + '\n\n<!-- wp:paragraph -->\n<p>Tail.</p>\n<!-- /wp:paragraph -->';

( async () => {
	const t = reporter( 'content-editor' );
	const { browser, page, errors } = await launch();
	await login( page );

	// The async preview swap can replace the island node — retry the chip
	// click until the popover actually mounts (rule-51 class).
	const openChip = async ( sel, waitFor ) => {
		for ( let i = 0; i < 8; i++ ) {
			try {
				await page.click( sel );
				await page.waitForSelector( waitFor, { timeout: 6000 } );
				return true;
			} catch ( e ) { await page.waitForTimeout( 1200 ); }
		}
		return false;
	};
	// Open a content card from its explicit edge badge. Text inside the preview
	// is intentionally editable in place and must win a press.
	const openCard = async ( sel, waitFor = '.minn-cted-card' ) => {
		for ( let i = 0; i < 8; i++ ) {
			await page.keyboard.press( 'Escape' ).catch( () => {} );
			const badge = page.locator( sel + ' [data-ctedbadge]' ).first();
			if ( await badge.count() ) {
				if ( i === 0 ) {
					// The first attempt is a real press, which pins the public
					// interaction. If the async preview swaps under Playwright,
					// later attempts dispatch the same delegated mousedown event.
					await badge.click( { timeout: 6000 } ).catch( () => {} );
				} else {
					await page.evaluate( ( selector ) => {
						const b = document.querySelector( selector + ' [data-ctedbadge]' );
						if ( b ) b.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true, button: 0 } ) );
					}, sel ).catch( () => {} );
				}
			}
			try {
				await page.waitForSelector( waitFor, { timeout: 6000 } );
				return true;
			} catch ( e ) { await page.waitForTimeout( 1200 ); }
		}
		return false;
	};
	const openAndClickMove = async ( sel, move ) => {
		for ( let i = 0; i < 8; i++ ) {
			await page.keyboard.press( 'Escape' ).catch( () => {} );
			const opened = await page.evaluate( ( selector ) => {
				const badge = document.querySelector( selector + ' [data-ctedbadge]' );
				if ( ! badge ) return false;
				// The content doorway deliberately binds mousedown because the
				// async preview can be replaced before a browser click is emitted.
				badge.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true, button: 0 } ) );
				return true;
			}, sel ).catch( () => false );
			if ( opened && await page.waitForSelector( move, { timeout: 6000 } ).catch( () => null ) ) {
				// Query again at click time: the asynchronous preview can replace
				// the modal node between waitForSelector and a handle click.
				const clicked = await page.evaluate( ( selector ) => {
					const control = document.querySelector( selector );
					if ( ! control ) return false;
					control.click();
					return true;
				}, move ).catch( () => false );
				if ( clicked ) return true;
			}
			await page.waitForTimeout( 1200 );
		}
		return false;
	};
	const saveAndRead = async ( id ) => {
		await page.keyboard.press( 'Meta+s' );
		await page.waitForTimeout( 3000 );
		return page.evaluate( async ( pid ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_cb=' + Math.random(), {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			const j = await r.json();
			return ( j.content && j.content.raw ) || '';
		}, id );
	};
	const diffAt = ( a, b ) => {
		let i = 0;
		while ( i < a.length && i < b.length && a[ i ] === b[ i ] ) i++;
		return 'diff at ' + i + ': got "' + a.slice( Math.max( 0, i - 30 ), i + 50 ) + '" want "' + b.slice( Math.max( 0, i - 30 ), i + 50 ) + '"';
	};
	const slice = ( raw, name ) => {
		const m = raw.match( new RegExp( '<!-- wp:' + name + '[\\s\\S]*?<!-- /wp:' + name + ' -->' ) );
		return m ? m[ 0 ] : '';
	};

	let id = 0;
	try {
		id = await createPost( page, { title: 'Content editor probe', content: CONTENT } );
		t.check( 'fixture post created', id > 0, String( id ) );
		await openEditor( page, id );
		await page.waitForSelector( '.minn-block-island[data-block="acme/stats"]', { timeout: 20000 } );
		await page.waitForTimeout( 2500 );

		// --- Stamp + badge on the generic island ---
		const stamp = await page.evaluate( () => {
			const isl = document.querySelector( '.minn-block-island[data-block="acme/stats"]' );
			const badge = isl && isl.querySelector( '[data-ctedbadge]' );
			return { cted: isl && isl.dataset.cted, badge: badge ? badge.textContent : '' };
		} );
		t.check( 'stats island stamped data-cted="3"', stamp.cted === '3', JSON.stringify( stamp ) );
		t.check( 'badge names the action and the count', /Edit content · 3/.test( stamp.badge ), stamp.badge );
		t.check( 'slot group is NOT stamped (its children are live DOM)', await page.evaluate( () =>
			! document.querySelector( '.minn-slot-island[data-cted]' ) ) );

		// --- Card doorway: editable text wins; the edge badge opens ---
		const p2 = await page.$( '.minn-block-island[data-block="acme/stats"] .minn-island-preview p:nth-of-type(2)' );
		const box = await p2.boundingBox();
		await page.mouse.click( box.x + box.width / 2, box.y + box.height / 2 );
		await page.waitForTimeout( 300 );
		t.check( 'editable text wins the card press', ( await page.$$( '.minn-cted-card' ) ).length === 0 );
		t.check( 'card badge opens the content editor', await openCard( '.minn-block-island[data-block="acme/stats"]' ) );
		const modal1 = await page.evaluate( () => ( {
			cards: document.querySelectorAll( '.minn-cted-card' ).length,
			texts: Array.from( document.querySelectorAll( '.minn-cted-card [data-insptext]' ) ).map( ( el ) => el.value ),
			panelsHidden: Array.from( document.querySelectorAll( '.minn-cted-set' ) ).every( ( el ) => el.hidden ),
		} ) );
		t.check( 'modal shows one card per child, text first', modal1.cards === 3
			&& modal1.texts.join( '|' ) === 'Type:|Residential|Location:', JSON.stringify( modal1 ) );
		t.check( 'settings are collapsed by default', modal1.panelsHidden );

		// --- Settings disclosure holds the schema form ---
		await page.click( '[data-ctset="0"]' );
		const setState = await page.evaluate( () => {
			const panel = document.querySelector( '[data-ctset-panel="0"]' );
			return { hidden: panel.hidden, fields: panel.querySelectorAll( '[data-insp]' ).length };
		} );
		t.check( 'settings disclosure opens the attr form', ! setState.hidden && setState.fields >= 3, JSON.stringify( setState ) );

		// --- Text edit → Apply → byte-exact save ---
		await page.fill( '.minn-cted-card[data-ci="0"] [data-insptext]', 'Category:' );
		await page.click( '#minn-cted-apply' );
		await page.waitForTimeout( 1500 );
		let raw = await saveAndRead( id );
		{
			const want = STATS( 'Category:', 'Residential', 'Location:' );
			const got = slice( raw, 'acme/stats' );
			t.check( 'text edit is byte-exact (untouched children identical)', got === want, got === want ? '' : diffAt( got, want ) );
		}

		// --- Reorder → Apply → byte-exact swap ---
		t.check( 'reorder control survives the preview swap', await openAndClickMove(
			'.minn-block-island[data-block="acme/stats"]',
			'.minn-cted-card[data-ci="0"] [data-cmove="0:1"]'
		) );
		const orderNow = await page.evaluate( () =>
			Array.from( document.querySelectorAll( '.minn-cted-card [data-insptext]' ) ).map( ( el ) => el.value ).join( '|' ) );
		t.check( 'cards reorder in the modal', orderNow === 'Residential|Category:|Location:', orderNow );
		await page.click( '#minn-cted-apply' );
		await page.waitForTimeout( 1500 );
		raw = await saveAndRead( id );
		{
			const want = STATS( 'Residential', 'Category:', 'Location:' );
			const got = slice( raw, 'acme/stats' );
			t.check( 'reorder is byte-exact', got === want, got === want ? '' : diffAt( got, want ) );
		}

		// --- Cancel discards ---
		await openCard( '.minn-block-island[data-block="acme/stats"]' );
		await page.fill( '.minn-cted-card[data-ci="0"] [data-insptext]', 'Discarded' );
		await page.click( '#minn-cted-cancel' );
		await page.waitForTimeout( 800 );
		raw = await saveAndRead( id );
		t.check( 'cancel discards the edit', slice( raw, 'acme/stats' ) === STATS( 'Residential', 'Category:', 'Location:' ) );

		// --- Popover: summary + doorway instead of the wall ---
		t.check( 'stats popover mounts', await openChip( '.minn-block-island[data-block="acme/stats"] .minn-island-chip', '#minn-insp-cted' ) );
		const popState = await page.evaluate( () => ( {
			head: ( document.querySelector( '.minn-inspector .minn-insp-imghead' ) || {} ).textContent || '',
			walls: document.querySelectorAll( '.minn-inspector [data-insptext]' ).length,
			// Nested chips stack in one corner — the open popover's island
			// must wear the highlight so 2-vs-14-blocks reads as two blocks.
			target: ( document.querySelector( '.minn-insp-target' ) || {} ).dataset,
		} ) );
		t.check( 'popover shows a summary, not per-child sections', /Content · 3 blocks/.test( popState.head ) && popState.walls === 0, JSON.stringify( popState ) );
		t.check( 'popover highlights its island', !! popState.target && popState.target.block === 'acme/stats', JSON.stringify( popState.target ) );
		await page.click( '#minn-insp-cted' );
		await page.waitForSelector( '.minn-cted-card', { timeout: 8000 } );
		t.check( 'popover doorway opens the modal', ( await page.$$( '.minn-cted-card' ) ).length === 3 );
		await page.click( '#minn-cted-cancel' );

		// --- Slot island (a nested group): popover doorway + REBUILD apply ---
		t.check( 'group popover offers Edit content', await openChip( '.minn-slot-island[data-block="group"] .minn-island-chip', '#minn-insp-cted' ) );
		const grpHead = await page.evaluate( () => ( document.querySelector( '.minn-inspector .minn-insp-imghead' ) || {} ).textContent || '' );
		t.check( 'group summary counts all four children', /Content · 4 blocks/.test( grpHead ), grpHead );
		await page.click( '#minn-insp-cted' );
		await page.waitForSelector( '.minn-cted-card', { timeout: 8000 } );
		const grpTexts = await page.evaluate( () =>
			Array.from( document.querySelectorAll( '.minn-cted-card [data-insptext]' ) ).map( ( el ) => el.value ).join( '|' ) );
		t.check( 'group modal lists prose and styled children', grpTexts === 'A plain intro line.|Completed:|2024|Size:', grpTexts );
		await page.fill( '.minn-cted-card[data-ci="3"] [data-insptext]', 'Sq. footage:' );
		await page.click( '#minn-cted-apply' );
		await page.waitForTimeout( 2500 );
		const domAfter = await page.evaluate( () => {
			const grp = document.querySelector( '.minn-slot-island[data-block="group"]' );
			return grp ? grp.textContent : '';
		} );
		t.check( 'apply REBUILDS the slot DOM (edit visible in place)', /Sq\. footage:/.test( domAfter ) && ! /Size:/.test( domAfter ), domAfter.slice( 0, 200 ) );

		// --- The revert-bug regression: type in the slot AFTER a modal apply ---
		// A dirty slot re-splices from the DOM at save time; before the
		// rebuild fix, that re-emitted the OLD child bytes over the edit.
		await page.click( '.minn-slot-island[data-block="group"] .minn-slot > p' );
		await page.keyboard.press( 'End' );
		await page.keyboard.type( ' Now typed.', { delay: 25 } );
		await page.waitForTimeout( 500 );
		raw = await saveAndRead( id );
		t.check( 'modal edit survives a slot edit + save', /Sq\. footage:/.test( raw ) && ! /<p class="has-small-font-size">Size:/.test( raw ) );
		t.check( 'typed slot edit saved alongside it', /A plain intro line\. Now typed\./.test( raw ) );

		// --- Gallery safe settings: columns + crop regenerate figure classes ---
		t.check( 'gallery popover offers safe settings', await openChip( '.minn-block-island[data-block="gallery"] .minn-island-chip', '[data-insp="own:columns"]' ) );
		const galPop = await page.evaluate( () => ( {
			cols: !! document.querySelector( '[data-insp="own:columns"]' ),
			crop: !! document.querySelector( '[data-insp="own:imageCrop"]' ),
			rand: !! document.querySelector( '[data-insp="own:randomOrder"]' ),
			linkTo: !! document.querySelector( '[data-insp="own:linkTo"]' ),
			edit: !! document.querySelector( '#minn-insp-imgedit' ),
			apply: !! document.querySelector( '#minn-insp-apply' ),
		} ) );
		t.check( 'exactly the safe attrs, alongside Edit images + Apply',
			galPop.cols && galPop.crop && galPop.rand && ! galPop.linkTo && galPop.edit && galPop.apply, JSON.stringify( galPop ) );
		await page.fill( '[data-insp="own:columns"]', '3' );
		await page.uncheck( '[data-insp="own:imageCrop"]' );
		await page.click( '#minn-insp-apply' );
		await page.waitForTimeout( 1500 );
		raw = await saveAndRead( id );
		{
			const want = GAL( '{"linkTo":"none","columns":3,"imageCrop":false}', 'wp-block-gallery has-nested-images columns-3' );
			const got = slice( raw, 'gallery' );
			t.check( 'columns + crop are byte-exact (attrs AND figure classes)', got === want, got === want ? '' : diffAt( got, want ) );
		}
	} finally {
		await deletePost( page, id );
	}

	await t.done( browser, errors );
} )();
