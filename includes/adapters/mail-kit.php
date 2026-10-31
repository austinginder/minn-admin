<?php
/**
 * The mail family's kit: what every email log adapter would otherwise build
 * again. A log's surface (its columns, Sent and Failed tabs, Resend and
 * Delete, all agreeing with the kernel resource by construction), its
 * detail sections, its status card, and the delete and resend declarations.
 *
 * Adapter tier: presentation and vendor-neutral formatting only. Routes,
 * gates and queries stay the kernel's; each adapter passes its vendor's
 * values (where the log lives, who may read it, how a row reads).
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * An email log's surface over its kernel resource.
 *
 * @param string $res Resource id ("post-smtp/emails").
 * @param array  $o   sub, plugin, cap, status (route under minn-admin/v1),
 *                    date (column key), utc, tabs (value => label), resend
 *                    (true, or ui() keys for it), columns (between Status and
 *                    Date), actions (after Resend), collection and surface
 *                    (keys merged over the built ones).
 * @return array
 */
function minn_admin_mail_surface( $res, array $o ) {
	$o += array(
		'date'       => 'created_at',
		'utc'        => false,
		'tabs'       => array( 'sent' => 'Sent', 'failed' => 'Failed' ),
		'resend'     => false,
		'columns'    => array(),
		'actions'    => array(),
		'collection' => array(),
		'surface'    => array(),
	);
	$actions = array();
	if ( $o['resend'] ) {
		$actions[] = Minn_Admin_Kernel::ui( $res, 'resend', array_merge(
			array( 'label' => __( 'Resend', 'minn-admin' ), 'confirm' => __( 'Resend this email to the original recipients?', 'minn-admin' ) ),
			is_array( $o['resend'] ) ? $o['resend'] : array()
		) );
	}
	$actions   = array_merge( $actions, $o['actions'] );
	$actions[] = Minn_Admin_Kernel::ui( $res, 'delete', array( 'label' => __( 'Delete', 'minn-admin' ), 'confirm' => __( 'Delete this log entry permanently? There is no trash.', 'minn-admin' ), 'danger' => true ) );
	$tabs      = array();
	foreach ( $o['tabs'] as $value => $label ) {
		$tabs[] = array( $value, $label );
	}
	return array_merge( array(
		'label'      => __( 'Email', 'minn-admin' ),
		'sub'        => $o['sub'],
		'plugin'     => $o['plugin'],
		'icon'       => 'send',
		'cap'        => $o['cap'],
		'family'     => 'mail',
		'status'     => array( 'route' => 'minn-admin/v1/' . $o['status'] ),
		// A status-chart bar narrows the log to that day (the kernel's
		// dateQuery: the chart's points carry from/to).
		'collection' => Minn_Admin_Kernel::collection( $res, array_merge( array(
			'tabs'    => array( 'param' => 'status', 'static' => $tabs, 'allLabel' => 'All' ),
			'columns' => array_merge(
				array(
					array( 'key' => 'subject', 'label' => __( 'Subject', 'minn-admin' ), 'format' => 'title' ),
					array( 'key' => 'to', 'label' => __( 'To', 'minn-admin' ), 'format' => 'text' ),
					array( 'key' => 'status', 'label' => __( 'Status', 'minn-admin' ), 'format' => 'pill' ),
				),
				$o['columns'],
				array( array_merge( array( 'key' => $o['date'], 'label' => __( 'Date', 'minn-admin' ), 'format' => 'ago' ), $o['utc'] ? array( 'utc' => true ) : array() ) )
			),
			// Server-built sections (status pill, sandboxed HTML body).
			'detail'  => array( 'sectionsRoute' => 'minn-admin/v1/' . $res . '/{id}/view' ),
			'actions' => $actions,
			'bulk'    => array(
				Minn_Admin_Kernel::ui( $res, 'delete', array( 'label' => __( 'Delete', 'minn-admin' ), 'confirm' => __( 'Delete the selected log entries permanently?', 'minn-admin' ), 'danger' => true ) ),
			),
		), $o['collection'] ) ),
	), $o['surface'] );
}

/**
 * A logged email's detail: Delivery, then the subject and body, then any
 * further sections (a failure, the headers).
 *
 * @param array       $delivery Delivery rows.
 * @param string      $subject  Subject.
 * @param string|null $body     Body, or null where the reader may not see it.
 * @param array       $more     Sections after the message.
 * @param array       $html     Keys for an HTML body's row ('remote').
 * @return array
 */
function minn_admin_mail_sections( array $delivery, $subject, $body = null, array $more = array(), array $html = array() ) {
	$message = array( array( 'label' => __( 'Subject', 'minn-admin' ), 'value' => minn_admin_mail_mask_reset_keys( (string) $subject ) ) );
	if ( null !== $body ) {
		$body      = minn_admin_mail_mask_reset_keys( (string) $body );
		$message[] = preg_match( '/<\/?[a-z][^>]*>/i', $body )
			? array_merge( array( 'label' => __( 'Body', 'minn-admin' ), 'value' => $body, 'type' => 'html-preview' ), $html )
			: array( 'label' => __( 'Body', 'minn-admin' ), 'value' => $body, 'type' => 'code' );
	}
	// Every other row is masked too: a vendor's stored headers or request
	// params, a provider's reply or an error can hold the message whole.
	$sections = array( array( 'title' => __( 'Delivery', 'minn-admin' ), 'rows' => $delivery ) );
	foreach ( $more as $section ) {
		$sections[] = $section;
	}
	foreach ( $sections as &$section ) {
		foreach ( ( isset( $section['rows'] ) && is_array( $section['rows'] ) ) ? array_keys( $section['rows'] ) : array() as $i ) {
			if ( is_array( $section['rows'][ $i ] ) && isset( $section['rows'][ $i ]['value'] ) ) {
				$section['rows'][ $i ]['value'] = minn_admin_mail_mask_value( $section['rows'][ $i ]['value'] );
			}
		}
	}
	unset( $section );
	array_splice( $sections, 1, 0, array( array( 'title' => __( 'Message', 'minn-admin' ), 'rows' => $message ) ) );
	return array( 'sections' => $sections );
}

/**
 * A mail log resource's list row or flat item builder, with every text it
 * returns masked as the sections are (minn_admin_mail_mask_value()): a
 * subject, a stored header, a mailer's error or a provider's reply can quote
 * a reset link. Every mail log adapter wraps its 'row' and its item 'view'
 * in this, so the next one cannot serve them raw;
 * tests/security-v049-registry-followups.test.php fails on one that does not.
 *
 * @param callable $build The builder, called with the kernel's arguments.
 * @return Closure
 */
function minn_admin_mail_masked( callable $build ) {
	return function ( ...$args ) use ( $build ) {
		return minn_admin_mail_mask_value( call_user_func_array( $build, $args ) );
	};
}

/**
 * minn_admin_mail_mask_reset_keys() over a detail row's value: a string, or
 * every string inside an array (a kv-table).
 *
 * @param mixed $value Row value.
 * @return mixed
 */
function minn_admin_mail_mask_value( $value ) {
	if ( is_string( $value ) ) {
		return minn_admin_mail_mask_reset_keys( $value );
	}
	if ( is_array( $value ) ) {
		foreach ( $value as $k => $v ) {
			$value[ $k ] = minn_admin_mail_mask_value( $v );
		}
	}
	return $value;
}

/**
 * A logged email's body with the key of every password-reset link hidden,
 * for anyone on a network who is not its owner. A site's administrator can
 * ask for a reset for any account on the login page of their own site, a
 * super admin's included, and the link lands in that site's mail log. Core
 * keeps only a hash of the key, so the egress mask cannot know it. On a
 * single site the administrator can reset any password already.
 *
 * Every way a body leaves goes through this or minn_admin_mail_bodies_open():
 * the detail sections, each log's item route, a body search and a resend to
 * another address.
 *
 * @param string $body Logged body, text or HTML.
 * @return string
 */
function minn_admin_mail_mask_reset_keys( $body ) {
	$body = (string) $body;
	// No cheaper test of the body first: a soft break inside "key", a doubly
	// encoded link or an "=" written as an entity would pass any such test.
	if ( minn_admin_mail_bodies_open() ) {
		return $body;
	}
	// A quoted-printable body (an SMTP transcript stores DATA as sent) breaks
	// lines every 76 characters with "=" and writes "=" as "=3D", so a link
	// can be cut anywhere, key included. Joined first, prefixes of a
	// transcript's lines too; shown joined only when that hides a key.
	if ( preg_match( '#=\r?\n#', $body ) ) {
		$joined = preg_replace( '#=\r?\n(?:[^\S\r\n]*(?:\d{4}-\d\d-\d\d[ T][\d:.]+\s+)?(?:CLIENT -> SERVER|SERVER -> CLIENT):[^\S\r\n]?)?#', '', $body );
		$joined = str_ireplace( '=3D', '=', (string) $joined );
		$masked = minn_admin_mail_mask_reset_links( $joined );
		if ( $masked !== $joined ) {
			return $masked;
		}
	}
	return minn_admin_mail_mask_reset_links( $body );
}

/**
 * minn_admin_mail_mask_reset_keys() for one run of text. Each stretch between
 * spaces, quotes and angle brackets that reads as a reset link, judged with
 * its JSON escapes, entities and percent-encoding undone (a click-tracking
 * wrapper encodes the whole link, an HTML body writes "&" as &amp;, &#038; or
 * &#x26;, a debug log writes JSON), has its key hidden on that same decoded
 * view. Judging one view and replacing on another is how a key survived: a
 * separator spelled a way the replacement did not know.
 *
 * @param string $body Text.
 * @return string
 */
function minn_admin_mail_mask_reset_links( $body ) {
	return (string) preg_replace_callback(
		'#[^\s"\'<>]{16,}#',
		function ( $m ) {
			$plain = $m[0];
			for ( $i = 0; $i < 3; $i++ ) {
				$plain = str_replace( '\/', '/', $plain );
				$plain = (string) preg_replace_callback( '#\\\\u00([0-7][0-9a-f])#i', function ( $u ) {
					return chr( hexdec( $u[1] ) );
				}, $plain );
				$plain = rawurldecode( html_entity_decode( $plain, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
			}
			$plain = str_ireplace( '=3D', '=', $plain );
			// Core's action=rp and resetpass, a /resetpass/, lost-password or
			// password-reset page (Theme My Login, WooCommerce, Ultimate
			// Member), a reset_password action (Ultimate Member, MemberPress),
			// or a key beside the account it is for (a renamed endpoint).
			$reset = preg_match( '#\bact(?:ion)?=(?:rp|resetpass|reset_?password)\b|lost-?password|reset-?pass|password-?reset#i', $plain );
			$keyed = preg_match( '#(?<![a-z0-9_])(?:key|hash|mkey|rp_key|token)=#i', $plain ) && preg_match( '#[?&;\#](?:login|id|u|user)=#i', $plain );
			return $reset || $keyed ? minn_admin_mail_hide_keys( $m[0] ) : $m[0];
		},
		$body
	);
}

/**
 * A reset link with its key hidden, however it is written. The link is read
 * one written character at a time (a percent escape at any depth, an entity,
 * a JSON escape, or a plain character), each standing for the one character
 * it decodes to, so the hiding sees "&", "=" and "/" as the judging did. It
 * hides the value of every key-like parameter, and every run of sixteen or
 * more letters and digits after the host: core's key is twenty of them, and a
 * fragment or a path segment carries one as well as a query does.
 *
 * @param string $run The link as written.
 * @return string
 */
function minn_admin_mail_hide_keys( $run ) {
	preg_match_all( '#%(?:25)*[0-9a-f]{2}|&(?:\#[0-9]+|\#x[0-9a-f]+|[a-z][a-z0-9]*);|\\\\u[0-9a-f]{4}|\\\\.|.#si', $run, $m );
	$units = $m[0];
	$plain = '';
	foreach ( $units as $u ) {
		if ( 1 === strlen( $u ) ) {
			$c = $u;
		} elseif ( '%' === $u[0] ) {
			$c = chr( hexdec( substr( $u, -2 ) ) );
		} elseif ( '&' === $u[0] ) {
			$c = html_entity_decode( $u, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		} elseif ( 6 === strlen( $u ) ) {
			$c = hexdec( substr( $u, 2 ) ) < 0x80 ? chr( hexdec( substr( $u, 2 ) ) ) : ' ';
		} else {
			$c = false !== strpos( 'nrt', $u[1] ) ? ' ' : $u[1];
		}
		// One byte per unit keeps offsets in step; a character outside ASCII
		// is never part of a key and ends a value like a space does.
		$plain .= 1 === strlen( $c ) ? $c : ' ';
	}
	$hide = array();
	preg_match_all( '#(?<![a-z0-9_])(?:key|hash|mkey|rp_key|token)(?:=|&(?:amp;)*(?:\#0*61|\#x0*3d|equals);)#i', $plain, $params, PREG_OFFSET_CAPTURE );
	foreach ( $params[0] as $p ) {
		$from = $p[1] + strlen( $p[0] );
		$to   = $from + strcspn( $plain, "&#;? \t\r\n\"'<>", $from );
		if ( $to > $from ) {
			$hide[ $from ] = max( $to, $hide[ $from ] ?? 0 );
		}
	}
	$host = strpos( $plain, '//' );
	$host = false === $host ? 0 : $host + 2 + strcspn( $plain, '/?#', $host + 2 );
	preg_match_all( '#[a-z0-9]{16,}#i', $plain, $runs, PREG_OFFSET_CAPTURE, $host );
	foreach ( $runs[0] as $r ) {
		$hide[ $r[1] ] = max( $r[1] + strlen( $r[0] ), $hide[ $r[1] ] ?? 0 );
	}
	if ( ! $hide ) {
		return $run;
	}
	ksort( $hide );
	$out = '';
	$end = 0;
	foreach ( $units as $i => $u ) {
		if ( isset( $hide[ $i ] ) && $i >= $end ) {
			$out .= rawurlencode( __( '[hidden]', 'minn-admin' ) );
		}
		if ( isset( $hide[ $i ] ) ) {
			$end = max( $end, $hide[ $i ] );
		}
		if ( $i >= $end ) {
			$out .= $u;
		}
	}
	return $out;
}

/**
 * Whether this user reads logged mail whole. On a single site, someone who
 * can edit every administrator (and so reset any password already); a log a
 * plugin opens to other roles shows them reset links with the key hidden.
 * edit_users alone is not that: WooCommerce grants it to a Shop Manager and
 * narrows edit_user to customers, so an administrator's reset key would be
 * theirs to read. On a network, the install's owner only (on a
 * multi-network install a secondary network's super admin could otherwise
 * read the main network's resets).
 *
 * @return bool
 */
function minn_admin_mail_bodies_open() {
	if ( is_multisite() ) {
		return Minn_Admin::install_owner();
	}
	if ( ! current_user_can( 'edit_users' ) ) {
		return false;
	}
	static $open = array();
	$uid = get_current_user_id();
	if ( ! isset( $open[ $uid ] ) ) {
		$open[ $uid ] = true;
		foreach ( get_users( array( 'role' => 'administrator', 'fields' => 'ID' ) ) as $admin ) {
			if ( ! current_user_can( 'edit_user', (int) $admin ) ) {
				$open[ $uid ] = false;
				break;
			}
		}
	}
	return $open[ $uid ];
}

/** The Failure section of a detail, the error as a code row and any rows after it. */
function minn_admin_mail_failure( $error, array $rows = array() ) {
	return array(
		'title' => __( 'Failure', 'minn-admin' ),
		'rows'  => array_merge( array( array( 'label' => __( 'Error', 'minn-admin' ), 'value' => (string) $error, 'type' => 'code' ) ), $rows ),
	);
}

/**
 * An email log's status card over its resource: how many it holds, how
 * many failed, and the last fourteen days, counted as the list counts.
 *
 * @param string $res Resource id.
 * @param array  $o   failed (conditions), chart (Minn_Admin_Kernel::days()),
 *                    ok (the hint when none failed), primary, secondary,
 *                    rows (after the first), actions, missing (the hint
 *                    while the table is not there yet) and table.
 * @return array
 */
function minn_admin_mail_card( $res, array $o ) {
	$o += array(
		'ok'        => __( 'All logged sends', 'minn-admin' ),
		'primary'   => 'Sent',
		'secondary' => 'Failed',
		'rows'      => array(),
		'actions'   => array(),
	);
	if ( isset( $o['table'] ) && ! Minn_Admin_Kernel_SQL::exists( $o['table'] ) ) {
		return array(
			'rows'    => array( array( 'label' => __( 'Email log', 'minn-admin' ), 'value' => $o['missing_value'] ?? 'Not ready', 'hint' => $o['missing'] ?? '' ) ),
			'actions' => $o['missing_actions'] ?? $o['actions'],
		);
	}
	$failed = Minn_Admin_Kernel::count( $res, $o['failed'] );
	return array(
		'rows'    => array_merge(
			array(
				array(
					'label' => __( 'Logged emails', 'minn-admin' ),
					'value' => number_format_i18n( Minn_Admin_Kernel::count( $res ) ),
					/* translators: %s: number of failed sends. */
					'hint'  => $failed ? sprintf( _n( '%s failed', '%s failed', $failed, 'minn-admin' ), number_format_i18n( $failed ) ) : $o['ok'],
				),
			),
			$o['rows']
		),
		'chart'   => array(
			'title'     => __( 'Last 14 days', 'minn-admin' ),
			'primary'   => $o['primary'],
			'secondary' => $o['secondary'],
			'points'    => array_values( Minn_Admin_Kernel::days( $res, $o['chart'] ) ),
		),
		'actions' => $o['actions'],
	);
}

/**
 * The delete every log declares: permanent, said done once the row is gone.
 *
 * @param callable $run Deletes one entry: the vendor's own delete, or the kernel's.
 * @param array    $o   Keys merged over it (gate, fail).
 * @return array
 */
function minn_admin_mail_delete( callable $run, array $o = array() ) {
	return array_merge( array(
		'method' => 'DELETE',
		'gate'   => 'view',
		'run'    => $run,
		'expect' => 'gone',
		'reply'  => function () {
			return array( 'deleted' => true );
		},
		'done'   => __( 'Log entry deleted.', 'minn-admin' ),
	), $o );
}

/**
 * The resend a log declares: proven only by the vendor's own answer (the
 * kernel's 'ran'), and told whom it went to when the run says.
 *
 * @param callable $run Resends one entry: whom it sent to, true, or a WP_Error.
 * @param array    $o   Keys merged over it (gate, reply, done).
 * @return array
 */
function minn_admin_mail_resend( callable $run, array $o = array() ) {
	return array_merge( array(
		'gate'   => 'view',
		'run'    => $run,
		'expect' => 'ran',
		'reply'  => function ( $id, $fresh, $to ) {
			return is_string( $to ) ? array( 'resent' => true, 'to' => $to, 'message' => __( 'Resent to ', 'minn-admin' ) . $to ) : array( 'resent' => true );
		},
	), $o );
}

/** A plain wp_mail() resend of a stored subject and body, HTML when it holds tags. */
function minn_admin_mail_wp_mail( array $to, $subject, $body ) {
	$body = (string) $body;
	if ( ! wp_mail( $to, (string) $subject, $body, preg_match( '/<\/?[a-z][\s\S]*>/i', $body ) ? array( 'Content-Type: text/html; charset=UTF-8' ) : array() ) ) {
		return new WP_Error( 'send_failed', 'wp_mail() reported the message could not be sent.', array( 'status' => 500 ) );
	}
	return implode( ', ', $to );
}
