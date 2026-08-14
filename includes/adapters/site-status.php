<?php
/**
 * Site visibility posture — "is my site actually reachable by the public?"
 *
 * A site can be silently invisible in several ways: a maintenance/coming-soon
 * plugin, a whole-site password gate, or "discourage search engines" left on.
 * Owners forget these are on and wonder why traffic dried up. This reports the
 * state read-only from local options, so Minn can warn on the Overview page
 * and the System health strip.
 *
 * Third parties add detectors via `minn_admin_visibility_providers`: return an
 * array of providers, each { name, kind, note?, url?, partial?, id? } where
 * kind is 'maintenance' | 'coming-soon' | 'password'. Set partial when only
 * SOME pages are covered (WooCommerce's store-pages-only coming soon) — the
 * warning then says "part of the site" instead of claiming the whole site is
 * dark. Only register a provider while its mode is actually ACTIVE (this runs
 * on every admin pageload).
 *
 * Providers with a matching writer in `minn_admin_visibility_toggles` can be
 * turned off (and back on, for Undo) from Minn's banner, chip popover and
 * Settings → Visibility; the rest stay honest link-outs.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Read an option's stored row directly, bypassing pre_option filters. Only
 * for detectors whose plugin masks its own option per request context
 * (Password Protected's allow_administrators / allow_users).
 *
 * @param string $name Option name.
 * @return string|null Raw option_value, null when the row is absent.
 */
function minn_admin_raw_option( $name ) {
	global $wpdb;
	return $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );
}

/**
 * Toggle writers — which visibility providers Minn can turn off (and back on,
 * for Undo). Detected at CALL TIME for loaded plugins regardless of the mode's
 * current state: an Undo must re-arm a provider that no longer appears in the
 * active list. Each writer goes through the plugin's own storage shape, never
 * a reimplementation of its logic.
 *
 * Contract (documented in for-plugin-authors.md): id => { set: callable }
 * where set( bool $on, array $ctx ) flips the mode; $ctx['kind'] carries the
 * mode that was active before Minn turned it off, so writers with more than
 * one mode (SeedProd, Elementor) restore exactly what was on. Third parties
 * join via the `minn_admin_visibility_toggles` filter and put the matching
 * `id` on their provider row.
 *
 * @return array id => array( 'set' => callable( bool $on, array $ctx ) )
 */
function minn_admin_visibility_toggles() {
	$t = array();

	if ( class_exists( 'WP_Maintenance_Mode' ) ) {
		$t['wpmm'] = array( 'set' => function ( $on ) {
			$s = get_option( 'wpmm_settings' );
			if ( ! is_array( $s ) ) {
				$s = array();
			}
			if ( ! isset( $s['general'] ) || ! is_array( $s['general'] ) ) {
				$s['general'] = array();
			}
			$s['general']['status'] = $on ? 1 : 0;
			update_option( 'wpmm_settings', $s );
		} );
	}

	if ( defined( 'SEEDPROD_VERSION' ) || defined( 'SEEDPROD_PRO_VERSION' ) ) {
		$t['seedprod'] = array( 'set' => function ( $on, $ctx = array() ) {
			$s = get_option( 'seedprod_settings' );
			if ( is_string( $s ) ) {
				$s = json_decode( $s, true );
			}
			if ( ! is_array( $s ) ) {
				$s = array();
			}
			$maint = isset( $ctx['kind'] ) && 'maintenance' === $ctx['kind'];
			$s['enable_coming_soon_mode']  = $on && ! $maint;
			$s['enable_maintenance_mode']  = $on && $maint;
			// Their v5+ save path stores the blob as a JSON string.
			update_option( 'seedprod_settings', wp_json_encode( $s ) );
		} );
	}

	if ( class_exists( 'MTNC' ) ) {
		$t['maintenance'] = array( 'set' => function ( $on ) {
			$s = get_option( 'mtnc-options' );
			if ( ! is_array( $s ) ) {
				$s = array();
			}
			if ( ! isset( $s['options'] ) || ! is_array( $s['options'] ) ) {
				$s['options'] = array();
			}
			$s['options']['status'] = $on ? '1' : '0';
			update_option( 'mtnc-options', $s );
		} );
	}

	if ( class_exists( 'CMP_Coming_Soon_and_Maintenance' ) ) {
		// niteoCS_activation (which mode) persists independently of the on
		// switch, so off/on round-trips the mode without touching it.
		$t['cmp'] = array( 'set' => function ( $on ) {
			update_option( 'niteoCS_status', $on ? '1' : '0' );
		} );
	}

	if ( function_exists( 'csmm_get_options' ) ) {
		$t['csmm'] = array( 'set' => function ( $on ) {
			// '2' is their stored disabled value, not '0' or false.
			update_option( 'signals_csmm_options', array_merge( (array) get_option( 'signals_csmm_options', array() ), array( 'status' => $on ? '1' : '2' ) ) );
		} );
	}

	if ( class_exists( 'UCP' ) ) {
		$t['ucp'] = array( 'set' => function ( $on ) {
			update_option( 'ucp', array_merge( (array) get_option( 'ucp', array() ), array( 'status' => $on ? '1' : '0' ) ) );
		} );
	}

	if ( class_exists( 'WooCommerce' ) ) {
		// woocommerce_store_pages_only is left alone, so a partial coming
		// soon undoes back to partial.
		$t['wc'] = array( 'set' => function ( $on ) {
			update_option( 'woocommerce_coming_soon', $on ? 'yes' : 'no' );
		} );
	}

	if ( defined( 'ELEMENTOR_VERSION' ) ) {
		$t['elementor'] = array( 'set' => function ( $on, $ctx = array() ) {
			$mode = isset( $ctx['kind'] ) && 'maintenance' === $ctx['kind'] ? 'maintenance' : 'coming_soon';
			update_option( 'elementor_maintenance_mode_mode', $on ? $mode : '' );
		} );
	}

	if ( class_exists( 'Password_Protected' ) ) {
		$t['password-protected'] = array( 'set' => function ( $on ) {
			update_option( 'password_protected_status', $on ? 1 : 0 );
		} );
	}

	/**
	 * Register a writer for a provider your plugin reports via
	 * `minn_admin_visibility_providers`. See docs/for-plugin-authors.md.
	 */
	$t = apply_filters( 'minn_admin_visibility_toggles', $t );

	$clean = array();
	foreach ( (array) $t as $id => $w ) {
		if ( is_array( $w ) && isset( $w['set'] ) && is_callable( $w['set'] ) ) {
			$clean[ sanitize_key( (string) $id ) ] = $w;
		}
	}
	return $clean;
}

/**
 * Assemble the current visibility state.
 *
 * @return array {
 *   state:             'public' | 'hidden' | 'password' | 'partial' | 'search-discouraged'
 *   public:            bool
 *   searchDiscouraged: bool
 *   providers:         array of { name, kind, note, url }
 * }
 */
function minn_admin_site_visibility() {
	$providers = array();

	// Minn's own maintenance mode (class-minn-admin.php serves a 503 page).
	if ( get_option( 'minn_admin_maintenance' ) ) {
		$providers[] = array(
			'name' => 'Minn maintenance mode',
			'kind' => 'maintenance',
			'note' => __( 'Visitors get a 503 holding page', 'minn-admin' ),
			'minn' => true, // toggled in Minn's own Settings, not a wp-admin screen
		);
	}

	// LightStart, formerly WP Maintenance Mode (Themeisle, 400k+):
	// wpmm_settings.general.status.
	if ( class_exists( 'WP_Maintenance_Mode' ) ) {
		$s = get_option( 'wpmm_settings' );
		if ( is_array( $s ) && ! empty( $s['general']['status'] ) ) {
			$providers[] = array(
				'name' => 'LightStart (WP Maintenance Mode)',
				'id'   => 'wpmm',
				'kind' => 'maintenance',
				'url'  => admin_url( 'admin.php?page=wp-maintenance-mode' ),
			);
		}
	}

	// SeedProd coming-soon / maintenance (700k+). Since v5 seedprod_settings is
	// a JSON STRING ({"enable_coming_soon_mode":bool,"enable_maintenance_mode":
	// bool,...} — their render-csp-mm.php json_decodes it); tolerate an array
	// from older builds. Login/404 modes don't hide the site, so skip them.
	if ( defined( 'SEEDPROD_VERSION' ) || defined( 'SEEDPROD_PRO_VERSION' ) ) {
		$s = get_option( 'seedprod_settings' );
		if ( is_string( $s ) ) {
			$s = json_decode( $s, true );
		}
		if ( is_array( $s ) && ( ! empty( $s['enable_coming_soon_mode'] ) || ! empty( $s['enable_maintenance_mode'] ) ) ) {
			$providers[] = array(
				'name' => 'SeedProd',
				'id'   => 'seedprod',
				'kind' => ! empty( $s['enable_maintenance_mode'] ) ? 'maintenance' : 'coming-soon',
				'url'  => admin_url( 'admin.php?page=seedprod_' . ( defined( 'SEEDPROD_BUILD' ) ? SEEDPROD_BUILD : 'lite' ) ),
			);
		}
	}

	// Maintenance (WebFactory, 1M+): option 'mtnc-options', the live flag is
	// ['options']['status'] ('1'/'0' strings — their frontend gate at
	// maintenance.php reads exactly this).
	if ( class_exists( 'MTNC' ) ) {
		$s = get_option( 'mtnc-options' );
		if ( is_array( $s ) && isset( $s['options'] ) && is_array( $s['options'] ) && ! empty( $s['options']['status'] ) && '0' !== (string) $s['options']['status'] ) {
			$providers[] = array(
				'name' => 'Maintenance',
				'id'   => 'maintenance',
				'kind' => 'maintenance',
				'url'  => admin_url( 'admin.php?page=maintenance' ),
			);
		}
	}

	// CMP — Coming Soon & Maintenance (NiteoThemes, 200k+): niteoCS_status is
	// the on switch ('0' off, anything else on — mirrors their cmp_active());
	// niteoCS_activation picks the mode ('1' = maintenance/503, else the
	// coming-soon page; default '2').
	if ( class_exists( 'CMP_Coming_Soon_and_Maintenance' ) ) {
		if ( '0' !== (string) get_option( 'niteoCS_status', '0' ) ) {
			$providers[] = array(
				'name' => 'CMP Coming Soon & Maintenance',
				'id'   => 'cmp',
				'kind' => '1' === (string) get_option( 'niteoCS_activation', '2' ) ? 'maintenance' : 'coming-soon',
				'url'  => admin_url( 'admin.php?page=cmp-settings' ),
			);
		}
	}

	// Minimal Coming Soon (WebFactory, 100k+): signals_csmm_options['status']
	// is '1' when enabled ('2' is their disabled default — not a boolean).
	if ( function_exists( 'csmm_get_options' ) ) {
		$s = get_option( 'signals_csmm_options' );
		if ( is_array( $s ) && isset( $s['status'] ) && '1' === (string) $s['status'] ) {
			$providers[] = array(
				'name' => 'Minimal Coming Soon',
				'id'   => 'csmm',
				'kind' => 'coming-soon',
				'url'  => admin_url( 'options-general.php?page=maintenance_mode_options' ),
			);
		}
	}

	// Under Construction (WebFactory, 600k+): option 'ucp' with ['status']
	// ('1'/'0' strings). Class-gated: a deactivated UCP with a stale status
	// row isn't hiding anything.
	$ucp = class_exists( 'UCP' ) ? get_option( 'ucp' ) : null;
	if ( is_array( $ucp ) && ! empty( $ucp['status'] ) ) {
		$providers[] = array(
			'name' => 'Under Construction',
			'id'   => 'ucp',
			'kind' => 'coming-soon',
			'url'  => admin_url( 'admin.php?page=under-construction-page' ),
		);
	}

	// WooCommerce coming soon — the "launch your store" flow (WC 9.1+) many
	// stores never finish. Plain yes/no options; store-pages-only hides just
	// the shop, so it's flagged partial and the copy softens.
	if ( class_exists( 'WooCommerce' ) && 'yes' === get_option( 'woocommerce_coming_soon' ) ) {
		$store_only  = 'yes' === get_option( 'woocommerce_store_pages_only' );
		$providers[] = array(
			'name'    => 'WooCommerce coming soon',
			'id'      => 'wc',
			'kind'    => 'coming-soon',
			'note'    => $store_only ? 'Only store pages are hidden; the rest of the site is public' : 'Visitors see the coming-soon page instead of the site',
			'url'     => admin_url( 'admin.php?page=wc-settings&tab=site-visibility' ),
			'partial' => $store_only,
		);
	}

	// Elementor maintenance mode (Tools → Maintenance Mode):
	// elementor_maintenance_mode_mode is '' | 'coming_soon' | 'maintenance'.
	if ( defined( 'ELEMENTOR_VERSION' ) ) {
		$el_mode = get_option( 'elementor_maintenance_mode_mode' );
		if ( 'maintenance' === $el_mode || 'coming_soon' === $el_mode ) {
			$providers[] = array(
				'name' => 'Elementor maintenance mode',
				'id'   => 'elementor',
				'kind' => 'maintenance' === $el_mode ? 'maintenance' : 'coming-soon',
				'url'  => admin_url( 'admin.php?page=elementor-tools#tab-maintenance_mode' ),
			);
		}
	}

	// Password Protected (Ben Huson, 300k+): whole site behind a password.
	// Read the RAW row, not get_option: their pre_option filters zero the
	// value for logged-in admins outside wp-admin (allow_administrators /
	// allow_users), and REST requests are ! is_admin() — a plain get_option
	// here reports "public" while visitors are still gated.
	if ( class_exists( 'Password_Protected' ) && minn_admin_raw_option( 'password_protected_status' ) ) {
		$providers[] = array(
			'name' => 'Password Protected',
			'id'   => 'password-protected',
			'kind' => 'password',
			'note' => __( 'The whole site is behind a password', 'minn-admin' ),
			'url'  => admin_url( 'options-reading.php' ),
		);
	}

	/**
	 * Register a maintenance/coming-soon/password provider while its mode is
	 * active. See docs/for-plugin-authors.md.
	 */
	$providers = apply_filters( 'minn_admin_visibility_providers', $providers );

	// Normalize + keep only well-formed, currently-active entries. `can` marks
	// rows a registered writer can turn off from Minn (manage_options only —
	// the visibility endpoint carries the same gate).
	$toggles = minn_admin_visibility_toggles();
	$can_fix = current_user_can( 'manage_options' );
	$clean   = array();
	foreach ( (array) $providers as $p ) {
		if ( ! is_array( $p ) || empty( $p['name'] ) || empty( $p['kind'] ) ) {
			continue;
		}
		$kind = in_array( $p['kind'], array( 'maintenance', 'coming-soon', 'password' ), true ) ? $p['kind'] : 'maintenance';
		$id   = isset( $p['id'] ) ? sanitize_key( (string) $p['id'] ) : '';
		$clean[] = array(
			'name'    => (string) $p['name'],
			'id'      => $id,
			'kind'    => $kind,
			'note'    => isset( $p['note'] ) ? (string) $p['note'] : '',
			'url'     => isset( $p['url'] ) ? esc_url_raw( (string) $p['url'] ) : '',
			'minn'    => ! empty( $p['minn'] ),
			'partial' => ! empty( $p['partial'] ),
			'can'     => $can_fix && '' !== $id && isset( $toggles[ $id ] ) && is_callable( $toggles[ $id ]['set'] ),
		);
	}

	$blocking = array_filter( $clean, function ( $p ) {
		return ( 'maintenance' === $p['kind'] || 'coming-soon' === $p['kind'] ) && ! $p['partial'];
	} );
	$partial  = array_filter( $clean, function ( $p ) {
		return ( 'maintenance' === $p['kind'] || 'coming-soon' === $p['kind'] ) && $p['partial'];
	} );
	$password  = array_filter( $clean, function ( $p ) {
		return 'password' === $p['kind'];
	} );
	// "Discourage search engines" — the Reading checkbox stores '0'/'1', but a
	// boolean write can land as '' ; match core's own (int) reading. Default 1
	// (public) when the row is absent.
	$discourage = 0 === (int) get_option( 'blog_public', 1 );

	if ( $blocking ) {
		$state = 'hidden';
	} elseif ( $password ) {
		$state = 'password';
	} elseif ( $partial ) {
		$state = 'partial';
	} elseif ( $discourage ) {
		$state = 'search-discouraged';
	} else {
		$state = 'public';
	}

	return array(
		'state'             => $state,
		'public'            => 'public' === $state,
		'searchDiscouraged' => $discourage,
		'providers'         => array_values( $clean ),
	);
}

/**
 * The System-page health check for site visibility. Hidden/coming-soon is a
 * hard warning (the public literally cannot see the site); a password gate or
 * "discourage search engines" is a softer note.
 *
 * @return array|null { label, status, detail } or null when fully public.
 */
function minn_admin_visibility_check() {
	$v     = minn_admin_site_visibility();
	$names = wp_list_pluck( $v['providers'], 'name' );

	if ( 'hidden' === $v['state'] ) {
		return array(
			'key'    => 'visibility',
			'label'  => __( 'Site visibility', 'minn-admin' ),
			'status' => 'warn',
			'detail' => __( 'Hidden from the public by ', 'minn-admin' ) . implode( ', ', $names ) . ' — visitors cannot see the site',
		);
	}
	if ( 'password' === $v['state'] ) {
		return array(
			'key'    => 'visibility',
			'label'  => __( 'Site visibility', 'minn-admin' ),
			'status' => 'warn',
			'detail' => __( 'The whole site is password-protected (', 'minn-admin' ) . implode( ', ', $names ) . ')',
		);
	}
	if ( 'partial' === $v['state'] ) {
		return array(
			'key'    => 'visibility',
			'label'  => __( 'Site visibility', 'minn-admin' ),
			'status' => 'warn',
			'detail' => __( 'Part of the site is hidden by ', 'minn-admin' ) . implode( ', ', $names ) . ' — some pages show a coming-soon page',
		);
	}
	if ( 'search-discouraged' === $v['state'] ) {
		return array(
			'key'    => 'visibility',
			'label'  => __( 'Site visibility', 'minn-admin' ),
			'status' => 'warn',
			'detail' => __( 'Search engines are discouraged (Settings → Reading) — the site is public but asks not to be indexed', 'minn-admin' ),
		);
	}
	// Fully public: no row. Like the backup/licenses checks, the visibility
	// check only appears when there's something to say (the banner and chip
	// likewise show nothing when the site is public).
	return null;
}

/**
 * Really Simple SSL enforcement row. The generic HTTPS check only proves the
 * CURRENT request is over TLS; this reports whether RSSSL is enforcing HTTPS
 * site-wide (redirects + optional mixed-content fixer). Read through RSSSL's
 * own accessor. Returns null when RSSSL is not loaded.
 *
 * @return array|null { label, status, detail }
 */
function minn_admin_rsssl_check() {
	if ( ! defined( 'rsssl_version' ) || ! function_exists( 'rsssl_get_option' ) ) {
		return null;
	}
	$enabled = (bool) rsssl_get_option( 'ssl_enabled' );
	if ( ! $enabled ) {
		return array(
			'label'  => __( 'SSL enforcement', 'minn-admin' ),
			'status' => 'warn',
			'detail' => __( 'Really Simple SSL is installed but SSL is not enabled yet — finish its setup', 'minn-admin' ),
		);
	}
	$fixer = (bool) rsssl_get_option( 'mixed_content_fixer' );
	return array(
		'label'  => __( 'SSL enforcement', 'minn-admin' ),
		'status' => 'pass',
		'detail' => __( 'Really Simple SSL is enforcing HTTPS', 'minn-admin' ) . ( $fixer ? ', mixed-content fixer on' : '' ),
	);
}

// Live visibility state — the boot payload is a snapshot, so the banner and
// topbar chip refetch this after a maintenance/search toggle to update
// without a full page reload.
add_action( 'rest_api_init', function () {
	register_rest_route(
		'minn-admin/v1',
		'/visibility',
		array(
			'methods'             => 'GET',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => function () {
				return rest_ensure_response( minn_admin_site_visibility() );
			},
		)
	);

	// Turn a provider's mode off (or back on, for Undo) through its registered
	// writer. Turning off remembers which mode was on (option map, capped) so
	// an Undo restores exactly it; the response is the fresh visibility state
	// so the client repaints in one round trip (license-action precedent).
	register_rest_route(
		'minn-admin/v1',
		'/visibility/toggle',
		array(
			'methods'             => 'POST',
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
			'callback'            => function ( $request ) {
				$id      = sanitize_key( (string) $request['id'] );
				$on      = ! empty( $request['on'] );
				$toggles = minn_admin_visibility_toggles();
				if ( '' === $id || ! isset( $toggles[ $id ] ) ) {
					return new WP_Error( 'minn_no_toggle', __( 'No visibility toggle is registered for that provider.', 'minn-admin' ), array( 'status' => 404 ) );
				}
				$mem = get_option( 'minn_admin_vis_restore', array() );
				if ( ! is_array( $mem ) ) {
					$mem = array();
				}
				$ctx = array();
				if ( $on ) {
					$ctx['kind'] = isset( $mem[ $id ] ) ? (string) $mem[ $id ] : '';
				} else {
					foreach ( minn_admin_site_visibility()['providers'] as $p ) {
						if ( $p['id'] === $id ) {
							$mem[ $id ] = $p['kind'];
							$mem        = array_slice( $mem, -20, null, true );
							update_option( 'minn_admin_vis_restore', $mem, false );
							break;
						}
					}
				}
				try {
					call_user_func( $toggles[ $id ]['set'], $on, $ctx );
				} catch ( \Throwable $e ) {
					return new WP_Error( 'minn_toggle_failed', $e->getMessage(), array( 'status' => 500 ) );
				}
				return rest_ensure_response( minn_admin_site_visibility() );
			},
		)
	);
} );
