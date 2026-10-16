<?php
/**
 * Which write routes the round trip reaches. roundtrip.sh runs this last.
 *
 * Every POST, PUT, PATCH and DELETE route under minn-admin/v1 either had a
 * save replayed and judged by a round-trip pass this run (each pass logs it
 * through lib/roundtrip.php's `cover`, into the file MINN_RT_COVERED names),
 * or is listed in fixtures/roundtrip-coverage.json with its kind and why no
 * pass covers it:
 *
 *   delete, action, create  a one-shot verb (a delete, a send, a run, a
 *                           new item): nothing loaded goes back, so there is
 *                           no unchanged save to replay;
 *   clean                   a page's save the pages pass already holds to a
 *                           stricter rule: a clean save sends nothing at all;
 *   edit                    a save of values a page or dialog loaded, which
 *                           no pass reaches yet. These are the backlog: the
 *                           class of save the 0.44.0 and 0.45.0 registry
 *                           scans found rewriting what nobody changed.
 *
 * An entry may name the `pass` that covers it when the lab lets it (a page
 * that timed out, a surface with no item on this lab): covered or not, it
 * stays listed, and a run that misses it says so.
 *
 * The run fails on a write route in neither list (a new route: give it a
 * round trip, or list it with its reason), and on a listed route a pass now
 * covers (take it off the list), so the backlog only shrinks. A listed route
 * this site does not register (its plugin is off) is a note.
 *
 * MINN_RT_COVERAGE=write rewrites the fixture from this run: covered routes
 * leave it, entries for routes this site lacks stay, and a new route is
 * added under a kind guessed from its shape, with a reason that says it is
 * unreviewed until someone writes the real one.
 *
 * Run: MINN_RT_COVERED=<file> wp eval-file tests/roundtrip-coverage.test.php --user=admin --path=<site>
 *
 * @package minn-admin
 */

$fixture = __DIR__ . '/fixtures/roundtrip-coverage.json';
$covered = getenv( 'MINN_RT_COVERED' );
if ( ! $covered || ! is_readable( $covered ) ) {
	echo "SKIP  no MINN_RT_COVERED file: run ./roundtrip.sh\n";
	return;
}

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, '' !== $detail ? " — {$detail}" : '' );
};

// Every write route this site registers under Minn's namespace.
$routes = array();
foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
	if ( 0 !== strpos( $route, '/minn-admin/v1/' ) ) {
		continue;
	}
	foreach ( $handlers as $h ) {
		foreach ( array_keys( array_filter( (array) $h['methods'] ) ) as $m ) {
			if ( in_array( $m, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ) {
				$routes[ "{$m} {$route}" ] = array( $m, $route );
			}
		}
	}
}
ksort( $routes );

// The routes this run's saves reached, matched the way the REST server
// matches a request to a route.
$hit = array();
foreach ( file( $covered, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) as $line ) {
	list( $m, $path ) = array_pad( explode( ' ', $line, 2 ), 2, '' );
	$path = '/' . ltrim( (string) strtok( $path, '?' ), '/' );
	foreach ( $routes as $key => $r ) {
		if ( $r[0] === $m && preg_match( '@^' . $r[1] . '$@i', $path ) ) {
			$hit[ $key ] = true;
		}
	}
}

$listed = is_readable( $fixture ) ? (array) json_decode( (string) file_get_contents( $fixture ), true ) : array();
$listed = (array) ( $listed['routes'] ?? array() );

// A kind from the route's shape, for a new entry until someone reviews it.
$guess = function ( $method, $route ) {
	if ( 'DELETE' === $method ) {
		return 'delete';
	}
	$path = preg_replace( '/\(\?P<[^>]+>[^)]*\)/', '{x}', $route );
	$last = basename( $path );
	if ( preg_match( '/^(status|restore|active|resend|duplicate|clone|preview|trash|spam|unspam|hide|unhide|test|run|order|reorder|delete|remove|unlock|toggle|revoke|resume|pause|regenerate|read|unread|move|import|export|deactivate|activate|enable|disable|cancel|backup-now|upload|update-all|transfer|star|send|send-test|seed-sessions|reset-password|reply|reindex|regenerate-token|reactivate|purge|flush|clear|sync|refresh|verify|connect|disconnect|install|update|upgrade|approve|reject|dismiss|mark|retry|start|stop|download|switch|apply|mix|reset|empty|render-blocks|check|scan|login|logout|link|unlink|rollback|release|block|unblock|lock|archive|unarchive|publish|unpublish)$/', $last ) ) {
		return 'action';
	}
	if ( '{x}' === $last || preg_match( '/\{x\}$/', $last ) ) {
		return 'edit';
	}
	if ( 'new' === $last || preg_match( '/s$/', $last ) ) {
		return 'create';
	}
	return 'edit';
};

$uncovered = array();
$stale     = array();
foreach ( $routes as $key => $r ) {
	if ( isset( $hit[ $key ] ) ) {
		if ( isset( $listed[ $key ] ) && empty( $listed[ $key ]['pass'] ) ) {
			$stale[] = $key;
		}
		continue;
	}
	if ( ! isset( $listed[ $key ] ) ) {
		$uncovered[] = $key;
	}
}
$absent = array_values( array_diff( array_keys( $listed ), array_keys( $routes ) ) );

if ( 'write' === getenv( 'MINN_RT_COVERAGE' ) ) {
	$out = array();
	foreach ( $listed as $key => $entry ) {
		if ( ! in_array( $key, $stale, true ) ) {
			$out[ $key ] = $entry;
		}
	}
	foreach ( $uncovered as $key ) {
		list( $m, $route ) = $routes[ $key ];
		$out[ $key ] = array( 'kind' => $guess( $m, $route ), 'why' => 'unreviewed: guessed from the route\'s shape' );
	}
	ksort( $out );
	file_put_contents( $fixture, wp_json_encode( array( 'routes' => $out ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n" );
	printf( "WROTE %s: %d listed (%d added, %d covered now and removed)\n", basename( $fixture ), count( $out ), count( $uncovered ), count( $stale ) );
	return;
}

$kinds = array();
foreach ( $listed as $key => $entry ) {
	if ( isset( $routes[ $key ] ) && ! in_array( $key, $stale, true ) ) {
		$k = (string) ( $entry['kind'] ?? '?' );
		$kinds[ $k ] = ( $kinds[ $k ] ?? 0 ) + 1;
	}
}
ksort( $kinds );
printf( "      %d write routes here: %d had a save replayed this run; listed: %s\n", count( $routes ), count( $hit ), wp_json_encode( $kinds ) );
$check( 'every write route had a round trip or is listed with its reason', ! $uncovered, $uncovered ? count( $uncovered ) . " new:\n        " . implode( "\n        ", $uncovered ) : '' );
$check( 'no listed route is covered now', ! $stale, $stale ? 'take off the list: ' . implode( ', ', $stale ) : '' );
$unreviewed = array_keys( array_filter( $listed, function ( $e ) {
	return 0 === strpos( (string) ( $e['why'] ?? '' ), 'unreviewed' );
} ) );
$check( 'every listed route says why it has no round trip', ! $unreviewed, $unreviewed ? count( $unreviewed ) . ' unreviewed, e.g. ' . implode( ', ', array_slice( $unreviewed, 0, 5 ) ) : '' );
$missed = array();
foreach ( $listed as $key => $entry ) {
	if ( ! empty( $entry['pass'] ) && isset( $routes[ $key ] ) && ! isset( $hit[ $key ] ) ) {
		$missed[] = "{$key} ({$entry['pass']})";
	}
}
if ( $missed ) {
	printf( "NOTE  %d route(s) a pass covers when the lab lets it were not reached this run:\n        %s\n", count( $missed ), implode( "\n        ", $missed ) );
}
if ( $absent ) {
	printf( "NOTE  %d listed route(s) not registered here (their plugin is off)\n", count( $absent ) );
}
printf( "\nroundtrip-coverage: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
