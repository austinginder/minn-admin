<?php
/**
 * Bundled adapter: YITH WooCommerce Gift Cards (free and premium).
 *
 * YITH has no REST API at all: its gift cards are a `gift_card` CPT registered
 * with show_in_rest => false, it registers no routes of its own, and it keeps no
 * custom tables (everything is posts + postmeta). So the kernel reads the posts
 * directly and every write goes through YITH's OWN object API
 * (YITH_YWGC_Gift_Card), never through its admin screen. Its merchant verbs
 * live as query-arg handlers on that screen (?enable-gift-card&id=…, which
 * wp_safe_redirect()s), and those are not callable from a REST request.
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

/** YITH's post type, which its constant names once YITH loads. */
function minn_admin_ywgc_type() {
	return defined( 'YWGC_CUSTOM_POST_TYPE_NAME' ) ? (string) YWGC_CUSTOM_POST_TYPE_NAME : 'gift_card';
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
 * Read one gift card's row shape straight from post + meta.
 *
 * Deliberately NOT through YITH_YWGC_Gift_Card: its constructor MIGRATES a
 * legacy card as a side effect of loading it (when _ywgc_amount_total is empty
 * it calls update_amount(), which writes meta). That is fine for a detail view
 * or a verb, and wrong for a list, where it would turn painting page one into
 * twenty-five writes. The object is used everywhere a single card is touched.
 *
 * @param array $post The gift card's post row (ID, post_title, post_status, post_date_gmt).
 * @return array
 */
function minn_admin_ywgc_row( array $post ) {
	$id         = (int) $post['ID'];
	$amount     = (float) get_post_meta( $id, '_ywgc_amount_total', true );
	$balance    = (float) get_post_meta( $id, '_ywgc_balance_total', true );
	$currency   = (string) get_post_meta( $id, '_ywgc_currency', true );
	$expiration = (int) get_post_meta( $id, '_ywgc_expiration', true );
	$order_id   = (int) get_post_meta( $id, '_ywgc_order_id', true );

	$status = array_search( $post['post_status'], minn_admin_ywgc_statuses(), true );
	$status = $status ? $status : $post['post_status'];

	// Expiration is meta, not a post status: an expired card is still
	// 'publish' in the database. A merchant scanning the list needs to see
	// that it can no longer be spent, so the pill says so.
	$expired = $expiration > 0 && $expiration < time();
	if ( 'active' === $status && $expired ) {
		$status = 'expired';
	}

	return array(
		'id'        => $id,
		'code'      => (string) $post['post_title'],
		'amount'    => minn_admin_gift_money( $amount, $currency ),
		'balance'   => minn_admin_gift_money( $balance, $currency ),
		'status'    => $status,
		// The action gates read this rather than `status`, so an expired card
		// can still be disabled and a disabled one re-enabled.
		'enabled'   => 'publish' === $post['post_status'],
		'recipient' => (string) get_post_meta( $id, '_ywgc_recipient', true ),
		'order'     => $order_id ? '#' . $order_id : '',
		// The href action fills {order_id} from here, and `has_order` gates it:
		// a card issued by hand has no order to open.
		'order_id'  => $order_id,
		'has_order' => $order_id > 0,
		'created'   => (string) $post['post_date_gmt'],
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
	return Minn_Admin_Kernel_SQL::count( array( 'core' => 'posts' ), array( 'post_type' => minn_admin_ywgc_type(), 'post_title' => (string) $code ) ) > 0;
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

/**
 * Load one gift card as YITH's own object. The kernel has already read its
 * post under the source's conditions, so this is the object for a verb.
 *
 * @param int $id Gift card post id.
 * @return YITH_YWGC_Gift_Card
 */
function minn_admin_ywgc_load( $id ) {
	return new YITH_YWGC_Gift_Card( array( 'ID' => (int) $id ) );
}

minn_admin_add_surface( 'yith-gift-cards', 'minn_admin_ywgc_active', function () {
	// Issuing a card by hand is a real counter transaction (a refund made
	// good, a comp, a promotion), and both editions offer it, unless the
	// store turned hand-issued cards off in YITH.
	return minn_admin_gift_surface( 'ywgc/gift-cards', array(
		'sub'        => 'YITH',
		'plugin'     => array( 'yith-woocommerce-gift-cards', 'yith-woocommerce-gift-cards-premium' ),
		// YITH's plugin framework draws every YITH panel, from whichever
		// plugin carries its newest copy, so the panel names this surface's
		// page rather than the plugin folder.
		'adminPages' => array( 'yith_woocommerce_gift_cards_panel' ),
		'cap'        => 'manage_woocommerce',
		'status'     => 'ywgc/status',
		'statuses'   => array(
			'active'         => __( 'Active', 'minn-admin' ),
			'disabled'       => __( 'Disabled', 'minn-admin' ),
			'dismissed'      => __( 'Dismissed', 'minn-admin' ),
			'pre-printed'    => __( 'Pre-printed', 'minn-admin' ),
			'code-not-valid' => __( 'Code not valid', 'minn-admin' ),
		),
		'search'     => __( 'Search gift cards (code, recipient…)', 'minn-admin' ),
		'create'     => minn_admin_ywgc_can_create() ? minn_admin_gift_create_fields( array( 'recipient' => 'optional', 'name' => true, 'from' => true, 'message' => true, 'send' => true ) ) : null,
	) );
} );

minn_admin_resource( 'ywgc/gift-cards', function () {
	$states = array();
	foreach ( minn_admin_ywgc_statuses() as $slug => $status ) {
		$states[ $slug ] = array( 'post_status' => $status );
	}
	return array(
		'active'  => 'minn_admin_ywgc_active',
		'gates'   => array( 'view' => 'minn_admin_ywgc_can', 'manage' => 'minn_admin_ywgc_can', 'create' => 'minn_admin_ywgc_can_create' ),
		'labels'  => array( 'not_found' => __( 'Gift card not found.', 'minn-admin' ) ),
		'source'  => array(
			'table'   => 'posts',
			'key'     => 'ID',
			'columns' => array( 'ID', 'post_title', 'post_status', 'post_date_gmt' ),
			// Every status YITH gives a card, and no other: a trashed or
			// auto-draft gift_card post is no card.
			'where'   => array( 'post_type' => minn_admin_ywgc_type(), 'post_status' => array_values( minn_admin_ywgc_statuses() ) ),
		),
		'list'    => array(
			'filter' => array( 'param' => 'status', 'many' => true, 'values' => $states ),
			// A merchant searches with whatever the customer read them over the
			// phone: the code (the title) or the recipient (meta).
			'search' => array( 'param' => 'search', 'columns' => array( 'post_title' ), 'meta' => array( 'table' => 'postmeta', 'key' => 'post_id', 'name' => 'meta_key', 'value' => 'meta_value', 'only' => '_ywgc_recipient' ) ),
			'range'  => array( 'column' => 'post_date_gmt', 'clock' => 'utc' ),
			'order'  => array( 'column' => 'post_date', 'dir' => 'DESC' ),
			// The meta cache primed once, so the row builder costs no further queries.
			'batch'  => function ( array $rows ) {
				update_meta_cache( 'post', array_map( 'intval', array_column( $rows, 'ID' ) ) );
			},
			'row'    => 'minn_admin_ywgc_row',
			'fields' => minn_admin_gift_fields(),
		),
		'reads'   => array(
			'view' => array(
				// One card, so the object is safe (and right) to instantiate: its
				// constructor resolves the legacy-meta fallbacks YITH itself relies on.
				'view'   => function ( array $row ) {
					$id    = (int) $row['ID'];
					$gc    = minn_admin_ywgc_load( $id );
					$built = minn_admin_ywgc_row( $row );
					$card  = minn_admin_gift_card_rows( $gc->gift_card_number, 'code', $built['status'], minn_admin_gift_money( $gc->get_balance(), $gc->currency ), minn_admin_gift_money( $gc->total_amount, $gc->currency ) );
					if ( (int) $gc->expiration > 0 ) {
						// Stored as a unix timestamp, not a formatted date.
						$card[] = array( 'label' => __( 'Expires', 'minn-admin' ), 'value' => wp_date( get_option( 'date_format' ), (int) $gc->expiration ) );
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
						// The order number reads better than its address; the
						// "View order" action is the way into it.
						$origin[] = array( 'label' => __( 'Order', 'minn-admin' ), 'value' => '#' . (int) $gc->order_id );
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
					$origin[] = array( 'label' => __( 'Created', 'minn-admin' ), 'value' => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), get_post_timestamp( $id ) ) );
					$origin[] = array( 'label' => __( 'Delivery', 'minn-admin' ), 'value' => $gc->is_digital ? __( 'Digital', 'minn-admin' ) : __( 'Physical', 'minn-admin' ) );
					if ( $gc->internal_notes ) {
						$origin[] = array( 'label' => __( 'Internal notes', 'minn-admin' ), 'value' => $gc->internal_notes );
					}

					return array(
						// Names the modal, so a stack of cards does not read "Gift
						// cards" each; the Code row stays, monospace, to be read aloud.
						'title'    => $gc->gift_card_number,
						'sections' => minn_admin_gift_sections( $card, $recipient, $origin ),
						'adminUrl' => admin_url( 'post.php?post=' . $id . '&action=edit' ),
					);
				},
				'fields' => minn_admin_gift_view_fields(),
			),
		),
		'create'  => array(
			'gate' => 'create',
			'run'  => function ( WP_REST_Request $r ) {
				$amount = minn_admin_gift_amount( $r['amount'], false, 'minn_ywgc_amount' );
				if ( is_wp_error( $amount ) ) {
					return $amount;
				}
				$typed     = trim( (string) $r['recipient'] );
				$recipient = $typed ? sanitize_email( $typed ) : '';
				if ( $typed && ! $recipient ) {
					return new WP_Error( 'minn_ywgc_recipient', __( 'That recipient email address is not valid.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				// Everything that can be refused is refused BEFORE the card
				// exists. A card created and then not sent would leave the
				// merchant tidying up after an error message.
				$send = 'yes' === (string) $r['send'];
				if ( $send && ! $recipient ) {
					return new WP_Error( 'minn_ywgc_no_recipient', __( 'Add a recipient email address, or choose not to send the email.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				$typed_code = trim( (string) $r['code'] );
				if ( '' !== $typed_code ) {
					$code = sanitize_text_field( $typed_code );
					// A typed code that is not the same after stripping tags or
					// collapsing whitespace is not a code: refuse rather than
					// silently generate a different one.
					if ( '' === $code || wp_strip_all_tags( $typed_code ) !== $code || strlen( $code ) > 191 ) {
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
				$gc->recipient_name = sanitize_text_field( (string) $r['recipient_name'] );
				$gc->sender_name    = sanitize_text_field( (string) $r['sender_name'] );
				$gc->message        = sanitize_textarea_field( (string) $r['message'] );
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
				// A card that came out holding the wrong amount is not issued,
				// and is never emailed.
				if ( ! minn_admin_gift_same( get_post_meta( $gc->ID, '_ywgc_balance_total', true ), $amount ) ) {
					return Minn_Admin_Kernel::fail( 'not_applied', __( 'The plugin reported success, but the change is not there.', 'minn-admin' ), 500 );
				}
				if ( $send ) {
					minn_admin_ywgc_send_email( $gc->ID );
				}
				return array( 'id' => (int) $gc->ID, 'code' => $code, 'amount' => $amount, 'sent' => $send ? $recipient : '' );
			},
			'reply' => function ( $id, $fresh, $ran ) {
				return minn_admin_gift_created( $ran['code'], $ran['sent'], minn_admin_gift_money( $ran['amount'] ) );
			},
		),
		'actions' => array(
			// Enable / disable, through YITH's own setter (which writes the post
			// status its list, its shortcodes and its checkout all read).
			'status'  => array(
				'gate'   => 'manage',
				'args'   => minn_admin_gift_switch_args(),
				// YITH's setter leaves a dismissed card as it is, without a word.
				'when'   => function ( array $row ) {
					return 'ywgc-dismissed' !== $row['post_status'];
				},
				'code'   => 'minn_ywgc_dismissed',
				'refuse' => __( 'YITH keeps a dismissed gift card dismissed, so it cannot be switched on or off.', 'minn-admin' ),
				'run'    => function ( $id, $row, WP_REST_Request $r ) {
					$gc = minn_admin_ywgc_load( $id );
					$on = (bool) $r['enabled'];
					$gc->set_enabled_status( $on );
					return array( 'enabled' => $on, 'code' => (string) $gc->gift_card_number );
				},
				'expect' => function ( $id, $fresh, $ran ) {
					return is_array( $fresh ) && ( 'publish' === $fresh['post_status'] ) === $ran['enabled'];
				},
				'reply'  => function ( $id, $fresh, $ran ) {
					return minn_admin_gift_switched( $ran['code'], $ran['enabled'] );
				},
			),
			// Topping a card up without creating an order is a real counter
			// transaction, and YITH's own screen allows it too.
			'balance' => array(
				'gate'   => 'manage',
				'run'    => function ( $id, $row, WP_REST_Request $r ) {
					$gc     = minn_admin_ywgc_load( $id );
					$now    = (float) $gc->get_balance();
					$target = minn_admin_gift_balance_target( $r, $now, 'minn_ywgc_amount', minn_admin_gift_money( $now, (string) $gc->currency ) );
					if ( is_wp_error( $target ) ) {
						return $target;
					}
					$gc->update_balance( $target['balance'] );
					return array( 'balance' => $target['balance'], 'code' => (string) $gc->gift_card_number, 'currency' => (string) $gc->currency );
				},
				'expect' => function ( $id, $fresh, $ran ) {
					return minn_admin_gift_same( get_post_meta( $id, '_ywgc_balance_total', true ), $ran['balance'] );
				},
				'reply'  => function ( $id, $fresh, $ran ) {
					return minn_admin_gift_holds( $ran['code'], minn_admin_gift_money( $ran['balance'], $ran['currency'] ) );
				},
			),
			// The mailer ladder lives in minn_admin_ywgc_send_email(); what
			// belongs here is refusing honestly, because YITH's mailer returns
			// without a word when it will not send.
			'resend'  => array(
				'gate'   => 'manage',
				'expect' => 'ran',
				'run'    => function ( $id ) {
					$gc = minn_admin_ywgc_load( $id );
					if ( ! $gc->recipient ) {
						return minn_admin_gift_no_recipient( 'minn_ywgc_no_recipient' );
					}
					// A physical card is a card YITH prints, not one it emails, and
					// its mailer bails on is_virtual() before doing anything.
					if ( ! $gc->is_virtual() ) {
						return new WP_Error( 'minn_ywgc_not_digital', __( 'This is a physical gift card, so YITH does not email it.', 'minn-admin' ), array( 'status' => 400 ) );
					}
					minn_admin_ywgc_send_email( $id );
					return (string) $gc->recipient;
				},
				'reply'  => function ( $id, $fresh, $to ) {
					return minn_admin_gift_sent( $to );
				},
			),
		),
	);
} );

// The status card: what a merchant wants above the list. Outstanding balance
// is the number that matters, because it is money the store owes.
minn_admin_endpoint( 'ywgc/status', array(
	'active' => 'minn_admin_ywgc_active',
	'gate'   => 'minn_admin_ywgc_can',
	'run'    => function () {
		$now   = time();
		$posts = array( 'core' => 'posts' );
		$cards = array( 'post_type' => minn_admin_ywgc_type(), 'post_status' => 'publish' );
		$x     = array( 'x' => array( 'core' => 'postmeta', 'on' => array( 'post_id' => 'e.ID' ) ) );
		$b     = array( 'b' => array( 'core' => 'postmeta', 'on' => array( 'post_id' => 'e.ID' ) ) );
		$owed  = $cards + array( 'b.meta_key' => '_ywgc_balance_total' );
		// Expiration is meta, not a status: a published card whose
		// _ywgc_expiration has passed cannot be spent, so it is not money the
		// store still owes. A card with no expiry never lapses, so what has
		// lapsed is counted on its own and taken away.
		$lapsed = array( 'x.meta_key' => '_ywgc_expiration', 'x.meta_value' => array( 'op' => '!=', 'value' => 0 ), "x.meta_value\0to" => array( 'op' => '<=', 'value' => $now ) );
		$d      = minn_admin_gift_decimals();
		return minn_admin_gift_status(
			Minn_Admin_Kernel_SQL::sum( $posts + array( 'joins' => $b ), 'b.meta_value', $owed, $d ) - Minn_Admin_Kernel_SQL::sum( $posts + array( 'joins' => $b + $x ), 'b.meta_value', $owed + $lapsed, $d ),
			Minn_Admin_Kernel_SQL::count( $posts, $cards ) - Minn_Admin_Kernel_SQL::count( $posts + array( 'joins' => $x ), $cards + $lapsed ),
			Minn_Admin_Kernel_SQL::count( $posts + array( 'joins' => $x ), $cards + array( 'x.meta_key' => '_ywgc_expiration', 'x.meta_value' => array( 'op' => '>', 'value' => $now ), "x.meta_value\0to" => array( 'op' => '<=', 'value' => $now + 30 * DAY_IN_SECONDS ) ) ),
			__( 'Open YITH Gift Cards ↗', 'minn-admin' ),
			admin_url( 'edit.php?post_type=' . minn_admin_ywgc_type() )
		);
	},
) );
