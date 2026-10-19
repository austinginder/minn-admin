<?php
/**
 * Perfmatters adapter — its settings estate as a Minn settings-only surface.
 *
 * Perfmatters registers every option through the core WP Settings API: one
 * shared print callback (`perfmatters_print_input`) whose args array is a
 * complete field descriptor (id, input type, choices, tooltip, placeholder,
 * nested section, target option). The schema here is read from those live
 * registrations at runtime, never hand-copied, so a Perfmatters update that
 * adds a field shows up in Minn with no adapter change. Fields drawn by a
 * bespoke callback (input rows, font subsets, quick exclusions) are counted
 * as locked and link out to wp-admin.
 *
 * The screen is declared to the settings tier (kernel/class-minn-admin-
 * kernel-settings.php), which owns the routes and the order of a save. Writes
 * merge only the edited keys into the stored option and save through
 * `update_option`, which runs Perfmatters' own registered sanitizer
 * (`perfmatters_sanitize_options`) exactly like its own settings form —
 * including the one-per-line textarea → array normalization.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tab layout mirroring Perfmatters' own admin sections (inc/admin.php).
 * Sections absent from the live registry (e.g. WooCommerce without Woo
 * active, version-gated speculative loading) simply don't render.
 */
function minn_admin_perfmatters_tabs() {
	return array(
		'general'   => array( 'label' => __( 'General', 'minn-admin' ), 'sections' => array( 'perfmatters_options', 'login_url', 'perfmatters_woocommerce' ) ),
		'js'        => array( 'label' => __( 'JavaScript', 'minn-admin' ), 'sections' => array( 'assets_js_defer', 'assets_js_delay', 'assets_js_minify' ) ),
		'css'       => array( 'label' => 'CSS', 'sections' => array( 'assets_css', 'assets_css_minify' ) ),
		'code'      => array( 'label' => __( 'Code', 'minn-admin' ), 'sections' => array( 'assets_code' ) ),
		'preload'   => array( 'label' => __( 'Preload', 'minn-admin' ), 'sections' => array( 'preload', 'preload_speculative', 'preload_connection' ) ),
		'lazyload'  => array( 'label' => __( 'Lazy Loading', 'minn-admin' ), 'sections' => array( 'lazyload', 'lazyload_css_background_images', 'lazyload_elements' ) ),
		'fonts'     => array( 'label' => __( 'Fonts', 'minn-admin' ), 'sections' => array( 'perfmatters_fonts' ) ),
		'cdn'       => array( 'label' => 'CDN', 'sections' => array( 'perfmatters_cdn' ) ),
		'analytics' => array( 'label' => __( 'Analytics', 'minn-admin' ), 'sections' => array( 'perfmatters_analytics' ) ),
	);
}

/**
 * The live Settings API registry for the perfmatters_options page.
 * `perfmatters_settings()` normally runs on admin_init; under REST we call
 * it directly — it only registers (fields, sections, the sanitizer), no
 * output — so the write path below also gets the sanitize filter.
 */
function minn_admin_perfmatters_registry() {
	static $primed = false;
	if ( ! $primed ) {
		// add_settings_section()/add_settings_field() live in an admin
		// include that REST requests don't load.
		if ( ! function_exists( 'add_settings_section' ) ) {
			require_once ABSPATH . 'wp-admin/includes/template.php';
		}
		perfmatters_settings();
		$primed = true;
	}
	global $wp_settings_fields, $wp_settings_sections;
	return array(
		'fields'   => isset( $wp_settings_fields['perfmatters_options'] ) ? $wp_settings_fields['perfmatters_options'] : array(),
		'sections' => isset( $wp_settings_sections['perfmatters_options'] ) ? $wp_settings_sections['perfmatters_options'] : array(),
	);
}

/**
 * Map one Settings API registration onto the shared form vocabulary.
 * Returns null for fields Minn can't render generically (bespoke callbacks,
 * action buttons) — the caller counts those as locked.
 */
function minn_admin_perfmatters_field( $field ) {
	if ( ! isset( $field['callback'] ) || 'perfmatters_print_input' !== $field['callback'] ) {
		return null;
	}
	$args = isset( $field['args'] ) ? (array) $field['args'] : array();
	if ( empty( $args['id'] ) ) {
		return null;
	}
	$input = isset( $args['input'] ) ? $args['input'] : '';
	if ( 'button' === $input ) {
		return null; // action buttons (clear caches etc.) are not settings
	}
	// Drop the docs-link anchor (its "?" glyph), then flatten; a space per
	// tag boundary keeps badge spans ("BETA") from fusing into the label.
	$title = preg_replace( '/<a\b[^>]*>.*?<\/a>/s', '', (string) $field['title'] );
	$label = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( str_replace( '<', ' <', $title ) ) ) );
	$f     = array(
		'key'   => minn_admin_perfmatters_key( $args ),
		'label' => $label ? $label : ucwords( str_replace( '_', ' ', $args['id'] ) ),
	);
	if ( ! empty( $args['tooltip'] ) ) {
		$f['help'] = trim( wp_strip_all_tags( (string) $args['tooltip'] ) );
	}
	if ( 'text' === $input || 'color' === $input ) {
		$f['type'] = 'text';
	} elseif ( 'select' === $input ) {
		$f['type']    = 'select';
		$f['options'] = array();
		foreach ( (array) ( $args['options'] ?? array() ) as $value => $title ) {
			$f['options'][] = array( (string) $value, wp_strip_all_tags( (string) $title ) );
		}
	} elseif ( 'textarea' === $input ) {
		$f['type'] = 'textarea';
		$f['rows'] = 4;
		$f['mono'] = true;
	} else {
		$f['type'] = 'toggle';
	}
	if ( ! empty( $args['placeholder'] ) ) {
		$f['placeholder'] = (string) $args['placeholder'];
	}
	return $f;
}

/**
 * Field key encoding: option:section:id (empty section allowed). Kept
 * parseable so the write path can resolve storage without re-walking args.
 */
function minn_admin_perfmatters_key( $args ) {
	$option  = ! empty( $args['option'] ) ? $args['option'] : 'perfmatters_options';
	$section = ! empty( $args['section'] ) ? $args['section'] : '';
	return $option . ':' . $section . ':' . $args['id'];
}

/** Current display value for one mapped field, from the stored option. */
function minn_admin_perfmatters_value( $args, $type ) {
	$option = ! empty( $args['option'] ) ? $args['option'] : 'perfmatters_options';
	$stored = get_option( $option, array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}
	if ( ! empty( $args['section'] ) ) {
		$stored = isset( $stored[ $args['section'] ] ) && is_array( $stored[ $args['section'] ] ) ? $stored[ $args['section'] ] : array();
	}
	$v = isset( $stored[ $args['id'] ] ) ? $stored[ $args['id'] ] : '';
	if ( 'toggle' === $type ) {
		return ! empty( $v );
	}
	if ( is_array( $v ) ) {
		// one-per-line textareas store arrays; render them back as lines
		return implode( "\n", array_map( 'strval', $v ) );
	}
	return (string) $v;
}

/**
 * One tab as offered, or every tab (null) for a save from PHP; null for a tab
 * that does not exist. Each field carries its registration (`_args`) for the
 * read and the write, and `_raw` when Perfmatters prints its value raw.
 *
 * @param string|null $tab_id Tab id, or null for the whole screen.
 * @return array|null { groups, adminUrl }
 */
function minn_admin_perfmatters_layout( $tab_id ) {
	$tabs = minn_admin_perfmatters_tabs();
	if ( null !== $tab_id && ! isset( $tabs[ $tab_id ] ) ) {
		return null;
	}
	$reg      = minn_admin_perfmatters_registry();
	$sections = null === $tab_id ? array_merge( ...array_values( wp_list_pluck( $tabs, 'sections' ) ) ) : $tabs[ $tab_id ]['sections'];
	$groups   = array();
	foreach ( $sections as $section_id ) {
		if ( empty( $reg['fields'][ $section_id ] ) ) {
			continue;
		}
		$title  = isset( $reg['sections'][ $section_id ]['title'] ) ? trim( wp_strip_all_tags( (string) $reg['sections'][ $section_id ]['title'] ) ) : '';
		$fields = array();
		$locked = 0;
		foreach ( $reg['fields'][ $section_id ] as $field ) {
			$f = minn_admin_perfmatters_field( $field );
			if ( null === $f ) {
				$locked++;
				continue;
			}
			$args       = (array) $field['args'];
			$f['_args'] = $args;
			$f['_raw']  = minn_admin_perfmatters_is_raw_output( (string) ( $args['section'] ?? '' ), (string) ( $args['id'] ?? '' ) );
			$fields[]   = $f;
		}
		if ( ! $fields && ! $locked ) {
			continue;
		}
		$groups[] = array(
			'title'  => $title,
			'fields' => $fields,
			'locked' => $locked,
		);
	}
	return array(
		'groups'   => $groups,
		'adminUrl' => admin_url( 'options-general.php?page=perfmatters' ),
	);
}

/**
 * Who may read and write Perfmatters settings through Minn.
 *
 * Perfmatters draws a boundary that is not a WordPress capability. Its
 * perfmatters_network_access() reads the network option and, when the network
 * owner has chosen "Super Admins Only", returns false for everyone else — and
 * the plugin wraps its ENTIRE add_options_page() registration in it, so a
 * subsite administrator has no Perfmatters screen at all on such a network.
 * manage_options alone reproduces the capability and drops the lockout, which
 * is the network owner's deliberate decision about their own network.
 *
 * @return bool
 */
function minn_admin_perfmatters_can() {
	if ( function_exists( 'perfmatters_network_access' ) && ! perfmatters_network_access() ) {
		return false;
	}
	return current_user_can( 'manage_options' );
}

/**
 * Settings fields whose value is emitted as raw markup on every page view.
 *
 * Perfmatters echoes these three verbatim from wp_head, after </body> and from
 * wp_footer, and its own sanitizer does not touch them. That makes writing one
 * the same act as authoring an HFCM or WPCode snippet, so it answers to the
 * same two rules those adapters already apply: the capability WordPress uses
 * for storing raw markup, and the directive a site owner sets to forbid
 * dashboard-driven code.
 *
 * @return string[] Field ids within the perfmatters_options `assets` section.
 */
function minn_admin_perfmatters_code_fields() {
	return array( 'header_code', 'body_code', 'footer_code' );
}

/**
 * Every Perfmatters field whose stored value reaches the page unescaped.
 *
 * Naming only the three `assets` code boxes covered the fields that LOOK like code
 * and missed the ones that behave like it. Perfmatters emits each line of
 * preload.dns_prefetch as
 *   echo "&lt;link rel='dns-prefetch' href='" . $url . "'&gt;"
 * straight into wp_head on every front-end request, with no escaping and no
 * sanitising beyond a trim and a split -- so a value carrying a quote closes the
 * attribute and the tag. analytics.tracking_id is the same shape, one sink smaller.
 *
 * Keyed section => ids so the test is exact rather than an id match that could
 * collide across sections.
 *
 * @return array<string,string[]> Section id => field ids.
 */
function minn_admin_perfmatters_raw_output_fields() {
	return array(
		'assets'    => array( 'header_code', 'body_code', 'footer_code' ),
		'preload'   => array( 'dns_prefetch' ),
		'analytics' => array( 'tracking_id' ),
		// Substituted into every src= and href= in the finished page, inside
		// the quotes and without escaping, and their own sanitizer does not
		// list it. Same rule as the fields above: a real CDN hostname loses
		// nothing to the quote-stripping these fields get.
		'cdn'       => array( 'cdn_url' ),
		'fonts'     => array( 'cdn_url' ),
		// threshold is concatenated into the inline window.lazyLoadOptions
		// script on every front-end page (their validate attribute runs in
		// the browser only); login_url_message is handed to wp_die verbatim.
		'lazyload'  => array( 'threshold' ),
		// Keyed by STORAGE location (the field's args.section), not by the
		// settings-screen section it is drawn in: login_url_message carries no
		// args.section, so it lives at the top level of perfmatters_options and
		// a 'login_url' key here never matched it.
		''          => array( 'login_url_message' ),
	);
}

/**
 * Does this field's value reach the page as raw markup?
 *
 * @param string $section Section id.
 * @param string $id      Field id.
 * @return bool
 */
function minn_admin_perfmatters_is_raw_output( $section, $id ) {
	$map = minn_admin_perfmatters_raw_output_fields();
	return isset( $map[ $section ] ) && in_array( (string) $id, $map[ $section ], true );
}

/**
 * Write the changed keys, in Perfmatters' stored shapes ('1'/absent flags,
 * plain strings; one-per-line strings are handed to its own sanitizer, which
 * normalizes them to arrays exactly like its form), one update per target
 * option. The settings tier has already kept every untouched value out and
 * refused a raw-output field this caller may not change.
 *
 * @param array $changes Changed values, by field key.
 * @param array $fields  Their fields, by the same key.
 * @return true
 */
function minn_admin_perfmatters_write( $changes, $fields ) {
	$pending = array(); // option name → stored array with edits applied
	foreach ( $changes as $key => $v ) {
		$args   = $fields[ $key ]['_args'];
		$type   = $fields[ $key ]['type'];
		$option = ! empty( $args['option'] ) ? $args['option'] : 'perfmatters_options';
		if ( ! isset( $pending[ $option ] ) ) {
			$stored             = get_option( $option, array() );
			$pending[ $option ] = is_array( $stored ) ? $stored : array();
		}
		$slot =& $pending[ $option ];
		if ( ! empty( $args['section'] ) ) {
			if ( ! isset( $slot[ $args['section'] ] ) || ! is_array( $slot[ $args['section'] ] ) ) {
				$slot[ $args['section'] ] = array();
			}
			$slot =& $slot[ $args['section'] ];
		}
		if ( 'toggle' === $type ) {
			// Perfmatters stores checked as '1' and reads !empty(); its own
			// form omits unchecked boxes, so off = the key goes away.
			if ( ! empty( $v ) && 'false' !== $v ) {
				$slot[ $args['id'] ] = '1';
			} else {
				unset( $slot[ $args['id'] ] );
			}
		} else {
			$val = is_scalar( $v ) ? (string) $v : '';
			// Belt and braces on the fields Perfmatters interpolates into a
			// single-quoted attribute or an inline script: strip the characters
			// that would close it and open a tag. Hostnames, protocol-relative
			// and absolute URLs are all unaffected, so this costs a legitimate
			// value nothing even when the caller does hold unfiltered_html.
			// login_url_message is prose wp_die() prints, not a value
			// interpolated into an attribute, so it keeps its quotes and tags
			// (the raw-output rule already limits who may set it).
			if ( ! empty( $fields[ $key ]['_raw'] )
				&& ! in_array( (string) $args['id'], minn_admin_perfmatters_code_fields(), true )
				&& 'login_url_message' !== (string) $args['id'] ) {
				$val = str_replace( array( '"', "'", '<', '>' ), '', $val );
			}
			$slot[ $args['id'] ] = $val;
		}
		unset( $slot );
	}
	foreach ( $pending as $option => $data ) {
		// update_option runs the sanitizer perfmatters_settings() registered.
		update_option( $option, $data );
	}
	return true;
}

/**
 * Save edited keys from PHP, over every tab, through the settings tier's
 * checks (the same path the tab's POST takes).
 *
 * @param array $values Sent values, by field key.
 * @return true|WP_Error A 403 naming the raw-output fields this caller may
 *                       not change, before anything is written.
 */
function minn_admin_perfmatters_save( $values ) {
	return minn_admin_settings_save( 'perfmatters', array( 'values' => (array) $values ), array( 'tab' => null ) );
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! function_exists( 'perfmatters_settings' ) ) {
		return $surfaces;
	}
	// Same predicate the routes use, so the nav cannot offer a surface the
	// routes will refuse (and vice versa).
	if ( ! minn_admin_perfmatters_can() ) {
		return $surfaces;
	}
	$tabs = array();
	foreach ( minn_admin_perfmatters_tabs() as $id => $tab ) {
		$tabs[] = array( 'id' => $id, 'label' => $tab['label'] );
	}
	$surfaces['perfmatters'] = array(
		'label'    => __( 'Performance', 'minn-admin' ),
		'sub'      => 'Perfmatters',
		'plugin'     => 'perfmatters',
		'family'   => 'performance',
		'icon'     => 'gear',
		'cap'      => 'manage_options',
		'settings' => array(
			'label' => __( 'Settings', 'minn-admin' ),
			'tabs'  => $tabs,
			'route' => 'minn-admin/v1/perfmatters/settings/{tab}',
		),
	);
	return $surfaces;
} );


// Every key is resolved against the live registry (never an arbitrary option
// write); the tier compares, gates and refuses before the one write.
minn_admin_settings( 'perfmatters', array(
	'route'  => 'perfmatters/settings/(?P<tab>[a-z]+)',
	'active' => function () {
		return function_exists( 'perfmatters_settings' );
	},
	'gate'   => 'minn_admin_perfmatters_can',
	'layout' => function ( $ctx ) {
		return minn_admin_perfmatters_layout( $ctx['tab'] );
	},
	'read'   => function ( $field ) {
		return minn_admin_perfmatters_value( $field['_args'], $field['type'] );
	},
	'write'  => 'minn_admin_perfmatters_write',
) );
