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

function minn_admin_ccj_get_options( $post_id ) {
	$raw = get_post_meta( $post_id, 'options', true );
	if ( is_array( $raw ) && isset( $raw['language'] ) ) {
		return array_merge( minn_admin_ccj_default_options( $raw['language'] ), $raw );
	}
	if ( is_string( $raw ) && $raw ) {
		$decoded = @unserialize( $raw ); // phpcs:ignore — their own storage
		if ( is_array( $decoded ) && isset( $decoded['language'] ) ) {
			return array_merge( minn_admin_ccj_default_options( $decoded['language'] ), $decoded );
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
	return $base;
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
		array( 'internal', 'Internal' ),
		array( 'external', 'External file' ),
	);

	$edit_fields = array(
		array( 'key' => 'name', 'label' => 'Name', 'placeholder' => 'Site tweaks' ),
		array(
			'key'         => 'code',
			'label'       => 'Code',
			'type'        => 'textarea',
			'mono'        => true,
			'rows'        => 14,
			'placeholder' => '/* your CSS */',
		),
		array( 'key' => 'language', 'label' => 'Language', 'type' => 'select', 'options' => $lang_options ),
		array( 'key' => 'type', 'label' => 'Where', 'type' => 'select', 'options' => $type_options ),
		array( 'key' => 'side', 'label' => 'Side', 'type' => 'select', 'options' => $side_options ),
		array( 'key' => 'linking', 'label' => 'Linking', 'type' => 'select', 'options' => $link_options ),
		array( 'key' => 'priority', 'label' => 'Priority', 'type' => 'number' ),
	);

	$surfaces['custom-css-js'] = array(
		'label'      => 'Snippets',
		'family'     => 'snippets',
		'sub'        => 'Simple Custom CSS and JS',
		'icon'       => 'code',
		'cap'        => 'read',
		'collection' => array(
			'route'     => 'minn-admin/v1/ccj/snippets',
			'pageQuery' => 'per_page=25&page={page}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'search'    => 'search={q}',
			'filter'    => array(
				'label'   => 'Status',
				'options' => array(
					array( 'all', 'All' ),
					array( '1', 'Active' ),
					array( '0', 'Inactive' ),
				),
				'query'   => 'active={v}',
			),
			'create'    => array(
				'label'    => 'Add code',
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
				array( 'key' => 'name', 'label' => 'Snippet', 'format' => 'title', 'width' => 'minmax(0,1.8fr)' ),
				array( 'key' => 'scope', 'label' => 'Type · where', 'format' => 'mono', 'width' => 'minmax(0,1.2fr)' ),
				array( 'key' => 'active', 'label' => 'Status', 'format' => 'pill', 'width' => '100px' ),
				array( 'key' => 'priority', 'label' => 'Priority', 'format' => 'num', 'width' => '80px' ),
				array( 'key' => 'modified', 'label' => 'Modified', 'format' => 'ago', 'utc' => true ),
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
					'label'  => 'Activate',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/ccj/snippets/{id}/active',
					'body'   => array( 'active' => true ),
					'when'   => array( 'key' => 'active', 'equals' => false ),
				),
				array(
					'label'  => 'Deactivate',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/ccj/snippets/{id}/active',
					'body'   => array( 'active' => false ),
					'when'   => array( 'key' => 'active', 'equals' => true ),
				),
				array(
					'label' => 'Edit in Simple Custom CSS and JS ↗',
					'href'  => admin_url( 'post.php?post={id}&action=edit' ),
				),
				array(
					'label'   => 'Delete',
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/ccj/snippets/{id}',
					'confirm' => 'Delete this custom code permanently?',
					'danger'  => true,
				),
			),
			'bulk'      => array(
				array(
					'label'  => 'Activate',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/ccj/snippets/{id}/active',
					'body'   => array( 'active' => true ),
					'when'   => array( 'key' => 'active', 'equals' => false ),
				),
				array(
					'label'  => 'Deactivate',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/ccj/snippets/{id}/active',
					'body'   => array( 'active' => false ),
					'when'   => array( 'key' => 'active', 'equals' => true ),
				),
				array(
					'label'   => 'Delete',
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/ccj/snippets/{id}',
					'confirm' => 'Delete the selected codes permanently?',
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
					return new WP_Error( 'missing_name', 'Name is required.', array( 'status' => 400 ) );
				}
				$code = isset( $body['code'] ) ? (string) $body['code'] : '';
				// unfiltered_html for raw CSS/JS.
				if ( ! current_user_can( 'unfiltered_html' ) ) {
					$code = wp_kses_post( $code );
				}
				$opts = minn_admin_ccj_normalize_options( $body );
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
					return new WP_Error( 'not_found', 'Code not found.', array( 'status' => 404 ) );
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
					return new WP_Error( 'not_found', 'Code not found.', array( 'status' => 404 ) );
				}
				$body = $request->get_json_params();
				if ( ! is_array( $body ) ) {
					$body = array();
				}
				$update = array( 'ID' => $id );
				if ( isset( $body['name'] ) ) {
					$update['post_title'] = sanitize_text_field( $body['name'] );
				}
				if ( array_key_exists( 'code', $body ) ) {
					$code = (string) $body['code'];
					if ( ! current_user_can( 'unfiltered_html' ) ) {
						$code = wp_kses_post( $code );
					}
					$update['post_content'] = $code;
				}
				if ( array_key_exists( 'active', $body ) ) {
					$update['post_status'] = $body['active'] ? 'publish' : 'draft';
					update_post_meta( $id, '_active', $body['active'] ? 'yes' : 'no' );
				}
				wp_update_post( $update );
				$opts = minn_admin_ccj_normalize_options( $body, minn_admin_ccj_get_options( $id ) );
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
					return new WP_Error( 'not_found', 'Code not found.', array( 'status' => 404 ) );
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
				return new WP_Error( 'not_found', 'Code not found.', array( 'status' => 404 ) );
			}
			$body   = $request->get_json_params();
			$active = is_array( $body ) ? ! empty( $body['active'] ) : true;
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
