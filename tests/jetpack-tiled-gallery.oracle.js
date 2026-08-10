/**
 * Oracle for the tiled-gallery adapter.
 *
 * The block's save() recomputes its layout from the images, and the block
 * editor compares saved markup against that output byte for byte. So the only
 * honest test is to ask the block editor itself: park what Minn built in a
 * draft, open it in wp-admin, and compare against wp.blocks.getSaveContent for
 * the very same attributes. Anything but an exact match is a block a writer
 * would find flagged as broken.
 *
 * Not part of the suite run: it needs a site with Jetpack ACTIVE and its tiled
 * gallery registered. Point it at one:
 *
 *   MINN_TEST_URL=https://mmonroe.localhost MINN_TEST_USER=… MINN_TEST_PASS=… \
 *     node tests/jetpack-tiled-gallery.oracle.js
 */
const { BASE, launch, login, reporter } = require( './helpers' );

// Image counts to cover: the chooser branches on count, on aspect ratios and
// on what it used for the previous rows, so a spread matters more than depth.
const SETS = [ 1, 2, 3, 4, 5, 6, 7, 9 ];

( async () => {
	const t = reporter( 'jetpack-tiled-gallery-oracle' );
	const { browser, page, errors } = await launch();
	await login( page );
	await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => !! window.MINN, null, { timeout: 30000 } );

	const registered = await page.evaluate( () => !! ( window.MINN.imageBlocks || {} )[ 'jetpack/tiled-gallery' ] );
	t.check( 'the adapter is registered on this site', registered );
	if ( ! registered ) { await t.done( browser, errors ); return; }

	const pool = await page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/media?per_page=20&media_type=image&_fields=id,media_details', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		// Varied shapes make the chooser take different branches.
		return ( await r.json() )
			.filter( ( m ) => m.media_details && m.media_details.width )
			.map( ( m ) => ( { id: m.id, ratio: m.media_details.width / m.media_details.height } ) );
	} );
	t.check( 'enough images to build with', pool.length >= 9, String( pool.length ) );
	if ( pool.length < 9 ) { await t.done( browser, errors ); return; }

	for ( const count of SETS ) {
		const ids = pool.slice( 0, count ).map( ( p ) => p.id );
		const built = await page.evaluate( async ( picked ) => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/image-block', {
				method: 'POST',
				headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify( { block: 'jetpack/tiled-gallery', ids: picked, raw: '' } ),
			} );
			return r.ok ? ( await r.json() ).markup : null;
		}, ids );
		if ( ! built ) { t.check( `built markup for ${ count } images`, false ); continue; }

		const pid = await page.evaluate( async ( content ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/pages', {
				method: 'POST',
				headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' },
				credentials: 'same-origin',
				body: JSON.stringify( { title: 'ZZ tiled oracle', status: 'draft', content } ),
			} );
			return ( await r.json() ).id;
		}, built );
		try {
			await page.goto( BASE + '/wp-admin/post.php?post=' + pid + '&action=edit', { waitUntil: 'domcontentloaded' } );
			await page.waitForFunction( () => window.wp && wp.data && wp.data.select( 'core/block-editor' ) && wp.data.select( 'core/block-editor' ).getBlocks().length, null, { timeout: 40000 } );
			await page.waitForTimeout( 2500 );
			const verdict = await page.evaluate( () => {
				const b = wp.data.select( 'core/block-editor' ).getBlocks()[ 0 ];
				const type = wp.blocks.getBlockType( b.name );
				let expected = '';
				try { expected = wp.blocks.getSaveContent( type, b.attributes, b.innerBlocks ); } catch ( e ) { expected = 'ERR ' + e.message; }
				const got = b.originalContent || '';
				let i = 0;
				while ( i < expected.length && i < got.length && expected[ i ] === got[ i ] ) i++;
				return {
					valid: b.isValid,
					same: expected === got,
					at: i,
					expected: expected.slice( Math.max( 0, i - 70 ), i + 90 ),
					got: got.slice( Math.max( 0, i - 70 ), i + 90 ),
				};
			} );
			t.check( `${ count } image${ count === 1 ? '' : 's' }: the block editor accepts what Minn built`,
				verdict.valid && verdict.same,
				verdict.same ? '' : `diverges at ${ verdict.at }\n  want …${ verdict.expected }\n  got  …${ verdict.got }` );
		} finally {
			await page.evaluate( async ( p ) => {
				await fetch( '/wp-json/wp/v2/pages/' + p + '?force=true', {
					method: 'DELETE',
					headers: { 'X-WP-Nonce': ( window.wpApiSettings || {} ).nonce || '' },
					credentials: 'same-origin',
				} );
			}, pid ).catch( () => {} );
			await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
			await page.waitForFunction( () => !! window.MINN, null, { timeout: 30000 } );
		}
	}

	// This oracle deliberately visits wp-admin, which is Jetpack's own console
	// (duplicate store registrations, its connection fetch). Judge Minn's noise
	// only.
	const mine = errors.filter( ( e ) => ! /already registered|failed_to_fetch_data|jetpack/i.test( e ) );
	await t.done( browser, mine );
} )();
