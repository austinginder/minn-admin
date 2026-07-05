<?php
/**
 * Bundled adapter: page builders (Elementor, Beaver Builder, Brizy, Divi, Etch).
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
 * Active builders as detector descriptors. Each entry:
 * { name, detect(WP_Post):bool, edit_url(WP_Post):string, owns_content:bool }
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

	if ( defined( 'ETCH_PLUGIN_FILE' ) ) {
		$builders['etch'] = array(
			'name'         => 'Etch',
			// Etch persists native wp:etch/* blocks — islands keep Minn's
			// editor fully usable around them.
			'owns_content' => false,
			'detect'       => function ( $post ) {
				return false !== strpos( (string) $post->post_content, '<!-- wp:etch/' );
			},
			// Front-end app; Etch strips the admin bar itself.
			'edit_url'     => function ( $post ) {
				return add_query_arg( array( 'etch' => 'magic' ), get_permalink( $post ) );
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
 * Active builders for the boot payload — just what the + New menu needs.
 *
 * @return array[] [ { id, name } ]
 */
function minn_admin_page_builders_boot() {
	$out = array();
	foreach ( minn_admin_page_builders() as $id => $b ) {
		$out[] = array(
			'id'   => $id,
			'name' => $b['name'],
		);
	}
	return $out;
}

/**
 * The `minn_builder` REST field: null, or
 * { id, name, edit_url, owns_content } for the builder that owns the post.
 * Plus POST /builders/new — create a draft already prepared for a builder
 * and hand back its editing surface, so "+ New → Page in Elementor" is one
 * request and a redirect.
 */
add_action(
	'rest_api_init',
	function () {
		if ( ! minn_admin_page_builders() ) {
			return; // No builder active — the field (and its per-row cost) vanishes.
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
					if ( ! isset( $builders[ $bid ] ) ) {
						return new WP_Error( 'unknown_builder', 'That builder is not active.', array( 'status' => 404 ) );
					}
					$type     = 'posts' === $request['type'] ? 'post' : 'page';
					$type_obj = get_post_type_object( $type );
					if ( ! current_user_can( $type_obj->cap->edit_posts ) ) {
						return new WP_Error( 'forbidden', 'You are not allowed to create this.', array( 'status' => 403 ) );
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
					$post = get_post( $item['id'] );
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
						return array(
							'id'           => $id,
							'name'         => $b['name'],
							'edit_url'     => (string) call_user_func( $b['edit_url'], $post ),
							'owns_content' => $owns,
						);
					}
					return null;
				},
				'schema'       => array(
					'type'        => array( 'object', 'null' ),
					'description' => 'Page builder that manages this post, if any.',
					'context'     => array( 'view', 'edit' ),
				),
			)
		);
	}
);
