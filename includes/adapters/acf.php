<?php
/**
 * Bundled adapter: Advanced Custom Fields (free and Pro).
 *
 * The proving adapter for the editor-panels framework. Values used to ride
 * ACF's own `acf` REST object, which only exists for field groups with
 * "Show in REST API" enabled — off by default, so on most real sites the
 * panel never rendered at all. Values now ride a dedicated `minn_acf` REST
 * field (the Meta Box / Pods pattern), read and written through ACF's own
 * get_field / update_field by field key, so every applicable group shows up
 * regardless of that setting. Complex field types the app has no control for
 * (relationships, flexible content, clone…) defer to wp-admin, mirroring the
 * editor's locked-mode philosophy.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

// color_picker stores a plain color string and renders as a text input;
// image stores an attachment id and rides the form engine's image control
// ({ id, url }) in the editor panel and a pick-button row in the inspector;
// gallery stores an ordered attachment-id array and rides the islands
// images editor in items mode (reorder / replace / remove / add); wysiwyg
// stores an HTML fragment and edits in the rich-text modal; checkbox (and a
// select with `multiple`) stores an ordered choice-key array and rides the
// engine's multicheck control; button_group is a styled radio.
// Date fields map onto the app's own picker: date_picker stores 'Ymd' and
// rides the engine's date control ('YYYY-MM-DD'), date_time_picker stores
// 'Y-m-d H:i:s' and rides datetime ('YYYY-MM-DDTHH:mm'), time_picker stores
// 'H:i:s' and rides the lenient time text control ('HH:mm').
// File stores an attachment id like image, but rides the file control (any
// attachment type, filename shown instead of a thumb).
// Relational fields (post_object / relationship / page_link / taxonomy /
// user) map onto ONE picker pair: `suggest` (single, async search) and
// `relation` (ordered multi with chips), both searching through the
// /acf/relation route which honors each field's own constraints.
const MINN_ADMIN_ACF_SIMPLE_TYPES = array( 'text', 'textarea', 'number', 'range', 'email', 'url', 'select', 'radio', 'button_group', 'checkbox', 'true_false', 'color_picker', 'image', 'gallery', 'file', 'wysiwyg', 'date_picker', 'date_time_picker', 'time_picker', 'post_object', 'relationship', 'page_link', 'taxonomy', 'user' );

/** Layout-only ACF field types: chrome, not data — never mapped, never counted as locked. */
const MINN_ADMIN_ACF_CHROME_TYPES = array( 'tab', 'message', 'accordion' );

/**
 * Stored image value (attachment id) → the { id, url } shape the image
 * control speaks, or '' when unset.
 *
 * @param mixed $val Raw stored value.
 * @return array|string
 */
function minn_admin_acf_image_out( $val ) {
	$id = is_numeric( $val ) ? (int) $val : 0;
	return $id > 0 ? array(
		'id'  => $id,
		'url' => (string) wp_get_attachment_image_url( $id, 'thumbnail' ),
	) : '';
}

/**
 * Stored gallery value (ordered attachment-id list) → [{ id, url }].
 *
 * @param mixed $val Raw stored value.
 * @return array[]
 */
function minn_admin_acf_gallery_out( $val ) {
	$items = array();
	foreach ( (array) $val as $gid ) {
		if ( is_numeric( $gid ) && (int) $gid > 0 ) {
			$items[] = array(
				'id'  => (int) $gid,
				'url' => (string) wp_get_attachment_image_url( (int) $gid, 'thumbnail' ),
			);
		}
	}
	return $items;
}

/**
 * Incoming image value ({ id, url }, a bare id, or empty) → validated
 * attachment id, or '' to clear (ACF's own form save stores '').
 *
 * @param mixed $value Incoming value.
 * @return int|string
 */
function minn_admin_acf_image_in( $value ) {
	if ( is_array( $value ) || is_object( $value ) ) {
		$value = (array) $value;
		$value = isset( $value['id'] ) ? $value['id'] : 0;
	}
	return is_numeric( $value ) && (int) $value > 0 && 'attachment' === get_post_type( (int) $value ) ? (int) $value : '';
}

/**
 * Stored multi-choice value (checkbox / multiple select) → list of strings.
 *
 * @param mixed $val Raw stored value.
 * @return string[]
 */
function minn_admin_acf_choices_out( $val ) {
	$out = array();
	foreach ( (array) $val as $x ) {
		if ( is_scalar( $x ) ) {
			$out[] = (string) $x;
		}
	}
	return $out;
}

/**
 * Incoming multi-choice value → deduped list of strings, whitelisted against
 * the field's choices (skipped for ACF checkbox `allow_custom` fields, whose
 * custom entries are legitimate values).
 *
 * @param mixed $value Incoming value.
 * @param array $field Mapped field ({ choices, anyChoice }).
 * @return string[]
 */
function minn_admin_acf_choices_in( $value, $field ) {
	$keys = array();
	if ( empty( $field['anyChoice'] ) && ! empty( $field['choices'] ) && is_array( $field['choices'] ) ) {
		$keys = array_map( 'strval', array_keys( $field['choices'] ) );
	}
	$out = array();
	foreach ( (array) $value as $v ) {
		if ( ! is_scalar( $v ) ) {
			continue;
		}
		$v = (string) $v;
		if ( $keys && ! in_array( $v, $keys, true ) ) {
			continue;
		}
		if ( ! in_array( $v, $out, true ) ) {
			$out[] = $v;
		}
	}
	return $out;
}

/**
 * Stored file value (attachment id) → { id, url, name } for the file
 * control, or '' when unset. Writes reuse minn_admin_acf_image_in — a file
 * is the same validated attachment id.
 *
 * @param mixed $val Raw stored value.
 * @return array|string
 */
function minn_admin_acf_file_out( $val ) {
	$id = is_numeric( $val ) ? (int) $val : 0;
	if ( $id <= 0 ) {
		return '';
	}
	$url = (string) wp_get_attachment_url( $id );
	$name = $url ? wp_basename( $url ) : '';
	return array(
		'id'   => $id,
		'url'  => $url,
		'name' => $name ? $name : '#' . $id,
	);
}

/**
 * Stored ACF date ('Ymd') → the date control's 'YYYY-MM-DD' ('' when unset).
 *
 * @param mixed $val Raw stored value.
 * @return string
 */
function minn_admin_acf_date_out( $val ) {
	$val = is_scalar( $val ) ? (string) $val : '';
	if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $val, $m ) ) {
		return $m[1] . '-' . $m[2] . '-' . $m[3];
	}
	return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $val ) ? $val : '';
}

/**
 * Incoming date ('YYYY-MM-DD' or 'Ymd') → ACF's stored 'Ymd'.
 * '' clears; null means invalid — the caller skips the write.
 *
 * @param mixed $value Incoming value.
 * @return string|null
 */
function minn_admin_acf_date_in( $value ) {
	if ( null === $value || false === $value || '' === $value ) {
		return '';
	}
	if ( ! is_scalar( $value ) ) {
		return null;
	}
	$value = (string) $value;
	if ( preg_match( '/^(\d{4})-?(\d{2})-?(\d{2})$/', $value, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
		return $m[1] . $m[2] . $m[3];
	}
	return null;
}

/**
 * Stored ACF datetime ('Y-m-d H:i:s') → 'YYYY-MM-DDTHH:mm' ('' when unset).
 *
 * @param mixed $val Raw stored value.
 * @return string
 */
function minn_admin_acf_datetime_out( $val ) {
	$val = is_scalar( $val ) ? (string) $val : '';
	return preg_match( '/^(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2})(:\d{2})?$/', $val, $m ) ? $m[1] . 'T' . $m[2] : '';
}

/**
 * Incoming datetime → ACF's stored 'Y-m-d H:i:s'. '' clears; null = invalid.
 *
 * @param mixed $value Incoming value.
 * @return string|null
 */
function minn_admin_acf_datetime_in( $value ) {
	if ( null === $value || false === $value || '' === $value ) {
		return '';
	}
	if ( ! is_scalar( $value ) ) {
		return null;
	}
	if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(:\d{2})?$/', (string) $value, $m )
		&& checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) && (int) $m[4] < 24 && (int) $m[5] < 60 ) {
		return $m[1] . '-' . $m[2] . '-' . $m[3] . ' ' . $m[4] . ':' . $m[5] . ( $m[6] ? $m[6] : ':00' );
	}
	return null;
}

/**
 * Stored ACF time ('H:i:s') → 'HH:mm' ('' when unset).
 *
 * @param mixed $val Raw stored value.
 * @return string
 */
function minn_admin_acf_time_out( $val ) {
	$val = is_scalar( $val ) ? (string) $val : '';
	return preg_match( '/^(\d{2}:\d{2})(:\d{2})?$/', $val, $m ) ? $m[1] : '';
}

/**
 * Incoming time → ACF's stored 'H:i:s'. '' clears; null = invalid.
 *
 * @param mixed $value Incoming value.
 * @return string|null
 */
function minn_admin_acf_time_in( $value ) {
	if ( null === $value || false === $value || '' === $value ) {
		return '';
	}
	if ( ! is_scalar( $value ) ) {
		return null;
	}
	if ( preg_match( '/^(\d{1,2}):(\d{2})(:\d{2})?$/', (string) $value, $m ) && (int) $m[1] < 24 && (int) $m[2] < 60 ) {
		return str_pad( $m[1], 2, '0', STR_PAD_LEFT ) . ':' . $m[2] . ( $m[3] ? $m[3] : ':00' );
	}
	return null;
}

/**
 * Incoming gallery value ([{ id, url }] entries or bare ids) → validated
 * attachment-id list. An empty list clears (ACF stores an empty array).
 *
 * @param mixed $value Incoming value.
 * @return int[]
 */
function minn_admin_acf_gallery_in( $value ) {
	$ids = array();
	foreach ( (array) $value as $entry ) {
		$id = minn_admin_acf_image_in( $entry );
		if ( '' !== $id ) {
			$ids[] = $id;
		}
	}
	return $ids;
}

/**
 * @return bool
 */
function minn_admin_acf_active() {
	return function_exists( 'acf_get_field_groups' )
		&& function_exists( 'acf_get_fields' )
		&& function_exists( 'get_field' )
		&& function_exists( 'update_field' );
}

/**
 * Field groups that apply to a post (or, for new posts, a post type).
 *
 * acf_get_field_groups applies ACF's own location rules, so block- and
 * options-page-scoped groups never match a post edit screen.
 *
 * @param int    $post_id   Post ID (0 for new).
 * @param string $post_type Post type slug.
 * @return array
 */
function minn_admin_acf_groups_for( $post_id, $post_type ) {
	return acf_get_field_groups( $post_id ? array( 'post_id' => $post_id ) : array( 'post_type' => $post_type ) );
}

/**
 * Map one ACF field onto the panel vocabulary, or null if locked.
 *
 * ACF types normalize onto engine types: button_group is a styled radio;
 * checkbox and a select with `multiple` are the same multi-value control
 * (`multicheck`, value = ordered choice-key list). `multiple` on anything
 * else stays locked.
 *
 * @param array $f ACF field array.
 * @return array|null { name, label, type, choices?, min?, max?, anyChoice?, key }
 */
function minn_admin_acf_map_field( $f ) {
	if ( empty( $f['name'] ) || empty( $f['type'] ) ) {
		return null;
	}
	if ( ! in_array( $f['type'], MINN_ADMIN_ACF_SIMPLE_TYPES, true ) ) {
		return null;
	}
	$type = $f['type'];
	if ( 'button_group' === $type ) {
		$type = 'radio';
	} elseif ( 'checkbox' === $type || ( 'select' === $type && ! empty( $f['multiple'] ) ) ) {
		$type = 'multicheck';
	} elseif ( 'date_picker' === $type ) {
		$type = 'date';
	} elseif ( 'date_time_picker' === $type ) {
		$type = 'datetime';
	} elseif ( 'time_picker' === $type ) {
		$type = 'time';
	} elseif ( in_array( $type, array( 'post_object', 'page_link', 'user' ), true ) ) {
		$type = ! empty( $f['multiple'] ) ? 'relation' : 'suggest';
	} elseif ( 'relationship' === $type ) {
		$type = 'relation'; // always an ordered multi
	} elseif ( 'taxonomy' === $type ) {
		// The field_type setting decides single vs multi, not `multiple`.
		$type = in_array( $f['field_type'] ?? '', array( 'checkbox', 'multi_select' ), true ) ? 'relation' : 'suggest';
	}
	if ( ! empty( $f['multiple'] ) && ! in_array( $type, array( 'multicheck', 'relation' ), true ) ) {
		return null;
	}
	$out = array(
		'name'      => $f['name'],
		'label'     => $f['label'],
		'type'      => $type,
		'choices'   => ! empty( $f['choices'] ) ? $f['choices'] : null,
		'min'       => isset( $f['min'] ) && '' !== $f['min'] ? $f['min'] : null,
		'max'       => isset( $f['max'] ) && '' !== $f['max'] ? $f['max'] : null,
		'anyChoice' => ! empty( $f['allow_custom'] ) ? true : null,
		'key'       => $f['key'],
	);
	if ( in_array( $type, array( 'suggest', 'relation' ), true ) ) {
		$out['route'] = 'minn-admin/v1/acf/relation?field=' . rawurlencode( $f['key'] );
	}
	return $out;
}

/**
 * The object kind behind a relational ACF field: post, term or user.
 *
 * @param array $acf_field Raw ACF field array.
 * @return string 'post' | 'term' | 'user' | ''
 */
function minn_admin_acf_relation_kind( $acf_field ) {
	$t = is_array( $acf_field ) ? ( $acf_field['type'] ?? '' ) : '';
	if ( in_array( $t, array( 'post_object', 'relationship', 'page_link' ), true ) ) {
		return 'post';
	}
	if ( 'taxonomy' === $t ) {
		return 'term';
	}
	if ( 'user' === $t ) {
		return 'user';
	}
	return '';
}

/**
 * One stored relational id → the picker's { value, label } row, or null when
 * the object is gone. Non-published posts carry their status in the label.
 *
 * @param string $kind post | term | user.
 * @param mixed  $id   Stored id.
 * @return array|null
 */
function minn_admin_acf_relation_entry( $kind, $id ) {
	if ( 'post' === $kind ) {
		$p = get_post( (int) $id );
		if ( ! $p || in_array( $p->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return null;
		}
		$label = '' !== $p->post_title ? $p->post_title : '#' . $p->ID;
		if ( 'publish' !== $p->post_status ) {
			$status = get_post_status_object( $p->post_status );
			$label .= ' (' . ( $status ? $status->label : $p->post_status ) . ')';
		}
		return array( 'value' => (string) $p->ID, 'label' => $label );
	}
	if ( 'term' === $kind ) {
		$t = get_term( (int) $id );
		return ( $t && ! is_wp_error( $t ) ) ? array( 'value' => (string) $t->term_id, 'label' => $t->name ) : null;
	}
	if ( 'user' === $kind ) {
		$u = get_userdata( (int) $id );
		return $u ? array( 'value' => (string) $u->ID, 'label' => $u->display_name ? $u->display_name : $u->user_login ) : null;
	}
	return null;
}

/**
 * Stored single relational value → { value, label } or ''. page_link archive
 * URLs (non-numeric strings) pass through with the URL as their own label.
 *
 * @param string $key Field key.
 * @param mixed  $val Raw stored value.
 * @return array|string
 */
function minn_admin_acf_suggest_out( $key, $val ) {
	if ( is_array( $val ) ) {
		$val = reset( $val ); // defensive: a single field holding an array
	}
	if ( null === $val || false === $val || '' === $val ) {
		return '';
	}
	$acf  = acf_get_field( $key );
	$kind = minn_admin_acf_relation_kind( $acf );
	if ( 'post' === $kind && ! is_numeric( $val ) ) {
		return array( 'value' => (string) $val, 'label' => (string) $val );
	}
	$entry = minn_admin_acf_relation_entry( $kind, $val );
	return $entry ? $entry : '';
}

/**
 * Stored relational list → ordered [{ value, label }] rows.
 *
 * @param string $key Field key.
 * @param mixed  $val Raw stored value.
 * @return array[]
 */
function minn_admin_acf_relation_list_out( $key, $val ) {
	$acf  = acf_get_field( $key );
	$kind = minn_admin_acf_relation_kind( $acf );
	$out  = array();
	foreach ( (array) $val as $id ) {
		if ( ! is_scalar( $id ) || '' === $id ) {
			continue;
		}
		if ( 'post' === $kind && ! is_numeric( $id ) ) {
			$out[] = array( 'value' => (string) $id, 'label' => (string) $id );
			continue;
		}
		$entry = minn_admin_acf_relation_entry( $kind, $id );
		if ( $entry ) {
			$out[] = $entry;
		}
	}
	return $out;
}

/**
 * Validate one incoming relational value against the field's own
 * constraints. Returns the storable id, '' to clear, or null when invalid
 * (the caller skips the write, never clobbering the stored value).
 *
 * @param array $acf   Raw ACF field array.
 * @param mixed $value { value, label }, a bare id, or empty.
 * @return string|int|null
 */
function minn_admin_acf_relation_id_in( $acf, $value ) {
	if ( is_array( $value ) || is_object( $value ) ) {
		$value = (array) $value;
		$value = isset( $value['value'] ) ? $value['value'] : null;
	}
	if ( null === $value || false === $value || '' === $value ) {
		return '';
	}
	if ( ! is_scalar( $value ) ) {
		return null;
	}
	$kind = minn_admin_acf_relation_kind( $acf );
	if ( 'post' === $kind ) {
		// page_link archives are stored as URL strings by ACF itself; pass
		// an untouched one back through so a whole-panel save can't drop it.
		if ( ! is_numeric( $value ) ) {
			$value = (string) $value;
			return ( 'page_link' === $acf['type'] && $value === esc_url_raw( $value ) && 0 === strpos( $value, 'http' ) ) ? $value : null;
		}
		$p = get_post( (int) $value );
		if ( ! $p || in_array( $p->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			return null;
		}
		if ( ! empty( $acf['post_type'] ) && ! in_array( $p->post_type, (array) $acf['post_type'], true ) ) {
			return null;
		}
		return (string) $p->ID;
	}
	if ( 'term' === $kind ) {
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		$t = get_term( (int) $value );
		if ( ! $t || is_wp_error( $t ) ) {
			return null;
		}
		if ( ! empty( $acf['taxonomy'] ) && $t->taxonomy !== $acf['taxonomy'] ) {
			return null;
		}
		return (int) $t->term_id; // ACF's own taxonomy save stores ints
	}
	if ( 'user' === $kind ) {
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		$u = get_userdata( (int) $value );
		if ( ! $u ) {
			return null;
		}
		if ( ! empty( $acf['role'] ) && ! array_intersect( (array) $acf['role'], (array) $u->roles ) ) {
			return null;
		}
		return (string) $u->ID;
	}
	return null;
}

/**
 * Incoming single relational value → stored id ('' clears, null = invalid).
 *
 * @param string $key   Field key.
 * @param mixed  $value Incoming value.
 * @return string|int|null
 */
function minn_admin_acf_suggest_in( $key, $value ) {
	$acf = acf_get_field( $key );
	return $acf ? minn_admin_acf_relation_id_in( $acf, $value ) : null;
}

/**
 * Incoming relational list → ordered stored-id list; invalid entries drop,
 * an empty list clears.
 *
 * @param string $key   Field key.
 * @param mixed  $value Incoming value.
 * @return array
 */
function minn_admin_acf_relation_in( $key, $value ) {
	$acf = acf_get_field( $key );
	$out = array();
	if ( ! $acf ) {
		return $out;
	}
	foreach ( (array) $value as $entry ) {
		$id = minn_admin_acf_relation_id_in( $acf, $entry );
		if ( null !== $id && '' !== $id && ! in_array( $id, $out, true ) ) {
			$out[] = $id;
		}
	}
	return $out;
}

/**
 * Map an ACF repeater (Pro) onto the panel's `rows` control, or null when no
 * sub-field is editable. One level deep: sub-fields from the simple set edit
 * in rows — including image (media picker) and gallery (images editor) since
 * the row cards learned those controls; nested-repeater and other complex
 * subs count as locked per row, and their stored values are PRESERVED by the
 * write path's row merge (an edit overlays only the mapped subs onto the
 * original row).
 *
 * @param array $f ACF repeater field array.
 * @return array|null { name, label, type: 'rows', subfields, subLocked, key, subs }
 */
function minn_admin_acf_map_repeater( $f ) {
	if ( empty( $f['name'] ) || empty( $f['key'] ) ) {
		return null;
	}
	$subs   = array();
	$locked = 0;
	foreach ( (array) ( $f['sub_fields'] ?? array() ) as $sub ) {
		if ( in_array( $sub['type'] ?? '', MINN_ADMIN_ACF_CHROME_TYPES, true ) ) {
			continue;
		}
		// Subs share the field map — everything simple except wysiwyg (the
		// row cards have no rich-text seat) and the relational pickers (no
		// arming in the rows dialect yet).
		$m = minn_admin_acf_map_field( $sub );
		if ( ! $m || in_array( $m['type'], array( 'wysiwyg', 'suggest', 'relation' ), true ) ) {
			$locked++;
			continue;
		}
		$subs[] = array(
			'name'      => $m['name'],
			'label'     => $m['label'],
			'type'      => $m['type'],
			'choices'   => $m['choices'],
			'anyChoice' => $m['anyChoice'],
			'key'       => $m['key'],
		);
	}
	if ( ! $subs ) {
		return null;
	}
	return array(
		'name'      => $f['name'],
		'label'     => $f['label'],
		'type'      => 'rows',
		'subfields' => array_map( function ( $s ) {
			unset( $s['key'] ); // internal — the client keys sub-inputs by name
			return $s;
		}, $subs ),
		'subLocked' => $locked,
		'key'       => $f['key'],
		'subs'      => $subs,
	);
}

/**
 * Build the fieldsRoute response for a post.
 *
 * @param int    $post_id   Post ID (0 for new).
 * @param string $post_type Post type slug.
 * @return array{groups: array}
 */
function minn_admin_acf_fields_payload( $post_id, $post_type ) {
	$out = array();
	foreach ( minn_admin_acf_groups_for( $post_id, $post_type ) as $group ) {
		$fields = acf_get_fields( $group );
		$mapped = array();
		$locked = 0;
		foreach ( (array) $fields as $f ) {
			if ( in_array( $f['type'] ?? '', MINN_ADMIN_ACF_CHROME_TYPES, true ) ) {
				continue;
			}
			// Repeaters (Pro) ride the `rows` control when any sub is simple.
			// Panel-only: options pages and block dataForms keep them locked
			// (their engines have no rows binding).
			if ( 'repeater' === ( $f['type'] ?? '' ) ) {
				$rep = minn_admin_acf_map_repeater( $f );
				if ( $rep ) {
					unset( $rep['key'], $rep['subs'] );
					$mapped[] = $rep;
				} else {
					$locked++;
				}
				continue;
			}
			$simple = minn_admin_acf_map_field( $f );
			if ( ! $simple ) {
				$locked++;
				continue;
			}
			unset( $simple['key'] ); // internal — the panel keys inputs by name
			$mapped[] = $simple;
		}
		if ( $mapped || $locked ) {
			$out[] = array(
				'group'  => $group['title'],
				'fields' => $mapped,
				'locked' => $locked,
			);
		}
	}
	return array( 'groups' => $out );
}

/**
 * All simple fields that apply to a post, keyed by field name.
 *
 * @param int $post_id Post ID.
 * @return array { name => { name, type, key, … } }
 */
function minn_admin_acf_simple_fields_for_post( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post ) {
		return array();
	}
	$out = array();
	foreach ( minn_admin_acf_groups_for( $post_id, $post->post_type ) as $group ) {
		foreach ( (array) acf_get_fields( $group ) as $f ) {
			if ( 'repeater' === ( $f['type'] ?? '' ) ) {
				$rep = minn_admin_acf_map_repeater( $f );
				if ( $rep ) {
					$out[ $rep['name'] ] = $rep;
				}
				continue;
			}
			$simple = minn_admin_acf_map_field( $f );
			if ( $simple ) {
				$out[ $simple['name'] ] = $simple;
			}
		}
	}
	return $out;
}

/**
 * Read all simple ACF values for a post as { field_name => value }.
 *
 * Raw (unformatted) values: a select's stored choice key is what the panel's
 * choices map wants; format_value could hand back labels or arrays depending
 * on each field's return_format.
 *
 * @param int $post_id Post ID.
 * @return array
 */
function minn_admin_acf_read_values( $post_id ) {
	$out = array();
	foreach ( minn_admin_acf_simple_fields_for_post( $post_id ) as $name => $field ) {
		$val = get_field( $field['key'], $post_id, false );
		if ( 'true_false' === $field['type'] ) {
			$out[ $name ] = ! empty( $val );
		} elseif ( 'image' === $field['type'] ) {
			// The form engine's image control speaks { id, url }.
			$out[ $name ] = minn_admin_acf_image_out( $val );
		} elseif ( 'gallery' === $field['type'] ) {
			// The gallery control speaks an ordered [{ id, url }] list.
			$out[ $name ] = minn_admin_acf_gallery_out( $val );
		} elseif ( 'file' === $field['type'] ) {
			$out[ $name ] = minn_admin_acf_file_out( $val );
		} elseif ( 'suggest' === $field['type'] ) {
			$out[ $name ] = minn_admin_acf_suggest_out( $field['key'], $val );
		} elseif ( 'relation' === $field['type'] ) {
			$out[ $name ] = minn_admin_acf_relation_list_out( $field['key'], $val );
		} elseif ( 'multicheck' === $field['type'] ) {
			$out[ $name ] = minn_admin_acf_choices_out( $val );
		} elseif ( 'date' === $field['type'] ) {
			$out[ $name ] = minn_admin_acf_date_out( $val );
		} elseif ( 'datetime' === $field['type'] ) {
			$out[ $name ] = minn_admin_acf_datetime_out( $val );
		} elseif ( 'time' === $field['type'] ) {
			$out[ $name ] = minn_admin_acf_time_out( $val );
		} elseif ( 'rows' === $field['type'] ) {
			// Repeater rows: [{ __idx, values }] — __idx is the row's position
			// in ACF's stored rows, the write path's merge anchor. Raw rows key
			// by subfield KEY; the client speaks names.
			$rows = array();
			foreach ( array_values( is_array( $val ) ? $val : array() ) as $i => $raw_row ) {
				$vals = array();
				foreach ( $field['subs'] as $sub ) {
					$v = is_array( $raw_row ) && array_key_exists( $sub['key'], $raw_row ) ? $raw_row[ $sub['key'] ] : null;
					if ( 'true_false' === $sub['type'] ) {
						$vals[ $sub['name'] ] = ! empty( $v );
					} elseif ( 'image' === $sub['type'] ) {
						$vals[ $sub['name'] ] = minn_admin_acf_image_out( $v );
					} elseif ( 'gallery' === $sub['type'] ) {
						$vals[ $sub['name'] ] = minn_admin_acf_gallery_out( $v );
					} elseif ( 'file' === $sub['type'] ) {
						$vals[ $sub['name'] ] = minn_admin_acf_file_out( $v );
					} elseif ( 'multicheck' === $sub['type'] ) {
						$vals[ $sub['name'] ] = minn_admin_acf_choices_out( $v );
					} elseif ( 'date' === $sub['type'] ) {
						$vals[ $sub['name'] ] = minn_admin_acf_date_out( $v );
					} elseif ( 'datetime' === $sub['type'] ) {
						$vals[ $sub['name'] ] = minn_admin_acf_datetime_out( $v );
					} elseif ( 'time' === $sub['type'] ) {
						$vals[ $sub['name'] ] = minn_admin_acf_time_out( $v );
					} elseif ( is_array( $v ) ) {
						$vals[ $sub['name'] ] = '';
					} else {
						$vals[ $sub['name'] ] = null === $v ? '' : $v;
					}
				}
				$rows[] = array( '__idx' => $i, 'values' => (object) $vals );
			}
			$out[ $name ] = $rows;
		} elseif ( is_array( $val ) ) {
			// Shouldn't appear for simple non-multiple fields; don't leak structure.
			$out[ $name ] = '';
		} else {
			$out[ $name ] = $val;
		}
	}
	return $out;
}

/**
 * Write simple field values through ACF's own setter, by field key.
 *
 * Writing by key (not name) is what makes ACF store the `_name` key
 * reference correctly on first write.
 *
 * @param int   $post_id Post ID.
 * @param array $values  Field name => value.
 */
function minn_admin_acf_write_values( $post_id, $values ) {
	if ( ! is_array( $values ) ) {
		return;
	}
	$allowed = minn_admin_acf_simple_fields_for_post( $post_id );
	foreach ( $values as $name => $value ) {
		if ( ! isset( $allowed[ $name ] ) ) {
			continue;
		}
		$field = $allowed[ $name ];
		if ( 'true_false' === $field['type'] ) {
			$value = ( ! empty( $value ) && 'false' !== $value && '0' !== (string) $value ) ? 1 : 0;
		} elseif ( 'image' === $field['type'] || 'file' === $field['type'] ) {
			// { id, … } from the control, a bare id, or empty = clear.
			$value = minn_admin_acf_image_in( $value );
		} elseif ( 'gallery' === $field['type'] ) {
			// [{ id, url }] entries or bare ids; an empty list clears.
			$value = minn_admin_acf_gallery_in( $value );
		} elseif ( 'suggest' === $field['type'] ) {
			$value = minn_admin_acf_suggest_in( $field['key'], $value );
			if ( null === $value ) {
				continue; // invalid pick never clobbers the stored value
			}
		} elseif ( 'relation' === $field['type'] ) {
			$value = minn_admin_acf_relation_in( $field['key'], $value );
		} elseif ( 'multicheck' === $field['type'] ) {
			$value = minn_admin_acf_choices_in( $value, $field );
		} elseif ( in_array( $field['type'], array( 'date', 'datetime', 'time' ), true ) ) {
			$in = array( 'date' => 'minn_admin_acf_date_in', 'datetime' => 'minn_admin_acf_datetime_in', 'time' => 'minn_admin_acf_time_in' );
			$value = call_user_func( $in[ $field['type'] ], $value );
			if ( null === $value ) {
				continue; // invalid input never clobbers a stored date
			}
		} elseif ( 'wysiwyg' === $field['type'] ) {
			// The same trust boundary WordPress applies to post content: users
			// without unfiltered_html get their markup run through kses.
			$value = is_scalar( $value ) ? (string) $value : '';
			if ( ! current_user_can( 'unfiltered_html' ) ) {
				$value = wp_kses_post( $value );
			}
		} elseif ( 'rows' === $field['type'] ) {
			// Repeater merge: each incoming row overlays ONLY the mapped subs
			// onto the original stored row it references (__idx), so complex
			// sub values (images, nested repeaters) survive edits, reorders
			// and deletions untouched. Rows without __idx are new; omission
			// deletes; the incoming order is the new order.
			if ( ! is_array( $value ) ) {
				continue;
			}
			$orig = get_field( $field['key'], $post_id, false );
			$orig = is_array( $orig ) ? array_values( $orig ) : array();
			$new  = array();
			foreach ( $value as $row ) {
				$row  = (array) $row;
				$vals = isset( $row['values'] ) ? (array) $row['values'] : array();
				$base = isset( $row['__idx'] ) && is_numeric( $row['__idx'] ) && isset( $orig[ (int) $row['__idx'] ] ) && is_array( $orig[ (int) $row['__idx'] ] )
					? $orig[ (int) $row['__idx'] ]
					: array();
				foreach ( $field['subs'] as $sub ) {
					if ( ! array_key_exists( $sub['name'], $vals ) ) {
						continue;
					}
					$v = $vals[ $sub['name'] ];
					if ( 'true_false' === $sub['type'] ) {
						$v = ( ! empty( $v ) && 'false' !== $v && '0' !== (string) $v ) ? 1 : 0;
					} elseif ( 'image' === $sub['type'] || 'file' === $sub['type'] ) {
						$v = minn_admin_acf_image_in( $v );
					} elseif ( 'gallery' === $sub['type'] ) {
						$v = minn_admin_acf_gallery_in( $v );
					} elseif ( 'multicheck' === $sub['type'] ) {
						$v = minn_admin_acf_choices_in( $v, $sub );
					} elseif ( in_array( $sub['type'], array( 'date', 'datetime', 'time' ), true ) ) {
						$in = array( 'date' => 'minn_admin_acf_date_in', 'datetime' => 'minn_admin_acf_datetime_in', 'time' => 'minn_admin_acf_time_in' );
						$v = call_user_func( $in[ $sub['type'] ], $v );
						if ( null === $v ) {
							continue; // invalid input keeps the stored value
						}
					} elseif ( null === $v || false === $v ) {
						$v = '';
					} elseif ( ! is_scalar( $v ) ) {
						continue;
					}
					$base[ $sub['key'] ] = $v;
				}
				$new[] = $base;
			}
			$value = $new;
		} elseif ( null === $value || false === $value ) {
			// The panel clears fields with empty values; ACF's own form save
			// stores '' rather than deleting the row — match it.
			$value = '';
		} elseif ( ! is_scalar( $value ) ) {
			continue;
		}
		update_field( $field['key'], $value, $post_id );
	}
}

/**
 * Map ACF block fields onto a `dataForm` descriptor for the block inspector.
 *
 * ACF blocks (Pro) store their content inside the comment's `data` attribute
 * as { field_name: value, _field_name: field_key }. The generic inspector
 * rightly skips object attributes, which left these blocks uneditable in Minn
 * (and worse, exposed the raw name/mode wrapper attrs). This describes each
 * registered acf/* block's simple fields so the inspector renders a real form.
 *
 * @return array Block name => descriptor additions.
 */
function minn_admin_acf_block_forms() {
	if ( ! function_exists( 'acf_get_block_types' ) || ! function_exists( 'acf_get_block_fields' ) ) {
		return array(); // blocks are ACF Pro
	}
	$out = array();
	foreach ( acf_get_block_types() as $name => $block_type ) {
		$fields = array();
		$alias  = array();
		$locked = 0;
		foreach ( (array) acf_get_block_fields( array( 'name' => $name ) ) as $f ) {
			if ( in_array( $f['type'] ?? '', MINN_ADMIN_ACF_CHROME_TYPES, true ) ) {
				continue;
			}
			$simple = minn_admin_acf_map_field( $f );
			if ( ! $simple ) {
				$locked++;
				continue;
			}
			// Date, file and relational types stay locked in block forms:
			// their values live in the block's data attribute in ACF's raw
			// storage formats, and the inspector has no adapter layer (or
			// per-type picker arming) to translate.
			if ( in_array( $simple['type'], array( 'date', 'datetime', 'time', 'file', 'suggest', 'relation' ), true ) ) {
				$locked++;
				continue;
			}
			$entry = array(
				'name'  => $simple['name'],
				'label' => $simple['label'],
			);
			switch ( $simple['type'] ) {
				case 'textarea':
					$entry['control'] = 'textarea';
					break;
				case 'number':
				case 'range':
					$entry['control'] = 'number';
					break;
				case 'true_false':
					$entry['control'] = 'checkbox';
					break;
				case 'select':
				case 'radio':
					$entry['control'] = 'select';
					break;
				case 'multicheck':
					$entry['control'] = 'multicheck';
					break;
				case 'image':
					$entry['control'] = 'image';
					break;
				case 'gallery':
					$entry['control'] = 'gallery';
					break;
				case 'wysiwyg':
					$entry['control'] = 'richtext';
					break;
				case 'color_picker':
					$entry['control'] = 'color';
					break;
				default:
					$entry['control'] = 'text';
			}
			if ( ! empty( $simple['choices'] ) && is_array( $simple['choices'] ) ) {
				$entry['options'] = array();
				foreach ( $simple['choices'] as $value => $label ) {
					$entry['options'][] = array( (string) $value, (string) $label );
				}
			}
			$fields[]                  = $entry;
			$alias[ $simple['name'] ] = $simple['key'];
		}
		$out[ $name ] = array(
			// The wrapper attrs are plumbing: `name` edits corrupt the block,
			// `data` is the map the form below owns, `mode` is a Gutenberg UI
			// concern. Hidden even when no fields mapped.
			'attributes' => array(
				'name' => array( 'hide' => true ),
				'data' => array( 'hide' => true ),
				'mode' => array( 'hide' => true ),
				'id'   => array( 'hide' => true ),
			),
		);
		if ( $fields || $locked ) {
			$out[ $name ]['dataForm'] = array(
				'attr'   => 'data',
				'fields' => $fields,
				'alias'  => $alias,
				'locked' => $locked,
			);
		}
	}
	return $out;
}

add_filter( 'minn_admin_block_forms', function ( $forms ) {
	if ( ! minn_admin_acf_active() ) {
		return $forms;
	}
	foreach ( minn_admin_acf_block_forms() as $name => $form ) {
		$forms[ $name ] = isset( $forms[ $name ] ) && is_array( $forms[ $name ] )
			? array_merge( $forms[ $name ], $form )
			: $form;
	}
	return $forms;
} );

add_filter( 'minn_admin_editor_panels', function ( $panels ) {
	if ( ! minn_admin_acf_active() ) {
		return $panels;
	}
	$panels['acf'] = array(
		'label'       => __( 'Custom fields', 'minn-admin' ),
		'sub'         => 'ACF',
		'cap'         => 'edit_posts',
		'fieldsRoute' => 'minn-admin/v1/acf/fields?post_id={id}&post_type={type}',
		'valuesKey'   => 'minn_acf',
		'writeKey'    => 'minn_acf',
	);
	return $panels;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_acf_active() ) {
		return;
	}

	register_rest_route( 'minn-admin/v1', '/acf/fields', array(
		'methods'             => 'GET',
		'permission_callback' => function ( WP_REST_Request $request ) {
			if ( ! current_user_can( 'edit_posts' ) ) {
				return false;
			}
			$post_id = (int) $request['post_id'];
			return ! $post_id || current_user_can( 'edit_post', $post_id );
		},
		'args'                => array(
			'post_id'   => array( 'type' => 'integer', 'default' => 0 ),
			'post_type' => array( 'type' => 'string', 'default' => 'posts' ),
		),
		'callback'            => function ( WP_REST_Request $request ) {
			$post_id = (int) $request['post_id'];

			// The app passes the REST base; resolve it to a post type slug.
			$rest_base = sanitize_key( $request['post_type'] );
			$post_type = 'post';
			foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $obj ) {
				$base = $obj->rest_base ? $obj->rest_base : $obj->name;
				if ( $base === $rest_base || $obj->name === $rest_base ) {
					$post_type = $obj->name;
					break;
				}
			}

			// Authorize against the resolved target, the way the Pods and Meta
			// Box panels do. The permission callback can only see edit_posts and
			// the post id; with no post id its second clause short-circuits true,
			// which let a Contributor name any post type and read its private
			// field schema. A field group describes the site's data model, and
			// internal naming and credential field ids live in it.
			if ( $post_id ) {
				$post = get_post( $post_id );
				if ( ! $post ) {
					return new WP_Error( 'not_found', __( 'Post not found.', 'minn-admin' ), array( 'status' => 404 ) );
				}
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return new WP_Error( 'rest_forbidden', __( 'You cannot edit this post.', 'minn-admin' ), array( 'status' => 403 ) );
				}
				$post_type = $post->post_type;
			} else {
				$type_obj = get_post_type_object( $post_type );
				if ( ! $type_obj || ! current_user_can( $type_obj->cap->edit_posts ) ) {
					return new WP_Error( 'rest_forbidden', __( 'You cannot edit that post type.', 'minn-admin' ), array( 'status' => 403 ) );
				}
			}

			return rest_ensure_response( minn_admin_acf_fields_payload( $post_id, $post_type ) );
		},
	) );

	// Relational-field search: rows for the suggest / relation pickers. The
	// field key names the constraints — post types, taxonomy terms filter,
	// user roles — so the picker can only ever offer what the field itself
	// allows (mirroring ACF's own ajax queries). Listing follows the TEC
	// suggest precedent: published (and scheduled) posts are shared choices;
	// drafts/pending/private are ownership-scoped for users who cannot edit
	// other people's posts.
	register_rest_route( 'minn-admin/v1', '/acf/relation', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'args'                => array(
			'field' => array( 'type' => 'string', 'required' => true ),
			'q'     => array( 'type' => 'string', 'default' => '' ),
		),
		'callback'            => function ( WP_REST_Request $request ) {
			$acf  = acf_get_field( (string) $request['field'] );
			$kind = $acf ? minn_admin_acf_relation_kind( $acf ) : '';
			if ( ! $kind ) {
				return new WP_Error( 'minn_no_field', __( 'Unknown relational field.', 'minn-admin' ), array( 'status' => 404 ) );
			}
			$q    = trim( (string) $request['q'] );
			$rows = array();

			if ( 'post' === $kind ) {
				$base = array(
					'post_type'        => ! empty( $acf['post_type'] ) ? (array) $acf['post_type'] : 'any',
					'posts_per_page'   => 20,
					'orderby'          => 'title',
					'order'            => 'ASC',
					'suppress_filters' => false,
				);
				if ( '' !== $q ) {
					$base['s'] = $q;
				}
				// ACF's taxonomy filter entries are "taxonomy:term-slug".
				if ( ! empty( $acf['taxonomy'] ) && is_array( $acf['taxonomy'] ) ) {
					$tax_query = array( 'relation' => 'OR' );
					foreach ( $acf['taxonomy'] as $pair ) {
						$bits = explode( ':', (string) $pair, 2 );
						if ( 2 === count( $bits ) ) {
							$tax_query[] = array( 'taxonomy' => $bits[0], 'field' => 'slug', 'terms' => $bits[1] );
						}
					}
					if ( count( $tax_query ) > 1 ) {
						$base['tax_query'] = $tax_query;
					}
				}
				$posts = get_posts( array_merge( $base, array( 'post_status' => array( 'publish', 'future' ) ) ) );
				$rest  = array_merge( $base, array( 'post_status' => array( 'draft', 'pending', 'private' ) ) );
				if ( ! current_user_can( 'edit_others_posts' ) ) {
					$rest['author'] = get_current_user_id();
				}
				$posts = array_merge( $posts, get_posts( $rest ) );
				usort( $posts, function ( $a, $b ) {
					return strcasecmp( (string) $a->post_title, (string) $b->post_title );
				} );
				$multi_type = ! is_array( $base['post_type'] ) || count( $base['post_type'] ) > 1;
				foreach ( array_slice( $posts, 0, 20 ) as $p ) {
					$entry = minn_admin_acf_relation_entry( 'post', $p->ID );
					if ( ! $entry ) {
						continue;
					}
					if ( $multi_type ) {
						$type_obj        = get_post_type_object( $p->post_type );
						$entry['label'] .= ' · ' . ( $type_obj ? $type_obj->labels->singular_name : $p->post_type );
					}
					$rows[] = $entry;
				}
			} elseif ( 'term' === $kind ) {
				$args = array(
					'taxonomy'   => ! empty( $acf['taxonomy'] ) ? $acf['taxonomy'] : 'category',
					'hide_empty' => false,
					'number'     => 20,
				);
				if ( '' !== $q ) {
					$args['search'] = $q;
				}
				foreach ( (array) get_terms( $args ) as $t ) {
					if ( $t instanceof WP_Term ) {
						$rows[] = array( 'value' => (string) $t->term_id, 'label' => $t->name );
					}
				}
			} else {
				// The site builder put a user picker on this form on purpose
				// (same trust call ACF's own ajax makes); the role filter is
				// the field's, never ours.
				$args = array(
					'number'  => 20,
					'orderby' => 'display_name',
				);
				if ( '' !== $q ) {
					$args['search'] = '*' . $q . '*';
				}
				if ( ! empty( $acf['role'] ) ) {
					$args['role__in'] = (array) $acf['role'];
				}
				foreach ( get_users( $args ) as $u ) {
					$rows[] = array( 'value' => (string) $u->ID, 'label' => $u->display_name ? $u->display_name : $u->user_login );
				}
			}
			return rest_ensure_response( $rows );
		},
	) );

	// Values ride the post REST object as `minn_acf` (the Meta Box pattern) —
	// present whether or not the group opted into ACF's own REST exposure.
	foreach ( get_post_types( array( 'show_in_rest' => true ), 'names' ) as $type ) {
		register_rest_field(
			$type,
			'minn_acf',
			array(
				'get_callback'    => function ( $obj ) {
					$id = isset( $obj['id'] ) ? (int) $obj['id'] : 0;
					if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
						return new stdClass(); // empty object in JSON
					}
					return (object) minn_admin_acf_read_values( $id );
				},
				'update_callback' => function ( $value, $post ) {
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
					minn_admin_acf_write_values( $post->ID, $value );
				},
				'schema'          => array(
					'description' => __( 'ACF simple field values for Minn Admin.', 'minn-admin' ),
					'type'        => 'object',
					'context'     => array( 'edit' ),
				),
			)
		);
	}
} );

/* ===== ACF options pages (Pro) as settings-only surfaces ===== */

/**
 * Options pages are ACF Pro; the free plugin has no registry to read.
 *
 * @return bool
 */
function minn_admin_acf_options_active() {
	return minn_admin_acf_active() && function_exists( 'acf_get_options_pages' );
}

/**
 * Options pages the current user may manage, keyed by menu slug. Each page
 * declares its own capability (ACF defaults to edit_posts); Minn honors it
 * rather than inventing a gate.
 *
 * @return array
 */
function minn_admin_acf_options_pages_allowed() {
	$out = array();
	foreach ( (array) acf_get_options_pages() as $page ) {
		if ( empty( $page['menu_slug'] ) ) {
			continue;
		}
		// Redirect parents exist only to hold children in the admin menu.
		if ( ! empty( $page['redirect'] ) && ! empty( $page['child_slugs'] ) ) {
			continue;
		}
		$cap = ! empty( $page['capability'] ) ? $page['capability'] : 'edit_posts';
		if ( ! current_user_can( $cap ) ) {
			continue;
		}
		$out[ $page['menu_slug'] ] = $page;
	}
	return $out;
}

/**
 * Tab layout for one options page. ACF `tab` fields are the natural section
 * boundaries: each starts a Minn tab that collects the fields after it (ACF's
 * own semantics); fields before any tab land in a tab named for their group.
 * Ids are positional (tab-0…) — both GET and POST re-derive the same walk, so
 * they stay stable within a schema version.
 *
 * @param array $page Options page array.
 * @return array[] { id, label, fields (simple maps incl. key), locked }
 */
function minn_admin_acf_options_tabs( $page ) {
	$tabs    = array();
	$current = -1;
	$open    = function ( $label ) use ( &$tabs, &$current ) {
		$tabs[]  = array( 'label' => $label, 'fields' => array(), 'locked' => 0 );
		$current = count( $tabs ) - 1;
	};
	foreach ( acf_get_field_groups( array( 'options_page' => $page['menu_slug'] ) ) as $group ) {
		$current = -1;
		foreach ( (array) acf_get_fields( $group ) as $f ) {
			$type = $f['type'] ?? '';
			if ( 'tab' === $type ) {
				$open( $f['label'] );
				continue;
			}
			if ( in_array( $type, MINN_ADMIN_ACF_CHROME_TYPES, true ) ) {
				continue;
			}
			if ( $current < 0 ) {
				$open( $group['title'] );
			}
			$simple = minn_admin_acf_map_field( $f );
			if ( ! $simple ) {
				$tabs[ $current ]['locked']++;
				continue;
			}
			$tabs[ $current ]['fields'][] = $simple;
		}
	}
	$out = array();
	foreach ( $tabs as $tab ) {
		if ( ! $tab['fields'] && ! $tab['locked'] ) {
			continue;
		}
		$tab['id'] = 'tab-' . count( $out );
		$out[]     = $tab;
	}
	return $out;
}

/**
 * The wp-admin URL for an options page (its locked-fields link-out).
 *
 * @param array $page Options page array.
 * @return string
 */
function minn_admin_acf_options_admin_url( $page ) {
	$parent = ! empty( $page['parent_slug'] ) ? (string) $page['parent_slug'] : '';
	$base   = ( $parent && false !== strpos( $parent, '.php' ) ) ? $parent : 'admin.php';
	return admin_url( $base . '?page=' . rawurlencode( $page['menu_slug'] ) );
}

/**
 * GET/POST shape for one options-page tab: { groups, values, adminUrl }.
 * Values key by ACF field KEY (unique and unambiguous across groups).
 *
 * @param array  $page   Options page array.
 * @param string $tab_id Positional tab id.
 * @return array|WP_Error
 */
function minn_admin_acf_options_tab_shape( $page, $tab_id ) {
	$tabs = minn_admin_acf_options_tabs( $page );
	$tab  = null;
	foreach ( $tabs as $t ) {
		if ( $t['id'] === $tab_id ) {
			$tab = $t;
			break;
		}
	}
	if ( ! $tab ) {
		return new WP_Error( 'minn_no_tab', __( 'Unknown settings tab.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	$post_id = ! empty( $page['post_id'] ) ? $page['post_id'] : 'options';
	$fields  = array();
	$values  = array();
	foreach ( $tab['fields'] as $f ) {
		$sf = array(
			'key'   => $f['key'],
			'label' => $f['label'],
			'type'  => 'true_false' === $f['type'] ? 'toggle'
				: ( in_array( $f['type'], array( 'select', 'radio' ), true ) ? 'select'
				: ( in_array( $f['type'], array( 'number', 'range' ), true ) ? 'number'
				: ( in_array( $f['type'], array( 'textarea', 'wysiwyg', 'gallery', 'image', 'file', 'multicheck', 'date', 'datetime', 'time', 'suggest', 'relation' ), true ) ? $f['type'] : 'text' ) ) ),
		);
		if ( ! empty( $f['route'] ) ) {
			$sf['route'] = $f['route'];
		}
		if ( in_array( $sf['type'], array( 'select', 'multicheck' ), true ) ) {
			$sf['options'] = array();
			foreach ( (array) ( $f['choices'] ?? array() ) as $value => $label ) {
				$sf['options'][] = array( (string) $value, (string) $label );
			}
		}
		$fields[] = $sf;

		$v = get_field( $f['key'], $post_id, false );
		if ( 'toggle' === $sf['type'] ) {
			$values[ $f['key'] ] = ! empty( $v );
		} elseif ( 'gallery' === $sf['type'] ) {
			$values[ $f['key'] ] = minn_admin_acf_gallery_out( $v );
		} elseif ( 'image' === $sf['type'] ) {
			$values[ $f['key'] ] = minn_admin_acf_image_out( $v );
		} elseif ( 'file' === $sf['type'] ) {
			$values[ $f['key'] ] = minn_admin_acf_file_out( $v );
		} elseif ( 'suggest' === $sf['type'] ) {
			$values[ $f['key'] ] = minn_admin_acf_suggest_out( $f['key'], $v );
		} elseif ( 'relation' === $sf['type'] ) {
			$values[ $f['key'] ] = minn_admin_acf_relation_list_out( $f['key'], $v );
		} elseif ( 'multicheck' === $sf['type'] ) {
			$values[ $f['key'] ] = minn_admin_acf_choices_out( $v );
		} elseif ( 'date' === $sf['type'] ) {
			$values[ $f['key'] ] = minn_admin_acf_date_out( $v );
		} elseif ( 'datetime' === $sf['type'] ) {
			$values[ $f['key'] ] = minn_admin_acf_datetime_out( $v );
		} elseif ( 'time' === $sf['type'] ) {
			$values[ $f['key'] ] = minn_admin_acf_time_out( $v );
		} elseif ( is_array( $v ) ) {
			$values[ $f['key'] ] = '';
		} else {
			$values[ $f['key'] ] = null === $v ? '' : $v;
		}
	}
	return array(
		'groups'   => array(
			array(
				'title'  => '',
				'fields' => $fields,
				'locked' => $tab['locked'],
			),
		),
		'values'   => $values,
		'adminUrl' => minn_admin_acf_options_admin_url( $page ),
	);
}

/**
 * Save edited values through ACF's own setter. Keys are whitelisted against
 * the page's mapped fields (never an arbitrary update_field), coerced to
 * ACF's stored shapes (1/0 for true_false, strings otherwise).
 *
 * @param array $page   Options page array.
 * @param array $values Field key => value.
 */
function minn_admin_acf_options_save( $page, $values ) {
	$byKey = array();
	foreach ( minn_admin_acf_options_tabs( $page ) as $tab ) {
		foreach ( $tab['fields'] as $f ) {
			$byKey[ $f['key'] ] = $f;
		}
	}
	$post_id = ! empty( $page['post_id'] ) ? $page['post_id'] : 'options';
	foreach ( (array) $values as $key => $v ) {
		if ( ! isset( $byKey[ $key ] ) ) {
			continue;
		}
		if ( 'true_false' === $byKey[ $key ]['type'] ) {
			$v = ( ! empty( $v ) && 'false' !== $v && '0' !== (string) $v ) ? 1 : 0;
		} elseif ( 'gallery' === $byKey[ $key ]['type'] ) {
			$v = minn_admin_acf_gallery_in( $v );
		} elseif ( 'image' === $byKey[ $key ]['type'] || 'file' === $byKey[ $key ]['type'] ) {
			$v = minn_admin_acf_image_in( $v );
		} elseif ( 'suggest' === $byKey[ $key ]['type'] ) {
			$v = minn_admin_acf_suggest_in( $key, $v );
			if ( null === $v ) {
				continue; // invalid pick never clobbers the stored value
			}
		} elseif ( 'relation' === $byKey[ $key ]['type'] ) {
			$v = minn_admin_acf_relation_in( $key, $v );
		} elseif ( 'multicheck' === $byKey[ $key ]['type'] ) {
			$v = minn_admin_acf_choices_in( $v, $byKey[ $key ] );
		} elseif ( in_array( $byKey[ $key ]['type'], array( 'date', 'datetime', 'time' ), true ) ) {
			$in = array( 'date' => 'minn_admin_acf_date_in', 'datetime' => 'minn_admin_acf_datetime_in', 'time' => 'minn_admin_acf_time_in' );
			$v = call_user_func( $in[ $byKey[ $key ]['type'] ], $v );
			if ( null === $v ) {
				continue; // invalid input never clobbers a stored date
			}
		} elseif ( 'wysiwyg' === $byKey[ $key ]['type'] ) {
			// The post-content trust boundary, same as the panel write path.
			$v = is_scalar( $v ) ? (string) $v : '';
			if ( ! current_user_can( 'unfiltered_html' ) ) {
				$v = wp_kses_post( $v );
			}
		} elseif ( null === $v || false === $v ) {
			$v = '';
		} elseif ( ! is_scalar( $v ) ) {
			continue;
		}
		update_field( $key, $v, $post_id );
	}
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_acf_options_active() ) {
		return $surfaces;
	}
	foreach ( minn_admin_acf_options_pages_allowed() as $slug => $page ) {
		$tabs = minn_admin_acf_options_tabs( $page );
		if ( ! $tabs ) {
			continue;
		}
		$tab_list = array();
		foreach ( $tabs as $t ) {
			$tab_list[] = array( 'id' => $t['id'], 'label' => $t['label'] );
		}
		$surfaces[ 'acf-options-' . sanitize_key( $slug ) ] = array(
			'label'    => $page['page_title'] ? $page['page_title'] : $page['menu_slug'],
			'sub'      => 'ACF',
			'icon'     => 'gear',
			'cap'      => ! empty( $page['capability'] ) ? $page['capability'] : 'edit_posts',
			'settings' => array(
				'label' => __( 'Settings', 'minn-admin' ),
				'tabs'  => $tab_list,
				'route' => 'minn-admin/v1/acf/options/' . rawurlencode( $slug ) . '/{tab}',
			),
		);
	}
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_acf_options_active() ) {
		return;
	}
	$resolve = function ( $req ) {
		$pages = minn_admin_acf_options_pages_allowed();
		$slug  = rawurldecode( (string) $req['page'] );
		return isset( $pages[ $slug ] ) ? $pages[ $slug ] : null;
	};
	register_rest_route( 'minn-admin/v1', '/acf/options/(?P<page>[A-Za-z0-9_%.\-]+)/(?P<tab>tab-\d+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => function ( $req ) use ( $resolve ) {
				return (bool) $resolve( $req ); // allowed-pages already filters by the page's own capability
			},
			'callback'            => function ( $req ) use ( $resolve ) {
				return rest_ensure_response( minn_admin_acf_options_tab_shape( $resolve( $req ), (string) $req['tab'] ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => function ( $req ) use ( $resolve ) {
				return (bool) $resolve( $req );
			},
			'callback'            => function ( $req ) use ( $resolve ) {
				$page   = $resolve( $req );
				$body   = $req->get_json_params();
				$values = isset( $body['values'] ) && is_array( $body['values'] ) ? $body['values'] : array();
				minn_admin_acf_options_save( $page, $values );
				return rest_ensure_response( minn_admin_acf_options_tab_shape( $page, (string) $req['tab'] ) );
			},
		),
	) );
} );
