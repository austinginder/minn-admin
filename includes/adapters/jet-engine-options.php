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

function minn_admin_jet_options_shape( $slug ) {
	$pages = minn_admin_jet_options_pages_allowed();
	if ( ! isset( $pages[ $slug ] ) ) {
		return new WP_Error( 'minn_no_page', __( 'Unknown options page.', 'minn-admin' ), array( 'status' => 404 ) );
	}
	$page   = $pages[ $slug ];
	$set    = minn_admin_jet_options_fields( $page );
	$fields = array();
	$values = array();
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
		$fields[]        = $sf;
		$values[ $name ] = minn_admin_jet_value_out( $f, $page->get( $name, false ) );
	}
	return array(
		'groups'   => array(
			array( 'title' => '', 'fields' => $fields, 'locked' => $set['locked'] ),
		),
		'values'   => $values,
		'adminUrl' => admin_url( 'admin.php?page=' . rawurlencode( $slug ) ),
	);
}

function minn_admin_jet_options_save( $slug, $values ) {
	$pages = minn_admin_jet_options_pages_allowed();
	if ( ! isset( $pages[ $slug ] ) || ! is_array( $values ) ) {
		return;
	}
	$page = $pages[ $slug ];
	$set  = minn_admin_jet_options_fields( $page );
	$data = array();
	foreach ( $values as $name => $value ) {
		if ( ! isset( $set['fields'][ $name ] ) ) {
			continue; // only the page's own mapped fields
		}
		$stored        = minn_admin_jet_value_in( $set['fields'][ $name ], $value );
		$data[ $name ] = null === $stored ? '' : $stored;
	}
	if ( ! $data ) {
		return;
	}
	// rewrite=false leaves fields absent from $data alone; sanitize=true
	// runs their per-field callbacks, exactly as their own Save does.
	$page->update_options( $data, false, true );
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
			'id'     => 'jet-engine:' . $slug,
			'label'  => $label,
			'source' => 'JetEngine',
			'cap'    => ! empty( $page->page['capability'] ) ? (string) $page->page['capability'] : 'manage_options',
			'tabs'   => array( array( 'id' => 'tab-0', 'label' => $label ) ),
			'route'  => 'minn-admin/v1/jet-engine/options/' . rawurlencode( $slug ) . '/{tab}',
		);
	}
	return $pages;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_jet_options_active() ) {
		return;
	}
	$resolve = function ( $req ) {
		$slug  = rawurldecode( (string) $req['page'] );
		$pages = minn_admin_jet_options_pages_allowed(); // the page's own capability decides
		return isset( $pages[ $slug ] ) ? $slug : null;
	};
	register_rest_route( 'minn-admin/v1', '/jet-engine/options/(?P<page>[A-Za-z0-9_%.\-]+)/(?P<tab>tab-0)', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => function ( $req ) use ( $resolve ) {
				return (bool) $resolve( $req );
			},
			'callback'            => function ( $req ) use ( $resolve ) {
				return rest_ensure_response( minn_admin_jet_options_shape( $resolve( $req ) ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => function ( $req ) use ( $resolve ) {
				return (bool) $resolve( $req );
			},
			'callback'            => function ( $req ) use ( $resolve ) {
				$slug   = $resolve( $req );
				$values = $req->get_param( 'values' );
				minn_admin_jet_options_save( $slug, is_array( $values ) ? $values : array() );
				return rest_ensure_response( minn_admin_jet_options_shape( $slug ) );
			},
		),
	) );
} );
