/**
 * Code Snippets surface: list from code-snippets/v1, status pills from the
 * boolean `active` field, detail with code body, activate/deactivate via PUT,
 * and an Edit deep link into the Code Snippets admin.
 *
 * SKIPs exit-0 when Code Snippets is not active (other suites share the site).
 *
 * Note: Code Snippets' REST DELETE only *trashes* (active = -1); trashed rows
 * still appear in the list and fill the first page. Suites permanently purge
 * leftover Minn fixtures via WP-CLI before seeding.
 */
const { execSync } = require( 'child_process' );
const { BASE, launch, login, reporter } = require( './helpers' );

const WP = process.env.MINN_TEST_WP
	|| '/Users/austin/Cove/Sites/minnadmin.localhost/public';

function purgeMinnSnippets() {
	const php = [
		'if ( ! function_exists( "Code_Snippets\\\\delete_snippet" ) ) { return; }',
		'global $wpdb;',
		'$t = $wpdb->prefix . "snippets";',
		'$ids = $wpdb->get_col( "SELECT id FROM `$t` WHERE name LIKE \'Minn suite%\' OR name LIKE \'probe%\' OR tags LIKE \'%minn-test%\'" );',
		'foreach ( (array) $ids as $id ) { \\Code_Snippets\\delete_snippet( (int) $id ); }',
	].join( ' ' );
	try {
		execSync( `wp --path=${ WP } eval ${ JSON.stringify( php ) }`, {
			stdio: 'ignore',
			timeout: 60000,
		} );
	} catch ( e ) { /* best-effort */ }
}

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'code-snippets' );
	await login( page );
	purgeMinnSnippets();

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

	// Unique names so leftover fixtures from a prior run can't steal the match.
	const uid = Date.now().toString( 36 );
	const nameOff = `Minn suite off ${ uid }`;
	const nameOn = `Minn suite on ${ uid }`;
	const nameCreated = `Minn suite new ${ uid }`;

	// Seed a pair of snippets: one active, one inactive.
	const seed = await page.evaluate( async ( args ) => {
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
			return { id: j.id, active: j.active, name: j.name };
		};
		const off = await mk( args.nameOff, false );
		const on = await mk( args.nameOn, true );
		return { off, on };
	}, { nameOff, nameOn } );

	const cleanup = async () => {
		// Permanent purge (REST DELETE only trashes).
		purgeMinnSnippets();
	};

	try {
		t.check( 'seed inactive is inactive', seed.off.active === false, JSON.stringify( seed.off ) );
		t.check( 'seed active is active', seed.on.active === true, JSON.stringify( seed.on ) );

		await page.goto( `${ BASE }/minn-admin/code-snippets`, { waitUntil: 'domcontentloaded' } );
		// Wait for OUR seeds (not just any row — page-1 used to be full of trash).
		await page.waitForFunction( ( names ) => {
			const text = document.querySelector( '.minn-table' )?.textContent || '';
			return names.every( ( n ) => text.includes( n ) );
		}, [ nameOn, nameOff ], { timeout: 20000 } );

		const list = await page.evaluate( () => {
			const rows = [ ...document.querySelectorAll( '.minn-table-row' ) ];
			return rows.map( ( r ) => ( {
				id: r.dataset.sitem || '',
				title: ( r.querySelector( '.minn-row-title' ) || {} ).textContent || '',
				pills: [ ...r.querySelectorAll( '.minn-status' ) ].map( ( p ) => p.textContent.trim() ),
			} ) );
		} );
		t.check( 'list shows seeded snippets', list.some( ( r ) => r.title.includes( nameOn ) ) && list.some( ( r ) => r.title.includes( nameOff ) ), JSON.stringify( list.map( ( r ) => r.title ) ) );
		const activeRow = list.find( ( r ) => r.title.includes( nameOn ) );
		const inactiveRow = list.find( ( r ) => r.title.includes( nameOff ) );
		t.check( 'active snippet has active pill', activeRow && activeRow.pills.includes( 'active' ), JSON.stringify( activeRow ) );
		t.check( 'inactive snippet has inactive pill', inactiveRow && inactiveRow.pills.includes( 'inactive' ), JSON.stringify( inactiveRow ) );

		// Open the inactive one by title (data-sitem is the row index, not the id).
		await page.evaluate( ( name ) => {
			const row = [ ...document.querySelectorAll( '.minn-table-row' ) ].find( ( r ) =>
				( ( r.querySelector( '.minn-row-title' ) || {} ).textContent || '' ).includes( name ) );
			if ( row ) row.click();
		}, nameOff );
		await page.waitForSelector( '#minn-modal-overlay', { timeout: 10000 } );
		await page.waitForFunction( () => {
			const m = document.querySelector( '#minn-modal-overlay' );
			return m && ! m.querySelector( '.minn-loading' );
		}, null, { timeout: 10000 } );

		const modal = await page.evaluate( () => ( {
			title: ( document.querySelector( '#minn-modal-overlay .minn-modal-title' ) || {} ).textContent || '',
			pill: ( document.querySelector( '#minn-modal-overlay .minn-status' ) || {} ).textContent || '',
			wide: !! document.querySelector( '#minn-modal-overlay .minn-modal.wide' ),
			name: ( document.querySelector( '#minn-modal-overlay [data-editfield="name"]' ) || {} ).value || '',
			code: ( document.querySelector( '#minn-modal-overlay [data-editfield="code"]' ) || {} ).value || '',
			hasSave: !! document.querySelector( '#minn-surface-save' ),
			actions: [ ...document.querySelectorAll( '#minn-modal-overlay .minn-modal-actions button, #minn-modal-overlay .minn-modal-actions a' ) ]
				.map( ( e ) => e.textContent.trim() ),
			editHref: ( document.querySelector( '#minn-modal-overlay a[href*="edit-snippet"]' ) || {} ).href || '',
			idTag: ( document.querySelector( '#minn-modal-overlay .minn-modal-id-tag' ) || {} ).textContent || '',
		} ) );
		// The id moved from the title into its own tag (57fb053's contact-card head).
		t.check( 'detail head shows snippet id tag', modal.idTag.trim() === '#' + seed.off.id, modal.idTag );
		t.check( 'detail shows inactive pill', /inactive/i.test( modal.pill ), modal.pill );
		t.check( 'detail is wide for the code editor', modal.wide );
		t.check( 'detail name field is editable', modal.name === nameOff, modal.name );
		t.check( 'detail code field is editable', modal.code.includes( nameOff ), modal.code.slice( 0, 80 ) );
		t.check( 'detail has Save button', modal.hasSave );
		t.check( 'detail offers Activate (not Deactivate)', modal.actions.includes( 'Activate' ) && ! modal.actions.includes( 'Deactivate' ), JSON.stringify( modal.actions ) );
		t.check( 'Edit deep link targets Code Snippets admin', modal.editHref.includes( 'page=edit-snippet&id=' + seed.off.id ), modal.editHref );

		/* ===== In-place save — rewrite code + name ===== */
		const nameRenamed = `Minn suite renamed ${ uid }`;
		await page.fill( '#minn-modal-overlay [data-editfield="name"]', nameRenamed );
		await page.fill( '#minn-modal-overlay [data-editfield="code"]', '// minn test EDITED\nadd_filter( "the_content", "__return_false" );\n' );
		await page.click( '#minn-surface-save' );
		await page.waitForFunction( () => ! document.querySelector( '#minn-modal-overlay' ), { timeout: 10000 } );

		const saved = await page.evaluate( async ( id ) => {
			const r = await fetch( window.MINN.restUrl + 'code-snippets/v1/snippets/' + id, {
				headers: { 'X-WP-Nonce': window.MINN.nonce },
			} );
			const j = await r.json();
			return { ok: r.ok, name: j.name, code: j.code, active: j.active };
		}, seed.off.id );
		t.check( 'Save persists name and code via REST', saved.ok && saved.name === nameRenamed && /EDITED/.test( saved.code ), JSON.stringify( saved ) );
		t.check( 'Save preserves inactive status', saved.active === false, JSON.stringify( saved ) );

		// List title should refresh with the new name.
		await page.waitForFunction( ( n ) =>
			[ ...document.querySelectorAll( '.minn-row-title' ) ].some( ( el ) => el.textContent.includes( n ) )
		, nameRenamed, { timeout: 10000 } );
		t.check( 'list shows renamed snippet after save', true );

		/* ===== Activate toggle still works after edit ===== */
		const renamedIdx = await page.evaluate( ( n ) => {
			const rows = [ ...document.querySelectorAll( '.minn-table-row' ) ];
			return rows.findIndex( ( r ) => ( ( r.querySelector( '.minn-row-title' ) || {} ).textContent || '' ).includes( n ) );
		}, nameRenamed );
		await page.click( `.minn-table-row[data-sitem="${ renamedIdx }"]` );
		await page.waitForSelector( '#minn-surface-save', { timeout: 10000 } );
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
			return { ok: r.ok, active: j.active, name: j.name };
		}, seed.off.id );
		t.check( 'Activate persists via Code Snippets REST', after.ok && after.active === true, JSON.stringify( after ) );
		t.check( 'Activate did not wipe the saved name', after.name === nameRenamed, JSON.stringify( after ) );

		/* ===== Create a new snippet from Minn ===== */
		await page.click( '#minn-surface-add' );
		await page.waitForSelector( '#minn-surface-create', { timeout: 5000 } );
		await page.fill( '[data-createfield="name"]', nameCreated );
		await page.fill( '[data-createfield="code"]', '// created in minn\n' );
		await page.click( '#minn-surface-create' );
		await page.waitForFunction( () => ! document.querySelector( '#minn-modal-overlay' ), { timeout: 10000 } );
		await page.waitForFunction( ( n ) =>
			[ ...document.querySelectorAll( '.minn-row-title' ) ].some( ( el ) => el.textContent.includes( n ) )
		, nameCreated, { timeout: 10000 } );
		const created = await page.evaluate( async ( n ) => {
			const r = await fetch( window.MINN.restUrl + 'code-snippets/v1/snippets?per_page=50', {
				headers: { 'X-WP-Nonce': window.MINN.nonce },
			} );
			const items = await r.json();
			const hit = ( items || [] ).find( ( s ) => s.name === n );
			if ( hit ) {
				await fetch( window.MINN.restUrl + 'code-snippets/v1/snippets/' + hit.id, {
					method: 'DELETE',
					headers: { 'X-WP-Nonce': window.MINN.nonce },
				} );
			}
			return hit ? { id: hit.id, code: hit.code, active: hit.active } : null;
		}, nameCreated );
		t.check( 'Add snippet creates via REST', !! created && /created in minn/.test( created.code ), JSON.stringify( created ) );
		t.check( 'new snippets start inactive', created && created.active === false, JSON.stringify( created ) );

		/* ===== Status card (v0.18.0) ===== */
		const st = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/code-snippets/status?_cb=' + Math.random(), {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' } );
			return { status: r.status, body: await r.json() };
		} );
		t.check( 'status endpoint answers with counts', st.status === 200
			&& ( st.body.rows || [] ).some( ( r ) => r.label === 'Active snippets' ),
			JSON.stringify( st.body.rows || [] ).slice( 0, 120 ) );
		await page.goto( `${ BASE }/minn-admin/code-snippets`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-surface-status', { timeout: 30000 } );
		t.check( 'status card renders above the snippets list', await page.evaluate( () =>
			/Active snippets/.test( document.querySelector( '.minn-surface-status' ).textContent ) ) );
	} finally {
		await cleanup();
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
