<?php
/**
 * Regressions for the v0.48.0 shipped-zip registry scan, mail logs (findings
 * that open v0.49.0): a log read a message out of the blob beside its body,
 * past the reset-key mask and the preview capability, and the mask judged
 * reset links in shapes it then could not hide the key of.
 *
 * Each section checks as a reader the log hides keys from, with a control
 * where a fix could over-block. Sections SKIP when their plugin is inactive.
 *
 * Run: wp eval-file tests/security-v049-registry-mail.test.php --user=admin --path=<site>
 *
 * @package minn-admin
 */

$results = array();
$check   = function ( $label, $ok, $detail = '' ) use ( &$results ) {
	set_time_limit( 0 );
	$results[] = $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, '' !== $detail ? " — {$detail}" : '' );
};
$summary = function () use ( &$results ) {
	printf( "\nsecurity-v049-registry-mail: %d/%d passed\n", count( array_filter( $results ) ), count( $results ) );
	exit( count( array_filter( $results ) ) === count( $results ) ? 0 : 1 );
};

// On a network wp_delete_user() only takes an account off this site.
$drop_user = function ( $id ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	if ( is_multisite() ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		return wpmu_delete_user( $id );
	}
	return wp_delete_user( $id );
};

// Whether any eight characters of a key show, soft breaks taken out first.
$leaks = function ( $out, $key ) {
	$out = str_replace( array( "=\r\n", "=\n" ), '', (string) $out );
	for ( $o = 0; $o + 8 <= strlen( $key ); $o++ ) {
		if ( false !== strpos( $out, substr( $key, $o, 8 ) ) ) {
			return true;
		}
	}
	return false;
};

$admin = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
if ( ! $admin ) {
	echo "SKIP  no administrator to run as\n";
	return;
}
$admin = $admin[0]->ID;
wp_set_current_user( $admin );
if ( ! function_exists( 'minn_admin_mail_mask_reset_keys' ) ) {
	$check( 'the mail kit is loaded', false );
	$summary();
}

// A reader the mail logs hide reset keys from: an Editor holds no edit_users,
// on a single site and on a network alike.
$reader = wp_insert_user( array(
	'user_login' => 'minn-v049-mail-' . wp_rand(),
	'user_pass'  => wp_generate_password( 24 ),
	'role'       => 'editor',
	'user_email' => 'minn-v049-mail-' . wp_rand() . '@example.com',
) );
if ( is_wp_error( $reader ) ) {
	$check( 'a reader to run as', false, $reader->get_error_message() );
	$summary();
}

try {
	/*
	 * Mask-2: the mask decided on a decoded link but hid the key on the raw
	 * one, so a separator written as a percent-encoded entity (%26amp%3B,
	 * %26%23038%3B) left the key whole, as did a key in a fragment or a path
	 * segment, a scheme written with entities or JSON escapes, and a link
	 * without a scheme. Every shape is judged and hidden on the same view.
	 */
	( function () use ( $check, $leaks, $reader, $admin ) {
		$site   = home_url( '/' );
		$login  = wp_login_url();
		$host   = (string) wp_parse_url( $site, PHP_URL_HOST );
		$path   = (string) wp_parse_url( $login, PHP_URL_PATH );
		$n      = 0;
		$key    = function () use ( &$n ) {
			return 'Mk' . str_pad( (string) ++$n, 2, '0', STR_PAD_LEFT ) . 'q7Lw9PaZx3Vb8Nc2';
		};
		$cases  = array();
		$add    = function ( $label, callable $shape ) use ( &$cases, $key ) {
			$k                = $key();
			$cases[ $label ] = array( $shape( $k ), $k );
		};
		$wrap   = function ( $inner ) {
			return 'https://track.example/r?to=' . rawurlencode( $inner );
		};
		// Shapes the mask already hid: they stay hidden.
		$add( 'core plain', function ( $k ) use ( $login ) {
			return "Visit {$login}?action=rp&key={$k}&login=root\n";
		} );
		$add( '&amp; separators', function ( $k ) use ( $login ) {
			return "<a href=\"{$login}?action=rp&amp;key={$k}&amp;login=root\">Reset</a>";
		} );
		$add( '&#038; separators', function ( $k ) use ( $login ) {
			return "<a href=\"{$login}?action=rp&#038;key={$k}&#038;login=root\">Reset</a>";
		} );
		$add( '&#x26; separators', function ( $k ) use ( $login ) {
			return "<a href=\"{$login}?action=rp&#x26;key={$k}&#x26;login=root\">Reset</a>";
		} );
		$add( 'a %26 wrapper', function ( $k ) use ( $login, $wrap ) {
			return $wrap( "{$login}?action=rp&key={$k}&login=root" );
		} );
		$add( 'a doubly encoded wrapper', function ( $k ) use ( $login, $wrap ) {
			return $wrap( rawurlencode( "{$login}?action=rp&key={$k}&login=root" ) );
		} );
		$add( 'an "=" written &#61;', function ( $k ) use ( $login ) {
			return "<a href=\"{$login}?action=rp&amp;key&#61;{$k}&amp;login=root\">Reset</a>";
		} );
		$add( 'quoted-printable =3D', function ( $k ) use ( $login ) {
			return "{$login}?action=3Drp&key=3D{$k}&login=3Droot";
		} );
		$add( 'an SMTP transcript cut by soft breaks', function ( $k ) use ( $login ) {
			$qp = quoted_printable_encode( str_repeat( 'Hello there. ', 6 ) . '<a href="' . "{$login}?action=rp&key={$k}&login=root" . '">Reset</a>' );
			return implode( "\n", array_map( function ( $l ) {
				return '2026-10-28 10:00:00 CLIENT -> SERVER: ' . $l;
			}, preg_split( '/\r?\n/', $qp ) ) );
		} );
		$add( 'the WooCommerce query', function ( $k ) use ( $site ) {
			return "{$site}my-account/lost-password/?key={$k}&id=5";
		} );
		$add( 'an upper-case scheme', function ( $k ) use ( $login ) {
			return strtoupper( substr( $login, 0, 5 ) ) . substr( $login, 5 ) . "?action=rp&key={$k}&login=root";
		} );
		$add( 'Ultimate Member', function ( $k ) use ( $site ) {
			return "{$site}password-reset/?act=reset_password&hash={$k}&login=root";
		} );
		$add( 'MemberPress', function ( $k ) use ( $site ) {
			return "{$site}login/?action=reset_password&mkey={$k}&u=root";
		} );
		// Shapes it judged and left whole.
		$add( 'a wrapper over &amp; (%26amp%3B)', function ( $k ) use ( $site, $wrap ) {
			return $wrap( "{$site}my-account/lost-password/?id=5&amp;key={$k}" );
		} );
		$add( 'a wrapper over esc_url\'s &#038; (%26%23038%3B)', function ( $k ) use ( $site, $wrap ) {
			return $wrap( "{$site}my-account/lost-password/?id=5&#038;key={$k}" );
		} );
		$add( 'a wrapper over a core link with &amp;', function ( $k ) use ( $login, $wrap ) {
			return $wrap( "{$login}?action=rp&amp;key={$k}&amp;login=root" );
		} );
		$add( 'a key in a fragment', function ( $k ) use ( $site ) {
			return "{$site}reset/#key={$k}&login=root";
		} );
		$add( 'a key as a path segment', function ( $k ) use ( $site ) {
			return "{$site}password-reset/{$k}/root/";
		} );
		$add( 'a scheme written with entities', function ( $k ) use ( $host, $path ) {
			return "<a href=\"https&#58;&#47;&#47;{$host}{$path}?action=rp&amp;key={$k}&amp;login=root\">Reset</a>";
		} );
		$add( 'a JSON-escaped link (a debug log line)', function ( $k ) use ( $login ) {
			return 'Starting email send with the following params: ' . wp_json_encode( array( 'content' => "Visit {$login}?action=rp&key={$k}&login=root" ), 0 );
		} );
		$add( 'JSON with & separators', function ( $k ) use ( $login ) {
			return 'params: ' . wp_json_encode( array( 'content' => "{$login}?action=rp&key={$k}&login=root" ), JSON_HEX_AMP );
		} );
		$add( 'a protocol-relative link', function ( $k ) use ( $host, $path ) {
			return "<a href=\"//{$host}{$path}?action=rp&amp;key={$k}&amp;login=root\">Reset</a>";
		} );
		$add( 'a relative link', function ( $k ) use ( $path ) {
			return "<a href=\"{$path}?action=rp&amp;key={$k}&amp;login=root\">Reset</a>";
		} );
		$add( 'a key after a percent-encoded ";"', function ( $k ) use ( $login, $wrap ) {
			return $wrap( "{$login}?action=rp;key={$k};login=root" );
		} );

		wp_set_current_user( $reader );
		$check( 'Mask-2 the reader does not read mail bodies whole (the premise)', ! minn_admin_mail_bodies_open() );
		foreach ( $cases as $label => $case ) {
			list( $body, $k ) = $case;
			$out = minn_admin_mail_mask_reset_keys( $body );
			$check( "Mask-2 {$label}: the key is hidden", ! $leaks( $out, $k ), substr( $out, 0, 240 ) );
		}
		$plain = (string) minn_admin_mail_mask_reset_keys( $cases['core plain'][0] );
		$check( 'Mask-2 control: a masked link keeps its host and its login', false !== strpos( $plain, (string) wp_parse_url( $login, PHP_URL_HOST ) ) && false !== strpos( $plain, 'login=root' ), $plain );
		$order = "{$site}checkout/order-received/7/?key=wc_order_KeepMe0123456";
		$check( 'Mask-2 control: an order link keeps its key', minn_admin_mail_mask_reset_keys( $order ) === $order );
		$news = "Read more: https://news.example/spring/?utm_campaign=Spring2026Newsletter01&id=Abcdefghijklmnop1234 today";
		$check( 'Mask-2 control: a link that is no reset link keeps its long values', minn_admin_mail_mask_reset_keys( $news ) === $news, minn_admin_mail_mask_reset_keys( $news ) );
		$prose = 'A sentence with a longwordwithoutanyspaces1234 and lost-password in it.';
		$check( 'Mask-2 control: prose keeps its words', minn_admin_mail_mask_reset_keys( $prose ) === $prose, minn_admin_mail_mask_reset_keys( $prose ) );
		wp_set_current_user( $admin );
		if ( minn_admin_mail_bodies_open() ) {
			$all = implode( "\n", array_map( function ( $c ) {
				return $c[0];
			}, $cases ) );
			$check( 'Mask-2 control: who reads mail whole reads every body as logged', minn_admin_mail_mask_reset_keys( $all ) === $all );
		}
	} )();

	/*
	 * Sections-1: the kit masked a detail's body and none of the sections an
	 * adapter adds after it (headers, a provider's reply, a failure), so a
	 * blob beside the body served the key whole.
	 */
	( function () use ( $check, $leaks, $reader, $admin ) {
		$k    = 'SecQ7Lw9PaZx3Vb8Nc2a';
		$link = wp_login_url() . "?action=rp&key={$k}&login=root";
		$more = array(
			array( 'title' => 'Headers', 'rows' => array( array( 'label' => 'Stored headers', 'value' => array( 'content' => "Visit {$link}" ), 'type' => 'kv-table' ) ) ),
			minn_admin_mail_failure( "SMTP said no to {$link}" ),
		);
		wp_set_current_user( $reader );
		$out = wp_json_encode( minn_admin_mail_sections( array( array( 'label' => 'Note', 'value' => $link ) ), 'Password Reset', null, $more ) );
		wp_set_current_user( $admin );
		$check( 'Sections-1 no section of a detail serves a reset key to a reader the log hides keys from', ! $leaks( $out, $k ), substr( (string) $out, 0, 400 ) );
	} )();

	/*
	 * GSMTP-1: Gravity SMTP's event detail read "stored headers" from the
	 * headers key to the end of the extra blob, through params, where a Zoho
	 * send logs the message whole, and the list searched extra for every log
	 * reader while it searched the message only for who may read it whole.
	 */
	( function () use ( $check, $leaks, $reader, $admin ) {
		global $wpdb;
		if ( ! function_exists( 'minn_admin_gsmtp_event_view' ) || ! function_exists( 'minn_admin_gravity_smtp_active' ) || ! minn_admin_gravity_smtp_active() ) {
			echo "SKIP  GSMTP-1 Gravity SMTP inactive\n";
			return;
		}
		$table = $wpdb->prefix . 'gravitysmtp_events';
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			echo "SKIP  GSMTP-1 no events table\n";
			return;
		}
		$tag  = 'v049mail' . wp_rand( 100000, 999999 );
		$k    = 'GsQ7Lw9PaZx3Vb8Nc2aT';
		$to   = "{$tag}@example.com";
		$body = "Someone has requested a password reset for the following account:\r\n\r\nUsername: root\r\n\r\nPhrase {$tag}zz\r\n\r\nTo reset your password, visit the following address:\r\n\r\n" . wp_login_url() . "?action=rp&key={$k}&login=root&wp_lang=en_US\r\n";
		$s    = function ( $v ) {
			return 's:' . strlen( $v ) . ':"' . $v . '";';
		};
		// Their Connector_Base::set_email_log_data() order and shapes: the
		// recipients are their Recipient_Collection objects, Zoho's request
		// body rides params with the message as content.
		$recipient = 'O:53:"Gravity_Forms\Gravity_SMTP\Utils\Recipient_Collection":1:{' . $s( 'recipients' ) . 'a:1:{i:0;O:42:"Gravity_Forms\Gravity_SMTP\Utils\Recipient":2:{' . $s( 'email' ) . $s( $to ) . $s( 'name' ) . $s( 'Root' ) . '}}}';
		$extra     = 'a:6:{' . $s( 'to' ) . $recipient . $s( 'from' ) . $s( 'sender@example.com' )
			. $s( 'headers' ) . 'a:1:{' . $s( 'content-type' ) . $s( 'text/plain' ) . '}'
			. $s( 'attachments' ) . 'a:0:{}' . $s( 'source' ) . $s( 'WordPress' )
			. $s( 'params' ) . 'a:5:{' . $s( 'fromAddress' ) . $s( 'sender@example.com' ) . $s( 'toAddress' ) . $s( $to ) . $s( 'subject' ) . $s( 'Password Reset' ) . $s( 'content' ) . $s( $body ) . $s( 'mailFormat' ) . $s( 'plaintext' ) . '}}';
		$now = gmdate( 'Y-m-d H:i:s' );
		$ok  = $wpdb->insert( $table, array(
			'date_created' => $now,
			'date_updated' => $now,
			'status'       => 'sent',
			'service'      => 'zoho',
			'subject'      => "Password Reset {$tag}",
			'message'      => $body,
			'extra'        => $extra,
		) );
		$id  = (int) $wpdb->insert_id;
		if ( ! $ok || $id < 1 ) {
			$check( 'GSMTP-1 a Zoho-shaped event is seeded', false, $wpdb->last_error );
			return;
		}
		$caps = array( minn_admin_gsmtp_cap( 'VIEW_EMAIL_LOG' ), minn_admin_gsmtp_cap( 'VIEW_EMAIL_LOG_DETAILS' ) );
		$user = new WP_User( $reader );
		try {
			$view = minn_admin_gsmtp_event_view( $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A ) );
			$kv   = array();
			foreach ( $view['sections'] as $sec ) {
				foreach ( $sec['rows'] as $row ) {
					if ( 'kv-table' === ( $row['type'] ?? '' ) ) {
						$kv = (array) $row['value'];
					}
				}
			}
			$check( 'GSMTP-1 stored headers are the headers array alone', array( 'content-type' ) === array_keys( $kv ), wp_json_encode( array_keys( $kv ) ) );
			$check( 'GSMTP-1 control: a stored header still shows', 'text/plain' === ( $kv['content-type'] ?? '' ) );

			$find = function ( $term ) use ( $id ) {
				$req = new WP_REST_Request( 'GET', '/minn-admin/v1/gravity-smtp/events' );
				$req->set_param( 'search', $term );
				$res = rest_do_request( $req );
				return array( 200 === $res->get_status() && (bool) preg_match( '/"id":' . $id . '[,}]/', (string) wp_json_encode( $res->get_data() ) ), $res->get_status() );
			};
			list( $hit ) = $find( "{$tag}zz" );
			$check( 'GSMTP-1 control: who reads mail whole finds an event by a phrase of its message', $hit );

			foreach ( $caps as $cap ) {
				$user->add_cap( $cap );
			}
			wp_set_current_user( 0 );
			wp_set_current_user( $reader );
			$check( 'GSMTP-1 the reader holds the log and its details but not the preview (the premise)', current_user_can( $caps[1] ) && ! minn_admin_gsmtp_can_preview() && ! minn_admin_mail_bodies_open() );
			$res = rest_do_request( new WP_REST_Request( 'GET', "/minn-admin/v1/gravity-smtp/events/{$id}/view" ) );
			$out = (string) wp_json_encode( $res->get_data() );
			$check( 'GSMTP-1 the event detail serves the reader no reset key', 200 === $res->get_status() && ! $leaks( $out, $k ), $res->get_status() . ' ' . substr( $out, 0, 300 ) );
			$res = rest_do_request( new WP_REST_Request( 'GET', "/minn-admin/v1/gravity-smtp/events/{$id}" ) );
			$check( 'GSMTP-1 the event item serves the reader no reset key', 200 === $res->get_status() && ! $leaks( (string) wp_json_encode( $res->get_data() ), $k ), (string) $res->get_status() );
			list( $hit, $status ) = $find( 'key=' . substr( $k, 0, 6 ) );
			$check( 'GSMTP-1 a search does not find the event by its reset key', 200 === $status && ! $hit, "status {$status}" . ( $hit ? ', found' : '' ) );
			list( $hit ) = $find( "{$tag}zz" );
			$check( 'GSMTP-1 a search does not find the event by a phrase of its message', ! $hit );
			list( $hit ) = $find( "Password Reset {$tag}" );
			$check( 'GSMTP-1 control: the reader finds the event by its subject', $hit );
			list( $hit ) = $find( $to );
			$check( 'GSMTP-1 control: the reader finds the event by its recipient\'s address', $hit );
		} finally {
			wp_set_current_user( $admin );
			foreach ( $caps as $cap ) {
				$user->remove_cap( $cap );
			}
			$wpdb->delete( $table, array( 'id' => $id ) );
		}
	} )();

	/*
	 * Search-1, the class: a mail log's list search may name a column that
	 * holds a message (or a blob beside it) only for who reads mail whole.
	 */
	( function () use ( $check ) {
		$body = array( 'message', 'body', 'content', 'extra', 'line', 'original_message', 'session_transcript', 'params', 'raw' );
		$open = array();
		foreach ( glob( MINN_ADMIN_DIR . 'includes/adapters/*.php' ) as $file ) {
			$src = (string) file_get_contents( $file );
			if ( false === strpos( $src, 'minn_admin_mail_surface' ) && false === strpos( $src, 'minn_admin_mail_sections' ) ) {
				continue;
			}
			$lines = preg_split( '/\r?\n/', $src );
			foreach ( $lines as $i => $line ) {
				if ( ! preg_match( "/'search'\s*=>/", $line ) ) {
					continue;
				}
				for ( $j = $i; $j <= $i + 2 && isset( $lines[ $j ] ); $j++ ) {
					if ( ! preg_match( "/'columns'\s*=>/", $lines[ $j ] ) ) {
						continue;
					}
					preg_match_all( "/'([a-z_]+)'/", substr( $lines[ $j ], strpos( $lines[ $j ], "'columns'" ) + 9 ), $m );
					if ( array_intersect( $m[1], $body ) && false === strpos( $lines[ $j ], 'minn_admin_mail_bodies_open' ) ) {
						$open[] = basename( $file ) . ':' . ( $j + 1 ) . ' ' . trim( $lines[ $j ] );
					}
					break;
				}
			}
		}
		$check( 'Search-1 no mail log searches a message column for a reader it hides keys from', ! $open, implode( ' | ', $open ) );
	} )();
} finally {
	wp_set_current_user( $admin );
	$drop_user( $reader );
}

$summary();
