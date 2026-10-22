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
 * Each field is plain postmeta under its own key, which the fields tier
 * reads and writes itself (flags as WPJM's 1/0), after each field's own
 * declared sanitize_callback (WPJM ships them: application accepts email OR
 * url, dates validate to Y-m-d). Their admin save handler is nonce-gated,
 * so Minn's REST saves are never clobbered.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

// WP Job Manager's reCAPTCHA secret.
minn_admin_secrets( 'wp-job-manager', array( 'options' => array( 'job_manager_recaptcha_secret_key' ) ) );

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
		// A field the site keeps out of the admin (show_in_admin: salary and
		// remote position can be switched off) is left out, as their
		// writepanel leaves it out.
		$shown = isset( $f['show_in_admin'] ) ? $f['show_in_admin'] : true;
		try {
			$shown = is_callable( $shown ) ? (bool) call_user_func( $shown, true, $key, (int) $post_id, get_current_user_id() ) : (bool) $shown;
		} catch ( \Throwable $e ) {
			$shown = false;
		}
		if ( ! $shown || ! minn_admin_wpjm_can_view_field( $f, $key, $post_id ) || ! minn_admin_wpjm_can_edit_field( $f, $key, $post_id ) ) {
			continue;
		}
		$type  = isset( $f['auth_edit_field_type'] ) ? (string) $f['auth_edit_field_type'] : ( isset( $f['type'] ) ? (string) $f['type'] : 'text' );
		$label = isset( $f['label'] ) ? wp_strip_all_tags( (string) $f['label'] ) : $key;
		$out   = array(
			'name'  => $key,
			'label' => $label,
			'meta'  => $key,
			// Their edit callback, already asked above, is the key's gate: a
			// field an add-on registers after init has no core meta auth,
			// and their writepanel saves it on this callback alone.
			'gate'  => function ( $id ) use ( $f, $key ) {
				return minn_admin_wpjm_can_edit_field( $f, $key, $id );
			},
		);
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
				$out['type']  = 'checkbox';
				$out['store'] = 'int';
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
		// Their own field sanitizer has the last word (a value it empties is
		// refused, not stored as a clear).
		if ( 'checkbox' !== $out['type'] && isset( $f['sanitize_callback'] ) && is_callable( $f['sanitize_callback'] ) ) {
			$out['sanitize'] = $f['sanitize_callback'];
		}
		$fields[] = $out;
	}
	return array(
		array( 'group' => __( 'Listing details', 'minn-admin' ), 'fields' => $fields, 'locked' => $locked ),
	);
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
		'description' => __( 'WP Job Manager listing fields for Minn Admin.', 'minn-admin' ),
	);
} );
