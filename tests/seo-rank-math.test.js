/**
 * Rank Math SEO panel depth — robots meta, advanced robots, canonical URL,
 * the Facebook/Twitter social split and the schema note, all through the
 * provider-declared fields contract (the fields map doubles as the write
 * whitelist server-side).
 *
 * Yoast is the dev site's resident SEO plugin; this suite activates Rank
 * Math for its run (one SEO plugin at a time, like real sites), drives the
 * editor panel UI — conditional Twitter reveal, robots combobox, toggles —
 * and verifies STORED Rank Math meta shapes with WP-CLI (an array meta read
 * back over REST is not proof of vendor-shaped storage). Yoast is restored
 * in finally.
 */
const { execSync } = require( 'child_process' );
const path = require( 'path' );
const WP_PATH = path.resolve( __dirname, '../../../..' );
// No $ in these snippets: the shell would expand it before PHP sees it.
const wpEval = ( php ) => {
	const output = execSync(
		`wp --path=${ JSON.stringify( WP_PATH ) } eval ${ JSON.stringify( php ) } 2>/dev/null`,
		{ encoding: 'utf8', timeout: 60000 }
	).trim();
	// WP-CLI can print its own PHP deprecations to stdout before the value.
	return output.split( /\r?\n/ ).filter( Boolean ).pop() || '';
};
const { launch, login, createPost, deletePost, openEditor, reporter, pickCombo, setSwitch, switchOn } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'seo-rank-math' );

	await login( page );

	const plugins = await page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins?_fields=plugin,status', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return await r.json();
	} );
	// Resolve by plugin DIRECTORY, never a name substring (the seo-mappers
	// Admin Columns addon lesson).
	const pluginId = ( dir ) => ( plugins.find( ( p ) => p.plugin.split( '/' )[ 0 ] === dir ) || {} ).plugin;
	const IDS = { yoast: pluginId( 'wordpress-seo' ), rankmath: pluginId( 'seo-by-rank-math' ) };
	t.check( 'Yoast + Rank Math installed', !! ( IDS.yoast && IDS.rankmath ), JSON.stringify( IDS ) );

	const setStatus = ( id, status ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + a.id, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			body: JSON.stringify( { status: a.status } ),
		} );
		return ( await r.json() ).status;
	}, { id, status } );

	// One SEO plugin at a time; leave every other family member inactive.
	const activateOnly = async ( keep ) => {
		for ( const p of plugins ) {
			const dir = p.plugin.split( '/' )[ 0 ];
			if ( ! /^(wordpress-seo|wordpress-seo-premium|all-in-one-seo-pack|wp-seopress|wp-seopress-pro|siteseo|seo-by-rank-math|seo-by-rank-math-pro|surerank|squirrly-seo)$/.test( dir ) ) continue;
			if ( p.plugin === keep ) continue;
			if ( p.status === 'active' ) await setStatus( p.plugin, 'inactive' );
		}
		return ( await setStatus( keep, 'active' ) ) === 'active';
	};

	const readSeo = ( id ) => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + `wp/v2/posts/${ pid }?context=edit&_fields=minn_seo`, {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return ( await r.json() ).minn_seo || null;
	}, id );

	const meta = ( pid, key ) => wpEval( `echo wp_json_encode( get_post_meta( ${ pid }, ${ JSON.stringify( key ) }, true ) );` );

	const postId = await createPost( page, { title: 'RM depth ' + Date.now(), content: '<!-- wp:paragraph -->\n<p>Body.</p>\n<!-- /wp:paragraph -->' } );
	try {
		t.check( 'Rank Math activated', await activateOnly( IDS.rankmath ) );

		await openEditor( page, postId );
		await page.waitForSelector( '[data-side-door="panel:seo"]', { timeout: 15000 } );
		await page.click( '[data-side-door="panel:seo"]' );
		await page.waitForSelector( '.minn-editor-side-modal [data-pf="seo:title"]', { timeout: 10000 } );

		// --- Panel shape -----------------------------------------------------
		const groups = await page.evaluate( () =>
			Array.from( document.querySelectorAll( '.minn-editor-side-modal .minn-panel-group' ) ).map( ( g ) => g.textContent.trim() )
		);
		t.check( 'Four groups render', groups.length === 4 && /Schema/.test( groups[ 3 ] ), JSON.stringify( groups ) );
		const note = await page.evaluate( () => {
			const el = document.querySelector( '.minn-editor-side-modal [data-pf="seo:schema_in_use"][data-ftype="note"]' );
			return el ? el.textContent.trim() : null;
		} );
		t.check( 'Schema note renders read-only', !! note, note );
		const lockedLink = await page.evaluate( () => {
			const el = document.querySelector( '.minn-editor-side-modal .minn-panel-locked' );
			return el ? el.textContent : '';
		} );
		t.check( 'Schema group locked link-out renders', /wp-admin/.test( lockedLink ), lockedLink );

		// --- SERP preview ----------------------------------------------------
		const serp = await page.evaluate( () => {
			const s = document.querySelector( '.minn-editor-side-modal .minn-serp' );
			if ( ! s ) return null;
			return {
				url: s.querySelector( '.minn-serp-url' ).textContent,
				title: s.querySelector( '[data-serp-line="title"]' ).textContent,
				desc: s.querySelector( '[data-serp-line="description"]' ).textContent,
			};
		} );
		t.check( 'SERP preview renders with resolved defaults', !! serp && /^https?:\/\//.test( serp.url ) && serp.title.length > 0, JSON.stringify( serp ) );
		const siteName = await page.evaluate( () => window.MINN.site.name );
		await page.fill( '[data-pf="seo:title"]', 'Live probe %sep% %sitename%' );
		await page.waitForTimeout( 250 );
		const liveTitle = await page.evaluate( () => document.querySelector( '[data-serp-line="title"]' ).textContent );
		t.check( 'SERP title tracks typing with %vars% resolved', liveTitle.indexOf( 'Live probe' ) === 0 && liveTitle.indexOf( siteName ) !== -1 && liveTitle.indexOf( '%' ) === -1, liveTitle );
		const counter = await page.evaluate( () => document.querySelector( '[data-pfcount="seo:title"]' ).textContent );
		t.check( 'Title counter tracks length', /^\d+ \/ 60$/.test( counter ), counter );
		await page.fill( '[data-pf="seo:title"]', '' );
		await page.waitForTimeout( 250 );
		const backToDefault = await page.evaluate( () => document.querySelector( '[data-serp-line="title"]' ).textContent );
		t.check( 'Cleared title falls back to the server default', backToDefault === serp.title, backToDefault );

		// --- Conditional Twitter reveal --------------------------------------
		const twRowHidden = () => page.evaluate( () => {
			const el = document.querySelector( '.minn-editor-side-modal [data-pf="seo:twitter_title"]' );
			return el ? el.closest( '.minn-panel-field' ).hidden : null;
		} );
		t.check( 'Twitter uses Facebook defaults ON', await switchOn( page, '[data-pf="seo:twitter_use_facebook"]' ) === true );
		t.check( 'Twitter fields hidden while inheriting', await twRowHidden() === true );
		await setSwitch( page, '[data-pf="seo:twitter_use_facebook"]', false );
		t.check( 'Twitter fields reveal when inheritance is off', await twRowHidden() === false );

		// --- Fill the depth fields and save ----------------------------------
		await pickCombo( page, '[data-pf="seo:robots_index"] .minn-ac-input', 'noindex' );
		await setSwitch( page, '[data-pf="seo:robots_nosnippet"]', true );
		await setSwitch( page, '[data-pf="seo:pillar_content"]', true );
		await page.fill( '[data-pf="seo:adv_max_snippet"]', '-1' );
		await page.fill( '[data-pf="seo:canonical"]', 'https://example.com/rm-canonical/' );
		await page.fill( '[data-pf="seo:twitter_title"]', 'TW title via Minn' );
		await pickCombo( page, '[data-pf="seo:twitter_card_type"] .minn-ac-input', 'summary' );
		await page.keyboard.press( 'Meta+s' );

		let seo = null;
		for ( let i = 0; i < 20; i++ ) {
			seo = await readSeo( postId );
			if ( seo && seo.canonical === 'https://example.com/rm-canonical/' ) break;
			await page.waitForTimeout( 500 );
		}
		t.check( 'Depth fields round-trip over REST', !! seo && seo.robots_index === 'noindex' && seo.robots_nosnippet === true
			&& seo.adv_max_snippet === -1 && seo.twitter_title === 'TW title via Minn' && seo.twitter_card_type === 'summary'
			&& seo.pillar_content === true && seo.twitter_use_facebook === false, JSON.stringify( seo ) );

		// --- Vendor-shaped storage (the part REST reads cannot prove) --------
		t.check( 'rank_math_robots stores the directive array',
			meta( postId, 'rank_math_robots' ) === '["noindex","nosnippet"]', meta( postId, 'rank_math_robots' ) );
		t.check( 'rank_math_advanced_robots stores the map',
			meta( postId, 'rank_math_advanced_robots' ) === '{"max-snippet":-1}', meta( postId, 'rank_math_advanced_robots' ) );
		t.check( 'canonical stored under their key',
			meta( postId, 'rank_math_canonical_url' ) === '"https:\\/\\/example.com\\/rm-canonical\\/"', meta( postId, 'rank_math_canonical_url' ) );
		t.check( 'use_facebook off is STORED, not deleted',
			meta( postId, 'rank_math_twitter_use_facebook' ) === '"off"', meta( postId, 'rank_math_twitter_use_facebook' ) );
		t.check( 'pillar stored as their on flag',
			meta( postId, 'rank_math_pillar_content' ) === '"on"', meta( postId, 'rank_math_pillar_content' ) );

		// --- Clearing inherits (metas deleted, not emptied) ------------------
		await page.evaluate( async ( pid ) => {
			await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: JSON.stringify( { minn_seo: { robots_index: '', robots_nosnippet: false, adv_max_snippet: '', canonical: '', pillar_content: false } } ),
			} );
		}, postId );
		t.check( 'cleared robots meta is DELETED (inherit)', meta( postId, 'rank_math_robots' ) === '""', meta( postId, 'rank_math_robots' ) );
		t.check( 'cleared advanced robots meta is DELETED', meta( postId, 'rank_math_advanced_robots' ) === '""' );
		t.check( 'cleared pillar meta is DELETED', meta( postId, 'rank_math_pillar_content' ) === '""' );

		// --- Bogus enum never reaches storage --------------------------------
		await page.evaluate( async ( pid ) => {
			await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: JSON.stringify( { minn_seo: { twitter_card_type: 'player_evil', robots_index: 'nuke' } } ),
			} );
		}, postId );
		t.check( 'bogus enums are ignored', meta( postId, 'rank_math_twitter_card_type' ) === '"summary"'
			&& meta( postId, 'rank_math_robots' ) === '""', meta( postId, 'rank_math_twitter_card_type' ) );
	} finally {
		await activateOnly( IDS.yoast ).catch( () => {} );
		await deletePost( page, postId ).catch( () => {} );
	}

	await t.done( browser, errors );
} )();
