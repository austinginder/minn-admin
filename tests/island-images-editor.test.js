/**
 * Island Images editor: reorder / remove / add for gallery-shaped blocks.
 *
 * The whole design hangs on byte-identity: units are permuted as verbatim
 * substrings and the "ids" attr permutes with them, so every reorder/remove
 * expectation here is a STRING the test builds itself from the same
 * constants, compared strictly against the SAVED raw. Covers both unit
 * shapes: HTML siblings (Jetpack-slideshow-shaped <li> run, unregistered —
 * islands preserve those) and inner wp:image blocks (core gallery, where
 * captions must travel with their unit).
 */
const { BASE, launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

const S = [
	{ id: 901, img: 'gal-red.png' },
	{ id: 902, img: 'gal-green.png' },
	{ id: 903, img: 'gal-blue.png' },
];
const slide = ( s ) => `<li class="wp-block-jetpack-slideshow_slide swiper-slide"><figure><img alt="" class="wp-block-jetpack-slideshow_image wp-image-${ s.id }" data-id="${ s.id }" data-aspect-ratio="1140 / 760" src="${ BASE }/wp-content/uploads/${ s.img }"/></figure></li>`;
const JP = ( set ) => `<!-- wp:jetpack/slideshow {"ids":[${ set.map( ( s ) => s.id ).join( ',' ) }],"sizeSlug":"large"} -->\n<div class="wp-block-jetpack-slideshow" data-effect="slide"><div class="wp-block-jetpack-slideshow_container swiper"><ul class="wp-block-jetpack-slideshow_swiper-wrapper swiper-wrapper">${ set.map( slide ).join( '' ) }</ul></div></div>\n<!-- /wp:jetpack/slideshow -->`;

const G = [
	{ id: 911, img: 'gal-red.png', cap: 'Red caption' },
	{ id: 912, img: 'gal-green.png', cap: 'Green caption' },
];
const gunit = ( g ) => `<!-- wp:image {"id":${ g.id },"sizeSlug":"large","linkDestination":"none"} -->\n<figure class="wp-block-image size-large"><img src="${ BASE }/wp-content/uploads/${ g.img }" alt="" class="wp-image-${ g.id }"/><figcaption class="wp-element-caption">${ g.cap }</figcaption></figure>\n<!-- /wp:image -->`;
const GAL = ( set ) => `<!-- wp:gallery {"linkTo":"none"} -->\n<figure class="wp-block-gallery has-nested-images columns-default is-cropped">${ set.map( gunit ).join( '\n\n' ) }</figure>\n<!-- /wp:gallery -->`;

const GRP = '<!-- wp:group {"layout":{"type":"constrained"}} -->\n<div class="wp-block-group">' + JP( S ) + '</div>\n<!-- /wp:group -->';
const CONTENT = JP( S ) + '\n\n' + GAL( G ) + '\n\n' + GRP + '\n\n<!-- wp:paragraph -->\n<p>Tail.</p>\n<!-- /wp:paragraph -->';

( async () => {
	const t = reporter( 'island-images-editor' );
	const { browser, page, errors } = await launch();
	await login( page );

	const openIsland = async ( blockSel ) => {
		// The async preview swap can replace the island node — retry the
		// chip click until the inspector actually mounts (rule-51 class).
		for ( let i = 0; i < 8; i++ ) {
			try {
				await page.click( `.minn-block-island[data-block="${ blockSel }"] .minn-island-chip` );
				await page.waitForSelector( '#minn-insp-imgedit', { timeout: 6000 } );
				return true;
			} catch ( e ) { await page.waitForTimeout( 1200 ); }
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
		id = await createPost( page, { title: 'Images editor probe', content: CONTENT } );
		t.check( 'fixture post created', id > 0, String( id ) );
		await openEditor( page, id );
		await page.waitForSelector( '.minn-block-island[data-block="jetpack/slideshow"]', { timeout: 20000 } );
		await page.waitForTimeout( 2500 );

		// --- Reorder (HTML-sibling units) ---
		t.check( 'slideshow inspector offers Edit images', await openIsland( 'jetpack/slideshow' ) );
		const inspState = await page.evaluate( () => ( {
			rows: document.querySelectorAll( '.minn-insp-img-row' ).length,
			head: ( document.querySelector( '.minn-insp-imghead' ) || {} ).textContent || '',
		} ) );
		t.check( 'inspector shows count, no per-image rows', inspState.rows === 0 && /Images · 3/.test( inspState.head ), JSON.stringify( inspState ) );
		await page.click( '#minn-insp-imgedit' );
		await page.waitForSelector( '.minn-imgedit-tile', { timeout: 8000 } );
		t.check( 'modal shows three tiles', ( await page.$$( '.minn-imgedit-tile' ) ).length === 3 );
		await page.click( '[data-mv="0:1"]' ); // A,B,C -> B,A,C
		const firstThumb = await page.evaluate( () => document.querySelector( '.minn-imgedit-tile img' ).src );
		t.check( 'tiles reordered in the grid', firstThumb.includes( 'gal-green' ), firstThumb );
		await page.click( '#minn-imgedit-apply' );
		await page.waitForTimeout( 1500 );
		let raw = await saveAndRead( id );
		const wantReorder = JP( [ S[ 1 ], S[ 0 ], S[ 2 ] ] );
		{
			const got = slice( raw, 'jetpack/slideshow' );
			t.check( 'reorder is byte-exact (units + ids permuted)', got === wantReorder, got === wantReorder ? '' : diffAt( got, wantReorder ) );
		}

		// --- Remove ---
		t.check( 'inspector reopens after reorder', await openIsland( 'jetpack/slideshow' ) );
		await page.click( '#minn-insp-imgedit' );
		await page.waitForSelector( '.minn-imgedit-tile', { timeout: 8000 } );
		await page.click( '[data-x="1"]' ); // B,A,C -> B,C
		t.check( 'tile removed from the grid', ( await page.$$( '.minn-imgedit-tile' ) ).length === 2 );
		await page.click( '#minn-imgedit-apply' );
		await page.waitForTimeout( 1500 );
		raw = await saveAndRead( id );
		const wantRemove = JP( [ S[ 1 ], S[ 2 ] ] );
		{
			const got = slice( raw, 'jetpack/slideshow' );
			t.check( 'remove is byte-exact', got === wantRemove, got === wantRemove ? '' : diffAt( got, wantRemove ) );
		}

		// --- Duplicate a tile (copy lands right after, byte-identical) ---
		t.check( 'inspector reopens for duplicate', await openIsland( 'jetpack/slideshow' ) );
		await page.click( '#minn-insp-imgedit' );
		await page.waitForSelector( '.minn-imgedit-tile', { timeout: 8000 } );
		await page.click( '[data-dup="0"]' );
		t.check( 'duplicate adds a tile', ( await page.$$( '.minn-imgedit-tile' ) ).length === 3 );
		await page.click( '#minn-imgedit-apply' );
		await page.waitForTimeout( 1500 );
		raw = await saveAndRead( id );
		{
			const got = slice( raw, 'jetpack/slideshow' );
			const wantDup = JP( [ S[ 1 ], S[ 1 ], S[ 2 ] ] );
			t.check( 'duplicate is byte-exact (unit + id repeated)', got === wantDup, got === wantDup ? '' : diffAt( got, wantDup ) );
		}
		// Undo the duplicate so the following steps keep their expectations.
		t.check( 'inspector reopens after duplicate', await openIsland( 'jetpack/slideshow' ) );
		await page.click( '#minn-insp-imgedit' );
		await page.waitForSelector( '.minn-imgedit-tile', { timeout: 8000 } );
		await page.click( '[data-x="1"]' );
		await page.click( '#minn-imgedit-apply' );
		await page.waitForTimeout( 1500 );
		raw = await saveAndRead( id );
		t.check( 'removing the copy restores the earlier markup', slice( raw, 'jetpack/slideshow' ) === wantRemove );

		// --- Replace via tile click (keeps position, swaps image) ---
		t.check( 'inspector reopens for replace', await openIsland( 'jetpack/slideshow' ) );
		await page.click( '#minn-insp-imgedit' );
		await page.waitForSelector( '.minn-imgedit-tile', { timeout: 8000 } );
		const beforeThumb = await page.evaluate( () => document.querySelector( '.minn-imgedit-tile[data-i="0"] img' ).src );
		await page.click( '.minn-imgedit-tile[data-i="0"]' );
		await page.waitForSelector( '.minn-picker-item', { timeout: 10000 } );
		await page.click( '.minn-picker-item' );
		await page.waitForFunction( ( prev ) => {
			const img = document.querySelector( '.minn-imgedit-tile[data-i="0"] img' );
			return img && img.src !== prev;
		}, beforeThumb, { timeout: 8000 } );
		await page.click( '#minn-imgedit-apply' );
		await page.waitForTimeout( 1500 );
		raw = await saveAndRead( id );
		const jpR = slice( raw, 'jetpack/slideshow' );
		const idsR = ( jpR.match( /"ids":\[([\d,]+)\]/ ) || [] )[ 1 ] || '';
		const replacedId = idsR.split( ',' )[ 0 ];
		t.check( 'replace retargets ids[0]', !! replacedId && replacedId !== '902' && idsR.split( ',' )[ 1 ] === '903', idsR );
		t.check( 'replaced unit carries new id, old id gone', jpR.includes( 'wp-image-' + replacedId ) && ! jpR.includes( 'wp-image-902' ) );
		t.check( 'untouched unit byte-identical through replace', jpR.includes( slide( S[ 2 ] ) ) );

		// --- Add via the media picker ---
		t.check( 'inspector reopens after remove', await openIsland( 'jetpack/slideshow' ) );
		await page.click( '#minn-insp-imgedit' );
		await page.waitForSelector( '.minn-imgedit-tile', { timeout: 8000 } );
		await page.click( '#minn-imgedit-add' );
		await page.waitForSelector( '.minn-picker-item', { timeout: 10000 } );
		await page.click( '.minn-picker-item' );
		await page.click( '#minn-picker-done' );
		await page.waitForSelector( '.minn-imgedit-new', { timeout: 8000 } );
		t.check( 'picked image joins the grid as new', ( await page.$$( '.minn-imgedit-tile' ) ).length === 3 );
		await page.click( '#minn-imgedit-apply' );
		await page.waitForTimeout( 1500 );
		raw = await saveAndRead( id );
		const jp = slice( raw, 'jetpack/slideshow' );
		const idsM = jp.match( /"ids":\[([\d,]+)\]/ );
		const newId = idsM ? idsM[ 1 ].split( ',' )[ 2 ] : '';
		t.check( 'ids array grew to three with a real attachment id', !! newId && parseInt( newId, 10 ) > 0 && idsM[ 1 ].split( ',' )[ 1 ] === '903', idsM && idsM[ 1 ] );
		t.check( 'new unit carries the retargeted id', newId && jp.includes( 'wp-image-' + newId ) && jp.includes( 'data-id="' + newId + '"' ) );
		t.check( 'new unit sheds proto srcset and caption', ! /srcset=/.test( jp ) && ! /figcaption/.test( jp ) );
		t.check( 'existing units untouched by the add', jp.includes( slide( S[ 2 ] ) ) );

		// --- Core gallery (inner-block units, captions travel) ---
		// Registered blocks get WP's one-time re-serialization on first save
		// (rule-19 normalization), so the expectation permutes the SAVED
		// markup's own unit substrings rather than the fixture constants.
		const galBefore = slice( raw, 'gallery' );
		const chunks = galBefore.match( /<!-- wp:image[\s\S]*?<!-- \/wp:image -->/g ) || [];
		t.check( 'saved gallery still has two image units', chunks.length === 2 );
		const wantGal = galBefore.replace( chunks[ 0 ], '@@MINN-SWAP@@' ).replace( chunks[ 1 ], chunks[ 0 ] ).replace( '@@MINN-SWAP@@', chunks[ 1 ] );
		t.check( 'gallery inspector offers Edit images', await openIsland( 'gallery' ) );
		await page.click( '#minn-insp-imgedit' );
		await page.waitForSelector( '.minn-imgedit-tile', { timeout: 8000 } );
		await page.click( '[data-mv="0:1"]' ); // Red,Green -> Green,Red
		await page.click( '#minn-imgedit-apply' );
		await page.waitForTimeout( 1500 );
		raw = await saveAndRead( id );
		{
			const got = slice( raw, 'gallery' );
			t.check( 'gallery reorder byte-exact, captions travel with units', got === wantGal, got === wantGal ? '' : diffAt( got, wantGal ) );
		}

		// --- Click an image in the preview to open the tooling directly ---
		// REAL MOUSE CLICK, never el.click(): a synthetic click skips the
		// press/release cycle, and this doorway exists precisely because a
		// real press re-renders the island between mousedown and mouseup
		// (the click then lands on the container, not the image).
		await page.keyboard.press( 'Escape' );
		await page.waitForTimeout( 400 );
		const spot = await page.evaluate( () => {
			const isl = document.querySelector( '.minn-block-island[data-block="jetpack/slideshow"][data-imgtool="edit"]' );
			const img = isl && isl.querySelector( '.minn-island-preview img' );
			if ( ! img ) return null;
			img.scrollIntoView( { block: 'center' } );
			const r = img.getBoundingClientRect();
			return { x: r.left + r.width / 2, y: r.top + r.height / 2, src: img.getAttribute( 'src' ) };
		} );
		t.check( 'slideshow island advertises image tooling', !! spot, JSON.stringify( spot ) );
		// The hover overlay names the action and must never swallow the press
		// it advertises, nor count as content.
		const badge = await page.evaluate( () => {
			const isl = document.querySelector( '.minn-block-island[data-imgtool="edit"]' );
			const b = isl && isl.querySelector( '.minn-imgtool-badge' );
			if ( ! b ) return null;
			return { text: b.textContent.trim(), pe: getComputedStyle( b ).pointerEvents };
		} );
		t.check( 'image overlay reads Edit images and is click-through', badge && badge.text === 'Edit images' && badge.pe === 'none', JSON.stringify( badge ) );
		if ( spot ) await page.mouse.click( spot.x, spot.y );
		const opened = await page.waitForSelector( '.minn-imgedit-tile', { timeout: 8000 } ).then( () => true ).catch( () => false );
		t.check( 'real mouse click on the image opens the editor', opened );
		const focused = await page.evaluate( () => {
			const f = document.querySelector( '.minn-imgedit-tile.flash' );
			return f ? parseInt( f.dataset.i, 10 ) : -1;
		} );
		t.check( 'clicked image opens the editor with its tile flagged', focused >= 0, String( focused ) );
		await page.click( '#minn-imgedit-cancel' );
		await page.waitForTimeout( 400 );

		// --- Containers list no images (each nested block manages its own) ---
		for ( let i = 0; i < 8; i++ ) {
			try {
				await page.click( '.minn-block-island[data-block="group"] .minn-island-chip' );
				await page.waitForSelector( '#minn-insp-close', { timeout: 5000 } );
				break;
			} catch ( e ) { await page.waitForTimeout( 1200 ); }
		}
		const grp = await page.evaluate( () => ( {
			open: !! document.querySelector( '#minn-insp-close' ),
			rows: document.querySelectorAll( '.minn-insp-img-row' ).length,
			edit: !! document.querySelector( '#minn-insp-imgedit' ),
		} ) );
		t.check( 'container inspector lists no images', grp.open && grp.rows === 0 && ! grp.edit, JSON.stringify( grp ) );
	} finally {
		await deletePost( page, id );
	}

	await t.done( browser, errors );
} )();
