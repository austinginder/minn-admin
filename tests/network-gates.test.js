/**
 * Network gates go through Minn_Admin::network_owner() (no browser, ~1s).
 *
 * On a network, manage_network_options and the other network capabilities
 * map to themselves, so a role plugin can hand one to an account that is not
 * a super admin. That is right where core or the vendor asks the same
 * capability for the same action (core's network settings screen, Autoptimize's
 * network config), and wrong for what Minn owns: the System page, logs,
 * database browser and licences answered such an account until v0.44.0.
 *
 * So every raw network capability and every bare is_super_admin() in the PHP
 * is listed below with the check it mirrors, counted per file. A new one
 * fails here until it is either Minn_Admin::network_owner() or listed with
 * its reason. A changed count fails too, so a copy of a listed gate into a
 * new route is seen.
 */
const fs = require( 'fs' );
const path = require( 'path' );

const ROOT = path.join( __dirname, '..' );
let pass = 0;
let fail = 0;
const check = ( label, ok, detail = '' ) => {
	ok ? pass++ : fail++;
	console.log( `${ ok ? 'PASS' : 'FAIL' }  ${ label }${ detail ? ' — ' + detail : '' }` );
};

// file => { token => [ count, what it mirrors ] }. Tokens are the quoted
// capability literal, or is_super_admin() with no argument.
const ALLOWED = {
	'includes/class-minn-admin.php': {
		"'manage_network_plugins'": [ 3, 'core: network activation in the plugins REST controller and Network Admin > Plugins' ],
		"'manage_network_themes'": [ 1, 'core: Network Admin > Themes (boot flag for the menu)' ],
		"'manage_network'": [ 1, "core: the admin bar's Network Admin link" ],
		'is_super_admin()': [ 1, 'Minn_Admin::network_owner() itself' ],
	},
	'includes/class-minn-admin-rest.php': {
		"'upgrade_network'": [ 2, 'core: network/upgrade.php' ],
		"'manage_network_users'": [ 3, "core: user-new.php's username lookup and the network user screens" ],
	},
	'includes/class-minn-admin-surfaces.php': {
		"'delete_site'": [ 1, 'descriptor validator: meta caps core maps for a bare check (not a gate)' ],
		"'setup_network'": [ 1, 'descriptor validator: meta caps core maps for a bare check (not a gate)' ],
		"'upgrade_network'": [ 1, 'descriptor validator: meta caps core maps for a bare check (not a gate)' ],
	},
	'includes/adapters/network.php': {
		"'manage_sites'": [ 4, 'core: Network Admin > Sites' ],
		"'manage_network_options'": [ 4, 'core: Network Admin > Settings and user-edit.php super admin toggle' ],
		"'manage_network_users'": [ 3, 'core: Network Admin > Users' ],
		"'manage_network_plugins'": [ 1, 'core: Network Admin > Plugins' ],
		"'manage_network_themes'": [ 1, 'core: Network Admin > Themes' ],
		"'create_sites'": [ 1, 'core: network/site-new.php' ],
		"'delete_sites'": [ 1, 'core: network/sites.php bulk delete' ],
		"'delete_site'": [ 1, 'core: wpmu_delete_blog() via network/sites.php' ],
		"'manage_network'": [ 2, 'core: links into Network Admin' ],
	},
	'includes/adapters/acf.php': {
		"'manage_network_users'": [ 1, "core: the users endpoint's network-wide lookup" ],
	},
	'includes/adapters/woocommerce-memberships.php': {
		"'manage_network_users'": [ 1, "core: Add Existing User's membership boundary" ],
	},
	'includes/adapters/autoptimize.php': {
		"'manage_network_options'": [ 1, 'Autoptimize: network-level config check' ],
	},
	'includes/adapters/wp-migrate.php': {
		"'manage_network_options'": [ 1, "WP Migrate: wpmdb_ajax_cap's default on a network" ],
	},
	'includes/adapters/wordfence.php': {
		"'manage_network'": [ 1, 'Wordfence: wfUtils::isAdmin() fallback' ],
	},
	'includes/adapters/wp-multi-network.php': {
		"'manage_networks'": [ 1, "WP Multi Network: its own network management capability" ],
		"'delete_sites'": [ 3, 'a request field name, not a capability' ],
	},
	'includes/adapters/asset-cleanup.php': {
		'is_super_admin()': [ 1, 'Asset CleanUp: Menu::userCanAccessPlugin() fallback' ],
	},
	'includes/adapters/seo.php': {
		'is_super_admin()': [ 1, 'SEOPress: seopress_metabox_role_is_blocked() fallback' ],
	},
	'includes/adapters/wpvivid.php': {
		'is_super_admin()': [ 1, "WPvivid: its menu's administrator-or-super-admin test" ],
	},
};

// PHP source with comments blanked (strings kept), so a capability named in
// a comment is not counted.
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
			// A ?> closes a line comment too.
			const close = src.slice( i, j ).indexOf( '?>' );
			const end = -1 === close ? j : i + close;
			out += ' '.repeat( end - i );
			i = end;
		} else {
			out += c;
			i++;
		}
	}
	return out;
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

const NETWORK_CAP = /(['"])(manage_network(?:_[a-z]+)?|manage_sites|manage_networks|create_sites|delete_sites?|upgrade_network|setup_network)\1/g;
const BARE_SUPER = /\bis_super_admin\(\s*\)/g;

const files = [ path.join( ROOT, 'minn-admin.php' ), ...phpFiles( path.join( ROOT, 'includes' ) ) ];
const found = {};
for ( const f of files ) {
	const rel = path.relative( ROOT, f ).split( path.sep ).join( '/' );
	const src = code( fs.readFileSync( f, 'utf8' ) );
	const lines = src.split( '\n' );
	lines.forEach( ( line, n ) => {
		for ( const m of line.matchAll( NETWORK_CAP ) ) {
			const tok = `'${ m[ 2 ] }'`;
			( ( found[ rel ] ||= {} )[ tok ] ||= [] ).push( n + 1 );
		}
		for ( const m of line.matchAll( BARE_SUPER ) ) {
			( ( found[ rel ] ||= {} )[ 'is_super_admin()' ] ||= [] ).push( n + 1 );
		}
		if ( /\bmanage_cap\s*\(/.test( line ) && ! /has_manage_cap/.test( line ) ) {
			check( `${ rel }:${ n + 1 } uses no widened settings capability`, false, 'Minn_Admin::manage_cap() is gone: a network boundary is Minn_Admin::network_owner()' );
		}
	} );
}

check( 'Minn_Admin::network_owner() is a super admin on a network, anyone on a single site',
	/function network_owner\(\)\s*\{\s*return ! is_multisite\(\) \|\| is_super_admin\(\);\s*\}/.test( code( fs.readFileSync( path.join( ROOT, 'includes/class-minn-admin.php' ), 'utf8' ) ) ) );

for ( const [ rel, toks ] of Object.entries( found ) ) {
	for ( const [ tok, lines ] of Object.entries( toks ) ) {
		const want = ALLOWED[ rel ] && ALLOWED[ rel ][ tok ];
		if ( ! want ) {
			check( `${ rel } ${ tok } is listed with the check it mirrors`, false, `line ${ lines.join( ', ' ) }: use Minn_Admin::network_owner() for anything Minn owns, or list it here with the core or vendor check it mirrors` );
			continue;
		}
		check( `${ rel } ${ tok } ×${ want[ 0 ] } (${ want[ 1 ] })`, lines.length === want[ 0 ], lines.length === want[ 0 ] ? '' : `found ${ lines.length } (lines ${ lines.join( ', ' ) }): a new use is Minn_Admin::network_owner() unless it mirrors the same check` );
	}
}
for ( const [ rel, toks ] of Object.entries( ALLOWED ) ) {
	for ( const tok of Object.keys( toks ) ) {
		if ( ! ( found[ rel ] && found[ rel ][ tok ] ) ) {
			check( `${ rel } ${ tok } is still there (else drop it from the list)`, false );
		}
	}
}

console.log( `\nnetwork-gates: ${ pass }/${ pass + fail } passed` );
process.exit( fail ? 1 : 0 );
