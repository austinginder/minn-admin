<?php
/**
 * Mail logs: what the shop's mail plugins recorded over the last few weeks.
 * One catalog of the mail the shop really sends (WooCommerce order mail for
 * the seeded orders, stock alerts, contact form notifications and their
 * auto-replies, password resets, workshop reminders, Wordfence lockout
 * alerts), then each plugin keeps its own slice of it in its own log tables:
 * Gravity SMTP, FluentSMTP, WP Mail SMTP (debug events), Post SMTP, WP Mail
 * Logging, SureMails and Site Mailer. Nothing is sent: every row is written
 * straight into the plugin's storage.
 *
 * The same few failures run through every log: the host's mail() gave out
 * for an hour eleven days ago (Ethan's cancelled hibiscus order), and
 * Daniel's first password reset request failed before his second went out.
 * Each plugin adds its own: Gravity SMTP has suppressed addresses, a
 * partially sent order alert and two sandboxed test mails; FluentSMTP has a
 * failed mail Mateo resent; Site Mailer saw Nadia's auto-reply bounce off a
 * mistyped address.
 *
 * Storage facts this part relies on:
 * - Clocks differ per plugin. UTC: Gravity SMTP (current_time mysql gmt),
 *   WP Mail SMTP created_at. Site-local datetime: FluentSMTP, WP Mail
 *   Logging, SureMails and Site Mailer (all current_time( 'mysql' )). Post
 *   SMTP `time` is a site-local epoch (current_time( 'timestamp' )).
 * - Gravity SMTP keeps recipients and headers in a serialized `extra`; a
 *   plain `to` string is a shape their own Event_Model writes. Its PHP Mail
 *   connector logs service 'wp_mail'. event_logs holds the per-mail trail.
 * - FluentSMTP `to`, `headers`, `response` and `extra` are serialized
 *   arrays; a resend flips status to sent, bumps resent_count and appends to
 *   extra['resends']. It only logs once a connection exists, so this part
 *   saves one (PHP mail(), no credentials) through its own Settings model.
 * - SureMails serializes email_to, headers and response, but `meta` is
 *   JSON under a json_valid() CHECK: a serialized meta is refused outright.
 * - Site Mailer stores `to` and `headers` JSON-encoded, and a per-recipient
 *   row in site_mail_statuses keyed by the log's api_id. It deletes rows
 *   older than 30 days, SureMails too, and FluentSMTP after its
 *   log_saved_interval_days (14 by default; set to 30 here).
 *
 * Mail plugins log the seed run itself (Sucuri and WooCommerce stock alerts
 * to the install's admin address), so every table here starts empty. Parts
 * that run after this one in the same process can still send: a shutdown
 * callback drops any row added to these tables after this part finished.
 *
 * @package minn-admin
 */

return function ( Minn_Demo_Seed $demo ) {
	global $wpdb;

	$p      = $wpdb->prefix;
	$tables = array(
		'gsmtp'        => $p . 'gravitysmtp_events',
		'gsmtp_logs'   => $p . 'gravitysmtp_event_logs',
		'gsmtp_debug'  => $p . 'gravitysmtp_debug_log',
		'gsmtp_supp'   => $p . 'gravitysmtp_suppressed_emails',
		'fluent'       => $p . 'fsmpt_email_logs',
		'wpms'         => $p . 'wpmailsmtp_debug_events',
		'postsmtp'     => $p . 'post_smtp_logs',
		'postsmtp_meta' => $p . 'post_smtp_logmeta',
		'wpml'         => $p . 'wpml_mails',
		'suremails'    => $p . 'suremails_email_log',
		'sitemailer'   => $p . 'site_mail_logs',
		'sitemailer_st' => $p . 'site_mail_statuses',
	);
	foreach ( $tables as $key => $table ) {
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			unset( $tables[ $key ] );
			continue;
		}
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	$has = function ( $key ) use ( $tables ) {
		return isset( $tables[ $key ] );
	};

	$from_name  = 'Larkspur & Fern';
	$from_email = 'hello@larkspur.example';
	$from       = $from_name . ' <' . $from_email . '>';
	$site       = 'https://larkspur.example';

	// Harmless sending setups, so each plugin's own screens read as configured:
	// PHP mail everywhere, no hosts, no credentials.
	$wpms = (array) get_option( 'wp_mail_smtp', array() );
	$wpms['mail'] = array_merge(
		isset( $wpms['mail'] ) ? (array) $wpms['mail'] : array(),
		array(
			'from_email' => $from_email,
			'from_name'  => $from_name,
			'mailer'     => 'mail',
		)
	);
	$wpms['debug_events'] = array_merge( isset( $wpms['debug_events'] ) ? (array) $wpms['debug_events'] : array(), array( 'email_debug' => true ) );
	update_option( 'wp_mail_smtp', $wpms );

	if ( class_exists( 'FluentMail\App\Models\Settings' ) ) {
		( new FluentMail\App\Models\Settings() )->store(
			array(
				'connection_key' => '',
				'valid_senders'  => array(),
				'connection'     => array(
					'provider'         => 'default',
					'sender_name'      => $from_name,
					'sender_email'     => $from_email,
					'force_from_name'  => 'yes',
					'force_from_email' => 'yes',
					'return_path'      => 'yes',
					'key_store'        => 'db',
				),
			)
		);
		$fluent = get_option( 'fluentmail-settings', array() );
		if ( is_array( $fluent ) ) {
			$fluent['misc']['log_emails']              = 'yes';
			$fluent['misc']['log_saved_interval_days'] = '30';
			update_option( 'fluentmail-settings', $fluent );
		}
		if ( class_exists( 'FluentMail\App\Services\ConnectionHealth' ) ) {
			( new FluentMail\App\Services\ConnectionHealth() )->checkAll();
		}
	}

	if ( class_exists( 'Gravity_Forms\Gravity_SMTP\Data_Store\Opts_Data_Store' ) ) {
		$opts = new Gravity_Forms\Gravity_SMTP\Data_Store\Opts_Data_Store();
		$opts->save_all(
			array(
				'activated'        => true,
				'configured'       => true,
				'enabled'          => true,
				'is_primary'       => true,
				'from_email'       => $from_email,
				'from_name'        => $from_name,
				'force_from_email' => true,
				'force_from_name'  => false,
			),
			'phpmail'
		);
		$plugin_opts = new Gravity_Forms\Gravity_SMTP\Data_Store\Plugin_Opts_Data_Store();
		$plugin_opts->save( 'enabled_connector', array( 'phpmail' => true ) );
		$plugin_opts->save( 'primary_connector', array( 'phpmail' => true ) );
		$plugin_opts->save( 'event_log_enabled', true );
		$plugin_opts->save( 'save_email_body_enabled', true );
		delete_transient( 'gsmtp_connector_configured_phpmail' );
		delete_transient( 'minn_admin_gsmtp_log_sources' );
	}

	$postman = (array) get_option( 'postman_options', array() );
	update_option( 'postman_options', array_merge( $postman, array( 'transport_type' => 'default', 'sender_email' => $from_email, 'sender_name' => $from_name ) ) );
	update_option( 'site_mailer_keep_log', true );

	// The catalog. Each mail: when, to whom, what, which plugin sent it and
	// from which line (WP Mail SMTP names the caller), what kind it is.
	$mails = array();
	$add   = function ( array $m ) use ( &$mails, $demo ) {
		if ( $m['ts'] > $demo->now - 120 ) {
			return; // Not sent yet when the demo is recorded.
		}
		$mails[] = $m + array(
			'html'     => true,
			'source'   => 'WooCommerce',
			'file'     => 'wp-content/plugins/woocommerce/includes/emails/class-wc-email.php',
			'line'     => 1262,
			'reply_to' => '',
			'attach'   => array(),
			'fail'     => '',
		);
	};
	$money = function ( $v ) {
		return '$' . number_format( (float) $v, 2 );
	};
	$wrap = function ( $heading, $inner ) use ( $site ) {
		return '<div style="background:#f4f1ea;padding:24px 0;font-family:Helvetica,Arial,sans-serif;">'
			. '<table role="presentation" width="560" align="center" cellpadding="0" cellspacing="0" style="background:#ffffff;border:1px solid #e3ddd0;border-radius:6px;">'
			. '<tr><td style="background:#4f6b4a;color:#ffffff;padding:22px 32px;font-size:22px;font-weight:600;">' . esc_html( $heading ) . '</td></tr>'
			. '<tr><td style="padding:24px 32px;color:#3b3b3b;font-size:14px;line-height:1.6;">' . $inner . '</td></tr>'
			. '<tr><td style="padding:14px 32px 22px;color:#8a8577;font-size:12px;text-align:center;">Larkspur &amp; Fern, 112 Station Road, Portland OR 97214<br><a href="' . esc_url( $site ) . '" style="color:#4f6b4a;">larkspur.example</a></td></tr>'
			. '</table></div>';
	};
	$cell = 'style="padding:6px 8px;border-bottom:1px solid #eee7da;text-align:left;"';

	// Orders, read back from WooCommerce so every amount matches.
	$orders = array();
	if ( function_exists( 'wc_get_order' ) ) {
		foreach ( $demo->ids( 'orders' ) as $number => $id ) {
			$o = wc_get_order( $id );
			if ( ! $o ) {
				continue;
			}
			$items = array();
			foreach ( $o->get_items() as $item ) {
				$items[] = array( $item->get_name(), $item->get_quantity(), $item->get_subtotal() );
			}
			$created = $o->get_date_created() ? $o->get_date_created()->getTimestamp() : 0;
			// WooCommerce stamps date_completed with the seed run itself (the
			// status change), so completion is the day after, as the shop part means it.
			$completed = $created && in_array( $o->get_status(), array( 'completed', 'refunded' ), true ) ? $created + DAY_IN_SECONDS + 3 * HOUR_IN_SECONDS : 0;
			$user      = $o->get_customer_id() ? get_userdata( $o->get_customer_id() ) : null;
			$refunds   = $o->get_refunds();
			$orders[ (int) $number ] = array(
				'number'    => (int) $number,
				'status'    => $o->get_status(),
				'created'   => $created,
				'completed' => $completed,
				'first'     => $o->get_billing_first_name(),
				'name'      => trim( $o->get_billing_first_name() . ' ' . $o->get_billing_last_name() ),
				'email'     => $o->get_billing_email(),
				'phone'     => $o->get_billing_phone(),
				'login'     => $user ? $user->user_login : '',
				'items'     => $items,
				'subtotal'  => $o->get_subtotal(),
				'discount'  => $o->get_discount_total(),
				'coupons'   => $o->get_coupon_codes(),
				'shipping'  => $o->get_shipping_method(),
				'ship_cost' => $o->get_shipping_total(),
				'payment'   => $o->get_payment_method_title(),
				'pay_id'    => $o->get_payment_method(),
				'total'     => $o->get_total(),
				'note'      => $o->get_customer_note(),
				'refund'    => $refunds ? array( $refunds[0]->get_amount(), $refunds[0]->get_reason() ) : null,
				'pickup'    => false !== strpos( $o->get_shipping_method(), 'pickup' ),
				'virtual'   => '' === $o->get_shipping_method(),
			);
		}
	}
	$table = function ( $o ) use ( $money, $cell ) {
		$rows = '';
		foreach ( $o['items'] as $i ) {
			$rows .= '<tr><td ' . $cell . '>' . esc_html( $i[0] ) . '</td><td ' . $cell . '>' . (int) $i[1] . '</td><td ' . $cell . '>' . $money( $i[2] ) . '</td></tr>';
		}
		$totals = array( array( 'Subtotal:', $money( $o['subtotal'] ) ) );
		if ( (float) $o['discount'] > 0 ) {
			$totals[] = array( 'Discount:', '-' . $money( $o['discount'] ) . ( $o['coupons'] ? ' (' . strtoupper( implode( ', ', $o['coupons'] ) ) . ')' : '' ) );
		}
		if ( '' !== $o['shipping'] ) {
			$totals[] = array( 'Shipping:', (float) $o['ship_cost'] > 0 ? $money( $o['ship_cost'] ) . ' via ' . $o['shipping'] : $o['shipping'] );
		}
		$totals[] = array( 'Payment method:', $o['payment'] );
		$totals[] = array( 'Total:', $money( $o['total'] ) );
		foreach ( $totals as $t ) {
			$rows .= '<tr><th colspan="2" ' . $cell . '>' . esc_html( $t[0] ) . '</th><td ' . $cell . '>' . esc_html( $t[1] ) . '</td></tr>';
		}
		$out = '<h2 style="color:#4f6b4a;font-size:16px;margin:20px 0 8px;">[Order #' . $o['number'] . '] (' . esc_html( wp_date( 'F j, Y', $o['created'] ) ) . ')</h2>'
			. '<table width="100%" cellspacing="0" cellpadding="0" style="border-collapse:collapse;font-size:13px;"><tr><th ' . $cell . '>Product</th><th ' . $cell . '>Quantity</th><th ' . $cell . '>Price</th></tr>' . $rows . '</table>';
		if ( '' !== $o['note'] ) {
			$out .= '<p style="margin-top:14px;"><strong>Note:</strong> ' . esc_html( $o['note'] ) . '</p>';
		}
		return $out;
	};

	$staff_alerts = array( 'june@larkspur.example', 'mateo@larkspur.example' );
	foreach ( $orders as $n => $o ) {
		if ( ! $o['created'] || 'pending' === $o['status'] ) {
			continue;
		}
		$hi   = '<p>Hi ' . esc_html( $o['first'] ) . ',</p>';
		$paid = $o['created'] + 60;
		$new  = function ( $ts ) use ( $add, $o, $n, $wrap, $table, $staff_alerts ) {
			$add(
				array(
					'key'     => "new-{$n}",
					'kind'    => 'new_order',
					'ts'      => $ts,
					'to'      => $staff_alerts,
					'subject' => "[Larkspur & Fern]: New order #{$n}",
					'body'    => $wrap( "New Order: #{$n}", '<p>You\'ve received the following order from ' . esc_html( $o['name'] ) . ':</p>' . $table( $o ) . '<p>Customer: ' . esc_html( $o['email'] ) . ', ' . esc_html( $o['phone'] ) . '</p>' ),
				)
			);
		};
		if ( 'failed' === $o['status'] ) {
			$add(
				array(
					'key'     => "failed-{$n}",
					'kind'    => 'failed',
					'ts'      => $o['created'] + 95,
					'to'      => array( 'june@larkspur.example' ),
					'subject' => "[Larkspur & Fern]: Order #{$n} has failed",
					'body'    => $wrap( "Order Failed: #{$n}", '<p>Payment for order #' . $n . ' from ' . esc_html( $o['name'] ) . ' has failed. The order was as follows:</p>' . $table( $o ) ),
				)
			);
			continue;
		}
		$new( $paid + 20 );
		if ( 'on-hold' === $o['status'] ) {
			$add(
				array(
					'key'     => "onhold-{$n}",
					'kind'    => 'on_hold',
					'ts'      => $paid + 26,
					'to'      => array( $o['email'] ),
					'subject' => 'Your Larkspur & Fern order has been received!',
					'body'    => $wrap( 'Thank you for your order', $hi . '<p>Thanks for your order. It\'s on hold until we confirm that payment has been received. Please use your order number as the payment reference.</p>' . $table( $o ) . '<p>We look forward to fulfilling your order soon.</p>' ),
					'attach'  => 'samuel' === sanitize_key( $o['first'] ) ? array( "invoice-{$n}.pdf" ) : array(),
				)
			);
		} else {
			$outro = $o['virtual'] ? 'Your place is booked. Priya will send a reminder the week of the workshop.' : ( $o['pickup'] ? 'We will email you when it is ready to collect at 112 Station Road.' : 'We pack orders Monday to Wednesday and will email you when yours is on its way.' );
			$add(
				array(
					'key'     => "processing-{$n}",
					'kind'    => 'processing',
					'ts'      => $paid + 27,
					'to'      => array( $o['email'] ),
					'subject' => 'Your Larkspur & Fern order has been received!',
					'body'    => $wrap( 'Thank you for your order', $hi . '<p>Just to let you know, we\'ve received your order #' . $n . ', and it is now being processed.</p>' . $table( $o ) . '<p>' . $outro . '</p>' ),
				)
			);
		}
		if ( $o['completed'] && in_array( $o['status'], array( 'completed', 'refunded' ), true ) ) {
			$outro = $o['virtual'] ? 'See you at the workshop!' : ( $o['pickup'] ? 'Thanks for collecting your order. Enjoy your new plant!' : 'Your plants are on their way. Unpack them the day they arrive and give them a day out of direct sun to settle in.' );
			$add(
				array(
					'key'     => "completed-{$n}",
					'kind'    => 'completed',
					'ts'      => $o['completed'],
					'to'      => array( $o['email'] ),
					'subject' => 'Your Larkspur & Fern order is now complete',
					'body'    => $wrap( 'Thanks for shopping with us', $hi . '<p>We have finished processing your order.</p>' . $table( $o ) . '<p>' . $outro . '</p>' ),
				)
			);
		}
		if ( 'refunded' === $o['status'] && $o['refund'] ) {
			$add(
				array(
					'key'     => "refunded-{$n}",
					'kind'    => 'refunded',
					'ts'      => $o['completed'] + 26 * HOUR_IN_SECONDS + 1260,
					'to'      => array( $o['email'] ),
					'subject' => "Your Larkspur & Fern order #{$n} has been refunded",
					'body'    => $wrap( "Order Refunded: {$n}", $hi . '<p>Your order on Larkspur &amp; Fern has been refunded. There are more details below for your reference:</p>' . $table( $o ) . '<p><strong>Refund:</strong> ' . $money( $o['refund'][0] ) . ', ' . esc_html( lcfirst( rtrim( $o['refund'][1], '.' ) ) ) . '.</p><p>We\'re sorry it didn\'t arrive in better shape, and thank you for letting us know.</p>' ),
				)
			);
		}
		if ( 'cancelled' === $o['status'] ) {
			$add(
				array(
					'key'     => "cancelled-{$n}",
					'kind'    => 'cancelled',
					'ts'      => $o['created'] + 3 * HOUR_IN_SECONDS + 400,
					'to'      => array( 'june@larkspur.example' ),
					'subject' => "[Larkspur & Fern]: Order #{$n} has been cancelled",
					'body'    => $wrap( "Order Cancelled: #{$n}", '<p>Notification to let you know: order #' . $n . ' belonging to ' . esc_html( $o['name'] ) . ' has been cancelled:</p>' . $table( $o ) ),
				)
			);
		}
		if ( 'bacs' === $o['pay_id'] && '' !== $o['note'] && false !== strpos( $o['note'], 'Ortiz' ) ) {
			$add(
				array(
					'key'     => "note-{$n}",
					'kind'    => 'note',
					'ts'      => $o['created'] + 2 * HOUR_IN_SECONDS + 600,
					'to'      => array( $o['email'] ),
					'subject' => 'Note added to your Larkspur & Fern order from ' . wp_date( 'F j, Y', $o['created'] ),
					'body'    => $wrap( 'A note has been added to your order', $hi . '<p>The following note has been added to your order:</p><blockquote style="border-left:3px solid #c9d6c3;margin:0;padding:4px 12px;">Thanks Samuel! We will have these ready for pickup on Thursday.</blockquote><p>As a reminder, here are your order details:</p>' . $table( $o ) ),
				)
			);
		}
	}

	// The mail() outage: Ethan's hibiscus order went through just as the host's
	// mail function stopped answering.
	$outage = 'Could not instantiate mail function.';
	foreach ( $mails as $i => $m ) {
		if ( in_array( $m['key'], array( 'new-1057', 'processing-1057' ), true ) ) {
			$mails[ $i ]['fail'] = $outage;
		}
	}

	// Stock alerts, at the orders that caused them.
	$stock = array(
		// key, order number, kind, product line, wp_mail line in class-wc-emails.php
		array( 'low-anthurium', 1069, 'low_stock', "Anthurium 'Red Heart' (LF-HP-001) is low in stock. There are 3 left.", 1044 ),
		array( 'low-phacelia', 1066, 'low_stock', 'Botanical Print: Phacelia (LF-PT-004) is low in stock. There are 4 left.', 1044 ),
		array( 'out-bellflower', 1066, 'no_stock', 'Botanical Print: Bellflower - 16 x 20 (LF-PT-001-16X20) is out of stock.', 1131 ),
		array( 'low-saguaro', 1065, 'low_stock', 'Golden Saguaro, 6 inch (LF-CS-001) is low in stock. There are 1 left.', 1044 ),
		array( 'out-hibiscus', 1057, 'no_stock', 'Red Hibiscus (LF-OP-001) is out of stock.', 1131 ),
		array( 'backorder-thistle', 1053, 'backorder', '1 units of Botanical Print: Thistle (LF-PT-003) have been backordered in order #1053.', 1232 ),
		array( 'out-kids', 1052, 'no_stock', 'Kids Sunflower Morning (LF-WS-002) is out of stock.', 1131 ),
	);
	$titles = array(
		'low_stock' => '[Larkspur & Fern] Product low in stock',
		'no_stock'  => '[Larkspur & Fern] Product out of stock',
		'backorder' => '[Larkspur & Fern] Product backorder',
	);
	foreach ( $stock as $s ) {
		if ( empty( $orders[ $s[1] ]['created'] ) ) {
			continue;
		}
		$add(
			array(
				'key'     => $s[0],
				'kind'    => $s[2],
				// Hibiscus ran out on an earlier order; Ethan's was cancelled for it.
				'ts'      => 'out-hibiscus' === $s[0] ? $orders[ $s[1] ]['created'] - 19 * HOUR_IN_SECONDS : $orders[ $s[1] ]['created'] + 75,
				'to'      => array( 'june@larkspur.example' ),
				'subject' => $titles[ $s[2] ],
				'body'    => $s[3],
				'html'    => false,
				'file'    => 'wp-content/plugins/woocommerce/includes/class-wc-emails.php',
				'line'    => $s[4],
			)
		);
	}

	// Contact form notifications and the auto-replies that go back.
	// slug, days ago, hour, minute, form plugin, subject, message
	$forms = array(
		array( 'nadia', 12.3, 16, 40, 'Contact Form 7', 'Shipping a peace lily to Eugene', 'Do you ship live plants to Eugene this time of year, and how are they packed? I would hate for it to arrive cold.' ),
		array( 'nadia', 11.2, 9, 5, 'Contact Form 7', 'Did my message come through?', 'I wrote yesterday about shipping a peace lily to Eugene but did not get the usual confirmation. Just checking it reached you.' ),
		array( 'owen', 7.2, 11, 48, 'Contact Form 7', 'Plants for our waiting room', 'Our waiting room gets bright light in the morning and almost none after noon. Could you suggest four or five low maintenance plants, and do you offer a care visit?' ),
		array( 'samuel', 6.3, 9, 12, 'Gravity Forms', 'Wholesale account for Ortiz Florals', 'We run a small florist on NE Alberta and would like to order peace lilies and anthuriums every month, around ten of each. Do you offer wholesale pricing and invoicing on 30 day terms?' ),
		array( 'ruth', 1.6, 14, 22, 'Contact Form 7', 'Event greenery for a December gala', 'We are hosting a winter gala at Lovejoy Hall in December and would love potted greenery for 40 tables, plus a few larger plants for the entrance. Could you send a quote, and do you deliver to NW Lovejoy?' ),
	);
	$form_lines = array(
		'Contact Form 7' => array( 'wp-content/plugins/contact-form-7/includes/mail.php', 329 ),
		'Gravity Forms'  => array( 'wp-content/plugins/gravityforms/common.php', 2502 ),
		'WPForms'        => array( 'wp-content/plugins/wpforms/includes/emails/class-emails.php', 533 ),
	);
	if ( ! empty( $orders[1068]['created'] ) ) {
		$forms[] = array( 'kenji', ( $demo->now - $orders[1068]['created'] - 50 * MINUTE_IN_SECONDS ) / DAY_IN_SECONDS, null, null, 'WPForms', 'Terrarium workshop with my daughter', 'I just booked two places on the terrarium workshop. My daughter is nine. Is that old enough, and should we bring anything?' );
	}
	foreach ( $forms as $i => $f ) {
		$who  = $demo->person( $f[0] );
		$ts   = null === $f[2] ? $demo->now - (int) round( $f[1] * DAY_IN_SECONDS ) : $demo->ago( $f[1], $f[2], $f[3] );
		$line = $form_lines[ $f[4] ];
		$body = '<p><strong>Name:</strong> ' . esc_html( $who['name'] ) . '<br><strong>Email:</strong> ' . esc_html( $who['email'] ) . '<br><strong>Phone:</strong> ' . esc_html( $who['phone'] )
			. ( $who['company'] ? '<br><strong>Company:</strong> ' . esc_html( $who['company'] ) : '' ) . '</p>'
			. '<p><strong>Subject:</strong> ' . esc_html( $f[5] ) . '</p><p><strong>Message:</strong><br>' . esc_html( $f[6] ) . '</p>'
			. '<p style="color:#8a8577;font-size:12px;">Sent from the contact form on larkspur.example/contact/</p>';
		$add(
			array(
				'key'      => "form-{$i}",
				'kind'     => 'form',
				'ts'       => $ts,
				'to'       => array( $from_email ),
				'subject'  => 'Gravity Forms' === $f[4] ? 'New wholesale enquiry from ' . $who['company'] : 'Larkspur & Fern: ' . $f[5],
				'body'     => $body,
				'source'   => $f[4],
				'file'     => $line[0],
				'line'     => $line[1],
				'reply_to' => $who['email'],
			)
		);
		// Nadia typed her address wrong both times.
		$reply_to = 'nadia' === $f[0] ? 'nadia.petrov@example.org' : $who['email'];
		$add(
			array(
				'key'     => "autoreply-{$i}",
				'kind'    => 'autoreply',
				'ts'      => $ts + 2,
				'to'      => array( $reply_to ),
				'subject' => 'Thanks for writing to Larkspur & Fern',
				'body'    => $wrap( 'Thanks for getting in touch', '<p>Hi ' . esc_html( $who['first'] ) . ',</p><p>Thanks for your message about "' . esc_html( $f[5] ) . '". We read every message ourselves and usually reply within a day.</p><p>Theo, for everyone at Larkspur &amp; Fern</p>' ),
				'source'  => $f[4],
				'file'    => $line[0],
				'line'    => $line[1],
			)
		);
	}

	// Workshop reminders, sent by a snippet the evening before the week's workshop.
	$reminders = array(
		array( 'sofia', 1, 'Spring Terrarium Workshop', 'this Saturday at 10am', 'All materials are included, and we keep a few aprons by the door.' ),
		array( 'ben', 17, 'Kids Sunflower Morning', 'this Saturday at 9:30am', 'Little gardeners get muddy, so old clothes are best. Every child takes home a labeled pot.' ),
	);
	foreach ( $reminders as $r ) {
		$who = $demo->person( $r[0] );
		$add(
			array(
				'key'      => 'reminder-' . $r[0],
				'kind'     => 'reminder',
				'ts'       => $demo->ago( $r[1], 17, 30 ),
				'to'       => array( $who['email'] ),
				'subject'  => 'Reminder: ' . $r[2] . ' ' . $r[3],
				'body'     => $wrap( 'See you Saturday', '<p>Hi ' . esc_html( $who['first'] ) . ',</p><p>A quick reminder that your ' . esc_html( $r[2] ) . ' is ' . esc_html( $r[3] ) . ' at the shop, 112 Station Road. Doors open fifteen minutes early.</p><p>' . esc_html( $r[4] ) . '</p><p>Priya</p>' ),
				'source'   => 'Code Snippets',
				'file'     => 'wp-content/plugins/code-snippets/php/snippet-ops.php(917) : eval()\'d code',
				'line'     => 23,
				'reply_to' => 'priya@larkspur.example',
			)
		);
	}

	// Password resets: Theo from the login screen, Daniel from My Account
	// (his first request failed, the second went through).
	$reset_key = function ( $seed ) {
		return substr( strtr( base64_encode( md5( 'larkspur-' . $seed, true ) ), '+/=', 'xyz' ), 0, 20 );
	};
	$add(
		array(
			'key'     => 'reset-theo',
			'kind'    => 'reset',
			'ts'      => $demo->ago( 16, 7, 40 ),
			'to'      => array( 'theo@larkspur.example' ),
			'subject' => '[Larkspur & Fern] Password Reset',
			'body'    => "Someone has requested a password reset for the following account:\r\n\r\nSite Name: Larkspur & Fern\r\n\r\nUsername: theo\r\n\r\nIf this was a mistake, ignore this email and nothing will happen.\r\n\r\nTo reset your password, visit the following address:\r\n\r\n{$site}/wp-login.php?login=theo&key=" . $reset_key( 'theo' ) . "&action=rp\r\n\r\nThis password reset request originated from the IP address 192.0.2.117.\r\n",
			'html'    => false,
			'source'  => 'WordPress',
			'file'    => 'wp-includes/user.php',
			'line'    => 3488,
		)
	);
	$daniel = $demo->person( 'daniel' );
	foreach ( array( 'reset-daniel-1' => -6 * MINUTE_IN_SECONDS, 'reset-daniel' => 0 ) as $key => $shift ) {
		$add(
			array(
				'key'     => $key,
				'kind'    => 'wc_reset',
				'ts'      => $demo->ago( 5, 21, 15 ) + $shift,
				'to'      => array( $daniel['email'] ),
				'subject' => 'Password Reset Request for Larkspur & Fern',
				'body'    => $wrap( 'Password Reset Request', '<p>Hi Daniel,</p><p>Someone has requested a new password for the following account on Larkspur &amp; Fern:</p><p>Username: <strong>daniel</strong></p><p>If you didn\'t make this request, just ignore this email. If you\'d like to proceed:</p><p><a href="' . esc_url( $site . '/my-account/lost-password/?key=' . $reset_key( $key ) . '&id=' . $daniel['id'] ) . '" style="color:#4f6b4a;">Click here to reset your password</a></p><p>Thanks for reading.</p>' ),
				'fail'    => 'reset-daniel-1' === $key ? $outage : '',
			)
		);
	}

	// Wordfence lockout alerts for the brute-force waves the security logs show.
	$waves = array(
		array( $demo->ago( 19, 2, 35 ) + 40, '203.0.113.42', "Exceeded the maximum number of login failures which is: 8. The last username they tried to sign in with was: 'admin'" ),
		array( $demo->ago( 6, 3, 51 ), '198.51.100.48', "Used an invalid username 'administrator' to try to sign in" ),
		array( $demo->now - 4 * HOUR_IN_SECONDS - 17 * MINUTE_IN_SECONDS, '203.0.113.9', "Exceeded the maximum number of login failures which is: 8. The last username they tried to sign in with was: 'shopadmin'" ),
	);
	foreach ( $waves as $i => $w ) {
		$add(
			array(
				'key'     => "lockout-{$i}",
				'kind'    => 'lockout',
				'ts'      => $w[0],
				'to'      => array( 'june@larkspur.example' ),
				'subject' => '[Wordfence Alert] larkspur.example User locked out from signing in',
				'body'    => 'This email was sent from your website "Larkspur & Fern" by the Wordfence plugin at ' . wp_date( 'l jS \of F Y \a\t h:i:s A', $w[0] ) . "\n\n"
					. "The Wordfence administrative URL for this site is: {$site}/wp-admin/admin.php?page=Wordfence\n\n"
					. "A user with IP address {$w[1]} has been locked out from signing in or using the password recovery form for the following reason: {$w[2]}.\n"
					. "The duration of the lockout is 4 hours.\n\nUser IP: {$w[1]}\n\n"
					. "To change your alert options for Wordfence, visit:\n{$site}/wp-admin/admin.php?page=Wordfence&subpage=global_options\n",
				'html'    => false,
				'source'  => 'Wordfence Security',
				'file'    => 'wp-content/plugins/wordfence/lib/wordfenceClass.php',
				'line'    => 7313,
			)
		);
	}

	usort(
		$mails,
		function ( $a, $b ) {
			return $a['ts'] - $b['ts'];
		}
	);

	$customer_kinds = array( 'processing', 'completed', 'on_hold', 'refunded', 'note', 'wc_reset', 'reminder', 'autoreply' );
	$days_ago       = function ( $m ) use ( $demo ) {
		return ( $demo->now - $m['ts'] ) / DAY_IN_SECONDS;
	};
	// A stable "random" pick per plugin, so each log keeps a slightly different slice.
	$keep = function ( $plugin, $m, $percent ) {
		return ( crc32( $plugin . '|' . $m['key'] ) % 100 ) < $percent;
	};
	$header_text = function ( $m ) use ( $from ) {
		return 'From: ' . $from . "\r\n" . 'Content-Type: ' . ( $m['html'] ? 'text/html' : 'text/plain' ) . '; charset=UTF-8' . "\r\n" . ( $m['reply_to'] ? 'Reply-To: ' . $m['reply_to'] . "\r\n" : '' );
	};
	$offset   = (int) round( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
	$counts   = array();

	// Gravity SMTP: two weeks through its PHP Mail connector, every form,
	// reset, lockout and refund plus a sample of the routine order mail; its
	// own suppressions, a partially sent order alert, a debug log, and the
	// test mails June sent in test mode.
	if ( $has( 'gsmtp' ) ) {
		$rows = array();
		foreach ( $mails as $m ) {
			if ( $days_ago( $m ) > 13.5 ) {
				continue;
			}
			// The old shared orders@ inbox was still on Chloe's new order alert
			// (Gravity SMTP stripped it as suppressed), and on the stock alert
			// her order set off, which went nowhere else.
			if ( 'new-1056' === $m['key'] ) {
				$m['to'][] = 'orders@larkspur.example';
				$rows[]    = $m + array( 'status' => 'partially-sent', 'log' => 'Email partially sent. Suppressed recipients removed: orders@larkspur.example' );
				$rows[]    = array(
					'key'     => 'gsmtp-suppressed',
					'kind'    => 'low_stock',
					'ts'      => $m['ts'] + 55,
					'to'      => array( 'orders@larkspur.example' ),
					'subject' => '[Larkspur & Fern] Product low in stock',
					'body'    => 'Wild Columbine (LF-PR-001) is low in stock. There are 4 left.',
					'html'    => false,
					'status'  => 'failed',
					'log'     => 'Recipient email address orders@larkspur.example is suppressed.',
				);
				continue;
			}
			$always = in_array( $m['kind'], array( 'form', 'lockout', 'reset', 'wc_reset', 'refunded', 'note' ), true ) || '' !== $m['fail'];
			if ( $always || $keep( 'gsmtp', $m, 26 ) ) {
				$rows[] = $m + array( 'status' => '' !== $m['fail'] ? 'failed' : 'sent', 'log' => $m['fail'] );
			}
		}
		// June tried test mode while setting Gravity SMTP up.
		foreach ( array( array( 16, 'june@larkspur.example' ), array( 16, 'mateo@larkspur.example' ) ) as $j => $t ) {
			$rows[] = array(
				'key'     => "gsmtp-test-{$j}",
				'kind'    => 'test',
				'ts'      => $demo->ago( $t[0], 15, 2 + $j * 3 ),
				'to'      => array( $t[1] ),
				'subject' => 'Test Email from Gravity SMTP',
				'body'    => '<div style="font-family:Helvetica,Arial,sans-serif;text-align:center;padding:32px;"><h1 style="color:#242748;font-size:26px;">Test Successful</h1><p>This email was sent from Larkspur &amp; Fern through PHP Mail. Your Gravity SMTP settings are working.</p></div>',
				'source'  => 'Gravity SMTP',
				'status'  => 'sandboxed',
				'log'     => 'Email sandboxed.',
			);
		}
		foreach ( $rows as $i => $r ) {
			$rows[ $i ] += array( 'html' => true, 'source' => 'WooCommerce', 'reply_to' => '', 'attach' => array() );
		}
		usort(
			$rows,
			function ( $a, $b ) {
				return $a['ts'] - $b['ts'];
			}
		);
		$debug = array();
		foreach ( $rows as $r ) {
			$headers = array( 'content-type' => ( $r['html'] ? 'text/html' : 'text/plain' ) . '; charset=UTF-8' );
			if ( $r['reply_to'] ) {
				$headers['reply-to'] = $r['reply_to'];
			}
			$attachments = array();
			foreach ( $r['attach'] as $file ) {
				$attachments[] = WP_CONTENT_DIR . '/uploads/wpo_wcpdf/attachments/' . $file;
			}
			$extra = array(
				'headers'     => $headers,
				'attachments' => $attachments,
				'source'      => $r['source'],
				'to'          => implode( ',', $r['to'] ),
				'from'        => $from,
			);
			$wpdb->insert(
				$tables['gsmtp'],
				array(
					'date_created' => $demo->gmt( $r['ts'] ),
					'date_updated' => $demo->gmt( $r['ts'] + 1 ),
					'status'       => $r['status'],
					'service'      => 'wp_mail',
					'subject'      => $r['subject'],
					'message'      => $r['body'],
					'extra'        => serialize( $extra ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
				)
			);
			$event = (int) $wpdb->insert_id;
			$trail = array( array( 'started', 'Starting email send for PHPMail connector.' ) );
			$trail[] = 'sent' === $r['status'] ? array( 'sent', 'Email successfully sent.' ) : array( $r['status'], $r['log'] );
			if ( $has( 'gsmtp_logs' ) ) {
				foreach ( $trail as $t ) {
					$wpdb->insert(
						$tables['gsmtp_logs'],
						array(
							'event_id'     => $event,
							'action_name'  => $t[0],
							'log_value'    => $t[1],
							'date_created' => $demo->gmt( $r['ts'] ),
							'date_updated' => $demo->gmt( $r['ts'] ),
						)
					);
				}
			}
			// June had the debug log on while she chased the outage and the
			// orders@ suppressions, then switched it off again.
			$age = $days_ago( $r );
			if ( $age >= 9.5 && $age <= 14 ) {
				$debug[] = array( $r['ts'], 'debug', "send(): [EMAIL ID {$event}] - Starting email send for PHPMail connector." );
				if ( 'sent' === $r['status'] ) {
					$debug[] = array( $r['ts'] + 1, 'debug', "send(): [EMAIL ID {$event}] - Email successfully sent." );
				} elseif ( 'failed' === $r['status'] && false !== strpos( $r['log'], 'suppressed' ) ) {
					$debug[] = array( $r['ts'] + 1, 'error', "handle_suppressed_email(): [EMAIL ID {$event}] - " . $r['log'] );
				} elseif ( 'failed' === $r['status'] ) {
					$debug[] = array( $r['ts'] + 1, 'error', "send(): [EMAIL ID {$event}] - Email failed to send. Details: " . $r['log'] );
					$debug[] = array( $r['ts'] + 2, 'warning', "send(): [EMAIL ID {$event}] - Backup integration not set, aborting email send." );
				} else {
					$debug[] = array( $r['ts'] + 1, 'info', "maybe_filter_suppressed_recipients(): [EMAIL ID {$event}] - " . $r['log'] );
				}
			}
		}
		if ( $has( 'gsmtp_debug' ) ) {
			$debug[] = array( $demo->ago( 14, 16, 22 ), 'info', 'Debug logging enabled by June Alder.' );
			$debug[] = array( $demo->ago( 9.4, 16, 30 ), 'info', 'Debug logging disabled by June Alder.' );
			usort(
				$debug,
				function ( $a, $b ) {
					return $a[0] - $b[0];
				}
			);
			foreach ( $debug as $d ) {
				$wpdb->insert(
					$tables['gsmtp_debug'],
					array(
						'priority'     => $d[1],
						'line'         => $d[2],
						'date_created' => $demo->gmt( $d[0] ),
						'date_updated' => $demo->gmt( $d[0] ),
					)
				);
			}
		}
		if ( $has( 'gsmtp_supp' ) ) {
			$supp = array(
				array( 'orders@larkspur.example', 'Old shared orders inbox, closed when we moved order alerts to June and Mateo.', $demo->ago( 14, 16, 20 ) ),
				array( 'nadia.petrov@example.org', 'Typo of nadia.petrova@example.org. Bounced twice from the contact form auto-reply.', $demo->ago( 11, 10, 20 ) ),
				array( 'workshops@larkspur.example', 'Retired address. Workshop questions go to Priya now.', $demo->ago( 27, 15, 45 ) ),
			);
			foreach ( $supp as $s ) {
				$wpdb->insert(
					$tables['gsmtp_supp'],
					array(
						'email'        => $s[0],
						'reason'       => 'manually_added',
						'notes'        => $s[1],
						'date_created' => $demo->gmt( $s[2] ),
					)
				);
			}
		}
		$counts['gravity-smtp'] = count( $rows );
	}

	// FluentSMTP: what customers were sent over the last twelve days, plus the
	// form notifications. Noah's completed-order mail failed and Mateo resent it.
	if ( $has( 'fluent' ) ) {
		$n = 0;
		foreach ( $mails as $m ) {
			$customer = in_array( $m['kind'], $customer_kinds, true );
			if ( $days_ago( $m ) > 12 || ( ! $customer && 'form' !== $m['kind'] ) || ! ( '' !== $m['fail'] || 'completed-1059' === $m['key'] || $keep( 'fluent', $m, 60 ) ) ) {
				continue;
			}
			$status   = '' !== $m['fail'] ? 'failed' : 'sent';
			$response = '' !== $m['fail'] ? array( 'code' => 0, 'message' => $m['fail'], 'errors' => array() ) : array( 'code' => 200, 'message' => 'OK' );
			$extra    = array( 'provider' => 'default', 'send_time_ms' => 180 + crc32( $m['key'] ) % 420 );
			$resent   = 0;
			if ( 'completed-1059' === $m['key'] ) {
				$response = array( 'code' => 0, 'message' => $outage, 'errors' => array() );
				$extra['resends'] = array(
					array(
						'at'   => $demo->local( $m['ts'] + 15 * HOUR_IN_SECONDS + 1320 ),
						'to'   => $m['to'],
						'by'   => 'Mateo Cruz',
						'sent' => true,
						'ms'   => 236.4,
					),
				);
				$resent = 1;
			}
			$to = array();
			foreach ( $m['to'] as $addr ) {
				$to[] = array( 'email' => $addr );
			}
			$reply = $m['reply_to'] ? array( array( 'email' => $m['reply_to'] ) ) : array();
			$ok = $wpdb->insert(
				$tables['fluent'],
				array(
					'site_id'      => get_current_blog_id(),
					'to'           => serialize( $to ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
					'from'         => $from,
					'subject'      => $m['subject'],
					'body'         => $m['body'],
					'headers'      => serialize( array( 'reply-to' => $reply, 'cc' => array(), 'bcc' => array(), 'content-type' => $m['html'] ? 'text/html' : 'text/plain' ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
					'attachments'  => serialize( $m['attach'] ? array( array( WP_CONTENT_DIR . '/uploads/wpo_wcpdf/attachments/' . $m['attach'][0], $m['attach'][0], 'base64', 'application/pdf', false, 'attachment', $m['attach'][0] ) ) : array() ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
					'status'       => $status,
					'response'     => serialize( $response ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
					'extra'        => serialize( $extra ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
					'retries'      => 0,
					'resent_count' => $resent,
					'created_at'   => $demo->local( $m['ts'] ),
					'updated_at'   => $demo->local( $resent ? $m['ts'] + 15 * HOUR_IN_SECONDS + 1320 : $m['ts'] ),
				)
			);
			$n += $ok ? 1 : 0;
		}
		$counts['fluent-smtp'] = $n;
	}

	// WP Mail SMTP (free) keeps debug events, not mail: one "request sent"
	// event per send while debug logging is on, an error for each failure.
	if ( $has( 'wpms' ) ) {
		$n = 0;
		foreach ( $mails as $m ) {
			if ( $days_ago( $m ) > 12 || ! ( '' !== $m['fail'] || $keep( 'wpms', $m, 45 ) ) ) {
				continue;
			}
			$ok = $wpdb->insert(
				$tables['wpms'],
				array(
					'content'    => '' !== $m['fail'] ? "Mailer: Default (none)\r\n" . $m['fail'] : 'An email request was sent.',
					'initiator'  => wp_json_encode( array( 'file' => ABSPATH . $m['file'], 'line' => $m['line'] ) ),
					'event_type' => '' !== $m['fail'] ? 0 : 1,
					'created_at' => $demo->gmt( $m['ts'] ),
				)
			);
			$n += $ok ? 1 : 0;
		}
		$counts['wp-mail-smtp'] = $n;
	}

	// Post SMTP: twelve days, about a third of everything and every failure.
	if ( $has( 'postsmtp' ) ) {
		$n = 0;
		foreach ( $mails as $m ) {
			if ( $days_ago( $m ) > 12 || ! ( '' !== $m['fail'] || $keep( 'postsmtp', $m, 34 ) ) ) {
				continue;
			}
			$failed = '' !== $m['fail'];
			$ok = $wpdb->insert(
				$tables['postsmtp'],
				array(
					'solution'           => $failed ? 'PHP could not hand the message to the server\'s mail program. Check that sendmail is running, or set up an SMTP connection so mail no longer depends on it.' : null,
					'success'            => $failed ? $m['fail'] : '1',
					'from_header'        => $from,
					'to_header'          => implode( ', ', $m['to'] ),
					'cc_header'          => '',
					'bcc_header'         => '',
					'reply_to_header'    => $m['reply_to'],
					'transport_uri'      => 'smtp://localhost:25',
					'original_to'        => implode( ', ', $m['to'] ),
					'original_subject'   => $m['subject'],
					'original_message'   => $m['body'],
					'original_headers'   => $header_text( $m ),
					'session_transcript' => '',
					'time'               => $m['ts'] + $offset,
				)
			);
			$n += $ok ? 1 : 0;
		}
		$counts['post-smtp'] = $n;
	}

	// WP Mail Logging: three weeks of the mail the staff get (stock alerts,
	// lockouts, form notifications, resets, order problems).
	if ( $has( 'wpml' ) ) {
		$n = 0;
		foreach ( $mails as $m ) {
			$staff = in_array( $m['kind'], array( 'low_stock', 'no_stock', 'backorder', 'lockout', 'form', 'reset', 'cancelled', 'failed' ), true );
			if ( $days_ago( $m ) > 22 || ! ( $staff || '' !== $m['fail'] ) ) {
				continue;
			}
			$ok = $wpdb->insert(
				$tables['wpml'],
				array(
					'timestamp'      => $demo->local( $m['ts'] ),
					'host'           => '',
					'receiver'       => implode( ',', $m['to'] ),
					'subject'        => $m['subject'],
					'message'        => $m['body'],
					'headers'        => $header_text( $m ),
					'attachments'    => '',
					'error'          => '' !== $m['fail'] ? $m['fail'] : null,
					'plugin_version' => defined( 'WPML_Plugin__version' ) ? WPML_Plugin__version : '1.17.0',
				)
			);
			$n += $ok ? 1 : 0;
		}
		$counts['wp-mail-logging'] = $n;
	}

	// SureMails: nine days, about two in five of everything. Sofia's completed-order
	// mail failed and was never resent.
	if ( $has( 'suremails' ) ) {
		$n = 0;
		foreach ( $mails as $m ) {
			if ( $days_ago( $m ) > 9 || ! ( '' !== $m['fail'] || 'completed-1062' === $m['key'] || $keep( 'suremails', $m, 40 ) ) ) {
				continue;
			}
			$fail    = '' !== $m['fail'] ? $m['fail'] : ( 'completed-1062' === $m['key'] ? $outage : '' );
			$message = '' !== $fail ? 'Email sending failed via PHP Mail: ' . $fail : 'Email sent successfully via PHP Mail.';
			$ok = $wpdb->insert(
				$tables['suremails'],
				array(
					'email_from'  => $from,
					'email_to'    => implode( ', ', $m['to'] ),
					'subject'     => $m['subject'],
					'body'        => $m['body'],
					'headers'     => maybe_serialize( array( 'content_type' => $m['html'] ? 'text/html' : 'text/plain', 'reply_to' => $m['reply_to'] ? array( array( 'email' => $m['reply_to'], 'name' => '' ) ) : array() ) ),
					'attachments' => maybe_serialize( array() ),
					'status'      => '' !== $fail ? 'failed' : 'sent',
					'response'    => maybe_serialize(
						array(
							array(
								'retry'      => '' !== $fail ? 1 : 0,
								'Message'    => $message,
								'Connection' => 'PHP Mail',
								'timestamp'  => $demo->local( $m['ts'] ),
								'simulated'  => false,
							),
						)
					),
					'meta'        => wp_json_encode( array( 'retry' => '' !== $fail ? 1 : 0, 'resend' => 0 ) ),
					'connection'  => 'PHPMAIL',
					'created_at'  => $demo->local( $m['ts'] ),
					'updated_at'  => $demo->local( $m['ts'] + ( '' !== $fail ? 60 : 0 ) ),
				)
			);
			$n += $ok ? 1 : 0;
		}
		$counts['suremails'] = $n;
	}

	// Site Mailer: two weeks of what customers were sent, with the delivery and
	// open tracking its cloud service reports back. Nadia's auto-replies went to
	// a mistyped address: the first bounced, the second was dropped.
	if ( $has( 'sitemailer' ) ) {
		$n = 0;
		foreach ( $mails as $m ) {
			if ( $days_ago( $m ) > 14 || ! in_array( $m['kind'], $customer_kinds, true ) || ( '' === $m['fail'] && ! $keep( 'sitemailer', $m, 65 ) && 'autoreply' !== $m['kind'] ) ) {
				continue;
			}
			$status = 'delivered';
			if ( in_array( 'nadia.petrov@example.org', $m['to'], true ) ) {
				$status = 'autoreply-0' === $m['key'] ? 'bounce' : 'dropped';
			} elseif ( '' !== $m['fail'] ) {
				$status = 'failed';
			}
			$opened = 'delivered' === $status && ( crc32( 'open' . $m['key'] ) % 100 ) < 60 ? 1 : 0;
			$hash   = md5( 'site-mailer|' . $m['key'] );
			$api_id = substr( $hash, 0, 8 ) . '-' . substr( $hash, 8, 4 ) . '-4' . substr( $hash, 13, 3 ) . '-a' . substr( $hash, 17, 3 ) . '-' . substr( $hash, 20, 12 );
			$to     = 1 === count( $m['to'] ) ? $m['to'][0] : $m['to'];
			$ok = $wpdb->insert(
				$tables['sitemailer'],
				array(
					'api_id'     => $api_id,
					'to'         => wp_json_encode( $to ),
					'subject'    => $m['subject'],
					'headers'    => wp_json_encode( $m['reply_to'] ? array( 'Reply-To: ' . $m['reply_to'] ) : '' ),
					'message'    => $m['body'],
					'activity'   => null,
					'source'     => $m['source'],
					'status'     => $status,
					'opened'     => $opened,
					'created_at' => $demo->local( $m['ts'] ),
					'updated_at' => $demo->local( $m['ts'] + ( $opened ? 3 * HOUR_IN_SECONDS : 40 ) ),
				)
			);
			if ( $has( 'sitemailer_st' ) ) {
				foreach ( $m['to'] as $addr ) {
					$wpdb->insert(
						$tables['sitemailer_st'],
						array(
							'log_id'     => $api_id,
							'email'      => $addr,
							'type'       => 'to',
							'status'     => $status,
							'opened'     => $opened,
							'created_at' => $demo->local( $m['ts'] ),
							'updated_at' => $demo->local( $m['ts'] + 40 ),
						)
					);
				}
			}
			$n += $ok ? 1 : 0;
		}
		$counts['site-mailer'] = $n;
	}

	// Later parts can still send in this process (WooCommerce stock alerts,
	// user notices). Keep the logs to the shop's history: anything added to
	// these tables after this point goes when the run ends.
	$marks = array();
	foreach ( $tables as $key => $table ) {
		$col             = 'wpml' === $key ? 'mail_id' : 'id';
		$marks[ $table ] = array( $col, (int) $wpdb->get_var( "SELECT COALESCE(MAX({$col}), 0) FROM {$table}" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	register_shutdown_function(
		function () use ( $marks ) {
			global $wpdb;
			foreach ( $marks as $table => $mark ) {
				$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE {$mark[0]} > %d", $mark[1] ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
	);

	$summary = array();
	foreach ( $counts as $surface => $count ) {
		$summary[] = "{$surface} {$count}";
	}
	$demo->log( 'mail logs: ' . implode( ', ', $summary ) );
};
