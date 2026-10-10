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
 * The same goes for the fields inside the shortcode, details and buttons
 * islands: content wearing their classes must not stand in for them.
 *
 * Fixtures: six posts written as minn-author through wp_insert_post (the
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

		// 4. Content dressed as an island's fields. kses keeps class, data-*
		// and a button's value, so an Author's markup can wear the shortcode,
		// details and buttons field classes and point at a real island. The
		// save used to read those look-alikes back as the island's value: the
		// shortcode one went into the post as raw markup, script included,
		// once someone who can post unfiltered HTML saved.
		const forged = asAuthor( 'Minn content ids: island fields', 'pending',
			'<!-- wp:shortcode -->\n[gallery]\n<!-- /wp:shortcode -->'
			+ '<!-- wp:details --><details class="wp-block-details"><summary>Real summary</summary><!-- wp:paragraph --><p>Hidden text</p><!-- /wp:paragraph --></details><!-- /wp:details -->'
			+ '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://real.example/">Real label</a></div><!-- /wp:button --></div><!-- /wp:buttons -->'
			+ '<!-- wp:paragraph --><p>Edit me.</p><!-- /wp:paragraph -->'
			+ '<!-- wp:paragraph --><p><button class="minn-shortcode-input" data-shortcode="0" value="&lt;script&gt;window.minnForged=1&lt;/script&gt;">a</button></p><!-- /wp:paragraph -->'
			+ '<!-- wp:paragraph --><p><span class="minn-block-island minn-details-island" data-island="1"><button class="minn-details-summary" value="Forged summary">b</button><span class="minn-details-body">Forged body</span></span></p><!-- /wp:paragraph -->'
			+ '<!-- wp:paragraph --><p><span class="minn-block-island minn-buttons-island" data-island="2" data-btn-stamped="1"><span class="minn-btn-row"><button class="minn-btn-label" value="Forged label">c</button><button class="minn-btn-url" value="https://forged.example/">d</button></span></span></p><!-- /wp:paragraph -->' );
		made.push( forged );
		const forgedStored = stored( forged );
		t.check( 'kses keeps the look-alike fields on an Author\'s markup (the precondition)', /class="minn-shortcode-input"/.test( forgedStored.content ) && /value="[^"]*script/.test( forgedStored.content ), forged + '' );
		await openEditor( page, forged );
		await page.waitForSelector( '#minn-editor-body input.minn-shortcode-input', { timeout: 30000 } ).catch( () => null );
		await page.waitForTimeout( 1500 );
		const islands = await page.evaluate( () => [ 'shortcode', 'details', 'buttons' ].map( ( k ) => {
			const el = document.querySelector( `#minn-editor-body .minn-${ k }-island:not(span)` );
			return el ? el.dataset.island : null;
		} ) );
		t.check( 'the real islands are 0, 1 and 2 (the look-alikes aim at them)', '0,1,2' === islands.join( ',' ), JSON.stringify( islands ) );
		await page.click( '#minn-editor-body p:has-text("Edit me.")' );
		await page.keyboard.press( 'End' );
		await page.keyboard.type( '!' );
		await save( forged );
		const forgedAfter = stored( forged );
		t.check( 'an edit saves (control)', /Edit me\.!/.test( forgedAfter.content ), forgedAfter.content.slice( 0, 160 ) );
		// The look-alike itself saves back as written; only a script outside
		// an attribute value would run.
		const outsideValues = forgedAfter.content.replace( /value="[^"]*"/g, '' );
		t.check( 'the shortcode block keeps its shortcode, no script', /<!-- wp:shortcode -->\s*\[gallery\]\s*<!-- \/wp:shortcode -->/.test( forgedAfter.content ) && ! /<script/i.test( outsideValues ), forgedAfter.content.slice( 0, 160 ) );
		t.check( 'the details block keeps its summary', /<summary>Real summary<\/summary>/.test( forgedAfter.content ) && ! /<summary>Forged summary/.test( forgedAfter.content ), ( forgedAfter.content.match( /<summary>[^<]*<\/summary>/ ) || [ '' ] )[ 0 ] );
		t.check( 'the buttons block keeps its button', /href="https:\/\/real\.example\/"[^>]*>Real label</.test( forgedAfter.content ) && ! /href="https:\/\/forged\.example/.test( forgedAfter.content ), ( forgedAfter.content.match( /<a class="wp-block-button__link[^>]*>[^<]*/ ) || [ '' ] )[ 0 ] );

		// 5. Control: the real fields still save what was typed in them.
		await openEditor( page, forged );
		await page.waitForSelector( '#minn-editor-body input.minn-shortcode-input', { timeout: 30000 } );
		await page.waitForTimeout( 1000 );
		await page.fill( '#minn-editor-body input.minn-shortcode-input', '[gallery columns="2"]' );
		await page.fill( '#minn-editor-body input.minn-details-summary', 'Edited summary' );
		await page.fill( '#minn-editor-body input.minn-btn-label', 'Edited label' );
		await save( forged );
		const realAfter = stored( forged );
		t.check( 'typing in the real shortcode, summary and label fields saves them', /\[gallery columns="2"\]/.test( realAfter.content ) && /<summary>Edited summary<\/summary>/.test( realAfter.content ) && />Edited label</.test( realAfter.content ), realAfter.content.slice( 0, 300 ) );

		// 6. Content naming the page's own members. document's named lookup
		// wins over its members, so a kses-clean <object id="querySelector">
		// replaced document.querySelector and stopped the editor, and
		// id="body" moved Minn's popovers into the post; any element's id
		// shadows what window inherits (addEventListener).
		const uploads = wpEval( `echo wp_upload_dir()['baseurl'];` );
		const clobber = asAuthor( 'Minn content ids: built-ins', 'pending',
			'<!-- wp:paragraph --><p id="addEventListener">Edit me.</p><!-- /wp:paragraph -->'
			+ `<!-- wp:paragraph --><p>Read the PDFs: <object id="querySelector" type="application/pdf" data="${ uploads }/minn-a.pdf"></object><object id="body" type="application/pdf" data="${ uploads }/minn-b.pdf"></object></p><!-- /wp:paragraph -->` );
		made.push( clobber );
		t.check( 'kses keeps the object ids on an Author\'s markup (the precondition)', /<object id="querySelector"/.test( stored( clobber ).content ), '' );
		await openEditor( page, clobber );
		await page.waitForSelector( '.minn-editor-side #minn-status-state', { timeout: 30000 } ).catch( () => null );
		await page.waitForTimeout( 1000 );
		const builtins = await page.evaluate( () => ( {
			qs: typeof document.querySelector,
			body: document.body && document.body.tagName,
			ael: typeof window.addEventListener,
			side: !! Document.prototype.querySelector.call( document, '.minn-editor-side #minn-status-state' ),
		} ) );
		t.check( 'content cannot replace document.querySelector, document.body or window.addEventListener', 'function' === builtins.qs && 'BODY' === builtins.body && 'function' === builtins.ael && builtins.side, JSON.stringify( builtins ) );
		await typeInFirstParagraph();
		await save( clobber );
		const clobberAfter = stored( clobber );
		t.check( 'the ids save back as written', /id="querySelector"/.test( clobberAfter.content ) && /id="addEventListener"/.test( clobberAfter.content ) && /Edit me\.!/.test( clobberAfter.content ) && ! /data-minn-inert-/.test( clobberAfter.content ), clobberAfter.content.slice( 0, 600 ) );

		// 7. The rich-text modal over the editor. A field's stored HTML seeds
		// it, and a <label for> in it reached the editor's Publish button
		// behind the modal: the first click inside published the post.
		const rt = asAuthor( 'Minn content ids: rich-text modal', 'pending', '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->' );
		made.push( rt );
		wpEval( `update_post_meta( ${ rt }, 'slideshow_notes', '<p><label for="minn-publish-btn">Please review this note</label></p>' ); update_post_meta( ${ rt }, '_slideshow_notes', 'field_minn_norest_notes' );` );
		await openEditor( page, rt );
		const rtSel = '[data-pf$=":slideshow_notes"][data-ftype="wysiwyg"]';
		await page.waitForSelector( '[data-side-door="panel:acf"]', { timeout: 15000 } ).catch( () => null );
		if ( ! await page.$( '[data-side-door="panel:acf"]' ) ) {
			t.check( 'ACF panel available (skip)', true, 'no ACF panel' );
		} else {
			await page.click( '[data-side-door="panel:acf"]' );
			await page.waitForSelector( rtSel, { timeout: 15000 } );
			await page.click( `${ rtSel } [data-rt-edit]` );
			await page.waitForSelector( '.minn-rt-body label', { timeout: 10000 } );
			const rtBefore = writes.length;
			await page.click( '.minn-rt-body label' );
			await page.waitForTimeout( 2000 );
			const rtPublished = writes.slice( rtBefore ).filter( ( w ) => /"status":"publish"/.test( w.body ) );
			t.check( 'clicking a label in the rich-text modal publishes nothing', 'pending' === stored( rt ).status && ! rtPublished.length, stored( rt ).status );
			t.check( 'the label in the modal points at nothing of Minn\'s', null === await page.$eval( '.minn-rt-body label', ( e ) => e.getAttribute( 'for' ) ), '' );
			await page.click( '#minn-rt-cancel' ).catch( () => null );
		}
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
