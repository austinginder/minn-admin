/**
 * The image-block extension point (minn_admin_image_blocks).
 *
 * Some blocks keep a layout their own plugin computed from the images in it —
 * Jetpack's tiled gallery is the case this was built for. Adding or removing a
 * photo means laying it out again, which is the plugin's rule, so Minn sends
 * the images a writer chose (in order) and takes back whole block markup.
 *
 * Driven here through a FIXTURE block in the dev mu-plugin, so the mechanism is
 * covered without depending on Jetpack being active.
 */
const { BASE, launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'image-blocks' );
	const { browser, page, errors } = await launch();
	await login( page );

	const setOpt = async ( key, val ) => {
		for ( let i = 0; i < 5; i++ ) {
			const got = await page.evaluate( async ( a ) => {
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST',
					headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' },
					credentials: 'same-origin',
					body: JSON.stringify( { [ a.key ]: a.val } ),
				} );
				const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), {
					headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} );
				return ( await r.json() )[ a.key ];
			}, { key, val } );
			if ( !! got === !! val ) return true;
			await page.waitForTimeout( 800 );
		}
		return false;
	};

	let id = 0;
	try {
		t.check( 'fixture image block armed', await setOpt( 'minn_test_image_block', true ) );

		// A gallery this block owns: two rows, widths from the photos' shapes.
		const ids = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/media?per_page=3&media_type=image&_fields=id', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return ( await r.json() ).map( ( m ) => m.id );
		} );
		t.check( 'three attachments to work with', ids.length === 3, JSON.stringify( ids ) );

		// The route builds the markup: this is the contract an adapter answers.
		const built = await page.evaluate( async ( picked ) => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/image-block', {
				method: 'POST',
				headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify( { block: 'acme/fixed-gallery', ids: picked, raw: '' } ),
			} );
			return r.ok ? ( await r.json() ).markup : 'HTTP ' + r.status;
		}, ids );
		t.check( 'the block owner builds markup from a list of images',
			/^<!-- wp:acme\/fixed-gallery /.test( built ) && ( built.match( /<img/g ) || [] ).length === 3, built.slice( 0, 200 ) );
		t.check( 'it laid the photos out itself', /"columnWidths":\[\[/.test( built ) && /flex-basis:/.test( built ), built.slice( 0, 200 ) );
		t.check( 'the ids it wrote are the ones we asked for', built.includes( '"ids":[' + ids.join( ',' ) + ']' ), built.slice( 0, 200 ) );

		// A block Minn does not know about is refused, not guessed at.
		const refused = await page.evaluate( async ( picked ) => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/image-block', {
				method: 'POST',
				headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify( { block: 'acme/not-registered', ids: picked, raw: '' } ),
			} );
			return r.status;
		}, ids );
		t.check( 'an unregistered block is refused', refused === 400, String( refused ) );

		// --- In the editor: add a photo to one the plugin owns ---
		id = await createPost( page, { title: 'Image block probe', content: built } );
		await openEditor( page, id );
		await page.waitForSelector( '.minn-block-island[data-block="acme/fixed-gallery"]', { timeout: 20000 } );
		await page.waitForTimeout( 2000 );
		const card = await page.evaluate( () => {
			const isl = document.querySelector( '.minn-block-island[data-block="acme/fixed-gallery"]' );
			return { tool: isl.dataset.imgtool || '', badge: ( isl.querySelector( '.minn-imgtool-badge' ) || {} ).textContent || '' };
		} );
		t.check( 'the gallery offers the images editor', card.tool === 'edit' && /Edit images · 3/.test( card.badge ), JSON.stringify( card ) );

		for ( let i = 0; i < 8; i++ ) {
			try {
				await page.click( '.minn-block-island[data-block="acme/fixed-gallery"] .minn-island-chip' );
				await page.waitForSelector( '#minn-insp-imgedit', { timeout: 6000 } );
				break;
			} catch ( e ) { await page.waitForTimeout( 1200 ); }
		}
		await page.click( '#minn-insp-imgedit' );
		await page.waitForSelector( '.minn-imgedit-tile', { timeout: 8000 } );
		// Because its owner can lay it out again, this one DOES offer add and
		// remove — the fixed-layout restrictions lift.
		const modal = await page.evaluate( () => ( {
			tiles: document.querySelectorAll( '.minn-imgedit-tile' ).length,
			removes: document.querySelectorAll( '[data-x]' ).length,
			add: !! document.querySelector( '#minn-imgedit-add' ),
		} ) );
		t.check( 'a block its plugin can rebuild offers add and remove', modal.tiles === 3 && modal.removes === 3 && modal.add, JSON.stringify( modal ) );

		await page.click( '[data-x="2"]' );
		await page.click( '#minn-imgedit-apply' );
		await page.waitForTimeout( 2500 );
		await page.keyboard.press( 'Meta+s' );
		await page.waitForTimeout( 3000 );
		const raw = await page.evaluate( async ( pid ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content&_cb=' + Math.random(), { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
			return ( await r.json() ).content.raw;
		}, id );
		t.check( 'the photo is gone and the block was laid out again',
			( raw.match( /<img/g ) || [] ).length === 2 && raw.includes( '"ids":[' + ids.slice( 0, 2 ).join( ',' ) + ']' ), raw.slice( 0, 220 ) );
		// One row of two now, so the widths describe that row, not the old pair.
		const widths = ( raw.match( /"columnWidths":(\[\[[^\]]*\]\])/ ) || [] )[ 1 ] || '';
		t.check( 'the widths describe the new layout', ( widths.match( /\[/g ) || [] ).length === 2, widths );
	} finally {
		if ( id ) await deletePost( page, id ).catch( () => {} );
		await setOpt( 'minn_test_image_block', false );
	}
	await t.done( browser, errors );
} )();
