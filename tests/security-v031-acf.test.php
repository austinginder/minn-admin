<?php
/**
 * v0.31 ACF adapter write and read scope.
 *
 *  1. The link field's scheme guard was an anchored regex over the raw string.
 *     Browsers strip control characters and whitespace before resolving a
 *     scheme, so "java\tscript:" and a leading \x01 both read as safe and both
 *     run. The url field type had no guard at all.
 *
 *  2. Options-page values are site-global and the usual pattern is a theme
 *     printing them with the_field(), which does not escape. wysiwyg was held
 *     to unfiltered_html on that path and the plain string types were not.
 *
 *  3. An options page declaring an empty capability was read as "unset" and
 *     fell back to edit_posts, granting access ACF's own menu registration
 *     refuses to everyone.
 *
 *  4. The relation route's user branch let WP_User_Query pick its own search
 *     columns, and a term containing @ makes it search user_email.
 *
 * Run: wp eval-file tests/security-v031-acf.test.php --user=admin --path=<site>
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail ? " — {$detail}" : '' );
};

if ( ! function_exists( 'minn_admin_acf_link_in' ) ) {
	echo "SKIP  ACF adapter not loaded\n";
	return;
}

// --- 1. scheme guard, through the link field --------------------------------
$blocked = array(
	'javascript:alert(1)'                 => 'plain',
	"java\tscript:alert(1)"               => 'interior tab',
	"\x01javascript:alert(1)"             => 'leading control character',
	' JaVaScRiPt:alert(1)'                => 'leading space and mixed case',
	'data:text/html,<script>x</script>'   => 'data URL',
);
foreach ( $blocked as $url => $why ) {
	$check(
		"Link field refuses a dangerous URL ({$why})",
		null === minn_admin_acf_link_in( array( 'url' => $url, 'title' => 'x' ) )
	);
}
$ok = minn_admin_acf_link_in( array( 'url' => 'https://example.com/page', 'title' => 'x' ) );
$check(
	'CONTROL an ordinary link is still stored',
	is_array( $ok ) && 'https://example.com/page' === $ok['url'],
	is_array( $ok ) ? $ok['url'] : var_export( $ok, true )
);

// --- 2. options scope holds string values to the markup rule ---------------
if ( ! function_exists( 'minn_admin_acf_value_in' ) ) {
	echo "SKIP  value_in missing\n";
} else {
	$field   = array( 'type' => 'text', 'key' => 'field_probe', 'name' => 'probe' );
	$payload = '<b>keep</b><script>steal()</script>';

	// As an administrator, who holds unfiltered_html on a single site, nothing
	// is filtered: this is the site owner editing their own site options.
	$check(
		'CONTROL an administrator stores options markup unchanged',
		! current_user_can( 'unfiltered_html' )
			|| $payload === minn_admin_acf_value_in( $field, $payload, 'options' )
	);

	// Without unfiltered_html the same write is filtered on the options scope.
	$filter = function ( $caps ) {
		$caps['unfiltered_html'] = false;
		return $caps;
	};
	add_filter( 'user_has_cap', $filter, 99 );
	$stored = minn_admin_acf_value_in( $field, $payload, 'options' );
	$check(
		'A caller without unfiltered_html cannot store a script in a site option',
		is_string( $stored ) && false === strpos( $stored, '<script' ) && false !== strpos( $stored, '<b>' ),
		is_string( $stored ) ? $stored : var_export( $stored, true )
	);
	// The post scope is ACF's own behaviour over a post the caller owns and is
	// deliberately unchanged.
	$check(
		'CONTROL the post scope is left as ACF behaves',
		$payload === minn_admin_acf_value_in( $field, $payload, 'post' )
	);
	remove_filter( 'user_has_cap', $filter, 99 );

	$check(
		'A url field refuses a javascript URL',
		null === minn_admin_acf_value_in( array( 'type' => 'url', 'key' => 'field_u', 'name' => 'u' ), 'javascript:alert(1)' )
	);
}

// --- 3. an options page that declared no capability grants nobody ----------
if ( ! function_exists( 'minn_admin_acf_options_pages_allowed' ) || ! function_exists( 'acf_add_options_page' ) ) {
	echo "SKIP  ACF Pro options pages unavailable\n";
} else {
	$probe = function ( $pages ) {
		$pages[] = array(
			'menu_slug'  => 'minn-probe-locked',
			'page_title' => 'Minn Probe Locked',
			'menu_title' => 'Minn Probe Locked',
			'capability' => '', // set on purpose: ACF hands this to add_menu_page, which denies everyone
		);
		return $pages;
	};
	add_filter( 'acf/get_options_pages', $probe, 99 );
	$allowed = minn_admin_acf_options_pages_allowed();
	remove_filter( 'acf/get_options_pages', $probe, 99 );
	$check(
		'An options page declaring an empty capability is offered to nobody',
		! isset( $allowed['minn-probe-locked'] ),
		'allowed: ' . implode( ', ', array_keys( (array) $allowed ) )
	);
}

// --- 4. pinned search columns do not answer questions about email ---------
$uid = wp_insert_user(
	array(
		'user_login'   => 'minn_acf_probe_' . wp_generate_password( 5, false ),
		'user_email'   => 'minnacfprobe@needle-example.com',
		'display_name' => 'Nothing Matching',
		'user_pass'    => wp_generate_password( 20 ),
	)
);
if ( ! is_wp_error( $uid ) ) {
	$loose = new WP_User_Query( array( 'search' => '*needle-example*', 'number' => 5 ) );
	$check(
		'An unpinned search does reach user_email (why pinning matters)',
		1 === count( $loose->get_results() )
	);
	$pinned = new WP_User_Query(
		array(
			'search'         => '*needle-example*',
			'search_columns' => array( 'display_name', 'user_nicename', 'user_login' ),
			'number'         => 5,
		)
	);
	$check(
		'Pinned to the columns the picker shows, it does not',
		0 === count( $pinned->get_results() )
	);
	wp_delete_user( $uid );
}

// --- 5. an import cannot name the row it lands on -------------------------
if ( ! function_exists( 'minn_admin_acf_schema_import' ) || ! function_exists( 'acf_import_field_group' ) ) {
	echo "SKIP  ACF field-group importer unavailable\n";
} else {
	wp_set_current_user( $admin );
	$victim = wp_insert_post(
		array(
			'post_type'    => 'post',
			'post_status'  => 'publish',
			'post_title'   => 'minn import victim',
			'post_content' => 'untouched',
		),
		true
	);
	if ( ! is_wp_error( $victim ) ) {
		$payload = wp_json_encode(
			array(
				array(
					'ID'       => (int) $victim, // the part an upload must not get to choose
					'key'      => 'group_minn_import_probe',
					'title'    => 'Minn Import Probe',
					'fields'   => array(),
					'location' => array(),
				),
			)
		);
		minn_admin_acf_schema_import( $payload );
		$after = get_post( $victim );
		$check(
			'An imported field group cannot overwrite a post it names',
			$after && 'post' === $after->post_type && 'minn import victim' === $after->post_title,
			$after ? $after->post_type . ' / ' . $after->post_title : 'post is gone'
		);
		// Clean up whatever the import did create.
		$made = get_posts(
			array(
				'post_type'   => 'acf-field-group',
				'name'        => 'group_minn_import_probe',
				'numberposts' => 5,
				'post_status' => 'any',
			)
		);
		foreach ( $made as $m ) {
			wp_delete_post( $m->ID, true );
		}
		wp_delete_post( $victim, true );
	}
}

printf( "\nsecurity-v031-acf: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
