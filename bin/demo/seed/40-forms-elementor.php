<?php
/**
 * Elementor Pro forms: two pages under Workshops built with Elementor, each
 * with a form (private workshop requests, school visit requests), and their
 * submissions from the last two months, read, unread and trashed.
 *
 * Elementor Pro has no form table: a form is a widget in the page's
 * _elementor_data, and its submissions are rows in e_submissions (with
 * post_id + element_id naming the widget, the form name copied in) and one
 * e_submissions_values row per answer. Field labels come from a snapshot of
 * the form kept in the page's __elementor_forms_snapshot meta, which
 * Elementor writes on each submission; without it Minn shows field ids.
 * Rows go in through Elementor's own Query::add_submission(), which runs no
 * form action, so no email goes out. Status is only 'new' or 'trash', read
 * state is the is_read flag; created_at is site time, created_at_gmt UTC.
 *
 * @package minn-admin
 */

use ElementorPro\Modules\Forms\Submissions\Database\Query;
use ElementorPro\Modules\Forms\Submissions\Database\Repositories\Form_Snapshot_Repository;

return function ( Minn_Demo_Seed $demo ) {
	global $wpdb;
	if ( ! class_exists( Query::class ) || ! class_exists( Form_Snapshot_Repository::class ) ) {
		$demo->log( 'Elementor Pro forms are not available: no requests' );
		return;
	}
	$group = 'forms-elementor';
	$query = Query::get_instance();
	foreach ( $demo->ids( $group ) as $key => $id ) {
		if ( 0 === strpos( $key, 'sub-' ) ) {
			$query->delete_submission( (int) $id );
		}
	}
	$pages = array(
		'private' => (int) $demo->id( $group, 'page-private' ),
		'school'  => (int) $demo->id( $group, 'page-school' ),
	);
	$demo->forget( $group );
	$june = $demo->id( 'users', 'june' );

	// key => page title, slug, form widget id, form name, intro, fields
	// (custom id, type, label, width, options), button, success message, days ago.
	$forms = array(
		'private' => array(
			'Private Workshops',
			'private-workshops',
			'f3a91c2',
			'Private workshop request',
			'Bring a group to the shop, or have Priya bring the workshop to you. Team events, birthdays, book clubs and showers, for six to thirty people.',
			array(
				array( 'name', 'text', 'Name', '50', array() ),
				array( 'email', 'email', 'Email', '50', array() ),
				array( 'phone', 'tel', 'Phone', '50', array() ),
				array( 'occasion', 'select', 'Occasion', '50', array( 'Team event', 'Birthday', 'Book club', 'Shower or celebration', 'Something else' ) ),
				array( 'workshop', 'select', 'Workshop', '50', array( 'Terrarium', 'Kokedama', 'Propagation', 'Not sure yet' ) ),
				array( 'guests', 'number', 'Number of guests', '25', array() ),
				array( 'date', 'date', 'Preferred date', '25', array() ),
				array( 'where', 'radio', 'Where', '100', array( 'At the shop', 'At our place' ) ),
				array( 'message', 'textarea', 'Tell us about your group', '100', array() ),
			),
			'Send request',
			'Thank you! Priya will get back to you within two days with dates and a quote.',
			68,
		),
		'school'  => array(
			'School Visits',
			'school-visits',
			'b8d04e7',
			'School visit request',
			'Class trips to the shop and visits to your school, from pre-K to high school. Free for public schools in Portland.',
			array(
				array( 'name', 'text', 'Your name', '50', array() ),
				array( 'email', 'email', 'Email', '50', array() ),
				array( 'phone', 'tel', 'Phone', '50', array() ),
				array( 'school', 'text', 'School', '50', array() ),
				array( 'grade', 'select', 'Grade', '50', array( 'Pre-K', 'K to 2', '3 to 5', '6 to 8', 'High school' ) ),
				array( 'students', 'number', 'Number of students', '25', array() ),
				array( 'date', 'date', 'Preferred date', '25', array() ),
				array( 'visit', 'radio', 'Visit type', '100', array( 'Class trip to the shop', 'Visit at our school' ) ),
				array( 'message', 'textarea', 'What is your class studying?', '100', array() ),
			),
			'Request a visit',
			'Thanks for reaching out! We will reply within a week to find a date.',
			61,
		),
	);

	$parent = (int) $demo->id( 'pages', 'workshops' );
	$hex    = function ( $seed ) {
		return substr( md5( 'minn-demo-' . $seed ), 0, 7 );
	};
	$labels = array();
	foreach ( $forms as $key => $f ) {
		list( $title, $slug, $element, $form_name, $intro, $fields, $button, $success, $days ) = $f;
		$form_fields = array();
		$snapshot    = array();
		foreach ( $fields as $field ) {
			list( $id, $type, $label, $width, $options ) = $field;
			$row = array(
				'_id'         => $hex( $key . $id ),
				'custom_id'   => $id,
				'field_type'  => $type,
				'field_label' => $label,
				'placeholder' => '',
				'required'    => in_array( $id, array( 'name', 'email', 'message' ), true ) ? 'true' : '',
				'width'       => $width,
			);
			$snap = array( 'id' => $id, 'type' => $type, 'label' => $label );
			if ( $options ) {
				$row['field_options'] = implode( "\n", $options );
				$snap['options']      = $options;
			}
			if ( 'textarea' === $type ) {
				$row['rows'] = 5;
			}
			$form_fields[]          = $row;
			$snapshot[]             = $snap;
			$labels[ $key ][ $id ] = $type;
		}
		$data = array(
			array(
				'id'       => $hex( $key . 'container' ),
				'elType'   => 'container',
				'isInner'  => false,
				'settings' => array( 'content_width' => 'boxed' ),
				'elements' => array(
					array(
						'id'         => $hex( $key . 'heading' ),
						'elType'     => 'widget',
						'widgetType' => 'heading',
						'settings'   => array( 'title' => $title, 'header_size' => 'h1' ),
						'elements'   => array(),
					),
					array(
						'id'         => $hex( $key . 'intro' ),
						'elType'     => 'widget',
						'widgetType' => 'text-editor',
						'settings'   => array( 'editor' => '<p>' . $intro . '</p>' ),
						'elements'   => array(),
					),
					array(
						'id'         => $element,
						'elType'     => 'widget',
						'widgetType' => 'form',
						'settings'   => array(
							'form_name'       => $form_name,
							'form_fields'     => $form_fields,
							'button_text'     => $button,
							'submit_actions'  => array( 'save-to-database', 'email' ),
							'email_to'        => 'priya@larkspur.example',
							'email_subject'   => 'New ' . strtolower( $form_name ) . ' from [field id="name"]',
							'email_content'   => '[all-fields]',
							'email_from'      => 'hello@larkspur.example',
							'email_from_name' => 'Larkspur & Fern',
							'email_reply_to'  => '[field id="email"]',
							'success_message' => $success,
						),
						'elements'   => array(),
					),
				),
			),
		);
		$ts   = $demo->ago( $days, 9, 30 );
		$post = array(
			'post_type'     => 'page',
			'post_status'   => 'publish',
			'post_title'    => $title,
			'post_name'     => $slug,
			'post_parent'   => $parent,
			'post_author'   => $june,
			'post_content'  => '<h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $intro ) . '</p>',
			'post_date'     => $demo->local( $ts ),
			'post_date_gmt' => $demo->gmt( $ts ),
		);
		if ( $pages[ $key ] && get_post( $pages[ $key ] ) ) {
			$post['ID'] = $pages[ $key ];
			wp_update_post( $post );
		} else {
			$pages[ $key ] = (int) wp_insert_post( $post );
		}
		$page_id = $pages[ $key ];
		update_post_meta( $page_id, '_elementor_edit_mode', 'builder' );
		update_post_meta( $page_id, '_elementor_template_type', 'wp-page' );
		update_post_meta( $page_id, '_elementor_version', defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : '' );
		update_post_meta( $page_id, '_elementor_pro_version', defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : '' );
		update_post_meta( $page_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
		update_post_meta( $page_id, '_wp_page_template', 'default' );
		Form_Snapshot_Repository::instance()->create_or_update( $page_id, $element, array( 'name' => $form_name, 'fields' => $snapshot ) );
		$demo->remember( $group, 'page-' . $key, $page_id );
	}

	$people = array(
		'tessa'  => array( 'Tessa Moreau', 'tessa.moreau@example.org', '(503) 555-0160' ),
		'devin'  => array( 'Devin Shah', 'devin.shah@example.com', '(503) 555-0161' ),
		'lena'   => array( 'Lena Park', 'lena.park@example.net', '(503) 555-0162' ),
		'corey'  => array( 'Corey Bell', 'corey.bell@example.com', '(971) 555-0163' ),
		'imani'  => array( 'Imani Cole', 'imani.cole@example.org', '(503) 555-0164' ),
		'rachel' => array( 'Rachel Gomez', 'rachel.gomez@example.org', '(503) 555-0165' ),
		'tom'    => array( 'Tom Reyes', 'tom.reyes@example.com', '(503) 555-0166' ),
		'helen'  => array( 'Helen Okoro', 'helen.okoro@example.net', '(971) 555-0167' ),
		'paul'   => array( 'Paul Lindgren', 'paul.lindgren@example.org', '(503) 555-0168' ),
		'joy'    => array( 'Joy Adams', 'joy.adams@example.com', '(503) 555-0169' ),
		'boost'  => array( 'StarBoost Reviews', 'reviews@starboost.example', '' ),
	);
	$who = function ( $slug ) use ( $demo, $people ) {
		if ( isset( $people[ $slug ] ) ) {
			return array( 'name' => $people[ $slug ][0], 'email' => $people[ $slug ][1], 'phone' => $people[ $slug ][2], 'id' => 0 );
		}
		return $demo->person( $slug );
	};
	$when = function ( $days, $hour, $minute ) use ( $demo ) {
		return $days < 1 ? $demo->now - (int) round( $days * DAY_IN_SECONDS ) : $demo->ago( $days, $hour, $minute );
	};
	$agents = array(
		'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
		'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
		'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
	);
	$n   = 0;
	$add = function ( $key, $answers, $p, $ts, $state ) use ( $demo, $wpdb, $query, $forms, $pages, $labels, $agents, &$n, $group ) {
		$values = array();
		foreach ( $answers as $id => $value ) {
			$values[] = array( 'id' => $id, 'type' => $labels[ $key ][ $id ], 'value' => (string) $value );
		}
		$page = get_post( $pages[ $key ] );
		$sid  = $query->add_submission(
			array(
				'post_id'                 => $page->ID,
				'referer'                 => get_permalink( $page ),
				'referer_title'           => $page->post_title,
				'element_id'              => $forms[ $key ][2],
				'form_name'               => $forms[ $key ][3],
				'campaign_id'             => 0,
				'user_id'                 => empty( $p['id'] ) ? 0 : (int) $p['id'],
				'user_ip'                 => ( 'spam' === $state ? '203.0.113.' : '192.0.2.' ) . ( 30 + $n * 11 % 200 ),
				'user_agent'              => $agents[ $n % count( $agents ) ],
				'actions_count'           => 1,
				'actions_succeeded_count' => 1,
				'meta'                    => wp_json_encode( array( 'edit_post_id' => $page->ID ) ),
			),
			$values
		);
		++$n;
		$wpdb->update(
			$wpdb->prefix . 'e_submissions',
			array(
				'status'         => in_array( $state, array( 'trash', 'spam' ), true ) ? 'trash' : 'new',
				'is_read'        => 'unread' === $state ? 0 : 1,
				'created_at_gmt' => $demo->gmt( $ts ),
				'updated_at_gmt' => $demo->gmt( $ts ),
				'created_at'     => $demo->local( $ts ),
				'updated_at'     => $demo->local( $ts ),
			),
			array( 'id' => $sid )
		);
		// The email action's log line, as their save-to-database action writes it.
		$wpdb->insert(
			$wpdb->prefix . 'e_submissions_actions_log',
			array(
				'submission_id'  => $sid,
				'action_name'    => 'email',
				'action_label'   => 'Email',
				'status'         => 'success',
				'log'            => null,
				'created_at_gmt' => $demo->gmt( $ts + 2 ),
				'updated_at_gmt' => $demo->gmt( $ts + 2 ),
				'created_at'     => $demo->local( $ts + 2 ),
				'updated_at'     => $demo->local( $ts + 2 ),
			)
		);
		$demo->remember( $group, 'sub-' . $sid, $sid );
	};
	$on = function ( $days ) use ( $demo ) {
		return wp_date( 'Y-m-d', $demo->ago( -$days ) );
	};

	// Both forms' requests go in as one list, oldest first, so ids follow dates.
	$queue = array();

	// Oldest first: who, days ago, hour, minute, occasion, workshop, guests, preferred date (days ahead), where, message, state.
	$private = array(
		array( 'priya', 58.0, 17, 10, 'Something else', 'Not sure yet', 6, 14, 'At the shop', 'Testing the new form before it goes live. Please ignore.', 'trash' ),
		array( 'imani', 33.5, 20, 40, 'Birthday', 'Terrarium', 8, 20, 'At the shop', 'My daughter turns 12 next month and wants a terrarium party with her friends. Is 12 old enough to work with the glass jars?', 'read' ),
		array( 'corey', 21.0, 12, 5, 'Team event', 'Not sure yet', 20, 18, 'At the shop', 'Our office wants a team outing, around 20 people. Which workshop works best for a group that mostly kills plants?', 'read' ),
		array( 'ruth', 15.2, 10, 15, 'Something else', 'Propagation', 30, 45, 'At our place', "We host a community open house at Lovejoy Hall and would love a drop-in propagation table, about 30 people over two hours.\n\nWe can cover materials and there is a long table by the windows.", 'read' ),
		array( 'boost', 12.0, 4, 20, 'Something else', 'Not sure yet', 1, 2, 'At our place', 'Get 50 five-star reviews for your shop this week. Packages from $99, results guaranteed.', 'spam' ),
		array( 'lena', 9.8, 19, 30, 'Shower or celebration', 'Kokedama', 12, 40, 'At our place', 'Planning a baby shower for my sister. Kokedama with small ferns as take-home favors would be lovely. Twelve guests, in my backyard in Sellwood.', 'read' ),
		array( 'olivia', 4.3, 21, 5, 'Birthday', 'Terrarium', 6, 12, 'At the shop', 'My best friend turns 40 and she loves the terrarium in your window. Six of us, ideally on a Saturday after the regular workshop. Can we bring a cake?', 'read' ),
		array( 'devin', 1.6, 11, 45, 'Team event', 'Terrarium', 14, 31, 'At our place', "Our design team of 14 has an offsite in the Central Eastside. Could Priya bring the terrarium workshop to us?\n\nWe have long tables and good light. A weekday afternoon would be best.", 'unread' ),
		array( 'tessa', 0.3, 0, 0, 'Book club', 'Kokedama', 8, 24, 'At the shop', 'Our book club just finished a novel set in a Japanese garden and we would love to make kokedama together. Eight of us. We will bring snacks if that is allowed.', 'unread' ),
	);
	foreach ( $private as $r ) {
		list( $slug, $days, $hour, $minute, $occasion, $workshop, $guests, $ahead, $where, $message, $state ) = $r;
		$p = $who( $slug );
		$queue[] = array(
			'private',
			array(
				'name'     => $p['name'],
				'email'    => $p['email'],
				'phone'    => $p['phone'],
				'occasion' => $occasion,
				'workshop' => $workshop,
				'guests'   => $guests,
				'date'     => $on( $ahead - $days ),
				'where'    => $where,
				'message'  => $message,
			),
			$p,
			$when( $days, $hour, $minute ),
			$state
		);
	}

	// Oldest first: who, days ago, hour, minute, school, grade, students, visit, preferred date (days ahead), message, state.
	$school = array(
		array( 'joy', 27.1, 15, 50, 'Eastbank High School', 'High school', 18, 'Class trip to the shop', 35, 'My environmental science students are designing a rain garden for the school. A tour and a talk about plants that handle wet winters would be a great start.', 'read' ),
		array( 'paul', 16.7, 16, 5, 'Cedar Loop Elementary', '3 to 5', 52, 'Visit at our school', 27, "Two fourth grade classes are studying ecosystems. Could you bring a terrarium demo?\n\nWe would split the kids into two sessions of 26.", 'read' ),
		array( 'helen', 8.4, 13, 10, 'Riverbend Montessori', 'Pre-K', 16, 'Class trip to the shop', 9, 'Our little ones are four and five. A short visit, maybe 45 minutes, to see and smell the plants would be wonderful. We will bring plenty of grown-ups.', 'read' ),
		array( 'tom', 2.9, 18, 25, 'Maple Row Middle School', '6 to 8', 28, 'Visit at our school', 22, 'Our science club is building a pollinator garden in the courtyard. Would you talk to the students about native plants and help us choose from your wildflower meadow mix?', 'unread' ),
		array( 'rachel', 0.5, 0, 0, 'Brightwater Elementary', 'K to 2', 24, 'Class trip to the shop', 16, "We are doing a unit on seeds and how plants grow. Could the class plant sunflower seeds to take home?\n\nWe can walk over, it is about ten minutes from school.", 'unread' ),
	);
	foreach ( $school as $r ) {
		list( $slug, $days, $hour, $minute, $name, $grade, $students, $visit, $ahead, $message, $state ) = $r;
		$p = $who( $slug );
		$queue[] = array(
			'school',
			array(
				'name'     => $p['name'],
				'email'    => $p['email'],
				'phone'    => $p['phone'],
				'school'   => $name,
				'grade'    => $grade,
				'students' => $students,
				'date'     => $on( $ahead - $days ),
				'visit'    => $visit,
				'message'  => $message,
			),
			$p,
			$when( $days, $hour, $minute ),
			$state
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
	$demo->log( count( $private ) . ' private workshop and ' . count( $school ) . ' school visit requests' );
};
