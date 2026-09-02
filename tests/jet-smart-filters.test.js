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
		// The indexer row carries the index size when it is on ("On · 12
		// rows"); the same rows join the JetSearch card when both are active.
		t.check( 'status card reports filters and the indexer state with its row count', st.status === 200 && !! on && /^(On · [\d,]+ rows|Off)$/.test( on.value ), JSON.stringify( st.body ) );
		if ( on && /^On/.test( on.value ) ) {
			t.check( 'Reindex is offered while the indexer is on', ( st.body.actions || [] ).some( ( a ) => /Reindex/.test( a.label ) ) );
			// Reindex is a background job: the route answers a job descriptor,
			// WP-Cron runs their index_filters(), and the status route reports
			// the outcome. Nudge wp-cron.php between polls so the run does not
			// wait for a visitor.
			const re = await api( 'minn-admin/v1/jet-smart-filters/reindex', { method: 'POST' } );
			const job = re.body && re.body.job;
			t.check( 'Reindex answers a job descriptor', re.status === 200 && !! job && /reindex\//.test( job.statusRoute ) && job.stopMethod === 'DELETE', JSON.stringify( re.body ) );
			let fin = null;
			for ( let i = 0; i < 40 && job; i++ ) {
				const s = await api( job.statusRoute );
				if ( s.body && [ 'done', 'error', 'canceled' ].includes( s.body.status ) ) { fin = s.body; break; }
				await page.evaluate( () => fetch( '/wp-cron.php?doing_wp_cron=' + Date.now(), { cache: 'no-store' } ).then( () => true, () => false ) );
				await new Promise( ( r ) => setTimeout( r, 1500 ) );
			}
			t.check( 'the job reaches done through WP-Cron with the row count', !! fin && fin.status === 'done' && /Index rebuilt/.test( fin.message ) && fin.percent === 100, JSON.stringify( fin ) );
			const re2 = await api( 'minn-admin/v1/jet-smart-filters/reindex', { method: 'POST' } );
			const stop = re2.body && re2.body.job ? await api( re2.body.job.stopRoute, { method: 'DELETE' } ) : { status: 0 };
			// spawn_cron() can win the race on a fast stack and finish the
			// rebuild before the cancel lands; then stop honestly reports done.
			t.check( 'stopping a job answers canceled while queued, or its final state once cron ran it',
				stop.status === 200 && [ 'canceled', 'done' ].includes( stop.body.status ), JSON.stringify( stop.body ) );
			const gone = await api( 'minn-admin/v1/jet-smart-filters/reindex/zzzzzzzzzzzz' );
			t.check( 'an unknown job token is refused', gone.status === 404, `${ gone.status }` );
		} else {
			t.check( 'Reindex is withheld while the indexer is off', ! ( st.body.actions || [] ).some( ( a ) => /Reindex/.test( a.label ) ) );
		}
		const del = await api( `minn-admin/v1/jet-smart-filters/filters/${ id }`, { method: 'DELETE' } );
		t.check( 'trash moves the filter to the trash', del.status === 200 && evalPhp( 'sf2', `echo get_post_status( ${ id } );` ) === 'trash' );
		t.check( 'the Filters view paints on the shared Search surface', await paintSurface( page, BASE, 'jet-search', 'Indexer' ) );
	} finally {
		if ( id ) evalPhp( 'sf3', `wp_delete_post( ${ id }, true );` );
		await t.done( browser, errors );
	}
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
