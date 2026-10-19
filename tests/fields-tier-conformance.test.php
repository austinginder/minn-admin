<?php
/**
 * Conformance for the fields tier (includes/kernel/class-minn-admin-kernel-panels.php).
 *
 * One check per panel rule and per field type, run over a fixture panel of
 * the suite's own (every type, a vendor check, a field the tier locks, an
 * edit check it can switch off or make throw) and over every panel declared
 * through the tier whose plugin is loaded:
 *
 *   R1  only the fields the plugin's own check allows are offered, read or
 *       written; the route asks for the post or the type, and a check that
 *       throws says no
 *   R2  a save never answers an error; refusals ride on the post's answer
 *   R3  a refused value keeps the stored one and is named
 *   R4  only an empty box clears; a value its cleaner empties, or one of the
 *       wrong shape, is a refusal
 *   U   a value sent back as served is left as stored
 *   T   each field type cleans what it takes and refuses what it does not
 *
 * Every check drives the tier's own entry points with a declaration, the
 * write replaced by a spy, so nothing a real panel stores is touched. The
 * e2e checks also save through core's posts controller (the installed tier
 * only).
 *
 * Proving the checks: MINN_FIELDS_TIER=<copy.php>[,<copy.php>…] runs them
 * against copies of the tier instead, each loaded under its own class name.
 * A copy with one primitive broken must FAIL the checks that hold it.
 *
 * Run: wp eval-file <ABSOLUTE path>/tests/fields-tier-conformance.test.php --user=admin --path=<site>
 *      (MINN_SWAP_ADD=powerpress/powerpress.php … --require=<ABSOLUTE path>/tests/lib/plugin-swap.php
 *      covers PowerPress, which the lab keeps inactive)
 *
 * @package minn-admin
 */

require_once __DIR__ . '/lib/signals.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

if ( ! class_exists( 'Minn_Admin_Kernel_Panels' ) ) {
	echo "FAIL  the fields tier is not loaded\n";
	exit( 1 );
}

$results = array();
$check   = static function ( $label, $ok, $detail = '' ) use ( &$results ) {
	$results[] = (bool) $ok;
	printf( "%s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, ( $ok || '' === $detail ) ? '' : ' — ' . $detail );
};
$skip    = static function ( $label ) {
	printf( "SKIP  %s\n", $label );
};
$as      = static function ( $uid, callable $fn ) {
	$was = get_current_user_id();
	wp_set_current_user( $uid );
	try {
		return $fn();
	} finally {
		wp_set_current_user( $was );
	}
};
$tag   = strtolower( wp_generate_password( 6, false ) );
$admin = get_current_user_id();

// Fixtures: a contributor (edits own posts, no pages), an admin's draft, a
// page and a post to pick, removed however the run ends.
$made  = array( 'posts' => array(), 'users' => array() );
$clean = static function () use ( &$made ) {
	foreach ( $made['posts'] as $pid ) {
		wp_delete_post( $pid, true );
	}
	foreach ( $made['users'] as $uid ) {
		wp_delete_user( $uid );
	}
	$made = array( 'posts' => array(), 'users' => array() );
};
register_shutdown_function( $clean );
$post_of = static function ( $type, $author, $status = 'draft' ) use ( &$made, $tag ) {
	$pid = (int) wp_insert_post( array( 'post_type' => $type, 'post_status' => $status, 'post_title' => 'Minn fields tier ' . $tag, 'post_author' => $author ) );
	if ( $pid ) {
		$made['posts'][] = $pid;
	}
	return $pid;
};
$contrib = wp_insert_user( array( 'user_login' => 'minn_fx_' . $tag, 'user_pass' => wp_generate_password( 24 ), 'user_email' => 'minn_fx_' . $tag . '@example.test', 'role' => 'contributor' ) );
if ( is_wp_error( $contrib ) ) {
	echo 'FAIL  fixture contributor: ' . esc_html( $contrib->get_error_message() ) . "\n";
	exit( 1 );
}
$made['users'][] = $contrib;
$draft  = $post_of( 'post', $admin );
$page   = $post_of( 'page', $admin, 'publish' );
$other  = $post_of( 'post', $admin, 'publish' );
$own    = $post_of( 'post', $contrib );
$image  = 0;
$pdf    = 0;
foreach ( array( 'image' => array( 'image/png', 'minn-fx.png' ), 'pdf' => array( 'application/pdf', 'minn-fx.pdf' ) ) as $kind => $spec ) {
	$aid = (int) wp_insert_attachment( array( 'post_title' => 'Minn fields tier ' . $tag, 'post_mime_type' => $spec[0], 'post_status' => 'inherit', 'post_author' => $admin ), $spec[1] );
	if ( $aid ) {
		$made['posts'][] = $aid;
		update_attached_file( $aid, $spec[1] );
	}
	${$kind} = $aid;
}

/*
 * The fixture panel: its store is in memory, its write a spy that can
 * refuse, fail or throw, and its edit check can say no or throw.
 */
$fx = array(
	'can'    => true,      // true | false | 'throw' | 'post' (no only for a post)
	'store'  => array(),
	'spy'    => array(),
	'answer' => null,      // what the write answers
	'throw'  => false,
	'boom'   => false,     // a capability filter throws
);
$fx_fields = static function () use ( $page ) {
	return array(
		array( 'name' => 't', 'label' => 'Text', 'type' => 'text' ),
		array( 'name' => 'ta', 'label' => 'Notes', 'type' => 'textarea' ),
		array( 'name' => 'th', 'label' => 'Body', 'type' => 'textarea', 'html' => true ),
		array( 'name' => 'n', 'label' => 'Count', 'type' => 'number', 'min' => 0, 'max' => 10 ),
		array( 'name' => 'ni', 'label' => 'Whole', 'type' => 'number', 'integer' => true ),
		array( 'name' => 's', 'label' => 'Pick', 'type' => 'select', 'choices' => array( 'a' => 'A', 'b' => 'B' ) ),
		array( 'name' => 'c', 'label' => 'Flag', 'type' => 'checkbox' ),
		array( 'name' => 'e', 'label' => 'Mail', 'type' => 'email' ),
		array( 'name' => 'u', 'label' => 'Link', 'type' => 'url' ),
		array( 'name' => 'd', 'label' => 'Day', 'type' => 'date' ),
		array( 'name' => 'dt', 'label' => 'When', 'type' => 'datetime' ),
		array( 'name' => 'sg', 'label' => 'Page', 'type' => 'suggest', 'post_type' => 'page', 'route' => 'minn-admin/v1/fx/suggest' ),
		array( 'name' => 'req', 'label' => 'Needed', 'type' => 'text', 'required' => true ),
		array( 'name' => 'chk', 'label' => 'Checked', 'type' => 'text', 'check' => static function ( $v ) {
			if ( 'throw' === $v ) {
				throw new RuntimeException( 'vendor check threw' );
			}
			return 'bad' === $v ? 'The vendor refuses bad.' : true;
		} ),
		array( 'name' => 'so', 'label' => 'Ordered', 'type' => 'select', 'options' => array( array( 'x', 'X' ), array( 'y', 'Y' ) ) ),
		array( 'name' => 'm', 'label' => 'Picture', 'type' => 'media', 'image' => true ),
		array( 'name' => 'nt', 'label' => 'Note', 'type' => 'note' ),
		array( 'name' => 'rep', 'label' => 'Rows', 'type' => 'repeater' ),
		array( 'name' => 'odd', 'label' => 'Odd', 'type' => 'text', 'unknown_key' => 1 ),
	);
};
$fx_def = static function () use ( &$fx, $fx_fields ) {
	return array(
		'id'          => 'minn-fx',
		'active'      => true,
		'label'       => 'Fixture',
		'sub'         => 'Suite',
		'key'         => 'minn_fx',
		'params'      => array( 'post_id', 'post_type' ),
		'types'       => array( 'post' ),
		'can'         => static function ( $post_id, $type ) use ( &$fx ) {
			if ( 'throw' === $fx['can'] ) {
				throw new RuntimeException( 'vendor edit check threw' );
			}
			if ( 'post' === $fx['can'] ) {
				return 0 === (int) $post_id && '' === $type;
			}
			if ( 'say' === $fx['can'] ) {
				return 0 === (int) $post_id && '' === $type ? true : 'The fixture plugin says why.';
			}
			return $fx['can'];
		},
		'refuse'      => 'The fixture plugin keeps you out.',
		'groups'      => static function ( $post_id, $type ) use ( $fx_fields ) {
			return array( array( 'group' => 'Fixture', 'fields' => $fx_fields(), 'locked' => 1 ) );
		},
		'read'        => static function ( $post_id ) use ( &$fx ) {
			return $fx['store'] + array( 'hidden_extra' => 'never offered' );
		},
		'write'       => static function ( $post_id, $changes, $fields ) use ( &$fx ) {
			$fx['spy'][] = $changes;
			if ( $fx['throw'] ) {
				throw new RuntimeException( 'vendor write threw' );
			}
			foreach ( $changes as $k => $v ) {
				if ( null === $v ) {
					unset( $fx['store'][ $k ] );
				} else {
					$fx['store'][ $k ] = $v;
				}
			}
			return $fx['answer'];
		},
		'extra'       => static function ( $post_id, $type ) use ( &$fx ) {
			$fx['extra'][] = array( $post_id, $type );
			return array( 'preview' => array( 'for' => $post_id ) );
		},
		'description' => '',
	);
};
$fx_reset = static function ( $store = array() ) use ( &$fx ) {
	$fx['can']    = true;
	$fx['store']  = $store;
	$fx['spy']    = array();
	$fx['answer'] = null;
	$fx['throw']  = false;
	$fx['extra']  = array();
};
// What reached the write, merged; and whether a key was refused.
$wrote   = static function () use ( &$fx ) {
	$all = array();
	foreach ( $fx['spy'] as $c ) {
		$all = $c + $all;
	}
	return $all;
};
$refused = static function ( array $rows, $key ) {
	foreach ( $rows as $row ) {
		if ( (string) $row['key'] === (string) $key ) {
			return $row;
		}
	}
	return null;
};
$req = static function ( array $params ) {
	$r = new WP_REST_Request( 'GET', '/minn-admin/v1/minn-fx/fields' );
	foreach ( $params as $k => $v ) {
		$r->set_param( $k, $v );
	}
	return $r;
};
$status = static function ( $answer ) {
	return is_wp_error( $answer ) ? (int) ( $answer->get_error_data()['status'] ?? 0 ) : 200;
};
$names = static function ( $answer ) {
	$out = array();
	foreach ( (array) ( is_array( $answer ) ? ( $answer['groups'] ?? array() ) : array() ) as $g ) {
		foreach ( $g['fields'] as $f ) {
			$out[] = $f['name'];
		}
	}
	return $out;
};

/**
 * Every check, against one tier class.
 */
$run = static function ( $T ) use ( $check, $skip, $as, $fx_def, $fx_reset, &$fx, $wrote, $refused, $req, $status, $names, $draft, $page, $other, $own, $contrib, $admin, $post_of, $image, $pdf ) {
	$def  = $fx_def();
	$post = get_post( $draft );

	// ---------------------------------------------------------------- R1
	$fx_reset();
	$a = $as( $contrib, static function () use ( $T, $def, $req, $draft ) {
		return $T::fields_answer( $def, $req( array( 'post_id' => $draft, 'post_type' => 'posts' ) ) );
	} );
	$check( 'R1 the route refuses a post the caller cannot edit (403)', 403 === $status( $a ), wp_json_encode( $a ) );
	$a = $T::fields_answer( $def, $req( array( 'post_id' => 999999999, 'post_type' => 'posts' ) ) );
	$check( 'R1 the route answers 404 for a post that does not exist', 404 === $status( $a ), wp_json_encode( $a ) );
	$fx_reset();
	$def_pages          = $def;
	$def_pages['types'] = array( 'post', 'page' );
	$a = $as( $contrib, static function () use ( $T, $def_pages, $req ) {
		return $T::fields_answer( $def_pages, $req( array( 'post_id' => 0, 'post_type' => 'pages' ) ) );
	} );
	$check( 'R1 the route refuses a new post of a type the caller cannot edit (403)', 403 === $status( $a ), wp_json_encode( $a ) );
	$a = $as( $contrib, static function () use ( $T, $def, $req, $own ) {
		return $T::fields_answer( $def, $req( array( 'post_id' => $own, 'post_type' => 'posts' ) ) );
	} );
	$check( 'R1 control: the caller\'s own post is offered', in_array( 't', $names( $a ), true ), wp_json_encode( $a ) );
	$a = $T::fields_answer( $def, $req( array( 'post_id' => 0, 'post_type' => 'pages' ) ) );
	$check( 'R1 a type the panel does not serve is offered nothing', array() === $names( $a ) && 200 === $status( $a ), wp_json_encode( $a ) );
	$fx['can'] = false;
	$a         = $T::fields_answer( $def, $req( array( 'post_id' => $draft, 'post_type' => 'posts' ) ) );
	$check( 'R1 the plugin\'s own check saying no offers nothing', array() === $names( $a ), wp_json_encode( $a ) );
	$fx['can'] = 'post';
	$a         = $T::fields_answer( $def, $req( array( 'post_id' => $draft, 'post_type' => 'posts' ) ) );
	$check( 'R1 the plugin\'s check is asked for the post itself', array() === $names( $a ) && true === $T::allowed( $def, 0, '' ), wp_json_encode( $a ) );
	$fx['can'] = 'throw';
	$a         = $T::fields_answer( $def, $req( array( 'post_id' => $draft, 'post_type' => 'posts' ) ) );
	$check( 'R1 a plugin check that throws offers nothing', array() === $names( $a ), wp_json_encode( $a ) );
	$check( 'R1 a plugin check that throws keeps the panel closed', false === $T::allowed( $def, 0, '' ) );
	$fx['can'] = false;
	$check( 'R1 the plugin\'s check closes the panel and its route', false === $T::allowed( $def, 0, '' ) );
	$fx_reset();
	$a = $T::fields_answer( $def, $req( array( 'post_id' => $draft, 'post_type' => 'posts' ) ) );
	$check( 'R1 the route\'s extra keys join the answer once its gates pass', ( $a['preview']['for'] ?? null ) === $draft && array( array( $draft, 'post' ) ) === $fx['extra'], wp_json_encode( array( $a, $fx['extra'] ) ) );
	$fx_reset();
	$a = $as( $contrib, static function () use ( $T, $def, $req, $draft ) {
		return $T::fields_answer( $def, $req( array( 'post_id' => $draft, 'post_type' => 'posts' ) ) );
	} );
	$fx['can'] = false;
	$b         = $T::fields_answer( $def, $req( array( 'post_id' => $draft, 'post_type' => 'posts' ) ) );
	$check( 'R1 the route\'s extra keys are never asked for a refused post', ! $fx['extra'] && ! isset( $b['preview'] ) && is_wp_error( $a ), wp_json_encode( array( $fx['extra'], $b ) ) );
	$fx_reset();
	$a      = $T::fields_answer( $def, $req( array( 'post_id' => $draft, 'post_type' => 'posts' ) ) );
	$locked = (int) ( $a['groups'][0]['locked'] ?? 0 );
	$check( 'R1 a field of a type the tier does not clean, or with a key it does not read, is locked', ! in_array( 'rep', $names( $a ), true ) && ! in_array( 'odd', $names( $a ), true ) && 3 === $locked, wp_json_encode( array( $names( $a ), $locked ) ) );
	$wire = array();
	foreach ( (array) ( $a['groups'][0]['fields'] ?? array() ) as $f ) {
		$wire = array_merge( $wire, array_keys( $f ) );
	}
	$check( 'R1 a field leaves with only what the panel draws (no check, refuse or post type)', ! array_intersect( array( 'check', 'refuse', 'post_type', 'html', 'integer', 'required', 'input' ), $wire ), wp_json_encode( array_values( array_unique( $wire ) ) ) );

	$fx_reset( array( 't' => 'stored' ) );
	$v = $as( $contrib, static function () use ( $T, $def, $draft ) {
		return $T::values( $def, $draft );
	} );
	$check( 'R1 the read is empty for a post the caller cannot edit', array() === (array) $v, wp_json_encode( $v ) );
	$fx['can'] = false;
	$v         = $T::values( $def, $draft );
	$check( 'R1 the read is empty when the plugin\'s check says no', array() === (array) $v, wp_json_encode( $v ) );
	$fx['can'] = 'throw';
	$v         = $T::values( $def, $draft );
	$check( 'R1 the read is empty when the plugin\'s check throws', array() === (array) $v, wp_json_encode( $v ) );
	$fx['can'] = true;
	$v         = (array) $T::values( $def, $draft );
	$check( 'R1 the read carries only offered fields', array( 't' => 'stored' ) === $v, wp_json_encode( $v ) );
	$ddef           = $def;
	$ddef['groups'] = static function () {
		return array( array( 'group' => 'Digits', 'fields' => array( array( 'name' => '42', 'label' => 'Digits', 'type' => 'text' ) ) ) );
	};
	$ddef['read']   = static function () {
		return array( '42' => 'kept' );
	};
	$dv = $T::values( $ddef, $draft );
	$check( 'R1 the read leaves as an object when empty or keyed by digits', is_object( $T::values( $def, 999999999 ) ) && is_object( $dv ) && array( '42' => 'kept' ) === (array) $dv, wp_json_encode( $dv ) );

	$fx_reset( array( 't' => 'stored' ) );
	$fx['can'] = false;
	$rows      = $T::save( $def, $post, array( 't' => 'changed' ) );
	$check( 'R1 a save the plugin\'s check refuses writes nothing and names the field', ! $fx['spy'] && $refused( $rows, 't' ) && 'The fixture plugin keeps you out.' === ( $refused( $rows, 't' )['message'] ?? '' ), wp_json_encode( array( $fx['spy'], $rows ) ) );
	$fx['can'] = 'say';
	$rows      = $T::save( $def, $post, array( 't' => 'changed' ) );
	$check( 'R1 a plugin check that answers why names the field with its words', ! $fx['spy'] && 'The fixture plugin says why.' === ( $refused( $rows, 't' )['message'] ?? '' ), wp_json_encode( $rows ) );
	$fx['can'] = 'throw';
	$rows      = $T::save( $def, $post, array( 't' => 'changed' ) );
	$check( 'R1 a save whose plugin check throws writes nothing', ! $fx['spy'] && $refused( $rows, 't' ), wp_json_encode( array( $fx['spy'], $rows ) ) );
	$fx_reset( array( 't' => 'stored' ) );
	$rows = $T::save( $def, get_post( $page ), array( 't' => 'changed' ) );
	$v    = $T::values( $def, $page );
	$check( 'R1 a post of a type the panel does not serve is neither read nor written', ! $fx['spy'] && $refused( $rows, 't' ) && array() === (array) $v, wp_json_encode( array( $fx['spy'], $rows, $v ) ) );
	$fx_reset();
	$rows = $as( $contrib, static function () use ( $T, $def, $post ) {
		return $T::save( $def, $post, array( 't' => 'changed' ) );
	} );
	$check( 'R1 a save by someone who cannot edit the post writes nothing', ! $fx['spy'] && $refused( $rows, 't' ), wp_json_encode( array( $fx['spy'], $rows ) ) );

	// ---------------------------------------------------------------- R2
	$fx_reset();
	$r   = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $draft );
	$out = $T::update( $def, array( 'u' => 'javascript:alert(1)', 'nope' => 'x' ), $post, $r );
	$check( 'R2 a save with refusals answers no error', null === $out, wp_json_encode( $out ) );
	$res  = $T::report( new WP_REST_Response( array( 'id' => $draft ), 201 ), $r );
	$data = $res instanceof WP_REST_Response ? $res->get_data() : array();
	$rows = (array) ( $data['minn_field_errors'] ?? array() );
	$named = array();
	foreach ( $rows as $row ) {
		$named[ $row['key'] ] = $row;
	}
	$check( 'R2 the post\'s answer keeps its status and id', $res instanceof WP_REST_Response && 201 === $res->get_status() && $draft === ( $data['id'] ?? 0 ), wp_json_encode( $data ) );
	$check( 'R2 the answer names each refused field with the panel\'s key, a label and a message', isset( $named['u'], $named['nope'] ) && 'minn_fx' === $named['u']['field'] && 'Link' === $named['u']['label'] && '' !== (string) $named['u']['message'], wp_json_encode( $rows ) );
	$check( 'R2 the answer carries a message the editor shows', '' !== (string) ( $data['minn_field_message'] ?? '' ), wp_json_encode( $data ) );
	$check( 'R2 refusals are taken once', array() === $T::held( $r ) );
	$r1 = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $draft );
	$r2 = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $draft );
	$T::held( $r1, new WP_Error( 'x', 'held', array( 'fields' => array() ) ), 'minn_fx' );
	$check( 'R2 refusals held for one request are never read by another', array() === $T::held( $r2 ) && 1 === count( $T::held( $r1 ) ) );
	$err = $T::report( new WP_Error( 'rest_bad', 'The post said no.', array( 'status' => 400 ) ), ( function () use ( $T ) {
		$r = new WP_REST_Request( 'POST', '/wp/v2/posts' );
		$T::held( $r, new WP_Error( 'minn_field_refused', 'The panel said no.', array( 'fields' => array() ) ), 'minn_fx' );
		return $r;
	} )() );
	$check( 'R2 an answer that is already an error keeps its message first and gains the refusal', is_wp_error( $err ) && 'rest_bad' === $err->get_error_code() && false !== strpos( $err->get_error_message(), 'The post said no.' ) && false !== strpos( $err->get_error_message(), 'The panel said no.' ), is_wp_error( $err ) ? $err->get_error_message() : 'not an error' );
	$fx_reset();
	$fx['throw'] = true;
	$threw       = false;
	try {
		$rows = $T::save( $def, $post, array( 't' => 'changed', 'n' => '3' ) );
	} catch ( \Throwable $e ) {
		$threw = true;
		$rows  = array();
	}
	$check( 'R2 a write that throws is caught and names every field it was given', ! $threw && $refused( $rows, 't' ) && $refused( $rows, 'n' ), wp_json_encode( $rows ) );
	$r = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $draft );
	try {
		$out = $T::update( $def, array( 't' => 'again' ), $post, $r );
	} catch ( \Throwable $e ) {
		$out = 'threw';
	}
	$T::held( $r );
	$check( 'R2 a write that throws still answers no error', null === $out, wp_json_encode( $out ) );

	// ---------------------------------------------------------------- R3
	$fx_reset( array( 'u' => 'https://example.com/kept' ) );
	$rows = $T::save( $def, $post, array( 'u' => 'javascript:alert(1)', 't' => 'written' ) );
	$w    = $wrote();
	$check( 'R3 a refused value never reaches the write and is named', ! array_key_exists( 'u', $w ) && $refused( $rows, 'u' ), wp_json_encode( array( $w, $rows ) ) );
	$check( 'R3 control: the value beside it is written', 'written' === ( $w['t'] ?? null ) && ! $refused( $rows, 't' ), wp_json_encode( array( $w, $rows ) ) );
	$check( 'R3 the stored value stays', 'https://example.com/kept' === $fx['store']['u'] );
	$fx_reset();
	$rows = $T::save( $def, $post, array( 'rep' => 'x', 'hidden_extra' => 'y', 'odd' => 'z' ) );
	$check( 'R3 a field the post does not offer (locked, unknown or read-only) is named and not written', ! $fx['spy'] && $refused( $rows, 'rep' ) && $refused( $rows, 'hidden_extra' ) && $refused( $rows, 'odd' ), wp_json_encode( array( $fx['spy'], $rows ) ) );
	$fx_reset( array( 'nt' => 'A note' ) );
	$rows = $T::save( $def, $post, array( 'nt' => 'Changed note' ) );
	$check( 'R3 a note is drawn, never written: a changed one is named as not changeable', ! $fx['spy'] && 'This field cannot be changed here.' === ( $refused( $rows, 'nt' )['message'] ?? '' ), wp_json_encode( array( $fx['spy'], $rows ) ) );
	$rows = $T::save( $def, $post, array( 'nt' => 'A note' ) );
	$check( 'R3 control: a note sent back as read is nothing to refuse', ! $fx['spy'] && ! $rows, wp_json_encode( $rows ) );
	$fx_reset();
	$fx['answer'] = array( 't' => 'The vendor kept the old text.', 'unoffered' => 'ignored' );
	$rows         = $T::save( $def, $post, array( 't' => 'changed', 'n' => '4' ) );
	$check( 'R3 a value the plugin\'s write refuses is named with its message', 'The vendor kept the old text.' === ( $refused( $rows, 't' )['message'] ?? '' ) && ! $refused( $rows, 'n' ) && ! $refused( $rows, 'unoffered' ), wp_json_encode( $rows ) );
	$fx_reset();
	$fx['answer'] = new WP_Error( 'vendor_no', 'The vendor refused the lot.', array( 'status' => 400 ) );
	$rows         = $T::save( $def, $post, array( 't' => 'changed', 'n' => '4' ) );
	$check( 'R3 a write that refuses whole names every field it was given', 'The vendor refused the lot.' === ( $refused( $rows, 't' )['message'] ?? '' ) && $refused( $rows, 'n' ), wp_json_encode( $rows ) );
	$fx_reset();
	$rows = $T::save( $def, $post, array( 'chk' => 'bad' ) );
	$check( 'R3 a vendor check refuses with its own message and nothing is written', ! $fx['spy'] && 'The vendor refuses bad.' === ( $refused( $rows, 'chk' )['message'] ?? '' ), wp_json_encode( array( $fx['spy'], $rows ) ) );
	$rows = $T::save( $def, $post, array( 'chk' => 'throw' ) );
	$check( 'R3 a vendor check that throws refuses', ! $fx['spy'] && $refused( $rows, 'chk' ), wp_json_encode( $rows ) );
	$rows = $T::save( $def, $post, array( 'chk' => 'fine' ) );
	$check( 'R3 control: a value the vendor check takes is written', 'fine' === ( $wrote()['chk'] ?? null ) && ! $rows, wp_json_encode( $rows ) );

	// ---------------------------------------------------------------- R4
	$fx_reset( array( 't' => 'stored', 'n' => '5', 'u' => 'https://example.com/' ) );
	$rows = $T::save( $def, $post, array( 't' => '', 'n' => null, 'u' => '   ' ) );
	$w    = $wrote();
	$check( 'R4 an emptied box clears (null to the write)', array_key_exists( 't', $w ) && null === $w['t'] && array_key_exists( 'n', $w ) && null === $w['n'] && array_key_exists( 'u', $w ) && null === $w['u'] && ! $rows, wp_json_encode( array( $w, $rows ) ) );
	$fx_reset( array( 't' => 'stored', 'ta' => 'stored', 'th' => 'stored' ) );
	$rows = $T::save( $def, $post, array( 't' => '<p></p>', 'ta' => '<b></b>', 'th' => '<script></script>' ) );
	$check( 'R4 a value its cleaner empties is refused, not a clear', ! $fx['spy'] && $refused( $rows, 't' ) && $refused( $rows, 'ta' ) && $refused( $rows, 'th' ), wp_json_encode( array( $fx['spy'], $rows ) ) );
	$fx_reset( array( 't' => 'stored', 'u' => 'https://example.com/', 'sg' => array( 'value' => (string) $page, 'label' => 'x' ) ) );
	$rows = $T::save( $def, $post, array( 't' => array( 'a' ), 'u' => array( 'x' => 1 ), 'c' => array( 1 ), 'sg' => array( 'nope' => 1 ) ) );
	$check( 'R4 a value of the wrong shape is refused, not a clear', ! $fx['spy'] && $refused( $rows, 't' ) && $refused( $rows, 'u' ) && $refused( $rows, 'c' ) && $refused( $rows, 'sg' ), wp_json_encode( array( $fx['spy'], $rows ) ) );
	$fx_reset( array( 'sg' => array( 'value' => (string) $page, 'label' => 'x' ) ) );
	$rows = $T::save( $def, $post, array( 'sg' => 'the page' ) );
	$check( 'R4 a pick that is no post id is refused, not a clear', ! $fx['spy'] && $refused( $rows, 'sg' ), wp_json_encode( array( $fx['spy'], $rows ) ) );
	$fx_reset( array( 'req' => 'stored' ) );
	$rows = $T::save( $def, $post, array( 'req' => '' ) );
	$check( 'R4 an emptied box on a field that cannot be empty is refused', ! $fx['spy'] && $refused( $rows, 'req' ), wp_json_encode( array( $fx['spy'], $rows ) ) );

	// ---------------------------------------------------------------- U
	$fx_reset( array( 't' => 'Save 50%AB <3 today', 'n' => '5', 'c' => false, 'sg' => array( 'value' => (string) $other, 'label' => 'Old' ) ) );
	$rows = $T::save( $def, $post, array( 't' => 'Save 50%AB <3 today', 'n' => 5.0, 'c' => 'false', 'sg' => array( 'value' => (string) $other, 'label' => 'Renamed' ) ) );
	$check( 'U a value sent back as served is left as stored (never cleaned or written)', ! $fx['spy'] && ! $rows, wp_json_encode( array( $fx['spy'], $rows ) ) );
	$fx_reset( array( 'sg' => array( 'value' => (string) $other, 'label' => 'Old' ) ) );
	$rows = $T::save( $def, $post, array( 'sg' => (string) $other ) );
	$check( 'U a pick sent back as its id alone is the one served', ! $fx['spy'] && ! $rows, wp_json_encode( array( $fx['spy'], $rows ) ) );

	// ---------------------------------------------------------------- T
	$fx_reset();
	$cases = array(
		// field, good value, what the write gets, bad value
		array( 't', "Tab\tbed  text", 'Tab bed text', '<i></i>' ),
		array( 'ta', "Line one\nLine two <b>x</b>", "Line one\nLine two x", '<b></b>' ),
		array( 'th', '<p>Para <strong>bold</strong></p><script>x()</script>', '<p>Para <strong>bold</strong></p>x()', '<script></script>' ),
		array( 'n', '7.5', '7.5', 'seven' ),
		array( 'ni', 7, '7', '1.5' ),
		array( 's', 'b', 'b', 'z' ),
		array( 'c', '1', true, array( 'on' ) ),
		array( 'e', ' first+tag@example.com ', 'first+tag@example.com', 'not an address' ),
		array( 'u', 'https://example.com/a?b=1&c=%20x', 'https://example.com/a?b=1&c=%20x', 'javascript:alert(1)' ),
		array( 'd', '2026-11-02', '2026-11-02', 'someday' ),
		array( 'dt', '2026-11-02 10:30', '2026-11-02 10:30:00', 'not a time' ),
		array( 'sg', array( 'value' => (string) $page, 'label' => 'p' ), $page, (string) $other ),
		array( 'so', 'y', 'y', 'z' ),
		array( 'm', array( 'id' => $image, 'url' => 'x' ), $image, (string) $other ),
	);
	foreach ( $cases as $c ) {
		list( $k, $good, $want, $bad ) = $c;
		$fx_reset();
		$rows = $T::save( $def, $post, array( $k => $good ) );
		$w    = $wrote();
		$check( "T {$k}: a value its type takes is written cleaned", array_key_exists( $k, $w ) && $want === $w[ $k ] && ! $rows, wp_json_encode( array( $w, $rows ) ) );
		$fx_reset();
		$rows = $T::save( $def, $post, array( $k => $bad ) );
		$check( "T {$k}: a value its type refuses is named and not written", ! $fx['spy'] && $refused( $rows, $k ), wp_json_encode( array( $fx['spy'], $rows ) ) );
	}
	$fx_reset();
	$rows = $T::save( $def, $post, array( 'n' => '11', 'c' => 'false' ) );
	$check( 'T n: a number above its max is refused', $refused( $rows, 'n' ) && ! array_key_exists( 'n', $wrote() ), wp_json_encode( $rows ) );
	$check( 'T c: "false" is off', false === ( $wrote()['c'] ?? null ), wp_json_encode( $wrote() ) );
	$fx_reset();
	$rows = $T::save( $def, $post, array( 'n' => '-1' ) );
	$check( 'T n: a number below its min is refused', $refused( $rows, 'n' ) && ! $fx['spy'], wp_json_encode( $rows ) );
	$hidden = $post_of( 'page', $admin, 'private' );
	$fx_reset();
	$rows = $as( $contrib, static function () use ( $T, $def, $own, $hidden ) {
		return $T::save( $def, get_post( $own ), array( 'sg' => (string) $hidden ) );
	} );
	$check( 'T sg: a post of the right type the caller may not read is refused', $refused( $rows, 'sg' ) && ! $fx['spy'], wp_json_encode( $rows ) );
	$fx_reset();
	$rows = $T::save( $def, $post, array( 't' => '100%Accurate, %category% & more' ) );
	$check( 'T t: text keeps what only looks like a percent-encoded octet', '100%Accurate, %category% & more' === ( $wrote()['t'] ?? null ), wp_json_encode( $wrote() ) );
	$fx_reset();
	$rows = $T::save( $def, $post, array( 'm' => (string) $pdf ) );
	$check( 'T m: an attachment that is no image is refused for an image field', $refused( $rows, 'm' ) && ! $fx['spy'], wp_json_encode( $rows ) );
	$fx_reset();
	$rows = $as( $contrib, static function () use ( $T, $def, $own, $image ) {
		return $T::save( $def, get_post( $own ), array( 'm' => $image ) );
	} );
	$check( 'T m: someone who cannot upload files cannot pick an attachment', $refused( $rows, 'm' ) && ! $fx['spy'], wp_json_encode( $rows ) );
	$fx_reset( array( 'm' => array( 'id' => $pdf, 'url' => 'x' ) ) );
	$rows = $as( $contrib, static function () use ( $T, $def, $own, $pdf ) {
		return $T::save( $def, get_post( $own ), array( 'm' => array( 'id' => $pdf, 'url' => 'changed' ) ) );
	} );
	$check( 'T m: the attachment already stored, sent back, is nothing to check or write', ! $rows && ! $fx['spy'], wp_json_encode( $rows ) );
	$fx_reset( array( 'm' => null ) );
	$rows = $T::save( $def, $post, array( 'm' => '' ) );
	$check( 'T m: an empty pick over an empty read writes nothing', ! $rows && ! $fx['spy'], wp_json_encode( $rows ) );
	$fx_reset( array( 'm' => array( 'id' => $image, 'url' => 'x' ) ) );
	$rows = $T::save( $def, $post, array( 'm' => null ) );
	$check( 'T m: an emptied pick clears', array_key_exists( 'm', $wrote() ) && null === $wrote()['m'] && ! $rows, wp_json_encode( array( $wrote(), $rows ) ) );
	$fx_reset();
	$rows = $T::save( $def, $post, array( 'sg' => '' ) );
	$check( 'T sg: an emptied pick clears', array_key_exists( 'sg', $wrote() ) && null === $wrote()['sg'] && ! $rows, wp_json_encode( array( $wrote(), $rows ) ) );

	// ------------------------------------------- clocks, filters, types
	$tz_was = date_default_timezone_get();
	$w      = array();
	$rows   = array( 'not run' );
	date_default_timezone_set( 'Europe/Berlin' );
	try {
		$fx_reset();
		$rows = $T::save( $def, $post, array( 'd' => '2026-10-20', 'dt' => '2026-10-20 10:00' ) );
		$w    = $wrote();
	} finally {
		date_default_timezone_set( $tz_was );
	}
	$check( 'T d, dt: a date and a time are stored as typed when PHP\'s zone is not UTC', '2026-10-20' === ( $w['d'] ?? null ) && '2026-10-20 10:00:00' === ( $w['dt'] ?? null ) && ! $rows, wp_json_encode( array( $w, $rows ) ) );
	$fx_reset();
	$rows = $T::save( $def, $post, array( 'n' => '-0' ) );
	$check( 'T n: "-0" is refused by a field that starts at 0', $refused( $rows, 'n' ) && ! $fx['spy'], wp_json_encode( $rows ) );

	$boom = static function ( $url ) {
		if ( false !== strpos( (string) $url, 'minn-boom' ) ) {
			throw new RuntimeException( 'a clean_url filter threw' );
		}
		return $url;
	};
	add_filter( 'clean_url', $boom );
	$threw = false;
	try {
		$fx_reset();
		$rows = $T::save( $def, $post, array( 'u' => 'https://example.com/minn-boom', 't' => 'beside it' ) );
	} catch ( \Throwable $e ) {
		$threw = true;
	} finally {
		remove_filter( 'clean_url', $boom );
	}
	$check( 'R2 a cleaner whose filter throws refuses that field only', ! $threw && $refused( $rows, 'u' ) && ! $refused( $rows, 't' ) && 'beside it' === ( $wrote()['t'] ?? null ) && ! array_key_exists( 'u', $wrote() ), $threw ? 'threw' : wp_json_encode( array( $wrote(), $rows ) ) );
	$cap_boom = static function ( $caps ) use ( &$fx ) {
		if ( $fx['boom'] ) {
			throw new RuntimeException( 'a map_meta_cap filter threw' );
		}
		return $caps;
	};
	add_filter( 'map_meta_cap', $cap_boom );
	$r = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $draft );
	try {
		$fx_reset();
		$fx['boom'] = true;
		$out        = $T::update( $def, array( 't' => 'changed' ), $post, $r );
	} catch ( \Throwable $e ) {
		$out = 'threw';
	} finally {
		$fx['boom'] = false;
		remove_filter( 'map_meta_cap', $cap_boom );
	}
	$held = $T::held( $r );
	$check( 'R2 a save whose gate throws answers no error and names what it was sent', null === $out && ! $fx['spy'] && $held, wp_json_encode( array( $out, $held ) ) );

	global $wp_rest_additional_fields;
	$rdef        = $def;
	$rdef['key'] = 'minn_fx_reg_' . strtolower( wp_generate_password( 4, false ) );
	$T::register_rest( $rdef );
	$on = array();
	foreach ( (array) $wp_rest_additional_fields as $t => $list ) {
		if ( isset( $list[ $rdef['key'] ] ) ) {
			$on[] = $t;
			unset( $wp_rest_additional_fields[ $t ][ $rdef['key'] ] );
		}
	}
	$check( 'R1 the REST field is registered on the panel\'s types and no other', array( 'post' ) === $on, wp_json_encode( $on ) );

	// ------------------------------------------------- declared panels
	foreach ( Minn_Admin_Kernel_Panels::ids() as $id ) {
		$real = Minn_Admin_Kernel_Panels::panel( $id );
		if ( null === $real ) {
			$check( "panel {$id}: its declaration reads whole", false, implode( '; ', Minn_Admin_Kernel_Panels::refused() ) );
			continue;
		}
		$on = $real['active'];
		if ( ! ( true === $on || ( ! is_bool( $on ) && Minn_Admin_Kernel::yes( $on ) ) ) ) {
			$skip( "panel {$id}: its plugin is not active" );
			continue;
		}
		$check( "panel {$id}: its declaration reads whole", true );
		$types = $T::types( $real );
		$type  = '';
		foreach ( $types as $t ) {
			if ( post_type_exists( $t ) && 'attachment' !== $t ) {
				$type = in_array( 'post', $types, true ) ? 'post' : $t;
				break;
			}
		}
		if ( '' === $type ) {
			$skip( "panel {$id}: no post type it serves exists here" );
			continue;
		}
		$pid  = $post_of( $type, $admin );
		$p    = get_post( $pid );
		$obj  = get_post_type_object( $type );
		$base = $obj && $obj->rest_base ? $obj->rest_base : $type;
		$rq   = new WP_REST_Request( 'GET', '/minn-admin/v1/' . $id . '/fields' );
		$rq->set_param( $real['params'][0], $pid );
		$rq->set_param( $real['params'][1], $base );
		$a = $as( $contrib, static function () use ( $T, $real, $rq ) {
			return $T::fields_answer( $real, $rq );
		} );
		$check( "panel {$id} R1: its route refuses a post the caller cannot edit", 403 === $status( $a ), wp_json_encode( $a ) );
		$v = $as( $contrib, static function () use ( $T, $real, $pid ) {
			return $T::values( $real, $pid );
		} );
		$check( "panel {$id} R1: its read is empty for a post the caller cannot edit", array() === (array) $v, wp_json_encode( $v ) );
		$spy           = array();
		$spied         = $real;
		$spied['write'] = static function ( $post_id, $changes ) use ( &$spy ) {
			$spy[] = $changes;
			return null;
		};
		$rows = $as( $contrib, static function () use ( $T, $spied, $p ) {
			return $T::save( $spied, $p, array( 'minn_any_field' => 'x' ) );
		} );
		$check( "panel {$id} R1: a save by someone who cannot edit the post writes nothing", ! $spy && $rows, wp_json_encode( $rows ) );
		$rows = $T::save( $spied, $p, array( 'minn_not_offered_' . $id => 'x' ) );
		$check( "panel {$id} R3: a field it does not offer is named and not written", ! $spy && $refused( $rows, 'minn_not_offered_' . $id ), wp_json_encode( $rows ) );
		$r   = new WP_REST_Request( 'POST', '/wp/v2/posts/' . $pid );
		$out = $T::update( $spied, array( 'minn_not_offered_' . $id => 'x' ), $p, $r );
		$T::held( $r );
		$check( "panel {$id} R2: a refused save answers no error", null === $out, wp_json_encode( $out ) );
		if ( 'tec' === $id ) {
			$tz_was = date_default_timezone_get();
			date_default_timezone_set( 'Europe/Berlin' );
			try {
				$rows = $T::save( $real, $p, array( 'start' => '2026-10-20 10:00', 'end' => '2026-10-20 12:30' ) );
			} finally {
				date_default_timezone_set( $tz_was );
			}
			wp_cache_delete( $pid, 'post_meta' );
			$check( 'panel tec T datetime: an event keeps the times typed when PHP\'s zone is not UTC', 0 === strpos( (string) get_post_meta( $pid, '_EventStartDate', true ), '2026-10-20 10:00' ) && 0 === strpos( (string) get_post_meta( $pid, '_EventEndDate', true ), '2026-10-20 12:30' ) && ! $rows, wp_json_encode( array( get_post_meta( $pid, '_EventStartDate', true ), get_post_meta( $pid, '_EventEndDate', true ), $rows ) ) );
		}
		$fields = $T::fields( $real, $pid, $type );
		if ( ! $fields ) {
			$skip( "panel {$id}: offers no fields on a new {$type} here" );
			continue;
		}
		$served = (array) $T::values( $real, $pid );
		$same   = array_intersect_key( $served, $fields );
		$spy    = array();
		$rows   = $T::save( $spied, $p, $same );
		$check( "panel {$id} U: its values sent back as read write nothing", ! $spy && ! $rows, wp_json_encode( array( $spy, $rows ) ) );
		$bad = array(
			'text'     => '<i></i>',
			'textarea' => '<script></script>',
			'number'   => 'not a number',
			'select'   => 'minn-not-a-choice',
			'checkbox' => array( 'on' ),
			'email'    => 'not an address',
			'url'      => 'javascript:alert(1)',
			'date'     => 'someday',
			'datetime' => 'not a time',
		);
		foreach ( $fields as $name => $f ) {
			$value = $bad[ $f['type'] ] ?? null;
			if ( 'suggest' === $f['type'] ) {
				$value = (string) ( 'page' === $f['post_type'] ? $other : $page );
			}
			if ( 'media' === $f['type'] ) {
				$value = (string) $other;
			}
			if ( null === $value ) {
				continue;
			}
			$spy  = array();
			$rows = $T::save( $spied, $p, array( $name => $value ) );
			$hit  = false;
			foreach ( $spy as $c ) {
				$hit = $hit || array_key_exists( $name, $c );
			}
			$check( "panel {$id} T {$f['type']} {$name}: a value its type refuses is named and not written", ! $hit && $refused( $rows, $name ), wp_json_encode( array( $spy, $rows ) ) );
		}
	}
};

// ------------------------------------------------------------------ e2e
$e2e = static function () use ( $check, $fx_def, $fx_reset, &$fx, $draft, $tag, $post_of, $admin, &$made ) {
	$def = $fx_def();
	unset( $def['id'] );
	minn_admin_fields( 'minn-fx-' . $tag, $def );
	$call = static function ( $method, $route, $body = null, $params = array() ) {
		$r = new WP_REST_Request( $method, $route );
		foreach ( $params as $k => $v ) {
			$r->set_param( $k, $v );
		}
		if ( null !== $body ) {
			$r->set_header( 'content-type', 'application/json' );
			$r->set_body( wp_json_encode( $body ) );
		}
		$res = rest_do_request( $r );
		return array( $res->get_status(), json_decode( wp_json_encode( $res->get_data() ), true ) );
	};
	$fx_reset( array( 'u' => 'https://example.com/kept' ) );
	list( $st, $d ) = $call( 'POST', '/wp/v2/posts', array( 'title' => 'Minn fields tier ' . $tag, 'status' => 'draft', 'minn_fx' => array( 'u' => 'javascript:alert(1)', 't' => 'written' ) ) );
	if ( ! empty( $d['id'] ) ) {
		$made['posts'][] = (int) $d['id'];
	}
	$rows = array();
	foreach ( (array) ( $d['minn_field_errors'] ?? array() ) as $row ) {
		$rows[ $row['field'] . ':' . $row['key'] ] = $row;
	}
	$check( 'e2e R2: a new post whose panel refuses a value answers 201 with its id', 201 === $st && ! empty( $d['id'] ), $st . ' ' . wp_json_encode( $d['code'] ?? '' ) );
	$check( 'e2e R3: the refused field is named on that answer, the other written', isset( $rows['minn_fx:u'] ) && ! isset( $rows['minn_fx:t'] ) && 'written' === ( $fx['store']['t'] ?? null ) && 'https://example.com/kept' === $fx['store']['u'], wp_json_encode( array( $rows, $fx['store'] ) ) );
	list( $st, $d ) = $call( 'GET', '/wp/v2/posts/' . $draft, null, array( 'context' => 'edit' ) );
	$check( 'e2e R1: the post\'s answer reads the panel\'s offered values', 200 === $st && isset( $d['minn_fx'] ) && ! isset( $d['minn_fx']['hidden_extra'] ), wp_json_encode( $d['minn_fx'] ?? null ) );
	list( $st, $d ) = $call( 'GET', '/minn-admin/v1/minn-fx-' . $tag . '/fields', null, array( 'post_id' => $draft, 'post_type' => 'posts' ) );
	$check( 'e2e R1: the panel\'s fields route is a kernel route', 200 === $st && ! empty( $d['groups'][0]['fields'] ), $st . ' ' . wp_json_encode( $d ) );
	$panels = Minn_Admin_Surfaces::editor_panels_for_current_user();
	$found  = null;
	foreach ( $panels as $pn ) {
		if ( 'minn-fx-' . $tag === $pn['id'] ) {
			$found = $pn;
		}
	}
	$check( 'e2e the panel joins the editor with one REST key and its route', $found && 'minn_fx' === $found['valuesKey'] && 'minn_fx' === $found['writeKey'] && 'minn-admin/v1/minn-fx-' . $tag . '/fields?post_id={id}&post_type={type}' === $found['fieldsRoute'], wp_json_encode( $found ) );
	$fx['can'] = false;
	$panels    = Minn_Admin_Surfaces::editor_panels_for_current_user();
	$gone      = true;
	foreach ( $panels as $pn ) {
		$gone = $gone && 'minn-fx-' . $tag !== $pn['id'];
	}
	list( $st ) = $call( 'GET', '/minn-admin/v1/minn-fx-' . $tag . '/fields', null, array( 'post_id' => $draft, 'post_type' => 'posts' ) );
	$check( 'e2e R1: a plugin that keeps the user out has no panel, and its route answers 403', $gone && 403 === $st, $st );
	$fx_reset();
	minn_admin_fields( 'minn-fx-' . $tag . '-bad', array( 'label' => 'x', 'key' => 'minn_fx_bad', 'types' => array( 'post' ), 'groups' => '__return_empty_array', 'read' => '__return_empty_array', 'write' => '__return_null', 'whn' => 1 ) );
	$check( 'e2e a declaration with a key the tier does not read registers nothing', null === Minn_Admin_Kernel_Panels::panel( 'minn-fx-' . $tag . '-bad' ) );
};

try {
	$alt = getenv( 'MINN_FIELDS_TIER' );
	if ( $alt ) {
		foreach ( array_filter( explode( ',', $alt ) ) as $i => $file ) {
			$class = 'Minn_Admin_Kernel_Panels_Probe' . $i;
			$src   = (string) file_get_contents( $file );
			// Only the class is under test: its boot and the functions below
			// it belong to the installed tier.
			$cut = strpos( $src, "\nMinn_Admin_Kernel_Panels::boot();" );
			$src = str_replace( 'Minn_Admin_Kernel_Panels', $class, false === $cut ? $src : substr( $src, 0, $cut ) );
			$tmp = wp_tempnam( 'minn-fields-tier' );
			file_put_contents( $tmp, $src );
			require $tmp;
			unlink( $tmp );
			echo "== {$file}\n";
			$run( $class );
		}
	} else {
		$run( 'Minn_Admin_Kernel_Panels' );
		$e2e();
	}
} finally {
	$clean();
}

$failed = count( array_filter( $results, function ( $ok ) {
	return ! $ok;
} ) );
printf( "\n%d checks, %d failed\n", count( $results ), $failed );
if ( $failed ) {
	exit( 1 );
}
