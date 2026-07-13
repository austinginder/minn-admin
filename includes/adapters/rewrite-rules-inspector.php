<?php
/**
 * Bundled adapter: Rewrite Rules Inspector (Automattic).
 *
 * Lists registered rewrite rules with source attribution through their
 * RewriteRules service (same generation logic as Tools → Rewrite Rules).
 * Flush mirrors their RuleFlush path: drop the rewrite_rules options cache
 * and flush_rewrite_rules( false ), then fire rri_flush_rules. Optional
 * URL test uses their UrlTester (first match + query vars).
 *
 * Nav: family `diagnostics` (label Diagnostics) with Scrutoscope, WP Crontrol
 * and Transients Manager — one Tools slot, provider switcher.
 *
 * Caps: manage_options (their $view_cap).
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

function minn_admin_rri_ready() {
	return defined( 'REWRITE_RULES_INSPECTOR_VERSION' )
		&& class_exists( '\\Automattic\\RewriteRulesInspector\\Core\\RewriteRules' );
}

function minn_admin_rri_can() {
	return current_user_can( 'manage_options' );
}

function minn_admin_rri_admin_url() {
	return admin_url( 'tools.php?page=rewrite-rules-inspector' );
}

/**
 * Full rule map via their service (no $_GET filter pollution).
 *
 * @return array<string,array{rewrite:string,source:string}>
 */
function minn_admin_rri_all_rules() {
	// Their get_rules() applies search/source from $_GET — stash and clear.
	$had_s      = array_key_exists( 's', $_GET );
	$had_source = array_key_exists( 'source', $_GET );
	$old_s      = $had_s ? $_GET['s'] : null;
	$old_source = $had_source ? $_GET['source'] : null;
	unset( $_GET['s'], $_GET['source'] );

	try {
		$svc   = new \Automattic\RewriteRulesInspector\Core\RewriteRules();
		$rules = $svc->get_rules();
	} finally {
		if ( $had_s ) {
			$_GET['s'] = $old_s;
		}
		if ( $had_source ) {
			$_GET['source'] = $old_source;
		}
	}

	return is_array( $rules ) ? $rules : array();
}

/**
 * Stable path-safe id for a rule pattern.
 *
 * @param string $rule Regex rule key.
 */
function minn_admin_rri_id( $rule ) {
	return substr( md5( (string) $rule ), 0, 16 );
}

/**
 * @return array{items: array, total: int}
 */
function minn_admin_rri_list( WP_REST_Request $request ) {
	$rules    = minn_admin_rri_all_rules();
	$search   = (string) $request->get_param( 'search' );
	$kind     = (string) $request->get_param( 'kind' );
	$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ?: 50 ) );
	$page     = max( 1, (int) $request->get_param( 'page' ) ?: 1 );

	$items = array();
	foreach ( $rules as $rule => $data ) {
		if ( ! is_array( $data ) ) {
			$data = array( 'rewrite' => (string) $data, 'source' => 'other' );
		}
		$source  = (string) ( $data['source'] ?? 'other' );
		$rewrite = (string) ( $data['rewrite'] ?? '' );

		if ( 'missing' === $kind && 'missing' !== $source ) {
			continue;
		}
		if ( 'post' === $kind && 'post' !== $source ) {
			continue;
		}
		if ( 'page' === $kind && 'page' !== $source ) {
			continue;
		}
		if ( 'other' === $kind && 'other' !== $source ) {
			continue;
		}
		if ( 'core' === $kind && ! in_array( $source, array( 'post', 'page', 'date', 'author', 'search', 'comments', 'root' ), true ) ) {
			continue;
		}

		if ( $search ) {
			$q = strtolower( $search );
			// Path-style search: if it looks like a path, match rules that would fire.
			$path = $search;
			if ( preg_match( '/^https?:\/\//', $path ) ) {
				$p = wp_parse_url( $path, PHP_URL_PATH );
				$path = is_string( $p ) ? $p : '';
				$home = wp_parse_url( home_url(), PHP_URL_PATH );
				if ( $home && is_string( $home ) ) {
					$path = str_replace( $home, '', $path );
				}
				$path = ltrim( (string) $path, '/' );
			} else {
				$path = ltrim( $path, '/' );
			}
			$rule_hit = false !== strpos( strtolower( (string) $rule ), $q )
				|| false !== strpos( strtolower( $rewrite ), $q )
				|| false !== strpos( strtolower( $source ), $q );
			$path_hit = ( $path !== '' && $path !== '0' )
				? (bool) @preg_match( sprintf( '#^%s#', $rule ), $path )
				: false;
			if ( ! $rule_hit && ! $path_hit ) {
				continue;
			}
		}

		$items[] = array(
			'id'      => minn_admin_rri_id( (string) $rule ),
			'rule'    => (string) $rule,
			'rewrite' => $rewrite,
			'source'  => $source,
			'status'  => 'missing' === $source ? 'missing' : 'active',
		);
	}

	$total = count( $items );
	$items = array_slice( $items, ( $page - 1 ) * $per_page, $per_page );

	return array( 'items' => $items, 'total' => $total );
}

/**
 * @param string $id md5 prefix from minn_admin_rri_id.
 * @return array|WP_Error
 */
function minn_admin_rri_detail( $id ) {
	$rules = minn_admin_rri_all_rules();
	$found = null;
	$rule  = '';
	foreach ( $rules as $r => $data ) {
		if ( minn_admin_rri_id( (string) $r ) === (string) $id ) {
			$found = is_array( $data ) ? $data : array( 'rewrite' => (string) $data, 'source' => 'other' );
			$rule  = (string) $r;
			break;
		}
	}
	if ( null === $found ) {
		return new WP_Error( 'not_found', 'Rewrite rule not found.', array( 'status' => 404 ) );
	}

	$source  = (string) ( $found['source'] ?? 'other' );
	$rewrite = (string) ( $found['rewrite'] ?? '' );
	$rows    = array(
		array( 'label' => 'Match', 'value' => $rule ),
		array( 'label' => 'Rewrite', 'value' => $rewrite ),
		array( 'label' => 'Source', 'value' => $source ),
		array(
			'label' => 'Status',
			'value' => 'missing' === $source
				? 'Missing from saved rules (generated but not stored)'
				: 'Registered',
		),
	);

	return array(
		'title'    => $rule,
		'status'   => 'missing' === $source ? 'missing' : 'active',
		'sections' => array(
			array( 'title' => 'Rule', 'rows' => $rows ),
		),
		'adminUrl' => minn_admin_rri_admin_url(),
	);
}

function minn_admin_rri_status_model() {
	$rules   = minn_admin_rri_all_rules();
	$total   = count( $rules );
	$missing = 0;
	$sources = array();
	foreach ( $rules as $data ) {
		$src = is_array( $data ) ? (string) ( $data['source'] ?? 'other' ) : 'other';
		if ( 'missing' === $src ) {
			$missing++;
		}
		$sources[ $src ] = true;
	}

	global $wp_rewrite;
	$struct = is_object( $wp_rewrite ) ? (string) $wp_rewrite->permalink_structure : (string) get_option( 'permalink_structure' );
	$ver    = defined( 'REWRITE_RULES_INSPECTOR_VERSION' ) ? REWRITE_RULES_INSPECTOR_VERSION : '—';

	return array(
		'rows'    => array(
			array(
				'label' => 'Rules',
				'value' => number_format_i18n( $total ),
				'hint'  => number_format_i18n( count( $sources ) ) . ' sources',
			),
			array(
				'label' => 'Missing',
				'value' => number_format_i18n( $missing ),
				'hint'  => $missing ? 'Generated but not in the saved option' : 'Saved set matches generation',
			),
			array(
				'label' => 'Permalink structure',
				'value' => $struct ? $struct : 'Plain (no pretty permalinks)',
				'hint'  => $struct ? 'Pretty permalinks on' : 'Flush only helps after a structure is set',
			),
			array(
				'label' => 'Rewrite Rules Inspector',
				'value' => (string) $ver,
			),
		),
		'actions' => array(
			array(
				'label'   => 'Flush rewrite rules',
				'method'  => 'POST',
				'route'   => 'minn-admin/v1/rewrite-rules/flush',
				'confirm' => 'Flush rewrite rules? WordPress will regenerate them from the current structure and plugins.',
			),
			array(
				'label'  => 'Test a URL',
				'method' => 'POST',
				'route'  => 'minn-admin/v1/rewrite-rules/test',
				'fields' => array(
					array(
						'key'         => 'url',
						'label'       => 'Path or URL',
						'placeholder' => '/sample-page/ or full URL',
					),
				),
			),
			array(
				'label' => 'Open Rewrite Rules Inspector ↗',
				'href'  => minn_admin_rri_admin_url(),
			),
		),
	);
}

/**
 * Flush path mirrors Automattic\RewriteRulesInspector\Core\RuleFlush::perform_flush.
 */
function minn_admin_rri_flush() {
	wp_cache_delete( 'rewrite_rules', 'options' );
	// Soft flush (false) matches their tool — hard flush rewrites .htaccess too.
	flush_rewrite_rules( false );
	/**
	 * Fired after RRI flushes rules (their hook; adapters may listen).
	 */
	do_action( 'rri_flush_rules' );
}

add_filter( 'minn_admin_surfaces', function ( $surfaces ) {
	if ( ! minn_admin_rri_ready() || ! minn_admin_rri_can() ) {
		return $surfaces;
	}

	$surfaces['rewrite-rules-inspector'] = array(
		'label'      => 'Diagnostics',
		'sub'        => 'Rewrites',
		'family'     => 'diagnostics',
		'icon'       => 'activity',
		'cap'        => 'manage_options',
		'group'      => 'tools',
		'status'     => array( 'route' => 'minn-admin/v1/rewrite-rules/status' ),
		'collection' => array(
			'viewLabel' => 'Rules',
			'route'     => 'minn-admin/v1/rewrite-rules',
			'pageQuery' => 'per_page=50&page={page}',
			'search'    => 'search={q}',
			'itemsKey'  => 'items',
			'totalKey'  => 'total',
			'tabs'      => array(
				'param'    => 'kind',
				'static'   => array(
					array( 'missing', 'Missing' ),
					array( 'core', 'Core' ),
					array( 'post', 'Posts' ),
					array( 'page', 'Pages' ),
					array( 'other', 'Other / plugins' ),
				),
				'allLabel' => 'All rules',
			),
			'columns'   => array(
				array( 'key' => 'rule', 'label' => 'Match', 'format' => 'title' ),
				array( 'key' => 'rewrite', 'label' => 'Query', 'format' => 'text' ),
				array( 'key' => 'source', 'label' => 'Source', 'format' => 'text' ),
				array( 'key' => 'status', 'label' => 'Status', 'format' => 'pill' ),
			),
			'detail'    => array(
				'sectionsRoute' => 'minn-admin/v1/rewrite-rules/{id}',
			),
			'actions'   => array(
				array(
					'label' => 'Open Rewrite Rules Inspector ↗',
					'href'  => minn_admin_rri_admin_url(),
				),
			),
		),
	);
	return $surfaces;
} );

add_action( 'rest_api_init', function () {
	if ( ! minn_admin_rri_ready() ) {
		return;
	}

	$perm = 'minn_admin_rri_can';

	register_rest_route( 'minn-admin/v1', '/rewrite-rules', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			return rest_ensure_response( minn_admin_rri_list( $request ) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/rewrite-rules/(?P<id>[a-f0-9]{16})', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$out = minn_admin_rri_detail( (string) $request['id'] );
			return is_wp_error( $out ) ? $out : rest_ensure_response( $out );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/rewrite-rules/status', array(
		'methods'             => 'GET',
		'permission_callback' => $perm,
		'callback'            => function () {
			return rest_ensure_response( minn_admin_rri_status_model() );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/rewrite-rules/flush', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function () {
			// Honor their flushing_enabled filter/flag when the plugin object is up.
			global $rewrite_rules_inspector;
			if ( is_object( $rewrite_rules_inspector ) && isset( $rewrite_rules_inspector->flushing_enabled )
				&& ! $rewrite_rules_inspector->flushing_enabled ) {
				return new WP_Error( 'forbidden', 'Rewrite rule flushing is disabled.', array( 'status' => 403 ) );
			}
			minn_admin_rri_flush();
			$count = count( minn_admin_rri_all_rules() );
			return rest_ensure_response( array(
				'ok'      => true,
				'message' => sprintf( 'Rewrite rules flushed. %s rules registered.', number_format_i18n( $count ) ),
			) );
		},
	) );

	register_rest_route( 'minn-admin/v1', '/rewrite-rules/test', array(
		'methods'             => 'POST',
		'permission_callback' => $perm,
		'callback'            => function ( WP_REST_Request $request ) {
			$body = $request->get_json_params();
			$url  = isset( $body['url'] ) ? sanitize_text_field( (string) $body['url'] ) : '';
			if ( '' === $url ) {
				return new WP_Error( 'empty_url', 'Enter a path or URL to test.', array( 'status' => 400 ) );
			}
			if ( ! class_exists( '\\Automattic\\RewriteRulesInspector\\Core\\UrlTester' ) ) {
				return new WP_Error( 'unavailable', 'URL tester is not available.', array( 'status' => 500 ) );
			}
			$tester = new \Automattic\RewriteRulesInspector\Core\UrlTester();
			$result = $tester->test_url_with_rules( $url, minn_admin_rri_all_rules() );
			if ( ! empty( $result['is_404'] ) || empty( $result['first_match'] ) ) {
				return rest_ensure_response( array(
					'ok'      => true,
					'message' => 'No rewrite rule matches “' . $url . '” (would 404 on routing alone).',
				) );
			}
			$m = $result['first_match'];
			$msg = sprintf(
				'First match: %s → %s (source: %s)',
				isset( $m['rule'] ) ? $m['rule'] : '?',
				isset( $m['rewrite'] ) ? $m['rewrite'] : '?',
				isset( $m['source'] ) ? $m['source'] : '?'
			);
			return rest_ensure_response( array(
				'ok'      => true,
				'message' => $msg,
				'match'   => $m,
			) );
		},
	) );
} );
