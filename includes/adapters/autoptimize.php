<?php
/**
 * Autoptimize adapter — JS/CSS/HTML minify settings as a Minn surface.
 *
 * Autoptimize stores each setting as its own option (`autoptimize_js`, …).
 * Checkboxes go through options.php as the string `on` when checked and as
 * empty when unchecked (WP still calls update_option with null/empty for
 * missing POST keys in a registered settings group). The form is hand-built,
 * not a Settings-API field registry, so the schema here is a curated map of
 * the daily options on the main "JS, CSS & HTML" screen. Critical CSS, Extra
 * and Image tabs stay one click away in wp-admin.
 *
 * Family: `performance` (shared nav item with Perfmatters, Asset CleanUp,
 * Performance Lab). Cache purge already ships via cache-purge.php.
 */

defined( 'ABSPATH' ) || exit;

/**
 * @return bool
 */
function minn_admin_autoptimize_active() {
	return defined( 'AUTOPTIMIZE_PLUGIN_VERSION' ) || class_exists( 'autoptimizeMain', false );
}

/**
 * Field schema: tab id => groups of fields.
 * Each field: key (option name), label, type (toggle|text|textarea), help?
 *
 * @return array
 */
function minn_admin_autoptimize_schema() {
	return array(
		'js'   => array(
			'label'  => 'JavaScript',
			'groups' => array(
				array(
					'title'  => 'JavaScript options',
					'fields' => array(
						array( 'key' => 'autoptimize_js', 'label' => 'Optimize JavaScript code', 'type' => 'toggle' ),
						array( 'key' => 'autoptimize_js_aggregate', 'label' => 'Aggregate JS files', 'type' => 'toggle', 'help' => 'Combine JS into fewer files. Mutually exclusive with "Defer non-aggregated JS".' ),
						array( 'key' => 'autoptimize_js_include_inline', 'label' => 'Also aggregate inline JS', 'type' => 'toggle' ),
						array( 'key' => 'autoptimize_js_forcehead', 'label' => 'Force JavaScript in head', 'type' => 'toggle' ),
						array( 'key' => 'autoptimize_js_trycatch', 'label' => 'Add try-catch wrapping', 'type' => 'toggle' ),
						array( 'key' => 'autoptimize_js_defer_not_aggregate', 'label' => 'Do not aggregate but defer', 'type' => 'toggle', 'help' => 'Defer individual scripts instead of combining them.' ),
						array( 'key' => 'autoptimize_js_defer_inline', 'label' => 'Also defer inline JS', 'type' => 'toggle' ),
						array( 'key' => 'autoptimize_js_exclude', 'label' => 'Exclude scripts from Autoptimize', 'type' => 'text', 'help' => 'Comma-separated filename or path fragments.' ),
					),
				),
			),
		),
		'css'  => array(
			'label'  => 'CSS',
			'groups' => array(
				array(
					'title'  => 'CSS options',
					'fields' => array(
						array( 'key' => 'autoptimize_css', 'label' => 'Optimize CSS code', 'type' => 'toggle' ),
						array( 'key' => 'autoptimize_css_aggregate', 'label' => 'Aggregate CSS files', 'type' => 'toggle' ),
						array( 'key' => 'autoptimize_css_include_inline', 'label' => 'Also aggregate inline CSS', 'type' => 'toggle' ),
						array( 'key' => 'autoptimize_css_datauris', 'label' => 'Generate data: URIs for images', 'type' => 'toggle' ),
						array( 'key' => 'autoptimize_css_inline', 'label' => 'Inline all CSS', 'type' => 'toggle' ),
						array( 'key' => 'autoptimize_css_defer', 'label' => 'Eliminate render-blocking CSS', 'type' => 'toggle', 'help' => 'Defers CSS load; paste critical CSS below or use the Critical CSS tab in Autoptimize.' ),
						array( 'key' => 'autoptimize_css_defer_inline', 'label' => 'Inline critical CSS', 'type' => 'textarea' ),
						array( 'key' => 'autoptimize_css_exclude', 'label' => 'Exclude CSS from Autoptimize', 'type' => 'text' ),
					),
				),
			),
		),
		'html' => array(
			'label'  => 'HTML',
			'groups' => array(
				array(
					'title'  => 'HTML options',
					'fields' => array(
						array( 'key' => 'autoptimize_html', 'label' => 'Optimize HTML code', 'type' => 'toggle' ),
						array( 'key' => 'autoptimize_html_minify_inline', 'label' => 'Minify inline JS and CSS', 'type' => 'toggle' ),
						array( 'key' => 'autoptimize_html_keepcomments', 'label' => 'Keep HTML comments', 'type' => 'toggle' ),
					),
				),
			),
		),
		'cdn'  => array(
			'label'  => 'CDN',
			'groups' => array(
				array(
					'title'  => 'CDN options',
					'fields' => array(
						array( 'key' => 'autoptimize_cdn_url', 'label' => 'CDN base URL', 'type' => 'text', 'help' => 'Leave empty to serve optimized files from this site. Example: //cdn.example.com/' ),
					),
				),
			),
		),
		'misc' => array(
			'label'  => 'Misc',
			'groups' => array(
				array(
					'title'  => 'Misc options',
					'fields' => array(
						array( 'key' => 'autoptimize_cache_nogzip', 'label' => 'Save aggregated script/css as static files', 'type' => 'toggle' ),
						array( 'key' => 'autoptimize_minify_excluded', 'label' => 'Minify excluded CSS and JS files', 'type' => 'toggle' ),
						array( 'key' => 'autoptimize_cache_fallback', 'label' => 'Enable 404 fallbacks', 'type' => 'toggle' ),
						array( 'key' => 'autoptimize_optimize_logged', 'label' => 'Also optimize for logged-in users', 'type' => 'toggle' ),
						array( 'key' => 'autoptimize_optimize_checkout', 'label' => 'Also optimize shop cart/checkout', 'type' => 'toggle' ),
						array( 'key' => 'autoptimize_enable_meta_ao_settings', 'label' => 'Enable configuration per post/page', 'type' => 'toggle' ),
					),
				),
			),
		),
	);
}

/**
 * Flat map of key => field def (with type) across all tabs.
 *
 * @return array
 */
function minn_admin_autoptimize_fields_by_key() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}
	$map = array();
	foreach ( minn_admin_autoptimize_schema() as $tab ) {
		foreach ( $tab['groups'] as $group ) {
			foreach ( $group['fields'] as $f ) {
				$map[ $f['key'] ] = $f;
			}
		}
	}
	return $map;
}

/**
 * Read one option through Autoptimize's wrapper when available.
 *
 * @param string $key Option name.
 * @return mixed
 */
function minn_admin_autoptimize_get( $key ) {
	if ( class_exists( 'autoptimizeOptionWrapper', false ) ) {
		return autoptimizeOptionWrapper::get_option( $key, false );
	}
	return get_option( $key, false );
}

/**
 * Write one option through Autoptimize's wrapper when available.
 *
 * @param string $key   Option name.
 * @param mixed  $value Value.
 */
function minn_admin_autoptimize_set( $key, $value ) {
	if ( class_exists( 'autoptimizeOptionWrapper', false ) ) {
		autoptimizeOptionWrapper::update_option( $key, $value );
		return;
	}
	update_option( $key, $value );
}

/**
 * Current display value for a field.
 *
 * @param array $field Field def.
 * @return mixed
 */
function minn_admin_autoptimize_value( $field ) {
	$raw = minn_admin_autoptimize_get( $field['key'] );
	if ( 'toggle' === $field['type'] ) {
		// Stored as 'on', 1, true when on; false/''/0/missing when off.
		return ! empty( $raw ) && '0' !== (string) $raw && 'off' !== (string) $raw && 'false' !== (string) $raw;
	}
	if ( false === $raw || null === $raw ) {
		return '';
	}
	return is_scalar( $raw ) ? (string) $raw : '';
}

/**
 * GET/POST shape for one tab.
 *
 * @param string $tab_id Tab id.
 * @return array|WP_Error
 */
function minn_admin_autoptimize_tab_shape( $tab_id ) {
	$schema = minn_admin_autoptimize_schema();
	if ( ! isset( $schema[ $tab_id ] ) ) {
		return new WP_Error( 'minn_no_tab', 'Unknown settings tab.', array( 'status' => 404 ) );
	}
	$tab    = $schema[ $tab_id ];
	$groups = array();
	$values = array();
	foreach ( $tab['groups'] as $group ) {
		$fields = array();
		foreach ( $group['fields'] as $f ) {
			$out = array(
				'key'   => $f['key'],
				'label' => $f['label'],
				'type'  => $f['type'],
			);
			if ( ! empty( $f['help'] ) ) {
				$out['help'] = $f['help'];
			}
			if ( 'textarea' === $f['type'] ) {
				$out['rows'] = 6;
				$out['mono'] = true;
			}
			$fields[]            = $out;
			$values[ $f['key'] ] = minn_admin_autoptimize_value( $f );
		}
		$groups[] = array(
			'title'  => $group['title'],
			'fields' => $fields,
			'locked' => 0,
		);
	}
	return array(
		'groups'   => $groups,
		'values'   => $values,
		'adminUrl' => admin_url( 'options-general.php?page=autoptimize' ),
	);
}

/**
 * Save edited keys. Unknown keys are ignored. Toggles store 'on' / ''.
 *
 * @param array $values Key => value map from the client.
 * @return true
 */
function minn_admin_autoptimize_save( $values ) {
	$by_key = minn_admin_autoptimize_fields_by_key();
	foreach ( (array) $values as $key => $v ) {
		if ( ! isset( $by_key[ $key ] ) ) {
			continue;
		}
		$type = $by_key[ $key ]['type'];
		if ( 'toggle' === $type ) {
			$on = ! empty( $v ) && 'false' !== $v && '0' !== (string) $v && 'off' !== (string) $v;
			minn_admin_autoptimize_set( $key, $on ? 'on' : '' );
		} else {
			$minn_val = is_scalar( $v ) ? (string) $v : '';
			// Critical CSS paste: strip tags like AO's own sanitizer path.
			if ( 'autoptimize_css_defer_inline' === $key && class_exists( 'autoptimizeStyles', false ) && method_exists( 'autoptimizeStyles', 'sanitize_css' ) ) {
				$minn_val = autoptimizeStyles::sanitize_css( $minn_val );
			}
			minn_admin_autoptimize_set( $key, $minn_val );
		}
	}
	return true;
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_autoptimize_active() ) {
		return $surfaces;
	}
	$tabs = array();
	foreach ( minn_admin_autoptimize_schema() as $id => $tab ) {
		$tabs[] = array( 'id' => $id, 'label' => $tab['label'] );
	}
	$surfaces['autoptimize'] = array(
		'label'    => 'Performance',
		'sub'      => 'Autoptimize',
		'family'   => 'performance',
		'icon'     => 'gear',
		'cap'      => 'manage_options',
		'settings' => array(
			'label' => 'Settings',
			'tabs'  => $tabs,
			'route' => 'minn-admin/v1/autoptimize/settings/{tab}',
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_autoptimize_active() ) {
		return;
	}
	register_rest_route( 'minn-admin/v1', '/autoptimize/settings/(?P<tab>[a-z]+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => function ( $req ) {
				return rest_ensure_response( minn_admin_autoptimize_tab_shape( $req['tab'] ) );
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
				minn_admin_autoptimize_save( $values );
				return rest_ensure_response( minn_admin_autoptimize_tab_shape( $req['tab'] ) );
			},
		),
	) );
} );
