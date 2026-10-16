<?php
/**
 * Pre-RC regressions for the forms and mail adapters: a save that rewrote a
 * value the person did not change.
 *
 * Each section seeds a value in the vendor's own store that the old save
 * path changed (markup and '50%DEAL' in a label or name, a key an add-on
 * keeps, a numeric id, edge spaces, a page since deleted, a part the form no
 * longer shows, a quote), replays what Minn's client sends for an edit that
 * leaves that value alone, and checks the stored bytes. A control in each
 * proves a real edit is still written. Sections SKIP when their plugin is
 * inactive, and every seed is removed or put back however the run ends.
 *
 * Run: wp eval-file tests/security-v046-prerc-forms.test.php --user=admin --path=<site>
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
// A page id that no longer exists: one made and deleted for the check.
$gone_page = function () {
	$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Minn prerc gone page' ), true );
	if ( is_wp_error( $id ) || ! $id ) {
		return 0;
	}
	wp_delete_post( (int) $id, true );
	return get_post( (int) $id ) ? 0 : (int) $id;
};

if ( ! current_user_can( 'manage_options' ) ) {
	echo "SKIP  run with --user=<an administrator>\n";
	return;
}
global $wpdb;
rest_get_server();

/*
 * Gravity Forms builder. The page sends every part of a Name field when any
 * part changes, and every choice (text, value, selected) when any choice
 * changes. The save ran each part's sub-label through sanitize_text_field
 * (Gravity Forms keeps markup there) and rebuilt every choice from the three
 * keys the page edits, so the keys an add-on keeps on a choice went and the
 * price became ''. Gravity Forms' own editor keeps both.
 */
( function () use ( $check, $skip, $call, $once, $wpdb ) {
	if ( ! function_exists( 'minn_admin_gfb_available' ) || ! minn_admin_gfb_available() || ! GFCommon::current_user_can_any( array( 'gravityforms_edit_forms', 'gform_full_access' ) ) ) {
		$skip( 'GF builder: Gravity Forms (with its editor save) inactive' );
		return;
	}
	$form_id = 0;
	$clean   = $once( function () use ( &$form_id ) {
		if ( $form_id ) {
			GFAPI::delete_form( $form_id );
		}
	} );
	// What the page's gfbRowOut() sends for one field of the builder's read.
	$row_out = function ( $f ) {
		$row = array( 'id' => $f['id'] );
		foreach ( (array) $f['settings'] as $k ) {
			if ( 'subInputs' === $k ) {
				$row['subInputs'] = array_map( function ( $i ) {
					return array( 'id' => $i['id'], 'isHidden' => ! empty( $i['isHidden'] ), 'customLabel' => (string) ( $i['customLabel'] ?? '' ) );
				}, (array) $f['inputs'] );
			} elseif ( 'choices' === $k ) {
				$row['choices']           = array_map( function ( $c ) {
					return array( 'text' => $c['text'], 'value' => $c['value'], 'isSelected' => ! empty( $c['isSelected'] ) );
				}, (array) $f['choices'] );
				$row['enableChoiceValue'] = ! empty( $f['enableChoiceValue'] );
			} elseif ( array_key_exists( $k, $f ) ) {
				$row[ $k ] = $f[ $k ];
			}
		}
		return $row;
	};
	// One builder save: read the page's payload, let $edit change the rows
	// (keyed by field id), send them all as the page does.
	$save = function ( callable $edit, $extra = array() ) use ( $call, $row_out, &$form_id ) {
		list( , $p ) = $call( 'GET', "/minn-admin/v1/gf/forms/{$form_id}/builder" );
		$rows = array();
		foreach ( (array) ( $p['fields'] ?? array() ) as $f ) {
			$rows[ (int) $f['id'] ] = $row_out( $f );
		}
		$rows = $edit( $rows );
		return $call( 'POST', "/minn-admin/v1/gf/forms/{$form_id}/builder", array_merge( array(
			'stamp'  => (string) ( $p['stamp'] ?? '' ),
			'known'  => array_map( 'intval', array_keys( $rows ) ),
			'fields' => array_values( $rows ),
		), $extra ) );
	};
	// The field as stored, straight from their meta table.
	$stored = function ( $fid ) use ( $wpdb, &$form_id ) {
		$meta = json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT display_meta FROM ' . GFFormsModel::get_meta_table_name() . ' WHERE form_id = %d', $form_id ) ), true ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( (array) ( $meta['fields'] ?? array() ) as $f ) {
			if ( (int) ( $f['id'] ?? 0 ) === (int) $fid ) {
				return $f;
			}
		}
		return array();
	};
	$input = function ( $field, $id ) {
		foreach ( (array) ( $field['inputs'] ?? array() ) as $in ) {
			if ( (string) ( $in['id'] ?? '' ) === (string) $id ) {
				return $in;
			}
		}
		return null;
	};
	$by_value = function ( $field, $value ) {
		foreach ( (array) ( $field['choices'] ?? array() ) as $c ) {
			if ( (string) ( $c['value'] ?? '' ) === (string) $value ) {
				return $c;
			}
		}
		return null;
	};
	try {
		$given   = '<strong>Given</strong> name 50%DEAL';
		$price   = GFCommon::to_money( 5 );
		$gold    = '<strong>Gold</strong> 50%DEAL &amp; more';
		$form_id = GFAPI::add_form( array(
			'title'  => 'Minn prerc forms ' . wp_rand( 1000, 9999 ),
			'fields' => array(
				array(
					'id'         => 1,
					'type'       => 'name',
					'label'      => 'Name',
					'nameFormat' => 'advanced',
					'inputs'     => array(
						array( 'id' => '1.3', 'label' => 'First', 'name' => '', 'customLabel' => $given ),
						array( 'id' => '1.4', 'label' => 'Middle', 'name' => '', 'isHidden' => true ),
						array( 'id' => '1.6', 'label' => 'Last', 'name' => '', 'customLabel' => 'Family <em>name</em>' ),
					),
				),
				array(
					'id'                => 2,
					'type'              => 'radio',
					'label'             => 'Tier',
					'enableChoiceValue' => true,
					'choices'           => array(
						array( 'text' => $gold, 'value' => 'gold', 'isSelected' => false, 'price' => $price, 'imageChoices_image' => 'https://example.com/gold.png', 'limit' => '5' ),
						array( 'text' => 'Silver', 'value' => 'silver', 'isSelected' => true, 'price' => $price, 'imageChoices_image' => 'https://example.com/silver.png' ),
						array( 'text' => 'Bronze', 'value' => 'bronze', 'isSelected' => false, 'price' => $price, 'imageChoices_image' => 'https://example.com/bronze.png' ),
					),
				),
			),
		) );
		if ( is_wp_error( $form_id ) || ! $form_id ) {
			$form_id = 0;
			$skip( 'GF builder: Gravity Forms would not store a test form' );
			return;
		}
		$form_id = (int) $form_id;
		// One save through their editor's handler first, so what is compared
		// is what Gravity Forms itself keeps after a save of its own.
		list( $st ) = $save( function ( $rows ) {
			return $rows;
		}, array( 'title' => 'Minn prerc forms warmed ' . wp_rand( 1000, 9999 ) ) );
		$name = $stored( 1 );
		$tier = $stored( 2 );
		$c0   = $by_value( $tier, 'gold' );
		if ( 200 !== $st || $given !== ( $input( $name, '1.3' )['customLabel'] ?? null ) || ! $c0 || $gold !== ( $c0['text'] ?? null ) || empty( $c0['imageChoices_image'] ) ) {
			$skip( 'GF builder: Gravity Forms stores these values cleaned on its own save (' . $st . '), so they are not stored values there' );
			return;
		}
		// Each step starts from the form as Gravity Forms kept it.
		$table = GFFormsModel::get_meta_table_name();
		$snap  = (string) $wpdb->get_var( $wpdb->prepare( "SELECT display_meta FROM {$table} WHERE form_id = %d", $form_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$reset = function () use ( $wpdb, $table, $snap, &$form_id ) {
			$wpdb->update( $table, array( 'display_meta' => $snap ), array( 'form_id' => $form_id ) );
			GFFormsModel::flush_current_forms();
			GFFormsModel::flush_current_form( GFFormsModel::get_form_cache_key( $form_id ) );
		};

		// A sub-label edit on one part keeps the other part's stored label.
		$first = $input( $name, '1.3' );
		list( $st, $d ) = $save( function ( $rows ) {
			foreach ( $rows[1]['subInputs'] as &$s ) {
				if ( '1.6' === (string) $s['id'] ) {
					$s['customLabel'] = 'Surname';
				}
			}
			unset( $s );
			return $rows;
		} );
		$now = $stored( 1 );
		$check( 'GF builder: a Last sub-label edit answers 200 and writes it (control)', 200 === $st && 'Surname' === ( $input( $now, '1.6' )['customLabel'] ?? null ), $st . ' ' . wp_json_encode( $input( $now, '1.6' ) ) . ( 200 === $st ? '' : ' ' . wp_json_encode( $d ) ) );
		$check( 'GF builder: and keeps the First part, its sub-label markup and 50%DEAL included, byte for byte', wp_json_encode( $first ) === wp_json_encode( $input( $now, '1.3' ) ), wp_json_encode( $input( $now, '1.3' ) ) );
		$check( 'GF builder: and keeps the hidden Middle part hidden', ! empty( $input( $now, '1.4' )['isHidden'] ), wp_json_encode( $input( $now, '1.4' ) ) );

		// One choice relabelled: the others keep every stored key.
		$reset();
		$tier   = $stored( 2 );
		$before = array( 'gold' => $by_value( $tier, 'gold' ), 'silver' => $by_value( $tier, 'silver' ), 'bronze' => $by_value( $tier, 'bronze' ) );
		list( $st, $d ) = $save( function ( $rows ) {
			foreach ( $rows[2]['choices'] as &$c ) {
				if ( 'bronze' === $c['value'] ) {
					$c['text'] = 'Copper';
				}
			}
			unset( $c );
			return $rows;
		} );
		$tier   = $stored( 2 );
		$copper = $by_value( $tier, 'bronze' );
		$check( 'GF builder: relabelling one choice answers 200 and writes the label (control)', 200 === $st && 'Copper' === ( $copper['text'] ?? null ), $st . ' ' . wp_json_encode( $copper ) . ( 200 === $st ? '' : ' ' . wp_json_encode( $d ) ) );
		$check( 'GF builder: and keeps the other choices byte for byte (label markup, add-on keys, price)', wp_json_encode( $before['gold'] ) === wp_json_encode( $by_value( $tier, 'gold' ) ) && wp_json_encode( $before['silver'] ) === wp_json_encode( $by_value( $tier, 'silver' ) ), wp_json_encode( array( $by_value( $tier, 'gold' ), $by_value( $tier, 'silver' ) ) ) );
		$check( 'GF builder: and the relabelled choice keeps its own image and price', ( $before['bronze']['imageChoices_image'] ?? 'x' ) === ( $copper['imageChoices_image'] ?? null ) && $price === ( $copper['price'] ?? null ), wp_json_encode( $copper ) );

		// Reordered, then one added: each choice keeps its own keys, and the
		// new one takes none of theirs.
		$reset();
		$tier   = $stored( 2 );
		$before = array( 'gold' => $by_value( $tier, 'gold' ), 'silver' => $by_value( $tier, 'silver' ), 'bronze' => $by_value( $tier, 'bronze' ) );
		list( $st ) = $save( function ( $rows ) {
			$rows[2]['choices']   = array_reverse( $rows[2]['choices'] );
			$rows[2]['choices'][] = array( 'text' => 'Tin', 'value' => 'tin', 'isSelected' => false );
			return $rows;
		} );
		$tier = $stored( 2 );
		$order = wp_list_pluck( (array) ( $tier['choices'] ?? array() ), 'value' );
		$check( 'GF builder: a reorder plus a new choice answers 200 and stores the new order (control)', 200 === $st && array( 'bronze', 'silver', 'gold', 'tin' ) === $order, $st . ' ' . wp_json_encode( $order ) );
		$check( 'GF builder: and each moved choice keeps its own stored bytes', wp_json_encode( $before['gold'] ) === wp_json_encode( $by_value( $tier, 'gold' ) ) && wp_json_encode( $before['silver'] ) === wp_json_encode( $by_value( $tier, 'silver' ) ) && wp_json_encode( $before['bronze'] ) === wp_json_encode( $by_value( $tier, 'bronze' ) ), wp_json_encode( $tier['choices'] ?? null ) );
		$tin = $by_value( $tier, 'tin' );
		$check( 'GF builder: and the new choice carries no other choice\'s image (control)', $tin && ! isset( $tin['imageChoices_image'] ) && '' === (string) ( $tin['price'] ?? '' ), wp_json_encode( $tin ) );
	} finally {
		$clean();
	}
} )();

/*
 * Gravity Forms notifications. The route merges the page's read of the
 * stored notification under the keys the page changed, and the save then ran
 * the name and the From name through sanitize_text_field, trimmed the
 * addresses and rebuilt the conditions and routing rules from that read, so
 * a subject-only edit rewrote a name an import or the API had stored
 * ('50%DEAL Store' became '50AL Store'), a numeric field id in a rule and a
 * routing address kept as typed. Gravity Forms' own screen never cleans a
 * name: it refuses the save.
 */
( function () use ( $check, $skip, $call, $once, $wpdb ) {
	if ( ! function_exists( 'minn_admin_gfn_load' ) || ! minn_admin_gfn_load() || ! GFCommon::current_user_can_any( array( 'gravityforms_edit_forms', 'gform_full_access' ) ) ) {
		$skip( 'GF notifications: Gravity Forms inactive' );
		return;
	}
	$form_id = 0;
	$clean   = $once( function () use ( &$form_id ) {
		if ( $form_id ) {
			GFAPI::delete_form( $form_id );
		}
	} );
	$stored = function ( $nid = 'minnn1' ) use ( $wpdb, &$form_id ) {
		$all = json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT notifications FROM ' . GFFormsModel::get_meta_table_name() . ' WHERE form_id = %d', $form_id ) ), true ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (array) ( $all[ $nid ] ?? array() );
	};
	try {
		$seed    = array(
			'id'       => 'minnn1',
			'name'     => '50%DEAL Store <b>alerts</b>',
			'isActive' => true,
			'service'  => 'wordpress',
			'event'    => 'form_submission',
			'toType'   => 'email',
			'to'       => '{admin_email}',
			'from'     => '{admin_email}',
			'fromName' => 'Shop 50%DEAL <i>team</i>',
			'replyTo'  => '',
			'bcc'      => '',
			'subject'  => 'New entry',
			'message'  => '{all_fields}',
			// Written through their API, a rule's field id is often a number.
			'conditionalLogic' => array( 'actionType' => 'show', 'logicType' => 'all', 'rules' => array( array( 'fieldId' => 2, 'operator' => 'is', 'value' => 'VIP' ) ) ),
		);
		// A routed one: their routing editor keeps an address as typed.
		$routed  = array_merge( $seed, array(
			'id'               => 'minnn2',
			'name'             => 'Routed',
			'toType'           => 'routing',
			'to'               => '',
			'routing'          => array( array( 'email' => ' sales@example.com', 'fieldId' => 2, 'operator' => 'is', 'value' => 'VIP' ) ),
			'conditionalLogic' => null,
		) );
		$form_id = GFAPI::add_form( array(
			'title'         => 'Minn prerc notifications ' . wp_rand( 1000, 9999 ),
			'fields'        => array( array( 'id' => 1, 'type' => 'email', 'label' => 'Email' ), array( 'id' => 2, 'type' => 'text', 'label' => 'Tier' ) ),
			'notifications' => array( 'minnn1' => $seed, 'minnn2' => $routed ),
		) );
		if ( is_wp_error( $form_id ) || ! $form_id ) {
			$form_id = 0;
			$skip( 'GF notifications: Gravity Forms would not store a test form' );
			return;
		}
		$form_id = (int) $form_id;
		$before  = $stored();
		if ( $seed['name'] !== ( $before['name'] ?? null ) || $seed['fromName'] !== ( $before['fromName'] ?? null ) || wp_json_encode( $seed['conditionalLogic'] ) !== wp_json_encode( $before['conditionalLogic'] ?? null ) || wp_json_encode( $routed['routing'] ) !== wp_json_encode( $stored( 'minnn2' )['routing'] ?? null ) ) {
			$skip( 'GF notifications: Gravity Forms stored the notification cleaned, so these are not stored values there' );
			return;
		}
		list( $st, $d ) = $call( 'POST', "/minn-admin/v1/gf/notifications/{$form_id}:minnn1/full", array( 'subject' => 'New entry from the site' ) );
		$now = $stored();
		$check( 'GF notifications: a subject-only edit answers 200 and writes the subject (control)', 200 === $st && 'New entry from the site' === ( $now['subject'] ?? null ), $st . ' ' . wp_json_encode( 200 === $st ? $now['subject'] ?? null : $d ) );
		$check( 'GF notifications: and keeps the stored name byte for byte', $seed['name'] === ( $now['name'] ?? null ), wp_json_encode( $now['name'] ?? null ) );
		$check( 'GF notifications: and keeps the stored From name byte for byte', $seed['fromName'] === ( $now['fromName'] ?? null ), wp_json_encode( $now['fromName'] ?? null ) );
		$check( 'GF notifications: and keeps the From and To addresses', $seed['from'] === ( $now['from'] ?? null ) && $seed['to'] === ( $now['to'] ?? null ), wp_json_encode( array( $now['from'] ?? null, $now['to'] ?? null ) ) );
		$check( 'GF notifications: and keeps its conditions as stored (a numeric field id included)', wp_json_encode( $seed['conditionalLogic'] ) === wp_json_encode( $now['conditionalLogic'] ?? null ), wp_json_encode( $now['conditionalLogic'] ?? null ) );

		list( $st, $d ) = $call( 'POST', "/minn-admin/v1/gf/notifications/{$form_id}:minnn2/full", array( 'subject' => 'Routed entry' ) );
		$now = $stored( 'minnn2' );
		$check( 'GF notifications: a subject-only edit of a routed one answers 200 and writes it (control)', 200 === $st && 'Routed entry' === ( $now['subject'] ?? null ), $st . ' ' . wp_json_encode( 200 === $st ? $now['subject'] ?? null : $d ) );
		$check( 'GF notifications: and keeps its routing rules as stored', wp_json_encode( $routed['routing'] ) === wp_json_encode( $now['routing'] ?? null ), wp_json_encode( $now['routing'] ?? null ) );
		list( $st ) = $call( 'POST', "/minn-admin/v1/gf/notifications/{$form_id}:minnn2/full", array( 'routing' => array( array( 'email' => 'not an address', 'fieldId' => '2', 'operator' => 'is', 'value' => 'VIP' ) ) ) );
		$check( 'GF notifications: an edited routing rule is still checked (control)', 400 === $st, (string) $st );
		list( $st ) = $call( 'POST', "/minn-admin/v1/gf/notifications/{$form_id}:minnn2/full", array( 'routing' => array( array( 'email' => ' ops@example.com ', 'fieldId' => '2', 'operator' => 'is', 'value' => 'Gold' ) ) ) );
		$now = $stored( 'minnn2' );
		$check( 'GF notifications: and an edited one is written (control)', 200 === $st && 'ops@example.com' === ( $now['routing'][0]['email'] ?? null ) && 'Gold' === ( $now['routing'][0]['value'] ?? null ), $st . ' ' . wp_json_encode( $now['routing'] ?? null ) );

		list( $st ) = $call( 'POST', "/minn-admin/v1/gf/notifications/{$form_id}:minnn1/full", array( 'name' => 'Renamed <b>alerts</b>' ) );
		$now = $stored();
		$check( 'GF notifications: an edited name is still written cleaned (control)', 200 === $st && 'Renamed alerts' === ( $now['name'] ?? null ), $st . ' ' . wp_json_encode( $now['name'] ?? null ) );
	} finally {
		$clean();
	}
} )();

/*
 * Gravity Forms confirmations. The page reads a page that no longer exists
 * as none and a type it does not offer as Text, the route merges that read
 * under the keys the page changed, and the save wrote it all back: a
 * message-only edit blanked the deleted page's id, turned the type into
 * 'message', rebuilt the conditions (a numeric field id became a string)
 * and ran the name through sanitize_text_field.
 */
( function () use ( $check, $skip, $call, $once, $wpdb, $gone_page ) {
	if ( ! function_exists( 'minn_admin_gfc_build' ) || ! function_exists( 'minn_admin_gfn_load' ) || ! minn_admin_gfn_load() || ! GFCommon::current_user_can_any( array( 'gravityforms_edit_forms', 'gform_full_access' ) ) ) {
		$skip( 'GF confirmations: Gravity Forms inactive' );
		return;
	}
	$gone = $gone_page();
	if ( ! $gone ) {
		$skip( 'GF confirmations: could not make a deleted page id' );
		return;
	}
	$form_id = 0;
	$clean   = $once( function () use ( &$form_id ) {
		if ( $form_id ) {
			GFAPI::delete_form( $form_id );
		}
	} );
	$stored = function ( $cid ) use ( $wpdb, &$form_id ) {
		$all = json_decode( (string) $wpdb->get_var( $wpdb->prepare( 'SELECT confirmations FROM ' . GFFormsModel::get_meta_table_name() . ' WHERE form_id = %d', $form_id ) ), true ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (array) ( $all[ $cid ] ?? array() );
	};
	try {
		$c1      = array( 'id' => 'minnc1', 'name' => 'Thanks 50%DEAL <b>VIP</b>', 'isDefault' => false, 'isActive' => true, 'type' => 'message', 'message' => 'Hi', 'pageId' => (string) $gone, 'url' => '', 'queryString' => '', 'conditionalLogic' => array( 'actionType' => 'show', 'logicType' => 'all', 'rules' => array( array( 'fieldId' => 1, 'operator' => 'is', 'value' => 'VIP' ) ) ) );
		// A type their page does not offer (Gravity Forms drops one with none
		// as corrupt, and reads any other as a redirect).
		$c2      = array( 'id' => 'minnc2', 'name' => 'Add-on type', 'isDefault' => false, 'isActive' => true, 'type' => 'minn_addon', 'message' => 'x', 'pageId' => '', 'url' => '', 'queryString' => '' );
		$form_id = GFAPI::add_form( array(
			'title'         => 'Minn prerc confirmations ' . wp_rand( 1000, 9999 ),
			'fields'        => array( array( 'id' => 1, 'type' => 'text', 'label' => 'Text' ) ),
			'confirmations' => array(
				'minnc0' => array( 'id' => 'minnc0', 'name' => 'Default', 'isDefault' => true, 'type' => 'message', 'message' => 'Thanks' ),
				'minnc1' => $c1,
				'minnc2' => $c2,
			),
		) );
		if ( is_wp_error( $form_id ) || ! $form_id ) {
			$form_id = 0;
			$skip( 'GF confirmations: Gravity Forms would not store a test form' );
			return;
		}
		$form_id = (int) $form_id;
		if ( $c1['name'] !== ( $stored( 'minnc1' )['name'] ?? null ) || (string) $gone !== (string) ( $stored( 'minnc1' )['pageId'] ?? '' ) || wp_json_encode( $c1['conditionalLogic'] ) !== wp_json_encode( $stored( 'minnc1' )['conditionalLogic'] ?? null ) ) {
			$skip( 'GF confirmations: Gravity Forms stored the confirmation cleaned, so these are not stored values there' );
			return;
		}
		list( $st, $d ) = $call( 'POST', "/minn-admin/v1/gf/confirmations/{$form_id}:minnc1/full", array( 'message' => 'Hello' ) );
		$now = $stored( 'minnc1' );
		$check( 'GF confirmations: a message-only edit answers 200 and writes the message (control)', 200 === $st && 'Hello' === ( $now['message'] ?? null ), $st . ' ' . wp_json_encode( 200 === $st ? $now['message'] ?? null : $d ) );
		$check( 'GF confirmations: and keeps the stored name byte for byte', $c1['name'] === ( $now['name'] ?? null ), wp_json_encode( $now['name'] ?? null ) );
		$check( 'GF confirmations: and keeps the deleted page\'s id', (string) $gone === (string) ( $now['pageId'] ?? '' ), wp_json_encode( $now['pageId'] ?? null ) );
		$check( 'GF confirmations: and keeps its conditions as stored (a numeric field id included)', wp_json_encode( $c1['conditionalLogic'] ) === wp_json_encode( $now['conditionalLogic'] ?? null ), wp_json_encode( $now['conditionalLogic'] ?? null ) );

		list( $st ) = $call( 'POST', "/minn-admin/v1/gf/confirmations/{$form_id}:minnc2/full", array( 'message' => 'y' ) );
		$now = $stored( 'minnc2' );
		$check( 'GF confirmations: a message-only edit of one with a type the page does not offer keeps that type', 200 === $st && 'minn_addon' === ( $now['type'] ?? null ) && 'y' === ( $now['message'] ?? null ), $st . ' ' . wp_json_encode( array( $now['type'] ?? null, $now['message'] ?? null ) ) );

		list( $st ) = $call( 'POST', "/minn-admin/v1/gf/confirmations/{$form_id}:minnc1/full", array( 'name' => 'Renamed <b>VIP</b>' ) );
		$check( 'GF confirmations: an edited name is still written cleaned (control)', 200 === $st && 'Renamed VIP' === ( $stored( 'minnc1' )['name'] ?? null ), $st . ' ' . wp_json_encode( $stored( 'minnc1' )['name'] ?? null ) );
		list( $st ) = $call( 'POST', "/minn-admin/v1/gf/confirmations/{$form_id}:minnc1/full", array( 'type' => 'page' ) );
		$check( 'GF confirmations: switching it to the deleted page is still refused (control)', 400 === $st, (string) $st );
		list( $st ) = $call( 'POST', "/minn-admin/v1/gf/confirmations/{$form_id}:minnc1/full", array( 'conditionalLogic' => array( 'actionType' => 'hide', 'logicType' => 'all', 'rules' => array( array( 'fieldId' => '1', 'operator' => 'is', 'value' => 'Gold' ) ) ) ) );
		$now = $stored( 'minnc1' );
		$check( 'GF confirmations: edited conditions are still written (control)', 200 === $st && 'hide' === ( $now['conditionalLogic']['actionType'] ?? null ) && 'Gold' === ( $now['conditionalLogic']['rules'][0]['value'] ?? null ), $st . ' ' . wp_json_encode( $now['conditionalLogic'] ?? null ) );
	} finally {
		$clean();
	}
} )();

/*
 * WPForms answers. Their format() writes '' for a name or address part not
 * submitted, and the save submitted only the parts the form shows now, so an
 * edit of the first name emptied a middle name the form had since stopped
 * asking for, and a city edit emptied a hidden Address line 2.
 */
( function () use ( $check, $skip, $call, $once ) {
	if ( ! function_exists( 'minn_admin_wpforms_edit_supported' ) || ! minn_admin_wpforms_edit_supported() || ! function_exists( 'minn_admin_wpforms_edit_block' ) ) {
		$skip( 'WPForms parts: WPForms Pro inactive' );
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
		$made['form'] = (int) wpforms()->obj( 'form' )->add( 'Minn prerc parts' );
		if ( ! $made['form'] ) {
			$skip( 'WPForms parts: WPForms would not store a test form' );
			return;
		}
		wpforms()->obj( 'form' )->update( $made['form'], array(
			'id'       => $made['form'],
			'field_id' => 3,
			'settings' => array( 'form_title' => 'Minn prerc parts' ),
			'fields'   => array(
				1 => array( 'id' => 1, 'type' => 'name', 'label' => 'Name', 'format' => 'first-last' ),
				2 => array( 'id' => 2, 'type' => 'address', 'label' => 'Address', 'scheme' => 'international', 'address2_hide' => '1' ),
			),
		) );
		$fields        = array(
			1 => array( 'name' => 'Name', 'value' => 'Ann Q. Lee', 'id' => 1, 'type' => 'name', 'first' => 'Ann', 'middle' => 'Q.', 'last' => 'Lee' ),
			2 => array( 'name' => 'Address', 'value' => "1 Main St\nSuite 5\nParis, IDF\n75001\nFR", 'id' => 2, 'type' => 'address', 'address1' => '1 Main St', 'address2' => 'Suite 5', 'city' => 'Paris', 'state' => 'IDF', 'postal' => '75001', 'country' => 'FR' ),
		);
		$made['entry'] = (int) wpforms()->obj( 'entry' )->add( array( 'form_id' => $made['form'], 'status' => '', 'fields' => wp_json_encode( $fields ) ) );
		if ( ! $made['entry'] ) {
			$skip( 'WPForms parts: WPForms would not store a test entry' );
			return;
		}
		wpforms()->obj( 'entry_fields' )->save( $fields, wpforms()->obj( 'form' )->get( $made['form'], array( 'content_only' => true ) ), $made['entry'] );
		$stored = function () use ( &$made ) {
			$e = wpforms()->obj( 'entry' )->get( $made['entry'] );
			return (array) wpforms_decode( (string) ( $e->fields ?? '' ) );
		};
		$part = function ( $f, $id, $key ) {
			return (string) ( $f[ $id ][ $key ] ?? $f[ (string) $id ][ $key ] ?? '' );
		};

		list( $st, $d ) = $call( 'POST', "/minn-admin/v1/wpforms/entries/{$made['entry']}/answers", array( 'values' => array( '1.first' => 'Anne' ), 'original' => array( '1.first' => 'Ann' ) ) );
		$now = $stored();
		$check( 'WPForms parts: a first-name edit answers 200 and writes it (control)', 200 === $st && 'Anne' === $part( $now, 1, 'first' ), $st . ' ' . wp_json_encode( 200 === $st ? $now[1] ?? null : $d ) );
		$check( 'WPForms parts: and keeps the middle name the form no longer shows', 'Q.' === $part( $now, 1, 'middle' ), wp_json_encode( $now[1] ?? null ) );

		list( $st, $d ) = $call( 'POST', "/minn-admin/v1/wpforms/entries/{$made['entry']}/answers", array( 'values' => array( '2.city' => 'Lyon' ), 'original' => array( '2.city' => 'Paris' ) ) );
		$now = $stored();
		$check( 'WPForms parts: a city edit answers 200 and writes it (control)', 200 === $st && 'Lyon' === $part( $now, 2, 'city' ), $st . ' ' . wp_json_encode( 200 === $st ? $now[2] ?? null : $d ) );
		$check( 'WPForms parts: and keeps the hidden Address line 2', 'Suite 5' === $part( $now, 2, 'address2' ), wp_json_encode( $now[2] ?? null ) );
	} finally {
		$clean();
	}
} )();

/*
 * WPForms notifications and confirmations page. The page sends a changed
 * confirmation with only its changed keys, but the save wrote all five back:
 * the name through sanitize_text_field (their builder stores it as typed),
 * a type the page reads as a message as one, a '0' page as '', a redirect
 * trimmed; and it checked where visitors go on every save, so a message edit
 * of a confirmation whose page was since deleted was refused.
 */
( function () use ( $check, $skip, $call, $once, $wpdb, $gone_page ) {
	if ( ! function_exists( 'minn_admin_wpforms_emails_payload' ) || ! function_exists( 'minn_admin_wpforms_active' ) || ! minn_admin_wpforms_active() ) {
		$skip( 'WPForms emails: WPForms inactive' );
		return;
	}
	$gone = $gone_page();
	$made = array( 'form' => 0 );
	$clean = $once( function () use ( &$made ) {
		if ( $made['form'] ) {
			wp_delete_post( $made['form'], true );
		}
	} );
	$stored = function ( $key ) use ( $wpdb, &$made ) {
		$data = (array) wpforms_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $made['form'] ) ) );
		return (array) ( $data['settings']['confirmations'][ $key ] ?? array() );
	};
	try {
		$made['form'] = (int) wpforms()->obj( 'form' )->add( 'Minn prerc emails' );
		if ( ! $made['form'] ) {
			$skip( 'WPForms emails: WPForms would not store a test form' );
			return;
		}
		$c1 = array( 'name' => 'Promo 50%DEAL <b>x</b>', 'type' => '', 'message' => 'Thanks', 'page' => '0', 'redirect' => ' https://example.com/x ' );
		$c2 = array( 'name' => 'Page one', 'type' => 'page', 'message' => 'M', 'page' => (string) $gone, 'redirect' => '' );
		wpforms()->obj( 'form' )->update( $made['form'], array(
			'id'       => $made['form'],
			'field_id' => 2,
			'settings' => array(
				'form_title'          => 'Minn prerc emails',
				'notification_enable' => '1',
				'notifications'       => array( 1 => array( 'notification_name' => 'Default', 'email' => '{admin_email}', 'subject' => 'New', 'message' => '{all_fields}' ) ),
				'confirmations'       => array( 1 => $c1, 2 => $c2 ),
			),
			'fields'   => array( 1 => array( 'id' => 1, 'type' => 'text', 'label' => 'Text' ) ),
		) );
		if ( wp_json_encode( $c1 ) !== wp_json_encode( array_intersect_key( $stored( 1 ), $c1 ) ) ) {
			$skip( 'WPForms emails: WPForms stored the confirmation cleaned, so these are not stored values there' );
			return;
		}
		list( $st, $d ) = $call( 'POST', "/minn-admin/v1/wpforms/forms/{$made['form']}/emails", array( 'confirmations' => array( array( 'key' => '1', 'message' => 'Thanks!' ) ) ) );
		$now = $stored( 1 );
		$check( 'WPForms emails: a message-only edit answers 200 and writes the message (control)', 200 === $st && 'Thanks!' === ( $now['message'] ?? null ), $st . ' ' . wp_json_encode( 200 === $st ? $now['message'] ?? null : $d ) );
		$check( 'WPForms emails: and keeps the stored name, type, page and redirect byte for byte', wp_json_encode( array( $c1['name'], $c1['type'], $c1['page'], $c1['redirect'] ) ) === wp_json_encode( array( $now['name'] ?? null, $now['type'] ?? null, $now['page'] ?? null, $now['redirect'] ?? null ) ), wp_json_encode( $now ) );

		if ( $gone ) {
			list( $st, $d ) = $call( 'POST', "/minn-admin/v1/wpforms/forms/{$made['form']}/emails", array( 'confirmations' => array( array( 'key' => '2', 'message' => 'M2' ) ) ) );
			$now = $stored( 2 );
			$check( 'WPForms emails: a message edit of a confirmation whose page was deleted is saved, and keeps the page', 200 === $st && 'M2' === ( $now['message'] ?? null ) && (string) $gone === (string) ( $now['page'] ?? '' ), $st . ' ' . wp_json_encode( 200 === $st ? $now : $d ) );
		} else {
			$skip( 'WPForms emails: could not make a deleted page id' );
		}
		$pre        = $stored( 1 );
		list( $st ) = $call( 'POST', "/minn-admin/v1/wpforms/forms/{$made['form']}/emails", array( 'confirmations' => array( array( 'key' => '1', 'name' => 'Renamed <b>x</b>', 'type' => 'redirect', 'redirect' => 'not a url' ) ) ) );
		$check( 'WPForms emails: a changed redirect is still checked, and a refused save writes nothing (control)', 400 === $st && $pre === $stored( 1 ), $st . ' ' . wp_json_encode( $stored( 1 ) ) );
		list( $st ) = $call( 'POST', "/minn-admin/v1/wpforms/forms/{$made['form']}/emails", array( 'confirmations' => array( array( 'key' => '1', 'name' => 'Renamed <b>x</b>' ) ) ) );
		$check( 'WPForms emails: an edited name is still written cleaned (control)', 200 === $st && 'Renamed x' === ( $stored( 1 )['name'] ?? null ), $st . ' ' . wp_json_encode( $stored( 1 )['name'] ?? null ) );
	} finally {
		$clean();
	}
} )();

printf( "\nsecurity-v046-prerc-forms: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
