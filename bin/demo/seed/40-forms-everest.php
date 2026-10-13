<?php
/**
 * Everest Forms: the corporate gifting inquiry (client and staff gifts,
 * succulents and prints in bulk), with nine inquiries, one of them spam and
 * one a double submission sent to the trash.
 *
 * Everest keeps a form as an everest_form post whose post_content is the
 * whole form as JSON (form_fields keyed by field id, each with the
 * "meta-key" its answers are stored under; the JSON carries its own post id).
 * A submission is an evf_entries row (date_created in UTC, status publish,
 * spam or trash, viewed and starred flags, the answers again as JSON in
 * `fields`) plus one evf_entrymeta row per answer keyed by meta-key. A
 * trashed entry remembers its old status in _evf_trash_entry_status. The
 * rows are written directly, the way their form task writes them, so no
 * notification runs. Their own "Contact Form" made on activation stays.
 *
 * @package minn-admin
 */

return function ( Minn_Demo_Seed $demo ) {
	if ( ! defined( 'EVF_VERSION' ) ) {
		$demo->log( 'Everest Forms is not active: no Everest forms' );
		return;
	}
	global $wpdb;
	$entries_t = $wpdb->prefix . 'evf_entries';
	$meta_t    = $wpdb->prefix . 'evf_entrymeta';
	$group     = 'forms-everest';
	foreach ( $demo->ids( $group ) as $form_id ) {
		$old = $wpdb->get_col( $wpdb->prepare( "SELECT entry_id FROM {$entries_t} WHERE form_id = %d", (int) $form_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $old ) {
			$wpdb->query( "DELETE FROM {$meta_t} WHERE entry_id IN (" . implode( ',', array_map( 'intval', $old ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DELETE FROM {$entries_t} WHERE entry_id IN (" . implode( ',', array_map( 'intval', $old ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		wp_delete_post( (int) $form_id, true );
	}
	$demo->forget( $group );
	$demo->as_user( 'june' );

	// id => type, label, required, choices.
	$fields = array(
		'name'     => array( 'text', 'Name', '1', null ),
		'company'  => array( 'text', 'Company or organization', '1', null ),
		'email'    => array( 'email', 'Email', '1', null ),
		'phone'    => array( 'phone', 'Phone', '', null ),
		'gift'     => array( 'select', 'What are you thinking of?', '1', array( 'Potted succulents', 'Botanical prints', 'Seed packets', 'Plant Club gift memberships', 'Mixed gift boxes' ) ),
		'quantity' => array( 'number', 'How many gifts?', '1', null ),
		'budget'   => array( 'select', 'Budget per gift', '', array( 'Under $25', '$25 to $50', '$50 to $100', 'Over $100' ) ),
		'deadline' => array( 'text', 'When do you need them?', '', null ),
		'message'  => array( 'textarea', 'Tell us about the occasion', '', null ),
	);
	$form_fields = array();
	foreach ( $fields as $id => $f ) {
		$form_fields[ $id ] = array(
			'id'          => $id,
			'type'        => $f[0],
			'label'       => $f[1],
			'meta-key'    => $id,
			'description' => '',
			'required'    => $f[2],
			'placeholder' => '',
			'css'         => '',
		);
		if ( $f[3] ) {
			$choices = array();
			foreach ( $f[3] as $i => $choice ) {
				$choices[ $i + 1 ] = array( 'label' => $choice, 'value' => '' );
			}
			$form_fields[ $id ]['choices'] = $choices;
		}
	}
	$made    = $demo->ago( 75, 11, 5 );
	$form_id = wp_insert_post(
		array(
			'post_type'     => 'everest_form',
			'post_status'   => 'publish',
			'post_title'    => 'Corporate gifting inquiry',
			'post_author'   => $demo->id( 'users', 'june' ),
			'post_date'     => $demo->local( $made ),
			'post_date_gmt' => $demo->gmt( $made ),
		)
	);
	if ( ! $form_id || is_wp_error( $form_id ) ) {
		WP_CLI::warning( 'Everest Forms: could not create the gifting form' );
		return;
	}
	$form = array(
		'id'            => $form_id,
		'form_field_id' => (string) count( $form_fields ),
		'form_fields'   => $form_fields,
		'settings'      => array(
			'form_title'                         => 'Corporate gifting inquiry',
			'form_desc'                          => 'Gifts for clients, staff and volunteers: potted succulents, prints, seeds and Plant Club memberships, with a card from you.',
			'successful_form_submission_message' => 'Thanks! Mateo will send ideas and a quote within two business days.',
			'redirect_to'                        => 'same',
			'layout_class'                       => 'default',
			'submit_button_text'                 => 'Send inquiry',
			'honeypot'                           => '1',
			'email'                              => array(
				'connection_1' => array(
					'connection_name'   => 'Gifting inquiry to the shop',
					'evf_to_email'      => 'hello@larkspur.example',
					'evf_from_name'     => 'Larkspur & Fern',
					'evf_from_email'    => '{admin_email}',
					'evf_reply_to'      => '{field_id="email"}',
					'evf_email_subject' => 'Gifting inquiry from {field_id="company"}',
					'evf_email_message' => '{all_fields}',
				),
			),
		),
		'structure'     => array( 'row_1' => array( 'grid_1' => array_keys( $form_fields ) ) ),
	);
	$wpdb->update( $wpdb->posts, array( 'post_content' => wp_json_encode( $form ), 'post_modified' => $demo->local( $made ), 'post_modified_gmt' => $demo->gmt( $made ) ), array( 'ID' => $form_id ) );
	clean_post_cache( $form_id );
	$demo->remember( $group, 'gifting', (int) $form_id );

	// People who are not in the cast.
	$extra = array(
		'marta'     => array( 'Marta Solis', 'Bridgeway Architects', 'marta.solis@bridgeway.example', '(503) 555-0161' ),
		'silk'      => array( 'Kevin Zhou', 'Silk Leaf Imports', 'sales@silkleaf.example', '' ),
		'kofi'      => array( 'Kofi Oduya', 'Fernhill Credit Union', 'k.oduya@fernhillcu.example', '(503) 555-0162' ),
		'tidewater' => array( 'Hollis Grey', 'Tidewater Coffee Roasters', 'hollis@tidewatercoffee.example', '(971) 555-0163' ),
		'hawthorne' => array( 'Rae Jimenez', 'Hawthorne Bike Works', 'rae@hawthornebikes.example', '(503) 555-0164' ),
	);
	// who, days ago, hour, gift, quantity, budget, deadline, message, status, viewed, starred.
	$inquiries = array(
		array( 'marta', 57, 14, 'Potted succulents', 30, 'Under $25', 'Before our open house on the 15th', 'We are opening our new studio to clients and neighbors and want a small potted succulent at every place setting. Could the pots be plain white so they match our tables?', 'publish', 1, 0 ),
		array( 'aisha', 49, 10, 'Botanical prints', 12, '$50 to $100', 'Within three weeks', 'I run a small interior design studio in Chicago and want to send prints to clients who finished projects this year. The bellflower and phacelia prints would be perfect. Do you frame them, or should I have them framed here?', 'publish', 1, 1 ),
		array( 'silk', 42, 3, 'Mixed gift boxes', 5000, 'Under $25', 'As soon as possible', 'We manufacture artificial plants at factory prices with a minimum order of 500 pieces. Reply for our full catalog and price list.', 'spam', 0, 0 ),
		array( 'kofi', 35, 11, 'Seed packets', 250, 'Under $25', 'For our spring member event', 'We would like a small giveaway for members at our spring event: seed packets with our name on a sticker. The wildflower meadow mix or the sunflowers would fit our garden theme.', 'publish', 1, 0 ),
		array( 'sofia', 26, 16, 'Plant Club gift memberships', 6, '$50 to $100', 'By the end of the month', 'Six people on my team just hit a big milestone and they all keep plants on their desks. Can I buy three-month Plant Club memberships as gifts and have the welcome note come from me?', 'publish', 1, 0 ),
		array( 'ruth', 18, 13, 'Mixed gift boxes', 25, '$50 to $100', 'Before the gala on the 28th', 'Thank-you gifts for the volunteers who run our gala at Lovejoy Hall. Something that will survive a week on a desk, with a small card about the shop.', 'publish', 1, 1 ),
		array( 'tidewater', 9.01, 15, 'Potted succulents', 60, '$25 to $50', 'First week of December', 'Holiday gifts for our wholesale cafe accounts. We would like small succulents planted in a ceramic cup, ideally in our own mugs if we send them to you.', 'trash', 1, 0 ),
		array( 'tidewater', 9, 15, 'Potted succulents', 60, '$25 to $50', 'First week of December', 'Holiday gifts for our wholesale cafe accounts. We would like small succulents planted in a ceramic cup, ideally in our own mugs if we send them to you. (Sorry, the first one went before I added this: we have 60 mugs ready.)', 'publish', 0, 0 ),
		array( 'owen', 3, 10, 'Potted succulents', 15, 'Under $25', 'Mid December', 'Small holiday gifts for our staff and two referring offices. A succulent with a handwritten card would be perfect. Could you deliver to the office on Harrison Street?', 'publish', 0, 0 ),
		array( 'hawthorne', 0.3, 6, 'Mixed gift boxes', 40, '$25 to $50', 'Before our ten year party next month', 'We turn ten next month and want to thank our regulars. Something green that can ride home in a bike basket. Open to ideas!', 'publish', 0, 0 ),
	);
	$devices = array( 'Chrome/Windows/Desktop', 'Safari/Macintosh/Desktop', 'Safari/iOS/Mobile', 'Firefox/Linux/Desktop' );
	$count   = 0;
	foreach ( $inquiries as $n => $r ) {
		if ( isset( $extra[ $r[0] ] ) ) {
			list( $name, $company, $email, $phone ) = $extra[ $r[0] ];
			$user_id = 0;
		} else {
			$p       = $demo->person( $r[0] );
			$name    = $p['name'];
			$company = $p['company'] ? $p['company'] : array( 'aisha' => 'Khan Interiors', 'sofia' => 'Alameda Analytics' )[ $r[0] ];
			$email   = $p['email'];
			$phone   = $p['phone'];
			$user_id = (int) $p['id'];
		}
		$answers = array(
			'name'     => $name,
			'company'  => $company,
			'email'    => $email,
			'phone'    => $phone,
			'gift'     => $r[3],
			'quantity' => (string) $r[4],
			'budget'   => $r[5],
			'deadline' => $r[6],
			'message'  => $r[7],
		);
		$json = array();
		foreach ( $answers as $id => $value ) {
			$json[ $id ] = array(
				'name'     => $fields[ $id ][1],
				'value'    => $value,
				'id'       => $id,
				'type'     => $fields[ $id ][0],
				'meta_key' => $id,
			);
			if ( 'select' === $fields[ $id ][0] ) {
				$json[ $id ]['value_raw'] = $value;
			}
		}
		$ts = $demo->ago( $r[1], $r[2], ( $n * 11 ) % 60 );
		$wpdb->insert(
			$entries_t,
			array(
				'form_id'         => $form_id,
				'user_id'         => $user_id,
				'user_device'     => $devices[ $n % count( $devices ) ],
				'user_ip_address' => '203.0.113.' . ( 60 + $n ),
				'referer'         => 'https://larkspur.example/corporate-gifts/',
				'fields'          => wp_json_encode( $json ),
				'status'          => $r[8],
				'viewed'          => $r[9],
				'starred'         => $r[10],
				'date_created'    => $demo->gmt( $ts ),
			)
		);
		$entry_id = (int) $wpdb->insert_id;
		if ( ! $entry_id ) {
			continue;
		}
		foreach ( $answers as $id => $value ) {
			if ( '' !== $value ) {
				$wpdb->insert( $meta_t, array( 'entry_id' => $entry_id, 'meta_key' => $id, 'meta_value' => $value ) );
			}
		}
		if ( 'trash' === $r[8] ) {
			$wpdb->insert( $meta_t, array( 'entry_id' => $entry_id, 'meta_key' => '_evf_trash_entry_status', 'meta_value' => 'publish' ) );
		}
		++$count;
	}
	wp_cache_flush_group( 'evf-entry' );
	wp_cache_flush_group( 'evf-entrymeta' );
	$demo->log( '1 Everest form, ' . $count . ' entries' );
};
