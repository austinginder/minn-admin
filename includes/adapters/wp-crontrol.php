<?php
/**
 * Bundled adapter: WP Crontrol (cron event manager).
 *
 * Surfaces WordPress cron events through WP Crontrol's own Event API
 * (Crontrol\Event\get / run / delete / pause / resume). Daily work lives
 * here: inventory, overdue filter, run-now, pause/resume, delete. Adding
 * PHP/URL cron jobs and editing schedules stays on Tools → Cron Events
 * (deep link) — those forms are canvases Minn does not reimplement.
 *
 * Caps mirror Crontrol: manage_options for the surface; per-event
 * runnable/deletable/pausable honor their UserContext + FeatureContext
 * (PHP cron run needs edit_files). PHP code bodies never leave their
 * args blob into Minn (get_args_display only).
 *
 * Complements Scrutoscope's Profiler → Cron view (attribution inventory)
 * and System's overdue-cron health row: this surface is the actionable
 * daily manager when WP Crontrol is installed.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/** Plugin loaded and Event API present. */
function minn_admin_crontrol_ready() {
	return function_exists( 'Crontrol\\Event\\get' )
		&& function_exists( 'Crontrol\\Event\\run' )
		&& function_exists( 'Crontrol\\Event\\delete' )
		&& function_exists( 'Crontrol\\Event\\pause' )
		&& function_exists( 'Crontrol\\Event\\resume' )
		&& function_exists( 'Crontrol\\Event\\find' );
}

function minn_admin_crontrol_can() {
	return current_user_can( 'manage_options' );
}

function minn_admin_crontrol_admin_url() {
	return admin_url( 'tools.php?page=wp-crontrol' );
}

/**
 * Stable, path-safe id for a cron event row.
 *
 * @param string $hook Hook name.
 * @param string $sig  Event signature (md5 of args).
 * @param int    $ts   UTC timestamp.
 */
function minn_admin_crontrol_id( $hook, $sig, $ts ) {
	$payload = wp_json_encode( array(
		'h' => (string) $hook,
		's' => (string) $sig,
		't' => (int) $ts,
	) );
	return rtrim( strtr( base64_encode( (string) $payload ), '+/', '-_' ), '=' );
}

/**
 * @param string $id Row id from minn_admin_crontrol_id().
 * @return array{h:string,s:string,t:int}|null
 */
function minn_admin_crontrol_parse_id( $id ) {
	$b64 = strtr( (string) $id, '-_', '+/' );
	$pad = strlen( $b64 ) % 4;
	if ( $pad ) {
		$b64 .= str_repeat( '=', 4 - $pad );
	}
	$raw  = base64_decode( $b64, true );
	$data = is_string( $raw ) ? json_decode( $raw, true ) : null;
	if ( ! is_array( $data ) || ! isset( $data['h'], $data['s'], $data['t'] ) ) {
		return null;
	}
	return array(
		'h' => (string) $data['h'],
		's' => (string) $data['s'],
		't' => (int) $data['t'],
	);
}

/** @return \Crontrol\Context\WordPressUserContext */
function minn_admin_crontrol_user_ctx() {
	return new \Crontrol\Context\WordPressUserContext();
}

/** @return \Crontrol\Context\WordPressFeatureContext */
function minn_admin_crontrol_feature_ctx() {
	return new \Crontrol\Context\WordPressFeatureContext();
}

/**
 * Human schedule label via their Event API (Unknown → raw slug).
 *
 * @param \Crontrol\Event\Event $ev Event.
 */
function minn_admin_crontrol_schedule_label( $ev ) {
	if ( ! $ev->is_recurring() ) {
		return 'Once';
	}
	try {
		return (string) $ev->get_schedule_name();
	} catch ( \Throwable $e ) {
		return (string) ( $ev->schedule ?: '—' );
	}
}

/**
 * Status pill: overdue | paused | immediate | scheduled.
 *
 * @param \Crontrol\Event\Event $ev Event.
 */
function minn_admin_crontrol_status( $ev ) {
	if ( $ev->is_paused() ) {
		return 'paused';
	}
	if ( method_exists( $ev, 'is_immediate' ) && $ev->is_immediate() ) {
		return 'immediate';
	}
	// is_late = more than 10 minutes past — also treat any past-due as overdue.
	if ( $ev->is_late() || $ev->timestamp < time() ) {
		return 'overdue';
	}
	return 'scheduled';
}

/**
 * Display row for one Event (never includes PHP source).
 *
 * @param \Crontrol\Event\Event $ev Event.
 * @return array
 */
function minn_admin_crontrol_row( $ev ) {
	$user     = minn_admin_crontrol_user_ctx();
	$features = minn_admin_crontrol_feature_ctx();
	$status   = minn_admin_crontrol_status( $ev );
	$args_out = '';
	try {
		$args_out = (string) $ev->get_args_display();
	} catch ( \Throwable $e ) {
		$args_out = '';
	}
	// Cap long args; never expand PHP code (get_args_display already says "PHP Code").
	if ( strlen( $args_out ) > 200 ) {
		$args_out = substr( $args_out, 0, 200 ) . '…';
	}

	return array(
		'id'         => minn_admin_crontrol_id( $ev->hook, $ev->sig, $ev->timestamp ),
		'hook'       => (string) $ev->hook,
		'schedule'   => minn_admin_crontrol_schedule_label( $ev ),
		'interval'   => $ev->interval ? human_time_diff( 0, (int) $ev->interval ) : '—',
		'status'     => $status,
		'date'       => gmdate( 'Y-m-d\TH:i:s\Z', (int) $ev->timestamp ),
		'args'       => $args_out,
		'recurring'  => $ev->is_recurring(),
		'paused'     => $ev->is_paused(),
		'can_run'    => $ev->runnable( $user, $features ) && ! $ev->is_paused() && 'immediate' !== $status,
		'can_delete' => $ev->deletable( $user, $features ) && ! $ev->persistent(),
		'can_pause'  => $ev->pausable() && ! $ev->is_paused(),
		'can_resume' => $ev->pausable() && $ev->is_paused(),
	);
}

/**
 * @return array{items: array, total: int}
 */
function minn_admin_crontrol_list( WP_REST_Request $request ) {
	if ( ! minn_admin_crontrol_ready() ) {
		return array( 'items' => array(), 'total' => 0 );
	}

	$events = \Crontrol\Event\get();
	$kind   = (string) $request->get_param( 'kind' );
	$search = (string) $request->get_param( 'search' );

	if ( $search ) {
		$events = \Crontrol\Event\filter_by_search( $events, $search );
	}

	$rows = array();
	foreach ( $events as $ev ) {
		// Skip phantom "run immediately" placeholders unless they stuck.
		if ( method_exists( $ev, 'is_immediate' ) && $ev->is_immediate() && 'immediate' !== $kind ) {
			// Still show under All if stuck (timestamp 1 = problem state).
			if ( ! $kind ) {
				// keep
			}
		}
		$row = minn_admin_crontrol_row( $ev );
		if ( 'overdue' === $kind && 'overdue' !== $row['status'] ) {
			continue;
		}
		if ( 'paused' === $kind && ! $row['paused'] ) {
			continue;
		}
		if ( 'recurring' === $kind && ! $row['recurring'] ) {
			continue;
		}
		if ( 'once' === $kind && $row['recurring'] ) {
			continue;
		}
		$rows[] = $row;
	}

	// Overdue first, then soonest next run.
	usort( $rows, function ( $a, $b ) {
		$rank = array( 'overdue' => 0, 'immediate' => 1, 'paused' => 2, 'scheduled' => 3 );
		$ra   = isset( $rank[ $a['status'] ] ) ? $rank[ $a['status'] ] : 9;
		$rb   = isset( $rank[ $b['status'] ] ) ? $rank[ $b['status'] ] : 9;
		if ( $ra !== $rb ) {
			return $ra - $rb;
		}
		return strcmp( $a['date'], $b['date'] );
	} );

	$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 50 ) );
	$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
	$total    = count( $rows );
	$items    = array_slice( $rows, ( $page - 1 ) * $per_page, $per_page );

	return array( 'items' => $items, 'total' => $total );
}

/**
 * Detail sections for one event.
 *
 * @param string $id Row id.
 * @return array|WP_Error
 */
function minn_admin_crontrol_detail( $id ) {
	$parts = minn_admin_crontrol_parse_id( $id );
	if ( ! $parts ) {
		return new WP_Error( 'bad_id', 'Invalid event id.', array( 'status' => 400 ) );
	}
	$ev = \Crontrol\Event\find( $parts['h'], $parts['t'], $parts['s'] );
	if ( ! $ev ) {
		return new WP_Error( 'not_found', 'Cron event not found.', array( 'status' => 404 ) );
	}

	$row     = minn_admin_crontrol_row( $ev );
	$meta    = array(
		array( 'label' => 'Hook', 'value' => $ev->hook ),
		array( 'label' => 'Next run (UTC)', 'value' => $ev->get_next_run_utc( 'Y-m-d H:i:s' ) . ' UTC' ),
		array( 'label' => 'Next run (site)', 'value' => $ev->get_next_run_local( 'Y-m-d H:i:s' ) ),
		array( 'label' => 'Schedule', 'value' => $row['schedule'] ),
		array( 'label' => 'Interval', 'value' => $row['interval'] ),
		array( 'label' => 'Status', 'value' => $row['status'] ),
		array( 'label' => 'Arguments', 'value' => $row['args'] ?: '—' ),
		array( 'label' => 'Signature', 'value' => $ev->sig ),
	);

	$cb_rows = array();
	try {
		foreach ( array_slice( $ev->get_callbacks(), 0, 20 ) as $cb ) {
			$fn = '';
			if ( isset( $cb['callback']['name'] ) ) {
				$fn = (string) $cb['callback']['name'];
			} elseif ( isset( $cb['callback']['function'] ) ) {
				$fn = is_string( $cb['callback']['function'] )
					? $cb['callback']['function']
					: 'callable';
			}
			$pri = isset( $cb['priority'] ) ? (int) $cb['priority'] : 10;
			$cb_rows[] = array(
				'label' => 'Priority ' . $pri,
				'value' => $fn ?: '—',
			);
		}
	} catch ( \Throwable $e ) {
		// Callbacks are informational only.
	}

	$sections = array_values( array_filter( array(
		array( 'title' => 'Event', 'rows' => array_values( array_filter( $meta, function ( $r ) {
			return '' !== (string) $r['value'];
		} ) ) ),
		$cb_rows ? array( 'title' => 'Callbacks', 'rows' => $cb_rows ) : null,
	) ) );

	return array(
		'title'    => $ev->hook,
		'status'   => $row['status'],
		'sections' => $sections,
		'adminUrl' => minn_admin_crontrol_admin_url(),
	);
}

function minn_admin_crontrol_status_model() {
	if ( ! minn_admin_crontrol_ready() ) {
		return array(
			'rows'    => array( array( 'label' => 'WP Crontrol', 'value' => 'Unavailable' ) ),
			'actions' => array(),
		);
	}

	$events  = \Crontrol\Event\get();
	$total   = count( $events );
	$overdue = 0;
	$paused  = 0;
	$once    = 0;
	foreach ( $events as $ev ) {
		if ( $ev->is_paused() ) {
			$paused++;
		}
		if ( ! $ev->is_recurring() ) {
			$once++;
		}
		if ( ! $ev->is_paused() && ( $ev->is_late() || $ev->timestamp < time() ) ) {
			$overdue++;
		}
	}

	$spawn = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON
		? 'Disabled (DISABLE_WP_CRON)'
		: 'Enabled';

	// Namespaced const from wp-crontrol.php.
	$ver = '—';
	if ( defined( 'Crontrol\\WP_CRONTROL_VERSION' ) ) {
		$ver = constant( 'Crontrol\\WP_CRONTROL_VERSION' );
	}

	return array(
		'rows'    => array(
			array(
				'label' => 'Events',
				'value' => number_format_i18n( $total ),
				'hint'  => $once ? ( number_format_i18n( $once ) . ' one-off' ) : '',
			),
			array(
				'label' => 'Overdue',
				'value' => number_format_i18n( $overdue ),
				'hint'  => $overdue ? 'Past next-run time' : 'None past due',
			),
			array(
				'label' => 'Paused',
				'value' => number_format_i18n( $paused ),
				'hint'  => 'Hook-level pause via WP Crontrol',
			),
			array(
				'label' => 'WP-Cron spawn',
				'value' => $spawn,
				'hint'  => 'Site uses page-load spawn unless disabled',
			),
			array(
				'label' => 'WP Crontrol',
				'value' => is_string( $ver ) ? $ver : '—',
			),
		),
		'actions' => array(
			array(
				'label' => 'Open WP Crontrol ↗',
				'href'  => minn_admin_crontrol_admin_url(),
			),
			array(
				'label' => 'Add event ↗',
				'href'  => admin_url( 'tools.php?page=wp-crontrol&crontrol_action=new-cron' ),
			),
		),
	);
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_crontrol_ready() || ! minn_admin_crontrol_can() ) {
		return $surfaces;
	}

	$surfaces['wp-crontrol'] = array(
		'label'      => 'Cron',
		'sub'        => 'WP Crontrol',
		'icon'       => 'activity',
		'cap'        => 'manage_options',
		'group'      => 'tools',
		'status'     => array( 'route' => 'minn-admin/v1/crontrol/status' ),
		'collection' => array(
			'viewLabel' => 'Events',
			'route'     => 'minn-admin/v1/crontrol/events',
			'pageQuery' => 'per_page=50&page={page}',
			'search'    => 'search={q}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'tabs'      => array(
				'param'    => 'kind',
				'static'   => array(
					array( 'overdue', 'Overdue' ),
					array( 'paused', 'Paused' ),
					array( 'recurring', 'Recurring' ),
					array( 'once', 'One-off' ),
				),
				'allLabel' => 'All events',
			),
			'columns'   => array(
				array( 'key' => 'hook', 'label' => 'Hook', 'format' => 'title' ),
				array( 'key' => 'schedule', 'label' => 'Schedule', 'format' => 'text' ),
				array( 'key' => 'interval', 'label' => 'Every', 'format' => 'text' ),
				array( 'key' => 'status', 'label' => 'Status', 'format' => 'pill' ),
				array( 'key' => 'date', 'label' => 'Next run', 'format' => 'ago', 'utc' => true ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/crontrol/events/{id}',
			),
			'actions'   => array(
				array(
					'label'  => 'Run now',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/crontrol/events/{id}/run',
					'when'   => array( 'key' => 'can_run', 'equals' => true ),
				),
				array(
					'label'  => 'Pause hook',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/crontrol/events/{id}/pause',
					'when'   => array( 'key' => 'can_pause', 'equals' => true ),
				),
				array(
					'label'  => 'Resume hook',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/crontrol/events/{id}/resume',
					'when'   => array( 'key' => 'can_resume', 'equals' => true ),
				),
				array(
					'label'   => 'Delete event',
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/crontrol/events/{id}',
					'confirm' => 'Delete this scheduled cron event? Recurring hooks can be re-added by their plugin.',
					'danger'  => true,
					'when'    => array( 'key' => 'can_delete', 'equals' => true ),
				),
				array(
					'label' => 'Open WP Crontrol ↗',
					'href'  => minn_admin_crontrol_admin_url(),
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_crontrol_ready() ) {
		return;
	}

	$perm = 'minn_admin_crontrol_can';

	register_rest_route( 'minn-admin/v1', '/crontrol/events', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			return rest_ensure_response( minn_admin_crontrol_list( $request ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/crontrol/events/(?P<id>[A-Za-z0-9_-]+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$out = minn_admin_crontrol_detail( (string) $request['id'] );
				return is_wp_error( $out ) ? $out : rest_ensure_response( $out );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$parts = minn_admin_crontrol_parse_id( (string) $request['id'] );
				if ( ! $parts ) {
					return new WP_Error( 'bad_id', 'Invalid event id.', array( 'status' => 400 ) );
				}
				$ev = \Crontrol\Event\find( $parts['h'], $parts['t'], $parts['s'] );
				if ( ! $ev ) {
					return new WP_Error( 'not_found', 'Cron event not found.', array( 'status' => 404 ) );
				}
				$user     = minn_admin_crontrol_user_ctx();
				$features = minn_admin_crontrol_feature_ctx();
				if ( ! $ev->deletable( $user, $features ) || $ev->persistent() ) {
					return new WP_Error( 'forbidden', 'This event cannot be deleted.', array( 'status' => 403 ) );
				}
				$result = \Crontrol\Event\delete( $parts['h'], $parts['s'], (string) $parts['t'] );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				return rest_ensure_response( array(
					'ok'      => true,
					'message' => 'Event deleted.',
				) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/crontrol/events/(?P<id>[A-Za-z0-9_-]+)/run', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$parts = minn_admin_crontrol_parse_id( (string) $request['id'] );
			if ( ! $parts ) {
				return new WP_Error( 'bad_id', 'Invalid event id.', array( 'status' => 400 ) );
			}
			$ev = \Crontrol\Event\find( $parts['h'], $parts['t'], $parts['s'] );
			if ( ! $ev ) {
				return new WP_Error( 'not_found', 'Cron event not found.', array( 'status' => 404 ) );
			}
			$user     = minn_admin_crontrol_user_ctx();
			$features = minn_admin_crontrol_feature_ctx();
			if ( ! $ev->runnable( $user, $features ) || $ev->is_paused() ) {
				return new WP_Error( 'forbidden', 'This event cannot be run now.', array( 'status' => 403 ) );
			}
			// Their run() schedules an immediate spawn and sleeps 1s.
			$result = \Crontrol\Event\run( $parts['h'], $parts['s'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return rest_ensure_response( array(
				'ok'      => true,
				'message' => 'Run scheduled — WP-Cron was spawned to execute it.',
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/crontrol/events/(?P<id>[A-Za-z0-9_-]+)/pause', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$parts = minn_admin_crontrol_parse_id( (string) $request['id'] );
			if ( ! $parts ) {
				return new WP_Error( 'bad_id', 'Invalid event id.', array( 'status' => 400 ) );
			}
			$ev = \Crontrol\Event\find( $parts['h'], $parts['t'], $parts['s'] );
			if ( ! $ev ) {
				return new WP_Error( 'not_found', 'Cron event not found.', array( 'status' => 404 ) );
			}
			if ( ! $ev->pausable() ) {
				return new WP_Error( 'forbidden', 'This hook cannot be paused.', array( 'status' => 403 ) );
			}
			$result = \Crontrol\Event\pause( $parts['h'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return rest_ensure_response( array(
				'ok'      => true,
				'message' => 'Hook paused — all events on this hook are skipped.',
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/crontrol/events/(?P<id>[A-Za-z0-9_-]+)/resume', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$parts = minn_admin_crontrol_parse_id( (string) $request['id'] );
			if ( ! $parts ) {
				return new WP_Error( 'bad_id', 'Invalid event id.', array( 'status' => 400 ) );
			}
			$result = \Crontrol\Event\resume( $parts['h'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			return rest_ensure_response( array(
				'ok'      => true,
				'message' => 'Hook resumed.',
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/crontrol/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			return rest_ensure_response( minn_admin_crontrol_status_model() );
		},
	) );
} );
