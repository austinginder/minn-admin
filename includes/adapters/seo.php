<?php
/**
 * Bundled adapter: Yoast SEO / Rank Math editor panel.
 *
 * The valuable 90% of both plugins at write time is three fields: SEO title,
 * meta description and focus keyword. Neither plugin exposes its post meta
 * over REST, so this adapter registers a dedicated `minn_seo` REST field
 * (NOT the generic meta API — the editor writes its whole panel object back
 * on save, and a dedicated field keeps that write scoped to these three
 * values) and describes the panel through the standard editor-panels
 * framework. Scores and content analysis stay in wp-admin — that's the
 * plugins' moat, not Minn's.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * The active SEO plugin and its meta keys — Yoast wins if both are active.
 *
 * @return array|null { name, keys: { title, description, focus_keyword } }
 */
function minn_admin_seo_plugin() {
	if ( defined( 'WPSEO_VERSION' ) ) {
		return array(
			'name' => 'Yoast SEO',
			'keys' => array(
				'title'         => '_yoast_wpseo_title',
				'description'   => '_yoast_wpseo_metadesc',
				'focus_keyword' => '_yoast_wpseo_focuskw',
			),
		);
	}
	if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
		return array(
			'name' => 'Rank Math',
			'keys' => array(
				'title'         => 'rank_math_title',
				'description'   => 'rank_math_description',
				'focus_keyword' => 'rank_math_focus_keyword',
			),
		);
	}
	return null;
}

add_filter( 'minn_admin_editor_panels', function ( $panels ) {
	$plugin = minn_admin_seo_plugin();
	if ( ! $plugin ) {
		return $panels;
	}
	$panels['seo'] = array(
		'label'       => 'SEO',
		'sub'         => $plugin['name'],
		'cap'         => 'edit_posts',
		'fieldsRoute' => 'minn-admin/v1/seo/fields',
		'valuesKey'   => 'minn_seo',
		'writeKey'    => 'minn_seo',
	);
	return $panels;
} );

add_action( 'rest_api_init', function () {
	$plugin = minn_admin_seo_plugin();
	if ( ! $plugin ) {
		return;
	}
	$keys = $plugin['keys'];

	register_rest_route( 'minn-admin/v1', '/seo/fields', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'callback'            => function () {
			return rest_ensure_response( array(
				'groups' => array(
					array(
						'group'  => 'Search appearance',
						'fields' => array(
							array( 'name' => 'title', 'label' => 'SEO title', 'type' => 'text' ),
							array( 'name' => 'description', 'label' => 'Meta description', 'type' => 'textarea' ),
							array( 'name' => 'focus_keyword', 'label' => 'Focus keyword', 'type' => 'text' ),
						),
						'locked' => 0,
					),
				),
			) );
		},
	) );

	// A read/write `minn_seo` object on every REST-visible post type,
	// context=edit only so values never appear on public API responses.
	$types = array();
	foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $obj ) {
		$types[] = $obj->name;
	}
	register_rest_field( $types, 'minn_seo', array(
		'get_callback'    => function ( $post_arr ) use ( $keys ) {
			$out = array();
			foreach ( $keys as $field => $meta_key ) {
				$out[ $field ] = (string) get_post_meta( (int) $post_arr['id'], $meta_key, true );
			}
			return $out;
		},
		'update_callback' => function ( $value, $post ) use ( $keys ) {
			if ( ! is_array( $value ) ) {
				return null;
			}
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				return new WP_Error( 'rest_forbidden', 'You cannot edit SEO fields on this post.', array( 'status' => 403 ) );
			}
			foreach ( $keys as $field => $meta_key ) {
				if ( ! array_key_exists( $field, $value ) ) {
					continue;
				}
				$clean = 'description' === $field
					? sanitize_textarea_field( (string) $value[ $field ] )
					: sanitize_text_field( (string) $value[ $field ] );
				if ( '' === $clean ) {
					delete_post_meta( $post->ID, $meta_key );
				} else {
					update_post_meta( $post->ID, $meta_key, $clean );
				}
			}
			return null;
		},
		'schema'          => array(
			'type'        => 'object',
			'description' => 'SEO title, meta description and focus keyword (Minn Admin editor panel).',
			'context'     => array( 'edit' ),
			'properties'  => array(
				'title'         => array( 'type' => 'string' ),
				'description'   => array( 'type' => 'string' ),
				'focus_keyword' => array( 'type' => 'string' ),
			),
		),
	) );
} );
