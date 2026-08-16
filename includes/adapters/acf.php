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
// The link type stores { title, url, target } and rides the engine's
// three-part link control (URL, text, new-tab).
const MINN_ADMIN_ACF_SIMPLE_TYPES = array( 'text', 'textarea', 'number', 'range', 'email', 'url', 'select', 'radio', 'button_group', 'checkbox', 'true_false', 'color_picker', 'image', 'gallery', 'file', 'link', 'wysiwyg', 'date_picker', 'date_time_picker', 'time_picker', 'post_object', 'relationship', 'page_link', 'taxonomy', 'user' );

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
 * Stored ACF link value → { title, url, target } for the link control
 * ('' when unset). target normalizes to '_blank' or ''.
 *
 * @param mixed $val Raw stored value.
 * @return array|string
 */
function minn_admin_acf_link_out( $val ) {
	if ( ! is_array( $val ) ) {
		return '';
	}
	return array(
		'title'  => isset( $val['title'] ) && is_scalar( $val['title'] ) ? (string) $val['title'] : '',
		'url'    => isset( $val['url'] ) && is_scalar( $val['url'] ) ? (string) $val['url'] : '',
		'target' => empty( $val['target'] ) ? '' : '_blank',
	);
}

/**
 * Incoming link value → ACF's stored { title, url, target } array.
 * '' clears; null = invalid (script-running URLs refuse the whole write).
 *
 * @param mixed $value Incoming value.
 * @return array|string|null
 */
function minn_admin_acf_link_in( $value ) {
	if ( null === $value || false === $value || '' === $value ) {
		return '';
	}
	if ( ! is_array( $value ) && ! is_object( $value ) ) {
		return null;
	}
	$value = (array) $value;
	$url   = isset( $value['url'] ) && is_scalar( $value['url'] ) ? trim( (string) $value['url'] ) : '';
	$title = isset( $value['title'] ) && is_scalar( $value['title'] ) ? (string) $value['title'] : '';
	if ( preg_match( '/^(javascript|data|vbscript):/i', $url ) ) {
		return null;
	}
	if ( '' === $url && '' === $title ) {
		return ''; // both emptied = cleared
	}
	$target = ! empty( $value['target'] ) && 'false' !== $value['target'] ? '_blank' : '';
	return array(
		'title'  => $title,
		'url'    => $url,
		'target' => $target,
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
/**
 * Choice labels as plain text. ACF renders its own screens as HTML, so a
 * choice label can carry markup (a dashicon span in front of "Center"); Minn's
 * selects escape what they are given, which would print the tag verbatim.
 * A label that was ONLY markup keeps its stored value as the fallback label.
 *
 * @param array $choices Stored choices.
 * @return array
 */
function minn_admin_acf_plain_choices( $choices ) {
	$out = array();
	foreach ( (array) $choices as $value => $label ) {
		$plain        = trim( wp_strip_all_tags( (string) $label ) );
		$out[ $value ] = '' !== $plain ? $plain : (string) $value;
	}
	return $out;
}

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
		'choices'   => ! empty( $f['choices'] ) ? minn_admin_acf_plain_choices( $f['choices'] ) : null,
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
 * Flatten one repeating unit's sub-fields (a repeater's `sub_fields`, or one
 * flexible-content layout's) onto the row dialect's sub vocabulary.
 *
 * Subs share the field map — everything simple except wysiwyg (the row cards
 * have no rich-text seat) and the relational pickers (no arming in the rows
 * dialect yet). A GROUP sub is a namespace, not a control: its subs flatten
 * into the row as prefixed columns ("Hover · Bg"), stored nested exactly where
 * ACF keeps them (gpath = the group-key chain the read/write descend).
 * Recursion is depth-capped, matching the options flatten.
 *
 * @param array $sub_fields ACF sub-field list.
 * @return array { subs: array, locked: int }
 */
/**
 * Is a conditional rule's field key one of these keys?
 *
 * ACF composes clone-derived keys (field_CLONE_field_ORIG) at load time but
 * leaves conditional logic pointing at the ORIGINAL key, resolving the two by
 * suffix at render time. Comparing the strings outright would read every rule
 * inside a cloned group as pointing somewhere else.
 *
 * @param string $key  Rule field key.
 * @param array  $keys Key => true map to test against.
 * @return bool
 */
function minn_admin_acf_key_in( $key, $keys ) {
	if ( '' === $key ) {
		return false;
	}
	if ( isset( $keys[ $key ] ) ) {
		return true;
	}
	foreach ( $keys as $k => $unused ) {
		$len = strlen( $key ) + 1;
		if ( strlen( $k ) > $len && substr( $k, -$len ) === '_' . $key ) {
			return true;
		}
		$len = strlen( $k ) + 1;
		if ( strlen( $key ) > $len && substr( $key, -$len ) === '_' . $k ) {
			return true;
		}
	}
	return false;
}

function minn_admin_acf_flatten_subs( $sub_fields ) {
	$subs   = array();
	$locked = 0;
	// Every key inside this unit, so a sub's conditional logic can be told
	// apart: rules over SIBLINGS vary per row (rendered unconditionally for
	// now, values always preserved), rules over anything else can never be
	// satisfied in here — ACF's own screen leaves those hidden, so counting
	// them as "living in wp-admin" would point at a field nobody can see.
	$own  = array();
	$keys = function ( $list ) use ( &$keys, &$own ) {
		foreach ( (array) $list as $f ) {
			if ( ! empty( $f['key'] ) ) {
				$own[ $f['key'] ] = true;
			}
			if ( 'group' === ( $f['type'] ?? '' ) && ! empty( $f['sub_fields'] ) ) {
				$keys( $f['sub_fields'] );
			}
		}
	};
	$keys( $sub_fields );
	$push = function ( $sub, $label_prefix, $name_prefix, $gpath ) use ( &$push, &$subs, &$locked, &$own ) {
		if ( in_array( $sub['type'] ?? '', MINN_ADMIN_ACF_CHROME_TYPES, true ) ) {
			return;
		}
		$cond = ! empty( $sub['conditional_logic'] ) && is_array( $sub['conditional_logic'] ) ? $sub['conditional_logic'] : null;
		if ( $cond ) {
			$foreign = true;
			foreach ( $cond as $rules ) {
				foreach ( (array) $rules as $rule ) {
					if ( minn_admin_acf_key_in( $rule['field'] ?? '', $own ) ) {
						$foreign = false;
					}
				}
			}
			// A controller that is not in the row answers as no value, exactly
			// as it does on ACF's screen, where the field simply never shows.
			if ( $foreign && ! minn_admin_acf_eval_cond( $cond, '__return_null' ) ) {
				return;
			}
		}
		if ( 'group' === ( $sub['type'] ?? '' ) && ! empty( $sub['sub_fields'] ) && count( $gpath ) < 3 ) {
			// An unlabelled group namespaces nothing a reader can see, so it
			// contributes no prefix (a bare "· Height" reads as a bug).
			$glabel = trim( wp_strip_all_tags( (string) ( $sub['label'] ?? '' ) ) );
			foreach ( (array) $sub['sub_fields'] as $s2 ) {
				$push(
					$s2,
					$label_prefix . ( '' !== $glabel ? $glabel . ' · ' : '' ),
					$name_prefix . $sub['name'] . '_',
					array_merge( $gpath, array( $sub['key'] ) )
				);
			}
			return;
		}
		$m = minn_admin_acf_map_field( $sub );
		if ( ! $m || in_array( $m['type'], array( 'wysiwyg', 'suggest', 'relation' ), true ) ) {
			$locked++;
			return;
		}
		$subs[] = array(
			'name'      => $name_prefix . $m['name'],
			'label'     => $label_prefix . $m['label'],
			'type'      => $m['type'],
			'choices'   => $m['choices'],
			'anyChoice' => $m['anyChoice'],
			'key'       => $m['key'],
			'gpath'     => $gpath ? $gpath : null,
		);
	};
	foreach ( (array) $sub_fields as $sub ) {
		$push( $sub, '', '', array() );
	}
	return array(
		'subs'   => $subs,
		'locked' => $locked,
	);
}

/**
 * Strip a flattened sub list down to the client shape (the form keys
 * sub-inputs by name; key and gpath are the write path's business).
 *
 * @param array $subs Flattened subs.
 * @return array
 */
function minn_admin_acf_client_subs( $subs ) {
	return array_map( function ( $s ) {
		unset( $s['key'], $s['gpath'] );
		return $s;
	}, $subs );
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
	$flat = minn_admin_acf_flatten_subs( $f['sub_fields'] ?? array() );
	if ( ! $flat['subs'] ) {
		return null;
	}
	return array(
		'name'      => $f['name'],
		'label'     => $f['label'],
		'type'      => 'rows',
		'subfields' => minn_admin_acf_client_subs( $flat['subs'] ),
		'subLocked' => $flat['locked'],
		'key'       => $f['key'],
		'subs'      => $flat['subs'],
	);
}

/**
 * Map an ACF flexible-content field (Pro) onto the `flex` control — a rows
 * control whose every row picks its schema by layout.
 *
 * Every declared layout is listed, including one with no mappable sub at all:
 * a stored section of that layout must still render (as an inert card naming
 * what lives in wp-admin) rather than silently disappear from a page's
 * content. Layout SCHEMAS stay ACF's business; Minn edits sections.
 *
 * `_acf` is the resolved ACF field array. Clone-derived fields carry composed
 * keys (field_CLONE_field_ORIG) that `acf_get_field()` cannot resolve, so the
 * key-based readers answer null for them — the raw read/write helpers fall
 * back to this array. It is internal: strip it before the payload ships.
 *
 * @param array $f ACF flexible-content field array.
 * @return array|null { name, label, type: 'flex', key, layouts, layoutSubs, _acf }
 */
function minn_admin_acf_map_flex( $f ) {
	if ( empty( $f['name'] ) || empty( $f['key'] ) || empty( $f['layouts'] ) ) {
		return null;
	}
	$layouts = array();
	$subs    = array();
	foreach ( (array) $f['layouts'] as $lay ) {
		$name = isset( $lay['name'] ) ? (string) $lay['name'] : '';
		if ( '' === $name || isset( $layouts[ $name ] ) ) {
			continue;
		}
		$flat             = minn_admin_acf_flatten_subs( $lay['sub_fields'] ?? array() );
		$label            = trim( wp_strip_all_tags( (string) ( $lay['label'] ?? '' ) ) );
		$layouts[ $name ] = array(
			'label'     => '' !== $label ? $label : $name,
			'subfields' => minn_admin_acf_client_subs( $flat['subs'] ),
			'subLocked' => $flat['locked'],
		);
		$subs[ $name ]    = $flat['subs'];
	}
	if ( ! $layouts ) {
		return null;
	}
	return array(
		'name'       => $f['name'],
		'label'      => $f['label'],
		'type'       => 'flex',
		'key'        => $f['key'],
		'layouts'    => $layouts,
		'layoutSubs' => $subs,
		'_acf'       => $f,
	);
}

/**
 * Raw stored value for a mapped field, through ACF's own reader.
 *
 * @param array      $field   Mapped field (may carry `_acf`).
 * @param int|string $post_id Post id or options id.
 * @return mixed
 */
function minn_admin_acf_raw_value( $field, $post_id ) {
	if ( ! empty( $field['_acf'] ) && ! acf_get_field( $field['key'] ) ) {
		return acf_get_value( acf_get_valid_post_id( $post_id ), $field['_acf'] );
	}
	return get_field( $field['key'], $post_id, false );
}

/**
 * Write a mapped field's stored value through ACF's own setter.
 *
 * @param array      $field   Mapped field (may carry `_acf`).
 * @param mixed      $value   Stored-shape value.
 * @param int|string $post_id Post id or options id.
 */
function minn_admin_acf_raw_update( $field, $value, $post_id ) {
	if ( ! empty( $field['_acf'] ) && ! acf_get_field( $field['key'] ) ) {
		acf_update_value( $value, acf_get_valid_post_id( $post_id ), $field['_acf'] );
		return;
	}
	update_field( $field['key'], $value, $post_id );
}

/**
 * Strip a mapped field's internals before it ships to the client.
 *
 * @param array $f Mapped field.
 * @return array
 */
function minn_admin_acf_public_field( $f ) {
	unset( $f['subs'], $f['layoutSubs'], $f['_acf'] );
	return $f;
}

/**
 * Evaluate ACF conditional logic (OR groups of AND rules) against values
 * from $get_value( field_key ). Mirrors acfCondShow in app.js — the two
 * must stay in sync.
 *
 * @param mixed    $logic     The field's conditional_logic setting.
 * @param callable $get_value Field key => current value.
 * @return bool Show the field.
 */
function minn_admin_acf_eval_cond( $logic, $get_value ) {
	if ( empty( $logic ) || ! is_array( $logic ) ) {
		return true;
	}
	foreach ( $logic as $group ) {
		if ( ! is_array( $group ) || ! $group ) {
			continue;
		}
		$ok = true;
		foreach ( $group as $rule ) {
			if ( ! is_array( $rule ) || ! minn_admin_acf_eval_rule( $rule, $get_value ) ) {
				$ok = false;
				break;
			}
		}
		if ( $ok ) {
			return true;
		}
	}
	return false;
}

/**
 * One conditional rule. Unknown operators answer true (never hide a field
 * over a rule Minn does not model).
 *
 * @param array    $rule      { field, operator, value }.
 * @param callable $get_value Field key => current value.
 * @return bool
 */
function minn_admin_acf_eval_rule( $rule, $get_value ) {
	$op  = isset( $rule['operator'] ) ? (string) $rule['operator'] : '==';
	$val = isset( $rule['value'] ) ? (string) $rule['value'] : '';
	$cur = call_user_func( $get_value, isset( $rule['field'] ) ? (string) $rule['field'] : '' );
	// An unchecked toggle (0/'0'), '', null and an empty list all read as
	// "no value" — ACF's own condition semantics for ==empty / !=empty.
	$has  = is_array( $cur ) ? count( $cur ) > 0 : ! ( null === $cur || false === $cur || '' === $cur || '0' === (string) $cur );
	$curS = ( true === $cur ) ? '1' : ( ( false === $cur || null === $cur ) ? '0' : ( is_scalar( $cur ) ? (string) $cur : '' ) );
	$list = is_array( $cur ) ? array_map( 'strval', array_filter( $cur, 'is_scalar' ) ) : null;
	switch ( $op ) {
		case '==empty':
			return ! $has;
		case '!=empty':
			return $has;
		case '==':
			return null !== $list ? in_array( $val, $list, true ) : $curS === $val;
		case '!=':
			return null !== $list ? ! in_array( $val, $list, true ) : $curS !== $val;
		case '==contains':
			return null !== $list ? in_array( $val, $list, true ) : ( '' !== $val && false !== strpos( $curS, $val ) );
		case '==pattern':
			return (bool) @preg_match( '/' . str_replace( '/', '\/', $val ) . '/', $curS );
		case '>':
			return is_numeric( $curS ) && is_numeric( $val ) && (float) $curS > (float) $val;
		case '<':
			return is_numeric( $curS ) && is_numeric( $val ) && (float) $curS < (float) $val;
	}
	return true;
}

/**
 * One mapped field's stored value → the engine value shape. Shared by the
 * panel read path, repeater rows, and the options engine — the per-type
 * vocabulary lives HERE, once.
 *
 * @param array $f Mapped field ({ type, key, choices… }).
 * @param mixed $v Raw stored value.
 * @return mixed
 */
function minn_admin_acf_value_out( $f, $v ) {
	switch ( $f['type'] ) {
		case 'true_false':
			return ! empty( $v );
		case 'image':
			return minn_admin_acf_image_out( $v );
		case 'file':
			return minn_admin_acf_file_out( $v );
		case 'gallery':
			return minn_admin_acf_gallery_out( $v );
		case 'link':
			return minn_admin_acf_link_out( $v );
		case 'multicheck':
			return minn_admin_acf_choices_out( $v );
		case 'date':
			return minn_admin_acf_date_out( $v );
		case 'datetime':
			return minn_admin_acf_datetime_out( $v );
		case 'time':
			return minn_admin_acf_time_out( $v );
		case 'suggest':
			return minn_admin_acf_suggest_out( $f['key'], $v );
		case 'relation':
			return minn_admin_acf_relation_list_out( $f['key'], $v );
	}
	if ( is_array( $v ) ) {
		return ''; // never leak structure through a scalar field
	}
	return null === $v ? '' : $v;
}

/**
 * One mapped field's incoming value → ACF's stored shape. Null means
 * invalid — callers skip the write so bad input never clobbers a stored
 * value. The kses trust boundary for wysiwyg lives here.
 *
 * @param array $f     Mapped field.
 * @param mixed $value Incoming value.
 * @return mixed|null
 */
function minn_admin_acf_value_in( $f, $value ) {
	switch ( $f['type'] ) {
		case 'true_false':
			return ( ! empty( $value ) && 'false' !== $value && '0' !== (string) $value ) ? 1 : 0;
		case 'image':
		case 'file':
			return minn_admin_acf_image_in( $value );
		case 'gallery':
			return minn_admin_acf_gallery_in( $value );
		case 'link':
			return minn_admin_acf_link_in( $value );
		case 'multicheck':
			return minn_admin_acf_choices_in( $value, $f );
		case 'date':
			return minn_admin_acf_date_in( $value );
		case 'datetime':
			return minn_admin_acf_datetime_in( $value );
		case 'time':
			return minn_admin_acf_time_in( $value );
		case 'suggest':
			return minn_admin_acf_suggest_in( $f['key'], $value );
		case 'relation':
			return minn_admin_acf_relation_in( $f['key'], $value );
		case 'wysiwyg':
			$value = is_scalar( $value ) ? (string) $value : '';
			return current_user_can( 'unfiltered_html' ) ? $value : wp_kses_post( $value );
	}
	if ( null === $value || false === $value ) {
		return ''; // clearing stores '' — ACF's own form save does the same
	}
	return is_scalar( $value ) ? $value : null;
}

/**
 * Stored repeater rows → the rows control's [{ __idx, values }] list.
 * __idx is the row's position in the stored data, the write merge's anchor;
 * raw rows key by subfield KEY, the client speaks names.
 *
 * @param array $field Mapped repeater (map_repeater output incl. subs).
 * @param mixed $val   Raw stored rows.
 * @return array
 */
function minn_admin_acf_rows_out( $field, $val ) {
	$rows = array();
	foreach ( array_values( is_array( $val ) ? $val : array() ) as $i => $raw_row ) {
		$rows[] = array(
			'__idx'  => $i,
			'values' => (object) minn_admin_acf_row_values_out( $field['subs'], $raw_row ),
		);
	}
	return $rows;
}

/**
 * One stored row → the form's { sub name => value } map.
 *
 * @param array $subs    Flattened subs (incl. key/gpath).
 * @param mixed $raw_row The stored row.
 * @return array
 */
function minn_admin_acf_row_values_out( $subs, $raw_row ) {
	$vals = array();
	foreach ( $subs as $sub ) {
		// Flattened group subs live nested in the row (gpath descends the
		// group-key chain to the node carrying the sub).
		$node = $raw_row;
		foreach ( (array) ( $sub['gpath'] ?? array() ) as $gk ) {
			$node = is_array( $node ) && isset( $node[ $gk ] ) ? $node[ $gk ] : null;
		}
		$v = is_array( $node ) && array_key_exists( $sub['key'], $node ) ? $node[ $sub['key'] ] : null;
		$vals[ $sub['name'] ] = minn_admin_acf_value_out( $sub, $v );
	}
	return $vals;
}

/**
 * Overlay a form row's edited subs onto the stored row it references. Only
 * mapped subs are written, so values the form never rendered survive.
 *
 * @param array $subs Flattened subs (incl. key/gpath).
 * @param array $vals Incoming { sub name => value }.
 * @param array $base The stored row (empty for a new row).
 * @return array
 */
function minn_admin_acf_row_overlay_in( $subs, $vals, $base ) {
	foreach ( $subs as $sub ) {
		if ( ! array_key_exists( $sub['name'], $vals ) ) {
			continue;
		}
		$v = minn_admin_acf_value_in( $sub, $vals[ $sub['name'] ] );
		if ( null === $v ) {
			continue; // invalid input keeps the stored sub value
		}
		// Flattened group subs write back into their nested home, creating
		// the group arrays on rows that never had them.
		$ref = &$base;
		foreach ( (array) ( $sub['gpath'] ?? array() ) as $gk ) {
			if ( ! isset( $ref[ $gk ] ) || ! is_array( $ref[ $gk ] ) ) {
				$ref[ $gk ] = array();
			}
			$ref = &$ref[ $gk ];
		}
		$ref[ $sub['key'] ] = $v;
		unset( $ref );
	}
	return $base;
}

/**
 * Incoming rows → merged stored rows. Each kept row overlays ONLY its
 * mapped subs onto the original stored row it references (__idx), so
 * complex sub values the form never rendered survive edits, reorders and
 * deletions. Rows without __idx are new; omission deletes; the incoming
 * order is the new order.
 *
 * @param array $field Mapped repeater.
 * @param mixed $value Incoming [{ __idx?, values }] list.
 * @param mixed $orig  The currently stored rows.
 * @return array|null Null when the incoming shape is not a list.
 */
function minn_admin_acf_rows_in( $field, $value, $orig ) {
	if ( ! is_array( $value ) ) {
		return null;
	}
	$orig = is_array( $orig ) ? array_values( $orig ) : array();
	$new  = array();
	foreach ( $value as $row ) {
		$row  = (array) $row;
		$vals = isset( $row['values'] ) ? (array) $row['values'] : array();
		$base = isset( $row['__idx'] ) && is_numeric( $row['__idx'] ) && isset( $orig[ (int) $row['__idx'] ] ) && is_array( $orig[ (int) $row['__idx'] ] )
			? $orig[ (int) $row['__idx'] ]
			: array();
		$new[] = minn_admin_acf_row_overlay_in( $field['subs'], $vals, $base );
	}
	return $new;
}

/**
 * Stored flexible-content sections → the flex control's
 * [{ __idx, __layout, values }] list. Each row maps through its own layout's
 * subs; a section whose stored layout the field no longer declares comes back
 * flagged `__locked` with no values, so the form shows it as preserved rather
 * than dropping it.
 *
 * @param array $field Mapped flex field (map_flex output incl. layoutSubs).
 * @param mixed $val   Raw stored sections.
 * @return array
 */
function minn_admin_acf_flex_out( $field, $val ) {
	$rows = array();
	foreach ( array_values( is_array( $val ) ? $val : array() ) as $i => $raw_row ) {
		$raw_row = (array) $raw_row;
		$layout  = isset( $raw_row['acf_fc_layout'] ) ? (string) $raw_row['acf_fc_layout'] : '';
		$known   = isset( $field['layoutSubs'][ $layout ] );
		$row     = array(
			'__idx'    => $i,
			'__layout' => $layout,
			'values'   => (object) ( $known ? minn_admin_acf_row_values_out( $field['layoutSubs'][ $layout ], $raw_row ) : array() ),
		);
		if ( ! $known ) {
			$row['__locked'] = true;
		}
		$rows[] = $row;
	}
	return $rows;
}

/**
 * Incoming sections → merged stored sections. The rows merge, per layout.
 *
 * A KEPT row's layout is the stored one: the form offers no layout switch, so
 * an incoming mismatch is bad input rather than an edit, and re-keying a row
 * would orphan every sub value under it. A NEW row's layout must be one the
 * field declares — anything else is dropped rather than stored as a section
 * ACF cannot render. Rows whose stored layout the field no longer declares
 * pass through verbatim.
 *
 * @param array $field Mapped flex field.
 * @param mixed $value Incoming [{ __idx?, __layout, values }] list.
 * @param mixed $orig  The currently stored sections.
 * @return array|null Null when the incoming shape is not a list.
 */
function minn_admin_acf_flex_in( $field, $value, $orig ) {
	if ( ! is_array( $value ) ) {
		return null;
	}
	$orig = is_array( $orig ) ? array_values( $orig ) : array();
	$new  = array();
	foreach ( $value as $row ) {
		$row      = (array) $row;
		$anchored = isset( $row['__idx'] ) && is_numeric( $row['__idx'] ) && isset( $orig[ (int) $row['__idx'] ] ) && is_array( $orig[ (int) $row['__idx'] ] );
		$base     = $anchored ? $orig[ (int) $row['__idx'] ] : array();
		$layout   = $anchored
			? (string) ( $base['acf_fc_layout'] ?? '' )
			: ( isset( $row['__layout'] ) ? (string) $row['__layout'] : '' );
		if ( ! isset( $field['layoutSubs'][ $layout ] ) ) {
			if ( $anchored ) {
				$new[] = $base;
			}
			continue;
		}
		$vals                  = isset( $row['values'] ) ? (array) $row['values'] : array();
		$base                  = minn_admin_acf_row_overlay_in( $field['layoutSubs'][ $layout ], $vals, $base );
		$base['acf_fc_layout'] = $layout;
		$new[]                 = $base;
	}
	return $new;
}

/**
 * Build the fieldsRoute response for a post.
 *
 * Conditional logic: when EVERY controlling field is itself rendered in the
 * payload, the condition ships as `cond` (rule field keys translated to
 * sibling names, [[{ f, op, v }]]) and the client shows/hides live. When a
 * controller is not rendered (locked type, another screen), the condition is
 * evaluated ONCE here against stored values — its answer cannot change
 * mid-session because the controller has no control — and a false hides the
 * field entirely, exactly like ACF's own screen.
 *
 * @param int    $post_id   Post ID (0 for new).
 * @param string $post_type Post type slug.
 * @return array{groups: array}
 */
function minn_admin_acf_fields_payload( $post_id, $post_type ) {
	$out    = array();
	$by_key = array(); // mapped field key => name, across ALL groups (ACF conditions may cross groups)
	foreach ( minn_admin_acf_groups_for( $post_id, $post_type ) as $group ) {
		$fields = acf_get_fields( $group );
		$mapped = array();
		$locked = 0;
		foreach ( (array) $fields as $f ) {
			if ( in_array( $f['type'] ?? '', MINN_ADMIN_ACF_CHROME_TYPES, true ) ) {
				continue;
			}
			$cond = ! empty( $f['conditional_logic'] ) && is_array( $f['conditional_logic'] ) ? $f['conditional_logic'] : null;
			// Repeaters (Pro) ride the `rows` control when any sub is simple.
			// Panel-only: options pages and block dataForms keep them locked
			// (their engines have no rows binding).
			if ( 'repeater' === ( $f['type'] ?? '' ) ) {
				$rep = minn_admin_acf_map_repeater( $f );
				if ( $rep ) {
					$by_key[ $rep['key'] ] = $rep['name'];
					$rep                   = minn_admin_acf_public_field( $rep );
					$rep['_cond']          = $cond;
					$mapped[]              = $rep;
				} else {
					$locked++;
				}
				continue;
			}
			// Flexible content (Pro): the `flex` control, sibling of `rows`.
			if ( 'flexible_content' === ( $f['type'] ?? '' ) ) {
				$fx = minn_admin_acf_map_flex( $f );
				if ( $fx ) {
					$by_key[ $fx['key'] ] = $fx['name'];
					$fx                   = minn_admin_acf_public_field( $fx );
					$fx['_cond']          = $cond;
					$mapped[]             = $fx;
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
			$by_key[ $simple['key'] ] = $simple['name'];
			$simple['_cond']          = $cond;
			$mapped[]                 = $simple;
		}
		if ( $mapped || $locked ) {
			$out[] = array(
				'group'  => $group['title'],
				'fields' => $mapped,
				'locked' => $locked,
			);
		}
	}
	// Second pass: translate or statically evaluate each condition.
	$stored = function ( $key ) use ( $post_id ) {
		if ( $post_id ) {
			return get_field( $key, $post_id, false );
		}
		$acf = acf_get_field( $key );
		return $acf && isset( $acf['default_value'] ) ? $acf['default_value'] : '';
	};
	foreach ( $out as $gi => $group ) {
		$fields = array();
		foreach ( $group['fields'] as $f ) {
			$cond = $f['_cond'];
			unset( $f['_cond'], $f['key'] ); // key is internal — the panel keys inputs by name
			if ( $cond ) {
				$all_mapped = true;
				foreach ( $cond as $rules ) {
					foreach ( (array) $rules as $rule ) {
						if ( ! isset( $by_key[ $rule['field'] ?? '' ] ) ) {
							$all_mapped = false;
						}
					}
				}
				if ( $all_mapped ) {
					$f['cond'] = array_map( function ( $rules ) use ( $by_key ) {
						return array_map( function ( $rule ) use ( $by_key ) {
							return array(
								'f'  => $by_key[ $rule['field'] ],
								'op' => isset( $rule['operator'] ) ? (string) $rule['operator'] : '==',
								'v'  => isset( $rule['value'] ) ? (string) $rule['value'] : '',
							);
						}, (array) $rules );
					}, $cond );
				} elseif ( ! minn_admin_acf_eval_cond( $cond, $stored ) ) {
					continue; // statically hidden, exactly like ACF's screen
				}
			}
			$fields[] = $f;
		}
		$out[ $gi ]['fields'] = $fields;
	}
	// A group left with no visible fields and nothing locked drops entirely.
	$out = array_values( array_filter( $out, function ( $g ) {
		return $g['fields'] || $g['locked'];
	} ) );
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
			if ( 'flexible_content' === ( $f['type'] ?? '' ) ) {
				$fx = minn_admin_acf_map_flex( $f );
				if ( $fx ) {
					$out[ $fx['name'] ] = $fx;
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
		$val = minn_admin_acf_raw_value( $field, $post_id );
		if ( 'rows' === $field['type'] ) {
			$out[ $name ] = minn_admin_acf_rows_out( $field, $val );
		} elseif ( 'flex' === $field['type'] ) {
			$out[ $name ] = minn_admin_acf_flex_out( $field, $val );
		} else {
			$out[ $name ] = minn_admin_acf_value_out( $field, $val );
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
		if ( 'rows' === $field['type'] ) {
			$value = minn_admin_acf_rows_in( $field, $value, minn_admin_acf_raw_value( $field, $post_id ) );
		} elseif ( 'flex' === $field['type'] ) {
			$value = minn_admin_acf_flex_in( $field, $value, minn_admin_acf_raw_value( $field, $post_id ) );
		} else {
			$value = minn_admin_acf_value_in( $field, $value );
		}
		if ( null === $value ) {
			continue; // invalid input never clobbers a stored value
		}
		minn_admin_acf_raw_update( $field, $value, $post_id );
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
			// Date, file, link and relational types stay locked in block
			// forms: their values live in the block's data attribute in
			// ACF's raw storage formats, and the inspector has no adapter
			// layer (or per-type picker arming) to translate.
			if ( in_array( $simple['type'], array( 'date', 'datetime', 'time', 'file', 'link', 'suggest', 'relation' ), true ) ) {
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

/**
 * One field value as a short display string for the revision comparison.
 *
 * Structural values (rows, sections, galleries) are SUMMARIZED rather than
 * spelled out: a diff that claimed to show every sub-value of nineteen
 * sections would be unreadable, and half-showing one would be dishonest.
 * The summary says what changed shape; the fields themselves are one click
 * away in the panel.
 *
 * @param array $f Mapped field.
 * @param mixed $v Raw stored value.
 * @return string
 */
function minn_admin_acf_display_value( $f, $v ) {
	$empty = __( 'empty', 'minn-admin' );
	switch ( $f['type'] ) {
		case 'rows':
			$n = is_array( $v ) ? count( $v ) : 0;
			/* translators: %d: number of repeater rows. */
			return $n ? sprintf( _n( '%d row', '%d rows', $n, 'minn-admin' ), $n ) : $empty;
		case 'flex':
			$names = array();
			foreach ( (array) $v as $row ) {
				$row     = (array) $row;
				$layout  = (string) ( $row['acf_fc_layout'] ?? '' );
				$names[] = isset( $f['layouts'][ $layout ] ) ? $f['layouts'][ $layout ]['label'] : $layout;
			}
			return $names ? implode( ', ', $names ) : $empty;
		case 'gallery':
			$n = is_array( $v ) ? count( $v ) : 0;
			/* translators: %d: number of images in a gallery field. */
			return $n ? sprintf( _n( '%d image', '%d images', $n, 'minn-admin' ), $n ) : $empty;
		case 'true_false':
			return empty( $v ) ? __( 'Off', 'minn-admin' ) : __( 'On', 'minn-admin' );
		case 'image':
		case 'file':
			$id = is_array( $v ) ? ( $v['ID'] ?? $v['id'] ?? 0 ) : $v;
			if ( ! $id ) {
				return $empty;
			}
			$title = get_the_title( (int) $id );
			return '' !== $title ? $title : '#' . (int) $id;
		case 'link':
			$v = (array) $v;
			return ( $v['url'] ?? '' ) ? (string) $v['url'] : $empty;
		case 'multicheck':
		case 'relation':
			$parts = array();
			foreach ( (array) $v as $entry ) {
				if ( is_scalar( $entry ) ) {
					$parts[] = ! empty( $f['choices'][ $entry ] ) ? (string) $f['choices'][ $entry ] : (string) $entry;
				}
			}
			return $parts ? implode( ', ', $parts ) : $empty;
	}
	if ( is_array( $v ) ) {
		return $empty;
	}
	$s = trim( wp_strip_all_tags( (string) $v ) );
	if ( '' === $s ) {
		return $empty;
	}
	if ( ! empty( $f['choices'][ $s ] ) ) {
		$s = (string) $f['choices'][ $s ];
	}
	return mb_strlen( $s ) > 160 ? mb_substr( $s, 0, 160 ) . '…' : $s;
}

/**
 * A repeating field's value as a bare count, for when the fuller summary
 * reads the same on both sides of a comparison.
 *
 * @param array $f Mapped field.
 * @param mixed $v Raw stored value.
 * @return string
 */
function minn_admin_acf_count_label( $f, $v ) {
	$n = is_array( $v ) ? count( $v ) : 0;
	if ( ! $n ) {
		return __( 'empty', 'minn-admin' );
	}
	if ( 'flex' === $f['type'] ) {
		/* translators: %d: number of flexible-content sections. */
		return sprintf( _n( '%d section', '%d sections', $n, 'minn-admin' ), $n );
	}
	/* translators: %d: number of repeater rows. */
	return sprintf( _n( '%d row', '%d rows', $n, 'minn-admin' ), $n );
}

// Custom fields in the revision comparison. ACF writes its values onto each
// revision post, so a revision whose only change was a field can finally say
// so instead of reporting itself identical.
add_filter( 'minn_admin_revision_fields', function ( $rows, $post_id, $revision_id ) {
	if ( ! minn_admin_acf_active() ) {
		return $rows;
	}
	$label  = __( 'Custom fields', 'minn-admin' );
	$fields = minn_admin_acf_simple_fields_for_post( $post_id );
	// Only fields the revision actually RECORDED can be compared. ACF writes
	// its values onto a revision as it is taken, so a revision made before the
	// field existed (or by a path that never stored one) simply has nothing —
	// reading that as "was empty" would invent a change that never happened.
	// The `_name` reference meta is what proves a value was written.
	$fields = array_filter( $fields, function ( $f ) use ( $revision_id ) {
		return '' !== (string) get_post_meta( $revision_id, '_' . $f['name'], true );
	} );
	foreach ( $fields as $field ) {
		$was = minn_admin_acf_raw_value( $field, $revision_id );
		$now = minn_admin_acf_raw_value( $field, $post_id );
		if ( wp_json_encode( $was ) === wp_json_encode( $now ) ) {
			continue;
		}
		$was_s = minn_admin_acf_display_value( $field, $was );
		$now_s = minn_admin_acf_display_value( $field, $now );
		// The values differ but their summaries read the same (the same eight
		// sections, edited inside). Saying "X → X" would report the change as
		// no change, so name what actually moved.
		if ( $was_s === $now_s ) {
			if ( in_array( $field['type'], array( 'rows', 'flex' ), true ) ) {
				$was_s = minn_admin_acf_count_label( $field, $was );
				$now_s = minn_admin_acf_count_label( $field, $now );
				$n     = 0;
				$old   = is_array( $was ) ? array_values( $was ) : array();
				foreach ( ( is_array( $now ) ? array_values( $now ) : array() ) as $i => $row ) {
					if ( ! isset( $old[ $i ] ) || wp_json_encode( $old[ $i ] ) !== wp_json_encode( $row ) ) {
						$n++;
					}
				}
				if ( $n ) {
					/* translators: %d: how many rows or sections were edited. */
					$now_s .= ' · ' . sprintf( _n( '%d edited', '%d edited', $n, 'minn-admin' ), $n );
				}
			} else {
				$now_s .= ' · ' . __( 'changed', 'minn-admin' );
			}
		}
		$rows[] = array(
			'group' => $label,
			'label' => trim( wp_strip_all_tags( (string) $field['label'] ) ),
			'was'   => $was_s,
			'now'   => $now_s,
		);
	}
	return $rows;
}, 10, 3 );

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
 * Tab layout for one options page. ACF `tab` fields are the natural tab
 * boundaries: each starts a Minn tab that collects the fields after it (ACF's
 * own semantics); fields before any tab land in a tab named for their group.
 * Ids are positional (tab-0…) — both GET and POST re-derive the same walk, so
 * they stay stable within a schema version.
 *
 * GROUP fields flatten into a titled SECTION: each mappable sub (including
 * repeaters) becomes a tab field that reads and writes through the parent —
 * group raw arrays key by sub KEY, exactly like repeater rows — carrying
 * `_section` (the group label) and `_parent` (the group key). The group's own
 * conditional logic distributes onto every sub (a hidden group hides them all).
 *
 * @param array $page Options page array.
 * @return array[] { id, label, fields (mapped incl. key/_section/_parent), locked }
 */
function minn_admin_acf_options_tabs( $page ) {
	$tabs    = array();
	$current = -1;
	$open    = function ( $label ) use ( &$tabs, &$current ) {
		$tabs[]  = array(
			'label'        => $label,
			'fields'       => array(),
			'locked'       => 0,
			'lockedLabels' => array(),
		);
		$current = count( $tabs ) - 1;
	};
	$lock = function ( $label ) use ( &$tabs, &$current ) {
		$tabs[ $current ]['locked']++;
		$label = trim( wp_strip_all_tags( (string) $label ) );
		if ( '' !== $label ) {
			$tabs[ $current ]['lockedLabels'][] = $label;
		}
	};
	// AND two OR-of-AND conditional trees by distribution: (A|B) AND C
	// becomes (A,C)|(B,C).
	$and_conds = function ( $a, $b ) {
		if ( ! $a ) {
			return $b;
		}
		if ( ! $b ) {
			return $a;
		}
		$out = array();
		foreach ( $a as $ga ) {
			foreach ( $b as $gb ) {
				$out[] = array_merge( (array) $ga, (array) $gb );
			}
		}
		return $out;
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
			$cond = ! empty( $f['conditional_logic'] ) && is_array( $f['conditional_logic'] ) ? $f['conditional_logic'] : null;
			if ( 'group' === $type ) {
				// Recursive flatten: a group inside a group is still a
				// namespace — its subs join the top group's SECTION with
				// prefixed labels ("Bar · Title"), and `_path` records the
				// group-key chain the read/write descend. Group labels can
				// carry admin-screen markup (dashicons spans); section and
				// prefix text is plain.
				$section = trim( wp_strip_all_tags( (string) $f['label'] ) );
				$flatten = function ( $sub_fields, $path, $prefix, $inherited ) use ( &$flatten, &$tabs, &$current, $and_conds, $lock, $f, $section ) {
					foreach ( (array) $sub_fields as $sub ) {
						if ( in_array( $sub['type'] ?? '', MINN_ADMIN_ACF_CHROME_TYPES, true ) ) {
							continue;
						}
						$scond = $and_conds( $inherited, ! empty( $sub['conditional_logic'] ) && is_array( $sub['conditional_logic'] ) ? $sub['conditional_logic'] : null );
						if ( 'group' === ( $sub['type'] ?? '' ) && count( $path ) < 4 ) {
							$glabel = trim( wp_strip_all_tags( (string) ( $sub['label'] ?? '' ) ) );
							$flatten(
								$sub['sub_fields'] ?? array(),
								array_merge( $path, array( $sub['key'] ) ),
								$prefix . ( '' !== $glabel ? $glabel . ' · ' : '' ),
								$scond
							);
							continue;
						}
						$stype = $sub['type'] ?? '';
						if ( 'repeater' === $stype ) {
							$m = minn_admin_acf_map_repeater( $sub );
						} elseif ( 'flexible_content' === $stype ) {
							$m = minn_admin_acf_map_flex( $sub );
						} else {
							$m = minn_admin_acf_map_field( $sub );
						}
						if ( ! $m ) {
							$lock( $prefix . ( $sub['label'] ?? '' ) );
							continue;
						}
						$m['label']    = $prefix . $m['label'];
						$m['_cond']    = $scond;
						$m['_section'] = $section;
						$m['_parent']  = $f['key'];
						$m['_path']    = $path;
						$tabs[ $current ]['fields'][] = $m;
					}
				};
				$flatten( $f['sub_fields'] ?? array(), array( $f['key'] ), '', $cond );
				continue;
			}
			if ( 'repeater' === $type || 'flexible_content' === $type ) {
				$rep = 'repeater' === $type ? minn_admin_acf_map_repeater( $f ) : minn_admin_acf_map_flex( $f );
				if ( ! $rep ) {
					$lock( $f['label'] ?? '' );
					continue;
				}
				$rep['_cond']    = $cond;
				$rep['_section'] = '';
				$rep['_parent']  = null;
				$tabs[ $current ]['fields'][] = $rep;
				continue;
			}
			$simple = minn_admin_acf_map_field( $f );
			if ( ! $simple ) {
				$lock( $f['label'] ?? '' );
				continue;
			}
			$simple['_cond']    = $cond;
			$simple['_section'] = '';
			$simple['_parent']  = null;
			$tabs[ $current ]['fields'][] = $simple;
		}
	}
	// Conditional logic: live (`cond`, rule fields = keys — options controls
	// already key by field key) when every controller sits on the SAME tab;
	// otherwise a one-time evaluation against stored values, hiding the
	// field like ACF's own screen would. A flattened group sub's stored
	// value reads through its parent.
	$post_id = ! empty( $page['post_id'] ) ? $page['post_id'] : 'options';
	$stored  = function ( $key ) use ( $post_id ) {
		$acf = acf_get_field( $key );
		if ( $acf && ! empty( $acf['parent'] ) ) {
			$parent = acf_get_field( $acf['parent'] );
			if ( $parent && 'group' === ( $parent['type'] ?? '' ) ) {
				$praw = get_field( $parent['key'], $post_id, false );
				return is_array( $praw ) && array_key_exists( $key, $praw ) ? $praw[ $key ] : null;
			}
		}
		return get_field( $key, $post_id, false );
	};
	foreach ( $tabs as $ti => $tab ) {
		$keys = array();
		foreach ( $tab['fields'] as $f ) {
			$keys[ $f['key'] ] = true;
		}
		$fields = array();
		foreach ( $tab['fields'] as $f ) {
			$cond = $f['_cond'];
			unset( $f['_cond'] );
			if ( $cond ) {
				$same_tab = true;
				foreach ( $cond as $rules ) {
					foreach ( (array) $rules as $rule ) {
						if ( ! isset( $keys[ $rule['field'] ?? '' ] ) ) {
							$same_tab = false;
						}
					}
				}
				if ( $same_tab ) {
					$f['cond'] = array_map( function ( $rules ) {
						return array_map( function ( $rule ) {
							return array(
								'f'  => (string) $rule['field'],
								'op' => isset( $rule['operator'] ) ? (string) $rule['operator'] : '==',
								'v'  => isset( $rule['value'] ) ? (string) $rule['value'] : '',
							);
						}, (array) $rules );
					}, $cond );
				} elseif ( ! minn_admin_acf_eval_cond( $cond, $stored ) ) {
					continue;
				}
			}
			$fields[] = $f;
		}
		$tabs[ $ti ]['fields'] = $fields;
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
 * Flattened ACF group fields render as their own titled sections; the
 * tab-level locked count rides the last section.
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
	$post_id    = ! empty( $page['post_id'] ) ? $page['post_id'] : 'options';
	$parent_raw = array(); // top group key => raw array (fetched once per parent)
	$read_raw   = function ( $f ) use ( $post_id, &$parent_raw ) {
		if ( empty( $f['_parent'] ) ) {
			return minn_admin_acf_raw_value( $f, $post_id );
		}
		$gk = $f['_parent'];
		if ( ! array_key_exists( $gk, $parent_raw ) ) {
			$raw = get_field( $gk, $post_id, false );
			$parent_raw[ $gk ] = is_array( $raw ) ? $raw : array();
		}
		// Descend the group-key chain past the top parent (nested groups).
		$node = $parent_raw[ $gk ];
		foreach ( array_slice( (array) ( $f['_path'] ?? array( $gk ) ), 1 ) as $step ) {
			$node = is_array( $node ) && isset( $node[ $step ] ) ? $node[ $step ] : null;
		}
		return is_array( $node ) && array_key_exists( $f['key'], $node ) ? $node[ $f['key'] ] : null;
	};
	$sections = array();
	$values   = array();
	foreach ( $tab['fields'] as $f ) {
		$sf = array(
			'key'   => $f['key'],
			'label' => $f['label'],
			'type'  => 'true_false' === $f['type'] ? 'toggle'
				: ( in_array( $f['type'], array( 'select', 'radio' ), true ) ? 'select'
				: ( in_array( $f['type'], array( 'number', 'range' ), true ) ? 'number'
				: ( in_array( $f['type'], array( 'textarea', 'wysiwyg', 'gallery', 'image', 'file', 'link', 'multicheck', 'date', 'datetime', 'time', 'suggest', 'relation', 'rows', 'flex', 'color_picker' ), true ) ? $f['type'] : 'text' ) ) ),
		);
		if ( 'rows' === $sf['type'] ) {
			$sf['subfields'] = $f['subfields'];
			$sf['subLocked'] = $f['subLocked'];
		}
		if ( 'flex' === $sf['type'] ) {
			$sf['layouts'] = $f['layouts'];
		}
		if ( ! empty( $f['route'] ) ) {
			$sf['route'] = $f['route'];
		}
		if ( ! empty( $f['cond'] ) ) {
			$sf['cond'] = $f['cond'];
		}
		if ( in_array( $sf['type'], array( 'select', 'multicheck' ), true ) ) {
			$sf['options'] = array();
			foreach ( (array) ( $f['choices'] ?? array() ) as $value => $label ) {
				$sf['options'][] = array( (string) $value, (string) $label );
			}
		}
		$sec_key = (string) ( $f['_parent'] ? $f['_parent'] : '' );
		if ( ! isset( $sections[ $sec_key ] ) ) {
			$sections[ $sec_key ] = array(
				'title'  => (string) ( $f['_section'] ?? '' ),
				'fields' => array(),
				'locked' => 0,
			);
		}
		$sections[ $sec_key ]['fields'][] = $sf;

		$v = $read_raw( $f );
		if ( 'rows' === $f['type'] ) {
			$values[ $f['key'] ] = minn_admin_acf_rows_out( $f, $v );
		} elseif ( 'flex' === $f['type'] ) {
			$values[ $f['key'] ] = minn_admin_acf_flex_out( $f, $v );
		} else {
			$values[ $f['key'] ] = minn_admin_acf_value_out( $f, $v );
		}
	}
	$groups = array_values( $sections );
	if ( ! $groups ) {
		$groups = array( array( 'title' => '', 'fields' => array(), 'locked' => 0 ) );
	}
	$groups[ count( $groups ) - 1 ]['locked'] = $tab['locked'];
	if ( ! empty( $tab['lockedLabels'] ) ) {
		// Name what the count hides — "1 advanced setting" alone reads as a
		// bug when the tab's whole content is one flexible-content builder.
		$groups[ count( $groups ) - 1 ]['lockedLabels'] = array_values( array_unique( $tab['lockedLabels'] ) );
	}
	return array(
		'groups'   => $groups,
		'values'   => $values,
		'adminUrl' => minn_admin_acf_options_admin_url( $page ),
	);
}

/**
 * Save edited values through ACF's own setter. Keys are whitelisted against
 * the page's mapped fields (never an arbitrary update_field) and coerced to
 * ACF's stored shapes through the shared value_in layer. Flattened group
 * subs collect into ONE parent write each: the stored group array is the
 * base, edited subs overlay it, so unmapped subs survive untouched (the
 * repeater-merge discipline, at group scale).
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
	$post_id        = ! empty( $page['post_id'] ) ? $page['post_id'] : 'options';
	$parent_updates = array(); // top group key => [ { path (past the top), key, value } ]
	$parent_stored  = function ( $gkey ) use ( $post_id ) {
		$raw = get_field( $gkey, $post_id, false );
		return is_array( $raw ) ? $raw : array();
	};
	// Stored value at a field's nested home (rows need their original rows
	// for the __idx merge).
	$stored_at = function ( $f ) use ( $parent_stored, $post_id ) {
		if ( empty( $f['_parent'] ) ) {
			return minn_admin_acf_raw_value( $f, $post_id );
		}
		$node = $parent_stored( $f['_parent'] );
		foreach ( array_slice( (array) ( $f['_path'] ?? array( $f['_parent'] ) ), 1 ) as $step ) {
			$node = is_array( $node ) && isset( $node[ $step ] ) ? $node[ $step ] : null;
		}
		return is_array( $node ) && array_key_exists( $f['key'], $node ) ? $node[ $f['key'] ] : null;
	};
	foreach ( (array) $values as $key => $v ) {
		if ( ! isset( $byKey[ $key ] ) ) {
			continue;
		}
		$f = $byKey[ $key ];
		if ( 'rows' === $f['type'] ) {
			$v = minn_admin_acf_rows_in( $f, $v, $stored_at( $f ) );
		} elseif ( 'flex' === $f['type'] ) {
			$v = minn_admin_acf_flex_in( $f, $v, $stored_at( $f ) );
		} else {
			$v = minn_admin_acf_value_in( $f, $v );
		}
		if ( null === $v ) {
			continue; // invalid input never clobbers a stored value
		}
		if ( ! empty( $f['_parent'] ) ) {
			$parent_updates[ $f['_parent'] ][] = array(
				'path'  => array_slice( (array) ( $f['_path'] ?? array( $f['_parent'] ) ), 1 ),
				'key'   => $key,
				'value' => $v,
			);
		} else {
			minn_admin_acf_raw_update( $f, $v, $post_id );
		}
	}
	// One write per top-level group: the stored array is the base, each
	// edited sub set at its nested home (group arrays created as needed),
	// so subs Minn cannot render survive untouched.
	foreach ( $parent_updates as $gkey => $edits ) {
		$base = $parent_stored( $gkey );
		foreach ( $edits as $edit ) {
			$ref = &$base;
			foreach ( $edit['path'] as $step ) {
				if ( ! isset( $ref[ $step ] ) || ! is_array( $ref[ $step ] ) ) {
					$ref[ $step ] = array();
				}
				$ref = &$ref[ $step ];
			}
			$ref[ $edit['key'] ] = $edit['value'];
			unset( $ref );
		}
		update_field( $gkey, $base, $post_id );
	}
}

/**
 * Group options pages into their ACF parent MENUS, so a "Site Options"
 * parent with four child pages is ONE sidebar item, not four (ACF's own
 * admin shows exactly one).
 *
 * Two registration shapes exist in the wild, both handled: the documented
 * one (children carry parent_slug = the redirect parent's slug), and the
 * first-child one (ACF re-parents children onto the FIRST child's slug,
 * while the real parent floats as a fieldless redirect page registered just
 * before it — the menu label). The anchor is whatever slug other pages name
 * as their parent; core-menu parents ('themes.php'…) never group.
 *
 * @return array{menus: array, standalone: array} menus: anchor => { label,
 *         members (allowed page slugs, registration order) }.
 */
function minn_admin_acf_options_menu_map() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}
	$all     = (array) acf_get_options_pages();
	$allowed = minn_admin_acf_options_pages_allowed();
	$anchors = array();
	foreach ( $all as $slug => $p ) {
		$parent = isset( $p['parent_slug'] ) ? (string) $p['parent_slug'] : '';
		if ( '' === $parent || false !== strpos( $parent, '.php' ) || ! isset( $all[ $parent ] ) ) {
			continue;
		}
		$anchors[ $parent ] = true;
	}
	$menus = array();
	$used  = array();
	foreach ( array_keys( $anchors ) as $anchor ) {
		$members = array();
		foreach ( $all as $slug => $p ) {
			$parent = isset( $p['parent_slug'] ) ? (string) $p['parent_slug'] : '';
			if ( $slug !== $anchor && $parent !== $anchor ) {
				continue;
			}
			$used[ $slug ] = true; // grouped even when cap-filtered for this user
			if ( isset( $allowed[ $slug ] ) ) {
				$members[] = $slug;
			}
		}
		if ( ! $members ) {
			continue;
		}
		// The menu label. Documented shape: the anchor IS the fieldless
		// redirect parent — its title is the label. First-child shape: the
		// real parent floats as a redirect page whose menu_slug ACF has
		// REWRITTEN to point at the anchor (its registry KEY keeps the
		// original slug) — that pointer is the link, and its title is the
		// menu's name.
		$label = ! empty( $all[ $anchor ]['page_title'] ) ? $all[ $anchor ]['page_title'] : $anchor;
		foreach ( $all as $key => $p ) {
			if ( $key !== $anchor && ! empty( $p['redirect'] ) && $anchor === (string) ( $p['menu_slug'] ?? '' ) ) {
				$label = ! empty( $p['page_title'] ) ? $p['page_title'] : $label;
				$used[ $key ] = true;
				break;
			}
		}
		$menus[ $anchor ] = array(
			'label'   => $label,
			'members' => $members,
		);
	}
	$standalone = array();
	foreach ( $allowed as $slug => $p ) {
		if ( empty( $used[ $slug ] ) ) {
			$standalone[] = $slug;
		}
	}
	$map = array(
		'menus'      => $menus,
		'standalone' => $standalone,
	);
	return $map;
}

/**
 * Merged tab list for one menu: each member page's tabs in registration
 * order, re-numbered positionally. A single-tab member's tab takes the
 * PAGE's name (it reads as the menu item it was); multi-tab members keep
 * their own tab labels. Each tab remembers its member page and the
 * member-local id so GET/POST can delegate.
 *
 * @param string $anchor Menu anchor slug.
 * @return array[]
 */
function minn_admin_acf_options_menu_tabs( $anchor ) {
	$map  = minn_admin_acf_options_menu_map();
	$menu = isset( $map['menus'][ $anchor ] ) ? $map['menus'][ $anchor ] : null;
	if ( ! $menu ) {
		return array();
	}
	$allowed = minn_admin_acf_options_pages_allowed();
	$out     = array();
	foreach ( $menu['members'] as $slug ) {
		$page   = $allowed[ $slug ];
		$tabs   = minn_admin_acf_options_tabs( $page );
		$single = 1 === count( $tabs );
		foreach ( $tabs as $t ) {
			$t['_page'] = $slug;
			$t['_ptab'] = $t['id'];
			if ( $single && ! empty( $page['page_title'] ) ) {
				$t['label'] = $page['page_title'];
			}
			$t['id'] = 'tab-' . count( $out );
			$out[]   = $t;
		}
	}
	return $out;
}

/**
 * Resolve one merged tab id to its member page + member-local tab id.
 *
 * @param string $anchor Menu anchor slug.
 * @param string $tab_id Merged tab id.
 * @return array|null { page (array), tab (member-local id) }
 */
function minn_admin_acf_options_menu_target( $anchor, $tab_id ) {
	$allowed = minn_admin_acf_options_pages_allowed();
	foreach ( minn_admin_acf_options_menu_tabs( $anchor ) as $t ) {
		if ( $t['id'] === $tab_id && isset( $allowed[ $t['_page'] ] ) ) {
			return array(
				'page' => $allowed[ $t['_page'] ],
				'tab'  => $t['_ptab'],
			);
		}
	}
	return null;
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_acf_options_active() ) {
		return $surfaces;
	}
	$map     = minn_admin_acf_options_menu_map();
	$allowed = minn_admin_acf_options_pages_allowed();
	$reg     = function ( $id, $label, $cap, $slug, $tabs ) use ( &$surfaces ) {
		$tab_list = array();
		foreach ( $tabs as $t ) {
			$tab_list[] = array(
				'id'    => $t['id'],
				'label' => $t['label'],
			);
		}
		$surfaces[ $id ] = array(
			'label'    => $label,
			'sub'      => 'ACF',
			'icon'     => 'gear',
			'cap'      => $cap,
			'settings' => array(
				'label' => __( 'Settings', 'minn-admin' ),
				'tabs'  => $tab_list,
				'route' => 'minn-admin/v1/acf/options/' . rawurlencode( $slug ) . '/{tab}',
			),
		);
	};
	foreach ( $map['menus'] as $anchor => $menu ) {
		$tabs = minn_admin_acf_options_menu_tabs( $anchor );
		if ( ! $tabs ) {
			continue;
		}
		// Nav gate: the first member's own capability (per-tab data is
		// cap-filtered again at request time through pages_allowed).
		$first = $allowed[ $menu['members'][0] ];
		$reg( 'acf-options-' . sanitize_key( $anchor ), $menu['label'], ! empty( $first['capability'] ) ? $first['capability'] : 'edit_posts', $anchor, $tabs );
	}
	foreach ( $map['standalone'] as $slug ) {
		$page = $allowed[ $slug ];
		$tabs = minn_admin_acf_options_tabs( $page );
		if ( ! $tabs ) {
			continue;
		}
		$reg( 'acf-options-' . sanitize_key( $slug ), $page['page_title'] ? $page['page_title'] : $page['menu_slug'], ! empty( $page['capability'] ) ? $page['capability'] : 'edit_posts', $slug, $tabs );
	}
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_acf_options_active() ) {
		return;
	}
	// A slug is either a standalone allowed page, or a menu anchor whose
	// merged tab id delegates to the member page owning it (its own
	// capability already filtered through pages_allowed).
	$resolve = function ( $req ) {
		$pages = minn_admin_acf_options_pages_allowed();
		$slug  = rawurldecode( (string) $req['page'] );
		$tab   = (string) $req['tab'];
		$map   = minn_admin_acf_options_menu_map();
		if ( isset( $map['menus'][ $slug ] ) ) {
			$target = minn_admin_acf_options_menu_target( $slug, $tab );
			return $target ? array(
				'page' => $target['page'],
				'tab'  => $target['tab'],
			) : null;
		}
		return isset( $pages[ $slug ] ) ? array(
			'page' => $pages[ $slug ],
			'tab'  => $tab,
		) : null;
	};
	register_rest_route( 'minn-admin/v1', '/acf/options/(?P<page>[A-Za-z0-9_%.\-]+)/(?P<tab>tab-\d+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => function ( $req ) use ( $resolve ) {
				return (bool) $resolve( $req ); // allowed-pages already filters by the page's own capability
			},
			'callback'            => function ( $req ) use ( $resolve ) {
				$target = $resolve( $req );
				return rest_ensure_response( minn_admin_acf_options_tab_shape( $target['page'], $target['tab'] ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => function ( $req ) use ( $resolve ) {
				return (bool) $resolve( $req );
			},
			'callback'            => function ( $req ) use ( $resolve ) {
				$target = $resolve( $req );
				$body   = $req->get_json_params();
				$values = isset( $body['values'] ) && is_array( $body['values'] ) ? $body['values'] : array();
				minn_admin_acf_options_save( $target['page'], $values );
				return rest_ensure_response( minn_admin_acf_options_tab_shape( $target['page'], $target['tab'] ) );
			},
		),
	) );
} );
