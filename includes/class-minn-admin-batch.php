<?php
/**
 * One update batch: plugins, themes and language packs, in that order, in a
 * single request, reported through one token-keyed progress record.
 *
 * Each section runs the path the command line takes (one bulk upgrader run
 * after one prefetch of its packages side by side) and appends its rows to
 * the same record, so the panel shows the whole batch walking down one list.
 * Items are keyed so the client can tell them apart without a lookup: plugin
 * rows by plugin file (the cards paint from those), theme rows as
 * "theme:<stylesheet>", language packs as "type|slug|locale"; every row
 * carries `kind`, and non-plugin rows carry their own `label`.
 *
 * The record is written twice per change: a transient for the REST reader,
 * and a file for progress.php, which answers while WordPress is in
 * maintenance mode (every REST request 503s then, for as long as an active
 * plugin or theme is being replaced).
 *
 * @package minn-admin
 */

defined( 'ABSPATH' ) || exit;

class Minn_Admin_Batch {

	/** @var array The progress record. */
	public $progress;

	private $token;
	private $file_path;
	private $last_write = 0.0;
	private $t0;

	public function __construct( $token ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		$this->token = (string) $token;
		$this->t0    = microtime( true );
		// Phases: check → fetch → install per section, done at the end.
		// States: queued, fetching, fetched, vendor (its own server downloads
		// it during install), unpacking, installing, done, failed.
		$this->progress  = array( 'known' => true, 'started' => time(), 'phase' => 'check', 'current' => '', 'done' => array(), 'failed' => array(), 'finished' => false, 'queue' => array(), 'items' => array(), 'timing' => array(), 'sections' => array() );
		$this->file_path = Minn_Admin_REST::progress_file_path( $this->token );
		if ( $this->file_path ) {
			$this->progress['fileUrl'] = plugins_url( 'progress.php', MINN_ADMIN_DIR . 'minn-admin.php' ) . '?t=' . $this->token;
		}
	}

	/** Persist the record; unforced writes are throttled to a few per second. */
	public function write( $force = true ) {
		if ( '' === $this->token ) {
			return;
		}
		if ( ! $force && microtime( true ) - $this->last_write < 0.25 ) {
			return;
		}
		$this->last_write = microtime( true );
		Minn_Admin_REST::progress_write( $this->token, $this->file_path, $this->progress );
	}

	/** Move one row to a state; done/failed also settle the batch lists. */
	public function mark( $key, $state, $error = '' ) {
		if ( ! isset( $this->progress['items'][ $key ] ) ) {
			return;
		}
		$this->progress['items'][ $key ]['state'] = $state;
		if ( '' !== $error ) {
			$this->progress['items'][ $key ]['error'] = $error;
		}
		if ( 'done' === $state || 'failed' === $state ) {
			if ( ! in_array( $key, $this->progress[ $state ], true ) ) {
				$this->progress[ $state ][] = $key;
			}
			$this->progress['current'] = '';
		} else {
			$this->progress['current'] = $key;
		}
		$this->write();
	}

	private function add_timing( $name, $t ) {
		$this->progress['timing'][ $name ] = (int) ( $this->progress['timing'][ $name ] ?? 0 ) + (int) round( ( microtime( true ) - $t ) * 1000 );
	}

	/** Close the record. */
	public function finish( $errors = array() ) {
		$this->progress['current']          = '';
		$this->progress['phase']            = 'done';
		$this->progress['finished']         = true;
		$this->progress['errors']           = array_values( array_unique( array_merge( (array) ( $this->progress['errors'] ?? array() ), (array) $errors ) ) );
		$this->progress['timing']['total_ms'] = (int) round( ( microtime( true ) - $this->t0 ) * 1000 );
		$this->write();
	}

	/**
	 * Every pending plugin, `wp plugin update --all` style: one update check,
	 * one prefetch, one Plugin_Upgrader bulk run. Rows keyed by plugin file.
	 *
	 * @return array { updated: string[], failed: string[], errors: string[] }
	 */
	public function run_plugins() {
		$t0 = microtime( true );
		wp_update_plugins();
		// real_plugin_updates: even freshly refreshed, never reinstall a
		// plugin whose installed version already satisfies the offer.
		$pending_before = Minn_Admin_REST::real_plugin_updates();
		$this->add_timing( 'check_ms', $t0 );
		if ( ! $pending_before ) {
			return array( 'updated' => array(), 'failed' => array(), 'errors' => array() );
		}
		$all   = function_exists( 'get_plugins' ) ? get_plugins() : array();
		$files = array_keys( $pending_before );
		$this->progress['sections'][] = 'plugins';
		$this->progress['queue']      = array_merge( $this->progress['queue'], $files );
		foreach ( $pending_before as $file => $data ) {
			$this->progress['items'][ $file ] = array(
				'kind'    => 'plugin',
				'state'   => 'queued',
				'bytes'   => 0,
				'version' => isset( $data->new_version ) ? (string) $data->new_version : '',
				'from'    => (string) ( $all[ $file ]['Version'] ?? '' ),
				'label'   => wp_strip_all_tags( (string) ( $all[ $file ]['Name'] ?? dirname( $file ) ) ),
			);
		}
		$this->progress['phase'] = 'fetch';
		$this->write();

		$t1    = microtime( true );
		$local = Minn_Admin_REST::prefetch_packages( $pending_before, function ( $file, $state, $bytes ) {
			$this->progress['items'][ $file ]['state'] = $state;
			$this->progress['items'][ $file ]['bytes'] = (int) $bytes;
			$this->write( 'fetching' !== $state );
		} );
		$this->add_timing( 'fetch_ms', $t1 );
		$this->progress['phase'] = 'install';
		$this->write();

		// Download is the first step of each plugin's run, so this is where
		// "current" moves; a prefetched package is handed over as the file.
		$pre_download = function ( $reply, $package, $upgrader, $hook_extra ) use ( $local ) {
			$file = ! empty( $hook_extra['plugin'] ) ? (string) $hook_extra['plugin'] : '';
			// This runs last, so any filter that already answered wins:
			// Minn's own updater returns the download it hash-verified
			// against the release manifest, or a WP_Error refusing the
			// package, and a vendor may fetch its own package its own way. Handing
			// the prefetched copy over here would install bytes that check
			// never saw, or ones it refused.
			if ( false !== $reply ) {
				if ( $file ) {
					$this->mark( $file, is_wp_error( $reply ) ? 'failed' : 'unpacking', is_wp_error( $reply ) ? $reply->get_error_message() : '' );
				}
				return $reply;
			}
			$have = isset( $local[ $package ] ) && is_file( $local[ $package ] ) && filesize( $local[ $package ] ) > 0;
			if ( $file ) {
				$this->mark( $file, $have ? 'unpacking' : 'fetching' );
			}
			return $have ? $local[ $package ] : $reply;
		};
		$pre_install  = function ( $return, $hook_extra ) {
			if ( ! empty( $hook_extra['plugin'] ) ) {
				$this->mark( (string) $hook_extra['plugin'], 'installing' );
			}
			return $return;
		};
		$post_install = function ( $return, $hook_extra ) {
			if ( ! empty( $hook_extra['plugin'] ) ) {
				$ok = $return && ! is_wp_error( $return );
				$this->mark( (string) $hook_extra['plugin'], $ok ? 'done' : 'failed', ( ! $ok && is_wp_error( $return ) ) ? $return->get_error_message() : '' );
			}
			return $return;
		};
		add_filter( 'upgrader_pre_download', $pre_download, PHP_INT_MAX, 4 );
		add_filter( 'upgrader_pre_install', $pre_install, 10, 2 );
		add_filter( 'upgrader_post_install', $post_install, 10, 2 );

		$t2       = microtime( true );
		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Plugin_Upgrader( $skin );
		$results  = $upgrader->bulk_upgrade( $files );
		$this->add_timing( 'install_ms', $t2 );

		remove_filter( 'upgrader_pre_download', $pre_download, PHP_INT_MAX );
		remove_filter( 'upgrader_pre_install', $pre_install, 10 );
		remove_filter( 'upgrader_post_install', $post_install, 10 );

		// Prefetched files the upgrader never consumed (a failure before
		// download) would otherwise sit in the temp dir.
		foreach ( (array) $local as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}

		$updated = array();
		$failed  = array();
		foreach ( (array) $results as $file => $result ) {
			if ( $result && ! is_wp_error( $result ) ) {
				$updated[] = $file;
			} else {
				$failed[] = $file;
			}
		}
		// Restore offers for anything that failed (or was not in the result set).
		Minn_Admin_REST::restore_plugin_update_offers( $pending_before, $updated );

		// A plugin that failed before its install hooks ran (no package to
		// download) never got a state from them.
		$msgs = $skin->get_error_messages();
		foreach ( $files as $f ) {
			$is_ok = in_array( $f, $updated, true );
			$state = $this->progress['items'][ $f ]['state'];
			if ( $is_ok && 'done' !== $state ) {
				$this->mark( $f, 'done' );
			} elseif ( ! $is_ok && 'failed' !== $state ) {
				$r = isset( $results[ $f ] ) ? $results[ $f ] : null;
				$this->mark( $f, 'failed', is_wp_error( $r ) ? $r->get_error_message() : ( $msgs ? (string) end( $msgs ) : '' ) );
			}
		}
		$this->progress['errors'] = array_merge( (array) ( $this->progress['errors'] ?? array() ), (array) $msgs );
		$this->write();
		return array( 'updated' => $updated, 'failed' => $failed, 'errors' => (array) $msgs );
	}

	/**
	 * Every pending theme: one prefetch, one Theme_Upgrader bulk run. Rows
	 * keyed "theme:<stylesheet>". Theme_Upgrader adds its own pre/post-install
	 * filters (current_before / current_after) alongside these, which is
	 * fine; it only removes its own afterwards.
	 *
	 * @return array { updated: string[], failed: string[], errors: string[] }
	 */
	public function run_themes() {
		$t0 = microtime( true );
		wp_update_themes();
		$pending_before = Minn_Admin_REST::real_theme_updates();
		$this->add_timing( 'check_ms', $t0 );
		if ( ! $pending_before ) {
			return array( 'updated' => array(), 'failed' => array(), 'errors' => array() );
		}
		$sheets  = array_keys( $pending_before );
		$key_of  = function ( $stylesheet ) {
			return 'theme:' . $stylesheet;
		};
		$objects = array();
		$this->progress['sections'][] = 'themes';
		foreach ( $pending_before as $stylesheet => $data ) {
			$theme = wp_get_theme( $stylesheet );
			$objects[ $key_of( $stylesheet ) ] = (object) $data;
			$this->progress['queue'][]         = $key_of( $stylesheet );
			$this->progress['items'][ $key_of( $stylesheet ) ] = array(
				'kind'    => 'theme',
				'state'   => 'queued',
				'bytes'   => 0,
				'version' => is_array( $data ) ? (string) ( $data['new_version'] ?? '' ) : '',
				'from'    => (string) $theme->get( 'Version' ),
				'label'   => wp_strip_all_tags( (string) $theme->get( 'Name' ) ) ?: $stylesheet,
			);
		}
		$this->progress['phase'] = 'fetch';
		$this->write();

		$t1    = microtime( true );
		$local = Minn_Admin_REST::prefetch_packages( $objects, function ( $key, $state, $bytes ) {
			$this->progress['items'][ $key ]['state'] = $state;
			$this->progress['items'][ $key ]['bytes'] = (int) $bytes;
			$this->write( 'fetching' !== $state );
		} );
		$this->add_timing( 'fetch_ms', $t1 );
		$this->progress['phase'] = 'install';
		$this->write();

		$pre_download = function ( $reply, $package, $upgrader, $hook_extra ) use ( $local, $key_of ) {
			$key = ! empty( $hook_extra['theme'] ) ? $key_of( (string) $hook_extra['theme'] ) : '';
			if ( false !== $reply ) {
				if ( $key ) {
					$this->mark( $key, is_wp_error( $reply ) ? 'failed' : 'unpacking', is_wp_error( $reply ) ? $reply->get_error_message() : '' );
				}
				return $reply;
			}
			$have = isset( $local[ $package ] ) && is_file( $local[ $package ] ) && filesize( $local[ $package ] ) > 0;
			if ( $key ) {
				$this->mark( $key, $have ? 'unpacking' : 'fetching' );
			}
			return $have ? $local[ $package ] : $reply;
		};
		$pre_install  = function ( $return, $hook_extra ) use ( $key_of ) {
			if ( ! empty( $hook_extra['theme'] ) ) {
				$this->mark( $key_of( (string) $hook_extra['theme'] ), 'installing' );
			}
			return $return;
		};
		$post_install = function ( $return, $hook_extra ) use ( $key_of ) {
			if ( ! empty( $hook_extra['theme'] ) ) {
				$ok = $return && ! is_wp_error( $return );
				$this->mark( $key_of( (string) $hook_extra['theme'] ), $ok ? 'done' : 'failed', ( ! $ok && is_wp_error( $return ) ) ? $return->get_error_message() : '' );
			}
			return $return;
		};
		add_filter( 'upgrader_pre_download', $pre_download, PHP_INT_MAX, 4 );
		add_filter( 'upgrader_pre_install', $pre_install, 10, 2 );
		add_filter( 'upgrader_post_install', $post_install, 10, 2 );

		$t2       = microtime( true );
		$skin     = new WP_Ajax_Upgrader_Skin();
		$upgrader = new Theme_Upgrader( $skin );
		$results  = $upgrader->bulk_upgrade( $sheets );
		$this->add_timing( 'install_ms', $t2 );

		remove_filter( 'upgrader_pre_download', $pre_download, PHP_INT_MAX );
		remove_filter( 'upgrader_pre_install', $pre_install, 10 );
		remove_filter( 'upgrader_post_install', $post_install, 10 );

		foreach ( (array) $local as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}

		$updated = array();
		$failed  = array();
		foreach ( (array) $results as $stylesheet => $result ) {
			if ( $result && ! is_wp_error( $result ) ) {
				$updated[] = $stylesheet;
			} else {
				$failed[] = $stylesheet;
			}
		}
		Minn_Admin_REST::restore_theme_update_offers( $pending_before, $updated );

		$msgs = $skin->get_error_messages();
		foreach ( $sheets as $s ) {
			$is_ok = in_array( $s, $updated, true );
			$state = $this->progress['items'][ $key_of( $s ) ]['state'];
			if ( $is_ok && 'done' !== $state ) {
				$this->mark( $key_of( $s ), 'done' );
			} elseif ( ! $is_ok && 'failed' !== $state ) {
				$r = isset( $results[ $s ] ) ? $results[ $s ] : null;
				$this->mark( $key_of( $s ), 'failed', is_wp_error( $r ) ? $r->get_error_message() : ( $msgs ? (string) end( $msgs ) : '' ) );
			}
		}
		$this->progress['errors'] = array_merge( (array) ( $this->progress['errors'] ?? array() ), (array) $msgs );
		$this->write();
		return array( 'updated' => $updated, 'failed' => $failed, 'errors' => (array) $msgs );
	}

	/**
	 * Every pending language pack: one prefetch, one Language_Pack_Upgrader
	 * bulk run, in the order the panel lists (language, then component).
	 *
	 * Language_Pack_Upgrader::bulk_upgrade() removes every upgrader_pre_install
	 * / post_install filter before it runs (core #29425), so per-pack install
	 * states come from the skin instead: run() feeds it unpack_package,
	 * installing_package and process_success/failed for each pack, with the
	 * pack itself on $skin->language_update.
	 *
	 * @return array { updated: int, failed: int, remaining: int, groups: array, errors: string[] }
	 */
	public function run_translations() {
		$t0 = microtime( true );
		// wordpress.org language packs are listed inside the plugin and theme
		// update data, and the plugins and themes sections before this one
		// replace that data when they finish: the upgraders clear it and the
		// re-seeded offers carry no pack list. Read then, every wordpress.org
		// pack would look up to date and only vendor packs (added through a
		// filter on every read) would install. Both checks skip the network
		// unless something changed since the last one, which after an update
		// section it has.
		wp_update_plugins();
		wp_update_themes();
		// The same pending set the summary counts (packs for languages this
		// site still has), keyed and labeled for the record.
		$summary = Minn_Admin_REST::translation_update_summary();
		$labels  = array();
		foreach ( $summary['groups'] as $group ) {
			foreach ( (array) ( $group['components'] ?? array() ) as $c ) {
				$labels[ $c['type'] . '|' . $c['slug'] . '|' . $group['locale'] ] = array( 'component' => $c['name'], 'language' => $group['name'], 'version' => $c['version'] );
			}
		}
		$key_of  = function ( $update ) {
			$update = (object) $update;
			$type   = isset( $update->type ) ? (string) $update->type : '';
			$slug   = isset( $update->slug ) ? sanitize_key( (string) $update->slug ) : '';
			if ( 'core' === $type && '' === $slug ) {
				$slug = 'wordpress';
			}
			$locale = isset( $update->language ) ? preg_replace( '/[^A-Za-z0-9_@.-]/', '', (string) $update->language ) : '';
			return $type . '|' . $slug . '|' . $locale;
		};
		$pending = array();
		foreach ( (array) wp_get_translation_updates() as $update ) {
			$key = $key_of( $update );
			if ( isset( $labels[ $key ] ) ) {
				$pending[ $key ] = (object) $update;
			}
		}
		$this->add_timing( 'check_ms', $t0 );
		if ( ! $pending ) {
			return array( 'updated' => 0, 'failed' => 0, 'remaining' => 0, 'groups' => $summary['groups'], 'errors' => array() );
		}
		// Install in the order the panel lists: by language, then component,
		// so the ticks walk down the list instead of landing wherever the
		// update transient happened to put each pack.
		uksort( $pending, function ( $a, $b ) use ( $labels ) {
			$c = strcasecmp( $labels[ $a ]['language'], $labels[ $b ]['language'] );
			if ( 0 !== $c ) {
				return $c;
			}
			$c = strcasecmp( $labels[ $a ]['component'], $labels[ $b ]['component'] );
			return 0 !== $c ? $c : strcmp( $a, $b );
		} );
		$this->progress['sections'][] = 'translations';
		$this->progress['queue']      = array_merge( $this->progress['queue'], array_keys( $pending ) );
		foreach ( $pending as $key => $update ) {
			$this->progress['items'][ $key ] = array(
				'kind'    => 'translation',
				'state'   => 'queued',
				'bytes'   => 0,
				'version' => $labels[ $key ]['version'],
				'label'   => $labels[ $key ]['component'] . ' · ' . $labels[ $key ]['language'],
			);
		}
		$this->progress['phase'] = 'fetch';
		$this->write();

		$t1    = microtime( true );
		$local = Minn_Admin_REST::prefetch_packages( $pending, function ( $key, $state, $bytes ) {
			$this->progress['items'][ $key ]['state'] = $state;
			$this->progress['items'][ $key ]['bytes'] = (int) $bytes;
			$this->write( 'fetching' !== $state );
		} );
		$this->add_timing( 'fetch_ms', $t1 );
		$this->progress['phase'] = 'install';
		$this->write();

		// Download is the first step of each pack's run; a prefetched package
		// is handed over as the file. This filter survives the upgrader's
		// remove_all_filters sweep (it only clears install ones).
		$pre_download = function ( $reply, $package, $upgrader, $hook_extra ) use ( $local, $key_of ) {
			if ( empty( $hook_extra['language_update'] ) ) {
				return $reply;
			}
			$key = $key_of( $hook_extra['language_update'] );
			if ( false !== $reply ) {
				$this->mark( $key, is_wp_error( $reply ) ? 'failed' : 'unpacking', is_wp_error( $reply ) ? $reply->get_error_message() : '' );
				return $reply;
			}
			$have = isset( $local[ $package ] ) && is_file( $local[ $package ] ) && filesize( $local[ $package ] ) > 0;
			$this->mark( $key, $have ? 'unpacking' : 'fetching' );
			return $have ? $local[ $package ] : $reply;
		};
		add_filter( 'upgrader_pre_download', $pre_download, PHP_INT_MAX, 4 );

		if ( ! class_exists( 'Minn_Admin_Translation_Skin' ) ) {
			require_once MINN_ADMIN_DIR . 'includes/class-minn-admin-translation-skin.php';
		}
		$skin           = new Minn_Admin_Translation_Skin();
		$skin->on_state = function ( $update, $state, $error = '' ) use ( $key_of ) {
			$this->mark( $key_of( $update ), $state, $error );
		};
		$t2       = microtime( true );
		$upgrader = new Language_Pack_Upgrader( $skin );
		$result   = $upgrader->bulk_upgrade( array_values( $pending ), array( 'clear_update_cache' => true ) );
		$this->add_timing( 'install_ms', $t2 );
		remove_filter( 'upgrader_pre_download', $pre_download, PHP_INT_MAX );

		foreach ( (array) $local as $path ) {
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
		}

		// Packs the skin never settled (a filesystem refusal before the
		// first run, or a break after a failure) read as failed with the
		// upgrader's own message.
		$msgs = $skin->get_error_messages();
		$done = 0;
		$fail = 0;
		foreach ( $pending as $key => $update ) {
			$state = $this->progress['items'][ $key ]['state'];
			if ( 'done' === $state ) {
				++$done;
			} elseif ( 'failed' === $state ) {
				++$fail;
			} else {
				++$fail;
				$this->mark( $key, 'failed', $msgs ? (string) end( $msgs ) : ( is_wp_error( $result ) ? $result->get_error_message() : __( 'not confirmed', 'minn-admin' ) ) );
			}
		}
		$this->progress['errors'] = array_merge( (array) ( $this->progress['errors'] ?? array() ), (array) $msgs );
		$this->write();

		// Re-read rather than subtract: a pack that failed is still pending.
		$after = Minn_Admin_REST::translation_update_summary();
		return array( 'updated' => $done, 'failed' => $fail, 'remaining' => $after['count'], 'groups' => $after['groups'], 'errors' => (array) $msgs, 'result' => $result );
	}
}
