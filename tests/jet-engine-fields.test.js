/**
 * JetEngine meta boxes as an editor panel. The fixture meta box "Minn Post
 * Details" (jet_engine_meta_boxes option, allowed on posts) carries eleven
 * field types; values are plain post meta written in JetEngine's own stored
 * shapes (switcher 'true'/'false', checkbox { key: 'true'|'false' }, media
 * id, date 'Y-m-d'). The suite creates a draft, drives the fields route and
 * the minn_jet REST field, then edits one field through the real panel.
 */
const { BASE, launch, login, loginAs, createPost, deletePost, openEditor, reporter } = require( './helpers' );
( async () => {
	const t = reporter( 'jet-engine-fields' );
	const { browser, page, errors } = await launch();
	await login( page );
	const api = ( p, opts ) => page.evaluate( async ( [ pathArg, o ] ) => {
		const r = await fetch( window.MINN.restUrl + pathArg + ( pathArg.includes( '?' ) ? '&' : '?' ) + '_cb=' + Math.random(), {
			method: ( o && o.method ) || 'GET',
			headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
			body: o && o.body ? JSON.stringify( o.body ) : undefined,
		} );
		let body = null;
		try { body = await r.json(); } catch ( e ) { body = null; }
		return { status: r.status, body };
	}, [ p, opts || null ] );
	const id = await createPost( page, { title: 'JetEngine panel suite ' + Date.now(), status: 'draft' } );
	try {
		const fields = await api( `minn-admin/v1/jet-engine/fields?post_id=${ id }&post_type=posts` );
		const group = ( ( fields.body || {} ).groups || [] ).find( ( g ) => g.group === 'Minn Post Details' );
		const types = group ? Object.fromEntries( group.fields.map( ( f ) => [ f.name, f.type ] ) ) : {};
		t.check( 'fields route lists the fixture meta box as a group', fields.status === 200 && !! group, JSON.stringify( ( fields.body || {} ).groups ) );
		t.check( 'JetEngine types map onto the panel vocabulary',
			types.minn_subtitle === 'text' && types.minn_summary === 'textarea' && types.minn_priority === 'number'
			&& types.minn_tier === 'select' && types.minn_channels === 'multicheck' && types.minn_featured === 'true_false'
			&& types.minn_accent === 'color_picker' && types.minn_due === 'date' && types.minn_cover === 'image'
			&& types.minn_body === 'wysiwyg' && types.minn_icon === 'text' && group.locked === 0,
			JSON.stringify( types ) );
		t.check( 'the select carries a clearing row plus the JetEngine options',
			!! group && JSON.stringify( Object.keys( group.fields.find( ( f ) => f.name === 'minn_tier' ).choices ) ) === JSON.stringify( [ '', 'bronze', 'silver', 'gold' ] ) );

		const { ctx: authorCtx, page: authorPage } = await loginAs( browser, 'minn-author', 'minn-author-pass-1' );
		const denied = await authorPage.evaluate( async ( postId ) => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/jet-engine/fields?post_type=posts&post_id=' + postId, {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return r.status;
		}, id );
		t.check( 'an Author cannot inspect another user\'s draft schema', denied === 403, String( denied ) );
		await authorCtx.close();

		const write = await api( `wp/v2/posts/${ id }`, { method: 'POST', body: { minn_jet: {
			minn_subtitle: 'Suite subtitle', minn_priority: '7', minn_tier: 'silver', minn_channels: [ 'sms' ],
			minn_featured: true, minn_accent: '#ff6600', minn_due: '2026-10-01',
		} } } );
		const read = await api( `wp/v2/posts/${ id }?context=edit&_fields=minn_jet` );
		const v = ( read.body || {} ).minn_jet || {};
		t.check( 'values write through the minn_jet field and read back in panel shapes',
			write.status === 200 && v.minn_subtitle === 'Suite subtitle' && v.minn_priority === '7' && v.minn_tier === 'silver'
			&& JSON.stringify( v.minn_channels ) === '["sms"]' && v.minn_featured === true && v.minn_accent === '#ff6600' && v.minn_due === '2026-10-01',
			JSON.stringify( v ) );
		const stored = await page.evaluate( async ( postId ) => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/jet-engine/fields?post_id=' + postId + '&post_type=posts', { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
			return r.status;
		}, id );
		t.check( 'fields route still answers for the edited post', stored === 200 );
		// The stored shapes are JetEngine's own: proven server-side by
		// reading raw meta through the core meta endpoint is not possible
		// (keys are unregistered), so the read-back above stands in, and the
		// clear path is checked by round trip.
		const clear = await api( `wp/v2/posts/${ id }`, { method: 'POST', body: { minn_jet: { minn_tier: '', minn_featured: false, minn_channels: [] } } } );
		const after = ( ( await api( `wp/v2/posts/${ id }?context=edit&_fields=minn_jet` ) ).body || {} ).minn_jet || {};
		t.check( 'clearing a select, switch and checkbox set round-trips empty',
			clear.status === 200 && after.minn_tier === '' && after.minn_featured === false && JSON.stringify( after.minn_channels ) === '[]',
			JSON.stringify( after ) );

		/* ===== The real panel ===== */
		await openEditor( page, id );
		await page.waitForSelector( '[data-side-door="panel:jet-engine"]', { timeout: 15000 } );
		const door = await page.$eval( '[data-side-door="panel:jet-engine"]', ( el ) => el.textContent );
		t.check( 'the editor sidebar shows the JetEngine door', /JetEngine/.test( door ), door );
		await page.click( '[data-side-door="panel:jet-engine"]' );
		const subSel = '[data-pf$=":minn_subtitle"]';
		await page.waitForSelector( subSel, { timeout: 15000 } );
		t.check( 'the panel opens with the stored subtitle', ( await page.$eval( subSel, ( e ) => e.value ) ) === 'Suite subtitle' );
		await page.fill( subSel, 'Typed in Minn' );
		await page.click( '[data-pf$=":minn_featured"][data-ftype="toggle"]' );
		const wait = page.waitForResponse( ( res ) => res.request().method() === 'POST' && new RegExp( 'wp/v2/posts/' + id ).test( res.url() ), { timeout: 20000 } );
		await page.keyboard.press( 'Meta+s' );
		await wait;
		await page.waitForTimeout( 400 );
		const saved = ( ( await api( `wp/v2/posts/${ id }?context=edit&_fields=minn_jet` ) ).body || {} ).minn_jet || {};
		t.check( 'a panel edit persists through the normal post save', saved.minn_subtitle === 'Typed in Minn' && saved.minn_featured === true, JSON.stringify( saved ) );
	} finally {
		await deletePost( page, id ).catch( () => {} );
		await t.done( browser, errors );
	}
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
