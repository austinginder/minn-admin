/**
 * The editor finds its islands by reference, never by what content can wear
 * (no browser, <1 s).
 *
 * Post kses keeps class and data-*, so content can carry an element wearing
 * .minn-block-island and data-island="K". Anything that reaches
 * ed.islands[] must find its island through isRealIsland(), islandOf() or
 * islandByIdx() (an island Minn rendered: contenteditable="false", not
 * inside another island's preview). Every other lookup by that class is
 * listed below with why it cannot steer a write: it only excludes islands
 * (a look-alike then makes Minn do less), or a real-island test follows it.
 *
 * A new bare lookup fails until it uses the helpers or is listed here with
 * its reason; a listed line that no longer exists fails too, so the list
 * stays true.
 */
const fs = require( 'fs' );
const path = require( 'path' );

const src = fs.readFileSync( path.join( __dirname, '..', 'assets', 'js', 'app.js' ), 'utf8' ).split( '\n' );
const LOOKUP = /closest\( '\.minn-block-island|minn-block-island\[data-island=|\$\$\( '\.minn-block-island|querySelector(All)?\( '\.minn-block-island|classList\.contains\( 'minn-block-island' \)/;

// Trimmed source line => why it is safe.
const REVIEWED = {
	"let n = el && el.closest ? el.closest( '.minn-block-island' ) : null;": 'islandOf() itself',
	"while ( n && ! isRealIsland( n ) ) n = n.parentElement ? n.parentElement.closest( '.minn-block-island' ) : null;": 'islandOf() itself',
	"return !! el && 1 === el.nodeType && el.classList.contains( 'minn-block-island' )": 'isRealIsland() itself',
	"if ( ! island || ! island.classList.contains( 'minn-block-island' ) || 'false' !== island.getAttribute( 'contenteditable' ) ) return null;": 'previewIsland(): the same test',
	"return el.closest( '.minn-block-island' ) === island ? island : null;": 'previewIsland(): compares to a real island',
	"const isl = slot.closest( '.minn-block-island' );": 'stampSlotDirtyFor: a dirty stamp on a look-alike is ignored, flushSlotIsland takes real islands only',
	"if ( pre.closest( '.minn-block-island' ) ) return; // islands stay verbatim": 'exclusion: leaves code inside islands alone',
	"if ( blk && ! blk.closest( '.minn-block-island' ) && blk.getAttribute( 'contenteditable' ) !== 'false' ) {": 'exclusion',
	"return li && body.contains( li ) && ! li.closest( '.minn-block-island' ) ? li : null;": 'exclusion',
	"if ( ! fc || ! body.contains( fc ) || fc.closest( '.minn-block-island' ) ) return;": 'exclusion',
	"&& ! aEl.closest( '.minn-block-island' ) ) {": 'exclusion',
	"if ( ! code || code.closest( 'pre' ) || code.closest( '.minn-block-island' ) ) return null;": 'exclusion',
	"if ( a && ebody.contains( a ) && ! a.closest( '.minn-block-island' ) ) return openLinkPop( a );": 'exclusion: no link popover inside islands',
	"const wrap = img.closest( '.minn-block-island, .minn-slot' );": 'exclusion: an image in an island is not edited here',
	"const wrap = a.closest( '.minn-block-island, .minn-slot' );": 'exclusion',
	"const wrap = table.closest( '.minn-block-island, .minn-slot' );": 'exclusion',
	"const inIsland = block.closest( '.minn-block-island' );": 'caret walk out of island chrome, no write',
	"if ( ! next || next.classList.contains( 'minn-block-island' ) ) {": 'landing paragraph check, no write',
	"if ( ! next || next.classList.contains( 'minn-block-island' ) ) return; // keep as the landing paragraph": 'landing paragraph check, no write',
	"|| last.classList.contains( 'minn-block-island' )": 'trailing paragraph check, no write',
	"} else if ( n.classList && n.classList.contains( 'minn-block-island' ) ) {": 'paste: rebuilds a copied island from this post\'s own raw',
	"if ( ! body.querySelector( '.minn-block-island .minn-block-island > .minn-island-chip' ) ) return;": 'chip layout early-out; the layout itself takes real islands',
	"for ( let anc = own.parentElement && own.parentElement.closest( '.minn-block-island' ); anc;": 'chip layout geometry only',
	"anc = anc.parentElement && anc.parentElement.closest( '.minn-block-island' ) ) {": 'chip layout geometry only',
	"if ( e.target.closest( '.minn-block-island[data-imgtool] > .minn-island-preview, .minn-block-island[data-cted] > .minn-island-preview' ) ) e.preventDefault();": 'preventDefault only',
	"const prev = e.target.closest( '.minn-block-island[data-imgtool] > .minn-island-preview, .minn-block-island[data-cted] > .minn-island-preview' );": 'preventDefault only',
	": ( ! cbadge0 && e.target.closest && e.target.closest( '.minn-block-island[data-cted] > .minn-island-preview' ) );": 'doorway: isRealIsland( parent ) checked next',
	": ( ! badge0 && e.target.closest && e.target.closest( '.minn-block-island[data-imgtool] > .minn-island-preview' ) );": 'doorway: isRealIsland( parent ) checked next',
	"const preview = e.target.closest && e.target.closest( '.minn-block-island > .minn-island-preview' );": 'context menu: isRealIsland( parent ) checked next',
	"const isl0 = e.target.closest && e.target.closest( '.minn-block-island[data-imgtool]' );": 'hover outline: isRealIsland checked next',
};

const results = [];
const check = ( label, ok, detail = '' ) => {
	results.push( ok );
	console.log( `${ ok ? 'PASS' : 'FAIL' }  ${ label }${ detail ? ' — ' + detail : '' }` );
};

const seen = new Set();
const unlisted = [];
src.forEach( ( line, i ) => {
	if ( ! LOOKUP.test( line ) ) return;
	const t = line.trim();
	// A lookup that tests what it found on the same line is the helper's.
	if ( /isRealIsland|islandOf\(|islandByIdx\(/.test( t ) && ! /^(function |let n = |while \( n )/.test( t ) ) {
		return;
	}
	if ( Object.prototype.hasOwnProperty.call( REVIEWED, t ) ) {
		seen.add( t );
		return;
	}
	unlisted.push( `app.js:${ i + 1 }  ${ t.slice( 0, 140 ) }` );
} );
check( 'every island lookup by class or data-island goes through the helpers or is reviewed', 0 === unlisted.length, unlisted.length ? '\n      ' + unlisted.join( '\n      ' ) : '' );
const stale = Object.keys( REVIEWED ).filter( ( t ) => ! seen.has( t ) );
check( 'every reviewed line still exists (remove the ones that do not)', 0 === stale.length, stale.join( ' | ' ).slice( 0, 400 ) );

const failed = results.filter( ( r ) => ! r ).length;
console.log( `\nisland-lookup-guard: ${ results.length - failed }/${ results.length } passed` );
process.exitCode = failed ? 1 : 0;
