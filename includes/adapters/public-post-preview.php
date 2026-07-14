<?php
/**
 * Bundled adapter: Public Post Preview (Dominik Schilling / ocean90).
 *
 * Shareable anonymous draft links. The plugin owns the front-end gate
 * (expiring `_ppp` nonces, option `public_post_preview` of enabled post
 * IDs). This adapter surfaces enable / disable / copy-link in Minn's
 * editor and content row menu — same "plugin owns secrets, Minn is UI"
 * shape as One Time Login.
 *
 * Enable/disable writes the plugin's option (same list their AJAX and
 * save_post handlers update). Preview URLs come from their public
 * `DS_Public_Post_Preview::get_preview_link()` so nonces stay theirs.
 *
 * Eligible statuses: anything not publish/private (their
 * `get_published_statuses` defaults). Future / pending / draft are OK.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether Public Post Preview is loadable.
 */
function minn_admin_ppp_active() {
	return class_exists( 'DS_Public_Post_Preview' )
		&& method_exists( 'DS_Public_Post_Preview', 'get_preview_link' );
}

/**
 * Post IDs currently registered for public preview (their option).
 *
 * @return int[]
 */
function minn_admin_ppp_ids() {
	$ids = get_option( 'public_post_preview', array() );
	if ( ! is_array( $ids ) ) {
		return array();
	}
	return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
}

/**
 * Whether a post is eligible for a public preview (status + type).
 *
 * Mirrors PPP: not publish/private (filterable via ppp_published_statuses
 * only on their side — we match the default + refuse trash).
 *
 * @param WP_Post $post Post.
 * @return true|WP_Error
 */
function minn_admin_ppp_eligible( $post ) {
	if ( ! ( $post instanceof WP_Post ) ) {
		return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
	}
	$blocked = apply_filters( 'ppp_published_statuses', array( 'publish', 'private' ) );
	$blocked = array_merge( (array) $blocked, array( 'trash', 'auto-draft' ) );
	if ( in_array( $post->post_status, $blocked, true ) ) {
		return new WP_Error(
			'invalid_status',
			'Public preview is only available for unpublished drafts (not publish or private).',
			array( 'status' => 400 )
		);
	}
	if ( ! is_post_type_viewable( $post->post_type ) ) {
		return new WP_Error( 'invalid_type', 'This post type is not viewable on the front end.', array( 'status' => 400 ) );
	}
	return true;
}

/**
 * Expiration lifetime in hours (their Reading setting, default 48).
 *
 * @return int
 */
function minn_admin_ppp_hours() {
	$hours = (int) get_option( 'public_post_preview_expiration_time', 48 );
	if ( $hours < 1 ) {
		$hours = 48;
	}
	// Their filter is on seconds; report hours for the UI.
	if ( has_filter( 'ppp_nonce_life' ) ) {
		$secs = (int) apply_filters( 'ppp_nonce_life', $hours * HOUR_IN_SECONDS );
		if ( $secs > 0 ) {
			$hours = max( 1, (int) round( $secs / HOUR_IN_SECONDS ) );
		}
	}
	return $hours;
}

/**
 * Build the response model for one post.
 *
 * @param WP_Post $post Post.
 * @return array{enabled:bool,url:string,hours:int,eligible:bool}
 */
function minn_admin_ppp_state( $post ) {
	$eligible = minn_admin_ppp_eligible( $post );
	$ok       = ! is_wp_error( $eligible );
	$enabled  = $ok && in_array( (int) $post->ID, minn_admin_ppp_ids(), true );
	$url      = '';
	if ( $enabled ) {
		$url = (string) DS_Public_Post_Preview::get_preview_link( $post );
	}
	return array(
		'enabled'  => $enabled,
		'url'      => $url,
		'hours'    => minn_admin_ppp_hours(),
		'eligible' => $ok,
		'reason'   => $ok ? '' : $eligible->get_error_message(),
	);
}

/**
 * Enable or disable public preview for a post via their option list.
 *
 * @param int  $post_id Post ID.
 * @param bool $on      Enable when true.
 * @return true|WP_Error
 */
function minn_admin_ppp_set( $post_id, $on ) {
	$post_id = (int) $post_id;
	$ids     = minn_admin_ppp_ids();
	$has     = in_array( $post_id, $ids, true );
	if ( $on && ! $has ) {
		$ids[] = $post_id;
	} elseif ( ! $on && $has ) {
		$ids = array_values( array_diff( $ids, array( $post_id ) ) );
	} else {
		return true;
	}
	// Same shape as DS_Public_Post_Preview::set_preview_post_ids().
	$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	$ret = update_option( 'public_post_preview', $ids );
	// update_option returns false when value unchanged — treat as OK if list matches.
	if ( false === $ret && minn_admin_ppp_ids() !== $ids ) {
		// Race: re-read and compare membership only.
		$now = minn_admin_ppp_ids();
		if ( $on && ! in_array( $post_id, $now, true ) ) {
			return new WP_Error( 'not_saved', 'Could not save public preview state.', array( 'status' => 500 ) );
		}
		if ( ! $on && in_array( $post_id, $now, true ) ) {
			return new WP_Error( 'not_saved', 'Could not save public preview state.', array( 'status' => 500 ) );
		}
	}
	return true;
}

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_ppp_active() ) {
		return;
	}

	$can = function ( WP_REST_Request $request ) {
		$id = (int) $request['id'];
		return $id > 0 && current_user_can( 'edit_post', $id );
	};

	register_rest_route( 'minn-admin/v1', '/ppp/(?P<id>\d+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $can,
			'callback'            => function ( WP_REST_Request $request ) {
				$post = get_post( (int) $request['id'] );
				if ( ! $post ) {
					return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
				}
				return rest_ensure_response( minn_admin_ppp_state( $post ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $can,
			'callback'            => function ( WP_REST_Request $request ) {
				$post = get_post( (int) $request['id'] );
				if ( ! $post ) {
					return new WP_Error( 'not_found', 'Post not found.', array( 'status' => 404 ) );
				}
				$eligible = minn_admin_ppp_eligible( $post );
				if ( is_wp_error( $eligible ) ) {
					return $eligible;
				}
				$body    = $request->get_json_params();
				if ( ! is_array( $body ) ) {
					$body = array();
				}
				$enabled = ! empty( $body['enabled'] );
				$set     = minn_admin_ppp_set( (int) $post->ID, $enabled );
				if ( is_wp_error( $set ) ) {
					return $set;
				}
				// Fresh post object in case status drifted.
				$post = get_post( (int) $post->ID );
				return rest_ensure_response( minn_admin_ppp_state( $post ) );
			},
		),
	) );
} );
