#!/usr/bin/env node
/**
 * Adapter inventory: measures every bundled adapter so the resource kernel's
 * rollout can be tracked as numbers (docs/adapter-kernel.md). Dev-only; bin/
 * never ships in the plugin zip.
 *
 *   node bin/adapter-inventory.js                    # the working tree
 *   node bin/adapter-inventory.js --ref v0.45.0      # any git ref
 *   node bin/adapter-inventory.js --out <dir>        # write adapters.json there
 *   node bin/adapter-inventory.js --record "label"   # also append a snapshot
 *                                                    # to bin/adapter-inventory/history.json
 *
 * Per adapter: lines, hand-written REST routes, permission callbacks,
 * capability checks, raw SQL reads and writes, the screen shapes it declares,
 * and kernel resources (minn_admin_resource() calls). An adapter is "kernel"
 * when it declares resources and registers no routes of its own, "partial"
 * when it does both, and "legacy" otherwise.
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

function measure( file, src, families ) {
	const name = path.basename( file );
	const lines = src.split( '\n' ).length;
	const routes = count( src, /register_rest_route\s*\(/g );
	const resources = count( src, /minn_admin_resource\s*\(/g );
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
		routes,
		permission_callbacks: count( src, /'permission_callback'/g ),
		cap_checks: count( src, /current_user_can\s*\(/g ),
		sql_reads: count( src, /\$wpdb->(get_results|get_var|get_row|get_col)\s*\(/g ),
		sql_writes: count( src, /\$wpdb->(query|update|insert|delete|replace)\s*\(/g ),
		resources,
		status: resources && ! routes ? 'kernel' : resources ? 'partial' : 'legacy',
	};
}

const families = familyMap();
const adapterFiles = listFiles( ADAPTERS ).filter( ( f ) => f.endsWith( '.php' ) ).sort();
const rows = adapterFiles.map( ( f ) => measure( f, read( f ), families ) );

const kernelLines = KERNEL_PATHS.flatMap( ( p ) => {
	const dir = p.endsWith( '/' ) ? p : path.dirname( p );
	return listFiles( dir ).filter( ( f ) => f.startsWith( p ) && f.endsWith( '.php' ) );
} ).reduce( ( n, f ) => n + read( f ).split( '\n' ).length, 0 );

const sum = ( k, list = rows ) => list.reduce( ( n, r ) => n + r[ k ], 0 );
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
	routes: sum( 'routes' ),
	permission_callbacks: sum( 'permission_callbacks' ),
	cap_checks: sum( 'cap_checks' ),
	sql_reads: sum( 'sql_reads' ),
	sql_writes: sum( 'sql_writes' ),
	resources: sum( 'resources' ),
	kernel_adapters: rows.filter( ( r ) => r.status === 'kernel' ).length,
	partial_adapters: rows.filter( ( r ) => r.status === 'partial' ).length,
	kernel_core_lines: kernelLines,
	collection_adapters: collection.length,
	collection_lines: sum( 'lines', collection ),
	collection_routes: sum( 'routes', collection ),
};

if ( outDir ) {
	fs.mkdirSync( outDir, { recursive: true } );
	fs.writeFileSync( path.join( outDir, 'adapters.json' ), JSON.stringify( rows, null, 1 ) + '\n' );
}
if ( record ) {
	const history = fs.existsSync( HISTORY ) ? JSON.parse( fs.readFileSync( HISTORY, 'utf8' ) ) : [];
	history.push( snapshot );
	history.sort( ( a, b ) => a.at.localeCompare( b.at ) );
	fs.mkdirSync( path.dirname( HISTORY ), { recursive: true } );
	fs.writeFileSync( HISTORY, JSON.stringify( history, null, 1 ) + '\n' );
}
console.log( JSON.stringify( snapshot, null, 1 ) );
