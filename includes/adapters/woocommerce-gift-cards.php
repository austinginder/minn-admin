<?php
/**
 * Bundled adapter: official WooCommerce Gift Cards (Kestrel).
 *
 * Cards live in {prefix}woocommerce_gc_cards, not a CPT. The plugin ships
 * wc/v3/gift-cards, but that list cannot search or filter, and remaining
 * balance is schema-readonly, so the kernel reads the table (each status
 * as their own predicates say it, in SQL) and each row is their data
 * object; remaining is written through WC_GC_Gift_Card_Data::set_balance.
 * Create rides their REST so issued activity and currency meta stay their job.
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
 * redeemed (account-linked, not spent), then scheduled, then disabled,
 * then active. Scheduled comes before disabled because their order flow
 * stores a card with a future delivery date inactive until it is sent.
 * is_delivered() answers false for "not yet" and the user id that sent it
 * otherwise, which is 0 for their own scheduler, so only false is unsent.
 *
 * @param WC_GC_Gift_Card_Data $card Card.
 * @return string
 */
function minn_admin_wcgc_status( $card ) {
	// minn_admin_wcgc_states() says the same in SQL, for the list's filter.
	if ( method_exists( $card, 'has_expired' ) && $card->has_expired() ) {
		return 'expired';
	}
	if ( method_exists( $card, 'is_redeemed' ) && $card->is_redeemed() ) {
		return 'redeemed';
	}
	if ( (int) $card->get_deliver_date() > 0 && false === $card->is_delivered() ) {
		return 'scheduled';
	}
	if ( ! $card->is_active() ) {
		return 'disabled';
	}
	return 'active';
}

/**
 * Each status as conditions on their table, in minn_admin_wcgc_status()'s
 * order, from the data object's own predicates: has_expired() is a set
 * expiry at or before now, is_redeemed() a redeem date and a redeemer,
 * is_delivered() false only for 'no', is_active() 'on'. (Their query's
 * has_expired argument is expire_date <= now, which a card that never
 * expires, 0, also meets.)
 *
 * @return array Status => conditions.
 */
function minn_admin_wcgc_states() {
	// One clock for every status a request names, so no card falls between two.
	$now  = isset( $_SERVER['REQUEST_TIME'] ) ? (int) $_SERVER['REQUEST_TIME'] : time();
	$live = array( 'expire_date' => array( 'any' => array( 0, array( 'op' => '>', 'value' => $now ) ) ) );
	$kept = $live + array( "#any\0redeemed" => array( array( 'redeem_date' => 0 ), array( 'redeemed_by' => 0 ) ) );
	$sent = $kept + array( "#any\0scheduled" => array( array( 'deliver_date' => 0 ), array( 'delivered' => array( 'not' => array( 'no' ) ) ) ) );
	return array(
		'expired'   => array( 'expire_date' => array( 'op' => '!=', 'value' => 0 ), "expire_date\0to" => array( 'op' => '<=', 'value' => $now ) ),
		'redeemed'  => $live + array( 'redeem_date' => array( 'op' => '!=', 'value' => 0 ), 'redeemed_by' => array( 'op' => '!=', 'value' => 0 ) ),
		'scheduled' => $kept + array( 'deliver_date' => array( 'op' => '>', 'value' => 0 ), 'delivered' => 'no' ),
		'disabled'  => $sent + array( 'is_active' => array( 'not' => array( 'on' ) ) ),
		'active'    => $sent + array( 'is_active' => 'on' ),
	);
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
		'code'      => minn_admin_wcgc_code( $card->get_code() ),
		'amount'    => minn_admin_gift_money( $card->get_initial_balance(), $currency ),
		'balance'   => minn_admin_gift_money( $card->get_balance(), $currency ),
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
 * A gift card code as this user is allowed to see it.
 *
 * WooCommerce Gift Cards masks codes for everyone below administrator by default:
 * wc_gc_mask_codes( 'admin' ) is true unless the user is a site administrator, or
 * is a shop manager AND the admin-only Privacy setting says otherwise. The vendor
 * applies it on the list table, the edit view, the order screen, order notes,
 * refunds and its CSV exporter. Minn stands in for those screens, so it honours
 * the same setting rather than handing a support agent a bearer instrument.
 *
 * @param string $code Raw gift card code.
 * @return string Code, masked when the vendor would mask it.
 */
function minn_admin_wcgc_code( $code ) {
	$code = (string) $code;
	if ( '' === $code ) {
		return $code;
	}
	if ( function_exists( 'wc_gc_mask_codes' ) && function_exists( 'wc_gc_mask_code' ) && wc_gc_mask_codes( 'admin' ) ) {
		return (string) wc_gc_mask_code( $code );
	}
	return $code;
}

/**
 * Is this user allowed to see full gift card codes?
 *
 * @return bool
 */
function minn_admin_wcgc_codes_visible() {
	return ! ( function_exists( 'wc_gc_mask_codes' ) && wc_gc_mask_codes( 'admin' ) );
}

/**
 * The gift card message, hidden where the vendor hides it.
 *
 * A gift card message is written by one customer to another, and their edit
 * screen shows it only to a site administrator: a shop manager sees the
 * privacy notice instead. Their wc_gc_mask_messages() cannot be called for
 * this, because it unmasks whenever ! is_admin() and a REST request is never
 * is_admin() — their own query ability carries a note saying exactly that and
 * keeps a separate administrator check for the same reason. So this asks the
 * question their screen asks.
 *
 * @param string $message Raw message.
 * @return string Message, or the vendor's notice when it would be hidden.
 */
function minn_admin_wcgc_message( $message ) {
	$message = (string) $message;
	if ( '' === $message ) {
		return $message;
	}
	$is_admin = function_exists( 'wc_gc_is_site_admin' )
		? (bool) wc_gc_is_site_admin()
		: in_array( 'administrator', (array) wp_get_current_user()->roles, true );
	if ( $is_admin ) {
		return $message;
	}
	return __( 'This message was hidden to protect the sender and recipient\'s privacy.', 'minn-admin' );
}

/**
 * Whether the viewer may read every card whole, its full code and its
 * message: the raw tables hold both, so this is what the database browser
 * asks beyond the list's gate. Their screens show a message only to a site
 * administrator, and an administrator sees codes unmasked unless their
 * filter masks them; with the plugin gone the administrator role answers,
 * as their own test does.
 *
 * @return bool
 */
function minn_admin_wcgc_sees_whole() {
	$is_admin = function_exists( 'wc_gc_is_site_admin' )
		? (bool) wc_gc_is_site_admin()
		: in_array( 'administrator', (array) wp_get_current_user()->roles, true );
	return $is_admin && ! ( function_exists( 'wc_gc_mask_codes' ) && wc_gc_mask_codes( 'admin' ) );
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

/*
 * Gift Cards writes the full code into order and subscription notes and masks
 * it below administrator through a get_comment filter that only runs on
 * wp-admin screens (its admin class only loads there). Minn's Timelines read
 * those notes over wc/v3, so the answer gets the same mask, by the vendor's
 * own predicate and masker.
 */
add_filter( 'woocommerce_rest_prepare_order_note', function ( $response ) {
	if ( ! $response instanceof WP_REST_Response || ! function_exists( 'wc_gc_mask_codes' ) || ! function_exists( 'wc_gc_mask_code' ) || ! wc_gc_mask_codes( 'admin' ) ) {
		return $response;
	}
	$data = $response->get_data();
	if ( ! isset( $data['note'] ) || ! is_string( $data['note'] ) || false === strpos( $data['note'], 'woocommerce-giftcards-admin-note-code' ) ) {
		return $response;
	}
	$data['note'] = preg_replace_callback(
		'/(<span class="woocommerce-giftcards-admin-note-code">)(.*?)(<\/span>)/',
		function ( $m ) {
			return $m[1] . wc_gc_mask_code( $m[2] ) . $m[3];
		},
		$data['note']
	);
	$response->set_data( $data );
	return $response;
} );

minn_admin_add_surface( 'woocommerce-gift-cards', 'minn_admin_wcgc_active', function () {
	return minn_admin_gift_surface( 'wcgc/gift-cards', array(
		'sub'         => 'WooCommerce',
		'plugin'      => 'woocommerce-gift-cards',
		'cap'         => 'manage_woocommerce',
		'status'      => 'wcgc/status',
		'statuses'    => array(
			'active'    => __( 'Active', 'minn-admin' ),
			'disabled'  => __( 'Disabled', 'minn-admin' ),
			'expired'   => __( 'Expired', 'minn-admin' ),
			'redeemed'  => __( 'Redeemed', 'minn-admin' ),
			'scheduled' => __( 'Scheduled', 'minn-admin' ),
		),
		'search'      => minn_admin_wcgc_codes_visible()
			? __( 'Search gift cards (code, recipient…)', 'minn-admin' )
			: __( 'Search by recipient email', 'minn-admin' ),
		'create'      => minn_admin_gift_create_fields( array( 'recipient' => 'required', 'from' => true, 'message' => true, 'send' => true ) ),
		// The resend refuses a disabled card; do not offer the button either.
		'resend_when' => array( 'key' => 'enabled', 'equals' => true ),
	) );
} );

minn_admin_resource( 'wcgc/gift-cards', function () {
	$states = array();
	foreach ( array( 'active', 'disabled', 'expired', 'redeemed', 'scheduled' ) as $slug ) {
		$states[ $slug ] = function () use ( $slug ) {
			return minn_admin_wcgc_states()[ $slug ];
		};
	}
	return array(
		'active'  => 'minn_admin_wcgc_active',
		// The rows mask codes and messages below an administrator; the raw
		// tables cannot, so the database browser shows them only to
		// someone who sees both whole.
		'gates'   => array( 'view' => 'minn_admin_wcgc_can', 'manage' => 'minn_admin_wcgc_can', 'table' => 'minn_admin_wcgc_sees_whole' ),
		'labels'  => array( 'not_found' => __( 'Gift card not found.', 'minn-admin' ) ),
		'source'  => array(
			'table'   => 'woocommerce_gc_cards',
			'key'     => 'id',
			'columns' => array( 'id', 'is_active', 'remaining' ),
			// Every card's redemptions and its meta.
			'tables'  => array( 'woocommerce_gc_activity', 'woocommerce_gc_cardsmeta' ),
		),
		'list'    => array(
			'filter' => array( 'param' => 'status', 'many' => true, 'values' => $states ),
			'search' => array(
				'param' => 'search',
				'also'  => function ( $term ) {
					if ( minn_admin_wcgc_codes_visible() ) {
						// Their own search: code, sender, recipient, sender email.
						$any = array();
						foreach ( array( 'code', 'sender', 'recipient', 'sender_email' ) as $col ) {
							$any[] = array( $col => array( 'contains' => $term ) );
						}
						return array( '#any' => $any );
					}
					// Masking the response while leaving a code-matching predicate on
					// the request turns the list into a prefix oracle: search 'A',
					// 'AB', 'ABC' and watch which rows survive, and a bearer code
					// falls out in a few hundred requests. A caller who may not see
					// codes gets the exact-match recipient filter instead. That still
					// answers the real support question -- which cards belong to this
					// customer -- without ever matching on a code.
					return is_email( $term ) ? array( 'recipient' => $term ) : array( '#any' => array() );
				},
			),
			'range'  => array( 'column' => 'create_date', 'clock' => 'epoch' ),
			'order'  => array( 'column' => 'create_date', 'dir' => 'DESC' ),
			'row'    => function ( array $row ) {
				$card = minn_admin_wcgc_load( (int) $row['id'] );
				return is_wp_error( $card ) ? array( 'id' => (int) $row['id'] ) : minn_admin_wcgc_row( $card );
			},
			'fields' => minn_admin_gift_fields(),
		),
		'reads'   => array(
			'view' => array(
				'view'   => function ( array $row ) {
					$card = minn_admin_wcgc_load( (int) $row['id'] );
					if ( is_wp_error( $card ) ) {
						return $card;
					}
					$built = minn_admin_wcgc_row( $card );
					// A masked code still identifies the row by its last characters; the
					// copy affordance is dropped with it, since there is nothing to copy.
					$gift = minn_admin_gift_card_rows( minn_admin_wcgc_code( $card->get_code() ), minn_admin_wcgc_codes_visible() ? 'code' : 'text', $built['status'], $built['balance'], $built['amount'] );
					// Scheduled says nothing of whether the card can already be spent:
					// their list adds Active beside it when the store switched it on
					// before its delivery date.
					if ( 'scheduled' === $built['status'] ) {
						array_splice( $gift, 2, 0, array( array( 'label' => __( 'Enabled', 'minn-admin' ), 'value' => $card->is_active() ? __( 'Yes', 'minn-admin' ) : __( 'No', 'minn-admin' ) ) ) );
					}
					if ( (int) $card->get_expire_date() > 0 ) {
						$gift[] = array( 'label' => __( 'Expires', 'minn-admin' ), 'value' => wp_date( get_option( 'date_format' ), (int) $card->get_expire_date() ) );
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
						$recipient[] = array( 'label' => __( 'Message', 'minn-admin' ), 'value' => minn_admin_wcgc_message( $card->get_message() ) );
					}

					$origin = array();
					if ( (int) $card->get_order_id() > 0 ) {
						$origin[] = array( 'label' => __( 'Order', 'minn-admin' ), 'value' => '#' . (int) $card->get_order_id() );
					}
					if ( (int) $card->get_date_created() > 0 ) {
						$origin[] = array( 'label' => __( 'Created', 'minn-admin' ), 'value' => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $card->get_date_created() ) );
					}
					$origin[] = array( 'label' => __( 'Delivery', 'minn-admin' ), 'value' => $card->is_virtual() ? __( 'Digital', 'minn-admin' ) : __( 'Physical', 'minn-admin' ) );
					if ( false !== $card->is_delivered() ) {
						$origin[] = array( 'label' => __( 'Delivered', 'minn-admin' ), 'value' => __( 'Yes', 'minn-admin' ) );
					}

					return array(
						'title'    => minn_admin_wcgc_code( $card->get_code() ),
						'sections' => minn_admin_gift_sections( $gift, $recipient, $origin ),
						'adminUrl' => admin_url( 'admin.php?page=gc_giftcards&section=edit&giftcard=' . (int) $card->get_id() ),
					);
				},
				'fields' => minn_admin_gift_view_fields(),
			),
		),
		'create'  => array(
			'gate' => 'manage',
			'run'  => function ( WP_REST_Request $r ) {
				$amount = minn_admin_gift_amount( $r['amount'], false, 'minn_wcgc_amount' );
				if ( is_wp_error( $amount ) ) {
					return $amount;
				}
				$typed     = trim( (string) $r['recipient'] );
				$recipient = $typed ? sanitize_email( $typed ) : '';
				if ( ! $recipient ) {
					return new WP_Error( 'minn_wcgc_recipient', __( 'Add a recipient email address.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				$send       = 'yes' === (string) $r['send'];
				$typed_code = trim( (string) $r['code'] );
				$code       = '';
				if ( '' !== $typed_code ) {
					$code = strtoupper( sanitize_text_field( $typed_code ) );
					if ( wp_strip_all_tags( $typed_code ) !== $code || ( function_exists( 'wc_gc_is_gift_card_code' ) && ! wc_gc_is_gift_card_code( $code ) ) ) {
						return new WP_Error( 'minn_wcgc_code', __( 'That code is not valid. Use XXXX-XXXX-XXXX-XXXX, or leave the field blank to generate one.', 'minn-admin' ), array( 'status' => 400 ) );
					}
				}

				$user   = wp_get_current_user();
				$sender = sanitize_text_field( (string) $r['sender_name'] );
				if ( '' === $sender ) {
					$sender = $user && $user->display_name ? $user->display_name : __( 'Store', 'minn-admin' );
				}
				$body = array(
					'recipient' => $recipient,
					'sender'    => $sender,
					'balance'   => $amount,
					'message'   => sanitize_textarea_field( (string) $r['message'] ),
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
					return new WP_Error( 'minn_wcgc_create', $res->as_error()->get_error_message(), array( 'status' => $res->get_status() ?: 400 ) );
				}
				$data = $res->get_data();
				$made = minn_admin_wcgc_load( isset( $data['id'] ) ? (int) $data['id'] : 0 );
				if ( is_wp_error( $made ) ) {
					return new WP_Error( 'minn_wcgc_create', __( 'The gift card could not be created.', 'minn-admin' ), array( 'status' => 500 ) );
				}
				// A card that came out holding the wrong amount is not issued,
				// and is never emailed.
				if ( ! minn_admin_gift_same( $made->get_balance(), $amount ) ) {
					return Minn_Admin_Kernel::fail( 'not_applied', __( 'The plugin reported success, but the change is not there.', 'minn-admin' ), 500 );
				}
				if ( $send ) {
					minn_admin_wcgc_send_email( $made );
				}
				return array( 'id' => (int) $made->get_id(), 'code' => minn_admin_wcgc_code( $made->get_code() ), 'amount' => $amount, 'sent' => $send ? $recipient : '' );
			},
			'reply' => function ( $id, $fresh, $ran ) {
				return minn_admin_gift_created( $ran['code'], $ran['sent'], minn_admin_gift_money( $ran['amount'] ) );
			},
		),
		'actions' => array(
			'status'  => array(
				'gate'   => 'manage',
				'args'   => minn_admin_gift_switch_args(),
				'run'    => function ( $id, $row, WP_REST_Request $r ) {
					$card = minn_admin_wcgc_load( $id );
					if ( is_wp_error( $card ) ) {
						return $card;
					}
					$on = (bool) $r['enabled'];
					if ( $on && $card->has_expired() ) {
						return new WP_Error( 'minn_wcgc_expired', __( 'An expired gift card cannot be turned back on.', 'minn-admin' ), array( 'status' => 400 ) );
					}
					$card->set_active( $on ? 'on' : 'off' );
					$card->save();
					return array( 'enabled' => $on, 'code' => minn_admin_wcgc_code( $card->get_code() ) );
				},
				'expect' => function ( $id, $fresh, $ran ) {
					return is_array( $fresh ) && ( 'on' === $fresh['is_active'] ) === $ran['enabled'];
				},
				'reply'  => function ( $id, $fresh, $ran ) {
					return minn_admin_gift_switched( $ran['code'], $ran['enabled'] );
				},
			),
			'balance' => array(
				'gate'   => 'manage',
				'run'    => function ( $id, $row, WP_REST_Request $r ) {
					$card = minn_admin_wcgc_load( $id );
					if ( is_wp_error( $card ) ) {
						return $card;
					}
					// Remaining is schema-readonly on their REST. set_balance writes
					// the remaining column; issued amount is left alone. The new
					// balance is the one read here plus the amount asked for, as
					// their own credit() computes it, never a balance typed
					// against an older read.
					$old    = (float) $card->get_balance();
					$target = minn_admin_gift_balance_target( $r, $old, 'minn_wcgc_amount', minn_admin_gift_money( $old, minn_admin_wcgc_currency( $card ) ) );
					if ( is_wp_error( $target ) ) {
						return $target;
					}
					$balance = $target['balance'];
					$card->set_balance( $balance );
					$card->save();
					// The vendor only moves a balance through credit()/debit(), which
					// each write an Activity row; set_balance() writes none, so a
					// top-up made here would be invisible in the ledger their own edit
					// screen renders. Record the adjustment ourselves, naming the
					// acting user, so the change is auditable. Never let a ledger
					// failure break the save that already landed.
					$delta = round( $balance - $old, minn_admin_gift_decimals() );
					if ( abs( $delta ) > 0 && WC_GC()->db && WC_GC()->db->activity ) {
						try {
							$actor = wp_get_current_user();
							WC_GC()->db->activity->add( array(
								'type'       => $delta > 0 ? 'issued' : 'used',
								'gc_id'      => $card->get_id(),
								'gc_code'    => $card->get_code(),
								'user_id'    => $actor ? $actor->ID : 0,
								'user_email' => $actor ? $actor->user_email : '',
								'amount'     => abs( $delta ),
								'note'       => sprintf(
									/* translators: %1$s: the user who adjusted the balance, %2$s: the new balance. */
									__( 'Balance adjusted to %2$s in Minn Admin by %1$s.', 'minn-admin' ),
									$actor ? $actor->user_login : __( 'a site administrator', 'minn-admin' ),
									minn_admin_gift_money( $balance, minn_admin_wcgc_currency( $card ) )
								),
							) );
						} catch ( \Throwable $e ) {
							// Ledger row is best-effort; the balance change stands.
							unset( $e );
						}
					}
					return array( 'balance' => $balance, 'code' => minn_admin_wcgc_code( $card->get_code() ), 'currency' => minn_admin_wcgc_currency( $card ) );
				},
				'expect' => function ( $id, $fresh, $ran ) {
					return is_array( $fresh ) && minn_admin_gift_same( $fresh['remaining'], $ran['balance'] );
				},
				'reply'  => function ( $id, $fresh, $ran ) {
					return minn_admin_gift_holds( $ran['code'], minn_admin_gift_money( $ran['balance'], $ran['currency'] ) );
				},
			),
			'resend'  => array(
				'gate'   => 'manage',
				'expect' => 'ran',
				'run'    => function ( $id ) {
					$card = minn_admin_wcgc_load( $id );
					if ( is_wp_error( $card ) ) {
						return $card;
					}
					if ( ! $card->get_recipient() ) {
						return minn_admin_gift_no_recipient( 'minn_wcgc_no_recipient' );
					}
					// force_trigger() is not a mailer: before sending it calls set_delivered(),
					// and for a scheduled order-purchased card it rewrites deliver_date and
					// RECOMPUTES expire_date -- to 0, meaning never expires, when the product
					// carries no expiry meta -- then save()s. WooCommerce's own screen guards
					// this action with ! has_expired() && is_active(); without the same guard
					// a button labelled "Resend email" silently revived a written-off card and
					// re-broadcast the code of one the store had disabled.
					if ( $card->has_expired() ) {
						return new WP_Error( 'minn_wcgc_expired', __( 'This gift card has expired, so its code cannot be emailed again.', 'minn-admin' ), array( 'status' => 400 ) );
					}
					if ( ! $card->is_active() ) {
						return new WP_Error( 'minn_wcgc_disabled', __( 'This gift card is disabled, so its code cannot be emailed again.', 'minn-admin' ), array( 'status' => 400 ) );
					}
					minn_admin_wcgc_send_email( $card );
					return (string) $card->get_recipient();
				},
				'reply'  => function ( $id, $fresh, $to ) {
					return minn_admin_gift_sent( $to );
				},
			),
		),
	);
} );

minn_admin_endpoint( 'wcgc/status', array(
	'active' => 'minn_admin_wcgc_active',
	'gate'   => 'minn_admin_wcgc_can',
	'run'    => function () {
		$now  = time();
		$live = array( 'is_active' => 'on', 'expire_date' => array( 'any' => array( 0, array( 'op' => '>', 'value' => $now ) ) ) );
		return minn_admin_gift_status(
			Minn_Admin_Kernel::sum( 'wcgc/gift-cards', 'remaining', $live, minn_admin_gift_decimals() ),
			Minn_Admin_Kernel::count( 'wcgc/gift-cards', $live ),
			Minn_Admin_Kernel::count( 'wcgc/gift-cards', array( 'is_active' => 'on', 'expire_date' => array( 'op' => '>', 'value' => $now ), "expire_date\0to" => array( 'op' => '<=', 'value' => $now + 30 * DAY_IN_SECONDS ) ) ),
			__( 'Open WooCommerce Gift Cards ↗', 'minn-admin' ),
			admin_url( 'admin.php?page=gc_giftcards' )
		);
	},
) );
