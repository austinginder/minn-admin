<?php
/**
 * Bundled adapter: WPCode (Insert Headers and Footers).
 *
 * WPCode stores snippets as a private CPT (`wpcode`) with no public REST surface,
 * so this is the shim pattern (same idea as Gravity SMTP / Stream): a thin
 * minn-admin/v1/wpcode collection over WPCode_Snippet, plus a Snippets surface
 * that matches the Code Snippets UX (list, edit, toggle, create, delete).
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * WPCode's own per-code-type capability for a submitted type.
 *
 * WPCode Pro grades snippet authoring by what the code can DO —
 * wpcode_edit_text_snippets (text/blocks), wpcode_edit_html_snippets
 * (HTML/JS/CSS), wpcode_edit_php_snippets (PHP and everything else) — and its
 * own save listener resolves the required capability FROM THE SUBMITTED
 * code_type before allowing the write (class-wpcode-admin-page-snippet-manager).
 * Checking only the flat wpcode_edit_snippets lets a user provisioned for
 * markup submit code_type=php and author executable PHP.
 *
 * On Lite the graded caps map down to wpcode_edit_snippets, so this is a no-op.
 */
function minn_admin_wpcode_can_type( $code_type ) {
	$required = '';
	if ( class_exists( 'WPCode_Access' ) && method_exists( 'WPCode_Access', 'capability_for_code_type' ) ) {
		$required = (string) WPCode_Access::capability_for_code_type( $code_type );
	}
	return current_user_can( $required ?: 'wpcode_edit_snippets' );
}

/**
 * Does WPCode EXECUTE this code type?
 *
 * WPCode runs two types, not one: class-wpcode-snippet.php's
 * run_activation_checks() declares array( 'php', 'universal' ), and the
 * universal handler is documented as taking "both HTML and PHP at the same
 * time in the same way you can write both in a .php file". Matching the
 * literal 'php' let code_type=universal author executable PHP on a site that
 * had turned dashboard code editing off.
 *
 * Anything not on the known-inert list counts as executing, so a type WPCode
 * adds later is refused under DISALLOW_FILE_EDIT until it is classified here
 * rather than silently allowed. The Pro-only WPCode_Access mapper resolving a
 * type to the PHP tier is treated as executing too; it does not exist in the
 * free plugin, which is why it cannot be the only signal.
 *
 * @param string $code_type WPCode code type.
 * @return bool
 */
function minn_admin_wpcode_type_executes( $code_type ) {
	$inert = array( 'html', 'text', 'css', 'js', 'scss', 'blocks' );
	if ( in_array( (string) $code_type, $inert, true ) ) {
		return false;
	}
	return true;
}

/**
 * Does WPCode EXECUTE snippets parked at this LOCATION, whatever their type?
 *
 * The code type decides how a snippet is authored; the LOCATION TERM decides
 * which runner picks it up, and the two are not connected. WPCode groups every
 * published snippet by its wpcode_location term with no code_type filter at all
 * (WPCode_Auto_Insert_Type::load_all_snippets_for_type), and
 * WPCode_Auto_Insert_Everywhere::run_snippets() concatenates get_code() for the
 * buckets below and hands the result to safe_execute_php() -> eval().
 *
 * So a snippet stored as `text` -- the one type authored without unfiltered_html,
 * because its bytes are kses-filtered rather than executed -- runs as PHP the
 * moment it sits at one of these locations. Guarding on code_type alone reads the
 * request's claim about itself and ignores where the bytes actually land.
 *
 * `on_demand` is included even though no runner consumes it: it is one PUT away
 * from a running bucket, and admitting it would leave a parked payload that a
 * later retarget starts.
 *
 * @param string $location WPCode location slug.
 * @return bool
 */
function minn_admin_wpcode_location_executes( $location ) {
	return in_array(
		(string) $location,
		array( 'everywhere', 'admin_only', 'frontend_only', 'frontend_cl', 'on_demand' ),
		true
	);
}

/**
 * The locations Minn offers for a given code type.
 *
 * WPCode's own form filters the location list by type in JavaScript; the REST
 * layer does not, and neither did this adapter -- it served one flat list, PHP
 * buckets included, for every type, with `everywhere` as the default. Pair the
 * vocabularies so the UI cannot offer a markup type a bucket that would execute
 * it, and so an unknown slug never reaches wp_set_post_terms (wpcode_location is
 * non-hierarchical, so it silently CREATES whatever slug it is handed).
 *
 * @param string $code_type WPCode code type.
 * @return string[] Allowed location slugs.
 */
function minn_admin_wpcode_locations_for_type( $code_type ) {
	$exec = array( 'everywhere', 'admin_only', 'frontend_only', 'frontend_cl', 'on_demand' );
	$markup = array(
		'site_wide_header',
		'site_wide_body',
		'site_wide_footer',
		'before_post',
		'after_post',
		'before_content',
		'after_content',
		'after_paragraph',
	);
	return minn_admin_wpcode_type_executes( $code_type ) ? $exec : $markup;
}

/**
 * Does this type put the author's MARKUP into the page?
 *
 * Distinct from executing: a `text` or `html` snippet never runs PHP, but
 * WPCode emits its bytes verbatim at the snippet's location, so a <script>
 * in one runs for every visitor and every administrator. That makes these
 * types answer to unfiltered_html, the capability WordPress uses everywhere
 * else to decide who may store raw markup, rather than to DISALLOW_FILE_EDIT.
 *
 * @param string $code_type WPCode code type.
 * @return bool
 */
function minn_admin_wpcode_type_is_markup( $code_type ) {
	// WPCode's own capability label names the tier: "Edit HTML, JavaScript &
	// CSS Snippets". Guarding html alone covered a third of it, and js is the
	// worse half — an html snippet has to smuggle a script tag, a js snippet
	// IS the script body, emitted inside <script> into wp_head and admin_head.
	// scss compiles into the same <style> sink.
	return in_array( (string) $code_type, array( 'html', 'text', 'js', 'css', 'scss' ), true );
}

/**
 * Reproduce WPCode's own write-time sanitizing for a caller without
 * unfiltered_html.
 *
 * WPCode turns core's kses OFF for its own saves: includes/post-type.php
 * hooks wpcode_maybe_remove_core_content_filters() to wpcode_before_snippet_save,
 * and that calls remove_all_filters( 'content_save_pre' ) immediately before
 * wp_insert_post() runs. Nothing sanitizes post_content on that path, so the
 * plugin compensates inside its own save listener instead
 * (class-wpcode-admin-page-snippet-manager.php: a `text` snippet authored
 * without unfiltered_html is passed through wp_kses_post). An adapter that
 * writes through WPCode_Snippet gets the filter removal and none of the
 * compensation, so it has to carry the same rule itself.
 *
 * @param string $code_type Effective code type.
 * @param string $code      Raw code.
 * @return string
 */
function minn_admin_wpcode_clean_code( $code_type, $code ) {
	if ( 'text' === (string) $code_type && ! current_user_can( 'unfiltered_html' ) ) {
		return wp_kses_post( (string) $code );
	}
	return (string) $code;
}

/**
 * Refuse code WPCode's own form would refuse.
 *
 * WPCode's submit listener runs WPCode_Snippet_Execute::is_code_not_allowed()
 * over the submitted code and wp_die()s on a match ("Restricted Code
 * Detected"). That check lives in the FORM handler, not in
 * WPCode_Snippet::save(), so an adapter writing through the model gets the
 * activation and capability backstops save() carries but not this one. Minn
 * calls the vendor's own static so the rule stays theirs; without the class
 * loaded there is nothing to reproduce and the write proceeds.
 *
 * @param string $code Raw code about to be stored.
 * @return WP_Error|true
 */
function minn_admin_wpcode_guard_code( $code ) {
	if ( ! class_exists( 'WPCode_Snippet_Execute' )
		|| ! method_exists( 'WPCode_Snippet_Execute', 'is_code_not_allowed' ) ) {
		return true;
	}
	if ( WPCode_Snippet_Execute::is_code_not_allowed( (string) $code ) ) {
		return new WP_Error(
			'forbidden',
			__( 'WPCode blocks this code because it contains patterns it treats as unsafe.', 'minn-admin' ),
			array( 'status' => 403 )
		);
	}
	return true;
}

/**
 * Types that cannot be repaired by filtering, only refused.
 *
 * kses over a JavaScript body is meaningless, so these are an outright refusal
 * for a caller without unfiltered_html, while `text` is filtered the way the
 * vendor filters it.
 *
 * Stated as "everything that is not text", not as a list of known-bad types,
 * so a type nobody here has thought about lands on the safe side. Naming the
 * four then meant `blocks` matched no arm at all: not executing, so no
 * DISALLOW_FILE_EDIT check; not needs-raw, so no refusal; not the literal
 * 'text', so no kses; and not markup, so no re-filter on a retype. It was the
 * one type stored with nothing applied to it, and block markup can carry a
 * core/html block, which is a script tag with extra steps.
 */
function minn_admin_wpcode_type_needs_raw( $code_type ) {
	$code_type = (string) $code_type;
	if ( 'text' === $code_type ) {
		return false; // filtered instead, in minn_admin_wpcode_clean_code()
	}
	// An executing type answers to code_edits_allowed() rather than to this.
	return ! minn_admin_wpcode_type_executes( $code_type );
}

/**
 * 403 unless the caller may author this code type (and, on an update, edit
 * this specific snippet — WPCode requires edit_post on the target too).
 */
/**
 * Resolve and validate a request's location against the type it will be stored as.
 *
 * Read from $request[...] so the guard and the write can never disagree about
 * which slot they are talking about, and refuse anything outside the vocabulary
 * for that type -- an unrecognised slug would otherwise be created as a brand new
 * wpcode_location term by wp_set_post_terms.
 *
 * @param WP_REST_Request $request   Request.
 * @param string          $code_type Effective code type for the write.
 * @param string|null     $fallback  Stored location to keep when none was sent.
 * @return string|WP_Error Location slug, or an error when it is not offered.
 */
function minn_admin_wpcode_location_in( $request, $code_type, $fallback = null ) {
	$allowed = minn_admin_wpcode_locations_for_type( $code_type );
	$raw     = $request['location'];
	if ( null === $raw || '' === $raw ) {
		if ( null !== $fallback ) {
			return (string) $fallback;
		}
		return (string) $allowed[0];
	}
	$loc = sanitize_key( (string) $raw );
	if ( ! in_array( $loc, $allowed, true ) ) {
		return new WP_Error(
			'forbidden',
			__( 'That location is not available for this snippet type.', 'minn-admin' ),
			array( 'status' => 400 )
		);
	}
	return $loc;
}

function minn_admin_wpcode_guard_type( $code_type, $snippet_id = 0, $writes_code = true, $location = null ) {
	// The LOCATION decides which runner executes the bytes, and WPCode's grouping
	// query never looks at the code type -- so a snippet parked at a PHP bucket is
	// PHP authoring however it is typed. Resolve the effective type from BOTH before
	// any bar is applied: the guard must judge what the request produces, not what
	// it calls itself.
	if ( null !== $location && minn_admin_wpcode_location_executes( $location ) ) {
		$code_type = 'php';
	}
	// html, js, css and scss are all emitted as raw code on every page, and
	// WPCode's own capability label treats them as one tier: "Edit HTML,
	// JavaScript & CSS Snippets". kses cannot repair a JavaScript body, so
	// these are refused rather than filtered; `text` is filtered instead, in
	// minn_admin_wpcode_clean_code(). None of them answers to
	// code_edits_allowed() below: they are not file editing, they are code
	// going into the page, and the capability for that is unfiltered_html.
	//
	// This is deliberately STRICTER than WPCode 2.3.x, whose own save
	// listener gates only on wpcode_edit_snippets. Authoring rights there
	// carry no markup-trust requirement at all, so a principal WordPress
	// withholds unfiltered_html from can still write a <script> through the
	// vendor's form. Minn does not widen that hole through its own routes.
	//
	// Only when the request actually authors or activates. Renaming,
	// relocating, deleting or DEACTIVATING an existing snippet stays open:
	// telling someone a snippet is too dangerous for them to edit and then
	// refusing to let them switch it off is not hardening.
	if ( $writes_code
		&& minn_admin_wpcode_type_needs_raw( $code_type )
		&& ! current_user_can( 'unfiltered_html' ) ) {
		return new WP_Error(
			'forbidden',
			__( 'This snippet type is emitted as raw code on every page, so authoring it needs the unfiltered_html capability.', 'minn-admin' ),
			array( 'status' => 403 )
		);
	}
	// A snippet WPCode EXECUTES is PHP authoring, so it answers to the
	// directive a site owner sets to forbid exactly that. Markup and text
	// snippets are unaffected.
	if ( $writes_code && minn_admin_wpcode_type_executes( $code_type ) && class_exists( 'Minn_Admin' ) && ! Minn_Admin::code_edits_allowed() ) {
		return new WP_Error(
			'forbidden',
			__( 'This site disallows editing code from the dashboard, so PHP snippets cannot be created or changed here.', 'minn-admin' ),
			array( 'status' => 403 )
		);
	}
	// The bar was inverted: a snippet that merely EMITS markup needed
	// unfiltered_html, while one the site EXECUTES did not. Every other code
	// adapter here (custom-css-js, hfcm, fluent-snippets) pairs its directive
	// check with the capability, so hold the executing types to at least the
	// bar the inert ones already carry.
	if ( $writes_code && minn_admin_wpcode_type_executes( $code_type ) && ! current_user_can( 'unfiltered_html' ) ) {
		return new WP_Error(
			'forbidden',
			__( 'Authoring a snippet this site executes needs the unfiltered_html capability.', 'minn-admin' ),
			array( 'status' => 403 )
		);
	}
	if ( ! minn_admin_wpcode_can_type( $code_type ) ) {
		return new WP_Error(
			'forbidden',
			__( 'You cannot create snippets of that type on this site.', 'minn-admin' ),
			array( 'status' => 403 )
		);
	}
	if ( $snippet_id && ! current_user_can( 'edit_post', (int) $snippet_id ) ) {
		return new WP_Error( 'forbidden', __( 'You cannot edit that snippet.', 'minn-admin' ), array( 'status' => 403 ) );
	}
	return true;
}

function minn_admin_wpcode_active() {
	return class_exists( 'WPCode_Snippet' ) || defined( 'WPCODE_VERSION' ) || defined( 'WPCODE_PLUGIN_VERSION' );
}

/**
 * Normalize a WPCode_Snippet into the shared Snippets list/detail shape.
 *
 * @param WPCode_Snippet $snippet Snippet object.
 * @return array
 */
function minn_admin_wpcode_item( $snippet ) {
	$post = $snippet->get_post_data();
	$modified = $post && $post->post_modified ? $post->post_modified : '';
	return array(
		'id'        => (int) $snippet->get_id(),
		'name'      => $snippet->get_title(),
		'desc'      => (string) $snippet->get_note(),
		'code'      => $snippet->get_code(),
		'tags'      => array_values( (array) $snippet->get_tags() ),
		// Column "scope" = type · location for a one-glance scan.
		'scope'     => trim( $snippet->get_code_type() . ' · ' . $snippet->get_location(), ' ·' ),
		'code_type' => $snippet->get_code_type(),
		'location'  => $snippet->get_location(),
		'active'    => (bool) $snippet->is_active(),
		// WPCode's insert method: on = runs at the location below, off = the
		// snippet only runs where its shortcode is placed.
		'auto_insert' => (bool) $snippet->get_auto_insert(),
		'priority'  => (int) $snippet->get_priority(),
		'modified'  => $modified,
	);
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_wpcode_active() ) {
		return $surfaces;
	}

	$type_options = array(
		array( 'php', 'PHP' ),
		array( 'js', 'JavaScript' ),
		array( 'css', 'CSS' ),
		array( 'html', 'HTML' ),
		array( 'text', 'Text' ),
	);
	// Two tiers, and they are not interchangeable: the first five are the buckets
	// WPCode executes as PHP, the rest emit markup. The server refuses a pairing it
	// did not offer (minn_admin_wpcode_location_in), so this list is the honest
	// vocabulary rather than the only guard.
	$location_options = array(
		array( 'everywhere', __( 'Everywhere (runs code)', 'minn-admin' ) ),
		array( 'frontend_only', __( 'Front-end only (runs code)', 'minn-admin' ) ),
		array( 'admin_only', __( 'Admin only (runs code)', 'minn-admin' ) ),
		array( 'site_wide_header', __( 'Site-wide header', 'minn-admin' ) ),
		array( 'site_wide_body', __( 'Site-wide body', 'minn-admin' ) ),
		array( 'site_wide_footer', __( 'Site-wide footer', 'minn-admin' ) ),
		array( 'before_post', __( 'Before post', 'minn-admin' ) ),
		array( 'after_post', __( 'After post', 'minn-admin' ) ),
		array( 'before_content', __( 'Before content', 'minn-admin' ) ),
		array( 'after_content', __( 'After content', 'minn-admin' ) ),
		array( 'after_paragraph', __( 'After paragraph', 'minn-admin' ) ),
	);

	$edit_fields = array(
		array( 'key' => 'name', 'label' => __( 'Name', 'minn-admin' ), 'placeholder' => __( 'Disable comments', 'minn-admin' ) ),
		array( 'key' => 'desc', 'label' => __( 'Note', 'minn-admin' ), 'type' => 'textarea', 'rows' => 2, 'required' => false ),
		array(
			'key'         => 'code',
			'label'       => __( 'Code', 'minn-admin' ),
			'type'        => 'textarea',
			'mono'        => true,
			'rows'        => 14,
			'placeholder' => "add_filter( '…', '…' );",
		),
		array( 'key' => 'code_type', 'label' => __( 'Type', 'minn-admin' ), 'type' => 'select', 'options' => $type_options ),
		array( 'key' => 'auto_insert', 'label' => __( 'Insert automatically', 'minn-admin' ), 'type' => 'toggle' ),
		array( 'key' => 'location', 'label' => __( 'Location', 'minn-admin' ), 'type' => 'select', 'options' => $location_options ),
		array( 'key' => 'priority', 'label' => __( 'Priority', 'minn-admin' ), 'type' => 'number' ),
		array( 'key' => 'tags', 'label' => __( 'Tags', 'minn-admin' ), 'type' => 'tags', 'required' => false ),
	);

	$surfaces['wpcode'] = array(
		'label'      => __( 'Snippets', 'minn-admin' ),
		'family'     => 'snippets',
		'sub'        => 'WPCode',
		'icon'       => 'code',
		'cap'        => 'wpcode_edit_snippets',
		// Status card (v0.18.0): family parity with Code Snippets.
		'status'     => array( 'route' => 'minn-admin/v1/wpcode/status' ),
		'collection' => array(
			'route'     => 'minn-admin/v1/wpcode/snippets',
			'pageQuery' => 'per_page=25&page={page}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'filter'    => array(
				'label'   => __( 'Status', 'minn-admin' ),
				'options' => array(
					array( 'all', 'All' ),
					array( 'active', 'Active' ),
					array( 'inactive', 'Inactive' ),
				),
				'query'   => 'active={v}',
			),
			'create'    => array(
				'label'    => __( 'Add snippet', 'minn-admin' ),
				'route'    => 'minn-admin/v1/wpcode/snippets',
				'method'   => 'POST',
				'defaults' => array(
					'active'    => false,
					'code_type'   => 'php',
					'location'    => 'everywhere',
					'auto_insert' => true,
					'priority'    => 10,
					'tags'      => array(),
					'code'      => '',
				),
				'fields'   => $edit_fields,
			),
			'columns'   => array(
				array( 'key' => 'name', 'label' => __( 'Snippet', 'minn-admin' ), 'format' => 'title', 'width' => 'minmax(0,1.8fr)' ),
				array( 'key' => 'scope', 'label' => __( 'Type · location', 'minn-admin' ), 'format' => 'mono', 'width' => 'minmax(0,1fr)' ),
				array( 'key' => 'active', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill', 'width' => '100px' ),
				array( 'key' => 'priority', 'label' => __( 'Priority', 'minn-admin' ), 'format' => 'num', 'width' => '80px' ),
				array( 'key' => 'modified', 'label' => __( 'Modified', 'minn-admin' ), 'format' => 'ago' ),
			),
			'detail'    => array(
				'detailRoute' => 'minn-admin/v1/wpcode/snippets/{id}',
				'skip'        => array(
					'code', 'name', 'desc', 'scope', 'code_type', 'location',
					'priority', 'tags', 'active', 'auto_insert',
				),
				'edit'        => array(
					'route'    => 'minn-admin/v1/wpcode/snippets/{id}',
					'method'   => 'PUT',
					'preserve' => array( 'active' ),
					'fields'   => $edit_fields,
				),
			),
			'actions'   => array(
				array(
					'label'  => __( 'Activate', 'minn-admin' ),
					'method' => 'POST',
					'route'  => 'minn-admin/v1/wpcode/snippets/{id}/active',
					'body'   => array( 'active' => true ),
					'when'   => array( 'key' => 'active', 'equals' => false ),
				),
				array(
					'label'  => __( 'Deactivate', 'minn-admin' ),
					'method' => 'POST',
					'route'  => 'minn-admin/v1/wpcode/snippets/{id}/active',
					'body'   => array( 'active' => false ),
					'when'   => array( 'key' => 'active', 'equals' => true ),
				),
				array(
					'label' => __( 'Edit in WPCode ↗', 'minn-admin' ),
					'href'  => admin_url( 'admin.php?page=wpcode-snippet-manager&snippet_id={id}' ),
				),
				array(
					'label'   => __( 'Delete snippet', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/wpcode/snippets/{id}',
					'confirm' => __( 'Delete this snippet permanently? Its code will be gone.', 'minn-admin' ),
					'danger'  => true,
				),
			),
			'bulk'      => array(
				array(
					'label'  => __( 'Activate', 'minn-admin' ),
					'method' => 'POST',
					'route'  => 'minn-admin/v1/wpcode/snippets/{id}/active',
					'body'   => array( 'active' => true ),
					'when'   => array( 'key' => 'active', 'equals' => false ),
				),
				array(
					'label'  => __( 'Deactivate', 'minn-admin' ),
					'method' => 'POST',
					'route'  => 'minn-admin/v1/wpcode/snippets/{id}/active',
					'body'   => array( 'active' => false ),
					'when'   => array( 'key' => 'active', 'equals' => true ),
				),
				array(
					'label'   => __( 'Delete', 'minn-admin' ),
					'method'  => 'DELETE',
					'route'   => 'minn-admin/v1/wpcode/snippets/{id}',
					'confirm' => __( 'Delete the selected snippets permanently?', 'minn-admin' ),
					'danger'  => true,
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_wpcode_active() || ! class_exists( 'WPCode_Snippet' ) ) {
		return;
	}

	$can_edit = function () {
		return current_user_can( 'wpcode_edit_snippets' );
	};
	$can_act = function () {
		return current_user_can( 'wpcode_activate_snippets' );
	};

	register_rest_route(
		'minn-admin/v1',
		'/wpcode/snippets',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => $can_edit,
				'callback'            => function ( WP_REST_Request $request ) {
					$per_page = min( 100, max( 1, (int) ( $request['per_page'] ?? 25 ) ) );
					$page     = max( 1, (int) ( $request['page'] ?? 1 ) );
					// Active filter: WPCode stores active as post status
					// publish=active, draft/private=inactive (their model).
					$active = sanitize_key( (string) ( $request['active'] ?? 'all' ) );
					$status = array( 'publish', 'draft', 'private' );
					if ( 'active' === $active ) {
						$status = array( 'publish' );
					} elseif ( 'inactive' === $active ) {
						$status = array( 'draft', 'private' );
					}
					$q = new WP_Query(
						array(
							'post_type'      => 'wpcode',
							'post_status'    => $status,
							'posts_per_page' => $per_page,
							'paged'          => $page,
							'orderby'        => 'modified',
							'order'          => 'DESC',
						)
					);
					$items = array();
					foreach ( $q->posts as $post ) {
						$snippet = new WPCode_Snippet( $post );
						$item    = minn_admin_wpcode_item( $snippet );
						// Same rule as the single-snippet read: the source of a
						// snippet whose type this caller cannot author is not
						// theirs to see. The list does not render `code`
						// anyway, so blanking it costs the view nothing.
						if ( is_wp_error( minn_admin_wpcode_guard_type( (string) $snippet->get_code_type(), (int) $snippet->get_id(), false ) ) ) {
							$item['code'] = '';
						}
						$items[] = $item;
					}
					return rest_ensure_response(
						array(
							'items' => $items,
							'total' => (int) $q->found_posts,
						)
					);
				},
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => $can_edit,
				'callback'            => function ( WP_REST_Request $request ) {
					$create_type     = sanitize_key( (string) ( $request['code_type'] ?? 'php' ) );
					$create_location = minn_admin_wpcode_location_in( $request, $create_type );
					if ( is_wp_error( $create_location ) ) {
						return $create_location;
					}
					$guard = minn_admin_wpcode_guard_type( $create_type, 0, true, $create_location );
					if ( is_wp_error( $guard ) ) {
						return $guard;
					}
					$guard_code = minn_admin_wpcode_guard_code( (string) ( $request['code'] ?? '' ) );
					if ( is_wp_error( $guard_code ) ) {
						return $guard_code;
					}
					// load_from_array (via the array constructor) can set private
					// fields like priority/note that are not assignable from outside.
					$snippet = new WPCode_Snippet(
						array(
							'title'       => sanitize_text_field( (string) $request['name'] ),
							'code'        => minn_admin_wpcode_clean_code( $create_type, (string) ( $request['code'] ?? '' ) ),
							'code_type'   => $create_type,
							'location'    => $create_location,
							'priority'    => (int) ( $request['priority'] ?? 10 ),
							'tags'        => array_map( 'sanitize_text_field', (array) ( $request['tags'] ?? array() ) ),
							'note'        => sanitize_textarea_field( (string) ( $request['desc'] ?? '' ) ),
							// Carry the requested insert method rather than forcing it on:
							// auto_insert=1 is what makes WPCode write the location term,
							// so hardcoding it published every created snippet at its
							// location whatever the form's own switch said.
							'auto_insert' => (int) rest_sanitize_boolean( $request['auto_insert'] ?? true ),
							'active'      => ! empty( $request['active'] ),
						)
					);
					$id = $snippet->save();
					if ( ! $id ) {
						return new WP_Error( 'wpcode_save_failed', __( 'Could not create the snippet.', 'minn-admin' ), array( 'status' => 500 ) );
					}
					return rest_ensure_response( minn_admin_wpcode_item( new WPCode_Snippet( (int) $id ) ) );
				},
			),
		)
	);

	register_rest_route(
		'minn-admin/v1',
		'/wpcode/snippets/(?P<id>\d+)',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => $can_edit,
				'callback'            => function ( WP_REST_Request $request ) {
					$snippet = new WPCode_Snippet( (int) $request['id'] );
					if ( ! $snippet->get_id() ) {
						return new WP_Error( 'not_found', __( 'Snippet not found.', 'minn-admin' ), array( 'status' => 404 ) );
					}
					// Reading a snippet's source is the same bar as editing it.
					// Deleting one already asks this, on the reasoning that
					// destroying a snippet needs the same standing as editing
					// it; reading the PHP a colleague wrote is no smaller a
					// thing, and snippets are where API keys and webhook
					// secrets end up. On WPCode Lite every tier collapses to
					// one capability, so this only bites where Access Control
					// deliberately drew tiers.
					$guard = minn_admin_wpcode_guard_type(
						(string) $snippet->get_code_type(),
						(int) $request['id'],
						false
					);
					if ( is_wp_error( $guard ) ) {
						return $guard;
					}
					return rest_ensure_response( minn_admin_wpcode_item( $snippet ) );
				},
			),
			array(
				'methods'             => 'PUT',
				'permission_callback' => $can_edit,
				'callback'            => function ( WP_REST_Request $request ) {
					$snippet = new WPCode_Snippet( (int) $request['id'] );
					if ( ! $snippet->get_id() ) {
						return new WP_Error( 'not_found', __( 'Snippet not found.', 'minn-admin' ), array( 'status' => 404 ) );
					}
					// The STORED type gates the edit, and when the payload
					// carries a new code_type that type must be allowed too —
					// otherwise a markup-only user retypes an HTML snippet to
					// php and authors executable code.
					// Publishing a snippet runs its code, so switching an inactive
					// one on counts as authoring here exactly as it does on the
					// dedicated /active route. Deriving this from the presence of
					// `code` alone left a hole: the editor sends `active` on every
					// save, so a body of just {"active": true} reached the write
					// below with the guard told it was not a write, and published
					// a snippet the same caller was refused permission to edit.
					//
					// Only a change counts. Re-sending active:true for a snippet
					// that is already on is what an ordinary rename does, and
					// renaming stays open.
					$activating = ! empty( $request['active'] ) && ! $snippet->is_active();
					// Promoting a live shortcode-only snippet to auto-insert
					// starts its bytes running on every request, which is the
					// same event as switching one on: the guard has to see it.
					$promoting = null !== $request['auto_insert']
						&& rest_sanitize_boolean( $request['auto_insert'] )
						&& ! (int) $snippet->get_auto_insert()
						&& $snippet->is_active();
					// Moving a live snippet into a running bucket starts its bytes
					// executing just as surely as switching it on. The guard keyed on
					// "did the request carry code?", so a body of only
					// {"location":"everywhere"} reached the write with the guard told
					// it was not a write. Activation, promotion and RETARGETING are all
					// execution without carrying code.
					$stored_loc  = (string) $snippet->get_location();
					$want_loc    = null !== $request['location'] ? sanitize_key( (string) $request['location'] ) : $stored_loc;
					$retargeting = $want_loc !== $stored_loc
						&& minn_admin_wpcode_location_executes( $want_loc )
						&& ! minn_admin_wpcode_location_executes( $stored_loc );

					$guard = minn_admin_wpcode_guard_type(
						(string) $snippet->get_code_type(),
						(int) $request['id'],
						null !== $request['code'] || $activating || $promoting || $retargeting,
						$stored_loc
					);
					if ( is_wp_error( $guard ) ) {
						return $guard;
					}
					// Resolve the submitted type ONCE, from the same accessor the
					// write below uses. $request['code_type'] resolves JSON, then
					// POST, then GET, then URL — so a guard that peeked only at
					// get_json_params() was skipped entirely by sending
					// ?code_type=php in the query string while the write still
					// picked it up. Guard and write must never read different
					// parameter sources.
					$new_type = $request['code_type'];
					if ( null !== $new_type ) {
						$new_type  = sanitize_key( (string) $new_type );
						$guard_new = minn_admin_wpcode_guard_type( $new_type, (int) $request['id'], true, $want_loc );
						if ( is_wp_error( $guard_new ) ) {
							return $guard_new;
						}
					}

					// Resolve the location against the type in force AFTER the write, so
					// a retype and a move in one request are judged together.
					$eff_loc = minn_admin_wpcode_location_in(
						$request,
						null !== $new_type ? $new_type : (string) $snippet->get_code_type(),
						$stored_loc
					);
					if ( is_wp_error( $eff_loc ) ) {
						return $eff_loc;
					}
					if ( null !== $request['code'] ) {
						$guard_code = minn_admin_wpcode_guard_code( (string) $request['code'] );
						if ( is_wp_error( $guard_code ) ) {
							return $guard_code;
						}
					}
					// Hydrate getters so load_from_array merges onto real state.
					$snippet->get_title();
					$snippet->get_code();
					$snippet->get_code_type();
					$snippet->get_location();
					$snippet->get_priority();
					$snippet->get_tags();
					$snippet->get_note();
					$snippet->is_active();
					// Location is only written when auto_insert === 1 (WPCode
					// save() gate) — force it so location edits stick.
					$snippet->get_auto_insert();

					// The type in force AFTER this write decides how the code is
					// filtered, so resolve it before touching the code: a request
					// that sends only a new type still changes what the stored
					// bytes mean.
					$eff_type = null !== $new_type ? $new_type : (string) $snippet->get_code_type();

					// Carry the stored insert method rather than forcing it on.
					// auto_insert IS the insert method in WPCode: setting it to 1 while
					// a location is present makes the snippet run everywhere, and
					// clearing it makes the snippet shortcode-only. Forcing it turned an
					// ordinary rename into a site-wide publish.
					$was_auto = (int) $snippet->get_auto_insert();
					// rest_sanitize_boolean, not ! empty(): a stringly-typed client sends
					// "false" for an off switch and ! empty( "false" ) is true, which
					// silently promoted a shortcode-only snippet on an ordinary rename.
					$want_auto = null !== $request['auto_insert']
						? (int) rest_sanitize_boolean( $request['auto_insert'] )
						: $was_auto;
					$patch = array( 'auto_insert' => $want_auto );
					if ( null !== $request['name'] ) {
						$patch['title'] = sanitize_text_field( (string) $request['name'] );
					}
					if ( null !== $request['code'] ) {
						$patch['code'] = minn_admin_wpcode_clean_code( $eff_type, (string) $request['code'] );
					} elseif ( null !== $new_type && minn_admin_wpcode_type_is_markup( $eff_type ) ) {
						// Retyping is a write even when no code is sent. Creating a
						// css snippet (never filtered, since css is not markup) and
						// then flipping it to text would otherwise promote bytes
						// into a markup context that never passed the markup rule.
						$patch['code'] = minn_admin_wpcode_clean_code( $eff_type, (string) $snippet->get_code() );
					}
					if ( null !== $new_type ) {
						$patch['code_type'] = $new_type;
					}
					if ( null !== $request['location'] ) {
						$patch['location'] = $eff_loc;
					}
					if ( null !== $request['priority'] ) {
						$patch['priority'] = (int) $request['priority'];
					}
					if ( null !== $request['tags'] ) {
						$patch['tags'] = array_map( 'sanitize_text_field', (array) $request['tags'] );
					}
					if ( null !== $request['desc'] ) {
						$patch['note'] = sanitize_textarea_field( (string) $request['desc'] );
					}
					if ( null !== $request['active'] && current_user_can( 'wpcode_activate_snippets' ) ) {
						$patch['active'] = (bool) $request['active'];
					}
					$snippet->load_from_array( $patch );
					if ( ! $snippet->save() ) {
						return new WP_Error( 'wpcode_save_failed', __( 'Could not save the snippet.', 'minn-admin' ), array( 'status' => 500 ) );
					}
					return rest_ensure_response( minn_admin_wpcode_item( new WPCode_Snippet( (int) $request['id'] ) ) );
				},
			),
			array(
				'methods'             => 'DELETE',
				'permission_callback' => $can_edit,
				'callback'            => function ( WP_REST_Request $request ) {
					$id = (int) $request['id'];
					$post = get_post( $id );
					if ( ! $post || 'wpcode' !== $post->post_type ) {
						return new WP_Error( 'not_found', __( 'Snippet not found.', 'minn-admin' ), array( 'status' => 404 ) );
					}
					// Destroying a snippet needs the same bar as editing it: the
					// stored code type's tier AND edit_post on this snippet.
					// Without it a text/markup-tier user could permanently delete
					// production PHP snippets they were never allowed to author.
					$guard = minn_admin_wpcode_guard_type( (string) ( new WPCode_Snippet( $id ) )->get_code_type(), $id, false );
					if ( is_wp_error( $guard ) ) {
						return $guard;
					}
					if ( ! current_user_can( 'delete_post', $id ) ) {
						return new WP_Error( 'forbidden', __( 'You cannot delete that snippet.', 'minn-admin' ), array( 'status' => 403 ) );
					}
					$ok = wp_delete_post( $id, true );
					if ( ! $ok ) {
						return new WP_Error( 'wpcode_delete_failed', __( 'Could not delete the snippet.', 'minn-admin' ), array( 'status' => 500 ) );
					}
					return new WP_REST_Response( null, 204 );
				},
			),
		)
	);

	register_rest_route(
		'minn-admin/v1',
		'/wpcode/snippets/(?P<id>\d+)/active',
		array(
			'methods'             => 'POST',
			'permission_callback' => $can_act,
			'callback'            => function ( WP_REST_Request $request ) {
				$snippet = new WPCode_Snippet( (int) $request['id'] );
				if ( ! $snippet->get_id() ) {
					return new WP_Error( 'not_found', __( 'Snippet not found.', 'minn-admin' ), array( 'status' => 404 ) );
				}
				// Publishing a snippet runs its code, so the stored type's tier
				// gates this too — a text-tier user must not be able to activate
				// or deactivate someone else's PHP snippet.
				$guard = minn_admin_wpcode_guard_type(
					(string) $snippet->get_code_type(),
					(int) $request['id'],
					! empty( $request['active'] )
				);
				if ( is_wp_error( $guard ) ) {
					return $guard;
				}
				if ( ! empty( $request['active'] ) ) {
					$snippet->activate();
				} else {
					$snippet->deactivate();
				}
				return rest_ensure_response( minn_admin_wpcode_item( new WPCode_Snippet( (int) $request['id'] ) ) );
			},
			'args'                => array(
				'active' => array( 'type' => 'boolean', 'required' => true ),
			),
		)
	);
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_wpcode_active() ) {
		return;
	}
	// Status card: WPCode snippets are a CPT — publish = active, everything
	// else inactive (their model, same rule the list filter uses). Code types
	// ride the _wpcode_code_type meta on active snippets.
	register_rest_route( 'minn-admin/v1', '/wpcode/status', array(
		'methods'             => 'GET',
		'permission_callback' => function () {
			return current_user_can( 'wpcode_edit_snippets' );
		},
		'callback'            => function () {
			global $wpdb;
			$counts   = wp_count_posts( 'wpcode' );
			$active   = isset( $counts->publish ) ? (int) $counts->publish : 0;
			$inactive = 0;
			foreach ( array( 'draft', 'private', 'pending' ) as $st ) {
				$inactive += isset( $counts->$st ) ? (int) $counts->$st : 0;
			}
			$rows = array(
				array(
					'label' => __( 'Active snippets', 'minn-admin' ),
					'value' => (string) $active,
					'hint'  => $inactive ? $inactive . ' inactive' : __( 'nothing inactive', 'minn-admin' ),
				),
			);
			$types = $wpdb->get_results(
				"SELECT pm.meta_value AS t, COUNT(*) AS c FROM {$wpdb->posts} p
				 JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_wpcode_code_type'
				 WHERE p.post_type = 'wpcode' AND p.post_status = 'publish'
				 GROUP BY pm.meta_value ORDER BY c DESC LIMIT 3"
			);
			if ( $types ) {
				$rows[] = array(
					'label' => __( 'Running types', 'minn-admin' ),
					'value' => implode( ' · ', array_map( function ( $t ) {
						return $t->c . ' ' . $t->t;
					}, $types ) ),
				);
			}
			$last = $wpdb->get_row(
				"SELECT post_title, post_modified FROM {$wpdb->posts}
				 WHERE post_type = 'wpcode' AND post_status IN ( 'publish', 'draft', 'private' )
				 ORDER BY post_modified DESC LIMIT 1"
			);
			if ( $last ) {
				$rows[] = array(
					'label' => __( 'Last change', 'minn-admin' ),
					'value' => (string) $last->post_title,
					'hint'  => substr( (string) $last->post_modified, 0, 10 ),
				);
			}
			return rest_ensure_response( array( 'rows' => $rows ) );
		},
	) );
} );
