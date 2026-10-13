/**
 * Path parameters are read from the URL, never the merged request bag
 * (no browser, ~1s).
 *
 * $request['id'] and $request->get_param( 'id' ) resolve the JSON body,
 * then the POST body, then the query string, and only then the URL
 * segment, so a body id can name a different row than the one the
 * permission callback authorized and the confirm dialog described. A route
 * callback or permission callback that reads a parameter its own path
 * declares goes through Minn_Admin::path_param() (or get_url_params()).
 *
 * For every register_rest_route() whose path declares (?P<name>…), this
 * finds the route's callback and permission_callback (a closure in place, a
 * closure assigned to the variable named there earlier in the file, or a
 * named function or method) and fails on $request['name'] or
 * $request->get_param( 'name' ) inside it. A closure or helper the callback
 * hands the request to is followed too, as deep as the hand-offs go: a
 * closure variable called with the request ($resolve( $req )), a function
 * (minn_admin_x( $req )) and a method of the same class or a named one
 * (self::x( $req ), $this->x( $req ), Minn_Admin::x( $req )), where the
 * request becomes whichever parameter it was passed as.
 */
const fs = require( 'fs' );
const path = require( 'path' );

const ROOT = path.join( __dirname, '..' );
let pass = 0;
let fail = 0;
const check = ( label, ok, detail = '' ) => {
	ok ? pass++ : fail++;
	if ( ! ok || process.env.MINN_VERBOSE ) {
		console.log( `${ ok ? 'PASS' : 'FAIL' }  ${ label }${ detail ? ' — ' + detail : '' }` );
	}
};

// PHP source with comments blanked (strings kept, offsets preserved).
function code( src ) {
	let out = '';
	let i = 0;
	while ( i < src.length ) {
		const c = src[ i ];
		const n = src[ i + 1 ];
		if ( "'" === c || '"' === c ) {
			let j = i + 1;
			while ( j < src.length && src[ j ] !== c ) {
				j += '\\' === src[ j ] ? 2 : 1;
			}
			out += src.slice( i, j + 1 );
			i = j + 1;
		} else if ( '/' === c && '*' === n ) {
			const j = src.indexOf( '*/', i + 2 );
			const end = -1 === j ? src.length : j + 2;
			out += src.slice( i, end ).replace( /[^\n]/g, ' ' );
			i = end;
		} else if ( ( '/' === c && '/' === n ) || '#' === c ) {
			let j = src.indexOf( '\n', i );
			j = -1 === j ? src.length : j;
			out += ' '.repeat( j - i );
			i = j;
		} else {
			out += c;
			i++;
		}
	}
	return out;
}

// Index just past the bracket matching the one at `open` (strings skipped).
function matchClose( src, open ) {
	const pairs = { '(': ')', '{': '}', '[': ']' };
	const stack = [ pairs[ src[ open ] ] ];
	let i = open + 1;
	while ( i < src.length && stack.length ) {
		const c = src[ i ];
		if ( "'" === c || '"' === c ) {
			let j = i + 1;
			while ( j < src.length && src[ j ] !== c ) {
				j += '\\' === src[ j ] ? 2 : 1;
			}
			i = j + 1;
			continue;
		}
		if ( pairs[ c ] ) {
			stack.push( pairs[ c ] );
		} else if ( c === stack[ stack.length - 1 ] ) {
			stack.pop();
		}
		i++;
	}
	return i;
}

// Offsets of the commas at the top level of a bracket's contents.
function topCommas( text ) {
	const commas = [];
	let depth = 0;
	for ( let i = 0; i < text.length; i++ ) {
		const c = text[ i ];
		if ( "'" === c || '"' === c ) {
			let j = i + 1;
			while ( j < text.length && text[ j ] !== c ) {
				j += '\\' === text[ j ] ? 2 : 1;
			}
			i = j;
			continue;
		}
		if ( '(' === c || '[' === c || '{' === c ) {
			depth++;
		} else if ( ')' === c || ']' === c || '}' === c ) {
			depth--;
		} else if ( ',' === c && 0 === depth ) {
			commas.push( i );
		}
	}
	return commas;
}

// A parameter list or argument list split at its top-level commas.
function splitTop( text ) {
	const out = [];
	let from = 0;
	for ( const c of topCommas( text ) ) {
		out.push( text.slice( from, c ) );
		from = c + 1;
	}
	out.push( text.slice( from ) );
	return out.map( ( s ) => s.trim() );
}

// The variable a parameter declaration names ($req in `WP_REST_Request $req = null`).
function paramName( decl ) {
	const m = decl.match( /\$([A-Za-z_][A-Za-z0-9_]*)/ );
	return m ? m[ 1 ] : null;
}

function phpFiles( dir ) {
	const out = [];
	for ( const e of fs.readdirSync( dir, { withFileTypes: true } ) ) {
		const p = path.join( dir, e.name );
		if ( e.isDirectory() ) {
			out.push( ...phpFiles( p ) );
		} else if ( e.name.endsWith( '.php' ) ) {
			out.push( p );
		}
	}
	return out;
}

const files = [ path.join( ROOT, 'minn-admin.php' ), ...phpFiles( path.join( ROOT, 'includes' ) ) ].map( ( f ) => ( {
	rel: path.relative( ROOT, f ).split( path.sep ).join( '/' ),
	src: code( fs.readFileSync( f, 'utf8' ) ),
} ) );
const byRel = Object.fromEntries( files.map( ( f ) => [ f.rel, f ] ) );
const lineOf = ( f, at ) => f.src.slice( 0, at ).split( '\n' ).length;

// Class bodies: name => { rel, start, end }.
const classes = {};
for ( const f of files ) {
	for ( const m of f.src.matchAll( /\bclass\s+([A-Za-z_][A-Za-z0-9_]*)[^{;]*\{/g ) ) {
		const open = m.index + m[ 0 ].length - 1;
		classes[ m[ 1 ] ] = { rel: f.rel, start: open, end: matchClose( f.src, open ) };
	}
}
const classAt = ( rel, at ) => Object.entries( classes ).find( ( [ , c ] ) => c.rel === rel && at > c.start && at < c.end )?.[ 0 ] || null;

// Named functions and methods anywhere in the plugin:
// name => [{ rel, start, body, params, line, cls }].
const named = {};
for ( const f of files ) {
	for ( const m of f.src.matchAll( /function\s+&?\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/g ) ) {
		const open = m.index + m[ 0 ].length - 1;
		const close = matchClose( f.src, open );
		const brace = f.src.indexOf( '{', close );
		const semi = f.src.indexOf( ';', close );
		if ( -1 === brace || ( -1 !== semi && semi < brace ) ) {
			continue;
		}
		( named[ m[ 1 ] ] ||= [] ).push( {
			rel: f.rel,
			start: brace,
			body: f.src.slice( brace, matchClose( f.src, brace ) ),
			params: splitTop( f.src.slice( open + 1, close - 1 ) ).map( paramName ),
			line: lineOf( f, m.index ),
			name: m[ 1 ],
			cls: classAt( f.rel, m.index ),
		} );
	}
}

// Closures assigned to a variable: rel => [{ name, at, start, body, params }].
const closures = {};
for ( const f of files ) {
	for ( const m of f.src.matchAll( /\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(?:static\s+)?function\s*\(/g ) ) {
		const open = m.index + m[ 0 ].length - 1;
		const close = matchClose( f.src, open );
		const brace = f.src.indexOf( '{', close );
		if ( -1 === brace ) {
			continue;
		}
		( closures[ f.rel ] ||= [] ).push( {
			name: m[ 1 ],
			at: m.index,
			start: brace,
			body: f.src.slice( brace, matchClose( f.src, brace ) ),
			params: splitTop( f.src.slice( open + 1, close - 1 ) ).map( paramName ),
			line: lineOf( f, m.index ),
		} );
	}
}
// The closure $name holds at `at`: its nearest assignment before that point.
function closureBefore( rel, name, at ) {
	let best = null;
	for ( const c of closures[ rel ] || [] ) {
		if ( c.name === name && c.at < at && ( ! best || c.at > best.at ) ) {
			best = c;
		}
	}
	return best;
}

// The bodies a callback value names: a closure in place, a closure
// variable, 'fn', array( X, 'method' ). `at` is the value's file offset.
function bodies( f, value, at ) {
	const out = [];
	const lead = value.length - value.trimStart().length;
	const v = value.trim();
	const fn = v.match( /^(?:static\s+)?function\s*\(/ );
	if ( fn ) {
		const open = v.indexOf( '(' );
		const close = matchClose( v, open );
		const brace = v.indexOf( '{', close );
		if ( -1 !== brace ) {
			out.push( { rel: f.rel, start: at + lead + brace, body: v.slice( brace, matchClose( v, brace ) ), param: paramName( splitTop( v.slice( open + 1, close - 1 ) )[ 0 ] || '' ), where: 'closure' } );
		}
		return out;
	}
	const cv = v.match( /^\$([A-Za-z_][A-Za-z0-9_]*)$/ );
	if ( cv ) {
		const c = closureBefore( f.rel, cv[ 1 ], at );
		if ( c ) {
			out.push( { rel: f.rel, start: c.start, body: c.body, param: c.params[ 0 ], where: `${ f.rel }:${ c.line } $${ c.name }` } );
		}
		return out;
	}
	const str = v.match( /^'([A-Za-z_][A-Za-z0-9_]*)'$/ );
	const arr = v.match( /^array\s*\(\s*[^,]+,\s*'([A-Za-z_][A-Za-z0-9_]*)'\s*\)$/ ) || v.match( /^\[\s*[^,]+,\s*'([A-Za-z_][A-Za-z0-9_]*)'\s*\]$/ );
	const name = ( str || arr || [] )[ 1 ];
	for ( const n of named[ name ] || [] ) {
		out.push( { rel: n.rel, start: n.start, body: n.body, param: n.params[ 0 ], where: `${ n.rel }:${ n.line } ${ name }()` } );
	}
	return out;
}

// What a call inside a body hands the request to, as the callee's own
// parameter: [{ rel, start, body, param, where }].
function handOffs( b ) {
	const out = [];
	const own = classAt( b.rel, b.start );
	const re = /(\$[A-Za-z_][A-Za-z0-9_]*\s*->\s*[A-Za-z_][A-Za-z0-9_]*|[A-Za-z_\\][A-Za-z0-9_\\]*\s*::\s*[A-Za-z_][A-Za-z0-9_]*|\$[A-Za-z_][A-Za-z0-9_]*|(?<![\w$>:\\])[A-Za-z_][A-Za-z0-9_]*)\s*\(/g;
	for ( const m of b.body.matchAll( re ) ) {
		const open = m.index + m[ 0 ].length - 1;
		const args = splitTop( b.body.slice( open + 1, matchClose( b.body, open ) - 1 ) );
		const idx = args.indexOf( `$${ b.param }` );
		if ( -1 === idx ) {
			continue;
		}
		const callee = m[ 1 ].replace( /\s+/g, '' );
		let targets = [];
		if ( /^\$[A-Za-z_][A-Za-z0-9_]*$/.test( callee ) ) {
			const c = closureBefore( b.rel, callee.slice( 1 ), b.start + m.index );
			if ( c ) {
				targets = [ { rel: b.rel, start: c.start, body: c.body, params: c.params, where: `${ b.rel }:${ c.line } $${ c.name }` } ];
			}
		} else if ( callee.includes( '->' ) ) {
			const [ obj, meth ] = callee.split( '->' );
			if ( '$this' === obj && own ) {
				targets = ( named[ meth ] || [] ).filter( ( n ) => n.cls === own );
			}
		} else if ( callee.includes( '::' ) ) {
			const [ cls, meth ] = callee.split( '::' );
			const name = [ 'self', 'static' ].includes( cls ) ? own : cls.replace( /^\\/, '' );
			targets = ( named[ meth ] || [] ).filter( ( n ) => n.cls && n.cls === name );
		} else {
			targets = ( named[ callee ] || [] ).filter( ( n ) => ! n.cls );
		}
		for ( const t of targets ) {
			const param = t.params[ idx ];
			if ( param ) {
				out.push( { rel: t.rel, start: t.start, body: t.body, param, where: t.where || `${ t.rel }:${ t.line } ${ t.name }()` } );
			}
		}
	}
	return out;
}

// Every merged-bag read of `p` in the body and everything it hands the
// request to: [ 'n × $req[…] in <chain>' ].
function reads( b, p, chain = [], seen = new Set() ) {
	const key = `${ b.rel }:${ b.start }:${ b.param }`;
	if ( ! b.param || seen.has( key ) || chain.length > 8 ) {
		return [];
	}
	seen.add( key );
	const here = [ ...chain, b.where ];
	const out = [];
	const q = `['"]${ p }['"]`;
	const index = [ ...b.body.matchAll( new RegExp( `\\$${ b.param }\\s*\\[\\s*${ q }\\s*\\]`, 'g' ) ) ].length;
	const getp = [ ...b.body.matchAll( new RegExp( `\\$${ b.param }\\s*->\\s*get_param\\s*\\(\\s*${ q }\\s*\\)`, 'g' ) ) ].length;
	if ( index ) {
		out.push( `${ index } × $${ b.param }['${ p }'] in ${ here.join( ' → ' ) }` );
	}
	if ( getp ) {
		out.push( `${ getp } × $${ b.param }->get_param( '${ p }' ) in ${ here.join( ' → ' ) }` );
	}
	for ( const h of handOffs( b ) ) {
		out.push( ...reads( h, p, here, seen ) );
	}
	return out;
}

// The value of `'key' => …` in an array literal's text, with its offset.
function topValues( text, key ) {
	const out = [];
	const re = new RegExp( `'${ key }'\\s*=>\\s*`, 'g' );
	for ( const m of text.matchAll( re ) ) {
		let i = m.index + m[ 0 ].length;
		const start = i;
		while ( i < text.length ) {
			const c = text[ i ];
			if ( "'" === c || '"' === c ) {
				let j = i + 1;
				while ( j < text.length && text[ j ] !== c ) {
					j += '\\' === text[ j ] ? 2 : 1;
				}
				i = j + 1;
				continue;
			}
			if ( '(' === c || '{' === c || '[' === c ) {
				i = matchClose( text, i );
				continue;
			}
			if ( ',' === c || ')' === c || ']' === c ) {
				break;
			}
			i++;
		}
		out.push( { value: text.slice( start, i ), at: start } );
	}
	return out;
}

let routes = 0;
let followed = 0;
for ( const f of files ) {
	// $var = '…(?P<name>…)…'; assignments path expressions may use.
	const vars = {};
	for ( const m of f.src.matchAll( /\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*([^;]*\(\?P<[^;]*);/g ) ) {
		vars[ m[ 1 ] ] = m[ 2 ];
	}
	for ( const m of f.src.matchAll( /register_rest_route\s*\(/g ) ) {
		const open = m.index + m[ 0 ].length - 1;
		const args = f.src.slice( open + 1, matchClose( f.src, open ) - 1 );
		// The path is the second argument.
		const commas = topCommas( args );
		if ( commas.length < 2 ) {
			continue;
		}
		let pathExpr = args.slice( commas[ 0 ] + 1, commas[ 1 ] );
		pathExpr = pathExpr.replace( /\$([A-Za-z_][A-Za-z0-9_]*)/g, ( all, v ) => vars[ v ] || all );
		const params = [ ...new Set( [ ...pathExpr.matchAll( /\(\?P<([A-Za-z_][A-Za-z0-9_]*)>/g ) ].map( ( p ) => p[ 1 ] ) ) ];
		if ( ! params.length ) {
			continue;
		}
		routes++;
		const optsAt = open + 1 + commas[ 1 ] + 1;
		const opts = args.slice( commas[ 1 ] + 1 );
		const line = lineOf( f, m.index );
		for ( const key of [ 'callback', 'permission_callback' ] ) {
			for ( const { value, at } of topValues( opts, key ) ) {
				for ( const b of bodies( f, value, optsAt + at ) ) {
					if ( ! b.param ) {
						continue;
					}
					followed += handOffs( b ).length;
					for ( const p of params ) {
						const hits = reads( b, p );
						check( `${ f.rel }:${ line } ${ key } reads {${ p }} from the URL`, 0 === hits.length, hits.length ? `${ hits.join( '; ' ) }: use Minn_Admin::path_param( $request, '${ p }' )` : '' );
					}
				}
			}
		}
	}
}

check( `found routes with path parameters (${ routes })`, routes > 100 );
check( `followed hand-offs from route callbacks (${ followed })`, followed > 50 );
console.log( `\npath-params: ${ pass }/${ pass + fail } passed` );
process.exit( fail ? 1 : 0 );
