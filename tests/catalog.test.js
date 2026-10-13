/**
 * The plugin catalog (bin/demo/catalog.json) stays in step with the adapters.
 * minnadmin.com builds its plugin library and the live demo's links from that
 * file, so an adapter missing from it never reaches the site, and a demo link
 * naming a surface that no longer exists opens an empty page.
 *
 * Every adapter file is claimed by a plugin entry or listed under `shared`
 * (helpers and Minn's own aggregators). Every file a plugin entry names
 * exists. A plugin's `surface` is one its own adapter files register, and an
 * adapter that registers surfaces gets at least one of them linked.
 *
 * Run: node tests/catalog.test.js
 */
'use strict';

const fs = require( 'fs' );
const path = require( 'path' );

const ROOT = path.resolve( __dirname, '..' );
const ADAPTERS = path.join( ROOT, 'includes', 'adapters' );
const checks = [];
const check = ( label, ok, detail = '' ) => {
	checks.push( !! ok );
	console.log( `${ ok ? 'PASS' : 'FAIL' }  ${ label }${ ok || ! detail ? '' : ' — ' + detail }` );
};

let catalog = null;
try {
	catalog = JSON.parse( fs.readFileSync( path.join( ROOT, 'bin', 'demo', 'catalog.json' ), 'utf8' ) );
} catch ( e ) {
	check( 'catalog.json parses', false, e.message );
	process.exit( 1 );
}
check( 'catalog.json parses', true );

const files = fs.readdirSync( ADAPTERS ).filter( ( f ) => f.endsWith( '.php' ) );
const source = Object.fromEntries( files.map( ( f ) => [ f, fs.readFileSync( path.join( ADAPTERS, f ), 'utf8' ) ] ) );
const shared = new Set( catalog.shared || [] );
const plugins = ( catalog.categories || [] ).flatMap( ( c ) => ( c.plugins || [] ).map( ( p ) => ( { ...p, category: c.id } ) ) );

/* ===== Shape ===== */
const ids = ( catalog.categories || [] ).map( ( c ) => c.id );
check( 'categories have unique ids', ids.length > 0 && new Set( ids ).size === ids.length );
const badCats = ( catalog.categories || [] ).filter( ( c ) => ! /^[a-z0-9-]+$/.test( c.id || '' ) || ! c.title || ! c.role || ! ( c.plugins || [] ).length );
check( 'every category has an id, title, role and plugins', ! badCats.length, badCats.map( ( c ) => c.id || c.title ).join( ', ' ) );
const badPlugins = plugins.filter( ( p ) => ! p.name || ! /^https:\/\//.test( p.url || '' ) || ! Array.isArray( p.adapters ) || ( ! p.adapters.length && ! p.core ) );
check( 'every plugin has a name, an https url and its adapter files (or core: true)', ! badPlugins.length, badPlugins.map( ( p ) => p.category + '/' + p.name ).join( ', ' ) );
const dupes = ( catalog.categories || [] ).flatMap( ( c ) => {
	const names = c.plugins.map( ( p ) => p.name );
	return names.filter( ( n, i ) => names.indexOf( n ) !== i ).map( ( n ) => c.id + '/' + n );
} );
check( 'no plugin is listed twice in one category', ! dupes.length, dupes.join( ', ' ) );

/* ===== Files ===== */
const named = new Set( [ ...plugins.flatMap( ( p ) => p.adapters ), ...shared ] );
const missing = [ ...named ].filter( ( f ) => ! source[ f ] );
check( 'every file the catalog names exists in includes/adapters', ! missing.length, missing.join( ', ' ) );
const unlisted = files.filter( ( f ) => ! named.has( f ) );
check( 'every adapter file is in the catalog', ! unlisted.length, unlisted.join( ', ' ) + ' (add a plugin entry, or list it under shared)' );
const both = [ ...shared ].filter( ( f ) => plugins.some( ( p ) => p.adapters.includes( f ) ) );
check( 'a shared file is not also a plugin adapter', ! both.length, both.join( ', ' ) );

/* ===== Demo links ===== */
// The keys an adapter registers on minn_admin_surfaces: $surfaces['id'] = ...
const registered = ( f ) => [ ...source[ f ].matchAll( /\$surfaces\[\s*'([a-z0-9-]+)'\s*\]\s*=/g ) ].map( ( m ) => m[ 1 ] );
const wrongSurface = plugins.filter( ( p ) => p.surface && ! p.adapters.some( ( f ) => source[ f ] && registered( f ).includes( p.surface ) ) );
check( "a plugin's surface is registered by its own adapter files", ! wrongSurface.length, wrongSurface.map( ( p ) => p.name + ' → ' + p.surface ).join( ', ' ) );
const linked = new Set( plugins.map( ( p ) => p.surface ).filter( Boolean ) );
const unlinked = files.filter( ( f ) => ! shared.has( f ) && registered( f ).length && ! registered( f ).some( ( id ) => linked.has( id ) ) );
check( 'every adapter that registers a surface has one linked from a plugin', ! unlinked.length, unlinked.join( ', ' ) );

const failed = checks.filter( ( ok ) => ! ok ).length;
console.log( `\ncatalog: ${ checks.length - failed }/${ checks.length } passed` );
process.exit( failed ? 1 : 0 );
