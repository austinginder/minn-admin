<?php
/**
 * Bundled adapter: SEO editor panel — Yoast, Rank Math, AIOSEO, SEOPress,
 * SureRank, SiteSEO, Squirrly.
 *
 * Every provider covers the shared core (SEO title, meta description,
 * focus keyword); a provider that declares a `fields` callable describes
 * its full per-post depth — robots meta, canonical URL, the social split,
 * schema notes (Rank Math is the reference). None of these plugins expose
 * per-post SEO over REST, so this adapter registers a dedicated `minn_seo`
 * REST field (NOT the generic meta API — the editor writes its whole panel
 * object back on save, and a dedicated field keeps that write scoped to
 * declared values) and describes the panel through the standard
 * editor-panels framework. The declared field map doubles as the write
 * whitelist, with per-type sanitizers in the REST layer. Scores and
 * content analysis stay in wp-admin — that's the plugins' moat.
 *
 * Yoast, Rank Math, SEOPress and SiteSEO store postmeta; AIOSEO v4 keeps
 * its own {prefix}aioseo_posts table, SureRank keeps GROUPED postmeta
 * blobs, and Squirrly keeps a serialized {prefix}qss row, so providers
 * carry read/write callables and those three go through their own models
 * (never raw SQL into AIOSEO's table, never a hand-built group array for
 * SureRank, never unserialize of Squirrly's seo column). Detection order
 * follows install base; the first active plugin wins.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Provider backed by simple postmeta keys. Empty values delete the meta.
 */
function minn_admin_seo_meta_provider( $name, $keys, $can_edit = null ) {
	return array(
		'name'    => $name,
		// A per-vendor predicate on top of edit_post. Several SEO plugins
		// gate their metabox on an extra per-role capability, so editing at
		// edit_post alone would let a role the vendor blocks (a Contributor,
		// under Rank Math) write SEO meta the vendor's own UI withholds.
		// Null means parity — edit_post is the vendor's own floor too.
		'can_edit' => $can_edit,
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
 * AIOSEO v4 provider — full per-post depth through AIOSEO's own Post model
 * so its table shape, sanitization and caches stay its business. The focus
 * keyword lives inside the `keyphrases` JSON blob; additional keyphrases
 * are preserved untouched.
 *
 * Model facts (app/Common/Models/Post.php, verified):
 * - Robots are COLUMNS behind one robots_default switch: ON means inherit
 *   everything, OFF reveals the directive booleans plus the max-snippet /
 *   video / image-preview limits (int_neg1 sanitize: non-numeric becomes
 *   -1; image preview default 'large'). The panel mirrors that shape with
 *   a "Use default robots" toggle and conditional fields, because a
 *   per-directive inherit does not exist in their model.
 * - twitter_use_og inherits the OG card like Rank Math's use_facebook;
 *   null means the site option (default on).
 * - Custom social images are URL columns (og_image_type 'custom' +
 *   og_image_custom_url); ids resolve back via attachment_url_to_postid
 *   (the Squirrly-provider convention).
 * - Groups follow their per-tab access caps (aioseo()->access):
 *   social_settings, advanced_settings, schema_settings.
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
	$has_cap = function ( $cap ) {
		try {
			return function_exists( 'aioseo' ) && isset( aioseo()->access )
				? (bool) aioseo()->access->hasCapability( $cap )
				: current_user_can( $cap );
		} catch ( \Throwable $e ) {
			return false;
		}
	};
	$robots_toggles = array(
		'robots_noindex'      => 'robots_noindex',
		'robots_nofollow'     => 'robots_nofollow',
		'robots_noarchive'    => 'robots_noarchive',
		'robots_noimageindex' => 'robots_noimageindex',
		'robots_nosnippet'    => 'robots_nosnippet',
	);
	return array(
		'name'    => 'AIOSEO',
		// AIOSEO gates its own post SEO write on aioseo_page_general_settings
		// (PostsTerms.php), so edit_post alone would grant what it withholds.
		'can_edit' => function () use ( $has_cap ) {
			return $has_cap( 'aioseo_page_general_settings' );
		},
		'fields' => function () use ( $has_cap ) {
			$dflt = array( array( array( 'f' => 'robots_default', 'op' => '==', 'v' => '0' ) ) );
			$tw   = array( array( array( 'f' => 'twitter_use_facebook', 'op' => '==', 'v' => '0' ) ) );
			$groups   = array();
			$appearance = array(
				array( 'name' => 'title', 'label' => __( 'SEO title', 'minn-admin' ), 'type' => 'text', 'counter' => 60 ),
				array( 'name' => 'description', 'label' => __( 'Meta description', 'minn-admin' ), 'type' => 'textarea', 'counter' => 160 ),
			);
			// AIOSEO files the keyphrase under its Analysis area and reserves
			// that write to aioseo_page_analysis in their own API, so the
			// general settings capability does not carry it. Same two-area
			// shape as SEOPress's target keyword.
			if ( $has_cap( 'aioseo_page_analysis' ) ) {
				$appearance[] = array( 'name' => 'focus_keyword', 'label' => __( 'Focus keyword', 'minn-admin' ), 'type' => 'text' );
			}
			$groups[] = array(
				'group'  => __( 'Search appearance', 'minn-admin' ),
				'fields' => $appearance,
			);
			if ( $has_cap( 'aioseo_page_social_settings' ) ) {
				$groups[] = array(
					'group'  => __( 'Social', 'minn-admin' ),
					'fields' => array(
						array( 'name' => 'facebook_title', 'label' => __( 'Facebook title', 'minn-admin' ), 'type' => 'text' ),
						array( 'name' => 'facebook_description', 'label' => __( 'Facebook description', 'minn-admin' ), 'type' => 'textarea' ),
						array( 'name' => 'social_image', 'label' => __( 'Social thumbnail', 'minn-admin' ), 'type' => 'image', 'help' => __( 'Sets a custom Facebook image; empty falls back to their default source.', 'minn-admin' ) ),
						array( 'name' => 'twitter_use_facebook', 'label' => __( 'X (Twitter) uses the Facebook card', 'minn-admin' ), 'type' => 'toggle' ),
						array(
							'name'    => 'twitter_card_type',
							'label'   => __( 'X (Twitter) card type', 'minn-admin' ),
							'type'    => 'select',
							'cond'    => $tw,
							'options' => array(
								array( '', __( 'Site default', 'minn-admin' ) ),
								array( 'summary', __( 'Summary', 'minn-admin' ) ),
								array( 'summary_large_image', __( 'Summary with large image', 'minn-admin' ) ),
							),
						),
						array( 'name' => 'twitter_title', 'label' => __( 'X (Twitter) title', 'minn-admin' ), 'type' => 'text', 'cond' => $tw ),
						array( 'name' => 'twitter_description', 'label' => __( 'X (Twitter) description', 'minn-admin' ), 'type' => 'textarea', 'cond' => $tw ),
						array( 'name' => 'twitter_image', 'label' => __( 'X (Twitter) image', 'minn-admin' ), 'type' => 'image', 'cond' => $tw ),
					),
				);
			}
			if ( $has_cap( 'aioseo_page_advanced_settings' ) ) {
				$groups[] = array(
					'group'  => __( 'Advanced', 'minn-admin' ),
					'fields' => array(
						// Cornerstone lives on their Advanced tab, so it answers
						// to the Advanced capability rather than the general one.
						array( 'name' => 'pillar_content', 'label' => __( 'Pillar content', 'minn-admin' ), 'type' => 'toggle', 'help' => __( 'Mark this as cornerstone content.', 'minn-admin' ) ),
						array( 'name' => 'robots_default', 'label' => __( 'Use default robots settings', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'name' => 'robots_noindex', 'label' => __( 'No index', 'minn-admin' ), 'type' => 'toggle', 'cond' => $dflt ),
						array( 'name' => 'robots_nofollow', 'label' => __( 'Nofollow links', 'minn-admin' ), 'type' => 'toggle', 'cond' => $dflt ),
						array( 'name' => 'robots_noarchive', 'label' => __( 'No archive', 'minn-admin' ), 'type' => 'toggle', 'cond' => $dflt ),
						array( 'name' => 'robots_noimageindex', 'label' => __( 'No image index', 'minn-admin' ), 'type' => 'toggle', 'cond' => $dflt ),
						array( 'name' => 'robots_nosnippet', 'label' => __( 'No snippet', 'minn-admin' ), 'type' => 'toggle', 'cond' => $dflt ),
						array( 'name' => 'adv_max_snippet', 'label' => __( 'Max snippet length', 'minn-admin' ), 'type' => 'number', 'cond' => $dflt, 'help' => __( '-1 sets no limit.', 'minn-admin' ) ),
						array( 'name' => 'adv_max_video_preview', 'label' => __( 'Max video preview', 'minn-admin' ), 'type' => 'number', 'cond' => $dflt, 'help' => __( 'Seconds. -1 sets no limit.', 'minn-admin' ) ),
						array(
							'name'    => 'adv_max_image_preview',
							'label'   => __( 'Max image preview', 'minn-admin' ),
							'type'    => 'select',
							'cond'    => $dflt,
							'options' => array(
								array( 'none', __( 'None', 'minn-admin' ) ),
								array( 'standard', __( 'Standard', 'minn-admin' ) ),
								array( 'large', __( 'Large', 'minn-admin' ) ),
							),
						),
						array( 'name' => 'canonical', 'label' => __( 'Canonical URL', 'minn-admin' ), 'type' => 'text', 'sanitize' => 'url', 'help' => __( 'Leave empty to use the permalink.', 'minn-admin' ) ),
					),
				);
			}
			if ( $has_cap( 'aioseo_page_schema_settings' ) ) {
				$groups[] = array(
					'group'  => __( 'Schema', 'minn-admin' ),
					'fields' => array(
						array( 'name' => 'schema_in_use', 'label' => __( 'Schema in use', 'minn-admin' ), 'type' => 'note' ),
					),
					// Their schema generator is their own app; the locked
					// link is the doorway.
					'locked' => 1,
				);
			}
			return $groups;
		},
		'preview' => function ( $post_id ) {
			try {
				$post = get_post( (int) $post_id );
				if ( ! $post || ! function_exists( 'aioseo' ) ) {
					return null;
				}
				// AIOSEO's smart tags are #syntax the client cannot re-run,
				// so the vars map carries only the generic %tokens%.
				$vars = array(
					'title'    => get_the_title( $post ),
					'sitename' => get_bloginfo( 'name' ),
					'sitedesc' => get_bloginfo( 'description' ),
					'sep'      => '-',
					'excerpt'  => wp_trim_words( (string) get_the_excerpt( $post ), 30 ),
					'page'     => '',
				);
				return array(
					'url'         => (string) get_permalink( $post ),
					'title'       => (string) aioseo()->meta->title->getPostTitle( $post ),
					'description' => (string) aioseo()->meta->description->getPostDescription( $post ),
					'fields'      => array( 'title' => 'title', 'description' => 'description' ),
					'vars'        => $vars,
				);
			} catch ( \Throwable $e ) {
				return null;
			}
		},
		'read'  => function ( $post_id ) use ( $model, $phrases_of, $robots_toggles ) {
			$out = array( 'title' => '', 'description' => '', 'focus_keyword' => '' );
			try {
				$post = $model::getPost( (int) $post_id );
				if ( ! $post ) {
					return $out;
				}
				$out['title']       = (string) $post->title;
				$out['description'] = (string) $post->description;
				$phrases            = $phrases_of( $post );
				if ( ! empty( $phrases['focus']['keyphrase'] ) ) {
					$out['focus_keyword'] = (string) $phrases['focus']['keyphrase'];
				}
				$out['pillar_content'] = ! empty( $post->pillar_content );
				// null = never explicit = their defaults.
				$out['robots_default'] = null === $post->robots_default ? true : (bool) $post->robots_default;
				foreach ( $robots_toggles as $field => $column ) {
					$out[ $field ] = ! empty( $post->$column );
				}
				$out['adv_max_snippet']       = null === $post->robots_max_snippet ? -1 : (int) $post->robots_max_snippet;
				$out['adv_max_video_preview'] = null === $post->robots_max_videopreview ? -1 : (int) $post->robots_max_videopreview;
				$out['adv_max_image_preview'] = null === $post->robots_max_imagepreview || '' === (string) $post->robots_max_imagepreview ? 'large' : (string) $post->robots_max_imagepreview;
				$out['canonical']             = (string) $post->canonical_url;
				$out['facebook_title']        = (string) $post->og_title;
				$out['facebook_description']  = (string) $post->og_description;
				$og_url = 'custom' === (string) $post->og_image_type ? (string) $post->og_image_custom_url : '';
				$out['social_image'] = '' !== $og_url ? array( 'id' => (int) attachment_url_to_postid( $og_url ), 'url' => $og_url ) : null;
				$out['twitter_use_facebook'] = null === $post->twitter_use_og ? true : (bool) $post->twitter_use_og;
				$out['twitter_card_type']    = null === $post->twitter_card || 'default' === (string) $post->twitter_card ? '' : (string) $post->twitter_card;
				$out['twitter_title']        = (string) $post->twitter_title;
				$out['twitter_description']  = (string) $post->twitter_description;
				$tw_url = 'custom' === (string) $post->twitter_image_type ? (string) $post->twitter_image_custom_url : '';
				$out['twitter_image'] = '' !== $tw_url ? array( 'id' => (int) attachment_url_to_postid( $tw_url ), 'url' => $tw_url ) : null;
				$out['schema_in_use'] = minn_admin_seo_aioseo_schema_in_use( $post, get_post_type( (int) $post_id ) );
			} catch ( \Throwable $e ) { /* their schema, their exceptions — read as empty */ }
			return $out;
		},
		'write' => function ( $post_id, $field, $clean ) use ( $model, $phrases_of, $robots_toggles ) {
			try {
				$post = $model::getPost( (int) $post_id );
				if ( ! $post ) {
					return;
				}
				$image_set = function ( $type_col, $url_col ) use ( $post, $clean ) {
					$att = is_numeric( $clean ) ? (int) $clean : 0;
					if ( $att > 0 ) {
						$url = (string) wp_get_attachment_url( $att );
						if ( '' !== $url ) {
							$post->$type_col = 'custom';
							$post->$url_col  = $url;
						}
					} else {
						$post->$type_col = 'default';
						$post->$url_col  = null;
					}
				};
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
				} elseif ( 'pillar_content' === $field ) {
					$post->pillar_content = (bool) $clean;
				} elseif ( 'robots_default' === $field ) {
					$post->robots_default = (bool) $clean;
				} elseif ( isset( $robots_toggles[ $field ] ) ) {
					$column        = $robots_toggles[ $field ];
					$post->$column = (bool) $clean;
				} elseif ( 'adv_max_snippet' === $field || 'adv_max_video_preview' === $field ) {
					$column        = 'adv_max_snippet' === $field ? 'robots_max_snippet' : 'robots_max_videopreview';
					// Their int_neg1 sanitize: anything non-numeric is -1.
					$post->$column = is_numeric( $clean ) ? (int) $clean : -1;
				} elseif ( 'adv_max_image_preview' === $field ) {
					$post->robots_max_imagepreview = '' === $clean ? 'large' : $clean;
				} elseif ( 'canonical' === $field ) {
					$post->canonical_url = '' === $clean ? null : $clean;
				} elseif ( 'facebook_title' === $field ) {
					$post->og_title = '' === $clean ? null : $clean;
				} elseif ( 'facebook_description' === $field ) {
					$post->og_description = '' === $clean ? null : $clean;
				} elseif ( 'social_image' === $field ) {
					$image_set( 'og_image_type', 'og_image_custom_url' );
				} elseif ( 'twitter_use_facebook' === $field ) {
					$post->twitter_use_og = (bool) $clean;
				} elseif ( 'twitter_card_type' === $field ) {
					$post->twitter_card = '' === $clean ? 'default' : $clean;
				} elseif ( 'twitter_title' === $field ) {
					$post->twitter_title = '' === $clean ? null : $clean;
				} elseif ( 'twitter_description' === $field ) {
					$post->twitter_description = '' === $clean ? null : $clean;
				} elseif ( 'twitter_image' === $field ) {
					$image_set( 'twitter_image_type', 'twitter_image_custom_url' );
				} else {
					return;
				}
				$post->save();
			} catch ( \Throwable $e ) { /* never let their model break the post save */ }
		},
	);
}

/**
 * What schema this post emits under AIOSEO: the default graph (unless
 * disabled) plus any custom graphs, read from the model's auto-decoded
 * `schema` JSON column. Guarded — a shape change reads as ''.
 *
 * A post their app never touched has a null schema column but still emits
 * the post-type default (dynamicOptions schemaType), so that is what an
 * empty column reports.
 *
 * @param object $post      AIOSEO Post model instance.
 * @param string $post_type Post type, for the default fallback.
 * @return string
 */
function minn_admin_seo_aioseo_schema_in_use( $post, $post_type = '' ) {
	$type_default = function () use ( $post_type ) {
		try {
			if ( $post_type && function_exists( 'aioseo' ) && aioseo()->dynamicOptions->searchAppearance->postTypes->has( $post_type ) ) {
				$default = (string) aioseo()->dynamicOptions->searchAppearance->postTypes->$post_type->schemaType;
				if ( '' !== $default && 'none' !== strtolower( $default ) ) {
					return $default;
				}
			}
		} catch ( \Throwable $e ) { /* fall through */ }
		return __( 'None', 'minn-admin' );
	};
	try {
		$schema = json_decode( (string) wp_json_encode( $post->schema ), true );
		if ( ! is_array( $schema ) ) {
			return $type_default();
		}
		$types = array();
		$default_enabled = ! isset( $schema['default']['isEnabled'] ) || ! empty( $schema['default']['isEnabled'] );
		if ( $default_enabled && ! empty( $schema['default']['graphName'] ) ) {
			$types[] = (string) $schema['default']['graphName'];
		}
		foreach ( array( 'graphs', 'customGraphs' ) as $key ) {
			if ( ! empty( $schema[ $key ] ) && is_array( $schema[ $key ] ) ) {
				foreach ( $schema[ $key ] as $graph ) {
					if ( ! empty( $graph['graphName'] ) ) {
						$types[] = (string) $graph['graphName'];
					} elseif ( ! empty( $graph['slug'] ) ) {
						$types[] = (string) $graph['slug'];
					}
				}
			}
		}
		return $types ? implode( ', ', array_unique( $types ) ) : $type_default();
	} catch ( \Throwable $e ) {
		return '';
	}
}

/**
 * Rank Math provider: the full per-post metabox depth — search appearance
 * strings, pillar content, the Facebook/Twitter social split, robots meta
 * (rank_math_robots array + rank_math_advanced_robots map), canonical URL
 * and a read-only "Schema in use" note. The Schema Generator and the
 * score/analysis checklists stay in their app (locked-field link-out).
 *
 * Storage facts this hangs on (their source, verified):
 * - Their own editor save (rank_math/v1/updateMeta) deletes a meta on any
 *   empty value, so delete-on-clear is vendor parity, not invention.
 * - rank_math_robots is an array of directive strings; ABSENT means
 *   "inherit the post-type default", so an all-off group deletes the meta.
 * - rank_math_advanced_robots is a { max-snippet, max-video-preview,
 *   max-image-preview } map; absent inherits titles.advanced_robots_global.
 * - twitter_use_facebook: absent defaults to ON (their metabox default);
 *   'off' must be STORED (an empty write would delete and flip it back on),
 *   so the toggle always writes 'on'/'off' explicitly.
 * - Card types whitelist mirrors their frontend sanitize_card_type().
 *
 * @return array
 */
function minn_admin_seo_rank_math_provider() {
	$base = minn_admin_seo_meta_provider(
		'Rank Math',
		array(
			'title'                => 'rank_math_title',
			'description'          => 'rank_math_description',
			'focus_keyword'        => 'rank_math_focus_keyword',
			'facebook_title'       => 'rank_math_facebook_title',
			'facebook_description' => 'rank_math_facebook_description',
			'twitter_title'        => 'rank_math_twitter_title',
			'twitter_description'  => 'rank_math_twitter_description',
			'canonical'            => 'rank_math_canonical_url',
		)
	);
	$base_read  = $base['read'];
	$base_write = $base['write'];

	$robots_toggles = array(
		'robots_nofollow'     => 'nofollow',
		'robots_noarchive'    => 'noarchive',
		'robots_noimageindex' => 'noimageindex',
		'robots_nosnippet'    => 'nosnippet',
	);
	$adv_keys       = array(
		'adv_max_snippet'       => 'max-snippet',
		'adv_max_video_preview' => 'max-video-preview',
		'adv_max_image_preview' => 'max-image-preview',
	);

	// Read an image pair (facebook_image/_id, twitter_image/_id) into the
	// { id, url } shape the panel's image control uses.
	$image_read = function ( $post_id, $prefix ) {
		$id  = (int) get_post_meta( (int) $post_id, "rank_math_{$prefix}_image_id", true );
		$url = (string) get_post_meta( (int) $post_id, "rank_math_{$prefix}_image", true );
		if ( $id && ! $url ) {
			$url = (string) wp_get_attachment_image_url( $id, 'medium' );
		}
		return ( $id || $url ) ? array( 'id' => $id, 'url' => $url ) : null;
	};
	// Write one: $att is an authorised attachment id (the REST layer already
	// vetted it) or 0/null to clear. The URL is DERIVED from the id, never
	// taken from the caller (og:image pinning, see the REST layer note).
	$image_write = function ( $post_id, $prefix, $att ) {
		$att = is_numeric( $att ) ? (int) $att : 0;
		if ( $att > 0 ) {
			$url = (string) wp_get_attachment_url( $att );
			if ( ! $url ) {
				$url = (string) wp_get_attachment_image_url( $att, 'full' );
			}
			update_post_meta( $post_id, "rank_math_{$prefix}_image_id", $att );
			update_post_meta( $post_id, "rank_math_{$prefix}_image", $url );
		} else {
			delete_post_meta( $post_id, "rank_math_{$prefix}_image_id" );
			delete_post_meta( $post_id, "rank_math_{$prefix}_image" );
		}
	};

	return array(
		'name'    => 'Rank Math',
		// Rank Math registers no SEO metabox for a role without its
		// onpage_general capability (Helper::has_cap('onpage_general') →
		// current_user_can('rank_math_onpage_general')), so editing at
		// edit_post alone would grant what Rank Math withholds.
		'can_edit' => function () {
			return current_user_can( 'rank_math_onpage_general' );
		},
		// The SERP preview resolves the EFFECTIVE title/description through
		// Rank Math's own machinery: replace_seo_fields() does their
		// meta-else-template fallback, and the variables register through
		// their Manager — created here when absent, because their container
		// only builds it once the setup wizard has run, and setup() is the
		// exact call their headless REST module makes (variables never
		// register on their own under REST). The vars map lets the client
		// re-resolve %tokens% live while the user types.
		'preview'  => function ( $post_id ) {
			try {
				$post = get_post( (int) $post_id );
				if ( ! $post || ! function_exists( 'rank_math' ) ) {
					return null;
				}
				$rm = rank_math();
				if ( ! isset( $rm->variables ) && class_exists( '\RankMath\Replace_Variables\Manager' ) ) {
					$rm->variables = new \RankMath\Replace_Variables\Manager();
				}
				if ( ! isset( $rm->variables ) ) {
					return null;
				}
				$rm->variables->setup();
				$vars = array();
				foreach ( array( 'title', 'sitename', 'sitedesc', 'sep', 'excerpt', 'page' ) as $key ) {
					$resolved = (string) \RankMath\Helper::replace_vars( '%' . $key . '%', $post );
					$vars[ $key ] = '%' . $key . '%' === $resolved ? '' : $resolved;
				}
				return array(
					'url'         => (string) \RankMath\Helper::replace_seo_fields( '%url%', $post ),
					'title'       => (string) \RankMath\Helper::replace_seo_fields( '%seo_title%', $post ),
					'description' => (string) \RankMath\Helper::replace_seo_fields( '%seo_description%', $post ),
					'fields'      => array( 'title' => 'title', 'description' => 'description' ),
					'vars'        => $vars,
				);
			} catch ( \Throwable $e ) {
				// Their machinery, their exceptions — the panel just
				// renders without a preview.
				return null;
			}
		},
		'fields' => function () {
			// Group visibility mirrors their per-tab capabilities
			// (canUser in their metabox screen): social needs
			// onpage_social, advanced needs onpage_advanced AND the site
			// in advanced setup mode, schema needs onpage_snippet. The
			// field map is rebuilt per request, so omitting a group here
			// also removes those keys from the write whitelist for this
			// user — the same trim their own save performs.
			$can = function ( $cap ) {
				return current_user_can( 'rank_math_' . $cap );
			};
			$advanced_mode = true;
			try {
				$advanced_mode = \RankMath\Helper::is_advanced_mode();
			} catch ( \Throwable $e ) { /* default to visible */ }
			// Twitter's own fields only apply when the card stops
			// inheriting Facebook — same reveal as their metabox.
			$tw = array( array( array( 'f' => 'twitter_use_facebook', 'op' => '==', 'v' => '0' ) ) );
			$groups = array();
			$groups[] = array(
					'group'  => __( 'Search appearance', 'minn-admin' ),
					'fields' => array(
						array( 'name' => 'title', 'label' => __( 'SEO title', 'minn-admin' ), 'type' => 'text', 'counter' => 60 ),
						array( 'name' => 'description', 'label' => __( 'Meta description', 'minn-admin' ), 'type' => 'textarea', 'counter' => 160 ),
						array( 'name' => 'focus_keyword', 'label' => __( 'Focus keyword', 'minn-admin' ), 'type' => 'text' ),
						array( 'name' => 'pillar_content', 'label' => __( 'Pillar content', 'minn-admin' ), 'type' => 'toggle', 'help' => __( 'Mark this as cornerstone content for internal-link suggestions.', 'minn-admin' ) ),
					),
			);
			if ( $can( 'onpage_social' ) ) {
				$groups[] = array(
					'group'  => __( 'Social', 'minn-admin' ),
					'fields' => array(
						array( 'name' => 'facebook_title', 'label' => __( 'Facebook title', 'minn-admin' ), 'type' => 'text' ),
						array( 'name' => 'facebook_description', 'label' => __( 'Facebook description', 'minn-admin' ), 'type' => 'textarea' ),
						array( 'name' => 'social_image', 'label' => __( 'Social thumbnail', 'minn-admin' ), 'type' => 'image' ),
						array( 'name' => 'twitter_use_facebook', 'label' => __( 'Twitter uses the Facebook card', 'minn-admin' ), 'type' => 'toggle' ),
						array(
							'name'    => 'twitter_card_type',
							'label'   => __( 'Twitter card type', 'minn-admin' ),
							'type'    => 'select',
							'cond'    => $tw,
							'options' => array(
								array( '', __( 'Site default', 'minn-admin' ) ),
								array( 'summary_large_image', __( 'Summary with large image', 'minn-admin' ) ),
								array( 'summary', __( 'Summary', 'minn-admin' ) ),
							),
						),
						array( 'name' => 'twitter_title', 'label' => __( 'Twitter title', 'minn-admin' ), 'type' => 'text', 'cond' => $tw ),
						array( 'name' => 'twitter_description', 'label' => __( 'Twitter description', 'minn-admin' ), 'type' => 'textarea', 'cond' => $tw ),
						array( 'name' => 'twitter_image', 'label' => __( 'Twitter image', 'minn-admin' ), 'type' => 'image', 'cond' => $tw ),
					),
				);
			}
			if ( $can( 'onpage_advanced' ) && $advanced_mode ) {
				$groups[] = array(
					'group'  => __( 'Advanced', 'minn-admin' ),
					'fields' => array(
						array(
							'name'    => 'robots_index',
							'label'   => __( 'Search indexing', 'minn-admin' ),
							'type'    => 'select',
							'options' => array(
								array( '', __( 'Default (post type setting)', 'minn-admin' ) ),
								array( 'index', __( 'Index', 'minn-admin' ) ),
								array( 'noindex', __( 'No index', 'minn-admin' ) ),
							),
						),
						array( 'name' => 'robots_nofollow', 'label' => __( 'Nofollow links', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'name' => 'robots_noarchive', 'label' => __( 'No archive', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'name' => 'robots_noimageindex', 'label' => __( 'No image index', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'name' => 'robots_nosnippet', 'label' => __( 'No snippet', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'name' => 'adv_max_snippet', 'label' => __( 'Max snippet length', 'minn-admin' ), 'type' => 'number', 'help' => __( 'Characters shown in results. -1 sets no limit; empty inherits the site default.', 'minn-admin' ) ),
						array( 'name' => 'adv_max_video_preview', 'label' => __( 'Max video preview', 'minn-admin' ), 'type' => 'number', 'help' => __( 'Seconds of video preview. -1 sets no limit; empty inherits the site default.', 'minn-admin' ) ),
						array(
							'name'    => 'adv_max_image_preview',
							'label'   => __( 'Max image preview', 'minn-admin' ),
							'type'    => 'select',
							'options' => array(
								array( '', __( 'Site default', 'minn-admin' ) ),
								array( 'none', __( 'None', 'minn-admin' ) ),
								array( 'standard', __( 'Standard', 'minn-admin' ) ),
								array( 'large', __( 'Large', 'minn-admin' ) ),
							),
						),
						array( 'name' => 'canonical', 'label' => __( 'Canonical URL', 'minn-admin' ), 'type' => 'text', 'sanitize' => 'url', 'help' => __( 'Leave empty to use the permalink.', 'minn-admin' ) ),
					),
				);
			}
			if ( $can( 'onpage_snippet' ) ) {
				$groups[] = array(
					'group'  => __( 'Schema', 'minn-admin' ),
					'fields' => array(
						array( 'name' => 'schema_in_use', 'label' => __( 'Schema in use', 'minn-admin' ), 'type' => 'note' ),
					),
					// The Schema Generator is Rank Math's own app; the
					// locked-field link is the doorway.
					'locked' => 1,
				);
			}
			return $groups;
		},
		'read'   => function ( $post_id ) use ( $base_read, $robots_toggles, $adv_keys, $image_read ) {
			$post_id = (int) $post_id;
			$out     = call_user_func( $base_read, $post_id );

			$robots = get_post_meta( $post_id, 'rank_math_robots', true );
			$robots = is_array( $robots ) ? $robots : array();
			$out['robots_index'] = in_array( 'noindex', $robots, true ) ? 'noindex'
				: ( in_array( 'index', $robots, true ) ? 'index' : '' );
			foreach ( $robots_toggles as $field => $directive ) {
				$out[ $field ] = in_array( $directive, $robots, true );
			}

			$adv = get_post_meta( $post_id, 'rank_math_advanced_robots', true );
			$adv = is_array( $adv ) ? $adv : array();
			foreach ( $adv_keys as $field => $key ) {
				$v = isset( $adv[ $key ] ) ? $adv[ $key ] : '';
				// Their shape stores false for a disabled directive.
				if ( false === $v || '' === $v || null === $v ) {
					$out[ $field ] = 'adv_max_image_preview' === $field ? '' : null;
				} else {
					$out[ $field ] = 'adv_max_image_preview' === $field ? (string) $v : (int) $v;
				}
			}

			$out['social_image']  = $image_read( $post_id, 'facebook' );
			$out['twitter_image'] = $image_read( $post_id, 'twitter' );
			// Absent means ON (their metabox default); only a stored 'off'
			// turns the inheritance off.
			$out['twitter_use_facebook'] = 'off' !== (string) get_post_meta( $post_id, 'rank_math_twitter_use_facebook', true );
			$out['twitter_card_type']    = (string) get_post_meta( $post_id, 'rank_math_twitter_card_type', true );
			$out['pillar_content']       = 'on' === (string) get_post_meta( $post_id, 'rank_math_pillar_content', true );
			$out['schema_in_use']        = minn_admin_seo_rank_math_schema_in_use( $post_id );
			return $out;
		},
		'write'  => function ( $post_id, $field, $clean ) use ( $base_write, $robots_toggles, $adv_keys, $image_write ) {
			$post_id = (int) $post_id;

			if ( 'social_image' === $field || 'twitter_image' === $field ) {
				$image_write( $post_id, 'social_image' === $field ? 'facebook' : 'twitter', $clean );
				return;
			}

			if ( 'robots_index' === $field || isset( $robots_toggles[ $field ] ) ) {
				$robots = get_post_meta( $post_id, 'rank_math_robots', true );
				$robots = is_array( $robots ) ? array_map( 'strval', $robots ) : array();
				if ( 'robots_index' === $field ) {
					$robots = array_diff( $robots, array( 'index', 'noindex' ) );
					if ( 'index' === $clean || 'noindex' === $clean ) {
						$robots[] = $clean;
					}
				} else {
					$directive = $robots_toggles[ $field ];
					$robots    = array_diff( $robots, array( $directive ) );
					if ( $clean ) {
						$robots[] = $directive;
					}
				}
				// Stable directive order, matching their metabox multicheck.
				$order  = array( 'index', 'noindex', 'nofollow', 'noarchive', 'noimageindex', 'nosnippet' );
				$robots = array_values( array_intersect( $order, $robots ) );
				if ( $robots ) {
					update_post_meta( $post_id, 'rank_math_robots', $robots );
				} else {
					// Nothing explicit left: inherit the post-type default.
					delete_post_meta( $post_id, 'rank_math_robots' );
				}
				return;
			}

			if ( isset( $adv_keys[ $field ] ) ) {
				$adv = get_post_meta( $post_id, 'rank_math_advanced_robots', true );
				$adv = is_array( $adv ) ? $adv : array();
				if ( '' === $clean || null === $clean ) {
					unset( $adv[ $adv_keys[ $field ] ] );
				} else {
					$adv[ $adv_keys[ $field ] ] = 'adv_max_image_preview' === $field ? (string) $clean : (int) $clean;
				}
				// Drop their disabled-directive false sentinels so an
				// all-cleared map deletes cleanly (absent = inherit).
				$adv = array_filter( $adv, function ( $v ) {
					return false !== $v && '' !== $v && null !== $v;
				} );
				if ( $adv ) {
					update_post_meta( $post_id, 'rank_math_advanced_robots', $adv );
				} else {
					delete_post_meta( $post_id, 'rank_math_advanced_robots' );
				}
				return;
			}

			if ( 'twitter_use_facebook' === $field ) {
				// 'off' must be STORED: their save deletes empty values and
				// an absent meta reads back as ON.
				update_post_meta( $post_id, 'rank_math_twitter_use_facebook', $clean ? 'on' : 'off' );
				return;
			}

			if ( 'pillar_content' === $field ) {
				if ( $clean ) {
					update_post_meta( $post_id, 'rank_math_pillar_content', 'on' );
				} else {
					delete_post_meta( $post_id, 'rank_math_pillar_content' );
				}
				return;
			}

			if ( 'twitter_card_type' === $field ) {
				if ( '' === $clean ) {
					delete_post_meta( $post_id, 'rank_math_twitter_card_type' );
				} else {
					update_post_meta( $post_id, 'rank_math_twitter_card_type', $clean );
				}
				return;
			}

			call_user_func( $base_write, $post_id, $field, $clean );
		},
	);
}

/**
 * What schema this post actually emits, mirroring Rank Math's own
 * seo_details column: schemas built in their generator live as
 * rank_math_schema_{Type} postmeta (JSON with @type); with none, the
 * post-type default applies unless rank_math_rich_snippet says off.
 *
 * @param int $post_id Post id.
 * @return string Human summary ('Article', 'Article, FAQPage', 'None').
 */
function minn_admin_seo_rank_math_schema_in_use( $post_id ) {
	$types = array();
	$meta  = get_post_meta( (int) $post_id );
	foreach ( array_keys( is_array( $meta ) ? $meta : array() ) as $key ) {
		if ( 0 !== strpos( (string) $key, 'rank_math_schema_' ) ) {
			continue;
		}
		// Their generator names the meta key after the @type
		// ('rank_math_schema_Article'), so the suffix IS the type — no need
		// to touch the serialized blob (never unserialize third-party data).
		$types[] = (string) substr( (string) $key, strlen( 'rank_math_schema_' ) );
	}
	if ( ! $types ) {
		try {
			if ( is_callable( array( '\RankMath\Helper', 'get_default_schema_type' ) ) ) {
				$default = \RankMath\Helper::get_default_schema_type( (int) $post_id );
				if ( $default ) {
					$types[] = ucfirst( (string) $default );
				}
			}
		} catch ( \Throwable $e ) { /* their helper, their exceptions */ }
	}
	return $types ? implode( ', ', array_unique( $types ) ) : __( 'None', 'minn-admin' );
}

/**
 * SureRank provider.
 *
 * SureRank does not keep one meta key per field. Everything lives in a few
 * GROUPED postmeta blobs (surerank_settings_general, _social, …) and their
 * own Get::all_post_meta() flattens those groups into a single map, which
 * is the shape their API writes back through
 * Post::update_post_meta_common(). Both are used here, so the grouping,
 * processing and any future shape change stay their business.
 *
 * THE EMPTY-VALUE TRAP (verified, and it bites both ways): SureRank treats
 * an empty SEO title or description as "inherit the site-wide template",
 * and their save path SUBSTITUTES that template into the post. Writing ''
 * through update_post_meta_common therefore stores '%title% - %site_name%'
 * as this post's own explicit title, and the next read shows the raw
 * template in the box. So clearing a field UNSETS the key inside the group
 * instead, and a stored value identical to the site template is read back
 * as empty, which is what it means.
 *
 * Every call is guarded: their models can never break a post save.
 */
function minn_admin_seo_surerank_provider() {
	$fields = array(
		'title'         => 'page_title',
		'description'   => 'page_description',
		'focus_keyword' => 'focus_keyword',
	);
	// The site-wide fallbacks a post inherits when its own field is empty.
	$site_defaults = function () {
		try {
			$d = \SureRank\Inc\Functions\Defaults::get_instance()->get_post_defaults( false );
			return ( is_array( $d ) && isset( $d['general'] ) && is_array( $d['general'] ) ) ? $d['general'] : array();
		} catch ( \Throwable $e ) {
			return array();
		}
	};
	// Their flattened per-post map, or an empty one.
	$flat = function ( $post_id ) {
		try {
			$m = \SureRank\Inc\Functions\Get::all_post_meta( (int) $post_id );
			return is_array( $m ) ? $m : array();
		} catch ( \Throwable $e ) {
			return array();
		}
	};
	$social_text = array(
		'facebook_title'       => 'facebook_title',
		'facebook_description' => 'facebook_description',
		'twitter_title'        => 'twitter_title',
		'twitter_description'  => 'twitter_description',
	);
	$robots = array(
		'robots_noindex'   => 'post_no_index',
		'robots_nofollow'  => 'post_no_follow',
		'robots_noarchive' => 'post_no_archive',
	);
	return array(
		'name'   => 'SureRank',
		// SureRank reserves per-post SEO to manage_options, and it says so in
		// two places: every one of its post routes registers validate_permission,
		// which falls through to a manage_options check, and its metabox does
		// not even load without the same test. Their filter is the documented
		// way a site widens that, so ask through it rather than hardcoding.
		'can_edit' => function () {
			return (bool) apply_filters( 'surerank_content_setting_access', current_user_can( 'manage_options' ) );
		},
		'fields' => function () {
			$tw = array( array( array( 'f' => 'twitter_use_facebook', 'op' => '==', 'v' => '0' ) ) );
			return array(
				array(
					'group'  => __( 'Search appearance', 'minn-admin' ),
					'fields' => array(
						array( 'name' => 'title', 'label' => __( 'SEO title', 'minn-admin' ), 'type' => 'text', 'counter' => 60 ),
						array( 'name' => 'description', 'label' => __( 'Meta description', 'minn-admin' ), 'type' => 'textarea', 'counter' => 160 ),
						array( 'name' => 'focus_keyword', 'label' => __( 'Focus keyword', 'minn-admin' ), 'type' => 'text' ),
					),
				),
				array(
					'group'  => __( 'Social', 'minn-admin' ),
					'fields' => array(
						array( 'name' => 'facebook_title', 'label' => __( 'Facebook title', 'minn-admin' ), 'type' => 'text' ),
						array( 'name' => 'facebook_description', 'label' => __( 'Facebook description', 'minn-admin' ), 'type' => 'textarea' ),
						array( 'name' => 'social_image', 'label' => __( 'Social thumbnail', 'minn-admin' ), 'type' => 'image' ),
						array( 'name' => 'twitter_use_facebook', 'label' => __( 'X (Twitter) uses the Facebook card', 'minn-admin' ), 'type' => 'toggle' ),
						array(
							'name'    => 'twitter_card_type',
							'label'   => __( 'X (Twitter) card type', 'minn-admin' ),
							'type'    => 'select',
							'cond'    => $tw,
							'options' => array(
								array( '', __( 'Site default', 'minn-admin' ) ),
								array( 'summary_large_image', __( 'Summary with large image', 'minn-admin' ) ),
								array( 'summary', __( 'Summary', 'minn-admin' ) ),
							),
						),
						array( 'name' => 'twitter_title', 'label' => __( 'X (Twitter) title', 'minn-admin' ), 'type' => 'text', 'cond' => $tw ),
						array( 'name' => 'twitter_description', 'label' => __( 'X (Twitter) description', 'minn-admin' ), 'type' => 'textarea', 'cond' => $tw ),
						array( 'name' => 'twitter_image', 'label' => __( 'X (Twitter) image', 'minn-admin' ), 'type' => 'image', 'cond' => $tw ),
					),
				),
				array(
					'group'  => __( 'Advanced', 'minn-admin' ),
					'fields' => array(
						array( 'name' => 'robots_noindex', 'label' => __( 'No index', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'name' => 'robots_nofollow', 'label' => __( 'Nofollow links', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'name' => 'robots_noarchive', 'label' => __( 'No archive', 'minn-admin' ), 'type' => 'toggle' ),
					),
				),
			);
		},
		'read'   => function ( $post_id ) use ( $fields, $site_defaults, $flat, $social_text, $robots ) {
			$meta = $flat( $post_id );
			$def  = $site_defaults();
			$out  = array();
			foreach ( $fields as $field => $key ) {
				$value = isset( $meta[ $key ] ) ? (string) $meta[ $key ] : '';
				// A value equal to the site template is SureRank's way of
				// saying this post sets nothing of its own.
				if ( '' !== $value && isset( $def[ $key ] ) && (string) $def[ $key ] === $value ) {
					$value = '';
				}
				$out[ $field ] = $value;
			}
			$id  = isset( $meta['facebook_image_id'] ) ? (int) $meta['facebook_image_id'] : 0;
			$url = isset( $meta['facebook_image_url'] ) ? (string) $meta['facebook_image_url'] : '';
			if ( $id && ! $url ) {
				$url = (string) wp_get_attachment_image_url( $id, 'medium' );
			}
			$out['social_image'] = ( $id || $url ) ? array( 'id' => $id, 'url' => $url ) : null;
			foreach ( $social_text as $field => $key ) {
				$out[ $field ] = isset( $meta[ $key ] ) ? (string) $meta[ $key ] : '';
			}
			$tw_id  = isset( $meta['twitter_image_id'] ) ? (int) $meta['twitter_image_id'] : 0;
			$tw_url = isset( $meta['twitter_image_url'] ) ? (string) $meta['twitter_image_url'] : '';
			if ( $tw_id && ! $tw_url ) {
				$tw_url = (string) wp_get_attachment_image_url( $tw_id, 'medium' );
			}
			$out['twitter_image'] = ( $tw_id || $tw_url ) ? array( 'id' => $tw_id, 'url' => $tw_url ) : null;
			// Absent means ON (their post default is true); only a stored
			// falsy value turns the inheritance off.
			$out['twitter_use_facebook'] = ! isset( $meta['twitter_same_as_facebook'] )
				|| ! in_array( $meta['twitter_same_as_facebook'], array( false, 'false', 0, '0', '' ), true );
			$out['twitter_card_type'] = isset( $meta['twitter_card_type'] ) ? (string) $meta['twitter_card_type'] : '';
			foreach ( $robots as $field => $key ) {
				$out[ $field ] = isset( $meta[ $key ] ) && 'yes' === (string) $meta[ $key ];
			}
			return $out;
		},
		'write'  => function ( $post_id, $field, $clean ) use ( $fields, $social_text, $robots ) {
			$post_id = (int) $post_id;
			try {
				if ( 'social_image' === $field ) {
					// The address is always worked out from the media item's
					// number, never accepted from the caller. That is the rule
					// the write path states and every other provider keeps: an
					// accepted address would let anyone who can edit a draft
					// pin any picture on the internet as the social image of a
					// post somebody else publishes later.
					$id  = 0;
					if ( is_array( $clean ) ) {
						$id = isset( $clean['id'] ) ? (int) $clean['id'] : 0;
					} elseif ( is_numeric( $clean ) ) {
						$id = (int) $clean;
					}
					$url = $id > 0 ? (string) wp_get_attachment_url( $id ) : '';
					if ( $id > 0 || '' !== $url ) {
						\SureRank\Inc\API\Post::update_post_meta_common( $post_id, array(
							'facebook_image_id'  => $id,
							'facebook_image_url' => $url,
						) );
					} else {
						minn_admin_seo_surerank_unset( $post_id, 'social', array( 'facebook_image_id', 'facebook_image_url' ) );
					}
					return;
				}
				if ( 'twitter_image' === $field ) {
					$id = is_numeric( $clean ) ? (int) $clean : 0;
					if ( $id > 0 ) {
						\SureRank\Inc\API\Post::update_post_meta_common( $post_id, array(
							'twitter_image_id'  => $id,
							'twitter_image_url' => (string) wp_get_attachment_url( $id ),
						) );
					} else {
						minn_admin_seo_surerank_unset( $post_id, 'social', array( 'twitter_image_id', 'twitter_image_url' ) );
					}
					return;
				}
				if ( 'twitter_use_facebook' === $field ) {
					// Absent reads as ON, so off must be STORED explicitly.
					\SureRank\Inc\API\Post::update_post_meta_common( $post_id, array( 'twitter_same_as_facebook' => (bool) $clean ) );
					return;
				}
				if ( 'twitter_card_type' === $field ) {
					if ( '' === $clean ) {
						minn_admin_seo_surerank_unset( $post_id, 'social', array( 'twitter_card_type' ) );
					} else {
						\SureRank\Inc\API\Post::update_post_meta_common( $post_id, array( 'twitter_card_type' => $clean ) );
					}
					return;
				}
				if ( isset( $robots[ $field ] ) ) {
					// post_no_* are SCALAR metas (surerank_settings_post_no_index),
					// not group keys; absent means the site-wide robots rules.
					if ( $clean ) {
						\SureRank\Inc\API\Post::update_post_meta_common( $post_id, array( $robots[ $field ] => 'yes' ) );
					} else {
						delete_post_meta( $post_id, 'surerank_settings_' . $robots[ $field ] );
					}
					return;
				}
				if ( isset( $social_text[ $field ] ) ) {
					if ( '' === $clean ) {
						// The same template trap as the general group.
						minn_admin_seo_surerank_unset( $post_id, 'social', array( $social_text[ $field ] ) );
					} else {
						\SureRank\Inc\API\Post::update_post_meta_common( $post_id, array( $social_text[ $field ] => $clean ) );
					}
					return;
				}
				if ( ! isset( $fields[ $field ] ) ) {
					return;
				}
				$key = $fields[ $field ];
				if ( '' === $clean ) {
					// See the file note: their save path would substitute
					// the site template here and store it as this post's own.
					minn_admin_seo_surerank_unset( $post_id, 'general', array( $key ) );
					return;
				}
				\SureRank\Inc\API\Post::update_post_meta_common( $post_id, array( $key => $clean ) );
			} catch ( \Throwable $e ) {
				// A vendor model must never break the post save around it.
				return;
			}
		},
	);
}

/**
 * Remove keys from one of SureRank's grouped postmeta blobs, which is what
 * "this post sets nothing here" really looks like in their storage.
 *
 * @param int      $post_id Post id.
 * @param string   $group   Group suffix ('general', 'social', …).
 * @param string[] $keys    Keys to drop.
 */
function minn_admin_seo_surerank_unset( $post_id, $group, $keys ) {
	$meta_key = 'surerank_settings_' . $group;
	$stored   = get_post_meta( (int) $post_id, $meta_key, true );
	if ( ! is_array( $stored ) ) {
		return;
	}
	$changed = false;
	foreach ( $keys as $key ) {
		if ( array_key_exists( $key, $stored ) ) {
			unset( $stored[ $key ] );
			$changed = true;
		}
	}
	if ( ! $changed ) {
		return;
	}
	try {
		\SureRank\Inc\Functions\Update::post_meta( (int) $post_id, $meta_key, $stored );
	} catch ( \Throwable $e ) {
		return;
	}
}

/**
 * Squirrly SEO provider.
 *
 * Per-page SEO lives in {prefix}qss as a serialized SQ_Models_Domain_Sq
 * blob (plus a few _sq_* postmeta fallbacks). Never read or write that
 * column ourselves: Squirrly 14.2 ships SQ_Models_Api_Seo as a
 * request-free service (getSeo / saveSeo) that owns the hash, the table
 * and sanitization. Partial writes: only the keys we pass are touched,
 * empty string clears. Focus keyword maps to their `keywords` field
 * (comma-separated in their store). Social thumbnail is og_media, a URL.
 *
 * Every call is guarded: their model can never break a post save.
 *
 * @return array
 */
function minn_admin_seo_squirrly_provider() {
	$api = function () {
		return SQ_Classes_ObjController::getClass( 'SQ_Models_Api_Seo' );
	};
	return array(
		'name'    => 'Squirrly SEO',
		// Their own snippet gate. Squirrly grants sq_manage_snippet to every
		// role holding edit_posts, so on a stock install this agrees with the
		// route anyway; it only speaks up on a site that stripped the cap,
		// which is what the other six predicates are for.
		'can_edit' => function () {
			if ( ! class_exists( 'SQ_Classes_Helpers_Tools' ) || ! method_exists( 'SQ_Classes_Helpers_Tools', 'userCan' ) ) {
				return true;
			}
			try {
				return (bool) SQ_Classes_Helpers_Tools::userCan( 'sq_manage_snippet' );
			} catch ( \Throwable $e ) {
				return true;
			}
		},
		'fields' => function () {
			return array(
				array(
					'group'  => __( 'Search appearance', 'minn-admin' ),
					'fields' => array(
						array( 'name' => 'title', 'label' => __( 'SEO title', 'minn-admin' ), 'type' => 'text', 'counter' => 60 ),
						array( 'name' => 'description', 'label' => __( 'Meta description', 'minn-admin' ), 'type' => 'textarea', 'counter' => 160 ),
						array( 'name' => 'focus_keyword', 'label' => __( 'Focus keyword', 'minn-admin' ), 'type' => 'text' ),
					),
				),
				array(
					'group'  => __( 'Social', 'minn-admin' ),
					'fields' => array(
						array( 'name' => 'facebook_title', 'label' => __( 'Facebook title', 'minn-admin' ), 'type' => 'text' ),
						array( 'name' => 'facebook_description', 'label' => __( 'Facebook description', 'minn-admin' ), 'type' => 'textarea' ),
						array( 'name' => 'social_image', 'label' => __( 'Social thumbnail', 'minn-admin' ), 'type' => 'image' ),
						array( 'name' => 'twitter_title', 'label' => __( 'X (Twitter) title', 'minn-admin' ), 'type' => 'text', 'help' => __( 'Leave empty to reuse the Facebook card.', 'minn-admin' ) ),
						array( 'name' => 'twitter_description', 'label' => __( 'X (Twitter) description', 'minn-admin' ), 'type' => 'textarea' ),
					),
				),
				array(
					'group'  => __( 'Advanced', 'minn-admin' ),
					'fields' => array(
						array( 'name' => 'robots_noindex', 'label' => __( 'No index', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'name' => 'robots_nofollow', 'label' => __( 'Nofollow links', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'name' => 'canonical', 'label' => __( 'Canonical URL', 'minn-admin' ), 'type' => 'text', 'sanitize' => 'url', 'help' => __( 'Leave empty to use the permalink.', 'minn-admin' ) ),
					),
				),
			);
		},
		'read'   => function ( $post_id ) use ( $api ) {
			$out = array(
				'title'         => '',
				'description'   => '',
				'focus_keyword' => '',
				'social_image'  => null,
			);
			try {
				$data = $api()->getSeo( array( 'post_id' => (int) $post_id ) );
				if ( is_wp_error( $data ) || ! is_array( $data ) ) {
					return $out;
				}
				$seo = ( isset( $data['seo'] ) && is_array( $data['seo'] ) ) ? $data['seo'] : array();
				$out['title']         = isset( $seo['title'] ) ? (string) $seo['title'] : '';
				$out['description']   = isset( $seo['description'] ) ? (string) $seo['description'] : '';
				$out['focus_keyword'] = isset( $seo['keywords'] ) ? (string) $seo['keywords'] : '';
				$out['robots_noindex']       = ! empty( $seo['noindex'] );
				$out['robots_nofollow']      = ! empty( $seo['nofollow'] );
				$out['canonical']            = isset( $seo['canonical'] ) ? (string) $seo['canonical'] : '';
				$out['facebook_title']       = isset( $seo['og_title'] ) ? (string) $seo['og_title'] : '';
				$out['facebook_description'] = isset( $seo['og_description'] ) ? (string) $seo['og_description'] : '';
				$out['twitter_title']        = isset( $seo['tw_title'] ) ? (string) $seo['tw_title'] : '';
				$out['twitter_description']  = isset( $seo['tw_description'] ) ? (string) $seo['tw_description'] : '';
				$url = isset( $seo['og_media'] ) ? (string) $seo['og_media'] : '';
				if ( '' !== $url ) {
					$out['social_image'] = array(
						'id'  => (int) attachment_url_to_postid( $url ),
						'url' => $url,
					);
				}
			} catch ( \Throwable $e ) {
				return $out;
			}
			return $out;
		},
		'write'  => function ( $post_id, $field, $clean ) use ( $api ) {
			$post_id = (int) $post_id;
			try {
				$fields = array();
				if ( 'social_image' === $field ) {
					// Worked out from the media item's number, never accepted
					// from the caller. See the note on the same field above.
					$id = 0;
					if ( is_array( $clean ) ) {
						$id = isset( $clean['id'] ) ? (int) $clean['id'] : 0;
					} elseif ( is_numeric( $clean ) ) {
						$id = (int) $clean;
					}
					$fields['og_media'] = $id > 0 ? (string) wp_get_attachment_url( $id ) : '';
				} elseif ( 'title' === $field ) {
					$fields['title'] = (string) $clean;
				} elseif ( 'description' === $field ) {
					$fields['description'] = (string) $clean;
				} elseif ( 'focus_keyword' === $field ) {
					$fields['keywords'] = (string) $clean;
				} elseif ( 'robots_noindex' === $field ) {
					$fields['noindex'] = $clean ? 1 : 0;
				} elseif ( 'robots_nofollow' === $field ) {
					$fields['nofollow'] = $clean ? 1 : 0;
				} elseif ( 'canonical' === $field ) {
					$fields['canonical'] = (string) $clean;
				} elseif ( 'facebook_title' === $field ) {
					$fields['og_title'] = (string) $clean;
				} elseif ( 'facebook_description' === $field ) {
					$fields['og_description'] = (string) $clean;
				} elseif ( 'twitter_title' === $field ) {
					$fields['tw_title'] = (string) $clean;
				} elseif ( 'twitter_description' === $field ) {
					$fields['tw_description'] = (string) $clean;
				} else {
					return;
				}
				// Their first save after a missing table creates the table
				// then returns an error; make sure the table is there first.
				SQ_Classes_ObjController::getClass( 'SQ_Models_Qss' )->checkTableExists();
				$api()->saveSeo( array( 'post_id' => $post_id ), $fields );
			} catch ( \Throwable $e ) {
				return;
			}
		},
	);
}

/**
 * Yoast SEO provider: full per-post metabox depth over their documented
 * meta keys (their own importers write the same postmeta).
 *
 * Storage facts (inc/class-wpseo-meta.php, verified):
 * - meta-robots-noindex: '0'/absent = post-type default, '2' = index,
 *   '1' = noindex. meta-robots-nofollow: '1' = nofollow, '0'/absent =
 *   follow. meta-robots-adv: csv of noimageindex/noarchive/nosnippet.
 * - is_cornerstone: their indexable builder tests === '1'.
 * - The advanced fields (robots + canonical) are editable only for users
 *   with wpseo_edit_advanced_metadata, unless the site turned
 *   disableadvanced_meta off — mirrored by omitting the group, which also
 *   trims those keys from the per-user write whitelist, the same strip
 *   their own save performs (class-wpseo-meta.php save_meta_boxes).
 * - Social fields exist per network only while the site-wide opengraph /
 *   twitter switches are on (their init() registers them conditionally).
 * - Schema page/article types come from Schema_Types::PAGE_TYPES /
 *   ARTICLE_TYPES; the article select hides on pages like their metabox.
 *
 * @return array
 */
function minn_admin_seo_yoast_provider() {
	$keys = array(
		'title'               => '_yoast_wpseo_title',
		'description'         => '_yoast_wpseo_metadesc',
		'focus_keyword'       => '_yoast_wpseo_focuskw',
		'facebook_title'      => '_yoast_wpseo_opengraph-title',
		'facebook_description'=> '_yoast_wpseo_opengraph-description',
		'twitter_title'       => '_yoast_wpseo_twitter-title',
		'twitter_description' => '_yoast_wpseo_twitter-description',
		'canonical'           => '_yoast_wpseo_canonical',
		'schema_page_type'    => '_yoast_wpseo_schema_page_type',
		'schema_article_type' => '_yoast_wpseo_schema_article_type',
	);
	$base       = minn_admin_seo_meta_provider( 'Yoast SEO', $keys );
	$base_read  = $base['read'];
	$base_write = $base['write'];

	$adv_directives = array( 'robots_noarchive' => 'noarchive', 'robots_noimageindex' => 'noimageindex', 'robots_nosnippet' => 'nosnippet' );

	// Advanced metadata permission, exactly their metabox test.
	$adv_allowed = function () {
		try {
			if ( class_exists( 'WPSEO_Capability_Utils' ) && \WPSEO_Capability_Utils::current_user_can( 'wpseo_edit_advanced_metadata' ) ) {
				return true;
			}
			return class_exists( 'WPSEO_Options' ) && false === \WPSEO_Options::get( 'disableadvanced_meta' );
		} catch ( \Throwable $e ) {
			return false;
		}
	};
	$social_on = function ( $network ) {
		try {
			return class_exists( 'WPSEO_Options' ) && true === \WPSEO_Options::get( $network );
		} catch ( \Throwable $e ) {
			return false;
		}
	};
	$image_read = function ( $post_id, $prefix ) {
		$id  = (int) get_post_meta( (int) $post_id, "_yoast_wpseo_{$prefix}-image-id", true );
		$url = (string) get_post_meta( (int) $post_id, "_yoast_wpseo_{$prefix}-image", true );
		if ( $id && ! $url ) {
			$url = (string) wp_get_attachment_image_url( $id, 'medium' );
		}
		return ( $id || $url ) ? array( 'id' => $id, 'url' => $url ) : null;
	};
	$image_write = function ( $post_id, $prefix, $att ) {
		$att = is_numeric( $att ) ? (int) $att : 0;
		if ( $att > 0 ) {
			$url = (string) wp_get_attachment_url( $att );
			if ( ! $url ) {
				$url = (string) wp_get_attachment_image_url( $att, 'full' );
			}
			// STRING id on purpose: Yoast registers sanitize_post_meta on
			// its keys and the default case blanks any non-string value
			// (is_string gate) — an integer id stores as ''.
			update_post_meta( $post_id, "_yoast_wpseo_{$prefix}-image-id", (string) $att );
			update_post_meta( $post_id, "_yoast_wpseo_{$prefix}-image", $url );
		} else {
			delete_post_meta( $post_id, "_yoast_wpseo_{$prefix}-image-id" );
			delete_post_meta( $post_id, "_yoast_wpseo_{$prefix}-image" );
		}
	};

	return array(
		'name'    => 'Yoast SEO',
		'fields'  => function ( $post_id = 0 ) use ( $adv_allowed, $social_on ) {
			$groups   = array();
			$groups[] = array(
				'group'  => __( 'Search appearance', 'minn-admin' ),
				'fields' => array(
					array( 'name' => 'title', 'label' => __( 'SEO title', 'minn-admin' ), 'type' => 'text', 'counter' => 60 ),
					array( 'name' => 'description', 'label' => __( 'Meta description', 'minn-admin' ), 'type' => 'textarea', 'counter' => 160 ),
					array( 'name' => 'focus_keyword', 'label' => __( 'Focus keyword', 'minn-admin' ), 'type' => 'text' ),
					array( 'name' => 'is_cornerstone', 'label' => __( 'Cornerstone content', 'minn-admin' ), 'type' => 'toggle', 'help' => __( 'Mark this as one of the most important pages on the site.', 'minn-admin' ) ),
				),
			);
			$social = array();
			if ( $social_on( 'opengraph' ) ) {
				$social[] = array( 'name' => 'facebook_title', 'label' => __( 'Facebook title', 'minn-admin' ), 'type' => 'text' );
				$social[] = array( 'name' => 'facebook_description', 'label' => __( 'Facebook description', 'minn-admin' ), 'type' => 'textarea' );
				$social[] = array( 'name' => 'social_image', 'label' => __( 'Social thumbnail', 'minn-admin' ), 'type' => 'image' );
			}
			if ( $social_on( 'twitter' ) ) {
				$social[] = array( 'name' => 'twitter_title', 'label' => __( 'X (Twitter) title', 'minn-admin' ), 'type' => 'text', 'help' => __( 'Leave empty to reuse the Facebook card.', 'minn-admin' ) );
				$social[] = array( 'name' => 'twitter_description', 'label' => __( 'X (Twitter) description', 'minn-admin' ), 'type' => 'textarea' );
				$social[] = array( 'name' => 'twitter_image', 'label' => __( 'X (Twitter) image', 'minn-admin' ), 'type' => 'image' );
			}
			if ( $social ) {
				$groups[] = array( 'group' => __( 'Social', 'minn-admin' ), 'fields' => $social );
			}
			if ( $adv_allowed() ) {
				$groups[] = array(
					'group'  => __( 'Advanced', 'minn-admin' ),
					'fields' => array(
						array(
							'name'    => 'robots_index',
							'label'   => __( 'Search indexing', 'minn-admin' ),
							'type'    => 'select',
							'options' => array(
								array( '', __( 'Default (post type setting)', 'minn-admin' ) ),
								array( 'index', __( 'Index', 'minn-admin' ) ),
								array( 'noindex', __( 'No index', 'minn-admin' ) ),
							),
						),
						array( 'name' => 'robots_nofollow', 'label' => __( 'Nofollow links', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'name' => 'robots_noarchive', 'label' => __( 'No archive', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'name' => 'robots_noimageindex', 'label' => __( 'No image index', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'name' => 'robots_nosnippet', 'label' => __( 'No snippet', 'minn-admin' ), 'type' => 'toggle' ),
						array( 'name' => 'canonical', 'label' => __( 'Canonical URL', 'minn-admin' ), 'type' => 'text', 'sanitize' => 'url', 'help' => __( 'Leave empty to use the permalink.', 'minn-admin' ) ),
					),
				);
			}
			$page_types = array( 'WebPage', 'ItemPage', 'AboutPage', 'FAQPage', 'QAPage', 'ProfilePage', 'ContactPage', 'MedicalWebPage', 'CollectionPage', 'CheckoutPage', 'RealEstateListing', 'SearchResultsPage' );
			$article_types = array( 'Article', 'BlogPosting', 'SocialMediaPosting', 'NewsArticle', 'AdvertiserContentArticle', 'SatiricalArticle', 'ScholarlyArticle', 'TechArticle', 'Report', 'None' );
			$schema_fields = array(
				array(
					'name'    => 'schema_page_type',
					'label'   => __( 'Page type', 'minn-admin' ),
					'type'    => 'select',
					'options' => array_merge(
						array( array( '', __( 'Site default', 'minn-admin' ) ) ),
						array_map( function ( $t ) { return array( $t, $t ); }, $page_types )
					),
				),
			);
			// Their metabox hides the article select on pages.
			$post_type = $post_id ? get_post_type( (int) $post_id ) : '';
			if ( 'page' !== $post_type ) {
				$schema_fields[] = array(
					'name'    => 'schema_article_type',
					'label'   => __( 'Article type', 'minn-admin' ),
					'type'    => 'select',
					'options' => array_merge(
						array( array( '', __( 'Site default', 'minn-admin' ) ) ),
						array_map( function ( $t ) { return array( $t, $t ); }, $article_types )
					),
				);
			}
			// Their get_meta_field_defs gates 'schema' on exactly the test it
			// gates 'advanced' on, and strips both from the save when it fails,
			// so a principal who may not set a canonical may not set the page's
			// schema type either.
			if ( $adv_allowed() ) {
				$groups[] = array( 'group' => __( 'Schema', 'minn-admin' ), 'fields' => $schema_fields );
			}
			return $groups;
		},
		'preview' => function ( $post_id ) {
			try {
				$post = get_post( (int) $post_id );
				if ( ! $post || ! function_exists( 'wpseo_replace_vars' ) || ! class_exists( 'WPSEO_Options' ) ) {
					return null;
				}
				$title_tpl = (string) get_post_meta( $post->ID, '_yoast_wpseo_title', true );
				if ( '' === $title_tpl ) {
					$title_tpl = (string) \WPSEO_Options::get( 'title-' . $post->post_type );
				}
				if ( '' === $title_tpl ) {
					$title_tpl = '%%title%% %%sep%% %%sitename%%';
				}
				$desc_tpl = (string) get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
				if ( '' === $desc_tpl ) {
					$desc_tpl = (string) \WPSEO_Options::get( 'metadesc-' . $post->post_type );
				}
				if ( '' === $desc_tpl ) {
					$desc_tpl = '%%excerpt%%';
				}
				$vars = array();
				foreach ( array( 'title', 'sitename', 'sitedesc', 'sep', 'excerpt', 'page' ) as $key ) {
					$resolved     = (string) wpseo_replace_vars( '%%' . $key . '%%', $post );
					$vars[ $key ] = '%%' . $key . '%%' === $resolved ? '' : $resolved;
				}
				return array(
					'url'         => (string) get_permalink( $post ),
					'title'       => (string) wpseo_replace_vars( $title_tpl, $post ),
					'description' => (string) wpseo_replace_vars( $desc_tpl, $post ),
					'fields'      => array( 'title' => 'title', 'description' => 'description' ),
					'vars'        => $vars,
				);
			} catch ( \Throwable $e ) {
				return null;
			}
		},
		'read'    => function ( $post_id ) use ( $base_read, $adv_directives, $image_read ) {
			$post_id = (int) $post_id;
			$out     = call_user_func( $base_read, $post_id );

			$noindex = (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', true );
			$out['robots_index']    = '1' === $noindex ? 'noindex' : ( '2' === $noindex ? 'index' : '' );
			$out['robots_nofollow'] = '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', true );
			$adv = array_filter( array_map( 'trim', explode( ',', (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-adv', true ) ) ) );
			foreach ( $adv_directives as $field => $directive ) {
				$out[ $field ] = in_array( $directive, $adv, true );
			}
			$out['is_cornerstone'] = '1' === (string) get_post_meta( $post_id, '_yoast_wpseo_is_cornerstone', true );
			$out['social_image']   = $image_read( $post_id, 'opengraph' );
			$out['twitter_image']  = $image_read( $post_id, 'twitter' );
			return $out;
		},
		'write'   => function ( $post_id, $field, $clean ) use ( $base_write, $adv_directives, $image_write ) {
			$post_id = (int) $post_id;

			if ( 'social_image' === $field || 'twitter_image' === $field ) {
				$image_write( $post_id, 'social_image' === $field ? 'opengraph' : 'twitter', $clean );
				return;
			}
			if ( 'robots_index' === $field ) {
				// '' means the post-type default, which is the ABSENT meta.
				if ( 'index' === $clean || 'noindex' === $clean ) {
					update_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex', 'noindex' === $clean ? '1' : '2' );
				} else {
					delete_post_meta( $post_id, '_yoast_wpseo_meta-robots-noindex' );
				}
				return;
			}
			if ( 'robots_nofollow' === $field ) {
				if ( $clean ) {
					update_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow', '1' );
				} else {
					delete_post_meta( $post_id, '_yoast_wpseo_meta-robots-nofollow' );
				}
				return;
			}
			if ( isset( $adv_directives[ $field ] ) ) {
				$adv = array_filter( array_map( 'trim', explode( ',', (string) get_post_meta( $post_id, '_yoast_wpseo_meta-robots-adv', true ) ) ) );
				$adv = array_diff( $adv, array( $adv_directives[ $field ] ) );
				if ( $clean ) {
					$adv[] = $adv_directives[ $field ];
				}
				// Stable order, matching their checkbox order.
				$adv = array_values( array_intersect( array( 'noimageindex', 'noarchive', 'nosnippet' ), $adv ) );
				if ( $adv ) {
					update_post_meta( $post_id, '_yoast_wpseo_meta-robots-adv', implode( ',', $adv ) );
				} else {
					delete_post_meta( $post_id, '_yoast_wpseo_meta-robots-adv' );
				}
				return;
			}
			if ( 'is_cornerstone' === $field ) {
				// Their indexable builder tests === '1'.
				if ( $clean ) {
					update_post_meta( $post_id, '_yoast_wpseo_is_cornerstone', '1' );
				} else {
					delete_post_meta( $post_id, '_yoast_wpseo_is_cornerstone' );
				}
				return;
			}
			call_user_func( $base_write, $post_id, $field, $clean );
		},
	);
}

/**
 * Shared depth provider for SEOPress and its SiteSEO fork — same storage
 * shape under different meta prefixes.
 *
 * Storage facts (their metabox save + frontend, verified):
 * - Robots are independent 'yes' flags with INVERTED names: robots_index
 *   = 'yes' means NOINDEX (their noindex_post_option test), same for
 *   follow/imageindex/snippet (SiteSEO also keeps archive; SEOPress
 *   dropped the per-post noarchive). Absent = the site-wide default, so
 *   off deletes the meta like their own save.
 * - Social images store FOUR metas per network (img URL, attachment id,
 *   width, height), delete-on-empty; their og:image:width tags read the
 *   stored pair.
 * - Twitter fields fall back to the Facebook card on their own; there is
 *   no use-Facebook toggle to mirror.
 *
 * @param string        $name         Provider display name.
 * @param string        $mp           Meta prefix ('_seopress_' or '_siteseo_').
 * @param bool          $with_archive Whether the per-post noarchive flag exists.
 * @param callable|null $can_edit     Vendor metabox-permission predicate.
 * @return array
 */
function minn_admin_seo_press_family_provider( $name, $mp, $with_archive, $can_edit ) {
	$base = minn_admin_seo_meta_provider( $name, array(
		'title'                => "{$mp}titles_title",
		'description'          => "{$mp}titles_desc",
		'focus_keyword'        => "{$mp}analysis_target_kw",
		'facebook_title'       => "{$mp}social_fb_title",
		'facebook_description' => "{$mp}social_fb_desc",
		'twitter_title'        => "{$mp}social_twitter_title",
		'twitter_description'  => "{$mp}social_twitter_desc",
		'canonical'            => "{$mp}robots_canonical",
	), $can_edit );
	$base_read  = $base['read'];
	$base_write = $base['write'];

	$robots = array(
		'robots_noindex'      => "{$mp}robots_index",
		'robots_nofollow'     => "{$mp}robots_follow",
		'robots_noimageindex' => "{$mp}robots_imageindex",
		'robots_nosnippet'    => "{$mp}robots_snippet",
	);
	if ( $with_archive ) {
		$robots['robots_noarchive'] = "{$mp}robots_archive";
	}

	$image_read = function ( $post_id, $net ) use ( $mp ) {
		$url = (string) get_post_meta( (int) $post_id, "{$mp}social_{$net}_img", true );
		$id  = (int) get_post_meta( (int) $post_id, "{$mp}social_{$net}_img_attachment_id", true );
		if ( '' === $url && ! $id ) {
			return null;
		}
		if ( '' === $url && $id ) {
			$url = (string) wp_get_attachment_image_url( $id, 'medium' );
		}
		return array( 'id' => $id, 'url' => $url );
	};
	$image_write = function ( $post_id, $net, $att ) use ( $mp ) {
		$att = is_numeric( $att ) ? (int) $att : 0;
		$src = $att > 0 ? wp_get_attachment_image_src( $att, 'full' ) : false;
		if ( $att > 0 && is_array( $src ) && ! empty( $src[0] ) ) {
			update_post_meta( $post_id, "{$mp}social_{$net}_img", (string) $src[0] );
			update_post_meta( $post_id, "{$mp}social_{$net}_img_attachment_id", (string) $att );
			update_post_meta( $post_id, "{$mp}social_{$net}_img_width", (string) $src[1] );
			update_post_meta( $post_id, "{$mp}social_{$net}_img_height", (string) $src[2] );
		} else {
			delete_post_meta( $post_id, "{$mp}social_{$net}_img" );
			delete_post_meta( $post_id, "{$mp}social_{$net}_img_attachment_id" );
			delete_post_meta( $post_id, "{$mp}social_{$net}_img_width" );
			delete_post_meta( $post_id, "{$mp}social_{$net}_img_height" );
		}
	};

	return array(
		'name'     => $name,
		'can_edit' => $can_edit,
		'fields'   => function () use ( $with_archive ) {
			$advanced = array(
				array( 'name' => 'robots_noindex', 'label' => __( 'No index', 'minn-admin' ), 'type' => 'toggle' ),
				array( 'name' => 'robots_nofollow', 'label' => __( 'Nofollow links', 'minn-admin' ), 'type' => 'toggle' ),
			);
			if ( $with_archive ) {
				$advanced[] = array( 'name' => 'robots_noarchive', 'label' => __( 'No archive', 'minn-admin' ), 'type' => 'toggle' );
			}
			$advanced[] = array( 'name' => 'robots_noimageindex', 'label' => __( 'No image index', 'minn-admin' ), 'type' => 'toggle' );
			$advanced[] = array( 'name' => 'robots_nosnippet', 'label' => __( 'No snippet', 'minn-admin' ), 'type' => 'toggle' );
			$advanced[] = array( 'name' => 'canonical', 'label' => __( 'Canonical URL', 'minn-admin' ), 'type' => 'text', 'sanitize' => 'url', 'help' => __( 'Leave empty to use the permalink.', 'minn-admin' ) );
			return array(
				array(
					'group'  => __( 'Search appearance', 'minn-admin' ),
					'fields' => array(
						array( 'name' => 'title', 'label' => __( 'SEO title', 'minn-admin' ), 'type' => 'text', 'counter' => 60 ),
						array( 'name' => 'description', 'label' => __( 'Meta description', 'minn-admin' ), 'type' => 'textarea', 'counter' => 160 ),
						array( 'name' => 'focus_keyword', 'label' => __( 'Focus keyword', 'minn-admin' ), 'type' => 'text' ),
					),
				),
				array(
					'group'  => __( 'Social', 'minn-admin' ),
					'fields' => array(
						array( 'name' => 'facebook_title', 'label' => __( 'Facebook title', 'minn-admin' ), 'type' => 'text' ),
						array( 'name' => 'facebook_description', 'label' => __( 'Facebook description', 'minn-admin' ), 'type' => 'textarea' ),
						array( 'name' => 'social_image', 'label' => __( 'Social thumbnail', 'minn-admin' ), 'type' => 'image' ),
						array( 'name' => 'twitter_title', 'label' => __( 'X (Twitter) title', 'minn-admin' ), 'type' => 'text', 'help' => __( 'Leave empty to reuse the Facebook card.', 'minn-admin' ) ),
						array( 'name' => 'twitter_description', 'label' => __( 'X (Twitter) description', 'minn-admin' ), 'type' => 'textarea' ),
						array( 'name' => 'twitter_image', 'label' => __( 'X (Twitter) image', 'minn-admin' ), 'type' => 'image' ),
					),
				),
				array(
					'group'  => __( 'Advanced', 'minn-admin' ),
					'fields' => $advanced,
				),
			);
		},
		'read'     => function ( $post_id ) use ( $base_read, $robots, $image_read ) {
			$post_id = (int) $post_id;
			$out     = call_user_func( $base_read, $post_id );
			foreach ( $robots as $field => $meta_key ) {
				$out[ $field ] = 'yes' === (string) get_post_meta( $post_id, $meta_key, true );
			}
			$out['social_image']  = $image_read( $post_id, 'fb' );
			$out['twitter_image'] = $image_read( $post_id, 'twitter' );
			return $out;
		},
		'write'    => function ( $post_id, $field, $clean ) use ( $base_write, $robots, $image_write ) {
			$post_id = (int) $post_id;
			if ( 'social_image' === $field || 'twitter_image' === $field ) {
				$image_write( $post_id, 'social_image' === $field ? 'fb' : 'twitter', $clean );
				return;
			}
			if ( isset( $robots[ $field ] ) ) {
				// Their save stores 'yes' or deletes; absent = site default.
				if ( $clean ) {
					update_post_meta( $post_id, $robots[ $field ], 'yes' );
				} else {
					delete_post_meta( $post_id, $robots[ $field ] );
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
		return minn_admin_seo_yoast_provider();
	}
	if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
		return minn_admin_seo_rank_math_provider();
	}
	if ( defined( 'AIOSEO_VERSION' ) && class_exists( '\AIOSEO\Plugin\Common\Models\Post' ) ) {
		return minn_admin_seo_aioseo_provider();
	}
	if ( defined( 'SEOPRESS_VERSION' ) ) {
		return minn_admin_seo_press_family_provider( 'SEOPress', '_seopress_', false, function () {
			// SEOPress blocks the metabox for roles listed in its advanced
			// settings; mirror that (super admins are never blocked).
			// SEOPress restricts its metaboxes in two independent areas, and
			// the target keyword is the one field it files under the second
			// one -- their own meta_auth callback switches on exactly that
			// key. Ask about both, so a role blocked from content analysis
			// cannot write the keyword through here.
			return ! function_exists( 'seopress_metabox_role_is_blocked' )
				|| ( ! seopress_metabox_role_is_blocked( 'GLOBAL' )
					&& ! seopress_metabox_role_is_blocked( 'CONTENT_ANALYSIS' ) );
		} );
	}
	if ( defined( 'SURERANK_VERSION' )
		&& class_exists( '\SureRank\Inc\Functions\Get' )
		&& class_exists( '\SureRank\Inc\API\Post' ) ) {
		return minn_admin_seo_surerank_provider();
	}
	// SiteSEO is the SEOPress fork; same postmeta shape under its own
	// prefix, and it kept the per-post noarchive flag SEOPress dropped.
	if ( defined( 'SITESEO_VERSION' ) ) {
		return minn_admin_seo_press_family_provider( 'SiteSEO', '_siteseo_', true, function () {
			// SiteSEO's own metabox-permission check (roles in its advanced
			// settings); it also covers the logged-in test.
			return ! function_exists( 'siteseo_user_can_metabox' )
				|| siteseo_user_can_metabox();
		} );
	}
	// Squirrly last: own {prefix}qss table, reached only through their API.
	if ( defined( 'SQ_VERSION' ) && class_exists( 'SQ_Classes_ObjController' ) ) {
		return minn_admin_seo_squirrly_provider();
	}
	return null;
}

/**
 * The panel groups for a provider: its own `fields` callable when it
 * declares one (Rank Math), else the legacy three-field shape (plus the
 * social thumbnail for providers flagged `social`).
 *
 * @param array $plugin Active provider.
 * @return array Groups in the editor-panels fields shape.
 */
function minn_admin_seo_groups( $plugin, $post_id = 0 ) {
	if ( isset( $plugin['fields'] ) && is_callable( $plugin['fields'] ) ) {
		$groups = call_user_func( $plugin['fields'], (int) $post_id );
		return is_array( $groups ) ? $groups : array();
	}
	$groups = array(
		array(
			'group'  => __( 'Search appearance', 'minn-admin' ),
			'fields' => array(
				array( 'name' => 'title', 'label' => __( 'SEO title', 'minn-admin' ), 'type' => 'text', 'counter' => 60 ),
				array( 'name' => 'description', 'label' => __( 'Meta description', 'minn-admin' ), 'type' => 'textarea', 'counter' => 160 ),
				array( 'name' => 'focus_keyword', 'label' => __( 'Focus keyword', 'minn-admin' ), 'type' => 'text' ),
			),
			'locked' => 0,
		),
	);
	if ( ! empty( $plugin['social'] ) ) {
		$groups[] = array(
			'group'  => __( 'Social', 'minn-admin' ),
			'fields' => array(
				array(
					'name'  => 'social_image',
					'label' => __( 'Social thumbnail', 'minn-admin' ),
					'type'  => 'image',
				),
			),
			'locked' => 0,
		);
	}
	return $groups;
}

/**
 * Flat name => field-def map for the write path. The declared fields ARE
 * the write whitelist: a key the provider never declared is ignored, and
 * each value is sanitized by its declared type before the provider's
 * write callable ever sees it.
 *
 * @param array $plugin Active provider.
 * @return array
 */
function minn_admin_seo_field_map( $plugin, $post_id = 0 ) {
	$map = array();
	foreach ( minn_admin_seo_groups( $plugin, $post_id ) as $group ) {
		foreach ( ( isset( $group['fields'] ) && is_array( $group['fields'] ) ? $group['fields'] : array() ) as $field ) {
			if ( ! empty( $field['name'] ) ) {
				$map[ (string) $field['name'] ] = $field;
			}
		}
	}
	return $map;
}

/**
 * The SERP preview for a post: the provider's own `preview` callable when
 * it declares one (vendor-exact variable resolution), else a naive
 * approximation from the stored values — good enough to show what a search
 * result will roughly look like, honest enough never to invent vendor
 * template semantics it cannot resolve.
 *
 * @param array $plugin  Active provider.
 * @param int   $post_id Post id.
 * @return array|null { url, title, description, fields, vars } or null.
 */
function minn_admin_seo_preview( $plugin, $post_id ) {
	$post_id = (int) $post_id;
	if ( $post_id <= 0 ) {
		return null;
	}
	if ( isset( $plugin['preview'] ) && is_callable( $plugin['preview'] ) ) {
		return call_user_func( $plugin['preview'], $post_id );
	}
	$post = get_post( $post_id );
	if ( ! $post ) {
		return null;
	}
	$vars = array(
		'title'    => get_the_title( $post ),
		'sitename' => get_bloginfo( 'name' ),
		'sitedesc' => get_bloginfo( 'description' ),
		'sep'      => '-',
		'excerpt'  => wp_trim_words( (string) get_the_excerpt( $post ), 30 ),
		'page'     => '',
	);
	// Stored values may themselves carry %tokens% (or Yoast's %%tokens%%);
	// resolve the small shared set, drop the rest.
	$resolve = function ( $s ) use ( $vars ) {
		$s = preg_replace_callback( '/%{1,2}([a-z0-9_]+)%{1,2}/i', function ( $m ) use ( $vars ) {
			$key = strtolower( $m[1] );
			return isset( $vars[ $key ] ) ? $vars[ $key ] : '';
		}, (string) $s );
		return trim( (string) preg_replace( '/\s+/', ' ', (string) $s ) );
	};
	try {
		$read = call_user_func( $plugin['read'], $post_id );
	} catch ( \Throwable $e ) {
		$read = array();
	}
	$title = isset( $read['title'] ) && '' !== $read['title'] ? $read['title'] : '%title% %sep% %sitename%';
	$desc  = isset( $read['description'] ) && '' !== $read['description'] ? $read['description'] : '%excerpt%';
	return array(
		'url'         => (string) get_permalink( $post ),
		'title'       => $resolve( $title ),
		'description' => $resolve( $desc ),
		'fields'      => array( 'title' => 'title', 'description' => 'description' ),
		'vars'        => $vars,
	);
}

/**
 * Whether the active SEO plugin still offers its own metabox on this post type.
 *
 * Every one of these plugins lets a site switch its SEO controls off for a
 * given content type, and when it does, the fields disappear from wp-admin for
 * everybody, administrators included. That is a site-wide decision about what
 * a content type is for, not a permission, so mirroring it is the difference
 * between Minn showing the same site wp-admin shows and Minn quietly reopening
 * a door the owner closed.
 *
 * Defaults to true wherever the answer cannot be read, so an unconfigured site
 * and an unfamiliar vendor both behave exactly as before.
 *
 * @param array  $plugin    Active provider descriptor.
 * @param string $post_type Post type slug.
 * @return bool
 */
function minn_admin_seo_shows_for_type( $plugin, $post_type ) {
	$post_type = (string) $post_type;
	if ( '' === $post_type ) {
		return true;
	}
	$name = isset( $plugin['name'] ) ? (string) $plugin['name'] : '';
	try {
		if ( 'Yoast SEO' === $name && class_exists( 'WPSEO_Options' ) ) {
			$v = WPSEO_Options::get( 'display-metabox-pt-' . $post_type, null );
			return ( null === $v ) ? true : (bool) $v;
		}
		if ( 'Rank Math' === $name && class_exists( '\RankMath\Helper' ) ) {
			$v = \RankMath\Helper::get_settings( 'titles.pt_' . $post_type . '_add_meta_box', null );
			return ( null === $v ) ? true : (bool) $v;
		}
		if ( 'AIOSEO' === $name && function_exists( 'aioseo' ) ) {
			$types = aioseo()->dynamicOptions->searchAppearance->postTypes;
			if ( $types && isset( $types->{$post_type} ) ) {
				$adv = $types->{$post_type}->advanced;
				if ( $adv && isset( $adv->showMetaBox ) ) {
					return (bool) $adv->showMetaBox;
				}
			}
			return true;
		}
		if ( 'SEOPress' === $name || 'SEOPress PRO' === $name ) {
			$titles = get_option( 'seopress_titles_option_name' );
			$key    = 'seopress_titles_single_titles';
			if ( is_array( $titles ) && isset( $titles[ $key ][ $post_type ]['enable'] ) ) {
				return (bool) $titles[ $key ][ $post_type ]['enable'];
			}
			return true;
		}
		// SiteSEO is the SEOPress fork, and Minn already builds it through the
		// same provider factory, so it carries the same switch under its own
		// option name. It was the one provider here whose sibling shape was
		// known and still unasked.
		if ( 'SiteSEO' === $name ) {
			$titles = get_option( 'siteseo_titles_option_name' );
			$key    = 'siteseo_titles_single_titles';
			if ( is_array( $titles ) && isset( $titles[ $key ][ $post_type ]['enable'] ) ) {
				return (bool) $titles[ $key ][ $post_type ]['enable'];
			}
			return true;
		}
		// SureRank and Squirrly ship no per-post-type metabox switch, so there
		// is nothing to mirror: their controls appear on every type that
		// supports the editor. Recorded here so the next sweep does not read
		// their absence as an oversight.
	} catch ( \Throwable $e ) {
		return true; // their internals moved; behave as before
	}
	return true;
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
		'fieldsRoute' => 'minn-admin/v1/seo/fields?post={id}',
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
		'args'                => array(
			'post' => array( 'type' => 'integer', 'default' => 0 ),
		),
		'callback'            => function ( $request ) use ( $plugin ) {
			$post_id = (int) $request->get_param( 'post' );
			// A post type the vendor withdrew its own metabox from has no SEO
			// fields on this site, so offer none here either.
			$ptype = $post_id > 0 ? (string) get_post_type( $post_id ) : '';
			if ( '' !== $ptype && ! minn_admin_seo_shows_for_type( $plugin, $ptype ) ) {
				return rest_ensure_response( array( 'groups' => array() ) );
			}
			// Groups can be post-aware (Yoast hides the article type on
			// pages) and are already per-user (capability-gated groups).
			$out = array( 'groups' => minn_admin_seo_groups( $plugin, $post_id ) );
			// The preview quotes the post's resolved title and excerpt, so
			// it is gated per POST, not just on the route's edit_posts.
			if ( $post_id > 0 && current_user_can( 'edit_post', $post_id ) ) {
				$preview = minn_admin_seo_preview( $plugin, $post_id );
				if ( $preview ) {
					$out['preview'] = $preview;
				}
			}
			return rest_ensure_response( $out );
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
			// Object-level gate, like the pods/meta-box adapters. Core's
			// collection check for context=edit is the blanket post-type
			// edit_posts cap, so without this a Contributor reading
			// wp/v2/posts?context=edit gets everyone's focus keywords.
			// A _fields request that names minn_seo but not id hands this
			// callback an id-less array (core prepares only requested
			// fields) — the isset is load-bearing, not defensive noise.
			$id = isset( $post_arr['id'] ) ? (int) $post_arr['id'] : 0;
			if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
				return new stdClass();
			}
			// The same vendor predicate the write path asks. A role the SEO
			// plugin hides its metabox from should not read the values back
			// either, and reading was the half that never asked.
			if ( isset( $plugin['can_edit'] ) && is_callable( $plugin['can_edit'] ) ) {
				$allowed = false;
				try {
					$allowed = (bool) call_user_func( $plugin['can_edit'], $id );
				} catch ( \Throwable $e ) {
					$allowed = false;
				}
				if ( ! $allowed ) {
					return new stdClass();
				}
			}
			// And the other half of the same question the write path asks:
			// turning the SEO controls off for a content type hides them from
			// wp-admin for everybody, so the values should not read back on
			// that type either.
			if ( ! minn_admin_seo_shows_for_type( $plugin, get_post_type( $id ) ) ) {
				return new stdClass();
			}
			return call_user_func( $plugin['read'], $id );
		},
		'update_callback' => function ( $value, $post ) use ( $plugin ) {
			if ( ! is_array( $value ) ) {
				return null;
			}
			if ( ! current_user_can( 'edit_post', $post->ID ) ) {
				return new WP_Error( 'rest_forbidden', __( 'You cannot edit SEO fields on this post.', 'minn-admin' ), array( 'status' => 403 ) );
			}
			// The active SEO plugin may withhold its metabox from this user's
			// role even when they can edit the post; honor that here so Minn
			// never writes SEO meta the vendor's own screen would refuse.
			if ( isset( $plugin['can_edit'] ) && is_callable( $plugin['can_edit'] )
				&& ! call_user_func( $plugin['can_edit'], $post->ID ) ) {
				return new WP_Error( 'rest_forbidden', __( 'You cannot edit SEO fields on this site.', 'minn-admin' ), array( 'status' => 403 ) );
			}
			// And the same answer the read side gives: a content type the
			// vendor withdrew its metabox from is not one Minn writes SEO for.
			if ( ! minn_admin_seo_shows_for_type( $plugin, (string) $post->post_type ) ) {
				return new WP_Error( 'rest_forbidden', __( 'SEO fields are switched off for this content type.', 'minn-admin' ), array( 'status' => 403 ) );
			}
			foreach ( minn_admin_seo_field_map( $plugin, $post->ID ) as $field => $def ) {
				if ( ! array_key_exists( $field, $value ) ) {
					continue;
				}
				$type = isset( $def['type'] ) ? $def['type'] : 'text';
				$raw  = $value[ $field ];

				// Notes are read-only rows; nothing to write.
				if ( 'note' === $type ) {
					continue;
				}

				if ( 'image' === $type ) {
					$att = is_array( $raw ) ? (int) ( isset( $raw['id'] ) ? $raw['id'] : 0 ) : ( is_numeric( $raw ) ? (int) $raw : 0 );
					if ( null === $raw || '' === $raw || false === $raw ) {
						call_user_func( $plugin['write'], $post->ID, $field, null );
					} elseif ( $att > 0 ) {
						// edit_post above authorises the POST. It says nothing about
						// the ATTACHMENT, which arrives as a bare id in the body.
						// Without a check here, posting ids one at a time and
						// reading the stored value back is an enumeration oracle
						// over the whole media table — and uploads are served with
						// no authorisation, so learning the URL is reading the file.
						// The vendors whose field this is put a media modal in
						// front of it, which means upload_files.
						if ( ! current_user_can( 'upload_files' )
							|| 'attachment' !== get_post_type( $att )
							|| ! current_user_can( 'read_post', $att ) ) {
							return new WP_Error( 'rest_forbidden', __( 'You cannot use that media item.', 'minn-admin' ), array( 'status' => 403 ) );
						}
						// The URL is DERIVED from the id, never taken from the
						// caller: every provider resolves it the same way, and
						// accepting one let anyone who can edit a draft pin an
						// arbitrary third-party image as the og:image of a post
						// somebody else publishes later.
						call_user_func( $plugin['write'], $post->ID, $field, $att );
					}
					continue;
				}

				if ( 'toggle' === $type ) {
					call_user_func( $plugin['write'], $post->ID, $field, (bool) $raw );
					continue;
				}

				if ( 'number' === $type ) {
					if ( null === $raw || '' === $raw ) {
						call_user_func( $plugin['write'], $post->ID, $field, '' );
					} elseif ( is_numeric( $raw ) ) {
						call_user_func( $plugin['write'], $post->ID, $field, (int) $raw );
					}
					continue;
				}

				if ( 'select' === $type ) {
					$raw = ( null === $raw || false === $raw ) ? '' : (string) $raw;
					// The declared options are the whitelist; '' always
					// means clear/inherit.
					$allowed = array();
					foreach ( ( isset( $def['options'] ) && is_array( $def['options'] ) ? $def['options'] : array() ) as $opt ) {
						$allowed[] = (string) ( is_array( $opt ) ? $opt[0] : $opt );
					}
					if ( '' === $raw || in_array( $raw, $allowed, true ) ) {
						call_user_func( $plugin['write'], $post->ID, $field, $raw );
					}
					continue;
				}

				$clean = 'textarea' === $type
					? sanitize_textarea_field( (string) $raw )
					: sanitize_text_field( (string) $raw );
				if ( isset( $def['sanitize'] ) && 'url' === $def['sanitize'] ) {
					$clean = esc_url_raw( trim( (string) $raw ) );
				}
				call_user_func( $plugin['write'], $post->ID, $field, $clean );
			}
			return null;
		},
		// The declared object stays loose on purpose: providers add their
		// own keys (robots, canonical, Twitter split) and the write path
		// sanitizes each by its declared type — the field map, not this
		// schema, is the whitelist.
		'schema'          => array(
			'type'        => 'object',
			'description' => __( 'Per-post SEO fields for the active SEO plugin (Minn Admin editor panel).', 'minn-admin' ),
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
