<?php
/**
 * Gravity Forms, the flagship of the forms family: a wholesale inquiry, an
 * event and venue inquiry and a plant care question (each with
 * notifications and confirmations, one of them conditional and one switched
 * off), last winter's holiday wreath pre-orders as an inactive form, and a
 * month or two of entries with staff notes, spam and a trashed duplicate.
 *
 * Entries go in through GFAPI::add_entry, which saves without running the
 * form's notifications (only a real submission does). Forms, entries and
 * notes are stamped "now" by Gravity Forms and every one of its dates is
 * UTC, so the dates are set on the rows afterwards. Note authors are plain
 * strings (user_name), and a notification's sending note is a note with
 * note_type "notification" named "<notification> (ID: <id>)", the way GF
 * logs a sent email. Opening an entry in Minn marks it read: run this part
 * again to put the unread ones back.
 *
 * @package minn-admin
 */

return function ( Minn_Demo_Seed $demo ) {
	global $wpdb;
	if ( ! class_exists( 'GFAPI' ) ) {
		$demo->log( 'Gravity Forms is not active: no Gravity Forms forms' );
		return;
	}
	$demo->as_user( 'june' );

	// Entry and note dates: never in the future, whatever hour the seed runs.
	$at = function ( $days, $hour, $minute = 0 ) use ( $demo ) {
		$ts = $demo->ago( $days, $hour, $minute );
		return $ts > $demo->now - 20 * MINUTE_IN_SECONDS ? $demo->now - ( 20 + $minute ) * MINUTE_IN_SECONDS : $ts;
	};
	$agents = array(
		'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
		'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
		'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
		'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Mobile Safari/537.36',
	);

	// Field builders, in Gravity Forms' own shapes.
	$name = function ( $id, $label = 'Name' ) {
		return array(
			'type'       => 'name',
			'id'         => $id,
			'label'      => $label,
			'isRequired' => true,
			'nameFormat' => 'advanced',
			'inputs'     => array(
				array( 'id' => $id . '.2', 'label' => 'Prefix', 'name' => '', 'isHidden' => true ),
				array( 'id' => $id . '.3', 'label' => 'First', 'name' => '' ),
				array( 'id' => $id . '.4', 'label' => 'Middle', 'name' => '', 'isHidden' => true ),
				array( 'id' => $id . '.6', 'label' => 'Last', 'name' => '' ),
				array( 'id' => $id . '.8', 'label' => 'Suffix', 'name' => '', 'isHidden' => true ),
			),
		);
	};
	$address = function ( $id, $label ) {
		return array(
			'type'         => 'address',
			'id'           => $id,
			'label'        => $label,
			'addressType'  => 'us',
			'defaultState' => 'Oregon',
			'inputs'       => array(
				array( 'id' => $id . '.1', 'label' => 'Street Address', 'name' => '' ),
				array( 'id' => $id . '.2', 'label' => 'Address Line 2', 'name' => '', 'isHidden' => true ),
				array( 'id' => $id . '.3', 'label' => 'City', 'name' => '' ),
				array( 'id' => $id . '.4', 'label' => 'State', 'name' => '' ),
				array( 'id' => $id . '.5', 'label' => 'ZIP Code', 'name' => '' ),
				array( 'id' => $id . '.6', 'label' => 'Country', 'name' => '', 'isHidden' => true ),
			),
		);
	};
	$field = function ( $type, $id, $label, $extra = array() ) {
		return array_merge( array( 'type' => $type, 'id' => $id, 'label' => $label ), $extra );
	};
	$choices = function ( $list ) {
		$out = array();
		foreach ( $list as $text ) {
			$out[] = array( 'text' => $text, 'value' => $text, 'isSelected' => false );
		}
		return $out;
	};
	$checkbox = function ( $id, $label, $list, $extra = array() ) use ( $choices ) {
		$inputs = array();
		foreach ( array_values( $list ) as $i => $text ) {
			$inputs[] = array( 'id' => $id . '.' . ( $i + 1 ), 'label' => $text, 'name' => '' );
		}
		return array_merge( array( 'type' => 'checkbox', 'id' => $id, 'label' => $label, 'choices' => $choices( $list ), 'inputs' => $inputs ), $extra );
	};
	$us_states = array( 'OR' => 'Oregon', 'WA' => 'Washington', 'CA' => 'California', 'NY' => 'New York', 'MA' => 'Massachusetts', 'IL' => 'Illinois', 'TX' => 'Texas', 'CO' => 'Colorado', 'MN' => 'Minnesota', 'WI' => 'Wisconsin', 'GA' => 'Georgia', 'NC' => 'North Carolina', 'RI' => 'Rhode Island', 'PA' => 'Pennsylvania', 'VT' => 'Vermont', 'NM' => 'New Mexico' );

	$notification = function ( $id, $name, $to, $subject, $message, $active = true, $extra = array() ) {
		return array_merge(
			array(
				'id'                => $id,
				'name'              => $name,
				'service'           => 'wordpress',
				'event'             => 'form_submission',
				'toType'            => 'email',
				'to'                => $to,
				'from'              => 'hello@larkspur.example',
				'fromName'          => 'Larkspur & Fern',
				'subject'           => $subject,
				'message'           => $message,
				'isActive'          => $active,
				'disableAutoformat' => false,
			),
			$extra
		);
	};
	$confirmation = function ( $id, $name, $default, $message, $extra = array() ) {
		return array_merge(
			array(
				'id'          => $id,
				'name'        => $name,
				'isDefault'   => $default,
				'type'        => 'message',
				'message'     => $message,
				'url'         => '',
				'pageId'      => '',
				'queryString' => '',
			),
			$extra
		);
	};

	// The forms: key => [ form object, created days ago ].
	$forms = array(
		'wholesale' => array(
			array(
				'title'         => 'Wholesale inquiry',
				'description'   => 'For florists, cafes, offices and shops buying plants, seeds and prints by the dozen. Mateo answers within two working days.',
				'labelPlacement' => 'top_label',
				'button'        => array( 'type' => 'text', 'text' => 'Send inquiry' ),
				'fields'        => array(
					$name( 1 ),
					$field( 'text', 2, 'Business name', array( 'isRequired' => true ) ),
					$field( 'email', 3, 'Email', array( 'isRequired' => true ) ),
					$field( 'phone', 4, 'Phone', array( 'phoneFormat' => 'standard' ) ),
					$address( 5, 'Business address' ),
					$field( 'select', 6, 'Type of business', array( 'placeholder' => 'Choose one', 'choices' => $choices( array( 'Florist', 'Cafe or restaurant', 'Office', 'Retail shop', 'Event planner', 'Other' ) ) ) ),
					$checkbox( 7, 'What are you interested in?', array( 'Houseplants', 'Cacti & succulents', 'Perennials', 'Seeds & bulbs', 'Botanical prints', 'Workshops for your team' ) ),
					$field( 'select', 8, 'Expected order size', array( 'placeholder' => 'Choose one', 'choices' => $choices( array( 'Under $250 a month', '$250 to $1,000 a month', 'Over $1,000 a month' ) ) ) ),
					$field( 'textarea', 9, 'Tell us about your business', array( 'isRequired' => true ) ),
					$field( 'radio', 10, 'Best way to reach you', array( 'choices' => $choices( array( 'Email', 'Phone' ) ) ) ),
				),
				'notifications' => array(
					$notification( '5f1a2b3c4d5e1', 'Wholesale team', 'mateo@larkspur.example', 'Wholesale inquiry from {Business name:2}', "{Name (First):1.3} {Name (Last):1.6} from {Business name:2} wrote in.\n\n{all_fields}", true, array( 'replyTo' => '{Email:3}' ) ),
					$notification( '5f1a2b3c4d5e2', 'Thank-you email', '3', 'Thanks for getting in touch, {Name (First):1.3}', "Hi {Name (First):1.3},\n\nThanks for your interest in buying from Larkspur & Fern. Mateo looks after wholesale and will reply within two working days with our price list and pickup days.\n\nJune, Mateo and the team\n112 Station Road, Portland", true, array( 'toType' => 'field' ) ),
					$notification( '5f1a2b3c4d5e3', 'Copy to June', 'june@larkspur.example', 'Wholesale: {Business name:2} ({Expected order size:8})', '{all_fields}', false ),
				),
				'confirmations' => array(
					$confirmation( '5f1a2b3c4d5f1', 'Default Confirmation', true, 'Thanks, {Name (First):1.3}! Mateo reads wholesale inquiries on Tuesdays and Fridays and will be in touch within two working days.' ),
					$confirmation(
						'5f1a2b3c4d5f2',
						'Larger accounts',
						false,
						'Thanks, {Name (First):1.3}. For orders this size June likes to talk in person: expect a call this week to set up a visit to the greenhouse.',
						array(
							'conditionalLogic' => array(
								'actionType' => 'show',
								'logicType'  => 'all',
								'rules'      => array( array( 'fieldId' => '8', 'operator' => 'is', 'value' => 'Over $1,000 a month' ) ),
							),
						)
					),
				),
			),
			140,
		),
		'events'    => array(
			array(
				'title'         => 'Event & venue inquiry',
				'description'   => 'Plant rentals, installs, centerpieces and living favors for weddings, venues, shoots and parties around Portland.',
				'labelPlacement' => 'top_label',
				'button'        => array( 'type' => 'text', 'text' => 'Ask about your event' ),
				'fields'        => array(
					$name( 1 ),
					$field( 'email', 2, 'Email', array( 'isRequired' => true ) ),
					$field( 'phone', 3, 'Phone', array( 'phoneFormat' => 'standard' ) ),
					$field( 'text', 4, 'Organization or venue' ),
					$field( 'select', 5, 'Event type', array( 'placeholder' => 'Choose one', 'choices' => $choices( array( 'Wedding', 'Corporate event', 'Private party', 'Venue styling', 'Photo shoot', 'Other' ) ) ) ),
					$field( 'date', 6, 'Event date', array( 'dateType' => 'datepicker', 'dateFormat' => 'mdy', 'calendarIconType' => 'calendar' ) ),
					$field( 'number', 7, 'Number of guests', array( 'numberFormat' => 'decimal_dot' ) ),
					$checkbox( 8, 'What do you need?', array( 'Plant rental', 'Installation and pickup', 'Centerpieces', 'Living favors', 'A workshop for guests' ) ),
					$address( 9, 'Venue address' ),
					$field( 'textarea', 10, 'Tell us about your event', array( 'isRequired' => true ) ),
				),
				'notifications' => array(
					$notification( '5f1a2b3c4d6e1', 'Events inbox', 'hello@larkspur.example', 'Event inquiry: {Event type:5} on {Event date:6}', '{all_fields}', true, array( 'replyTo' => '{Email:2}', 'cc' => 'june@larkspur.example' ) ),
					$notification( '5f1a2b3c4d6e2', 'Auto-reply', '2', 'We got your event inquiry', "Hi {Name (First):1.3},\n\nThanks for thinking of us for your {Event type:5}. June will reply within a day or two with ideas and a rough quote. If your date is less than two weeks away, call the shop on (503) 555-0100.\n\nLarkspur & Fern", true, array( 'toType' => 'field' ) ),
				),
				'confirmations' => array(
					$confirmation( '5f1a2b3c4d6f1', 'Default Confirmation', true, '', array( 'type' => 'page', 'pageId' => (string) $demo->id( 'pages', 'contact' ) ) ),
					$confirmation( '5f1a2b3c4d6f2', 'Workshop for guests', false, 'Thanks! Priya plans every group workshop herself, so she will be in touch about dates, numbers and what everyone will make.', array(
						'conditionalLogic' => array(
							'actionType' => 'show',
							'logicType'  => 'any',
							'rules'      => array( array( 'fieldId' => '8', 'operator' => 'is', 'value' => 'A workshop for guests' ) ),
						),
					) ),
				),
			),
			120,
		),
		'care'      => array(
			array(
				'title'         => 'Plant care question',
				'description'   => 'Something not looking right? Tell us what you see and Priya or Hana will write back, usually the same day.',
				'labelPlacement' => 'top_label',
				'button'        => array( 'type' => 'text', 'text' => 'Ask the plant desk' ),
				'fields'        => array(
					$name( 1 ),
					$field( 'email', 2, 'Email', array( 'isRequired' => true ) ),
					$field( 'phone', 3, 'Phone' , array( 'phoneFormat' => 'standard' ) ),
					$field( 'select', 4, 'Which plant?', array( 'placeholder' => 'Choose one', 'choices' => $choices( array( "Anthurium 'Red Heart'", 'Peace Lily', 'Golden Saguaro', 'Red Hibiscus', 'Calla lily', 'Wild Columbine', 'Lupine', 'Something else' ) ) ) ),
					$field( 'radio', 5, 'Did it come from us?', array( 'choices' => $choices( array( 'Yes', 'No', 'It was a gift' ) ) ) ),
					$checkbox( 6, 'What are you seeing?', array( 'Yellow leaves', 'Brown tips', 'Drooping', 'Spots or patches', 'Pests', 'No new growth' ) ),
					$field( 'select', 7, 'Where does it live?', array( 'placeholder' => 'Choose one', 'choices' => $choices( array( 'Sunny window', 'Bright, indirect light', 'Low light', 'Outdoors' ) ) ) ),
					$field( 'textarea', 8, 'Tell us what is going on', array( 'isRequired' => true ) ),
					$field( 'text', 9, 'Order number, if you have it' ),
				),
				'notifications' => array(
					$notification( '5f1a2b3c4d7e1', 'Plant desk', 'priya@larkspur.example', 'Plant question: {Which plant?:4}', "{Name (First):1.3} asks about their {Which plant?:4}.\n\n{all_fields}", true, array( 'replyTo' => '{Email:2}', 'cc' => 'hana@larkspur.example' ) ),
					$notification( '5f1a2b3c4d7e2', 'Copy to the asker', '2', 'Your plant question', "Hi {Name (First):1.3},\n\nWe have your question about your {Which plant?:4} and will write back soon. In the meantime, our care guides are on the journal.\n\nPriya and Hana", false, array( 'toType' => 'field' ) ),
				),
				'confirmations' => array(
					$confirmation( '5f1a2b3c4d7f1', 'Default Confirmation', true, 'Thanks, {Name (First):1.3}. Your question is with the plant desk. We usually answer the same day, Tuesday to Saturday.' ),
					$confirmation( '5f1a2b3c4d7f2', 'Pests first aid', false, 'While you wait: move the plant away from the others and wipe the leaves with a damp cloth. We will follow up with what to do next.', array(
						'isActive'         => false,
						'conditionalLogic' => array(
							'actionType' => 'show',
							'logicType'  => 'any',
							'rules'      => array( array( 'fieldId' => '6', 'operator' => 'is', 'value' => 'Pests' ) ),
						),
					) ),
				),
			),
			90,
		),
		'wreaths'   => array(
			array(
				'title'         => 'Holiday wreath pre-orders',
				'description'   => 'Fresh cedar and noble fir wreaths, made in the shop. Pick up the first or second weekend of December.',
				'labelPlacement' => 'top_label',
				'button'        => array( 'type' => 'text', 'text' => 'Reserve my wreath' ),
				'fields'        => array(
					$name( 1 ),
					$field( 'email', 2, 'Email', array( 'isRequired' => true ) ),
					$field( 'phone', 3, 'Phone', array( 'phoneFormat' => 'standard' ) ),
					$field( 'select', 4, 'Wreath', array( 'placeholder' => 'Choose one', 'choices' => $choices( array( 'Small, 14 inch ($45)', 'Large, 22 inch ($75)', 'Door swag ($38)' ) ) ) ),
					$field( 'number', 5, 'How many?', array( 'numberFormat' => 'decimal_dot' ) ),
					$field( 'radio', 6, 'Pickup weekend', array( 'choices' => $choices( array( 'December 5 and 6', 'December 12 and 13' ) ) ) ),
					$field( 'textarea', 7, 'Anything else?' ),
				),
				'notifications' => array(
					$notification( '5f1a2b3c4d8e1', 'Admin Notification', '{admin_email}', 'New wreath pre-order', '{all_fields}' ),
				),
				'confirmations' => array(
					$confirmation( '5f1a2b3c4d8f1', 'Default Confirmation', true, 'Your wreath is reserved. We will email you the week before pickup.' ),
				),
			),
			330,
		),
	);
	// Each form is found again (by the id an earlier run kept, or by its
	// title) and updated in place, so its id and URL stay put; its entries,
	// with their meta and notes, go before the new ones are added.
	$form_ids = array();
	foreach ( $forms as $key => $f ) {
		$id = (int) $demo->id( 'forms-gravity', $key );
		if ( ! $id || ! GFAPI::form_id_exists( $id ) ) {
			$id = 0;
			foreach ( GFFormsModel::get_forms( null, 'title' ) as $existing ) {
				if ( $existing->title === $f[0]['title'] ) {
					$id = (int) $existing->id;
					break;
				}
			}
		}
		if ( $id ) {
			GFFormsModel::delete_entries_by_form( $id );
			$result = GFAPI::update_form( $f[0], $id );
			GFFormsModel::update_form_active( $id, true );
		} else {
			$result = GFAPI::add_form( $f[0] );
			$id     = $result;
		}
		if ( is_wp_error( $result ) ) {
			WP_CLI::warning( 'Gravity Forms: ' . $result->get_error_message() );
			continue;
		}
		$wpdb->update( $wpdb->prefix . 'gf_form', array( 'date_created' => $demo->gmt( $demo->ago( $f[1], 9, 15 ) ) ), array( 'id' => $id ) );
		$form_ids[ $key ] = $demo->remember( 'forms-gravity', $key, $id );
	}
	if ( isset( $form_ids['wreaths'] ) ) {
		GFFormsModel::update_form_active( $form_ids['wreaths'], false );
	}

	// Entries: form, who (cast slug, or an array for someone outside it),
	// days ago, hour, answers by field id, [ read, starred, status ], notes.
	// A note is [ staff slug, hours after the entry, text ], or
	// [ 'sent', notification id, name ] for a notification's sending note.
	// An order of the shop part, by its key there, as WooCommerce numbers it.
	$order_no = function ( $key ) use ( $demo ) {
		$id    = (int) $demo->id( 'orders', $key );
		$order = $id && function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : null;
		return $order ? (string) $order->get_order_number() : '';
	};
	$outsider = function ( $first, $last, $email, $phone, $street = '', $city = 'Portland', $state = 'OR', $postcode = '', $company = '' ) {
		return array( 'slug' => '', 'id' => 0, 'first' => $first, 'last' => $last, 'name' => $first . ' ' . $last, 'email' => $email, 'phone' => $phone, 'street' => $street, 'city' => $city, 'state' => $state, 'postcode' => $postcode, 'company' => $company );
	};
	$entries = array(
		// Wholesale inquiry.
		array( 'wholesale', $outsider( 'Noor', 'Haddad', 'noor@wrencoffee.example', '(503) 555-0141', '2715 SE Division Street', 'Portland', 'OR', '97202', 'Wren Coffee Co.' ), 0.3, 8, array( 6 => 'Cafe or restaurant', 7 => array( 'Cacti & succulents', 'Seeds & bulbs' ), 8 => 'Under $250 a month', 9 => "We have fourteen tables and want a small succulent on each one, swapped out when they get tired. We would also like to sell seed packets at the register in spring.\n\nDo you offer wholesale pricing on the wildflower meadow mix?", 10 => 'Email' ), array( 0, 0, 'active' ), array( array( 'sent', '5f1a2b3c4d5e1', 'Wholesale team' ), array( 'sent', '5f1a2b3c4d5e2', 'Thank-you email' ) ) ),
		array( 'wholesale', 'samuel', 3.4, 9, array( 6 => 'Florist', 7 => array( 'Houseplants', 'Botanical prints' ), 8 => '$250 to $1,000 a month', 9 => "We style weekly arrangements for two hotels downtown and they now want potted plants in the lobbies too. Could you hold six to ten anthuriums and peace lilies for us every other Thursday?\n\nWe would pick up from Station Road. A few framed bellflower prints for the hotel bar would be lovely as well.", 10 => 'Phone' ), array( 1, 1, 'active' ), array( array( 'sent', '5f1a2b3c4d5e1', 'Wholesale team' ), array( 'mateo', 5, 'Called Samuel. Sending the wholesale price list and setting him up on net 30. First pickup next Thursday: 6 anthuriums, 4 peace lilies.' ), array( 'june', 26, 'Samuel is a good fit for the 15% trade rate. Prints at the trade price too.' ) ) ),
		array( 'wholesale', 'samuel', 3.4, 9, array( 6 => 'Florist', 7 => array( 'Houseplants', 'Botanical prints' ), 8 => '$250 to $1,000 a month', 9 => 'We style weekly arrangements for two hotels downtown and they now want potted plants in the lobbies too.', 10 => 'Phone' ), array( 1, 0, 'trash' ), array( array( 'mateo', 5, 'Duplicate of the inquiry above (the page was submitted twice).' ) ), 2 ),
		array( 'wholesale', $outsider( 'Adele', 'Fontaine', 'adele@fernandfable.example', '(541) 555-0142', '410 Willamette Street', 'Eugene', 'OR', '97401', 'Fern & Fable Books' ), 6.2, 14, array( 6 => 'Retail shop', 7 => array( 'Botanical prints' ), 8 => '$250 to $1,000 a month', 9 => 'We sell stationery and art prints alongside new and used books. Your bellflower, thistle and phacelia prints would sell well here. Could we buy ten of each in the 8 x 10 size, or carry them on consignment to start?', 10 => 'Email' ), array( 0, 1, 'active' ), array( array( 'sent', '5f1a2b3c4d5e1', 'Wholesale team' ) ) ),
		array( 'wholesale', $outsider( 'Tessa', 'Hoang', 'tessa@greenlineinteriors.example', '(503) 555-0143', '1220 NW Glisan Street', 'Portland', 'OR', '97209', 'Greenline Office Interiors' ), 9.1, 11, array( 6 => 'Office', 7 => array( 'Houseplants', 'Cacti & succulents', 'Perennials' ), 8 => 'Over $1,000 a month', 9 => "We fit out offices around the Pearl District and need a steady source for 30 to 50 plants a quarter, mostly floor plants in 10 and 14 inch pots. Can you deliver to job sites, and do you take back plants that don't work out in a space?", 10 => 'Phone' ), array( 1, 1, 'active' ), array( array( 'sent', '5f1a2b3c4d5e1', 'Wholesale team' ), array( 'june', 3, 'Big one. Booked a call with Tessa for Tuesday at 10 and invited her to see the greenhouse.' ), array( 'mateo', 75, 'Quote sent for the first install: 36 plants, delivery and a 30 day swap guarantee.' ) ) ),
		array( 'wholesale', 'owen', 12.3, 16, array( 6 => 'Office', 7 => array( 'Houseplants' ), 8 => 'Under $250 a month', 9 => 'Our waiting room has one north window and no plants at all. We would like eight to ten low light plants in nice pots, and ideally someone to check on them once a month, because none of us will remember to water.', 10 => 'Email' ), array( 1, 0, 'active' ), array( array( 'june', 20, 'Sent Owen the office care plan: monthly visit, $40. He wants peace lilies and a couple of trailing plants for the front desk.' ) ) ),
		array( 'wholesale', $outsider( 'Hugo', 'Navarro', 'hugo@cedarandsalt.example', '(503) 555-0144', '3341 SE Belmont Street', 'Portland', 'OR', '97214', 'Cedar & Salt' ), 20.5, 10, array( 6 => 'Cafe or restaurant', 7 => array( 'Perennials', 'Workshops for your team' ), 8 => 'Under $250 a month', 9 => 'Planters for our patio before the summer, mostly columbine and lupine so it looks a bit wild. In the slow months we would also like a kokedama workshop for the kitchen and floor staff, about twelve people.', 10 => 'Email' ), array( 1, 0, 'active' ), array( array( 'priya', 30, 'Pencilled in a staff kokedama session for a Monday in January.' ) ) ),
		array( 'wholesale', $outsider( 'Paige', 'Whitman', 'paige@lowtideflowers.example', '(503) 555-0145', '1180 Commercial Street', 'Astoria', 'OR', '97103', 'Low Tide Flowers' ), 34.2, 15, array( 6 => 'Florist', 7 => array( 'Perennials', 'Seeds & bulbs' ), 8 => '$250 to $1,000 a month', 9 => 'Our grower retired this year. Do you sell calla lily bulbs in bulk and lupine starts by the flat? Spring weddings keep us busy from April to June.', 10 => 'Phone' ), array( 1, 0, 'active' ), array() ),
		array( 'wholesale', $outsider( 'Kevin', 'Stroud', 'growth@rankboost.example', '(555) 555-0199', '', '', '', '', 'RankBoost Digital' ), 5.1, 3, array( 6 => 'Other', 8 => 'Over $1,000 a month', 9 => 'Hello sir or madam, I visited your website and it is not on page 1 of Google for plant shop. We guarantee top ranking in 30 days for a very small fee. Reply today for a free audit.' ), array( 0, 0, 'spam' ), array() ),

		// Event & venue inquiry.
		array( 'events', $outsider( 'Clara', 'Nguyen', 'clara.nguyen@example.com', '(503) 555-0146', '18750 NW Cornell Road', 'Hillsboro', 'OR', '97124' ), 0.8, 21, array( 4 => '', 5 => 'Wedding', 6 => 45, 7 => '120', 8 => array( 'Centerpieces', 'Living favors' ), 10 => "We are getting married at a farm outside Hillsboro and would love small potted succulents as favors, each with a name tag, for 120 guests.\n\nCould you also make six long, low centerpieces with ferns and moss for the head tables?" ), array( 0, 0, 'active' ), array( array( 'sent', '5f1a2b3c4d6e1', 'Events inbox' ), array( 'sent', '5f1a2b3c4d6e2', 'Auto-reply' ) ) ),
		array( 'events', 'ruth', 2.1, 13, array( 4 => 'Lovejoy Hall', 5 => 'Venue styling', 6 => 21, 7 => '250', 8 => array( 'Plant rental', 'Installation and pickup' ), 10 => 'We are refreshing the lobby and the long gallery at Lovejoy Hall. We are looking for large floor plants on a rental basis, swapped out each season, that can handle low light and a lot of foot traffic. Could someone come by for a walkthrough?' ), array( 1, 1, 'active' ), array( array( 'sent', '5f1a2b3c4d6e1', 'Events inbox' ), array( 'june', 2, 'Walkthrough with Ruth booked for Friday at 2. Bringing the rental lookbook.' ), array( 'mateo', 46, 'Measured the gallery: room for twelve large planters along the windows, four in the lobby.' ) ) ),
		array( 'events', 'olivia', 4.3, 19, array( 4 => '', 5 => 'Private party', 6 => 20, 7 => '40', 8 => array( 'Plant rental', 'Living favors' ), 10 => "A garden party in our backyard for my mom's 70th. I'd love to rent a few big flowering plants to dress the patio, and give everyone a seed packet to take home. She loves sunflowers." ), array( 1, 0, 'active' ), array( array( 'june', 18, 'Suggested Teddy Bear sunflower seed packets with a custom label. Olivia said yes.' ) ) ),
		array( 'events', 'kenji', 8.2, 10, array( 4 => 'Riverbend Analytics', 5 => 'Corporate event', 6 => 30, 7 => '25', 8 => array( 'A workshop for guests' ), 10 => 'For our team offsite we would like a terrarium building session for about 25 people, materials included. Could Priya lead it on a weekday afternoon at our office in Vancouver?' ), array( 1, 0, 'active' ), array( array( 'priya', 4, 'I can do a Thursday, 2 to 4. Quote sent: $45 a head with all materials, plus travel.' ) ) ),
		array( 'events', $outsider( 'Marco', 'Bellini', 'marco@studiolumen.example', '(503) 555-0147', '2025 SE 7th Avenue', 'Portland', 'OR', '97214' ), 15.4, 12, array( 4 => 'Studio Lumen', 5 => 'Photo shoot', 6 => -3, 8 => array( 'Plant rental' ), 10 => 'We are shooting a spring catalog for a ceramics brand and need about twenty plants for two days, mostly trailing and sculptural ones. Happy to credit the shop in the lookbook.' ), array( 1, 0, 'active' ), array() ),
		array( 'events', $outsider( 'Rhea', 'Fuller', 'rhea.fuller@example.org', '(503) 555-0148', '1455 NW Irving Street', 'Portland', 'OR', '97209' ), 26.0, 9, array( 4 => 'Fuller & Rowe Architects', 5 => 'Corporate event', 6 => 62, 7 => '80', 8 => array( 'Centerpieces', 'Living favors' ), 10 => 'Holiday party for our office, 80 people, at the studio. Ten table centerpieces and a small potted plant for everyone to take home instead of the usual swag.' ), array( 1, 0, 'active' ), array() ),
		array( 'events', 'nadia', 41.6, 20, array( 4 => '', 5 => 'Wedding', 6 => 12, 7 => '60', 8 => array( 'Centerpieces' ), 10 => 'Small wedding in Eugene, sixty guests. We would love potted herbs as centerpieces that guests can take home after. Do you deliver that far?' ), array( 1, 0, 'active' ), array( array( 'june', 22, 'We can deliver to Eugene for $60. Nadia is checking with her venue.' ) ) ),
		array( 'events', $outsider( 'Elena', 'Ross', 'awards@grant-office.example', '(555) 555-0198' ), 11.0, 4, array( 5 => 'Other', 10 => 'Congratulations! Your business has been selected for a small business grant of $25,000. To claim, reply with your bank details within 48 hours.' ), array( 0, 0, 'spam' ), array() ),

		// Plant care question.
		array( 'care', 'emma', 0.2, 9, array( 4 => "Anthurium 'Red Heart'", 5 => 'Yes', 6 => array( 'Yellow leaves' ), 7 => 'Bright, indirect light', 8 => 'Two of the lower leaves on my anthurium turned yellow this week. It is still flowering and I water when the top inch of soil is dry. Should I be worried?', 9 => $order_no( '1058' ) ), array( 0, 0, 'active' ), array( array( 'sent', '5f1a2b3c4d7e1', 'Plant desk' ) ) ),
		array( 'care', $outsider( 'Trevor', 'Lang', 'deals@quickprofit.example', '' ), 0.9, 3, array( 4 => 'Something else', 8 => 'Earn $5,000 a week from home with our automated trading app. Limited spots, sign up at example.com/profit today.' ), array( 0, 0, 'spam' ), array() ),
		array( 'care', 'marcus', 1.5, 20, array( 4 => 'Peace Lily', 5 => 'Yes', 6 => array( 'Drooping' ), 7 => 'Low light', 8 => "My peace lily droops dramatically every few days, perks up as soon as I water it, then droops again. Is the pot too small? Roots are starting to come out of the bottom." ), array( 0, 1, 'active' ), array( array( 'sent', '5f1a2b3c4d7e1', 'Plant desk' ) ) ),
		array( 'care', 'aisha', 2.8, 11, array( 4 => 'Golden Saguaro', 5 => 'Yes', 6 => array( 'Spots or patches' ), 7 => 'Sunny window', 8 => 'There are two soft brown patches near the base of my saguaro. It sits in a south window and I have watered it twice since September. It gets cold against the glass at night.' ), array( 1, 1, 'active' ), array( array( 'sent', '5f1a2b3c4d7e1', 'Plant desk' ), array( 'priya', 3, 'Replied: most likely too much water plus the cold glass. No more water until March, and move it six inches back from the window. Asked her for a photo of the base.' ) ) ),
		array( 'care', 'liam', 5.0, 18, array( 4 => 'Lupine', 5 => 'Yes', 6 => array( 'No new growth' ), 7 => 'Outdoors', 8 => 'I planted three lupines in June and they have barely grown since. The soil here is heavy clay. Should I move them before winter?' ), array( 1, 0, 'active' ), array() ),
		array( 'care', 'grace', 1.0, 13, array( 4 => 'Something else', 5 => 'Yes', 7 => 'Outdoors', 8 => 'The replacement impatiens arrived in perfect shape, thank you. Can I bring them inside for the winter here in Denver, or are they done after the first frost?' ), array( 1, 0, 'active' ), array( array( 'hana', 6, 'Answered: take cuttings now and root them on a windowsill, they overwinter better than the whole plant.' ) ) ),
		array( 'care', 'chloe', 9.2, 15, array( 4 => 'Wild Columbine', 5 => 'Yes', 6 => array( 'Spots or patches', 'Pests' ), 7 => 'Outdoors', 8 => 'Squiggly white lines all over the columbine leaves in our school pollinator bed. Is that something we need to treat? The kids are worried about the plants.' ), array( 1, 1, 'active' ), array( array( 'priya', 2, 'Leaf miners. Only cosmetic: cut the worst leaves back after flowering and bin them. Sent Chloe our fall pollinator bed checklist for the class.' ) ) ),
		array( 'care', 'kenji', 10.6, 8, array( 4 => 'Peace Lily', 5 => 'It was a gift', 6 => array( 'Brown tips' ), 7 => 'Bright, indirect light', 8 => 'Crispy brown tips on most of the leaves of my peace lily. I water with tap water once a week. Is it the water?' ), array( 1, 0, 'active' ), array() ),
		array( 'care', 'daniel', 14.3, 17, array( 4 => 'Calla lily', 5 => 'Yes', 6 => array( 'Yellow leaves', 'Drooping' ), 7 => 'Outdoors', 8 => 'The calla bulbs I planted in spring flowered beautifully, and now the leaves are yellowing and flopping over. Do I dig them up, or can they stay in the pot over winter in San Diego?' ), array( 1, 0, 'active' ), array( array( 'priya', 20, 'Normal die back. In San Diego they can stay in the pot: stop watering until the leaves are gone, then a light top dress in February.' ) ) ),
		array( 'care', 'isla', 19.1, 12, array( 4 => 'Golden Saguaro', 5 => 'Yes', 6 => array(), 7 => 'Sunny window', 8 => 'Is it normal for my saguaro to lean toward the window? It has gone a little crooked in two months.' ), array( 1, 0, 'active' ), array() ),
		array( 'care', 'ben', 23.4, 19, array( 4 => 'Something else', 5 => 'Yes', 7 => 'Outdoors', 8 => 'The sunflowers my kids planted at your Sunflower Morning are now taller than my son. How do we save seeds for next year?' ), array( 1, 1, 'active' ), array( array( 'hana', 15, 'Sent Ben the seed saving steps: wait for the back of the head to turn brown, cover it with a paper bag, then dry it in the garage.' ) ) ),
		array( 'care', 'sofia', 31.2, 14, array( 4 => "Anthurium 'Red Heart'", 5 => 'Yes', 6 => array( 'Brown tips' ), 7 => 'Bright, indirect light', 8 => 'Brown, crispy tips on the new leaves since we turned on the air conditioning. It is about four feet from the vent.' ), array( 1, 0, 'active' ), array() ),
		array( 'care', 'noah', 40.5, 21, array( 4 => 'Peace Lily', 5 => 'No', 7 => 'Low light', 8 => 'Quick question before I order: is the peace lily safe to keep around cats? Ours chews anything green.' ), array( 1, 0, 'active' ), array( array( 'hana', 14, 'Answered: not pet safe. Suggested the saguaro on a high shelf, or a calathea, which is safe for cats.' ) ) ),
		array( 'care', 'hana', 44.0, 16, array( 4 => 'Something else', 8 => 'Test after updating the plant list. Please ignore.' ), array( 1, 0, 'trash' ), array() ),

		// Holiday wreath pre-orders, last winter.
		array( 'wreaths', 'ruth', 318.0, 10, array( 4 => 'Large, 22 inch ($75)', 5 => '4', 6 => 'December 5 and 6', 7 => 'Four for the front doors of Lovejoy Hall, please. Plain, no bows.' ), array( 1, 1, 'active' ), array() ),
		array( 'wreaths', 'olivia', 314.0, 18, array( 4 => 'Small, 14 inch ($45)', 5 => '1', 6 => 'December 12 and 13', 7 => '' ), array( 1, 0, 'active' ), array() ),
		array( 'wreaths', 'mia', 309.0, 12, array( 4 => 'Door swag ($38)', 5 => '2', 6 => 'December 5 and 6', 7 => 'One with eucalyptus if you have it.' ), array( 1, 0, 'active' ), array() ),
	);

	// Fields that hold who wrote in, per form: name, email, phone, address.
	$identity = array(
		'wholesale' => array( 1, 3, 4, 5, 2 ),
		'events'    => array( 1, 2, 3, 9, 0 ),
		'care'      => array( 1, 2, 3, 0, 0 ),
		'wreaths'   => array( 1, 2, 3, 0, 0 ),
	);
	$pages = array(
		'wholesale' => 'wholesale',
		'events'    => 'contact',
		'care'      => 'faq',
		'wreaths'   => 'visit-the-shop',
	);
	// Gravity Forms credits an entry to the signed-in user when it names no
	// one, so the entries go in signed out.
	wp_set_current_user( 0 );
	// Oldest first, so entry ids rise with the dates as they would on a live site.
	uasort(
		$entries,
		function ( $a, $b ) use ( $demo ) {
			return $demo->ago( $a[2], $a[3] ) <=> $demo->ago( $b[2], $b[3] );
		}
	);
	$count = 0;
	foreach ( $entries as $n => $e ) {
		list( $form_key, $who, $days, $hour, $answers, $flags, $notes ) = $e;
		if ( empty( $form_ids[ $form_key ] ) ) {
			continue;
		}
		$p  = is_array( $who ) ? $who : $demo->person( $who );
		$ts = $at( $days, $hour, ( $n * 13 ) % 60 ) + ( isset( $e[7] ) ? $e[7] * MINUTE_IN_SECONDS : 0 );
		list( $f_name, $f_email, $f_phone, $f_address, $f_company ) = $identity[ $form_key ];
		$entry = array(
			'form_id'      => $form_ids[ $form_key ],
			'date_created' => $demo->gmt( $ts ),
			'date_updated' => $demo->gmt( $ts ),
			'is_read'      => $flags[0],
			'is_starred'   => $flags[1],
			'status'       => $flags[2],
			'ip'           => ( 'spam' === $flags[2] ? '203.0.113.' : '198.51.100.' ) . ( 20 + $n * 3 ),
			'source_url'   => 'https://larkspur.example/' . $pages[ $form_key ] . '/',
			'user_agent'   => $agents[ $n % count( $agents ) ],
			'created_by'   => ! empty( $p['id'] ) ? $p['id'] : '',
			'currency'     => 'USD',
		);
		$entry[ $f_name . '.3' ] = $p['first'];
		$entry[ $f_name . '.6' ] = $p['last'];
		$entry[ (string) $f_email ] = $p['email'];
		if ( $f_phone && '' !== $p['phone'] ) {
			$entry[ (string) $f_phone ] = $p['phone'];
		}
		if ( $f_address && '' !== $p['street'] ) {
			$entry[ $f_address . '.1' ] = $p['street'];
			$entry[ $f_address . '.3' ] = $p['city'];
			$entry[ $f_address . '.4' ] = isset( $us_states[ $p['state'] ] ) ? $us_states[ $p['state'] ] : $p['state'];
			$entry[ $f_address . '.5' ] = $p['postcode'];
			$entry[ $f_address . '.6' ] = 'United States';
		}
		if ( $f_company && '' !== $p['company'] ) {
			$entry[ (string) $f_company ] = $p['company'];
		}
		$form = GFAPI::get_form( $form_ids[ $form_key ] );
		foreach ( $answers as $field_id => $value ) {
			$gf_field = GFFormsModel::get_field( $form, $field_id );
			if ( ! $gf_field ) {
				continue;
			}
			if ( 'checkbox' === $gf_field->type ) {
				foreach ( $gf_field->choices as $i => $choice ) {
					$entry[ $field_id . '.' . ( $i + 1 ) ] = in_array( $choice['value'], (array) $value, true ) ? $choice['value'] : '';
				}
			} elseif ( 'date' === $gf_field->type ) {
				// An event date: days from the entry's own date.
				$entry[ (string) $field_id ] = wp_date( 'Y-m-d', $ts + (int) $value * DAY_IN_SECONDS );
			} elseif ( '' !== $value ) {
				$entry[ (string) $field_id ] = $value;
			}
		}
		$entry_id = GFAPI::add_entry( $entry );
		if ( is_wp_error( $entry_id ) ) {
			WP_CLI::warning( 'Gravity Forms entry: ' . $entry_id->get_error_message() );
			continue;
		}
		++$count;
		foreach ( $notes as $i => $note ) {
			if ( 'sent' === $note[0] ) {
				$note_id = GFAPI::add_note( $entry_id, 0, $note[2] . ' (ID: ' . $note[1] . ')', 'WordPress successfully passed the notification email to the sending server.', 'notification', 'success' );
				$when    = $ts + 5 + $i;
			} else {
				$staff   = $demo->person( $note[0] );
				$note_id = GFAPI::add_note( $entry_id, $staff['id'], $staff['name'], $note[2] );
				$when    = min( $ts + (int) $note[1] * HOUR_IN_SECONDS, $demo->now - 10 * MINUTE_IN_SECONDS );
			}
			// add_note returns $wpdb->insert_id after its gform_post_note_added
			// hook, which another plugin's insert can change: find the note itself.
			if ( ! is_wp_error( $note_id ) ) {
				$note_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(id) FROM {$wpdb->prefix}gf_entry_notes WHERE entry_id = %d", $entry_id ) );
				$wpdb->update( $wpdb->prefix . 'gf_entry_notes', array( 'date_created' => $demo->gmt( $when ) ), array( 'id' => $note_id ) );
			}
		}
	}
	GFFormsModel::flush_current_forms();
	$demo->log( count( $form_ids ) . ' Gravity Forms forms, ' . $count . ' entries' );
};
