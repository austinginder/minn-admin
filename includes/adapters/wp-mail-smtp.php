<?php
/**
 * Bundled adapter: WP Mail SMTP.
 *
 * The free plugin stores no full email log (that's Pro) — what it does keep
 * is {prefix}wpmailsmtp_debug_events: delivery errors and, when verbose
 * debugging is on, send attempts. That is exactly the "did my mail fail and
 * why" daily-work question, so the surface lists those events read-only.
 * event_type 0 = error, 1 = debug. created_at is a MySQL CURRENT_TIMESTAMP —
 * the DB server clock, UTC on the stacks this targets (verified against a
 * seeded row) — so it's emitted as an ISO-8601 Z string. The `initiator`
 * column is a {"file","line"} JSON blob, reduced to basename:line. The
 * table only exists after the plugin's migration runs; routes answer empty
 * until then.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_wp_mail_smtp_active() {
	return defined( 'WPMS_PLUGIN_VER' ) || function_exists( 'wp_mail_smtp' );
}

/** "2026-07-10 02:01:50" (DB clock, UTC) → ISO-8601 Z. */
function minn_admin_wp_mail_smtp_iso( $mysql ) {
	$ts = strtotime( (string) $mysql . ' UTC' );
	return $ts ? gmdate( 'Y-m-d\TH:i:s\Z', $ts ) : (string) $mysql;
}

/** {"file":"…","line":N} → "file.php:N"; anything else passes through. */
function minn_admin_wp_mail_smtp_initiator( $raw ) {
	$data = json_decode( (string) $raw, true );
	if ( is_array( $data ) && ! empty( $data['file'] ) ) {
		return basename( str_replace( '\\', '/', $data['file'] ) ) . ( isset( $data['line'] ) ? ':' . (int) $data['line'] : '' );
	}
	return (string) $raw;
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_wp_mail_smtp_active() ) {
		return $surfaces;
	}

	$surfaces['wp-mail-smtp'] = array(
		'label'      => 'Email',
		'sub'        => 'WP Mail SMTP',
		'icon'       => 'send',
		'cap'        => 'manage_options',
		'family'     => 'mail',
		'collection' => array(
			'route'     => 'minn-admin/v1/wp-mail-smtp/events',
			'pageQuery' => 'per_page=25&page={page}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'tabs'      => array(
				'param'  => 'type',
				'static' => array(
					array( 'error', 'Errors' ),
					array( 'debug', 'Debug' ),
				),
				'allLabel' => 'All',
			),
			'columns'   => array(
				array( 'key' => 'content', 'label' => 'Event', 'format' => 'title' ),
				array( 'key' => 'initiator', 'label' => 'Initiator', 'format' => 'text' ),
				array( 'key' => 'type', 'label' => 'Type', 'format' => 'pill' ),
				array( 'key' => 'created_at', 'label' => 'Date', 'format' => 'ago' ),
			),
			'detail'    => array(
				'detailRoute' => 'minn-admin/v1/wp-mail-smtp/events/{id}',
				'messageKey'  => 'message',
				'skip'        => array( 'message' ),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_wp_mail_smtp_active() ) {
		return;
	}

	$perm  = function () {
		return current_user_can( 'manage_options' );
	};
	$table = $GLOBALS['wpdb']->prefix . 'wpmailsmtp_debug_events';

	register_rest_route( 'minn-admin/v1', '/wp-mail-smtp/events', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) use ( $table ) {
			global $wpdb;
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
				return rest_ensure_response( array( 'items' => array(), 'total' => 0 ) );
			}
			$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
			$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
			$type     = sanitize_key( (string) $request->get_param( 'type' ) );

			$where = '';
			if ( 'error' === $type ) {
				$where = 'WHERE event_type = 0';
			} elseif ( 'debug' === $type ) {
				$where = 'WHERE event_type != 0';
			}
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where}" ); // phpcs:ignore
			$rows  = $wpdb->get_results( $wpdb->prepare(
				"SELECT id, content, initiator, event_type, created_at FROM {$table} {$where} ORDER BY id DESC LIMIT %d OFFSET %d", // phpcs:ignore
				$per_page,
				( $page - 1 ) * $per_page
			) );

			$items = array_map( function ( $row ) {
				$content = trim( preg_replace( '/\s+/', ' ', (string) $row->content ) );
				if ( function_exists( 'mb_substr' ) && mb_strlen( $content ) > 120 ) {
					$content = mb_substr( $content, 0, 119 ) . '…';
				}
				return array(
					'id'         => (int) $row->id,
					'content'    => $content,
					'initiator'  => minn_admin_wp_mail_smtp_initiator( $row->initiator ),
					'type'       => 0 === (int) $row->event_type ? 'error' : 'debug',
					'created_at' => minn_admin_wp_mail_smtp_iso( $row->created_at ),
				);
			}, $rows ? $rows : array() );

			return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wp-mail-smtp/events/(?P<id>\d+)', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) use ( $table ) {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, content, initiator, event_type, created_at FROM {$table} WHERE id = %d", // phpcs:ignore
				(int) $request['id']
			) );
			if ( ! $row ) {
				return new WP_Error( 'not_found', 'Event not found', array( 'status' => 404 ) );
			}
			return rest_ensure_response( array(
				'id'         => (int) $row->id,
				'initiator'  => minn_admin_wp_mail_smtp_initiator( $row->initiator ),
				'type'       => 0 === (int) $row->event_type ? 'error' : 'debug',
				'created_at' => minn_admin_wp_mail_smtp_iso( $row->created_at ),
				'message'    => (string) $row->content,
			) );
		},
	) );
} );
