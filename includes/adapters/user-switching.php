<?php
/**
 * User Switching (johnbillion, 900k+) — "Switch to this user" in the users
 * list row menu.
 *
 * The plugin's own nonce URLs do all the work: user_switching::maybe_switch_url()
 * returns a wp-login.php?action=switch_to_user link only when the current user
 * passes its switch_to_user meta cap (and never for yourself), so Minn just
 * carries the URL to the row menu and navigates. Switching away lands wherever
 * the plugin sends you (wp-admin or the front end, by the target's caps); the
 * plugin's own admin-bar / footer "Switch back" links cover the return trip.
 *
 * Exposed as a REST field on wp/v2/users, edit context only. Empty string when
 * switching isn't available for that row.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'rest_api_init', function () {
	if ( ! class_exists( 'user_switching' ) ) {
		return;
	}
	register_rest_field(
		'user',
		'minn_switch_url',
		array(
			'get_callback' => function ( $data ) {
				$user = isset( $data['id'] ) ? get_userdata( (int) $data['id'] ) : false;
				if ( ! $user ) {
					return '';
				}
				$url = user_switching::maybe_switch_url( $user );
				// wp_nonce_url() output is HTML-escaped (the wp_logout_url
				// rule) — decode &amp; or the query args break as a location.
				return $url ? str_replace( '&amp;', '&', $url ) : '';
			},
			'schema'       => array(
				'description' => 'User Switching nonce URL for the current viewer, when allowed.',
				'type'        => 'string',
				'context'     => array( 'edit' ),
			),
		)
	);
} );

/**
 * A switched session's way home (the boot payload `switchBack` key). The
 * plugin's own switch-back link lives in wp-admin's admin bar, which Minn
 * never renders — without this, switching to a lesser account from Minn is
 * a one-way door. Returns { name, url } via the plugin's own nonce URL
 * (redirecting back into Minn), or null when this session isn't switched.
 */
function minn_admin_user_switching_back() {
	if ( ! class_exists( 'user_switching' ) || ! method_exists( 'user_switching', 'get_old_user' ) ) {
		return null;
	}
	try {
		$old = user_switching::get_old_user();
		if ( ! $old ) {
			return null;
		}
		$url = user_switching::switch_back_url( $old );
		if ( ! $url ) {
			return null;
		}
		// wp_nonce_url() output is HTML-escaped (the wp_logout_url rule).
		$url = str_replace( '&amp;', '&', $url );
		$url = add_query_arg( 'redirect_to', rawurlencode( Minn_Admin::app_url() ), $url );
		return array(
			'name' => $old->display_name ? $old->display_name : $old->user_login,
			'url'  => $url,
		);
	} catch ( \Throwable $e ) {
		return null;
	}
}
