<?php
/**
 * Fluent Forms: the newsletter sign-up and the office plant service inquiry.
 * Both are the two starter forms Fluent Forms makes on activation
 * ("Subscription Form" and "Contact Form Demo"), adopted and rebuilt, each
 * with its email notifications and confirmation message, and two months of
 * entries: unread, read, favourites, spam and a staff test in the trash.
 *
 * Fluent keeps a form's fields as JSON in fluentform_forms.form_fields and
 * its settings as JSON rows in fluentform_form_meta (formSettings holds the
 * confirmation, each notification is its own `notifications` row). An entry
 * is one row in fluentform_submissions whose `response` is the submitted
 * data as JSON (a name field is an object of its parts, checkboxes a list),
 * stamped in site time, plus one fluentform_entry_details row per answer
 * part, which their reports and filters read. Rows go in directly, so no
 * notification runs. Minn labels answers from top-level fields only, so the
 * fields are not wrapped in column containers.
 *
 * @package minn-admin
 */

return function ( Minn_Demo_Seed $demo ) {
	global $wpdb;
	if ( ! defined( 'FLUENTFORM_DIR_PATH' ) ) {
		$demo->log( 'Fluent Forms is not active: no sign-ups' );
		return;
	}
	$group = 'forms-fluent';
	$forms = $wpdb->prefix . 'fluentform_forms';
	$meta  = $wpdb->prefix . 'fluentform_form_meta';
	$subs  = $wpdb->prefix . 'fluentform_submissions';
	foreach ( $demo->ids( $group ) as $key => $id ) {
		if ( 0 === strpos( $key, 'entry-' ) ) {
			$wpdb->delete( $subs, array( 'id' => (int) $id ) );
			$wpdb->delete( $wpdb->prefix . 'fluentform_entry_details', array( 'submission_id' => (int) $id ) );
			$wpdb->delete( $wpdb->prefix . 'fluentform_submission_meta', array( 'response_id' => (int) $id ) );
		}
	}
	// The starter forms, found by what Fluent calls them on a fresh site.
	$find = function ( $key, $starter ) use ( $demo, $wpdb, $forms, $group ) {
		$id = (int) $demo->id( $group, $key );
		if ( $id && $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$forms} WHERE id = %d", $id ) ) ) {
			return $id;
		}
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$forms} WHERE title = %s ORDER BY id LIMIT 1", $starter ) );
	};
	$newsletter_id = $find( 'form-newsletter', 'Subscription Form' );
	$office_id     = $find( 'form-office', 'Contact Form Demo' );
	$demo->forget( $group );

	// Field definitions from Fluent's own element templates.
	$load = function () {
		include FLUENTFORM_DIR_PATH . 'app/Services/FormBuilder/DefaultElements.php';
		return isset( $fluentformDefaultElements ) ? $fluentformDefaultElements : array();
	};
	$templates = $load();
	$templates = isset( $templates['general'] ) ? $templates['general'] : array();
	$n         = 0;
	$make      = function ( $type, $name, $label, $required = false, $options = array() ) use ( $templates, &$n ) {
		$f = isset( $templates[ $type ] ) ? $templates[ $type ] : array(
			'element'    => $type,
			'attributes' => array( 'type' => 'text', 'value' => '' ),
			'settings'   => array( 'validation_rules' => array( 'required' => array( 'value' => false, 'message' => 'This field is required' ) ) ),
		);
		$f['index']              = $n;
		$f['uniqElKey']          = 'el_' . ( 1726000000000 + 7919 * ++$n );
		$f['attributes']['name'] = $name;
		if ( 'input_name' === $type ) {
			$f['settings']['admin_field_label'] = $label;
			unset( $f['fields']['middle_name'] );
		} else {
			$f['settings']['label']             = $label;
			$f['settings']['admin_field_label'] = '';
		}
		if ( isset( $f['settings']['validation_rules']['required'] ) ) {
			$f['settings']['validation_rules']['required']['value'] = $required;
		}
		if ( $options ) {
			$f['settings']['advanced_options'] = array();
			foreach ( $options as $o ) {
				$f['settings']['advanced_options'][] = array( 'label' => $o, 'value' => $o, 'calc_value' => '', 'image' => '' );
			}
		}
		return $f;
	};
	$submit = function ( $text ) {
		return array(
			'uniqElKey'      => 'el_1524065200616',
			'element'        => 'button',
			'attributes'     => array( 'type' => 'submit', 'class' => '' ),
			'settings'       => array(
				'align'            => 'left',
				'button_style'     => 'default',
				'container_class'  => '',
				'help_message'     => '',
				'background_color' => '#2f5d3a',
				'button_size'      => 'md',
				'color'            => '#ffffff',
				'button_ui'        => array( 'type' => 'default', 'text' => $text, 'img_url' => '' ),
			),
			'editor_options' => array( 'title' => 'Submit Button' ),
		);
	};
	$settings = function ( $message ) {
		return array(
			'confirmation' => array(
				'redirectTo'           => 'samePage',
				'messageToShow'        => $message,
				'customPage'           => null,
				'samePageFormBehavior' => 'hide_form',
				'customUrl'            => null,
			),
			'restrictions' => array(
				'limitNumberOfEntries' => array( 'enabled' => false, 'numberOfEntries' => null, 'period' => 'total', 'limitReachedMsg' => 'Maximum number of entries exceeded.' ),
				'scheduleForm'         => array( 'enabled' => false, 'start' => null, 'end' => null, 'pendingMsg' => 'Form submission is not started yet.', 'expiredMsg' => 'Form submission is now closed.' ),
				'requireLogin'         => array( 'enabled' => false, 'requireLoginMsg' => 'You must be logged in to submit the form.' ),
				'denyEmptySubmission'  => array( 'enabled' => false, 'message' => 'Sorry, you cannot submit an empty form.' ),
			),
			'layout'       => array( 'labelPlacement' => 'top', 'helpMessagePlacement' => 'with_label', 'errorMessagePlacement' => 'inline', 'asteriskPlacement' => 'asterisk-right' ),
		);
	};
	$notification = function ( $name, $to, $subject, $message, $enabled ) {
		$field = 0 === strpos( $to, 'field:' );
		return array(
			'name'           => $name,
			'sendTo'         => array(
				'type'    => $field ? 'field' : 'email',
				'email'   => $field ? '' : $to,
				'field'   => $field ? substr( $to, 6 ) : 'email',
				'routing' => array( array( 'email' => null, 'field' => null, 'operator' => '=', 'value' => null ) ),
			),
			'fromName'       => 'Larkspur & Fern',
			'fromEmail'      => 'hello@larkspur.example',
			'replyTo'        => $field ? 'hello@larkspur.example' : '{inputs.email}',
			'bcc'            => '',
			'subject'        => $subject,
			'message'        => $message,
			'conditionals'   => array( 'status' => false, 'type' => 'all', 'conditions' => array( array( 'field' => null, 'operator' => '=', 'value' => null ) ) ),
			'enabled'        => $enabled,
			'email_template' => '',
		);
	};
	$save_form = function ( $id, $title, $fields, $button, $form_settings, $notifications, $created, $updated ) use ( $demo, $wpdb, $forms, $meta ) {
		$row = array(
			'title'       => $title,
			'status'      => 'published',
			'form_fields' => wp_json_encode( array( 'fields' => $fields, 'submitButton' => $button ) ),
			'has_payment' => 0,
			'type'        => 'form',
			'created_by'  => $demo->id( 'users', 'june' ),
			'created_at'  => $demo->local( $created ),
			'updated_at'  => $demo->local( $updated ),
		);
		if ( $id ) {
			$wpdb->update( $forms, $row, array( 'id' => $id ) );
		} else {
			$wpdb->insert( $forms, $row );
			$id = (int) $wpdb->insert_id;
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$meta} WHERE form_id = %d AND meta_key IN ('formSettings','notifications','_primary_email_field')", $id ) );
		$wpdb->insert( $meta, array( 'form_id' => $id, 'meta_key' => 'formSettings', 'value' => wp_json_encode( $form_settings ) ) );
		$wpdb->insert( $meta, array( 'form_id' => $id, 'meta_key' => '_primary_email_field', 'value' => 'email' ) );
		foreach ( $notifications as $n ) {
			$wpdb->insert( $meta, array( 'form_id' => $id, 'meta_key' => 'notifications', 'value' => wp_json_encode( $n ) ) );
		}
		return $id;
	};

	$newsletter_id = $save_form(
		$newsletter_id,
		'Newsletter sign-up',
		array(
			$make( 'input_name', 'names', 'Name' ),
			$make( 'input_email', 'email', 'Email', true ),
			$make( 'select', 'heard_from', 'How did you find us?', false, array( 'In the shop', 'Instagram', 'A friend', 'The journal', 'Search' ) ),
			$make( 'input_checkbox', 'interests', 'What would you like to hear about?', false, array( 'Houseplant care', 'Workshops and events', 'Native plants and seeds', 'New prints' ) ),
		),
		$submit( 'Sign me up' ),
		$settings( 'Thanks for joining! Your welcome email has a code for $10 off your first order.' ),
		array(
			$notification( 'Welcome email', 'field:email', 'Welcome to Larkspur & Fern, {inputs.names.first_name}', '<p>Hi {inputs.names.first_name},</p><p>Thanks for signing up. Use the code WELCOME10 for $10 off your first order.</p><p>June and the Larkspur & Fern team</p>', true ),
			$notification( 'New subscriber alert', 'hello@larkspur.example', 'New newsletter sign-up: {inputs.email}', '<p>{all_data}</p>', false ),
		),
		$demo->ago( 140, 11, 0 ),
		$demo->ago( 21, 16, 10 )
	);
	$office_id = $save_form(
		$office_id,
		'Office plant service inquiry',
		array(
			$make( 'input_name', 'names', 'Name' ),
			$make( 'input_email', 'email', 'Email', true ),
			$make( 'input_text', 'phone', 'Phone' ),
			$make( 'input_text', 'company', 'Business name', true ),
			$make( 'select', 'space_type', 'Type of space', false, array( 'Office', 'Cafe or restaurant', 'Clinic or studio', 'Shop', 'Event venue', 'Other' ) ),
			$make( 'input_number', 'plant_count', 'Roughly how many plants?' ),
			$make( 'input_radio', 'service', 'What are you looking for?', false, array( 'Plants and regular care visits', 'Care visits for plants we already have', 'One-time styling' ) ),
			$make( 'textarea', 'message', 'Tell us about the space', true ),
		),
		$submit( 'Send inquiry' ),
		$settings( 'Thanks! Mateo will reply within two business days to set up a walk-through.' ),
		array(
			$notification( 'New inquiry to Mateo', 'mateo@larkspur.example, hello@larkspur.example', 'Office plants: {inputs.company}', '<p>{all_data}</p><p>Sent from {embed_post.permalink}</p>', true ),
			$notification( 'Auto-reply to sender', 'field:email', 'We got your note, {inputs.names.first_name}', '<p>Hi {inputs.names.first_name},</p><p>Thanks for telling us about {inputs.company}. Mateo looks after our office plant service and will be in touch within two business days.</p>', true ),
		),
		$demo->ago( 75, 10, 30 ),
		$demo->ago( 12, 9, 45 )
	);
	$demo->remember( $group, 'form-newsletter', $newsletter_id );
	$demo->remember( $group, 'form-office', $office_id );

	$others = array(
		'wren'  => array( 'Wren', 'Castillo', 'wren.castillo@example.org', '(503) 555-0147', '' ),
		'leah'  => array( 'Leah', 'Brandt', 'leah@millendcoffee.example', '(503) 555-0151', 'Mill End Coffee' ),
		'gus'   => array( 'Gus', 'Halvorsen', 'gus@halvorsen-architects.example', '(503) 555-0152', 'Halvorsen Architects' ),
		'dana'  => array( 'Dana', 'Whitcomb', 'dana@sunroomyoga.example', '(971) 555-0153', 'Sunroom Yoga' ),
		'bea'   => array( 'Bea', 'Thornton', 'bea.thornton@thorntonvale.example', '(503) 555-0154', 'Thornton & Vale Law' ),
		'nell'  => array( 'Nell', 'Okafor', 'nell@littleloaf.example', '(503) 555-0155', 'Little Loaf Bakery' ),
		'jake'  => array( 'Jake', 'Morrow', 'jake@brightclean.example', '(800) 555-0199', 'BrightClean Commercial' ),
		'bonus' => array( 'Crypto', 'Wins', 'promo@coinflash.example', '', '' ),
	);
	$who = function ( $slug ) use ( $demo, $others ) {
		if ( isset( $others[ $slug ] ) ) {
			$o = $others[ $slug ];
			return array( 'first' => $o[0], 'last' => $o[1], 'email' => $o[2], 'phone' => $o[3], 'company' => $o[4], 'id' => 0 );
		}
		return $demo->person( $slug );
	};
	$when = function ( $days, $hour, $minute ) use ( $demo ) {
		return $days < 1 ? $demo->now - (int) round( $days * DAY_IN_SECONDS ) : $demo->ago( $days, $hour, $minute );
	};
	$journal = array_values( $demo->ids( 'posts' ) );
	$devices = array( array( 'Chrome', 'OS X' ), array( 'Safari', 'iPhone' ), array( 'Firefox', 'Windows' ), array( 'Safari', 'OS X' ), array( 'Chrome', 'Android' ) );
	$serial  = array();
	$add     = function ( $form_id, $response, $p, $ts, $status, $star, $source ) use ( $demo, $wpdb, $subs, $devices, &$serial, $group ) {
		$serial[ $form_id ] = isset( $serial[ $form_id ] ) ? $serial[ $form_id ] + 1 : 1;
		$i                  = count( $demo->ids( $group ) );
		$device             = $devices[ $i % count( $devices ) ];
		$wpdb->insert(
			$subs,
			array(
				'form_id'       => $form_id,
				'serial_number' => $serial[ $form_id ],
				'response'      => wp_json_encode( $response, JSON_UNESCAPED_UNICODE ),
				'source_url'    => $source,
				'user_id'       => empty( $p['id'] ) ? 0 : (int) $p['id'],
				'status'        => $status,
				'is_favourite'  => $star ? 1 : 0,
				'browser'       => $device[0],
				'device'        => $device[1],
				'ip'            => ( 'spam' === $status ? '203.0.113.' : '198.51.100.' ) . ( 20 + $i * 7 % 200 ),
				'country'       => 'US',
				'created_at'    => $demo->local( $ts ),
				'updated_at'    => $demo->local( $ts ),
			)
		);
		$entry = (int) $wpdb->insert_id;
		// What their recordEntryDetails() writes: one row per answer part.
		foreach ( $response as $field => $value ) {
			foreach ( is_array( $value ) ? $value : array( '' => $value ) as $sub => $part ) {
				if ( '' === $part || null === $part ) {
					continue;
				}
				$wpdb->insert(
					$wpdb->prefix . 'fluentform_entry_details',
					array(
						'form_id'        => $form_id,
						'submission_id'  => $entry,
						'field_name'     => $field,
						'sub_field_name' => (string) $sub,
						'field_value'    => (string) $part,
					)
				);
			}
		}
		$demo->remember( $group, 'entry-' . $entry, $entry );
	};

	// Both forms' entries go in as one list, oldest first: Minn lists entries
	// newest id first, so the order they are made in is the order they read in.
	$queue = array();

	// Oldest first: who, days ago, hour, minute, how they found us, interests, status, favourite.
	$signups = array(
		array( 'hana', 55.0, 9, 40, 'In the shop', array( 'Workshops and events' ), 'trashed', false ),
		array( 'emma', 51.2, 20, 15, 'The journal', array( 'Houseplant care', 'Native plants and seeds' ), 'read', false ),
		array( 'noah', 46.3, 7, 50, 'Search', array( 'New prints' ), 'read', false ),
		array( 'isla', 40.6, 18, 5, 'A friend', array( 'Houseplant care' ), 'read', false ),
		array( 'bonus', 36.2, 3, 33, 'Search', array( 'Houseplant care', 'Workshops and events', 'Native plants and seeds', 'New prints' ), 'spam', false ),
		array( 'ben', 30.4, 21, 0, 'The journal', array( 'Workshops and events' ), 'read', false ),
		array( 'daniel', 24.0, 12, 20, 'Instagram', array( 'New prints' ), 'read', false ),
		array( 'liam', 17.5, 19, 45, 'Instagram', array( 'Houseplant care', 'New prints' ), 'read', true ),
		array( 'sofia', 12.9, 8, 10, 'A friend', array( 'Workshops and events', 'Native plants and seeds' ), 'read', false ),
		array( 'ethan', 8.1, 22, 30, 'Search', array( 'Houseplant care' ), 'read', false ),
		array( 'chloe', 4.6, 15, 25, 'The journal', array( 'Native plants and seeds' ), 'read', true ),
		array( 'wren', 2.0, 11, 50, 'In the shop', array( 'Workshops and events', 'Houseplant care' ), 'unread', false ),
		array( 'zoe', 0.9, 0, 0, 'A friend', array( 'New prints' ), 'unread', false ),
		array( 'marcus', 0.25, 0, 0, 'Instagram', array( 'Native plants and seeds', 'Workshops and events' ), 'unread', false ),
	);
	foreach ( $signups as $i => $r ) {
		list( $slug, $days, $hour, $minute, $heard, $interests, $status, $star ) = $r;
		$p      = $who( $slug );
		$source = 'The journal' === $heard && $journal ? get_permalink( $journal[ $i % count( $journal ) ] ) : home_url( '/' );
		$queue[] = array(
			$newsletter_id,
			array(
				'names'      => array( 'first_name' => $p['first'], 'last_name' => $p['last'] ),
				'email'      => $p['email'],
				'heard_from' => $heard,
				'interests'  => $interests,
			),
			$p,
			$when( $days, $hour, $minute ),
			$status,
			$star,
			$source
		);
	}

	// Oldest first: who, days ago, hour, minute, type of space, plants, service, message, status, favourite.
	$inquiries = array(
		array( 'mateo', 45.0, 10, 5, 'Other', 1, 'One-time styling', 'Test entry from Mateo, please ignore.', 'trashed', false ),
		array( 'ruth', 38.2, 14, 20, 'Event venue', 8, 'Plants and regular care visits', "Beyond flowers for events, we would like permanent plants for the foyer and the green room at Lovejoy Hall.\n\nThe two big planters by the front doors get afternoon sun. The green room has no windows at all. Is that hopeless?", 'read', false ),
		array( 'bea', 29.5, 9, 35, 'Office', 6, 'One-time styling', 'We move into a new suite next month and want the reception area styled for the opening. One-time styling only, our office manager will do the watering.', 'read', false ),
		array( 'dana', 22.0, 16, 50, 'Clinic or studio', 14, 'Care visits for plants we already have', "Our hot room stays humid and the plants in there love it, but the lobby ones are struggling.\n\nMostly pothos, a snake plant and a fiddle leaf fig that has seen better days. Could someone come every two weeks?", 'read', true ),
		array( 'jake', 15.3, 6, 15, 'Office', 1, 'Plants and regular care visits', 'Hi, we offer commercial cleaning for businesses like yours at 30% below your current provider. Can I send over a quote this week?', 'spam', false ),
		array( 'gus', 11.0, 13, 40, 'Office', 25, 'Plants and regular care visits', 'Twenty-five desks across two floors, lots of south light upstairs and very little downstairs. We would also like a few statement plants for the conference room. Budget is flexible for the right plan.', 'read', false ),
		array( 'leah', 5.4, 7, 30, 'Cafe or restaurant', 18, 'Plants and regular care visits', "Our cafe has a big north-facing window and lots of high shelves. We would love trailing plants up top and a care schedule we cannot forget.\n\nCould visits happen before we open at 7?", 'read', false ),
		array( 'nell', 2.1, 15, 5, 'Shop', 5, 'One-time styling', 'Small bakery, big front window. We would like four or five plants that can take the warm air from the ovens and still look good in photos.', 'unread', false ),
		array( 'owen', 0.6, 0, 0, 'Clinic or studio', 12, 'Plants and regular care visits', "We just renovated our waiting room and it feels cold. Looking for 10 to 12 low-light plants, a couple of big floor plants by the front window, and someone to look after them so my team doesn't have to.\n\nVisits before 8 am work best for us.", 'unread', true ),
	);
	foreach ( $inquiries as $r ) {
		list( $slug, $days, $hour, $minute, $space, $plants, $service, $message, $status, $star ) = $r;
		$p = $who( $slug );
		$queue[] = array(
			$office_id,
			array(
				'names'       => array( 'first_name' => $p['first'], 'last_name' => $p['last'] ),
				'email'       => $p['email'],
				'phone'       => $p['phone'],
				'company'     => '' !== $p['company'] ? $p['company'] : ( 'mateo' === $slug ? 'Larkspur & Fern' : '' ),
				'space_type'  => $space,
				'plant_count' => (string) $plants,
				'service'     => $service,
				'message'     => $message,
			),
			array( 'id' => 0 ),
			$when( $days, $hour, $minute ),
			$status,
			$star,
			home_url( '/contact/' )
		);
	}
	usort(
		$queue,
		function ( $a, $b ) {
			return $a[3] - $b[3];
		}
	);
	foreach ( $queue as $q ) {
		$add( ...$q );
	}
	$demo->log( count( $signups ) . ' newsletter sign-ups, ' . count( $inquiries ) . ' office plant inquiries' );
};
