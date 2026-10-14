<?php
/**
 * Egress redaction: a credential the database browser would redact never
 * leaves in a minn-admin/v1 response, whatever route built it.
 *
 * Routes keep their own rules (a licence key reported only as present, mail
 * connector secrets as a sentinel, the database browser's redaction). This is
 * the floor under them, for an adapter that slips. It works on the response
 * as it is about to be sent (rest_pre_echo_response, after links and embeds
 * are added), as JSON text, so keys, nested objects and links are covered
 * alike: every copy of a credential, raw, JSON-escaped, URL-encoded or inside
 * a base64 token, becomes "[redacted:<fingerprint>]". The credentials are
 * the ones the browser reads (Minn_Admin_DB::egress_credentials(): the
 * licence, gateway, connector and vendor rows, vendor key tables and account
 * tokens). They are only looked up when the response holds something shaped
 * like a key, so an ordinary response costs one scan. A response that needed
 * it logs one line: minn_egress_redacted route=<route> count=<n>.
 *
 * Out of scope, as for the browser: a credential copied in another case, in
 * hex, cut short, split across two values, or one the browser's rules do not
 * count as a credential.
 *
 * A route that hands a credential to its owner on purpose names the exact
 * value with Minn_Admin_Egress::intended() while it answers; that value, and
 * only it, passes in that response.
 *
 * The write side: while a minn-admin/v1 request runs, a save of an option
 * that carries "[redacted:<fingerprint>]" gets back the credential with that
 * fingerprint, but only one the option already holds, so a screen that showed
 * a redacted value and saves it back cannot overwrite it, and a token pasted
 * into another option stays the text it is.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

class Minn_Admin_Egress {

	const MASK = '[redacted';

	/** Credential => fingerprint, null until first needed. */
	private static $credentials = null;

	/** Values a route hands out on purpose, for the response being built. */
	private static $intended = array();

	/** How many minn-admin/v1 requests are running (a route may dispatch another). */
	private static $depth = 0;

	public static function init() {
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'enter' ), 0, 3 );
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'leave' ), PHP_INT_MAX, 3 );
		add_filter( 'rest_pre_echo_response', array( __CLASS__, 'scrub' ), PHP_INT_MAX, 3 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'scrub_headers' ), PHP_INT_MAX, 3 );
		add_filter( 'pre_update_option', array( __CLASS__, 'keep_option' ), PHP_INT_MAX, 3 );
		// Network rows: core has no filter for every site option, only one per name.
		add_action( 'init', function () {
			if ( is_multisite() && method_exists( 'Minn_Admin_DB', 'credential_option_names' ) ) {
				foreach ( Minn_Admin_DB::credential_option_names() as $name ) {
					add_filter( 'pre_update_site_option_' . $name, array( __CLASS__, 'keep_site_option' ), PHP_INT_MAX, 3 );
				}
			}
		} );
	}

	/** Whether a request is one of Minn's own routes. */
	private static function ours( $request ) {
		return $request instanceof WP_REST_Request && 0 === strpos( $request->get_route(), '/minn-admin/v1' );
	}

	public static function enter( $response, $handler, $request ) {
		if ( self::ours( $request ) ) {
			++self::$depth;
		}
		return $response;
	}

	public static function leave( $response, $handler, $request ) {
		if ( self::ours( $request ) && self::$depth > 0 ) {
			--self::$depth;
		}
		return $response;
	}

	/**
	 * Name a value this response hands to its owner on purpose (a sign-in
	 * link, a connection key). It passes exactly as given; any other
	 * credential in the same response is still redacted.
	 *
	 * @param string $value The value.
	 */
	public static function intended( $value ) {
		$value = (string) $value;
		if ( '' !== $value ) {
			self::$intended[ $value ] = true;
		}
	}

	/** Forget what was read and named, after a save changed a credential row (and between tests). */
	public static function reset() {
		self::$credentials = null;
		self::$intended    = array();
		wp_cache_delete( 'egress_credentials', 'minn_admin' );
	}

	/**
	 * Credential => fingerprint (six hex characters of its hash, nothing more
	 * of it). With a persistent object cache, which already holds the option
	 * rows they come from, the list is kept there for a minute; it is never
	 * written to the database.
	 */
	private static function credentials() {
		if ( null === self::$credentials ) {
			self::$credentials = array();
			$list              = wp_using_ext_object_cache() ? wp_cache_get( 'egress_credentials', 'minn_admin' ) : false;
			if ( ! is_array( $list ) ) {
				$list = method_exists( 'Minn_Admin_DB', 'egress_credentials' ) ? Minn_Admin_DB::egress_credentials() : array();
				if ( wp_using_ext_object_cache() ) {
					wp_cache_set( 'egress_credentials', $list, 'minn_admin', MINUTE_IN_SECONDS );
				}
			}
			if ( $list ) {
				foreach ( $list as $c ) {
					$c = (string) $c;
					if ( strlen( $c ) >= 16 ) {
						self::$credentials[ $c ] = substr( hash( 'sha256', $c ), 0, 6 );
					}
				}
			}
		}
		return self::$credentials;
	}

	/** The forms a credential takes inside JSON text: raw, slash-escaped, URL-encoded, and base64 cores of each. */
	private static function forms( $c ) {
		$forms = array( $c => true, rawurlencode( $c ) => true );
		for ( $s = 0; $s < 3; $s++ ) {
			$core = base64_encode( substr( $c, $s, (int) floor( ( strlen( $c ) - $s ) / 3 ) * 3 ) );
			if ( strlen( $core ) >= 16 ) {
				$forms[ $core ]                      = true;
				$forms[ strtr( $core, '+/', '-_' ) ] = true;
			}
		}
		foreach ( array_keys( $forms ) as $f ) {
			$forms[ str_replace( '/', '\\/', (string) $f ) ] = true;
		}
		return array_keys( $forms );
	}

	/**
	 * Whether JSON text holds anything shaped like a key: a run of 16 or more
	 * token characters with at least three digits and three letters, read
	 * with escaped and URL-encoded slashes, pluses and equals decoded, and
	 * broken at query separators so "_wpnonce=<nonce>" is two short runs.
	 */
	private static function looks_keyed( $json ) {
		$text = str_ireplace( array( '\\/', '%2B', '%2F', '%3D' ), array( '/', '+', '/', '=' ), $json );
		if ( ! preg_match_all( '#[A-Za-z0-9_\-+/]{16,}#', $text, $m ) ) {
			return false;
		}
		foreach ( $m[0] as $run ) {
			if ( preg_match_all( '/\d/', $run ) >= 3 && preg_match_all( '/[A-Za-z]/', $run ) >= 3 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * JSON text with every credential copy replaced, and how many there were.
	 * Intended values are set aside first and put back after.
	 *
	 * @param string $json JSON text.
	 * @return array [ json, count ]
	 */
	private static function mask_json( $json ) {
		if ( ! self::looks_keyed( $json ) || ! self::credentials() ) {
			return array( $json, 0 );
		}
		$held = array();
		$i    = 0;
		foreach ( array_keys( self::$intended ) as $ok ) {
			$enc = substr( (string) wp_json_encode( (string) $ok ), 1, -1 );
			if ( '' !== $enc && false !== strpos( $json, $enc ) ) {
				$mark          = 'minnintendedvalue' . ( $i++ ) . 'x';
				$held[ $mark ] = $enc;
				$json          = str_replace( $enc, $mark, $json );
			}
		}
		$n = 0;
		foreach ( self::credentials() as $c => $fp ) {
			foreach ( self::forms( (string) $c ) as $form ) {
				if ( false !== strpos( $json, $form ) ) {
					$json = str_replace( $form, self::MASK . ':' . $fp . ']', $json, $count );
					$n   += $count;
				}
			}
		}
		if ( $held ) {
			$json = strtr( $json, $held );
		}
		return array( $json, $n );
	}

	/**
	 * rest_pre_echo_response: the body of a minn-admin/v1 response, redacted.
	 *
	 * @param mixed           $result  Response data, links included.
	 * @param WP_REST_Server  $server  Server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function scrub( $result, $server, $request ) {
		if ( ! self::ours( $request ) ) {
			return $result;
		}
		$json = wp_json_encode( $result );
		if ( false === $json ) {
			return $result;
		}
		list( $masked, $n ) = self::mask_json( $json );
		self::$intended     = array();
		if ( ! $n ) {
			return $result;
		}
		$out = json_decode( $masked );
		if ( null === $out && 'null' !== $masked ) {
			// Masking never breaks JSON (the mask holds no quote or backslash);
			// if it ever did, nothing is better than the unmasked body.
			return array();
		}
		error_log( 'minn_egress_redacted route=' . $request->get_route() . ' count=' . $n ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		return $out;
	}

	/** rest_post_dispatch: a credential in a response header is redacted too. */
	public static function scrub_headers( $response, $server, $request ) {
		if ( ! self::ours( $request ) || ! $response instanceof WP_HTTP_Response ) {
			return $response;
		}
		foreach ( $response->get_headers() as $name => $value ) {
			if ( is_string( $value ) && strlen( $value ) >= 16 ) {
				$enc = (string) wp_json_encode( $value );
				list( $masked, $n ) = self::mask_json( $enc );
				if ( $n ) {
					$response->header( $name, (string) json_decode( $masked ) );
				}
			}
		}
		return $response;
	}

	/** pre_update_option: see the header. */
	public static function keep_option( $value, $option, $old_value ) {
		return self::keep( $value, $old_value, 'option=' . $option );
	}

	/** pre_update_site_option_{name}: the same for a network row. */
	public static function keep_site_option( $value, $old_value, $option ) {
		return self::keep( $value, $old_value, 'site_option=' . $option );
	}

	private static function keep( $value, $old_value, $what ) {
		if ( self::$depth < 1 || ! self::carries_mask( $value ) ) {
			return $value;
		}
		// The credentials this row already holds, by fingerprint: only those
		// can come back, and only into this row.
		$held = array();
		$old  = (string) wp_json_encode( $old_value );
		foreach ( self::credentials() as $c => $fp ) {
			if ( false !== strpos( $old, substr( (string) wp_json_encode( (string) $c ), 1, -1 ) ) ) {
				$held[ self::MASK . ':' . $fp . ']' ] = (string) $c;
			}
		}
		$kept = 0;
		$out  = self::restore( $value, $held, $kept );
		if ( $kept ) {
			error_log( 'minn_egress_kept ' . $what . ' count=' . $kept ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		return $out;
	}

	private static function carries_mask( $v, $depth = 0 ) {
		if ( is_string( $v ) ) {
			return false !== strpos( $v, self::MASK . ':' );
		}
		if ( $depth < 32 && ( is_array( $v ) || is_object( $v ) ) ) {
			foreach ( (array) $v as $x ) {
				if ( self::carries_mask( $x, $depth + 1 ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Each held mask in a saved value back to its credential, wherever it sits. */
	private static function restore( $v, array $held, &$kept, $depth = 0 ) {
		if ( is_string( $v ) ) {
			if ( $held && false !== strpos( $v, self::MASK . ':' ) ) {
				$new   = strtr( $v, $held );
				$kept += ( $new !== $v ) ? 1 : 0;
				return $new;
			}
			return $v;
		}
		if ( $depth >= 32 ) {
			return $v;
		}
		if ( is_array( $v ) ) {
			foreach ( $v as $k => $x ) {
				$v[ $k ] = self::restore( $x, $held, $kept, $depth + 1 );
			}
		} elseif ( is_object( $v ) && ! $v instanceof Closure ) {
			$v = clone $v;
			foreach ( get_object_vars( $v ) as $k => $x ) {
				$v->$k = self::restore( $x, $held, $kept, $depth + 1 );
			}
		}
		return $v;
	}
}
