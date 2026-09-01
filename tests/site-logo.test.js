/**
 * Site logo (Settings → Site): the theme's custom_logo theme_mod behind
 * minn-admin/v1/site-logo. Supported when the theme declares custom-logo
 * support (the dev fixture opens that gate for the classic marketing
 * theme) OR when the theme is a block theme, which manages a logo through
 * the Site Logo block without ever declaring support. Sets via REST,
 * asserts the field renders the saved logo, drives Remove + Save through
 * the real UI, then proves the block-theme gate on twentytwentyfive —
 * including that the theme-mod write syncs to the site_logo OPTION the
 * block actually reads.
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
	let prevTheme = '';
	try {
		const base = await api( 'minn-admin/v1/site-logo' );
		// Supported either way here: the fixture opens the classic gate on the
		// dev site, and a block theme (the bare next-core site) qualifies on its own.
		t.check( 'route reports logo support for the active theme', base && base.supported === true, JSON.stringify( base ) );

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

		/* ===== Block themes: supported WITHOUT declaring custom-logo =====
		 * Stock block themes (Twenty Twenty-Five included) never call
		 * add_theme_support('custom-logo') yet manage a logo through the Site
		 * Logo block, and core syncs the theme mod with the site_logo option
		 * unconditionally. The fixture only opens the classic gate now, so
		 * this exercises Minn's own block-theme gate the way a real site
		 * would. */
		prevTheme = ( await api( 'wp/v2/themes?status=active&_fields=stylesheet' ) )[ 0 ].stylesheet;
		await api( 'minn-admin/v1/themes/activate', { method: 'POST', body: JSON.stringify( { stylesheet: 'twentytwentyfive' } ) } );
		const blockGate = await api( 'minn-admin/v1/site-logo' );
		t.check( 'a block theme reports logo support without declaring it',
			blockGate && blockGate.supported === true, JSON.stringify( blockGate ) );
		const blockSet = await api( 'minn-admin/v1/site-logo', { method: 'POST', body: JSON.stringify( { id: mediaId } ) } );
		t.check( 'setting the logo works on a block theme', blockSet && blockSet.id === mediaId, JSON.stringify( blockSet ) );
		// The Site Logo block reads the site_logo OPTION — the sync is the
		// whole reason the theme-mod write is safe on a block theme.
		const optionSynced = await api( 'wp/v2/settings?_fields=site_logo' );
		t.check( 'the write synced to the site_logo option the block reads',
			optionSynced && optionSynced.site_logo === mediaId, JSON.stringify( optionSynced ) );
		const blockClear = await api( 'minn-admin/v1/site-logo', { method: 'POST', body: JSON.stringify( { id: 0 } ) } );
		t.check( 'clearing works on a block theme too', blockClear && blockClear.id === 0 );
	} finally {
		await api( 'minn-admin/v1/site-logo', { method: 'POST', body: JSON.stringify( { id: 0 } ) } ).catch( () => {} );
		if ( prevTheme ) await api( 'minn-admin/v1/themes/activate', { method: 'POST', body: JSON.stringify( { stylesheet: prevTheme } ) } ).catch( () => {} );
		if ( mediaId ) await api( `wp/v2/media/${ mediaId }?force=true`, { method: 'DELETE' } ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
