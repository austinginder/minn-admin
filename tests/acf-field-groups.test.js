/**
 * ACF Field Groups manager (adapters/acf-field-groups.php): the schema side
 * of the ACF story, driven through the surface UI end to end — create a
 * group, add fields (choices parsing included), see the new schema LIVE in
 * the editor's Custom fields panel, edit a field through the detail modal,
 * reorder, delete, and trash the group. Code-registered groups (the
 * group_minn_local mu-fixture) list read-only with the 'code' pill and
 * every mutation refused server-side.
 *
 * Fixtures: ACF free active; group_minn_test + group_minn_norest are DB
 * groups; group_minn_local is registered via acf_add_local_field_group in
 * minn-dev-fixtures. Cleanup force-deletes the suite group through the
 * DELETE route's test-only force param (never fills ACF's trash).
 */
const { launch, login, createPost, deletePost, openEditor, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'acf-field-groups' );
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

	const groupsRest = async () => ( await api( 'GET', 'minn-admin/v1/acf/schema/groups?_cb=' + Math.random() ) ).data;
	const sweep = async () => {
		const list = await groupsRest();
		for ( const g of ( list.items || [] ) ) {
			if ( /^Suite Group/.test( g.title ) ) {
				await api( 'DELETE', 'minn-admin/v1/acf/schema/groups/' + g.id + '?force=1' );
			}
		}
	};
	let postId = 0;

	try {
		await sweep(); // a crashed prior run must not desync tab/row expectations

		/* ===== List: DB groups + the code fixture with its pill ===== */
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-table-row' ) ).some( ( r ) => r.textContent.includes( 'Post details' ) ),
		null, { timeout: 15000 } );
		const localRow = await page.evaluate( () =>
			( Array.from( document.querySelectorAll( '.minn-table-row' ) ).find( ( r ) => r.textContent.includes( 'Local code group' ) ) || { textContent: '' } ).textContent );
		t.check( 'code-registered group lists with the code pill', /code/i.test( localRow ), localRow.slice( 0, 120 ) );
		t.check( 'location humanizes', /Post type: Pages/.test( localRow ), localRow.slice( 0, 120 ) );

		/* ===== Create a group ===== */
		await page.click( '#minn-surface-add' );
		await page.waitForSelector( '[data-createfield="title"]', { timeout: 8000 } );
		await page.type( '[data-createfield="title"]', 'Suite Group' );
		await page.click( '[data-createfield="location"] .minn-ac-input' );
		await page.waitForSelector( '[data-createfield="location"] .minn-ac-item[data-acv="post_type:post"]', { timeout: 5000 } );
		await page.click( '[data-createfield="location"] .minn-ac-item[data-acv="post_type:post"]' );
		await page.click( '#minn-surface-create' );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-table-row' ) ).some( ( r ) => r.textContent.includes( 'Suite Group' ) ),
		null, { timeout: 15000 } );
		const gkey = ( ( await groupsRest() ).items.find( ( g ) => g.title === 'Suite Group' ) || {} ).id;
		t.check( 'group created active on Posts', !! gkey, gkey );

		/* ===== Add fields through the nested route (the UI add path lives
		 * in the group builder now — tests/field-group-builder.test.js;
		 * group rows navigate there via collection.open). ===== */
		const addText = await api( 'POST', `minn-admin/v1/acf/schema/groups/${ gkey }/fields`, {
			label: 'Suite Headline', type: 'text',
		} );
		t.check( 'add field persisted', addText.status === 200 && addText.data.name === 'suite_headline', JSON.stringify( addText.data ) );

		const addSelect = await api( 'POST', `minn-admin/v1/acf/schema/groups/${ gkey }/fields`, {
			label: 'Suite Tone', type: 'select', choices: 'calm : Calm\nbold : Bold',
		} );
		t.check( 'select field with parsed choices', addSelect.status === 200 && addSelect.data.name === 'suite_tone', JSON.stringify( addSelect.data ) );

		/* ===== Fields view renders the group tab with its rows. More than
		 * six groups turn the tab strip into the combobox — handle both
		 * (the surface-tab suite rule). ===== */
		const pickGroupTab = async () => {
			await page.waitForSelector( `[data-stab="${ gkey }"], [data-stabcombo]`, { timeout: 15000 } );
			if ( await page.$( `[data-stab="${ gkey }"]` ) ) {
				await page.click( `[data-stab="${ gkey }"]` );
				return;
			}
			await page.click( '[data-stabcombo] .minn-ac-input' );
			await page.waitForSelector( `[data-stabcombo] .minn-ac-item[data-acv="${ gkey }"]`, { timeout: 10000 } );
			await page.click( `[data-stabcombo] .minn-ac-item[data-acv="${ gkey }"]` );
		};
		await page.click( '[data-sview="x0"]' );
		await pickGroupTab();
		t.check( 'new group gets its own Fields tab', true );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-table-row' ) ).some( ( r ) => r.textContent.includes( 'suite_headline' ) ),
		null, { timeout: 15000 } );
		t.check( 'field rows render on the group tab', true );

		/* ===== The killer check: new schema shows up in the editor panel ===== */
		postId = await createPost( page, { title: 'ACF schema e2e', content: '<p>x</p>' } );
		await openEditor( page, postId );
		await page.waitForSelector( '[data-side-door="panel:acf"]', { timeout: 15000 } );
		await page.click( '[data-side-door="panel:acf"]' );
		await page.waitForSelector( '[data-pf$=":suite_headline"]', { timeout: 15000 } );
		t.check( 'new group renders in the Custom fields panel', true );
		const tone = page.locator( '[data-pf$=":suite_tone"]' );
		let toneChoice = false;
		if ( await tone.evaluate( ( e ) => e.tagName === 'SELECT' ) ) {
			toneChoice = await tone.evaluate( ( e ) => Array.from( e.options ).some( ( o ) => o.value === 'bold' ) );
		} else {
			await tone.locator( '.minn-ac-input' ).click();
			const bold = tone.locator( '.minn-ac-item[data-acv="bold"]' );
			await bold.waitFor( { timeout: 5000 } );
			toneChoice = /Bold/.test( await bold.textContent() );
		}
		t.check( 'choice field renders its choices', toneChoice );

		/* ===== Edit a field through the detail modal ===== */
		await page.goto( BASE + '/minn-admin/acf-field-groups', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-sview="x0"]', { timeout: 20000 } );
		await page.click( '[data-sview="x0"]' );
		await pickGroupTab();
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-table-row' ) ).some( ( r ) => r.textContent.includes( 'suite_headline' ) ),
		null, { timeout: 15000 } );
		await page.evaluate( () => {
			Array.from( document.querySelectorAll( '.minn-table-row' ) )
				.find( ( r ) => r.textContent.includes( 'suite_headline' ) ).click();
		} );
		await page.waitForSelector( '[data-editfield="label"]', { timeout: 10000 } );
		await page.evaluate( () => { document.querySelector( '[data-editfield="label"]' ).value = ''; } );
		await page.type( '[data-editfield="label"]', 'Suite Headline Renamed' );
		await page.click( '[data-editfield="required"] .minn-ac-input' );
		await page.waitForSelector( '[data-editfield="required"] .minn-ac-item[data-acv="Yes"]', { timeout: 5000 } );
		await page.click( '[data-editfield="required"] .minn-ac-item[data-acv="Yes"]' );
		await page.click( '#minn-surface-save' );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-toast' ) ).some( ( x ) => /Saved|saved/.test( x.textContent ) ),
		null, { timeout: 15000 } );
		let fields = ( await api( 'GET', `minn-admin/v1/acf/schema/groups/${ gkey }/fields?_cb=` + Math.random() ) ).data;
		const headline = fields.items.find( ( f ) => f.name === 'suite_headline' );
		t.check( 'label + required edit persisted (name untouched)',
			!! headline && headline.label === 'Suite Headline Renamed' && headline.required === 'Yes', JSON.stringify( headline ) );

		/* ===== Reorder + delete ===== */
		const moved = await api( 'POST', `minn-admin/v1/acf/schema/fields/${ headline.id }/move`, { dir: 'down' } );
		fields = ( await api( 'GET', `minn-admin/v1/acf/schema/groups/${ gkey }/fields?_cb=` + Math.random() ) ).data;
		t.check( 'move down reorders', moved.status === 200 && fields.items[ 1 ].name === 'suite_headline',
			fields.items.map( ( f ) => f.name ).join( ',' ) );
		const del = await api( 'DELETE', 'minn-admin/v1/acf/schema/fields/' + headline.id );
		fields = ( await api( 'GET', `minn-admin/v1/acf/schema/groups/${ gkey }/fields?_cb=` + Math.random() ) ).data;
		t.check( 'field delete removes the definition', del.status === 200 && ! fields.items.some( ( f ) => f.name === 'suite_headline' ) );

		/* ===== Code group stays read-only ===== */
		const ro = await api( 'POST', 'minn-admin/v1/acf/schema/groups/group_minn_local/rename', { title: 'nope' } );
		t.check( 'code-registered group refuses mutation', ro.status === 400 && ro.data.code === 'read_only', JSON.stringify( ro.data ) );
		const roField = await api( 'PUT', 'minn-admin/v1/acf/schema/fields/field_minn_local_note', { label: 'nope' } );
		t.check( 'code-registered field refuses edit', roField.status === 400 );

		/* ===== Trash (the exposed verb), then force-clean ===== */
		const trash = await api( 'DELETE', 'minn-admin/v1/acf/schema/groups/' + gkey );
		const after = await groupsRest();
		t.check( 'trash removes the group from the list', trash.status === 200 && ! after.items.some( ( g ) => g.id === gkey ) );
		const purge = await api( 'DELETE', 'minn-admin/v1/acf/schema/groups/' + gkey + '?force=1' );
		t.check( 'force delete resolves the trashed group', purge.status === 200, JSON.stringify( purge.data ) );
	} finally {
		if ( postId ) await deletePost( page, postId ).catch( () => {} );
		// The suite group is in ACF's trash now (or leaked on a crash) — the
		// force path deletes trashed groups too via acf_delete_field_group.
		await page.evaluate( async () => {
			const h = { 'X-WP-Nonce': window.MINN.nonce };
			// Trashed groups no longer list; sweep acf-field-group posts named Suite Group via the schema route first…
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/acf/schema/groups?_cb=' + Math.random(), { headers: h, credentials: 'same-origin' } );
			const data = await r.json();
			for ( const g of ( data.items || [] ) ) {
				if ( /^Suite Group/.test( g.title ) ) {
					await fetch( window.MINN.restUrl + 'minn-admin/v1/acf/schema/groups/' + g.id + '?force=1', { method: 'DELETE', headers: h, credentials: 'same-origin' } );
				}
			}
		} ).catch( () => {} );
	}

	await t.done( browser, errors );
} )();
