/**
 * ACF field group builder (/field-groups/{key}): the schema canvas. Rows
 * open the builder via collection.open, fields build up dynamically (label
 * auto-derives the name until touched, per-type settings render inline),
 * the whole group saves as one unit, reorders and deletions persist, and —
 * the end-to-end proof — the built group renders live in the editor's
 * Custom fields panel. Cleanup force-deletes through the schema route.
 */
const { launch, login, createPost, deletePost, openEditor, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'field-group-builder' );
	const { browser, page, errors } = await launch();
	await login( page );
	await page.goto( BASE + '/minn-admin/acf-field-groups', { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-surface-add', { timeout: 20000 } );

	const api = ( method, route, body ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.route, {
			method: a.method, credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: a.body ? JSON.stringify( a.body ) : undefined,
		} );
		return { status: r.status, data: await r.json().catch( () => null ) };
	}, { method, route, body } );
	const sweep = async () => {
		const list = ( await api( 'GET', 'minn-admin/v1/acf/schema/groups?_cb=' + Math.random() ) ).data;
		for ( const g of ( list.items || [] ) ) {
			if ( /^Builder Suite/.test( g.title ) ) await api( 'DELETE', 'minn-admin/v1/acf/schema/groups/' + g.id + '?force=1' );
		}
	};
	let postId = 0;
	let gkey = '';
	// Save-wait on the POST response, never the toast: a prior save's toast
	// lingers long enough to satisfy a text wait while the post-save adopt +
	// re-render is still racing the next interactions (the rule-51 class).
	const saveGroup = async () => {
		const wait = page.waitForResponse( ( res ) =>
			res.request().method() === 'POST' && /acf\/schema\/groups\/[^/]+\/full/.test( res.url() ), { timeout: 20000 } );
		await page.click( '#minn-fgb-save' );
		const res = await wait;
		await page.waitForTimeout( 600 ); // adopt + re-render settle
		return res.status();
	};

	try {
		await sweep();
		// Create a group, open its builder from the row.
		await page.click( '#minn-surface-add' );
		await page.waitForSelector( '[data-createfield="title"]', { timeout: 8000 } );
		await page.type( '[data-createfield="title"]', 'Builder Suite Group' );
		await page.click( '[data-createfield="location"] .minn-ac-input' );
		await page.waitForSelector( '[data-createfield="location"] .minn-ac-item[data-acv="post_type:post"]', { timeout: 5000 } );
		await page.click( '[data-createfield="location"] .minn-ac-item[data-acv="post_type:post"]' );
		await page.click( '#minn-surface-create' );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-table-row' ) ).some( ( r ) => r.textContent.includes( 'Builder Suite Group' ) ),
		null, { timeout: 15000 } );
		await page.evaluate( () => {
			Array.from( document.querySelectorAll( '.minn-table-row' ) ).find( ( r ) => r.textContent.includes( 'Builder Suite Group' ) ).click();
		} );
		await page.waitForSelector( '#minn-fgb-add', { timeout: 15000 } );
		t.check( 'row click opens the builder page', ( await page.evaluate( () => location.pathname ) ).includes( '/field-groups/' ) );

		// Build three fields.
		await page.click( '#minn-fgb-add' );
		await page.waitForSelector( '[data-fgb="0:label"]', { timeout: 5000 } );
		await page.type( '[data-fgb="0:label"]', 'Suite Headline' );
		t.check( 'name auto-derives from the label', await page.$eval( '[data-fgb="0:name"]', ( e ) => e.value ) === 'suite_headline' );
		await page.type( '[data-fgb="0:placeholder"]', 'Type it' );
		await page.click( '[data-fgbreq="0"]' );

		await page.click( '#minn-fgb-add' );
		await page.waitForSelector( '[data-fgb="1:label"]', { timeout: 5000 } );
		await page.type( '[data-fgb="1:label"]', 'Suite Mood' );
		await page.selectOption( '[data-fgb="1:type"]', 'select' );
		await page.waitForSelector( '[data-fgb="1:choices"]', { timeout: 5000 } );
		await page.type( '[data-fgb="1:choices"]', 'calm : Calm\nbold : Bold' );

		await page.click( '#minn-fgb-add' );
		await page.waitForSelector( '[data-fgb="2:label"]', { timeout: 5000 } );
		await page.type( '[data-fgb="2:label"]', 'Suite Flag' );
		await page.selectOption( '[data-fgb="2:type"]', 'true_false' );

		t.check( 'save round-trips', ( await saveGroup() ) === 200 );

		const list = ( await api( 'GET', 'minn-admin/v1/acf/schema/groups?_cb=' + Math.random() ) ).data;
		gkey = ( list.items.find( ( g ) => g.title === 'Builder Suite Group' ) || {} ).id;
		let full = ( await api( 'GET', 'minn-admin/v1/acf/schema/groups/' + gkey + '/full' ) ).data;
		t.check( 'three fields saved with their settings', full.fields.length === 3
			&& full.fields[ 0 ].name === 'suite_headline' && full.fields[ 0 ].required && full.fields[ 0 ].placeholder === 'Type it'
			&& full.fields[ 1 ].type === 'select' && /bold : Bold/.test( full.fields[ 1 ].choices )
			&& full.fields[ 2 ].type === 'true_false',
			JSON.stringify( full.fields.map( ( f ) => f.name + ':' + f.type ) ) );

		// The end-to-end proof: the built group renders in the editor panel.
		postId = await createPost( page, { title: 'FGB e2e', content: '<p>x</p>' } );
		await openEditor( page, postId );
		await page.waitForSelector( '[data-side-door="panel:acf"]', { timeout: 15000 } );
		await page.click( '[data-side-door="panel:acf"]' );
		await page.waitForSelector( '[data-pf$=":suite_headline"]', { timeout: 15000 } );
		t.check( 'built group renders in the Custom fields panel', true );
		t.check( 'built select carries its choices', await page.$eval( '[data-pf$=":suite_mood"]', ( e ) =>
			Array.from( e.options || [] ).some( ( o ) => o.value === 'bold' ) ) );

		// Back in the builder: edit a label, reorder, delete the flag, save.
		await page.goto( BASE + '/minn-admin/field-groups/' + gkey, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-fgb-row', { timeout: 20000 } );
		await page.click( '[data-fgbtoggle="0"]' );
		await page.waitForSelector( '[data-fgb="0:label"]', { timeout: 5000 } );
		await page.evaluate( () => { document.querySelector( '[data-fgb="0:label"]' ).value = ''; } );
		await page.type( '[data-fgb="0:label"]', 'Suite Headline Renamed' );
		t.check( 'existing field name input is locked', await page.$eval( '[data-fgb="0:name"]', ( e ) => e.disabled ) );
		await page.click( '[data-fgbmv="1:1"]' ); // mood below flag
		await page.waitForTimeout( 300 );
		page.once( 'dialog', ( d ) => d.accept() );
		await page.evaluate( () => {
			// remove the (now) middle row: Suite Flag
			const rows = Array.from( document.querySelectorAll( '.minn-fgb-row' ) );
			const flag = rows.find( ( r ) => r.textContent.includes( 'Suite Flag' ) );
			flag.querySelector( '[data-fgbdel]' ).click();
		} );
		// themed confirm
		await page.waitForSelector( '.minn-confirm-overlay button.minn-btn-primary, .minn-confirm-overlay .danger', { timeout: 5000 } ).catch( () => {} );
		await page.evaluate( () => {
			const ov = document.querySelector( '.minn-confirm-overlay' );
			if ( ov ) {
				const btn = Array.from( ov.querySelectorAll( 'button' ) ).find( ( b ) => /Remove/.test( b.textContent ) );
				if ( btn ) btn.click();
			}
		} );
		await page.waitForFunction( () =>
			! Array.from( document.querySelectorAll( '.minn-fgb-row' ) ).some( ( r ) => r.textContent.includes( 'Suite Flag' ) ),
		null, { timeout: 5000 } );
		t.check( 'save round-trips', ( await saveGroup() ) === 200 );
		full = ( await api( 'GET', 'minn-admin/v1/acf/schema/groups/' + gkey + '/full?_cb=' + Math.random() ) ).data;
		t.check( 'rename + reorder + delete saved as one unit',
			full.fields.length === 2 && full.fields[ 0 ].label === 'Suite Headline Renamed'
			&& full.fields[ 1 ].name === 'suite_mood' && full.fields[ 0 ].name === 'suite_headline',
			JSON.stringify( full.fields.map( ( f ) => f.name + ':' + f.label ) ) );

		/* ===== Location rules: switch the value, add an AND rule and an OR
		 * set, save, verify the exact structure — and the invalid-value
		 * refusal writes nothing. ===== */
		await page.waitForSelector( '.minn-fgb-loc', { timeout: 10000 } );
		await page.selectOption( '[data-lgv="0:0"]', 'page' );
		await page.click( '[data-lgand="0"]' );
		await page.waitForSelector( '[data-lgp="0:1"]', { timeout: 5000 } );
		await page.selectOption( '[data-lgp="0:1"]', 'current_user_role' );
		await page.waitForTimeout( 300 );
		await page.selectOption( '[data-lgo="0:1"]', '!=' );
		await page.click( '#minn-fgb-locadd' );
		await page.waitForSelector( '[data-lgp="1:0"]', { timeout: 5000 } );
		t.check( 'save round-trips', ( await saveGroup() ) === 200 );
		full = ( await api( 'GET', 'minn-admin/v1/acf/schema/groups/' + gkey + '/full?_cb=' + Math.random() ) ).data;
		t.check( 'location rules save as OR sets of AND rows',
			full.group.location.length === 2
			&& full.group.location[ 0 ][ 0 ].value === 'page'
			&& full.group.location[ 0 ][ 1 ].param === 'current_user_role'
			&& full.group.location[ 0 ][ 1 ].operator === '!=',
			JSON.stringify( full.group.location ) );
		const badLoc = await api( 'POST', 'minn-admin/v1/acf/schema/groups/' + gkey + '/full', {
			fields: full.fields.map( ( f ) => ( { key: f.key } ) ),
			location: [ [ { param: 'post_type', operator: '==', value: 'no_such_type' } ] ],
		} );
		t.check( 'invalid location value refuses', badLoc.status === 400, JSON.stringify( badLoc.data ) );
		const after = ( await api( 'GET', 'minn-admin/v1/acf/schema/groups/' + gkey + '/full?_cb=' + Math.random() ) ).data;
		t.check( 'refused save wrote nothing', JSON.stringify( after.group.location ) === JSON.stringify( full.group.location ) );

		/* ===== Drag to reorder: real mouse drag of the grip, drop above the
		 * first row, save, verify the persisted order flipped. ===== */
		await page.waitForSelector( '.minn-fgb-row[data-fi="1"] .minn-fgb-grip', { timeout: 5000 } );
		const gripBox = await ( await page.$( '.minn-fgb-row[data-fi="1"] .minn-fgb-grip' ) ).boundingBox();
		const firstBox = await ( await page.$( '.minn-fgb-row[data-fi="0"]' ) ).boundingBox();
		await page.mouse.move( gripBox.x + gripBox.width / 2, gripBox.y + gripBox.height / 2 );
		await page.mouse.down();
		await page.mouse.move( firstBox.x + 80, firstBox.y + firstBox.height * 0.2, { steps: 8 } );
		await page.mouse.move( firstBox.x + 80, firstBox.y + firstBox.height * 0.2 + 1 );
		await page.mouse.up();
		await page.waitForFunction( () => {
			const names = document.querySelectorAll( '.minn-fgb-rows .minn-fgb-name' );
			return names.length === 2 && names[ 0 ].textContent === 'suite_mood';
		}, null, { timeout: 5000 } );
		t.check( 'grip drag reorders rows in place', true );
		t.check( 'save round-trips', ( await saveGroup() ) === 200 );
		full = ( await api( 'GET', 'minn-admin/v1/acf/schema/groups/' + gkey + '/full?_cb=' + Math.random() ) ).data;
		t.check( 'dragged order persists', full.fields[ 0 ].name === 'suite_mood' && full.fields[ 1 ].name === 'suite_headline',
			JSON.stringify( full.fields.map( ( f ) => f.name ) ) );

		/* ===== Duplicate: the copy is a new field with a free name, settings
		 * carried (choices survive), created on save with its own key. ===== */
		await page.click( '.minn-fgb-row[data-fi="0"] [data-fgbdup]' );
		await page.waitForSelector( '[data-fgb="1:label"]', { timeout: 5000 } );
		t.check( 'duplicate inserts an open copy below', await page.$eval( '[data-fgb="1:name"]', ( e ) => e.value ) === 'suite_mood_copy' );
		t.check( 'save round-trips', ( await saveGroup() ) === 200 );
		full = ( await api( 'GET', 'minn-admin/v1/acf/schema/groups/' + gkey + '/full?_cb=' + Math.random() ) ).data;
		const copyRow = full.fields.find( ( f ) => f.name === 'suite_mood_copy' );
		t.check( 'copy persists with settings and its own key', full.fields.length === 3
			&& !! copyRow && copyRow.type === 'select' && /bold : Bold/.test( copyRow.choices )
			&& copyRow.key !== full.fields[ 0 ].key,
			JSON.stringify( full.fields.map( ( f ) => f.name + ':' + f.type ) ) );
	} finally {
		if ( postId ) await deletePost( page, postId ).catch( () => {} );
		await sweep().catch( () => {} );
	}

	await t.done( browser, errors );
} )();
