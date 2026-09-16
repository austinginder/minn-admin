<?php
/**
 * WooCommerce store settings — every WooCommerce → Settings page editable
 * from Minn's Store settings page.
 *
 * The schema is read from WooCommerce's own settings registry at request time
 * (`WC_Admin_Settings::get_settings_pages()` → each page's sections → each
 * section's field array), never hand-copied, so a WooCommerce update or an
 * extension page (Subscriptions, Point of Sale, JetWooBuilder) shows up in
 * Minn with no adapter change. Fields whose type Minn cannot draw generically
 * (React slotfills, the email preview, the tax-rate table, the payments and
 * shipping-zone screens that other phases own) are counted as locked with the
 * wp-admin link.
 *
 * Writes reproduce the wp-admin form submit: the section's field list is
 * assembled into `$_POST` (current values overlaid with the edited ones,
 * exactly what the browser would send) and WooCommerce's own save sequence
 * runs — `woocommerce_settings_save_{tab}` (the page's save(), which carries
 * every page-specific override: term recounts, tax classes, page-collision
 * rules, tracking events), then the update-options actions extensions listen
 * to, the rewrite flush queue, endpoints and `woocommerce_settings_saved`.
 * Every sanitizer and every listener is theirs.
 *
 * Emails ride the same registry via `WC_Settings_API` (each `WC_Email`'s
 * `form_fields`), saved through `set_post_data()` + the email's own
 * `woocommerce_update_options_email_{id}` action.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Field types WooCommerce draws as UI chrome, not settings. Never counted as
 * locked: nothing is missing when they are absent.
 */
const MINN_ADMIN_WC_UI_TYPES = array(
	'title',
	'sectionend',
	'email_notification',
	'email_preview',
	'email_color_palette',
	'email_improvements_button',
	'previewing_new_templates',
	'conflict_error',
	'add_settings_slot',
	'slotfill_placeholder',
	'info',
);

/**
 * The primed settings-page registry (pages are WC_Settings_Page instances).
 *
 * The admin settings class is not loaded under REST; requiring it is enough,
 * get_settings_pages() pulls the page classes in itself. Cached per request:
 * constructing the pages hooks their save() callbacks, which must happen only
 * once or a save would run twice.
 *
 * @return WC_Settings_Page[] Keyed by page id.
 */
function minn_admin_wc_settings_pages() {
	static $pages = null;
	if ( null !== $pages ) {
		return $pages;
	}
	$pages = array();
	if ( ! class_exists( 'WooCommerce' ) ) {
		return $pages;
	}
	if ( ! class_exists( 'WC_Admin_Settings' ) ) {
		require_once WC_ABSPATH . 'includes/admin/class-wc-admin-settings.php';
	}
	// Page classes reach for admin helpers (wc_back_header, screen ids) that
	// the front-end bootstrap never loads.
	if ( ! function_exists( 'wc_back_header' ) && file_exists( WC_ABSPATH . 'includes/admin/wc-admin-functions.php' ) ) {
		require_once WC_ABSPATH . 'includes/admin/wc-admin-functions.php';
	}
	try {
		foreach ( WC_Admin_Settings::get_settings_pages() as $page ) {
			if ( is_object( $page ) && method_exists( $page, 'get_id' ) ) {
				$pages[ $page->get_id() ] = $page;
			}
		}
	} catch ( Throwable $e ) {
		$pages = array();
	}
	return $pages;
}

/**
 * Raw WooCommerce field array for one page section, through the compat
 * accessor: extension pages written before WC 5.4 redefine `get_settings(
 * $section )` and answer nothing to `get_settings_for_section()` (WooCommerce
 * Subscriptions and JetWooBuilder both do), which is why WooCommerce's own
 * save path reads through `get_settings()` too.
 *
 * @param WC_Settings_Page $page    Page.
 * @param string           $section Section id ('' for the default section).
 * @return array Field arrays.
 */
function minn_admin_wc_settings_raw_fields( $page, $section ) {
	try {
		$fields = $page->get_settings( $section );
	} catch ( Throwable $e ) {
		return array();
	}
	return is_array( $fields ) ? array_values( array_filter( $fields, 'is_array' ) ) : array();
}

/**
 * Plain text out of a WooCommerce description: tags stripped, entities
 * decoded, whitespace collapsed.
 *
 * @param mixed $s Description.
 * @return string
 */
function minn_admin_wc_settings_text( $s ) {
	if ( ! is_string( $s ) || '' === $s ) {
		return '';
	}
	$s = wp_strip_all_tags( $s, true );
	$s = html_entity_decode( $s, ENT_QUOTES, 'UTF-8' );
	return trim( preg_replace( '/\s+/u', ' ', $s ) );
}

/**
 * Country + state catalog as combobox options: "US:CA" for a state, "US" for
 * a country without states, the shape WooCommerce stores for the base
 * location.
 *
 * @return array [ value, label ] pairs.
 */
function minn_admin_wc_settings_country_state_options() {
	$out = array();
	if ( ! function_exists( 'WC' ) || ! WC()->countries ) {
		return $out;
	}
	foreach ( WC()->countries->get_countries() as $cc => $cname ) {
		$states = WC()->countries->get_states( $cc );
		$cname  = html_entity_decode( $cname, ENT_QUOTES, 'UTF-8' );
		if ( is_array( $states ) && $states ) {
			foreach ( $states as $sc => $sname ) {
				$out[] = array( $cc . ':' . $sc, $cname . ' — ' . html_entity_decode( $sname, ENT_QUOTES, 'UTF-8' ) );
			}
		} else {
			$out[] = array( $cc, $cname );
		}
	}
	return $out;
}

/**
 * Published pages as combobox options, plus whatever page the option holds
 * right now even when it is not published (a draft shop page must still show
 * as the current pick rather than silently reading as none).
 *
 * @param array $exclude Page ids to leave out (WC's own `args.exclude`).
 * @param mixed $current The stored value.
 * @return array [ value, label ] pairs, an empty pick first.
 */
function minn_admin_wc_settings_page_options( $exclude, $current ) {
	$out   = array( array( '', __( 'No page', 'minn-admin' ) ) );
	$pages = get_pages(
		array(
			'post_status' => 'publish',
			'exclude'     => array_map( 'intval', (array) $exclude ),
			'number'      => 500,
		)
	);
	$seen  = array();
	foreach ( (array) $pages as $p ) {
		$seen[ $p->ID ] = true;
		$depth          = 0;
		$anc            = $p->post_parent;
		while ( $anc && $depth < 6 ) {
			$depth++;
			$parent = get_post( $anc );
			$anc    = $parent ? $parent->post_parent : 0;
		}
		$title = get_the_title( $p );
		$out[] = array( (string) $p->ID, str_repeat( '— ', $depth ) . ( '' === $title ? __( '(no title)', 'minn-admin' ) : $title ) );
	}
	$cur = (int) $current;
	if ( $cur && empty( $seen[ $cur ] ) ) {
		$p = get_post( $cur );
		if ( $p && 'page' === $p->post_type ) {
			$out[] = array( (string) $cur, get_the_title( $p ) . ' (' . $p->post_status . ')' );
		}
	}
	return $out;
}

/**
 * The relative-date units WooCommerce offers on its retention selectors.
 *
 * @return array [ value, label ] pairs.
 */
function minn_admin_wc_settings_relative_units() {
	return array(
		array( 'days', __( 'Day(s)', 'minn-admin' ) ),
		array( 'weeks', __( 'Week(s)', 'minn-admin' ) ),
		array( 'months', __( 'Month(s)', 'minn-admin' ) ),
		array( 'years', __( 'Year(s)', 'minn-admin' ) ),
	);
}

/**
 * Options map (assoc) → [ value, label ] pairs with entities decoded.
 *
 * @param mixed $options WC options.
 * @return array
 */
function minn_admin_wc_settings_options( $options ) {
	$out = array();
	foreach ( (array) $options as $v => $l ) {
		$out[] = array( (string) $v, minn_admin_wc_settings_text( is_string( $l ) ? $l : (string) $v ) );
	}
	return $out;
}

/**
 * Current stored value for a WooCommerce settings field, the way its own
 * screen reads it (`WC_Admin_Settings::get_option` understands `a[b]` ids and
 * defaults); fields that are not options carry their value in the array.
 *
 * @param array $f Field.
 * @return mixed
 */
function minn_admin_wc_settings_current( $f ) {
	if ( isset( $f['is_option'] ) && false === $f['is_option'] ) {
		return isset( $f['value'] ) ? $f['value'] : '';
	}
	if ( isset( $f['value'] ) && ! isset( $f['id'] ) ) {
		return $f['value'];
	}
	$default = isset( $f['default'] ) ? $f['default'] : '';
	return WC_Admin_Settings::get_option( isset( $f['field_name'] ) ? $f['field_name'] : $f['id'], $default );
}

/**
 * Map one WooCommerce settings field onto Minn's form vocabulary.
 *
 * Returns a list of Minn fields (one, or two for the relative-date pair),
 * or null when the field is locked (a type Minn cannot draw, or a disabled
 * control). The caller fills `$values` keyed by Minn field key.
 *
 * @param array $f      WC field.
 * @param array $values Values map (by reference).
 * @return array|null
 */
function minn_admin_wc_settings_map_field( $f, &$values ) {
	$type = isset( $f['type'] ) ? (string) $f['type'] : '';
	$id   = isset( $f['id'] ) ? (string) $f['id'] : '';
	if ( '' === $id || '' === $type ) {
		return null;
	}
	if ( ! empty( $f['disabled'] ) ) {
		return null;
	}
	$title = minn_admin_wc_settings_text( isset( $f['title'] ) ? $f['title'] : ( isset( $f['name'] ) ? $f['name'] : '' ) );
	$desc  = minn_admin_wc_settings_text( isset( $f['desc'] ) ? $f['desc'] : '' );
	$tip   = isset( $f['desc_tip'] ) && is_string( $f['desc_tip'] ) ? minn_admin_wc_settings_text( $f['desc_tip'] ) : '';
	// WooCommerce's two description slots: `desc` is the visible line (or,
	// with desc_tip === true, the tooltip); a string desc_tip is a tooltip
	// beside a visible desc. Minn has one help line, so both land there.
	$help = trim( implode( ' ', array_filter( array( $desc, $tip ) ) ) );
	if ( 'checkbox' === $type ) {
		// The checkbox's own text is what the switch means; the title names the
		// row when there is one, else that text is the label.
		$label = '' !== $title ? $title : $desc;
		$help  = '' !== $title ? $help : $tip;
	} else {
		$label = '' !== $title ? $title : $id;
	}
	$base = array(
		'key'   => $id,
		'label' => $label,
		'help'  => $help,
	);
	if ( ! empty( $f['placeholder'] ) ) {
		$base['placeholder'] = minn_admin_wc_settings_text( $f['placeholder'] );
	}
	$cur = minn_admin_wc_settings_current( $f );
	$ca  = isset( $f['custom_attributes'] ) && is_array( $f['custom_attributes'] ) ? $f['custom_attributes'] : array();

	switch ( $type ) {
		case 'text':
		case 'safe_text':
		case 'decimal':
		case 'price':
		case 'url':
		case 'email':
			$values[ $id ] = is_scalar( $cur ) ? (string) $cur : '';
			return array( $base + array( 'type' => 'email' === $type ? 'email' : ( 'url' === $type ? 'url' : 'text' ) ) );
		case 'password':
			// Served masked; the write path keeps the stored secret when the
			// mask rides back untouched.
			$values[ $id ] = '' === (string) $cur ? '' : '****************';
			return array( $base + array( 'type' => 'text', 'mono' => true ) );
		case 'textarea':
			$values[ $id ] = is_scalar( $cur ) ? (string) $cur : '';
			return array( $base + array( 'type' => 'textarea' ) );
		case 'number':
			$values[ $id ] = '' === (string) $cur ? null : ( is_numeric( $cur ) ? $cur + 0 : (string) $cur );
			$nf            = $base + array( 'type' => 'number' );
			if ( isset( $ca['min'] ) ) {
				$nf['min'] = $ca['min'];
			}
			if ( isset( $ca['max'] ) ) {
				$nf['max'] = $ca['max'];
			}
			return array( $nf );
		case 'color':
			$values[ $id ] = is_scalar( $cur ) ? (string) $cur : '';
			return array( $base + array( 'type' => 'color' ) );
		case 'checkbox':
			$values[ $id ] = 'yes' === $cur || true === $cur || '1' === $cur || 1 === $cur;
			return array( $base + array( 'type' => 'toggle' ) );
		case 'hidden':
			// WooCommerce hides the email auto-sync switch behind its React
			// palette; the stored yes/no is an ordinary switch here. Any other
			// hidden field is internal and stays out of the form.
			if ( 'woocommerce_email_auto_sync_with_theme' !== $id ) {
				return array();
			}
			$values[ $id ] = 'yes' === $cur;
			return array( $base + array( 'type' => 'toggle' ) );
		case 'select':
		case 'radio':
			$values[ $id ] = is_scalar( $cur ) ? (string) $cur : '';
			return array( $base + array( 'type' => 'select', 'options' => minn_admin_wc_settings_options( isset( $f['options'] ) ? $f['options'] : array() ) ) );
		case 'multiselect':
			$opts          = minn_admin_wc_settings_options( isset( $f['options'] ) ? $f['options'] : array() );
			$values[ $id ] = array_map( 'strval', array_values( (array) $cur ) );
			return array( $base + array( 'type' => 'multicheck', 'options' => $opts ) );
		case 'multi_select_countries':
			$names = function_exists( 'WC' ) && WC()->countries ? WC()->countries->get_countries() : array();
			$picks = array();
			foreach ( (array) $cur as $cc ) {
				$cc      = (string) $cc;
				$picks[] = array(
					'value' => $cc,
					'label' => isset( $names[ $cc ] ) ? html_entity_decode( $names[ $cc ], ENT_QUOTES, 'UTF-8' ) : $cc,
				);
			}
			$values[ $id ] = $picks;
			return array(
				$base + array(
					'type'        => 'relation',
					'route'       => 'minn-admin/v1/wc/lookup?catalog=countries',
					'placeholder' => __( 'Search countries…', 'minn-admin' ),
				),
			);
		case 'single_select_country':
			$values[ $id ] = is_scalar( $cur ) ? (string) $cur : '';
			return array( $base + array( 'type' => 'combobox', 'options' => minn_admin_wc_settings_country_state_options() ) );
		case 'single_select_page':
		case 'single_select_page_with_search':
			$exclude       = isset( $f['args']['exclude'] ) ? (array) $f['args']['exclude'] : array();
			$values[ $id ] = '' === (string) $cur || '0' === (string) $cur ? '' : (string) (int) $cur;
			return array( $base + array( 'type' => 'combobox', 'options' => minn_admin_wc_settings_page_options( $exclude, $cur ) ) );
		case 'relative_date_selector':
			$cur                     = is_array( $cur ) ? $cur : array();
			$num                     = isset( $cur['number'] ) ? (string) $cur['number'] : '';
			$values[ $id ]           = '' === $num ? null : ( is_numeric( $num ) ? $num + 0 : $num );
			$values[ $id . '__unit' ] = isset( $cur['unit'] ) && '' !== (string) $cur['unit'] ? (string) $cur['unit'] : 'months';
			return array(
				$base + array( 'type' => 'number', 'min' => 0 ),
				array(
					'key'     => $id . '__unit',
					/* translators: %s: the retention setting this unit belongs to. */
					'label'   => sprintf( __( '%s: unit', 'minn-admin' ), $label ),
					'type'    => 'select',
					'options' => minn_admin_wc_settings_relative_units(),
				),
			);
		case 'email_image_url':
			$url = is_scalar( $cur ) ? (string) $cur : '';
			$att = '' !== $url ? (int) attachment_url_to_postid( $url ) : 0;
			if ( '' === $url || $att ) {
				$values[ $id ] = $att ? array( 'id' => $att, 'url' => $url ) : null;
				return array( $base + array( 'type' => 'image' ) );
			}
			// An outside URL has no attachment to show; keep it editable as text.
			$values[ $id ] = $url;
			return array( $base + array( 'type' => 'url' ) );
		case 'email_font_family':
			$fonts = class_exists( '\Automattic\WooCommerce\Internal\Email\EmailFont' )
				? array_keys( (array) \Automattic\WooCommerce\Internal\Email\EmailFont::$font )
				: array( 'Helvetica' );
			$values[ $id ] = is_scalar( $cur ) ? (string) $cur : '';
			return array(
				$base + array(
					'type'    => 'select',
					'options' => array_map(
						static function ( $n ) {
							return array( $n, $n );
						},
						$fonts
					),
				),
			);
	}
	return null;
}

/**
 * Minn's settings-contract payload for one page section.
 *
 * @param WC_Settings_Page $page    Page.
 * @param string           $section Section id.
 * @return array { groups, values, adminUrl, page, section }
 */
function minn_admin_wc_settings_schema( $page, $section ) {
	$values = array();
	$groups = array();
	$group  = null;
	$open   = static function ( $title, $desc ) {
		return array(
			'title'        => $title,
			'desc'         => $desc,
			'fields'       => array(),
			'locked'       => 0,
			'lockedLabels' => array(),
		);
	};
	$close  = static function () use ( &$group, &$groups ) {
		if ( $group && ( $group['fields'] || $group['locked'] ) ) {
			if ( ! $group['lockedLabels'] ) {
				unset( $group['lockedLabels'] );
			}
			if ( '' === $group['desc'] ) {
				unset( $group['desc'] );
			}
			$groups[] = $group;
		}
		$group = null;
	};
	foreach ( minn_admin_wc_settings_raw_fields( $page, $section ) as $f ) {
		$type = isset( $f['type'] ) ? (string) $f['type'] : '';
		if ( 'title' === $type ) {
			$close();
			$group = $open(
				minn_admin_wc_settings_text( isset( $f['title'] ) ? $f['title'] : '' ),
				minn_admin_wc_settings_text( isset( $f['desc'] ) ? $f['desc'] : '' )
			);
			continue;
		}
		if ( 'sectionend' === $type ) {
			$close();
			continue;
		}
		if ( in_array( $type, MINN_ADMIN_WC_UI_TYPES, true ) ) {
			continue;
		}
		if ( ! $group ) {
			$group = $open( '', '' );
		}
		$mapped = minn_admin_wc_settings_map_field( $f, $values );
		if ( null === $mapped ) {
			$group['locked']++;
			$label = minn_admin_wc_settings_text( isset( $f['title'] ) ? $f['title'] : ( isset( $f['name'] ) ? $f['name'] : '' ) );
			if ( '' !== $label ) {
				$group['lockedLabels'][] = $label;
			}
			continue;
		}
		foreach ( $mapped as $mf ) {
			$group['fields'][] = $mf;
		}
	}
	$close();
	$page_id = $page->get_id();
	return array(
		'page'     => $page_id,
		'section'  => $section,
		'groups'   => $groups,
		'values'   => $values,
		'adminUrl' => admin_url( 'admin.php?page=wc-settings&tab=' . rawurlencode( $page_id ) . ( '' !== $section ? '&section=' . rawurlencode( $section ) : '' ) ),
	);
}

/**
 * The section list for the Store settings page: every (page, section) with
 * at least one field Minn can draw. Sections made only of chrome (the
 * Payments page, tax-rate tables, shipping zones, API keys, webhooks,
 * Blueprint, Site visibility) drop out here; other phases own them.
 *
 * @return array List of { id, page, section, label, pageLabel, count, locked }.
 */
function minn_admin_wc_settings_sections() {
	$out = array();
	foreach ( minn_admin_wc_settings_pages() as $page_id => $page ) {
		$page_label = minn_admin_wc_settings_text( $page->get_label() );
		try {
			$sections = (array) $page->get_sections();
		} catch ( Throwable $e ) {
			$sections = array();
		}
		if ( ! $sections ) {
			$sections = array( '' => $page_label );
		}
		foreach ( $sections as $sid => $slabel ) {
			$sid    = (string) $sid;
			$schema = minn_admin_wc_settings_schema( $page, $sid );
			$count  = 0;
			$locked = 0;
			foreach ( $schema['groups'] as $g ) {
				$count  += count( $g['fields'] );
				$locked += (int) $g['locked'];
			}
			if ( ! $count ) {
				continue;
			}
			$out[] = array(
				'id'        => $page_id . ( '' !== $sid ? ':' . $sid : '' ),
				'page'      => $page_id,
				'section'   => $sid,
				'label'     => minn_admin_wc_settings_text( is_string( $slabel ) ? $slabel : $page_label ),
				'pageLabel' => $page_label,
				'count'     => $count,
				'locked'    => $locked,
			);
		}
	}
	return $out;
}

/**
 * Turn Minn's edited values into what the wp-admin form would have posted
 * for the fields it knows, overlaying the current values for the rest so a
 * checkbox WooCommerce reads as "absent = no" is never flipped by a save that
 * did not touch it.
 *
 * @param array $fields Raw WC fields for the section.
 * @param array $edited Values keyed by Minn field key (only the edited ones).
 * @return array|WP_Error Unslashed post data keyed by option id.
 */
function minn_admin_wc_settings_post_data( $fields, $edited ) {
	$post = array();
	foreach ( $fields as $f ) {
		$type = isset( $f['type'] ) ? (string) $f['type'] : '';
		$id   = isset( $f['id'] ) ? (string) $f['id'] : '';
		if ( '' === $id || '' === $type || in_array( $type, MINN_ADMIN_WC_UI_TYPES, true ) || ! empty( $f['disabled'] ) ) {
			continue;
		}
		$probe = array();
		if ( null === minn_admin_wc_settings_map_field( $f, $probe ) ) {
			continue; // locked: never posted, so never touched
		}
		$touched = array_key_exists( $id, $edited ) || array_key_exists( $id . '__unit', $edited );
		if ( isset( $f['is_option'] ) && false === $f['is_option'] && ! $touched ) {
			continue; // tax classes: their save runs only on an explicit change
		}
		$cur = minn_admin_wc_settings_current( $f );
		switch ( $type ) {
			case 'checkbox':
			case 'hidden':
				if ( 'hidden' === $type && 'woocommerce_email_auto_sync_with_theme' !== $id ) {
					break;
				}
				$on = $touched ? ! empty( $edited[ $id ] ) : ( 'yes' === $cur || true === $cur || '1' === $cur );
				// wp-admin sends only checked boxes; an absent key saves as "no".
				if ( $on ) {
					$post[ $id ] = '1';
				}
				break;
			case 'multiselect':
			case 'multi_select_countries':
				$vals = $touched ? (array) $edited[ $id ] : (array) $cur;
				$flat = array();
				foreach ( $vals as $v ) {
					$flat[] = is_array( $v ) && isset( $v['value'] ) ? (string) $v['value'] : (string) $v;
				}
				$post[ $id ] = array_values( array_filter( $flat, 'strlen' ) );
				break;
			case 'relative_date_selector':
				$cur = is_array( $cur ) ? $cur : array();
				$num = array_key_exists( $id, $edited ) ? $edited[ $id ] : ( isset( $cur['number'] ) ? $cur['number'] : '' );
				$post[ $id ] = array(
					'number' => null === $num ? '' : (string) $num,
					'unit'   => array_key_exists( $id . '__unit', $edited ) ? (string) $edited[ $id . '__unit' ] : ( isset( $cur['unit'] ) ? (string) $cur['unit'] : 'months' ),
				);
				break;
			case 'email_image_url':
				if ( $touched ) {
					$v           = $edited[ $id ];
					$post[ $id ] = is_array( $v ) ? ( isset( $v['url'] ) ? (string) $v['url'] : '' ) : ( null === $v ? '' : (string) $v );
				} else {
					$post[ $id ] = is_scalar( $cur ) ? (string) $cur : '';
				}
				break;
			case 'password':
				if ( $touched && '****************' !== (string) $edited[ $id ] ) {
					$post[ $id ] = (string) $edited[ $id ];
				} else {
					$post[ $id ] = is_scalar( $cur ) ? (string) $cur : '';
				}
				break;
			case 'number':
				$v           = $touched ? $edited[ $id ] : $cur;
				$post[ $id ] = null === $v ? '' : (string) $v;
				break;
			default:
				$v           = $touched ? $edited[ $id ] : $cur;
				$post[ $id ] = is_scalar( $v ) ? (string) $v : ( null === $v ? '' : $v );
		}
	}
	return $post;
}

/**
 * Run WooCommerce's own settings save for one page section against a
 * prepared post-data array: the same actions wp-admin fires, in the same
 * order, minus the nonce, the admin notice and the redirect.
 *
 * @param WC_Settings_Page $page    Page.
 * @param string           $section Section id.
 * @param array            $post    Unslashed post data keyed by option id.
 */
function minn_admin_wc_settings_run_save( $page, $section, $post ) {
	global $current_tab, $current_section;
	$tab            = $page->get_id();
	$prev_post      = $_POST;
	$prev_tab       = $current_tab;
	$prev_section   = $current_section;
	$current_tab    = $tab;
	$current_section = $section;
	$_POST          = wp_slash( $post );
	try {
		do_action( 'woocommerce_settings_save_' . $tab );
		do_action( 'woocommerce_update_options_' . $tab );
		do_action( 'woocommerce_update_options' );
		if ( method_exists( 'WC_Admin_Settings', 'check_download_folder_protection' ) ) {
			WC_Admin_Settings::check_download_folder_protection();
		}
		update_option( 'woocommerce_queue_flush_rewrite_rules', 'yes' );
		if ( WC()->query ) {
			WC()->query->init_query_vars();
			WC()->query->add_endpoints();
		}
		do_action( 'woocommerce_settings_saved' );
	} finally {
		$_POST           = $prev_post;
		$current_tab     = $prev_tab;
		$current_section = $prev_section;
	}
}

/**
 * Errors WooCommerce queued through WC_Admin_Settings::add_error() during a
 * save. The queue is private; show_messages() is its only reader, so it is
 * rendered into a buffer and read back as text.
 *
 * @return string[]
 */
function minn_admin_wc_settings_queued_errors() {
	if ( ! class_exists( 'WC_Admin_Settings' ) ) {
		return array();
	}
	ob_start();
	WC_Admin_Settings::show_messages();
	$html = (string) ob_get_clean();
	if ( false === strpos( $html, 'class="error' ) ) {
		return array();
	}
	preg_match_all( '#<div id="message" class="error[^"]*"><p><strong>(.*?)</strong></p></div>#s', $html, $m );
	return array_values( array_filter( array_map( 'minn_admin_wc_settings_text', $m[1] ) ) );
}

/**
 * The emails list: one row per WC_Email the mailer knows, with what the
 * WooCommerce table shows (recipient, type, on/off, manual).
 *
 * @return array
 */
function minn_admin_wc_settings_emails() {
	$rows = array();
	if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
		return $rows;
	}
	foreach ( WC()->mailer()->get_emails() as $key => $email ) {
		if ( ! $email instanceof WC_Email ) {
			continue;
		}
		$manual    = method_exists( $email, 'is_manual' ) && $email->is_manual();
		$customer  = method_exists( $email, 'is_customer_email' ) && $email->is_customer_email();
		$recipient = $customer ? __( 'Customer', 'minn-admin' ) : minn_admin_wc_settings_text( (string) $email->get_recipient() );
		$rows[]    = array(
			'id'          => $email->id,
			'title'       => minn_admin_wc_settings_text( $email->get_title() ),
			'description' => minn_admin_wc_settings_text( $email->get_description() ),
			'recipient'   => $recipient,
			'customer'    => $customer,
			'enabled'     => (bool) $email->is_enabled(),
			'manual'      => $manual,
			'type'        => minn_admin_wc_settings_text( (string) $email->get_email_type() ),
			'class'       => (string) $key,
		);
	}
	return $rows;
}

/**
 * Find one email by its id.
 *
 * @param string $id Email id (WC_Email::$id).
 * @return WC_Email|null
 */
function minn_admin_wc_settings_email( $id ) {
	if ( ! function_exists( 'WC' ) || ! WC()->mailer() ) {
		return null;
	}
	foreach ( WC()->mailer()->get_emails() as $email ) {
		if ( $email instanceof WC_Email && $email->id === $id ) {
			return $email;
		}
	}
	return null;
}

/**
 * One email's settings form from its WC_Settings_API form fields. Same
 * vocabulary as the pages, slightly different keys (`description` for the
 * help line, values read through `$email->get_option`).
 *
 * @param WC_Email $email Email.
 * @return array { groups, values, adminUrl, email }
 */
function minn_admin_wc_settings_email_schema( $email ) {
	$values = array();
	$groups = array();
	$group  = array( 'title' => '', 'fields' => array(), 'locked' => 0, 'lockedLabels' => array() );
	$flush  = static function () use ( &$group, &$groups ) {
		if ( $group['fields'] || $group['locked'] ) {
			if ( ! $group['lockedLabels'] ) {
				unset( $group['lockedLabels'] );
			}
			$groups[] = $group;
		}
		$group = array( 'title' => '', 'fields' => array(), 'locked' => 0, 'lockedLabels' => array() );
	};
	foreach ( (array) $email->get_form_fields() as $key => $f ) {
		if ( ! is_array( $f ) ) {
			continue;
		}
		$type = isset( $f['type'] ) ? (string) $f['type'] : 'text';
		if ( 'title' === $type ) {
			$flush();
			$group['title'] = minn_admin_wc_settings_text( isset( $f['title'] ) ? $f['title'] : '' );
			continue;
		}
		// WC_Settings_API spells the help line `description`; the page mapper
		// reads `desc`, so translate the field before handing it over.
		$wf = $f;
		$wf['id']   = (string) $key;
		$wf['type'] = $type;
		if ( isset( $f['description'] ) && ! isset( $wf['desc'] ) ) {
			$wf['desc'] = $f['description'];
		}
		if ( ! isset( $wf['desc_tip'] ) ) {
			$wf['desc_tip'] = true;
		}
		$wf['value'] = $email->get_option( $key, isset( $f['default'] ) ? $f['default'] : '' );
		unset( $wf['is_option'] );
		$mapped = minn_admin_wc_settings_map_field( $wf, $values );
		if ( null === $mapped ) {
			$group['locked']++;
			$label = minn_admin_wc_settings_text( isset( $f['title'] ) ? $f['title'] : '' );
			if ( '' !== $label ) {
				$group['lockedLabels'][] = $label;
			}
			continue;
		}
		foreach ( $mapped as $mf ) {
			$group['fields'][] = $mf;
		}
	}
	$flush();
	return array(
		'email'    => $email->id,
		'title'    => minn_admin_wc_settings_text( $email->get_title() ),
		'groups'   => $groups,
		'values'   => $values,
		'adminUrl' => admin_url( 'admin.php?page=wc-settings&tab=email&section=' . rawurlencode( sanitize_title( $email->id ) ) ),
	);
}

/**
 * Save one email's settings through WC_Settings_API: the post data is the
 * current values overlaid with the edits (keys prefixed the way the email's
 * own form posts them), and the email's own update-options action does the
 * validation and the write.
 *
 * @param WC_Email $email  Email.
 * @param array    $edited Edited values keyed by field key.
 */
function minn_admin_wc_settings_email_save( $email, $edited ) {
	$post = array();
	foreach ( (array) $email->get_form_fields() as $key => $f ) {
		if ( ! is_array( $f ) ) {
			continue;
		}
		$type = isset( $f['type'] ) ? (string) $f['type'] : 'text';
		if ( 'title' === $type ) {
			continue;
		}
		$wf          = $f;
		$wf['id']    = (string) $key;
		$wf['type']  = $type;
		$wf['value'] = $email->get_option( $key, isset( $f['default'] ) ? $f['default'] : '' );
		$one         = minn_admin_wc_settings_post_data( array( $wf ), $edited );
		foreach ( $one as $k => $v ) {
			$post[ $email->get_field_key( $k ) ] = $v;
		}
	}
	$email->set_post_data( wp_slash( $post ) );
	do_action( 'woocommerce_update_options_email_' . $email->id );
	$email->set_post_data( array() );
	do_action( 'woocommerce_settings_saved' );
}

/**
 * Lookup catalogs behind relation fields.
 *
 * @param string $catalog Catalog id.
 * @param string $q       Query.
 * @return array [ { value, label } ]
 */
function minn_admin_wc_settings_lookup( $catalog, $q ) {
	$q   = mb_strtolower( trim( (string) $q ) );
	$out = array();
	if ( 'countries' === $catalog && function_exists( 'WC' ) && WC()->countries ) {
		foreach ( WC()->countries->get_countries() as $cc => $name ) {
			$name = html_entity_decode( $name, ENT_QUOTES, 'UTF-8' );
			if ( '' !== $q && false === mb_strpos( mb_strtolower( $name ), $q ) && false === mb_strpos( mb_strtolower( $cc ), $q ) ) {
				continue;
			}
			$out[] = array( 'value' => (string) $cc, 'label' => $name );
			if ( count( $out ) >= 30 ) {
				break;
			}
		}
	}
	return $out;
}

add_action(
	'rest_api_init',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}
		$can = static function () {
			return current_user_can( 'manage_woocommerce' );
		};
		$resolve = static function ( $req ) {
			$pages   = minn_admin_wc_settings_pages();
			$page_id = sanitize_key( $req['page'] );
			$section = 'default' === $req['section'] ? '' : sanitize_key( $req['section'] );
			if ( ! isset( $pages[ $page_id ] ) ) {
				return new WP_Error( 'minn_wc_no_page', __( 'That settings page is not registered.', 'minn-admin' ), array( 'status' => 404 ) );
			}
			$page     = $pages[ $page_id ];
			$sections = (array) $page->get_sections();
			if ( '' !== $section && ! array_key_exists( $section, $sections ) ) {
				return new WP_Error( 'minn_wc_no_section', __( 'That settings section is not registered.', 'minn-admin' ), array( 'status' => 404 ) );
			}
			return array( $page, $section );
		};

		register_rest_route(
			'minn-admin/v1',
			'/wc/settings',
			array(
				'methods'             => 'GET',
				'permission_callback' => $can,
				'callback'            => static function () {
					return array(
						'sections' => minn_admin_wc_settings_sections(),
						'adminUrl' => admin_url( 'admin.php?page=wc-settings' ),
					);
				},
			)
		);

		register_rest_route(
			'minn-admin/v1',
			'/wc/settings/(?P<page>[\w-]+)/(?P<section>[\w-]+)',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $can,
					'callback'            => static function ( $req ) use ( $resolve ) {
						$r = $resolve( $req );
						if ( is_wp_error( $r ) ) {
							return $r;
						}
						return minn_admin_wc_settings_schema( $r[0], $r[1] );
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $can,
					'callback'            => static function ( $req ) use ( $resolve ) {
						$r = $resolve( $req );
						if ( is_wp_error( $r ) ) {
							return $r;
						}
						list( $page, $section ) = $r;
						$body   = $req->get_json_params();
						$edited = isset( $body['values'] ) && is_array( $body['values'] ) ? $body['values'] : array();
						if ( ! $edited ) {
							return new WP_Error( 'minn_wc_nothing', __( 'Nothing to save.', 'minn-admin' ), array( 'status' => 400 ) );
						}
						$fields = minn_admin_wc_settings_raw_fields( $page, $section );
						$post   = minn_admin_wc_settings_post_data( $fields, $edited );
						if ( is_wp_error( $post ) ) {
							return $post;
						}
						try {
							minn_admin_wc_settings_run_save( $page, $section, $post );
						} catch ( Throwable $e ) {
							return new WP_Error( 'minn_wc_save_failed', $e->getMessage(), array( 'status' => 500 ) );
						}
						// Errors WooCommerce queued during the save (its own
						// validation) ride back beside the re-read values, so the
						// form shows what actually stuck and says why.
						$schema           = minn_admin_wc_settings_schema( $page, $section );
						$schema['errors'] = minn_admin_wc_settings_queued_errors();
						return $schema;
					},
				),
			)
		);

		register_rest_route(
			'minn-admin/v1',
			'/wc/emails',
			array(
				'methods'             => 'GET',
				'permission_callback' => $can,
				'callback'            => static function () {
					return array( 'emails' => minn_admin_wc_settings_emails() );
				},
			)
		);

		register_rest_route(
			'minn-admin/v1',
			'/wc/emails/(?P<id>[\w-]+)',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $can,
					'callback'            => static function ( $req ) {
						$email = minn_admin_wc_settings_email( (string) $req['id'] );
						if ( ! $email ) {
							return new WP_Error( 'minn_wc_no_email', __( 'That email is not registered.', 'minn-admin' ), array( 'status' => 404 ) );
						}
						return minn_admin_wc_settings_email_schema( $email );
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $can,
					'callback'            => static function ( $req ) {
						$email = minn_admin_wc_settings_email( (string) $req['id'] );
						if ( ! $email ) {
							return new WP_Error( 'minn_wc_no_email', __( 'That email is not registered.', 'minn-admin' ), array( 'status' => 404 ) );
						}
						$body   = $req->get_json_params();
						$edited = isset( $body['values'] ) && is_array( $body['values'] ) ? $body['values'] : array();
						if ( ! $edited ) {
							return new WP_Error( 'minn_wc_nothing', __( 'Nothing to save.', 'minn-admin' ), array( 'status' => 400 ) );
						}
						try {
							minn_admin_wc_settings_email_save( $email, $edited );
						} catch ( Throwable $e ) {
							return new WP_Error( 'minn_wc_save_failed', $e->getMessage(), array( 'status' => 500 ) );
						}
						// Re-read through a fresh instance: the email caches its
						// settings array at construction.
						$email->init_settings();
						return minn_admin_wc_settings_email_schema( $email );
					},
				),
			)
		);

		register_rest_route(
			'minn-admin/v1',
			'/wc/lookup',
			array(
				'methods'             => 'GET',
				'permission_callback' => $can,
				'callback'            => static function ( $req ) {
					return minn_admin_wc_settings_lookup( sanitize_key( $req['catalog'] ), (string) $req['q'] );
				},
			)
		);
	}
);
