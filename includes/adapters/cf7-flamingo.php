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

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_flamingo_ready() || ! minn_admin_flamingo_can_view() ) {
		return $surfaces;
	}
	$cf7 = defined( 'WPCF7_VERSION' );

	$surfaces['cf7'] = array(
		'label'      => 'Forms',
		'family'     => 'forms',
		'group'      => 'workspace', // inbox-shaped (see gravity-forms.php)
		'sub'        => $cf7 ? 'Contact Form 7' : 'Flamingo',
		'icon'       => 'inbox',
		'cap'        => 'read',
		'collection' => array(
			'viewLabel' => 'Messages',
			'route'     => 'minn-admin/v1/cf7/messages',
			'pageQuery' => 'per_page=25&page={page}',
			'search'    => 'search={q}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'tabs'      => array(
				'route'    => 'minn-admin/v1/cf7/channels',
				'valueKey' => 'id',
				'labelKey' => 'title',
				'param'    => 'channel_id',
				'allLabel' => 'All messages',
			),
			'columns'   => array(
				array( 'key' => 'from', 'label' => 'From', 'format' => 'title', 'width' => 'minmax(0,1.2fr)' ),
				array( 'key' => 'subject', 'label' => 'Subject', 'width' => 'minmax(0,1.4fr)' ),
				array( 'key' => 'form', 'label' => 'Form' ),
				array( 'key' => 'status', 'label' => 'Status', 'format' => 'pill', 'width' => '96px' ),
				array( 'key' => 'date', 'label' => 'When', 'format' => 'ago' ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/cf7/messages/{id}',
			),
			'actions'   => array(
				array(
					'label'  => 'Mark as spam',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/cf7/messages/{id}/spam',
					'when'   => array( 'key' => 'spam', 'equals' => 'no' ),
				),
				array(
					'label'  => 'Not spam',
					'method' => 'POST',
					'route'  => 'minn-admin/v1/cf7/messages/{id}/unspam',
					'when'   => array( 'key' => 'spam', 'equals' => 'yes' ),
				),
				array(
					'label'   => 'Trash message',
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/cf7/messages/{id}',
					'confirm' => 'Move this message to the trash? It stays restorable in Flamingo.',
					'danger'  => true,
				),
				array(
					'label' => 'Open in Flamingo ↗',
					'href'  => admin_url( 'admin.php?page=flamingo_inbound&post={id}&action=edit' ),
				),
			),
		),
	);

	if ( $cf7 ) {
		$surfaces['cf7']['manage'] = array(
			'viewLabel' => 'Forms',
			'route'     => 'minn-admin/v1/cf7/forms',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'columns'   => array(
				array( 'key' => 'title', 'label' => 'Form', 'format' => 'title' ),
				array( 'key' => 'shortcode', 'label' => 'Shortcode', 'format' => 'mono', 'width' => 'minmax(0,1fr)' ),
				array( 'key' => 'messages', 'label' => 'Messages', 'format' => 'num' ),
				array( 'key' => 'date', 'label' => 'Updated', 'format' => 'ago' ),
			),
			'detail'    => array(),
			'actions'   => array(
				array(
					'label' => 'Edit in Contact Form 7 ↗',
					'href'  => admin_url( 'admin.php?page=wpcf7&post={id}&action=edit' ),
				),
			),
		);
	}
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_flamingo_ready() ) {
		return;
	}

	register_rest_route( 'minn-admin/v1', '/cf7/channels', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_flamingo_can_view',
		'callback'            => function () {
			return rest_ensure_response( minn_admin_flamingo_channels() );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/cf7/messages', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_flamingo_can_view',
		'callback'            => function ( WP_REST_Request $request ) {
			$per_page = min( 100, max( 1, (int) ( $request['per_page'] ?: 25 ) ) );
			$page     = max( 1, (int) ( $request['page'] ?: 1 ) );

			$args = array(
				'posts_per_page' => $per_page,
				'offset'         => ( $page - 1 ) * $per_page,
				'orderby'        => 'date',
				'order'          => 'DESC',
				// flamingo-spam is exclude_from_search, so 'any' would hide
				// spam — name both statuses explicitly.
				'post_status'    => array( 'publish', Flamingo_Inbound_Message::spam_status ),
			);
			if ( $request['channel_id'] ) {
				$args['channel_id'] = (int) $request['channel_id'];
			}
			if ( $request['search'] ) {
				// find() feeds WP_Query; post_content carries the searchable
				// concatenation of every field.
				$args['s'] = sanitize_text_field( (string) $request['search'] );
			}

			$messages = Flamingo_Inbound_Message::find( $args );
			$total    = Flamingo_Inbound_Message::count();

			$channel_names = array();
			foreach ( minn_admin_flamingo_channels() as $c ) {
				$channel_names[ $c['slug'] ] = $c['title'];
			}

			$items = array();
			foreach ( $messages as $msg ) {
				$post    = get_post( $msg->id() );
				$items[] = array(
					'id'      => (int) $msg->id(),
					'from'    => $msg->from_name ?: ( $msg->from_email ?: '(unknown sender)' ),
					'subject' => $msg->subject ?: '(no subject)',
					'form'    => $channel_names[ (string) $msg->channel ] ?? (string) $msg->channel,
					'status'  => minn_admin_flamingo_status( $msg ),
					'spam'    => $msg->spam ? 'yes' : 'no',
					// post_date is site-local; leave un-zoned so timeAgo
					// parses it as local (fluent-forms precedent).
					'date'    => $post ? str_replace( ' ', 'T', $post->post_date ) : '',
				);
			}

			return rest_ensure_response( array(
				'items' => $items,
				'total' => $total,
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/cf7/messages/(?P<id>\d+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => 'minn_admin_flamingo_can_view',
			'callback'            => function ( WP_REST_Request $request ) {
				$post = get_post( (int) $request['id'] );
				if ( ! $post || Flamingo_Inbound_Message::post_type !== $post->post_type ) {
					return new WP_Error( 'not_found', 'Message not found.', array( 'status' => 404 ) );
				}
				$msg = new Flamingo_Inbound_Message( $post );

				$answers = array();
				foreach ( (array) $msg->fields as $key => $value ) {
					if ( is_array( $value ) ) {
						$value = implode( ', ', array_filter( array_map( 'strval', $value ) ) );
					}
					$value = trim( (string) $value );
					if ( '' === $value ) {
						continue;
					}
					// CF7 field names read like 'your-name' — humanize, and
					// drop the conventional 'your-' prefix.
					$label     = preg_replace( '/^your[-_]/', '', (string) $key );
					$label     = ucwords( str_replace( array( '-', '_' ), ' ', $label ) );
					$answers[] = array(
						'label' => $label,
						'value' => $value,
						'type'  => is_email( $value ) ? 'email'
							: ( 0 === strpos( $value, 'http' ) ? 'url' : 'text' ),
					);
				}

				$meta = array(
					array(
						'label' => 'Submitted',
						'value' => date_i18n( 'M j, Y g:i a', strtotime( $post->post_date ) ),
					),
				);
				$channel_names = array();
				foreach ( minn_admin_flamingo_channels() as $c ) {
					$channel_names[ $c['slug'] ] = $c['title'];
				}
				if ( $msg->channel ) {
					$meta[] = array( 'label' => 'Form', 'value' => $channel_names[ (string) $msg->channel ] ?? (string) $msg->channel );
				}
				if ( $msg->from ) {
					$meta[] = array( 'label' => 'From', 'value' => (string) $msg->from );
				}
				$extra = (array) $msg->meta; // CF7's special mail tags: url, remote_ip, user_agent…
				if ( ! empty( $extra['url'] ) ) {
					$meta[] = array( 'label' => 'Source', 'value' => (string) $extra['url'], 'type' => 'url' );
				}
				if ( ! empty( $extra['remote_ip'] ) ) {
					$meta[] = array( 'label' => 'IP', 'value' => (string) $extra['remote_ip'] );
				}
				if ( ! empty( $extra['user_agent'] ) ) {
					$meta[] = array( 'label' => 'Client', 'value' => (string) $extra['user_agent'] );
				}
				if ( $msg->spam ) {
					$log = array();
					foreach ( (array) $msg->spam_log as $entry ) {
						if ( ! empty( $entry['reason'] ) ) {
							$log[] = (string) $entry['reason'];
						}
					}
					$meta[] = array( 'label' => 'Spam', 'value' => $log ? implode( ' · ', $log ) : 'Marked as spam' );
				}

				return rest_ensure_response( array(
					'kind'     => 'entry',
					'title'    => $msg->subject ?: 'Message',
					'status'   => minn_admin_flamingo_status( $msg ),
					'sections' => array(
						array( 'title' => 'Responses', 'rows' => $answers ),
						array( 'title' => 'Submission', 'rows' => $meta ),
					),
					'adminUrl' => admin_url( 'admin.php?page=flamingo_inbound&post=' . (int) $msg->id() . '&action=edit' ),
				) );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => function () {
				return current_user_can( 'flamingo_delete_inbound_message' );
			},
			'callback'            => function ( WP_REST_Request $request ) {
				$post = get_post( (int) $request['id'] );
				if ( ! $post || Flamingo_Inbound_Message::post_type !== $post->post_type ) {
					return new WP_Error( 'not_found', 'Message not found.', array( 'status' => 404 ) );
				}
				$msg = new Flamingo_Inbound_Message( $post );
				$msg->trash();
				return rest_ensure_response( array( 'id' => (int) $request['id'], 'trashed' => true ) );
			},
		),
	) );

	foreach ( array( 'spam', 'unspam' ) as $op ) {
		register_rest_route( 'minn-admin/v1', '/cf7/messages/(?P<id>\d+)/' . $op, array(
			'methods'             => 'POST',
			'permission_callback' => function () use ( $op ) {
				return current_user_can( "flamingo_{$op}_inbound_message" );
			},
			'callback'            => function ( WP_REST_Request $request ) use ( $op ) {
				$post = get_post( (int) $request['id'] );
				if ( ! $post || Flamingo_Inbound_Message::post_type !== $post->post_type ) {
					return new WP_Error( 'not_found', 'Message not found.', array( 'status' => 404 ) );
				}
				$msg = new Flamingo_Inbound_Message( $post );
				// Their own handlers (Akismet submit rides along like their UI).
				$msg->$op();
				return rest_ensure_response( array( 'id' => (int) $request['id'], 'ok' => true ) );
			},
		) );
	}

	if ( ! defined( 'WPCF7_VERSION' ) ) {
		return;
	}

	register_rest_route( 'minn-admin/v1', '/cf7/forms', array(
		'methods'             => 'GET',
		'permission_callback' => 'minn_admin_flamingo_can_view',
		'callback'            => function ( WP_REST_Request $request ) {
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
					'title'     => $post->post_title ?: '(untitled form)',
					'shortcode' => sprintf( '[contact-form-7 id="%d"]', $post->ID ),
					'messages'  => $counts[ $post->post_name ] ?? 0,
					'date'      => str_replace( ' ', 'T', $post->post_modified ),
				);
			}
			return rest_ensure_response( array(
				'items' => $items,
				'total' => (int) $query->found_posts,
			) );
		},
	) );
} );
