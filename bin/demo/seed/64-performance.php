<?php
/**
 * Performance settings a careful small shop would pick: Perfmatters for the
 * site-wide cleanups, deferred scripts, lazy loading and Heartbeat limits;
 * Autoptimize for CSS and HTML; Asset CleanUp for minified CSS and the head
 * tags it removes. Nothing that touches the admin, Minn, the REST API or the
 * login: no JS delay, no login URL change, no CDN, REST left on, and
 * Autoptimize skips logged-in users and the cart and checkout.
 *
 * Performance Lab is left as it is: its screen lists the WordPress
 * performance feature plugins, and showing one as active would mean
 * downloading and activating a plugin.
 *
 * Storage facts worth knowing:
 * - Perfmatters keeps one perfmatters_options array, with nested arrays per
 *   section (assets, preload, lazyload, fonts) and one-per-line textareas
 *   stored as arrays. Only the keys below are written; the rest is kept.
 * - Autoptimize keeps one option per setting, 'on' or ''. Unset means its
 *   default, and "also optimize for logged-in users" defaults to on.
 * - Asset CleanUp keeps a JSON string in wpassetcleanup_settings and writes
 *   nothing until its settings are first saved; until then it runs on its
 *   defaults, which its Settings::getAllStored() supplies, so the merge
 *   starts from there.
 *
 * @package minn-admin
 */

return function ( Minn_Demo_Seed $demo ) {
	if ( function_exists( 'perfmatters_settings' ) || defined( 'PERFMATTERS_VERSION' ) ) {
		$pm = get_option( 'perfmatters_options', array() );
		$pm = is_array( $pm ) ? $pm : array();
		foreach ( array(
			'disable_emojis'                         => '1',
			'disable_embeds'                         => '1',
			'disable_xmlrpc'                         => '1',
			'remove_jquery_migrate'                  => '1',
			'hide_wp_version'                        => '1',
			'remove_rsd_link'                        => '1',
			'remove_shortlink'                       => '1',
			'disable_self_pingbacks'                 => '1',
			'disable_dashicons'                      => '1',
			'heartbeat_frequency'                    => '60',
			'limit_post_revisions'                   => '10',
			'autosave_interval'                      => '120',
			'disable_woocommerce_cart_fragmentation' => '1',
			'disable_woocommerce_status'             => '1',
		) as $key => $value ) {
			$pm[ $key ] = $value;
		}
		$sections = array(
			'assets'   => array(
				'defer_js'      => '1',
				'js_exclusions' => array( 'jquery.min.js' ),
			),
			'preload'  => array(
				'instant_page'    => '1',
				'critical_images' => '2',
				'dns_prefetch'    => array( '//analytics.example.com' ),
			),
			'lazyload' => array(
				'lazy_loading'               => '1',
				'exclude_leading_images'     => '2',
				'lazy_loading_iframes'       => '1',
				'youtube_preview_thumbnails' => '1',
				'image_dimensions'           => '1',
				'threshold'                  => '200px',
			),
			'fonts'    => array(
				'display_swap' => '1',
			),
		);
		foreach ( $sections as $section => $values ) {
			$pm[ $section ] = array_merge( isset( $pm[ $section ] ) && is_array( $pm[ $section ] ) ? $pm[ $section ] : array(), $values );
		}
		update_option( 'perfmatters_options', $pm );
		$demo->log( 'Perfmatters configured' );
	}

	if ( defined( 'AUTOPTIMIZE_PLUGIN_VERSION' ) ) {
		$ao = array(
			'autoptimize_html'                    => 'on',
			'autoptimize_html_keepcomments'       => '',
			'autoptimize_html_minify_inline'      => '',
			'autoptimize_js'                      => 'on',
			'autoptimize_js_aggregate'            => '',
			'autoptimize_js_defer_not_aggregate'  => 'on',
			'autoptimize_js_defer_inline'         => 'on',
			'autoptimize_js_include_inline'       => '',
			'autoptimize_js_forcehead'            => '',
			'autoptimize_js_trycatch'             => '',
			'autoptimize_js_exclude'              => 'wp-includes/js/dist/, wp-includes/js/tinymce/, js/jquery/jquery.js, js/jquery/jquery.min.js',
			'autoptimize_css'                     => 'on',
			'autoptimize_css_aggregate'           => 'on',
			'autoptimize_css_include_inline'      => 'on',
			'autoptimize_css_datauris'            => '',
			'autoptimize_css_inline'              => '',
			'autoptimize_css_defer'               => '',
			'autoptimize_css_exclude'             => 'wp-content/cache/, wp-content/uploads/, admin-bar.min.css, dashicons.min.css',
			'autoptimize_cdn_url'                 => '',
			'autoptimize_cache_nogzip'            => 'on',
			'autoptimize_minify_excluded'         => 'on',
			'autoptimize_cache_fallback'          => 'on',
			'autoptimize_optimize_logged'         => '',
			'autoptimize_optimize_checkout'       => '',
			'autoptimize_enable_meta_ao_settings' => 'on',
		);
		foreach ( $ao as $key => $value ) {
			update_option( $key, $value );
		}
		$demo->log( 'Autoptimize configured' );
	}

	if ( class_exists( '\WpAssetCleanUp\Settings' ) ) {
		$stored = ( new \WpAssetCleanUp\Settings() )->getAllStored( true );
		$stored = is_array( $stored ) ? $stored : array();
		foreach ( array(
			'minify_loaded_css'       => '1',
			'combine_loaded_css'      => '1',
			'combine_loaded_css_for'  => 'guests',
			'minify_loaded_js'        => '',
			'combine_loaded_js'       => '',
			'disable_emojis'          => '1',
			'disable_oembed'          => '1',
			'remove_wp_version'       => '1',
			'remove_generator_tag'    => '1',
			'remove_rsd_link'         => '1',
			'remove_wlw_link'         => '1',
			'remove_shortlink'        => '1',
			'remove_rest_api_link'    => '',
			'disable_xmlrpc'          => 'disable_all',
			'google_fonts_combine'    => '1',
			'google_fonts_preconnect' => '1',
			'google_fonts_remove'     => '',
			'test_mode'               => '',
			'dashboard_show'          => '1',
			'frontend_show'           => '',
			'hide_from_admin_bar'     => '',
		) as $key => $value ) {
			$stored[ $key ] = $value;
		}
		$json = wp_json_encode( $stored );
		if ( class_exists( '\WpAssetCleanUp\Misc' ) && method_exists( '\WpAssetCleanUp\Misc', 'addUpdateOption' ) ) {
			\WpAssetCleanUp\Misc::addUpdateOption( 'wpassetcleanup_settings', $json );
		} else {
			update_option( 'wpassetcleanup_settings', $json, false );
		}
		$demo->log( 'Asset CleanUp configured' );
	}
};
