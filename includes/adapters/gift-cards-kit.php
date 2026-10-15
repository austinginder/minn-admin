<?php
/**
 * The gift cards family's kit: what every gift card adapter would otherwise
 * build again. Money as text, an amount read from a request, the surface
 * (filter bar, columns, the switch, the balance, the resend, the create
 * form), the row's fields, the detail's sections, the status card and the
 * replies every verb gives.
 *
 * Adapter tier: presentation and vendor-neutral formatting only. Routes,
 * gates and queries are the kernel's; where a card lives, how it is
 * switched, credited and mailed stay each adapter's, in its vendor's API.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Money as display-ready text: wc_price() is HTML, and every surface value
 * is escaped before it is painted, so its markup is stripped and its
 * entities decoded here, where the store's currency, decimals and
 * separators still apply.
 *
 * @param float|string $value    Amount.
 * @param string       $currency Optional currency code the card was sold in.
 * @return string
 */
function minn_admin_gift_money( $value, $currency = '' ) {
	if ( ! function_exists( 'wc_price' ) ) {
		return (string) $value;
	}
	$args = $currency ? array( 'currency' => $currency ) : array();
	return html_entity_decode( wp_strip_all_tags( wc_price( (float) $value, $args ) ), ENT_QUOTES, 'UTF-8' );
}

/** The store's price decimals. */
function minn_admin_gift_decimals() {
	return function_exists( 'wc_get_price_decimals' ) ? (int) wc_get_price_decimals() : 2;
}

/**
 * A money amount from a request, or a WP_Error. Scientific notation and
 * non-finite values are numeric to PHP and would otherwise store a $1e20
 * card; nine million and change is already past any real store card. Zero
 * is legal only for a balance (a card can be emptied, not issued empty).
 *
 * @param mixed  $raw        Request value.
 * @param bool   $allow_zero Whether 0 is a legal amount.
 * @param string $code       The vendor's error code ("minn_pwgc_amount").
 * @return float|WP_Error
 */
function minn_admin_gift_amount( $raw, $allow_zero, $code ) {
	if ( '' === $raw || null === $raw || ! is_numeric( $raw ) || ! is_finite( (float) $raw ) ) {
		return new WP_Error( $code, __( 'Enter the amount as a number.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	$amount = round( (float) $raw, minn_admin_gift_decimals() );
	if ( $amount < 0 || ( ! $allow_zero && $amount <= 0 ) ) {
		return new WP_Error(
			$code,
			$allow_zero
				? __( 'A balance cannot be negative.', 'minn-admin' )
				: __( 'A gift card has to be worth more than nothing.', 'minn-admin' ),
			array( 'status' => 400 )
		);
	}
	if ( $amount > 9999999.99 ) {
		return new WP_Error( $code, __( 'Enter an amount no larger than 9,999,999.99.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	return $amount;
}

/**
 * A list row's fields, the same for every vendor: the code, both amounts as
 * the store prints money, the status pill, whether it can be spent (which
 * the switch reads, so an expired card can still be disabled), the
 * recipient, the order it came from, and when it was made (UTC).
 *
 * @return array
 */
function minn_admin_gift_fields() {
	return array(
		'id'        => 'int',
		'code'      => 'text',
		'amount'    => 'text',
		'balance'   => 'text',
		'status'    => 'text',
		'enabled'   => 'bool',
		'recipient' => 'text',
		'order'     => 'text',
		'order_id'  => 'int',
		'has_order' => 'bool',
		'created'   => 'text',
	);
}

/** The detail's fields: its title (the code), its sections, the vendor's screen. */
function minn_admin_gift_view_fields() {
	return array( 'title' => 'text', 'sections' => 'raw', 'adminUrl' => 'text' );
}

/** The switch's one argument: which way to flip, never a default (rest_sanitize_boolean( null ) is false). */
function minn_admin_gift_switch_args() {
	return array( 'enabled' => array( 'type' => 'boolean', 'required' => true ) );
}

/**
 * The create form: the amount and the code always, then what the vendor
 * can store.
 *
 * @param array $o recipient ('optional' | 'required'), name, from, message
 *                 and send (bool each).
 * @return array[]
 */
function minn_admin_gift_create_fields( array $o ) {
	$fields = array(
		array( 'key' => 'amount', 'label' => __( 'Amount', 'minn-admin' ), 'type' => 'number' ),
		array(
			'key'         => 'code',
			'label'       => __( 'Code', 'minn-admin' ),
			'mono'        => true,
			'required'    => false,
			'placeholder' => __( 'Leave blank to generate one', 'minn-admin' ),
		),
	);
	if ( ! empty( $o['recipient'] ) ) {
		$fields[] = array( 'key' => 'recipient', 'label' => __( 'Recipient email', 'minn-admin' ), 'type' => 'email', 'required' => 'required' === $o['recipient'] );
	}
	if ( ! empty( $o['name'] ) ) {
		$fields[] = array( 'key' => 'recipient_name', 'label' => __( 'Recipient name', 'minn-admin' ), 'required' => false );
	}
	if ( ! empty( $o['from'] ) ) {
		$fields[] = array( 'key' => 'sender_name', 'label' => __( 'From', 'minn-admin' ), 'required' => false );
	}
	if ( ! empty( $o['message'] ) ) {
		$fields[] = array( 'key' => 'message', 'label' => __( 'Message', 'minn-admin' ), 'type' => 'textarea', 'rows' => 3, 'required' => false );
	}
	if ( ! empty( $o['send'] ) ) {
		$fields[] = array(
			'key'     => 'send',
			'label'   => __( 'Send the gift card email now', 'minn-admin' ),
			'type'    => 'select',
			'value'   => 'no',
			'options' => array(
				array( 'no', __( 'No', 'minn-admin' ) ),
				array( 'yes', __( 'Yes', 'minn-admin' ) ),
			),
		);
	}
	return $fields;
}

/**
 * A gift card list's surface: the orders filter bar (a status dropdown that
 * holds several at once, a date window, chips, all of it in the URL), the
 * columns, the detail, Disable, Enable, Adjust balance, Resend email, View
 * order and the create form.
 *
 * @param string $res Resource id ("pwgc/gift-cards").
 * @param array  $o   sub, plugin, cap, status (route under minn-admin/v1),
 *                    statuses (value => label), search (placeholder),
 *                    create (fields, or null for none), resend_when (a
 *                    `when` for Resend email, or null), and any further
 *                    surface keys (adminPages).
 * @return array
 */
function minn_admin_gift_surface( $res, array $o ) {
	$statuses = array();
	foreach ( $o['statuses'] as $value => $label ) {
		$statuses[] = array( $value, $label );
	}
	$resend = Minn_Admin_Kernel::ui( $res, 'resend', array( 'label' => __( 'Resend email', 'minn-admin' ) ) );
	if ( ! empty( $o['resend_when'] ) ) {
		$resend['when'] = $o['resend_when'];
	}
	$collection = Minn_Admin_Kernel::collection( $res, array(
		'filterBar' => array(
			'searchPlaceholder' => $o['search'],
			'statuses'          => $statuses,
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
		'detail'    => array( 'sectionsRoute' => 'minn-admin/v1/' . $res . '/{id}/view' ),
		'actions'   => array(
			Minn_Admin_Kernel::ui( $res, 'status', array(
				'label'   => __( 'Disable', 'minn-admin' ),
				'body'    => array( 'enabled' => false ),
				'when'    => true,
				'key'     => 'enabled',
				'confirm' => __( 'Disable this gift card? Its balance can no longer be spent.', 'minn-admin' ),
				'danger'  => true,
			) ),
			Minn_Admin_Kernel::ui( $res, 'status', array(
				'label' => __( 'Enable', 'minn-admin' ),
				'body'  => array( 'enabled' => true ),
				'when'  => false,
				'key'   => 'enabled',
			) ),
			Minn_Admin_Kernel::ui( $res, 'balance', array( 'label' => __( 'Adjust balance', 'minn-admin' ) ) ) + array(
				'fields' => array( array( 'key' => 'balance', 'label' => __( 'New balance', 'minn-admin' ), 'type' => 'number' ) ),
			),
			$resend,
			array(
				'label' => __( 'View order', 'minn-admin' ),
				'href'  => home_url( '/minn-admin/orders/{order_id}' ),
				'when'  => array( 'key' => 'has_order', 'equals' => true ),
			),
		),
	) );
	if ( ! empty( $o['create'] ) ) {
		$collection['create'] = array(
			'label'  => __( 'Add gift card', 'minn-admin' ),
			'route'  => 'minn-admin/v1/' . $res,
			'fields' => $o['create'],
		);
	}
	$surface = array(
		'label'      => __( 'Gift cards', 'minn-admin' ),
		'family'     => 'gift-cards',
		'sub'        => $o['sub'],
		'plugin'     => $o['plugin'],
		'icon'       => 'gift',
		'cap'        => $o['cap'],
		'group'      => 'commerce',
		'status'     => array( 'route' => 'minn-admin/v1/' . $o['status'] ),
		'collection' => $collection,
	);
	unset( $o['sub'], $o['plugin'], $o['cap'], $o['status'], $o['statuses'], $o['search'], $o['create'], $o['resend_when'] );
	return array_merge( $surface, $o );
}

/**
 * A detail's sections: the card always, the recipient when there is one,
 * the origin when there is one (or always, for a vendor whose origin
 * always has a row).
 *
 * @param array $gift      Rows of the card itself.
 * @param array $recipient Rows naming whom it is for.
 * @param array $origin    Rows naming where it came from.
 * @return array[]
 */
function minn_admin_gift_sections( array $gift, array $recipient, array $origin ) {
	$sections = array( array( 'title' => __( 'Gift card', 'minn-admin' ), 'rows' => $gift ) );
	if ( $recipient ) {
		$sections[] = array( 'title' => __( 'Recipient', 'minn-admin' ), 'rows' => $recipient );
	}
	if ( $origin ) {
		$sections[] = array( 'title' => __( 'Origin', 'minn-admin' ), 'rows' => $origin );
	}
	return $sections;
}

/** The card rows every vendor's detail opens with: code, status, balance, original amount. */
function minn_admin_gift_card_rows( $code, $code_type, $status, $balance, $original ) {
	return array(
		array( 'label' => __( 'Code', 'minn-admin' ), 'value' => $code, 'type' => $code_type ),
		array( 'label' => __( 'Status', 'minn-admin' ), 'value' => $status, 'type' => 'pill' ),
		array( 'label' => __( 'Balance', 'minn-admin' ), 'value' => $balance ),
		array( 'label' => __( 'Original amount', 'minn-admin' ), 'value' => $original ),
	);
}

/**
 * The status card: what the store still owes, across how many cards, and
 * how many lapse within 30 days when the vendor dates them.
 *
 * @param float    $outstanding Money still spendable.
 * @param int      $active      Cards that can be spent.
 * @param int|null $expiring    Cards expiring within 30 days, or null for a vendor that does not say.
 * @param string   $open        The vendor screen's label ("Open PW Gift Cards ↗").
 * @param string   $href        Its address.
 * @return array
 */
function minn_admin_gift_status( $outstanding, $active, $expiring, $open, $href ) {
	$rows = array(
		array(
			'label' => __( 'Outstanding balance', 'minn-admin' ),
			'value' => minn_admin_gift_money( $outstanding ),
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
	return array( 'rows' => $rows, 'actions' => array( array( 'label' => $open, 'href' => $href ) ) );
}

/** The switch's reply. */
function minn_admin_gift_switched( $code, $enabled ) {
	if ( $enabled ) {
		return array(
			'message' => sprintf(
				/* translators: %s: gift card code. */
				__( '%s is enabled.', 'minn-admin' ),
				$code
			),
		);
	}
	return array(
		'message' => sprintf(
			/* translators: %s: gift card code. */
			__( '%s is disabled and can no longer be spent.', 'minn-admin' ),
			$code
		),
	);
}

/** The balance's reply. */
function minn_admin_gift_holds( $code, $money ) {
	return array(
		'message' => sprintf(
			/* translators: 1: gift card code; 2: formatted money amount. */
			__( '%1$s now holds %2$s.', 'minn-admin' ),
			$code,
			$money
		),
	);
}

/** The resend's reply. */
function minn_admin_gift_sent( $to ) {
	return array(
		'message' => sprintf(
			/* translators: %s: recipient email address. */
			__( 'Gift card sent to %s.', 'minn-admin' ),
			$to
		),
	);
}

/**
 * The create's reply.
 *
 * @param string $code    The new card's code (as the caller may see it).
 * @param string $sent_to Whom it was emailed to, or '' when it was not.
 * @param string $money   What it holds, as the store prints money.
 * @return array
 */
function minn_admin_gift_created( $code, $sent_to, $money ) {
	if ( '' !== $sent_to ) {
		return array(
			'message' => sprintf(
				/* translators: 1: gift card code; 2: recipient email address. */
				__( '%1$s created and sent to %2$s.', 'minn-admin' ),
				$code,
				$sent_to
			),
		);
	}
	return array(
		'message' => sprintf(
			/* translators: 1: gift card code; 2: formatted money amount. */
			__( '%1$s created, holding %2$s.', 'minn-admin' ),
			$code,
			$money
		),
	);
}

/** Whether two amounts are the same money at the store's decimals. */
function minn_admin_gift_same( $a, $b ) {
	return abs( round( (float) $a, minn_admin_gift_decimals() ) - round( (float) $b, minn_admin_gift_decimals() ) ) < 0.000001;
}

/** The refusal when a card has no address to send to. */
function minn_admin_gift_no_recipient( $code ) {
	return new WP_Error( $code, __( 'This gift card has no recipient address, so there is nothing to resend.', 'minn-admin' ), array( 'status' => 400 ) );
}
