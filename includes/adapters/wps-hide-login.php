<?php
/**
 * Bundled adapter: WPS Hide Login — one System health row.
 *
 * WPS Hide Login moves the login page to a custom slug and sends visitors who
 * ask for wp-login.php or wp-admin somewhere else. There is nothing to list or
 * act on, so the whole integration is a posture row naming the live login
 * address, where blocked requests land, and a warning when the slug is still
 * the default `login` (the first path credential-stuffing bots try after
 * wp-login.php).
 *
 * The address comes from wp_login_url(), which the plugin filters, so it is
 * exactly what the site serves (pretty and plain permalinks alike). The
 * redirect slug mirrors their private new_redirect_slug(): site option, then
 * the network default when network-activated, then '404'. Nothing is written;
 * the setting stays on their General Settings section.
 *
 * Cap: manage_options (their fields live on options-general.php and save
 * through register_setting( 'general' )). The System page already requires it.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_wps_hide_login_active() {
	return class_exists( '\WPS\WPS_Hide_Login\Plugin' ) && defined( 'WPS_HIDE_LOGIN_BASENAME' );
}

function minn_admin_wps_hide_login_network_active() {
	if ( ! is_multisite() ) {
		return false;
	}
	if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	return is_plugin_active_for_network( WPS_HIDE_LOGIN_BASENAME );
}

/** The slug their login page answers on, from their own login_url filter. */
function minn_admin_wps_hide_login_slug() {
	$url  = wp_login_url();
	$home = trailingslashit( home_url() );
	if ( 0 !== strpos( $url, $home ) ) {
		return '';
	}
	$rest = substr( $url, strlen( $home ) );
	$rest = ltrim( $rest, '?' );
	return trim( (string) strtok( $rest, '?&#' ), '/' );
}

function minn_admin_wps_hide_login_redirect_slug() {
	$slug = get_option( 'whl_redirect_admin' );
	if ( ! $slug && minn_admin_wps_hide_login_network_active() ) {
		$slug = get_site_option( 'whl_redirect_admin', '404' );
	}
	return $slug ? (string) $slug : '404';
}

/**
 * System health rows: [] when the plugin is not loaded or the viewer cannot
 * manage the setting.
 *
 * @return array[]
 */
function minn_admin_wps_hide_login_checks() {
	if ( ! minn_admin_wps_hide_login_active() || ! current_user_can( 'manage_options' ) ) {
		return array();
	}
	$slug = minn_admin_wps_hide_login_slug();
	// Their own login_url filter did not take (a later filter rewrote it, or
	// the plugin bailed on this request): say nothing rather than guess.
	if ( '' === $slug || false !== strpos( $slug, 'wp-login.php' ) ) {
		return array();
	}
	$redirect = minn_admin_wps_hide_login_redirect_slug();
	$href     = admin_url( 'options-general.php#whl_settings' );
	if ( 'login' === $slug ) {
		return array(
			array(
				'label'  => __( 'Login address', 'minn-admin' ),
				'status' => 'warn',
				/* translators: %s: the login page address, like /login/. */
				'detail' => sprintf( __( 'WPS Hide Login is on but still uses the default address %s, which bots try right after wp-login.php. Pick a less guessable one.', 'minn-admin' ), '/' . $slug . '/' ),
				'href'   => $href,
			),
		);
	}
	return array(
		array(
			'label'  => __( 'Login address', 'minn-admin' ),
			'status' => 'pass',
			/* translators: 1: the login page address, like /my-door/. 2: where wp-login.php and wp-admin send visitors, like /404/. */
			'detail' => sprintf( __( 'Moved to %1$s by WPS Hide Login; wp-login.php and wp-admin send visitors to %2$s', 'minn-admin' ), '/' . $slug . '/', '/' . $redirect . '/' ),
			'href'   => $href,
		),
	);
}
