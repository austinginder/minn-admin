<?php
/**
 * Bundled adapter: Simple History.
 *
 * Simple History 5.x ships a full REST API (simple-history/v1/events with
 * standard WP pagination), so this is a pure descriptor — the audit log
 * renders with Minn's generic surface primitives. Visibility follows Simple
 * History's own view capability.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! defined( 'SIMPLE_HISTORY_VERSION' ) ) {
		return $surfaces;
	}

	$surfaces['simple-history'] = array(
		'label'      => 'Activity Log',
		'sub'        => 'Simple History',
		'icon'       => 'clock',
		'cap'        => apply_filters( 'simple_history/view_history_capability', 'edit_pages' ),
		'collection' => array(
			'route'     => 'simple-history/v1/events',
			'pageQuery' => 'per_page=25&page={page}',
			'search'    => 'search={q}',
			'tabs'      => array(
				'param'    => 'loglevels',
				'static'   => array(
					array( 'warning', 'Warnings' ),
					array( 'error', 'Errors' ),
				),
				'allLabel' => 'All',
			),
			'columns'   => array(
				array( 'key' => 'message', 'label' => 'Event', 'format' => 'title' ),
				array( 'key' => 'initiator_data.user_login', 'altKey' => 'initiator', 'label' => 'Who' ),
				array( 'key' => 'loglevel', 'label' => 'Level', 'format' => 'pill' ),
				array( 'key' => 'date_gmt', 'label' => 'When', 'format' => 'ago' ),
			),
			'detail'    => array(
				'skip' => array(
					'message_html', 'message_uninterpolated', 'details_html', 'details_data',
					'context', 'ip_addresses', 'action_links', 'occasions_id', 'sticky',
					'sticky_appended', 'backfilled', 'ai_origin', 'via', 'link', 'permalink',
					'message_key', 'date_local', 'subsequent_occasions_count',
				),
			),
		),
	);
	return $surfaces;
} );
