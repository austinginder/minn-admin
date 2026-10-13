<?php
/**
 * WPForms Pro: the shop's Contact us form and the Plant Club application,
 * each with its notifications and confirmation, and two months of entries
 * (unread, read, starred, spam and a trashed test).
 *
 * A form is a `wpforms` post whose content is the whole form as JSON
 * (fields, settings.notifications, settings.confirmations); the form
 * handler's add() makes the post and update() writes that JSON. Entries are
 * rows in wpforms_entries written by the entry handler: `fields` is JSON
 * keyed by field id ({ name, value, id, type, plus the parts of a name or
 * address }), `date` is UTC, `status` is '' for the inbox, `viewed` and
 * `starred` are flags. The per-field rows in wpforms_entry_fields (what
 * WPForms searches) come from entry_fields->save(). Nothing here runs the
 * form's process, so no notification is sent. Opening an entry in Minn
 * marks it viewed: run this part again to put the unread ones back.
 *
 * @package minn-admin
 */

return function ( Minn_Demo_Seed $demo ) {
	global $wpdb;
	if ( ! function_exists( 'wpforms' ) || ! is_object( wpforms()->obj( 'entry' ) ) ) {
		$demo->log( 'WPForms Pro is not active: no WPForms entries' );
		return;
	}
	$demo->as_user( 'june' );
	// The forms are found again (by the id an earlier run kept, or by title)
	// and updated in place so their ids stay put; their entries go first,
	// through the entry handler, which clears entry_fields and entry_meta too.
	$existing = array();
	foreach ( get_posts( array( 'post_type' => 'wpforms', 'post_status' => 'any', 'posts_per_page' => -1 ) ) as $post ) {
		$existing[ $post->post_title ] = (int) $post->ID;
	}
	$found = array();
	foreach ( array( 'contact' => 'Contact us', 'club' => 'Plant Club application' ) as $key => $title ) {
		$id = (int) $demo->id( 'forms-wpforms', $key );
		if ( ! $id || 'wpforms' !== get_post_type( $id ) ) {
			$id = isset( $existing[ $title ] ) ? $existing[ $title ] : 0;
		}
		if ( $id ) {
			foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT entry_id FROM {$wpdb->prefix}wpforms_entries WHERE form_id = %d", $id ) ) as $entry_id ) {
				wpforms()->obj( 'entry' )->delete( (int) $entry_id );
			}
			$found[ $key ] = $id;
		}
	}

	$at = function ( $days, $hour, $minute = 0 ) use ( $demo ) {
		$ts = $demo->ago( $days, $hour, $minute );
		return $ts > $demo->now - 20 * MINUTE_IN_SECONDS ? $demo->now - ( 25 + $minute ) * MINUTE_IN_SECONDS : $ts;
	};
	$choices = function ( $list ) {
		$out = array();
		foreach ( array_values( $list ) as $i => $label ) {
			$out[ (string) ( $i + 1 ) ] = array( 'label' => $label, 'value' => '', 'image' => '', 'icon' => '' );
		}
		return $out;
	};
	$field = function ( $id, $type, $label, $extra = array() ) {
		return array_merge(
			array(
				'id'          => (string) $id,
				'type'        => $type,
				'label'       => $label,
				'description' => '',
				'size'        => 'medium',
				'css'         => '',
			),
			$extra
		);
	};

	// key => [ title, description, created days ago, fields, notifications, confirmation, submit text ]
	$forms = array(
		'contact' => array(
			'Contact us',
			'Questions about an order, the shop or anything green. We answer Tuesday to Saturday.',
			150,
			array(
				$field( 1, 'name', 'Name', array( 'format' => 'first-last', 'required' => '1' ) ),
				$field( 2, 'email', 'Email', array( 'required' => '1' ) ),
				$field( 3, 'phone', 'Phone', array( 'format' => 'us' ) ),
				$field( 4, 'select', 'What is this about?', array( 'choices' => $choices( array( 'An order', 'Visiting the shop', 'Workshops', 'Plant Club', 'Something else' ) ), 'style' => 'classic' ) ),
				$field( 5, 'text', 'Order number' ),
				$field( 6, 'textarea', 'Message', array( 'required' => '1' ) ),
			),
			array(
				'1' => array(
					'notification_name' => 'Shop inbox',
					'email'             => 'hello@larkspur.example',
					'carboncopy'        => '',
					'subject'           => 'Contact us: {field_id="4"} from {field_id="1"}',
					'sender_name'       => 'Larkspur & Fern',
					'sender_address'    => '{admin_email}',
					'replyto'           => '{field_id="2"}',
					'message'           => '{all_fields}',
				),
				'2' => array(
					'notification_name' => 'Auto-reply',
					'email'             => '{field_id="2"}',
					'carboncopy'        => '',
					'subject'           => 'We got your message',
					'sender_name'       => 'Larkspur & Fern',
					'sender_address'    => '{admin_email}',
					'replyto'           => 'hello@larkspur.example',
					'message'           => "Hi {field_id=\"1\" key=\"first\"},\n\nThanks for writing to Larkspur & Fern. One of us will answer within a working day (we are closed Sundays and Mondays).\n\nIf it is about an order, have the order number handy and we will sort it out quickly.\n\nJune and the team",
				),
			),
			array( 'type' => 'message', 'message' => '<p>Thanks for writing in! One of us will reply within a working day. For anything urgent, call the shop on (503) 555-0100.</p>', 'message_scroll' => '1', 'page' => '', 'redirect' => '' ),
			'Send message',
		),
		'club'    => array(
			'Plant Club application',
			'A new plant every month, chosen by us, with a care card and a pot that fits. Tell us about your home and we will pick well.',
			100,
			array(
				$field( 1, 'name', 'Name', array( 'format' => 'first-last', 'required' => '1' ) ),
				$field( 2, 'email', 'Email', array( 'required' => '1' ) ),
				$field( 3, 'phone', 'Phone', array( 'format' => 'us' ) ),
				$field( 4, 'address', 'Shipping address', array( 'scheme' => 'us', 'address2_hide' => '1', 'country_default' => 'US' ) ),
				$field( 5, 'radio', 'How would you like your plant?', array( 'choices' => $choices( array( 'Ship it to me', 'I will pick it up at Station Road' ) ) ) ),
				$field( 6, 'select', 'How much light do you have?', array( 'choices' => $choices( array( 'Bright and sunny', 'Bright, but no direct sun', 'Low light, north facing' ) ), 'style' => 'classic' ) ),
				$field( 7, 'checkbox', 'Who else lives with your plants?', array( 'choices' => $choices( array( 'Cats', 'Dogs', 'Small children', 'Just me' ) ) ) ),
				$field( 8, 'radio', 'How experienced are you?', array( 'choices' => $choices( array( 'Just starting out', 'A few plants, mostly alive', 'My home is a jungle' ) ) ) ),
				$field( 9, 'textarea', 'Tell us about your plants', array( 'description' => 'What you already have, what you love, what you have killed.' ) ),
			),
			array(
				'1' => array(
					'notification_name' => 'New member application',
					'email'             => 'june@larkspur.example',
					'carboncopy'        => 'mateo@larkspur.example',
					'subject'           => 'Plant Club application: {field_id="1"}',
					'sender_name'       => 'Larkspur & Fern',
					'sender_address'    => '{admin_email}',
					'replyto'           => '{field_id="2"}',
					'message'           => '{all_fields}',
				),
			),
			array( 'type' => 'page', 'message' => '', 'message_scroll' => '1', 'page' => (string) $demo->id( 'pages', 'plant-club' ), 'redirect' => '' ),
			'Apply to join',
		),
	);
	$form_ids = array();
	foreach ( $forms as $key => $f ) {
		list( $title, $desc, $days, $fields, $notifications, $confirmation, $submit ) = $f;
		$created = $demo->ago( $days, 11, 20 );
		$id      = isset( $found[ $key ] ) ? $found[ $key ] : wpforms()->obj( 'form' )->add(
			$title,
			array(
				'post_author'   => $demo->id( 'users', 'june' ),
				'post_date'     => $demo->local( $created ),
				'post_date_gmt' => $demo->gmt( $created ),
			),
			array( 'builder' => true )
		);
		if ( ! $id ) {
			WP_CLI::warning( "WPForms did not create the form {$title}." );
			continue;
		}
		$by_id = array();
		foreach ( $fields as $fd ) {
			$by_id[ $fd['id'] ] = $fd;
		}
		wpforms()->obj( 'form' )->update(
			$id,
			array(
				'id'       => (string) $id,
				'field_id' => (string) ( count( $fields ) + 1 ),
				'fields'   => $by_id,
				'settings' => array(
					'form_title'             => $title,
					'form_desc'              => $desc,
					'submit_text'            => $submit,
					'submit_text_processing' => 'Sending...',
					'antispam_v3'            => '1',
					'store_spam_entries'     => '1',
					'notification_enable'    => '1',
					'notifications'          => $notifications,
					'confirmations'          => array( '1' => array_merge( array( 'name' => 'Default Confirmation' ), $confirmation ) ),
				),
				'meta'     => array( 'template' => 'blank' ),
			),
			array( 'skip_revision' => true )
		);
		// The Forms view shows when a form last changed: when it was made.
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_author'       => $demo->id( 'users', 'june' ),
				'post_date'         => $demo->local( $created ),
				'post_date_gmt'     => $demo->gmt( $created ),
				'post_modified'     => $demo->local( $created ),
				'post_modified_gmt' => $demo->gmt( $created ),
			),
			array( 'ID' => $id )
		);
		clean_post_cache( $id );
		$form_ids[ $key ] = $demo->remember( 'forms-wpforms', $key, $id );
	}

	// Entries: form, who (cast slug or someone outside it), days ago, hour,
	// answers by field id, [ viewed, starred, status ].
	// An order of the shop part, by its key there, as WooCommerce numbers it.
	$order_no = function ( $key ) use ( $demo ) {
		$id    = (int) $demo->id( 'orders', $key );
		$order = $id && function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : null;
		return $order ? (string) $order->get_order_number() : '';
	};
	$outsider = function ( $first, $last, $email, $phone = '', $street = '', $city = '', $state = '', $postcode = '' ) {
		return array( 'id' => 0, 'first' => $first, 'last' => $last, 'name' => $first . ' ' . $last, 'email' => $email, 'phone' => $phone, 'street' => $street, 'city' => $city, 'state' => $state, 'postcode' => $postcode );
	};
	$entries = array(
		array( 'contact', 'zoe', 0.1, 9, array( 4 => 'An order', 5 => $order_no( '1066' ), 6 => "Just checking the gift note made it onto my order. It should say: Happy housewarming, Ada! Love, Z.\n\nAnd no prices on the packing slip, please. Thank you!" ), array( 0, 0, '' ) ),
		array( 'contact', 'aisha', 0.7, 10, array( 4 => 'An order', 5 => $order_no( '1065' ), 6 => 'I sent the bank transfer for my two saguaros this morning, with the order number as the reference. Could you let me know when it arrives so the order can go out?' ), array( 0, 1, '' ) ),
		array( 'contact', $outsider( 'Brandon', 'Lee', 'outreach@linkfarm.example' ), 1.3, 2, array( 4 => 'Something else', 6 => 'Hi! We publish guest posts on high authority gardening blogs. For $99 we will place a do-follow link to your shop. Interested?' ), array( 0, 0, 'spam' ) ),
		array( 'contact', 'kenji', 2.2, 18, array( 4 => 'Workshops', 6 => 'Is there parking near the shop on Saturday mornings? I am coming down from Vancouver for the terrarium workshop and would rather not circle the block for twenty minutes.' ), array( 1, 0, '' ) ),
		array( 'contact', 'olivia', 3.6, 12, array( 4 => 'Visiting the shop', 6 => 'Do you have the anthurium in a bigger pot than the one online? Looking for something about two feet tall for the living room. Happy to come in and pick one out.' ), array( 1, 1, '' ) ),
		array( 'contact', 'ethan', 6.4, 20, array( 4 => 'An order', 5 => $order_no( '1057' ), 6 => 'Any idea when the red hibiscus will be back in stock? I still want one before it gets cold. Could you email me when it is?' ), array( 1, 0, '' ) ),
		array( 'contact', 'lucas', 8.9, 16, array( 4 => 'Something else', 6 => 'Thank you for slipping an extra packet of sunflower seeds into our order. The kids planted them the same afternoon and check on them every morning.' ), array( 1, 1, '' ) ),
		array( 'contact', 'mia', 12.5, 11, array( 4 => 'Visiting the shop', 6 => 'What time do you open on Saturdays? I would like to pick up a peace lily for my sister before her birthday lunch.' ), array( 1, 0, '' ) ),
		array( 'contact', $outsider( 'Sasha', 'Petrakis', 'hello@pixelperfect.example' ), 15.0, 4, array( 4 => 'Something else', 6 => 'Your website looks outdated. We redesign small business sites in 7 days. Book a free call this week and get 50% off.' ), array( 0, 0, 'spam' ) ),
		array( 'contact', 'grace', 4.2, 15, array( 4 => 'An order', 5 => $order_no( '1060' ), 6 => 'The refund for the broken impatiens came through this morning. Thanks for sorting it out so quickly, and for sending a replacement anyway.' ), array( 1, 0, '' ) ),
		array( 'contact', 'noah', 21.3, 19, array( 4 => 'Something else', 6 => 'Do you ship the botanical prints flat or rolled? I would like to order the bellflower in 16 x 20 for my parents and want to know if it needs flattening.' ), array( 1, 0, '' ) ),
		array( 'contact', 'nadia', 28.7, 13, array( 4 => 'Plant Club', 6 => 'Could I give a Plant Club membership as a gift? My brother just moved into his first apartment and has nothing green in it.' ), array( 1, 0, '' ) ),
		array( 'contact', 'mateo', 35.0, 9, array( 4 => 'Something else', 6 => 'Testing the new phone field. Ignore me.' ), array( 1, 0, 'trash' ) ),
		array( 'contact', 'daniel', 47.2, 14, array( 4 => 'An order', 6 => 'If I place two orders this week, seeds now and a print on Friday, could you ship them together to save on postage?' ), array( 1, 0, '' ) ),

		array( 'club', 'isla', 0.5, 20, array( 5 => 'Ship it to me', 6 => 'Bright and sunny', 7 => array( 'Just me' ), 8 => 'A few plants, mostly alive', 9 => 'High desert sun all day in my kitchen. I have two cacti from you and a jade that is older than me. Would love something that flowers.' ), array( 0, 0, '' ) ),
		array( 'club', 'zoe', 1.8, 21, array( 5 => 'Ship it to me', 6 => 'Bright, but no direct sun', 7 => array( 'Cats' ), 8 => 'My home is a jungle', 9 => 'About forty plants in a Brooklyn apartment and one very curious cat, so pet safe only please. I would love some rarer things: hoyas, begonias, anything with interesting leaves.' ), array( 0, 1, '' ) ),
		array( 'club', 'chloe', 4.9, 17, array( 5 => 'Ship it to me', 6 => 'Bright and sunny', 7 => array( 'Small children', 'Dogs' ), 8 => 'A few plants, mostly alive', 9 => 'Applying for our classroom. Twenty second graders take turns watering, so tough plants only. The school pays, so please send the invoice to the front office.' ), array( 1, 1, '' ) ),
		array( 'club', 'kenji', 9.6, 12, array( 5 => 'I will pick it up at Station Road', 6 => 'Low light, north facing', 7 => array( 'Just me' ), 8 => 'Just starting out', 9 => 'My apartment faces north and gets very little sun. So far I have kept a pothos alive for a year, which I am proud of.' ), array( 1, 0, '' ) ),
		array( 'club', $outsider( 'Rosa', 'Delgado', 'rosa.delgado@example.net', '(971) 555-0143', '5420 N Interstate Avenue', 'Portland', 'OR', '97217' ), 13.2, 9, array( 5 => 'I will pick it up at Station Road', 6 => 'Bright, but no direct sun', 7 => array( 'Dogs' ), 8 => 'A few plants, mostly alive', 9 => 'I walk past the shop every day and finally want to commit. Our dog chews things, so nothing toxic please.' ), array( 1, 0, '' ) ),
		array( 'club', 'ben', 19.4, 20, array( 5 => 'Ship it to me', 6 => 'Bright and sunny', 7 => array( 'Small children' ), 8 => 'Just starting out', 9 => 'After the Sunflower Morning my kids want a plant of their own each month. Could the club plant be something they can help look after?' ), array( 1, 0, '' ) ),
		array( 'club', 'emma', 26.1, 18, array( 5 => 'Ship it to me', 6 => 'Bright, but no direct sun', 7 => array( 'Cats' ), 8 => 'My home is a jungle', 9 => 'I already have the anthurium and a calathea that loves me. Happy to try fussier plants.' ), array( 1, 1, '' ) ),
		array( 'club', 'lucas', 38.0, 13, array( 5 => 'Ship it to me', 6 => 'Low light, north facing', 7 => array( 'Small children', 'Cats' ), 8 => 'Just starting out', 9 => 'Gift for my partner, who has been asking for plants for months.' ), array( 1, 0, '' ) ),
		array( 'club', $outsider( 'Win', 'Free', 'claim@freeprize.example' ), 7.3, 3, array( 5 => 'Ship it to me', 9 => 'You have won a free iPhone. Click to claim your prize now.' ), array( 0, 0, 'spam' ) ),
	);
	$agents = array(
		'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
		'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
		'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:127.0) Gecko/20100101 Firefox/127.0',
	);
	// Oldest first, so entry ids rise with the dates as they would on a live site.
	uasort(
		$entries,
		function ( $a, $b ) use ( $demo ) {
			return $demo->ago( $a[2], $a[3] ) <=> $demo->ago( $b[2], $b[3] );
		}
	);
	$count = 0;
	foreach ( $entries as $n => $e ) {
		list( $key, $who, $days, $hour, $answers, $flags ) = $e;
		if ( empty( $form_ids[ $key ] ) ) {
			continue;
		}
		$form_id   = $form_ids[ $key ];
		$form_data = wpforms()->obj( 'form' )->get( $form_id, array( 'content_only' => true ) );
		$p         = is_array( $who ) ? $who : $demo->person( $who );
		$ts        = $at( $days, $hour, ( $n * 11 ) % 60 );
		$values    = array(
			1 => array( 'value' => $p['name'], 'first' => $p['first'], 'middle' => '', 'last' => $p['last'] ),
			2 => array( 'value' => $p['email'] ),
			3 => array( 'value' => 'spam' === $flags[2] ? '' : $p['phone'] ),
		);
		if ( 'club' === $key ) {
			$values[4] = '' === $p['street'] ? array( 'value' => '' ) : array(
				'value'    => $p['street'] . "\n" . $p['city'] . ', ' . $p['state'] . ' ' . $p['postcode'],
				'address1' => $p['street'],
				'address2' => '',
				'city'     => $p['city'],
				'state'    => $p['state'],
				'postal'   => $p['postcode'],
				'country'  => '',
			);
		}
		foreach ( $answers as $field_id => $value ) {
			$values[ $field_id ] = is_array( $value ) ? array( 'value' => implode( "\n", $value ), 'value_raw' => implode( "\n", $value ) ) : array( 'value' => $value );
			if ( in_array( $form_data['fields'][ $field_id ]['type'], array( 'select', 'radio' ), true ) ) {
				$values[ $field_id ]['value_raw'] = $value;
			}
		}
		$fields = array();
		foreach ( $form_data['fields'] as $field_id => $fd ) {
			$v                   = isset( $values[ $field_id ] ) ? $values[ $field_id ] : array( 'value' => '' );
			$fields[ $field_id ] = array_merge( array( 'name' => $fd['label'], 'value' => $v['value'], 'id' => (int) $field_id, 'type' => $fd['type'] ), array_diff_key( $v, array( 'value' => 1 ) ) );
		}
		$entry_id = wpforms()->obj( 'entry' )->add(
			array(
				'form_id'       => $form_id,
				'user_id'       => $p['id'] ? $p['id'] : 0,
				'status'        => $flags[2],
				'type'          => '',
				'viewed'        => $flags[0],
				'starred'       => $flags[1],
				'fields'        => wp_json_encode( $fields ),
				'meta'          => '',
				'date'          => $demo->gmt( $ts ),
				'date_modified' => $demo->gmt( $ts ),
				'ip_address'    => ( 'spam' === $flags[2] ? '203.0.113.' : '192.0.2.' ) . ( 40 + $n * 5 ),
				'user_agent'    => $agents[ $n % count( $agents ) ],
				'user_uuid'     => wp_generate_uuid4(),
			)
		);
		if ( ! $entry_id ) {
			continue;
		}
		$form_data['date'] = $demo->gmt( $ts );
		wpforms()->obj( 'entry_fields' )->save( $fields, $form_data, $entry_id );
		++$count;
	}
	$demo->log( count( $form_ids ) . ' WPForms forms, ' . $count . ' entries' );
};
