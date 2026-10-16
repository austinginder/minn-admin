<?php
/**
 * Regressions for the v0.45.0 shipped-zip registry scan: saves that rewrote
 * a field the person did not change (findings #6, #13, #14, #15, #16, and
 * the siblings their sweep found in the Gravity Forms and WPForms entry
 * editors and the JetThemeCore and Elementor template renames).
 *
 * Each section seeds a stored value the old save path changed (a description
 * with a link and line breaks, labels CPT UI keeps entity-encoded, a JetSearch
 * name '50%DEAL', a multi-choice answer holding a choice the form no longer
 * offers, an unnamed WP Freighter tenant or template), replays what Minn's client sends
 * for an edit that leaves that field alone, and checks the stored bytes.
 * Sections SKIP when their plugin is inactive; the Freighter section runs on
 * a WP Freighter host.
 *
 * Run: wp eval-file tests/security-v046-registry-writes.test.php --user=admin --path=<site>
 *      wp eval-file tests/security-v046-registry-writes.test.php --user=<freighter admin> --path=<WP Freighter site>
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	set_time_limit( 0 );
	$results[] = (bool) $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( $ok || '' === $detail ) ? '' : ' — ' . $detail );
};
$skip    = function ( $label ) {
	printf( "SKIP  %s\n", $label );
};
$call    = function ( $method, $route, $body = null ) {
	$r = new WP_REST_Request( $method, $route );
	if ( null !== $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), json_decode( wp_json_encode( $res->get_data() ), true ) );
};
// Cleanup that runs once, from the section's finally or at shutdown when a
// fatal error ends the run first.
$once = function ( callable $fn ) {
	$done = false;
	$run  = function () use ( &$done, $fn ) {
		if ( $done ) {
			return;
		}
		$done = true;
		$fn();
	};
	register_shutdown_function( $run );
	return $run;
};

if ( ! current_user_can( 'manage_options' ) ) {
	echo "SKIP  run with --user=<an administrator>\n";
	return;
}
global $wpdb;
rest_get_server();
// Novamira Pro registers its memory post type only with a licence. Where it
// is active without one (the lab), the memory section stands the type in for
// this process and runs the Novamira adapter's route registration once more,
// so Minn's own memory routes (registered only when the type exists) are the
// ones under test.
if ( function_exists( 'minn_admin_novamira_active' ) && minn_admin_novamira_active() && ! post_type_exists( 'novamira_memory' ) ) {
	register_post_type( 'novamira_memory', array( 'public' => false, 'supports' => array( 'title', 'editor', 'excerpt' ) ) );
	global $wp_filter;
	foreach ( (array) ( isset( $wp_filter['rest_api_init'] ) ? $wp_filter['rest_api_init']->callbacks : array() ) as $hooked ) {
		foreach ( $hooked as $cb ) {
			if ( $cb['function'] instanceof Closure && '/adapters/novamira.php' === substr( str_replace( '\\', '/', ( new ReflectionFunction( $cb['function'] ) )->getFileName() ), -22 ) ) {
				call_user_func( $cb['function'] );
			}
		}
	}
}

/*
 * #6: an edit of a post type or taxonomy that leaves the labels and the
 * description alone keeps them as the vendor stored them. The modal sends
 * only what changed, but the route filled a field it left out from the
 * registered type, entity-decoded, through sanitize_text_field: the link and
 * line break went out of the description and CPT UI's encoded labels came
 * back decoded. A description the modal's single-line input sends back
 * without its line breaks counts as untouched too.
 */
( function () use ( $check, $skip, $call, $wpdb ) {
	$row = function ( $kind, $slug ) use ( $call ) {
		list( , $d ) = $call( 'GET', '/minn-admin/v1/' . ( 'type' === $kind ? 'post-types' : 'taxonomies' ) );
		foreach ( (array) ( $d[ 'type' === $kind ? 'types' : 'taxonomies' ] ?? array() ) as $r ) {
			if ( $slug === $r['slug'] ) {
				return $r;
			}
		}
		return null;
	};
	$desc = "Read <a href=\"/shop\">our shop</a>.\nSecond line.";

	$wpdb->query( 'START TRANSACTION' );
	try {
		// Minn's own store.
		$slug = 'minnregw' . wp_rand( 100, 999 );
		$call( 'POST', '/minn-admin/v1/post-types', array( 'slug' => $slug, 'singular' => 'Dish', 'plural' => 'Dishes', 'description' => 'x', 'public' => 1, 'backend' => 'minn' ) );
		$types                     = (array) get_option( Minn_Admin_CPT::OPTION, array() );
		$types[ $slug ]['plural']  = 'Fish &amp; Chips';
		$types[ $slug ]['description'] = $desc;
		update_option( Minn_Admin_CPT::OPTION, $types );
		if ( post_type_exists( $slug ) ) {
			unregister_post_type( $slug );
		}
		Minn_Admin_CPT::register_native_types();
		$before = $row( 'type', $slug );
		list( $st ) = $call( 'POST', "/minn-admin/v1/post-types/{$slug}", array( 'show_in_rest' => 1, 'base' => $before['stamp'] ?? '' ) );
		$kept = (array) ( ( (array) get_option( Minn_Admin_CPT::OPTION, array() ) )[ $slug ] ?? array() );
		$check( '#6 a Minn post type save of one switch answers 200', 200 === $st, (string) $st );
		$check( '#6 and keeps the stored description with its link and line break', $desc === ( $kept['description'] ?? null ), wp_json_encode( $kept['description'] ?? null ) );
		$check( '#6 and keeps the stored plural label byte for byte', 'Fish &amp; Chips' === ( $kept['plural'] ?? null ), wp_json_encode( $kept['plural'] ?? null ) );
		$check( '#6 and writes the switch it sent (control)', ! empty( $kept['show_in_rest'] ) );

		// Minn taxonomy: labels only.
		$tax = 'minnregx' . wp_rand( 100, 999 );
		$call( 'POST', '/minn-admin/v1/taxonomies', array( 'slug' => $tax, 'singular' => 'Pot', 'plural' => 'Pots', 'object_types' => array( 'post' ), 'backend' => 'minn' ) );
		$taxes                     = (array) get_option( Minn_Admin_CPT::OPTION_TAX, array() );
		$taxes[ $tax ]['plural']   = 'Pots &amp; Pans';
		update_option( Minn_Admin_CPT::OPTION_TAX, $taxes );
		if ( taxonomy_exists( $tax ) ) {
			unregister_taxonomy( $tax );
		}
		Minn_Admin_CPT::register_native_taxonomies();
		$tb = $row( 'tax', $tax );
		list( $st ) = $call( 'POST', "/minn-admin/v1/taxonomies/{$tax}", array( 'hierarchical' => 1, 'base' => $tb['stamp'] ?? '' ) );
		$tk = (array) ( ( (array) get_option( Minn_Admin_CPT::OPTION_TAX, array() ) )[ $tax ] ?? array() );
		$check( '#6 a taxonomy save of one switch keeps the stored plural label', 200 === $st && 'Pots &amp; Pans' === ( $tk['plural'] ?? null ), $st . ' ' . wp_json_encode( $tk['plural'] ?? null ) );

		// CPT UI's store, as its own screen leaves it.
		if ( defined( 'CPTUI_VERSION' ) && function_exists( 'cptui_register_single_post_type' ) ) {
			$cpt = 'minnregc' . wp_rand( 100, 999 );
			list( $st ) = $call( 'POST', '/minn-admin/v1/post-types', array( 'slug' => $cpt, 'singular' => 'Book', 'plural' => 'Books', 'description' => 'x', 'public' => 1, 'backend' => 'cptui' ) );
			$cptui = (array) get_option( 'cptui_post_types', array() );
			if ( 200 !== $st || ! isset( $cptui[ $cpt ] ) ) {
				$skip( '#6 CPT UI would not take a test post type (' . $st . ')' );
			} else {
				$cptui[ $cpt ]['label']          = 'Books &amp; Bits';
				$cptui[ $cpt ]['singular_label'] = 'Book &amp; Bit';
				$cptui[ $cpt ]['description']    = $desc;
				update_option( 'cptui_post_types', $cptui );
				$reload = function () use ( $cpt ) {
					if ( post_type_exists( $cpt ) ) {
						unregister_post_type( $cpt );
					}
					cptui_register_single_post_type( ( (array) get_option( 'cptui_post_types', array() ) )[ $cpt ] );
				};
				$reload();
				$cb = $row( 'type', $cpt );
				list( $st ) = $call( 'POST', "/minn-admin/v1/post-types/{$cpt}", array( 'has_archive' => 1, 'base' => $cb['stamp'] ?? '' ) );
				$ck = (array) ( ( (array) get_option( 'cptui_post_types', array() ) )[ $cpt ] ?? array() );
				$check( '#6 CPT UI: a save of one switch answers 200 and writes it (control)', 200 === $st && 'true' === ( $ck['has_archive'] ?? null ), $st . ' ' . wp_json_encode( $ck['has_archive'] ?? null ) );
				$check( '#6 CPT UI: and keeps the description with its link and line break', $desc === ( $ck['description'] ?? null ), wp_json_encode( $ck['description'] ?? null ) );
				$check( '#6 CPT UI: and keeps both labels entity-encoded as CPT UI stored them', 'Books &amp; Bits' === ( $ck['label'] ?? null ) && 'Book &amp; Bit' === ( $ck['singular_label'] ?? null ), wp_json_encode( array( $ck['label'] ?? null, $ck['singular_label'] ?? null ) ) );

				// The modal is a textarea and the client compares as textOf()
				// does: a description sent back with CRLF endings and padding
				// is one nobody edited, and keeps the stored bytes.
				$reload();
				$cb = $row( 'type', $cpt );
				list( $st ) = $call( 'POST', "/minn-admin/v1/post-types/{$cpt}", array( 'description' => '  ' . str_replace( "\n", "\r\n", (string) $cb['description'] ) . "\n", 'show_in_rest' => 1, 'base' => $cb['stamp'] ?? '' ) );
				$ck = (array) ( ( (array) get_option( 'cptui_post_types', array() ) )[ $cpt ] ?? array() );
				$check( '#6 CPT UI: a description sent back with CRLF endings and padding keeps the stored bytes', 200 === $st && $desc === ( $ck['description'] ?? null ), $st . ' ' . wp_json_encode( $ck['description'] ?? null ) );

				// N1: an edit that changes only the line breaks is an edit.
				$reload();
				$cb = $row( 'type', $cpt );
				list( $st ) = $call( 'POST', "/minn-admin/v1/post-types/{$cpt}", array( 'description' => str_replace( "\n", "\n\n", (string) $cb['description'] ), 'base' => $cb['stamp'] ?? '' ) );
				$ck = (array) ( ( (array) get_option( 'cptui_post_types', array() ) )[ $cpt ] ?? array() );
				$check( 'N1 CPT UI: a description edit that only adds a line break is written', 200 === $st && str_replace( "\n", "\n\n", $desc ) === ( $ck['description'] ?? null ), $st . ' ' . wp_json_encode( $ck['description'] ?? null ) );
				$reload();
				$cb = $row( 'type', $cpt );
				list( $st ) = $call( 'POST', "/minn-admin/v1/post-types/{$cpt}", array( 'description' => str_replace( "\n", '', $desc ), 'base' => $cb['stamp'] ?? '' ) );
				$ck = (array) ( ( (array) get_option( 'cptui_post_types', array() ) )[ $cpt ] ?? array() );
				$check( 'N1 CPT UI: a description edit that only removes the line breaks is written', 200 === $st && str_replace( "\n", '', $desc ) === ( $ck['description'] ?? null ), $st . ' ' . wp_json_encode( $ck['description'] ?? null ) );

				// An edited description keeps its HTML, as CPT UI's own save does.
				$reload();
				$cb = $row( 'type', $cpt );
				list( $st ) = $call( 'POST', "/minn-admin/v1/post-types/{$cpt}", array( 'description' => 'See <a href="/new">the new page</a>', 'base' => $cb['stamp'] ?? '' ) );
				$ck = (array) ( ( (array) get_option( 'cptui_post_types', array() ) )[ $cpt ] ?? array() );
				$check( '#6 CPT UI: an edited description keeps its link (wp_kses_post, as CPT UI)', 200 === $st && 'See <a href="/new">the new page</a>' === ( $ck['description'] ?? null ), $st . ' ' . wp_json_encode( $ck['description'] ?? null ) );
				list( $st ) = $call( 'POST', "/minn-admin/v1/post-types/{$cpt}", array( 'description' => 'Bad <script>alert(1)</script>', 'base' => $row( 'type', $cpt )['stamp'] ?? '' ) );
				$ck = (array) ( ( (array) get_option( 'cptui_post_types', array() ) )[ $cpt ] ?? array() );
				$check( '#6 CPT UI: an edited description still loses a script tag (control)', false === strpos( (string) ( $ck['description'] ?? '' ), '<script' ), wp_json_encode( $ck['description'] ?? null ) );
			}
		} else {
			$skip( '#6 CPT UI inactive' );
		}
	} finally {
		$wpdb->query( 'ROLLBACK' );
	}
} )();

/*
 * #14: a JetSearch suggestion edit that changes only the weight leaves the
 * name alone. The dialog sends every field, the name as stored, and the
 * route ran it through sanitize_text_field: '50%DEAL' became '50AL', 'Shoes
 * <50' 'Shoes &lt;50', and a name that cleaned into another suggestion's
 * answered 409.
 */
( function () use ( $check, $skip, $call, $wpdb ) {
	if ( ! function_exists( 'minn_admin_jet_search_active' ) || ! minn_admin_jet_search_active() || ! minn_admin_jet_search_has_table() ) {
		$skip( '#14 JetSearch inactive' );
		return;
	}
	$table = minn_admin_jet_search_table();
	$wpdb->query( 'START TRANSACTION' );
	try {
		$ids = array();
		foreach ( array( '50%DEAL', 'Shoes <50', 'C%2B%2B guide', 'C guide' ) as $name ) {
			$wpdb->insert( $table, array( 'name' => $name, 'weight' => 3, 'parent' => '', 'term' => '' ) );
			$ids[ $name ] = (int) $wpdb->insert_id;
		}
		foreach ( array( '50%DEAL', 'Shoes <50', 'C%2B%2B guide' ) as $name ) {
			list( $st, $d ) = $call( 'POST', '/minn-admin/v1/jet-search/suggestions/' . $ids[ $name ], array( 'weight_seen' => 3, 'name' => $name, 'weight' => 5 ) );
			$now = $wpdb->get_row( $wpdb->prepare( "SELECT name, weight FROM {$table} WHERE id = %d", $ids[ $name ] ), ARRAY_A ); // phpcs:ignore
			$check( "#14 a weight-only edit of '{$name}' answers 200 and writes the weight", 200 === $st && 5 === (int) ( $now['weight'] ?? 0 ), $st . ' ' . wp_json_encode( $d ) );
			$check( "#14 and keeps the name '{$name}' as stored", $name === ( $now['name'] ?? null ), wp_json_encode( $now['name'] ?? null ) );
		}
		list( $st ) = $call( 'POST', '/minn-admin/v1/jet-search/suggestions/' . $ids['50%DEAL'], array( 'weight_seen' => 5, 'name' => 'C guide', 'weight' => 5 ) );
		$check( '#14 a real rename onto another suggestion is still refused with 409 (control)', 409 === $st, (string) $st );
		list( $st ) = $call( 'POST', '/minn-admin/v1/jet-search/suggestions/' . $ids['50%DEAL'], array( 'weight_seen' => 5, 'name' => 'Deals', 'weight' => 5 ) );
		$check( '#14 a real rename is written (control)', 200 === $st && 'Deals' === $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$table} WHERE id = %d", $ids['50%DEAL'] ) ), (string) $st ); // phpcs:ignore
	} finally {
		$wpdb->query( 'ROLLBACK' );
	}
} )();

/*
 * #13: an Elementor multi-choice answer keeps a pick the form no longer
 * offers. The entry page drew boxes for the form's current options only and
 * the save kept only those, so the first edit of the answer dropped the
 * visitor's renamed or removed choice; an option whose own text holds ', '
 * was split in two.
 */
( function () use ( $check, $skip, $call, $once ) {
	if ( ! function_exists( 'minn_admin_elementor_forms_ready' ) || ! minn_admin_elementor_forms_ready() || ! class_exists( '\ElementorPro\Modules\Forms\Submissions\Database\Repositories\Form_Snapshot_Repository' ) ) {
		$skip( '#13 Elementor Pro forms inactive' );
		return;
	}
	$query = \ElementorPro\Modules\Forms\Submissions\Database\Query::get_instance();
	$post  = wp_insert_post( array( 'post_title' => 'Minn registry writes', 'post_status' => 'draft', 'post_type' => 'post' ) );
	$sub   = 0;
	$clean = $once( function () use ( &$sub, $post, $query ) {
		if ( $sub ) {
			$query->delete_submission( $sub );
		}
		if ( $post ) {
			wp_delete_post( $post, true );
		}
	} );
	try {
		\ElementorPro\Modules\Forms\Submissions\Database\Repositories\Form_Snapshot_Repository::instance()->create_or_update( $post, 'minnreg', array(
			'name'   => 'Registry writes',
			'fields' => array( array( 'id' => 'picks', 'type' => 'checkbox', 'label' => 'Picks', 'options' => array( 'A', 'B', 'D', 'Red, dark' ) ) ),
		) );
		$sub = (int) $query->add_submission(
			array( 'post_id' => $post, 'element_id' => 'minnreg', 'form_name' => 'Registry writes', 'referer' => home_url( '/' ), 'referer_title' => 'Home' ),
			array( array( 'id' => 'picks', 'type' => 'checkbox', 'value' => 'A, C, Red, dark' ) )
		);
		if ( ! $sub ) {
			$skip( '#13 Elementor would not store a test submission' );
			return;
		}
		$block  = minn_admin_elementor_edit_block( $query->get_submission( $sub )['data'] );
		$field  = null;
		foreach ( (array) ( $block['fields'] ?? array() ) as $f ) {
			if ( 'picks' === $f['id'] ) {
				$field = $f;
			}
		}
		$served = $field ? $field['inputs'][0]['value'] : null;
		$values = $field ? array_map( 'strval', wp_list_pluck( $field['choices'], 0 ) ) : array();
		$check( '#13 the answer serves as a multi-choice field', $field && 'multi' === $field['kind'], wp_json_encode( $field ) );
		$check( "#13 an option holding ', ' is read as one pick", array( 'A', 'C', 'Red, dark' ) === $served, wp_json_encode( $served ) );
		$check( '#13 the pick the form no longer offers has a box', in_array( 'C', $values, true ), wp_json_encode( $values ) );
		// What the page sends when the person ticks B: every ticked box.
		$send = array();
		foreach ( $values as $v ) {
			if ( in_array( $v, array( 'A', 'B', 'C', 'Red, dark' ), true ) ) {
				$send[] = $v;
			}
		}
		list( $st, $d ) = $call( 'POST', "/minn-admin/v1/elementor/submissions/{$sub}/answers", array( 'values' => array( 'picks' => $send ), 'original' => array( 'picks' => $served ) ) );
		$stored = '';
		foreach ( (array) ( $query->get_submission( $sub )['data']['values'] ?? array() ) as $v ) {
			if ( 'picks' === ( $v['key'] ?? '' ) ) {
				$stored = (string) $v['value'];
			}
		}
		$picks = array_map( 'trim', explode( ', ', $stored ) );
		$check( '#13 ticking B answers 200 and stores B (control)', 200 === $st && in_array( 'B', $picks, true ), $st . ' ' . wp_json_encode( $d ) . ' ' . $stored );
		$check( '#13 and keeps the pick the form no longer offers', in_array( 'C', $picks, true ), $stored );
		$check( "#13 and keeps the option holding ', ' whole", false !== strpos( $stored, 'Red, dark' ), $stored );
		list( $st ) = $call( 'POST', "/minn-admin/v1/elementor/submissions/{$sub}/answers", array( 'values' => array( 'picks' => array( 'A', 'Z' ) ), 'original' => array( 'picks' => minn_admin_elementor_edit_block( $query->get_submission( $sub )['data'] )['fields'][0]['inputs'][0]['value'] ) ) );
		$stored = '';
		foreach ( (array) ( $query->get_submission( $sub )['data']['values'] ?? array() ) as $v ) {
			if ( 'picks' === ( $v['key'] ?? '' ) ) {
				$stored = (string) $v['value'];
			}
		}
		$check( '#13 a pick that is neither offered nor stored is not written (control)', false === strpos( $stored, 'Z' ), $stored );
	} finally {
		$clean();
	}
} )();

/*
 * #15: a Ninja Forms multi-choice answer keeps the picks that came from the
 * list a site fills at render time (ninja_forms_render_options) and the ones
 * the form no longer offers. Their own entry editor draws the filtered list;
 * Minn offered only the saved options and kept only those on save.
 */
( function () use ( $check, $skip, $call, $once ) {
	if ( ! function_exists( 'Ninja_Forms' ) || ! function_exists( 'minn_admin_ninja_forms_edit_block' ) || ! minn_admin_ninja_forms_can_edit() ) {
		$skip( '#15 Ninja Forms inactive' );
		return;
	}
	$form_id  = 0;
	$sub_id   = 0;
	$field_id = 0;
	$key      = 'minnregw_picks';
	$filter   = function ( $options, $settings ) use ( $key ) {
		if ( $key === ( $settings['key'] ?? '' ) ) {
			$options   = (array) $options;
			$options[] = array( 'label' => 'C', 'value' => 'C', 'calc' => '', 'selected' => 0 );
		}
		return $options;
	};
	$clean    = $once( function () use ( &$form_id, &$sub_id, &$field_id, $filter ) {
		remove_filter( 'ninja_forms_render_options', $filter, 10 );
		if ( $sub_id ) {
			wp_delete_post( $sub_id, true );
		}
		if ( $form_id && $field_id ) {
			Ninja_Forms()->form( $form_id )->field( $field_id )->get()->delete();
		}
		if ( $form_id ) {
			Ninja_Forms()->form( $form_id )->get()->delete();
			delete_option( 'nf_form_' . $form_id );
		}
	} );
	try {
		$form = Ninja_Forms()->form()->get();
		$form->update_setting( 'title', 'Minn registry writes' );
		$form->save();
		$form_id = (int) $form->get_id();
		$field   = Ninja_Forms()->form( $form_id )->field()->get();
		$field->update_settings( array(
			'type'    => 'listcheckbox',
			'label'   => 'Picks',
			'key'     => $key,
			'order'   => 1,
			'options' => array(
				array( 'label' => 'A', 'value' => 'A', 'calc' => '', 'selected' => 0, 'order' => 0 ),
				array( 'label' => 'B', 'value' => 'B', 'calc' => '', 'selected' => 0, 'order' => 1 ),
			),
		) );
		$field->save();
		$field_id = (int) $field->get_id();
		if ( ! $form_id || ! $field_id ) {
			$skip( '#15 Ninja Forms would not store a test form' );
			return;
		}
		add_filter( 'ninja_forms_render_options', $filter, 10, 2 );
		$sub = Ninja_Forms()->form( $form_id )->sub()->get();
		$sub->update_field_value( $field_id, array( 'A', 'C', 'Z' ) );
		$sub->save();
		$sub_id = (int) $sub->get_id();

		$block = minn_admin_ninja_forms_edit_block( get_post( $sub_id ), $form_id );
		$row   = null;
		foreach ( (array) ( $block['fields'] ?? array() ) as $f ) {
			if ( (string) $field_id === $f['id'] ) {
				$row = $f;
			}
		}
		$values = $row ? array_map( 'strval', wp_list_pluck( $row['choices'], 0 ) ) : array();
		$served = $row ? $row['inputs'][0]['value'] : null;
		$check( '#15 the answer serves as a multi-choice field with its stored picks', $row && 'multi' === $row['kind'] && array( 'A', 'C', 'Z' ) === $served, wp_json_encode( $row ) );
		$check( '#15 the pick from the render-time list has a box, as in Ninja Forms\' own editor', in_array( 'C', $values, true ), wp_json_encode( $values ) );
		$check( '#15 the pick the form no longer offers has a box', in_array( 'Z', $values, true ), wp_json_encode( $values ) );
		list( $st, $d ) = $call( 'POST', "/minn-admin/v1/ninja-forms/entries/{$sub_id}/answers", array( 'values' => array( (string) $field_id => array( 'A', 'B', 'C', 'Z' ) ), 'original' => array( (string) $field_id => $served ) ) );
		$now = minn_admin_ninja_forms_edit_value( $sub_id, $field_id, 'multi' );
		$check( '#15 ticking B answers 200 and stores B (control)', 200 === $st && in_array( 'B', $now, true ), $st . ' ' . wp_json_encode( $d ) . ' ' . wp_json_encode( $now ) );
		$check( '#15 and keeps the render-time pick and the one the form no longer offers', in_array( 'C', $now, true ) && in_array( 'Z', $now, true ), wp_json_encode( $now ) );
		list( $st ) = $call( 'POST', "/minn-admin/v1/ninja-forms/entries/{$sub_id}/answers", array( 'values' => array( (string) $field_id => array( 'A', 'Q' ) ), 'original' => array( (string) $field_id => $now ) ) );
		$check( '#15 a pick that is neither offered nor stored is not written (control)', ! in_array( 'Q', minn_admin_ninja_forms_edit_value( $sub_id, $field_id, 'multi' ), true ), wp_json_encode( minn_admin_ninja_forms_edit_value( $sub_id, $field_id, 'multi' ) ) );
	} finally {
		$clean();
	}
} )();

/*
 * #16: the WP Freighter tenant edit dialog round-trips an empty name. The
 * row served the display fallback (the hostname, or 'Tenant N') as `name`,
 * the dialog sent it back as the untouched value, and the route stored it.
 * A stored name with an entity came back decoded the same way.
 */
( function () use ( $check, $skip, $call ) {
	if ( ! function_exists( 'minn_admin_freighter_active' ) || ! minn_admin_freighter_active() || ! minn_admin_freighter_can() ) {
		$skip( '#16 WP Freighter inactive' );
		return;
	}
	$sites = array();
	foreach ( (array) \WPFreighter\Sites::fetch() as $s ) {
		$id = (int) $s['stacked_site_id'];
		if ( ! minn_admin_freighter_is_primary_id( $id ) ) {
			$sites[ $id ] = $s;
		}
	}
	if ( ! $sites ) {
		$skip( '#16 no tenant to edit' );
		return;
	}
	$id   = (int) array_key_first( $sites );
	$orig = (string) ( $sites[ $id ]['name'] ?? '' );
	$restore = function () use ( $id, $orig ) {
		\WPFreighter\Site::update( $id, array( 'name' => $orig ) );
	};
	register_shutdown_function( $restore );
	try {
		foreach ( array( '', 'Fish &amp; Chips' ) as $name ) {
			\WPFreighter\Site::update( $id, array( 'name' => $name ) );
			$row = minn_admin_freighter_row( \WPFreighter\Site::get( $id ) );
			$check( '#16 the row serves the stored name ' . wp_json_encode( $name ) . ' as stored, with a title to show', $name === $row['name'] && '' !== (string) ( $row['title'] ?? '' ), wp_json_encode( $row ) );
			// What the dialog sends for an untouched name: the row's value.
			list( $st ) = $call( 'POST', "/minn-admin/v1/freighter/sites/{$id}", array( 'name' => $row['name'] ) );
			$now = (string) ( \WPFreighter\Site::get( $id )['name'] ?? '' );
			$check( '#16 a save that leaves it alone keeps the stored name ' . wp_json_encode( $name ), 200 === $st && $name === $now, $st . ' ' . wp_json_encode( $now ) );
		}
		list( $st ) = $call( 'POST', "/minn-admin/v1/freighter/sites/{$id}", array( 'name' => 'Minn suite rename' ) );
		$check( '#16 a real rename is written (control)', 200 === $st && 'Minn suite rename' === ( \WPFreighter\Site::get( $id )['name'] ?? '' ), (string) $st );
	} finally {
		$restore();
	}
	$check( '#16 the tenant ends with its own name', $orig === (string) ( \WPFreighter\Site::get( $id )['name'] ?? '' ) );
} )();

/*
 * Sibling of #13/#15: the Gravity Forms and WPForms entry editors dropped a
 * stored multi-choice pick the form no longer offers on the first edit of
 * that answer. WPForms' own save drops any value outside the field's choices
 * too, so Minn's save admits the entry's own stored picks through their
 * wpforms_field_choices_allow_unknown_value filter for that save.
 */
( function () use ( $check, $skip, $call, $once ) {
	if ( ! class_exists( 'GFAPI' ) || ! function_exists( 'minn_admin_gf_entry_edit_block' ) || ! GFCommon::current_user_can_any( array( 'gravityforms_edit_entries', 'gform_full_access' ) ) ) {
		$skip( 'GF multi-choice: Gravity Forms inactive' );
		return;
	}
	$form_id = 0;
	$clean   = $once( function () use ( &$form_id ) {
		if ( $form_id ) {
			GFAPI::delete_form( $form_id );
		}
	} );
	try {
		$form_id = GFAPI::add_form( array(
			'title'  => 'Minn registry writes ' . wp_rand( 1000, 9999 ),
			'fields' => array(
				array( 'id' => 1, 'type' => 'multiselect', 'label' => 'Picks', 'storageType' => 'json', 'choices' => array( array( 'text' => 'A', 'value' => 'A' ), array( 'text' => 'B', 'value' => 'B' ) ) ),
			),
		) );
		if ( is_wp_error( $form_id ) || ! $form_id ) {
			$form_id = 0;
			$skip( 'GF multi-choice: Gravity Forms would not store a test form' );
			return;
		}
		$entry_id = GFAPI::add_entry( array( 'form_id' => $form_id, '1' => wp_json_encode( array( 'A', 'Gone' ) ) ) );
		$block    = minn_admin_gf_entry_edit_block( GFAPI::get_form( $form_id ), GFAPI::get_entry( $entry_id ) );
		$row      = $block['fields'][0] ?? null;
		$values   = $row ? array_map( 'strval', wp_list_pluck( (array) ( $row['choices'] ?? array() ), 0 ) ) : array();
		$served   = $row ? $row['inputs'][0]['value'] : null;
		$check( 'GF multi-choice: the pick the form no longer offers has a box', $row && 'multi' === $row['kind'] && in_array( 'Gone', $values, true ), wp_json_encode( $row ) );
		list( $st, $d ) = $call( 'POST', "/minn-admin/v1/gf/entries/{$entry_id}/answers", array( 'values' => array( '1' => array( 'A', 'B', 'Gone' ) ), 'original' => array( '1' => $served ) ) );
		$now = array_map( 'strval', (array) GFAPI::get_field( GFAPI::get_form( $form_id ), 1 )->to_array( rgar( GFAPI::get_entry( $entry_id ), '1' ) ) );
		$check( 'GF multi-choice: ticking B answers 200 and stores B (control)', 200 === $st && in_array( 'B', $now, true ), $st . ' ' . wp_json_encode( $d ) . ' ' . wp_json_encode( $now ) );
		$check( 'GF multi-choice: and keeps the pick the form no longer offers', in_array( 'Gone', $now, true ), wp_json_encode( $now ) );
		list( $st ) = $call( 'POST', "/minn-admin/v1/gf/entries/{$entry_id}/answers", array( 'values' => array( '1' => array( 'A', 'Q' ) ), 'original' => array( '1' => $now ) ) );
		$after = array_map( 'strval', (array) GFAPI::get_field( GFAPI::get_form( $form_id ), 1 )->to_array( rgar( GFAPI::get_entry( $entry_id ), '1' ) ) );
		$check( 'GF multi-choice: a pick that is neither offered nor stored is not written (control)', ! in_array( 'Q', $after, true ), wp_json_encode( $after ) );
	} finally {
		$clean();
	}
} )();

( function () use ( $check, $skip, $call, $once ) {
	if ( ! function_exists( 'minn_admin_wpforms_edit_supported' ) || ! minn_admin_wpforms_edit_supported() || ! function_exists( 'minn_admin_wpforms_edit_block' ) ) {
		$skip( 'WPForms multi-choice: WPForms Pro inactive' );
		return;
	}
	$made  = array( 'form' => 0, 'entry' => 0 );
	$clean = $once( function () use ( &$made ) {
		if ( $made['entry'] ) {
			wpforms()->obj( 'entry' )->delete( $made['entry'] );
		}
		if ( $made['form'] ) {
			wp_delete_post( $made['form'], true );
		}
	} );
	try {
		$made['form'] = (int) wpforms()->obj( 'form' )->add( 'Minn registry writes' );
		wpforms()->obj( 'form' )->update( $made['form'], array(
			'id'       => $made['form'],
			'field_id' => 2,
			'settings' => array( 'form_title' => 'Minn registry writes' ),
			'fields'   => array(
				1 => array( 'id' => 1, 'type' => 'checkbox', 'label' => 'Extras', 'choices' => array( 1 => array( 'label' => 'News', 'value' => '' ), 2 => array( 'label' => 'Call', 'value' => '' ) ) ),
			),
		) );
		$fields        = array( 1 => array( 'name' => 'Extras', 'value' => "News\nGone", 'value_raw' => "News\nGone", 'id' => 1, 'type' => 'checkbox' ) );
		$made['entry'] = (int) wpforms()->obj( 'entry' )->add( array( 'form_id' => $made['form'], 'status' => '', 'fields' => wp_json_encode( $fields ) ) );
		wpforms()->obj( 'entry_fields' )->save( $fields, wpforms()->obj( 'form' )->get( $made['form'], array( 'content_only' => true ) ), $made['entry'] );
		$stored = function () use ( &$made ) {
			$e = wpforms()->obj( 'entry' )->get( $made['entry'] );
			$f = (array) wpforms_decode( (string) ( $e->fields ?? '' ) );
			return array_values( array_filter( explode( "\n", (string) ( $f[1]['value_raw'] ?? $f['1']['value_raw'] ?? '' ) ), 'strlen' ) );
		};
		$block  = minn_admin_wpforms_edit_block( (object) Minn_Admin_Kernel_SQL::row( 'wpforms_entries', 'entry_id', $made['entry'] ) );
		$row    = null;
		foreach ( (array) ( $block['fields'] ?? array() ) as $f ) {
			if ( '1' === (string) $f['id'] ) {
				$row = $f;
			}
		}
		$values = $row ? array_map( 'strval', wp_list_pluck( (array) ( $row['choices'] ?? array() ), 0 ) ) : array();
		$served = $row ? $row['inputs'][0]['value'] : null;
		$check( 'WPForms multi-choice: the pick the form no longer offers has a box', $row && 'multi' === $row['kind'] && in_array( 'Gone', $values, true ), wp_json_encode( $row ) );
		list( $st, $d ) = $call( 'POST', "/minn-admin/v1/wpforms/entries/{$made['entry']}/answers", array( 'values' => array( '1' => array( 'News', 'Call', 'Gone' ) ), 'original' => array( '1' => $served ) ) );
		$now = $stored();
		$check( 'WPForms multi-choice: ticking Call answers 200 and stores Call (control)', 200 === $st && in_array( 'Call', $now, true ), $st . ' ' . wp_json_encode( $d ) . ' ' . wp_json_encode( $now ) );
		$check( 'WPForms multi-choice: and keeps the pick the form no longer offers', in_array( 'Gone', $now, true ), wp_json_encode( $now ) );
		list( $st ) = $call( 'POST', "/minn-admin/v1/wpforms/entries/{$made['entry']}/answers", array( 'values' => array( '1' => array( 'News', 'Q' ) ), 'original' => array( '1' => $now ) ) );
		$check( 'WPForms multi-choice: a pick that is neither offered nor stored is not written (control)', ! in_array( 'Q', $stored(), true ), wp_json_encode( $stored() ) );
	} finally {
		$clean();
	}
} )();

/*
 * Sibling of #16: the JetThemeCore and Elementor template rename dialogs
 * round-trip the stored title. The row served '(no title)' (Elementor: the
 * entity-decoded title too) as `title`, the dialog sent it back as the
 * untouched value, and the route wrote it, through sanitize_text_field on
 * JetThemeCore.
 */
( function () use ( $check, $skip, $call, $once ) {
	$cases = array();
	if ( function_exists( 'minn_admin_jet_tc_active' ) && minn_admin_jet_tc_active() && minn_admin_jet_tc_can() ) {
		$cases['JetThemeCore'] = array(
			'type'  => 'jet-theme-core',
			'meta'  => array( '_jet_template_type' => 'jet_footer' ),
			'route' => '/minn-admin/v1/jet-theme-core/templates/',
			'item'  => 'minn_admin_jet_tc_item',
		);
	} else {
		$skip( 'template titles: JetThemeCore inactive' );
	}
	if ( function_exists( 'minn_admin_elementor_templates_ready' ) && minn_admin_elementor_templates_ready() && minn_admin_elementor_can_manage() ) {
		$cases['Elementor'] = array(
			'type'  => 'elementor_library',
			'meta'  => array( '_elementor_template_type' => 'section', '_elementor_edit_mode' => 'builder' ),
			'route' => '/minn-admin/v1/elementor/templates/',
			'item'  => 'minn_admin_elementor_template_item',
		);
	} else {
		$skip( 'template titles: Elementor templates inactive' );
	}
	foreach ( $cases as $name => $c ) {
		$post  = 0;
		$clean = $once( function () use ( &$post ) {
			if ( $post ) {
				wp_delete_post( $post, true );
			}
		} );
		try {
			foreach ( array( '', '50%DEAL footer', 'Fish &amp; Chips' ) as $title ) {
				if ( ! $post ) {
					$post = (int) wp_insert_post( array( 'post_type' => $c['type'], 'post_status' => 'publish', 'post_title' => $title, 'post_content' => '<!-- minn registry writes -->', 'meta_input' => $c['meta'] ) );
				} else {
					global $wpdb;
					$wpdb->update( $wpdb->posts, array( 'post_title' => $title ), array( 'ID' => $post ) );
					clean_post_cache( $post );
				}
				$row = call_user_func( $c['item'], get_post( $post ) );
				$check( "{$name}: the row serves the stored title " . wp_json_encode( $title ) . ' as stored, with a label to show', $title === ( $row['title'] ?? null ) && '' !== (string) ( $row['label'] ?? '' ), wp_json_encode( $row ) );
				// What the rename dialog sends for an untouched title: the row's value.
				list( $st, $d ) = $call( 'PUT', $c['route'] . $post, array( 'title' => $row['title'] ) );
				$check( "{$name}: a save that leaves it alone keeps the stored title " . wp_json_encode( $title ), 200 === $st && $title === get_post( $post )->post_title, $st . ' ' . wp_json_encode( $d ) . ' ' . wp_json_encode( get_post( $post )->post_title ) );
			}
			list( $st ) = $call( 'PUT', $c['route'] . $post, array( 'title' => 'Minn suite rename' ) );
			$check( "{$name}: a real rename is written (control)", 200 === $st && 'Minn suite rename' === get_post( $post )->post_title, (string) $st );
			list( $st ) = $call( 'PUT', $c['route'] . $post, array( 'title' => '' ) );
			$check( "{$name}: clearing a title is still refused (control)", 400 === $st && 'Minn suite rename' === get_post( $post )->post_title, (string) $st );
		} finally {
			$clean();
		}
	}
} )();

/*
 * N2 and I9 (reviews of ed4a8756 and aea3eff6): on a WPForms field with
 * values shown, their format() rebuilds the answer's labels (`value`, what
 * their entry view and export print) from the choices it still has, so a
 * kept pick stayed in value_raw and left `value`. Minn's save now puts the
 * kept pick's stored label back into `value`, and the field stays editable
 * in Minn (a lock into WPForms' editor was the first fix). And the
 * allow-unknown filter Minn sets for its save answers for this form only.
 */
( function () use ( $check, $skip, $call, $once ) {
	if ( ! function_exists( 'minn_admin_wpforms_edit_supported' ) || ! minn_admin_wpforms_edit_supported() || ! function_exists( 'minn_admin_wpforms_edit_block' ) ) {
		$skip( 'N2 WPForms Pro inactive' );
		return;
	}
	$made  = array( 'form' => 0, 'entry' => 0 );
	$clean = $once( function () use ( &$made ) {
		if ( $made['entry'] ) {
			wpforms()->obj( 'entry' )->delete( $made['entry'] );
		}
		if ( $made['form'] ) {
			wp_delete_post( $made['form'], true );
		}
	} );
	try {
		$made['form'] = (int) wpforms()->obj( 'form' )->add( 'Minn registry writes N2' );
		wpforms()->obj( 'form' )->update( $made['form'], array(
			'id'       => $made['form'],
			'field_id' => 3,
			'settings' => array( 'form_title' => 'Minn registry writes N2' ),
			'fields'   => array(
				1 => array( 'id' => 1, 'type' => 'checkbox', 'label' => 'Coded', 'show_values' => '1', 'choices' => array( 1 => array( 'label' => 'News', 'value' => 'n' ), 2 => array( 'label' => 'Call', 'value' => 'c' ) ) ),
				2 => array( 'id' => 2, 'type' => 'checkbox', 'label' => 'Plain', 'choices' => array( 1 => array( 'label' => 'News', 'value' => '' ), 2 => array( 'label' => 'Call', 'value' => '' ) ) ),
			),
		) );
		$fields        = array(
			1 => array( 'name' => 'Coded', 'value' => "News\nGone", 'value_raw' => "n\ng", 'id' => 1, 'type' => 'checkbox' ),
			2 => array( 'name' => 'Plain', 'value' => "News\nGone", 'value_raw' => "News\nGone", 'id' => 2, 'type' => 'checkbox' ),
		);
		$made['entry'] = (int) wpforms()->obj( 'entry' )->add( array( 'form_id' => $made['form'], 'status' => '', 'fields' => wp_json_encode( $fields ) ) );
		wpforms()->obj( 'entry_fields' )->save( $fields, wpforms()->obj( 'form' )->get( $made['form'], array( 'content_only' => true ) ), $made['entry'] );
		$block = minn_admin_wpforms_edit_block( (object) Minn_Admin_Kernel_SQL::row( 'wpforms_entries', 'entry_id', $made['entry'] ) );
		$ids   = array_map( 'strval', wp_list_pluck( (array) ( $block['fields'] ?? array() ), 'id' ) );
		$check( 'N2 WPForms: a values-shown answer holding a pick the form no longer offers is editable in Minn', in_array( '1', $ids, true ) && ! in_array( 'Coded', (array) ( $block['locked'] ?? array() ), true ), wp_json_encode( $block ) );
		list( $st, $d ) = $call( 'POST', "/minn-admin/v1/wpforms/entries/{$made['entry']}/answers", array( 'values' => array( '1' => array( 'n', 'c', 'g' ) ), 'original' => array( '1' => array( 'n', 'g' ) ) ) );
		$entry  = wpforms()->obj( 'entry' )->get( $made['entry'] );
		$stored = (array) wpforms_decode( (string) ( $entry->fields ?? '' ) );
		$coded  = (array) ( $stored[1] ?? $stored['1'] ?? array() );
		$shown  = explode( "\n", (string) ( $coded['value'] ?? '' ) );
		$check( 'N2 WPForms: ticking Call answers 200 and keeps the pick in value_raw (control)', 200 === $st && array( 'n', 'c', 'g' ) === explode( "\n", (string) ( $coded['value_raw'] ?? '' ) ), $st . ' ' . wp_json_encode( $d ) . ' ' . wp_json_encode( $coded ) );
		$check( 'N2 WPForms: and what WPForms shows and exports keeps the kept pick\'s label', in_array( 'Gone', $shown, true ) && in_array( 'News', $shown, true ) && in_array( 'Call', $shown, true ), wp_json_encode( $coded ) );
		$row_value = '';
		foreach ( (array) wpforms()->obj( 'entry_fields' )->get_fields( array( 'entry_id' => $made['entry'], 'field_id' => 1 ) ) as $ef ) {
			$row_value = (string) ( is_object( $ef ) ? $ef->value : ( $ef['value'] ?? '' ) );
		}
		$check( 'N2 WPForms: and so does its entry_fields row (their search)', false !== strpos( $row_value, 'Gone' ), wp_json_encode( $row_value ) );
		$check( 'N2 WPForms: a plain checkbox answer with a kept pick is still editable (control)', in_array( '2', $ids, true ), wp_json_encode( $ids ) );

		// I9: inside Minn's save, the filter answers for this form only.
		$seen  = array();
		$probe = function () use ( &$seen, $made ) {
			$seen['other'] = (bool) apply_filters( 'wpforms_field_choices_allow_unknown_value', false, array( 'Gone' ), array( 'id' => 2 ), array( 'id' => $made['form'] + 100000 ) );
			$seen['own']   = (bool) apply_filters( 'wpforms_field_choices_allow_unknown_value', false, array( 'Gone' ), array( 'id' => 2 ), array( 'id' => $made['form'] ) );
		};
		add_action( 'wpforms_pro_admin_entries_edit_submit_before_processing', $probe );
		$call( 'POST', "/minn-admin/v1/wpforms/entries/{$made['entry']}/answers", array( 'values' => array( '2' => array( 'News', 'Call', 'Gone' ) ), 'original' => array( '2' => array( 'News', 'Gone' ) ) ) );
		remove_action( 'wpforms_pro_admin_entries_edit_submit_before_processing', $probe );
		$check( 'I9 WPForms: the save\'s allow-unknown filter does not answer for another form\'s field of the same id', isset( $seen['other'] ) && false === $seen['other'], wp_json_encode( $seen ) );
		$check( 'I9 WPForms: and still answers for its own form (control)', ! empty( $seen['own'] ), wp_json_encode( $seen ) );
	} finally {
		$clean();
	}
} )();

/*
 * L5/L6 (review): Custom CSS & JS served 'Untitled CSS' as the editable
 * name and wrote the name through sanitize_text_field on every save; the
 * edit dialog sends every field. Runs where Custom CSS & JS is loaded
 * (on minnadmin: MINN_SWAP_ADD=custom-css-js/custom-css-js.php with
 * --require=tests/lib/plugin-swap.php).
 */
( function () use ( $check, $skip, $call, $once ) {
	if ( ! function_exists( 'minn_admin_ccj_item' ) || ! post_type_exists( 'custom-css-js' ) || ! defined( 'CCJ_UPLOAD_DIR' ) ) {
		$skip( 'L5/L6 Custom CSS & JS not loaded' );
		return;
	}
	$tree_was = get_option( 'custom-css-js-tree', array() );
	$post     = 0;
	$clean    = $once( function () use ( &$post, $tree_was ) {
		if ( $post ) {
			minn_admin_ccj_drop_files( $post );
			wp_delete_post( $post, true );
		}
		update_option( 'custom-css-js-tree', $tree_was );
		minn_admin_ccj_rebuild_tree();
	} );
	try {
		$post = (int) wp_insert_post( array( 'post_type' => 'custom-css-js', 'post_status' => 'publish', 'post_title' => '', 'post_content' => '.minn-reg{color:red}' ) );
		update_post_meta( $post, 'options', minn_admin_ccj_default_options( 'css' ) );
		update_post_meta( $post, '_active', 'yes' );
		foreach ( array( '', '50%DEAL tweaks' ) as $title ) {
			global $wpdb;
			$wpdb->update( $wpdb->posts, array( 'post_title' => $title ), array( 'ID' => $post ) );
			clean_post_cache( $post );
			list( , $row ) = $call( 'GET', "/minn-admin/v1/ccj/snippets/{$post}" );
			$check( 'L6 CCJ: the row serves the stored name ' . wp_json_encode( $title ) . ' as stored, with a label to show', $title === ( $row['name'] ?? null ) && '' !== (string) ( $row['label'] ?? '' ), wp_json_encode( $row ) );
			// What the dialog sends for a code-only edit: every field, the name as the row has it.
			$body = array( 'name' => $row['name'] ?? '', 'code' => '.minn-reg{color:blue}' . strlen( $title ), 'language' => 'css', 'type' => 'header', 'side' => 'frontend', 'linking' => 'internal', 'priority' => 5 );
			list( $st, $d ) = $call( 'PUT', "/minn-admin/v1/ccj/snippets/{$post}", $body );
			$now = get_post( $post );
			$check( 'L5 CCJ: a code-only edit answers 200 and writes the code (control)', 200 === $st && false !== strpos( $now->post_content, 'color:blue' ), $st . ' ' . wp_json_encode( $d ) );
			$check( 'L5/L6 CCJ: and keeps the stored name ' . wp_json_encode( $title ), $title === $now->post_title, wp_json_encode( $now->post_title ) );
		}
		list( $st ) = $call( 'PUT', "/minn-admin/v1/ccj/snippets/{$post}", array( 'name' => 'Minn suite rename' ) );
		$check( 'L5 CCJ: a real rename is written (control)', 200 === $st && 'Minn suite rename' === get_post( $post )->post_title, (string) $st );
	} finally {
		$clean();
	}
} )();

/*
 * L5 (review): a Novamira memory edit cleaned and wrote every field the
 * dialog sent, so changing only the type ran the content through
 * wp_strip_all_tags and the name and description through
 * sanitize_text_field.
 */
( function () use ( $check, $skip, $once ) {
	if ( ! function_exists( 'minn_admin_novamira_memory_ready' ) || ! minn_admin_novamira_memory_ready() || ! minn_admin_novamira_can() ) {
		$skip( 'L5 Novamira memories inactive' );
		return;
	}
	$post  = 0;
	$clean = $once( function () use ( &$post ) {
		if ( $post ) {
			wp_delete_post( $post, true );
		}
	} );
	$send  = function ( $id, array $body ) {
		$r = new WP_REST_Request( 'POST', '/minn-admin/v1/novamira/memories/' . $id );
		$r->set_header( 'content-type', 'application/json' );
		$r->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
		$r->set_body( wp_json_encode( $body ) );
		return rest_do_request( $r )->get_status();
	};
	try {
		$content = "Use <code>wp_kses_post()</code> on <b>output</b>.\nKeep 50%DEAL as typed.";
		$post    = (int) wp_insert_post( wp_slash( array( 'post_type' => 'novamira_memory', 'post_status' => 'publish', 'post_title' => '50%DEAL rules', 'post_excerpt' => 'Shoes <50 & more', 'post_content' => $content ) ) );
		update_post_meta( $post, '_novamira_memory_type', 'project' );
		$p   = get_post( $post );
		$row = minn_admin_novamira_memory_item( $p );
		$st  = $send( $post, array( 'name' => $row['name'], 'description' => $row['description'], 'type' => 'reference', 'content' => $row['content'] ) );
		$now = get_post( $post );
		$check( 'L5 Novamira: a type-only edit answers 200 and writes the type (control)', 200 === $st && 'reference' === get_post_meta( $post, '_novamira_memory_type', true ), (string) $st );
		$check( 'L5 Novamira: and keeps the content with its tags', $p->post_content === $now->post_content, wp_json_encode( $now->post_content ) );
		$check( 'L5 Novamira: and keeps the name and description as stored', $p->post_title === $now->post_title && $p->post_excerpt === $now->post_excerpt, wp_json_encode( array( $now->post_title, $now->post_excerpt ) ) );
		$st = $send( $post, array( 'name' => 'Minn suite rename' ) );
		$check( 'L5 Novamira: a real rename is written (control)', 200 === $st && 'Minn suite rename' === get_post( $post )->post_title, (string) $st );
	} finally {
		$clean();
	}
} )();

/*
 * L6 (review): Bricks served html_entity_decode( post_title ) as the title
 * its edit dialog round-trips, and a type-only edit wrote esc_html() of the
 * decoded copy back, turning a stored title's tags into text. Runs where
 * Bricks is the active theme (the builders lab).
 */
( function () use ( $check, $skip, $call, $once ) {
	if ( ! defined( 'BRICKS_DB_TEMPLATE_SLUG' ) || ! function_exists( 'minn_admin_bricks_template_item' ) || ! minn_admin_bricks_can_view_templates() ) {
		$skip( 'L6 Bricks inactive' );
		return;
	}
	$post  = 0;
	$clean = $once( function () use ( &$post ) {
		if ( $post ) {
			wp_delete_post( $post, true );
		}
	} );
	try {
		$post = (int) wp_insert_post( array( 'post_type' => BRICKS_DB_TEMPLATE_SLUG, 'post_status' => 'publish', 'post_title' => 'x', 'post_content' => '' ) );
		update_post_meta( $post, BRICKS_DB_TEMPLATE_TYPE, 'section' );
		foreach ( array( '', 'Fish &amp; Chips', '<b>Hot</b> &amp; fresh' ) as $title ) {
			global $wpdb;
			$wpdb->update( $wpdb->posts, array( 'post_title' => $title ), array( 'ID' => $post ) );
			clean_post_cache( $post );
			$row = minn_admin_bricks_template_item( get_post( $post ) );
			$check( 'L6 Bricks: the row serves the stored title ' . wp_json_encode( $title ) . ' as stored', $title === ( $row['title'] ?? null ) && array_key_exists( 'label', $row ), wp_json_encode( $row ) );
			// A type-only edit: the dialog sends the title as the row has it.
			list( $st, $d ) = $call( 'PUT', "/minn-admin/v1/bricks/templates/{$post}", array( 'title' => $row['title'], 'type' => 'section' ) );
			$check( 'L6 Bricks: a save that leaves it alone keeps the stored title ' . wp_json_encode( $title ), 200 === $st && $title === get_post( $post )->post_title, $st . ' ' . wp_json_encode( $d ) . ' ' . wp_json_encode( get_post( $post )->post_title ) );
		}
		list( $st ) = $call( 'PUT', "/minn-admin/v1/bricks/templates/{$post}", array( 'title' => 'Minn suite rename', 'type' => 'section' ) );
		$check( 'L6 Bricks: a real rename is written (control)', 200 === $st && 'Minn suite rename' === get_post( $post )->post_title, (string) $st );
	} finally {
		$clean();
	}
} )();

/*
 * Found by the seeded round trip (roundtrip.sh, lib/roundtrip-seed.php),
 * the #14 class in three more snippet stores: WPCode, Header Footer Code
 * Manager and FluentSnippets cleaned the name, the description and the tags
 * on every save, so an edit that changed only another field rewrote them
 * ("50%DEAL" lost "%DE", inline markup was stripped).
 */
$awkward = "Minn 50%DEAL &amp; <em>Fish</em> 'chips' é";
( function () use ( $check, $skip, $call, $wpdb, $awkward ) {
	if ( ! function_exists( 'minn_admin_hfcm_active' ) || ! minn_admin_hfcm_active() ) {
		$skip( 'seeded HFCM inactive' );
		return;
	}
	$table = $wpdb->prefix . 'hfcm_scripts';
	$id    = (int) $wpdb->get_var( "SELECT script_id FROM {$table} ORDER BY script_id ASC LIMIT 1" ); // phpcs:ignore
	if ( ! $id ) {
		$skip( 'seeded HFCM has no snippet' );
		return;
	}
	$wpdb->query( 'START TRANSACTION' );
	try {
		$wpdb->update( $table, array( 'name' => $awkward ), array( 'script_id' => $id ) );
		$row  = $wpdb->get_row( $wpdb->prepare( "SELECT device_type FROM {$table} WHERE script_id = %d", $id ), ARRAY_A ); // phpcs:ignore
		$next = 'mobile' === $row['device_type'] ? 'desktop' : 'mobile';
		list( $st, $d ) = $call( 'PUT', "/minn-admin/v1/hfcm/snippets/{$id}", array( 'name' => $awkward, 'device_type' => $next ) );
		$now = $wpdb->get_row( $wpdb->prepare( "SELECT name, device_type FROM {$table} WHERE script_id = %d", $id ), ARRAY_A ); // phpcs:ignore
		$check( 'seeded HFCM: a device-only edit answers 200 and writes the device', 200 === $st && $next === $now['device_type'], $st . ' ' . wp_json_encode( $d ) );
		$check( 'seeded HFCM: and keeps the stored name', $awkward === $now['name'], wp_json_encode( $now['name'] ) );
		list( $st ) = $call( 'PUT', "/minn-admin/v1/hfcm/snippets/{$id}", array( 'name' => 'Minn <b>rename</b>' ) );
		$check( 'seeded HFCM: a real rename is cleaned and written (control)', 200 === $st && 'Minn rename' === $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$table} WHERE script_id = %d", $id ) ), (string) $st ); // phpcs:ignore
	} finally {
		$wpdb->query( 'ROLLBACK' );
		wp_cache_flush();
	}
} )();

( function () use ( $check, $skip, $call, $wpdb, $awkward ) {
	if ( ! function_exists( 'wpcode' ) || ! class_exists( 'WPCode_Snippet' ) ) {
		$skip( 'seeded WPCode inactive' );
		return;
	}
	$ids = get_posts( array( 'post_type' => 'wpcode', 'post_status' => 'any', 'numberposts' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids' ) );
	if ( ! $ids ) {
		$skip( 'seeded WPCode has no snippet' );
		return;
	}
	$id   = (int) $ids[0];
	$note = "Line one 50%DEAL &amp; é\n\nLine two <b>bold</b>";
	$wpdb->query( 'START TRANSACTION' );
	try {
		$wpdb->update( $wpdb->posts, array( 'post_title' => $awkward ), array( 'ID' => $id ) );
		update_post_meta( $id, '_wpcode_note', wp_slash( $note ) );
		clean_post_cache( $id );
		$prio = (int) get_post_meta( $id, '_wpcode_priority', true );
		list( $st, $d ) = $call( 'PUT', "/minn-admin/v1/wpcode/snippets/{$id}", array( 'name' => $awkward, 'desc' => $note, 'priority' => $prio + 3 ) );
		clean_post_cache( $id );
		$check( 'seeded WPCode: a priority-only edit answers 200 and writes the priority', 200 === $st && $prio + 3 === (int) get_post_meta( $id, '_wpcode_priority', true ), $st . ' ' . wp_json_encode( $d ) );
		$check( 'seeded WPCode: and keeps the stored title', $awkward === get_post( $id )->post_title, wp_json_encode( get_post( $id )->post_title ) );
		$check( 'seeded WPCode: and keeps the stored note', $note === get_post_meta( $id, '_wpcode_note', true ), wp_json_encode( get_post_meta( $id, '_wpcode_note', true ) ) );
		list( $st ) = $call( 'PUT', "/minn-admin/v1/wpcode/snippets/{$id}", array( 'name' => 'Minn <b>rename</b>' ) );
		clean_post_cache( $id );
		$check( 'seeded WPCode: a real rename is cleaned and written (control)', 200 === $st && 'Minn rename' === get_post( $id )->post_title, (string) $st . ' ' . wp_json_encode( get_post( $id )->post_title ) );
	} finally {
		$wpdb->query( 'ROLLBACK' );
		wp_cache_flush();
	}
} )();

( function () use ( $check, $skip, $call, $once, $awkward ) {
	if ( ! function_exists( 'minn_admin_fluent_ready' ) || ! minn_admin_fluent_ready() ) {
		$skip( 'seeded FluentSnippets inactive' );
		return;
	}
	// A draft PHP snippet, so nothing it holds ever runs; its file goes
	// however the section ends.
	list( $st, $made ) = $call( 'POST', '/minn-admin/v1/fluent-snippets', array( 'name' => 'Minn seeded', 'desc' => 'x', 'code' => '// minn suite', 'type' => 'PHP', 'run_at' => 'all', 'active' => false ) );
	$file = is_array( $made ) ? (string) ( $made['id'] ?? $made['file'] ?? '' ) : '';
	if ( 201 !== $st && 200 !== $st || '' === $file ) {
		$check( 'seeded FluentSnippets: a draft snippet is created for the check', false, $st . ' ' . wp_json_encode( $made ) );
		return;
	}
	$clean = $once( function () use ( $call, $file ) {
		$call( 'DELETE', '/minn-admin/v1/fluent-snippets/' . rawurlencode( $file ) );
	} );
	try {
		list( , $meta, $code ) = minn_admin_fluent_load( $file );
		$meta['name']        = $awkward;
		$meta['description'] = "Line one 50%DEAL &amp; é\n\nLine two <b>bold</b>";
		minn_admin_fluent_save( $file, $meta, $code );
		list( , $stored ) = minn_admin_fluent_load( $file );
		if ( (string) ( $stored['name'] ?? '' ) !== $awkward ) {
			$skip( 'seeded FluentSnippets stores names cleaned by its own updater, so an awkward name is not a stored value there' );
			return;
		}
		list( $st, $d ) = $call( 'PUT', '/minn-admin/v1/fluent-snippets/' . rawurlencode( $file ), array( 'name' => $stored['name'], 'desc' => $stored['description'], 'priority' => 15 ) );
		list( , $now ) = minn_admin_fluent_load( $file );
		$check( 'seeded FluentSnippets: a priority-only edit answers 200 and writes the priority', 200 === $st && 15 === (int) ( $now['priority'] ?? 0 ), $st . ' ' . wp_json_encode( $d ) );
		$check( 'seeded FluentSnippets: and keeps the stored name and description', $stored['name'] === ( $now['name'] ?? null ) && $stored['description'] === ( $now['description'] ?? null ), wp_json_encode( array( $now['name'] ?? null, $now['description'] ?? null ) ) );
	} finally {
		$clean();
	}
} )();

printf( "\nsecurity-v046-registry-writes: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
