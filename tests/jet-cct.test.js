/**
 * JetEngine Custom Content Types as a surface with a view per type. The
 * fixture type "Contacts" (minn_contact: name, email, tier select, vip
 * switcher, notes) is read from its jet_cct_minn_contact table; create /
 * edit / status / delete go through the type's own item handler. The
 * suite creates and deletes its own rows.
 */
const { BASE, launch, login, loginAs, reporter } = require( './helpers' );
const { evalPhp, apiFor, paintSurface } = require( './_jet-common' );
( async () => {
	const t = reporter( 'jet-cct' );
	const { browser, page, errors } = await launch();
	await login( page );
	const api = apiFor( page );
	let id = 0;
	try {
		const surface = await page.evaluate( () => ( window.MINN.surfaces || [] ).find( ( s ) => s.id === 'jet-cct' ) );
		t.check( 'the Content types surface lists Contacts with columns from its fields and a create form', !! surface && surface.collection.viewLabel === 'Contacts'
			&& surface.collection.columns.some( ( c ) => c.key === 'email' ) && ( surface.collection.create.fields || [] ).some( ( f ) => f.key === 'tier' && f.type === 'select' ),
			JSON.stringify( surface && { cols: surface.collection.columns.map( ( c ) => c.key ), create: ( surface.collection.create || {} ).fields } ) );
		const create = await api( 'minn-admin/v1/jet-cct/minn_contact/items', { method: 'POST', body: { name: 'Sam Suite', email: 'sam@suite.test', tier: 'lead', vip: true, notes: 'Suite note' } } );
		id = ( create.body || {} ).id || 0;
		t.check( 'create inserts through the item handler and answers the row in panel shapes', create.status === 200 && id > 0 && create.body.item.vip === true && create.body.item.tier === 'lead' && create.body.item.title === 'Sam Suite', JSON.stringify( create.body ) );
		const raw = evalPhp( 'cct1', `global $wpdb; echo wp_json_encode( $wpdb->get_row( $wpdb->prepare( "SELECT name, tier, vip, cct_status FROM {$wpdb->prefix}jet_cct_minn_contact WHERE _ID = %d", ${ id } ), ARRAY_A ) );` );
		t.check( 'the stored row carries JetEngine\'s own shapes (switcher as \'true\')', raw === '{"name":"Sam Suite","tier":"lead","vip":"true","cct_status":"publish"}', raw );
		const search = await api( 'minn-admin/v1/jet-cct/minn_contact/items?search=' + encodeURIComponent( 'sam@suite' ) );
		t.check( 'search covers the text columns', search.status === 200 && ( search.body.items || [] ).some( ( i ) => i.id === id ), JSON.stringify( search.body && search.body.total ) );
		const edit = await api( `minn-admin/v1/jet-cct/minn_contact/items/${ id }`, { method: 'POST', body: { tier: 'client', vip: false } } );
		t.check( 'edit merges over the stored row (name untouched, tier + vip changed)', edit.status === 200 && edit.body.tier === 'client' && edit.body.vip === false && edit.body.name === 'Sam Suite', JSON.stringify( edit.body ) );
		const draft = await api( `minn-admin/v1/jet-cct/minn_contact/items/${ id }/status`, { method: 'POST', body: { status: 'draft' } } );
		const drafts = await api( 'minn-admin/v1/jet-cct/minn_contact/items?status=draft' );
		t.check( 'move to draft flips cct_status and the Drafts tab lists it', draft.status === 200 && ( drafts.body.items || [] ).some( ( i ) => i.id === id && i.status === 'draft' ), JSON.stringify( draft.body ) );
		const bad = await api( `minn-admin/v1/jet-cct/minn_contact/items/${ id }/status`, { method: 'POST', body: { status: 'bogus' } } );
		t.check( 'an unknown status is refused', bad.status === 400 );

		const { ctx, page: editorPage } = await loginAs( browser, 'minn-editor', 'minn-editor-pass-1' );
		const denied = await editorPage.evaluate( async () => ( await fetch( window.MINN.restUrl + 'minn-admin/v1/jet-cct/minn_contact/items', { headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' } ) ).status );
		t.check( 'an Editor is refused by the type\'s own capability', denied === 403, String( denied ) );
		await ctx.close();

		const del = await api( `minn-admin/v1/jet-cct/minn_contact/items/${ id }`, { method: 'DELETE' } );
		const gone = evalPhp( 'cct2', `global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jet_cct_minn_contact WHERE _ID = %d", ${ id } ) );` );
		t.check( 'delete removes the row through their handler', del.status === 200 && gone === '0', JSON.stringify( { del: del.status, gone } ) );
		id = 0;
		t.check( 'the Content types surface paints', await paintSurface( page, BASE, 'jet-cct', 'Add item' ) );
	} finally {
		if ( id ) evalPhp( 'cct3', `global $wpdb; $wpdb->delete( $wpdb->prefix . 'jet_cct_minn_contact', array( '_ID' => ${ id } ) );` );
		await t.done( browser, errors );
	}
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
