/**
 * A post's title or address leaves only for a post the viewer may read (no
 * browser, ~1s).
 *
 * get_the_title() and get_permalink() answer for any id, read access or not.
 * When the id comes from stored data someone below the viewer can write (a
 * field value, a meta row, a vendor's table) or from the request, printing
 * its title tells a Contributor another author's draft, a private post or a
 * privacy request's email address (0.45.0 registry #8: the revision
 * comparison named whatever post an ACF image field held).
 *
 * So every call in the PHP is counted per file and listed with where its id
 * comes from. A new call fails here until it is listed: check
 * current_user_can( 'read_post', $id ) first (and the post type, where the
 * field names one), or say why the id is safe. A changed count fails too, so
 * a copy into a new place is seen.
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

// file => [ calls, where each id comes from ].
const ALLOWED = {
	'includes/class-minn-admin-rest.php': [ 7, "an attachment's parent after read_post; comments on posts comment_row_visible() admits; a post object the caller's own query loaded; the caller's own new copy" ],
	'includes/adapters/acf.php': [ 5, "a link field's post after read_post; an old-shape link's post, as ACF's own screen resolves it; an image or file field's attachment after read_post" ],
	'includes/adapters/bricks.php': [ 6, "template conditions and builder links for posts Bricks lets the caller build; a form's page, shown to whoever reads Bricks' submissions (its own screen names it)" ],
	'includes/adapters/broken-link-checker.php': [ 1, "the post a broken link sits in, for Broken Link Checker's own viewers" ],
	'includes/adapters/connect-matomo.php': [ 1, "a page Matomo counted visits to, for its report's viewers" ],
	'includes/adapters/eps-301-redirects.php': [ 1, "a redirect's target, for whoever manages the redirects" ],
	'includes/adapters/fluent-forms-emails.php': [ 3, "the pages offered as a confirmation target, from a query of published pages, for the form's managers" ],
	'includes/adapters/gravity-forms-confirmations.php': [ 7, "a confirmation's page and the published pages offered, for the form's managers" ],
	'includes/adapters/independent-analytics.php': [ 2, 'a page the plugin counted visits to, for its report\'s viewers' ],
	'includes/adapters/jet-reviews.php': [ 2, "the reviewed item, for whoever moderates JetReviews' reviews" ],
	'includes/adapters/matomo.php': [ 1, "a page Matomo counted visits to, for its report's viewers" ],
	'includes/adapters/monsterinsights.php': [ 1, "a page MonsterInsights counted visits to, for its report's viewers" ],
	'includes/adapters/otter.php': [ 1, 'the post being edited' ],
	'includes/adapters/page-builders.php': [ 4, "the post being opened in its builder, after the builder's own edit check" ],
	'includes/adapters/plausible-analytics.php': [ 1, "a page Plausible counted visits to, for its report's viewers" ],
	'includes/adapters/seo.php': [ 5, 'the post whose SEO panel or list row is open, read or edited by the caller' ],
	'includes/adapters/sureforms.php': [ 1, "a form's own title, for whoever reads its entries" ],
	'includes/adapters/the-events-calendar.php': [ 1, 'an event from a query the caller may read' ],
	'includes/adapters/traffic-kit.php': [ 2, "a page a traffic plugin counted visits to, for its report's viewers" ],
	'includes/adapters/woocommerce-memberships.php': [ 2, 'a restricted post after read_post; posts from a query of what the plan restricts' ],
	'includes/adapters/woocommerce-settings.php': [ 2, "the pages offered for a store page, for whoever manages WooCommerce's settings" ],
	'includes/adapters/wp-statistics.php': [ 2, "a page WP Statistics counted visits to, for its report's viewers" ],
	'includes/adapters/wpforms-emails.php': [ 3, "the form's title and the pages offered as a confirmation target, for the form's managers" ],
	'includes/adapters/wpforms.php': [ 1, "a form's own title, for whoever reads its entries" ],
};

// PHP source with comments blanked (strings kept), so a call named in a
// comment is not counted.
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

const LOOKUP = /\b(get_the_title|get_permalink)\s*\(/g;
const found = {};
for ( const f of [ path.join( ROOT, 'minn-admin.php' ), ...phpFiles( path.join( ROOT, 'includes' ) ) ] ) {
	const rel = path.relative( ROOT, f ).split( path.sep ).join( '/' );
	code( fs.readFileSync( f, 'utf8' ) ).split( '\n' ).forEach( ( line, n ) => {
		for ( const m of line.matchAll( LOOKUP ) ) {
			( found[ rel ] ||= [] ).push( `${ n + 1 }:${ m[ 1 ] }` );
		}
	} );
}
for ( const [ rel, at ] of Object.entries( found ) ) {
	const want = ALLOWED[ rel ];
	if ( ! want ) {
		check( `${ rel } title lookups are listed with where their ids come from`, false, `${ at.join( ', ' ) }: check read_post on an id that came from stored data or the request, then list it here` );
		continue;
	}
	check( `${ rel } ×${ want[ 0 ] } (${ want[ 1 ] })`, at.length === want[ 0 ], at.length === want[ 0 ] ? '' : `found ${ at.length } (${ at.join( ', ' ) }): a new one checks read_post on an id from stored data or the request` );
}
for ( const rel of Object.keys( ALLOWED ) ) {
	if ( ! found[ rel ] ) {
		check( `${ rel } still looks titles up (else drop it from the list)`, false );
	}
}

// The ACF revision summary's image and file branch keeps its read check.
const acf = code( fs.readFileSync( path.join( ROOT, 'includes/adapters/acf.php' ), 'utf8' ) );
const branch = acf.slice( acf.indexOf( 'function minn_admin_acf_display_value' ), acf.indexOf( "case 'link':", acf.indexOf( 'function minn_admin_acf_display_value' ) ) );
check( "ACF's revision summary names an image or file field's post only when it is an attachment the viewer may read",
	/'attachment' === get_post_type\( \$id \) && current_user_can\( 'read_post', \$id \)/.test( branch ) );

console.log( `\ntitle-lookups: ${ pass }/${ pass + fail } passed` );
process.exit( fail ? 1 : 0 );
