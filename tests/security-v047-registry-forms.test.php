<?php
/**
 * Regressions for the v0.46.0 shipped-zip registry scan, forms lane.
 *
 *  - 04-1 = 13-1 Gravity Forms form builder: the builder's read serves a
 *    credential copy in a field's settings (HTML content, default value,
 *    description, choices, a sub-label) or the form's description masked,
 *    and the page sends every row back on every save. The save compared
 *    each value with the stored one byte for byte, so the masked copy read
 *    as an edit and "[redacted:…]" was written where the key was. An edit
 *    still carrying a mask was stored as well.
 *  - 01-1 Fluent Forms emails: Fluent's own validator ran inside the write
 *    loop, so a save that changed notification A and left notification B
 *    (or the confirmation) invalid stored A and then answered 400.
 *
 * The suite seeds a credential row of its own (a licence row shape, a
 * made-up key) before anything reads the list, and fixtures holding copies
 * of it. Sections SKIP when their plugin is inactive. Everything it makes is
 * removed however the run ends.
 *
 * Run: wp eval-file <ABSOLUTE path>/tests/security-v047-registry-forms.test.php --user=admin --path=<site>
 *
 * @package minn-admin
 */

// Ctrl-C, TERM and HUP end the suite through its cleanups (see the file).
require_once __DIR__ . '/lib/signals.php';

$results = array();
$check   = static function ( $label, $ok, $detail = '' ) use ( &$results ) {
	set_time_limit( 0 );
	$results[] = (bool) $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( $ok || '' === $detail ) ? '' : ' — ' . substr( (string) $detail, 0, 400 ) );
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

if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'Minn_Admin_Egress' ) ) {
	echo "SKIP  run with --user=<an administrator> on a site running Minn Admin\n";
	return;
}
global $wpdb;
rest_get_server();
$tag = strtolower( wp_generate_password( 6, false ) );

// What a browser receives: dispatch, the server's response filters, the
// body as sent (the egress fence runs on rest_pre_echo_response).
$send = static function ( $method, $route, $body = null ) {
	$server = rest_get_server();
	$req    = new WP_REST_Request( $method, $route );
	if ( null !== $body ) {
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
	}
	$req->set_header( 'X-WP-Nonce', wp_create_nonce( 'wp_rest' ) );
	$res  = apply_filters( 'rest_post_dispatch', rest_ensure_response( rest_do_request( $req ) ), $server, $req );
	$data = apply_filters( 'rest_pre_echo_response', $server->response_to_data( $res, false ), $server, $req );
	return array( $res->get_status(), json_decode( (string) wp_json_encode( $data ), true ) );
};
$fp  = static function ( $k ) {
	return '[redacted:' . substr( hash( 'sha256', $k ), 0, 6 ) . ']';
};
$has = static function ( $hay, $needle ) {
	return false !== strpos( is_string( $hay ) ? $hay : (string) wp_json_encode( $hay ), (string) $needle );
};

// The credential row (a licence row shape), seeded before anything reads
// the list.
$key        = 'MinnV47Forms' . strtoupper( wp_generate_password( 10, false ) ) . '7731qz';
$row        = 'pue_install_key_minnv47f' . $tag;
$t1         = $fp( $key );
$seed_clean = $once( static function () use ( $row ) {
	delete_option( $row );
	Minn_Admin_Egress::reset();
} );
add_option( $row, $key, '', 'no' );
Minn_Admin_Egress::reset();

try {
	/*
	 * 04-1 = 13-1 Gravity Forms builder.
	 */
	( static function () use ( $check, $skip, $send, $once, $has, $key, $t1, $tag ) {
		global $wpdb;
		if ( ! function_exists( 'minn_admin_gfb_available' ) || ! minn_admin_gfb_available() || ! GFCommon::current_user_can_any( array( 'gravityforms_edit_forms', 'gform_full_access' ) ) ) {
			$skip( '04-1 GF builder: Gravity Forms (with its editor save) inactive' );
			return;
		}
		$form_id = 0;
		$clean   = $once( static function () use ( &$form_id ) {
			if ( $form_id ) {
				GFAPI::delete_form( $form_id );
			}
		} );
		// What the page's gfbRowOut() sends for one field of the builder's read.
		$row_out = static function ( $f ) {
			$row = array( 'id' => $f['id'] );
			foreach ( (array) $f['settings'] as $k ) {
				if ( 'subInputs' === $k ) {
					$row['subInputs'] = array_map( static function ( $i ) {
						return array( 'id' => $i['id'], 'isHidden' => ! empty( $i['isHidden'] ), 'customLabel' => (string) ( $i['customLabel'] ?? '' ) );
					}, (array) $f['inputs'] );
				} elseif ( 'choices' === $k ) {
					$row['choices']           = array_map( static function ( $c ) {
						return array( 'text' => $c['text'], 'value' => $c['value'], 'isSelected' => ! empty( $c['isSelected'] ) );
					}, (array) $f['choices'] );
					$row['enableChoiceValue'] = ! empty( $f['enableChoiceValue'] );
				} elseif ( array_key_exists( $k, $f ) ) {
					$row[ $k ] = $f[ $k ];
				}
			}
			return $row;
		};
		// One builder save as the browser makes it: read the page's payload
		// through the response fence, let $edit change the rows (keyed by
		// field id), send them all.
		$served = null;
		$save   = static function ( callable $edit, $extra = array() ) use ( $send, $row_out, &$form_id, &$served ) {
			list( , $p ) = $send( 'GET', "/minn-admin/v1/gf/forms/{$form_id}/builder" );
			$served = $p;
			$rows   = array();
			foreach ( (array) ( $p['fields'] ?? array() ) as $f ) {
				$rows[ (int) $f['id'] ] = $row_out( $f );
			}
			$rows = $edit( $rows );
			return $send( 'POST', "/minn-admin/v1/gf/forms/{$form_id}/builder", array_merge( array(
				'stamp'  => (string) ( $p['stamp'] ?? '' ),
				'known'  => array_map( 'intval', array_keys( $rows ) ),
				'fields' => array_values( $rows ),
			), is_callable( $extra ) ? $extra( $p ) : $extra ) );
		};
		$meta  = static function () use ( $wpdb, &$form_id ) {
			return (string) $wpdb->get_var( $wpdb->prepare( 'SELECT display_meta FROM ' . GFFormsModel::get_meta_table_name() . ' WHERE form_id = %d', $form_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		};
		$field = static function ( $fid ) use ( $meta ) {
			foreach ( (array) ( json_decode( $meta(), true )['fields'] ?? array() ) as $f ) {
				if ( (int) ( $f['id'] ?? 0 ) === (int) $fid ) {
					return $f;
				}
			}
			return array();
		};
		$choice = static function ( $f, $value ) {
			foreach ( (array) ( $f['choices'] ?? array() ) as $c ) {
				if ( (string) ( $c['value'] ?? '' ) === (string) $value ) {
					return $c;
				}
			}
			return null;
		};
		$input  = static function ( $f, $id ) {
			foreach ( (array) ( $f['inputs'] ?? array() ) as $in ) {
				if ( (string) ( $in['id'] ?? '' ) === (string) $id ) {
					return $in;
				}
			}
			return null;
		};
		$sent_row = static function ( $rows, $id, callable $fn ) {
			$rows[ $id ] = $fn( $rows[ $id ] );
			return $rows;
		};
		try {
			$html    = '<p>Map key ' . $key . '</p>';
			$desc    = 'Token ' . $key;
			$fdesc   = 'Form uses ' . $key;
			$form_id = GFAPI::add_form( array(
				'title'       => 'Minn v047 forms ' . $tag,
				'description' => $fdesc,
				'fields'      => array(
					array( 'id' => 1, 'type' => 'html', 'label' => 'Embed', 'content' => $html ),
					array( 'id' => 2, 'type' => 'hidden', 'label' => 'Token', 'defaultValue' => $key ),
					array( 'id' => 3, 'type' => 'text', 'label' => 'Your name', 'description' => $desc ),
					array(
						'id'                => 4,
						'type'              => 'select',
						'label'             => 'Plan',
						'enableChoiceValue' => true,
						'choices'           => array(
							array( 'text' => 'Keyed ' . $key, 'value' => $key, 'isSelected' => false, 'price' => '' ),
							array( 'text' => 'Plain', 'value' => 'plain', 'isSelected' => false, 'price' => '' ),
						),
					),
					array(
						'id'         => 5,
						'type'       => 'name',
						'label'      => 'Name',
						'nameFormat' => 'advanced',
						'inputs'     => array(
							array( 'id' => '5.3', 'label' => 'First', 'name' => '', 'customLabel' => 'First ' . $key ),
							array( 'id' => '5.6', 'label' => 'Last', 'name' => '', 'customLabel' => 'Surname' ),
						),
					),
				),
			) );
			if ( is_wp_error( $form_id ) || ! $form_id ) {
				$form_id = 0;
				$check( '04-1 GF builder: a test form is stored', false );
				return;
			}
			$form_id = (int) $form_id;

			list( $st, $p ) = $send( 'GET', "/minn-admin/v1/gf/forms/{$form_id}/builder" );
			$by_id = array();
			foreach ( (array) ( $p['fields'] ?? array() ) as $f ) {
				$by_id[ (int) $f['id'] ] = $f;
			}
			$check( '04-1 GF builder: the read serves each copy of the key masked (fixture)', 200 === $st && ! $has( $p, $key ) && $has( $by_id[1]['content'] ?? '', $t1 ) && $t1 === ( $by_id[2]['defaultValue'] ?? '' ) && $has( $by_id[3]['description'] ?? '', $t1 ) && $has( $by_id[4]['choices'] ?? '', $t1 ) && $has( $by_id[5]['inputs'] ?? '', $t1 ) && $has( $p['form']['description'] ?? '', $t1 ), $st . ' ' . wp_json_encode( $by_id ) );

			// An unrelated relabel sends every row back as served.
			list( $st, $d ) = $save( static function ( $rows ) use ( $sent_row ) {
				return $sent_row( $rows, 3, static function ( $r ) {
					$r['label'] = 'Full name';
					return $r;
				} );
			} );
			$m = $meta();
			$check( '04-1 GF builder: relabelling one field answers 200 and writes the label (control)', 200 === $st && 'Full name' === ( $field( 3 )['label'] ?? null ), $st . ' ' . wp_json_encode( 200 === $st ? $field( 3 )['label'] ?? null : $d ) );
			$check( '04-1 GF builder: and no field nobody touched gets the placeholder', ! $has( $m, '[redacted' ), $m );
			$check( '04-1 GF builder: the HTML field keeps its content holding the real key', $html === ( $field( 1 )['content'] ?? null ), wp_json_encode( $field( 1 )['content'] ?? null ) );
			$check( '04-1 GF builder: the hidden field keeps its default value', $key === ( $field( 2 )['defaultValue'] ?? null ), wp_json_encode( $field( 2 )['defaultValue'] ?? null ) );
			$check( '04-1 GF builder: the relabelled field keeps its description', $desc === ( $field( 3 )['description'] ?? null ), wp_json_encode( $field( 3 )['description'] ?? null ) );
			$c4 = $choice( $field( 4 ), $key );
			$check( '04-1 GF builder: the drop-down keeps the keyed choice\'s text and value', $c4 && 'Keyed ' . $key === ( $c4['text'] ?? null ), wp_json_encode( $field( 4 )['choices'] ?? null ) );
			$check( '04-1 GF builder: the Name field keeps its sub-label', 'First ' . $key === ( $input( $field( 5 ), '5.3' )['customLabel'] ?? null ), wp_json_encode( $input( $field( 5 ), '5.3' ) ) );

			// Another choice and another sub-label edited, the masked ones sent as served.
			list( $st, $d ) = $save( static function ( $rows ) use ( $sent_row ) {
				$rows = $sent_row( $rows, 4, static function ( $r ) {
					foreach ( $r['choices'] as &$c ) {
						if ( 'plain' === $c['value'] ) {
							$c['text'] = 'Basic';
						}
					}
					unset( $c );
					return $r;
				} );
				return $sent_row( $rows, 5, static function ( $r ) {
					foreach ( $r['subInputs'] as &$s ) {
						if ( '5.6' === (string) $s['id'] ) {
							$s['customLabel'] = 'Family name';
						}
					}
					unset( $s );
					return $r;
				} );
			} );
			$f4 = $field( 4 );
			$f5 = $field( 5 );
			$check( '04-1 GF builder: editing the other choice and sub-label answers 200 and writes both (control)', 200 === $st && 'Basic' === ( $choice( $f4, 'plain' )['text'] ?? null ) && 'Family name' === ( $input( $f5, '5.6' )['customLabel'] ?? null ), $st . ' ' . wp_json_encode( 200 === $st ? array( $f4['choices'] ?? null, $f5['inputs'] ?? null ) : $d ) );
			$check( '04-1 GF builder: and the keyed choice and sub-label keep the real key', 'Keyed ' . $key === ( $choice( $f4, $key )['text'] ?? null ) && 'First ' . $key === ( $input( $f5, '5.3' )['customLabel'] ?? null ) && ! $has( $meta(), '[redacted' ), wp_json_encode( array( $f4['choices'] ?? null, $f5['inputs'] ?? null ) ) );

			// An edit that keeps the mask: refused, and nothing is written.
			$before = $meta();
			list( $st, $d ) = $save( static function ( $rows ) use ( $sent_row ) {
				$rows = $sent_row( $rows, 3, static function ( $r ) {
					$r['label'] = 'Not written';
					return $r;
				} );
				return $sent_row( $rows, 1, static function ( $r ) {
					$r['content'] .= '<p>More</p>';
					return $r;
				} );
			} );
			$check( '04-1 GF builder: HTML content edited around its mask is refused (400 minn_egress_mask)', 400 === $st && 'minn_egress_mask' === ( $d['code'] ?? '' ), $st . ' ' . wp_json_encode( $d ) );
			$check( '04-1 GF builder: and the refused save writes nothing, the other field\'s edit included', $before === $meta(), $meta() );

			list( $st, $d ) = $save( static function ( $rows ) use ( $sent_row ) {
				return $sent_row( $rows, 2, static function ( $r ) {
					$r['defaultValue'] .= 'x';
					return $r;
				} );
			} );
			$check( '04-1 GF builder: a default value edited around its mask is refused and writes nothing', 400 === $st && $before === $meta(), $st . ' ' . wp_json_encode( $d ) );

			list( $st, $d ) = $save( static function ( $rows ) use ( $sent_row ) {
				return $sent_row( $rows, 4, static function ( $r ) {
					foreach ( $r['choices'] as &$c ) {
						if ( 'Basic' !== $c['text'] ) {
							$c['text'] .= ' tier';
						}
					}
					unset( $c );
					return $r;
				} );
			} );
			$check( '04-1 GF builder: a choice edited around its mask is refused and writes nothing', 400 === $st && $before === $meta(), $st . ' ' . wp_json_encode( $d ) );

			list( $st, $d ) = $save( static function ( $rows ) use ( $t1 ) {
				$rows[] = array( 'type' => 'text', 'tempId' => 'v47', 'label' => 'Copy ' . $t1 );
				return $rows;
			} );
			$check( '04-1 GF builder: a new field carrying a mask is refused and writes nothing', 400 === $st && $before === $meta(), $st . ' ' . wp_json_encode( $d ) );

			list( $st, $d ) = $save( static function ( $rows ) {
				return $rows;
			}, static function ( $p ) {
				return array( 'description' => (string) ( $p['form']['description'] ?? '' ) . ' and more' );
			} );
			$check( '04-1 GF builder: the form description edited around its mask is refused and writes nothing', 400 === $st && $before === $meta(), $st . ' ' . wp_json_encode( $d ) );

			list( $st, $d ) = $save( static function ( $rows ) {
				return $rows;
			}, static function ( $p ) {
				return array( 'description' => (string) ( $p['form']['description'] ?? '' ), 'title' => (string) ( $p['form']['title'] ?? '' ) . ' renamed' );
			} );
			$fm = json_decode( $meta(), true );
			$check( '04-1 GF builder: the form description sent back as served keeps the real key, and a rename lands (control)', 200 === $st && $fdesc === ( $fm['description'] ?? null ) && 'Minn v047 forms ' . $tag . ' renamed' === ( $fm['title'] ?? null ), $st . ' ' . wp_json_encode( 200 === $st ? array( $fm['title'] ?? null, $fm['description'] ?? null ) : $d ) );

			list( $st, $d ) = $send( 'POST', '/minn-admin/v1/gf/forms', array( 'title' => 'Minn v047 forms new ' . $tag, 'description' => 'Copy ' . $t1 ) );
			$made = (int) ( $d['id'] ?? 0 );
			if ( $made ) {
				GFAPI::delete_form( $made );
			}
			$check( '04-1 GF builder: a new form whose description carries a mask is refused', 400 === $st && ! $made, $st . ' ' . wp_json_encode( $d ) );

			// A real edit of the content is written.
			list( $st, $d ) = $save( static function ( $rows ) use ( $sent_row, $tag ) {
				return $sent_row( $rows, 1, static function ( $r ) use ( $tag ) {
					$r['content'] = '<p>Plain ' . $tag . '</p>';
					return $r;
				} );
			} );
			$check( '04-1 GF builder control: a real content edit is written', 200 === $st && '<p>Plain ' . $tag . '</p>' === ( $field( 1 )['content'] ?? null ), $st . ' ' . wp_json_encode( 200 === $st ? $field( 1 )['content'] ?? null : $d ) );
		} finally {
			$clean();
		}
	} )();

	/*
	 * 01-1 Fluent Forms emails: every refusal Fluent's own validator makes
	 * comes before the first write.
	 */
	( static function () use ( $check, $skip, $send, $once, $tag ) {
		global $wpdb;
		if ( ! defined( 'FLUENTFORM' ) || ! function_exists( 'minn_admin_fluent_notifications' ) || ! class_exists( '\FluentForm\App\Services\Settings\Validator' ) ) {
			$skip( '01-1 Fluent Forms emails: inactive' );
			return;
		}
		$p = $wpdb->prefix;
		$wpdb->query( 'START TRANSACTION' );
		$rollback = $once( static function () use ( $wpdb ) {
			$wpdb->query( 'ROLLBACK' );
			wp_cache_flush();
		} );
		$deny = static function ( $caps, $cap ) {
			return 'unfiltered_html' === $cap ? array( 'do_not_allow' ) : $caps;
		};
		try {
			$wpdb->insert( "{$p}fluentform_forms", array( 'title' => 'Minn v047 Fluent ' . $tag, 'status' => 'published', 'form_fields' => wp_json_encode( array( 'fields' => array() ) ), 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ) );
			$fid  = (int) $wpdb->insert_id;
			$note = static function ( $name, $subject ) {
				return wp_json_encode( array( 'name' => $name, 'sendTo' => array( 'type' => 'email', 'email' => '{wp.admin_email}', 'field' => '', 'routing' => array() ), 'subject' => $subject, 'message' => '<p>Hello</p>', 'enabled' => true ) );
			};
			$a_was = $note( 'First email', 'First' );
			$wpdb->insert( "{$p}fluentform_form_meta", array( 'form_id' => $fid, 'meta_key' => 'notifications', 'value' => $a_was ) );
			$aid = (int) $wpdb->insert_id;
			$wpdb->insert( "{$p}fluentform_form_meta", array( 'form_id' => $fid, 'meta_key' => 'notifications', 'value' => $note( 'Second email', 'Second' ) ) );
			$bid = (int) $wpdb->insert_id;
			$wpdb->insert( "{$p}fluentform_form_meta", array( 'form_id' => $fid, 'meta_key' => 'formSettings', 'value' => wp_json_encode( array( 'confirmation' => array( 'redirectTo' => 'samePage', 'messageToShow' => '<p>Thanks</p>', 'samePageFormBehavior' => 'hide_form', 'customPage' => null, 'customUrl' => null ) ) ) ) );
			$sid = (int) $wpdb->insert_id;
			if ( ! $fid || ! $aid || ! $bid || ! $sid ) {
				$check( '01-1 Fluent: the fixture rows are seeded', false, $wpdb->last_error );
				return;
			}
			$value = static function ( $id ) use ( $wpdb, $p ) {
				return (string) $wpdb->get_var( $wpdb->prepare( "SELECT value FROM {$p}fluentform_form_meta WHERE id = %d", $id ) ); // phpcs:ignore
			};
			$subj  = static function () use ( $value, $aid ) {
				return (string) ( json_decode( $value( $aid ), true )['subject'] ?? '' );
			};
			$conf  = static function () use ( $value, $sid ) {
				return (array) ( json_decode( $value( $sid ), true )['confirmation'] ?? array() );
			};
			$reset = static function () use ( $wpdb, $p, $aid, $a_was ) {
				$wpdb->update( "{$p}fluentform_form_meta", array( 'value' => $a_was ), array( 'id' => $aid ) );
			};
			$route = "/minn-admin/v1/fluent-forms/forms/{$fid}/emails";

			// A later notification Fluent refuses (its message cleared).
			$b_was          = $value( $bid );
			list( $st, $d ) = $send( 'POST', $route, array( 'notifications' => array( array( 'metaId' => $aid, 'subject' => 'Changed ' . $tag ), array( 'metaId' => $bid, 'message' => '' ) ) ) );
			$check( '01-1 Fluent: a later notification Fluent refuses answers 400 on that notification', 400 === $st && 'minn_ffe_invalid' === ( $d['code'] ?? '' ) && 'n' . $bid . '.message' === ( $d['data']['field'] ?? '' ), $st . ' ' . wp_json_encode( $d ) );
			$check( '01-1 Fluent: and leaves the earlier notification in the same save unwritten', 'First' === $subj() && $b_was === $value( $bid ), $subj() );
			$reset();

			// Its Send-to address cleared.
			list( $st, $d ) = $send( 'POST', $route, array( 'notifications' => array( array( 'metaId' => $aid, 'subject' => 'Changed ' . $tag ), array( 'metaId' => $bid, 'toEmail' => '' ) ) ) );
			$check( '01-1 Fluent: a later notification with no Send-to address is refused and the earlier one stays unwritten', 400 === $st && 'n' . $bid . '.toEmail' === ( $d['data']['field'] ?? '' ) && 'First' === $subj(), $st . ' ' . wp_json_encode( $d ) . ' ' . $subj() );
			$reset();

			// The confirmation switched to a redirect with no address.
			$c_was          = $conf();
			list( $st, $d ) = $send( 'POST', $route, array( 'notifications' => array( array( 'metaId' => $aid, 'subject' => 'Changed ' . $tag ) ), 'confirmation' => array( 'redirectTo' => 'customUrl' ) ) );
			$check( '01-1 Fluent: a redirect confirmation with no address answers 400 on the confirmation', 400 === $st && 'confirmation.customUrl' === ( $d['data']['field'] ?? '' ), $st . ' ' . wp_json_encode( $d ) );
			$check( '01-1 Fluent: and leaves the notification in the same save unwritten', 'First' === $subj() && $c_was === $conf(), $subj() . ' ' . wp_json_encode( $conf() ) );
			$reset();

			// One Fluent empties only in its own cleaning (no unfiltered_html:
			// sanitize_url drops a javascript: address), after the raw check.
			add_filter( 'map_meta_cap', $deny, 10, 2 );
			try {
				list( $st, $d ) = $send( 'POST', $route, array( 'notifications' => array( array( 'metaId' => $aid, 'subject' => 'Changed ' . $tag ) ), 'confirmation' => array( 'redirectTo' => 'customUrl', 'customUrl' => 'javascript:alert(1)' ) ) );
			} finally {
				remove_filter( 'map_meta_cap', $deny, 10 );
			}
			$check( '01-1 Fluent: an address Fluent\'s cleaning empties is refused and the notification stays unwritten', 400 === $st && 'First' === $subj() && $c_was === $conf(), $st . ' ' . $subj() . ' ' . wp_json_encode( $conf() ) );
			$reset();

			// Controls: a clean save writes every part.
			list( $st, $d ) = $send( 'POST', $route, array( 'notifications' => array( array( 'metaId' => $aid, 'subject' => 'Changed ' . $tag ), array( 'metaId' => $bid, 'subject' => 'Second ' . $tag ) ), 'confirmation' => array( 'redirectTo' => 'customUrl', 'customUrl' => 'https://example.com/thanks-' . $tag ) ) );
			$check( '01-1 Fluent control: a clean save writes both notifications and the confirmation', 200 === $st && 'Changed ' . $tag === $subj() && 'Second ' . $tag === ( json_decode( $value( $bid ), true )['subject'] ?? '' ) && 'https://example.com/thanks-' . $tag === ( $conf()['customUrl'] ?? '' ), $st . ' ' . wp_json_encode( 200 === $st ? $conf() : $d ) );
		} finally {
			$rollback();
		}
	} )();
} finally {
	$seed_clean();
}

$failed = count( array_filter( $results, static function ( $ok ) {
	return ! $ok;
} ) );
printf( "\n%d passed, %d failed\n", count( $results ) - $failed, $failed );
if ( $failed ) {
	exit( 1 );
}
