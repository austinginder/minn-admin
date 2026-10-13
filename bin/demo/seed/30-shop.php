<?php
/**
 * The shop: WooCommerce settings, product categories, products (simple,
 * variable, virtual, on sale, low and out of stock), coupons, two months of
 * orders in every status, refunds, order notes and product reviews.
 *
 * @package minn-admin
 */

return function ( Minn_Demo_Seed $demo ) {
	if ( ! function_exists( 'wc_create_order' ) ) {
		$demo->log( 'WooCommerce is not active: no shop' );
		return;
	}
	foreach ( $demo->ids( 'orders' ) as $id ) {
		$order = wc_get_order( $id );
		if ( $order ) {
			$order->delete( true );
		}
	}
	foreach ( $demo->ids( 'products' ) as $id ) {
		$product = wc_get_product( $id );
		if ( $product ) {
			foreach ( $product->get_children() as $child ) {
				wp_delete_post( $child, true );
			}
			wp_delete_post( $id, true );
		}
	}
	foreach ( $demo->ids( 'coupons' ) as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( array( 'orders', 'products', 'coupons' ) as $group ) {
		$demo->forget( $group );
	}
	$demo->as_user( 'june' );

	$settings = array(
		'woocommerce_store_address'           => '112 Station Road',
		'woocommerce_store_city'              => 'Portland',
		'woocommerce_default_country'         => 'US:OR',
		'woocommerce_store_postcode'          => '97214',
		'woocommerce_currency'                => 'USD',
		'woocommerce_weight_unit'             => 'lbs',
		'woocommerce_dimension_unit'          => 'in',
		'woocommerce_calc_taxes'              => 'no',
		'woocommerce_enable_reviews'          => 'yes',
		'woocommerce_review_rating_verification_label' => 'yes',
		'woocommerce_manage_stock'            => 'yes',
		'woocommerce_notify_low_stock_amount' => 4,
		'woocommerce_onboarding_profile'      => array( 'completed' => true, 'skipped' => true ),
		'woocommerce_task_list_hidden'        => 'yes',
		'woocommerce_show_marketplace_suggestions' => 'no',
		'woocommerce_allow_tracking'          => 'no',
		'woocommerce_coming_soon'             => 'no',
		'woocommerce_email_from_name'         => 'Larkspur & Fern',
		'woocommerce_email_from_address'      => 'hello@larkspur.example',
	);
	foreach ( $settings as $key => $value ) {
		update_option( $key, $value );
	}
	if ( class_exists( 'WC_Shipping_Zone' ) && ! WC_Shipping_Zones::get_zones() ) {
		$zone = new WC_Shipping_Zone();
		$zone->set_zone_name( 'United States' );
		$zone->add_location( 'US', 'country' );
		$zone->save();
		$flat = $zone->add_shipping_method( 'flat_rate' );
		update_option( 'woocommerce_flat_rate_' . $flat . '_settings', array( 'title' => 'Standard shipping', 'cost' => '8', 'tax_status' => 'none' ) );
		$free = $zone->add_shipping_method( 'free_shipping' );
		update_option( 'woocommerce_free_shipping_' . $free . '_settings', array( 'title' => 'Free shipping', 'requires' => 'min_amount', 'min_amount' => '75' ) );
		$zone->add_shipping_method( 'local_pickup' );
	}

	$cat_ids = array();
	foreach ( array( 'Houseplants', 'Cacti & Succulents', 'Perennials', 'Outdoor Plants', 'Bulbs & Seeds', 'Prints', 'Workshops' ) as $name ) {
		$term             = term_exists( $name, 'product_cat' );
		$term             = $term ? $term : wp_insert_term( $name, 'product_cat' );
		$cat_ids[ $name ] = (int) $term['term_id'];
	}
	$cat_images = array( 'Houseplants' => 'botany-flowers', 'Cacti & Succulents' => 'category-cactus', 'Perennials' => 'dallas-creek-square', 'Outdoor Plants' => 'akaka-falls-state-park-flora', 'Bulbs & Seeds' => 'category-sunflowers', 'Prints' => 'delphinium-flowers', 'Workshops' => 'coral-square' );
	foreach ( $cat_images as $name => $image ) {
		update_term_meta( $cat_ids[ $name ], 'thumbnail_id', $demo->image( $image ) );
	}

	// slug => name, category, price, sale price, stock (null: not tracked), image, tags, short description, sku
	$catalog = array(
		'anthurium'      => array( "Anthurium 'Red Heart'", 'Houseplants', 34, 29, 12, 'category-anthuriums', array( 'tropical', 'blooms-indoors' ), 'Glossy red spathes most of the year. Bright, indirect light.', 'LF-HP-001' ),
		'peace-lily'     => array( 'Peace Lily', 'Houseplants', 28, null, 18, 'botany-flowers', array( 'low-light', 'beginner-friendly' ), 'White blooms, forgiving roots, tells you when it is thirsty.', 'LF-HP-002' ),
		'calla-bulbs'    => array( 'Calla Lily Bulbs, pack of 3', 'Bulbs & Seeds', 16, null, 40, 'botany-flowers-closeup', array( 'bulbs' ), 'Plant in spring for summer flowers in pots or borders.', 'LF-BS-001' ),
		'saguaro'        => array( 'Golden Saguaro, 6 inch', 'Cacti & Succulents', 22, null, 3, 'category-cactus', array( 'beginner-friendly', 'pet-safe' ), 'A slow, tidy column for your sunniest window.', 'LF-CS-001' ),
		'sunflower-seed' => array( "Sunflower 'Teddy Bear' Seeds", 'Bulbs & Seeds', 5, null, 120, 'category-sunflowers', array( 'seeds', 'kids' ), 'Fluffy double flowers on a compact plant. About 30 seeds.', 'LF-BS-002' ),
		'hibiscus'       => array( 'Red Hibiscus', 'Outdoor Plants', 38, null, 0, 'red-hibiscus-closeup', array( 'tropical' ), 'Big red flowers all summer; bring it indoors before frost.', 'LF-OP-001' ),
		'columbine'      => array( 'Wild Columbine', 'Perennials', 14, null, 25, 'dallas-creek-square', array( 'native', 'pollinator-friendly' ), 'Native to the Northwest; hummingbirds love it.', 'LF-PR-001' ),
		'lupine'         => array( "Lupine 'Gallery Blue'", 'Perennials', 15, 12, 30, 'malibu-plantlife', array( 'pollinator-friendly' ), 'Compact spires of purple-blue in early summer.', 'LF-PR-002' ),
		'impatiens'      => array( 'Pink Impatiens, flat of 6', 'Outdoor Plants', 18, null, 22, 'akaka-falls-state-park-flora', array( 'shade' ), 'Color for the shady side of the house, May to frost.', 'LF-OP-002' ),
		'poppy-seed'     => array( 'Iceland Poppy Seeds', 'Bulbs & Seeds', 5, null, 90, 'northern-buttercups-flowers', array( 'seeds' ), 'Papery yellow, orange and white flowers. About 200 seeds.', 'LF-BS-003' ),
		'meadow-mix'     => array( 'Wildflower Meadow Seed Mix', 'Bulbs & Seeds', 12, null, 75, 'flower-meadow-square', array( 'seeds', 'native', 'pollinator-friendly' ), 'Fourteen native annuals and perennials. Covers 100 square feet.', 'LF-BS-004' ),
		'print-thistle'  => array( 'Botanical Print: Thistle', 'Prints', 45, null, 0, 'star-thristle-flower', array( 'prints' ), 'Archival black and white print on cotton rag paper, 11 x 14.', 'LF-PT-003' ),
		'print-phacelia' => array( 'Botanical Print: Phacelia', 'Prints', 45, null, 6, 'grid-flower-2', array( 'prints' ), 'Archival black and white print on cotton rag paper, 11 x 14.', 'LF-PT-004' ),
		'workshop'       => array( 'Spring Terrarium Workshop', 'Workshops', 55, null, 12, 'coral-square', array( 'workshop' ), 'Saturday morning, two hours, all materials included.', 'LF-WS-001' ),
		'workshop-kids'  => array( 'Kids Sunflower Morning', 'Workshops', 20, null, 0, 'category-sunflowers', array( 'workshop', 'kids' ), 'Ages five and up, with a grown-up. Sow, label and take home a pot.', 'LF-WS-002' ),
	);
	$p = 0;
	foreach ( $catalog as $slug => $row ) {
		list( $name, $cat, $price, $sale, $stock, $image, $tags, $short, $sku ) = $row;
		$product = new WC_Product_Simple();
		$product->set_name( $name );
		$product->set_slug( $slug );
		$product->set_sku( $sku );
		$product->set_regular_price( (string) $price );
		if ( $sale ) {
			$product->set_sale_price( (string) $sale );
		}
		$product->set_short_description( $short );
		$product->set_description( '<p>' . esc_html( $short ) . '</p><p>Every plant is grown or chosen by us, packed by hand and shipped Monday to Wednesday.</p>' );
		$product->set_category_ids( array( $cat_ids[ $cat ] ) );
		$product->set_image_id( $demo->image( $image ) );
		$product->set_manage_stock( null !== $stock );
		if ( null !== $stock ) {
			$product->set_stock_quantity( $stock );
		}
		if ( 'print-thistle' === $slug ) {
			$product->set_backorders( 'notify' );
		}
		if ( 'Workshops' === $cat ) {
			$product->set_virtual( true );
		} else {
			$product->set_weight( 'Prints' === $cat ? '0.5' : '2' );
		}
		if ( in_array( $slug, array( 'anthurium', 'meadow-mix' ), true ) ) {
			$product->set_featured( true );
		}
		$product->set_date_created( $demo->ago( 300 - $p * 15, 10, $p * 4 ) );
		$id = $product->save();
		wp_set_object_terms( $id, $tags, 'product_tag' );
		$demo->remember( 'products', $slug, $id );
		++$p;
	}

	// One variable product: the bellflower print in three sizes.
	$attr = new WC_Product_Attribute();
	$attr->set_name( 'Size' );
	$attr->set_options( array( '8 x 10', '11 x 14', '16 x 20' ) );
	$attr->set_visible( true );
	$attr->set_variation( true );
	$print = new WC_Product_Variable();
	$print->set_name( 'Botanical Print: Bellflower' );
	$print->set_slug( 'print-bellflower' );
	$print->set_sku( 'LF-PT-001' );
	$print->set_short_description( 'Archival black and white print on cotton rag paper, in three sizes.' );
	$print->set_category_ids( array( $cat_ids['Prints'] ) );
	$print->set_image_id( $demo->image( 'campanula-alliariifolia-flower' ) );
	$print->set_gallery_image_ids( array( $demo->image( 'delphinium-flowers' ), $demo->image( 'star-thristle-flower' ) ) );
	$print->set_attributes( array( $attr ) );
	$print->set_date_created( $demo->ago( 64, 9 ) );
	$print_id = $print->save();
	foreach ( array( '8 x 10' => array( 45, 9 ), '11 x 14' => array( 65, 5 ), '16 x 20' => array( 90, 2 ) ) as $size => $v ) {
		$var = new WC_Product_Variation();
		$var->set_parent_id( $print_id );
		$var->set_attributes( array( 'size' => $size ) );
		$var->set_regular_price( (string) $v[0] );
		$var->set_manage_stock( true );
		$var->set_stock_quantity( $v[1] );
		$var->set_sku( 'LF-PT-001-' . str_replace( ' x ', 'X', $size ) );
		$var->save();
	}
	WC_Product_Variable::sync( $print_id );
	$demo->remember( 'products', 'print-bellflower', $print_id );
	wp_set_object_terms( $print_id, array( 'prints' ), 'product_tag' );

	// A draft product, so the products view has one to filter.
	$draft = new WC_Product_Simple();
	$draft->set_name( 'Kokedama Kit' );
	$draft->set_slug( 'kokedama-kit' );
	$draft->set_status( 'draft' );
	$draft->set_regular_price( '32' );
	$draft->set_category_ids( array( $cat_ids['Houseplants'] ) );
	$draft->set_short_description( 'Moss, string and a fern: everything for a hanging moss ball.' );
	$demo->remember( 'products', 'kokedama-kit', $draft->save() );

	// code => type, amount, description, expires in days (null: never), usage limit
	$coupons = array(
		'SPRING15'  => array( 'percent', '15', 'Spring sale, all plants', 30, null ),
		'WELCOME10' => array( 'fixed_cart', '10', 'First order from the newsletter sign-up', null, 1 ),
		'CLUBMEMBER' => array( 'percent', '10', 'Plant Club members, every order', null, null ),
		'FREESHIP'  => array( 'fixed_cart', '0', 'Free shipping weekend', -3, null ),
	);
	foreach ( $coupons as $code => $c ) {
		$coupon = new WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( $c[0] );
		$coupon->set_amount( $c[1] );
		$coupon->set_description( $c[2] );
		if ( null !== $c[3] ) {
			$coupon->set_date_expires( $demo->ago( -$c[3], 23, 59 ) );
		}
		if ( $c[4] ) {
			$coupon->set_usage_limit_per_user( $c[4] );
		}
		if ( 'FREESHIP' === $code ) {
			$coupon->set_free_shipping( true );
		}
		$demo->remember( 'coupons', $code, $coupon->save() );
	}

	// Orders, newest first: who (a customer, or a guest from the cast), days
	// ago, status, [ product slug => quantity ], shipping (flat, free, pickup),
	// payment, coupon, customer note.
	$orders = array(
		array( 'olivia', 0.1, 'processing', array( 'anthurium' => 1, 'peace-lily' => 1 ), 'flat', 'card', '', 'Please leave it at the side door if no one answers.' ),
		array( 'kenji', 0.3, 'pending', array( 'workshop' => 2 ), 'none', 'card', '', '' ),
		array( 'marcus', 0.6, 'processing', array( 'meadow-mix' => 3, 'poppy-seed' => 2 ), 'flat', 'paypal', '', '' ),
		array( 'zoe', 1.2, 'processing', array( 'print-bellflower:16 x 20' => 1, 'print-phacelia' => 1 ), 'free', 'card', 'SPRING15', 'It is a gift: no prices on the packing slip, please.' ),
		array( 'aisha', 1.9, 'on-hold', array( 'saguaro' => 2 ), 'flat', 'bacs', '', '' ),
		array( 'liam', 2.4, 'processing', array( 'lupine' => 2, 'columbine' => 3 ), 'flat', 'card', '', '' ),
		array( 'samuel', 3.1, 'on-hold', array( 'peace-lily' => 6, 'anthurium' => 4 ), 'pickup', 'bacs', '', 'For the shop on Alberta Street. Invoice to Ortiz Florals.' ),
		array( 'sofia', 4.0, 'completed', array( 'workshop' => 1, 'calla-bulbs' => 1 ), 'flat', 'card', '', '' ),
		array( 'daniel', 5.2, 'completed', array( 'sunflower-seed' => 4 ), 'flat', 'paypal', '', '' ),
		array( 'grace', 6.8, 'refunded', array( 'impatiens' => 1 ), 'flat', 'card', '', '' ),
		array( 'noah', 8.3, 'completed', array( 'print-bellflower:11 x 14' => 1 ), 'flat', 'card', 'SPRING15', '' ),
		array( 'emma', 9.9, 'completed', array( 'anthurium' => 1, 'saguaro' => 1, 'meadow-mix' => 1 ), 'free', 'card', '', '' ),
		array( 'ethan', 11.5, 'cancelled', array( 'hibiscus' => 1 ), 'flat', 'paypal', '', '' ),
		array( 'chloe', 13.0, 'completed', array( 'columbine' => 4, 'lupine' => 4, 'meadow-mix' => 2 ), 'free', 'card', '', 'Planting a pollinator bed for our school.' ),
		array( 'lucas', 15.4, 'completed', array( 'poppy-seed' => 2, 'sunflower-seed' => 2 ), 'flat', 'card', 'WELCOME10', '' ),
		array( 'mia', 17.2, 'completed', array( 'peace-lily' => 1 ), 'pickup', 'cod', '', '' ),
		array( 'olivia', 19.8, 'completed', array( 'print-thistle' => 1 ), 'flat', 'card', '', '' ),
		array( 'ben', 22.1, 'completed', array( 'workshop-kids' => 2, 'sunflower-seed' => 1 ), 'none', 'card', '', '' ),
		array( 'isla', 24.6, 'failed', array( 'anthurium' => 2 ), 'flat', 'card', '', '' ),
		array( 'isla', 24.5, 'completed', array( 'anthurium' => 2 ), 'flat', 'paypal', '', '' ),
		array( 'marcus', 27.3, 'completed', array( 'calla-bulbs' => 2, 'impatiens' => 1 ), 'flat', 'paypal', '', '' ),
		array( 'nadia', 30.0, 'completed', array( 'print-bellflower:8 x 10' => 2 ), 'flat', 'card', '', '' ),
		array( 'aisha', 33.6, 'completed', array( 'peace-lily' => 1, 'calla-bulbs' => 1 ), 'flat', 'card', '', '' ),
		array( 'zoe', 37.2, 'completed', array( 'meadow-mix' => 1 ), 'flat', 'card', '', '' ),
		array( 'liam', 41.0, 'completed', array( 'saguaro' => 1, 'print-phacelia' => 1 ), 'free', 'card', '', '' ),
		array( 'grace', 44.8, 'completed', array( 'anthurium' => 1, 'columbine' => 2 ), 'free', 'card', '', '' ),
		array( 'sofia', 49.5, 'completed', array( 'lupine' => 3 ), 'flat', 'paypal', '', '' ),
		array( 'daniel', 53.3, 'completed', array( 'print-bellflower:16 x 20' => 1 ), 'flat', 'card', '', '' ),
		array( 'emma', 58.1, 'completed', array( 'impatiens' => 2, 'poppy-seed' => 1 ), 'flat', 'card', '', '' ),
		array( 'chloe', 63.7, 'completed', array( 'peace-lily' => 2 ), 'flat', 'card', '', '' ),
	);
	$payments = array(
		'card'   => array( 'stripe', 'Credit card' ),
		'paypal' => array( 'ppcp-gateway', 'PayPal' ),
		'bacs'   => array( 'bacs', 'Direct bank transfer' ),
		'cod'    => array( 'cod', 'Pay at pickup' ),
	);
	$variations = array();
	foreach ( wc_get_product( $print_id )->get_children() as $child ) {
		$variations[ wc_get_product( $child )->get_attribute( 'size' ) ] = $child;
	}
	$number = 1040;
	foreach ( array_reverse( $orders ) as $o ) {
		list( $who, $days, $status, $items, $ship, $pay, $code, $note ) = $o;
		$person = $demo->person( $who );
		$ts     = $demo->ago( $days, 8 + ( $number % 12 ), ( $number * 7 ) % 60 );
		$order  = wc_create_order( array( 'customer_id' => $person['id'] ? $person['id'] : 0 ) );
		foreach ( $items as $key => $qty ) {
			$parts   = explode( ':', $key );
			$product = isset( $parts[1] ) ? wc_get_product( $variations[ $parts[1] ] ) : wc_get_product( $demo->id( 'products', $parts[0] ) );
			$order->add_product( $product, $qty );
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
		if ( 'none' !== $ship ) {
			$order->set_address( $address, 'shipping' );
			$rate = new WC_Order_Item_Shipping();
			$rate->set_method_title( array( 'flat' => 'Standard shipping', 'free' => 'Free shipping', 'pickup' => 'Local pickup' )[ $ship ] );
			$rate->set_method_id( array( 'flat' => 'flat_rate', 'free' => 'free_shipping', 'pickup' => 'local_pickup' )[ $ship ] );
			$rate->set_total( 'flat' === $ship ? '8.00' : '0.00' );
			$order->add_item( $rate );
		}
		$order->set_payment_method( $payments[ $pay ][0] );
		$order->set_payment_method_title( $payments[ $pay ][1] );
		$order->set_customer_note( $note );
		$order->set_created_via( 'checkout' );
		$order->set_customer_ip_address( '192.0.2.' . ( $number % 200 ) );
		$order->set_customer_user_agent( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15' );
		if ( $code ) {
			$order->apply_coupon( $code );
		}
		$order->calculate_totals( false );
		$order->set_date_created( $ts );
		if ( in_array( $status, array( 'processing', 'completed', 'refunded' ), true ) ) {
			$order->set_date_paid( $ts + 60 );
			$order->set_transaction_id( strtoupper( substr( md5( 'demo' . $number ), 0, 14 ) ) );
		}
		if ( 'completed' === $status || 'refunded' === $status ) {
			$order->set_date_completed( $ts + DAY_IN_SECONDS + 3 * HOUR_IN_SECONDS );
		}
		$order->set_status( $status );
		$order->save();
		if ( 'refunded' === $status ) {
			wc_create_refund(
				array(
					'order_id' => $order->get_id(),
					'amount'   => $order->get_total(),
					'reason'   => 'Arrived with a broken stem. Customer kept the plant.',
				)
			);
		}
		if ( 'grace' === $who && 44.8 === $days ) {
			wc_create_refund(
				array(
					'order_id' => $order->get_id(),
					'amount'   => 14,
					'reason'   => 'One columbine was missing from the box.',
				)
			);
		}
		$notes = array(
			'processing' => 'Payment received. Packing on the next shipping day.',
			'on-hold'    => 'Awaiting bank transfer.',
			'completed'  => 'Shipped with USPS Priority Mail.',
			'cancelled'  => 'Cancelled at the customer\'s request: the hibiscus is out of stock.',
			'failed'     => 'Card declined. The customer tried again with PayPal.',
		);
		if ( isset( $notes[ $status ] ) ) {
			$note_id = $order->add_order_note( $notes[ $status ], 0, false );
			wp_update_comment( array( 'comment_ID' => $note_id, 'comment_date' => $demo->local( $ts + 2 * HOUR_IN_SECONDS ), 'comment_date_gmt' => $demo->gmt( $ts + 2 * HOUR_IN_SECONDS ) ) );
		}
		if ( 'samuel' === $who ) {
			$order->add_order_note( 'Thanks Samuel! We will have these ready for pickup on Thursday.', 1, true );
		}
		$demo->remember( 'orders', (string) $number, $order->get_id() );
		++$number;
	}

	// Reviews: product => [ who, rating, days ago, text ]
	$reviews = array(
		'anthurium'      => array( array( 'emma', 5, 8, 'Arrived in perfect shape with two blooms and a third on the way.' ), array( 'grace', 4, 40, 'Beautiful plant. Took a couple of weeks to settle in after shipping.' ) ),
		'meadow-mix'     => array( array( 'chloe', 5, 10, 'The kids at school watched it go from bare soil to a meadow in eight weeks.' ), array( 'zoe', 5, 30, 'So many bees. Exactly what I wanted.' ) ),
		'peace-lily'     => array( array( 'mia', 5, 15, 'Great size for the price and very healthy roots.' ), array( 'aisha', 4, 31, 'Lovely, though one leaf was bent in the box.' ) ),
		'print-bellflower' => array( array( 'noah', 5, 6, 'The paper is gorgeous. Framed it the day it arrived.' ) ),
		'saguaro'        => array( array( 'liam', 3, 38, 'Healthy plant, but smaller than I expected for six inches.' ) ),
		'columbine'      => array( array( 'chloe', 5, 9, 'Already flowering. The hummingbirds found it on day two.' ) ),
	);
	foreach ( $reviews as $slug => $list ) {
		$product_id = $demo->id( 'products', $slug );
		foreach ( $list as $r ) {
			$p  = $demo->person( $r[0] );
			$ts = $demo->ago( $r[2], 19, 30 );
			$id = wp_insert_comment(
				array(
					'comment_post_ID'      => $product_id,
					'comment_author'       => $p['name'],
					'comment_author_email' => $p['email'],
					'comment_content'      => $r[3],
					'comment_type'         => 'review',
					'comment_approved'     => '1',
					'user_id'              => $p['id'],
					'comment_date'         => $demo->local( $ts ),
					'comment_date_gmt'     => $demo->gmt( $ts ),
				)
			);
			update_comment_meta( $id, 'rating', $r[1] );
			update_comment_meta( $id, 'verified', 1 );
		}
		WC_Comments::clear_transients( $product_id );
	}
	$demo->log( count( $demo->ids( 'products' ) ) . ' products, ' . count( $demo->ids( 'orders' ) ) . ' orders, ' . count( $demo->ids( 'coupons' ) ) . ' coupons' );
};
