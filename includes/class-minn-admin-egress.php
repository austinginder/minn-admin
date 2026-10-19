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
 * A route that shortens a value for display cuts it with clip(), which masks
 * the whole value first: cut first, a key straddling the cut would no longer
 * be recognised and its first part would be served.
 *
 * The write side: while a minn-admin/v1 request runs, a save of an option
 * that carries "[redacted:<fingerprint>]" gets back the credential with that
 * fingerprint, but only at the place in the option that held it (the same
 * keys down to it), so a screen that showed a redacted value and saves it
 * back cannot overwrite it. A mask anywhere else, or one whose credential the
 * restore would leave where the browser no longer counts it as one (a key
 * moved under a name such as "title", text typed beside it), is a refused
 * save: the option keeps its stored value and the request answers 400. A
 * credential is recognised by the names along its path, so a moved copy
 * would stop being redacted.
 *
 * Every other store (snippet code, a post, a table row, a file) has no hook
 * to restore in, so its routes compare what was sent with the stored value
 * served the way a read serves it (served_as(), which Minn_Admin::unchanged()
 * asks), leave an untouched field as stored, and refuse a write that would
 * store a mask (refuse_masks()). An export file is refused rather than
 * handed out with a mask in it (refuse_export()).
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

class Minn_Admin_Egress {

	const MASK = '[redacted';

	/** A mask as the fence writes it: six hex characters of the credential's hash. */
	const TOKEN = '/\[redacted:[0-9a-f]{6}\]/';

	/** Credential => fingerprint, null until first needed. */
	private static $credentials = null;

	/** The object cache generation of the list, read once per request. */
	private static $generation = null;

	/** The row recording whether the list needs the wider scan rule (wide()). */
	const LOOSE = 'minn_admin_egress_loose';

	/** Values a route hands out on purpose, for the response being built. */
	private static $intended = array();

	/** How many minn-admin/v1 requests are running (a route may dispatch another). */
	private static $depth = 0;

	/** Rows the running request was refused a save of (a mask they could not take back). */
	private static $refused = array();

	/** Whether an option name is a credential row, per site and name, for this request. */
	private static $named = array();

	public static function init() {
		add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'enter' ), 0, 3 );
		add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'leave' ), PHP_INT_MAX, 3 );
		add_filter( 'rest_pre_echo_response', array( __CLASS__, 'scrub' ), PHP_INT_MAX, 3 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'scrub_headers' ), PHP_INT_MAX, 3 );
		add_filter( 'pre_update_option', array( __CLASS__, 'keep_option' ), PHP_INT_MAX, 3 );
		// A credential row that changes makes the list stale, and with a
		// persistent object cache the list outlives the request.
		foreach ( array( 'added_option', 'updated_option', 'deleted_option', 'add_site_option', 'update_site_option', 'delete_site_option' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'changed' ), PHP_INT_MAX, 1 );
		}
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
			if ( 0 === self::$depth ) {
				self::$refused = array();
			}
			++self::$depth;
		}
		return $response;
	}

	public static function leave( $response, $handler, $request ) {
		if ( ! self::ours( $request ) || self::$depth < 1 ) {
			return $response;
		}
		--self::$depth;
		if ( self::$depth > 0 || ! self::$refused ) {
			return $response;
		}
		$names         = array_values( array_unique( self::$refused ) );
		self::$refused = array();
		error_log( sprintf( 'minn_egress_refused user=%d route="%s %s" refused=%s', get_current_user_id(), $request->get_method(), $request->get_route(), implode( ',', $names ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		// The write fence's own refusal, when it refused too, says it first.
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		return new WP_Error(
			'minn_egress_mask_refused',
			__( 'Part of this change was not saved: it moves or changes a hidden key, shown as [redacted:…]. Type the real value instead.', 'minn-admin' ),
			array(
				'status'  => 400,
				'refused' => $names,
			)
		);
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

	/** Forget what was read and named (between tests). */
	public static function reset() {
		self::forget();
		self::$intended = array();
		self::$named    = array();
	}

	/**
	 * The build the list belongs to: Minn's version (its central lists) and
	 * the adapters' declarations, so a process running other code never
	 * reads a list or a shape record built without its stores.
	 */
	public static function stamp() {
		return ( defined( 'MINN_ADMIN_VERSION' ) ? MINN_ADMIN_VERSION : '' ) . '_' . ( class_exists( 'Minn_Admin_Kernel_Secrets', false ) ? Minn_Admin_Kernel_Secrets::hash() : '' );
	}

	/**
	 * The object cache key for the list: the build, and a generation every
	 * forget() moves on, so dropping the list reaches every process's copy
	 * whatever build it runs.
	 */
	public static function cache_key() {
		if ( null === self::$generation ) {
			$g                = wp_cache_get( 'egress_generation', 'minn_admin' );
			self::$generation = false === $g ? 0 : (int) $g;
		}
		return 'egress_credentials_' . self::stamp() . '_' . self::$generation;
	}

	/** An adapter declared credential stores: read the list, and name rows, again when next needed. */
	public static function declarations_changed() {
		self::$credentials = null;
		self::$named       = array();
	}

	/**
	 * Whether a response is scanned on the wider shape rule
	 * (Minn_Admin_DB::key_shaped()) rather than three digits and three
	 * letters. Only a list holding a credential the narrower rule would miss
	 * needs it, and the list costs tens of milliseconds to build without an
	 * object cache, so its build records the answer (with its stamp) in one
	 * autoloaded row. A record from another build, or none, reads as wide:
	 * the next scan builds the list and records it again. A credential row
	 * that changes drops the record (changed()).
	 */
	private static function wide() {
		$rec = get_option( self::LOOSE, '' );
		return ! ( is_string( $rec ) && self::stamp() . ':0' === $rec );
	}

	/**
	 * Record whether the list just built holds a credential the narrower
	 * rule would miss (Minn_Admin_DB calls this after each build).
	 *
	 * @param bool $loose Whether one does.
	 */
	public static function record_shapes( $loose ) {
		$rec = self::stamp() . ':' . ( $loose ? '1' : '0' );
		if ( get_option( self::LOOSE, '' ) !== $rec ) {
			update_option( self::LOOSE, $rec, true );
		}
	}

	/** Read the credentials again when next needed. */
	private static function forget() {
		self::$credentials = null;
		wp_cache_delete( self::cache_key(), 'minn_admin' );
		if ( false === wp_cache_incr( 'egress_generation', 1, 'minn_admin' ) ) {
			wp_cache_set( 'egress_generation', self::$generation + 1, 'minn_admin' );
		}
		self::$generation = null;
		if ( method_exists( 'Minn_Admin_DB', 'forget_credentials' ) ) {
			Minn_Admin_DB::forget_credentials();
		}
	}

	/**
	 * An option or network option was added, changed or deleted: when it is
	 * a credential row, the list is read again next time. Nothing is asked
	 * unless a list is held (this request's, or the object cache's). Rows
	 * are named only inside a minn-admin/v1 request and after init: naming
	 * them starts WooCommerce's gateways and the licence readers, which no
	 * other request (a front-end visit, cron writing its schedule) should pay
	 * for, and which before init would freeze the list before gateway
	 * plugins add theirs. Anywhere else the list is just dropped, and the
	 * next Minn response reads it again.
	 *
	 * @param string $option Option name.
	 */
	public static function changed( $option ) {
		if ( self::LOOSE === $option ) {
			return;
		}
		// A credential row the lists name changed: the wide() record may no
		// longer hold. Asked of the fixed and declared names only (no
		// gateway or licence reader starts here), on every option write.
		if ( false !== get_option( self::LOOSE, false ) && method_exists( 'Minn_Admin_DB', 'is_listed_option' ) && Minn_Admin_DB::is_listed_option( (string) $option ) ) {
			delete_option( self::LOOSE );
		}
		if ( null === self::$credentials && ( ! wp_using_ext_object_cache() || false === wp_cache_get( self::cache_key(), 'minn_admin' ) ) ) {
			return;
		}
		if ( ! did_action( 'init' ) || self::$depth < 1 ) {
			self::forget();
			return;
		}
		static $naming = false;
		$at = get_current_blog_id() . ':' . $option;
		if ( ! isset( self::$named[ $at ] ) ) {
			// Naming the rows can start a gateway that saves an option of its
			// own; that save drops the list rather than name rows again.
			if ( $naming ) {
				self::forget();
				return;
			}
			$naming = true;
			try {
				self::$named[ $at ] = method_exists( 'Minn_Admin_DB', 'is_credential_option' ) && Minn_Admin_DB::is_credential_option( (string) $option );
			} finally {
				$naming = false;
			}
		}
		if ( self::$named[ $at ] ) {
			self::forget();
		}
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
			$list              = wp_using_ext_object_cache() ? wp_cache_get( self::cache_key(), 'minn_admin' ) : false;
			if ( ! is_array( $list ) ) {
				$list = method_exists( 'Minn_Admin_DB', 'egress_credentials' ) ? Minn_Admin_DB::egress_credentials() : array();
				if ( wp_using_ext_object_cache() ) {
					wp_cache_set( self::cache_key(), $list, 'minn_admin', MINUTE_IN_SECONDS );
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

	/**
	 * The forms a credential takes inside JSON text: raw, URL-encoded, and
	 * the base64 cores of each, as [ form, pattern ] pairs. A credential is
	 * token characters only (Minn_Admin_DB::is_credential_leaf()), so the one
	 * character JSON escapes in it is a slash, and JSON text carried inside
	 * JSON text (an export file, a stored JSON string, a field holding JSON)
	 * escapes it again at every level: "\/", then "\\\/" (or "\\/" where an
	 * inner encoder left slashes alone). A form holding a slash is matched by
	 * a pattern taking any run of backslashes before each slash; the others
	 * by the string itself (pattern null).
	 *
	 * @param string $c Credential.
	 * @return array[]
	 */
	private static function forms( $c ) {
		$forms = array( $c => true, rawurlencode( $c ) => true );
		for ( $s = 0; $s < 3; $s++ ) {
			$core = base64_encode( substr( $c, $s, (int) floor( ( strlen( $c ) - $s ) / 3 ) * 3 ) );
			if ( strlen( $core ) >= 16 ) {
				$forms[ $core ]                      = true;
				$forms[ strtr( $core, '+/', '-_' ) ] = true;
			}
		}
		$out = array();
		foreach ( array_keys( $forms ) as $f ) {
			$f     = (string) $f;
			$out[] = array( $f, false === strpos( $f, '/' ) ? null : self::slash_pattern( $f ) );
		}
		return $out;
	}

	/** A form with a slash, matched whatever run of backslashes JSON put before each slash. */
	private static function slash_pattern( $f ) {
		return '#' . implode( '\\\\*/', array_map( function ( $part ) {
			return preg_quote( $part, '#' );
		}, explode( '/', $f ) ) ) . '#';
	}

	/**
	 * The text a form takes where it occurs in a value, or null: the form
	 * itself, or for one with a slash the first escaped copy found.
	 */
	private static function found( $text, array $form ) {
		list( $f, $re ) = $form;
		if ( null === $re ) {
			return false !== strpos( $text, $f ) ? $f : null;
		}
		// Only a pattern whose longest slash-free part occurs is run.
		$parts = explode( '/', $f );
		usort( $parts, function ( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		} );
		if ( '' !== $parts[0] && false === strpos( $text, $parts[0] ) ) {
			return null;
		}
		return preg_match( $re, $text, $m ) ? $m[0] : null;
	}

	/**
	 * Whether JSON text holds anything shaped like a key: a run of 16 or more
	 * token characters with three digits and three letters or, while the
	 * list holds a credential that rule would miss (wide()), one that
	 * Minn_Admin_DB::key_shaped() calls a key (the rule a declared value is
	 * held to, so no needle is one the scan skips), read
	 * with escaped slashes (at any depth) and URL-encoded slashes, pluses
	 * and equals decoded, and broken at query separators so
	 * "_wpnonce=<nonce>" is two short runs.
	 */
	private static function looks_keyed( $json ) {
		$text = str_ireplace( array( '%2B', '%2F', '%3D' ), array( '+', '/', '=' ), (string) preg_replace( '#\\\\+/#', '/', $json ) );
		if ( ! preg_match_all( '#[A-Za-z0-9_\-+/]{16,}#', $text, $m ) ) {
			return false;
		}
		foreach ( $m[0] as $run ) {
			if ( preg_match_all( '/\d/', $run ) >= 3 && preg_match_all( '/[A-Za-z]/', $run ) >= 3 ) {
				return true;
			}
		}
		if ( ! method_exists( 'Minn_Admin_DB', 'key_shaped' ) || ! self::wide() ) {
			return false;
		}
		foreach ( $m[0] as $run ) {
			if ( Minn_Admin_DB::key_shaped( $run ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * JSON text with every credential copy replaced, and how many there were.
	 * Intended values are set aside first and put back after.
	 *
	 * @param string $json   JSON text.
	 * @param bool   $answer A response (true): only when it looks keyed, and
	 *                       intended values pass. False masks every copy.
	 * @return array [ json, count ]
	 */
	private static function mask_json( $json, $answer = true ) {
		if ( ( $answer && ! self::looks_keyed( $json ) ) || ! self::credentials() ) {
			return array( $json, 0 );
		}
		$held = array();
		$i    = 0;
		foreach ( $answer ? array_keys( self::$intended ) : array() as $ok ) {
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
				if ( null === self::found( $json, $form ) ) {
					continue;
				}
				$json = null === $form[1]
					? str_replace( $form[0], self::MASK . ':' . $fp . ']', $json, $count )
					: (string) preg_replace( $form[1], self::MASK . ':' . $fp . ']', $json, -1, $count );
				$n   += $count;
			}
		}
		if ( $held ) {
			$json = strtr( $json, $held );
		}
		return array( $json, $n );
	}

	/**
	 * Text cut short for a response, with the credential copies in the WHOLE
	 * text masked first. The fence recognises a key only whole, so a cut
	 * made before it runs can keep the first part of one, which it then no
	 * longer matches. Masking first leaves the cut nothing to split but a
	 * mask, and a mask the cut would split is kept whole. Text that fits
	 * comes back as it is: the fence sees it whole in the response. A screen
	 * that shortens a stored or captured value asks this instead of cutting
	 * it itself.
	 *
	 * @param string $text The whole text.
	 * @param int    $keep Characters kept when the text is longer (bytes without mbstring).
	 * @param string $more Appended to cut text.
	 * @return string
	 */
	public static function clip( $text, $keep, $more = '…' ) {
		$text = (string) $text;
		$keep = max( 0, (int) $keep );
		$mb   = function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' );
		if ( ( $mb ? mb_strlen( $text, 'UTF-8' ) : strlen( $text ) ) <= $keep ) {
			return $text;
		}
		$json = wp_json_encode( $text );
		if ( false === $json ) {
			return '';
		}
		list( $masked, $n ) = self::mask_json( $json );
		if ( $n ) {
			$text = json_decode( $masked );
			if ( ! is_string( $text ) ) {
				return '';
			}
		}
		$cut = strlen( $mb ? mb_substr( $text, 0, $keep, 'UTF-8' ) : substr( $text, 0, $keep ) );
		if ( false !== strpos( $text, self::MASK ) && preg_match_all( self::TOKEN, $text, $m, PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m[0] as $hit ) {
				if ( $hit[1] < $cut && $cut < $hit[1] + strlen( $hit[0] ) ) {
					$cut = $hit[1] + strlen( $hit[0] );
				}
			}
		}
		return substr( $text, 0, $cut ) . $more;
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
		if ( self::$depth < 1 || ! self::tokens( $value ) ) {
			return $value;
		}
		$old = array();
		self::paths( $old_value, '', $old );
		$kept    = array();
		$allowed = 0;
		$out     = self::restore( $value, $old, $kept, $allowed );
		$moved   = self::tokens( $out ) > $allowed;
		// A credential restored where the browser no longer counts it as one
		// (text typed beside it, a JSON string rearranged around it) drops out
		// of the list, and the next response prints it.
		if ( ! $moved && $kept && method_exists( 'Minn_Admin_DB', 'credential_strings' ) ) {
			$before = Minn_Admin_DB::credential_strings( (string) maybe_serialize( $old_value ) );
			$after  = Minn_Admin_DB::credential_strings( (string) maybe_serialize( $out ) );
			foreach ( array_keys( $kept ) as $c ) {
				if ( isset( $before[ $c ] ) && ! isset( $after[ $c ] ) ) {
					$moved = true;
				}
			}
		}
		if ( $moved ) {
			self::$refused[] = $what;
			return $old_value;
		}
		if ( $kept ) {
			error_log( 'minn_egress_kept ' . $what . ' count=' . count( $kept ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		return $out;
	}

	/** How many masks a value holds anywhere: strings, keys, deep or non-public properties alike. */
	private static function tokens( $v ) {
		if ( is_string( $v ) ) {
			return (int) preg_match_all( self::TOKEN, $v );
		}
		if ( ! is_array( $v ) && ! is_object( $v ) ) {
			return 0;
		}
		try {
			$text = serialize( $v ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- core serializes the same value to store it.
		} catch ( \Throwable $e ) {
			$text = (string) wp_json_encode( $v );
		}
		return (int) preg_match_all( self::TOKEN, $text );
	}

	/**
	 * A path's next step. A list position is "#": a credential is recognised
	 * by the names along its path and a position names nothing, so a mask may
	 * follow its item when a list is reordered or shortened, never move to
	 * another name. A name carries its length, so no key can spell a path.
	 */
	private static function step( $path, $k ) {
		return $path . ( ( is_int( $k ) || ctype_digit( (string) $k ) ) ? '/#' : '/' . strlen( (string) $k ) . ':' . $k );
	}

	/** Every place in a value, path => the strings there (list items joined by NUL, which no credential or mask holds). */
	private static function paths( $v, $path, array &$out, $depth = 0 ) {
		$out[ $path ] = ( isset( $out[ $path ] ) ? $out[ $path ] . "\0" : '' ) . ( is_string( $v ) ? $v : '' );
		if ( $depth < 32 && ( is_array( $v ) || ( is_object( $v ) && ! $v instanceof Closure ) ) ) {
			foreach ( is_array( $v ) ? $v : get_object_vars( $v ) as $k => $x ) {
				self::paths( $x, self::step( $path, $k ), $out, $depth + 1 );
			}
		}
	}

	/**
	 * Each mask in a saved value back to its credential, only where the old
	 * value held that credential at the same path. $allowed counts the masks
	 * left that the old value already held as text in the same place (a mask
	 * stored as text earlier saves back as it is); every other mask left
	 * refuses the save.
	 */
	private static function restore( $v, array $old, array &$kept, &$allowed, $path = '', $depth = 0 ) {
		if ( is_string( $v ) ) {
			if ( ! preg_match( self::TOKEN, $v ) ) {
				return $v;
			}
			$was  = isset( $old[ $path ] ) ? $old[ $path ] : '';
			$held = array();
			$whom = array();
			foreach ( '' === $was ? array() : self::credentials() as $c => $fp ) {
				// The bytes the old value holds there: the credential, or a
				// JSON string's escaped copy of it (a response masks both).
				$at = self::found( $was, self::forms( (string) $c )[0] );
				if ( null !== $at ) {
					$held[ self::MASK . ':' . $fp . ']' ] = $at;
					// Kept under the credential itself, not the bytes restored:
					// keep()'s moved check looks it up in the decoded list.
					$whom[ self::MASK . ':' . $fp . ']' ] = (string) $c;
				}
			}
			$new = $held ? strtr( $v, $held ) : $v;
			foreach ( $whom as $mask => $c ) {
				if ( false !== strpos( $v, $mask ) ) {
					$kept[ $c ] = true;
				}
			}
			if ( preg_match_all( self::TOKEN, $new, $left ) ) {
				foreach ( $left[0] as $mask ) {
					$allowed += ( '' !== $was && false !== strpos( $was, $mask ) ) ? 1 : 0;
				}
			}
			return $new;
		}
		if ( $depth >= 32 ) {
			return $v;
		}
		$walk = function ( $k, $x ) use ( $old, &$kept, &$allowed, $path, $depth ) {
			$at = self::step( $path, $k );
			// A mask in a key stays text: only one the old value had there.
			if ( is_string( $k ) && array_key_exists( $at, $old ) ) {
				$allowed += (int) preg_match_all( self::TOKEN, $k );
			}
			return self::restore( $x, $old, $kept, $allowed, $at, $depth + 1 );
		};
		if ( is_array( $v ) ) {
			foreach ( $v as $k => $x ) {
				$v[ $k ] = $walk( $k, $x );
			}
		} elseif ( is_object( $v ) && ! $v instanceof Closure ) {
			$v = clone $v;
			foreach ( get_object_vars( $v ) as $k => $x ) {
				$v->$k = $walk( $k, $x );
			}
		}
		return $v;
	}

	/**
	 * Whether a value sent back is the stored one as a minn-admin/v1 read
	 * served it. Every response shows each known credential as its mask, so a
	 * field a dialog sends back untouched arrives masked; it equals the whole
	 * stored value masked, never a part of it, and nothing is restored.
	 *
	 * @param mixed $sent   The value sent.
	 * @param mixed $stored The stored value it would replace.
	 * @return bool
	 */
	public static function served_as( $sent, $stored ) {
		$json = wp_json_encode( $sent );
		if ( ! is_string( $json ) || ! preg_match( self::TOKEN, $json ) ) {
			return false;
		}
		$was = wp_json_encode( $stored );
		return is_string( $was ) && self::mask_json( $was, false )[0] === $json;
	}

	/**
	 * A write outside the options API that would store a mask. Each value
	 * about to be written is held against the stored value it replaces: a
	 * mask that stored value already holds as text may stay, any other is a
	 * value a read showed masked and someone saved, and writing it would put
	 * "[redacted:…]" where the credential was. Nothing is written then.
	 *
	 * @param array $writes Values about to be written, by field.
	 * @param array $stored The stored values they replace, by the same field.
	 * @return WP_Error|null
	 */
	public static function refuse_masks( array $writes, array $stored ) {
		foreach ( $writes as $field => $v ) {
			if ( ! preg_match_all( self::TOKEN, (string) ( is_string( $v ) ? $v : wp_json_encode( $v ) ), $m ) ) {
				continue;
			}
			$was = isset( $stored[ $field ] ) ? $stored[ $field ] : '';
			$was = (string) ( is_string( $was ) ? $was : wp_json_encode( $was ) );
			foreach ( $m[0] as $mask ) {
				if ( false === strpos( $was, $mask ) ) {
					error_log( 'minn_egress_refused field=' . $field ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					return new WP_Error( 'minn_egress_mask', __( 'Nothing was saved: this change holds a hidden key, shown as [redacted:…], and saving it would put that placeholder where the key was. Type the real value in its place, or leave that part as it was.', 'minn-admin' ), array( 'status' => 400 ) );
				}
			}
		}
		return null;
	}

	/**
	 * A file built for download (an export) that holds a credential. The
	 * response would carry it masked, which no importer can use, so the file
	 * is refused rather than handed out silently broken.
	 *
	 * @param mixed $content The file's content as the response would carry it.
	 * @return WP_Error|null
	 */
	public static function refuse_export( $content ) {
		if ( ! self::mask_json( (string) wp_json_encode( $content ), false )[1] ) {
			return null;
		}
		return new WP_Error( 'minn_egress_export', __( 'This holds a saved key (an API or licence key), and Minn never hands one out, so the export file was not made: it would not import correctly without the key.', 'minn-admin' ), array( 'status' => 409 ) );
	}
}
