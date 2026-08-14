/**
 * Exports the entries a locale still needs into numbered chunk files, ready
 * for a translator (human or model) to fill in.
 *
 * Entries WordPress core already answers are excluded, and the core terms
 * relevant to what remains ride along as a glossary so the two halves of the
 * catalog use the same vocabulary.
 *
 * Usage: node export-batch.js <locale> [--out DIR] [--chunk N]
 */
'use strict';
const fs = require( 'fs' );
const path = require( 'path' );

const { parsePo } = require( './po.js' );
const { loadCoreGlossary, buildNormIndex, lookup, coreMayAnswer } = require( './core-glossary.js' );
const { byCode, nplurals } = require( './locales.js' );

const ROOT = path.resolve( __dirname, '../..' );
const POT = path.join( ROOT, 'languages/minn-admin.pot' );

const code = process.argv[ 2 ];
const loc = code && byCode( code );
if ( ! loc ) { console.error( 'usage: node export-batch.js <locale> [--out DIR] [--chunk N]' ); process.exit( 2 ); }

const outFlag = process.argv.indexOf( '--out' );
const OUT = outFlag > 0 ? process.argv[ outFlag + 1 ] : '/tmp/minn-i18n';
const chunkFlag = process.argv.indexOf( '--chunk' );
const CHUNK = chunkFlag > 0 ? Number( process.argv[ chunkFlag + 1 ] ) : 400;

const np = nplurals( loc );
const { entries } = parsePo( fs.readFileSync( POT, 'utf8' ) );
const glossary = loadCoreGlossary( loc.code );
const normIdx = buildNormIndex( glossary );

// Carry forward anything a human already reviewed. With --missing-only,
// carry forward everything already translated too, so a top-up pass asks
// only for what is genuinely still absent.
const missingOnly = process.argv.includes( '--missing-only' );
const outPath = path.join( ROOT, 'languages', `${ loc.code }.po` );
const reviewed = new Set();
const already = new Set();
if ( fs.existsSync( outPath ) ) {
	for ( const e of parsePo( fs.readFileSync( outPath, 'utf8' ) ).entries ) {
		if ( e.flags.includes( 'minn-reviewed' ) ) reviewed.add( e.msgid );
		if ( e.msgstr.some( Boolean ) ) already.add( e.msgid );
	}
}

const todo = [];
for ( const e of entries ) {
	if ( reviewed.has( e.msgid ) ) continue;
	if ( missingOnly && already.has( e.msgid ) ) continue;
	const core = e.msgidPlural == null ? lookup( glossary, normIdx, e.msgid ) : null;
	if ( core && coreMayAnswer( e.msgid ) ) continue;
	const item = { id: todo.length, source: e.msgid };
	if ( e.msgidPlural != null ) item.plural_source = e.msgidPlural;
	// A single word core also has: offered as a suggestion, not imposed.
	// Core answers for core's meaning, and "Table" is furniture there.
	if ( core && core.forms[ 0 ] ) item.core_suggestion = core.forms[ 0 ];
	const note = e.comments.find( ( c ) => /^translators\s*:/i.test( c ) );
	if ( note ) item.note = note.replace( /^translators\s*:\s*/i, '' );
	todo.push( item );
}

// A compact glossary of the core terms that actually appear in what remains.
const words = new Set();
for ( const t of todo ) {
	for ( const w of t.source.split( /[^A-Za-z]+/ ) ) if ( w.length > 2 ) words.add( w );
}
const gloss = {};
for ( const w of words ) {
	const hit = glossary.get( w ) || glossary.get( w[ 0 ].toUpperCase() + w.slice( 1 ).toLowerCase() );
	if ( hit && hit[ 0 ] && hit[ 0 ] !== w ) gloss[ w ] = hit[ 0 ];
}

fs.mkdirSync( OUT, { recursive: true } );
const chunks = [];
for ( let i = 0; i < todo.length; i += CHUNK ) chunks.push( todo.slice( i, i + CHUNK ) );

chunks.forEach( ( items, i ) => {
	const file = path.join( OUT, `${ loc.code }.${ String( i + 1 ).padStart( 2, '0' ) }.json` );
	fs.writeFileSync( file, JSON.stringify( {
		locale: loc.code,
		language: loc.name,
		rtl: !! loc.rtl,
		plural_forms: loc.plural,
		nplurals: np,
		chunk: `${ i + 1 } of ${ chunks.length }`,
		glossary: gloss,
		entries: items,
	}, null, 1 ) );
} );

console.log( `${ loc.code }: ${ todo.length } entries in ${ chunks.length } chunk(s) -> ${ OUT }/${ loc.code }.NN.json` );
console.log( `  ${ np } plural form(s); glossary terms: ${ Object.keys( gloss ).length }` );
