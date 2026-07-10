<?php
/**
 * Spam filter providers — the Settings → Spam page.
 *
 * Detects the site's spam protection at call time and exposes a uniform
 * card per provider: configured state, all-time blocked count, a few safe
 * toggles written back through the provider's own option shape, and a link
 * to its full wp-admin screen. Bundled: Akismet, Antispam Bee, CleanTalk.
 * Third parties append via the `minn_admin_spam_providers` filter
 * (docs/for-plugin-authors.md).
 *
 * Each provider descriptor:
 *   id       string
 *   name     string
 *   status   callable(): array {
 *              configured bool     key/setup state
 *              note       string   one-line status ("API key set (a1b2…)")
 *              blocked    int      all-time blocked count
 *              toggles    array[]  { id, label, desc, on }
 *              adminUrl   string   the provider's wp-admin screen
 *            }
 *   set      callable( $toggle_id, $on ) apply one toggle
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_spam_providers() {
	$providers = array();

	// --- Akismet (key-based cloud filtering) ----------------------------
	if ( class_exists( 'Akismet' ) ) {
		$providers[] = array(
			'id'     => 'akismet',
			'name'   => 'Akismet',
			'status' => function () {
				$key = '';
				try {
					$key = (string) Akismet::get_api_key();
				} catch ( \Throwable $e ) { /* stay unconfigured */ }
				return array(
					'configured' => '' !== $key,
					'note'       => '' !== $key
						? 'API key set (' . substr( $key, 0, 4 ) . '…)'
						: 'Needs an API key: connect it on the Akismet screen',
					'blocked'    => (int) get_option( 'akismet_spam_count', 0 ),
					'toggles'    => array(
						array(
							'id'    => 'strictness',
							'label' => 'Silently discard the worst spam',
							'desc'  => 'The most pervasive spam never reaches the spam folder. Off keeps everything reviewable for 15 days.',
							'on'    => get_option( 'akismet_strictness' ) === '1',
						),
					),
					'adminUrl'   => admin_url( 'options-general.php?page=akismet-key-config' ),
				);
			},
			'set'    => function ( $id, $on ) {
				if ( 'strictness' === $id ) {
					// Akismet stores '1'/'0' strings — match its own writes.
					update_option( 'akismet_strictness', $on ? '1' : '0' );
				}
			},
		);
	}

	// --- Antispam Bee (local heuristics, no cloud) -----------------------
	if ( class_exists( 'Antispam_Bee' ) ) {
		// The option array only holds keys that differ from Antispam_Bee's
		// defaults on some installs — read with the defaults that matter here.
		$asb_read = function () {
			$o = get_option( 'antispam_bee' );
			return is_array( $o ) ? $o : array();
		};
		$providers[] = array(
			'id'     => 'antispam-bee',
			'name'   => 'Antispam Bee',
			'status' => function () use ( $asb_read ) {
				$o = $asb_read();
				return array(
					'configured' => true, // works out of the box, no key
					'note'       => 'Local filtering, no cloud service or key needed',
					'blocked'    => isset( $o['spam_count'] ) ? (int) $o['spam_count'] : 0,
					'toggles'    => array(
						array(
							'id'    => 'flag_spam',
							'label' => 'Keep spam for review',
							'desc'  => 'Move detected spam to the spam folder instead of deleting it immediately.',
							'on'    => ! isset( $o['flag_spam'] ) || (int) $o['flag_spam'] === 1, // default 1
						),
						array(
							'id'    => 'email_notify',
							'label' => 'Email me about new spam',
							'desc'  => 'Send a notification when a comment lands in the spam folder.',
							'on'    => isset( $o['email_notify'] ) && (int) $o['email_notify'] === 1, // default 0
						),
					),
					'adminUrl'   => admin_url( 'options-general.php?page=antispam_bee' ),
				);
			},
			'set'    => function ( $id, $on ) use ( $asb_read ) {
				if ( ! in_array( $id, array( 'flag_spam', 'email_notify' ), true ) ) {
					return;
				}
				$o        = $asb_read();
				$o[ $id ] = $on ? 1 : 0;
				update_option( 'antispam_bee', $o );
			},
		);
	}

	// --- CleanTalk Anti-Spam (cloud) -------------------------------------
	if ( defined( 'APBCT_VERSION' ) ) {
		$providers[] = array(
			'id'     => 'cleantalk',
			'name'   => 'CleanTalk Anti-Spam',
			'status' => function () {
				$settings = get_option( 'cleantalk_settings' );
				$data     = get_option( 'cleantalk_data' );
				$key      = is_array( $settings ) && ! empty( $settings['apikey'] ) ? (string) $settings['apikey'] : '';
				$counter  = is_array( $data ) && isset( $data['admin_bar__all_time_counter'] ) ? (array) $data['admin_bar__all_time_counter'] : array();
				return array(
					'configured' => '' !== $key,
					'note'       => '' !== $key
						? 'Access key set (' . substr( $key, 0, 4 ) . '…)'
						: 'Needs an access key: connect it on the CleanTalk screen',
					'blocked'    => isset( $counter['blocked'] ) ? (int) $counter['blocked'] : 0,
					'toggles'    => array(), // config is cloud-side; keep read-only
					'adminUrl'   => admin_url( 'options-general.php?page=cleantalk' ),
				);
			},
			'set'    => function () {},
		);
	}

	return apply_filters( 'minn_admin_spam_providers', $providers );
}

/** The Settings → Spam page state. */
function minn_admin_spam_state() {
	$providers = array();
	foreach ( minn_admin_spam_providers() as $p ) {
		if ( ! is_array( $p ) || empty( $p['id'] ) ) {
			continue;
		}
		$st = array();
		try {
			$st = is_callable( $p['status'] ?? null ) ? (array) call_user_func( $p['status'] ) : array();
		} catch ( \Throwable $e ) { /* a broken provider never breaks the page */ }
		$providers[] = array(
			'id'         => (string) $p['id'],
			'name'       => isset( $p['name'] ) ? (string) $p['name'] : (string) $p['id'],
			'configured' => ! empty( $st['configured'] ),
			'note'       => isset( $st['note'] ) ? (string) $st['note'] : '',
			'blocked'    => isset( $st['blocked'] ) ? (int) $st['blocked'] : 0,
			'toggles'    => isset( $st['toggles'] ) && is_array( $st['toggles'] ) ? array_values( $st['toggles'] ) : array(),
			'adminUrl'   => isset( $st['adminUrl'] ) ? (string) $st['adminUrl'] : '',
		);
	}
	$counts = wp_count_comments();
	return array(
		'providers'       => $providers,
		'queue'           => array(
			'spam'    => (int) $counts->spam,
			'pending' => (int) $counts->moderated,
		),
		// Core's built-in filter: comments matching these lines go straight
		// to the spam folder (one word/IP/email/URL fragment per line).
		'disallowed_keys' => (string) get_option( 'disallowed_keys', '' ),
	);
}

add_action( 'rest_api_init', function () {
	$can = function () {
		return current_user_can( 'manage_options' );
	};
	register_rest_route(
		'minn-admin/v1',
		'/spam',
		array(
			array(
				'methods'             => 'GET',
				'permission_callback' => $can,
				'callback'            => function () {
					return rest_ensure_response( minn_admin_spam_state() );
				},
			),
			array(
				'methods'             => 'POST',
				'permission_callback' => $can,
				'callback'            => function ( WP_REST_Request $req ) {
					$toggles = $req->get_param( 'toggles' );
					if ( is_array( $toggles ) ) {
						$providers = array();
						foreach ( minn_admin_spam_providers() as $p ) {
							if ( is_array( $p ) && ! empty( $p['id'] ) ) {
								$providers[ $p['id'] ] = $p;
							}
						}
						foreach ( $toggles as $pid => $set ) {
							if ( ! isset( $providers[ $pid ] ) || ! is_array( $set ) || ! is_callable( $providers[ $pid ]['set'] ?? null ) ) {
								continue;
							}
							foreach ( $set as $tid => $on ) {
								try {
									call_user_func( $providers[ $pid ]['set'], (string) $tid, (bool) $on );
								} catch ( \Throwable $e ) { /* skip the broken toggle */ }
							}
						}
					}
					// Core sanitizes registered options on update_option
					// (sanitize_option strips tags per line for this one).
					$keys = $req->get_param( 'disallowed_keys' );
					if ( null !== $keys && is_string( $keys ) ) {
						update_option( 'disallowed_keys', $keys );
					}
					return rest_ensure_response( minn_admin_spam_state() );
				},
			),
		)
	);
} );
