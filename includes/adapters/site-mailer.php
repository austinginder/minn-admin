<?php
/**
 * Bundled adapter: Site Mailer (Wave B).
 *
 * Site Mailer (by Elementor) sends over its own cloud API and logs every send
 * into {prefix}site_mail_logs (free feature). No log REST for third parties,
 * so this is the shim pattern: prefix-scoped SELECTs, delivered/failed tabs,
 * search, single + bulk delete, a status card, and a sections detail (status
 * pill, sandboxed HTML body). Delivery is the cloud's job, so there is no
 * local resend; the card links out to Site Mailer's own log for that. Its
 * `created_at` defaults to the DB CURRENT_TIMESTAMP (DB session zone) —
 * normalized to UTC via the shared minn_admin_db_local_to_utc_iso() helper.
 *
 * last-sweep: 2026-07-17
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_site_mailer_active() {
	return defined( 'SITE_MAILER_VERSION' );
}

function minn_admin_site_mailer_table() {
	global $wpdb;
	return $wpdb->prefix . 'site_mail_logs';
}

/**
 * Site Mailer's status vocabulary → the shared pill classes. Its own tallies
 * treat delivered as good and failed/bounce/dropped as bad (log-entry.php);
 * the remaining states (not sent / rate limit / not valid / unsubscribed) are
 * neutral.
 */
function minn_admin_site_mailer_status( $status ) {
	$s = strtolower( trim( (string) $status ) );
	if ( in_array( $s, array( 'delivered', 'sent' ), true ) ) {
		return 'sent';
	}
	if ( in_array( $s, array( 'failed', 'bounce', 'dropped' ), true ) ) {
		return 'failed';
	}
	return $s ?: 'pending';
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_site_mailer_active() ) {
		return $surfaces;
	}
	$surfaces['site-mailer'] = array(
		'label'      => 'Email',
		'family'     => 'mail',
		'sub'        => 'Site Mailer',
		'icon'       => 'send',
		'cap'        => 'manage_options',
		'status'     => array( 'route' => 'minn-admin/v1/site-mailer/status' ),
		'collection' => array(
			'route'     => 'minn-admin/v1/site-mailer/emails',
			'pageQuery' => 'per_page=25&page={page}',
			'search'    => 'search={q}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'tabs'      => array(
				'param'    => 'status',
				'static'   => array(
					array( 'delivered', 'Delivered' ),
					array( 'failed', 'Failed' ),
				),
				'allLabel' => 'All',
			),
			'columns'   => array(
				array( 'key' => 'subject', 'label' => 'Subject', 'format' => 'title' ),
				array( 'key' => 'to', 'label' => 'To', 'format' => 'text' ),
				array( 'key' => 'status', 'label' => 'Status', 'format' => 'pill' ),
				array( 'key' => 'created_at', 'label' => 'Date', 'format' => 'ago' ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/site-mailer/emails/{id}/view',
			),
			'actions'   => array(
				array(
					'label'   => 'Delete',
					'route'   => 'minn-admin/v1/site-mailer/emails/{id}',
					'method'  => 'DELETE',
					'confirm' => 'Delete this log entry permanently? There is no trash.',
					'danger'  => true,
				),
			),
			'bulk'      => array(
				array(
					'label'   => 'Delete',
					'route'   => 'minn-admin/v1/site-mailer/emails/{id}',
					'method'  => 'DELETE',
					'confirm' => 'Delete the selected log entries permanently?',
					'danger'  => true,
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_site_mailer_active() ) {
		return;
	}
	$perm = function () {
		return current_user_can( 'manage_options' );
	};
	$has_table = function () {
		global $wpdb;
		$t = minn_admin_site_mailer_table();
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
	};

	register_rest_route( 'minn-admin/v1', '/site-mailer/emails', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) use ( $has_table ) {
			if ( ! $has_table() ) {
				return rest_ensure_response( array( 'items' => array(), 'total' => 0 ) );
			}
			global $wpdb;
			$table    = minn_admin_site_mailer_table();
			$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
			$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
			$status   = sanitize_key( (string) $request->get_param( 'status' ) );
			$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$where  = array( '1=1' );
			$params = array();
			// The status ENUM is finer than the two tabs: map tab → members.
			if ( 'delivered' === $status ) {
				$where[] = "status IN ('delivered','sent')";
			} elseif ( 'failed' === $status ) {
				$where[] = "status IN ('failed','bounce','dropped')";
			}
			if ( '' !== $search ) {
				$like    = '%' . $wpdb->esc_like( $search ) . '%';
				$where[] = '( subject LIKE %s OR `to` LIKE %s )';
				array_push( $params, $like, $like );
			}
			$where_sql = 'WHERE ' . implode( ' AND ', $where );
			$count_sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
			$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
			$rows      = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, `to`, subject, status, created_at FROM {$table} {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d",
				array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) )
			) );
			// phpcs:enable
			$items = array_map( function ( $r ) {
				return array(
					'id'         => (int) $r->id,
					'subject'    => (string) $r->subject,
					'to'         => (string) $r->to,
					'status'     => minn_admin_site_mailer_status( $r->status ),
					'created_at' => minn_admin_db_local_to_utc_iso( $r->created_at ),
				);
			}, $rows ? $rows : array() );
			return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/site-mailer/emails/(?P<id>\d+)/view', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$table = minn_admin_site_mailer_table();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $request['id'] ) );
			if ( ! $row ) {
				return new WP_Error( 'not_found', 'Email not found', array( 'status' => 404 ) );
			}
			$delivery = array(
				array( 'label' => 'Status', 'value' => minn_admin_site_mailer_status( $row->status ), 'type' => 'pill' ),
				array( 'label' => 'To', 'value' => (string) $row->to ),
			);
			if ( '' !== (string) $row->source ) {
				$delivery[] = array( 'label' => 'Source', 'value' => (string) $row->source );
			}
			$iso = minn_admin_db_local_to_utc_iso( $row->created_at );
			if ( '' !== $iso ) {
				$delivery[] = array( 'label' => 'Date', 'value' => $iso );
			}
			$body     = (string) $row->message;
			$sections = array(
				array( 'title' => 'Delivery', 'rows' => $delivery ),
				array(
					'title' => 'Message',
					'rows'  => array(
						array( 'label' => 'Subject', 'value' => (string) $row->subject ),
						preg_match( '/<\/?[a-z][^>]*>/i', $body )
							? array( 'label' => 'Body', 'value' => $body, 'type' => 'html-preview' )
							: array( 'label' => 'Body', 'value' => $body, 'type' => 'code' ),
					),
				),
			);
			return rest_ensure_response( array( 'sections' => $sections ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/site-mailer/emails/(?P<id>\d+)', array(
		'methods'             => 'DELETE',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$table = minn_admin_site_mailer_table();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id = %d", (int) $request['id'] ) );
			if ( ! $deleted ) {
				return new WP_Error( 'not_found', 'Email not found', array( 'status' => 404 ) );
			}
			return rest_ensure_response( array( 'deleted' => true, 'message' => 'Log entry deleted.' ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/site-mailer/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () use ( $has_table ) {
			$admin_url = admin_url( 'admin.php?page=site-mailer' );
			if ( ! $has_table() ) {
				return rest_ensure_response( array(
					'rows'    => array( array( 'label' => 'Email log', 'value' => '—', 'hint' => 'Site Mailer has not logged any sends yet' ) ),
					'actions' => array( array( 'label' => 'Open Site Mailer ↗', 'href' => $admin_url ) ),
				) );
			}
			global $wpdb;
			$table     = minn_admin_site_mailer_table();
			$total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore
			$delivered = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status IN ('delivered','sent')" ); // phpcs:ignore
			$failed    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status IN ('failed','bounce','dropped')" ); // phpcs:ignore
			$opened    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE opened = 1" ); // phpcs:ignore

			$db_now = time() + minn_admin_db_utc_offset();
			$since  = gmdate( 'Y-m-d 00:00:00', $db_now - 13 * DAY_IN_SECONDS );
			$byday  = array();
			for ( $i = 13; $i >= 0; $i-- ) {
				$d           = gmdate( 'Y-m-d', $db_now - $i * DAY_IN_SECONDS );
				$byday[ $d ] = array( 'label' => gmdate( 'M j', strtotime( $d ) ), 'value' => 0, 'secondary' => 0 );
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT DATE(created_at) AS d, status, COUNT(*) AS c FROM {$table} WHERE created_at >= %s GROUP BY DATE(created_at), status",
				$since
			) );
			foreach ( (array) $rows as $r ) {
				$d = (string) $r->d;
				if ( ! isset( $byday[ $d ] ) ) {
					continue;
				}
				if ( 'failed' === minn_admin_site_mailer_status( $r->status ) ) {
					$byday[ $d ]['secondary'] += (int) $r->c;
				} else {
					$byday[ $d ]['value'] += (int) $r->c;
				}
			}
			return rest_ensure_response( array(
				'rows'    => array(
					array(
						'label' => 'Logged emails',
						'value' => number_format_i18n( $total ),
						'hint'  => $failed ? number_format_i18n( $failed ) . ' failed' : 'All delivered',
					),
					array(
						'label' => 'Delivered',
						'value' => number_format_i18n( $delivered ),
						'hint'  => $opened ? number_format_i18n( $opened ) . ' opened' : '',
					),
				),
				'chart'   => array(
					'title'     => 'Last 14 days',
					'primary'   => 'Delivered',
					'secondary' => 'Failed',
					'points'    => array_values( $byday ),
				),
				'actions' => array( array( 'label' => 'Open Site Mailer ↗', 'href' => $admin_url ) ),
			) );
		},
	) );
} );
