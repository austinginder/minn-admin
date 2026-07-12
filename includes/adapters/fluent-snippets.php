<?php
/**
 * Bundled adapter: FluentSnippets (Easy Code Manager).
 *
 * FluentSnippets ships its own REST under `fluent-snippets/*`, but list items
 * key on file_name (not id), status is draft/published, and create/update take
 * a nested meta JSON blob. A thin minn-admin shim normalizes that into the
 * shared Snippets surface shape used by Code Snippets and WPCode.
 *
 * Cap note: Fluent gates its own REST on `install_plugins`; Minn also requires
 * `unfiltered_html` for write paths (matching Fluent's isBlockedRequest).
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_fluent_snippets_active() {
	return defined( 'FLUENT_SNIPPETS_PLUGIN_VERSION' )
		|| class_exists( 'FluentSnippets\App\Helpers\Helper' )
		|| defined( 'FLUENTSNIPPETS' );
}

/**
 * Map a Fluent snippet (list index row or full find result) to the shared shape.
 *
 * @param array $row List row or full snippet with meta/code.
 * @return array
 */
function minn_admin_fluent_item( $row ) {
	// Full find returns { meta, code, file }; list returns flat fields + file_name.
	if ( isset( $row['meta'] ) && is_array( $row['meta'] ) ) {
		$meta = $row['meta'];
		$file = isset( $row['file_name'] ) ? $row['file_name'] : ( isset( $row['file'] ) ? basename( $row['file'] ) : '' );
		$code = isset( $row['code'] ) ? (string) $row['code'] : '';
		// Strip the leading <?php Fluent prepends for PHP files.
		if ( ! empty( $meta['type'] ) && 'PHP' === $meta['type'] ) {
			$code = preg_replace( '/^<\?php\s*/', '', $code );
		}
		$status = isset( $meta['status'] ) ? $meta['status'] : 'draft';
		$tags   = isset( $meta['tags'] ) ? $meta['tags'] : '';
		return array(
			'id'       => $file,
			'name'     => isset( $meta['name'] ) ? (string) $meta['name'] : $file,
			'desc'     => isset( $meta['description'] ) ? (string) $meta['description'] : '',
			'code'     => $code,
			'tags'     => is_array( $tags ) ? $tags : array_filter( array_map( 'trim', explode( ',', (string) $tags ) ) ),
			'scope'    => trim( ( isset( $meta['type'] ) ? $meta['type'] : '' ) . ' · ' . ( isset( $meta['run_at'] ) ? $meta['run_at'] : '' ), ' ·' ),
			'type'     => isset( $meta['type'] ) ? (string) $meta['type'] : 'PHP',
			'run_at'   => isset( $meta['run_at'] ) ? (string) $meta['run_at'] : 'all',
			'active'   => ( 'published' === $status ),
			'priority' => isset( $meta['priority'] ) ? (int) $meta['priority'] : 10,
			'modified' => isset( $meta['updated_at'] ) ? (string) $meta['updated_at'] : '',
		);
	}

	$tags = isset( $row['tags'] ) ? $row['tags'] : '';
	return array(
		'id'       => isset( $row['file_name'] ) ? (string) $row['file_name'] : '',
		'name'     => isset( $row['name'] ) ? (string) $row['name'] : '',
		'desc'     => isset( $row['description'] ) ? (string) $row['description'] : '',
		'code'     => '',
		'tags'     => is_array( $tags ) ? $tags : array_filter( array_map( 'trim', explode( ',', (string) $tags ) ) ),
		'scope'    => trim( ( isset( $row['type'] ) ? $row['type'] : '' ) . ' · ' . ( isset( $row['run_at'] ) ? $row['run_at'] : '' ), ' ·' ),
		'type'     => isset( $row['type'] ) ? (string) $row['type'] : 'PHP',
		'run_at'   => isset( $row['run_at'] ) ? (string) $row['run_at'] : 'all',
		'active'   => ( isset( $row['status'] ) && 'published' === $row['status'] ),
		'priority' => isset( $row['priority'] ) ? (int) $row['priority'] : 10,
		'modified' => isset( $row['updated_at'] ) ? (string) $row['updated_at'] : '',
	);
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_fluent_snippets_active() ) {
		return $surfaces;
	}

	$type_options = array(
		array( 'PHP', 'Functions (PHP)' ),
		array( 'php_content', 'Content (PHP + HTML)' ),
		array( 'css', 'Styles (CSS)' ),
		array( 'js', 'Scripts (JS)' ),
	);
	// Common run_at values across types; Fluent validates against type.
	$run_options = array(
		array( 'all', 'Everywhere' ),
		array( 'backend', 'Admin only' ),
		array( 'frontend', 'Front-end only' ),
		array( 'wp_head', 'Site header' ),
		array( 'wp_footer', 'Site footer' ),
		array( 'wp_body_open', 'Body open' ),
		array( 'before_content', 'Before content' ),
		array( 'after_content', 'After content' ),
		array( 'shortcode', 'Shortcode' ),
		array( 'admin_head', 'Admin header' ),
		array( 'admin_footer', 'Admin footer' ),
		array( 'everywhere', 'Backend + frontend (CSS)' ),
	);

	$edit_fields = array(
		array( 'key' => 'name', 'label' => 'Name', 'placeholder' => 'Disable emojis' ),
		array( 'key' => 'desc', 'label' => 'Description', 'type' => 'textarea', 'rows' => 2, 'required' => false ),
		array(
			'key'         => 'code',
			'label'       => 'Code',
			'type'        => 'textarea',
			'mono'        => true,
			'rows'        => 14,
			'placeholder' => "add_filter( '…', '…' );",
		),
		array( 'key' => 'type', 'label' => 'Type', 'type' => 'select', 'options' => $type_options ),
		array( 'key' => 'run_at', 'label' => 'Run at', 'type' => 'select', 'options' => $run_options ),
		array( 'key' => 'priority', 'label' => 'Priority', 'type' => 'number' ),
		array( 'key' => 'tags', 'label' => 'Tags', 'type' => 'tags', 'required' => false ),
	);

	$surfaces['fluent-snippets'] = array(
		'label'      => 'Snippets',
		'family'     => 'snippets',
		'sub'        => 'FluentSnippets',
		'icon'       => 'code',
		// install_plugins is Fluent's own gate; unfiltered_html is needed to write code.
		'cap'        => 'install_plugins',
		'collection' => array(
			'route'     => 'minn-admin/v1/fluent-snippets',
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
				'route'    => 'minn-admin/v1/fluent-snippets',
				'method'   => 'POST',
				'defaults' => array(
					'active'   => false,
					'type'     => 'PHP',
					'run_at'   => 'all',
					'priority' => 10,
					'tags'     => array(),
					'code'     => '',
				),
				'fields'   => $edit_fields,
			),
			'columns'   => array(
				array( 'key' => 'name', 'label' => 'Snippet', 'format' => 'title', 'width' => 'minmax(0,1.8fr)' ),
				array( 'key' => 'scope', 'label' => 'Type · run at', 'format' => 'mono', 'width' => 'minmax(0,1fr)' ),
				array( 'key' => 'active', 'label' => 'Status', 'format' => 'pill', 'width' => '100px' ),
				array( 'key' => 'priority', 'label' => 'Priority', 'format' => 'num', 'width' => '80px' ),
				array( 'key' => 'modified', 'label' => 'Modified', 'format' => 'ago' ),
			),
			'detail'    => array(
				'detailRoute' => 'minn-admin/v1/fluent-snippets/{id}',
				'skip'        => array(
					'code', 'name', 'desc', 'scope', 'type', 'run_at',
					'priority', 'tags', 'active',
				),
				'edit'        => array(
					'route'    => 'minn-admin/v1/fluent-snippets/{id}',
					'method'   => 'PUT',
					'preserve' => array( 'active', 'type', 'run_at' ),
					'fields'   => $edit_fields,
				),
			),
			'actions'   => array(
				array(
					'label'  => 'Activate',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/fluent-snippets/{id}/active',
					'body'   => array( 'active' => true ),
					'when'   => array( 'key' => 'active', 'equals' => false ),
				),
				array(
					'label'  => 'Deactivate',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/fluent-snippets/{id}/active',
					'body'   => array( 'active' => false ),
					'when'   => array( 'key' => 'active', 'equals' => true ),
				),
				array(
					'label' => 'Edit in FluentSnippets ↗',
					'href'  => admin_url( 'admin.php?page=fluent-snippets' ),
				),
				array(
					'label'   => 'Delete snippet',
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/fluent-snippets/{id}',
					'confirm' => 'Delete this snippet permanently? Its code will be gone.',
					'danger'  => true,
				),
			),
			'bulk'      => array(
				array(
					'label'  => 'Activate',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/fluent-snippets/{id}/active',
					'body'   => array( 'active' => true ),
					'when'   => array( 'key' => 'active', 'equals' => false ),
				),
				array(
					'label'  => 'Deactivate',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/fluent-snippets/{id}/active',
					'body'   => array( 'active' => false ),
					'when'   => array( 'key' => 'active', 'equals' => true ),
				),
				array(
					'label'   => 'Delete',
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/fluent-snippets/{id}',
					'confirm' => 'Delete the selected snippets permanently?',
					'danger'  => true,
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_fluent_snippets_active() ) {
		return;
	}
	if ( ! class_exists( 'FluentSnippets\App\Helpers\Helper' ) || ! class_exists( 'FluentSnippets\App\Model\Snippet' ) ) {
		return;
	}

	$can_read = function () {
		return current_user_can( 'install_plugins' );
	};
	$can_write = function () {
		return current_user_can( 'install_plugins' ) && current_user_can( 'unfiltered_html' );
	};

	register_rest_route(
		'minn-admin/v1',
		'/fluent-snippets',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => $can_read,
				'callback'            => function ( WP_REST_Request $request ) {
					\FluentSnippets\App\Helpers\Helper::cacheSnippetIndex( '', true );
					$per_page = min( 100, max( 1, (int) ( $request['per_page'] ?? 25 ) ) );
					$page     = max( 1, (int) ( $request['page'] ?? 1 ) );
					$model    = new \FluentSnippets\App\Model\Snippet();
					// Call without pagination — their paginated total is counted
					// AFTER array_slice, so it always equals the page size.
					$all = $model->getIndexedSnippets();
					if ( ! is_array( $all ) ) {
						$all = array();
					}
					// Defensive: some code paths return a pack object.
					if ( isset( $all['data'] ) && is_array( $all['data'] ) ) {
						$all = $all['data'];
					}
					$active = sanitize_key( (string) ( $request['active'] ?? 'all' ) );
					if ( 'active' === $active || 'inactive' === $active ) {
						$want = ( 'active' === $active );
						$all  = array_values( array_filter( $all, function ( $row ) use ( $want ) {
							$item = minn_admin_fluent_item( $row );
							return ! empty( $item['active'] ) === $want;
						} ) );
					}
					$total = count( $all );
					$slice = array_slice( $all, ( $page - 1 ) * $per_page, $per_page );
					$items = array_map( 'minn_admin_fluent_item', $slice );
					return rest_ensure_response(
						array(
							'items' => $items,
							'total' => $total,
						)
					);
				},
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => $can_write,
				'callback'            => function ( WP_REST_Request $request ) {
					$tags = $request['tags'];
					if ( is_array( $tags ) ) {
						$tags = implode( ', ', array_map( 'sanitize_text_field', $tags ) );
					}
					$meta = array(
						'name'        => sanitize_text_field( (string) $request['name'] ),
						'status'      => ! empty( $request['active'] ) ? 'published' : 'draft',
						'type'        => sanitize_text_field( (string) ( $request['type'] ?? 'PHP' ) ),
						'run_at'      => sanitize_text_field( (string) ( $request['run_at'] ?? 'all' ) ),
						'description' => sanitize_textarea_field( (string) ( $request['desc'] ?? '' ) ),
						'tags'        => (string) $tags,
						'group'       => '',
						'priority'    => (int) ( $request['priority'] ?? 10 ),
						'shortcode'   => 'no',
						'load_as_file'=> 'no',
						'condition'   => array( 'status' => 'no' ),
					);
					$result = \FluentSnippets\App\Helpers\Helper::createSnippet(
						array(
							'meta' => $meta,
							'code' => (string) ( $request['code'] ?? '' ),
						)
					);
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					// createSnippet returns the file name string on success.
					$file  = is_string( $result ) ? $result : ( isset( $result['file_name'] ) ? $result['file_name'] : '' );
					$model = new \FluentSnippets\App\Model\Snippet();
					$full  = $model->findByFileName( $file );
					if ( is_wp_error( $full ) ) {
						return rest_ensure_response( array( 'id' => $file, 'name' => $meta['name'] ) );
					}
					$full['file_name'] = $file;
					return rest_ensure_response( minn_admin_fluent_item( $full ) );
				},
			),
		)
	);

	// file names look like "1-name.php" — allow dots and hyphens.
	register_rest_route(
		'minn-admin/v1',
		'/fluent-snippets/(?P<file>[^/]+)',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => $can_read,
				'callback'            => function ( WP_REST_Request $request ) {
					$file  = sanitize_file_name( $request['file'] );
					$model = new \FluentSnippets\App\Model\Snippet();
					$full  = $model->findByFileName( $file );
					if ( is_wp_error( $full ) ) {
						return $full;
					}
					$full['file_name'] = $file;
					return rest_ensure_response( minn_admin_fluent_item( $full ) );
				},
			),
			array(
				'methods'             => 'PUT',
				'permission_callback' => $can_write,
				'callback'            => function ( WP_REST_Request $request ) {
					$file  = sanitize_file_name( $request['file'] );
					$model = new \FluentSnippets\App\Model\Snippet();
					$full  = $model->findByFileName( $file );
					if ( is_wp_error( $full ) ) {
						return $full;
					}
					$meta = isset( $full['meta'] ) && is_array( $full['meta'] ) ? $full['meta'] : array();
					$code = isset( $full['code'] ) ? (string) $full['code'] : '';
					if ( isset( $meta['type'] ) && 'PHP' === $meta['type'] ) {
						$code = preg_replace( '/^<\?php\s*/', '', $code );
					}

					if ( null !== $request['name'] ) {
						$meta['name'] = sanitize_text_field( (string) $request['name'] );
					}
					if ( null !== $request['desc'] ) {
						$meta['description'] = sanitize_textarea_field( (string) $request['desc'] );
					}
					if ( null !== $request['code'] ) {
						$code = (string) $request['code'];
					}
					if ( null !== $request['type'] ) {
						$meta['type'] = sanitize_text_field( (string) $request['type'] );
					}
					if ( null !== $request['run_at'] ) {
						$meta['run_at'] = sanitize_text_field( (string) $request['run_at'] );
					}
					if ( null !== $request['priority'] ) {
						$meta['priority'] = (int) $request['priority'];
					}
					if ( null !== $request['tags'] ) {
						$tags = $request['tags'];
						$meta['tags'] = is_array( $tags )
							? implode( ', ', array_map( 'sanitize_text_field', $tags ) )
							: sanitize_text_field( (string) $tags );
					}
					if ( null !== $request['active'] ) {
						$meta['status'] = ! empty( $request['active'] ) ? 'published' : 'draft';
					}
					if ( empty( $meta['condition'] ) || ! is_array( $meta['condition'] ) ) {
						$meta['condition'] = array( 'status' => 'no' );
					}

					$result = \FluentSnippets\App\Helpers\Helper::updateSnippet(
						array(
							'meta'       => $meta,
							'code'       => $code,
							'file_name'  => $file,
							'reactivate' => false,
						)
					);
					if ( is_wp_error( $result ) ) {
						return $result;
					}
					$full = $model->findByFileName( $file );
					if ( is_wp_error( $full ) ) {
						return $full;
					}
					$full['file_name'] = $file;
					return rest_ensure_response( minn_admin_fluent_item( $full ) );
				},
			),
			array(
				'methods'             => 'DELETE',
				'permission_callback' => $can_write,
				'callback'            => function ( WP_REST_Request $request ) {
					$file  = sanitize_file_name( $request['file'] );
					$model = new \FluentSnippets\App\Model\Snippet();
					$full  = $model->findByFileName( $file );
					if ( is_wp_error( $full ) ) {
						return $full;
					}
					$model->deleteSnippet( $file );
					return new WP_REST_Response( null, 204 );
				},
			),
		)
	);

	register_rest_route(
		'minn-admin/v1',
		'/fluent-snippets/(?P<file>[^/]+)/active',
		array(
			'methods'             => 'POST',
			'permission_callback' => $can_write,
			'callback'            => function ( WP_REST_Request $request ) {
				$file  = sanitize_file_name( $request['file'] );
				$model = new \FluentSnippets\App\Model\Snippet();
				$full  = $model->findByFileName( $file );
				if ( is_wp_error( $full ) ) {
					return $full;
				}
				$meta = isset( $full['meta'] ) && is_array( $full['meta'] ) ? $full['meta'] : array();
				$code = isset( $full['code'] ) ? (string) $full['code'] : '';
				if ( isset( $meta['type'] ) && 'PHP' === $meta['type'] ) {
					$code = preg_replace( '/^<\?php\s*/', '', $code );
				}
				$meta['status'] = ! empty( $request['active'] ) ? 'published' : 'draft';
				if ( empty( $meta['condition'] ) || ! is_array( $meta['condition'] ) ) {
					$meta['condition'] = array( 'status' => 'no' );
				}
				$result = \FluentSnippets\App\Helpers\Helper::updateSnippet(
					array(
						'meta'       => $meta,
						'code'       => $code,
						'file_name'  => $file,
						'reactivate' => false,
					)
				);
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$full = $model->findByFileName( $file );
				$full['file_name'] = $file;
				return rest_ensure_response( minn_admin_fluent_item( $full ) );
			},
			'args'                => array(
				'active' => array( 'type' => 'boolean', 'required' => true ),
			),
		)
	);
} );
