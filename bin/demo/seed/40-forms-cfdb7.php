<?php
/**
 * CFDB7 (Contact Form 7 Database Addon): its table of saved submissions for
 * two of the CF7 forms the cf7 part makes, Workshop feedback and Plant swap
 * sign-up, some read and some not.
 *
 * CFDB7 has no API for adding an entry (it saves from CF7's
 * wpcf7_before_send_mail), so rows go straight into {prefix}db7_forms the
 * way it writes them: form_value is a PHP-serialized map of the posted
 * fields with cfdb7_status ('read' or 'unread') first, quotes and slashes
 * turned into HTML entities, and form_date in site-local time. Minn shows
 * those entities as stored (I&#039;ve), so the answers here have no quotes,
 * apostrophes or slashes. Runs after the cf7 part, whose form ids it files
 * the rows under.
 *
 * @package minn-admin
 */

return function ( Minn_Demo_Seed $demo ) {
	global $wpdb;
	if ( ! function_exists( 'cfdb7_before_send_mail' ) ) {
		$demo->log( 'CFDB7 is not active: no CFDB7 entries' );
		return;
	}
	$forms = array(
		'feedback' => (int) $demo->id( 'forms-cf7', 'feedback' ),
		'swap'     => (int) $demo->id( 'forms-cf7', 'swap' ),
	);
	if ( ! $forms['feedback'] || ! $forms['swap'] ) {
		$demo->log( 'No CF7 forms from the cf7 part: no CFDB7 entries' );
		return;
	}
	$table = $wpdb->prefix . 'db7_forms';
	foreach ( $demo->ids( 'forms-cfdb7' ) as $id ) {
		$wpdb->delete( $table, array( 'form_id' => (int) $id ) );
	}
	// Nothing on the demo site is ever submitted, so every row under these forms is ours.
	foreach ( $forms as $form_id ) {
		$wpdb->delete( $table, array( 'form_post_id' => $form_id ) );
	}
	$demo->forget( 'forms-cfdb7' );

	$outsider = function ( $first, $last, $email ) {
		return array( 'name' => $first . ' ' . $last, 'email' => $email );
	};
	// form, who, days ago, hour, fields beyond name and email, read.
	$rows = array(
		array( 'swap', $outsider( 'Lena', 'Park', 'lena.park@example.net' ), 0.6, 19, array( 'plant-count' => '3', 'your-message' => 'Tradescantia cuttings in jars and a rooted fiddle leaf fig cutting.', 'volunteer' => array( 'I can help set up tables at 9' ) ), false ),
		array( 'feedback', 'nadia', 1.2, 20, array( 'workshop' => 'Spring Terrarium Workshop', 'rating' => '5, loved it', 'your-message' => 'My terrarium sits on the kitchen windowsill and still looks perfect. Priya explained the layers so clearly that I made a second one at home.', 'next-workshop' => array( 'Tell me about the next workshop' ) ), false ),
		array( 'swap', $outsider( 'Iris', 'Banks', 'iris.banks@example.net' ), 1.9, 8, array( 'plant-count' => '5', 'your-message' => 'Hoya cuttings, a string of pearls and a few baby aloes.', 'volunteer' => array( 'I can help set up tables at 9' ) ), false ),
		array( 'feedback', $outsider( 'Gemma', 'Fox', 'gemma.fox@example.net' ), 2.3, 18, array( 'workshop' => 'Spring Terrarium Workshop', 'rating' => '5, loved it', 'your-message' => 'Came with my mom for her birthday. We both left with a terrarium and a list of plants to buy.' ), false ),
		array( 'swap', 'samuel', 3.2, 21, array( 'plant-count' => '8', 'your-message' => "Potted herbs left over from a wedding: rosemary, thyme and some very happy mint. They are too good to compost." ), true ),
		array( 'swap', 'owen', 4.1, 12, array( 'plant-count' => '2', 'your-message' => 'Two peace lily divisions from our waiting room. They are doing a little too well.' ), true ),
		array( 'feedback', $outsider( 'Leah', 'Okafor', 'leah.okafor@example.com' ), 5.5, 16, array( 'workshop' => 'Kokedama', 'rating' => '5, loved it', 'your-message' => "The moss ball workshop was the most relaxing Saturday I have had all year. Could you run an evening one for grown ups?", 'next-workshop' => array( 'Tell me about the next workshop' ) ), true ),
		array( 'swap', 'ruth', 6.7, 10, array( 'plant-count' => '6', 'your-message' => 'Six snake plants from the hall lobby that need new homes before the rental plants arrive.' ), true ),
		array( 'feedback', 'owen', 9.1, 13, array( 'workshop' => 'Kokedama', 'rating' => '4', 'your-message' => 'Brought two people from the office and we all loved it. We would book a private session for the whole team.' ), true ),
		array( 'feedback', $outsider( 'Tomas', 'Ruiz', 'tomas.ruiz@example.com' ), 13.4, 15, array( 'workshop' => 'Propagation basics', 'rating' => '3', 'your-message' => 'Good content, but twenty people was a lot for the space. It was hard to see the demo from the back.' ), true ),
		array( 'feedback', $outsider( 'Ari', 'Cohen', 'ari.cohen@example.com' ), 20.5, 12, array( 'workshop' => 'Kids Sunflower Morning', 'rating' => '5, loved it', 'your-message' => 'My daughter labeled her pot herself and tells everyone it is her sunflower.', 'next-workshop' => array( 'Tell me about the next workshop' ) ), true ),
		array( 'feedback', $outsider( 'Pat', 'Doyle', 'pat.doyle@example.org' ), 27.0, 14, array( 'workshop' => 'Propagation basics', 'rating' => '2', 'your-message' => 'It started twenty minutes late and the handout was missing the watering schedule we were promised.' ), true ),
	);
	// Oldest first, so ids rise with the dates as they would on a live site.
	usort(
		$rows,
		function ( $a, $b ) use ( $demo ) {
			return $demo->ago( $a[2], $a[3] ) <=> $demo->ago( $b[2], $b[3] );
		}
	);
	// CFDB7's own escaping of what it stores.
	$escape = function ( $v ) {
		$bl = array( '\"', "\'", '/', '\\', '"', "'" );
		$wl = array( '&quot;', '&#039;', '&#047;', '&#092;', '&quot;', '&#039;' );
		return is_array( $v ) ? array_map( function ( $i ) use ( $bl, $wl ) { return str_replace( $bl, $wl, $i ); }, $v ) : str_replace( $bl, $wl, $v );
	};
	$count = 0;
	foreach ( $rows as $n => $r ) {
		list( $key, $who, $days, $hour, $answers, $read ) = $r;
		$p     = is_array( $who ) ? $who : $demo->person( $who );
		$ts    = min( $demo->ago( $days, $hour, ( $n * 19 ) % 60 ), $demo->now - ( 40 + $n ) * MINUTE_IN_SECONDS );
		$value = array( 'cfdb7_status' => $read ? 'read' : 'unread' );
		foreach ( array_merge( array( 'your-name' => $p['name'], 'your-email' => $p['email'] ), $answers ) as $k => $v ) {
			$value[ $k ] = $escape( $v );
		}
		$wpdb->insert(
			$table,
			array(
				'form_post_id' => $forms[ $key ],
				'form_value'   => serialize( $value ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- CFDB7's own storage format.
				'form_date'    => $demo->local( $ts ),
			)
		);
		if ( $wpdb->insert_id ) {
			$demo->remember( 'forms-cfdb7', (string) $n, (int) $wpdb->insert_id );
			++$count;
		}
	}
	$demo->log( $count . ' CFDB7 entries' );
};
