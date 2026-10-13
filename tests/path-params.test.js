/**
 * Path parameters are read from the URL, never the merged request bag
 * (no browser, ~1s).
 *
 * $request['id'] resolves the JSON body, then the POST body, then the query
 * string, and only then the URL segment, so a body id can name a different
 * row than the one the permission callback authorized and the confirm
 * dialog described. A route callback or permission callback that reads a
 * parameter its own path declares goes through Minn_Admin::path_param() (or
 * get_url_params()).
 *
 * For every register_rest_route() whose path declares (?P<name>…), this
 * finds the route's callback and permission_callback (a closure in place,
 * or a named function or method) and fails on $request['name'] inside it.
 * A helper the callback hands the request to is not followed.
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

// Named functions and methods anywhere in the plugin: name => [{ rel, body, param }].
const named = {};
for ( const f of files ) {
	for ( const m of f.src.matchAll( /function\s+&?\s*([A-Za-z_][A-Za-z0-9_]*)\s*\(/g ) ) {
		const open = m.index + m[ 0 ].length - 1;
		const close = matchClose( f.src, open );
		const params = f.src.slice( open + 1, close - 1 );
		const brace = f.src.indexOf( '{', close );
		const semi = f.src.indexOf( ';', close );
		if ( -1 === brace || ( -1 !== semi && semi < brace ) ) {
			continue;
		}
		const pm = params.match( /\$([A-Za-z_][A-Za-z0-9_]*)/ );
		( named[ m[ 1 ] ] ||= [] ).push( { rel: f.rel, body: f.src.slice( brace, matchClose( f.src, brace ) ), param: pm ? pm[ 1 ] : null, line: f.src.slice( 0, m.index ).split( '\n' ).length } );
	}
}

// The bodies a callback value names: a closure in place, 'fn', array( X, 'method' ).
function bodies( value ) {
	const out = [];
	const fn = value.match( /^\s*(?:static\s+)?function\s*\(/ );
	if ( fn ) {
		const open = value.indexOf( '(' );
		const close = matchClose( value, open );
		const pm = value.slice( open + 1, close - 1 ).match( /\$([A-Za-z_][A-Za-z0-9_]*)/ );
		const brace = value.indexOf( '{', close );
		if ( -1 !== brace ) {
			out.push( { body: value.slice( brace, matchClose( value, brace ) ), param: pm ? pm[ 1 ] : null, where: 'closure' } );
		}
		return out;
	}
	const str = value.match( /^\s*'([A-Za-z_][A-Za-z0-9_]*)'\s*$/ );
	const arr = value.match( /^\s*array\s*\(\s*[^,]+,\s*'([A-Za-z_][A-Za-z0-9_]*)'\s*\)\s*$/ ) || value.match( /^\s*\[\s*[^,]+,\s*'([A-Za-z_][A-Za-z0-9_]*)'\s*\]\s*$/ );
	const name = ( str || arr || [] )[ 1 ];
	for ( const n of named[ name ] || [] ) {
		out.push( { body: n.body, param: n.param, where: `${ n.rel }:${ n.line } ${ name }()` } );
	}
	return out;
}

// The value of `'key' => …` at the top level of an array literal's text.
function topValues( text, key ) {
	const out = [];
	const re = new RegExp( `'${ key }'\\s*=>\\s*`, 'g' );
	for ( const m of text.matchAll( re ) ) {
		let i = m.index + m[ 0 ].length;
		const start = i;
		let depth = 0;
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
			if ( ( ',' === c || ')' === c || ']' === c ) && 0 === depth ) {
				break;
			}
			i++;
		}
		out.push( text.slice( start, i ) );
	}
	return out;
}

let routes = 0;
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
		let depth = 0;
		let k = 0;
		let commas = [];
		for ( let i = 0; i < args.length; i++ ) {
			const c = args[ i ];
			if ( "'" === c || '"' === c ) {
				let j = i + 1;
				while ( j < args.length && args[ j ] !== c ) {
					j += '\\' === args[ j ] ? 2 : 1;
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
		k = commas.length;
		if ( k < 2 ) {
			continue;
		}
		let pathExpr = args.slice( commas[ 0 ] + 1, commas[ 1 ] );
		pathExpr = pathExpr.replace( /\$([A-Za-z_][A-Za-z0-9_]*)/g, ( all, v ) => vars[ v ] || all );
		const params = [ ...new Set( [ ...pathExpr.matchAll( /\(\?P<([A-Za-z_][A-Za-z0-9_]*)>/g ) ].map( ( p ) => p[ 1 ] ) ) ];
		if ( ! params.length ) {
			continue;
		}
		routes++;
		const opts = args.slice( commas[ 1 ] + 1 );
		const line = f.src.slice( 0, m.index ).split( '\n' ).length;
		for ( const key of [ 'callback', 'permission_callback' ] ) {
			for ( const value of topValues( opts, key ) ) {
				for ( const b of bodies( value.trim() ) ) {
					if ( ! b.param ) {
						continue;
					}
					for ( const p of params ) {
						const re = new RegExp( `\\$${ b.param }\\s*\\[\\s*['"]${ p }['"]\\s*\\]`, 'g' );
						const hits = [ ...b.body.matchAll( re ) ].length;
						check( `${ f.rel }:${ line } ${ key } reads {${ p }} from the URL`, 0 === hits, hits ? `${ hits } × $${ b.param }['${ p }'] in ${ b.where }: use Minn_Admin::path_param( $${ b.param }, '${ p }' )` : '' );
					}
				}
			}
		}
	}
}

check( `found routes with path parameters (${ routes })`, routes > 100 );
console.log( `\npath-params: ${ pass }/${ pass + fail } passed` );
process.exit( fail ? 1 : 0 );
