/**
 * A custom type's own taxonomies in the editor: one chip picker per
 * taxonomy the type shows in REST, under the Settings door. The item's
 * terms load picked, a chip toggles one, a name typed and Entered picks the
 * term by that name (made when there is none), and a save sends the
 * taxonomy's field (its rest_base) with exactly the picked terms. Posts keep
 * their own Categories and Tags. The mu-fixture option minn_test_custom_tax
 * arms a minn_wiki type with a hierarchical minn_wiki_topic taxonomy.
 */
const { BASE, launch, login, reporter, onExit } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'editor-custom-taxonomies' );
	await login( page );
	await page.goto( `${ BASE }/minn-admin/overview`, { waitUntil: 'domcontentloaded' } );
	await page.waitForSelector( '.minn-scroll', { timeout: 15000 } );

	const rest = ( path, method = 'GET', body = null ) => page.evaluate( async ( [ p, m, b ] ) => {
		const r = await fetch( window.MINN.restUrl + p, {
			method: m,
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			body: b ? JSON.stringify( b ) : undefined,
		} );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, [ path, method, body ] );

	// Write-then-verify with retries: a settings write can race the app's boot requests.
	const setOpt = async ( v ) => {
		for ( let attempt = 1; attempt <= 5; attempt++ ) {
			await rest( 'wp/v2/settings', 'POST', { minn_test_custom_tax: v } );
			const r = await rest( 'wp/v2/settings?_cb=' + Math.random() );
			if ( r.body && r.body.minn_test_custom_tax === v ) return true;
			await page.waitForTimeout( 800 );
		}
		return false;
	};

	const made = { entry: 0, terms: [] };
	const cleanup = async () => {
		if ( made.entry ) await rest( `wp/v2/minn-wiki/${ made.entry }?force=true`, 'DELETE' ).catch( () => {} );
		for ( const id of made.terms ) await rest( `wp/v2/minn-wiki-topics/${ id }?force=true`, 'DELETE' ).catch( () => {} );
		await setOpt( '' ).catch( () => {} );
	};
	onExit( () => {} );

	try {
		t.check( 'custom taxonomy fixture armed', await setOpt( '1' ), '' );
		const alpha = await rest( 'wp/v2/minn-wiki-topics', 'POST', { name: 'Zz Alpha' } );
		t.check( 'a topic exists to start from', alpha.status === 201, JSON.stringify( alpha.body && ( alpha.body.id || alpha.body.code ) ) );
		made.terms.push( alpha.body.id );
		const entry = await rest( 'wp/v2/minn-wiki', 'POST', { title: 'Zz custom taxonomy entry', status: 'draft', content: '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->', 'minn-wiki-topics': [ alpha.body.id ] } );
		t.check( 'an entry in that topic exists', entry.status === 201, JSON.stringify( entry.body && entry.body.id ) );
		made.entry = entry.body.id;

		// A fresh boot, so the app's type list carries the type the option registered.
		await page.goto( `${ BASE }/minn-admin/editor/minn-wiki/${ made.entry }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-editor-body', { timeout: 20000 } );
		await page.waitForSelector( '[data-side-door="settings"]', { timeout: 15000 } );
		await page.click( '[data-side-door="settings"]' );
		await page.waitForSelector( '[data-taxterm="minn-wiki-topics"]', { timeout: 15000 } );
		const chips = () => page.$$eval( '[data-taxterm="minn-wiki-topics"]', ( c ) => c.map( ( x ) => x.textContent + ( x.classList.contains( 'sel' ) ? '*' : '' ) ) );
		t.check( 'the Settings door shows the type\'s taxonomy by its name, the entry\'s topic picked', ( await page.textContent( '.minn-editor-door-body' ) ).includes( 'Topics' ) && ( await chips() ).includes( 'Zz Alpha*' ), JSON.stringify( await chips() ) );

		await page.fill( '[data-taxnew="minn-wiki-topics"]', 'Zz Beta' );
		await page.press( '[data-taxnew="minn-wiki-topics"]', 'Enter' );
		await page.waitForFunction( () => [ ...document.querySelectorAll( '[data-taxterm="minn-wiki-topics"]' ) ].some( ( c ) => c.textContent === 'Zz Beta' && c.classList.contains( 'sel' ) ), null, { timeout: 15000 } );
		t.check( 'a name Entered makes the topic and picks it, the field keeping focus', await page.evaluate( () => document.activeElement && document.activeElement.dataset.taxnew === 'minn-wiki-topics' ), '' );
		const beta = await rest( 'wp/v2/minn-wiki-topics?search=Zz%20Beta&_fields=id,name' );
		const betaId = ( ( beta.body || [] ).find( ( x ) => x.name === 'Zz Beta' ) || {} ).id || 0;
		if ( betaId ) made.terms.push( betaId );
		t.check( 'the new topic is stored', betaId > 0, JSON.stringify( beta.body ) );

		// Entering the same name again picks it, never a duplicate.
		await page.fill( '[data-taxnew="minn-wiki-topics"]', 'zz beta' );
		await page.press( '[data-taxnew="minn-wiki-topics"]', 'Enter' );
		await page.waitForTimeout( 1200 );
		const again = await rest( 'wp/v2/minn-wiki-topics?search=Zz%20Beta&_fields=id' );
		t.check( 'a name already taken (in any case) picks that topic, making no second one', ( again.body || [] ).length === 1, JSON.stringify( again.body ) );

		await page.click( '[data-taxterm="minn-wiki-topics"]:has-text("Zz Alpha")' );
		t.check( 'a chip toggles its topic off', ( await chips() ).includes( 'Zz Alpha' ), JSON.stringify( await chips() ) );

		const sent = [];
		page.on( 'request', ( r ) => {
			if ( r.method() === 'POST' && new RegExp( `/minn-wiki/${ made.entry }(\\?|$)` ).test( r.url() ) ) sent.push( r.postData() || '' );
		} );
		await page.keyboard.press( 'Escape' );
		await page.waitForTimeout( 300 );
		await page.keyboard.press( 'Meta+s' );
		await page.waitForFunction( () => ! document.querySelector( '.minn-modal' ), null, { timeout: 5000 } ).catch( () => {} );
		await page.waitForTimeout( 2500 );
		const sentTopics = sent.map( ( b ) => {
			try {
				return JSON.parse( b )[ 'minn-wiki-topics' ];
			} catch ( e ) {
				return undefined;
			}
		} ).filter( ( x ) => x !== undefined );
		t.check( 'the save sends the taxonomy field with exactly the picked topics', sentTopics.length > 0 && JSON.stringify( sentTopics[ sentTopics.length - 1 ] ) === JSON.stringify( [ betaId ] ), JSON.stringify( sent ) );
		const stored = await rest( `wp/v2/minn-wiki/${ made.entry }?context=edit&_fields=minn-wiki-topics` );
		t.check( 'the entry is stored in the new topic only', JSON.stringify( stored.body && stored.body[ 'minn-wiki-topics' ] ) === JSON.stringify( [ betaId ] ), JSON.stringify( stored.body ) );

		// A post keeps its own Categories and Tags, with no per-taxonomy picker.
		const post = await rest( 'wp/v2/posts', 'POST', { title: 'Zz custom taxonomy post', status: 'draft' } );
		await page.goto( `${ BASE }/minn-admin/editor/posts/${ post.body.id }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-side-door="settings"]', { timeout: 20000 } );
		await page.click( '[data-side-door="settings"]' );
		await page.waitForSelector( '#minn-editor-cats', { timeout: 15000 } );
		t.check( 'a post shows Categories and no per-taxonomy picker', ( await page.$$( '[data-taxterm]' ) ).length === 0, '' );
		await page.keyboard.press( 'Escape' );
		await rest( `wp/v2/posts/${ post.body.id }?force=true`, 'DELETE' );
	} catch ( e ) {
		t.check( 'suite ran to the end', false, e.message );
	} finally {
		await cleanup();
		await t.done( browser, errors );
	}
} )();
