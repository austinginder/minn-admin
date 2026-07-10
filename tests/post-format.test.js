/**
 * Per-post format picker (core-gaps bundle). post_format sits in TAX_SKIP, so
 * format-driven themes couldn't be worked in Minn; only the site default was
 * settable. The editor sidebar now has a Format select, gated on the theme
 * declaring post-format support (the minn-dev-fixtures mu-plugin declares a
 * representative set), that saves through wp/v2's native `format` field.
 *
 * Uses a throwaway draft; deletes it in the finally.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'post-format' );
	const { browser, page, errors } = await launch();
	await login( page );

	const rest = ( path, opts ) => page.evaluate( async ( [ p, o ] ) => {
		const r = await fetch( window.MINN.restUrl + p, Object.assign( {
			headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
		}, o || {} ) );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, [ path, opts ] );

	let postId = null;
	try {
		const created = await rest( 'wp/v2/posts', { method: 'POST', body: JSON.stringify( {
			title: 'Minn format picker test', status: 'draft', content: 'Format test body.',
		} ) } );
		postId = created.body && created.body.id;
		t.check( 'draft post created', !! postId, String( postId ) );
		t.check( 'new post defaults to standard format', created.body && created.body.format === 'standard', created.body && created.body.format );

		await page.goto( BASE + `/minn-admin/editor/posts/${ postId }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-post-format', { timeout: 20000 } );

		// Boot payload exposes the theme's supported formats.
		const bootFormats = await page.evaluate( () => Object.keys( window.MINN.postFormats || {} ) );
		t.check( 'boot payload lists supported formats incl. standard + aside', bootFormats.includes( 'standard' ) && bootFormats.includes( 'aside' ), bootFormats.join( ',' ) );

		const initial = await page.$eval( '#minn-post-format', ( s ) => s.value );
		t.check( 'picker starts on the post\'s current format', initial === 'standard', initial );

		// Choose Aside and save the draft.
		await page.selectOption( '#minn-post-format', 'aside' );
		await page.click( '#minn-save-draft-btn' );

		// Poll the server for the saved format.
		let saved = '';
		for ( let i = 0; i < 20; i++ ) {
			const r = await rest( `wp/v2/posts/${ postId }?context=edit&_fields=format` );
			saved = r.body && r.body.format;
			if ( saved === 'aside' ) break;
			await page.waitForTimeout( 500 );
		}
		t.check( 'chosen format saved to the server', saved === 'aside', saved );

		// Reload and confirm the picker reflects the saved value.
		await page.goto( BASE + `/minn-admin/editor/posts/${ postId }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-post-format', { timeout: 20000 } );
		const reloaded = await page.$eval( '#minn-post-format', ( s ) => s.value );
		t.check( 'picker reflects the saved format after reload', reloaded === 'aside', reloaded );

	} finally {
		if ( postId ) await rest( `wp/v2/posts/${ postId }?force=true`, { method: 'DELETE' } ).catch( () => {} );
	}
	await t.done( browser, errors );
} )();
