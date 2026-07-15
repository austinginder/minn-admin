<?php
/**
 * Bundled adapter: SEO editor panel — Yoast, Rank Math, AIOSEO, SEOPress.
 *
 * The valuable 90% of every SEO plugin at write time is three fields: SEO
 * title, meta description and focus keyword. None of them expose those over
 * REST, so this adapter registers a dedicated `minn_seo` REST field (NOT
 * the generic meta API — the editor writes its whole panel object back on
 * save, and a dedicated field keeps that write scoped to these values) and
 * describes the panel through the standard editor-panels framework. Scores
 * and content analysis stay in wp-admin — that's the plugins' moat.
 *
 * Rank Math also maps social thumbnail (Facebook OG image, which Twitter
 * reuses when "use Facebook" is on) as an image field on the same panel.
 *
 * Yoast, Rank Math and SEOPress store postmeta; AIOSEO v4 keeps its own
 * {prefix}aioseo_posts table, so providers carry read/write callables and
 * AIOSEO's go through its own Post model (never raw SQL into their table).
 * Detection order follows install base; the first active plugin wins.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Provider backed by simple postmeta keys. Empty values delete the meta.
 */
function minn_admin_seo_meta_provider( $name, $keys ) {
	return array(
		'name'  => $name,
		'read'  => function ( $post_id ) use ( $keys ) {
			$out = array();
			foreach ( $keys as $field => $meta_key ) {
				$out[ $field ] = (string) get_post_meta( (int) $post_id, $meta_key, true );
			}
			return $out;
		},
		'write' => function ( $post_id, $field, $clean ) use ( $keys ) {
			if ( ! isset( $keys[ $field ] ) ) {
				return;
			}
			if ( '' === $clean ) {
				delete_post_meta( $post_id, $keys[ $field ] );
			} else {
				update_post_meta( $post_id, $keys[ $field ], $clean );
			}
		},
	);
}

/**
 * AIOSEO v4 provider — reads and writes through AIOSEO's own Post model so
 * its table shape, sanitization and caches stay its business. The focus
 * keyword lives inside the `keyphrases` JSON blob; additional keyphrases
 * are preserved untouched.
 */
function minn_admin_seo_aioseo_provider() {
	$model = '\AIOSEO\Plugin\Common\Models\Post';
	// The model auto-decodes JSON columns, so `keyphrases` may arrive as an
	// object, an array or a raw string depending on code path — normalize
	// to a plain array before touching it.
	$phrases_of = function ( $post ) {
		$raw = $post->keyphrases;
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
		} else {
			$decoded = json_decode( (string) wp_json_encode( $raw ), true );
		}
		return is_array( $decoded ) ? $decoded : array();
	};
	return array(
		'name'  => 'AIOSEO',
		'read'  => function ( $post_id ) use ( $model, $phrases_of ) {
			$out = array( 'title' => '', 'description' => '', 'focus_keyword' => '' );
			try {
				$post = $model::getPost( (int) $post_id );
				if ( $post ) {
					$out['title']       = (string) $post->title;
					$out['description'] = (string) $post->description;
					$phrases            = $phrases_of( $post );
					if ( ! empty( $phrases['focus']['keyphrase'] ) ) {
						$out['focus_keyword'] = (string) $phrases['focus']['keyphrase'];
					}
				}
			} catch ( \Throwable $e ) { /* their schema, their exceptions — read as empty */ }
			return $out;
		},
		'write' => function ( $post_id, $field, $clean ) use ( $model, $phrases_of ) {
			try {
				$post = $model::getPost( (int) $post_id );
				if ( ! $post ) {
					return;
				}
				if ( 'title' === $field ) {
					$post->title = '' === $clean ? null : $clean;
				} elseif ( 'description' === $field ) {
					$post->description = '' === $clean ? null : $clean;
				} elseif ( 'focus_keyword' === $field ) {
					$phrases = $phrases_of( $post );
					if ( '' === $clean ) {
						unset( $phrases['focus'] );
					} else {
						$phrases['focus'] = array_merge(
							isset( $phrases['focus'] ) && is_array( $phrases['focus'] ) ? $phrases['focus'] : array(),
							array( 'keyphrase' => $clean )
						);
					}
					$post->keyphrases = $phrases ? wp_json_encode( $phrases ) : null;
				}
				$post->save();
			} catch ( \Throwable $e ) { /* never let their model break the post save */ }
		},
	);
}

/**
 * Rank Math provider: core SEO strings plus social thumbnail
 * (rank_math_facebook_image / _id). Twitter reuses Facebook when
 * rank_math_twitter_use_facebook is on — Minn writes that flag on image set.
 *
 * @return array
 */
function minn_admin_seo_rank_math_provider() {
	$base = minn_admin_seo_meta_provider(
		'Rank Math',
		array(
			'title'         => 'rank_math_title',
			'description'   => 'rank_math_description',
			'focus_keyword' => 'rank_math_focus_keyword',
		)
	);
	$base_read  = $base['read'];
	$base_write = $base['write'];
	return array(
		'name'   => 'Rank Math',
		// Extra panel groups beyond Search appearance (client uses this).
		'social' => true,
		'read'   => function ( $post_id ) use ( $base_read ) {
			$out = call_user_func( $base_read, $post_id );
			$id  = (int) get_post_meta( (int) $post_id, 'rank_math_facebook_image_id', true );
			$url = (string) get_post_meta( (int) $post_id, 'rank_math_facebook_image', true );
			if ( $id && ! $url ) {
				$url = (string) wp_get_attachment_image_url( $id, 'medium' );
			}
			$out['social_image'] = ( $id || $url )
				? array(
					'id'  => $id,
					'url' => $url,
				)
				: null;
			return $out;
		},
		'write'  => function ( $post_id, $field, $clean ) use ( $base_write ) {
			if ( 'social_image' === $field ) {
				// $clean is null/'' to clear, or { id, url } / attachment id.
				$id  = 0;
				$url = '';
				if ( is_array( $clean ) ) {
					$id  = isset( $clean['id'] ) ? (int) $clean['id'] : 0;
					$url = isset( $clean['url'] ) ? (string) $clean['url'] : '';
				} elseif ( is_numeric( $clean ) ) {
					$id = (int) $clean;
				}
				if ( $id > 0 ) {
					if ( ! $url ) {
						$url = (string) wp_get_attachment_url( $id );
					}
					// Prefer full-size source for OG; fall back to medium if missing.
					if ( ! $url ) {
						$url = (string) wp_get_attachment_image_url( $id, 'full' );
					}
					update_post_meta( $post_id, 'rank_math_facebook_image_id', $id );
					update_post_meta( $post_id, 'rank_math_facebook_image', $url );
					// Let Twitter inherit the Facebook image (Rank Math default).
					update_post_meta( $post_id, 'rank_math_twitter_use_facebook', 'on' );
				} else {
					delete_post_meta( $post_id, 'rank_math_facebook_image_id' );
					delete_post_meta( $post_id, 'rank_math_facebook_image' );
				}
				return;
			}
			call_user_func( $base_write, $post_id, $field, $clean );
		},
	);
}

/**
 * The active SEO plugin as { name, read, write } — first active wins, in
 * install-base order.
 *
 * @return array|null
 */
function minn_admin_seo_plugin() {
	if ( defined( 'WPSEO_VERSION' ) ) {
		return minn_admin_seo_meta_provider( 'Yoast SEO', array(
			'title'         => '_yoast_wpseo_title',
			'description'   => '_yoast_wpseo_metadesc',
			'focus_keyword' => '_yoast_wpseo_focuskw',
		) );
	}
	if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
		return minn_admin_seo_rank_math_provider();
	}
	if ( defined( 'AIOSEO_VERSION' ) && class_exists( '\AIOSEO\Plugin\Common\Models\Post' ) ) {
		return minn_admin_seo_aioseo_provider();
	}
	if ( defined( 'SEOPRESS_VERSION' ) ) {
		return minn_admin_seo_meta_provider( 'SEOPress', array(
			'title'         => '_seopress_titles_title',
			'description'   => '_seopress_titles_desc',
			'focus_keyword' => '_seopress_analysis_target_kw',
		) );
	}
	// SiteSEO is the SEOPress fork; same postmeta shape under its own prefix.
	if ( defined( 'SITESEO_VERSION' ) ) {
		return minn_admin_seo_meta_provider( 'SiteSEO', array(
			'title'         => '_siteseo_titles_title',
			'description'   => '_siteseo_titles_desc',
			'focus_keyword' => '_siteseo_analysis_target_kw',
		) );
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

	register_rest_route( 'minn-admin/v1', '/seo/fields', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'callback'            => function () use ( $plugin ) {
			$groups = array(
				array(
					'group'  => 'Search appearance',
					'fields' => array(
						array( 'name' => 'title', 'label' => 'SEO title', 'type' => 'text' ),
						array( 'name' => 'description', 'label' => 'Meta description', 'type' => 'textarea' ),
						array( 'name' => 'focus_keyword', 'label' => 'Focus keyword', 'type' => 'text' ),
					),
					'locked' => 0,
				),
			);
			// Rank Math social thumbnail (Facebook OG; Twitter inherits).
			if ( ! empty( $plugin['social'] ) ) {
				$groups[] = array(
					'group'  => 'Social',
					'fields' => array(
						array(
							'name'  => 'social_image',
							'label' => 'Social thumbnail',
							'type'  => 'image',
						),
					),
					'locked' => 0,
				);
			}
			return rest_ensure_response( array( 'groups' => $groups ) );
		},
	) );

	// A read/write `minn_seo` object on every REST-visible post type,
	// context=edit only so values never appear on public API responses.
	$types = array();
	foreach ( get_post_types( array( 'show_in_rest' => true ), 'objects' ) as $obj ) {
		$types[] = $obj->name;
	}
	register_rest_field( $types, 'minn_seo', array(
		'get_callback'    => function ( $post_arr ) use ( $plugin ) {
			return call_user_func( $plugin['read'], (int) $post_arr['id'] );
		},
		'update_callback' => function ( $value, $post ) use ( $plugin ) {
			if ( ! is_array( $value ) ) {
				return null;
			}
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				return new WP_Error( 'rest_forbidden', 'You cannot edit SEO fields on this post.', array( 'status' => 403 ) );
			}
			foreach ( array( 'title', 'description', 'focus_keyword' ) as $field ) {
				if ( ! array_key_exists( $field, $value ) ) {
					continue;
				}
				$clean = 'description' === $field
					? sanitize_textarea_field( (string) $value[ $field ] )
					: sanitize_text_field( (string) $value[ $field ] );
				call_user_func( $plugin['write'], $post->ID, $field, $clean );
			}
			if ( array_key_exists( 'social_image', $value ) ) {
				$raw = $value['social_image'];
				if ( null === $raw || '' === $raw || false === $raw ) {
					call_user_func( $plugin['write'], $post->ID, 'social_image', null );
				} elseif ( is_array( $raw ) ) {
					call_user_func(
						$plugin['write'],
						$post->ID,
						'social_image',
						array(
							'id'  => isset( $raw['id'] ) ? (int) $raw['id'] : 0,
							'url' => isset( $raw['url'] ) ? esc_url_raw( (string) $raw['url'] ) : '',
						)
					);
				} elseif ( is_numeric( $raw ) ) {
					call_user_func( $plugin['write'], $post->ID, 'social_image', (int) $raw );
				}
			}
			return null;
		},
		'schema'          => array(
			'type'        => 'object',
			'description' => 'SEO title, meta description, focus keyword, and social image (Minn Admin editor panel).',
			'context'     => array( 'edit' ),
			'properties'  => array(
				'title'         => array( 'type' => 'string' ),
				'description'   => array( 'type' => 'string' ),
				'focus_keyword' => array( 'type' => 'string' ),
				'social_image'  => array(
					'type'       => array( 'object', 'null' ),
					'properties' => array(
						'id'  => array( 'type' => 'integer' ),
						'url' => array( 'type' => 'string' ),
					),
				),
			),
		),
	) );
} );
