/**
 * JetThemeCore templates in the builder-templates family: theme parts as
 * jet-theme-core posts with type / canvas / conditions meta, listed with
 * type tabs, created through their create_template(), renamed, trashed.
 * The suite creates and permanently deletes its own template.
 */
const { BASE, launch, login, reporter } = require( './helpers' );
const { evalPhp, apiFor, paintSurface } = require( './_jet-common' );
( async () => {
	const t = reporter( 'jet-theme-core' );
	const { browser, page, errors } = await launch();
	await login( page );
	const api = apiFor( page );
	let id = 0;
	try {
		const surface = await page.evaluate( () => ( window.MINN.surfaces || [] ).find( ( s ) => s.id === 'jet-theme-core' ) );
		t.check( 'JetThemeCore joins the builder-templates family with type tabs from its structures', !! surface && surface.family === 'builder-templates' && ( surface.collection.tabs.static || [] ).some( ( x ) => x[ 0 ] === 'jet_header' && x[ 1 ] === 'Header' ), JSON.stringify( surface && surface.collection.tabs ) );
		const create = await api( 'minn-admin/v1/jet-theme-core/templates', { method: 'POST', body: { title: 'Minn suite footer', type: 'jet_footer', contentType: 'default' } } );
		id = ( create.body || {} ).id || 0;
		t.check( 'create goes through create_template and seeds the type + canvas meta', create.status === 200 && id > 0 && create.body.item.typeLabel === 'Footer' && create.body.item.contentType === 'Block Editor', JSON.stringify( create.body ) );
		const meta = evalPhp( 'tc1', `echo wp_json_encode( array( get_post_meta( ${ id }, '_jet_template_type', true ), get_post_meta( ${ id }, '_jet_template_content_type', true ), get_post_type( ${ id } ) ) );` );
		t.check( 'the stored template is JetThemeCore\'s own shape', meta === '["jet_footer","default","jet-theme-core"]', meta );
		const list = await api( 'minn-admin/v1/jet-theme-core/templates?type=jet_footer' );
		const row = ( list.body.items || [] ).find( ( i ) => i.id === id );
		t.check( 'the Footer tab lists it with its conditions summary and edit link', list.status === 200 && !! row && /Conditions aren/.test( row.conditions ) && /post=/.test( row.editUrl ), JSON.stringify( row ) );
		const bad = await api( 'minn-admin/v1/jet-theme-core/templates', { method: 'POST', body: { title: 'x', type: 'nope', contentType: 'default' } } );
		t.check( 'an unknown type is refused', bad.status === 400, String( bad.status ) );
		const ren = await api( `minn-admin/v1/jet-theme-core/templates/${ id }`, { method: 'PUT', body: { title: 'Minn suite footer renamed' } } );
		t.check( 'rename edits in place', ren.status === 200 && ren.body.title === 'Minn suite footer renamed', JSON.stringify( ren.body && ren.body.title ) );
		const trash = await api( `minn-admin/v1/jet-theme-core/templates/${ id }`, { method: 'DELETE' } );
		const status = evalPhp( 'tc2', `echo get_post_status( ${ id } );` );
		t.check( 'trash moves it to the trash (recoverable in JetThemeCore)', trash.status === 200 && status === 'trash', status );
		const st = await api( 'minn-admin/v1/jet-theme-core/templates/status' );
		t.check( 'status card counts templates and links to JetThemeCore', st.status === 200 && ( st.body.rows || [] ).some( ( r ) => r.label === 'Templates' ) && ( st.body.actions || [] ).some( ( a ) => /Open JetThemeCore/.test( a.label ) ), JSON.stringify( st.body ) );
		t.check( 'the Templates surface paints the JetThemeCore provider', await paintSurface( page, BASE, 'jet-theme-core', 'Templates' ) );
	} finally {
		if ( id ) evalPhp( 'tc3', `wp_delete_post( ${ id }, true );` );
		await t.done( browser, errors );
	}
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
