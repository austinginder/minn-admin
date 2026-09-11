<?php
/**
 * Shared status-card chart helpers: the "last N days" bucket skeleton the
 * family status cards fill (entries per day, backups per day, and so on).
 *
 * Days are the SITE's days (wp_date), which is the only calendar a person
 * reading the card has. Each adapter decides how its own timestamps map onto
 * those days: a site-local column groups by DATE() directly, a UTC column is
 * bucketed through minn_admin_chart_utc_day(), and a column on the DB session
 * clock goes through the mail family's minn_admin_db_local_to_utc_iso() first.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Empty buckets for the last $n site-local days ending today, keyed Y-m-d.
 *
 * @param int $n Days.
 * @return array<string,array{label:string,value:int,secondary:int}>
 */
function minn_admin_chart_days( $n = 14 ) {
	$days = array();
	for ( $i = $n - 1; $i >= 0; $i-- ) {
		$d          = wp_date( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
		// from/to are the bounds a clicked bar sends back to the collection
		// (the `dateQuery` contract). They are SITE-LOCAL, like the buckets
		// themselves; a route whose column is on another clock converts them
		// with minn_admin_chart_range_clause() rather than comparing raw.
		$days[ $d ] = array(
			'label'     => $d,
			'value'     => 0,
			'secondary' => 0,
			'from'      => $d . ' 00:00:00',
			'to'        => $d . ' 23:59:59',
		);
	}
	return $days;
}

/** Site-local midnight $n-1 days ago, as a MySQL datetime on the site clock. */
function minn_admin_chart_local_since( $n = 14 ) {
	return wp_date( 'Y-m-d', time() - ( $n - 1 ) * DAY_IN_SECONDS ) . ' 00:00:00';
}

/** The same instant as minn_admin_chart_local_since(), as a UTC MySQL datetime. */
function minn_admin_chart_utc_since( $n = 14 ) {
	try {
		$local = new DateTimeImmutable( wp_date( 'Y-m-d', time() - ( $n - 1 ) * DAY_IN_SECONDS ) . ' 00:00:00', wp_timezone() );
		return $local->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	} catch ( \Throwable $e ) {
		return gmdate( 'Y-m-d H:i:s', time() - $n * DAY_IN_SECONDS );
	}
}

/**
 * SQL that maps a UTC datetime column onto the site's day, for GROUP BY.
 *
 * The PHP bucketer below is exact, but it needs one row per item, and these
 * tables are filled by unauthenticated form submissions — a spam wave turned
 * a status card into a memory-exhaustion 500. Grouping in SQL returns at most
 * a row per day per status however large the table gets.
 *
 * It uses the site's CURRENT offset, so an entry inside the one-hour band a
 * DST change moves can land on the neighbouring day. That is the same
 * approximation the site-clock siblings already make by grouping on DATE(),
 * and it is worth a bounded query.
 *
 * @param string $col Adapter-authored column name (never request input).
 * @return string
 */
function minn_admin_chart_utc_day_sql( $col ) {
	$offset = (int) round( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS );
	return sprintf( 'DATE(%s + INTERVAL %d SECOND)', $col, $offset );
}

/** The site day (Y-m-d) a UTC MySQL datetime falls on; '' when unparsable. */
function minn_admin_chart_utc_day( $mysql_utc ) {
	$ts = strtotime( trim( (string) $mysql_utc ) . ' UTC' );
	return $ts ? wp_date( 'Y-m-d', $ts ) : '';
}

/**
 * The WHERE fragments for a clicked chart bar's day, converted onto the
 * clock the adapter's own column is stored in.
 *
 * The bounds arrive site-local (minn_admin_chart_days minted them that way,
 * because the site's days are the only calendar the reader has). What differs
 * per adapter is the column, so the caller names its clock and gets back
 * clauses it can drop into its own WHERE:
 *
 *   local  A datetime already on the site's clock. Compared directly.
 *   utc    A datetime stored in UTC (the common case for plugins that use
 *          current_time('mysql', true) or gmdate).
 *   epoch  A Unix timestamp column, always a real UTC instant.
 *
 * Returns array( array $sql_fragments, array $args ); both empty when the
 * request carries no bar. The caller supplies $column itself, so it is never
 * request input.
 *
 * @param WP_REST_Request $request Request carrying after/before.
 * @param string          $column  Adapter-authored column name.
 * @param string          $clock   'local' | 'utc' | 'epoch'.
 * @return array{0:string[],1:array}
 */
function minn_admin_chart_range_clause( $request, $column, $clock = 'local' ) {
	$sql  = array();
	$args = array();
	foreach ( array( 'after' => '>=', 'before' => '<=' ) as $param => $op ) {
		$bound = (string) $request->get_param( $param );
		if ( '' === $bound ) {
			continue;
		}
		if ( 'epoch' === $clock ) {
			// strtotime reads the bound on the SITE's clock (PHP's default
			// zone is the site's), which is what these bounds are.
			$stamp = strtotime( $bound );
			if ( false === $stamp ) {
				continue;
			}
			$sql[]  = "{$column} {$op} %d";
			$args[] = $stamp;
			continue;
		}
		$value = 'utc' === $clock ? get_gmt_from_date( $bound ) : $bound;
		if ( '' === (string) $value ) {
			continue;
		}
		$sql[]  = "{$column} {$op} %s";
		$args[] = $value;
	}
	return array( $sql, $args );
}

/**
 * Count one item into its day. Unknown days (outside the window) are ignored.
 *
 * @param array  $days      Buckets from minn_admin_chart_days(), by reference.
 * @param string $day       Y-m-d.
 * @param bool   $secondary Count into the secondary series instead of the primary.
 * @param int    $n         How many to add.
 */
function minn_admin_chart_bump( array &$days, $day, $secondary = false, $n = 1 ) {
	if ( ! isset( $days[ $day ] ) ) {
		return;
	}
	$days[ $day ][ $secondary ? 'secondary' : 'value' ] += (int) $n;
}

/**
 * The chart payload the status card contract expects.
 *
 * @param array  $days      Buckets.
 * @param string $primary   Primary series label.
 * @param string $secondary Secondary series label ('' for a single-series chart).
 * @param string $title     Chart title.
 * @return array
 */
function minn_admin_chart_build( array $days, $primary, $secondary = '', $title = '' ) {
	$points = array_values( $days );
	if ( '' === $secondary ) {
		foreach ( $points as &$p ) {
			unset( $p['secondary'] );
		}
		unset( $p );
	}
	$out = array(
		'title'   => '' !== $title ? $title : __( 'Last 14 days', 'minn-admin' ),
		'primary' => $primary,
		'points'  => $points,
	);
	if ( '' !== $secondary ) {
		$out['secondary'] = $secondary;
	}
	return $out;
}
