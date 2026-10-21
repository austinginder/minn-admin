<?php
/**
 * The write surface: every way a request can change something through Minn on
 * the site it runs on, and who owns the rules for that change.
 *
 * A write is a REST handler under minn-admin/v1 that takes anything but GET or
 * HEAD, a REST field Minn adds with an update callback (a panel save rides the
 * post's own save), or an admin-ajax / admin-post action Minn hooks. Each is
 * sorted by where its rules live:
 *
 *   settings   the settings tier saves it (minn_admin_settings)
 *   fields     the fields tier saves it (minn_admin_fields)
 *   declared   a resource's create, save, delete or item action: the kernel
 *              owns the order of answers and the post-condition, the adapter
 *              only the vendor call
 *   handler    a kernel endpoint whose logic the adapter writes itself
 *   route      a route the adapter registers itself
 *   panel      a REST field save an adapter writes itself
 *   ajax       an admin-ajax or admin-post action
 *   core       Minn's own screens (posts, users, plugins, settings), no adapter
 *
 * handler, route, panel and ajax are hand-written: the rules a review has to
 * read line by line. Only plugins active here register, so the census covers
 * what this site runs; run it on each lab that holds other fixtures.
 *
 * Run: wp eval-file bin/write-surface.php [json] --user=<an administrator>
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

$minn_ws_root = wp_normalize_path( MINN_ADMIN_DIR );

/** The Minn file a callable is written in, relative, or '' for one outside Minn. */
$minn_ws_file = static function ( $cb ) use ( $minn_ws_root ) {
	try {
		if ( $cb instanceof Closure || ( is_string( $cb ) && function_exists( $cb ) ) ) {
			$ref = new ReflectionFunction( $cb );
		} elseif ( is_array( $cb ) && 2 === count( $cb ) ) {
			$ref = new ReflectionMethod( $cb[0], $cb[1] );
		} elseif ( is_string( $cb ) && false !== strpos( $cb, '::' ) ) {
			$ref = new ReflectionMethod( $cb );
		} elseif ( is_object( $cb ) && method_exists( $cb, '__invoke' ) ) {
			$ref = new ReflectionMethod( $cb, '__invoke' );
		} else {
			return '';
		}
	} catch ( ReflectionException $e ) {
		return '';
	}
	$file = wp_normalize_path( (string) $ref->getFileName() );
	return 0 === strpos( $file, $minn_ws_root ) ? substr( $file, strlen( $minn_ws_root ) ) : '';
};

/** An adapter's id from its file, or '' for a file that is not an adapter. */
$minn_ws_adapter = static function ( $file ) {
	return preg_match( '#^includes/adapters/([^/]+)\.php$#', $file, $m ) ? $m[1] : '';
};

// Every route registers on rest_api_init; a CLI process has not fired it.
rest_get_server();

// The kernel endpoints, by method and route, with the file their logic is in.
$minn_ws_eps = array();
foreach ( Minn_Admin_Kernel::endpoints() as $ep ) {
	foreach ( (array) ( $ep['methods'] ?? 'GET' ) as $ms ) {
		foreach ( explode( ',', (string) $ms ) as $m ) {
			$minn_ws_eps[ strtoupper( trim( $m ) ) . ' /minn-admin/v1/' . $ep['path'] ] = array(
				'run'  => isset( $ep['run'] ) ? $minn_ws_file( $ep['run'] ) : '',
				'file' => (string) ( $ep['file'] ?? '' ),
			);
		}
	}
}

$minn_ws_rows = array();
$minn_ws_add  = static function ( array $row ) use ( &$minn_ws_rows ) {
	$minn_ws_rows[] = $row + array( 'adapter' => '', 'file' => '' );
};

foreach ( rest_get_server()->get_routes( 'minn-admin/v1' ) as $route => $handlers ) {
	foreach ( $handlers as $h ) {
		$writes = array_diff( array_keys( array_filter( (array) $h['methods'] ) ), array( 'GET', 'HEAD' ) );
		foreach ( $writes as $method ) {
			$key = "{$method} {$route}";
			if ( isset( $h['minn_file'] ) ) {
				$file = (string) $h['minn_file'];
				if ( isset( $minn_ws_eps[ $key ] ) ) {
					$run  = $minn_ws_eps[ $key ]['run'];
					$tier = 'includes/kernel/class-minn-admin-kernel-settings.php' === $run ? 'settings'
						: ( 'includes/kernel/class-minn-admin-kernel-panels.php' === $run ? 'fields' : 'handler' );
				} else {
					$tier = 'declared';
				}
			} else {
				$file = $minn_ws_file( $h['callback'] );
				// A route in the namespace whose code is not Minn's (a lab's
				// own fixtures) is not Minn's surface.
				if ( '' === $file ) {
					continue;
				}
				$tier = '' === $minn_ws_adapter( $file ) ? 'core' : 'route';
			}
			$minn_ws_add( array( 'kind' => 'rest', 'method' => $method, 'path' => $route, 'tier' => $tier, 'adapter' => $minn_ws_adapter( $file ), 'file' => $file ) );
		}
	}
}

// REST fields Minn adds with an update callback: the panel saves. One row
// per field and the file that saves it, however many object types carry it.
global $wp_rest_additional_fields;
$minn_ws_fields = array();
foreach ( (array) $wp_rest_additional_fields as $type => $fields ) {
	foreach ( (array) $fields as $name => $def ) {
		if ( empty( $def['update_callback'] ) ) {
			continue;
		}
		$file = $minn_ws_file( $def['update_callback'] );
		if ( '' === $file ) {
			continue;
		}
		$minn_ws_fields[ "{$name} {$file}" ]           = $minn_ws_fields[ "{$name} {$file}" ] ?? array( 'name' => $name, 'file' => $file, 'types' => array() );
		$minn_ws_fields[ "{$name} {$file}" ]['types'][] = $type;
	}
}
foreach ( $minn_ws_fields as $fd ) {
	$file = $fd['file'];
	$minn_ws_add( array(
		'kind'    => 'field',
		'method'  => 'UPDATE',
		'path'    => $fd['name'] . ' (' . implode( ', ', $fd['types'] ) . ')',
		'tier'    => 'includes/kernel/class-minn-admin-kernel-panels.php' === $file ? 'fields' : ( '' === $minn_ws_adapter( $file ) ? 'core' : 'panel' ),
		// A fields-tier panel is saved by the kernel; name it by its panel.
		'adapter' => $minn_ws_adapter( $file ) ? $minn_ws_adapter( $file ) : preg_replace( '/^minn_/', '', $fd['name'] ),
		'file'    => $file,
	) );
}

// admin-ajax and admin-post actions Minn hooks.
global $wp_filter;
foreach ( $wp_filter as $hook => $obj ) {
	if ( ! preg_match( '/^(wp_ajax_|wp_ajax_nopriv_|admin_post_|admin_post_nopriv_)/', (string) $hook ) ) {
		continue;
	}
	foreach ( $obj->callbacks as $cbs ) {
		foreach ( $cbs as $cb ) {
			$file = $minn_ws_file( $cb['function'] );
			if ( '' === $file ) {
				continue;
			}
			$minn_ws_add( array( 'kind' => 'ajax', 'method' => 'POST', 'path' => (string) $hook, 'tier' => 'ajax', 'adapter' => $minn_ws_adapter( $file ), 'file' => $file ) );
		}
	}
}

usort( $minn_ws_rows, static function ( $a, $b ) {
	return array( $a['adapter'], $a['path'], $a['method'] ) <=> array( $b['adapter'], $b['path'], $b['method'] );
} );

$minn_ws_hand = array( 'handler', 'route', 'panel', 'ajax' );
$minn_ws_sum  = array( 'total' => count( $minn_ws_rows ), 'hand_written' => 0, 'by_tier' => array() );
foreach ( $minn_ws_rows as $row ) {
	$minn_ws_sum['by_tier'][ $row['tier'] ] = ( $minn_ws_sum['by_tier'][ $row['tier'] ] ?? 0 ) + 1;
	if ( in_array( $row['tier'], $minn_ws_hand, true ) ) {
		++$minn_ws_sum['hand_written'];
	}
}
ksort( $minn_ws_sum['by_tier'] );

if ( in_array( 'json', (array) ( $args ?? array() ), true ) ) {
	echo wp_json_encode( array( 'summary' => $minn_ws_sum, 'writes' => $minn_ws_rows ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) . "\n";
	return;
}
foreach ( $minn_ws_rows as $row ) {
	printf( "%-9s %-6s %-26s %s\n", $row['tier'], $row['method'], $row['adapter'] ? $row['adapter'] : '(' . $row['file'] . ')', $row['path'] );
}
printf( "\n%d writes, %d hand-written. By tier: %s\n", $minn_ws_sum['total'], $minn_ws_sum['hand_written'], wp_json_encode( $minn_ws_sum['by_tier'] ) );
