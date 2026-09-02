/**
 * JetEngine as a Post Types backend. The fixture type minn_jet_case ("Jet
 * Cases") and taxonomy minn_jet_topic live in JetEngine's jet_post_types /
 * jet_taxonomies tables; Minn attributes them to JetEngine, edits them
 * through jet_engine()->cpt->data / ->taxonomies->data, and can create and
 * delete definitions there. The suite creates and removes its own type and
 * taxonomy, and renames the fixture type back to what it found.
 */
const { BASE, launch, login, reporter } = require( './helpers' );
( async () => {
	const t = reporter( 'jet-engine-cpt' );
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
	const SLUG = 'minn_jet_suite';
	const TAX = 'minn_jet_suite_tag';
	let fixture = null;
	try {
		await api( `minn-admin/v1/post-types/${ SLUG }`, { method: 'DELETE' } );
		await api( `minn-admin/v1/taxonomies/${ TAX }`, { method: 'DELETE' } );
		const list = await api( 'minn-admin/v1/post-types' );
		fixture = ( ( list.body || {} ).types || [] ).find( ( x ) => x.slug === 'minn_jet_case' );
		t.check( 'JetEngine is a writable backend', list.status === 200 && ( list.body.backends || [] ).includes( 'jet' ), JSON.stringify( list.body && list.body.backends ) );
		t.check( 'the JetEngine fixture type is attributed to JetEngine and editable', !! fixture && fixture.source === 'jet' && fixture.editable === true && fixture.taxonomies.includes( 'minn_jet_topic' ), JSON.stringify( fixture ) );

		const upd = await api( 'minn-admin/v1/post-types/minn_jet_case', { method: 'POST', body: { singular: fixture.singular, plural: 'Jet Cases (suite)', public: true, hierarchical: false, has_archive: true, show_in_rest: true, supports: [ 'title', 'editor', 'thumbnail', 'excerpt' ] } } );
		const after = ( ( ( await api( 'minn-admin/v1/post-types' ) ).body || {} ).types || [] ).find( ( x ) => x.slug === 'minn_jet_case' );
		t.check( 'an update writes through JetEngine and registers live', upd.status === 200 && after && after.plural === 'Jet Cases (suite)' && after.supports.includes( 'excerpt' ), JSON.stringify( { upd: upd.status, after } ) );

		const create = await api( 'minn-admin/v1/post-types', { method: 'POST', body: { slug: SLUG, backend: 'jet', singular: 'Suite Item', plural: 'Suite Items', public: true, show_in_rest: true, supports: [ 'title' ] } } );
		const created = ( ( ( await api( 'minn-admin/v1/post-types' ) ).body || {} ).types || [] ).find( ( x ) => x.slug === SLUG );
		t.check( 'a new type stored in JetEngine registers and reads back as JetEngine\'s', create.status === 200 && create.body.backend === 'jet' && created && created.source === 'jet' && created.plural === 'Suite Items', JSON.stringify( { create: create.body, created } ) );

		const tcreate = await api( 'minn-admin/v1/taxonomies', { method: 'POST', body: { slug: TAX, backend: 'jet', singular: 'Suite tag', plural: 'Suite tags', public: true, show_in_rest: true, hierarchical: false, object_types: [ SLUG ] } } );
		const tcreated = ( ( ( await api( 'minn-admin/v1/taxonomies' ) ).body || {} ).taxonomies || [] ).find( ( x ) => x.slug === TAX );
		t.check( 'a new taxonomy stored in JetEngine registers against the type', tcreate.status === 200 && tcreated && tcreated.source === 'jet' && tcreated.object_types.includes( SLUG ), JSON.stringify( { tcreate: tcreate.body, tcreated } ) );

		const tupd = await api( `minn-admin/v1/taxonomies/${ TAX }`, { method: 'POST', body: { singular: 'Suite tag', plural: 'Suite tags (renamed)', public: true, show_in_rest: true, hierarchical: true, object_types: [ SLUG ] } } );
		const tafter = ( ( ( await api( 'minn-admin/v1/taxonomies' ) ).body || {} ).taxonomies || [] ).find( ( x ) => x.slug === TAX );
		t.check( 'a taxonomy update writes through JetEngine', tupd.status === 200 && tafter && tafter.plural === 'Suite tags (renamed)' && tafter.hierarchical === true, JSON.stringify( tafter ) );

		const del = await api( `minn-admin/v1/post-types/${ SLUG }`, { method: 'DELETE' } );
		const tdel = await api( `minn-admin/v1/taxonomies/${ TAX }`, { method: 'DELETE' } );
		const gone = ( ( ( await api( 'minn-admin/v1/post-types' ) ).body || {} ).types || [] ).some( ( x ) => x.slug === SLUG );
		const tgone = ( ( ( await api( 'minn-admin/v1/taxonomies' ) ).body || {} ).taxonomies || [] ).some( ( x ) => x.slug === TAX );
		t.check( 'deleting removes the JetEngine rows and the registrations', del.status === 200 && tdel.status === 200 && ! gone && ! tgone, JSON.stringify( { del: del.status, tdel: tdel.status, gone, tgone } ) );

		await page.goto( `${ BASE }/minn-admin/posttypes`, { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () => /Jet Cases/.test( ( document.querySelector( '#minn-view' ) || {} ).textContent || '' ), null, { timeout: 20000 } );
		const pill = await page.evaluate( () => {
			const row = [ ...document.querySelectorAll( '#minn-view .minn-table-row, #minn-view [data-slug]' ) ].find( ( r ) => /Jet Cases/.test( r.textContent ) );
			const st = row && row.querySelector( '.minn-status' );
			return st ? st.textContent.trim() : ( row ? row.textContent.slice( 0, 120 ) : '' );
		} );
		t.check( 'Structure labels the type as JetEngine', /JetEngine/.test( pill ), pill );
	} finally {
		if ( fixture ) {
			await api( 'minn-admin/v1/post-types/minn_jet_case', { method: 'POST', body: { singular: fixture.singular, plural: fixture.plural, public: fixture.public, hierarchical: fixture.hierarchical, has_archive: fixture.has_archive, show_in_rest: fixture.show_in_rest, supports: fixture.supports.filter( ( s ) => s !== 'autosave' ) } } ).catch( () => {} );
		}
		await api( `minn-admin/v1/post-types/${ SLUG }`, { method: 'DELETE' } ).catch( () => {} );
		await api( `minn-admin/v1/taxonomies/${ TAX }`, { method: 'DELETE' } ).catch( () => {} );
		await t.done( browser, errors );
	}
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
