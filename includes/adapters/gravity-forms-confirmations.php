<?php
/**
 * Gravity Forms: confirmations, the list view and the confirmation page.
 *
 * What a visitor sees after submitting: a message, a page of the site, or a
 * redirect, and (for every confirmation but the form's default) when it
 * applies. /gravity-forms/confirmation/{form}:{id} edits one, {form}:new
 * starts one that exists only once it is saved, and the preview shows the
 * message (or the address a visitor lands on) as the form's latest entry
 * would produce it.
 *
 * The save mirrors GF_Confirmation's settings save callback: the type
 * whitelist, their message filtering (merge tags used as HTML attribute
 * values are stripped for people without unfiltered_html, then kses), the
 * redirect URL check, logic through sanitize_conditional_logic (never on the
 * default), gform_pre_confirmation_save, and save_form_confirmations. Delete
 * and the Active switch are GFFormsModel's own; duplicate follows their
 * "Name (2)" naming.
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/** Their unsafe-merge-tag pattern (GF_Confirmation::$unsafe_regex). */
const MINN_ADMIN_GFC_UNSAFE_RE = '/(\S+)\s*=\s*["|\']({[^{]*?:(\d+(\.\d+)?)(:(.*?))?})["|\']/mi';

/** The form's confirmations, as their confirmation page reads them. */
function minn_admin_gfc_form( $form_id ) {
	$form = GFFormsModel::get_form_meta( $form_id );
	if ( ! is_array( $form ) || empty( $form['id'] ) ) {
		return null;
	}
	if ( empty( $form['confirmations'] ) || ! is_array( $form['confirmations'] ) ) {
		$form['confirmations'] = array();
	}
	return $form;
}

/** Their message filter: unsafe attribute merge tags out, then kses. */
function minn_admin_gfc_kses( $html ) {
	$html = (string) $html;
	if ( ! current_user_can( 'unfiltered_html' ) && preg_match_all( MINN_ADMIN_GFC_UNSAFE_RE, $html, $matches, PREG_SET_ORDER ) ) {
		foreach ( $matches as $m ) {
			if ( 'merge_tag' !== strtolower( $m[1] ) ) {
				$html = str_replace( $m[0], $m[1] . '=""', $html );
			}
		}
	}
	return (string) GFCommon::maybe_wp_kses( $html, 'post', array() );
}

/** The form's merge tags, plus the save-and-continue ones their page adds for the save events. */
function minn_admin_gfc_merge_tags( $form, $event ) {
	$groups = minn_admin_gfn_merge_tags( $form, '' );
	if ( ! in_array( $event, array( 'form_saved', 'form_save_email_sent' ), true ) ) {
		return $groups;
	}
	$extra = array(
		array( 'tag' => '{save_link}', 'label' => __( 'Save & Continue Link', 'minn-admin' ) ),
		array( 'tag' => '{save_token}', 'label' => __( 'Save & Continue Token', 'minn-admin' ) ),
	);
	if ( 'form_saved' === $event ) {
		$extra[] = array( 'tag' => '{save_email_input}', 'label' => __( 'Save & Continue Email Input', 'minn-admin' ) );
	}
	$groups[] = array( 'label' => __( 'Save & Continue', 'minn-admin' ), 'tags' => $extra );
	return $groups;
}

/** A row for the Confirmations view. */
function minn_admin_gfc_row( $form, $c ) {
	$types = array(
		'message'  => __( 'Text', 'minn-admin' ),
		'page'     => __( 'Page', 'minn-admin' ),
		'redirect' => __( 'Redirect', 'minn-admin' ),
	);
	$type    = isset( $types[ rgar( $c, 'type' ) ] ) ? $c['type'] : 'message';
	$default = ! empty( $c['isDefault'] );
	$active  = $default || ! isset( $c['isActive'] ) || false !== $c['isActive'];
	$shows   = '';
	if ( 'page' === $type ) {
		$shows = rgar( $c, 'pageId' ) ? get_the_title( (int) $c['pageId'] ) : '';
	} elseif ( 'redirect' === $type ) {
		$shows = (string) rgar( $c, 'url' );
	} else {
		$shows = wp_trim_words( wp_strip_all_tags( (string) rgar( $c, 'message' ) ), 12 );
	}
	return array(
		'id'      => $form['id'] . ':' . $c['id'],
		'form_id' => (int) $form['id'],
		'cid'     => (string) $c['id'],
		'name'    => (string) rgar( $c, 'name' ),
		'form'    => (string) $form['title'],
		'type'    => $types[ $type ],
		'shows'   => $shows,
		'when'    => $default ? __( 'Default', 'minn-admin' ) : ( ! empty( $c['conditionalLogic']['rules'] ) ? __( 'Conditional', 'minn-admin' ) : __( 'Always', 'minn-admin' ) ),
		'default' => $default ? '1' : '0',
		'status'  => $active ? 'active' : 'inactive',
		'toggle'  => $default ? '' : ( $active ? 'deactivate' : 'activate' ),
	);
}

/** The page's read: the confirmation, its form, and what the page picks from. */
function minn_admin_gfc_payload( $form, $c, $is_new ) {
	$type   = in_array( rgar( $c, 'type' ), array( 'message', 'page', 'redirect' ), true ) ? $c['type'] : 'message';
	$page   = rgar( $c, 'pageId' ) ? get_post( (int) $c['pageId'] ) : null;
	$event  = (string) rgar( $c, 'event' );
	$latest = minn_admin_gfn_latest_entry( $form['id'] );
	return array(
		'id'           => $form['id'] . ':' . ( $is_new ? 'new' : $c['id'] ),
		'isNew'        => (bool) $is_new,
		'confirmation' => array(
			'name'              => (string) rgar( $c, 'name' ),
			'isDefault'         => ! empty( $c['isDefault'] ),
			'isActive'          => ! empty( $c['isDefault'] ) || ! isset( $c['isActive'] ) || false !== $c['isActive'],
			'event'             => $event,
			'type'              => $type,
			'message'           => (string) rgar( $c, 'message' ),
			'disableAutoformat' => (bool) rgar( $c, 'disableAutoformat' ),
			'pageId'            => $page ? (string) $page->ID : '',
			'pageTitle'         => $page ? get_the_title( $page ) : '',
			'url'               => (string) rgar( $c, 'url' ),
			'queryString'       => (string) rgar( $c, 'queryString' ),
			'conditionalLogic'  => minn_admin_gfn_logic_out( rgar( $c, 'conditionalLogic' ) ),
		),
		'form'         => array(
			'id'           => (int) $form['id'],
			'title'        => (string) $form['title'],
			'builderRoute' => function_exists( 'minn_admin_gfb_available' ) && minn_admin_gfb_available() ? 'gravity-forms/form/' . (int) $form['id'] : '',
			'latestEntry'  => $latest ? (int) $latest['id'] : 0,
		),
		// Save-and-continue confirmations are messages by nature (their page
		// locks the type the same way).
		'typeLocked'   => in_array( $event, array( 'form_saved', 'form_save_email_sent' ), true ),
		'adminUrl'     => admin_url( 'admin.php?page=gf_edit_forms&view=settings&subview=confirmation&id=' . (int) $form['id'] . ( $is_new ? '' : '&cid=' . rawurlencode( (string) $c['id'] ) ) ),
		'fields'       => minn_admin_gfn_fields( $form ),
		'mergeTags'    => minn_admin_gfc_merge_tags( $form, $event ),
		'pages'        => minn_admin_gfc_pages( $page ),
		'canHtml'      => current_user_can( 'unfiltered_html' ),
	);
}

/** Published pages for the Page picker (their post_select lists pages), plus the chosen one. */
function minn_admin_gfc_pages( $current ) {
	$out  = array();
	$seen = array();
	foreach ( get_pages( array( 'post_status' => 'publish', 'sort_column' => 'post_title', 'number' => 500 ) ) as $p ) {
		$out[]             = array( (string) $p->ID, get_the_title( $p ) ? get_the_title( $p ) : sprintf( '#%d', $p->ID ) );
		$seen[ $p->ID ] = true;
	}
	if ( $current && empty( $seen[ $current->ID ] ) && current_user_can( 'read_post', $current->ID ) ) {
		$out[] = array( (string) $current->ID, get_the_title( $current ) ? get_the_title( $current ) : sprintf( '#%d', $current->ID ) );
	}
	return $out;
}

/**
 * The confirmation with the page's fields applied, validated the way their
 * settings fields validate it.
 *
 * @return array|WP_Error
 */
function minn_admin_gfc_build( $form, $stored, $body, $is_new ) {
	$c       = $stored;
	$default = ! empty( $stored['isDefault'] );
	$val     = function ( $key ) use ( $body ) {
		return array_key_exists( $key, $body ) && is_scalar( $body[ $key ] ) ? (string) $body[ $key ] : '';
	};
	$invalid = function ( $field, $message ) {
		return new WP_Error( 'minn_gfc_invalid', $message, array( 'status' => 400, 'field' => $field ) );
	};

	$name = $default ? (string) rgar( $stored, 'name' ) : trim( sanitize_text_field( $val( 'name' ) ) );
	if ( '' === $name ) {
		return $invalid( 'name', __( 'Give the confirmation a name.', 'minn-admin' ) );
	}
	foreach ( $form['confirmations'] as $other_id => $other ) {
		if ( ( $is_new || (string) $other_id !== (string) rgar( $stored, 'id' ) ) && strtolower( (string) rgar( $other, 'name' ) ) === strtolower( $name ) ) {
			return $invalid( 'name', __( 'Another confirmation on this form already uses that name.', 'minn-admin' ) );
		}
	}

	$event = (string) rgar( $stored, 'event' );
	$type  = GFCommon::whitelist( $val( 'type' ), array( 'message', 'page', 'redirect' ) );
	if ( in_array( $event, array( 'form_saved', 'form_save_email_sent' ), true ) ) {
		$type = 'message';
	}

	$message = $val( 'message' );
	$page_id = absint( $val( 'pageId' ) );
	$url     = trim( $val( 'url' ) );
	if ( 'page' === $type && ( ! $page_id || ! get_post( $page_id ) || 'trash' === get_post_status( $page_id ) || ! current_user_can( 'read_post', $page_id ) ) ) {
		return $invalid( 'pageId', __( 'Choose the page visitors go to.', 'minn-admin' ) );
	}
	if ( 'redirect' === $type && ( '' === $url || ! GFCommon::is_valid_url( $url ) ) && ! GFCommon::has_merge_tag( $url ) ) {
		return $invalid( 'url', __( 'Enter a full address (https://…) or a merge tag.', 'minn-admin' ) );
	}

	$logic = array();
	if ( ! $default && ! empty( $body['conditionalLogic'] ) && is_array( $body['conditionalLogic'] ) && ! empty( $body['conditionalLogic']['rules'] ) ) {
		$rules = array();
		foreach ( (array) $body['conditionalLogic']['rules'] as $r ) {
			if ( is_array( $r ) && '' !== (string) ( $r['fieldId'] ?? '' ) ) {
				$rules[] = array(
					'fieldId'  => (string) $r['fieldId'],
					'operator' => is_scalar( $r['operator'] ?? '' ) ? (string) ( $r['operator'] ?? '' ) : '',
					'value'    => is_scalar( $r['value'] ?? '' ) ? (string) $r['value'] : '',
				);
			}
		}
		if ( $rules ) {
			$logic = array(
				'actionType' => 'hide' === ( $body['conditionalLogic']['actionType'] ?? '' ) ? 'hide' : 'show',
				'logicType'  => 'any' === ( $body['conditionalLogic']['logicType'] ?? '' ) ? 'any' : 'all',
				'rules'      => $rules,
			);
		}
	}

	// Their save callback, in order. An unchanged message is kept as stored.
	$c['name']              = $name;
	$c['event']             = $event;
	$c['type']              = $type;
	$c['message']           = $message === (string) rgar( $stored, 'message' ) ? $message : minn_admin_gfc_kses( $message );
	$c['disableAutoformat'] = ! empty( $body['disableAutoformat'] );
	$c['pageId']            = $page_id ? (string) $page_id : '';
	$c['url']               = $url;
	$c['queryString']       = $val( 'queryString' );
	$c['conditionalLogic']  = $default || ! $logic ? array() : minn_admin_gfn_sanitize_rules( $logic, rgars( $stored, 'conditionalLogic/rules' ) );
	return $c;
}

/** Save through their write path; returns the confirmation id. */
function minn_admin_gfc_store( $form, $c, $is_new ) {
	if ( $is_new ) {
		$c['id']        = uniqid();
		$c['isDefault'] = false;
		$c['isActive']  = true;
	}
	$c = gf_apply_filters( array( 'gform_pre_confirmation_save', $form['id'] ), $c, $form, $is_new );
	$c = GFFormsModel::trim_conditional_logic_values_from_element( $c, $form );
	$form['confirmations'][ $c['id'] ] = $c;
	GFFormsModel::save_form_confirmations( $form['id'], $form['confirmations'] );
	GFFormsModel::flush_current_forms();
	return (string) $c['id'];
}

/**
 * The preview: the message as a visitor sees it, or the address they land
 * on, built from the page's unsaved fields and the form's latest entry
 * through their own rendering (GFFormDisplay). Shortcodes in a message are
 * left as typed; running them here could act on the site.
 */
function minn_admin_gfc_preview( $form, $stored, $body ) {
	$c = minn_admin_gfc_build( $form, $stored, $body, empty( $stored ) );
	if ( is_wp_error( $c ) ) {
		// Preview what is there even when it would not save yet.
		$c = array(
			'type'              => GFCommon::whitelist( is_scalar( $body['type'] ?? '' ) ? (string) $body['type'] : '', array( 'message', 'page', 'redirect' ) ),
			'message'           => minn_admin_gfc_kses( is_scalar( $body['message'] ?? '' ) ? (string) $body['message'] : '' ),
			'disableAutoformat' => ! empty( $body['disableAutoformat'] ),
			'pageId'            => current_user_can( 'read_post', absint( $body['pageId'] ?? 0 ) ) ? absint( $body['pageId'] ?? 0 ) : 0,
			'url'               => trim( is_scalar( $body['url'] ?? '' ) ? (string) $body['url'] : '' ),
			'queryString'       => is_scalar( $body['queryString'] ?? '' ) ? (string) $body['queryString'] : '',
		);
	}
	$entry = minn_admin_gfn_latest_entry( $form['id'] );
	$out   = array( 'entry' => $entry ? (int) $entry['id'] : 0, 'type' => $c['type'], 'html' => '', 'url' => '' );
	if ( 'message' === $c['type'] ) {
		$html = (string) $c['message'];
		if ( $entry ) {
			$html = (string) GFCommon::replace_variables( $html, $form, $entry, false, true, empty( $c['disableAutoformat'] ), 'html' );
		}
		$out['html'] = method_exists( 'GFCommon', 'maybe_sanitize_confirmation_message' ) ? (string) GFCommon::maybe_sanitize_confirmation_message( $html ) : $html;
		return $out;
	}
	if ( ! $entry ) {
		// No entry yet: the address with its merge tags still in it.
		$base = 'page' === $c['type'] ? ( $c['pageId'] ? (string) get_permalink( (int) $c['pageId'] ) : '' ) : (string) $c['url'];
		$qs   = trim( (string) $c['queryString'] );
		$out['url'] = '' !== $qs && '' !== $base ? $base . ( false === strpos( $base, '?' ) ? '?' : '&' ) . $qs : $base;
		return $out;
	}
	if ( ! class_exists( 'GFFormDisplay' ) ) {
		require_once GFCommon::get_base_path() . '/form_display.php';
	}
	$out['url'] = (string) GFFormDisplay::get_confirmation_url( $c, $form, $entry );
	return $out;
}

add_action( 'rest_api_init', function () {
	if ( ! class_exists( 'GFAPI' ) || ! method_exists( 'GFFormsModel', 'save_form_confirmations' ) || ! function_exists( 'minn_admin_gfn_load' ) ) {
		return;
	}
	$perm = function () {
		return GFCommon::current_user_can_any( array( 'gravityforms_edit_forms', 'gform_full_access' ) );
	};

	// The list: one form's confirmations, or every form's.
	$list = function ( WP_REST_Request $request ) {
		$form_id  = (int) $request['form'];
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 25 ) );
		$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );
		$forms    = array();
		if ( $form_id ) {
			$form = minn_admin_gfc_form( $form_id );
			if ( ! $form ) {
				return new WP_Error( 'not_found', __( 'Form not found.', 'minn-admin' ), array( 'status' => 404 ) );
			}
			$forms[] = $form;
		} else {
			foreach ( GFFormsModel::get_forms( null, 'title' ) as $f ) {
				$form = minn_admin_gfc_form( (int) $f->id );
				if ( $form ) {
					$forms[] = $form;
				}
			}
		}
		$rows = array();
		foreach ( $forms as $form ) {
			foreach ( $form['confirmations'] as $c ) {
				if ( is_array( $c ) && ! empty( $c['id'] ) ) {
					$rows[] = minn_admin_gfc_row( $form, $c );
				}
			}
		}
		return rest_ensure_response( array( 'items' => array_slice( $rows, ( $page - 1 ) * $per_page, $per_page ), 'total' => count( $rows ) ) );
	};
	register_rest_route( 'minn-admin/v1', '/gf/confirmations', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => $list ) );
	register_rest_route( 'minn-admin/v1', '/gf/forms/(?P<form>\d+)/confirmations', array( 'methods' => 'GET', 'permission_callback' => $perm, 'callback' => $list ) );

	$resolve = function ( WP_REST_Request $request ) {
		if ( ! minn_admin_gfn_load() ) {
			return new WP_Error( 'minn_gfc_unavailable', __( 'This version of Gravity Forms can’t be edited from here. Use Gravity Forms.', 'minn-admin' ), array( 'status' => 501 ) );
		}
		$form = minn_admin_gfc_form( (int) Minn_Admin::path_param( $request, 'form' ) );
		if ( ! $form ) {
			return new WP_Error( 'not_found', __( 'Form not found.', 'minn-admin' ), array( 'status' => 404 ) );
		}
		$cid    = (string) Minn_Admin::path_param( $request, 'cid' );
		$is_new = 'new' === $cid;
		if ( ! $is_new && ! isset( $form['confirmations'][ $cid ] ) ) {
			return new WP_Error( 'not_found', __( 'Confirmation not found.', 'minn-admin' ), array( 'status' => 404 ) );
		}
		return array( $form, $is_new ? array() : $form['confirmations'][ $cid ], $is_new, $cid );
	};
	$id_re = '(?P<form>\d+):(?P<cid>[a-zA-Z0-9_.-]+)';

	register_rest_route( 'minn-admin/v1', '/gf/confirmations/' . $id_re . '/full', array(
		array(
			'methods'             => 'GET',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) use ( $resolve ) {
				$r = $resolve( $request );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				list( $form, $stored, $is_new ) = $r;
				$seed = $is_new ? array(
					'name'    => __( 'New confirmation', 'minn-admin' ),
					'type'    => 'message',
					'message' => __( 'Thanks for contacting us! We will get in touch with you shortly.', 'minn-admin' ),
				) : $stored;
				return rest_ensure_response( minn_admin_gfc_payload( $form, $seed, $is_new ) );
			},
		),
		array(
			'methods'             => 'POST',
			'permission_callback' => $perm,
			'callback'            => function ( WP_REST_Request $request ) use ( $resolve ) {
				$r = $resolve( $request );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				list( $form, $stored, $is_new ) = $r;
				$body = (array) $request->get_json_params();
				$c    = minn_admin_gfc_build( $form, $stored, $body, $is_new );
				if ( is_wp_error( $c ) ) {
					return $c;
				}
				$cid = minn_admin_gfc_store( $form, $c, $is_new );
				if ( array_key_exists( 'isActive', $body ) && empty( $c['isDefault'] ) ) {
					$was = $is_new ? true : ( ! isset( $stored['isActive'] ) || false !== $stored['isActive'] );
					if ( (bool) $body['isActive'] !== $was ) {
						GFFormsModel::update_confirmation_active( $form['id'], $cid, (bool) $body['isActive'] );
						GFFormsModel::flush_current_forms();
					}
				}
				$fresh = minn_admin_gfc_form( $form['id'] );
				return rest_ensure_response( minn_admin_gfc_payload( $fresh, $fresh['confirmations'][ $cid ], false ) );
			},
		),
	) );

	register_rest_route( 'minn-admin/v1', '/gf/confirmations/' . $id_re . '/preview', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) use ( $resolve ) {
			$r = $resolve( $request );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			list( $form, $stored ) = $r;
			return rest_ensure_response( minn_admin_gfc_preview( $form, $stored, (array) $request->get_json_params() ) );
		},
	) );

	// Start a new confirmation (the list's New confirmation): it exists only
	// once its page is saved.
	register_rest_route( 'minn-admin/v1', '/gf/confirmations/new', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$form_id = (int) $request->get_param( 'form' );
			if ( ! $form_id || ! GFAPI::form_id_exists( $form_id ) ) {
				return new WP_Error( 'not_found', __( 'Choose a form for the confirmation.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			return rest_ensure_response( array( 'id' => $form_id . ':new', 'message' => __( 'Set up the confirmation, then save it.', 'minn-admin' ) ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/gf/confirmations/' . $id_re . '/active', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'args'                => array( 'active' => array( 'type' => 'boolean', 'required' => true ) ),
		'callback'            => function ( WP_REST_Request $request ) use ( $resolve ) {
			$r = $resolve( $request );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			list( $form, $stored, $is_new, $cid ) = $r;
			if ( $is_new || ! empty( $stored['isDefault'] ) ) {
				return new WP_Error( 'minn_gfc_default', __( 'The default confirmation is always on.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			GFFormsModel::update_confirmation_active( $form['id'], $cid, (bool) $request['active'] );
			GFFormsModel::flush_current_forms();
			return rest_ensure_response( array( 'id' => $form['id'] . ':' . $cid, 'active' => (bool) $request['active'] ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/gf/confirmations/' . $id_re . '/duplicate', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) use ( $resolve ) {
			$r = $resolve( $request );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			list( $form, $stored, $is_new ) = $r;
			if ( $is_new ) {
				return new WP_Error( 'minn_gfc_unsaved', __( 'Save the confirmation before duplicating it.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			// Their duplicate: a new id, never the default, "Name (2)".
			$copy              = $stored;
			$copy['id']        = uniqid();
			$copy['isDefault'] = false;
			$base              = trim( preg_replace( '/\(\d+\)$/', '', (string) rgar( $stored, 'name' ) ) );
			$n                 = preg_match( '/\((\d+)\)$/', (string) rgar( $stored, 'name' ), $m ) ? (int) $m[1] + 1 : 1;
			$taken             = array_map( 'strtolower', wp_list_pluck( $form['confirmations'], 'name' ) );
			do {
				$name = $base . " ($n)";
				++$n;
			} while ( in_array( strtolower( $name ), $taken, true ) );
			$copy['name']                         = $name;
			$form['confirmations'][ $copy['id'] ] = $copy;
			GFFormsModel::save_form_confirmations( $form['id'], $form['confirmations'] );
			GFFormsModel::flush_current_forms();
			return rest_ensure_response( array( 'id' => $form['id'] . ':' . $copy['id'], 'message' => __( 'Confirmation duplicated', 'minn-admin' ) ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/gf/confirmations/' . $id_re, array(
		'methods'             => 'DELETE',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) use ( $resolve ) {
			$r = $resolve( $request );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			list( $form, $stored, $is_new, $cid ) = $r;
			if ( $is_new || ! empty( $stored['isDefault'] ) ) {
				return new WP_Error( 'minn_gfc_default', __( 'The default confirmation can’t be deleted; it shows whenever no other confirmation applies.', 'minn-admin' ), array( 'status' => 400 ) );
			}
			GFFormsModel::delete_form_confirmation( $cid, $form['id'] );
			GFFormsModel::flush_current_forms();
			return rest_ensure_response( array( 'deleted' => true, 'message' => __( 'Confirmation deleted', 'minn-admin' ) ) );
		},
	) );
} );
