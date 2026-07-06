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
					$tr  = get_site_transient( 'update_plugins' );
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
		$rendered = array();
		foreach ( array_slice( $blocks, 0, 100 ) as $raw ) {
			$html = do_blocks( (string) $raw );
			// Embed blocks keep a bare URL in their saved HTML; the front end
			// converts it via WP_Embed::autoembed on the_content — run the same
			// pass here so island previews show the real embed.
			if ( isset( $GLOBALS['wp_embed'] ) && false !== strpos( $html, 'wp-block-embed__wrapper' ) ) {
				$html = $GLOBALS['wp_embed']->autoembed( $html );
			}
			$rendered[] = $html;
		}
		return rest_ensure_response( array( 'rendered' => $rendered ) );
	}

	/**
	 * The stylesheets that make blocks look like the front end — what the block
	 * editor loads into its canvas, collected for Minn's island previews: every
	 * registered block's style handles (resolved with their dependencies and
	 * wp_add_inline_style extras), the theme's declared editor styles, and the
	 * theme.json global stylesheet. The client fetches, scopes and injects them.
	 */
	public static function editor_styles() {
		$styles  = wp_styles();
		$handles = array( 'wp-block-library', 'wp-block-library-theme' );
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

		// The same theme styles the block editor honors (add_editor_style API).
		foreach ( get_editor_stylesheets() as $url ) {
			$urls[] = $url;
		}
		if ( function_exists( 'wp_get_global_stylesheet' ) ) {
			$inline .= wp_get_global_stylesheet();
		}

		return rest_ensure_response(
			array(
				'urls'   => array_values( array_unique( $urls ) ),
				'inline' => $inline,
			)
		);
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
				$label    = 1 === $bucket_days
					? date_i18n( 'M j, Y', time() - $offset * DAY_IN_SECONDS )
					: 'Week of ' . date_i18n( 'M j, Y', time() - ( $offset + $bucket_days - 1 ) * DAY_IN_SECONDS );
				$tchart[] = array(
					'label' => $label,
					'value' => $bucket['v'],
					'views' => $bucket['p'],
				);
			}

			$compact = function ( $n ) {
				return $n >= 10000 ? round( $n / 1000, 1 ) . 'k' : number_format_i18n( $n );
			};
			$prev  = isset( $traffic['prev_visitors'] ) ? (int) $traffic['prev_visitors'] : 0;
			$delta = $prev > 0 ? round( ( $visitors - $prev ) / $prev * 100, 1 ) : null;
			array_unshift(
				$stats,
				array(
					'label' => 'Visitors',
					'value' => $compact( $visitors ),
					'delta' => null !== $delta
						? ( $delta >= 0 ? '↑ ' : '↓ ' ) . abs( $delta ) . '% vs prior ' . $days . 'd'
						: $compact( $pageviews ) . ' pageviews',
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
			$activity[] = array(
				'text'  => sprintf( '%s %s “%s”', $author, $verb, self::plain_title( $p ) ),
				'time'  => $time,
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
					self::plain_title( $c->comment_post_ID )
				),
				'time'  => strtotime( $c->comment_date_gmt . ' UTC' ),
				'color' => $pending ? 'amber' : 'blue',
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
	 * Installed themes with active state, screenshots and update availability.
	 */
	public static function list_themes() {
		$updates    = get_site_transient( 'update_themes' );
		$active     = get_stylesheet();
		$items      = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$items[] = array(
				'stylesheet' => $stylesheet,
				'name'       => $theme->get( 'Name' ),
				'version'    => $theme->get( 'Version' ),
				'author'     => wp_strip_all_tags( $theme->get( 'Author' ) ),
				'screenshot' => $theme->get_screenshot() ?: '',
				'active'     => $stylesheet === $active,
				'parent'     => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
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

	public static function theme_update( WP_REST_Request $request ) {
		$stylesheet = sanitize_text_field( $request['stylesheet'] );
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		wp_update_themes();
		$updates = get_site_transient( 'update_themes' );
		if ( ! $updates || empty( $updates->response[ $stylesheet ] ) ) {
			return new WP_Error( 'no_update', 'No update available for that theme.', array( 'status' => 400 ) );
		}
		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		$result   = $upgrader->upgrade( $stylesheet );
		if ( ! $result || is_wp_error( $result ) ) {
			$errors = $skin->get_error_messages();
			return new WP_Error( 'update_failed', $errors ? implode( ' ', (array) $errors ) : 'Update failed.', array( 'status' => 500 ) );
		}
		$theme = wp_get_theme( $stylesheet );
		return rest_ensure_response( array( 'updated' => true, 'version' => $theme->get( 'Version' ) ) );
	}

	/**
	 * Search the wordpress.org theme directory (proxied server-side).
	 */
	public static function search_themes( WP_REST_Request $request ) {
		require_once ABSPATH . 'wp-admin/includes/theme.php';

		$res = themes_api(
			'query_themes',
			array(
				'search'   => sanitize_text_field( $request['q'] ),
				'per_page' => 12,
				'fields'   => array(
					'screenshot_url' => true,
					'rating'         => true,
					'active_installs'=> true,
				),
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
			$errors = $skin->get_error_messages();
			return new WP_Error( 'update_failed', $errors ? implode( ' ', (array) $errors ) : 'Update failed.', array( 'status' => 500 ) );
		}

		// Safety net: whatever the upgrade path did, an active plugin stays active.
		if ( $was_active && ! is_plugin_active( $file ) ) {
			activate_plugin( $file, '', $was_network, true );
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
		$checks    = array(
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

		return rest_ensure_response(
			array(
				'generated'  => current_time( 'c' ),
				'checks'     => $checks,
				'config'     => self::config_state(),
				'extensions' => self::extensions_manifest(),
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
