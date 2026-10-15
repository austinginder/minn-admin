<?php
/**
 * Bundled adapter: Simple History.
 *
 * Simple History 5.x ships a full REST API (simple-history/v1/events with
 * standard WP pagination), so the list is a pure descriptor. The status
 * card counts simple_history through the kernel, over the loggers this
 * caller may read, so the surface matches Solid / LLA-R / Wordfence
 * daily-ops depth. Visibility
 * follows Simple History's own view capability.
 *
 * last-sweep: 2026-09-19
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_simple_history_can() {
	// Stealth mode exists to keep the plugin out of sight, and it works by
	// removing its own pages rather than by a capability. Naming the surface
	// "Activity Log - Simple History" in the nav announces the plugin to
	// exactly the people it is being hidden from.
	//
	// There are TWO stealth modes and the constant is only one of them. The
	// full mode hides the log from everyone, before any list of addresses is
	// consulted. The partial mode needs no constant at all: a non-empty list
	// of allowed addresses IS the switch, and that is the mode a site actually
	// deploys, because it leaves a working screen for whoever is on the list.
	// Ask their own resolver rather than reproducing either rule, since their
	// log route checks only the capability and would not catch a mistake here.
	if ( class_exists( '\Simple_History\Services\Stealth_Mode' )
		&& method_exists( '\Simple_History\Services\Stealth_Mode', 'is_gui_visible_to_user' ) ) {
		if ( ! \Simple_History\Services\Stealth_Mode::is_gui_visible_to_user() ) {
			return false;
		}
	} else {
		// A build that does not carry the resolver: reproduce both switches,
		// and refuse rather than open up when the answer is not clear.
		if ( apply_filters( 'simple_history/full_stealth_mode_enabled', defined( 'SIMPLE_HISTORY_STEALTH_MODE_ENABLE' ) && true === SIMPLE_HISTORY_STEALTH_MODE_ENABLE ) ) {
			return false;
		}
		$from_constant = defined( 'SIMPLE_HISTORY_STEALTH_MODE_ALLOWED_EMAILS' )
			? array_filter( array_map( 'trim', explode( ',', (string) SIMPLE_HISTORY_STEALTH_MODE_ALLOWED_EMAILS ) ) )
			: array();
		$allowed = array_filter( (array) apply_filters( 'simple_history/stealth_mode_allowed_emails', $from_constant ) );
		if ( $allowed ) {
			$user = wp_get_current_user();
			if ( ! $user || ! in_array( (string) $user->user_email, $allowed, true ) ) {
				return false;
			}
		}
	}
	return current_user_can( minn_admin_simple_history_view_cap() );
}

/**
 * Their own view capability, resolver first.
 *
 * Simple History applies TWO filters, the deprecated name before the current
 * one, so reproducing only the current one answers differently from their own
 * screen on a site that hardened this years ago with the filter that existed
 * then. Call their resolver where it is available, the way the WSAL, Stream
 * and Asset CleanUp adapters do, and reproduce both filters in order only as
 * a fallback.
 *
 * @return string
 */
function minn_admin_simple_history_view_cap() {
	if ( class_exists( '\\Simple_History\\Helpers' ) && method_exists( '\\Simple_History\\Helpers', 'get_view_history_capability' ) ) {
		try {
			return (string) \Simple_History\Helpers::get_view_history_capability();
		} catch ( \Throwable $e ) {
			// fall through to the filters below
		}
	}
	return (string) apply_filters(
		'simple_history/view_history_capability',
		apply_filters( 'simple_history_view_history_capability', 'edit_pages' )
	);
}

function minn_admin_simple_history_admin_url() {
	if ( class_exists( 'Simple_History\\Simple_History' ) ) {
		try {
			$slug = \Simple_History\Simple_History::MENU_PAGE_SLUG;
			if ( is_string( $slug ) && $slug ) {
				return admin_url( 'admin.php?page=' . $slug );
			}
		} catch ( \Throwable $e ) { /* fall through */ }
	}
	// Stable fallbacks across SH 4.x/5.x placements.
	return admin_url( 'index.php?page=simple_history_page' );
}

/**
 * The loggers Simple History itself lets this caller read.
 *
 * Their Log_Query never reads the table without them: each logger declares a
 * capability, so an Editor is shown posts and media but never the user,
 * options, plugin or core-update loggers. The collection route rides their own
 * REST endpoint and inherits that for free. Anything here that reads the
 * table directly has to hold to the same set, or it reports on rows the same
 * caller is refused when they ask for them one by one.
 *
 * Returns null when their API is missing, so the caller can refuse rather
 * than count everything; an empty list when the caller may read no logger.
 *
 * @return string[]|null Logger slugs.
 */
function minn_admin_simple_history_loggers() {
	if ( ! class_exists( 'Simple_History\\Simple_History' ) ) {
		return null;
	}
	try {
		$sh = Simple_History\Simple_History::get_instance();
		if ( ! method_exists( $sh, 'get_loggers_that_user_can_read' ) ) {
			return null;
		}
		$slugs = array();
		foreach ( (array) $sh->get_loggers_that_user_can_read( get_current_user_id(), 'array' ) as $logger ) {
			if ( isset( $logger['instance'] ) && is_object( $logger['instance'] ) && method_exists( $logger['instance'], 'get_slug' ) ) {
				$slugs[] = (string) $logger['instance']->get_slug();
			}
		}
		return $slugs;
	} catch ( \Throwable $e ) {
		return null;
	}
}

/**
 * Status-card model: 24h / 7d / all-time counts + level mix + last event,
 * over the loggers this caller may read.
 *
 * @return array{rows:array,actions:array}
 */
function minn_admin_simple_history_status_model() {
	$open    = array( array( 'label' => __( 'Open Simple History ↗', 'minn-admin' ), 'href' => minn_admin_simple_history_admin_url() ) );
	$loggers = minn_admin_simple_history_loggers();
	if ( null === $loggers ) {
		return minn_admin_activity_none( $open, __( 'Cannot determine which loggers you may read', 'minn-admin' ) );
	}
	// Count only what this caller is allowed to read, exactly as their own
	// Log_Query does. Without it an Editor gets a live failed-login counter
	// and an administrator-activity timeline from a screen the plugin blanks
	// for them: failed logins are recorded by SimpleUserLogger, which asks
	// for edit_users. SH stores `date` as GMT (current_time( 'mysql', 1 )).
	// Bars count EVENTS, the number their own counters report. Their list
	// route folds repeats of one event into a single row with an occasions
	// count, so a bar's total can exceed the rows a click narrows the list
	// to. That is their screen's own arithmetic, not a windowing error.
	$errors = array( 'emergency', 'alert', 'critical', 'error' );
	$n      = Minn_Admin_Kernel::log_counts( array(
		'table' => 'simple_history',
		'key'   => 'id',
		'where' => array( 'logger' => $loggers ),
		'date'  => array( 'column' => 'date', 'clock' => 'utc' ),
	), array(
		'counts' => array(
			'errors' => array( 'where' => array( 'level' => $errors ), 'window' => 7 * DAY_IN_SECONDS ),
			'warns'  => array( 'where' => array( 'level' => 'warning' ), 'window' => 7 * DAY_IN_SECONDS ),
		),
		'chart'  => array( 'split' => 'level', 'secondary' => $errors ),
	) );
	if ( null === $n ) {
		return minn_admin_activity_none( $open, __( 'History table not found', 'minn-admin' ) );
	}
	$mix = array();
	if ( $n['counts']['errors'] ) {
		$mix[] = number_format_i18n( $n['counts']['errors'] ) . ' error' . ( 1 === $n['counts']['errors'] ? '' : 's' );
	}
	if ( $n['counts']['warns'] ) {
		$mix[] = number_format_i18n( $n['counts']['warns'] ) . ' warning' . ( 1 === $n['counts']['warns'] ? '' : 's' );
	}
	$severity = $mix ? implode( ' · ', $mix ) : __( 'No errors or warnings', 'minn-admin' );
	return minn_admin_activity_card( $n, $open, __( 'Severity (7d)', 'minn-admin' ), $severity, __( 'Errors', 'minn-admin' ) );
}

/**
 * Their label for an initiator ("web_user" is "Anonymous web user"), the
 * words their own screen prints for an event with no user behind it.
 *
 * @param string $initiator Stored initiator.
 * @return string
 */
function minn_admin_simple_history_initiator_label( $initiator ) {
	$initiator = (string) $initiator;
	try {
		if ( method_exists( '\\Simple_History\\Log_Initiators', 'get_initiator_label' ) ) {
			return (string) \Simple_History\Log_Initiators::get_initiator_label( $initiator );
		}
		if ( method_exists( '\\Simple_History\\Log_Initiators', 'get_initiator_text_from_row' ) ) {
			$text = \Simple_History\Log_Initiators::get_initiator_text_from_row( (object) array( 'initiator' => $initiator, 'context' => array() ) );
			return is_string( $text ) && '' !== $text ? $text : $initiator;
		}
	} catch ( \Throwable $e ) { /* fall through */ }
	return $initiator;
}

/**
 * Split the changes their JSON runs together back into one item each.
 *
 * Their post logger renders a post's field changes as one HTML block of
 * <dt>label</dt><dd>diff</dd> pairs, and their JSON describes that block by
 * its stripped text: "status Deleted: draftAdded: future", every change in
 * one value. An item whose value is exactly the stripped text of such a
 * block is replaced by that block's pairs, each with its old and new value;
 * anything else passes through untouched. Their own comment holds that the
 * pairs never nest and never occur inside a value. Values are plain text.
 *
 * @param array  $groups Their details_data.
 * @param string $html   Their details_html for the same event.
 * @return array
 */
function minn_admin_simple_history_split_details( array $groups, $html ) {
	$blocks = array();
	if ( preg_match_all( '#<dl class="SimpleHistoryLogitem__keyValueTable">(.*?)</dl>#s', (string) $html, $m ) ) {
		foreach ( $m[0] as $i => $whole ) {
			$blocks[ trim( wp_strip_all_tags( $whole ) ) ] = $m[1][ $i ];
		}
	}
	if ( ! $blocks ) {
		return $groups;
	}
	// One side of WordPress's diff table: the deleted or the added lines,
	// without the "Deleted:" / "Added:" screen-reader prefix.
	$side = function ( $dd, $kind ) {
		if ( ! preg_match_all( '#<td class=([\'"])diff-' . $kind . 'line\1>(.*?)</td>#s', $dd, $cells ) ) {
			return '';
		}
		$lines = array();
		foreach ( $cells[2] as $cell ) {
			$lines[] = trim( Minn_Admin::plain_text( preg_replace( '#<span[^>]*screen-reader-text[^>]*>.*?</span>#s', '', $cell ) ) );
		}
		return implode( "\n", $lines );
	};
	foreach ( $groups as $g => $group ) {
		if ( ! is_array( $group ) || empty( $group['items'] ) || ! is_array( $group['items'] ) ) {
			continue;
		}
		$items = array();
		foreach ( $group['items'] as $item ) {
			$flat = is_array( $item ) && isset( $item['new_value'] ) && is_string( $item['new_value'] ) && ! isset( $item['prev_value'] ) ? trim( $item['new_value'] ) : null;
			if ( null === $flat || ! isset( $blocks[ $flat ] ) || ! preg_match_all( '#<dt>(.*?)</dt>\s*<dd>(.*?)</dd>#s', $blocks[ $flat ], $pairs ) ) {
				$items[] = $item;
				continue;
			}
			foreach ( $pairs[1] as $i => $label ) {
				$prev = $side( $pairs[2][ $i ], 'deleted' );
				$new  = $side( $pairs[2][ $i ], 'added' );
				if ( '' === $prev && '' === $new ) {
					// Not a line diff (their content diff): its text as one value.
					$new = trim( Minn_Admin::plain_text( $pairs[2][ $i ] ) );
				}
				$items[] = array(
					'name'       => trim( Minn_Admin::plain_text( $label ) ),
					'prev_value' => $prev,
					'new_value'  => $new,
				);
			}
		}
		$groups[ $g ]['items'] = $items;
	}
	return $groups;
}

/*
 * Minn reads their events route directly, and their JSON leaves three things
 * raw that their own screen renders: who acted when no user did (the bare
 * initiator), how many times a grouped run happened (their "Show 7 similar
 * events"), and a post's changes (one run-together value). The surface asks
 * with minn_view=1, so only Minn's own reading of the list changes; their
 * screens and every other client get the response as they ship it. This runs
 * after their permission check and their query, on rows the caller was
 * already given.
 */
add_filter( 'rest_request_after_callbacks', function ( $response, $handler, $request ) {
	if ( ! $response instanceof WP_REST_Response || $response->is_error()
		|| ! $request instanceof WP_REST_Request
		|| 'GET' !== $request->get_method()
		|| '/simple-history/v1/events' !== $request->get_route()
		|| ! $request->get_param( 'minn_view' ) ) {
		return $response;
	}
	$events = $response->get_data();
	if ( ! is_array( $events ) ) {
		return $response;
	}
	foreach ( $events as $i => $event ) {
		if ( ! is_array( $event ) ) {
			continue;
		}
		if ( isset( $event['initiator'] ) && is_string( $event['initiator'] ) ) {
			$event['who'] = minn_admin_simple_history_initiator_label( $event['initiator'] );
		}
		$times = isset( $event['subsequent_occasions_count'] ) ? (int) $event['subsequent_occasions_count'] : 1;
		if ( $times > 1 && isset( $event['message'] ) && is_string( $event['message'] ) ) {
			$event['message'] = sprintf(
				/* translators: 1: an activity log event, 2: how many times it happened in a row. */
				_n( '%1$s · %2$s time', '%1$s · %2$s times', $times, 'minn-admin' ),
				$event['message'],
				number_format_i18n( $times )
			);
		}
		if ( ! empty( $event['details_data'] ) && is_array( $event['details_data'] ) && isset( $event['details_html'] ) && is_string( $event['details_html'] ) ) {
			$event['details_data'] = minn_admin_simple_history_split_details( $event['details_data'], $event['details_html'] );
		}
		$events[ $i ] = $event;
	}
	$response->set_data( $events );
	return $response;
}, 10, 3 );

minn_admin_add_surface( 'simple-history', function () {
	// Ask the same question the routes ask. Stealth mode hides the plugin by
	// removing its own pages, so registering the surface anyway put the name
	// back in the nav for exactly the people it is hidden from, which is what
	// the gate exists to prevent.
	return defined( 'SIMPLE_HISTORY_VERSION' ) && minn_admin_simple_history_can();
}, function () {
	return minn_admin_activity_surface( null, array(
		'sub'     => 'Simple History',
		'plugin'  => 'simple-history',
		'icon'    => 'clock',
		'cap'     => minn_admin_simple_history_view_cap(),
		'status'  => 'simple-history/status',
		'head'    => array(
			'route'     => 'simple-history/v1/events',
			// Asks for the readable rows (the rest_request_after_callbacks filter above).
			'query'     => 'minn_view=1',
			'pageQuery' => 'per_page=25&page={page}',
			// Their events route sorts by date|id|level|logger|message (5.34+;
			// older builds ignore the unregistered params and keep newest
			// first). Any order but date returns events ungrouped, since
			// occasion grouping depends on date order.
			'sortQuery' => 'orderby={by}&order={dir}',
			'search'    => 'search={q}',
			// Their events route takes date_from/date_to as timestamps OR as
			// datetime strings, which it parses in the site's zone; the bounds
			// a bar sends are site-local, so they ride through verbatim.
			'dateQuery' => 'date_from={from}&date_to={to}',
		),
		'tabs'    => array( 'loglevels', array( 'error' => 'Errors', 'warning' => 'Warnings', 'notice' => 'Notices', 'info' => 'Info' ), 'All' ),
		'event'   => array( 'sort' => 'message' ),
		'columns' => array(
			array( 'key' => 'initiator_data.user_login', 'altKey' => 'who', 'label' => __( 'Who', 'minn-admin' ) ),
			array( 'key' => 'loglevel', 'label' => __( 'Level', 'minn-admin' ), 'format' => 'pill', 'sort' => 'level' ),
		),
		// date_local is site-local (matches parseWpDate). date_gmt
		// without a zone suffix used to render "in 4h" on EDT.
		'when'    => array( 'key' => 'date_local', 'altKey' => 'date_gmt', 'sort' => 'date' ),
		'detail'  => array(
			'skip' => array(
				'message_html', 'message_uninterpolated', 'details_html', 'details_data',
				'context', 'ip_addresses', 'action_links', 'occasions_id', 'sticky',
				'sticky_appended', 'backfilled', 'ai_origin', 'via', 'link', 'permalink',
				'message_key', 'date_local', 'subsequent_occasions_count',
			),
		),
	) );
} );

minn_admin_endpoint( 'simple-history/status', array(
	'active' => function () {
		return defined( 'SIMPLE_HISTORY_VERSION' );
	},
	'gate'   => 'minn_admin_simple_history_can',
	'run'    => 'minn_admin_simple_history_status_model',
) );
