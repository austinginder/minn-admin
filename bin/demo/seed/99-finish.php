<?php
/**
 * Last: dates that read like a shop's history. WordPress stamps every post
 * modified "now", and plugins add their own pages when activated (Shop,
 * Checkout, a members area), so without this the overview's recent activity
 * is the seed run itself. Seeded content gets modified = published; pages
 * that plugins made move back to when the shop opened, by its owner.
 * WooCommerce's customer and analytics tables fill from queued jobs, run here.
 *
 * @package minn-admin
 */

return function ( Minn_Demo_Seed $demo ) {
	global $wpdb;
	$opened = $demo->ago( 150, 9, 0 );
	$owner  = $demo->id( 'users', 'june' );
	$wpdb->query( "UPDATE {$wpdb->posts} SET post_modified = post_date, post_modified_gmt = post_date_gmt WHERE post_status <> 'auto-draft' AND post_date_gmt <> '0000-00-00 00:00:00'" );
	// Drafts and pending posts have no GMT date until published: their local date stands in.
	$wpdb->query( "UPDATE {$wpdb->posts} SET post_modified = post_date, post_modified_gmt = post_date WHERE post_status IN ('draft','pending') AND post_date_gmt = '0000-00-00 00:00:00'" );

	$seeded = array();
	foreach ( array( 'posts', 'pages', 'images', 'products' ) as $group ) {
		$seeded = array_merge( $seeded, array_map( 'intval', $demo->ids( $group ) ) );
	}
	$recent = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('page','post') AND post_status IN ('publish','draft','private') AND post_date_gmt > %s",
			$demo->gmt( $demo->ago( 2, 0, 0 ) )
		)
	);
	$moved = 0;
	foreach ( array_diff( array_map( 'intval', $recent ), $seeded ) as $i => $id ) {
		$ts = $opened + $i * 7 * MINUTE_IN_SECONDS;
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_author'       => $owner,
				'post_date'         => $demo->local( $ts ),
				'post_date_gmt'     => $demo->gmt( $ts ),
				'post_modified'     => $demo->local( $ts ),
				'post_modified_gmt' => $demo->gmt( $ts ),
			),
			array( 'ID' => $id )
		);
		clean_post_cache( $id );
		++$moved;
	}
	wp_cache_flush();

	if ( class_exists( 'ActionScheduler_QueueRunner' ) ) {
		$runner = ActionScheduler_QueueRunner::instance();
		for ( $i = 0; $i < 10 && $runner->run( 'WP CLI' ); $i++ ) {
			// Each batch runs what is due; a few rounds empty the queue.
		}
	}
	if ( class_exists( 'Automattic\WooCommerce\Admin\API\Reports\Customers\DataStore' ) ) {
		foreach ( $demo->ids( 'users' ) as $id ) {
			Automattic\WooCommerce\Admin\API\Reports\Customers\DataStore::update_registered_customer( $id );
		}
	}
	$demo->log( 'dates settled, ' . $moved . ' plugin pages moved to opening day' );
};
