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
 * A snippet's updated_at as ISO 8601 UTC. FluentSnippets stamps it with
 * gmdate() on create and date() on update, which is UTC under WordPress,
 * and stores it bare, which the client would read as site time.
 *
 * @param string $stamp Stored updated_at.
 * @return string
 */
function minn_admin_fluent_utc_iso( $stamp ) {
	$ts = '' !== (string) $stamp ? strtotime( $stamp . ' UTC' ) : false;
	return $ts ? gmdate( 'c', $ts ) : '';
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
			'modified' => isset( $meta['updated_at'] ) ? minn_admin_fluent_utc_iso( $meta['updated_at'] ) : '',
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
		'modified' => isset( $row['updated_at'] ) ? minn_admin_fluent_utc_iso( $row['updated_at'] ) : '',
	);
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_fluent_snippets_active() ) {
		return $surfaces;
	}

	$type_options = array(
		array( 'PHP', __( 'Functions (PHP)', 'minn-admin' ) ),
		array( 'php_content', 'Content (PHP + HTML)' ),
		array( 'css', __( 'Styles (CSS)', 'minn-admin' ) ),
		array( 'js', __( 'Scripts (JS)', 'minn-admin' ) ),
	);
	// Common run_at values across types; Fluent validates against type.
	$run_options = array(
		array( 'all', 'Everywhere' ),
		array( 'backend', __( 'Admin only', 'minn-admin' ) ),
		array( 'frontend', __( 'Front-end only', 'minn-admin' ) ),
		array( 'wp_head', __( 'Site header', 'minn-admin' ) ),
		array( 'wp_footer', __( 'Site footer', 'minn-admin' ) ),
		array( 'wp_body_open', __( 'Body open', 'minn-admin' ) ),
		array( 'before_content', __( 'Before content', 'minn-admin' ) ),
		array( 'after_content', __( 'After content', 'minn-admin' ) ),
		array( 'shortcode', 'Shortcode' ),
		array( 'admin_head', __( 'Admin header', 'minn-admin' ) ),
		array( 'admin_footer', __( 'Admin footer', 'minn-admin' ) ),
		array( 'everywhere', 'Backend + frontend (CSS)' ),
	);

	$edit_fields = array(
		array( 'key' => 'name', 'label' => __( 'Name', 'minn-admin' ), 'placeholder' => __( 'Disable emojis', 'minn-admin' ) ),
		array( 'key' => 'desc', 'label' => __( 'Description', 'minn-admin' ), 'type' => 'textarea', 'rows' => 2, 'required' => false ),
		array(
			'key'         => 'code',
			'label'       => __( 'Code', 'minn-admin' ),
			'type'        => 'textarea',
			'mono'        => true,
			'rows'        => 14,
			'placeholder' => "add_filter( '…', '…' );",
		),
		// Clearable: a snippet file with no @type line runs nothing, and one with
		// no @run_at runs everywhere. A form seeding the first option would make
		// the first into PHP on a rename; an empty value changes nothing.
		array( 'key' => 'type', 'label' => __( 'Type', 'minn-admin' ), 'type' => 'select', 'options' => $type_options, 'clearable' => true ),
		array( 'key' => 'run_at', 'label' => __( 'Run at', 'minn-admin' ), 'type' => 'select', 'options' => $run_options, 'clearable' => true ),
		array( 'key' => 'priority', 'label' => __( 'Priority', 'minn-admin' ), 'type' => 'number' ),
		array( 'key' => 'tags', 'label' => __( 'Tags', 'minn-admin' ), 'type' => 'tags', 'required' => false ),
	);

	list( $actions, $bulk ) = minn_admin_snippets_actions( 'minn-admin/v1/fluent-snippets', array(
		'edit_in' => array(
			'label' => __( 'Edit in FluentSnippets ↗', 'minn-admin' ),
			'href'  => admin_url( 'admin.php?page=fluent-snippets' ),
		),
		'delete'  => __( 'Delete snippet', 'minn-admin' ),
		'confirm' => __( 'Delete this snippet permanently? Its code will be gone.', 'minn-admin' ),
	) );
	$surfaces['fluent-snippets'] = minn_admin_snippets_surface( array(
		'sub'        => 'FluentSnippets',
		'plugin'     => 'easy-code-manager',
		// install_plugins is Fluent's own gate; unfiltered_html is needed to write code.
		'cap'        => 'install_plugins',
		'status'     => 'fluent-snippets/status',
		'collection' => array(
			'route'     => 'minn-admin/v1/fluent-snippets',
			'pageQuery' => 'per_page=25&page={page}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'filter'    => minn_admin_snippets_filter(),
			'create'    => array(
				'label'    => __( 'Add snippet', 'minn-admin' ),
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
			'columns'   => minn_admin_snippets_columns( __( 'Type · run at', 'minn-admin' ) ),
			'detail'    => array(
				'detailRoute' => 'minn-admin/v1/fluent-snippets/{id}',
				'skip'        => array( 'code', 'name', 'desc', 'scope', 'type', 'run_at', 'priority', 'tags', 'active' ),
				'edit'        => array(
					'route'    => 'minn-admin/v1/fluent-snippets/{id}',
					'method'   => 'PUT',
					'preserve' => array( 'type', 'run_at' ),
					'fields'   => $edit_fields,
				),
			),
			'actions'   => $actions,
			'bulk'      => $bulk,
		),
	) );
	return $surfaces;
} );

/**
 * Whether their snippet store is loaded: the routes need its helper and model.
 *
 * @return bool
 */
function minn_admin_fluent_ready() {
	return minn_admin_fluent_snippets_active()
		&& class_exists( 'FluentSnippets\App\Helpers\Helper' )
		&& class_exists( 'FluentSnippets\App\Model\Snippet' );
}

function minn_admin_fluent_can_read() {
	return current_user_can( 'install_plugins' );
}

function minn_admin_fluent_can_write() {
	// These snippets are PHP executed from files. DISALLOW_FILE_MODS happens
	// to close this because core maps install_plugins to do_not_allow under
	// it, but DISALLOW_FILE_EDIT alone does not.
	if ( class_exists( 'Minn_Admin' ) && ! Minn_Admin::code_edits_allowed() ) {
		return false;
	}
	return current_user_can( 'install_plugins' ) && current_user_can( 'unfiltered_html' );
}

/**
 * Every snippet in their index, rebuilt first as their own list does. Their
 * paginated read counts its total AFTER the slice, so it is read whole.
 *
 * @return array[]
 */
function minn_admin_fluent_all() {
	\FluentSnippets\App\Helpers\Helper::cacheSnippetIndex( '', true );
	$model = new \FluentSnippets\App\Model\Snippet();
	$all   = $model->getIndexedSnippets();
	// Defensive: some code paths return a pack object.
	if ( isset( $all['data'] ) && is_array( $all['data'] ) ) {
		$all = $all['data'];
	}
	return is_array( $all ) ? $all : array();
}

/**
 * One snippet by file name, as their store reads it, with its code (the
 * leading "<?php" they prepend to PHP files taken off).
 *
 * @param string $file File name.
 * @return array|WP_Error array( full, meta, code )
 */
function minn_admin_fluent_load( $file ) {
	$model = new \FluentSnippets\App\Model\Snippet();
	$full  = $model->findByFileName( $file );
	// Their missing-file error carries no status, which would answer 500.
	if ( is_wp_error( $full ) ) {
		return new WP_Error( 'not_found', __( 'Snippet not found.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	$full['file_name'] = $file;
	$meta              = isset( $full['meta'] ) && is_array( $full['meta'] ) ? $full['meta'] : array();
	$code              = isset( $full['code'] ) ? (string) $full['code'] : '';
	if ( isset( $meta['type'] ) && 'PHP' === $meta['type'] ) {
		$code = preg_replace( '/^<\?php\s*/', '', $code );
	}
	return array( $full, $meta, $code );
}

/**
 * Save a snippet through their own updater, then read it back.
 *
 * @param string $file File name.
 * @param array  $meta Its meta.
 * @param string $code Its code.
 * @return array|WP_Error The item as it now reads.
 */
function minn_admin_fluent_save( $file, array $meta, $code ) {
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
	// Done only when the file reads back as written: whether it runs.
	$now = minn_admin_fluent_load( $file );
	if ( ! is_wp_error( $now ) && (string) ( $now[1]['status'] ?? 'draft' ) !== (string) $meta['status'] ) {
		return new WP_Error( 'not_applied', __( 'The plugin reported success, but the change is not there.', 'minn-admin' ), array( 'status' => 500 ) );
	}
	return is_wp_error( $now ) ? $now : minn_admin_fluent_item( $now[0] );
}

/** The file name in the URL, as their store names files ("1-name.php"). */
function minn_admin_fluent_file( WP_REST_Request $request ) {
	return sanitize_file_name( (string) Minn_Admin::path_param( $request, 'file' ) );
}

// Status card: counts from their own indexed snippet list (a file-based
// store). Declared before the item route, which would also match "status".
minn_admin_endpoint( 'fluent-snippets/status', array(
	'active' => 'minn_admin_fluent_ready',
	'gate'   => 'minn_admin_fluent_can_read',
	'run'    => function () {
		$items  = array_map( 'minn_admin_fluent_item', minn_admin_fluent_all() );
		$active = count( array_filter( $items, function ( $i ) {
			return ! empty( $i['active'] );
		} ) );
		return array(
			'rows' => array(
				minn_admin_snippets_count_row( $active, count( $items ) - $active ),
				array(
					'label' => __( 'Storage', 'minn-admin' ),
					'value' => __( 'Standalone files', 'minn-admin' ),
					'hint'  => __( 'FluentSnippets runs snippets from files, not the database', 'minn-admin' ),
				),
			),
		);
	},
) );

minn_admin_endpoint( 'fluent-snippets', array(
	'active' => 'minn_admin_fluent_ready',
	'gate'   => 'minn_admin_fluent_can_read',
	'run'    => function ( WP_REST_Request $request ) {
		$per_page = min( 100, max( 1, (int) ( $request['per_page'] ?? 25 ) ) );
		$page     = min( 100000, max( 1, (int) ( $request['page'] ?? 1 ) ) );
		$all      = minn_admin_fluent_all();
		$active   = sanitize_key( (string) ( $request['active'] ?? 'all' ) );
		if ( 'active' === $active || 'inactive' === $active ) {
			$want = ( 'active' === $active );
			$all  = array_values( array_filter( $all, function ( $row ) use ( $want ) {
				$item = minn_admin_fluent_item( $row );
				return ! empty( $item['active'] ) === $want;
			} ) );
		}
		return array(
			'items' => array_map( 'minn_admin_fluent_item', array_slice( $all, ( $page - 1 ) * $per_page, $per_page ) ),
			'total' => count( $all ),
		);
	},
) );

minn_admin_endpoint( 'fluent-snippets', array(
	'methods' => 'POST',
	'active'  => 'minn_admin_fluent_ready',
	'gate'    => 'minn_admin_fluent_can_write',
	'run'     => function ( WP_REST_Request $request ) {
		$tags = $request['tags'];
		if ( is_array( $tags ) ) {
			$tags = implode( ', ', array_map( 'sanitize_text_field', $tags ) );
		}
		$meta   = array(
			'name'         => sanitize_text_field( (string) $request['name'] ),
			'status'       => ! empty( $request['active'] ) ? 'published' : 'draft',
			'type'         => sanitize_text_field( (string) ( $request['type'] ?? 'PHP' ) ),
			'run_at'       => sanitize_text_field( (string) ( $request['run_at'] ?? 'all' ) ),
			'description'  => sanitize_textarea_field( (string) ( $request['desc'] ?? '' ) ),
			'tags'         => (string) $tags,
			'group'        => '',
			'priority'     => (int) ( $request['priority'] ?? 10 ),
			'shortcode'    => 'no',
			'load_as_file' => 'no',
			'condition'    => array( 'status' => 'no' ),
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
		// createSnippet answers the file name on success.
		$file = is_string( $result ) ? $result : ( isset( $result['file_name'] ) ? $result['file_name'] : '' );
		$now  = minn_admin_fluent_load( $file );
		return is_wp_error( $now ) ? array( 'id' => $file, 'name' => $meta['name'] ) : minn_admin_fluent_item( $now[0] );
	},
) );

// File names look like "1-name.php": dots and hyphens.
minn_admin_endpoint( 'fluent-snippets/(?P<file>[^/]+)', array(
	'active' => 'minn_admin_fluent_ready',
	'gate'   => 'minn_admin_fluent_can_read',
	'run'    => function ( WP_REST_Request $request ) {
		$now = minn_admin_fluent_load( minn_admin_fluent_file( $request ) );
		return is_wp_error( $now ) ? $now : minn_admin_fluent_item( $now[0] );
	},
) );

minn_admin_endpoint( 'fluent-snippets/(?P<file>[^/]+)', array(
	'methods' => 'PUT',
	'active'  => 'minn_admin_fluent_ready',
	'gate'    => 'minn_admin_fluent_can_write',
	'run'     => function ( WP_REST_Request $request ) {
		$file = minn_admin_fluent_file( $request );
		$now  = minn_admin_fluent_load( $file );
		if ( is_wp_error( $now ) ) {
			return $now;
		}
		list( , $meta, $code ) = $now;
		// A field the dialog sends back as stored is left as stored: cleaning
		// an untouched value rewrites it ("50%DEAL" loses "%DE").
		if ( null !== $request['name'] && (string) $request['name'] !== (string) ( $meta['name'] ?? '' ) ) {
			$meta['name'] = sanitize_text_field( (string) $request['name'] );
		}
		if ( null !== $request['desc'] && (string) $request['desc'] !== (string) ( $meta['description'] ?? '' ) ) {
			$meta['description'] = sanitize_textarea_field( (string) $request['desc'] );
		}
		if ( null !== $request['code'] ) {
			$code = (string) $request['code'];
		}
		if ( null !== $request['type'] && '' !== (string) $request['type'] ) {
			$meta['type'] = sanitize_text_field( (string) $request['type'] );
		}
		if ( null !== $request['run_at'] && '' !== (string) $request['run_at'] ) {
			$meta['run_at'] = sanitize_text_field( (string) $request['run_at'] );
		}
		if ( null !== $request['priority'] ) {
			$meta['priority'] = (int) $request['priority'];
		}
		if ( null !== $request['tags'] && ( is_array( $request['tags'] ) ? implode( ', ', array_map( 'strval', $request['tags'] ) ) : (string) $request['tags'] ) !== (string) ( $meta['tags'] ?? '' ) ) {
			$tags         = $request['tags'];
			$meta['tags'] = is_array( $tags )
				? implode( ', ', array_map( 'sanitize_text_field', $tags ) )
				: sanitize_text_field( (string) $tags );
		}
		if ( null !== $request['active'] ) {
			$meta['status'] = ! empty( $request['active'] ) ? 'published' : 'draft';
		}
		// A snippet file with no @type runs nothing, one with no @run_at runs
		// or not depending on its type, and FluentSnippets saves neither
		// empty: the person picks them.
		if ( '' === (string) ( $meta['type'] ?? '' ) ) {
			return new WP_Error( 'minn_fluent_no_type', __( 'This snippet has no type, so FluentSnippets does not run it. Pick a type to save it.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		if ( '' === (string) ( $meta['run_at'] ?? '' ) ) {
			return new WP_Error( 'minn_fluent_no_run_at', __( 'This snippet has no Run at setting. Pick one to save it.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		return minn_admin_fluent_save( $file, $meta, $code );
	},
) );

minn_admin_endpoint( 'fluent-snippets/(?P<file>[^/]+)', array(
	'methods' => 'DELETE',
	'active'  => 'minn_admin_fluent_ready',
	'gate'    => 'minn_admin_fluent_can_write',
	'run'     => function ( WP_REST_Request $request ) {
		$file = minn_admin_fluent_file( $request );
		$now  = minn_admin_fluent_load( $file );
		if ( is_wp_error( $now ) ) {
			return $now;
		}
		$model = new \FluentSnippets\App\Model\Snippet();
		$model->deleteSnippet( $file );
		// Done only once their store no longer finds it.
		if ( ! is_wp_error( minn_admin_fluent_load( $file ) ) ) {
			return new WP_Error( 'not_applied', __( 'The plugin reported success, but the change is not there.', 'minn-admin' ), array( 'status' => 500 ) );
		}
		// A body, not a 204: the app reads every answer as JSON.
		return array( 'ok' => true );
	},
) );

minn_admin_endpoint( 'fluent-snippets/(?P<file>[^/]+)/active', array(
	'methods' => 'POST',
	'active'  => 'minn_admin_fluent_ready',
	'gate'    => 'minn_admin_fluent_can_write',
	'args'    => array(
		'active' => array( 'type' => 'boolean', 'required' => true ),
	),
	'run'     => function ( WP_REST_Request $request ) {
		$file = minn_admin_fluent_file( $request );
		$now  = minn_admin_fluent_load( $file );
		if ( is_wp_error( $now ) ) {
			return $now;
		}
		list( , $meta, $code ) = $now;
		$meta['status']        = ! empty( $request['active'] ) ? 'published' : 'draft';
		return minn_admin_fluent_save( $file, $meta, $code );
	},
) );
