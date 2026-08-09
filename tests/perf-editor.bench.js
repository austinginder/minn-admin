/**
 * Editor feel benchmark — NOT a pass/fail suite. Measures what a writer
 * feels: keystroke latency (Event Timing API: event start → next paint),
 * long tasks while typing, and editor open time, across three reference
 * documents (prose, nested layout, heavy). Run it on two checkouts to
 * answer "did this cycle slow the editor?"; record numbers per release.
 *
 *   MINN_TEST_PASS=… node perf-editor.bench.js [label]
 */
const { BASE, launch, login, createPost, deletePost } = require( './helpers' );

const LABEL = process.argv[ 2 ] || 'current';

const para = ( n ) => `<!-- wp:paragraph -->\n<p>Sentence ${ n } of steady writing flows along with enough words to look like a real draft paragraph in progress.</p>\n<!-- /wp:paragraph -->`;
const heading = ( n ) => `<!-- wp:heading -->\n<h2 class="wp-block-heading">Section heading ${ n }</h2>\n<!-- /wp:heading -->`;
const card = ( n ) => [
	'<!-- wp:group {"className":"bench-card"} -->',
	`<div class="wp-block-group bench-card"><!-- wp:paragraph -->`,
	`<p>Nested card ${ n } body text with a couple of sentences to give the slot some real weight.</p>`,
	'<!-- /wp:paragraph -->',
	'',
	'<!-- wp:spacer {"height":"20px"} -->',
	'<div style="height:20px" aria-hidden="true" class="wp-block-spacer"></div>',
	'<!-- /wp:spacer --></div>',
	'<!-- /wp:group -->',
].join( '\n' );

const FIXTURES = {
	prose: Array.from( { length: 60 }, ( _, i ) => ( i % 12 === 0 ? heading( i ) : para( i ) ) ).join( '\n\n' ),
	nested: Array.from( { length: 8 }, ( _, s ) => [
		'<!-- wp:group {"layout":{"type":"constrained"}} -->',
		`<div class="wp-block-group">${ heading( s ) }`,
		'',
		'<!-- wp:columns -->',
		'<div class="wp-block-columns"><!-- wp:column -->',
		`<div class="wp-block-column">${ card( s * 3 ) }</div>`,
		'<!-- /wp:column -->',
		'',
		'<!-- wp:column -->',
		`<div class="wp-block-column">${ card( s * 3 + 1 ) }</div>`,
		'<!-- /wp:column -->',
		'',
		'<!-- wp:column -->',
		`<div class="wp-block-column">${ card( s * 3 + 2 ) }</div>`,
		'<!-- /wp:column --></div>',
		'<!-- /wp:columns --></div>',
		'<!-- /wp:group -->',
	].join( '\n' ) ).join( '\n\n' ),
	heavy: Array.from( { length: 200 }, ( _, i ) =>
		( i % 25 === 0 ? heading( i )
		: i % 40 === 13 ? '<!-- wp:acme/widget -->\n<div class="acme-widget">Island payload</div>\n<!-- /wp:acme/widget -->'
		: para( i ) ) ).join( '\n\n' ),
};

const pct = ( arr, p ) => {
	if ( ! arr.length ) return 0;
	const s = [ ...arr ].sort( ( a, b ) => a - b );
	return s[ Math.min( s.length - 1, Math.floor( ( p / 100 ) * s.length ) ) ];
};

( async () => {
	const { browser, page } = await launch();
	await login( page );
	const results = {};

	for ( const [ name, content ] of Object.entries( FIXTURES ) ) {
		const id = await createPost( page, { title: `Bench ${ name } ${ Date.now() }`, content, status: 'draft' } );
		const t0 = Date.now();
		await page.goto( `${ BASE }/minn-admin/editor/posts/${ id }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-editor-body', { timeout: 30000 } );
		const openMs = Date.now() - t0;
		await page.waitForTimeout( 2500 ); // previews/arming settle

		// Instrument: Event Timing for keydown (start → next paint) + long tasks.
		await page.evaluate( () => {
			window.__bench = { events: [], longTasks: 0 };
			new PerformanceObserver( ( list ) => {
				for ( const e of list.getEntries() ) {
					if ( 'keydown' === e.name ) window.__bench.events.push( e.duration );
				}
			} ).observe( { type: 'event', durationThreshold: 16 } );
			new PerformanceObserver( ( list ) => {
				window.__bench.longTasks += list.getEntries().length;
			} ).observe( { type: 'longtask' } );
			// Caret into the FIRST paragraph of the writing surface (a slot
			// paragraph on the nested fixture — the realistic target).
			const body = document.querySelector( '#minn-editor-body' );
			const p = body.querySelector( '.minn-slot > p' ) || body.querySelector( ':scope > p' );
			const host = p.closest( '.minn-slot' ) || body;
			host.focus( { preventScroll: true } );
			const r = document.createRange();
			r.selectNodeContents( p );
			r.collapse( false );
			const s = getSelection();
			s.removeAllRanges();
			s.addRange( r );
		} );
		await page.keyboard.type( ' The quick brown fox jumps over the lazy dog while the editor keeps every keystroke smooth and the caret never stalls under load at all.', { delay: 15 } );
		await page.waitForTimeout( 800 );
		const m = await page.evaluate( () => window.__bench );
		// Event Timing only reports events ≥ the 16ms threshold; everything
		// under it painted within a frame. Report slow-event share + tails.
		const typed = 135;
		results[ name ] = {
			openMs,
			slowKeys: m.events.length,
			slowShare: Math.round( ( m.events.length / typed ) * 100 ) + '%',
			p95SlowMs: Math.round( pct( m.events, 95 ) ),
			worstMs: Math.round( Math.max( 0, ...m.events ) ),
			longTasks: m.longTasks,
		};
		await deletePost( page, id );
	}

	console.log( `\n=== editor feel · ${ LABEL } ===` );
	console.log( 'fixture  open_ms  keys>16ms  share  p95_ms  worst_ms  longtasks' );
	for ( const [ n, r ] of Object.entries( results ) ) {
		console.log(
			n.padEnd( 8 ) + String( r.openMs ).padEnd( 9 ) + String( r.slowKeys ).padEnd( 11 )
			+ String( r.slowShare ).padEnd( 7 ) + String( r.p95SlowMs ).padEnd( 8 )
			+ String( r.worstMs ).padEnd( 10 ) + r.longTasks
		);
	}
	await Promise.race( [ browser.close(), new Promise( ( r ) => setTimeout( r, 5000 ) ) ] );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
