<?php
/**
 * Bundled adapter: JetEngine options pages (Crocoblock) under Site options.
 *
 * JetEngine registers each options page as a Jet_Engine_Options_Page_Factory
 * on init (jet_engine()->options_pages->registered_pages, keyed by slug).
 * A page carries its own capability, a storage type ('default' = one
 * option array named by the slug, 'separate' = one option per field,
 * prefixed unless option_prefix is off) and the same field vocabulary the
 * meta boxes use, so the mapper in jet-engine-fields.php serves both.
 * Reads go through the page's own get(), writes through update_options()
 * with rewrite off (untouched fields keep their value) and sanitize on
 * (their per-field sanitize callbacks run), which also fires their
 * jet-engine/options-pages/updated hooks. One tab per page: JetEngine
 * pages have no tabs of their own.
 *
 * @package minn-admin
 */
defined( 'ABSPATH' ) || exit;

function minn_admin_jet_options_active() {
	return function_exists( 'jet_engine' ) && is_object( jet_engine()->options_pages ) && function_exists( 'minn_admin_jet_map_field' );
}

/** slug => Jet_Engine_Options_Page_Factory the current user may open. */
function minn_admin_jet_options_pages_allowed() {
	if ( ! minn_admin_jet_options_active() ) {
		return array();
	}
	$out   = array();
	$pages = jet_engine()->options_pages->registered_pages;
	foreach ( is_array( $pages ) ? $pages : array() as $slug => $page ) {
		if ( ! is_object( $page ) || ! isset( $page->page ) || ! is_array( $page->page ) ) {
			continue;
		}
		$cap = ! empty( $page->page['capability'] ) ? (string) $page->page['capability'] : 'manage_options';
		if ( ! current_user_can( $cap ) ) {
			continue;
		}
		$out[ (string) $slug ] = $page;
	}
	return $out;
}

/** The page's raw field definitions (the meta_box list), name-keyed mapped fields + locked count. */
function minn_admin_jet_options_fields( $page ) {
	$raw    = isset( $page->meta_box ) && is_array( $page->meta_box ) ? $page->meta_box : array();
	$fields = array();
	$locked = 0;
	foreach ( $raw as $field ) {
		if ( ! is_array( $field ) ) {
			continue;
		}
		if ( isset( $field['object_type'] ) && 'field' !== $field['object_type'] ) {
			continue;
		}
		if ( in_array( $field['type'] ?? '', array( 'html', 'heading' ), true ) ) {
			continue;
		}
		$m = minn_admin_jet_map_field( $field );
		if ( ! $m ) {
			$locked++;
			continue;
		}
		$fields[ $m['name'] ] = $m;
	}
	return array( 'fields' => $fields, 'locked' => $locked );
}

/**
 * The page as the settings tier offers it: one group of the page's mapped
 * fields, each carrying its mapping (`_jet`) for the read and the write.
 *
 * @param object $page Jet_Engine_Options_Page_Factory.
 * @param string $slug Its slug.
 * @return array { groups, adminUrl }
 */
function minn_admin_jet_options_layout( $page, $slug ) {
	$set    = minn_admin_jet_options_fields( $page );
	$fields = array();
	foreach ( $set['fields'] as $name => $f ) {
		$sf = array( 'name' => $name, 'label' => $f['label'], 'type' => $f['type'] );
		foreach ( array( 'min', 'max', 'step', 'help' ) as $extra ) {
			if ( isset( $f[ $extra ] ) ) {
				$sf[ $extra ] = $f[ $extra ];
			}
		}
		if ( isset( $f['choices'] ) ) {
			$sf['options'] = array();
			foreach ( (array) $f['choices'] as $value => $label ) {
				$sf['options'][] = array( (string) $value, (string) $label );
			}
		}
		if ( 'radio' === $f['type'] ) {
			$sf['clearable'] = true; // else an unpicked radio shows its first choice
		}
		// A raw-format textarea stores what it is sent and prints it as
		// stored (JetEngine's default format): the tier's rule for a value
		// printed unescaped, unfiltered_html and code edits left on.
		if ( 'textarea' === $f['type'] && ! in_array( $f['_jet']['value_format'] ?? 'raw', array( 'plain_text', 'safe_html' ), true ) ) {
			$sf['_raw'] = true;
		}
		$sf['_jet'] = $f;
		$fields[]   = $sf;
	}
	return array(
		'groups'   => array(
			array( 'title' => '', 'fields' => $fields, 'locked' => $set['locked'] ),
		),
		'adminUrl' => admin_url( 'admin.php?page=' . rawurlencode( $slug ) ),
	);
}

/**
 * Each changed value in the shape their store takes, before anything is
 * written. A value their field refuses (a choice it does not offer, a
 * picture this person may not use) refuses the save, named.
 *
 * @param object $page    Jet_Engine_Options_Page_Factory.
 * @param array  $changes Changed values, by field name.
 * @param array  $fields  Their fields, by the same name.
 * @return array|WP_Error Values to store, by field name.
 */
function minn_admin_jet_options_parse( $page, $changes, $fields ) {
	$data    = array();
	$refused = array();
	foreach ( $changes as $name => $value ) {
		$f      = $fields[ $name ]['_jet'];
		$stored = minn_admin_jet_value_in( $f, $value, $page->get( $name, false ) );
		if ( null === $stored ) {
			$refused[] = $name;
			continue;
		}
		// A "save as array" checkbox goes in the shape their own form posts,
		// key => 'true': their sanitize callback (sanitize=true below) keeps
		// the keys whose value reads as true, so a plain list of keys stored
		// an empty list whatever was ticked.
		if ( 'multicheck' === $f['type'] && 'checkbox' === ( $f['_jet']['type'] ?? '' ) && ! empty( $f['_jet']['is_array'] ) && is_array( $stored ) ) {
			$stored = array_fill_keys( array_map( 'strval', $stored ), 'true' );
		}
		$data[ $name ] = false === $stored ? '' : $stored;
	}
	if ( $refused ) {
		$names = array_map( function ( $n ) use ( $fields ) {
			return (string) $fields[ $n ]['label'];
		}, $refused );
		return new WP_Error(
			'minn_jet_options_refused',
			sprintf(
				/* translators: %s: comma-separated field names. */
				_n( 'Nothing was saved: %s has a value the field does not accept.', 'Nothing was saved: %s have values the fields do not accept.', count( $names ), 'minn-admin' ),
				implode( ', ', $names )
			),
			array( 'status' => 400, 'fields' => $refused )
		);
	}
	return $data;
}

add_filter( 'minn_admin_option_pages', function ( $pages ) {
	foreach ( minn_admin_jet_options_pages_allowed() as $slug => $page ) {
		$set = minn_admin_jet_options_fields( $page );
		if ( ! $set['fields'] && ! $set['locked'] ) {
			continue; // a page with no fields is a menu entry, not a tab
		}
		// The page name rides labels.name after filter_item_for_register.
		$label   = ! empty( $page->page['labels']['name'] ) ? (string) $page->page['labels']['name'] : ( ! empty( $page->page['name'] ) ? (string) $page->page['name'] : $slug );
		$pages[] = array(
			'id'        => 'jet-engine:' . $slug,
			'label'     => $label,
			'source'    => 'JetEngine',
			'cap'       => ! empty( $page->page['capability'] ) ? (string) $page->page['capability'] : 'manage_options',
			'tabs'      => array( array( 'id' => 'tab-0', 'label' => $label ) ),
			'route'     => 'minn-admin/v1/jet-engine/options/' . rawurlencode( $slug ) . '/{tab}',
			// The menu slug JetEngine registers the page under.
			'adminPage' => ! empty( $page->page['slug'] ) ? (string) $page->page['slug'] : (string) $slug,
		);
	}
	return $pages;
} );

/**
 * The page's settings body, as its route answers GET.
 *
 * @param string $slug Page slug.
 * @return array|WP_Error
 */
function minn_admin_jet_options_shape( $slug ) {
	$pages = minn_admin_jet_options_pages_allowed();
	if ( ! isset( $pages[ $slug ] ) ) {
		return new WP_Error( 'minn_no_page', __( 'Unknown options page.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	return Minn_Admin_Kernel_Settings::page( 'jet-engine-options', array( 'slug' => $slug, 'page' => $pages[ $slug ] ) );
}

/**
 * Save a page's values from PHP, through the settings tier's checks.
 *
 * @param string $slug   Page slug.
 * @param array  $values Field name => value.
 * @return true|WP_Error|null Null when this user may not open the page.
 */
function minn_admin_jet_options_save( $slug, $values ) {
	$pages = minn_admin_jet_options_pages_allowed();
	if ( ! isset( $pages[ $slug ] ) || ! is_array( $values ) ) {
		return null;
	}
	return minn_admin_settings_save( 'jet-engine-options', array( 'values' => $values ), array( 'slug' => $slug, 'page' => $pages[ $slug ] ) );
}

/**
 * The page a route addresses, by its URL segment only (never the body or
 * query string), when the page's own capability lets this user open it.
 *
 * @param WP_REST_Request $req Request.
 * @return array|null { slug, page }
 */
function minn_admin_jet_options_resolve( WP_REST_Request $req ) {
	$slug  = rawurldecode( Minn_Admin::path_param( $req, 'page' ) );
	$pages = minn_admin_jet_options_pages_allowed();
	return isset( $pages[ $slug ] ) ? array( 'slug' => $slug, 'page' => $pages[ $slug ] ) : null;
}

minn_admin_settings( 'jet-engine-options', array(
	'route'    => 'jet-engine/options/(?P<page>[A-Za-z0-9_%.\-]+)/(?P<tab>tab-0)',
	'active'   => 'minn_admin_jet_options_active',
	'gate'     => function ( WP_REST_Request $req ) {
		return null !== minn_admin_jet_options_resolve( $req );
	},
	'target'   => 'minn_admin_jet_options_resolve',
	'layout'   => function ( $ctx ) {
		return minn_admin_jet_options_layout( $ctx['page'], $ctx['slug'] );
	},
	'read'     => function ( $field, $ctx ) {
		return minn_admin_jet_value_out( $field['_jet'], $ctx['page']->get( $field['name'], false ) );
	},
	// Their reading of a value sent back as served: a number box cannot
	// show a stored '12 units', so it comes back empty.
	'same'     => function ( $field, $sent, $served ) {
		return minn_admin_jet_same_value( $field['_jet'], $sent, $served );
	},
	'validate' => function ( $changes, $fields, $ctx ) {
		return minn_admin_jet_options_parse( $ctx['page'], $changes, $fields );
	},
	// rewrite=false leaves fields absent from the data alone; sanitize=true
	// runs their per-field callbacks, exactly as their own Save does.
	// Slashed: their Save hands update_options() the slashed $_REQUEST and
	// stores what it gets, and get() unslashes every read, so an unslashed
	// write lost each backslash in Minn, wp-admin and on the front end.
	// Options API: a hidden key sent back goes back to its credential.
	'restore'  => true,
	'write'    => function ( $data, $fields, $ctx ) {
		if ( $data ) {
			$ctx['page']->update_options( wp_slash( $data ), false, true );
		}
		return true;
	},
) );
