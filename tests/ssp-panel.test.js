/**
 * Seriously Simple Podcasting editor panel — the "Podcast episode" door on
 * podcast-type posts (adapters/seriously-simple-podcasting.php). Schema is
 * read live from SSP's own CPT_Podcast_Handler::custom_fields(); writes
 * mirror SSP's metabox storage ('on'/'' checkboxes, plain postmeta). Server
 * truth is read back through SSP's OWN registered meta on the REST object.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'ssp-panel' );
	const { browser, page, errors } = await launch();
	await login( page );
	await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN, null, { timeout: 20000 } );

	// Disposable episode through core REST (the podcast CPT is show_in_rest).
	const postId = await page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/podcast', {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: JSON.stringify( { title: 'Minn SSP Suite Episode', status: 'draft' } ),
		} );
		return ( await r.json() ).id;
	} );
	t.check( 'episode created over core REST', !! postId, String( postId ) );

	const readEpisode = () => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + `wp/v2/podcast/${ pid }?context=edit&_fields=minn_ssp,meta&_cb=` + Math.random(), {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return r.json();
	}, postId );

	try {
		await page.goto( BASE + '/minn-admin/editor/podcast/' + postId, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-side-door="panel:ssp"]', { timeout: 20000 } );
		const door = await page.$eval( '[data-side-door="panel:ssp"]', ( el ) => el.textContent );
		t.check( 'episode door renders with the SSP badge', /Podcast episode/.test( door ) && /Seriously Simple Podcasting/.test( door ), door.trim().replace( /\s+/g, ' ' ) );

		await page.click( '[data-side-door="panel:ssp"]' );
		await page.waitForSelector( '.minn-editor-side-modal [data-pf="ssp:audio_file"]', { timeout: 10000 } );
		t.check( 'schema fields render from SSP\'s own custom_fields()', !! ( await page.$( '[data-pf="ssp:duration"]' ) ) && !! ( await page.$( '[data-pf="ssp:itunes_episode_number"]' ) ) );
		t.check( 'cover image counts as locked with the wp-admin escape', await page.evaluate( () =>
			!! Array.from( document.querySelectorAll( '.minn-editor-side-modal .minn-panel-locked' ) ).length ) );

		await page.fill( '[data-pf="ssp:audio_file"]', 'https://example.com/minn-suite-ep.mp3' );
		await page.fill( '[data-pf="ssp:duration"]', '18:45' );
		await page.fill( '[data-pf="ssp:itunes_episode_number"]', '7' );
		// Explicit is a true_false switch; episode_type renders as a native
		// select (the panel dialect aliases radio → select, rule 70).
		await page.evaluate( () => {
			const explicit = document.querySelector( '[data-pf="ssp:explicit"]' );
			if ( explicit && explicit.matches( 'button, .minn-switch' ) ) explicit.click();
			else if ( explicit ) explicit.checked = true, explicit.dispatchEvent( new Event( 'change', { bubbles: true } ) );
		} );
		await page.selectOption( '[data-pf="ssp:episode_type"]', 'video' );
		await page.keyboard.press( 'Meta+s' );

		let ep = null;
		for ( let i = 0; i < 20; i++ ) {
			ep = await readEpisode();
			if ( ep && ep.minn_ssp && ep.minn_ssp.audio_file === 'https://example.com/minn-suite-ep.mp3' && ep.minn_ssp.explicit === true ) break;
			await page.waitForTimeout( 500 );
		}
		t.check( 'panel save round-trips through the dedicated field',
			!! ( ep && ep.minn_ssp ) && ep.minn_ssp.audio_file === 'https://example.com/minn-suite-ep.mp3'
				&& ep.minn_ssp.duration === '18:45' && ep.minn_ssp.itunes_episode_number === '7'
				&& ep.minn_ssp.explicit === true && ep.minn_ssp.episode_type === 'video',
			JSON.stringify( ep && ep.minn_ssp ) );
		// Server truth via SSP's OWN registered meta (show_in_rest), including
		// its 'on' checkbox convention.
		t.check( 'SSP\'s registered meta carries the stored values',
			!! ( ep && ep.meta ) && ep.meta.audio_file === 'https://example.com/minn-suite-ep.mp3' && ep.meta.explicit === 'on',
			JSON.stringify( ep && { audio: ep.meta && ep.meta.audio_file, explicit: ep.meta && ep.meta.explicit } ) );
	} finally {
		await page.evaluate( async ( pid ) => {
			await fetch( window.MINN.restUrl + 'wp/v2/podcast/' + pid + '?force=true', {
				method: 'DELETE', headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} ).catch( () => {} );
		}, postId ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
