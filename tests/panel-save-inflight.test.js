/**
 * A field panel edit made while a save is in flight is saved by the next
 * one.
 *
 * Panels send only the fields changed since their baseline, and after a
 * save the baseline became whatever the panel held when the response came
 * back. A field edited while the save was on its way was folded into it
 * unsent, its dirty flag cleared, and no later save carried it: it showed
 * in the panel and was never stored. The editor also read as saved. The
 * baseline now moves to what the save sent, and an edit made after the
 * payload was taken leaves the editor unsaved.
 *
 * Fixture: an ACF panel post (group_minn_test, the acf-panel suite's
 * "Post details": subtitle, editor_notes), made and deleted here. The
 * first save's response is held for a few seconds to open the window.
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'panel-save-inflight' );
	const { browser, page, errors } = await launch();
	await login( page );
	const id = await createPost( page, { title: 'Panel in-flight test', content: '<p>x</p>' } );
	const saveUrl = new RegExp( 'wp/v2/posts/' + id + '(\\?|$)' );
	const readAcf = () => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=acf', { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return ( await r.json() ).acf || {};
	}, id );
	const nextSave = () => page.waitForResponse( ( r ) => 'POST' === r.request().method() && saveUrl.test( r.url() ), { timeout: 30000 } );

	try {
		await openEditor( page, id );
		await page.waitForSelector( '[data-side-door="panel:acf"]', { timeout: 15000 } );
		await page.click( '[data-side-door="panel:acf"]' );
		const subtitle = '[data-pf$=":subtitle"]';
		const notes = '[data-pf$=":editor_notes"]';
		await page.waitForSelector( subtitle, { timeout: 10000 } );
		t.check( 'the panel shows both fields', !! await page.$( notes ) );

		// Hold the first save's request so a second edit lands mid-flight.
		const hold = async ( route ) => {
			if ( 'POST' === route.request().method() ) await new Promise( ( r ) => setTimeout( r, 3000 ) );
			await route.continue().catch( () => {} );
		};
		await page.route( saveUrl, hold );
		await page.fill( subtitle, 'Sent first' );
		const first = nextSave();
		await page.keyboard.press( 'Meta+s' );
		await page.waitForTimeout( 600 );
		await page.fill( notes, 'Typed while saving' );
		await first;
		await page.unroute( saveUrl, hold );
		await page.waitForTimeout( 500 );

		const stateAfter = await page.evaluate( () => ( { dirty: !! window.MINN && !! document.querySelector( '#minn-saved-state.amber' ), text: ( document.querySelector( '#minn-saved-state' ) || {} ).textContent } ) );
		t.check( 'the editor still reads as unsaved', stateAfter.dirty, JSON.stringify( stateAfter ) );

		const second = nextSave();
		await page.keyboard.press( 'Meta+s' );
		const res = await second;
		const body = JSON.parse( res.request().postData() || '{}' );
		await page.waitForTimeout( 400 );
		const sentPanel = body.minn_acf || body.acf || {};
		t.check( 'the next save sends the field typed mid-flight', 'Typed while saving' === sentPanel.editor_notes, JSON.stringify( sentPanel ) );
		const stored = await readAcf();
		t.check( 'and both fields are stored', 'Sent first' === stored.subtitle && 'Typed while saving' === stored.editor_notes, JSON.stringify( { subtitle: stored.subtitle, notes: stored.editor_notes } ) );

		const third = nextSave();
		await page.keyboard.press( 'Meta+s' );
		const res3 = await third;
		const body3 = JSON.parse( res3.request().postData() || '{}' );
		t.check( 'a save after that sends neither again', ! ( 'minn_acf' in body3 ) && ! ( 'acf' in body3 ), JSON.stringify( Object.keys( body3 ) ) );

		// A panel the save did not send: change only the title, save, and
		// edit a field while that save is in flight.
		await page.waitForTimeout( 400 );
		await page.route( saveUrl, hold );
		await page.fill( '#minn-editor-title', 'Panel in-flight test, retitled' );
		const fourth = nextSave();
		await page.keyboard.press( 'Meta+s' );
		await page.waitForTimeout( 600 );
		await page.fill( subtitle, 'Typed while the title saved' );
		const res4 = await fourth;
		await page.unroute( saveUrl, hold );
		const body4 = JSON.parse( res4.request().postData() || '{}' );
		t.check( 'control: the title-only save sent no panel', ! ( 'minn_acf' in body4 ) && ! ( 'acf' in body4 ), JSON.stringify( Object.keys( body4 ) ) );
		await page.waitForTimeout( 500 );
		const fifth = nextSave();
		await page.keyboard.press( 'Meta+s' );
		const res5 = await fifth;
		const body5 = JSON.parse( res5.request().postData() || '{}' );
		await page.waitForTimeout( 400 );
		const sent5 = body5.minn_acf || body5.acf || {};
		t.check( 'an edit to a panel the in-flight save did not send goes with the next one', 'Typed while the title saved' === sent5.subtitle, JSON.stringify( sent5 ) );
		t.check( 'and it is stored', 'Typed while the title saved' === ( await readAcf() ).subtitle );
	} catch ( e ) {
		t.check( 'suite ran without throwing', false, e.message );
	} finally {
		await deletePost( page, id ).catch( () => {} );
	}
	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
