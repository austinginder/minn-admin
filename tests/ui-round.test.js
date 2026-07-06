/**
 * Shell/overview round: clickable version badge → changelog modal (markdown
 * rendered client-side), clickable overview stat cards, entity-decoded
 * activity feed with (no title) fallback, the global nav show/hide tab, and
 * the profile modal's role combobox (verified against the SAVED role).
 */
const { BASE, launch, login, createPost, deletePost, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'ui-round' );
	await login( page );

	/* ===== Fixtures the activity feed will lead with ===== */
	const entPost = await createPost( page, { title: 'Entity probe: Let&#8217;s go for it', content: '<p>x</p>', status: 'draft' } );
	const blankPost = await createPost( page, { title: '', content: '<p>y</p>', status: 'draft' } );

	await page.goto( `${ BASE }/minn-admin/`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-activity-row', { timeout: 15000 } );

	/* ===== Activity feed reads like prose ===== */
	const feed = await page.$$eval( '.minn-activity-text', ( els ) => els.map( ( e ) => e.textContent ).join( ' | ' ) );
	t.check( 'entities decode in the activity feed', feed.includes( 'Let’s go for it' ) && ! feed.includes( '#8217' ), feed.slice( 0, 120 ) );
	t.check( 'untitled drafts read (no title), never empty quotes', feed.includes( '(no title)' ) && ! feed.includes( '““' ) && ! feed.includes( '“”' ), '' );

	/* ===== Stat cards navigate ===== */
	const statCards = await page.$$eval( '.minn-stat.clickable', ( els ) => els.map( ( e ) => e.dataset.goto ) );
	t.check( 'stat cards are clickable doors', statCards.length >= 3, JSON.stringify( statCards ) );
	await page.click( '.minn-stat[data-goto="content:posts"]' );
	await page.waitForSelector( '.minn-content-cols.minn-table-row', { timeout: 15000 } );
	const tab = await page.$eval( '.minn-tab.active', ( e ) => e.textContent.trim() );
	t.check( 'Published posts card lands on the Posts tab', tab === 'Posts', tab );

	/* ===== Version badge → changelog modal ===== */
	await page.click( '#minn-ver-btn' );
	await page.waitForSelector( '.minn-changelog h3', { timeout: 10000 } );
	const log = await page.evaluate( () => ( {
		title: document.querySelector( '.minn-modal-title' ).textContent,
		heads: document.querySelectorAll( '.minn-changelog h3' ).length,
		bullets: document.querySelectorAll( '.minn-changelog li' ).length,
		// "**v…" would mean the version headings failed to render; a bare
		// "##" can legitimately appear inside code spans, so don't match it.
		raw: /\*\*v\d/.test( document.querySelector( '.minn-changelog' ).textContent ),
	} ) );
	t.check( 'changelog modal renders versions + bullets, no raw markdown', /What's new/.test( log.title ) && log.heads >= 3 && log.bullets > 10 && ! log.raw, JSON.stringify( log ) );
	await page.keyboard.press( 'Escape' );
	await page.waitForTimeout( 300 );

	/* ===== Global nav tab ===== */
	t.check( 'nav tab exists at the left edge', !! ( await page.$( '#minn-nav-tab' ) ), '' );
	await page.click( '#minn-nav-tab' );
	await page.waitForTimeout( 400 );
	const navHidden = await page.evaluate( () => ( {
		cls: document.body.classList.contains( 'minn-nav-hidden' ),
		w: document.querySelector( '.minn-sidebar' ).offsetWidth,
	} ) );
	t.check( 'nav tab hides the sidebar', navHidden.cls && navHidden.w < 10, JSON.stringify( navHidden ) );
	await page.reload( { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '#minn-nav-tab', { timeout: 15000 } );
	t.check( 'hidden nav persists across reloads', await page.evaluate( () => document.body.classList.contains( 'minn-nav-hidden' ) ), '' );
	await page.click( '#minn-nav-tab' );
	await page.waitForTimeout( 400 );
	t.check( 'nav tab restores the sidebar', await page.evaluate( () => ! document.body.classList.contains( 'minn-nav-hidden' ) && document.querySelector( '.minn-sidebar' ).offsetWidth > 100 ), '' );

	/* ===== Role combobox in the user modal (real save round-trip) ===== */
	await page.goto( `${ BASE }/minn-admin/users`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-user-cols.minn-table-row', { timeout: 15000 } );
	await page.locator( '.minn-user-cols.minn-table-row', { hasText: 'minn-editor' } ).first().click();
	await page.waitForSelector( '#minn-uf-role', { timeout: 10000 } );
	t.check( 'role field is the strict combobox showing the label', await page.$eval( '#minn-uf-role', ( i ) => i.classList.contains( 'minn-ac-input' ) && i.value === 'Editor' ), '' );
	await page.click( '#minn-uf-role' );
	await page.keyboard.type( 'auth' );
	await page.waitForTimeout( 250 );
	await page.keyboard.press( 'Enter' );
	await page.click( '#minn-uf-save' );
	await page.waitForTimeout( 1500 );
	const savedRole = await page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/users?context=edit&search=minn-editor&_fields=id,roles', { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return ( await r.json() )[ 0 ];
	} );
	t.check( 'picked role persists over REST', savedRole && savedRole.roles.join() === 'author', JSON.stringify( savedRole ) );
	// Revert the fixture account.
	await page.evaluate( async ( uid ) => {
		await fetch( window.MINN.restUrl + 'wp/v2/users/' + uid, {
			method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: JSON.stringify( { roles: [ 'editor' ] } ),
		} );
	}, savedRole.id );

	await deletePost( page, entPost );
	await deletePost( page, blankPost );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
