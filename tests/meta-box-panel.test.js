/**
 * Meta Box editor panel — ACF sibling for simple post fields.
 *
 * Proves: panel registers when Meta Box is active; fixture group maps
 * text/textarea/select/checkbox (cloneable notes count as locked); values
 * round-trip through minn_meta_box on wp/v2; the editor sidebar renders
 * the Custom fields · Meta Box card.
 */
const { launch, login, reporter, BASE, createPost, deletePost, openEditor } = require( './helpers' );

( async () => {
	const t = reporter( 'meta-box-panel' );
	const { browser, page, errors } = await launch();
	await login( page );

	let postId = null;
	const priorMb = { status: null };

	const pluginGet = async () => page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/meta-box/meta-box?_fields=status', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		if ( ! r.ok ) return null;
		return r.json();
	} );
	const pluginSet = async ( status ) => page.evaluate( async ( s ) => {
		try {
			await fetch( window.MINN.restUrl + 'wp/v2/plugins/meta-box/meta-box', {
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
		priorMb.status = cur && cur.status === 'active' ? 'active' : 'inactive';
		if ( priorMb.status !== 'active' ) {
			await pluginSet( 'active' );
			await page.waitForTimeout( 800 );
			await page.goto( `${ BASE }/minn-admin/`, { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( '#minn-app', { state: 'attached', timeout: 20000 } );
		}

		const panel = await page.evaluate( () =>
			( window.MINN.editorPanels || [] ).find( ( p ) => p.id === 'meta-box' ) || null );
		t.check( 'meta-box panel in boot payload', !! panel );
		if ( ! panel ) throw new Error( 'Meta Box panel missing — is the plugin active?' );
		t.check( 'valuesKey is minn_meta_box', panel.valuesKey === 'minn_meta_box' );
		t.check( 'writeKey is minn_meta_box', panel.writeKey === 'minn_meta_box' );

		// Fields route for posts.
		const fields = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/meta-box/fields?post_id=0&post_type=posts', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return { status: r.status, body: await r.json() };
		} );
		t.check( 'fields route 200', fields.status === 200 );
		const groups = fields.body.groups || [];
		const fixture = groups.find( ( g ) =>
			/Minn Meta Box Test/i.test( g.group || '' )
			|| ( g.fields || [] ).some( ( f ) => f.name === 'minn_mb_subtitle' ) );
		t.check( 'fixture group present', !! fixture, groups.map( ( g ) => g.group ).join( '|' ) );
		const names = ( fixture && fixture.fields || [] ).map( ( f ) => f.name );
		t.check( 'simple fields mapped',
			[ 'minn_mb_subtitle', 'minn_mb_summary', 'minn_mb_priority', 'minn_mb_featured' ]
				.every( ( n ) => names.includes( n ) ),
			names.join( ',' ) );
		t.check( 'cloneable field counted as locked', ( fixture && fixture.locked ) >= 1 );

		// Create a draft via REST and write panel values.
		const created = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/posts', {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				body: JSON.stringify( {
					title: 'Meta Box panel suite ' + Date.now(),
					status: 'draft',
					content: '<!-- wp:paragraph --><p>MB panel.</p><!-- /wp:paragraph -->',
					minn_meta_box: {
						minn_mb_subtitle: 'Panel subtitle',
						minn_mb_summary: 'Summary text',
						minn_mb_priority: 'high',
						minn_mb_featured: true,
					},
				} ),
			} );
			const body = await r.json();
			return { status: r.status, id: body.id, mb: body.minn_meta_box };
		} );
		t.check( 'create with minn_meta_box 201/200', created.status === 201 || created.status === 200, String( created.status ) );
		postId = created.id;
		t.check( 'create returned id', !! postId );

		const read = await page.evaluate( async ( id ) => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + id + '?context=edit&_fields=id,minn_meta_box', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return r.json();
		}, postId );
		const mb = read.minn_meta_box || {};
		t.check( 'subtitle round-trips', mb.minn_mb_subtitle === 'Panel subtitle', String( mb.minn_mb_subtitle ) );
		t.check( 'summary round-trips', mb.minn_mb_summary === 'Summary text' );
		t.check( 'priority round-trips', mb.minn_mb_priority === 'high' );
		t.check( 'featured toggle round-trips', mb.minn_mb_featured === true || mb.minn_mb_featured === 1 || mb.minn_mb_featured === '1' );

		// Open in Minn editor — panel card visible.
		await page.goto( `${ BASE }/minn-admin/editor/posts/${ postId }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-editor-body, .minn-editor', { timeout: 25000 } );
		// Panels load async after fieldsRoute.
		await page.waitForFunction( () => {
			const cards = [ ...document.querySelectorAll( '.minn-side-title' ) ];
			return cards.some( ( c ) => /Custom fields/i.test( c.textContent ) && /Meta Box/i.test( c.textContent ) );
		}, { timeout: 20000 } );
		t.check( 'editor shows Custom fields · Meta Box card', true );

		const ui = await page.evaluate( () => {
			const cards = [ ...document.querySelectorAll( '.minn-side-card' ) ];
			const mbCard = cards.find( ( c ) => {
				const t = c.querySelector( '.minn-side-title' );
				return t && /Meta Box/i.test( t.textContent );
			} );
			const labels = mbCard
				? [ ...mbCard.querySelectorAll( '.minn-panel-field .minn-field-label' ) ].map( ( e ) => e.textContent.trim() )
				: [];
			const locked = mbCard
				? [ ...mbCard.querySelectorAll( '.minn-panel-locked' ) ].map( ( e ) => e.textContent.trim() ).join( '|' )
				: '';
			const sub = mbCard ? ( mbCard.querySelector( '.minn-panel-sub' ) || {} ).textContent || '' : '';
			return { labels, locked, sub: String( sub ).trim(), hasCard: !! mbCard };
		} );
		t.check( 'panel sub is Meta Box', ui.hasCard && /Meta Box/i.test( ui.sub ), ui.sub );
		t.check( 'subtitle field label visible', ui.labels.some( ( l ) => /Subtitle/i.test( l ) ), ui.labels.join( ',' ) );
		t.check( 'locked notes mention advanced fields', /advanced field/i.test( ui.locked ) );

		// Edit subtitle in the panel UI and save.
		const saved = await page.evaluate( async () => {
			const inputs = [ ...document.querySelectorAll( '[data-pf]' ) ];
			const sub = inputs.find( ( el ) => ( el.getAttribute( 'data-pf' ) || '' ).includes( 'minn_mb_subtitle' ) );
			if ( ! sub ) return { ok: false, why: 'no subtitle input' };
			sub.focus();
			sub.value = 'Edited in sidebar';
			sub.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			// Mark dirty the way the binder does.
			const pf = sub.getAttribute( 'data-pf' ) || '';
			const [ pid, name ] = pf.split( ':' );
			if ( window.state && window.state.editor ) {
				// state is not global — trigger input handler by input event only.
			}
			return { ok: true, pf };
		} );
		// Use the page's bind path: type into the field.
		const subSel = await page.evaluate( () => {
			const inputs = [ ...document.querySelectorAll( '[data-pf]' ) ];
			const sub = inputs.find( ( el ) => ( el.getAttribute( 'data-pf' ) || '' ).includes( 'minn_mb_subtitle' ) );
			return sub ? '[data-pf="' + sub.getAttribute( 'data-pf' ) + '"]' : null;
		} );
		if ( subSel ) {
			await page.fill( subSel, 'Edited in sidebar' );
			// Trigger dirty + save via Update/⌘S — click Update if draft.
			const saveBtn = await page.$( '#minn-publish, [data-publish], .minn-btn-primary' );
			// Prefer keyboard save if available.
			await page.keyboard.down( 'Meta' );
			await page.keyboard.press( 's' );
			await page.keyboard.up( 'Meta' );
			// Also try Ctrl+S for non-mac.
			await page.keyboard.down( 'Control' );
			await page.keyboard.press( 's' );
			await page.keyboard.up( 'Control' );
			await page.waitForTimeout( 1500 );
			// Poll REST for the new value.
			let ok = false;
			for ( let i = 0; i < 10; i++ ) {
				const v = await page.evaluate( async ( id ) => {
					const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + id + '?context=edit&_fields=minn_meta_box&_cb=' + Math.random(), {
						headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
					} );
					const b = await r.json();
					return ( b.minn_meta_box && b.minn_meta_box.minn_mb_subtitle ) || '';
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
		if ( priorMb.status === 'inactive' ) {
			await pluginSet( 'inactive' ).catch( () => {} );
		}
	}

	await t.done( browser, errors );
} )();
