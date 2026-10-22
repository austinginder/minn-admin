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

// Blubrry's OAuth tokens and client secret, and the Blubrry account login
// (base64 user:password) inside the general settings.
minn_admin_secrets( 'powerpress', array( 'options' => array( 'powerpress_creds', 'powerpress_general' ) ) );

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

/**
 * The episode group (fields tier, minn_admin_fields()).
 *
 * @return array[]
 */
function minn_admin_powerpress_groups() {
	return array(
		array(
			'group'  => __( 'Episode (default channel)', 'minn-admin' ),
			'fields' => array(
				array( 'name' => 'url', 'label' => __( 'Media file URL', 'minn-admin' ), 'type' => 'url', 'placeholder' => __( 'https://example.com/episode.mp3 — clearing this removes the episode', 'minn-admin' ) ),
				array(
					'name'        => 'size',
					'label'       => __( 'File size (bytes)', 'minn-admin' ),
					'type'        => 'text',
					'placeholder' => __( 'Detected by PowerPress when blank', 'minn-admin' ),
					'check'       => function ( $v ) {
						return ctype_digit( (string) $v ) ? true : __( 'Enter the file size as a number of bytes.', 'minn-admin' );
					},
				),
				array(
					'name'        => 'duration',
					'label'       => __( 'Duration', 'minn-admin' ),
					'type'        => 'text',
					'placeholder' => __( 'HH:MM:SS', 'minn-admin' ),
					'check'       => function ( $v ) {
						return preg_match( '/^\d{1,2}(:[0-5]?\d){1,2}$/', (string) $v ) ? true : __( 'Enter the duration as HH:MM:SS or MM:SS.', 'minn-admin' );
					},
				),
				array( 'name' => 'subtitle', 'label' => __( 'Subtitle', 'minn-admin' ), 'type' => 'text' ),
				array( 'name' => 'episode_title', 'label' => __( 'Episode title (Apple)', 'minn-admin' ), 'type' => 'text' ),
				array( 'name' => 'episode_no', 'label' => __( 'Episode number', 'minn-admin' ), 'type' => 'number', 'integer' => true, 'min' => 0 ),
				array( 'name' => 'season', 'label' => __( 'Season', 'minn-admin' ), 'type' => 'number', 'integer' => true, 'min' => 0 ),
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
	);
}

/**
 * Store the panel's changes in the enclosure blob, rebuilt only when
 * something actually changed.
 *
 * @param int   $post_id Post ID.
 * @param array $changes Field => cleaned value, null to clear.
 * @return array Field => why it was not stored.
 */
function minn_admin_powerpress_store( $post_id, $changes ) {
	$enc     = minn_admin_powerpress_parse( get_post_meta( $post_id, 'enclosure', true ) );
	$changed = false;
	if ( array_key_exists( 'url', $changes ) ) {
		if ( null === $changes['url'] ) {
			// Clearing the URL removes the episode entirely (their remove
			// flow, including the legacy itunes:duration meta).
			if ( '' !== $enc['url'] ) {
				delete_post_meta( $post_id, 'enclosure' );
				delete_post_meta( $post_id, 'itunes:duration' );
				return array();
			}
		} elseif ( $changes['url'] !== $enc['url'] ) {
			$enc['url'] = $changes['url'];
			// A new file usually means a new content type.
			$type = powerpress_get_contenttype( $enc['url'] );
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
		foreach ( $changes as $key => $value ) {
			if ( 'url' !== $key && null !== $value ) {
				$orphans[ $key ] = __( 'Add the media file URL first: PowerPress keeps these details with the episode file.', 'minn-admin' );
			}
		}
		return $orphans;
	}
	if ( array_key_exists( 'size', $changes ) ) {
		// Their metabox "modify" checkbox semantics: a typed size is kept
		// over the detected one; an emptied box hands it back to detection.
		$enc['size'] = null === $changes['size'] ? '' : $changes['size'];
		if ( null === $changes['size'] ) {
			unset( $enc['extras']['set_size'] );
		} else {
			$enc['extras']['set_size'] = 1;
		}
		$changed = true;
	}
	if ( array_key_exists( 'duration', $changes ) ) {
		if ( null === $changes['duration'] ) {
			unset( $enc['extras']['duration'], $enc['extras']['set_duration'] );
		} else {
			$enc['extras']['duration']     = $changes['duration'];
			$enc['extras']['set_duration'] = 1;
		}
		$changed = true;
	}
	foreach ( array( 'subtitle', 'episode_title', 'episode_no', 'season', 'episode_type' ) as $key ) {
		if ( ! array_key_exists( $key, $changes ) ) {
			continue;
		}
		if ( null === $changes[ $key ] ) {
			unset( $enc['extras'][ $key ] );
		} else {
			$enc['extras'][ $key ] = (string) $changes[ $key ];
		}
		$changed = true;
	}
	if ( $changed ) {
		// Slashed: update_post_meta() unslashes, and a backslash taken out of
		// an extras string nobody edited ('AC\DC live') left its serialized
		// length wrong, so the whole extras array (duration, chapters,
		// hosting) stopped reading back.
		update_post_meta(
			$post_id,
			'enclosure',
			wp_slash( "{$enc['url']}\n{$enc['size']}\n{$enc['type']}\n" . serialize( $enc['extras'] ) ) // phpcs:ignore -- their exact storage format
		);
	}
	return array();
}

minn_admin_fields( 'powerpress', function () {
	return array(
		'active'      => 'minn_admin_powerpress_active',
		'label'       => __( 'Podcast episode', 'minn-admin' ),
		'sub'         => 'PowerPress',
		'key'         => 'minn_powerpress',
		// Default-channel podcasting lives on plain posts.
		'types'       => array( 'post' ),
		'can'         => 'minn_admin_powerpress_can_podcast',
		'refuse'      => __( 'PowerPress limits podcast episodes to podcast editors on this site.', 'minn-admin' ),
		'groups'      => 'minn_admin_powerpress_groups',
		'read'        => 'minn_admin_powerpress_read_values',
		'write'       => 'minn_admin_powerpress_store',
		'description' => __( 'PowerPress default-channel episode fields for Minn Admin.', 'minn-admin' ),
	);
} );
