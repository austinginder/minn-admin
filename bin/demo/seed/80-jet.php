<?php
/**
 * The Crocoblock tools: JetReviews reviews of products and journal posts
 * (approved and waiting, from customers and guests, with a shop reply or
 * two), two JetEngine Custom Content Types (Plant Care Guides and Supplier
 * Contacts), the searches JetSearch has recorded as suggestions, and the
 * JetSmartFilters filters for the catalog with the indexer on.
 *
 * What the plugins store, learned the hard way:
 * - JetReviews: {prefix}jet_reviews, rating 0-100 (Minn shows /20), approved
 *   '0'/'1', rating_data a SERIALIZED list of field_label / field_value /
 *   field_step / field_max (what their submit endpoint writes). A guest's
 *   author is a 'guest_…' string resolved through jet_review_guests.
 *   Their submit-comment call mails the moderator, so replies go straight
 *   into jet_review_comments.
 * - JetEngine CCT: the module is a slug in the jet_engine_modules option;
 *   switched on mid-request it has missed 'init', so the part boots it by
 *   hand. A type is a jet_post_types row (status 'content-type') whose
 *   table jet_cct_{slug} is made by Data::create_item() and dropped by
 *   Data::delete_item(); create refuses a slug whose table still exists.
 *   Rows go through the type's own item handler, which stamps "now", so
 *   the dates are set afterwards (both columns read as site-local).
 * - JetSearch: {prefix}jet_search_suggestions, weight = times searched,
 *   parent = a parent suggestion's id ('0' for none, as their front end
 *   writes it). Duplicates are told apart by TRIM(name).
 * - JetSmartFilters: filters are jet-smart-filters posts with their
 *   settings in underscore meta. The indexer object is built at load from
 *   the use_indexed_filters setting, so switching it on mid-request needs a
 *   fresh Jet_Smart_Filters_Indexer_Manager before index_filters().
 *
 * @package minn-admin
 */

return function ( Minn_Demo_Seed $demo ) {
	global $wpdb;
	$demo->as_user( 'june' );

	// Seeding sends nothing out. OttoKit posts some plugin events (every
	// JetEngine content type item among them) to its own servers whether or
	// not an automation uses them, then logs and retries what fails. While
	// this part runs, requests leave for this site only and OttoKit logs none.
	$home    = wp_parse_url( home_url(), PHP_URL_HOST );
	$offline = function ( $pre, $args, $url ) use ( $home ) {
		return wp_parse_url( $url, PHP_URL_HOST ) === $home ? $pre : new WP_Error( 'minn_demo_offline', 'The demo seed makes no outside requests.' );
	};
	add_filter( 'pre_http_request', $offline, 10, 3 );
	add_filter( 'suretriggers_enable_request_logging', '__return_false' );

	/*
	 * JetReviews.
	 */
	if ( function_exists( 'jet_reviews' ) && class_exists( '\Jet_Reviews\Reviews\Data' ) && is_object( jet_reviews()->db ) ) {
		$data     = \Jet_Reviews\Reviews\Data::get_instance();
		$guests_t = jet_reviews()->db->tables( 'review_guests', 'name' );
		$notes_t  = jet_reviews()->db->tables( 'review_comments', 'name' );
		foreach ( $demo->ids( 'jet_reviews' ) as $id ) {
			$data->delete_review_by_id( (int) $id ); // drops its comments and meta too
		}
		$demo->forget( 'jet_reviews' );

		$products = $demo->ids( 'products' );
		$posts    = $demo->ids( 'posts' );
		// who, what (product or post slug), stars, title, review, approved, days ago, hour, likes, dislikes
		$reviews = array(
			array( 'olivia', 'anthurium', 5, 'Still blooming after two months', 'It came with three red spathes and has put out two more since. I keep it in the east window of the bathroom and mist it when I remember.', 1, 52, 19, 6, 0 ),
			array( 'marcus', 'peace-lily', 4, 'Forgiving, as promised', 'I let it droop twice before I learned its schedule and it bounced back both times. Knocked off a star because the pot was a bit small for the roots.', 1, 47, 8, 3, 1 ),
			array( 'kenji', 'columbine', 4, 'Hummingbirds found it in a week', 'Planted three by the fence in early spring. The leaves had some leaf miner trails but the flowers were lovely.', 1, 41, 17, 2, 0 ),
			array( 'aisha', 'meadow-mix', 5, 'A whole meadow from one packet', 'Sowed it on a bare strip by the alley. Poppies first, then cornflowers and phacelia. The bees have not left since.', 1, 36, 12, 9, 0 ),
			array( 'liam', 'print-bellflower', 5, 'Lovely print, lovely paper', 'Bought the 11 x 14 for my sister. The paper has real weight to it and the line work is crisp. Arrived flat in a stiff mailer.', 1, 31, 20, 4, 0 ),
			array( 'noah', 'five-houseplants-that-forgive-a-missed-watering', 5, 'Exactly the list I needed', 'I travel for work and every plant I owned died while I was gone. Bought the ZZ plant and the snake plant from this list and both are thriving.', 1, 27, 21, 5, 0 ),
			array( 'sofia', 'saguaro', 4, 'Small but perfect', 'Arrived wrapped in paper and padded with straw, not a single spine bent. It is smaller than I pictured, which the listing does say.', 1, 22, 9, 1, 0 ),
			array( 'grace', 'workshop', 5, 'Best Saturday morning in ages', 'Priya walked us through layering, moss and the little figures. I went home with a terrarium I am actually proud of. Coffee was good too.', 1, 16, 14, 7, 0 ),
			array( 'emma', 'lupine', 3, 'Healthy but slow to settle in', 'The plant arrived healthy and well packed, but it sulked for a few weeks after planting and only gave me one spire this year.', 1, 11, 18, 0, 1 ),
			array( 'chloe', 'sowing-sunflowers-with-kids', 5, 'Our new spring tradition', 'We followed this with my two kids and the Teddy Bear seeds. Every one came up. They measure them every morning now.', 0, 6, 20, 0, 0 ),
			array( 'ruth', 'workshop', 5, 'Booked it for our staff', 'We brought twelve people from Lovejoy Hall to the terrarium session as a team outing. Priya kept everyone laughing and nobody broke any glass.', 0, 3, 16, 0, 0 ),
			array( 'nadia', 'peace-lily', 2, 'One leaf arrived torn', 'The plant looks healthy, but one of the big leaves was torn in transit and the soil had spilled into the box. Hoping it recovers.', 0, 1.5, 11, 0, 0 ),
			array( 'daniel', 'anthurium', 4, 'Gorgeous color', 'The red is even deeper in person. Two of the lower leaves are a little yellow, so I am watching the watering. Would buy again.', 0, 0.2, 8, 0, 0 ),
		);
		// One reply from the shop under two of them, as JetReviews shows it.
		$replies = array(
			'nadia' => 'So sorry about the leaf, Nadia. Trim it at the base and it will push out new growth from the center. We have sent you a note about a partial refund.',
			'emma'  => 'Lupines often take a season to settle. Give it a sunny spot and it should come back stronger next spring.',
		);
		$synced  = array();
		$count   = 0;
		$pending = 0;
		foreach ( $reviews as $r ) {
			list( $slug, $target, $stars, $title, $content, $approved, $days, $hour, $likes, $dislikes ) = $r;
			$p         = $demo->person( $slug );
			$post_id   = isset( $products[ $target ] ) ? (int) $products[ $target ] : (int) ( $posts[ $target ] ?? 0 );
			$post_type = isset( $products[ $target ] ) ? 'product' : 'post';
			if ( ! $post_id ) {
				continue;
			}
			if ( $p['id'] ) {
				$author = (int) $p['id'];
			} else {
				// A guest, the way their form records one.
				$author = 'guest_' . substr( md5( $p['email'] ), 0, 9 );
				$wpdb->delete( $guests_t, array( 'mail' => $p['email'] ) );
				jet_reviews()->user_manager->add_new_guest( array( 'guest_id' => $author, 'name' => $p['name'], 'mail' => $p['email'] ) );
			}
			$ts     = min( $demo->now - 1800, $demo->ago( $days, $hour, ( $count * 13 ) % 60 ) );
			$result = $data->add_new_review(
				array(
					'source'      => 'post',
					'post_id'     => $post_id,
					'post_type'   => $post_type,
					'author'      => $author,
					'date'        => $demo->local( $ts ),
					'title'       => $title,
					'content'     => $content,
					'type_slug'   => 'default',
					'rating_data' => maybe_serialize( array( array( 'field_label' => 'Rating', 'field_value' => $stars, 'field_step' => 1, 'field_max' => 5 ) ) ),
					'rating'      => $stars * 20,
					'likes'       => $likes,
					'dislikes'    => $dislikes,
					'approved'    => $approved,
				)
			);
			if ( empty( $result['insert_id'] ) ) {
				WP_CLI::warning( "JetReviews would not take {$slug}'s review." );
				continue;
			}
			$demo->remember( 'jet_reviews', $slug . '-' . $target, (int) $result['insert_id'] );
			if ( isset( $replies[ $slug ] ) ) {
				$wpdb->insert(
					$notes_t,
					array(
						'source'    => 'post',
						'post_id'   => $post_id,
						'parent_id' => 0,
						'review_id' => (int) $result['insert_id'],
						'author'    => $demo->id( 'users', 'june' ),
						'content'   => $replies[ $slug ],
						'date'      => $demo->local( $ts + 5 * HOUR_IN_SECONDS ),
						'approved'  => '1',
					)
				);
			}
			$synced[ $post_type . ':' . $post_id ] = array( $post_type, $post_id );
			++$count;
			$pending += $approved ? 0 : 1;
		}
		foreach ( $synced as $s ) {
			$data->sync_rating_source_meta_by_id( 'post', $s[0], $s[1] );
		}
		$demo->log( "{$count} JetReviews reviews, {$pending} waiting" );
	}

	/*
	 * JetEngine Custom Content Types.
	 */
	if ( function_exists( 'jet_engine' ) && is_object( jet_engine()->modules ) ) {
		$modules = jet_engine()->modules;
		$modules->activate_module( 'custom-content-types' ); // keeps whatever else is on
		if ( ! class_exists( '\Jet_Engine\Modules\Custom_Content_Types\Module' ) ) {
			require_once $modules->modules_path( 'custom-content-types/inc/module.php' );
		}
		$cct = \Jet_Engine\Modules\Custom_Content_Types\Module::instance();
		if ( ! $cct->manager ) {
			$cct->init(); // switched on in this request: 'init' has already run
		}
		foreach ( array( 'single-item-factory.php', 'factory.php', 'type-pages.php' ) as $file ) {
			require_once $cct->module_path( $file );
		}
		$types_t = $wpdb->prefix . 'jet_post_types';

		$field = function ( $name, $title, $type, $extra = array() ) {
			static $n = 0;
			return array_merge(
				array(
					'title'       => $title,
					'name'        => $name,
					'object_type' => 'field',
					'type'        => $type,
					'width'       => '100%',
					'id'          => ++$n,
					'isNested'    => false,
				),
				$extra
			);
		};
		$select = function ( $name, $title, $choices ) use ( $field ) {
			$options = array();
			foreach ( $choices as $i => $choice ) {
				$options[] = array( 'key' => $choice, 'value' => $choice, 'id' => $i + 1 );
			}
			return $field( $name, $title, 'select', array( 'options' => $options, 'options_source' => 'manual', 'width' => '50%' ) );
		};

		// JetEngine lists types newest first, and Minn makes the first one
		// the surface's main view: the guides are made last to lead.
		$types = array(
			'supplier_contacts' => array(
				'name'   => 'Supplier Contacts',
				'icon'   => 'dashicons-businessperson',
				'author' => 'mateo',
				'fields' => array(
					$field( 'company', 'Company', 'text', array( 'is_required' => true ) ),
					$field( 'contact_name', 'Contact', 'text', array( 'width' => '50%' ) ),
					$select( 'supplies', 'Supplies', array( 'Plants', 'Pots and planters', 'Soil and amendments', 'Seeds and bulbs', 'Workshop supplies', 'Printing' ) ),
					$field( 'email', 'Email', 'text', array( 'width' => '50%' ) ),
					$field( 'phone', 'Phone', 'text', array( 'width' => '50%' ) ),
					$field( 'ordering', 'Ordering now', 'switcher' ),
					$field( 'notes', 'Notes', 'textarea', array( 'value_format' => 'plain' ) ),
				),
				// company, contact, supplies, email, phone, ordering, notes, status, days ago
				'rows'   => array(
					array( 'Cedar Hollow Nursery', 'Rosa Delgado', 'Plants', 'orders@cedarhollow.example', '(503) 555-0141', true, 'Tropicals and houseplants. Delivers on Tuesdays, minimum order $400.', 'publish', 57 ),
					array( 'Foxglove Pottery', 'Will Haddad', 'Pots and planters', 'will@foxglovepottery.example', '(503) 555-0142', true, 'Hand-thrown planters, all with drainage holes. Six weeks lead time on the speckled glaze.', 'publish', 49 ),
					array( 'Basalt & Bark Soils', 'Dana Whitfield', 'Soil and amendments', 'sales@basaltbark.example', '(971) 555-0143', true, 'Peat-free potting mix in 40 quart bags. Our mix since spring.', 'publish', 38 ),
					array( 'Rainshadow Seed Co.', 'Eli Brandt', 'Seeds and bulbs', 'wholesale@rainshadowseed.example', '(541) 555-0144', true, 'Native wildflower seed for the meadow mix. Order by January for spring packing.', 'publish', 24 ),
					array( 'Mossback Terrarium Supply', 'Lena Ito', 'Workshop supplies', 'hello@mossback.example', '(360) 555-0145', false, 'Glass jars, cushion moss and lava rock for the workshops. Paused until the fall calendar is set.', 'publish', 13 ),
					array( 'Inkwell Print Studio', 'Tomas Reyes', 'Printing', 'prints@inkwellstudio.example', '(503) 555-0146', false, 'Quote for the 16 x 20 bellflower run. Waiting on cotton rag paper samples.', 'draft', 1 ),
				),
			),
			'plant_care_guides' => array(
				'name'   => 'Plant Care Guides',
				'icon'   => 'dashicons-palmtree',
				'author' => 'theo',
				'fields' => array(
					$field( 'plant_name', 'Plant', 'text', array( 'is_required' => true ) ),
					$select( 'light', 'Light', array( 'Low light', 'Medium light', 'Bright indirect', 'Full sun' ) ),
					$select( 'difficulty', 'Difficulty', array( 'Easy', 'Moderate', 'Fussy' ) ),
					$select( 'pets', 'Pets', array( 'Pet safe', 'Mildly toxic', 'Toxic to pets' ) ),
					$field( 'botanical_name', 'Botanical name', 'text', array( 'width' => '50%' ) ),
					$field( 'water', 'Water', 'text', array( 'width' => '50%' ) ),
					$field( 'care_notes', 'Care notes', 'textarea', array( 'value_format' => 'plain' ) ),
					$field( 'staff_pick', 'Staff pick', 'switcher' ),
				),
				// plant, light, difficulty, pets, botanical, water, notes, staff pick, status, days ago
				'rows'   => array(
					array( 'Golden barrel cactus', 'Full sun', 'Easy', 'Pet safe', 'Echinocactus grusonii', 'Every three weeks in summer, almost never in winter', 'Give it the sunniest sill you have and a gritty mix. Turn it a quarter turn every month so it grows straight.', false, 'publish', 58 ),
					array( 'Peace lily', 'Low light', 'Easy', 'Toxic to pets', 'Spathiphyllum wallisii', 'Weekly, or as soon as the leaves start to droop', 'Tells you when it is thirsty. Wipe the leaves now and then and keep it away from cold drafts.', true, 'publish', 55 ),
					array( 'Snake plant', 'Low light', 'Easy', 'Mildly toxic', 'Dracaena trifasciata', 'Every two to three weeks, less in winter', 'The best plant for a dark hallway. Overwatering is the only way to kill it.', true, 'publish', 51 ),
					array( 'ZZ plant', 'Low light', 'Easy', 'Toxic to pets', 'Zamioculcas zamiifolia', 'When the soil is completely dry', 'Stores water in its rhizomes, so it shrugs off a missed watering or three.', false, 'publish', 46 ),
					array( 'Anthurium', 'Bright indirect', 'Moderate', 'Toxic to pets', 'Anthurium andraeanum', 'When the top inch of soil is dry', 'Loves a humid bathroom. Feed lightly every month in spring and summer to keep the spathes coming.', true, 'publish', 40 ),
					array( 'Spider plant', 'Bright indirect', 'Easy', 'Pet safe', 'Chlorophytum comosum', 'Weekly in summer, every ten days in winter', 'Brown tips usually mean tap water. Pot up the babies in small pots and they root in two weeks.', false, 'publish', 33 ),
					array( 'Pothos', 'Medium light', 'Easy', 'Toxic to pets', 'Epipremnum aureum', 'When the top two inches are dry', 'Trails or climbs. Cuttings root in a glass of water, which makes it the star of the propagation bar.', false, 'publish', 27 ),
					array( 'Boston fern', 'Medium light', 'Moderate', 'Pet safe', 'Nephrolepis exaltata', 'Keep the soil evenly moist', 'Mist it or set it on a tray of wet pebbles. Drops leaflets if the air gets too dry.', false, 'publish', 19 ),
					array( 'Calathea orbifolia', 'Medium light', 'Fussy', 'Pet safe', 'Goeppertia orbifolia', 'Keep lightly moist with filtered water', 'Curls its leaves when it is unhappy. Hates direct sun, cold windows and hard water.', false, 'publish', 12 ),
					array( 'Fiddle leaf fig', 'Bright indirect', 'Fussy', 'Toxic to pets', 'Ficus lyrata', 'When the top two inches are dry', 'Pick a spot and leave it there. Moving it is the most common reason it drops leaves.', false, 'publish', 6 ),
					array( 'String of pearls', 'Bright indirect', 'Moderate', 'Toxic to pets', 'Curio rowleyanus', 'Every two weeks, from below', 'Draft for the kokedama class handout. Priya to add her tip about shallow pots.', false, 'draft', 2 ),
					array( 'Cushion moss', 'Low light', 'Moderate', 'Pet safe', 'Leucobryum glaucum', 'Mist twice a week', 'Draft for the terrarium workshop. Needs a photo of the moss layer.', false, 'draft', 0.4 ),
				),
			),
		);

		// JetEngine caches each of its tables per request; a type made or
		// dropped here is only seen after the cache is let go.
		$fresh = function () use ( $cct ) {
			unset( $cct->manager->data->db->query_cache['post_types'] );
		};
		$made  = array();
		foreach ( $types as $slug => $type ) {
			// Start over: the type's own delete drops its table with it.
			$old = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$types_t} WHERE slug = %s AND status = 'content-type'", $slug ) );
			if ( $old ) {
				$fresh();
				$cct->manager->data->set_request( array( 'id' => $old ) );
				$cct->manager->data->delete_item( false );
			}
			$wpdb->query( 'DROP TABLE IF EXISTS ' . esc_sql( $wpdb->prefix . 'jet_cct_' . $slug ) ); // a table left by a broken run
			$cct->manager->data->set_request(
				array(
					'name'        => $type['name'],
					'slug'        => $slug,
					'args'        => array( 'name' => $type['name'], 'slug' => $slug, 'icon' => $type['icon'], 'capability' => 'manage_options' ),
					'meta_fields' => $type['fields'],
				)
			);
			$type_id = $cct->manager->data->create_item( false );
			$fresh();
			$item = $type_id ? $cct->manager->data->get_item_by_id( $type_id ) : false;
			if ( ! $item ) {
				WP_CLI::warning( "JetEngine would not create the {$type['name']} content type." );
				continue;
			}
			$factory = new \Jet_Engine\Modules\Custom_Content_Types\Factory( $item['args'], $item['meta_fields'], $type_id );
			$handler = $factory->get_item_handler();
			$names   = wp_list_pluck( $type['fields'], 'name' );
			$demo->as_user( $type['author'] );
			$n = 0;
			foreach ( $type['rows'] as $row ) {
				$days   = array_pop( $row );
				$status = array_pop( $row );
				$values = array_combine( $names, $row );
				foreach ( $values as $key => $value ) {
					if ( is_bool( $value ) ) {
						$values[ $key ] = $value ? 'true' : 'false'; // how their switcher stores
					}
				}
				$values['cct_status'] = $status;
				$id                   = $handler->update_item( $values );
				if ( ! $id || is_wp_error( $id ) ) {
					WP_CLI::warning( "{$type['name']}: could not add {$row[0]}." );
					continue;
				}
				$created  = min( $demo->now - 1800, $demo->ago( $days, 9 + $n % 8, ( $n * 17 ) % 60 ) );
				// Every third one was touched up later.
				$modified = $n % 3 ? $created : max( $created, min( $demo->now - 900, $demo->ago( $days * 0.6, 10 + $n % 7, ( $n * 23 ) % 60 ) ) );
				$wpdb->update(
					$wpdb->prefix . 'jet_cct_' . $slug,
					array( 'cct_created' => $demo->local( $created ), 'cct_modified' => $demo->local( $modified ) ),
					array( '_ID' => (int) $id )
				);
				++$n;
			}
			$demo->as_user( 'june' );
			$made[] = $type['name'] . ' (' . $n . ')';
		}
		$demo->log( 'JetEngine content types: ' . implode( ', ', $made ) );
	}

	/*
	 * JetSearch suggestions: what visitors typed into the shop search.
	 */
	$suggest_t = $wpdb->prefix . 'jet_search_suggestions';
	if ( function_exists( 'jet_search' ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $suggest_t ) ) === $suggest_t ) {
		// name => weight; a parent of 'workshops' is set below.
		$searches = array(
			'monstera'            => 46,
			'pet safe plants'     => 38,
			'workshops'           => 33,
			'low light'           => 27,
			'terrarium workshop'  => 24,
			'peace lily'          => 22,
			'anthurium'           => 19,
			'succulent soil'      => 14,
			'gift card'           => 12,
			'kokedama'            => 11,
			'sunflower seeds'     => 9,
			'botanical print'     => 8,
			'hanging planter'     => 6,
			'fiddle leaf fig'     => 5,
			'wildflower seed mix' => 4,
			'propagation'         => 3,
			// An older JetSearch saved untrimmed text: the same search twice.
			'pet safe plants '    => 2,
		);
		$children = array( 'terrarium workshop', 'kokedama' );
		$names    = array_unique( array_map( 'trim', array_keys( $searches ) ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$suggest_t} WHERE TRIM(name) IN (" . implode( ', ', array_fill( 0, count( $names ), '%s' ) ) . ')', $names ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$ids = array();
		foreach ( $searches as $name => $weight ) {
			$wpdb->insert( $suggest_t, array( 'name' => $name, 'weight' => $weight, 'parent' => '0', 'term' => null ), array( '%s', '%d', '%s', '%s' ) );
			$ids[ $name ] = (int) $wpdb->insert_id;
		}
		foreach ( $children as $name ) {
			$wpdb->update( $suggest_t, array( 'parent' => (string) $ids['workshops'] ), array( 'id' => $ids[ $name ] ) );
		}
		$demo->log( count( $searches ) . ' JetSearch suggestions' );
	}

	/*
	 * JetSmartFilters: the filters on the shop page, and the indexer.
	 */
	if ( function_exists( 'jet_smart_filters' ) && post_type_exists( 'jet-smart-filters' ) ) {
		foreach ( $demo->ids( 'jet_filters' ) as $id ) {
			wp_delete_post( (int) $id, true );
		}
		$demo->forget( 'jet_filters' );
		$manual = function ( $pairs ) {
			$out = array();
			foreach ( $pairs as $value => $label ) {
				$out[] = array( 'value' => $value, 'label' => $label );
			}
			return $out;
		};
		// key => title, status, days ago, meta
		$filters = array(
			'price'    => array(
				'Price',
				'publish',
				120,
				array( '_filter_type' => 'range', '_filter_label' => 'Price', '_query_var' => '_price', '_source_callback' => 'jet_smart_filters_woo_prices', '_source_min' => '0', '_source_max' => '60', '_source_step' => '1', '_values_prefix' => '$', '_values_decimal_num' => '0', '_values_thousand_sep' => ',', '_range_inputs_enabled' => 'false' ),
			),
			'category' => array(
				'Category',
				'publish',
				118,
				array( '_filter_type' => 'checkboxes', '_filter_label' => 'Category', '_data_source' => 'taxonomies', '_source_taxonomy' => 'product_cat', '_query_var' => 'product_cat', '_terms_relational_operator' => 'OR', '_show_empty_terms' => 'false' ),
			),
			'light'    => array(
				'Light level',
				'publish',
				96,
				array( '_filter_type' => 'radio', '_filter_label' => 'How much light do you have?', '_data_source' => 'manual_input', '_source_manual_input' => $manual( array( 'low' => 'Low light', 'medium' => 'Medium light', 'bright' => 'Bright indirect', 'sun' => 'Full sun' ) ), '_query_var' => 'light_level', '_query_compare' => 'equal', '_add_all_option' => 'true', '_all_option_label' => 'Any light', '_ability_deselect_radio' => 'true' ),
			),
			'pets'     => array(
				'Pet safe',
				'publish',
				96,
				array( '_filter_type' => 'checkboxes', '_filter_label' => 'Pets at home', '_data_source' => 'manual_input', '_source_manual_input' => $manual( array( 'yes' => 'Safe for cats and dogs' ) ), '_query_var' => 'pet_safe', '_query_compare' => 'equal' ),
			),
			'tags'     => array(
				'Good for',
				'publish',
				74,
				array( '_filter_type' => 'select', '_filter_label' => 'Good for', '_data_source' => 'taxonomies', '_source_taxonomy' => 'product_tag', '_query_var' => 'product_tag', '_placeholder' => 'Any', '_show_empty_terms' => 'false' ),
			),
			'rating'   => array(
				'Customer rating',
				'publish',
				61,
				array( '_filter_type' => 'rating', '_filter_label' => 'Customer rating', '_rating_options' => '5', '_rating_compare_operand' => 'greater', '_query_var' => '_wc_average_rating' ),
			),
			'search'   => array(
				'Search plants',
				'publish',
				45,
				array( '_filter_type' => 'search', '_filter_label' => 'Search', '_s_by' => 'default', '_s_placeholder' => 'Search plants, seeds and prints', '_query_var' => '' ),
			),
			'dates'    => array(
				'Workshop dates',
				'draft',
				4,
				array( '_filter_type' => 'date-range', '_filter_label' => 'Workshop dates', '_date_source' => 'meta_query', '_query_var' => 'workshop_date', '_date_format' => 'mm/dd/yy', '_date_from_placeholder' => 'From', '_date_to_placeholder' => 'To' ),
			),
		);
		$i = 0;
		foreach ( $filters as $key => $f ) {
			list( $title, $status, $days, $meta ) = $f;
			$ts = $demo->ago( $days, 10 + $i % 6, ( $i * 11 ) % 60 );
			$id = wp_insert_post(
				array(
					'post_type'         => 'jet-smart-filters',
					'post_title'        => $title,
					'post_status'       => $status,
					'post_author'       => $demo->id( 'users', 'june' ),
					'post_date'         => $demo->local( $ts ),
					'post_date_gmt'     => 'draft' === $status ? '0000-00-00 00:00:00' : $demo->gmt( $ts ),
					'post_modified'     => $demo->local( $ts ),
					'post_modified_gmt' => $demo->gmt( $ts ),
				),
				true
			);
			if ( is_wp_error( $id ) || ! $id ) {
				WP_CLI::warning( "JetSmartFilters: could not add {$title}." );
				continue;
			}
			foreach ( $meta as $mk => $mv ) {
				update_post_meta( $id, $mk, $mv );
			}
			$wpdb->update( $wpdb->posts, array( 'post_modified' => $demo->local( $ts ), 'post_modified_gmt' => $demo->gmt( $ts ) ), array( 'ID' => $id ) );
			clean_post_cache( $id );
			$demo->remember( 'jet_filters', $key, $id );
			++$i;
		}

		// The indexer: on for products, rebuilt by hand (auto-indexing off,
		// so other parts' product saves do not trigger it).
		$settings                         = (array) get_option( 'jet-smart-filters-settings', array() );
		$settings['use_indexed_filters']  = 'true';
		$settings['use_auto_indexing']    = 'false';
		$settings['avaliable_post_types'] = array_merge( (array) ( $settings['avaliable_post_types'] ?? array() ), array( 'product' => 'true' ) );
		update_option( 'jet-smart-filters-settings', $settings );
		$jsf                         = jet_smart_filters();
		$jsf->settings->all_settings = null;
		if ( ( ! $jsf->indexer || ! $jsf->indexer->is_indexer_enabled ) && ! class_exists( 'Jet_Smart_Filters_Indexer_Data' ) ) {
			$jsf->indexer = new Jet_Smart_Filters_Indexer_Manager(); // it requires its classes itself
		}
		$jsf->indexer->index_filters();
		$rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}jet_smart_filters_indexer" );
		$demo->log( "{$i} JetSmartFilters filters, {$rows} index rows" );
	}

	remove_filter( 'pre_http_request', $offline, 10 );
	remove_filter( 'suretriggers_enable_request_logging', '__return_false' );
};
