<?php
/**
 * Custom REST endpoints for Minn Admin (namespace minn-admin/v1).
 *
 * Everything the app can't get from core wp/v2 routes lives here:
 * dashboard overview, notifications, plugin update info and bulk updates.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

class Minn_Admin_REST {

	const NS = 'minn-admin/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			self::NS,
			'/overview',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'overview' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'days' => array(
						'type'    => 'integer',
						'default' => 30,
						'minimum' => 7,
						'maximum' => 90,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/notifications',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'notifications' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		register_rest_route(
			self::NS,
			'/notifications/read',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'notifications_read' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		register_rest_route(
			self::NS,
			'/plugin-updates',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'plugin_updates' ),
				'permission_callback' => function () {
					return current_user_can( 'update_plugins' );
				},
			)
		);

		$sessions_perm = function ( WP_REST_Request $request ) {
			$uid = (int) $request['id'];
			return get_current_user_id() === $uid || current_user_can( 'edit_users' );
		};

		register_rest_route(
			self::NS,
			'/users/(?P<id>\d+)/sessions',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'user_sessions' ),
					'permission_callback' => $sessions_perm,
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'destroy_all_sessions' ),
					'permission_callback' => $sessions_perm,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/users/(?P<id>\d+)/sessions/(?P<verifier>[a-f0-9]{40,64})',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'destroy_session' ),
				'permission_callback' => $sessions_perm,
			)
		);

		register_rest_route(
			self::NS,
			'/plugins/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'search_plugins' ),
				'permission_callback' => function () {
					return current_user_can( 'install_plugins' );
				},
				'args'                => array(
					'q' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/plugins/upload',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'upload_plugin' ),
				'permission_callback' => function () {
					return current_user_can( 'install_plugins' ) && current_user_can( 'upload_files' );
				},
			)
		);

		register_rest_route(
			self::NS,
			'/plugins/update',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'update_single_plugin' ),
				'permission_callback' => function () {
					return current_user_can( 'update_plugins' );
				},
				'args'                => array(
					'plugin' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/plugins/update-all',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'update_all_plugins' ),
				'permission_callback' => function () {
					return current_user_can( 'update_plugins' );
				},
			)
		);
	}

	/**
	 * Dashboard: stat cards, activity chart buckets and a recent-activity feed.
	 */
	public static function overview( WP_REST_Request $request ) {
		global $wpdb;

		$days = (int) $request['days'];

		$posts    = wp_count_posts( 'post' );
		$pages    = wp_count_posts( 'page' );
		$comments = wp_count_comments();
		$media    = wp_count_posts( 'attachment' );

		$stats = array(
			array(
				'label' => 'Published posts',
				'value' => number_format_i18n( (int) $posts->publish ),
				'delta' => (int) $posts->draft . ' draft' . ( 1 === (int) $posts->draft ? '' : 's' ),
				'up'    => null,
			),
			array(
				'label' => 'Pages',
				'value' => number_format_i18n( (int) $pages->publish ),
				'delta' => 'published',
				'up'    => null,
			),
			array(
				'label' => 'Comments',
				'value' => number_format_i18n( (int) $comments->approved ),
				'delta' => (int) $comments->moderated . ' pending',
				'up'    => (int) $comments->moderated > 0,
			),
			array(
				'label' => 'Media files',
				'value' => number_format_i18n( (int) $media->inherit ),
				'delta' => size_format( self::uploads_size(), 1 ) . ' used',
				'up'    => null,
			),
		);

		// Activity chart: posts published + comments received per bucket.
		$bucket_days = $days > 45 ? 7 : 1;
		$buckets     = (int) ceil( $days / $bucket_days );
		$series      = array_fill( 0, $buckets, 0 );
		$since       = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );

		$post_dates = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_date_gmt FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('post','page') AND post_date_gmt >= %s",
				$since
			)
		);
		$comment_dates = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT comment_date_gmt FROM {$wpdb->comments} WHERE comment_date_gmt >= %s",
				$since
			)
		);
		foreach ( array_merge( $post_dates, $comment_dates ) as $date ) {
			$age = time() - strtotime( $date . ' UTC' );
			$idx = $buckets - 1 - (int) floor( $age / ( $bucket_days * DAY_IN_SECONDS ) );
			if ( $idx >= 0 && $idx < $buckets ) {
				$series[ $idx ]++;
			}
		}

		$chart = array();
		foreach ( $series as $i => $count ) {
			$offset  = ( $buckets - 1 - $i ) * $bucket_days;
			$label   = 1 === $bucket_days
				? date_i18n( 'M j', time() - $offset * DAY_IN_SECONDS )
				: 'Week of ' . date_i18n( 'M j', time() - ( $offset + $bucket_days - 1 ) * DAY_IN_SECONDS );
			$chart[] = array(
				'label' => $label . ' · ' . $count . ' event' . ( 1 === $count ? '' : 's' ),
				'value' => $count,
			);
		}

		// Recent activity feed.
		$activity = array();

		$recent_posts = get_posts(
			array(
				'post_type'   => array( 'post', 'page' ),
				'post_status' => array( 'publish', 'draft', 'future', 'pending' ),
				'numberposts' => 5,
				'orderby'     => 'modified',
			)
		);
		foreach ( $recent_posts as $p ) {
			$author = get_the_author_meta( 'display_name', $p->post_author );
			$verb   = 'publish' === $p->post_status ? 'published' : ( 'future' === $p->post_status ? 'scheduled' : 'drafted' );
			$activity[] = array(
				'text'  => sprintf( '%s %s “%s”', $author, $verb, get_the_title( $p ) ),
				'time'  => strtotime( $p->post_modified_gmt . ' UTC' ),
				'color' => 'publish' === $p->post_status ? 'green' : ( 'future' === $p->post_status ? 'blue' : 'accent' ),
			);
		}

		$recent_comments = get_comments( array( 'number' => 3, 'status' => 'all' ) );
		foreach ( $recent_comments as $c ) {
			$pending = '0' === $c->comment_approved;
			$activity[] = array(
				'text'  => sprintf(
					$pending ? 'Comment from %s awaiting moderation on “%s”' : '%s commented on “%s”',
					$c->comment_author ? $c->comment_author : 'Anonymous',
					get_the_title( $c->comment_post_ID )
				),
				'time'  => strtotime( $c->comment_date_gmt . ' UTC' ),
				'color' => $pending ? 'amber' : 'blue',
			);
		}

		usort( $activity, function ( $a, $b ) {
			return $b['time'] - $a['time'];
		} );
		$activity = array_slice( $activity, 0, 6 );
		foreach ( $activity as &$item ) {
			$item['time'] = sprintf( '%s ago', human_time_diff( $item['time'] ) );
		}

		return rest_ensure_response(
			array(
				'stats'    => $stats,
				'chart'    => $chart,
				'activity' => $activity,
				'greeting' => self::greeting(),
			)
		);
	}

	private static function greeting() {
		$hour = (int) current_time( 'G' );
		if ( $hour < 12 ) {
			return 'Good morning';
		}
		if ( $hour < 17 ) {
			return 'Good afternoon';
		}
		return 'Good evening';
	}

	/**
	 * Total size of the uploads directory, cached for 12 hours.
	 */
	private static function uploads_size() {
		$size = get_transient( 'minn_admin_uploads_size' );
		if ( false !== $size ) {
			return (int) $size;
		}
		$uploads = wp_get_upload_dir();
		$size    = 0;
		if ( is_dir( $uploads['basedir'] ) ) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $uploads['basedir'], FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $file ) {
				$size += $file->getSize();
			}
		}
		set_transient( 'minn_admin_uploads_size', $size, 12 * HOUR_IN_SECONDS );
		return $size;
	}

	/**
	 * Notification feed: pending comments, recent comments, plugin/core updates.
	 */
	public static function notifications() {
		$read_at = (int) get_user_meta( get_current_user_id(), 'minn_admin_notif_read_at', true );
		$items   = array();

		if ( current_user_can( 'moderate_comments' ) ) {
			foreach ( get_comments( array( 'status' => 'hold', 'number' => 5 ) ) as $c ) {
				$items[] = array(
					'id'    => 'comment-' . $c->comment_ID,
					'kind'  => 'comments',
					'icon'  => '💬',
					'title' => sprintf( 'New comment from %s awaiting moderation on “%s”', $c->comment_author ?: 'Anonymous', get_the_title( $c->comment_post_ID ) ),
					'time'  => strtotime( $c->comment_date_gmt . ' UTC' ),
				);
			}
		}
		foreach ( get_comments( array( 'status' => 'approve', 'number' => 3 ) ) as $c ) {
			$items[] = array(
				'id'    => 'comment-' . $c->comment_ID,
				'kind'  => 'comments',
				'icon'  => '💬',
				'title' => sprintf( '%s commented on “%s”', $c->comment_author ?: 'Anonymous', get_the_title( $c->comment_post_ID ) ),
				'time'  => strtotime( $c->comment_date_gmt . ' UTC' ),
			);
		}

		if ( current_user_can( 'update_plugins' ) ) {
			$updates = get_site_transient( 'update_plugins' );
			$checked = $updates && ! empty( $updates->last_checked ) ? (int) $updates->last_checked : time();
			if ( $updates && ! empty( $updates->response ) ) {
				$all_plugins = get_plugins();
				foreach ( $updates->response as $file => $data ) {
					$name    = isset( $all_plugins[ $file ]['Name'] ) ? $all_plugins[ $file ]['Name'] : $file;
					$items[] = array(
						'id'    => 'plugin-' . $file . '-' . $data->new_version,
						'kind'  => 'updates',
						'icon'  => '⬆',
						'title' => sprintf( '%s %s is available to install', $name, $data->new_version ),
						'time'  => $checked,
					);
				}
			}
		}

		if ( current_user_can( 'update_core' ) ) {
			$core = get_site_transient( 'update_core' );
			if ( $core && ! empty( $core->updates ) && 'upgrade' === $core->updates[0]->response ) {
				$items[] = array(
					'id'    => 'core-' . $core->updates[0]->version,
					'kind'  => 'system',
					'icon'  => '🛡',
					'title' => sprintf( 'WordPress %s is available', $core->updates[0]->version ),
					'time'  => (int) $core->last_checked,
				);
			}
		}

		if ( current_user_can( 'list_users' ) ) {
			$users = get_users(
				array(
					'orderby'    => 'registered',
					'order'      => 'DESC',
					'number'     => 2,
					'date_query' => array( array( 'after' => '7 days ago' ) ),
				)
			);
			foreach ( $users as $u ) {
				$items[] = array(
					'id'    => 'user-' . $u->ID,
					'kind'  => 'system',
					'icon'  => '👤',
					'title' => sprintf( 'New user registered: %s', $u->display_name ),
					'time'  => strtotime( $u->user_registered . ' UTC' ),
				);
			}
		}

		usort( $items, function ( $a, $b ) {
			return $b['time'] - $a['time'];
		} );

		$read_ids = get_user_meta( get_current_user_id(), 'minn_admin_notif_read_ids', true );
		$read_ids = is_array( $read_ids ) ? $read_ids : array();

		$today = strtotime( 'today', current_time( 'timestamp' ) ) - (int) ( get_option( 'gmt_offset' ) * HOUR_IN_SECONDS );
		foreach ( $items as &$item ) {
			$item['unread'] = $item['time'] > $read_at && ! in_array( $item['id'], $read_ids, true );
			$item['group']  = $item['time'] >= $today ? 'Today' : 'Earlier';
			$item['ago']    = sprintf( '%s ago', human_time_diff( $item['time'] ) );
		}

		return rest_ensure_response( array( 'items' => $items ) );
	}

	/**
	 * Mark one notification read (body: {id}) or everything read (no id).
	 */
	public static function notifications_read( WP_REST_Request $request ) {
		$uid = get_current_user_id();
		$id  = sanitize_text_field( (string) $request->get_param( 'id' ) );
		if ( $id ) {
			$ids   = get_user_meta( $uid, 'minn_admin_notif_read_ids', true );
			$ids   = is_array( $ids ) ? $ids : array();
			$ids[] = $id;
			update_user_meta( $uid, 'minn_admin_notif_read_ids', array_slice( array_unique( $ids ), -200 ) );
		} else {
			update_user_meta( $uid, 'minn_admin_notif_read_at', time() );
			delete_user_meta( $uid, 'minn_admin_notif_read_ids' );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Map of plugin_file => new_version for available updates.
	 */
	public static function plugin_updates() {
		$updates = get_site_transient( 'update_plugins' );
		$map     = array();
		if ( $updates && ! empty( $updates->response ) ) {
			foreach ( $updates->response as $file => $data ) {
				$map[ $file ] = $data->new_version;
			}
		}
		return rest_ensure_response( array( 'updates' => $map ) );
	}

	/**
	 * Active login sessions for a user, from the session_tokens user meta.
	 */
	public static function user_sessions( WP_REST_Request $request ) {
		$uid    = (int) $request['id'];
		$tokens = get_user_meta( $uid, 'session_tokens', true );
		$tokens = is_array( $tokens ) ? $tokens : array();

		// Flag the requester's own current session so the UI can warn.
		$current = '';
		if ( get_current_user_id() === $uid && function_exists( 'wp_get_session_token' ) ) {
			$token   = wp_get_session_token();
			$current = function_exists( 'hash' ) ? hash( 'sha256', $token ) : sha1( $token );
		}

		$items = array();
		foreach ( $tokens as $verifier => $session ) {
			$items[] = array(
				'verifier'   => $verifier,
				'ip'         => isset( $session['ip'] ) ? $session['ip'] : '',
				'ua'         => isset( $session['ua'] ) ? $session['ua'] : '',
				'login'      => isset( $session['login'] ) ? (int) $session['login'] : 0,
				'expiration' => isset( $session['expiration'] ) ? (int) $session['expiration'] : 0,
				'current'    => $verifier === $current,
			);
		}
		usort( $items, function ( $a, $b ) {
			return $b['login'] - $a['login'];
		} );

		return rest_ensure_response( array( 'sessions' => $items ) );
	}

	/**
	 * Destroy every session for a user (keeps the requester's own current
	 * session when acting on themselves, so they aren't logged out mid-action).
	 */
	public static function destroy_all_sessions( WP_REST_Request $request ) {
		$uid     = (int) $request['id'];
		$manager = WP_Session_Tokens::get_instance( $uid );
		if ( get_current_user_id() === $uid ) {
			$manager->destroy_others( wp_get_session_token() );
		} else {
			$manager->destroy_all();
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Destroy a single session by its verifier hash.
	 */
	public static function destroy_session( WP_REST_Request $request ) {
		$uid      = (int) $request['id'];
		$verifier = $request['verifier'];
		$tokens   = get_user_meta( $uid, 'session_tokens', true );
		if ( ! is_array( $tokens ) || ! isset( $tokens[ $verifier ] ) ) {
			return new WP_Error( 'not_found', 'Session not found', array( 'status' => 404 ) );
		}
		unset( $tokens[ $verifier ] );
		update_user_meta( $uid, 'session_tokens', $tokens );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Search the wordpress.org plugin directory (proxied server-side so the
	 * app never talks to external hosts).
	 */
	public static function search_plugins( WP_REST_Request $request ) {
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$res = plugins_api(
			'query_plugins',
			array(
				'search'   => sanitize_text_field( $request['q'] ),
				'per_page' => 12,
				'fields'   => array(
					'icons'             => true,
					'short_description' => true,
					'active_installs'   => true,
					'rating'            => true,
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		// Directory names of installed plugins, for "Installed" labels.
		$installed = array();
		foreach ( array_keys( get_plugins() ) as $file ) {
			$installed[ dirname( $file ) ] = $file;
		}

		$items = array();
		foreach ( (array) $res->plugins as $p ) {
			$p       = (array) $p;
			$icons   = isset( $p['icons'] ) ? (array) $p['icons'] : array();
			$items[] = array(
				'slug'        => $p['slug'],
				'name'        => wp_strip_all_tags( $p['name'] ),
				'description' => wp_strip_all_tags( isset( $p['short_description'] ) ? $p['short_description'] : '' ),
				'installs'    => isset( $p['active_installs'] ) ? (int) $p['active_installs'] : 0,
				'rating'      => isset( $p['rating'] ) ? (int) $p['rating'] : 0,
				'version'     => isset( $p['version'] ) ? $p['version'] : '',
				'icon'        => isset( $icons['1x'] ) ? $icons['1x'] : ( isset( $icons['default'] ) ? $icons['default'] : '' ),
				'installed'   => isset( $installed[ $p['slug'] ] ) ? $installed[ $p['slug'] ] : null,
			);
		}
		return rest_ensure_response( array( 'plugins' => $items ) );
	}

	/**
	 * Install a plugin from an uploaded zip.
	 */
	public static function upload_plugin( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) || empty( $files['file']['tmp_name'] ) ) {
			return new WP_Error( 'no_file', 'No file uploaded.', array( 'status' => 400 ) );
		}
		if ( ! preg_match( '/\.zip$/i', $files['file']['name'] ) ) {
			return new WP_Error( 'not_zip', 'Plugin uploads must be .zip files.', array( 'status' => 400 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		$package = wp_tempnam( $files['file']['name'] );
		if ( ! $package || ! move_uploaded_file( $files['file']['tmp_name'], $package ) ) {
			return new WP_Error( 'move_failed', 'Could not store the upload.', array( 'status' => 500 ) );
		}

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $package );
		@unlink( $package ); // phpcs:ignore

		if ( ! $result || is_wp_error( $result ) ) {
			$errors = $skin->get_error_messages();
			return new WP_Error( 'install_failed', $errors ? implode( ' ', (array) $errors ) : 'Install failed.', array( 'status' => 500 ) );
		}

		return rest_ensure_response(
			array(
				'installed' => true,
				'plugin'    => $upgrader->plugin_info(),
			)
		);
	}

	/**
	 * Update one plugin by its plugin file (e.g. "akismet/akismet.php").
	 */
	public static function update_single_plugin( WP_REST_Request $request ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		$file = sanitize_text_field( $request['plugin'] );

		wp_update_plugins();
		$updates = get_site_transient( 'update_plugins' );
		if ( ! $updates || empty( $updates->response[ $file ] ) ) {
			return new WP_Error( 'no_update', 'No update available for that plugin.', array( 'status' => 400 ) );
		}

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $file );

		if ( ! $result || is_wp_error( $result ) ) {
			$errors = $skin->get_error_messages();
			return new WP_Error( 'update_failed', $errors ? implode( ' ', (array) $errors ) : 'Update failed.', array( 'status' => 500 ) );
		}

		$plugins = get_plugins();
		return rest_ensure_response(
			array(
				'updated' => true,
				'version' => isset( $plugins[ $file ]['Version'] ) ? $plugins[ $file ]['Version'] : '',
			)
		);
	}

	/**
	 * Run all pending plugin updates.
	 */
	public static function update_all_plugins() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		wp_update_plugins();
		$updates = get_site_transient( 'update_plugins' );
		if ( ! $updates || empty( $updates->response ) ) {
			return rest_ensure_response( array( 'updated' => array() ) );
		}

		$files    = array_keys( $updates->response );
		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$results  = $upgrader->bulk_upgrade( $files );

		$updated = array();
		$failed  = array();
		foreach ( (array) $results as $file => $result ) {
			if ( $result && ! is_wp_error( $result ) ) {
				$updated[] = $file;
			} else {
				$failed[] = $file;
			}
		}

		return rest_ensure_response(
			array(
				'updated' => $updated,
				'failed'  => $failed,
				'errors'  => $skin->get_error_messages(),
			)
		);
	}
}
