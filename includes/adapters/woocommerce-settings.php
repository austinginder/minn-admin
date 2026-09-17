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
		// One level of optgroups (Cash on delivery's shipping methods are
		// grouped by method): the group name prefixes each choice.
		if ( is_array( $l ) ) {
			foreach ( $l as $gv => $gl ) {
				$out[] = array( (string) $gv, minn_admin_wc_settings_text( (string) $v ) . ' · ' . minn_admin_wc_settings_text( is_string( $gl ) ? $gl : (string) $gv ) );
			}
			continue;
		}
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
	if ( isset( $f['value'] ) && ( ! isset( $f['id'] ) || ! empty( $f['minn_object'] ) ) ) {
		// A WC_Settings_API field (email, gateway, shipping instance) keeps
		// its value on the object, never in wp_options under the bare key;
		// the builders read it through the object and mark the field so it
		// is never resolved against a global option of the same name.
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
		// The Payments page is a React screen with no field array; Minn draws
		// it as a gateway list from the registry instead (kind 'payments').
		if ( 'checkout' === $page_id ) {
			$out[] = array(
				'id'        => 'checkout',
				'page'      => 'checkout',
				'section'   => '',
				'kind'      => 'payments',
				'label'     => $page_label,
				'pageLabel' => $page_label,
				'count'     => count( minn_admin_wc_settings_gateways() ),
				'locked'    => 0,
			);
			continue;
		}
		foreach ( $sections as $sid => $slabel ) {
			$sid = (string) $sid;
			// Webhooks and REST API keys are tables under Advanced.
			if ( 'advanced' === $page_id && in_array( $sid, array( 'keys', 'webhooks' ), true ) ) {
				$out[] = array(
					'id'        => 'advanced:' . $sid,
					'page'      => 'advanced',
					'section'   => $sid,
					'kind'      => 'keys' === $sid ? 'api-keys' : 'webhooks',
					'label'     => minn_admin_wc_settings_text( is_string( $slabel ) ? $slabel : $sid ),
					'pageLabel' => $page_label,
					'count'     => 0,
					'locked'    => 0,
				);
				continue;
			}
			// Tax rates are one table per class (the sections WooCommerce
			// registers beside its options), drawn by Minn over wc/v3/taxes.
			if ( 'tax' === $page_id && '' !== $sid ) {
				$out[] = array(
					'id'        => 'tax:' . $sid,
					'page'      => 'tax',
					'section'   => $sid,
					'kind'      => 'tax-rates',
					'label'     => minn_admin_wc_settings_text( is_string( $slabel ) ? $slabel : $sid ),
					'pageLabel' => $page_label,
					'count'     => 0,
					'locked'    => 0,
				);
				continue;
			}
			// Shipping zones and classes are tables, not field arrays: Minn
			// draws them itself, at the positions WooCommerce gives them.
			if ( 'shipping' === $page_id && in_array( $sid, array( '', 'classes' ), true ) ) {
				$out[] = array(
					'id'        => 'shipping' . ( '' !== $sid ? ':' . $sid : '' ),
					'page'      => 'shipping',
					'section'   => $sid,
					'kind'      => '' === $sid ? 'shipping-zones' : 'shipping-classes',
					'label'     => minn_admin_wc_settings_text( is_string( $slabel ) ? $slabel : $page_label ),
					'pageLabel' => $page_label,
					'count'     => 0,
					'locked'    => 0,
				);
				continue;
			}
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
 * One email's settings form (WC_Settings_API).
 *
 * @param WC_Email $email Email.
 * @return array { email, title, groups, values, adminUrl }
 */
function minn_admin_wc_settings_email_schema( $email ) {
	return array(
		'email' => $email->id,
		'title' => minn_admin_wc_settings_text( $email->get_title() ),
	) + minn_admin_wc_settings_api_schema( $email, admin_url( 'admin.php?page=wc-settings&tab=email&section=' . rawurlencode( sanitize_title( $email->id ) ) ) );
}

/**
 * Save one email's settings: the email's own update-options action runs its
 * process_admin_options over the prepared post data.
 *
 * @param WC_Email $email  Email.
 * @param array    $edited Edited values keyed by field key.
 */
function minn_admin_wc_settings_email_save( $email, $edited ) {
	$email->set_post_data( minn_admin_wc_settings_api_post_data( $email, $edited ) );
	do_action( 'woocommerce_update_options_email_' . $email->id );
	$email->set_post_data( array() );
	do_action( 'woocommerce_settings_saved' );
}

/**
 * Payment gateways in the store's own display order (WC_Payment_Gateways
 * sorts by the woocommerce_gateway_order option), with what the Payments
 * screen shows per row and where its full settings live. A gateway whose
 * settings screen is React-only (WooPayments, Stripe) still lists, with its
 * own settings URL as the way in; its WC_Settings_API fields, when it has
 * them, edit here too.
 *
 * @return array
 */
function minn_admin_wc_settings_gateways() {
	$rows = array();
	if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
		return $rows;
	}
	foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
		if ( ! $gateway instanceof WC_Payment_Gateway ) {
			continue;
		}
		$fields = array();
		try {
			$fields = (array) $gateway->get_form_fields();
		} catch ( Throwable $e ) {
			$fields = array();
		}
		$editable = 0;
		foreach ( $fields as $k => $f ) {
			if ( is_array( $f ) && 'title' !== ( isset( $f['type'] ) ? $f['type'] : 'text' ) ) {
				$editable++;
			}
		}
		$rows[] = array(
			'id'          => $gateway->id,
			'title'       => minn_admin_wc_settings_text( $gateway->get_method_title() ),
			'label'       => minn_admin_wc_settings_text( $gateway->get_title() ),
			'description' => minn_admin_wc_settings_text( $gateway->get_method_description() ),
			'enabled'     => 'yes' === $gateway->enabled,
			'needsSetup'  => (bool) $gateway->needs_setup(),
			'fields'      => $editable,
			'settingsUrl' => minn_admin_wc_settings_gateway_url( $gateway ),
		);
	}
	return $rows;
}

/**
 * Where WooCommerce itself sends a shop owner for this gateway's settings:
 * the gateway's own URL when it names one (React screens do), else its
 * section on the Payments tab.
 *
 * @param WC_Payment_Gateway $gateway Gateway.
 * @return string
 */
function minn_admin_wc_settings_gateway_url( $gateway ) {
	$url = '';
	try {
		if ( method_exists( $gateway, 'get_settings_url' ) && is_callable( array( $gateway, 'get_settings_url' ) ) ) {
			$url = trim( (string) $gateway->get_settings_url() );
			if ( '' !== $url && 0 === strpos( $url, 'admin.php' ) ) {
				$url = admin_url( $url );
			}
		}
	} catch ( Throwable $e ) {
		$url = '';
	}
	if ( '' === $url || ! wc_is_valid_url( $url ) ) {
		$url = admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . rawurlencode( strtolower( $gateway->id ) ) );
	}
	return $url;
}

/**
 * Find one gateway by id.
 *
 * @param string $id Gateway id.
 * @return WC_Payment_Gateway|null
 */
function minn_admin_wc_settings_gateway( $id ) {
	if ( ! function_exists( 'WC' ) || ! WC()->payment_gateways() ) {
		return null;
	}
	foreach ( WC()->payment_gateways()->payment_gateways() as $gateway ) {
		if ( $gateway instanceof WC_Payment_Gateway && $gateway->id === $id ) {
			return $gateway;
		}
	}
	return null;
}

/**
 * One WC_Settings_API object's form (gateways and emails share the API):
 * the schema from its `form_fields`, values through `get_option`.
 *
 * @param WC_Settings_API $obj      Gateway or email.
 * @param string          $adminUrl Escape link.
 * @return array { groups, values, adminUrl }
 */
function minn_admin_wc_settings_api_schema( $obj, $admin_url, $fields = null, $read = null ) {
	$fields = null === $fields ? (array) $obj->get_form_fields() : (array) $fields;
	$read   = null === $read ? array( $obj, 'get_option' ) : $read;
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
	foreach ( $fields as $key => $f ) {
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
		$wf         = $f;
		$wf['id']   = (string) $key;
		$wf['type'] = $type;
		if ( isset( $f['description'] ) && ! isset( $wf['desc'] ) ) {
			$wf['desc'] = $f['description'];
		}
		if ( ! isset( $wf['desc_tip'] ) ) {
			$wf['desc_tip'] = true;
		}
		$wf['value']       = call_user_func( $read, $key, isset( $f['default'] ) ? $f['default'] : '' );
		$wf['minn_object'] = true;
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
		'groups'   => $groups,
		'values'   => $values,
		'adminUrl' => $admin_url,
	);
}

/**
 * Post data for a WC_Settings_API object: current values overlaid with the
 * edits, keys prefixed the way its own form posts them.
 *
 * @param WC_Settings_API $obj    Gateway or email.
 * @param array           $edited Edited values keyed by field key.
 * @return array Slashed post data.
 */
function minn_admin_wc_settings_api_post_data( $obj, $edited, $fields = null, $read = null ) {
	$fields = null === $fields ? (array) $obj->get_form_fields() : (array) $fields;
	$read   = null === $read ? array( $obj, 'get_option' ) : $read;
	$post   = array();
	foreach ( $fields as $key => $f ) {
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
		$wf['value']       = call_user_func( $read, $key, isset( $f['default'] ) ? $f['default'] : '' );
		$wf['minn_object'] = true;
		unset( $wf['is_option'] );
		$one               = minn_admin_wc_settings_post_data( array( $wf ), $edited );
		foreach ( $one as $k => $v ) {
			$post[ $obj->get_field_key( $k ) ] = $v;
		}
	}
	return wp_slash( $post );
}

/* ===== Shipping zones, methods and classes ===== */

/**
 * A zone location as the client shows it: type, code and a human label
 * (the same catalogs WooCommerce's own zone screen resolves through).
 *
 * @param string $type Location type.
 * @param string $code Code (state codes are CC:ST).
 * @return array { type, code, label }
 */
function minn_admin_wc_shipping_location_label( $type, $code ) {
	$countries = WC()->countries;
	$label     = (string) $code;
	if ( 'continent' === $type ) {
		$c     = $countries->get_continents();
		$label = isset( $c[ $code ]['name'] ) ? $c[ $code ]['name'] : $code;
	} elseif ( 'country' === $type ) {
		$c     = $countries->get_countries();
		$label = isset( $c[ $code ] ) ? $c[ $code ] : $code;
	} elseif ( 'state' === $type ) {
		$parts = explode( ':', (string) $code );
		$c     = $countries->get_countries();
		$st    = count( $parts ) === 2 ? $countries->get_states( $parts[0] ) : array();
		$label = ( isset( $st[ $parts[1] ] ) ? $st[ $parts[1] ] : $code ) . ', ' . ( isset( $c[ $parts[0] ] ) ? $c[ $parts[0] ] : $parts[0] );
	}
	return array(
		'type'  => $type,
		'code'  => (string) $code,
		'label' => html_entity_decode( $label, ENT_QUOTES, 'UTF-8' ),
	);
}

/**
 * One shipping method instance as a row.
 *
 * @param WC_Shipping_Method $method Instance-bound method.
 * @return array
 */
function minn_admin_wc_shipping_method_row( $method ) {
	$editable = 0;
	try {
		foreach ( (array) $method->get_instance_form_fields() as $f ) {
			if ( is_array( $f ) && 'title' !== ( isset( $f['type'] ) ? $f['type'] : 'text' ) ) {
				$editable++;
			}
		}
	} catch ( Throwable $e ) {
		$editable = 0;
	}
	return array(
		'instance'    => (int) $method->get_instance_id(),
		'method'      => (string) $method->id,
		'title'       => minn_admin_wc_settings_text( $method->get_title() ),
		'methodTitle' => minn_admin_wc_settings_text( $method->get_method_title() ),
		'enabled'     => (bool) $method->is_enabled(),
		'order'       => isset( $method->method_order ) ? (int) $method->method_order : 0,
		'fields'      => $editable,
	);
}

/**
 * One zone as a row: name, regions (as pickable items + a postcode list),
 * methods in order.
 *
 * @param WC_Shipping_Zone $zone Zone.
 * @return array
 */
function minn_admin_wc_shipping_zone_row( $zone ) {
	$regions   = array();
	$postcodes = array();
	foreach ( (array) $zone->get_zone_locations( 'edit' ) as $loc ) {
		if ( 'postcode' === $loc->type ) {
			$postcodes[] = (string) $loc->code;
			continue;
		}
		$l         = minn_admin_wc_shipping_location_label( $loc->type, $loc->code );
		$regions[] = array(
			'value' => $l['type'] . ':' . $l['code'],
			'label' => $l['label'],
		);
	}
	$methods = array();
	foreach ( (array) $zone->get_shipping_methods( false, 'admin' ) as $m ) {
		$methods[] = minn_admin_wc_shipping_method_row( $m );
	}
	usort(
		$methods,
		static function ( $a, $b ) {
			return $a['order'] <=> $b['order'] ?: $a['instance'] <=> $b['instance'];
		}
	);
	return array(
		'id'        => (int) $zone->get_id(),
		'name'      => minn_admin_wc_settings_text( $zone->get_zone_name( 'edit' ) ),
		'order'     => (int) $zone->get_zone_order( 'edit' ),
		'summary'   => minn_admin_wc_settings_text( $zone->get_formatted_location( 6, 'edit' ) ),
		'regions'   => $regions,
		'postcodes' => implode( "\n", $postcodes ),
		'methods'   => $methods,
	);
}

/**
 * The whole shipping picture: zones in match order, the "everywhere else"
 * zone, the methods a zone can add, and the classes.
 *
 * @return array
 */
function minn_admin_wc_shipping_payload() {
	$zones = array();
	foreach ( WC_Shipping_Zones::get_shipping_zones() as $zone ) {
		$zones[] = minn_admin_wc_shipping_zone_row( $zone );
	}
	usort(
		$zones,
		static function ( $a, $b ) {
			return $a['order'] <=> $b['order'] ?: $a['id'] <=> $b['id'];
		}
	);
	$available = array();
	foreach ( (array) WC()->shipping()->get_shipping_methods() as $m ) {
		if ( ! $m->supports( 'shipping-zones' ) ) {
			continue;
		}
		$available[] = array(
			'id'          => (string) $m->id,
			'title'       => minn_admin_wc_settings_text( $m->get_method_title() ),
			'description' => minn_admin_wc_settings_text( $m->get_method_description() ),
		);
	}
	$classes = array();
	foreach ( (array) WC()->shipping()->get_shipping_classes() as $term ) {
		$classes[] = array(
			'id'          => (int) $term->term_id,
			'name'        => (string) $term->name,
			'slug'        => (string) $term->slug,
			'description' => (string) $term->description,
			'count'       => (int) $term->count,
		);
	}
	return array(
		'zones'     => $zones,
		'rest'      => minn_admin_wc_shipping_zone_row( new WC_Shipping_Zone( 0 ) ),
		'available' => $available,
		'classes'   => $classes,
		'adminUrl'  => admin_url( 'admin.php?page=wc-settings&tab=shipping' ),
	);
}

/**
 * Apply name / regions / postcodes to a zone the way the zones screen's
 * ajax save does (wc_clean, then clear + add per type), and save.
 *
 * @param WC_Shipping_Zone $zone Zone.
 * @param array            $body Request body.
 * @return true|WP_Error
 */
function minn_admin_wc_shipping_zone_apply( $zone, $body ) {
	if ( $zone->get_id() && array_key_exists( 'name', $body ) ) {
		$name = wc_clean( (string) $body['name'] );
		if ( '' === trim( $name ) ) {
			return new WP_Error( 'minn_wc_zone_name', __( 'A zone needs a name.', 'minn-admin' ), array( 'status' => 400 ) );
		}
		do_action( 'woocommerce_update_non_option_setting', array( 'id' => 'zone_name' ) );
		$zone->set_zone_name( $name );
	}
	if ( $zone->get_id() && array_key_exists( 'regions', $body ) ) {
		do_action( 'woocommerce_update_non_option_setting', array( 'id' => 'zone_locations' ) );
		$zone->clear_locations( array( 'state', 'country', 'continent' ) );
		foreach ( (array) $body['regions'] as $r ) {
			$v     = is_array( $r ) && isset( $r['value'] ) ? $r['value'] : $r;
			$parts = explode( ':', wc_clean( (string) $v ), 2 );
			if ( 2 !== count( $parts ) || ! $zone->is_valid_location_type( $parts[0] ) ) {
				continue;
			}
			$zone->add_location( $parts[1], $parts[0] );
		}
	}
	if ( $zone->get_id() && array_key_exists( 'postcodes', $body ) ) {
		do_action( 'woocommerce_update_non_option_setting', array( 'id' => 'zone_postcodes' ) );
		$zone->clear_locations( 'postcode' );
		$codes = array_filter( array_map( 'strtoupper', array_map( 'wc_clean', explode( "\n", str_replace( ',', "\n", (string) $body['postcodes'] ) ) ) ) );
		foreach ( $codes as $code ) {
			$zone->add_location( $code, 'postcode' );
		}
	}
	$zone->save();
	WC_Cache_Helper::get_transient_version( 'shipping', true );
	return true;
}

/**
 * Method instance form: schema over the instance fields, values through
 * get_instance_option.
 *
 * @param WC_Shipping_Method $method Instance-bound method.
 * @return array
 */
function minn_admin_wc_shipping_method_schema( $method ) {
	return array(
		'instance' => (int) $method->get_instance_id(),
		'title'    => minn_admin_wc_settings_text( $method->get_method_title() ),
	) + minn_admin_wc_settings_api_schema(
		$method,
		admin_url( 'admin.php?page=wc-settings&tab=shipping&instance_id=' . (int) $method->get_instance_id() ),
		$method->get_instance_form_fields(),
		array( $method, 'get_instance_option' )
	);
}

/**
 * Regions a zone can cover (continents, the countries the store ships to,
 * their states), for the picker.
 *
 * @param string $q Query.
 * @return array
 */
function minn_admin_wc_shipping_regions_lookup( $q ) {
	$q   = mb_strtolower( trim( (string) $q ) );
	$out = array();
	$hit = static function ( $label ) use ( $q ) {
		return '' === $q || false !== mb_strpos( mb_strtolower( $label ), $q );
	};
	$countries = WC()->countries;
	foreach ( (array) $countries->get_continents() as $code => $c ) {
		$label = html_entity_decode( $c['name'], ENT_QUOTES, 'UTF-8' );
		if ( $hit( $label ) ) {
			$out[] = array( 'value' => 'continent:' . $code, 'label' => $label );
		}
	}
	foreach ( (array) $countries->get_shipping_countries() as $code => $name ) {
		$name = html_entity_decode( $name, ENT_QUOTES, 'UTF-8' );
		if ( $hit( $name . ' ' . $code ) ) {
			$out[] = array( 'value' => 'country:' . $code, 'label' => $name . ' (' . $code . ')' );
		}
		if ( '' === $q ) {
			continue; // states only surface for a query; a blank list stays short
		}
		foreach ( (array) $countries->get_states( $code ) as $sc => $sname ) {
			$sname = html_entity_decode( $sname, ENT_QUOTES, 'UTF-8' );
			if ( $hit( $sname ) ) {
				$out[] = array( 'value' => 'state:' . $code . ':' . $sc, 'label' => $sname . ', ' . $name );
			}
		}
		if ( count( $out ) > 200 ) {
			break;
		}
	}
	return array_slice( $out, 0, 40 );
}

/* ===== REST API keys (no WooCommerce REST of their own) ===== */

/**
 * API key rows the way the Keys screen lists them: never the hash, never
 * the secret, the truncated key WooCommerce keeps for display.
 *
 * @return array
 */
function minn_admin_wc_api_keys() {
	global $wpdb;
	$rows = $wpdb->get_results( "SELECT key_id, user_id, description, permissions, truncated_key, last_access FROM {$wpdb->prefix}woocommerce_api_keys ORDER BY key_id DESC" );
	$out  = array();
	foreach ( (array) $rows as $r ) {
		$user  = get_user_by( 'id', (int) $r->user_id );
		$out[] = array(
			'id'          => (int) $r->key_id,
			'description' => (string) $r->description,
			'user'        => (int) $r->user_id,
			'userName'    => $user ? $user->display_name : __( '(deleted user)', 'minn-admin' ),
			'userLogin'   => $user ? $user->user_login : '',
			'permissions' => (string) $r->permissions,
			'truncated'   => (string) $r->truncated_key,
			'lastAccess'  => $r->last_access ? get_gmt_from_date( (string) $r->last_access ) . 'Z' : '',
		);
	}
	return $out;
}

/**
 * The users an API key can belong to: WooCommerce offers anyone with
 * manage_woocommerce, plus the current user.
 *
 * @param string $q Query.
 * @return array
 */
function minn_admin_wc_api_key_users( $q ) {
	$q     = trim( (string) $q );
	$users = get_users(
		array(
			'number'     => 30,
			'search'     => '' !== $q ? '*' . $q . '*' : '',
			'orderby'    => 'display_name',
			'capability' => 'manage_woocommerce',
		)
	);
	$out   = array();
	foreach ( (array) $users as $u ) {
		$out[] = array(
			'value' => (string) $u->ID,
			'label' => $u->display_name . ' (' . $u->user_login . ')',
		);
	}
	return $out;
}

/**
 * The Keys screen's ownership rule for an EXISTING key: the caller may edit
 * or revoke it only when they can edit its owner, or it is their own. The
 * screen applies this on edit-key and revoke-key, so a shop manager (who
 * holds manage_woocommerce but not edit_user on an administrator) cannot
 * take over or kill an administrator's integration key.
 *
 * @param int $key_id Key row id.
 * @return bool
 */
function minn_admin_wc_api_key_owned( $key_id ) {
	global $wpdb;
	$owner = (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$wpdb->prefix}woocommerce_api_keys WHERE key_id = %d", absint( $key_id ) ) );
	return $owner > 0 && ( current_user_can( 'edit_user', $owner ) || get_current_user_id() === $owner );
}

/**
 * Create or update a key the way WC_AJAX::update_api_key does: the same
 * hash, the same truncated key, the same ownership rule. The consumer key
 * and secret are returned once on create and never stored by Minn.
 *
 * @param int   $key_id 0 to create.
 * @param array $body   { description, user, permissions }.
 * @return array|WP_Error
 */
function minn_admin_wc_api_key_save( $key_id, $body ) {
	global $wpdb;
	$description = isset( $body['description'] ) ? sanitize_text_field( (string) $body['description'] ) : '';
	$permissions = isset( $body['permissions'] ) && in_array( $body['permissions'], array( 'read', 'write', 'read_write' ), true ) ? $body['permissions'] : 'read';
	$user_id     = isset( $body['user'] ) ? absint( $body['user'] ) : get_current_user_id();
	if ( '' === $description ) {
		return new WP_Error( 'minn_wc_key_desc', __( 'Give the key a description.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	if ( ! $user_id || ! get_user_by( 'id', $user_id ) ) {
		return new WP_Error( 'minn_wc_key_user', __( 'Pick the user the key acts as.', 'minn-admin' ), array( 'status' => 400 ) );
	}
	if ( ! current_user_can( 'edit_user', $user_id ) && get_current_user_id() !== $user_id ) {
		return new WP_Error( 'minn_wc_key_user', __( 'You do not have permission to assign API keys to that user.', 'minn-admin' ), array( 'status' => 403 ) );
	}
	if ( $key_id && ! minn_admin_wc_api_key_owned( $key_id ) ) {
		return new WP_Error( 'minn_wc_key_user', __( 'You do not have permission to edit this API key.', 'minn-admin' ), array( 'status' => 403 ) );
	}
	if ( $key_id ) {
		$wpdb->update(
			$wpdb->prefix . 'woocommerce_api_keys',
			array(
				'user_id'     => $user_id,
				'description' => $description,
				'permissions' => $permissions,
			),
			array( 'key_id' => $key_id ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);
		return array( 'id' => $key_id );
	}
	$consumer_key    = 'ck_' . wc_rand_hash();
	$consumer_secret = 'cs_' . wc_rand_hash();
	$ok              = $wpdb->insert(
		$wpdb->prefix . 'woocommerce_api_keys',
		array(
			'user_id'         => $user_id,
			'description'     => $description,
			'permissions'     => $permissions,
			'consumer_key'    => wc_api_hash( $consumer_key ),
			'consumer_secret' => $consumer_secret,
			'truncated_key'   => substr( $consumer_key, -7 ),
		),
		array( '%d', '%s', '%s', '%s', '%s', '%s' )
	);
	if ( ! $ok || ! $wpdb->insert_id ) {
		return new WP_Error( 'minn_wc_key_insert', __( 'WooCommerce could not store the key.', 'minn-admin' ), array( 'status' => 500 ) );
	}
	return array(
		'id'             => (int) $wpdb->insert_id,
		'consumerKey'    => $consumer_key,
		'consumerSecret' => $consumer_secret,
	);
}

/**
 * Webhook topics as the webhook editor offers them (their filter included),
 * with the custom "action" entry that takes an event name.
 *
 * @return array [ value, label ] pairs.
 */
function minn_admin_wc_webhook_topics() {
	$topics = apply_filters(
		'woocommerce_webhook_topics',
		array(
			'coupon.created'    => __( 'Coupon created', 'woocommerce' ),
			'coupon.updated'    => __( 'Coupon updated', 'woocommerce' ),
			'coupon.deleted'    => __( 'Coupon deleted', 'woocommerce' ),
			'coupon.restored'   => __( 'Coupon restored', 'woocommerce' ),
			'customer.created'  => __( 'Customer created', 'woocommerce' ),
			'customer.updated'  => __( 'Customer updated', 'woocommerce' ),
			'customer.deleted'  => __( 'Customer deleted', 'woocommerce' ),
			'order.created'     => __( 'Order created', 'woocommerce' ),
			'order.updated'     => __( 'Order updated', 'woocommerce' ),
			'order.deleted'     => __( 'Order deleted', 'woocommerce' ),
			'order.restored'    => __( 'Order restored', 'woocommerce' ),
			'product.created'   => __( 'Product created', 'woocommerce' ),
			'product.updated'   => __( 'Product updated', 'woocommerce' ),
			'product.deleted'   => __( 'Product deleted', 'woocommerce' ),
			'product.restored'  => __( 'Product restored', 'woocommerce' ),
			'product.published' => __( 'Product published', 'woocommerce' ),
			'action'            => __( 'Action', 'woocommerce' ),
		)
	);
	$out = array();
	foreach ( (array) $topics as $v => $l ) {
		if ( '' === (string) $v ) {
			continue;
		}
		$out[] = array( (string) $v, minn_admin_wc_settings_text( (string) $l ) );
	}
	return $out;
}

/**
 * Lookup catalogs behind relation fields.
 *
 * @param string $catalog Catalog id.
 * @param string $q       Query.
 * @return array [ { value, label } ]
 */
function minn_admin_wc_settings_lookup( $catalog, $q, $all = false ) {
	$q   = mb_strtolower( trim( (string) $q ) );
	$out = array();
	if ( 'regions' === $catalog && function_exists( 'WC' ) && WC()->countries ) {
		return minn_admin_wc_shipping_regions_lookup( $q );
	}
	if ( 'key-users' === $catalog ) {
		return minn_admin_wc_api_key_users( $q );
	}
	if ( 'countries' === $catalog && function_exists( 'WC' ) && WC()->countries ) {
		foreach ( WC()->countries->get_countries() as $cc => $name ) {
			$name = html_entity_decode( $name, ENT_QUOTES, 'UTF-8' );
			if ( '' !== $q && false === mb_strpos( mb_strtolower( $name ), $q ) && false === mb_strpos( mb_strtolower( $cc ), $q ) ) {
				continue;
			}
			$out[] = array( 'value' => (string) $cc, 'label' => $name );
			if ( ! $all && count( $out ) >= 30 ) {
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

		// The route path deliberately contains "payment_gateways": Cash on
		// delivery only builds its shipping-method options when the request
		// is wp-admin's Payments screen or a REST route named that way
		// (WC_Gateway_COD::is_accessing_settings), and other gateways copy
		// the gate.
		register_rest_route(
			'minn-admin/v1',
			'/wc/payment_gateways',
			array(
				'methods'             => 'GET',
				'permission_callback' => $can,
				'callback'            => static function () {
					return array( 'gateways' => minn_admin_wc_settings_gateways() );
				},
			)
		);

		// Display order: the same option WC_Payment_Gateways::process_admin_options
		// writes from the Payments screen's drag order.
		register_rest_route(
			'minn-admin/v1',
			'/wc/payment_gateways/order',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'callback'            => static function ( $req ) {
					$body = $req->get_json_params();
					$ids  = isset( $body['ids'] ) && is_array( $body['ids'] ) ? array_map( 'sanitize_text_field', $body['ids'] ) : array();
					$known = array_map(
						static function ( $g ) {
							return $g['id'];
						},
						minn_admin_wc_settings_gateways()
					);
					$order = array();
					$i     = 0;
					foreach ( $ids as $id ) {
						if ( in_array( $id, $known, true ) && ! isset( $order[ $id ] ) ) {
							$order[ $id ] = $i++;
						}
					}
					foreach ( $known as $id ) {
						if ( ! isset( $order[ $id ] ) ) {
							$order[ $id ] = $i++;
						}
					}
					update_option( 'woocommerce_gateway_order', $order );
					WC()->payment_gateways()->init();
					return array( 'gateways' => minn_admin_wc_settings_gateways() );
				},
			)
		);

		register_rest_route(
			'minn-admin/v1',
			'/wc/payment_gateways/(?P<id>(?!order$)[\w-]+)',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $can,
					'callback'            => static function ( $req ) {
						$gateway = minn_admin_wc_settings_gateway( (string) $req['id'] );
						if ( ! $gateway ) {
							return new WP_Error( 'minn_wc_no_gateway', __( 'That payment method is not registered.', 'minn-admin' ), array( 'status' => 404 ) );
						}
						return array(
							'gateway' => $gateway->id,
							'title'   => minn_admin_wc_settings_text( $gateway->get_method_title() ),
						) + minn_admin_wc_settings_api_schema( $gateway, minn_admin_wc_settings_gateway_url( $gateway ) );
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $can,
					'callback'            => static function ( $req ) {
						$gateway = minn_admin_wc_settings_gateway( (string) $req['id'] );
						if ( ! $gateway ) {
							return new WP_Error( 'minn_wc_no_gateway', __( 'That payment method is not registered.', 'minn-admin' ), array( 'status' => 404 ) );
						}
						$body   = $req->get_json_params();
						$edited = isset( $body['values'] ) && is_array( $body['values'] ) ? $body['values'] : array();
						if ( ! $edited ) {
							return new WP_Error( 'minn_wc_nothing', __( 'Nothing to save.', 'minn-admin' ), array( 'status' => 400 ) );
						}
						try {
							// The Payments screen's own sequence for one gateway's
							// section: its update action (process_admin_options is
							// hooked there by every gateway), then a registry init.
							$gateway->set_post_data( minn_admin_wc_settings_api_post_data( $gateway, $edited ) );
							do_action( 'woocommerce_update_options_payment_gateways_' . $gateway->id );
							$gateway->set_post_data( array() );
							WC()->payment_gateways()->init();
							do_action( 'woocommerce_update_options_checkout' );
							do_action( 'woocommerce_settings_saved' );
						} catch ( Throwable $e ) {
							return new WP_Error( 'minn_wc_save_failed', $e->getMessage(), array( 'status' => 500 ) );
						}
						$fresh = minn_admin_wc_settings_gateway( $gateway->id );
						if ( $fresh ) {
							$fresh->init_settings();
						}
						return array(
							'gateway' => $gateway->id,
							'title'   => minn_admin_wc_settings_text( $gateway->get_method_title() ),
							'errors'  => minn_admin_wc_settings_queued_errors(),
						) + minn_admin_wc_settings_api_schema( $fresh ? $fresh : $gateway, minn_admin_wc_settings_gateway_url( $gateway ) );
					},
				),
			)
		);

		$zone_of = static function ( $req ) {
			$id = absint( $req['zone'] );
			if ( $id && ! WC_Shipping_Zones::get_zone( $id ) ) {
				return new WP_Error( 'minn_wc_no_zone', __( 'That shipping zone no longer exists.', 'minn-admin' ), array( 'status' => 404 ) );
			}
			return $id ? WC_Shipping_Zones::get_zone( $id ) : new WC_Shipping_Zone( 0 );
		};
		$method_of = static function ( $req, $zone ) {
			$instance = absint( $req['instance'] );
			$method   = $instance ? WC_Shipping_Zones::get_shipping_method( $instance ) : false;
			if ( ! $method ) {
				return new WP_Error( 'minn_wc_no_method', __( 'That shipping method no longer exists.', 'minn-admin' ), array( 'status' => 404 ) );
			}
			$owner = WC_Shipping_Zones::get_zone_by( 'instance_id', $instance );
			if ( ! $owner || (int) $owner->get_id() !== (int) $zone->get_id() ) {
				return new WP_Error( 'minn_wc_no_method', __( 'That shipping method is not in this zone.', 'minn-admin' ), array( 'status' => 404 ) );
			}
			return $method;
		};

		register_rest_route(
			'minn-admin/v1',
			'/wc/shipping',
			array(
				'methods'             => 'GET',
				'permission_callback' => $can,
				'callback'            => static function () {
					return minn_admin_wc_shipping_payload();
				},
			)
		);

		register_rest_route(
			'minn-admin/v1',
			'/wc/shipping/zones',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'callback'            => static function ( $req ) {
					$body = (array) $req->get_json_params();
					$name = isset( $body['name'] ) ? wc_clean( (string) $body['name'] ) : '';
					if ( '' === trim( $name ) ) {
						return new WP_Error( 'minn_wc_zone_name', __( 'A zone needs a name.', 'minn-admin' ), array( 'status' => 400 ) );
					}
					$zone = new WC_Shipping_Zone( null );
					$zone->set_zone_name( $name );
					$zone->set_zone_order( count( WC_Shipping_Zones::get_zones() ) + 1 );
					$zone->save();
					$r = minn_admin_wc_shipping_zone_apply( $zone, $body );
					if ( is_wp_error( $r ) ) {
						return $r;
					}
					return array( 'zone' => (int) $zone->get_id() ) + minn_admin_wc_shipping_payload();
				},
			)
		);

		register_rest_route(
			'minn-admin/v1',
			'/wc/shipping/zones/order',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'callback'            => static function ( $req ) {
					$body = (array) $req->get_json_params();
					$ids  = isset( $body['ids'] ) && is_array( $body['ids'] ) ? array_map( 'absint', $body['ids'] ) : array();
					$i    = 0;
					foreach ( $ids as $id ) {
						$zone = $id ? WC_Shipping_Zones::get_zone( $id ) : false;
						if ( ! $zone ) {
							continue;
						}
						do_action( 'woocommerce_update_non_option_setting', array( 'id' => 'zone_order' ) );
						$zone->set_zone_order( $i++ );
						$zone->save();
					}
					WC_Cache_Helper::get_transient_version( 'shipping', true );
					return minn_admin_wc_shipping_payload();
				},
			)
		);

		register_rest_route(
			'minn-admin/v1',
			'/wc/shipping/zones/(?P<zone>\d+)',
			array(
				array(
					'methods'             => 'POST',
					'permission_callback' => $can,
					'callback'            => static function ( $req ) use ( $zone_of ) {
						$zone = $zone_of( $req );
						if ( is_wp_error( $zone ) ) {
							return $zone;
						}
						$r = minn_admin_wc_shipping_zone_apply( $zone, (array) $req->get_json_params() );
						if ( is_wp_error( $r ) ) {
							return $r;
						}
						return minn_admin_wc_shipping_payload();
					},
				),
				array(
					'methods'             => 'DELETE',
					'permission_callback' => $can,
					'callback'            => static function ( $req ) use ( $zone_of ) {
						$zone = $zone_of( $req );
						if ( is_wp_error( $zone ) ) {
							return $zone;
						}
						if ( ! $zone->get_id() ) {
							return new WP_Error( 'minn_wc_zone_rest', __( 'The everywhere-else zone cannot be deleted.', 'minn-admin' ), array( 'status' => 400 ) );
						}
						WC_Shipping_Zones::delete_zone( $zone->get_id() );
						return minn_admin_wc_shipping_payload();
					},
				),
			)
		);

		register_rest_route(
			'minn-admin/v1',
			'/wc/shipping/zones/(?P<zone>\d+)/methods',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'callback'            => static function ( $req ) use ( $zone_of ) {
					$zone = $zone_of( $req );
					if ( is_wp_error( $zone ) ) {
						return $zone;
					}
					$body = (array) $req->get_json_params();
					$type = isset( $body['method'] ) ? sanitize_key( $body['method'] ) : '';
					$instance = $type ? (int) $zone->add_shipping_method( $type ) : 0;
					if ( ! $instance ) {
						return new WP_Error( 'minn_wc_method_add', __( 'That shipping method cannot be added to a zone.', 'minn-admin' ), array( 'status' => 400 ) );
					}
					return array( 'instance' => $instance ) + minn_admin_wc_shipping_payload();
				},
			)
		);

		register_rest_route(
			'minn-admin/v1',
			'/wc/shipping/zones/(?P<zone>\d+)/methods/order',
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'callback'            => static function ( $req ) use ( $zone_of ) {
					global $wpdb;
					$zone = $zone_of( $req );
					if ( is_wp_error( $zone ) ) {
						return $zone;
					}
					$body = (array) $req->get_json_params();
					$ids  = isset( $body['instances'] ) && is_array( $body['instances'] ) ? array_map( 'absint', $body['instances'] ) : array();
					$own  = array_map( 'intval', array_keys( (array) $zone->get_shipping_methods( false, 'admin' ) ) );
					$i    = 1;
					foreach ( $ids as $instance ) {
						if ( ! in_array( $instance, $own, true ) ) {
							continue;
						}
						// The zones screen's own write for method_order.
						do_action( 'woocommerce_update_non_option_setting', array( 'id' => 'zone_methods_order' ) );
						$wpdb->update( "{$wpdb->prefix}woocommerce_shipping_zone_methods", array( 'method_order' => $i++ ), array( 'instance_id' => $instance ) );
					}
					WC_Cache_Helper::get_transient_version( 'shipping', true );
					return minn_admin_wc_shipping_payload();
				},
			)
		);

		register_rest_route(
			'minn-admin/v1',
			'/wc/shipping/zones/(?P<zone>\d+)/methods/(?P<instance>\d+)',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $can,
					'callback'            => static function ( $req ) use ( $zone_of, $method_of ) {
						$zone = $zone_of( $req );
						if ( is_wp_error( $zone ) ) {
							return $zone;
						}
						$method = $method_of( $req, $zone );
						if ( is_wp_error( $method ) ) {
							return $method;
						}
						return minn_admin_wc_shipping_method_schema( $method );
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $can,
					'callback'            => static function ( $req ) use ( $zone_of, $method_of ) {
						global $wpdb;
						$zone = $zone_of( $req );
						if ( is_wp_error( $zone ) ) {
							return $zone;
						}
						$method = $method_of( $req, $zone );
						if ( is_wp_error( $method ) ) {
							return $method;
						}
						$body     = (array) $req->get_json_params();
						$instance = (int) $method->get_instance_id();
						if ( array_key_exists( 'enabled', $body ) ) {
							// The zones screen's own toggle write + its action.
							do_action( 'woocommerce_update_non_option_setting', array( 'id' => 'zone_methods_enabled' ) );
							$on = ! empty( $body['enabled'] ) ? 1 : 0;
							if ( $wpdb->update( "{$wpdb->prefix}woocommerce_shipping_zone_methods", array( 'is_enabled' => $on ), array( 'instance_id' => $instance ) ) ) {
								do_action( 'woocommerce_shipping_zone_method_status_toggled', $instance, $method->id, $zone->get_id(), $on );
							}
							WC_Cache_Helper::get_transient_version( 'shipping', true );
							return minn_admin_wc_shipping_payload();
						}
						$edited = isset( $body['values'] ) && is_array( $body['values'] ) ? $body['values'] : array();
						if ( ! $edited ) {
							return new WP_Error( 'minn_wc_nothing', __( 'Nothing to save.', 'minn-admin' ), array( 'status' => 400 ) );
						}
						try {
							// The method-settings ajax save, byte for byte: prepared
							// post data, the instance id the method checks for, the
							// options action, then its own process_admin_options.
							$method->set_post_data( minn_admin_wc_settings_api_post_data( $method, $edited, $method->get_instance_form_fields(), array( $method, 'get_instance_option' ) ) );
							$prev_instance             = isset( $_REQUEST['instance_id'] ) ? $_REQUEST['instance_id'] : null;
							$_REQUEST['instance_id']   = $instance;
							global $current_tab;
							$prev_tab    = $current_tab;
							$current_tab = 'shipping';
							do_action( 'woocommerce_update_non_option_setting', array( 'id' => 'zone_method_settings' ) );
							do_action( 'woocommerce_update_options' );
							$method->process_admin_options();
							$current_tab = $prev_tab;
							if ( null === $prev_instance ) {
								unset( $_REQUEST['instance_id'] );
							} else {
								$_REQUEST['instance_id'] = $prev_instance;
							}
							$method->set_post_data( array() );
							WC_Cache_Helper::get_transient_version( 'shipping', true );
						} catch ( Throwable $e ) {
							return new WP_Error( 'minn_wc_save_failed', $e->getMessage(), array( 'status' => 500 ) );
						}
						$fresh  = WC_Shipping_Zones::get_shipping_method( $instance );
						$errors = array_values( array_filter( array_map( 'minn_admin_wc_settings_text', (array) $method->get_errors() ) ) );
						return array( 'errors' => $errors ) + minn_admin_wc_shipping_method_schema( $fresh ? $fresh : $method );
					},
				),
				array(
					'methods'             => 'DELETE',
					'permission_callback' => $can,
					'callback'            => static function ( $req ) use ( $zone_of, $method_of ) {
						$zone = $zone_of( $req );
						if ( is_wp_error( $zone ) ) {
							return $zone;
						}
						$method = $method_of( $req, $zone );
						if ( is_wp_error( $method ) ) {
							return $method;
						}
						$key = $method->get_instance_option_key();
						$zone->delete_shipping_method( (int) $method->get_instance_id() );
						// The zones screen deletes the instance's option row too.
						delete_option( $key );
						return minn_admin_wc_shipping_payload();
					},
				),
			)
		);

		register_rest_route(
			'minn-admin/v1',
			'/wc/api-keys',
			array(
				array(
					'methods'             => 'GET',
					'permission_callback' => $can,
					'callback'            => static function () {
						return array( 'keys' => minn_admin_wc_api_keys() );
					},
				),
				array(
					'methods'             => 'POST',
					'permission_callback' => $can,
					'callback'            => static function ( $req ) {
						$r = minn_admin_wc_api_key_save( 0, (array) $req->get_json_params() );
						if ( is_wp_error( $r ) ) {
							return $r;
						}
						return $r + array( 'keys' => minn_admin_wc_api_keys() );
					},
				),
			)
		);

		register_rest_route(
			'minn-admin/v1',
			'/wc/api-keys/(?P<id>\d+)',
			array(
				array(
					'methods'             => 'POST',
					'permission_callback' => $can,
					'callback'            => static function ( $req ) {
						global $wpdb;
						$id = absint( $req['id'] );
						if ( ! $wpdb->get_var( $wpdb->prepare( "SELECT key_id FROM {$wpdb->prefix}woocommerce_api_keys WHERE key_id = %d", $id ) ) ) {
							return new WP_Error( 'minn_wc_no_key', __( 'That API key no longer exists.', 'minn-admin' ), array( 'status' => 404 ) );
						}
						$r = minn_admin_wc_api_key_save( $id, (array) $req->get_json_params() );
						if ( is_wp_error( $r ) ) {
							return $r;
						}
						return $r + array( 'keys' => minn_admin_wc_api_keys() );
					},
				),
				array(
					'methods'             => 'DELETE',
					'permission_callback' => $can,
					'callback'            => static function ( $req ) {
						global $wpdb;
						// Revoke = the Keys screen's revoke_key: the same owner rule,
						// then the row goes, the hash with it, and any client holding
						// the key is out.
						if ( ! minn_admin_wc_api_key_owned( absint( $req['id'] ) ) ) {
							return new WP_Error( 'minn_wc_key_user', __( 'You do not have permission to revoke this API key.', 'minn-admin' ), array( 'status' => 403 ) );
						}
						$wpdb->delete( $wpdb->prefix . 'woocommerce_api_keys', array( 'key_id' => absint( $req['id'] ) ), array( '%d' ) );
						return array( 'keys' => minn_admin_wc_api_keys() );
					},
				),
			)
		);

		register_rest_route(
			'minn-admin/v1',
			'/wc/webhook-topics',
			array(
				'methods'             => 'GET',
				'permission_callback' => $can,
				'callback'            => static function () {
					$versions = function_exists( 'wc_get_webhook_rest_api_versions' ) ? wc_get_webhook_rest_api_versions() : array( 'wp_api_v3' );
					return array(
						'topics'   => minn_admin_wc_webhook_topics(),
						'versions' => array_values( array_map( 'strval', (array) $versions ) ),
					);
				},
			)
		);

		register_rest_route(
			'minn-admin/v1',
			'/wc/lookup',
			array(
				'methods'             => 'GET',
				'permission_callback' => $can,
				'callback'            => static function ( $req ) {
					return minn_admin_wc_settings_lookup( sanitize_key( $req['catalog'] ), (string) $req['q'], ! empty( $req['all'] ) );
				},
			)
		);
	}
);
