/**
 * Breakdance 2.8.1 integration: exact plugin fixture, Pro license visibility,
 * and the builder-owned content fence with its canonical editor URL.
 */
const { BASE, WP, launch, login, reporter } = require( './helpers' );
const { execFileSync } = require( 'child_process' );

function wp( args, opts = {} ) {
	return execFileSync( 'wp', [ `--path=${ WP }`, ...args ], {
		encoding: 'utf8',
		stdio: [ 'ignore', 'pipe', 'ignore' ],
		timeout: 60000,
		...opts,
	} ).trim();
}

function isActive() {
	try {
		wp( [ 'plugin', 'is-active', 'breakdance' ] );
		return true;
	} catch ( e ) {
		return false;
	}
}

( async () => {
	const t = reporter( 'breakdance' );
	const wasActive = isActive();
	let ids = [];

	t.check( 'the exact Breakdance fixture is installed', /2\.8\.1/.test( wp( [ 'plugin', 'get', 'breakdance', '--field=version' ] ) ) );

	const { browser, page, errors } = await launch();
	await login( page );

	const create = async ( type ) => page.evaluate( async ( postType ) => {
		const route = 'page' === postType ? 'pages' : 'posts';
		const r = await fetch( window.MINN.restUrl + 'wp/v2/' + route, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			body: JSON.stringify( { title: 'Breakdance ' + postType + ' fixture', status: 'draft', content: '' } ),
		} );
		const j = await r.json();
		if ( ! r.ok ) throw new Error( j.message || 'fixture create failed' );
		return j.id;
	}, type );

	try {
		if ( wasActive ) wp( [ 'plugin', 'deactivate', 'breakdance' ] );
		const postId = await create( 'post' );
		ids.push( [ 'posts', postId ] );
		const pageId = await create( 'page' );
		ids.push( [ 'pages', pageId ] );
		const section = {
			id: 100,
			data: { type: 'EssentialElements\\Section', properties: null },
			children: [],
			_parentId: 1,
		};
		const root = {
			id: 1,
			data: { type: 'root', properties: [] },
			children: [ section ],
		};
		const tree = JSON.stringify( {
			root,
			_nextNodeId: 101,
			status: 'exported',
			exportedLookupTable: { 1: root, 100: section },
		} );
		for ( const [ , id ] of ids ) {
			// Breakdance stores its metadata as slashed JSON, not as a
			// WordPress-serialized PHP array. Match Data\set_meta() even while
			// the plugin is inactive for this half of the integration test.
			wp( [ 'eval', `update_post_meta( ${ id }, '_breakdance_data', wp_slash( wp_json_encode( array( 'tree_json_string' => ${ JSON.stringify( tree ) } ) ) ) );` ] );
		}

		for ( const [ route, id ] of ids ) {
			await page.goto( `${ BASE }/minn-admin/editor/${ route }/${ id }`, { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( 'a.minn-builder-note', { timeout: 20000 } );
			const inactive = await page.evaluate( () => {
				const note = document.querySelector( 'a.minn-builder-note' );
				return {
					message: note.textContent,
					locked: document.querySelector( '#minn-editor-body' ).classList.contains( 'locked' ),
					href: note.href,
				};
			} );
			t.check( `${ route } stays fenced while Breakdance is inactive`, inactive.locked && /Breakdance is currently off/.test( inactive.message ), JSON.stringify( inactive ) );
			t.check( `${ route } inactive warning points to Extensions`, /\/minn-admin\/extensions\/?$/.test( inactive.href ) && /Open Extensions/.test( inactive.message ), JSON.stringify( inactive ) );
		}

		wp( [ 'plugin', 'activate', 'breakdance' ] );

		for ( const [ route, id ] of ids ) {
			await page.goto( `${ BASE }/minn-admin/editor/${ route }/${ id }`, { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( 'a.minn-builder-note', { timeout: 20000 } );
			const shape = await page.evaluate( ( expectedId ) => {
				const note = document.querySelector( 'a.minn-builder-note' );
				const body = document.querySelector( '#minn-editor-body' );
				const url = new URL( note.href );
				return {
					message: note.textContent,
					locked: body.classList.contains( 'locked' ),
					builder: url.searchParams.get( 'breakdance' ),
					id: url.searchParams.get( 'id' ),
					expectedId: String( expectedId ),
				};
			}, id );
			t.check( `${ route } content is fenced with a Breakdance message`, shape.locked && /managed by Breakdance/.test( shape.message ), JSON.stringify( shape ) );
			t.check( `${ route } message links directly to the Breakdance builder`, shape.builder === 'builder' && shape.id === shape.expectedId && /Edit in Breakdance/.test( shape.message ), JSON.stringify( shape ) );
		}

		const builderPageId = ids.find( ( [ route ] ) => route === 'pages' )[1];
		const minnErrorCount = errors.length;
		const documentLoaded = page.waitForResponse( ( response ) => {
			const request = response.request();
			return response.url().includes( '/wp-admin/admin-ajax.php' )
				&& request.method() === 'POST'
				&& ( request.postData() || '' ).includes( 'breakdance_load_document' );
		}, { timeout: 60000 } );
		await page.goto( `${ BASE }/?breakdance=builder&id=${ builderPageId }`, { waitUntil: 'domcontentloaded', timeout: 60000 } );
		await page.waitForFunction( () => document.querySelector( '.breakdance-builder' ), null, { timeout: 30000 } );
		const documentResponse = await documentLoaded;
		const documentResponseText = await documentResponse.text();
		await page.waitForTimeout( 3000 );
		const builderState = await page.evaluate( () => ( {
			failed: /WordPress AJAX Request failed/.test( document.body.innerText ),
			loaded: !! document.querySelector( '.breakdance-builder' ),
		} ) );
		let documentJson = null;
		try { documentJson = JSON.parse( documentResponseText ); } catch ( e ) {}
		t.check( 'the direct Breakdance link boots the builder without an AJAX failure', documentResponse.ok() && documentJson !== null && builderState.loaded && ! builderState.failed, JSON.stringify( { ...builderState, status: documentResponse.status() } ) );
		await page.goto( `${ BASE }/minn-admin/extensions`, { waitUntil: 'domcontentloaded', timeout: 60000 } );
		await page.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 20000 } );
		// The destination is Breakdance's app, not Minn's. Its canvas also loads
		// third-party front-end scripts (Elementor Pro Notes currently throws on
		// unload), so the explicit response/UI assertion above is the gate.
		errors.splice( minnErrorCount );

		const license = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/licenses', {
				headers: { 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
			} );
			const j = await r.json();
			return j.items.find( ( item ) => item.source === 'breakdance' ) || null;
		} );
		t.check( 'Breakdance Pro license is valid on the dev site', license && license.name === 'Breakdance Pro' && license.state === 'valid', JSON.stringify( license ) );
		t.check( 'Breakdance license actions are available without exposing the key', license && license.key === true && ! Object.prototype.hasOwnProperty.call( license, 'license_key' ) && [ 'activate', 'deactivate', 'verify' ].every( ( action ) => license.can.includes( action ) ), JSON.stringify( license ) );

		const rejected = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/licenses/action', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				credentials: 'same-origin',
				body: JSON.stringify( {
					provider: 'breakdance',
					action: 'activate',
					secret: 'minn-breakdance-invalid-test-key',
				} ),
			} );
			const j = await r.json();
			return {
				ok: j.ok,
				code: j.code,
				echoed: JSON.stringify( j ).includes( 'minn-breakdance-invalid-test-key' ),
				state: j.licenses.items.find( ( item ) => item.source === 'breakdance' )?.state,
			};
		} );
		t.check( 'Breakdance rejects a bad key cleanly without echoing it', rejected.ok === false && rejected.code === 'invalid' && ! rejected.echoed, JSON.stringify( rejected ) );
		t.check( 'a rejected key restores the working Breakdance Pro state', rejected.state === 'valid', JSON.stringify( rejected ) );
	} finally {
		for ( const [ route, id ] of ids ) {
			await page.evaluate( async ( args ) => {
				await fetch( window.MINN.restUrl + `wp/v2/${ args.route }/${ args.id }?force=true`, {
					method: 'DELETE',
					headers: { 'X-WP-Nonce': window.MINN.nonce },
					credentials: 'same-origin',
				} ).catch( () => {} );
			}, { route, id } ).catch( () => {} );
		}
		if ( wasActive && ! isActive() ) wp( [ 'plugin', 'activate', 'breakdance' ] );
		if ( ! wasActive && isActive() ) wp( [ 'plugin', 'deactivate', 'breakdance' ] );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
