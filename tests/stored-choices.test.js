/**
 * Surface edit forms keep stored choices their option lists do not name.
 *
 * The edit modal sends every field on save. A select or combobox whose
 * stored value was missing from its list used to fall back to the FIRST
 * option, so an ordinary rename rewrote a field nobody touched: a WPCode
 * PHP snippet parked "on demand" moved to run everywhere, a universal one
 * was retyped as PHP, and a Code Snippets CSS snippet became global PHP.
 *
 * Every seed is INACTIVE and inert, and every seed is deleted in finally.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'stored-choices' );
	await login( page );

	const uid = Date.now();
	const rest = ( path, opts = {} ) => page.evaluate( async ( [ path, opts ] ) => {
		const r = await fetch( window.MINN.restUrl + path, {
			method: opts.method || 'GET',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: opts.body ? JSON.stringify( opts.body ) : undefined,
		} );
		let j = null;
		try { j = await r.json(); } catch ( e ) { /* 204 */ }
		return { ok: r.ok, status: r.status, j };
	}, [ path, opts ] );

	// Open a seeded row by name, touch only the named text field, save.
	const editAndSave = async ( route, name, field, text, saveUrl ) => {
		await page.goto( `${ BASE }/minn-admin/${ route }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( ( n ) => ( document.querySelector( '.minn-table' )?.textContent || '' ).includes( n ), name, { timeout: 30000 } );
		await page.evaluate( ( n ) => {
			const row = [ ...document.querySelectorAll( '.minn-table-row' ) ].find( ( r ) =>
				( ( r.querySelector( '.minn-row-title' ) || {} ).textContent || '' ).includes( n ) );
			if ( row ) row.click();
		}, name );
		await page.waitForSelector( '#minn-surface-save', { timeout: 15000 } );
		await page.fill( `#minn-modal-overlay [data-editfield="${ field }"]`, text );
		const resp = page.waitForResponse( ( r ) => r.url().includes( saveUrl ) && [ 'PUT', 'POST' ].includes( r.request().method() ), { timeout: 20000 } );
		await page.click( '#minn-surface-save' );
		const r = await resp;
		return { status: r.status(), sent: JSON.parse( r.request().postData() || '{}' ) };
	};

	const boot = await page.evaluate( () => ( window.MINN.surfaces || [] ).map( ( s ) => s.id ) );
	const cleanup = [];

	try {
		/* ===== WPCode: on-demand PHP and a universal snippet ===== */
		if ( boot.includes( 'wpcode' ) ) {
			const seeds = [
				{ name: `Minn stored on-demand ${ uid }`, code_type: 'php', location: 'on_demand' },
				{ name: `Minn stored conditional ${ uid }`, code_type: 'php', location: 'frontend_cl' },
				{ name: `Minn stored universal ${ uid }`, code_type: 'universal', location: 'on_demand' },
			];
			for ( const s of seeds ) {
				const c = await rest( 'minn-admin/v1/wpcode/snippets', { method: 'POST', body: {
					name: s.name, code: '// minn stored-choices probe (inert)\n', code_type: s.code_type,
					location: s.location, auto_insert: true, priority: 10, active: false, tags: [], desc: 'seed',
				} } );
				t.check( `WPCode seed stored at ${ s.location } (${ s.code_type })`, c.ok && c.j.location === s.location && c.j.code_type === s.code_type, JSON.stringify( c.j ).slice( 0, 200 ) );
				if ( ! c.ok ) continue;
				cleanup.push( 'minn-admin/v1/wpcode/snippets/' + c.j.id );
				const saved = await editAndSave( 'wpcode', s.name, 'desc', 'note edited by suite', 'wpcode/snippets/' + c.j.id );
				t.check( `note edit sends the stored location (${ s.location })`, saved.sent.location === s.location, JSON.stringify( saved.sent.location ) );
				t.check( `note edit sends the stored type (${ s.code_type })`, saved.sent.code_type === s.code_type, JSON.stringify( saved.sent.code_type ) );
				const after = await rest( 'minn-admin/v1/wpcode/snippets/' + c.j.id );
				t.check( `${ s.location } snippet keeps location and type after a note edit`,
					after.j && after.j.location === s.location && after.j.code_type === s.code_type && after.j.desc === 'note edited by suite',
					JSON.stringify( after.j && { location: after.j.location, code_type: after.j.code_type, desc: after.j.desc } ) );
			}
		} else {
			console.log( 'SKIP: WPCode not active' );
		}

		/* ===== Code Snippets: a CSS-scoped snippet keeps its scope ===== */
		if ( boot.includes( 'code-snippets' ) ) {
			const name = `Minn stored site-css ${ uid }`;
			const c = await rest( 'code-snippets/v1/snippets', { method: 'POST', body: {
				name, code: '/* minn stored-choices probe */', scope: 'site-css', active: false, priority: 10, tags: [],
			} } );
			t.check( 'Code Snippets seed stored with scope site-css', c.ok && c.j.scope === 'site-css', JSON.stringify( c.j ).slice( 0, 200 ) );
			if ( c.ok ) {
				cleanup.push( 'code-snippets/v1/snippets/' + c.j.id );
				const saved = await editAndSave( 'code-snippets', name, 'name', name + ' renamed', 'code-snippets/v1/snippets/' + c.j.id );
				t.check( 'rename sends the stored scope', saved.sent.scope === 'site-css', JSON.stringify( saved.sent.scope ) );
				const after = await rest( 'code-snippets/v1/snippets/' + c.j.id );
				t.check( 'CSS snippet is still CSS after a rename', after.j && after.j.scope === 'site-css' && after.j.name === name + ' renamed', JSON.stringify( after.j && { scope: after.j.scope, name: after.j.name } ) );
			}
		} else {
			console.log( 'SKIP: Code Snippets not active' );
		}
	} finally {
		for ( const path of cleanup ) {
			await rest( path, { method: 'DELETE' } ).catch( () => {} );
		}
	}

	await t.done( browser, errors );
} )();
