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
 * PowerPress's own episode gate. With "Roles and Capabilities" on
 * (powerpress_general['use_caps']) its episode box only loads for
 * edit_podcast (powerpress_admin_menu); otherwise every post editor gets it.
 */
function minn_admin_powerpress_can_podcast() {
	$general = get_option( 'powerpress_general', array() );
	return empty( $general['use_caps'] ) || current_user_can( 'edit_podcast' );
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
		// Their exact read, through the shared data-only decoder (a
		// Contributor writes this blob through the enclosure field).
		$extras = Minn_Admin::decode_serialized( $parts[3] );
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

/** The panel's field labels, by key (the fields route draws the same). */
function minn_admin_powerpress_labels() {
	return array(
		'url'           => __( 'Media file URL', 'minn-admin' ),
		'size'          => __( 'File size (bytes)', 'minn-admin' ),
		'duration'      => __( 'Duration', 'minn-admin' ),
		'subtitle'      => __( 'Subtitle', 'minn-admin' ),
		'episode_title' => __( 'Episode title (Apple)', 'minn-admin' ),
		'episode_no'    => __( 'Episode number', 'minn-admin' ),
		'season'        => __( 'Season', 'minn-admin' ),
		'episode_type'  => __( 'Episode type', 'minn-admin' ),
	);
}

/**
 * A refusal naming panel fields, in the shape the post's answer carries
 * (minn_admin_panel_report_held(), adapters/acpt.php).
 *
 * @param string   $code    Error code.
 * @param string   $message What to tell the person.
 * @param int      $status  HTTP status the refusal stands for.
 * @param string[] $keys    Panel field keys.
 * @return WP_Error
 */
function minn_admin_powerpress_refusal( $code, $message, $status, $keys ) {
	$labels = minn_admin_powerpress_labels();
	$fields = array();
	foreach ( $keys as $key ) {
		$fields[] = array(
			'key'     => (string) $key,
			'label'   => $labels[ $key ] ?? (string) $key,
			'message' => $message,
		);
	}
	return new WP_Error( $code, $message, array( 'status' => $status, 'fields' => $fields ) );
}

/**
 * Diff-based write: rebuild the blob only when something actually changed.
 *
 * @param int   $post_id Post ID.
 * @param array $values  Panel values.
 * @return WP_Error[] What was refused, each naming its fields (kept as
 *                    stored); empty when nothing was.
 */
function minn_admin_powerpress_write_values( $post_id, $values ) {
	if ( ! is_array( $values ) ) {
		return array();
	}
	$raw     = get_post_meta( $post_id, 'enclosure', true );
	$enc     = minn_admin_powerpress_parse( $raw );
	$current = minn_admin_powerpress_read_values( $post_id );
	$changed = false;
	$refused = array();

	// Clearing the URL removes the episode entirely (their remove flow,
	// including the legacy itunes:duration meta).
	// A URL sent back as stored is left alone before any cleaning: esc_url_raw()
	// would rewrite a stored one ('my ep.mp3' to 'my%20ep.mp3') and re-derive
	// its content type.
	if ( array_key_exists( 'url', $values ) && ! Minn_Admin::unchanged( $values['url'], $enc['url'] ) ) {
		// A list or object is no address, and coerced it read as an emptied
		// box: refused like an address the cleaner empties, never a clear.
		$shape = null === $values['url'] || is_scalar( $values['url'] );
		$typed = $shape ? trim( (string) $values['url'] ) : '';
		$url   = '' === $typed ? '' : esc_url_raw( $typed );
		if ( ! $shape ) {
			$refused[] = minn_admin_powerpress_refusal( 'minn_powerpress_bad_url', __( 'That URL is not valid.', 'minn-admin' ), 400, array( 'url' ) );
		} elseif ( '' === $typed && '' !== $enc['url'] ) {
			delete_post_meta( $post_id, 'enclosure' );
			delete_post_meta( $post_id, 'itunes:duration' );
			return $refused;
		}
		// Only an empty box clears. An address esc_url_raw() empties (a
		// javascript: URL, an unknown scheme) or the house rule refuses is
		// refused: reading its '' as a clear deleted the stored episode.
		if ( '' !== $typed && ( '' === $url || null === minn_admin_url_clean( $typed ) ) ) {
			$refused[] = minn_admin_powerpress_refusal( 'minn_powerpress_bad_url', __( 'That URL is not valid.', 'minn-admin' ), 400, array( 'url' ) );
			$url       = '';
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
	// No episode yet and no URL supplied: nothing to attach the rest to, so
	// whatever else was typed is named rather than dropped.
	if ( '' === $enc['url'] ) {
		$orphans = array();
		foreach ( array_keys( minn_admin_powerpress_labels() ) as $key ) {
			if ( 'url' !== $key && array_key_exists( $key, $values ) && is_scalar( $values[ $key ] ) && '' !== trim( (string) $values[ $key ] ) && ! Minn_Admin::unchanged( $values[ $key ], $current[ $key ] ) ) {
				$orphans[] = $key;
			}
		}
		if ( $orphans ) {
			$refused[] = minn_admin_powerpress_refusal( 'minn_powerpress_no_episode', __( 'Add the media file URL first: PowerPress keeps these details with the episode file.', 'minn-admin' ), 400, $orphans );
		}
		return $refused;
	}

	if ( array_key_exists( 'size', $values ) ) {
		$size = preg_replace( '/[^0-9]/', '', is_scalar( $values['size'] ) ? (string) $values['size'] : '' );
		if ( '' === $size && is_scalar( $values['size'] ) && '' !== trim( (string) $values['size'] ) ) {
			$refused[] = minn_admin_powerpress_refusal( 'minn_powerpress_bad_size', __( 'Enter the file size as a number of bytes.', 'minn-admin' ), 400, array( 'size' ) );
		}
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
			} else {
				// Unreadable durations keep the stored value, and say so.
				$refused[] = minn_admin_powerpress_refusal( 'minn_powerpress_bad_duration', __( 'Enter the duration as HH:MM:SS or MM:SS.', 'minn-admin' ), 400, array( 'duration' ) );
			}
		}
	}
	foreach ( array( 'subtitle', 'episode_title' ) as $key ) {
		// The panel resends every field: compare what came in with what the
		// read served BEFORE cleaning it, or a stored value the cleaner would
		// alter ("Episode <3") never compares equal and is rewritten.
		if ( ! array_key_exists( $key, $values ) || Minn_Admin::unchanged( $values[ $key ], $current[ $key ] ) ) {
			continue;
		}
		$typed = is_scalar( $values[ $key ] ) ? (string) $values[ $key ] : '';
		$val   = sanitize_text_field( $typed );
		// Text the cleaner takes away whole (a lone tag, say) is not an
		// emptied box: refused, so the stored text stays.
		if ( '' === $val && '' !== trim( $typed ) ) {
			$refused[] = minn_admin_powerpress_refusal( 'minn_powerpress_bad_text', __( 'That text could not be saved as typed.', 'minn-admin' ), 400, array( $key ) );
			continue;
		}
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
		if ( ! array_key_exists( $key, $values ) || Minn_Admin::unchanged( $values[ $key ], $current[ $key ], 'number' ) ) {
			continue;
		}
		// A whole number, or empty to clear. Stripping the rest turned 1.5
		// into 15 and 'abc' into a clear of the stored number.
		// An emptied number box arrives as null: empty, so it clears.
		$val = null === $values[ $key ] ? '' : ( is_scalar( $values[ $key ] ) ? trim( (string) $values[ $key ] ) : null );
		if ( null === $val || ( '' !== $val && ! ctype_digit( $val ) ) ) {
			$refused[] = minn_admin_powerpress_refusal( 'minn_powerpress_bad_number', __( 'Enter a whole number.', 'minn-admin' ), 400, array( $key ) );
			continue;
		}
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
		$val = is_scalar( $values['episode_type'] ) ? (string) $values['episode_type'] : '';
		if ( ! in_array( $val, array( '', 'full', 'trailer', 'bonus' ), true ) ) {
			$refused[] = minn_admin_powerpress_refusal( 'minn_powerpress_bad_type', __( 'That is not one of this field\'s choices.', 'minn-admin' ), 400, array( 'episode_type' ) );
		} elseif ( $val !== $current['episode_type'] ) {
			if ( '' === $val ) {
				unset( $enc['extras']['episode_type'] );
			} else {
				$enc['extras']['episode_type'] = $val;
			}
			$changed = true;
		}
	}

	if ( ! $changed ) {
		return $refused;
	}
	// Slashed: update_post_meta() unslashes, and a backslash taken out of an
	// extras string nobody edited ('AC\DC live') left its serialized length
	// wrong, so the whole extras array (duration, chapters, hosting) stopped
	// reading back.
	update_post_meta(
		$post_id,
		'enclosure',
		wp_slash( "{$enc['url']}\n{$enc['size']}\n{$enc['type']}\n" . serialize( $enc['extras'] ) ) // phpcs:ignore -- their exact storage format
	);
	return $refused;
}

add_filter( 'minn_admin_editor_panels', function ( $panels ) {
	if ( ! minn_admin_powerpress_active() || ! minn_admin_powerpress_can_podcast() ) {
		return $panels;
	}
	$panels['powerpress'] = array(
		'label'       => __( 'Podcast episode', 'minn-admin' ),
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

	minn_admin_endpoint( '/powerpress/fields', array(
		'methods'             => 'GET',
		'gate'                => function () {
			return current_user_can( 'edit_posts' ) && minn_admin_powerpress_can_podcast();
		},
		'args'                => array(
			'post_id'   => array( 'type' => 'integer' ),
			'post_type' => array( 'type' => 'string' ),
		),
		'run'                 => function ( WP_REST_Request $request ) {
			$post_id   = (int) $request['post_id'];
			$rest_base = sanitize_key( (string) ( $request['post_type'] ?? 'posts' ) );
			$post_type = $post_id && get_post( $post_id ) ? get_post( $post_id )->post_type : ( 'posts' === $rest_base ? 'post' : $rest_base );
			// Default-channel podcasting lives on plain posts.
			if ( 'post' !== $post_type ) {
				return rest_ensure_response( array( 'groups' => array() ) );
			}
			if ( $post_id ) {
				if ( ! get_post( $post_id ) ) {
					return new WP_Error( 'not_found', __( 'Post not found.', 'minn-admin' ), array( 'status' => 404 ) );
				}
				if ( ! current_user_can( 'edit_post', $post_id ) ) {
					return new WP_Error( 'rest_forbidden', __( 'You cannot edit this post.', 'minn-admin' ), array( 'status' => 403 ) );
				}
			} else {
				// No post to authorize against, so authorize against the TYPE,
				// the way meta-box and pods already do. The field list here is
				// fixed today, but the gate should not depend on that staying
				// true.
				$type_obj = get_post_type_object( $post_type );
				if ( ! $type_obj || ! current_user_can( $type_obj->cap->edit_posts ) ) {
					return new WP_Error( 'rest_forbidden', __( 'You cannot edit posts.', 'minn-admin' ), array( 'status' => 403 ) );
				}
			}
			return rest_ensure_response( array(
				'groups' => array(
					array(
						'group'  => __( 'Episode (default channel)', 'minn-admin' ),
						'fields' => array(
							array( 'name' => 'url', 'label' => __( 'Media file URL', 'minn-admin' ), 'type' => 'url', 'placeholder' => __( 'https://example.com/episode.mp3 — clearing this removes the episode', 'minn-admin' ) ),
							array( 'name' => 'size', 'label' => __( 'File size (bytes)', 'minn-admin' ), 'type' => 'text', 'placeholder' => __( 'Detected by PowerPress when blank', 'minn-admin' ) ),
							array( 'name' => 'duration', 'label' => __( 'Duration', 'minn-admin' ), 'type' => 'text', 'placeholder' => __( 'HH:MM:SS', 'minn-admin' ) ),
							array( 'name' => 'subtitle', 'label' => __( 'Subtitle', 'minn-admin' ), 'type' => 'text' ),
							array( 'name' => 'episode_title', 'label' => __( 'Episode title (Apple)', 'minn-admin' ), 'type' => 'text' ),
							array( 'name' => 'episode_no', 'label' => __( 'Episode number', 'minn-admin' ), 'type' => 'number' ),
							array( 'name' => 'season', 'label' => __( 'Season', 'minn-admin' ), 'type' => 'number' ),
							array(
								'name'    => 'episode_type',
								'label'   => __( 'Episode type', 'minn-admin' ),
								'type'    => 'select',
								'choices' => array( '' => __( 'Full (default)', 'minn-admin' ), 'full' => __( 'Full Episode', 'minn-admin' ), 'trailer' => __( 'Trailer', 'minn-admin' ), 'bonus' => __( 'Bonus', 'minn-admin' ) ),
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
				if ( ! $id || ! current_user_can( 'edit_post', $id ) || ! minn_admin_powerpress_can_podcast() ) {
					return new stdClass();
				}
				return (object) minn_admin_powerpress_read_values( $id );
			},
			'update_callback' => function ( $value, $post, $field_name = '', $request = null ) {
				if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
					return;
				}
				if ( is_object( $value ) ) {
					$value = (array) $value;
				}
				// Never an error from here. The post is already saved, a new
				// one included, so an error answer would hide its id from the
				// editor (each retry and autosave then made another draft).
				// A refusal rides on the post's own answer instead, naming its
				// fields (minn_admin_panel_report_held(), adapters/acpt.php).
				// The panel and its fields route are not offered to a user
				// PowerPress keeps from episodes, so this is a body sent anyway.
				if ( ! minn_admin_powerpress_can_podcast() ) {
					$keys    = is_array( $value ) ? array_values( array_intersect( array_map( 'strval', array_keys( $value ) ), array_keys( minn_admin_powerpress_labels() ) ) ) : array();
					$refused = array( minn_admin_powerpress_refusal( 'rest_forbidden', __( 'PowerPress limits podcast episodes to podcast editors on this site.', 'minn-admin' ), 403, $keys ) );
				} else {
					$refused = minn_admin_powerpress_write_values( $post->ID, $value );
				}
				if ( ! $refused ) {
					return;
				}
				if ( $request instanceof WP_REST_Request && function_exists( 'minn_admin_panel_held' ) ) {
					foreach ( $refused as $error ) {
						minn_admin_panel_held( $request, $error, 'minn_powerpress' );
					}
					return;
				}
				return $refused[0];
			},
			'schema'          => array(
				'description' => __( 'PowerPress default-channel episode fields for Minn Admin.', 'minn-admin' ),
				'type'        => 'object',
				'context'     => array( 'edit' ),
			),
		)
	);
} );
