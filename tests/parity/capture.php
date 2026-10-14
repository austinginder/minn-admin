<?php
/**
 * Parity capture, one account: what every minn-admin/v1 route answers.
 *
 * tests/parity/capture.js runs this once per kind of account and merges the
 * parts; its header describes the whole tool. This part records, for the
 * account WP-CLI runs as (--user, or nobody):
 *
 * - every minn-admin/v1 endpoint: its methods, declared args, the file its
 *   callback lives in, and whether its permission callback admits this
 *   account (asked with no path parameters, as route-gates does);
 * - the surfaces and editor panels this account is sent, by value;
 * - the shape of each GET it is admitted to: every JSON path with the types
 *   and string formats seen there, never a value. Requests come from the
 *   descriptors the way the app makes them (the page query, each filter
 *   option, the first tab, a search, the first item's detail) and from a walk
 *   of every GET route, its path ids taken from the parent route's list;
 * - with MINN_PARITY_WRITES, each row and bulk action of the named surfaces,
 *   run on the first item its `when` matches.
 *
 * Every request runs inside a transaction that is rolled back, with outbound
 * HTTP and mail refused, so a GET that marks an entry viewed or an action
 * that trashes one leaves the lab as it was. A statement that would end the
 * transaction or write what cannot roll back (DDL, LOCK, a non-InnoDB table)
 * is refused and noted on its request. Files a route writes or deletes are
 * outside the transaction, which is why actions only run for named surfaces.
 *
 * Environment (the driver sets these):
 *   MINN_PARITY_OUT     the JSON file to write (required)
 *   MINN_PARITY_AS      this account's label in the merged capture
 *   MINN_PARITY_ONLY    comma list: probe only routes whose callback file, or
 *                       surfaces whose id, contains one of these
 *   MINN_PARITY_WRITES  comma list of surface ids (or callback file parts)
 *                       whose actions run
 *   MINN_PARITY_SKIP    newline list of probe keys not to run (one that
 *                       killed an earlier attempt)
 *
 * @package minn-admin
 */

set_time_limit( 0 );

$minn_parity_out = (string) getenv( 'MINN_PARITY_OUT' );
if ( '' === $minn_parity_out ) {
	fwrite( STDERR, "MINN_PARITY_OUT is not set; run tests/parity/capture.js\n" );
	exit( 2 );
}
$minn_parity_list = function ( $name, $sep = ',' ) {
	return array_values( array_filter( array_map( 'trim', explode( $sep, (string) getenv( $name ) ) ), 'strlen' ) );
};
$as_label  = (string) ( getenv( 'MINN_PARITY_AS' ) ?: ( get_current_user_id() ? 'user-' . get_current_user_id() : 'signed_out' ) );
$only      = $minn_parity_list( 'MINN_PARITY_ONLY' );
$writes    = $minn_parity_list( 'MINN_PARITY_WRITES' );
$skip      = array_flip( $minn_parity_list( 'MINN_PARITY_SKIP', "\n" ) );
$inflight  = $minn_parity_out . '.inflight';
$plugin    = trailingslashit( wp_normalize_path( dirname( __DIR__, 2 ) ) );
$wp_root   = trailingslashit( wp_normalize_path( ABSPATH ) );

// Nothing leaves the machine and nothing is mailed, whatever a route tries.
add_filter( 'pre_http_request', function () {
	return new WP_Error( 'minn_parity_offline', 'Outbound HTTP is off during a parity capture.' );
}, PHP_INT_MAX );
add_filter( 'pre_wp_mail', '__return_false', PHP_INT_MAX );

// The transaction guard. While a request runs, a statement that would commit
// the open transaction (DDL, LOCK, a transaction statement of the route's own)
// or write a table that cannot roll back is replaced with nothing, which
// wpdb answers as a failed query.
global $wpdb;
$guard       = array( 'on' => false, 'notes' => array() );
$soft_tables = (array) $wpdb->get_col( "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND ENGINE IS NOT NULL AND ENGINE <> 'InnoDB'" );
add_filter( 'query', function ( $q ) use ( &$guard, $soft_tables ) {
	if ( ! $guard['on'] ) {
		return $q;
	}
	$head = preg_replace( '#^\s*(/\*.*?\*/\s*)*#s', '', (string) $q );
	$why  = '';
	if ( preg_match( '/^(CREATE\s+(?!TEMPORARY\b)|ALTER\b|DROP\s+(?!TEMPORARY\b)|TRUNCATE\b|RENAME\b|LOCK\b|UNLOCK\b|GRANT\b|REVOKE\b|COMMIT\b|ROLLBACK\b|START\s+TRANSACTION\b|BEGIN\b|SAVEPOINT\b|RELEASE\b|SET\s+(SESSION\s+|@@)?autocommit\b|OPTIMIZE\b|REPAIR\b|ANALYZE\b|FLUSH\b|LOAD\s+DATA\b|XA\b)/i', $head, $m ) ) {
		$why = strtoupper( preg_replace( '/\s+/', ' ', trim( $m[1] ) ) );
	} elseif ( $soft_tables && preg_match( '/^(INSERT|REPLACE|UPDATE|DELETE)\b/i', $head, $m ) ) {
		foreach ( $soft_tables as $t ) {
			if ( preg_match( '/\b' . preg_quote( $t, '/' ) . '\b/i', $head ) ) {
				$why = strtoupper( $m[1] ) . ' on non-transactional ' . $t;
				break;
			}
		}
	}
	if ( '' === $why ) {
		return $q;
	}
	$guard['notes'][ 'refused ' . $why ] = true;
	return '';
}, PHP_INT_MAX );

// The routes, once: key "<METHODS> <route>" like route-gates.
$server = rest_get_server();
$routes = $server->get_routes();

$file_of = function ( $cb, $known = '' ) use ( $plugin, $wp_root ) {
	if ( '' !== $known ) {
		return $known;
	}
	try {
		if ( $cb instanceof Closure || ( is_string( $cb ) && false === strpos( $cb, '::' ) && function_exists( $cb ) ) ) {
			$ref = new ReflectionFunction( $cb );
		} elseif ( is_array( $cb ) && 2 === count( $cb ) ) {
			$ref = new ReflectionMethod( is_object( $cb[0] ) ? get_class( $cb[0] ) : $cb[0], $cb[1] );
		} elseif ( is_string( $cb ) && false !== strpos( $cb, '::' ) ) {
			$ref = new ReflectionMethod( $cb );
		} elseif ( is_object( $cb ) && method_exists( $cb, '__invoke' ) ) {
			$ref = new ReflectionMethod( $cb, '__invoke' );
		} else {
			return '';
		}
		$file = wp_normalize_path( (string) $ref->getFileName() );
	} catch ( \Throwable $e ) {
		return '';
	}
	if ( 0 === strpos( $file, $plugin ) ) {
		return substr( $file, strlen( $plugin ) );
	}
	return 0 === strpos( $file, $wp_root ) ? substr( $file, strlen( $wp_root ) ) : basename( $file );
};

$args_of = function ( $args ) {
	$out = array();
	foreach ( (array) $args as $name => $a ) {
		if ( ! is_array( $a ) ) {
			continue;
		}
		$row = array();
		foreach ( array( 'type', 'required', 'enum', 'default', 'format', 'minimum', 'maximum' ) as $k ) {
			if ( array_key_exists( $k, $a ) && ! ( $a[ $k ] instanceof Closure ) ) {
				$row[ $k ] = $a[ $k ];
			}
		}
		if ( isset( $a['items']['type'] ) ) {
			$row['items'] = $a['items']['type'];
		}
		$out[ $name ] = $row;
	}
	ksort( $out );
	return $out;
};

$endpoints = array();
$by_route  = array(); // route => [ method => file ]
foreach ( $routes as $route => $handlers ) {
	if ( 0 !== strpos( $route, '/minn-admin/v1' ) ) {
		continue;
	}
	foreach ( (array) $handlers as $ep ) {
		if ( ! is_array( $ep ) || empty( $ep['methods'] ) ) {
			continue;
		}
		$methods = array_keys( array_filter( (array) $ep['methods'] ) );
		sort( $methods );
		$key  = implode( '|', $methods ) . ' ' . $route;
		// A kernel route names the adapter that declared it.
		$file = ! empty( $ep['minn_file'] ) ? $file_of( null, (string) $ep['minn_file'] ) : $file_of( $ep['callback'] ?? null );
		$cb   = $ep['permission_callback'] ?? null;
		$gate = true; // core serves a missing callback publicly, with a notice
		if ( null !== $cb ) {
			try {
				$gate = true === call_user_func( $cb, new WP_REST_Request( $methods[0], $route ) );
			} catch ( \Throwable $e ) {
				$gate = false;
			}
		}
		$endpoints[ $key ] = array(
			'route'   => $route,
			'methods' => $methods,
			'file'    => $file,
			'gate'    => $gate,
			'args'    => $args_of( $ep['args'] ?? array() ),
		);
		foreach ( $methods as $m ) {
			$by_route[ $route ][ $m ] = array( 'file' => $file, 'gate' => $gate );
		}
	}
}
ksort( $endpoints );

// The registered route a concrete path would match, or null.
$match = function ( $method, $path ) use ( $by_route ) {
	foreach ( $by_route as $route => $methods ) {
		if ( isset( $methods[ $method ] ) && preg_match( '@^' . $route . '$@i', $path ) ) {
			return array( $route, $methods[ $method ] );
		}
	}
	return null;
};
$wanted = function ( $file, $surface = '' ) use ( $only ) {
	if ( ! $only ) {
		return true;
	}
	foreach ( $only as $o ) {
		if ( ( '' !== $file && false !== strpos( $file, $o ) ) || ( '' !== $surface && false !== strpos( $surface, $o ) ) ) {
			return true;
		}
	}
	return false;
};

// A string's format, never its value.
$format = function ( $s ) {
	if ( '' === $s ) {
		return 'empty';
	}
	if ( preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d(:\d\d(\.\d+)?)?(Z|[+-]\d\d:?\d\d)$/', $s ) ) {
		return 'iso-tz';
	}
	if ( preg_match( '/^\d{4}-\d\d-\d\dT\d\d:\d\d(:\d\d(\.\d+)?)?$/', $s ) ) {
		return 'iso-local';
	}
	if ( preg_match( '/^\d{4}-\d\d-\d\d \d\d:\d\d(:\d\d)?$/', $s ) ) {
		return 'sql-datetime';
	}
	if ( preg_match( '/^\d{4}-\d\d-\d\d$/', $s ) ) {
		return 'date';
	}
	if ( preg_match( '/^-?\d+(\.\d+)?$/', $s ) ) {
		return 'numeric';
	}
	if ( preg_match( '#^https?://#i', $s ) ) {
		return 'url';
	}
	if ( preg_match( '#<[a-z!/][^>]*>#i', $s ) ) {
		return 'html';
	}
	if ( preg_match( '/&(#\d+|#x[0-9a-f]+|[a-z][a-z0-9]*);/i', $s ) ) {
		return 'entities';
	}
	return 'text';
};

// Flatten decoded JSON (objects as stdClass) into path => "type|type".
// Lists merge their elements under "[]"; an object whose keys are all
// numbers is a map, merged under "<n>"; a key that some instances of its
// object lack is marked "absent".
$shape = function ( $value ) use ( $format ) {
	$paths = array();
	$inst  = array();
	$seen  = array();
	$walk  = function ( $v, $p ) use ( &$walk, &$paths, &$inst, &$seen, $format ) {
		if ( null === $v ) {
			$t = 'null';
		} elseif ( is_bool( $v ) ) {
			$t = 'bool';
		} elseif ( is_int( $v ) ) {
			$t = 'int';
		} elseif ( is_float( $v ) ) {
			$t = 'float';
		} elseif ( is_string( $v ) ) {
			$t = 'string:' . $format( $v );
		} elseif ( is_array( $v ) ) {
			$t = $v ? 'list' : 'list:empty';
			foreach ( $v as $e ) {
				$walk( $e, $p . '[]' );
			}
		} else {
			$vars = get_object_vars( $v );
			$nums = count( array_filter( array_keys( $vars ), 'is_numeric' ) );
			if ( $vars && count( $vars ) === $nums ) {
				$t = 'map';
				foreach ( $vars as $e ) {
					$walk( $e, $p . '.<n>' );
				}
			} else {
				$t          = $vars ? 'object' : 'object:empty';
				$inst[ $p ] = ( $inst[ $p ] ?? 0 ) + 1;
				foreach ( $vars as $k => $e ) {
					// This run's own role, named for its run, is one key in every capture.
					$k           = preg_replace( '/^minn_parity_netrole_[a-z0-9]+$/', 'minn_parity_netrole', (string) $k );
					$cp          = $p . '.' . str_replace( array( '.', '[' ), array( '%2E', '%5B' ), $k );
					$seen[ $cp ] = ( $seen[ $cp ] ?? 0 ) + 1;
					$walk( $e, $cp );
				}
			}
		}
		$paths[ $p ][ $t ] = true;
	};
	$walk( $value, '$' );
	foreach ( $seen as $cp => $n ) {
		$parent = substr( $cp, 0, strrpos( $cp, '.' ) );
		if ( $n < ( $inst[ $parent ] ?? 0 ) ) {
			$paths[ $cp ]['absent'] = true;
		}
	}
	ksort( $paths );
	foreach ( $paths as $p => $types ) {
		$types = array_keys( $types );
		sort( $types );
		$paths[ $p ] = implode( '|', $types );
	}
	return $paths;
};

// One request, as the app makes it. $tx runs callables inside one
// transaction that is rolled back; $dispatch is one request inside one.
$call = function ( $method, $path, $query = array(), $body = null ) use ( &$guard, $inflight, $server ) {
	file_put_contents( $inflight, $method . ' ' . $path . ( $query ? '?' . http_build_query( $query ) : '' ) );
	$start = microtime( true );
	$data  = null;
	$threw = '';
	$route = '';
	$was   = $guard['notes'];
	ob_start();
	try {
		$req = new WP_REST_Request( $method, $path );
		if ( $query ) {
			$req->set_query_params( $query );
		}
		if ( null !== $body ) {
			$req->set_header( 'content-type', 'application/json' );
			$req->set_body( wp_json_encode( $body ) );
		}
		// What serve_request() does around dispatch, so response filters
		// (a plugin's, or a fence of Minn's) shape what is recorded.
		$res    = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_do_request( $req ) ), $server, $req );
		$status = $res->get_status();
		$data   = apply_filters( 'rest_pre_echo_response', $server->response_to_data( $res, false ), $server, $req );
		$route  = $res->get_matched_route();
	} catch ( \Throwable $e ) {
		$threw  = get_class( $e ) . ': ' . $e->getMessage();
		$status = 'threw';
	}
	$echoed = (string) ob_get_clean();
	$json   = wp_json_encode( $data );
	return array(
		'status' => $status,
		'data'   => false === $json ? null : json_decode( $json ),
		'json'   => false !== $json,
		'route'  => $route,
		'ms'     => (int) round( ( microtime( true ) - $start ) * 1000 ),
		'notes'  => array_keys( array_diff_key( $guard['notes'], $was ) ),
		'echoed' => strlen( $echoed ),
		'threw'  => $threw,
	);
};
$tx = function ( callable $fn ) use ( &$guard ) {
	global $wpdb;
	$wpdb->query( 'START TRANSACTION' );
	$guard['notes'] = array();
	$guard['on']    = true;
	try {
		return $fn();
	} finally {
		$guard['on'] = false;
		$wpdb->query( 'ROLLBACK' );
		wp_cache_flush();
	}
};
$dispatch = function ( $method, $path, $query = array(), $body = null ) use ( $tx, $call ) {
	return $tx( function () use ( $call, $method, $path, $query, $body ) {
		return $call( $method, $path, $query, $body );
	} );
};

$responses = array();
$fetched   = array(); // concrete request => dispatch result, so two probe keys share one call
$skipped   = array();
// Record one probe under its key. $result is a request already made (one
// run after a setup step, inside that step's transaction).
$record    = function ( $key, $method, $path, $query, $via, $result = null, $body = null ) use ( &$responses, &$fetched, &$skipped, $skip, $dispatch, $match, $shape ) {
	ksort( $query );
	$concrete = $method . ' ' . $path . ( $query ? '?' . http_build_query( $query ) : '' ) . ( null !== $body ? ' ' . wp_json_encode( $body ) : '' );
	if ( null === $result && ( isset( $skip[ $key ] ) || isset( $skip[ $concrete ] ) ) ) {
		$skipped[] = $key;
		return null;
	}
	if ( isset( $responses[ $key ] ) ) {
		$responses[ $key ]['via'] = array_values( array_unique( array_merge( $responses[ $key ]['via'], array( $via ) ) ) );
		return $fetched[ $concrete ] ?? null;
	}
	if ( null !== $result ) {
		$r = $result;
	} else {
		if ( ! isset( $fetched[ $concrete ] ) ) {
			$fetched[ $concrete ] = $dispatch( $method, $path, $query, $body );
		}
		$r = $fetched[ $concrete ];
	}
	$m     = $match( $method, $path );
	$entry = array(
		'status' => $r['status'],
		'route'  => $r['route'] ?: ( $m ? $m[0] : '' ),
		'file'   => $m ? $m[1]['file'] : '',
		'via'    => array( $via ),
		'ms'     => $r['ms'],
	);
	if ( ! $r['json'] ) {
		$entry['unencodable'] = true;
	} elseif ( is_int( $r['status'] ) && $r['status'] >= 400 && is_object( $r['data'] ) && isset( $r['data']->code ) ) {
		// An error answers with its code; its message is prose and its data varies.
		$entry['error'] = (string) $r['data']->code;
	} else {
		$entry['shape'] = $shape( $r['data'] );
	}
	foreach ( array( 'notes', 'threw' ) as $k ) {
		if ( $r[ $k ] ) {
			$entry[ $k ] = $r[ $k ];
		}
	}
	if ( $r['echoed'] ) {
		$entry['echoed'] = $r['echoed'];
	}
	$responses[ $key ] = $entry;
	return $r;
};

// "minn-admin/v1/x?a=1" (descriptor form) → [ "/minn-admin/v1/x", [ a => 1 ] ].
$split = function ( $route ) {
	$route = '/' . ltrim( (string) $route, '/' );
	$query = array();
	$q     = strpos( $route, '?' );
	if ( false !== $q ) {
		parse_str( substr( $route, $q + 1 ), $query );
		$route = substr( $route, 0, $q );
	}
	return array( $route, $query );
};
$is_api = function ( $s ) {
	return is_string( $s ) && preg_match( '#^/?[a-z0-9][a-z0-9_-]*/v\d+[a-z0-9]*(/|\?|$)#i', $s );
};
// A list's rows from its response: the itemsKey, the data itself, or the
// first list of objects under it.
$rows_of = function ( $data, $items_key = '' ) {
	if ( '' !== $items_key && is_object( $data ) ) {
		$v = $data;
		foreach ( explode( '.', $items_key ) as $k ) {
			$v = is_object( $v ) && isset( $v->$k ) ? $v->$k : null;
		}
		return is_array( $v ) ? $v : array();
	}
	if ( is_array( $data ) ) {
		return $data;
	}
	if ( is_object( $data ) ) {
		foreach ( get_object_vars( $data ) as $v ) {
			if ( is_array( $v ) && $v && is_object( $v[0] ) ) {
				return $v;
			}
		}
	}
	return array();
};
$value_at = function ( $item, $key ) {
	foreach ( explode( '.', (string) $key ) as $k ) {
		$item = is_object( $item ) && isset( $item->$k ) ? $item->$k : null;
	}
	return $item;
};
// Placeholders take the item's raw value: the app encodes it, and the server
// matches routes on the decoded path, which is what this hands it.
$fill = function ( $tpl, $item ) {
	return preg_replace_callback( '/\{(\w+)\}/', function ( $m ) use ( $item ) {
		$v = is_object( $item ) && isset( $item->{$m[1]} ) ? $item->{$m[1]} : '';
		return is_scalar( $v ) ? (string) $v : '';
	}, $tpl );
};

// The surfaces this account is sent, and the requests they make.
// Descriptor values minus what differs per run: nonces, and this run's own
// account (created fresh, with a random suffix).
$me    = wp_get_current_user();
$stamp = function ( $v ) use ( &$stamp, $me ) {
	if ( is_string( $v ) ) {
		$v = preg_replace( '/((?:_wpnonce|nonce)=)[0-9a-f]{10}\b/i', '$1<nonce>', $v );
		$v = preg_replace( '/minn_parity_netrole_[a-z0-9]+/', '<netrole>', $v );
		return $me->ID ? str_replace( array( $me->user_login, $me->user_email ), array( '<self>', '<self-email>' ), $v ) : $v;
	}
	if ( is_array( $v ) ) {
		return array_map( $stamp, $v );
	}
	if ( is_object( $v ) ) {
		foreach ( get_object_vars( $v ) as $k => $x ) {
			$v->$k = ( is_string( $x ) && preg_match( '/nonce$/i', (string) $k ) && preg_match( '/^[0-9a-f]{10}$/', $x ) ) ? '<nonce>' : $stamp( $x );
		}
	}
	return $v;
};
$surfaces = array();
foreach ( Minn_Admin_Surfaces::for_current_user() as $s ) {
	if ( is_array( $s ) && isset( $s['id'] ) ) {
		$surfaces[ (string) $s['id'] ] = json_decode( wp_json_encode( $s ) );
	}
}
ksort( $surfaces );
$panels = json_decode( wp_json_encode( Minn_Admin_Surfaces::editor_panels_for_current_user() ) );

$actions_to_run = array(); // [ surface, list node path, action, pool, the list's requests, items key, sibling actions ]
// A list request as the app builds it (surfaceRoute in app.js): the tab's
// route ({tab}, or allRoute for "All"), the fixed query, the tab param, the
// search and filter templates (or JSON criteria merged into one param), and
// the page query. Returns [ path, query ].
$list_request = function ( $node, $tab, $fv, $q ) use ( $split ) {
	$route = '_all' === $tab ? ( isset( $node->allRoute ) && is_string( $node->allRoute ) ? $node->allRoute : $node->route ) : str_replace( '{tab}', rawurlencode( (string) $tab ), $node->route );
	$parts = array();
	if ( isset( $node->query ) && is_string( $node->query ) ) {
		$parts[] = $node->query;
	}
	if ( '_all' !== $tab && isset( $node->tabs->param ) && is_string( $node->tabs->param ) ) {
		$parts[] = $node->tabs->param . '=' . rawurlencode( (string) $tab );
	}
	$criteria = array();
	$cparam   = null;
	$json_sub = function ( $json, $token, $value ) {
		$enc = substr( wp_json_encode( (string) $value ), 1, -1 );
		return json_decode( str_replace( $token, $enc, wp_json_encode( $json ) ), true );
	};
	if ( '' !== $q && isset( $node->search ) ) {
		if ( is_string( $node->search ) ) {
			$parts[] = str_replace( '{q}', rawurlencode( $q ), $node->search );
		} elseif ( isset( $node->search->param, $node->search->json ) ) {
			$cparam   = (string) $node->search->param;
			$criteria = array_merge( $criteria, (array) $json_sub( $node->search->json, '{q}', $q ) );
		}
	}
	if ( '' !== (string) $fv && isset( $node->filter ) ) {
		if ( isset( $node->filter->query ) && is_string( $node->filter->query ) ) {
			$parts[] = str_replace( '{v}', rawurlencode( (string) $fv ), $node->filter->query );
		} elseif ( isset( $node->filter->json ) ) {
			$cparam   = $cparam ? $cparam : ( isset( $node->filter->param ) ? (string) $node->filter->param : null );
			$criteria = array_merge( $criteria, (array) $json_sub( $node->filter->json, '{v}', $fv ) );
		}
	}
	$page = isset( $node->pageQuery ) && is_string( $node->pageQuery ) ? $node->pageQuery : 'per_page=25&page={page}';
	$parts[] = str_replace( array( '{page}', '{page0}' ), array( '1', '0' ), $page );
	list( $path, $query ) = $split( $route );
	parse_str( implode( '&', $parts ), $more );
	if ( $cparam && $criteria ) {
		// The app encodes it twice because Gravity Forms decodes it again;
		// this request is past the first decode already.
		$more[ $cparam ] = rawurlencode( wp_json_encode( $criteria ) );
	}
	return array( $path, $query + $more );
};
$probe_list = function ( $sid, $node, $where ) use ( &$probe_list, &$actions_to_run, $record, $split, $rows_of, $fill, $wanted, $match, $is_api, $list_request ) {
	list( $tpl_route ) = $split( $node->route );
	$items_key         = isset( $node->itemsKey ) && is_string( $node->itemsKey ) ? $node->itemsKey : '';
	$via               = "descriptor:{$sid}{$where}";
	// Tabs: the first real one, as the app lists them.
	$tab = null;
	if ( isset( $node->tabs->static ) && is_array( $node->tabs->static ) && isset( $node->tabs->static[0][0] ) ) {
		$tab = (string) $node->tabs->static[0][0];
	} elseif ( isset( $node->tabs->route ) && $is_api( $node->tabs->route ) ) {
		list( $troute, $tq ) = $split( $node->tabs->route );
		$tabs                = $record( 'GET ' . $troute . ( $tq ? '?' . http_build_query( $tq ) : '' ), 'GET', $troute, $tq, $via . '.tabs' );
		$vk                  = isset( $node->tabs->valueKey ) && is_string( $node->tabs->valueKey ) ? $node->tabs->valueKey : 'id';
		$rows                = $tabs && ( is_array( $tabs['data'] ) || is_object( $tabs['data'] ) ) ? array_values( (array) $tabs['data'] ) : array();
		if ( $rows && is_object( $rows[0] ) && isset( $rows[0]->$vk ) && is_scalar( $rows[0]->$vk ) ) {
			$tab = (string) $rows[0]->$vk;
		}
	}
	$filters = array();
	if ( isset( $node->filter->options ) && is_array( $node->filter->options ) ) {
		foreach ( $node->filter->options as $opt ) {
			$filters[] = is_array( $opt ) ? (string) ( $opt[0] ?? '' ) : ( is_object( $opt ) && isset( $opt->value ) ? (string) $opt->value : '' );
		}
	}
	$default = $filters ? $filters[0] : '';
	$all     = '_all' === $tab || false === strpos( $node->route, '{tab}' ) || isset( $node->allRoute );
	$probes  = array();
	if ( $all ) {
		$probes[ 'tab=all' ] = array( '_all', $default, '' );
		foreach ( array_slice( $filters, 1 ) as $f ) {
			$probes[ 'tab=all filter=' . $f ] = array( '_all', $f, '' );
		}
		if ( isset( $node->search ) ) {
			$probes['tab=all search'] = array( '_all', $default, 'a' );
		}
	}
	if ( null !== $tab ) {
		$probes['tab=first'] = array( $tab, $default, '' );
		if ( ! $all ) {
			foreach ( array_slice( $filters, 1 ) as $f ) {
				$probes[ 'tab=first filter=' . $f ] = array( $tab, $f, '' );
			}
		}
	}
	$pool     = array();
	$variants = array();
	foreach ( $probes as $label => list( $t, $f, $q ) ) {
		list( $path, $query ) = $list_request( $node, $t, $f, $q );
		$m                    = $match( 'GET', $path );
		if ( ! $wanted( $m ? $m[1]['file'] : '', $sid ) ) {
			continue;
		}
		$variants[] = array( $path, $query );
		$r          = $record( 'LIST ' . $tpl_route . ' ' . $label, 'GET', $path, $query, $via );
		if ( $r && '' === $q ) {
			$pool = array_merge( $pool, $rows_of( $r['data'], $items_key ) );
		}
	}
	if ( ! $variants ) {
		return;
	}
	$item = null;
	foreach ( $pool as $row ) {
		if ( is_object( $row ) && isset( $row->id ) ) {
			$item = $row;
			break;
		}
	}
	foreach ( array( 'sectionsRoute', 'detailRoute' ) as $k ) {
		if ( $item && isset( $node->detail->$k ) && $is_api( $node->detail->$k ) ) {
			list( $droute, $dq ) = $split( $fill( $node->detail->$k, $item ) );
			list( $dtpl )        = $split( $node->detail->$k );
			$record( 'GET ' . $dtpl, 'GET', $droute, $dq, $via . '.detail' );
		}
	}
	if ( $item && isset( $node->detail->labels->route ) && $is_api( $node->detail->labels->route ) ) {
		list( $lroute, $lq ) = $split( $fill( $node->detail->labels->route, $item ) );
		list( $ltpl )        = $split( $node->detail->labels->route );
		$record( 'GET ' . $ltpl, 'GET', $lroute, $lq, $via . '.detail.labels' );
	}
	$mine = array();
	foreach ( array( 'actions', 'bulk' ) as $k ) {
		if ( empty( $node->$k ) || ! is_array( $node->$k ) ) {
			continue;
		}
		foreach ( $node->$k as $i => $a ) {
			if ( is_object( $a ) && ! empty( $a->route ) && $is_api( $a->route ) && ! empty( $a->method ) && 'GET' !== strtoupper( $a->method ) ) {
				$mine[] = array( "{$where}.{$k}[{$i}]", $a );
			}
		}
	}
	foreach ( $mine as list( $at, $a ) ) {
		$actions_to_run[] = array( $sid, $at, $a, $pool, $variants, $items_key, $mine );
	}
	// Views and other nested lists.
	foreach ( get_object_vars( $node ) as $k => $v ) {
		$kids = is_array( $v ) ? $v : array( $v );
		foreach ( $kids as $i => $kid ) {
			if ( is_object( $kid ) && isset( $kid->route ) && $is_api( $kid->route ) && ( isset( $kid->columns ) || isset( $kid->itemsKey ) ) ) {
				$probe_list( $sid, $kid, "{$where}.{$k}" . ( is_array( $v ) ? "[{$i}]" : '' ) );
			}
		}
	}
};
// Any other route a descriptor names, fetched as written when it needs no item.
$probe_routes = function ( $sid, $node, $where ) use ( &$probe_routes, $probe_list, $record, $split, $wanted, $match, $is_api ) {
	if ( is_object( $node ) && isset( $node->route ) && $is_api( $node->route ) && ( isset( $node->columns ) || isset( $node->itemsKey ) ) ) {
		$probe_list( $sid, $node, $where );
		return;
	}
	if ( ! is_object( $node ) && ! is_array( $node ) ) {
		return;
	}
	$is_get = ! is_object( $node ) || empty( $node->method ) || 'GET' === strtoupper( (string) $node->method );
	foreach ( ( is_object( $node ) ? get_object_vars( $node ) : $node ) as $k => $v ) {
		$here = $where . ( is_array( $node ) ? "[{$k}]" : ".{$k}" );
		if ( is_string( $v ) ) {
			if ( $is_get && $is_api( $v ) && false === strpos( $v, '{' ) && in_array( $k, array( 'route', 'statusRoute', 'chartRoute', 'countRoute', 'loadRoute', 'listRoute' ), true ) ) {
				list( $r, $q ) = $split( $v );
				$m             = $match( 'GET', $r );
				if ( $wanted( $m ? $m[1]['file'] : '', $sid ) ) {
					$record( 'GET ' . $r . ( $q ? '?' . http_build_query( $q ) : '' ), 'GET', $r, $q, "descriptor:{$sid}{$here}" );
				}
			}
			continue;
		}
		$probe_routes( $sid, $v, $here );
	}
};
// A descriptor shape the probes do not expect is reported, not fatal.
$probe_errors = array();
foreach ( $surfaces as $sid => $s ) {
	try {
		$probe_routes( $sid, $s, '' );
	} catch ( \Throwable $e ) {
		$probe_errors[ $sid ] = get_class( $e ) . ': ' . $e->getMessage() . ' at line ' . $e->getLine();
	}
}

// The walk: every admitted GET route, path ids taken from the parent list.
$params_of = function ( $route ) {
	$out = array();
	$pos = 0;
	while ( false !== ( $at = strpos( $route, '(?P<', $pos ) ) ) {
		$depth = 0;
		for ( $i = $at; $i < strlen( $route ); $i++ ) {
			if ( '(' === $route[ $i ] && ( 0 === $i || '\\' !== $route[ $i - 1 ] ) ) {
				$depth++;
			} elseif ( ')' === $route[ $i ] && '\\' !== $route[ $i - 1 ] ) {
				$depth--;
				if ( 0 === $depth ) {
					break;
				}
			}
		}
		$group = substr( $route, $at, $i - $at + 1 );
		preg_match( '/^\(\?P<(\w+)>(.*)\)$/s', $group, $m );
		$out[] = array( 'group' => $group, 'name' => $m[1] ?? '', 'pattern' => $m[2] ?? '' );
		$pos   = $i + 1;
	}
	return $out;
};
$walk_routes = array();
foreach ( $by_route as $route => $methods ) {
	if ( isset( $methods['GET'] ) && $methods['GET']['gate'] && $wanted( $methods['GET']['file'] ) ) {
		$walk_routes[] = $route;
	}
}
sort( $walk_routes );
$pending = array();
foreach ( $walk_routes as $route ) {
	$params = $params_of( $route );
	if ( ! $params ) {
		$record( 'GET ' . $route, 'GET', $route, array(), 'walk' );
	} else {
		$pending[ $route ] = $params;
	}
}
$unfilled = array();
for ( $pass = 0; $pass < 3 && $pending; $pass++ ) {
	foreach ( $pending as $route => $params ) {
		$path = $route;
		$ok   = true;
		foreach ( $params as $p ) {
			// A fixed alternation names its own values.
			if ( preg_match( '/^[\w-]+(\|[\w-]+)*$/', $p['pattern'] ) ) {
				$path = str_replace( $p['group'], explode( '|', $p['pattern'] )[0], $path );
				continue;
			}
			$parent = rtrim( substr( $path, 0, strpos( $path, $p['group'] ) ), '/' );
			$rows   = array();
			foreach ( $fetched as $concrete => $r ) {
				if ( 0 === strpos( $concrete, 'GET ' . $parent ) && preg_match( '#^GET ' . preg_quote( $parent, '#' ) . '(\?|$)#', $concrete ) && 200 === $r['status'] ) {
					$rows = $rows_of( $r['data'] );
					if ( $rows ) {
						break;
					}
				}
			}
			$value = null;
			foreach ( $rows as $row ) {
				foreach ( array_unique( array( $p['name'], 'id', 'slug', 'key', 'name' ) ) as $k ) {
					if ( is_object( $row ) && isset( $row->$k ) && is_scalar( $row->$k ) && preg_match( '@^(' . $p['pattern'] . ')$@', (string) $row->$k ) ) {
						$value = (string) $row->$k;
						break 2;
					}
				}
			}
			if ( null === $value ) {
				$ok = false;
				break;
			}
			$path = str_replace( $p['group'], $value, $path );
		}
		if ( $ok ) {
			$record( 'GET ' . $route, 'GET', $path, array(), 'walk' );
			unset( $pending[ $route ] );
		}
	}
}
$unfilled = array_keys( $pending );

// Actions of the named surfaces, each on the first item its `when` matches.
// With no such item (no spam on the lab for "Not spam"), a sibling action
// that moves an item there runs first, in the same rolled-back transaction.
$matches = function ( $row, $a ) use ( $value_at ) {
	if ( ! is_object( $row ) ) {
		return false;
	}
	if ( empty( $a->when ) ) {
		return true;
	}
	$v = $value_at( $row, is_string( $a->when->key ?? null ) ? $a->when->key : '' );
	$e = $a->when->equals ?? '';
	return is_scalar( $v ) && is_scalar( $e ) && (string) $v === (string) $e;
};
$ran = array();
foreach ( $actions_to_run as list( $sid, $where, $a, $pool, $list, $items_key, $siblings ) ) {
	$method = strtoupper( $a->method );
	$hit    = false;
	foreach ( $writes as $w ) {
		$m = $match( $method, '/' . ltrim( preg_replace( '/\{\w+\}/', '1', $split( $a->route )[0] ), '/' ) );
		if ( $w === $sid || ( $m && false !== strpos( $m[1]['file'], $w ) ) ) {
			$hit = true;
		}
	}
	if ( ! $hit ) {
		continue;
	}
	list( $tpl ) = $split( $a->route );
	$key         = $method . ' ' . $tpl . ( empty( $a->when ) ? '' : ' when ' . wp_json_encode( $a->when ) );
	if ( isset( $ran[ $key ] ) ) {
		$responses[ $key ]['via'][] = "descriptor:{$sid}{$where}";
		continue;
	}
	$ran[ $key ] = true;
	if ( ! empty( $a->fields ) ) {
		// A parameterized action takes what the person types; nothing to replay.
		$responses[ $key ] = array( 'status' => 'needs input', 'via' => array( "descriptor:{$sid}{$where}" ) );
		continue;
	}
	$body        = isset( $a->body ) ? $a->body : null;
	$item        = null;
	foreach ( $pool as $row ) {
		if ( $matches( $row, $a ) ) {
			$item = $row;
			break;
		}
	}
	if ( $item ) {
		list( $path, $q ) = $split( $fill( $a->route, $item ) );
		$record( $key, $method, $path, $q, "descriptor:{$sid}{$where}", null, $body );
		continue;
	}
	$done = false;
	foreach ( $siblings as list( $sat, $s ) ) {
		$from = null;
		foreach ( $pool as $row ) {
			if ( $s !== $a && $matches( $row, $s ) && isset( $row->id ) ) {
				$from = $row;
				break;
			}
		}
		if ( ! $from ) {
			continue;
		}
		if ( ! empty( $s->fields ) ) {
			continue;
		}
		$result = $tx( function () use ( $call, $split, $fill, $rows_of, $matches, $s, $a, $from, $list, $items_key, $method, $body ) {
			list( $sp, $sq ) = $split( $fill( $s->route, $from ) );
			$setup           = $call( strtoupper( $s->method ), $sp, $sq, isset( $s->body ) ? $s->body : null );
			if ( ! is_int( $setup['status'] ) || $setup['status'] >= 400 ) {
				return null;
			}
			foreach ( $list as list( $vp, $vq ) ) {
				$r = $call( 'GET', $vp, $vq );
				foreach ( $rows_of( $r['data'], $items_key ) as $row ) {
					if ( isset( $row->id ) && (string) $row->id === (string) $from->id && $matches( $row, $a ) ) {
						list( $path, $q ) = $split( $fill( $a->route, $row ) );
						return array( $path, $q, $call( $method, $path, $q, $body ) );
					}
				}
			}
			return null;
		} );
		if ( $result ) {
			$record( $key, $method, $result[0], $result[1], "descriptor:{$sid}{$where} after {$sat}", $result[2], $body );
			$done = true;
			break;
		}
	}
	if ( ! $done ) {
		$responses[ $key ] = array( 'status' => 'no item', 'via' => array( "descriptor:{$sid}{$where}" ) );
	}
}
ksort( $responses );
@unlink( $inflight );

$user = wp_get_current_user();
file_put_contents( $minn_parity_out, wp_json_encode( array(
	'as'           => $as_label,
	'user'         => (int) $user->ID,
	'roles'        => array_values( (array) $user->roles ),
	'super'        => is_multisite() && is_super_admin(),
	'endpoints'    => $endpoints,
	'surfaces'     => $stamp( (object) $surfaces ),
	'editorPanels' => $stamp( $panels ),
	'responses'    => (object) $responses,
	'unfilled'     => $unfilled,
	'probeErrors'  => (object) $probe_errors,
	'skipped'      => $skipped,
), JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT ) . "\n" );
printf( "parity %s: %d endpoints, %d surfaces, %d responses, %d routes with no sample id\n", $as_label, count( $endpoints ), count( $surfaces ), count( $responses ), count( $unfilled ) );
