<?php
/**
 * Forminator: the repotting service request and the weekend shop helper
 * application, with entries from the last two months and a little spam.
 *
 * Forminator keeps a form as a forminator_forms post (fields and settings in
 * its meta, through Forminator_API::add_form) and each submission as a row in
 * frmt_form_entry plus one frmt_form_entry_meta row per answer. Entries stamp
 * date_created in SITE-LOCAL time (date_i18n), meta rows too. Spam is the
 * is_spam flag with status 'spam'; there is no read state and no trash.
 * Choice answers are stored as the option's value, so the options here use
 * the label as their value and read naturally anywhere they are shown.
 * Entries go in through the entry model (save + set_fields), which writes the
 * rows and nothing else: no notification runs.
 *
 * @package minn-admin
 */

return function ( Minn_Demo_Seed $demo ) {
	if ( ! class_exists( 'Forminator_API' ) || ! class_exists( 'Forminator_Form_Entry_Model' ) ) {
		$demo->log( 'Forminator is not active: no Forminator forms' );
		return;
	}
	global $wpdb;
	$group = 'forms-forminator';
	foreach ( $demo->ids( $group ) as $form_id ) {
		Forminator_Form_Entry_Model::delete_by_form( (int) $form_id );
		wp_delete_post( (int) $form_id, true );
	}
	$demo->forget( $group );
	// A safety net: nothing in this part should mail anyone.
	$no_mail = function () {
		return false;
	};
	add_filter( 'pre_wp_mail', $no_mail );
	Forminator_API::initialize( true );
	$demo->as_user( 'june' );

	// One field per row, the way Forminator's own builder lays out a simple form.
	$field = function ( $id, $type, $label, $extra = array() ) {
		return array(
			'wrapper_id' => 'wrapper-' . substr( md5( $id . $label ), 0, 13 ),
			'fields'     => array( array_merge( array( 'element_id' => $id, 'type' => $type, 'cols' => '12', 'field_label' => $label ), $extra ) ),
		);
	};
	$options = function ( $labels ) {
		return array_map(
			function ( $label ) {
				return array( 'label' => $label, 'value' => $label );
			},
			$labels
		);
	};

	$forms = array(
		'repotting' => array(
			'Repotting service request',
			'Thanks! We will reply within a day to set a time. Bring your plants in their current pots, and leave the new pots to us.',
			array(
				$field( 'name-1', 'name', 'Name', array( 'required' => 'true' ) ),
				$field( 'email-1', 'email', 'Email address', array( 'required' => 'true', 'validation' => true ) ),
				$field( 'phone-1', 'phone', 'Phone number', array( 'validation' => 'none', 'phone_validation_type' => 'standard' ) ),
				$field( 'select-1', 'select', 'Drop off or pick up?', array( 'value_type' => 'single', 'options' => $options( array( 'I will bring them in', 'Please pick them up (Portland only)' ) ) ) ),
				$field( 'number-1', 'number', 'How many plants?' ),
				$field( 'select-2', 'select', 'Size of the largest pot now', array( 'value_type' => 'single', 'options' => $options( array( 'Under 6 inch', '6 to 10 inch', '10 to 14 inch', 'Over 14 inch' ) ) ) ),
				$field( 'checkbox-1', 'checkbox', 'Which plants?', array( 'value_type' => 'checkbox', 'options' => $options( array( 'Anthurium', 'Peace lily', 'Fiddle leaf fig', 'Monstera', 'Snake plant', 'Cactus or succulent', 'Something else' ) ) ) ),
				$field( 'text-1', 'text', 'Best day for you', array( 'input_type' => 'line' ) ),
				$field( 'textarea-1', 'textarea', 'Anything we should know?', array( 'input_type' => 'paragraph', 'limit' => '1000', 'limit_type' => 'characters' ) ),
			),
		),
		'helper'    => array(
			'Weekend shop helper application',
			'Thank you for applying. June reads every application and will be in touch within a week.',
			array(
				$field( 'name-1', 'name', 'Name', array( 'required' => 'true' ) ),
				$field( 'email-1', 'email', 'Email address', array( 'required' => 'true', 'validation' => true ) ),
				$field( 'phone-1', 'phone', 'Phone number', array( 'validation' => 'none', 'phone_validation_type' => 'standard' ) ),
				$field( 'checkbox-1', 'checkbox', 'When can you work?', array( 'value_type' => 'checkbox', 'options' => $options( array( 'Saturday mornings', 'Saturday afternoons', 'Sunday mornings', 'Sunday afternoons' ) ) ) ),
				$field( 'select-1', 'select', 'Plant experience', array( 'value_type' => 'single', 'options' => $options( array( 'My own houseplants', 'Worked at a nursery or garden center', 'Horticulture student', 'Florist or grower' ) ) ) ),
				$field( 'textarea-1', 'textarea', 'Why would you like to work at Larkspur & Fern?', array( 'input_type' => 'paragraph', 'limit' => '1500', 'limit_type' => 'characters' ) ),
				$field( 'text-1', 'text', 'Earliest start date', array( 'input_type' => 'line' ) ),
			),
		),
	);
	$form_ids = array();
	foreach ( $forms as $key => $f ) {
		$id = Forminator_API::add_form(
			$f[0],
			$f[2],
			array(
				'submission-behaviour' => 'behaviour-thankyou',
				'thankyou-message'     => $f[1],
				'submitData'           => array( 'custom-submit-text' => 'helper' === $key ? 'Send application' : 'Request a time' ),
			)
		);
		if ( is_wp_error( $id ) || ! $id ) {
			WP_CLI::warning( 'Forminator did not create ' . $f[0] );
			continue;
		}
		$made = $demo->ago( 'helper' === $key ? 70 : 95, 16, 20 );
		$wpdb->update(
			$wpdb->posts,
			array( 'post_date' => $demo->local( $made ), 'post_date_gmt' => $demo->gmt( $made ), 'post_modified' => $demo->local( $made ), 'post_modified_gmt' => $demo->gmt( $made ) ),
			array( 'ID' => $id )
		);
		clean_post_cache( $id );
		$form_ids[ $key ] = (int) $demo->remember( $group, $key, (int) $id );
	}
	if ( count( $form_ids ) < 2 ) {
		remove_filter( 'pre_wp_mail', $no_mail );
		return;
	}

	// People who are not in the cast: neighbors and applicants from Portland.
	$extra = array(
		'tessa'    => array( 'Tessa Lindqvist', 'tessa.lindqvist@example.com', '(503) 555-0141' ),
		'marisol'  => array( 'Marisol Vega', 'marisol.vega@example.net', '(503) 555-0142' ),
		'derek'    => array( 'Derek Huang', 'derek.huang@example.org', '(971) 555-0143' ),
		'spam1'    => array( 'Ranking Pros', 'growth@rankingpros.example', '' ),
		'jun'      => array( 'Jun Park', 'jun.park@example.com', '(503) 555-0144' ),
		'abby'     => array( 'Abby Kowalski', 'abby.kowalski@example.net', '(503) 555-0145' ),
		'felix'    => array( 'Felix Romero', 'felix.romero@example.org', '(971) 555-0146' ),
		'leah'     => array( 'Leah Okafor', 'leah.okafor@example.com', '(503) 555-0147' ),
		'sam'      => array( 'Sam Whitaker', 'sam.whitaker@example.net', '(503) 555-0148' ),
		'spam2'    => array( 'Remote Jobs Desk', 'offers@remotejobsdesk.example', '' ),
	);
	$who = function ( $slug ) use ( $demo, $extra ) {
		if ( isset( $extra[ $slug ] ) ) {
			return array( 'name' => $extra[ $slug ][0], 'email' => $extra[ $slug ][1], 'phone' => $extra[ $slug ][2] );
		}
		return $demo->person( $slug );
	};

	// Repotting: who, days ago, hour, drop off or pick up, plants, pot, which, day, note, spam.
	$repotting = array(
		array( 'nadia', 47, 11, 'I will bring them in', 1, '10 to 14 inch', array( 'Peace lily' ), 'A Saturday', "I'm in Portland every other Saturday for the farmers market. Could I drop my peace lily off before Priya's workshop and collect it after? It has been in the same pot for four years and the roots are circling the bottom.", false ),
		array( 'derek', 34, 18, 'Please pick them up (Portland only)', 4, '6 to 10 inch', array( 'Monstera', 'Snake plant' ), 'Any weekday after 5', 'Four plants in a third floor walk-up, so pick up would be a lifesaver. The monstera is putting out aerial roots everywhere and the snake plants have split their plastic pots.', false ),
		array( 'marisol', 22, 9, 'I will bring them in', 2, '6 to 10 inch', array( 'Anthurium', 'Monstera' ), 'Saturday morning', 'Both pots have fungus gnats. Should I repot into fresh soil, or treat the gnats first and repot later? I would rather not bring bugs into your shop.', false ),
		array( 'ruth', 15, 14, 'Please pick them up (Portland only)', 6, 'Over 14 inch', array( 'Monstera', 'Fiddle leaf fig' ), 'Before the 28th', "Six large floor planters in the lobby at Lovejoy Hall. We have a gala on the 28th, so they would need to be back and settled before then. Happy to pay for new pots if you think the ceramic ones are too small.", false ),
		array( 'spam1', 11, 3, 'I will bring them in', 1, 'Under 6 inch', array( 'Something else' ), 'Today', 'We can get larkspur.example to the first page of search results in 30 days. Reply for a free audit of your website.', true ),
		array( 'tessa', 9, 16, 'I will bring them in', 5, 'Under 6 inch', array( 'Cactus or succulent', 'Something else' ), 'Saturday afternoon', "A windowsill of succulents that were my grandmother's. Two are leaning badly and one has a soft spot near the base. Would it be all right to watch how you repot them?", false ),
		array( 'owen', 4.8, 12, 'Please pick them up (Portland only)', 12, '10 to 14 inch', array( 'Snake plant', 'Peace lily', 'Monstera' ), 'Friday', "Our waiting room plants have not been repotted since we opened. Twelve pots, mostly snake plants and peace lilies. Could you pick them up on a Friday and bring them back Monday so the office doesn't look bare?", false ),
		array( 'kenji', 2.3, 19, 'I will bring them in', 1, 'Over 14 inch', array( 'Fiddle leaf fig' ), 'Sunday', 'My fiddle leaf is about six feet tall and top heavy. It fits in the car lying down if that is okay for it. Is fall a bad time to repot something this big?', false ),
		array( 'olivia', 0.4, 8, 'I will bring them in', 3, '6 to 10 inch', array( 'Anthurium', 'Peace lily' ), 'Saturday morning', 'The anthurium I bought from you in spring has roots coming out of the drainage holes, and the peace lily droops two days after watering. Could you also tell me what size pots they should go into next?', false ),
	);
	// Helper applications: who, days ago, hour, when, experience, why, start, spam.
	$helper = array(
		array( 'sam', 58, 20, array( 'Sunday mornings', 'Sunday afternoons' ), 'My own houseplants', 'I manage a cafe during the week and want something quieter and greener on Sundays. I am good with customers who are in a hurry and customers who want to talk for twenty minutes.', 'Right away', false ),
		array( 'leah', 41, 10, array( 'Saturday mornings' ), 'Florist or grower', "I left full time floristry last year and miss the work with my hands. I could help with Saturday workshop setup and with arrangements when the shop does events.", 'First weekend of next month', false ),
		array( 'spam2', 30, 2, array( 'Saturday mornings' ), 'My own houseplants', 'Earn $500 a day from home with our proven system. No experience needed. Visit our site to start today.', 'Now', true ),
		array( 'felix', 19, 13, array( 'Sunday mornings', 'Sunday afternoons' ), 'Worked at a nursery or garden center', 'Two seasons at a garden center in Salem, mostly perennials, watering and loading cars. I can lift heavy stock and I have a truck if you ever need help with deliveries.', 'Two weeks from now', false ),
		array( 'abby', 6, 21, array( 'Saturday mornings', 'Saturday afternoons' ), 'My own houseplants', 'I have about forty houseplants in a one bedroom apartment, which my landlord finds alarming. I worked weekends at a bookstore for three years, so I am comfortable on a register and with busy afternoons.', 'Any time', false ),
		array( 'jun', 1.1, 15, array( 'Saturday mornings', 'Sunday mornings' ), 'Horticulture student', "I'm in my second year of the horticulture program at the community college and want to learn the retail side. I know propagation well and would love to help at Priya's Saturday workshops.", 'After finals in December', false ),
	);

	$count = 0;
	$add   = function ( $form_id, $ts, $answers, $spam, $n ) use ( $demo, &$count ) {
		$entry             = new Forminator_Form_Entry_Model();
		$entry->entry_type = 'custom-forms';
		$entry->form_id    = $form_id;
		$entry->is_spam    = $spam ? 1 : 0;
		$entry->status     = $spam ? 'spam' : 'active';
		$entry->save( $demo->local( $ts ) );
		if ( ! $entry->entry_id ) {
			return;
		}
		$meta = array();
		foreach ( $answers as $key => $value ) {
			if ( '' !== $value && array() !== $value ) {
				$meta[] = array( 'name' => $key, 'value' => $value );
			}
		}
		$meta[] = array( 'name' => '_forminator_user_ip', 'value' => '198.51.100.' . ( 20 + $n ) );
		$entry->set_fields( $meta, $demo->local( $ts ) );
		++$count;
	};
	// Interleave both forms oldest first, so entry ids rise with the date
	// (Minn and Forminator list newest id first).
	$rows = array();
	foreach ( $repotting as $r ) {
		$p      = $who( $r[0] );
		$rows[] = array(
			$r[1],
			$r[2],
			$form_ids['repotting'],
			array(
				'name-1'     => $p['name'],
				'email-1'    => $p['email'],
				'phone-1'    => $p['phone'],
				'select-1'   => $r[3],
				'number-1'   => (string) $r[4],
				'select-2'   => $r[5],
				'checkbox-1' => $r[6],
				'text-1'     => $r[7],
				'textarea-1' => $r[8],
			),
			$r[9],
		);
	}
	foreach ( $helper as $r ) {
		$p      = $who( $r[0] );
		$rows[] = array(
			$r[1],
			$r[2],
			$form_ids['helper'],
			array(
				'name-1'     => $p['name'],
				'email-1'    => $p['email'],
				'phone-1'    => $p['phone'],
				'checkbox-1' => $r[3],
				'select-1'   => $r[4],
				'textarea-1' => $r[5],
				'text-1'     => $r[6],
			),
			$r[7],
		);
	}
	usort(
		$rows,
		function ( $a, $b ) {
			return $b[0] <=> $a[0];
		}
	);
	foreach ( $rows as $n => $row ) {
		$add( $row[2], $demo->ago( $row[0], $row[1], ( $n * 13 ) % 60 ), $row[3], $row[4], $n );
	}
	foreach ( $form_ids as $form_id ) {
		Forminator_Form_Entry_Model::delete_form_entry_cache( $form_id );
	}
	remove_filter( 'pre_wp_mail', $no_mail );
	$demo->log( count( $form_ids ) . ' Forminator forms, ' . $count . ' entries' );
};
