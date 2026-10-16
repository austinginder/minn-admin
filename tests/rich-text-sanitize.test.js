/**
 * The rich-text modal seeds its editable body from stored markup, so that
 * markup must never be parsed by the live document.
 *
 * Parsing inertly is only half of it. The scrub that follows used to remove
 * three element names and two attribute classes and then re-serialise the
 * result straight into `innerHTML` on an element already in the page, which
 * re-parses everything it just cleaned. Six shapes came through that: an
 * `<iframe srcdoc>` (which inherits this origin, and whose content is a fresh
 * navigation, so a plain `<script>` inside it DOES run), a `<base href>` that
 * re-roots every relative URL on the page, `formaction`, an SVG `<animate>`
 * retargeting an href, an on* attribute inside `<template>` (which
 * `querySelectorAll` never descends into), and `<object data>`.
 *
 * The checks that matter, in order: nothing executes and no dangerous
 * attribute stays live, and the content people actually store still arrives
 * intact (over-scrubbing a wysiwyg field is a regression, and the release
 * note promises tables, images and links come through as saved). Since the
 * v0.45.0 registry review (#17) the field takes the post editor's path: what
 * runs is PARKED under this page's inert prefix rather than deleted, so a
 * field opened and applied keeps every byte its writer stored, mailto:,
 * tel:, #anchor and relative links included, and the value read back out
 * (rtFieldHtml) is the writer's markup again.
 *
 * The seeder is module-scoped, and a permanent test hook in shipped code costs
 * more than it is worth, so the suite lifts the real functions OUT OF THE
 * SHIPPED BUNDLE by name and evaluates them in a real page. If they are
 * renamed or restructured the extraction fails loudly rather than going quietly
 * green, and the bytes under test are always the bytes that ship.
 *
 * Test with event attributes, never a bare `<script>`: an innerHTML parse fires
 * handlers but does not run script elements, so `<script>` would report a real
 * hole as safe. The one exception is inside `srcdoc`, which is a navigation.
 */
const fs = require( 'fs' );
const path = require( 'path' );
const { launch, reporter } = require( './helpers' );

const APP = path.join( __dirname, '..', 'assets', 'js', 'app.js' );

/** Lift a contiguous named region out of the bundle. */
function extract( src, startsWith, endsBefore ) {
	const i = src.indexOf( startsWith );
	const j = i === -1 ? -1 : src.indexOf( endsBefore, i );
	if ( i === -1 || j === -1 ) return null;
	return src.slice( i, j );
}

// live: a selector that matches when the dangerous part is still live in the
// seeded box (a parked attribute carries the inert prefix, so it does not).
const VECTORS = [
	{ name: 'iframe srcdoc executes same-origin', html: `<iframe srcdoc="&lt;img src=x onerror=window.__rtHit('srcdoc')&gt;"></iframe>`, live: '[srcdoc]' },
	{ name: 'base href re-roots the document', html: `<base href="https://evil.example/">`, live: 'base[href]' },
	{ name: 'formaction javascript:', html: `<form><button formaction="javascript:window.__rtHit('fa')">go</button></form>`, live: '[formaction]' },
	{ name: 'svg animate retargets href', html: `<svg><a><animate attributeName="href" values="javascript:window.__rtHit('an')"/><text>x</text></a></svg>`, live: 'animate[attributeName], animate[values]' },
	{ name: 'on* inside template', html: `<template><img src=x onerror="window.__rtHit('tpl')"></template>`, live: '[onerror]', template: true },
	{ name: 'object data javascript:', html: `<object data="javascript:window.__rtHit('obj')"></object>`, live: 'object[data]' },
	{ name: 'plain img onerror', html: `<img src=x onerror="window.__rtHit('img')">`, live: '[onerror]' },
	{ name: 'javascript: link', html: `<a href="javascript:window.__rtHit('a')">x</a>`, live: 'a[href]' },
	{ name: 'id naming Minn chrome', html: `<p id="minn-editor-body">x</p>`, live: '[id]' },
];

const KEEP = [
	{ name: 'link keeps its href', html: `<p>See <a href="/wp-admin/x.php?a=1">this</a>.</p>`, want: /<a href="\/wp-admin\/x\.php\?a=1"/i },
	{ name: 'table keeps structure', html: `<table><tbody><tr><td colspan="2">cell</td></tr></tbody></table>`, want: /<td colspan="2">cell<\/td>/i },
	{ name: 'image keeps src and caption', html: `<figure><img src="/uploads/a.png" alt="A"><figcaption>Cap</figcaption></figure>`, want: /<img src="\/uploads\/a\.png" alt="A">[\s\S]*Cap/i },
	{ name: 'inline formatting survives', html: `<p><strong>b</strong> <em>i</em> <s>s</s> <code>c</code></p>`, want: /<strong>b<\/strong>[\s\S]*<code>c<\/code>/i },
	{ name: 'list survives', html: `<ul><li>one</li><li>two</li></ul>`, want: /<li>one<\/li>\s*<li>two<\/li>/i },
	{ name: 'mailto: link', html: `<p><a href="mailto:hello@example.com">us</a></p>`, want: /<a href="mailto:hello@example\.com">/i },
	{ name: 'tel: link', html: `<p><a href="tel:+15551234567">call</a></p>`, want: /<a href="tel:\+15551234567">/i },
	{ name: '#anchor link', html: `<p><a href="#faq">FAQ</a></p>`, want: /<a href="#faq">/i },
	{ name: 'relative link', html: `<p><a href="../docs/">docs</a></p>`, want: /<a href="\.\.\/docs\/">/i },
	{ name: 'protocol-relative image (as an https one)', html: `<img src="//cdn.example/a.png" alt="">`, want: /<img src="\/\/cdn\.example\/a\.png"/i },
	{ name: 'inline image', html: `<img src="data:image/png;base64,iVBORw0KGgo=" alt="">`, want: /<img src="data:image\/png;base64,/i },
	{ name: 'https embed iframe', html: `<iframe src="https://www.youtube.com/embed/x"></iframe>`, want: /<iframe src="https:\/\/www\.youtube\.com\/embed\/x">/i },
];

( async () => {
	const t = reporter( 'rich-text-sanitize' );
	const src = fs.readFileSync( APP, 'utf8' );

	const seeder = extract( src, 'const RT_URL_ATTRS', '\tfunction openRichTextModal' );
	// safeHref spans several lines; take it to the close of its arrow body.
	const safeHrefBody = extract( src, '\tconst safeHref = ', '\n\t};\n' );
	const safeHrefLine = safeHrefBody ? safeHrefBody + '\n\t};\n' : null;
	const inertParseFn = extract( src, '\tconst inertParse = ', '\n\n' );

	const { browser, page, errors } = await launch();

	t.check( 'sanitizer region found in the shipped bundle', !! seeder );
	t.check( 'safeHref found in the shipped bundle', !! safeHrefLine );
	t.check( 'inertParse found in the shipped bundle', !! inertParseFn );
	if ( ! seeder || ! safeHrefLine || ! inertParseFn ) {
		await t.done( browser, errors );
		return;
	}

	await page.setContent( '<!doctype html><meta charset=utf-8><body><div id="live"></div></body>' );
	await page.evaluate( ( parts ) => {
		window.__rtHits = [];
		window.__rtHit = ( w ) => window.__rtHits.push( w );
		// The seeder's own dependencies, verbatim from the bundle where they
		// carry security meaning (safeHref, inertParse) and stubbed only where
		// they do not (miniAutop's paragraph wrapping, the $$ helper).
		const preamble = `
			const stripTagsDoc = document.implementation.createHTMLDocument('');
			const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));
			const miniAutop = (s) => '<p>' + s + '</p>';
			const scopeCssToPreviews = () => '';
			const modernizeStrikes = () => {};
			const cleanBoundaryNbsp = () => {};
		`;
		// eslint-disable-next-line no-new-func
		new Function( preamble + parts.safeHref + parts.inertParse + parts.seeder + '; window.__rtSeedInto = rtFieldSeedInto; window.__rtFieldHtml = rtFieldHtml;' )();
	}, { seeder, safeHref: safeHrefLine, inertParse: inertParseFn } );

	t.check( 'rtFieldSeedInto and rtFieldHtml are callable', await page.evaluate( () => typeof window.__rtSeedInto === 'function' && typeof window.__rtFieldHtml === 'function' ) );

	const seed = ( html, live ) => page.evaluate( ( a ) => {
		window.__rtHits = [];
		const box = document.createElement( 'div' );
		document.getElementById( 'live' ).appendChild( box );
		window.__rtSeedInto( box, a.markup );
		const roots = [ box, ...Array.from( box.querySelectorAll( 'template' ) ).map( ( tp ) => tp.content ) ];
		return {
			html: box.innerHTML,
			live: a.live ? roots.some( ( r ) => !! r.querySelector( a.live ) ) : false,
			back: window.__rtFieldHtml( box ),
			hits: window.__rtHits.slice(),
		};
	}, { markup: html, live } );

	for ( const v of VECTORS ) {
		const r = await seed( v.html, v.live );
		await page.waitForTimeout( 120 );
		const fired = ( await page.evaluate( () => window.__rtHits.slice() ) ).concat( r.hits );
		t.check(
			`blocked: ${ v.name }`,
			! fired.length && ! r.live,
			fired.length ? `handler fired: ${ fired.join( ',' ) }` : ( r.live ? `live in DOM: ${ ( r.html || '' ).slice( 0, 90 ) }` : '' )
		);
		// Parked, not deleted: the value read back is the writer's again.
		const want = await page.evaluate( ( m ) => {
			const d = document.implementation.createHTMLDocument( '' );
			d.body.innerHTML = m;
			return d.body.innerHTML.trim();
		}, v.html );
		t.check( `round-trips: ${ v.name }`, r.back === want, r.back.slice( 0, 120 ) );
	}

	for ( const k of KEEP ) {
		const r = await seed( k.html );
		t.check( `preserved: ${ k.name }`, k.want.test( r.html || '' ) && k.want.test( r.back || '' ), ( r.html || '' ).slice( 0, 90 ) );
	}

	// Plain text still becomes editable blocks.
	const plain = await seed( 'just words' );
	t.check( 'preserved: plain text becomes a paragraph', /<p>just words<\/p>/i.test( plain.html || '' ), plain.html );

	await t.done( browser, errors );
} )();
