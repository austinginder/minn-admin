/**
 * WPCode + FluentSnippets surfaces share the Snippets label/UX with Code
 * Snippets. Proves boot registration, list/create/edit/toggle/delete through
 * the minn-admin shims, and that multiple snippet plugins can coexist.
 *
 * Individual checks SKIP-friendly when a plugin is missing.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'snippets-adapters' );
	await login( page );

	const boot = await page.evaluate( () => {
		const surfaces = window.MINN.surfaces || [];
		return {
			ids: surfaces.map( ( s ) => s.id ),
			snippets: surfaces
				.filter( ( s ) => s.label === 'Snippets' )
				.map( ( s ) => ( { id: s.id, sub: s.sub, route: s.collection && s.collection.route } ) ),
		};
	} );
	t.check( 'at least one Snippets surface registered', boot.snippets.length >= 1, JSON.stringify( boot.snippets ) );
	t.check( 'Code Snippets surface present', boot.ids.includes( 'code-snippets' ), boot.ids.join( ',' ) );

	const hasWpcode = boot.ids.includes( 'wpcode' );
	const hasFluent = boot.ids.includes( 'fluent-snippets' );
	t.check( 'WPCode surface present when plugin active', hasWpcode, boot.ids.join( ',' ) );
	t.check( 'FluentSnippets surface present when plugin active', hasFluent, boot.ids.join( ',' ) );

	/* ===== WPCode shim ===== */
	if ( hasWpcode ) {
		const wp = await page.evaluate( async () => {
			const hdrs = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
			const create = await fetch( window.MINN.restUrl + 'minn-admin/v1/wpcode/snippets', {
				method: 'POST', headers: hdrs,
				body: JSON.stringify( {
					name: 'Minn WPCode adapter test',
					code: '// adapter test\n',
					code_type: 'php',
					location: 'everywhere',
					priority: 10,
					active: false,
					tags: [ 'minn-test' ],
					desc: 'probe',
				} ),
			} );
			const created = await create.json();
			if ( ! create.ok ) return { err: created.message || 'create failed' };
			const upd = await fetch( window.MINN.restUrl + 'minn-admin/v1/wpcode/snippets/' + created.id, {
				method: 'PUT', headers: hdrs,
				body: JSON.stringify( {
					name: 'Minn WPCode adapter renamed',
					code: '// edited adapter\n',
					code_type: 'php',
					location: 'frontend_only',
					priority: 7,
					active: false,
					tags: [ 'minn-test' ],
					desc: 'edited',
				} ),
			} );
			const updated = await upd.json();
			const act = await fetch( window.MINN.restUrl + 'minn-admin/v1/wpcode/snippets/' + created.id + '/active', {
				method: 'POST', headers: hdrs,
				body: JSON.stringify( { active: true } ),
			} );
			const activated = await act.json();
			const list = await fetch( window.MINN.restUrl + 'minn-admin/v1/wpcode/snippets?per_page=50', {
				headers: { 'X-WP-Nonce': window.MINN.nonce },
			} );
			const listed = await list.json();
			const hit = ( listed.items || [] ).find( ( i ) => i.id === created.id );
			await fetch( window.MINN.restUrl + 'minn-admin/v1/wpcode/snippets/' + created.id, {
				method: 'DELETE', headers: { 'X-WP-Nonce': window.MINN.nonce },
			} );
			return {
				created: created.name,
				updated: updated.name,
				location: updated.location,
				code: updated.code,
				activated: activated.active,
				listed: !! hit,
				scope: hit && hit.scope,
			};
		} );
		t.check( 'WPCode create', wp.created === 'Minn WPCode adapter test', JSON.stringify( wp ) );
		t.check( 'WPCode update name + location + code', wp.updated === 'Minn WPCode adapter renamed' && wp.location === 'frontend_only' && /edited adapter/.test( wp.code ), JSON.stringify( wp ) );
		t.check( 'WPCode activate', wp.activated === true, JSON.stringify( wp ) );
		t.check( 'WPCode list includes created item', wp.listed, JSON.stringify( wp ) );

		// UI: surface route loads a table.
		await page.goto( `${ BASE }/minn-admin/wpcode`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-toolbar, .minn-empty, .minn-table', { timeout: 15000 } );
		const ui = await page.evaluate( () => ( {
			title: document.body.innerText.includes( 'Snippets' ) || document.body.innerText.includes( 'WPCode' ),
			add: !! document.querySelector( '#minn-surface-add' ),
		} ) );
		t.check( 'WPCode surface UI renders with Add', ui.add, JSON.stringify( ui ) );
	}

	/* ===== FluentSnippets shim ===== */
	if ( hasFluent ) {
		const fl = await page.evaluate( async () => {
			const hdrs = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
			const create = await fetch( window.MINN.restUrl + 'minn-admin/v1/fluent-snippets', {
				method: 'POST', headers: hdrs,
				body: JSON.stringify( {
					name: 'Minn Fluent adapter test',
					code: '// fluent adapter\n',
					type: 'PHP',
					run_at: 'all',
					priority: 10,
					active: false,
					tags: [ 'minn-test' ],
					desc: 'probe',
				} ),
			} );
			const created = await create.json();
			if ( ! create.ok ) return { err: created.message || 'create failed', status: create.status };
			const id = created.id;
			const upd = await fetch( window.MINN.restUrl + 'minn-admin/v1/fluent-snippets/' + encodeURIComponent( id ), {
				method: 'PUT', headers: hdrs,
				body: JSON.stringify( {
					name: 'Minn Fluent adapter renamed',
					code: '// fluent edited\n',
					type: 'PHP',
					run_at: 'frontend',
					priority: 4,
					active: false,
					tags: [ 'minn-test' ],
					desc: 'edited',
				} ),
			} );
			const updated = await upd.json();
			const act = await fetch( window.MINN.restUrl + 'minn-admin/v1/fluent-snippets/' + encodeURIComponent( id ) + '/active', {
				method: 'POST', headers: hdrs,
				body: JSON.stringify( { active: true } ),
			} );
			const activated = await act.json();
			const list = await fetch( window.MINN.restUrl + 'minn-admin/v1/fluent-snippets?per_page=50', {
				headers: { 'X-WP-Nonce': window.MINN.nonce },
			} );
			const listed = await list.json();
			const hit = ( listed.items || [] ).find( ( i ) => i.id === id );
			await fetch( window.MINN.restUrl + 'minn-admin/v1/fluent-snippets/' + encodeURIComponent( id ), {
				method: 'DELETE', headers: { 'X-WP-Nonce': window.MINN.nonce },
			} );
			return {
				id,
				created: created.name,
				updated: updated.name,
				run_at: updated.run_at,
				code: updated.code,
				activated: activated.active,
				listed: !! hit,
			};
		} );
		t.check( 'Fluent create', fl.created === 'Minn Fluent adapter test', JSON.stringify( fl ) );
		t.check( 'Fluent update name + run_at + code', fl.updated === 'Minn Fluent adapter renamed' && fl.run_at === 'frontend' && /fluent edited/.test( fl.code ), JSON.stringify( fl ) );
		t.check( 'Fluent activate', fl.activated === true, JSON.stringify( fl ) );
		t.check( 'Fluent list includes created item', fl.listed, JSON.stringify( fl ) );
		t.check( 'Fluent id is a file name', /\.php$/.test( fl.id || '' ), fl.id );

		await page.goto( `${ BASE }/minn-admin/fluent-snippets`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-toolbar, .minn-empty, .minn-table', { timeout: 15000 } );
		const fui = await page.evaluate( () => ( {
			add: !! document.querySelector( '#minn-surface-add' ),
		} ) );
		t.check( 'Fluent surface UI renders with Add', fui.add, JSON.stringify( fui ) );
	}

	/* ===== Coexistence: all Snippets routes are distinct ===== */
	const routes = boot.snippets.map( ( s ) => s.route );
	t.check( 'each Snippets surface has its own route', new Set( routes ).size === routes.length, routes.join( ' | ' ) );

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
