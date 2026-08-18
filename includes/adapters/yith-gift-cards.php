<?php
/**
 * Bundled adapter: YITH WooCommerce Gift Cards (free and premium).
 *
 * YITH has no REST API at all: its gift cards are a `gift_card` CPT registered
 * with show_in_rest => false, it registers no routes of its own, and it keeps no
 * custom tables (everything is posts + postmeta). So this is a shim in the
 * Gravity SMTP mould: Minn's own routes read the posts directly and write
 * through YITH's OWN object API (YITH_YWGC_Gift_Card), never through its admin
 * screen. Its merchant verbs live as query-arg handlers on that screen
 * (?enable-gift-card&id=…, which wp_safe_redirect()s), and those are not
 * callable from a REST request.
 *
 * The record model is identical in free and premium (the META_* / STATUS_*
 * constants match byte for byte), so one adapter serves both editions. Premium
 * adds features around it: expiration, bulk generation, CSV, PDF, QR. Those
 * stay in YITH; the detail links out to its screen.
 *
 * See docs/yith-gift-cards.md for the source audit behind these choices.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * The one capability check. The descriptor and every permission_callback call
 * this, so UI gating and the real boundary cannot drift apart.
 *
 * The CPT declares capability_type 'product', but a gift card is a money
 * instrument: it carries a redeemable balance an admin can change by hand. The
 * shop manager (manage_woocommerce) is the honest gate, not everyone who may
 * edit a product.
 *
 * @return bool
 */
function minn_admin_ywgc_can() {
	return current_user_can( 'manage_woocommerce' );
}

/**
 * Whether YITH's gift cards are present, in either edition.
 *
 * @return bool
 */
function minn_admin_ywgc_active() {
	return class_exists( 'YITH_YWGC_Gift_Card' ) && defined( 'YWGC_CUSTOM_POST_TYPE_NAME' );
}

/**
 * Minn's status vocabulary mapped onto YITH's post statuses.
 *
 * The raw slugs are a storage detail ('ywgc-code-not-valid' humanizes into
 * something nobody would write), and Minn's shared pill vocabulary already
 * knows what 'active' and 'expired' mean. Tabs, columns and action gates all
 * speak the left-hand column; only this map and the queries know the right.
 *
 * @return array<string,string> Minn status => YITH post status.
 */
function minn_admin_ywgc_statuses() {
	return array(
		'active'         => 'publish',
		'disabled'       => 'ywgc-disabled',
		'dismissed'      => 'ywgc-dismissed',
		'pre-printed'    => 'ywgc-pre-printed',
		'code-not-valid' => 'ywgc-code-not-valid',
	);
}

/**
 * Money as display-ready text.
 *
 * wc_price() returns HTML (a <span class="woocommerce-Price-amount"> wrapping
 * an entity-encoded symbol) and every surface value is escaped before render,
 * so handing it over raw would print the markup. Strip and decode here, where
 * the store's own currency, decimals and separators still apply.
 *
 * @param float|string $value    Amount.
 * @param string       $currency Optional currency code the card was sold in.
 * @return string
 */
function minn_admin_ywgc_money( $value, $currency = '' ) {
	if ( ! function_exists( 'wc_price' ) ) {
		return (string) $value;
	}
	$args = $currency ? array( 'currency' => $currency ) : array();
	return html_entity_decode( wp_strip_all_tags( wc_price( (float) $value, $args ) ), ENT_QUOTES, 'UTF-8' );
}

/**
 * Parse a money amount from a request, or a WP_Error.
 *
 * Scientific notation and non-finite values are numeric to PHP and would
 * otherwise store a $1e20 card. Zero is allowed only when $allow_zero is
 * true (a balance can be emptied; a new card cannot be worth nothing).
 *
 * @param mixed $raw        Request value.
 * @param bool  $allow_zero Whether 0 is a legal amount.
 * @return float|WP_Error
 */
function minn_admin_ywgc_parse_amount( $raw, $allow_zero = false ) {
	if ( '' === $raw || null === $raw || ! is_numeric( $raw ) ) {
		return new WP_Error( 'minn_ywgc_amount', __( 'Enter the amount as a number.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	$amount = (float) $raw;
	if ( ! is_finite( $amount ) ) {
		return new WP_Error( 'minn_ywgc_amount', __( 'Enter the amount as a number.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	$amount = round( $amount, function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2 );
	if ( $amount < 0 || ( ! $allow_zero && $amount <= 0 ) ) {
		return new WP_Error(
			'minn_ywgc_amount',
			$allow_zero
				? __( 'A balance cannot be negative.', 'minn-admin' )
				: __( 'A gift card has to be worth more than nothing.', 'minn-admin' ),
			array( 'status' => 400 )
		);
	}
	// A money instrument, not a float playground. Nine million and change
	// is already past any real store card; refuse the rest rather than
	// writing a scientific-notation title into the liability.
	if ( $amount > 9999999.99 ) {
		return new WP_Error( 'minn_ywgc_amount', __( 'Enter an amount no larger than 9,999,999.99.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	return $amount;
}

/**
 * Read one gift card's row shape straight from post + meta.
 *
 * Deliberately NOT through YITH_YWGC_Gift_Card: its constructor MIGRATES a
 * legacy card as a side effect of loading it (when _ywgc_amount_total is empty
 * it calls update_amount(), which writes meta). That is fine for a detail view
 * or a verb, and wrong for a list, where it would turn painting page one into
 * twenty-five writes. The object is used everywhere a single card is touched.
 *
 * @param WP_Post $post Gift card post.
 * @return array
 */
function minn_admin_ywgc_row( $post ) {
	$amount     = (float) get_post_meta( $post->ID, '_ywgc_amount_total', true );
	$balance    = (float) get_post_meta( $post->ID, '_ywgc_balance_total', true );
	$currency   = (string) get_post_meta( $post->ID, '_ywgc_currency', true );
	$expiration = (int) get_post_meta( $post->ID, '_ywgc_expiration', true );
	$order_id   = (int) get_post_meta( $post->ID, '_ywgc_order_id', true );

	$status = array_search( $post->post_status, minn_admin_ywgc_statuses(), true );
	$status = $status ? $status : $post->post_status;

	// Expiration is meta, not a post status: an expired card is still
	// 'publish' in the database. A merchant scanning the list needs to see
	// that it can no longer be spent, so the pill says so.
	$expired = $expiration > 0 && $expiration < time();
	if ( 'active' === $status && $expired ) {
		$status = 'expired';
	}

	return array(
		'id'        => $post->ID,
		'code'      => $post->post_title,
		'amount'    => minn_admin_ywgc_money( $amount, $currency ),
		'balance'   => minn_admin_ywgc_money( $balance, $currency ),
		'status'    => $status,
		// The action gates read this rather than `status`, so an expired card
		// can still be disabled and a disabled one re-enabled.
		'enabled'   => 'publish' === $post->post_status,
		'recipient' => (string) get_post_meta( $post->ID, '_ywgc_recipient', true ),
		'order'     => $order_id ? '#' . $order_id : '',
		// The href action fills {order_id} from here, and `has_order` gates it:
		// a card issued by hand has no order to open.
		'order_id'  => $order_id,
		'has_order' => $order_id > 0,
		'created'   => $post->post_date_gmt,
	);
}

/**
 * Whether the current user may issue a gift card by hand.
 *
 * Two gates, and the second one is YITH's. The capability is the same one
 * every other verb here uses; `current_user_can_create()` is the switch YITH
 * gives a site to turn hand-issued cards off, and it gates YITH's own create
 * UI, so Minn honours it rather than offering a button the store has already
 * decided against.
 *
 * @return bool
 */
function minn_admin_ywgc_can_create() {
	if ( ! minn_admin_ywgc_can() ) {
		return false;
	}
	if ( function_exists( 'YITH_YWGC' ) ) {
		$ywgc = YITH_YWGC();
		if ( is_object( $ywgc ) && method_exists( $ywgc, 'current_user_can_create' ) ) {
			return (bool) $ywgc->current_user_can_create();
		}
	}
	return true;
}

/**
 * A blank gift card as YITH's OWN most specific class.
 *
 * Reads go through post meta (see minn_admin_ywgc_row), but a write has to be
 * the object, and it has to be the deepest one installed: YITH_YWGC_Gift_Card,
 * _Extended and _Premium each override save(), and each writes meta the one
 * below it does not. Creating with the base class on a premium site would
 * leave a card YITH's own screens read as half written. Resolve downwards,
 * exactly as YITH's own generator does.
 *
 * @return YITH_YWGC_Gift_Card
 */
function minn_admin_ywgc_new() {
	foreach ( array( 'YWGC_Gift_Card_Premium', 'YITH_YWGC_Gift_Card_Extended' ) as $class ) {
		if ( class_exists( $class ) ) {
			return new $class();
		}
	}
	return new YITH_YWGC_Gift_Card();
}

/**
 * Whether a gift card code is already spoken for.
 *
 * Deliberately not YITH's own get_gift_card_by_code(): that runs get_posts(),
 * which defaults to post_status 'publish', so it cannot see a disabled,
 * dismissed or pre-printed card and would happily hand out a code that is
 * already in circulation. A code is the whole security of the instrument, so
 * the check reads every status.
 *
 * @param string $code Gift card code.
 * @return bool
 */
function minn_admin_ywgc_code_taken( $code ) {
	global $wpdb;

	$id = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s LIMIT 1",
			YWGC_CUSTOM_POST_TYPE_NAME,
			$code
		)
	);

	return (int) $id > 0;
}

/**
 * Generate an unused gift card code, or '' after too many collisions.
 *
 * The pattern and the randomness are YITH's, because the store configures the
 * shape of its codes in YITH's settings and a card issued from Minn must not
 * look different. The retry is YITH's too. Where this parts company is the
 * failure: YITH's bulk generator saves the card anyway, titled NOT VALID,
 * because a batch of five hundred should not stop for one. A merchant filling
 * in one form is better served by an error and the form still open.
 *
 * @return string
 */
function minn_admin_ywgc_generate_code() {
	if ( ! function_exists( 'YITH_YWGC' ) ) {
		return '';
	}

	$ywgc = YITH_YWGC();
	if ( ! is_object( $ywgc ) || ! method_exists( $ywgc, 'generate_gift_card_code' ) ) {
		return '';
	}

	for ( $attempt = 0; $attempt < 100; $attempt++ ) {
		$code = (string) $ywgc->generate_gift_card_code();
		if ( '' !== $code && ! minn_admin_ywgc_code_taken( $code ) ) {
			return $code;
		}
	}

	return '';
}

/**
 * The expiry a new card inherits from the store, as YITH would write it.
 *
 * There is no date type in the field vocabulary, and inventing an expiry here
 * would be worse than not offering one: the store already answers this in
 * YITH's settings (a number of months, premium only), and a hand-issued card
 * that expires on a different rule than the ones YITH issues is the kind of
 * difference nobody notices until a customer is turned away at the counter.
 *
 * Two departures from YITH's own generator, both bugs there: it tests
 * `'0' !== $option`, which is TRUE for the empty string a free site returns,
 * and then hands strtotime a monthless "+ month". Anything that is not a
 * positive number means no expiry. The timestamp itself is site time because
 * that is what YITH writes, so the value is indistinguishable from its own.
 *
 * @return int|string Timestamp, or '0' for no expiry.
 */
function minn_admin_ywgc_expiration() {
	$months = get_option( 'ywgc_usage_expiration', '' );

	if ( ! is_numeric( $months ) || (int) $months <= 0 ) {
		return '0';
	}

	return strtotime( '+' . (int) $months . ' month', current_time( 'timestamp' ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
}

/**
 * Send one gift card's email through YITH's own mailer.
 *
 * Premium subclasses the mailer, so resolve the most specific instance
 * available and fall back to the action hook, which both editions register.
 * YITH refuses to send a card that is not digital or has no recipient, and it
 * does so silently, so callers check both BEFORE claiming anything was sent.
 *
 * @param int $id Gift card post ID.
 * @return void
 */
function minn_admin_ywgc_send_email( $id ) {
	foreach ( array( 'YITH_YWGC_Emails_Premium', 'YITH_YWGC_Emails_Extended', 'YITH_YWGC_Emails' ) as $class ) {
		if ( class_exists( $class ) && method_exists( $class, 'get_instance' ) ) {
			$mailer = call_user_func( array( $class, 'get_instance' ) );
			if ( $mailer && method_exists( $mailer, 'send_gift_card_email' ) ) {
				$mailer->send_gift_card_email( $id, false );
				return;
			}
		}
	}

	// Both editions register this action on their mailer.
	do_action( 'yith_ywgc_send_gift_card_email', $id );
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_ywgc_active() ) {
		return $surfaces;
	}

	$surfaces['yith-gift-cards'] = array(
		'label'      => __( 'Gift cards', 'minn-admin' ),
		'family'     => 'gift-cards',
		'sub'        => 'YITH',
		'icon'       => 'tag',
		'cap'        => 'manage_woocommerce',
		'group'      => 'commerce',
		'status'     => array( 'route' => 'minn-admin/v1/ywgc/status' ),
		'collection' => array(
			'route'     => 'minn-admin/v1/ywgc/gift-cards',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'search'    => 'search={q}',
			// The orders bar rather than a pill strip: a status dropdown that
			// can hold more than one status at a time, Add filter, chips
			// beneath and the whole narrowing in the URL. Minn renders it; the
			// adapter only supplies the vocabulary and answers the parameters.
			'filterBar' => array(
				'searchPlaceholder' => __( 'Search gift cards (code, recipient…)', 'minn-admin' ),
				'statuses'          => array(
					array( 'active', __( 'Active', 'minn-admin' ) ),
					array( 'disabled', __( 'Disabled', 'minn-admin' ) ),
					array( 'dismissed', __( 'Dismissed', 'minn-admin' ) ),
					array( 'pre-printed', __( 'Pre-printed', 'minn-admin' ) ),
					array( 'code-not-valid', __( 'Code not valid', 'minn-admin' ) ),
				),
				// Status is here as well as in the dropdown because the two
				// are different questions: the dropdown is the old tab strip
				// (one status, one click), Add filter is the multi-select that
				// a strip could never express. Date is the only other
				// dimension with a real parameter behind it; customer and
				// product would need a lookup this shim cannot serve, and
				// narrowing them in the browser would lie about the count the
				// moment the list paginates.
				'kinds'             => array( 'status', 'date' ),
			),
			'columns'   => array(
				array( 'key' => 'code', 'label' => __( 'Code', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,1.4fr)' ),
				array( 'key' => 'balance', 'label' => __( 'Balance', 'minn-admin' ), 'format' => 'num', 'width' => '110px' ),
				array( 'key' => 'amount', 'label' => __( 'Amount', 'minn-admin' ), 'format' => 'num', 'width' => '110px' ),
				array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill', 'width' => '120px' ),
				array( 'key' => 'recipient', 'label' => __( 'Recipient', 'minn-admin' ), 'width' => 'minmax(0,1.2fr)' ),
				array( 'key' => 'order', 'label' => __( 'Order', 'minn-admin' ), 'format' => 'mono', 'width' => '90px' ),
				// post_date_gmt, so the client must not read it as site time.
				array( 'key' => 'created', 'label' => __( 'Created', 'minn-admin' ), 'format' => 'ago', 'utc' => true ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/ywgc/gift-cards/{id}/view',
			),
			'actions'   => array(
				array(
					'label'   => __( 'Disable', 'minn-admin' ),
					'route'   => 'minn-admin/v1/ywgc/gift-cards/{id}/status',
					'body'    => array( 'enabled' => false ),
					'when'    => array( 'key' => 'enabled', 'equals' => true ),
					'confirm' => __( 'Disable this gift card? Its balance can no longer be spent.', 'minn-admin' ),
					'danger'  => true,
				),
				array(
					'label' => __( 'Enable', 'minn-admin' ),
					'route' => 'minn-admin/v1/ywgc/gift-cards/{id}/status',
					'body'  => array( 'enabled' => true ),
					'when'  => array( 'key' => 'enabled', 'equals' => false ),
				),
				array(
					'label'  => __( 'Adjust balance', 'minn-admin' ),
					'route'  => 'minn-admin/v1/ywgc/gift-cards/{id}/balance',
					// Parameterized actions are detail-only by contract: they
					// need the modal's form chrome.
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
					'route' => 'minn-admin/v1/ywgc/gift-cards/{id}/resend',
				),
				array(
					'label' => __( 'View order', 'minn-admin' ),
					'href'  => home_url( '/minn-admin/orders/{order_id}' ),
					'when'  => array( 'key' => 'has_order', 'equals' => true ),
				),
			),
		),
	);

	// Issuing a card by hand is a real counter transaction (a refund made
	// good, a comp, a promotion), and both editions offer it. The create
	// primitive is the whole UI: Minn renders the Add button, the modal and
	// the form, so this only declares what the form asks for. Everything here
	// is optional except the amount, because the one thing a gift card cannot
	// be issued without is a value.
	if ( minn_admin_ywgc_can_create() ) {
		$surfaces['yith-gift-cards']['collection']['create'] = array(
			'label'  => __( 'Add gift card', 'minn-admin' ),
			'route'  => 'minn-admin/v1/ywgc/gift-cards',
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
					'required' => false,
				),
				array(
					'key'      => 'recipient_name',
					'label'    => __( 'Recipient name', 'minn-admin' ),
					'required' => false,
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
		);
	}

	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_ywgc_active() ) {
		return;
	}

	$permission = 'minn_admin_ywgc_can';

	// The list. Raw SQL rather than WP_Query because the search has to reach
	// BOTH the code (post_title) and the recipient (postmeta) in one ordered,
	// paginated result: a merchant searches with whatever the customer read
	// them over the phone. Prefix-scoped and prepared; ids only, with the meta
	// cache primed once so the row builder costs no further queries.
	register_rest_route( 'minn-admin/v1', '/ywgc/gift-cards', array(
		'methods'             => 'GET',
		'permission_callback' => $permission,
		'callback'            => function ( $request ) {
			global $wpdb;

			$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
			$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
			$search   = trim( (string) $request->get_param( 'search' ) );

			// The filter bar sends WooCommerce's own shape: `status=any` for
			// no narrowing, a bare `status` for one, and repeated `status[]`
			// for several. An unknown slug narrows to nothing rather than
			// quietly widening to everything.
			$map    = minn_admin_ywgc_statuses();
			$wanted = $request->get_param( 'status' );
			$wanted = is_array( $wanted ) ? $wanted : ( ( '' === (string) $wanted ) ? array() : array( $wanted ) );
			$wanted = array_values( array_diff( array_map( 'strval', $wanted ), array( 'any' ) ) );
			if ( $wanted ) {
				$statuses = array();
				foreach ( $wanted as $slug ) {
					$statuses[] = isset( $map[ $slug ] ) ? $map[ $slug ] : '__none__';
				}
			} else {
				$statuses = array_values( $map );
			}

			$where  = array( 'p.post_type = %s' );
			$params = array( YWGC_CUSTOM_POST_TYPE_NAME );

			$where[]  = 'p.post_status IN ( ' . implode( ', ', array_fill( 0, count( $statuses ), '%s' ) ) . ' )';
			$params   = array_merge( $params, $statuses );
			$join     = '';

			// The date window, in WooCommerce's own parameter names. The bar
			// sends ISO datetimes; gift cards are posts, so the window applies
			// to post_date_gmt and the values are compared as GMT.
			foreach ( array( 'after' => '>=', 'before' => '<=' ) as $param => $op ) {
				$raw = trim( (string) $request->get_param( $param ) );
				if ( '' === $raw ) {
					continue;
				}
				$ts = strtotime( $raw );
				if ( ! $ts ) {
					continue;
				}
				$where[]  = "p.post_date_gmt {$op} %s";
				$params[] = gmdate( 'Y-m-d H:i:s', $ts );
			}

			if ( '' !== $search ) {
				$like     = '%' . $wpdb->esc_like( $search ) . '%';
				$join     = "LEFT JOIN {$wpdb->postmeta} m ON ( m.post_id = p.ID AND m.meta_key = '_ywgc_recipient' )";
				$where[]  = '( p.post_title LIKE %s OR m.meta_value LIKE %s )';
				$params[] = $like;
				$params[] = $like;
			}
			$clause = implode( ' AND ', $where );

			$total = (int) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT COUNT( DISTINCT p.ID ) FROM {$wpdb->posts} p {$join} WHERE {$clause}",
				$params
			) );

			$ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p {$join} WHERE {$clause} ORDER BY p.post_date DESC, p.ID DESC LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) )
			) );

			$items = array();
			if ( $ids ) {
				$ids = array_map( 'intval', $ids );
				update_meta_cache( 'post', $ids );
				foreach ( $ids as $id ) {
					$post = get_post( $id );
					if ( $post ) {
						$items[] = minn_admin_ywgc_row( $post );
					}
				}
			}

			return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
		},
	) );

	// The detail. One card, so the object is safe (and right) to instantiate:
	// its constructor resolves the legacy-meta fallbacks YITH itself relies on.
	register_rest_route( 'minn-admin/v1', '/ywgc/gift-cards/(?P<id>\d+)/view', array(
		'methods'             => 'GET',
		'permission_callback' => $permission,
		'callback'            => function ( $request ) {
			$id   = (int) $request['id'];
			$post = get_post( $id );
			if ( ! $post || YWGC_CUSTOM_POST_TYPE_NAME !== $post->post_type ) {
				return new WP_Error( 'minn_ywgc_not_found', __( 'Gift card not found.', 'minn-admin' ), array( 'status' => 404 ) );
			}

			$gc  = new YITH_YWGC_Gift_Card( array( 'ID' => $id ) );
			$row = minn_admin_ywgc_row( $post );

			$card = array(
				array( 'label' => __( 'Code', 'minn-admin' ), 'value' => $gc->gift_card_number, 'type' => 'code' ),
				array( 'label' => __( 'Status', 'minn-admin' ), 'value' => $row['status'], 'type' => 'pill' ),
				array( 'label' => __( 'Balance', 'minn-admin' ), 'value' => minn_admin_ywgc_money( $gc->get_balance(), $gc->currency ) ),
				array( 'label' => __( 'Original amount', 'minn-admin' ), 'value' => minn_admin_ywgc_money( $gc->total_amount, $gc->currency ) ),
			);
			if ( (int) $gc->expiration > 0 ) {
				$card[] = array(
					'label' => __( 'Expires', 'minn-admin' ),
					// Stored as a unix timestamp, not a formatted date.
					'value' => wp_date( get_option( 'date_format' ), (int) $gc->expiration ),
				);
			}

			$recipient = array();
			if ( $gc->recipient ) {
				$recipient[] = array( 'label' => __( 'Email', 'minn-admin' ), 'value' => $gc->recipient, 'type' => 'email' );
			}
			if ( $gc->recipient_name ) {
				$recipient[] = array( 'label' => __( 'Name', 'minn-admin' ), 'value' => $gc->recipient_name );
			}
			if ( $gc->sender_name ) {
				$recipient[] = array( 'label' => __( 'From', 'minn-admin' ), 'value' => $gc->sender_name );
			}
			if ( $gc->message ) {
				$recipient[] = array( 'label' => __( 'Message', 'minn-admin' ), 'value' => $gc->message );
			}

			$origin = array();
			if ( (int) $gc->order_id > 0 ) {
				// A `url` row renders the href AS its text, which puts a raw
				// address in a card that is otherwise all short values. The
				// order number reads better here; the "View order" action is
				// the way into it.
				$origin[] = array(
					'label' => __( 'Order', 'minn-admin' ),
					'value' => '#' . (int) $gc->order_id,
				);
			}
			if ( (int) $gc->customer_id > 0 ) {
				$user     = get_userdata( (int) $gc->customer_id );
				$origin[] = array(
					'label' => __( 'Bought by', 'minn-admin' ),
					'value' => $user ? $user->display_name : sprintf(
						/* translators: %d: WordPress user ID. */
						__( 'User #%d', 'minn-admin' ),
						(int) $gc->customer_id
					),
				);
			}
			$origin[] = array(
				'label' => __( 'Created', 'minn-admin' ),
				'value' => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), get_post_timestamp( $post ) ),
			);
			$origin[] = array(
				'label' => __( 'Delivery', 'minn-admin' ),
				'value' => $gc->is_digital ? __( 'Digital', 'minn-admin' ) : __( 'Physical', 'minn-admin' ),
			);
			if ( $gc->internal_notes ) {
				$origin[] = array( 'label' => __( 'Internal notes', 'minn-admin' ), 'value' => $gc->internal_notes );
			}

			$sections = array( array( 'title' => __( 'Gift card', 'minn-admin' ), 'rows' => $card ) );
			if ( $recipient ) {
				$sections[] = array( 'title' => __( 'Recipient', 'minn-admin' ), 'rows' => $recipient );
			}
			$sections[] = array( 'title' => __( 'Origin', 'minn-admin' ), 'rows' => $origin );

			return rest_ensure_response( array(
				// Names the modal. Without it the header carries the surface
				// label, so every card in a stack reads "Gift cards". The Code
				// row survives beside it because a code is read out loud, and
				// monospace is what makes that possible.
				'title'    => $gc->gift_card_number,
				'sections' => $sections,
				'adminUrl' => admin_url( 'post.php?post=' . $id . '&action=edit' ),
			) );
		},
	) );

	// Enable / disable, through YITH's own setter (which writes the post
	// status its list, its shortcodes and its checkout all read).
	register_rest_route( 'minn-admin/v1', '/ywgc/gift-cards/(?P<id>\d+)/status', array(
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => function ( $request ) {
			$id = (int) $request['id'];
			$gc = minn_admin_ywgc_load( $id );
			if ( is_wp_error( $gc ) ) {
				return $gc;
			}
			// rest_sanitize_boolean, not (bool): the string "false" is
			// truthy in PHP and would enable a card the merchant meant
			// to turn off.
			$enabled = rest_sanitize_boolean( $request->get_param( 'enabled' ) );
			$gc->set_enabled_status( $enabled );

			return rest_ensure_response( array(
				'message' => $enabled
					? sprintf(
						/* translators: %s: gift card code. */
						__( '%s is enabled.', 'minn-admin' ),
						$gc->gift_card_number
					)
					: sprintf(
						/* translators: %s: gift card code. */
						__( '%s is disabled and can no longer be spent.', 'minn-admin' ),
						$gc->gift_card_number
					),
			) );
		},
	) );

	// Issue a card by hand. This is a SECOND handler on the collection route,
	// not a new URL: register_rest_route() upgrades a single-handler
	// declaration into a list and merges repeat registrations, so create
	// posts to the same address the list reads from, which is what the REST
	// shape says it should be.
	register_rest_route( 'minn-admin/v1', '/ywgc/gift-cards', array(
		'methods'             => 'POST',
		'permission_callback' => 'minn_admin_ywgc_can_create',
		'callback'            => function ( $request ) {
			$amount = minn_admin_ywgc_parse_amount( $request->get_param( 'amount' ) );
			if ( is_wp_error( $amount ) ) {
				return $amount;
			}

			$typed     = trim( (string) $request->get_param( 'recipient' ) );
			$recipient = $typed ? sanitize_email( $typed ) : '';
			if ( $typed && ! $recipient ) {
				return new WP_Error( 'minn_ywgc_recipient', __( 'That recipient email address is not valid.', 'minn-admin' ), array( 'status' => 400 ) );
			}

			// Everything that can be refused is refused BEFORE the card
			// exists. A card created and then not sent would leave the
			// merchant tidying up after an error message.
			$send = 'yes' === (string) $request->get_param( 'send' );
			if ( $send && ! $recipient ) {
				return new WP_Error( 'minn_ywgc_no_recipient', __( 'Add a recipient email address, or choose not to send the email.', 'minn-admin' ), array( 'status' => 400 ) );
			}

			$typed_code = trim( (string) $request->get_param( 'code' ) );
			$code       = '';
			if ( '' !== $typed_code ) {
				$code = sanitize_text_field( $typed_code );
				// A typed code that is not the same after stripping tags
				// or collapsing whitespace is not a code: refuse rather
				// than silently generate a different one.
				if ( '' === $code || $code !== wp_strip_all_tags( $typed_code ) || strlen( $code ) > 191 ) {
					return new WP_Error( 'minn_ywgc_code', __( 'That code is not valid. Use plain text, or leave the field blank to generate one.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				if ( minn_admin_ywgc_code_taken( $code ) ) {
					return new WP_Error( 'minn_ywgc_code_taken', __( 'That code is already in use. Leave the field blank to generate one.', 'minn-admin' ), array( 'status' => 409 ) );
				}
			} else {
				$code = minn_admin_ywgc_generate_code();
				if ( '' === $code ) {
					return new WP_Error( 'minn_ywgc_code', __( 'Could not generate an unused code. Try again.', 'minn-admin' ), array( 'status' => 500 ) );
				}
			}

			$gc                   = minn_admin_ywgc_new();
			$gc->gift_card_number = $code;
			$gc->total_amount     = $amount;
			// The balance is a protected property, and update_balance() is
			// its setter. With no ID yet it only sets it; save() writes it.
			// This is the order YITH's own generator uses.
			$gc->update_balance( $amount );
			$gc->currency       = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '';
			$gc->version        = defined( 'YITH_YWGC_VERSION' ) ? YITH_YWGC_VERSION : '';
			$gc->recipient      = $recipient;
			$gc->recipient_name = sanitize_text_field( (string) $request->get_param( 'recipient_name' ) );
			$gc->sender_name    = sanitize_text_field( (string) $request->get_param( 'sender_name' ) );
			$gc->message        = sanitize_textarea_field( (string) $request->get_param( 'message' ) );
			// Not cosmetic. YITH's mailer bails on is_virtual() before it
			// does anything, so a card carrying a recipient address but left
			// non-digital would accept Resend email forever and send nothing.
			$gc->is_digital = (bool) $recipient;
			$gc->expiration = minn_admin_ywgc_expiration();
			// Premium's own marker for a card whose value was typed rather
			// than bought from a product's amount list. Set only where the
			// property exists, so the free edition is untouched.
			if ( property_exists( $gc, 'is_manual_amount' ) ) {
				$gc->is_manual_amount = true;
			}
			$gc->save();

			if ( ! $gc->ID ) {
				return new WP_Error( 'minn_ywgc_create', __( 'The gift card could not be created.', 'minn-admin' ), array( 'status' => 500 ) );
			}

			if ( $send ) {
				minn_admin_ywgc_send_email( $gc->ID );
			}

			return rest_ensure_response( array(
				'id'      => $gc->ID,
				'message' => $send
					? sprintf(
						/* translators: 1: gift card code; 2: recipient email address. */
						__( '%1$s created and sent to %2$s.', 'minn-admin' ),
						$code,
						$recipient
					)
					: sprintf(
						/* translators: 1: gift card code; 2: formatted money amount. */
						__( '%1$s created, holding %2$s.', 'minn-admin' ),
						$code,
						minn_admin_ywgc_money( $amount )
					),
			) );
		},
	) );

	// Adjust the balance. YITH's own screen allows this too: topping a card up
	// without creating an order is a real counter transaction.
	register_rest_route( 'minn-admin/v1', '/ywgc/gift-cards/(?P<id>\d+)/balance', array(
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => function ( $request ) {
			$id = (int) $request['id'];
			$gc = minn_admin_ywgc_load( $id );
			if ( is_wp_error( $gc ) ) {
				return $gc;
			}
			$balance = minn_admin_ywgc_parse_amount( $request->get_param( 'balance' ), true );
			if ( is_wp_error( $balance ) ) {
				return $balance;
			}
			$gc->update_balance( $balance );

			return rest_ensure_response( array(
				'message' => sprintf(
					/* translators: 1: gift card code; 2: formatted money amount. */
					__( '%1$s now holds %2$s.', 'minn-admin' ),
					$gc->gift_card_number,
					minn_admin_ywgc_money( $balance, $gc->currency )
				),
			) );
		},
	) );

	// Resend the gift card email. The mailer ladder lives in
	// minn_admin_ywgc_send_email(); what belongs here is refusing honestly,
	// because YITH's mailer returns without a word when it will not send.
	register_rest_route( 'minn-admin/v1', '/ywgc/gift-cards/(?P<id>\d+)/resend', array(
		'methods'             => 'POST',
		'permission_callback' => $permission,
		'callback'            => function ( $request ) {
			$id = (int) $request['id'];
			$gc = minn_admin_ywgc_load( $id );
			if ( is_wp_error( $gc ) ) {
				return $gc;
			}
			if ( ! $gc->recipient ) {
				return new WP_Error(
					'minn_ywgc_no_recipient',
					__( 'This gift card has no recipient address, so there is nothing to resend.', 'minn-admin' ),
					array( 'status' => 400 )
				);
			}
			// A physical card is a card YITH prints, not one it emails, and
			// its mailer bails on is_virtual() before doing anything. Without
			// this the request would answer "sent" and no mail would leave.
			if ( ! $gc->is_virtual() ) {
				return new WP_Error(
					'minn_ywgc_not_digital',
					__( 'This is a physical gift card, so YITH does not email it.', 'minn-admin' ),
					array( 'status' => 400 )
				);
			}

			minn_admin_ywgc_send_email( $id );

			return rest_ensure_response( array(
				'message' => sprintf(
					/* translators: %s: recipient email address. */
					__( 'Gift card sent to %s.', 'minn-admin' ),
					$gc->recipient
				),
			) );
		},
	) );

	// The status card: what a merchant wants above the list. Outstanding
	// balance is the number that matters, because it is money the store owes.
	register_rest_route( 'minn-admin/v1', '/ywgc/status', array(
		'methods'             => 'GET',
		'permission_callback' => $permission,
		'callback'            => function () {
			global $wpdb;

			// Expiration is meta, not a status: a publish card whose
			// _ywgc_expiration has passed cannot be spent, so it is not
			// money the store still owes.
			$now    = time();
			$active = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} e ON ( e.post_id = p.ID AND e.meta_key = '_ywgc_expiration' )
				 WHERE p.post_type = %s AND p.post_status = 'publish'
				   AND ( e.meta_value IS NULL OR e.meta_value + 0 = 0 OR e.meta_value + 0 > %d )",
				YWGC_CUSTOM_POST_TYPE_NAME,
				$now
			) );
			$outstanding = (float) $wpdb->get_var( $wpdb->prepare(
				"SELECT SUM( m.meta_value + 0 ) FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} m ON ( m.post_id = p.ID AND m.meta_key = '_ywgc_balance_total' )
				 LEFT JOIN {$wpdb->postmeta} e ON ( e.post_id = p.ID AND e.meta_key = '_ywgc_expiration' )
				 WHERE p.post_type = %s AND p.post_status = 'publish'
				   AND ( e.meta_value IS NULL OR e.meta_value + 0 = 0 OR e.meta_value + 0 > %d )",
				YWGC_CUSTOM_POST_TYPE_NAME,
				$now
			) );
			$expiring = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} m ON ( m.post_id = p.ID AND m.meta_key = '_ywgc_expiration' )
				 WHERE p.post_type = %s AND p.post_status = 'publish'
				   AND m.meta_value + 0 > %d AND m.meta_value + 0 <= %d",
				YWGC_CUSTOM_POST_TYPE_NAME,
				time(),
				time() + 30 * DAY_IN_SECONDS
			) );

			$rows = array(
				array(
					'label' => __( 'Outstanding balance', 'minn-admin' ),
					'value' => minn_admin_ywgc_money( $outstanding ),
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
						'label' => __( 'Open YITH Gift Cards ↗', 'minn-admin' ),
						'href'  => admin_url( 'edit.php?post_type=' . YWGC_CUSTOM_POST_TYPE_NAME ),
					),
				),
			) );
		},
	) );
} );

/**
 * Load one gift card as YITH's own object, or a 404.
 *
 * @param int $id Gift card post id.
 * @return YITH_YWGC_Gift_Card|WP_Error
 */
function minn_admin_ywgc_load( $id ) {
	$post = get_post( $id );
	if ( ! $post || YWGC_CUSTOM_POST_TYPE_NAME !== $post->post_type ) {
		return new WP_Error( 'minn_ywgc_not_found', __( 'Gift card not found.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	return new YITH_YWGC_Gift_Card( array( 'ID' => (int) $id ) );
}
