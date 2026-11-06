/**
 * A type whose records can't be added or deleted (create_posts do_not_allow,
 * delete_post mapped away): Minn offers no way to do either, rather than a
 * button that answers 403. Boot names the type so the New menu, the palette
 * and the row menu leave it out, and the editor's trash link is gone from a
 * record that can't be deleted, while an ordinary post keeps it. Along the
 * way, an ACF field's instructions show under it in the panel, as plain text.
 * The mu-fixture option minn_test_fixed_type arms the minn_fixed type.
 */
const { BASE, launch, login, reporter, onExit } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'editor-fixed-type' );
	await login( page );
	await page.goto( `${ BASE }/minn-admin/overview`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-scroll', { timeout: 15000 } );

	const rest = ( path, method = 'GET', body = null ) => page.evaluate( async ( [ p, m, b ] ) => {
		const r = await fetch( window.MINN.restUrl + p, {
			method: m,
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			body: b ? JSON.stringify( b ) : undefined,
		} );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, [ path, method, body ] );

	const setOpt = async ( v ) => {
		for ( let attempt = 1; attempt <= 5; attempt++ ) {
			await rest( 'wp/v2/settings', 'POST', { minn_test_fixed_type: v } );
			const r = await rest( 'wp/v2/settings?_cb=' + Math.random() );
			if ( r.body && r.body.minn_test_fixed_type === v ) return true;
			await page.waitForTimeout( 800 );
		}
		return false;
	};

	const openEd = async ( path ) => {
		await page.goto( `${ BASE }/minn-admin/${ path }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-publish-btn', { timeout: 30000 } );
		await page.waitForTimeout( 1200 );
	};

	let ordinary = 0;
	const fixedId = { id: 0 };
	const cleanup = async () => {
		if ( ordinary ) await rest( `wp/v2/posts/${ ordinary }?force=true`, 'DELETE' ).catch( () => {} );
		await setOpt( '' ).catch( () => {} );
	};
	onExit( () => {} );

	try {
		t.check( 'fixture armed', await setOpt( '1' ), '' );

		// Nothing can add or delete a record of this type over REST: the fixture
		// makes the one it needs when armed, and it stays between runs.
		await page.goto( `${ BASE }/minn-admin/overview`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-scroll', { timeout: 15000 } );
		const found = await rest( 'wp/v2/minn-fixed?context=edit&per_page=1' );
		fixedId.id = found.body && found.body[ 0 ] ? found.body[ 0 ].id : 0;
		t.check( 'a fixed record exists', !! fixedId.id, '' );

		const made = await rest( 'wp/v2/posts', 'POST', { title: 'Fixed-type control', status: 'draft', content: '<p>x</p>' } );
		ordinary = made.body && made.body.id;

		// The menu, the palette and the row menu all ask this list. Whether a type
		// has a row at all depends on the lab's other plugins, so the list is what
		// the suite can hold steady.
		await page.goto( `${ BASE }/minn-admin/overview`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-scroll', { timeout: 15000 } );
		const noCreate = await page.evaluate( () => window.MINN.noCreate || {} );
		t.check( 'boot names the type nobody can add', true === noCreate[ 'minn-fixed' ], JSON.stringify( noCreate ) );
		t.check( 'boot leaves ordinary types out', ! noCreate.posts && ! noCreate.pages, JSON.stringify( noCreate ) );

		await openEd( `editor/posts/${ ordinary }` );
		t.check( 'ordinary post keeps its trash link', await page.locator( '#minn-trash-post' ).count() === 1, '' );

		if ( fixedId.id ) {
			await openEd( `editor/minn-fixed/${ fixedId.id }` );
			t.check( 'a record that can\'t be deleted has no trash link', await page.locator( '#minn-trash-post' ).count() === 0, '' );

			const door = page.getByText( /Custom fields/ ).last();
			await door.click();
			await page.waitForSelector( '#minn-modal-overlay', { timeout: 8000 } );
			await page.waitForTimeout( 600 );
			const panel = await page.$eval( '#minn-modal-overlay', ( m ) => m.innerText );
			t.check( 'field instructions show in the panel', panel.includes( 'Written for the writer, under the field.' ), panel.slice( 0, 200 ) );
			t.check( 'as plain text', ! panel.includes( '<em>' ), '' );
			await page.locator( '#minn-modal-overlay .minn-x-btn' ).first().click();
		}

		if ( fixedId.id ) {
			const row = await rest( `wp/v2/minn-fixed/${ fixedId.id }?context=edit&_fields=id,minn_can_delete` );
			t.check( 'the record says it can\'t be deleted', false === row.body.minn_can_delete, JSON.stringify( row.body ) );
			const refused = await rest( `wp/v2/minn-fixed/${ fixedId.id }`, 'DELETE' );
			t.check( 'and the server agrees', 403 === refused.status, String( refused.status ) );
		}
		const own = await rest( `wp/v2/posts/${ ordinary }?context=edit&_fields=id,minn_can_delete` );
		t.check( 'an ordinary post says it can', true === own.body.minn_can_delete, JSON.stringify( own.body ) );
		const anon = await page.evaluate( async ( id ) => {
			const r = await fetch( `/wp-json/wp/v2/posts/${ id }`, { credentials: 'omit' } );
			return r.status;
		}, ordinary );
		t.check( 'draft stays private to logged-out readers', 401 === anon || 403 === anon || 404 === anon, String( anon ) );
	} finally {
		await cleanup();
		await t.done( browser, errors );
	}
} )();
