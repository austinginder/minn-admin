/**
 * sectionsRoute detail row types (v0.18.0): pill, code, html-preview
 * (sandboxed iframe), kv-table, plus the email/url link rows. Driven
 * through the contract fixture's Log view, whose /view endpoint returns
 * every type with HOSTILE values: a <script> inside the code row (must
 * render as escaped text) and a <script> inside the html-preview HTML
 * (must be BLOCKED by the sandbox — the browser's "Blocked script
 * execution" console error is asserted as the proof, then excused from
 * the zero-console-errors gate).
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'detail-rows' );
	const { browser, page, errors } = await launch();
	await login( page );

	const setOpt = async ( v ) => {
		for ( let attempt = 1; attempt <= 5; attempt++ ) {
			const stored = await page.evaluate( async ( val ) => {
				const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST', headers: h, credentials: 'same-origin',
					body: JSON.stringify( { minn_test_contract_surface: val } ),
				} );
				const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), {
					headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} );
				return ( await r.json() ).minn_test_contract_surface;
			}, v );
			if ( stored === v ) return true;
			await page.waitForTimeout( 800 );
		}
		return false;
	};

	try {
		t.check( 'contract fixture armed', await setOpt( true ) );

		await page.goto( `${ BASE }/minn-admin/minn-contract-fixture`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-sview="x0"]', { timeout: 30000 } );
		await page.click( '[data-sview="x0"]' );
		await page.waitForSelector( '.minn-table-row', { timeout: 15000 } );
		await page.click( '.minn-table-row' );
		await page.waitForSelector( '.minn-modal .minn-detail-frame', { timeout: 15000 } );

		const probe = await page.evaluate( () => {
			const modal = document.querySelector( '.minn-modal' );
			const frame = modal.querySelector( '.minn-detail-frame' );
			let frameDoc = 'inaccessible';
			try { frameDoc = frame.contentDocument ? 'ACCESSIBLE' : 'null'; } catch ( e ) { frameDoc = 'inaccessible'; }
			const kv = [ ...modal.querySelectorAll( '.minn-detail-kv' ) ];
			return {
				pill: ( modal.querySelector( '.minn-status.publish' ) || {} ).textContent || '',
				email: ( modal.querySelector( 'a[href^="mailto:"]' ) || {} ).getAttribute ? modal.querySelector( 'a[href^="mailto:"]' ).getAttribute( 'href' ) : '',
				url: !! modal.querySelector( 'a[href="https://example.com/contract"][target="_blank"]' ),
				code: ( modal.querySelector( '.minn-detail-code' ) || {} ).textContent || '',
				codeHasScriptEl: !! modal.querySelector( '.minn-detail-code script' ),
				sandbox: frame.getAttribute( 'sandbox' ),
				srcdocHasPreview: ( frame.getAttribute( 'srcdoc' ) || '' ).includes( 'Fixture preview' ),
				frameDoc,
				parentXss: window.__rowFrameXss === undefined && window.__rowCodeXss === undefined,
				kvCount: kv.length,
				kvFirst: kv[ 0 ] ? kv[ 0 ].textContent : '',
				kvFirstHasBoldEl: kv[ 0 ] ? !! kv[ 0 ].querySelector( 'b' ) : true,
				kvSecond: kv[ 1 ] ? kv[ 1 ].textContent : '',
			};
		} );

		t.check( 'pill row renders the shared status pill', probe.pill.trim() === 'sent', probe.pill );
		t.check( 'email row renders a mailto link', probe.email === 'mailto:dana@example.com', probe.email );
		t.check( 'url row renders a new-tab link', probe.url );
		t.check( 'code row shows the script tag as TEXT', probe.code.includes( '<script>window.__rowCodeXss=1</script>' ) && probe.code.includes( 'Content-Type: text/html' ), probe.code.slice( 0, 80 ) );
		t.check( 'code row contains no live element from the payload', ! probe.codeHasScriptEl );
		t.check( 'html-preview iframe is fully sandboxed', probe.sandbox === '', String( probe.sandbox ) );
		t.check( 'iframe carries the HTML via srcdoc', probe.srcdocHasPreview );
		t.check( 'sandboxed document is opaque to the parent', probe.frameDoc !== 'ACCESSIBLE', probe.frameDoc );
		t.check( 'neither hostile script reached the app window', probe.parentXss );
		t.check( 'both kv-table shapes render', probe.kvCount === 2, String( probe.kvCount ) );
		t.check( 'object-map kv rows render escaped', probe.kvFirst.includes( 'Reply-To' ) && probe.kvFirst.includes( '<b>not html</b>' ) && ! probe.kvFirstHasBoldEl, probe.kvFirst.slice( 0, 80 ) );
		t.check( 'pair-array kv rows render both pair shapes', probe.kvSecond.includes( 'k1' ) && probe.kvSecond.includes( 'v1' ) && probe.kvSecond.includes( 'k2' ) && probe.kvSecond.includes( 'v2' ), probe.kvSecond );

		// The sandbox-block console error IS the proof the script was denied.
		const blocked = errors.filter( ( e ) => /Blocked script execution/.test( e ) );
		t.check( 'browser reports the sandboxed script as blocked', blocked.length >= 1, errors.join( ' | ' ).slice( 0, 120 ) );
		// Excuse ONLY that expected message from the zero-errors gate.
		errors.splice( 0, errors.length, ...errors.filter( ( e ) => ! /Blocked script execution/.test( e ) ) );
	} finally {
		await setOpt( false );
	}

	await t.done( browser, errors );
} )();
