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

/**
 * 'Y-m-d H:i' for the panel from TEC's stored 'Y-m-d H:i:s', or ''. A wall
 * time, so read and printed on one clock: a time in a default zone's DST
 * gap moved.
 */
function minn_admin_tec_panel_datetime( $meta_value ) {
	$d = $meta_value ? date_create_immutable( (string) $meta_value, new DateTimeZone( 'UTC' ) ) : false;
	return $d ? $d->format( 'Y-m-d H:i' ) : '';
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
	// The picker withholds other people's drafts and applies a per-row read
	// check; reading a linked id back skipped both, so an id typed by hand
	// answered with a title the picker would not have offered. Same boundary
	// on the way out as on the way in.
	if ( ! current_user_can( 'read_post', $post->ID ) ) {
		return '';
	}
	$title = get_the_title( $post );
	return array(
		'value' => (string) $id,
		'label' => '' !== $title ? $title : ( '#' . $id ),
	);
}

/**
 * Whether Events Calendar Pro treats this as a recurring event. Its custom
 * tables save hook (Updates\Events::save_recurrence_meta) deletes the
 * recurrence rules whenever saveEventMeta() runs without them, so a panel
 * save would collapse the series; recurring events are edited in TEC.
 */
function minn_admin_tec_is_recurring( $post_id ) {
	if ( ! class_exists( 'Tribe__Events__Pro__Main' ) ) {
		return false;
	}
	$rules = get_post_meta( (int) $post_id, '_EventRecurrence', true );
	if ( is_array( $rules ) && ! empty( $rules['rules'] ) ) {
		return true;
	}
	return function_exists( 'tribe_is_recurring_event' ) && (bool) tribe_is_recurring_event( (int) $post_id );
}

/** Whether this event carries more than one organizer (locks the field). */
function minn_admin_tec_multi_organizer( $post_id ) {
	return count( (array) get_post_meta( (int) $post_id, '_EventOrganizerID' ) ) > 1;
}

/**
 * The event panel's values as stored, the shape the editor reads and sends.
 *
 * @param int $id Event id.
 * @return array
 */
function minn_admin_tec_panel_values( $id ) {
	$values = array(
		'start'   => minn_admin_tec_panel_datetime( get_post_meta( $id, '_EventStartDate', true ) ),
		'end'     => minn_admin_tec_panel_datetime( get_post_meta( $id, '_EventEndDate', true ) ),
		// Their own reader: TEC now stores the flag as '1' (older
		// events carry 'yes'), so a string compare misread every
		// all-day event as timed.
		'all_day' => function_exists( 'tribe_event_is_all_day' )
			? (bool) tribe_event_is_all_day( $id )
			: in_array( (string) get_post_meta( $id, '_EventAllDay', true ), array( 'yes', '1', 'true' ), true ),
		'cost'    => (string) get_post_meta( $id, '_EventCost', true ),
		'website' => (string) get_post_meta( $id, '_EventURL', true ),
	);
	if ( ! minn_admin_tec_multi_venue( $id ) ) {
		$values['venue'] = minn_admin_tec_linked_pick( get_post_meta( $id, '_EventVenueID', true ) );
	}
	if ( ! minn_admin_tec_multi_organizer( $id ) ) {
		$values['organizer'] = minn_admin_tec_linked_pick( get_post_meta( $id, '_EventOrganizerID', true ) );
	}
	return $values;
}

/**
 * Whether this event carries more than one venue (locks the field). Events
 * Calendar Pro links several; TEC links exactly the venues a save submits and
 * unlinks the rest, so a single pick would drop all but the first.
 */
function minn_admin_tec_multi_venue( $post_id ) {
	return count( (array) get_post_meta( (int) $post_id, '_EventVenueID' ) ) > 1;
}

/**
 * The Event details group for an event (fields tier, minn_admin_fields()).
 *
 * @param int $post_id Event id, 0 for one not saved yet.
 * @return array[]
 */
function minn_admin_tec_groups( $post_id ) {
	if ( $post_id && minn_admin_tec_is_recurring( $post_id ) ) {
		// Every field is TEC's on a recurring event (see the helper).
		return array(
			array( 'group' => __( 'Event details', 'minn-admin' ), 'fields' => array(), 'locked' => 8 ),
		);
	}
	// Dates are typed by hand in TEC's shape and cannot be emptied: an event
	// always has both.
	$date = function ( $name, $label, $side ) {
		return array(
			'name'        => $name,
			'label'       => $label,
			'type'        => 'datetime',
			'input'       => 'text',
			'required'    => true,
			'placeholder' => __( 'YYYY-MM-DD HH:MM', 'minn-admin' ),
			/* translators: %s: which date could not be parsed, "start" or "end". */
			'refuse'      => sprintf( __( 'Could not read the %s date — use YYYY-MM-DD HH:MM.', 'minn-admin' ), $side ),
		);
	};
	$fields = array(
		$date( 'start', __( 'Starts', 'minn-admin' ), 'start' ),
		$date( 'end', __( 'Ends', 'minn-admin' ), 'end' ),
		array( 'name' => 'all_day', 'label' => __( 'All-day event', 'minn-admin' ), 'type' => 'checkbox' ),
	);
	$locked = 0;
	if ( $post_id && minn_admin_tec_multi_venue( $post_id ) ) {
		// Several venues: single-pick would silently drop the rest.
		$locked++;
	} else {
		$fields[] = array( 'name' => 'venue', 'label' => __( 'Venue', 'minn-admin' ), 'type' => 'suggest', 'post_type' => 'tribe_venue', 'route' => 'minn-admin/v1/tec/suggest?kind=venue', 'placeholder' => __( 'Search venues…', 'minn-admin' ), 'refuse' => __( 'Pick a venue from the list.', 'minn-admin' ) );
	}
	if ( $post_id && minn_admin_tec_multi_organizer( $post_id ) ) {
		// Several organizers: single-pick would silently drop the rest.
		$locked++;
	} else {
		$fields[] = array( 'name' => 'organizer', 'label' => __( 'Organizer', 'minn-admin' ), 'type' => 'suggest', 'post_type' => 'tribe_organizer', 'route' => 'minn-admin/v1/tec/suggest?kind=organizer', 'placeholder' => __( 'Search organizers…', 'minn-admin' ), 'refuse' => __( 'Pick an organizer from the list.', 'minn-admin' ) );
	}
	$fields[] = array( 'name' => 'cost', 'label' => __( 'Cost', 'minn-admin' ), 'type' => 'text', 'placeholder' => __( 'e.g. 25 or Free', 'minn-admin' ) );
	$fields[] = array( 'name' => 'website', 'label' => __( 'Event website', 'minn-admin' ), 'type' => 'url' );
	// Recurrence / tickets / timezone stay TEC's (plus the organizer row
	// when locked above).
	return array(
		array( 'group' => __( 'Event details', 'minn-admin' ), 'fields' => $fields, 'locked' => $locked + 1 ),
	);
}

/**
 * Store the panel's changes through TEC's own save.
 *
 * @param int   $post_id Event id.
 * @param array $changes Field => cleaned value (null clears): dates as
 *                       'Y-m-d H:i:s', the flag a boolean, picks an id.
 * @return WP_Error|null What TEC refused, which is the whole submission.
 */
function minn_admin_tec_store( $post_id, $changes ) {
	$post   = get_post( $post_id );
	$served = minn_admin_tec_panel_values( $post_id );
	$data   = array();
	// Wall times, read on the clock they are written on (UTC, as the tier
	// cleans them): parsed in PHP's default zone and printed back, a value
	// moved by the zone's offset wherever that is not UTC.
	$utc = new DateTimeZone( 'UTC' );
	foreach ( array( 'start' => 'Start', 'end' => 'End' ) as $key => $side ) {
		$d = isset( $changes[ $key ] ) ? date_create_immutable( (string) $changes[ $key ], $utc ) : false;
		if ( $d ) {
			// The exact shape TEC's own REST endpoint sends.
			$data[ "Event{$side}Date" ] = $d->format( 'Y-m-d' );
			$data[ "Event{$side}Time" ] = $d->format( 'H:i:s' );
		}
	}
	// TEC takes new times only as a pair (both or it keeps the stored ones),
	// so a changed side goes with the other as stored, to the second.
	foreach ( array( 'Start' => 'End', 'End' => 'Start' ) as $side => $other ) {
		if ( isset( $data[ "Event{$other}Date" ] ) && ! isset( $data[ "Event{$side}Date" ] ) ) {
			$d = date_create_immutable( (string) get_post_meta( $post_id, "_Event{$side}Date", true ), $utc );
			if ( $d ) {
				$data[ "Event{$side}Date" ] = $d->format( 'Y-m-d' );
				$data[ "Event{$side}Time" ] = $d->format( 'H:i:s' );
			}
		}
	}
	// saveEventMeta() is a full-form save: an absent all-day flag reads as
	// off and an absent cost is deleted, so those go back as the event holds
	// them when the panel did not change them. A venue or organizer left out
	// keeps its links (the submission names the event, below).
	$all_day             = array_key_exists( 'all_day', $changes ) ? $changes['all_day'] : ! empty( $served['all_day'] );
	$data['EventAllDay'] = $all_day ? 'yes' : 'no';
	if ( array_key_exists( 'venue', $changes ) ) {
		$data['venue'] = array( 'VenueID' => (int) $changes['venue'] );
	}
	if ( array_key_exists( 'organizer', $changes ) ) {
		$oid               = (int) $changes['organizer'];
		$data['organizer'] = array( 'OrganizerID' => $oid ? array( $oid ) : array() );
	}
	// saveEventMeta() always rewrites the cost (an absent one is deleted) and
	// stores through add_post_meta(), which unslashes, as its own form's
	// slashed $_POST expects. It also runs every string it stores through
	// tec_sanitize_string() (entity decode, then strip_tags), so a value
	// passed back as stored still came out changed: a cost the block editor
	// stored as 'Under &lt;$20' became 'Under '. An untouched cost has to be
	// sent, so it is put back as stored once TEC is done (below). An edited
	// one is slashed or a backslash would be lost. A website nobody touched
	// is left out (an absent one is kept).
	$stored_cost = (string) get_post_meta( $post_id, '_EventCost', true );
	$cost_was    = null;
	if ( array_key_exists( 'cost', $changes ) ) {
		$data['EventCost'] = wp_slash( (string) $changes['cost'] );
	} else {
		$data['EventCost'] = wp_slash( $stored_cost );
		if ( 1 === count( get_post_meta( $post_id, '_EventCost' ) ) ) {
			$cost_was = $stored_cost;
		}
	}
	if ( array_key_exists( 'website', $changes ) ) {
		$data['EventURL'] = wp_slash( (string) $changes['website'] );
	}

	// TEC treats a linked type MISSING from the submission as "keep the
	// existing links" only when the submission names the event
	// (Linked_Posts::get_linked_post_type_data reads $submission['ID']);
	// without it a missing venue or organizer reads as "link none" and the
	// stored one is dropped. Its own REST endpoint sends the id too.
	$data['ID'] = $post_id;

	// saveEventMeta() is TEC's FULL-form save: a key its form always posts
	// reads as "off" when absent. The panel draws none of these, so carry the
	// stored state or an ordinary save unfeatures the event, un-hides it from
	// listings, unpins it from the month view, switches its map off and moves
	// it to the site timezone (shifting its UTC instant).
	$hide = get_post_meta( $post_id, '_EventHideFromUpcoming', true );
	if ( ! empty( $hide ) ) {
		$data['EventHideFromUpcoming'] = $hide;
	}
	$tz = (string) get_post_meta( $post_id, '_EventTimezone', true );
	if ( '' !== $tz ) {
		$data['EventTimezone'] = $tz;
	}
	if ( '-1' === (string) $post->menu_order ) {
		$data['EventShowInCalendar'] = 'yes';
	}
	try {
		if ( tribe( 'tec.featured_events' )->is_featured( $post_id ) ) {
			$data['feature_event'] = 'yes';
		}
	} catch ( \Throwable $e ) {
		// No featured-events service: nothing to carry.
		unset( $e );
	}
	// The map flags ride inside the venue submission, and a venue submission
	// without an id makes TEC create a new venue. Carry them only alongside a
	// real VenueID; otherwise put the stored values back after TEC has reset
	// them.
	$map_keys = array( 'EventShowMap' => '_EventShowMap', 'EventShowMapLink' => '_EventShowMapLink' );
	$map_was  = array();
	foreach ( $map_keys as $field => $meta_key ) {
		if ( metadata_exists( 'post', $post_id, $meta_key ) ) {
			$map_was[ $meta_key ] = get_post_meta( $post_id, $meta_key, true );
		}
	}
	$venue_sent = ! empty( $data['venue']['VenueID'] );
	if ( $venue_sent ) {
		foreach ( $map_keys as $field => $meta_key ) {
			$v = isset( $map_was[ $meta_key ] ) ? $map_was[ $meta_key ] : '';
			if ( ! empty( $v ) && 'false' !== (string) $v ) {
				$data['venue'][ $field ] = '1';
			}
		}
	}

	try {
		// Pass the post: TEC's own save and its listeners (Community Events
		// types the third argument) expect it.
		$ok = Tribe__Events__API::saveEventMeta( $post_id, $data, $post );
	} finally {
		// TEC resets the map flags early in the save, so put them back even
		// when a later listener throws.
		if ( ! $venue_sent ) {
			foreach ( $map_was as $meta_key => $v ) {
				update_post_meta( $post_id, $meta_key, $v );
			}
		}
		// One stored cost row, as it was (a ticketing add-on that writes
		// several price rows is left to its own rows).
		$now = null === $cost_was ? array() : get_post_meta( $post_id, '_EventCost' );
		if ( 1 === count( $now ) && (string) $now[0] !== $cost_was ) {
			update_post_meta( $post_id, '_EventCost', wp_slash( $cost_was ) );
		}
	}
	if ( false === $ok ) {
		// Their invalid-meta path (e.g. end before start).
		return new WP_Error( 'minn_tec_refused', __( 'The Events Calendar refused those event details (check that the end is after the start).', 'minn-admin' ), array( 'status' => 400 ) );
	}
	return null;
}

minn_admin_fields( 'tec', function () {
	return array(
		'active'      => 'minn_admin_tec_active',
		'label'       => __( 'Event details', 'minn-admin' ),
		'sub'         => 'The Events Calendar',
		'key'         => 'minn_tec',
		'types'       => array( 'tribe_events' ),
		'groups'      => 'minn_admin_tec_groups',
		'read'        => 'minn_admin_tec_panel_values',
		'write'       => 'minn_admin_tec_store',
		'description' => __( 'The Events Calendar event details for Minn Admin.', 'minn-admin' ),
	);
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_tec_active() ) {
		return;
	}

	// Venue / organizer suggestions: published records plus drafts the caller
	// may edit, alphabetical, with q filtering.
	minn_admin_endpoint( '/tec/suggest', array(
		'methods'             => 'GET',
		'gate'                => function () {
			return current_user_can( 'edit_posts' );
		},
		'args'                => array(
			'kind' => array( 'type' => 'string', 'required' => true, 'enum' => array( 'venue', 'organizer' ) ),
			'q'    => array( 'type' => 'string' ),
		),
		'run'                 => function ( WP_REST_Request $request ) {
			$type = 'venue' === $request['kind'] ? 'tribe_venue' : 'tribe_organizer';
			$base = array(
				'post_type'      => $type,
				'posts_per_page' => 20,
				'orderby'        => 'title',
				'order'          => 'ASC',
			);
			$q = trim( (string) $request['q'] );
			if ( '' !== $q ) {
				$base['s'] = $q;
			}

			// Published venues/organizers are shared choices. Drafts are not:
			// mirror wp-admin's ownership boundary for users who cannot edit
			// other people's records instead of returning every draft title/id.
			$posts       = get_posts( array_merge( $base, array( 'post_status' => 'publish' ) ) );
			$draft_query = array_merge( $base, array( 'post_status' => 'draft' ) );
			$post_type   = get_post_type_object( $type );
			if ( ! $post_type || ! current_user_can( $post_type->cap->edit_others_posts ) ) {
				$draft_query['author'] = get_current_user_id();
			}
			$posts = array_merge( $posts, get_posts( $draft_query ) );
			usort( $posts, function ( $a, $b ) {
				return strcasecmp( (string) $a->post_title, (string) $b->post_title );
			} );

			$rows = array();
			foreach ( array_slice( $posts, 0, 20 ) as $p ) {
				// Respect object-level capability filters as a final boundary too.
				if ( ! current_user_can( 'read_post', $p->ID ) ) {
					continue;
				}
				$rows[] = array(
					'value' => (string) $p->ID,
					'label' => '' !== $p->post_title ? $p->post_title : ( '#' . $p->ID ),
				);
			}
			return rest_ensure_response( $rows );
		},
	) );

} );
