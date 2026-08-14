'use strict';
/**
 * The catalog pipeline's own correctness, checked against two bugs that both
 * corrupted translations SILENTLY — no error, no warning, a plausible catalog
 * that was simply wrong.
 *
 * 1. parseMo dropped msgctxt and keyed on the bare msgid, so a narrow
 *    contextual translation overwrote the general one. WordPress core's
 *    Japanese answered "Site" with テーマ — the word for "Site" in a
 *    theme-picker context — instead of サイト, and "Excerpt" with 抜枠
 *    instead of 抜粋. Everything downstream trusted that glossary.
 *
 * 2. writePo padded plural entries to a floor of TWO msgstr slots. Japanese
 *    has one plural form, so a correct entry was written as msgstr[0] plus an
 *    empty msgstr[1]; re-reading it handed the validator two forms where the
 *    header promises one, and the NEXT import dropped the entry. Four more
 *    single-form locales arrive in wave 2, so this is a class of bug rather
 *    than a Japanese quirk.
 *
 * Both are round-trip properties: write, read back, and the meaning survives.
 */
const assert = require( 'assert' );
const path = require( 'path' );
const { parsePo, writePo, parseMo } = require( '../bin/i18n/po.js' );

let pass = 0, fail = 0;
const check = ( name, fn ) => {
	try { fn(); console.log( `PASS  ${ name }` ); pass++; }
	catch ( e ) { console.log( `FAIL  ${ name }\n      ${ e.message }` ); fail++; }
};

/** Build a minimal little-endian .mo in memory from [original, translated] pairs. */
function buildMo( pairs ) {
	const enc = ( s ) => Buffer.from( s, 'utf8' );
	const n = pairs.length;
	const head = 28, tables = head + n * 16;
	const originals = pairs.map( ( p ) => enc( p[ 0 ] ) );
	const translated = pairs.map( ( p ) => enc( p[ 1 ] ) );
	let off = tables;
	const oMeta = [], tMeta = [];
	for ( const b of originals ) { oMeta.push( [ b.length, off ] ); off += b.length + 1; }
	for ( const b of translated ) { tMeta.push( [ b.length, off ] ); off += b.length + 1; }
	const buf = Buffer.alloc( off );
	buf.writeUInt32LE( 0x950412de, 0 );
	buf.writeUInt32LE( 0, 4 );
	buf.writeUInt32LE( n, 8 );
	buf.writeUInt32LE( head, 12 );
	buf.writeUInt32LE( head + n * 8, 16 );
	for ( let i = 0; i < n; i++ ) {
		buf.writeUInt32LE( oMeta[ i ][ 0 ], head + i * 8 );
		buf.writeUInt32LE( oMeta[ i ][ 1 ], head + i * 8 + 4 );
		buf.writeUInt32LE( tMeta[ i ][ 0 ], head + n * 8 + i * 8 );
		buf.writeUInt32LE( tMeta[ i ][ 1 ], head + n * 8 + i * 8 + 4 );
	}
	for ( let i = 0; i < n; i++ ) {
		originals[ i ].copy( buf, oMeta[ i ][ 1 ] );
		translated[ i ].copy( buf, tMeta[ i ][ 1 ] );
	}
	return buf;
}

const EOT = '\x04';

check( 'A contextual .mo entry never overwrites the general one', () => {
	// Context entry FIRST, so plain must win on merge order.
	const a = parseMo( buildMo( [
		[ `theme-picker${ EOT }Site`, 'テーマ' ],
		[ 'Site', 'サイト' ],
	] ) );
	assert.strictEqual( a.get( 'Site' )[ 0 ], 'サイト', 'plain entry should win when it comes second' );

	// Plain FIRST, so the context entry must not clobber it.
	const b = parseMo( buildMo( [
		[ 'Site', 'サイト' ],
		[ `theme-picker${ EOT }Site`, 'テーマ' ],
	] ) );
	assert.strictEqual( b.get( 'Site' )[ 0 ], 'サイト', 'context entry should not overwrite the plain one' );
} );

check( 'A contextual entry still fills a gap nothing else covers', () => {
	const m = parseMo( buildMo( [ [ `col${ EOT }Order`, '注文' ] ] ) );
	assert.strictEqual( m.get( 'Order' )[ 0 ], '注文', 'a context-only term should still reach the glossary' );
} );

check( 'A one-form plural survives a write/read round trip', () => {
	const entry = {
		msgid: '%s draft', msgidPlural: '%s drafts', msgctxt: null,
		comments: [], refs: [], flags: [], msgstr: [ '%s件の下書き' ],
	};
	const text = writePo( 'Language: ja\nPlural-Forms: nplurals=1; plural=0;', [ entry ] );
	const back = parsePo( text ).entries.find( ( e ) => e.msgid === '%s draft' );
	assert.ok( back, 'entry survived the round trip' );
	assert.strictEqual( back.msgstr.length, 1,
		`a 1-form locale must read back 1 form, got ${ back.msgstr.length } — the next import would drop this entry` );
	assert.strictEqual( back.msgstr[ 0 ], '%s件の下書き' );
} );

check( 'Multi-form plurals round trip unchanged', () => {
	const entry = {
		msgid: '%s draft', msgidPlural: '%s drafts', msgctxt: null,
		comments: [], refs: [], flags: [], msgstr: [ 'один', 'два', 'много' ],
	};
	const back = parsePo( writePo( 'Language: ru_RU', [ entry ] ) ).entries[ 0 ];
	assert.deepStrictEqual( back.msgstr, [ 'один', 'два', 'много' ] );
} );

check( 'Every shipped catalog reads back the form count its header promises', () => {
	const fs = require( 'fs' );
	const { LOCALES, nplurals } = require( '../bin/i18n/locales.js' );
	const dir = path.resolve( __dirname, '../languages' );
	const bad = [];
	for ( const loc of LOCALES ) {
		const p = path.join( dir, `${ loc.code }.po` );
		if ( ! fs.existsSync( p ) ) continue;
		const np = nplurals( loc );
		for ( const e of parsePo( fs.readFileSync( p, 'utf8' ) ).entries ) {
			if ( e.msgidPlural == null || ! e.msgstr.some( Boolean ) ) continue;
			if ( e.msgstr.length !== np ) {
				bad.push( `${ loc.code } ${ JSON.stringify( e.msgid.slice( 0, 30 ) ) }: want ${ np }, got ${ e.msgstr.length }` );
			}
		}
	}
	assert.strictEqual( bad.length, 0, `\n      ${ bad.slice( 0, 6 ).join( '\n      ' ) }` );
} );

console.log( `\npo-roundtrip: ${ pass }/${ pass + fail } passed` );
process.exit( fail ? 1 : 0 );
