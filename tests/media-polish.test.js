/**
 * Core media polish — the Unattached filter (core's parent=0 query), the
 * month combobox (minn-admin/v1/media/months feeding after/before windows)
 * and the detail modal's "Attached to" row with its editor jump. Fixtures:
 * one PNG attached to a disposable draft post, one unattached PNG.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'media-polish' );
	const { browser, page, errors } = await launch();
	await login( page );
	await page.goto( BASE + '/minn-admin/media', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN, null, { timeout: 20000 } );

	// Fixtures: a draft post, a PNG attached to it, a PNG attached to nothing.
	const fx = await page.evaluate( async () => {
		const head = { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' };
		const post = await ( await fetch( window.MINN.restUrl + 'wp/v2/posts', {
			method: 'POST', headers: head, credentials: 'same-origin',
			body: JSON.stringify( { title: 'Media Polish Suite Post', status: 'draft' } ),
		} ) ).json();
		const png = async ( color, name, parent ) => {
			const canvas = document.createElement( 'canvas' );
			canvas.width = 640;
			canvas.height = 480;
			const ctx = canvas.getContext( '2d' );
			ctx.fillStyle = color;
			ctx.fillRect( 0, 0, 640, 480 );
			const blob = await new Promise( ( r ) => canvas.toBlob( r, 'image/png' ) );
			const fd = new FormData();
			fd.append( 'file', blob, name );
			if ( parent ) fd.append( 'post', String( parent ) );
			const res = await fetch( window.MINN.restUrl + 'wp/v2/media', {
				method: 'POST', headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin', body: fd,
			} );
			return ( await res.json() ).id;
		};
		return {
			post: post.id,
			attached: await png( '#3a6ea5', 'minn-polish-attached.png', post.id ),
			loose: await png( '#3aa56e', 'minn-polish-loose.png', 0 ),
		};
	} );
	t.check( 'fixtures created', !! ( fx.post && fx.attached && fx.loose ), JSON.stringify( fx ) );

	try {
		await page.goto( BASE + '/minn-admin/media', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( `[data-media="${ fx.attached }"]`, { timeout: 20000 } );
		t.check( 'toolbar shows the Unattached filter', !! ( await page.$( '#minn-media-unattached' ) ) );
		t.check( 'toolbar shows the month combobox', !! ( await page.$( '[data-monthcombo] .minn-ac-input' ) ) );

		// Unattached: the loose file stays, the attached one drops out.
		await page.click( '#minn-media-unattached' );
		await page.waitForFunction( ( id ) => ! document.querySelector( `[data-media="${ id }"]` ), fx.attached, { timeout: 20000 } );
		t.check( 'attached file drops out under Unattached', true );
		t.check( 'loose file stays under Unattached', !! ( await page.$( `[data-media="${ fx.loose }"]` ) ) );
		t.check( 'filter pill reads active', await page.$eval( '#minn-media-unattached', ( b ) => b.classList.contains( 'active' ) ) );

		await page.click( '#minn-media-unattached' );
		await page.waitForSelector( `[data-media="${ fx.attached }"]`, { timeout: 20000 } );
		t.check( 'toggling back restores the attached file', true );

		// Month filter: the current month is a listed option and keeps the
		// fresh fixtures; the combobox seeds and applies through a real pick.
		const now = new Date();
		const ym = `${ now.getFullYear() }-${ String( now.getMonth() + 1 ).padStart( 2, '0' ) }`;
		await page.waitForSelector( `[data-monthcombo] .minn-ac-input`, { timeout: 8000 } );
		await page.click( '[data-monthcombo] .minn-ac-input' );
		await page.waitForSelector( `[data-monthcombo] .minn-ac-item[data-acv="${ ym }"]`, { timeout: 8000 } );
		t.check( 'current month is an option', true );
		await page.click( `[data-monthcombo] .minn-ac-item[data-acv="${ ym }"]` );
		await page.waitForSelector( `[data-media="${ fx.attached }"]`, { timeout: 20000 } );
		t.check( 'month filter keeps this month\'s uploads', !! ( await page.$( `[data-media="${ fx.loose }"]` ) ) );

		// Back to all dates so the modal checks see both files regardless.
		await page.click( '[data-monthcombo] .minn-ac-input' );
		await page.waitForSelector( '[data-monthcombo] .minn-ac-item[data-acv=""]', { timeout: 8000 } );
		await page.click( '[data-monthcombo] .minn-ac-item[data-acv=""]' );
		await page.waitForSelector( `[data-media="${ fx.attached }"]`, { timeout: 20000 } );

		// Attached-to row: title shown, click lands in the parent's editor.
		await page.click( `[data-media="${ fx.attached }"]` );
		await page.waitForSelector( '#minn-media-attached', { timeout: 8000 } );
		const attTitle = await page.$eval( '#minn-media-attached', ( b ) => b.textContent.trim() );
		t.check( 'modal names the parent post', attTitle === 'Media Polish Suite Post', attTitle );
		await page.click( '#minn-media-attached' );
		await page.waitForSelector( '#minn-editor-title', { timeout: 20000 } );
		await page.waitForFunction( () => {
			const el = document.querySelector( '#minn-editor-title' );
			return el && el.value === 'Media Polish Suite Post';
		}, null, { timeout: 20000 } );
		t.check( 'Attached to jumps into the parent editor', true );
		t.check( 'editor route carries the parent id', await page.evaluate( ( id ) => location.pathname.includes( '/editor/posts/' + id ), fx.post ) );

		// The loose file honestly says Unattached (plain text, no jump).
		await page.goto( BASE + '/minn-admin/media', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( `[data-media="${ fx.loose }"]`, { timeout: 20000 } );
		await page.click( `[data-media="${ fx.loose }"]` );
		await page.waitForFunction( () => {
			const rows = Array.from( document.querySelectorAll( '.minn-modal .minn-side-row' ) );
			return rows.some( ( r ) => r.textContent.includes( 'Attached to' ) && r.textContent.includes( 'Unattached' ) );
		}, null, { timeout: 8000 } );
		t.check( 'unattached file reads Unattached in the modal', true );
		t.check( 'no editor jump for an unattached file', ! ( await page.$( '#minn-media-attached' ) ) );
	} finally {
		await page.evaluate( async ( fx2 ) => {
			const head = { 'X-WP-Nonce': window.MINN.nonce };
			for ( const id of [ fx2.attached, fx2.loose ] ) {
				await fetch( window.MINN.restUrl + 'wp/v2/media/' + id + '?force=true', { method: 'DELETE', headers: head, credentials: 'same-origin' } ).catch( () => {} );
			}
			await fetch( window.MINN.restUrl + 'wp/v2/posts/' + fx2.post + '?force=true', { method: 'DELETE', headers: head, credentials: 'same-origin' } ).catch( () => {} );
		}, fx ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
