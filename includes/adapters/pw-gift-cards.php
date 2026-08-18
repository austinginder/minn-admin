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
function minn_admin_pwgc_can() {
	return current_user_can( 'manage_woocommerce' );
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
 * Card table name, once PW has bound it.
 *
 * @return string
 */
function minn_admin_pwgc_table() {
	global $wpdb;
	return ! empty( $wpdb->pimwick_gift_card ) ? $wpdb->pimwick_gift_card : $wpdb->prefix . 'pimwick_gift_card';
}

/**
 * Activity table name.
 *
 * @return string
 */
function minn_admin_pwgc_activity_table() {
	global $wpdb;
	return ! empty( $wpdb->pimwick_gift_card_activity ) ? $wpdb->pimwick_gift_card_activity : $wpdb->prefix . 'pimwick_gift_card_activity';
}

/**
 * Money as display-ready text.
 *
 * @param float|string $value Amount.
 * @return string
 */
function minn_admin_pwgc_money( $value ) {
	if ( ! function_exists( 'wc_price' ) ) {
		return (string) $value;
	}
	return html_entity_decode( wp_strip_all_tags( wc_price( (float) $value ) ), ENT_QUOTES, 'UTF-8' );
}

/**
 * Parse a money amount from a request, or a WP_Error.
 *
 * @param mixed $raw        Request value.
 * @param bool  $allow_zero Whether 0 is a legal amount.
 * @return float|WP_Error
 */
function minn_admin_pwgc_parse_amount( $raw, $allow_zero = false ) {
	if ( '' === $raw || null === $raw || ! is_numeric( $raw ) ) {
		return new WP_Error( 'minn_pwgc_amount', __( 'Enter the amount as a number.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	$amount = (float) $raw;
	if ( ! is_finite( $amount ) ) {
		return new WP_Error( 'minn_pwgc_amount', __( 'Enter the amount as a number.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	$amount = round( $amount, function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2 );
	if ( $amount < 0 || ( ! $allow_zero && $amount <= 0 ) ) {
		return new WP_Error(
			'minn_pwgc_amount',
			$allow_zero
				? __( 'A balance cannot be negative.', 'minn-admin' )
				: __( 'A gift card has to be worth more than nothing.', 'minn-admin' ),
			array( 'status' => 400 )
		);
	}
	if ( $amount > 9999999.99 ) {
		return new WP_Error( 'minn_pwgc_amount', __( 'Enter an amount no larger than 9,999,999.99.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	return $amount;
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
		'amount'    => minn_admin_pwgc_money( minn_admin_pwgc_original( $card ) ),
		'balance'   => minn_admin_pwgc_money( $card->get_balance() ),
		'status'    => minn_admin_pwgc_status( $card ),
		'enabled'   => (bool) $card->get_active(),
		'recipient' => minn_admin_pwgc_recipient( $card ),
		'order'     => $order_id ? '#' . $order_id : '',
		'order_id'  => $order_id,
		'has_order' => $order_id > 0,
		'created'   => $created,
	);
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_pwgc_active() ) {
		return $surfaces;
	}

	$pro = minn_admin_pwgc_is_pro();

	$surfaces['pw-gift-cards'] = array(
		'label'      => __( 'Gift cards', 'minn-admin' ),
		'family'     => 'gift-cards',
		'sub'        => $pro ? 'PW Pro' : 'PW',
		'icon'       => 'tag',
		'cap'        => 'manage_woocommerce',
		'group'      => 'commerce',
		'status'     => array( 'route' => 'minn-admin/v1/pwgc/status' ),
		'collection' => array(
			'route'     => 'minn-admin/v1/pwgc/gift-cards',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'search'    => 'search={q}',
			'filterBar' => array(
				'searchPlaceholder' => __( 'Search gift cards (code, recipient…)', 'minn-admin' ),
				'statuses'          => array(
					array( 'active', __( 'Active', 'minn-admin' ) ),
					array( 'disabled', __( 'Disabled', 'minn-admin' ) ),
					array( 'expired', __( 'Expired', 'minn-admin' ) ),
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
				'sectionsRoute' => 'minn-admin/v1/pwgc/gift-cards/{id}/view',
			),
			'actions'   => array(
				array(
					'label'   => __( 'Disable', 'minn-admin' ),
					'route'   => 'minn-admin/v1/pwgc/gift-cards/{id}/status',
					'body'    => array( 'enabled' => false ),
					'when'    => array( 'key' => 'enabled', 'equals' => true ),
					'confirm' => __( 'Disable this gift card? Its balance can no longer be spent.', 'minn-admin' ),
					'danger'  => true,
				),
				array(
					'label' => __( 'Enable', 'minn-admin' ),
					'route' => 'minn-admin/v1/pwgc/gift-cards/{id}/status',
					'body'  => array( 'enabled' => true ),
					'when'  => array( 'key' => 'enabled', 'equals' => false ),
				),
				array(
					'label'  => __( 'Adjust balance', 'minn-admin' ),
					'route'  => 'minn-admin/v1/pwgc/gift-cards/{id}/balance',
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
					'route' => 'minn-admin/v1/pwgc/gift-cards/{id}/resend',
				),
				array(
					'label' => __( 'View order', 'minn-admin' ),
					'href'  => home_url( '/minn-admin/orders/{order_id}' ),
					'when'  => array( 'key' => 'has_order', 'equals' => true ),
				),
			),
			'create'    => array(
				'label'  => __( 'Add gift card', 'minn-admin' ),
				'route'  => 'minn-admin/v1/pwgc/gift-cards',
				'fields' => array_values( array_filter( array(
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
					$pro ? array(
						'key'      => 'recipient',
						'label'    => __( 'Recipient email', 'minn-admin' ),
						'type'     => 'email',
						'required' => false,
					) : null,
					$pro ? array(
						'key'      => 'recipient_name',
						'label'    => __( 'Recipient name', 'minn-admin' ),
						'required' => false,
					) : null,
					$pro ? array(
						'key'      => 'sender_name',
						'label'    => __( 'From', 'minn-admin' ),
						'required' => false,
					) : null,
					$pro ? array(
						'key'      => 'message',
						'label'    => __( 'Message', 'minn-admin' ),
						'type'     => 'textarea',
						'rows'     => 3,
						'required' => false,
					) : null,
					$pro ? array(
						'key'     => 'send',
						'label'   => __( 'Send the gift card email now', 'minn-admin' ),
						'type'    => 'select',
						'value'   => 'no',
						'options' => array(
							array( 'no', __( 'No', 'minn-admin' ) ),
							array( 'yes', __( 'Yes', 'minn-admin' ) ),
						),
					) : null,
				) ) ),
			),
		),
	);

	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_pwgc_active() ) {
		return;
	}

	$permission = 'minn_admin_pwgc_can';

	register_rest_route( 'minn-admin/v1', '/pwgc/gift-cards', array(
		'methods'             => 'GET',
		'permission_callback' => $permission,
		'callback'            => function ( $request ) {
			global $wpdb;
			$table    = minn_admin_pwgc_table();
			$activity = minn_admin_pwgc_activity_table();
			$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
			$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
			$search   = trim( (string) $request->get_param( 'search' ) );

			$wanted = $request->get_param( 'status' );
			$wanted = is_array( $wanted ) ? $wanted : ( ( '' === (string) $wanted ) ? array() : array( $wanted ) );
			$wanted = array_values( array_diff( array_map( 'strval', $wanted ), array( 'any' ) ) );

			$where  = array( '1=1' );
			$params = array();

			if ( $wanted ) {
				$ors = array();
				foreach ( $wanted as $slug ) {
					if ( 'disabled' === $slug ) {
						$ors[] = 'c.active = 0';
					} elseif ( 'expired' === $slug ) {
						$ors[] = '( c.active = 1 AND c.expiration_date IS NOT NULL AND c.expiration_date < %s )';
						$params[] = current_time( 'Y-m-d' );
					} elseif ( 'active' === $slug ) {
						$ors[] = '( c.active = 1 AND ( c.expiration_date IS NULL OR c.expiration_date >= %s ) )';
						$params[] = current_time( 'Y-m-d' );
					} else {
						$ors[] = '0=1';
					}
				}
				$where[] = '( ' . implode( ' OR ', $ors ) . ' )';
			}

			foreach ( array( 'after' => '>=', 'before' => '<=' ) as $param => $op ) {
				$raw = trim( (string) $request->get_param( $param ) );
				if ( '' === $raw ) {
					continue;
				}
				$ts = strtotime( $raw );
				if ( ! $ts ) {
					continue;
				}
				$where[]  = "c.create_date {$op} %s";
				$params[] = gmdate( 'Y-m-d H:i:s', $ts );
			}

			$join = '';
			if ( '' !== $search ) {
				$like = '%' . $wpdb->esc_like( $search ) . '%';
				if ( minn_admin_pwgc_is_pro() ) {
					$where[]  = '( c.number LIKE %s OR c.recipient_email LIKE %s )';
					$params[] = $like;
					$params[] = $like;
				} else {
					$where[]  = 'c.number LIKE %s';
					$params[] = $like;
				}
			}
			$clause = implode( ' AND ', $where );

			$total = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT COUNT(*) FROM {$table} c {$join} WHERE {$clause}",
				$params
			) );

			$ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT c.pimwick_gift_card_id FROM {$table} c {$join} WHERE {$clause} ORDER BY c.create_date DESC, c.pimwick_gift_card_id DESC LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) )
			) );

			$items = array();
			foreach ( array_map( 'intval', $ids ) as $id ) {
				$card = minn_admin_pwgc_load( $id );
				if ( ! is_wp_error( $card ) ) {
					$items[] = minn_admin_pwgc_row( $card );
				}
			}

			return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/pwgc/gift-cards/(?P<id>\d+)/view', array(
		'methods'             => 'GET',
		'permission_callback' => $permission,
		'callback'            => function ( $request ) {
			$card = minn_admin_pwgc_load( (int) $request['id'] );
			if ( is_wp_error( $card ) ) {
				return $card;
			}
			$row = minn_admin_pwgc_row( $card );

			$gift = array(
				array( 'label' => __( 'Code', 'minn-admin' ), 'value' => $card->get_number(), 'type' => 'code' ),
				array( 'label' => __( 'Status', 'minn-admin' ), 'value' => $row['status'], 'type' => 'pill' ),
				array( 'label' => __( 'Balance', 'minn-admin' ), 'value' => minn_admin_pwgc_money( $card->get_balance() ) ),
				array( 'label' => __( 'Original amount', 'minn-admin' ), 'value' => minn_admin_pwgc_money( minn_admin_pwgc_original( $card ) ) ),
			);
			$exp = method_exists( $card, 'get_expiration_date' ) ? $card->get_expiration_date() : '';
			if ( $exp ) {
				$gift[] = array( 'label' => __( 'Expires', 'minn-admin' ), 'value' => $exp );
			}

			$recipient = array();
			$email     = minn_admin_pwgc_recipient( $card );
			if ( $email ) {
				$recipient[] = array( 'label' => __( 'Email', 'minn-admin' ), 'value' => $email, 'type' => 'email' );
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
			if ( $row['order_id'] ) {
				$origin[] = array( 'label' => __( 'Order', 'minn-admin' ), 'value' => '#' . $row['order_id'] );
			}
			$created = method_exists( $card, 'get_create_date_gmt' ) ? $card->get_create_date_gmt() : $card->get_create_date();
			if ( $created ) {
				$ts = strtotime( $created . ( false === strpos( (string) $created, 'Z' ) ? ' UTC' : '' ) );
				$origin[] = array(
					'label' => __( 'Created', 'minn-admin' ),
					'value' => $ts ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) : $created,
				);
			}

			$sections = array( array( 'title' => __( 'Gift card', 'minn-admin' ), 'rows' => $gift ) );
			if ( $recipient ) {
				$sections[] = array( 'title' => __( 'Recipient', 'minn-admin' ), 'rows' => $recipient );
			}
			if ( $origin ) {
				$sections[] = array( 'title' => __( 'Origin', 'minn-admin' ), 'rows' => $origin );
			}

			$admin = function_exists( 'pwgc_admin_url' )
				? pwgc_admin_url( 'balances', array( 'card_number' => $card->get_number() ) )
				: admin_url( 'admin.php?page=wc-pw-gift-cards' );

			return rest_ensure_response( array(
				'title'    => $card->get_number(),
				'sections' => $sections,
				'adminUrl' => $admin,
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/pwgc/gift-cards/(?P<id>\d+)/status', array(
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => function ( $request ) {
			$card = minn_admin_pwgc_load( (int) $request['id'] );
			if ( is_wp_error( $card ) ) {
				return $card;
			}
			$enabled = rest_sanitize_boolean( $request->get_param( 'enabled' ) );
			if ( $enabled ) {
				$card->reactivate();
			} else {
				$card->deactivate();
			}
			return rest_ensure_response( array(
				'message' => $enabled
					? sprintf(
						/* translators: %s: gift card code. */
						__( '%s is enabled.', 'minn-admin' ),
						$card->get_number()
					)
					: sprintf(
						/* translators: %s: gift card code. */
						__( '%s is disabled and can no longer be spent.', 'minn-admin' ),
						$card->get_number()
					),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/pwgc/gift-cards', array(
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => function ( $request ) {
			$amount = minn_admin_pwgc_parse_amount( $request->get_param( 'amount' ) );
			if ( is_wp_error( $amount ) ) {
				return $amount;
			}

			$typed     = trim( (string) $request->get_param( 'recipient' ) );
			$recipient = $typed ? sanitize_email( $typed ) : '';
			if ( $typed && ! $recipient ) {
				return new WP_Error( 'minn_pwgc_recipient', __( 'That recipient email address is not valid.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			$send = 'yes' === (string) $request->get_param( 'send' );
			if ( $send && ! $recipient ) {
				return new WP_Error( 'minn_pwgc_no_recipient', __( 'Add a recipient email address, or choose not to send the email.', 'minn-admin' ), array( 'status' => 400 ) );
			}

			$typed_code = trim( (string) $request->get_param( 'code' ) );
			$code       = '';
			if ( '' !== $typed_code ) {
				$code = sanitize_text_field( $typed_code );
				if ( '' === $code || $code !== wp_strip_all_tags( $typed_code ) || strlen( $code ) > 128 ) {
					return new WP_Error( 'minn_pwgc_code', __( 'That code is not valid. Use plain text, or leave the field blank to generate one.', 'minn-admin' ), array( 'status' => 400 ) );
				}
			}

			$note = __( 'Issued from Minn Admin.', 'minn-admin' );
			if ( '' !== $code ) {
				$card = PW_Gift_Card::add_card( $code, $note );
			} else {
				$card = PW_Gift_Card::create_card( $note );
			}
			if ( ! $card instanceof PW_Gift_Card ) {
				$msg = is_string( $card ) ? $card : __( 'The gift card could not be created.', 'minn-admin' );
				$status = is_string( $card ) && false !== stripos( $card, 'already' ) ? 409 : 500;
				return new WP_Error( 'minn_pwgc_create', $msg, array( 'status' => $status ) );
			}

			$card->credit( $amount, $note );

			if ( minn_admin_pwgc_is_pro() ) {
				if ( $recipient && method_exists( $card, 'set_recipient_email' ) ) {
					$card->set_recipient_email( $recipient );
				}
				if ( method_exists( $card, 'set_recipient_name' ) ) {
					$card->set_recipient_name( sanitize_text_field( (string) $request->get_param( 'recipient_name' ) ) );
				}
				if ( method_exists( $card, 'set_from' ) ) {
					$card->set_from( sanitize_text_field( (string) $request->get_param( 'sender_name' ) ) );
				}
				if ( method_exists( $card, 'set_message' ) ) {
					$card->set_message( sanitize_textarea_field( (string) $request->get_param( 'message' ) ) );
				}
			}

			if ( $send && $recipient ) {
				$from = sanitize_text_field( (string) $request->get_param( 'sender_name' ) );
				$name = sanitize_text_field( (string) $request->get_param( 'recipient_name' ) );
				$msg  = sanitize_textarea_field( (string) $request->get_param( 'message' ) );
				minn_admin_pwgc_send_email( $card, $recipient, $from, $name, $msg, $amount );
			}

			return rest_ensure_response( array(
				'id'      => (int) $card->get_id(),
				'message' => $send
					? sprintf(
						/* translators: 1: gift card code; 2: recipient email address. */
						__( '%1$s created and sent to %2$s.', 'minn-admin' ),
						$card->get_number(),
						$recipient
					)
					: sprintf(
						/* translators: 1: gift card code; 2: formatted money amount. */
						__( '%1$s created, holding %2$s.', 'minn-admin' ),
						$card->get_number(),
						minn_admin_pwgc_money( $amount )
					),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/pwgc/gift-cards/(?P<id>\d+)/balance', array(
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => function ( $request ) {
			$card = minn_admin_pwgc_load( (int) $request['id'] );
			if ( is_wp_error( $card ) ) {
				return $card;
			}
			$balance = minn_admin_pwgc_parse_amount( $request->get_param( 'balance' ), true );
			if ( is_wp_error( $balance ) ) {
				return $balance;
			}
			if ( ! $card->get_active() ) {
				return new WP_Error( 'minn_pwgc_inactive', __( 'Enable this gift card before changing its balance.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			$delta = $balance - (float) $card->get_balance();
			$card->adjust_balance( $delta, __( 'Balance set from Minn Admin.', 'minn-admin' ) );
			return rest_ensure_response( array(
				'message' => sprintf(
					/* translators: 1: gift card code; 2: formatted money amount. */
					__( '%1$s now holds %2$s.', 'minn-admin' ),
					$card->get_number(),
					minn_admin_pwgc_money( $balance )
				),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/pwgc/gift-cards/(?P<id>\d+)/resend', array(
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => function ( $request ) {
			$card = minn_admin_pwgc_load( (int) $request['id'] );
			if ( is_wp_error( $card ) ) {
				return $card;
			}
			$recipient = minn_admin_pwgc_recipient( $card );
			if ( ! $recipient ) {
				return new WP_Error(
					'minn_pwgc_no_recipient',
					__( 'This gift card has no recipient address, so there is nothing to resend.', 'minn-admin' ),
					array( 'status' => 400 )
				);
			}
			$from = method_exists( $card, 'get_from' ) ? (string) $card->get_from() : '';
			$name = method_exists( $card, 'get_recipient_name' ) ? (string) $card->get_recipient_name() : '';
			$msg  = method_exists( $card, 'get_message' ) ? (string) $card->get_message() : '';
			minn_admin_pwgc_send_email( $card, $recipient, $from, $name, $msg, $card->get_balance() );
			return rest_ensure_response( array(
				'message' => sprintf(
					/* translators: %s: recipient email address. */
					__( 'Gift card sent to %s.', 'minn-admin' ),
					$recipient
				),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/pwgc/status', array(
		'methods'             => 'GET',
		'permission_callback' => $permission,
		'callback'            => function () {
			global $wpdb;
			$table    = minn_admin_pwgc_table();
			$activity = minn_admin_pwgc_activity_table();
			$today    = current_time( 'Y-m-d' );
			$decimals = function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;

			$active = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE active = 1 AND ( expiration_date IS NULL OR expiration_date >= %s )",
				$today
			) );
			$outstanding = (float) $wpdb->get_var( $wpdb->prepare(
				"SELECT SUM( ROUND( a.amount, %d ) ) FROM {$activity} a
				 INNER JOIN {$table} c ON c.pimwick_gift_card_id = a.pimwick_gift_card_id
				 WHERE c.active = 1 AND ( c.expiration_date IS NULL OR c.expiration_date >= %s )",
				$decimals,
				$today
			) );

			return rest_ensure_response( array(
				'rows'    => array(
					array(
						'label' => __( 'Outstanding balance', 'minn-admin' ),
						'value' => minn_admin_pwgc_money( $outstanding ),
						'hint'  => sprintf(
							/* translators: %s: number of active gift cards. */
							_n( 'across %s active gift card', 'across %s active gift cards', $active, 'minn-admin' ),
							number_format_i18n( $active )
						),
					),
				),
				'actions' => array(
					array(
						'label' => __( 'Open PW Gift Cards ↗', 'minn-admin' ),
						'href'  => admin_url( 'admin.php?page=wc-pw-gift-cards' ),
					),
				),
			) );
		},
	) );
} );
