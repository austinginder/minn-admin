<?php
/**
 * Bundled adapter: PW WooCommerce Gift Cards (free and Pro).
 *
 * Cards live in {prefix}pimwick_gift_card; the balance is a SUM of
 * {prefix}pimwick_gift_card_activity, never a column. Free and Pro share
 * the same class names and tables. They are mutually exclusive (only one
 * may load). Writes go through PW_Gift_Card (create_card / add_card,
 * credit, adjust_balance, deactivate, reactivate). The admin list template
 * is never used: Pro's search-results-rows.php writes recipient_email as
 * a side effect of painting a row.
 *
 * See docs/pw-gift-cards.md for the source audit.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Shop manager is the honest gate (PWGC_REQUIRES_PRIVILEGE).
 *
 * @return bool
 */
function minn_admin_pwgc_cap() {
	// Their own gate, which pimwick_define() leaves overridable: a site that
	// sets PWGC_REQUIRES_PRIVILEGE in wp-config locks every PW screen to that
	// capability, and Minn has to move with it rather than keep answering the
	// default the site owner just replaced.
	return defined( 'PWGC_REQUIRES_PRIVILEGE' ) ? (string) PWGC_REQUIRES_PRIVILEGE : 'manage_woocommerce';
}

function minn_admin_pwgc_can() {
	return current_user_can( minn_admin_pwgc_cap() );
}

/**
 * Whether either edition of PW Gift Cards is loaded.
 *
 * @return bool
 */
function minn_admin_pwgc_active() {
	return class_exists( 'PW_Gift_Card' ) && class_exists( 'PW_Gift_Cards' ) && defined( 'PWGC_VERSION' );
}

/**
 * Pro adds recipient setters the free edition does not have.
 *
 * @return bool
 */
function minn_admin_pwgc_is_pro() {
	return minn_admin_pwgc_active() && method_exists( 'PW_Gift_Card', 'set_recipient_email' );
}

/**
 * Whether PW keeps one pair of tables for the whole network
 * (PWGC_MULTISITE_SHARED_DATABASE), on the base prefix.
 *
 * @return bool
 */
function minn_admin_pwgc_network() {
	return defined( 'PWGC_MULTISITE_SHARED_DATABASE' ) && true === PWGC_MULTISITE_SHARED_DATABASE;
}

/**
 * A card that can be spent: switched on and not past its expiry date, which
 * PW stores as a site-local DATE.
 *
 * @return array Conditions.
 */
function minn_admin_pwgc_live() {
	return array( 'active' => 1, 'expiration_date' => array( 'any' => array( null, array( 'op' => '>=', 'value' => current_time( 'Y-m-d' ) ) ) ) );
}

/**
 * Load one card or a 404. Constructor is a read.
 *
 * @param int $id Card id.
 * @return PW_Gift_Card|WP_Error
 */
function minn_admin_pwgc_load( $id ) {
	if ( ! method_exists( 'PW_Gift_Card', 'get_by_id' ) ) {
		return new WP_Error( 'minn_pwgc_not_found', __( 'Gift card not found.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	$card = PW_Gift_Card::get_by_id( (int) $id );
	if ( ! $card || ! is_object( $card ) || ! $card->get_id() ) {
		return new WP_Error( 'minn_pwgc_not_found', __( 'Gift card not found.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	return $card;
}

/**
 * Minn status slug for one card.
 *
 * @param PW_Gift_Card $card Card.
 * @return string
 */
function minn_admin_pwgc_status( $card ) {
	if ( ! $card->get_active() ) {
		return 'disabled';
	}
	if ( method_exists( $card, 'has_expired' ) && $card->has_expired() ) {
		return 'expired';
	}
	return 'active';
}

/**
 * Recipient email from the card (Pro) or its originating order item (free).
 *
 * @param PW_Gift_Card $card Card.
 * @return string
 */
function minn_admin_pwgc_recipient( $card ) {
	if ( method_exists( $card, 'get_recipient_email' ) ) {
		$email = trim( (string) $card->get_recipient_email() );
		if ( $email ) {
			return $email;
		}
	}
	$item_id = 0;
	if ( method_exists( $card, 'get_order_item_id' ) ) {
		$item_id = (int) $card->get_order_item_id();
	}
	if ( $item_id <= 0 && method_exists( $card, 'get_original_order_item_id' ) ) {
		$item_id = (int) $card->get_original_order_item_id();
	}
	if ( $item_id > 0 && function_exists( 'wc_get_order_item_meta' ) ) {
		return (string) wc_get_order_item_meta( $item_id, 'pw_gift_card_to', true );
	}
	return '';
}

/**
 * Originating order id, or 0.
 *
 * @param PW_Gift_Card $card Card.
 * @return int
 */
function minn_admin_pwgc_order_id( $card ) {
	$item_id = 0;
	if ( method_exists( $card, 'get_order_item_id' ) ) {
		$item_id = (int) $card->get_order_item_id();
	}
	if ( $item_id <= 0 && method_exists( $card, 'get_original_order_item_id' ) ) {
		$item_id = (int) $card->get_original_order_item_id();
	}
	if ( $item_id <= 0 || $item_id === -9999 ) {
		return 0;
	}
	return function_exists( 'wc_get_order_id_by_order_item_id' ) ? (int) wc_get_order_id_by_order_item_id( $item_id ) : 0;
}

/**
 * Original issued amount: first credit on the ledger.
 *
 * @param PW_Gift_Card $card Card.
 * @return float
 */
function minn_admin_pwgc_original( $card ) {
	if ( ! method_exists( $card, 'get_activity' ) ) {
		return (float) $card->get_balance();
	}
	$activity = $card->get_activity();
	if ( ! is_array( $activity ) ) {
		return (float) $card->get_balance();
	}
	foreach ( $activity as $row ) {
		$amount = is_object( $row ) && isset( $row->amount ) ? (float) $row->amount : 0;
		if ( $amount > 0 ) {
			return $amount;
		}
	}
	return (float) $card->get_balance();
}

/**
 * Send the gift card email. Pro's hook is accepted=7 (expiration last).
 *
 * @param PW_Gift_Card $card      Card.
 * @param string       $recipient Recipient email.
 * @param string       $from      Sender name.
 * @param string       $name      Recipient name.
 * @param string       $message   Message.
 * @param float        $amount    Amount shown on the email.
 * @return void
 */
function minn_admin_pwgc_send_email( $card, $recipient, $from, $name, $message, $amount ) {
	$expiry = method_exists( $card, 'get_expiration_date' ) ? (string) $card->get_expiration_date() : '';
	do_action( 'pw_gift_cards_send_email_manually', $card->get_number(), $recipient, $from, $name, $message, $amount, $expiry );
}

/**
 * One list row. Constructor is a read.
 *
 * @param PW_Gift_Card $card Card.
 * @return array
 */
function minn_admin_pwgc_row( $card ) {
	$order_id = minn_admin_pwgc_order_id( $card );
	$created  = method_exists( $card, 'get_create_date_gmt' ) ? (string) $card->get_create_date_gmt() : (string) $card->get_create_date();
	if ( $created && false === strpos( $created, 'T' ) && false === strpos( $created, 'Z' ) ) {
		$ts = strtotime( $created . ' UTC' );
		$created = $ts ? gmdate( 'Y-m-d\TH:i:s\Z', $ts ) : $created;
	}
	return array(
		'id'        => (int) $card->get_id(),
		'code'      => (string) $card->get_number(),
		'amount'    => minn_admin_gift_money( minn_admin_pwgc_original( $card ) ),
		'balance'   => minn_admin_gift_money( $card->get_balance() ),
		'status'    => minn_admin_pwgc_status( $card ),
		'enabled'   => (bool) $card->get_active(),
		'recipient' => minn_admin_pwgc_recipient( $card ),
		'order'     => $order_id ? '#' . $order_id : '',
		'order_id'  => $order_id,
		'has_order' => $order_id > 0,
		'created'   => $created,
	);
}

minn_admin_add_surface( 'pw-gift-cards', 'minn_admin_pwgc_active', function () {
	$pro = minn_admin_pwgc_is_pro();
	return minn_admin_gift_surface( 'pwgc/gift-cards', array(
		'sub'      => $pro ? 'PW Pro' : 'PW',
		'plugin'   => array( 'pw-woocommerce-gift-cards', 'pw-gift-cards' ),
		'cap'      => minn_admin_pwgc_cap(),
		'status'   => 'pwgc/status',
		'statuses' => array(
			'active'   => __( 'Active', 'minn-admin' ),
			'disabled' => __( 'Disabled', 'minn-admin' ),
			'expired'  => __( 'Expired', 'minn-admin' ),
		),
		'search'   => __( 'Search gift cards (code, recipient…)', 'minn-admin' ),
		// The free edition stores no recipient, sender or message of its own.
		'create'   => minn_admin_gift_create_fields( array( 'recipient' => $pro ? 'optional' : '', 'name' => $pro, 'from' => $pro, 'message' => $pro, 'send' => $pro ) ),
	) );
} );

minn_admin_resource( 'pwgc/gift-cards', function () {
	return array(
		'active'  => 'minn_admin_pwgc_active',
		'gates'   => array( 'view' => 'minn_admin_pwgc_can', 'manage' => 'minn_admin_pwgc_can' ),
		'labels'  => array( 'not_found' => __( 'Gift card not found.', 'minn-admin' ) ),
		'source'  => array(
			'table'   => 'pimwick_gift_card',
			'network' => minn_admin_pwgc_network(),
			'key'     => 'pimwick_gift_card_id',
			'columns' => array( 'pimwick_gift_card_id', 'number', 'active' ),
		),
		'list'    => array(
			'filter' => array(
				'param'  => 'status',
				'many'   => true,
				'values' => array(
					'active'   => 'minn_admin_pwgc_live',
					'disabled' => array( 'active' => 0 ),
					'expired'  => function () {
						return array( 'active' => 1, 'expiration_date' => array( 'op' => '<', 'value' => current_time( 'Y-m-d' ) ) );
					},
				),
			),
			// Pro keeps the recipient on the card; the free edition does not.
			'search' => array( 'param' => 'search', 'columns' => minn_admin_pwgc_is_pro() ? array( 'number', 'recipient_email' ) : array( 'number' ) ),
			// create_date is a TIMESTAMP the database writes, read on the site's clock.
			'range'  => array( 'column' => 'create_date', 'clock' => 'local' ),
			'order'  => array( 'column' => 'create_date', 'dir' => 'DESC' ),
			'row'    => function ( array $row ) {
				$card = minn_admin_pwgc_load( (int) $row['pimwick_gift_card_id'] );
				return is_wp_error( $card ) ? array( 'id' => (int) $row['pimwick_gift_card_id'], 'code' => (string) $row['number'] ) : minn_admin_pwgc_row( $card );
			},
			'fields' => minn_admin_gift_fields(),
		),
		'reads'   => array(
			'view' => array(
				'view'   => function ( array $row ) {
					$card = minn_admin_pwgc_load( (int) $row['pimwick_gift_card_id'] );
					if ( is_wp_error( $card ) ) {
						return $card;
					}
					$built = minn_admin_pwgc_row( $card );
					$gift  = minn_admin_gift_card_rows( $card->get_number(), 'code', $built['status'], $built['balance'], $built['amount'] );
					$exp   = method_exists( $card, 'get_expiration_date' ) ? $card->get_expiration_date() : '';
					if ( $exp ) {
						$gift[] = array( 'label' => __( 'Expires', 'minn-admin' ), 'value' => $exp );
					}

					$recipient = array();
					if ( $built['recipient'] ) {
						$recipient[] = array( 'label' => __( 'Email', 'minn-admin' ), 'value' => $built['recipient'], 'type' => 'email' );
					}
					if ( method_exists( $card, 'get_recipient_name' ) && $card->get_recipient_name() ) {
						$recipient[] = array( 'label' => __( 'Name', 'minn-admin' ), 'value' => $card->get_recipient_name() );
					}
					if ( method_exists( $card, 'get_from' ) && $card->get_from() ) {
						$recipient[] = array( 'label' => __( 'From', 'minn-admin' ), 'value' => $card->get_from() );
					}
					if ( method_exists( $card, 'get_message' ) && $card->get_message() ) {
						$recipient[] = array( 'label' => __( 'Message', 'minn-admin' ), 'value' => $card->get_message() );
					}

					$origin = array();
					if ( $built['order_id'] ) {
						$origin[] = array( 'label' => __( 'Order', 'minn-admin' ), 'value' => '#' . $built['order_id'] );
					}
					$created = method_exists( $card, 'get_create_date_gmt' ) ? $card->get_create_date_gmt() : $card->get_create_date();
					if ( $created ) {
						$ts       = strtotime( $created . ( false === strpos( (string) $created, 'Z' ) ? ' UTC' : '' ) );
						$origin[] = array(
							'label' => __( 'Created', 'minn-admin' ),
							'value' => $ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : $created,
						);
					}

					return array(
						'title'    => $card->get_number(),
						'sections' => minn_admin_gift_sections( $gift, $recipient, $origin ),
						'adminUrl' => function_exists( 'pwgc_admin_url' )
							? pwgc_admin_url( 'balances', array( 'card_number' => $card->get_number() ) )
							: admin_url( 'admin.php?page=wc-pw-gift-cards' ),
					);
				},
				'fields' => minn_admin_gift_view_fields(),
			),
		),
		'create'  => array(
			'gate' => 'manage',
			'run'  => function ( WP_REST_Request $r ) {
				$amount = minn_admin_gift_amount( $r['amount'], false, 'minn_pwgc_amount' );
				if ( is_wp_error( $amount ) ) {
					return $amount;
				}
				$typed     = trim( (string) $r['recipient'] );
				$recipient = $typed ? sanitize_email( $typed ) : '';
				if ( $typed && ! $recipient ) {
					return new WP_Error( 'minn_pwgc_recipient', __( 'That recipient email address is not valid.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				$send = 'yes' === (string) $r['send'];
				if ( $send && ! $recipient ) {
					return new WP_Error( 'minn_pwgc_no_recipient', __( 'Add a recipient email address, or choose not to send the email.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				$typed_code = trim( (string) $r['code'] );
				$code       = '';
				if ( '' !== $typed_code ) {
					$code = sanitize_text_field( $typed_code );
					if ( '' === $code || wp_strip_all_tags( $typed_code ) !== $code || strlen( $code ) > 128 ) {
						return new WP_Error( 'minn_pwgc_code', __( 'That code is not valid. Use plain text, or leave the field blank to generate one.', 'minn-admin' ), array( 'status' => 400 ) );
					}
				}

				$note = __( 'Issued from Minn Admin.', 'minn-admin' );
				$card = '' !== $code ? PW_Gift_Card::add_card( $code, $note ) : PW_Gift_Card::create_card( $note );
				if ( ! $card instanceof PW_Gift_Card ) {
					$msg = is_string( $card ) ? $card : __( 'The gift card could not be created.', 'minn-admin' );
					return new WP_Error( 'minn_pwgc_create', $msg, array( 'status' => is_string( $card ) && false !== stripos( $card, 'already' ) ? 409 : 500 ) );
				}
				$card->credit( $amount, $note );
				// A card that came out holding the wrong amount is not issued,
				// and is never emailed.
				if ( ! minn_admin_gift_same( $card->get_balance( true ), $amount ) ) {
					return Minn_Admin_Kernel::fail( 'not_applied', __( 'The plugin reported success, but the change is not there.', 'minn-admin' ), 500 );
				}

				$name = sanitize_text_field( (string) $r['recipient_name'] );
				$from = sanitize_text_field( (string) $r['sender_name'] );
				$msg  = sanitize_textarea_field( (string) $r['message'] );
				if ( minn_admin_pwgc_is_pro() ) {
					if ( $recipient && method_exists( $card, 'set_recipient_email' ) ) {
						$card->set_recipient_email( $recipient );
					}
					if ( method_exists( $card, 'set_recipient_name' ) ) {
						$card->set_recipient_name( $name );
					}
					if ( method_exists( $card, 'set_from' ) ) {
						$card->set_from( $from );
					}
					if ( method_exists( $card, 'set_message' ) ) {
						$card->set_message( $msg );
					}
				}
				if ( $send ) {
					minn_admin_pwgc_send_email( $card, $recipient, $from, $name, $msg, $amount );
				}
				return array( 'id' => (int) $card->get_id(), 'code' => (string) $card->get_number(), 'amount' => $amount, 'sent' => $send ? $recipient : '' );
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
					$card = minn_admin_pwgc_load( $id );
					if ( is_wp_error( $card ) ) {
						return $card;
					}
					$on = (bool) $r['enabled'];
					if ( $on ) {
						$card->reactivate();
					} else {
						$card->deactivate();
					}
					return array( 'enabled' => $on, 'code' => (string) $card->get_number() );
				},
				'expect' => function ( $id, $fresh, $ran ) {
					return is_array( $fresh ) && (int) $fresh['active'] === ( $ran['enabled'] ? 1 : 0 );
				},
				'reply'  => function ( $id, $fresh, $ran ) {
					return minn_admin_gift_switched( $ran['code'], $ran['enabled'] );
				},
			),
			'balance' => array(
				'gate'   => 'manage',
				'when'   => array( 'active' => array( 1 ) ),
				'code'   => 'minn_pwgc_inactive',
				'refuse' => __( 'Enable this gift card before changing its balance.', 'minn-admin' ),
				'run'    => function ( $id, $row, WP_REST_Request $r ) {
					$balance = minn_admin_gift_amount( $r['balance'], true, 'minn_pwgc_amount' );
					$card    = is_wp_error( $balance ) ? $balance : minn_admin_pwgc_load( $id );
					if ( is_wp_error( $card ) ) {
						return $card;
					}
					$card->adjust_balance( $balance - (float) $card->get_balance(), __( 'Balance set from Minn Admin.', 'minn-admin' ) );
					return array( 'balance' => $balance, 'code' => (string) $card->get_number() );
				},
				'expect' => function ( $id, $fresh, $ran ) {
					$card = minn_admin_pwgc_load( $id );
					return ! is_wp_error( $card ) && minn_admin_gift_same( $card->get_balance( true ), $ran['balance'] );
				},
				'reply'  => function ( $id, $fresh, $ran ) {
					return minn_admin_gift_holds( $ran['code'], minn_admin_gift_money( $ran['balance'] ) );
				},
			),
			'resend'  => array(
				'gate'   => 'manage',
				'expect' => 'ran',
				'run'    => function ( $id ) {
					$card = minn_admin_pwgc_load( $id );
					if ( is_wp_error( $card ) ) {
						return $card;
					}
					$to = minn_admin_pwgc_recipient( $card );
					if ( ! $to ) {
						return minn_admin_gift_no_recipient( 'minn_pwgc_no_recipient' );
					}
					$from = method_exists( $card, 'get_from' ) ? (string) $card->get_from() : '';
					$name = method_exists( $card, 'get_recipient_name' ) ? (string) $card->get_recipient_name() : '';
					$msg  = method_exists( $card, 'get_message' ) ? (string) $card->get_message() : '';
					minn_admin_pwgc_send_email( $card, $to, $from, $name, $msg, $card->get_balance() );
					return $to;
				},
				'reply'  => function ( $id, $fresh, $to ) {
					return minn_admin_gift_sent( $to );
				},
			),
		),
	);
} );

minn_admin_endpoint( 'pwgc/status', array(
	'active' => 'minn_admin_pwgc_active',
	'gate'   => 'minn_admin_pwgc_can',
	'run'    => function () {
		// The balance is the ledger's sum, each entry rounded as the store
		// shows money, over the cards that can still be spent.
		$live = array();
		foreach ( minn_admin_pwgc_live() as $col => $rule ) {
			$live[ 'c.' . $col ] = $rule;
		}
		$ledger = array(
			'table'   => 'pimwick_gift_card_activity',
			'network' => minn_admin_pwgc_network(),
			'joins'   => array( 'c' => array( 'table' => 'pimwick_gift_card', 'network' => minn_admin_pwgc_network(), 'on' => array( 'pimwick_gift_card_id' => 'e.pimwick_gift_card_id' ) ) ),
		);
		return minn_admin_gift_status(
			Minn_Admin_Kernel_SQL::sum( $ledger, 'amount', $live, minn_admin_gift_decimals() ),
			Minn_Admin_Kernel::count( 'pwgc/gift-cards', minn_admin_pwgc_live() ),
			null,
			__( 'Open PW Gift Cards ↗', 'minn-admin' ),
			admin_url( 'admin.php?page=wc-pw-gift-cards' )
		);
	},
) );
