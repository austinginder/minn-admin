/**
 * JetSearch suggestions surface: {prefix}jet_search_suggestions rows listed
 * by weight, add / edit / delete, and the plugin's own duplicate merge SQL.
 * The suite adds its own rows (a duplicate pair) and removes what it made.
 */
const { BASE, launch, login, reporter } = require( './helpers' );
const { evalPhp, apiFor, paintSurface } = require( './_jet-common' );
( async () => {
	const t = reporter( 'jet-search' );
	const { browser, page, errors } = await launch();
	await login( page );
	const api = apiFor( page );
	const NAME = 'minn suite phrase';
	const CLEAN = `global $wpdb; $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}jet_search_suggestions WHERE TRIM(name) = %s", '${ NAME }' ) );`;
	try {
		evalPhp( 'js0', CLEAN );
		const create = await api( 'minn-admin/v1/jet-search/suggestions', { method: 'POST', body: { name: NAME, weight: 5 } } );
		const id = ( create.body || {} ).id || 0;
		t.check( 'a suggestion is created', create.status === 200 && id > 0, JSON.stringify( create.body ) );
		const dup = await api( 'minn-admin/v1/jet-search/suggestions', { method: 'POST', body: { name: NAME, weight: 1 } } );
		t.check( 'the same text again is refused with 409 (their rule)', dup.status === 409, String( dup.status ) );
		const upd = await api( `minn-admin/v1/jet-search/suggestions/${ id }`, { method: 'POST', body: { weight: 21 } } );
		t.check( 'weight edits in place', upd.status === 200 && upd.body.weight === 21, JSON.stringify( upd.body ) );
		const list = await api( 'minn-admin/v1/jet-search/suggestions?search=' + encodeURIComponent( 'suite phrase' ) );
		t.check( 'search finds it', list.status === 200 && ( list.body.items || [] ).some( ( i ) => i.id === id ), JSON.stringify( list.body && list.body.total ) );
		// A duplicate written the way the front end records searches (a trailing space).
		evalPhp( 'js1', `global $wpdb; $wpdb->insert( $wpdb->prefix . 'jet_search_suggestions', array( 'name' => '${ NAME } ', 'weight' => 4, 'parent' => '', 'term' => '' ), array( '%s', '%d', '%s', '%s' ) );` );
		const st = await api( 'minn-admin/v1/jet-search/status' );
		t.check( 'status card counts the duplicate and offers Merge', st.status === 200 && ( st.body.rows || [] ).some( ( r ) => r.label === 'Duplicates' && parseInt( r.value, 10 ) >= 1 ) && ( st.body.actions || [] ).some( ( a ) => /Merge duplicates/.test( a.label ) ), JSON.stringify( st.body ) );
		const dd = await api( 'minn-admin/v1/jet-search/dedupe', { method: 'POST' } );
		const after = evalPhp( 'js2', `global $wpdb; echo wp_json_encode( $wpdb->get_results( $wpdb->prepare( "SELECT id, weight FROM {$wpdb->prefix}jet_search_suggestions WHERE TRIM(name) = %s", '${ NAME }' ), ARRAY_A ) );` );
		let rows = [];
		try { rows = JSON.parse( after ); } catch ( e ) { rows = []; }
		t.check( 'merge folds the duplicate into the first row with weights added (their SQL)', dd.status === 200 && rows.length === 1 && parseInt( rows[ 0 ].weight, 10 ) === 25 && parseInt( rows[ 0 ].id, 10 ) === id, JSON.stringify( { dd: dd.body, rows } ) );
		const del = await api( `minn-admin/v1/jet-search/suggestions/${ id }`, { method: 'DELETE' } );
		const gone = evalPhp( 'js3', `global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jet_search_suggestions WHERE id = %d", ${ id } ) );` );
		t.check( 'delete removes the row', del.status === 200 && gone === '0', JSON.stringify( { del: del.status, gone } ) );
		t.check( 'the Search suggestions surface paints', await paintSurface( page, BASE, 'jet-search', 'Suggestions' ) );
	} finally {
		evalPhp( 'js4', CLEAN );
		await t.done( browser, errors );
	}
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
