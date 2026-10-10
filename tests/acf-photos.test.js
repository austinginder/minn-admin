/**
 * ACF photos, replaced from the preview.
 *
 * An ACF block keeps its image and gallery fields as attachment ids in the
 * comment's `data`, so its preview carries no URL of the block's own and the
 * core "Replace image" doorway never offered itself. Two ways in, both
 * writing what the block settings' image row writes (the id plus its
 * `_field` key, nothing else in the block touched):
 * - a photo marked with ACF's toolbar contract naming one image field opens
 *   the media picker on click (no popover), one naming a gallery field opens
 *   the Images editor, and the hover chip says so; a marker naming a photo
 *   AND other fields keeps its popover, whose photo row works and keeps what
 *   was typed beside it;
 * - an unmarked <img> is paired with its field by URL and gets the same
 *   click, unless the picture belongs to two fields (it stays unpaired).
 * Without upload rights nothing pairs and a marked photo keeps the popover.
 *
 * Fixture: acf/minn-photo in minn-dev-fixtures.php. SKIPs (exit 0) without
 * ACF Pro.
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

const KEYS = { title: 'field_minn_photo_title', photo: 'field_minn_photo_photo', photo2: 'field_minn_photo_photo2', shots: 'field_minn_photo_shots', marked: 'field_minn_photo_marked' };
const block = ( data ) => {
	const d = {};
	Object.keys( data ).forEach( ( k ) => {
		d[ k ] = data[ k ];
		d[ '_' + k ] = KEYS[ k ];
	} );
	return '<!-- wp:acf/minn-photo ' + JSON.stringify( { name: 'acf/minn-photo', data: d, mode: 'preview' } ) + ' /-->';
};
const ISL = '.minn-block-island[data-block="acf/minn-photo"]';

( async () => {
	const t = reporter( 'acf-photos' );
	const { browser, page, errors } = await launch();
	await login( page );

	const ids = await page.evaluate( async () => {
		const h = { headers: { 'X-WP-Nonce': window.MINN.nonce } };
		const bt = await fetch( window.MINN.restUrl + 'wp/v2/block-types/acf/minn-photo', h );
		if ( ! bt.ok ) return null;
		// The picker lists newest first: index 0 is the newest image.
		const r = await fetch( window.MINN.restUrl + 'wp/v2/media?media_type=image&per_page=5&orderby=date&order=desc&_fields=id', h );
		return ( await r.json() ).map( ( m ) => m.id );
	} );
	if ( ! ids ) {
		console.log( 'SKIP  acf/minn-photo is not registered (ACF Pro inactive?) — suite not run' );
		await browser.close();
		process.exit( 0 );
	}
	if ( ids.length < 4 ) throw new Error( 'need four image attachments' );
	const [ D, A, B, C ] = ids; // D is what the picker offers first
	const S = ( n ) => String( n );

	const content = [
		block( { title: 'Marked card', photo: A, photo2: B, shots: [ S( A ), S( C ) ], marked: 1 } ),
		block( { title: 'Shared photo', photo: B, photo2: B, shots: [ S( C ), S( A ) ], marked: 0 } ),
		block( { title: 'Plain card', photo: C, photo2: A, shots: [ S( B ) ], marked: 0 } ),
	].join( '\n\n' );

	const saved = async ( pid ) => {
		await page.keyboard.press( 'Meta+s' );
		await page.waitForTimeout( 2500 );
		const raw = await page.evaluate( async ( p ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + p + '?context=edit&_fields=content', { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
			return ( await r.json() ).content.raw;
		}, pid );
		return [ ...raw.matchAll( /<!-- wp:acf\/minn-photo (\{.*?\}) \/-->/g ) ].map( ( m ) => JSON.parse( m[ 1 ] ).data );
	};
	// Scroll first and let it settle: the chip hides on scroll by design.
	const chipText = async ( sel ) => {
		await page.mouse.move( 1, 1 );
		await page.locator( sel ).scrollIntoViewIfNeeded();
		await page.waitForTimeout( 500 );
		await page.hover( sel, { position: { x: 12, y: 12 } } );
		await page.waitForTimeout( 400 );
		return page.evaluate( () => { const c = document.getElementById( 'minn-acf-tb-chip' ); return c && ! c.hidden ? c.textContent.trim() : ''; } );
	};
	const pickFirst = async () => {
		await page.waitForSelector( '.minn-picker-item[data-pick="0"]', { timeout: 15000 } );
		await page.evaluate( () => document.querySelector( '.minn-picker-item[data-pick="0"]' ).dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) ) );
		await page.waitForTimeout( 1500 );
	};
	const paired = () => page.evaluate( ( isl ) => [ ...document.querySelectorAll( isl ) ].map( ( el ) => ( {
		photo: ( el.querySelector( '.mph-photo img' ) || {} ).getAttribute?.( 'data-minn-acfimg' ) || '',
		photo2: ( el.querySelector( '.mph-card img' ) || {} ).getAttribute?.( 'data-minn-acfimg' ) || '',
		shots: [ ...el.querySelectorAll( '.mph-shots img' ) ].map( ( i ) => i.getAttribute( 'data-minn-acfimg' ) || '' ),
	} ) ), ISL );

	let id = null;
	try {
		id = await createPost( page, { title: 'ACF photos', content, status: 'draft' } );
		await openEditor( page, id );
		await page.waitForSelector( `${ ISL } .mph-photo img`, { timeout: 30000 } );
		await page.waitForFunction( ( isl ) => document.querySelectorAll( `${ isl } [data-minn-acfimg]` ).length >= 3, ISL, { timeout: 15000 } ).catch( () => {} );

		// Unmarked photos pair by URL; a picture two fields share does not.
		const p = await paired();
		t.check( 'an unmarked photo pairs with its image field', p[ 2 ].photo === 'photo' && p[ 2 ].photo2 === 'photo2', JSON.stringify( p[ 2 ] ) );
		t.check( 'unmarked gallery pictures pair with the gallery field', p[ 1 ].shots.every( ( x ) => x === 'shots' ) && p[ 2 ].shots[ 0 ] === 'shots', JSON.stringify( p[ 1 ].shots ) );
		t.check( 'a picture two fields share stays unpaired', ! p[ 1 ].photo && ! p[ 1 ].photo2, JSON.stringify( p[ 1 ] ) );
		t.check( 'marked photos are left to their marker', ! p[ 0 ].photo && p[ 0 ].shots.every( ( x ) => ! x ), JSON.stringify( p[ 0 ] ) );

		// Hover chips say what the click does.
		t.check( 'a marked photo\'s chip says Replace image', ( await chipText( `${ ISL } >> nth=0 >> .mph-photo` ) ) === 'Replace image' );
		t.check( 'a marked gallery\'s chip says Edit images', ( await chipText( `${ ISL } >> nth=0 >> .mph-shots` ) ) === 'Edit images' );
		const cardChip = await chipText( `${ ISL } >> nth=0 >> .mph-card img` );
		t.check( 'a marker naming more than a photo keeps its title', /Card$/.test( cardChip ) && ! /image/i.test( cardChip ), cardChip );
		const plainChip = await chipText( `${ ISL } >> nth=2 >> .mph-photo img` );
		t.check( 'an unmarked paired photo\'s chip says Replace image', plainChip === 'Replace image', plainChip );

		// A marked photo opens the picker, not a popover.
		await page.locator( `${ ISL } >> nth=0 >> .mph-photo` ).click( { position: { x: 12, y: 12 } } );
		await page.waitForTimeout( 600 );
		t.check( 'a marked photo opens the media picker (no popover)', !! ( await page.$( '.minn-picker-item' ) ) || ! ( await page.$( '.minn-tb-pop' ) ) );
		await pickFirst();
		let data = await saved( id );
		t.check( 'the pick is saved to the field with its key', String( data[ 0 ].photo ) === S( D ) && data[ 0 ]._photo === KEYS.photo, JSON.stringify( data[ 0 ] ) );
		t.check( '...and nothing else in the block changes', data[ 0 ].title === 'Marked card' && String( data[ 0 ].photo2 ) === S( B ) && JSON.stringify( data[ 0 ].shots ) === JSON.stringify( [ S( A ), S( C ) ] ) && String( data[ 0 ].marked ) === '1', JSON.stringify( data[ 0 ] ) );
		t.check( 'the other blocks are untouched', String( data[ 1 ].photo ) === S( B ) && String( data[ 2 ].photo ) === S( C ), JSON.stringify( data.slice( 1 ) ) );

		// A marked gallery opens the Images editor on its pictures.
		await page.locator( `${ ISL } >> nth=0 >> .mph-shots` ).click( { position: { x: 12, y: 12 } } );
		await page.waitForSelector( '.minn-imgedit-tile', { timeout: 15000 } );
		t.check( 'a marked gallery opens the Images editor with its pictures', ( await page.$$( '.minn-imgedit-tile' ) ).length === 2 );
		await page.keyboard.press( 'Escape' );
		await page.waitForTimeout( 600 );

		// A mixed marker keeps its popover; its photo row works and keeps the typed title.
		// On the card's picture: its title is typed over in place, so a click
		// there seats the caret rather than opening the popover.
		await page.locator( `${ ISL } >> nth=0 >> .mph-card img` ).click();
		await page.waitForSelector( '.minn-tb-pop', { timeout: 6000 } );
		const pop = await page.evaluate( () => ( { photoRow: !! document.querySelector( '.minn-tb-pop [data-inspdfimg]' ), text: !! document.querySelector( '.minn-tb-pop input[data-inspdf]' ) } ) );
		t.check( 'a marker naming a photo and a title opens a popover with both', pop.photoRow && pop.text, JSON.stringify( pop ) );
		await page.fill( '.minn-tb-pop input[data-inspdf]', 'Marked card 2' );
		await page.click( '.minn-tb-pop [data-inspdfimg]' );
		await pickFirst();
		data = await saved( id );
		t.check( 'the popover\'s photo row saves the pick and the typed title', String( data[ 0 ].photo2 ) === S( D ) && data[ 0 ].title === 'Marked card 2', JSON.stringify( data[ 0 ] ) );

		// An unmarked paired photo opens the picker too.
		await page.locator( `${ ISL } >> nth=2 >> .mph-card img` ).click();
		await pickFirst();
		data = await saved( id );
		t.check( 'an unmarked paired photo saves its pick to its own field', String( data[ 2 ].photo2 ) === S( D ) && String( data[ 2 ].photo ) === S( C ), JSON.stringify( data[ 2 ] ) );

		// An unmarked gallery picture opens the Images editor at its tile.
		await page.locator( `${ ISL } >> nth=1 >> .mph-shots img >> nth=1` ).click();
		await page.waitForSelector( '.minn-imgedit-tile', { timeout: 15000 } );
		const tile = await page.evaluate( () => ( { n: document.querySelectorAll( '.minn-imgedit-tile' ).length, flash: ( document.querySelector( '.minn-imgedit-tile.flash' ) || {} ).dataset?.i } ) );
		t.check( 'an unmarked gallery picture opens the Images editor at its tile', tile.n === 2 && tile.flash === '1', JSON.stringify( tile ) );
		await page.keyboard.press( 'Escape' );
		await page.waitForTimeout( 600 );

		// Without upload rights: no pairing, and a marked photo keeps the popover.
		// A second tab whose boot payload never had them (the editor reads it
		// at render, and openEditor is a full load that would restore it).
		const p2 = await page.context().newPage();
		p2.on( 'pageerror', ( e ) => errors.push( 'tab2: ' + e.message ) );
		await p2.addInitScript( () => {
			let boot;
			Object.defineProperty( window, 'MINN', { configurable: true, get: () => boot, set: ( v ) => { if ( v && v.caps ) v.caps.upload = false; boot = v; } } );
		} );
		await openEditor( p2, id );
		await p2.waitForSelector( `${ ISL } .mph-photo img`, { timeout: 30000 } );
		await p2.waitForTimeout( 2500 );
		const noUp = await p2.evaluate( () => ( { caps: window.MINN.caps.upload, paired: document.querySelectorAll( '[data-minn-acfimg]' ).length } ) );
		t.check( 'without upload rights no photo pairs', noUp.caps === false && noUp.paired === 0, JSON.stringify( noUp ) );
		await p2.locator( `${ ISL } >> nth=0 >> .mph-photo` ).click( { position: { x: 12, y: 12 } } );
		await p2.waitForTimeout( 1200 );
		const fallback = await p2.evaluate( () => ( { pop: !! document.querySelector( '.minn-tb-pop' ), picker: !! document.querySelector( '.minn-modal-overlay.minn-picker-over' ) } ) );
		t.check( '...and a marked photo opens its popover instead of the picker', fallback.pop && ! fallback.picker, JSON.stringify( fallback ) );
		await p2.close();
	} finally {
		if ( id ) await deletePost( page, id );
	}

	await t.done( browser, errors );
} )();
