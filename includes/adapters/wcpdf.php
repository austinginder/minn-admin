<?php
/**
 * PDF Invoices & Packing Slips for WooCommerce (WP Overnight, 500k+) —
 * per-document Download buttons on Minn's order detail modal.
 *
 * The plugin's admin-ajax endpoint (action=generate_wpo_wcpdf) streams the
 * PDF and enforces its own permission model: user_can_manage_document
 * (edit_shop_orders) plus a nonce for the generate_wpo_wcpdf action, the same
 * one its own order-list buttons ride. Minn only hands the client the enabled
 * document types and that nonce at boot; the click is a plain link into the
 * plugin's endpoint.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Boot payload for the order modal: enabled PDF documents the current user
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
