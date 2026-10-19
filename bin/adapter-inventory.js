#!/usr/bin/env node
/**
 * Adapter inventory: measures every bundled adapter so the resource kernel's
 * rollout can be tracked as numbers (docs/adapter-kernel.md). Dev-only; bin/
 * never ships in the plugin zip.
 *
 *   node bin/adapter-inventory.js                    # the working tree
 *   node bin/adapter-inventory.js --ref v0.45.0      # any git ref
 *   node bin/adapter-inventory.js --out <dir>        # write adapters.json and routes.json there
 *   node bin/adapter-inventory.js --record "label"   # also append a snapshot
 *                                                    # to bin/adapter-inventory/history.json
 *
 * Per adapter: lines, lines of code (no blank or comment-only lines, so a
 * comment never counts as code), hand-written REST routes, permission callbacks,
 * capability checks, raw SQL reads and writes, the screen shapes it declares,
 * kernel resources (minn_admin_resource() calls) and kernel handlers
 * (minn_admin_endpoint() calls).
 *
 * The surface measures count the security rules an adapter still writes by
 * hand, the places the registry scans keep finding the same bug in another
 * file: editor-panel save callbacks, held panel refusals, the egress mask
 * compare and refusal (served_as, refuse_masks, unchanged), URL cleaning,
 * and stored text cut by hand rather than through Minn_Admin_Egress::clip().
 * Each should reach 0 outside the kernel as the tiers absorb them
 * (tests/surface-ratchet.test.js). credential_rows counts the rows only the
 * database browser's central lists know about (an entry an adapter also
 * declares does not count), and declared_rows the stores adapters declare
 * with minn_admin_secrets(); the first falls as the second grows.
 *
 * The weight measures count what keeps an adapter from being lean, so the
 * logic it repeats can move into the kernel (tests/weight-ratchet.test.js):
 * vendor_guards (function_exists, class_exists, method_exists and defined
 * calls, the file's own ABSPATH guard aside, and catch blocks), hand_errors
 * (new WP_Error), detect_helpers (top-level functions named
 * minn_admin_*_active, _can, _ready, _allowed, _available, _enabled,
 * _installed or _has_*), cap_checks, and adapter_kernel_lines, the code
 * lines of the adapters (kits included) and of the kernel (includes/kernel/,
 * every tier) together, so a line moved into the kernel is not a saving
 * unless the kernel's copy is smaller. Each hand-written route also gets a
 * kind and
 * a tier: "declarable" (a list, an item, an item action, a status card) or
 * "handler" (a job, a send, a builder, a licence flow and the like). An
 * adapter is "kernel" when it uses the kernel (a resource, an endpoint, or a
 * screen or panel declared on the settings or fields tier) and registers no
 * routes of its own, "partial" when it does both, and "legacy" otherwise.
 *
 * The family comes from bin/demo/catalog.json (the category its plugin is
 * listed under); the catalog's shared helpers are their own family.
 */
const { execFileSync } = require( 'child_process' );
const fs = require( 'fs' );
const path = require( 'path' );

const ROOT = path.resolve( __dirname, '..' );
const ADAPTERS = 'includes/adapters';
const HISTORY = path.join( __dirname, 'adapter-inventory', 'history.json' );
// Where the kernel itself lives once it exists; counted separately, since the
// kernel's lines are the cost the adapters' savings pay for.
const KERNEL_PATHS = [ 'includes/class-minn-admin-resource', 'includes/kernel/' ];

const args = process.argv.slice( 2 );
const flag = ( name ) => {
	const i = args.indexOf( name );
	return i >= 0 ? args[ i + 1 ] : null;
};
const ref = flag( '--ref' );
const outDir = flag( '--out' );
const record = flag( '--record' );

// stderr is dropped: an older ref simply lacks newer files (the catalog), and
// readIfExists() already treats that as absent.
const git = ( ...a ) => execFileSync( 'git', a, { cwd: ROOT, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024, stdio: [ 'ignore', 'pipe', 'ignore' ] } );

// Files and contents, from the working tree or from a ref.
function listFiles( dir ) {
	if ( ref ) {
		return git( 'ls-tree', '-r', '--name-only', ref, dir ).split( '\n' ).filter( Boolean );
	}
	const abs = path.join( ROOT, dir );
	if ( ! fs.existsSync( abs ) ) return [];
	const walk = ( d ) => fs.readdirSync( d, { withFileTypes: true } ).flatMap( ( e ) => {
		const p = path.join( d, e.name );
		return e.isDirectory() ? walk( p ) : [ path.relative( ROOT, p ) ];
	} );
	return walk( abs );
}
function read( file ) {
	return ref ? git( 'show', `${ ref }:${ file }` ) : fs.readFileSync( path.join( ROOT, file ), 'utf8' );
}
function readIfExists( file ) {
	try {
		return read( file );
	} catch ( e ) {
		return null;
	}
}

function familyMap() {
	const raw = readIfExists( 'bin/demo/catalog.json' );
	const map = {};
	if ( ! raw ) return map;
	const cat = JSON.parse( raw );
	( cat.categories || [] ).forEach( ( c ) => ( c.plugins || [] ).forEach( ( p ) => ( p.adapters || [] ).forEach( ( a ) => {
		if ( ! map[ a ] ) map[ a ] = { family: c.title, plugin: p.name };
	} ) ) );
	( cat.shared || [] ).forEach( ( a ) => {
		if ( ! map[ a ] ) map[ a ] = { family: 'Shared helpers', plugin: '' };
	} );
	return map;
}

const count = ( src, re ) => ( src.match( re ) || [] ).length;

// Prefix cuts of 16 characters or more: a credential the egress fence knows
// is at least that long. A hash or a MySQL datetime is never stored text.
function handClips( src ) {
	return src.split( '\n' ).filter( ( line ) => {
		if ( line.includes( 'substr( md5(' ) || line.includes( 'substr( (string) $v, 0, 19 )' ) ) return false;
		const m = line.match( /\b(?:mb_)?substr\(\s*[^,;]+,\s*0,\s*(\d+)\s*\)/ );
		return m && parseInt( m[ 1 ], 10 ) >= 16;
	} ).length;
}

// The text inside the parentheses that open at src[ i ].
function parens( src, i ) {
	let depth = 0;
	let end = i;
	for ( ; end < src.length; end++ ) {
		if ( '(' === src[ end ] ) depth++;
		if ( ')' === src[ end ] && 0 === --depth ) break;
	}
	return src.slice( i + 1, end );
}

// The single-quoted strings in a PHP array body, each with how many
// parentheses deep it sits (0: an entry or key of this array).
function quoted( body ) {
	// Comments first: an apostrophe in one ("Formidable's") is no string.
	body = body.replace( /\/\*[\s\S]*?\*\//g, '' ).replace( /^\s*(\/\/|#).*$/gm, '' );
	const out = [];
	let depth = 0;
	for ( let i = 0; i < body.length; i++ ) {
		const ch = body[ i ];
		if ( '(' === ch ) depth++;
		else if ( ')' === ch ) depth--;
		else if ( "'" === ch ) {
			let j = i + 1;
			while ( j < body.length && "'" !== body[ j ] ) j += '\\' === body[ j ] ? 2 : 1;
			out.push( { value: body.slice( i + 1, j ), depth } );
			i = j;
		}
	}
	return out;
}

// Entries in one of the database browser's credential lists: quoted names
// in a *_KEYS list, array( prefix, suffix ) pairs in a *_SHAPES list (as
// "prefix*suffix", a declaration's spelling).
function constEntries( src, name ) {
	const start = src.indexOf( 'const ' + name + ' = array(' );
	if ( start < 0 ) return [];
	const body = parens( src, src.indexOf( '(', start ) );
	if ( ! name.endsWith( '_SHAPES' ) ) return quoted( body ).map( ( q ) => q.value );
	return quoted( body ).reduce( ( out, q, i, all ) => ( i % 2 ? out.concat( all[ i - 1 ].value + '*' + q.value ) : out ), [] );
}

// The stores adapters declare with minn_admin_secrets(), as "store:name"
// (options and user meta, shapes spelt with their *; a column as
// "column:table.column"). A row's leaves are part of the row, not entries.
function declaredStores( src ) {
	const out = [];
	const re = /\bminn_admin_secrets\(\s*'/g;
	let m;
	while ( ( m = re.exec( src ) ) ) {
		const body = parens( src, m.index + m[ 0 ].indexOf( '(' ) );
		for ( const key of [ 'options', 'usermeta', 'user_tokens', 'columns', 'column_tokens' ] ) {
			const at = body.search( new RegExp( "'" + key + "'\\s*=>\\s*array\\s*\\(" ) );
			if ( at < 0 ) continue;
			const list = quoted( parens( body, body.indexOf( '(', at ) ) );
			if ( 'columns' === key || 'column_tokens' === key ) {
				let table = '';
				list.forEach( ( q ) => {
					if ( 0 === q.depth ) table = q.value;
					else out.push( 'column:' + table + '.' + q.value );
				} );
			} else {
				list.filter( ( q ) => 0 === q.depth ).forEach( ( q ) => out.push( ( 'options' === key ? 'option:' : 'usermeta:' ) + q.value ) );
			}
		}
	}
	return out;
}

// Hand-written routes, each with its path and a kind. The kind says which
// tier a port can reach (docs/adapter-kernel.md, "Three tiers"): the generic
// kinds become declarations, the specialised ones kernel handlers.
const GENERIC_KINDS = new Set( [ 'list', 'item', 'item action', 'status card' ] );
function routePath( expr ) {
	// A path is a literal or a concatenation ('/x/' . $slug); each variable
	// part reads as {var}, and each named group as {id}.
	return expr.split( /\s*\.\s*/ ).map( ( part ) => {
		const q = part.match( /^(['"])(.*)\1$/s );
		return q ? q[ 2 ] : '{var}';
	} ).join( '' ).replace( /\(\?P<[^>]+>[^)]*\)/g, '{id}' );
}
function routeKind( p, methods ) {
	const n = p.toLowerCase().replace( /\/+$/, '' );
	const last = n.split( '/' ).pop();
	if ( /(status|stats|chart|summary|overview|health|counts?|totals?)$/.test( n ) ) return 'status card';
	if ( /settings|options|config|preferences/.test( n ) ) return 'settings tab';
	if ( /(test|send|resend|preview|render|retry|rerun|notify|email)/.test( last ) ) return 'send, preview, retry';
	if ( /activate|deactivate|toggle|enable|disable|license|licence|(^|\/)keys?($|\/)/.test( n ) ) return 'activate, licence';
	if ( /export|import|download|upload|backup|restore|(^|\/)run|start|progress|job|cancel|stop|scan|clone|migrat|push|pull|regenerate|purge|flush|clear/.test( n ) ) return 'job';
	if ( /schema|builder|layout|design|template/.test( n ) ) return 'schema, builder';
	if ( /search|suggest|lookup|autocomplete|choices/.test( n ) && methods === 'GET' ) return 'picker';
	if ( /bulk|batch/.test( n ) ) return 'bulk action';
	if ( last === '{id}' || last === '{var}' ) return 'item';
	if ( /\{(id|var)\}\//.test( n ) ) return 'item action';
	if ( methods === 'GET' ) return 'list';
	return 'other write';
}
function routesOf( src ) {
	const out = [];
	const re = /register_rest_route\s*\(\s*(['"])[^'"]*\1\s*,\s*([\s\S]+?)\s*,\s*(?=array\s*\(|\[|\$)/g;
	let m;
	while ( ( m = re.exec( src ) ) ) {
		const p = routePath( m[ 2 ].trim() );
		const body = src.slice( re.lastIndex, re.lastIndex + 800 );
		const mm = body.match( /'methods'\s*=>\s*([^\n]+)/ );
		const ms = mm ? mm[ 1 ] : '';
		const methods = [
			/GET|READABLE/.test( ms ) && 'GET',
			/POST|CREATABLE|EDITABLE/.test( ms ) && 'POST',
			/PUT/.test( ms ) && 'PUT',
			/DELETE|DELETABLE/.test( ms ) && 'DELETE',
		].filter( Boolean ).join( ',' ) || '?';
		const kind = routeKind( p, methods );
		out.push( { path: p, methods, kind, tier: GENERIC_KINDS.has( kind ) ? 'declarable' : 'handler' } );
	}
	return out;
}

// Lines that hold code: not blank, not inside or wholly a comment. A line
// with code and a trailing comment counts.
function codeLines( src ) {
	let n = 0;
	let block = false;
	for ( const raw of src.split( '\n' ) ) {
		let line = raw.trim();
		if ( block ) {
			const end = line.indexOf( '*/' );
			if ( end < 0 ) continue;
			block = false;
			line = line.slice( end + 2 ).trim();
		}
		while ( line.startsWith( '/*' ) ) {
			const end = line.indexOf( '*/', 2 );
			if ( end < 0 ) {
				block = true;
				line = '';
				break;
			}
			line = line.slice( end + 2 ).trim();
		}
		if ( '' === line || line.startsWith( '//' ) || line.startsWith( '#' ) || '<?php' === line ) continue;
		n++;
	}
	return n;
}

function measure( file, src, families ) {
	const name = path.basename( file );
	const lines = src.split( '\n' ).length;
	const routes = count( src, /register_rest_route\s*\(/g );
	const resources = count( src, /minn_admin_resource\s*\(/g );
	// Kernel-owned routes whose logic the adapter keeps (the handler tier).
	const endpoints = count( src, /minn_admin_endpoint\s*\(/g );
	// Settings screens and editor panels the tiers save.
	const tiers = count( src, /(?<!function\s)minn_admin_(settings|fields)\s*\(/g );
	const routeList = routesOf( src );
	const shapes = [];
	if ( /'collection'\s*=>/.test( src ) ) shapes.push( 'collection' );
	if ( /'settings'\s*=>\s*array/.test( src ) ) shapes.push( 'settings' );
	if ( /minn_admin_editor_panels/.test( src ) ) shapes.push( 'panel' );
	if ( /'status'\s*=>\s*array\(\s*'route'/.test( src ) ) shapes.push( 'status' );
	// Nothing declared: the adapter works through filters and hooks alone.
	if ( ! shapes.length ) shapes.push( 'hooks' );
	const fam = families[ name ] || { family: 'Uncatalogued', plugin: '' };
	return {
		adapter: name.replace( /\.php$/, '' ),
		family: fam.family,
		plugin: fam.plugin,
		shape: shapes.join( '+' ),
		lines,
		code_lines: codeLines( src ),
		routes,
		// Of the hand-written routes: the shapes a declaration covers, and
		// the ones that keep their logic as a kernel handler.
		routes_declarable: routeList.filter( ( r ) => r.tier === 'declarable' ).length,
		routes_handler: routeList.filter( ( r ) => r.tier === 'handler' ).length,
		permission_callbacks: count( src, /'permission_callback'/g ),
		cap_checks: count( src, /current_user_can\s*\(/g ),
		sql_reads: count( src, /\$wpdb->(get_results|get_var|get_row|get_col)\s*\(/g ),
		sql_writes: count( src, /\$wpdb->(query|update|insert|delete|replace)\s*\(/g ),
		panel_saves: count( src, /'update_callback'\s*=>/g ),
		held_refusals: count( src, /(?<!function\s)minn_admin_panel_held\s*\(/g ),
		mask_rules: count( src, /(Minn_Admin_Egress::(served_as|refuse_masks)|Minn_Admin::unchanged)\s*\(/g ),
		url_cleaning: count( src, /\b(esc_url_raw|minn_admin_url_clean)\s*\(/g ),
		hand_clips: handClips( src ),
		vendor_guards: count( src, /\b(function_exists|class_exists|method_exists|defined)\s*\(/g ) - count( src, /\bdefined\s*\(\s*'ABSPATH'\s*\)/g ) + count( src, /\bcatch\s*\(/g ),
		hand_errors: count( src, /\bnew\s+WP_Error\s*\(/g ),
		detect_helpers: count( src, /^function\s+minn_admin_\w+_(active|can|ready|allowed|available|enabled|installed|has_\w+)\s*\(/gm ),
		resources,
		endpoints,
		tiers,
		status: ( resources || endpoints || tiers ) && ! routes ? 'kernel' : ( resources || endpoints || tiers ) ? 'partial' : 'legacy',
		route_list: routeList,
	};
}

const families = familyMap();
const adapterFiles = listFiles( ADAPTERS ).filter( ( f ) => f.endsWith( '.php' ) ).sort();
const rows = adapterFiles.map( ( f ) => measure( f, read( f ), families ) );

const kernelFiles = KERNEL_PATHS.flatMap( ( p ) => {
	const dir = p.endsWith( '/' ) ? p : path.dirname( p );
	return listFiles( dir ).filter( ( f ) => f.startsWith( p ) && f.endsWith( '.php' ) );
} );
const kernelLines = kernelFiles.reduce( ( n, f ) => n + read( f ).split( '\n' ).length, 0 );
const kernelCode = kernelFiles.reduce( ( n, f ) => n + codeLines( read( f ) ), 0 );

const sum = ( k, list = rows ) => list.reduce( ( n, r ) => n + r[ k ], 0 );
const declared = new Set( adapterFiles.flatMap( ( f ) => declaredStores( read( f ) ) ) );
const centralRows = ( ( db ) => db ? [ [ 'SECRET_OPTION_KEYS', 'option:' ], [ 'SECRET_OPTION_SHAPES', 'option:' ], [ 'SECRET_USERMETA_KEYS', 'usermeta:' ], [ 'SECRET_USERMETA_SHAPES', 'usermeta:' ] ].flatMap( ( [ c, store ] ) => constEntries( db, c ).map( ( e ) => store + e ) ) : [] )( readIfExists( 'includes/class-minn-admin-db.php' ) );
const collection = rows.filter( ( r ) => r.shape.includes( 'collection' ) );
const commit = git( 'rev-parse', '--short', ref || 'HEAD' ).trim();
const pluginSrc = read( 'minn-admin.php' );
const version = ( pluginSrc.match( /\*\s*Version:\s*([0-9.]+)/ ) || [] )[ 1 ] || '';
const at = ref ? git( 'log', '-1', '--format=%cI', ref ).trim() : new Date().toISOString();

const snapshot = {
	at,
	ref: ref || 'working tree',
	commit,
	version,
	label: record || '',
	adapters: rows.length,
	lines: sum( 'lines' ),
	code_lines: sum( 'code_lines' ),
	routes: sum( 'routes' ),
	permission_callbacks: sum( 'permission_callbacks' ),
	cap_checks: sum( 'cap_checks' ),
	sql_reads: sum( 'sql_reads' ),
	sql_writes: sum( 'sql_writes' ),
	routes_declarable: sum( 'routes_declarable' ),
	routes_handler: sum( 'routes_handler' ),
	resources: sum( 'resources' ),
	endpoints: sum( 'endpoints' ),
	kernel_adapters: rows.filter( ( r ) => r.status === 'kernel' ).length,
	partial_adapters: rows.filter( ( r ) => r.status === 'partial' ).length,
	kernel_core_lines: kernelLines,
	kernel_code_lines: kernelCode,
	collection_adapters: collection.length,
	collection_lines: sum( 'lines', collection ),
	collection_code_lines: sum( 'code_lines', collection ),
	collection_routes: sum( 'routes', collection ),
	panel_saves: sum( 'panel_saves' ),
	held_refusals: sum( 'held_refusals' ),
	mask_rules: sum( 'mask_rules' ),
	url_cleaning: sum( 'url_cleaning' ),
	hand_clips: sum( 'hand_clips' ),
	vendor_guards: sum( 'vendor_guards' ),
	hand_errors: sum( 'hand_errors' ),
	detect_helpers: sum( 'detect_helpers' ),
	adapter_kernel_lines: sum( 'code_lines' ) + kernelCode,
	credential_rows: centralRows.filter( ( e ) => ! declared.has( e ) ).length,
	declared_rows: declared.size,
};

if ( outDir ) {
	fs.mkdirSync( outDir, { recursive: true } );
	fs.writeFileSync( path.join( outDir, 'adapters.json' ), JSON.stringify( rows.map( ( { route_list, ...r } ) => r ), null, 1 ) + '\n' );
	// One row per hand-written route, for the breakdown by kind and tier.
	const routeRows = rows.flatMap( ( r ) => r.route_list.map( ( x ) => Object.assign( { adapter: r.adapter, family: r.family }, x ) ) );
	fs.writeFileSync( path.join( outDir, 'routes.json' ), JSON.stringify( routeRows, null, 1 ) + '\n' );
}
if ( record ) {
	const history = fs.existsSync( HISTORY ) ? JSON.parse( fs.readFileSync( HISTORY, 'utf8' ) ) : [];
	history.push( snapshot );
	history.sort( ( a, b ) => a.at.localeCompare( b.at ) );
	fs.mkdirSync( path.dirname( HISTORY ), { recursive: true } );
	fs.writeFileSync( HISTORY, JSON.stringify( history, null, 1 ) + '\n' );
}
console.log( JSON.stringify( snapshot, null, 1 ) );
