<?php
/**
 * WP Migrate (Delicious Brains / WP Engine) — push and pull from Minn.
 *
 * WP Migrate's own screen is a React app that drives the migration from the
 * browser over two channels, and this adapter hands Minn what it needs to
 * drive the same ones rather than reimplementing any of the work:
 *
 *   1. their REST namespace `mdb-api/v1` (verify-connection,
 *      initiate-migration, finalize-migration, cancel-migration), which
 *      Minn's client can call directly because a wp_rest nonce authenticates
 *      any REST route and their permission callback is a capability check;
 *   2. `wp_ajax_wpmdb_migrate_table`, the chunked loop the browser polls
 *      once per table until the response says the table is finished. That
 *      handler authenticates with a nonce for the `migrate-table` action and
 *      binds to no screen, so Minn mints the same nonce here.
 *
 * Nothing about the migration itself is re-implemented: every row that moves
 * is moved by WP Migrate's own code, on both ends.
 *
 * The capability is theirs, including their filter, so a site that has
 * narrowed who may migrate keeps that answer in Minn.
 */

defined( 'ABSPATH' ) || exit;

/**
 * WP Migrate Pro is loaded and its migration machinery is available.
 */
function minn_admin_wp_migrate_active() {
	return defined( 'WPMDB_PRO' ) && WPMDB_PRO && class_exists( '\DeliciousBrains\WPMDB\WPMDBDI' );
}

/**
 * Their own capability answer, filter included.
 */
function minn_admin_wp_migrate_cap() {
	$cap = is_multisite() ? 'manage_network_options' : 'export';
	return (string) apply_filters( 'wpmdb_ajax_cap', $cap );
}

function minn_admin_wp_migrate_can() {
	return minn_admin_wp_migrate_active() && current_user_can( minn_admin_wp_migrate_cap() );
}

/**
 * A migration only runs while the license is good enough for their own
 * connection gate, which passes on an expired subscription by their design
 * (migrations keep working; updates and support stop).
 */
function minn_admin_wp_migrate_licensed() {
	if ( ! minn_admin_wp_migrate_active() ) {
		return false;
	}
	try {
		$license = \DeliciousBrains\WPMDB\WPMDBDI::getInstance()->get( \DeliciousBrains\WPMDB\Pro\License::class );
		return (bool) $license->is_valid_licence();
	} catch ( \Throwable $e ) {
		return false;
	}
}

/**
 * Installed version, read from the plugin header (WP Migrate defines no
 * version constant of its own).
 */
function minn_admin_wp_migrate_version() {
	if ( ! defined( 'WPMDBPRO_FILE' ) ) {
		return '';
	}
	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$data = get_plugin_data( WPMDBPRO_FILE, false, false );
	return isset( $data['Version'] ) ? (string) $data['Version'] : '';
}

/**
 * This site's own details, built by WP Migrate's own code so a migration
 * started from Minn describes the local end exactly as their screen does.
 * Their local-site-details route answers add-on availability only, and
 * their React app reads the rest from its page bootstrap, so Minn reads it
 * from the same source: Util::site_details() and the prefix-scoped table
 * list, which is the set their "all tables with prefix" option means.
 */
function minn_admin_wp_migrate_local() {
	try {
		$di      = \DeliciousBrains\WPMDB\WPMDBDI::getInstance();
		$util    = $di->get( \DeliciousBrains\WPMDB\Common\Util\Util::class );
		$table   = $di->get( \DeliciousBrains\WPMDB\Common\Sql\Table::class );
		$details = $util->site_details();
		return array(
			'site_url' => isset( $details['site_url'] ) ? $details['site_url'] : untrailingslashit( site_url() ),
			'home_url' => isset( $details['home_url'] ) ? $details['home_url'] : untrailingslashit( home_url() ),
			'prefix'   => isset( $details['prefix'] ) ? $details['prefix'] : $GLOBALS['wpdb']->base_prefix,
			// The find-and-replace path pair is the site root on disk, which
			// is what their remote reports for its own end.
			'path'     => \DeliciousBrains\WPMDB\Common\Util\Util::get_absolute_root_file_path(),
			'tables'   => array_values( (array) $table->get_tables( 'prefix' ) ),
			'details'  => $details,
		);
	} catch ( \Throwable $e ) {
		return null;
	}
}

/**
 * Boot payload for the Migrate view. The migrate-table nonce is the same one
 * their own page embeds, so it is handed over only to a user who holds their
 * capability, and never on a site where the plugin is not running.
 */
function minn_admin_wp_migrate_boot() {
	if ( ! minn_admin_wp_migrate_can() ) {
		return null;
	}
	$settings = get_site_option( 'wpmdb_settings' );
	$settings = is_array( $settings ) ? $settings : array();
	return array(
		'restBase'  => 'mdb-api/v1',
		'ajax'      => admin_url( 'admin-ajax.php' ),
		// Util::create_nonce runs wp_create_nonce with their nonce filters
		// removed, which is what their handler verifies against.
		'nonce'     => \DeliciousBrains\WPMDB\Common\Util\Util::create_nonce( 'migrate-table' ),
		'licensed'  => minn_admin_wp_migrate_licensed(),
		// They define no version constant; the header is the reliable source.
		'version'   => minn_admin_wp_migrate_version(),
		'adminUrl'  => admin_url( 'tools.php?page=wp-migrate-db-pro' ),
		// What this site will accept FROM a remote, which is the answer the
		// other end needs and the one people forget to turn on.
		'allowPush' => ! empty( $settings['allow_push'] ),
		'allowPull' => ! empty( $settings['allow_pull'] ),
		'prefix'    => $GLOBALS['wpdb']->base_prefix,
		'siteUrl'   => untrailingslashit( home_url() ),
		// This end of the migration, described the way their own code
		// describes it.
		'local'     => minn_admin_wp_migrate_local(),
	);
}

/**
 * The connection info this site hands to the other end, and the two
 * settings that decide whether the other end may use it.
 *
 * The secret key is fetched on demand rather than carried in the boot
 * payload, the same way the one-time login link and the Disembark command
 * are handled: a site secret should not ride along on every page load of
 * the app just so a button can exist.
 */
add_action( 'rest_api_init', function () {
	$perm = function () {
		return minn_admin_wp_migrate_can();
	};

	register_rest_route( 'minn-admin/v1', '/wp-migrate/connection', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			$settings = get_site_option( 'wpmdb_settings' );
			$settings = is_array( $settings ) ? $settings : array();
			$key      = isset( $settings['key'] ) ? (string) $settings['key'] : '';
			if ( '' === $key ) {
				return new WP_Error(
					'minn_wpm_no_key',
					__( 'WP Migrate has not created a secret key for this site yet. Open its settings once to generate one.', 'minn-admin' ),
					array( 'status' => 409 )
				);
			}
			return rest_ensure_response( array(
				'url'   => untrailingslashit( network_home_url() ),
				'key'   => $key,
				// Their own field is the address and key on separate lines,
				// so hand back the exact block that can be pasted as is.
				'block' => untrailingslashit( network_home_url() ) . "\n" . $key,
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wp-migrate/accept', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$settings = get_site_option( 'wpmdb_settings' );
			$settings = is_array( $settings ) ? $settings : array();
			foreach ( array( 'push', 'pull' ) as $which ) {
				$val = $request->get_param( $which );
				if ( null !== $val ) {
					// Their own storage is a plain boolean on these two keys.
					$settings[ 'allow_' . $which ] = (bool) rest_sanitize_boolean( $val );
				}
			}
			update_site_option( 'wpmdb_settings', $settings );
			$fresh = get_site_option( 'wpmdb_settings' );
			$fresh = is_array( $fresh ) ? $fresh : array();
			return rest_ensure_response( array(
				'allowPush' => ! empty( $fresh['allow_push'] ),
				'allowPull' => ! empty( $fresh['allow_pull'] ),
			) );
		},
	) );
} );

/**
 * WP Migrate's backups, as a provider in the Backups family.
 *
 * This is the half of WP Migrate that is genuinely backup shaped: the .sql
 * and .sql.gz files it writes when you back the database up, export it, or
 * ask a migration to back the other end up first. The migration itself is
 * a flow and lives on its own page; these are an inventory, so they sit
 * beside UpdraftPlus and Duplicator.
 *
 * Reads go through their own Filesystem::get_backups(), which is what
 * their Backups tab lists, and deletes replay their own delete-backup
 * route so a file is removed exactly the way their screen removes it.
 * Downloads are a link to their admin handler rather than the file URL:
 * the backup directory ships an .htaccess and an index.php precisely so a
 * database dump is not fetchable over the web, and their handler streams
 * it behind the same capability that draws their screen.
 */
function minn_admin_wpm_backup_dir() {
	try {
		$fs = \DeliciousBrains\WPMDB\WPMDBDI::getInstance()->get( \DeliciousBrains\WPMDB\Common\Filesystem\Filesystem::class );
		return (string) $fs->get_upload_info( 'path' );
	} catch ( \Throwable $e ) {
		return '';
	}
}

/**
 * Their own listing, normalised for a Minn collection.
 *
 * Their entry carries a display-formatted date rather than a timestamp, so
 * the file's own mtime is read for the sortable value; the row still shows
 * their formatting for the name.
 */
function minn_admin_wpm_backups() {
	try {
		$fs   = \DeliciousBrains\WPMDB\WPMDBDI::getInstance()->get( \DeliciousBrains\WPMDB\Common\Filesystem\Filesystem::class );
		$list = $fs->get_backups();
	} catch ( \Throwable $e ) {
		return array();
	}
	if ( ! is_array( $list ) ) {
		return array();
	}
	$out = array();
	foreach ( $list as $row ) {
		$raw  = isset( $row['raw_name'] ) ? (string) $row['raw_name'] : '';
		$path = isset( $row['path'] ) ? (string) $row['path'] : '';
		if ( '' === $raw ) {
			continue;
		}
		$gz = ( '.gz' === substr( $raw, -3 ) );
		// Their filename encodes which kind it was: a backup taken on its
		// own, or the safety copy a migration made before overwriting.
		$kind = ( false !== strpos( $raw, '-migrate-' ) )
			? __( 'Before a migration', 'minn-admin' )
			: __( 'Backup', 'minn-admin' );
		$size  = ( $path && file_exists( $path ) ) ? (int) filesize( $path ) : 0;
		$mtime = ( $path && file_exists( $path ) ) ? (int) filemtime( $path ) : 0;
		$out[] = array(
			// The name without its extension is what their own routes take.
			'id'         => preg_replace( '/\.sql(\.gz)?$/', '', $raw ),
			'name'       => isset( $row['name'] ) ? (string) $row['name'] : $raw,
			'file'       => $raw,
			'kind'       => $kind,
			'compressed' => $gz,
			'size'       => $size ? size_format( $size ) : '',
			'bytes'      => $size,
			// mtime is a UTC epoch; the column is marked utc so it renders
			// in the site's own zone.
			'created'    => $mtime ? gmdate( 'c', $mtime ) : '',
		);
	}
	return $out;
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_wp_migrate_can() ) {
		return $surfaces;
	}
	// The registry keys a surface by its array key, which becomes the route
	// id; appending would register it as "0".
	$surfaces['wpmigrate-backups'] = array(
		'label'      => __( 'Backups', 'minn-admin' ),
		'sub'        => 'WP Migrate',
		'icon'       => 'database',
		'family'     => 'backups',
		'group'      => 'tools',
		'cap'        => 'read',
		'status'     => array( 'route' => 'minn-admin/v1/wp-migrate/backups/status' ),
		'collection' => array(
			'route'    => 'minn-admin/v1/wp-migrate/backups',
			'itemsKey' => 'items',
			'totalKey' => 'total',
			'search'   => true,
			'columns'  => array(
				array( 'key' => 'name', 'label' => __( 'Backup', 'minn-admin' ), 'format' => 'title' ),
				array( 'key' => 'kind', 'label' => __( 'Kind', 'minn-admin' ), 'format' => 'text' ),
				array( 'key' => 'size', 'label' => __( 'Size', 'minn-admin' ), 'format' => 'text' ),
				array( 'key' => 'created', 'label' => __( 'Created', 'minn-admin' ), 'format' => 'ago', 'utc' => true ),
			),
			'actions' => array(
				array(
					'label' => __( 'Download', 'minn-admin' ),
					'href'  => add_query_arg(
						array(
							'page'                    => 'wp-migrate-db-pro',
							'wpmdb-download-backup'   => '{id}',
							'wpmdb-compressed-backup' => '{compressed}',
						),
						network_admin_url( is_multisite() ? 'settings.php' : 'tools.php' )
					),
				),
				array(
					'label'   => __( 'Delete backup', 'minn-admin' ),
					'route'   => 'minn-admin/v1/wp-migrate/backups/{id}/delete',
					'method'  => 'POST',
					'confirm' => __( 'Delete this backup file? It cannot be recovered.', 'minn-admin' ),
					'danger'  => true,
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	$perm = function () {
		return minn_admin_wp_migrate_can();
	};

	register_rest_route( 'minn-admin/v1', '/wp-migrate/backups', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$all      = minn_admin_wpm_backups();
			$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
			$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
			$search   = strtolower( trim( (string) $request->get_param( 'search' ) ) );
			if ( '' !== $search ) {
				$all = array_values( array_filter( $all, function ( $r ) use ( $search ) {
					return false !== strpos( strtolower( $r['name'] . ' ' . $r['file'] ), $search );
				} ) );
			}
			return rest_ensure_response( array(
				'items' => array_slice( $all, ( $page - 1 ) * $per_page, $per_page ),
				'total' => count( $all ),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wp-migrate/backups/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			$all   = minn_admin_wpm_backups();
			$bytes = 0;
			foreach ( $all as $r ) {
				$bytes += (int) $r['bytes'];
			}
			$newest = ! empty( $all[0]['created'] ) ? $all[0]['created'] : '';
			$rows   = array(
				array(
					'label' => __( 'Newest backup', 'minn-admin' ),
					'value' => $newest ? human_time_diff( strtotime( $newest ) ) . ' ' . __( 'ago', 'minn-admin' ) : __( 'None yet', 'minn-admin' ),
				),
				array( 'label' => __( 'Files', 'minn-admin' ), 'value' => (string) count( $all ) ),
				array(
					'label' => __( 'On disk', 'minn-admin' ),
					'value' => $bytes ? size_format( $bytes ) : '0',
					'hint'  => __( 'These are database files only. WP Migrate does not back up uploads, themes or plugins.', 'minn-admin' ),
				),
			);
			return rest_ensure_response( array(
				'rows'    => $rows,
				'actions' => array(
					array( 'label' => __( 'Back up in WP Migrate ↗', 'minn-admin' ), 'href' => admin_url( 'tools.php?page=wp-migrate-db-pro#migrate' ) ),
				),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/wp-migrate/backups/(?P<id>[^/]+)/delete', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$id = (string) $request['id'];
			// Find the row first so the compressed flag comes from the file
			// that is really there rather than from the caller.
			$match = null;
			foreach ( minn_admin_wpm_backups() as $row ) {
				if ( $row['id'] === $id ) {
					$match = $row;
					break;
				}
			}
			if ( ! $match ) {
				return new WP_Error( 'not_found', __( 'That backup is not there any more.', 'minn-admin' ), array( 'status' => 404 ) );
			}
			// Their own delete-backup route is not reusable from here: its
			// handler answers with wp_send_json_error, which EXITS, so a
			// refused delete would take Minn's whole response down with it,
			// and it reads its arguments from a JSON body rather than REST
			// params. The removal itself is one unlink through their own
			// filesystem, which is what their handler ends up calling, so
			// that is done directly and the result is checked.
			$dir  = minn_admin_wpm_backup_dir();
			$file = $dir . DIRECTORY_SEPARATOR . $match['file'];
			// The name came from their listing, but resolve it anyway and
			// refuse anything that escapes the backup directory.
			$real = realpath( $file );
			$base = realpath( $dir );
			if ( ! $real || ! $base || 0 !== strpos( $real, $base . DIRECTORY_SEPARATOR ) ) {
				return new WP_Error( 'not_found', __( 'That backup is not there any more.', 'minn-admin' ), array( 'status' => 404 ) );
			}
			try {
				$fs = \DeliciousBrains\WPMDB\WPMDBDI::getInstance()->get( \DeliciousBrains\WPMDB\Common\Filesystem\Filesystem::class );
				$fs->unlink( $real );
			} catch ( \Throwable $e ) {
				return new WP_Error( 'delete_failed', $e->getMessage(), array( 'status' => 500 ) );
			}
			if ( file_exists( $real ) ) {
				return new WP_Error( 'delete_failed', __( 'WP Migrate could not delete that backup.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			return rest_ensure_response( array( 'ok' => true ) );
		},
	) );
} );
