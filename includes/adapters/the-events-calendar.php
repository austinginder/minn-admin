<?php
/**
 * Bundled adapter: The Events Calendar (700k).
 *
 * Events are a REST-exposed CPT (tribe_events), so Minn's Content list and
 * editor already carry them; this adapter adds the "Event details" editor
 * panel: start/end, all-day, venue, organizer, cost and website. Venue and
 * organizer are the first consumers of the async-suggest panel field
 * (type "suggest": the field's route is searched as the user types).
 *
 * Writes go through Tribe__Events__API::saveEventMeta with the exact
 * payload shape TEC's OWN REST endpoint builds (EventStartDate Y-m-d +
 * EventStartTime H:i:s, EventAllDay yes/no, venue.VenueID,
 * organizer.OrganizerID), so duration, UTC mirrors, timezone handling and
 * linked-post bookkeeping are all TEC's. HARD-WON: the events ORM
 * (tribe_events()) cannot update a bare draft that has no date meta yet —
 * its query joins on start-date meta — which is exactly the post Minn's
 * "+ New" creates; saveEventMeta handles that case, so it is the write
 * path here.
 *
 * Deliberate boundaries: single organizer only (an event carrying several
 * organizers locks the field and defers to TEC's screen), and recurrence,
 * tickets, timezone and venue/organizer CREATION stay in TEC.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_tec_active() {
	return class_exists( 'Tribe__Events__API' )
		&& class_exists( 'Tribe__Events__Main' )
		&& post_type_exists( 'tribe_events' );
}

/** 'Y-m-d H:i' for the panel from TEC's stored 'Y-m-d H:i:s', or ''. */
function minn_admin_tec_panel_datetime( $meta_value ) {
	$ts = $meta_value ? strtotime( (string) $meta_value ) : false;
	return $ts ? date( 'Y-m-d H:i', $ts ) : '';
}

/** { value, label } for a linked post id, or '' when unset/missing. */
function minn_admin_tec_linked_pick( $id ) {
	$id = (int) $id;
	if ( ! $id ) {
		return '';
	}
	$post = get_post( $id );
	if ( ! $post ) {
		return '';
	}
	$title = get_the_title( $post );
	return array(
		'value' => (string) $id,
		'label' => '' !== $title ? $title : ( '#' . $id ),
	);
}

/** Whether this event carries more than one organizer (locks the field). */
function minn_admin_tec_multi_organizer( $post_id ) {
	return count( (array) get_post_meta( (int) $post_id, '_EventOrganizerID' ) ) > 1;
}

add_filter( 'minn_admin_editor_panels', function ( $panels ) {
	if ( ! minn_admin_tec_active() ) {
		return $panels;
	}
	$panels['tec'] = array(
		'label'       => 'Event details',
		'sub'         => 'The Events Calendar',
		'cap'         => 'edit_posts',
		'fieldsRoute' => 'minn-admin/v1/tec/fields?post_id={id}&post_type={type}',
		'valuesKey'   => 'minn_tec',
		'writeKey'    => 'minn_tec',
	);
	return $panels;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_tec_active() ) {
		return;
	}

	register_rest_route( 'minn-admin/v1', '/tec/fields', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'args'                => array(
			'post_id'   => array( 'type' => 'integer', 'default' => 0 ),
			'post_type' => array( 'type' => 'string', 'default' => 'posts' ),
		),
		'callback'            => function ( WP_REST_Request $request ) {
			$rest_base = sanitize_key( $request['post_type'] );
			$post_id   = (int) $request['post_id'];
			$post_type = $post_id && get_post( $post_id ) ? get_post( $post_id )->post_type : $rest_base;
			if ( ! in_array( $post_type, array( 'tribe_events' ), true ) ) {
				return rest_ensure_response( array( 'groups' => array() ) );
			}
			if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
				return new WP_Error( 'rest_forbidden', 'You cannot edit this event.', array( 'status' => 403 ) );
			}
			$fields = array(
				array( 'name' => 'start', 'label' => 'Starts', 'type' => 'text', 'placeholder' => 'YYYY-MM-DD HH:MM' ),
				array( 'name' => 'end', 'label' => 'Ends', 'type' => 'text', 'placeholder' => 'YYYY-MM-DD HH:MM' ),
				array( 'name' => 'all_day', 'label' => 'All-day event', 'type' => 'true_false' ),
				array( 'name' => 'venue', 'label' => 'Venue', 'type' => 'suggest', 'route' => 'minn-admin/v1/tec/suggest?kind=venue', 'placeholder' => 'Search venues…' ),
			);
			$locked = 0;
			if ( $post_id && minn_admin_tec_multi_organizer( $post_id ) ) {
				// Several organizers: single-pick would silently drop the rest.
				$locked++;
			} else {
				$fields[] = array( 'name' => 'organizer', 'label' => 'Organizer', 'type' => 'suggest', 'route' => 'minn-admin/v1/tec/suggest?kind=organizer', 'placeholder' => 'Search organizers…' );
			}
			$fields[] = array( 'name' => 'cost', 'label' => 'Cost', 'type' => 'text', 'placeholder' => 'e.g. 25 or Free' );
			$fields[] = array( 'name' => 'website', 'label' => 'Event website', 'type' => 'url' );
			// Recurrence / tickets / timezone stay TEC's (plus the organizer
			// row when locked above).
			$locked += 1;
			return rest_ensure_response( array(
				'groups' => array(
					array( 'group' => 'Event details', 'fields' => $fields, 'locked' => $locked ),
				),
			) );
		},
	) );

	// Venue / organizer suggestions: titles only, newest first, q filters.
	register_rest_route( 'minn-admin/v1', '/tec/suggest', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'args'                => array(
			'kind' => array( 'type' => 'string', 'required' => true, 'enum' => array( 'venue', 'organizer' ) ),
			'q'    => array( 'type' => 'string', 'default' => '' ),
		),
		'callback'            => function ( WP_REST_Request $request ) {
			$type  = 'venue' === $request['kind'] ? 'tribe_venue' : 'tribe_organizer';
			$query = array(
				'post_type'      => $type,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 20,
				'orderby'        => 'title',
				'order'          => 'ASC',
			);
			$q = trim( (string) $request['q'] );
			if ( '' !== $q ) {
				$query['s'] = $q;
			}
			$rows = array();
			foreach ( get_posts( $query ) as $p ) {
				$rows[] = array(
					'value' => (string) $p->ID,
					'label' => '' !== $p->post_title ? $p->post_title : ( '#' . $p->ID ),
				);
			}
			return rest_ensure_response( $rows );
		},
	) );

	register_rest_field(
		'tribe_events',
		'minn_tec',
		array(
			'get_callback'    => function ( $obj ) {
				$id = isset( $obj['id'] ) ? (int) $obj['id'] : 0;
				if ( ! $id || ! current_user_can( 'edit_post', $id ) ) {
					return new stdClass();
				}
				$values = array(
					'start'   => minn_admin_tec_panel_datetime( get_post_meta( $id, '_EventStartDate', true ) ),
					'end'     => minn_admin_tec_panel_datetime( get_post_meta( $id, '_EventEndDate', true ) ),
					'all_day' => 'yes' === get_post_meta( $id, '_EventAllDay', true ),
					'venue'   => minn_admin_tec_linked_pick( get_post_meta( $id, '_EventVenueID', true ) ),
					'cost'    => (string) get_post_meta( $id, '_EventCost', true ),
					'website' => (string) get_post_meta( $id, '_EventURL', true ),
				);
				if ( ! minn_admin_tec_multi_organizer( $id ) ) {
					$values['organizer'] = minn_admin_tec_linked_pick( get_post_meta( $id, '_EventOrganizerID', true ) );
				}
				return (object) $values;
			},
			'update_callback' => function ( $value, $post ) {
				if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
					return null;
				}
				if ( is_object( $value ) ) {
					$value = (array) $value;
				}
				if ( ! is_array( $value ) ) {
					return null;
				}
				// Suggest fields arrive as { value, label } (untouched) or as
				// the picked '' / { value, label } — normalize to the id.
				$linked_id = function ( $v ) {
					if ( is_array( $v ) && isset( $v['value'] ) ) {
						return (int) $v['value'];
					}
					if ( is_object( $v ) && isset( $v->value ) ) {
						return (int) $v->value;
					}
					return is_scalar( $v ) && '' !== (string) $v ? (int) $v : 0;
				};
				$data = array();
				foreach ( array( 'start' => 'Start', 'end' => 'End' ) as $key => $side ) {
					if ( ! array_key_exists( $key, $value ) ) {
						continue;
					}
					$raw = trim( (string) $value[ $key ] );
					if ( '' === $raw ) {
						continue; // dates can't be unset; TEC events always have them
					}
					$ts = strtotime( $raw );
					if ( ! $ts ) {
						return new WP_Error( 'minn_tec_bad_date', sprintf( 'Could not read the %s date — use YYYY-MM-DD HH:MM.', strtolower( $side ) ), array( 'status' => 400 ) );
					}
					// The exact shape TEC's own REST endpoint sends.
					$data[ "Event{$side}Date" ] = date( 'Y-m-d', $ts );
					$data[ "Event{$side}Time" ] = date( 'H:i:s', $ts );
				}
				if ( array_key_exists( 'all_day', $value ) ) {
					$data['EventAllDay'] = ( ! empty( $value['all_day'] ) && 'false' !== (string) $value['all_day'] ) ? 'yes' : 'no';
				}
				if ( array_key_exists( 'venue', $value ) ) {
					$data['venue'] = array( 'VenueID' => $linked_id( $value['venue'] ) );
				}
				if ( array_key_exists( 'organizer', $value ) && ! minn_admin_tec_multi_organizer( $post->ID ) ) {
					$oid = $linked_id( $value['organizer'] );
					$data['organizer'] = array( 'OrganizerID' => $oid ? array( $oid ) : array() );
				}
				if ( array_key_exists( 'cost', $value ) ) {
					$data['EventCost'] = sanitize_text_field( (string) $value['cost'] );
				}
				if ( array_key_exists( 'website', $value ) ) {
					$data['EventURL'] = esc_url_raw( (string) $value['website'] );
				}
				if ( ! $data ) {
					return null;
				}
				try {
					$ok = Tribe__Events__API::saveEventMeta( $post->ID, $data );
				} catch ( \Throwable $e ) {
					return new WP_Error( 'minn_tec_save_failed', $e->getMessage(), array( 'status' => 500 ) );
				}
				if ( false === $ok ) {
					// Their invalid-meta path (e.g. end before start).
					return new WP_Error( 'minn_tec_refused', 'The Events Calendar refused those event details (check that the end is after the start).', array( 'status' => 400 ) );
				}
				return null;
			},
			'schema'          => array(
				'description' => 'The Events Calendar event details for Minn Admin.',
				'type'        => 'object',
				'context'     => array( 'edit' ),
			),
		)
	);
} );
