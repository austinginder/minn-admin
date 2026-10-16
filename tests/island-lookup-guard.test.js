/**
 * The editor finds its islands and its own markers by reference, never by
 * what content can wear (no browser, <1 s).
 *
 * Post kses keeps class and data-*, so content can carry an element wearing
 * any of Minn's island classes (.minn-block-island, .minn-details-island,
 * .minn-buttons-island, .minn-cols-island, …), a data-island="K", or any
 * data-minn-* marker. Anything that reaches ed.islands[] must find its
 * island through isRealIsland(), islandOf() or islandByIdx() (an island Minn
 * rendered: contenteditable="false", not inside another island's preview),
 * and Minn's one-shot markers (upload placeholders, paste brackets, a rebuilt
 * table) carry this page's random token. Every other lookup by those classes
 * or markers is listed below with why it cannot steer a write: it only
 * excludes islands (a look-alike then makes Minn do less), a real-island test
 * follows it, or the marker only ever describes its own element.
 *
 * A new bare lookup fails until it uses the helpers or is listed here with
 * its reason; a listed line that no longer exists fails too, so the list
 * stays true.
 */
const fs = require( 'fs' );
const path = require( 'path' );

const src = fs.readFileSync( path.join( __dirname, '..', 'assets', 'js', 'app.js' ), 'utf8' ).split( '\n' );
// Every Minn island class (minn-block-island, minn-details-island, …; not
// minn-island-preview and the other chrome inside an island).
const ISL = "minn-(?:[a-z]+-)*island(?![a-z-])";
const LOOKUP = new RegExp( [
	"(closest|querySelector(All)?|matches|\\$\\$?)\\(\\s*['`\"][^'`\"]*\\." + ISL,
	"classList\\.contains\\(\\s*'" + ISL + "'\\s*\\)",
	"\\[data-(island|block|shortcode)[\\]=]",
	"\\[data-minn-[a-z-]+",
	"dataset\\.minn[A-Z][A-Za-z]*",
	"(get|has)Attribute\\(\\s*'data-minn-",
].join( '|' ) );
// A lookup that tests what it found on the same line is the helpers'.
const GUARDED = /isRealIsland|islandOf\(|islandByIdx\(|isUploadPlaceholder|uploadPlaceholder\(|isPasteBracket/;
const HELPER_ITSELF = /^(function |let n = |while \( n |return !! el && 1 === el\.nodeType && el\.classList)/;

// Trimmed source line => why it is safe.
const REVIEWED = {
	// The helpers themselves.
	"return !! el && 1 === el.nodeType && el.classList.contains( 'minn-block-island' )": 'isRealIsland() itself',
	"&& 0 === String( el.getAttribute( 'data-minn-upload' ) || '' ).indexOf( UPLOAD_KEY );": 'isUploadPlaceholder(): only this page\'s token',
	"return host ? $$( 'figure[data-minn-upload]', host ).find( ( f ) => f.getAttribute( 'data-minn-upload' ) === key ) || null : null;": 'uploadPlaceholder(): this page\'s key, under the editor body',
	"if ( ! island || ! island.classList.contains( 'minn-block-island' ) || 'false' !== island.getAttribute( 'contenteditable' ) ) return null;": 'previewIsland(): the same test',
	"return el.closest( '.minn-block-island' ) === island ? island : null;": 'previewIsland(): compares to a real island',

	// Compared with, or asked of, an island already known to be real.
	"const isl = slot.closest( '.minn-block-island' );": 'stampSlotDirtyFor: a dirty stamp on a look-alike is ignored, flushSlotIsland takes real islands only',
	"isl.dataset.minnSlotDirty = '1';": 'stampSlotDirtyFor: read only by flushSlotIsland, which takes real islands only',
	"const slots = $$( '.minn-slot', el ).filter( ( s ) => 'true' === s.getAttribute( 'contenteditable' ) && s.closest( '.minn-slot-island' ) === el );": 'flushSlotIsland: el is real; Minn-rendered slots of that island',
	"if ( el.classList.contains( 'minn-cols-island' ) ) {": 'flushSlotIsland: el is real',
	".filter( ( sl ) => 'true' === sl.getAttribute( 'contenteditable' ) && sl.closest( '.minn-slot-island' ) === island )": 'columnOp: island is real; Minn-rendered slots of that island',
	"if ( ! island || ! island.classList.contains( 'minn-buttons-island' ) ) return;": 'removeButtonsRow: island came from islandOf()',
	"if ( island.classList.contains( 'minn-slot-island' ) ) return;": 'armIslandRuns loop over real islands; exclusion',
	"if ( islandEl.classList.contains( 'minn-slot-island' ) ) flushSlotIsland( islandEl, ed.islands );": 'flushSlotIsland re-checks isRealIsland',
	"if ( ! colsIsland || ! colsIsland.classList.contains( 'minn-cols-island' ) || slot.closest( '.minn-slot-island' ) !== colsIsland ) return { slot: null, slots: [] };": 'islandSiblingSlots: colsIsland came from islandOf()',
	"return { slot, slots: $$( '.minn-slot', colsIsland ).filter( ( s ) => realSlot( s ) && s.closest( '.minn-slot-island' ) === colsIsland ) };": 'islandSiblingSlots: Minn-rendered slots of a real island',
	"if ( n.classList.contains( 'minn-slot-island' ) ) flushSlotIsland( n, islands );": 'flushSlotIsland re-checks isRealIsland',
	"const isSlotIsland = islandEl && islandEl.classList && islandEl.classList.contains( 'minn-slot-island' );": 'content editor: islandEl is the inspected real island',
	"const isSlot = insp.islandEl && insp.islandEl.classList && insp.islandEl.classList.contains( 'minn-slot-island' );": 'inspector: insp.islandEl is real',
	"if ( insp.islandEl && insp.islandEl.classList.contains( 'minn-slot-island' ) ) {": 'inspector: insp.islandEl is real',
	"if ( islandEl && islandEl.classList.contains( 'minn-details-island' ) ) {": 'inspector: islandEl is insp.islandEl or islandByIdx()',
	"if ( slot.closest( '.minn-slot-island' ) !== island ) return;": 'column menu: island came from islandOf()',

	// Exclusions: a look-alike only makes Minn do less.
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
	"} else if ( n.classList && n.classList.contains( 'minn-block-island' ) ) {": 'paste: a clipboard island pastes as its visible preview, never as an island',
	"const slotIdx = new Set( $$( '.minn-slot-island', body ).filter( isRealIsland ).map( ( el ) => parseInt( el.dataset.island, 10 ) ) );": 'guarded on the line',

	// Geometry, hover and preventDefault only.
	"if ( ! body.querySelector( '.minn-block-island .minn-block-island > .minn-island-chip' ) ) return;": 'chip layout early-out; the layout itself takes real islands',
	"for ( let anc = own.parentElement && own.parentElement.closest( '.minn-block-island' ); anc;": 'chip layout geometry only',
	"anc = anc.parentElement && anc.parentElement.closest( '.minn-block-island' ) ) {": 'chip layout geometry only',
	"if ( e.target.closest( '.minn-block-island[data-imgtool] > .minn-island-preview, .minn-block-island[data-cted] > .minn-island-preview' ) ) e.preventDefault();": 'preventDefault only',
	"const prev = e.target.closest( '.minn-block-island[data-imgtool] > .minn-island-preview, .minn-block-island[data-cted] > .minn-island-preview' );": 'preventDefault only',
	": ( ! cbadge0 && e.target.closest && e.target.closest( '.minn-block-island[data-cted] > .minn-island-preview' ) );": 'doorway: isRealIsland( parent ) checked next',
	": ( ! badge0 && e.target.closest && e.target.closest( '.minn-block-island[data-imgtool] > .minn-island-preview' ) );": 'doorway: isRealIsland( parent ) checked next',
	"const preview = e.target.closest && e.target.closest( '.minn-block-island > .minn-island-preview' );": 'context menu: isRealIsland( parent ) checked next',
	"const isl0 = e.target.closest && e.target.closest( '.minn-block-island[data-imgtool]' );": 'hover outline: isRealIsland checked next',

	// Markers that describe only their own element.
	"const offered = ( state.cache.pluginUpdates || {} )[ file + '.php' ] || btn.dataset.minnOffered || '';": 'plugin card button Minn rendered, not editor content',
	"if ( offered ) btn.dataset.minnOffered = offered;": 'plugin card button Minn rendered, not editor content',
	"const raw = el.dataset ? el.dataset.minnAttrs : null;": 'takeMinnAttrs: an element\'s own block attributes, which its writer could put in the block comment anyway',
	"if ( ! ( el.dataset && el.dataset.minnAttrs ) ) el.removeAttribute( 'style' );": 'own block attributes (style kept)',
	"const carrier = anchorEl && anchorEl.closest ? anchorEl.closest( '[data-minn-attrs]' ) : null;": 'Enter split: clears the marker off the empty half of the same block',
	"const stamp = carrier.dataset.minnAttrs;": 'Enter split: same block',
	"if ( sib && sib.dataset && sib.dataset.minnAttrs === stamp": 'Enter split: same block',
	"const mk = runAnchorEl && runAnchorEl.closest ? runAnchorEl.closest( '[data-minn-attrs]' ) : null;": 'island run Enter split: same block',
	"try { parked = JSON.parse( ( figure || img ).dataset.minnAttrs || '{}' ) || {}; } catch ( e ) { parked = {}; }": 'image popover: the figure\'s own attributes',
	"try { return JSON.parse( fig.dataset.minnAttrs || '{}' ) || {}; } catch ( e ) { return {}; }": 'image popover: the figure\'s own attributes',
	"if ( Object.keys( attrs ).length ) fig.dataset.minnAttrs = JSON.stringify( attrs );": 'image popover: the figure\'s own attributes',
	"if ( host.dataset.minnAttrs ) {": 'own block attributes',
	"const a = JSON.parse( host.dataset.minnAttrs );": 'own block attributes',
	"host.dataset.minnAttrs = JSON.stringify( a );": 'own block attributes',
	"if ( blockEl && blockEl.tagName === 'P' && ! blockEl.dataset.minnAttrs && blockEl.textContent.trim() === '``' && upto.endsWith( '``' ) ) {": 'markdown shortcut: exclusion',
	"if ( ! blockEl || blockEl.tagName !== 'P' || blockEl.dataset.minnAttrs ) return;": 'markdown shortcut: exclusion',
	"if ( blockEl && blockEl.tagName === 'P' && ! blockEl.dataset.minnAttrs && blockEl.textContent.trim() === '--' && upto.endsWith( '--' ) ) {": 'markdown shortcut: exclusion',
	"fig.dataset.minnAttrs = JSON.stringify( { id: it.id } );": 'upload landed: written on the placeholder uploadPlaceholder() found',
	"$$( '[data-minn-acfimg]', clone ).forEach( ( n ) => {": 'clipboard copy: strips the markers',
	"$$( '[data-minn-acfimg]', preview ).forEach( ( n ) => {": 'armAcfImages: clears then re-stamps the markers on that island\'s preview',
	"const acfImg = e.target.closest( '.minn-island-preview [data-minn-acfimg]' );": 'ACF image doorway: opens a field of the island it sits in (previewIsland)',
	"if ( island ) editAcfMediaField( island, img.getAttribute( 'data-minn-acfimg' ), { id: img.getAttribute( 'data-minn-acfimg-id' ) } );": 'ACF image doorway: the island\'s own field',
	"const media = own ? acfMediaField( String( own.dataset.block || '' ), el.getAttribute( 'data-minn-acfimg' ) ) : null;": 'ACF image doorway: the island\'s own field',
	"const el = t && t.closest && t.closest( '.minn-island-preview [data-acf-inline-fields], .minn-island-preview [data-minn-acfimg]' );": 'hover only',
	"const readEl = preview.querySelector( '[data-minn-acf-read]' );": 'island runs: which of the island\'s own fields read inline',
	"const read = new Set( readEl ? readEl.getAttribute( 'data-minn-acf-read' ).split( /\\s+/ ) : [] );": 'island runs: the island\'s own fields',
	"const marked = editorBody.querySelector( `[data-minn-tbl=\"${ marker }\"]` );": 'table rebuild: one-shot marker with this page\'s token',
	"$$( '[data-minn-bkt]', clone ).filter( isPasteBracket ).forEach( ( el ) => el.removeAttribute( 'data-minn-bkt' ) );": 'guarded on the line',
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
	if ( GUARDED.test( t ) && ! HELPER_ITSELF.test( t ) && ! Object.prototype.hasOwnProperty.call( REVIEWED, t ) ) return;
	if ( Object.prototype.hasOwnProperty.call( REVIEWED, t ) ) {
		seen.add( t );
		return;
	}
	unlisted.push( `app.js:${ i + 1 }  ${ t.slice( 0, 140 ) }` );
} );
check( 'every island-class or Minn-marker lookup goes through the helpers or is reviewed', 0 === unlisted.length, unlisted.length ? '\n      ' + unlisted.join( '\n      ' ) : '' );
const stale = Object.keys( REVIEWED ).filter( ( t ) => ! seen.has( t ) );
check( 'every reviewed line still exists (remove the ones that do not)', 0 === stale.length, stale.join( ' | ' ).slice( 0, 400 ) );

// The island write paths each prove the island is real themselves, so a
// caller that found it by class cannot hand them a look-alike.
const body = src.join( '\n' );
const fnBody = ( name ) => {
	const at = body.indexOf( `function ${ name }(` );
	if ( at < 0 ) return '';
	const end = body.indexOf( '\n\t}\n', at );
	return body.slice( at, end < 0 ? at + 2000 : end );
};
[ 'commitDetailsIsland', 'commitButtonsIsland', 'addButtonsRow', 'columnOp', 'flushSlotIsland' ].forEach( ( fn ) => {
	check( `${ fn } refuses an island Minn did not render`, /isRealIsland\(/.test( fnBody( fn ) ) );
} );
check( 'removeButtonsRow finds its island through islandOf()', /islandOf\(/.test( fnBody( 'removeButtonsRow' ) ) );
check( 'commitShortcodeInput finds its island through islandOf()', /islandOf\(/.test( fnBody( 'commitShortcodeInput' ) ) );
check( 'the serializer flush commits real Details and Buttons islands only',
	/'\.minn-details-island', root \)\.filter\( isRealIsland \)/.test( body ) && /'\.minn-buttons-island', root \)\.filter\( isRealIsland \)/.test( body ) );
check( 'upload placeholders carry the page token', /const key = UPLOAD_KEY \+/.test( body ) && ! /'u' \+ \( \+\+uploadSeq \)/.test( body ) );
check( 'paste never rebuilds an island from clipboard HTML', ! /islandHtml\( idx, n\.dataset\.block/.test( body ) );

const failed = results.filter( ( r ) => ! r ).length;
console.log( `\nisland-lookup-guard: ${ results.length - failed }/${ results.length } passed` );
process.exitCode = failed ? 1 : 0;
