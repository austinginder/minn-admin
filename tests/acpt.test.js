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
		await page.goto( `${ BASE }/minn-admin/acpt`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-app', { timeout: 20000 } );

		const boot = await page.evaluate( () => ( {
			surface: ( window.MINN.surfaces || [] ).find( ( s ) => s.id === 'acpt' ) || null,
			panel: ( window.MINN.editorPanels || [] ).find( ( p ) => p.id === 'acpt' ) || null,
		} ) );
		t.check( 'ACPT Content models surface is in boot data', !! boot.surface && boot.surface.sub === 'ACPT', JSON.stringify( boot.surface ) );
		t.check( 'ACPT editor panel uses its dedicated REST field', !! boot.panel && boot.panel.valuesKey === 'minn_acpt' && boot.panel.writeKey === 'minn_acpt', JSON.stringify( boot.panel ) );

		const inventories = await page.evaluate( async () => {
			const get = async ( path ) => {
				const r = await fetch( window.MINN.restUrl + path, { headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' } );
				return { status: r.status, body: await r.json() };
			};
			return {
				groups: await get( 'minn-admin/v1/acpt/meta-groups' ),
				postTypes: await get( 'minn-admin/v1/acpt/post-types?search=Posts' ),
				taxonomies: await get( 'minn-admin/v1/acpt/taxonomies?search=Categories' ),
			};
		} );
		const inventoryFixture = inventories.groups.body.items.find( ( item ) => item.name === 'minn-acpt-suite' );
		t.check( 'field-group inventory finds the ACPT fixture', inventories.groups.status === 200 && inventoryFixture && inventoryFixture.fields === 3, JSON.stringify( inventories.groups ) );
		t.check( 'post-type inventory is backed by ACPT models', inventories.postTypes.status === 200 && inventories.postTypes.body.items.some( ( i ) => i.name === 'post' ), JSON.stringify( inventories.postTypes ) );
		t.check( 'taxonomy inventory is backed by ACPT models', inventories.taxonomies.status === 200 && inventories.taxonomies.body.items.some( ( i ) => i.slug === 'category' ), JSON.stringify( inventories.taxonomies ) );

		await page.waitForSelector( '.minn-table, .minn-list', { timeout: 20000 } );
		t.check( 'Content models renders the fixture row', /Minn ACPT suite/.test( await page.locator( '#minn-view' ).innerText() ) );

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

		const { ctx: editorCtx, page: editorPage } = await loginAs( browser, 'minn-editor', 'minn-editor-pass-1' );
		const denied = await editorPage.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/acpt/meta-groups', { headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' } );
			return r.status;
		} );
		t.check( 'non-admins cannot enumerate ACPT schemas', denied === 403, String( denied ) );
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
