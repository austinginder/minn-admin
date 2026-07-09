/**
 * Code Snippets surface: list from code-snippets/v1, status pills from the
 * boolean `active` field, detail with code body, activate/deactivate via PUT,
 * and an Edit deep link into the Code Snippets admin.
 *
 * SKIPs exit-0 when Code Snippets is not active (other suites share the site).
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'code-snippets' );
	await login( page );

	const available = await page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'code-snippets/v1/snippets?per_page=1', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		return r.ok;
	} );
	if ( ! available ) {
		t.check( 'Code Snippets active (skip suite)', false, 'plugin not active — install on the test site' );
		await t.done( browser, errors );
		return;
	}

	// Boot payload should expose the surface when the plugin is present.
	const surface = await page.evaluate( () => {
		const surfaces = window.MINN.surfaces || [];
		const s = surfaces.find( ( x ) => x.id === 'code-snippets' );
		return {
			found: !! s,
			ids: surfaces.map( ( x ) => x.id ),
			label: s ? s.label : '',
			route: s && s.collection ? s.collection.route : '',
		};
	} );
	t.check( 'code-snippets surface in boot payload', surface.found, surface.ids.join( ',' ) );
	if ( ! surface.found ) {
		await t.done( browser, errors );
		return;
	}
	t.check( 'surface label is Snippets', surface.label === 'Snippets', surface.label );
	t.check( 'collection points at Code Snippets REST', /code-snippets\/v1\/snippets/.test( surface.route ), surface.route );

	// Seed a pair of snippets: one active, one inactive.
	const seed = await page.evaluate( async () => {
		const mk = async ( name, active ) => {
			const r = await fetch( window.MINN.restUrl + 'code-snippets/v1/snippets', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				body: JSON.stringify( {
					name,
					code: '// minn test ' + name + '\n',
					desc: 'seeded by code-snippets.test.js',
					tags: [ 'minn-test' ],
					scope: 'global',
					active,
					priority: 10,
				} ),
			} );
			const j = await r.json();
			if ( ! r.ok ) throw new Error( j.message || 'create failed' );
			return j.id;
		};
		const off = await mk( 'Minn suite inactive', false );
		const on = await mk( 'Minn suite active', true );
		return { off, on };
	} );

	const cleanup = async () => {
		await page.evaluate( async ( ids ) => {
			for ( const id of ids ) {
				await fetch( window.MINN.restUrl + 'code-snippets/v1/snippets/' + id, {
					method: 'DELETE',
					headers: { 'X-WP-Nonce': window.MINN.nonce },
				} ).catch( () => {} );
			}
		}, [ seed.off, seed.on ] ).catch( () => {} );
	};

	try {
		await page.goto( `${ BASE }/minn-admin/code-snippets`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-table-row', { timeout: 15000 } );

		const list = await page.evaluate( () => {
			const rows = [ ...document.querySelectorAll( '.minn-table-row' ) ];
			return rows.map( ( r ) => ( {
				title: ( r.querySelector( '.minn-row-title' ) || {} ).textContent || '',
				pills: [ ...r.querySelectorAll( '.minn-status' ) ].map( ( p ) => p.textContent.trim() ),
				meta: [ ...r.querySelectorAll( '.minn-row-meta' ) ].map( ( m ) => m.textContent.trim() ),
			} ) );
		} );
		t.check( 'list shows seeded snippets', list.some( ( r ) => /Minn suite active/.test( r.title ) ) && list.some( ( r ) => /Minn suite inactive/.test( r.title ) ), JSON.stringify( list.map( ( r ) => r.title ) ) );
		const activeRow = list.find( ( r ) => /Minn suite active/.test( r.title ) );
		const inactiveRow = list.find( ( r ) => /Minn suite inactive/.test( r.title ) );
		t.check( 'active snippet has active pill', activeRow && activeRow.pills.includes( 'active' ), JSON.stringify( activeRow ) );
		t.check( 'inactive snippet has inactive pill', inactiveRow && inactiveRow.pills.includes( 'inactive' ), JSON.stringify( inactiveRow ) );

		// Open the inactive one → Activate action → verify via REST.
		const inactiveIdx = list.findIndex( ( r ) => /Minn suite inactive/.test( r.title ) );
		await page.click( `.minn-table-row[data-sitem="${ inactiveIdx }"]` );
		await page.waitForSelector( '#minn-modal-overlay', { timeout: 10000 } );
		await page.waitForFunction( () => {
			const m = document.querySelector( '#minn-modal-overlay' );
			return m && ! m.querySelector( '.minn-loading' );
		}, null, { timeout: 10000 } );

		const modal = await page.evaluate( () => ( {
			title: ( document.querySelector( '#minn-modal-overlay .minn-modal-title' ) || {} ).textContent || '',
			pill: ( document.querySelector( '#minn-modal-overlay .minn-status' ) || {} ).textContent || '',
			code: ( document.querySelector( '#minn-modal-overlay .minn-surface-message' ) || {} ).textContent || '',
			actions: [ ...document.querySelectorAll( '#minn-modal-overlay .minn-modal-actions button, #minn-modal-overlay .minn-modal-actions a' ) ]
				.map( ( e ) => e.textContent.trim() ),
			editHref: ( document.querySelector( '#minn-modal-overlay a[href*="edit-snippet"]' ) || {} ).href || '',
		} ) );
		t.check( 'detail title includes snippet id', /#\d+/.test( modal.title ), modal.title );
		t.check( 'detail shows inactive pill', /inactive/i.test( modal.pill ), modal.pill );
		t.check( 'detail shows code body', /minn test Minn suite inactive/.test( modal.code ), modal.code.slice( 0, 80 ) );
		t.check( 'detail offers Activate (not Deactivate)', modal.actions.includes( 'Activate' ) && ! modal.actions.includes( 'Deactivate' ), JSON.stringify( modal.actions ) );
		t.check( 'Edit deep link targets Code Snippets admin', /page=edit-snippet&id=\d+/.test( modal.editHref ), modal.editHref );

		await page.evaluate( () => {
			const btn = [ ...document.querySelectorAll( '#minn-modal-overlay [data-saction]' ) ]
				.find( ( b ) => /Activate/.test( b.textContent ) );
			if ( btn ) btn.click();
		} );
		await page.waitForFunction( () => ! document.querySelector( '#minn-modal-overlay' ), { timeout: 10000 } );

		const after = await page.evaluate( async ( id ) => {
			const r = await fetch( window.MINN.restUrl + 'code-snippets/v1/snippets/' + id, {
				headers: { 'X-WP-Nonce': window.MINN.nonce },
			} );
			const j = await r.json();
			return { ok: r.ok, active: j.active };
		}, seed.off );
		t.check( 'Activate persists via Code Snippets REST', after.ok && after.active === true, JSON.stringify( after ) );

		// List should refresh with the new status.
		await page.waitForFunction( () => {
			const rows = [ ...document.querySelectorAll( '.minn-table-row' ) ];
			const r = rows.find( ( row ) => /Minn suite inactive/.test( ( row.querySelector( '.minn-row-title' ) || {} ).textContent || '' ) );
			if ( ! r ) return false;
			return [ ...r.querySelectorAll( '.minn-status' ) ].some( ( p ) => p.textContent.trim() === 'active' );
		}, null, { timeout: 10000 } ).catch( () => {} );
		const refreshed = await page.evaluate( () => {
			const rows = [ ...document.querySelectorAll( '.minn-table-row' ) ];
			const r = rows.find( ( row ) => /Minn suite inactive/.test( ( row.querySelector( '.minn-row-title' ) || {} ).textContent || '' ) );
			return r ? [ ...r.querySelectorAll( '.minn-status' ) ].map( ( p ) => p.textContent.trim() ) : [];
		} );
		t.check( 'list row updates to active after toggle', refreshed.includes( 'active' ), JSON.stringify( refreshed ) );
	} finally {
		await cleanup();
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
