<?php
/**
 * Bundled adapter: Broken Link Checker (WPMU DEV) — the local link checker.
 *
 * Broken Link Checker 2.x ships two engines. The cloud engine keeps its
 * results on WPMU DEV's servers; the "legacy" local engine (their default:
 * use_legacy_blc_version is true out of the box) scans posts and comments on
 * the site and stores every link in {prefix}blc_links with where it was found
 * in {prefix}blc_instances. Minn works with the local engine only, and only
 * through its own code: blc_get_links() with their built-in filters (broken,
 * warnings, redirects, dismissed, all) for the list, and their blcLink model
 * for every action, mirroring the admin-ajax handlers behind Tools → Broken
 * Links exactly (recheck, edit URL, unlink, mark as not broken, dismiss). The
 * surface is absent while the cloud engine is selected.
 *
 * Gate: the capability of their links screen (minn_admin_blc_cap()), which
 * is what hands out the nonces every one of those handlers checks. Their URL check for Edit (parse_url, then
 * wp_kses_bad_protocol for users without unfiltered_html) is repeated here.
 *
 * Nav: a "Broken links" item under Tools (family link-health).
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_blc_active() {
	return function_exists( 'blc_get_links' ) && class_exists( 'blcLink' );
}

/**
 * The capability of their links screen, which is also what hands out the
 * per-action nonces their ajax handlers need. On a multisite subsite that is
 * view-broken-links at edit_others_posts; on a single site it is the
 * blc_local screen, whose capability follows their "Show the dashboard widget
 * for" setting (edit_others_posts or manage_options; "Nobody" maps to
 * administrator; empty falls back to manage_options), mirroring
 * Local_Submenu\Controller::prepare_props().
 */
function minn_admin_blc_cap() {
	if ( is_multisite() && ! is_main_site() ) {
		return 'edit_others_posts';
	}
	$cap = 'manage_options';
	try {
		$conf   = function_exists( 'blc_get_configuration' ) ? blc_get_configuration() : null;
		$widget = $conf ? (string) $conf->get( 'dashboard_widget_capability' ) : '';
		if ( in_array( $widget, array( 'edit_others_posts', 'manage_options' ), true ) ) {
			$cap = $widget;
		} elseif ( 'do_not_allow' === $widget ) {
			$cap = 'administrator';
		}
	} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		// Unknown configuration keeps their fallback.
	}
	return $cap;
}

function minn_admin_blc_can() {
	return minn_admin_blc_active() && current_user_can( minn_admin_blc_cap() );
}

function minn_admin_blc_admin_url() {
	return ( is_multisite() && ! is_main_site() )
		? admin_url( 'admin.php?page=view-broken-links' )
		: admin_url( 'admin.php?page=blc_local' );
}

function minn_admin_blc_iso( $ts ) {
	return ( is_numeric( $ts ) && (int) $ts > 0 ) ? gmdate( 'Y-m-d\TH:i:s\Z', (int) $ts ) : '';
}

/**
 * Where a link instance lives: title + edit URL, from their container.
 *
 * @return array { title, edit }
 */
function minn_admin_blc_where( $instance ) {
	$title = '';
	$edit  = '';
	try {
		$type = (string) $instance->container_type;
		$id   = (int) $instance->container_id;
		if ( 'comment' === $type ) {
			$comment = get_comment( $id );
			/* translators: %s: comment author name. */
			$title = $comment ? sprintf( __( 'Comment by %s', 'minn-admin' ), $comment->comment_author ) : __( 'Comment', 'minn-admin' );
			$edit  = admin_url( 'comment.php?action=editcomment&c=' . $id );
		} elseif ( post_type_exists( $type ) && get_post( $id ) ) {
			$title = get_the_title( $id );
			$title = '' !== $title ? $title : __( '(no title)', 'minn-admin' );
			$edit  = (string) get_edit_post_link( $id, 'raw' );
		} else {
			$container = $instance->get_container();
			$edit      = $container && method_exists( $container, 'get_edit_url' ) ? (string) $container->get_edit_url() : '';
			$title     = $type . ' #' . $id;
		}
	} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
	}
	return array( 'title' => wp_strip_all_tags( $title ), 'edit' => $edit );
}

/** List row for one blcLink. */
function minn_admin_blc_item( $link ) {
	$status    = $link->analyse_status();
	$instances = (array) $link->get_instances();
	$first     = $instances ? reset( $instances ) : null;
	$found     = '';
	if ( $first ) {
		$where = minn_admin_blc_where( $first );
		$text  = trim( wp_strip_all_tags( (string) $first->link_text ) );
		$found = ( '' !== $text ? '“' . $text . '” · ' : '' ) . $where['title'];
		if ( count( $instances ) > 1 ) {
			/* translators: %d: number of other places the same link appears. */
			$found .= ' ' . sprintf( __( '+%d more', 'minn-admin' ), count( $instances ) - 1 );
		}
	}
	if ( $link->dismissed ) {
		$state = 'dismissed';
	} elseif ( $link->broken ) {
		$state = 'broken';
	} elseif ( $link->warning ) {
		$state = 'warning';
	} elseif ( (int) $link->redirect_count > 0 ) {
		$state = 'redirect';
	} else {
		$state = 'ok';
	}
	return array(
		'id'         => (int) $link->link_id,
		'url'        => (string) $link->url,
		'state'      => $state,
		'broken'     => $link->broken ? '1' : '0',
		'dismissed'  => $link->dismissed ? '1' : '0',
		'status'     => wp_strip_all_tags( (string) $status['text'] ),
		'http_code'  => $link->http_code ? (string) (int) $link->http_code : '',
		'found'      => $found,
		'last_check' => minn_admin_blc_iso( $link->last_check ),
	);
}

/**
 * Their legacy engine loads its core only on admin screens and cron
 * (legacy/init.php: `is_admin() || DOING_CRON` → require core/core.php and
 * construct wsBrokenLinkChecker). REST is neither, yet blcLink::save(),
 * check(), edit() and unlink() lean on what core.php defines (the
 * transaction manager, the mutex, microtime_float() for the HTTP checker).
 * Load that file, which only defines classes and helpers; the constructor
 * that registers their menus, ajax and cron is deliberately not run. Their
 * init has already taken the non-admin path by the time a REST route runs,
 * so the file can never load twice.
 */
function minn_admin_blc_boot() {
	if ( class_exists( 'wsBrokenLinkChecker' ) || ! defined( 'BLC_DIRECTORY_LEGACY' ) ) {
		return;
	}
	$core = BLC_DIRECTORY_LEGACY . '/core/core.php';
	if ( file_exists( $core ) ) {
		require_once $core;
	}
}

/** One link by id, or a WP_Error. */
function minn_admin_blc_link( $id ) {
	minn_admin_blc_boot();
	$link = new blcLink( (int) $id );
	if ( ! $link->valid() ) {
		return new WP_Error( 'not_found', __( 'Link not found', 'minn-admin' ), array( 'status' => 404 ) );
	}
	return $link;
}

/** Save a model change inside their transaction, as their handlers do. */
function minn_admin_blc_save( $link ) {
	$link->isOptionLinkChanged = true;
	$tm = class_exists( 'TransactionManager' ) ? TransactionManager::getInstance() : null;
	if ( $tm ) {
		$tm->start();
	}
	$ok = $link->save();
	if ( $tm && $ok ) {
		$tm->commit();
	}
	return (bool) $ok;
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_blc_can() ) {
		return $surfaces;
	}
	$surfaces['broken-links'] = array(
		'label'      => __( 'Broken links', 'minn-admin' ),
		'family'     => 'link-health',
		'group'      => 'tools',
		'sub'        => 'Broken Link Checker',
		'plugin'     => 'broken-link-checker',
		'icon'       => 'link',
		'cap'        => minn_admin_blc_cap(),
		'status'     => array( 'route' => 'minn-admin/v1/blc/status' ),
		'collection' => array(
			'route'     => 'minn-admin/v1/blc/links',
			'pageQuery' => 'per_page=25&page={page}',
			'search'    => 'search={q}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'filter'    => array(
				'label'   => __( 'Show', 'minn-admin' ),
				'options' => array(
					array( 'broken', __( 'Broken', 'minn-admin' ) ),
					array( 'warnings', __( 'Warnings', 'minn-admin' ) ),
					array( 'redirects', __( 'Redirects', 'minn-admin' ) ),
					array( 'dismissed', __( 'Dismissed', 'minn-admin' ) ),
					array( 'all', __( 'All', 'minn-admin' ) ),
				),
				'query'   => 'filter={v}',
			),
			'columns'   => array(
				array( 'key' => 'url', 'label' => __( 'Link', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,1.6fr)' ),
				array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'width' => 'minmax(0,1fr)' ),
				array( 'key' => 'found', 'label' => __( 'Found in', 'minn-admin' ), 'width' => 'minmax(0,1.4fr)' ),
				array( 'key' => 'last_check', 'label' => __( 'Checked', 'minn-admin' ), 'format' => 'ago', 'utc' => true ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/blc/links/{id}',
			),
			'actions'   => array(
				array(
					'label'  => __( 'Recheck', 'minn-admin' ),
					'route'  => 'minn-admin/v1/blc/links/{id}/recheck',
					'method' => 'POST',
				),
				array(
					'label'  => __( 'Edit URL', 'minn-admin' ),
					'route'  => 'minn-admin/v1/blc/links/{id}/edit',
					'method' => 'POST',
					'list'   => true,
					'fields' => array(
						array( 'key' => 'new_url', 'label' => __( 'New URL (changed everywhere this link appears)', 'minn-admin' ), 'mono' => true, 'placeholder' => 'https://…' ),
					),
				),
				array(
					'label'  => __( 'Not broken', 'minn-admin' ),
					'route'  => 'minn-admin/v1/blc/links/{id}/not-broken',
					'method' => 'POST',
					'when'   => array( 'key' => 'broken', 'equals' => '1' ),
				),
				array(
					'label'  => __( 'Dismiss', 'minn-admin' ),
					'route'  => 'minn-admin/v1/blc/links/{id}/dismiss',
					'method' => 'POST',
					'body'   => array( 'dismissed' => true ),
					'when'   => array( 'key' => 'dismissed', 'equals' => '0' ),
				),
				array(
					'label'  => __( 'Undismiss', 'minn-admin' ),
					'route'  => 'minn-admin/v1/blc/links/{id}/dismiss',
					'method' => 'POST',
					'body'   => array( 'dismissed' => false ),
					'when'   => array( 'key' => 'dismissed', 'equals' => '1' ),
				),
				array(
					'label'   => __( 'Unlink', 'minn-admin' ),
					'route'   => 'minn-admin/v1/blc/links/{id}/unlink',
					'method'  => 'POST',
					'confirm' => __( 'Remove this link from every post and comment it appears in? The link text stays; only the link goes.', 'minn-admin' ),
					'danger'  => true,
				),
			),
			'bulk'      => array(
				array(
					'label'  => __( 'Recheck', 'minn-admin' ),
					'route'  => 'minn-admin/v1/blc/links/{id}/recheck',
					'method' => 'POST',
				),
				array(
					'label'  => __( 'Dismiss', 'minn-admin' ),
					'route'  => 'minn-admin/v1/blc/links/{id}/dismiss',
					'method' => 'POST',
					'body'   => array( 'dismissed' => true ),
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_blc_active() ) {
		return;
	}
	$perm = 'minn_admin_blc_can';

	register_rest_route( 'minn-admin/v1', '/blc/links', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$filter = sanitize_key( (string) ( $request->get_param( 'filter' ) ?: 'broken' ) );
			if ( ! in_array( $filter, array( 'broken', 'warnings', 'redirects', 'dismissed', 'all' ), true ) ) {
				$filter = 'broken';
			}
			$params = array( 's_filter' => $filter );
			$q      = trim( (string) $request->get_param( 'search' ) );
			if ( '' !== $q ) {
				// Their URL search ("*" is their wildcard, so strip it from input).
				$params['s_link_url'] = str_replace( '*', '', $q );
			}
			$per_page = min( 100, max( 1, (int) ( $request->get_param( 'per_page' ) ?: 25 ) ) );
			$page     = max( 1, (int) $request->get_param( 'page' ) );
			$total    = (int) blc_get_links( array_merge( $params, array( 'count_only' => true ) ) );
			$links    = blc_get_links( array_merge( $params, array(
				'offset'         => ( $page - 1 ) * $per_page,
				'max_results'    => $per_page,
				'load_instances' => true,
				'orderby'        => 'url',
				'order'          => 'asc',
			) ) );
			$items = array();
			foreach ( (array) $links as $link ) {
				$items[] = minn_admin_blc_item( $link );
			}
			return rest_ensure_response( array( 'items' => $items, 'total' => $total ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/blc/links/(?P<id>\d+)', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$link = minn_admin_blc_link( (int) $request['id'] );
			if ( is_wp_error( $link ) ) {
				return $link;
			}
			$item   = minn_admin_blc_item( $link );
			$status = array_values( array_filter( array(
				array( 'label' => __( 'URL', 'minn-admin' ), 'value' => (string) $link->url, 'type' => 'code' ),
				array( 'label' => __( 'Status', 'minn-admin' ), 'value' => $item['status'] ),
				'' !== $item['http_code'] ? array( 'label' => __( 'HTTP code', 'minn-admin' ), 'value' => $item['http_code'] ) : null,
				(int) $link->redirect_count > 0 && $link->final_url ? array( 'label' => __( 'Redirects to', 'minn-admin' ), 'value' => (string) $link->final_url, 'type' => 'code' ) : null,
				$item['last_check'] ? array( 'label' => __( 'Last checked', 'minn-admin' ), 'value' => $item['last_check'] ) : null,
				minn_admin_blc_iso( $link->first_failure ) ? array( 'label' => __( 'Failing since', 'minn-admin' ), 'value' => minn_admin_blc_iso( $link->first_failure ) ) : null,
				minn_admin_blc_iso( $link->last_success ) ? array( 'label' => __( 'Last worked', 'minn-admin' ), 'value' => minn_admin_blc_iso( $link->last_success ) ) : null,
			) ) );
			$where = array();
			foreach ( array_slice( (array) $link->get_instances(), 0, 25 ) as $inst ) {
				$w       = minn_admin_blc_where( $inst );
				$text    = trim( wp_strip_all_tags( (string) $inst->link_text ) );
				$where[] = array(
					'label' => $w['title'],
					'value' => '' !== $text ? '“' . $text . '”' : '—',
				);
			}
			$sections = array( array( 'title' => __( 'Link', 'minn-admin' ), 'rows' => $status ) );
			if ( $where ) {
				$sections[] = array( 'title' => __( 'Found in', 'minn-admin' ), 'rows' => $where );
			}
			$log = trim( wp_strip_all_tags( (string) $link->log ) );
			if ( '' !== $log ) {
				$sections[] = array( 'title' => __( 'Check log', 'minn-admin' ), 'rows' => array( array( 'label' => __( 'Log', 'minn-admin' ), 'value' => $log, 'type' => 'code' ) ) );
			}
			return rest_ensure_response( array( 'sections' => $sections, 'adminUrl' => minn_admin_blc_admin_url() ) );
		},
	) );

	// Recheck: their ajax_recheck — queue it (last_check_attempt 0), then
	// check now and save, inside their transaction.
	register_rest_route( 'minn-admin/v1', '/blc/links/(?P<id>\d+)/recheck', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$link = minn_admin_blc_link( (int) $request['id'] );
			if ( is_wp_error( $link ) ) {
				return $link;
			}
			$tm = class_exists( 'TransactionManager' ) ? TransactionManager::getInstance() : null;
			if ( $tm ) {
				$tm->start();
			}
			$link->last_check_attempt  = 0;
			$link->isOptionLinkChanged = true;
			$link->save();
			$link->check( true );
			if ( $tm ) {
				$tm->commit();
			}
			$status = $link->analyse_status();
			/* translators: %s: the link's status after the check, like "Not Found". */
			return rest_ensure_response( array( 'message' => sprintf( __( 'Rechecked: %s', 'minn-admin' ), wp_strip_all_tags( (string) $status['text'] ) ) ) );
		},
	) );

	// Edit URL: their ajax_edit — same URL checks, then blcLink::edit(), which
	// rewrites the link in every post and comment and returns counts.
	register_rest_route( 'minn-admin/v1', '/blc/links/(?P<id>\d+)/edit', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$link = minn_admin_blc_link( (int) $request['id'] );
			if ( is_wp_error( $link ) ) {
				return $link;
			}
			$new_url = trim( (string) $request->get_param( 'new_url' ) );
			if ( '' === $new_url || ! wp_parse_url( $new_url ) ) {
				return new WP_Error( 'minn_blc_url', __( 'That URL is not valid.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			if ( ! current_user_can( 'unfiltered_html' ) && wp_kses_bad_protocol( $new_url, wp_allowed_protocols() ) !== $new_url ) {
				return new WP_Error( 'minn_blc_url', __( 'That URL is not valid.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			$rez = $link->edit( $new_url, null );
			if ( false === $rez ) {
				return new WP_Error( 'minn_blc_edit', __( 'Broken Link Checker could not change that link.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			$msgs = array();
			foreach ( (array) $rez['errors'] as $err ) {
				if ( is_wp_error( $err ) ) {
					$msgs[] = implode( ', ', $err->get_error_messages() );
				}
			}
			$ok = (int) $rez['cnt_okay'];
			/* translators: %d: number of places the link was changed. */
			$message = sprintf( _n( 'Changed in %d place.', 'Changed in %d places.', $ok, 'minn-admin' ), $ok );
			if ( $msgs ) {
				$message .= ' ' . implode( ' ', $msgs );
			}
			return rest_ensure_response( array( 'message' => $message, 'id' => (int) $rez['new_link_id'] ) );
		},
	) );

	// Unlink: their ajax_unlink — blcLink::unlink() removes the <a> from every
	// instance and keeps the text.
	register_rest_route( 'minn-admin/v1', '/blc/links/(?P<id>\d+)/unlink', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$link = minn_admin_blc_link( (int) $request['id'] );
			if ( is_wp_error( $link ) ) {
				return $link;
			}
			$rez = $link->unlink();
			if ( false === $rez ) {
				return new WP_Error( 'minn_blc_unlink', __( 'Broken Link Checker could not remove that link.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			$ok = (int) $rez['cnt_okay'];
			/* translators: %d: number of places the link was removed from. */
			return rest_ensure_response( array( 'message' => sprintf( _n( 'Removed from %d place.', 'Removed from %d places.', $ok, 'minn-admin' ), $ok ) ) );
		},
	) );

	// Not broken: their ajax_discard (false positive).
	register_rest_route( 'minn-admin/v1', '/blc/links/(?P<id>\d+)/not-broken', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$link = minn_admin_blc_link( (int) $request['id'] );
			if ( is_wp_error( $link ) ) {
				return $link;
			}
			$link->broken             = false;
			$link->warning            = false;
			$link->false_positive     = true;
			$link->last_check_attempt = time();
			$link->log                = __( 'This link was manually marked as working by the user.', 'broken-link-checker' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- their own log string, stored in their row.
			if ( ! minn_admin_blc_save( $link ) ) {
				return new WP_Error( 'minn_blc_save', __( 'Broken Link Checker could not update that link.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			return rest_ensure_response( array( 'message' => __( 'Marked as not broken.', 'minn-admin' ) ) );
		},
	) );

	// Dismiss / undismiss: their ajax_set_link_dismissed.
	register_rest_route( 'minn-admin/v1', '/blc/links/(?P<id>\d+)/dismiss', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'args'                => array(
			'dismissed' => array(
				'required'          => true,
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
		),
		'callback'            => function ( WP_REST_Request $request ) {
			$link = minn_admin_blc_link( (int) $request['id'] );
			if ( is_wp_error( $link ) ) {
				return $link;
			}
			$link->dismissed = (bool) $request->get_param( 'dismissed' );
			if ( ! minn_admin_blc_save( $link ) ) {
				return new WP_Error( 'minn_blc_save', __( 'Broken Link Checker could not update that link.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			return rest_ensure_response( array( 'message' => $link->dismissed ? __( 'Dismissed.', 'minn-admin' ) : __( 'Restored to the list.', 'minn-admin' ) ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/blc/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			$count = function ( $filter ) {
				return (int) blc_get_links( array( 's_filter' => $filter, 'count_only' => true ) );
			};
			$broken = $count( 'broken' );
			$rows   = array(
				array(
					'label' => __( 'Broken links', 'minn-admin' ),
					'value' => number_format_i18n( $broken ),
					'hint'  => $broken ? __( 'Fix, unlink or dismiss them below', 'minn-admin' ) : __( 'None found', 'minn-admin' ),
				),
				array( 'label' => __( 'Warnings', 'minn-admin' ), 'value' => number_format_i18n( $count( 'warnings' ) ) ),
				array( 'label' => __( 'Redirects', 'minn-admin' ), 'value' => number_format_i18n( $count( 'redirects' ) ) ),
				array( 'label' => __( 'Links checked', 'minn-admin' ), 'value' => number_format_i18n( $count( 'all' ) ) ),
			);
			return rest_ensure_response( array(
				'rows'    => $rows,
				'actions' => array(
					array( 'label' => __( 'Open Broken Link Checker ↗', 'minn-admin' ), 'href' => minn_admin_blc_admin_url() ),
				),
			) );
		},
	) );
} );
