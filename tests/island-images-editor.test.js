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

// Third unit shape: a slider that nests its run inside a viewport element and
// gives every image its own wrapper BLOCK (Carousel Slider's cb/slide, and
// most slick/swiper blocks). Unregistered on purpose — islands preserve those.
const C = [
	{ id: 921, img: 'gal-red.png' },
	{ id: 922, img: 'gal-green.png' },
	{ id: 923, img: 'gal-blue.png' },
];
const cslide = ( c ) => `<!-- wp:acme/slide -->\n<div class="wp-block-acme-slide">\n<!-- wp:image {"id":${ c.id },"sizeSlug":"large"} -->\n<figure class="wp-block-image size-large"><img src="${ BASE }/wp-content/uploads/${ c.img }" alt="" class="wp-image-${ c.id }"/></figure>\n<!-- /wp:image -->\n</div>\n<!-- /wp:acme/slide -->`;
const CAR = ( set ) => `<!-- wp:acme/carousel -->\n<div class="wp-block-acme-carousel" data-slick="{}">\n${ set.map( cslide ).join( '\n' ) }\n</div>\n<!-- /wp:acme/carousel -->`;

// A gallery big enough to overflow the tile grid: the row tracks must size
// from the tiles' own square, not collapse and let them overlap.
const MANY = Array.from( { length: 30 }, ( _, i ) => ( { id: 9500 + i, img: [ 'gal-red.png', 'gal-green.png', 'gal-blue.png' ][ i % 3 ] } ) );

// Fourth shape: no image tag anywhere. The pictures live in block settings
// (Gutenslider), with a mirror list on the parent that has to travel with the
// slides, string ids, and two addresses per picture.
const A = [
	{ id: 931, img: 'gal-red.png' },
	{ id: 932, img: 'gal-green.png' },
	{ id: 933, img: 'gal-blue.png' },
];
const amedia = ( a ) => `{"alt":"","id":"${ a.id }","url":"${ BASE }/wp-content/uploads/${ a.img }","fullUrl":"${ BASE }/wp-content/uploads/${ a.img }"}`;
const aslide = ( a ) => `<!-- wp:acme/gslide {"background":{"backgroundImage":${ amedia( a ) },"mediaId":"${ a.id }"}} -->\n<!-- wp:paragraph -->\n<p>Slide ${ a.id }.</p>\n<!-- /wp:paragraph -->\n<!-- /wp:acme/gslide -->`;
const ASLIDER = ( set ) => `<!-- wp:acme/gslider {"media":[${ set.map( amedia ).join( ',' ) }]} -->\n${ set.map( aslide ).join( '\n' ) }\n<!-- /wp:acme/gslider -->`;

const GRP = '<!-- wp:group {"layout":{"type":"constrained"}} -->\n<div class="wp-block-group">' + JP( S ) + '</div>\n<!-- /wp:group -->';
const CONTENT = JP( S ) + '\n\n' + GAL( G ) + '\n\n' + CAR( C ) + '\n\n' + ASLIDER( A ) + '\n\n' + GRP + '\n\n<!-- wp:paragraph -->\n<p>Tail.</p>\n<!-- /wp:paragraph -->';

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
	// The drop test uploads a real attachment — deleted on the way out.
	let droppedId = 0;
	let bigId = 0;
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

		// --- Captions, edited in the modal ---
		// wp-admin edits these inline under each photo; Minn's images editor is
		// where the whole set is managed, so they belong on the tiles.
		t.check( 'gallery inspector reopens for captions', await openIsland( 'gallery' ) );
		await page.click( '#minn-insp-imgedit' );
		await page.waitForSelector( '.minn-imgedit-tile', { timeout: 8000 } );
		const caps = await page.evaluate( () => [ ...document.querySelectorAll( '[data-cap]' ) ].map( ( i ) => i.value ) );
		t.check( 'existing captions load into the tiles', caps.length === 2 && caps.some( ( c ) => /caption/i.test( c ) ), JSON.stringify( caps ) );
		await page.fill( '[data-cap="0"]', 'Edited caption & more' );
		await page.click( '#minn-imgedit-apply' );
		await page.waitForTimeout( 1500 );
		raw = await saveAndRead( id );
		{
			const got = slice( raw, 'gallery' );
			t.check( 'the typed caption is saved, escaped', got.includes( 'Edited caption &amp; more' ), got.slice( 0, 300 ) );
			t.check( 'the other caption is untouched', ( got.match( /figcaption/g ) || [] ).length === 4, got.slice( 0, 400 ) );
		}
		// Clearing one removes the element rather than leaving an empty tag.
		t.check( 'gallery inspector reopens to clear a caption', await openIsland( 'gallery' ) );
		await page.click( '#minn-insp-imgedit' );
		await page.waitForSelector( '.minn-imgedit-tile', { timeout: 8000 } );
		await page.fill( '[data-cap="0"]', '' );
		await page.click( '#minn-imgedit-apply' );
		await page.waitForTimeout( 1500 );
		raw = await saveAndRead( id );
		{
			const got = slice( raw, 'gallery' );
			t.check( 'an emptied caption leaves no empty element', ( got.match( /figcaption/g ) || [] ).length === 2 && ! /<figcaption[^>]*><\/figcaption>/.test( got ), got.slice( 0, 400 ) );
		}

		// --- Nested slide units (slider markup: wrapper element + wrapper block) ---
		const carBefore = slice( raw, 'acme/carousel' );
		const cUnits = carBefore.match( /<!-- wp:acme\/slide[\s\S]*?<!-- \/wp:acme\/slide -->/g ) || [];
		t.check( 'saved carousel still has three slide units', cUnits.length === 3, String( cUnits.length ) );
		const carState = await page.evaluate( () => {
			const isl = document.querySelector( '.minn-block-island[data-block="acme/carousel"]' );
			const prev = isl && isl.querySelector( '.minn-island-preview' );
			if ( ! prev ) return null;
			return {
				tool: isl.dataset.imgtool || '',
				badge: ( isl.querySelector( '.minn-imgtool-badge' ) || {} ).textContent || '',
				collapsed: prev.dataset.sliderCollapsed || '',
				shown: Array.from( prev.querySelectorAll( 'img' ) ).filter( ( im ) => im.offsetParent !== null ).length,
			};
		} );
		t.check( 'nested slides read as image units, not loose images', carState && carState.tool === 'edit' && carState.badge === 'Edit images · 3', JSON.stringify( carState ) );
		// A slider is a stack until its JS runs — the preview shows slide one,
		// the way the block itself does on the site.
		t.check( 'uninitialized slider preview collapses to one slide', carState && carState.collapsed === '3' && carState.shown === 1, JSON.stringify( carState ) );
		const wantCar = carBefore.replace( cUnits[ 0 ], '@@MINN-SWAP@@' ).replace( cUnits[ 1 ], cUnits[ 0 ] ).replace( '@@MINN-SWAP@@', cUnits[ 1 ] );
		t.check( 'carousel inspector offers Edit images', await openIsland( 'acme/carousel' ) );
		const carInsp = await page.evaluate( () => ( {
			rows: document.querySelectorAll( '.minn-insp-img-row' ).length,
			head: ( document.querySelector( '.minn-insp-imghead' ) || {} ).textContent || '',
		} ) );
		t.check( 'carousel inspector shows a count, not per-image rows', carInsp.rows === 0 && /Images · 3/.test( carInsp.head ), JSON.stringify( carInsp ) );
		await page.click( '#minn-insp-imgedit' );
		await page.waitForSelector( '.minn-imgedit-tile', { timeout: 8000 } );
		t.check( 'modal shows a tile per slide', ( await page.$$( '.minn-imgedit-tile' ) ).length === 3 );
		await page.click( '[data-mv="0:1"]' );
		await page.click( '#minn-imgedit-apply' );
		await page.waitForTimeout( 1500 );
		raw = await saveAndRead( id );
		{
			const got = slice( raw, 'acme/carousel' );
			t.check( 'slide reorder byte-exact, wrapper markup untouched', got === wantCar, got === wantCar ? '' : diffAt( got, wantCar ) );
		}

		// --- Drop an image FILE straight into the modal ---
		// The window-level handler would otherwise take the writer to the media
		// library with the file uploaded there instead (Austin's repro): while
		// this modal is open it owns every drop.
		t.check( 'inspector reopens for the drop', await openIsland( 'acme/carousel' ) );
		await page.click( '#minn-insp-imgedit' );
		await page.waitForSelector( '.minn-imgedit-tile', { timeout: 8000 } );
		const beforeTiles = ( await page.$$( '.minn-imgedit-tile' ) ).length;
		const dropped = await page.evaluate( async () => {
			const overlay = document.querySelector( '.minn-imgedit-overlay' );
			const canvas = document.createElement( 'canvas' );
			canvas.width = 32; canvas.height = 32;
			const cx = canvas.getContext( '2d' );
			cx.fillStyle = '#f9a8d4';
			cx.fillRect( 0, 0, 32, 32 );
			const blob = await new Promise( ( res ) => canvas.toBlob( res, 'image/png' ) );
			const dt = new DataTransfer();
			dt.items.add( new File( [ blob ], 'dropped-slide.png', { type: 'image/png' } ) );
			// Chrome's DragEvent constructor drops the dataTransfer member.
			const ev = new DragEvent( 'drop', { bubbles: true, cancelable: true } );
			Object.defineProperty( ev, 'dataTransfer', { value: dt } );
			overlay.dispatchEvent( ev );
			return { prevented: ev.defaultPrevented, zone: overlay.id };
		} );
		t.check( 'modal claims the drop instead of the media library', dropped.prevented && dropped.zone === 'minn-imgedit-drop', JSON.stringify( dropped ) );
		const grew = await page.waitForFunction( ( n ) => document.querySelectorAll( '.minn-imgedit-tile' ).length === n + 1, beforeTiles, { timeout: 25000 } ).then( () => true ).catch( () => false );
		t.check( 'dropped image uploads and joins the grid', grew );
		t.check( 'stayed in the editor, modal still open', page.url().includes( '/editor/' ) && !! ( await page.$( '.minn-imgedit' ) ) );
		await page.click( '#minn-imgedit-apply' );
		await page.waitForTimeout( 1500 );
		raw = await saveAndRead( id );
		{
			const got = slice( raw, 'acme/carousel' );
			const units = got.match( /<!-- wp:acme\/slide[\s\S]*?<!-- \/wp:acme\/slide -->/g ) || [];
			const last = units[ units.length - 1 ] || '';
			const newId = ( last.match( /wp-image-(\d+)/ ) || [] )[ 1 ];
			droppedId = newId ? parseInt( newId, 10 ) : 0;
			t.check( 'dropped image becomes a real slide unit', units.length === 4 && !! newId && ! [ '921', '922', '923' ].includes( newId ), units.length + ' units, new id ' + newId );
			t.check( 'dropped slide keeps the wrapper block shape', last.includes( '<div class="wp-block-acme-slide">' ) && last.includes( 'dropped-slide' ) );
		}

		// --- Slides whose image lives only in block settings ---
		const asBefore = slice( raw, 'acme/gslider' );
		t.check( 'saved attribute slider still has three slides', ( asBefore.match( /<!-- wp:acme\/gslide \{/g ) || [] ).length === 3 );
		t.check( 'attribute slider offers Edit images', await openIsland( 'acme/gslider' ) );
		const asInsp = await page.evaluate( () => ( {
			rows: document.querySelectorAll( '.minn-insp-img-row' ).length,
			head: ( document.querySelector( '.minn-insp-imghead' ) || {} ).textContent || '',
		} ) );
		// Two addresses per picture, three pictures: still three, not six.
		t.check( 'attribute slider counts three images, no per-image rows', asInsp.rows === 0 && /Images · 3/.test( asInsp.head ), JSON.stringify( asInsp ) );
		await page.click( '#minn-insp-imgedit' );
		await page.waitForSelector( '.minn-imgedit-tile', { timeout: 8000 } );
		t.check( 'modal shows a tile per attribute slide', ( await page.$$( '.minn-imgedit-tile' ) ).length === 3 );
		await page.click( '[data-mv="0:1"]' );
		await page.click( '#minn-imgedit-apply' );
		await page.waitForTimeout( 1500 );
		raw = await saveAndRead( id );
		{
			const got = slice( raw, 'acme/gslider' );
			const slides = got.match( /<!-- wp:acme\/gslide \{[\s\S]*?<!-- \/wp:acme\/gslide -->/g ) || [];
			const order = slides.map( ( u ) => ( u.match( /Slide (\d+)\./ ) || [] )[ 1 ] );
			const mirrorIds = ( ( got.match( /"media":\[([\s\S]*?)\]\}/ ) || [] )[ 1 ] || '' ).match( /"id":"(\d+)"/g ) || [];
			t.check( 'slides reordered with their own content', order.join( ',' ) === '932,931,933', order.join( ',' ) );
			// The parent's mirror list has to follow, or the block renders one
			// set and lists another.
			t.check( 'the parent mirror list followed the reorder',
				mirrorIds.join( ',' ) === '"id":"932","id":"931","id":"933"', mirrorIds.join( ',' ) );
			t.check( 'untouched slide stays byte-identical', got.includes( aslide( A[ 2 ] ) ) );
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
			return {
				text: b.textContent.trim(),
				pe: getComputedStyle( b ).pointerEvents,
				// The hover dim is a card-level ::after; it must be inert too.
				dimPe: getComputedStyle( isl, '::after' ).pointerEvents,
			};
		} );
		// The count rides the overlay: on a slider the preview shows one slide,
		// so this is where "there are more of these" gets said.
		t.check( 'image overlay names the action, counts the images, stays click-through',
			badge && /^Edit images · \d+$/.test( badge.text ) && badge.pe === 'none' && badge.dimPe === 'none',
			JSON.stringify( badge ) );
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

		// --- ⌥-click the island's ⚙ chip duplicates the whole card ---
		// REAL modifier click: a synthetic MouseEvent skips the browser layer
		// where ⌃-click turned out to be macOS's secondary click.
		const dupState = await page.evaluate( () => ( {
			before: document.querySelectorAll( '.minn-block-island[data-block="jetpack/slideshow"]' ).length,
		} ) );
		await page.click( '.minn-block-island[data-block="jetpack/slideshow"] .minn-island-chip', { modifiers: [ 'Alt' ] } ).catch( () => {} );
		await page.waitForTimeout( 1200 );
		const dupAfter = await page.evaluate( () => ( {
			count: document.querySelectorAll( '.minn-block-island[data-block="jetpack/slideshow"]' ).length,
			inspectorOpen: !! document.querySelector( '#minn-insp-close' ),
		} ) );
		t.check( 'alt-click the block handle duplicates it, no settings popover',
			dupState && dupAfter.count === dupState.before + 1 && ! dupAfter.inspectorOpen, JSON.stringify( dupAfter ) );
		// ⇧⌥-click removes the copy again (Undo toast path).
		await page.click( '.minn-block-island[data-block="jetpack/slideshow"] .minn-island-chip', { modifiers: [ 'Alt', 'Shift' ] } ).catch( () => {} );
		await page.waitForTimeout( 1200 );
		const afterRemove = await page.evaluate( () => ( {
			count: document.querySelectorAll( '.minn-block-island[data-block="jetpack/slideshow"]' ).length,
			undoToast: !! document.querySelector( '.minn-toast button, .minn-toast-action' ),
		} ) );
		t.check( 'shift-alt-click removes the block with an Undo offer',
			afterRemove.count === dupState.before && afterRemove.undoToast, JSON.stringify( afterRemove ) );

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

		// --- A gallery with many images keeps square, non-overlapping tiles ---
		bigId = await createPost( page, { title: 'Images editor overflow probe', content: JP( MANY ) } );
		await openEditor( page, bigId );
		await page.waitForSelector( '.minn-block-island[data-block="jetpack/slideshow"]', { timeout: 20000 } );
		await page.waitForTimeout( 2500 );
		t.check( 'big gallery inspector offers Edit images', await openIsland( 'jetpack/slideshow' ) );
		await page.click( '#minn-insp-imgedit' );
		await page.waitForSelector( '.minn-imgedit-tile', { timeout: 8000 } );
		await page.waitForTimeout( 800 );
		const gridBox = await page.evaluate( () => {
			const grid = document.querySelector( '.minn-imgedit-grid' );
			const tiles = Array.from( grid.querySelectorAll( '.minn-imgedit-tile' ) );
			const rows = [ ...new Set( tiles.map( ( el ) => Math.round( el.getBoundingClientRect().top ) ) ) ].sort( ( a, b ) => a - b );
			const h = Math.round( tiles[ 0 ].getBoundingClientRect().height );
			const w = Math.round( tiles[ 0 ].getBoundingClientRect().width );
			return { tiles: tiles.length, w, h, pitch: rows.length > 1 ? rows[ 1 ] - rows[ 0 ] : 0, rows: rows.length, scrollH: grid.scrollHeight };
		} );
		// Row pitch must clear the tile: a collapsed track crushes the images
		// into each other (the tile stays square, the row does not follow).
		t.check( 'many tiles stay square and never overlap',
			gridBox.tiles === 30 && Math.abs( gridBox.w - gridBox.h ) <= 2 && gridBox.pitch >= gridBox.h,
			JSON.stringify( gridBox ) );
		t.check( 'the grid scrolls to hold every row', gridBox.scrollH >= gridBox.rows * gridBox.h, JSON.stringify( gridBox ) );
	} finally {
		await deletePost( page, id );
		if ( bigId ) await deletePost( page, bigId ).catch( () => {} );
		if ( droppedId ) {
			await page.evaluate( async ( mid ) => {
				await fetch( window.MINN.restUrl + 'wp/v2/media/' + mid + '?force=true', {
					method: 'DELETE', headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} );
			}, droppedId ).catch( () => {} );
		}
	}

	await t.done( browser, errors );
} )();
