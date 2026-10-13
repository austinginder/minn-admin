<?php
/**
 * Formidable: the plant sitting request (holiday plant care while people
 * are away), with ten requests from the last two months.
 *
 * Formidable keeps forms in frm_forms and fields in frm_fields; each entry is
 * a frm_items row with one frm_item_metas row per answer (checkbox answers
 * serialized by their meta layer). Every stamp is UTC. Minn's surface only
 * registers once frm_items exists, so a site where Formidable never ran its
 * install gets FrmAppController::install() first (it also makes Formidable's
 * own "Contact Us" form, which stays as it is). Entries go through
 * FrmEntry::create, which runs the form's actions: this form has none, so no
 * notification goes out. There is no read state, spam or trash in Formidable
 * Lite; the IP and the meta stamps are set after the insert because
 * FrmEntry::create only takes them during an import.
 *
 * @package minn-admin
 */

return function ( Minn_Demo_Seed $demo ) {
	if ( ! class_exists( 'FrmEntry' ) || ! class_exists( 'FrmForm' ) ) {
		$demo->log( 'Formidable is not active: no Formidable forms' );
		return;
	}
	global $wpdb;
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'frm_items' ) ) !== $wpdb->prefix . 'frm_items' && class_exists( 'FrmAppController' ) ) {
		FrmAppController::install();
	}
	$group = 'forms-formidable';
	foreach ( $demo->ids( $group ) as $form_id ) {
		FrmForm::destroy( (int) $form_id );
	}
	$demo->forget( $group );
	$no_mail = function () {
		return false;
	};
	add_filter( 'pre_wp_mail', $no_mail );
	$demo->as_user( 'june' );

	$made    = $demo->ago( 80, 15, 40 );
	$form_id = FrmForm::create(
		array(
			'form_key'    => 'plant-sitting',
			'name'        => 'Plant sitting request',
			'description' => 'Going away? We visit, water and check on your plants while you are gone. Portland and Vancouver only.',
			'status'      => 'published',
			'created_at'  => $demo->gmt( $made ),
			'options'     => array(
				'submit_value' => 'Send request',
				'success_msg'  => 'Thanks! Mateo will call or email within a day to plan a walk-through.',
			),
		)
	);
	if ( ! $form_id ) {
		WP_CLI::warning( 'Formidable did not create the plant sitting form' );
		remove_filter( 'pre_wp_mail', $no_mail );
		return;
	}
	$demo->remember( $group, 'plant-sitting', (int) $form_id );

	// key => type, label, required, choices.
	$fields = array(
		'name'    => array( 'text', 'Name', 1, null ),
		'email'   => array( 'email', 'Email', 1, null ),
		'phone'   => array( 'phone', 'Phone', 0, null ),
		'address' => array( 'text', 'Street address', 1, null ),
		'dates'   => array( 'text', 'When will you be away?', 1, null ),
		'plants'  => array( 'number', 'Number of plants', 0, null ),
		'visits'  => array( 'radio', 'Visits per week', 0, array( 'Once a week', 'Twice a week', 'Every other day' ) ),
		'care'    => array( 'checkbox', 'Plants that need extra care', 0, array( 'Ferns or calatheas', 'Orchids', 'Seedlings or cuttings', 'Outdoor containers', 'Cacti and succulents (light watering only)' ) ),
		'notes'   => array( 'textarea', 'Notes for the sitter', 0, null ),
	);
	$field_ids = array();
	foreach ( $fields as $key => $f ) {
		$values             = FrmFieldsHelper::setup_new_vars( $f[0], $form_id );
		$values['name']     = $f[1];
		$values['required'] = $f[2];
		$values['field_key'] = 'sit-' . $key;
		if ( $f[3] ) {
			$values['options'] = $f[3];
		}
		$field_ids[ $key ] = (int) FrmField::create( $values );
	}

	// People who are not in the cast, all in Portland.
	$extra = array(
		'priscilla' => array( 'Priscilla Grant', 'priscilla.grant@example.com', '(503) 555-0151', '4318 SE Woodstock Boulevard' ),
		'tom'       => array( 'Tom Becker', 'tom.becker@example.net', '(503) 555-0152', '2710 NE 15th Avenue' ),
		'ines'      => array( 'Ines Duarte', 'ines.duarte@example.org', '(971) 555-0153', '5530 N Interstate Avenue' ),
		'callum'    => array( 'Callum Reid', 'callum.reid@example.com', '(503) 555-0154', '1925 SE Ankeny Street' ),
		'wren'      => array( 'Wren Holloway', 'wren.holloway@example.net', '(503) 555-0155', '815 NW 23rd Avenue' ),
		'dana'      => array( 'Dana Moss', 'dana.moss@example.org', '(503) 555-0156', '3344 SE Belmont Street' ),
	);
	// who, days ago, hour, when away, plants, visits, extra care, notes.
	$requests = array(
		array( 'priscilla', 56, 10, 'Two weeks, leaving the first of next month', 9, 'Twice a week', array( 'Ferns or calatheas' ), 'The key is in the lockbox by the back door and I will text the code. The maidenhair fern dries out fast, so please check it first.' ),
		array( 'nadia', 44, 19, 'Ten days over Thanksgiving', 6, 'Once a week', array(), 'I know Eugene is well outside your area. If you cannot cover it, could you recommend someone down here? I would happily pay for the drive.' ),
		array( 'tom', 37, 8, 'Ten days for a work trip', 3, 'Once a week', array( 'Cacti and succulents (light watering only)' ), 'Honestly they are all cacti and probably need nothing, but my partner insists. One visit in the middle should do it.' ),
		array( 'kenji', 29, 21, 'Three weeks visiting family in Japan', 22, 'Twice a week', array( 'Orchids', 'Seedlings or cuttings' ), 'Twenty-two plants, including a shelf of propagation jars. The orchids live in the bathroom for the humidity. I will leave a written schedule on the fridge.' ),
		array( 'ines', 21, 12, 'A long weekend, Friday to Monday', 5, 'Once a week', array( 'Outdoor containers' ), 'Mostly patio containers: tomatoes that are finishing up and two pots of lupine from your shop. If it gets over 85 they need water both days. Is that possible?' ),
		array( 'owen', 14, 9, 'The office is closed between Christmas and New Year', 12, 'Once a week', array(), 'Harrison Street Dental is closed for the holidays and the building manager can let you in. Mostly snake plants and peace lilies. Please do not overwater the snake plants.' ),
		array( 'callum', 8, 17, 'Thanksgiving week', 7, 'Every other day', array( 'Ferns or calatheas', 'Seedlings or cuttings' ), 'We are rooting a tray of cuttings under a humidity dome and they cannot dry out. Could you also bring in the mail?' ),
		array( 'olivia', 4, 20, 'Two weeks over the holidays', 14, 'Twice a week', array( 'Ferns or calatheas' ), 'My neighbor in 2238 has a key. The anthurium and peace lily from your shop are in the front window. The calathea is dramatic but fine.' ),
		array( 'wren', 1.6, 11, 'Five days next week', 2, 'Once a week', array(), 'Only two plants, but one is a big monstera I am very attached to. Would one visit be enough, or should I move it out of the south window first?' ),
		array( 'dana', 0.6, 13, 'Two weeks from Saturday', 11, 'Twice a week', array( 'Orchids' ), 'First time using a plant sitter. Could someone come by before I leave so I can show you around? Happy to pay for the extra visit.' ),
	);
	$count = 0;
	foreach ( $requests as $n => $r ) {
		if ( isset( $extra[ $r[0] ] ) ) {
			list( $name, $email, $phone, $street ) = $extra[ $r[0] ];
			$user_id = 0;
		} else {
			$p       = $demo->person( $r[0] );
			$name    = $p['name'];
			$email   = $p['email'];
			$phone   = $p['phone'];
			$street  = $p['street'] . ', ' . $p['city'];
			$user_id = (int) $p['id'];
		}
		$ts   = $demo->ago( $r[1], $r[2], ( $n * 17 ) % 60 );
		$meta = array(
			$field_ids['name']    => $name,
			$field_ids['email']   => $email,
			$field_ids['phone']   => $phone,
			$field_ids['address'] => $street,
			$field_ids['dates']   => $r[3],
			$field_ids['plants']  => (string) $r[4],
			$field_ids['visits']  => $r[5],
			$field_ids['notes']   => $r[7],
		);
		if ( $r[6] ) {
			$meta[ $field_ids['care'] ] = $r[6];
		}
		$entry_id = FrmEntry::create(
			array(
				'form_id'     => $form_id,
				'item_key'    => 'plant-sit-' . ( $n + 1 ),
				'created_at'  => $demo->gmt( $ts ),
				'description' => array(
					'browser'  => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
					'referrer' => 'https://larkspur.example/plant-sitting/',
				),
				'item_meta'   => $meta,
			)
		);
		if ( ! $entry_id ) {
			continue;
		}
		$wpdb->update(
			$wpdb->prefix . 'frm_items',
			array( 'ip' => '203.0.113.' . ( 40 + $n ), 'user_id' => $user_id, 'updated_by' => $user_id, 'updated_at' => $demo->gmt( $ts ) ),
			array( 'id' => $entry_id )
		);
		$wpdb->update( $wpdb->prefix . 'frm_item_metas', array( 'created_at' => $demo->gmt( $ts ) ), array( 'item_id' => $entry_id ) );
		++$count;
	}
	if ( class_exists( 'FrmEntry' ) && method_exists( 'FrmEntry', 'clear_cache' ) ) {
		FrmEntry::clear_cache();
	}
	remove_filter( 'pre_wp_mail', $no_mail );
	$demo->log( '1 Formidable form, ' . $count . ' entries' );
};
