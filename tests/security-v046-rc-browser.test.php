<?php
/**
 * v0.46.0 RC audit, the database browser lane: what System › Database, the
 * transients list and the shared health checks show a role a plugin's own
 * rule keeps out.
 *
 * - 01-1: a whole table holds every column, so a resource whose item, read
 *   or rows keep some behind a stricter rule (gift card codes and messages,
 *   Gravity SMTP bodies, JetAppointments meta) leaves its tables out for a
 *   role that rule refuses.
 * - 04-1: WP Statistics, Independent Analytics, Matomo, AnalyticsWP and
 *   Rank Math's 404 and analytics tables follow each plugin's own viewer
 *   rule, also while the plugin is inactive; Gravity Forms' payment add-on
 *   tables follow its entries.
 * - 13-1: Redirection's logs and 404s follow its log and 404 capabilities.
 * - 08-1: the forms and mail plugins' other tables holding entry or mail
 *   data (queued feeds, payments, task meta, the mail queue) follow the
 *   resource they belong to.
 * - 08-2: the mail plugins' connection rows are credential rows.
 * - 11-2: a transient whose whole value the browser would redact or
 *   withhold is hidden in the transients list and detail, however the value
 *   is cut.
 * - 13-3: Activity Log (Aryo)'s viewer scope is the kernel's to hold.
 * - Info: a shared health row naming a table the viewer cannot open offers
 *   neither its detail nor its command.
 *
 * Each check calls the route Minn's client calls (or the kernel's own
 * answer, for a table the lab does not have), as the role the finding
 * names, with an administrator and the role the plugin admits as controls.
 * Sections SKIP when their plugin's table is not on the site. Run it on a
 * single site: only the network's owner reaches the browser on a network.
 * Everything it makes (a role, users, options, transients) is removed
 * however the run ends; filters live in this process only.
 *
 * Run: wp eval-file tests/security-v046-rc-browser.test.php --user=admin --path=<site>
 *
 * @package minn-admin
 */

// Ctrl-C, TERM and HUP end the suite through its cleanups (see the file).
require_once __DIR__ . '/lib/signals.php';

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	set_time_limit( 0 );
	$results[] = (bool) $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( $ok || '' === $detail ) ? '' : ' — ' . $detail );
};
$skip    = function ( $label ) {
	printf( "SKIP  %s\n", $label );
};
$call    = function ( $method, $route, $params = array() ) {
	$r = new WP_REST_Request( $method, $route );
	foreach ( $params as $k => $v ) {
		$r->set_param( $k, $v );
	}
	$res = rest_do_request( $r );
	return array( $res->get_status(), json_decode( wp_json_encode( $res->get_data() ), true ) );
};
// Cleanup that runs once, from the section's finally or at shutdown when a
// fatal error ends the run first.
$once = function ( callable $fn ) {
	$done = false;
	$run  = function () use ( &$done, $fn ) {
		if ( $done ) {
			return;
		}
		$done = true;
		$fn();
	};
	register_shutdown_function( $run );
	return $run;
};

$admin = get_current_user_id();
if ( ! $admin || ! current_user_can( 'manage_options' ) ) {
	echo "SKIP  run with --user=<an administrator>\n";
	return;
}
if ( is_multisite() ) {
	echo "SKIP  a network: only its owner reaches the browser, run this on a single site\n";
	return;
}
if ( ! class_exists( 'Minn_Admin_DB' ) || ! class_exists( 'Minn_Admin_Kernel' ) ) {
	echo "FAIL  the database browser or the kernel is not loaded\n";
	exit( 1 );
}
global $wpdb;
rest_get_server();
$tag  = strtolower( wp_generate_password( 6, false, false ) );
$p    = $wpdb->prefix;
$role = 'minn_rc_browser_sys';

$made = array( 'users' => array(), 'options' => array() );
// The browser keeps its list of credentials for the process: a test that
// adds or removes a credential row asks it to read them again.
$reset_needles = function () {
	$rc = new ReflectionClass( 'Minn_Admin_DB' );
	foreach ( array( 'needles', 'credentials' ) as $prop ) {
		if ( $rc->hasProperty( $prop ) ) {
			$p = $rc->getProperty( $prop );
			$p->setAccessible( true );
			$p->setValue( null, null );
		}
	}
};
$cleanup = $once( function () use ( &$made, $role, $admin, $reset_needles ) {
	wp_set_current_user( $admin );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $made['users'] as $id ) {
		if ( is_int( $id ) && $id > 1 && $id !== $admin ) {
			wp_delete_user( $id );
		}
	}
	foreach ( $made['options'] as $name ) {
		delete_option( $name );
	}
	remove_role( $role );
	$reset_needles();
} );
$make_user = function ( $login, $role_name ) use ( &$made ) {
	$id = wp_insert_user( array(
		'user_login' => $login . '_' . wp_generate_password( 6, false, false ),
		'user_pass'  => wp_generate_password( 24 ),
		'user_email' => $login . '.' . wp_generate_password( 6, false, false ) . '@example.com',
		'role'       => $role_name,
	) );
	if ( ! is_int( $id ) || $id < 2 ) {
		return 0;
	}
	$made['users'][] = $id;
	return $id;
};
// WP Activity Log keeps the first user it saw for the whole process, and
// core keeps the current user's object until another user is set, so a
// capability just granted counts only once it is set afresh.
$become = function ( $id ) {
	wp_set_current_user( 0 );
	wp_set_current_user( $id );
	if ( class_exists( '\\WSAL\\Helpers\\User_Helper' ) && property_exists( '\\WSAL\\Helpers\\User_Helper', 'current_user' ) ) {
		$ref = new ReflectionProperty( '\\WSAL\\Helpers\\User_Helper', 'current_user' );
		$ref->setAccessible( true );
		$ref->setValue( null, null );
	}
};
$exists = function ( $base ) use ( $wpdb ) {
	$t = $wpdb->prefix . $base;
	return $t === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $t ) ) ) ? $t : '';
};
$listed = function () use ( $call ) {
	list( $st, $d ) = $call( 'GET', '/minn-admin/v1/db/tables', array( 'all' => true ) );
	return 200 === $st ? array_map( 'strtolower', array_column( (array) ( $d['tables'] ?? array() ), 'name' ) ) : null;
};
// Out of the list, and 404 for its rows and its structure.
$hidden = function ( $t ) use ( $call, $listed ) {
	$names = $listed();
	list( $a ) = $call( 'GET', '/minn-admin/v1/db/rows', array( 'table' => $t ) );
	list( $b ) = $call( 'GET', '/minn-admin/v1/db/structure', array( 'table' => $t ) );
	return is_array( $names ) && ! in_array( strtolower( $t ), $names, true ) && 404 === $a && 404 === $b;
};
$shown = function ( $t ) use ( $call, $listed ) {
	$names = $listed();
	list( $a ) = $call( 'GET', '/minn-admin/v1/db/rows', array( 'table' => $t ) );
	return is_array( $names ) && in_array( strtolower( $t ), $names, true ) && 200 === $a;
};
// A table name the lab does not have, asked of the browser's own rule.
$viewer_refused = function ( $t ) {
	$m = new ReflectionMethod( 'Minn_Admin_DB', 'viewer_refused' );
	$m->setAccessible( true );
	return true === $m->invoke( null, $t );
};
$tr = function ( $t ) {
	return Minn_Admin_Kernel::table_refused( $t );
};

try {
	// The System page's caps (manage_options, view_site_health_checks) and
	// manage_woocommerce, without WordPress's administrator role: a cloned
	// "store admin" role, and Gravity SMTP's log and detail caps without its
	// preview cap.
	remove_role( $role );
	add_role( $role, 'Minn RC browser probe', array(
		'read'                               => true,
		'edit_posts'                         => true,
		'manage_options'                     => true,
		'install_plugins'                    => true,
		'manage_woocommerce'                 => true,
		'gravitysmtp_view_email_log'         => true,
		'gravitysmtp_view_email_log_details' => true,
	) );
	$uid = $make_user( 'minn_rc_browser', $role );
	if ( ! $uid ) {
		$check( 'setup', false, 'could not make the probe user' );
		return;
	}
	$become( $uid );
	if ( ! Minn_Admin_REST::can_read_system() ) {
		$skip( 'the probe role cannot open the System page here (file mods off?)' );
		return;
	}

	// --- 01-1 WooCommerce Gift Cards: codes and messages -------------------
	$gc = $exists( 'woocommerce_gc_cards' );
	if ( ! $gc || ! function_exists( 'minn_admin_wcgc_can' ) ) {
		$skip( '01-1 WooCommerce Gift Cards has no table here' );
	} else {
		$more = array_filter( array( $exists( 'woocommerce_gc_activity' ), $exists( 'woocommerce_gc_cardsmeta' ) ) );
		$all  = $hidden( $gc );
		foreach ( $more as $t ) {
			$all = $all && $hidden( $t );
		}
		$check( "01-1 WooCommerce Gift Cards: a store admin its rows mask codes and messages for browses none of its card tables raw (gate: " . ( minn_admin_wcgc_can() ? 'yes' : 'no' ) . ')', $all );
		$check( '01-1 WooCommerce Gift Cards: a copy of the cards table follows it', $tr( $p . 'woocommerce_gc_cards_old' ) && $tr( strtoupper( $p ) . 'BAK_WOOCOMMERCE_GC_CARDS' ) );
		if ( function_exists( 'minn_admin_wcgc_active' ) && minn_admin_wcgc_active() ) {
			list( $own ) = $call( 'GET', '/minn-admin/v1/wcgc/gift-cards' );
			$check( '01-1 WooCommerce Gift Cards control: that store admin still lists cards in Minn, codes masked there', 200 === $own, (string) $own );
		}
		// Codes unmasked for shop managers by their setting: messages still are not.
		if ( function_exists( 'wc_gc_is_shop_manager' ) && get_role( 'shop_manager' ) ) {
			$sm = $make_user( 'minn_rc_browser_sm', 'shop_manager' );
			if ( $sm ) {
				$u = new WP_User( $sm );
				$u->add_cap( 'manage_options' );
				$u->add_cap( 'install_plugins' );
				$unmask = function () {
					return 'yes';
				};
				add_filter( 'pre_option_wc_gc_unmask_codes_for_shop_managers', $unmask );
				$become( $sm );
				$sm_codes = function_exists( 'minn_admin_wcgc_codes_visible' ) && minn_admin_wcgc_codes_visible();
				$check( '01-1 WooCommerce Gift Cards: a shop manager it unmasks codes for still browses no card table, since messages stay masked for them', $sm_codes && $tr( $gc ), $sm_codes ? '' : 'codes not unmasked here' );
				remove_filter( 'pre_option_wc_gc_unmask_codes_for_shop_managers', $unmask );
				$become( $uid );
			}
		}
		$become( $admin );
		$check( '01-1 WooCommerce Gift Cards control: an administrator still browses its card tables', $shown( $gc ) && ! $tr( $gc ) );
		$become( $uid );
	}

	// --- 01-1 Gravity SMTP: message bodies behind its preview cap ----------
	$gs = $exists( 'gravitysmtp_events' );
	if ( ! $gs || ! function_exists( 'minn_admin_gsmtp_can_preview' ) || ! defined( 'Gravity_Forms\\Gravity_SMTP\\Users\\Roles::VIEW_EMAIL_LOG_PREVIEW' ) ) {
		$skip( '01-1 Gravity SMTP inactive' );
	} else {
		$logs = $exists( 'gravitysmtp_event_logs' );
		list( $own ) = $call( 'GET', '/minn-admin/v1/gravity-smtp/events' );
		$check( "01-1 Gravity SMTP: a role with its log and detail caps but not its preview cap browses no event table (Minn's own list: {$own})", ! minn_admin_gsmtp_can_preview() && $hidden( $gs ) && ( ! $logs || $hidden( $logs ) ) && $tr( $p . 'gravitysmtp_events_bak' ) );
		$check( '01-1 Gravity SMTP control: that role still lists the events in Minn, bodies withheld there', 200 === $own, (string) $own );
		$u = new WP_User( $uid );
		$u->add_cap( 'gravitysmtp_view_email_log_preview' );
		$become( $uid );
		$check( '01-1 Gravity SMTP control: grant the preview cap and the same role browses them', $shown( $gs ) );
		$u->remove_cap( 'gravitysmtp_view_email_log_preview' );
		$become( $admin );
		$check( '01-1 Gravity SMTP control: an administrator still browses them', $shown( $gs ) );
		$become( $uid );
	}

	// --- 01-1 JetAppointments: meta behind its own context ----------------
	$ja = $exists( 'jet_appointments' );
	$jm = $exists( 'jet_appointments_meta' );
	if ( ! $ja || ! $jm || ! function_exists( 'minn_admin_jet_apb_active' ) || ! minn_admin_jet_apb_active() ) {
		$skip( '01-1 JetAppointments inactive' );
	} else {
		$probe = $uid;
		$deny  = function ( $can, $context ) use ( $probe ) {
			return ( 'appointment-meta' === $context && get_current_user_id() === $probe ) ? false : $can;
		};
		add_filter( 'jet-apb/current-user-can', $deny, 10, 2 );
		try {
			list( $own ) = $call( 'GET', '/minn-admin/v1/jet-appointments/appointments' );
			$check( "01-1 JetAppointments: a role its meta context refuses browses neither the meta table nor the appointments raw (Minn's list: {$own})", ! minn_admin_jet_apb_can_meta() && $hidden( $jm ) && $hidden( $ja ) );
			$check( '01-1 JetAppointments control: that role still lists appointments in Minn', 200 === $own, (string) $own );
		} finally {
			remove_filter( 'jet-apb/current-user-can', $deny, 10 );
		}
		$check( '01-1 JetAppointments control: with the meta context its own again, the same role browses them', $shown( $jm ) && $shown( $ja ) );
		$become( $admin );
		$check( '01-1 JetAppointments control: an administrator still browses them', $shown( $jm ) );
		$become( $uid );
	}

	// --- 04-1 Analytics and 404 tables follow each plugin's viewer rule ----
	$iawp = $exists( 'independent_analytics_sessions' );
	if ( ! $iawp ) {
		$skip( '04-1 Independent Analytics has no table here' );
	} else {
		// Its administrator test asks setup_network, which core maps to
		// manage_options on a single site, so it admits the probe role
		// as it stands; a site that keeps setup_network from a role keeps
		// that role out of its reports.
		$check( '04-1 Independent Analytics control: a role it admits (setup_network, here manage_options) browses its tables', $shown( $iawp ) );
		$probe = $uid;
		$keep  = function ( $caps, $cap, $user_id ) use ( $probe ) {
			return ( 'setup_network' === $cap && $user_id === $probe ) ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $keep, 10, 3 );
		try {
			$ready = function_exists( 'minn_admin_iawp_ready' ) && minn_admin_iawp_ready();
			$check( "04-1 Independent Analytics: a role it grants no access browses none of its visitor tables (its own rule: " . ( $ready ? 'yes' : 'no' ) . ')', ! $ready && $hidden( $iawp ) && $viewer_refused( $p . 'bak_independent_analytics_views' ) );
		} finally {
			remove_filter( 'map_meta_cap', $keep, 10 );
		}
		$become( $admin );
		$check( '04-1 Independent Analytics control: an administrator still browses them', $shown( $iawp ) );
		$become( $uid );
	}
	$mt = $exists( 'matomo_log_visit' );
	if ( ! $mt ) {
		$skip( '04-1 Matomo has no table here' );
	} else {
		$check( '04-1 Matomo: a role without view_matomo that is not its super user browses none of its tables (plugin ' . ( class_exists( '\\WpMatomo\\Capabilities' ) ? 'active' : 'inactive' ) . ')', $hidden( $mt ) && $viewer_refused( strtoupper( $p . 'matomo_log_visit_old' ) ) );
		$u = new WP_User( $uid );
		$u->add_cap( 'view_matomo' );
		$become( $uid );
		$check( '04-1 Matomo control: a role its access settings grant view_matomo browses them', $shown( $mt ) );
		$u->remove_cap( 'view_matomo' );
		$become( $admin );
		$check( '04-1 Matomo control: an administrator (its super user) still browses them', $shown( $mt ) );
		$become( $uid );
	}
	$ws = $exists( 'statistics_visitor' );
	if ( ! $ws ) {
		$skip( '04-1 WP Statistics has no table here' );
	} else {
		$check( '04-1 WP Statistics control: at its default read capability (manage_options) the probe role browses its tables', $shown( $ws ) );
		$raise = function ( $v ) {
			$v                    = is_array( $v ) ? $v : array();
			$v['read_capability'] = 'moderate_comments';
			return $v;
		};
		$raise_one = function () {
			return 'moderate_comments';
		};
		add_filter( 'option_wp_statistics', $raise );
		add_filter( 'wp_statistics_option_read_capability', $raise_one );
		try {
			$check( '04-1 WP Statistics: raise its read capability past the role and its visitor tables are left out, copies included', $hidden( $ws ) && $viewer_refused( $p . 'statistics_visitor_relationships_bak' ) );
			$become( $admin );
			$check( '04-1 WP Statistics control: an administrator holding that capability still browses them', $shown( $ws ) );
			$become( $uid );
		} finally {
			remove_filter( 'option_wp_statistics', $raise );
			remove_filter( 'wp_statistics_option_read_capability', $raise_one );
		}
		$burst = new ReflectionClassConstant( 'Minn_Admin_DB', 'VIEWER_RULES' );
		$wps   = '';
		foreach ( array_keys( $burst->getValue() ) as $pattern ) {
			if ( false !== strpos( $pattern, 'statistics_(' ) ) {
				$wps = $pattern;
			}
		}
		$check( "04-1 WP Statistics: its rule names its own tables, not Burst's statistics_searches", '' !== $wps && preg_match( $wps, 'statistics_visitor' ) && preg_match( $wps, 'bak_statistics_pages' ) && ! preg_match( $wps, 'burst_statistics_searches' ) && ! preg_match( $wps, 'burst_goal_statistics' ), $wps );
	}
	$rm = $exists( 'rank_math_404_logs' );
	if ( ! $rm ) {
		$skip( '04-1 Rank Math 404 Monitor has no table here' );
	} else {
		$check( '04-1 Rank Math: a role without its 404 Monitor capability browses no 404 log, nor its analytics tables', ! current_user_can( 'rank_math_404_monitor' ) && $hidden( $rm ) && $viewer_refused( $p . 'rank_math_analytics_gsc' ) );
		$become( $admin );
		$check( '04-1 Rank Math control: an administrator holding its capabilities still browses them', current_user_can( 'rank_math_404_monitor' ) && $shown( $rm ) && ! $viewer_refused( $p . 'rank_math_analytics_gsc' ) );
		$become( $uid );
	}
	$aw = $exists( 'analytics_wp_events' );
	if ( ! $aw ) {
		$skip( '04-1 AnalyticsWP has no table here' );
	} else {
		$check( "04-1 AnalyticsWP: a role Minn's own traffic card refuses (not a super admin) browses none of its events", $hidden( $aw ) );
		$become( $admin );
		$check( '04-1 AnalyticsWP control: an administrator still browses them', $shown( $aw ) );
		$become( $uid );
	}
	// Gravity Forms' payment add-on tables follow its entries.
	$gf_open = class_exists( 'GFCommon' ) && GFCommon::current_user_can_any( array( 'gravityforms_view_entries', 'gform_full_access' ) );
	if ( $gf_open ) {
		$skip( '04-1 Gravity Forms admits the probe role here' );
	} else {
		$check( "04-1 Gravity Forms: a role its entries refuse browses none of its payment add-ons' transactions or callbacks", $viewer_refused( $p . 'gf_addon_payment_transaction' ) && $viewer_refused( $p . 'gf_addon_payment_callback' ) );
		$become( $admin );
		$check( '04-1 Gravity Forms control: an administrator it admits still browses them', ! $viewer_refused( $p . 'gf_addon_payment_transaction' ) );
		$become( $uid );
	}

	// --- 13-1 Redirection: logs and 404s at their own capabilities --------
	$rl = $exists( 'redirection_logs' );
	$r4 = $exists( 'redirection_404' );
	if ( ! $rl || ! $r4 || ! class_exists( 'Redirection_Capabilities' ) ) {
		$skip( '13-1 Redirection inactive' );
	} else {
		$check( "13-1 Redirection control: a role holding its plugin role and both capabilities browses its logs and 404s", $shown( $rl ) && $shown( $r4 ) );
		$probe  = $uid;
		$denied = '';
		$keep   = function ( $cap, $name ) use ( $probe, &$denied ) {
			return ( $name === $denied && get_current_user_id() === $probe ) ? 'do_not_allow' : $cap;
		};
		add_filter( 'redirection_capability_check', $keep, 10, 2 );
		try {
			$denied = Redirection_Capabilities::CAP_LOG_MANAGE;
			$check( '13-1 Redirection: kept out of its logs, the role browses no logs table and still its 404s', $hidden( $rl ) && $shown( $r4 ) );
			$denied = Redirection_Capabilities::CAP_404_MANAGE;
			$check( '13-1 Redirection: kept out of its 404s, the role browses no 404 table and still its logs', $hidden( $r4 ) && $shown( $rl ) && $viewer_refused( $p . 'redirection_404_old' ) );
			$become( $admin );
			$check( '13-1 Redirection control: an administrator the filter does not touch still browses both', $shown( $rl ) && $shown( $r4 ) );
			$become( $uid );
		} finally {
			remove_filter( 'redirection_capability_check', $keep, 10 );
		}
	}

	// --- 08-1 Forms and mail: the vendor's other tables follow ------------
	$ff = $exists( 'fluentform_submissions' );
	if ( ! $ff || ! function_exists( 'minn_admin_fluent_forms_allowed_form_ids' ) || ! class_exists( '\\FluentForm\\App\\Services\\Manager\\FormManagerService' ) ) {
		$skip( '08-1 Fluent Forms inactive' );
	} else {
		// An administrator Fluent's Managers screen keeps to one form.
		update_user_meta( $uid, '_fluent_forms_has_specific_forms_permission', 'yes' );
		update_user_meta( $uid, '_fluent_forms_allowed_forms', array( 999999 ) );
		try {
			$queue  = $exists( 'ff_scheduled_actions' );
			$scoped = null !== minn_admin_fluent_forms_allowed_form_ids();
			$names  = array( 'fluentform_order_items', 'fluentform_transactions', 'fluentform_subscriptions', 'fluentform_draft_submissions', 'fluentform_form_analytics', 'ff_scheduled_actions' );
			$out    = array();
			foreach ( $names as $n ) {
				if ( ! $tr( $p . $n ) ) {
					$out[] = $n;
				}
			}
			$check( "08-1 Fluent Forms: a manager it keeps to some forms browses neither its queued feeds nor its payment, draft and form-view tables", $scoped && $hidden( $ff ) && ( ! $queue || $hidden( $queue ) ) && ! $out, implode( ', ', $out ) );
		} finally {
			delete_user_meta( $uid, '_fluent_forms_has_specific_forms_permission' );
			delete_user_meta( $uid, '_fluent_forms_allowed_forms' );
		}
		$queue = $exists( 'ff_scheduled_actions' );
		$check( '08-1 Fluent Forms control: unscoped, the same role browses its entries and queue', $shown( $ff ) && ( ! $queue || $shown( $queue ) ) );
	}
	// WPForms and WP Mail SMTP refuse a role without their capability, which
	// a site can raise: asked of an Editor, whom both refuse.
	$editor = $make_user( 'minn_rc_browser_ed', 'editor' );
	if ( $editor ) {
		$become( $editor );
		$wpf = array( 'wpforms_entries', 'wpforms_tasks_meta', 'wpforms_protected_files' );
		$wms = array( 'wpmailsmtp_debug_events', 'wpmailsmtp_emails_queue' );
		// Formidable's and Everest Forms' answers live in their meta tables,
		// which each resource searches (list.search.meta), so the kernel
		// already gives them the entries' answer: kept as a guard.
		$frm = $exists( 'frm_items' ) && function_exists( 'minn_admin_formidable_can' );
		$evf = $exists( 'evf_entries' ) && function_exists( 'minn_admin_everest_can' );
		if ( ! $frm ) {
			$skip( '08-1 Formidable has no table here' );
		} else {
			$check( "08-1 Formidable: a role its entries refuse is refused every answer (frm_item_metas) with them", ! minn_admin_formidable_can() && $tr( $p . 'frm_items' ) && $tr( $p . 'frm_item_metas' ) && $tr( $p . 'frm_item_metas_old' ) );
		}
		if ( ! $evf ) {
			$skip( '08-1 Everest Forms has no table here' );
		} else {
			$check( "08-1 Everest Forms: a role its entries refuse is refused every answer (evf_entrymeta) with them", ! minn_admin_everest_can() && $tr( $p . 'evf_entries' ) && $tr( $p . 'evf_entrymeta' ) );
		}
		$ans = array();
		foreach ( array_merge( $wpf, $wms ) as $n ) {
			$ans[ $n ] = $tr( $p . $n );
		}
		$check( "08-1 WPForms: a role its entries refuse is refused its queued entry emails and protected uploads with them", $ans['wpforms_entries'] && $ans['wpforms_tasks_meta'] && $ans['wpforms_protected_files'], wp_json_encode( $ans ) );
		$check( "08-1 WP Mail SMTP: a role its capability refuses is refused its mail queue with its events", $ans['wpmailsmtp_debug_events'] && $ans['wpmailsmtp_emails_queue'], wp_json_encode( $ans ) );
		$become( $admin );
		$ans = array();
		foreach ( array_merge( $wpf, $wms ) as $n ) {
			$ans[ $n ] = $tr( $p . $n );
		}
		$check( '08-1 control: an administrator both admit is refused none of them', ! in_array( true, $ans, true ), wp_json_encode( $ans ) );
		if ( $frm ) {
			$check( '08-1 Formidable control: an administrator it admits is refused neither its entries nor their answers', ! $tr( $p . 'frm_items' ) && ! $tr( $p . 'frm_item_metas' ) );
		}
		if ( $evf ) {
			$check( '08-1 Everest Forms control: an administrator it admits is refused neither its entries nor their answers', ! $tr( $p . 'evf_entries' ) && ! $tr( $p . 'evf_entrymeta' ) );
		}
		$become( $uid );
		// Formidable answers to frm_view_entries or the administrator role,
		// so the System role here (manage_options without either) is one it
		// refuses: the browser leaves out its answers with its entries.
		$fm = $exists( 'frm_item_metas' );
		if ( $frm && $fm && ! minn_admin_formidable_can() ) {
			$check( "08-1 Formidable: a System role Formidable refuses browses none of its answers", $hidden( $exists( 'frm_items' ) ) && $hidden( $fm ) );
			$u = new WP_User( $uid );
			$u->add_cap( 'frm_view_entries' );
			$become( $uid );
			$check( '08-1 Formidable control: grant frm_view_entries and the same role browses its entries and answers', $shown( $exists( 'frm_items' ) ) && $shown( $fm ) );
			$u->remove_cap( 'frm_view_entries' );
			$become( $uid );
		}
	}

	// --- 08-2 Mail plugins' connection rows are credential rows -----------
	$rows = array( 'fluentmail-settings', '_fluent_smtp_notify_settings', 'postman_office365_oauth', 'post_smtp_mobile_app_connection', 'suremails_connections', 'gosmtp_options', 'swpsmtp_options', 'swpsmtp_enc_key', 'site_mailer_client_secret', 'site_mailer_access_token', 'site_mailer_refresh_token', 'site_mailer_user_access_token', 'site_mailer_unsubscribe_encryption_data' );
	$miss = array();
	foreach ( $rows as $o ) {
		if ( ! Minn_Admin_DB::is_credential_option( $o ) || ! Minn_Admin_DB::redacts_cell( $wpdb->options, 'option_value', array( 'option_name' => $o, 'option_value' => 'x' ) ) ) {
			$miss[] = $o;
		}
	}
	$check( '08-2 every mail plugin connection row (FluentSMTP, Post SMTP, SureMails, GoSMTP, Easy WP SMTP, Site Mailer) is redacted whole in the browser and known to the egress fence', ! $miss, implode( ', ', $miss ) );
	$check( '08-2 control: a mail plugin row holding no secret, and a core row, stay readable', ! Minn_Admin_DB::is_credential_option( 'fluentmail_version_probe' ) && ! Minn_Admin_DB::redacts_cell( $wpdb->options, 'option_value', array( 'option_name' => 'admin_email', 'option_value' => 'x' ) ) );
	$fs_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s", 'fluentmail-settings' ) );
	if ( ! $fs_id ) {
		$skip( '08-2 FluentSMTP has no settings row here' );
	} else {
		list( $st, $d ) = $call( 'GET', '/minn-admin/v1/db/row', array( 'table' => $wpdb->options, 'pk' => wp_json_encode( array( 'option_id' => $fs_id ) ) ) );
		$cell = null;
		foreach ( (array) ( $d['columns'] ?? array() ) as $i => $c ) {
			if ( 'option_value' === ( $c['name'] ?? '' ) ) {
				$cell = $d['cells'][ $i ] ?? null;
			}
		}
		$check( "08-2 FluentSMTP: its connections row opens redacted in the browser ({$st})", 200 === $st && is_array( $cell ) && ! empty( $cell['redacted'] ), wp_json_encode( $cell ) );
	}

	// --- 11-2 Transients: redaction decided on the whole stored value -----
	if ( ! function_exists( 'minn_admin_tm_row' ) || ! function_exists( 'minn_admin_tm_can' ) ) {
		$skip( '11-2 Transients Manager inactive' );
	} else {
		$become( $admin );
		// A credential row of the browser's own (a core Connector key row, by
		// its name shape), made for this run.
		$key_row = 'connectors_minnrc' . $tag . '_api_key';
		$secret  = 'mRc' . $tag . 'Kx7Q2' . strtolower( wp_generate_password( 12, false, false ) ) . '94Ly3Zt8';
		if ( false !== get_option( $key_row, false ) ) {
			$skip( '11-2 the probe row exists already' );
		} else {
			add_option( $key_row, $secret, '', 'no' );
			$made['options'][] = $key_row;
			$reset_needles();
			$names = array(
				'list'   => 'minn_rc_tm_list_' . $tag,
				'detail' => 'minn_rc_tm_detail_' . $tag,
				'b64'    => 'minn_rc_tm_b64_' . $tag,
				'plain'  => 'minn_rc_tm_plain_' . $tag,
			);
			$values = array(
				// The copy straddles the list preview's 120-byte cut.
				'list'   => str_repeat( 'x', 100 ) . $secret,
				// And the detail's 2,000-byte cut.
				'detail' => str_repeat( 'y', 1990 ) . $secret,
				// Inside a base64 token, cut by the preview.
				'b64'    => str_repeat( 'z', 60 ) . base64_encode( '{"license":"' . $secret . '"}' ),
				'plain'  => 'Minn RC plain transient value 12345, nothing secret in it',
			);
			// Written as the options table holds them (the list reads that
			// table), whatever object cache the site runs.
			foreach ( $names as $k => $n ) {
				add_option( '_transient_' . $n, $values[ $k ], '', 'no' );
				$made['options'][] = '_transient_' . $n;
			}
			$head = substr( $secret, 0, 8 );
			$find = function ( $name ) use ( $call ) {
				list( $st, $d ) = $call( 'GET', '/minn-admin/v1/transients', array( 'search' => $name ) );
				foreach ( (array) ( $d['items'] ?? array() ) as $it ) {
					if ( $name === ( $it['name'] ?? '' ) ) {
						return $it;
					}
				}
				return null;
			};
			$detail = function ( $item ) use ( $call ) {
				if ( ! $item ) {
					return null;
				}
				list( $st, $d ) = $call( 'GET', '/minn-admin/v1/transients/' . (int) $item['id'] );
				foreach ( (array) ( $d['sections'][0]['rows'] ?? array() ) as $r ) {
					if ( 'Value' === ( $r['label'] ?? '' ) ) {
						return (string) $r['value'];
					}
				}
				return null;
			};
			$capped = ( function () {
				$m = new ReflectionMethod( 'Minn_Admin_DB', 'needles_capped' );
				$m->setAccessible( true );
				return $m->invoke( null );
			} )();
			$li = $find( $names['list'] );
			$check( '11-2 a transient holding a credential copy across the list cut prints no part of it in the list', $li && false === strpos( wp_json_encode( $li ), $head ) && '(hidden)' === $li['preview'], wp_json_encode( $li ? $li['preview'] : null ) );
			$de = $detail( $find( $names['detail'] ) );
			$check( '11-2 a transient holding a credential copy across the detail cut prints no part of it in the detail', null !== $de && false === strpos( $de, $head ) && false === strpos( $de, 'yyyy' ), null === $de ? 'no detail' : substr( $de, -40 ) );
			$b6 = $find( $names['b64'] );
			$check( '11-2 a copy inside a base64 token is hidden in the list and the detail', $b6 && '(hidden)' === $b6['preview'] && false === strpos( (string) $detail( $b6 ), 'zzzz' ), wp_json_encode( $b6 ? $b6['preview'] : null ) );
			$pl = $find( $names['plain'] );
			if ( $capped ) {
				$skip( "11-2 control: this site's credential list is capped, so every long value is withheld, as the browser withholds it" );
			} else {
				$check( '11-2 control: a transient holding no credential still previews and opens in full', $pl && $values['plain'] === $pl['preview'] && $values['plain'] === $detail( $pl ), wp_json_encode( $pl ? $pl['preview'] : null ) );
			}
		}
		$become( $uid );
	}

	// --- 13-3 Activity Log (Aryo): the kernel holds its viewer scope ------
	$ar = $exists( 'aryo_activity_log' );
	if ( ! $ar || ! function_exists( 'minn_admin_aryo_scope' ) ) {
		$skip( '13-3 Activity Log (Aryo) has no table here' );
	} else {
		// An editor given manage_options: Aryo keys the actors it shows on
		// the first role, so this viewer sees editors' and authors' actions
		// only, a scope that narrows without being empty.
		$narrow = $make_user( 'minn_rc_browser_aryo', 'editor' );
		if ( $narrow ) {
			$u = new WP_User( $narrow );
			$u->add_cap( 'manage_options' );
			$u->add_cap( 'install_plugins' );
			$become( $narrow );
			$scope = minn_admin_aryo_scope();
			$check( "13-3 Activity Log (Aryo): the kernel's own answer leaves its table out for a viewer its scope narrows", is_array( $scope ) && array() !== $scope && $tr( $ar ) && $hidden( $ar ), wp_json_encode( $scope ) );
			$become( $admin );
			$check( '13-3 Activity Log (Aryo) control: an administrator it shows everything is shown the table by the kernel and the browser', ! $tr( $ar ) && $shown( $ar ) );
			$become( $uid );
		}
	}

	// --- Info: a shared health row names no table the viewer cannot open ---
	$gf = $exists( 'gf_entry' );
	$hidden_t = '';
	foreach ( array( $gf, $exists( 'pimwick_gift_card' ), $exists( 'independent_analytics_sessions' ) ) as $cand ) {
		if ( $cand && '' === $hidden_t && $hidden( $cand ) ) {
			$hidden_t = $cand;
		}
	}
	if ( ! $hidden_t ) {
		$skip( 'health: no table here is hidden from the probe role' );
	} else {
		$health_was = get_transient( 'minn_admin_db_health' );
		try {
			set_transient( 'minn_admin_db_health', array(
				'checks'   => array(
					array( 'id' => 'minn_rc_a', 'label' => 'Probe A', 'severity' => 'warn', 'table' => $hidden_t, 'detail' => $hidden_t . ' alone is holding 82 MB.', 'command' => array( 'label' => 'Optimise', 'text' => 'wp db query "OPTIMIZE TABLE ' . $hidden_t . '"', 'hint' => '' ) ),
					array( 'id' => 'minn_rc_b', 'label' => 'Probe B', 'severity' => 'warn', 'names' => array( $hidden_t ), 'detail' => 'These tables lack a key: ' . $hidden_t ),
					array( 'id' => 'minn_rc_c', 'label' => 'Probe C', 'severity' => 'info', 'table' => $wpdb->posts, 'detail' => 'Kept detail.' ),
				),
				'warnings' => 2,
				'scan_max' => 1,
			), 60 );
			list( $hs, $hd ) = $call( 'GET', '/minn-admin/v1/db/health' );
			$by   = array_column( (array) ( $hd['checks'] ?? array() ), null, 'id' );
			$text = wp_json_encode( $hd );
			$check( 'health: a shared row whose detail, list or command names a table the viewer cannot open offers none of them, and keeps its count', 200 === $hs && false === stripos( $text, $hidden_t ) && '' === ( $by['minn_rc_a']['detail'] ?? null ) && array_key_exists( 'command', (array) ( $by['minn_rc_a'] ?? array() ) ) && null === $by['minn_rc_a']['command'] && '' === ( $by['minn_rc_b']['detail'] ?? null ) && 'warn' === ( $by['minn_rc_b']['severity'] ?? '' ), substr( $text, 0, 300 ) );
			$check( 'health control: a row naming a table the viewer opens keeps its detail', 'Kept detail.' === ( $by['minn_rc_c']['detail'] ?? null ) && $wpdb->posts === ( $by['minn_rc_c']['table'] ?? null ) );
			$become( $admin );
			list( , $ad ) = $call( 'GET', '/minn-admin/v1/db/health' );
			$aby = array_column( (array) ( $ad['checks'] ?? array() ), null, 'id' );
			$check( 'health control: an administrator who opens that table sees the whole row, and never the internal list', false !== strpos( (string) ( $aby['minn_rc_a']['detail'] ?? '' ), $hidden_t ) && ! empty( $aby['minn_rc_a']['command'] ) && ! array_key_exists( 'names', (array) ( $aby['minn_rc_b'] ?? array() ) ) );
			$become( $uid );
		} finally {
			if ( false === $health_was ) {
				delete_transient( 'minn_admin_db_health' );
			} else {
				set_transient( 'minn_admin_db_health', $health_was, Minn_Admin_DB::HEALTH_TTL );
			}
		}
	}
} finally {
	$cleanup();
}

$failed = count( array_filter( $results, function ( $r ) {
	return ! $r;
} ) );
printf( "\nsecurity-v046-rc-browser: %d/%d passed\n", count( $results ) - $failed, count( $results ) );
exit( $failed ? 1 : 0 );
