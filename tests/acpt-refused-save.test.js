/**
 * An ACPT value ACPT refuses, on a new post's first save and after.
 *
 * ACPT's setter refuses some values a field's own control lets through (a
 * number above the field's max: nothing submits a form, so the input's max
 * attribute stops nothing). The post itself is saved all the same. That save
 * answered 400, so the editor never learned the new post's id, and each
 * retry or autosave created another draft. The answer now keeps the post's
 * status and names the refused field: the editor takes the new id, shows
 * the field's error, keeps that field unsaved, and the next save updates the
 * same post.
 *
 * Fixture: an ACPT group on posts made for the run through Minn's own
 * builder routes (a Number "Qty" with max 9 and a Text "Note"): ACPT caches
 * its queries in files, and a group written from WP-CLI may not reach the
 * site's own requests. It is deleted through the same routes, and every post
 * titled for the run is removed however the run ends.
 */
const { BASE, launch, login, reporter, onExit } = require( './helpers' );
const { evalPhp, apiFor } = require( './_jet-common' );

( async () => {
	const t = reporter( 'acpt-refused-save' );
	const tag = String( process.pid );
	const title = 'Minn ACPT refused ' + tag;
	const groupTitle = 'Minn refused ' + tag;
	let groupId = '';
	// A killed run: the posts by the run's own title, and the group by its id.
	const cleanup = onExit( () => {
		evalPhp( 'acpt-refused-clean', `
			foreach ( get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'title' => ${ JSON.stringify( title ) }, 'fields' => 'ids', 'posts_per_page' => 50 ) ) as $id ) {
				if ( (int) $id > 0 && ${ JSON.stringify( title ) } === get_the_title( $id ) ) { wp_delete_post( (int) $id, true ); }
			}
			$gid = ${ JSON.stringify( groupId ) };
			if ( '' !== $gid && class_exists( '\\ACPT\\Core\\Repository\\MetaRepository' ) ) {
				$g = \\ACPT\\Core\\Repository\\MetaRepository::get( array( 'id' => $gid ) );
				if ( $g && ${ JSON.stringify( 'minn-refused-' + tag ) } === $g[0]->getName() ) { ( new \\ACPT\\Core\\CQRS\\Command\\DeleteMetaGroupCommand( $gid ) )->execute(); }
			}
			echo 'ok';
		` );
	} );
	const { browser, page, errors } = await launch();
	const api = apiFor( page );
	let ids = null;
	try {
		await login( page );
		if ( ! await page.evaluate( () => ( window.MINN.editorPanels || [] ).some( ( p ) => p.id === 'acpt' ) ) ) {
			t.check( 'SKIP: ACPT is inactive', true );
			return;
		}
		const made = await api( 'minn-admin/v1/acpt/schema/groups', { method: 'POST', body: { title: groupTitle, location: 'post_type:post' } } );
		groupId = ( made.body || {} ).id || '';
		const full = groupId ? await api( `minn-admin/v1/acpt/schema/groups/${ groupId }/full` ) : { body: {} };
		const box = ( ( full.body || {} ).fields || [] )[ 0 ];
		if ( box ) {
			await api( `minn-admin/v1/acpt/schema/groups/${ groupId }/full`, { method: 'POST', body: {
				stamp: full.body.stamp, title: full.body.group.title, location: full.body.group.location,
				fields: [ { key: box.key, label: box.label, sub_fields: [
					{ type: 'number', name: 'qty', label: 'Qty', max: '9' },
					{ type: 'text', name: 'note', label: 'Note' },
				] } ],
			} } );
		}
		const after = groupId ? await api( `minn-admin/v1/acpt/schema/groups/${ groupId }/full` ) : { body: {} };
		ids = {};
		( ( ( ( after.body || {} ).fields || [] )[ 0 ] || {} ).sub_fields || [] ).forEach( ( f ) => { ids[ f.name ] = f.key; } );
		ids.box = ( ( ( after.body || {} ).fields || [] )[ 0 ] || {} ).name;
		if ( ! ids.qty || ! ids.note ) {
			t.check( 'the run\'s ACPT group is made through the builder', false, JSON.stringify( { made: made.status, ids } ) );
			return;
		}
		const count = () => parseInt( evalPhp( 'acpt-refused-count', `echo count( get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'title' => ${ JSON.stringify( title ) }, 'fields' => 'ids', 'posts_per_page' => 50 ) ) );` ), 10 ) || 0;
		const stored = ( id, field ) => evalPhp( 'acpt-refused-read', `echo wp_json_encode( get_acpt_field( array( 'post_id' => ${ parseInt( id, 10 ) || 0 }, 'box_name' => ${ JSON.stringify( ids.box ) }, 'field_name' => ${ JSON.stringify( field ) }, 'format' => 'only_value', 'return' => 'raw' ) ) );` );
		const saves = /\/wp\/v2\/posts(\/\d+)?(\?|$)/;
		const nextSave = () => page.waitForResponse( ( r ) => 'POST' === r.request().method() && saves.test( r.url() ), { timeout: 30000 } );

		await page.goto( `${ BASE }/minn-admin/editor/posts`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-editor-body', { timeout: 20000 } );
		await page.waitForSelector( '[data-side-door="panel:acpt"]', { timeout: 20000 } );
		await page.fill( '#minn-editor-title', title );
		await page.click( '[data-side-door="panel:acpt"]' );
		const qty = `[data-pf$=":${ ids.qty }"]`;
		const note = `[data-pf$=":${ ids.note }"]`;
		await page.waitForSelector( qty, { timeout: 10000 } );
		await page.fill( qty, '100' );
		await page.fill( note, 'Kept note' );

		// The first save creates the post.
		const first = nextSave();
		await page.keyboard.press( 'Meta+s' );
		const r1 = await first;
		let b1 = {};
		try { b1 = await r1.json(); } catch ( e ) { b1 = {}; }
		t.check( 'the first save creates the post and answers with it', 201 === r1.status() && b1.id > 0, r1.status() + ' ' + JSON.stringify( { id: b1.id, code: b1.code } ) );
		t.check( 'the answer names the refused field', Array.isArray( b1.minn_field_errors ) && b1.minn_field_errors.some( ( e ) => e.key === ids.qty ) && /“Qty”/.test( b1.minn_field_message || '' ), JSON.stringify( { errors: b1.minn_field_errors, message: b1.minn_field_message || b1.message } ) );
		await page.waitForTimeout( 400 );
		const toast = await page.evaluate( () => ( document.querySelector( '.minn-toast' ) || {} ).textContent || '' );
		t.check( 'the editor shows which field was not saved', /“Qty”/.test( toast ), toast.trim() );
		await page.waitForFunction( () => /editor\/posts\/\d+/.test( location.pathname ), null, { timeout: 10000 } ).catch( () => {} );
		const newId = await page.evaluate( () => {
			const m = location.pathname.match( /editor\/posts\/(\d+)/ );
			return m ? parseInt( m[ 1 ], 10 ) : 0;
		} );
		t.check( 'the editor takes the new post\'s id', newId > 0 && newId === b1.id, String( newId ) );
		t.check( 'the note beside it is stored', '"Kept note"' === stored( newId || b1.id, 'note' ), stored( newId || b1.id, 'note' ) );
		const unsaved = await page.evaluate( () => !! document.querySelector( '#minn-saved-state.amber' ) );
		t.check( 'the editor still reads as unsaved (the refused field is not stored)', unsaved );

		// Saving again updates that post: no second draft.
		const second = nextSave();
		await page.keyboard.press( 'Meta+s' );
		const r2 = await second;
		let b2 = {};
		try { b2 = await r2.json(); } catch ( e ) { b2 = {}; }
		const body2 = JSON.parse( r2.request().postData() || '{}' );
		t.check( 'the next save updates the same post', new RegExp( '/wp/v2/posts/' + newId + '(\\?|$)' ).test( r2.url() ) && 200 === r2.status(), r2.status() + ' ' + r2.url() );
		t.check( 'and resends only the refused field', body2.minn_acpt && Object.keys( body2.minn_acpt ).join() === ids.qty, JSON.stringify( body2.minn_acpt ) );
		t.check( 'and names it again', Array.isArray( b2.minn_field_errors ) && b2.minn_field_errors.some( ( e ) => e.key === ids.qty ), JSON.stringify( b2.minn_field_errors ) );
		t.check( 'only one post exists for the run', 1 === count(), String( count() ) );

		// A value ACPT takes is stored and the editor reads as saved.
		await page.fill( qty, '7' );
		const third = nextSave();
		await page.keyboard.press( 'Meta+s' );
		const r3 = await third;
		let b3 = {};
		try { b3 = await r3.json(); } catch ( e ) { b3 = {}; }
		await page.waitForTimeout( 500 );
		t.check( 'a value ACPT takes is stored with no field error (control)', 200 === r3.status() && ! ( b3.minn_field_errors || [] ).length && /^"?7"?$/.test( stored( newId, 'qty' ) ), r3.status() + ' ' + stored( newId, 'qty' ) );
		const clean = await page.evaluate( () => ! document.querySelector( '#minn-saved-state.amber' ) );
		t.check( 'and the editor reads as saved', clean );
		t.check( 'still only one post for the run', 1 === count(), String( count() ) );
	} finally {
		// The editor may still autosave: leave it before removing anything.
		await page.goto( `${ BASE }/minn-admin/`, { waitUntil: 'domcontentloaded' } ).catch( () => {} );
		if ( groupId ) {
			const del = await api( `minn-admin/v1/acpt/schema/groups/${ groupId }`, { method: 'DELETE' } ).catch( () => ( {} ) );
			if ( del && 200 === del.status ) groupId = '';
		}
		cleanup();
		await t.done( browser, errors );
	}
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
