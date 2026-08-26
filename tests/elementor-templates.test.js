/**
 * Elementor templates surface (adapters/elementor-templates.php).
 *
 * Elementor + Pro are active fixtures on minnadmin, so this runs there
 * (unlike bricks.test.js, which SKIPs without the Bricks theme). Standing
 * fixtures: "Minn Site Header" (type header, Entire site) and
 * "Minn CTA Section" (type section). The suite creates its own probe
 * templates and force-deletes them on the way out.
 *
 *   MINN_TEST_PASS=… node elementor-templates.test.js
 */
const { execFileSync } = require( 'child_process' );
const { launch, login, reporter, BASE, WP, pickCombo } = require( './helpers' );

const wpEval = ( code ) => execFileSync( 'wp', [ '--path=' + WP, 'eval', code ], { encoding: 'utf8' } ).trim();

( async () => {
	const t = reporter( 'elementor-templates' );
	const { browser, page, errors } = await launch();
	await login( page );
	await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN && Array.isArray( window.MINN.surfaces ), null, { timeout: 20000 } );

	const surface = await page.evaluate( () =>
		( window.MINN.surfaces || [] ).find( ( s ) => s.id === 'elementor-templates' ) || null );
	if ( ! surface ) {
		console.log( 'SKIP: no Elementor templates surface on this site' );
		await browser.close().catch( () => {} );
		process.exit( 0 );
	}

	const rest = ( method, path, body ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path + ( a.path.includes( '?' ) ? '&' : '?' ) + '_cb=' + Math.random(), {
			method: a.method, credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: a.body ? JSON.stringify( a.body ) : undefined,
		} );
		return { status: r.status, data: await r.json().catch( () => null ) };
	}, { method, path, body } );
	const rowWith = ( text ) => page.evaluate( ( tx ) =>
		( Array.from( document.querySelectorAll( '.minn-table-row, .minn-surface-row' ) ).find( ( r ) => r.textContent.includes( tx ) ) || { textContent: '' } ).textContent, text );
	const probeIds = [];

	try {
		t.check( 'surface declares type tabs from Elementor\'s registry',
			Array.isArray( surface.collection.tabs.static ) && surface.collection.tabs.static.some( ( tab ) => tab[ 0 ] === 'header' ) && surface.collection.tabs.static.some( ( tab ) => tab[ 0 ] === 'section' ),
			String( ( surface.collection.tabs.static || [] ).map( ( tab ) => tab[ 0 ] ) ) );

		await page.goto( BASE + '/minn-admin/elementor-templates', { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-table-row, .minn-surface-row' ) ).some( ( r ) => r.textContent.includes( 'Minn Site Header' ) ),
		null, { timeout: 20000 } );
		const headerRow = await rowWith( 'Minn Site Header' );
		t.check( 'fixture row carries the Header type pill', /Header/i.test( headerRow ), headerRow.slice( 0, 160 ) );
		t.check( 'fixture row shows Entire site from their Instances column', /Entire site/i.test( headerRow ), headerRow.slice( 0, 160 ) );

		await page.click( '[data-stabcombo] .minn-ac-input' );
		await page.waitForSelector( '.minn-ac-panel:not([hidden]) .minn-ac-item[data-acv="header"]', { timeout: 8000 } );
		await page.click( '.minn-ac-panel:not([hidden]) .minn-ac-item[data-acv="header"]' );
		await page.waitForFunction( () => {
			const rows = Array.from( document.querySelectorAll( '.minn-table-row, .minn-surface-row' ) );
			return rows.length && rows.every( ( r ) => ! r.textContent.includes( 'Minn CTA Section' ) );
		}, null, { timeout: 15000 } );
		t.check( 'Header tab filters out the section template', true );

		await page.click( '[data-stabcombo] .minn-ac-input' );
		await page.waitForSelector( '.minn-ac-panel:not([hidden]) .minn-ac-item', { timeout: 8000 } );
		await page.click( '.minn-ac-panel:not([hidden]) .minn-ac-item' );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-table-row, .minn-surface-row' ) ).some( ( r ) => r.textContent.includes( 'Minn CTA Section' ) ),
		null, { timeout: 15000 } );

		await page.click( '#minn-surface-add' );
		await page.waitForSelector( '[data-createfield="title"]', { timeout: 8000 } );
		await page.type( '[data-createfield="title"]', 'Suite Probe Footer' );
		await pickCombo( page, '[data-createfield="type"] .minn-ac-input', 'footer' );
		await page.click( '#minn-surface-create' );
		await page.waitForFunction( () =>
			! document.querySelector( '[data-createfield="title"]' )
			&& Array.from( document.querySelectorAll( '.minn-table-row, .minn-surface-row' ) ).some( ( r ) => r.textContent.includes( 'Suite Probe Footer' ) ),
		null, { timeout: 15000 } );
		let list = await rest( 'GET', 'minn-admin/v1/elementor/templates?search=Suite Probe' );
		let probe = ( list.data.items || [] ).find( ( i ) => i.title === 'Suite Probe Footer' );
		t.check( 'create stores the template with its type through documents->create', !! probe && probe.type === 'footer' && probe.status === 'publish', JSON.stringify( probe ) );
		if ( probe ) probeIds.push( probe.id );

		await page.evaluate( () => {
			Array.from( document.querySelectorAll( '.minn-table-row, .minn-surface-row' ) )
				.find( ( r ) => r.textContent.includes( 'Suite Probe Footer' ) ).click();
		} );
		await page.waitForSelector( '[data-editfield="title"]', { timeout: 10000 } );
		t.check( 'detail offers Edit in Elementor with the canvas URL', await page.evaluate( () =>
			Array.from( document.querySelectorAll( '.minn-modal a' ) ).some( ( a ) => /action=elementor/.test( a.href ) ) ) );
		t.check( 'detail does not offer a type picker (type is create-only)', await page.evaluate( () =>
			! document.querySelector( '[data-editfield="type"]' ) ) );
		await page.evaluate( () => { document.querySelector( '[data-editfield="title"]' ).value = ''; } );
		await page.type( '[data-editfield="title"]', 'Suite Probe Renamed' );
		const renamed = page.waitForResponse( ( res ) =>
			res.request().method() === 'PUT' && /elementor\/templates\//.test( res.url() ), { timeout: 20000 } );
		await page.click( '#minn-surface-save' );
		await renamed;
		list = await rest( 'GET', 'minn-admin/v1/elementor/templates?search=Suite Probe' );
		probe = ( list.data.items || [] ).find( ( i ) => i.id === probeIds[ 0 ] );
		t.check( 'edit persists rename and leaves the type alone', !! probe && probe.title === 'Suite Probe Renamed' && probe.type === 'footer', JSON.stringify( probe ) );

		const emptyExport = await rest( 'GET', 'minn-admin/v1/elementor/templates/' + probeIds[ 0 ] + '/export' );
		t.check( 'export of an empty template is refused', emptyExport.status === 400, JSON.stringify( emptyExport.data ) );

		const sectionList = await rest( 'GET', 'minn-admin/v1/elementor/templates?search=Minn CTA' );
		const section = ( sectionList.data.items || [] ).find( ( i ) => i.title === 'Minn CTA Section' );
		t.check( 'section fixture is listed', !! section && section.type === 'section', JSON.stringify( section ) );
		if ( section ) {
			const exported = await rest( 'GET', 'minn-admin/v1/elementor/templates/' + section.id + '/export' );
			let payload = null;
			try { payload = JSON.parse( exported.data.content ); } catch ( e ) { /* stays null */ }
			t.check( 'export serves Elementor\'s interchange JSON', exported.status === 200
				&& /\.json$/.test( exported.data.filename ) && !! payload && payload.type === 'section' && Array.isArray( payload.content ) && payload.content.length > 0,
				exported.data && exported.data.filename );
		}

		const headerList = await rest( 'GET', 'minn-admin/v1/elementor/templates?search=Minn Site Header' );
		const header = ( headerList.data.items || [] ).find( ( i ) => i.title === 'Minn Site Header' );
		t.check( 'header fixture reports Entire site', !! header && /Entire site/i.test( header.conditions ), JSON.stringify( header ) );
		if ( header ) {
			const dup = await rest( 'POST', 'minn-admin/v1/elementor/templates/' + header.id + '/duplicate' );
			t.check( 'duplicate clones type and strips conditions', dup.status === 200 && dup.data.type === 'header' && dup.data.conditions === '', JSON.stringify( dup.data ) );
			if ( dup.data && dup.data.id ) probeIds.push( dup.data.id );
		}

		await page.waitForFunction( () => ! document.querySelector( '[data-editfield="title"]' ), null, { timeout: 15000 } ).catch( () => {} );
		await page.evaluate( () => {
			const m = document.querySelector( '.minn-modal' );
			if ( m ) {
				const x = m.querySelector( '.minn-modal-close, [data-mclose]' );
				if ( x ) x.click();
			}
		} );
		await page.goto( BASE + '/minn-admin/elementor-templates', { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-table-row, .minn-surface-row' ) ).some( ( r ) => r.textContent.includes( 'Suite Probe Renamed' ) ),
		null, { timeout: 20000 } );
		await page.evaluate( () => {
			Array.from( document.querySelectorAll( '.minn-table-row, .minn-surface-row' ) )
				.find( ( r ) => r.textContent.includes( 'Suite Probe Renamed' ) ).click();
		} );
		await page.waitForSelector( '[data-saction]', { timeout: 10000 } );
		page.once( 'dialog', ( d ) => d.accept() );
		await page.evaluate( () => {
			Array.from( document.querySelectorAll( '[data-saction]' ) ).find( ( b ) => /trash/i.test( b.textContent ) ).click();
		} );
		await page.waitForFunction( () =>
			! Array.from( document.querySelectorAll( '.minn-table-row, .minn-surface-row' ) ).some( ( r ) => r.textContent.includes( 'Suite Probe Renamed' ) ),
		null, { timeout: 15000 } );
		list = await rest( 'GET', 'minn-admin/v1/elementor/templates?search=Suite Probe Renamed' );
		t.check( 'trash removes the probe from the list', ! ( list.data.items || [] ).some( ( i ) => i.title === 'Suite Probe Renamed' ), String( list.data.total ) );

		const bad = await rest( 'POST', 'minn-admin/v1/elementor/templates', { title: '', type: 'footer' } );
		t.check( 'create without a title is refused', bad.status === 400, JSON.stringify( bad.data ) );
		const badType = await rest( 'POST', 'minn-admin/v1/elementor/templates', { title: 'Nope', type: 'kit' } );
		t.check( 'create with a skipped type is refused', badType.status === 400, JSON.stringify( badType.data ) );

		t.check( 'type tabs include Floating Buttons',
			( surface.collection.tabs.static || [] ).some( ( tab ) => tab[ 0 ] === 'floating-buttons' ),
			String( ( surface.collection.tabs.static || [] ).map( ( tab ) => tab[ 0 ] ) ) );
		const floated = await rest( 'POST', 'minn-admin/v1/elementor/templates', { title: 'Suite Probe Float', type: 'floating-buttons' } );
		t.check( 'create a floating button through the same route', floated.status === 200 && floated.data && floated.data.type === 'floating-buttons', JSON.stringify( floated.data ) );
		if ( floated.data && floated.data.id ) {
			probeIds.push( floated.data.id );
			const listed = await rest( 'GET', 'minn-admin/v1/elementor/templates?type=floating-buttons&search=Suite Probe Float' );
			t.check( 'Floating Buttons tab lists the new item', ( listed.data.items || [] ).some( ( i ) => i.id === floated.data.id ), String( listed.data.total ) );
			const dupFloat = await rest( 'POST', 'minn-admin/v1/elementor/templates/' + floated.data.id + '/duplicate' );
			t.check( 'duplicate keeps the floating type', dupFloat.status === 200 && dupFloat.data && dupFloat.data.type === 'floating-buttons', JSON.stringify( dupFloat.data ) );
			if ( dupFloat.data && dupFloat.data.id ) probeIds.push( dupFloat.data.id );
		}

		const card = await rest( 'GET', 'minn-admin/v1/elementor/templates/status' );
		const labels = ( ( card.data && card.data.rows ) || [] ).map( ( r ) => r.label );
		t.check( 'status card reports template counts', card.status === 200 && labels.includes( 'Templates' ) && labels.includes( 'Floating buttons' ), JSON.stringify( card.data ) );
		t.check( 'status card links to Elementor',
			( ( card.data && card.data.actions ) || [] ).some( ( a ) => /post_type=elementor_library/.test( a.href || '' ) ),
			JSON.stringify( card.data && card.data.actions ) );
	} finally {
		for ( const id of probeIds ) {
			try { wpEval( 'wp_delete_post( ' + id + ', true ); echo "gone";' ); } catch ( e ) { /* already gone */ }
		}
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
