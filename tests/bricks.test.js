/**
 * Bricks templates surface + the Bricks visibility provider
 * (adapters/bricks.php + the site-status detector/writer).
 *
 * Bricks is a THEME, so this cannot run on minnadmin (its active theme is
 * the marketing theme and must stay). On a site without Bricks active the
 * suite SKIPs (exit 0, the acf-options convention). Run it for real against
 * the builders lab, where Bricks is the active theme:
 *
 *   MINN_TEST_URL=https://builders.localhost MINN_TEST_USER=admin \
 *   MINN_TEST_PASS=minn-builders-test-1 \
 *   MINN_TEST_WP=/Users/austin/Cove/Sites/builders.localhost/public \
 *   node bricks.test.js
 *
 * Expects the standing lab fixtures: "Site Header" (type header, condition
 * Entire website) and "CTA Section" (type section). The suite creates its
 * own probe templates and force-deletes them on the way out; the visibility
 * half arms bricks_global_settings.maintenanceMode over wp-cli and restores
 * the key absent in the finally. Front-end probes carry a cache-buster (the
 * lab runs a page cache that happily serves a pre-maintenance copy).
 */
const { execFileSync } = require( 'child_process' );
const { launch, login, reporter, BASE, WP } = require( './helpers' );

const wpEval = ( code ) => execFileSync( 'wp', [ '--path=' + WP, 'eval', code ], { encoding: 'utf8' } ).trim();

( async () => {
	const t = reporter( 'bricks' );
	const { browser, page, errors } = await launch();
	await login( page );
	await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN && Array.isArray( window.MINN.surfaces ), null, { timeout: 20000 } );

	const surface = await page.evaluate( () =>
		( window.MINN.surfaces || [] ).find( ( s ) => s.id === 'bricks-templates' ) || null );
	if ( ! surface ) {
		console.log( 'SKIP: no Bricks templates surface on this site (Bricks must be the active theme — run against builders.localhost)' );
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
	let prevFrontBar = null;

	try {
		t.check( 'surface declares the type tabs', Array.isArray( surface.collection.tabs.static ) && surface.collection.tabs.static.length >= 8,
			String( ( surface.collection.tabs.static || [] ).length ) );
		const tabValues = ( surface.collection.tabs.static || [] ).map( ( row ) => row[ 0 ] );
		const createTypes = ( ( surface.collection.create || {} ).fields || [] )
			.filter( ( f ) => f.key === 'type' )
			.flatMap( ( f ) => ( f.options || [] ).map( ( o ) => o[ 0 ] ) );
		t.check( 'type tabs come from Bricks and end with Trash',
			tabValues.includes( 'header' ) && tabValues.includes( 'footer' ) && tabValues.includes( 'content' )
			&& tabValues[ tabValues.length - 1 ] === 'trash' && ! createTypes.includes( 'trash' ),
			JSON.stringify( { tabValues, createTypes } ) );
		t.check( 'Import is offered on the templates list',
			!! ( surface.collection.import && /bricks\/templates\/import/.test( surface.collection.import.route ) ),
			JSON.stringify( surface.collection.import ) );
		const liveTypes = JSON.parse( wpEval( 'echo wp_json_encode( minn_admin_bricks_template_types() );' ) );
		const bricksTypes = JSON.parse( wpEval( 'echo wp_json_encode( \\Bricks\\Setup::get_control_options( "templateTypes" ) );' ) );
		t.check( 'template types match Bricks\' own control options',
			JSON.stringify( Object.keys( liveTypes ) ) === JSON.stringify( Object.keys( bricksTypes || {} ) ),
			JSON.stringify( { live: Object.keys( liveTypes ), bricks: Object.keys( bricksTypes || {} ) } ) );
		const wooLogin = wpEval( 'add_filter( "bricks/setup/control_options", function ( $o ) { $o["templateTypes"]["wc_account_form_login"] = "WooCommerce - Account - Login"; return $o; }, 99 ); echo isset( minn_admin_bricks_template_types()["wc_account_form_login"] ) ? "yes" : "no";' );
		t.check( 'Woo types join when Bricks filters them on', wooLogin === 'yes', wooLogin );

		/* ===== List renders the standing fixtures ===== */
		await page.goto( BASE + '/minn-admin/bricks-templates', { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-table-row, .minn-surface-row' ) ).some( ( r ) => r.textContent.includes( 'Site Header' ) ),
		null, { timeout: 20000 } );
		const headerRow = await rowWith( 'Site Header' );
		t.check( 'fixture row carries the type pill', /Header/i.test( headerRow ), headerRow.slice( 0, 140 ) );
		t.check( 'fixture row shows the conditions summary', /Entire website/.test( headerRow ), headerRow.slice( 0, 140 ) );

		/* ===== Type tab (9 tabs → the strip renders as a combobox) ===== */
		await page.click( '[data-stabcombo] .minn-ac-input' );
		await page.waitForSelector( '.minn-ac-panel:not([hidden]) .minn-ac-item[data-acv="header"]', { timeout: 8000 } );
		await page.click( '.minn-ac-panel:not([hidden]) .minn-ac-item[data-acv="header"]' );
		await page.waitForFunction( () => {
			const rows = Array.from( document.querySelectorAll( '.minn-table-row, .minn-surface-row' ) );
			return rows.length && rows.every( ( r ) => ! r.textContent.includes( 'CTA Section' ) );
		}, null, { timeout: 15000 } );
		t.check( 'Header tab filters out the section template', true );
		// Back to All for the create flow.
		await page.click( '[data-stabcombo] .minn-ac-input' );
		await page.waitForSelector( '.minn-ac-panel:not([hidden]) .minn-ac-item', { timeout: 8000 } );
		await page.click( '.minn-ac-panel:not([hidden]) .minn-ac-item' );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-table-row, .minn-surface-row' ) ).some( ( r ) => r.textContent.includes( 'CTA Section' ) ),
		null, { timeout: 15000 } );

		/* ===== Create through the modal (type is a themed combobox) ===== */
		await page.click( '#minn-surface-add' );
		await page.waitForSelector( '[data-createfield="title"]', { timeout: 8000 } );
		await page.type( '[data-createfield="title"]', 'Suite Probe Footer' );
		await page.click( '[data-createfield="type"] .minn-ac-input' );
		await page.waitForSelector( '[data-createfield="type"] .minn-ac-item[data-acv="footer"]', { timeout: 5000 } );
		await page.click( '[data-createfield="type"] .minn-ac-item[data-acv="footer"]' );
		await page.click( '#minn-surface-create' );
		await page.waitForFunction( () =>
			! document.querySelector( '[data-createfield="title"]' )
			&& Array.from( document.querySelectorAll( '.minn-table-row, .minn-surface-row' ) ).some( ( r ) => r.textContent.includes( 'Suite Probe Footer' ) ),
		null, { timeout: 15000 } );
		let list = await rest( 'GET', 'minn-admin/v1/bricks/templates?search=Suite Probe' );
		let probe = ( list.data.items || [] ).find( ( i ) => i.title === 'Suite Probe Footer' );
		t.check( 'create stores the template with its type', !! probe && probe.type === 'footer' && probe.status === 'publish', JSON.stringify( probe ) );
		if ( probe ) probeIds.push( probe.id );

		/* ===== Detail edit: rename + retype ===== */
		await page.evaluate( () => {
			Array.from( document.querySelectorAll( '.minn-table-row, .minn-surface-row' ) )
				.find( ( r ) => r.textContent.includes( 'Suite Probe Footer' ) ).click();
		} );
		await page.waitForSelector( '[data-editfield="title"]', { timeout: 10000 } );
		t.check( 'detail offers Edit in Bricks with the builder URL', await page.evaluate( () =>
			Array.from( document.querySelectorAll( '.minn-modal a' ) ).some( ( a ) => /bricks=run/.test( a.href ) ) ) );
		await page.evaluate( () => { document.querySelector( '[data-editfield="title"]' ).value = ''; } );
		await page.type( '[data-editfield="title"]', 'Suite Probe Popup' );
		await page.click( '[data-editfield="type"] .minn-ac-input' );
		await page.waitForSelector( '[data-editfield="type"] .minn-ac-item[data-acv="popup"]', { timeout: 5000 } );
		await page.click( '[data-editfield="type"] .minn-ac-item[data-acv="popup"]' );
		await page.click( '#minn-surface-save' );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-toast' ) ).some( ( x ) => /saved/i.test( x.textContent ) ),
		null, { timeout: 15000 } );
		list = await rest( 'GET', 'minn-admin/v1/bricks/templates?search=Suite Probe' );
		probe = ( list.data.items || [] ).find( ( i ) => i.id === probeIds[ 0 ] );
		t.check( 'edit persists rename + type change', !! probe && probe.title === 'Suite Probe Popup' && probe.type === 'popup', JSON.stringify( probe ) );

		/* ===== Tags edit + Export action (probe row) ===== */
		await page.waitForFunction( () => ! document.querySelector( '[data-editfield="title"]' ), null, { timeout: 15000 } );
		await page.evaluate( () => {
			Array.from( document.querySelectorAll( '.minn-table-row, .minn-surface-row' ) )
				.find( ( r ) => r.textContent.includes( 'Suite Probe Popup' ) ).click();
		} );
		await page.waitForSelector( '[data-editfield="tags"]', { timeout: 10000 } );
		t.check( 'detail offers the Export action', await page.evaluate( () =>
			Array.from( document.querySelectorAll( '[data-saction]' ) ).some( ( b ) => /Export/.test( b.textContent ) ) ) );
		await page.type( '[data-editfield="tags"]', 'suite-tag-a, suite tag b' );
		// Wait on the PUT response, never toast text: the rename save's toast
		// lingers and satisfies a text wait before this save even lands.
		const tagsSaved = page.waitForResponse( ( res ) =>
			res.request().method() === 'PUT' && /bricks\/templates\//.test( res.url() ), { timeout: 20000 } );
		await page.click( '#minn-surface-save' );
		await tagsSaved;
		list = await rest( 'GET', 'minn-admin/v1/bricks/templates?search=Suite Probe' );
		probe = ( list.data.items || [] ).find( ( i ) => i.id === probeIds[ 0 ] );
		t.check( 'tags create and attach through the edit field', !! probe && /suite-tag-a/.test( probe.tags ) && /suite tag b/.test( probe.tags ), probe && probe.tags );
		const exported = await rest( 'GET', 'minn-admin/v1/bricks/templates/' + probeIds[ 0 ] + '/export' );
		let exportPayload = null;
		try { exportPayload = JSON.parse( exported.data.content ); } catch ( e ) { /* stays null */ }
		t.check( 'export serves Bricks\' own payload as a download', exported.status === 200
			&& /\.json$/.test( exported.data.filename ) && !! exportPayload && exportPayload.templateType === 'popup',
			exported.data.filename );

		/* ===== Import the same JSON back as a new template ===== */
		const importBody = Object.assign( {}, exportPayload, { title: 'Suite Probe Import' } );
		const imported = await rest( 'POST', 'minn-admin/v1/bricks/templates/import', { content: JSON.stringify( importBody ) } );
		t.check( 'import creates a template from Bricks JSON',
			imported.status === 200 && Array.isArray( imported.data.ids ) && imported.data.ids.length === 1
			&& /Imported 1 template/.test( imported.data.message || '' ),
			JSON.stringify( imported.data ) );
		if ( imported.data && imported.data.ids && imported.data.ids[ 0 ] ) probeIds.push( imported.data.ids[ 0 ] );
		list = await rest( 'GET', 'minn-admin/v1/bricks/templates?search=Suite Probe Import' );
		const importedRow = ( list.data.items || [] ).find( ( i ) => i.title === 'Suite Probe Import' );
		t.check( 'imported template keeps its type', !! importedRow && importedRow.type === 'popup', JSON.stringify( importedRow ) );
		const badImport = await rest( 'POST', 'minn-admin/v1/bricks/templates/import', { content: 'not json {' } );
		t.check( 'invalid JSON import refuses 400', badImport.status === 400, JSON.stringify( badImport.data ) );

		/* ===== Duplicate from the detail modal ===== */
		await page.waitForFunction( () => ! document.querySelector( '[data-editfield="title"]' ), null, { timeout: 15000 } );
		await page.evaluate( () => {
			Array.from( document.querySelectorAll( '.minn-table-row, .minn-surface-row' ) )
				.find( ( r ) => r.textContent.includes( 'Suite Probe Popup' ) ).click();
		} );
		await page.waitForSelector( '[data-saction]', { timeout: 10000 } );
		await page.evaluate( () => {
			Array.from( document.querySelectorAll( '[data-saction]' ) ).find( ( b ) => /Duplicate/.test( b.textContent ) ).click();
		} );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-table-row, .minn-surface-row' ) ).some( ( r ) => r.textContent.includes( '(copy)' ) ),
		null, { timeout: 15000 } );
		list = await rest( 'GET', 'minn-admin/v1/bricks/templates?search=Suite Probe' );
		const copy = ( list.data.items || [] ).find( ( i ) => /\(copy\)/.test( i.title ) );
		t.check( 'duplicate clones the template with its type', !! copy && copy.type === 'popup', JSON.stringify( copy ) );
		if ( copy ) probeIds.push( copy.id );

		/* ===== Trash via the detail action (confirm) ===== */
		await page.evaluate( () => {
			const m = document.querySelector( '.minn-modal' );
			if ( m ) {
				const x = m.querySelector( '.minn-modal-close, [data-mclose]' );
				if ( x ) x.click();
			}
		} );
		await page.evaluate( () => {
			Array.from( document.querySelectorAll( '.minn-table-row, .minn-surface-row' ) )
				.find( ( r ) => r.textContent.includes( '(copy)' ) ).click();
		} );
		await page.waitForSelector( '[data-saction]', { timeout: 10000 } );
		page.once( 'dialog', ( d ) => d.accept() );
		await page.evaluate( () => {
			Array.from( document.querySelectorAll( '[data-saction]' ) ).find( ( b ) => /trash/i.test( b.textContent ) ).click();
		} );
		await page.waitForFunction( () =>
			! Array.from( document.querySelectorAll( '.minn-table-row, .minn-surface-row' ) ).some( ( r ) => r.textContent.includes( '(copy)' ) ),
		null, { timeout: 15000 } );
		list = await rest( 'GET', 'minn-admin/v1/bricks/templates?search=Suite Probe' );
		t.check( 'trash removes the copy from the list', ! ( list.data.items || [] ).some( ( i ) => /\(copy\)/.test( i.title ) ), String( list.data.total ) );
		const trashed = await rest( 'GET', 'minn-admin/v1/bricks/templates?type=trash&search=Suite Probe' );
		const trashRow = ( trashed.data.items || [] ).find( ( i ) => /\(copy\)/.test( i.title ) );
		t.check( 'Trash tab lists the trashed template', !! trashRow && trashRow.inTrash === true, JSON.stringify( trashRow ) );

		await page.click( '[data-stabcombo] .minn-ac-input' );
		await page.waitForSelector( '.minn-ac-panel:not([hidden]) .minn-ac-item[data-acv="trash"]', { timeout: 8000 } );
		await page.click( '.minn-ac-panel:not([hidden]) .minn-ac-item[data-acv="trash"]' );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-table-row, .minn-surface-row' ) ).some( ( r ) => r.textContent.includes( '(copy)' ) ),
		null, { timeout: 15000 } );
		t.check( 'Trash tab shows the trashed copy', true );
		await page.evaluate( () => {
			Array.from( document.querySelectorAll( '.minn-table-row, .minn-surface-row' ) )
				.find( ( r ) => r.textContent.includes( '(copy)' ) ).click();
		} );
		await page.waitForSelector( '[data-saction]', { timeout: 10000 } );
		t.check( 'a trashed template offers Restore, not Edit in Bricks', await page.evaluate( () => {
			const labels = Array.from( document.querySelectorAll( '[data-saction]' ) ).map( ( b ) => b.textContent );
			return labels.some( ( l ) => /Restore/.test( l ) ) && ! labels.some( ( l ) => /Edit in Bricks/.test( l ) );
		} ) );
		await page.evaluate( () => {
			Array.from( document.querySelectorAll( '[data-saction]' ) ).find( ( b ) => /Restore/.test( b.textContent ) ).click();
		} );
		await page.waitForFunction( () =>
			! Array.from( document.querySelectorAll( '.minn-table-row, .minn-surface-row' ) ).some( ( r ) => r.textContent.includes( '(copy)' ) ),
		null, { timeout: 15000 } );
		list = await rest( 'GET', 'minn-admin/v1/bricks/templates?search=Suite Probe' );
		const restoredCopy = ( list.data.items || [] ).find( ( i ) => /\(copy\)/.test( i.title ) );
		t.check( 'restore returns the copy to the live list', !! restoredCopy && restoredCopy.inTrash === false, JSON.stringify( restoredCopy ) );
		if ( restoredCopy ) {
			const retrash = await rest( 'DELETE', 'minn-admin/v1/bricks/templates/' + restoredCopy.id );
			t.check( 're-trash of a restored copy 200s', retrash.status === 200, JSON.stringify( retrash.data ) );
			const gone = await rest( 'DELETE', 'minn-admin/v1/bricks/templates/' + restoredCopy.id );
			t.check( 'delete from trash is permanent', gone.status === 200 && gone.data && gone.data.deleted === restoredCopy.id,
				JSON.stringify( gone.data ) );
			const still = await rest( 'GET', 'minn-admin/v1/bricks/templates?type=trash&search=Suite Probe' );
			t.check( 'permanently deleted copy is gone from Trash',
				! ( still.data.items || [] ).some( ( i ) => i.id === restoredCopy.id ), String( still.data.total ) );
			const idx = probeIds.indexOf( restoredCopy.id );
			if ( idx >= 0 ) probeIds.splice( idx, 1 );
		}

		await page.click( '[data-stabcombo] .minn-ac-input' );
		await page.waitForSelector( '.minn-ac-panel:not([hidden]) .minn-ac-item', { timeout: 8000 } );
		await page.click( '.minn-ac-panel:not([hidden]) .minn-ac-item' );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-table-row, .minn-surface-row' ) ).some( ( r ) => r.textContent.includes( 'CTA Section' ) ),
		null, { timeout: 15000 } );

		await page.waitForSelector( '#minn-surface-import', { timeout: 8000 } );
		await page.click( '#minn-surface-import' );
		await page.waitForSelector( '#minn-simport-text', { timeout: 5000 } );
		const pastePayload = JSON.stringify( { title: 'Suite Probe Pasted', templateType: 'section', content: [] } );
		await page.evaluate( ( content ) => { document.querySelector( '#minn-simport-text' ).value = content; }, pastePayload );
		const pasteWait = page.waitForResponse( ( res ) =>
			res.request().method() === 'POST' && /bricks\/templates\/import/.test( res.url() ), { timeout: 15000 } );
		await page.click( '#minn-simport-go' );
		t.check( 'import dialog posts and succeeds', ( await pasteWait ).status() === 200 );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-table-row, .minn-surface-row' ) ).some( ( r ) => r.textContent.includes( 'Suite Probe Pasted' ) ),
		null, { timeout: 15000 } );
		t.check( 'pasted import appears in the list', true );
		list = await rest( 'GET', 'minn-admin/v1/bricks/templates?search=Suite Probe Pasted' );
		const pasted = ( list.data.items || [] ).find( ( i ) => i.title === 'Suite Probe Pasted' );
		if ( pasted ) probeIds.push( pasted.id );

		/* ===== Settings view: curated schema over bricks_global_settings ===== */
		await page.click( '[data-sview="settings"]' );
		await page.waitForSelector( '.minn-surface-settings', { timeout: 15000 } );
		await page.waitForSelector( '[data-ssettab]', { timeout: 15000 } );
		const setTabs = await page.$$eval( '[data-ssettab]', ( els ) => els.map( ( e ) => e.dataset.ssettab ) );
		t.check( 'settings view renders the four curated tabs',
			[ 'general', 'templates', 'builder', 'maintenance' ].every( ( id ) => setTabs.includes( id ) ), setTabs.join( ',' ) );
		await page.waitForSelector( '[data-sset="postTypes"] .minn-switch[data-mcv]', { timeout: 8000 } );
		t.check( 'post types are Minn switches, not native checkboxes',
			await page.$eval( '[data-sset="postTypes"]', ( el ) =>
				el.querySelectorAll( '.minn-switch[data-mcv]' ).length >= 2
				&& el.querySelectorAll( 'input[type="checkbox"]' ).length === 0 ) );
		await page.click( '[data-ssettab="templates"]' );
		await page.waitForSelector( '[data-sset="publicTemplates"]', { timeout: 15000 } );
		const saveSettings = async () => {
			const wait = page.waitForResponse( ( res ) =>
				res.request().method() === 'POST' && /bricks\/settings\//.test( res.url() ), { timeout: 20000 } );
			await page.click( '#minn-sset-save' );
			const res = await wait;
			await page.waitForTimeout( 300 );
			return res.status();
		};
		await page.evaluate( () => document.querySelector( '[data-sset="publicTemplates"]' ).click() );
		t.check( 'toggle save 200', ( await saveSettings() ) === 200 );
		const stored = wpEval( '$s = get_option( "bricks_global_settings" ); echo isset( $s["publicTemplates"] ) ? $s["publicTemplates"] : "absent";' );
		t.check( 'toggle stores the literal on (their checkbox shape)', stored === 'on', stored );
		await page.evaluate( () => document.querySelector( '[data-sset="publicTemplates"]' ).click() );
		t.check( 'untoggle save 200', ( await saveSettings() ) === 200 );
		const cleared = wpEval( '$s = get_option( "bricks_global_settings" ); echo isset( $s["publicTemplates"] ) ? "present" : "absent";' );
		t.check( 'off means the key is absent, siblings intact', cleared === 'absent', cleared );

		/* ===== CSS-files purger: only in external-files mode ===== */
		const cachePurge = () => page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/cache/purge', {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				body: JSON.stringify( { provider: 'bricks-css' } ),
			} );
			return { status: r.status, data: await r.json().catch( () => null ) };
		} );
		const inlineTry = await cachePurge();
		t.check( 'purger absent while CSS is inline', inlineTry.status !== 200 || ! ( inlineTry.data.purged || [] ).length, JSON.stringify( inlineTry.data ) );
		wpEval( '$s = get_option( "bricks_global_settings" ); $s["cssLoading"] = "file"; update_option( "bricks_global_settings", $s ); echo "armed";' );
		const fileTry = await cachePurge();
		t.check( 'file mode regenerates through their Assets_Files', fileTry.status === 200 && ( fileTry.data.purged || [] ).includes( 'Bricks CSS files' ), JSON.stringify( fileTry.data ) );
		wpEval( '$s = get_option( "bricks_global_settings" ); unset( $s["cssLoading"] ); update_option( "bricks_global_settings", $s ); echo "restored";' );

		/* ===== Front-end bar: templates used on this page ===== */
		prevFrontBar = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/me/appearance', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			const d = await r.json();
			return d.frontBar === true;
		} );
		await page.evaluate( async () => {
			await fetch( window.MINN.restUrl + 'minn-admin/v1/me/appearance', {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				body: JSON.stringify( { frontBar: true } ),
			} );
		} );
		await page.evaluate( () => { try { sessionStorage.removeItem( 'minn-bar-corner' ); } catch ( e ) {} } );
		await page.goto( BASE + '/', { waitUntil: 'domcontentloaded', timeout: 60000 } );
		await page.waitForSelector( '#minn-cornerbar', { timeout: 20000 } );
		await page.hover( '.minn-bar-markbtn' );
		await page.waitForTimeout( 400 );
		const homeEdit = await page.evaluate( () => {
			const a = document.querySelector( 'a.minn-bar-edit' );
			const cmds = ( window.MINN_BAR || {} ).commands || [];
			return {
				text: a ? a.textContent.trim() : '',
				href: a ? a.getAttribute( 'href' ) : '',
				headerCmd: cmds.some( ( c ) => /Edit header/.test( c.title ) && /bricks=run/.test( String( c.value || '' ) ) ),
			};
		} );
		t.check( 'home front bar offers Edit header for the Site Header template',
			/header/i.test( homeEdit.text ) && /bricks=run/.test( homeEdit.href ) && homeEdit.headerCmd,
			JSON.stringify( homeEdit ) );

		const postLink = wpEval( '$p = get_posts( array( "numberposts" => 1, "post_status" => "publish" ) ); echo $p ? get_permalink( $p[0] ) : "";' );
		t.check( 'lab has a published post to open', !! postLink, postLink );
		if ( postLink ) {
			await page.evaluate( () => { try { sessionStorage.removeItem( 'minn-bar-corner' ); } catch ( e ) {} } );
			await page.goto( postLink, { waitUntil: 'domcontentloaded', timeout: 60000 } );
			await page.waitForSelector( '#minn-cornerbar', { timeout: 20000 } );
			await page.hover( '.minn-bar-markbtn' );
			await page.waitForTimeout( 400 );
			const postEdit = await page.evaluate( () => {
				const a = document.querySelector( 'a.minn-bar-edit' );
				const more = document.querySelector( '.minn-bar-edit-more' );
				return {
					text: a ? a.textContent.trim() : '',
					href: a ? a.getAttribute( 'href' ) : '',
					hasMore: !! more,
				};
			} );
			t.check( 'a post keeps its own Edit and lists templates under More',
				!! postEdit.href && postEdit.hasMore, JSON.stringify( postEdit ) );
			await page.click( '.minn-bar-edit-more' );
			await page.waitForSelector( '#minn-bar-menu-edit:not([hidden])', { timeout: 5000 } );
			const menu = await page.evaluate( () => document.getElementById( 'minn-bar-menu-edit' ).textContent );
			t.check( 'the Edit menu names the Site Header template',
				/Site Header/.test( menu ) && /Edit header/.test( menu ) && ! /Hello world/i.test( menu ), menu );
		}

		/* Product pages: Bricks is usually NOT set to edit the product type,
		   so the canvas is a Single Product template wrapping the product.
		   Primary Edit stays Edit Product (this product, in Minn). The
		   wrapping template is Edit content under More, same as a header
		   wrapping a Gutenberg post. */
		const wooOn = wpEval( 'echo class_exists( "WooCommerce" ) ? "yes" : "no";' );
		t.check( 'WooCommerce is available for the product-page bar check', wooOn === 'yes' || wooOn === 'no', wooOn );
		if ( wooOn === 'yes' ) {
			const product = await page.evaluate( async () => {
				const bar = window.MINN_BAR || {};
				const r = await fetch( bar.rest + 'wc/v3/products', {
					method: 'POST', credentials: 'same-origin',
					headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': bar.nonce },
					body: JSON.stringify( {
						name: 'Suite Bricks Product ' + Date.now(),
						type: 'simple',
						regular_price: '11',
						status: 'publish',
					} ),
				} );
				const d = await r.json().catch( () => null );
				return { status: r.status, id: d && d.id, permalink: d && d.permalink };
			} );
			t.check( 'created a product to open on the front end',
				( product.status === 201 || product.status === 200 ) && !! product.id && !! product.permalink,
				JSON.stringify( product ) );
			const tplId = wpEval(
				'$id = wp_insert_post( array( "post_type" => "bricks_template", "post_title" => "Suite Single Product", "post_status" => "publish" ) );'
				+ ' update_post_meta( $id, "_bricks_template_type", "wc_product" );'
				+ ' update_post_meta( $id, "_bricks_editor_mode", "bricks" );'
				+ ' update_post_meta( $id, "_bricks_template_settings", array( "templateConditions" => array( array( "id" => "s1", "main" => "postType", "postType" => array( "product" ) ) ) ) );'
				+ ' echo $id;'
			);
			t.check( 'created a WooCommerce single-product template', /^\d+$/.test( tplId ) && Number( tplId ) > 0, tplId );
			if ( /^\d+$/.test( tplId ) ) probeIds.push( Number( tplId ) );
			if ( product.id && product.permalink ) {
				await page.evaluate( () => { try { sessionStorage.removeItem( 'minn-bar-corner' ); } catch ( e ) {} } );
				await page.goto( product.permalink, { waitUntil: 'domcontentloaded', timeout: 60000 } );
				await page.waitForSelector( '#minn-cornerbar', { timeout: 20000 } );
				await page.hover( '.minn-bar-markbtn' );
				await page.waitForTimeout( 400 );
				const productEdit = await page.evaluate( () => {
					const a = document.querySelector( 'a.minn-bar-edit' );
					const more = document.querySelector( '.minn-bar-edit-more' );
					const cmds = ( window.MINN_BAR || {} ).commands || [];
					return {
						text: a ? a.textContent.trim() : '',
						href: a ? a.getAttribute( 'href' ) : '',
						hasMore: !! more,
						contentCmd: cmds.some( ( c ) => /Edit content/.test( c.title ) && /bricks=run/.test( String( c.value || '' ) ) ),
					};
				} );
				t.check( 'a product page Edit opens the product in Minn, not a Bricks template',
					/Edit Product/.test( productEdit.text )
					&& /\/minn-admin\/products\//.test( productEdit.href )
					&& ! /bricks=run/.test( productEdit.href ),
					JSON.stringify( productEdit ) );
				t.check( 'the wrapping Single Product template stays under More',
					productEdit.hasMore && productEdit.contentCmd,
					JSON.stringify( productEdit ) );
				if ( productEdit.hasMore ) {
					await page.click( '.minn-bar-edit-more' );
					await page.waitForSelector( '#minn-bar-menu-edit:not([hidden])', { timeout: 5000 } );
					const pmenu = await page.evaluate( () => document.getElementById( 'minn-bar-menu-edit' ).textContent );
					t.check( 'the Edit menu names the Single Product template and the header',
						/Edit content/.test( pmenu ) && /Suite Single Product/.test( pmenu ) && /Site Header/.test( pmenu ), pmenu );
				}
				await page.evaluate( async ( id ) => {
					const bar = window.MINN_BAR || {};
					await fetch( bar.rest + 'wc/v3/products/' + id + '?force=true', {
						method: 'DELETE', credentials: 'same-origin',
						headers: { 'X-WP-Nonce': bar.nonce },
					} );
				}, product.id ).catch( () => null );
			}
		}

		await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded', timeout: 60000 } );
		await page.waitForFunction( () => window.MINN && Array.isArray( window.MINN.surfaces ), null, { timeout: 20000 } );

		/* ===== Visibility: detector + toggle round-trip (endpoint level) ===== */
		wpEval( '$s = get_option( "bricks_global_settings" ); if ( ! is_array( $s ) ) { $s = array(); } $s["maintenanceMode"] = "comingSoon"; update_option( "bricks_global_settings", $s ); echo "armed";' );
		let vis = await rest( 'GET', 'minn-admin/v1/visibility' );
		let row = ( vis.data.providers || [] ).find( ( p ) => p.id === 'bricks' );
		t.check( 'armed coming soon is detected with a working toggle', !! row && row.kind === 'coming-soon' && row.can === true, JSON.stringify( row ) );

		vis = await rest( 'POST', 'minn-admin/v1/visibility/toggle', { id: 'bricks', on: false } );
		const off = wpEval( '$s = get_option( "bricks_global_settings" ); echo isset( $s["maintenanceMode"] ) ? "present" : "absent";' );
		t.check( 'turn off removes the key from bricks_global_settings', off === 'absent' && ! ( vis.data.providers || [] ).some( ( p ) => p.id === 'bricks' ), off );

		vis = await rest( 'POST', 'minn-admin/v1/visibility/toggle', { id: 'bricks', on: true } );
		const restored = wpEval( '$s = get_option( "bricks_global_settings" ); echo isset( $s["maintenanceMode"] ) ? $s["maintenanceMode"] : "absent";' );
		t.check( 'undo restores the exact mode that was on', restored === 'comingSoon', restored );

		/* ===== Front end really gates while maintenance is armed ===== */
		wpEval( '$s = get_option( "bricks_global_settings" ); $s["maintenanceMode"] = "maintenance"; update_option( "bricks_global_settings", $s ); echo "armed";' );
		const front = await page.evaluate( async ( base ) => {
			const r = await fetch( base + '/?minnprobe=' + Math.random(), { credentials: 'omit' } );
			return r.status;
		}, BASE );
		t.check( 'anonymous front end answers 503 under maintenance', front === 503, String( front ) );
	} finally {
		// Resting state: no maintenance key, inline CSS, no restore memory,
		// no probe posts or suite tags. Front-bar opt-in is restored too.
		if ( prevFrontBar !== null ) {
			try {
				await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded', timeout: 60000 } );
				await page.waitForFunction( () => window.MINN, null, { timeout: 20000 } );
				await page.evaluate( async ( on ) => {
					await fetch( window.MINN.restUrl + 'minn-admin/v1/me/appearance', {
						method: 'POST', credentials: 'same-origin',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
						body: JSON.stringify( { frontBar: on } ),
					} );
				}, prevFrontBar );
			} catch ( e ) { /* appearance restore is best-effort */ }
		}
		wpEval( '$s = get_option( "bricks_global_settings" ); if ( is_array( $s ) ) { unset( $s["maintenanceMode"], $s["cssLoading"] ); update_option( "bricks_global_settings", $s ); } delete_option( "minn_admin_vis_restore" ); foreach ( get_terms( array( "taxonomy" => "template_tag", "hide_empty" => false, "search" => "suite" ) ) as $t ) { wp_delete_term( $t->term_id, "template_tag" ); } echo "clean";' );
		for ( const id of probeIds ) {
			try { wpEval( 'wp_delete_post( ' + id + ', true ); echo "gone";' ); } catch ( e ) { /* already gone */ }
		}
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
