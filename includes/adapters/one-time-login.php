<?php
/**
 * Bundled adapter: One Time Login (Daniel Bachhuber).
 *
 * The plugin is CLI/REST only — it ships no admin UI at all. This adapter
 * gives it its first surface: a "Copy one-time login link" action in the
 * users row menu, which mints a single-use login-as-that-user link through
 * the plugin's OWN token generator (one_time_login_generate_tokens), copies
 * it to the clipboard, and stores nothing.
 *
 * A one-time login link is a SECRET (it signs the holder in as that user
 * once), so it is generated ON DEMAND and never rides the boot payload —
 * the boot flag is a boolean only (the Disembark-command precedent).
 *
 * Caps mirror the plugin's own REST route exactly: edit_user on the TARGET
 * (not a blanket edit_users), so you can only mint a link for an account
 * you may already edit. The link lands in wp-admin on use (the plugin
 * hardcodes that redirect); Minn can't change where it lands.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_otl_active() {
	return function_exists( 'one_time_login_generate_tokens' );
}

/**
 * Honor "Minn is the default admin" for one-time logins too. The plugin
 * sets the auth cookie and then hardcodes wp_safe_redirect( admin_url() ),
 * which never passes through the `login_redirect` filter Minn's setting
 * rides on — so a one-time link always landed in wp-admin even when the
 * site prefers Minn. Its own post-auth action fires immediately before that
 * redirect, so we redirect into Minn first, under the same gate as the
 * normal login flow (setting on + the user can actually use Minn).
 */
add_action( 'one_time_login_after_auth_cookie_set', function ( $user ) {
	if ( ! ( $user instanceof WP_User ) || ! $user->has_cap( 'edit_posts' ) ) {
		return;
	}
	// Per-user preference (was a site option).
	if ( ! Minn_Admin::user_wants_default_admin( $user->ID ) ) {
		return;
	}
	wp_safe_redirect( Minn_Admin::app_url() );
	exit;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_otl_active() ) {
		return;
	}

	register_rest_route( 'minn-admin/v1', '/otl/(?P<id>\d+)', array(
		'methods'             => 'POST',
		'permission_callback' => function ( WP_REST_Request $request ) {
			// The plugin's own gate: edit_user on the target account.
			return current_user_can( 'edit_user', (int) $request['id'] );
		},
		'callback'            => function ( WP_REST_Request $request ) {
			$user = get_userdata( (int) $request['id'] );
			if ( ! $user ) {
				return new WP_Error( 'not_found', 'User not found', array( 'status' => 404 ) );
			}
			// delay_delete=true: existing tokens keep working for 15 minutes
			// (the plugin's own grace flag) rather than being wiped at once —
			// friendlier when a couple of links are handed out close together.
			$urls = one_time_login_generate_tokens( $user, 1, true );
			$url  = ! empty( $urls[0] ) ? $urls[0] : '';
			if ( ! $url ) {
				return new WP_Error( 'mint_failed', 'One Time Login could not generate a link.', array( 'status' => 500 ) );
			}
			return rest_ensure_response( array(
				'url'  => $url,
				'name' => $user->display_name ? $user->display_name : $user->user_login,
			) );
		},
	) );
} );
