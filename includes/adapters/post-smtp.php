<?php
/**
 * Bundled adapter: Post SMTP.
 *
 * Post SMTP 2.x logs to {prefix}post_smtp_logs (all-longtext columns plus a
 * BIGINT `time` that is current_time('timestamp') — a WP-LOCAL epoch, the
 * same trap as Aryo Activity Log, so it's shifted by gmt_offset before the
 * ISO string is emitted). `success` holds '' / '1' for delivered mail and
 * the error text otherwise. Recipient columns can hold serialized arrays —
 * addresses are pulled with a regex, never unserialized. Read-only.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_post_smtp_active() {
	return defined( 'POST_SMTP_VER' ) || class_exists( 'PostmanOptions' );
}

/** Email addresses out of a maybe-serialized recipient blob. */
function minn_admin_post_smtp_recipients( $raw ) {
	if ( ! $raw ) {
		return '';
	}
	if ( ! preg_match_all( '/[\w.+\-]+@[\w.\-]+\.[A-Za-z]{2,}/', (string) $raw, $m ) ) {
		return '';
	}
	$emails = array_values( array_unique( $m[0] ) );
	$out    = implode( ', ', array_slice( $emails, 0, 2 ) );
	if ( count( $emails ) > 2 ) {
		$out .= ' +' . ( count( $emails ) - 2 );
	}
	return $out;
}

/** WP-local epoch → ISO-8601 UTC (rule: hist-time columns store local). */
function minn_admin_post_smtp_iso( $local_epoch ) {
	$offset = (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS;
	return gmdate( 'Y-m-d\TH:i:s\Z', (int) $local_epoch - (int) $offset );
}

function minn_admin_post_smtp_status( $success ) {
	return ( '' === (string) $success || '1' === (string) $success ) ? 'sent' : 'failed';
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_post_smtp_active() ) {
		return $surfaces;
	}

	$surfaces['post-smtp'] = array(
		'label'      => 'Email',
		'sub'        => 'Post SMTP',
		'icon'       => 'send',
		'cap'        => 'manage_options',
		'family'     => 'mail',
		'collection' => array(
			'route'     => 'minn-admin/v1/post-smtp/emails',
			'pageQuery' => 'per_page=25&page={page}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'tabs'      => array(
				'param'  => 'status',
				'static' => array(
					array( 'sent', 'Sent' ),
					array( 'failed', 'Failed' ),
				),
				'allLabel' => 'All',
			),
			'columns'   => array(
				array( 'key' => 'subject', 'label' => 'Subject', 'format' => 'title' ),
				array( 'key' => 'to', 'label' => 'To', 'format' => 'text' ),
				array( 'key' => 'status', 'label' => 'Status', 'format' => 'pill' ),
				array( 'key' => 'date', 'label' => 'Date', 'format' => 'ago', 'utc' => true ),
			),
			'detail'    => array(
				'detailRoute' => 'minn-admin/v1/post-smtp/emails/{id}',
				'messageKey'  => 'message',
				'skip'        => array( 'message' ),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_post_smtp_active() ) {
		return;
	}

	$perm  = function () {
		return current_user_can( 'manage_options' );
	};
	$table = $GLOBALS['wpdb']->prefix . 'post_smtp_logs';

	register_rest_route( 'minn-admin/v1', '/post-smtp/emails', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) use ( $table ) {
			global $wpdb;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return rest_ensure_response( array( 'items' => array(), 'total' => 0 ) );
			}
			$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
			$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
			$status   = sanitize_key( (string) $request->get_param( 'status' ) );

			$sent_sql = "(success IS NULL OR success = '' OR success = '1')";
			$where    = '';
			if ( 'sent' === $status ) {
				$where = "WHERE {$sent_sql}";
			} elseif ( 'failed' === $status ) {
				$where = "WHERE NOT {$sent_sql}";
			}
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" ); // phpcs:ignore
			$rows  = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, original_subject, original_to, to_header, success, time FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore
				$per_page,
				( $page - 1 ) * $per_page
			) );

			$items = array_map( function ( $row ) {
				return array(
					'id'      => (int) $row->id,
					'subject' => $row->original_subject,
					'to'      => minn_admin_post_smtp_recipients( $row->original_to ?: $row->to_header ),
					'status'  => minn_admin_post_smtp_status( $row->success ),
					'date'    => minn_admin_post_smtp_iso( $row->time ),
				);
			}, $rows ? $rows : array() );

			return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/post-smtp/emails/(?P<id>\d+)', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) use ( $table ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, original_subject, original_to, to_header, from_header, original_message, success, solution, transport_uri, time FROM {$table} WHERE id = %d", // phpcs:ignore
				(int) $request['id']
			) );
			if ( ! $row ) {
				return new WP_Error( 'not_found', 'Email not found', array( 'status' => 404 ) );
			}
			$status = minn_admin_post_smtp_status( $row->success );
			return rest_ensure_response( array(
				'id'        => (int) $row->id,
				'subject'   => $row->original_subject,
				'to'        => minn_admin_post_smtp_recipients( $row->original_to ?: $row->to_header ),
				'from'      => minn_admin_post_smtp_recipients( $row->from_header ),
				'status'    => $status,
				'error'     => 'failed' === $status ? (string) $row->success : '',
				'solution'  => (string) $row->solution,
				'transport' => (string) $row->transport_uri,
				'date'      => minn_admin_post_smtp_iso( $row->time ),
				'message'   => $row->original_message,
			) );
		},
	) );
} );
