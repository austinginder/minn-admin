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
		$days[ $d ] = array( 'label' => $d, 'value' => 0, 'secondary' => 0 );
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

/** The site day (Y-m-d) a UTC MySQL datetime falls on; '' when unparsable. */
function minn_admin_chart_utc_day( $mysql_utc ) {
	$ts = strtotime( trim( (string) $mysql_utc ) . ' UTC' );
	return $ts ? wp_date( 'Y-m-d', $ts ) : '';
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
