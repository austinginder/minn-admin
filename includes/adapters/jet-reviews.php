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

/** A table of theirs as the kernel names it. */
function minn_admin_jet_reviews_spec( $key = 'reviews' ) {
	return Minn_Admin_Kernel_SQL::named( minn_admin_jet_reviews_table( $key ) );
}

function minn_admin_jet_reviews_has_table() {
	return Minn_Admin_Kernel_SQL::exists( minn_admin_jet_reviews_spec() );
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

/**
 * The reviewed item's name as plain text. get_the_title() runs wptexturize,
 * so a quote arrives as &#8216;, and the client escapes what it is sent.
 */
function minn_admin_jet_reviews_item_title( $r ) {
	return $r->post_id ? Minn_Admin::plain_text( get_the_title( (int) $r->post_id ) ) : '';
}

function minn_admin_jet_reviews_item( $r ) {
	$a     = minn_admin_jet_reviews_author( $r->author );
	$title = minn_admin_jet_reviews_item_title( $r );
	return array(
		'id'       => (int) $r->id,
		'author'   => '' !== $a['mail'] && 'email@example.com' !== $a['mail'] ? $a['name'] . ' · ' . $a['mail'] : $a['name'],
		'item'     => '' !== $title ? $title : ( $r->post_id ? '#' . (int) $r->post_id : '—' ),
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
		'plugin'     => 'jet-reviews',
		'family'     => 'reviews',
		'group'      => 'workspace',
		'icon'       => 'star',
		'cap'        => 'manage_options',
		'status'     => array( 'route' => 'minn-admin/v1/jet-reviews/status' ),
		'collection' => Minn_Admin_Kernel::collection( 'jet-reviews/reviews', array(
			'viewLabel' => __( 'Reviews', 'minn-admin' ),
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
		) ),
	);
	return $surfaces;
} );

/** A review's detail: the reviewer, then the review with its per-question scores. */
function minn_admin_jet_reviews_view( array $row ) {
	$r     = (object) $row;
	$a     = minn_admin_jet_reviews_author( $r->author );
	$item  = minn_admin_jet_reviews_item( $r );
	$title = minn_admin_jet_reviews_item_title( $r );
	$rows  = array(
		array( 'label' => __( 'Rating', 'minn-admin' ), 'value' => $item['rating'] ),
		array( 'label' => __( 'Status', 'minn-admin' ), 'value' => $item['status'] ),
		array( 'label' => __( 'Reviewed', 'minn-admin' ), 'value' => '' !== $title ? $title : '—', 'type' => $r->post_id ? 'link' : '', 'href' => $r->post_id ? get_permalink( (int) $r->post_id ) : '' ),
		array( 'label' => __( 'Source', 'minn-admin' ), 'value' => (string) $r->source ),
		array( 'label' => __( 'Type', 'minn-admin' ), 'value' => (string) $r->type_slug ),
		array( 'label' => __( 'Likes / dislikes', 'minn-admin' ), 'value' => (int) $r->likes . ' / ' . (int) $r->dislikes ),
		array( 'label' => __( 'Date', 'minn-admin' ), 'value' => $r->date ? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $r->date ) ) : '—' ),
	);
	// Per-question scores, in the shape their submit endpoint stores:
	// maybe_serialize() of [ { field_label, field_value, field_step,
	// field_max } ], read through decode_serialized (arrays and
	// scalars only).
	$breakdown = Minn_Admin::decode_serialized( $r->rating_data, null );
	if ( is_array( $breakdown ) ) {
		foreach ( $breakdown as $f ) {
			if ( ! is_array( $f ) || ! isset( $f['field_label'], $f['field_value'] ) || ! is_scalar( $f['field_label'] ) || ! is_numeric( $f['field_value'] ) ) {
				continue;
			}
			$max    = isset( $f['field_max'] ) && is_numeric( $f['field_max'] ) ? 0 + $f['field_max'] : 0;
			$rows[] = array(
				'label' => Minn_Admin::plain_text( $f['field_label'] ),
				'value' => $max > 0 ? ( 0 + $f['field_value'] ) . '/' . $max : (string) ( 0 + $f['field_value'] ),
			);
		}
	}
	// Title and body are the visitor's, stored HTML-encoded: through
	// wp_kses on submit, esc_html with <br> line breaks on edit.
	// Plain text, line breaks kept; the client escapes it.
	$title_text = Minn_Admin::plain_text( $r->title );
	$body       = Minn_Admin::plain_text( preg_replace( '/<br\s*\/?>/i', "\n", (string) $r->content ) );
	return array(
		'kind'     => 'entry',
		'status'   => $item['status'],
		'title'    => '' !== $title_text ? $title_text : __( 'Review', 'minn-admin' ),
		'sections' => array(
			array( 'title' => __( 'Reviewer', 'minn-admin' ), 'rows' => array(
				array( 'label' => __( 'Name', 'minn-admin' ), 'value' => $a['name'] . ( $a['guest'] ? ' (' . __( 'guest', 'minn-admin' ) . ')' : '' ) ),
				array( 'label' => __( 'Email', 'minn-admin' ), 'value' => '' !== $a['mail'] ? $a['mail'] : '—', 'type' => 'email' ),
				array( 'label' => __( 'Message', 'minn-admin' ), 'value' => $body ),
			) ),
			array( 'title' => __( 'Review', 'minn-admin' ), 'rows' => $rows ),
		),
		'adminUrl' => minn_admin_jet_reviews_admin_url(),
	);
}

/**
 * A search's extra matches: reviews of posts whose title holds the term, and
 * by users or guests whose name or email holds it (at most 500 of each).
 *
 * @return array|null Conditions, or null when nothing else matches.
 */
function minn_admin_jet_reviews_search_ids( $term ) {
	$like    = array( 'contains' => (string) $term );
	$posts   = array_map( 'intval', array_column( Minn_Admin_Kernel_SQL::select( array( 'core' => 'posts' ), array( 'ID' ), array( 'post_title' => $like ), array(), 500 ), 'ID' ) );
	$authors = array_map( 'strval', array_column( Minn_Admin_Kernel_SQL::select( array( 'core' => 'users' ), array( 'ID' ), array( '#any' => array( array( 'display_name' => $like ), array( 'user_email' => $like ) ) ), array(), 500 ), 'ID' ) );
	$guests  = minn_admin_jet_reviews_spec( 'review_guests' );
	if ( Minn_Admin_Kernel_SQL::exists( $guests ) ) {
		$authors = array_merge( $authors, array_map( 'strval', array_column( Minn_Admin_Kernel_SQL::select( $guests, array( 'guest_id' ), array( '#any' => array( array( 'name' => $like ), array( 'mail' => $like ) ) ), array(), 500 ), 'guest_id' ) ) );
	}
	$any = array();
	if ( $posts ) {
		$any[] = array( 'post_id' => $posts );
	}
	if ( $authors ) {
		$any[] = array( 'author' => array_values( array_unique( $authors ) ) );
	}
	return $any ? array( '#any' => $any ) : null;
}

minn_admin_resource( 'jet-reviews/reviews', function () {
	return array(
		'active'  => 'minn_admin_jet_reviews_active',
		'gates'   => array( 'view' => 'minn_admin_jet_reviews_can' ),
		'labels'  => array( 'not_found' => __( 'Review not found', 'minn-admin' ) ),
		'source'  => array(
			'table'   => minn_admin_jet_reviews_spec(),
			'key'     => 'id',
			'columns' => array( 'id', 'post_id', 'author', 'date', 'rating', 'approved' ),
			// Every column, as their own reads take a review (the rating
			// sync reads source and post_type from it).
			'detail'  => array(),
		),
		'list'    => array(
			'facets' => array( array( 'param' => 'status', 'values' => array( 'pending' => array( 'approved' => 0 ), 'approved' => array( 'approved' => 1 ) ) ) ),
			'search' => array(
				'columns' => array( 'title', 'content' ),
				// The reviewed item's title and the reviewer, a user or a
				// guest, matched first and named by id, so no join rides
				// every read of the table.
				'also'    => 'minn_admin_jet_reviews_search_ids',
			),
			'order'  => array( 'column' => 'id', 'dir' => 'DESC' ),
			'row'    => function ( array $row ) {
				return minn_admin_jet_reviews_item( (object) $row );
			},
			'fields' => array(
				'id'       => 'int',
				'author'   => 'text',
				'item'     => 'text',
				'rating'   => 'text',
				'status'   => 'text',
				'date'     => 'text',
				'approved' => 'int',
			),
		),
		'item'    => array(
			'view'   => 'minn_admin_jet_reviews_view',
			'fields' => array( 'kind' => 'text', 'status' => 'text', 'title' => 'text', 'sections' => 'raw', 'adminUrl' => 'text' ),
		),
		'actions' => array(
			'approve' => array(
				'method' => 'POST',
				'gate'   => 'view',
				// Their toggle-review-approve endpoint: the same table update,
				// then the source sync.
				'run'    => function ( $id, array $row, WP_REST_Request $request ) {
					$on = filter_var( $request->get_param( 'approved' ), FILTER_VALIDATE_BOOLEAN ) ? 1 : 0;
					Minn_Admin_Kernel_SQL::update( minn_admin_jet_reviews_spec(), 'id', $id, array( 'approved' => $on ) );
					minn_admin_jet_reviews_sync( (object) $row );
					return $on;
				},
				'expect' => function ( $id, $fresh, $on ) {
					return is_array( $fresh ) && (int) $fresh['approved'] === (int) $on;
				},
				'fail'   => __( 'JetReviews could not update that review.', 'minn-admin' ),
				'reply'  => function ( $id, $fresh, $on ) {
					return array(
						'status'  => $on ? 'approved' : 'pending',
						'message' => $on ? __( 'Review approved.', 'minn-admin' ) : __( 'Review unapproved.', 'minn-admin' ),
					);
				},
			),
			'delete'  => array(
				'method' => 'DELETE',
				'gate'   => 'view',
				// Their delete-review endpoint, step for step.
				'run'    => function ( $id, array $row ) {
					jet_reviews()->user_manager->delete_user_approval_review( $id );
					$deleted = \Jet_Reviews\Reviews\Data::get_instance()->delete_review_by_id( $id );
					if ( class_exists( '\Jet_Reviews\Reviews\Media' ) ) {
						\Jet_Reviews\Reviews\Media::get_instance()->delete_media_by_review_id( $id );
					}
					minn_admin_jet_reviews_sync( (object) $row );
					return $deleted;
				},
				'expect' => 'gone',
				'fail'   => __( 'JetReviews could not delete that review.', 'minn-admin' ),
				'done'   => __( 'Review deleted.', 'minn-admin' ),
			),
		),
	);
} );

minn_admin_endpoint( 'jet-reviews/status', array(
	'active' => 'minn_admin_jet_reviews_active',
	'gate'   => 'minn_admin_jet_reviews_can',
	'run'    => function () {
		$open = array( array( 'label' => __( 'Open JetReviews ↗', 'minn-admin' ), 'href' => minn_admin_jet_reviews_admin_url() ) );
		if ( ! minn_admin_jet_reviews_has_table() ) {
			return array( 'rows' => array( array( 'label' => __( 'Reviews', 'minn-admin' ), 'value' => '—' ) ), 'actions' => $open );
		}
		$t       = minn_admin_jet_reviews_spec();
		$pending = Minn_Admin_Kernel_SQL::count( $t, array( 'approved' => 0 ) );
		$total   = Minn_Admin_Kernel_SQL::count( $t );
		// The average of the approved reviews that carry a rating.
		$rated   = array( 'approved' => 1, 'rating' => array( 'op' => '>', 'value' => 0 ) );
		$n       = Minn_Admin_Kernel_SQL::count( $t, $rated );
		$avg     = $n ? Minn_Admin_Kernel_SQL::sum( $t, 'rating', $rated ) / $n : 0;
		return array(
			'rows'    => array(
				array( 'label' => __( 'Pending', 'minn-admin' ), 'value' => number_format_i18n( $pending ) ),
				array( 'label' => __( 'Reviews', 'minn-admin' ), 'value' => number_format_i18n( $total ) ),
				array( 'label' => __( 'Average', 'minn-admin' ), 'value' => $avg ? number_format_i18n( (float) $avg / 20, 1 ) . '/5' : '—', 'hint' => __( 'approved reviews', 'minn-admin' ) ),
			),
			'actions' => $open,
		);
	},
) );
