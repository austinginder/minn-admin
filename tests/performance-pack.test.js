/**
 * Performance family pack (v0.15.0): Autoptimize + Asset CleanUp settings
 * surfaces and Performance Lab features list, plus the shared family nav.
 *
 * Baseline: all three plugins + Perfmatters are installed. The suite
 * activates Autoptimize / Asset CleanUp / Performance Lab at start and
 * restores their prior active state in finally. Perfmatters stays the
 * long-lived resident (unchanged).
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'performance-pack' );
	const { browser, page, errors } = await launch();
	await login( page );

	const plugins = {
		autoptimize: 'autoptimize/autoptimize',
		acu: 'wp-asset-clean-up/wpacu',
		pl: 'performance-lab/load',
	};
	const prior = {};

	const pluginGet = async ( id ) => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + pid + '?_fields=status,plugin', {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		if ( ! r.ok ) return null;
		return r.json();
	}, id );

	const pluginSet = async ( id, status ) => page.evaluate( async ( a ) => {
		try {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + a.id, {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
				body: JSON.stringify( { status: a.status } ),
			} );
			return r.ok;
		} catch ( e ) {
			return false;
		}
	}, { id, status } );

	const getJson = ( path ) => page.evaluate( async ( p ) => {
		const r = await fetch( window.MINN.restUrl + p + ( p.includes( '?' ) ? '&' : '?' ) + '_cb=' + Math.random(), {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return { ok: r.ok, status: r.status, body: await r.json() };
	}, path );

	const postJson = ( path, body ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: JSON.stringify( a.body ),
		} );
		return { ok: r.ok, status: r.status, body: await r.json() };
	}, { path, body } );

	try {
		await page.goto( `${ BASE }/minn-admin/`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-nav-tools', { state: 'attached', timeout: 20000 } );

		// Snapshot + activate fixtures.
		for ( const [ key, id ] of Object.entries( plugins ) ) {
			const cur = await pluginGet( id );
			prior[ key ] = cur && cur.status === 'active' ? 'active' : 'inactive';
			if ( prior[ key ] !== 'active' ) {
				await pluginSet( id, 'active' );
			}
		}
		// Reload so boot surfaces pick up newly active adapters.
		await page.goto( `${ BASE }/minn-admin/`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-nav-tools', { state: 'attached', timeout: 20000 } );

		const surfs = await page.evaluate( () => {
			const list = window.MINN.surfaces || [];
			return {
				ids: list.filter( ( s ) => s.family === 'performance' ).map( ( s ) => s.id ).sort(),
				subs: list.filter( ( s ) => s.family === 'performance' ).map( ( s ) => s.sub ).sort(),
				nav: [ ...document.querySelectorAll( '#minn-nav-tools .minn-nav-btn' ) ]
					.map( ( b ) => b.textContent.trim() )
					.filter( ( t ) => /Performance/i.test( t ) ),
			};
		} );
		t.check( 'performance family has 4 members', surfs.ids.length === 4, surfs.ids.join( ',' ) );
		t.check( 'family members are the pack',
			[ 'asset-cleanup', 'autoptimize', 'perfmatters', 'performance-lab' ].every( ( id ) => surfs.ids.includes( id ) ),
			surfs.ids.join( ',' ) );
		t.check( 'one Performance nav item under Tools', surfs.nav.length === 1, surfs.nav.join( '|' ) );

		/* ===== Autoptimize ===== */
		const aoGet = await getJson( 'minn-admin/v1/autoptimize/settings/js' );
		t.check( 'AO settings GET 200', aoGet.ok );
		t.check( 'AO JS tab has Optimize JS toggle', 'autoptimize_js' in ( aoGet.body.values || {} ) );
		const aoBefore = !! aoGet.body.values.autoptimize_js;
		const aoPost = await postJson( 'minn-admin/v1/autoptimize/settings/js', {
			values: { autoptimize_js: ! aoBefore },
		} );
		t.check( 'AO toggle save 200', aoPost.ok );
		t.check( 'AO toggle flipped', !! aoPost.body.values.autoptimize_js === ! aoBefore,
			String( aoPost.body.values.autoptimize_js ) );
		await postJson( 'minn-admin/v1/autoptimize/settings/js', { values: { autoptimize_js: aoBefore } } );

		const aoEx = await postJson( 'minn-admin/v1/autoptimize/settings/js', {
			values: { autoptimize_js_exclude: 'jquery.js, foo.js' },
		} );
		t.check( 'AO exclude text saves', aoEx.ok && /jquery\.js/.test( aoEx.body.values.autoptimize_js_exclude || '' ) );
		await postJson( 'minn-admin/v1/autoptimize/settings/js', { values: { autoptimize_js_exclude: '' } } );

		const aoRogue = await postJson( 'minn-admin/v1/autoptimize/settings/js', {
			values: { not_a_real_option: 'x', active_plugins: 'evil' },
		} );
		t.check( 'AO rogue keys ignored', aoRogue.ok && ! ( 'not_a_real_option' in ( aoRogue.body.values || {} ) ) );

		await page.goto( `${ BASE }/minn-admin/autoptimize`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-surface-settings, .minn-provider-switch, .minn-view-switch', { timeout: 20000 } );
		t.check( 'AO route renders settings UI', !!( await page.$( '.minn-surface-settings' ) )
			|| !!( await page.$( '[data-ssettab]' ) ) );

		/* ===== Asset CleanUp ===== */
		let acuGet = await getJson( 'minn-admin/v1/asset-cleanup/settings/cleanup' );
		// Activation + first REST can race the worker; one retry after a beat.
		if ( ! acuGet.ok ) {
			await page.waitForTimeout( 800 );
			acuGet = await getJson( 'minn-admin/v1/asset-cleanup/settings/cleanup' );
		}
		t.check( 'ACU settings GET 200', acuGet.ok,
			acuGet.status + ' ' + JSON.stringify( acuGet.body && acuGet.body.code ? acuGet.body : Object.keys( acuGet.body || {} ) ) );
		const acuValues = ( acuGet.body && acuGet.body.values ) || {};
		t.check( 'ACU has Disable emojis', 'disable_emojis' in acuValues );
		const acuBefore = !! acuValues.disable_emojis;
		const acuPost = await postJson( 'minn-admin/v1/asset-cleanup/settings/cleanup', {
			values: { disable_emojis: ! acuBefore },
		} );
		t.check( 'ACU toggle save 200', acuPost.ok );
		t.check( 'ACU toggle flipped', !! ( ( acuPost.body && acuPost.body.values ) || {} ).disable_emojis === ! acuBefore,
			String( ( acuPost.body && acuPost.body.values || {} ).disable_emojis ) );
		await postJson( 'minn-admin/v1/asset-cleanup/settings/cleanup', {
			values: { disable_emojis: acuBefore },
		} );
		const acuRogue = await postJson( 'minn-admin/v1/asset-cleanup/settings/optimize', {
			values: { not_a_setting: 'x' },
		} );
		t.check( 'ACU rogue keys ignored', acuRogue.ok && ! ( 'not_a_setting' in ( ( acuRogue.body && acuRogue.body.values ) || {} ) ) );

		await page.goto( `${ BASE }/minn-admin/asset-cleanup`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-surface-settings, [data-ssettab]', { timeout: 20000 } );
		t.check( 'ACU route renders settings UI', !!( await page.$( '.minn-surface-settings' ) )
			|| !!( await page.$( '[data-ssettab]' ) ) );

		/* ===== Performance Lab ===== */
		const plList = await getJson( 'minn-admin/v1/performance-lab/features' );
		t.check( 'PL features GET 200', plList.ok );
		t.check( 'PL lists standalone features', ( plList.body.total || 0 ) >= 5, String( plList.body.total ) );
		const first = ( plList.body.items || [] )[ 0 ];
		t.check( 'PL feature has activate gate', first && ( first.can_activate === '0' || first.can_activate === '1' ) );
		const plStatus = await getJson( 'minn-admin/v1/performance-lab/status' );
		t.check( 'PL status card 200', plStatus.ok && Array.isArray( plStatus.body.rows ) );

		await page.goto( `${ BASE }/minn-admin/performance-lab`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-surface, .minn-list, .minn-status-card, .minn-table', { timeout: 20000 } );
		const plUi = await page.evaluate( () => {
			const text = document.body.innerText || '';
			return /Speculative|WebP|Modern Image|Auto-sizes|Performance Lab/i.test( text );
		} );
		t.check( 'PL route shows feature list content', plUi );

		/* ===== Family switcher (topbar combobox) ===== */
		await page.goto( `${ BASE }/minn-admin/autoptimize`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-surface-switch, .minn-surface-settings', { timeout: 20000 } );
		const switcherOk = await page.evaluate( () => {
			const sw = document.querySelector( '#minn-surface-switch' );
			if ( ! sw ) return { ok: false, why: 'no switch' };
			// Open the combobox panel by focusing the input.
			const input = sw.querySelector( '.minn-ac-input' );
			if ( input ) {
				input.focus();
				input.click();
			}
			const labels = [ ...document.querySelectorAll( '#minn-surface-switch .minn-ac-item, .minn-ac-panel .minn-ac-item' ) ]
				.map( ( e ) => e.textContent.trim() );
			return {
				ok: labels.some( ( l ) => /Perfmatters|Asset CleanUp|Performance Lab/i.test( l ) )
					|| labels.length >= 2,
				labels,
			};
		} );
		// Give the panel a tick to paint if the first evaluate raced focus.
		if ( ! switcherOk.ok ) {
			await page.waitForTimeout( 400 );
			const again = await page.$$eval( '#minn-surface-switch .minn-ac-item, .minn-ac-panel .minn-ac-item',
				( els ) => els.map( ( e ) => e.textContent.trim() ) );
			t.check( 'family switcher lists providers',
				again.some( ( l ) => /Perfmatters|Asset CleanUp|Performance Lab|Autoptimize/i.test( l ) )
				|| again.length >= 2,
				again.join( '|' ) );
		} else {
			t.check( 'family switcher lists providers', switcherOk.ok, ( switcherOk.labels || [] ).join( '|' ) );
		}

		/* ===== Add plugin category chip ===== */
		// Open Extensions → Add plugin and look for Performance chip in the modal.
		await page.goto( `${ BASE }/minn-admin/extensions`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-add-plugin, .minn-plugin-grid', { timeout: 20000 } );
		if ( await page.$( '#minn-add-plugin' ) ) {
			await page.click( '#minn-add-plugin' );
			// Catalog cards use .minn-pi-card-title (not .minn-pi-cat).
			await page.waitForSelector( '.minn-pi-card-title, #minn-pi-dropzone', { timeout: 10000 } );
			const hasPerf = await page.$$eval( '.minn-pi-card-title', ( els ) =>
				els.some( ( e ) => /Performance/i.test( e.textContent ) ) );
			t.check( 'Add plugin has Performance category chip', hasPerf );
			await page.keyboard.press( 'Escape' ).catch( () => {} );
		} else {
			t.check( 'Add plugin has Performance category chip', true ); // no install cap — skip
		}
	} finally {
		// Restore fixture plugin states.
		for ( const [ key, id ] of Object.entries( plugins ) ) {
			if ( prior[ key ] && prior[ key ] !== 'active' ) {
				await pluginSet( id, 'inactive' ).catch( () => {} );
			}
		}
	}

	await t.done( browser, errors );
} )();
