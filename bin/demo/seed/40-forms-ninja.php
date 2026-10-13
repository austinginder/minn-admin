<?php
/**
 * Ninja Forms: Priya's workshop waitlist and the waitlist for the kokedama
 * kit (the plugin's own "Contact Me" form, adopted and renamed), with
 * submissions from the last two months and a couple in the trash.
 *
 * Ninja Forms keeps forms, fields and actions in its nf3_* tables but every
 * submission is an `nf_sub` post: `_form_id`, `_seq_num` and one
 * `_field_{id}` meta per answer, stored HTML-encoded by the submission model
 * (which is what writes them here, so no action runs and no email goes out).
 * The model takes `_seq_num` from a counter in nf3_form_meta that only goes
 * up, so the part numbers its own submissions and resets that counter. Ninja
 * Forms has no read or starred state, only published and trashed posts.
 *
 * @package minn-admin
 */

return function ( Minn_Demo_Seed $demo ) {
	global $wpdb;
	if ( ! function_exists( 'Ninja_Forms' ) || ! class_exists( 'WPN_Helper' ) ) {
		$demo->log( 'Ninja Forms is not active: no waitlists' );
		return;
	}
	$group = 'forms-ninja';
	foreach ( $demo->ids( $group ) as $key => $id ) {
		if ( 0 === strpos( $key, 'sub-' ) ) {
			wp_delete_post( (int) $id, true );
		}
	}
	// The waitlist form is ours alone: it keeps its id, and its fields and
	// actions are made again below.
	$wait_id = (int) $demo->id( $group, 'form-waitlist' );
	if ( $wait_id && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}nf3_forms WHERE id = %d", $wait_id ) ) ) {
		// get_fields() can answer from the form cache, whose models do not
		// delete, so the rows go directly.
		$fields = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}nf3_fields WHERE parent_id = %d", $wait_id ) );
		foreach ( $fields as $fid ) {
			$wpdb->delete( $wpdb->prefix . 'nf3_field_meta', array( 'parent_id' => (int) $fid ) );
			$wpdb->delete( $wpdb->prefix . 'nf3_fields', array( 'id' => (int) $fid ) );
		}
		WPN_Helper::delete_nf_cache( $wait_id );
		foreach ( Ninja_Forms()->form( $wait_id )->get_actions() as $a ) {
			$a->delete();
		}
	} else {
		$wait_id = 0;
	}
	$kit_id = (int) $demo->id( $group, 'form-kit' );
	if ( ! $kit_id || ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}nf3_forms WHERE id = %d", $kit_id ) ) ) {
		// The form Ninja Forms makes on activation.
		$kit_id = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}nf3_forms WHERE title = 'Contact Me' ORDER BY id LIMIT 1" );
	}
	$demo->forget( $group );

	$field = function ( $form_id, $settings ) {
		$f = Ninja_Forms()->form( $form_id )->field()->get();
		$f->update_settings( $settings + array( 'label_pos' => 'default', 'required' => 0 ) );
		$f->update_setting( 'parent_id', $form_id );
		$f->save();
		return (int) $f->get_id();
	};
	$action = function ( $form_id, $settings ) {
		$a = Ninja_Forms()->form( $form_id )->action()->get();
		$a->update_settings( $settings + array( 'active' => 1 ) );
		$a->update_setting( 'parent_id', $form_id );
		$a->save();
	};
	$choices = function ( $labels ) {
		$out = array();
		foreach ( array_values( $labels ) as $i => $label ) {
			$out[] = array( 'label' => $label, 'value' => $label, 'calc' => '', 'selected' => 0, 'order' => $i );
		}
		return $out;
	};

	// The waitlist.
	$form = $wait_id ? Ninja_Forms()->form( $wait_id )->get() : Ninja_Forms()->form()->get();
	$form->update_settings(
		array(
			'title'             => 'Workshop waitlist',
			'key'               => '',
			'default_label_pos' => 'above',
			'show_title'        => 0,
			'clear_complete'    => 1,
			'hide_complete'     => 1,
		)
	);
	$form->save();
	$wait_id   = (int) $form->get_id();
	$workshops = array( 'Spring Terrarium Workshop', 'Propagation Basics', 'Kokedama Moss Balls', 'Kids Sunflower Morning' );
	$w         = array(
		'name'     => $field( $wait_id, array( 'type' => 'textbox', 'label' => 'Name', 'key' => 'name', 'order' => 1, 'required' => 1 ) ),
		'email'    => $field( $wait_id, array( 'type' => 'email', 'label' => 'Email', 'key' => 'email', 'order' => 2, 'required' => 1 ) ),
		'workshop' => $field( $wait_id, array( 'type' => 'listselect', 'label' => 'Workshop', 'key' => 'workshop', 'order' => 3, 'required' => 1, 'options' => $choices( $workshops ) ) ),
		'seats'    => $field( $wait_id, array( 'type' => 'number', 'label' => 'Seats', 'key' => 'seats', 'order' => 4, 'num_min' => 1, 'num_max' => 8, 'num_step' => 1 ) ),
		'phone'    => $field( $wait_id, array( 'type' => 'phone', 'label' => 'Phone', 'key' => 'phone', 'order' => 5 ) ),
		'times'    => $field( $wait_id, array( 'type' => 'listcheckbox', 'label' => 'Times that work', 'key' => 'times', 'order' => 6, 'options' => $choices( array( 'Saturday 10 am', 'Saturday 1 pm', 'A weekday evening' ) ) ) ),
		'notes'    => $field( $wait_id, array( 'type' => 'textarea', 'label' => 'Notes for Priya', 'key' => 'notes', 'order' => 7 ) ),
	);
	$field( $wait_id, array( 'type' => 'submit', 'label' => 'Join the waitlist', 'key' => 'submit', 'order' => 8, 'processing_label' => 'Sending' ) );
	$action( $wait_id, array( 'type' => 'save', 'label' => 'Record Submission' ) );
	$action( $wait_id, array( 'type' => 'email', 'label' => 'Tell Priya', 'to' => 'priya@larkspur.example', 'email_subject' => 'Waitlist: {field:workshop}', 'email_message' => '{fields_table}', 'from_name' => 'Larkspur & Fern', 'reply_to' => '{field:email}' ) );
	$action( $wait_id, array( 'type' => 'successmessage', 'label' => 'Success Message', 'success_msg' => '<p>You are on the list. Priya will email you as soon as a seat opens up.</p>' ) );
	// The form cache answers get_fields() once it exists, so it is made again
	// from the rows (Minn labels answers from it).
	WPN_Helper::delete_nf_cache( $wait_id );
	WPN_Helper::build_nf_cache( $wait_id );
	$demo->remember( $group, 'form-waitlist', $wait_id );

	// The kokedama kit waitlist: Ninja Forms' starter form, renamed.
	if ( ! $kit_id ) {
		$kit = Ninja_Forms()->form()->get();
		$kit->update_settings( array( 'title' => 'Kokedama kit waitlist', 'default_label_pos' => 'above' ) );
		$kit->save();
		$kit_id = (int) $kit->get_id();
		$field( $kit_id, array( 'type' => 'textbox', 'label' => 'Name', 'key' => 'name', 'order' => 1 ) );
		$field( $kit_id, array( 'type' => 'email', 'label' => 'Email', 'key' => 'email', 'order' => 2 ) );
		$field( $kit_id, array( 'type' => 'textarea', 'label' => 'Notes', 'key' => 'message', 'order' => 3 ) );
		$field( $kit_id, array( 'type' => 'submit', 'label' => 'Notify me', 'key' => 'submit', 'order' => 4 ) );
	} else {
		$kit = Ninja_Forms()->form( $kit_id )->get();
		$kit->update_setting( 'title', 'Kokedama kit waitlist' );
		$kit->save();
		WPN_Helper::delete_nf_cache( $kit_id );
		foreach ( Ninja_Forms()->form( $kit_id )->get_fields() as $f ) {
			$label = array( 'textarea' => 'Notes', 'submit' => 'Notify me' );
			$type  = (string) $f->get_setting( 'type' );
			if ( isset( $label[ $type ] ) ) {
				$f->update_setting( 'label', $label[ $type ] );
				$f->save();
			}
		}
	}
	$kit_fields = array();
	foreach ( Ninja_Forms()->form( $kit_id )->get_fields() as $f ) {
		$kit_fields[ (string) $f->get_setting( 'type' ) ] = (int) $f->get_id();
	}
	WPN_Helper::delete_nf_cache( $kit_id );
	WPN_Helper::build_nf_cache( $kit_id );
	$demo->remember( $group, 'form-kit', $kit_id );

	// Locals who are not in the cast.
	$locals = array(
		'maya'   => array( 'Maya Lindqvist', 'maya.lindqvist@example.org', '(503) 555-0141' ),
		'felix'  => array( 'Felix Grant', 'felix.grant@example.com', '(503) 555-0142' ),
		'rosa'   => array( 'Rosa Delgado', 'rosa.delgado@example.net', '(971) 555-0143' ),
		'hollis' => array( 'Hollis Reed', 'hollis.reed@example.com', '(503) 555-0144' ),
		'ana'    => array( 'Ana Kowalski', 'ana.kowalski@example.org', '(360) 555-0145' ),
		'jonah'  => array( 'Jonah Feld', 'jonah.feld@example.net', '(503) 555-0146' ),
		'spam'   => array( 'Rank Fast SEO', 'growth@rankfast.example', '' ),
	);
	$who = function ( $slug ) use ( $demo, $locals ) {
		if ( isset( $locals[ $slug ] ) ) {
			return array( 'name' => $locals[ $slug ][0], 'email' => $locals[ $slug ][1], 'phone' => $locals[ $slug ][2] );
		}
		$p = $demo->person( $slug );
		return array( 'name' => $p['name'], 'email' => $p['email'], 'phone' => $p['phone'] );
	};
	$when = function ( $days, $hour, $minute ) use ( $demo ) {
		// Under a day: an exact offset, so "this morning" is never later today.
		return $days < 1 ? $demo->now - (int) round( $days * DAY_IN_SECONDS ) : $demo->ago( $days, $hour, $minute );
	};
	$save = function ( $form_id, $values, $ts, $status, $seq ) use ( $demo, $wpdb ) {
		$sub = Ninja_Forms()->form( $form_id )->sub()->get();
		$sub->update_field_values( $values );
		$sub->save();
		$id = (int) $sub->get_id();
		update_post_meta( $id, '_seq_num', $seq );
		// Trash first: wp_trash_post saves the post again, dates included.
		if ( 'trash' === $status ) {
			wp_trash_post( $id );
		}
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_author'       => 0,
				'post_date'         => $demo->local( $ts ),
				'post_date_gmt'     => $demo->gmt( $ts ),
				'post_modified'     => $demo->local( $ts ),
				'post_modified_gmt' => $demo->gmt( $ts ),
			),
			array( 'ID' => $id )
		);
		clean_post_cache( $id );
		return $id;
	};
	$counter = function ( $form_id, $next ) use ( $wpdb ) {
		$table = $wpdb->prefix . 'nf3_form_meta';
		$wpdb->delete( $table, array( 'parent_id' => $form_id, 'key' => '_seq_num' ) );
		$wpdb->insert( $table, array( 'parent_id' => $form_id, 'key' => '_seq_num', 'value' => $next, 'meta_key' => '_seq_num', 'meta_value' => $next ) );
		$wpdb->update( $wpdb->prefix . 'nf3_forms', array( 'seq_num' => $next ), array( 'id' => $form_id ) );
	};

	// Oldest first: who, days ago, hour, minute, workshop, seats, times, notes, status.
	$waitlist = array(
		array( 'ruth', 52.0, 18, 40, 'Propagation Basics', 1, array( 'Saturday 10 am' ), "Asking for myself this time, not for the hall. I have been meaning to learn how to root cuttings in water for years.", 'publish' ),
		array( 'jonah', 41.3, 20, 5, 'Spring Terrarium Workshop', 1, array( 'Saturday 10 am' ), 'I walk past the terrariums in your window on Station Road every morning. Any chance of a closed-jar version of the class?', 'publish' ),
		array( 'spam', 35.6, 3, 12, 'Spring Terrarium Workshop', 1, array(), 'We can put your shop on the first page of search results in 30 days. Reply today for a free audit of your website.', 'trash' ),
		array( 'ana', 28.2, 12, 30, 'Kokedama Moss Balls', 1, array( 'Saturday 1 pm' ), "I'm coming down from Vancouver. Would love to make one with a fern for my bathroom, it gets steamy in there.", 'publish' ),
		array( 'hollis', 21.5, 21, 15, 'Propagation Basics', 2, array( 'Saturday 10 am', 'Saturday 1 pm' ), 'My partner and I inherited a huge rubber plant from our neighbor and want to learn to propagate it before it hits the ceiling.', 'publish' ),
		array( 'rosa', 14.8, 9, 50, 'Kids Sunflower Morning', 2, array( 'Saturday 10 am' ), "My daughter is five. Is that old enough? She is very serious about sunflowers and already picked a spot in the yard.", 'publish' ),
		array( 'felix', 9.4, 22, 10, 'Spring Terrarium Workshop', 1, array( 'A weekday evening' ), 'Saturdays are hard with my work schedule. If you ever run an evening terrarium session, count me in.', 'publish' ),
		array( 'kenji', 6.1, 13, 25, 'Kokedama Moss Balls', 1, array( 'Saturday 10 am', 'Saturday 1 pm' ), 'I already have two seats for the terrarium class. Put me down for kokedama too if it runs before the holidays.', 'publish' ),
		array( 'nadia', 3.7, 8, 45, 'Propagation Basics', 1, array( 'Saturday 1 pm' ), 'I drive up from Eugene, so the afternoon session is easier. Can I bring cuttings from my own pothos and monstera?', 'publish' ),
		array( 'maya', 1.3, 19, 5, 'Kids Sunflower Morning', 3, array( 'Saturday 10 am' ), 'Two kids, ages 6 and 8, plus me. We missed the last one because it sold out in a day.', 'publish' ),
		array( 'maya', 1.29, 19, 6, 'Kids Sunflower Morning', 3, array( 'Saturday 10 am' ), 'Two kids, ages 6 and 8, plus me. We missed the last one because it sold out in a day.', 'trash' ),
		array( 'olivia', 0.15, 0, 0, 'Spring Terrarium Workshop', 2, array( 'Saturday 10 am', 'Saturday 1 pm' ), 'My sister is in town the first two weekends of next month. If two seats open up together we would love them, even at short notice.', 'publish' ),
	);
	// Both lists go in as one, oldest first: Minn lists submissions newest
	// id first, so the order they are made in is the order they read in.
	$queue = array();
	foreach ( $waitlist as $r ) {
		list( $slug, $days, $hour, $minute, $workshop, $seats, $times, $notes, $status ) = $r;
		$p       = $who( $slug );
		$queue[] = array(
			$when( $days, $hour, $minute ),
			$wait_id,
			array(
				$w['name']     => $p['name'],
				$w['email']    => $p['email'],
				$w['workshop'] => $workshop,
				$w['seats']    => (string) $seats,
				$w['phone']    => $p['phone'],
				$w['times']    => $times,
				$w['notes']    => $notes,
			),
			$status,
		);
	}

	// The kit waitlist opened when the kit went into the catalog as a draft.
	$kit_list = array(
		array( 'mia', 44.5, 11, 5, 'No rush. Just the kit, I have a fern that needs a new home.' ),
		array( 'isla', 33.0, 16, 20, 'Would it work with a small succulent? Ferns do not last a week in Santa Fe.' ),
		array( 'lucas', 19.2, 7, 55, 'Will you ship the kit with a live fern to Rhode Island, or is it pickup only?' ),
		array( 'grace', 8.6, 14, 40, '' ),
		array( 'zoe', 2.4, 20, 30, 'Please let me know the minute it is ready. I would like two as gifts.' ),
	);
	foreach ( $kit_list as $r ) {
		list( $slug, $days, $hour, $minute, $notes ) = $r;
		$p      = $who( $slug );
		$values = array(
			$kit_fields['textbox'] => $p['name'],
			$kit_fields['email']   => $p['email'],
		);
		if ( isset( $kit_fields['textarea'] ) ) {
			$values[ $kit_fields['textarea'] ] = $notes;
		}
		$queue[] = array( $when( $days, $hour, $minute ), $kit_id, $values, 'publish' );
	}
	usort(
		$queue,
		function ( $a, $b ) {
			return $a[0] - $b[0];
		}
	);
	$seq = array( $wait_id => 0, $kit_id => 0 );
	foreach ( $queue as $i => $q ) {
		list( $ts, $form_id, $values, $status ) = $q;
		$demo->remember( $group, 'sub-' . $i, $save( $form_id, $values, $ts, $status, ++$seq[ $form_id ] ) );
	}
	foreach ( $seq as $form_id => $last ) {
		$counter( $form_id, $last + 1 );
	}
	$wpdb->update( $wpdb->prefix . 'nf3_forms', array( 'created_at' => $demo->local( $demo->ago( 64, 9, 20 ) ) ), array( 'id' => $wait_id ) );
	$wpdb->update( $wpdb->prefix . 'nf3_forms', array( 'created_at' => $demo->local( $demo->ago( 47, 10, 5 ) ) ), array( 'id' => $kit_id ) );

	$demo->log( count( $waitlist ) . ' waitlist and ' . count( $kit_list ) . ' kit waitlist submissions' );
};
