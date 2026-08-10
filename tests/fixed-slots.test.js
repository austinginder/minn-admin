/**
 * Images in a FIXED layout (Jetpack's tiled gallery shape).
 *
 * The photos are packed into columns whose widths the plugin computed from
 * their aspect ratios, so the arrangement is not ours to rewrite: the block
 * gets reorder and replace, never add or remove. A reorder moves the images
 * THROUGH the openings — every byte of layout stays where it was, and the
 * ids array follows the photos so the block still describes itself.
 */
const { BASE, launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

const PIC = [
	{ id: 941, img: 'gal-red.png', w: 1122, h: 1402 },
	{ id: 942, img: 'gal-green.png', w: 1600, h: 1067 },
	{ id: 943, img: 'gal-blue.png', w: 1200, h: 1200 },
	{ id: 944, img: 'gal-red.png', w: 900, h: 1200 },
	{ id: 945, img: 'gal-green.png', w: 1400, h: 933 },
];
const img = ( p ) => `<img alt="" data-height="${ p.h }" data-id="${ p.id }" data-link="${ BASE }/photo-${ p.id }/" data-url="${ BASE }/wp-content/uploads/${ p.img }" data-width="${ p.w }" src="${ BASE }/wp-content/uploads/${ p.img }"/>`;
const item = ( p ) => `<figure class="tiled-gallery__item">${ img( p ) }</figure>`;
const col = ( basis, pics ) => `<div class="tiled-gallery__col" style="flex-basis:${ basis }%">${ pics.map( item ).join( '' ) }</div>`;
const TILED = `<!-- wp:jetpack/tiled-gallery {"columns":3,"columnWidths":[["61.73583","38.26417"],["100.00000"]],"ids":[${ PIC.map( ( p ) => p.id ).join( ',' ) }]} -->
<div class="wp-block-jetpack-tiled-gallery aligncenter is-style-rectangular"><div class=""><div class="tiled-gallery__gallery"><div class="tiled-gallery__row">${ col( '61.73583', [ PIC[ 0 ], PIC[ 1 ] ] ) }${ col( '38.26417', [ PIC[ 2 ] ] ) }</div><div class="tiled-gallery__row">${ col( '100.00000', [ PIC[ 3 ], PIC[ 4 ] ] ) }</div></div></div></div>
<!-- /wp:jetpack/tiled-gallery -->`;

( async () => {
	const t = reporter( 'fixed-slots' );
	const { browser, page, errors } = await launch();
	await login( page );

	const openIsland = async () => {
		for ( let i = 0; i < 8; i++ ) {
			try {
				await page.click( '.minn-block-island[data-block="jetpack/tiled-gallery"] .minn-island-chip' );
				await page.waitForSelector( '#minn-insp-imgedit', { timeout: 6000 } );
				return true;
			} catch ( e ) { await page.waitForTimeout( 1200 ); }
		}
		return false;
	};
	const saveAndRead = async ( pid ) => {
		await page.keyboard.press( 'Meta+s' );
		await page.waitForTimeout( 3000 );
		return page.evaluate( async ( p2 ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + p2 + '?context=edit&_fields=content&_cb=' + Math.random(), { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
			return ( await r.json() ).content.raw;
		}, pid );
	};
	// Everything that is NOT an image tag or the ids array is layout.
	const layoutOf = ( raw ) => raw.replace( /<img[^>]*>/g, 'IMG' ).replace( /"ids":\[[^\]]*\]/, 'IDS' );

	let id = 0;
	try {
		id = await createPost( page, { title: 'Fixed slots probe', content: TILED } );
		await openEditor( page, id );
		await page.waitForSelector( '.minn-block-island[data-block="jetpack/tiled-gallery"]', { timeout: 20000 } );
		await page.waitForTimeout( 2500 );

		const card = await page.evaluate( () => {
			const isl = document.querySelector( '.minn-block-island[data-block="jetpack/tiled-gallery"]' );
			return { tool: isl.dataset.imgtool || '', badge: ( isl.querySelector( '.minn-imgtool-badge' ) || {} ).textContent || '' };
		} );
		t.check( 'a fixed layout still offers the images editor', card.tool === 'edit' && /Edit images · 5/.test( card.badge ), JSON.stringify( card ) );

		t.check( 'inspector offers Edit images, not a list of Replace rows', await openIsland() );
		const insp = await page.evaluate( () => ( {
			rows: document.querySelectorAll( '.minn-insp-img-row' ).length,
			head: ( document.querySelector( '.minn-insp-imghead' ) || {} ).textContent || '',
		} ) );
		t.check( 'it counts the five photos', insp.rows === 0 && /Images · 5/.test( insp.head ), JSON.stringify( insp ) );

		await page.click( '#minn-insp-imgedit' );
		await page.waitForSelector( '.minn-imgedit-tile', { timeout: 8000 } );
		const modal = await page.evaluate( () => ( {
			tiles: document.querySelectorAll( '.minn-imgedit-tile' ).length,
			removes: document.querySelectorAll( '[data-x]' ).length,
			dups: document.querySelectorAll( '[data-dup]' ).length,
			add: !! document.querySelector( '#minn-imgedit-add' ),
			hint: ( document.querySelector( '.minn-imgedit-hint' ) || {} ).textContent || '',
			drop: !! document.querySelector( '#minn-imgedit-drop' ),
		} ) );
		// The layout has a set number of openings: promising × or Add would be
		// a promise the block cannot keep.
		t.check( 'the modal offers reorder and replace only', modal.tiles === 5 && modal.removes === 0 && modal.dups === 0 && ! modal.add && ! modal.drop, JSON.stringify( modal ) );
		t.check( 'and says why', /fixed number of images/i.test( modal.hint ), modal.hint );

		await page.click( '[data-mv="0:1"]' );
		await page.click( '#minn-imgedit-apply' );
		await page.waitForTimeout( 1500 );
		let raw = await saveAndRead( id );
		{
			const imgs = raw.match( /<img[^>]*>/g ) || [];
			const ids = ( raw.match( /"ids":\[([^\]]*)\]/ ) || [] )[ 1 ];
			t.check( 'every byte of layout survived the reorder', layoutOf( raw ) === layoutOf( TILED ), raw.slice( 0, 260 ) );
			t.check( 'the photos swapped openings', imgs.length === 5 && /data-id="942"/.test( imgs[ 0 ] ) && /data-id="941"/.test( imgs[ 1 ] ), imgs.slice( 0, 2 ).join( ' | ' ).slice( 0, 200 ) );
			t.check( 'the ids array followed them', ids === '942,941,943,944,945', ids );
			t.check( 'the computed column widths are untouched', raw.includes( '"columnWidths":[["61.73583","38.26417"],["100.00000"]]' ) );
		}

		// Replace: the opening keeps its shape, the photo inside changes.
		t.check( 'inspector reopens for a replace', await openIsland() );
		await page.click( '#minn-insp-imgedit' );
		await page.waitForSelector( '.minn-imgedit-tile', { timeout: 8000 } );
		await page.click( '.minn-imgedit-tile[data-i="0"]' );
		await page.waitForSelector( '.minn-picker-item', { timeout: 10000 } );
		await page.click( '.minn-picker-item' );
		await page.waitForTimeout( 1200 );
		await page.click( '#minn-imgedit-apply' );
		await page.waitForTimeout( 1500 );
		raw = await saveAndRead( id );
		{
			const first = ( raw.match( /<img[^>]*>/g ) || [] )[ 0 ] || '';
			t.check( 'the replaced photo carries its own id and address', /data-id="(?!94[12])\d+"/.test( first ) && /data-url="[^"]+"/.test( first ), first.slice( 0, 220 ) );
			// data-link pointed at the OLD photo's page; a wrong link is worse
			// than none.
			t.check( 'the old attachment link is gone, not left pointing wrong', ! /data-link/.test( first ), first.slice( 0, 220 ) );
			t.check( 'the layout still stands', layoutOf( raw ) === layoutOf( TILED ), raw.slice( 0, 260 ) );
		}
	} finally {
		if ( id ) await deletePost( page, id ).catch( () => {} );
	}
	await t.done( browser, errors );
} )();
