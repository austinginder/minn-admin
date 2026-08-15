/**
 * Island links: a block whose link lives only in its saved HTML (a Stackable
 * button — its server attr schema is empty, so no attr form ever showed the
 * href) gets a Links section in the ⚙ inspector. Edits apply as global
 * quote-bounded URL replacement (swapIslandLink), so comment-attr mirrors
 * stay in sync and everything else stays byte-identical. Dangerous schemes
 * are refused at the swap. Verified against SAVED markup.
 *
 * The fixture is the real cabinetjoint button-group shape (block-scoped
 * <style>, stk classes, anchor with span text).
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

const OLD_URL = 'https://example.com/get-a-quote/';
const NEW_URL = 'https://example.com/contact-us/';

const GROUP = `<!-- wp:stackable/button-group {"uniqueId":"c1ac7fd","contentAlign":"center"} -->
<div class="wp-block-stackable-button-group stk-block-button-group stk-block stk-c1ac7fd" data-block-id="c1ac7fd"><div class="stk-row stk-inner-blocks has-text-align-center stk-block-content stk-button-group"><!-- wp:stackable/button {"uniqueId":"ea431e8","buttonBackgroundColor":"#2274A5"} -->
<div class="wp-block-stackable-button stk-block-button stk-block stk-ea431e8" data-block-id="ea431e8"><style>.stk-ea431e8 .stk-button{background:#2274A5 !important;}</style><a class="stk-link stk-button stk--hover-effect-darken" href="${ OLD_URL }"><span class="stk-button__inner-text">Get a Quote</span></a></div>
<!-- /wp:stackable/button --></div></div>
<!-- /wp:stackable/button-group -->`;

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'island-links' );
	await login( page );

	const id = await createPost( page, { title: 'Island links probe', content: GROUP, status: 'draft' } );
	const readRaw = () => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content.raw', { headers: { 'X-WP-Nonce': window.MINN.nonce } } );
		return ( await r.json() ).content.raw;
	}, id );
	// The create's one-time core normalization is the baseline, not the
	// constant above (the rule-19 fixed point).
	const baseline = await readRaw();

	await openEditor( page, id );
	await page.waitForSelector( '.minn-block-island', { timeout: 15000 } );

	// Chip clicks can race the async preview swap — retry until the popover
	// carries the Links input (the undo-toast lesson).
	const openInspector = async () => {
		for ( let i = 0; i < 4; i++ ) {
			await page.click( '.minn-island-chip' ).catch( () => {} );
			try {
				await page.waitForSelector( '.minn-inspector [data-insplink]', { timeout: 4000 } );
				return true;
			} catch ( e ) { /* retry */ }
		}
		return false;
	};

	t.check( 'inspector offers the Links section', await openInspector() );
	t.check( 'the button href seeds the link input',
		await page.$eval( '.minn-inspector [data-insplink="0"]', ( e ) => e.value ) === OLD_URL );

	/* ===== Edit the URL, Apply, save, verify ===== */
	await page.fill( '.minn-inspector [data-insplink="0"]', NEW_URL );
	await page.click( '#minn-insp-apply' );
	await page.waitForTimeout( 1200 );
	await page.keyboard.press( 'Meta+s' );
	await page.waitForTimeout( 1500 );
	let raw = await readRaw();
	t.check( 'new href persisted in the saved HTML', raw.includes( `href="${ NEW_URL }"` ), raw.slice( 0, 160 ) );
	t.check( 'old URL is gone everywhere', ! raw.includes( OLD_URL ) );
	t.check( 'everything else stayed byte-identical',
		raw.split( NEW_URL ).join( OLD_URL ) === baseline );

	/* ===== A script-running URL is refused at the swap ===== */
	t.check( 'inspector reopens with the updated link', await openInspector()
		&& await page.$eval( '.minn-inspector [data-insplink="0"]', ( e ) => e.value ) === NEW_URL );
	await page.fill( '.minn-inspector [data-insplink="0"]', 'javascript:alert(1)' );
	await page.click( '#minn-insp-apply' );
	await page.waitForTimeout( 1200 );
	await page.keyboard.press( 'Meta+s' );
	await page.waitForTimeout( 1500 );
	raw = await readRaw();
	t.check( 'dangerous scheme never lands in the markup',
		! raw.includes( 'javascript:' ) && raw.includes( `href="${ NEW_URL }"` ) );

	await deletePost( page, id );
	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
