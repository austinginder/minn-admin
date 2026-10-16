<?php
/**
 * Pre-RC: field plugins' saves keep what nobody changed (ACPT, ACF, JetEngine).
 *
 * The group builders send every setting of every row back on any save, the
 * surface edit dialogs every field, and the editor panels and options pages
 * a whole multi-choice list when one of its boxes changes. Each section
 * seeds a value the vendor's own store holds (a label with markup or a %XX
 * run, an unlabelled group, a choice label holding " : ", a step of 'any',
 * a pick the field no longer offers, a JetEngine map keyed by numbers, a
 * content type with a 'title' field of its own), replays what the client
 * sends for an edit of something else, and checks the stored bytes, with a
 * control that a real edit is still written.
 *
 * Sections SKIP when their plugin is inactive. Everything a section makes
 * (groups, posts, a content type and its table, an options page) is removed
 * however the run ends.
 *
 * Run: wp eval-file tests/security-v046-prerc-fields.test.php --user=admin --path=<site>
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
$draft = function ( $title ) {
	$id = wp_insert_post( array( 'post_title' => $title, 'post_status' => 'draft', 'post_type' => 'post' ), true );
	return is_wp_error( $id ) ? 0 : (int) $id;
};

if ( ! current_user_can( 'manage_options' ) ) {
	echo "SKIP  run with --user=<an administrator>\n";
	return;
}
global $wpdb;
rest_get_server();
$tag = (string) wp_rand( 1000, 9999 );

/*
 * ACPT group builder (POST acpt/schema/groups/{id}/full). The builder sends
 * every setting of every row as the payload served it, and the save cleaned
 * each one again: a label or description ACPT's own canvas stored with
 * markup or a %XX run lost it, a choice whose label is empty took its value
 * as label and a value with edge spaces lost them (and its default flag), an
 * unlabelled group took its name as its label, and a step of 'any' refused
 * the whole save.
 */
( function () use ( $check, $skip, $call, $once, $draft, $tag ) {
	if ( ! function_exists( 'minn_admin_acpt_active' ) || ! minn_admin_acpt_active() || ! class_exists( '\ACPT\Core\CQRS\Command\SaveMetaGroupCommand' ) ) {
		$skip( 'ACPT builder: ACPT inactive' );
		return;
	}
	$name  = 'minnprerc' . $tag;
	$box   = 'minnprerc_box' . $tag;
	$gid   = '';
	$post  = 0;
	$extra = array();
	$clean = $once( function () use ( &$gid, &$post, &$extra, $tag ) {
		global $wpdb;
		if ( $post ) {
			wp_delete_post( $post, true );
		}
		// The create check's post, and any a pre-fix run left by the same title.
		$made = get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'title' => 'Minn prerc ACPT create', 'fields' => 'ids', 'posts_per_page' => 20 ) );
		foreach ( array_unique( array_merge( array_map( 'intval', (array) $extra ), array_map( 'intval', (array) $made ) ) ) as $pid ) {
			if ( $pid > 0 && 'Minn prerc ACPT create' === get_the_title( $pid ) ) {
				wp_delete_post( $pid, true );
			}
		}
		// What the option-page check wrote: options under the run's own page slug.
		foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", '%' . $wpdb->esc_like( 'prerc_acpt_' . $tag ) . '%', '%' . $wpdb->esc_like( 'prerc-acpt-' . $tag ) . '%' ) ) as $opt ) { // phpcs:ignore
			delete_option( $opt );
		}
		if ( '' !== $gid ) {
			try {
				( new \ACPT\Core\CQRS\Command\DeleteMetaGroupCommand( $gid ) )->execute();
			} catch ( \Throwable $e ) {
				echo 'WARN  could not delete the ACPT test group ' . $gid . "\n";
			}
		}
	} );
	$read = function () use ( &$gid ) {
		$g = minn_admin_acpt_builder_group( $gid );
		return $g ? json_decode( wp_json_encode( $g ), true ) : null;
	};
	$field = function ( $g, $fname ) {
		foreach ( (array) ( $g['boxes'][0]['fields'] ?? array() ) as $f ) {
			if ( $fname === ( $f['name'] ?? '' ) ) {
				return $f;
			}
		}
		return null;
	};
	// What the builder posts for a clean copy: every setting of every row as
	// served (app.js rowOf).
	$body_of = function ( $payload ) {
		$row_of = function ( $f ) use ( &$row_of ) {
			$row = isset( $f['key'] ) && '' !== $f['key'] ? array( 'key' => $f['key'] ) : array( 'type' => $f['type'], 'name' => $f['name'] );
			foreach ( array( 'label', 'instructions', 'required', 'default_value', 'placeholder', 'choices', 'min', 'max', 'step', 'rows', 'ui_on_text', 'ui_off_text', 'button_label' ) as $k ) {
				$row[ $k ] = $f[ $k ] ?? null;
			}
			if ( in_array( $f['type'], array( 'box', 'repeater' ), true ) && ! empty( $f['editable'] ) ) {
				$row['sub_fields'] = array_map( $row_of, (array) ( $f['sub_fields'] ?? array() ) );
			}
			return $row;
		};
		return array(
			'stamp'    => $payload['stamp'],
			'title'    => $payload['group']['title'],
			'location' => $payload['group']['location'],
			'fields'   => array_map( $row_of, $payload['fields'] ),
		);
	};
	// Field key => name, from the payload (an existing row is sent by key).
	$names_of = function ( $payload ) {
		$out = array();
		foreach ( (array) ( $payload['fields'][0]['sub_fields'] ?? array() ) as $f ) {
			$out[ $f['key'] ] = $f['name'];
		}
		return $out;
	};
	try {
		$seed = array(
			'name'    => $name,
			'label'   => null,
			'belongs' => array( array( 'belongsTo' => \ACPT\Constants\MetaTypes::CUSTOM_POST_TYPE, 'operator' => '=', 'find' => 'post', 'logic' => '' ) ),
			'boxes'   => array(
				array(
					'name'   => $box,
					'label'  => 'Box <b>one</b> 50%DEAL',
					'fields' => array(
						array( 'name' => 'width', 'label' => 'Width <small>(px)</small>', 'type' => 'Text', 'description' => "Line one\n<b>two</b> 100%Beef", 'isRequired' => false ),
						array(
							'name'    => 'ratio',
							'label'   => 'Ratio',
							'type'    => 'Select',
							'isRequired' => false,
							'options' => array(
								array( 'label' => 'Wide', 'value' => 'wide', 'isDefault' => false ),
								array( 'label' => '', 'value' => 'bare', 'isDefault' => false ),
								array( 'label' => 'Padded', 'value' => ' sp ', 'isDefault' => true ),
							),
						),
						array( 'name' => 'qty', 'label' => 'Qty', 'type' => 'Number', 'isRequired' => false, 'advancedOptions' => array( array( 'key' => 'min', 'value' => '0.50' ) ) ),
						array( 'name' => 'count', 'label' => 'Count', 'type' => 'Number', 'isRequired' => false, 'advancedOptions' => array( array( 'key' => 'min', 'value' => '1' ) ) ),
						array(
							'name'    => 'picks',
							'label'   => 'Picks',
							'type'    => 'Checkbox',
							'isRequired' => false,
							'options' => array(
								array( 'label' => 'A', 'value' => 'a', 'isDefault' => false ),
								array( 'label' => 'Deal', 'value' => '50%DEAL', 'isDefault' => false ),
							),
						),
					),
				),
			),
		);
		$gid = (string) ( new \ACPT\Core\CQRS\Command\SaveMetaGroupCommand( $seed ) )->execute();
		$before = $read();
		$width  = $field( $before, 'width' );
		$ratio  = $field( $before, 'ratio' );
		$check( 'ACPT builder: the test group is seeded through ACPT\'s own save command', $before && $width && $ratio, wp_json_encode( $before ) );
		if ( ! $before || ! $width || ! $ratio ) {
			return;
		}
		$labels = array( wp_json_encode( $before['label'] ?? null ), $before['boxes'][0]['label'] ?? null, $width['label'] ?? null, $width['description'] ?? null, wp_json_encode( wp_list_pluck( (array) $ratio['options'], 'value' ) ) );
		// An unlabelled group reads back with an empty label.
		if ( '' !== (string) ( $before['label'] ?? '' ) || 'Box <b>one</b> 50%DEAL' !== ( $before['boxes'][0]['label'] ?? null ) || 'Width <small>(px)</small>' !== ( $width['label'] ?? null ) ) {
			$skip( 'ACPT builder: ACPT cleans these values on its own save, so they are not stored values there: ' . wp_json_encode( $labels ) );
			return;
		}

		// A save that only ticks "required" on qty.
		list( $st, $payload ) = $call( 'GET', "/minn-admin/v1/acpt/schema/groups/{$gid}/full" );
		$body = $body_of( $payload );
		$nm   = $names_of( $payload );
		foreach ( $body['fields'][0]['sub_fields'] as &$row ) {
			if ( 'qty' === ( $nm[ $row['key'] ] ?? '' ) ) {
				$row['required'] = true;
			}
		}
		unset( $row );
		list( $st, $d ) = $call( 'POST', "/minn-admin/v1/acpt/schema/groups/{$gid}/full", $body );
		$after = $read();
		$check( 'ACPT builder: a save that ticks one required box answers 200 and writes it (control)', 200 === $st && ! empty( $field( $after, 'qty' )['isRequired'] ), $st . ' ' . wp_json_encode( $d ) );
		$check( 'ACPT builder: an unlabelled group stays unlabelled (not its name)', null === ( $after['label'] ?? null ) || '' === ( $after['label'] ?? null ), wp_json_encode( $after['label'] ?? null ) );
		$check( 'ACPT builder: the box label keeps its markup and %XX run', 'Box <b>one</b> 50%DEAL' === ( $after['boxes'][0]['label'] ?? null ), wp_json_encode( $after['boxes'][0]['label'] ?? null ) );
		$aw = $field( $after, 'width' );
		$check( 'ACPT builder: a field label keeps its markup', 'Width <small>(px)</small>' === ( $aw['label'] ?? null ), wp_json_encode( $aw['label'] ?? null ) );
		$check( 'ACPT builder: a field description keeps its markup, line break and %XX run', $width['description'] === ( $aw['description'] ?? null ), wp_json_encode( $aw['description'] ?? null ) );
		$check( 'ACPT builder: a field with no default stays without one', ( $width['defaultValue'] ?? null ) === ( $aw['defaultValue'] ?? null ), wp_json_encode( array( $width['defaultValue'] ?? null, $aw['defaultValue'] ?? null ) ) );
		$opt = function ( $f ) {
			$out = array();
			foreach ( (array) ( $f['options'] ?? array() ) as $o ) {
				$out[] = array( $o['id'] ?? null, $o['label'] ?? null, $o['value'] ?? null, ! empty( $o['isDefault'] ) );
			}
			return $out;
		};
		$check( 'ACPT builder: a choice list keeps its ids, empty label, edge spaces and default', $opt( $ratio ) === $opt( $field( $after, 'ratio' ) ), wp_json_encode( array( $opt( $ratio ), $opt( $field( $after, 'ratio' ) ) ) ) );
		$q0 = $field( $before, 'qty' );
		$q1 = $field( $after, 'qty' );
		$check( 'ACPT builder: the number field keeps its advanced options as stored', ( $q0['advancedOptions'] ?? null ) === ( $q1['advancedOptions'] ?? null ), wp_json_encode( array( $q0['advancedOptions'] ?? null, $q1['advancedOptions'] ?? null ) ) );

		// A step of 'any' (ACPT's canvas takes one) on a save of another field.
		$seed2 = json_decode( wp_json_encode( $after ), true );
		foreach ( $seed2['boxes'][0]['fields'] as &$sf ) {
			if ( 'qty' === $sf['name'] ) {
				$sf['advancedOptions'][] = array( 'key' => 'step', 'value' => 'any' );
			}
		}
		unset( $sf );
		( new \ACPT\Core\CQRS\Command\SaveMetaGroupCommand( $seed2 ) )->execute();
		$any = $read();
		list( , $payload ) = $call( 'GET', "/minn-admin/v1/acpt/schema/groups/{$gid}/full" );
		$body = $body_of( $payload );
		$nm   = $names_of( $payload );
		foreach ( $body['fields'][0]['sub_fields'] as &$row ) {
			if ( 'width' === ( $nm[ $row['key'] ] ?? '' ) ) {
				$row['required'] = true;
			}
		}
		unset( $row );
		list( $st, $d ) = $call( 'POST', "/minn-admin/v1/acpt/schema/groups/{$gid}/full", $body );
		$now = $read();
		$check( "ACPT builder: a stored step of 'any' does not refuse a save of another field", 200 === $st && ! empty( $field( $now, 'width' )['isRequired'] ), $st . ' ' . wp_json_encode( $d ) );
		$check( "ACPT builder: and the step of 'any' stays as stored", ( $field( $any, 'qty' )['advancedOptions'] ?? null ) === ( $field( $now, 'qty' )['advancedOptions'] ?? null ), wp_json_encode( $field( $now, 'qty' )['advancedOptions'] ?? null ) );

		// Controls: real edits are written, and still cleaned.
		list( , $payload ) = $call( 'GET', "/minn-admin/v1/acpt/schema/groups/{$gid}/full" );
		$body          = $body_of( $payload );
		$nm            = $names_of( $payload );
		$body['title'] = 'Prerc <b>group</b>';
		$body['fields'][0]['label'] = 'Box two';
		foreach ( $body['fields'][0]['sub_fields'] as &$row ) {
			$rn = $nm[ $row['key'] ] ?? '';
			if ( 'width' === $rn ) {
				$row['label']        = 'Height <i>cm</i>';
				$row['instructions'] = 'New help';
			} elseif ( 'ratio' === $rn ) {
				$row['choices'] .= "\ntall : Tall";
			} elseif ( 'qty' === $rn ) {
				$row['max'] = '9';
			}
		}
		unset( $row );
		list( $st, $d ) = $call( 'POST', "/minn-admin/v1/acpt/schema/groups/{$gid}/full", $body );
		$ed = $read();
		$adv = array();
		foreach ( (array) ( $field( $ed, 'qty' )['advancedOptions'] ?? array() ) as $o ) {
			$adv[ $o['key'] ] = $o['value'];
		}
		$check( 'ACPT builder: a real title edit is written, cleaned (control)', 200 === $st && 'Prerc group' === ( $ed['label'] ?? null ), $st . ' ' . wp_json_encode( $ed['label'] ?? null ) );
		$check( 'ACPT builder: real box label, field label and help edits are written, cleaned (control)', 'Box two' === ( $ed['boxes'][0]['label'] ?? null ) && 'Height cm' === ( $field( $ed, 'width' )['label'] ?? null ) && 'New help' === ( $field( $ed, 'width' )['description'] ?? null ), wp_json_encode( array( $ed['boxes'][0]['label'] ?? null, $field( $ed, 'width' )['label'] ?? null ) ) );
		$check( 'ACPT builder: a real choice edit is written (control)', in_array( 'tall', wp_list_pluck( (array) ( $field( $ed, 'ratio' )['options'] ?? array() ), 'value' ), true ), wp_json_encode( $field( $ed, 'ratio' )['options'] ?? null ) );
		$check( "ACPT builder: a real max edit is written beside the kept step and min (control)", '9' === ( $adv['max'] ?? null ) && 'any' === ( $adv['step'] ?? null ) && '0.50' === ( $adv['min'] ?? null ), wp_json_encode( $adv ) );

		/*
		 * The ACPT editor panel: a Checkbox list sent when one of its boxes
		 * changes went through sanitize_text_field pick by pick, so an option
		 * value '50%DEAL' became '50AL'. ACPT's own setter refuses a list
		 * holding a value that is not an option, so ticking another box in
		 * such a list saved nothing, while the save answered 200.
		 */
		$picks = $field( $ed, 'picks' );
		$post  = $draft( 'Minn prerc ACPT' );
		if ( ! $picks || ! $post ) {
			$check( 'ACPT panel: a test post and Checkbox field exist', false, wp_json_encode( array( $post, $picks ) ) );
			return;
		}
		$args = array( 'post_id' => $post, 'box_name' => (string) $ed['boxes'][0]['name'], 'field_name' => 'picks' );
		save_acpt_meta_field_value( $args + array( 'value' => array( '50%DEAL' ) ) );
		$stored = get_acpt_field( $args + array( 'format' => 'only_value', 'return' => 'raw' ) );
		if ( array( '50%DEAL' ) !== $stored ) {
			$skip( 'ACPT panel: ACPT does not store the option value 50%DEAL as given: ' . wp_json_encode( $stored ) );
			return;
		}
		// The panel draws a and 50%DEAL with 50%DEAL on; the person ticks a.
		list( $st, $d ) = $call( 'POST', "/wp/v2/posts/{$post}", array( 'minn_acpt' => array( (string) $picks['id'] => array( 'a', '50%DEAL' ) ) ) );
		$now = get_acpt_field( $args + array( 'format' => 'only_value', 'return' => 'raw' ) );
		$check( 'ACPT panel: ticking a box beside an option holding a %XX run is stored', 200 === $st && in_array( 'a', (array) $now, true ), $st . ' ' . wp_json_encode( $now ) );
		$check( "ACPT panel: and the option value stays '50%DEAL'", in_array( '50%DEAL', (array) $now, true ), wp_json_encode( $now ) );
		list( $st ) = $call( 'POST', "/wp/v2/posts/{$post}", array( 'minn_acpt' => array( (string) $picks['id'] => array( 'a' ) ) ) );
		$now = get_acpt_field( $args + array( 'format' => 'only_value', 'return' => 'raw' ) );
		$check( 'ACPT panel: unticking a box removes it (control)', 200 === $st && array( 'a' ) === $now, wp_json_encode( $now ) );

		/*
		 * A value ACPT's setter refuses (a select value that is not one of its
		 * options, a number outside its bounds) was not stored, and the save
		 * answered 200 all the same. The post's answer now names each such
		 * field (minn_field_errors, minn_field_message) and keeps its own
		 * status, since the post itself was saved: an error answer on a new
		 * post's first save hid the new id from the editor, and each retry
		 * and autosave made another draft. The fields beside it are written,
		 * and so are the REST fields registered after Minn's ACPT panel.
		 */
		$ids = array();
		foreach ( (array) $ed['boxes'][0]['fields'] as $f ) {
			$ids[ $f['name'] ] = (string) $f['id'];
		}
		$box_name = (string) $ed['boxes'][0]['name'];
		$raw      = function ( $n, $pid = 0 ) use ( &$post, $box_name ) {
			return get_acpt_field( array( 'post_id' => $pid ? $pid : $post, 'box_name' => $box_name, 'field_name' => $n, 'format' => 'only_value', 'return' => 'raw' ) );
		};
		$refused = function ( $d, $key ) {
			foreach ( (array) ( is_array( $d ) ? ( $d['minn_field_errors'] ?? array() ) : array() ) as $e ) {
				if ( 'minn_acpt' === ( $e['field'] ?? '' ) && $key === ( $e['key'] ?? '' ) ) {
					return $e;
				}
			}
			return null;
		};
		$fmsg = function ( $d ) {
			return is_array( $d ) ? (string) ( $d['minn_field_message'] ?? $d['message'] ?? '' ) : '';
		};

		// A new post's first save: the post is created and its answer says so.
		$made = 0;
		list( $st, $d ) = $call( 'POST', '/wp/v2/posts', array( 'title' => 'Minn prerc ACPT create', 'status' => 'draft', 'minn_acpt' => array( $ids['width'] => 'Created text', $ids['qty'] => '100' ) ) );
		$made = is_array( $d ) && isset( $d['id'] ) && 'Minn prerc ACPT create' === get_the_title( (int) $d['id'] ) ? (int) $d['id'] : 0;
		if ( ! $made && is_array( $d ) && ! isset( $d['id'] ) ) {
			// The pre-fix answer was an error: find the post it created anyway.
			$found = get_posts( array( 'post_type' => 'post', 'post_status' => 'any', 'title' => 'Minn prerc ACPT create', 'fields' => 'ids', 'posts_per_page' => 5 ) );
			$made  = $found ? (int) $found[0] : 0;
		}
		$extra[] = $made;
		$check( 'ACPT create: a refused value on a new post\'s first save answers 201 with the post', 201 === $st && $made > 0, $st . ' ' . wp_json_encode( is_array( $d ) ? ( $d['code'] ?? $d['id'] ?? '' ) : $d ) );
		$e = $refused( $d, $ids['qty'] );
		$check( 'ACPT create: and the answer names the refused field', $e && 'Qty' === ( $e['label'] ?? '' ) && false !== strpos( $fmsg( $d ), '“Qty”' ), wp_json_encode( is_array( $d ) ? array( $d['minn_field_errors'] ?? null, $fmsg( $d ) ) : $d ) );
		$check( 'ACPT create: the field beside it is written and the refused one is not', $made && 'Created text' === $raw( 'width', $made ) && in_array( $raw( 'qty', $made ), array( null, '' ), true ), $made ? wp_json_encode( array( $raw( 'width', $made ), $raw( 'qty', $made ) ) ) : 'no post' );

		$ran = false;
		register_rest_field( 'post', 'minn_prerc_probe', array(
			'update_callback' => function () use ( &$ran ) {
				$ran = true;
			},
			'schema'          => array( 'type' => 'string', 'context' => array( 'edit' ) ),
		) );
		$ratio_was = $raw( 'ratio' );
		list( $st, $d ) = $call( 'POST', "/wp/v2/posts/{$post}", array( 'minn_acpt' => array( $ids['width'] => 'Panel text', $ids['ratio'] => 'nope' ), 'minn_prerc_probe' => 'x' ) );
		$check( 'ACPT panel: a select value ACPT refuses is named in the save\'s answer', 200 === $st && $refused( $d, $ids['ratio'] ) && false !== strpos( $fmsg( $d ), '“Ratio”' ), $st . ' ' . $fmsg( $d ) );
		$check( 'ACPT panel: and the answer says the other changes were saved', false !== strpos( $fmsg( $d ), 'other changes were saved' ), $fmsg( $d ) );
		$check( 'ACPT panel: the field beside it is written', 'Panel text' === $raw( 'width' ), wp_json_encode( $raw( 'width' ) ) );
		$check( 'ACPT panel: the refused field keeps its stored value', $ratio_was === $raw( 'ratio' ), wp_json_encode( $raw( 'ratio' ) ) );
		$check( 'ACPT panel: REST fields registered after the ACPT panel still run in that save', $ran );
		list( $st, $d ) = $call( 'POST', "/wp/v2/posts/{$post}", array( 'minn_acpt' => array( $ids['qty'] => '100' ) ) );
		$check( 'ACPT panel: a number above its max is named', 200 === $st && $refused( $d, $ids['qty'] ) && false !== strpos( $fmsg( $d ), '“Qty”' ) && false === strpos( $fmsg( $d ), 'other changes' ), $st . ' ' . $fmsg( $d ) );
		list( $st, $d ) = $call( 'POST', "/wp/v2/posts/{$post}", array( 'minn_acpt' => array( $ids['qty'] => '100', $ids['ratio'] => 'nope', $ids['width'] => 'Panel text two' ) ) );
		$check( 'ACPT panel: two refused fields are both named, beside a written one', 200 === $st && $refused( $d, $ids['qty'] ) && $refused( $d, $ids['ratio'] ) && false !== strpos( $fmsg( $d ), '“Qty”' ) && false !== strpos( $fmsg( $d ), '“Ratio”' ) && false !== strpos( $fmsg( $d ), 'other changes were saved' ) && 'Panel text two' === $raw( 'width' ), $st . ' ' . $fmsg( $d ) );
		// A never-set number sent 0 under a minimum of 1: ACPT refuses it, and a
		// loose read-back (null == 0) took that for a stored 0.
		list( $st, $d ) = $call( 'POST', "/wp/v2/posts/{$post}", array( 'minn_acpt' => array( $ids['count'] => 0 ) ) );
		$check( 'ACPT panel: a 0 ACPT refuses on a never-set number is named, not taken for stored', $refused( $d, $ids['count'] ) && in_array( $raw( 'count' ), array( null, '' ), true ), $st . ' ' . $fmsg( $d ) . ' ' . wp_json_encode( $raw( 'count' ) ) );
		// A later REST field that answers an error keeps the refusal beside it.
		register_rest_field( 'post', 'minn_prerc_fails', array(
			'update_callback' => function () {
				return new WP_Error( 'minn_prerc_fails', 'Probe field refused.', array( 'status' => 400 ) );
			},
			'schema'          => array( 'type' => 'string', 'context' => array( 'edit' ) ),
		) );
		list( $st, $d ) = $call( 'POST', "/wp/v2/posts/{$post}", array( 'minn_acpt' => array( $ids['ratio'] => 'nope' ), 'minn_prerc_fails' => 'x' ) );
		$check( 'ACPT panel: when a later field answers an error, the refusal is kept in it', 400 === $st && false !== strpos( $fmsg( $d ), 'Probe field refused.' ) && false !== strpos( $fmsg( $d ), '“Ratio”' ), $st . ' ' . $fmsg( $d ) );
		global $wp_rest_additional_fields;
		unset( $wp_rest_additional_fields['post']['minn_prerc_fails'], $wp_rest_additional_fields['post']['minn_prerc_probe'] );
		// A refusal held for one request is never read by another.
		$r1 = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post );
		minn_admin_acpt_held_error( $r1, new WP_Error( 'minn_acpt_refused', 'Held.' ) );
		unset( $r1 );
		$r2 = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $post );
		$check( 'ACPT panel: a refusal held for one request never answers another', empty( minn_admin_acpt_held_error( $r2 ) ) );
		// ACPT's setter also answers false when its own cleaning leaves what is
		// already stored: that is no refusal.
		list( $st, $d ) = $call( 'POST', "/wp/v2/posts/{$post}", array( 'minn_acpt' => array( $ids['width'] => 'Panel text two ' ) ) );
		$check( 'ACPT panel: a value ACPT cleans to what it already holds is no refusal (control)', 200 === $st && ! $refused( $d, $ids['width'] ) && 'Panel text two' === $raw( 'width' ), $st . ' ' . $fmsg( $d ) );
		list( $st, $d ) = $call( 'POST', "/wp/v2/posts/{$post}", array( 'minn_acpt' => array( $ids['ratio'] => 'tall', $ids['qty'] => '7' ) ) );
		$check( 'ACPT panel: values ACPT accepts are written with no refusal (control)', 200 === $st && empty( $d['minn_field_errors'] ) && 'tall' === $raw( 'ratio' ) && 7 === (int) $raw( 'qty' ), $st . ' ' . wp_json_encode( array( $raw( 'ratio' ), $raw( 'qty' ) ) ) );

		// Option pages write through the same writer (listing one needs an
		// ACPT licence, so drive it with an option-page context).
		$model = null;
		foreach ( \ACPT\Core\Repository\MetaRepository::get( array( 'id' => $gid ) )[0]->getBoxes() as $b ) {
			foreach ( $b->getFields() as $f ) {
				if ( 'ratio' === $f->getName() ) {
					$model = $f;
				}
			}
		}
		$opage = 'minn-prerc-acpt-' . $tag;
		$res   = $model ? minn_admin_acpt_write_one( $model, 'nope', array( 'option_page' => $opage, 'box_name' => (string) $ed['boxes'][0]['name'], 'field_name' => 'ratio' ) ) : null;
		$check( 'ACPT options: the shared writer answers an error naming a field ACPT refused', is_wp_error( $res ) && false !== strpos( $res->get_error_message(), '“Ratio”' ), is_wp_error( $res ) ? $res->get_error_message() : wp_json_encode( $res ) );
		$res = $model ? minn_admin_acpt_write_one( $model, 'wide', array( 'option_page' => $opage, 'box_name' => (string) $ed['boxes'][0]['name'], 'field_name' => 'ratio' ) ) : 'no model';
		$check( 'ACPT options: and a value it accepts writes with no error (control)', null === $res, is_wp_error( $res ) ? $res->get_error_message() : wp_json_encode( $res ) );
	} finally {
		$clean();
	}
} )();

/*
 * ACF field PUT (acf/schema/fields/{key}) and group builder (POST
 * acf/schema/groups/{key}/full). Both re-parsed every field's choices from
 * their "value : Label" text, so a label holding " : " lost its tail, and
 * the builder cast min / max / step to a number, so a step of 'any' became 0
 * and a min '0.5' a float, on a save that changed something else.
 */
( function () use ( $check, $skip, $call, $once, $tag ) {
	if ( ! function_exists( 'minn_admin_acf_schema_active' ) || ! minn_admin_acf_schema_active() || ! function_exists( 'acf_update_field_group' ) ) {
		$skip( 'ACF field groups: ACF inactive' );
		return;
	}
	$gkey  = 'group_minnprerc' . $tag;
	$gid   = 0;
	$clean = $once( function () use ( &$gid ) {
		if ( $gid && 'acf-field-group' === get_post_type( $gid ) ) {
			acf_delete_field_group( $gid );
		}
	} );
	try {
		$g   = acf_update_field_group( array(
			'key'      => $gkey,
			'title'    => 'Minn prerc fields',
			'active'   => false,
			'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ),
		) );
		$gid = is_array( $g ) ? (int) ( $g['ID'] ?? 0 ) : 0;
		if ( ! $gid ) {
			$check( 'ACF field groups: a test group is created', false );
			return;
		}
		$k = function ( $n ) use ( $tag ) {
			return 'field_minnprerc' . $tag . '_' . $n;
		};
		acf_update_field( array( 'key' => $k( 'ratio' ), 'label' => 'Ratio', 'name' => 'minnprerc_ratio', 'type' => 'select', 'parent' => $gid, 'menu_order' => 0, 'choices' => array( 'wide' => 'Wide : 16 : 9', 'tall' => 'Tall' ) ) );
		acf_update_field( array( 'key' => $k( 'qty' ), 'label' => 'Qty', 'name' => 'minnprerc_qty', 'type' => 'number', 'parent' => $gid, 'menu_order' => 1, 'min' => '0.5', 'step' => 'any' ) );
		acf_update_field( array( 'key' => $k( 'note' ), 'label' => 'Note', 'name' => 'minnprerc_note', 'type' => 'text', 'parent' => $gid, 'menu_order' => 2 ) );
		$ratio = acf_get_field( $k( 'ratio' ) );
		$qty   = acf_get_field( $k( 'qty' ) );
		if ( array( 'wide' => 'Wide : 16 : 9', 'tall' => 'Tall' ) !== ( $ratio['choices'] ?? null ) || 'any' !== ( $qty['step'] ?? null ) ) {
			$skip( 'ACF field groups: ACF cleans these settings on its own save: ' . wp_json_encode( array( $ratio['choices'] ?? null, $qty['step'] ?? null ) ) );
			return;
		}

		// The Fields view's edit dialog: every field as the fresh item serves
		// it, "Required" switched to Yes.
		list( , $list ) = $call( 'GET', "/minn-admin/v1/acf/schema/groups/{$gkey}/fields" );
		$item = null;
		foreach ( (array) ( $list['items'] ?? array() ) as $it ) {
			if ( $k( 'ratio' ) === $it['id'] ) {
				$item = $it;
			}
		}
		$check( 'ACF field PUT: the field lists with its choices text', $item && "wide : Wide : 16 : 9\ntall : Tall" === $item['choices'], wp_json_encode( $item ) );
		list( $st, $d ) = $call( 'PUT', '/minn-admin/v1/acf/schema/fields/' . $k( 'ratio' ), array( 'label' => $item['label'] ?? '', 'default_value' => $item['default_value'] ?? '', 'choices' => $item['choices'] ?? '', 'required' => 'Yes' ) );
		$now = acf_get_field( $k( 'ratio' ) );
		$check( 'ACF field PUT: a Required-only edit answers 200 and writes it (control)', 200 === $st && ! empty( $now['required'] ), $st . ' ' . wp_json_encode( $d ) );
		$check( "ACF field PUT: and keeps a choice label holding ' : ' whole", $ratio['choices'] === ( $now['choices'] ?? null ), wp_json_encode( $now['choices'] ?? null ) );
		$check( 'ACF field PUT: and keeps the default as stored', ( $ratio['default_value'] ?? null ) === ( $now['default_value'] ?? null ), wp_json_encode( array( $ratio['default_value'] ?? null, $now['default_value'] ?? null ) ) );
		list( $st ) = $call( 'PUT', '/minn-admin/v1/acf/schema/fields/' . $k( 'ratio' ), array( 'label' => 'Ratio', 'default_value' => '', 'choices' => "wide : Wide\ntall : Tall", 'required' => 'Yes' ) );
		$now = acf_get_field( $k( 'ratio' ) );
		$check( 'ACF field PUT: a real choices edit is written (control)', 200 === $st && array( 'wide' => 'Wide', 'tall' => 'Tall' ) === ( $now['choices'] ?? null ), wp_json_encode( $now['choices'] ?? null ) );
		acf_update_field( array_merge( $now, array( 'choices' => array( 'wide' => 'Wide : 16 : 9', 'tall' => 'Tall' ) ) ) );
		$ratio = acf_get_field( $k( 'ratio' ) );

		// The group builder: every row as served, one label edited.
		clean_post_cache( $gid );
		$settings = get_post_field( 'post_content', $gid, 'raw' );
		list( , $payload ) = $call( 'GET', "/minn-admin/v1/acf/schema/groups/{$gkey}/full" );
		$rows = array();
		foreach ( (array) ( $payload['fields'] ?? array() ) as $f ) {
			$row = array( 'key' => $f['key'] );
			foreach ( array( 'label', 'instructions', 'required', 'default_value', 'placeholder', 'choices', 'min', 'max', 'step', 'rows', 'ui_on_text', 'ui_off_text', 'button_label' ) as $s ) {
				$row[ $s ] = $f[ $s ] ?? null;
			}
			if ( $k( 'note' ) === $f['key'] ) {
				$row['label'] = 'Note edited';
			}
			$rows[] = $row;
		}
		$body = array( 'stamp' => $payload['stamp'] ?? '', 'title' => $payload['group']['title'] ?? '', 'location' => $payload['group']['location'] ?? array(), 'fields' => $rows, 'active' => false );
		list( $st, $d ) = $call( 'POST', "/minn-admin/v1/acf/schema/groups/{$gkey}/full", $body );
		$check( 'ACF builder: a save of one label answers 200 and writes it (control)', 200 === $st && 'Note edited' === ( acf_get_field( $k( 'note' ) )['label'] ?? null ), $st . ' ' . wp_json_encode( is_array( $d ) ? ( $d['message'] ?? $d['code'] ?? '' ) : $d ) );
		clean_post_cache( $gid );
		$check( 'ACF builder: and the group\'s stored settings stay byte for byte (no Minn key added)', $settings === get_post_field( 'post_content', $gid, 'raw' ), wp_json_encode( array( $settings, get_post_field( 'post_content', $gid, 'raw' ) ) ) );
		$r1 = acf_get_field( $k( 'ratio' ) );
		$q1 = acf_get_field( $k( 'qty' ) );
		$check( "ACF builder: an untouched choice label holding ' : ' stays whole", $ratio['choices'] === ( $r1['choices'] ?? null ), wp_json_encode( $r1['choices'] ?? null ) );
		$check( "ACF builder: an untouched step of 'any' stays 'any'", 'any' === ( $q1['step'] ?? null ), wp_json_encode( $q1['step'] ?? null ) );
		$check( "ACF builder: an untouched min '0.5' stays the stored string", $qty['min'] === ( $q1['min'] ?? null ), wp_json_encode( array( $qty['min'] ?? null, $q1['min'] ?? null ) ) );
		foreach ( $body['fields'] as &$row ) {
			if ( $k( 'qty' ) === $row['key'] ) {
				$row['max'] = '12';
			}
		}
		unset( $row );
		list( , $payload ) = $call( 'GET', "/minn-admin/v1/acf/schema/groups/{$gkey}/full" );
		$body['stamp'] = $payload['stamp'] ?? '';
		list( $st ) = $call( 'POST', "/minn-admin/v1/acf/schema/groups/{$gkey}/full", $body );
		$q2 = acf_get_field( $k( 'qty' ) );
		$check( 'ACF builder: a real max edit is written beside the kept step (control)', 200 === $st && 12.0 === (float) ( $q2['max'] ?? 0 ) && 'any' === ( $q2['step'] ?? null ), wp_json_encode( array( $q2['max'] ?? null, $q2['step'] ?? null ) ) );
	} finally {
		$clean();
	}
} )();

/*
 * The ACF editor panel (minn_acf) and ACF options pages. The panel draws a
 * switch for each of a checkbox field's current choices only and sends the
 * ticked ones when one changes, so a stored pick the field no longer offers
 * (a choice removed since, an "Allow Custom" entry) was dropped. A repeater
 * row comes back whole, so there it was dropped from a row nobody touched.
 */
( function () use ( $check, $skip, $call, $once, $draft, $tag ) {
	if ( ! function_exists( 'minn_admin_acf_active' ) || ! minn_admin_acf_active() || ! function_exists( 'acf_add_local_field_group' ) ) {
		$skip( 'ACF panel: ACF inactive' );
		return;
	}
	$k    = function ( $n ) use ( $tag ) {
		return 'field_minnprercp' . $tag . '_' . $n;
	};
	$pick = array( 'key' => $k( 'picks' ), 'label' => 'Picks', 'name' => 'minnprercp_picks', 'type' => 'checkbox', 'choices' => array( 'a' => 'A', 'b' => 'B', 'c' => 'C' ) );
	$rep  = function_exists( 'acf_get_field_type' ) && acf_get_field_type( 'repeater' );
	$fields = array( $pick );
	if ( $rep ) {
		$fields[] = array(
			'key'        => $k( 'rows' ),
			'label'      => 'Rows',
			'name'       => 'minnprercp_rows',
			'type'       => 'repeater',
			'sub_fields' => array(
				array( 'key' => $k( 'rows_picks' ), 'label' => 'Row picks', 'name' => 'picks', 'type' => 'checkbox', 'choices' => array( 'a' => 'A', 'b' => 'B' ) ),
				array( 'key' => $k( 'rows_note' ), 'label' => 'Row note', 'name' => 'note', 'type' => 'text' ),
			),
		);
	}
	// Local groups: registered for this process only, nothing to remove.
	acf_add_local_field_group( array(
		'key'      => 'group_minnprercp' . $tag,
		'title'    => 'Minn prerc panel',
		'fields'   => $fields,
		'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ),
	) );
	$post  = $draft( 'Minn prerc ACF' );
	$slug  = 'minn-prerc-opts-' . $tag;
	$opid  = 'minn_prerc_opts_' . $tag;
	$clean = $once( function () use ( &$post, $opid ) {
		if ( $post ) {
			wp_delete_post( $post, true );
		}
		foreach ( array( '', '_' ) as $pre ) {
			delete_option( $pre . $opid . '_minnprercp_opick' );
		}
	} );
	try {
		if ( ! $post ) {
			$check( 'ACF panel: a test post is created', false );
			return;
		}
		update_field( $k( 'picks' ), array( 'a', 'old' ), $post );
		$stored = get_field( $k( 'picks' ), $post, false );
		$check( 'ACF panel: the off-list pick is stored through ACF', array( 'a', 'old' ) === $stored, wp_json_encode( $stored ) );
		// The panel draws a, b and c with a on; the person ticks b.
		list( $st, $d ) = $call( 'POST', "/wp/v2/posts/{$post}", array( 'minn_acf' => array( 'minnprercp_picks' => array( 'a', 'b' ) ) ) );
		$now = get_field( $k( 'picks' ), $post, false );
		$check( 'ACF panel: ticking a box answers 200 and stores it (control)', 200 === $st && in_array( 'b', (array) $now, true ), $st . ' ' . wp_json_encode( $now ) );
		$check( 'ACF panel: and keeps the stored pick the field no longer offers, where it was', array( 'a', 'old', 'b' ) === $now, wp_json_encode( $now ) );
		list( $st ) = $call( 'POST', "/wp/v2/posts/{$post}", array( 'minn_acf' => array( 'minnprercp_picks' => array( 'b' ) ) ) );
		$now = get_field( $k( 'picks' ), $post, false );
		$check( 'ACF panel: unticking an offered box removes it (control)', 200 === $st && ! in_array( 'a', (array) $now, true ) && in_array( 'b', (array) $now, true ), wp_json_encode( $now ) );

		if ( $rep ) {
			update_field( $k( 'rows' ), array( array( $k( 'rows_picks' ) => array( 'old', 'a' ), $k( 'rows_note' ) => 'x' ) ), $post );
			$rows0 = get_field( $k( 'rows' ), $post, false );
			// A row comes back whole when its note changes; its picks as drawn.
			list( $st ) = $call( 'POST', "/wp/v2/posts/{$post}", array( 'minn_acf' => array( 'minnprercp_rows' => array( array( '__idx' => 0, 'values' => array( 'picks' => array( 'a' ), 'note' => 'y' ) ) ) ) ) );
			$rows1 = get_field( $k( 'rows' ), $post, false );
			$check( 'ACF panel: a repeater row edit answers 200 and writes the note (control)', 200 === $st && 'y' === ( $rows1[0][ $k( 'rows_note' ) ] ?? null ), $st . ' ' . wp_json_encode( $rows1 ) );
			$check( 'ACF panel: and the row\'s untouched picks keep their off-list pick, as stored', ( $rows0[0][ $k( 'rows_picks' ) ] ?? null ) === ( $rows1[0][ $k( 'rows_picks' ) ] ?? null ), wp_json_encode( array( $rows0[0][ $k( 'rows_picks' ) ] ?? null, $rows1[0][ $k( 'rows_picks' ) ] ?? null ) ) );
		} else {
			$skip( 'ACF panel: repeater rows need ACF Pro' );
		}

		// An options page, the same list.
		if ( ! function_exists( 'acf_add_options_page' ) || ! function_exists( 'minn_admin_acf_options_active' ) || ! minn_admin_acf_options_active() ) {
			$skip( 'ACF options: options pages need ACF Pro' );
			return;
		}
		acf_add_options_page( array( 'page_title' => 'Minn prerc options', 'menu_title' => 'Minn prerc options', 'menu_slug' => $slug, 'post_id' => $opid, 'capability' => 'manage_options', 'redirect' => false ) );
		acf_add_local_field_group( array(
			'key'      => 'group_minnprerco' . $tag,
			'title'    => 'Minn prerc options',
			'fields'   => array( array( 'key' => $k( 'opick' ), 'label' => 'Option picks', 'name' => 'minnprercp_opick', 'type' => 'checkbox', 'choices' => array( 'x' => 'X', 'y' => 'Y' ) ) ),
			'location' => array( array( array( 'param' => 'options_page', 'operator' => '==', 'value' => $slug ) ) ),
		) );
		update_field( $k( 'opick' ), array( 'gone', 'x' ), $opid );
		list( $st, $d ) = $call( 'POST', '/minn-admin/v1/acf/options/' . rawurlencode( $slug ) . '/tab-0', array( 'values' => array( $k( 'opick' ) => array( 'x', 'y' ) ) ) );
		$now = get_field( $k( 'opick' ), $opid, false );
		$check( 'ACF options: ticking a box answers 200 and stores it (control)', 200 === $st && in_array( 'y', (array) $now, true ), $st . ' ' . wp_json_encode( $now ) . ' ' . wp_json_encode( is_array( $d ) ? ( $d['code'] ?? '' ) : '' ) );
		$check( 'ACF options: and keeps the stored pick the field no longer offers, where it was', array( 'gone', 'x', 'y' ) === $now, wp_json_encode( $now ) );
	} finally {
		$clean();
	}
} )();

/*
 * JetEngine: the editor panel (minn_jet), an options page and a content type
 * item. A checkbox list sent when one of its boxes changes kept only the
 * field's current options, so a stored pick the field no longer offers was
 * dropped. A key => 'true' map whose keys are numbers ('1', '2') read as a
 * list of the values 'true' / 'false', so nothing showed as ticked and the
 * next tick cleared the rest. A "save as array" checkbox on an options page
 * stored an empty list whatever was ticked: JetEngine's own sanitizer there
 * expects key => 'true'. A content type with a 'title' field of its own was
 * served the display fallback (the first non-empty text field, or '#id') in
 * that key, and its edit dialog wrote it back as the title.
 */
( function () use ( $check, $skip, $call, $once, $draft, $tag, $wpdb ) {
	if ( ! function_exists( 'minn_admin_jet_fields_active' ) || ! minn_admin_jet_fields_active() ) {
		$skip( 'JetEngine inactive' );
		return;
	}

	// The panel, on the lab's own meta box field when it is there.
	$box_field = null;
	foreach ( (array) jet_engine()->meta_boxes->data->get_items() as $b ) {
		if ( 'post' !== ( $b['args']['object_type'] ?? 'post' ) || ! in_array( 'post', (array) ( $b['args']['allowed_post_type'] ?? array() ), true ) ) {
			continue;
		}
		foreach ( (array) ( $b['meta_fields'] ?? array() ) as $fd ) {
			if ( 'checkbox' === ( $fd['type'] ?? '' ) && empty( $fd['is_array'] ) && empty( $fd['allow_custom'] ) && count( (array) ( $fd['options'] ?? array() ) ) >= 2 && ! $box_field ) {
				$box_field = $fd;
			}
		}
	}
	$post  = 0;
	$clean = $once( function () use ( &$post ) {
		if ( $post ) {
			wp_delete_post( $post, true );
		}
	} );
	try {
		$post = $box_field ? $draft( 'Minn prerc Jet' ) : 0;
		if ( ! $box_field || ! $post ) {
			$skip( 'JetEngine panel: no post meta box with a checkbox field on posts' );
		} else {
			$keys = array_map( function ( $o ) {
				return (string) $o['key'];
			}, array_values( (array) $box_field['options'] ) );
			$name = $box_field['name'];
			update_post_meta( $post, $name, array( $keys[0] => 'true', $keys[1] => 'false', 'gone' => 'true' ) );
			// The panel draws the offered keys with the first on; the person ticks the second.
			list( $st, $d ) = $call( 'POST', "/wp/v2/posts/{$post}", array( 'minn_jet' => array( $name => array( $keys[0], $keys[1] ) ) ) );
			$now = get_post_meta( $post, $name, true );
			$check( 'JetEngine panel: ticking a box answers 200 and stores it (control)', 200 === $st && 'true' === ( $now[ $keys[1] ] ?? null ), $st . ' ' . wp_json_encode( $now ) );
			$check( 'JetEngine panel: and keeps the stored key the field no longer offers', 'true' === ( $now['gone'] ?? null ), wp_json_encode( $now ) );
		}
	} finally {
		$clean();
	}

	// An options page made for this run: a map checkbox and a "save as array" one.
	if ( ! function_exists( 'minn_admin_jet_options_active' ) || ! minn_admin_jet_options_active() || ! method_exists( jet_engine()->options_pages, 'register_new_options_page' ) ) {
		$skip( 'JetEngine options pages inactive' );
	} else {
		$slug  = 'minn-prerc-opts-' . $tag;
		$clean = $once( function () use ( $slug ) {
			delete_option( $slug );
			unset( jet_engine()->options_pages->registered_pages[ $slug ] );
		} );
		try {
			jet_engine()->options_pages->register_new_options_page( array(
				'slug'            => $slug,
				'labels'          => array( 'name' => 'Minn prerc', 'menu_name' => 'Minn prerc' ),
				'capability'      => 'manage_options',
				'storage_type'    => 'default',
				'autoload_option' => false,
				'position'        => null,
				'fields'          => array(
					array( 'title' => 'Channels', 'name' => 'chan', 'object_type' => 'field', 'type' => 'checkbox', 'options' => array( array( 'key' => 'email', 'value' => 'Email' ), array( 'key' => 'sms', 'value' => 'SMS' ) ) ),
					array( 'title' => 'Days', 'name' => 'days', 'object_type' => 'field', 'type' => 'checkbox', 'is_array' => true, 'options' => array( array( 'key' => 'mon', 'value' => 'Mon' ), array( 'key' => 'tue', 'value' => 'Tue' ) ) ),
					array( 'title' => 'Note', 'name' => 'note', 'object_type' => 'field', 'type' => 'text' ),
				),
			) );
			update_option( $slug, array( 'chan' => array( 'email' => 'true', 'sms' => 'false', 'fax' => 'true' ), 'days' => array( 'mon', 'sun' ), 'note' => '50%DEAL <b>x</b>' ) );
			jet_engine()->options_pages->registered_pages[ $slug ]->options = null;
			$route = '/minn-admin/v1/jet-engine/options/' . rawurlencode( $slug ) . '/tab-0';
			list( $st, $d ) = $call( 'GET', $route );
			$check( 'JetEngine options: the run\'s page serves its checkboxes', 200 === $st && array( 'email' ) === ( $d['values']['chan'] ?? null ) && array( 'mon' ) === ( $d['values']['days'] ?? null ), $st . ' ' . wp_json_encode( $d['values'] ?? $d ) );
			// The settings form sends only the changed key.
			list( $st, $d ) = $call( 'POST', $route, array( 'values' => array( 'days' => array( 'mon', 'tue' ) ) ) );
			$now = get_option( $slug );
			$check( 'JetEngine options: ticking a "save as array" box stores the ticked keys, not an empty list', 200 === $st && in_array( 'mon', (array) ( $now['days'] ?? array() ), true ) && in_array( 'tue', (array) ( $now['days'] ?? array() ), true ), $st . ' ' . wp_json_encode( $now['days'] ?? null ) );
			$check( 'JetEngine options: and keeps the stored pick the field no longer offers, where it was', array( 'mon', 'sun', 'tue' ) === ( $now['days'] ?? null ), wp_json_encode( $now['days'] ?? null ) );
			list( $st ) = $call( 'POST', $route, array( 'values' => array( 'chan' => array( 'email', 'sms' ) ) ) );
			$now = get_option( $slug );
			$check( 'JetEngine options: ticking a map box keeps the stored key the field no longer offers', 200 === $st && 'true' === ( $now['chan']['sms'] ?? null ) && 'true' === ( $now['chan']['fax'] ?? null ), wp_json_encode( $now['chan'] ?? null ) );
			$check( 'JetEngine options: and leaves the untouched note byte for byte (control)', '50%DEAL <b>x</b>' === ( $now['note'] ?? null ), wp_json_encode( $now['note'] ?? null ) );
		} finally {
			$clean();
		}
	}

	// A content type made for this run, with a 'title' field of its own.
	if ( ! function_exists( 'minn_admin_jet_cct_active' ) || ! minn_admin_jet_cct_active() || ! class_exists( '\Jet_Engine\Modules\Custom_Content_Types\Factory' ) ) {
		$skip( 'JetEngine content types inactive' );
		return;
	}
	$slug    = 'minnprerc' . $tag;
	$factory = null;
	$clean   = $once( function () use ( &$factory, $slug, $wpdb ) {
		if ( $factory && $wpdb->prefix . 'jet_cct_' . $slug === $factory->db->table() ) {
			$factory->db->drop_table();
		}
	} );
	try {
		$opts    = function ( $pairs ) {
			$out = array();
			foreach ( $pairs as $key => $label ) {
				$out[] = array( 'key' => (string) $key, 'value' => $label );
			}
			return $out;
		};
		$factory = new \Jet_Engine\Modules\Custom_Content_Types\Factory(
			array( 'slug' => $slug, 'name' => 'Minn prerc', 'capability' => 'manage_options' ),
			array(
				array( 'title' => 'Title', 'name' => 'title', 'object_type' => 'field', 'type' => 'text' ),
				array( 'title' => 'Name', 'name' => 'name', 'object_type' => 'field', 'type' => 'text' ),
				array( 'title' => 'Sizes', 'name' => 'sizes', 'object_type' => 'field', 'type' => 'checkbox', 'options' => $opts( array( 1 => 'Small', 2 => 'Large' ) ) ),
				array( 'title' => 'Tags', 'name' => 'tags', 'object_type' => 'field', 'type' => 'checkbox', 'is_array' => true, 'options' => $opts( array( 'a' => 'A', 'b' => 'B' ) ) ),
			)
		);
		$factory->db->create_table();
		\Jet_Engine\Modules\Custom_Content_Types\Module::instance()->manager->register_instance( $slug, $factory );
		$table = $wpdb->prefix . 'jet_cct_' . $slug;
		$wpdb->insert( $table, array(
			'title'      => '',
			'name'       => 'Ann',
			'sizes'      => maybe_serialize( array( 1 => 'true', 2 => 'false', 9 => 'true' ) ),
			'tags'       => maybe_serialize( array( 'a', 'zz' ) ),
			'cct_status' => 'publish',
		) );
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			$check( 'JetEngine CCT: a test item is stored', false, $wpdb->last_error );
			return;
		}
		$base = '/minn-admin/v1/jet-cct/' . $slug . '/items/' . $id;
		$row  = function () use ( $wpdb, $table, $id ) {
			return $wpdb->get_row( $wpdb->prepare( "SELECT title, name, sizes, tags FROM {$table} WHERE _ID = %d", $id ), ARRAY_A ); // phpcs:ignore
		};
		$was = $row();
		list( $st, $item ) = $call( 'GET', $base );
		$check( 'JetEngine CCT: the item serves its own empty title, not a display fallback', 200 === $st && '' === ( $item['title'] ?? null ), $st . ' ' . wp_json_encode( $item['title'] ?? $item ) );
		$check( "JetEngine CCT: a key => 'true' map keyed by numbers reads as its ticked keys", array( '1' ) === ( $item['sizes'] ?? null ), wp_json_encode( $item['sizes'] ?? null ) );
		// The edit dialog sends every field as the item serves it; the name changes.
		$send = array( 'title' => $item['title'] ?? '', 'name' => 'Bob', 'sizes' => $item['sizes'] ?? array(), 'tags' => $item['tags'] ?? array() );
		list( $st ) = $call( 'POST', $base, $send );
		$now = $row();
		$check( 'JetEngine CCT: a name edit answers 200 and writes it (control)', 200 === $st && 'Bob' === $now['name'], $st . ' ' . wp_json_encode( $now ) );
		$check( 'JetEngine CCT: and the empty title stays empty', $was['title'] === $now['title'], wp_json_encode( $now['title'] ) );
		$check( 'JetEngine CCT: and the untouched checkboxes stay byte for byte', $was['sizes'] === $now['sizes'] && $was['tags'] === $now['tags'], wp_json_encode( array( $now['sizes'], $now['tags'] ) ) );
		// Ticking Large: what the dialog then sends for that list.
		list( , $item ) = $call( 'GET', $base );
		list( $st ) = $call( 'POST', $base, array( 'title' => $item['title'] ?? '', 'name' => $item['name'] ?? '', 'sizes' => array_values( array_unique( array_merge( (array) ( $item['sizes'] ?? array() ), array( '2' ) ) ) ), 'tags' => $item['tags'] ?? array() ) );
		$sizes = Minn_Admin::decode_serialized( (string) $row()['sizes'], array() );
		$check( 'JetEngine CCT: ticking a box keeps the boxes already ticked and the key the field no longer offers', 200 === $st && 'true' === ( $sizes[1] ?? null ) && 'true' === ( $sizes[2] ?? null ) && 'true' === ( $sizes[9] ?? null ), wp_json_encode( $sizes ) );
		list( , $item ) = $call( 'GET', $base );
		list( $st ) = $call( 'POST', $base, array( 'title' => $item['title'] ?? '', 'name' => $item['name'] ?? '', 'sizes' => $item['sizes'] ?? array(), 'tags' => array( 'a', 'b' ) ) );
		$tags = Minn_Admin::decode_serialized( (string) $row()['tags'], array() );
		$check( 'JetEngine CCT: ticking a "save as array" box keeps the stored pick the field no longer offers, where it was', 200 === $st && array( 'a', 'zz', 'b' ) === $tags, wp_json_encode( $tags ) );
		list( , $item ) = $call( 'GET', $base );
		list( $st ) = $call( 'POST', $base, array( 'title' => 'Real title', 'name' => $item['name'] ?? '', 'sizes' => $item['sizes'] ?? array(), 'tags' => $item['tags'] ?? array() ) );
		$check( 'JetEngine CCT: a real title edit is written (control)', 200 === $st && 'Real title' === $row()['title'], wp_json_encode( $row()['title'] ) );
	} finally {
		$clean();
	}
} )();

printf( "\nsecurity-v046-prerc-fields: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
