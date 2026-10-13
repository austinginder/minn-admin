<?php
/**
 * Contact Form 7 with Flamingo: four CF7 forms (the default "Contact form
 * 1", which CF7 makes when it is activated, becomes Quick question; then
 * Order help, Workshop feedback and Plant swap sign-up) with their mail,
 * autoresponder and messages, and Flamingo's inbound messages for them:
 * sent, one that failed to send, spam and a trashed one.
 *
 * CF7 keeps no submissions; Flamingo does, as flamingo_inbound posts in a
 * channel term named after the form (slug = the form's post_name, under a
 * "contact-form-7" parent), the way CF7's own Flamingo module files them.
 * The message status pill comes from _submission_status (mail_sent,
 * mail_failed) and spam is the flamingo-spam post status. Flamingo has no
 * read state. Forms are updated in place on a rerun (never recreated), so
 * their ids stay put for the CFDB7 part, which files entries under them.
 *
 * @package minn-admin
 */

return function ( Minn_Demo_Seed $demo ) {
	global $wpdb;
	if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
		$demo->log( 'Contact Form 7 is not active: no CF7 forms' );
		return;
	}
	$demo->as_user( 'june' );

	$messages_ok = array(
		'mail_sent_ok'     => 'Thanks for your message. It has been sent, and one of us will reply within a working day.',
		'mail_sent_ng'     => 'Sorry, something went wrong sending your message. Please call the shop on (503) 555-0100.',
		'validation_error' => 'One or more fields have an error. Please check and try again.',
		'spam'             => 'Sorry, something went wrong sending your message. Please call the shop on (503) 555-0100.',
	);
	$mail = function ( $subject, $body, $extra = array() ) {
		return array_merge(
			array(
				'subject'            => $subject,
				'sender'             => 'Larkspur & Fern <hello@larkspur.example>',
				'body'               => $body,
				'recipient'          => 'hello@larkspur.example',
				'additional_headers' => 'Reply-To: [your-email]',
				'attachments'        => '',
				'use_html'           => 0,
				'exclude_blank'      => 1,
			),
			$extra
		);
	};
	$reply = function ( $subject, $body ) {
		return array(
			'active'             => true,
			'subject'            => $subject,
			'sender'             => 'Larkspur & Fern <hello@larkspur.example>',
			'body'               => $body,
			'recipient'          => '[your-email]',
			'additional_headers' => 'Reply-To: hello@larkspur.example',
			'attachments'        => '',
			'use_html'           => 0,
			'exclude_blank'      => 1,
		);
	};

	// key => [ title, slug, created days ago, form, mail, mail (2) or null, additional settings ]
	$forms = array(
		'question' => array(
			'Quick question',
			'quick-question',
			150,
			"<label> Your name\n    [text* your-name autocomplete:name] </label>\n\n<label> Your email\n    [email* your-email autocomplete:email] </label>\n\n<label> Subject\n    [text* your-subject] </label>\n\n<label> Your message\n    [textarea* your-message] </label>\n\n[submit \"Send\"]",
			$mail( 'Quick question: [your-subject]', "From: [your-name] <[your-email]>\nSubject: [your-subject]\n\n[your-message]\n\n-- \nSent from the Quick question form on larkspur.example" ),
			null,
			'',
		),
		'order'    => array(
			'Order help',
			'order-help',
			140,
			"<label> Your name\n    [text* your-name autocomplete:name] </label>\n\n<label> Your email\n    [email* your-email autocomplete:email] </label>\n\n<label> Order number\n    [text order-number placeholder \"e.g. 1046\"] </label>\n\n<label> What can we help with?\n    [select* issue \"Where is my order?\" \"Something arrived damaged\" \"Change my order\" \"Returns and refunds\" \"Something else\"] </label>\n\n<label> Tell us more\n    [textarea* your-message] </label>\n\n[submit \"Get help\"]",
			$mail( 'Order help: [issue] (#[order-number])', "From: [your-name] <[your-email]>\nOrder: [order-number]\nIssue: [issue]\n\n[your-message]", array( 'recipient' => 'mateo@larkspur.example' ) ),
			$reply( 'We are on it: [issue]', "Hi [your-name],\n\nThanks for getting in touch about order [order-number]. Mateo looks after orders and will reply within a working day. If a plant arrived damaged, a photo of it in the box helps us sort it out quickly.\n\nLarkspur & Fern\n112 Station Road, Portland" ),
			'flamingo_subject: "Order #[order-number]: [issue]"',
		),
		'feedback' => array(
			'Workshop feedback',
			'workshop-feedback',
			80,
			"<label> Your name\n    [text your-name autocomplete:name] </label>\n\n<label> Your email\n    [email your-email autocomplete:email] </label>\n\n<label> Which workshop?\n    [select* workshop \"Spring Terrarium Workshop\" \"Propagation basics\" \"Kokedama\" \"Kids Sunflower Morning\"] </label>\n\n<label> How was it?\n    [radio rating use_label_element default:1 \"5, loved it\" \"4\" \"3\" \"2\" \"1, not for me\"] </label>\n\n<label> What should we keep or change?\n    [textarea your-message] </label>\n\n[checkbox next-workshop \"Tell me about the next workshop\"]\n\n[submit \"Send feedback\"]",
			$mail( 'Workshop feedback: [workshop] ([rating])', "[your-name] <[your-email]> on [workshop]\nRating: [rating]\n\n[your-message]", array( 'recipient' => 'priya@larkspur.example' ) ),
			null,
			'flamingo_subject: "[workshop]: [rating]"',
		),
		'swap'     => array(
			'Plant swap sign-up',
			'plant-swap-sign-up',
			35,
			"<p>Fall plant swap: Saturday, November 7, 10 to 1, in the yard behind the shop.</p>\n\n<label> Your name\n    [text* your-name autocomplete:name] </label>\n\n<label> Your email\n    [email* your-email autocomplete:email] </label>\n\n<label> How many plants are you bringing?\n    [number plant-count min:1 max:20] </label>\n\n<label> What are you bringing?\n    [textarea your-message] </label>\n\n[checkbox volunteer \"I can help set up tables at 9\"]\n\n[submit \"Count me in\"]",
			$mail( 'Plant swap: [your-name] is bringing [plant-count]', "[your-name] <[your-email]>\nPlants: [plant-count]\nVolunteer: [volunteer]\n\n[your-message]", array( 'recipient' => 'hana@larkspur.example' ) ),
			$reply( 'See you at the plant swap', "Hi [your-name],\n\nYou are on the list for the fall plant swap, Saturday, November 7, 10 to 1, in the yard behind the shop. Label your plants with their names if you know them, and bring a box to carry new ones home.\n\nHana" ),
			'flamingo_subject: "Plant swap: [plant-count] plants"',
		),
	);

	// Find each form again: by id from an earlier run, or, for Quick
	// question, the form CF7 made when it was activated.
	$form_ids = array();
	foreach ( $forms as $key => $f ) {
		list( $title, $slug, $days, $template, $mail_1, $mail_2, $settings ) = $f;
		$id = (int) $demo->id( 'forms-cf7', $key );
		if ( $id && 'wpcf7_contact_form' !== get_post_type( $id ) ) {
			$id = 0;
		}
		if ( ! $id ) {
			foreach ( get_posts( array( 'post_type' => 'wpcf7_contact_form', 'posts_per_page' => -1, 'post_status' => 'any' ) ) as $post ) {
				if ( $post->post_title === $title || ( 'question' === $key && 'Contact form 1' === $post->post_title ) ) {
					$id = $post->ID;
					break;
				}
			}
		}
		$form = $id ? WPCF7_ContactForm::get_instance( $id ) : WPCF7_ContactForm::get_template( array( 'title' => $title ) );
		$form->set_title( $title );
		$form->set_properties(
			array(
				'form'                => $template,
				'mail'                => $mail_1,
				'mail_2'              => $mail_2 ? $mail_2 : array_merge( WPCF7_ContactFormTemplate::mail_2(), array( 'active' => false, 'sender' => 'Larkspur & Fern <hello@larkspur.example>' ) ),
				'messages'            => array_merge( WPCF7_ContactFormTemplate::messages(), $messages_ok ),
				'additional_settings' => $settings,
			)
		);
		$id      = $form->save();
		$created = $demo->ago( $days, 10, 5 );
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_name'         => $slug,
				'post_author'       => $demo->id( 'users', 'june' ),
				'post_date'         => $demo->local( $created ),
				'post_date_gmt'     => $demo->gmt( $created ),
				'post_modified'     => $demo->local( $created ),
				'post_modified_gmt' => $demo->gmt( $created ),
			),
			array( 'ID' => $id )
		);
		clean_post_cache( $id );
		$form_ids[ $key ] = $demo->remember( 'forms-cf7', $key, $id );
	}

	if ( ! class_exists( 'Flamingo_Inbound_Message' ) || ! function_exists( 'wpcf7_flamingo_add_channel' ) ) {
		$demo->log( count( $form_ids ) . ' CF7 forms; Flamingo is not active: no messages' );
		return;
	}
	// The channels, as CF7's Flamingo module makes them on a first submission.
	$channels = array();
	foreach ( $forms as $key => $f ) {
		$channel_id = wpcf7_flamingo_add_channel( $f[1], $f[0] );
		if ( $channel_id ) {
			wp_update_term( $channel_id, Flamingo_Inbound_Message::channel_taxonomy, array( 'name' => $f[0] ) );
			update_post_meta( $form_ids[ $key ], '_flamingo', array( 'channel' => (int) $channel_id ) );
			$channels[ $key ] = (int) $channel_id;
		}
	}
	// Clear the messages an earlier run made: those it remembered, and
	// anything else filed under these forms (nothing real is ever sent here).
	$old = array_map( 'intval', $demo->ids( 'forms-cf7-messages' ) );
	if ( $channels ) {
		$old = array_merge(
			$old,
			get_posts(
				array(
					'post_type'      => Flamingo_Inbound_Message::post_type,
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'tax_query'      => array( array( 'taxonomy' => Flamingo_Inbound_Message::channel_taxonomy, 'field' => 'term_id', 'terms' => array_values( $channels ) ) ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				)
			)
		);
	}
	foreach ( array_unique( $old ) as $id ) {
		if ( Flamingo_Inbound_Message::post_type === get_post_type( $id ) ) {
			wp_delete_post( $id, true );
		}
	}
	$demo->forget( 'forms-cf7-messages' );

	// An order of the shop part, by its key there, as WooCommerce numbers it.
	$order_no = function ( $key ) use ( $demo ) {
		$id    = (int) $demo->id( 'orders', $key );
		$order = $id && function_exists( 'wc_get_order' ) ? wc_get_order( $id ) : null;
		return $order ? (string) $order->get_order_number() : '';
	};
	$outsider = function ( $first, $last, $email ) {
		return array( 'id' => 0, 'first' => $first, 'last' => $last, 'name' => $first . ' ' . $last, 'email' => $email );
	};
	// form, who, days ago, hour, fields beyond name and email, status
	// (mail_sent, mail_failed, spam, trash).
	$inbound = array(
		array( 'question', 'liam', 0.4, 11, array( 'your-subject' => 'Columbine in full shade?', 'your-message' => "Is the wild columbine happy in full shade? Our backyard in Boston only gets an hour of sun in the morning. I'd like to add three more next to the lupines." ), 'mail_sent' ),
		array( 'question', 'sofia', 1.1, 15, array( 'your-subject' => 'Gift cards', 'your-message' => 'Do you sell gift cards, and can they be used for workshops too? My sister lives in Portland and I want to give her a terrarium morning.' ), 'mail_sent' ),
		array( 'question', $outsider( 'Max', 'Turner', 'promo@cheapfollowers.example' ), 1.6, 3, array( 'your-subject' => 'Grow your Instagram fast', 'your-message' => 'Buy 10,000 real followers for your plant shop today. Fast delivery, no password needed.' ), 'spam' ),
		array( 'question', 'nadia', 4.7, 10, array( 'your-subject' => 'Framing the prints', 'your-message' => 'Do the botanical prints come framed, or can you recommend a frame size for the 11 x 14? I am thinking of the phacelia for my office.' ), 'mail_sent' ),
		array( 'question', 'mia', 10.3, 21, array( 'your-subject' => 'Bigger peace lily?', 'your-message' => 'The peace lily I picked up is thriving. Do you ever have them in a 10 inch pot? I would like a big one for the corner by the stairs.' ), 'mail_failed' ),
		array( 'question', $outsider( 'Maren', 'Hart', 'maren.hart@example.com' ), 16.2, 13, array( 'your-subject' => 'Repotting service?', 'your-message' => 'My fiddle leaf fig has outgrown its pot. Do you repot plants in the shop if I bring it in?' ), 'mail_sent' ),
		array( 'question', 'marcus', 27.9, 9, array( 'your-subject' => 'Seed mix for a shady yard', 'your-message' => 'Is there a version of the wildflower meadow mix for part shade? Our Seattle yard is under two big maples.' ), 'mail_sent' ),
		array( 'question', 'theo', 33.0, 16, array( 'your-subject' => 'test', 'your-message' => 'Checking the form after the theme update.' ), 'trash' ),

		array( 'order', 'olivia', 0.05, 9, array( 'order-number' => $order_no( '1069' ), 'issue' => 'Change my order', 'your-message' => 'Could you add a second peace lily to my order from this morning before it ships? Happy to pay the difference.' ), 'mail_sent' ),
		array( 'order', 'grace', 5.0, 18, array( 'order-number' => $order_no( '1060' ), 'issue' => 'Something arrived damaged', 'your-message' => 'The impatiens flat arrived with two plants snapped at the stem and soil all over the box. Photo attached to my reply. The others look fine.' ), 'mail_sent' ),
		array( 'order', 'noah', 3.0, 12, array( 'order-number' => $order_no( '1059' ), 'issue' => 'Where is my order?', 'your-message' => 'My bellflower print shows as shipped, but the tracking has not updated in three days. Is it stuck somewhere?' ), 'mail_sent' ),
		array( 'order', 'ethan', 11.4, 14, array( 'order-number' => $order_no( '1057' ), 'issue' => 'Returns and refunds', 'your-message' => 'I cancelled the hibiscus order since it is out of stock. How long does the PayPal refund usually take?' ), 'mail_sent' ),
		array( 'order', 'daniel', 2.0, 17, array( 'order-number' => $order_no( '1061' ), 'issue' => 'Where is my order?', 'your-message' => 'The tracking for my sunflower seeds has not moved in two days. Could you check with the post office?' ), 'mail_sent' ),

		array( 'feedback', 'ben', 21.6, 13, array( 'workshop' => 'Kids Sunflower Morning', 'rating' => '5, loved it', 'your-message' => 'Both kids talked about it all week. Priya had endless patience with the five year olds. More seats next time, please: it sold out fast.', 'next-workshop' => array( 'Tell me about the next workshop' ) ), 'mail_sent' ),
		array( 'feedback', 'olivia', 12.2, 14, array( 'workshop' => 'Propagation basics', 'rating' => '4', 'your-message' => 'Loved it. The room got warm by the end, and I would have liked one more cutting to take home, but my pothos cuttings already have roots.' ), 'mail_sent' ),

		array( 'swap', 'olivia', 2.9, 20, array( 'plant-count' => '4', 'your-message' => 'Pothos cuttings, two spider plant babies and a jade that has gotten out of hand.', 'volunteer' => array( 'I can help set up tables at 9' ) ), 'mail_sent' ),
		array( 'swap', $outsider( 'Jonah', 'Pike', 'jonah.pike@example.net' ), 5.5, 19, array( 'plant-count' => '6', 'your-message' => 'Mostly succulents I propagated over the summer, plus a big snake plant.' ), 'mail_sent' ),
		array( 'swap', 'kenji', 8.0, 12, array( 'plant-count' => '2', 'your-message' => 'Two rooted pothos cuttings from the plant I have kept alive for a year.' ), 'mail_sent' ),
	);
	// Oldest first, so ids rise with the dates as they would on a live site.
	usort(
		$inbound,
		function ( $a, $b ) use ( $demo ) {
			return $demo->ago( $a[2], $a[3] ) <=> $demo->ago( $b[2], $b[3] );
		}
	);
	$agents = array(
		'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
		'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
		'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
	);
	$pages = array( 'question' => 'contact', 'order' => 'shipping-returns', 'feedback' => 'workshops', 'swap' => 'visit-the-shop' );
	$count = 0;
	foreach ( $inbound as $n => $m ) {
		list( $key, $who, $days, $hour, $answers, $status ) = $m;
		$p  = is_array( $who ) ? $who : $demo->person( $who );
		$ts = min( $demo->ago( $days, $hour, ( $n * 17 ) % 60 ), $demo->now - ( 30 + $n ) * MINUTE_IN_SECONDS );
		$fields = array_merge( array( 'your-name' => $p['name'], 'your-email' => $p['email'] ), $answers );
		$f      = $forms[ $key ];
		// The subject Flamingo files: the form's flamingo_subject setting, or [your-subject].
		if ( preg_match( '/flamingo_subject: "(.*)"/', $f[6], $tpl ) ) {
			$subject = preg_replace_callback(
				'/\[([a-z0-9-]+)\]/',
				function ( $t ) use ( $fields ) {
					$v = isset( $fields[ $t[1] ] ) ? $fields[ $t[1] ] : '';
					return is_array( $v ) ? implode( ', ', $v ) : $v;
				},
				$tpl[1]
			);
		} else {
			$subject = $fields['your-subject'];
		}
		$msg = Flamingo_Inbound_Message::add(
			array(
				'channel'          => $f[1],
				'status'           => 'spam' === $status || 'trash' === $status ? 'mail_sent' : $status,
				'subject'          => $subject,
				'from'             => $p['name'] . ' <' . $p['email'] . '>',
				'from_name'        => $p['name'],
				'from_email'       => $p['email'],
				'fields'           => $fields,
				'meta'             => array(
					'serial_number' => (string) ( $count + 1 ),
					'remote_ip'     => ( 'spam' === $status ? '203.0.113.' : '198.51.100.' ) . ( 120 + $n * 4 ),
					'user_agent'    => $agents[ $n % count( $agents ) ],
					'url'           => 'https://larkspur.example/' . ( 'shipping-returns' === $pages[ $key ] ? 'faq/shipping-returns' : $pages[ $key ] ) . '/',
					'date'          => wp_date( 'F j, Y', $ts ),
					'time'          => wp_date( 'g:i a', $ts ),
					'post_title'    => ucwords( str_replace( '-', ' ', $pages[ $key ] ) ),
					'site_title'    => 'Larkspur & Fern',
					'site_url'      => 'https://larkspur.example',
					'user_email'    => $p['id'] ? $p['email'] : '',
				),
				'spam'             => 'spam' === $status,
				'spam_log'         => 'spam' === $status ? array( array( 'agent' => 'akismet', 'reason' => 'Akismet returns a spam response.' ) ) : array(),
				'consent'          => array(),
				'timestamp'        => $ts,
				'posted_data_hash' => md5( wp_json_encode( $fields ) ),
			)
		);
		$id = $msg ? $msg->id() : 0;
		if ( ! $id ) {
			continue;
		}
		$wpdb->update( $wpdb->posts, array( 'post_date_gmt' => $demo->gmt( $ts ), 'post_modified' => $demo->local( $ts ), 'post_modified_gmt' => $demo->gmt( $ts ), 'post_author' => 0 ), array( 'ID' => $id ) );
		clean_post_cache( $id );
		if ( 'trash' === $status ) {
			$msg->trash();
		}
		$demo->remember( 'forms-cf7-messages', (string) $n, $id );
		++$count;
	}
	foreach ( $channels as $channel_id ) {
		wp_update_term_count_now( array( $channel_id ), Flamingo_Inbound_Message::channel_taxonomy );
	}
	$demo->log( count( $form_ids ) . ' CF7 forms, ' . $count . ' Flamingo messages' );
};
