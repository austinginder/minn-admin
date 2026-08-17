/**
 * CPT creation (GH #26): custom post types from the content map are
 * creatable everywhere the built-ins are — the + New menu, the ⌘K palette
 * and the /editor/<rest_base> route — and a blank CPT document derives its
 * sidebar controls from the type's supports map instead of posting as a
 * Post. Fixture type: minn_rest_on "Field Reports" (title/editor/thumbnail
 * only), plus job_listing for the rest_base ≠ slug case.
 */
const { BASE, launch, login, loginAs, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'cpt-create' );
	await login( page );

	const del = ( restBase, id ) => page.evaluate( async ( a ) => {
		await fetch( window.MINN.restUrl + 'wp/v2/' + a.restBase + '/' + a.id + '?force=true', {
			method: 'DELETE', headers: { 'X-WP-Nonce': window.MINN.nonce },
		} ).catch( () => {} );
	}, { restBase, id } ).catch( () => {} );
	const created = [];

	try {
		/* ===== + New menu lists the CPT ===== */
		// The types cache rides the boot-status seed, which can take several
		// seconds after paint — reopen until the row lands (~30s cap).
		let row = null;
		for ( let i = 0; i < 20 && ! row; i++ ) {
			await page.evaluate( () => { const m = document.querySelector( '#minn-new-menu' ); if ( m ) m.remove(); } );
			await page.click( '#minn-new-btn' );
			await page.waitForTimeout( 500 );
			row = await page.$( '#minn-new-menu [data-newtype="minn_rest_on"]' );
			if ( ! row ) await page.waitForTimeout( 1000 );
		}
		t.check( '+ New menu has a Field Report row', !! row, '' );
		const rowLabel = row ? ( await row.textContent() ).trim() : '';
		t.check( 'row wears the singular label', /Field Report/.test( rowLabel ), rowLabel );

		/* ===== Menu click opens a blank CPT document ===== */
		await row.click();
		await page.waitForSelector( '#minn-editor-body', { timeout: 15000 } );
		await page.waitForFunction( () => ( document.querySelector( '#minn-sub' ) || {} ).textContent.includes( 'New' ), null, { timeout: 10000 } );
		t.check( 'route targets the CPT', await page.evaluate( () => location.pathname.includes( '/editor/minn_rest_on' ) || location.hash.includes( 'editor/minn_rest_on' ) ), await page.evaluate( () => location.href ) );
		const pill = await page.$eval( '#minn-sub', ( e ) => e.textContent.trim() );
		t.check( 'topbar pill names the type', /New field report/i.test( pill ), pill );

		/* ===== Sidebar gates ride the supports map ===== */
		t.check( 'featured image offered (thumbnail support)', await page.evaluate( () => !! document.querySelector( '#minn-featured-set' ) ), '' );
		t.check( 'no excerpt field (unsupported)', await page.evaluate( () => ! document.querySelector( '#minn-editor-excerpt' ) ), '' );
		t.check( 'no discussion toggles (unsupported)', await page.evaluate( () => ! document.querySelector( '#minn-comment-status' ) ), '' );

		/* ===== ⌘S creates an item of the CPT, not a Post ===== */
		const stamp = 'CPT create probe ' + Date.now();
		await page.click( '#minn-editor-title' );
		await page.keyboard.type( stamp );
		await page.click( '#minn-editor-body' );
		await page.keyboard.type( 'A field report body line.' );
		await page.keyboard.press( 'Meta+s' );
		await page.waitForFunction( () => /editor\/minn_rest_on\/\d+/.test( location.pathname + location.hash ), null, { timeout: 20000 } );
		const newId = await page.evaluate( () => parseInt( ( location.pathname + location.hash ).match( /editor\/minn_rest_on\/(\d+)/ )[ 1 ], 10 ) );
		created.push( [ 'minn_rest_on', newId ] );
		const saved = await page.evaluate( async ( pid ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/minn_rest_on/' + pid + '?context=edit&_fields=id,type,status,title,content', { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
			return await r.json();
		}, newId );
		t.check( 'saved item is the CPT', saved.type === 'minn_rest_on', String( saved.type ) );
		t.check( 'saved as a draft with the typed title', saved.status === 'draft' && saved.title.raw === stamp, JSON.stringify( { status: saved.status, title: saved.title && saved.title.raw } ) );
		t.check( 'typed body reached the store', /field report body line/.test( ( saved.content && saved.content.raw ) || '' ), '' );

		/* ===== Direct /editor/<rest_base> no longer coerces to Post ===== */
		await page.goto( BASE + '/minn-admin/editor/job-listings', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-editor-body', { timeout: 15000 } );
		await page.waitForFunction( () => /New/.test( ( document.querySelector( '#minn-sub' ) || {} ).textContent || '' ), null, { timeout: 10000 } );
		const jobPill = await page.$eval( '#minn-sub', ( e ) => e.textContent.trim() );
		t.check( 'direct route resolves the type (New job, not New post)', /New job/i.test( jobPill ), jobPill );

		/* ===== ⌘K palette entry ===== */
		await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-new-btn', { timeout: 15000 } );
		// Fresh page — the types cache rides the async boot seed again; the
		// palette builds its command list from that cache, so wait for it
		// (the + New menu is the observable proxy).
		for ( let i = 0; i < 20; i++ ) {
			await page.evaluate( () => { const m = document.querySelector( '#minn-new-menu' ); if ( m ) m.remove(); } );
			await page.click( '#minn-new-btn' );
			await page.waitForTimeout( 500 );
			const ready = await page.$( '#minn-new-menu [data-newtype="minn_rest_on"]' );
			if ( ready ) break;
			await page.waitForTimeout( 1000 );
		}
		await page.evaluate( () => { const m = document.querySelector( '#minn-new-menu' ); if ( m ) m.remove(); } );
		await page.keyboard.press( 'Meta+k' );
		await page.waitForSelector( '#minn-palette-input', { timeout: 5000 } );
		await page.type( '#minn-palette-input', 'create new field' );
		await page.waitForTimeout( 400 );
		const cmd = await page.evaluate( () =>
			Array.from( document.querySelectorAll( '.minn-palette-item .minn-palette-label' ) )
				.map( ( e ) => e.textContent ).find( ( x ) => /Create new Field Report/.test( x ) ) || ''
		);
		t.check( 'palette offers Create new Field Report', !! cmd, cmd );
		await page.keyboard.press( 'Escape' );

		/* ===== Author: menu opens without the Page row, CPTs intact ===== */
		const { ctx, page: author } = await loginAs( browser, 'minn-author', 'minn-author-pass-1' );
		let aRows = { types: [], pages: true };
		for ( let i = 0; i < 20; i++ ) {
			await author.evaluate( () => { const m = document.querySelector( '#minn-new-menu' ); if ( m ) m.remove(); } );
			await author.click( '#minn-new-btn' );
			await author.waitForTimeout( 500 );
			aRows = await author.evaluate( () => ( {
				types: Array.from( document.querySelectorAll( '#minn-new-menu [data-newtype]' ) ).map( ( b ) => b.dataset.newtype ),
				pages: !! document.querySelector( '#minn-new-menu [data-newtype="pages"]' ),
			} ) );
			if ( aRows.types.includes( 'minn_rest_on' ) ) break;
			await author.waitForTimeout( 1000 );
		}
		t.check( 'Author gets the menu with CPT rows', aRows.types.includes( 'minn_rest_on' ), JSON.stringify( aRows.types ) );
		t.check( 'Author menu hides the Page row', ! aRows.pages, JSON.stringify( aRows.types ) );
		await ctx.close();
	} finally {
		for ( const [ rb, id ] of created ) await del( rb, id );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
