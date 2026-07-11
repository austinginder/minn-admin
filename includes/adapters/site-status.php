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
 * array of providers, each { name, kind, note?, url? } where kind is
 * 'maintenance' | 'coming-soon' | 'password'. Only register a provider while
 * its mode is actually ACTIVE (this runs on every admin pageload).
 */

defined( 'ABSPATH' ) || exit;

/**
 * Assemble the current visibility state.
 *
 * @return array {
 *   state:             'public' | 'hidden' | 'password' | 'search-discouraged'
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
			'note' => 'Visitors get a 503 holding page',
			'minn' => true, // toggled in Minn's own Settings, not a wp-admin screen
		);
	}

	// WP Maintenance Mode (designmodo, 700k+): wpmm_settings.general.status.
	if ( class_exists( 'WP_Maintenance_Mode' ) ) {
		$s = get_option( 'wpmm_settings' );
		if ( is_array( $s ) && ! empty( $s['general']['status'] ) ) {
			$providers[] = array(
				'name' => 'WP Maintenance Mode',
				'kind' => 'maintenance',
				'url'  => admin_url( 'admin.php?page=wp-maintenance-mode' ),
			);
		}
	}

	// SeedProd coming-soon / maintenance (1M+). Lite stores the enabled mode in
	// seedprod_settings; guard on the option shape so a miss just no-ops.
	if ( defined( 'SEEDPROD_VERSION' ) || defined( 'SEEDPROD_PRO_VERSION' ) ) {
		$s = get_option( 'seedprod_settings' );
		$mode = is_array( $s ) ? ( isset( $s['coming_soon'] ) ? (int) $s['coming_soon'] : ( isset( $s['maintenance_mode'] ) ? (int) $s['maintenance_mode'] : 0 ) ) : 0;
		if ( $mode ) {
			$providers[] = array(
				'name' => 'SeedProd',
				'kind' => isset( $s['maintenance_mode'] ) && $s['maintenance_mode'] ? 'maintenance' : 'coming-soon',
				'url'  => admin_url( 'admin.php?page=seedprod_lite' ),
			);
		}
	}

	// Under Construction (WebFactory, 200k+): option 'ucp' with ['status'].
	$ucp = get_option( 'ucp' );
	if ( is_array( $ucp ) && ! empty( $ucp['status'] ) ) {
		$providers[] = array(
			'name' => 'Under Construction',
			'kind' => 'coming-soon',
			'url'  => admin_url( 'admin.php?page=under-construction-page' ),
		);
	}

	// Password Protected (Ben Huson, 300k+): whole site behind a password.
	if ( class_exists( 'Password_Protected' ) && get_option( 'password_protected_status' ) ) {
		$providers[] = array(
			'name' => 'Password Protected',
			'kind' => 'password',
			'note' => 'The whole site is behind a password',
			'url'  => admin_url( 'options-reading.php' ),
		);
	}

	/**
	 * Register a maintenance/coming-soon/password provider while its mode is
	 * active. See docs/for-plugin-authors.md.
	 */
	$providers = apply_filters( 'minn_admin_visibility_providers', $providers );

	// Normalize + keep only well-formed, currently-active entries.
	$clean = array();
	foreach ( (array) $providers as $p ) {
		if ( ! is_array( $p ) || empty( $p['name'] ) || empty( $p['kind'] ) ) {
			continue;
		}
		$kind = in_array( $p['kind'], array( 'maintenance', 'coming-soon', 'password' ), true ) ? $p['kind'] : 'maintenance';
		$clean[] = array(
			'name' => (string) $p['name'],
			'kind' => $kind,
			'note' => isset( $p['note'] ) ? (string) $p['note'] : '',
			'url'  => isset( $p['url'] ) ? esc_url_raw( (string) $p['url'] ) : '',
			'minn' => ! empty( $p['minn'] ),
		);
	}

	$blocking = array_filter( $clean, function ( $p ) {
		return 'maintenance' === $p['kind'] || 'coming-soon' === $p['kind'];
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
			'label'  => 'Site visibility',
			'status' => 'warn',
			'detail' => 'Hidden from the public by ' . implode( ', ', $names ) . ' — visitors cannot see the site',
		);
	}
	if ( 'password' === $v['state'] ) {
		return array(
			'label'  => 'Site visibility',
			'status' => 'warn',
			'detail' => 'The whole site is password-protected (' . implode( ', ', $names ) . ')',
		);
	}
	if ( 'search-discouraged' === $v['state'] ) {
		return array(
			'label'  => 'Site visibility',
			'status' => 'warn',
			'detail' => 'Search engines are discouraged (Settings → Reading) — the site is public but asks not to be indexed',
		);
	}
	return array(
		'label'  => 'Site visibility',
		'status' => 'pass',
		'detail' => 'Public and indexable',
	);
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
			'label'  => 'SSL enforcement',
			'status' => 'warn',
			'detail' => 'Really Simple SSL is installed but SSL is not enabled yet — finish its setup',
		);
	}
	$fixer = (bool) rsssl_get_option( 'mixed_content_fixer' );
	return array(
		'label'  => 'SSL enforcement',
		'status' => 'pass',
		'detail' => 'Really Simple SSL is enforcing HTTPS' . ( $fixer ? ', mixed-content fixer on' : '' ),
	);
}
