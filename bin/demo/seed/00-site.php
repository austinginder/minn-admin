<?php
/**
 * The site itself and its people: name, clock, the staff accounts and the
 * customers' accounts with their addresses.
 *
 * @package minn-admin
 */

return function ( Minn_Demo_Seed $demo ) {
	update_option( 'blogname', 'Larkspur & Fern' );
	update_option( 'blogdescription', 'Plants, seeds and botanical prints from a small shop in Portland' );
	update_option( 'timezone_string', 'America/Los_Angeles' );
	update_option( 'date_format', 'F j, Y' );
	update_option( 'time_format', 'g:i a' );
	update_option( 'start_of_week', 0 );
	update_option( 'admin_email', 'june@larkspur.example' );
	update_option( 'new_admin_email', 'june@larkspur.example' );
	update_option( 'users_can_register', 0 );
	update_option( 'default_comment_status', 'open' );
	update_option( 'comment_moderation', 0 );
	update_option( 'comment_previously_approved', 1 );

	// The install's own sample content goes; the journal replaces it.
	foreach ( array( 'hello-world', 'sample-page' ) as $slug ) {
		$found = get_posts( array( 'name' => $slug, 'post_type' => array( 'post', 'page' ), 'post_status' => 'any', 'numberposts' => 1 ) );
		if ( $found ) {
			wp_delete_post( $found[0]->ID, true );
		}
	}
	$uncategorized = get_term_by( 'slug', 'uncategorized', 'category' );
	if ( $uncategorized ) {
		wp_update_term( $uncategorized->term_id, 'category', array( 'name' => 'Shop News', 'slug' => 'shop-news' ) );
	}

	// The owner is the account the recording signs in as.
	$owner = get_user_by( 'id', 1 );
	wp_update_user(
		array(
			'ID'           => $owner->ID,
			'first_name'   => 'June',
			'last_name'    => 'Alder',
			'display_name' => 'June Alder',
			'nickname'     => 'June',
			'user_email'   => 'june@larkspur.example',
			'description'  => 'Owner of Larkspur & Fern. Grows too many tomatoes every summer.',
			'user_url'     => '',
		)
	);
	$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->users, array( 'user_registered' => $demo->gmt( $demo->ago( 900, 8, 12 ) ) ), array( 'ID' => $owner->ID ) );
	clean_user_cache( $owner->ID );
	$demo->remember( 'users', 'june', $owner->ID );

	foreach ( Minn_Demo_Seed::cast() as $i => $p ) {
		if ( null === $p['role'] || 'june' === $p['slug'] ) {
			continue;
		}
		$old = get_user_by( 'email', $p['email'] );
		if ( $old ) {
			$id = $old->ID;
		} else {
			$id = wp_insert_user(
				array(
					'user_login'      => $p['slug'],
					'user_email'      => $p['email'],
					'user_pass'       => wp_generate_password( 24 ),
					'first_name'      => $p['first'],
					'last_name'       => $p['last'],
					'display_name'    => $p['name'],
					'nickname'        => $p['first'],
					'role'            => $p['role'],
					// Staff joined first, customers over the last year.
					'user_registered' => $demo->gmt( $demo->ago( 'customer' === $p['role'] ? 340 - $i * 14 : 700 - $i * 30, 9 + $i % 8, ( $i * 17 ) % 60 ) ),
				)
			);
			if ( is_wp_error( $id ) ) {
				WP_CLI::error( $p['slug'] . ': ' . $id->get_error_message() );
			}
		}
		$demo->remember( 'users', $p['slug'], $id );
		if ( 'customer' === $p['role'] ) {
			foreach ( array( 'billing', 'shipping' ) as $kind ) {
				update_user_meta( $id, "{$kind}_first_name", $p['first'] );
				update_user_meta( $id, "{$kind}_last_name", $p['last'] );
				update_user_meta( $id, "{$kind}_address_1", $p['street'] );
				update_user_meta( $id, "{$kind}_city", $p['city'] );
				update_user_meta( $id, "{$kind}_state", $p['state'] );
				update_user_meta( $id, "{$kind}_postcode", $p['postcode'] );
				update_user_meta( $id, "{$kind}_country", 'US' );
			}
			update_user_meta( $id, 'billing_email', $p['email'] );
			update_user_meta( $id, 'billing_phone', $p['phone'] );
		}
	}
	$bios = array(
		'theo'  => 'Edits the journal and answers the plant-care questions that come in by email.',
		'priya' => 'Runs the workshops. Terrariums, propagation and anything with moss.',
		'mateo' => 'Packs every order and keeps the stockroom honest.',
		'hana'  => 'Writes about small spaces and the plants that fit them.',
	);
	foreach ( $bios as $slug => $bio ) {
		update_user_meta( $demo->id( 'users', $slug ), 'description', $bio );
	}
	$demo->log( count( $demo->ids( 'users' ) ) . ' people' );
};
