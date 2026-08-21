/**
 * ACPT backend of the field group builder (/field-groups/acpt/{id}): rows on
 * the shared surface's ACPT view open the schema canvas, boxes ride as
 * container rows above their fields, new boxes and fields build up and save
 * through ACPT's own SaveMetaGroupCommand, reorders keep field ids, and the
 * chrome ACPT has no concept for (active switch, export) stays off the page.
 * The standing `minn-acpt-suite` group is the fixture; everything this suite
 * creates lives in a probe box it removes again.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'acpt-field-group-builder' );
	const { browser, page, errors } = await launch();
	await login( page );

	const api = ( method, route, body ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.route, {
			method: a.method, credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: a.body ? JSON.stringify( a.body ) : undefined,
		} );
		return { status: r.status, data: await r.json().catch( () => null ) };
	}, { method, route, body } );
	// Save-wait on the POST response, never the toast (the rule-51 class).
	const saveGroup = async () => {
		const wait = page.waitForResponse( ( res ) =>
			res.request().method() === 'POST' && /acpt\/schema\/groups\/[^/]+\/full/.test( res.url() ), { timeout: 20000 } );
		await page.click( '#minn-fgb-save' );
		const res = await wait;
		await page.waitForTimeout( 600 ); // adopt + re-render settle
		return res.status();
	};
	const comboPick = async ( wrapSel, value ) => {
		await page.click( wrapSel + ' .minn-ac-input' );
		await page.waitForSelector( `${ wrapSel } .minn-ac-item[data-acv="${ value }"]`, { timeout: 5000 } );
		await page.click( `${ wrapSel } .minn-ac-item[data-acv="${ value }"]` );
		await page.waitForTimeout( 250 );
	};
	const fullOf = async ( id ) => ( await api( 'GET', 'minn-admin/v1/acpt/schema/groups/' + id + '/full?_cb=' + Math.random() ) ).data;
	// Drop the probe box over REST so a crashed earlier run never leaves it
	// behind: the save body mirrors the client's rowOf.
	const rowOf = ( f ) => {
		const row = f.key ? { key: f.key } : { type: f.type, name: f.name };
		[ 'label', 'instructions', 'required', 'default_value', 'placeholder', 'choices', 'min', 'max', 'step', 'rows', 'ui_on_text', 'ui_off_text', 'button_label' ].forEach( ( k ) => { row[ k ] = f[ k ] == null ? '' : f[ k ]; } );
		if ( ( f.type === 'repeater' || f.type === 'box' ) && f.editable ) row.sub_fields = ( f.sub_fields || [] ).map( rowOf );
		return row;
	};
	const sweep = async ( id ) => {
		const full = await fullOf( id );
		if ( ! full.fields.some( ( b ) => b.name === 'probe_box' ) ) return;
		await api( 'POST', 'minn-admin/v1/acpt/schema/groups/' + id + '/full', {
			title: full.group.title,
			location: full.group.location,
			fields: full.fields.filter( ( b ) => b.name !== 'probe_box' ).map( rowOf ),
		} );
	};

	let gid = '';
	try {
		await page.goto( BASE + '/minn-admin/field-groups', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-sview]', { timeout: 20000 } );

		const list = ( await api( 'GET', 'minn-admin/v1/acpt/groups' ) ).data;
		const fixture = ( list.items || [] ).find( ( g ) => g.name === 'Minn ACPT suite' );
		t.check( 'standing ACPT fixture group listed', !! fixture, JSON.stringify( ( list.items || [] ).map( ( g ) => g.name ) ) );
		gid = fixture.id;
		await sweep( gid );

		// Switch to the ACPT view and open the fixture row.
		await page.evaluate( () => {
			Array.from( document.querySelectorAll( '[data-sview]' ) ).find( ( b ) => b.textContent.trim() === 'ACPT' ).click();
		} );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-table-row' ) ).some( ( r ) => r.textContent.includes( 'Minn ACPT suite' ) ),
		null, { timeout: 15000 } );
		await page.evaluate( () => {
			Array.from( document.querySelectorAll( '.minn-table-row' ) ).find( ( r ) => r.textContent.includes( 'Minn ACPT suite' ) ).click();
		} );
		await page.waitForSelector( '#minn-fgb-add', { timeout: 15000 } );
		t.check( 'row click opens the ACPT builder page', ( await page.evaluate( () => location.pathname ) ).includes( '/field-groups/acpt/' ) );

		// Chrome matches what ACPT can express.
		const chrome = await page.evaluate( () => ( {
			active: !! document.querySelector( '#minn-fgb-active' ),
			exportBtn: !! document.querySelector( '#minn-fgb-export' ),
			add: document.querySelector( '#minn-fgb-add' ).textContent.trim(),
			vendorLink: ( document.querySelector( '.minn-fgb-meta a' ) || { textContent: '' } ).textContent,
			sub: ( document.querySelector( '#minn-topbar-sub, .minn-topbar-sub' ) || { textContent: '' } ).textContent,
		} ) );
		t.check( 'no active switch (ACPT groups have no flag)', ! chrome.active );
		t.check( 'no export button on the ACPT backend', ! chrome.exportBtn );
		t.check( 'top-level add creates boxes', /Add box/.test( chrome.add ), chrome.add );
		t.check( 'escape hatch names ACPT', /Edit in ACPT/.test( chrome.vendorLink ), chrome.vendorLink );

		// The fixture box renders as a container row with its fields nested.
		await page.click( '[data-fgbtoggle="0"]' );
		await page.waitForSelector( '.minn-fgb-row[data-fi="0.0"]', { timeout: 5000 } );
		const fixtureRows = await page.evaluate( () =>
			Array.from( document.querySelectorAll( '.minn-fgb-row[data-fi^="0."] .minn-fgb-name' ) ).map( ( e ) => e.textContent ) );
		t.check( 'fixture box lists its fields nested', fixtureRows.includes( 'minn_acpt_suite_text' ) && fixtureRows.includes( 'minn_acpt_suite_choice' ), JSON.stringify( fixtureRows ) );

		// Location rules ride the editor on the post_type catalog.
		const loc = await page.evaluate( () => ( {
			rules: document.querySelectorAll( '.minn-fgb-loc-rule' ).length,
			param: ( document.querySelector( '[data-lgp] .minn-ac-input' ) || { value: '' } ).value,
		} ) );
		t.check( 'belongs chain renders as an editable location rule', loc.rules >= 1 && /Post type/i.test( loc.param ), JSON.stringify( loc ) );

		// Build: a probe box with a text field and a select.
		await page.click( '#minn-fgb-add' );
		const boxTok = await page.evaluate( () => document.querySelectorAll( '.minn-fgb > .minn-fgb-rows > .minn-fgb-row' ).length - 1 );
		await page.waitForSelector( `[data-fgb="${ boxTok }:label"]`, { timeout: 5000 } );
		await page.type( `[data-fgb="${ boxTok }:label"]`, 'Probe Box' );
		t.check( 'box name auto-derives from the label', await page.$eval( `[data-fgb="${ boxTok }:name"]`, ( e ) => e.value ) === 'probe_box' );

		await page.click( `[data-fgbsubadd="${ boxTok }"]` );
		await page.waitForSelector( `[data-fgb="${ boxTok }.0:label"]`, { timeout: 5000 } );
		await page.type( `[data-fgb="${ boxTok }.0:label"]`, 'Probe Title' );

		await page.click( `[data-fgbsubadd="${ boxTok }"]` );
		await page.waitForSelector( `[data-fgb="${ boxTok }.1:label"]`, { timeout: 5000 } );
		await page.type( `[data-fgb="${ boxTok }.1:label"]`, 'Probe Pick' );
		await comboPick( `[data-fgbtype="${ boxTok }.1"]`, 'select' );
		await page.waitForSelector( `[data-fgb="${ boxTok }.1:choices"]`, { timeout: 5000 } );
		await page.type( `[data-fgb="${ boxTok }.1:choices"]`, 'calm : Calm\nbold : Bold' );

		t.check( 'save round-trips', ( await saveGroup() ) === 200 );

		let full = await fullOf( gid );
		const probe = full.fields.find( ( b ) => b.name === 'probe_box' );
		t.check( 'probe box saved with both fields', !! probe && probe.sub_fields.length === 2
			&& probe.sub_fields[ 0 ].name === 'probe_title' && probe.sub_fields[ 1 ].type === 'select'
			&& /bold : Bold/.test( probe.sub_fields[ 1 ].choices ),
			JSON.stringify( ( probe || { sub_fields: [] } ).sub_fields.map( ( f ) => f.name + ':' + f.type ) ) );
		const pickKey = probe.sub_fields[ 1 ].key;

		// Reorder inside the probe box (select first) and save: ids hold.
		const probeIdx = full.fields.findIndex( ( b ) => b.name === 'probe_box' );
		await page.waitForSelector( `[data-fgbmv="${ probeIdx }.1:-1"]`, { timeout: 5000 } );
		await page.click( `[data-fgbmv="${ probeIdx }.1:-1"]` );
		await page.waitForTimeout( 300 );
		t.check( 'reorder save round-trips', ( await saveGroup() ) === 200 );
		full = await fullOf( gid );
		const probe2 = full.fields.find( ( b ) => b.name === 'probe_box' );
		t.check( 'order persisted with a stable field id', probe2.sub_fields[ 0 ].name === 'probe_pick' && probe2.sub_fields[ 0 ].key === pickKey,
			JSON.stringify( probe2.sub_fields.map( ( f ) => f.name ) ) );

		// The panel machinery sees the built schema: the values route lists
		// the probe fields for a post (the end-to-end proof that the builder
		// writes real ACPT schema, not a parallel copy).
		const panel = ( await api( 'GET', 'minn-admin/v1/acpt/fields?post_id=0&post_type=posts' ) ).data;
		const panelFields = ( panel.groups || [] ).flatMap( ( g ) => g.fields.map( ( f ) => f.label ) );
		t.check( 'built fields reach the editor panel schema', panelFields.includes( 'Probe Pick' ), JSON.stringify( panelFields ) );

		// Delete the probe box in the UI (confirm dialog) and save.
		await page.click( `[data-fgbdel="${ probeIdx }"]` );
		await page.waitForSelector( '.minn-confirm-overlay', { timeout: 5000 } );
		await page.evaluate( () => {
			const ov = document.querySelector( '.minn-confirm-overlay' );
			( ov.querySelector( '.danger' ) || ov.querySelector( 'button.minn-btn-primary' ) ).click();
		} );
		await page.waitForTimeout( 400 );
		t.check( 'delete save round-trips', ( await saveGroup() ) === 200 );
		full = await fullOf( gid );
		t.check( 'probe box removed, fixture box intact', ! full.fields.some( ( b ) => b.name === 'probe_box' )
			&& full.fields.some( ( b ) => b.name === 'minn_acpt_suite_box' ),
			JSON.stringify( full.fields.map( ( b ) => b.name ) ) );

		// Refusals stay refusals at the route level.
		const bad = await api( 'POST', 'minn-admin/v1/acpt/schema/groups/' + gid + '/full', {
			title: full.group.title, location: full.group.location,
			fields: full.fields.map( rowOf ).concat( [ { type: 'box', name: 'refuse_box', label: 'R', sub_fields: [ { type: 'select', name: 'no_choices', label: 'X', choices: '' } ] } ] ),
		} );
		t.check( 'choice field without choices refuses 400', bad.status === 400, JSON.stringify( bad.data ) );
	} finally {
		if ( gid ) await sweep( gid ).catch( () => {} );
	}

	await t.done( browser, errors );
} )();
