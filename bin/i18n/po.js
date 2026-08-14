/**
 * Minimal PO reader/writer for the translation pipeline.
 *
 * Deliberately dependency-free: this repo has no build step and no runtime
 * node_modules outside tests/, and a catalog format that has been stable
 * since 1995 does not need a library to read.
 */
'use strict';
const fs = require( 'fs' );

/** Unescape a PO string literal body. */
const unesc = ( s ) => s
	.replace( /\\n/g, '\n' ).replace( /\\t/g, '\t' ).replace( /\\r/g, '\r' )
	.replace( /\\"/g, '"' ).replace( /\\\\/g, '\\' );

/** Escape a JS string for a PO literal. */
const esc = ( s ) => s
	.replace( /\\/g, '\\\\' ).replace( /"/g, '\\"' )
	.replace( /\n/g, '\\n' ).replace( /\t/g, '\\t' ).replace( /\r/g, '\\r' );

/**
 * Parse a .po / .pot file.
 * @returns {{ header: string, entries: Array }} entries carry
 *   { msgid, msgidPlural, msgctxt, msgstr: [], comments: [], refs: [], flags: [] }
 */
function parsePo( text ) {
	const lines = text.split( /\r?\n/ );
	const entries = [];
	let cur = null;
	let lastKey = null;

	const flush = () => { if ( cur && ( cur.msgid !== null ) ) entries.push( cur ); cur = null; lastKey = null; };
	const fresh = () => ( { msgid: null, msgidPlural: null, msgctxt: null, msgstr: [], comments: [], refs: [], flags: [] } );

	for ( const raw of lines ) {
		const line = raw.trim();
		if ( '' === line ) { flush(); continue; }
		if ( line.startsWith( '#' ) ) {
			if ( ! cur ) cur = fresh();
			if ( line.startsWith( '#.' ) ) cur.comments.push( line.slice( 2 ).trim() );
			else if ( line.startsWith( '#:' ) ) cur.refs.push( line.slice( 2 ).trim() );
			else if ( line.startsWith( '#,' ) ) cur.flags.push( ...line.slice( 2 ).split( ',' ).map( ( f ) => f.trim() ) );
			continue;
		}
		if ( ! cur ) cur = fresh();
		let m;
		if ( ( m = /^msgctxt\s+"(.*)"$/.exec( line ) ) ) { cur.msgctxt = unesc( m[ 1 ] ); lastKey = 'msgctxt'; continue; }
		if ( ( m = /^msgid\s+"(.*)"$/.exec( line ) ) ) { cur.msgid = unesc( m[ 1 ] ); lastKey = 'msgid'; continue; }
		if ( ( m = /^msgid_plural\s+"(.*)"$/.exec( line ) ) ) { cur.msgidPlural = unesc( m[ 1 ] ); lastKey = 'msgidPlural'; continue; }
		if ( ( m = /^msgstr\s*\[(\d+)\]\s+"(.*)"$/.exec( line ) ) ) { const i = +m[ 1 ]; cur.msgstr[ i ] = unesc( m[ 2 ] ); lastKey = 'msgstr' + i; continue; }
		if ( ( m = /^msgstr\s+"(.*)"$/.exec( line ) ) ) { cur.msgstr[ 0 ] = unesc( m[ 1 ] ); lastKey = 'msgstr0'; continue; }
		if ( ( m = /^"(.*)"$/.exec( line ) ) ) {
			const add = unesc( m[ 1 ] );
			if ( 'msgid' === lastKey ) cur.msgid += add;
			else if ( 'msgidPlural' === lastKey ) cur.msgidPlural += add;
			else if ( 'msgctxt' === lastKey ) cur.msgctxt += add;
			else if ( lastKey && lastKey.startsWith( 'msgstr' ) ) {
				const i = +lastKey.slice( 6 );
				cur.msgstr[ i ] = ( cur.msgstr[ i ] || '' ) + add;
			}
		}
	}
	flush();

	let header = '';
	const hIdx = entries.findIndex( ( e ) => '' === e.msgid && ! e.msgctxt );
	if ( hIdx >= 0 ) { header = entries[ hIdx ].msgstr[ 0 ] || ''; entries.splice( hIdx, 1 ); }
	return { header, entries };
}

/** Serialize entries back to .po text. */
function writePo( header, entries ) {
	const out = [ 'msgid ""', 'msgstr ""' ];
	for ( const l of header.split( '\n' ) ) {
		if ( '' === l ) continue;
		out.push( `"${ esc( l ) }\\n"` );
	}
	out.push( '' );
	for ( const e of entries ) {
		for ( const c of e.comments ) out.push( `#. ${ c }` );
		for ( const r of e.refs ) out.push( `#: ${ r }` );
		if ( e.flags.length ) out.push( `#, ${ [ ...new Set( e.flags ) ].join( ', ' ) }` );
		if ( e.msgctxt != null ) out.push( `msgctxt "${ esc( e.msgctxt ) }"` );
		out.push( `msgid "${ esc( e.msgid ) }"` );
		if ( e.msgidPlural != null ) {
			out.push( `msgid_plural "${ esc( e.msgidPlural ) }"` );
			// One slot per form this locale actually has. Forcing a floor of 2
			// broke the round trip for every nplurals=1 locale: Japanese wrote
			// msgstr[0] plus an empty msgstr[1], and re-reading that file
			// handed the validator two forms where the header promises one, so
			// a correct entry was dropped on the NEXT import. Wave 2 adds four
			// more single-form locales, so this is a class, not a Japanese
			// quirk.
			const n = Math.max( e.msgstr.length, 1 );
			for ( let i = 0; i < n; i++ ) out.push( `msgstr[${ i }] "${ esc( e.msgstr[ i ] || '' ) }"` );
		} else {
			out.push( `msgstr "${ esc( e.msgstr[ 0 ] || '' ) }"` );
		}
		out.push( '' );
	}
	return out.join( '\n' );
}

/**
 * Parse a binary .mo file into { msgid => [forms] }. Handles the msgctxt
 * (EOT) and plural (NUL) separators gettext packs into the key/value.
 */
function parseMo( buf ) {
	const magic = buf.readUInt32LE( 0 );
	let le = true;
	if ( 0x950412de === magic ) le = true;
	else if ( 0xde120495 === magic ) le = false;
	else throw new Error( 'not a .mo file' );
	const u32 = ( o ) => ( le ? buf.readUInt32LE( o ) : buf.readUInt32BE( o ) );
	const count = u32( 8 ), oOff = u32( 12 ), tOff = u32( 16 );
	const map = new Map();
	for ( let i = 0; i < count; i++ ) {
		const oLen = u32( oOff + i * 8 ), oPos = u32( oOff + i * 8 + 4 );
		const tLen = u32( tOff + i * 8 ), tPos = u32( tOff + i * 8 + 4 );
		const original = buf.slice( oPos, oPos + oLen ).toString( 'utf8' );
		const translated = buf.slice( tPos, tPos + tLen ).toString( 'utf8' );
		// gettext packs two things into the key: a msgctxt prefix separated
		// by EOT, and the plural source separated by NUL. The translated side
		// packs its plural FORMS the same way. Written as escapes, never as
		// literal control characters: those do not survive a copy-paste.
		const EOT = '\x04', NUL = '\x00';
		const ctxSplit = original.split( EOT );
		const hasCtx = ctxSplit.length > 1;
		const key = hasCtx ? ctxSplit[ 1 ] : ctxSplit[ 0 ];
		const singular = key.split( NUL )[ 0 ];
		if ( ! singular ) continue;
		// A msgctxt entry is a NARROWER reading of the same word, and dropping
		// the context collapses it onto the general one. Last-wins made core's
		// Japanese answer "Site" with テーマ — the translation of "Site" in a
		// theme-picker context — clobbering サイト. The general reading is the
		// right default for a glossary, so a contextual entry only fills a gap
		// it does not already occupy, and never overwrites.
		if ( hasCtx && map.has( singular ) ) continue;
		map.set( singular, translated.split( NUL ) );
	}
	return map;
}

module.exports = { parsePo, writePo, parseMo, escapePo: esc };
