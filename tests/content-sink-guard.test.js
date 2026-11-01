/**
 * Editor content reaches a live string sink only through the neutralizer
 * (no browser, <1 s).
 *
 * Stored and pasted markup is parsed inertly and parked (rtNeutralize)
 * before it touches the page. A string sink parses it AGAIN in the live
 * document, and parse, serialize, parse is not always the same tree: a
 * nested form plus a MathML namespace flip turns an xmp's raw text into a
 * live <img onerror>. So every string hand-off of editor content goes
 * through rtInsertHtml / rtInsertAdjacentHtml, which settle the markup to a
 * fixed point (rtNeutralizedHtml) and insert nothing when it never settles,
 * and a copy into a live element is imported from an inert parse
 * (rtNeutralizeInto) or moved as nodes, never assigned as a string.
 *
 * A new sink fails here until it uses the helpers or is listed with the
 * reason it cannot carry editor content; a listed line that no longer
 * exists fails too, so the list stays true.
 */
const fs = require( 'fs' );
const path = require( 'path' );

const src = fs.readFileSync( path.join( __dirname, '..', 'assets', 'js', 'app.js' ), 'utf8' );
const lines = src.split( '\n' );

const results = [];
const check = ( label, ok, detail = '' ) => {
	results.push( ok );
	console.log( `${ ok ? 'PASS' : 'FAIL' }  ${ label }${ detail ? ' — ' + detail : '' }` );
};
const isComment = ( line ) => /^\s*(\/\/|\*|\/\*)/.test( line );
const fnBody = ( name ) => {
	const at = src.indexOf( `function ${ name }(` );
	if ( at < 0 ) return '';
	const end = src.indexOf( '\n\t}\n', at );
	return src.slice( at, end < 0 ? at + 3000 : end );
};

// insertAdjacentHTML calls that write Minn's own chrome, built from esc()'d
// values or constants (trimmed source line => why).
const ADJACENT_REVIEWED = {
	"row.insertAdjacentHTML( 'afterend', html );": 'order filter bar: orderFilterChipsHtml() escapes every chip',
	"box.insertAdjacentHTML( 'beforeend', buttonsRowHtml( i, {": 'Buttons island row editor: buttonsRowHtml() escapes the row\'s fields into inputs',
	"title.insertAdjacentHTML( 'afterend', `": 'editor notices under the title (backup, crash net, lock, revision): esc()\'d template',
	"fig.insertAdjacentHTML( 'afterend', '<p><br></p>' );": 'constant landing paragraph',
	"blockEl.insertAdjacentHTML( 'beforebegin', '<hr>' );": 'constant separator from the --- shortcut',
};

// innerHTML / outerHTML assignments whose value is a variable or a
// serialized tree (trimmed source line => why it is not a live re-parse of
// editor content).
const ASSIGN_REVIEWED = {
	"stripTagsDoc.body.innerHTML = html || '';": 'stripTags: the inert document',
	"el.innerHTML = html || '';": 'inertParse: the inert document',
	"if ( 'noscript' === el.localName && RT_HTML_NS === el.namespaceURI ) el.innerHTML = inner;": 'rtUnparkRaw: runs on the serializer\'s inert copy (unpark-inert-guard)',
	"existing.outerHTML = html;": 'order filter bar: orderFilterChipsHtml() escapes every chip',
	"pPanel.innerHTML = rows;": 'autocomplete panel rows built with esc()',
	"panel.innerHTML = items.length": 'autocomplete panels: a ternary over esc()\'d rows on the next lines',
	"btn.innerHTML = orig;": 'puts back the button\'s own chrome after a busy state',
	"el.innerHTML = words || session": 'word count chrome built with esc()',
	"w.innerHTML = html;": 'find-in-post marks: positioned empty divs, numbers only',
	"el.innerHTML = s;": 'decodeTextEntities: a textarea parses its value as text (RCDATA)',
	"tbChip.innerHTML = photo": 'ACF toolbar chip: esc()\'d template',
	"body.innerHTML = url": 'form confirmation preview: escaped redirect link or a sandboxed frame',
};

const results0 = { insertHtml: [], adjacent: [], assign: [], api: [] };
const adjSeen = new Set();
const assignSeen = new Set();
// A value that is a bare variable or property, or that reads a serialized
// tree or a block's stored markup.
const ASSIGN = /\.(inner|outer)HTML\s*\+?=\s*(?!=)/;
const RISKY_VALUE = /\.(inner|outer)HTML\s*\+?=\s*(?:[A-Za-z_$][\w$.]*(?:\[[^\]]*\])?\s*(?:;|\|\||$)|.*(?:\.(?:inner|outer)HTML\b|islandHtml\(|editableSegmentHtml\(|stripBlockComments\(|\.raw\b|\.rendered\b|\b(?:slice|r|action|payload)\.html\b))/;
lines.forEach( ( line, i ) => {
	if ( isComment( line ) ) return;
	const t = line.trim();
	const at = `app.js:${ i + 1 }  ${ t.slice( 0, 140 ) }`;
	if ( /execCommand\(\s*['"]insertHTML['"]/.test( t ) && t !== "const done = document.execCommand( 'insertHTML', false, safe );" ) results0.insertHtml.push( at );
	if ( /\.insertAdjacentHTML\(/.test( t ) && t !== 'el.insertAdjacentHTML( where, safe );' ) {
		if ( Object.prototype.hasOwnProperty.call( ADJACENT_REVIEWED, t ) ) adjSeen.add( t );
		else results0.adjacent.push( at );
	}
	if ( ASSIGN.test( t ) && RISKY_VALUE.test( t ) ) {
		if ( Object.prototype.hasOwnProperty.call( ASSIGN_REVIEWED, t ) ) assignSeen.add( t );
		else results0.assign.push( at );
	}
	if ( /createContextualFragment|setHTMLUnsafe|parseHTMLUnsafe|document\.write(ln)?\(/.test( t ) ) results0.api.push( at );
} );

check( 'insertHTML runs only inside rtInsertHtml', 0 === results0.insertHtml.length, results0.insertHtml.length ? '\n      ' + results0.insertHtml.join( '\n      ' ) : '' );
check( 'every insertAdjacentHTML goes through rtInsertAdjacentHtml or is reviewed chrome', 0 === results0.adjacent.length, results0.adjacent.length ? '\n      ' + results0.adjacent.join( '\n      ' ) : '' );
check( 'no innerHTML / outerHTML assignment re-parses a serialized tree or stored markup unreviewed', 0 === results0.assign.length, results0.assign.length ? '\n      ' + results0.assign.join( '\n      ' ) : '' );
check( 'no other live HTML parser entry point', 0 === results0.api.length, results0.api.join( ' | ' ) );
const adjStale = Object.keys( ADJACENT_REVIEWED ).filter( ( t ) => ! adjSeen.has( t ) );
check( 'every reviewed insertAdjacentHTML line still exists', 0 === adjStale.length, adjStale.join( ' | ' ) );
const assignStale = Object.keys( ASSIGN_REVIEWED ).filter( ( t ) => ! assignSeen.has( t ) );
check( 'every reviewed assignment line still exists', 0 === assignStale.length, assignStale.join( ' | ' ) );

// The helpers themselves.
const settle = fnBody( 'rtNeutralizedHtml' );
check( 'rtNeutralizedHtml repeats the inert walk until the markup is a fixed point',
	/for \( let i = 0; i < RT_SETTLE_PASSES; i\+\+ \)/.test( settle ) && /if \( next === s \) return s;/.test( settle ) && /return null;/.test( settle ) );
check( 'rtInsertHtml settles first and inserts nothing it could not settle',
	/const safe = rtNeutralizedHtml\( html \);/.test( fnBody( 'rtInsertHtml' ) ) && /if \( null === safe \)/.test( fnBody( 'rtInsertHtml' ) ) );
// insertAdjacentHTML parses in the target's own context (a content <form>
// or a foreign element above it), which no inert walk reproduces: the
// helper builds the tree inertly and inserts nodes.
check( 'rtInsertAdjacentHtml imports an inert tree instead of parsing in the target\'s context',
	/rtNeutralizeInto\( document\.createElement\( 'div' \), html \)/.test( fnBody( 'rtInsertAdjacentHtml' ) ) && ! /insertAdjacentHTML/.test( fnBody( 'rtInsertAdjacentHtml' ) ) );
// Island previews and Custom HTML wrappers go into joined markup empty and
// are filled as nodes, so stored markup is never glued to its neighbours.
check( 'island previews and Custom HTML wrappers are filled from nodes, never glued into the joined markup',
	/data-minn-fill="\$\{ esc\( previewFill\( inner \) \) \}"><br>/.test( src ) && /data-minn-raw="\$\{ esc\( key \) \}"><br><\/div>/.test( src )
	&& /rtNeutralizeInto\( el, rec\.inner \);/.test( fnBody( 'adoptRawBlocks' ) ) && /if \( ! rtSelfContained\( inner \) \) return null;/.test( fnBody( 'rawBlockHtml' ) ) );
const carriers = ( src.match( /const RT_RAW_CARRIERS = \[([^\]]*)\]/ ) || [] )[ 1 ] || '';
check( 'every raw-text carrier\'s inside is parked',
	[ 'noscript', 'xmp', 'iframe', 'noembed', 'noframes', 'plaintext' ].every( ( c ) => carriers.includes( `'${ c }'` ) ), carriers );
check( 'rtNeutralize parks raw-text insides and rtUnpark puts them back',
	/rtParkRaw\( el \);/.test( fnBody( 'rtNeutralize' ) ) && /rtUnparkRaw\( el \);/.test( fnBody( 'rtUnpark' ) ) );
check( 'the partial-selection copy imports its slice instead of parsing it live',
	/rtNeutralizeInto\( el\.cloneNode\( false \), slice\.html \)/.test( fnBody( 'selectionBlockMarkup' ) ) );
check( 'table header ops move cells\' nodes instead of copying markup',
	! /\.innerHTML = c\.innerHTML/.test( fnBody( 'applyTableMutation' ) ) );

// Every other block glued into the joined markup is checked as well: an
// editable block stays prose only when its markup is self-contained, slot
// wrapper bytes are closed from their own parse (never counted by
// pattern), and freeform counts as comment-only only by the parser.
check( 'an editable block is prose only when its markup is self-contained',
	/return rtSelfContained\( html \) \? html : null;/.test( src ) );
const slotFn = fnBody( 'slotIslandHtml' );
check( 'slot islands close their wrapper bytes from the parse, and fall back to a plain island when they cannot',
	( slotFn.match( /rtSlotClosers\(/g ) || [] ).length >= 3 && ! /domOpenDivs/.test( src ) && ( slotFn.match( /if \( null == (closers|colClose) \) return null;/g ) || [] ).length >= 3 );
check( 'comment-only freeform is decided by the parser, never by a <!--…--> pattern',
	/const slotIgnorableHtml = \( raw \) => rtCommentOnly\( raw \);/.test( src )
	&& /if \( rtCommentOnly\( seg\.raw \) \) return seg\.raw;/.test( fnBody( 'appendEditableContent' ) )
	&& /rtCommentOnly\( seg\.raw \) \? seg\.raw\.trim\(\) : null/.test( slotFn ) );

const failed = results.filter( ( r ) => ! r ).length;
console.log( `\ncontent-sink-guard: ${ results.length - failed }/${ results.length } passed` );
process.exitCode = failed ? 1 : 0;
