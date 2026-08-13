/**
 * Re-serialization must escape every class attribute it rebuilds.
 *
 * The decode-after-filter class: wp_kses_post() stores a breakout payload
 * INERT (the quote arrives as &quot;), so it survives review. Setting
 * body.innerHTML then DECODES it back to a real quote, so el.className holds
 * the raw string, and a serializer that interpolates className into a
 * double-quoted attribute without escaping writes a live event handler back
 * to post_content — through an unfiltered_html holder, so kses never re-runs.
 *
 * Each case seeds the KSES-PROCESSED bytes (what a Contributor's submission
 * actually becomes in the database), opens the post in the editor, saves, and
 * asserts the SAVED content did not grow an attribute outside the class value.
 *
 *   MINN_TEST_PASS=... node class-escape.test.js
 */
const { launch, login, createPost, deletePost, openEditor, reporter } = require( './helpers' );

const t = reporter( 'class-escape' );

// Exactly what wp_kses_post() emits for class='x" tabindex=0 autofocus
// onfocus=alert(1)' — verified against this site's WordPress.
const BREAKOUT = 'x&quot; tabindex=0 autofocus onfocus=alert(1)';

const CASES = [
	{
		name: 'list',
		content: `<!-- wp:list -->\n<ul class="wp-block-list ${ BREAKOUT }"><!-- wp:list-item -->\n<li>item</li>\n<!-- /wp:list-item --></ul>\n<!-- /wp:list -->`,
	},
	{
		name: 'quote',
		content: `<!-- wp:quote -->\n<blockquote class="wp-block-quote ${ BREAKOUT }"><!-- wp:paragraph -->\n<p>quoted</p>\n<!-- /wp:paragraph --></blockquote>\n<!-- /wp:quote -->`,
	},
	{
		name: 'verse',
		content: `<!-- wp:verse -->\n<pre class="wp-block-verse ${ BREAKOUT }">line</pre>\n<!-- /wp:verse -->`,
	},
	{
		name: 'preformatted',
		content: `<!-- wp:preformatted -->\n<pre class="wp-block-preformatted ${ BREAKOUT }">text</pre>\n<!-- /wp:preformatted -->`,
	},
	{
		name: 'table',
		content: `<!-- wp:table -->\n<figure class="wp-block-table ${ BREAKOUT }"><table><tbody><tr><td>cell</td></tr></tbody></table></figure>\n<!-- /wp:table -->`,
	},
	{
		// Different bypass from the cases above: wp_kses_post() does not
		// inspect block comments AT ALL, so a " JSON escape reaches the
		// database byte-identical and JSON.parse turns it back into a real
		// quote when the buttons island is parsed. The class value here is
		// therefore the JSON escape, not the HTML entity.
		name: 'buttons',
		content: '<!-- wp:buttons -->\n<div class="wp-block-buttons"><!-- wp:button {"className":"x\\u0022 tabindex=0 autofocus onfocus=alert(1) y=\\u0022"} -->\n<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://example.com">go</a></div>\n<!-- /wp:button --></div>\n<!-- /wp:buttons -->',
	},
];

/**
 * Did the saved markup grow a live handler?
 *
 * A correctly-escaped save keeps the whole payload INSIDE the quoted class
 * value as &quot;, so the raw sequence `" tabindex` never appears. Match on
 * that rather than on the word onfocus, which is present either way.
 */
function brokeOut( saved ) {
	return /"\s*tabindex\s*=/i.test( saved ) || /"\s*onfocus\s*=/i.test( saved );
}

( async () => {
	const { browser, page, errors } = await launch();
	await login( page );

	const created = [];
	try {
		for ( const c of CASES ) {
			let id;
			try {
				id = await createPost( page, { title: `class-escape ${ c.name }`, content: c.content } );
				created.push( id );

				await openEditor( page, id );

				// A save must happen for the serializer to run. Type a marker
				// into the title, then Save draft via the keyboard.
				const marker = 'SAVED' + Date.now().toString().slice( -5 );
				await page.click( '#minn-editor-title' );
				await page.keyboard.type( marker );
				await page.waitForTimeout( 300 );
				await page.keyboard.down( 'Meta' );
				await page.keyboard.press( 's' );
				await page.keyboard.up( 'Meta' );

				// Poll for the SAVE, tracked by the title marker — not by the
				// content changing. A correct save is byte-identical here: the
				// DOM decodes &quot; to a quote on load and esc() re-encodes it
				// on the way out, so the stored bytes round-trip unchanged.
				// Watching for a content diff would call that "no save ran".
				let saved = '';
				let landed = false;
				for ( let i = 0; i < 25; i++ ) {
					await page.waitForTimeout( 400 );
					const row = await page.evaluate( async ( pid ) => {
						const r = await fetch(
							window.MINN.restUrl + 'wp/v2/posts/' + pid + '?context=edit&_fields=content,title&cb=' + Math.random(),
							{ headers: { 'X-WP-Nonce': window.MINN.nonce } }
						);
						const j = await r.json();
						return {
							content: ( j && j.content && j.content.raw ) || '',
							title: ( j && j.title && j.title.raw ) || '',
						};
					}, id );
					saved = row.content;
					if ( row.title.includes( marker ) ) { landed = true; break; }
				}

				t.check( `${ c.name }: the save landed`, landed,
					landed ? '' : 'title marker never reached the database — case proves nothing' );
				t.check( `${ c.name }: class stays inside the attribute after save`, ! brokeOut( saved ),
					brokeOut( saved ) ? saved.slice( 0, 180 ) : '' );
			} catch ( e ) {
				t.check( `${ c.name }: case ran`, false, e.message );
			}
		}
	} finally {
		for ( const id of created ) await deletePost( page, id );
	}

	await t.done( browser, errors );
} )();
