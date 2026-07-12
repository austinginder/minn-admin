/**
 * Snippets leftovers: Simple Custom CSS and JS (CPT) + Header Footer Code
 * Manager (hfcm_scripts). Proves list/create/activate/delete and family
 * membership for both. Plugins rest inactive after the run.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'snippets-leftovers' );
	await login( page );

	const api = ( path, opts = {} ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, {
			method: a.method || 'GET',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': window.MINN.nonce,
			},
			...( a.body ? { body: JSON.stringify( a.body ) } : {} ),
		} );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, { path, ...opts } );

	const setPlugin = async ( file, status ) => page.evaluate( async ( { f, s } ) => {
		try {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + f, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				body: JSON.stringify( { status: s } ),
			} );
			return r.status;
		} catch ( e ) {
			return 0;
		}
	}, { f: file, s: status } );

	const uid = Date.now().toString( 36 );
	const created = { ccj: null, hfcm: null };

	try {
		/* ===== Simple Custom CSS and JS ===== */
		await setPlugin( 'custom-css-js/custom-css-js', 'active' );
		await page.waitForTimeout( 1200 );
		await page.reload( { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-app', { timeout: 20000 } );

		const ccjSurf = await page.evaluate( () => {
			const s = ( window.MINN.surfaces || [] ).find( ( x ) => x.id === 'custom-css-js' );
			return s ? { family: s.family, sub: s.sub, route: s.collection && s.collection.route } : null;
		} );
		t.check( 'CCJ surface joins snippets family',
			!! ccjSurf && ccjSurf.family === 'snippets' && /Simple Custom CSS/.test( ccjSurf.sub || '' ),
			JSON.stringify( ccjSurf ) );

		const ccjCreate = await api( 'minn-admin/v1/ccj/snippets', {
			method: 'POST',
			body: {
				name: 'Minn CCJ ' + uid,
				code: '/* minn ' + uid + ' */\nbody { outline: 1px solid red; }',
				language: 'css',
				type: 'header',
				side: 'frontend',
				linking: 'internal',
				priority: 5,
				active: false,
			},
		} );
		t.check( 'CCJ create returns a snippet',
			ccjCreate.status === 200 && ccjCreate.body && ccjCreate.body.id,
			JSON.stringify( ccjCreate ) );
		created.ccj = ccjCreate.body && ccjCreate.body.id;

		const ccjList = await api( 'minn-admin/v1/ccj/snippets?per_page=50' );
		t.check( 'CCJ list includes the new snippet',
			!! ccjList.body && ( ccjList.body.items || [] ).some( ( r ) => r.id === created.ccj ),
			JSON.stringify( ccjList.body && { total: ccjList.body.total } ) );

		const ccjOn = await api( `minn-admin/v1/ccj/snippets/${ created.ccj }/active`, {
			method: 'POST',
			body: { active: true },
		} );
		t.check( 'CCJ activate sticks',
			ccjOn.status === 200 && ccjOn.body && ccjOn.body.active === true,
			JSON.stringify( ccjOn.body ) );

		await page.goto( `${ BASE }/minn-admin/custom-css-js`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );
		t.check( 'CCJ surface renders rows', true );

		/* ===== Header Footer Code Manager ===== */
		await setPlugin( 'header-footer-code-manager/99robots-header-footer-code-manager', 'active' );
		await page.waitForTimeout( 1200 );
		await page.reload( { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-app', { timeout: 20000 } );

		const hfcmSurf = await page.evaluate( () => {
			const s = ( window.MINN.surfaces || [] ).find( ( x ) => x.id === 'hfcm' );
			return s ? { family: s.family, sub: s.sub } : null;
		} );
		t.check( 'HFCM surface joins snippets family',
			!! hfcmSurf && hfcmSurf.family === 'snippets' && /Header Footer/.test( hfcmSurf.sub || '' ),
			JSON.stringify( hfcmSurf ) );

		const hfcmCreate = await api( 'minn-admin/v1/hfcm/snippets', {
			method: 'POST',
			body: {
				name: 'Minn HFCM ' + uid,
				code: '<!-- minn ' + uid + ' -->',
				snippet_type: 'html',
				location: 'footer',
				device_type: 'both',
				active: true,
			},
		} );
		t.check( 'HFCM create returns a snippet',
			hfcmCreate.status === 200 && hfcmCreate.body && hfcmCreate.body.id,
			JSON.stringify( hfcmCreate ) );
		created.hfcm = hfcmCreate.body && hfcmCreate.body.id;

		const hfcmList = await api( 'minn-admin/v1/hfcm/snippets?per_page=50' );
		t.check( 'HFCM list includes the new snippet',
			!! hfcmList.body && ( hfcmList.body.items || [] ).some( ( r ) => r.id === created.hfcm ),
			JSON.stringify( hfcmList.body && { total: hfcmList.body.total } ) );

		const hfcmOff = await api( `minn-admin/v1/hfcm/snippets/${ created.hfcm }/active`, {
			method: 'POST',
			body: { active: false },
		} );
		t.check( 'HFCM deactivate sticks',
			hfcmOff.status === 200 && hfcmOff.body && hfcmOff.body.active === false,
			JSON.stringify( hfcmOff.body ) );

		await page.goto( `${ BASE }/minn-admin/hfcm`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-table-row', { timeout: 20000 } );
		t.check( 'HFCM surface renders rows', true );

		// Delete both.
		if ( created.ccj ) {
			const d = await api( `minn-admin/v1/ccj/snippets/${ created.ccj }`, { method: 'DELETE' } );
			t.check( 'CCJ delete works', d.status === 200 && d.body && d.body.deleted, JSON.stringify( d ) );
		}
		if ( created.hfcm ) {
			const d = await api( `minn-admin/v1/hfcm/snippets/${ created.hfcm }`, { method: 'DELETE' } );
			t.check( 'HFCM delete works', d.status === 200 && d.body && d.body.deleted, JSON.stringify( d ) );
		}

	} finally {
		// Clean leftovers if delete failed mid-run.
		if ( created.ccj ) {
			await api( `minn-admin/v1/ccj/snippets/${ created.ccj }`, { method: 'DELETE' } ).catch( () => {} );
		}
		if ( created.hfcm ) {
			await api( `minn-admin/v1/hfcm/snippets/${ created.hfcm }`, { method: 'DELETE' } ).catch( () => {} );
		}
		await setPlugin( 'custom-css-js/custom-css-js', 'inactive' ).catch( () => {} );
		await setPlugin( 'header-footer-code-manager/99robots-header-footer-code-manager', 'inactive' ).catch( () => {} );
		await t.done( browser, errors );
	}
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
