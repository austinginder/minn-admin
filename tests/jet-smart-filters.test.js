/**
 * JetSmartFilters: the filters list (jet-smart-filters posts with type /
 * query-var meta) and the indexer on the status card, whose Reindex runs
 * their own index_filters(). The suite adds and trashes its own filter.
 */
const { BASE, launch, login, reporter } = require( './helpers' );
const { evalPhp, apiFor, paintSurface } = require( './_jet-common' );
( async () => {
	const t = reporter( 'jet-smart-filters' );
	const { browser, page, errors } = await launch();
	await login( page );
	const api = apiFor( page );
	let id = 0;
	try {
		id = parseInt( evalPhp( 'sf1', `$id = wp_insert_post( array( 'post_type' => 'jet-smart-filters', 'post_title' => 'Minn suite tag filter', 'post_status' => 'publish' ) ); update_post_meta( $id, '_filter_type', 'select' ); update_post_meta( $id, '_query_var', 'post_tag' ); update_post_meta( $id, '_data_source', 'taxonomies' ); echo $id;` ), 10 ) || 0;
		t.check( 'a filter is seeded', id > 0 );
		const list = await api( 'minn-admin/v1/jet-smart-filters/filters?search=' + encodeURIComponent( 'Minn suite tag' ) );
		const row = ( list.body.items || [] ).find( ( i ) => i.id === id );
		t.check( 'the list shows it with its type name from their registry and query variable', list.status === 200 && !! row && /Select/.test( row.kind ) && row.queryVar === 'post_tag', JSON.stringify( row ) );
		const st = await api( 'minn-admin/v1/jet-smart-filters/status' );
		const on = ( st.body.rows || [] ).find( ( r ) => r.label === 'Indexer' );
		t.check( 'status card reports filters, indexer state and index rows', st.status === 200 && !! on && ( st.body.rows || [] ).some( ( r ) => r.label === 'Index rows' ), JSON.stringify( st.body ) );
		if ( on && on.value === 'On' ) {
			t.check( 'Reindex is offered while the indexer is on', ( st.body.actions || [] ).some( ( a ) => /Reindex/.test( a.label ) ) );
			const re = await api( 'minn-admin/v1/jet-smart-filters/reindex', { method: 'POST' } );
			t.check( 'Reindex runs their index_filters() and reports the row count', re.status === 200 && re.body.ok && /Index rebuilt/.test( re.body.message ), JSON.stringify( re.body ) );
		} else {
			t.check( 'Reindex is withheld while the indexer is off', ! ( st.body.actions || [] ).some( ( a ) => /Reindex/.test( a.label ) ) );
		}
		const del = await api( `minn-admin/v1/jet-smart-filters/filters/${ id }`, { method: 'DELETE' } );
		t.check( 'trash moves the filter to the trash', del.status === 200 && evalPhp( 'sf2', `echo get_post_status( ${ id } );` ) === 'trash' );
		t.check( 'the Filters surface paints', await paintSurface( page, BASE, 'jet-smart-filters', 'Indexer' ) );
	} finally {
		if ( id ) evalPhp( 'sf3', `wp_delete_post( ${ id }, true );` );
		await t.done( browser, errors );
	}
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
