<?php
/**
 * Field groups: one place for every plugin's content schema.
 *
 * ACF and ACPT both describe the same idea, a named group of fields attached
 * to something, and each used to claim its own sidebar entry. They share one
 * **Field groups** item now, a view per plugin.
 *
 * A view rather than one mixed list, because a row's verbs are not the same
 * everywhere: ACF groups edit in Minn's own builder, while ACPT's schema
 * builder is a multi-step canvas Minn does not reimplement, so its rows list
 * and link out. Merged into one list, every action would have to explain
 * which rows it applies to. Side by side, each list is simply itself.
 *
 * A provider contributes through `minn_admin_field_group_sources`:
 *
 *     add_filter( 'minn_admin_field_group_sources', function ( $sources ) {
 *         $sources[] = array(
 *             'id'         => 'my-plugin',
 *             'label'      => 'My Plugin',   // names the view's tab
 *             'cap'        => 'manage_options',
 *             'plugin'     => 'my-plugin',   // its folder: the view leaves with it
 *             'collection' => array( … ),    // the usual collection descriptor
 *         );
 *         return $sources;
 *     } );
 *
 * `collection` is the ordinary collection vocabulary from
 * docs/for-plugin-authors.md, so a provider that already had a surface keeps
 * whatever it offered: columns, tabs, actions, create, import, open. A
 * source whose list is a post type it registered names it in `contentTypes`
 * (ACF's groups are its acf-field-group post type), so the list stays while
 * its wp-admin list does, as a surface's contentTypes does.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/** The shared surface id. */
const MINN_ADMIN_FIELD_GROUPS_SURFACE = 'field-groups';

/**
 * Every contributed source this user may see, in registration order.
 *
 * @return array[] { id, label, cap, collection }
 */
function minn_admin_field_group_sources() {
	$out = array();
	foreach ( (array) apply_filters( 'minn_admin_field_group_sources', array() ) as $source ) {
		if ( ! is_array( $source ) || empty( $source['id'] ) || empty( $source['collection'] ) ) {
			continue;
		}
		$collection = (array) $source['collection'];
		if ( empty( $collection['route'] ) ) {
			continue; // a view with nothing to list is not a view
		}
		$cap = isset( $source['cap'] ) ? (string) $source['cap'] : 'manage_options';
		if ( '' === $cap || ! current_user_can( $cap ) ) {
			continue;
		}
		$label = (string) ( $source['label'] ?? $source['id'] );
		// The switcher names each view after the plugin it belongs to, which
		// is the one thing a reader needs to tell two lists of field groups
		// apart. A provider that named its own view keeps that name.
		if ( empty( $collection['viewLabel'] ) ) {
			$collection['viewLabel'] = $label;
		}
		// A provider may bring more than one list of its own (ACF ships a
		// Fields view beside its Groups list); those ride along after it.
		$extra = array();
		foreach ( (array) ( $source['views'] ?? array() ) as $view ) {
			if ( is_array( $view ) && ! empty( $view['route'] ) && ! empty( $view['viewLabel'] ) ) {
				$extra[] = $view;
			}
		}
		$types = isset( $source['contentTypes'] ) ? (array) $source['contentTypes'] : ( 'acf' === (string) $source['id'] ? array( 'pt:acf-field-group' ) : array() );
		$out[] = array(
			'id'           => (string) $source['id'],
			'label'        => $label,
			'cap'          => $cap,
			'plugin'       => (array) ( $source['plugin'] ?? array() ),
			'contentTypes' => array_values( array_map( 'strval', $types ) ),
			'collection'   => $collection,
			'views'        => $extra,
		);
	}
	return $out;
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	$sources = minn_admin_field_group_sources();
	if ( ! $sources ) {
		return $surfaces;
	}
	// Whose groups these are is a question about PLUGINS, not about how many
	// lists they brought: ACF alone still ships two views.
	$providers = count( $sources );
	$plugins   = array();
	foreach ( $sources as $source ) {
		$plugins = array_merge( $plugins, $source['plugin'] );
	}
	$first     = array_shift( $sources );
	// Every list names the plugin it belongs to, so a menu tool that hides
	// one plugin takes its lists away and leaves the others: with the first
	// plugin's gone, the next plugin's list becomes the main one.
	$list_keys = function ( $source ) {
		$keys = array();
		if ( $source['plugin'] ) {
			$keys['plugin'] = $source['plugin'];
		}
		if ( $source['contentTypes'] ) {
			$keys['contentTypes'] = $source['contentTypes'];
		}
		return $keys;
	};
	// The first provider's own extra views stay directly behind its list, so
	// its two lists remain neighbours however many other plugins are here.
	$views = array();
	foreach ( $first['views'] as $own ) {
		$views[] = $list_keys( $first ) + $own;
	}
	foreach ( $sources as $source ) {
		// Per-view capability, so a reader who may see one plugin's schema
		// but not another's gets only the view they may have. The route's own
		// permission callback is still the real gate.
		$own_keys = array( 'cap' => $source['cap'] ) + $list_keys( $source );
		$views[]  = $own_keys + $source['collection'];
		foreach ( $source['views'] as $own ) {
			$views[] = $own_keys + $own;
		}
	}
	$surfaces[ MINN_ADMIN_FIELD_GROUPS_SURFACE ] = array(
		'label'      => __( 'Field Groups', 'minn-admin' ),
		// One plugin's groups say whose they are on the item itself; several
		// and the views say it instead.
		'sub'        => $providers > 1 ? '' : $first['label'],
		'plugin'     => array_values( array_unique( $plugins ) ),
		'icon'       => 'grid',
		'cap'        => $first['cap'],
		'collection' => $list_keys( $first ) + $first['collection'],
	);
	if ( $first['contentTypes'] ) {
		$surfaces[ MINN_ADMIN_FIELD_GROUPS_SURFACE ]['contentTypes'] = $first['contentTypes'];
	}
	if ( $views ) {
		$surfaces[ MINN_ADMIN_FIELD_GROUPS_SURFACE ]['views'] = $views;
	}
	return $surfaces;
} );
