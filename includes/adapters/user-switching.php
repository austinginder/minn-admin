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
