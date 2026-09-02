/**
 * JetReviews moderation surface. Reviews live in {prefix}jet_reviews with
 * approved 0/1; approve/unapprove mirror their toggle endpoint (table update
 * + source rating sync), delete mirrors their delete endpoint. The suite
 * seeds a pending review through their Data::add_new_review and removes it.
 */
const { BASE, launch, login, loginAs, reporter } = require( './helpers' );
const { evalPhp, apiFor, paintSurface } = require( './_jet-common' );
( async () => {
	const t = reporter( 'jet-reviews' );
	const { browser, page, errors } = await launch();
	await login( page );
	const api = apiFor( page );
	let id = 0;
	try {
		const seeded = evalPhp( 'jr', `$d = \\Jet_Reviews\\Reviews\\Data::get_instance(); $r = $d->add_new_review( array( 'source' => 'post', 'post_id' => 1, 'post_type' => 'post', 'author' => get_user_by( 'login', 'admin' )->ID, 'date' => current_time( 'mysql' ), 'title' => 'Minn suite review', 'content' => 'Suite review body text', 'type_slug' => 'default', 'rating_data' => wp_json_encode( array( array( 'title' => 'Quality', 'value' => 60 ) ) ), 'rating' => 60, 'approved' => 0 ) ); echo (int) ( $r['insert_id'] ?? 0 );` );
		id = parseInt( seeded, 10 ) || 0;
		t.check( 'a pending review is seeded through JetReviews', id > 0, seeded.slice( 0, 80 ) );

		const pending = await api( 'minn-admin/v1/jet-reviews/reviews?status=pending' );
		const row = ( pending.body.items || [] ).find( ( i ) => i.id === id );
		t.check( 'the Pending tab lists it with reviewer, item, rating and pill', pending.status === 200 && !! row && /admin/.test( row.author ) && row.item === 'Hello world!' && row.rating === '3/5' && row.status === 'pending', JSON.stringify( row ) );
		const search = await api( 'minn-admin/v1/jet-reviews/reviews?search=' + encodeURIComponent( 'Suite review body' ) );
		t.check( 'search matches the review text', search.status === 200 && ( search.body.items || [] ).some( ( i ) => i.id === id ), JSON.stringify( search.body && search.body.total ) );
		const detail = await api( `minn-admin/v1/jet-reviews/reviews/${ id }` );
		t.check( 'detail is an entry card with reviewer message and the rating breakdown', detail.status === 200 && detail.body.kind === 'entry'
			&& ( detail.body.sections || [] ).some( ( s ) => s.title === 'Reviewer' && s.rows.some( ( r ) => r.label === 'Message' && /Suite review body/.test( r.value ) ) )
			&& ( detail.body.sections || [] ).some( ( s ) => s.title === 'Review' && s.rows.some( ( r ) => r.label === 'Quality' && r.value === '60' ) )
			&& /page=jet-reviews-list-page/.test( detail.body.adminUrl || '' ), JSON.stringify( ( detail.body.sections || [] ).map( ( s ) => s.title ) ) );
		const approve = await api( `minn-admin/v1/jet-reviews/reviews/${ id }/approve`, { method: 'POST', body: { approved: true } } );
		const stored = evalPhp( 'jr2', `global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT approved FROM {$wpdb->prefix}jet_reviews WHERE id = %d", ${ id } ) );` );
		t.check( 'approve writes the row the way their toggle does', approve.status === 200 && approve.body.ok && stored === '1', JSON.stringify( { approve: approve.body, stored } ) );
		const approved = await api( 'minn-admin/v1/jet-reviews/reviews?status=approved' );
		t.check( 'the Approved tab now lists it', ( approved.body.items || [] ).some( ( i ) => i.id === id ) );
		const un = await api( `minn-admin/v1/jet-reviews/reviews/${ id }/approve`, { method: 'POST', body: { approved: false } } );
		t.check( 'unapprove flips it back', un.status === 200 && un.body.status === 'pending' );
		const st = await api( 'minn-admin/v1/jet-reviews/status' );
		t.check( 'status card carries pending, total, average and Open JetReviews', st.status === 200 && [ 'Pending', 'Reviews', 'Average' ].every( ( l ) => ( st.body.rows || [] ).some( ( r ) => r.label === l ) ) && ( st.body.actions || [] ).some( ( a ) => /Open JetReviews/.test( a.label ) ), JSON.stringify( st.body ) );

		const { ctx, page: editorPage } = await loginAs( browser, 'minn-editor', 'minn-editor-pass-1' );
		const denied = await editorPage.evaluate( async () => ( await fetch( window.MINN.restUrl + 'minn-admin/v1/jet-reviews/reviews', { headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' } ) ).status );
		t.check( 'an Editor is refused (their screens gate on manage_options)', denied === 403, String( denied ) );
		await ctx.close();

		const del = await api( `minn-admin/v1/jet-reviews/reviews/${ id }`, { method: 'DELETE' } );
		const gone = evalPhp( 'jr3', `global $wpdb; echo (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}jet_reviews WHERE id = %d", ${ id } ) );` );
		t.check( 'delete removes the review through their data class', del.status === 200 && gone === '0', JSON.stringify( { del: del.status, gone } ) );
		id = 0;

		t.check( 'the Reviews surface paints with its status card', await paintSurface( page, BASE, 'jet-reviews', 'Pending' ) && await page.evaluate( () => !! document.querySelector( '.minn-surface-status' ) ) );
	} finally {
		if ( id ) evalPhp( 'jr4', `global $wpdb; $wpdb->delete( $wpdb->prefix . 'jet_reviews', array( 'id' => ${ id } ) );` );
		await t.done( browser, errors );
	}
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
