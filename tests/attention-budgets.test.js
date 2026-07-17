/**
 * Attention budgets (v1.0 gate G3): placement and count limits enforced by
 * the validator and the client, not by convention.
 *
 * 1. `group: 'workspace'` requires an inbox-shaped collection (an `ago`
 *    column); anything else degrades to Tools and the Integrations card
 *    flags it as a contract problem.
 * 2. One owner holds at most 3 nav slots: past that, family-less surfaces
 *    collapse into one synthetic family (one nav item, one palette row via
 *    the existing switcher mechanics). Informational note on the card.
 * 3. One namespace holds at most 3 default slash-menu slots; overflow
 *    demotes to search-only (still reachable by typing, never dropped).
 *
 * Fixture: minn_test_budget_fixtures arms a workspace-claiming non-inbox
 * surface, five family-less surfaces, and five default commands in one
 * namespace, all owned by the mu-plugin ("Minn Dev Fixtures").
 */
const { BASE, launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

( async () => {
	const t = reporter( 'attention-budgets' );
	const { browser, page, errors } = await launch();
	await login( page );

	const setOpt = async ( name, v ) => {
		for ( let attempt = 1; attempt <= 5; attempt++ ) {
			const stored = await page.evaluate( async ( a ) => {
				const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST', headers: h, credentials: 'same-origin',
					body: JSON.stringify( { [ a.name ]: a.v } ),
				} );
				const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), {
					headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} );
				return ( await r.json() )[ a.name ];
			}, { name, v } );
			if ( stored === v ) return true;
			await page.waitForTimeout( 800 );
		}
		return false;
	};

	const FAM = 'plugin-minn-dev-fixtures';
	let postId = null;
	try {
		t.check( 'budget fixtures armed', await setOpt( 'minn_test_budget_fixtures', true ) );

		/* ===== Boot payload: demotion + collapse (server-enforced) ===== */
		await page.goto( `${ BASE }/minn-admin/`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-nav-btn', { timeout: 20000 } );
		const boot = await page.evaluate( ( fam ) => {
			const mine = ( window.MINN.surfaces || [] ).filter( ( s ) => s.id.indexOf( 'minn-budget' ) === 0 );
			return {
				count: mine.length,
				wsGroup: ( mine.find( ( s ) => s.id === 'minn-budget-workspace' ) || {} ).group,
				allCollapsed: mine.every( ( s ) => s.family === fam ),
			};
		}, FAM );
		t.check( 'all six fixture surfaces ride the boot payload', boot.count === 6, JSON.stringify( boot ) );
		t.check( 'workspace claim without an ago column degrades to Tools', boot.wsGroup === 'tools' );
		t.check( 'over-budget owner collapses into one synthetic family', boot.allCollapsed );

		/* ===== Nav: one slot for the whole owner ===== */
		const nav = await page.evaluate( ( fam ) => ( {
			famButtons: document.querySelectorAll( `.minn-nav-btn[data-family="${ fam }"]` ).length,
			soloButtons: [ ...document.querySelectorAll( '.minn-nav-btn[data-nav^="minn-budget"]' ) ]
				.filter( ( b ) => b.dataset.family !== fam ).length,
		} ), FAM );
		t.check( 'nav shows exactly one slot for the owner', nav.famButtons === 1 && nav.soloButtons === 0, JSON.stringify( nav ) );

		/* ===== Palette: one row for the whole owner ===== */
		await page.keyboard.press( 'Meta+k' );
		await page.waitForSelector( '#minn-palette-input', { timeout: 5000 } );
		// The collapsed row is labeled by the PREFERRED member (here the
		// first registered, "Budget Workspace Claim") — query the shared word.
		await page.keyboard.type( 'Budget' );
		await page.waitForTimeout( 400 );
		const pal = await page.$$eval( '.minn-palette-item', ( els ) =>
			els.map( ( e ) => e.textContent.trim() ).filter( ( x ) => x.includes( 'Budget' ) ) );
		t.check( 'palette shows one row for the collapsed owner', pal.length === 1, JSON.stringify( pal ) );
		t.check( 'palette row names the provider count', /6 providers/.test( pal[ 0 ] || '' ), pal[ 0 ] );
		await page.keyboard.press( 'Escape' );

		/* ===== Switcher still reaches every member (degrade, not drop) ===== */
		const famNavId = await page.evaluate( ( fam ) =>
			( document.querySelector( `.minn-nav-btn[data-family="${ fam }"]` ) || {} ).dataset?.nav, FAM );
		await page.click( `.minn-nav-btn[data-family="${ FAM }"]` );
		await page.waitForSelector( '.minn-table-row, .minn-surface-status, .minn-topbar', { timeout: 20000 } );
		t.check( 'collapsed slot routes to a member surface', /^minn-budget/.test( famNavId || '' ), famNavId );

		/* ===== Slash budget: 3 default slots per namespace ===== */
		postId = await createPost( page, { title: 'Budget slash probe', content: '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->' } );
		await openEditor( page, postId );
		await page.waitForSelector( '#minn-editor-body', { timeout: 20000 } );
		await page.click( '#minn-editor-body' );
		await page.keyboard.press( 'Meta+a' );
		await page.keyboard.press( 'Delete' );
		await page.keyboard.type( '/' );
		await page.waitForSelector( '.minn-slash-item', { timeout: 10000 } );
		const defaultCmds = await page.$$eval( '.minn-slash-item', ( els ) =>
			els.map( ( e ) => e.textContent.trim() ).filter( ( x ) => x.includes( 'Budget Cmd' ) ) );
		t.check( 'default slash menu holds exactly 3 namespace entries', defaultCmds.length === 3, JSON.stringify( defaultCmds ) );
		t.check( 'the first three registered stay default',
			defaultCmds.some( ( x ) => x.includes( 'One' ) ) && defaultCmds.some( ( x ) => x.includes( 'Two' ) ) && defaultCmds.some( ( x ) => x.includes( 'Three' ) ) );
		await page.keyboard.type( 'budget cmd f' );
		await page.waitForTimeout( 400 );
		const searched = await page.$$eval( '.minn-slash-item', ( els ) =>
			els.map( ( e ) => e.textContent.trim() ).filter( ( x ) => x.includes( 'Budget Cmd' ) ) );
		t.check( 'overflow entries stay reachable by search',
			searched.some( ( x ) => x.includes( 'Four' ) ) && searched.some( ( x ) => x.includes( 'Five' ) ), JSON.stringify( searched ) );
		await page.keyboard.press( 'Escape' );

		/* ===== Integrations card: problem + informational note ===== */
		await page.goto( `${ BASE }/minn-admin/system`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-sys-integrations', { timeout: 20000 } );
		const card = await page.evaluate( () => ( {
			problems: [ ...document.querySelectorAll( '.minn-sys-int-problem' ) ].map( ( e ) => e.textContent ),
			notes: [ ...document.querySelectorAll( '.minn-sys-int-note' ) ].map( ( e ) => e.textContent ),
		} ) );
		t.check( 'workspace shape flagged as a contract problem',
			card.problems.some( ( p ) => p.includes( 'workspace is for inbox-shaped surfaces' ) ), card.problems.join( ' | ' ) );
		t.check( 'owner budget note shown, informationally',
			card.notes.some( ( p ) => /uses \d+ nav slots \(budget 3\); its family-less surfaces share one\./.test( p ) ), card.notes.join( ' | ' ) );

		/* ===== Family = one nav slot: 5 surfaces in one family stay within
		 * budget, no collapse, no note (the code-review family-count fix) ===== */
		await setOpt( 'minn_test_budget_fixtures', false );
		if ( ! await setOpt( 'minn_test_family_budget', true ) ) throw new Error( 'could not enable family budget fixture' );
		await page.goto( `${ BASE }/minn-admin/`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-nav-btn', { timeout: 20000 } );
		const fam = await page.evaluate( () => {
			const mine = ( window.MINN.surfaces || [] ).filter( ( s ) => s.id.indexOf( 'minn-fambudget' ) === 0 );
			return {
				count: mine.length,
				allRealFamily: mine.every( ( s ) => s.family === 'minn-fambudget' ),
				noSynthetic: mine.every( ( s ) => ! /^plugin-/.test( s.family || '' ) ),
			};
		} );
		t.check( 'five family surfaces ride the payload', fam.count === 5, JSON.stringify( fam ) );
		t.check( 'a shared family is ONE nav slot, kept as-is (not collapsed)', fam.allRealFamily && fam.noSynthetic, JSON.stringify( fam ) );
		await page.goto( `${ BASE }/minn-admin/system`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-sys-integrations', { timeout: 20000 } );
		const famNotes = await page.evaluate( () =>
			[ ...document.querySelectorAll( '.minn-sys-int-note' ) ].map( ( e ) => e.textContent ) );
		t.check( 'no budget note for a within-budget family owner', famNotes.length === 0, famNotes.join( ' | ' ) );

		/* ===== Cleanup restores a clean registry ===== */
		await setOpt( 'minn_test_family_budget', false );
		await page.goto( `${ BASE }/minn-admin/system`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-sys-integrations', { timeout: 20000 } );
		const after = await page.evaluate( () => ( {
			problems: document.querySelectorAll( '.minn-sys-int-problem' ).length,
			notes: document.querySelectorAll( '.minn-sys-int-note' ).length,
		} ) );
		t.check( 'registry clean again after disarming', after.problems === 0 && after.notes === 0, JSON.stringify( after ) );
	} finally {
		if ( postId ) await deletePost( page, postId ).catch( () => {} );
		await setOpt( 'minn_test_budget_fixtures', false );
		await setOpt( 'minn_test_family_budget', false );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
