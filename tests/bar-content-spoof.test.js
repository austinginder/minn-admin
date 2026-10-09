/**
 * Post content cannot drive the Minn Bar.
 *
 * The bar prints on wp_footer, after the post content, and post content from
 * an author keeps id, style, hidden and <label for> through kses. Any control
 * the bar looked up by a document-wide id was therefore the CONTENT's when
 * the content carried the same id first, and a <label for> aimed at a real
 * bar button clicks it from anywhere on the page. The one that matters is the
 * site-visibility fix: one invisible full-screen element and the admin's next
 * click anywhere POSTs blog_public (or maintenance off) with the admin's nonce.
 *
 * Every fixture post is written by the AUTHOR account over REST, so kses runs
 * exactly as it does for a real author; the admin then views it with the bar
 * on and the site hidden from search (blog_public 0, restored at the end).
 * The control is the bar's own fix: it still works, behind its confirm step.
 */
const { BASE, launch, login, loginAs, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'bar-content-spoof' );
	await login( page );
	await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 30000 } );
	const auth = await page.evaluate( () => ( { rest: window.MINN.restUrl, nonce: window.MINN.nonce } ) );
	const rest = ( method, route, body ) => page.evaluate( async ( { a, m, r, b } ) => {
		const res = await fetch( a.rest + r, {
			method: m,
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': a.nonce },
			body: b ? JSON.stringify( b ) : undefined,
		} );
		return res.json().catch( () => ( {} ) );
	}, { a: auth, m: method, r: route, b: body } );
	const blogPublic = async () => Number( ( await rest( 'GET', 'wp/v2/settings' ) ).blog_public );

	const before = await rest( 'GET', 'minn-admin/v1/me/appearance' );
	const prevFrontBar = !! before.frontBar;
	const prevPublic = await blogPublic();
	await rest( 'POST', 'minn-admin/v1/me/appearance', { frontBar: true } );

	// Settings writes from the page, whoever fires them. settle() waits for
	// any that started to finish, so the stored-value checks read after them.
	const writes = [];
	let pending = 0;
	const isWrite = ( r ) => 'POST' === r.method() && /wp\/v2\/settings|wp%2Fv2%2Fsettings|visibility\/toggle|visibility%2Ftoggle/.test( r.url() );
	page.on( 'request', ( r ) => {
		if ( isWrite( r ) ) {
			writes.push( r.url() );
			pending++;
		}
	} );
	const done = ( r ) => { if ( isWrite( r ) ) pending--; };
	page.on( 'requestfinished', done );
	page.on( 'requestfailed', done );
	const settle = async () => {
		await page.waitForTimeout( 1500 );
		for ( let i = 0; pending > 0 && i < 60; i++ ) await page.waitForTimeout( 250 );
	};

	const author = await loginAs( browser, 'minn-author', 'minn-author-pass-1' );
	const authorPost = ( title, content ) => author.page.evaluate( async ( args ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts?context=edit', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: JSON.stringify( { ...args, status: 'publish' } ),
		} );
		const j = await r.json();
		return { id: j.id, link: j.link, raw: j.content && j.content.raw };
	}, { title, content } );

	const overlay = 'position:fixed;top:0;left:0;right:0;bottom:0;z-index:2147483647;opacity:0';
	const posts = [];
	// The real corner is the last one in the document (the bar renders on
	// wp_footer); everything before it is the post's.
	const real = () => page.locator( '#minn-cornerbar' ).last();
	const view = async ( link ) => {
		await page.goto( link, { waitUntil: 'domcontentloaded', timeout: 60000 } );
		await page.waitForFunction( () => window.MINN_BAR && document.querySelectorAll( '#minn-cornerbar' ).length, null, { timeout: 20000 } );
		await page.waitForTimeout( 600 );
	};
	// Click the middle of the post's first element matching sel (outside the
	// real corner), with a real mouse, and report what the click landed on.
	// Themes that animate the article in with a transform make a fixed
	// element fixed to the article rather than the viewport, so "anywhere"
	// means anywhere it covers, which is still the post the admin is reading.
	const clickContent = async ( sel ) => {
		const at = await page.evaluate( ( s ) => {
			const corners = document.querySelectorAll( '#minn-cornerbar' );
			const realCorner = corners[ corners.length - 1 ];
			const el = Array.from( document.querySelectorAll( s ) ).find( ( e ) => ! realCorner.contains( e ) );
			if ( ! el ) return null;
			const r = el.getBoundingClientRect();
			const x = Math.round( r.left + r.width / 2 );
			const y = Math.round( r.top + r.height / 2 );
			const hit = document.elementFromPoint( x, y );
			return { x, y, onIt: hit === el || el.contains( hit ) };
		}, sel );
		if ( at ) await page.mouse.click( at.x, at.y );
		return at;
	};
	const reveal = async () => {
		await real().locator( '.minn-bar-markbtn' ).hover();
		await page.waitForTimeout( 320 );
	};

	try {
		// --- A: an invisible full-screen element carrying the fix's id, and a
		// spoofed account menu with the bar's menu id. ---
		const a = await authorPost( 'Minn bar spoof A ' + Date.now(),
			`<p>Spoof fixture A.</p><div id="minn-bar-status-fix" style="${ overlay }"></div>`
			+ '<div id="minn-bar-menu-user" class="minn-bar-menu" hidden><a href="https://example.invalid/phish">Sign out</a></div>' );
		posts.push( a.id );
		t.check( 'kses keeps the author\'s spoof markup (precondition)',
			/id="minn-bar-status-fix"/.test( a.raw || '' ) && /position:fixed/.test( a.raw || '' ) && /id="minn-bar-menu-user"/.test( a.raw || '' ),
			( a.raw || '' ).slice( 0, 160 ) );

		await rest( 'POST', 'wp/v2/settings', { blog_public: 0 } );
		await view( a.link );
		writes.length = 0;
		const hitA = await clickContent( '#minn-bar-status-fix' );
		await settle();
		t.check( 'A: the click lands on the invisible content element (the repro is live)', !! ( hitA && hitA.onIt ), JSON.stringify( hitA ) );
		t.check( 'A: a click over a content element with the fix\'s id sends no settings write',
			writes.length === 0, writes.join( ' ' ) );
		t.check( 'A: the site stays hidden from search', 0 === await blogPublic() );

		// The account menu: the bar opens its own, never the content's.
		await rest( 'POST', 'wp/v2/settings', { blog_public: 0 } );
		await page.evaluate( () => {
			const spoof = document.querySelector( '#minn-bar-status-fix:not(#minn-cornerbar *)' );
			if ( spoof && spoof.style.position === 'fixed' ) spoof.remove();
			// The overlay sent the bar behind it; let it re-check.
			dispatchEvent( new Event( 'resize' ) );
		} );
		await page.waitForTimeout( 300 );
		await reveal();
		await real().locator( '[data-barmenu="minn-bar-menu-user"]' ).click();
		await page.waitForTimeout( 300 );
		const menus = await page.evaluate( () => {
			const all = Array.from( document.querySelectorAll( '#minn-bar-menu-user' ) );
			const corners = document.querySelectorAll( '#minn-cornerbar' );
			const corner = corners[ corners.length - 1 ];
			return all.map( ( m ) => ( { ours: corner.contains( m ), open: ! m.hidden } ) );
		} );
		t.check( 'A: the account button opens the bar\'s own menu, and the content\'s copy stays hidden',
			menus.some( ( m ) => m.ours && m.open ) && ! menus.some( ( m ) => ! m.ours && m.open ),
			JSON.stringify( menus ) );
		await page.keyboard.press( 'Escape' );

		// --- B: a <label for> aimed at the fix's id, nothing else. On the
		// unfixed bar this clicked the REAL button from anywhere. ---
		const b = await authorPost( 'Minn bar spoof B ' + Date.now(),
			`<p>Spoof fixture B.</p><label for="minn-bar-status-fix" style="${ overlay }">.</label>` );
		posts.push( b.id );
		await rest( 'POST', 'wp/v2/settings', { blog_public: 0 } );
		await view( b.link );
		writes.length = 0;
		const hitB = await clickContent( 'label[for="minn-bar-status-fix"]' );
		await settle();
		t.check( 'B: the click lands on the invisible label (the repro is live)', !! ( hitB && hitB.onIt ), JSON.stringify( hitB ) );
		t.check( 'B: a click on a <label for> aimed at the fix sends no settings write', writes.length === 0, writes.join( ' ' ) );
		t.check( 'B: the site stays hidden from search', 0 === await blogPublic() );

		// --- C: a counterfeit corner and root BEFORE the real bar, with its
		// own "confirm" button. The bar must bind to the corner the server
		// printed for this request, and only that one. ---
		const c = await authorPost( 'Minn bar spoof C ' + Date.now(),
			'<p>Spoof fixture C.</p><div id="minn-cornerbar" data-minn-bar="guess" style="position:static;width:auto;height:auto"><div id="minn-bar-root">'
			+ '<button type="button" id="minn-bar-status-fix" class="minn-bar-menu-item" data-barfix="confirm">Read more</button>'
			+ '</div></div>' );
		posts.push( c.id );
		await rest( 'POST', 'wp/v2/settings', { blog_public: 0 } );
		await view( c.link );
		writes.length = 0;
		const hitC = await clickContent( '#minn-cornerbar button' );
		await settle();
		t.check( 'C: the click lands on the counterfeit button (the repro is live)', !! ( hitC && hitC.onIt ), JSON.stringify( hitC ) );
		t.check( 'C: a counterfeit bar button in the content sends no settings write', writes.length === 0, writes.join( ' ' ) );
		t.check( 'C: the site stays hidden from search', 0 === await blogPublic() );

		// Control, on the same page, counterfeit still present: the real fix
		// works, and only on its confirm.
		await rest( 'POST', 'wp/v2/settings', { blog_public: 0 } );
		await view( c.link );
		writes.length = 0;
		let opened = false;
		try {
			await reveal();
			await real().locator( '.minn-bar-status' ).click( { timeout: 5000 } );
			await real().locator( '[data-barfix="ask"]' ).click( { timeout: 5000 } );
			opened = await real().locator( '[data-barfix="confirm"]' ).isVisible();
		} catch ( e ) {
			opened = false;
		}
		await page.waitForTimeout( 800 );
		t.check( 'control: the bar\'s fix asks first (a confirm, no write yet)', opened && writes.length === 0,
			JSON.stringify( { opened, writes } ) );
		let cancelled = false;
		try {
			await real().locator( '[data-barfix="cancel"]' ).click( { timeout: 5000 } );
			cancelled = await real().locator( '[data-barfix="ask"]' ).isVisible()
				&& ! await real().locator( '[data-barfix="confirm"]' ).isVisible();
		} catch ( e ) {
			cancelled = false;
		}
		t.check( 'control: Cancel backs out of the confirm without a write', cancelled && writes.length === 0,
			JSON.stringify( { cancelled, writes } ) );
		let fixed = false;
		try {
			await real().locator( '[data-barfix="ask"]' ).click( { timeout: 5000 } );
			await real().locator( '[data-barfix="confirm"]' ).click( { timeout: 5000 } );
			await page.waitForFunction( () => {
				const corners = document.querySelectorAll( '#minn-cornerbar' );
				return ! corners[ corners.length - 1 ].querySelector( '.minn-bar-status' );
			}, null, { timeout: 15000 } );
			fixed = true;
		} catch ( e ) {
			fixed = false;
		}
		t.check( 'control: confirming the bar\'s own fix makes the site visible to search engines',
			fixed && writes.length === 1 && 1 === await blogPublic(), JSON.stringify( { fixed, writes } ) );
	} finally {
		await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } ).catch( () => null );
		await page.waitForFunction( () => window.MINN && window.MINN.nonce, null, { timeout: 30000 } ).catch( () => null );
		for ( const id of posts ) {
			if ( id ) await rest( 'DELETE', 'wp/v2/posts/' + id + '?force=true' ).catch( () => null );
		}
		await rest( 'POST', 'wp/v2/settings', { blog_public: prevPublic } ).catch( () => null );
		await rest( 'POST', 'minn-admin/v1/me/appearance', { frontBar: prevFrontBar } ).catch( () => null );
		await author.ctx.close().catch( () => null );
	}
	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
