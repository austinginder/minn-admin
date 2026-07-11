/**
 * Settings reorganized by intent (v0.11.0). WordPress's General/Writing/
 * Reading/Discussion/Permalinks tabs are gone; the five job-based tabs are
 * Site, Visibility, Homepage, Content, Comments. Search-engine visibility now
 * sits with maintenance mode + membership under Visibility; Permalinks fold
 * into Content (own endpoint); Spam folds into Comments (own endpoint). The
 * section nav is sticky. A single Save runs whichever endpoints the current
 * tab shows.
 *
 * Verifies the tab set, key field placement, the sticky nav, and — the
 * riskiest part — that one Save on the Content tab persists BOTH a core
 * setting and a permalink change. Restores everything in the finally.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'settings-layout' );
	const { browser, page, errors } = await launch();
	await login( page );

	const rest = ( path ) => page.evaluate( async ( p ) => {
		const r = await fetch( window.MINN.restUrl + p + ( p.includes( '?' ) ? '&' : '?' ) + '_cb=' + Math.random(), { headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin' } );
		return r.ok ? r.json() : null;
	}, path );
	const openTab = async ( name ) => {
		await page.evaluate( ( n ) => { [ ...document.querySelectorAll( '.minn-settings-nav-item' ) ].find( ( b ) => b.textContent.trim() === n ).click(); }, name );
		await page.waitForTimeout( 300 );
	};

	let origTagline = null, origCatBase = null;
	try {
		await page.goto( BASE + '/minn-admin/settings', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-settings-nav-item', { timeout: 20000 } );

		/* ===== The five intent tabs ===== */
		const tabs = await page.$$eval( '.minn-settings-nav-item', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		t.check( 'tabs are Site / Visibility / Homepage / Content / Comments',
			JSON.stringify( tabs ) === JSON.stringify( [ 'Site', 'Visibility', 'Homepage', 'Content', 'Comments' ] ), JSON.stringify( tabs ) );
		t.check( 'no WordPress tabs remain', ! tabs.some( ( x ) => [ 'General', 'Reading', 'Writing', 'Discussion', 'Permalinks' ].includes( x ) ) );

		/* ===== Section nav is sticky ===== */
		t.check( 'section nav is sticky', ( await page.$eval( '.minn-settings-nav', ( el ) => getComputedStyle( el ).position ) ) === 'sticky' );

		/* ===== Visibility groups search-engine + maintenance + membership ===== */
		await openTab( 'Visibility' );
		const vis = await page.evaluate( () => ( {
			blog: !! document.querySelector( '[data-setting="blog_public"]' ),
			maint: !! document.querySelector( '[data-setting="minn_admin_maintenance"]' ),
			member: !! document.querySelector( '[data-setting="users_can_register"]' ),
			role: !! document.querySelector( '[data-combo="default_role"]' ),
		} ) );
		t.check( 'Visibility holds search-engine + maintenance + membership + role', vis.blog && vis.maint && vis.member && vis.role, JSON.stringify( vis ) );

		/* ===== Content merges writing defaults + permalinks ===== */
		await openTab( 'Content' );
		const content = await page.evaluate( () => ( {
			cat: !! document.querySelector( '[data-combo="default_category"]' ),
			perma: !! document.querySelector( '[data-permakey="structure"]' ),
			urlsSub: [ ...document.querySelectorAll( '.minn-fields-sub' ) ].some( ( e ) => /URLs/.test( e.textContent ) ),
		} ) );
		t.check( 'Content holds content defaults + a URLs (permalinks) subsection', content.cat && content.perma && content.urlsSub, JSON.stringify( content ) );

		/* ===== Comments merges discussion + spam ===== */
		await openTab( 'Comments' );
		const comments = await page.evaluate( () => ( {
			allow: !! document.querySelector( '[data-setting="default_comment_status"]' ),
			spamSub: [ ...document.querySelectorAll( '.minn-fields-sub' ) ].some( ( e ) => /Spam/.test( e.textContent ) ),
		} ) );
		t.check( 'Comments holds discussion toggles + a Spam subsection', comments.allow && comments.spamSub, JSON.stringify( comments ) );

		/* ===== One Save on Content persists BOTH a setting and a permalink ===== */
		await openTab( 'Content' );
		await page.waitForSelector( '[data-permakey="category_base"]', { timeout: 8000 } );
		const before = await rest( 'wp/v2/settings' );
		origCatBase = ( await rest( 'minn-admin/v1/permalinks' ) ).category_base || '';
		// Change a core setting (default post format) AND a permalink (category base).
		await page.selectOption( '[data-key="default_post_format"]', 'aside' );
		await page.fill( '[data-permakey="category_base"]', 'topics' );
		await page.click( '#minn-save-settings' );
		await page.waitForTimeout( 1500 );
		const afterSettings = await rest( 'wp/v2/settings' );
		const afterPerma = await rest( 'minn-admin/v1/permalinks' );
		t.check( 'the core setting saved (default post format)', afterSettings.default_post_format === 'aside', afterSettings.default_post_format );
		t.check( 'the permalink saved in the same click', afterPerma.category_base === 'topics', afterPerma.category_base );
		// Restore both.
		await page.selectOption( '[data-key="default_post_format"]', String( before.default_post_format || 'standard' ) );
		await page.fill( '[data-permakey="category_base"]', origCatBase );
		await page.click( '#minn-save-settings' );
		await page.waitForTimeout( 1500 );
		const restored = await rest( 'minn-admin/v1/permalinks' );
		t.check( 'permalink restored', ( restored.category_base || '' ) === origCatBase );

	} finally {
		// Belt-and-suspenders: make sure the category base is not left as "topics".
		if ( origCatBase !== null ) {
			await page.evaluate( async ( cb ) => {
				await fetch( window.MINN.restUrl + 'minn-admin/v1/permalinks', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin', body: JSON.stringify( { category_base: cb } ) } );
			}, origCatBase ).catch( () => {} );
		}
	}
	await t.done( browser, errors );
} )();
