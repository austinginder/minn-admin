/**
 * Merges translated chunk files back into a locale's .po.
 *
 * Every entry goes through the validator on the way in, and anything that
 * fails is DROPPED with a reason rather than written. That is the whole point
 * of the round trip: a translation that loses a %s is a fatal sprintf error in
 * somebody else's admin, in a language the author cannot read.
 *
 * Reads <OUT>/<locale>.NN.done.json — the same shape as the export plus a
 * `forms` array on each entry.
 *
 * Usage: node import-batch.js <locale> [--out DIR]
 */
'use strict';
const fs = require( 'fs' );
const path = require( 'path' );

const { parsePo, writePo } = require( './po.js' );
const { loadCoreGlossary, buildNormIndex, lookup, coreMayAnswer } = require( './core-glossary.js' );
const { byCode, nplurals } = require( './locales.js' );
const { validateCatalog } = require( './validate.js' );

const ROOT = path.resolve( __dirname, '../..' );
const POT = path.join( ROOT, 'languages/minn-admin.pot' );

const code = process.argv[ 2 ];
const loc = code && byCode( code );
if ( ! loc ) { console.error( 'usage: node import-batch.js <locale> [--out DIR]' ); process.exit( 2 ); }
const outFlag = process.argv.indexOf( '--out' );
const OUT = outFlag > 0 ? process.argv[ outFlag + 1 ] : '/tmp/minn-i18n';

const np = nplurals( loc );
const { entries: potEntries } = parsePo( fs.readFileSync( POT, 'utf8' ) );
const glossary = loadCoreGlossary( loc.code );
const normIdx = buildNormIndex( glossary );

const outPath = path.join( ROOT, 'languages', `${ loc.code }.po` );

// Human-reviewed translations live in their OWN file, outside the generated
// catalog, and win over everything.
//
// They used to be a `minn-reviewed` flag inside the generated .po, which
// survives a normal re-import and does NOT survive `rm languages/xx.po` — and
// that is a completely ordinary thing to do when regenerating. Three fixes
// were lost that way in one afternoon: a Spanish "¡" with no closing "!", a
// Japanese sentence carrying both "。" and ".", and an Arabic label with two
// short vowels stacked on one letter. A native speaker's correction has to be
// harder to destroy than the thing it corrects.
//
// languages/reviewed/<locale>.po is a plain .po. Anything in it is final.
const reviewed = new Map();
const reviewedPath = path.join( ROOT, 'languages/reviewed', `${ loc.code }.po` );
if ( fs.existsSync( reviewedPath ) ) {
	for ( const e of parsePo( fs.readFileSync( reviewedPath, 'utf8' ) ).entries ) {
		if ( e.msgid && e.msgstr.some( Boolean ) ) reviewed.set( e.msgid, e );
	}
}
// Everything already in the catalog is a base layer. A top-up pass covers
// only the strings that were missing, so without this an import would throw
// away every translation the previous pass produced.
const existing = new Map();
if ( fs.existsSync( outPath ) ) {
	for ( const e of parsePo( fs.readFileSync( outPath, 'utf8' ) ).entries ) {
		if ( e.flags.includes( 'minn-reviewed' ) ) { if ( ! reviewed.has( e.msgid ) ) reviewed.set( e.msgid, e ); }
		else if ( e.msgstr.some( Boolean ) ) existing.set( e.msgid, e.msgstr );
	}
}

// Resolve ids through the EXPORT files, never by recomputing the ordering.
// An id is only meaningful next to the export that issued it: change
// anything upstream (a glossary rule, a new string) and a re-derived list
// shifts, silently pairing every translation with its neighbour's source.
// That happened once and produced a catalog where "Custom fields" was
// translated as "Post not found."
const idToSource = new Map();
let inputFiles = 0;
for ( const f of fs.readdirSync( OUT ).sort() ) {
	if ( ! f.startsWith( `${ loc.code }.` ) || ! f.endsWith( '.json' ) || f.endsWith( '.done.json' ) ) continue;
	inputFiles++;
	const data = JSON.parse( fs.readFileSync( path.join( OUT, f ), 'utf8' ) );
	for ( const e of data.entries || [] ) idToSource.set( e.id, e.source );
}
if ( ! inputFiles ) {
	console.error( `no export files for ${ loc.code } in ${ OUT }; re-run export-batch.js` );
	process.exit( 1 );
}

// Collect the translated chunks, keyed by SOURCE STRING.
const translated = new Map();
let files = 0, orphaned = 0, overSupplied = 0;
for ( const f of fs.readdirSync( OUT ).sort() ) {
	if ( ! f.startsWith( `${ loc.code }.` ) || ! f.endsWith( '.done.json' ) ) continue;
	files++;
	let data;
	try { data = JSON.parse( fs.readFileSync( path.join( OUT, f ), 'utf8' ) ); }
	catch ( e ) { console.error( `  unparseable: ${ f } (${ e.message })` ); continue; }
	for ( const t of data.entries || [] ) {
		if ( typeof t.id !== 'number' ) continue;
		let forms = Array.isArray( t.forms ) ? t.forms : ( typeof t.translation === 'string' ? [ t.translation ] : null );
		if ( ! forms ) continue;
		// Too MANY forms is a miscount, not a mistranslation: gettext reads
		// only 0..nplurals-1, and form 0 is the one this locale uses. Japanese
		// has a single form, and a translator handing back the English
		// singular/plural pair cost 20 entries to a drop that was discarding a
		// correct translation. Too FEW still fails — nothing can be invented.
		if ( forms.length > np ) { forms = forms.slice( 0, np ); overSupplied++; }
		const source = idToSource.get( t.id );
		if ( source == null ) { orphaned++; continue; }
		translated.set( source, forms );
	}
}

// Assemble the catalog: reviewed, then core, then translated.
const out = [];
let nReviewed = 0, nCore = 0, nNew = 0, nSelf = 0, nKept = 0;
for ( const e of potEntries ) {
	const entry = {
		msgid: e.msgid, msgidPlural: e.msgidPlural, msgctxt: e.msgctxt,
		comments: e.comments, refs: e.refs, flags: [], msgstr: [],
	};
	const was = reviewed.get( e.msgid );
	if ( was ) {
		entry.msgstr = was.msgstr; entry.flags = [ 'minn-reviewed' ];
		out.push( entry ); nReviewed++; continue;
	}
	if ( e.msgidPlural == null && coreMayAnswer( e.msgid ) ) {
		const hit = lookup( glossary, normIdx, e.msgid );
		if ( hit && hit.forms[ 0 ] ) {
			entry.msgstr = [ hit.forms[ 0 ] ];
			out.push( entry ); nCore++; continue;
		}
	}
	const forms = translated.get( e.msgid );
	if ( forms && forms.some( Boolean ) ) { entry.msgstr = forms; nNew++; out.push( entry ); continue; }

	// Nothing fresh for this string: keep whatever an earlier pass produced.
	const prev = existing.get( e.msgid );
	if ( prev && prev.some( Boolean ) ) { entry.msgstr = prev; nKept++; out.push( entry ); continue; }

	// An English variant that needs no spelling change IS translated: to
	// itself. Filling these in rather than leaving them blank costs nothing
	// at runtime (a blank falls through to the same text) and keeps the
	// coverage figure honest instead of reporting a hole that isn't one.
	if ( /^en_/.test( loc.code ) ) {
		entry.msgstr = e.msgidPlural != null ? [ e.msgid, e.msgidPlural ] : [ e.msgid ];
		nSelf++;
	}
	out.push( entry );
}

const filled = out.filter( ( e ) => e.msgstr.some( Boolean ) );
const { kept, dropped } = validateCatalog( filled, np );

const stamp = new Date().toISOString().replace( 'T', ' ' ).slice( 0, 16 ) + '+0000';
const header = [
	'Project-Id-Version: Minn Admin',
	'Report-Msgid-Bugs-To: https://github.com/austinginder/minn-admin/issues',
	`PO-Revision-Date: ${ stamp }`,
	'Last-Translator: Minn Admin translation pipeline',
	`Language-Team: ${ loc.name }`,
	'MIME-Version: 1.0',
	'Content-Type: text/plain; charset=UTF-8',
	'Content-Transfer-Encoding: 8bit',
	`Language: ${ loc.code }`,
	`Plural-Forms: ${ loc.plural }`,
	'X-Generator: minn-admin/bin/i18n',
].join( '\n' );

fs.writeFileSync( outPath, writePo( header, kept ) );

const pct = ( kept.length / potEntries.length * 100 ).toFixed( 1 );
console.log( `${ loc.code }: ${ files } chunk file(s), ${ translated.size } translations read` );
if ( orphaned ) console.log( `  ${ orphaned } translation(s) carried an id no export issued, ignored` );
if ( overSupplied ) console.log( `  ${ overSupplied } translation(s) had more plural forms than ${ loc.code } uses, trimmed to ${ np }` );
console.log( `  reviewed ${ nReviewed } | core ${ nCore } | new ${ nNew }${ nKept ? ` | carried ${ nKept }` : '' }${ nSelf ? ` | unchanged ${ nSelf }` : '' }` );
console.log( `  kept ${ kept.length } (${ pct }%), dropped ${ dropped.length }` );
for ( const d of dropped.slice( 0, 8 ) ) {
	console.log( `    DROP ${ JSON.stringify( d.entry.msgid.slice( 0, 44 ) ) }: ${ d.reason }` );
}
