<?php
/**
 * Bundled adapter: Gravity Forms.
 *
 * Pure descriptor — Gravity Forms ships its own REST API (gf/v2) with cookie
 * auth, so no shim is needed. Entries are listed per form (tabs), with a
 * detail view that resolves field labels from the form schema, and a Trash
 * action.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! class_exists( 'GFAPI' ) ) {
		return $surfaces;
	}

	// Gravity Forms only registers its gf/v2 routes when the REST API is
	// enabled (Forms → Settings → REST API), so hide the surface until then.
	$webapi = get_option( 'gravityformsaddon_gravityformswebapi_settings' );
	if ( empty( $webapi['enabled'] ) ) {
		return $surfaces;
	}

	$surfaces['gravity-forms'] = array(
		'label'      => 'Forms',
		'sub'        => 'Gravity Forms',
		'icon'       => 'inbox',
		'cap'        => 'gravityforms_view_entries',
		'collection' => array(
			'route'     => 'gf/v2/forms/{tab}/entries',
			'allRoute'  => 'gf/v2/entries',
			'query'     => 'sorting[key]=date_created&sorting[direction]=DESC',
			'pageQuery' => 'paging[page_size]=25&paging[current_page]={page}',
			// gf/v2 takes search criteria as a JSON string; key 0 = any field.
			'search'    => array(
				'param' => 'search',
				'json'  => array( 'field_filters' => array( array( 'key' => 0, 'value' => '{q}', 'operator' => 'contains' ) ) ),
			),
			'itemsKey'  => 'entries',
			'totalKey'  => 'total_count',
			'tabs'      => array(
				'route'    => 'gf/v2/forms',
				'valueKey' => 'id',
				'labelKey' => 'title',
				'allLabel' => 'All entries',
			),
			'columns'   => array(
				array( 'key' => '_summary', 'label' => 'Entry', 'format' => 'entry-summary' ),
				array( 'key' => 'status', 'label' => 'Status', 'format' => 'pill' ),
				array( 'key' => 'date_created', 'label' => 'Date', 'format' => 'ago' ),
			),
			'detail'    => array(
				'labels' => array(
					'route'    => 'gf/v2/forms/{form_id}',
					'itemsKey' => 'fields',
					'valueKey' => 'id',
					'labelKey' => 'label',
				),
				'skip'   => array( 'is_starred', 'is_read', 'is_fulfilled', 'currency', 'payment_status', 'payment_date', 'payment_amount', 'payment_method', 'transaction_id', 'transaction_type', 'user_agent', 'status', 'post_id' ),
			),
			'actions'   => array(
				array(
					'label'   => 'Trash entry',
					'method'  => 'DELETE',
					'route'   => 'gf/v2/entries/{id}',
					'confirm' => 'Move this entry to trash?',
					'danger'  => true,
				),
			),
		),
	);
	return $surfaces;
} );
