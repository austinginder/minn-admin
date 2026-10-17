<?php
/**
 * Bundled adapter: Pods (wp.org free).
 *
 * Sibling of the ACF / Meta Box editor-panel adapters. Pods free stores field
 * values as post meta (for extended post types) and does not expose a first-class
 * REST object like ACF's `acf` field, so this adapter:
 *   1. Lists simple fields for the post being edited via fieldsRoute
 *   2. Registers a `minn_pods` REST field on every show_in_rest post type for
 *      read/write through pods( $pod, $id )->field() / ->save()
 *
 * Complex field types (file, relationships, WYSIWYG, multi-pick, repeatable…)
 * count as locked and link out to wp-admin, same philosophy as ACF.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/** Pods field types Minn can render as panel inputs. */
const MINN_ADMIN_PODS_SIMPLE = array(
	'text'      => 'text',
	'website'   => 'url',
	'phone'     => 'text',
	'email'     => 'email',
	'password'  => 'text',
	'paragraph' => 'textarea',
	'code'      => 'textarea',
	'number'    => 'number',
	'currency'  => 'number',
	// Single custom-simple pick only (see map_field).
	'pick'      => 'select',
	'boolean'   => 'true_false',
);

/**
 * @return bool
 */
function minn_admin_pods_active() {
	return function_exists( 'pods' ) && function_exists( 'pods_api' );
}

/**
 * Run Pods code with its refusals thrown, never printed.
 *
 * Inside any REST request Pods answers a refusal by printing its own JSON
 * error and exiting (pods_error() takes its 'json' mode whenever
 * REST_REQUEST is set), and its Pods object exits on one by default
 * anywhere. Reached from a post save, that ended the request after
 * WordPress had stored the post: a new post's id never reached the editor,
 * so each retry and autosave made another draft, and the REST fields after
 * this one were never written. Every Pods call Minn makes goes through
 * here, so a refusal throws to the caller instead, and Pods' fallback
 * handler for an uncaught one is left out of it.
 *
 * @param callable $fn What to run.
 * @return mixed What it returns.
 */
function minn_admin_pods_quiet( callable $fn ) {
	$mode     = function () {
		return 'exception';
	};
	$fallback = function () {
		return false;
	};
	add_filter( 'pods_error_mode', $mode, PHP_INT_MAX );
	add_filter( 'pods_error_exception_fallback_enabled', $fallback, PHP_INT_MAX );
	try {
		return $fn();
	} finally {
		remove_filter( 'pods_error_mode', $mode, PHP_INT_MAX );
		remove_filter( 'pods_error_exception_fallback_enabled', $fallback, PHP_INT_MAX );
	}
}

/**
 * Resolve REST base (or post type slug) to a post type name.
 *
 * @param string $rest_base From the editor.
 * @return string
 */
function minn_admin_pods_resolve_type( $rest_base ) {
	$rest_base = sanitize_key( $rest_base );
	foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $obj ) {
		$base = $obj->rest_base ? $obj->rest_base : $obj->name;
		if ( $base === $rest_base || $obj->name === $rest_base ) {
			return $obj->name;
		}
	}
	return 'post';
}

/**
 * Read a Pods field arg from a Whatsit or array.
 *
 * @param object|array $field Field object.
 * @param string       $key   Arg name.
 * @param mixed        $default Default.
 * @return mixed
 */
function minn_admin_pods_field_arg( $field, $key, $default = null ) {
	if ( is_object( $field ) ) {
		if ( isset( $field[ $key ] ) ) {
			return $field[ $key ];
		}
		if ( method_exists( $field, 'get_arg' ) ) {
			$v = $field->get_arg( $key );
			return ( null !== $v && '' !== $v ) ? $v : $default;
		}
		return $default;
	}
	return is_array( $field ) && array_key_exists( $key, $field ) ? $field[ $key ] : $default;
}

/**
 * Parse Pods pick_custom ("value|Label\n…") into choices map.
 *
 * @param string $custom Raw pick_custom.
 * @return array value => label
 */
function minn_admin_pods_parse_pick_custom( $custom ) {
	$choices = array();
	$custom  = (string) $custom;
	if ( '' === $custom ) {
		return $choices;
	}
	// Pods accepts newlines or commas as separators.
	$lines = preg_split( '/[\r\n]+/', $custom );
	foreach ( $lines as $line ) {
		$line = trim( $line );
		if ( '' === $line ) {
			continue;
		}
		if ( false !== strpos( $line, '|' ) ) {
			list( $val, $label ) = array_map( 'trim', explode( '|', $line, 2 ) );
		} else {
			$val   = $line;
			$label = $line;
		}
		if ( '' !== $val ) {
			$choices[ $val ] = $label !== '' ? $label : $val;
		}
	}
	return $choices;
}

/**
 * Map one Pods field onto the panel vocabulary, or null if locked.
 *
 * @param object|array $field Pods field.
 * @return array|null { name, label, type, choices?, min?, max? }
 */
/**
 * Does Pods let the current user see this field at all?
 *
 * A field (or its whole group) can be restricted to admins, to roles, to a
 * capability or to logged-in users. Their metabox neither renders nor
 * saves such a field for anyone who fails the check, and pods()->save()
 * cannot backstop it: PodsAPI short-circuits the permission test when the
 * save originates from code. So the check runs here, where the field map
 * for the schema route, the read and the write all come from.
 *
 * @param object|array $field Pods field object or legacy array.
 * @return bool
 */
function minn_admin_pods_may_see( $field ) {
	// Fail closed, like every other vendor gate here: without Pods' own
	// permission helper there is no way to know a restricted field from an
	// open one, so none is offered.
	if ( ! function_exists( 'pods_permission' ) ) {
		return false;
	}
	try {
		return minn_admin_pods_quiet( function () use ( $field ) {
			if ( ! pods_permission( $field ) ) {
				return false;
			}
			$group = ( is_object( $field ) && method_exists( $field, 'get_group_object' ) ) ? $field->get_group_object() : null;
			return ! $group || pods_permission( $group );
		} );
	} catch ( Throwable $e ) {
		return false;
	}
}

function minn_admin_pods_map_field( $field ) {
	$name = (string) minn_admin_pods_field_arg( $field, 'name', '' );
	$type = (string) minn_admin_pods_field_arg( $field, 'type', '' );
	if ( '' === $name || '' === $type ) {
		return null;
	}
	if ( ! minn_admin_pods_may_see( $field ) ) {
		return null;
	}
	// Pods' own read-only and hidden options: its form renders the first
	// locked and skips the second, so neither is offered as editable here.
	foreach ( array( 'read_only', 'hidden' ) as $flag ) {
		$on = minn_admin_pods_field_arg( $field, $flag, 0 );
		if ( ! empty( $on ) && '0' !== (string) $on ) {
			return null;
		}
	}
	// Chrome / layout-only.
	if ( in_array( $type, array( 'heading', 'html' ), true ) ) {
		return null;
	}
	// Repeatable fields are multi-value — not safe as a single panel input.
	$repeatable = minn_admin_pods_field_arg( $field, 'repeatable', 0 );
	if ( ! empty( $repeatable ) && '0' !== (string) $repeatable ) {
		return null;
	}
	if ( ! isset( MINN_ADMIN_PODS_SIMPLE[ $type ] ) ) {
		return null;
	}
	// Pick: only single custom-simple lists map cleanly to a select.
	if ( 'pick' === $type ) {
		$pick_object = (string) minn_admin_pods_field_arg( $field, 'pick_object', '' );
		$format_type = (string) minn_admin_pods_field_arg( $field, 'pick_format_type', 'single' );
		if ( 'custom-simple' !== $pick_object ) {
			return null;
		}
		if ( $format_type && 'single' !== $format_type ) {
			return null;
		}
		$choices = minn_admin_pods_parse_pick_custom( minn_admin_pods_field_arg( $field, 'pick_custom', '' ) );
		if ( ! $choices ) {
			return null;
		}
		// Empty option so clearing is possible.
		$choices = array( '' => '—' ) + $choices;
		return array(
			'name'    => $name,
			'label'   => (string) ( minn_admin_pods_field_arg( $field, 'label', $name ) ?: $name ),
			'type'    => 'select',
			'choices' => $choices,
		);
	}

	$mapped = array(
		'name'  => $name,
		'label' => (string) ( minn_admin_pods_field_arg( $field, 'label', $name ) ?: $name ),
		'type'  => MINN_ADMIN_PODS_SIMPLE[ $type ],
	);
	$min = minn_admin_pods_field_arg( $field, 'number_min', null );
	if ( null === $min || '' === $min ) {
		$min = minn_admin_pods_field_arg( $field, 'min', null );
	}
	$max = minn_admin_pods_field_arg( $field, 'number_max', null );
	if ( null === $max || '' === $max ) {
		$max = minn_admin_pods_field_arg( $field, 'max', null );
	}
	if ( null !== $min && '' !== $min ) {
		$mapped['min'] = $min;
	}
	if ( null !== $max && '' !== $max ) {
		$mapped['max'] = $max;
	}
	return $mapped;
}

/**
 * Pods that extend a given post type (object match).
 *
 * @param string $post_type Post type slug.
 * @return array List of pod objects (Whatsit) keyed by name.
 */
function minn_admin_pods_for_type( $post_type ) {
	if ( ! minn_admin_pods_active() ) {
		return array();
	}
	try {
		$pods = minn_admin_pods_quiet( function () use ( $post_type ) {
			return pods_api()->load_pods(
				array(
					'type'   => 'post_type',
					'object' => $post_type,
					'fields' => true,
				)
			);
		} );
	} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		return array();
	}
	return is_array( $pods ) ? $pods : array();
}

/**
 * Build the fieldsRoute response for a post.
 *
 * @param int    $post_id   Post ID (0 for new).
 * @param string $post_type Post type slug.
 * @return array{groups: array}
 */
function minn_admin_pods_fields_payload( $post_id, $post_type ) {
	$groups = array();
	foreach ( minn_admin_pods_for_type( $post_type ) as $pod_name => $pod ) {
		$fields = array();
		if ( is_object( $pod ) && method_exists( $pod, 'get_fields' ) ) {
			$fields = (array) $pod->get_fields();
		} elseif ( is_array( $pod ) && ! empty( $pod['fields'] ) ) {
			$fields = (array) $pod['fields'];
		}
		$mapped = array();
		$locked = 0;
		foreach ( $fields as $field ) {
			$type = (string) minn_admin_pods_field_arg( $field, 'type', '' );
			// Heading/html are chrome, not locked data.
			if ( in_array( $type, array( 'heading', 'html' ), true ) ) {
				continue;
			}
			// A field Pods withholds from this user is not locked, it is
			// absent, the way their metabox leaves it out.
			if ( ! minn_admin_pods_may_see( $field ) ) {
				continue;
			}
			$simple = minn_admin_pods_map_field( $field );
			if ( ! $simple ) {
				$locked++;
				continue;
			}
			$mapped[] = $simple;
		}
		if ( $mapped || $locked ) {
			$label = is_object( $pod ) && method_exists( $pod, 'get_arg' )
				? (string) ( $pod->get_arg( 'label' ) ?: $pod_name )
				: (string) ( is_array( $pod ) && ! empty( $pod['label'] ) ? $pod['label'] : $pod_name );
			$groups[] = array(
				'group'  => $label,
				'fields' => $mapped,
				'locked' => $locked,
			);
		}
	}
	return array( 'groups' => $groups );
}

/**
 * Simple field names that apply to a post (for REST get/update).
 *
 * @param int $post_id Post ID.
 * @return array name => panel type
 */
function minn_admin_pods_simple_map_for_post( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return array();
	}
	$map = array();
	foreach ( minn_admin_pods_for_type( $post->post_type ) as $pod ) {
		$fields = array();
		if ( is_object( $pod ) && method_exists( $pod, 'get_fields' ) ) {
			$fields = (array) $pod->get_fields();
		} elseif ( is_array( $pod ) && ! empty( $pod['fields'] ) ) {
			$fields = (array) $pod['fields'];
		}
		foreach ( $fields as $field ) {
			$simple = minn_admin_pods_map_field( $field );
			if ( $simple ) {
				$map[ $simple['name'] ] = $simple['type'];
			}
		}
	}
	return $map;
}

/**
 * Read all simple Pods values for a post as { field_name => value }.
 *
 * @param int $post_id Post ID.
 * @return array
 */
function minn_admin_pods_read_values( $post_id ) {
	$out  = array();
	$post = get_post( $post_id );
	if ( ! $post || ! minn_admin_pods_active() ) {
		return $out;
	}
	$simple = minn_admin_pods_simple_map_for_post( $post_id );
	if ( ! $simple ) {
		return $out;
	}
	// Prefer pods() so relationship/boolean normalization stays Pods' job.
	// Fall back to raw post meta if the pod object won't load.
	$pod_obj = null;
	try {
		$pod_obj = minn_admin_pods_quiet( function () use ( $post ) {
			return pods( $post->post_type, $post->ID );
		} );
		if ( ! $pod_obj || ( method_exists( $pod_obj, 'valid' ) && ! $pod_obj->valid() ) ) {
			$pod_obj = null;
		}
	} catch ( Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
		$pod_obj = null;
	}

	foreach ( $simple as $name => $panel_type ) {
		$val = null;
		try {
			$val = ( $pod_obj && method_exists( $pod_obj, 'field' ) )
				? minn_admin_pods_quiet( function () use ( $pod_obj, $name ) {
					return $pod_obj->field( $name );
				} )
				: get_post_meta( $post_id, $name, true );
		} catch ( Throwable $e ) {
			$val = get_post_meta( $post_id, $name, true );
		}
		if ( 'true_false' === $panel_type ) {
			$out[ $name ] = ! empty( $val ) && '0' !== (string) $val && 'false' !== (string) $val;
		} elseif ( is_array( $val ) ) {
			// Multi-value shouldn't appear for simple fields; stringify edge cases.
			$out[ $name ] = '';
		} else {
			$out[ $name ] = $val;
		}
	}
	return $out;
}

/**
 * A number or currency value as Pods' own form would send it.
 *
 * The panel's number input holds a dot-decimal. Pods parses what it is given
 * in the field's own format: it deletes the thousands separator, then turns
 * the decimal mark into a dot. Where '.' is the thousands separator (a de_DE
 * site's localized default, or an explicit 9.999,99 format), '19.99' would be
 * stored as 1999.00, so the dot becomes the field's decimal mark first.
 *
 * @param string $pod_name Pod (post type) name.
 * @param string $name     Field name.
 * @param string $value    Dot-decimal value from the panel.
 * @return string
 */
function minn_admin_pods_number_in( $pod_name, $name, $value ) {
	if ( ! class_exists( 'PodsForm' ) || ! is_numeric( $value ) ) {
		return $value;
	}
	try {
		return minn_admin_pods_quiet( function () use ( $pod_name, $name, $value ) {
			$field = pods_api()->load_field( array( 'pod' => $pod_name, 'name' => $name ) );
			$type  = $field ? (string) pods_v( 'type', $field ) : '';
			if ( ! in_array( $type, array( 'number', 'currency' ), true ) || 'slider' === pods_v( $type . '_format_type', $field ) ) {
				return $value;
			}
			$loader = PodsForm::field_loader( $type );
			if ( ! $loader || ! method_exists( $loader, 'get_number_format_args' ) ) {
				return $value;
			}
			$args = $loader->get_number_format_args( $field );
			$dot  = isset( $args['dot'] ) ? (string) $args['dot'] : '.';
			return '.' === $dot || '' === $dot ? $value : str_replace( '.', $dot, $value );
		} );
	} catch ( Throwable $e ) {
		return $value;
	}
}

/**
 * Pods' refusal message as plain text: it arrives as markup (a list when
 * there are several), and the editor shows it as text.
 *
 * @param string $message Pods' message.
 * @return string
 */
function minn_admin_pods_reason( $message ) {
	return trim( html_entity_decode( wp_strip_all_tags( str_replace( '</li>', ' </li>', (string) $message ) ), ENT_QUOTES, 'UTF-8' ) );
}

/**
 * Pods' own verdict on one value before it is saved: null when Pods takes
 * it, otherwise its reason ('' when it gave none).
 *
 * PodsAPI::save_pod_item() runs this same check on every field it is handed
 * before it writes any, and one refusal refuses the whole save. Asked field
 * by field first, the values Pods takes are saved and the one it refuses is
 * named. A Pods build without the check (or a pod it cannot describe) skips
 * this; the save itself still refuses, and that refusal is reported.
 *
 * @param object $pod_obj Pods object for the post.
 * @param string $name    Field name.
 * @param mixed  $value   The value as Pods' saver would be handed it.
 * @param int    $post_id Post ID.
 * @return string|null
 */
function minn_admin_pods_refusal( $pod_obj, $name, $value, $post_id ) {
	$pod = isset( $pod_obj->pod_data ) ? $pod_obj->pod_data : null;
	$api = pods_api();
	if ( ! is_object( $pod ) || ! method_exists( $pod, 'get_fields' ) || ! method_exists( $pod, 'get_object_fields' ) || ! method_exists( $api, 'handle_field_validation' ) ) {
		return null;
	}
	try {
		$copy = $value;
		$ok   = $api->handle_field_validation( $copy, $name, $pod->get_object_fields(), $pod->get_fields(), $pod, (object) array(
			'id'   => (int) $post_id,
			'pod'  => $pod['name'],
			'from' => 'save',
		) );
	} catch ( Throwable $e ) {
		return minn_admin_pods_reason( $e->getMessage() );
	}
	// Read as save_pod_item() reads it: anything but true is cast to a list,
	// and only an empty list passes ('' and 0 are refusals there).
	if ( true === $ok || ( ! is_bool( $ok ) && array() === (array) $ok ) ) {
		return null;
	}
	return false === $ok ? '' : minn_admin_pods_reason( implode( ' ', array_map( 'strval', (array) $ok ) ) );
}

/**
 * The fields a save could not write, as one error, or null when there are
 * none. One field keeps its own message; several are named together, and a
 * save that wrote other fields says so. The error data lists each field
 * (key, label, message) for the post answer that reports them.
 *
 * @param object|null $pod_obj Pods object, for the labels.
 * @param array       $refused Field name => Pods' reason ('' for none).
 * @param int         $done    Fields the same save wrote.
 * @return WP_Error|null
 */
function minn_admin_pods_write_errors( $pod_obj, $refused, $done ) {
	if ( ! $refused ) {
		return null;
	}
	$fields = array();
	$names  = array();
	foreach ( $refused as $name => $reason ) {
		$label = '';
		try {
			$def   = ( $pod_obj && isset( $pod_obj->pod_data ) && is_object( $pod_obj->pod_data ) && method_exists( $pod_obj->pod_data, 'get_field' ) ) ? $pod_obj->pod_data->get_field( $name ) : null;
			$label = $def ? (string) minn_admin_pods_field_arg( $def, 'label', '' ) : '';
		} catch ( Throwable $e ) {
			$label = '';
		}
		$label = '' !== $label ? $label : (string) $name;
		if ( '' !== (string) $reason ) {
			/* translators: 1: field label, 2: the reason Pods gave. */
			$message = sprintf( __( '“%1$s” was not saved: %2$s', 'minn-admin' ), $label, $reason );
		} else {
			/* translators: %s: field label. */
			$message = sprintf( __( '“%s” was not saved: Pods did not accept that value.', 'minn-admin' ), $label );
		}
		$fields[] = array(
			'key'     => (string) $name,
			'label'   => $label,
			'message' => $message,
		);
		$names[]  = '“' . $label . '”';
	}
	if ( 1 === count( $fields ) ) {
		$message = $fields[0]['message'];
	} else {
		/* translators: %s: comma-separated list of field labels, each in quotes. */
		$message = sprintf( __( 'These fields were not saved: %s.', 'minn-admin' ), implode( ', ', $names ) );
	}
	if ( $done > 0 ) {
		$message .= ' ' . __( 'Your other changes were saved.', 'minn-admin' );
	}
	return new WP_Error( 'minn_pods_refused', $message, array( 'status' => 400, 'fields' => $fields ) );
}

/**
 * Write simple field values through Pods' own saver.
 *
 * A value Pods refuses is never written another way: it is named in the
 * answer and the stored value stays.
 *
 * @param int   $post_id Post ID.
 * @param array $values  Field name => value.
 * @return WP_Error|null Every field that was not written, named in one
 *                       error; the others are written either way.
 */
function minn_admin_pods_write_values( $post_id, $values ) {
	if ( ! is_array( $values ) || ! minn_admin_pods_active() ) {
		return null;
	}
	$post = get_post( $post_id );
	if ( ! $post ) {
		return null;
	}
	$allowed = minn_admin_pods_simple_map_for_post( $post_id );
	// The panel sends every field back when any one of them changes. A value
	// equal to what the read handed out is left as stored, so an untouched
	// field never goes back through Pods' parser.
	$held = minn_admin_pods_read_values( $post_id );
	$data = array();
	foreach ( $values as $key => $value ) {
		if ( ! isset( $allowed[ $key ] ) ) {
			continue;
		}
		$panel_type = $allowed[ $key ];
		if ( 'true_false' === $panel_type ) {
			$value = ( ! empty( $value ) && 'false' !== $value && '0' !== (string) $value ) ? 1 : 0;
		}
		if ( array_key_exists( $key, $held ) && ( is_scalar( $value ) || null === $value ) && ( is_scalar( $held[ $key ] ) || null === $held[ $key ] ) ) {
			$was = $held[ $key ];
			if ( 'true_false' === $panel_type ) {
				$same = (bool) $value === (bool) $was;
			} elseif ( 'number' === $panel_type && is_numeric( $value ) && is_numeric( $was ) ) {
				$same = (float) $value === (float) $was;
			} else {
				// Or as served, a credential copy masked.
				$same = (string) $value === (string) $was || Minn_Admin_Egress::served_as( (string) $value, (string) $was );
			}
			if ( $same ) {
				continue;
			}
		}
		// Empty select: clear meta.
		if ( ( '' === $value || null === $value ) && 'true_false' !== $panel_type ) {
			$data[ $key ] = '';
			continue;
		}
		if ( 'url' === $panel_type ) {
			// The house rule every other URL writer applies: an address that
			// runs code instead of going somewhere is refused, and refusing
			// it leaves the stored address alone.
			$clean = minn_admin_url_clean( $value );
			if ( null === $clean ) {
				continue;
			}
			$value = $clean;
		}
		$data[ $key ] = $value;
	}
	if ( ! $data ) {
		return null;
	}
	// pods()->save accepts a field map for an existing item, and parses
	// numbers in each field's own format.
	$in = $data;
	foreach ( $in as $key => $value ) {
		if ( 'number' === $allowed[ $key ] && is_scalar( $value ) && '' !== (string) $value ) {
			$in[ $key ] = minn_admin_pods_number_in( $post->post_type, $key, (string) $value );
		}
	}
	$refused = array();
	$pod_obj = null;
	try {
		$pod_obj = minn_admin_pods_quiet( function () use ( $post ) {
			return pods( $post->post_type, $post->ID );
		} );
	} catch ( Throwable $e ) {
		$pod_obj = null;
	}
	if ( ! $pod_obj || ! method_exists( $pod_obj, 'save' ) || ( method_exists( $pod_obj, 'valid' ) && ! $pod_obj->valid() ) ) {
		// No saver to hand them to: nothing is written behind Pods' back.
		return minn_admin_pods_write_errors( null, array_fill_keys( array_keys( $in ), '' ), 0 );
	}
	foreach ( $in as $key => $value ) {
		$reason = minn_admin_pods_quiet( function () use ( $pod_obj, $key, $value, $post_id ) {
			return minn_admin_pods_refusal( $pod_obj, $key, $value, $post_id );
		} );
		if ( null !== $reason ) {
			$refused[ $key ] = $reason;
			unset( $in[ $key ] );
		}
	}
	if ( $in ) {
		try {
			minn_admin_pods_quiet( function () use ( $pod_obj, $in ) {
				return $pod_obj->save( $in );
			} );
		} catch ( Throwable $e ) {
			// A refusal the check above did not foresee (a hook on Pods' own
			// save). Pods checks every field before it writes any, but such a
			// hook can also throw after, so the store says which were
			// written. None is written another way.
			$reason = minn_admin_pods_reason( $e->getMessage() );
			$now    = minn_admin_pods_read_values( $post_id );
			foreach ( array_keys( $in ) as $key ) {
				$a = $now[ $key ] ?? null;
				$b = $held[ $key ] ?? null;
				if ( is_bool( $a ) || is_bool( $b ) ? (bool) $a === (bool) $b : (string) $a === (string) $b ) {
					$refused[ $key ] = $reason;
					unset( $in[ $key ] );
				}
			}
		}
	}
	return minn_admin_pods_write_errors( $pod_obj, $refused, count( $in ) );
}

add_filter( 'minn_admin_editor_panels', function ( $panels ) {
	if ( ! minn_admin_pods_active() ) {
		return $panels;
	}
	$panels['pods'] = array(
		'label'       => __( 'Custom fields', 'minn-admin' ),
		'sub'         => 'Pods',
		'cap'         => 'edit_posts',
		'fieldsRoute' => 'minn-admin/v1/pods/fields?post_id={id}&post_type={type}',
		'valuesKey'   => 'minn_pods',
		'writeKey'    => 'minn_pods',
	);
	return $panels;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_pods_active() ) {
		return;
	}

	minn_admin_endpoint( '/pods/fields', array(
		'methods'             => 'GET',
		'gate'                => function () {
			return current_user_can( 'edit_posts' );
		},
		'args'                => array(
			'post_id'   => array( 'type' => 'integer' ),
			'post_type' => array( 'type' => 'string' ),
		),
		'run'                 => function ( WP_REST_Request $request ) {
			$post_id   = (int) $request['post_id'];
			$post_type = minn_admin_pods_resolve_type( (string) ( $request['post_type'] ?? 'posts' ) );
			if ( $post_id ) {
				$post = get_post( $post_id );
				// A missing post used to fall THROUGH with the caller's own
				// post_type intact, which is the same hole as post_id=0.
				if ( ! $post ) {
					return new WP_Error( 'not_found', __( 'Post not found.', 'minn-admin' ), array( 'status' => 404 ) );
				}
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return new WP_Error( 'rest_forbidden', __( 'You cannot edit this post.', 'minn-admin' ), array( 'status' => 403 ) );
				}
				$post_type = $post->post_type;
			} else {
				// No post to authorize against, so authorize against the TYPE.
				// edit_posts alone let a Contributor enumerate field labels,
				// names, types and choice lists for types they cannot edit —
				// internal naming and credential field ids are common there.
				$type_obj = get_post_type_object( $post_type );
				if ( ! $type_obj || ! current_user_can( $type_obj->cap->edit_posts ) ) {
					return new WP_Error( 'rest_forbidden', __( 'You cannot edit that post type.', 'minn-admin' ), array( 'status' => 403 ) );
				}
			}
			return rest_ensure_response( minn_admin_pods_fields_payload( $post_id, $post_type ) );
		},
	) );

	$types = get_post_types( array( 'show_in_rest' => true ), 'names' );
	foreach ( $types as $type ) {
		register_rest_field(
			$type,
			'minn_pods',
			array(
				'get_callback'    => function ( $obj ) {
					$id = isset( $obj['id'] ) ? (int) $obj['id'] : 0;
					if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
						return new stdClass();
					}
					return (object) minn_admin_pods_read_values( $id );
				},
				'update_callback' => function ( $value, $post, $field_name = '', $request = null ) {
					if ( ! $post instanceof WP_Post ) {
						return;
					}
					if ( ! current_user_can( 'edit_post', $post->ID ) ) {
						return;
					}
					if ( is_object( $value ) ) {
						$value = (array) $value;
					}
					if ( ! is_array( $value ) ) {
						return;
					}
					$error = minn_admin_pods_write_values( $post->ID, $value );
					if ( ! is_wp_error( $error ) ) {
						return;
					}
					// Never an error from here: the post is already saved, a
					// new one included. The refusal rides on the post's own
					// answer instead, beside any other panel's
					// (minn_admin_panel_report_held(), adapters/acpt.php).
					if ( $request instanceof WP_REST_Request && function_exists( 'minn_admin_panel_held' ) ) {
						minn_admin_panel_held( $request, $error, 'minn_pods' );
						return;
					}
					return $error;
				},
				'schema'          => array(
					'description' => __( 'Pods simple field values for Minn Admin.', 'minn-admin' ),
					'type'        => 'object',
					'context'     => array( 'edit' ),
				),
			)
		);
	}
} );
