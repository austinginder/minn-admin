<?php
/**
 * Bundled adapter: WP Job Manager (80k).
 *
 * Job listings are a REST-exposed CPT (job_listing, rest_base
 * job-listings), so Minn's Content list and editor already carry them; this
 * adapter adds the "Job listing" editor panel with the whole details
 * estate — location, company fields, application email/URL, salary, the
 * remote/filled/featured flags and the expiry date — read at request time
 * from WPJM's OWN schema (WP_Job_Manager_Post_Types::get_job_listing_fields(),
 * which is already filtered through job_manager_job_listing_data_fields, so
 * site and add-on customizations track live).
 *
 * Writes prefer each field's own declared sanitize_callback (WPJM ships
 * them: application accepts email OR url, dates validate to Y-m-d),
 * falling back per data_type. Their admin save handler is nonce-gated, so
 * Minn's REST saves are never clobbered.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_wpjm_active() {
	return class_exists( 'WP_Job_Manager_Post_Types' )
		&& method_exists( 'WP_Job_Manager_Post_Types', 'get_job_listing_fields' )
		&& post_type_exists( 'job_listing' );
}

/** WPJM's live field schema (already site-filtered), or array(). */
function minn_admin_wpjm_raw_fields() {
	try {
		return (array) WP_Job_Manager_Post_Types::get_job_listing_fields();
	} catch ( \Throwable $e ) {
		return array();
	}
}

/**
 * WPJM's own per-field VIEW authorization, asked once and the same way
 * everywhere.
 *
 * Their schema attaches auth_view_callback beside auth_edit_callback, and a
 * site using their view-capability setting hides an applicant's address and
 * the salary behind it. Reading was taught to ask; the field LIST was not, so
 * the panel still advertised the field with an empty value and an ordinary
 * save wrote that empty value back over what it had just refused to show.
 *
 * @param array  $f       Field schema.
 * @param string $key     Meta key.
 * @param int    $post_id Listing id, 0 when asking about the type.
 * @return bool
 */
function minn_admin_wpjm_can_view_field( $f, $key, $post_id = 0 ) {
	if ( ! isset( $f['auth_view_callback'] ) || ! is_callable( $f['auth_view_callback'] ) ) {
		return true;
	}
	try {
		return (bool) call_user_func( $f['auth_view_callback'], false, $key, (int) $post_id, get_current_user_id() );
	} catch ( \Throwable $e ) {
		return false;
	}
}

/**
 * WPJM's own per-field EDIT authorization, the other callback their schema
 * attaches.
 *
 * Their metabox leaves out any field this callback refuses (Featured and
 * Listing Expiry ask manage_job_listings, because both are sold), so a
 * listing editor without it never sees either. The panel draws what that box
 * draws: a control the save can only refuse reads as saved while nothing
 * changed.
 *
 * @param array  $f       Field schema.
 * @param string $key     Meta key.
 * @param int    $post_id Listing id, 0 when asking about the type.
 * @return bool
 */
function minn_admin_wpjm_can_edit_field( $f, $key, $post_id = 0 ) {
	if ( ! isset( $f['auth_edit_callback'] ) || ! is_callable( $f['auth_edit_callback'] ) ) {
		return true;
	}
	try {
		return (bool) call_user_func( $f['auth_edit_callback'], false, $key, (int) $post_id, get_current_user_id() );
	} catch ( \Throwable $e ) {
		return false;
	}
}

/**
 * The Listing details group for a listing (fields tier, minn_admin_fields()):
 * the fields WPJM's own metabox would draw for this user.
 *
 * @param int $post_id Listing id, 0 for one not saved yet.
 * @return array[]
 */
function minn_admin_wpjm_groups( $post_id = 0 ) {
	$fields = array();
	$locked = 0;
	foreach ( minn_admin_wpjm_raw_fields() as $key => $f ) {
		if ( ! minn_admin_wpjm_can_view_field( $f, $key, $post_id ) || ! minn_admin_wpjm_can_edit_field( $f, $key, $post_id ) ) {
			continue;
		}
		$type  = isset( $f['auth_edit_field_type'] ) ? (string) $f['auth_edit_field_type'] : ( isset( $f['type'] ) ? (string) $f['type'] : 'text' );
		$label = isset( $f['label'] ) ? wp_strip_all_tags( (string) $f['label'] ) : $key;
		$out   = array( 'name' => $key, 'label' => $label );
		if ( ! empty( $f['placeholder'] ) && is_string( $f['placeholder'] ) ) {
			$out['placeholder'] = $f['placeholder'];
		}
		switch ( $type ) {
			case 'text':
				$out['type'] = 'text';
				break;
			case 'file': // company video: a URL with an uploader in wp-admin
				$out['type'] = 'url';
				break;
			case 'checkbox':
				$out['type'] = 'checkbox';
				break;
			case 'select':
				$options = isset( $f['options'] ) && is_array( $f['options'] ) ? $f['options'] : array();
				if ( ! $options ) {
					$locked++;
					continue 2;
				}
				$out['type']     = 'select';
				$out['choices']  = $options;
				// WPJM's own control always holds one of its choices.
				$out['required'] = ! array_key_exists( '', $options );
				break;
			default:
				$locked++;
				continue 2;
		}
		$fields[] = $out;
	}
	return array(
		array( 'group' => __( 'Listing details', 'minn-admin' ), 'fields' => $fields, 'locked' => $locked ),
	);
}

/** Read panel values: { key => value } (checkboxes as bool). */
function minn_admin_wpjm_read_values( $post_id ) {
	$out = array();
	foreach ( minn_admin_wpjm_raw_fields() as $key => $f ) {
		$type = isset( $f['auth_edit_field_type'] ) ? (string) $f['auth_edit_field_type'] : ( isset( $f['type'] ) ? (string) $f['type'] : 'text' );
		if ( ! in_array( $type, array( 'text', 'file', 'checkbox', 'select' ), true ) ) {
			continue;
		}
		$val         = get_post_meta( $post_id, $key, true );
		$out[ $key ] = 'checkbox' === $type ? ( (int) $val > 0 ) : ( is_scalar( $val ) ? (string) $val : '' );
	}
	return $out;
}

/**
 * Store cleaned values through WPJM's own per-field sanitizers.
 *
 * @param int   $post_id Listing id.
 * @param array $changes Meta key => cleaned value, null to clear.
 * @param array $fields  The offered fields, by key.
 * @return array Meta key => why WPJM did not take it.
 */
function minn_admin_wpjm_store( $post_id, $changes, $fields ) {
	$schema  = minn_admin_wpjm_raw_fields();
	$refused = array();
	foreach ( $changes as $key => $value ) {
		$f = isset( $schema[ $key ] ) ? $schema[ $key ] : array();
		if ( 'checkbox' === $fields[ $key ]['type'] ) {
			// WPJM stores 1/0 integers (data_type integer).
			update_post_meta( $post_id, $key, $value ? 1 : 0 );
			continue;
		}
		$typed = null === $value ? '' : (string) $value;
		$value = $typed;
		// Their own field sanitizer wins when declared (application's
		// email-or-url rule, the expiry date's Y-m-d validation). A value it
		// takes away whole is refused, not stored as a clear.
		if ( isset( $f['sanitize_callback'] ) && is_callable( $f['sanitize_callback'] ) ) {
			try {
				$value = call_user_func( $f['sanitize_callback'], $typed, $key );
			} catch ( \Throwable $e ) {
				$refused[ $key ] = __( 'WP Job Manager did not accept this value.', 'minn-admin' );
				continue;
			}
			if ( '' !== $typed && ( null === $value || '' === $value || false === $value ) ) {
				$refused[ $key ] = __( 'WP Job Manager did not accept this value.', 'minn-admin' );
				continue;
			}
		}
		update_post_meta( $post_id, $key, wp_slash( $value ) ); // update_post_meta() unslashes
	}
	return $refused;
}

// Their per-field VIEW and EDIT callbacks decide what the panel offers
// (minn_admin_wpjm_groups()), so a field withheld from this user is neither
// read nor written: WPJM gates Featured and Listing Expiry behind
// manage_job_listings because both are sold (WooCommerce Paid Listings), and
// a third door has to ask the same question its own two do.
minn_admin_fields( 'wpjm', function () {
	return array(
		'active'      => 'minn_admin_wpjm_active',
		'label'       => __( 'Job listing', 'minn-admin' ),
		'sub'         => 'WP Job Manager',
		'key'         => 'minn_wpjm',
		'types'       => array( 'job_listing' ),
		'groups'      => 'minn_admin_wpjm_groups',
		'read'        => 'minn_admin_wpjm_read_values',
		'write'       => 'minn_admin_wpjm_store',
		'description' => __( 'WP Job Manager listing fields for Minn Admin.', 'minn-admin' ),
	);
} );
