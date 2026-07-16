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
			'/plugin-meta',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () {
					return current_user_can( 'activate_plugins' );
				},
				'callback'            => function () {
					// wp.org icons + directory URLs already ride the
					// update_plugins transient (core fetched them for the
					// updates screen) — zero extra HTTP, and presence here IS
					// the "this plugin is on wp.org" signal. Keyed by plugin
					// file ("dir/plugin.php").
					$tr = get_site_transient( 'update_plugins' );
					// Plugin toggles wipe this transient (wp_clean_plugins_cache),
					// and Minn may be the only admin this site ever loads — when
					// it's near-empty, prime it ourselves instead of waiting for
					// the next wp-cron check (one attempt per 5 minutes).
					$have = count( (array) ( $tr->response ?? array() ) ) + count( (array) ( $tr->no_update ?? array() ) );
					if ( $have < 5 && ! get_transient( 'minn_plugin_meta_primed' ) ) {
						set_transient( 'minn_plugin_meta_primed', 1, 5 * MINUTE_IN_SECONDS );
						wp_update_plugins();
						$tr = get_site_transient( 'update_plugins' );
					}
					$out = array();
					foreach ( array( 'response', 'no_update' ) as $bucket ) {
						if ( empty( $tr->$bucket ) || ! is_array( $tr->$bucket ) ) {
							continue;
						}
						foreach ( $tr->$bucket as $file => $data ) {
							$data  = (object) $data;
							$icons = isset( $data->icons ) ? (array) $data->icons : array();
							$slug  = isset( $data->slug ) && $data->slug ? $data->slug : dirname( $file );
							$out[ $file ] = array(
								'slug' => $slug,
								'icon' => isset( $icons['svg'] ) ? $icons['svg']
									: ( isset( $icons['2x'] ) ? $icons['2x']
									: ( isset( $icons['1x'] ) ? $icons['1x'] : '' ) ),
								'url'  => isset( $data->url ) && $data->url ? $data->url : 'https://wordpress.org/plugins/' . $slug . '/',
							);
						}
					}
					return rest_ensure_response( $out );
				},
			)
		);

		register_rest_route(
			self::NS,
			'/changelog',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'callback'            => function () {
					// The bundled changelog.md, rendered client-side.
					$file = MINN_ADMIN_DIR . 'changelog.md';
					return rest_ensure_response( array(
						'version'  => MINN_ADMIN_VERSION,
						'markdown' => is_readable( $file ) ? (string) file_get_contents( $file ) : '',
					) );
				},
			)
		);

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
			'/overview/activity',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'overview_activity' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'from' => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$',
					),
					'to'   => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$',
					),
				),
			)
		);

		// Traffic day drill-down: top pages (and optional referrers) for a
		// chart bar's date window. Providers answer minn_admin_traffic_day.
		register_rest_route(
			self::NS,
			'/overview/traffic-day',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'overview_traffic_day' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'from' => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^\d{4}-\d{2}-\d{2}$',
					),
					'to'   => array(
						'type'     => 'string',
						'required' => true,
						'pattern'  => '^\d{4}-\d{2}-\d{2}$',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/templates',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'page_templates' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'type' => array(
						'type'    => 'string',
						'default' => 'page',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/posts/(?P<id>\d+)/restore',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'restore_post' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		// Post locking on core's own _edit_lock meta (wp_set_post_lock /
		// wp_check_post_lock), so Minn, the classic editor and Gutenberg all
		// see each other's locks. POST acquires or refreshes; {"take_over":true}
		// steals, exactly like wp-admin's takeover button.
		register_rest_route(
			self::NS,
			'/posts/(?P<id>\d+)/lock',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'lock_post' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		// Release is its own POST route (not DELETE on /lock): the client frees
		// the lock from pagehide via navigator.sendBeacon, which can only POST
		// and can't set headers — the nonce rides in as a ?_wpnonce query param.
		register_rest_route(
			self::NS,
			'/posts/(?P<id>\d+)/unlock',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'unlock_post' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
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

		// Hide/unhide a captured admin notice in Minn's own digest — the
		// answer for notices whose dismissal only exists as plugin-specific
		// admin-ajax JS that Minn cannot replay (see Minn_Admin_Notices).
		foreach ( array( 'hide', 'unhide' ) as $op ) {
			register_rest_route(
				self::NS,
				'/notices/' . $op,
				array(
					'methods'             => 'POST',
					'permission_callback' => function () {
						return current_user_can( 'edit_posts' );
					},
					'args'                => array(
						'id'    => array(
							'type'     => 'string',
							// Hide requires a 12-char hash; unhide also accepts
							// "all" / "*" / "" to clear every hide (suite reset).
							'required' => 'hide' === $op,
						),
						'clear' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
					'callback'            => function ( WP_REST_Request $request ) use ( $op ) {
						$id = (string) $request['id'];
						if ( 'unhide' === $op && ( ! empty( $request['clear'] ) || in_array( $id, array( '', 'all', '*' ), true ) ) ) {
							Minn_Admin_Notices::unhide( 'all' );
							return rest_ensure_response( array( 'ok' => true, 'cleared' => true ) );
						}
						if ( ! preg_match( '/^[a-f0-9]{12}$/', $id ) ) {
							return new WP_Error( 'bad_id', 'Invalid notice id.', array( 'status' => 400 ) );
						}
						Minn_Admin_Notices::$op( $id );
						return rest_ensure_response( array( 'ok' => true ) );
					},
				)
			);
		}

		// Whitelisted notice ajax (Everest "No, Thanks" / "Allow" and peers):
		// href="#" buttons that only work via admin-ajax JS in wp-admin.
		register_rest_route(
			self::NS,
			'/notices/ajax',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'action'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'args'      => array(
						'type'    => 'object',
						'default' => array(),
					),
					'notice_id' => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'callback'            => function ( WP_REST_Request $request ) {
					$result = Minn_Admin_Notices::run_ajax(
						sanitize_key( $request['action'] ),
						is_array( $request['args'] ) ? $request['args'] : array()
					);
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					// Also hide from Minn's digest so a lagging re-capture
					// does not bounce the row back before the plugin's option sticks.
					$id = preg_replace( '/[^a-f0-9]/', '', (string) $request['notice_id'] );
					if ( strlen( $id ) === 12 ) {
						Minn_Admin_Notices::hide( $id );
					}
					return rest_ensure_response( array( 'ok' => true ) );
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

		// Current user's Minn UI appearance (color scheme). Self only.
		register_rest_route(
			self::NS,
			'/me/appearance',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_my_appearance' ),
					'permission_callback' => function () {
						return is_user_logged_in() && current_user_can( 'edit_posts' );
					},
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'update_my_appearance' ),
					'permission_callback' => function () {
						return is_user_logged_in() && current_user_can( 'edit_posts' );
					},
					'args'                => array(
						'scheme' => array(
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_key',
						),
						'custom' => array(
							'type'     => 'object',
							'required' => false,
						),
						// Legacy fields still accepted and migrated in normalize_appearance.
						'accent' => array(
							'type'     => 'string',
							'required' => false,
						),
					),
				),
			)
		);

		// Users list with session-status filter (active / expired / never).
		// Core wp/v2/users can't filter on session_tokens (serialized meta with
		// nested expiration), so this endpoint classifies tokens in PHP then
		// paginates via WP_User_Query include/exclude.
		// Per-user integration hide/unhide (goal #7). Both answer with the
		// fresh boot slices so one round trip repaints nav + panels + the
		// restore list without a reload.
		foreach ( array( 'hide', 'unhide' ) as $op ) {
			register_rest_route(
				self::NS,
				'/integrations/' . $op,
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'hide' === $op ? 'hide_integration' : 'unhide_integration' ),
					'permission_callback' => function () {
						return is_user_logged_in() && current_user_can( 'edit_posts' );
					},
					'args'                => array(
						'id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => function ( $v ) {
								return preg_replace( '/[^a-z0-9_:\-]/', '', strtolower( (string) $v ) );
							},
						),
					),
				)
			);
		}

		register_rest_route(
			self::NS,
			'/users',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_users' ),
				'permission_callback' => function () {
					return current_user_can( 'list_users' );
				},
				'args'                => array(
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 50,
						'minimum' => 1,
						'maximum' => 100,
					),
					'search'   => array(
						'type'    => 'string',
						'default' => '',
					),
					'roles'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'orderby'  => array(
						'type'    => 'string',
						'default' => 'registered_date',
						'enum'    => array( 'id', 'name', 'email', 'registered_date', 'slug' ),
					),
					'order'    => array(
						'type'    => 'string',
						'default' => 'desc',
						'enum'    => array( 'asc', 'desc' ),
					),
					'session'  => array(
						'type'    => 'string',
						'default' => 'all',
						'enum'    => array( 'all', 'active', 'expired', 'never' ),
					),
				),
			)
		);

		// Password-reset email (wp-admin "Send password reset").
		register_rest_route(
			self::NS,
			'/users/(?P<id>\d+)/reset-password',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'user_reset_password' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_users' );
				},
			)
		);

		// Styled HTML email from Minn Admin to a user.
		register_rest_route(
			self::NS,
			'/users/(?P<id>\d+)/email',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'user_send_email' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_users' );
				},
				'args'                => array(
					'subject' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'message' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		// WooCommerce order helpers (email + resend WC transactional emails).
		// Order CRUD/refunds ride wc/v3; these cover what core WC REST leaves out.
		if ( class_exists( 'WooCommerce' ) ) {
			$order_cap = function () {
				return current_user_can( 'edit_shop_orders' );
			};
			register_rest_route(
				self::NS,
				'/orders/(?P<id>\d+)/email',
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'order_send_email' ),
					'permission_callback' => $order_cap,
					'args'                => array(
						'subject' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'message' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_textarea_field',
						),
					),
				)
			);
			register_rest_route(
				self::NS,
				'/orders/(?P<id>\d+)/emails',
				array(
					array(
						'methods'             => 'GET',
						'callback'            => array( __CLASS__, 'order_list_emails' ),
						'permission_callback' => $order_cap,
					),
					array(
						'methods'             => 'POST',
						'callback'            => array( __CLASS__, 'order_trigger_email' ),
						'permission_callback' => $order_cap,
						'args'                => array(
							'email_id' => array(
								'type'              => 'string',
								'required'          => true,
								'sanitize_callback' => 'sanitize_key',
							),
						),
					),
				)
			);
		}

		register_rest_route(
			self::NS,
			'/themes',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'list_themes' ),
				'permission_callback' => function () {
					return current_user_can( 'switch_themes' );
				},
			)
		);

		foreach ( array( 'activate', 'delete', 'update' ) as $theme_action ) {
			register_rest_route(
				self::NS,
				'/themes/' . $theme_action,
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'theme_' . $theme_action ),
					'permission_callback' => function () use ( $theme_action ) {
						$caps = array(
							'activate' => 'switch_themes',
							'delete'   => 'delete_themes',
							'update'   => 'update_themes',
						);
						return current_user_can( $caps[ $theme_action ] );
					},
					'args'                => array(
						'stylesheet' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				)
			);
		}

		// Terms manager: taxonomy switcher data + merge. Term CRUD itself
		// rides core's own wp/v2 taxonomy routes (create/update/delete with
		// core's per-taxonomy capability checks); only merge is Minn's.
		register_rest_route(
			self::NS,
			'/term-taxonomies',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'term_taxonomies' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		register_rest_route(
			self::NS,
			'/terms/merge',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'merge_terms' ),
				'permission_callback' => function () {
					return is_user_logged_in(); // real check is per-taxonomy in the callback
				},
				'args'                => array(
					'taxonomy' => array( 'type' => 'string', 'required' => true ),
					'from'     => array( 'type' => 'integer', 'required' => true ),
					'into'     => array( 'type' => 'integer', 'required' => true ),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/themes/search',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'search_themes' ),
				'permission_callback' => function () {
					return current_user_can( 'install_themes' );
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
			'/themes/install',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'install_theme' ),
				'permission_callback' => function () {
					return current_user_can( 'install_themes' );
				},
				'args'                => array(
					'slug' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/themes/upload',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'upload_theme' ),
				'permission_callback' => function () {
					return current_user_can( 'install_themes' ) && current_user_can( 'upload_files' );
				},
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
					'q'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'page' => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
				),
			)
		);

		// Hover-tooltip payload for the Add-plugin catalog (one slug at a time).
		register_rest_route(
			self::NS,
			'/plugins/info',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'plugin_info' ),
				'permission_callback' => function () {
					return current_user_can( 'install_plugins' );
				},
				'args'                => array(
					'slug' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_title',
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

		// Install from a remote zip (GitHub release, etc.). Used by the curated
		// Add-plugin catalog for plugins that are not on wordpress.org.
		register_rest_route(
			self::NS,
			'/plugins/install-url',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'install_plugin_from_url' ),
				'permission_callback' => function () {
					return current_user_can( 'install_plugins' );
				},
				'args'                => array(
					'url'    => array(
						'type'     => 'string',
						'required' => false,
					),
					'github' => array(
						'type'     => 'string',
						'required' => false,
					),
					'asset'  => array(
						'type'     => 'string',
						'required' => false,
					),
				),
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
			'/core',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'core_status' ),
				'permission_callback' => function () {
					return current_user_can( 'update_core' );
				},
			)
		);

		register_rest_route(
			self::NS,
			'/core/update',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'core_update' ),
				'permission_callback' => function () {
					return current_user_can( 'update_core' );
				},
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

		register_rest_route(
			self::NS,
			'/cache/purge',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'cache_purge' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			self::NS,
			'/connectors',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'connectors' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		register_rest_route(
			self::NS,
			'/posts/(?P<id>\d+)/duplicate',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'duplicate_post' ),
				'permission_callback' => function ( $request ) {
					return current_user_can( 'edit_post', (int) $request['id'] );
				},
			)
		);

		register_rest_route(
			self::NS,
			'/render-blocks',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'render_blocks' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'blocks' => array(
						'type'     => 'array',
						'required' => true,
						'items'    => array( 'type' => 'string' ),
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/editor-styles',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'editor_styles' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		// Live re-poll of the boot payload keys that change when plugins/
		// themes are toggled — insertable blocks, inspector forms, design-
		// library flags. Same shape as window.MINN's boot fields.
		register_rest_route(
			self::NS,
			'/editor-blocks',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'editor_blocks' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		// Same idea for the sidebar: B.surfaces is a boot snapshot, so
		// activating/deactivating Safe Redirect Manager (etc.) would leave
		// stale nav items until a full reload without this re-poll.
		register_rest_route(
			self::NS,
			'/surfaces',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'surfaces' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		// Run a surface's one-time plugin setup (the `setup` descriptor key)
		// through the plugin's own installer. Capability is the SURFACE's own
		// cap, checked in the handler where the descriptor is at hand.
		register_rest_route(
			self::NS,
			'/surfaces/(?P<id>[a-z0-9_-]+)/setup',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'surface_setup' ),
				'permission_callback' => 'is_user_logged_in',
			)
		);

		register_rest_route(
			self::NS,
			'/patterns',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'patterns' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		// Single pattern content — `name` is a query arg because pattern names
		// contain slashes (otter-blocks/aw-hero-split).
		register_rest_route(
			self::NS,
			'/pattern',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'pattern' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'name' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		$permalinks_perm = function () {
			return current_user_can( 'manage_options' );
		};
		register_rest_route(
			self::NS,
			'/permalinks',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_permalinks' ),
					'permission_callback' => $permalinks_perm,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'save_permalinks' ),
					'permission_callback' => $permalinks_perm,
					'args'                => array(
						'structure'     => array(
							'type'     => 'string',
							'required' => true,
						),
						'category_base' => array( 'type' => 'string' ),
						'tag_base'      => array( 'type' => 'string' ),
					),
				),
			)
		);

		// Custom CSS — core's per-theme custom_css post (what the Customizer's
		// "Additional CSS" edits), surfaced in Settings → Design. Cap is core's
		// own edit_css (unfiltered_html-mapped; super-admin-only on multisite).
		$css_perm = function () {
			return current_user_can( 'edit_css' );
		};
		register_rest_route(
			self::NS,
			'/custom-css',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'get_custom_css' ),
					'permission_callback' => $css_perm,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'save_custom_css' ),
					'permission_callback' => $css_perm,
					'args'                => array(
						'css' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
			)
		);

		// System diagnostics — the developer's "what am I running on" page.
		register_rest_route(
			self::NS,
			'/system',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'system_info' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		// Toggle a whitelisted debug constant in wp-config.php. Sensitive — the
		// callback re-checks writability, DISALLOW_FILE_MODS and multisite.
		register_rest_route(
			self::NS,
			'/system/config',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'set_config_constant' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
				'args'                => array(
					'constant' => array(
						'type'     => 'string',
						'required' => true,
					),
					'value'    => array(
						'type'     => 'boolean',
						'required' => true,
					),
				),
			)
		);

		// Full autoload + cron detail behind the System page's summary rows.
		register_rest_route(
			self::NS,
			'/system/autoload',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'autoload_detail' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
		register_rest_route(
			self::NS,
			'/system/cron',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'cron_detail' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);

		// Read (tail) or clear the WordPress debug log.
		register_rest_route(
			self::NS,
			'/system/debug-log',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'read_debug_log' ),
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( __CLASS__, 'clear_debug_log' ),
					'permission_callback' => function () {
						return current_user_can( 'manage_options' );
					},
				),
			)
		);
	}

	/**
	 * Every autoloaded option by size, for the System page's detail modal.
	 * Same variant-aware autoload IN() as the summary; capped at 200 rows
	 * (past that the long tail is sub-100-byte noise).
	 */
	public static function autoload_detail() {
		global $wpdb;
		$autoload_in  = function_exists( 'wp_autoload_values_to_autoload' )
			? array_values( (array) wp_autoload_values_to_autoload() )
			: array( 'yes', 'on', 'auto', 'auto-on' );
		$placeholders = implode( ',', array_fill( 0, count( $autoload_in ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL -- placeholders built above
		$totals = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS c, COALESCE(SUM(LENGTH(option_value)),0) AS s FROM {$wpdb->options} WHERE autoload IN ($placeholders)",
			$autoload_in
		) );
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT option_name AS name, LENGTH(option_value) AS len, autoload FROM {$wpdb->options} WHERE autoload IN ($placeholders) ORDER BY len DESC LIMIT 200",
			$autoload_in
		) );
		// phpcs:enable
		return rest_ensure_response( array(
			'count'      => (int) $totals->c,
			'size'       => (int) $totals->s,
			'size_human' => size_format( (int) $totals->s, 1 ),
			'shown'      => count( (array) $rows ),
			'items'      => array_map( function ( $r ) {
				return array(
					'name'     => (string) $r->name,
					'size'     => (int) $r->len,
					'sizeh'    => size_format( (int) $r->len, 1 ),
					'autoload' => (string) $r->autoload,
				);
			}, (array) $rows ),
		) );
	}

	/**
	 * Every scheduled cron event with its next run and recurrence, for the
	 * System page's detail modal. Times are UTC epochs (cron stores GMT);
	 * the client renders relative times.
	 */
	public static function cron_detail() {
		$schedules = wp_get_schedules();
		$items     = array();
		foreach ( (array) _get_cron_array() as $ts => $hooks ) {
			if ( ! is_numeric( $ts ) ) {
				continue; // the 'version' key
			}
			foreach ( (array) $hooks as $hook => $entries ) {
				foreach ( (array) $entries as $entry ) {
					$schedule = isset( $entry['schedule'] ) ? (string) $entry['schedule'] : '';
					$items[]  = array(
						'hook'       => (string) $hook,
						'next'       => (int) $ts,
						'overdue'    => $ts < time() - 5 * MINUTE_IN_SECONDS,
						'recurrence' => $schedule
							? ( isset( $schedules[ $schedule ]['display'] ) ? (string) $schedules[ $schedule ]['display'] : $schedule )
							: 'One-off',
						'args'       => isset( $entry['args'] ) ? count( (array) $entry['args'] ) : 0,
					);
				}
			}
		}
		return rest_ensure_response( array(
			'now'      => time(),
			'disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'items'    => $items, // already time-ordered (cron array is)
		) );
	}

	/**
	 * Total users without count_users()' per-role breakdown — a single
	 * COUNT(*) instead of the meta JOIN, cheap even on huge user tables.
	 */
	private static function user_count() {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
	}

	/**
	 * A title as plain text: get_the_title() returns stored entities
	 * (&#8217; etc.) which read as raw code in the activity feed — decode
	 * them, and give untitled drafts a label instead of empty quotes.
	 * The client re-escapes on render.
	 */
	private static function plain_title( $post ) {
		$t = html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' );
		return '' === trim( $t ) ? '(no title)' : $t;
	}

	/**
	 * The active debug log path: a string WP_DEBUG_LOG wins, else PHP's
	 * error_log if it points at a real file, else the WordPress default.
	 */
	private static function debug_log_path() {
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG ) {
			return WP_DEBUG_LOG;
		}
		$ini = ini_get( 'error_log' );
		if ( $ini && 'syslog' !== $ini && ( file_exists( $ini ) || is_dir( dirname( $ini ) ) ) && '/' === substr( $ini, 0, 1 ) ) {
			return $ini;
		}
		return WP_CONTENT_DIR . '/debug.log';
	}

	/**
	 * Return the tail of the debug log (last 256 KB, partial first line
	 * dropped) plus metadata — never the whole thing, which can be enormous.
	 */
	public static function read_debug_log( WP_REST_Request $request ) {
		$path = self::debug_log_path();
		$rel  = str_replace( ABSPATH, '', $path );
		if ( ! file_exists( $path ) ) {
			return rest_ensure_response(
				array(
					'exists'  => false,
					'path'    => $rel,
					'content' => '',
					'size'    => 0,
				)
			);
		}
		$max       = 256 * 1024;
		$size      = (int) filesize( $path );
		$truncated = $size > $max;
		$content   = '';
		$fh        = @fopen( $path, 'rb' );
		if ( $fh ) {
			if ( $truncated ) {
				fseek( $fh, -$max, SEEK_END );
			}
			$content = (string) stream_get_contents( $fh );
			fclose( $fh );
			if ( $truncated ) {
				// Drop the partial line the byte-offset seek landed inside.
				$nl = strpos( $content, "\n" );
				if ( false !== $nl ) {
					$content = substr( $content, $nl + 1 );
				}
			}
		}
		return rest_ensure_response(
			array(
				'exists'     => true,
				'path'       => $rel,
				'size'       => $size,
				'size_human' => size_format( $size, 1 ),
				'truncated'  => $truncated,
				'writable'   => wp_is_writable( $path ),
				'content'    => $content,
			)
		);
	}

	/** Empty the debug log (truncate to zero). */
	public static function clear_debug_log() {
		$path = self::debug_log_path();
		if ( ! file_exists( $path ) ) {
			return rest_ensure_response( array( 'cleared' => true ) );
		}
		if ( ! wp_is_writable( $path ) ) {
			return new WP_Error( 'not_writable', 'The debug log is not writable.', array( 'status' => 400 ) );
		}
		$fh = @fopen( $path, 'w' );
		if ( ! $fh ) {
			return new WP_Error( 'clear_failed', 'Could not clear the debug log.', array( 'status' => 500 ) );
		}
		fclose( $fh );
		return rest_ensure_response( array( 'cleared' => true ) );
	}

	/**
	 * Render raw block markup server-side (do_blocks) — powers island previews
	 * in the editor and the block inspector's post-edit refresh. This runs the
	 * same render callbacks the front end runs on every page view; the cap gate
	 * (edit_posts) matches the editor itself.
	 */
	public static function render_blocks( WP_REST_Request $request ) {
		$blocks = $request['blocks'];
		if ( ! is_array( $blocks ) ) {
			return new WP_Error( 'invalid_blocks', 'Expected an array of block markup strings.', array( 'status' => 400 ) );
		}
		// The post these islands belong to, when the client knows it — some
		// plugins cache per-post generated CSS the styles filter below can
		// recover (adapters/otter.php).
		$post_id = absint( $request['post'] ?? 0 );
		// GenerateBlocks keeps each block's CSS in a `css` attribute and only
		// inlines it after wp_head; this documented filter makes every GB
		// block prepend its own <style> during our render
		// (docs/block-suites.md, CSS models).
		add_filter( 'generateblocks_do_inline_styles', '__return_true' );
		// Adapters that need to register assets *before* do_blocks (Otter
		// front-end style handles only exist after its has_block enqueue
		// path — adapters/otter.php registers them from the submitted
		// markup so the queue-diff below can see them).
		do_action( 'minn_admin_before_render_blocks', $blocks, $post_id );
		// Plugins with lazy CSS loading (Stackable's optimizer, Kadence,
		// GenerateBlocks) only enqueue their stylesheets from inside a
		// render_block filter when one of their blocks actually renders — an
		// editor-styles sweep can never see those. Diff the style queue across
		// the render and hand the newly-enqueued styles to the client, which
		// scopes them into the previews like everything else.
		$queue_before = wp_styles()->queue;
		$rendered     = array();
		foreach ( array_slice( $blocks, 0, 100 ) as $raw ) {
			$raw  = (string) $raw;
			$html = do_blocks( $raw );
			// Embed blocks keep a bare URL in their saved HTML; the front end
			// converts it via WP_Embed::autoembed on the_content — run the same
			// pass here so island previews show the real embed.
			if ( isset( $GLOBALS['wp_embed'] ) && false !== strpos( $html, 'wp-block-embed__wrapper' ) ) {
				$html = $GLOBALS['wp_embed']->autoembed( $html );
			}
			/**
			 * Per-block HTML post-process for island previews. Used by the
			 * Otter adapter to swap JS-only Leaflet maps for an OSM embed.
			 *
			 * @param string $html    Rendered HTML from do_blocks.
			 * @param string $raw     Original block markup string.
			 * @param int    $post_id Post being edited (0 when unknown).
			 */
			$html       = apply_filters( 'minn_admin_rendered_html', $html, $raw, $post_id );
			$rendered[] = $html;
		}
		$out         = array( 'rendered' => $rendered );
		$new_handles = array_values( array_diff( wp_styles()->queue, $queue_before ) );
		$styles      = $new_handles ? self::collect_style_urls( $new_handles ) : array(
			'urls'   => array(),
			'inline' => '',
		);
		/**
		 * Preview styles adapters can extend — e.g. per-post generated CSS a
		 * plugin cached in postmeta (Otter/atomic-wind) or CSS carried inside
		 * the submitted markup itself (Essential Blocks' blockMeta).
		 *
		 * @param array $styles  { urls: string[], inline: string }
		 * @param array $blocks  The submitted block markup strings.
		 * @param int   $post_id The post being edited (0 when unknown).
		 */
		$styles = apply_filters( 'minn_admin_render_styles', $styles, $blocks, $post_id );
		if ( ! empty( $styles['urls'] ) || ! empty( $styles['inline'] ) || ! empty( $styles['warm'] ) ) {
			$out['styles'] = $styles;
		}
		return rest_ensure_response( $out );
	}

	/**
	 * Resolve style handles — with their dependency chains, inline
	 * wp_add_inline_style extras, and versioned URLs — into a fetchable
	 * { urls, inline } bundle for the client's preview-scoping pipeline.
	 */
	private static function collect_style_urls( $handles ) {
		$styles = wp_styles();
		$urls   = array();
		$inline = '';
		$done   = array();
		$add    = function ( $handle ) use ( &$add, &$urls, &$inline, &$done, $styles ) {
			if ( isset( $done[ $handle ] ) || empty( $styles->registered[ $handle ] ) ) {
				return;
			}
			$done[ $handle ] = true;
			$dep             = $styles->registered[ $handle ];
			foreach ( (array) $dep->deps as $d ) {
				$add( $d );
			}
			if ( $dep->src ) {
				$src = $dep->src;
				if ( 0 === strpos( $src, '/' ) && 0 !== strpos( $src, '//' ) ) {
					$src = site_url( $src );
				}
				$urls[] = add_query_arg( 'ver', $dep->ver ? $dep->ver : get_bloginfo( 'version' ), $src );
			}
			$after = $styles->get_data( $handle, 'after' );
			if ( $after ) {
				$inline .= implode( "\n", (array) $after ) . "\n";
			}
		};
		foreach ( array_unique( $handles ) as $handle ) {
			$add( $handle );
		}
		return array(
			'urls'   => array_values( array_unique( $urls ) ),
			'inline' => $inline,
		);
	}

	/**
	 * The stylesheets that make blocks look like the front end — what the block
	 * editor loads into its canvas, collected for Minn's island previews: every
	 * registered block's style handles (resolved with their dependencies and
	 * wp_add_inline_style extras), the theme's declared editor styles, and the
	 * theme.json global stylesheet. The client fetches, scopes and injects them.
	 */
	public static function editor_styles() {
		// Many plugins register their block styles only on front-end enqueue
		// hooks (Stackable's ugb-style-css, for one), which never fire during
		// a REST request — the handles below would resolve to nothing and
		// previews render unstyled. Fire the registration hooks defensively:
		// output-buffered (some callbacks echo) and exception-swallowed (a
		// misbehaving enqueue must never break the editor).
		$queue_before = wp_styles()->queue;
		ob_start();
		try {
			do_action( 'wp_enqueue_scripts' );
			do_action( 'enqueue_block_assets' );
		} catch ( \Throwable $e ) {
			// Best effort — whatever registered before the throw still counts.
		}
		ob_end_clean();

		$handles = array( 'wp-block-library', 'wp-block-library-theme' );
		// Styles those hooks ENQUEUED directly (atomic-wind's base CSS et al)
		// never appear as block style_handles — carry them too.
		foreach ( array_diff( wp_styles()->queue, $queue_before ) as $handle ) {
			$handles[] = $handle;
		}
		foreach ( WP_Block_Type_Registry::get_instance()->get_all_registered() as $block_type ) {
			foreach ( (array) $block_type->style_handles as $handle ) {
				$handles[] = $handle;
			}
			if ( isset( $block_type->view_style_handles ) ) {
				foreach ( (array) $block_type->view_style_handles as $handle ) {
					$handles[] = $handle;
				}
			}
		}
		$out = self::collect_style_urls( $handles );

		// The same theme styles the block editor honors (add_editor_style API).
		foreach ( get_editor_stylesheets() as $url ) {
			$out['urls'][] = $url;
		}
		if ( function_exists( 'wp_get_global_stylesheet' ) ) {
			$out['inline'] .= wp_get_global_stylesheet();
		}
		$out['urls'] = array_values( array_unique( $out['urls'] ) );

		return rest_ensure_response( $out );
	}

	/**
	 * Re-poll of the boot keys that go stale when a plugin/theme is toggled
	 * mid-session. Mirrors the corresponding window.MINN fields so the
	 * client can update them without a full page reload (slash menu, block
	 * picker, design libraries).
	 */
	public static function editor_blocks() {
		$block_forms = apply_filters( 'minn_admin_block_forms', array() );
		return rest_ensure_response(
			array(
				'insertBlocks'    => Minn_Admin::insertable_blocks( $block_forms ),
				'blockForms'      => $block_forms,
				'designs'         => Minn_Admin::design_sources(),
				'editorCommands'  => Minn_Admin::editor_commands(),
				// Comments feature can vanish when Disable Comments (etc.) is
				// toggled — same re-poll path as blocks so the nav tracks live.
				'comments'        => Minn_Admin::comments_enabled(),
			)
		);
	}

	/**
	 * Live surface list for the current user — same shape as the boot
	 * payload's `surfaces` key. Re-polled after plugin/theme toggles so the
	 * sidebar tracks adapters that appear/disappear.
	 */
	public static function surfaces() {
		return rest_ensure_response( Minn_Admin_Surfaces::for_current_user() );
	}

	/**
	 * Run a surface's one-time setup through the plugin's OWN installer
	 * (the descriptor's `run` callable — vendor code, never a rebuild).
	 * Choices are booleans keyed by the descriptor's declared option ids;
	 * anything undeclared is dropped, absent ids get the declared default.
	 *
	 * @param WP_REST_Request $req { id, choices? }.
	 */
	public static function surface_setup( WP_REST_Request $req ) {
		$id       = sanitize_key( $req['id'] );
		$surfaces = Minn_Admin_Surfaces::all();
		$surface  = null;
		foreach ( $surfaces as $sid => $s ) {
			if ( sanitize_key( $sid ) === $id ) {
				$surface = $s;
				break;
			}
		}
		if ( ! $surface || empty( $surface['setup'] ) || ! is_array( $surface['setup'] ) ) {
			return new WP_Error( 'no_setup', 'That surface has no setup to run.', array( 'status' => 404 ) );
		}
		$cap = isset( $surface['cap'] ) ? $surface['cap'] : 'manage_options';
		if ( ! current_user_can( $cap ) ) {
			return new WP_Error( 'forbidden', 'You cannot set up this plugin.', array( 'status' => 403 ) );
		}
		$setup = $surface['setup'];
		if ( empty( $setup['run'] ) || ! is_callable( $setup['run'] ) ) {
			return new WP_Error( 'no_setup', 'This setup runs on the plugin\'s own screen.', array( 'status' => 400 ) );
		}
		if ( isset( $setup['needed'] ) && is_callable( $setup['needed'] ) && ! call_user_func( $setup['needed'] ) ) {
			return rest_ensure_response( array( 'ok' => true, 'already' => true ) );
		}
		$sent    = (array) $req->get_param( 'choices' );
		$choices = array();
		foreach ( (array) ( $setup['options'] ?? array() ) as $opt ) {
			if ( ! is_array( $opt ) || empty( $opt['id'] ) ) {
				continue;
			}
			$oid             = sanitize_key( $opt['id'] );
			$choices[ $oid ] = array_key_exists( $oid, $sent )
				? rest_sanitize_boolean( $sent[ $oid ] )
				: ! empty( $opt['default'] );
		}
		try {
			$result = call_user_func( $setup['run'], $choices );
		} catch ( \Throwable $e ) {
			$msg = trim( wp_strip_all_tags( (string) $e->getMessage() ) );
			return new WP_Error( 'setup_failed', '' !== $msg ? $msg : 'The plugin reported an error during setup.', array( 'status' => 500 ) );
		}
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 500 ) );
			return $result;
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Server-registered block patterns, slim: ready-made valid saved markup
	 * from WP_Block_Patterns_Registry (core, the active theme, Otter,
	 * Essential Blocks, anything else that registers locally). Contextual
	 * patterns are excluded — blockTypes-bound ones are query-variation /
	 * template-part fills, templateTypes ones belong to the site editor.
	 * postTypes restrictions ride along for the client to filter against the
	 * post being edited.
	 */
	public static function patterns() {
		$out = array();
		foreach ( WP_Block_Patterns_Registry::get_instance()->get_all_registered() as $p ) {
			if ( isset( $p['inserter'] ) && false === $p['inserter'] ) {
				continue;
			}
			if ( empty( $p['content'] ) || empty( $p['name'] ) ) {
				continue;
			}
			if ( ! empty( $p['blockTypes'] ) || ! empty( $p['templateTypes'] ) ) {
				continue;
			}
			$item = array(
				'name'  => (string) $p['name'],
				'title' => wp_strip_all_tags( (string) ( $p['title'] ?? $p['name'] ) ),
				'ns'    => strtok( (string) $p['name'], '/' ),
			);
			if ( ! empty( $p['postTypes'] ) ) {
				$item['postTypes'] = array_values( (array) $p['postTypes'] );
			}
			$out[] = $item;
		}
		usort( $out, function ( $a, $b ) {
			return strcasecmp( $a['title'], $b['title'] );
		} );
		return rest_ensure_response( array( 'patterns' => $out ) );
	}

	public static function pattern( WP_REST_Request $request ) {
		$p = WP_Block_Patterns_Registry::get_instance()->get_registered( (string) $request['name'] );
		if ( ! $p || empty( $p['content'] ) || ( isset( $p['inserter'] ) && false === $p['inserter'] ) ) {
			return new WP_Error( 'minn_pattern_not_found', __( 'Pattern not found.', 'minn-admin' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( array( 'content' => (string) $p['content'] ) );
	}

	/**
	 * Permalink settings — core leaves these out of wp/v2/settings because
	 * changing them needs a rewrite flush, so Minn exposes its own endpoint.
	 */
	private static function permalink_state() {
		return array(
			'structure'     => (string) get_option( 'permalink_structure' ),
			'category_base' => (string) get_option( 'category_base' ),
			'tag_base'      => (string) get_option( 'tag_base' ),
			'pretty'        => (bool) get_option( 'permalink_structure' ),
			// Where the app lives under the new structure — the client hard-redirects
			// here when saving flips between path routing and ?minn_admin=1.
			'app_url'       => Minn_Admin::app_url(),
		);
	}

	public static function get_permalinks() {
		return rest_ensure_response( self::permalink_state() );
	}

	public static function save_permalinks( WP_REST_Request $request ) {
		global $wp_rewrite;
		// misc.php supplies got_url_rewrite() + the .htaccess writer that a hard
		// flush uses when it can (file.php for get_home_path underneath it).
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		$structure = trim( (string) $request['structure'] );
		if ( '' !== $structure && ! preg_match( '/%[^\/%]+%/', $structure ) ) {
			return new WP_Error(
				'invalid_structure',
				'A custom structure needs at least one tag, e.g. %postname%.',
				array( 'status' => 400 )
			);
		}
		if ( '' !== $structure ) {
			// Same normalization as options-permalink.php: no hash, single slashes,
			// leading slash, and an /index.php prefix where URL rewriting is unavailable.
			$structure = preg_replace( '#/+#', '/', '/' . str_replace( '#', '', $structure ) );
			if ( ! got_url_rewrite() && 0 !== strpos( $structure, '/index.php' ) ) {
				$structure = '/index.php' . $structure;
			}
		}
		$wp_rewrite->set_permalink_structure( $structure );

		if ( $request->has_param( 'category_base' ) ) {
			$wp_rewrite->set_category_base( sanitize_option( 'category_base', (string) $request['category_base'] ) );
		}
		if ( $request->has_param( 'tag_base' ) ) {
			$wp_rewrite->set_tag_base( sanitize_option( 'tag_base', (string) $request['tag_base'] ) );
		}

		flush_rewrite_rules();

		return rest_ensure_response( self::permalink_state() );
	}

	/**
	 * The Custom CSS state: the active theme's stylesheet (core keeps one
	 * custom_css post per theme) plus the theme name for the UI note.
	 */
	private static function custom_css_state() {
		return array(
			'css'   => (string) wp_get_custom_css(),
			'theme' => wp_get_theme()->get( 'Name' ),
		);
	}

	public static function get_custom_css() {
		return rest_ensure_response( self::custom_css_state() );
	}

	public static function save_custom_css( WP_REST_Request $request ) {
		$css = (string) $request['css'];

		// The Customizer refuses obviously broken CSS rather than blanking the
		// front end with it; mirror its cheap structural checks (balance only —
		// full parsing stays out of scope, exactly like core).
		$balance = array(
			array( '{', '}', 'curly brackets' ),
			array( '(', ')', 'parentheses' ),
			array( '[', ']', 'square brackets' ),
		);
		foreach ( $balance as $pair ) {
			if ( substr_count( $css, $pair[0] ) !== substr_count( $css, $pair[1] ) ) {
				return new WP_Error( 'invalid_css', 'That CSS has unbalanced ' . $pair[2] . '. Fix it and save again; nothing was changed.', array( 'status' => 400 ) );
			}
		}
		if ( substr_count( $css, '/*' ) !== substr_count( $css, '*/' ) ) {
			return new WP_Error( 'invalid_css', 'That CSS has an unclosed comment. Fix it and save again; nothing was changed.', array( 'status' => 400 ) );
		}

		$result = wp_update_custom_css_post( $css );
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 400 ) );
			return $result;
		}
		return rest_ensure_response( self::custom_css_state() );
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

		/**
		 * Traffic providers (analytics plugins) hook `minn_admin_traffic` and
		 * return ['source' => 'Koko Analytics', 'days' => ['Y-m-d' => ['visitors' => int,
		 * 'pageviews' => int]], 'prev_visitors' => int] covering the requested
		 * range (prev_visitors = the period before it, for the delta).
		 */
		$traffic     = apply_filters( 'minn_admin_traffic', null, $days );
		$traffic_out = null;

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
			// Many sites never use comments — an eternal zero is dead weight,
			// so a comment-less site gets a Users count instead. Pending
			// comments still force the card (they need moderating).
			( 0 === (int) $comments->approved && 0 === (int) $comments->moderated ) ? array(
				'label' => 'Users',
				'value' => number_format_i18n( self::user_count() ),
				'delta' => 'registered',
				'up'    => null,
			) : array(
				'label' => 'Comments',
				'value' => number_format_i18n( (int) $comments->approved ),
				'delta' => (int) $comments->moderated . ' pending',
				'up'    => (int) $comments->moderated > 0 ? 'warn' : null,
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
				'label' => $label,
				'value' => $count,
				// GMT bounds of the bucket ((from, to] — the same math the counting
				// loop uses), so the client can fetch the events behind a bar.
				'from'  => gmdate( 'Y-m-d H:i:s', time() - ( $buckets - $i ) * $bucket_days * DAY_IN_SECONDS ),
				'to'    => gmdate( 'Y-m-d H:i:s', time() - ( $buckets - 1 - $i ) * $bucket_days * DAY_IN_SECONDS ),
			);
		}

		// When an analytics provider responded, bucket its daily numbers the
		// same way and lead the stats with a Visitors card.
		if ( is_array( $traffic ) && ! empty( $traffic['days'] ) ) {
			$tseries = array_fill( 0, $buckets, array( 'v' => 0, 'p' => 0 ) );
			$visitors  = 0;
			$pageviews = 0;
			foreach ( $traffic['days'] as $date => $row ) {
				$age = time() - strtotime( $date . ' 12:00:00 UTC' );
				$idx = $buckets - 1 - (int) floor( $age / ( $bucket_days * DAY_IN_SECONDS ) );
				if ( $idx < 0 || $idx >= $buckets ) {
					continue;
				}
				$tseries[ $idx ]['v'] += (int) $row['visitors'];
				$tseries[ $idx ]['p'] += (int) $row['pageviews'];
				$visitors             += (int) $row['visitors'];
				$pageviews            += (int) $row['pageviews'];
			}
			$tchart = array();
			foreach ( $tseries as $i => $bucket ) {
				$offset   = ( $buckets - 1 - $i ) * $bucket_days;
				// Inclusive calendar window for the traffic-day drill-down
				// (Y-m-d — matches how analytics plugins store daily rows).
				$from_ts  = time() - ( $offset + $bucket_days - 1 ) * DAY_IN_SECONDS;
				$to_ts    = time() - $offset * DAY_IN_SECONDS;
				$label    = 1 === $bucket_days
					? date_i18n( 'M j, Y', $to_ts )
					: 'Week of ' . date_i18n( 'M j, Y', $from_ts );
				$tchart[] = array(
					'label' => $label,
					'value' => $bucket['v'],
					'views' => $bucket['p'],
					'from'  => gmdate( 'Y-m-d', $from_ts ),
					'to'    => gmdate( 'Y-m-d', $to_ts ),
				);
			}

			$compact = function ( $n ) {
				return $n >= 10000 ? round( $n / 1000, 1 ) . 'k' : number_format_i18n( $n );
			};
			$prev  = isset( $traffic['prev_visitors'] ) ? (int) $traffic['prev_visitors'] : 0;
			$delta = $prev > 0 ? round( ( $visitors - $prev ) / $prev * 100, 1 ) : null;
			// Always surface pageviews on the card; when a period delta exists
			// it leads, with pageviews as a quiet second clause.
			$views_bit = $compact( $pageviews ) . ' pageviews';
			$delta_bit = null !== $delta
				? ( $delta >= 0 ? '↑ ' : '↓ ' ) . abs( $delta ) . '% vs prior ' . $days . 'd · ' . $views_bit
				: $views_bit;
			array_unshift(
				$stats,
				array(
					'label' => 'Visitors',
					'value' => $compact( $visitors ),
					'delta' => $delta_bit,
					'up'    => null !== $delta ? ( $delta >= 0 ? true : 'down' ) : null,
				)
			);
			$traffic_out = array(
				'source' => isset( $traffic['source'] ) ? $traffic['source'] : 'Analytics',
				'chart'  => $tchart,
			);
		}

		// Recent activity feed.
		$activity = array();

		// Two queries, merged: never-edited drafts carry a zeroed modified
		// date and sort out of an orderby=modified window, but a fresh draft
		// IS activity — the by-date query catches them. The usort below
		// settles the merged order.
		$base_query   = array(
			'post_type'   => array( 'post', 'page' ),
			'post_status' => array( 'publish', 'draft', 'future', 'pending' ),
			'numberposts' => 5,
		);
		$recent_posts = get_posts( array_merge( $base_query, array( 'orderby' => 'modified' ) ) );
		$seen_ids     = wp_list_pluck( $recent_posts, 'ID' );
		foreach ( get_posts( array_merge( $base_query, array( 'orderby' => 'date' ) ) ) as $by_date ) {
			if ( ! in_array( $by_date->ID, $seen_ids, true ) ) {
				$recent_posts[] = $by_date;
			}
		}
		foreach ( $recent_posts as $p ) {
			$time = strtotime( $p->post_modified_gmt . ' UTC' );
			if ( ! $time || $time < 0 ) {
				// Never-updated drafts zero BOTH gmt columns — post_date
				// (site-local) is the only truthful stamp; convert it here
				// instead of hiding the draft from the feed.
				$time = strtotime( get_gmt_from_date( $p->post_date ) . ' UTC' );
			}
			if ( ! $time || $time < 0 ) {
				continue;
			}
			$author = get_the_author_meta( 'display_name', $p->post_author );
			$verb   = 'publish' === $p->post_status ? 'published' : ( 'future' === $p->post_status ? 'scheduled' : 'drafted' );
			$pto    = get_post_type_object( $p->post_type );
			$activity[] = array(
				'text'  => sprintf( '%s %s “%s”', $author, $verb, self::plain_title( $p ) ),
				'time'  => $time,
				'color' => 'publish' === $p->post_status ? 'green' : ( 'future' === $p->post_status ? 'blue' : 'accent' ),
				// Rows are clickable: land in the editor (Austin's ask). The
				// editor route takes the REST base, not the post type.
				'goto'  => array(
					'kind' => 'editor',
					'type' => $pto && $pto->rest_base ? $pto->rest_base : $p->post_type . 's',
					'id'   => $p->ID,
				),
			);
		}

		$recent_comments = get_comments( array( 'number' => 3, 'status' => 'all' ) );
		foreach ( $recent_comments as $c ) {
			$pending = '0' === $c->comment_approved;
			$activity[] = array(
				'text'  => sprintf(
					$pending ? 'Comment from %s awaiting moderation on “%s”' : '%s commented on “%s”',
					$c->comment_author ? $c->comment_author : 'Anonymous',
					self::plain_title( $c->comment_post_ID )
				),
				'time'  => strtotime( $c->comment_date_gmt . ' UTC' ),
				'color' => $pending ? 'amber' : 'blue',
				'goto'  => array(
					'kind' => 'comments',
					'tab'  => $pending ? 'hold' : 'approve',
				),
			);
		}

		usort( $activity, function ( $a, $b ) {
			return $b['time'] - $a['time'];
		} );
		// 4 rows keeps the Recent activity card the same height as the chart card.
		$activity = array_slice( $activity, 0, 4 );
		foreach ( $activity as &$item ) {
			$item['time'] = sprintf( '%s ago', human_time_diff( $item['time'] ) );
		}

		return rest_ensure_response(
			array(
				'stats'    => $stats,
				'chart'    => $chart,
				'traffic'  => $traffic_out,
				'activity' => $activity,
				'greeting' => self::greeting(),
			)
		);
	}

	/**
	 * The events behind one activity-chart bar: posts/pages published and
	 * comments received in the (from, to] GMT window the overview handed out.
	 */
	/**
	 * Traffic chart bar drill-down: top pages (and optional referrers) for a
	 * date window. Providers answer the `minn_admin_traffic_day` filter with
	 * { source, pages:[{title,path,url?,postId?,visitors,pageviews}],
	 *   referrers?:[{label,visitors,pageviews}], adminUrl? }.
	 * First non-null wins, same priority convention as minn_admin_traffic.
	 */
	public static function overview_traffic_day( WP_REST_Request $request ) {
		$from = $request['from'];
		$to   = $request['to'];
		if ( $from > $to ) {
			return new WP_Error( 'bad_range', 'from must be on or before to.', array( 'status' => 400 ) );
		}
		// Cap the window so a bad client can't ask for years of aggregation.
		$span = (int) ( ( strtotime( $to . ' UTC' ) - strtotime( $from . ' UTC' ) ) / DAY_IN_SECONDS ) + 1;
		if ( $span > 31 ) {
			return new WP_Error( 'range_too_long', 'Pick a window of 31 days or fewer.', array( 'status' => 400 ) );
		}

		/**
		 * Traffic day detail for the Overview chart drill-down.
		 *
		 * @param array|null $data Existing answer (return early when non-null).
		 * @param string     $from Inclusive Y-m-d start.
		 * @param string     $to   Inclusive Y-m-d end.
		 */
		$data = apply_filters( 'minn_admin_traffic_day', null, $from, $to );
		if ( ! is_array( $data ) ) {
			return rest_ensure_response(
				array(
					'source'    => '',
					'pages'     => array(),
					'referrers' => array(),
					'adminUrl'  => '',
				)
			);
		}
		$pages = array();
		foreach ( (array) ( $data['pages'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$pages[] = array(
				'title'     => isset( $row['title'] ) ? (string) $row['title'] : '',
				'path'      => isset( $row['path'] ) ? (string) $row['path'] : '',
				'url'       => isset( $row['url'] ) ? (string) $row['url'] : '',
				'postId'    => isset( $row['postId'] ) ? (int) $row['postId'] : 0,
				'visitors'  => isset( $row['visitors'] ) ? (int) $row['visitors'] : 0,
				'pageviews' => isset( $row['pageviews'] ) ? (int) $row['pageviews'] : 0,
			);
		}
		$referrers = array();
		foreach ( (array) ( $data['referrers'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$referrers[] = array(
				'label'     => isset( $row['label'] ) ? (string) $row['label'] : '',
				'visitors'  => isset( $row['visitors'] ) ? (int) $row['visitors'] : 0,
				'pageviews' => isset( $row['pageviews'] ) ? (int) $row['pageviews'] : 0,
			);
		}
		return rest_ensure_response(
			array(
				'source'    => isset( $data['source'] ) ? (string) $data['source'] : '',
				'pages'     => $pages,
				'referrers' => $referrers,
				'adminUrl'  => isset( $data['adminUrl'] ) ? (string) $data['adminUrl'] : '',
			)
		);
	}

	public static function overview_activity( WP_REST_Request $request ) {
		global $wpdb;

		$from  = $request['from'];
		$to    = $request['to'];
		$items = array();

		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_title, post_type, post_author, post_date_gmt FROM {$wpdb->posts}
				 WHERE post_status = 'publish' AND post_type IN ('post','page')
				 AND post_date_gmt > %s AND post_date_gmt <= %s
				 ORDER BY post_date_gmt DESC LIMIT 100",
				$from,
				$to
			)
		);
		foreach ( $posts as $p ) {
			$author  = get_the_author_meta( 'display_name', (int) $p->post_author );
			// Titles carry HTML entities; the client escapes, so decode here.
			$title   = html_entity_decode( $p->post_title ?: '(no title)', ENT_QUOTES );
			$items[] = array(
				'kind'  => 'post',
				'id'    => (int) $p->ID,
				'type'  => 'page' === $p->post_type ? 'pages' : 'posts',
				'text'  => sprintf( '%s published “%s”', $author ?: 'Someone', $title ),
				'time'  => strtotime( $p->post_date_gmt . ' UTC' ),
				'color' => 'green',
			);
		}

		$comments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT comment_ID, comment_author, comment_post_ID, comment_date_gmt, comment_approved FROM {$wpdb->comments}
				 WHERE comment_date_gmt > %s AND comment_date_gmt <= %s
				 ORDER BY comment_date_gmt DESC LIMIT 100",
				$from,
				$to
			)
		);
		foreach ( $comments as $c ) {
			$pending = '0' === $c->comment_approved;
			$items[] = array(
				'kind'  => 'comment',
				'id'    => (int) $c->comment_ID,
				'text'  => sprintf(
					$pending ? 'Comment from %s awaiting moderation on “%s”' : '%s commented on “%s”',
					$c->comment_author ? $c->comment_author : 'Anonymous',
					html_entity_decode( get_the_title( (int) $c->comment_post_ID ) ?: '(no title)', ENT_QUOTES )
				),
				'time'  => strtotime( $c->comment_date_gmt . ' UTC' ),
				'color' => $pending ? 'amber' : 'blue',
			);
		}

		usort( $items, function ( $a, $b ) {
			return $b['time'] - $a['time'];
		} );
		$items = array_slice( $items, 0, 100 );
		foreach ( $items as &$item ) {
			$item['ago'] = sprintf( '%s ago', human_time_diff( $item['time'] ) );
		}

		return rest_ensure_response( array( 'items' => $items ) );
	}

	/**
	 * Theme page templates for a post type — the classic `Template Name:`
	 * headers (plus anything added via the theme_page_templates filter). Core
	 * REST validates the `template` field against this list but never exposes
	 * it, so the editor's picker needs its own endpoint.
	 */
	public static function page_templates( WP_REST_Request $request ) {
		$type      = sanitize_key( $request['type'] );
		$templates = wp_get_theme()->get_page_templates( null, $type );
		asort( $templates );
		$out = array();
		foreach ( $templates as $file => $name ) {
			$out[] = array(
				'file' => $file,
				'name' => $name,
			);
		}
		return rest_ensure_response( array( 'templates' => $out ) );
	}

	/**
	 * Restore a trashed post — core wp/v2 has no untrash, so Minn provides one.
	 * wp_untrash_post lands on draft by default (core behavior since 5.6).
	 */
	public static function restore_post( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$post = get_post( $id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
		}
		if ( 'trash' !== $post->post_status ) {
			return new WP_Error( 'not_trashed', 'That post is not in the trash.', array( 'status' => 400 ) );
		}
		// Same capability wp-admin's own untrash link requires.
		if ( ! current_user_can( 'delete_post', $id ) ) {
			return new WP_Error( 'forbidden', 'You are not allowed to restore this item.', array( 'status' => 403 ) );
		}
		if ( ! wp_untrash_post( $id ) ) {
			return new WP_Error( 'restore_failed', 'Could not restore the item.', array( 'status' => 500 ) );
		}
		return rest_ensure_response(
			array(
				'restored' => true,
				'status'   => get_post_status( $id ),
			)
		);
	}

	/**
	 * Acquire or refresh the edit lock. Uses core's lock primitives (150s
	 * window by default, refreshed by wp-admin's heartbeat every 15s and by
	 * Minn every 30s) so both admins honor the same lock. When someone else
	 * holds a fresh lock and take_over wasn't asked for, returns who — taking
	 * over is just setting the lock to us, same as wp-admin's takeover.
	 */
	public static function lock_post( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$post = get_post( $id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'forbidden', 'You are not allowed to edit this item.', array( 'status' => 403 ) );
		}
		require_once ABSPATH . 'wp-admin/includes/post.php';
		$other = wp_check_post_lock( $id );
		if ( $other && empty( $request['take_over'] ) ) {
			$user = get_userdata( $other );
			return rest_ensure_response(
				array(
					'acquired' => false,
					'holder'   => array(
						'id'     => $other,
						'name'   => $user ? $user->display_name : 'Someone',
						'avatar' => get_avatar_url( $other, array( 'size' => 96 ) ),
					),
				)
			);
		}
		wp_set_post_lock( $id );
		return rest_ensure_response( array( 'acquired' => true ) );
	}

	/**
	 * Release the edit lock — but only our own. A stale release arriving after
	 * someone else took over (a beacon from a closing tab) must not free THEIR
	 * lock.
	 */
	public static function unlock_post( WP_REST_Request $request ) {
		$id   = (int) $request['id'];
		$post = get_post( $id );
		if ( ! $post ) {
			return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'forbidden', 'You are not allowed to edit this item.', array( 'status' => 403 ) );
		}
		$lock  = (string) get_post_meta( $id, '_edit_lock', true );
		$parts = explode( ':', $lock );
		if ( ! empty( $parts[1] ) && (int) $parts[1] === get_current_user_id() ) {
			delete_post_meta( $id, '_edit_lock' );
		}
		return rest_ensure_response( array( 'unlocked' => true ) );
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
						'id'     => 'plugin-' . $file . '-' . $data->new_version,
						'kind'   => 'updates',
						'icon'   => '⬆',
						'title'  => sprintf( '%s %s is available to install', $name, $data->new_version ),
						'time'   => $checked,
						// Client renders an in-row Update button from this.
						'update' => array(
							'type'    => 'plugin',
							'plugin'  => $file,
							'version' => $data->new_version,
							'name'    => $name,
						),
					);
				}
			}
		}

		if ( current_user_can( 'update_themes' ) ) {
			$tupdates = get_site_transient( 'update_themes' );
			$tchecked = $tupdates && ! empty( $tupdates->last_checked ) ? (int) $tupdates->last_checked : time();
			if ( $tupdates && ! empty( $tupdates->response ) ) {
				foreach ( $tupdates->response as $stylesheet => $data ) {
					$theme   = wp_get_theme( $stylesheet );
					$ver     = is_array( $data ) ? ( $data['new_version'] ?? '' ) : '';
					$tname   = $theme->exists() ? $theme->get( 'Name' ) : $stylesheet;
					$items[] = array(
						'id'     => 'theme-' . $stylesheet . '-' . $ver,
						'kind'   => 'updates',
						'icon'   => '⬆',
						'title'  => sprintf( '%s theme %s is available to install', $tname, $ver ),
						'time'   => $tchecked,
						'update' => array(
							'type'       => 'theme',
							'stylesheet' => $stylesheet,
							'version'    => $ver,
							'name'       => $tname,
						),
					);
				}
			}
		}

		if ( current_user_can( 'update_core' ) ) {
			$core = get_site_transient( 'update_core' );
			if ( $core && ! empty( $core->updates ) && 'upgrade' === $core->updates[0]->response ) {
				$items[] = array(
					'id'     => 'core-' . $core->updates[0]->version,
					'kind'   => 'system',
					'icon'   => '🛡',
					'title'  => sprintf( 'WordPress %s is available', $core->updates[0]->version ),
					'time'   => (int) $core->last_checked,
					'update' => array(
						'type'    => 'core',
						'version' => $core->updates[0]->version,
						'name'    => 'WordPress',
					),
				);
			}
			// When core auto-updated itself (minor releases do, within hours),
			// say so — otherwise an update nag the user saw in wp-admin just
			// vanishes with no explanation.
			$auto = get_option( 'auto_core_update_notified' );
			if ( is_array( $auto ) && 'success' === ( $auto['type'] ?? '' ) && ! empty( $auto['version'] )
				&& ( time() - (int) ( $auto['timestamp'] ?? 0 ) ) < 14 * DAY_IN_SECONDS ) {
				$items[] = array(
					'id'    => 'core-auto-' . $auto['version'],
					'kind'  => 'system',
					'icon'  => '🛡',
					'title' => sprintf( 'WordPress updated itself to %s', $auto['version'] ),
					'time'  => (int) $auto['timestamp'],
				);
			}
		}

		// Extracted admin notices (class-minn-admin-notices.php) — captured
		// per user, so capability gating already happened inside the
		// plugins' own callbacks at capture time.
		foreach ( Minn_Admin_Notices::items_for_user() as $n ) {
			$items[] = $n;
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
		$themes    = current_user_can( 'update_themes' ) ? get_site_transient( 'update_themes' ) : null;
		$theme_map = array();
		if ( $themes && ! empty( $themes->response ) ) {
			foreach ( $themes->response as $stylesheet => $data ) {
				$theme_map[ $stylesheet ] = is_array( $data ) ? ( $data['new_version'] ?? '' ) : '';
			}
		}
		return rest_ensure_response(
			array(
				'updates' => $map,
				'themes'  => $theme_map,
			)
		);
	}

	/**
	 * GET minn-admin/v1/me/appearance — current user's color scheme preference.
	 */
	public static function get_my_appearance() {
		return rest_ensure_response( Minn_Admin::get_user_appearance( get_current_user_id() ) );
	}

	/**
	 * POST minn-admin/v1/me/appearance — save scheme for the current user only.
	 * Body: { scheme, custom?: { dark: {slot:hex}, light: {slot:hex} } }
	 * Partial custom maps merge onto Minn defaults. Legacy {accent,custom:#hex}
	 * still migrates via normalize_appearance.
	 */
	public static function hide_integration( WP_REST_Request $request ) {
		$id = (string) $request->get_param( 'id' );
		if ( ! Minn_Admin_Surfaces::hide_integration( $id ) ) {
			return new WP_Error( 'minn_unknown_integration', 'That integration is not registered.', array( 'status' => 400 ) );
		}
		return rest_ensure_response( self::integration_state() );
	}

	public static function unhide_integration( WP_REST_Request $request ) {
		Minn_Admin_Surfaces::unhide_integration( (string) $request->get_param( 'id' ) );
		return rest_ensure_response( self::integration_state() );
	}

	private static function integration_state() {
		return array(
			'ok'           => true,
			'surfaces'     => Minn_Admin_Surfaces::for_current_user(),
			'editorPanels' => Minn_Admin_Surfaces::editor_panels_for_current_user(),
			'hidden'       => Minn_Admin_Surfaces::hidden_for_current_user(),
		);
	}

	public static function update_my_appearance( WP_REST_Request $request ) {
		$uid = get_current_user_id();
		$cur = Minn_Admin::get_user_appearance( $uid );
		// JSON body may put nested custom under get_json_params.
		$json = $request->get_json_params();
		if ( ! is_array( $json ) ) {
			$json = array();
		}
		$raw = array(
			'scheme'       => array_key_exists( 'scheme', $json )
				? $json['scheme']
				: ( $request->has_param( 'scheme' ) ? $request->get_param( 'scheme' ) : $cur['scheme'] ),
			'custom'       => array_key_exists( 'custom', $json )
				? $json['custom']
				: ( $request->has_param( 'custom' ) ? $request->get_param( 'custom' ) : $cur['custom'] ),
			'defaultAdmin' => array_key_exists( 'defaultAdmin', $json )
				? $json['defaultAdmin']
				: ( $request->has_param( 'defaultAdmin' ) ? $request->get_param( 'defaultAdmin' ) : $cur['defaultAdmin'] ),
		);
		// Legacy accent-only body (no scheme key).
		if ( ! array_key_exists( 'scheme', $json ) && isset( $json['accent'] ) ) {
			$raw = array_merge( array( 'defaultAdmin' => $cur['defaultAdmin'] ), $json );
		}
		$norm = Minn_Admin::save_user_appearance( $uid, $raw );
		return rest_ensure_response( $norm );
	}

	/**
	 * Classify raw session_tokens meta: active (any non-expired), expired
	 * (tokens present but all past expiration), never (empty / absent).
	 * Matches the filtering in user_sessions() — expiration 0 counts as live.
	 */
	public static function classify_session_tokens( $tokens ) {
		if ( ! is_array( $tokens ) || empty( $tokens ) ) {
			return 'never';
		}
		$now = time();
		foreach ( $tokens as $session ) {
			if ( ! is_array( $session ) ) {
				continue;
			}
			$expiration = isset( $session['expiration'] ) ? (int) $session['expiration'] : 0;
			if ( ! $expiration || $expiration >= $now ) {
				return 'active';
			}
		}
		return 'expired';
	}

	/**
	 * User IDs grouped by session status from the raw session_tokens meta.
	 * Only users with non-empty tokens appear in active/expired; everyone
	 * else (no meta, empty array after sign-out) is "never".
	 *
	 * @return array{active:int[],expired:int[]}
	 */
	public static function session_user_ids() {
		global $wpdb;
		$active  = array();
		$expired = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'session_tokens'"
		);
		if ( ! is_array( $rows ) ) {
			return array( 'active' => array(), 'expired' => array() );
		}
		foreach ( $rows as $row ) {
			$uid    = (int) $row->user_id;
			$tokens = maybe_unserialize( $row->meta_value );
			$class  = self::classify_session_tokens( $tokens );
			if ( 'active' === $class ) {
				$active[] = $uid;
			} elseif ( 'expired' === $class ) {
				$expired[] = $uid;
			}
		}
		return array(
			'active'  => $active,
			'expired' => $expired,
		);
	}

	/**
	 * List users with optional session-status filter. Shape matches the
	 * fields the Users view loads from wp/v2/users (context=edit).
	 */
	public static function list_users( WP_REST_Request $request ) {
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$search   = trim( (string) $request->get_param( 'search' ) );
		$roles    = trim( (string) $request->get_param( 'roles' ) );
		$orderby  = (string) $request->get_param( 'orderby' );
		$order    = strtoupper( (string) $request->get_param( 'order' ) ) === 'ASC' ? 'ASC' : 'DESC';
		$session  = (string) $request->get_param( 'session' );
		if ( ! in_array( $session, array( 'all', 'active', 'expired', 'never' ), true ) ) {
			$session = 'all';
		}

		$orderby_map = array(
			'id'              => 'ID',
			'name'            => 'display_name',
			'email'           => 'user_email',
			'registered_date' => 'user_registered',
			'slug'            => 'user_nicename',
		);
		$wp_orderby = isset( $orderby_map[ $orderby ] ) ? $orderby_map[ $orderby ] : 'user_registered';

		$args = array(
			'number'  => $per_page,
			'paged'   => $page,
			'orderby' => $wp_orderby,
			'order'   => $order,
			'fields'  => 'ID',
		);
		if ( $search !== '' ) {
			$args['search']         = '*' . $search . '*';
			$args['search_columns'] = array( 'user_login', 'user_nicename', 'user_email', 'display_name' );
		}
		if ( $roles !== '' ) {
			// Core REST accepts a single role or comma list; WP_User_Query takes one role
			// or role__in for multiple.
			$role_list = array_values( array_filter( array_map( 'sanitize_key', explode( ',', $roles ) ) ) );
			if ( count( $role_list ) === 1 ) {
				$args['role'] = $role_list[0];
			} elseif ( $role_list ) {
				$args['role__in'] = $role_list;
			}
		}

		if ( 'all' !== $session ) {
			$buckets = self::session_user_ids();
			if ( 'active' === $session ) {
				$ids = $buckets['active'];
			} elseif ( 'expired' === $session ) {
				$ids = $buckets['expired'];
			} else {
				// never: everyone not currently active or holding only-expired tokens
				$known = array_values( array_unique( array_merge( $buckets['active'], $buckets['expired'] ) ) );
				if ( $known ) {
					$args['exclude'] = $known;
				}
				$ids = null;
			}
			if ( null !== $ids ) {
				if ( ! $ids ) {
					$response = rest_ensure_response( array() );
					$response->header( 'X-WP-Total', 0 );
					$response->header( 'X-WP-TotalPages', 0 );
					return $response;
				}
				$args['include'] = $ids;
			}
		}

		$query = new WP_User_Query( $args );
		$total = (int) $query->get_total();
		$ids   = array_map( 'intval', (array) $query->get_results() );
		$items = array();
		foreach ( $ids as $uid ) {
			$user = get_userdata( $uid );
			if ( ! $user ) {
				continue;
			}
			$item = array(
				'id'              => $uid,
				'name'            => $user->display_name,
				'email'           => $user->user_email,
				'roles'           => array_values( $user->roles ),
				'registered_date' => mysql_to_rfc3339( $user->user_registered ),
				'avatar_urls'     => rest_get_avatar_urls( $user->user_email ),
			);
			// Same shape as the User Switching REST field when the plugin is active.
			if ( class_exists( 'user_switching' ) && method_exists( 'user_switching', 'maybe_switch_url' ) ) {
				$url = user_switching::maybe_switch_url( $user );
				$item['minn_switch_url'] = $url ? str_replace( '&amp;', '&', $url ) : '';
			}
			$items[] = $item;
		}

		$pages    = $per_page > 0 ? (int) ceil( $total / $per_page ) : 0;
		$response = rest_ensure_response( $items );
		$response->header( 'X-WP-Total', $total );
		$response->header( 'X-WP-TotalPages', max( 1, $pages ) );
		if ( 0 === $total ) {
			$response->header( 'X-WP-TotalPages', 0 );
		}
		return $response;
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

		// Match core: only NON-EXPIRED tokens are real sessions.
		// get_user_meta reads the raw store, which can hold expired tokens
		// WordPress has not garbage-collected yet (GC is probabilistic);
		// core's WP_Session_Tokens::get_all() filters them via is_still_valid,
		// so list them the same way rather than showing dead sessions.
		$now   = time();
		$items = array();
		foreach ( $tokens as $verifier => $session ) {
			$expiration = isset( $session['expiration'] ) ? (int) $session['expiration'] : 0;
			if ( $expiration && $expiration < $now ) {
				continue;
			}
			$items[] = array(
				'verifier'   => $verifier,
				'ip'         => isset( $session['ip'] ) ? $session['ip'] : '',
				'ua'         => isset( $session['ua'] ) ? $session['ua'] : '',
				'login'      => isset( $session['login'] ) ? (int) $session['login'] : 0,
				'expiration' => $expiration,
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
	 * Email the user WordPress's standard password-reset link.
	 */
	public static function user_reset_password( WP_REST_Request $request ) {
		$user = get_userdata( (int) $request['id'] );
		if ( ! $user ) {
			return new WP_Error( 'not_found', 'User not found.', array( 'status' => 404 ) );
		}
		// Super admins on multisite need network-level rights.
		if ( is_multisite() && is_super_admin( $user->ID ) && ! current_user_can( 'manage_network_users' ) ) {
			return new WP_Error( 'forbidden', 'You cannot reset a super admin password.', array( 'status' => 403 ) );
		}
		$result = retrieve_password( $user->user_login );
		if ( true !== $result ) {
			$msg = is_wp_error( $result ) ? $result->get_error_message() : 'Could not send the reset email.';
			return new WP_Error( 'reset_failed', $msg, array( 'status' => 500 ) );
		}
		return rest_ensure_response( array(
			'ok'    => true,
			'email' => $user->user_email,
		) );
	}

	/**
	 * Send a styled HTML email to a user (from the current admin / site mail).
	 */
	public static function user_send_email( WP_REST_Request $request ) {
		$user = get_userdata( (int) $request['id'] );
		if ( ! $user ) {
			return new WP_Error( 'not_found', 'User not found.', array( 'status' => 404 ) );
		}
		$subject = trim( (string) $request['subject'] );
		$message = trim( (string) $request['message'] );
		if ( '' === $subject || '' === $message ) {
			return new WP_Error( 'invalid', 'Subject and message are required.', array( 'status' => 400 ) );
		}
		if ( ! is_email( $user->user_email ) ) {
			return new WP_Error( 'invalid_email', 'This user has no valid email address.', array( 'status' => 400 ) );
		}

		$who  = $user->display_name ? $user->display_name : $user->user_login;
		$html = self::minn_email_html( $subject, $message, $who );
		$sent = self::minn_send_html_mail( $user->user_email, $subject, $html );
		if ( is_wp_error( $sent ) ) {
			return $sent;
		}
		return rest_ensure_response( array(
			'ok'    => true,
			'email' => $user->user_email,
		) );
	}

	/**
	 * Send a styled HTML email to an order's billing address (order context).
	 */
	public static function order_send_email( WP_REST_Request $request ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
			return new WP_Error( 'no_wc', 'WooCommerce is not available.', array( 'status' => 400 ) );
		}
		$order = wc_get_order( (int) $request['id'] );
		if ( ! $order ) {
			return new WP_Error( 'not_found', 'Order not found.', array( 'status' => 404 ) );
		}
		$to = $order->get_billing_email();
		if ( ! is_email( $to ) ) {
			return new WP_Error( 'invalid_email', 'This order has no valid billing email.', array( 'status' => 400 ) );
		}
		$subject = trim( (string) $request['subject'] );
		$message = trim( (string) $request['message'] );
		if ( '' === $subject || '' === $message ) {
			return new WP_Error( 'invalid', 'Subject and message are required.', array( 'status' => 400 ) );
		}

		$who = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		if ( '' === $who ) {
			$who = $to;
		}
		// CTA: payment link when unpaid, else the customer view-order URL.
		$cta_url   = $order->needs_payment() ? $order->get_checkout_payment_url() : $order->get_view_order_url();
		$cta_label = $order->needs_payment() ? 'Pay for order #' . $order->get_order_number() : 'View order #' . $order->get_order_number();
		$html      = self::minn_email_html( $subject, $message, $who, $cta_url, $cta_label );
		$sent      = self::minn_send_html_mail( $to, $subject, $html );
		if ( is_wp_error( $sent ) ) {
			return $sent;
		}
		$order->add_order_note(
			sprintf(
				/* translators: 1: admin name, 2: subject */
				__( 'Minn Admin email sent by %1$s: %2$s', 'minn-admin' ),
				wp_get_current_user()->display_name,
				$subject
			),
			false,
			true
		);
		return rest_ensure_response( array(
			'ok'    => true,
			'email' => $to,
		) );
	}

	/**
	 * List WooCommerce emails that can be re-triggered for an order.
	 */
	public static function order_list_emails( WP_REST_Request $request ) {
		if ( ! function_exists( 'WC' ) || ! function_exists( 'wc_get_order' ) ) {
			return new WP_Error( 'no_wc', 'WooCommerce is not available.', array( 'status' => 400 ) );
		}
		$order = wc_get_order( (int) $request['id'] );
		if ( ! $order ) {
			return new WP_Error( 'not_found', 'Order not found.', array( 'status' => 404 ) );
		}
		// Order-scoped transactional emails only (not account/reset).
		$allow = array(
			'new_order',
			'cancelled_order',
			'failed_order',
			'customer_on_hold_order',
			'customer_processing_order',
			'customer_completed_order',
			'customer_refunded_order',
			'customer_invoice',
			'customer_note',
			'customer_failed_order',
			'customer_cancelled_order',
		);
		$out = array();
		foreach ( WC()->mailer()->get_emails() as $email ) {
			if ( ! is_object( $email ) || empty( $email->id ) || ! in_array( $email->id, $allow, true ) ) {
				continue;
			}
			$out[] = array(
				'id'      => $email->id,
				'title'   => $email->get_title(),
				'enabled' => (bool) $email->is_enabled(),
				// customer_invoice is the usual "resend order details" even when disabled by default.
				'to'      => ( method_exists( $email, 'is_customer_email' ) && $email->is_customer_email() )
					? 'customer'
					: 'admin',
			);
		}
		usort( $out, function ( $a, $b ) {
			return strcasecmp( $a['title'], $b['title'] );
		} );
		return rest_ensure_response( array(
			'emails' => $out,
			'order'  => (int) $order->get_id(),
		) );
	}

	/**
	 * Trigger a WooCommerce email for an order (their Email::trigger).
	 */
	public static function order_trigger_email( WP_REST_Request $request ) {
		if ( ! function_exists( 'WC' ) || ! function_exists( 'wc_get_order' ) ) {
			return new WP_Error( 'no_wc', 'WooCommerce is not available.', array( 'status' => 400 ) );
		}
		$order = wc_get_order( (int) $request['id'] );
		if ( ! $order ) {
			return new WP_Error( 'not_found', 'Order not found.', array( 'status' => 404 ) );
		}
		$email_id = (string) $request['email_id'];
		$found    = null;
		foreach ( WC()->mailer()->get_emails() as $email ) {
			if ( is_object( $email ) && ! empty( $email->id ) && $email->id === $email_id ) {
				$found = $email;
				break;
			}
		}
		if ( ! $found || ! is_callable( array( $found, 'trigger' ) ) ) {
			return new WP_Error( 'unknown_email', 'That email type is not available.', array( 'status' => 400 ) );
		}
		// Force-send even if the email is disabled in settings (admin intent).
		// WC_Email::trigger checks is_enabled(); temporarily enable via filter.
		$force = function ( $enabled, $email_obj ) use ( $found ) {
			return ( $email_obj === $found ) ? true : $enabled;
		};
		add_filter( 'woocommerce_email_enabled_' . $found->id, $force, 100, 2 );
		// Most order emails accept ( $order_id, $order ). customer_refunded_order
		// takes ( $order_id, $partial_refund ). invoice takes ( $order_id, $order ).
		try {
			if ( 'customer_refunded_order' === $found->id ) {
				$found->trigger( $order->get_id(), false );
			} else {
				$found->trigger( $order->get_id(), $order );
			}
		} catch ( \Throwable $e ) {
			remove_filter( 'woocommerce_email_enabled_' . $found->id, $force, 100 );
			return new WP_Error( 'send_failed', $e->getMessage(), array( 'status' => 500 ) );
		}
		remove_filter( 'woocommerce_email_enabled_' . $found->id, $force, 100 );

		$order->add_order_note(
			sprintf(
				/* translators: 1: email title, 2: admin name */
				__( '“%1$s” email resent via Minn Admin by %2$s.', 'minn-admin' ),
				$found->get_title(),
				wp_get_current_user()->display_name
			),
			false,
			true
		);
		return rest_ensure_response( array(
			'ok'    => true,
			'email' => $found->id,
			'title' => $found->get_title(),
		) );
	}

	/**
	 * Shared wp_mail HTML send with site From + admin Reply-To.
	 *
	 * @param string $to      Recipient address.
	 * @param string $subject Subject.
	 * @param string $html    HTML body.
	 * @return true|WP_Error
	 */
	private static function minn_send_html_mail( $to, $subject, $html ) {
		$from    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
			'From: ' . $from . ' <' . get_option( 'admin_email' ) . '>',
		);
		$me = wp_get_current_user();
		if ( $me && $me->user_email && is_email( $me->user_email ) ) {
			$headers[] = 'Reply-To: ' . $me->display_name . ' <' . $me->user_email . '>';
		}
		$sent = wp_mail( $to, $subject, $html, $headers );
		if ( ! $sent ) {
			return new WP_Error( 'send_failed', 'wp_mail() could not send the message. Check your mail configuration.', array( 'status' => 500 ) );
		}
		return true;
	}

	/**
	 * Minn-styled HTML email wrapper for admin messages.
	 *
	 * @param string      $subject   Subject line (already sanitized).
	 * @param string      $message   Plain-text body (already sanitized).
	 * @param string      $who       Recipient greeting name.
	 * @param string|null $cta_url   Optional button URL.
	 * @param string|null $cta_label Optional button label.
	 */
	public static function minn_email_html( $subject, $message, $who, $cta_url = null, $cta_label = null ) {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$url  = home_url( '/' );
		$who  = is_string( $who ) ? $who : '';
		// Plain text → escaped paragraphs (no untrusted HTML in the body).
		$paras = array_filter( array_map( 'trim', preg_split( '/\n\s*\n/', $message ) ) );
		$body  = '';
		foreach ( $paras as $p ) {
			$body .= '<p style="margin:0 0 14px;font-size:15.5px;line-height:1.55;color:#3a3a42;">'
				. nl2br( esc_html( $p ) ) . '</p>';
		}
		if ( ! $body ) {
			$body = '<p style="margin:0;font-size:15.5px;line-height:1.55;color:#3a3a42;">'
				. nl2br( esc_html( $message ) ) . '</p>';
		}

		$btn_url   = $cta_url ? $cta_url : $url;
		$btn_label = $cta_label ? $cta_label : ( 'Visit ' . $site );

		return '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width"></head>
<body style="margin:0;padding:0;background:#f4f4f6;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f4f6;padding:28px 16px;">
<tr><td align="center">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:560px;background:#ffffff;border:1px solid #e7e7ea;border-radius:14px;overflow:hidden;">
<tr><td style="padding:20px 24px;background:linear-gradient(135deg,#7166f6,#8f86f8);">
<div style="font-size:18px;font-weight:700;color:#ffffff;">' . esc_html( $site ) . '</div>
</td></tr>
<tr><td style="padding:28px 24px 8px;">
<p style="margin:0 0 18px;font-size:15px;color:#65656f;">Hi ' . esc_html( $who ) . ',</p>
' . $body . '
</td></tr>
<tr><td style="padding:8px 24px 24px;">
<a href="' . esc_url( $btn_url ) . '" style="display:inline-block;background:#7166f6;color:#ffffff;text-decoration:none;font-weight:600;font-size:14px;padding:11px 18px;border-radius:10px;">' . esc_html( $btn_label ) . '</a>
</td></tr>
<tr><td style="padding:16px 24px;border-top:1px solid #e7e7ea;font-size:12.5px;color:#9494a0;line-height:1.45;">
Sent from <a href="' . esc_url( $url ) . '" style="color:#5a4ef0;text-decoration:none;">' . esc_html( $site ) . '</a>
</td></tr>
</table>
</td></tr>
</table>
</body></html>';
	}

	/**
	 * Installed themes with active state, screenshots and update availability.
	 */
	public static function list_themes() {
		$updates    = get_site_transient( 'update_themes' );
		$active     = get_stylesheet();
		$items      = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			// update_themes lists only themes WordPress.org (or a licensed
			// vendor channel) knows about — response = has update, no_update
			// = current. Used so the context menu can offer a wp.org link
			// without guessing for custom themes.
			$on_wporg = $updates && (
				isset( $updates->response[ $stylesheet ] )
				|| isset( $updates->no_update[ $stylesheet ] )
			);
			$items[] = array(
				'stylesheet' => $stylesheet,
				'name'       => $theme->get( 'Name' ),
				'version'    => $theme->get( 'Version' ),
				'author'     => wp_strip_all_tags( $theme->get( 'Author' ) ),
				'author_uri' => esc_url_raw( (string) $theme->get( 'AuthorURI' ) ),
				'theme_uri'  => esc_url_raw( (string) $theme->get( 'ThemeURI' ) ),
				'screenshot' => $theme->get_screenshot() ?: '',
				'active'     => $stylesheet === $active,
				'parent'     => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
				'on_wporg'   => (bool) $on_wporg,
				'update'     => $updates && isset( $updates->response[ $stylesheet ]['new_version'] )
					? $updates->response[ $stylesheet ]['new_version'] : null,
			);
		}
		usort( $items, function ( $a, $b ) {
			return $b['active'] <=> $a['active'] ?: strcasecmp( $a['name'], $b['name'] );
		} );
		return rest_ensure_response( array( 'themes' => $items ) );
	}

	private static function get_valid_theme( $stylesheet ) {
		$theme = wp_get_theme( $stylesheet );
		return $theme->exists() ? $theme : null;
	}

	public static function theme_activate( WP_REST_Request $request ) {
		$stylesheet = sanitize_text_field( $request['stylesheet'] );
		$theme      = self::get_valid_theme( $stylesheet );
		if ( ! $theme ) {
			return new WP_Error( 'not_found', 'Theme not found.', array( 'status' => 404 ) );
		}
		switch_theme( $stylesheet );
		return rest_ensure_response( array( 'active' => get_stylesheet() ) );
	}

	public static function theme_delete( WP_REST_Request $request ) {
		$stylesheet = sanitize_text_field( $request['stylesheet'] );
		if ( ! self::get_valid_theme( $stylesheet ) ) {
			return new WP_Error( 'not_found', 'Theme not found.', array( 'status' => 404 ) );
		}
		if ( get_stylesheet() === $stylesheet || get_template() === $stylesheet ) {
			return new WP_Error( 'theme_in_use', 'The active theme (or its parent) cannot be deleted.', array( 'status' => 400 ) );
		}
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		$result = delete_theme( $stylesheet );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Theme_Upgrader hooks upgrader_process_complete → wp_clean_themes_cache,
	 * which deletes the entire update_themes transient — same trap as plugins.
	 * Snapshot response[] before the upgrade; restore every stylesheet that
	 * was not successfully updated (notifications + Themes badges).
	 *
	 * @param array $pending_before stylesheet => update data, from before upgrade.
	 * @param array $updated_styles Stylesheets that upgraded successfully.
	 */
	public static function restore_theme_update_offers( array $pending_before, array $updated_styles ) {
		foreach ( $updated_styles as $s ) {
			unset( $pending_before[ $s ] );
		}
		if ( ! $pending_before && ! $updated_styles ) {
			return;
		}
		$current = get_site_transient( 'update_themes' );
		if ( ! is_object( $current ) ) {
			$current = (object) array(
				'last_checked' => time(),
				'checked'      => array(),
				'response'     => array(),
				'no_update'    => array(),
			);
		}
		$response = ( isset( $current->response ) && is_array( $current->response ) )
			? $current->response
			: array();
		foreach ( $pending_before as $stylesheet => $data ) {
			if ( ! isset( $response[ $stylesheet ] ) ) {
				$response[ $stylesheet ] = $data;
			}
		}
		foreach ( $updated_styles as $s ) {
			unset( $response[ $s ] );
		}
		$current->response     = $response;
		$current->last_checked = time();
		set_site_transient( 'update_themes', $current );
	}

	public static function theme_update( WP_REST_Request $request ) {
		$stylesheet = sanitize_text_field( $request['stylesheet'] );
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';

		// Only re-hit the API when this stylesheet is not already known pending
		// (mirrors update_single_plugin — avoid a full check on every click).
		$updates = get_site_transient( 'update_themes' );
		if ( ! $updates || empty( $updates->response[ $stylesheet ] ) ) {
			wp_update_themes();
			$updates = get_site_transient( 'update_themes' );
		}
		if ( ! $updates || empty( $updates->response[ $stylesheet ] ) ) {
			return new WP_Error( 'no_update', 'No update available for that theme.', array( 'status' => 400 ) );
		}

		// Snapshot every pending offer — upgrade() wipes the transient.
		$pending_before = is_array( $updates->response ) ? $updates->response : array();

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		$result   = $upgrader->upgrade( $stylesheet );
		if ( ! $result || is_wp_error( $result ) ) {
			self::restore_theme_update_offers( $pending_before, array() );
			$errors = $skin->get_error_messages();
			return new WP_Error( 'update_failed', $errors ? implode( ' ', (array) $errors ) : 'Update failed.', array( 'status' => 500 ) );
		}

		// Put every other pending offer back.
		self::restore_theme_update_offers( $pending_before, array( $stylesheet ) );

		$theme = wp_get_theme( $stylesheet );
		return rest_ensure_response( array( 'updated' => true, 'version' => $theme->get( 'Version' ) ) );
	}

	/**
	 * Search the wordpress.org theme directory (proxied server-side).
	 */
	/**
	 * Taxonomies the current user can manage terms in, for the Terms
	 * manager's switcher. REST-enabled + show_ui only (a taxonomy outside
	 * REST is invisible to Minn by construction), filtered per-user by the
	 * taxonomy's own manage_terms capability.
	 */
	public static function term_taxonomies() {
		$out = array();
		foreach ( get_taxonomies( array( 'show_in_rest' => true, 'show_ui' => true ), 'objects' ) as $tax ) {
			if ( ! current_user_can( $tax->cap->manage_terms ) ) {
				continue;
			}
			// Only taxonomies that organize PUBLIC content — internals like
			// wp_pattern_category (attached to wp_block) are not daily work.
			$organizes_public = false;
			foreach ( (array) $tax->object_type as $pt ) {
				$obj = get_post_type_object( $pt );
				if ( $obj && $obj->public ) {
					$organizes_public = true;
					break;
				}
			}
			if ( ! $organizes_public ) {
				continue;
			}
			$count = wp_count_terms( array( 'taxonomy' => $tax->name, 'hide_empty' => false ) );
			$out[] = array(
				'slug'         => $tax->name,
				'rest'         => $tax->rest_base ? $tax->rest_base : $tax->name,
				'label'        => $tax->labels->name,
				'item'         => strtolower( $tax->labels->singular_name ),
				'hierarchical' => (bool) $tax->hierarchical,
				'canDelete'    => current_user_can( $tax->cap->delete_terms ),
				'canEdit'      => current_user_can( $tax->cap->edit_terms ),
				'count'        => is_wp_error( $count ) ? 0 : (int) $count,
				'types'        => array_values( (array) $tax->object_type ),
			);
		}
		// Categories and tags first (the daily pair), then alphabetical.
		usort( $out, function ( $a, $b ) {
			$rank = array( 'category' => 0, 'post_tag' => 1 );
			$ra   = isset( $rank[ $a['slug'] ] ) ? $rank[ $a['slug'] ] : 2;
			$rb   = isset( $rank[ $b['slug'] ] ) ? $rank[ $b['slug'] ] : 2;
			return $ra !== $rb ? $ra - $rb : strcasecmp( $a['label'], $b['label'] );
		} );
		return rest_ensure_response( $out );
	}

	/**
	 * Merge one term into another: every object assigned to `from` gets
	 * `into` instead, then `from` is deleted. Core has no merge endpoint,
	 * but wp_delete_term's force_default arg IS the reassignment machinery
	 * its own category-delete uses, so the whole operation stays core code.
	 * Children of `from` are reparented to from's parent (core behavior).
	 */
	public static function merge_terms( WP_REST_Request $request ) {
		$taxonomy = sanitize_key( $request->get_param( 'taxonomy' ) );
		$from_id  = (int) $request->get_param( 'from' );
		$into_id  = (int) $request->get_param( 'into' );

		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax || ! $tax->show_in_rest ) {
			return new WP_Error( 'bad_taxonomy', 'Unknown taxonomy.', array( 'status' => 404 ) );
		}
		if ( ! current_user_can( $tax->cap->delete_terms ) || ! current_user_can( $tax->cap->edit_terms ) ) {
			return new WP_Error( 'forbidden', 'You cannot manage terms in this taxonomy.', array( 'status' => 403 ) );
		}
		if ( $from_id === $into_id ) {
			return new WP_Error( 'same_term', 'Pick a different term to merge into.', array( 'status' => 400 ) );
		}
		$from = get_term( $from_id, $taxonomy );
		$into = get_term( $into_id, $taxonomy );
		if ( ! $from || is_wp_error( $from ) || ! $into || is_wp_error( $into ) ) {
			return new WP_Error( 'bad_term', 'Both terms must exist in this taxonomy.', array( 'status' => 404 ) );
		}

		// Term counts only include published posts; count real assignments.
		$objects = get_objects_in_term( $from_id, $taxonomy );
		$moved   = is_wp_error( $objects ) ? 0 : count( $objects );
		$result  = wp_delete_term( $from_id, $taxonomy, array(
			'default'       => $into_id,
			'force_default' => true,
		) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( true !== $result ) {
			return new WP_Error( 'merge_failed', 'The merge did not complete.', array( 'status' => 500 ) );
		}
		clean_term_cache( $into_id, $taxonomy );
		return rest_ensure_response( array(
			'ok'    => true,
			'moved' => $moved,
			'into'  => $into->name,
		) );
	}

	public static function search_themes( WP_REST_Request $request ) {
		require_once ABSPATH . 'wp-admin/includes/theme.php';

		// No search term → the popular directory, so the Add theme dialog
		// opens with something to pick instead of a blank box.
		$q    = sanitize_text_field( (string) $request['q'] );
		$args = '' === $q
			? array( 'browse' => 'popular' )
			: array( 'search' => $q );

		$res = themes_api(
			'query_themes',
			array_merge(
				$args,
				array(
					'per_page' => 12,
					'fields'   => array(
						'screenshot_url' => true,
						'rating'         => true,
						'active_installs'=> true,
					),
				)
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$installed = array_keys( wp_get_themes() );

		$items = array();
		foreach ( (array) $res->themes as $t ) {
			$t       = (array) $t;
			$items[] = array(
				'slug'       => $t['slug'],
				'name'       => html_entity_decode( wp_strip_all_tags( $t['name'] ), ENT_QUOTES ),
				'version'    => isset( $t['version'] ) ? $t['version'] : '',
				'screenshot' => isset( $t['screenshot_url'] ) ? $t['screenshot_url'] : '',
				'installs'   => isset( $t['active_installs'] ) ? (int) $t['active_installs'] : 0,
				'installed'  => in_array( $t['slug'], $installed, true ),
				'active'     => get_stylesheet() === $t['slug'],
			);
		}
		return rest_ensure_response( array( 'themes' => $items ) );
	}

	/**
	 * Install a theme from the wordpress.org directory by slug.
	 */
	public static function install_theme( WP_REST_Request $request ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		$slug = sanitize_key( $request['slug'] );
		$api  = themes_api( 'theme_information', array( 'slug' => $slug ) );
		if ( is_wp_error( $api ) ) {
			return $api;
		}

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		$result   = $upgrader->install( $api->download_link );

		if ( ! $result || is_wp_error( $result ) ) {
			$errors = $skin->get_error_messages();
			return new WP_Error( 'install_failed', $errors ? implode( ' ', (array) $errors ) : 'Install failed.', array( 'status' => 500 ) );
		}
		return rest_ensure_response( array( 'installed' => true, 'stylesheet' => $slug ) );
	}

	/**
	 * Install a theme from an uploaded zip.
	 */
	public static function upload_theme( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) || empty( $files['file']['tmp_name'] ) ) {
			return new WP_Error( 'no_file', 'No file uploaded.', array( 'status' => 400 ) );
		}
		if ( ! preg_match( '/\.zip$/i', $files['file']['name'] ) ) {
			return new WP_Error( 'not_zip', 'Theme uploads must be .zip files.', array( 'status' => 400 ) );
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		$package = wp_tempnam( $files['file']['name'] );
		if ( ! $package || ! move_uploaded_file( $files['file']['tmp_name'], $package ) ) {
			return new WP_Error( 'move_failed', 'Could not store the upload.', array( 'status' => 500 ) );
		}

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		$result   = $upgrader->install( $package );
		@unlink( $package ); // phpcs:ignore

		if ( ! $result || is_wp_error( $result ) ) {
			$errors = $skin->get_error_messages();
			return new WP_Error( 'install_failed', $errors ? implode( ' ', (array) $errors ) : 'Install failed.', array( 'status' => 500 ) );
		}
		return rest_ensure_response( array( 'installed' => true, 'stylesheet' => $upgrader->theme_info() ? $upgrader->theme_info()->get_stylesheet() : null ) );
	}

	/**
	 * Search the wordpress.org plugin directory (proxied server-side so the
	 * app never talks to external hosts).
	 */
	public static function search_plugins( WP_REST_Request $request ) {
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$page = max( 1, (int) $request['page'] );
		$res  = plugins_api(
			'query_plugins',
			array(
				'search'   => sanitize_text_field( $request['q'] ),
				'per_page' => 12,
				'page'     => $page,
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
				'name'        => html_entity_decode( wp_strip_all_tags( $p['name'] ), ENT_QUOTES ),
				'description' => html_entity_decode( wp_strip_all_tags( isset( $p['short_description'] ) ? $p['short_description'] : '' ), ENT_QUOTES ),
				'installs'    => isset( $p['active_installs'] ) ? (int) $p['active_installs'] : 0,
				'rating'      => isset( $p['rating'] ) ? (int) $p['rating'] : 0,
				'version'     => isset( $p['version'] ) ? $p['version'] : '',
				'icon'        => isset( $icons['1x'] ) ? $icons['1x'] : ( isset( $icons['default'] ) ? $icons['default'] : '' ),
				'installed'   => isset( $installed[ $p['slug'] ] ) ? $installed[ $p['slug'] ] : null,
			);
		}
		$info = isset( $res->info ) ? (array) $res->info : array();
		return rest_ensure_response(
			array(
				'plugins' => $items,
				'page'    => $page,
				'pages'   => isset( $info['pages'] ) ? (int) $info['pages'] : 1,
				'total'   => isset( $info['results'] ) ? (int) $info['results'] : count( $items ),
			)
		);
	}

	/**
	 * Slim plugin card for the Add-plugin catalog hover tip.
	 *
	 * Reads wordpress.org via plugins_api (plugin_information). Cached 12h per
	 * slug so opening the catalog and hovering many chips stays snappy.
	 */
	public static function plugin_info( WP_REST_Request $request ) {
		$slug = sanitize_title( (string) $request['slug'] );
		if ( ! $slug ) {
			return new WP_Error( 'no_slug', 'Plugin slug is required.', array( 'status' => 400 ) );
		}

		// Non-wp.org catalog entries (GitHub-only) answer from a small local map
		// so the tip never 404s on Disembark et al.
		$local = self::catalog_external_info( $slug );
		if ( $local ) {
			return rest_ensure_response( $local );
		}

		$cache_key = 'minn_pi_info_' . md5( $slug );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return rest_ensure_response( $cached );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		$res = plugins_api(
			'plugin_information',
			array(
				'slug'   => $slug,
				'fields' => array(
					'short_description' => true,
					'icons'             => true,
					'active_installs'   => true,
					'sections'          => false,
					'description'       => false,
					'reviews'           => false,
					'downloaded'        => false,
					'rating'            => true,
					'ratings'           => false,
					'last_updated'      => false,
					'added'             => false,
					'tags'              => false,
					'homepage'          => false,
					'donate_link'       => false,
					'contributors'      => false,
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$p     = (array) $res;
		$icons = isset( $p['icons'] ) ? (array) $p['icons'] : array();
		// author is HTML like <a href="…">Name</a> — strip to plain text.
		$author = isset( $p['author'] ) ? wp_strip_all_tags( (string) $p['author'] ) : '';
		$author = html_entity_decode( $author, ENT_QUOTES );
		$payload = array(
			'slug'        => $slug,
			'name'        => html_entity_decode( wp_strip_all_tags( isset( $p['name'] ) ? $p['name'] : $slug ), ENT_QUOTES ),
			'author'      => $author,
			'description' => html_entity_decode( wp_strip_all_tags( isset( $p['short_description'] ) ? $p['short_description'] : '' ), ENT_QUOTES ),
			'installs'    => isset( $p['active_installs'] ) ? (int) $p['active_installs'] : 0,
			'version'     => isset( $p['version'] ) ? (string) $p['version'] : '',
			'rating'      => isset( $p['rating'] ) ? (int) $p['rating'] : 0,
			'icon'        => isset( $icons['2x'] ) ? $icons['2x'] : ( isset( $icons['1x'] ) ? $icons['1x'] : ( isset( $icons['default'] ) ? $icons['default'] : '' ) ),
			'source'      => 'wporg',
		);
		set_transient( $cache_key, $payload, 12 * HOUR_IN_SECONDS );
		return rest_ensure_response( $payload );
	}

	/**
	 * Static tip payload for catalog plugins that are not on wordpress.org.
	 *
	 * @param string $slug Plugin directory slug.
	 * @return array|null
	 */
	private static function catalog_external_info( $slug ) {
		$map = array(
			'disembark' => array(
				'slug'        => 'disembark',
				'name'        => 'Disembark',
				'author'      => 'Disembark Host',
				'description' => 'Generate a full WordPress backup (files + database) and pull it off-site with the Disembark CLI or disembark.host.',
				'installs'    => 0,
				'version'     => '',
				'rating'      => 0,
				'icon'        => '',
				'source'      => 'github',
				'homepage'    => 'https://disembark.host/',
			),
		);
		return isset( $map[ $slug ] ) ? $map[ $slug ] : null;
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
	 * Install a plugin from a remote zip URL or a GitHub release.
	 *
	 * Body: { url: "https://…/plugin.zip" } OR
	 *       { github: "Owner/repo", asset?: "name.zip" }
	 *
	 * Hosts are allowlisted (GitHub release downloads + wp.org packages) so a
	 * compromised client cannot point the server at arbitrary URLs.
	 */
	public static function install_plugin_from_url( WP_REST_Request $request ) {
		$url    = trim( (string) $request['url'] );
		$github = trim( (string) $request['github'] );
		$asset  = trim( (string) $request['asset'] );

		if ( $github ) {
			$resolved = self::github_release_zip_url( $github, $asset );
			if ( is_wp_error( $resolved ) ) {
				return $resolved;
			}
			$url = $resolved;
		}

		if ( ! $url ) {
			return new WP_Error( 'no_source', 'Provide a zip URL or a github owner/repo.', array( 'status' => 400 ) );
		}

		$allowed = self::plugin_install_url_allowed( $url );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		// Plugin_Upgrader::install accepts a remote package URL and downloads
		// it through download_url() (follows redirects to objects.githubusercontent.com).
		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $url );

		if ( ! $result || is_wp_error( $result ) ) {
			$errors = $skin->get_error_messages();
			return new WP_Error( 'install_failed', $errors ? implode( ' ', (array) $errors ) : 'Install failed.', array( 'status' => 500 ) );
		}

		return rest_ensure_response(
			array(
				'installed' => true,
				'plugin'    => $upgrader->plugin_info(),
				'url'       => $url,
			)
		);
	}

	/**
	 * Resolve the latest GitHub release zip for owner/repo.
	 *
	 * @param string $repo  "Owner/repo".
	 * @param string $asset Preferred asset filename (optional).
	 * @return string|WP_Error Download URL.
	 */
	private static function github_release_zip_url( $repo, $asset = '' ) {
		if ( ! preg_match( '#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo ) ) {
			return new WP_Error( 'bad_github', 'Invalid GitHub repository.', array( 'status' => 400 ) );
		}
		$api = 'https://api.github.com/repos/' . $repo . '/releases/latest';
		$res = wp_remote_get(
			$api,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'Minn-Admin/' . ( defined( 'MINN_ADMIN_VERSION' ) ? MINN_ADMIN_VERSION : '1' ),
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$body = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code || ! is_array( $body ) ) {
			return new WP_Error( 'github_api', 'Could not read the latest GitHub release.', array( 'status' => 502 ) );
		}
		$assets = isset( $body['assets'] ) && is_array( $body['assets'] ) ? $body['assets'] : array();
		$pick   = null;
		if ( $asset ) {
			foreach ( $assets as $a ) {
				if ( isset( $a['name'] ) && $a['name'] === $asset && ! empty( $a['browser_download_url'] ) ) {
					$pick = $a['browser_download_url'];
					break;
				}
			}
		}
		if ( ! $pick ) {
			foreach ( $assets as $a ) {
				if ( ! empty( $a['browser_download_url'] ) && preg_match( '/\.zip$/i', (string) ( $a['name'] ?? '' ) ) ) {
					$pick = $a['browser_download_url'];
					break;
				}
			}
		}
		if ( ! $pick ) {
			return new WP_Error( 'github_no_zip', 'That release has no zip asset.', array( 'status' => 404 ) );
		}
		return $pick;
	}

	/**
	 * Allowlist remote install hosts.
	 *
	 * @param string $url Candidate package URL.
	 * @return true|WP_Error
	 */
	private static function plugin_install_url_allowed( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) ) {
			return new WP_Error( 'bad_url', 'Install URL must be HTTPS.', array( 'status' => 400 ) );
		}
		$host = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		$ok   = array(
			'github.com',
			'www.github.com',
			'objects.githubusercontent.com',
			'release-assets.githubusercontent.com',
			'downloads.wordpress.org',
			'downloads.w.org',
		);
		if ( ! in_array( $host, $ok, true ) ) {
			return new WP_Error( 'host_not_allowed', 'That download host is not allowed.', array( 'status' => 400 ) );
		}
		// GitHub release assets: /…/releases/download/…/*.zip
		// wp.org packages: /plugin/*.zip
		$path = isset( $parts['path'] ) ? $parts['path'] : '';
		if ( ! preg_match( '/\.zip$/i', $path ) ) {
			return new WP_Error( 'not_zip', 'Install URL must point at a .zip file.', array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Plugin_Upgrader hooks upgrader_process_complete → wp_clean_plugins_cache,
	 * which deletes the entire update_plugins transient. Without restoration,
	 * updating Jetpack wipes every other pending offer until the next full
	 * wp_update_plugins() (notifications + Extensions badges all go blank).
	 *
	 * Snapshot response[] before the upgrade; put back every file that was
	 * not successfully updated. Prefer any post-upgrade responses if present.
	 *
	 * @param array $pending_before file => update object, from before upgrade.
	 * @param array $updated_files  Plugin files that upgraded successfully.
	 */
	public static function restore_plugin_update_offers( array $pending_before, array $updated_files ) {
		foreach ( $updated_files as $f ) {
			unset( $pending_before[ $f ] );
		}
		if ( ! $pending_before && ! $updated_files ) {
			return;
		}
		$current = get_site_transient( 'update_plugins' );
		if ( ! is_object( $current ) ) {
			$current = (object) array(
				'last_checked' => time(),
				'checked'      => array(),
				'response'     => array(),
				'no_update'    => array(),
			);
		}
		$response = ( isset( $current->response ) && is_array( $current->response ) )
			? $current->response
			: array();
		// Re-seed offers the clean wiped; keep fresher entries if any exist.
		foreach ( $pending_before as $file => $data ) {
			if ( ! isset( $response[ $file ] ) ) {
				$response[ $file ] = $data;
			}
		}
		foreach ( $updated_files as $f ) {
			unset( $response[ $f ] );
		}
		$current->response     = $response;
		$current->last_checked = time();
		set_site_transient( 'update_plugins', $current );
	}

	/**
	 * Update one plugin by its plugin file (e.g. "akismet/akismet.php").
	 */
	public static function update_single_plugin( WP_REST_Request $request ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';

		$file = sanitize_text_field( $request['plugin'] );
		// Normalize: list rows use "dir/file", the update map uses ".php".
		if ( $file && ! str_ends_with( $file, '.php' ) ) {
			$file .= '.php';
		}

		// Only re-hit the update API when this file is not already known
		// pending. Calling wp_update_plugins() on every single-plugin click
		// races when two updates fire close together (network + transient
		// rewrite) and is the slow half of "Failed to fetch" after a second
		// concurrent upgrade recycled the worker.
		$updates = get_site_transient( 'update_plugins' );
		if ( ! $updates || empty( $updates->response[ $file ] ) ) {
			wp_update_plugins();
			$updates = get_site_transient( 'update_plugins' );
		}
		if ( ! $updates || empty( $updates->response[ $file ] ) ) {
			return new WP_Error( 'no_update', 'No update available for that plugin.', array( 'status' => 400 ) );
		}

		// Snapshot every pending offer — bulk_upgrade wipes the transient.
		$pending_before = is_array( $updates->response ) ? $updates->response : array();

		$was_active  = is_plugin_active( $file );
		$was_network = is_plugin_active_for_network( $file );

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		// bulk_upgrade (like core's own AJAX single-plugin update) — upgrade()
		// deactivates an active plugin and leaves reactivation to the caller,
		// which is how updating an active plugin here used to strand it inactive.
		$results = $upgrader->bulk_upgrade( array( $file ) );
		$result  = is_array( $results ) && isset( $results[ $file ] ) ? $results[ $file ] : false;

		if ( ! $result || is_wp_error( $result ) ) {
			// Even on failure the upgrader may have cleaned the cache — put offers back.
			self::restore_plugin_update_offers( $pending_before, array() );
			$errors = $skin->get_error_messages();
			return new WP_Error( 'update_failed', $errors ? implode( ' ', (array) $errors ) : 'Update failed.', array( 'status' => 500 ) );
		}

		// Safety net: whatever the upgrade path did, an active plugin stays active.
		if ( $was_active && ! is_plugin_active( $file ) ) {
			activate_plugin( $file, '', $was_network, true );
		}

		// Put every other pending offer back (Jetpack update must not clear
		// the rest of the notification panel).
		self::restore_plugin_update_offers( $pending_before, array( $file ) );

		$plugins = get_plugins();
		return rest_ensure_response(
			array(
				'updated' => true,
				'version' => isset( $plugins[ $file ]['Version'] ) ? $plugins[ $file ]['Version'] : '',
			)
		);
	}

	/**
	 * Core version + whether an update is on offer. wp_version_check() is
	 * self-throttling, so calling it here just keeps the offer fresh.
	 */
	public static function core_status() {
		require_once ABSPATH . 'wp-admin/includes/update.php';
		wp_version_check();
		$offers = get_core_updates();
		$offer  = is_array( $offers ) && $offers && 'upgrade' === $offers[0]->response ? $offers[0] : null;
		// The loaded $GLOBALS['wp_version'] can be stale right after an update —
		// read the file that ships with the current core instead.
		include ABSPATH . WPINC . '/version.php';
		return rest_ensure_response(
			array(
				'version' => $wp_version,
				// True when files updated but the DB migration hasn't run —
				// happens when the update request's connection dropped before
				// its upgrade.php loopback. The client poll finishes the job.
				'dbUpgrade' => (int) get_option( 'db_version' ) < (int) $wp_db_version,
				'update'  => $offer ? array(
					'version' => $offer->current,
					'locale'  => $offer->locale,
				) : null,
			)
		);
	}

	/**
	 * Run the offered core update — the same Core_Upgrader path as
	 * wp-admin/update-core.php, including its automatic rollback protections.
	 */
	public static function core_update() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 ); // the package download + copy can be slow
		}

		wp_version_check();
		$offers = get_core_updates();
		$offer  = is_array( $offers ) && $offers && 'upgrade' === $offers[0]->response ? $offers[0] : null;
		if ( ! $offer ) {
			return new WP_Error( 'no_update', 'WordPress is already up to date.', array( 'status' => 400 ) );
		}

		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Core_Upgrader( $skin );
		$result   = $upgrader->upgrade( $offer );

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'update_failed', $result->get_error_message() ?: 'Core update failed.', array( 'status' => 500 ) );
		}

		// Run any database migration with the NEW code — the standard
		// upgrade.php step, hit over loopback (what wp-admin does after its
		// post-update redirect).
		wp_remote_get( admin_url( 'upgrade.php?step=1' ), array( 'timeout' => 60, 'sslverify' => false ) );

		include ABSPATH . WPINC . '/version.php';
		return rest_ensure_response(
			array(
				'updated' => true,
				'version' => $wp_version,
			)
		);
	}

	/**
	 * Purge every active cache layer (adapters/cache-purge.php). Each
	 * provider runs in its own Throwable guard so one broken cache plugin
	 * can't fail the action for the rest.
	 */
	public static function cache_purge( WP_REST_Request $request ) {
		$only    = sanitize_text_field( (string) $request->get_param( 'provider' ) );
		$purged  = array();
		$failed  = array();
		$matched = false;
		foreach ( minn_admin_cache_purgers() as $p ) {
			if ( $only && $p['id'] !== $only ) {
				continue;
			}
			$matched = true;
			try {
				call_user_func( $p['purge'] );
				$purged[] = $p['name'];
			} catch ( \Throwable $e ) {
				$failed[] = $p['name'];
			}
		}
		if ( ! $matched ) {
			return new WP_Error( 'no_cache', 'No cache layer detected on this site.', array( 'status' => 400 ) );
		}
		return rest_ensure_response(
			array(
				'purged' => $purged,
				'failed' => $failed,
			)
		);
	}

	/**
	 * WP 7.0 Connectors (Settings → Connectors): the core registry reduced
	 * to a display model. Raw keys never leave the server — only the source
	 * (env / constant / database / none) and the last four characters of a
	 * database-stored key ride out. Saves go through core's OWN
	 * wp/v2/settings route, where core masks responses and validates
	 * AI-provider keys against the live provider; a connector's setting is
	 * only REST-registered while its companion plugin is active, so the
	 * client gates the key field on `registered`.
	 */
	public static function connectors() {
		if ( ! function_exists( 'wp_get_connectors' ) ) {
			return rest_ensure_response(
				array(
					'supported'  => false,
					'connectors' => array(),
				)
			);
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$registered = get_registered_settings();
		$out        = array();
		foreach ( wp_get_connectors() as $id => $c ) {
			$auth    = isset( $c['authentication'] ) && is_array( $c['authentication'] ) ? $c['authentication'] : array();
			$setting = isset( $auth['setting_name'] ) ? (string) $auth['setting_name'] : '';
			// Key source in core's own precedence (env → constant →
			// database). Core's resolver is @access private, so the three
			// checks are mirrored here rather than called.
			$source  = 'none';
			$db_key  = '';
			$env     = isset( $auth['env_var_name'] ) ? (string) $auth['env_var_name'] : '';
			$const   = isset( $auth['constant_name'] ) ? (string) $auth['constant_name'] : '';
			if ( '' !== $env && false !== getenv( $env ) && '' !== getenv( $env ) ) {
				$source = 'env';
			} elseif ( '' !== $const && defined( $const ) && is_string( constant( $const ) ) && '' !== constant( $const ) ) {
				$source = 'constant';
			} elseif ( '' !== $setting ) {
				$db_key = (string) get_option( $setting, '' );
				if ( '' !== $db_key ) {
					$source = 'database';
				}
			}
			$plugin = null;
			if ( ! empty( $c['plugin']['file'] ) && is_string( $c['plugin']['file'] ) ) {
				$file      = $c['plugin']['file'];
				$installed = file_exists( WP_PLUGIN_DIR . '/' . $file );
				$active    = false;
				if ( isset( $c['plugin']['is_active'] ) && is_callable( $c['plugin']['is_active'] ) ) {
					try {
						$active = (bool) call_user_func( $c['plugin']['is_active'] );
					} catch ( \Throwable $e ) {
						$active = false;
					}
				} else {
					$active = is_plugin_active( $file );
				}
				$plugin = array(
					'file'        => $file,
					'slug'        => dirname( $file ),
					'installed'   => $installed,
					'active'      => $active,
					'canInstall'  => ! $installed && current_user_can( 'install_plugins' ) && wp_is_file_mod_allowed( 'install_plugins' ),
					'canActivate' => $installed && ! $active && current_user_can( 'activate_plugins' ),
				);
			}
			$out[] = array(
				'id'             => (string) $id,
				'name'           => isset( $c['name'] ) ? (string) $c['name'] : (string) $id,
				'description'    => isset( $c['description'] ) ? (string) $c['description'] : '',
				'type'           => isset( $c['type'] ) ? (string) $c['type'] : '',
				'method'         => isset( $auth['method'] ) ? (string) $auth['method'] : 'none',
				'settingName'    => $setting,
				'registered'     => '' !== $setting && isset( $registered[ $setting ] ),
				'credentialsUrl' => isset( $auth['credentials_url'] ) ? (string) $auth['credentials_url'] : '',
				'constantName'   => $const,
				'envVarName'     => $env,
				'source'         => $source,
				'keyTail'        => ( 'database' === $source && strlen( $db_key ) > 8 ) ? substr( $db_key, -4 ) : '',
				'plugin'         => $plugin,
			);
		}
		return rest_ensure_response(
			array(
				'supported'  => true,
				'connectors' => $out,
			)
		);
	}

	/**
	 * Duplicate a post as a new draft owned by the current user — content,
	 * excerpt, taxonomy terms and meta (builder data, featured image, SEO)
	 * ride along, so the copy renders like the original everywhere.
	 */
	public static function duplicate_post( WP_REST_Request $request ) {
		$post = get_post( (int) $request['id'] );
		if ( ! $post || 'trash' === $post->post_status ) {
			return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
		}
		$new_id = wp_insert_post(
			array(
				'post_title'     => $post->post_title ? $post->post_title : 'Untitled',
				'post_content'   => $post->post_content,
				'post_excerpt'   => $post->post_excerpt,
				'post_type'      => $post->post_type,
				'post_status'    => 'draft',
				'post_parent'    => $post->post_parent,
				'menu_order'     => $post->menu_order,
				'comment_status' => $post->comment_status,
				'ping_status'    => $post->ping_status,
				'post_password'  => $post->post_password,
				'post_author'    => get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}
		foreach ( get_object_taxonomies( $post->post_type ) as $tax ) {
			$terms = wp_get_object_terms( $post->ID, $tax, array( 'fields' => 'ids' ) );
			if ( $terms && ! is_wp_error( $terms ) ) {
				wp_set_object_terms( $new_id, $terms, $tax );
			}
		}
		$skip_meta = array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_old_date' );
		foreach ( get_post_meta( $post->ID ) as $key => $values ) {
			if ( in_array( $key, $skip_meta, true ) ) {
				continue;
			}
			foreach ( $values as $value ) {
				// get_post_meta(single=false) returns serialized-form strings;
				// add_post_meta would double-serialize without the unserialize.
				add_post_meta( $new_id, $key, wp_slash( maybe_unserialize( $value ) ) );
			}
		}
		return rest_ensure_response(
			array(
				'id'    => $new_id,
				'title' => get_the_title( $new_id ),
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

		$pending_before = is_array( $updates->response ) ? $updates->response : array();
		$files          = array_keys( $pending_before );
		$skin           = new WP_Ajax_Upgrader_Skin();
		$upgrader       = new Plugin_Upgrader( $skin );
		$results        = $upgrader->bulk_upgrade( $files );

		$updated = array();
		$failed  = array();
		foreach ( (array) $results as $file => $result ) {
			if ( $result && ! is_wp_error( $result ) ) {
				$updated[] = $file;
			} else {
				$failed[] = $file;
			}
		}

		// Restore offers for anything that failed (or was not in the result set).
		self::restore_plugin_update_offers( $pending_before, $updated );

		return rest_ensure_response(
			array(
				'updated' => $updated,
				'failed'  => $failed,
				'errors'  => $skin->get_error_messages(),
			)
		);
	}

	/**
	 * System diagnostics: WordPress, PHP, database, server and directory facts
	 * a developer wants at a glance, plus derived health checks. Every probe is
	 * defensive — a missing constant or a slow DB never fatals the page.
	 */
	public static function system_info() {
		global $wpdb;

		$bytes = function ( $val ) {
			// Parse a php.ini shorthand size (128M, 1G, -1) into bytes.
			$val = trim( (string) $val );
			if ( '' === $val || '-1' === $val ) {
				return -1;
			}
			$unit = strtolower( substr( $val, -1 ) );
			$num  = (float) $val;
			switch ( $unit ) {
				case 'g':
					$num *= 1024;
					// fall through.
				case 'm':
					$num *= 1024;
					// fall through.
				case 'k':
					$num *= 1024;
			}
			return (int) $num;
		};

		// --- WordPress -----------------------------------------------------
		$upload = wp_get_upload_dir();
		$theme  = wp_get_theme();
		$parent = $theme->parent();
		$cron   = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		$wordpress = array(
			'Version'          => get_bloginfo( 'version' ),
			'Environment'      => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
			'Site URL'         => site_url(),
			'Home URL'         => home_url(),
			// The REAL login URL — wp_login_url() honors login-hiders (WPS Hide
			// Login and friends filter it), so this shows the custom slug when
			// one is active rather than a wp-login.php that would 404.
			'Login URL'        => wp_login_url(),
			'Multisite'        => is_multisite() ? 'Yes (' . get_blog_count() . ' sites)' : 'No',
			'Language'         => get_locale(),
			'Timezone'         => wp_timezone_string() ? wp_timezone_string() : (string) get_option( 'gmt_offset' ),
			'Permalinks'       => get_option( 'permalink_structure' ) ? get_option( 'permalink_structure' ) : 'Plain',
			'Debug mode'       => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? 'On' . ( ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) ? ' + log' : '' ) : 'Off',
			'Object cache'     => wp_using_ext_object_cache() ? 'External (persistent)' : 'None (transient)',
			'WP-Cron'          => $cron ? 'Disabled (external)' : 'Enabled',
			'Memory limit'     => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '(default)',
			'Max memory limit' => defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : '(default)',
			'Active theme'     => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) . ( $parent ? ' (child of ' . $parent->get( 'Name' ) . ')' : '' ),
			'Active plugins'   => (string) count( (array) get_option( 'active_plugins', array() ) ) . ( count( (array) ( function_exists( 'wp_get_mu_plugins' ) ? wp_get_mu_plugins() : array() ) ) ? ' + ' . count( wp_get_mu_plugins() ) . ' mu' : '' ),
		);

		// --- PHP -----------------------------------------------------------
		$exts = array( 'curl', 'gd', 'imagick', 'mbstring', 'xml', 'zip', 'intl', 'openssl', 'opcache', 'redis', 'memcached', 'apcu', 'exif', 'fileinfo', 'sodium' );
		$loaded = array();
		foreach ( $exts as $e ) {
			if ( extension_loaded( $e ) ) {
				$loaded[] = $e;
			}
		}
		$opcache = function_exists( 'opcache_get_status' ) ? @opcache_get_status( false ) : false;
		$php     = array(
			'Version'             => PHP_VERSION,
			'Interface (SAPI)'    => PHP_SAPI,
			'memory_limit'        => ini_get( 'memory_limit' ),
			'max_execution_time'  => ini_get( 'max_execution_time' ) . 's',
			'upload_max_filesize' => ini_get( 'upload_max_filesize' ),
			'post_max_size'       => ini_get( 'post_max_size' ),
			'max_input_vars'      => ini_get( 'max_input_vars' ),
			'max_input_time'      => ini_get( 'max_input_time' ) . 's',
			'OPcache'             => ( is_array( $opcache ) && ! empty( $opcache['opcache_enabled'] ) ) ? 'Enabled' : ( function_exists( 'opcache_get_status' ) ? 'Disabled' : 'Not installed' ),
			'Extensions'          => implode( ', ', $loaded ),
			'cURL'                => function_exists( 'curl_version' ) ? ( curl_version()['version'] ?? 'yes' ) : 'no',
		);

		// --- Database ------------------------------------------------------
		$db_version = $wpdb->get_var( 'SELECT VERSION()' );
		$server_info = '';
		if ( isset( $wpdb->dbh ) && $wpdb->dbh instanceof mysqli ) {
			$server_info = @mysqli_get_server_info( $wpdb->dbh );
		}
		$is_maria = false !== stripos( (string) ( $server_info ? $server_info : $db_version ), 'maria' );
		// Table count + total data/index size, scoped to this install's prefix
		// (fast — reads information_schema metadata, not the tables).
		$tables = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT table_name AS name, ( data_length + index_length ) AS size, table_rows AS rows_count
				 FROM information_schema.TABLES WHERE table_schema = %s AND table_name LIKE %s
				 ORDER BY size DESC',
				DB_NAME,
				$wpdb->esc_like( $wpdb->prefix ) . '%'
			)
		);
		$db_size    = 0;
		$top_tables = array();
		foreach ( (array) $tables as $i => $tbl ) {
			$db_size += (int) $tbl->size;
			if ( $i < 5 ) {
				$top_tables[] = array(
					'name' => $tbl->name,
					'size' => size_format( (int) $tbl->size, 1 ),
					'rows' => number_format_i18n( (int) $tbl->rows_count ),
				);
			}
		}
		// --- Autoload / transients / cron: the silent-rot trio --------------
		// Autoloaded options load on EVERY request — the classic hidden
		// performance tax. WP 6.6 split autoload into yes/on/auto* variants;
		// core's resolver is the source of truth where it exists.
		$autoload_in  = function_exists( 'wp_autoload_values_to_autoload' )
			? array_values( (array) wp_autoload_values_to_autoload() )
			: array( 'yes', 'on', 'auto-on', 'auto' );
		$placeholders = implode( ',', array_fill( 0, count( $autoload_in ), '%s' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above; core tables.
		$autoload_totals = $wpdb->get_row( $wpdb->prepare(
			"SELECT COUNT(*) AS c, COALESCE(SUM(LENGTH(option_value)),0) AS s FROM {$wpdb->options} WHERE autoload IN ($placeholders)",
			$autoload_in
		) );
		$autoload_top = $wpdb->get_results( $wpdb->prepare(
			"SELECT option_name AS name, LENGTH(option_value) AS len FROM {$wpdb->options} WHERE autoload IN ($placeholders) ORDER BY len DESC LIMIT 8",
			$autoload_in
		) );
		// Expired transients never clean themselves up without object caching
		// — dead weight in the options table. '_transient_timeout_' is 19
		// chars, so the paired value key starts at position 20.
		$expired_transients = $wpdb->get_row(
			"SELECT COUNT(*) AS c, COALESCE(SUM(LENGTH(b.option_value)),0) AS s
			 FROM {$wpdb->options} a
			 LEFT JOIN {$wpdb->options} b ON b.option_name = CONCAT('_transient_', SUBSTRING(a.option_name, 20))
			 WHERE a.option_name LIKE '\\_transient\\_timeout\\_%' AND a.option_value < UNIX_TIMESTAMP()"
		);
		// phpcs:enable
		$autoload_size  = (int) $autoload_totals->s;
		$autoload       = array(
			'count'      => (int) $autoload_totals->c,
			'size'       => $autoload_size,
			'size_human' => size_format( $autoload_size, 1 ),
			'top'        => array_map(
				function ( $r ) {
					return array( 'name' => $r->name, 'size' => size_format( (int) $r->len, 1 ) );
				},
				(array) $autoload_top
			),
		);

		// Cron: overdue events mean scheduled posts and emails silently stall.
		$cron_events  = 0;
		$cron_overdue = 0;
		$cron_next    = null;
		foreach ( (array) _get_cron_array() as $ts => $hooks ) {
			if ( ! is_numeric( $ts ) ) {
				continue; // the 'version' key
			}
			$n = 0;
			foreach ( (array) $hooks as $entries ) {
				$n += count( (array) $entries );
			}
			$cron_events += $n;
			if ( $ts < time() - 5 * MINUTE_IN_SECONDS ) {
				$cron_overdue += $n;
			}
			if ( null === $cron_next ) {
				$cron_next = (int) $ts; // the array is time-ordered
			}
		}
		$cron_disabled     = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;
		$wordpress['Cron'] = $cron_events . ' event' . ( 1 === $cron_events ? '' : 's' )
			. ( $cron_next ? ( $cron_next <= time() ? ', next due now' : ', next in ' . human_time_diff( time(), $cron_next ) ) : '' )
			. ( $cron_disabled ? ' · WP-Cron disabled (system cron expected)' : '' );

		$database = array(
			'Engine'             => $is_maria ? 'MariaDB' : 'MySQL',
			'Version'            => $server_info ? $server_info : $db_version,
			'Host'               => DB_HOST,
			'Name'               => DB_NAME,
			'Charset'            => defined( 'DB_CHARSET' ) ? DB_CHARSET : $wpdb->charset,
			'Collation'          => $wpdb->collate ? $wpdb->collate : '(default)',
			'Prefix'             => $wpdb->prefix,
			'Tables'             => (string) count( (array) $tables ),
			'Size'               => size_format( $db_size, 1 ),
			'Expired transients' => number_format_i18n( (int) $expired_transients->c )
				. ( (int) $expired_transients->s > 0 ? ' (' . size_format( (int) $expired_transients->s, 1 ) . ')' : '' ),
		);

		// --- Server & filesystem -------------------------------------------
		// Managed hosts (Kinsta) strip disk_*_space + php_uname from the web SAPI
		// via disable_functions — calling one is a FATAL, and @ does not save you.
		// CLI shows them enabled, so only a real web request catches this.
		$uploads_writable = wp_is_writable( $upload['basedir'] );
		$disk_free        = function_exists( 'disk_free_space' ) ? @disk_free_space( ABSPATH ) : false;
		$disk_total       = function_exists( 'disk_total_space' ) ? @disk_total_space( ABSPATH ) : false;
		$has_uname        = function_exists( 'php_uname' );
		$server           = array(
			'Web server'      => isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : 'Unknown',
			'Protocol'        => isset( $_SERVER['SERVER_PROTOCOL'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_PROTOCOL'] ) ) : '',
			'HTTPS'           => is_ssl() ? 'Yes' : 'No',
			'Operating system'=> $has_uname ? php_uname( 's' ) . ' ' . php_uname( 'r' ) : PHP_OS,
			'Architecture'    => $has_uname ? php_uname( 'm' ) : ( PHP_INT_SIZE === 8 ? '64-bit' : '32-bit' ),
			'Server IP'       => isset( $_SERVER['SERVER_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_ADDR'] ) ) : '',
			'Uploads writable'=> $uploads_writable ? 'Yes' : 'No',
			'Disk free'       => ( $disk_free && $disk_total ) ? size_format( $disk_free ) . ' free of ' . size_format( $disk_total ) : 'Unknown',
		);

		// --- Health checks (pass / warn / fail) ----------------------------
		$php_ok    = version_compare( PHP_VERSION, '8.1', '>=' );
		$php_warn  = ! $php_ok && version_compare( PHP_VERSION, '7.4', '>=' );
		$mem_bytes = $bytes( ini_get( 'memory_limit' ) );
		$env       = $wordpress['Environment'];
		$debug_on  = defined( 'WP_DEBUG' ) && WP_DEBUG;
		$core_t     = get_site_transient( 'update_core' );
		$core_offer = ( $core_t && ! empty( $core_t->updates ) && 'upgrade' === $core_t->updates[0]->response )
			? $core_t->updates[0]->version : '';
		$checks    = array(
			array(
				'label'  => 'WordPress version',
				'status' => $core_offer ? 'warn' : 'pass',
				'detail' => $core_offer
					? get_bloginfo( 'version' ) . ' installed — WordPress ' . $core_offer . ' is available'
					: get_bloginfo( 'version' ) . ' is current',
			),
			array(
				'label'  => 'PHP version',
				'status' => $php_ok ? 'pass' : ( $php_warn ? 'warn' : 'fail' ),
				'detail' => $php_ok ? PHP_VERSION . ' is current' : PHP_VERSION . ' is past its supported life — upgrade to 8.2+',
			),
			array(
				'label'  => 'HTTPS',
				'status' => is_ssl() ? 'pass' : 'warn',
				'detail' => is_ssl() ? 'Served over TLS' : 'This request is not over HTTPS',
			),
			array(
				'label'  => 'Persistent object cache',
				'status' => wp_using_ext_object_cache() ? 'pass' : 'warn',
				'detail' => wp_using_ext_object_cache() ? 'A drop-in is active' : 'Redis/Memcached would speed up repeat queries',
			),
			array(
				'label'  => 'Memory limit',
				'status' => ( $mem_bytes < 0 || $mem_bytes >= 256 * 1024 * 1024 ) ? 'pass' : ( $mem_bytes >= 128 * 1024 * 1024 ? 'warn' : 'fail' ),
				'detail' => ini_get( 'memory_limit' ) . ' available to PHP',
			),
			array(
				'label'  => 'OPcache',
				'status' => ( is_array( $opcache ) && ! empty( $opcache['opcache_enabled'] ) ) ? 'pass' : 'warn',
				'detail' => ( is_array( $opcache ) && ! empty( $opcache['opcache_enabled'] ) ) ? 'Bytecode caching is on' : 'Not enabled — pages recompile each request',
			),
			array(
				'label'  => 'Debug mode',
				'status' => ( $debug_on && 'production' === $env ) ? 'warn' : 'pass',
				'detail' => ( $debug_on && 'production' === $env ) ? 'WP_DEBUG is on in a production environment' : ( $debug_on ? 'On (fine for ' . $env . ')' : 'Off' ),
			),
			array(
				'label'  => 'Uploads writable',
				'status' => $uploads_writable ? 'pass' : 'fail',
				'detail' => $uploads_writable ? 'The uploads directory accepts writes' : 'Uploads directory is not writable',
			),
			array(
				'label'  => 'Autoload size',
				// The usual guidance: under ~800 KB is healthy, past a few MB
				// every request pays a real tax.
				'status' => $autoload_size < 800 * 1024 ? 'pass' : ( $autoload_size < 3 * 1024 * 1024 ? 'warn' : 'fail' ),
				'detail' => $autoload['size_human'] . ' across ' . number_format_i18n( $autoload['count'] ) . ' options'
					. ( $autoload_size < 800 * 1024 ? ' — healthy' : ' loads on every request — see the top offenders in the Database card' ),
			),
			array(
				'label'  => 'Cron',
				'status' => $cron_overdue > 0 ? 'warn' : 'pass',
				'detail' => $cron_overdue > 0
					? $cron_overdue . ' overdue event' . ( 1 === $cron_overdue ? '' : 's' ) . ' — cron may be stalled' . ( $cron_disabled ? ' (WP-Cron is disabled; is the system cron running?)' : '' )
					: $cron_events . ' scheduled event' . ( 1 === $cron_events ? '' : 's' ) . ', none overdue',
			),
		);

		// Loopback + REST self-checks: core's OWN Site Health tests, each a
		// real HTTP request back to this site (broken loopback silently
		// kills cron, scheduled posts and background updates). Cached 15
		// minutes so the System page doesn't pay two self-requests per load.
		$self = get_transient( 'minn_admin_self_checks' );
		if ( ! is_array( $self ) && empty( $_COOKIE ) ) {
			// CLI/cron context: core's REST test forwards the requester's
			// cookies + nonce, so it would report a FALSE failure here and
			// cache it. Skip the rows rather than poison the transient.
			$self = array();
		} elseif ( ! is_array( $self ) ) {
			$self = array();
			if ( ! class_exists( 'WP_Site_Health' ) ) {
				require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
			}
			try {
				$health = WP_Site_Health::get_instance();
				$loop   = $health->can_perform_loopback();
				$self['loopback'] = array(
					'status'  => isset( $loop->status ) ? (string) $loop->status : 'critical',
					'message' => isset( $loop->message ) ? wp_strip_all_tags( (string) $loop->message ) : '',
				);
				$rest_test    = $health->get_test_rest_availability();
				$self['rest'] = array(
					'status'  => isset( $rest_test['status'] ) ? (string) $rest_test['status'] : 'critical',
					'message' => isset( $rest_test['label'] ) ? wp_strip_all_tags( (string) $rest_test['label'] ) : '',
				);
			} catch ( \Throwable $e ) {
				$self = array();
			}
			set_transient( 'minn_admin_self_checks', $self, 15 * MINUTE_IN_SECONDS );
		}
		$grade = function ( $status ) {
			return 'good' === $status ? 'pass' : ( 'recommended' === $status ? 'warn' : 'fail' );
		};
		if ( isset( $self['loopback'] ) ) {
			$checks[] = array(
				'label'  => 'Loopback requests',
				'status' => $grade( $self['loopback']['status'] ),
				'detail' => 'good' === $self['loopback']['status']
					? 'The site can reach itself (cron and scheduled posts depend on this)'
					: ( $self['loopback']['message'] ? $self['loopback']['message'] : 'Loopback requests are failing' ),
			);
		}
		if ( isset( $self['rest'] ) ) {
			$checks[] = array(
				'label'  => 'REST API self-check',
				'status' => $grade( $self['rest']['status'] ),
				'detail' => 'good' === $self['rest']['status']
					? 'The REST API answers external requests (Minn itself rides it)'
					: ( $self['rest']['message'] ? $self['rest']['message'] : 'The REST API did not answer' ),
			);
		}

		// Backups — only when a backup plugin is present to report on.
		// UpdraftPlus first (existing health slot owner); WPvivid when
		// Updraft is not the active reporter.
		$bk_provider = null;
		$bk          = null;
		if ( function_exists( 'minn_admin_updraftplus_active' ) && minn_admin_updraftplus_active() ) {
			$bk_provider = 'UpdraftPlus';
			$bk          = minn_admin_updraft_last();
		} elseif ( function_exists( 'minn_admin_wpvivid_active' ) && minn_admin_wpvivid_active() ) {
			$bk_provider = 'WPvivid';
			$bk          = minn_admin_wpvivid_last();
		}
		if ( $bk_provider ) {
			$age = $bk ? time() - $bk['time'] : null;
			if ( ! $bk ) {
				$bk_status = 'warn';
				$bk_detail = $bk_provider . ' is active but no backup has completed yet';
			} elseif ( ! $bk['success'] ) {
				$bk_status = 'fail';
				$bk_detail = 'The last backup reported errors (' . human_time_diff( $bk['time'] ) . ' ago)';
			} elseif ( $age <= 36 * HOUR_IN_SECONDS ) {
				$bk_status = 'pass';
				$bk_detail = 'Last backup completed ' . human_time_diff( $bk['time'] ) . ' ago';
			} elseif ( $age <= 8 * DAY_IN_SECONDS ) {
				$bk_status = 'warn';
				$bk_detail = 'Last backup was ' . human_time_diff( $bk['time'] ) . ' ago — consider a fresher schedule';
			} else {
				$bk_status = 'fail';
				$bk_detail = 'Last backup was ' . human_time_diff( $bk['time'] ) . ' ago';
			}
			array_splice( $checks, 1, 0, array( array(
				'label'  => 'Backups',
				'status' => $bk_status,
				'detail' => $bk_detail,
			) ) );
		}

		// Site visibility — surfaced high because "the public can't see the
		// site" is one of the loudest things Minn can tell an owner.
		if ( function_exists( 'minn_admin_visibility_check' ) ) {
			$vis = minn_admin_visibility_check();
			if ( is_array( $vis ) ) {
				array_splice( $checks, 1, 0, array( $vis ) );
			}
		}

		// SSL enforcement (Really Simple SSL) — near the HTTPS check.
		if ( function_exists( 'minn_admin_rsssl_check' ) ) {
			$rsssl = minn_admin_rsssl_check();
			if ( is_array( $rsssl ) ) {
				$checks[] = $rsssl;
			}
		}

		// Security posture — Wordfence firewall + scan rows (adapters/
		// wordfence.php). Appended (not spliced high) since they're informative
		// rather than the loudest thing on the page.
		if ( function_exists( 'minn_admin_wordfence_checks' ) ) {
			foreach ( minn_admin_wordfence_checks() as $wf_check ) {
				$checks[] = $wf_check;
			}
		}

		// Solid Security posture (adapters/solid-security.php), same shape.
		if ( function_exists( 'minn_admin_solid_security_checks' ) ) {
			foreach ( minn_admin_solid_security_checks() as $ss_check ) {
				$checks[] = $ss_check;
			}
		}

		// Redis Object Cache drop-in + connection (adapters/cache-purge.php).
		// Only renders when the plugin is loaded; complements the generic
		// "Persistent object cache" row with vendor-specific status.
		if ( function_exists( 'minn_admin_redis_object_cache_checks' ) ) {
			foreach ( minn_admin_redis_object_cache_checks() as $redis_check ) {
				$checks[] = $redis_check;
			}
		}

		// Licenses — read-only visibility (adapters/licenses.php); the health
		// check only renders when the site has license-wanting components.
		$licenses = function_exists( 'minn_admin_licenses' ) ? minn_admin_licenses() : null;
		if ( $licenses && ! empty( $licenses['items'] ) ) {
			$sum  = $licenses['summary'];
			$bad  = $sum['expired'] + $sum['invalid'];
			$soft = $sum['missing'] + $sum['unknown'];
			$bits = array();
			foreach ( array( 'expired', 'invalid', 'missing', 'unknown' ) as $k ) {
				if ( $sum[ $k ] ) {
					$bits[] = $sum[ $k ] . ' ' . $k;
				}
			}
			$checks[] = array(
				'label'  => 'Licenses',
				'status' => $bad ? 'fail' : ( $soft ? 'warn' : 'pass' ),
				'detail' => $bits
					? implode( ', ', $bits ) . ' of ' . count( $licenses['items'] ) . ' paid components — see the Licenses card'
					: 'All ' . count( $licenses['items'] ) . ' paid components hold a valid license',
			);
		}

		return rest_ensure_response(
			array(
				'generated'  => current_time( 'c' ),
				'checks'     => $checks,
				'config'     => self::config_state(),
				'licenses'   => $licenses,
				'extensions' => self::extensions_manifest(),
				// Live registry of everything hooked into Minn, with owner
				// attribution + descriptor-contract problems — the feedback
				// loop for integration authors (class-minn-admin-surfaces.php).
				'integrations' => Minn_Admin_Surfaces::integrations(),
				'groups'     => array(
					array( 'title' => 'WordPress', 'icon' => 'wp', 'rows' => self::kv_rows( $wordpress ) ),
					array( 'title' => 'PHP', 'icon' => 'php', 'rows' => self::kv_rows( $php ) ),
					array( 'title' => 'Database', 'icon' => 'database', 'rows' => self::kv_rows( $database ), 'tables' => $top_tables, 'autoload' => $autoload ),
					array( 'title' => 'Server', 'icon' => 'server', 'rows' => self::kv_rows( $server ) ),
				),
			)
		);
	}

	/**
	 * Installed plugins (active first), must-use plugins, and themes with
	 * versions — the diagnostic manifest a developer pastes into a ticket.
	 */
	private static function extensions_manifest() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$active = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}
		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			$plugins[] = array(
				'name'    => $data['Name'],
				'version' => $data['Version'] ? $data['Version'] : '—',
				'active'  => in_array( $file, $active, true ),
			);
		}
		usort(
			$plugins,
			function ( $a, $b ) {
				if ( $a['active'] !== $b['active'] ) {
					return $a['active'] ? -1 : 1;
				}
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		$mu = array();
		foreach ( (array) get_mu_plugins() as $file => $data ) {
			$mu[] = array(
				'name'    => ! empty( $data['Name'] ) ? $data['Name'] : $file,
				'version' => ! empty( $data['Version'] ) ? $data['Version'] : '',
				'active'  => true,
			);
		}

		$current = get_stylesheet();
		$themes  = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			$parent   = $theme->parent();
			$themes[] = array(
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ) ? $theme->get( 'Version' ) : '—',
				'active'  => $slug === $current,
				'parent'  => $parent ? $parent->get( 'Name' ) : '',
			);
		}
		usort(
			$themes,
			function ( $a, $b ) {
				if ( $a['active'] !== $b['active'] ) {
					return $a['active'] ? -1 : 1;
				}
				return strcasecmp( $a['name'], $b['name'] );
			}
		);

		return array(
			'plugins'        => $plugins,
			'active_plugins' => count( array_filter( $plugins, function ( $p ) { return $p['active']; } ) ),
			'mu_plugins'     => $mu,
			'themes'         => $themes,
		);
	}

	/**
	 * The whitelisted, boolean-only wp-config constants Minn will toggle. This
	 * is the ONLY set the write endpoint accepts — never an arbitrary name.
	 */
	private static function debug_constants() {
		return array(
			'WP_DEBUG'         => array( 'label' => 'Debug mode', 'desc' => 'Master switch for WordPress debugging.' ),
			'WP_DEBUG_LOG'     => array( 'label' => 'Log to file', 'desc' => 'Write notices and errors to wp-content/debug.log.' ),
			'WP_DEBUG_DISPLAY' => array( 'label' => 'Show errors on screen', 'desc' => 'Render errors in the page. Leave off in production and read the log instead.' ),
			'SCRIPT_DEBUG'     => array( 'label' => 'Unminified assets', 'desc' => 'Load the full-length core and plugin JS/CSS.' ),
			'SAVEQUERIES'      => array( 'label' => 'Log database queries', 'desc' => 'Record every query for inspection — a real performance cost; turn off when done.' ),
		);
	}

	/** Regex matching a `define( 'NAME', <value> );` line, any quote/spacing. */
	private static function const_pattern( $name ) {
		return "/define\\(\\s*(['\"])" . preg_quote( $name, '/' ) . "\\1\\s*,\\s*[^)]*\\)\\s*;/";
	}

	/**
	 * Locate the wp-config.php this install actually loads (core's own rule:
	 * ABSPATH first, then one level up when there's no wp-settings.php there).
	 */
	private static function wpconfig_path() {
		if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
			return ABSPATH . 'wp-config.php';
		}
		$up = dirname( ABSPATH ) . '/wp-config.php';
		if ( file_exists( $up ) && ! file_exists( dirname( ABSPATH ) . '/wp-settings.php' ) ) {
			return $up;
		}
		return '';
	}

	/**
	 * Whether editing wp-config is possible here, and the current state of each
	 * debug constant. A constant defined OUTSIDE wp-config (a mu-plugin, the
	 * host's prepend) is 'locked' — editing wp-config wouldn't change it.
	 */
	private static function config_state() {
		$path       = self::wpconfig_path();
		$writable   = $path && wp_is_writable( $path );
		$disallowed = ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) || ( is_multisite() && ! is_super_admin() );
		$contents   = ( $path && is_readable( $path ) ) ? (string) file_get_contents( $path ) : '';

		$constants = array();
		foreach ( self::debug_constants() as $name => $meta ) {
			$in_config = '' !== $contents && (bool) preg_match( self::const_pattern( $name ), $contents );
			$defined   = defined( $name );
			$constants[] = array(
				'name'      => $name,
				'label'     => $meta['label'],
				'desc'      => $meta['desc'],
				'value'     => $defined ? (bool) constant( $name ) : false,
				'in_config' => $in_config,
				'locked'    => $defined && ! $in_config,
			);
		}

		$log_path = self::debug_log_path();
		$log      = array(
			'path'       => str_replace( ABSPATH, '', $log_path ),
			'exists'     => file_exists( $log_path ),
			'size_human' => file_exists( $log_path ) ? size_format( (int) filesize( $log_path ), 1 ) : '',
		);

		return array(
			'editable'   => (bool) ( $writable && ! $disallowed ),
			'writable'   => (bool) $writable,
			'disallowed' => (bool) $disallowed,
			'constants'  => $constants,
			'log'        => $log,
		);
	}

	/**
	 * Set a whitelisted boolean debug constant in wp-config.php. Every write is
	 * gated (writability / DISALLOW_FILE_MODS / multisite super-admin), scoped
	 * to the whitelist, syntax-validated before it touches disk, and preceded
	 * by a .minn-bak backup.
	 */
	public static function set_config_constant( WP_REST_Request $request ) {
		$name  = (string) $request['constant'];
		$value = (bool) $request['value'];

		$consts = self::debug_constants();
		if ( ! isset( $consts[ $name ] ) ) {
			return new WP_Error( 'bad_constant', 'That constant is not editable.', array( 'status' => 400 ) );
		}
		if ( ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) || ( is_multisite() && ! is_super_admin() ) ) {
			return new WP_Error( 'forbidden', 'File modifications are disabled on this site.', array( 'status' => 403 ) );
		}
		$path = self::wpconfig_path();
		if ( ! $path || ! wp_is_writable( $path ) ) {
			return new WP_Error( 'not_writable', 'wp-config.php is not writable.', array( 'status' => 400 ) );
		}
		$contents = file_get_contents( $path );
		if ( false === $contents ) {
			return new WP_Error( 'read_failed', 'Could not read wp-config.php.', array( 'status' => 500 ) );
		}

		$line    = "define( '" . $name . "', " . ( $value ? 'true' : 'false' ) . " );";
		$pattern = self::const_pattern( $name );

		if ( preg_match( $pattern, $contents ) ) {
			$new = preg_replace( $pattern, $line, $contents, 1 );
		} elseif ( defined( $name ) ) {
			// Defined elsewhere — a wp-config edit would be a confusing no-op.
			return new WP_Error( 'defined_elsewhere', $name . ' is defined outside wp-config.php, so it can\'t be toggled here.', array( 'status' => 400 ) );
		} else {
			$marker = "/* That's all, stop editing! Happy publishing. */";
			if ( false !== strpos( $contents, $marker ) ) {
				$new = str_replace( $marker, $line . "\n\n" . $marker, $contents );
			} else {
				// Fall back to just before wp-settings.php loads.
				$new = preg_replace( "/(require_once\\s*\\(?\\s*ABSPATH\\s*\\.\\s*['\"]wp-settings\\.php['\"])/", $line . "\n\n\$1", $contents, 1 );
			}
			if ( null === $new || $new === $contents ) {
				return new WP_Error( 'place_failed', 'Could not find a safe place to add the constant.', array( 'status' => 500 ) );
			}
		}
		if ( null === $new ) {
			return new WP_Error( 'edit_failed', 'The edit could not be applied.', array( 'status' => 500 ) );
		}

		// Validate the transformed file parses BEFORE writing it. TOKEN_PARSE
		// makes token_get_all throw ParseError on a syntax error (PHP 7+).
		try {
			token_get_all( $new, TOKEN_PARSE );
		} catch ( \ParseError $e ) {
			return new WP_Error( 'parse_error', 'The change would break wp-config.php, so it was not saved.', array( 'status' => 500 ) );
		}

		@copy( $path, $path . '.minn-bak' );
		if ( false === file_put_contents( $path, $new ) ) {
			return new WP_Error( 'write_failed', 'Could not write wp-config.php.', array( 'status' => 500 ) );
		}

		return rest_ensure_response(
			array(
				'constant' => $name,
				// The value that will apply on the next request (this one already
				// bootstrapped with the old constant).
				'value'    => $value,
			)
		);
	}

	/** Turn an ordered assoc array into [{key,value}] rows for the client. */
	private static function kv_rows( array $data ) {
		$rows = array();
		foreach ( $data as $k => $v ) {
			$rows[] = array(
				'key'   => $k,
				'value' => (string) $v,
			);
		}
		return $rows;
	}
}
