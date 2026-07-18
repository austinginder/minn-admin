<?php
/**
 * Bundled adapter: PowerPress by Blubrry (20k).
 *
 * PowerPress episodes ride normal POSTS: everything lives in one `enclosure`
 * postmeta blob — "url\nsize\ntype\n" followed by a PHP-serialized extras
 * array (duration, iTunes fields, hosting, chapters…). There is no callable
 * write model (their save is one long $_POST['Powerpress'] handler), so this
 * adapter mirrors the blob format directly — a documented exception to
 * never-reimplement, with three guardrails:
 *   1. The extras array is ONLY unserialized their way
 *      (allowed_classes => false) and rebuilt with every unmanaged key
 *      byte-preserved (hosting, chapters, artwork survive untouched).
 *   2. Writes are diff-based: a key is rewritten only when the panel value
 *      actually differs from the stored one, so an untouched save
 *      round-trips the blob unchanged.
 *   3. Manual duration/size edits set their set_duration/set_size override
 *      flags exactly like the "modify" checkboxes on their metabox.
 *
 * Their save_post handler only runs when $_POST['Powerpress'] is present,
 * so Minn's REST saves are never clobbered. Scope: the DEFAULT channel
 * ('podcast', the plain `enclosure` meta) on the `post` type — custom
 * channels and post-type podcasting stay on PowerPress's metabox, as do
 * artwork, explicit/block, chapters and transcripts (the locked count).
 * Clearing the media URL removes the episode, their remove flow.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_powerpress_active() {
	return defined( 'POWERPRESS_VERSION' ) && function_exists( 'powerpress_get_contenttype' );
}

/**
 * Parse the enclosure blob into [ url, size, type, extras[] ].
 *
 * @param string $raw Stored meta value.
 * @return array{url: string, size: string, type: string, extras: array}
 */
function minn_admin_powerpress_parse( $raw ) {
	$out = array( 'url' => '', 'size' => '', 'type' => '', 'extras' => array() );
	if ( ! is_string( $raw ) || '' === $raw ) {
		return $out;
	}
	$parts       = explode( "\n", $raw, 4 );
	$out['url']  = isset( $parts[0] ) ? trim( $parts[0] ) : '';
	$out['size'] = isset( $parts[1] ) ? trim( $parts[1] ) : '';
	$out['type'] = isset( $parts[2] ) ? trim( $parts[2] ) : '';
	if ( isset( $parts[3] ) && '' !== $parts[3] ) {
		// Their exact unserialize call — object injection off.
		$extras = @unserialize( $parts[3], array( 'allowed_classes' => false ) );
		if ( is_array( $extras ) ) {
			$out['extras'] = $extras;
		}
	}
	return $out;
}

/** Panel values for a post: flat strings, '' when no episode yet. */
function minn_admin_powerpress_read_values( $post_id ) {
	$enc = minn_admin_powerpress_parse( get_post_meta( $post_id, 'enclosure', true ) );
	$x   = $enc['extras'];
	$str = function ( $key ) use ( $x ) {
		return isset( $x[ $key ] ) && is_scalar( $x[ $key ] ) ? (string) $x[ $key ] : '';
	};
	return array(
		'url'           => $enc['url'],
		'size'          => $enc['size'],
		'duration'      => $str( 'duration' ),
		'subtitle'      => $str( 'subtitle' ),
		'episode_title' => $str( 'episode_title' ),
		'episode_no'    => $str( 'episode_no' ),
		'season'        => $str( 'season' ),
		'episode_type'  => $str( 'episode_type' ),
	);
}

/**
 * Diff-based write: rebuild the blob only when something actually changed.
 *
 * @param int   $post_id Post ID.
 * @param array $values  Panel values.
 */
function minn_admin_powerpress_write_values( $post_id, $values ) {
	if ( ! is_array( $values ) ) {
		return;
	}
	$raw     = get_post_meta( $post_id, 'enclosure', true );
	$enc     = minn_admin_powerpress_parse( $raw );
	$current = minn_admin_powerpress_read_values( $post_id );
	$changed = false;

	// Clearing the URL removes the episode entirely (their remove flow,
	// including the legacy itunes:duration meta).
	if ( array_key_exists( 'url', $values ) ) {
		$url = esc_url_raw( trim( (string) $values['url'] ) );
		if ( '' === $url && '' !== $enc['url'] ) {
			delete_post_meta( $post_id, 'enclosure' );
			delete_post_meta( $post_id, 'itunes:duration' );
			return;
		}
		if ( '' !== $url && $url !== $enc['url'] ) {
			$enc['url'] = $url;
			// A new file usually means a new content type.
			$type = powerpress_get_contenttype( $url );
			if ( $type ) {
				$enc['type'] = $type;
			}
			$changed = true;
		}
	}
	// No episode yet and no URL supplied: nothing to attach the rest to.
	if ( '' === $enc['url'] ) {
		return;
	}

	if ( array_key_exists( 'size', $values ) ) {
		$size = preg_replace( '/[^0-9]/', '', (string) $values['size'] );
		if ( '' !== $size && $size !== $enc['size'] ) {
			$enc['size'] = $size;
			// Their metabox "modify" checkbox semantics.
			$enc['extras']['set_size'] = 1;
			$changed = true;
		}
	}
	if ( array_key_exists( 'duration', $values ) ) {
		$duration = trim( (string) $values['duration'] );
		if ( $duration !== $current['duration'] ) {
			if ( '' === $duration ) {
				unset( $enc['extras']['duration'], $enc['extras']['set_duration'] );
				$changed = true;
			} elseif ( preg_match( '/^\d{1,2}(:[0-5]?\d){1,2}$/', $duration ) ) {
				$enc['extras']['duration']     = $duration;
				$enc['extras']['set_duration'] = 1;
				$changed = true;
			}
			// Unreadable durations keep the stored value.
		}
	}
	foreach ( array( 'subtitle', 'episode_title' ) as $key ) {
		if ( ! array_key_exists( $key, $values ) ) {
			continue;
		}
		$val = sanitize_text_field( (string) $values[ $key ] );
		if ( $val !== $current[ $key ] ) {
			if ( '' === $val ) {
				unset( $enc['extras'][ $key ] );
			} else {
				$enc['extras'][ $key ] = $val;
			}
			$changed = true;
		}
	}
	foreach ( array( 'episode_no', 'season' ) as $key ) {
		if ( ! array_key_exists( $key, $values ) ) {
			continue;
		}
		$val = preg_replace( '/[^0-9]/', '', (string) $values[ $key ] );
		if ( $val !== $current[ $key ] ) {
			if ( '' === $val ) {
				unset( $enc['extras'][ $key ] );
			} else {
				$enc['extras'][ $key ] = $val;
			}
			$changed = true;
		}
	}
	if ( array_key_exists( 'episode_type', $values ) ) {
		$val = (string) $values['episode_type'];
		if ( in_array( $val, array( '', 'full', 'trailer', 'bonus' ), true ) && $val !== $current['episode_type'] ) {
			if ( '' === $val ) {
				unset( $enc['extras']['episode_type'] );
			} else {
				$enc['extras']['episode_type'] = $val;
			}
			$changed = true;
		}
	}

	if ( ! $changed ) {
		return;
	}
	update_post_meta(
		$post_id,
		'enclosure',
		"{$enc['url']}\n{$enc['size']}\n{$enc['type']}\n" . serialize( $enc['extras'] ) // phpcs:ignore -- their exact storage format
	);
}

add_filter( 'minn_admin_editor_panels', function ( $panels ) {
	if ( ! minn_admin_powerpress_active() ) {
		return $panels;
	}
	$panels['powerpress'] = array(
		'label'       => 'Podcast episode',
		'sub'         => 'PowerPress',
		'cap'         => 'edit_posts',
		'fieldsRoute' => 'minn-admin/v1/powerpress/fields?post_id={id}&post_type={type}',
		'valuesKey'   => 'minn_powerpress',
		'writeKey'    => 'minn_powerpress',
	);
	return $panels;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_powerpress_active() ) {
		return;
	}

	register_rest_route( 'minn-admin/v1', '/powerpress/fields', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'args'                => array(
			'post_id'   => array( 'type' => 'integer', 'default' => 0 ),
			'post_type' => array( 'type' => 'string', 'default' => 'posts' ),
		),
		'callback'            => function ( WP_REST_Request $request ) {
			$post_id   = (int) $request['post_id'];
			$rest_base = sanitize_key( $request['post_type'] );
			$post_type = $post_id && get_post( $post_id ) ? get_post( $post_id )->post_type : ( 'posts' === $rest_base ? 'post' : $rest_base );
			// Default-channel podcasting lives on plain posts.
			if ( 'post' !== $post_type ) {
				return rest_ensure_response( array( 'groups' => array() ) );
			}
			if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error( 'rest_forbidden', 'You cannot edit this post.', array( 'status' => 403 ) );
			}
			return rest_ensure_response( array(
				'groups' => array(
					array(
						'group'  => 'Episode (default channel)',
						'fields' => array(
							array( 'name' => 'url', 'label' => 'Media file URL', 'type' => 'url', 'placeholder' => 'https://example.com/episode.mp3 — clearing this removes the episode' ),
							array( 'name' => 'size', 'label' => 'File size (bytes)', 'type' => 'text', 'placeholder' => 'Detected by PowerPress when blank' ),
							array( 'name' => 'duration', 'label' => 'Duration', 'type' => 'text', 'placeholder' => 'HH:MM:SS' ),
							array( 'name' => 'subtitle', 'label' => 'Subtitle', 'type' => 'text' ),
							array( 'name' => 'episode_title', 'label' => 'Episode title (Apple)', 'type' => 'text' ),
							array( 'name' => 'episode_no', 'label' => 'Episode number', 'type' => 'number' ),
							array( 'name' => 'season', 'label' => 'Season', 'type' => 'number' ),
							array(
								'name'    => 'episode_type',
								'label'   => 'Episode type',
								'type'    => 'select',
								'choices' => array( '' => 'Full (default)', 'full' => 'Full Episode', 'trailer' => 'Trailer', 'bonus' => 'Bonus' ),
							),
						),
						// Artwork, explicit/block, chapters, transcripts stay on
						// PowerPress's metabox.
						'locked' => 4,
					),
				),
			) );
		},
	) );

	register_rest_field(
		'post',
		'minn_powerpress',
		array(
			'get_callback'    => function ( $obj ) {
				$id = isset( $obj['id'] ) ? (int) $obj['id'] : 0;
				if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
					return new stdClass();
				}
				return (object) minn_admin_powerpress_read_values( $id );
			},
			'update_callback' => function ( $value, $post ) {
				if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
					return;
				}
				if ( is_object( $value ) ) {
					$value = (array) $value;
				}
				minn_admin_powerpress_write_values( $post->ID, $value );
			},
			'schema'          => array(
				'description' => 'PowerPress default-channel episode fields for Minn Admin.',
				'type'        => 'object',
				'context'     => array( 'edit' ),
			),
		)
	);
} );
