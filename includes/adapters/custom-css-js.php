<?php
/**
 * Bundled adapter: Simple Custom CSS and JS (custom-css-js).
 *
 * CPT `custom-css-js`: title + post_content hold the name/code; a single
 * serialized `options` meta holds language/type/linking/side/priority;
 * `_active` is yes/no. No REST surface — shim over core posts + their
 * meta, then rebuild the frontend search tree (custom-css-js-tree) and
 * write the upload file the way their save_post handler does.
 *
 * Cap: manage_options or edit_custom_csss (their Web Designer role).
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_ccj_active() {
	return post_type_exists( 'custom-css-js' )
		|| class_exists( 'CustomCSSandJS' )
		|| defined( 'CCJ_VERSION' );
}

function minn_admin_ccj_can() {
	return current_user_can( 'manage_options' ) || current_user_can( 'edit_custom_csss' );
}

/**
 * Whether this caller may store code for the given snippet options.
 *
 * A snippet's bytes are written to CCJ_UPLOAD_DIR/<id>.<language> and, for
 * internal linking, wrapped in a <script> tag in the page. So the sink is a
 * JavaScript execution context, and wp_kses_post() is no protection there: it
 * sanitizes HTML, and a payload carrying no HTML tags at all passes through
 * byte for byte before the sink re-adds the <script> wrapper.
 *
 * Mirror the decision already made for HFCM: refuse the write rather than
 * pretend to sanitize it. Scoped by language, because the edit_custom_csss
 * designer role exists to edit CSS and CSS is not an execution context:
 *
 *   js, html  -> needs unfiltered_html (script)
 *   css       -> allowed, unless it renders in wp-admin or on the login screen,
 *                where injected CSS can overlay an administrator's session
 *
 * Who this affects: the designer role, every site administrator on multisite
 * (only super admins hold unfiltered_html there), and any install using
 * DISALLOW_UNFILTERED_HTML.
 *
 * @param array $opts Normalized options (language + side).
 * @return bool
 */
function minn_admin_ccj_can_write_code( $opts ) {
	$language = isset( $opts['language'] ) ? (string) $opts['language'] : 'css';
	$sides    = array_filter( array_map( 'trim', explode( ',', isset( $opts['side'] ) ? (string) $opts['side'] : '' ) ) );
	$scripty  = in_array( $language, array( 'js', 'html' ), true );
	$admin    = (bool) array_intersect( $sides, array( 'admin', 'login' ) );
	// "CSS is not an execution context" holds only while the bytes never
	// reach an HTML parser, and that is a property of the SINK, not the
	// language. With linking=internal (the default) or 'both',
	// minn_admin_ccj_rebuild_tree() concatenates the caller's bytes between
	// literal <style type="text/css"> and </style> and the plugin echoes that
	// file into the page, so a payload can close the element and run script.
	// Only linking=external is genuinely inert: the file is loaded through
	// wp_enqueue_style and cannot break out. The 'block' side routes into
	// block_css.css and is inlined the same way, so it is not exempt either.
	$linking = isset( $opts['linking'] ) ? (string) $opts['linking'] : 'internal';
	$inlined = 'external' !== $linking || in_array( 'block', $sides, true );
	if ( ! $scripty && ! $admin && ! $inlined ) {
		return true;
	}
	// Executable snippets are code, so a site that forbids dashboard code
	// editing forbids these too. Plain front end CSS returned above already.
	if ( $scripty && class_exists( 'Minn_Admin' ) && ! Minn_Admin::code_edits_allowed() ) {
		return false;
	}
	if ( defined( 'DISALLOW_UNFILTERED_HTML' ) && DISALLOW_UNFILTERED_HTML ) {
		return false;
	}
	return current_user_can( 'unfiltered_html' );
}

/**
 * Whether this caller may make an EXISTING snippet run.
 *
 * Publishing is the same privilege as authoring: the stored bytes are written
 * to CCJ_UPLOAD_DIR and, for a js snippet or an admin-side one, execute in a
 * context this caller may not write to. Checked against the snippet's stored
 * options rather than anything in the request.
 */
function minn_admin_ccj_can_activate( $post_id ) {
	return minn_admin_ccj_can_write_code( minn_admin_ccj_get_options( (int) $post_id ) );
}

/** WP_Error explaining why a snippet write was refused. */
function minn_admin_ccj_code_error( $opts ) {
	$language = isset( $opts['language'] ) ? (string) $opts['language'] : 'css';
	$message  = in_array( $language, array( 'js', 'html' ), true )
		? 'JavaScript and HTML snippets run as code in the page, so editing them needs the unfiltered_html capability on this site.'
		: 'Snippets that load in the admin or on the login screen need the unfiltered_html capability on this site.';
	return new WP_Error( 'forbidden', $message, array( 'status' => 403 ) );
}

/** Options meta defaults (mirrors CustomCSSandJS_Admin::$default_options). */
function minn_admin_ccj_default_options( $language = 'css' ) {
	$language = in_array( $language, array( 'css', 'js', 'html' ), true ) ? $language : 'css';
	return array(
		'type'     => 'header',
		'linking'  => 'html' === $language ? 'both' : 'internal',
		'side'     => 'frontend',
		'priority' => 5,
		'language' => $language,
	);
}

/**
 * Force `language` back into the allowlist.
 *
 * minn_admin_ccj_get_options() merges stored meta OVER the validated defaults,
 * so the raw value wins on the very next key — and rebuild_tree() uses it
 * directly as a FILE EXTENSION:
 * @file_put_contents( CCJ_UPLOAD_DIR . '/' . $post->ID . '.' . $language, … ).
 * A stored 'php' would write executable code into uploads; a '../' would climb
 * out of it. Minn's own writes validate, but the loop runs over every published
 * custom-css-js post on every write, so one polluted row from a migration or
 * another component is enough.
 *
 * @param array $opts Merged options.
 * @return array
 */
function minn_admin_ccj_guard_options( $opts ) {
	$allowed = array( 'css', 'js', 'html' );
	if ( ! isset( $opts['language'] ) || ! in_array( $opts['language'], $allowed, true ) ) {
		$opts['language'] = 'css';
	}
	return $opts;
}

function minn_admin_ccj_get_options( $post_id ) {
	$raw = get_post_meta( $post_id, 'options', true );
	if ( is_array( $raw ) && isset( $raw['language'] ) ) {
		return minn_admin_ccj_guard_options( array_merge( minn_admin_ccj_default_options( $raw['language'] ), $raw ) );
	}
	if ( is_string( $raw ) && $raw ) {
		$decoded = @unserialize( $raw, array( 'allowed_classes' => false ) ); // phpcs:ignore — their own storage; array-only, no objects
		if ( is_array( $decoded ) && isset( $decoded['language'] ) ) {
			return minn_admin_ccj_guard_options( array_merge( minn_admin_ccj_default_options( $decoded['language'] ), $decoded ) );
		}
	}
	return minn_admin_ccj_default_options();
}

function minn_admin_ccj_is_active( $post_id ) {
	return 'publish' === get_post_status( $post_id )
		&& 'no' !== get_post_meta( $post_id, '_active', true );
}

function minn_admin_ccj_item( $post ) {
	$post = get_post( $post );
	if ( ! $post || 'custom-css-js' !== $post->post_type ) {
		return null;
	}
	$opts     = minn_admin_ccj_get_options( $post->ID );
	$language = isset( $opts['language'] ) ? (string) $opts['language'] : 'css';
	$type     = isset( $opts['type'] ) ? (string) $opts['type'] : 'header';
	$side     = isset( $opts['side'] ) ? (string) $opts['side'] : 'frontend';
	return array(
		'id'       => (int) $post->ID,
		'name'     => $post->post_title ? $post->post_title : ( 'Untitled ' . strtoupper( $language ) ),
		'code'     => (string) $post->post_content,
		'language' => $language,
		'type'     => $type,
		'side'     => $side,
		'linking'  => isset( $opts['linking'] ) ? (string) $opts['linking'] : 'internal',
		'priority' => isset( $opts['priority'] ) ? (int) $opts['priority'] : 5,
		'scope'    => strtoupper( $language ) . ' · ' . $type . ' · ' . $side,
		'active'   => minn_admin_ccj_is_active( $post->ID ),
		'modified' => $post->post_modified_gmt ? str_replace( ' ', 'T', $post->post_modified_gmt ) . 'Z' : '',
	);
}

/**
 * Rebuild custom-css-js-tree + write the upload file for one post.
 * Mirrors CustomCSSandJS_Admin::build_search_tree / options_save essentials.
 */
function minn_admin_ccj_rebuild_tree() {
	$posts = get_posts( array(
		'post_type'      => 'custom-css-js',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'orderby'        => 'ID',
		'order'          => 'ASC',
	) );
	$tree = array();
	foreach ( $posts as $post ) {
		if ( ! minn_admin_ccj_is_active( $post->ID ) ) {
			continue;
		}
		$opts     = minn_admin_ccj_get_options( $post->ID );
		$language = $opts['language'];
		$filename = $post->ID . '.' . $language;
		$branch   = $language . '-' . $opts['type'] . '-' . $opts['linking'];
		foreach ( explode( ',', (string) $opts['side'] ) as $side ) {
			$side = trim( $side );
			if ( $side ) {
				$tree[ $side . '-' . $branch ][] = $filename;
			}
		}
		// Keep the upload file in sync for external/internal loaders.
		if ( defined( 'CCJ_UPLOAD_DIR' ) && wp_is_writable( CCJ_UPLOAD_DIR ) ) {
			$code   = $post->post_content;
			$before = '';
			$after  = '';
			if ( 'internal' === $opts['linking'] ) {
				$before = '<!-- start Simple Custom CSS and JS -->' . PHP_EOL;
				$after  = '<!-- end Simple Custom CSS and JS -->' . PHP_EOL;
				if ( 'css' === $language ) {
					$before .= '<style type="text/css">' . PHP_EOL;
					$after   = '</style>' . PHP_EOL . $after;
				}
				if ( 'js' === $language && ! preg_match( '/<script\b[^>]*>([\s\S]*?)<\/script>/im', $code ) ) {
					$before .= '<script type="text/javascript">' . PHP_EOL;
					$after   = '</script>' . PHP_EOL . $after;
				}
			}
			@file_put_contents( CCJ_UPLOAD_DIR . '/' . $filename, $before . $code . $after );
		}
	}
	update_option( 'custom-css-js-tree', $tree );
}

function minn_admin_ccj_normalize_options( $input, $existing = array() ) {
	$base = array_merge( minn_admin_ccj_default_options(), $existing, is_array( $input ) ? $input : array() );
	$base['language'] = in_array( $base['language'], array( 'css', 'js', 'html' ), true ) ? $base['language'] : 'css';
	$base['type']     = in_array( $base['type'], array( 'header', 'footer' ), true ) ? $base['type'] : 'header';
	$base['linking']  = in_array( $base['linking'], array( 'internal', 'external', 'both' ), true ) ? $base['linking'] : 'internal';
	// side may arrive as comma list or array.
	if ( is_array( $base['side'] ) ) {
		$base['side'] = implode( ',', $base['side'] );
	}
	$sides = array_values( array_filter( array_map( 'trim', explode( ',', (string) $base['side'] ) ) ) );
	$ok    = array( 'frontend', 'admin', 'login', 'block' );
	$sides = array_values( array_intersect( $sides, $ok ) );
	$base['side']     = $sides ? implode( ',', $sides ) : 'frontend';
	$base['priority'] = (int) $base['priority'];
	// Return ONLY the settings. Merging the raw body meant name, code and any
	// attacker-chosen key were persisted into the options meta too, so the
	// snippet's source was duplicated into a store nothing surfaces and CCJ's
	// own delete path does not know about, and other code regex-scans it.
	return array(
		'language' => $base['language'],
		'type'     => $base['type'],
		'linking'  => $base['linking'],
		'side'     => $base['side'],
		'priority' => $base['priority'],
	);
}

function minn_admin_ccj_rows( $args = array() ) {
	$q = array(
		'post_type'      => 'custom-css-js',
		'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
		'posts_per_page' => -1,
		'orderby'        => 'modified',
		'order'          => 'DESC',
	);
	if ( ! empty( $args['s'] ) ) {
		$q['s'] = $args['s'];
	}
	$items = array();
	foreach ( get_posts( $q ) as $post ) {
		$item = minn_admin_ccj_item( $post );
		if ( ! $item ) {
			continue;
		}
		if ( isset( $args['active'] ) ) {
			$want = ( '1' === (string) $args['active'] || 'true' === $args['active'] || true === $args['active'] );
			if ( (bool) $item['active'] !== $want ) {
				continue;
			}
		}
		$items[] = $item;
	}
	return $items;
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_ccj_active() || ! minn_admin_ccj_can() ) {
		return $surfaces;
	}

	$lang_options = array(
		array( 'css', 'CSS' ),
		array( 'js', 'JavaScript' ),
		array( 'html', 'HTML' ),
	);
	$type_options = array(
		array( 'header', 'Header' ),
		array( 'footer', 'Footer' ),
	);
	$side_options = array(
		array( 'frontend', 'Front-end' ),
		array( 'admin', 'Admin' ),
		array( 'login', 'Login' ),
	);
	$link_options = array(
		array( 'internal', __( 'Internal', 'minn-admin' ) ),
		array( 'external', __( 'External file', 'minn-admin' ) ),
	);

	$edit_fields = array(
		array( 'key' => 'name', 'label' => __( 'Name', 'minn-admin' ), 'placeholder' => __( 'Site tweaks', 'minn-admin' ) ),
		array(
			'key'         => 'code',
			'label'       => __( 'Code', 'minn-admin' ),
			'type'        => 'textarea',
			'mono'        => true,
			'rows'        => 14,
			'placeholder' => __( '/* your CSS */', 'minn-admin' ),
		),
		array( 'key' => 'language', 'label' => __( 'Language', 'minn-admin' ), 'type' => 'select', 'options' => $lang_options ),
		array( 'key' => 'type', 'label' => __( 'Where', 'minn-admin' ), 'type' => 'select', 'options' => $type_options ),
		array( 'key' => 'side', 'label' => __( 'Side', 'minn-admin' ), 'type' => 'select', 'options' => $side_options ),
		array( 'key' => 'linking', 'label' => __( 'Linking', 'minn-admin' ), 'type' => 'select', 'options' => $link_options ),
		array( 'key' => 'priority', 'label' => __( 'Priority', 'minn-admin' ), 'type' => 'number' ),
	);

	$surfaces['custom-css-js'] = array(
		'label'      => __( 'Snippets', 'minn-admin' ),
		'family'     => 'snippets',
		'sub'        => 'Simple Custom CSS and JS',
		'icon'       => 'code',
		'cap'        => 'read',
		// Status card (v0.18.0): family parity with Code Snippets.
		'status'     => array( 'route' => 'minn-admin/v1/custom-css-js/status' ),
		'collection' => array(
			'route'     => 'minn-admin/v1/ccj/snippets',
			'pageQuery' => 'per_page=25&page={page}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'search'    => 'search={q}',
			'filter'    => array(
				'label'   => __( 'Status', 'minn-admin' ),
				'options' => array(
					array( 'all', 'All' ),
					array( '1', 'Active' ),
					array( '0', 'Inactive' ),
				),
				'query'   => 'active={v}',
			),
			'create'    => array(
				'label'    => __( 'Add code', 'minn-admin' ),
				'route'    => 'minn-admin/v1/ccj/snippets',
				'method'   => 'POST',
				'defaults' => array(
					'active'   => false,
					'language' => 'css',
					'type'     => 'header',
					'side'     => 'frontend',
					'linking'  => 'internal',
					'priority' => 5,
					'code'     => '',
				),
				'fields'   => $edit_fields,
			),
			'columns'   => array(
				array( 'key' => 'name', 'label' => __( 'Snippet', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,1.8fr)' ),
				array( 'key' => 'scope', 'label' => __( 'Type · where', 'minn-admin' ), 'format' => 'mono', 'width' => 'minmax(0,1.2fr)' ),
				array( 'key' => 'active', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill', 'width' => '100px' ),
				array( 'key' => 'priority', 'label' => __( 'Priority', 'minn-admin' ), 'format' => 'num', 'width' => '80px' ),
				array( 'key' => 'modified', 'label' => __( 'Modified', 'minn-admin' ), 'format' => 'ago', 'utc' => true ),
			),
			'detail'    => array(
				'detailRoute' => 'minn-admin/v1/ccj/snippets/{id}',
				'skip'        => array( 'code', 'name', 'scope', 'language', 'type', 'side', 'linking', 'priority', 'active' ),
				'edit'        => array(
					'route'    => 'minn-admin/v1/ccj/snippets/{id}',
					'method'   => 'PUT',
					'preserve' => array( 'active' ),
					'fields'   => $edit_fields,
				),
			),
			'actions'   => array(
				array(
					'label'  => __( 'Activate', 'minn-admin' ),
					'method' => 'POST',
					'route'  => 'minn-admin/v1/ccj/snippets/{id}/active',
					'body'   => array( 'active' => true ),
					'when'   => array( 'key' => 'active', 'equals' => false ),
				),
				array(
					'label'  => __( 'Deactivate', 'minn-admin' ),
					'method' => 'POST',
					'route'  => 'minn-admin/v1/ccj/snippets/{id}/active',
					'body'   => array( 'active' => false ),
					'when'   => array( 'key' => 'active', 'equals' => true ),
				),
				array(
					'label' => __( 'Edit in Simple Custom CSS and JS ↗', 'minn-admin' ),
					'href'  => admin_url( 'post.php?post={id}&action=edit' ),
				),
				array(
					'label'   => __( 'Delete', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/ccj/snippets/{id}',
					'confirm' => __( 'Delete this custom code permanently?', 'minn-admin' ),
					'danger'  => true,
				),
			),
			'bulk'      => array(
				array(
					'label'  => __( 'Activate', 'minn-admin' ),
					'method' => 'POST',
					'route'  => 'minn-admin/v1/ccj/snippets/{id}/active',
					'body'   => array( 'active' => true ),
					'when'   => array( 'key' => 'active', 'equals' => false ),
				),
				array(
					'label'  => __( 'Deactivate', 'minn-admin' ),
					'method' => 'POST',
					'route'  => 'minn-admin/v1/ccj/snippets/{id}/active',
					'body'   => array( 'active' => false ),
					'when'   => array( 'key' => 'active', 'equals' => true ),
				),
				array(
					'label'   => __( 'Delete', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/ccj/snippets/{id}',
					'confirm' => __( 'Delete the selected codes permanently?', 'minn-admin' ),
					'danger'  => true,
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_ccj_active() ) {
		return;
	}
	$perm = function () {
		return minn_admin_ccj_can();
	};

	// Status card: counts over their CPT. Active = published without the
	// '_active' = no meta (their own convention; absent meta means active).
	// Languages are regex-peeked from the serialized `options` meta — never
	// unserialized.
	register_rest_route( 'minn-admin/v1', '/custom-css-js/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			global $wpdb;
			$rowsdb = $wpdb->get_results(
				"SELECT p.ID, pm.meta_value AS off, po.meta_value AS opts
				 FROM {$wpdb->posts} p
				 LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_active'
				 LEFT JOIN {$wpdb->postmeta} po ON po.post_id = p.ID AND po.meta_key = 'options'
				 WHERE p.post_type = 'custom-css-js' AND p.post_status = 'publish'"
			);
			$active   = 0;
			$inactive = 0;
			$langs    = array();
			foreach ( (array) $rowsdb as $r ) {
				if ( 'no' === (string) $r->off ) {
					$inactive++;
					continue;
				}
				$active++;
				if ( preg_match( '/"language";s:\d+:"([a-z]+)"/', (string) $r->opts, $m ) ) {
					$langs[ $m[1] ] = ( $langs[ $m[1] ] ?? 0 ) + 1;
				}
			}
			$rows = array(
				array(
					'label' => __( 'Active codes', 'minn-admin' ),
					'value' => (string) $active,
					'hint'  => $inactive
						? sprintf(
							/* translators: %d: number of inactive codes. */
							__( '%d inactive', 'minn-admin' ),
							$inactive
						)
						: __( 'nothing inactive', 'minn-admin' ),
				),
			);
			if ( $langs ) {
				arsort( $langs );
				$rows[] = array(
					'label' => __( 'Running languages', 'minn-admin' ),
					'value' => implode( ' · ', array_map( function ( $c, $l ) {
						return $c . ' ' . $l;
					}, $langs, array_keys( $langs ) ) ),
				);
			}
			return rest_ensure_response( array( 'rows' => $rows ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/ccj/snippets', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
				$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
				$args     = array();
				if ( $request->get_param( 'search' ) ) {
					$args['s'] = sanitize_text_field( $request->get_param( 'search' ) );
				}
				$active = $request->get_param( 'active' );
				if ( null !== $active && '' !== $active && 'all' !== $active ) {
					$args['active'] = $active;
				}
				$all = minn_admin_ccj_rows( $args );
				return rest_ensure_response( array(
					'items' => array_slice( $all, ( $page - 1 ) * $per_page, $per_page ),
					'total' => count( $all ),
				) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$body = $request->get_json_params();
				if ( ! is_array( $body ) ) {
					$body = array();
				}
				$name = isset( $body['name'] ) ? sanitize_text_field( $body['name'] ) : '';
				if ( ! $name ) {
					return new WP_Error( 'missing_name', __( 'Name is required.', 'minn-admin' ), array( 'status' => 400 ) );
				}
				$code = isset( $body['code'] ) ? (string) $body['code'] : '';
				$opts = minn_admin_ccj_normalize_options( $body );
				// Unconditional, matching the PUT path and HFCM: creating the
				// container for an execution context is the same privilege as
				// filling it, and an empty pre-activated js/admin-side shell is
				// exactly what a later write would need.
				if ( ! minn_admin_ccj_can_write_code( $opts ) ) {
					return minn_admin_ccj_code_error( $opts );
				}
				$id   = wp_insert_post( array(
					'post_type'    => 'custom-css-js',
					'post_title'   => $name,
					'post_content' => $code,
					'post_status'  => ! empty( $body['active'] ) ? 'publish' : 'draft',
				), true );
				if ( is_wp_error( $id ) ) {
					return $id;
				}
				update_post_meta( $id, 'options', $opts );
				update_post_meta( $id, '_active', ! empty( $body['active'] ) ? 'yes' : 'no' );
				minn_admin_ccj_rebuild_tree();
				$item = minn_admin_ccj_item( $id );
				return rest_ensure_response( $item ? $item : array( 'id' => $id ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/ccj/snippets/(?P<id>\d+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$item = minn_admin_ccj_item( (int) $request['id'] );
				if ( ! $item ) {
					return new WP_Error( 'not_found', __( 'Code not found.', 'minn-admin' ), array( 'status' => 404 ) );
				}
				return rest_ensure_response( $item );
			},
		),
		array(
			'methods'             => 'PUT',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$id   = (int) $request['id'];
				$post = get_post( $id );
				if ( ! $post || 'custom-css-js' !== $post->post_type ) {
					return new WP_Error( 'not_found', __( 'Code not found.', 'minn-admin' ), array( 'status' => 404 ) );
				}
				$body = $request->get_json_params();
				if ( ! is_array( $body ) ) {
					$body = array();
				}
				$stored = minn_admin_ccj_get_options( $id );
				$opts   = minn_admin_ccj_normalize_options( $body, $stored );
				// Check the RESULTING snippet, not just the incoming code. Retyping
				// an existing css/frontend snippet to js, or moving it onto the admin
				// or login screen, makes its stored bytes execute in a context this
				// caller may not write to, so an options-only edit is the same
				// escalation as a code write. Linking counts for the same reason:
				// external is the one shape minn_admin_ccj_can_write_code() lets a
				// caller without unfiltered_html store raw, because it loads through
				// wp_enqueue_style and cannot break out. Moving it to internal/both
				// hands those same stored bytes to rebuild_tree()'s inlined
				// <style>/<script> sink, so the promotion is a code write.
				$retargets = ( $opts['language'] !== ( isset( $stored['language'] ) ? (string) $stored['language'] : 'css' ) )
					|| ( $opts['side'] !== ( isset( $stored['side'] ) ? (string) $stored['side'] : 'frontend' ) )
					|| ( $opts['linking'] !== ( isset( $stored['linking'] ) ? (string) $stored['linking'] : 'internal' ) );
				if ( ( array_key_exists( 'code', $body ) || $retargets ) && ! minn_admin_ccj_can_write_code( $opts ) ) {
					return minn_admin_ccj_code_error( $opts );
				}
				$update = array( 'ID' => $id );
				if ( isset( $body['name'] ) ) {
					$update['post_title'] = sanitize_text_field( $body['name'] );
				}
				if ( array_key_exists( 'code', $body ) ) {
					$update['post_content'] = (string) $body['code'];
				}
				if ( array_key_exists( 'active', $body ) ) {
					if ( ! empty( $body['active'] ) && ! minn_admin_ccj_can_write_code( $opts ) ) {
						return minn_admin_ccj_code_error( $opts );
					}
					$update['post_status'] = $body['active'] ? 'publish' : 'draft';
					update_post_meta( $id, '_active', $body['active'] ? 'yes' : 'no' );
				}
				wp_update_post( $update );
				update_post_meta( $id, 'options', $opts );
				minn_admin_ccj_rebuild_tree();
				return rest_ensure_response( minn_admin_ccj_item( $id ) );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$id   = (int) $request['id'];
				$post = get_post( $id );
				if ( ! $post || 'custom-css-js' !== $post->post_type ) {
					return new WP_Error( 'not_found', __( 'Code not found.', 'minn-admin' ), array( 'status' => 404 ) );
				}
				// Deleting is a write to the same store, and it takes the file
				// in CCJ_UPLOAD_DIR with it.
				if ( ! minn_admin_ccj_can_activate( $id ) ) {
					return minn_admin_ccj_code_error( minn_admin_ccj_get_options( $id ) );
				}
				wp_delete_post( $id, true );
				minn_admin_ccj_rebuild_tree();
				return rest_ensure_response( array( 'deleted' => true ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/ccj/snippets/(?P<id>\d+)/active', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$id   = (int) $request['id'];
			$post = get_post( $id );
			if ( ! $post || 'custom-css-js' !== $post->post_type ) {
				return new WP_Error( 'not_found', __( 'Code not found.', 'minn-admin' ), array( 'status' => 404 ) );
			}
			$body   = $request->get_json_params();
			$active = is_array( $body ) ? ! empty( $body['active'] ) : true;
			// Turning a snippet ON runs it. Turning it OFF is always allowed.
			if ( $active && ! minn_admin_ccj_can_activate( $id ) ) {
				return minn_admin_ccj_code_error( minn_admin_ccj_get_options( $id ) );
			}
			wp_update_post( array(
				'ID'          => $id,
				'post_status' => $active ? 'publish' : 'draft',
			) );
			update_post_meta( $id, '_active', $active ? 'yes' : 'no' );
			minn_admin_ccj_rebuild_tree();
			return rest_ensure_response( minn_admin_ccj_item( $id ) );
		},
	) );
} );
