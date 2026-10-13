<?php
/**
 * Backups: a few weeks of history in each backup plugin, each with its own
 * job at the shop. UpdraftPlus does the nightly database and the weekly
 * full site; WPvivid holds June's snapshots before risky changes (one
 * locked); BackWPup runs two jobs started by the host's cron through their
 * job links; All-in-One WP Migration has the exports made for the staging
 * site; Duplicator has packages, one failed this morning and built again;
 * WP Migrate has database dumps, some made before a migration; Disembark
 * has two scan sessions left on disk. No backup runs and nothing leaves the
 * site: every remote destination stays unconfigured.
 *
 * What each plugin keeps and what Minn reads:
 * - UpdraftPlus: option updraft_backup_history (time => set, the sizes as
 *   "{entity}-size" keys) and updraft_last_backup. Minn shows the recorded
 *   sizes, so the files on disk are tiny stand-ins for the download door.
 * - WPvivid: option wpvivid_backup_list (task id => set, sizes in
 *   backup.files). Its schedule only reads as on while the real cron event
 *   wpvivid_main_schedule_event exists, and that event runs a real backup,
 *   so the schedule stays off and its sets are manual ones (plus two old
 *   cron sets from before June moved the nightly run to UpdraftPlus).
 *   backup_files on a set names its contents: without it Minn guesses from
 *   the file name, and a merged "_backup_all" set would read as Files only.
 * - BackWPup: jobs live in the site option backwpup_jobs; Minn lists every
 *   archive in a FOLDER job's directory with the file's real size and mtime.
 *   A wpcron job is put back on the schedule by BackWPup's own cleanup cron
 *   and would run, so these jobs start by link. lastrun is site-local time.
 * - All-in-One WP Migration, WP Migrate, Disembark: files on disk, real size
 *   and mtime (labels in ai1wm_backups_labels, scan totals in
 *   disembark_last_scan_stats). Duplicator 5: rows in duplicator_backups,
 *   built with its own DupPackage and save() so the row hydrates; the size
 *   is the archive file's. Its cron deletes failed rows six hours after they
 *   were made, so the failed build is from this morning.
 * Those real sizes are small: every file here is a placeholder (the whole
 * part writes about 4 MB).
 *
 * @package minn-admin
 */

return function ( Minn_Demo_Seed $demo ) {
	$content = trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
	$rel     = function ( $abs ) use ( $content ) {
		$abs = wp_normalize_path( $abs );
		return 0 === strpos( $abs, $content ) ? substr( $abs, strlen( $content ) ) : '';
	};

	// What an earlier run made goes first, from the ids and paths it kept.
	if ( class_exists( 'UpdraftPlus_Backup_History' ) && $demo->ids( 'backups-updraft' ) ) {
		$history = UpdraftPlus_Backup_History::get_history();
		foreach ( $demo->ids( 'backups-updraft' ) as $ts ) {
			unset( $history[ $ts ] );
		}
		UpdraftPlus_Backup_History::save_history( $history );
	}
	if ( class_exists( 'WPvivid_Setting' ) && $demo->ids( 'backups-wpvivid' ) ) {
		$list = WPvivid_Setting::get_option( 'wpvivid_backup_list' );
		$list = is_array( $list ) ? $list : array();
		foreach ( $demo->ids( 'backups-wpvivid' ) as $id ) {
			unset( $list[ $id ] );
		}
		WPvivid_Setting::update_option( 'wpvivid_backup_list', $list );
	}
	if ( class_exists( 'BackWPup_Option' ) ) {
		foreach ( $demo->ids( 'backups-backwpup' ) as $id ) {
			BackWPup_Option::delete_job( $id );
		}
	}
	if ( class_exists( '\Duplicator\Package\DupPackage' ) ) {
		foreach ( $demo->ids( 'backups-duplicator' ) as $id ) {
			\Duplicator\Package\DupPackage::forceDelete( (int) $id );
		}
	}
	if ( class_exists( 'Ai1wm_Backups' ) && $demo->ids( 'backups-ai1wm' ) ) {
		Ai1wm_Backups::delete_labels( array_values( $demo->ids( 'backups-ai1wm' ) ) );
	}
	foreach ( $demo->ids( 'backups-files' ) as $file ) {
		if ( '' !== $file && false === strpos( $file, '..' ) && is_file( $content . $file ) ) {
			unlink( $content . $file );
		}
	}
	$dirs = array_values( $demo->ids( 'backups-dirs' ) );
	usort(
		$dirs,
		function ( $a, $b ) {
			return strlen( $b ) - strlen( $a );
		}
	);
	foreach ( $dirs as $dir ) {
		if ( '' !== $dir && false === strpos( $dir, '..' ) && is_dir( $content . $dir ) ) {
			@rmdir( $content . $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- only when empty.
		}
	}
	foreach ( array( 'backups-updraft', 'backups-wpvivid', 'backups-backwpup', 'backups-duplicator', 'backups-ai1wm', 'backups-files', 'backups-dirs' ) as $group ) {
		$demo->forget( $group );
	}

	$made_files = array();
	$made_dirs  = array();
	$ensure_dir = function ( $abs ) use ( &$made_dirs, $content, $rel ) {
		$missing = array();
		$dir     = untrailingslashit( wp_normalize_path( $abs ) );
		while ( ! is_dir( $dir ) && strlen( $dir ) > strlen( $content ) ) {
			$missing[] = $dir;
			$dir       = dirname( $dir );
		}
		foreach ( array_reverse( $missing ) as $dir ) {
			mkdir( $dir, 0755 );
			$made_dirs[] = $rel( $dir );
		}
	};
	// A stand-in archive of $bytes (or the $data given), dated $mtime. The
	// filler is md5 output, so it neither compresses nor changes between runs.
	$write = function ( $abs, $bytes, $mtime, $data = null ) use ( &$made_files, $ensure_dir, $rel ) {
		$ensure_dir( dirname( $abs ) );
		if ( null === $data ) {
			$data = "Larkspur & Fern demo placeholder. Not a real backup.\n";
			$seed = basename( $abs );
			for ( $i = 0; strlen( $data ) < $bytes; $i++ ) {
				$data .= md5( $seed . ':' . $i, true );
			}
			$data = substr( $data, 0, $bytes );
			if ( '.gz' === substr( $abs, -3 ) ) {
				$data = gzencode( $data, 0 );
			}
		}
		file_put_contents( $abs, $data );
		touch( $abs, $mtime );
		$made_files[] = $rel( $abs );
		return strlen( $data );
	};
	// The most recent $hour:$minute that has passed, $days_back days earlier.
	$recent = function ( $days_back, $hour, $minute = 0 ) use ( $demo ) {
		$first = $demo->ago( 0, $hour, $minute ) <= $demo->now - 10 * MINUTE_IN_SECONDS ? 0 : 1;
		return $demo->ago( $first + $days_back, $hour, $minute );
	};
	// A steady number that wobbles a little, the same on every run.
	$about = function ( $base, $seed, $spread ) {
		return (int) ( $base + hexdec( substr( md5( $seed ), 0, 6 ) ) % max( 1, $spread ) );
	};
	$blog    = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$host    = (string) wp_parse_url( home_url(), PHP_URL_HOST );
	$summary = array();

	/*
	 * UpdraftPlus: the nightly database at 2:00, with files on the weekly
	 * run, and one labelled manual backup before an update. Retention keeps
	 * ten database sets and four file sets.
	 */
	if ( class_exists( 'UpdraftPlus_Backup_History' ) && class_exists( 'UpdraftPlus_Options' ) ) {
		global $updraftplus;
		$dir  = trailingslashit( $updraftplus->backups_dir_location() );
		$site = str_replace( '__', '_', preg_replace( '/[^A-Za-z0-9_]/', '', str_replace( ' ', '_', substr( $blog, 0, 32 ) ) ) );
		$runs = array();
		// Files go with the Sunday run.
		$sunday = (int) wp_date( 'w', $recent( 0, 2, 0 ) );
		foreach ( array_unique( array_merge( range( 0, 9 ), array( $sunday, $sunday + 7, $sunday + 14, $sunday + 21 ) ) ) as $n ) {
			$runs[] = array( $recent( $n, 2, 0 ), $sunday === $n % 7, '' );
		}
		$runs[]   = array( $demo->ago( 6, 16, 20 ), true, 'Before the WooCommerce update' );
		$history  = UpdraftPlus_Backup_History::get_history();
		$newest   = null;
		$sizes    = array(
			'plugins' => 31400000,
			'themes'  => 4630000,
			'uploads' => 12100000,
			'others'  => 870000,
		);
		foreach ( $runs as $run ) {
			list( $ts, $full, $label ) = $run;
			$days  = ( $demo->now - $ts ) / DAY_IN_SECONDS;
			$nonce = substr( md5( 'updraft-' . $ts ), 0, 12 );
			$base  = 'backup_' . wp_date( 'Y-m-d-Hi', $ts ) . '_' . $site . '_' . $nonce;
			$set   = array(
				'db'      => $base . '-db.gz',
				'db-size' => $about( 6240000 - (int) ( $days * 9100 ), 'db' . $ts, 60000 ),
			);
			$write( $dir . $set['db'], $about( 1400, 'f' . $ts, 600 ), $ts + 70 );
			if ( $full ) {
				$at = array();
				foreach ( $sizes as $entity => $bytes ) {
					$name                     = $base . '-' . $entity . '.zip';
					$set[ $entity ]           = array( $name );
					$set[ $entity . '-size' ] = $about( 'uploads' === $entity ? $bytes - (int) ( $days * 52000 ) : $bytes, $entity . $ts, (int) ( $bytes / 40 ) );
					$at[ $entity ]            = $ts + 20;
					$write( $dir . $name, $about( 1400, $entity . $ts, 600 ), $ts + 240 );
				}
				$set['files_enumerated_at'] = $at;
			}
			$set += array(
				'nonce'                => $nonce,
				'service'              => array( 'none' ),
				'service_instance_ids' => array(),
				'always_keep'          => false,
				'created_by_version'   => isset( $updraftplus->version ) ? $updraftplus->version : '',
				'is_multisite'         => false,
			);
			if ( $label ) {
				$set['label'] = $label;
			}
			$history[ $ts ] = $set;
			$demo->remember( 'backups-updraft', (string) $ts, $ts );
			if ( ! $newest || $ts > $newest[0] ) {
				$newest = array( $ts, $set );
			}
		}
		UpdraftPlus_Backup_History::save_history( $history );
		UpdraftPlus_Options::update_updraft_option(
			'updraft_last_backup',
			array(
				'nonincremental_backup_time' => $newest[0],
				'backup_time'                => $newest[0],
				'backup_array'               => $newest[1],
				'success'                    => 1,
				'errors'                     => array(),
				'backup_nonce'               => $newest[1]['nonce'],
			),
			false
		);
		$summary[] = count( $runs ) . ' UpdraftPlus sets';
	}

	/*
	 * WPvivid: snapshots June takes before a change, plus the last two runs
	 * of the schedule it had before UpdraftPlus took over the nights.
	 */
	if ( class_exists( 'WPvivid_Setting' ) && class_exists( 'WPvivid_Backuplist' ) ) {
		$list   = WPvivid_Setting::get_option( 'wpvivid_backup_list' );
		$list   = is_array( $list ) ? $list : array();
		$folder = WPvivid_Setting::get_backupdir();
		$common = WPvivid_Setting::get_option( 'wpvivid_common_setting' );
		$path   = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		$prefix = $host . str_replace( '/', '_', $path );
		// days ago, hour, minute, type, contents, bytes, locked
		$sets = array(
			array( 1, 16, 40, 'Manual', 'files+db', 54800000, false ),
			array( 6, 9, 15, 'Manual', 'db', 6190000, false ),
			array( 13, 18, 5, 'Manual', 'files+db', 53900000, true ),
			array( 27, 11, 30, 'Manual', 'db', 5990000, false ),
			array( 44, 3, 0, 'Cron', 'files+db', 51200000, false ),
			array( 51, 3, 0, 'Cron', 'files+db', 50700000, false ),
		);
		foreach ( $sets as $i => $s ) {
			list( $days, $hour, $minute, $type, $files, $bytes, $locked ) = $s;
			$ts   = $demo->ago( $days, $hour, $minute );
			$id   = 'wpvivid-' . substr( md5( 'wpvivid-' . $i ), 0, 13 );
			$name = $prefix . '_' . $id . '_' . wp_date( 'Y-m-d-H-i', $ts ) . ( 'db' === $files ? '_backup_db.zip' : '_backup_all.zip' );
			$set  = array(
				'type'          => $type,
				'create_time'   => $ts,
				'manual_delete' => 0,
				'local'         => array( 'path' => $folder ),
				'compress'      => array( 'compress_type' => 'zip' ),
				'save_local'    => 1,
				'backup_prefix' => $prefix,
				'log'           => WP_CONTENT_DIR . '/' . $folder . '/wpvivid_log/' . $id . '_backup_log.txt',
				'backup'        => array(
					'result' => 'success',
					'files'  => array(
						array(
							'file_name' => $name,
							'size'      => $about( $bytes, $id, (int) ( $bytes / 50 ) ),
						),
					),
				),
				'remote'        => array(),
				'backup_files'  => $files,
			);
			if ( $locked ) {
				$set['lock'] = 1;
			}
			$list[ $id ] = $set;
			$write( WP_CONTENT_DIR . '/' . $folder . '/' . $name, $about( 1500, $name, 700 ), $ts + 300 );
			$demo->remember( 'backups-wpvivid', $id, $id );
		}
		WPvivid_Setting::update_option( 'wpvivid_backup_list', $list );
		if ( is_array( $common ) ) {
			// Their retention counts unlocked sets; the default three would prune these on the next run.
			$common['max_backup_count'] = 7;
			WPvivid_Setting::update_option( 'wpvivid_common_setting', $common );
		}
		$summary[] = count( $sets ) . ' WPvivid sets';
	}

	/*
	 * BackWPup: two jobs to the local folder, each in its own directory (the
	 * folder lists every archive in it, whichever job wrote it).
	 */
	if ( class_exists( 'BackWPup_Option' ) && class_exists( 'BackWPup' ) ) {
		$sunday = (int) wp_date( 'w', $recent( 0, 3, 30 ) );
		$hash   = (string) BackWPup::get_plugin_data( 'hash' );
		$upload = wp_upload_dir( null, false );
		$jobs   = array(
			'nightly' => array(
				'name'      => 'Nightly database',
				'type'      => array( 'DBDUMP' ),
				'cron'      => '0 2 * * *',
				'frequency' => 'daily',
				'dir'       => 'nightly-database',
				'keep'      => 14,
				'runs'      => array( 0, 1, 2, 3, 4, 5, 6, 7 ),
				'hour'      => 2,
				'minute'    => 15,
				'bytes'     => 98000,
				'runtime'   => 41,
			),
			'weekly'  => array(
				'name'      => 'Weekly full site',
				'type'      => array( 'FILE', 'DBDUMP', 'WPPLUGIN' ),
				'cron'      => '30 3 * * 0',
				'frequency' => 'weekly',
				'dir'       => 'weekly-site',
				'keep'      => 4,
				'runs'      => array( $sunday, $sunday + 7, $sunday + 14 ),
				'hour'      => 3,
				'minute'    => 30,
				'bytes'     => 215000,
				'runtime'   => 388,
			),
		);
		$count = 0;
		foreach ( $jobs as $key => $j ) {
			$id     = BackWPup_Option::next_job_id();
			$folder = 'uploads/backwpup/' . $hash . '/' . $j['dir'] . '/';
			$last   = $recent( $j['runs'][0], $j['hour'], $j['minute'] );
			$job    = array_merge(
				BackWPup_Option::defaults_job(),
				array(
					'jobid'                 => $id,
					'name'                  => $j['name'],
					'type'                  => $j['type'],
					'destinations'          => array( 'FOLDER' ),
					// Started by the host's cron through the job link, never by WP-Cron.
					'activetype'            => 'link',
					'cronselect'            => 'basic',
					'cron'                  => $j['cron'],
					'frequency'             => $j['frequency'],
					'mailaddresslog'        => 'june@larkspur.example',
					'mailaddresssenderlog'  => 'BackWPup ' . $blog . ' <hello@larkspur.example>',
					'archiveformat'         => '.tar.gz',
					'dbdumpfilecompression' => '.gz',
					'backupdir'             => $folder,
					'maxbackups'            => $j['keep'],
					'tempjob'               => false,
					// Their own clock: current_time( 'timestamp' ), site-local.
					'lastrun'               => $last + (int) ( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ),
					'lastruntime'           => $j['runtime'],
					'lastrunerrors'         => 0,
				)
			);
			foreach ( $job as $option => $value ) {
				BackWPup_Option::update( $id, $option, $value );
			}
			$demo->remember( 'backups-backwpup', $key, $id );
			foreach ( $j['runs'] as $n ) {
				$ts   = $recent( $n, $j['hour'], $j['minute'] );
				$code = \Base32\Base32::encode( pack( 'H*', substr( md5( $key . $ts ), 0, 2 ) . str_pad( $hash, 6, '0', STR_PAD_LEFT ) . substr( md5( $key . $ts ), 2, 2 ) ) ) . sprintf( '%02d', $id );
				$name = wp_date( 'Y-m-d_H-i-s', $ts + $j['runtime'] ) . '_' . $code . '.tar.gz';
				$write( trailingslashit( $upload['basedir'] ) . 'backwpup/' . $hash . '/' . $j['dir'] . '/' . $name, $about( $j['bytes'] - $n * 400, $name, (int) ( $j['bytes'] / 12 ) ), $ts + $j['runtime'] );
				++$count;
			}
		}
		$summary[] = $count . ' BackWPup archives';
	}

	/*
	 * All-in-One WP Migration: exports for the staging site and before
	 * updates. Named as with AI1WM_KEEP_DOMAIN_NAME (the host keeps its
	 * dots), so the recorder's host scrub reaches the file names too.
	 */
	if ( class_exists( 'Ai1wm_Backups' ) && defined( 'AI1WM_BACKUPS_PATH' ) ) {
		// days ago, hour, minute, label, bytes
		$exports = array(
			array( 2, 11, 15, 'Copy for the staging site', 262000 ),
			array( 16, 8, 50, 'Before the theme update', 241000 ),
			array( 30, 17, 5, 'Before WordPress 7.1', 228000 ),
			array( 48, 14, 20, '', 196000 ),
		);
		foreach ( $exports as $i => $e ) {
			list( $days, $hour, $minute, $label, $bytes ) = $e;
			$ts   = $demo->ago( $days, $hour, $minute );
			$name = $host . '-' . wp_date( 'Ymd', $ts ) . '-' . wp_date( 'His', $ts ) . '-' . substr( md5( 'ai1wm-' . $i ), 0, 12 ) . '.wpress';
			$write( trailingslashit( AI1WM_BACKUPS_PATH ) . $name, $about( $bytes, $name, 9000 ), $ts + 95 );
			if ( $label ) {
				Ai1wm_Backups::set_label( $name, $label );
			}
			$demo->remember( 'backups-ai1wm', (string) $i, $name );
		}
		$summary[] = count( $exports ) . ' exports';
	}

	/*
	 * Duplicator 5: packages made by their own class and save(), dated by
	 * setting the fields their constructor stamps with "now" (no setters).
	 */
	if ( class_exists( '\Duplicator\Package\DupPackage' ) && class_exists( '\Duplicator\Package\NameFormat' ) && defined( 'DUPLICATOR_SSDIR_PATH' ) ) {
		$abstract  = 'Duplicator\Package\AbstractPackage';
		$installer = 'Duplicator\Package\Create\PackInstaller';
		// days ago, hour, minute, status, database only, notes, archive bytes
		$packages = array(
			array( 47, 10, 20, 'done', false, 'Before the shop redesign', 252000 ),
			array( 19, 15, 45, 'done', true, 'Before importing the fall bulb catalog', 118000 ),
			array( 0.125, null, null, 'error', false, 'Full copy for the staging site', 0 ),
			array( 0.09, null, null, 'done', false, 'Full copy for the staging site, second try', 268000 ),
		);
		foreach ( $packages as $i => $p ) {
			list( $days, $hour, $minute, $state, $db_only, $notes, $bytes ) = $p;
			$ts      = null === $hour ? $demo->now - (int) ( $days * DAY_IN_SECONDS ) : $demo->ago( $days, $hour, $minute );
			$package = new \Duplicator\Package\DupPackage();
			$format  = new \Duplicator\Package\NameFormat();
			$format->setTimestamp( $ts );
			$seed = md5( 'duplicator-' . $i . '-' . $ts );
			$set  = Closure::bind(
				function ( $field, $value ) {
					$this->$field = $value;
				},
				$package,
				$abstract
			);
			$set( 'created', gmdate( 'Y-m-d H:i:s', $ts ) );
			$set( 'name', $format->getName() );
			// Their makeHash(): 16 hex, four digits, the UTC build time.
			$set( 'hash', substr( $seed, 0, 16 ) . ( 1000 + hexdec( substr( $seed, 16, 4 ) ) % 9000 ) . '_' . gmdate( 'YmdHis', $ts ) );
			$set( 'status', 'done' === $state ? $abstract::STATUS_COMPLETE : $abstract::STATUS_ERROR );
			$package->Archive->setFormatFromEngine( \Duplicator\Models\GlobalEntity::getInstance()->getBuildMode() );
			Closure::bind(
				function ( $file ) {
					$this->File = $file;
				},
				$package->Installer,
				$installer
			)( $package->getNameHash() . '_installer.php' );
			if ( $db_only ) {
				$package->components = array( 'package_component_db' );
			}
			$package->notes = $notes;
			if ( 'done' === $state ) {
				$zip                = $write( DUPLICATOR_SSDIR_PATH . '/' . $package->getArchiveFilename(), $about( $bytes, $seed, 8000 ), $ts + 75 );
				$exe                = $write( DUPLICATOR_SSDIR_PATH . '/' . $package->Installer->getInstallerLocalName(), $about( 6200, 'i' . $seed, 400 ), $ts + 80 );
				$package->ZipSize   = (string) $zip;
				$package->ExeSize   = (string) $exe;
				$package->Runtime   = ( $db_only ? 9 : 74 ) + $i . '.' . ( $i * 3 ) . ' sec.';
			}
			$package->save( false );
			$demo->remember( 'backups-duplicator', (string) $i, $package->getId() );
		}
		$summary[] = count( $packages ) . ' Duplicator packages';
	}

	// WP Migrate: database dumps, the "-migrate-" ones taken before a migration replaced the tables.
	if ( class_exists( '\DeliciousBrains\WPMDB\WPMDBDI' ) && class_exists( '\DeliciousBrains\WPMDB\Common\Filesystem\Filesystem' ) ) {
		$fs  = \DeliciousBrains\WPMDB\WPMDBDI::getInstance()->get( \DeliciousBrains\WPMDB\Common\Filesystem\Filesystem::class );
		$dir = trailingslashit( (string) $fs->get_upload_info( 'path' ) );
		// Their dump name is the site title, lower case, letters and digits only.
		$stem = preg_replace( '/[^A-Za-z0-9]/', '', strtolower( preg_replace( '/\s+/', '', $blog ) ) );
		// days ago, hour, minute, kind, bytes
		$dumps = array(
			array( 1, 10, 5, 'migrate', 131000 ),
			array( 8, 14, 50, 'backup', 127000 ),
			array( 15, 9, 30, 'migrate', 124000 ),
			array( 29, 16, 10, 'backup', 119000 ),
			array( 46, 11, 0, 'backup', 111000 ),
		);
		foreach ( $dumps as $i => $d ) {
			list( $days, $hour, $minute, $kind, $bytes ) = $d;
			$ts   = $demo->ago( $days, $hour, $minute );
			$name = $stem . '-' . $kind . '-' . gmdate( 'YmdHis', $ts ) . '-' . substr( md5( 'wpmdb-' . $i ), 0, 5 ) . '.sql.gz';
			$write( $dir . $name, $about( $bytes, $name, 6000 ), $ts + 30 );
		}
		$summary[] = count( $dumps ) . ' WP Migrate dumps';
	}

	/*
	 * Disembark: two scan sessions left in uploads/disembark, each a set of
	 * chunk lists (files-N.json) and the manifest that sums them, as their
	 * finalize_manifest() writes it. The lists name real files of this site.
	 */
	if ( class_exists( '\Disembark\Token' ) ) {
		$upload = wp_upload_dir( null, false );
		$root   = wp_normalize_path( dirname( WP_CONTENT_DIR ) ) . '/';
		$found  = array();
		foreach ( array( WP_CONTENT_DIR . '/themes', $upload['basedir'] . '/' . gmdate( 'Y' ), WP_CONTENT_DIR . '/plugins/woocommerce/includes' ) as $from ) {
			if ( ! is_dir( $from ) ) {
				continue;
			}
			$walk = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $from, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $walk as $file ) {
				if ( $file->isFile() && ! $file->isLink() ) {
					$found[ substr( wp_normalize_path( $file->getPathname() ), strlen( $root ) ) ] = (int) $file->getSize();
				}
			}
		}
		ksort( $found );
		// days ago, hour, minute, one file in how many missing (the site had fewer then)
		$sessions = array(
			array( 4, 21, 10, 0 ),
			array( 18, 20, 45, 11 ),
		);
		$stats = null;
		foreach ( $sessions as $i => $s ) {
			list( $days, $hour, $minute, $gap ) = $s;
			$ts    = $demo->ago( $days, $hour, $minute );
			$token = substr( md5( 'disembark-session-' . $i ), 0, 12 );
			$dir   = trailingslashit( $upload['basedir'] ) . 'disembark/' . $token;
			$list  = array();
			foreach ( $found as $name => $size ) {
				if ( ! $gap || 0 !== crc32( $name ) % $gap ) {
					$list[ $name ] = $size;
				}
			}
			$chunks   = array_chunk( array_keys( $list ), (int) ceil( count( $list ) / 3 ) );
			$manifest = array();
			$total    = 0;
			foreach ( $chunks as $n => $names ) {
				$objects = array();
				foreach ( $names as $name ) {
					$objects[] = array(
						'name' => $name,
						'size' => $list[ $name ],
						'type' => 'file',
					);
				}
				$file = 'files-' . ( $n + 1 ) . '.json';
				$write( $dir . '/' . $file, 0, $ts - 60 + $n * 20, wp_json_encode( $objects, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				$size       = array_sum( wp_list_pluck( $objects, 'size' ) );
				$total     += $size;
				$manifest[] = array(
					'name'  => $file,
					'url'   => trailingslashit( $upload['baseurl'] ) . 'disembark/' . $token . '/' . $file,
					'path'  => substr( wp_normalize_path( $dir ), strlen( wp_normalize_path( ABSPATH ) ) ) . '/' . $file,
					'size'  => $size,
					'count' => count( $objects ),
				);
			}
			$write( $dir . '/manifest.json', 0, $ts, wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			if ( ! $stats ) {
				$stats = array(
					'total_size'  => $total,
					'total_files' => count( $list ),
					'timestamp'   => $ts,
				);
			}
		}
		update_option( 'disembark_last_scan_stats', $stats );
		$summary[] = count( $sessions ) . ' Disembark sessions';
	}

	foreach ( $made_files as $i => $file ) {
		$demo->remember( 'backups-files', (string) $i, $file );
	}
	foreach ( $made_dirs as $i => $dir ) {
		$demo->remember( 'backups-dirs', (string) $i, $dir );
	}
	$bytes = 0;
	foreach ( $made_files as $file ) {
		$bytes += (int) filesize( $content . $file );
	}
	$demo->log( implode( ', ', $summary ) . '; ' . count( $made_files ) . ' placeholder files, ' . size_format( $bytes ) );
};
