/**
 * SEO panel mappers — AIOSEO, SEOPress, SiteSEO and SureRank behind the
 * shared minn_seo field.
 *
 * Yoast is the dev site's resident SEO plugin; this suite swaps the active
 * provider over REST (one SEO plugin at a time, like real sites), drives
 * the editor panel against AIOSEO (the one with its own table instead of
 * postmeta), REST-verifies SEOPress and SiteSEO (the SEOPress fork with its
 * own meta prefix), and REST-verifies SureRank, which stores GROUPED meta
 * blobs and substitutes site-wide templates for empty values. That last
 * one is why the SureRank section inspects the raw stored group with
 * WP-CLI: reading '' back is not proof, since a stored template also reads
 * back as ''. Yoast is restored in finally.
 */
const { execSync } = require( 'child_process' );
const path = require( 'path' );
const WP_PATH = path.resolve( __dirname, '../../../..' );
// No $ in these snippets: the shell would expand it before PHP sees it.
const wpEval = ( php ) => execSync(
	`wp --path=${ JSON.stringify( WP_PATH ) } eval ${ JSON.stringify( php ) } 2>/dev/null`,
	{ encoding: 'utf8', timeout: 60000 }
).trim();
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
	const IDS = { yoast: pluginId( 'yoast seo' ), aioseo: pluginId( 'all in one seo' ), seopress: pluginId( 'seopress' ), siteseo: pluginId( 'siteseo' ), surerank: pluginId( 'surerank' ) };
	t.check( 'All five SEO plugins installed', !! ( IDS.yoast && IDS.aioseo && IDS.seopress && IDS.siteseo && IDS.surerank ), JSON.stringify( IDS ) );

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
		// Deactivate every SEO-ish install (incl. Yoast Premium and Rank Math
		// Pro siblings) so detection first-active-wins can't leave Premium
		// active and still label the door "Yoast SEO" while we think AIOSEO.
		const all = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins?_fields=plugin,name,status', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return await r.json();
		} );
		const keep = IDS[ key ];
		for ( const p of all ) {
			if ( ! /yoast|all.in.one.seo|seopress|siteseo|rank.?math|surerank/i.test( p.name || '' ) ) continue;
			if ( p.plugin === keep ) continue;
			if ( p.status === 'active' ) await setStatus( p.plugin, 'inactive' );
		}
		const got = await setStatus( keep, 'active' );
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
		// Panels load async after the editor (fieldsRoute fetch) — wait for
		// the SEO door, then open it (fields live in the modal now).
		await page.waitForSelector( '[data-side-door="panel:seo"]', { timeout: 15000 } );
		const panelSub = await page.evaluate( () => {
			const door = document.querySelector( '[data-side-door="panel:seo"]' );
			return door ? door.textContent : '';
		} );
		t.check( 'SEO door renders on the rail', /SEO/.test( panelSub ), panelSub );
		// Provider name is first-active-wins; free Yoast + Premium can leave
		// the label on Yoast even after AIOSEO is activated in the suite.
		// The write path below is what proves AIOSEO when it is the provider.
		await page.click( '[data-side-door="panel:seo"]' );
		await page.waitForSelector( '.minn-editor-side-modal [data-pf="seo:title"]', { timeout: 10000 } );

		await page.fill( '[data-pf="seo:title"]', 'Panel title via Minn' );
		await page.fill( '[data-pf="seo:description"]', 'Panel description via Minn' );
		await page.keyboard.press( 'Meta+s' );
		// Poll REST (toast may be "Draft saved" or "Updated", and panel-only
		// dirty can skip the toast if dirty remains set).
		let aio = null;
		for ( let i = 0; i < 20; i++ ) {
			aio = await readSeo( postId );
			if ( aio && aio.title === 'Panel title via Minn' && aio.description === 'Panel description via Minn' ) break;
			await page.waitForTimeout( 500 );
		}
		t.check( 'SEO panel save round-trips via door modal', !! aio && aio.title === 'Panel title via Minn' && aio.description === 'Panel description via Minn', JSON.stringify( aio ) );

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

		// --- SureRank: grouped meta blobs + the empty-value template trap ----
		t.check( 'SureRank activated', await activateOnly( 'surerank' ) );
		const writeSeo = ( pid, payload ) => page.evaluate( async ( a ) => {
			const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
			await fetch( window.MINN.restUrl + 'wp/v2/posts/' + a.pid, {
				method: 'POST', headers: h, credentials: 'same-origin',
				body: JSON.stringify( { minn_seo: a.payload } ),
			} );
			const r = await fetch( window.MINN.restUrl + `wp/v2/posts/${ a.pid }?context=edit&_fields=minn_seo&_cb=` + Math.random(), {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return ( await r.json() ).minn_seo;
		}, { pid, payload } );
		// Their stored group, straight from postmeta — the only way to tell a
		// cleared field from a stored site template.
		const group = () => {
			try {
				return JSON.parse( wpEval( `echo wp_json_encode( get_post_meta( ${ postId }, "surerank_settings_general", true ) );` ) || 'null' );
			} catch ( e ) {
				return null;
			}
		};

		const srStart = await page.evaluate( async ( pid ) => {
			const r = await fetch( window.MINN.restUrl + `wp/v2/posts/${ pid }?context=edit&_fields=minn_seo&_cb=` + Math.random(), {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return ( await r.json() ).minn_seo;
		}, postId );
		t.check( 'SureRank starts empty (SiteSEO values not read across)',
			!! srStart && srStart.title === '' && srStart.focus_keyword === '', JSON.stringify( srStart ) );

		const sr1 = await writeSeo( postId, { title: 'SR via Minn', description: 'SureRank description', focus_keyword: 'surerank kw' } );
		t.check( 'SureRank write/read round-trips',
			!! sr1 && sr1.title === 'SR via Minn' && sr1.description === 'SureRank description' && sr1.focus_keyword === 'surerank kw',
			JSON.stringify( sr1 ) );
		const g1 = group();
		t.check( 'values land in SureRank\'s own grouped meta blob',
			!! g1 && g1.page_title === 'SR via Minn' && g1.page_description === 'SureRank description' && g1.focus_keyword === 'surerank kw',
			JSON.stringify( g1 ) );

		// Clearing one field must not disturb its neighbours.
		const sr2 = await writeSeo( postId, { title: '' } );
		t.check( 'clearing the SEO title leaves the other fields alone',
			!! sr2 && sr2.title === '' && sr2.description === 'SureRank description' && sr2.focus_keyword === 'surerank kw',
			JSON.stringify( sr2 ) );

		// THE REGRESSION: SureRank's own save path substitutes the site-wide
		// template ('%title% - %site_name%') for an empty value and stores it
		// as the post's own. A cleared field must leave NO key behind.
		const g2 = group();
		t.check( 'a cleared field is unset, not filled with the site template',
			!! g2 && ! ( 'page_title' in g2 ) && ! JSON.stringify( g2 ).includes( '%site_name%' ),
			JSON.stringify( g2 ) );

		const sr3 = await writeSeo( postId, { description: '', focus_keyword: '' } );
		t.check( 'clearing every field empties the group', !! sr3 && sr3.title === '' && sr3.description === '' && sr3.focus_keyword === '', JSON.stringify( sr3 ) );
		const g3 = group();
		t.check( 'no template text is left in storage after a full clear',
			! g3 || ( ! JSON.stringify( g3 ).includes( '%' ) ), JSON.stringify( g3 ) );
	} finally {
		await deletePost( page, postId ).catch( () => {} );
		// Yoast back as the resident provider, everything else off.
		await setStatus( IDS.seopress, 'inactive' ).catch( () => {} );
		await setStatus( IDS.siteseo, 'inactive' ).catch( () => {} );
		await setStatus( IDS.aioseo, 'inactive' ).catch( () => {} );
		await setStatus( IDS.surerank, 'inactive' ).catch( () => {} );
		await setStatus( IDS.yoast, 'active' ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
