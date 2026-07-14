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
 * Writes merge only the edited keys into the stored option and save through
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
		'general'   => array( 'label' => 'General', 'sections' => array( 'perfmatters_options', 'login_url', 'perfmatters_woocommerce' ) ),
		'js'        => array( 'label' => 'JavaScript', 'sections' => array( 'assets_js_defer', 'assets_js_delay', 'assets_js_minify' ) ),
		'css'       => array( 'label' => 'CSS', 'sections' => array( 'assets_css', 'assets_css_minify' ) ),
		'code'      => array( 'label' => 'Code', 'sections' => array( 'assets_code' ) ),
		'preload'   => array( 'label' => 'Preload', 'sections' => array( 'preload', 'preload_speculative', 'preload_connection' ) ),
		'lazyload'  => array( 'label' => 'Lazy Loading', 'sections' => array( 'lazyload', 'lazyload_css_background_images', 'lazyload_elements' ) ),
		'fonts'     => array( 'label' => 'Fonts', 'sections' => array( 'perfmatters_fonts' ) ),
		'cdn'       => array( 'label' => 'CDN', 'sections' => array( 'perfmatters_cdn' ) ),
		'analytics' => array( 'label' => 'Analytics', 'sections' => array( 'perfmatters_analytics' ) ),
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

/** GET/POST shape for one tab: { groups, values, adminUrl }. */
function minn_admin_perfmatters_tab_shape( $tab_id ) {
	$tabs = minn_admin_perfmatters_tabs();
	if ( ! isset( $tabs[ $tab_id ] ) ) {
		return new WP_Error( 'minn_no_tab', 'Unknown settings tab.', array( 'status' => 404 ) );
	}
	$reg    = minn_admin_perfmatters_registry();
	$groups = array();
	$values = array();
	foreach ( $tabs[ $tab_id ]['sections'] as $section_id ) {
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
			$fields[]                = $f;
			$values[ $f['key'] ]     = minn_admin_perfmatters_value( (array) $field['args'], $f['type'] );
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
		'values'   => $values,
		'adminUrl' => admin_url( 'options-general.php?page=perfmatters' ),
	);
}

/**
 * Save edited keys. Each key is validated against the live registry (never
 * an arbitrary option write), coerced to Perfmatters' stored shapes
 * ('1'/absent flags, plain strings; one-per-line strings are handed to its
 * own sanitizer, which normalizes them to arrays exactly like its form),
 * then the whole option updates once per target option.
 */
function minn_admin_perfmatters_save( $values ) {
	$reg   = minn_admin_perfmatters_registry();
	$byKey = array();
	foreach ( $reg['fields'] as $section_fields ) {
		foreach ( $section_fields as $field ) {
			$f = minn_admin_perfmatters_field( $field );
			if ( $f ) {
				$byKey[ $f['key'] ] = array( 'args' => (array) $field['args'], 'type' => $f['type'] );
			}
		}
	}
	$pending = array(); // option name → stored array with edits applied
	foreach ( (array) $values as $key => $v ) {
		if ( ! isset( $byKey[ $key ] ) ) {
			continue;
		}
		$args   = $byKey[ $key ]['args'];
		$type   = $byKey[ $key ]['type'];
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
			$slot[ $args['id'] ] = is_scalar( $v ) ? (string) $v : '';
		}
		unset( $slot );
	}
	foreach ( $pending as $option => $data ) {
		// update_option runs the sanitizer perfmatters_settings() registered.
		update_option( $option, $data );
	}
	return true;
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! function_exists( 'perfmatters_settings' ) ) {
		return $surfaces;
	}
	$tabs = array();
	foreach ( minn_admin_perfmatters_tabs() as $id => $tab ) {
		$tabs[] = array( 'id' => $id, 'label' => $tab['label'] );
	}
	$surfaces['perfmatters'] = array(
		'label'    => 'Performance',
		'sub'      => 'Perfmatters',
		'family'   => 'performance',
		'icon'     => 'gear',
		'cap'      => 'manage_options',
		'settings' => array(
			'label' => 'Settings',
			'tabs'  => $tabs,
			'route' => 'minn-admin/v1/perfmatters/settings/{tab}',
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! function_exists( 'perfmatters_settings' ) ) {
		return;
	}
	register_rest_route( 'minn-admin/v1', '/perfmatters/settings/(?P<tab>[a-z]+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => function ( $req ) {
				return rest_ensure_response( minn_admin_perfmatters_tab_shape( $req['tab'] ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => function ( $req ) {
				$body   = $req->get_json_params();
				$values = isset( $body['values'] ) && is_array( $body['values'] ) ? $body['values'] : array();
				minn_admin_perfmatters_save( $values );
				return rest_ensure_response( minn_admin_perfmatters_tab_shape( $req['tab'] ) );
			},
		),
	) );
} );
