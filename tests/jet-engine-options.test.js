/**
 * JetEngine options pages under Site options. The fixture page
 * "Minn Site Options" (slug minn-site-options, storage 'default' = one
 * option array, manage_options) joins the merged Site options tabs with a
 * JetEngine badge; reads go through the page's own get(), writes through
 * update_options() with rewrite off so untouched fields keep their value.
 */
const { BASE, launch, login, reporter } = require( './helpers' );
( async () => {
	const t = reporter( 'jet-engine-options' );
	const { browser, page, errors } = await launch();
	await login( page );
	const api = ( p, opts ) => page.evaluate( async ( [ pathArg, o ] ) => {
		const r = await fetch( window.MINN.restUrl + pathArg + ( pathArg.includes( '?' ) ? '&' : '?' ) + '_cb=' + Math.random(), {
			method: ( o && o.method ) || 'GET',
			headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
			body: o && o.body ? JSON.stringify( o.body ) : undefined,
		} );
		let body = null;
		try { body = await r.json(); } catch ( e ) { body = null; }
		return { status: r.status, body };
	}, [ p, opts || null ] );
	const surface = await page.evaluate( () => ( window.MINN.surfaces || [] ).find( ( s ) => s.settings && /minn-admin\/v1\/options\//.test( s.settings.route ) ) );
	t.check( 'Site options surface is registered', !! surface, JSON.stringify( surface && surface.settings && surface.settings.tabs ) );
	const tab = surface && surface.settings.tabs.find( ( x ) => /Minn Site Options/.test( x.label ) );
	t.check( 'the JetEngine page is one of its tabs', !! tab, JSON.stringify( surface && surface.settings.tabs.map( ( x ) => x.label ) ) );
	let original = null;
	try {
		const get = await api( `minn-admin/v1/options/${ tab.id }` );
		original = ( get.body || {} ).values || {};
		const names = ( ( ( get.body || {} ).groups || [] )[ 0 ] || { fields: [] } ).fields.map( ( f ) => f.name + ':' + f.type );
		t.check( 'GET maps the page fields', get.status === 200 && JSON.stringify( names ) === JSON.stringify( [ 'company_name:text', 'support_email:text', 'weekends:true_false', 'plan:select', 'footer_note:textarea' ] ) && /page=minn-site-options/.test( get.body.adminUrl ), JSON.stringify( names ) );
		const stamp = 'Suite Co ' + Date.now();
		const post = await api( `minn-admin/v1/options/${ tab.id }`, { method: 'POST', body: { values: { company_name: stamp, weekends: true, plan: 'pro' } } } );
		const v = ( post.body || {} ).values || {};
		t.check( 'POST saves through the page and answers fresh values', post.status === 200 && v.company_name === stamp && v.weekends === true && v.plan === 'pro', JSON.stringify( v ) );
		const partial = await api( `minn-admin/v1/options/${ tab.id }`, { method: 'POST', body: { values: { plan: 'basic' } } } );
		const pv = ( partial.body || {} ).values || {};
		t.check( 'a partial save leaves the other fields alone', partial.status === 200 && pv.company_name === stamp && pv.plan === 'basic' && pv.weekends === true, JSON.stringify( pv ) );

		await page.goto( `${ BASE }/minn-admin/${ surface.id }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-view input, #minn-view textarea', { timeout: 20000 } );
		await page.evaluate( () => {
			const el = Array.from( document.querySelectorAll( '#minn-view [data-stab], #minn-view .minn-tab, #minn-view button' ) ).find( ( b ) => /Minn Site Options/.test( b.textContent ) );
			if ( el ) el.click();
		} );
		await page.waitForTimeout( 800 );
		const ui = await page.evaluate( ( s ) => ( {
			company: [ ...document.querySelectorAll( '#minn-view input' ) ].some( ( i ) => i.value === s ),
			labels: document.querySelectorAll( '#minn-view .minn-field-label' ).length,
			nullText: /(^|\s)null(\s|$)/.test( document.querySelector( '#minn-view' ).textContent ),
		} ), stamp );
		t.check( 'the page renders the JetEngine tab with the stored value', ui.company && ui.labels > 0 && ! ui.nullText, JSON.stringify( ui ) );
	} finally {
		if ( tab && original ) {
			await api( `minn-admin/v1/options/${ tab.id }`, { method: 'POST', body: { values: { company_name: original.company_name || '', weekends: !! original.weekends, plan: original.plan || '' } } } ).catch( () => {} );
		}
		await t.done( browser, errors );
	}
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
