/**
 * Novamira adapter (adapters/novamira.php): the Agent Access surface.
 * Connections (OAuth apps, client ids, Novamira application passwords) with
 * Revoke, a status card with the AI-abilities switch, and the Abilities
 * settings view writing Novamira's own rules option.
 *
 * Fixture: Novamira active on minnadmin with abilities OFF at rest. The suite
 * creates and revokes one application password, flips one ability rule and
 * restores it, and turns abilities on then back off.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'novamira' );
	const { browser, page, errors } = await launch();
	await login( page );

	const rest = ( path, opts = {} ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, {
			method: a.method || 'GET', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			...( a.body ? { body: JSON.stringify( a.body ) } : {} ),
		} );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, { path, method: opts.method, body: opts.body } );

	let pwUuid = '';
	let enabledBefore = null;
	try {
		await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 60000 } );
		const surf = await page.evaluate( () => ( window.MINN.surfaces || [] ).find( ( s ) => s.id === 'novamira' ) || null );
		t.check( 'Agent Access surface in the boot payload with its plugin key', !! surf && surf.plugin === 'novamira' && surf.settings && surf.settings.tabs.length > 1, JSON.stringify( surf && { plugin: surf.plugin, tabs: surf.settings && surf.settings.tabs.length } ) );
		if ( ! surf ) throw new Error( 'Novamira surface missing; is the plugin active?' );

		/* ===== Status + enable switch ===== */
		let st = await rest( 'minn-admin/v1/novamira/status' );
		enabledBefore = st.body.rows[ 0 ].value === 'On';
		t.check( 'status card names abilities state, connections and the endpoint', st.status === 200 && st.body.rows.length >= 3 && /\/wp-json\/mcp\/novamira$/.test( st.body.command.text ), JSON.stringify( st.body.rows.map( ( r ) => r.label + ': ' + r.value ) ) );
		const on = await rest( 'minn-admin/v1/novamira/enabled/on', { method: 'POST' } );
		t.check( 'abilities can be switched on through the plugin\'s own function', on.status === 200 && on.body.enabled === true, JSON.stringify( on.body && on.body.enabled ) );

		/* ===== Connections ===== */
		// A Novamira-named application password is a connection row.
		const made = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/users/me/application-passwords', {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				body: JSON.stringify( { name: 'Novamira (Minn suite)' } ),
			} );
			return r.json();
		} );
		pwUuid = made && made.uuid ? made.uuid : '';
		t.check( 'fixture application password created', !! pwUuid, JSON.stringify( made && made.code ) );
		let c = await rest( 'minn-admin/v1/novamira/connections' );
		const row = ( c.body.items || [] ).find( ( i ) => i.id === 'pw:' + pwUuid );
		t.check( 'connections list the password without exposing it', !! row && row.kind === 'password' && ! JSON.stringify( row ).includes( made.password ), JSON.stringify( row ) );
		const revoke = await rest( 'minn-admin/v1/novamira/connections/pw:' + pwUuid + '/revoke', { method: 'POST' } );
		c = await rest( 'minn-admin/v1/novamira/connections' );
		t.check( 'revoke deletes the application password', revoke.status === 200 && ! ( c.body.items || [] ).some( ( i ) => i.id === 'pw:' + pwUuid ), JSON.stringify( revoke.body ) );
		if ( revoke.status === 200 ) pwUuid = '';
		const bad = await rest( 'minn-admin/v1/novamira/connections/oauth:nope/revoke', { method: 'POST' } );
		t.check( 'revoking an unknown connection is a clean no-op, not an error', bad.status === 200 || bad.status === 404, `status ${ bad.status }` );

		/* ===== Abilities settings ===== */
		let tab = await rest( 'minn-admin/v1/novamira/abilities/novamira' );
		const key = 'ability:novamira/execute-php';
		t.check( 'Novamira tab lists its abilities as toggles with descriptions', tab.status === 200 && tab.body.groups[ 0 ].fields.some( ( f ) => f.key === key && f.type === 'toggle' && f.help ), JSON.stringify( tab.body && tab.body.groups[ 0 ].fields.length ) );
		const wasOn = tab.body.values[ key ];
		const saved = await rest( 'minn-admin/v1/novamira/abilities/novamira', { method: 'POST', body: { values: { [ key ]: false } } } );
		t.check( 'switching an ability off writes Novamira\'s rule', saved.status === 200 && saved.body.values[ key ] === false, JSON.stringify( saved.body && saved.body.values[ key ] ) );
		st = await rest( 'minn-admin/v1/novamira/status' );
		t.check( 'status counts the switched-off ability', /1 switched off/.test( st.body.rows[ 2 ].hint ), st.body.rows[ 2 ].hint );
		const back = await rest( 'minn-admin/v1/novamira/abilities/novamira', { method: 'POST', body: { values: { [ key ]: wasOn } } } );
		t.check( 'switching it back clears the rule', back.status === 200 && back.body.values[ key ] === wasOn );

		/* ===== UI ===== */
		await page.goto( BASE + '/minn-admin/novamira', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-sstat', { timeout: 60000 } );
		const ui = await page.evaluate( () => ( {
			title: document.querySelector( '#minn-title' ).textContent.trim(),
			views: [ ...document.querySelectorAll( '[data-sview]' ) ].map( ( b ) => b.textContent.trim() ),
			endpoint: ( document.querySelector( '.minn-sstat code, .minn-sstat-cmd, .minn-sstat input' ) || {} ).textContent || '',
		} ) );
		t.check( 'surface renders the status card and both views', ui.title === 'Agent Access' && ui.views.includes( 'Connections' ) && ui.views.includes( 'Abilities' ), JSON.stringify( ui ) );
		await page.click( '[data-sview="settings"]' );
		await page.waitForSelector( '.minn-toggle-row', { timeout: 60000 } );
		const rows = await page.evaluate( () => document.querySelectorAll( '.minn-toggle-row' ).length );
		t.check( 'Abilities view renders one switch per ability', rows > 5, String( rows ) );

		// The card doorway: Extensions → Novamira card links to the surface.
		await page.goto( BASE + '/minn-admin/extensions', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-plugin', { timeout: 60000 } );
		await page.waitForSelector( '.minn-plugin[data-plugin="novamira/novamira"] [data-mdoor]', { timeout: 60000 } );
		const chips = await page.evaluate( () => [ ...document.querySelectorAll( '.minn-plugin[data-plugin="novamira/novamira"] [data-mdoor]' ) ].map( ( b ) => b.textContent.trim() ) );
		t.check( 'plugin card carries the Agent Access chip', chips.includes( 'Agent Access' ), JSON.stringify( chips ) );
	} catch ( e ) {
		t.check( 'suite ran without throwing', false, e.message );
	} finally {
		if ( pwUuid ) await rest( 'minn-admin/v1/novamira/connections/pw:' + pwUuid + '/revoke', { method: 'POST' } ).catch( () => {} );
		await rest( 'minn-admin/v1/novamira/abilities/novamira', { method: 'POST', body: { values: { 'ability:novamira/execute-php': true } } } ).catch( () => {} );
		if ( enabledBefore === false ) await rest( 'minn-admin/v1/novamira/enabled/off', { method: 'POST' } ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
