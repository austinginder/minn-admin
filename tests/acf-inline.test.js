/**
 * ACF block copy, edited in place.
 *
 * ACF Pro v3 blocks keep their copy in the comment's `data` object, and ACF
 * defines an inline-editing contract for them: acf_inline_text_editing_attrs()
 * marks an element with the field it shows, but only during ACF's preview
 * render. This suite pins both halves: render-blocks previews ACF v3 blocks
 * through ACF's preview path (so the markers and $is_preview reach Minn), and
 * the editor arms preview text as in-place runs that splice the quoted JSON
 * value. Marked blocks arm exactly their marked fields; unmarked blocks arm
 * text and textarea fields whose value appears once. A value shown through a
 * shortcode arms as one run with the shortcode's output as an atomic token:
 * words are typed around it, a click on the token edits its source (with the
 * choices minn_admin_shortcode_hints describes) or removes it, Backspace
 * removes it whole, and typing [ offers the described shortcodes (Escape
 * types a plain bracket). Every save writes the shortcode back as stored. A value that reads the same as other
 * text, or one the template never reads (static copy that happens to match
 * it), stays sidebar-only. A textarea shown one line per list item arms each
 * line on its own. A run inside a link takes the click instead of following
 * the link.
 * A value ACF's own inline editing stored entity-encoded ("&amp;") pairs on
 * the text it shows and stays byte-identical until typed over.
 *
 * Fixture: acf/minn-inline in minn-dev-fixtures.php. SKIPs (exit 0) without
 * ACF Pro.
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

const block = ( data ) => {
	const d = {};
	const keys = { kicker: 'field_minn_acfi_kicker', headline: 'field_minn_acfi_headline', body: 'field_minn_acfi_body', since: 'field_minn_acfi_since', note: 'field_minn_acfi_note', cta: 'field_minn_acfi_cta', button: 'field_minn_acfi_button', points: 'field_minn_acfi_points', stats: 'field_minn_acfi_stats', markers: 'field_minn_acfi_markers' };
	Object.keys( data ).forEach( ( k ) => {
		d[ k ] = data[ k ];
		d[ '_' + k ] = keys[ k ];
	} );
	return '<!-- wp:acf/minn-inline ' + JSON.stringify( { name: 'acf/minn-inline', data: d, mode: 'preview' } ) + ' /-->';
};

( async () => {
	const t = reporter( 'acf-inline' );
	const { browser, page, errors } = await launch();
	await login( page );

	const hasBlock = await page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/block-types/acf/minn-inline', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		return r.ok;
	} );
	if ( ! hasBlock ) {
		console.log( 'SKIP  acf/minn-inline is not registered (ACF Pro inactive?) — suite not run' );
		await browser.close();
		process.exit( 0 );
	}

	const marked = block( { kicker: 'Work', headline: 'Alpha &amp; headline', body: 'Body copy alpha', since: 'Since [minn_fixture_year]', note: 'Read more', button: 'Book a call', markers: '1' } );
	const plain = block( { kicker: 'Work', headline: 'Bravo headline', body: 'Body copy bravo', since: 'Since [minn_fixture_year]', note: 'Read more', cta: 'Learn more', points: 'First point\nSecond point', stats: '[minn_fixture_fact key="years"] years and [minn_fixture_fact key="sites"] sites', markers: '0' } );

	// Server half: the render route previews ACF v3 blocks through ACF.
	const rendered = await page.evaluate( async ( blocks ) => {
		const r = await fetch( window.MINN.restUrl + 'minn-admin/v1/render-blocks', {
			method: 'POST',
			headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' },
			body: JSON.stringify( { blocks } ),
		} );
		return ( await r.json() ).rendered || [];
	}, [ marked, plain ] );
	t.check( 'render-blocks renders ACF blocks as a preview', ( rendered[ 0 ] || '' ).includes( '>preview<' ) && ( rendered[ 1 ] || '' ).includes( '>preview<' ) );
	t.check( 'ACF inline markers reach the preview',
		( rendered[ 0 ] || '' ).includes( 'data-acf-inline-contenteditable-field-slug="kicker"' )
		&& ( rendered[ 0 ] || '' ).includes( 'data-acf-inline-contenteditable-field-slug="headline"' ) );
	t.check( 'an unmarked template carries no markers', ! ( rendered[ 1 ] || '' ).includes( 'data-acf-inline' ) );
	t.check( 'an entity-encoded value renders once-escaped', ( rendered[ 0 ] || '' ).includes( '>Alpha &amp; headline<' ) );
	t.check( 'the shortcode field renders its result', ( rendered[ 0 ] || '' ).includes( 'Since 2026' ) );
	const readList = ( ( rendered[ 1 ] || '' ).match( /data-minn-acf-read="([^"]*)"/ ) || [] )[ 1 ] || '';
	t.check( 'the preview lists the fields the template read', readList.split( ' ' ).includes( 'headline' ) && ! readList.split( ' ' ).includes( 'cta' ), readList );

	const id = await createPost( page, { title: 'ACF inline edit test', content: marked + '\n\n' + plain } );

	const rawContent = () => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content', {
			headers: { 'X-WP-Nonce': window.MINN.nonce },
		} );
		return ( await r.json() ).content.raw;
	}, id );
	const save = async ( expectFn ) => {
		await page.keyboard.press( 'Meta+s' );
		for ( let i = 0; i < 20; i++ ) {
			await page.waitForTimeout( 900 );
			const raw = await rawContent();
			if ( ! expectFn || expectFn( raw ) ) return raw;
		}
		return rawContent();
	};
	const dataOf = ( raw, n ) => {
		const all = [ ...raw.matchAll( /<!-- wp:acf\/minn-inline ([\s\S]*?) \/-->/g ) ];
		try { return JSON.parse( all[ n ][ 1 ] ).data; } catch ( e ) { return null; }
	};
	const runTexts = ( n ) => page.$$eval( '.minn-block-island[data-block="acf/minn-inline"]', ( els, i ) =>
		[ ...els[ i ].querySelectorAll( '.minn-island-run' ) ].map( ( s ) => s.textContent.trim() ), n );
	// Real mouse: preview chrome is pointer-events:none, so a synthetic click
	// would pass even if the run were unreachable.
	const typeInto = async ( n, text, replacement ) => {
		const loc = page.locator( '.minn-block-island[data-block="acf/minn-inline"]' ).nth( n )
			.locator( '.minn-island-run', { hasText: text } ).first();
		await loc.scrollIntoViewIfNeeded();
		const box = await loc.boundingBox();
		await page.mouse.click( box.x + box.width / 2, box.y + box.height / 2 );
		await page.keyboard.press( 'Meta+a' );
		await page.keyboard.type( replacement );
	};
	const island = ( n ) => page.locator( '.minn-block-island[data-block="acf/minn-inline"]' ).nth( n );
	const clickAt = async ( loc, opts ) => {
		await loc.scrollIntoViewIfNeeded();
		const box = await loc.boundingBox();
		await page.mouse.click( box.x + box.width / 2, box.y + box.height / 2, opts );
	};
	// Caret at the end of a run: click it, then ⌘→ (End scrolls on macOS).
	const caretEnd = async ( n, text ) => {
		await clickAt( island( n ).locator( '.minn-island-run', { hasText: text } ).first() );
		await page.keyboard.press( 'Meta+ArrowRight' );
	};
	const tokens = ( n, sel ) => page.$$eval( '.minn-block-island[data-block="acf/minn-inline"]', ( els, [ i, q ] ) =>
		[ ...els[ i ].querySelectorAll( q + ' .minn-sc-token' ) ].map( ( tk ) => ( { text: tk.textContent, sc: tk.dataset.sc, ce: tk.getAttribute( 'contenteditable' ) } ) ), [ n, sel ] );

	try {
		await openEditor( page, id );
		await page.waitForSelector( '.minn-block-island[data-block="acf/minn-inline"] .minn-island-run', { timeout: 45000 } );
		// Both islands arm; wait for the second one too.
		await page.waitForFunction( () => {
			const els = document.querySelectorAll( '.minn-block-island[data-block="acf/minn-inline"]' );
			return els.length === 2 && els[ 1 ].querySelector( '.minn-island-run' );
		}, null, { timeout: 20000 } ).catch( () => {} );

		const a = await runTexts( 0 );
		t.check( 'marked block arms its marked fields and its shortcode line only', a.length === 4 && [ 'Work', 'Alpha & headline', 'Book a call', 'Since 2026' ].every( ( x ) => a.includes( x ) ), JSON.stringify( a ) );
		t.check( 'marked block leaves the static crumb alone',
			await page.$eval( '.minn-block-island[data-block="acf/minn-inline"] .acfi-crumb', ( el ) => ! el.querySelector( '.minn-island-run' ) && ! el.closest( '.minn-island-run' ) ) );

		const b = await runTexts( 1 );
		t.check( 'unmarked block arms unique text and textarea values', b.includes( 'Bravo headline' ) && b.includes( 'Body copy bravo' ), JSON.stringify( b ) );
		t.check( 'a value that reads like other text stays sidebar-only', ! b.includes( 'Work' ) && ! b.includes( 'Read more' ) );
		const since = await tokens( 1, '.acfi-since' );
		t.check( 'a shortcode line arms as one run with its output as a token', b.includes( 'Since 2026' ) && since.length === 1 && since[ 0 ].text === '2026' && since[ 0 ].sc === '[minn_fixture_year]' && since[ 0 ].ce === 'false', JSON.stringify( since ) );
		const stats = await tokens( 1, '.acfi-stats' );
		t.check( 'each shortcode in a line is its own token', b.includes( '15+ years and 200+ sites' ) && stats.length === 2 && stats[ 0 ].text === '15+' && stats[ 1 ].text === '200+', JSON.stringify( stats ) );
		t.check( 'a field the template never reads stays sidebar-only', ! b.includes( 'Learn more' ) );
		t.check( 'each line of a listed textarea arms on its own', b.includes( 'First point' ) && b.includes( 'Second point' ) );

		const before = await rawContent();
		const plainBefore = ( before.match( /<!-- wp:acf\/minn-inline [\s\S]*? \/-->/g ) || [] )[ 1 ];

		await typeInto( 0, 'Work', 'Projects' );
		const raw1 = await save( ( r ) => ( dataOf( r, 0 ) || {} ).kicker === 'Projects' );
		const d1 = dataOf( raw1, 0 ) || {};
		t.check( 'typed kicker saved to the block data', d1.kicker === 'Projects' );
		t.check( 'field key reference kept', d1._kicker === 'field_minn_acfi_kicker' );
		t.check( 'other fields untouched, entity form kept', d1.headline === 'Alpha &amp; headline' && d1.since === 'Since [minn_fixture_year]' && d1.body === 'Body copy alpha' );
		t.check( 'untouched block stays byte-identical', ( raw1.match( /<!-- wp:acf\/minn-inline [\s\S]*? \/-->/g ) || [] )[ 1 ] === plainBefore );

		// A label inside a link: the click seats the caret, the link stays put.
		const urlBefore = page.url();
		await typeInto( 0, 'Book a call', 'Book a visit' );
		await page.waitForTimeout( 800 );
		t.check( 'clicking a run inside a link does not follow it', page.url() === urlBefore, page.url() );
		const raw1b = await save( ( r ) => ( dataOf( r, 0 ) || {} ).button === 'Book a visit' );
		t.check( 'linked label saved', ( dataOf( raw1b, 0 ) || {} ).button === 'Book a visit' );

		// Shortcode text: the words change, the shortcode stays. Double-click
		// selects the word itself (Meta+a would take the token too).
		const word = await island( 1 ).locator( '.acfi-since .minn-island-run' ).evaluate( ( run ) => {
			const tn = [ ...run.childNodes ].find( ( c ) => c.nodeType === 3 && c.nodeValue.includes( 'Since' ) );
			run.scrollIntoView( { block: 'center' } );
			const r = document.createRange();
			const at = tn.nodeValue.indexOf( 'Since' );
			r.setStart( tn, at );
			r.setEnd( tn, at + 5 );
			const b = r.getBoundingClientRect();
			return { x: b.x + b.width / 2, y: b.y + b.height / 2 };
		} );
		await page.mouse.dblclick( word.x, word.y );
		await page.keyboard.type( 'From' );
		const raw1c = await save( ( r ) => ( dataOf( r, 1 ) || {} ).since === 'From [minn_fixture_year]' );
		t.check( 'shortcode survives an edit to its words', ( dataOf( raw1c, 1 ) || {} ).since === 'From [minn_fixture_year]', ( dataOf( raw1c, 1 ) || {} ).since );

		// Token popover: switch the fact through the hint's choices.
		await clickAt( island( 1 ).locator( '.acfi-stats .minn-sc-token', { hasText: '15+' } ) );
		await page.waitForSelector( '.minn-sc-pop [data-sc-src]', { timeout: 5000 } );
		const pop = await page.evaluate( () => ( {
			field: ( document.querySelector( '.minn-sc-pop [data-sc-field="0"]' ) || {} ).value,
			src: document.querySelector( '.minn-sc-pop [data-sc-src]' ).value,
			title: document.querySelector( '.minn-sc-pop .minn-insp-title' ).textContent,
		} ) );
		t.check( 'a token opens its source with the hinted choices', pop.field === 'years' && pop.src === '[minn_fixture_fact key="years"]' && pop.title === 'Fixture fact', JSON.stringify( pop ) );
		await page.selectOption( '.minn-sc-pop [data-sc-field="0"]', 'sites' );
		t.check( 'choosing an option rewrites the shortcode', await page.inputValue( '.minn-sc-pop [data-sc-src]' ) === '[minn_fixture_fact key="sites"]' );
		await clickAt( page.locator( '.minn-sc-pop [data-sc-apply]' ) );
		const statsSites = '[minn_fixture_fact key="sites"] years and [minn_fixture_fact key="sites"] sites';
		const raw1e = await save( ( r ) => ( dataOf( r, 1 ) || {} ).stats === statsSites );
		t.check( 'an edited shortcode saves', ( dataOf( raw1e, 1 ) || {} ).stats === statsSites, ( dataOf( raw1e, 1 ) || {} ).stats );
		await page.waitForFunction( () => {
			const tk = [ ...document.querySelectorAll( '.minn-block-island[data-block="acf/minn-inline"]' ) ][ 1 ].querySelectorAll( '.acfi-stats .minn-sc-token' );
			return tk.length === 2 && tk[ 0 ].textContent === '200+';
		}, null, { timeout: 30000 } ).catch( () => {} );
		t.check( 'the preview re-renders the new output', ( await tokens( 1, '.acfi-stats' ) ).map( ( x ) => x.text ).join( ',' ) === '200+,200+' );

		// Remove from the popover.
		await clickAt( island( 1 ).locator( '.acfi-stats .minn-sc-token' ).nth( 1 ) );
		await page.waitForSelector( '.minn-sc-pop [data-sc-remove]', { timeout: 5000 } );
		await clickAt( page.locator( '.minn-sc-pop [data-sc-remove]' ) );
		const statsRemoved = '[minn_fixture_fact key="sites"] years and  sites';
		const raw1f = await save( ( r ) => ( dataOf( r, 1 ) || {} ).stats === statsRemoved );
		t.check( 'a removed token drops its shortcode', ( dataOf( raw1f, 1 ) || {} ).stats === statsRemoved, ( dataOf( raw1f, 1 ) || {} ).stats );

		// [ opens the picker of described shortcodes.
		await caretEnd( 1, 'years and' );
		await page.keyboard.type( ' [' );
		await page.waitForSelector( '.minn-sc-pop [data-sc-q]', { timeout: 5000 } );
		await page.waitForTimeout( 150 );
		await page.keyboard.type( 'years' );
		await page.keyboard.press( 'Enter' );
		const statsAdded = statsRemoved + ' [minn_fixture_fact key="years"]';
		const raw1g = await save( ( r ) => ( dataOf( r, 1 ) || {} ).stats === statsAdded );
		t.check( 'a picked shortcode is inserted as a token', ( dataOf( raw1g, 1 ) || {} ).stats === statsAdded, ( dataOf( raw1g, 1 ) || {} ).stats );
		t.check( 'the new token shows the hinted output', ( await tokens( 1, '.acfi-stats' ) ).some( ( x ) => x.text === '15+' ) );

		// Backspace right after a token removes it whole.
		await page.keyboard.press( 'Backspace' );
		const raw1h = await save( ( r ) => ( dataOf( r, 1 ) || {} ).stats === statsRemoved + ' ' );
		t.check( 'Backspace removes a token whole', ( dataOf( raw1h, 1 ) || {} ).stats === statsRemoved + ' ', JSON.stringify( ( dataOf( raw1h, 1 ) || {} ).stats ) );

		// Escape types the bracket after all.
		await caretEnd( 1, 'Bravo headline' );
		await page.keyboard.type( ' [' );
		await page.waitForSelector( '.minn-sc-pop [data-sc-q]', { timeout: 5000 } );
		await page.waitForTimeout( 150 );
		await page.keyboard.press( 'Escape' );
		await page.keyboard.type( 'sic]' );
		const raw1i = await save( ( r ) => ( dataOf( r, 1 ) || {} ).headline === 'Bravo headline [sic]' );
		t.check( 'Escape in the picker types a plain bracket', ( dataOf( raw1i, 1 ) || {} ).headline === 'Bravo headline [sic]', ( dataOf( raw1i, 1 ) || {} ).headline );

		// One line of a list: the other line and the line break stay.
		await typeInto( 1, 'Second point', 'Second edited' );
		const raw1d = await save( ( r ) => ( dataOf( r, 1 ) || {} ).points === 'First point\nSecond edited' );
		t.check( 'a list line saves alone', ( dataOf( raw1d, 1 ) || {} ).points === 'First point\nSecond edited', JSON.stringify( ( dataOf( raw1d, 1 ) || {} ).points ) );

		await typeInto( 1, 'Body copy bravo', 'Fresh body & more' );
		const raw2 = await save( ( r ) => ( dataOf( r, 1 ) || {} ).body === 'Fresh body & more' );
		const d2 = dataOf( raw2, 1 ) || {};
		t.check( 'fallback-armed textarea saved', d2.body === 'Fresh body & more' );
		t.check( 'saved JSON uses comment-safe escaping', /"body":"Fresh body \\u0026 more"/.test( raw2 ) );
		t.check( 'earlier edit survived', ( dataOf( raw2, 0 ) || {} ).kicker === 'Projects' );
	} finally {
		await deletePost( page, id );
	}

	await t.done( browser, errors );
} )();
