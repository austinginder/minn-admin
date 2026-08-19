/**
 * Elementor's hamburger "Exit to WordPress" opens Minn when this user
 * has Minn as the default admin, and keeps the classic editor when they
 * have not opted in. There is no extra "Exit to Minn Admin" row.
 *
 * SKIPs when Elementor is not active so run-all stays green on a site
 * without the builder. Creates its own post, seeds Elementor's builder
 * meta, and deletes the post on the way out.
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

	const auth = await page.evaluate( () => ( { rest: window.MINN.restUrl, nonce: window.MINN.nonce } ) );
	const setDefaultAdmin = ( on ) => page.evaluate( async ( { a, v } ) => {
		const r = await fetch( a.rest + 'minn-admin/v1/me/appearance', {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': a.nonce },
			body: JSON.stringify( { defaultAdmin: v } ),
		} );
		return ( await r.json() ).defaultAdmin;
	}, { a: auth, v: on } );
	const previous = await page.evaluate( async ( a ) => {
		const r = await fetch( a.rest + 'minn-admin/v1/me/appearance', {
			headers: { 'X-WP-Nonce': a.nonce }, credentials: 'same-origin',
		} );
		return ( await r.json() ).defaultAdmin;
	}, auth );

	const id = await createPost( page, { title: 'Elementor exit fixture', content: '', status: 'draft' } );
	execSync( `wp --path=${ WP } post meta update ${ id } _elementor_data "[]"`, { stdio: 'ignore' } );
	execSync( `wp --path=${ WP } post meta update ${ id } _elementor_edit_mode builder`, { stdio: 'ignore' } );

	const editorUrl = `${ BASE }/wp-admin/post.php?post=${ id }&action=elementor`;

	const openExit = async () => {
		const barReady = await page.waitForSelector( '#elementor-editor-wrapper-v2', { timeout: 45000 } )
			.then( () => true )
			.catch( () => false );
		if ( ! barReady ) {
			return { barReady: false, item: null, href: '', extra: false };
		}
		await page.getByRole( 'button', { name: 'Elementor Logo' } ).click();
		const item = page.getByRole( 'menuitem', { name: 'Exit to WordPress' } ).or(
			page.locator( 'a' ).filter( { hasText: 'Exit to WordPress' } )
		).first();
		const itemReady = await item.waitFor( { state: 'visible', timeout: 10000 } )
			.then( () => true )
			.catch( () => false );
		const extra = await page.getByRole( 'menuitem', { name: 'Exit to Minn Admin' } )
			.waitFor( { state: 'visible', timeout: 1500 } )
			.then( () => true )
			.catch( () => false );
		const href = itemReady ? ( await item.getAttribute( 'href' ) ) : '';
		return { barReady, item: itemReady ? item : null, href: href || '', extra };
	};

	try {
		await setDefaultAdmin( true );
		await page.goto( editorUrl, { waitUntil: 'domcontentloaded', timeout: 60000 } );
		const on = await openExit();
		t.check( 'the Elementor app bar is on screen', on.barReady );
		t.check( 'Exit to WordPress is in the hamburger', !! on.item );
		t.check( 'there is no extra Exit to Minn Admin row', ! on.extra );
		t.check( 'default-admin on: Exit to WordPress points at /minn-admin/',
			/\/minn-admin\/?/.test( on.href ) && ! /post\.php/.test( on.href ),
			on.href );

		if ( on.item && /\/minn-admin\/?/.test( on.href ) ) {
			await Promise.all( [
				page.waitForURL( /\/minn-admin/, { timeout: 20000 } ),
				on.item.click(),
			] );
			t.check( 'clicking it lands in Minn, not wp-admin',
				/\/minn-admin/.test( page.url() ) && ! /\/wp-admin\//.test( page.url() ),
				page.url() );
			const app = await page.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 20000 } )
				.then( () => true )
				.catch( () => false );
			t.check( 'the Minn app actually booted', app );
		}

		await setDefaultAdmin( false );
		await page.goto( editorUrl, { waitUntil: 'domcontentloaded', timeout: 60000 } );
		const off = await openExit();
		t.check( 'default-admin off: Exit to WordPress stays on WordPress',
			!! off.href && ! /minn-admin/.test( off.href ) && /wp-admin/.test( off.href ),
			off.href );
	} finally {
		await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded', timeout: 60000 } ).catch( () => {} );
		await page.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 20000 } ).catch( () => {} );
		await setDefaultAdmin( previous === true ).catch( () => {} );
		await deletePost( page, id ).catch( () => {} );
		try {
			execSync( `wp --path=${ WP } post delete ${ id } --force`, { stdio: 'ignore' } );
		} catch ( e ) { /* already gone */ }
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
