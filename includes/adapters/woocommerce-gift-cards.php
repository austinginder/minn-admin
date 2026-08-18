<?php
/**
 * Bundled adapter: official WooCommerce Gift Cards (Kestrel).
 *
 * Cards live in {prefix}woocommerce_gc_cards, not a CPT. The plugin ships
 * wc/v3/gift-cards, but that list cannot search or filter, and remaining
 * balance is schema-readonly, so Minn reads through WC_GC()->db->giftcards
 * and writes remaining through WC_GC_Gift_Card_Data::set_balance. Create
 * rides their REST so issued activity and currency meta stay their job.
 *
 * See docs/woocommerce-gift-cards.md for the source audit.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shop manager is the honest gate: a gift card is a money instrument.
 *
 * @return bool
 */
function minn_admin_wcgc_can() {
	return current_user_can( 'manage_woocommerce' );
}

/**
 * Whether the official WooCommerce Gift Cards plugin is up.
 *
 * WC_GC() exists as soon as the main file loads; tables and objects only
 * exist after is_plugin_initialized() (WooCommerce 10.6+ and a finished boot).
 *
 * @return bool
 */
function minn_admin_wcgc_active() {
	return function_exists( 'WC_GC' )
		&& defined( 'WC_GC_VERSION' )
		&& class_exists( 'WC_GC_Gift_Card_Data' )
		&& is_object( WC_GC() )
		&& method_exists( WC_GC(), 'is_plugin_initialized' )
		&& WC_GC()->is_plugin_initialized();
}

/**
 * Money as display-ready text (wc_price HTML stripped).
 *
 * @param float|string $value    Amount.
 * @param string       $currency Optional currency code.
 * @return string
 */
function minn_admin_wcgc_money( $value, $currency = '' ) {
	if ( ! function_exists( 'wc_price' ) ) {
		return (string) $value;
	}
	$args = $currency ? array( 'currency' => $currency ) : array();
	return html_entity_decode( wp_strip_all_tags( wc_price( (float) $value, $args ) ), ENT_QUOTES, 'UTF-8' );
}

/**
 * Parse a money amount from a request, or a WP_Error.
 *
 * @param mixed $raw        Request value.
 * @param bool  $allow_zero Whether 0 is a legal amount.
 * @return float|WP_Error
 */
function minn_admin_wcgc_parse_amount( $raw, $allow_zero = false ) {
	if ( '' === $raw || null === $raw || ! is_numeric( $raw ) ) {
		return new WP_Error( 'minn_wcgc_amount', __( 'Enter the amount as a number.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	$amount = (float) $raw;
	if ( ! is_finite( $amount ) ) {
		return new WP_Error( 'minn_wcgc_amount', __( 'Enter the amount as a number.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	$amount = round( $amount, function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2 );
	if ( $amount < 0 || ( ! $allow_zero && $amount <= 0 ) ) {
		return new WP_Error(
			'minn_wcgc_amount',
			$allow_zero
				? __( 'A balance cannot be negative.', 'minn-admin' )
				: __( 'A gift card has to be worth more than nothing.', 'minn-admin' ),
			array( 'status' => 400 )
		);
	}
	if ( $amount > 9999999.99 ) {
		return new WP_Error( 'minn_wcgc_amount', __( 'Enter an amount no larger than 9,999,999.99.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	return $amount;
}

/**
 * Currency stored on the card, or the store default.
 *
 * @param WC_GC_Gift_Card_Data $card Card.
 * @return string
 */
function minn_admin_wcgc_currency( $card ) {
	if ( method_exists( $card, 'get_meta' ) ) {
		$meta = (string) $card->get_meta( '_currency' );
		if ( $meta ) {
			return $meta;
		}
	}
	return function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
}

/**
 * Minn status slug for one card.
 *
 * There is no status column. Expired wins (the money is gone), then
 * redeemed (account-linked, not spent), then disabled, then scheduled,
 * then active.
 *
 * @param WC_GC_Gift_Card_Data $card Card.
 * @return string
 */
function minn_admin_wcgc_status( $card ) {
	if ( method_exists( $card, 'has_expired' ) && $card->has_expired() ) {
		return 'expired';
	}
	if ( method_exists( $card, 'is_redeemed' ) && $card->is_redeemed() ) {
		return 'redeemed';
	}
	if ( ! $card->is_active() ) {
		return 'disabled';
	}
	if ( (int) $card->get_deliver_date() > 0 && ! $card->is_delivered() ) {
		return 'scheduled';
	}
	return 'active';
}

/**
 * One list row from a data object. The constructor is a read.
 *
 * @param WC_GC_Gift_Card_Data $card Card.
 * @return array
 */
function minn_admin_wcgc_row( $card ) {
	$order_id = (int) $card->get_order_id();
	$currency = minn_admin_wcgc_currency( $card );
	$created  = (int) $card->get_date_created();
	return array(
		'id'        => (int) $card->get_id(),
		'code'      => (string) $card->get_code(),
		'amount'    => minn_admin_wcgc_money( $card->get_initial_balance(), $currency ),
		'balance'   => minn_admin_wcgc_money( $card->get_balance(), $currency ),
		'status'    => minn_admin_wcgc_status( $card ),
		'enabled'   => $card->is_active(),
		'recipient' => (string) $card->get_recipient(),
		'order'     => $order_id ? '#' . $order_id : '',
		'order_id'  => $order_id,
		'has_order' => $order_id > 0,
		'created'   => $created ? gmdate( 'Y-m-d\TH:i:s\Z', $created ) : '',
	);
}

/**
 * Load one card or a 404.
 *
 * @param int $id Card id.
 * @return WC_GC_Gift_Card_Data|WP_Error
 */
function minn_admin_wcgc_load( $id ) {
	if ( ! function_exists( 'wc_gc_get_gift_card' ) ) {
		return new WP_Error( 'minn_wcgc_not_found', __( 'Gift card not found.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	$card = wc_gc_get_gift_card( (int) $id );
	if ( ! $card || ! $card->get_id() ) {
		return new WP_Error( 'minn_wcgc_not_found', __( 'Gift card not found.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	return $card;
}

/**
 * Send the gift card email through their force-send hook.
 *
 * @param WC_GC_Gift_Card_Data $card Card.
 * @return void
 */
function minn_admin_wcgc_send_email( $card ) {
	$hook = apply_filters( 'woocommerce_gc_force_send_gift_card_hook', 'woocommerce_gc_force_send_gift_card_to_customer', $card );
	do_action( $hook, $card );
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_wcgc_active() ) {
		return $surfaces;
	}

	$surfaces['woocommerce-gift-cards'] = array(
		'label'      => __( 'Gift cards', 'minn-admin' ),
		'family'     => 'gift-cards',
		'sub'        => 'WooCommerce',
		'icon'       => 'tag',
		'cap'        => 'manage_woocommerce',
		'group'      => 'commerce',
		'status'     => array( 'route' => 'minn-admin/v1/wcgc/status' ),
		'collection' => array(
			'route'     => 'minn-admin/v1/wcgc/gift-cards',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'search'    => 'search={q}',
			'filterBar' => array(
				'searchPlaceholder' => __( 'Search gift cards (code, recipient…)', 'minn-admin' ),
				'statuses'          => array(
					array( 'active', __( 'Active', 'minn-admin' ) ),
					array( 'disabled', __( 'Disabled', 'minn-admin' ) ),
					array( 'expired', __( 'Expired', 'minn-admin' ) ),
					array( 'redeemed', __( 'Redeemed', 'minn-admin' ) ),
					array( 'scheduled', __( 'Scheduled', 'minn-admin' ) ),
				),
				'kinds'             => array( 'status', 'date' ),
			),
			'columns'   => array(
				array( 'key' => 'code', 'label' => __( 'Code', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,1.4fr)' ),
				array( 'key' => 'balance', 'label' => __( 'Balance', 'minn-admin' ), 'format' => 'num', 'width' => '110px' ),
				array( 'key' => 'amount', 'label' => __( 'Amount', 'minn-admin' ), 'format' => 'num', 'width' => '110px' ),
				array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill', 'width' => '120px' ),
				array( 'key' => 'recipient', 'label' => __( 'Recipient', 'minn-admin' ), 'width' => 'minmax(0,1.2fr)' ),
				array( 'key' => 'order', 'label' => __( 'Order', 'minn-admin' ), 'format' => 'mono', 'width' => '90px' ),
				array( 'key' => 'created', 'label' => __( 'Created', 'minn-admin' ), 'format' => 'ago', 'utc' => true ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/wcgc/gift-cards/{id}/view',
			),
			'actions'   => array(
				array(
					'label'   => __( 'Disable', 'minn-admin' ),
					'route'   => 'minn-admin/v1/wcgc/gift-cards/{id}/status',
					'body'    => array( 'enabled' => false ),
					'when'    => array( 'key' => 'enabled', 'equals' => true ),
					'confirm' => __( 'Disable this gift card? Its balance can no longer be spent.', 'minn-admin' ),
					'danger'  => true,
				),
				array(
					'label' => __( 'Enable', 'minn-admin' ),
					'route' => 'minn-admin/v1/wcgc/gift-cards/{id}/status',
					'body'  => array( 'enabled' => true ),
					'when'  => array( 'key' => 'enabled', 'equals' => false ),
				),
				array(
					'label'  => __( 'Adjust balance', 'minn-admin' ),
					'route'  => 'minn-admin/v1/wcgc/gift-cards/{id}/balance',
					'fields' => array(
						array(
							'key'   => 'balance',
							'label' => __( 'New balance', 'minn-admin' ),
							'type'  => 'number',
						),
					),
				),
				array(
					'label' => __( 'Resend email', 'minn-admin' ),
					'route' => 'minn-admin/v1/wcgc/gift-cards/{id}/resend',
				),
				array(
					'label' => __( 'View order', 'minn-admin' ),
					'href'  => home_url( '/minn-admin/orders/{order_id}' ),
					'when'  => array( 'key' => 'has_order', 'equals' => true ),
				),
			),
			'create'    => array(
				'label'  => __( 'Add gift card', 'minn-admin' ),
				'route'  => 'minn-admin/v1/wcgc/gift-cards',
				'fields' => array(
					array(
						'key'   => 'amount',
						'label' => __( 'Amount', 'minn-admin' ),
						'type'  => 'number',
					),
					array(
						'key'         => 'code',
						'label'       => __( 'Code', 'minn-admin' ),
						'mono'        => true,
						'required'    => false,
						'placeholder' => __( 'Leave blank to generate one', 'minn-admin' ),
					),
					array(
						'key'      => 'recipient',
						'label'    => __( 'Recipient email', 'minn-admin' ),
						'type'     => 'email',
						'required' => true,
					),
					array(
						'key'      => 'sender_name',
						'label'    => __( 'From', 'minn-admin' ),
						'required' => false,
					),
					array(
						'key'      => 'message',
						'label'    => __( 'Message', 'minn-admin' ),
						'type'     => 'textarea',
						'rows'     => 3,
						'required' => false,
					),
					array(
						'key'     => 'send',
						'label'   => __( 'Send the gift card email now', 'minn-admin' ),
						'type'    => 'select',
						'value'   => 'no',
						'options' => array(
							array( 'no', __( 'No', 'minn-admin' ) ),
							array( 'yes', __( 'Yes', 'minn-admin' ) ),
						),
					),
				),
			),
		),
	);

	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_wcgc_active() ) {
		return;
	}

	$permission = 'minn_admin_wcgc_can';

	register_rest_route( 'minn-admin/v1', '/wcgc/gift-cards', array(
		'methods'             => 'GET',
		'permission_callback' => $permission,
		'callback'            => function ( $request ) {
			$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
			$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
			$search   = trim( (string) $request->get_param( 'search' ) );

			$wanted = $request->get_param( 'status' );
			$wanted = is_array( $wanted ) ? $wanted : ( ( '' === (string) $wanted ) ? array() : array( $wanted ) );
			$wanted = array_values( array_diff( array_map( 'strval', $wanted ), array( 'any' ) ) );

			$args = array(
				'return'   => 'objects',
				'order_by' => array( 'create_date' => 'DESC', 'id' => 'DESC' ),
			);
			if ( '' !== $search ) {
				$args['search'] = $search;
			}

			foreach ( array( 'after' => 'start_date', 'before' => 'end_date' ) as $param => $key ) {
				$raw = trim( (string) $request->get_param( $param ) );
				if ( '' === $raw ) {
					continue;
				}
				$ts = strtotime( $raw );
				if ( ! $ts ) {
					continue;
				}
				$args[ $key ] = $ts;
			}

			// One status can ride their query. Several statuses (or a mix
			// their args cannot express) are filtered after a broader fetch
			// is sliced in PHP: their query has no OR of computed labels.
			$single = ( 1 === count( $wanted ) ) ? $wanted[0] : '';
			$filter_after = $wanted && ! in_array( $single, array( 'disabled', 'redeemed', 'expired' ), true );
			if ( 'disabled' === $single ) {
				$args['is_active'] = 'off';
			} elseif ( 'redeemed' === $single ) {
				$args['is_redeemed'] = true;
			} elseif ( 'expired' === $single ) {
				$args['has_expired'] = true;
			}

			if ( ! $filter_after ) {
				$args['limit']  = $per_page;
				$args['offset'] = ( $page - 1 ) * $per_page;
				$count_args     = $args;
				$count_args['count']  = true;
				unset( $count_args['return'], $count_args['order_by'], $count_args['limit'], $count_args['offset'] );
				$total = (int) WC_GC()->db->giftcards->query( $count_args );
				$cards = WC_GC()->db->giftcards->query( $args );
				$items = array();
				foreach ( $cards as $card ) {
					$items[] = minn_admin_wcgc_row( $card );
				}
				return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
			}

			// Active and scheduled (and multi-select) need the computed label.
			$scan            = $args;
			$scan['limit']   = -1;
			$scan['offset']  = -1;
			$all             = WC_GC()->db->giftcards->query( $scan );
			$matched         = array();
			$allowed         = $wanted ? array_flip( $wanted ) : array();
			foreach ( $all as $card ) {
				$slug = minn_admin_wcgc_status( $card );
				if ( $allowed && ! isset( $allowed[ $slug ] ) ) {
					continue;
				}
				$matched[] = $card;
			}
			$total = count( $matched );
			$slice = array_slice( $matched, ( $page - 1 ) * $per_page, $per_page );
			$items = array();
			foreach ( $slice as $card ) {
				$items[] = minn_admin_wcgc_row( $card );
			}
			return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wcgc/gift-cards/(?P<id>\d+)/view', array(
		'methods'             => 'GET',
		'permission_callback' => $permission,
		'callback'            => function ( $request ) {
			$card = minn_admin_wcgc_load( (int) $request['id'] );
			if ( is_wp_error( $card ) ) {
				return $card;
			}
			$row      = minn_admin_wcgc_row( $card );
			$currency = minn_admin_wcgc_currency( $card );

			$gift = array(
				array( 'label' => __( 'Code', 'minn-admin' ), 'value' => $card->get_code(), 'type' => 'code' ),
				array( 'label' => __( 'Status', 'minn-admin' ), 'value' => $row['status'], 'type' => 'pill' ),
				array( 'label' => __( 'Balance', 'minn-admin' ), 'value' => minn_admin_wcgc_money( $card->get_balance(), $currency ) ),
				array( 'label' => __( 'Original amount', 'minn-admin' ), 'value' => minn_admin_wcgc_money( $card->get_initial_balance(), $currency ) ),
			);
			if ( (int) $card->get_expire_date() > 0 ) {
				$gift[] = array(
					'label' => __( 'Expires', 'minn-admin' ),
					'value' => wp_date( get_option( 'date_format' ), (int) $card->get_expire_date() ),
				);
			}

			$recipient = array();
			if ( $card->get_recipient() ) {
				$recipient[] = array( 'label' => __( 'Email', 'minn-admin' ), 'value' => $card->get_recipient(), 'type' => 'email' );
			}
			if ( $card->get_sender() ) {
				$recipient[] = array( 'label' => __( 'From', 'minn-admin' ), 'value' => $card->get_sender() );
			}
			if ( $card->get_sender_email() ) {
				$recipient[] = array( 'label' => __( 'Sender email', 'minn-admin' ), 'value' => $card->get_sender_email(), 'type' => 'email' );
			}
			if ( $card->get_message() ) {
				$recipient[] = array( 'label' => __( 'Message', 'minn-admin' ), 'value' => $card->get_message() );
			}

			$origin = array();
			if ( (int) $card->get_order_id() > 0 ) {
				$origin[] = array(
					'label' => __( 'Order', 'minn-admin' ),
					'value' => '#' . (int) $card->get_order_id(),
				);
			}
			if ( (int) $card->get_date_created() > 0 ) {
				$origin[] = array(
					'label' => __( 'Created', 'minn-admin' ),
					'value' => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $card->get_date_created() ),
				);
			}
			$origin[] = array(
				'label' => __( 'Delivery', 'minn-admin' ),
				'value' => $card->is_virtual() ? __( 'Digital', 'minn-admin' ) : __( 'Physical', 'minn-admin' ),
			);
			if ( $card->is_delivered() ) {
				$origin[] = array(
					'label' => __( 'Delivered', 'minn-admin' ),
					'value' => __( 'Yes', 'minn-admin' ),
				);
			}

			$sections = array( array( 'title' => __( 'Gift card', 'minn-admin' ), 'rows' => $gift ) );
			if ( $recipient ) {
				$sections[] = array( 'title' => __( 'Recipient', 'minn-admin' ), 'rows' => $recipient );
			}
			$sections[] = array( 'title' => __( 'Origin', 'minn-admin' ), 'rows' => $origin );

			return rest_ensure_response( array(
				'title'    => $card->get_code(),
				'sections' => $sections,
				'adminUrl' => admin_url( 'admin.php?page=gc_giftcards&section=edit&giftcard=' . (int) $card->get_id() ),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wcgc/gift-cards/(?P<id>\d+)/status', array(
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => function ( $request ) {
			$card = minn_admin_wcgc_load( (int) $request['id'] );
			if ( is_wp_error( $card ) ) {
				return $card;
			}
			$enabled = rest_sanitize_boolean( $request->get_param( 'enabled' ) );
			if ( $enabled && method_exists( $card, 'has_expired' ) && $card->has_expired() ) {
				return new WP_Error(
					'minn_wcgc_expired',
					__( 'An expired gift card cannot be turned back on.', 'minn-admin' ),
					array( 'status' => 400 )
				);
			}
			$card->set_active( $enabled ? 'on' : 'off' );
			$card->save();

			return rest_ensure_response( array(
				'message' => $enabled
					? sprintf(
						/* translators: %s: gift card code. */
						__( '%s is enabled.', 'minn-admin' ),
						$card->get_code()
					)
					: sprintf(
						/* translators: %s: gift card code. */
						__( '%s is disabled and can no longer be spent.', 'minn-admin' ),
						$card->get_code()
					),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wcgc/gift-cards', array(
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => function ( $request ) {
			$amount = minn_admin_wcgc_parse_amount( $request->get_param( 'amount' ) );
			if ( is_wp_error( $amount ) ) {
				return $amount;
			}

			$typed     = trim( (string) $request->get_param( 'recipient' ) );
			$recipient = $typed ? sanitize_email( $typed ) : '';
			if ( ! $recipient ) {
				return new WP_Error( 'minn_wcgc_recipient', __( 'Add a recipient email address.', 'minn-admin' ), array( 'status' => 400 ) );
			}

			$send = 'yes' === (string) $request->get_param( 'send' );

			$typed_code = trim( (string) $request->get_param( 'code' ) );
			$code       = '';
			if ( '' !== $typed_code ) {
				$code = strtoupper( sanitize_text_field( $typed_code ) );
				if ( $code !== wp_strip_all_tags( $typed_code ) || ( function_exists( 'wc_gc_is_gift_card_code' ) && ! wc_gc_is_gift_card_code( $code ) ) ) {
					return new WP_Error( 'minn_wcgc_code', __( 'That code is not valid. Use XXXX-XXXX-XXXX-XXXX, or leave the field blank to generate one.', 'minn-admin' ), array( 'status' => 400 ) );
				}
			}

			$user   = wp_get_current_user();
			$sender = sanitize_text_field( (string) $request->get_param( 'sender_name' ) );
			if ( '' === $sender ) {
				$sender = $user && $user->display_name ? $user->display_name : __( 'Store', 'minn-admin' );
			}

			$body = array(
				'recipient' => $recipient,
				'sender'    => $sender,
				'balance'   => $amount,
				'message'   => sanitize_textarea_field( (string) $request->get_param( 'message' ) ),
			);
			if ( $user && $user->user_email ) {
				$body['sender_email'] = $user->user_email;
			}
			if ( '' !== $code ) {
				$body['code'] = $code;
			}

			$req = new WP_REST_Request( 'POST', '/wc/v3/gift-cards' );
			$req->set_header( 'content-type', 'application/json' );
			$req->set_body( wp_json_encode( $body ) );
			$res = rest_do_request( $req );
			if ( $res->is_error() ) {
				$err = $res->as_error();
				return new WP_Error( 'minn_wcgc_create', $err->get_error_message(), array( 'status' => $res->get_status() ?: 400 ) );
			}
			$data = $res->get_data();
			$id   = isset( $data['id'] ) ? (int) $data['id'] : 0;
			if ( ! $id ) {
				return new WP_Error( 'minn_wcgc_create', __( 'The gift card could not be created.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			$made = minn_admin_wcgc_load( $id );
			if ( is_wp_error( $made ) ) {
				return $made;
			}

			if ( $send ) {
				minn_admin_wcgc_send_email( $made );
			}

			return rest_ensure_response( array(
				'id'      => $id,
				'message' => $send
					? sprintf(
						/* translators: 1: gift card code; 2: recipient email address. */
						__( '%1$s created and sent to %2$s.', 'minn-admin' ),
						$made->get_code(),
						$recipient
					)
					: sprintf(
						/* translators: 1: gift card code; 2: formatted money amount. */
						__( '%1$s created, holding %2$s.', 'minn-admin' ),
						$made->get_code(),
						minn_admin_wcgc_money( $amount )
					),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wcgc/gift-cards/(?P<id>\d+)/balance', array(
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => function ( $request ) {
			$card = minn_admin_wcgc_load( (int) $request['id'] );
			if ( is_wp_error( $card ) ) {
				return $card;
			}
			$balance = minn_admin_wcgc_parse_amount( $request->get_param( 'balance' ), true );
			if ( is_wp_error( $balance ) ) {
				return $balance;
			}
			// Remaining is schema-readonly on their REST. set_balance writes
			// the remaining column; issued amount is left alone.
			$card->set_balance( $balance );
			$card->save();

			return rest_ensure_response( array(
				'message' => sprintf(
					/* translators: 1: gift card code; 2: formatted money amount. */
					__( '%1$s now holds %2$s.', 'minn-admin' ),
					$card->get_code(),
					minn_admin_wcgc_money( $balance, minn_admin_wcgc_currency( $card ) )
				),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wcgc/gift-cards/(?P<id>\d+)/resend', array(
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => function ( $request ) {
			$card = minn_admin_wcgc_load( (int) $request['id'] );
			if ( is_wp_error( $card ) ) {
				return $card;
			}
			if ( ! $card->get_recipient() ) {
				return new WP_Error(
					'minn_wcgc_no_recipient',
					__( 'This gift card has no recipient address, so there is nothing to resend.', 'minn-admin' ),
					array( 'status' => 400 )
				);
			}
			minn_admin_wcgc_send_email( $card );
			return rest_ensure_response( array(
				'message' => sprintf(
					/* translators: %s: recipient email address. */
					__( 'Gift card sent to %s.', 'minn-admin' ),
					$card->get_recipient()
				),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wcgc/status', array(
		'methods'             => 'GET',
		'permission_callback' => $permission,
		'callback'            => function () {
			global $wpdb;
			$table = $wpdb->prefix . 'woocommerce_gc_cards';
			$now   = time();

			$active = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE is_active = %s AND ( expire_date = 0 OR expire_date > %d )",
				'on',
				$now
			) );
			$outstanding = (float) $wpdb->get_var( $wpdb->prepare(
				"SELECT SUM( remaining ) FROM {$table} WHERE is_active = %s AND ( expire_date = 0 OR expire_date > %d )",
				'on',
				$now
			) );
			$expiring = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE is_active = %s AND expire_date > %d AND expire_date <= %d",
				'on',
				$now,
				$now + 30 * DAY_IN_SECONDS
			) );

			$rows = array(
				array(
					'label' => __( 'Outstanding balance', 'minn-admin' ),
					'value' => minn_admin_wcgc_money( $outstanding ),
					'hint'  => sprintf(
						/* translators: %s: number of active gift cards. */
						_n( 'across %s active gift card', 'across %s active gift cards', $active, 'minn-admin' ),
						number_format_i18n( $active )
					),
				),
			);
			if ( $expiring ) {
				$rows[] = array(
					'label' => __( 'Expiring soon', 'minn-admin' ),
					'value' => number_format_i18n( $expiring ),
					'hint'  => __( 'within 30 days', 'minn-admin' ),
				);
			}

			return rest_ensure_response( array(
				'rows'    => $rows,
				'actions' => array(
					array(
						'label' => __( 'Open WooCommerce Gift Cards ↗', 'minn-admin' ),
						'href'  => admin_url( 'admin.php?page=gc_giftcards' ),
					),
				),
			) );
		},
	) );
} );
