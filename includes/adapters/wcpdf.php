<?php
/**
 * PDF Invoices & Packing Slips for WooCommerce (WP Overnight, 500k+) — an
 * order's documents in Minn: the invoice number on the orders list, a
 * Documents card on the order page and quick view, and a download per
 * enabled document.
 *
 * Downloads ride the plugin's admin-ajax endpoint (action=generate_wpo_wcpdf),
 * which streams the PDF and enforces its own permission model:
 * user_can_manage_document (edit_shop_orders) plus a nonce for the
 * generate_wpo_wcpdf action, the same one its own order-list buttons ride.
 * Minn hands the client the enabled document types and that nonce at boot.
 * The invoice number on the list is the plugin's own field on wc/v3/orders
 * (wpo_wcpdf_invoice_number), so the list asks for it by name.
 *
 * The card's create, edit, regenerate and delete go through the plugin's
 * document objects, as its order screen does (Admin::ajax_crud_document()).
 * That handler's form parser only runs with its own ajax nonce, so the save
 * builds the same data array here and passes it through the parser's filter,
 * which the plugin's paid extensions hook. Its rule for who may change a
 * number or a date holds here too: the person manages its settings, and for
 * an invoice or a credit note its "Enable document data editing" debug
 * setting is on. Notes are open to anyone who may manage the document.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Boot payload for the order views: enabled PDF documents the current user
 * may generate, or null when the plugin (or access) is absent.
 *
 * @return array|null { ajax, nonce, docs: [ { type, title } ] }
 */
function minn_admin_wcpdf_boot() {
	if ( ! function_exists( 'WPO_WCPDF' ) || ! class_exists( 'WooCommerce' ) ) {
		return null;
	}
	try {
		$main = WPO_WCPDF();
		if ( empty( $main->documents ) || empty( $main->admin ) || ! method_exists( $main->admin, 'user_can_manage_document' ) ) {
			return null;
		}
		$docs = array();
		foreach ( $main->documents->get_documents( 'enabled' ) as $document ) {
			if ( ! $main->admin->user_can_manage_document( $document->get_type() ) ) {
				continue;
			}
			$docs[] = array(
				'type'  => $document->get_type(),
				'title' => wp_strip_all_tags( (string) $document->get_title() ),
			);
		}
		if ( ! $docs ) {
			return null;
		}
		return array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'generate_wpo_wcpdf' ),
			'docs'  => $docs,
		);
	} catch ( \Throwable $e ) {
		return null;
	}
}

function minn_admin_wcpdf_active() {
	return function_exists( 'WPO_WCPDF' ) && function_exists( 'wcpdf_get_document' ) && class_exists( 'WooCommerce' );
}

/** The plugin's own gate for one document type; false when it cannot be asked. */
function minn_admin_wcpdf_can_manage( $type ) {
	try {
		$admin = WPO_WCPDF()->admin;
	} catch ( \Throwable $e ) {
		return false;
	}
	return is_object( $admin ) && method_exists( $admin, 'user_can_manage_document' ) && (bool) $admin->user_can_manage_document( (string) $type );
}

/**
 * Whether the caller may change a document's number and date, by the
 * plugin's own rule (Admin::process_order_document_form_data()).
 */
function minn_admin_wcpdf_can_edit_data( $type ) {
	try {
		$settings = WPO_WCPDF()->settings;
	} catch ( \Throwable $e ) {
		return false;
	}
	if ( ! is_object( $settings ) || ! method_exists( $settings, 'user_can_manage_settings' ) || ! $settings->user_can_manage_settings() ) {
		return false;
	}
	$debug = isset( $settings->debug_settings ) && is_array( $settings->debug_settings ) ? $settings->debug_settings : array();
	return ! empty( $debug['enable_document_data_editing'] ) || ! in_array( (string) $type, array( 'invoice', 'credit-note' ), true );
}

/**
 * Who may reach an order's documents: an order editor, as WooCommerce's own
 * REST asks it. A missing order passes here and is a 404 in the handler, as
 * wc/v3/orders answers one.
 */
function minn_admin_wcpdf_order_gate( WP_REST_Request $r ) {
	if ( ! minn_admin_wcpdf_active() || ! function_exists( 'wc_rest_check_post_permissions' ) || ! current_user_can( 'edit_shop_orders' ) ) {
		return false;
	}
	$id = (int) Minn_Admin::path_param( $r );
	if ( $id > 0 && wc_get_order( $id ) && ! wc_rest_check_post_permissions( 'shop_order', 'edit', $id ) ) {
		return false;
	}
	$type = Minn_Admin::path_param( $r, 'type' );
	return '' === $type || minn_admin_wcpdf_can_manage( $type );
}

/**
 * The plugin's bookkeeping after a write (its order notes, creation trigger
 * and printed marks), asked only when it is there. It runs after the
 * document is saved, so a failure in it never turns a change that held
 * into an error.
 */
function minn_admin_wcpdf_note( $method, ...$args ) {
	try {
		$main = WPO_WCPDF()->main;
		if ( is_object( $main ) && is_callable( array( $main, $method ) ) ) {
			call_user_func_array( array( $main, $method ), $args );
		}
	} catch ( \Throwable $e ) {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( 'minn-admin-wcpdf: ' . $method . ' ' . get_class( $e ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}

/** The order, or the 404 the card shows. */
function minn_admin_wcpdf_order( $id ) {
	$order = $id > 0 ? wc_get_order( (int) $id ) : false;
	if ( ! $order || 'shop_order' !== $order->get_type() ) {
		return new WP_Error( 'not_found', __( 'Order not found.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	return $order;
}

/**
 * One document on one order, read fresh, or null when the plugin does not
 * offer it there: a type it does not know, a disabled one, or one its
 * settings hold back for the order's status until it exists. A document
 * that lives on refunds (a credit note) is not the order's own.
 *
 * @param string   $type     Document type.
 * @param WC_Order $order    Order.
 * @param bool     $init     Create it when it does not exist yet.
 * @return object|null
 */
function minn_admin_wcpdf_document( $type, $order, $init = false ) {
	$id = $order->get_id();
	if ( function_exists( 'wcpdf_filter_order_ids' ) && array( $id ) !== array_values( array_map( 'intval', (array) wcpdf_filter_order_ids( array( $id ), $type ) ) ) ) {
		return null;
	}
	$document = wcpdf_get_document( (string) $type, $order, (bool) $init );
	return is_object( $document ) && is_callable( array( $document, 'exists' ) ) ? $document : null;
}

/** A document as the card reads it. */
function minn_admin_wcpdf_row( $document ) {
	$type   = (string) $document->get_type();
	$exists = (bool) $document->exists();
	$number = $exists ? $document->get_number() : null;
	$date   = $exists ? $document->get_date() : null;
	$notes  = $exists ? (string) $document->get_notes() : '';
	$plain  = is_object( $number ) && is_callable( array( $number, 'get_plain' ) ) ? $number->get_plain() : null;
	return array(
		'type'         => $type,
		'title'        => wp_strip_all_tags( (string) $document->get_title() ),
		'exists'       => $exists,
		'number'       => is_object( $number ) && is_callable( array( $number, 'get_formatted' ) ) ? (string) $number->get_formatted() : '',
		'number_plain' => null === $plain ? null : (int) $plain,
		// The instant with its real offset (WC_DateTime::date() would print
		// the site's clock labelled +00:00), and the site's own clock for the
		// card's day and the edit field.
		'date'         => $date instanceof WC_DateTime ? $date->format( DATE_ATOM ) : '',
		'date_local'   => $date instanceof WC_DateTime ? $date->date( 'Y-m-d\TH:i' ) : '',
		'notes'        => $notes,
		'number_label' => wp_strip_all_tags( (string) $document->get_number_title() ),
		'date_label'   => wp_strip_all_tags( (string) $document->get_date_title() ),
		// What the plugin's own document data box shows: the invoice always,
		// any other document once it has a number (a packing slip in the free
		// plugin exists once downloaded, with a date and no number to keep).
		'tracked'      => 'invoice' === $type || ( $exists && null !== $plain ),
		'edit'         => array(
			'data'  => minn_admin_wcpdf_can_edit_data( $type ),
			'notes' => true,
		),
	);
}

/** The HTML the plugin's own parser allows in document notes. */
function minn_admin_wcpdf_notes_html() {
	$attrs = array(
		'id'    => array(),
		'class' => array(),
		'style' => array(),
	);
	return array(
		'a'      => $attrs + array(
			'href'  => array(),
			'title' => array(),
		),
		'br'     => array(),
		'em'     => array(),
		'strong' => array(),
		'div'    => $attrs,
		'span'   => $attrs,
		'p'      => $attrs,
		'b'      => array(),
	);
}

/**
 * The fields a save sends, as the plugin's document setters take them: only
 * the ones the person changed. Number and date answer to the plugin's
 * editing rule; a value that does not parse refuses the whole save.
 *
 * @return array|WP_Error
 */
function minn_admin_wcpdf_save_data( WP_REST_Request $r, $document ) {
	$type     = (string) $document->get_type();
	$slug     = (string) $document->slug;
	$data     = array();
	$form     = array();
	$has_num  = null !== $r->get_param( 'number' );
	$has_date = null !== $r->get_param( 'date' );
	if ( ( $has_num || $has_date ) && ! minn_admin_wcpdf_can_edit_data( $type ) ) {
		return new WP_Error( 'rest_forbidden', __( 'This document\'s number and date can be changed only by someone who manages the plugin\'s settings, with document data editing turned on in its Advanced settings.', 'minn-admin' ), array( 'status' => 403 ) );
	}
	if ( $has_num ) {
		$raw = $r->get_param( 'number' );
		if ( ! ( is_int( $raw ) || ( is_string( $raw ) && ctype_digit( $raw ) ) ) || (int) $raw < 1 ) {
			return new WP_Error( 'rest_invalid_param', __( 'The number must be a whole number above zero.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		// The prefix, suffix and padding stay as they are; the plugin
		// formats the number again from them, as its own number field does.
		$cur  = $document->get_number();
		$data['number'] = array(
			'number'        => (int) $raw,
			'prefix'        => is_object( $cur ) && is_callable( array( $cur, 'get_prefix' ) ) ? $cur->get_prefix() : null,
			'suffix'        => is_object( $cur ) && is_callable( array( $cur, 'get_suffix' ) ) ? $cur->get_suffix() : null,
			'padding'       => is_object( $cur ) && is_callable( array( $cur, 'get_padding' ) ) ? $cur->get_padding() : null,
			'document_type' => $type,
			'order_id'      => $document->order->get_id(),
		);
		$form[ "_wcpdf_{$slug}_number_plain" ] = (string) (int) $raw;
	}
	if ( $has_date ) {
		$raw = (string) $r->get_param( 'date' );
		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})$/', $raw, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) || (int) $m[4] > 23 || (int) $m[5] > 59 ) {
			return new WP_Error( 'rest_invalid_param', __( 'The date must be a real date and time.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		// A string is a time in the site's zone to the plugin's set_date().
		$data['date'] = "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:00";
		$form[ "_wcpdf_{$slug}_date" ] = array( 'date' => "{$m[1]}-{$m[2]}-{$m[3]}", 'hour' => $m[4], 'minute' => $m[5] );
	}
	if ( null !== $r->get_param( 'notes' ) ) {
		$notes = $r->get_param( 'notes' );
		if ( ! is_string( $notes ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'Notes must be text.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		$data['notes'] = wp_kses( $notes, minn_admin_wcpdf_notes_html() );
		$form[ "_wcpdf_{$slug}_notes" ] = $notes;
	}
	if ( ! $data ) {
		return new WP_Error( 'rest_missing_callback_param', __( 'Nothing to save.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	// What the document must read back as, taken before the filter: a
	// filter that drops or reshapes a key must not let a change that never
	// happened answer as saved.
	$want = array();
	if ( isset( $data['number'] ) ) {
		$want['number_plain'] = (int) $data['number']['number'];
	}
	if ( isset( $data['date'] ) ) {
		$want['date_local'] = substr( str_replace( ' ', 'T', $data['date'] ), 0, 16 );
	}
	if ( array_key_exists( 'notes', $data ) ) {
		// The plugin's setter keeps no empty value ("0" included).
		$want['notes'] = empty( $data['notes'] ) ? '' : (string) $data['notes'];
	}
	return array(
		'data' => (array) apply_filters( 'wpo_wcpdf_order_document_form_data', $data, $form, $document ),
		'want' => $want,
	);
}

/**
 * The document a write acts on, or the error that stops it: the order's
 * 404, then a 404 for a document the plugin does not offer on this order,
 * then a 409 for one it has not created yet.
 *
 * @return array|WP_Error array( order, document )
 */
function minn_admin_wcpdf_target( $id, $type, $must_exist = true ) {
	$order = minn_admin_wcpdf_order( $id );
	if ( is_wp_error( $order ) ) {
		return $order;
	}
	$document = minn_admin_wcpdf_document( $type, $order );
	if ( ! $document ) {
		return new WP_Error( 'not_found', __( 'This document is not available on this order.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	if ( $must_exist && ! $document->exists() ) {
		return new WP_Error( 'not_created', __( 'This document has not been created yet.', 'minn-admin' ), array( 'status' => 409 ) );
	}
	return array( $order, $document );
}

/** The document read back from a fresh copy of the order, for a write's answer. */
function minn_admin_wcpdf_fresh( $id, $type ) {
	$order = wc_get_order( (int) $id );
	return $order ? minn_admin_wcpdf_document( $type, $order ) : null;
}

$minn_admin_wcpdf_doc = 'wcpdf/orders/(?P<id>\d+)/documents/(?P<type>[a-z0-9_-]+)';

// The order's documents, each as the card shows it.
minn_admin_endpoint( 'wcpdf/orders/(?P<id>\d+)/documents', array(
	'active' => 'minn_admin_wcpdf_active',
	'gate'   => 'minn_admin_wcpdf_order_gate',
	'run'    => function ( WP_REST_Request $r, array $ctx ) {
		$order = minn_admin_wcpdf_order( $ctx['id'] );
		if ( is_wp_error( $order ) ) {
			return $order;
		}
		$out = array();
		foreach ( WPO_WCPDF()->documents->get_documents( 'enabled' ) as $doc ) {
			$type = (string) $doc->get_type();
			if ( ! minn_admin_wcpdf_can_manage( $type ) ) {
				continue;
			}
			$document = minn_admin_wcpdf_document( $type, $order );
			if ( $document ) {
				$out[] = minn_admin_wcpdf_row( $document );
			}
		}
		return array( 'documents' => $out );
	},
) );

// Create: the plugin's own init (number and date from its settings), as a
// first download does.
minn_admin_endpoint( $minn_admin_wcpdf_doc . '/create', array(
	'active'  => 'minn_admin_wcpdf_active',
	'methods' => 'POST',
	'gate'    => 'minn_admin_wcpdf_order_gate',
	'run'     => function ( WP_REST_Request $r, array $ctx ) {
		$type = Minn_Admin::path_param( $r, 'type' );
		$t    = minn_admin_wcpdf_target( $ctx['id'], $type, false );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		list( $order, $document ) = $t;
		if ( $document->exists() ) {
			return new WP_Error( 'exists', __( 'This document already exists.', 'minn-admin' ), array( 'status' => 409 ) );
		}
		$document = minn_admin_wcpdf_document( $type, $order, true );
		$fresh    = minn_admin_wcpdf_fresh( $ctx['id'], $type );
		if ( ! $document || ! $fresh || ! $fresh->exists() ) {
			return new WP_Error( 'minn_failed', __( 'The plugin did not create the document. Nothing was reported as done.', 'minn-admin' ), array( 'status' => 500 ) );
		}
		minn_admin_wcpdf_note( 'log_document_creation_to_order_notes', $fresh, 'document_data' );
		minn_admin_wcpdf_note( 'log_document_creation_trigger_to_order_meta', $fresh, 'document_data', false, array( 'order_id' => $ctx['id'] ) );
		return array(
			'ok'       => true,
			'document' => minn_admin_wcpdf_row( $fresh ),
			/* translators: %s: the document's name, e.g. "Invoice". */
			'message'  => sprintf( __( '%s created.', 'minn-admin' ), wp_strip_all_tags( (string) $fresh->get_title() ) ),
		);
	},
) );

// Save the fields the person changed, then read them back.
minn_admin_endpoint( $minn_admin_wcpdf_doc, array(
	'active'  => 'minn_admin_wcpdf_active',
	'methods' => 'PUT',
	'gate'    => 'minn_admin_wcpdf_order_gate',
	'run'     => function ( WP_REST_Request $r, array $ctx ) {
		$type = Minn_Admin::path_param( $r, 'type' );
		$t    = minn_admin_wcpdf_target( $ctx['id'], $type );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		list( $order, $document ) = $t;
		$save = minn_admin_wcpdf_save_data( $r, $document );
		if ( is_wp_error( $save ) ) {
			return $save;
		}
		$document->set_data( $save['data'], $order );
		// The plugin numbers a dated document that has none when its number
		// or date is saved; a notes-only save must not number a document
		// that keeps none (a free packing slip).
		if ( ( isset( $save['want']['number_plain'] ) || isset( $save['want']['date_local'] ) ) && $document->get_date() && ! $document->get_number() && is_callable( array( $document, 'initiate_number' ) ) ) {
			$document->initiate_number();
		}
		$document->save();
		minn_admin_wcpdf_note( 'mark_document_printed', $document, 'document_data' );
		$fresh = minn_admin_wcpdf_fresh( $ctx['id'], $type );
		$row   = $fresh ? minn_admin_wcpdf_row( $fresh ) : null;
		$held  = $row && $row['exists'];
		foreach ( $save['want'] as $key => $value ) {
			$held = $held && $row[ $key ] === $value;
		}
		if ( ! $held ) {
			// The save ran, so this says what is known: it does not read back.
			return new WP_Error( 'minn_failed', __( 'The document does not read back as you sent it. Reload the order to see what the plugin kept.', 'minn-admin' ), array( 'status' => 500 ) );
		}
		return array(
			'ok'       => true,
			'document' => $row,
			'message'  => __( 'Document saved.', 'minn-admin' ),
		);
	},
) );

// Regenerate: the plugin applies its current settings and template data to
// the document, keeping its number and date, and notes it on the order.
minn_admin_endpoint( $minn_admin_wcpdf_doc . '/regenerate', array(
	'active'  => 'minn_admin_wcpdf_active',
	'methods' => 'POST',
	'gate'    => 'minn_admin_wcpdf_order_gate',
	'run'     => function ( WP_REST_Request $r, array $ctx ) {
		$type = Minn_Admin::path_param( $r, 'type' );
		$t    = minn_admin_wcpdf_target( $ctx['id'], $type );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		list( $order, $document ) = $t;
		$document->regenerate( $order, array() );
		minn_admin_wcpdf_note( 'log_document_creation_trigger_to_order_meta', $document, 'document_data', true, array( 'order_id' => $ctx['id'] ) );
		minn_admin_wcpdf_note( 'mark_document_printed', $document, 'document_data' );
		$fresh = minn_admin_wcpdf_fresh( $ctx['id'], $type );
		if ( ! $fresh || ! $fresh->exists() ) {
			return new WP_Error( 'minn_failed', __( 'The plugin did not regenerate the document. Nothing was reported as done.', 'minn-admin' ), array( 'status' => 500 ) );
		}
		return array(
			'ok'       => true,
			'document' => minn_admin_wcpdf_row( $fresh ),
			/* translators: %s: the document's name, e.g. "Invoice". */
			'message'  => sprintf( __( '%s regenerated.', 'minn-admin' ), wp_strip_all_tags( (string) $fresh->get_title() ) ),
		);
	},
) );

// Delete: the plugin removes the document's number, date, notes and settings
// from the order. Done only once it no longer reads back.
minn_admin_endpoint( $minn_admin_wcpdf_doc, array(
	'active'  => 'minn_admin_wcpdf_active',
	'methods' => 'DELETE',
	'gate'    => 'minn_admin_wcpdf_order_gate',
	'run'     => function ( WP_REST_Request $r, array $ctx ) {
		$type = Minn_Admin::path_param( $r, 'type' );
		$t    = minn_admin_wcpdf_target( $ctx['id'], $type );
		if ( is_wp_error( $t ) ) {
			return $t;
		}
		list( $order, $document ) = $t;
		$title = wp_strip_all_tags( (string) $document->get_title() );
		$document->delete( $order );
		$fresh = minn_admin_wcpdf_fresh( $ctx['id'], $type );
		if ( $fresh && $fresh->exists() ) {
			return new WP_Error( 'minn_failed', __( 'The plugin did not delete the document. Nothing was reported as done.', 'minn-admin' ), array( 'status' => 500 ) );
		}
		// No document when the plugin no longer offers it on this order (its
		// settings hold it back for the status until it exists).
		return array(
			'ok'       => true,
			'document' => $fresh ? minn_admin_wcpdf_row( $fresh ) : null,
			/* translators: %s: the document's name, e.g. "Invoice". */
			'message'  => sprintf( __( '%s deleted.', 'minn-admin' ), $title ),
		);
	},
) );
unset( $minn_admin_wcpdf_doc );
