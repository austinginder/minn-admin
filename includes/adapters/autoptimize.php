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
 * and Image tabs stay one click away in wp-admin. The screen is declared to
 * the settings tier, which reads and writes each option itself.
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
			'label'  => __( 'JavaScript', 'minn-admin' ),
			'groups' => array(
				array(
					'title'  => __( 'JavaScript options', 'minn-admin' ),
					'fields' => array(
						array( 'key' => 'autoptimize_js', 'label' => __( 'Optimize JavaScript code', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'key' => 'autoptimize_js_aggregate', 'label' => __( 'Aggregate JS files', 'minn-admin' ), 'type' => 'toggle', 'help' => 'Combine JS into fewer files. Mutually exclusive with "Defer non-aggregated JS".' ),
						array( 'key' => 'autoptimize_js_include_inline', 'label' => __( 'Also aggregate inline JS', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'key' => 'autoptimize_js_forcehead', 'label' => __( 'Force JavaScript in head', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'key' => 'autoptimize_js_trycatch', 'label' => __( 'Add try-catch wrapping', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'key' => 'autoptimize_js_defer_not_aggregate', 'label' => __( 'Do not aggregate but defer', 'minn-admin' ), 'type' => 'toggle', 'help' => 'Defer individual scripts instead of combining them.' ),
						array( 'key' => 'autoptimize_js_defer_inline', 'label' => __( 'Also defer inline JS', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'key' => 'autoptimize_js_exclude', 'label' => __( 'Exclude scripts from Autoptimize', 'minn-admin' ), 'type' => 'text', 'help' => 'Comma-separated filename or path fragments.' ),
					),
				),
			),
		),
		'css'  => array(
			'label'  => 'CSS',
			'groups' => array(
				array(
					'title'  => __( 'CSS options', 'minn-admin' ),
					'fields' => array(
						array( 'key' => 'autoptimize_css', 'label' => __( 'Optimize CSS code', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'key' => 'autoptimize_css_aggregate', 'label' => __( 'Aggregate CSS files', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'key' => 'autoptimize_css_include_inline', 'label' => __( 'Also aggregate inline CSS', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'key' => 'autoptimize_css_datauris', 'label' => __( 'Generate data: URIs for images', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'key' => 'autoptimize_css_inline', 'label' => __( 'Inline all CSS', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'key' => 'autoptimize_css_defer', 'label' => __( 'Eliminate render-blocking CSS', 'minn-admin' ), 'type' => 'toggle', 'help' => 'Defers CSS load; paste critical CSS below or use the Critical CSS tab in Autoptimize.' ),
						array( 'key' => 'autoptimize_css_defer_inline', 'label' => __( 'Inline critical CSS', 'minn-admin' ), 'type' => 'textarea' ),
						array( 'key' => 'autoptimize_css_exclude', 'label' => __( 'Exclude CSS from Autoptimize', 'minn-admin' ), 'type' => 'text' ),
					),
				),
			),
		),
		'html' => array(
			'label'  => 'HTML',
			'groups' => array(
				array(
					'title'  => __( 'HTML options', 'minn-admin' ),
					'fields' => array(
						array( 'key' => 'autoptimize_html', 'label' => __( 'Optimize HTML code', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'key' => 'autoptimize_html_minify_inline', 'label' => __( 'Minify inline JS and CSS', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'key' => 'autoptimize_html_keepcomments', 'label' => __( 'Keep HTML comments', 'minn-admin' ), 'type' => 'toggle' ),
					),
				),
			),
		),
		'cdn'  => array(
			'label'  => 'CDN',
			'groups' => array(
				array(
					'title'  => __( 'CDN options', 'minn-admin' ),
					'fields' => array(
						array( 'key' => 'autoptimize_cdn_url', 'label' => __( 'CDN base URL', 'minn-admin' ), 'type' => 'text', 'help' => 'Leave empty to serve optimized files from this site. Example: //cdn.example.com/' ),
					),
				),
			),
		),
		'misc' => array(
			'label'  => __( 'Misc', 'minn-admin' ),
			'groups' => array(
				array(
					'title'  => __( 'Misc options', 'minn-admin' ),
					'fields' => array(
						array( 'key' => 'autoptimize_cache_nogzip', 'label' => __( 'Save aggregated script/css as static files', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'key' => 'autoptimize_minify_excluded', 'label' => __( 'Minify excluded CSS and JS files', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'key' => 'autoptimize_cache_fallback', 'label' => __( 'Enable 404 fallbacks', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'key' => 'autoptimize_optimize_logged', 'label' => __( 'Also optimize for logged-in users', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'key' => 'autoptimize_optimize_checkout', 'label' => __( 'Also optimize shop cart/checkout', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'key' => 'autoptimize_enable_meta_ao_settings', 'label' => __( 'Enable configuration per post/page', 'minn-admin' ), 'type' => 'toggle' ),
					),
				),
			),
		),
	);
}

/**
 * One tab as offered, or null for a tab that does not exist. Each setting is
 * its own option, a switch stored as 'on' or '' as Autoptimize's form posts it.
 *
 * @param string $tab_id Tab id.
 * @return array|null { groups, adminUrl }
 */
function minn_admin_autoptimize_layout( $tab_id ) {
	$schema = minn_admin_autoptimize_schema();
	if ( ! isset( $schema[ $tab_id ] ) ) {
		return null;
	}
	$groups = array();
	// Network-wide, these are the network's settings and only Network Admin
	// writes them: a save here would land in a per-site option Autoptimize
	// never reads, so every field is locked with the way there, and a save
	// is refused rather than answered as saved.
	$network = minn_admin_autoptimize_network_wide();
	$why     = __( 'Autoptimize runs on the network’s settings here, so change them in Network Admin.', 'minn-admin' );
	foreach ( $schema[ $tab_id ]['groups'] as $group ) {
		if ( $network ) {
			$groups[] = array(
				'title'  => $group['title'],
				'desc'   => $groups ? '' : $why,
				'fields' => array(),
				'locked' => count( $group['fields'] ),
			);
			continue;
		}
		foreach ( $group['fields'] as $i => $f ) {
			$f += 'textarea' === $f['type'] ? array( 'rows' => 6, 'mono' => true ) : array();
			// Read and written by plain option calls: Autoptimize's wrapper
			// reads and writes the site's own options whenever it is not
			// network-wide.
			$group['fields'][ $i ] = $f + array( '_option' => $f['key'], '_on' => 'on' );
		}
		$groups[] = $group + array( 'locked' => 0 );
	}
	return array(
		'groups'   => $groups,
		'adminUrl' => $network ? network_admin_url( 'settings.php?page=autoptimize' ) : admin_url( 'options-general.php?page=autoptimize' ),
		'closed'   => $network ? new WP_Error( 'minn_autoptimize_network', $why, array( 'status' => 409 ) ) : null,
	);
}

/**
 * Whether the current user may read and change Autoptimize's configuration.
 *
 * Autoptimize answers this itself, and the answer moves. Turned on across a
 * network with per-site configuration switched off, the settings ARE the
 * network's: their own per-site page degrades to a notice pointing at the
 * network admin, and their option reader quietly starts reading the network's
 * values. Asking only whether someone manages this site then handed a single
 * site's administrator the whole network's configuration, and took edits that
 * their reader would never look at again.
 *
 * @return bool
 */
function minn_admin_autoptimize_can() {
	if ( minn_admin_autoptimize_network_wide() ) {
		// Asked where core's network admin asks it: on the main site, so a
		// network capability one subsite's role grants counts for nothing.
		return function_exists( 'minn_admin_network_user_can' )
			? minn_admin_network_user_can( 'manage_network_options' )
			: Minn_Admin::network_owner();
	}
	return current_user_can( 'manage_options' );
}

/**
 * Whether Autoptimize runs on the network's settings: network-active with
 * per-site configuration off. Its reader then returns the network's values,
 * and its writer reaches them only from Network Admin (any other write lands
 * in a per-site option it never reads).
 *
 * @return bool
 */
function minn_admin_autoptimize_network_wide() {
	if ( ! class_exists( 'autoptimizeOptionWrapper', false )
		|| ! method_exists( 'autoptimizeOptionWrapper', 'is_ao_active_for_network' ) ) {
		return false;
	}
	try {
		return autoptimizeOptionWrapper::is_ao_active_for_network()
			&& 'on' !== autoptimizeOptionWrapper::get_option( 'autoptimize_enable_site_config' );
	} catch ( \Throwable $e ) {
		return false;
	}
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_autoptimize_active() || ! minn_admin_autoptimize_can() ) {
		return $surfaces;
	}
	$tabs = array();
	foreach ( minn_admin_autoptimize_schema() as $id => $tab ) {
		$tabs[] = array( 'id' => $id, 'label' => $tab['label'] );
	}
	$surfaces['autoptimize'] = array(
		'label'    => __( 'Performance', 'minn-admin' ),
		'sub'      => 'Autoptimize',
		'plugin'     => 'autoptimize',
		'family'   => 'performance',
		'icon'     => 'gear',
		// Their answer is a resolver, not a capability name; the guard above
		// is the real gate (the UpdraftPlus precedent).
		'cap'      => 'read',
		'settings' => array(
			'label' => __( 'Settings', 'minn-admin' ),
			'tabs'  => $tabs,
			'route' => 'minn-admin/v1/autoptimize/settings/{tab}',
		),
	);
	return $surfaces;
} );

minn_admin_settings( 'autoptimize', array(
	'route'    => 'autoptimize/settings/(?P<tab>[a-z]+)',
	'active'   => 'minn_admin_autoptimize_active',
	'gate'     => 'minn_admin_autoptimize_can',
	'layout'   => function ( $ctx ) {
		return minn_admin_autoptimize_layout( $ctx['tab'] );
	},
	// Critical CSS goes into a style tag on every page: tags stripped, as
	// Autoptimize's own sanitizer strips them.
	'validate' => function ( $changes ) {
		if ( isset( $changes['autoptimize_css_defer_inline'] ) && class_exists( 'autoptimizeStyles' ) && method_exists( 'autoptimizeStyles', 'sanitize_css' ) ) {
			$v = $changes['autoptimize_css_defer_inline'];
			$changes['autoptimize_css_defer_inline'] = autoptimizeStyles::sanitize_css( is_scalar( $v ) ? (string) $v : '' );
		}
		return $changes;
	},
) );
