<?php
/**
 * Commerce extras: the Garden Club (WooCommerce Memberships), the Plant of
 * the Month Club (WooCommerce Subscriptions) and gift cards in all three gift
 * card plugins, each with its own product (YITH: the shop gift card;
 * WooCommerce Gift Cards: a workshop seat; PW: an e-gift card), the orders
 * that bought them and what has been spent since.
 *
 * Every plugin here acts on order status changes: Memberships grants access,
 * YITH, WooCommerce Gift Cards and PW mint cards with random codes, and
 * Subscriptions records renewal payments and swaps customers to its
 * "subscriber" role. While this part runs those reactions are switched off
 * (filters and removed hooks, restored at the end; PW skips a line already
 * marked _pw_gift_cards_created) so the records below are the only ones made,
 * with codes that come from md5 of a seed string.
 *
 * The Garden Club's content rule restricts the journal's Plant Care category
 * to members, so on the public site those posts show the members-only notice.
 *
 * Storage worth knowing:
 * - YITH cards are `gift_card` posts; balance and amount are post meta, and
 *   an order line links to its cards through ywgc_set_order_item_giftcards().
 * - WooCommerce Gift Cards keeps cards in {prefix}woocommerce_gc_cards (unix
 *   UTC dates; `remaining` is the balance). A card bought with a delivery date
 *   is created inactive until it is sent, which Minn shows as Disabled, so no
 *   scheduled card is seeded.
 * - PW (free) has no balance column: it is SUM(amount) of the activity table,
 *   and the recipient lives only on the order line (pw_gift_card_to), so a
 *   card issued by hand shows no recipient. create_date is a TIMESTAMP in the
 *   MySQL session time zone.
 * - Memberships are `wc_user_membership` posts; their dates are UTC post
 *   meta, and pausing or cancelling stamps "now", so dates and notes are
 *   moved back afterwards.
 * - Subscriptions, parents and renewals all live in the HPOS orders table;
 *   WooCommerce Subscriptions checks that a cancelled or end date comes after
 *   the last renewal order.
 *
 * @package minn-admin
 */

return function ( Minn_Demo_Seed $demo ) {
	global $wpdb;
	if ( ! function_exists( 'wc_create_order' ) ) {
		$demo->log( 'WooCommerce is not active: no commerce extras' );
		return;
	}
	$has_wcs  = function_exists( 'wcs_create_subscription' ) && class_exists( 'WC_Product_Subscription' );
	$has_wcm  = function_exists( 'wc_memberships_create_user_membership' );
	$has_ywgc = class_exists( 'YWGC_Gift_Card_Premium' ) && class_exists( 'WC_Product_Gift_Card' );
	$has_wcgc = function_exists( 'WC_GC' ) && class_exists( 'WC_GC_Gift_Card_Data' );
	$has_pwgc = class_exists( 'PW_Gift_Card' ) && class_exists( 'WC_Product_PW_Gift_Card' );

	// Nothing this part does should reach an inbox, whichever plugin sends it.
	add_filter( 'pre_wp_mail', '__return_false', 99 );

	// Each plugin's own reaction to an order changing status, paused for the run.
	$quiet = array(
		array( 'woocommerce_order_status_changed', 'WC_Subscriptions_Renewal_Order::maybe_record_subscription_payment', 10 ),
		array( 'woocommerce_order_status_changed', 'WC_Subscriptions_Order::maybe_record_subscription_payment', 9 ),
		array( 'woocommerce_payment_complete', 'WC_Subscriptions_Renewal_Order::trigger_renewal_payment_complete', 10 ),
		array( 'woocommerce_order_status_failed', 'WC_Subscriptions_Manager::failed_subscription_sign_ups_for_order', 10 ),
		array( 'woocommerce_payment_complete', 'WC_GC_Gift_Card_Product::create_order_gift_cards', 10 ),
		array( 'woocommerce_order_status_completed', 'WC_GC_Gift_Card_Product::create_order_gift_cards', 10 ),
		array( 'woocommerce_order_status_cancelled', 'WC_GC_Gift_Card_Product::deactivate_order_gift_cards', 10 ),
		array( 'woocommerce_order_status_failed', 'WC_GC_Gift_Card_Product::deactivate_order_gift_cards', 10 ),
		array( 'woocommerce_order_status_refunded', 'WC_GC_Gift_Card_Product::deactivate_order_gift_cards', 10 ),
	);
	$removed = array();
	foreach ( $quiet as $q ) {
		if ( has_action( $q[0], $q[1] ) !== $q[2] ) {
			continue;
		}
		$hooked = $GLOBALS['wp_filter'][ $q[0] ]->callbacks[ $q[2] ][ $q[1] ];
		remove_action( $q[0], $q[1], $q[2] );
		$removed[] = array( $q[0], $hooked['function'], $q[2], $hooked['accepted_args'] );
	}
	add_filter( 'ywgc_custom_condition_to_create_gift_card', '__return_false' );
	add_filter( 'wc_memberships_grant_access_from_new_purchase', '__return_false' );
	add_filter( 'woocommerce_subscriptions_update_users_role', '__return_false' );

	// What the last run made, newest dependents first.
	if ( $has_wcs ) {
		foreach ( $demo->ids( 'commerce_subscriptions' ) as $id ) {
			$sub = wcs_get_subscription( $id );
			if ( $sub ) {
				$sub->delete( true );
			}
		}
	}
	foreach ( $demo->ids( 'commerce_memberships' ) as $id ) {
		if ( 'wc_user_membership' === get_post_type( $id ) ) {
			wp_delete_post( $id, true );
		}
	}
	foreach ( $demo->ids( 'commerce_plans' ) as $id ) {
		$plan = $has_wcm ? wc_memberships_get_membership_plan( $id ) : null;
		if ( $plan ) {
			$plan->delete_rules();
		}
		if ( 'wc_membership_plan' === get_post_type( $id ) ) {
			wp_delete_post( $id, true );
		}
	}
	foreach ( $demo->ids( 'commerce_ywgc' ) as $id ) {
		if ( 'gift_card' === get_post_type( $id ) ) {
			wp_delete_post( $id, true );
		}
	}
	if ( $has_wcgc ) {
		foreach ( $demo->ids( 'commerce_wcgc' ) as $id ) {
			WC()->queue()->cancel_all( 'woocommerce_gc_send_expiration_reminder', array( 'gift_card_id' => (int) $id ) );
			$card = new WC_GC_Gift_Card_Data( (int) $id );
			if ( $card->get_id() ) {
				$card->delete();
			}
		}
	}
	if ( $has_pwgc ) {
		foreach ( $demo->ids( 'commerce_pwgc' ) as $id ) {
			$wpdb->delete( $wpdb->pimwick_gift_card_activity, array( 'pimwick_gift_card_id' => (int) $id ) );
			$wpdb->delete( $wpdb->pimwick_gift_card, array( 'pimwick_gift_card_id' => (int) $id ) );
		}
	}
	foreach ( $demo->ids( 'commerce_orders' ) as $id ) {
		$order = wc_get_order( $id );
		if ( $order ) {
			$order->delete( true );
		}
	}
	foreach ( $demo->ids( 'commerce_products' ) as $id ) {
		$product = wc_get_product( $id );
		if ( $product ) {
			foreach ( $product->get_children() as $child ) {
				wp_delete_post( $child, true );
			}
			wp_delete_post( $id, true );
		}
	}
	foreach ( array( 'subscriptions', 'memberships', 'plans', 'ywgc', 'wcgc', 'pwgc', 'orders', 'products' ) as $group ) {
		$demo->forget( 'commerce_' . $group );
	}
	$demo->as_user( 'june' );

	// A code in the shape every one of these plugins generates (XXXX-XXXX-XXXX-XXXX), from a seed.
	$code = function ( $seed ) {
		$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		$hash  = md5( 'larkspur-gift-' . $seed );
		$out   = '';
		for ( $i = 0; $i < 16; $i++ ) {
			$out .= $chars[ hexdec( $hash[ $i * 2 ] . $hash[ $i * 2 + 1 ] ) % 32 ];
			if ( 3 === $i % 4 && $i < 15 ) {
				$out .= '-';
			}
		}
		return $out;
	};

	// Notes WooCommerce writes while an order is built carry the seed's clock; move them to the order's.
	$redate_notes = function ( $post_id, $ts ) use ( $wpdb, $demo ) {
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->comments} SET comment_date = %s, comment_date_gmt = %s WHERE comment_post_ID = %d",
				$demo->local( $ts ),
				$demo->gmt( $ts ),
				$post_id
			)
		);
	};
	$note = function ( $order, $text, $ts, $by_user = false ) use ( $demo ) {
		$id = $order->add_order_note( $text, 0, $by_user );
		wp_update_comment(
			array(
				'comment_ID'       => $id,
				'comment_date'     => $demo->local( $ts ),
				'comment_date_gmt' => $demo->gmt( $ts ),
			)
		);
	};

	$cat_ids = array();
	foreach ( array( 'Gift Cards', 'Clubs' ) as $name ) {
		$term             = term_exists( $name, 'product_cat' );
		$term             = $term ? $term : wp_insert_term( $name, 'product_cat' );
		$cat_ids[ $name ] = (int) $term['term_id'];
	}
	update_term_meta( $cat_ids['Gift Cards'], 'thumbnail_id', $demo->image( 'coming-soon-bg-image' ) );
	update_term_meta( $cat_ids['Clubs'], 'thumbnail_id', $demo->image( 'link-in-bio-image' ) );
	$product_base = function ( $product, $name, $slug, $sku, $cat, $image, $short, $days ) use ( $demo, $cat_ids ) {
		$product->set_name( $name );
		$product->set_slug( $slug );
		$product->set_sku( $sku );
		$product->set_status( 'publish' );
		$product->set_short_description( $short );
		$product->set_description( '<p>' . esc_html( $short ) . '</p>' );
		$product->set_category_ids( array( $cat_ids[ $cat ] ) );
		$product->set_image_id( $demo->image( $image ) );
		$product->set_date_created( $demo->ago( $days, 9, 30 ) );
		return $product;
	};

	$payments = array(
		'card'   => array( 'stripe', 'Credit card' ),
		'paypal' => array( 'ppcp-gateway', 'PayPal' ),
		'bacs'   => array( 'bacs', 'Direct bank transfer' ),
	);
	$serial     = 0;
	$make_order = function ( $key, $who, $ts, $lines, $status, $pay = 'card', $ship = false, $customer_note = '' ) use ( $demo, $payments, $redate_notes, &$serial ) {
		++$serial;
		$person = $demo->person( $who );
		$order  = wc_create_order( array( 'customer_id' => $person['id'] ? $person['id'] : 0 ) );
		$items  = array();
		foreach ( $lines as $line ) {
			$args    = isset( $line[2] ) ? $line[2] : array();
			$item_id = $order->add_product( $line[0], $line[1], $args );
			$item    = $order->get_item( $item_id );
			foreach ( isset( $line[3] ) ? $line[3] : array() as $meta_key => $meta_value ) {
				$item->add_meta_data( $meta_key, $meta_value, true );
			}
			$item->save();
			$items[] = $item;
		}
		$address = array(
			'first_name' => $person['first'],
			'last_name'  => $person['last'],
			'company'    => $person['company'],
			'address_1'  => $person['street'],
			'city'       => $person['city'],
			'state'      => $person['state'],
			'postcode'   => $person['postcode'],
			'country'    => 'US',
		);
		$order->set_address( $address + array( 'email' => $person['email'], 'phone' => $person['phone'] ), 'billing' );
		if ( $ship ) {
			$order->set_address( $address, 'shipping' );
			$rate = new WC_Order_Item_Shipping();
			$rate->set_method_title( 'Free shipping' );
			$rate->set_method_id( 'free_shipping' );
			$rate->set_total( '0.00' );
			$order->add_item( $rate );
		}
		$order->set_payment_method( $payments[ $pay ][0] );
		$order->set_payment_method_title( $payments[ $pay ][1] );
		$order->set_customer_note( $customer_note );
		$order->set_created_via( 'checkout' );
		$order->set_customer_ip_address( '198.51.100.' . ( 20 + $serial ) );
		$order->set_customer_user_agent( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15' );
		$order->calculate_totals( false );
		$order->set_date_created( $ts );
		if ( in_array( $status, array( 'processing', 'completed', 'cancelled' ), true ) && 'bacs' !== $pay ) {
			$order->set_date_paid( $ts + 60 );
			$order->set_transaction_id( strtoupper( substr( md5( 'commerce' . $key ), 0, 14 ) ) );
		}
		if ( 'completed' === $status ) {
			$order->set_date_completed( $ts + 5 * MINUTE_IN_SECONDS );
		}
		$order->set_status( $status );
		$order->save();
		$redate_notes( $order->get_id(), $ts + 5 * MINUTE_IN_SECONDS );
		$demo->remember( 'commerce_orders', $key, $order->get_id() );
		return array( $order, $items );
	};
	$counts = array();

	/*
	 * The Garden Club: a year of 10% off and the Plant Care library in the
	 * journal, bought as a product. A draft Workshop Pass sits beside it.
	 */
	if ( $has_wcm ) {
		$club = $product_base( new WC_Product_Simple(), 'Garden Club Membership, 1 year', 'garden-club-membership', 'LF-CL-001', 'Clubs', 'link-in-bio-image', 'A year in the Garden Club: 10% off every order and the full Plant Care library in the journal.', 450 );
		$club->set_regular_price( '40' );
		$club->set_virtual( true );
		$club_id = $club->save();
		$demo->remember( 'commerce_products', 'garden-club-membership', $club_id );

		$plan_id = wp_insert_post(
			array(
				'post_type'    => 'wc_membership_plan',
				'post_title'   => 'Garden Club',
				'post_name'    => 'garden-club',
				'post_status'  => 'publish',
				'post_author'  => $demo->id( 'users', 'june' ),
				'post_date'    => $demo->local( $demo->ago( 450, 9, 0 ) ),
				'post_content' => 'Our year-round club: 10% off everything in the shop and the Plant Care guides in the journal.',
			)
		);
		$demo->remember( 'commerce_plans', 'garden-club', $plan_id );
		$plan = wc_memberships_get_membership_plan( $plan_id );
		$plan->set_access_method( 'purchase' );
		$plan->set_product_ids( array( $club_id ) );
		$plan->set_access_length( '1 years' );
		$plan->set_members_area_sections();
		$plan->set_rules(
			array(
				array(
					'id'                 => 'rule_' . substr( md5( 'garden-club-discount' ), 0, 13 ),
					'membership_plan_id' => $plan_id,
					'rule_type'          => 'purchasing_discount',
					'content_type'       => 'post_type',
					'content_type_name'  => 'product',
					'object_ids'         => array(),
					'discount_type'      => 'percentage',
					'discount_amount'    => '10',
					'active'             => 'yes',
				),
				array(
					'id'                 => 'rule_' . substr( md5( 'garden-club-plant-care' ), 0, 13 ),
					'membership_plan_id' => $plan_id,
					'rule_type'          => 'content_restriction',
					'content_type'       => 'taxonomy',
					'content_type_name'  => 'category',
					'object_ids'         => array_filter( array( (int) get_cat_ID( 'Plant Care' ) ) ),
					'access_schedule'    => 'immediate',
				),
			)
		);

		$pass_id = wp_insert_post(
			array(
				'post_type'    => 'wc_membership_plan',
				'post_title'   => 'Workshop Pass',
				'post_name'    => 'workshop-pass',
				'post_status'  => 'draft',
				'post_author'  => $demo->id( 'users', 'priya' ),
				'post_date'    => $demo->local( $demo->ago( 6, 16, 10 ) ),
				'post_content' => 'Six months of Saturday workshops at a members price. Still working out the details.',
			)
		);
		$demo->remember( 'commerce_plans', 'workshop-pass', $pass_id );
		$pass = wc_memberships_get_membership_plan( $pass_id );
		$pass->set_access_method( 'manual' );
		$pass->set_access_length( '6 months' );
		$workshops = term_exists( 'Workshops', 'product_cat' );
		if ( $workshops ) {
			$pass->set_rules(
				array(
					array(
						'id'                 => 'rule_' . substr( md5( 'workshop-pass-discount' ), 0, 13 ),
						'membership_plan_id' => $pass_id,
						'rule_type'          => 'purchasing_discount',
						'content_type'       => 'taxonomy',
						'content_type_name'  => 'product_cat',
						'object_ids'         => array( (int) $workshops['term_id'] ),
						'discount_type'      => 'percentage',
						'discount_amount'    => '15',
						'active'             => 'yes',
					),
				)
			);
		}

		// who => joined days ago, status, paid by (null: granted by hand), [ days ago, note ] for what happened since
		$members = array(
			'liam'   => array( 150, 'active', 'card', null ),
			'olivia' => array( 140, 'active', 'card', null ),
			'noah'   => array( 118, 'paused', 'card', array( 12, 'Noah is away for six weeks and asked us to hold his membership until he is back.' ) ),
			'emma'   => array( 96, 'active', 'paypal', null ),
			'ethan'  => array( 81, 'cancelled', 'paypal', array( 11, 'Cancelled at Ethan\'s request: he is not gardening this year. No refund, as agreed with him.' ) ),
			'marcus' => array( 62, 'active', 'paypal', null ),
			'chloe'  => array( 45, 'complimentary', null, array( 45, 'Complimentary for Chloe\'s school pollinator garden. Thank you for spreading the meadow mix around!' ) ),
			'sofia'  => array( 33, 'active', 'card', null ),
			'aisha'  => array( 11, 'active', 'card', null ),
			'zoe'    => array( 4, 'active', 'card', null ),
			'ben'    => array( 2, 'active', 'paypal', null ),
			'grace'  => array( 395, 'expired', 'card', null ),
			'mia'    => array( 430, 'expired', 'card', null ),
		);
		foreach ( $members as $who => $m ) {
			list( $days, $status, $pay, $event ) = $m;
			$person = $demo->person( $who );
			$start  = $demo->ago( $days, 8 + strlen( $who ), 5 * strlen( $who ) );
			$args   = array(
				'plan_id' => $plan_id,
				'user_id' => $person['id'],
			);
			if ( $pay ) {
				list( $order ) = $make_order( 'club-' . $who, $who, $start, array( array( $club, 1 ) ), 'completed', $pay );
				$args         += array(
					'product_id' => $club_id,
					'order_id'   => $order->get_id(),
				);
			}
			$member = wc_memberships_create_user_membership( $args );
			$id     = $member->get_id();
			$demo->remember( 'commerce_memberships', $who, $id );
			$member->set_start_date( $demo->gmt( $start ) );
			if ( 'complimentary' === $status ) {
				$member->set_end_date( '' );
			} else {
				$member->set_end_date( $demo->gmt( strtotime( '+1 year', $start ) ) );
			}
			if ( 'paused' === $status ) {
				$member->pause_membership();
				$member->set_paused_date( $demo->gmt( $demo->ago( $event[0], 10, 20 ) ) );
				update_post_meta( $id, '_paused_intervals', array( $demo->ago( $event[0], 10, 20 ) => '' ) );
			} elseif ( 'cancelled' === $status ) {
				$member->cancel_membership();
				$member->set_cancelled_date( $demo->gmt( $demo->ago( $event[0], 14, 5 ) ) );
			} elseif ( 'active' !== $status ) {
				$member->update_status( $status );
			}
			$wpdb->update(
				$wpdb->posts,
				array(
					'post_date'         => $demo->local( $start ),
					'post_date_gmt'     => $demo->gmt( $start ),
					'post_modified'     => $demo->local( $start ),
					'post_modified_gmt' => $demo->gmt( $start ),
				),
				array( 'ID' => $id )
			);
			clean_post_cache( $id );

			// The notes the plugin wrote on the way carry today's date: replace them with the history.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->comments} WHERE comment_post_ID = %d AND comment_type = 'user_membership_note'", $id ) );
			$member = wc_memberships_get_user_membership( $id );
			$story  = array();
			$story[ $start + 120 ] = $pay
				? sprintf( 'Membership access granted from purchasing Garden Club Membership, 1 year (Order #%s).', $args['order_id'] )
				: 'Membership access granted manually by June Alder.';
			if ( $event ) {
				$story[ max( $start + 600, $demo->ago( $event[0], 14, 6 ) ) ] = $event[1];
			}
			if ( 'expired' === $status ) {
				$story[ strtotime( '+1 year', $start ) ] = 'Membership expired.';
			}
			foreach ( $story as $ts => $text ) {
				$member->add_note( $text );
				$note_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(comment_ID) FROM {$wpdb->comments} WHERE comment_post_ID = %d", $id ) );
				$wpdb->update(
					$wpdb->comments,
					array(
						'comment_date'     => $demo->local( $ts ),
						'comment_date_gmt' => $demo->gmt( $ts ),
					),
					array( 'comment_ID' => $note_id )
				);
			}
			clean_comment_cache( $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID = %d", $id ) ) );
		}
		$counts[] = count( $members ) . ' members';
	}

	/*
	 * The Plant of the Month Club: $30 a month, one plant and a care card,
	 * free shipping. Parent orders, renewals and every status the list filters on.
	 */
	if ( $has_wcs ) {
		$potm = $product_base( new WC_Product_Subscription(), 'Plant of the Month Club', 'plant-of-the-month-club', 'LF-CL-002', 'Clubs', 'grid-flower-1', 'A healthy plant chosen by June every month, with a care card. Shipping included.', 170 );
		$potm->set_regular_price( '30' );
		$potm->set_weight( '3' );
		foreach ( array(
			'_subscription_price'               => '30',
			'_subscription_period'              => 'month',
			'_subscription_period_interval'     => '1',
			'_subscription_length'              => '0',
			'_subscription_trial_length'        => '0',
			'_subscription_trial_period'        => 'day',
			'_subscription_sign_up_fee'         => '',
			'_subscription_limit'               => 'no',
			'_subscription_one_time_shipping'   => 'no',
			'_subscription_payment_sync_date'   => '0',
		) as $key => $value ) {
			$potm->update_meta_data( $key, $value );
		}
		$potm_id = $potm->save();
		$demo->remember( 'commerce_products', 'plant-of-the-month-club', $potm_id );
		$potm = wc_get_product( $potm_id );

		// who => started days ago, status, payment, renewals that failed (1-based), [ days ago, note ] for the ending
		$clubbers = array(
			'olivia' => array( 140, 'active', 'card', array(), null ),
			'daniel' => array( 141, 'cancelled', 'paypal', array(), array( 52, 'Daniel asked us to cancel: his windowsills are full. He may come back in spring.' ) ),
			'sofia'  => array( 128, 'pending-cancel', 'card', array(), array( 3, 'Sofia is moving to a place without much light and asked to stop after the month she has paid for.' ) ),
			'marcus' => array( 97, 'active', 'paypal', array(), null ),
			'chloe'  => array( 70, 'active', 'card', array(), null ),
			'liam'   => array( 63, 'on-hold', 'card', array( 2 ), array( 2, 'Renewal payment failed: the card on file has expired. Emailed Liam a link to update it.' ) ),
			'zoe'    => array( 9, 'active', 'card', array(), null ),
			'isla'   => array( 1.4, 'pending', 'bacs', array(), null ),
		);
		$utc = new DateTimeZone( 'UTC' );
		foreach ( $clubbers as $who => $c ) {
			list( $days, $status, $pay, $fails, $ending ) = $c;
			$person = $demo->person( $who );
			$start  = $demo->ago( $days, 7 + strlen( $who ), 3 * strlen( $who ) );
			list( $parent ) = $make_order( 'potm-' . $who, $who, $start, array( array( $potm, 1 ) ), 'pending' === $status ? 'pending' : 'completed', $pay, true );
			$sub = wcs_create_subscription(
				array(
					'order_id'         => $parent->get_id(),
					'customer_id'      => $person['id'],
					'status'           => 'pending',
					'billing_period'   => 'month',
					'billing_interval' => 1,
					'start_date'       => $demo->gmt( $start ),
					'date_created'     => $demo->gmt( $start ),
					'created_via'      => 'checkout',
				)
			);
			if ( is_wp_error( $sub ) ) {
				$demo->log( 'subscription for ' . $who . ': ' . $sub->get_error_message() );
				continue;
			}
			$demo->remember( 'commerce_subscriptions', $who, $sub->get_id() );
			$sub->add_product( $potm, 1 );
			$address = array(
				'first_name' => $person['first'],
				'last_name'  => $person['last'],
				'address_1'  => $person['street'],
				'city'       => $person['city'],
				'state'      => $person['state'],
				'postcode'   => $person['postcode'],
				'country'    => 'US',
			);
			$sub->set_address( $address + array( 'email' => $person['email'], 'phone' => $person['phone'] ), 'billing' );
			$sub->set_address( $address, 'shipping' );
			$rate = new WC_Order_Item_Shipping();
			$rate->set_method_title( 'Free shipping' );
			$rate->set_method_id( 'free_shipping' );
			$rate->set_total( '0.00' );
			$sub->add_item( $rate );
			$sub->set_payment_method( $payments[ $pay ][0] );
			$sub->set_payment_method_title( $payments[ $pay ][1] );
			// The demo has no card gateway, so is_manual() stays true whatever is stored; only the bank transfer is flagged manual.
			$sub->set_requires_manual_renewal( 'bacs' === $pay );
			$sub->calculate_totals();
			$sub->save();

			// Monthly renewals from the start date until now (or until the club ended).
			$until    = $ending && in_array( $status, array( 'cancelled' ), true ) ? $demo->ago( $ending[0], 9, 0 ) : $demo->now;
			$story    = array( $start + 120 => array( 'Status changed from Pending payment to Active.', false ) );
			$next     = 0;
			for ( $k = 1; 'pending' !== $status && $k < 24; $k++ ) {
				$when = new DateTime( '@' . $start );
				$when->setTimezone( $utc );
				$when->modify( '+' . $k . ' month' );
				$ts = $when->getTimestamp();
				if ( $ts > $until ) {
					$next = $ts;
					break;
				}
				$renewal = wcs_create_renewal_order( $sub );
				if ( is_wp_error( $renewal ) ) {
					continue;
				}
				$failed = in_array( $k, $fails, true );
				$renewal->set_payment_method( $payments[ $pay ][0] );
				$renewal->set_payment_method_title( $payments[ $pay ][1] );
				$renewal->set_date_created( $ts );
				$renewal->set_customer_ip_address( '' );
				if ( ! $failed ) {
					$renewal->set_date_paid( $ts + 60 );
					$renewal->set_date_completed( $ts + DAY_IN_SECONDS );
					$renewal->set_transaction_id( strtoupper( substr( md5( 'renewal' . $who . $k ), 0, 14 ) ) );
				}
				$renewal->set_status( $failed ? 'failed' : 'completed' );
				$renewal->save();
				$redate_notes( $renewal->get_id(), $ts + 120 );
				if ( $failed ) {
					$note( $renewal, 'Card declined: the card on file expired last month.', $ts + 180 );
				}
				$demo->remember( 'commerce_orders', 'potm-' . $who . '-' . $k, $renewal->get_id() );
				$story[ $ts + 60 ] = array( sprintf( 'Order #%s created to record renewal.', $renewal->get_order_number() ), false );
				if ( $failed ) {
					$story[ $ts + 200 ] = array( 'Payment failed. Status changed from Active to On hold.', false );
				}
			}

			$sub   = wcs_get_subscription( $sub->get_id() );
			$ended = $ending ? $demo->ago( $ending[0], 'cancelled' === $status ? 11 : 18, 'cancelled' === $status ? 15 : 40 ) : 0;
			if ( 'pending' !== $status ) {
				if ( $next ) {
					$sub->update_dates( array( 'next_payment' => $demo->gmt( $next ) ) );
				}
				$sub->update_status( 'active' );
			}
			if ( 'on-hold' === $status ) {
				$sub->update_status( 'on-hold' );
				$sub->delete_date( 'next_payment' );
				$sub->save();
			} elseif ( 'pending-cancel' === $status ) {
				$sub->update_status( 'pending-cancel' );
				$sub->update_dates( array( 'cancelled' => $demo->gmt( $ended ) ) );
				$sub->save();
				$story[ $ended ] = array( 'Status changed from Active to Pending cancellation.', false );
			} elseif ( 'cancelled' === $status ) {
				$sub->update_status( 'cancelled' );
				$sub->update_dates( array( 'cancelled' => $demo->gmt( $ended ), 'end' => $demo->gmt( $ended ) ) );
				$sub->save();
				$story[ $ended ] = array( 'Status changed from Active to Cancelled.', false );
			}
			if ( $ending ) {
				// The note that explains it, a few minutes after (for a failed renewal, after the plugin's own).
				$story[ max( $ended, max( array_keys( $story ) ) ) + 5 * MINUTE_IN_SECONDS ] = array( $ending[1], true );
			}
			if ( 'pending' === $status ) {
				$story = array( $start + 60 => array( 'Awaiting bank transfer for the first month.', false ) );
			}

			// Replace the notes the plugin stamped with today's date by the club's history.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->comments} WHERE comment_post_ID = %d", $sub->get_id() ) );
			ksort( $story );
			foreach ( $story as $ts => $n ) {
				$note( $sub, $n[0], $ts, $n[1] );
			}
			$redate_notes( $parent->get_id(), $start + 5 * MINUTE_IN_SECONDS );
		}
		$counts[] = count( $demo->ids( 'commerce_subscriptions' ) ) . ' subscriptions';
	}

	/*
	 * YITH: the shop's own gift card, $25 / $50 / $100, emailed.
	 * key => buyer (null: issued by hand), recipient, amount, days ago, balance, state, message, internal note, sender of a card issued by hand
	 */
	if ( $has_ywgc ) {
		$yp = $product_base( new WC_Product_Gift_Card( 0 ), 'Larkspur & Fern Gift Card', 'gift-card', 'LF-GC-001', 'Gift Cards', 'coming-soon-bg-image', 'Let them choose: emailed with your note, good for plants, prints and workshops for a year.', 200 );
		$yp->set_virtual( true );
		$yp_id = $yp->save();
		$yp->save_amounts( array( 25, 50, 100 ) );
		$demo->remember( 'commerce_products', 'gift-card', $yp_id );

		$cards = array(
			'mia-replacement' => array( null, 'mia', 50, 32, 28, 'active', 'Happy new place! Fill it with green.', 'Replaces the card Chloe bought for Mia the day before, which never reached her. The old code is disabled.', 'Chloe Martin' ),
			'olivia-grace'    => array( 'olivia', 'grace', 50, 40, 16, 'active', 'Happy birthday, Grace! Pick something that flowers.', '' ),
			'marcus-aisha'    => array( 'marcus', 'aisha', 25, 28, 25, 'active', 'Thanks for watering my ferns all summer.', '' ),
			'ben-zoe'         => array( 'ben', 'zoe', 100, 18, 0, 'active', 'For the new balcony garden. Love, Ben', '' ),
			'emma-noah'       => array( 'emma', 'noah', 50, 9, 12, 'active', 'Congratulations on the new job!', '' ),
			'chloe-mia'       => array( 'chloe', 'mia', 50, 33, 50, 'disabled', 'Happy new place! Fill it with green.', 'Mia never received the email and the code could not be found. Disabled and replaced by hand.' ),
			'plant-swap'      => array( null, 'lucas', 25, 58, 25, 'expired', 'Winner of the spring plant swap raffle. Enjoy!', 'Raffle prize from the spring plant swap. Good for 45 days.', 'Larkspur & Fern' ),
		);
		foreach ( $cards as $key => $g ) {
			list( $buyer, $to, $amount, $days, $balance, $state, $message, $internal ) = $g;
			$sender    = isset( $g[8] ) ? $g[8] : '';
			$recipient = $demo->person( $to );
			$ts        = $demo->ago( $days, 11, 10 + strlen( $key ) );
			$order     = null;
			$item      = null;
			if ( $buyer ) {
				$from = $demo->person( $buyer );
				list( $order, $items ) = $make_order(
					'ywgc-' . $key,
					$buyer,
					$ts,
					array(
						array(
							$yp,
							1,
							array(
								'subtotal' => $amount,
								'total'    => $amount,
							),
							array(
								'_ywgc_product_id'     => $yp_id,
								'_ywgc_amount'         => $amount,
								'_ywgc_is_digital'     => true,
								'_ywgc_recipients'     => array( $recipient['email'] ),
								'_ywgc_sender_name'    => $from['first'],
								'_ywgc_recipient_name' => $recipient['first'],
								'_ywgc_message'        => $message,
							),
						),
					),
					'completed'
				);
				$item = $items[0];
			}
			$gc                   = new YWGC_Gift_Card_Premium();
			$gc->product_id       = $yp_id;
			$gc->order_id         = $order ? $order->get_id() : 0;
			$gc->order_item_id    = $item ? $item->get_id() : 0;
			$gc->customer_id      = $buyer ? $from['id'] : 0;
			$gc->gift_card_number = $code( 'ywgc-' . $key );
			$gc->total_amount     = $amount;
			$gc->update_balance( $balance );
			$gc->currency       = 'USD';
			$gc->version        = defined( 'YITH_YWGC_VERSION' ) ? YITH_YWGC_VERSION : '';
			$gc->is_digital     = true;
			$gc->recipient      = $recipient['email'];
			$gc->recipient_name = $recipient['name'];
			$gc->sender_name    = $buyer ? $from['name'] : $sender;
			$gc->message        = $message;
			$gc->expiration     = 'expired' === $state ? $ts + 45 * DAY_IN_SECONDS : strtotime( '+1 year', $ts );
			if ( property_exists( $gc, 'is_manual_amount' ) ) {
				$gc->is_manual_amount = ! $buyer;
			}
			if ( property_exists( $gc, 'internal_notes' ) ) {
				$gc->internal_notes = $internal;
			}
			$gc->status = 'disabled' === $state ? 'ywgc-disabled' : 'publish';
			$gc->save();
			update_post_meta( $gc->ID, '_ywgc_internal_notes', $internal );
			$wpdb->update(
				$wpdb->posts,
				array(
					'post_author'       => $demo->id( 'users', 'june' ),
					'post_date'         => $demo->local( $ts + 120 ),
					'post_date_gmt'     => $demo->gmt( $ts + 120 ),
					'post_modified'     => $demo->local( $ts + 120 ),
					'post_modified_gmt' => $demo->gmt( $ts + 120 ),
				),
				array( 'ID' => $gc->ID )
			);
			clean_post_cache( $gc->ID );
			if ( $item ) {
				ywgc_set_order_item_giftcards( $item->get_id(), array( $gc->ID ) );
			}
			$demo->remember( 'commerce_ywgc', $key, $gc->ID );
		}
		$counts[] = count( $cards ) . ' YITH cards';
	}

	/*
	 * WooCommerce Gift Cards: a seat at a Saturday workshop, good for a year.
	 * key => buyer, recipient, seats, days ago, remaining, redeemed to an account, order status, message
	 */
	if ( $has_wcgc ) {
		$wp_card = $product_base( new WC_Product_Simple(), 'Workshop Gift Card', 'workshop-gift-card', 'LF-GC-002', 'Gift Cards', 'coral-square', 'A seat at any Saturday workshop: terrariums, propagation or kokedama with Priya. Emailed, good for a year.', 420 );
		$wp_card->set_regular_price( '55' );
		$wp_card->set_virtual( true );
		$wp_card->update_meta_data( '_gift_card', 'yes' );
		$wp_card->update_meta_data( '_gift_card_expiration_days', 365 );
		$wp_card_id = $wp_card->save();
		$demo->remember( 'commerce_products', 'workshop-gift-card', $wp_card_id );

		$cards = array(
			'grace-ben'   => array( 'grace', 'ben', 1, 380, 55, false, 'completed', 'A morning of moss and glass. You will love Priya.' ),
			'daniel-emma' => array( 'daniel', 'emma', 1, 31, 0, true, 'completed', 'For the terrarium workshop you keep talking about.' ),
			'ruth-owen'   => array( 'ruth', 'owen', 1, 20, 55, false, 'completed', 'Thank you for lending us the dental office plants for the gala. From all of us at Lovejoy Hall.' ),
			'aisha-liam'  => array( 'aisha', 'liam', 2, 14, 55, true, 'completed', 'One for you and one for a friend. Happy anniversary!' ),
			'ethan-isla'  => array( 'ethan', 'isla', 1, 12, 55, false, 'cancelled', 'Happy birthday from Atlanta!' ),
			'noah-mia'    => array( 'noah', 'mia', 1, 6, 55, false, 'completed', 'Propagation workshop, then lunch on me.' ),
		);
		foreach ( $cards as $key => $g ) {
			list( $buyer, $to, $seats, $days, $remaining, $redeemed, $status, $message ) = $g;
			$from      = $demo->person( $buyer );
			$recipient = $demo->person( $to );
			$ts        = $demo->ago( $days, 12, 15 + strlen( $key ) );
			$amount    = 55 * $seats;
			list( $order, $items ) = $make_order(
				'wcgc-' . $key,
				$buyer,
				$ts,
				array(
					array(
						$wp_card,
						1,
						array(
							'subtotal' => $amount,
							'total'    => $amount,
						),
						array(
							'wc_gc_giftcard_to'      => $recipient['email'],
							'wc_gc_giftcard_from'    => $from['first'],
							'wc_gc_giftcard_message' => $message,
							'wc_gc_giftcard_amount'  => $amount,
						),
					),
				),
				$status
			);
			$item    = $items[0];
			$card_id = WC_GC()->db->giftcards->add(
				array(
					'is_active'        => 'cancelled' === $status ? 'off' : 'on',
					'is_virtual'       => 'on',
					'code'             => $code( 'wcgc-' . $key ),
					'order_id'         => $order->get_id(),
					'order_item_id'    => $item->get_id(),
					'recipient'        => $recipient['email'],
					'redeemed_by'      => $redeemed ? $recipient['id'] : 0,
					'sender'           => $from['first'],
					'sender_email'     => $from['email'],
					'message'          => $message,
					'balance'          => $amount,
					'remaining'        => $remaining,
					'create_date'      => $ts + 120,
					'delivered'        => '0',
					'expire_date'      => strtotime( '+365 days', $ts ),
					'redeem_date'      => $redeemed ? $ts + 2 * DAY_IN_SECONDS : 0,
					'last_modify_date' => $ts + 120,
				)
			);
			// add() fills an empty remaining with the full amount, so a spent card is set afterwards.
			if ( (float) $remaining !== (float) $amount ) {
				$data = new WC_GC_Gift_Card_Data( $card_id );
				$data->set_balance( $remaining );
				$data->save();
			}
			$item->update_meta_data( 'wc_gc_giftcards', array( $card_id ) );
			if ( 'cancelled' === $status ) {
				$item->update_meta_data( '_wc_gc_deactivated_through_order_status', 'yes' );
			}
			$item->save();
			$log = array(
				array( 'issued', $order->get_id(), $amount, $ts + 120, $from ),
			);
			if ( $redeemed ) {
				$log[] = array( 'redeemed', 0, $amount, $ts + 2 * DAY_IN_SECONDS, $recipient );
			}
			if ( $remaining < $amount ) {
				$log[] = array( 'used', 0, $amount - $remaining, $ts + 3 * DAY_IN_SECONDS, $recipient );
			}
			foreach ( $log as $l ) {
				WC_GC()->db->activity->add(
					array(
						'type'       => $l[0],
						'gc_id'      => $card_id,
						'gc_code'    => $code( 'wcgc-' . $key ),
						'object_id'  => $l[1],
						'user_id'    => $l[4]['id'],
						'user_email' => $l[4]['email'],
						'amount'     => $l[2],
						'date'       => $l[3],
					)
				);
			}
			$demo->remember( 'commerce_wcgc', $key, $card_id );
		}
		$counts[] = count( $cards ) . ' WooCommerce gift cards';
	}

	/*
	 * PW: the e-gift card in $25 / $50 / $75 / $100.
	 * key => buyer (null: issued by hand), recipient, amount, days ago, spent [ amount, days ago ], expires in days, active, note
	 */
	if ( $has_pwgc ) {
		$pw = $product_base( new WC_Product_PW_Gift_Card(), 'E-Gift Card', 'e-gift-card', 'LF-GC-003', 'Gift Cards', 'delphinium-flowers', 'Sent by email the moment you order, with your message. Spend it online or at the shop.', 160 );
		$pw->set_tax_status( 'none' );
		$pw_id = $pw->save();
		$denominations = array();
		foreach ( array( '25', '50', '75', '100' ) as $amount ) {
			$variation = $pw->add_amount( $amount );
			if ( is_numeric( $variation ) ) {
				$denominations[ $amount ] = (int) $variation;
			} else {
				$demo->log( 'PW amount ' . $amount . ': ' . $variation );
			}
		}
		$demo->remember( 'commerce_products', 'e-gift-card', $pw_id );

		$cards = array(
			'lucas-sofia'  => array( 'lucas', 'sofia', 50, 36, array( array( 32, 30, 'Online order: Wild Columbine and a print' ) ), null, true, '' ),
			'mia-marcus'   => array( 'mia', 'marcus', 25, 22, array( array( 25, 15, 'In store: two fern starts' ) ), null, true, '' ),
			'isla-chloe'   => array( 'isla', 'chloe', 75, 12, array(), null, true, '' ),
			'kenji-nadia'  => array( 'kenji', 'nadia', 50, 3, array(), null, true, '' ),
			'credit-grace' => array( null, null, 20, 8, array( array( 8, 2, 'In store: seed packets' ) ), null, true, 'Store credit: the replacement lupine arrived late, sorry Grace.' ),
			'spring-fair'  => array( null, null, 10, 52, array(), 45, true, 'Spring plant fair giveaway card.' ),
			'duplicate'    => array( null, null, 50, 47, array(), null, false, 'Created twice by mistake while helping a customer at the counter.' ),
		);
		$now_session = function ( $ts ) use ( $wpdb, $demo ) {
			return $wpdb->get_var( $wpdb->prepare( "SELECT CONVERT_TZ( %s, '+00:00', @@session.time_zone )", $demo->gmt( $ts ) ) );
		};
		foreach ( $cards as $key => $g ) {
			list( $buyer, $to, $amount, $days, $spent, $expires, $active, $note_text ) = $g;
			$ts     = $demo->ago( $days, 13, 5 + strlen( $key ) );
			$number = $code( 'pwgc-' . $key );
			$card   = PW_Gift_Card::add_card( $number, $note_text );
			if ( ! $card instanceof PW_Gift_Card ) {
				$demo->log( 'PW card ' . $key . ': ' . $card );
				continue;
			}
			$ref = 'Issued by hand at the counter';
			if ( $buyer ) {
				$from      = $demo->person( $buyer );
				$recipient = $demo->person( $to );
				list( $order ) = $make_order(
					'pwgc-' . $key,
					$buyer,
					$ts,
					array(
						array(
							wc_get_product( $denominations[ (string) $amount ] ),
							1,
							array(),
							array(
								PWGC_AMOUNT_META_KEY             => $amount,
								PWGC_TO_META_KEY                 => $recipient['email'],
								PWGC_FROM_META_KEY               => $from['first'],
								PWGC_MESSAGE_META_KEY            => 'Happy gardening!',
								PWGC_GIFT_CARD_NUMBER_META_KEY   => $number,
								PWGC_GIFT_CARDS_CREATED_META_KEY => 'yes',
							),
						),
					),
					'completed'
				);
				$ref = 'Order #' . $order->get_order_number();
			}
			$card->credit( $amount, $ref );
			$entries = array( $ts + 60, $ts + 90 );
			foreach ( $spent as $s ) {
				$card->debit( -1 * $s[0], $s[2] );
				$entries[] = $demo->ago( $s[1], 15, 30 );
			}
			if ( ! $active ) {
				$card->deactivate( 'Duplicate of another card' );
				$entries[] = $ts + 600;
			}
			$wpdb->update(
				$wpdb->pimwick_gift_card,
				array( 'expiration_date' => $expires ? wp_date( 'Y-m-d', $ts + $expires * DAY_IN_SECONDS ) : null ),
				array( 'pimwick_gift_card_id' => $card->get_id() )
			);
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->pimwick_gift_card} SET create_date = %s WHERE pimwick_gift_card_id = %d", $now_session( $ts + 60 ), $card->get_id() ) );
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT pimwick_gift_card_activity_id FROM {$wpdb->pimwick_gift_card_activity} WHERE pimwick_gift_card_id = %d ORDER BY pimwick_gift_card_activity_id", $card->get_id() ) );
			foreach ( $rows as $i => $row ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->pimwick_gift_card_activity} SET activity_date = %s WHERE pimwick_gift_card_activity_id = %d", $now_session( isset( $entries[ $i ] ) ? $entries[ $i ] : end( $entries ) ), $row ) );
			}
			$demo->remember( 'commerce_pwgc', $key, $card->get_id() );
		}
		$counts[] = count( $demo->ids( 'commerce_pwgc' ) ) . ' PW cards';
	}

	// Moving dates reschedules the plugins' reminders and expiries; the jobs they replaced only clutter the queue.
	$stale = $wpdb->get_col( "SELECT action_id FROM {$wpdb->prefix}actionscheduler_actions WHERE status = 'canceled' AND ( hook LIKE 'wc\\_memberships\\_%' OR hook LIKE 'woocommerce\\_scheduled\\_subscription\\_%' OR hook LIKE 'woocommerce\\_gc\\_%' )" );
	foreach ( array_chunk( array_map( 'intval', $stale ), 200 ) as $chunk ) {
		$in = implode( ',', $chunk );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}actionscheduler_logs WHERE action_id IN ({$in})" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}actionscheduler_actions WHERE action_id IN ({$in})" );
	}

	foreach ( $removed as $q ) {
		add_action( $q[0], $q[1], $q[2], $q[3] );
	}
	remove_filter( 'ywgc_custom_condition_to_create_gift_card', '__return_false' );
	remove_filter( 'wc_memberships_grant_access_from_new_purchase', '__return_false' );
	remove_filter( 'woocommerce_subscriptions_update_users_role', '__return_false' );
	remove_filter( 'pre_wp_mail', '__return_false', 99 );
	$demo->log( implode( ', ', $counts ) . ', ' . count( $demo->ids( 'commerce_orders' ) ) . ' orders' );
};
