<?php
/**
 * Bundled adapter: JetReviews (Crocoblock, reviews family).
 *
 * JetReviews keeps reviews in its own {prefix}jet_reviews table (id, source
 * post|user|manually|dashboard, post_id + post_type = what was reviewed,
 * author = a WP user id or a guest id from jet_review_guests, date, title,
 * content, type_slug, rating_data, rating, likes, dislikes, approved 0/1,
 * pinned) with comments, media and per-type rating fields in siblings.
 * Nothing of that is a post, so it never showed in Minn. This surface is
 * the moderation inbox: pending / approved / all, search, a contact-style
 * detail, approve / unapprove / delete singly or in bulk, done the way
 * their own endpoints do it (the same table update, then their
 * sync_rating_source_meta_by_id so the reviewed item's rating meta stays
 * true; delete also drops the user-approval record and media through their
 * managers). Review types, rating fields and the front-end form stay in
 * JetReviews. Every one of their admin endpoints and screens gates on
 * manage_options, so that gate is parity, not a downgrade.
 *
 * @package minn-admin
 */
defined( 'ABSPATH' ) || exit;

function minn_admin_jet_reviews_active() {
	return function_exists( 'jet_reviews' ) && class_exists( '\Jet_Reviews\Reviews\Data' ) && is_object( jet_reviews()->db );
}

function minn_admin_jet_reviews_can() {
	return current_user_can( 'manage_options' );
}

/** Their DB manager's table names: keys 'reviews', 'review_guests', 'review_comments'… */
function minn_admin_jet_reviews_table( $key = 'reviews' ) {
	global $wpdb;
	try {
		$name = jet_reviews()->db->tables( $key, 'name' );
		if ( is_string( $name ) && '' !== $name ) {
			return $name;
		}
	} catch ( \Throwable $e ) {
		// fall through to the known prefix shape
	}
	return $wpdb->prefix . 'jet_' . $key;
}

function minn_admin_jet_reviews_has_table() {
	global $wpdb;
	$t = minn_admin_jet_reviews_table();
	return 0 === strcasecmp( (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ), $t );
}

/** Their user manager resolves users and guests alike. */
function minn_admin_jet_reviews_author( $author ) {
	try {
		$u = jet_reviews()->user_manager->get_raw_user_data( $author );
		return array( 'name' => (string) ( $u['name'] ?? '' ), 'mail' => (string) ( $u['mail'] ?? '' ), 'guest' => in_array( 'guest', (array) ( $u['roles'] ?? array() ), true ) );
	} catch ( \Throwable $e ) {
		return array( 'name' => __( 'Guest', 'minn-admin' ), 'mail' => '', 'guest' => true );
	}
}

function minn_admin_jet_reviews_admin_url() {
	return admin_url( 'admin.php?page=jet-reviews-list-page' );
}

function minn_admin_jet_reviews_row( $id ) {
	global $wpdb;
	$t = minn_admin_jet_reviews_table();
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ) );
}

function minn_admin_jet_reviews_item( $r ) {
	$a     = minn_admin_jet_reviews_author( $r->author );
	$title = $r->post_id ? get_the_title( (int) $r->post_id ) : '';
	return array(
		'id'       => (int) $r->id,
		'author'   => '' !== $a['mail'] && 'email@example.com' !== $a['mail'] ? $a['name'] . ' · ' . $a['mail'] : $a['name'],
		'item'     => '' !== $title ? wp_strip_all_tags( $title ) : ( $r->post_id ? '#' . (int) $r->post_id : '—' ),
		'rating'   => (int) $r->rating ? sprintf( '%d/5', (int) round( (int) $r->rating / 20 ) ) : '—',
		'status'   => (int) $r->approved ? 'approved' : 'pending',
		'date'     => $r->date ? mysql2date( 'c', $r->date, false ) : '',
		'approved' => (int) $r->approved,
	);
}

/** The vendor's post-write sync, so the reviewed item's rating meta follows. */
function minn_admin_jet_reviews_sync( $r ) {
	try {
		\Jet_Reviews\Reviews\Data::get_instance()->sync_rating_source_meta_by_id( $r->source, $r->post_type, $r->post_id );
	} catch ( \Throwable $e ) {
		// The review row is already written; a stale source meta must not undo it.
	}
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_jet_reviews_active() || ! minn_admin_jet_reviews_can() ) {
		return $surfaces;
	}
	$route = 'minn-admin/v1/jet-reviews/reviews/{id}/approve';
	$surfaces['jet-reviews'] = array(
		'label'      => __( 'Reviews', 'minn-admin' ),
		'sub'        => 'JetReviews',
		'family'     => 'reviews',
		'group'      => 'workspace',
		'icon'       => 'star',
		'cap'        => 'manage_options',
		'status'     => array( 'route' => 'minn-admin/v1/jet-reviews/status' ),
		'collection' => array(
			'viewLabel' => __( 'Reviews', 'minn-admin' ),
			'route'     => 'minn-admin/v1/jet-reviews/reviews',
			'pageQuery' => 'per_page=25&page={page}',
			'search'    => 'search={q}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'tabs'      => array(
				'param'    => 'status',
				'static'   => array( array( 'pending', __( 'Pending', 'minn-admin' ) ), array( 'approved', __( 'Approved', 'minn-admin' ) ) ),
				'allLabel' => __( 'All', 'minn-admin' ),
			),
			'columns'   => array(
				array( 'key' => 'author', 'label' => __( 'Reviewer', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,1.4fr)' ),
				array( 'key' => 'item', 'label' => __( 'Reviewed', 'minn-admin' ) ),
				array( 'key' => 'rating', 'label' => __( 'Rating', 'minn-admin' ), 'width' => '80px' ),
				array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill', 'width' => '110px' ),
				array( 'key' => 'date', 'label' => __( 'When', 'minn-admin' ), 'format' => 'ago' ),
			),
			'detail'    => array( 'sectionsRoute' => 'minn-admin/v1/jet-reviews/reviews/{id}' ),
			'actions'   => array(
				array( 'label' => __( 'Approve', 'minn-admin' ), 'method' => 'POST', 'route' => $route, 'body' => array( 'approved' => true ), 'when' => array( 'key' => 'status', 'equals' => 'pending' ) ),
				array( 'label' => __( 'Unapprove', 'minn-admin' ), 'method' => 'POST', 'route' => $route, 'body' => array( 'approved' => false ), 'when' => array( 'key' => 'status', 'equals' => 'approved' ) ),
				array(
					'label'   => __( 'Delete', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/jet-reviews/reviews/{id}',
					'confirm' => __( 'Delete this review and its comments permanently? JetReviews has no trash.', 'minn-admin' ),
					'danger'  => true,
				),
				array( 'label' => __( 'Open in JetReviews ↗', 'minn-admin' ), 'href' => minn_admin_jet_reviews_admin_url() ),
			),
			'bulk'      => array(
				array( 'label' => __( 'Approve', 'minn-admin' ), 'method' => 'POST', 'route' => $route, 'body' => array( 'approved' => true ) ),
				array( 'label' => __( 'Unapprove', 'minn-admin' ), 'method' => 'POST', 'route' => $route, 'body' => array( 'approved' => false ) ),
				array( 'label' => __( 'Delete', 'minn-admin' ), 'method' => 'DELETE', 'route' => 'minn-admin/v1/jet-reviews/reviews/{id}', 'confirm' => __( 'Delete the selected reviews permanently?', 'minn-admin' ), 'danger' => true ),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_jet_reviews_active() ) {
		return;
	}
	$perm = 'minn_admin_jet_reviews_can';

	register_rest_route( 'minn-admin/v1', '/jet-reviews/reviews', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			if ( ! minn_admin_jet_reviews_has_table() ) {
				return rest_ensure_response( array( 'items' => array(), 'total' => 0 ) );
			}
			global $wpdb;
			$t        = minn_admin_jet_reviews_table();
			$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
			$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
			$status   = sanitize_key( (string) $request->get_param( 'status' ) );
			$search   = sanitize_text_field( (string) $request->get_param( 'search' ) );
			$where    = array( '1=1' );
			$params   = array();
			if ( 'pending' === $status ) {
				$where[] = 'r.approved = 0';
			} elseif ( 'approved' === $status ) {
				$where[] = 'r.approved = 1';
			}
			if ( '' !== $search ) {
				$like     = '%' . $wpdb->esc_like( $search ) . '%';
				$where[]  = '(r.title LIKE %s OR r.content LIKE %s OR p.post_title LIKE %s OR u.display_name LIKE %s OR u.user_email LIKE %s OR g.name LIKE %s OR g.mail LIKE %s)';
				$params   = array_merge( $params, array_fill( 0, 7, $like ) );
			}
			$guests = minn_admin_jet_reviews_table( 'review_guests' );
			$from   = "{$t} r LEFT JOIN {$wpdb->posts} p ON p.ID = r.post_id LEFT JOIN {$wpdb->users} u ON u.ID = r.author LEFT JOIN {$guests} g ON g.guest_id = r.author";
			$wsql   = 'WHERE ' . implode( ' AND ', $where );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$count_sql = "SELECT COUNT(*) FROM {$from} {$wsql}";
			$total     = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ) : $wpdb->get_var( $count_sql ) );
			$rows      = $wpdb->get_results( $wpdb->prepare( "SELECT r.* FROM {$from} {$wsql} ORDER BY r.id DESC LIMIT %d OFFSET %d", array_merge( $params, array( $per_page, ( $page - 1 ) * $per_page ) ) ) );
			// phpcs:enable
			return rest_ensure_response( array( 'items' => array_map( 'minn_admin_jet_reviews_item', $rows ? $rows : array() ), 'total' => $total ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/jet-reviews/reviews/(?P<id>\d+)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$r = minn_admin_jet_reviews_has_table() ? minn_admin_jet_reviews_row( (int) $request['id'] ) : null;
				if ( ! $r ) {
					return new WP_Error( 'not_found', __( 'Review not found', 'minn-admin' ), array( 'status' => 404 ) );
				}
				$a     = minn_admin_jet_reviews_author( $r->author );
				$item  = minn_admin_jet_reviews_item( $r );
				$title = $r->post_id ? wp_strip_all_tags( get_the_title( (int) $r->post_id ) ) : '';
				$rows  = array(
					array( 'label' => __( 'Rating', 'minn-admin' ), 'value' => $item['rating'] ),
					array( 'label' => __( 'Status', 'minn-admin' ), 'value' => $item['status'] ),
					array( 'label' => __( 'Reviewed', 'minn-admin' ), 'value' => '' !== $title ? $title : '—', 'type' => $r->post_id ? 'link' : '', 'href' => $r->post_id ? get_permalink( (int) $r->post_id ) : '' ),
					array( 'label' => __( 'Source', 'minn-admin' ), 'value' => (string) $r->source ),
					array( 'label' => __( 'Type', 'minn-admin' ), 'value' => (string) $r->type_slug ),
					array( 'label' => __( 'Likes / dislikes', 'minn-admin' ), 'value' => (int) $r->likes . ' / ' . (int) $r->dislikes ),
					array( 'label' => __( 'Date', 'minn-admin' ), 'value' => $r->date ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $r->date ) ) : '—' ),
				);
				// Per-field scores from their rating_data blob, decoded only
				// as JSON; a serialized blob stays opaque.
				$breakdown = is_string( $r->rating_data ) ? json_decode( $r->rating_data, true ) : null;
				if ( is_array( $breakdown ) ) {
					foreach ( $breakdown as $f ) {
						if ( is_array( $f ) && isset( $f['title'], $f['value'] ) ) {
							$rows[] = array( 'label' => (string) $f['title'], 'value' => (string) $f['value'] );
						}
					}
				}
				return rest_ensure_response( array(
					'kind'     => 'entry',
					'status'   => $item['status'],
					'title'    => '' !== (string) $r->title ? wp_strip_all_tags( (string) $r->title ) : __( 'Review', 'minn-admin' ),
					'sections' => array(
						array( 'title' => __( 'Reviewer', 'minn-admin' ), 'rows' => array(
							array( 'label' => __( 'Name', 'minn-admin' ), 'value' => $a['name'] . ( $a['guest'] ? ' (' . __( 'guest', 'minn-admin' ) . ')' : '' ) ),
							array( 'label' => __( 'Email', 'minn-admin' ), 'value' => '' !== $a['mail'] ? $a['mail'] : '—', 'type' => 'email' ),
							array( 'label' => __( 'Message', 'minn-admin' ), 'value' => wp_strip_all_tags( (string) $r->content ) ),
						) ),
						array( 'title' => __( 'Review', 'minn-admin' ), 'rows' => $rows ),
					),
					'adminUrl' => minn_admin_jet_reviews_admin_url(),
				) );
			},
		),
		array(
			'methods'             => 'DELETE',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) {
				$id = (int) $request['id'];
				$r  = minn_admin_jet_reviews_row( $id );
				if ( ! $r ) {
					return new WP_Error( 'not_found', __( 'Review not found', 'minn-admin' ), array( 'status' => 404 ) );
				}
				try {
					// Their delete-review endpoint, step for step.
					jet_reviews()->user_manager->delete_user_approval_review( $id );
					$deleted = \Jet_Reviews\Reviews\Data::get_instance()->delete_review_by_id( $id );
					if ( class_exists( '\Jet_Reviews\Reviews\Media' ) ) {
						\Jet_Reviews\Reviews\Media::get_instance()->delete_media_by_review_id( $id );
					}
					minn_admin_jet_reviews_sync( $r );
				} catch ( \Throwable $e ) {
					return new WP_Error( 'delete_failed', __( 'JetReviews could not delete that review.', 'minn-admin' ), array( 'status' => 500 ) );
				}
				if ( empty( $deleted ) || minn_admin_jet_reviews_row( $id ) ) {
					return new WP_Error( 'delete_failed', __( 'JetReviews could not delete that review.', 'minn-admin' ), array( 'status' => 500 ) );
				}
				return rest_ensure_response( array( 'ok' => true, 'message' => __( 'Review deleted.', 'minn-admin' ) ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/jet-reviews/reviews/(?P<id>\d+)/approve', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			global $wpdb;
			$id = (int) $request['id'];
			$r  = minn_admin_jet_reviews_row( $id );
			if ( ! $r ) {
				return new WP_Error( 'not_found', __( 'Review not found', 'minn-admin' ), array( 'status' => 404 ) );
			}
			$on = filter_var( $request->get_param( 'approved' ), FILTER_VALIDATE_BOOLEAN );
			// Their toggle-review-approve endpoint: the same table update, then the source sync.
			$ok = $wpdb->update( minn_admin_jet_reviews_table(), array( 'approved' => $on ? 1 : 0 ), array( 'id' => $id ), array( '%d' ), array( '%d' ) );
			if ( false === $ok ) {
				return new WP_Error( 'update_failed', __( 'JetReviews could not update that review.', 'minn-admin' ), array( 'status' => 500 ) );
			}
			minn_admin_jet_reviews_sync( $r );
			return rest_ensure_response( array(
				'ok'      => true,
				'status'  => $on ? 'approved' : 'pending',
				'message' => $on ? __( 'Review approved.', 'minn-admin' ) : __( 'Review unapproved.', 'minn-admin' ),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/jet-reviews/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			$open = array( array( 'label' => __( 'Open JetReviews ↗', 'minn-admin' ), 'href' => minn_admin_jet_reviews_admin_url() ) );
			if ( ! minn_admin_jet_reviews_has_table() ) {
				return rest_ensure_response( array( 'rows' => array( array( 'label' => __( 'Reviews', 'minn-admin' ), 'value' => '—' ) ), 'actions' => $open ) );
			}
			global $wpdb;
			$t = minn_admin_jet_reviews_table();
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$pending = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE approved = 0" );
			$total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" );
			$avg     = $wpdb->get_var( "SELECT AVG(rating) FROM {$t} WHERE approved = 1 AND rating > 0" );
			// phpcs:enable
			return rest_ensure_response( array(
				'rows'    => array(
					array( 'label' => __( 'Pending', 'minn-admin' ), 'value' => number_format_i18n( $pending ) ),
					array( 'label' => __( 'Reviews', 'minn-admin' ), 'value' => number_format_i18n( $total ) ),
					array( 'label' => __( 'Average', 'minn-admin' ), 'value' => $avg ? number_format_i18n( (float) $avg / 20, 1 ) . '/5' : '—', 'hint' => __( 'approved reviews', 'minn-admin' ) ),
				),
				'actions' => $open,
			) );
		},
	) );
} );
