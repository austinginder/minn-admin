<?php
/**
 * Bundled adapter: Contact Form 7 + Flamingo entries.
 *
 * CF7 itself stores no submissions; Flamingo does (CPT flamingo_inbound,
 * one channel term per form under the 'contact-form-7' parent). This shim
 * reads through Flamingo's own model class (find/spam/unspam/trash — its
 * meta layout of one `_field_{name}` key per answer stays Flamingo's
 * business) and joins the forms family: entries as contact cards, forms in
 * the Manage view, spam/unspam and trash as row actions. Building forms
 * stays in CF7's editor, one click away.
 *
 * Capability model: Flamingo's own meta caps (flamingo_edit_inbound_messages
 * etc., mapped through ITS map_meta_cap filter — edit_users by default), so
 * a site that remapped them keeps its policy in Minn.
 *
 * The messages are a kernel resource (docs/adapter-kernel.md): the kernel
 * owns the routes, gates, ids, the not-found and wrong-state answers and the
 * post-condition of each action; the rows still come from Flamingo's find()
 * (WP_Query for trash, which find() does not speak) and every change from its
 * model's own methods.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_flamingo_ready() {
	return class_exists( 'Flamingo_Inbound_Message' );
}

function minn_admin_flamingo_can_view() {
	return current_user_can( 'flamingo_edit_inbound_messages' );
}

/** Channel terms (one per form; CF7 nests them under a container parent). */
function minn_admin_flamingo_channels() {
	$terms = get_terms( array(
		'taxonomy'   => Flamingo_Inbound_Message::channel_taxonomy,
		'hide_empty' => false,
	) );
	if ( is_wp_error( $terms ) ) {
		return array();
	}
	$out = array();
	foreach ( $terms as $term ) {
		// The 'contact-form-7' parent is a container, not a form.
		if ( 'contact-form-7' === $term->slug && 0 === (int) $term->parent ) {
			continue;
		}
		$out[] = array(
			'id'    => (int) $term->term_id,
			'title' => $term->name,
			'slug'  => $term->slug,
		);
	}
	return $out;
}

/** A message's channel slug as its form's name. */
function minn_admin_flamingo_channel_name( $slug ) {
	foreach ( minn_admin_flamingo_channels() as $c ) {
		if ( (string) $c['slug'] === (string) $slug ) {
			return $c['title'];
		}
	}
	return (string) $slug;
}

/** CF7 submission statuses → short pill labels; spam wins outright. */
function minn_admin_flamingo_status( $msg ) {
	if ( ! empty( $msg->spam ) ) {
		return 'spam';
	}
	$map = array(
		'mail_sent'   => 'sent',
		'mail_failed' => 'failed',
	);
	$s = (string) $msg->submission_status;
	return $map[ $s ] ?? ( $s ? $s : 'inbound' );
}

/** The list's bucket a post status files a message under. */
function minn_admin_flamingo_bucket( $post_status ) {
	if ( 'trash' === $post_status ) {
		return 'trash';
	}
	return Flamingo_Inbound_Message::spam_status === $post_status ? 'spam' : 'inbox';
}

/** One message as the routes see it, or null when the id is not one. */
function minn_admin_flamingo_get( $id ) {
	$post = get_post( (int) $id );
	if ( ! $post || Flamingo_Inbound_Message::post_type !== $post->post_type ) {
		return null;
	}
	return array(
		'ID'          => (int) $post->ID,
		'post_status' => (string) $post->post_status,
		'post_date'   => (string) $post->post_date,
		'bucket'      => minn_admin_flamingo_bucket( $post->post_status ),
	);
}

/** Whether the post is still in the table, whatever it now is. */
function minn_admin_flamingo_exists( $id ) {
	return null !== Minn_Admin_Kernel_SQL::row( 'posts', 'ID', (int) $id, array( 'ID' ) );
}

/**
 * Trashed, or deleted where the site keeps no trash: Flamingo's trash()
 * deletes outright when EMPTY_TRASH_DAYS is 0.
 */
function minn_admin_flamingo_trashed( $id, $fresh ) {
	if ( is_array( $fresh ) ) {
		return 'trash' === $fresh['bucket'];
	}
	return ! EMPTY_TRASH_DAYS && ! minn_admin_flamingo_exists( $id );
}

/**
 * Server-built model for the surface status card (SureForms parity).
 * Counts via core's wp_count_posts on Flamingo's CPT — publish is the
 * inbox, flamingo-spam is its exclude_from_search spam status, trash is
 * trash. No model query needed, and the numbers match their list table.
 */
function minn_admin_flamingo_status_model() {
	$counts = wp_count_posts( Flamingo_Inbound_Message::post_type );
	$inbox  = (int) ( $counts->publish ?? 0 );
	$spam   = (int) ( $counts->{'flamingo-spam'} ?? 0 );
	$trash  = (int) ( $counts->trash ?? 0 );
	$forms  = count( minn_admin_flamingo_channels() );
	$bits   = array();
	if ( $spam ) {
		$bits[] = sprintf(
			/* translators: %s: number of spam messages. */
			__( '%s spam', 'minn-admin' ),
			number_format_i18n( $spam )
		);
	}
	if ( $trash ) {
		$bits[] = sprintf(
			/* translators: %s: number of messages in the trash. */
			__( '%s in trash', 'minn-admin' ),
			number_format_i18n( $trash )
		);
	}
	// Inbound messages are posts; post_date is site-local, so the chart groups
	// on the site's days.
	$n = Minn_Admin_Kernel::inbox_counts( array(
		'table' => 'posts',
		'chart' => array(
			'column'    => 'post_date',
			'clock'     => 'local',
			'where'     => array(
				'post_type'   => Flamingo_Inbound_Message::post_type,
				'post_status' => array( 'publish', Flamingo_Inbound_Message::spam_status ),
			),
			'split'     => 'post_status',
			'secondary' => array( Flamingo_Inbound_Message::spam_status ),
		),
	) );
	return minn_admin_forms_card(
		array(
			'label' => __( 'Inbox messages', 'minn-admin' ),
			'value' => number_format_i18n( $inbox ),
			'hint'  => $bits ? implode( ', ', $bits ) : __( 'All stored messages', 'minn-admin' ),
		),
		$forms,
		$n['days'],
		array( 'label' => __( 'Open Flamingo ↗', 'minn-admin' ), 'href' => admin_url( 'admin.php?page=flamingo_inbound' ) ),
		__( 'Spam', 'minn-admin' ),
		__( 'Messages', 'minn-admin' )
	);
}

/**
 * A page of messages through Flamingo's own find(), which takes WP_Query
 * args (flamingo-spam is exclude_from_search, so statuses are named
 * explicitly). find() does not speak 'trash'; WP_Query serves that bucket.
 *
 * @param array $q The kernel's list query.
 * @return array [ rows, total ]
 */
function minn_admin_flamingo_list( array $q ) {
	$r       = $q['request'];
	$bucket  = (string) $q['filter'];
	$status  = (string) ( $q['where']['post_status'] ?? 'publish' );
	$channel = (int) $r->get_param( 'channel_id' );

	// A status-chart bar narrows the list to that day. Flamingo messages are
	// POSTS, so core's date_query serves both branches. post_date is the site's
	// clock, the same one the bounds arrive on.
	$date_query = array();
	$after      = (string) $r->get_param( 'after' );
	$before     = (string) $r->get_param( 'before' );
	if ( '' !== $after || '' !== $before ) {
		$date_query = array( array( 'inclusive' => true ) );
		if ( '' !== $after ) {
			$date_query[0]['after'] = $after;
		}
		if ( '' !== $before ) {
			$date_query[0]['before'] = $before;
		}
	}

	if ( 'trash' === $status ) {
		$q_args = array(
			'post_type'      => Flamingo_Inbound_Message::post_type,
			'post_status'    => 'trash',
			'date_query'     => $date_query,
			'posts_per_page' => $q['per_page'],
			'paged'          => $q['page'],
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( $channel ) {
			$q_args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => Flamingo_Inbound_Message::channel_taxonomy,
					'field'    => 'term_id',
					'terms'    => $channel,
				),
			);
		}
		if ( '' !== $q['search'] ) {
			$q_args['s'] = sanitize_text_field( $q['search'] );
		}
		$query    = new WP_Query( $q_args );
		$messages = array();
		foreach ( $query->posts as $post ) {
			$messages[] = new Flamingo_Inbound_Message( $post );
		}
		$total = (int) $query->found_posts;
	} else {
		$args = array(
			'posts_per_page' => $q['per_page'],
			'offset'         => ( $q['page'] - 1 ) * $q['per_page'],
			'date_query'     => $date_query,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'post_status'    => $status,
		);
		if ( $channel ) {
			$args['channel_id'] = $channel;
		}
		if ( '' !== $q['search'] ) {
			$args['s'] = sanitize_text_field( $q['search'] );
		}
		$messages = Flamingo_Inbound_Message::find( $args );
		// find()'s count() is unfiltered — recount via a limit=-1 pass.
		$count_args                   = $args;
		$count_args['posts_per_page'] = -1;
		$count_args['offset']         = 0;
		$total                        = count( Flamingo_Inbound_Message::find( $count_args ) );
	}
	$rows = array();
	foreach ( $messages as $msg ) {
		$rows[] = array( 'message' => $msg, 'bucket' => $bucket );
	}
	return array( $rows, $total );
}

/** A message's list row, from Flamingo's model. */
function minn_admin_flamingo_row( array $row ) {
	$msg    = $row['message'];
	$bucket = $row['bucket'];
	$post   = get_post( $msg->id() );
	return array(
		'id'      => (int) $msg->id(),
		'from'    => $msg->from_name ?: ( $msg->from_email ?: __( '(unknown sender)', 'minn-admin' ) ),
		'subject' => $msg->subject ?: __( '(no subject)', 'minn-admin' ),
		'form'    => minn_admin_flamingo_channel_name( $msg->channel ),
		'status'  => 'trash' === $bucket ? 'trash' : minn_admin_flamingo_status( $msg ),
		'bucket'  => $bucket,
		'spam'    => $msg->spam ? 'yes' : 'no',
		'date'    => $post ? $post->post_date : '',
	);
}

/** A message's detail: every answer, then the submission's own facts. */
function minn_admin_flamingo_view( array $row ) {
	$msg = new Flamingo_Inbound_Message( (int) $row['ID'] );

	$answers = array();
	foreach ( (array) $msg->fields as $key => $value ) {
		if ( is_array( $value ) ) {
			$value = implode( ', ', array_filter( array_map( 'strval', $value ) ) );
		}
		$value = trim( (string) $value );
		if ( '' === $value ) {
			continue;
		}
		// CF7 field names read like 'your-name' — humanize, and drop the
		// conventional 'your-' prefix.
		$label     = preg_replace( '/^your[-_]/', '', (string) $key );
		$answers[] = minn_admin_forms_typed( ucwords( str_replace( array( '-', '_' ), ' ', $label ) ), $value );
	}

	$meta = minn_admin_forms_meta_dated( date_i18n( 'M j, Y g:i a', strtotime( $row['post_date'] ) ), $msg->channel ? minn_admin_flamingo_channel_name( $msg->channel ) : null );
	if ( $msg->from ) {
		$meta[] = array( 'label' => __( 'From', 'minn-admin' ), 'value' => (string) $msg->from );
	}
	$extra = (array) $msg->meta; // CF7's special mail tags: url, remote_ip, user_agent…
	if ( ! empty( $extra['url'] ) ) {
		$meta[] = array( 'label' => __( 'Source', 'minn-admin' ), 'value' => (string) $extra['url'], 'type' => 'url' );
	}
	if ( ! empty( $extra['remote_ip'] ) ) {
		$meta[] = array( 'label' => 'IP', 'value' => (string) $extra['remote_ip'] );
	}
	if ( ! empty( $extra['user_agent'] ) ) {
		$meta[] = array( 'label' => __( 'Client', 'minn-admin' ), 'value' => (string) $extra['user_agent'] );
	}
	if ( $msg->spam ) {
		$log = array();
		foreach ( (array) $msg->spam_log as $entry ) {
			if ( ! empty( $entry['reason'] ) ) {
				$log[] = (string) $entry['reason'];
			}
		}
		$meta[] = array( 'label' => __( 'Spam', 'minn-admin' ), 'value' => $log ? implode( ' · ', $log ) : 'Marked as spam' );
	}

	return minn_admin_forms_entry( $answers, $meta, admin_url( 'admin.php?page=flamingo_inbound&post=' . (int) $msg->id() . '&action=edit' ), array(
		'responses' => true,
		'title'     => $msg->subject ?: 'Message',
		'status'    => minn_admin_flamingo_status( $msg ),
	) );
}

/** Every action's reply names the message it acted on. */
function minn_admin_flamingo_id_reply( $id ) {
	return array( 'id' => (int) $id );
}

// Flamingo's own meta caps, mapped through ITS map_meta_cap filter. Its
// untrash action requires the delete meta cap (admin/admin.php, the untrash
// bulk action); stock maps both caps to edit_users, a site that splits them
// means it.
minn_admin_resource( 'cf7/messages', function () {
	return array(
		'active'  => 'minn_admin_flamingo_ready',
		'gates'   => array(
			'view'   => 'minn_admin_flamingo_can_view',
			'delete' => function () {
				return current_user_can( 'flamingo_delete_inbound_message' );
			},
			'spam'   => function () {
				return current_user_can( 'flamingo_spam_inbound_message' );
			},
			'unspam' => function () {
				return current_user_can( 'flamingo_unspam_inbound_message' );
			},
		),
		'labels'  => array(
			'not_found' => __( 'Message not found.', 'minn-admin' ),
		),
		'source'  => array(
			'list'   => 'minn_admin_flamingo_list',
			'get'    => 'minn_admin_flamingo_get',
			'exists' => 'minn_admin_flamingo_exists',
		),
		'list'    => array(
			// flamingo-spam is exclude_from_search; trash is core trash — three
			// buckets, each one post status the list source asks find() for.
			'filter' => array(
				'param'   => 'status',
				'values'  => array(
					'inbox' => array( 'post_status' => 'publish' ),
					'spam'  => array( 'post_status' => 'flamingo-spam' ),
					'trash' => array( 'post_status' => 'trash' ),
				),
				'default' => 'inbox',
			),
			'search' => array( 'param' => 'search' ),
			'range'  => array( 'column' => 'post_date', 'clock' => 'local' ),
			'row'    => 'minn_admin_flamingo_row',
			'fields' => array(
				'id'      => 'int',
				'from'    => 'text',
				'subject' => 'text',
				'form'    => 'text',
				'status'  => 'text',
				'bucket'  => 'text',
				'spam'    => 'text',
				// post_date is site-local; sent un-zoned so timeAgo parses it as local.
				'date'    => array( 'type' => 'datetime', 'stored' => 'site', 'wire' => 'naked' ),
			),
		),
		'item'    => array( 'view' => 'minn_admin_flamingo_view' ),
		'actions' => array(
			// Their own handlers (Akismet submit rides along like their UI). A
			// message Flamingo already reads as spam is left as it is.
			'spam'    => array(
				'gate'   => 'spam',
				'run'    => function ( $id ) {
					( new Flamingo_Inbound_Message( $id ) )->spam();
					return true;
				},
				'expect' => function ( $id ) {
					return true === ( new Flamingo_Inbound_Message( $id ) )->spam;
				},
				'fail'   => __( 'Flamingo did not mark the message as spam.', 'minn-admin' ),
				'reply'  => 'minn_admin_flamingo_id_reply',
				'done'   => __( 'Marked as spam.', 'minn-admin' ),
			),
			// Out of the spam status. Flamingo may still read a message as spam by
			// its Akismet flag when Akismet could not be told.
			'unspam'  => array(
				'gate'   => 'unspam',
				'run'    => function ( $id ) {
					( new Flamingo_Inbound_Message( $id ) )->unspam();
					return true;
				},
				'expect' => function ( $id, $fresh ) {
					return is_array( $fresh ) && 'spam' !== $fresh['bucket'];
				},
				'fail'   => __( 'Flamingo did not mark the message as not spam.', 'minn-admin' ),
				'reply'  => 'minn_admin_flamingo_id_reply',
				'done'   => __( 'Marked not spam.', 'minn-admin' ),
			),
			'trash'   => array(
				'gate'   => 'delete',
				'run'    => function ( $id ) {
					( new Flamingo_Inbound_Message( $id ) )->trash();
					return true;
				},
				'expect' => function ( $id, $fresh ) {
					return minn_admin_flamingo_trashed( $id, $fresh );
				},
				'fail'   => __( 'Flamingo did not move the message to trash.', 'minn-admin' ),
				'reply'  => 'minn_admin_flamingo_id_reply',
				'done'   => __( 'Moved to trash.', 'minn-admin' ),
			),
			'restore' => array(
				'gate'   => 'delete',
				'when'   => array( 'bucket' => array( 'trash' ) ),
				'code'   => 'not_trashed',
				'refuse' => __( 'Only trashed messages can be restored.', 'minn-admin' ),
				// Flamingo's wp_untrash_post_status filter puts back the status
				// the message was trashed from.
				'run'    => function ( $id ) {
					( new Flamingo_Inbound_Message( $id ) )->untrash();
					return true;
				},
				'expect' => function ( $id, $fresh ) {
					return is_array( $fresh ) && 'trash' !== $fresh['bucket'];
				},
				'fail'   => __( 'Flamingo did not restore the message.', 'minn-admin' ),
				'reply'  => 'minn_admin_flamingo_id_reply',
				'done'   => __( 'Message restored.', 'minn-admin' ),
			),
			// Permanent for a message asked with force, in trash or read as spam
			// (their delete() force-deletes the post); otherwise it goes to trash.
			'delete'  => array(
				'method' => 'DELETE',
				'gate'   => 'delete',
				'run'    => function ( $id, $row, WP_REST_Request $request ) {
					$msg = new Flamingo_Inbound_Message( $id );
					if ( ! empty( $request['force'] ) || 'trash' === $row['post_status'] || ! empty( $msg->spam ) ) {
						$msg->delete();
						return 'deleted';
					}
					$msg->trash();
					return 'trashed';
				},
				'expect' => function ( $id, $fresh, $ran ) {
					return 'deleted' === $ran ? ! minn_admin_flamingo_exists( $id ) : minn_admin_flamingo_trashed( $id, $fresh );
				},
				'fail'   => __( 'Flamingo did not delete the message.', 'minn-admin' ),
				'reply'  => function ( $id, $fresh ) {
					if ( null === $fresh ) {
						return array( 'id' => (int) $id, 'deleted' => true, 'message' => __( 'Message deleted permanently.', 'minn-admin' ) );
					}
					return array( 'id' => (int) $id, 'trashed' => true, 'message' => __( 'Moved to trash.', 'minn-admin' ) );
				},
			),
		),
	);
} );

minn_admin_endpoint( 'cf7/status', array(
	'active' => 'minn_admin_flamingo_ready',
	'gate'   => 'minn_admin_flamingo_can_view',
	'run'    => 'minn_admin_flamingo_status_model',
) );

minn_admin_endpoint( 'cf7/channels', array(
	'active' => 'minn_admin_flamingo_ready',
	'gate'   => 'minn_admin_flamingo_can_view',
	'run'    => 'minn_admin_flamingo_channels',
) );

minn_admin_endpoint( 'cf7/forms', array(
	'active' => function () {
		return minn_admin_flamingo_ready() && defined( 'WPCF7_VERSION' );
	},
	'gate'   => 'minn_admin_flamingo_can_view',
	'run'    => function ( WP_REST_Request $request ) {
		$per_page = min( 100, max( 1, (int) ( $request['per_page'] ?: 25 ) ) );
		$page     = max( 1, (int) ( $request['page'] ?: 1 ) );
		$query    = new WP_Query( array(
			'post_type'      => 'wpcf7_contact_form',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'title',
			'order'          => 'ASC',
		) );

		// Message counts come from each form's channel term.
		$counts = array();
		foreach ( minn_admin_flamingo_channels() as $c ) {
			$term = get_term( $c['id'], Flamingo_Inbound_Message::channel_taxonomy );
			if ( $term && ! is_wp_error( $term ) ) {
				$counts[ $term->slug ] = (int) $term->count;
			}
		}

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = array(
				'id'        => (int) $post->ID,
				'title'     => $post->post_title ?: __( '(untitled form)', 'minn-admin' ),
				'shortcode' => sprintf( '[contact-form-7 id="%d"]', $post->ID ),
				'messages'  => $counts[ $post->post_name ] ?? 0,
				'date'      => str_replace( ' ', 'T', $post->post_modified ),
			);
		}
		return array(
			'items' => $items,
			'total' => (int) $query->found_posts,
		);
	},
) );

minn_admin_add_surface( 'cf7', function () {
	return minn_admin_flamingo_ready() && minn_admin_flamingo_can_view();
}, function () {
	$cf7     = defined( 'WPCF7_VERSION' );
	$ui      = minn_admin_forms_ui( 'cf7/messages' );
	$surface = minn_admin_forms_surface( 'cf7/messages', array(
		'sub'        => $cf7 ? 'Contact Form 7' : 'Flamingo',
		'plugin'     => array( 'contact-form-7', 'flamingo' ),
		'api'        => 'cf7',
		'forms'      => 'cf7/channels',
		'tab'        => 'channel_id',
		'messages'   => true,
		'filter'     => array( 'inbox' => 'Received', 'spam' => 'Spam', 'trash' => 'Trash' ),
		'lead'       => array(
			array( 'key' => 'from', 'label' => __( 'From', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,1.2fr)' ),
			array( 'key' => 'subject', 'label' => __( 'Subject', 'minn-admin' ), 'width' => 'minmax(0,1.4fr)' ),
		),
		'form'       => 'form',
		// The messages are Flamingo's inbox, so they leave with Flamingo or
		// that page, and Contact Form 7's forms list takes their place while
		// Contact Form 7 is still in the menu; the forms list leaves with
		// Contact Form 7.
		'collection' => array(
			'plugin'     => 'flamingo',
			'adminPages' => array( 'flamingo_inbound' ),
		),
		'actions'    => array(
			$ui( 'spam', array( 'label' => __( 'Mark as spam', 'minn-admin' ), 'key' => 'bucket', 'when' => 'inbox' ) ),
			$ui( 'unspam', array( 'label' => __( 'Not spam', 'minn-admin' ), 'key' => 'bucket', 'when' => 'spam' ) ),
			$ui( 'trash', array( 'label' => __( 'Trash message', 'minn-admin' ), 'confirm' => __( 'Move this message to trash?', 'minn-admin' ), 'danger' => true, 'key' => 'bucket', 'when' => 'inbox' ) ),
			$ui( 'restore', array( 'label' => __( 'Restore', 'minn-admin' ), 'when' => 'trash' ) ),
			// Permanent, whatever bucket the message is in by the time it arrives.
			$ui( 'delete', array( 'label' => __( 'Delete permanently', 'minn-admin' ), 'confirm' => __( 'Delete this message permanently? There is no undo.', 'minn-admin' ), 'danger' => true, 'query' => 'force=1', 'key' => 'bucket', 'when' => 'trash' ) ),
			$ui( 'delete', array( 'label' => __( 'Delete permanently', 'minn-admin' ), 'confirm' => __( 'Delete this message permanently? There is no undo.', 'minn-admin' ), 'danger' => true, 'query' => 'force=1', 'key' => 'bucket', 'when' => 'spam' ) ),
			array(
				'label' => __( 'Open in Flamingo ↗', 'minn-admin' ),
				'href'  => admin_url( 'admin.php?page=flamingo_inbound&post={id}&action=edit' ),
			),
		),
		'bulk'       => array(
			$ui( 'spam', array( 'label' => __( 'Mark as spam', 'minn-admin' ), 'key' => 'bucket', 'when' => 'inbox' ) ),
			$ui( 'trash', array( 'label' => __( 'Trash', 'minn-admin' ), 'confirm' => __( 'Move the selected messages to trash?', 'minn-admin' ), 'danger' => true, 'key' => 'bucket', 'when' => 'inbox' ) ),
			$ui( 'restore', array( 'label' => __( 'Restore', 'minn-admin' ), 'when' => 'trash' ) ),
			$ui( 'delete', array( 'label' => __( 'Delete permanently', 'minn-admin' ), 'confirm' => __( 'Delete the selected messages permanently?', 'minn-admin' ), 'danger' => true, 'query' => 'force=1', 'key' => 'bucket', 'when' => 'trash' ) ),
		),
	) );
	if ( $cf7 ) {
		// A row opens the form's email and messages page (cf7-mail.php).
		$surface['manage'] = minn_admin_forms_manage( 'cf7', __( 'Edit in Contact Form 7 ↗', 'minn-admin' ), admin_url( 'admin.php?page=wpcf7&post={id}&action=edit' ), array(), array(
			'plugin'   => 'contact-form-7',
			'route'    => 'minn-admin/v1/cf7/forms',
			'itemsKey' => 'items',
			'totalKey' => 'total',
			'columns'  => array(
				array( 'key' => 'title', 'label' => __( 'Form', 'minn-admin' ), 'format' => 'title' ),
				array( 'key' => 'shortcode', 'label' => __( 'Shortcode', 'minn-admin' ), 'format' => 'mono', 'width' => 'minmax(0,1fr)' ),
				array( 'key' => 'messages', 'label' => __( 'Messages', 'minn-admin' ), 'format' => 'num' ),
				array( 'key' => 'date', 'label' => __( 'Updated', 'minn-admin' ), 'format' => 'ago' ),
			),
			'open'     => array( 'route' => 'cf7/form/{id}' ),
		) );
	}
	return $surface;
} );
