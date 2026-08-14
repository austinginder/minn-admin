<?php
/**
 * Bundled adapter: Duplicator (Lite) — backups family.
 *
 * Duplicator stores one row per package in {prefix}duplicator_packages;
 * the `package` column is a serialized PHP object and is NEVER touched by
 * this shim (sizes come from the archive files on disk in its storage dir,
 * matched by name_hash). Free-tier packages are MANUAL builds, so Minn
 * makes no freshness claims (the Disembark precedent): the status card
 * reports the newest completed package and total disk footprint, honestly
 * labeled. Delete goes through Duplicator's OWN loader + delete() so its
 * file cleanup logic stays its code. Building a package stays on its
 * screen (a multi-step JS wizard).
 *
 * Two upstream quirks the code mirrors: (a) `created` is written with
 * current_time('mysql', get_option('gmt_offset', 1)) — the offset rides
 * the $gmt FLAG, so timestamps are UTC exactly when the site offset is
 * non-zero; (b) the packages table appears via dbDelta on an admin visit,
 * so every route SHOW TABLES-gates and answers empty before then.
 *
 * Caps mirror the plugin: its whole admin is gated on `export`.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_duplicator_active() {
	global $wpdb;
	if ( ! defined( 'DUPLICATOR_VERSION' ) && ! class_exists( 'DUP_Package' ) ) {
		return false;
	}
	// Duplicator keeps ONE packages table on base_prefix and its archives
	// hold the WHOLE network's database — on multisite that's super-admin
	// data (its own menu lives in Network Admin there).
	if ( ! Minn_Admin::network_owner() ) {
		return false;
	}
	$table = $wpdb->base_prefix . 'duplicator_packages';
	$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
	return $found && 0 === strcasecmp( (string) $found, $table );
}

/** Their created-column quirk: UTC exactly when the site offset is truthy. */
function minn_admin_duplicator_dates_are_gmt() {
	return (bool) get_option( 'gmt_offset', 1 );
}

/** Duplicator's storage dir (wp-content/backups-dup-lite by default). */
function minn_admin_duplicator_ssdir() {
	try {
		if ( class_exists( 'DUP_Settings' ) && method_exists( 'DUP_Settings', 'getSsdirPath' ) ) {
			return (string) DUP_Settings::getSsdirPath();
		}
	} catch ( \Throwable $e ) {
		// Fall through to the default location.
	}
	return WP_CONTENT_DIR . '/backups-dup-lite';
}

/** Archive size on disk for a package, matched by its name_hash file stem. */
function minn_admin_duplicator_archive_size( $name, $hash ) {
	$dir = minn_admin_duplicator_ssdir();
	if ( ! $name || ! $hash || ! is_dir( $dir ) ) {
		return 0;
	}
	$size = 0;
	foreach ( (array) glob( $dir . '/' . $name . '_' . $hash . '_archive.*' ) as $file ) {
		$size += (int) @filesize( $file );
	}
	return $size;
}

/** Display rows for the packages table, newest first. */
function minn_admin_duplicator_rows() {
	global $wpdb;
	$table = $wpdb->base_prefix . 'duplicator_packages';
	$rows  = $wpdb->get_results( "SELECT id, name, hash, status, created, owner FROM {$table} ORDER BY id DESC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$gmt   = minn_admin_duplicator_dates_are_gmt();
	$items = array();
	foreach ( (array) $rows as $r ) {
		$status = 'building';
		if ( (int) $r->status >= 100 ) {
			$status = 'completed';
		} elseif ( (int) $r->status < 0 ) {
			$status = 'error';
		}
		$size    = minn_admin_duplicator_archive_size( $r->name, $r->hash );
		$items[] = array(
			'id'      => (int) $r->id,
			'name'    => (string) $r->name,
			'status'  => $status,
			'size'    => $size ? size_format( $size ) : '—',
			'owner'   => (string) $r->owner,
			'created' => $gmt ? str_replace( ' ', 'T', (string) $r->created ) . 'Z' : (string) $r->created,
		);
	}
	return $items;
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_duplicator_active() ) {
		return $surfaces;
	}

	$surfaces['duplicator'] = array(
		'label'      => __( 'Backups', 'minn-admin' ),
		'sub'        => 'Duplicator',
		'icon'       => 'database',
		'cap'        => 'export',
		'family'     => 'backups',
		'status'     => array( 'route' => 'minn-admin/v1/duplicator/status' ),
		'collection' => array(
			'route'     => 'minn-admin/v1/duplicator/packages',
			'pageQuery' => 'per_page=25&page={page}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'columns'   => array(
				array( 'key' => 'name', 'label' => __( 'Package', 'minn-admin' ), 'format' => 'title' ),
				array( 'key' => 'size', 'label' => __( 'Size', 'minn-admin' ), 'format' => 'text' ),
				array( 'key' => 'owner', 'label' => __( 'By', 'minn-admin' ), 'format' => 'text' ),
				array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill' ),
				array( 'key' => 'created', 'label' => __( 'Created', 'minn-admin' ), 'format' => 'ago' ),
			),
			'detail'    => array(
				'skip' => array( 'name' ),
			),
			'actions'   => array(
				array(
					'label'   => __( 'Delete package', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/duplicator/packages/{id}',
					'confirm' => __( 'Delete this package and its archive files permanently?', 'minn-admin' ),
					'danger'  => true,
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_duplicator_active() ) {
		return;
	}
	$perm = function () {
		// Network-shared packages table (see minn_admin_duplicator_active).
		return current_user_can( 'export' ) && Minn_Admin::network_owner();
	};

	register_rest_route( 'minn-admin/v1', '/duplicator/packages', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
			$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
			$all      = minn_admin_duplicator_rows();
			return rest_ensure_response( array(
				'items' => array_slice( $all, ( $page - 1 ) * $per_page, $per_page ),
				'total' => count( $all ),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/duplicator/packages/(?P<id>\d+)', array(
		'methods'             => 'DELETE',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			// Duplicator's own loader + delete() — its file cleanup, not a
			// re-guess. (Their getByID unserializes their own blob; this
			// shim never does.) getByID does NOT copy the row id onto the
			// object — delete() queries WHERE id = $this->ID, so a blob
			// whose stored ID drifted (site clones, fixtures) silently
			// no-ops. Pin it, and verify the row is really gone.
			global $wpdb;
			$id = (int) $request['id'];
			try {
				$package = DUP_Package::getByID( $id );
				if ( ! $package ) {
					return new WP_Error( 'not_found', __( 'Package not found', 'minn-admin' ), array( 'status' => 404 ) );
				}
				$package->ID = $id;
				$package->delete();
			} catch ( \Throwable $e ) {
				return new WP_Error( 'delete_failed', __( 'Duplicator could not delete: ', 'minn-admin' ) . $e->getMessage(), array( 'status' => 500 ) );
			}
			$table = $wpdb->base_prefix . 'duplicator_packages';
			if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				return new WP_Error( 'delete_failed', __( 'Duplicator reported success but the package row is still there.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			return rest_ensure_response( array( 'deleted' => true ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/duplicator/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			$rows      = minn_admin_duplicator_rows();
			$completed = array_values( array_filter( $rows, function ( $r ) {
				return 'completed' === $r['status'];
			} ) );
			$disk = 0;
			$dir  = minn_admin_duplicator_ssdir();
			if ( is_dir( $dir ) ) {
				foreach ( (array) glob( $dir . '/*' ) as $file ) {
					if ( is_file( $file ) ) {
						$disk += (int) @filesize( $file );
					}
				}
			}
			$newest = $completed ? $completed[0] : null;
			$gmt    = minn_admin_duplicator_dates_are_gmt();
			$when   = '';
			if ( $newest && $newest['created'] ) {
				$ts   = strtotime( $gmt ? $newest['created'] : get_gmt_from_date( $newest['created'] ) . 'Z' );
				$when = $ts ? human_time_diff( $ts ) . ' ago' : '';
			}
			return rest_ensure_response( array(
				'rows'    => array(
					array(
						'label' => __( 'Newest package', 'minn-admin' ),
						'value' => $newest ? $newest['name'] : __( 'None yet', 'minn-admin' ),
						'hint'  => $newest ? trim( $when . ' · ' . $newest['size'], ' ·' ) : 'Packages are built manually from Duplicator\'s screen.',
					),
					array(
						'label' => __( 'Packages', 'minn-admin' ),
						'value' => (string) count( $rows ),
						'hint'  => __( 'Manual builds; Minn makes no freshness claims for Duplicator.', 'minn-admin' ),
					),
					array(
						'label' => __( 'On disk', 'minn-admin' ),
						'value' => $disk ? size_format( $disk ) : '0 B',
						'hint'  => basename( $dir ),
					),
				),
				'actions' => array(
					array( 'label' => __( 'Build a package ↗', 'minn-admin' ), 'href' => admin_url( 'admin.php?page=duplicator' ) ),
				),
			) );
		},
	) );
} );
