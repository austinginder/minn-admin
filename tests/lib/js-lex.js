/**
 * Lexical scanner for app.js: classifies every offset as code / string /
 * template-text / comment / regex, so a literal sweep can prove a match sits
 * in template TEXT (where ${ } interpolates) rather than in a plain string,
 * a comment, or a regex.
 *
 * Two bugs this had to grow out of, both worth keeping in mind:
 *
 * 1. Nested template literals inside ${ } expressions were blanket-marked as
 *    template text, so HTML held in a single-quoted string inside a nested
 *    expression looked translatable. Scanning is fully recursive now.
 *
 * 2. Regex-vs-division needs real context. After an arrow (=>) or a keyword
 *    like `return`, a slash starts a REGEX; after an identifier or `)` it is
 *    division. Getting this wrong on `( u ) => /^https?:/.test( u ) ? ...`
 *    made the scanner close the "regex" at the slash inside `</a>`, which
 *    swallowed a backtick and left the rest of the file looking like one
 *    enormous template literal.
 */
'use strict';
const fs = require( 'fs' );

const CODE = 0, SQ = 1, DQ = 2, TMPL = 3, COMMENT = 4, REGEX = 5;

// A slash after any of these begins a regex literal, not a division.
const REGEX_AFTER_PUNCT = new Set( '(,=:[!&|?{};+-*%~^<>'.split( '' ) );
const REGEX_AFTER_WORD = new Set( [
	'return', 'typeof', 'instanceof', 'in', 'of', 'new', 'delete', 'void',
	'case', 'do', 'else', 'yield', 'await', 'throw',
] );
const ID = /[A-Za-z0-9_$]/;

function classify( src ) {
	const st = new Uint8Array( src.length );
	const mark = ( from, to, v ) => { for ( let k = from; k < to; k++ ) st[ k ] = v; };

	// Last significant token: a punctuation char, an identifier/keyword, or '=>'.
	let prevTok = '';

	const regexAllowed = () => {
		if ( prevTok === '' ) return true;
		if ( prevTok === '=>' ) return true;
		if ( prevTok.length === 1 && REGEX_AFTER_PUNCT.has( prevTok ) ) return true;
		return REGEX_AFTER_WORD.has( prevTok );
	};

	function scanString( i ) {
		const q = src[ i ];
		let j = i + 1;
		while ( j < src.length ) {
			if ( src[ j ] === '\\' ) { j += 2; continue; }
			if ( src[ j ] === q || src[ j ] === '\n' ) { j++; break; }
			j++;
		}
		mark( i, j, q === "'" ? SQ : DQ );
		return j;
	}

	function scanRegex( i ) {
		let j = i + 1, cls = false, ok = false;
		while ( j < src.length ) {
			const d = src[ j ];
			if ( d === '\\' ) { j += 2; continue; }
			if ( d === '\n' ) break;
			if ( cls ) { if ( d === ']' ) cls = false; }
			else if ( d === '[' ) cls = true;
			else if ( d === '/' ) { ok = true; j++; break; }
			j++;
		}
		if ( ! ok ) return -1;
		while ( j < src.length && /[gimsuyd]/.test( src[ j ] ) ) j++;
		mark( i, j, REGEX );
		return j;
	}

	function scanTemplate( i ) {
		st[ i ] = TMPL;
		let j = i + 1;
		while ( j < src.length ) {
			const c = src[ j ];
			if ( c === '\\' ) { st[ j ] = TMPL; if ( j + 1 < src.length ) st[ j + 1 ] = TMPL; j += 2; continue; }
			if ( c === '`' ) { st[ j ] = TMPL; return j + 1; }
			if ( c === '$' && src[ j + 1 ] === '{' ) {
				st[ j ] = CODE; st[ j + 1 ] = CODE;
				j = scanExpr( j + 2 );
				continue;
			}
			st[ j ] = TMPL; j++;
		}
		return j;
	}

	// Body of a ${ } expression, up to its closing brace.
	function scanExpr( i ) {
		const outer = prevTok;
		prevTok = '{';
		let j = i, depth = 1;
		while ( j < src.length && depth > 0 ) {
			j = step( j, () => depth++, () => depth--, true );
			if ( depth === 0 ) break;
		}
		prevTok = outer;
		return j;
	}

	// One token of code. onOpen/onClose fire for { and } when inExpr.
	function step( j, onOpen, onClose, inExpr ) {
		const c = src[ j ], c2 = src[ j + 1 ];
		if ( c === '/' && c2 === '/' ) { let k = src.indexOf( '\n', j ); if ( k < 0 ) k = src.length; mark( j, k, COMMENT ); return k; }
		if ( c === '/' && c2 === '*' ) { let k = src.indexOf( '*/', j + 2 ); k = k < 0 ? src.length : k + 2; mark( j, k, COMMENT ); return k; }
		if ( c === '/' && regexAllowed() ) {
			const k = scanRegex( j );
			if ( k > 0 ) { prevTok = '/re/'; return k; }
		}
		if ( c === "'" || c === '"' ) { prevTok = 'str'; return scanString( j ); }
		if ( c === '`' ) { prevTok = 'str'; return scanTemplate( j ); }
		if ( ID.test( c ) ) {
			let k = j;
			while ( k < src.length && ID.test( src[ k ] ) ) { st[ k ] = CODE; k++; }
			prevTok = src.slice( j, k );
			return k;
		}
		if ( inExpr && c === '{' ) { onOpen(); st[ j ] = CODE; prevTok = '{'; return j + 1; }
		if ( inExpr && c === '}' ) { onClose(); st[ j ] = CODE; prevTok = '}'; return j + 1; }
		st[ j ] = CODE;
		if ( ! /\s/.test( c ) ) {
			// '=>' is one token: a slash after it starts a regex.
			prevTok = ( c === '>' && prevTok === '=' ) ? '=>' : c;
		}
		return j + 1;
	}

	let i = 0;
	while ( i < src.length ) {
		const next = step( i, () => {}, () => {}, false );
		i = next > i ? next : i + 1;
	}
	return st;
}

module.exports = { classify, CODE, SQ, DQ, TMPL, COMMENT, REGEX };

if ( require.main === module ) {
	const src = fs.readFileSync( process.argv[ 2 ], 'utf8' );
	const st = classify( src );
	const counts = {};
	for ( const v of st ) counts[ v ] = ( counts[ v ] || 0 ) + 1;
	console.log( 'classification counts:', counts, 'total:', src.length );
}
