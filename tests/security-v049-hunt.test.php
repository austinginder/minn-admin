<?php
/**
 * Regressions for the v0.49.0 RC4 hunt and the RC4 audit: a key the body
 * left out was filled with a default the vendor never writes, a detail view
 * wrote or printed what the vendor's own screen does not.
 *
 *  - H1 WooCommerce Memberships: a purchasing discount without "active" was
 *    stored active, so an inactive discount applied at checkout.
 *  - H2 WooCommerce Memberships: one without "discount_type" was stored as a
 *    percentage, so a fixed amount became that many percent.
 *  - H3 Gravity Forms notifications, H4 confirmations, H5 field conditions:
 *    a condition without logicType (which their evaluator reads as never
 *    matching) was stored "all", one without actionType "show", and a rule
 *    without an operator "is".
 *  - H6 Fluent Forms: a confirmation with no redirect type read as the same
 *    page, and a message edit stored samePage over it.
 *  - H7 Everest Forms: opening an entry marked it viewed.
 *  - H8 Forminator: an entry's answers printed the lead token and the
 *    signup's activation key.
 *  - H9 ACPT: a location rule without an operator was stored "=".
 *  - H10 WooCommerce: a retention period without a unit was posted as months.
 *  - H11 WooCommerce: a page setting never set (-1) was posted as "-1",
 *    which wc_get_page_id() then answers as post 1.
 *  - H12 Rank Math: clearing the last advanced robots directive deleted the
 *    map, so the post inherited the global directives it was suppressing.
 *    Runs only with Rank Math swapped in.
 *
 * Each section calls the function the route calls, with the body the finding
 * named, and a control where the fix could over-block. Rows and meta it
 * writes are put back however the run ends.
 *
 * Run: wp eval-file <ABSOLUTE path>/tests/security-v049-hunt.test.php --user=admin --path=<site>
 *      MINN_SEO_SWAP=rank-math wp eval-file <ABSOLUTE path>/tests/security-v049-hunt.test.php --user=admin --require=<ABSOLUTE path>/tests/lib/seo-swap.php
 *
 * @package minn-admin
 */

// Ctrl-C, TERM and HUP end the suite through its cleanups (see the file).
require_once __DIR__ . '/lib/signals.php';

$results = array();
$check   = static function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = (bool) $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( $ok || '' === $detail ) ? '' : ' — ' . $detail );
};
$skip    = static function ( $label ) {
	printf( "SKIP  %s\n", $label );
};
// Cleanup that runs once, from the section's finally or at shutdown when a
// fatal error ends the run first.
$once = static function ( callable $fn ) {
	$done = false;
	$run  = static function () use ( &$done, $fn ) {
		if ( $done ) {
			return;
		}
		$done = true;
		$fn();
	};
	register_shutdown_function( $run );
	return $run;
};
$swapped_seo = '' !== (string) getenv( 'MINN_SEO_SWAP' );

if ( ! $swapped_seo ) {

	/*
	 * H1, H2 Memberships purchasing discounts, through the rule cleaner the
	 * plan save calls.
	 */
	( static function () use ( $check, $skip ) {
		if ( ! function_exists( 'minn_admin_wcm_plan_rules_clean' ) ) {
			$skip( 'H1/H2 Memberships: not active' );
			return;
		}
		$rule = array( 'target' => 'post_type:product', 'object_ids' => array(), 'discount_amount' => '5' );
		$out  = minn_admin_wcm_plan_rules_clean( array( 'purchasing_discount' => array( $rule ) ) );
		if ( is_wp_error( $out ) || empty( $out['purchasing_discount'][0] ) ) {
			$check( 'H1/H2 Memberships: the rule cleaner takes a product discount', false, is_wp_error( $out ) ? $out->get_error_message() : wp_json_encode( $out ) );
			return;
		}
		$c = $out['purchasing_discount'][0];
		$check( 'H1 Memberships: a discount without active is stored off', array_key_exists( 'active', $c ) && false === $c['active'], wp_json_encode( $c ) );
		$check( 'H2 Memberships: a discount without a type carries no type', ! array_key_exists( 'discount_type', $c ), wp_json_encode( $c ) );
		$ctl = minn_admin_wcm_plan_rules_clean( array( 'purchasing_discount' => array( $rule + array( 'discount_type' => 'amount', 'active' => true ) ) ) );
		$c   = is_wp_error( $ctl ) ? array() : ( $ctl['purchasing_discount'][0] ?? array() );
		$check( 'H1/H2 Memberships control: a sent type and active are kept', 'amount' === ( $c['discount_type'] ?? null ) && true === ( $c['active'] ?? null ), wp_json_encode( $c ) );
		$bad = minn_admin_wcm_plan_rules_clean( array( 'purchasing_discount' => array( $rule + array( 'discount_type' => 'fixed' ) ) ) );
		$check( 'H2 Memberships control: a type they do not know is refused', is_wp_error( $bad ), wp_json_encode( $bad ) );
	} )();

	/*
	 * H3, H4 Gravity Forms notification and confirmation conditions, through
	 * the builders their routes call.
	 */
	( static function () use ( $check, $skip ) {
		if ( ! class_exists( 'GFAPI' ) || ! function_exists( 'minn_admin_gfn_build' ) || ! function_exists( 'minn_admin_gfc_build' ) || ! minn_admin_gfn_load() ) {
			$skip( 'H3/H4 Gravity Forms: not active' );
			return;
		}
		$form = null;
		foreach ( GFAPI::get_forms() as $f ) {
			if ( ! empty( $f['fields'] ) && ! empty( $f['notifications'] ) && ! empty( $f['confirmations'] ) ) {
				$form = $f;
				break;
			}
		}
		if ( ! $form ) {
			$skip( 'H3/H4 Gravity Forms: no form with a field, a notification and a confirmation' );
			return;
		}
		$fid   = (string) $form['fields'][0]->id;
		$logic = array( 'actionType' => 'show', 'rules' => array( array( 'fieldId' => $fid, 'operator' => 'is', 'value' => 'x' ) ) );

		$stored = reset( $form['notifications'] );
		$body   = array_merge( minn_admin_gfn_payload( $form, $stored, false )['notification'], array( 'conditionalLogic' => $logic ) );
		$n      = minn_admin_gfn_build( $form, $stored, $body, false );
		$cl     = is_wp_error( $n ) ? null : ( $n['conditionalLogic'] ?? null );
		$check( 'H3 GF notification: a condition without logicType stores none', is_array( $cl ) && ! array_key_exists( 'logicType', $cl ) && 'show' === ( $cl['actionType'] ?? null ), is_wp_error( $n ) ? $n->get_error_message() : wp_json_encode( $cl ) );
		$n  = minn_admin_gfn_build( $form, $stored, array_merge( $body, array( 'conditionalLogic' => $logic + array( 'logicType' => 'any' ) ) ), false );
		$cl = is_wp_error( $n ) ? null : ( $n['conditionalLogic'] ?? null );
		$check( 'H3 GF notification control: a sent logicType is kept', 'any' === ( $cl['logicType'] ?? null ), wp_json_encode( $cl ) );

		// A default confirmation takes no conditions: use a copy that is not
		// one (held in memory; the build saves nothing).
		$stored = array_merge( reset( $form['confirmations'] ), array( 'isDefault' => false, 'conditionalLogic' => array() ) );
		$body   = array_merge( minn_admin_gfc_payload( $form, $stored, false )['confirmation'], array( 'conditionalLogic' => $logic ) );
		$c      = minn_admin_gfc_build( $form, $stored, $body, false );
		$cl     = is_wp_error( $c ) ? null : ( $c['conditionalLogic'] ?? null );
		$check( 'H4 GF confirmation: a condition without logicType stores none', is_array( $cl ) && ! empty( $cl['rules'] ) && ! array_key_exists( 'logicType', $cl ), is_wp_error( $c ) ? $c->get_error_message() : wp_json_encode( $cl ) );
	} )();

	/*
	 * H5 Gravity Forms field conditions, through the builder's overlay.
	 */
	( static function () use ( $check, $skip ) {
		if ( ! class_exists( 'GFAPI' ) || ! function_exists( 'minn_admin_gfb_overlay' ) ) {
			$skip( 'H5 GF builder: not active' );
			return;
		}
		$form = null;
		foreach ( GFAPI::get_forms() as $f ) {
			if ( count( $f['fields'] ) >= 2 ) {
				$form = $f;
				break;
			}
		}
		if ( ! $form ) {
			$skip( 'H5 GF builder: no form with two fields' );
			return;
		}
		$arr = (array) json_decode( wp_json_encode( $form['fields'][1] ), true );
		$row = array( 'conditionalLogic' => array( 'rules' => array( array( 'fieldId' => (string) $form['fields'][0]->id, 'value' => 'x' ) ) ) );
		$out = minn_admin_gfb_overlay( $arr, $row, array( 'conditionalLogic' ), (int) $form['id'] );
		$cl  = is_wp_error( $out ) ? null : ( $out['conditionalLogic'] ?? null );
		$check( 'H5 GF builder: a field condition without action, logic or operator stores none of them', is_array( $cl ) && ! isset( $cl['actionType'] ) && ! isset( $cl['logicType'] ) && ! isset( $cl['rules'][0]['operator'] ), is_wp_error( $out ) ? $out->get_error_message() : wp_json_encode( $cl ) );
		$row['conditionalLogic'] += array( 'actionType' => 'hide', 'logicType' => 'any' );
		$row['conditionalLogic']['rules'][0]['operator'] = 'isnot';
		$out = minn_admin_gfb_overlay( $arr, $row, array( 'conditionalLogic' ), (int) $form['id'] );
		$cl  = is_wp_error( $out ) ? null : ( $out['conditionalLogic'] ?? null );
		$check( 'H5 GF builder control: sent values are kept', 'hide' === ( $cl['actionType'] ?? null ) && 'any' === ( $cl['logicType'] ?? null ) && 'isnot' === ( $cl['rules'][0]['operator'] ?? null ), wp_json_encode( $cl ) );
	} )();

	/*
	 * H6 Fluent Forms confirmation with no redirect type stored.
	 */
	( static function () use ( $check, $skip, $once ) {
		global $wpdb;
		if ( ! function_exists( 'minn_admin_fluent_emails_validate' ) || ! function_exists( 'wpFluent' ) ) {
			$skip( 'H6 Fluent Forms: not active' );
			return;
		}
		$table = $wpdb->prefix . 'fluentform_form_meta';
		$row   = $wpdb->get_row( "SELECT id, form_id, value FROM $table WHERE meta_key = 'formSettings' ORDER BY id LIMIT 1" ); // phpcs:ignore WordPress.DB
		$set   = $row ? json_decode( (string) $row->value, true ) : null;
		if ( ! is_array( $set ) || ! isset( $set['confirmation'] ) ) {
			$skip( 'H6 Fluent Forms: no form with a confirmation' );
			return;
		}
		$clean = $once( static function () use ( $wpdb, $table, $row ) {
			$wpdb->update( $table, array( 'value' => $row->value ), array( 'id' => $row->id ) ); // phpcs:ignore WordPress.DB
		} );
		try {
			unset( $set['confirmation']['redirectTo'] );
			$set['confirmation']['customUrl'] = 'https://example.invalid/minn-h6';
			$wpdb->update( $table, array( 'value' => wp_json_encode( $set ) ), array( 'id' => $row->id ) ); // phpcs:ignore WordPress.DB
			$out = minn_admin_fluent_confirmation_out( $set['confirmation'] );
			$check( 'H6 Fluent Forms: a missing redirect type reads blank', '' === $out['redirectTo'], wp_json_encode( $out['redirectTo'] ) );
			$fields = array( 'confirmation.messageToShow' => array( '_k' => 'messageToShow' ), 'confirmation.redirectTo' => array( '_k' => 'redirectTo' ) );
			$ctx    = array( 'form' => (object) array( 'id' => (int) $row->form_id ) );
			$res    = minn_admin_fluent_emails_validate( array( 'confirmation.messageToShow' => 'Thanks' ), $fields, $ctx );
			$check( 'H6 Fluent Forms: a message edit is refused until a type is picked, not stored as samePage', is_wp_error( $res ), is_wp_error( $res ) ? '' : wp_json_encode( $res ) );
			$res = minn_admin_fluent_emails_validate( array( 'confirmation.messageToShow' => 'Thanks', 'confirmation.redirectTo' => 'samePage' ), $fields, $ctx );
			$check( 'H6 Fluent Forms control: picking a type saves', ! is_wp_error( $res ), is_wp_error( $res ) ? $res->get_error_message() : '' );
		} finally {
			$clean();
		}
	} )();

	/*
	 * H7 Everest Forms: the detail view of an unread entry.
	 */
	( static function () use ( $check, $skip, $once ) {
		global $wpdb;
		if ( ! function_exists( 'minn_admin_everest_view' ) ) {
			$skip( 'H7 Everest Forms: not active' );
			return;
		}
		$table = $wpdb->prefix . 'evf_entries';
		$form  = (int) $wpdb->get_var( "SELECT form_id FROM $table ORDER BY entry_id LIMIT 1" ); // phpcs:ignore WordPress.DB
		$wpdb->insert( $table, array( 'form_id' => $form, 'user_id' => 0, 'status' => 'publish', 'date_created' => current_time( 'mysql', true ), 'viewed' => 0, 'starred' => 0, 'user_device' => '', 'user_ip_address' => '', 'referer' => '', 'fields' => '{}' ) ); // phpcs:ignore WordPress.DB
		$id = (int) $wpdb->insert_id;
		if ( $id < 1 ) {
			$check( 'H7 Everest: entry fixture inserted', false );
			return;
		}
		$clean = $once( static function () use ( $wpdb, $table, $id ) {
			$wpdb->delete( $table, array( 'entry_id' => $id ) ); // phpcs:ignore WordPress.DB
		} );
		try {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE entry_id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
			minn_admin_everest_view( $row );
			$now = (int) $wpdb->get_var( $wpdb->prepare( "SELECT viewed FROM $table WHERE entry_id = %d", $id ) ); // phpcs:ignore WordPress.DB
			$check( 'H7 Everest: opening an unread entry leaves it unread', 0 === $now, 'viewed=' . $now );
		} finally {
			$clean();
		}
	} )();

	/*
	 * H8 Forminator: an entry holding a lead token and an activation key.
	 */
	( static function () use ( $check, $skip, $once ) {
		global $wpdb;
		if ( ! function_exists( 'minn_admin_forminator_answers' ) || ! class_exists( 'Forminator_Form_Entry_Model' ) ) {
			$skip( 'H8 Forminator: not active' );
			return;
		}
		$e    = $wpdb->prefix . 'frmt_form_entry';
		$m    = $wpdb->prefix . 'frmt_form_entry_meta';
		$now  = current_time( 'mysql' );
		$form = (int) $wpdb->get_var( "SELECT form_id FROM $e ORDER BY entry_id LIMIT 1" ); // phpcs:ignore WordPress.DB
		$wpdb->insert( $e, array( 'entry_type' => 'custom-forms', 'form_id' => $form, 'is_spam' => 0, 'date_created' => $now ) ); // phpcs:ignore WordPress.DB
		$id = (int) $wpdb->insert_id;
		if ( $id < 1 ) {
			$check( 'H8 Forminator: entry fixture inserted', false );
			return;
		}
		$clean = $once( static function () use ( $wpdb, $e, $m, $id ) {
			$wpdb->delete( $m, array( 'entry_id' => $id ) ); // phpcs:ignore WordPress.DB
			$wpdb->delete( $e, array( 'entry_id' => $id ) ); // phpcs:ignore WordPress.DB
			wp_cache_flush();
		} );
		try {
			foreach ( array( 'name-1' => 'Dana Tester', 'entry_token' => 'minnh8tokenminnh8tokenminnh8tok', 'activation_key' => 'minnh8activationkey' ) as $k => $v ) {
				$wpdb->insert( $m, array( 'entry_id' => $id, 'meta_key' => $k, 'meta_value' => $v, 'date_created' => $now ) ); // phpcs:ignore WordPress.DB
			}
			wp_cache_flush();
			$a = minn_admin_forminator_answers( $id );
			$check( 'H8 Forminator: the lead token and activation key are not answers', ! isset( $a['entry_token'] ) && ! isset( $a['activation_key'] ), wp_json_encode( array_keys( $a ) ) );
			$check( 'H8 Forminator control: a real answer is still there', 'Dana Tester' === ( $a['name-1'] ?? null ), wp_json_encode( array_keys( $a ) ) );
		} finally {
			$clean();
		}
	} )();

	/*
	 * H9 ACPT location rules, through the location reader the group save calls.
	 */
	( static function () use ( $check, $skip ) {
		if ( ! function_exists( 'minn_admin_acpt_builder_belongs_in' ) || ! class_exists( '\ACPT\Constants\MetaTypes' ) ) {
			$skip( 'H9 ACPT: not active' );
			return;
		}
		$cpt    = \ACPT\Constants\MetaTypes::CUSTOM_POST_TYPE;
		$stored = array( array( 'id' => 'minnh9', 'belongsTo' => $cpt, 'operator' => '!=', 'find' => 'post', 'logic' => '' ) );
		$out    = minn_admin_acpt_builder_belongs_in( array( array( array( 'param' => 'post_type', 'value' => 'post', 'id' => 'minnh9' ) ) ), $stored );
		$check( 'H9 ACPT: a stored rule sent without an operator keeps "!="', is_array( $out ) && '!=' === ( $out[0]['operator'] ?? null ), is_wp_error( $out ) ? $out->get_error_message() : wp_json_encode( $out ) );
		$new = minn_admin_acpt_builder_belongs_in( array( array( array( 'param' => 'post_type', 'value' => 'post' ) ) ), array() );
		$check( 'H9 ACPT: a new rule without an operator is refused, not stored "="', is_wp_error( $new ), wp_json_encode( $new ) );
		$ctl = minn_admin_acpt_builder_belongs_in( array( array( array( 'param' => 'post_type', 'operator' => '==', 'value' => 'post' ) ) ), array() );
		$check( 'H9 ACPT control: the page\'s "==" is stored "="', is_array( $ctl ) && '=' === ( $ctl[0]['operator'] ?? null ), is_wp_error( $ctl ) ? $ctl->get_error_message() : wp_json_encode( $ctl ) );
	} )();

	/*
	 * H10, H11 WooCommerce settings post data for an untouched retention
	 * period with no unit and a page setting never set.
	 */
	( static function () use ( $check, $skip ) {
		if ( ! function_exists( 'minn_admin_wc_settings_post_data' ) || ! class_exists( 'WC_Admin_Settings' ) ) {
			$skip( 'H10/H11 WooCommerce settings: not active' );
			return;
		}
		$fields = array(
			array( 'id' => 'minn_h10_retention', 'title' => 'Retention', 'type' => 'relative_date_selector', 'value' => array( 'number' => '3' ) ),
			array( 'id' => 'minn_h11_page', 'title' => 'Page', 'type' => 'single_select_page_with_search', 'value' => -1, 'args' => array() ),
			array( 'id' => 'minn_h11_set', 'title' => 'Page set', 'type' => 'single_select_page_with_search', 'value' => '7', 'args' => array() ),
		);
		$post = minn_admin_wc_settings_post_data( $fields, array() );
		$check( 'H10 WooCommerce: a retention period without a unit posts days', 'days' === ( $post['minn_h10_retention']['unit'] ?? null ), wp_json_encode( $post['minn_h10_retention'] ?? null ) );
		$check( 'H11 WooCommerce: a page never set posts blank, not "-1"', '' === ( $post['minn_h11_page'] ?? null ), wp_json_encode( $post['minn_h11_page'] ?? null ) );
		$check( 'H11 WooCommerce control: a page that is set posts its id', '7' === ( $post['minn_h11_set'] ?? null ), wp_json_encode( $post['minn_h11_set'] ?? null ) );
	} )();
} else {
	/*
	 * H12 Rank Math advanced robots: clear the last directive on a post.
	 */
	( static function () use ( $check, $skip, $once ) {
		if ( ! class_exists( '\RankMath\Helper' ) || ! function_exists( 'minn_admin_seo_rank_math_provider' ) ) {
			$skip( 'H12 Rank Math: not swapped in (MINN_SEO_SWAP=rank-math)' );
			return;
		}
		$pid = (int) wp_insert_post( array( 'post_title' => 'minn v049 hunt robots', 'post_status' => 'draft', 'post_type' => 'post' ) );
		if ( $pid < 2 ) {
			$check( 'H12 Rank Math: post fixture inserted', false );
			return;
		}
		$clean = $once( static function () use ( $pid ) {
			if ( 'minn v049 hunt robots' === get_the_title( $pid ) ) {
				wp_delete_post( $pid, true );
			}
		} );
		try {
			update_post_meta( $pid, 'rank_math_advanced_robots', array( 'max-snippet' => -1, 'max-video-preview' => false, 'max-image-preview' => false ) );
			$write = minn_admin_seo_rank_math_provider()['write'];
			$write( $pid, 'adv_max_snippet', '' );
			$adv = get_post_meta( $pid, 'rank_math_advanced_robots', true );
			$check( 'H12 Rank Math: clearing the last directive keeps an all-off map', is_array( $adv ) && $adv && ! array_filter( $adv, static function ( $v ) {
				return false !== $v;
			} ), wp_json_encode( $adv ) );
			$write( $pid, 'adv_max_snippet', '50' );
			$adv = get_post_meta( $pid, 'rank_math_advanced_robots', true );
			$check( 'H12 Rank Math control: setting a directive stores it', 50 === ( $adv['max-snippet'] ?? null ), wp_json_encode( $adv ) );
		} finally {
			$clean();
		}
	} )();
}

$failed = count( array_filter( $results, static function ( $ok ) {
	return ! $ok;
} ) );
printf( "\n%d passed, %d failed\n", count( $results ) - $failed, $failed );
if ( $failed ) {
	exit( 1 );
}
