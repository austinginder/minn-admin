/**
 * ACF field group import/export: the export route serves ACF's own Tools
 * JSON shape (fields nested, prepared for export, array-wrapped), the row
 * action downloads it as a real file, and the Import dialog round-trips it
 * — delete a group, import the file, get the same keys back. Re-importing
 * updates IN PLACE (no duplicate group posts, the ACF-tools failure mode),
 * code-registered keys refuse, and malformed payloads refuse with nothing
 * written.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'acf-import-export' );
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
		for ( const g of ( ( list && list.items ) || [] ) ) {
			if ( /^Import Suite/.test( g.title ) ) await api( 'DELETE', 'minn-admin/v1/acf/schema/groups/' + g.id + '?force=1' );
		}
	};

	let gkey = '';
	try {
		await sweep();
		// Build a group with two fields over REST.
		await api( 'POST', 'minn-admin/v1/acf/schema/groups', { title: 'Import Suite Group', location: 'post_type:post' } );
		const list = ( await api( 'GET', 'minn-admin/v1/acf/schema/groups?_cb=' + Math.random() ) ).data;
		gkey = ( list.items.find( ( g ) => g.title === 'Import Suite Group' ) || {} ).id;
		await api( 'POST', 'minn-admin/v1/acf/schema/groups/' + gkey + '/full', { fields: [
			{ type: 'text', name: 'suite_note', label: 'Suite Note', placeholder: 'Hm' },
			{ type: 'select', name: 'suite_pick', label: 'Suite Pick', choices: 'one : One\ntwo : Two' },
		] } );

		/* ===== Export: ACF Tools shape, array-wrapped, keys intact. ===== */
		const exp = await api( 'GET', 'minn-admin/v1/acf/schema/groups/' + gkey + '/export' );
		t.check( 'export route answers filename + content', exp.status === 200
			&& /^acf-export-import-suite-group-\d{4}-\d{2}-\d{2}\.json$/.test( exp.data.filename ),
			JSON.stringify( exp.data && exp.data.filename ) );
		const parsed = JSON.parse( exp.data.content );
		t.check( 'content is an ACF export array with nested fields', Array.isArray( parsed )
			&& parsed[ 0 ].key === gkey && parsed[ 0 ].fields.length === 2
			&& parsed[ 0 ].fields[ 1 ].choices && parsed[ 0 ].fields[ 1 ].choices.two === 'Two',
			JSON.stringify( parsed[ 0 ] && parsed[ 0 ].fields && parsed[ 0 ].fields.map( ( f ) => f.name ) ) );
		const fieldKeys = parsed[ 0 ].fields.map( ( f ) => f.key ).sort();

		/* ===== The row action downloads a real file. ===== */
		await page.reload( { waitUntil: 'domcontentloaded' } );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-table-row' ) ).some( ( r ) => r.textContent.includes( 'Import Suite Group' ) ),
		null, { timeout: 15000 } );
		const dlWait = page.waitForEvent( 'download', { timeout: 10000 } );
		await page.evaluate( () => {
			const row = Array.from( document.querySelectorAll( '.minn-table-row' ) ).find( ( r ) => r.textContent.includes( 'Import Suite Group' ) );
			row.querySelector( '.minn-row-more' ).click();
		} );
		await page.waitForSelector( '.minn-ctx-menu', { timeout: 5000 } );
		await page.evaluate( () => {
			const item = Array.from( document.querySelectorAll( '.minn-ctx-menu button' ) )
				.find( ( b ) => /Export JSON/.test( b.textContent ) );
			if ( item ) item.click();
		} );
		const dl = await dlWait;
		t.check( 'row Export JSON downloads the file', /^acf-export-import-suite-group-/.test( dl.suggestedFilename() ), dl.suggestedFilename() );

		/* ===== Round trip: delete the group, import the file via the UI
		 * dialog (paste path), same keys come back. ===== */
		await api( 'DELETE', 'minn-admin/v1/acf/schema/groups/' + gkey + '?force=1' );
		await page.reload( { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-surface-import', { timeout: 15000 } );
		await page.click( '#minn-surface-import' );
		await page.waitForSelector( '#minn-simport-text', { timeout: 5000 } );
		await page.evaluate( ( content ) => { document.querySelector( '#minn-simport-text' ).value = content; }, exp.data.content );
		const importWait = page.waitForResponse( ( res ) =>
			res.request().method() === 'POST' && /acf\/schema\/import/.test( res.url() ), { timeout: 15000 } );
		await page.click( '#minn-simport-go' );
		t.check( 'import dialog posts and succeeds', ( await importWait ).status() === 200 );
		await page.waitForFunction( () =>
			Array.from( document.querySelectorAll( '.minn-table-row' ) ).some( ( r ) => r.textContent.includes( 'Import Suite Group' ) ),
		null, { timeout: 15000 } );
		t.check( 'imported group reappears in the list', true );
		const back = ( await api( 'GET', 'minn-admin/v1/acf/schema/groups/' + gkey + '/full?_cb=' + Math.random() ) ).data;
		t.check( 'round trip preserves the group and field keys', !! back.group
			&& back.fields.map( ( f ) => f.key ).sort().join() === fieldKeys.join()
			&& /two : Two/.test( ( back.fields.find( ( f ) => f.name === 'suite_pick' ) || {} ).choices ),
			JSON.stringify( back.fields && back.fields.map( ( f ) => f.name + ':' + f.key ) ) );

		/* ===== Re-import updates in place — never a duplicate post. ===== */
		const again = await api( 'POST', 'minn-admin/v1/acf/schema/import', { content: exp.data.content } );
		t.check( 're-import reports an in-place update', again.status === 200
			&& again.data.updated === 1 && again.data.created === 0, JSON.stringify( again.data ) );
		const after = ( await api( 'GET', 'minn-admin/v1/acf/schema/groups?_cb=' + Math.random() ) ).data;
		t.check( 'no duplicate group appears', after.items.filter( ( g ) => g.title === 'Import Suite Group' ).length === 1 );

		/* ===== A drop ANYWHERE while the dialog is open routes to the
		 * import, never the drop-to-Media handler (the handler-competition
		 * class — a real report: the file uploaded to the media library). */
		await page.click( '#minn-surface-import' );
		await page.waitForSelector( '#minn-simport-drop', { timeout: 5000 } );
		const dropWait = page.waitForResponse( ( res ) =>
			res.request().method() === 'POST' && /acf\/schema\/import/.test( res.url() ), { timeout: 15000 } );
		await page.evaluate( ( content ) => {
			const dt = new DataTransfer();
			dt.items.add( new File( [ content ], 'acf-export-drop-probe.json', { type: 'application/json' } ) );
			const ev = new DragEvent( 'drop', { bubbles: true, cancelable: true } );
			Object.defineProperty( ev, 'dataTransfer', { value: dt } );
			document.body.dispatchEvent( ev );
		}, exp.data.content );
		t.check( 'a drop anywhere imports through the dialog', ( await dropWait ).status() === 200 );
		await page.waitForTimeout( 800 );
		const strayMedia = await api( 'GET', 'wp/v2/media?search=acf-export-drop-probe&_fields=id' );
		t.check( 'nothing landed in the media library', Array.isArray( strayMedia.data ) && strayMedia.data.length === 0,
			JSON.stringify( strayMedia.data ) );

		/* ===== Refusals write nothing. ===== */
		const bad = await api( 'POST', 'minn-admin/v1/acf/schema/import', { content: 'not json {' } );
		t.check( 'invalid JSON refuses 400', bad.status === 400, JSON.stringify( bad.data ) );
		const noGroups = await api( 'POST', 'minn-admin/v1/acf/schema/import', {
			content: JSON.stringify( [ { key: 'post_type_abc123', title: 'A CPT' } ] ),
		} );
		t.check( 'non-group entries alone refuse 400', noGroups.status === 400, JSON.stringify( noGroups.data ) );
		const codeClash = await api( 'POST', 'minn-admin/v1/acf/schema/import', {
			content: JSON.stringify( [ { key: 'group_minn_local', title: 'Shadow', fields: [] } ] ),
		} );
		t.check( 'code-registered key refuses with a plain message', codeClash.status === 400
			&& /registered in code/.test( ( codeClash.data || {} ).message || '' ), JSON.stringify( codeClash.data ) );
	} finally {
		await sweep().catch( () => {} );
	}

	await t.done( browser, errors );
} )();
