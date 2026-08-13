<?php
/**
 * Bundled adapter: WPCode (Insert Headers and Footers).
 *
 * WPCode stores snippets as a private CPT (`wpcode`) with no public REST surface,
 * so this is the shim pattern (same idea as Gravity SMTP / Stream): a thin
 * minn-admin/v1/wpcode collection over WPCode_Snippet, plus a Snippets surface
 * that matches the Code Snippets UX (list, edit, toggle, create, delete).
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * WPCode's own per-code-type capability for a submitted type.
 *
 * WPCode Pro grades snippet authoring by what the code can DO —
 * wpcode_edit_text_snippets (text/blocks), wpcode_edit_html_snippets
 * (HTML/JS/CSS), wpcode_edit_php_snippets (PHP and everything else) — and its
 * own save listener resolves the required capability FROM THE SUBMITTED
 * code_type before allowing the write (class-wpcode-admin-page-snippet-manager).
 * Checking only the flat wpcode_edit_snippets lets a user provisioned for
 * markup submit code_type=php and author executable PHP.
 *
 * On Lite the graded caps map down to wpcode_edit_snippets, so this is a no-op.
 */
function minn_admin_wpcode_can_type( $code_type ) {
	$required = '';
	if ( class_exists( 'WPCode_Access' ) && method_exists( 'WPCode_Access', 'capability_for_code_type' ) ) {
		$required = (string) WPCode_Access::capability_for_code_type( $code_type );
	}
	return current_user_can( $required ?: 'wpcode_edit_snippets' );
}

/**
 * Does WPCode EXECUTE this code type?
 *
 * WPCode runs two types, not one: class-wpcode-snippet.php's
 * run_activation_checks() declares array( 'php', 'universal' ), and the
 * universal handler is documented as taking "both HTML and PHP at the same
 * time in the same way you can write both in a .php file". Matching the
 * literal 'php' let code_type=universal author executable PHP on a site that
 * had turned dashboard code editing off.
 *
 * Anything not on the known-inert list counts as executing, so a type WPCode
 * adds later is refused under DISALLOW_FILE_EDIT until it is classified here
 * rather than silently allowed. The Pro-only WPCode_Access mapper resolving a
 * type to the PHP tier is treated as executing too; it does not exist in the
 * free plugin, which is why it cannot be the only signal.
 *
 * @param string $code_type WPCode code type.
 * @return bool
 */
function minn_admin_wpcode_type_executes( $code_type ) {
	$inert = array( 'html', 'text', 'css', 'js', 'scss', 'blocks' );
	if ( in_array( (string) $code_type, $inert, true ) ) {
		return false;
	}
	return true;
}

/**
 * Does this type put the author's MARKUP into the page?
 *
 * Distinct from executing: a `text` or `html` snippet never runs PHP, but
 * WPCode emits its bytes verbatim at the snippet's location, so a <script>
 * in one runs for every visitor and every administrator. That makes these
 * types answer to unfiltered_html, the capability WordPress uses everywhere
 * else to decide who may store raw markup, rather than to DISALLOW_FILE_EDIT.
 *
 * @param string $code_type WPCode code type.
 * @return bool
 */
function minn_admin_wpcode_type_is_markup( $code_type ) {
	return in_array( (string) $code_type, array( 'html', 'text' ), true );
}

/**
 * Reproduce WPCode's own write-time sanitizing for a caller without
 * unfiltered_html.
 *
 * WPCode turns core's kses OFF for its own saves: includes/post-type.php
 * hooks wpcode_maybe_remove_core_content_filters() to wpcode_before_snippet_save,
 * and that calls remove_all_filters( 'content_save_pre' ) immediately before
 * wp_insert_post() runs. Nothing sanitizes post_content on that path, so the
 * plugin compensates inside its own save listener instead
 * (class-wpcode-admin-page-snippet-manager.php: a `text` snippet authored
 * without unfiltered_html is passed through wp_kses_post). An adapter that
 * writes through WPCode_Snippet gets the filter removal and none of the
 * compensation, so it has to carry the same rule itself.
 *
 * @param string $code_type Effective code type.
 * @param string $code      Raw code.
 * @return string
 */
function minn_admin_wpcode_clean_code( $code_type, $code ) {
	if ( 'text' === (string) $code_type && ! current_user_can( 'unfiltered_html' ) ) {
		return wp_kses_post( (string) $code );
	}
	return (string) $code;
}

/**
 * 403 unless the caller may author this code type (and, on an update, edit
 * this specific snippet — WPCode requires edit_post on the target too).
 */
function minn_admin_wpcode_guard_type( $code_type, $snippet_id = 0 ) {
	// An `html` snippet is raw markup emitted site-wide, and WPCode will not
	// let a user without unfiltered_html author one: its snippet editor drops
	// to read-only when html is among the available types. `text` is allowed
	// but filtered, which minn_admin_wpcode_clean_code() handles at the write.
	// Note that neither answers to code_edits_allowed() below: they are not
	// file editing, they are markup, and the capability for markup is
	// unfiltered_html. Only super admins hold it on multisite, and no one
	// holds it on a site defining DISALLOW_UNFILTERED_HTML.
	if ( 'html' === (string) $code_type && ! current_user_can( 'unfiltered_html' ) ) {
		return new WP_Error(
			'forbidden',
			'HTML snippets run as raw markup on every page, so they need the unfiltered_html capability.',
			array( 'status' => 403 )
		);
	}
	// A snippet WPCode EXECUTES is PHP authoring, so it answers to the
	// directive a site owner sets to forbid exactly that. Markup and text
	// snippets are unaffected.
	if ( minn_admin_wpcode_type_executes( $code_type ) && class_exists( 'Minn_Admin' ) && ! Minn_Admin::code_edits_allowed() ) {
		return new WP_Error(
			'forbidden',
			'This site disallows editing code from the dashboard, so PHP snippets cannot be created or changed here.',
			array( 'status' => 403 )
		);
	}
	if ( ! minn_admin_wpcode_can_type( $code_type ) ) {
		return new WP_Error(
			'forbidden',
			'You cannot create snippets of that type on this site.',
			array( 'status' => 403 )
		);
	}
	if ( $snippet_id && ! current_user_can( 'edit_post', (int) $snippet_id ) ) {
		return new WP_Error( 'forbidden', 'You cannot edit that snippet.', array( 'status' => 403 ) );
	}
	return true;
}

function minn_admin_wpcode_active() {
	return class_exists( 'WPCode_Snippet' ) || defined( 'WPCODE_VERSION' ) || defined( 'WPCODE_PLUGIN_VERSION' );
}

/**
 * Normalize a WPCode_Snippet into the shared Snippets list/detail shape.
 *
 * @param WPCode_Snippet $snippet Snippet object.
 * @return array
 */
function minn_admin_wpcode_item( $snippet ) {
	$post = $snippet->get_post_data();
	$modified = $post && $post->post_modified ? $post->post_modified : '';
	return array(
		'id'        => (int) $snippet->get_id(),
		'name'      => $snippet->get_title(),
		'desc'      => (string) $snippet->get_note(),
		'code'      => $snippet->get_code(),
		'tags'      => array_values( (array) $snippet->get_tags() ),
		// Column "scope" = type · location for a one-glance scan.
		'scope'     => trim( $snippet->get_code_type() . ' · ' . $snippet->get_location(), ' ·' ),
		'code_type' => $snippet->get_code_type(),
		'location'  => $snippet->get_location(),
		'active'    => (bool) $snippet->is_active(),
		'priority'  => (int) $snippet->get_priority(),
		'modified'  => $modified,
	);
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_wpcode_active() ) {
		return $surfaces;
	}

	$type_options = array(
		array( 'php', 'PHP' ),
		array( 'js', 'JavaScript' ),
		array( 'css', 'CSS' ),
		array( 'html', 'HTML' ),
		array( 'text', 'Text' ),
	);
	$location_options = array(
		array( 'everywhere', 'Everywhere' ),
		array( 'frontend_only', 'Front-end only' ),
		array( 'admin_only', 'Admin only' ),
		array( 'site_wide_header', 'Site-wide header' ),
		array( 'site_wide_body', 'Site-wide body' ),
		array( 'site_wide_footer', 'Site-wide footer' ),
		array( 'before_post', 'Before post' ),
		array( 'after_post', 'After post' ),
		array( 'before_content', 'Before content' ),
		array( 'after_content', 'After content' ),
		array( 'after_paragraph', 'After paragraph' ),
	);

	$edit_fields = array(
		array( 'key' => 'name', 'label' => 'Name', 'placeholder' => 'Disable comments' ),
		array( 'key' => 'desc', 'label' => 'Note', 'type' => 'textarea', 'rows' => 2, 'required' => false ),
		array(
			'key'         => 'code',
			'label'       => 'Code',
			'type'        => 'textarea',
			'mono'        => true,
			'rows'        => 14,
			'placeholder' => "add_filter( '…', '…' );",
		),
		array( 'key' => 'code_type', 'label' => 'Type', 'type' => 'select', 'options' => $type_options ),
		array( 'key' => 'location', 'label' => 'Location', 'type' => 'select', 'options' => $location_options ),
		array( 'key' => 'priority', 'label' => 'Priority', 'type' => 'number' ),
		array( 'key' => 'tags', 'label' => 'Tags', 'type' => 'tags', 'required' => false ),
	);

	$surfaces['wpcode'] = array(
		'label'      => 'Snippets',
		'family'     => 'snippets',
		'sub'        => 'WPCode',
		'icon'       => 'code',
		'cap'        => 'wpcode_edit_snippets',
		// Status card (v0.18.0): family parity with Code Snippets.
		'status'     => array( 'route' => 'minn-admin/v1/wpcode/status' ),
		'collection' => array(
			'route'     => 'minn-admin/v1/wpcode/snippets',
			'pageQuery' => 'per_page=25&page={page}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'filter'    => array(
				'label'   => 'Status',
				'options' => array(
					array( 'all', 'All' ),
					array( 'active', 'Active' ),
					array( 'inactive', 'Inactive' ),
				),
				'query'   => 'active={v}',
			),
			'create'    => array(
				'label'    => 'Add snippet',
				'route'    => 'minn-admin/v1/wpcode/snippets',
				'method'   => 'POST',
				'defaults' => array(
					'active'    => false,
					'code_type' => 'php',
					'location'  => 'everywhere',
					'priority'  => 10,
					'tags'      => array(),
					'code'      => '',
				),
				'fields'   => $edit_fields,
			),
			'columns'   => array(
				array( 'key' => 'name', 'label' => 'Snippet', 'format' => 'title', 'width' => 'minmax(0,1.8fr)' ),
				array( 'key' => 'scope', 'label' => 'Type · location', 'format' => 'mono', 'width' => 'minmax(0,1fr)' ),
				array( 'key' => 'active', 'label' => 'Status', 'format' => 'pill', 'width' => '100px' ),
				array( 'key' => 'priority', 'label' => 'Priority', 'format' => 'num', 'width' => '80px' ),
				array( 'key' => 'modified', 'label' => 'Modified', 'format' => 'ago' ),
			),
			'detail'    => array(
				'detailRoute' => 'minn-admin/v1/wpcode/snippets/{id}',
				'skip'        => array(
					'code', 'name', 'desc', 'scope', 'code_type', 'location',
					'priority', 'tags', 'active',
				),
				'edit'        => array(
					'route'    => 'minn-admin/v1/wpcode/snippets/{id}',
					'method'   => 'PUT',
					'preserve' => array( 'active' ),
					'fields'   => $edit_fields,
				),
			),
			'actions'   => array(
				array(
					'label'  => 'Activate',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/wpcode/snippets/{id}/active',
					'body'   => array( 'active' => true ),
					'when'   => array( 'key' => 'active', 'equals' => false ),
				),
				array(
					'label'  => 'Deactivate',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/wpcode/snippets/{id}/active',
					'body'   => array( 'active' => false ),
					'when'   => array( 'key' => 'active', 'equals' => true ),
				),
				array(
					'label' => 'Edit in WPCode ↗',
					'href'  => admin_url( 'admin.php?page=wpcode-snippet-manager&snippet_id={id}' ),
				),
				array(
					'label'   => 'Delete snippet',
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/wpcode/snippets/{id}',
					'confirm' => 'Delete this snippet permanently? Its code will be gone.',
					'danger'  => true,
				),
			),
			'bulk'      => array(
				array(
					'label'  => 'Activate',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/wpcode/snippets/{id}/active',
					'body'   => array( 'active' => true ),
					'when'   => array( 'key' => 'active', 'equals' => false ),
				),
				array(
					'label'  => 'Deactivate',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/wpcode/snippets/{id}/active',
					'body'   => array( 'active' => false ),
					'when'   => array( 'key' => 'active', 'equals' => true ),
				),
				array(
					'label'   => 'Delete',
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/wpcode/snippets/{id}',
					'confirm' => 'Delete the selected snippets permanently?',
					'danger'  => true,
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_wpcode_active() || ! class_exists( 'WPCode_Snippet' ) ) {
		return;
	}

	$can_edit = function () {
		return current_user_can( 'wpcode_edit_snippets' );
	};
	$can_act = function () {
		return current_user_can( 'wpcode_activate_snippets' );
	};

	register_rest_route(
		'minn-admin/v1',
		'/wpcode/snippets',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => $can_edit,
				'callback'            => function ( WP_REST_Request $request ) {
					$per_page = min( 100, max( 1, (int) ( $request['per_page'] ?? 25 ) ) );
					$page     = max( 1, (int) ( $request['page'] ?? 1 ) );
					// Active filter: WPCode stores active as post status
					// publish=active, draft/private=inactive (their model).
					$active = sanitize_key( (string) ( $request['active'] ?? 'all' ) );
					$status = array( 'publish', 'draft', 'private' );
					if ( 'active' === $active ) {
						$status = array( 'publish' );
					} elseif ( 'inactive' === $active ) {
						$status = array( 'draft', 'private' );
					}
					$q = new WP_Query(
						array(
							'post_type'      => 'wpcode',
							'post_status'    => $status,
							'posts_per_page' => $per_page,
							'paged'          => $page,
							'orderby'        => 'modified',
							'order'          => 'DESC',
						)
					);
					$items = array();
					foreach ( $q->posts as $post ) {
						$items[] = minn_admin_wpcode_item( new WPCode_Snippet( $post ) );
					}
					return rest_ensure_response(
						array(
							'items' => $items,
							'total' => (int) $q->found_posts,
						)
					);
				},
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => $can_edit,
				'callback'            => function ( WP_REST_Request $request ) {
					$guard = minn_admin_wpcode_guard_type( sanitize_key( (string) ( $request['code_type'] ?? 'php' ) ) );
					if ( is_wp_error( $guard ) ) {
						return $guard;
					}
					// load_from_array (via the array constructor) can set private
					// fields like priority/note that are not assignable from outside.
					$create_type = sanitize_key( (string) ( $request['code_type'] ?? 'php' ) );
					$snippet = new WPCode_Snippet(
						array(
							'title'       => sanitize_text_field( (string) $request['name'] ),
							'code'        => minn_admin_wpcode_clean_code( $create_type, (string) ( $request['code'] ?? '' ) ),
							'code_type'   => $create_type,
							'location'    => sanitize_key( (string) ( $request['location'] ?? 'everywhere' ) ),
							'priority'    => (int) ( $request['priority'] ?? 10 ),
							'tags'        => array_map( 'sanitize_text_field', (array) ( $request['tags'] ?? array() ) ),
							'note'        => sanitize_textarea_field( (string) ( $request['desc'] ?? '' ) ),
							'auto_insert' => 1,
							'active'      => ! empty( $request['active'] ),
						)
					);
					$id = $snippet->save();
					if ( ! $id ) {
						return new WP_Error( 'wpcode_save_failed', 'Could not create the snippet.', array( 'status' => 500 ) );
					}
					return rest_ensure_response( minn_admin_wpcode_item( new WPCode_Snippet( (int) $id ) ) );
				},
			),
		)
	);

	register_rest_route(
		'minn-admin/v1',
		'/wpcode/snippets/(?P<id>\d+)',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => $can_edit,
				'callback'            => function ( WP_REST_Request $request ) {
					$snippet = new WPCode_Snippet( (int) $request['id'] );
					if ( ! $snippet->get_id() ) {
						return new WP_Error( 'not_found', 'Snippet not found.', array( 'status' => 404 ) );
					}
					return rest_ensure_response( minn_admin_wpcode_item( $snippet ) );
				},
			),
			array(
				'methods'             => 'PUT',
				'permission_callback' => $can_edit,
				'callback'            => function ( WP_REST_Request $request ) {
					$snippet = new WPCode_Snippet( (int) $request['id'] );
					if ( ! $snippet->get_id() ) {
						return new WP_Error( 'not_found', 'Snippet not found.', array( 'status' => 404 ) );
					}
					// The STORED type gates the edit, and when the payload
					// carries a new code_type that type must be allowed too —
					// otherwise a markup-only user retypes an HTML snippet to
					// php and authors executable code.
					$guard = minn_admin_wpcode_guard_type( (string) $snippet->get_code_type(), (int) $request['id'] );
					if ( is_wp_error( $guard ) ) {
						return $guard;
					}
					// Resolve the submitted type ONCE, from the same accessor the
					// write below uses. $request['code_type'] resolves JSON, then
					// POST, then GET, then URL — so a guard that peeked only at
					// get_json_params() was skipped entirely by sending
					// ?code_type=php in the query string while the write still
					// picked it up. Guard and write must never read different
					// parameter sources.
					$new_type = $request['code_type'];
					if ( null !== $new_type ) {
						$new_type  = sanitize_key( (string) $new_type );
						$guard_new = minn_admin_wpcode_guard_type( $new_type, (int) $request['id'] );
						if ( is_wp_error( $guard_new ) ) {
							return $guard_new;
						}
					}
					// Hydrate getters so load_from_array merges onto real state.
					$snippet->get_title();
					$snippet->get_code();
					$snippet->get_code_type();
					$snippet->get_location();
					$snippet->get_priority();
					$snippet->get_tags();
					$snippet->get_note();
					$snippet->is_active();
					// Location is only written when auto_insert === 1 (WPCode
					// save() gate) — force it so location edits stick.
					$snippet->get_auto_insert();

					// The type in force AFTER this write decides how the code is
					// filtered, so resolve it before touching the code: a request
					// that sends only a new type still changes what the stored
					// bytes mean.
					$eff_type = null !== $new_type ? $new_type : (string) $snippet->get_code_type();

					$patch = array( 'auto_insert' => 1 );
					if ( null !== $request['name'] ) {
						$patch['title'] = sanitize_text_field( (string) $request['name'] );
					}
					if ( null !== $request['code'] ) {
						$patch['code'] = minn_admin_wpcode_clean_code( $eff_type, (string) $request['code'] );
					} elseif ( null !== $new_type && minn_admin_wpcode_type_is_markup( $eff_type ) ) {
						// Retyping is a write even when no code is sent. Creating a
						// css snippet (never filtered, since css is not markup) and
						// then flipping it to text would otherwise promote bytes
						// into a markup context that never passed the markup rule.
						$patch['code'] = minn_admin_wpcode_clean_code( $eff_type, (string) $snippet->get_code() );
					}
					if ( null !== $new_type ) {
						$patch['code_type'] = $new_type;
					}
					if ( null !== $request['location'] ) {
						$patch['location'] = sanitize_key( (string) $request['location'] );
					}
					if ( null !== $request['priority'] ) {
						$patch['priority'] = (int) $request['priority'];
					}
					if ( null !== $request['tags'] ) {
						$patch['tags'] = array_map( 'sanitize_text_field', (array) $request['tags'] );
					}
					if ( null !== $request['desc'] ) {
						$patch['note'] = sanitize_textarea_field( (string) $request['desc'] );
					}
					if ( null !== $request['active'] && current_user_can( 'wpcode_activate_snippets' ) ) {
						$patch['active'] = (bool) $request['active'];
					}
					$snippet->load_from_array( $patch );
					if ( ! $snippet->save() ) {
						return new WP_Error( 'wpcode_save_failed', 'Could not save the snippet.', array( 'status' => 500 ) );
					}
					return rest_ensure_response( minn_admin_wpcode_item( new WPCode_Snippet( (int) $request['id'] ) ) );
				},
			),
			array(
				'methods'             => 'DELETE',
				'permission_callback' => $can_edit,
				'callback'            => function ( WP_REST_Request $request ) {
					$id = (int) $request['id'];
					$post = get_post( $id );
					if ( ! $post || 'wpcode' !== $post->post_type ) {
						return new WP_Error( 'not_found', 'Snippet not found.', array( 'status' => 404 ) );
					}
					// Destroying a snippet needs the same bar as editing it: the
					// stored code type's tier AND edit_post on this snippet.
					// Without it a text/markup-tier user could permanently delete
					// production PHP snippets they were never allowed to author.
					$guard = minn_admin_wpcode_guard_type( (string) ( new WPCode_Snippet( $id ) )->get_code_type(), $id );
					if ( is_wp_error( $guard ) ) {
						return $guard;
					}
					if ( ! current_user_can( 'delete_post', $id ) ) {
						return new WP_Error( 'forbidden', 'You cannot delete that snippet.', array( 'status' => 403 ) );
					}
					$ok = wp_delete_post( $id, true );
					if ( ! $ok ) {
						return new WP_Error( 'wpcode_delete_failed', 'Could not delete the snippet.', array( 'status' => 500 ) );
					}
					return new WP_REST_Response( null, 204 );
				},
			),
		)
	);

	register_rest_route(
		'minn-admin/v1',
		'/wpcode/snippets/(?P<id>\d+)/active',
		array(
			'methods'             => 'POST',
			'permission_callback' => $can_act,
			'callback'            => function ( WP_REST_Request $request ) {
				$snippet = new WPCode_Snippet( (int) $request['id'] );
				if ( ! $snippet->get_id() ) {
					return new WP_Error( 'not_found', 'Snippet not found.', array( 'status' => 404 ) );
				}
				// Publishing a snippet runs its code, so the stored type's tier
				// gates this too — a text-tier user must not be able to activate
				// or deactivate someone else's PHP snippet.
				$guard = minn_admin_wpcode_guard_type( (string) $snippet->get_code_type(), (int) $request['id'] );
				if ( is_wp_error( $guard ) ) {
					return $guard;
				}
				if ( ! empty( $request['active'] ) ) {
					$snippet->activate();
				} else {
					$snippet->deactivate();
				}
				return rest_ensure_response( minn_admin_wpcode_item( new WPCode_Snippet( (int) $request['id'] ) ) );
			},
			'args'                => array(
				'active' => array( 'type' => 'boolean', 'required' => true ),
			),
		)
	);
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_wpcode_active() ) {
		return;
	}
	// Status card: WPCode snippets are a CPT — publish = active, everything
	// else inactive (their model, same rule the list filter uses). Code types
	// ride the _wpcode_code_type meta on active snippets.
	register_rest_route( 'minn-admin/v1', '/wpcode/status', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return current_user_can( 'wpcode_edit_snippets' );
		},
		'callback'            => function () {
			global $wpdb;
			$counts   = wp_count_posts( 'wpcode' );
			$active   = isset( $counts->publish ) ? (int) $counts->publish : 0;
			$inactive = 0;
			foreach ( array( 'draft', 'private', 'pending' ) as $st ) {
				$inactive += isset( $counts->$st ) ? (int) $counts->$st : 0;
			}
			$rows = array(
				array(
					'label' => 'Active snippets',
					'value' => (string) $active,
					'hint'  => $inactive ? $inactive . ' inactive' : 'nothing inactive',
				),
			);
			$types = $wpdb->get_results(
				"SELECT pm.meta_value AS t, COUNT(*) AS c FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wpcode_code_type'
				 WHERE p.post_type = 'wpcode' AND p.post_status = 'publish'
				 GROUP BY pm.meta_value ORDER BY c DESC LIMIT 3"
			);
			if ( $types ) {
				$rows[] = array(
					'label' => 'Running types',
					'value' => implode( ' · ', array_map( function ( $t ) {
						return $t->c . ' ' . $t->t;
					}, $types ) ),
				);
			}
			$last = $wpdb->get_row(
				"SELECT post_title, post_modified FROM {$wpdb->posts}
				 WHERE post_type = 'wpcode' AND post_status IN ( 'publish', 'draft', 'private' )
				 ORDER BY post_modified DESC LIMIT 1"
			);
			if ( $last ) {
				$rows[] = array(
					'label' => 'Last change',
					'value' => (string) $last->post_title,
					'hint'  => substr( (string) $last->post_modified, 0, 10 ),
				);
			}
			return rest_ensure_response( array( 'rows' => $rows ) );
		},
	) );
} );
