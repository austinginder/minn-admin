<?php
/**
 * Field types: how each value a resource sends leaves the server.
 *
 * A resource declares the fields its rows carry; only those leave, each
 * turned into one shape for the client, whatever the vendor stored:
 *
 *   'int', 'float', 'bool'   cast
 *   'text'                   a string; 'decode' => true for a vendor that
 *                            stores entities ("Smith &amp; Sons"), so the
 *                            client, which escapes what it prints, gets text
 *   'enum'                   a string from 'values', or '' for anything else
 *   'datetime'               'stored' => utc | site | epoch | local-epoch, and
 *                            'wire' => naked ("Y-m-d\TH:i:s" on the stored clock
 *                            for a datetime column, which the descriptor marks
 *                            utc or not; on the site's clock for an epoch),
 *                            iso (ISO-8601 with the site's offset), iso-utc
 *                            (ISO-8601 in UTC, "+00:00") or z (UTC with a
 *                            trailing Z, "Y-m-d\TH:i:s\Z"; '' for a zero date)
 *   'raw'                    as built (nested sections, an edit block)
 *   'secret'                 never sent
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

class Minn_Admin_Kernel_Fields {

	/**
	 * A declaration's field spec as an array with a type.
	 *
	 * @param string|array $spec Type name or spec.
	 * @return array
	 */
	public static function spec( $spec ) {
		$spec = is_array( $spec ) ? $spec : array( 'type' => (string) $spec );
		if ( empty( $spec['type'] ) ) {
			$spec['type'] = 'raw';
		}
		return $spec;
	}

	/**
	 * The declared fields of a built row, encoded. Anything undeclared is
	 * dropped, and a declared field the row lacks is left out.
	 *
	 * @param array $fields Name => spec.
	 * @param array $row    Built row.
	 * @return array
	 */
	public static function out( array $fields, array $row ) {
		$out = array();
		foreach ( $fields as $name => $spec ) {
			$spec = self::spec( $spec );
			if ( 'secret' === $spec['type'] || ! array_key_exists( $name, $row ) ) {
				continue;
			}
			$out[ $name ] = self::encode( $spec, $row[ $name ] );
		}
		// For the adapter's author, in the log: a notice here would ride every
		// REST response as an X-WP-DoingItWrong header, to anyone.
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			$extra = array_diff( array_keys( $row ), array_keys( $fields ) );
			if ( $extra ) {
				error_log( 'minn_kernel_fields_dropped=' . implode( ',', $extra ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
		}
		return $out;
	}

	/**
	 * One value, encoded for the client.
	 *
	 * @param array $spec Field spec.
	 * @param mixed $v    Value as built.
	 * @return mixed
	 */
	public static function encode( array $spec, $v ) {
		if ( null === $v && ! empty( $spec['nullable'] ) ) {
			return null;
		}
		switch ( $spec['type'] ) {
			case 'int':
				return (int) $v;
			case 'float':
				return (float) $v;
			case 'bool':
				return (bool) $v;
			case 'text':
				$v = is_scalar( $v ) ? (string) $v : '';
				return empty( $spec['decode'] ) ? $v : html_entity_decode( $v, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			case 'enum':
				$v = is_scalar( $v ) ? (string) $v : '';
				return in_array( $v, array_map( 'strval', (array) ( $spec['values'] ?? array() ) ), true ) ? $v : '';
			case 'datetime':
				return self::datetime( $v, (string) ( $spec['stored'] ?? 'site' ), (string) ( $spec['wire'] ?? 'iso' ) );
			default:
				return $v;
		}
	}

	/**
	 * A stored date on the wire.
	 *
	 * @param mixed  $v      Stored value.
	 * @param string $stored utc | site | epoch | local-epoch.
	 * @param string $wire   naked | iso | iso-utc | z.
	 * @return string '' when there is no date.
	 */
	public static function datetime( $v, $stored, $wire ) {
		$v = is_scalar( $v ) ? trim( (string) $v ) : '';
		if ( '' === $v || ( 'z' === $wire && 0 === strpos( $v, '0000-00-00' ) ) ) {
			return '';
		}
		if ( 'z' === $wire ) {
			$iso = self::datetime( $v, $stored, 'iso-utc' );
			return '' === $iso ? '' : gmdate( 'Y-m-d\TH:i:s\Z', (int) strtotime( $iso ) );
		}
		if ( 'epoch' === $stored || 'local-epoch' === $stored ) {
			if ( ! is_numeric( $v ) ) {
				return '';
			}
			$ts = (int) $v;
			if ( 'naked' === $wire ) {
				// The epoch's own clock: UTC for a real one, the site's wall
				// clock for a local one (which already carries the offset).
				return gmdate( 'Y-m-d\TH:i:s', 'epoch' === $stored ? $ts + (int) round( (float) get_option( 'gmt_offset', 0 ) * HOUR_IN_SECONDS ) : $ts );
			}
			if ( 'local-epoch' === $stored ) {
				$ts = (int) strtotime( (string) get_gmt_from_date( gmdate( 'Y-m-d H:i:s', $ts ) ) . ' UTC' );
			}
			return 'iso-utc' === $wire ? gmdate( 'c', $ts ) : wp_date( 'c', $ts );
		}
		if ( 'naked' === $wire ) {
			return str_replace( ' ', 'T', $v );
		}
		try {
			$zone = 'utc' === $stored ? new DateTimeZone( 'UTC' ) : wp_timezone();
			$d    = new DateTimeImmutable( $v, $zone );
			return $d->setTimezone( 'iso-utc' === $wire ? new DateTimeZone( 'UTC' ) : wp_timezone() )->format( 'c' );
		} catch ( \Throwable $e ) {
			return '';
		}
	}
}
