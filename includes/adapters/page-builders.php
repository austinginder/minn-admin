<?php
/**
 * Bundled adapter: page builders (Elementor, Beaver Builder, Brizy, Divi,
 * Breakdance, Etch).
 *
 * Builder users should never have to visit a wp-admin SCREEN — and Minn's
 * editor should never let them corrupt builder-owned content. This adapter
 * registers a `minn_builder` REST field on builder-capable post types that
 * answers, per post: which builder owns it, where its editing surface lives,
 * and whether the builder OWNS the content canvas.
 *
 * Two classes of builder (verified empirically, docs/page-builders.md):
 *
 * - Block-native (Etch, Divi 5): canonical content is `wp:etch/*` /
 *   `wp:divi/*` block markup in post_content. Minn's islands already
 *   preserve it byte-identically (modulo core's own one-time REST-save
 *   normalization), so the editor stays usable — the field only adds the
 *   "Edit in X" affordance. owns_content = false.
 *
 * - Meta-storage (Elementor, Beaver Builder, Brizy) and shortcode-era
 *   Divi 4: canonical content lives OUTSIDE post_content (JSON meta,
 *   serialized PHP meta, or shortcode soup). post_content is a stale or
 *   compiled copy — a Minn edit would silently never render, or be
 *   overwritten by the builder's next save. owns_content = true and the
 *   client locks the canvas, keeping title/status/slug/tags/SEO editable.
 *
 * Third-party builders can register through the `minn_admin_page_builders`
 * filter with the same descriptor shape.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Builder detector descriptors. Each entry:
 * { name, detect(WP_Post):bool, edit_url(WP_Post):string, owns_content:bool,
 *   active?:bool, edit_blocked?(WP_Post):string }
 *
 * edit_blocked returns a reason when the builder itself refuses to open this
 * post (the edit link would dead-end), or '' when it can. The editor then
 * shows the reason instead of an "Edit in X" button.
 *
 * Detection deliberately checks the POST, not just the plugin — a site can
 * run a builder for landing pages while writing everything else in Minn.
 *
 * @return array[]
 */
function minn_admin_page_builders() {
	static $builders = null;
	if ( null !== $builders ) {
		return $builders;
	}
	$builders = array();

	if ( defined( 'ELEMENTOR_VERSION' ) ) {
		$builders['elementor'] = array(
			'name'         => 'Elementor',
			// Canonical content is the _elementor_data JSON blob.
			'owns_content' => true,
			'detect'       => function ( $post ) {
				return 'builder' === get_post_meta( $post->ID, '_elementor_edit_mode', true );
			},
			// A wp-admin URL, but it renders Elementor's full-screen app —
			// zero wp-admin chrome (verified).
			'edit_url'     => function ( $post ) {
				return admin_url( 'post.php?post=' . $post->ID . '&action=elementor' );
			},
			// Elementor refuses its editor on the posts page and (4.3+) the
			// WooCommerce Shop page, where WordPress shows a listing instead of
			// the page's content; action=elementor then falls back to the plain
			// edit screen. Their own gate is the source of truth.
			'edit_blocked' => function ( $post ) {
				if ( ! class_exists( '\Elementor\User' ) || ! method_exists( '\Elementor\User', 'is_current_user_can_edit' ) ) {
					return '';
				}
				if ( \Elementor\User::is_current_user_can_edit( $post->ID ) ) {
					return '';
				}
				if ( (int) get_option( 'page_for_posts' ) === (int) $post->ID ) {
					return __( 'Elementor does not edit this page because it is the site’s posts page, which shows the latest posts instead of its own content.', 'minn-admin' );
				}
				if ( function_exists( 'wc_get_page_id' ) && (int) wc_get_page_id( 'shop' ) === (int) $post->ID ) {
					return __( 'Elementor does not edit this page because it is the WooCommerce Shop page, which shows the product listing instead of its own content.', 'minn-admin' );
				}
				return __( 'Elementor does not allow editing this page with your account.', 'minn-admin' );
			},
			// Same seeding Elementor's own new-post flow performs.
			'prepare'      => function ( $post_id, $type ) {
				update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
				update_post_meta( $post_id, '_elementor_template_type', 'wp-' . ( 'page' === $type ? 'page' : 'post' ) );
			},
		);
	}

	if ( class_exists( 'FLBuilderModel' ) ) {
		$builders['beaver-builder'] = array(
			'name'         => 'Beaver Builder',
			// Canonical content is serialized node data in _fl_builder_data;
			// post_content only carries a flattened render.
			'owns_content' => true,
			'detect'       => function ( $post ) {
				return (bool) get_post_meta( $post->ID, '_fl_builder_enabled', true );
			},
			// Pure front-end editing surface.
			'edit_url'     => function ( $post ) {
				return add_query_arg( 'fl_builder', '', get_permalink( $post ) );
			},
			'prepare'      => function ( $post_id ) {
				update_post_meta( $post_id, '_fl_builder_enabled', true );
			},
		);
	}

	if ( class_exists( 'Brizy_Editor_Entity' ) ) {
		$builders['brizy'] = array(
			'name'         => 'Brizy',
			'owns_content' => true,
			'detect'       => function ( $post ) {
				try {
					return Brizy_Editor_Entity::isBrizyEnabled( $post->ID );
				} catch ( Exception $e ) {
					return false;
				}
			},
			// post.php?action=in-front-editor — bounces to the front-end editor.
			'edit_url'     => function ( $post ) {
				try {
					return Brizy_Editor_Entity::getEditUrl( $post->ID );
				} catch ( Exception $e ) {
					return '';
				}
			},
			'prepare'      => function ( $post_id ) {
				try {
					Brizy_Editor_Entity::setBrizyEnabled( $post_id, 1 );
				} catch ( Exception $e ) { /* builder will enable on first open */ }
			},
		);
	}

	if ( function_exists( 'et_setup_theme' ) || defined( 'ET_BUILDER_VERSION' ) || defined( 'ET_CORE_VERSION' ) ) {
		$builders['divi'] = array(
			'name'         => 'Divi',
			// Divi 5 stores wp:divi/* blocks in post_content (islands handle
			// them); Divi 4 legacy is [et_pb_*] shortcode soup that the
			// Visual Builder owns. owns_content is decided per post below.
			'owns_content' => null,
			'detect'       => function ( $post ) {
				return 'on' === get_post_meta( $post->ID, '_et_pb_use_builder', true );
			},
			'owns_post'    => function ( $post ) {
				// Block-native D5 content is island-safe; shortcode-era isn't.
				return false === strpos( (string) $post->post_content, '<!-- wp:divi/' );
			},
			// The Visual Builder — pure front-end URL.
			'edit_url'     => function ( $post ) {
				return add_query_arg( 'et_fb', '1', get_permalink( $post ) );
			},
			'prepare'      => function ( $post_id, $type ) {
				update_post_meta( $post_id, '_et_pb_use_builder', 'on' );
				update_post_meta( $post_id, '_et_pb_built_for_post_type', $type );
			},
		);
	}

	if ( defined( 'BRICKS_VERSION' ) ) {
		$builders['bricks'] = array(
			'name'         => 'Bricks',
			// Canonical content is the _bricks_page_content_2 element tree.
			'owns_content' => true,
			'detect'       => function ( $post ) {
				return 'bricks' === get_post_meta( $post->ID, '_bricks_editor_mode', true )
					|| (bool) get_post_meta( $post->ID, '_bricks_page_content_2', true );
			},
			// permalink?bricks=run — pure front-end (Helpers::get_builder_edit_link).
			'edit_url'     => function ( $post ) {
				return add_query_arg( defined( 'BRICKS_BUILDER_PARAM' ) ? BRICKS_BUILDER_PARAM : 'bricks', 'run', get_permalink( $post ) );
			},
			'prepare'      => function ( $post_id ) {
				update_post_meta( $post_id, '_bricks_editor_mode', 'bricks' );
			},
		);
	}

	$breakdance_active = defined( '__BREAKDANCE_VERSION' )
		&& defined( 'BREAKDANCE_MODE' )
		&& 'breakdance' === BREAKDANCE_MODE
		&& function_exists( '\Breakdance\Admin\get_builder_loader_url' );
	// Keep installed-but-inactive Breakdance pages fenced. Their canonical
	// tree remains builder-owned even while the plugin cannot render it.
	if ( $breakdance_active || file_exists( WP_PLUGIN_DIR . '/breakdance/plugin.php' ) ) {
		$builders['breakdance'] = array(
			'name'         => 'Breakdance',
			'active'       => $breakdance_active,
			// Canonical content is the _breakdance_data element tree.
			'owns_content' => true,
			'detect'       => function ( $post ) {
				return (bool) get_post_meta( $post->ID, '_breakdance_data', true );
			},
			// Breakdance's own full-screen builder loader at the site root. The
			// fallback is returned only as metadata while inactive; the client
			// points the user to Extensions until Breakdance can handle it.
			'edit_url'     => function ( $post ) use ( $breakdance_active ) {
				if ( $breakdance_active ) {
					return \Breakdance\Admin\get_builder_loader_url( (string) $post->ID );
				}
				return add_query_arg(
					array(
						'breakdance' => 'builder',
						'id'         => $post->ID,
					),
					home_url( '/' )
				);
			},
		);
	}

	if ( defined( 'WPB_VC_VERSION' ) ) {
		$builders['wpbakery'] = array(
			'name'         => 'WPBakery',
			// Content is [vc_row…] shortcode soup in post_content — same class
			// as legacy Divi 4: renders through the_content, but hand-editing
			// it in Minn would mangle what the builder owns.
			'owns_content' => true,
			'detect'       => function ( $post ) {
				return 'true' === get_post_meta( $post->ID, '_wpb_vc_js_status', true )
					|| false !== strpos( (string) $post->post_content, '[vc_row' );
			},
			// The front-end inline editor (Vc_Frontend_Editor::getInlineUrl) —
			// a wp-admin URL rendering a full-screen editing app.
			'edit_url'     => function ( $post ) {
				return admin_url( 'post.php?vc_action=vc_inline&post_id=' . $post->ID . '&post_type=' . get_post_type( $post ) );
			},
			'prepare'      => function ( $post_id ) {
				update_post_meta( $post_id, '_wpb_vc_js_status', 'true' );
			},
		);
	}

	// SiteOrigin Page Builder: the canonical layout is the panels_data
	// postmeta array; post_content is a rendered HTML mirror their save
	// writes for search and plugin fallbacks, so a Minn edit there would be
	// overwritten on their next save. (Their "SiteOrigin Layout" BLOCK is
	// block-native and needs nothing here: islands keep it verbatim.)
	$siteorigin_active = defined( 'SITEORIGIN_PANELS_VERSION' );
	if ( $siteorigin_active || file_exists( WP_PLUGIN_DIR . '/siteorigin-panels/siteorigin-panels.php' ) ) {
		$builders['siteorigin'] = array(
			'name'         => 'SiteOrigin Page Builder',
			'active'       => $siteorigin_active,
			'owns_content' => true,
			'detect'       => function ( $post ) {
				// Their own is_panel() test: a non-empty panels_data.
				return (bool) get_post_meta( $post->ID, 'panels_data', true );
			},
			// Their Live Editor: the edit screen with so_live_editor=1, the URL
			// their admin-bar "Live Editor" link builds (raw, not &amp;-escaped).
			'edit_url'     => function ( $post ) {
				return add_query_arg(
					array(
						'post'           => $post->ID,
						'action'         => 'edit',
						'so_live_editor' => 1,
					),
					admin_url( 'post.php' )
				);
			},
		);
	}

	// Oxygen. Two unrelated products share the plugin folder `oxygen/`:
	// Oxygen 4 (classic, CT_VERSION, main file functions.php) keeps its tree
	// in _ct_builder_json (older pages: _ct_builder_shortcodes, and before
	// their meta-prefix migration the unprefixed ct_builder_shortcodes);
	// Oxygen 6 is built on the Breakdance engine (BREAKDANCE_MODE 'oxygen',
	// main file plugin.php) and keeps its tree in _oxygen_data. Either way
	// post_content is not what renders.
	$oxygen4_active = defined( 'CT_VERSION' ) && function_exists( 'ct_get_post_builder_link' );
	if ( $oxygen4_active || file_exists( WP_PLUGIN_DIR . '/oxygen/functions.php' ) ) {
		$builders['oxygen'] = array(
			'name'         => 'Oxygen',
			'active'       => $oxygen4_active,
			'owns_content' => true,
			'detect'       => function ( $post ) {
				foreach ( array( '_ct_builder_json', '_ct_builder_shortcodes', 'ct_builder_shortcodes' ) as $key ) {
					if ( '' !== (string) get_post_meta( $post->ID, $key, true ) ) {
						return true;
					}
				}
				return false;
			},
			// Their own URL builder adds ct_inner for inner-content templates;
			// the fallback is their base form (permalink + ct_builder=true).
			'edit_url'     => function ( $post ) use ( $oxygen4_active ) {
				if ( $oxygen4_active && function_exists( 'oxy_get_builder_url' ) ) {
					return oxy_get_builder_url( $post->ID );
				}
				return add_query_arg( 'ct_builder', 'true', get_permalink( $post ) );
			},
		);
	} else {
		$oxygen6_active = defined( '__BREAKDANCE_VERSION' )
			&& defined( 'BREAKDANCE_MODE' )
			&& 'oxygen' === BREAKDANCE_MODE
			&& function_exists( '\Breakdance\Admin\get_builder_loader_url' );
		if ( $oxygen6_active || file_exists( WP_PLUGIN_DIR . '/oxygen/plugin.php' ) ) {
			$builders['oxygen'] = array(
				'name'         => 'Oxygen',
				'active'       => $oxygen6_active,
				'owns_content' => true,
				'detect'       => function ( $post ) {
					return (bool) get_post_meta( $post->ID, '_oxygen_data', true );
				},
				// The Breakdance engine's loader URL, which in Oxygen mode is
				// home_url( '?oxygen=builder&id=N' ).
				'edit_url'     => function ( $post ) use ( $oxygen6_active ) {
					if ( $oxygen6_active ) {
						return \Breakdance\Admin\get_builder_loader_url( (string) $post->ID );
					}
					return add_query_arg(
						array(
							'oxygen' => 'builder',
							'id'     => $post->ID,
						),
						home_url( '/' )
					);
				},
			);
		}
	}

	if ( defined( 'ETCH_PLUGIN_FILE' ) ) {
		$builders['etch'] = array(
			'name'         => 'Etch',
			// Etch persists native wp:etch/* blocks. Copy lives in
			// wp:etch/text `content` attrs (armed as in-place runs); images
			// in tag:img element attrs show in the inspector. Layout stays
			// in Etch.
			'owns_content' => false,
			'detect'       => function ( $post ) {
				return false !== strpos( (string) $post->post_content, '<!-- wp:etch/' );
			},
			// Front-end app at the SITE ROOT — Etch's AppRenderer only renders
			// when is_front_page(), so the post rides in as ?post_id (exactly
			// the URL Etch's own admin-bar button builds). A permalink-based
			// URL yields a silent blank template.
			'edit_url'     => function ( $post ) {
				return add_query_arg(
					array(
						'etch'    => 'magic',
						'post_id' => $post->ID,
					),
					home_url( '/' )
				);
			},
		);
	}

	/**
	 * Register additional page builders.
	 *
	 * @param array[] $builders Descriptor map keyed by builder id.
	 */
	$builders = apply_filters( 'minn_admin_page_builders', $builders );
	return $builders;
}

/**
 * The builder managing one post, resolved through the detector table.
 * Shared by the minn_builder REST field and the front-end bar's contextual
 * Edit; callers do their own capability gating.
 *
 * @param int|WP_Post $post Post (or ID) to resolve.
 * @return array|null { id, name, edit_url, owns_content, active }
 */
function minn_admin_builder_for_post( $post ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return null;
	}
	foreach ( minn_admin_page_builders() as $id => $b ) {
		if ( ! call_user_func( $b['detect'], $post ) ) {
			continue;
		}
		$owns = isset( $b['owns_post'] )
			? (bool) call_user_func( $b['owns_post'], $post )
			: (bool) $b['owns_content'];
		// edit_url comes from the minn_admin_page_builders filter, so a third
		// party supplies it. Sanitize once here and every consumer inherits the
		// scheme allowlist instead of re-deriving it at each sink.
		$active  = ! isset( $b['active'] ) || (bool) $b['active'];
		$blocked = ( $active && isset( $b['edit_blocked'] ) && is_callable( $b['edit_blocked'] ) )
			? (string) call_user_func( $b['edit_blocked'], $post )
			: '';
		$out     = array(
			'id'           => $id,
			'name'         => $b['name'],
			'edit_url'     => '' === $blocked ? esc_url_raw( (string) call_user_func( $b['edit_url'], $post ) ) : '',
			'owns_content' => $owns,
			'active'       => $active,
		);
		if ( '' !== $blocked ) {
			$out['edit_blocked'] = wp_strip_all_tags( $blocked );
		}
		return $out;
	}
	return null;
}

/**
 * Active builders for the boot payload — just what the + New menu needs.
 *
 * @return array[] [ { id, name } ]
 */
function minn_admin_page_builders_boot() {
	$out = array();
	foreach ( minn_admin_page_builders() as $id => $b ) {
		if ( isset( $b['active'] ) && ! $b['active'] ) {
			continue;
		}
		$out[] = array(
			'id'   => $id,
			'name' => $b['name'],
		);
	}
	return $out;
}

/**
 * The `minn_builder` REST field: null, or
 * { id, name, edit_url, owns_content, active } for the builder that owns the
 * post.
 * Plus POST /builders/new — create a draft already prepared for a builder
 * and hand back its editing surface, so "+ New → Page in Elementor" is one
 * request and a redirect.
 */
add_action(
	'rest_api_init',
	function () {
		// Always registered (even with no builder active) so the client can
		// re-poll after a plugin/theme toggle and see a builder appear — the
		// boot payload is a one-time snapshot.
		register_rest_route(
			'minn-admin/v1',
			'/builders',
			array(
				'methods'             => 'GET',
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'callback'            => function () {
					return rest_ensure_response( minn_admin_page_builders_boot() );
				},
			)
		);
		if ( ! minn_admin_page_builders() ) {
			return; // No builder to detect — the field and per-row cost vanish.
		}
		register_rest_route(
			'minn-admin/v1',
			'/builders/new',
			array(
				'methods'             => 'POST',
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'callback'            => function ( WP_REST_Request $request ) {
					$builders = minn_admin_page_builders();
					$bid      = sanitize_key( $request['builder'] );
					if ( ! isset( $builders[ $bid ] )
						|| ( isset( $builders[ $bid ]['active'] ) && ! $builders[ $bid ]['active'] ) ) {
						return new WP_Error( 'unknown_builder', __( 'That builder is not active.', 'minn-admin' ), array( 'status' => 404 ) );
					}
					$type     = 'posts' === $request['type'] ? 'post' : 'page';
					$type_obj = get_post_type_object( $type );
					if ( ! current_user_can( $type_obj->cap->edit_posts ) ) {
						return new WP_Error( 'forbidden', __( 'You are not allowed to create this.', 'minn-admin' ), array( 'status' => 403 ) );
					}
					$title = sanitize_text_field( (string) $request['title'] );
					if ( '' === $title ) {
						// wp_insert_post refuses an entirely empty post
						// ("Content, title, and excerpt are empty.").
						$title = __( 'Untitled' );
					}
					$post_id = wp_insert_post(
						array(
							'post_type'    => $type,
							'post_status'  => 'draft',
							'post_title'   => $title,
							'post_content' => '',
						),
						true
					);
					if ( is_wp_error( $post_id ) ) {
						return new WP_Error( 'create_failed', $post_id->get_error_message(), array( 'status' => 500 ) );
					}
					$b = $builders[ $bid ];
					if ( isset( $b['prepare'] ) ) {
						call_user_func( $b['prepare'], $post_id, $type );
					}
					return rest_ensure_response(
						array(
							'id'       => $post_id,
							'edit_url' => (string) call_user_func( $b['edit_url'], get_post( $post_id ) ),
						)
					);
				},
			)
		);
		$types = get_post_types( array( 'show_in_rest' => true ) );
		unset( $types['attachment'] );
		register_rest_field(
			array_values( $types ),
			'minn_builder',
			array(
				'get_callback' => function ( $item ) {
					// A _fields request naming minn_builder without id hands
					// this an id-less array (core prepares only requested
					// fields), and get_post( 0 ) would fall back to the
					// global post — refuse explicitly.
					if ( empty( $item['id'] ) ) {
						return null;
					}
					$post = get_post( (int) $item['id'] );
					if ( ! $post ) {
						return null;
					}
					// Only for someone who can edit the post. This answers which
					// builder plugin runs the site and hands back that post's
					// builder editing URL, which is a description of the admin
					// rather than of the published page, and every sibling field
					// here is bound to edit_post the same way.
					if ( ! current_user_can( 'edit_post', $post->ID ) ) {
						return null;
					}
					return minn_admin_builder_for_post( $post );
				},
				'schema'       => array(
					'type'        => array( 'object', 'null' ),
					'description' => __( 'Page builder that manages this post, if any.', 'minn-admin' ),
					// Editor context only, like every other field this plugin
					// adds. In view context it rode along on anonymous
					// wp/v2/posts responses, naming the builder plugin per post.
					'context'     => array( 'edit' ),
				),
			)
		);
	}
);

/**
 * Elementor's hamburger "Exit to WordPress" reads these document URLs
 * (this post, all posts, or the dashboard, per the person's exit
 * preference). When Minn is the default admin, send every path to
 * Minn's front door instead. The label stays "Exit to WordPress".
 * People who have not opted in keep WordPress's destination.
 */
function minn_admin_elementor_exit_url( $url ) {
	if ( ! current_user_can( 'edit_posts' ) || ! Minn_Admin::user_wants_default_admin() ) {
		return $url;
	}
	return Minn_Admin::app_url();
}
add_filter( 'elementor/document/urls/exit_to_dashboard', 'minn_admin_elementor_exit_url' );
add_filter( 'elementor/document/urls/all_post_type', 'minn_admin_elementor_exit_url' );
add_filter( 'elementor/document/urls/main_dashboard', 'minn_admin_elementor_exit_url' );

/**
 * Brizy's more menu "Go to Dashboard" is the classic editor for the
 * same post. When Minn is this person's default admin, send that item
 * (and the shared backToDashboard URL it reads) to Minn's front door
 * instead. WordPress's destination stays when they have not opted in.
 * `_top` replaces the whole tab so Minn does not boot inside Brizy's
 * editor wrapper.
 */
function minn_admin_brizy_editor_config( $config, $context = '' ) {
	if ( 'compile' === $context ) {
		return $config;
	}
	if ( ! is_array( $config ) || ! current_user_can( 'edit_posts' ) ) {
		return $config;
	}
	if ( ! Minn_Admin::user_wants_default_admin() ) {
		return $config;
	}
	$url      = Minn_Admin::app_url();
	$dash_url = '';
	if ( isset( $config['urls'] ) && is_array( $config['urls'] ) ) {
		$dash_url = isset( $config['urls']['backToDashboard'] ) ? (string) $config['urls']['backToDashboard'] : '';
		$config['urls']['backToDashboard'] = $url;
	}
	$options = isset( $config['ui']['leftSidebar']['more']['options'] )
		? $config['ui']['leftSidebar']['more']['options']
		: null;
	if ( ! is_array( $options ) ) {
		return $config;
	}
	foreach ( $options as $i => $opt ) {
		if ( ! is_array( $opt ) || ( isset( $opt['type'] ) && 'link' !== $opt['type'] ) ) {
			continue;
		}
		$link = isset( $opt['link'] ) ? (string) $opt['link'] : '';
		$icon = isset( $opt['icon'] ) ? (string) $opt['icon'] : '';
		$hit  = ( $dash_url && $link === $dash_url ) || ( 'nc-back' === $icon && $link );
		if ( ! $hit ) {
			continue;
		}
		$config['ui']['leftSidebar']['more']['options'][ $i ]['link']       = $url;
		$config['ui']['leftSidebar']['more']['options'][ $i ]['linkTarget'] = '_top';
		break;
	}
	return $config;
}
add_filter( 'brizy_editor_config', 'minn_admin_brizy_editor_config', 10, 2 );
