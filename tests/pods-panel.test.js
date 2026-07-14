/**
 * Pods editor panel — ACF / Meta Box sibling for simple post fields.
 *
 * Proves: panel registers when Pods is active; fixture fields on the extended
 * post pod map text/paragraph/pick/boolean (file counts as locked); values
 * round-trip through minn_pods on wp/v2; the editor sidebar renders
 * Custom fields · Pods.
 *
 * Fixture fields are standing data on the post pod (minn_pods_* names), created
 * once via pods_api()->save_field on the extended post pod.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'pods-panel' );
	const { browser, page, errors } = await launch();
	await login( page );

	let postId = null;
	const prior = { status: null };

	const pluginGet = async () => page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/pods/init?_fields=status', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		if ( ! r.ok ) return null;
		return r.json();
	} );
	const pluginSet = async ( status ) => page.evaluate( async ( s ) => {
		try {
			await fetch( window.MINN.restUrl + 'wp/v2/plugins/pods/init', {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				body: JSON.stringify( { status: s } ),
			} );
		} catch ( e ) { /* worker recycle */ }
	}, status );

	try {
		await page.goto( `${ BASE }/minn-admin/`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-app', { state: 'attached', timeout: 20000 } );

		const cur = await pluginGet();
		prior.status = cur && cur.status === 'active' ? 'active' : 'inactive';
		if ( prior.status !== 'active' ) {
			await pluginSet( 'active' );
			await page.waitForTimeout( 800 );
			await page.goto( `${ BASE }/minn-admin/`, { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( '#minn-app', { state: 'attached', timeout: 20000 } );
		}

		const panel = await page.evaluate( () =>
			( window.MINN.editorPanels || [] ).find( ( p ) => p.id === 'pods' ) || null );
		t.check( 'pods panel in boot payload', !! panel );
		if ( ! panel ) throw new Error( 'Pods panel missing — is the plugin active with an extended post pod?' );
		t.check( 'valuesKey is minn_pods', panel.valuesKey === 'minn_pods' );
		t.check( 'writeKey is minn_pods', panel.writeKey === 'minn_pods' );

		const fields = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/pods/fields?post_id=0&post_type=posts', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return { status: r.status, body: await r.json() };
		} );
		t.check( 'fields route 200', fields.status === 200 );
		const groups = fields.body.groups || [];
		const fixture = groups.find( ( g ) =>
			( g.fields || [] ).some( ( f ) => f.name === 'minn_pods_subtitle' )
			|| /post/i.test( g.group || '' ) );
		t.check( 'fixture group present', !! fixture, groups.map( ( g ) => g.group ).join( '|' ) );
		const names = ( fixture && fixture.fields || [] ).map( ( f ) => f.name );
		t.check( 'simple fields mapped',
			[ 'minn_pods_subtitle', 'minn_pods_summary', 'minn_pods_priority', 'minn_pods_featured' ]
				.every( ( n ) => names.includes( n ) ),
			names.join( ',' ) );
		t.check( 'file field counted as locked', ( fixture && fixture.locked ) >= 1 );

		const created = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/posts', {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				body: JSON.stringify( {
					title: 'Pods panel suite ' + Date.now(),
					status: 'draft',
					content: '<!-- wp:paragraph --><p>Pods panel.</p><!-- /wp:paragraph -->',
					minn_pods: {
						minn_pods_subtitle: 'Panel subtitle',
						minn_pods_summary: 'Summary text',
						minn_pods_priority: 'high',
						minn_pods_featured: true,
					},
				} ),
			} );
			const body = await r.json();
			return { status: r.status, id: body.id, pods: body.minn_pods };
		} );
		t.check( 'create with minn_pods 201/200', created.status === 201 || created.status === 200, String( created.status ) );
		postId = created.id;
		t.check( 'create returned id', !! postId );

		const read = await page.evaluate( async ( id ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + id + '?context=edit&_fields=id,minn_pods', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return r.json();
		}, postId );
		const pods = read.minn_pods || {};
		t.check( 'subtitle round-trips', pods.minn_pods_subtitle === 'Panel subtitle', String( pods.minn_pods_subtitle ) );
		t.check( 'summary round-trips', pods.minn_pods_summary === 'Summary text' );
		t.check( 'priority round-trips', pods.minn_pods_priority === 'high' );
		t.check( 'featured toggle round-trips', pods.minn_pods_featured === true || pods.minn_pods_featured === 1 || pods.minn_pods_featured === '1' );

		await page.goto( `${ BASE }/minn-admin/editor/posts/${ postId }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-editor-body, .minn-editor', { timeout: 25000 } );
		await page.waitForFunction( () => {
			const cards = [ ...document.querySelectorAll( '.minn-side-title' ) ];
			return cards.some( ( c ) => /Custom fields/i.test( c.textContent ) && /Pods/i.test( c.textContent ) );
		}, { timeout: 20000 } );
		t.check( 'editor shows Custom fields · Pods card', true );

		const ui = await page.evaluate( () => {
			const cards = [ ...document.querySelectorAll( '.minn-side-card' ) ];
			const card = cards.find( ( c ) => {
				const t = c.querySelector( '.minn-side-title' );
				return t && /Pods/i.test( t.textContent );
			} );
			const labels = card
				? [ ...card.querySelectorAll( '.minn-panel-field .minn-field-label' ) ].map( ( e ) => e.textContent.trim() )
				: [];
			const locked = card
				? [ ...card.querySelectorAll( '.minn-panel-locked' ) ].map( ( e ) => e.textContent.trim() ).join( '|' )
				: '';
			const sub = card ? ( card.querySelector( '.minn-panel-sub' ) || {} ).textContent || '' : '';
			return { labels, locked, sub: String( sub ).trim(), hasCard: !! card };
		} );
		t.check( 'panel sub is Pods', ui.hasCard && /Pods/i.test( ui.sub ), ui.sub );
		t.check( 'subtitle field label visible', ui.labels.some( ( l ) => /Subtitle/i.test( l ) ), ui.labels.join( ',' ) );
		t.check( 'locked notes mention advanced fields', /advanced field/i.test( ui.locked ) );

		const subSel = await page.evaluate( () => {
			const inputs = [ ...document.querySelectorAll( '[data-pf]' ) ];
			const sub = inputs.find( ( el ) => ( el.getAttribute( 'data-pf' ) || '' ).includes( 'minn_pods_subtitle' ) );
			return sub ? '[data-pf="' + sub.getAttribute( 'data-pf' ) + '"]' : null;
		} );
		if ( subSel ) {
			await page.fill( subSel, 'Edited in sidebar' );
			await page.keyboard.down( 'Meta' );
			await page.keyboard.press( 's' );
			await page.keyboard.up( 'Meta' );
			await page.keyboard.down( 'Control' );
			await page.keyboard.press( 's' );
			await page.keyboard.up( 'Control' );
			await page.waitForTimeout( 1500 );
			let ok = false;
			for ( let i = 0; i < 10; i++ ) {
				const v = await page.evaluate( async ( id ) => {
					const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + id + '?context=edit&_fields=minn_pods&_cb=' + Math.random(), {
						headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
					} );
					const b = await r.json();
					return ( b.minn_pods && b.minn_pods.minn_pods_subtitle ) || '';
				}, postId );
				if ( v === 'Edited in sidebar' ) { ok = true; break; }
				await page.waitForTimeout( 500 );
			}
			t.check( 'sidebar edit saves subtitle', ok );
		} else {
			t.check( 'sidebar edit saves subtitle', false, 'subtitle input not found' );
		}
	} finally {
		if ( postId ) {
			await page.evaluate( async ( id ) => {
				await fetch( window.MINN.restUrl + 'wp/v2/posts/' + id + '?force=true', {
					method: 'DELETE', credentials: 'same-origin',
					headers: { 'X-WP-Nonce': window.MINN.nonce },
				} ).catch( () => {} );
			}, postId ).catch( () => {} );
		}
		if ( prior.status === 'inactive' ) {
			await pluginSet( 'inactive' ).catch( () => {} );
		}
	}

	await t.done( browser, errors );
} )();
