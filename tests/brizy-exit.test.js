/**
 * Brizy's more-menu "Go to Dashboard" opens Minn when this user has
 * Minn as the default admin, and keeps the classic editor when they
 * have not opted in.
 *
 * SKIPs when Brizy is not active so run-all stays green on a site
 * without the builder. Point MINN_TEST_URL at builders.localhost to
 * exercise it. Creates its own post, seeds Brizy's enabled flag, and
 * deletes the post on the way out.
 */
const { BASE, WP, launch, login, createPost, deletePost, reporter } = require( './helpers' );
const { execSync } = require( 'child_process' );

function brizyActive() {
	try {
		execSync( `wp --path=${ WP } plugin is-active brizy`, { stdio: 'ignore' } );
		return true;
	} catch ( e ) {
		return false;
	}
}

( async () => {
	if ( ! brizyActive() ) {
		console.log( 'SKIP  brizy-exit (Brizy is not active)' );
		process.exit( 0 );
	}

	const { browser, page, errors } = await launch();
	const t = reporter( 'brizy-exit' );
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

	const id = await createPost( page, { title: 'Brizy exit fixture', content: '', status: 'draft' } );
	execSync( `wp --path=${ WP } --user=admin post meta update ${ id } brizy_enabled 1`, { stdio: 'ignore' } );

	const editorUrl = `${ BASE }/wp-admin/post.php?action=in-front-editor&post=${ id }`;

	const dashLink = async () => {
		await page.waitForSelector( '.brz-ed-sidebar', { timeout: 45000 } );
		const cfg = await page.waitForFunction( () => {
			const f = document.getElementById( 'brz-ed-iframe' );
			const w = f && f.contentWindow;
			const opts = w && w.__VISUAL_CONFIG__
				&& w.__VISUAL_CONFIG__.ui
				&& w.__VISUAL_CONFIG__.ui.leftSidebar
				&& w.__VISUAL_CONFIG__.ui.leftSidebar.more
				&& w.__VISUAL_CONFIG__.ui.leftSidebar.more.options;
			return Array.isArray( opts ) ? opts : null;
		}, null, { timeout: 45000 } ).then( ( h ) => h.jsonValue() ).catch( () => null );
		if ( ! cfg ) {
			return { href: '', target: '', opts: null };
		}
		const dash = cfg.find( ( o ) => o && /Dashboard/.test( o.label || '' ) ) || {};
		return { href: dash.link || '', target: dash.linkTarget || '', opts: cfg };
	};

	const clickDashboard = async () => {
		const more = page.getByTitle( 'More', { exact: true } );
		await more.waitFor( { state: 'visible', timeout: 20000 } );
		await more.click();
		const item = page.locator( 'a.brz-a' ).filter( { hasText: 'Go to Dashboard' } ).first();
		await item.waitFor( { state: 'visible', timeout: 10000 } );
		return item;
	};

	try {
		await setDefaultAdmin( true );
		await page.goto( editorUrl, { waitUntil: 'domcontentloaded', timeout: 60000 } );
		const on = await dashLink();
		t.check( 'default-admin on: Go to Dashboard points at /minn-admin/',
			/\/minn-admin\/?/.test( on.href || '' ) && ! /post\.php/.test( on.href || '' ),
			JSON.stringify( { href: on.href, target: on.target } ) );
		t.check( 'default-admin on: the item breaks out of Brizy\'s iframe',
			on.target === '_top', on.target );

		if ( /\/minn-admin\/?/.test( on.href || '' ) ) {
			const item = await clickDashboard();
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

		await setDefaultAdmin( false );
		await page.goto( editorUrl, { waitUntil: 'domcontentloaded', timeout: 60000 } );
		const off = await dashLink();
		t.check( 'default-admin off: Go to Dashboard stays on the classic editor',
			/post\.php/.test( off.href || '' ) && /action=edit/.test( off.href || '' ) && ! /minn-admin/.test( off.href || '' ),
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

	// Brizy's editor throws on unload when we leave via _top (its
	// `components` lookup races the torn-down iframe). That is their
	// editor, not Minn, so it is not a product failure.
	errors.splice( 0, errors.length, ...errors.filter( ( e ) =>
		! /reading 'components'/.test( e )
	) );

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
