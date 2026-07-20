/**
 * Site logo (Settings → Site): the theme's custom_logo theme_mod behind
 * minn-admin/v1/site-logo, gated on the active theme declaring
 * custom-logo support (the dev fixture mu-plugin opens the gate — the
 * marketing theme declares none). Sets via REST, asserts the field
 * renders the saved logo, then drives Remove + Save through the real UI.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'site-logo' );
	await login( page );

	const api = ( path, opts ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, Object.assign( {
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
		}, a.opts || {} ) );
		return r.json();
	}, { path, opts } );

	let mediaId = null;
	try {
		const base = await api( 'minn-admin/v1/site-logo' );
		t.check( 'route reports theme support (fixture gate)', base && base.supported === true, JSON.stringify( base ) );

		mediaId = await page.evaluate( async () => {
			const c = document.createElement( 'canvas' );
			c.width = 120; c.height = 40;
			c.getContext( '2d' ).fillRect( 0, 0, 120, 40 );
			const blob = await new Promise( ( res ) => c.toBlob( res, 'image/png' ) );
			const fd = new FormData();
			fd.append( 'file', blob, 'site-logo-suite.png' );
			const r = await fetch( window.MINN.restUrl + 'wp/v2/media', {
				method: 'POST',
				headers: { 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: fd,
			} );
			return r.ok ? ( await r.json() ).id : null;
		} );
		t.check( 'logo fixture uploaded', !! mediaId, String( mediaId ) );

		const set = await api( 'minn-admin/v1/site-logo', { method: 'POST', body: JSON.stringify( { id: mediaId } ) } );
		t.check( 'POST sets the theme_mod and echoes url', set && set.id === mediaId && /site-logo-suite/.test( set.url || '' ), JSON.stringify( set ) );

		/* ===== The Settings field renders the saved logo ===== */
		await page.goto( BASE + '/minn-admin/settings', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-logo-drop', { timeout: 20000 } );
		const shown = await page.evaluate( () => {
			const img = document.querySelector( '#minn-logo-img' );
			return { hidden: img.hidden, src: img.src, removeShown: ! document.querySelector( '#minn-logo-remove' ).hidden };
		} );
		t.check( 'field previews the saved logo with Remove offered', ! shown.hidden && /site-logo-suite/.test( shown.src ) && shown.removeShown, JSON.stringify( shown ) );

		/* ===== Remove + Save through the real UI clears the mod ===== */
		await page.click( '#minn-logo-remove' );
		await page.click( '#minn-save-settings' );
		await page.waitForFunction( () =>
			[ ...document.querySelectorAll( '.minn-toast' ) ].some( ( x ) => /saved/i.test( x.textContent ) ),
		null, { timeout: 20000 } );
		const after = await api( 'minn-admin/v1/site-logo' );
		t.check( 'Remove + Save clears the logo', after && after.id === 0 && '' === after.url, JSON.stringify( after ) );
	} finally {
		await api( 'minn-admin/v1/site-logo', { method: 'POST', body: JSON.stringify( { id: 0 } ) } ).catch( () => {} );
		if ( mediaId ) await api( `wp/v2/media/${ mediaId }?force=true`, { method: 'DELETE' } ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
