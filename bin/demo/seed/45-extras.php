<?php
/**
 * What sits around the content rather than in a surface: traffic for the
 * overview (Koko Analytics), SEO titles and descriptions (Yoast SEO), the
 * plant details field group (ACF) and the workshop dates (The Events
 * Calendar).
 *
 * Koko keeps daily totals per site, per post and per referrer; since 2.x the
 * referrer names live in koko_analytics_referrer_labels (id, value).
 *
 * @package minn-admin
 */

return function ( Minn_Demo_Seed $demo ) {
	global $wpdb;

	$koko = $wpdb->prefix . 'koko_analytics_';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $koko . 'site_stats' ) ) ) {
		foreach ( array( 'site_stats', 'post_stats', 'paths', 'referrer_stats', 'referrer_labels' ) as $table ) {
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $koko . $table ) ) ) {
				$wpdb->query( "TRUNCATE TABLE {$koko}{$table}" ); // phpcs:ignore
			}
		}
		// Pages people land on: path => [ post id, share of the day's views ].
		$pages = array( '/' => array( 0, 0.24 ), '/shop/' => array( (int) wc_get_page_id( 'shop' ), 0.17 ) );
		$share = array( 0.08, 0.07, 0.05, 0.05, 0.04, 0.04, 0.03, 0.03, 0.02, 0.02 );
		$i     = 0;
		foreach ( array_merge( array_slice( $demo->ids( 'products' ), 0, 5 ), array_slice( $demo->ids( 'posts' ), 0, 5 ) ) as $id ) {
			$pages[ wp_make_link_relative( get_permalink( $id ) ) ] = array( (int) $id, $share[ $i++ ] );
		}
		foreach ( array( 'workshops', 'about', 'visit-the-shop' ) as $slug ) {
			$pages[ wp_make_link_relative( get_permalink( $demo->id( 'pages', $slug ) ) ) ] = array( (int) $demo->id( 'pages', $slug ), 0.03 );
		}
		$path_ids = array();
		foreach ( array_keys( $pages ) as $path ) {
			$wpdb->insert( $koko . 'paths', array( 'path' => $path ) );
			$path_ids[ $path ] = (int) $wpdb->insert_id;
		}
		$referrers = array( 'google.com' => 0.21, 'instagram.com' => 0.09, 'duckduckgo.com' => 0.04, 'pinterest.com' => 0.04, 'bing.com' => 0.02, 'newsletter.larkspur.example' => 0.05 );
		$ref_ids   = array();
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $koko . 'referrer_labels' ) ) ) {
			foreach ( array_keys( $referrers ) as $label ) {
				$wpdb->insert( $koko . 'referrer_labels', array( 'value' => $label ) );
				$ref_ids[ $label ] = (int) $wpdb->insert_id;
			}
		}
		// Four months of visits: busier on weekends and in spring, growing slowly.
		for ( $d = 120; $d >= 0; $d-- ) {
			$ts       = $demo->ago( $d, 12 );
			$weekend  = in_array( (int) wp_date( 'w', $ts ), array( 0, 6 ), true ) ? 1.35 : 1.0;
			$visitors = (int) round( ( 70 + ( 120 - $d ) * 0.9 ) * $weekend * ( 0.85 + mt_rand( 0, 30 ) / 100 ) );
			$views    = (int) round( $visitors * ( 2.1 + mt_rand( 0, 60 ) / 100 ) );
			$date     = wp_date( 'Y-m-d', $ts );
			$wpdb->insert( $koko . 'site_stats', array( 'date' => $date, 'visitors' => $visitors, 'pageviews' => $views ) );
			foreach ( $pages as $path => $p ) {
				$v = (int) round( $views * $p[1] * ( 0.7 + mt_rand( 0, 60 ) / 100 ) );
				if ( $v > 0 ) {
					$wpdb->insert( $koko . 'post_stats', array( 'date' => $date, 'post_id' => $p[0], 'path_id' => $path_ids[ $path ], 'visitors' => (int) ceil( $v * 0.8 ), 'pageviews' => $v ) );
				}
			}
			foreach ( $ref_ids as $label => $id ) {
				$v = (int) round( $visitors * $referrers[ $label ] * ( 0.6 + mt_rand( 0, 80 ) / 100 ) );
				if ( $v > 0 ) {
					$wpdb->insert( $koko . 'referrer_stats', array( 'date' => $date, 'id' => $id, 'unique_hits' => $v, 'hits' => (int) ceil( $v * 1.2 ) ) );
				}
			}
		}
		$demo->log( 'traffic for 121 days' );
	}

	if ( defined( 'WPSEO_VERSION' ) ) {
		foreach ( $demo->ids( 'posts' ) as $id ) {
			$post = get_post( $id );
			update_post_meta( $id, '_yoast_wpseo_title', $post->post_title . ' %%sep%% %%sitename%%' );
			if ( $post->post_excerpt ) {
				update_post_meta( $id, '_yoast_wpseo_metadesc', $post->post_excerpt );
			}
			$tags = wp_get_post_tags( $id, array( 'fields' => 'names' ) );
			if ( $tags ) {
				update_post_meta( $id, '_yoast_wpseo_focuskw', str_replace( '-', ' ', $tags[0] ) );
			}
		}
		foreach ( $demo->ids( 'products' ) as $slug => $id ) {
			$product = wc_get_product( $id );
			if ( $product && $product->get_short_description() ) {
				update_post_meta( $id, '_yoast_wpseo_metadesc', wp_strip_all_tags( $product->get_short_description() ) . ' Shipped from Portland by Larkspur & Fern.' );
			}
		}
		update_post_meta( $demo->id( 'pages', 'about' ), '_yoast_wpseo_metadesc', 'A small plant shop on Station Road in Portland: plants, seeds, botanical prints and Saturday workshops.' );
		$demo->log( 'SEO titles and descriptions' );
	}

	if ( function_exists( 'acf_import_field_group' ) ) {
		foreach ( $demo->ids( 'acf' ) as $id ) {
			acf_delete_field_group( $id );
		}
		$demo->forget( 'acf' );
		$group = acf_import_field_group(
			array(
				'key'      => 'group_minn_demo_plant',
				'title'    => 'Plant Details',
				'fields'   => array(
					array( 'key' => 'field_minn_demo_light', 'label' => 'Light', 'name' => 'light', 'type' => 'select', 'choices' => array( 'low' => 'Low light', 'medium' => 'Bright, indirect', 'full' => 'Full sun' ) ),
					array( 'key' => 'field_minn_demo_water', 'label' => 'Water', 'name' => 'water', 'type' => 'select', 'choices' => array( 'weekly' => 'Weekly', 'fortnightly' => 'Every two weeks', 'monthly' => 'Monthly' ) ),
					array( 'key' => 'field_minn_demo_pets', 'label' => 'Safe for pets', 'name' => 'pet_safe', 'type' => 'true_false', 'ui' => 1 ),
					array( 'key' => 'field_minn_demo_level', 'label' => 'Difficulty', 'name' => 'difficulty', 'type' => 'radio', 'choices' => array( 'easy' => 'Easy', 'moderate' => 'Moderate', 'expert' => 'Expert' ) ),
					array( 'key' => 'field_minn_demo_notes', 'label' => 'Care notes', 'name' => 'care_notes', 'type' => 'textarea', 'rows' => 3 ),
				),
				'location' => array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'product' ) ) ),
				'position' => 'normal',
				'active'   => true,
			)
		);
		$demo->remember( 'acf', 'plant', $group['ID'] );
		$care = array(
			'anthurium'  => array( 'medium', 'weekly', 0, 'moderate', 'Mist in dry winters. Feed monthly spring to fall.' ),
			'peace-lily' => array( 'low', 'weekly', 0, 'easy', 'Droops when thirsty and recovers within hours.' ),
			'saguaro'    => array( 'full', 'monthly', 1, 'easy', 'Soak and dry. Almost no water in winter.' ),
			'hibiscus'   => array( 'full', 'weekly', 1, 'moderate', 'Bring indoors before nights drop below 50°F.' ),
			'columbine'  => array( 'medium', 'weekly', 1, 'easy', 'Self-seeds gently. Cut back after flowering.' ),
			'lupine'     => array( 'full', 'weekly', 0, 'moderate', 'Deadhead for a second flush of flowers.' ),
			'impatiens'  => array( 'low', 'weekly', 1, 'easy', 'Keep evenly moist in hot weather.' ),
		);
		foreach ( $care as $slug => $c ) {
			$id = $demo->id( 'products', $slug );
			update_field( 'light', $c[0], $id );
			update_field( 'water', $c[1], $id );
			update_field( 'pet_safe', $c[2], $id );
			update_field( 'difficulty', $c[3], $id );
			update_field( 'care_notes', $c[4], $id );
		}
		$demo->log( 'plant details on ' . count( $care ) . ' products' );
	}

	if ( function_exists( 'tribe_create_event' ) ) {
		foreach ( $demo->ids( 'events' ) as $id ) {
			wp_delete_post( $id, true );
		}
		$demo->forget( 'events' );
		$demo->as_user( 'priya' );
		$venue  = tribe_create_venue( array( 'Venue' => 'Larkspur & Fern', 'Address' => '112 Station Road', 'City' => 'Portland', 'State' => 'OR', 'Zip' => '97214', 'Country' => 'United States' ) );
		$events = array(
			'Spring Terrarium Workshop'  => array( -4, 10, 2, 'Build a closed terrarium that waters itself. All materials included.' ),
			'Propagation Bar'            => array( -11, 10, 1.5, 'Take cuttings from our mother plants and learn which ones root in water.' ),
			'Kids Sunflower Morning'     => array( -18, 9, 1, 'Sow, label and take home a pot. Ages five and up, with a grown-up.' ),
			'Kokedama Class'             => array( -25, 10, 2, 'Wrap a fern in moss and string it up.' ),
			'Seed Swap at the Shop'      => array( 9, 11, 3, 'Bring seeds you saved, take home something new. Free.' ),
		);
		foreach ( $events as $title => $e ) {
			$start = $demo->ago( $e[0], $e[1] );
			$end   = $start + (int) ( $e[2] * HOUR_IN_SECONDS );
			$id    = tribe_create_event(
				array(
					'post_title'       => $title,
					'post_content'     => $e[3],
					'post_status'      => 'publish',
					'post_author'      => get_current_user_id(),
					'EventStartDate'   => wp_date( 'Y-m-d', $start ),
					'EventStartHour'   => wp_date( 'h', $start ),
					'EventStartMinute' => wp_date( 'i', $start ),
					'EventStartMeridian' => wp_date( 'a', $start ),
					'EventEndDate'     => wp_date( 'Y-m-d', $end ),
					'EventEndHour'     => wp_date( 'h', $end ),
					'EventEndMinute'   => wp_date( 'i', $end ),
					'EventEndMeridian' => wp_date( 'a', $end ),
					'EventVenueID'     => $venue,
					'EventCost'        => 'Seed Swap at the Shop' === $title ? '0' : '',
				)
			);
			if ( $id ) {
				$demo->remember( 'events', sanitize_title( $title ), $id );
			}
		}
		$demo->remember( 'events', 'venue', $venue );
		$demo->log( count( $events ) . ' events' );
	}
};
