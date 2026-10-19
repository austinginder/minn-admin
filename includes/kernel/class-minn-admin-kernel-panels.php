<?php
/**
 * The fields tier: editor panels declared, saved by the kernel.
 *
 * An editor panel is a plugin's per-post fields drawn beside the editor. Each
 * one used to be written by hand three times over: a fields route listing the
 * fields, a read of the values onto the post's REST answer, and a save on the
 * same REST field. The registry scans kept finding the same mistakes in the
 * next adapter (a field offered that the plugin's own screen withholds, an
 * error answered after the post was already stored, a refused value written
 * as a clear, a typed value the cleaner emptied read as an emptied box), so
 * those rules live here and an adapter declares only what its plugin knows:
 *
 *   minn_admin_fields( 'meta-box', function () {    // or the array itself;
 *     return array(                                  // a function is asked once,
 *                                                    // after translations load
 *       'active' => callable(): bool,                // the plugin is loaded
 *       'label'  => __( 'Custom fields', 'minn-admin' ),
 *       'sub'    => 'Meta Box',
 *       'key'    => 'minn_meta_box',                 // the post's REST field: the
 *                                                    // panel's valuesKey and writeKey
 *       'params' => array( 'post_id', 'post_type' ), // the fields route's query names
 *                                                    // for the post and its rest base
 *       'types'  => array( 'post' ) | callable(): string[],   // the post types served
 *       'can'    => callable( int $post_id, string $post_type ): bool,
 *                   // optional: the plugin's own edit check. Asked with ( 0, '' )
 *                   // for the panel at all, then for the post (or, for a post not
 *                   // saved yet, the type being made). Anything but true, or a
 *                   // throw, is no.
 *       'refuse' => 'Why',                           // optional: told when 'can' says no
 *       'groups' => callable( int $post_id, string $post_type ): array,
 *                   // array( array( 'group' => 'Label', 'fields' => array( field, … ),
 *                   //               'locked' => int ) ), the fields the plugin lets
 *                   // this user edit on this post; 'locked' counts what stays in
 *                   // the plugin's own screen. 'can' may answer a string: no,
 *                   // and what to tell the person
 *       'read'   => callable( int $post_id ): array, // name => value, the panel's shape
 *       'write'  => callable( int $post_id, array $changes, array $fields ): array|WP_Error|null,
 *                   // $changes: name => cleaned value, null to clear. Answers
 *                   // name => message for each value the plugin refused, or a
 *                   // WP_Error that refuses every one of them. Both are for
 *                   // stores the plugin keeps whole (a form saved at once, a
 *                   // blob); a field that names its post meta needs neither
 *       'extra'  => callable( int $post_id, string $post_type ): array,
 *                   // optional: more keys for the fields route's answer (a
 *                   // search-result preview), asked only once its gates pass
 *       'description' => 'For the REST schema',
 *     );
 *   } );
 *
 * The fields route is GET minn-admin/v1/<id>/fields. A field:
 *
 *   array(
 *     'name' => 'key', 'label' => 'Label', 'type' => one of TYPES,
 *     'input'       => 'text',     // the control drawn, when not the type's own
 *     'choices'     => array( value => label ),            // select: these, or
 *     'options'     => array( array( value, label ), … ),  // an ordered list (one is required)
 *     'min', 'max', 'integer' => true,                     // number
 *     'html'        => true,       // textarea: post HTML (wp_kses_post), not plain text
 *     'raw'         => true,       // text, textarea, email: the plugin's own save
 *                                  // cleans and refuses it, so it goes as typed
 *     'required'    => true,       // an emptied box is refused, not a clear
 *     'post_type'   => 'tribe_venue',   // suggest (required): what a pick must be
 *     'image'       => true,       // media: the attachment must be an image
 *     'meta'        => 'key',      // stored as this post meta (one value): read with
 *                                  // get_post_meta(), written slashed where the caller
 *                                  // may edit that key (edit_post_meta), '' to clear
 *     'store'       => 'on' | 'int',   // checkbox in meta: 'on'/'' or 1/0 (default '1'/'')
 *     'sanitize'    => callable( mixed $clean, string $name ): mixed,
 *                                  // the plugin's own cleaner, after the type's; a
 *                                  // value it empties, or a throw, is refused
 *     'route', 'placeholder', 'help', 'counter', 'cond',   // drawn as given
 *     'check'       => callable( mixed $clean, int $post_id ): true|string,
 *                                  // the plugin's own rule for a cleaned value; a
 *                                  // string refuses with it
 *     'refuse'      => 'Why',      // told instead of the type's own message
 *   )
 *
 * A media field takes an attachment the caller may upload with and read (a
 * social image); its value is { id, url } or an id. A note is drawn and
 * never written.
 *
 * What the kernel holds every panel to (tests/fields-tier-conformance.test.php
 * checks each one):
 *
 *  1. Only the fields the plugin's own check allows are offered, read or
 *     written. The fields route asks for the post (edit_post) or, for a post
 *     not saved yet, the type (its edit_posts), and the plugin's check; the
 *     read answers an empty object to anyone refused.
 *  2. A save never answers an error. The post is stored before its REST
 *     fields are written, so an error would hide a new post's id from the
 *     editor (each retry then made another draft) and stop the fields
 *     registered after this one. Refusals are held on the request and ride
 *     on the post's own answer as minn_field_errors and minn_field_message.
 *  3. A refused value keeps the stored one and is named: it never reaches the
 *     plugin's write. So is a field the post does not offer.
 *  4. Only an empty box clears. A value its type's cleaner empties, or one of
 *     the wrong shape, is a refusal.
 *
 * And a value sent back equal to the one served is left as stored: it never
 * goes through a cleaner that could change what the plugin's own form stored.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

final class Minn_Admin_Kernel_Panels {

	/** The field types a panel may offer: a field of any other type is locked. */
	const TYPES = array( 'text', 'textarea', 'number', 'select', 'checkbox', 'email', 'url', 'date', 'datetime', 'suggest', 'media', 'note' );

	/** The keys a declaration may hold: one holding any other registers nothing. */
	const KEYS = array( 'active', 'label', 'sub', 'key', 'params', 'types', 'can', 'refuse', 'groups', 'read', 'write', 'extra', 'description' );

	/** The keys a field may hold: a field holding any other is locked. */
	const FIELD_KEYS = array( 'name', 'label', 'type', 'input', 'choices', 'options', 'min', 'max', 'integer', 'html', 'required', 'post_type', 'image', 'route', 'placeholder', 'help', 'counter', 'cond', 'meta', 'store', 'raw', 'check', 'sanitize', 'refuse' );

	/** What of a field the client is sent. */
	const WIRE_KEYS = array( 'name', 'label', 'type', 'choices', 'options', 'min', 'max', 'placeholder', 'route', 'help', 'counter', 'cond' );

	/** @var array id => declaration as given (an array or a function). */
	private static $given = array();

	/** @var array id => declaration read whole, or false when it cannot be. */
	private static $read = array();

	/** @var array What was refused, for the conformance runner. */
	private static $refused = array();

	/** @var SplObjectStorage|null Refusals held per REST request. */
	private static $held = null;

	/**
	 * Declare a panel. Its route, its REST field and its place in the editor
	 * register from here, so the route is attributed to the declaring file.
	 *
	 * @param string         $id  Panel id; its fields route is <id>/fields.
	 * @param array|callable $def Declaration, or a function returning it.
	 */
	public static function declare( $id, $def ) {
		$id = (string) $id;
		if ( ! preg_match( '/^[a-z0-9][a-z0-9-]*$/', $id ) || isset( self::$given[ $id ] ) ) {
			self::wrong( "Panel \"{$id}\" is not a new lowercase id, so it registers nothing." );
			return;
		}
		self::$given[ $id ] = $def;
		Minn_Admin_Kernel::endpoint( '/' . $id . '/fields', array(
			'methods' => 'GET',
			'active'  => function () use ( $id ) {
				return null !== self::active( $id );
			},
			'gate'    => function ( WP_REST_Request $r ) use ( $id ) {
				$def = self::active( $id );
				return null !== $def && current_user_can( 'edit_posts' ) && self::allowed( $def, 0, '' );
			},
			'run'     => function ( WP_REST_Request $r ) use ( $id ) {
				$def = self::active( $id );
				return null === $def ? Minn_Admin_Kernel::fail( 'rest_no_route', __( 'Not found.', 'minn-admin' ), 404 ) : self::fields_answer( $def, $r );
			},
		) );
		add_filter( 'minn_admin_editor_panels', function ( $panels ) use ( $id ) {
			$def = self::active( $id );
			if ( null === $def || ! self::allowed( $def, 0, '' ) ) {
				return $panels;
			}
			$q                = $def['params'];
			$panels[ $id ]    = array(
				'label'       => $def['label'],
				'sub'         => $def['sub'],
				'cap'         => 'edit_posts',
				'fieldsRoute' => 'minn-admin/v1/' . $id . '/fields?' . $q[0] . '={id}&' . $q[1] . '={type}',
				'valuesKey'   => $def['key'],
				'writeKey'    => $def['key'],
			);
			return $panels;
		} );
		if ( did_action( 'rest_api_init' ) ) {
			self::register_field( $id );
		}
	}

	/** Every declared panel id. */
	public static function ids() {
		return array_keys( self::$given );
	}

	/** What the tier refused this request, in order. */
	public static function refused() {
		return self::$refused;
	}

	/**
	 * A panel's declaration, read whole, or null when it cannot be read
	 * (the reason is in refused()).
	 *
	 * @param string $id Panel id.
	 * @return array|null
	 */
	public static function panel( $id ) {
		if ( ! isset( self::$given[ $id ] ) ) {
			return null;
		}
		if ( ! array_key_exists( $id, self::$read ) ) {
			self::$read[ $id ] = false;
			try {
				$def = self::$given[ $id ];
				$def = is_callable( $def ) && ! is_array( $def ) ? call_user_func( $def ) : $def;
				$why = self::unreadable( $def );
				if ( '' === $why ) {
					self::$read[ $id ] = self::normalize( $id, $def );
				} else {
					self::wrong( "Panel \"{$id}\" {$why}, so it registers nothing." );
				}
			} catch ( \Throwable $e ) {
				self::wrong( "Panel \"{$id}\" threw while its declaration was read, so it registers nothing." );
			}
		}
		return false === self::$read[ $id ] ? null : self::$read[ $id ];
	}

	/** The declaration when its plugin is active, or null. */
	private static function active( $id ) {
		$def = self::panel( $id );
		if ( null === $def ) {
			return null;
		}
		$on = $def['active'];
		return ( true === $on || ( ! is_bool( $on ) && Minn_Admin_Kernel::yes( $on ) ) ) ? $def : null;
	}

	/**
	 * Why a declaration cannot be read whole, or ''.
	 *
	 * @param mixed $def Declaration.
	 * @return string
	 */
	private static function unreadable( $def ) {
		if ( ! is_array( $def ) ) {
			return 'is not a list of keys';
		}
		foreach ( array_keys( $def ) as $k ) {
			if ( ! in_array( $k, self::KEYS, true ) ) {
				return "holds \"{$k}\", which the tier does not read";
			}
		}
		foreach ( array( 'label', 'key', 'types', 'groups' ) as $k ) {
			if ( ! isset( $def[ $k ] ) ) {
				return "has no \"{$k}\"";
			}
		}
		if ( ! is_string( $def['key'] ) || ! preg_match( '/^[a-z][a-z0-9_]*$/', $def['key'] ) ) {
			return 'has a REST key that is not a lowercase name';
		}
		if ( ! is_callable( $def['groups'] ) ) {
			return 'has a "groups" that cannot be called';
		}
		foreach ( array( 'read', 'write', 'can', 'extra' ) as $k ) {
			if ( isset( $def[ $k ] ) && ! is_callable( $def[ $k ] ) ) {
				return "has a \"{$k}\" that cannot be called";
			}
		}
		if ( isset( $def['active'] ) && ! is_bool( $def['active'] ) && ! is_callable( $def['active'] ) ) {
			return 'has an "active" that is neither a boolean nor a function';
		}
		if ( ! is_array( $def['types'] ) && ! is_callable( $def['types'] ) ) {
			return 'has "types" that are neither a list nor a function';
		}
		if ( isset( $def['params'] ) ) {
			$p = $def['params'];
			if ( ! is_array( $p ) || 2 !== count( $p ) || array_values( $p ) !== $p || ! preg_match( '/^[a-z_]+$/', (string) $p[0] ) || ! preg_match( '/^[a-z_]+$/', (string) $p[1] ) || $p[0] === $p[1] ) {
				return 'has "params" that are not two query names';
			}
		}
		return '';
	}

	private static function normalize( $id, array $def ) {
		return array(
			'id'          => $id,
			'active'      => $def['active'] ?? true,
			'label'       => (string) $def['label'],
			'sub'         => (string) ( $def['sub'] ?? '' ),
			'key'         => $def['key'],
			'params'      => $def['params'] ?? array( 'post_id', 'post_type' ),
			'types'       => $def['types'],
			'can'         => $def['can'] ?? null,
			'refuse'      => isset( $def['refuse'] ) ? (string) $def['refuse'] : '',
			'groups'      => $def['groups'],
			'read'        => $def['read'] ?? null,
			'write'       => $def['write'] ?? null,
			'extra'       => isset( $def['extra'] ) && is_callable( $def['extra'] ) ? $def['extra'] : null,
			'description' => (string) ( $def['description'] ?? '' ),
		);
	}

	/** The post types a panel serves (none when its function throws). */
	public static function types( array $def ) {
		try {
			$types = is_array( $def['types'] ) ? $def['types'] : call_user_func( $def['types'] );
		} catch ( \Throwable $e ) {
			return array();
		}
		return array_values( array_filter( array_map( 'strval', is_array( $types ) ? $types : array() ) ) );
	}

	/**
	 * The plugin's own edit check: for the panel at all, and for the post or
	 * the type being made when one is named. Strictly true or no.
	 *
	 * @param array  $def     Declaration.
	 * @param int    $post_id Post id, 0 for none.
	 * @param string $type    Post type, '' for none.
	 * @return bool
	 */
	public static function allowed( array $def, $post_id, $type ) {
		if ( ! is_callable( $def['can'] ) ) {
			return true;
		}
		if ( ! Minn_Admin_Kernel::yes( $def['can'], 0, '' ) ) {
			return false;
		}
		return ( ! $post_id && '' === (string) $type ) || Minn_Admin_Kernel::yes( $def['can'], (int) $post_id, (string) $type );
	}

	/**
	 * The groups offered for a post: each field read whole or locked.
	 *
	 * @param array  $def     Declaration.
	 * @param int    $post_id Post id, 0 for a post not saved yet.
	 * @param string $type    Post type.
	 * @return array[] Each { group, fields: name => field, locked }.
	 */
	public static function offered( array $def, $post_id, $type ) {
		try {
			$groups = call_user_func( $def['groups'], (int) $post_id, (string) $type );
		} catch ( \Throwable $e ) {
			return array();
		}
		$out  = array();
		$seen = array();
		foreach ( is_array( $groups ) ? $groups : array() as $g ) {
			if ( ! is_array( $g ) ) {
				continue;
			}
			$fields = array();
			$locked = isset( $g['locked'] ) ? max( 0, (int) $g['locked'] ) : 0;
			foreach ( isset( $g['fields'] ) && is_array( $g['fields'] ) ? $g['fields'] : array() as $f ) {
				$f = self::field( $f );
				if ( null === $f || isset( $seen[ $f['name'] ] ) ) {
					$locked++;
					continue;
				}
				$seen[ $f['name'] ]     = true;
				$fields[ $f['name'] ] = $f;
			}
			if ( $fields || $locked ) {
				$out[] = array(
					'group'  => isset( $g['group'] ) && is_scalar( $g['group'] ) ? (string) $g['group'] : '',
					'fields' => $fields,
					'locked' => $locked,
				);
			}
		}
		return $out;
	}

	/** Every offered field for a post, by name. */
	public static function fields( array $def, $post_id, $type ) {
		$out = array();
		foreach ( self::offered( $def, $post_id, $type ) as $g ) {
			$out += $g['fields'];
		}
		return $out;
	}

	/**
	 * A field read whole, or null (locked): a type the tier cleans, every key
	 * one it reads, and what its type needs to refuse a value.
	 *
	 * @param mixed $f Field as declared.
	 * @return array|null
	 */
	public static function field( $f ) {
		if ( ! is_array( $f ) || ! isset( $f['name'], $f['type'] ) || ! is_scalar( $f['name'] ) || '' === (string) $f['name'] ) {
			return null;
		}
		if ( ! in_array( $f['type'], self::TYPES, true ) || array_diff( array_keys( $f ), self::FIELD_KEYS ) ) {
			return null;
		}
		if ( 'select' === $f['type'] && ! self::choice_keys( $f ) ) {
			return null;
		}
		if ( 'suggest' === $f['type'] && ( empty( $f['post_type'] ) || ! is_string( $f['post_type'] ) ) ) {
			return null;
		}
		foreach ( array( 'check', 'sanitize' ) as $k ) {
			if ( isset( $f[ $k ] ) && ! is_callable( $f[ $k ] ) ) {
				return null;
			}
		}
		if ( isset( $f['meta'] ) && ( ! is_string( $f['meta'] ) || '' === $f['meta'] || in_array( $f['type'], array( 'note', 'suggest', 'media' ), true ) ) ) {
			return null;
		}
		if ( isset( $f['store'] ) && ! in_array( $f['store'], array( 'on', 'int' ), true ) ) {
			return null;
		}
		foreach ( array( 'min', 'max' ) as $k ) {
			if ( isset( $f[ $k ] ) && ! is_numeric( $f[ $k ] ) ) {
				return null;
			}
		}
		$f['name']  = (string) $f['name'];
		$f['label'] = isset( $f['label'] ) && is_scalar( $f['label'] ) && '' !== (string) $f['label'] ? (string) $f['label'] : $f['name'];
		return $f;
	}

	/** The values a select takes, from its choices or its options, as strings. */
	private static function choice_keys( array $f ) {
		$keys = array();
		if ( isset( $f['choices'] ) && is_array( $f['choices'] ) ) {
			$keys = array_map( 'strval', array_keys( $f['choices'] ) );
		} elseif ( isset( $f['options'] ) && is_array( $f['options'] ) ) {
			foreach ( $f['options'] as $o ) {
				$v = is_array( $o ) ? ( $o[0] ?? null ) : $o;
				if ( is_scalar( $v ) ) {
					$keys[] = (string) $v;
				}
			}
		}
		return $keys;
	}

	/** A field as the client is sent it. */
	private static function wire( array $f ) {
		$out         = array_intersect_key( $f, array_flip( self::WIRE_KEYS ) );
		$out['type'] = isset( $f['input'] ) && is_string( $f['input'] ) && '' !== $f['input'] ? $f['input'] : ( 'checkbox' === $f['type'] ? 'true_false' : ( 'media' === $f['type'] ? 'image' : $f['type'] ) );
		return $out;
	}

	/** The post type a rest base (or type name) names, or ''. */
	private static function type_of( $base ) {
		$base = sanitize_key( (string) $base );
		foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $obj ) {
			if ( ( $obj->rest_base ? $obj->rest_base : $obj->name ) === $base || $obj->name === $base ) {
				return $obj->name;
			}
		}
		return '';
	}

	/**
	 * The fields route's answer: the groups offered for the post, or for the
	 * type a new post is being made as.
	 *
	 * @param array           $def Declaration.
	 * @param WP_REST_Request $r   Request.
	 * @return array|WP_Error
	 */
	public static function fields_answer( array $def, WP_REST_Request $r ) {
		$id      = $r->get_param( $def['params'][0] );
		$post_id = is_scalar( $id ) && preg_match( '/^\d+$/', (string) $id ) ? (int) $id : 0;
		$base    = $r->get_param( $def['params'][1] );
		$post    = $post_id ? get_post( $post_id ) : null;
		$type    = $post ? $post->post_type : self::type_of( is_scalar( $base ) && '' !== (string) $base ? $base : 'posts' );
		if ( '' === $type || ! in_array( $type, self::types( $def ), true ) ) {
			return array( 'groups' => array() );
		}
		if ( $post_id && ! $post ) {
			return Minn_Admin_Kernel::fail( 'not_found', __( 'Post not found.', 'minn-admin' ), 404 );
		}
		if ( $post ) {
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				return Minn_Admin_Kernel::fail( 'rest_forbidden', __( 'You cannot edit this post.', 'minn-admin' ), 403 );
			}
		} else {
			$obj = get_post_type_object( $type );
			if ( ! $obj || ! current_user_can( $obj->cap->edit_posts ) ) {
				return Minn_Admin_Kernel::fail( 'rest_forbidden', __( 'You cannot edit that post type.', 'minn-admin' ), 403 );
			}
		}
		if ( ! self::allowed( $def, $post_id, $type ) ) {
			return array( 'groups' => array() );
		}
		$groups = array();
		foreach ( self::offered( $def, $post_id, $type ) as $g ) {
			$g['fields'] = array_values( array_map( array( __CLASS__, 'wire' ), $g['fields'] ) );
			$groups[]    = $g;
		}
		$out = array( 'groups' => $groups );
		if ( $def['extra'] ) {
			try {
				$more = call_user_func( $def['extra'], $post_id, $type );
			} catch ( \Throwable $e ) {
				$more = array();
			}
			$out += is_array( $more ) ? $more : array();
		}
		return $out;
	}

	/**
	 * The values a post's REST answer carries: only the offered fields, and
	 * an empty object to anyone the post or the plugin refuses.
	 *
	 * @param array $def     Declaration.
	 * @param int   $post_id Post id.
	 * @return array|stdClass
	 */
	public static function values( array $def, $post_id ) {
		$post = $post_id ? get_post( (int) $post_id ) : null;
		if ( ! $post || ! in_array( $post->post_type, self::types( $def ), true ) || ! current_user_can( 'edit_post', $post->ID ) || ! self::allowed( $def, $post->ID, $post->post_type ) ) {
			return new stdClass();
		}
		$fields = self::fields( $def, $post->ID, $post->post_type );
		$stored = self::stored( $def, $post->ID, $fields );
		// An object when empty or when the names are digits, which PHP keys
		// as ints (a list would leave as a JSON array).
		$out = array_intersect_key( $stored, $fields );
		return $out && ! array_filter( array_keys( $out ), 'is_int' ) ? $out : (object) $out;
	}

	/** What the plugin holds for a post, as its read gives it (nothing when it throws). */
	private static function stored( array $def, $post_id, array $fields = array() ) {
		$v = array();
		if ( is_callable( $def['read'] ) ) {
			try {
				$v = call_user_func( $def['read'], (int) $post_id );
			} catch ( \Throwable $e ) {
				$v = array();
			}
			$v = is_array( $v ) ? $v : ( is_object( $v ) ? (array) $v : array() );
		}
		foreach ( $fields as $name => $f ) {
			if ( isset( $f['meta'] ) ) {
				$raw        = get_post_meta( (int) $post_id, $f['meta'], true );
				$v[ $name ] = 'checkbox' === $f['type'] ? self::flag_of( $f, $raw ) : ( is_scalar( $raw ) ? (string) $raw : '' );
			}
		}
		return $v;
	}

	/** A checkbox's stored meta as on or off, in its store's shape. */
	private static function flag_of( array $f, $raw ) {
		$store = $f['store'] ?? '';
		if ( 'on' === $store ) {
			return 'on' === $raw;
		}
		if ( 'int' === $store ) {
			return (int) $raw > 0;
		}
		return ! empty( $raw ) && '0' !== (string) $raw;
	}

	/**
	 * Store cleaned values in the post meta their fields name: slashed, as
	 * update_post_meta() unslashes. A clear stores '' and keeps the row, as
	 * the vendors' own metaboxes do: a plugin that acts on a change listens
	 * for an update (WP Job Manager geocodes a location on one), and a
	 * deleted row is never updated again, only added.
	 *
	 * @return string[] The fields that were not stored (a key the caller may
	 *                  not edit, or a write that threw); the rest are.
	 */
	private static function store( $post_id, array $changes, array $fields ) {
		$failed = array();
		foreach ( $changes as $name => $value ) {
			$f     = $fields[ $name ];
			$store = $f['store'] ?? '';
			if ( 'checkbox' === $f['type'] ) {
				$value = 'on' === $store ? ( $value ? 'on' : '' ) : ( 'int' === $store ? ( $value ? 1 : 0 ) : ( $value ? '1' : '' ) );
			}
			try {
				// Core's own question for this key (a protected key with no
				// auth callback is no one's to write through here).
				if ( ! current_user_can( 'edit_post_meta', $post_id, $f['meta'] ) ) {
					$failed[] = $name;
					continue;
				}
				update_post_meta( $post_id, $f['meta'], null === $value ? '' : wp_slash( $value ) );
			} catch ( \Throwable $e ) {
				$failed[] = $name;
			}
		}
		return $failed;
	}

	/**
	 * Save what the panel sent for a post. Never throws and never writes a
	 * refused value.
	 *
	 * @param array   $def    Declaration.
	 * @param WP_Post $post   The post, already stored.
	 * @param array   $values Field name => value as sent.
	 * @return array[] Each refused field: { key, label, message, status }.
	 */
	public static function save( array $def, WP_Post $post, array $values ) {
		$refused = array();
		$refuse  = function ( $key, $label, $message, $status = 400 ) use ( &$refused ) {
			$refused[ (string) $key ] = array(
				'key'     => (string) $key,
				'label'   => wp_strip_all_tags( (string) $label ),
				'message' => (string) $message,
				'status'  => (int) $status,
			);
		};
		if ( ! $values ) {
			return array();
		}
		$typed = in_array( $post->post_type, self::types( $def ), true );
		if ( ! $typed || ! current_user_can( 'edit_post', $post->ID ) || ! self::allowed( $def, $post->ID, $post->post_type ) ) {
			$why = __( 'You cannot edit this post.', 'minn-admin' );
			if ( $typed && current_user_can( 'edit_post', $post->ID ) ) {
				$why = '' !== $def['refuse'] ? $def['refuse'] : $why;
				try {
					$said = is_callable( $def['can'] ) ? call_user_func( $def['can'], (int) $post->ID, (string) $post->post_type ) : null;
				} catch ( \Throwable $e ) {
					$said = null;
				}
				$why = is_string( $said ) && '' !== $said ? $said : $why;
			}
			foreach ( array_keys( $values ) as $key ) {
				$refuse( $key, $key, $why, 403 );
			}
			return array_values( $refused );
		}
		$fields  = self::fields( $def, $post->ID, $post->post_type );
		$served  = self::stored( $def, $post->ID, $fields );
		$changes = array();
		foreach ( $values as $key => $sent ) {
			$key = (string) $key;
			// A cleaner, a capability check or a compare runs filters any plugin
			// can hook, and the post is stored already: whatever throws refuses
			// its own field and nothing else.
			try {
				if ( ! isset( $fields[ $key ] ) ) {
					$refuse( $key, $key, __( 'This field cannot be changed here.', 'minn-admin' ), 403 );
					continue;
				}
				$f = $fields[ $key ];
				if ( array_key_exists( $key, $served ) && self::same( $f, $sent, $served[ $key ] ) ) {
					continue;
				}
				if ( 'note' === $f['type'] ) {
					$refuse( $key, $f['label'], __( 'This field cannot be changed here.', 'minn-admin' ), 403 );
					continue;
				}
				$clean = self::clean( $f, $sent, $served[ $key ] ?? null );
				if ( is_string( $clean ) ) {
					$refuse( $key, $f['label'], isset( $f['refuse'] ) && is_string( $f['refuse'] ) && '' !== $f['refuse'] ? $f['refuse'] : $clean );
					continue;
				}
				$value = $clean[0];
				if ( null !== $value && isset( $f['check'] ) ) {
					try {
						$ok = call_user_func( $f['check'], $value, (int) $post->ID );
					} catch ( \Throwable $e ) {
						$ok = false;
					}
					if ( true !== $ok ) {
						$refuse( $key, $f['label'], is_string( $ok ) && '' !== $ok ? $ok : ( isset( $f['refuse'] ) && is_string( $f['refuse'] ) && '' !== $f['refuse'] ? $f['refuse'] : self::says( $f ) ) );
						continue;
					}
				}
				if ( null !== $value && isset( $f['sanitize'] ) ) {
					try {
						$value = call_user_func( $f['sanitize'], $value, $key );
					} catch ( \Throwable $e ) {
						$value = null;
					}
					// The plugin's own cleaner taking a value away whole is a
					// refusal, as the type's is.
					if ( null === $value || '' === $value || false === $value || ! is_scalar( $value ) ) {
						$refuse( $key, $f['label'], isset( $f['refuse'] ) && is_string( $f['refuse'] ) && '' !== $f['refuse'] ? $f['refuse'] : __( 'That value was not accepted.', 'minn-admin' ) );
						continue;
					}
				}
				$changes[ $key ] = $value;
			} catch ( \Throwable $e ) {
				$refuse( $key, isset( $fields[ $key ]['label'] ) ? $fields[ $key ]['label'] : $key, __( 'That did not work. Nothing was reported as done.', 'minn-admin' ), 500 );
			}
		}
		if ( ! $changes ) {
			return array_values( $refused );
		}
		$own = array_filter( $fields, function ( $f ) {
			return isset( $f['meta'] );
		} );
		$mine    = array_intersect_key( $changes, $own );
		$changes = array_diff_key( $changes, $own );
		foreach ( $mine ? self::store( (int) $post->ID, $mine, $fields ) : array() as $key ) {
			$refuse( $key, $fields[ $key ]['label'], __( 'That did not work. Nothing was reported as done.', 'minn-admin' ), 500 );
		}
		if ( ! $changes ) {
			return array_values( $refused );
		}
		try {
			if ( ! is_callable( $def['write'] ) ) {
				throw new LogicException( 'A field with no meta in a panel with no write.' );
			}
			$out = call_user_func( $def['write'], (int) $post->ID, $changes, $fields );
		} catch ( \Throwable $e ) {
			$out = new WP_Error( 'minn_failed', __( 'That did not work. Nothing was reported as done.', 'minn-admin' ), array( 'status' => 500 ) );
		}
		if ( is_wp_error( $out ) ) {
			$data   = $out->get_error_data();
			$status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 400;
			foreach ( array_keys( $changes ) as $key ) {
				$refuse( $key, $fields[ $key ]['label'], $out->get_error_message(), $status );
			}
		} elseif ( is_array( $out ) ) {
			foreach ( $out as $key => $message ) {
				$key = (string) $key;
				if ( isset( $fields[ $key ] ) && is_string( $message ) && '' !== $message ) {
					$refuse( $key, $fields[ $key ]['label'], $message );
				}
			}
		}
		return array_values( $refused );
	}

	/** Whether a sent value is the one the read served for the field. */
	public static function same( array $f, $sent, $served ) {
		// A list or object sent for a one-value field is never the value
		// served: its shape is refused before anything compares it.
		if ( ! in_array( $f['type'], array( 'suggest', 'media' ), true ) && null !== $sent && ! is_scalar( $sent ) ) {
			return false;
		}
		switch ( $f['type'] ) {
			case 'checkbox':
				return Minn_Admin::unchanged( $sent, $served, 'bool' );
			case 'number':
				return Minn_Admin::unchanged( $sent, $served, 'number' );
			case 'suggest':
				$a = self::pick( $sent );
				$b = self::pick( $served );
				return null !== $a && $a === $b;
			case 'media':
				$a = self::pick( $sent, 'id' );
				$b = self::pick( $served, 'id' );
				return null !== $a && $a === $b;
		}
		return Minn_Admin::unchanged( $sent, $served );
	}

	/** A picked post's id from { value, label } (media: { id, url }), an id or '', 0 for none, null for no id. */
	private static function pick( $v, $key = 'value' ) {
		if ( is_object( $v ) ) {
			$v = (array) $v;
		}
		if ( is_array( $v ) ) {
			if ( ! array_key_exists( $key, $v ) ) {
				return null;
			}
			$v = $v[ $key ];
		}
		if ( null === $v || '' === $v || false === $v ) {
			return 0;
		}
		return is_scalar( $v ) && preg_match( '/^\d+$/', trim( (string) $v ) ) ? (int) $v : null;
	}

	/** Whether a sent value is an empty box. */
	private static function blank( $v ) {
		return null === $v || ( is_string( $v ) && '' === trim( $v ) );
	}

	/**
	 * A sent value cleaned by its field's type: array( value ) to store, with
	 * null to clear, or the message refusing it.
	 *
	 * @param array $f      Field.
	 * @param mixed $sent   Value as sent.
	 * @param mixed $served The value the read served, if any.
	 * @return array|string
	 */
	public static function clean( array $f, $sent, $served = null ) {
		$type = $f['type'];
		if ( 'checkbox' === $type ) {
			if ( null !== $sent && ! is_scalar( $sent ) ) {
				return self::says( $f );
			}
			return array( ! empty( $sent ) && 'false' !== $sent && '0' !== (string) $sent && 'off' !== $sent );
		}
		if ( 'suggest' === $type ) {
			$id = self::pick( $sent );
			if ( null === $id ) {
				return self::says( $f );
			}
			if ( 0 === $id ) {
				return ! empty( $f['required'] ) ? __( 'This field cannot be empty.', 'minn-admin' ) : array( null );
			}
			// The one already linked passes; anything else is a post of the
			// field's type the caller may read, so a private page's title
			// can never be linked in under another name.
			if ( $id !== self::pick( $served ) && ( $f['post_type'] !== get_post_type( $id ) || ! current_user_can( 'read_post', $id ) ) ) {
				return self::says( $f );
			}
			return array( $id );
		}
		if ( 'media' === $type ) {
			$id = self::pick( $sent, 'id' );
			if ( null === $id ) {
				return self::says( $f );
			}
			if ( 0 === $id ) {
				return ! empty( $f['required'] ) ? __( 'This field cannot be empty.', 'minn-admin' ) : array( null );
			}
			// A bare id names any row of the media table: the caller must be
			// able to pick it (upload_files, as the vendor's media modal asks)
			// and read it, or storing it and reading the URL back is an
			// enumeration oracle over every upload.
			if ( ! current_user_can( 'upload_files' ) || 'attachment' !== get_post_type( $id ) || ! current_user_can( 'read_post', $id ) ) {
				return __( 'You cannot use that media item.', 'minn-admin' );
			}
			// Asked only after the checks above, so it tells nothing about an
			// item the caller cannot read.
			if ( ! empty( $f['image'] ) && ! wp_attachment_is_image( $id ) ) {
				return __( 'That media item is not an image.', 'minn-admin' );
			}
			return array( $id );
		}
		if ( null !== $sent && ! is_scalar( $sent ) ) {
			return self::says( $f );
		}
		if ( self::blank( $sent ) || false === $sent ) {
			return ! empty( $f['required'] ) ? __( 'This field cannot be empty.', 'minn-admin' ) : array( null );
		}
		$s = (string) $sent;
		// Text the plugin's own save cleans and refuses (Pods sanitizes by
		// each field's own options) goes to it as typed: cleaning it here too
		// would strip what the field allows.
		if ( ! empty( $f['raw'] ) && in_array( $type, array( 'text', 'textarea', 'email' ), true ) ) {
			return array( $s );
		}
		switch ( $type ) {
			case 'text':
				$v = self::text( $s, false );
				break;
			case 'textarea':
				$v = ! empty( $f['html'] ) ? wp_kses_post( $s ) : self::text( $s, true );
				break;
			case 'number':
				$v = trim( $s );
				if ( ! is_numeric( $v ) || ( ! empty( $f['integer'] ) && ! preg_match( '/^-?\d+$/', $v ) ) ) {
					return self::says( $f );
				}
				if ( ! empty( $f['integer'] ) ) {
					$v = (string) preg_replace( '/^(-?)0+(?=\d)/', '$1', $v ); // '007' is 7
				}
				// '-0' is no less than 0, and no number a field from 0 up takes.
				$below = isset( $f['min'] ) && ( (float) $v < (float) $f['min'] || ( (float) $f['min'] >= 0 && '-' === substr( $v, 0, 1 ) ) );
				if ( $below || ( isset( $f['max'] ) && (float) $v > (float) $f['max'] ) ) {
					return __( 'That number is outside the range this field takes.', 'minn-admin' );
				}
				break;
			case 'select':
				$v = in_array( $s, self::choice_keys( $f ), true ) ? $s : '';
				break;
			case 'email':
				$v = sanitize_email( trim( $s ) );
				$v = is_email( $v ) ? $v : '';
				break;
			case 'url':
				$v = minn_admin_url_clean( trim( $s ) );
				break;
			case 'date':
			case 'datetime':
				// Read and written as a wall time on one clock (UTC), so the
				// value typed is the value stored whatever PHP's default zone.
				// An absolute date only: 'tomorrow' resolves against the
				// server's now, not the person's.
				$d = preg_match( '/^\d{4}-\d{1,2}-\d{1,2}(?:[ T]\d{1,2}:\d{2}(?::\d{2})?)?$/', trim( $s ) ) ? date_create_immutable( trim( $s ), new DateTimeZone( 'UTC' ) ) : false;
				$e = DateTimeImmutable::getLastErrors();
				$v = $d && ! ( $e && $e['warning_count'] ) ? $d->format( 'date' === $type ? 'Y-m-d' : 'Y-m-d H:i:s' ) : '';
				break;
			default:
				$v = '';
		}
		// A value that was there, made nothing by its cleaner, is no clear.
		return ( null === $v || '' === $v ) ? self::says( $f ) : array( $v );
	}

	/**
	 * Plain text: sanitize_text_field() with one step left out. It deletes
	 * anything shaped like a percent-encoded octet, and a typed "100%Accurate"
	 * or an SEO plugin's "%category%" variable is text, not an octet ("%ca"
	 * went). Everything else is core's own sequence: invalid UTF-8 dropped,
	 * tags stripped, and for a single line the whitespace collapsed.
	 *
	 * @param string $s         Typed text.
	 * @param bool   $multiline Keep line breaks.
	 * @return string
	 */
	public static function text( $s, $multiline = false ) {
		$s = wp_check_invalid_utf8( (string) $s );
		$s = wp_strip_all_tags( wp_pre_kses_less_than( $s ), ! $multiline );
		if ( ! $multiline ) {
			$s = preg_replace( '/[\r\n\t ]+/', ' ', $s );
		}
		return trim( (string) $s );
	}

	/** The message a field's type refuses a value with. */
	private static function says( array $f ) {
		switch ( $f['type'] ) {
			case 'number':
				return ! empty( $f['integer'] ) ? __( 'Enter a whole number.', 'minn-admin' ) : __( 'Enter a number.', 'minn-admin' );
			case 'select':
				return __( 'That is not one of this field\'s choices.', 'minn-admin' );
			case 'suggest':
				return __( 'That is not one of the offered choices.', 'minn-admin' );
			case 'email':
				return __( 'Enter a valid email address.', 'minn-admin' );
			case 'url':
				return __( 'That URL is not valid.', 'minn-admin' );
			case 'date':
			case 'datetime':
				return __( 'Enter a valid date.', 'minn-admin' );
		}
		return __( 'That text could not be saved as typed.', 'minn-admin' );
	}

	/**
	 * The REST field's write: the save, its refusals held for the post's own
	 * answer. Never an error (rule 2).
	 *
	 * @param array                $def     Declaration.
	 * @param mixed                $value   Sent object.
	 * @param mixed                $post    The stored post.
	 * @param WP_REST_Request|null $request The request.
	 * @return null
	 */
	public static function update( array $def, $value, $post, $request = null ) {
		if ( ! $post instanceof WP_Post ) {
			return null;
		}
		if ( is_object( $value ) ) {
			$value = (array) $value;
		}
		if ( ! is_array( $value ) || ! $value ) {
			return null;
		}
		try {
			$refused = self::save( $def, $post, $value );
		} catch ( \Throwable $e ) {
			// The gates before the fields run filters too.
			$refused = array();
			foreach ( array_keys( $value ) as $key ) {
				$refused[] = array(
					'key'     => (string) $key,
					'label'   => (string) $key,
					'message' => __( 'That did not work. Nothing was reported as done.', 'minn-admin' ),
					'status'  => 500,
				);
			}
		}
		if ( $refused && $request instanceof WP_REST_Request ) {
			$by = array();
			foreach ( $refused as $row ) {
				$by[ $row['message'] ][] = $row;
			}
			foreach ( $by as $message => $rows ) {
				self::held( $request, new WP_Error( 'minn_field_refused', (string) $message, array( 'status' => $rows[0]['status'], 'fields' => array_map( function ( $row ) {
					return array( 'key' => $row['key'], 'label' => $row['label'], 'message' => $row['message'] );
				}, $rows ) ) ), $def['key'] );
			}
		}
		return null;
	}

	/** rest_api_init: each active panel's REST field on its types. */
	public static function register_field( $id ) {
		$def = self::active( $id );
		if ( null !== $def ) {
			self::register_rest( $def );
		}
	}

	/** A panel's REST field, on the types it serves and no other. */
	public static function register_rest( array $def ) {
		$types = self::types( $def );
		if ( ! $types ) {
			return;
		}
		$schema = array(
			'type'    => 'object',
			'context' => array( 'edit' ),
		);
		if ( '' !== $def['description'] ) {
			$schema = array( 'description' => $def['description'] ) + $schema;
		}
		register_rest_field( $types, $def['key'], array(
			'get_callback'    => function ( $obj ) use ( $def ) {
				// A _fields request naming this field but not id hands an
				// id-less array: the isset is load-bearing.
				return self::values( $def, is_array( $obj ) && isset( $obj['id'] ) ? (int) $obj['id'] : 0 );
			},
			'update_callback' => function ( $value, $post, $field_name = '', $request = null ) use ( $def ) {
				return self::update( $def, $value, $post, $request );
			},
			'schema'          => $schema,
		) );
	}

	/**
	 * The refusals editor panels hold for one REST request: add one with
	 * $error and the panel's write key, take them all (and forget them)
	 * without. Held by the request object itself, so a leftover can never be
	 * read by another request.
	 *
	 * @param WP_REST_Request $request The request the save runs in.
	 * @param WP_Error|null   $error   A refusal to add.
	 * @param string          $panel   The panel's write key (its REST field).
	 * @return array[] Each { panel, error }.
	 */
	public static function held( $request, $error = null, $panel = '' ) {
		if ( null === self::$held ) {
			self::$held = new SplObjectStorage();
		}
		if ( ! is_object( $request ) ) {
			return array();
		}
		if ( null !== $error ) {
			$list                     = isset( self::$held[ $request ] ) ? self::$held[ $request ] : array();
			$list[]                   = array(
				'panel' => (string) $panel,
				'error' => $error,
			);
			self::$held[ $request ] = $list;
			return $list;
		}
		if ( ! isset( self::$held[ $request ] ) ) {
			return array();
		}
		$list = self::$held[ $request ];
		unset( self::$held[ $request ] );
		return $list;
	}

	/**
	 * Put the panels' refusals on the post's own answer once the save has run.
	 *
	 * A successful answer keeps its status (the post was saved) and carries
	 * `minn_field_errors` (each refused field: the panel's write key, the
	 * field key, its label and message) and `minn_field_message`, which the
	 * editor shows, keeping those fields unsaved. An answer that is already
	 * an error keeps its own message first and gains the refusal's, so
	 * neither is lost.
	 *
	 * @param mixed           $response The callback's answer.
	 * @param WP_REST_Request $request  The request.
	 * @return mixed
	 */
	public static function report( $response, $request ) {
		$held = $request instanceof WP_REST_Request ? self::held( $request ) : array();
		if ( ! $held ) {
			return $response;
		}
		$fields   = array();
		$messages = array();
		$errors   = array();
		foreach ( $held as $h ) {
			$e          = $h['error'];
			$errors[]   = $e;
			$messages[] = $e->get_error_message();
			$data       = $e->get_error_data();
			foreach ( (array) ( is_array( $data ) && isset( $data['fields'] ) ? $data['fields'] : array() ) as $f ) {
				$fields[] = array( 'field' => $h['panel'] ) + (array) $f;
			}
		}
		if ( is_wp_error( $response ) ) {
			$out = new WP_Error( $response->get_error_code(), trim( $response->get_error_message() . ' ' . implode( ' ', $messages ) ), $response->get_error_data() );
			foreach ( $response->get_error_codes() as $code ) {
				foreach ( $response->get_error_messages( $code ) as $i => $message ) {
					if ( $code !== $response->get_error_code() || $i > 0 ) {
						$out->add( $code, $message );
					}
				}
			}
			foreach ( $errors as $e ) {
				$out->add( $e->get_error_code(), $e->get_error_message(), $e->get_error_data() );
			}
			return $out;
		}
		$response = rest_ensure_response( $response );
		$data     = $response->get_data();
		if ( ! is_array( $data ) ) {
			return $response;
		}
		$data['minn_field_errors']  = $fields;
		$data['minn_field_message'] = implode( ' ', array_unique( $messages ) );
		$response->set_data( $data );
		return $response;
	}

	/** A declaration the tier refuses: a line in refused(), and a notice for its author. */
	private static function wrong( $message ) {
		self::$refused[] = (string) $message;
		// During a REST request a notice rides the response as an
		// X-WP-DoingItWrong header, to anyone.
		$rest = ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( function_exists( 'wp_is_serving_rest_request' ) && wp_is_serving_rest_request() );
		if ( $rest ) {
			if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
				error_log( 'Minn Admin minn_admin_fields: ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return;
		}
		if ( did_action( 'init' ) ) {
			_doing_it_wrong( 'minn_admin_fields', esc_html( $message ), '0.47.0' );
		}
	}

	/** The hooks every panel shares. */
	public static function boot() {
		add_action( 'rest_api_init', function () {
			foreach ( array_keys( self::$given ) as $id ) {
				self::register_field( $id );
			}
		} );
		add_filter( 'rest_request_after_callbacks', function ( $response, $handler, $request ) {
			return self::report( $response, $request );
		}, 10, 3 );
		// A controller run outside the server's own dispatch never reaches
		// the filter above; whatever it held goes with its request.
		add_filter( 'rest_post_dispatch', function ( $result, $server, $request ) {
			if ( $request instanceof WP_REST_Request ) {
				self::held( $request );
			}
			return $result;
		}, 10, 3 );
	}
}

Minn_Admin_Kernel_Panels::boot();

/**
 * Declare an editor panel (Minn_Admin_Kernel_Panels' header lists the keys).
 *
 * @param string         $id  Panel id; its fields route is minn-admin/v1/<id>/fields.
 * @param array|callable $def Declaration, or a function returning it so its
 *                            strings are translated after Minn's translations load.
 */
function minn_admin_fields( $id, $def ) {
	Minn_Admin_Kernel_Panels::declare( $id, $def );
}

/**
 * The refusals editor panels hold for one REST request
 * (Minn_Admin_Kernel_Panels::held()), for panels that still save by hand.
 *
 * @param WP_REST_Request $request The request the save runs in.
 * @param WP_Error|null   $error   A refusal to add.
 * @param string          $panel   The panel's write key (its REST field).
 * @return array[]
 */
function minn_admin_panel_held( $request, $error = null, $panel = '' ) {
	return Minn_Admin_Kernel_Panels::held( $request, $error, $panel );
}

/**
 * Put the panels' refusals on the post's own answer
 * (Minn_Admin_Kernel_Panels::report()).
 *
 * @param mixed           $response The callback's answer.
 * @param WP_REST_Request $request  The request.
 * @return mixed
 */
function minn_admin_panel_report_held( $response, $request ) {
	return Minn_Admin_Kernel_Panels::report( $response, $request );
}
