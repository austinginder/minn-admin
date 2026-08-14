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
const { loadCoreGlossary, buildNormIndex, lookup } = require( './core-glossary.js' );
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
const reviewed = new Map();
if ( fs.existsSync( outPath ) ) {
	for ( const e of parsePo( fs.readFileSync( outPath, 'utf8' ) ).entries ) {
		if ( e.flags.includes( 'minn-reviewed' ) ) reviewed.set( e.msgid, e );
	}
}

// Rebuild the same todo ordering the exporter used, so ids line up.
const todo = [];
for ( const e of potEntries ) {
	if ( reviewed.has( e.msgid ) ) continue;
	if ( e.msgidPlural == null && lookup( glossary, normIdx, e.msgid ) ) continue;
	todo.push( e );
}

// Collect the translated chunks.
const translated = new Map();
let files = 0;
for ( const f of fs.readdirSync( OUT ).sort() ) {
	if ( ! f.startsWith( `${ loc.code }.` ) || ! f.endsWith( '.done.json' ) ) continue;
	files++;
	let data;
	try { data = JSON.parse( fs.readFileSync( path.join( OUT, f ), 'utf8' ) ); }
	catch ( e ) { console.error( `  unparseable: ${ f } (${ e.message })` ); continue; }
	for ( const t of data.entries || [] ) {
		if ( typeof t.id !== 'number' ) continue;
		const forms = Array.isArray( t.forms ) ? t.forms : ( typeof t.translation === 'string' ? [ t.translation ] : null );
		if ( ! forms ) continue;
		translated.set( t.id, forms );
	}
}

// Assemble the catalog: reviewed, then core, then translated.
const out = [];
let nReviewed = 0, nCore = 0, nNew = 0;
let i = 0;
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
	if ( e.msgidPlural == null ) {
		const hit = lookup( glossary, normIdx, e.msgid );
		if ( hit && hit.forms[ 0 ] ) {
			entry.msgstr = [ hit.forms[ 0 ] ];
			out.push( entry ); nCore++; continue;
		}
	}
	const forms = translated.get( i );
	i++;
	if ( forms && forms.some( Boolean ) ) { entry.msgstr = forms; nNew++; }
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
console.log( `  reviewed ${ nReviewed } | core ${ nCore } | new ${ nNew }` );
console.log( `  kept ${ kept.length } (${ pct }%), dropped ${ dropped.length }` );
for ( const d of dropped.slice( 0, 8 ) ) {
	console.log( `    DROP ${ JSON.stringify( d.entry.msgid.slice( 0, 44 ) ) }: ${ d.reason }` );
}
