<?php
/**
 * The settings tier: settings screens declared, saved by the kernel.
 *
 * A settings screen is a set of stored values a person edits and saves
 * together: a plugin's settings tabs, an options page, a form's emails. Each
 * one used to write its own save, and each save had to remember the same
 * rules in the same order. The ones that forgot shipped as findings: a field
 * the caller may not change skipped while the rest stored under "Settings
 * saved", a vendor validator that refused a later value after an earlier
 * one was written, a hidden key's placeholder written where the key was. The
 * tier owns that order; the adapter declares only what is its vendor's:
 *
 *   minn_admin_settings( 'perfmatters', array(
 *     'route'    => 'perfmatters/settings/(?P<tab>[a-z]+)',  // under minn-admin/v1, GET and POST
 *     'active'   => callable(): bool,                   // routes register only when true
 *     'gate'     => callable( WP_REST_Request ): bool,  // the screen's gate; only a strict true opens it
 *     'target'   => callable( WP_REST_Request ): array|null|WP_Error,
 *                   // optional: what the URL addresses (a page, a form), merged into the
 *                   // context every other callable gets; null is a 404
 *     'layout'   => callable( array $ctx ): array|null|WP_Error,
 *                   // the screen as offered: array( 'groups' => array( array( 'title' => …,
 *                   // 'fields' => array( field, … ), 'locked' => … ) ), 'adminUrl' => … ).
 *                   // $ctx['tab'] is the tab the URL names (null for a screen without
 *                   // tabs, or for the whole screen when PHP saves it); null for a tab
 *                   // that does not exist
 *     'read'     => callable( array $field, array $ctx ): mixed,   // the stored value as the page shows it
 *     'same'     => callable( array $field, mixed $sent, mixed $served, array $ctx ): bool,
 *                   // optional: the plugin's own reading of a value sent back as served
 *                   // (a stored shape its control cannot show), asked when the tier's
 *                   // compare finds a difference
 *     'view'     => callable( array $ctx ): array,     // optional: a body of the screen's own;
 *                   // without it the body is the layout with 'values' (read) after its groups
 *     'sent'     => callable( array $body, array $ctx ): array|WP_Error,
 *                   // optional: the body's values by field key (else $body['values']); the
 *                   // values the page loaded are read the same way from $body['base']
 *     'may'      => callable( array $field, array $ctx ): bool,   // optional: a per-field gate
 *     'validate' => callable( array $changes, array $fields, array $ctx ): null|array|WP_Error,
 *                   // optional: the plugin's own validator; an array it answers is what
 *                   // write() gets in place of the changes (the values as the store takes them)
 *     'restore'  => true,   // optional: the store is options-API, where a placeholder sent back
 *                   // goes back to its credential (see step 5); without it a store with no
 *                   // restore (form meta, a table) refuses any placeholder left in an edit
 *     'write'    => callable( array $changes, array $fields, array $ctx ): true|WP_Error,
 *     'labels'   => array( 'may' => callable( string[] $labels ): string ),   // the gate's refusal
 *   ) );
 *
 * read() must serve each value from the place write() writes it: the
 * compare, the stale check and the restore all take the stored value from
 * what read() serves, and the restore puts a credential back from there.
 *
 * A field is the shared settings form's: 'key' (or 'name', which the form
 * also reads), 'label', a 'type' from TYPES and its presentation keys. A key that starts with "_" is the adapter's own
 * (where the value lives) and never leaves. '_raw' => true marks a value the
 * plugin prints into its pages unescaped: changing one takes unfiltered_html
 * and code edits left on, the rule every code-authoring surface keeps. A
 * field of another type is not offered at all.
 *
 * A save takes a JSON body only (400 invalid_body otherwise) and, before
 * anything is written:
 *   1. only the fields the layout offers are read from the body;
 *   2. a value equal to what read() serves for it is dropped: an untouched
 *      value, and a credential sent back as its [redacted:…] placeholder
 *      (Minn_Admin::unchanged() against the stored value);
 *   3. the per-field gates ('_raw' and 'may') answer 403;
 *   4. when the body carries the values the page loaded ('base'), a field
 *      whose value as served (credentials masked, so a base can never test
 *      a guess at one) moved since answers 409, so the person reloads; a
 *      body without one (an app cached from before) is saved as before;
 *   5. a placeholder left in an edited value: on a 'restore' screen it
 *      goes through the options API's own restore
 *      (Minn_Admin_Egress::restore_value(), what update_option() runs), so
 *      it goes back only where its credential was; one it cannot put back
 *      answers 400 minn_egress_mask, as does any placeholder on a screen
 *      without 'restore';
 *   6. the plugin's validator answers what it answers.
 * A refusal names its fields (data.fields: key, label, message; data.field:
 * the first key, for a page that puts the message on a field), and nothing
 * is written. Then write() runs once with every change, and the answer is
 * the screen as stored now.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

class Minn_Admin_Kernel_Settings {

	/** The field types the settings form draws (formControlHtml()), and the aliases it reads. */
	const TYPES = array(
		'text', 'textarea', 'number', 'email', 'url', 'color', 'select', 'combobox', 'toggle',
		'multicheck', 'tags', 'date', 'datetime', 'time', 'image', 'file', 'gallery', 'link',
		'suggest', 'relation', 'rows', 'flex', 'wysiwyg', 'hidden',
		'true_false', 'range', 'radio', 'color_picker',
	);

	/** The keys a declaration may hold. */
	const KEYS = array( 'route', 'active', 'gate', 'target', 'layout', 'read', 'same', 'view', 'sent', 'may', 'validate', 'restore', 'write', 'labels' );

	/** The keys a declaration must hold. */
	const REQUIRED = array( 'route', 'gate', 'layout', 'read', 'write' );

	/** Declared screens, by id. */
	private static $screens = array();

	/** Declarations refused, and why. */
	private static $refused = array();

	/** The last save's steps, for the conformance runner. */
	private static $journal = array();

	/** When true, a save stops where it would write (the conformance runner, on live screens). */
	private static $dry = false;

	/**
	 * Declare a screen. One the tier cannot read whole gets no route.
	 *
	 * @param string $id  Screen id.
	 * @param array  $def Declaration (this file's header lists the keys).
	 * @return bool Whether it was declared.
	 */
	public static function declare_screen( $id, array $def ) {
		$id  = (string) $id;
		$why = self::unreadable( $def );
		if ( '' === $why && isset( self::$screens[ $id ] ) ) {
			$why = 'it is declared twice';
		}
		if ( '' !== $why ) {
			self::$refused[] = "{$id}: {$why}";
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( 'Minn Admin minn_admin_settings: ' . $id . ': ' . $why . ', so it has no route.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return false;
		}
		$def['route']         = trim( (string) $def['route'], '/' );
		self::$screens[ $id ] = $def;
		$gate                 = $def['gate'];
		$open                 = function ( WP_REST_Request $r ) use ( $gate ) {
			return Minn_Admin_Kernel::yes( $gate, $r );
		};
		$common = array( 'gate' => $open );
		if ( array_key_exists( 'active', $def ) ) {
			$common['active'] = $def['active'];
		}
		Minn_Admin_Kernel::endpoint( $def['route'], $common + array(
			'methods' => 'GET',
			'run'     => function ( WP_REST_Request $r ) use ( $id ) {
				return Minn_Admin_Kernel_Settings::get( $id, $r );
			},
		) );
		Minn_Admin_Kernel::endpoint( $def['route'], $common + array(
			'methods' => 'POST',
			'run'     => function ( WP_REST_Request $r ) use ( $id ) {
				return Minn_Admin_Kernel_Settings::post( $id, $r );
			},
		) );
		return true;
	}

	/** Why a declaration cannot be read whole, or ''. */
	private static function unreadable( array $def ) {
		foreach ( array_keys( $def ) as $k ) {
			if ( ! in_array( $k, self::KEYS, true ) ) {
				return "it holds \"{$k}\", which the tier does not read";
			}
		}
		foreach ( self::REQUIRED as $k ) {
			if ( ! isset( $def[ $k ] ) ) {
				return "it has no {$k}";
			}
		}
		if ( ! is_string( $def['route'] ) || '' === trim( $def['route'], '/' ) ) {
			return 'its route is not a path';
		}
		foreach ( array( 'gate', 'target', 'layout', 'read', 'same', 'view', 'sent', 'may', 'validate', 'write' ) as $k ) {
			if ( isset( $def[ $k ] ) && ! is_callable( $def[ $k ] ) ) {
				return "its {$k} cannot be called";
			}
		}
		if ( isset( $def['labels'] ) && ! is_array( $def['labels'] ) ) {
			return 'its labels are not a list';
		}
		if ( isset( $def['restore'] ) && ! is_bool( $def['restore'] ) ) {
			return 'its restore is not true or false';
		}
		if ( ! self::takes_request( $def['gate'] ) ) {
			return 'its gate takes something other than the request it is asked with';
		}
		return '';
	}

	/**
	 * Whether a gate takes the request and nothing else (or nothing): the
	 * routes ask it with the request, so a gate written for another argument
	 * would read the request as that argument.
	 */
	private static function takes_request( $gate ) {
		try {
			if ( is_string( $gate ) && false !== strpos( $gate, '::' ) ) {
				$gate = explode( '::', $gate, 2 );
			}
			if ( is_array( $gate ) ) {
				$rf = new ReflectionMethod( $gate[0], $gate[1] );
			} elseif ( is_object( $gate ) && ! $gate instanceof Closure ) {
				$rf = new ReflectionMethod( $gate, '__invoke' );
			} else {
				$rf = new ReflectionFunction( $gate );
			}
		} catch ( \Throwable $e ) {
			return false;
		}
		foreach ( $rf->getParameters() as $prm ) {
			$t = $prm->getType();
			if ( false === strpos( $t instanceof ReflectionNamedType ? $t->getName() : (string) $t, 'WP_REST_Request' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a switch is on, the one reading of on/off the compare and the
	 * writes share: a switch sent as "off" or "false" is off everywhere.
	 *
	 * @param mixed $v Value.
	 * @return bool
	 */
	public static function on( $v ) {
		if ( is_string( $v ) ) {
			return ! in_array( strtolower( trim( $v ) ), array( '', '0', 'false', 'off' ), true );
		}
		return ! empty( $v );
	}

	/** A value as a minn-admin/v1 response shows it: every credential copy masked. */
	private static function shown( $v ) {
		return Minn_Admin_Egress::masked( $v );
	}

	/** Declared screens, for the conformance runner and the inventory. */
	public static function screens() {
		return self::$screens;
	}

	/** Declarations refused this request, in order. */
	public static function refused() {
		return self::$refused;
	}

	/** The last save's steps: id, steps taken, changed and refused keys. */
	public static function journal() {
		return self::$journal;
	}

	/**
	 * Stop every save where it would write, or write again. PHP only: the
	 * conformance runner drives live screens with it.
	 *
	 * @param bool $on Whether saves stop before writing.
	 */
	public static function dry( $on ) {
		self::$dry = (bool) $on;
	}

	/**
	 * The context a screen's callables get: the tab the URL names, the
	 * target's keys, and a memo for one pass (an object, so a callable can
	 * keep a read for the next one).
	 */
	private static function context( array $def, ?WP_REST_Request $r = null, array $given = array() ) {
		$ctx = array( 'tab' => null );
		if ( $r && false !== strpos( $def['route'], '(?P<tab>' ) ) {
			$ctx['tab'] = (string) Minn_Admin::path_param( $r, 'tab' );
		}
		if ( $r && isset( $def['target'] ) ) {
			$t = call_user_func( $def['target'], $r );
			if ( is_wp_error( $t ) ) {
				return $t;
			}
			if ( ! is_array( $t ) ) {
				return Minn_Admin_Kernel::fail( 'not_found', __( 'Not found.', 'minn-admin' ), 404 );
			}
			$ctx = array_merge( $ctx, $t );
		}
		$ctx           = array_merge( $ctx, $given );
		$ctx['memo']   = new ArrayObject();
		$ctx['screen'] = isset( $ctx['screen'] ) ? $ctx['screen'] : '';
		return $ctx;
	}

	/**
	 * The screen's layout with its offered fields: the groups with each
	 * field of a known type, and those fields by key.
	 *
	 * @return array|WP_Error array( layout, fields by key )
	 */
	private static function layout( array $def, array $ctx ) {
		$layout = call_user_func( $def['layout'], $ctx );
		if ( is_wp_error( $layout ) ) {
			return $layout;
		}
		if ( ! is_array( $layout ) ) {
			return Minn_Admin_Kernel::fail( 'minn_no_tab', __( 'Unknown settings tab.', 'minn-admin' ), 404 );
		}
		$fields = array();
		$groups = array();
		foreach ( (array) ( $layout['groups'] ?? array() ) as $g ) {
			if ( ! is_array( $g ) ) {
				continue;
			}
			$kept = array();
			foreach ( (array) ( $g['fields'] ?? array() ) as $f ) {
				if ( ! is_array( $f ) ) {
					continue;
				}
				$fkey = $f['key'] ?? ( $f['name'] ?? null );
				if ( ! is_scalar( $fkey ) || '' === (string) $fkey ) {
					continue;
				}
				// A type the form cannot draw is a field nobody can edit here,
				// so it is neither offered nor taken.
				if ( ! in_array( $f['type'] ?? 'text', self::TYPES, true ) ) {
					continue;
				}
				$fields[ (string) $fkey ] = $f;
				$kept[]                   = $f;
			}
			$g['fields'] = $kept;
			$groups[]    = $g;
		}
		$layout['groups'] = $groups;
		return array( $layout, $fields );
	}

	/** A field as it leaves: none of the adapter's own keys. */
	private static function public_field( array $f ) {
		foreach ( array_keys( $f ) as $k ) {
			if ( is_string( $k ) && 0 === strpos( $k, '_' ) ) {
				unset( $f[ $k ] );
			}
		}
		return $f;
	}

	/** The screen's body: its own view, or the layout with the values read() serves. */
	private static function body( array $def, array $ctx ) {
		$built = self::layout( $def, $ctx );
		if ( is_wp_error( $built ) ) {
			return $built;
		}
		if ( isset( $def['view'] ) ) {
			return call_user_func( $def['view'], $ctx );
		}
		list( $layout, $fields ) = $built;
		$values                  = array();
		foreach ( $fields as $key => $f ) {
			$values[ $key ] = call_user_func( $def['read'], $f, $ctx );
		}
		foreach ( $layout['groups'] as $i => $g ) {
			$layout['groups'][ $i ]['fields'] = array_map( array( __CLASS__, 'public_field' ), $g['fields'] );
		}
		$out = array( 'groups' => $layout['groups'], 'values' => $values );
		unset( $layout['groups'], $layout['values'] );
		return $out + $layout;
	}

	/**
	 * What a screen offers at a route and what read() serves for each field,
	 * before the egress fence: for the conformance runner.
	 *
	 * @param string          $id Screen id.
	 * @param WP_REST_Request $r  A request for the screen's route.
	 * @return array|WP_Error { fields: by key, values: by key }
	 */
	public static function served( $id, WP_REST_Request $r ) {
		if ( ! isset( self::$screens[ $id ] ) ) {
			return Minn_Admin_Kernel::fail( 'not_found', __( 'Not found.', 'minn-admin' ), 404 );
		}
		$def = self::$screens[ $id ];
		// A request built by hand has not been matched to its route yet.
		if ( ! $r->get_url_params() && preg_match( '#^/minn-admin/v1/' . $def['route'] . '$#', $r->get_route(), $m ) ) {
			$r->set_url_params( array_filter( $m, 'is_string', ARRAY_FILTER_USE_KEY ) );
		}
		$ctx = self::context( $def, $r, array( 'screen' => $id ) );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		$built = self::layout( $def, $ctx );
		if ( is_wp_error( $built ) ) {
			return $built;
		}
		$values = array();
		foreach ( $built[1] as $key => $f ) {
			$values[ $key ] = call_user_func( $def['read'], $f, $ctx );
		}
		return array(
			'fields' => $built[1],
			'values' => $values,
		);
	}

	/** GET: the screen as stored. */
	public static function get( $id, WP_REST_Request $r ) {
		$def = self::$screens[ $id ];
		$ctx = self::context( $def, $r, array( 'screen' => $id ) );
		return is_wp_error( $ctx ) ? $ctx : self::body( $def, $ctx );
	}

	/** POST: save, then the screen as stored. */
	public static function post( $id, WP_REST_Request $r ) {
		$def = self::$screens[ $id ];
		$ctx = self::context( $def, $r, array( 'screen' => $id ) );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		// The body only, as JSON: a form-encoded save would read as no change
		// and answer as saved.
		$body = $r->get_json_params();
		if ( ! is_array( $body ) ) {
			return Minn_Admin_Kernel::fail( 'invalid_body', __( 'Send the values as JSON.', 'minn-admin' ), 400 );
		}
		$saved = self::save_in( $id, $def, $body, $ctx );
		if ( is_wp_error( $saved ) ) {
			return $saved;
		}
		// Read again from the store, nothing remembered from before the write.
		$ctx['memo'] = new ArrayObject();
		return self::body( $def, $ctx );
	}

	/**
	 * Save a screen from PHP: the same path a POST takes, without the route's
	 * gate (the caller is code, not a request).
	 *
	 * @param string $id   Screen id.
	 * @param array  $body The body a POST would carry.
	 * @param array  $ctx  What the target would answer, and the tab (null: the whole screen).
	 * @return true|WP_Error
	 */
	public static function save( $id, array $body, array $ctx = array() ) {
		if ( ! isset( self::$screens[ $id ] ) ) {
			return Minn_Admin_Kernel::fail( 'not_found', __( 'Not found.', 'minn-admin' ), 404 );
		}
		$def = self::$screens[ $id ];
		$ctx = self::context( $def, null, $ctx + array( 'screen' => $id ) );
		return self::save_in( $id, $def, $body, $ctx );
	}

	/**
	 * A screen's body from PHP, as its GET answers it.
	 *
	 * @param string $id  Screen id.
	 * @param array  $ctx What the screen's target answers, and 'tab'.
	 * @return array|WP_Error
	 */
	public static function page( $id, array $ctx = array() ) {
		if ( ! isset( self::$screens[ $id ] ) ) {
			return Minn_Admin_Kernel::fail( 'not_found', __( 'Not found.', 'minn-admin' ), 404 );
		}
		$def = self::$screens[ $id ];
		return self::body( $def, self::context( $def, null, $ctx + array( 'screen' => $id ) ) );
	}

	/** How a field's sent value is compared with what was served. */
	private static function kind( array $f ) {
		$t = $f['type'] ?? 'text';
		if ( in_array( $t, array( 'toggle', 'true_false' ), true ) ) {
			return 'bool';
		}
		if ( in_array( $t, array( 'number', 'range' ), true ) ) {
			return 'number';
		}
		if ( in_array( $t, array( 'multicheck', 'tags' ), true ) ) {
			return 'list';
		}
		return 'text';
	}

	/**
	 * Whether a value sent is the one served: Minn_Admin::unchanged() for the
	 * field's kind (a credential as its placeholder included), then the
	 * plugin's own reading when it declares one.
	 */
	private static function same( array $def, array $field, $sent, $served, array $ctx ) {
		// Compared as the page received it: JSON keeps no difference between
		// an empty object and an empty list, so neither may the compare.
		if ( is_array( $served ) || is_object( $served ) ) {
			$served = json_decode( (string) wp_json_encode( $served ), true );
		}
		$kind = self::kind( $field );
		if ( 'bool' === $kind ? self::on( $sent ) === self::on( $served ) : Minn_Admin::unchanged( $sent, $served, $kind ) ) {
			return true;
		}
		return isset( $def['same'] ) && Minn_Admin_Kernel::yes( $def['same'], $field, $sent, $served, $ctx );
	}

	/** The body's values by field key, through the screen's own reader when it has one. */
	private static function sent( array $def, $body, array $ctx ) {
		$body = is_array( $body ) ? $body : array();
		if ( isset( $def['sent'] ) ) {
			$out = call_user_func( $def['sent'], $body, $ctx );
			return is_wp_error( $out ) ? $out : ( is_array( $out ) ? $out : array() );
		}
		return isset( $body['values'] ) && is_array( $body['values'] ) ? $body['values'] : array();
	}

	/**
	 * A refusal that names its fields, with nothing written.
	 *
	 * @param WP_Error $e      The refusal.
	 * @param string[] $keys   Fields it refuses, when the error does not say.
	 * @param array    $fields Offered fields by key.
	 * @return WP_Error
	 */
	private static function named( WP_Error $e, array $keys, array $fields ) {
		$data  = $e->get_error_data();
		$data  = is_array( $data ) ? $data : array();
		$named = array();
		foreach ( (array) ( $data['fields'] ?? array() ) as $one ) {
			$k = is_array( $one ) ? (string) ( $one['key'] ?? '' ) : ( is_scalar( $one ) ? (string) $one : '' );
			if ( '' !== $k ) {
				$named[ $k ] = is_array( $one ) && isset( $one['message'] ) ? (string) $one['message'] : '';
			}
		}
		if ( ! $named && isset( $data['field'] ) && is_scalar( $data['field'] ) && '' !== (string) $data['field'] ) {
			$named[ (string) $data['field'] ] = '';
		}
		// A refusal that cannot say which field refuses every one it was asked about.
		if ( ! $named ) {
			$named = array_fill_keys( $keys, '' );
		}
		$list = array();
		foreach ( $named as $k => $message ) {
			$label  = isset( $fields[ $k ]['label'] ) && '' !== (string) $fields[ $k ]['label'] ? (string) $fields[ $k ]['label'] : (string) $k;
			$list[] = array( 'key' => (string) $k, 'label' => $label, 'message' => '' !== $message ? $message : $e->get_error_message() );
		}
		$data['status'] = isset( $data['status'] ) ? (int) $data['status'] : 400;
		$data['fields'] = $list;
		if ( ! isset( $data['field'] ) && $list ) {
			$data['field'] = $list[0]['key'];
		}
		self::$journal['refused'] = array_column( $list, 'key' );
		return new WP_Error( $e->get_error_code(), $e->get_error_message(), $data );
	}

	/** The labels of some fields, for a message. */
	private static function labels( array $keys, array $fields ) {
		return array_map( function ( $k ) use ( $fields ) {
			return isset( $fields[ $k ]['label'] ) && '' !== (string) $fields[ $k ]['label'] ? (string) $fields[ $k ]['label'] : (string) $k;
		}, $keys );
	}

	/** Whether the caller may change a value the plugin prints into its pages unescaped. */
	private static function may_raw() {
		return current_user_can( 'unfiltered_html' ) && Minn_Admin::code_edits_allowed();
	}

	/** The save itself: every check, then one write. */
	private static function save_in( $id, array $def, array $body, $ctx ) {
		self::$journal = array( 'id' => $id, 'steps' => array(), 'changes' => array(), 'refused' => array(), 'wrote' => false );
		if ( is_wp_error( $ctx ) ) {
			return $ctx;
		}
		$built = self::layout( $def, $ctx );
		if ( is_wp_error( $built ) ) {
			return $built;
		}
		$fields = $built[1];
		$values = self::sent( $def, $body, $ctx );
		if ( is_wp_error( $values ) ) {
			return self::named( $values, array(), $fields );
		}

		// 1-2. What changed: an offered field whose value is not the one served.
		self::$journal['steps'][] = 'compare';
		$changes = array();
		$served  = array();
		foreach ( $values as $key => $v ) {
			$key = (string) $key;
			if ( ! isset( $fields[ $key ] ) ) {
				continue;
			}
			$served[ $key ] = call_user_func( $def['read'], $fields[ $key ], $ctx );
			// A value holding a credential in clear is a change even when it
			// equals the stored one: a page never sends one (it was shown the
			// placeholder), and as no change it would skip the gates a wrong
			// guess meets, so the answer would tell the stored key.
			if ( ! self::same( $def, $fields[ $key ], $v, $served[ $key ], $ctx ) || Minn_Admin_Egress::masked( $v ) !== $v ) {
				$changes[ $key ] = $v;
			}
		}
		self::$journal['changes'] = array_keys( $changes );

		// 3. Per-field gates, beyond the screen's, before anything else is
		// answered about a field the caller may not change.
		self::$journal['steps'][] = 'gates';
		$raw    = array();
		$denied = array();
		foreach ( array_keys( $changes ) as $key ) {
			if ( ! empty( $fields[ $key ]['_raw'] ) && ! self::may_raw() ) {
				$raw[] = $key;
			} elseif ( isset( $def['may'] ) && ! Minn_Admin_Kernel::yes( $def['may'], $fields[ $key ], $ctx ) ) {
				$denied[] = $key;
			}
		}
		if ( $raw ) {
			$names = self::labels( $raw, $fields );
			return self::named(
				new WP_Error(
					'minn_settings_raw_output',
					sprintf(
						/* translators: %s: comma-separated setting names. */
						_n(
							'Nothing was saved. %s goes into your pages as raw code, which your account cannot change here.',
							'Nothing was saved. %s go into your pages as raw code, which your account cannot change here.',
							count( $names ),
							'minn-admin'
						),
						implode( ', ', $names )
					),
					array( 'status' => 403 )
				),
				$raw,
				$fields
			);
		}
		if ( $denied ) {
			$names   = self::labels( $denied, $fields );
			$message = isset( $def['labels']['may'] ) && is_callable( $def['labels']['may'] ) ? (string) call_user_func( $def['labels']['may'], $names ) : '';
			if ( '' === $message ) {
				$message = sprintf(
					/* translators: %s: comma-separated setting names. */
					_n( 'Nothing was saved. Your account cannot change %s here.', 'Nothing was saved. Your account cannot change %s here.', count( $names ), 'minn-admin' ),
					implode( ', ', $names )
				);
			}
			return self::named( new WP_Error( 'minn_settings_forbidden', $message, array( 'status' => 403 ) ), $denied, $fields );
		}

		// 4. A stale copy: the page loaded a value that is no longer stored,
		// compared as the page was shown it, credentials masked.
		if ( isset( $body['base'] ) && is_array( $body['base'] ) ) {
			self::$journal['steps'][] = 'stale';
			$base = isset( $def['sent'] ) ? self::sent( $def, $body['base'], $ctx ) : $body['base'];
			if ( is_wp_error( $base ) ) {
				return self::named( $base, array(), $fields );
			}
			// Every key sent with a base, changed or not: asked only of the
			// changes, a value sent equal to the stored one would skip the
			// check that a different value meets, and the answer would tell
			// which value is stored.
			$moved = array();
			foreach ( $served as $key => $was ) {
				if ( array_key_exists( $key, $base ) && ! self::same( $def, $fields[ $key ], $base[ $key ], self::shown( $was ), $ctx ) ) {
					$moved[] = $key;
				}
			}
			if ( $moved ) {
				return self::named( new WP_Error( 'minn_stale', __( 'This changed since you opened it. Reload to get the latest version, then make your changes again.', 'minn-admin' ), array( 'status' => 409 ) ), $moved, $fields );
			}
		}

		if ( ! $changes ) {
			return true;
		}

		// 5. A hidden key's placeholder left in an edited value.
		self::$journal['steps'][] = 'masks';
		$masked = array();
		$error  = null;
		foreach ( $changes as $key => $v ) {
			if ( ! empty( $def['restore'] ) ) {
				// The options API's own restore, the one update_option() runs.
				$back = Minn_Admin_Egress::restore_value( $v, $served[ $key ] );
				if ( null !== $back ) {
					$changes[ $key ] = $back[0];
					continue;
				}
				$e = new WP_Error( 'minn_egress_mask', __( 'Nothing was saved: this change holds a hidden key, shown as [redacted:…], and saving it would put that placeholder where the key was. Type the real value in its place, or leave that part as it was.', 'minn-admin' ), array( 'status' => 400 ) );
			} else {
				$e = Minn_Admin_Egress::refuse_masks( array( $key => $v ), array( $key => $served[ $key ] ) );
			}
			if ( $e ) {
				$masked[] = $key;
				$error    = $e;
			}
		}
		if ( $masked ) {
			return self::named( $error, $masked, $fields );
		}

		// 6. The plugin's own validator, on every change at once.
		$prepared = $changes;
		if ( isset( $def['validate'] ) ) {
			self::$journal['steps'][] = 'validate';
			$checked = call_user_func( $def['validate'], $changes, array_intersect_key( $fields, $changes ), $ctx );
			if ( is_wp_error( $checked ) ) {
				return self::named( $checked, array_keys( $changes ), $fields );
			}
			if ( is_array( $checked ) ) {
				$prepared = $checked;
			}
		}

		if ( self::$dry ) {
			return true;
		}
		self::$journal['steps'][] = 'write';
		$wrote                    = call_user_func( $def['write'], $prepared, array_intersect_key( $fields, $changes ), $ctx );
		self::$journal['wrote']   = true;
		if ( is_wp_error( $wrote ) ) {
			return self::named( $wrote, array_keys( $changes ), $fields );
		}
		return true;
	}
}

/**
 * Declare a settings screen: the kernel owns its routes and its save.
 *
 * @param string $id  Screen id.
 * @param array  $def Declaration (Minn_Admin_Kernel_Settings' header lists the keys).
 * @return bool Whether it was declared.
 */
function minn_admin_settings( $id, array $def ) {
	return Minn_Admin_Kernel_Settings::declare_screen( $id, $def );
}

/**
 * Save a declared settings screen from PHP, through the same checks a POST
 * takes (the screen's own gate aside).
 *
 * @param string $id   Screen id.
 * @param array  $body Body as a POST carries it.
 * @param array  $ctx  What the screen's target answers, and 'tab' (null: the whole screen).
 * @return true|WP_Error
 */
function minn_admin_settings_save( $id, array $body, array $ctx = array() ) {
	return Minn_Admin_Kernel_Settings::save( $id, $body, $ctx );
}
