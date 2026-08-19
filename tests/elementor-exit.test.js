/**
 * Elementor hamburger gains "Exit to Minn Admin" and that item lands on
 * /minn-admin/, not post.php?action=edit (GH #36).
 *
 * SKIPs when Elementor is not active so run-all stays green on a site
 * without the builder. Creates its own page, seeds Elementor's builder
 * meta, and deletes the page on the way out.
 */
const { BASE, WP, launch, login, createPost, deletePost, reporter } = require( './helpers' );
const { execSync } = require( 'child_process' );

function elementorActive() {
	try {
		execSync( `wp --path=${ WP } plugin is-active elementor`, { stdio: 'ignore' } );
		return true;
	} catch ( e ) {
		return false;
	}
}

( async () => {
	if ( ! elementorActive() ) {
		console.log( 'SKIP  elementor-exit (Elementor is not active)' );
		process.exit( 0 );
	}

	const { browser, page, errors } = await launch();
	const t = reporter( 'elementor-exit' );
	await login( page );

	const id = await createPost( page, { title: 'Elementor exit fixture', content: '', status: 'draft' } );
	execSync( `wp --path=${ WP } post meta update ${ id } _elementor_data "[]"`, { stdio: 'ignore' } );
	execSync( `wp --path=${ WP } post meta update ${ id } _elementor_edit_mode builder`, { stdio: 'ignore' } );

	try {
		await page.goto( `${ BASE }/wp-admin/post.php?post=${ id }&action=elementor`, {
			waitUntil: 'domcontentloaded',
			timeout: 60000,
		} );

		const cfg = await page.waitForFunction(
			() => window.MINN_ELEMENTOR_EXIT && window.MINN_ELEMENTOR_EXIT.url,
			null,
			{ timeout: 30000 }
		).then( () => page.evaluate( () => window.MINN_ELEMENTOR_EXIT ) )
			.catch( () => null );

		t.check( 'editor boot carries the Minn exit URL',
			!! ( cfg && /minn-admin/.test( cfg.url ) && /Exit to Minn Admin/.test( cfg.title ) ),
			JSON.stringify( cfg ) );

		const barReady = await page.waitForSelector( '#elementor-editor-wrapper-v2', { timeout: 45000 } )
			.then( () => true )
			.catch( () => false );
		t.check( 'the Elementor app bar is on screen', barReady );
		const logo = page.getByRole( 'button', { name: 'Elementor Logo' } );

		if ( barReady ) {
			await logo.click();
			const item = page.getByRole( 'menuitem', { name: 'Exit to Minn Admin' } ).or(
				page.locator( 'a[href*="minn-admin"]' ).filter( { hasText: 'Exit to Minn Admin' } )
			).first();
			const itemReady = await item.waitFor( { state: 'visible', timeout: 10000 } )
				.then( () => true )
				.catch( () => false );
			t.check( 'Exit to Minn Admin is in the hamburger', itemReady );

			if ( itemReady ) {
				const href = await item.getAttribute( 'href' );
				t.check( 'the item points at /minn-admin/', /\/minn-admin\/?/.test( href || '' ), href );
				await Promise.all( [
					page.waitForURL( /\/minn-admin/, { timeout: 20000 } ),
					item.click(),
				] );
				t.check( 'clicking it lands in Minn, not wp-admin',
					/\/minn-admin/.test( page.url() ) && ! /\/wp-admin\//.test( page.url() ),
					page.url() );
				const app = await page.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 20000 } )
					.then( () => true )
					.catch( () => false );
				t.check( 'the Minn app actually booted', app );
			}
		}
	} finally {
		await deletePost( page, id ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
