<?php
/**
 * Bundled adapter: Seriously Simple Podcasting (Castos, wp.org free).
 *
 * SSP's episodes are a REST-exposed CPT (`podcast`, plus any extra types
 * enabled in its settings via ssp_post_types()), so Minn's Content list and
 * editor already carry them. This adapter adds the "Podcast episode" editor
 * panel: episode file URL, type, duration, explicit/block, the iTunes
 * fields — read at request time from SSP's OWN schema
 * (CPT_Podcast_Handler::custom_fields() through their service container), so
 * a field SSP adds or a site disables (iTunes fields off) tracks live.
 *
 * Each field is plain postmeta under its own key, which the fields tier
 * reads and writes itself (checkboxes in SSP's 'on'/'' shape). SSP's own save_post handler bails without its metabox nonce,
 * so a Minn REST save never gets clobbered. Castos hosting sync (the hidden
 * podmotor and castos fields, sync_status) and the cover-image uploader stay
 * SSP's — cover image counts as locked with the wp-admin escape.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

// The Castos hosting API token (publishes and deletes episodes).
minn_admin_secrets( 'seriously-simple-podcasting', array( 'options' => array( 'ss_podcasting_podmotor_account_api_token' ) ) );

function minn_admin_ssp_active() {
	return defined( 'SSP_VERSION' )
		&& function_exists( 'ssp_app' )
		&& function_exists( 'ssp_post_types' );
}

/** SSP's live episode-field schema, or array() when unavailable. */
function minn_admin_ssp_raw_fields() {
	try {
		$handler = ssp_app()->get_service( 'cpt_podcast_handler' );
		if ( $handler && method_exists( $handler, 'custom_fields' ) ) {
			return (array) $handler->custom_fields();
		}
	} catch ( \Throwable $e ) {
		// Fall through — panel just renders empty.
	}
	return array();
}

/**
 * Map SSP's field schema onto the panel vocabulary (fields tier,
 * minn_admin_fields()).
 *
 * @return array[] The one Episode details group.
 */
function minn_admin_ssp_groups() {
	$fields = array();
	$locked = 0;
	foreach ( minn_admin_ssp_raw_fields() as $key => $f ) {
		$type = isset( $f['type'] ) ? (string) $f['type'] : '';
		// Internal plumbing (Castos ids, raw byte sizes) — not user fields.
		if ( in_array( $type, array( 'hidden', 'sync_status' ), true ) ) {
			continue;
		}
		$label = isset( $f['name'] ) ? rtrim( wp_strip_all_tags( (string) $f['name'] ), ': ' ) : $key;
		switch ( $type ) {
			case 'episode_file':
				$fields[] = array( 'name' => $key, 'label' => $label, 'type' => 'url', 'meta' => $key );
				break;
			case 'text':
			case 'datepicker': // stored as display text; keep their format
				$fields[] = array( 'name' => $key, 'label' => $label, 'type' => 'text', 'meta' => $key );
				break;
			case 'number':
				$fields[] = array( 'name' => $key, 'label' => $label, 'type' => 'number', 'integer' => true, 'min' => 0, 'meta' => $key );
				break;
			case 'checkbox':
				$fields[] = array( 'name' => $key, 'label' => $label, 'type' => 'checkbox', 'meta' => $key, 'store' => 'on' );
				break;
			case 'radio':
			case 'select':
				$options = isset( $f['options'] ) && is_array( $f['options'] ) ? $f['options'] : array();
				if ( ! $options ) {
					$locked++;
					break;
				}
				$fields[] = array(
					'name'     => $key,
					'label'    => $label,
					'type'     => 'select',
					'input'    => $type,
					'choices'  => $options,
					// SSP's own control always holds one of its choices.
					'required' => ! array_key_exists( '', $options ),
					'meta'     => $key,
				);
				break;
			default:
				// image (cover) and anything SSP adds later that Minn can't draw.
				$locked++;
		}
	}
	return array(
		array( 'group' => __( 'Episode details', 'minn-admin' ), 'fields' => $fields, 'locked' => $locked ),
	);
}

minn_admin_fields( 'ssp', function () {
	return array(
		'active'      => 'minn_admin_ssp_active',
		'label'       => __( 'Podcast episode', 'minn-admin' ),
		'sub'         => 'Seriously Simple Podcasting',
		'key'         => 'minn_ssp',
		// Episode types: podcast plus any extras enabled in SSP's settings.
		'types'       => function () {
			return array_values( array_filter( (array) ssp_post_types(), function ( $type ) {
				$obj = get_post_type_object( $type );
				return $obj && $obj->show_in_rest;
			} ) );
		},
		'groups'      => 'minn_admin_ssp_groups',
		'description' => __( 'Seriously Simple Podcasting episode fields for Minn Admin.', 'minn-admin' ),
	);
} );
