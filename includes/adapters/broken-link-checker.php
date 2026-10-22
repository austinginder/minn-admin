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
 * A check time for the link page, in the site's zone: its rows print their
 * values as text, so an ISO stamp would reach the screen raw.
 */
function minn_admin_blc_when( $ts ) {
	return ( is_numeric( $ts ) && (int) $ts > 0 ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $ts ) : '';
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

/**
 * Whether the user may edit every item a link appears in: Broken Link
 * Checker's own rule before an edit or an unlink (current_user_can_modify_link
 * in its core.php, since 2.4.15). The gate only says who may use the links
 * screen (edit_others_posts by default); an Editor still may not rewrite a
 * link inside a post type, comment or field they cannot edit. An item that
 * cannot be resolved cannot be authorised, so it denies.
 *
 * @param blcLink $link Link.
 * @return bool
 */
function minn_admin_blc_can_modify( $link ) {
	if ( ! ( $link instanceof blcLink ) || ! $link->valid() || ! class_exists( 'blcContainerHelper' ) ) {
		return false;
	}
	$refs = array();
	foreach ( (array) $link->get_instances() as $instance ) {
		$refs[ $instance->container_type . '|' . $instance->container_id ] = array( $instance->container_type, $instance->container_id );
	}
	if ( ! $refs ) {
		return true; // Used nowhere: no item to authorise, as BLC answers.
	}
	$containers = blcContainerHelper::get_containers( $refs );
	foreach ( array_keys( $refs ) as $key ) {
		if ( ! isset( $containers[ $key ] ) || ! $containers[ $key ]->current_user_can_edit() ) {
			return false;
		}
	}
	return true;
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

minn_admin_add_surface( 'broken-links', 'minn_admin_blc_can', function () {
	return array(
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
} );

/** One link's detail: where it points, how it last checked, where it is found, their check log. */
function minn_admin_blc_view( array $item ) {
	$link = minn_admin_blc_link( $item['id'] );
	if ( is_wp_error( $link ) ) {
		return $link;
	}
	$status = array_values( array_filter( array(
		array( 'label' => __( 'URL', 'minn-admin' ), 'value' => (string) $link->url, 'type' => 'code' ),
		array( 'label' => __( 'Status', 'minn-admin' ), 'value' => $item['status'] ),
		'' !== $item['http_code'] ? array( 'label' => __( 'HTTP code', 'minn-admin' ), 'value' => $item['http_code'] ) : null,
		(int) $link->redirect_count > 0 && $link->final_url ? array( 'label' => __( 'Redirects to', 'minn-admin' ), 'value' => (string) $link->final_url, 'type' => 'code' ) : null,
		minn_admin_blc_when( $link->last_check ) ? array( 'label' => __( 'Last checked', 'minn-admin' ), 'value' => minn_admin_blc_when( $link->last_check ) ) : null,
		minn_admin_blc_when( $link->first_failure ) ? array( 'label' => __( 'Failing since', 'minn-admin' ), 'value' => minn_admin_blc_when( $link->first_failure ) ) : null,
		minn_admin_blc_when( $link->last_success ) ? array( 'label' => __( 'Last worked', 'minn-admin' ), 'value' => minn_admin_blc_when( $link->last_success ) ) : null,
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
	return array( 'sections' => $sections, 'adminUrl' => minn_admin_blc_admin_url() );
}

/** A model write's answer: their result, or why it did not happen. */
function minn_admin_blc_saved( $link, $code ) {
	return minn_admin_blc_save( $link ) ? true : new WP_Error( $code, __( 'Broken Link Checker could not update that link.', 'minn-admin' ), array( 'status' => 500 ) );
}

// Every read and action through their own code: blc_get_links() with their
// built-in filters for the list, their blcLink model for one link, the calls
// their admin-ajax handlers make.
minn_admin_resource( 'blc/links', function () {
	$fields = array(
		'id'         => 'int',
		'url'        => 'text',
		'state'      => 'text',
		'broken'     => 'text',
		'dismissed'  => 'text',
		'status'     => 'text',
		'http_code'  => 'text',
		'found'      => 'text',
		'last_check' => 'text',
	);
	return array(
		'active'  => 'minn_admin_blc_active',
		'gates'   => array( 'view' => 'minn_admin_blc_can' ),
		'labels'  => array( 'not_found' => __( 'Link not found', 'minn-admin' ) ),
		'source'  => array(
			'list' => function ( array $q ) {
				$params = array( 's_filter' => $q['filter'] );
				if ( '' !== $q['search'] ) {
					// Their URL search ("*" is their wildcard, so strip it from input).
					$params['s_link_url'] = str_replace( '*', '', $q['search'] );
				}
				$items = array();
				foreach ( (array) blc_get_links( array_merge( $params, array(
					'offset'         => ( $q['page'] - 1 ) * $q['per_page'],
					'max_results'    => $q['per_page'],
					'load_instances' => true,
					'orderby'        => 'url',
					'order'          => 'asc',
				) ) ) as $link ) {
					$items[] = minn_admin_blc_item( $link );
				}
				return array( $items, (int) blc_get_links( array_merge( $params, array( 'count_only' => true ) ) ) );
			},
			'get'  => function ( $id ) {
				$link = minn_admin_blc_link( $id );
				return is_wp_error( $link ) ? null : minn_admin_blc_item( $link );
			},
		),
		'list'    => array(
			'filter' => array( 'param' => 'filter', 'column' => 's_filter', 'values' => array( 'broken', 'warnings', 'redirects', 'dismissed', 'all' ), 'default' => 'broken' ),
			'search' => array( 'columns' => array( 'url' ) ),
			'fields' => $fields,
		),
		'item'    => array(
			'view'   => 'minn_admin_blc_view',
			'fields' => array( 'sections' => 'raw', 'adminUrl' => 'text' ),
		),
		'actions' => array(
			// Their ajax_recheck: queue it (last_check_attempt 0), then check
			// now and save, inside their transaction.
			'recheck'    => array(
				'gate'   => 'view',
				'run'    => function ( $id ) {
					$link  = minn_admin_blc_link( $id );
					$start = time();
					$tm    = class_exists( 'TransactionManager' ) ? TransactionManager::getInstance() : null;
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
					return $start;
				},
				'expect' => function ( $id, $fresh, $start ) {
					return is_array( $fresh ) && '' !== $fresh['last_check'] && strtotime( $fresh['last_check'] ) >= (int) $start - 1;
				},
				'reply'  => function ( $id, $fresh ) {
					/* translators: %s: the link's status after the check, like "Not Found". */
					return array( 'message' => sprintf( __( 'Rechecked: %s', 'minn-admin' ), $fresh['status'] ) );
				},
			),
			// Their ajax_edit: the same URL checks, then blcLink::edit(), which
			// rewrites the link in every post and comment and answers counts.
			'edit'       => array(
				'gate'   => 'view',
				'run'    => function ( $id, array $row, WP_REST_Request $request ) {
					$new_url = trim( (string) $request->get_param( 'new_url' ) );
					if ( '' === $new_url || ! wp_parse_url( $new_url ) || ( ! current_user_can( 'unfiltered_html' ) && wp_kses_bad_protocol( $new_url, wp_allowed_protocols() ) !== $new_url ) ) {
						return new WP_Error( 'minn_blc_url', __( 'That URL is not valid.', 'minn-admin' ), array( 'status' => 400 ) );
					}
					$link = minn_admin_blc_link( $id );
					if ( ! minn_admin_blc_can_modify( $link ) ) {
						return new WP_Error( 'minn_blc_forbidden', __( 'You cannot edit every place this link appears, so it was not changed.', 'minn-admin' ), array( 'status' => 403 ) );
					}
					$rez = $link->edit( $new_url, null );
					return false === $rez ? new WP_Error( 'minn_blc_edit', __( 'Broken Link Checker could not change that link.', 'minn-admin' ), array( 'status' => 500 ) ) : $rez;
				},
				// Their own count of the places it changed is the proof.
				'expect' => function ( $id, $fresh, $rez ) {
					return is_array( $rez ) && isset( $rez['cnt_okay'] );
				},
				'reply'  => function ( $id, $fresh, $rez ) {
					$msgs = array();
					foreach ( (array) $rez['errors'] as $err ) {
						if ( is_wp_error( $err ) ) {
							$msgs[] = implode( ', ', $err->get_error_messages() );
						}
					}
					$ok = (int) $rez['cnt_okay'];
					/* translators: %d: number of places the link was changed. */
					$message = sprintf( _n( 'Changed in %d place.', 'Changed in %d places.', $ok, 'minn-admin' ), $ok );
					return array( 'message' => $msgs ? $message . ' ' . implode( ' ', $msgs ) : $message, 'id' => (int) $rez['new_link_id'] );
				},
			),
			// Their ajax_unlink: blcLink::unlink() removes the <a> from every
			// instance and keeps the text.
			'unlink'     => array(
				'gate'   => 'view',
				'run'    => function ( $id ) {
					$link = minn_admin_blc_link( $id );
					if ( ! minn_admin_blc_can_modify( $link ) ) {
						return new WP_Error( 'minn_blc_forbidden', __( 'You cannot edit every place this link appears, so it was not changed.', 'minn-admin' ), array( 'status' => 403 ) );
					}
					$rez = $link->unlink();
					return false === $rez ? new WP_Error( 'minn_blc_unlink', __( 'Broken Link Checker could not remove that link.', 'minn-admin' ), array( 'status' => 500 ) ) : $rez;
				},
				'expect' => function ( $id, $fresh, $rez ) {
					return is_array( $rez ) && isset( $rez['cnt_okay'] );
				},
				'reply'  => function ( $id, $fresh, $rez ) {
					$ok = (int) $rez['cnt_okay'];
					/* translators: %d: number of places the link was removed from. */
					return array( 'message' => sprintf( _n( 'Removed from %d place.', 'Removed from %d places.', $ok, 'minn-admin' ), $ok ) );
				},
			),
			// Their ajax_discard (a false positive).
			'not-broken' => array(
				'gate'   => 'view',
				'run'    => function ( $id ) {
					$link                     = minn_admin_blc_link( $id );
					$link->broken             = false;
					$link->warning            = false;
					$link->false_positive     = true;
					$link->last_check_attempt = time();
					$link->log                = __( 'This link was manually marked as working by the user.', 'broken-link-checker' ); // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- their own log string, stored in their row.
					return minn_admin_blc_saved( $link, 'minn_blc_save' );
				},
				'expect' => array( 'broken' => '0' ),
				'done'   => __( 'Marked as not broken.', 'minn-admin' ),
			),
			// Their ajax_set_link_dismissed.
			'dismiss'    => array(
				'gate'   => 'view',
				'args'   => array(
					'dismissed' => array(
						'required' => true,
						'type'     => 'boolean',
					),
				),
				'run'    => function ( $id, array $row, WP_REST_Request $request ) {
					$link            = minn_admin_blc_link( $id );
					$link->dismissed = (bool) $request->get_param( 'dismissed' );
					$saved           = minn_admin_blc_saved( $link, 'minn_blc_save' );
					return is_wp_error( $saved ) ? $saved : ( $link->dismissed ? '1' : '0' );
				},
				'expect' => function ( $id, $fresh, $want ) {
					return is_array( $fresh ) && $want === $fresh['dismissed'];
				},
				'reply'  => function ( $id, $fresh, $want ) {
					return array( 'message' => '1' === $want ? __( 'Dismissed.', 'minn-admin' ) : __( 'Restored to the list.', 'minn-admin' ) );
				},
			),
		),
	);
} );

minn_admin_endpoint( 'blc/status', array(
	'active' => 'minn_admin_blc_active',
	'gate'   => 'minn_admin_blc_can',
	'run'    => function () {
		$count  = function ( $filter ) {
			return (int) blc_get_links( array( 's_filter' => $filter, 'count_only' => true ) );
		};
		$broken = $count( 'broken' );
		return array(
			'rows'    => array(
				array(
					'label' => __( 'Broken links', 'minn-admin' ),
					'value' => number_format_i18n( $broken ),
					'hint'  => $broken ? __( 'Fix, unlink or dismiss them below', 'minn-admin' ) : __( 'None found', 'minn-admin' ),
				),
				array( 'label' => __( 'Warnings', 'minn-admin' ), 'value' => number_format_i18n( $count( 'warnings' ) ) ),
				array( 'label' => __( 'Redirects', 'minn-admin' ), 'value' => number_format_i18n( $count( 'redirects' ) ) ),
				array( 'label' => __( 'Links checked', 'minn-admin' ), 'value' => number_format_i18n( $count( 'all' ) ) ),
			),
			'actions' => array(
				array( 'label' => __( 'Open Broken Link Checker ↗', 'minn-admin' ), 'href' => minn_admin_blc_admin_url() ),
			),
		);
	},
) );
