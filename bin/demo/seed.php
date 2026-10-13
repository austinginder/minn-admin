<?php
/**
 * Seed the demo site that record.js records for minnadmin.com. A fresh
 * WordPress with the plugins in plugins.txt active becomes Larkspur & Fern, a
 * small plant shop with a journal, workshops and a plant club. Everything in
 * it is made up, the people included, and every image comes from the CC0 set
 * bundled with Twenty Twenty-Five. The same run on a fresh site makes the
 * same site; dates count back from the day it runs, so the demo looks current
 * whenever it is recorded.
 *
 * seed.sh resets the site and runs this. By hand, from the site root:
 *   wp eval-file <plugin>/bin/demo/seed.php          (a fresh site: every part)
 *   wp eval-file <plugin>/bin/demo/seed.php mail     (one part again, on a seeded site)
 *
 * The parts are seed/*.php in name order. Each returns a function that takes
 * the Minn_Demo_Seed below: the cast, the clock, and the ids the earlier parts
 * made. Run on its own, a part first clears what it made before, so it can be
 * worked on without reseeding the whole site.
 *
 * @package minn-admin
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

final class Minn_Demo_Seed {

	/** The Unix time the run started: every date counts back from it. */
	public $now;

	/** The part running now. */
	public $part = '';

	public function __construct() {
		$this->now = time();
	}

	/**
	 * A Unix time $days before the run, at $hour:$minute in the site's
	 * timezone ($days may be negative for the future).
	 */
	public function ago( $days, $hour = 10, $minute = 0 ) {
		$day = new DateTime( '@' . ( $this->now - (int) round( $days * DAY_IN_SECONDS ) ) );
		$day->setTimezone( wp_timezone() );
		$day->setTime( (int) $hour, (int) $minute );
		return $day->getTimestamp();
	}

	/** Site-local and UTC MySQL datetimes for a Unix time. */
	public function local( $ts ) {
		return wp_date( 'Y-m-d H:i:s', $ts );
	}

	public function gmt( $ts ) {
		return gmdate( 'Y-m-d H:i:s', $ts );
	}

	/**
	 * Ids the parts made, kept so a later part (or a rerun) finds them. One
	 * option per group, so parts run side by side never write over each
	 * other's ids.
	 */
	public function remember( $group, $key, $id ) {
		$ids         = $this->ids( $group );
		$ids[ $key ] = $id;
		update_option( 'minn_demo_ids_' . $group, $ids, false );
		return $id;
	}

	public function id( $group, $key ) {
		$ids = $this->ids( $group );
		return isset( $ids[ $key ] ) ? $ids[ $key ] : 0;
	}

	public function ids( $group ) {
		return (array) get_option( 'minn_demo_ids_' . $group, array() );
	}

	public function forget( $group ) {
		delete_option( 'minn_demo_ids_' . $group );
	}

	/** One person of the cast, with their user id when they have an account. */
	public function person( $slug ) {
		foreach ( self::cast() as $p ) {
			if ( $p['slug'] === $slug ) {
				$p['id'] = $this->id( 'users', $slug );
				return $p;
			}
		}
		WP_CLI::error( "No one called {$slug} in the cast." );
	}

	/** The cast whose role is $role ('customer', or null for people without an account). */
	public function people( $role = 'customer' ) {
		$out = array();
		foreach ( self::cast() as $p ) {
			if ( $p['role'] === $role ) {
				$p['id']  = $this->id( 'users', $p['slug'] );
				$out[]    = $p;
			}
		}
		return $out;
	}

	/** An attachment id by the image's file name (without .webp). */
	public function image( $name ) {
		$id = $this->id( 'images', $name );
		if ( ! $id ) {
			WP_CLI::error( "The media part has no image called {$name}." );
		}
		return $id;
	}

	/** Act as a cast member with an account, so authorship and logs read right. */
	public function as_user( $slug ) {
		wp_set_current_user( $this->id( 'users', $slug ) );
	}

	public function log( $message ) {
		WP_CLI::log( '  ' . $message );
	}

	/**
	 * The people of the demo. Staff and customers get accounts; the rest only
	 * write in (forms, guest orders, bookings). Emails sit on reserved domains
	 * and phone numbers in the 555-01xx range kept for fiction.
	 */
	public static function cast() {
		$rows = array(
			// slug, first, last, role, email, phone, street, city, state, postcode, company
			array( 'june', 'June', 'Alder', 'administrator', 'june@larkspur.example', '(503) 555-0100', '112 Station Road', 'Portland', 'OR', '97214', 'Larkspur & Fern' ),
			array( 'theo', 'Theo', 'Marsh', 'editor', 'theo@larkspur.example', '(503) 555-0101', '', 'Portland', 'OR', '', '' ),
			array( 'priya', 'Priya', 'Raman', 'author', 'priya@larkspur.example', '(503) 555-0102', '', 'Portland', 'OR', '', '' ),
			array( 'mateo', 'Mateo', 'Cruz', 'shop_manager', 'mateo@larkspur.example', '(503) 555-0103', '', 'Portland', 'OR', '', '' ),
			array( 'hana', 'Hana', 'Sato', 'contributor', 'hana@larkspur.example', '(503) 555-0104', '', 'Portland', 'OR', '', '' ),
			array( 'olivia', 'Olivia', 'Bennett', 'customer', 'olivia.bennett@example.com', '(503) 555-0111', '2240 SE Clinton Street', 'Portland', 'OR', '97202', '' ),
			array( 'marcus', 'Marcus', 'Webb', 'customer', 'marcus.webb@example.net', '(206) 555-0112', '815 N 36th Street', 'Seattle', 'WA', '98103', '' ),
			array( 'aisha', 'Aisha', 'Khan', 'customer', 'aisha.khan@example.org', '(312) 555-0113', '1450 W Wellington Avenue', 'Chicago', 'IL', '60657', '' ),
			array( 'liam', 'Liam', "O'Connor", 'customer', 'liam.oconnor@example.com', '(617) 555-0114', '62 Pinckney Street', 'Boston', 'MA', '02114', '' ),
			array( 'sofia', 'Sofia', 'Rossi', 'customer', 'sofia.rossi@example.com', '(512) 555-0115', '1907 Alameda Drive', 'Austin', 'TX', '78704', '' ),
			array( 'daniel', 'Daniel', 'Kim', 'customer', 'daniel.kim@example.net', '(619) 555-0116', '3380 Granada Avenue', 'San Diego', 'CA', '92104', '' ),
			array( 'grace', 'Grace', 'Liu', 'customer', 'grace.liu@example.org', '(303) 555-0117', '1225 S Pearl Street', 'Denver', 'CO', '80210', '' ),
			array( 'noah', 'Noah', 'Fischer', 'customer', 'noah.fischer@example.com', '(612) 555-0118', '4120 Bryant Avenue S', 'Minneapolis', 'MN', '55409', '' ),
			array( 'emma', 'Emma', 'Larsen', 'customer', 'emma.larsen@example.net', '(608) 555-0119', '507 Jenifer Street', 'Madison', 'WI', '53703', '' ),
			array( 'ethan', 'Ethan', 'Brooks', 'customer', 'ethan.brooks@example.com', '(404) 555-0120', '988 Virginia Avenue NE', 'Atlanta', 'GA', '30306', '' ),
			array( 'chloe', 'Chloe', 'Martin', 'customer', 'chloe.martin@example.org', '(828) 555-0121', '31 Montford Avenue', 'Asheville', 'NC', '28801', '' ),
			array( 'lucas', 'Lucas', 'Silva', 'customer', 'lucas.silva@example.com', '(401) 555-0122', '144 Ives Street', 'Providence', 'RI', '02906', '' ),
			array( 'mia', 'Mia', 'Novak', 'customer', 'mia.novak@example.net', '(412) 555-0123', '5521 Walnut Street', 'Pittsburgh', 'PA', '15232', '' ),
			array( 'zoe', 'Zoe', 'Adeyemi', 'customer', 'zoe.adeyemi@example.com', '(718) 555-0124', '281 Prospect Place', 'Brooklyn', 'NY', '11238', '' ),
			array( 'ben', 'Ben', 'Carter', 'customer', 'ben.carter@example.org', '(802) 555-0125', '76 Loomis Street', 'Burlington', 'VT', '05401', '' ),
			array( 'isla', 'Isla', 'Morgan', 'customer', 'isla.morgan@example.com', '(505) 555-0126', '420 Camino Lejo', 'Santa Fe', 'NM', '87505', '' ),
			array( 'samuel', 'Samuel', 'Ortiz', null, 'samuel@ortizflorals.example', '(971) 555-0131', '3016 NE Alberta Street', 'Portland', 'OR', '97211', 'Ortiz Florals' ),
			array( 'ruth', 'Ruth', 'Abernathy', null, 'ruth.abernathy@example.com', '(503) 555-0132', '1800 NW Lovejoy Street', 'Portland', 'OR', '97209', 'Lovejoy Hall' ),
			array( 'kenji', 'Kenji', 'Watanabe', null, 'kenji.watanabe@example.net', '(360) 555-0133', '1104 Main Street', 'Vancouver', 'WA', '98660', '' ),
			array( 'nadia', 'Nadia', 'Petrova', null, 'nadia.petrova@example.org', '(541) 555-0134', '233 W 8th Avenue', 'Eugene', 'OR', '97401', '' ),
			array( 'owen', 'Owen', 'Price', null, 'owen.price@example.com', '(503) 555-0135', '640 SW Harrison Street', 'Portland', 'OR', '97201', 'Harrison Street Dental' ),
		);
		$out = array();
		foreach ( $rows as $r ) {
			$out[] = array(
				'slug'     => $r[0],
				'first'    => $r[1],
				'last'     => $r[2],
				'name'     => $r[1] . ' ' . $r[2],
				'role'     => $r[3],
				'email'    => $r[4],
				'phone'    => $r[5],
				'street'   => $r[6],
				'city'     => $r[7],
				'state'    => $r[8],
				'postcode' => $r[9],
				'company'  => $r[10],
				'country'  => 'US',
			);
		}
		return $out;
	}
}

$minn_demo_parts = glob( __DIR__ . '/seed/*.php' );
sort( $minn_demo_parts );
$minn_demo_only = isset( $args[0] ) ? (string) $args[0] : '';

if ( '' === $minn_demo_only ) {
	// A whole seed only lands on a fresh install: it adds, it never merges.
	// (Plugins add their own pages when activated, so only posts count.)
	$minn_demo_posts = (int) $GLOBALS['wpdb']->get_var( "SELECT COUNT(*) FROM {$GLOBALS['wpdb']->posts} WHERE post_type = 'post' AND post_status <> 'auto-draft'" );
	if ( get_option( 'minn_demo_seeded' ) || count_users()['total_users'] > 1 || $minn_demo_posts > 1 ) {
		WP_CLI::error( 'This site is not a fresh install. seed.sh resets a demo site first; a single part runs with its name.' );
	}
	// Marked from the start, so seed.sh can reset a run that stopped partway.
	update_option( 'minn_demo_seeded', 'started', false );
} elseif ( ! is_numeric( get_option( 'minn_demo_seeded' ) ) ) {
	WP_CLI::error( 'Seed the whole site once before running one part of it.' );
}

$minn_demo = new Minn_Demo_Seed();
// Seeding sends nothing off the site. Some plugins post to their own servers
// on events the seed causes: OttoKit sends every JetEngine item to its
// webhook whether or not any automation asks for it.
add_filter(
	'pre_http_request',
	function ( $pre, $request, $url ) {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		return $host && wp_parse_url( home_url(), PHP_URL_HOST ) !== $host ? new WP_Error( 'minn_demo_offline', 'The demo seed sends nothing off the site.' ) : $pre;
	},
	1,
	3
);
// Seeding mails no one: WooCommerce sends through this callback, and every
// other part writes its rows directly.
add_filter(
	'woocommerce_mail_callback',
	function () {
		return '__return_true';
	}
);
// What the first page loads would have done. A few plugins make their tables
// only on an admin page (JetBooking and JetAppointments on its init, WP Mail
// SMTP on admin_init, Elementor Pro's submissions) or from a daily job (Koko
// Analytics), and a fresh install, seeded with cron off, has had none. Each
// call is the one the plugin makes.
wp_set_current_user( 1 );
if ( class_exists( 'ElementorPro\Modules\Forms\Submissions\Database\Migration' ) ) {
	ElementorPro\Modules\Forms\Submissions\Database\Migration::install();
}
foreach ( array( 'jet_abaf', 'jet_apb' ) as $minn_demo_jet ) {
	if ( function_exists( $minn_demo_jet ) && is_object( $minn_demo_jet()->db ) ) {
		foreach ( get_object_vars( $minn_demo_jet()->db ) as $minn_demo_table ) {
			if ( is_object( $minn_demo_table ) && method_exists( $minn_demo_table, 'install_table' ) ) {
				$minn_demo_table->install_table();
			}
		}
	}
}
if ( class_exists( 'WPMailSMTP\Migrations' ) ) {
	( new WPMailSMTP\Migrations() )->init_migrations_on_request();
}
if ( function_exists( 'KokoAnalytics\get_migrations' ) ) {
	KokoAnalytics\get_migrations()->ensure_current();
}
wp_set_current_user( 0 );
foreach ( $minn_demo_parts as $minn_demo_file ) {
	$minn_demo->part = preg_replace( '/^\d+-|\.php$/', '', basename( $minn_demo_file ) );
	if ( '' !== $minn_demo_only && $minn_demo->part !== $minn_demo_only ) {
		continue;
	}
	WP_CLI::log( $minn_demo->part );
	// Plugins set PHP's time limit as they go (Koko's migrations ask for five
	// minutes), and it counts for the whole run.
	set_time_limit( 0 );
	// Each part draws the same "random" choices whether it runs alone or in the whole seed.
	mt_srand( crc32( $minn_demo->part ) );
	$minn_demo_run = require $minn_demo_file;
	$minn_demo_run( $minn_demo, '' !== $minn_demo_only );
	wp_set_current_user( 0 );
}
if ( '' === $minn_demo_only ) {
	update_option( 'minn_demo_seeded', $minn_demo->now, false );
}
WP_CLI::success( 'Seeded ' . wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ) . '.' );
