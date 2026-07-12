/**
 * Plugin slash-menu commands (minn_admin_editor_commands filter).
 *
 * Proves the editor-command seam is open: the minn-dev-fixtures mu-plugin
 * registers four commands through the same public filter a third-party
 * plugin would use (no Minn patch), gated on the REST-exposed
 * minn_test_editor_commands option. Covers: boot payload lists them,
 * slash search by keyword surfaces them with the ns badge, html + template
 * inserts land and survive save, async route returns both shapes, the
 * block picker groups them under "Minn-test · commands", and disabling
 * the option drops them from the editor-blocks re-poll.
 */
const { launch, login, createPost, deletePost, openEditor, freshParagraph, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'editor-commands' );
	const { browser, page, errors } = await launch();
	await login( page );

	// Write-then-verify with retries (REST settings write can race boot
	// requests — site-kit / design-sources suite rule).
	const setOpt = async ( v ) => {
		for ( let attempt = 1; attempt <= 5; attempt++ ) {
			const stored = await page.evaluate( async ( val ) => {
				const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST', headers: h, credentials: 'same-origin',
					body: JSON.stringify( { minn_test_editor_commands: val } ),
				} );
				const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), {
					headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} );
				return ( await r.json() ).minn_test_editor_commands;
			}, v );
			if ( stored === v ) return true;
			await page.waitForTimeout( 800 );
		}
		return false;
	};

	if ( ! await setOpt( true ) ) {
		console.log( 'FAIL  could not enable the fixture editor-commands option' );
		await browser.close();
		process.exit( 1 );
	}

	const id = await createPost( page, {
		title: 'Editor commands seam test',
		content: '<!-- wp:paragraph -->\n<p>Seam test.</p>\n<!-- /wp:paragraph -->',
	} );

	const rawContent = () => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		return ( await r.json() ).content.raw;
	}, id );

	// Type a slash query and poll for a matching menu row (async lists
	// may still be settling; zero-match closes the menu until next keyup).
	const slashFind = async ( query, needle ) => {
		await freshParagraph( page );
		await page.keyboard.type( query, { delay: 30 } );
		let found = false;
		for ( let i = 0; i < 16 && ! found; i++ ) {
			await page.waitForTimeout( 350 );
			found = await page.$$eval( '.minn-slash-item', ( els, n ) =>
				els.some( ( e ) => e.textContent.includes( n ) )
			, needle ).catch( () => false );
			if ( ! found ) {
				await page.keyboard.press( 'Backspace' );
				await page.keyboard.type( query.slice( -1 ), { delay: 30 } );
			}
		}
		return found;
	};

	// Click the exact row (Enter can hit a different startsWith match, e.g.
	// Anchor Blocks' "Callout" ranks above "Fixture Callout Island").
	const slashPick = async ( needle ) => {
		const ok = await page.$$eval( '.minn-slash-item', ( els, n ) => {
			const el = els.find( ( e ) => e.textContent.includes( n ) );
			if ( ! el ) return false;
			el.dispatchEvent( new MouseEvent( 'mousedown', { bubbles: true, cancelable: true } ) );
			return true;
		}, needle );
		return ok;
	};

	try {
		// Force a re-poll so B.editorCommands is live without a hard reload
		// (boot snapshot was taken before setOpt).
		await page.goto( page.url().split( '#' )[ 0 ].replace( /\/$/, '' ) + '/minn-admin/content', { waitUntil: 'domcontentloaded' } );
		await page.waitForTimeout( 800 );
		await openEditor( page, id );

		// If the boot payload is still empty, poke editor-blocks and re-render
		// by leaving/re-entering (refreshEditorBlocks runs on plugin change;
		// here we re-open after a full navigate so boot includes the option).
		const bootHas = await page.evaluate( () =>
			( window.MINN.editorCommands || [] ).some( ( c ) => c.id === 'minn-fixture-cta' ) );
		if ( ! bootHas ) {
			// Hard reload the app so the boot payload re-reads the filter.
			await page.goto( page.url().split( '#' )[ 0 ].replace( /\/minn-admin.*/, '' ) + '/minn-admin/', {
				waitUntil: 'domcontentloaded',
			} );
			await page.waitForTimeout( 1000 );
			await openEditor( page, id );
		}

		t.check( 'boot payload lists the fixture html command', await page.evaluate( () => {
			const c = ( window.MINN.editorCommands || [] ).find( ( x ) => x.id === 'minn-fixture-cta' );
			return !! ( c && c.html && c.searchOnly && c.ns === 'minn-test'
				&& Array.isArray( c.keywords ) && c.keywords.includes( 'fixturecmd' ) );
		} ) );
		t.check( 'boot payload lists the template command', await page.evaluate( () =>
			( window.MINN.editorCommands || [] ).some( ( c ) =>
				c.id === 'minn-fixture-callout' && !! c.template && c.block === 'core/group' ) ) );
		t.check( 'boot payload lists the async route command', await page.evaluate( () =>
			( window.MINN.editorCommands || [] ).some( ( c ) =>
				c.id === 'minn-fixture-async-html' && c.route && c.method === 'POST' ) ) );

		// Keyword match surfaces the search-only CTA (label may not include the query).
		const ctaFound = await slashFind( '/fixturecmd', 'Fixture CTA Boilerplate' );
		t.check( 'slash keyword surfaces the html command with ns badge', ctaFound
			&& await page.$$eval( '.minn-slash-item', ( els ) =>
				els.some( ( e ) => e.textContent.includes( 'Fixture CTA Boilerplate' )
					&& e.textContent.includes( 'minn-test' ) ) ) );

		t.check( 'picked html command row', await slashPick( 'Fixture CTA Boilerplate' ) );
		await page.waitForTimeout( 600 );
		t.check( 'html command inserted prose into the body', await page.evaluate( () =>
			!! document.querySelector( '#minn-editor-body .minn-fixture-cta' ) ) );

		// Template island insert (unique query so Anchor's "Callout" isn't the only hit).
		const calloutFound = await slashFind( '/fixture callout', 'Fixture Callout Island' );
		t.check( 'slash surfaces the template command', calloutFound );
		t.check( 'picked template command row', await slashPick( 'Fixture Callout Island' ) );
		await page.waitForSelector( '.minn-block-island[data-block="core/group"]', { timeout: 10000 } );
		const calloutIsland = await page.waitForFunction( () => {
			const body = document.getElementById( 'minn-editor-body' );
			if ( ! body ) return false;
			return body.innerHTML.includes( 'minn-fixture-callout' )
				|| [ ...body.querySelectorAll( '.minn-block-island' ) ].some( ( el ) =>
					( el.textContent || '' ).includes( 'Fixture callout island body' ) );
		}, null, { timeout: 10000 } ).then( () => true ).catch( () => false );
		t.check( 'template command inserted as an island', calloutIsland );

		// Async html route.
		const asyncHtml = await slashFind( '/fixture async html', 'Fixture Async HTML' );
		t.check( 'slash surfaces the async html command', asyncHtml );
		t.check( 'picked async html command row', await slashPick( 'Fixture Async HTML' ) );
		await page.waitForSelector( '#minn-editor-body .minn-fixture-cmd-html', { timeout: 15000 } );
		t.check( 'async route html landed in the body', true );

		// Async island route.
		const asyncIsland = await slashFind( '/fixture async island', 'Fixture Async Island' );
		t.check( 'slash surfaces the async island command', asyncIsland );
		t.check( 'picked async island command row', await slashPick( 'Fixture Async Island' ) );
		let islandLanded = false;
		for ( let i = 0; i < 20 && ! islandLanded; i++ ) {
			await page.waitForTimeout( 400 );
			islandLanded = await page.evaluate( () => {
				const body = document.getElementById( 'minn-editor-body' );
				if ( ! body ) return false;
				return body.innerHTML.includes( 'minn-fixture-cmd-island' )
					|| [ ...body.querySelectorAll( '.minn-block-island' ) ].some( ( el ) =>
						( el.textContent || '' ).includes( 'Fixture async island body' ) );
			} );
		}
		t.check( 'async route template landed as an island', islandLanded );

		// Block picker groups commands by ns.
		await page.click( '#minn-editor-title' );
		await page.keyboard.press( 'Meta+/' );
		let grouped = false;
		for ( let i = 0; i < 16 && ! grouped; i++ ) {
			await page.waitForTimeout( 400 );
			grouped = await page.$$eval( '.minn-bp-group h3', ( els ) =>
				els.some( ( e ) => /minn-test|Minn-test|Minn Test/i.test( e.textContent )
					&& /command/i.test( e.textContent ) )
			).catch( () => false );
		}
		// prettyNs('minn-test') → 'Minn-test' (charAt uppercases first only).
		if ( ! grouped ) {
			grouped = await page.$$eval( '.minn-bp-group h3', ( els ) =>
				els.some( ( e ) => e.textContent.includes( 'commands' ) )
			).catch( () => false );
		}
		t.check( 'block picker groups plugin commands', grouped );
		// Search in picker by keyword.
		if ( grouped ) {
			await page.fill( '#minn-bp-search', 'boilerplate' );
			await page.waitForTimeout( 200 );
			t.check( 'block picker keyword finds the CTA command', await page.$$eval( '.minn-bp-item', ( els ) =>
				els.some( ( e ) => e.textContent.includes( 'Fixture CTA Boilerplate' ) ) ) );
		} else {
			t.check( 'block picker keyword finds the CTA command', false );
		}
		await page.keyboard.press( 'Escape' );
		await page.waitForTimeout( 300 );

		await page.keyboard.press( 'Meta+s' );
		await page.waitForTimeout( 2500 );
		const raw = await rawContent();
		t.check( 'saved markup keeps the html command prose',
			raw.includes( 'minn-fixture-cta' ) && raw.includes( 'Ready to start?' ), raw.slice( 0, 200 ) );
		t.check( 'saved markup keeps a template island',
			raw.includes( 'minn-fixture-callout' ) || raw.includes( 'Fixture callout island body' ),
			raw.slice( 0, 200 ) );
		t.check( 'saved markup keeps the async html command',
			raw.includes( 'minn-fixture-cmd-html' ) || raw.includes( 'Fixture async command prose' ),
			raw.slice( 0, 200 ) );

		// Disabling the option drops commands from the re-poll payload.
		await setOpt( false );
		const gone = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/editor-blocks', {
				headers: { 'X-WP-Nonce': window.MINN.nonce },
			} );
			const j = await r.json();
			return ! ( j.editorCommands || [] ).some( ( c ) => String( c.id || '' ).startsWith( 'minn-fixture-' ) );
		} );
		t.check( 'disabled commands absent from the editor-blocks re-poll', gone );
	} finally {
		await setOpt( false );
		await deletePost( page, id );
	}

	await t.done( browser, errors );
} )();
