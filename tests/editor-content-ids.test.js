/**
 * Post content cannot reach Minn's own controls by id.
 *
 * The editor renders stored HTML in the same document as Minn's chrome, and
 * post kses keeps id, for and form on an Author's or Contributor's markup.
 * A content element carrying one of Minn's ids came first in tree order, so
 * the side panel (status, Publish, Trash) rendered INTO the editable body and
 * the next save wrote it into the post; a content <label for> clicked Minn's
 * real Publish button from inside the text, so an editor clicking into a
 * Contributor's pending post published it.
 *
 * Fixtures: three posts written as minn-author through wp_insert_post (the
 * same kses a REST save runs), removed after.
 */
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { execSync } = require( 'child_process' );
const { launch, login, reporter, openEditor } = require( './helpers' );

const WP_PATH = path.resolve( __dirname, '../../../..' );
const wpEval = ( php ) => {
	const file = path.join( os.tmpdir(), `minn-ecid-${ process.pid }.php` );
	fs.writeFileSync( file, '<?php ' + php );
	try {
		return execSync( `wp --path=${ JSON.stringify( WP_PATH ) } eval-file ${ JSON.stringify( file ) } 2>/dev/null`, { encoding: 'utf8', timeout: 90000 } ).trim();
	} finally {
		fs.unlinkSync( file );
	}
};
const asAuthor = ( title, status, content ) => parseInt( wpEval( `
	$u = get_user_by( 'login', 'minn-author' );
	wp_set_current_user( $u ? $u->ID : 0 );
	kses_init();
	echo (int) wp_insert_post( array( 'post_title' => ${ JSON.stringify( title ) }, 'post_status' => ${ JSON.stringify( status ) }, 'post_author' => $u ? $u->ID : 0, 'post_content' => ${ JSON.stringify( content ) } ) );
` ), 10 ) || 0;
const stored = ( id ) => JSON.parse( wpEval( `$p = get_post( ${ id } ); echo wp_json_encode( $p ? array( 'status' => $p->post_status, 'content' => $p->post_content ) : null );` ) || 'null' );

( async () => {
	const t = reporter( 'editor-content-ids' );
	const { browser, page, errors } = await launch();
	await login( page );
	await page.setViewportSize( { width: 1440, height: 1000 } );
	const made = [];
	const writes = [];
	page.on( 'request', ( r ) => {
		if ( [ 'POST', 'PUT', 'DELETE' ].includes( r.method() ) && /wp\/v2\/posts\/\d+/.test( r.url() ) ) {
			writes.push( { method: r.method(), url: r.url(), body: r.postData() || '' } );
		}
	} );
	// The editor's own save (⌘S), waited out on its response.
	const save = async ( id ) => {
		const done = page.waitForResponse( ( r ) => r.request().method() === 'POST' && new RegExp( `wp/v2/posts/${ id }(\\?|$)` ).test( r.url() ), { timeout: 30000 } );
		await page.keyboard.press( 'Meta+s' );
		await done;
		await page.waitForTimeout( 800 );
	};
	const typeInFirstParagraph = async () => {
		await page.click( '#minn-editor-body p' );
		await page.keyboard.press( 'End' );
		await page.keyboard.type( '!' );
	};

	try {
		const authorOk = wpEval( `echo get_user_by( 'login', 'minn-author' ) ? 'yes' : 'no';` );
		t.check( 'fixture author exists', 'yes' === authorOk, authorOk );

		// 1. Content wearing Minn's own ids.
		const shadowIds = [ 'minn-editor-side', 'minn-overlays', 'minn-outline', 'minn-saved-state', 'minn-publish-btn' ];
		const shadow = asAuthor( 'Minn content ids: shadow', 'pending',
			'<!-- wp:paragraph --><p>Hello from the author.</p><!-- /wp:paragraph -->'
			+ '<!-- wp:html --><div id="minn-editor-side"></div><div id="minn-overlays"></div><div id="minn-outline"></div><span id="minn-saved-state">x</span><button id="minn-publish-btn">p</button><!-- /wp:html -->' );
		made.push( shadow );
		const shadowStored = stored( shadow );
		t.check( 'kses keeps Minn\'s ids on an Author\'s markup (the precondition)', shadowIds.every( ( x ) => shadowStored.content.includes( `id="${ x }"` ) ), shadow + '' );
		await openEditor( page, shadow );
		await page.waitForSelector( '.minn-editor-side #minn-status-state', { timeout: 30000 } ).catch( () => null );
		await page.waitForTimeout( 1500 );
		const placed = await page.evaluate( () => {
			const body = document.querySelector( '#minn-editor-body' );
			const side = [ ...document.querySelectorAll( '.minn-editor-side' ) ].find( ( e ) => ! body.contains( e ) );
			return {
				sideHasPanel: !! ( side && side.querySelector( '#minn-status-state' ) ),
				minnControlsInBody: [ '#minn-status-state', '#minn-trash-post', '#minn-schedule-input', '.minn-side-key' ].filter( ( s ) => body.querySelector( s ) ),
			};
		} );
		t.check( 'the side panel renders in its own column', placed.sideHasPanel, JSON.stringify( placed ) );
		t.check( 'none of Minn\'s controls render inside the post body', ! placed.minnControlsInBody.length, JSON.stringify( placed.minnControlsInBody ) );
		await typeInFirstParagraph();
		await save( shadow );
		const shadowAfter = stored( shadow );
		t.check( 'an edit saves (control)', /Hello from the author\.!/.test( shadowAfter.content ), shadowAfter.content.slice( 0, 120 ) );
		t.check( 'the save writes none of Minn\'s interface into the post', ! /Publish time|Featured image|Public preview link|minn-side-key|minn-status-state/.test( shadowAfter.content ), shadowAfter.content.length + ' bytes' );
		// Custom HTML is edited as rich text, so its divs come back as
		// paragraphs (their ids with them, as for any div); the inline
		// elements keep theirs, restored from where they were parked.
		t.check( 'the author\'s own ids are saved back as written', [ 'minn-saved-state', 'minn-publish-btn' ].every( ( x ) => shadowAfter.content.includes( `id="${ x }"` ) ) && ! /data-minn-inert-/.test( shadowAfter.content ), shadowAfter.content.slice( 0, 200 ) );
		t.check( 'the post is still pending', 'pending' === shadowAfter.status, shadowAfter.status );

		// 2. Labels aimed at Minn's buttons.
		const labels = asAuthor( 'Minn content ids: labels', 'pending',
			'<!-- wp:paragraph --><p><label for="minn-trash-post">Read the trash note</label></p><!-- /wp:paragraph -->'
			+ '<!-- wp:paragraph --><p><label for="minn-publish-btn">Read the publish note</label></p><!-- /wp:paragraph -->'
			+ '<!-- wp:paragraph --><p><button type="button" popovertarget="minn-overlays" commandfor="minn-publish-btn" command="--x">Open note</button></p><!-- /wp:paragraph -->' );
		made.push( labels );
		await openEditor( page, labels );
		await page.waitForSelector( '.minn-editor-side #minn-publish-btn', { timeout: 30000 } ).catch( () => null );
		await page.waitForTimeout( 1000 );
		const before = writes.length;
		for ( const txt of [ 'Read the trash note', 'Read the publish note', 'Open note' ] ) {
			await page.click( `#minn-editor-body :text("${ txt }")` );
			await page.waitForTimeout( 2000 );
			if ( await page.$( '.minn-confirm-modal' ) ) {
				t.check( `clicking "${ txt }" opens none of Minn's confirmations`, false );
				await page.keyboard.press( 'Escape' );
			}
		}
		const labelsAfter = stored( labels );
		const published = writes.slice( before ).filter( ( w ) => /"status":"publish"/.test( w.body ) || 'DELETE' === w.method );
		t.check( 'clicking a label in the text publishes nothing', 'pending' === labelsAfter.status && ! published.length, labelsAfter.status + ' ' + JSON.stringify( published.map( ( w ) => w.method ) ) );
		t.check( 'and trashes nothing', 'trash' !== labelsAfter.status, labelsAfter.status );
		const live = await page.evaluate( () => {
			const b = document.querySelector( '#minn-editor-body' );
			return [ ...b.querySelectorAll( 'label, button' ) ].map( ( e ) => [ e.getAttribute( 'for' ), e.getAttribute( 'popovertarget' ), e.getAttribute( 'commandfor' ) ] );
		} );
		t.check( 'for, popovertarget and commandfor are inert while the post is open', live.every( ( a ) => a.every( ( v ) => null === v ) ), JSON.stringify( live ) );

		// 3. Control: ordinary ids, an in-page link, a label for content, a field.
		const plainHtml = '<h2 id="intro">Intro</h2><p><a href="#intro">Back to intro</a></p><p><label for="note">Note</label></p><textarea id="note" name="note">x</textarea>';
		const plain = asAuthor( 'Minn content ids: control', 'draft',
			'<!-- wp:paragraph --><p>Edit me.</p><!-- /wp:paragraph -->'
			+ `<!-- wp:html -->${ plainHtml }<!-- /wp:html -->` );
		made.push( plain );
		await openEditor( page, plain );
		await page.waitForTimeout( 1500 );
		t.check( 'an ordinary content id stays live in the editor', await page.evaluate( () => !! document.querySelector( '#minn-editor-body #intro' ) ) );
		await typeInFirstParagraph();
		await save( plain );
		const plainAfter = stored( plain );
		// The editor turns a Custom HTML block of plain markup into native
		// blocks, so the bytes around them move; every attribute must not.
		const kept = [ 'id="intro"', 'href="#intro"', 'for="note"', 'id="note"', 'name="note"' ].filter( ( a ) => ! plainAfter.content.includes( a ) );
		t.check( 'ids, links, labels and field names the author wrote save back as written', ! kept.length && ! /data-minn-inert-/.test( plainAfter.content ) && /Edit me\.!/.test( plainAfter.content ), kept.length ? 'lost ' + kept.join( ' ' ) : plainAfter.content.slice( 0, 200 ) );
	} finally {
		for ( const id of made ) {
			if ( id ) wpEval( `wp_delete_post( ${ id }, true );` );
		}
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
