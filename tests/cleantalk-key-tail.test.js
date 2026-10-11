/**
 * CleanTalk's access key shows no characters to someone who may not manage it
 * (no browser, ~20 s).
 *
 * The spam settings note printed the key's first four characters and the
 * Connectors card its last four, to anyone with manage_options. The key is
 * saved only with activate_plugins, and on a network a site's key can be the
 * network's, which CleanTalk's own subsite screen withholds. The note now
 * names no characters, and the card's last four go only to someone who may
 * manage plugins (a super admin on a network).
 *
 * CleanTalk is installed-inactive at rest on minnadmin (Antispam Bee is the
 * resident): the suite activates it, sets a fake key, asks both routes as an
 * administrator and as a settings-only role through wp eval-file, and puts
 * the key, the role and the plugin back.
 */
const { execFileSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { WP } = require( './helpers' );

const wp = ( ...args ) => execFileSync( 'wp', [ `--path=${ WP }`, ...args ], { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'ignore' ] } ).trim();
const results = [];
const t = {
	check( label, ok, detail = '' ) {
		results.push( ok );
		console.log( `${ ok ? 'PASS' : 'FAIL' }  ${ label }${ detail ? ' — ' + detail : '' }` );
	},
};

const probe = path.join( os.tmpdir(), `minn-ct-tail-${ process.pid }.php` );
fs.writeFileSync( probe, `<?php
$out = array( 'tail' => null, 'note' => null );
$r   = rest_do_request( new WP_REST_Request( 'GET', '/minn-admin/v1/connectors' ) );
foreach ( (array) ( $r->get_data()['connectors'] ?? array() ) as $c ) {
	if ( 'cleantalk' === ( $c['id'] ?? '' ) ) {
		$out['tail'] = (string) $c['keyTail'];
	}
}
$walk = function ( $v ) use ( &$walk, &$out ) {
	if ( is_object( $v ) ) {
		$v = (array) $v;
	}
	if ( ! is_array( $v ) ) {
		return;
	}
	if ( 'cleantalk' === ( $v['id'] ?? '' ) && isset( $v['note'] ) ) {
		$out['note'] = (string) $v['note'];
	}
	foreach ( $v as $x ) {
		$walk( $x );
	}
};
$walk( json_decode( wp_json_encode( rest_do_request( new WP_REST_Request( 'GET', '/minn-admin/v1/spam' ) )->get_data() ), true ) );
echo wp_json_encode( $out );
` );
const ask = ( user ) => {
	try {
		return JSON.parse( wp( 'eval-file', probe, `--user=${ user }` ) );
	} catch ( e ) {
		return { error: true };
	}
};

const fake = 'minnFAKEcleantalk' + String( Date.now() ).slice( -6 ) + 'TAIL';
const login = 'minn-ct-settings-' + process.pid;
let wasActive = false;
let settingsWas = null;
( async () => {
	try {
		const status = wp( 'plugin', 'list', '--name=cleantalk-spam-protect', '--field=status' );
		if ( ! status ) {
			console.log( 'SKIP  CleanTalk is not installed' );
			return;
		}
		wasActive = 'active' === status;
		if ( ! wasActive ) {
			wp( 'plugin', 'activate', 'cleantalk-spam-protect' );
		}
		settingsWas = wp( 'option', 'get', 'cleantalk_settings', '--format=json' );
		const settings = JSON.parse( settingsWas || '{}' );
		settings.apikey = fake;
		wp( 'option', 'update', 'cleantalk_settings', JSON.stringify( settings ), '--format=json' );
		wp( 'eval', "add_role( 'minn_ct_settings', 'Minn CleanTalk settings', array( 'read' => true, 'manage_options' => true ) );" );
		wp( 'user', 'create', login, `${ login }@example.com`, '--role=minn_ct_settings', '--porcelain' );

		const admin = ask( 'admin' );
		const settingsOnly = ask( login );
		const head = fake.slice( 0, 4 );
		t.check( 'the spam note names no characters of the key', admin.note === 'Access key set' && settingsOnly.note === 'Access key set', JSON.stringify( [ admin.note, settingsOnly.note ].map( ( n ) => ( n || '' ).includes( head ) ? 'shows key characters' : n ) ) );
		t.check( 'someone who may manage plugins still sees the last four on the card', admin.tail === 'TAIL', admin.tail === null ? 'no card' : ( admin.tail ? 'shown' : 'empty' ) );
		t.check( 'a settings-only role sees none of it', settingsOnly.tail === '', settingsOnly.tail === null ? 'no card' : ( settingsOnly.tail ? 'shown' : 'empty' ) );
	} finally {
		try {
			wp( 'user', 'delete', login, '--yes' );
		} catch ( e ) {}
		try {
			wp( 'eval', "remove_role( 'minn_ct_settings' );" );
		} catch ( e ) {}
		if ( null !== settingsWas ) {
			wp( 'option', 'update', 'cleantalk_settings', settingsWas, '--format=json' );
		}
		if ( ! wasActive ) {
			try {
				wp( 'plugin', 'deactivate', 'cleantalk-spam-protect' );
			} catch ( e ) {}
		}
		fs.unlinkSync( probe );
		const failed = results.filter( ( r ) => ! r ).length;
		console.log( `\ncleantalk-key-tail: ${ results.length - failed }/${ results.length } passed` );
		process.exitCode = failed || ! results.length ? 1 : 0;
	}
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
