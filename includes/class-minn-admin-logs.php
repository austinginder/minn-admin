<?php
/**
 * Log sources — the multi-source site log registry behind System's log viewer.
 *
 * Bundled sources: the WordPress debug log, the PHP error log (when it is a
 * distinct readable file), and one source per WooCommerce log channel (read
 * through WC's own FileV2 controller, never raw globs). Plugins and host
 * mu-plugins can add their own via the `minn_admin_log_sources` filter:
 *
 *   id => array(
 *     'label' => 'My log',
 *     'group' => 'My Plugin',
 *     'stat'  => callable(): { exists, size, modified },   // cheap, for lists
 *     'read'  => callable(): { exists, path, size, size_human, truncated, content, note? },
 *     'clear' => callable(): true|WP_Error,                // optional
 *   )
 *
 * True web-server access/error logs are deliberately NOT guessed at: their
 * paths are host-specific and usually outside open_basedir. A host that does
 * expose them can register a source through the filter.
 *
 * @package Minn_Admin
 */

defined( 'ABSPATH' ) || exit;

class Minn_Admin_Logs {

	const TAIL_BYTES = 262144; // 256 KB — never serve a whole log.

	/**
	 * Where WordPress-level PHP errors land: an explicit WP_DEBUG_LOG path,
	 * else a file-shaped error_log ini, else content/debug.log.
	 */
	public static function debug_log_path() {
		if ( defined( 'WP_DEBUG_LOG' ) && is_string( WP_DEBUG_LOG ) && '' !== WP_DEBUG_LOG ) {
			return WP_DEBUG_LOG;
		}
		$ini = ini_get( 'error_log' );
		if ( $ini && 'syslog' !== $ini && ( file_exists( $ini ) || is_dir( dirname( $ini ) ) ) && '/' === substr( $ini, 0, 1 ) ) {
			return $ini;
		}
		return WP_CONTENT_DIR . '/debug.log';
	}

	/**
	 * The full source registry (bundled + filter-registered).
	 */
	public static function sources() {
		$sources = array();

		$debug            = self::debug_log_path();
		$sources['debug'] = self::file_source( 'Debug log', 'WordPress', $debug, true );

		// The PHP error log, when it is a separate real file (not syslog,
		// not already the debug path).
		$ini = ini_get( 'error_log' );
		if ( $ini && 'syslog' !== $ini && '/' === substr( $ini, 0, 1 ) && $ini !== $debug && file_exists( $ini ) ) {
			$sources['php-error'] = self::file_source( 'PHP error log', 'Server', $ini, true );
		}

		// One source per WooCommerce log channel, via WC's own controller.
		if ( class_exists( 'WooCommerce' )
			&& function_exists( 'wc_get_container' )
			&& class_exists( '\Automattic\WooCommerce\Internal\Admin\Logging\FileV2\FileController' ) ) {
			try {
				$fc       = wc_get_container()->get( \Automattic\WooCommerce\Internal\Admin\Logging\FileV2\FileController::class );
				$channels = $fc->get_file_sources();
				if ( is_array( $channels ) ) {
					$channels = array_values( $channels );
					sort( $channels );
					foreach ( $channels as $channel ) {
						$sources[ 'wc:' . $channel ] = self::wc_source( $fc, (string) $channel );
					}
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
				// WC internals shifted — skip the channels, never fatal.
			}
		}

		$sources = apply_filters( 'minn_admin_log_sources', $sources );
		return is_array( $sources ) ? $sources : array();
	}

	/**
	 * Cheap list payload for the System card and the viewer's source picker:
	 * existing sources only, sizes from stat(), never file contents.
	 */
	public static function list_payload() {
		$out = array();
		foreach ( self::sources() as $id => $src ) {
			if ( ! is_array( $src ) || empty( $src['stat'] ) || ! is_callable( $src['stat'] ) ) {
				continue;
			}
			try {
				$stat = call_user_func( $src['stat'] );
			} catch ( \Throwable $e ) {
				continue;
			}
			if ( empty( $stat['exists'] ) ) {
				continue;
			}
			$out[] = array(
				'id'         => (string) $id,
				'label'      => isset( $src['label'] ) ? (string) $src['label'] : (string) $id,
				'group'      => isset( $src['group'] ) ? (string) $src['group'] : '',
				'size'       => (int) $stat['size'],
				'size_human' => size_format( (int) $stat['size'], 1 ),
				'modified'   => ! empty( $stat['modified'] ) ? gmdate( 'c', (int) $stat['modified'] ) : null,
				'clearable'  => ! empty( $src['clear'] ),
			);
		}
		// Debug and PHP logs lead; the rest newest-first.
		usort( $out, function ( $a, $b ) {
			$rank = function ( $row ) {
				if ( 'debug' === $row['id'] ) {
					return 0;
				}
				return 'php-error' === $row['id'] ? 1 : 2;
			};
			if ( $rank( $a ) !== $rank( $b ) ) {
				return $rank( $a ) - $rank( $b );
			}
			return strcmp( (string) $b['modified'], (string) $a['modified'] );
		} );
		return $out;
	}

	/**
	 * Read one source's tail.
	 *
	 * @return array|WP_Error
	 */
	public static function read( $id ) {
		$sources = self::sources();
		if ( ! isset( $sources[ $id ] ) || ! is_callable( $sources[ $id ]['read'] ) ) {
			return new WP_Error( 'unknown_log', 'Unknown log source.', array( 'status' => 404 ) );
		}
		try {
			$payload = call_user_func( $sources[ $id ]['read'] );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'log_read_failed', 'Could not read this log.', array( 'status' => 500 ) );
		}
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		$payload['id']        = (string) $id;
		$payload['label']     = isset( $sources[ $id ]['label'] ) ? (string) $sources[ $id ]['label'] : (string) $id;
		$payload['group']     = isset( $sources[ $id ]['group'] ) ? (string) $sources[ $id ]['group'] : '';
		$payload['clearable'] = ! empty( $sources[ $id ]['clear'] );
		return $payload;
	}

	/**
	 * Clear one source, where the source offers it.
	 *
	 * @return true|WP_Error
	 */
	public static function clear( $id ) {
		$sources = self::sources();
		if ( ! isset( $sources[ $id ] ) ) {
			return new WP_Error( 'unknown_log', 'Unknown log source.', array( 'status' => 404 ) );
		}
		if ( empty( $sources[ $id ]['clear'] ) || ! is_callable( $sources[ $id ]['clear'] ) ) {
			return new WP_Error( 'not_clearable', 'This log cannot be cleared from here.', array( 'status' => 400 ) );
		}
		try {
			$result = call_user_func( $sources[ $id ]['clear'] );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'clear_failed', 'Could not clear the log.', array( 'status' => 500 ) );
		}
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Tail a file: last TAIL_BYTES with the partial first line dropped.
	 */
	public static function tail_file( $path ) {
		$rel = str_replace( ABSPATH, '', (string) $path );
		if ( ! file_exists( $path ) ) {
			return array(
				'exists'  => false,
				'path'    => $rel,
				'content' => '',
				'size'    => 0,
			);
		}
		$size      = (int) filesize( $path );
		$truncated = $size > self::TAIL_BYTES;
		$content   = '';
		$fh        = @fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( $fh ) {
			if ( $truncated ) {
				fseek( $fh, -self::TAIL_BYTES, SEEK_END );
			}
			$content = (string) stream_get_contents( $fh );
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( $truncated ) {
				$nl = strpos( $content, "\n" );
				if ( false !== $nl ) {
					$content = substr( $content, $nl + 1 );
				}
			}
		}
		return array(
			'exists'     => true,
			'path'       => $rel,
			'size'       => $size,
			'size_human' => size_format( $size, 1 ),
			'truncated'  => $truncated,
			'content'    => $content,
		);
	}

	/**
	 * A plain-file source (debug log, PHP error log).
	 */
	private static function file_source( $label, $group, $path, $clearable ) {
		$src = array(
			'label' => $label,
			'group' => $group,
			'stat'  => function () use ( $path ) {
				$exists = file_exists( $path );
				return array(
					'exists'   => $exists,
					'size'     => $exists ? (int) filesize( $path ) : 0,
					'modified' => $exists ? (int) filemtime( $path ) : 0,
				);
			},
			'read'  => function () use ( $path ) {
				return self::tail_file( $path );
			},
		);
		if ( $clearable ) {
			$src['clear'] = function () use ( $path ) {
				if ( ! file_exists( $path ) ) {
					return true;
				}
				if ( ! wp_is_writable( $path ) ) {
					return new WP_Error( 'not_writable', 'This log is not writable.', array( 'status' => 400 ) );
				}
				$fh = @fopen( $path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( ! $fh ) {
					return new WP_Error( 'clear_failed', 'Could not clear the log.', array( 'status' => 500 ) );
				}
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				return true;
			};
		}
		return $src;
	}

	/**
	 * One WooCommerce channel. Reads the channel's newest file through WC's
	 * controller. get_files()'s source arg is a glob PREFIX ("plugin-woocommerce"
	 * also matches plugin-woocommerce-subscriptions files), so results are
	 * re-filtered on the exact source. Read-only: rotation and retention are
	 * WC's own job (Status → Logs).
	 */
	private static function wc_source( $fc, $channel ) {
		$newest = function () use ( $fc, $channel ) {
			$files = $fc->get_files( array( 'source' => $channel, 'per_page' => 100 ) );
			if ( is_wp_error( $files ) || ! is_array( $files ) ) {
				return null;
			}
			$files = array_filter( $files, function ( $f ) use ( $channel ) {
				return $f->get_source() === $channel;
			} );
			if ( ! $files ) {
				return null;
			}
			usort( $files, function ( $a, $b ) {
				return $b->get_modified_timestamp() - $a->get_modified_timestamp();
			} );
			return reset( $files );
		};
		return array(
			'label' => $channel,
			'group' => 'WooCommerce',
			'stat'  => function () use ( $newest ) {
				$f = $newest();
				return array(
					'exists'   => (bool) $f,
					'size'     => $f ? (int) $f->get_file_size() : 0,
					'modified' => $f ? (int) $f->get_modified_timestamp() : 0,
				);
			},
			'read'  => function () use ( $newest ) {
				$f = $newest();
				if ( ! $f ) {
					return array(
						'exists'  => false,
						'path'    => '',
						'content' => '',
						'size'    => 0,
					);
				}
				$payload         = self::tail_file( $f->get_path() );
				$payload['note'] = 'Newest file for this channel. Older files and retention live in WooCommerce → Status → Logs.';
				return $payload;
			},
		);
	}
}
