<?php
/**
 * Regressions for the v0.43.0 release-candidate audit.
 *
 * Each section replays what Minn's client sends through the route it actually
 * calls, as the role the finding named, and checks what came back or what got
 * stored, with a control where a fix could over-block. Sections SKIP when their
 * plugin is inactive.
 *
 * Run: wp eval-file tests/security-v043-rc.test.php --user=admin --path=<site>
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail ? " — {$detail}" : '' );
};
$skip    = function ( $label ) {
	printf( "SKIP  %s\n", $label );
};
$summary = function () use ( &$results ) {
	printf( "\nsecurity-v043-rc: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
	exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
};
$call    = function ( $method, $route, $body = null, $params = array() ) {
	$r = new WP_REST_Request( $method, $route );
	foreach ( $params as $k => $v ) {
		$r->set_param( $k, $v );
	}
	if ( null !== $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), $res->get_data() );
};

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $admin ) {
	echo "SKIP  no administrator to run as\n";
	return;
}
$admin = (int) $admin[0]->ID;
wp_set_current_user( $admin );
$author = get_user_by( 'login', 'minn-author' );
$editor = get_user_by( 'login', 'minn-editor' );

// --- 01-01 / 06-01 /render-blocks drops a post id the caller cannot edit ---
if ( $author ) {
	$foreign = wp_insert_post( array( 'post_title' => 'Minn v043 foreign private', 'post_status' => 'private', 'post_author' => $admin ) );
	wp_set_current_user( $author->ID );
	$own    = wp_insert_post( array( 'post_title' => 'Minn v043 own draft', 'post_status' => 'draft', 'post_author' => $author->ID ) );
	$seen   = null;
	$spy    = function ( $blocks, $pid ) use ( &$seen ) {
		$seen = $pid;
	};
	$markup = array( '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->' );
	add_action( 'minn_admin_before_render_blocks', $spy, 1, 2 );
	list( $status ) = $call( 'POST', '/minn-admin/v1/render-blocks', array( 'blocks' => $markup, 'post' => $foreign ) );
	$check( 'render-blocks: an Author naming an admin\'s private post renders without its id', 0 === $seen, "status {$status}, hooks saw " . var_export( $seen, true ) );
	$seen = null;
	$call( 'POST', '/minn-admin/v1/render-blocks', array( 'blocks' => $markup, 'post' => $own ) );
	$check( 'render-blocks: the Author\'s own draft still reaches the hooks (control)', $own === $seen, 'hooks saw ' . var_export( $seen, true ) );
	remove_action( 'minn_admin_before_render_blocks', $spy, 1 );
	wp_set_current_user( $admin );
	wp_delete_post( $foreign, true );
	wp_delete_post( $own, true );
} else {
	$skip( 'render-blocks: no minn-author account' );
}

// --- 01-02 Zip uploads ask core's upload caps, not just install caps -----
// A host that keeps installs to wordpress.org denies upload_plugins and
// upload_themes while leaving install_* alone; core's upload screens honour
// that. Outbound HTTP is refused so the URL install never downloads.
$deny_upload = function ( $caps, $cap ) {
	return in_array( $cap, array( 'upload_plugins', 'upload_themes' ), true ) ? array( 'do_not_allow' ) : $caps;
};
$no_http     = function () {
	return new WP_Error( 'minn_v043_offline', 'offline' );
};
add_filter( 'pre_http_request', $no_http );
add_filter( 'map_meta_cap', $deny_upload, 10, 2 );
$routes = array(
	'plugin upload' => array( '/minn-admin/v1/plugins/upload', null ),
	'theme upload'  => array( '/minn-admin/v1/themes/upload', null ),
	'URL install'   => array( '/minn-admin/v1/plugins/install-url', array( 'url' => 'https://github.com/example/example/archive/refs/heads/main.zip' ) ),
);
foreach ( $routes as $label => $r ) {
	list( $st ) = $call( 'POST', $r[0], $r[1] );
	$check( "{$label}: refused where the host denies uploads", 403 === $st, "status {$st}" );
}
remove_filter( 'map_meta_cap', $deny_upload, 10 );
list( $st ) = $call( 'POST', '/minn-admin/v1/plugins/upload' );
$check( 'plugin upload: an administrator still reaches it (control)', 403 !== $st, "status {$st}" );
remove_filter( 'pre_http_request', $no_http );

// --- 02-core-other-01 Maintenance mode holds front-end form posts --------
// CF7 (parse_request 20) and Gravity Forms (wp 9) process a POST to any page
// before template_redirect paints the holding page. The hold sits on
// parse_request 15 so WordPress's own order supplies the exemptions (review
// N1-N3: guessing them from the URL at init locked admins out of a moved
// login screen and held WooCommerce's /wc-api/ callbacks).
$m_was    = get_option( 'minn_admin_maintenance' );
$m_server = $_SERVER;
$m_held   = function ( $fn, $method, $script = '/index.php' ) {
	$_SERVER['REQUEST_METHOD'] = $method;
	$_SERVER['SCRIPT_NAME']    = $script;
	$thrower = function () {
		return function () {
			throw new Exception( 'held' );
		};
	};
	add_filter( 'wp_die_handler', $thrower );
	try {
		call_user_func( array( 'Minn_Admin', $fn ) );
		$held = false;
	} catch ( Exception $e ) {
		$held = true;
	}
	remove_filter( 'wp_die_handler', $thrower );
	return $held;
};
update_option( 'minn_admin_maintenance', 1 );
wp_set_current_user( 0 );
$check( 'maintenance: a visitor\'s form POST to a page is held', method_exists( 'Minn_Admin', 'maintenance_front_post' ) && $m_held( 'maintenance_front_post', 'POST' ) );
$check( 'maintenance: a visitor\'s GET is left to the holding page (control)', method_exists( 'Minn_Admin', 'maintenance_front_post' ) && ! $m_held( 'maintenance_front_post', 'GET' ) );
$check( 'maintenance: the init hold leaves a POST to index.php alone, so a moved login screen still signs in', ! $m_held( 'maintenance_admin_entry', 'POST' ) );
$prio = has_action( 'parse_request', array( 'Minn_Admin', 'maintenance_front_post' ) );
$check( 'maintenance: the hold runs after REST is served and before Contact Form 7', is_int( $prio ) && $prio > (int) has_action( 'parse_request', 'rest_api_loaded' ) && $prio < 20, 'priority ' . var_export( $prio, true ) );
if ( class_exists( '\Automattic\WooCommerce\Internal\Utilities\LegacyRestApiStub' ) ) {
	$wc_prio = has_action( 'parse_request', array( 'Automattic\WooCommerce\Internal\Utilities\LegacyRestApiStub', 'parse_legacy_rest_api_request' ) );
	$check( 'maintenance: WooCommerce payment callbacks are served before the hold', is_int( $wc_prio ) && is_int( $prio ) && $wc_prio < $prio, 'wc ' . var_export( $wc_prio, true ) );
}
if ( $editor ) {
	wp_set_current_user( $editor->ID );
	$check( 'maintenance: an editor\'s POST passes (control)', ! $m_held( 'maintenance_front_post', 'POST' ) );
}
wp_set_current_user( $admin );
$_SERVER = $m_server;
update_option( 'minn_admin_maintenance', $m_was );

// --- 02-core-other-02 / -03 Post type manager respects the vendor's store --
if ( class_exists( 'Minn_Admin_CPT' ) && function_exists( 'cptui_get_post_type_data' ) ) {
	$pt  = 'mv043t' . wp_rand( 10, 99 );
	$tx  = 'mv043x' . wp_rand( 10, 99 );
	$sup = array( 'title', 'editor' );
	list( $st ) = $call( 'POST', '/minn-admin/v1/post-types', array( 'slug' => $pt, 'singular' => 'Thing', 'plural' => 'Things', 'public' => true, 'show_in_rest' => true, 'supports' => $sup, 'taxonomies' => array(), 'backend' => 'cptui' ) );
	register_post_type( $pt, array( 'public' => true, 'label' => 'Things', 'supports' => $sup ) ); // CPT UI registers on init
	// What CPT UI's own screen can add and Minn's modal can't show.
	$o                         = (array) get_option( 'cptui_post_types', array() );
	$o[ $pt ]['supports'][]    = 'post-formats';
	update_option( 'cptui_post_types', $o );
	add_post_type_support( $pt, 'post-formats' );
	list( $st2 ) = $call( 'POST', "/minn-admin/v1/post-types/{$pt}", array( 'singular' => 'Thing 2', 'plural' => 'Things 2', 'public' => true, 'show_in_rest' => true, 'supports' => $sup, 'taxonomies' => array() ) );
	$o = (array) get_option( 'cptui_post_types', array() );
	$check( 'post type edit: a label save keeps Post Formats support', 200 === $st2 && in_array( 'post-formats', (array) ( $o[ $pt ]['supports'] ?? array() ), true ), "create {$st}, update {$st2}, supports " . implode( ',', (array) ( $o[ $pt ]['supports'] ?? array() ) ) );
	list( $st ) = $call( 'POST', '/minn-admin/v1/taxonomies', array( 'slug' => $tx, 'singular' => 'Topic', 'plural' => 'Topics', 'public' => true, 'show_in_rest' => true, 'object_types' => array( 'post' ), 'backend' => 'cptui' ) );
	register_taxonomy( $tx, 'post', array( 'public' => true, 'label' => 'Topics' ) );
	$t                          = (array) get_option( 'cptui_taxonomies', array() );
	$t[ $tx ]['object_types'][] = 'attachment';
	update_option( 'cptui_taxonomies', $t );
	register_taxonomy_for_object_type( $tx, 'attachment' );
	list( $st2 ) = $call( 'POST', "/minn-admin/v1/taxonomies/{$tx}", array( 'singular' => 'Topic 2', 'plural' => 'Topics 2', 'public' => true, 'show_in_rest' => true, 'object_types' => array( 'post' ) ) );
	$t = (array) get_option( 'cptui_taxonomies', array() );
	$check( 'taxonomy edit: a label save keeps it attached to Media', 200 === $st2 && in_array( 'attachment', (array) ( $t[ $tx ]['object_types'] ?? array() ), true ), "create {$st}, update {$st2}" );
	$call( 'DELETE', "/minn-admin/v1/post-types/{$pt}" );
	$call( 'DELETE', "/minn-admin/v1/taxonomies/{$tx}" );
} else {
	$skip( 'post type manager: CPT UI inactive' );
}
if ( class_exists( 'Minn_Admin_CPT' ) && function_exists( 'acf_get_setting' ) && acf_get_setting( 'enable_post_types' ) ) {
	$apt = 'mv043a' . wp_rand( 10, 99 );
	list( $st ) = $call( 'POST', '/minn-admin/v1/post-types', array( 'slug' => $apt, 'singular' => 'Gadget', 'plural' => 'Gadgets', 'public' => true, 'show_in_rest' => true, 'supports' => array( 'title' ), 'taxonomies' => array(), 'backend' => 'acf' ) );
	register_post_type( $apt, array( 'public' => true, 'label' => 'Gadgets' ) );
	$lock = '__return_false';
	add_filter( 'acf/settings/show_admin', $lock );
	list( $st2 ) = $call( 'POST', "/minn-admin/v1/post-types/{$apt}", array( 'singular' => 'Gadget 2', 'plural' => 'Gadgets 2', 'public' => true, 'show_in_rest' => true, 'supports' => array( 'title' ), 'taxonomies' => array() ) );
	list( $st3 ) = $call( 'DELETE', "/minn-admin/v1/post-types/{$apt}" );
	list( , $list ) = $call( 'GET', '/minn-admin/v1/post-types' );
	$check( 'ACF locked away: its post type can\'t be edited or deleted in Minn', 200 !== $st2 && 200 !== $st3, "create {$st}, update {$st2}, delete {$st3}" );
	$check( 'ACF locked away: Minn won\'t create new ACF types', ! in_array( 'acf', (array) ( $list['backends'] ?? array() ), true ) );
	remove_filter( 'acf/settings/show_admin', $lock );
	list( $st4 ) = $call( 'DELETE', "/minn-admin/v1/post-types/{$apt}" );
	$check( 'ACF unlocked: an administrator can still delete it (control)', 200 === $st4, "status {$st4}" );
	foreach ( (array) get_posts( array( 'post_type' => 'acf-post-type', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $pid ) {
		if ( false !== strpos( (string) get_post_field( 'post_content', $pid ), $apt ) ) {
			wp_delete_post( $pid, true );
		}
	}
} else {
	$skip( 'post type manager: ACF post types unavailable' );
}

// --- 07-01 Store settings: an Emails save keeps theme auto-sync as stored --
if ( class_exists( 'WooCommerce' ) && function_exists( 'minn_admin_wc_settings_map_field' ) ) {
	$sync_was = get_option( 'woocommerce_email_auto_sync_with_theme', null );
	$from_was = get_option( 'woocommerce_email_from_name' );
	update_option( 'woocommerce_email_auto_sync_with_theme', 'yes' );
	list( $st ) = $call( 'POST', '/minn-admin/v1/wc/settings/email/default', array( 'values' => array( 'woocommerce_email_from_name' => 'Minn v043 Store' ) ) );
	wp_cache_delete( 'alloptions', 'options' );
	$check( 'store settings: an Emails save leaves theme auto-sync on', 'yes' === get_option( 'woocommerce_email_auto_sync_with_theme' ), "status {$st}, now " . var_export( get_option( 'woocommerce_email_auto_sync_with_theme' ), true ) );
	list( $st ) = $call( 'POST', '/minn-admin/v1/wc/settings/email/default', array( 'values' => array( 'woocommerce_email_auto_sync_with_theme' => false ) ) );
	wp_cache_delete( 'alloptions', 'options' );
	$check( 'store settings: the auto-sync switch can turn it off', 'no' === get_option( 'woocommerce_email_auto_sync_with_theme' ), "status {$st}, now " . var_export( get_option( 'woocommerce_email_auto_sync_with_theme' ), true ) );
	// 07-02: a gateway / email / shipping method's hidden form field is
	// posted back with its stored value, as WooCommerce's own form does.
	$gw   = new class() {
		public $id = 'minn_v043_gw';
		public function get_field_key( $k ) {
			return 'woocommerce_minn_v043_gw_' . $k;
		}
	};
	$post = minn_admin_wc_settings_api_post_data(
		$gw,
		array( 'enabled' => true ),
		array( 'enabled' => array( 'type' => 'checkbox', 'title' => 'Enabled' ), 'webhook_id' => array( 'type' => 'hidden' ) ),
		function ( $k, $d ) {
			return 'webhook_id' === $k ? 'wh_minn_v043' : $d;
		}
	);
	$check( 'store settings: a gateway\'s hidden field is posted back unchanged', 'wh_minn_v043' === ( $post['woocommerce_minn_v043_gw_webhook_id'] ?? null ), wp_json_encode( $post ) );
	null === $sync_was ? delete_option( 'woocommerce_email_auto_sync_with_theme' ) : update_option( 'woocommerce_email_auto_sync_with_theme', $sync_was );
	update_option( 'woocommerce_email_from_name', $from_was );
} else {
	$skip( 'store settings: WooCommerce inactive' );
}

// --- 06-02 ACPT repeater rows keep their checkbox selections ---------------
if ( function_exists( 'minn_admin_acpt_rows_in' ) && function_exists( 'get_acpt_field' ) ) {
	$opt  = function ( $v ) {
		return new class( $v ) {
			private $v;
			public function __construct( $v ) {
				$this->v = $v;
			}
			public function getValue() {
				return $this->v;
			}
			public function getLabel() {
				return ucfirst( $this->v );
			}
		};
	};
	$kid  = function ( $name, $type, $options ) {
		return new class( $name, $type, $options ) {
			private $n;
			private $t;
			private $o;
			public function __construct( $n, $t, $o ) {
				$this->n = $n;
				$this->t = $t;
				$this->o = $o;
			}
			public function getType() {
				return $this->t;
			}
			public function getName() {
				return $this->n;
			}
			public function getLabelOrName() {
				return $this->n;
			}
			public function getOptions() {
				return $this->o;
			}
			public function userPermissions() {
				return array( 'read' => true, 'edit' => true );
			}
		};
	};
	$rep  = new class( array( $kid( 'title', 'Text', array() ), $kid( 'tags', 'Checkbox', array( $opt( 'red' ), $opt( 'blue' ) ) ) ) ) {
		private $c;
		public function __construct( $c ) {
			$this->c = $c;
		}
		public function getType() {
			return 'Repeater';
		}
		public function getChildren() {
			return $this->c;
		}
		public function getId() {
			return 'minn_v043_rep';
		}
		public function getLabelOrName() {
			return 'Rows';
		}
	};
	$rows = minn_admin_acpt_rows_in( $rep, array( array( 'values' => array( 'title' => 'One', 'tags' => array( 'red', 'blue' ) ) ) ), array( 'post_id' => 1, 'box_name' => 'minn_v043_box', 'field_name' => 'minn_v043_rep' ) );
	$check( 'ACPT: a repeater row keeps its checkbox selections', array( 'red', 'blue' ) === ( $rows[0]['tags'] ?? null ), wp_json_encode( $rows ) );
	$check( 'ACPT: a text sub-field still saves (control)', 'One' === ( $rows[0]['title'] ?? null ) );
} else {
	$skip( 'ACPT inactive' );
}

// --- 03-01 / 03-02 License providers: pinned keys and actions that can't verify --
if ( function_exists( 'minn_admin_license_default_providers' ) ) {
	$lp = apply_filters( 'minn_admin_license_providers', minn_admin_license_default_providers() );
	if ( class_exists( 'GFFormsModel' ) && isset( $lp['gravityforms']['activate'] ) ) {
		$check( 'licenses: Gravity Forms honours a key pinned in wp-config (GF_LICENSE_KEY)', in_array( 'GF_LICENSE_KEY', (array) ( $lp['gravityforms']['key_constant'] ?? array() ), true ) );
	} else {
		$skip( 'licenses: Gravity Forms actions not loaded' );
	}
	if ( class_exists( 'ET_Core_Updates' ) ) {
		$check( 'licenses: Divi offers no activate or verify that cannot reach Elegant Themes', empty( $lp['divi']['activate'] ) && empty( $lp['divi']['verify'] ) && ! empty( $lp['divi']['activate_url'] ) );
	} else {
		$skip( 'licenses: Divi / Elegant Themes not loaded' );
	}
}

// A throwaway account holding exactly the caps given, on top of Subscriber.
$temp_users = array();
$temp_user  = function ( $login, $caps ) use ( &$temp_users ) {
	$existing = get_user_by( 'login', $login );
	if ( $existing ) {
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $existing->ID );
	}
	$id = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password( 24 ), 'user_email' => $login . '@example.com', 'role' => 'subscriber' ) );
	$u  = new WP_User( $id );
	foreach ( $caps as $cap ) {
		$u->add_cap( $cap );
	}
	$temp_users[] = $id;
	return $id;
};

// --- 04-01 / 04-03 Gravity Forms previews need the entry-view capability ---
if ( class_exists( 'GFAPI' ) && function_exists( 'minn_admin_gfn_latest_entry' ) ) {
	$fid  = GFAPI::add_form( array(
		'title'         => 'Minn v043 GF ' . time(),
		'fields'        => array( array( 'id' => 1, 'type' => 'text', 'label' => 'Name' ) ),
		'notifications' => array( 'mv043' => array( 'id' => 'mv043', 'name' => 'Admin', 'event' => 'form_submission', 'toType' => 'email', 'to' => '{admin_email}', 'subject' => 'New', 'message' => '{all_fields}', 'isActive' => true ) ),
	) );
	GFAPI::add_entry( array( 'form_id' => $fid, '1' => 'MinnSecretGF' ) );
	$form    = GFAPI::get_form( $fid );
	$cid     = (string) key( (array) $form['confirmations'] );
	$private = wp_insert_post( array( 'post_title' => 'Minn v043 private page', 'post_name' => 'minn-v043-private-slug', 'post_type' => 'page', 'post_status' => 'private', 'post_author' => $admin ) );
	$gf_ed   = $temp_user( 'minn-v043-gf-editor', array( 'gravityforms_edit_forms' ) );
	$sent    = 0;
	$abort   = function ( $email ) use ( &$sent ) {
		++$sent;
		$email['abort_email'] = true;
		return $email;
	};
	add_filter( 'gform_pre_send_email', $abort );
	$preview = function () use ( $call, $fid ) {
		return $call( 'POST', "/minn-admin/v1/gf/notifications/{$fid}:mv043/preview", array( 'subject' => 'New', 'message' => '{all_fields}' ) );
	};
	$confirm = function ( $body ) use ( $call, $fid, $cid ) {
		return $call( 'POST', "/minn-admin/v1/gf/confirmations/{$fid}:{$cid}/preview", $body );
	};

	wp_set_current_user( $gf_ed );
	list( $st, $d ) = $preview();
	$check( 'GF notification preview: an edit-forms-only user gets no entry', 200 === $st && false === strpos( (string) wp_json_encode( $d ), 'MinnSecretGF' ), "status {$st}" );
	list( $st, $d ) = $call( 'GET', "/minn-admin/v1/gf/notifications/{$fid}:mv043/full" );
	$check( 'GF notification page: no latest entry id for that user', 0 === (int) ( $d['form']['latestEntry'] ?? -1 ), "status {$st}" );
	list( $st, $d ) = $confirm( array( 'type' => 'message', 'message' => '{Name:1}' ) );
	$check( 'GF confirmation preview: no entry for that user', 200 === $st && false === strpos( (string) wp_json_encode( $d ), 'MinnSecretGF' ), "status {$st}" );
	$sent = 0;
	list( $st ) = $call( 'POST', "/minn-admin/v1/gf/notifications/{$fid}:mv043/test", array( 'subject' => 'New', 'message' => '{all_fields}' ) );
	$check( 'GF test send: refused for that user, nothing sent', $st >= 400 && 0 === $sent, "status {$st}, sent {$sent}" );
	list( $st, $d ) = $confirm( array( 'type' => 'page', 'pageId' => $private ) );
	$check( 'GF confirmation preview: no address for a page the user cannot read', '' === (string) ( $d['url'] ?? '' ), 'url ' . ( $d['url'] ?? '' ) );

	wp_set_current_user( $admin );
	list( $st, $d ) = $preview();
	$check( 'GF notification preview: an administrator still sees the latest entry (control)', false !== strpos( (string) wp_json_encode( $d ), 'MinnSecretGF' ), "status {$st}" );
	list( $st, $d ) = $confirm( array( 'type' => 'message', 'message' => '{Name:1}' ) );
	$check( 'GF confirmation preview: an administrator still sees it (control)', false !== strpos( (string) wp_json_encode( $d ), 'MinnSecretGF' ), "status {$st}" );
	remove_filter( 'gform_pre_send_email', $abort );
	GFAPI::delete_form( $fid );
	wp_delete_post( $private, true );
} else {
	$skip( 'Gravity Forms previews: Gravity Forms inactive' );
}

// --- 01-03 Replying to a submitter needs more than reading entries -------
// Anyone can put an address in an entry by submitting the form, so a reply
// From the site to that address asks for the vendor's notes cap (Gravity
// Forms' own "email this note") or manage_options, not the view floor.
if ( class_exists( 'GFAPI' ) ) {
	$rf  = GFAPI::add_form( array(
		'title'  => 'Minn v043 reply ' . time(),
		'fields' => array( array( 'id' => 1, 'type' => 'text', 'label' => 'Name' ), array( 'id' => 2, 'type' => 'email', 'label' => 'Email' ) ),
	) );
	$re  = GFAPI::add_entry( array( 'form_id' => $rf, '1' => 'Reply Target', '2' => 'minn-v043-reply@example.com' ) );
	$rv  = $temp_user( 'minn-v043-gf-viewer', array( 'edit_posts', 'gravityforms_view_entries' ) );
	$rn  = $temp_user( 'minn-v043-gf-notes', array( 'edit_posts', 'gravityforms_view_entries', 'gravityforms_edit_entry_notes' ) );
	$mails = 0;
	$count = function ( $atts ) use ( &$mails ) {
		++$mails;
		return $atts;
	};
	add_filter( 'wp_mail', $count );
	wp_set_current_user( $rv );
	list( $st ) = $call( 'POST', '/minn-admin/v1/entries/reply', array( 'surface' => 'gravity-forms', 'id' => (string) $re, 'to' => 'minn-v043-reply@example.com', 'subject' => 'Hello', 'message' => 'From the site' ) );
	$check( 'entry reply: a view-only user cannot email the submitter', 403 === $st && 0 === $mails, "status {$st}, mails {$mails}" );
	remove_filter( 'wp_mail', $count );
	if ( method_exists( 'Minn_Admin_REST', 'can_reply_to_entries' ) ) {
		$gs = Minn_Admin_Surfaces::all()['gravity-forms'] ?? array();
		wp_set_current_user( $rn );
		$check( 'entry reply: a user with Gravity Forms\' notes cap may (control)', Minn_Admin_REST::can_reply_to_entries( $gs ) );
		wp_set_current_user( $admin );
		$check( 'entry reply: an administrator may (control)', Minn_Admin_REST::can_reply_to_entries( $gs ) );
	}
	wp_set_current_user( $admin );
	GFAPI::delete_form( $rf );
} else {
	$skip( 'entry reply: Gravity Forms inactive' );
}

// --- 04-01 WPForms emails preview needs the entry-view capability --------
if ( function_exists( 'wpforms' ) && function_exists( 'wpforms_current_user_can' ) && class_exists( '\WPForms\Pro\Access\Capabilities' ) ) {
	$wf_id = wpforms()->obj( 'form' )->add( 'Minn v043 WPForms ' . time(), array(), array( 'template' => 'simple-contact-form-template' ) );
	$wf_e  = wpforms()->obj( 'entry' )->add( array( 'form_id' => $wf_id, 'status' => '', 'fields' => wp_json_encode( array( 3 => array( 'name' => 'Comment or Message', 'value' => 'MinnSecretWPF', 'id' => 3, 'type' => 'textarea' ) ) ) ) );
	$wf_ed = $temp_user( 'minn-v043-wpf-editor', array( 'wpforms_view_others_forms', 'wpforms_edit_others_forms' ) );
	$wf_pv = function () use ( $call, $wf_id ) {
		return $call( 'POST', "/minn-admin/v1/wpforms/forms/{$wf_id}/emails/preview", array( 'key' => '1', 'subject' => 'New', 'message' => '{all_fields}' ) );
	};
	// WPForms applies its granular caps only in wp-admin; under REST every
	// check falls back to its manage cap. Grant edit (never entries) the way
	// Access Controls would, so the role split is the one the finding names.
	$wf_edit = function ( $can, $caps ) {
		return 'edit_form_single' === $caps ? true : $can;
	};
	add_filter( 'wpforms_current_user_can', $wf_edit, 10, 2 );
	wp_set_current_user( $wf_ed );
	list( $st, $d ) = $wf_pv();
	$check( 'WPForms preview: an edit-forms-only user gets no entry', 200 === $st && false === strpos( (string) wp_json_encode( $d ), 'MinnSecretWPF' ), "status {$st}" );
	list( $st, $d ) = $call( 'GET', "/minn-admin/v1/wpforms/forms/{$wf_id}/emails" );
	$check( 'WPForms page: no latest entry id for that user', 200 === $st && 0 === (int) ( $d['latest'] ?? -1 ), "status {$st}" );
	remove_filter( 'wpforms_current_user_can', $wf_edit, 10 );
	wp_set_current_user( $admin );
	list( $st, $d ) = $wf_pv();
	$check( 'WPForms preview: an administrator still sees the latest entry (control)', false !== strpos( (string) wp_json_encode( $d ), 'MinnSecretWPF' ), "status {$st}" );
	// 04-02: the Forms view counts only forms whose entries you may read.
	// Its own form: wpforms_current_user_can() caches each answer per user,
	// cap and form, so one the checks above already asked about would stick.
	$wf_id2 = wpforms()->obj( 'form' )->add( 'Minn v043 WPForms count ' . time(), array(), array( 'template' => 'simple-contact-form-template' ) );
	$wf_e2  = wpforms()->obj( 'entry' )->add( array( 'form_id' => $wf_id2, 'status' => '', 'fields' => wp_json_encode( array() ) ) );
	$no_entries = function ( $can, $caps, $id ) use ( $wf_id2 ) {
		return 'view_entries_form_single' === $caps && (int) $id === (int) $wf_id2 ? false : $can;
	};
	add_filter( 'wpforms_current_user_can', $no_entries, 10, 3 );
	list( $st, $d ) = $call( 'GET', '/minn-admin/v1/wpforms/forms', null, array( 'manage' => 1 ) );
	$wf_row = current( array_filter( (array) ( $d['items'] ?? array() ), function ( $r ) use ( $wf_id2 ) {
		return (int) ( $r['id'] ?? 0 ) === (int) $wf_id2;
	} ) );
	$check( 'WPForms Forms view: no entry count for a form whose entries you can\'t read', is_array( $wf_row ) && null === $wf_row['entries'], is_array( $wf_row ) ? 'entries ' . var_export( $wf_row['entries'], true ) : 'row missing' );
	remove_filter( 'wpforms_current_user_can', $no_entries, 10 );
	wpforms()->obj( 'entry' )->delete( $wf_e2 );
	wp_delete_post( $wf_id2, true );
	wpforms()->obj( 'entry' )->delete( $wf_e );
	wp_delete_post( $wf_id, true );
} else {
	$skip( 'WPForms preview: WPForms Pro inactive' );
}

// --- 05-01 CF7 mail preview needs Flamingo's read capability -------------
if ( function_exists( 'wpcf7_save_contact_form' ) && class_exists( 'Flamingo_Inbound_Message' ) && $editor ) {
	$cf = wpcf7_save_contact_form( array( 'title' => 'Minn v043 CF7 ' . time() ) );
	$cf = wpcf7_contact_form( $cf->id() );
	$tx = Flamingo_Inbound_Message::channel_taxonomy;
	$pt = term_exists( 'contact-form-7', $tx );
	$pt = $pt ? $pt : wp_insert_term( 'Contact Form 7', $tx, array( 'slug' => 'contact-form-7' ) );
	wp_insert_term( $cf->title(), $tx, array( 'slug' => $cf->name(), 'parent' => (int) $pt['term_id'] ) );
	$msg   = Flamingo_Inbound_Message::add( array( 'channel' => $cf->name(), 'subject' => 'x', 'from' => 'x <x@example.com>', 'fields' => array( 'your-name' => 'MinnSecretCF7', 'your-message' => 'MinnSecretCF7' ) ) );
	$cf_pv = function () use ( $call, $cf ) {
		return $call( 'POST', '/minn-admin/v1/cf7/forms/' . $cf->id() . '/mail/preview', array( 'which' => 'mail', 'subject' => '[your-name]', 'body' => '[your-name] [your-message]' ) );
	};
	wp_set_current_user( $editor->ID );
	list( $st, $d ) = $cf_pv();
	$check( 'CF7 preview: an Editor (no Flamingo access) gets no submission', false === strpos( (string) wp_json_encode( $d ), 'MinnSecretCF7' ), "status {$st}" );
	list( $st, $d ) = $call( 'GET', '/minn-admin/v1/cf7/forms/' . $cf->id() . '/mail' );
	$check( 'CF7 page: no latest message id for an Editor', 200 === $st && 0 === (int) ( $d['latest'] ?? -1 ), "status {$st}" );
	wp_set_current_user( $admin );
	list( $st, $d ) = $cf_pv();
	$check( 'CF7 preview: an administrator still sees the latest submission (control)', false !== strpos( (string) wp_json_encode( $d ), 'MinnSecretCF7' ), "status {$st}" );
	if ( $msg ) {
		wp_delete_post( $msg->id(), true );
	}
	$t = get_term_by( 'slug', $cf->name(), $tx );
	if ( $t ) {
		wp_delete_term( $t->term_id, $tx );
	}
	wp_delete_post( $cf->id(), true );
} else {
	$skip( 'CF7 preview: CF7, Flamingo or minn-editor missing' );
}

// --- 05-02 A CF7 mail save leaves other panels' settings alone ----------
// CF7's Brevo module and AnalyticsWP rebuild their per-form settings from the
// editor's $_POST on wpcf7_save_contact_form; Minn's JSON save has none, so
// firing that action blanked them. The spy listener has the same shape.
if ( function_exists( 'wpcf7_save_contact_form' ) ) {
	$cf  = wpcf7_save_contact_form( array( 'title' => 'Minn v043 CF7 panels ' . time() ) );
	$cid = (int) $cf->id();
	update_post_meta( $cid, '_minn_v043_panel', 'yes' );
	$panel = function ( $form ) {
		update_post_meta( $form->id(), '_minn_v043_panel', isset( $_POST['minn_v043_panel'] ) ? 'yes' : 'no' ); // phpcs:ignore WordPress.Security.NonceVerification
	};
	add_action( 'wpcf7_save_contact_form', $panel );
	$mail            = (array) wpcf7_contact_form( $cid )->prop( 'mail' );
	$mail['subject'] = 'Minn v043 subject';
	list( $st ) = $call( 'POST', "/minn-admin/v1/cf7/forms/{$cid}/mail", array( 'mail' => $mail ) );
	remove_action( 'wpcf7_save_contact_form', $panel );
	$stored = (array) wpcf7_contact_form( $cid )->prop( 'mail' );
	$check( 'CF7 mail save: the subject is saved (control)', 200 === $st && 'Minn v043 subject' === ( $stored['subject'] ?? '' ), "status {$st}" );
	$check( 'CF7 mail save: a panel that saves from the editor\'s form keeps its setting', 'yes' === get_post_meta( $cid, '_minn_v043_panel', true ), 'now ' . get_post_meta( $cid, '_minn_v043_panel', true ) );
	wp_delete_post( $cid, true );
} else {
	$skip( 'CF7 mail save: Contact Form 7 inactive' );
}

// --- 05-03 Fluent Forms preview needs the entries-viewer permission ------
if ( function_exists( 'wpFluent' ) && class_exists( '\FluentForm\App\Modules\Acl\Acl' ) ) {
	global $wpdb;
	$p   = $wpdb->prefix;
	$src = $wpdb->get_var( "SELECT form_fields FROM {$p}fluentform_forms ORDER BY id ASC LIMIT 1" );
	$wpdb->insert( "{$p}fluentform_forms", array( 'title' => 'Minn v043 Fluent', 'status' => 'published', 'type' => 'form', 'form_fields' => (string) $src, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
	$ff_id = (int) $wpdb->insert_id;
	$wpdb->insert( "{$p}fluentform_form_meta", array( 'form_id' => $ff_id, 'meta_key' => 'notifications', 'value' => wp_json_encode( array( 'name' => 'Admin email', 'sendTo' => array( 'type' => 'email', 'email' => '{wp.admin_email}', 'field' => '', 'routing' => array() ), 'subject' => 'New', 'message' => '<p>{all_data}</p>', 'enabled' => true ) ) ) );
	$wpdb->insert( "{$p}fluentform_submissions", array( 'form_id' => $ff_id, 'serial_number' => 1, 'response' => wp_json_encode( array( 'names' => 'MinnSecretFF', 'message' => 'MinnSecretFF' ) ), 'status' => 'unread', 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
	$ff_ed = $temp_user( 'minn-v043-ff-manager', array( 'fluentform_forms_manager' ) );
	update_user_meta( $ff_ed, '_fluent_forms_has_role', 1 );
	$ff_pv = function () use ( $call, $ff_id ) {
		return $call( 'POST', "/minn-admin/v1/fluent-forms/forms/{$ff_id}/emails/preview", array( 'subject' => 'New', 'message' => '{inputs.names} {all_data}' ) );
	};
	wp_set_current_user( $ff_ed );
	list( $st, $d ) = $ff_pv();
	$check( 'Fluent preview: a forms manager without View Entries gets no entry', false === strpos( (string) wp_json_encode( $d ), 'MinnSecretFF' ), "status {$st}" );
	wp_set_current_user( $admin );
	list( $st, $d ) = $ff_pv();
	$check( 'Fluent preview: an administrator still sees the latest entry (control)', false !== strpos( (string) wp_json_encode( $d ), 'MinnSecretFF' ), "status {$st}" );
	$wpdb->delete( "{$p}fluentform_submissions", array( 'form_id' => $ff_id ) );
	$wpdb->delete( "{$p}fluentform_form_meta", array( 'form_id' => $ff_id ) );
	$wpdb->delete( "{$p}fluentform_forms", array( 'id' => $ff_id ) );
} else {
	$skip( 'Fluent preview: Fluent Forms inactive' );
}

// --- 08-01 / 08-02 Custom CSS & JS writes keep the vendor's tree whole -------
// CCJ builds its tree only from wp-admin, so Minn's writes rebuild it: the
// jQuery flag, the block-editor bundles and external cache-busters must
// survive, and a write may only touch the file of the snippet it changed.
if ( defined( 'CCJ_UPLOAD_DIR' ) && function_exists( 'minn_admin_ccj_rebuild_tree' ) ) {
	$tree_was  = get_option( 'custom-css-js-tree', array() );
	$bundles   = array();
	foreach ( array( 'block_js.js', 'block_css.css' ) as $f ) {
		$bundles[ $f ] = is_file( CCJ_UPLOAD_DIR . '/' . $f ) ? file_get_contents( CCJ_UPLOAD_DIR . '/' . $f ) : null;
	}
	$made = array();
	$make = function ( $args ) use ( $call, &$made ) {
		list( $st, $d ) = $call( 'POST', '/minn-admin/v1/ccj/snippets', array_merge( array( 'type' => 'header', 'linking' => 'internal', 'side' => 'frontend', 'priority' => 5, 'active' => true ), $args ) );
		$made[] = (int) ( $d['id'] ?? 0 );
		return (int) ( $d['id'] ?? 0 );
	};
	$tree = function () {
		wp_cache_delete( 'custom-css-js-tree', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		return (array) get_option( 'custom-css-js-tree', array() );
	};
	$jq = $make( array( 'name' => 'Minn v043 jq', 'language' => 'js', 'code' => 'jQuery( function () { window.minnV043 = 1; } );' ) );
	$check( 'CCJ: a front-end jQuery snippet keeps CCJ enqueuing jQuery', ! empty( $tree()['jquery'] ), 'tree keys ' . implode( ',', array_keys( $tree() ) ) );
	$ext   = $make( array( 'name' => 'Minn v043 ext', 'language' => 'css', 'linking' => 'external', 'code' => '.minn-v043-ext{color:red}' ) );
	$files = (array) ( $tree()['frontend-css-header-external'] ?? array() );
	$check( 'CCJ: an external file carries its cache-buster', (bool) preg_grep( '/^' . $ext . '\.css\?v=\d+$/', $files ), implode( ',', $files ) );
	$ba = $make( array( 'name' => 'Minn v043 block A', 'language' => 'js', 'side' => 'block', 'code' => 'window.minnV043BlockA = 1;' ) );
	$bb = $make( array( 'name' => 'Minn v043 block B', 'language' => 'js', 'side' => 'block', 'code' => 'window.minnV043BlockB = 1;' ) );
	$bundle = (string) @file_get_contents( CCJ_UPLOAD_DIR . '/block_js.js' );
	$check( 'CCJ: block-side snippets reach the block editor bundle', false !== strpos( $bundle, 'minnV043BlockA' ) && false !== strpos( $bundle, 'minnV043BlockB' ) );
	$call( 'POST', "/minn-admin/v1/ccj/snippets/{$ba}/active", array( 'active' => false ) );
	$bundle = (string) @file_get_contents( CCJ_UPLOAD_DIR . '/block_js.js' );
	$check( 'CCJ: switching a block snippet off takes it out of the bundle', false === strpos( $bundle, 'minnV043BlockA' ) && false !== strpos( $bundle, 'minnV043BlockB' ) );
	// An untouched snippet's file holds what CCJ wrote, which can differ from
	// post_content (CCJ writes the raw editor bytes). A write elsewhere must not
	// regenerate it.
	$own = $make( array( 'name' => 'Minn v043 untouched', 'language' => 'css', 'code' => '.minn-v043-own{color:blue}' ) );
	file_put_contents( CCJ_UPLOAD_DIR . '/' . $own . '.css', '/* minn-v043 raw bytes */' );
	$call( 'PUT', "/minn-admin/v1/ccj/snippets/{$jq}", array( 'name' => 'Minn v043 jq renamed' ) );
	$check( 'CCJ: a write to one snippet leaves another snippet\'s file alone', '/* minn-v043 raw bytes */' === (string) @file_get_contents( CCJ_UPLOAD_DIR . '/' . $own . '.css' ) );
	$html = $make( array( 'name' => 'Minn v043 html', 'language' => 'html', 'code' => '<p>minn-v043</p>' ) );
	$check( 'CCJ: an HTML snippet is not published as a file', ! is_file( CCJ_UPLOAD_DIR . '/' . $html . '.html' ) );
	foreach ( array_filter( $made ) as $id ) {
		$call( 'DELETE', "/minn-admin/v1/ccj/snippets/{$id}" );
	}
	update_option( 'custom-css-js-tree', $tree_was );
	foreach ( $bundles as $f => $bytes ) {
		if ( null === $bytes ) {
			@unlink( CCJ_UPLOAD_DIR . '/' . $f );
		} else {
			file_put_contents( CCJ_UPLOAD_DIR . '/' . $f, $bytes );
		}
	}
} else {
	$skip( 'Custom CSS & JS writes: plugin inactive' );
}

// --- 09-01 Whole-site tools ask the vendor's own narrowed answer ----------
if ( function_exists( 'minn_admin_wpvivid_can' ) ) {
	add_filter( 'wpvivid_ajax_check_security', '__return_false' );
	$check( 'WPvivid: an administrator the site kept out of backups is kept out here too', ! minn_admin_wpvivid_can() );
	remove_filter( 'wpvivid_ajax_check_security', '__return_false' );
	$check( 'WPvivid: an administrator is otherwise let in (control)', minn_admin_wpvivid_can() );
}
if ( function_exists( 'minn_admin_tm_ready' ) && minn_admin_tm_ready() ) {
	$tm      = \AM\TransientsManager\TransientsManager::getInstance();
	$tm_was  = $tm->capability;
	$tm->capability = 'minn_v043_transients';
	$check( 'Transients Manager: its raised capability keeps an administrator out', ! minn_admin_tm_can() );
	$tm->capability = $tm_was;
} else {
	$skip( 'Transients Manager inactive' );
}
if ( class_exists( 'WooCommerce' ) && function_exists( 'minn_admin_visibility_toggles' ) ) {
	$cs_was  = get_option( 'woocommerce_coming_soon', null );
	$no_wc   = function ( $allcaps ) {
		$allcaps['manage_woocommerce'] = false;
		return $allcaps;
	};
	add_filter( 'user_has_cap', $no_wc );
	list( $st ) = $call( 'POST', '/minn-admin/v1/visibility/toggle', array( 'id' => 'wc', 'on' => 'yes' !== $cs_was ) );
	remove_filter( 'user_has_cap', $no_wc );
	wp_cache_delete( 'alloptions', 'options' );
	$check( 'store coming soon: only someone who can manage WooCommerce flips it', 403 === $st && get_option( 'woocommerce_coming_soon', null ) === $cs_was, "status {$st}" );
	null === $cs_was ? delete_option( 'woocommerce_coming_soon' ) : update_option( 'woocommerce_coming_soon', $cs_was );
}

// --- 10-01 Simple 301 Redirects: adding never replaces an existing rule ---
if ( function_exists( 'minn_admin_s301_active' ) && minn_admin_s301_active() ) {
	$s301_was          = get_option( '301_redirects', array() );
	$rows              = (array) $s301_was;
	$rows['/minn-v043-old'] = '/minn-v043-kept';
	update_option( '301_redirects', $rows );
	list( $st ) = $call( 'POST', '/minn-admin/v1/s301/redirects', array( 'from' => '/minn-v043-old', 'to' => '/minn-v043-new' ) );
	$after = (array) get_option( '301_redirects', array() );
	$check( 'Simple 301: adding a source that has a rule is refused, the rule kept', 400 === $st && '/minn-v043-kept' === ( $after['/minn-v043-old'] ?? '' ), "status {$st}, now " . ( $after['/minn-v043-old'] ?? '(none)' ) );
	update_option( '301_redirects', $s301_was );
} else {
	$skip( 'Simple 301 Redirects inactive' );
}

// --- H1 A package another filter supplies for Minn is still hash-checked ---
if ( class_exists( 'Minn_Admin_Updater' ) ) {
	$pkg  = 'https://github.com/austinginder/minn-admin/releases/download/v9.9.9/minn-admin.zip';
	$good = wp_tempnam( 'minn-v043-good' );
	file_put_contents( $good, 'minn v043 good package' );
	$fake = (object) array( 'version' => '9.9.9', 'download_url' => $pkg, 'sha256' => hash_file( 'sha256', $good ) );
	$mock = function () use ( $fake ) {
		return $fake;
	};
	add_filter( 'pre_transient_minn_admin_updater', $mock );
	$upd  = new Minn_Admin_Updater();
	$bad  = wp_tempnam( 'minn-v043-bad' );
	file_put_contents( $bad, 'something else entirely' );
	$extra = array( 'plugin' => 'minn-admin/minn-admin.php' );
	$res   = $upd->verify_package( $bad, $pkg, null, $extra );
	$check( 'updater: a mismatched file another filter supplies for Minn is refused', is_wp_error( $res ), is_wp_error( $res ) ? $res->get_error_code() : 'accepted' );
	$res2  = $upd->verify_package( $good, $pkg, null, $extra );
	$check( 'updater: a matching file from another filter is accepted (control)', $good === $res2, is_wp_error( $res2 ) ? $res2->get_error_code() : '' );
	$res3  = $upd->verify_package( $bad, 'https://downloads.wordpress.org/plugin/akismet.zip', null, array( 'plugin' => 'akismet/akismet.php' ) );
	$check( 'updater: another plugin\'s answered download is left alone (control)', $bad === $res3 );
	remove_filter( 'pre_transient_minn_admin_updater', $mock );
	@unlink( $good );
	@unlink( $bad );
}

// --- H3 The database viewer hides Gravity Forms' REST API secret -----------
if ( class_exists( 'Minn_Admin_DB' ) && method_exists( 'Minn_Admin_DB', 'is_secret_cell' ) ) {
	global $wpdb;
	$secret = new ReflectionMethod( 'Minn_Admin_DB', 'is_secret_cell' );
	$secret->setAccessible( true );
	$check( 'database viewer: Gravity Forms\' REST API secret is redacted', true === (bool) $secret->invoke( null, $wpdb->prefix . 'gf_rest_api_keys', 'consumer_secret', array() ) );
}

// --- H2 Descriptor filter and open routes answer to the route rule --------
if ( class_exists( 'Minn_Admin_Surfaces' ) ) {
	$evil = function ( $s ) {
		$s['minn-v043-probe'] = array(
			'label'      => 'Probe',
			'cap'        => 'read',
			'collection' => array(
				'route'  => 'minn-admin/v1/minn-v043/items',
				'filter' => array( 'label' => 'Kind', 'route' => '//evil.example/options' ),
				'open'   => array( 'route' => 'javascript:alert(1)' ),
			),
		);
		return $s;
	};
	$reg = new ReflectionProperty( 'Minn_Admin_Surfaces', 'all_cache' );
	$reg->setAccessible( true );
	$reg->setValue( null, null ); // the registry is built once per request
	add_filter( 'minn_admin_surfaces', $evil );
	$mine = current( array_filter( Minn_Admin_Surfaces::for_current_user(), function ( $s ) {
		return 'minn-v043-probe' === ( $s['id'] ?? '' );
	} ) );
	remove_filter( 'minn_admin_surfaces', $evil );
	$reg->setValue( null, null );
	$coll = is_array( $mine ) ? (array) ( $mine['collection'] ?? array() ) : array();
	$check( 'surfaces: an off-origin filter route is dropped', is_array( $mine ) && empty( $coll['filter']['route'] ), wp_json_encode( $coll['filter'] ?? null ) );
	$check( 'surfaces: a scheme in an open route is dropped', is_array( $mine ) && empty( $coll['open']['route'] ), wp_json_encode( $coll['open'] ?? null ) );
}

// --- 11-02 Folder listings authorize a bounded set ------------------------
if ( function_exists( 'minn_admin_media_folders_provider' ) && minn_admin_media_folders_provider() && $author ) {
	$reads = 0;
	$count = function ( $caps, $cap ) use ( &$reads ) {
		if ( 'read_post' === $cap ) {
			++$reads;
		}
		return $caps;
	};
	wp_set_current_user( $author->ID );
	add_filter( 'map_meta_cap', $count, 10, 2 );
	list( $st, $d ) = $call( 'GET', '/minn-admin/v1/media/folders/0/ids' );
	remove_filter( 'map_meta_cap', $count, 10 );
	$ids = (array) ( $d['ids'] ?? array() );
	$check( 'media folders: every id handed to an Author is one they may read', 200 === $st && count( $ids ) === count( array_filter( $ids, function ( $id ) {
		return current_user_can( 'read_post', $id );
	} ) ), "status {$st}" );
	$check( 'media folders: authorizing the listing stays bounded', $reads <= 1200, "{$reads} read_post checks" );
	wp_set_current_user( $admin );
} else {
	$skip( 'media folders: no folder plugin active' );
}

// --- 08-04 Snippet edits keep an empty (shortcode-only) location ----------
$all_surfaces = class_exists( 'Minn_Admin_Surfaces' ) ? Minn_Admin_Surfaces::all() : array();
foreach ( array( 'wpcode', 'hfcm' ) as $sid ) {
	if ( empty( $all_surfaces[ $sid ]['collection']['detail']['edit']['fields'] ) ) {
		$skip( "{$sid}: not loaded" );
		continue;
	}
	$loc = current( array_filter( $all_surfaces[ $sid ]['collection']['detail']['edit']['fields'], function ( $f ) {
		return 'location' === ( $f['key'] ?? '' );
	} ) );
	$check( "{$sid}: the edit form keeps an empty stored location instead of seeding the first", ! empty( $loc['clearable'] ) );
}
if ( class_exists( 'WPCode_Snippet' ) && ! empty( $all_surfaces['wpcode'] ) ) {
	$sn = new WPCode_Snippet( array( 'title' => 'Minn v043 shortcode', 'code' => '<p>minn</p>', 'code_type' => 'html', 'auto_insert' => 0, 'active' => false ) );
	$sn->save();
	$sid = (int) $sn->get_id();
	list( $st ) = $call( 'PUT', "/minn-admin/v1/wpcode/snippets/{$sid}", array( 'name' => 'Minn v043 shortcode 2', 'location' => '', 'code_type' => 'html', 'auto_insert' => false ) );
	$check( 'wpcode: an untouched empty location saves and stays empty', 200 === $st && '' === (string) ( new WPCode_Snippet( $sid ) )->get_location(), "status {$st}" );
	wp_delete_post( $sid, true );
	// Review N6: "—" keeps the stored location only while the type allows it.
	$sn2 = new WPCode_Snippet( array( 'title' => 'Minn v043 footer', 'code' => '<p>minn</p>', 'code_type' => 'html', 'location' => 'site_wide_footer', 'auto_insert' => 1, 'active' => false ) );
	$sn2->save();
	$sid2 = (int) $sn2->get_id();
	list( $st ) = $call( 'PUT', "/minn-admin/v1/wpcode/snippets/{$sid2}", array( 'location' => '', 'code_type' => 'php' ) );
	$check( 'wpcode: retyping with "—" can\'t keep a location the new type doesn\'t allow', 400 === $st && 'html' === ( new WPCode_Snippet( $sid2 ) )->get_code_type(), "status {$st}, type " . ( new WPCode_Snippet( $sid2 ) )->get_code_type() );
	list( $st ) = $call( 'PUT', "/minn-admin/v1/wpcode/snippets/{$sid2}", array( 'location' => '', 'code_type' => 'css' ) );
	$check( 'wpcode: a retype the stored location still fits saves (control)', 200 === $st && 'site_wide_footer' === ( new WPCode_Snippet( $sid2 ) )->get_location(), "status {$st}" );
	wp_delete_post( $sid2, true );
}

// --- 08-03 An SEO save keeps an unchanged social image without re-checking it --
$seo_plugin = function_exists( 'minn_admin_seo_plugin' ) ? minn_admin_seo_plugin() : null;
$seo_image  = '';
if ( $seo_plugin && function_exists( 'minn_admin_seo_field_map' ) ) {
	foreach ( minn_admin_seo_field_map( $seo_plugin, 0 ) as $f => $def ) {
		if ( 'image' === ( $def['type'] ?? '' ) ) {
			$seo_image = $f;
			break;
		}
	}
}
if ( $seo_image ) {
	$writer = $temp_user( 'minn-v043-writer', array( 'edit_posts' ) );
	$pid    = wp_insert_post( array( 'post_title' => 'Minn v043 SEO', 'post_status' => 'draft', 'post_author' => $writer ) );
	$img    = wp_insert_attachment( array( 'post_title' => 'minn-v043-og', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), 'minn-v043-og.png' );
	call_user_func( $seo_plugin['write'], $pid, $seo_image, $img );
	wp_set_current_user( $writer );
	list( $st ) = $call( 'POST', "/wp/v2/posts/{$pid}", array( 'minn_seo' => array( $seo_image => array( 'id' => $img ), 'description' => 'Minn v043 description' ) ) );
	$check( 'SEO panel: a writer without upload rights can save with the image an editor set', 200 === $st, "status {$st}" );
	wp_set_current_user( $admin );
	$img2 = wp_insert_attachment( array( 'post_title' => 'minn-v043-og2', 'post_mime_type' => 'image/png', 'post_status' => 'inherit' ), 'minn-v043-og2.png' );
	wp_set_current_user( $writer );
	list( $st ) = $call( 'POST', "/wp/v2/posts/{$pid}", array( 'minn_seo' => array( $seo_image => array( 'id' => $img2 ) ) ) );
	$check( 'SEO panel: that writer still cannot pick a different image (control)', 403 === $st, "status {$st}" );
	wp_set_current_user( $admin );
	wp_delete_attachment( $img2, true );
	wp_delete_post( $pid, true );
	wp_delete_attachment( $img, true );
} else {
	$skip( 'SEO panel: the active SEO plugin has no image field in Minn' );
}

if ( $temp_users ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $temp_users as $id ) {
		wp_delete_user( $id );
	}
}

$summary();
