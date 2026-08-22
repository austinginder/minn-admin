/**
 * Yoast SEO panel depth — robots meta, canonical, cornerstone, the social
 * split and schema type selects, through the provider-declared fields
 * contract. Yoast is the dev site's RESIDENT SEO plugin, so unlike the
 * Rank Math suite nothing is swapped; the suite asserts it is active and
 * restores nothing.
 *
 * The vendor-shape assertions run through WP-CLI because Yoast registers
 * sanitize_post_meta on its keys: what the REST field reads back proves
 * Minn's round-trip, only the raw meta proves Yoast stored what its own
 * frontend will read (an integer image id, for instance, sanitizes to ''
 * — the string-id lesson). The advanced group is CAPABILITY territory:
 * with disableadvanced_meta on (Yoast's default), an Author must not see
 * robots/canonical fields and their writes must be ignored, while core
 * fields still save (the over-blocking control).
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
	return output.split( /\r?\n/ ).filter( Boolean ).pop() || '';
};
const { launch, login, loginAs, createPost, deletePost, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'seo-yoast' );

	await login( page );

	const active = await page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins?_fields=plugin,status', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		const list = await r.json();
		const y = list.find( ( p ) => p.plugin.split( '/' )[ 0 ] === 'wordpress-seo' );
		return y ? y.status : null;
	} );
	t.check( 'Yoast is the active resident', active === 'active', String( active ) );

	const fieldsFor = ( id ) => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/seo/fields?post=' + pid, {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return await r.json();
	}, id );
	const writeSeo = ( id, body ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + a.id, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			body: JSON.stringify( { minn_seo: a.body } ),
		} );
		return r.status;
	}, { id, body } );
	const meta = ( pid, key ) => wpEval( `echo wp_json_encode( get_post_meta( ${ pid }, ${ JSON.stringify( key ) }, true ) );` );

	const postId = await createPost( page, { title: 'Yoast depth ' + Date.now(), content: '<!-- wp:paragraph -->\n<p>Body.</p>\n<!-- /wp:paragraph -->' } );
	const att = parseInt( wpEval( `echo (int) current( get_posts( array( 'post_type' => 'attachment', 'numberposts' => 1, 'fields' => 'ids' ) ) );` ), 10 );
	try {
		// --- Group shape (admin) ---------------------------------------------
		const admin = await fieldsFor( postId );
		const byGroup = {};
		( admin.groups || [] ).forEach( ( g ) => { byGroup[ g.group ] = g.fields.map( ( f ) => f.name ); } );
		t.check( 'Admin sees the four groups', Object.keys( byGroup ).length === 4, Object.keys( byGroup ).join( '|' ) );
		t.check( 'Advanced carries robots + canonical', ( byGroup.Advanced || [] ).includes( 'robots_index' ) && ( byGroup.Advanced || [] ).includes( 'canonical' ) );
		t.check( 'Schema carries both type selects on a post', ( byGroup.Schema || [] ).includes( 'schema_page_type' ) && ( byGroup.Schema || [] ).includes( 'schema_article_type' ) );
		t.check( 'SERP preview rides the route', !! admin.preview && admin.preview.title.length > 0, admin.preview && admin.preview.title );

		// --- Depth writes, vendor-shaped storage -----------------------------
		t.check( 'depth write returns 200', await writeSeo( postId, {
			robots_index: 'noindex', robots_nofollow: true, robots_noarchive: true, robots_nosnippet: true,
			canonical: 'https://example.com/yoast/', is_cornerstone: true,
			schema_page_type: 'FAQPage', schema_article_type: 'TechArticle',
			facebook_title: 'OG via Minn', twitter_title: 'TW via Minn',
			social_image: { id: att }, twitter_image: att,
		} ) === 200 );
		t.check( 'noindex meta is their 1', meta( postId, '_yoast_wpseo_meta-robots-noindex' ) === '"1"' );
		t.check( 'nofollow meta is their 1', meta( postId, '_yoast_wpseo_meta-robots-nofollow' ) === '"1"' );
		t.check( 'adv csv in their checkbox order', meta( postId, '_yoast_wpseo_meta-robots-adv' ) === '"noarchive,nosnippet"', meta( postId, '_yoast_wpseo_meta-robots-adv' ) );
		t.check( 'cornerstone stores their 1', meta( postId, '_yoast_wpseo_is_cornerstone' ) === '"1"' );
		t.check( 'schema types stored', meta( postId, '_yoast_wpseo_schema_page_type' ) === '"FAQPage"' && meta( postId, '_yoast_wpseo_schema_article_type' ) === '"TechArticle"' );
		t.check( 'og image pair survives their sanitizer (string id)', meta( postId, '_yoast_wpseo_opengraph-image-id' ) === `"${ att }"` && meta( postId, '_yoast_wpseo_opengraph-image' ) !== '""', meta( postId, '_yoast_wpseo_opengraph-image-id' ) );
		t.check( 'twitter image pair stored', meta( postId, '_yoast_wpseo_twitter-image-id' ) === `"${ att }"` );

		// --- Flip + clear = their default (deleted meta) ---------------------
		await writeSeo( postId, { robots_index: 'index', robots_noarchive: false } );
		t.check( 'index flips to their 2', meta( postId, '_yoast_wpseo_meta-robots-noindex' ) === '"2"' );
		t.check( 'adv csv shrinks', meta( postId, '_yoast_wpseo_meta-robots-adv' ) === '"nosnippet"' );
		await writeSeo( postId, { robots_index: '', robots_nofollow: false, robots_nosnippet: false, is_cornerstone: false, schema_page_type: '' } );
		t.check( 'cleared robots metas are DELETED', meta( postId, '_yoast_wpseo_meta-robots-noindex' ) === '""' && meta( postId, '_yoast_wpseo_meta-robots-adv' ) === '""' );
		t.check( 'cleared cornerstone + schema are DELETED', meta( postId, '_yoast_wpseo_is_cornerstone' ) === '""' && meta( postId, '_yoast_wpseo_schema_page_type' ) === '""' );

		// --- Article type hides on pages -------------------------------------
		const pageId = parseInt( wpEval( `echo wp_insert_post( array( 'post_title' => 'Yoast page probe', 'post_status' => 'draft', 'post_type' => 'page' ) );` ), 10 );
		const pg = await fieldsFor( pageId );
		const pgSchema = ( ( pg.groups || [] ).find( ( g ) => g.group === 'Schema' ) || { fields: [] } ).fields.map( ( f ) => f.name );
		t.check( 'article type hidden on pages', pgSchema.includes( 'schema_page_type' ) && ! pgSchema.includes( 'schema_article_type' ), pgSchema.join( ',' ) );
		wpEval( `wp_delete_post( ${ pageId }, true );` );

		// --- Author: advanced is vendor-gated territory ----------------------
		// disableadvanced_meta is Yoast's default-on; guard the premise so a
		// changed site setting fails loudly instead of testing nothing.
		t.check( 'premise: disableadvanced_meta is on', wpEval( `echo WPSEO_Options::get( 'disableadvanced_meta' ) ? 'on' : 'off';` ) === 'on' );
		const { ctx: authorCtx, page: authorPage } = await loginAs( browser, 'minn-author', process.env.MINN_AUTHOR_PASS || 'minn-author-pass-1' );
		const aFields = await authorPage.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/seo/fields?post=0', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return await r.json();
		} );
		const aGroups = ( aFields.groups || [] ).map( ( g ) => g.group );
		t.check( 'author never sees the Advanced group', ! aGroups.includes( 'Advanced' ), aGroups.join( '|' ) );
		const aPostId = parseInt( wpEval( `echo wp_insert_post( array( 'post_title' => 'Author yoast probe', 'post_status' => 'draft', 'post_author' => get_user_by( 'login', 'minn-author' )->ID ) );` ), 10 );
		const aStatus = await authorPage.evaluate( async ( a ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + a.id, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: JSON.stringify( { minn_seo: { title: 'Author title', robots_index: 'noindex', canonical: 'https://evil.example/' } } ),
			} );
			return r.status;
		}, { id: aPostId } );
		t.check( 'author write returns 200 (not blocked wholesale)', aStatus === 200, String( aStatus ) );
		t.check( 'author CAN write the core fields', meta( aPostId, '_yoast_wpseo_title' ) === '"Author title"' );
		t.check( 'author robots/canonical writes are IGNORED', meta( aPostId, '_yoast_wpseo_meta-robots-noindex' ) === '""' && meta( aPostId, '_yoast_wpseo_canonical' ) === '""' );
		wpEval( `wp_delete_post( ${ aPostId }, true );` );
		await authorCtx.close().catch( () => {} );
	} finally {
		await deletePost( page, postId ).catch( () => {} );
	}

	await t.done( browser, errors );
} )();
