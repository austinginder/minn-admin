/**
 * SEO panel mappers — AIOSEO, SEOPress and SiteSEO behind the shared
 * minn_seo field.
 *
 * Yoast is the dev site's resident SEO plugin; this suite swaps the active
 * provider over REST (one SEO plugin at a time, like real sites), drives
 * the editor panel against AIOSEO (the one with its own table instead of
 * postmeta), REST-verifies SEOPress and SiteSEO (the SEOPress fork with its
 * own meta prefix), and restores Yoast in finally.
 */
const { BASE, launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'seo-mappers' );

	await login( page );

	const plugins = await page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins?_fields=plugin,name,status', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return await r.json();
	} );
	const pluginId = ( frag ) => ( plugins.find( ( p ) => p.name.toLowerCase().includes( frag ) ) || {} ).plugin;
	const IDS = { yoast: pluginId( 'yoast seo' ), aioseo: pluginId( 'all in one seo' ), seopress: pluginId( 'seopress' ), siteseo: pluginId( 'siteseo' ) };
	t.check( 'All four SEO plugins installed', !! ( IDS.yoast && IDS.aioseo && IDS.seopress && IDS.siteseo ), JSON.stringify( IDS ) );

	const setStatus = ( id, status ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + a.id, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			body: JSON.stringify( { status: a.status } ),
		} );
		return ( await r.json() ).status;
	}, { id, status } );
	const activateOnly = async ( key ) => {
		for ( const k of Object.keys( IDS ) ) {
			if ( k !== key ) await setStatus( IDS[ k ], 'inactive' );
		}
		const got = await setStatus( IDS[ key ], 'active' );
		return got === 'active';
	};

	const readSeo = ( id ) => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + `wp/v2/posts/${ pid }?context=edit&_fields=minn_seo`, {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return ( await r.json() ).minn_seo || null;
	}, id );

	const postId = await createPost( page, { title: 'SEO mappers ' + Date.now(), content: '<!-- wp:paragraph -->\n<p>Body.</p>\n<!-- /wp:paragraph -->' } );
	try {
		// --- AIOSEO: full panel UI round-trip --------------------------------
		t.check( 'AIOSEO activated', await activateOnly( 'aioseo' ) );
		await openEditor( page, postId );
		// Panels load async after the editor (fieldsRoute fetch) — wait.
		await page.waitForSelector( '[data-pf="seo:title"]', { timeout: 15000 } );
		const panelSub = await page.evaluate( () => {
			const titles = Array.from( document.querySelectorAll( '.minn-side-title' ) );
			const seo = titles.find( ( el ) => el.textContent.trim().startsWith( 'SEO' ) );
			return seo ? seo.textContent : '';
		} );
		t.check( 'SEO panel renders with AIOSEO fields', true );
		t.check( 'Panel names the provider', panelSub.includes( 'AIOSEO' ), panelSub );

		await page.click( '[data-pf="seo:title"]' );
		await page.type( '[data-pf="seo:title"]', 'Panel title via Minn' );
		await page.click( '[data-pf="seo:description"]' );
		await page.type( '[data-pf="seo:description"]', 'Panel description via Minn' );
		await page.keyboard.press( 'Meta+s' );
		await page.waitForFunction(
			() => Array.from( document.querySelectorAll( '.minn-toast' ) ).some( ( x ) => /saved|Saved/.test( x.textContent ) ),
			null, { timeout: 15000 }
		);
		const aio = await readSeo( postId );
		t.check( 'AIOSEO panel save round-trips', !! aio && aio.title === 'Panel title via Minn' && aio.description === 'Panel description via Minn', JSON.stringify( aio ) );

		// --- SEOPress: shared-code REST round-trip ----------------------------
		t.check( 'SEOPress activated', await activateOnly( 'seopress' ) );
		const sp = await page.evaluate( async ( pid ) => {
			const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
			await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid, {
				method: 'POST', headers: h, credentials: 'same-origin',
				body: JSON.stringify( { minn_seo: { title: 'SP via Minn', focus_keyword: 'seopress kw' } } ),
			} );
			const r = await fetch( window.MINN.restUrl + `wp/v2/posts/${ pid }?context=edit&_fields=minn_seo`, {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return ( await r.json() ).minn_seo;
		}, postId );
		t.check( 'SEOPress write/read round-trips', !! sp && sp.title === 'SP via Minn' && sp.focus_keyword === 'seopress kw', JSON.stringify( sp ) );
		t.check( 'Providers are isolated (AIOSEO values not read by SEOPress)', !! sp && sp.description === '' );

		// --- SiteSEO: the SEOPress fork, own _siteseo_ meta prefix -----------
		t.check( 'SiteSEO activated', await activateOnly( 'siteseo' ) );
		const ss = await page.evaluate( async ( pid ) => {
			const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
			const before = await ( await fetch( window.MINN.restUrl + `wp/v2/posts/${ pid }?context=edit&_fields=minn_seo&_cb=` + Math.random(), {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} ) ).json();
			await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid, {
				method: 'POST', headers: h, credentials: 'same-origin',
				body: JSON.stringify( { minn_seo: { title: 'SS via Minn', focus_keyword: 'siteseo kw' } } ),
			} );
			const after = await ( await fetch( window.MINN.restUrl + `wp/v2/posts/${ pid }?context=edit&_fields=minn_seo&_cb=` + Math.random(), {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} ) ).json();
			return { before: before.minn_seo, after: after.minn_seo };
		}, postId );
		t.check( 'SiteSEO starts empty (SEOPress values not read by the fork)', !! ss.before && ss.before.title === '' && ss.before.focus_keyword === '' , JSON.stringify( ss.before ) );
		t.check( 'SiteSEO write/read round-trips', !! ss.after && ss.after.title === 'SS via Minn' && ss.after.focus_keyword === 'siteseo kw', JSON.stringify( ss.after ) );
	} finally {
		await deletePost( page, postId ).catch( () => {} );
		// Yoast back as the resident provider, everything else off.
		await setStatus( IDS.seopress, 'inactive' ).catch( () => {} );
		await setStatus( IDS.siteseo, 'inactive' ).catch( () => {} );
		await setStatus( IDS.aioseo, 'inactive' ).catch( () => {} );
		await setStatus( IDS.yoast, 'active' ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
