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
 * attribute or element survives, and the content people actually store still
 * arrives intact (over-scrubbing a wysiwyg field is a regression, and the
 * release note promises tables, images and links come through as saved).
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

const VECTORS = [
	{ name: 'iframe srcdoc executes same-origin', html: `<iframe srcdoc="&lt;img src=x onerror=window.__rtHit('srcdoc')&gt;"></iframe>`, bad: /srcdoc=|<iframe/i },
	{ name: 'base href re-roots the document', html: `<base href="https://evil.example/">`, bad: /<base/i },
	{ name: 'formaction javascript:', html: `<form><button formaction="javascript:window.__rtHit('fa')">go</button></form>`, bad: /formaction|<form/i },
	{ name: 'svg animate retargets href', html: `<svg><a><animate attributeName="href" values="javascript:window.__rtHit('an')"/><text>x</text></a></svg>`, bad: /attributeName|<animate/i },
	{ name: 'on* inside template', html: `<template><img src=x onerror="window.__rtHit('tpl')"></template>`, bad: /onerror|<template/i },
	{ name: 'object data javascript:', html: `<object data="javascript:window.__rtHit('obj')"></object>`, bad: /<object/i },
	{ name: 'protocol-relative src', html: `<img src="//evil.example/beacon.png">`, bad: /\/\/evil\.example/i },
	{ name: 'plain img onerror', html: `<img src=x onerror="window.__rtHit('img')">`, bad: /onerror/i },
];

const KEEP = [
	{ name: 'link keeps its href', html: `<p>See <a href="/wp-admin/x.php?a=1">this</a>.</p>`, want: /<a href="\/wp-admin\/x\.php\?a=1"/i },
	{ name: 'table keeps structure', html: `<table><tbody><tr><td colspan="2">cell</td></tr></tbody></table>`, want: /<td colspan="2">cell<\/td>/i },
	{ name: 'image keeps src and caption', html: `<figure><img src="/uploads/a.png" alt="A"><figcaption>Cap</figcaption></figure>`, want: /<img src="\/uploads\/a\.png" alt="A">[\s\S]*Cap/i },
	{ name: 'inline formatting survives', html: `<p><strong>b</strong> <em>i</em> <s>s</s> <code>c</code></p>`, want: /<strong>b<\/strong>[\s\S]*<code>c<\/code>/i },
	{ name: 'list survives', html: `<ul><li>one</li><li>two</li></ul>`, want: /<li>one<\/li>\s*<li>two<\/li>/i },
];

( async () => {
	const t = reporter( 'rich-text-sanitize' );
	const src = fs.readFileSync( APP, 'utf8' );

	const seeder = extract( src, 'const RT_DROP_TAGS', '\tfunction openRichTextModal' );
	const safeHrefLine = ( src.match( /\n\tconst safeHref = [^\n]+\n/ ) || [] )[ 0 ];
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
		`;
		// eslint-disable-next-line no-new-func
		new Function( preamble + parts.safeHref + parts.inertParse + parts.seeder + '; window.__rtSeedInto = rtSeedInto;' )();
	}, { seeder, safeHref: safeHrefLine, inertParse: inertParseFn } );

	t.check( 'rtSeedInto is callable', await page.evaluate( () => typeof window.__rtSeedInto === 'function' ) );

	const seed = ( html ) => page.evaluate( ( markup ) => {
		window.__rtHits = [];
		const box = document.createElement( 'div' );
		document.getElementById( 'live' ).appendChild( box );
		window.__rtSeedInto( box, markup );
		return { html: box.innerHTML, hits: window.__rtHits.slice() };
	}, html );

	for ( const v of VECTORS ) {
		const r = await seed( v.html );
		await page.waitForTimeout( 120 );
		const fired = ( await page.evaluate( () => window.__rtHits.slice() ) ).concat( r.hits );
		const left = v.bad.test( r.html || '' );
		t.check(
			`blocked: ${ v.name }`,
			! fired.length && ! left,
			fired.length ? `handler fired: ${ fired.join( ',' ) }` : ( left ? `left in DOM: ${ ( r.html || '' ).slice( 0, 90 ) }` : '' )
		);
	}

	for ( const k of KEEP ) {
		const r = await seed( k.html );
		t.check( `preserved: ${ k.name }`, k.want.test( r.html || '' ), ( r.html || '' ).slice( 0, 90 ) );
	}

	// Plain text still becomes editable blocks.
	const plain = await seed( 'just words' );
	t.check( 'preserved: plain text becomes a paragraph', /<p>just words<\/p>/i.test( plain.html || '' ), plain.html );

	await t.done( browser, errors );
} )();
