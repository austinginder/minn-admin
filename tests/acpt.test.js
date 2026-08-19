/**
 * ACPT integration: schema inventory, simple editor fields, capability
 * boundaries and the two-secret licensing form. The development site keeps
 * a `minn-acpt-suite` field group as its standing fixture. No real license is
 * used.
 */
const { BASE, launch, login, loginAs, reporter, createPost, deletePost, openEditor } = require( './helpers' );

( async () => {
	const t = reporter( 'acpt' );
	let postId = 0;
	const { browser, page, errors } = await launch();
	await login( page );

	try {
		// ACPT does not get a surface of its own. Its post types and
		// taxonomies are WordPress post types and taxonomies, so they belong
		// in Structure alongside the ones ACF, CPT UI and Minn create, and a
		// second list of the same things (which also showed WordPress's own
		// categories and tags) was duplication rather than integration.
		await page.goto( `${ BASE }/minn-admin/posttypes`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-app', { timeout: 20000 } );

		const boot = await page.evaluate( () => ( {
			surface: ( window.MINN.surfaces || [] ).find( ( s ) => s.id === 'acpt' ) || null,
			panel: ( window.MINN.editorPanels || [] ).find( ( p ) => p.id === 'acpt' ) || null,
		} ) );
		t.check( 'ACPT claims no surface of its own', boot.surface === null, JSON.stringify( boot.surface ) );
		t.check( 'ACPT editor panel uses its dedicated REST field', !! boot.panel && boot.panel.valuesKey === 'minn_acpt' && boot.panel.writeKey === 'minn_acpt', JSON.stringify( boot.panel ) );

		// Structure names ACPT as the owner of the types it defines, and
		// leaves them read-only: ACPT keeps them in its own tables behind its
		// own builder, so Minn attributes and links out rather than writing.
		const types = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/post-types', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' } );
			const b = await r.json();
			return ( b.types || b || [] ).map( ( x ) => ( { slug: x.slug, source: x.source, editable: x.editable } ) );
		} );
		const owned = types.filter( ( x ) => x.source === 'acpt' );
		// The dev site has ACPT active but defines no custom types through it,
		// so there may be nothing for ACPT to own. Say which case ran instead
		// of passing an assertion that never looked at anything.
		if ( owned.length ) {
			t.check( 'Structure attributes ACPT-defined types to ACPT', true, JSON.stringify( owned ) );
			t.check( 'ACPT-owned types are read-only in Structure', owned.every( ( x ) => x.editable === false ), JSON.stringify( owned ) );
		} else {
			t.check( 'SKIP: ACPT defines no custom post types on this site', true,
				'nothing for ACPT to own; create one in ACPT to cover the attribution path' );
		}
		// The types ACPT merely mirrors (post, page, attachment) are still
		// WordPress's, and must not be relabelled.
		t.check( 'core types keep their own attribution',
			types.filter( ( x ) => [ 'post', 'page' ].includes( x.slug ) ).every( ( x ) => x.source === 'core' ),
			JSON.stringify( types.filter( ( x ) => [ 'post', 'page' ].includes( x.slug ) ) ) );

		postId = await createPost( page, { title: 'ACPT panel suite', content: '<p>ACPT.</p>' } );
		const fields = await page.evaluate( async ( id ) => {
			const r = await fetch( window.MINN.restUrl + `minn-admin/v1/acpt/fields?post_id=${ id }&post_type=posts`, {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return { status: r.status, body: await r.json() };
		}, postId );
		const group = ( fields.body.groups || [] ).find( ( g ) => /Minn ACPT suite/.test( g.group ) );
		const text = group && group.fields.find( ( f ) => f.label === 'ACPT suite text' );
		const choice = group && group.fields.find( ( f ) => f.label === 'ACPT suite choice' );
		t.check( 'simple fields map and the repeater stays locked', fields.status === 200 && !! text && choice && choice.choices.two === 'Two' && group.locked === 1, JSON.stringify( group ) );

		const roundTrip = await page.evaluate( async ( args ) => {
			const write = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + args.id, {
				method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				body: JSON.stringify( { minn_acpt: { [ args.text ]: 'Written through Minn', [ args.choice ]: 'two', not_allowed: 'blocked' } } ),
			} );
			const read = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + args.id + '?context=edit&_fields=minn_acpt', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return { status: write.status, body: await read.json() };
		}, { id: postId, text: text.name, choice: choice.name } );
		t.check( 'ACPT values round-trip and an unknown field is ignored', roundTrip.status === 200 && roundTrip.body.minn_acpt[ text.name ] === 'Written through Minn' && roundTrip.body.minn_acpt[ choice.name ] === 'two' && ! ( 'not_allowed' in roundTrip.body.minn_acpt ), JSON.stringify( roundTrip ) );

		await openEditor( page, postId );
		await page.waitForSelector( '[data-side-door="panel:acpt"]', { timeout: 20000 } );
		await page.click( '[data-side-door="panel:acpt"]' );
		await page.waitForSelector( '.minn-editor-side-modal .minn-panel-fields', { timeout: 10000 } );
		const panelText = await page.locator( '.minn-editor-side-modal' ).innerText();
		t.check( 'editor UI shows ACPT fields and the advanced-field count', /ACPT suite text/.test( panelText ) && /ACPT suite choice/.test( panelText ) && /advanced field/i.test( panelText ), panelText );

		// The admin-only schema listings are gone with the surface they fed,
		// so the remaining question is narrower: the one route left is the
		// editor panel's own, and it belongs to anyone who may edit posts.
		const { ctx: editorCtx, page: editorPage } = await loginAs( browser, 'minn-editor', 'minn-editor-pass-1' );
		const asEditor = await editorPage.evaluate( async () => {
			const get = async ( path ) => ( await fetch( window.MINN.restUrl + path, {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' } ) ).status;
			return {
				fields: await get( 'minn-admin/v1/acpt/fields?post_id=0&post_type=posts' ),
				groups: await get( 'minn-admin/v1/acpt/meta-groups' ),
				types: await get( 'minn-admin/v1/acpt/post-types' ),
			};
		} );
		t.check( 'the retired schema listings are gone, not merely hidden',
			asEditor.groups === 404 && asEditor.types === 404, JSON.stringify( asEditor ) );
		t.check( 'an editor can still load the panel’s own fields', asEditor.fields === 200, JSON.stringify( asEditor ) );
		await editorCtx.close();

		const licenses = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/licenses?_cb=' + Math.random(), { headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' } );
			const textBody = await r.text();
			return { status: r.status, text: textBody, body: JSON.parse( textBody ) };
		} );
		const license = licenses.body.items.find( ( row ) => row.source === 'acpt' );
		t.check( 'ACPT license row is local-state missing with two secret fields', licenses.status === 200 && license && license.state === 'missing' && license.secretFields.map( ( f ) => f.id ).join() === 'license,email', JSON.stringify( license ) );
		t.check( 'license GET does not expose a code or activation id', ! /activation_id|[a-f0-9]{32}/i.test( licenses.text ) );

		await page.goto( BASE + '/minn-admin/extensions', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-xtab="licenses"]', { timeout: 20000 } );
		await page.click( '[data-xtab="licenses"]' );
		await page.waitForSelector( '[data-lic="activate"][data-provider="acpt"]', { timeout: 20000 } );
		await page.click( '[data-lic="activate"][data-provider="acpt"]' );
		const placeholders = await page.locator( '.minn-lic-key' ).evaluateAll( ( els ) => els.map( ( el ) => el.placeholder ) );
		t.check( 'license UI asks for ACPT code and account email together', placeholders.join( '|' ) === 'ACPT license code|Account email', placeholders.join( '|' ) );
		await page.click( '[data-lic-cancel]' );
	} finally {
		await deletePost( page, postId ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( error ) => {
	console.error( error );
	process.exit( 1 );
} );
