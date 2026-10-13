<?php
/**
 * The journal and the pages: categories, tags, posts in every status the
 * content view filters by, the shop's pages, and a comment thread with
 * replies, a few waiting for approval and one spam.
 *
 * @package minn-admin
 */

return function ( Minn_Demo_Seed $demo ) {
	foreach ( array( 'posts', 'pages' ) as $group ) {
		foreach ( $demo->ids( $group ) as $id ) {
			wp_delete_post( $id, true );
		}
		$demo->forget( $group );
	}

	// Block markup from a short list: a string is a paragraph, '## ' a heading,
	// '- ' lines a list, 'img:<file>' an image from the media part.
	$blocks = function ( array $parts ) use ( $demo ) {
		$out = array();
		foreach ( $parts as $part ) {
			if ( 0 === strpos( $part, '## ' ) ) {
				$out[] = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">" . esc_html( substr( $part, 3 ) ) . "</h2>\n<!-- /wp:heading -->";
			} elseif ( 0 === strpos( $part, '- ' ) ) {
				$items = array();
				foreach ( explode( "\n", $part ) as $line ) {
					$items[] = "<!-- wp:list-item -->\n<li>" . esc_html( substr( $line, 2 ) ) . "</li>\n<!-- /wp:list-item -->";
				}
				$out[] = "<!-- wp:list -->\n<ul class=\"wp-block-list\">" . implode( "\n", $items ) . "</ul>\n<!-- /wp:list -->";
			} elseif ( 0 === strpos( $part, 'img:' ) ) {
				$id    = $demo->image( substr( $part, 4 ) );
				$out[] = '<!-- wp:image {"id":' . $id . ',"sizeSlug":"large","linkDestination":"none"} -->' . "\n"
					. '<figure class="wp-block-image size-large"><img src="' . esc_url( wp_get_attachment_image_url( $id, 'large' ) ) . '" alt="' . esc_attr( get_post_meta( $id, '_wp_attachment_image_alt', true ) ) . '" class="wp-image-' . $id . '"/></figure>' . "\n<!-- /wp:image -->";
			} else {
				$out[] = "<!-- wp:paragraph -->\n<p>" . $part . "</p>\n<!-- /wp:paragraph -->";
			}
		}
		return implode( "\n\n", $out );
	};

	$cats = array();
	foreach ( array( 'Plant Care', 'Workshops', 'Seasonal', 'Shop News' ) as $name ) {
		$term          = term_exists( $name, 'category' );
		$term          = $term ? $term : wp_insert_term( $name, 'category' );
		$cats[ $name ] = (int) $term['term_id'];
	}
	wp_update_term( $cats['Plant Care'], 'category', array( 'description' => 'Watering, light, soil and the questions customers ask us most.' ) );
	wp_update_term( $cats['Workshops'], 'category', array( 'description' => 'What is on at the shop and what we learned running it.' ) );

	// title => author, category, tags, status, days ago (negative: scheduled), featured image, excerpt, body
	$posts = array(
		'How often should you really water a cactus?' => array(
			'theo', 'Plant Care', array( 'cacti', 'watering', 'beginner-friendly' ), 'publish', 3, 'category-cactus',
			'Less often than you think, and more in summer than in winter. Here is the soak and dry method we use in the shop.',
			array(
				'Most cacti that come back to the shop in a sad state were not neglected. They were loved too much. A cactus stores water in its stem, and roots that sit in damp soil rot long before the plant looks thirsty.',
				'## The soak and dry method',
				'Water thoroughly until it runs out of the drainage hole, then leave it alone until the soil is completely dry all the way down. In a terracotta pot on a sunny sill that is usually every two to three weeks in summer.',
				'- Push a wooden skewer to the bottom of the pot. If it comes out clean and dry, water.' . "\n" . '- In winter, water once a month at most. Many cacti rest and need almost nothing.' . "\n" . '- Never let the pot stand in a saucer of water.',
				'img:category-cactus',
				'## Signs you are overwatering',
				'A soft, translucent base, yellowing from the bottom up, or a stem that leans when it did not before. If you catch it early, unpot the plant, cut away any brown roots and let it dry for a week before repotting in fresh, gritty mix.',
			),
		),
		'Five houseplants that forgive a missed watering' => array(
			'hana', 'Plant Care', array( 'houseplants', 'beginner-friendly', 'low-light' ), 'publish', 9, 'botany-flowers',
			'Going away for a long weekend? These five will be waiting for you, a little dry and none the worse.',
			array(
				'Not every plant needs a schedule. These are the ones we recommend to anyone who travels, forgets, or simply wants a plant that asks very little.',
				'- Peace lily: it droops dramatically when dry and perks up within hours of a drink.' . "\n" . '- ZZ plant: thick rhizomes hold weeks of water.' . "\n" . '- Snake plant: happy in a dim corner and dry soil.' . "\n" . '- Pothos: tells you when it is thirsty and grows back fast.' . "\n" . '- Golden saguaro: needs a sunny window, then mostly needs to be left alone.',
				'img:botany-flowers',
				'All five do best in a pot with a drainage hole. If you want a decorative pot without one, keep the plant in its nursery pot inside it and tip out any water that collects.',
			),
		),
		'The spring workshop calendar is open' => array(
			'priya', 'Workshops', array( 'terrariums', 'propagation' ), 'publish', 14, 'coral-square',
			'Terrariums, propagation and a kids sunflower morning: six Saturdays, twelve seats each.',
			array(
				'Our spring workshops are back, and this year every session is on a Saturday morning so you can come straight from the market.',
				'## What is on',
				'- Closed terrariums: build a small glass world that waters itself.' . "\n" . '- Propagation bar: take cuttings from our mother plants and learn which ones root in water.' . "\n" . '- Kids sunflower morning: sow, label and take home a pot, ages five and up.' . "\n" . '- Kokedama: wrap a fern in moss and string it up.',
				'Every workshop includes materials and a plant to take home. Seats are limited to twelve, so book early. If a session is full, join the waitlist and we will write as soon as a seat opens.',
				'img:coral-square',
			),
		),
		'Meet the anthurium, a tropical that blooms all year' => array(
			'theo', 'Plant Care', array( 'houseplants', 'tropicals' ), 'publish', 21, 'category-anthuriums',
			'Those glossy red hearts are not petals, and with bright indirect light they keep coming all year.',
			array(
				'The red "flower" of an anthurium is a spathe, a modified leaf, and the little spike in the middle is where the real flowers are. That is why each one lasts for weeks.',
				'img:category-anthuriums',
				'## Light and water',
				'Give it bright, indirect light, close to an east window or a few feet back from a south one. Water when the top two inches of soil are dry, and mist or group it with other plants if your home is dry in winter.',
				'## Getting more blooms',
				'Feed every month from spring to fall with a fertilizer that is higher in phosphorus, and do not rush to repot: a slightly snug anthurium flowers more freely.',
			),
		),
		'Sowing sunflowers with kids' => array(
			'priya', 'Seasonal', array( 'seeds', 'kids', 'sunflowers' ), 'publish', 30, 'category-sunflowers',
			'Big seeds, fast sprouts and a plant taller than the gardener: sunflowers are the best first crop.',
			array(
				'Sunflower seeds are big enough for small hands, they sprout in under a week, and by the end of summer the plant towers over everyone. We sow them with every kids workshop.',
				'- Sow two seeds per pot, a knuckle deep, after the last frost.' . "\n" . '- Keep the soil damp until they sprout, then water when the top feels dry.' . "\n" . '- Plant out when they have four true leaves, in the sunniest spot you have.',
				'The Teddy Bear variety stays under three feet and makes fluffy double flowers, which suits a patio pot. For a giant, choose Russian Mammoth and give it a stake.',
			),
		),
		'Why we switched to peat-free potting mix' => array(
			'june', 'Shop News', array( 'soil', 'sustainability' ), 'publish', 41, '',
			'Every bag of mix we sell is now peat-free. Here is why, and what changes for your plants.',
			array(
				'Peat bogs take thousands of years to form and store a huge amount of carbon. Digging them up for potting soil releases it. This spring we moved every mix we sell, and everything we pot in the shop, to peat-free blends.',
				'## What is in it instead',
				'Our mix is a blend of composted bark, coir and a little perlite. It drains well and holds nutrients, but it dries out a touch faster at the surface, so check with a finger rather than by looking.',
				'If you have any questions about switching your own plants over, ask at the counter or write to us. We are happy to talk soil for as long as you like.',
			),
		),
		'A field guide to our wildflower seed mix' => array(
			'theo', 'Seasonal', array( 'seeds', 'pollinator-friendly', 'meadow' ), 'publish', 55, 'flower-meadow-square',
			'Fourteen native annuals and perennials, and what to expect in the first year and the second.',
			array(
				'The meadow mix started as a single bed behind the shop. Customers kept asking what was growing in it, so we started packing the seed.',
				'img:flower-meadow-square',
				'## Year one',
				'The annuals carry the first summer: California poppy, Chinese houses and farewell-to-spring flower first, then blanket flower takes over into autumn.',
				'## Year two and after',
				'The perennials settle in underneath. Expect lupine, columbine and yarrow from the second spring, and leave the seed heads standing through winter for the birds.',
			),
		),
		'Botanical prints are back in stock' => array(
			'june', 'Shop News', array( 'prints' ), 'publish', 63, 'delphinium-flowers',
			'The archive print series is back: bellflower, delphinium and thistle, in three sizes.',
			array(
				'The black and white botanical prints sold out in a week last winter. They are back, printed on heavy cotton rag paper by a small studio across the river.',
				'img:delphinium-flowers',
				'Each print comes in three sizes. Frames are not included, but we can recommend a framer nearby who knows the sizes.',
			),
		),
		'Repotting without the mess' => array(
			'hana', 'Plant Care', array( 'repotting', 'beginner-friendly' ), 'publish', 78, '',
			'A tarp, a chopstick and a pot one size up. Our step by step for repotting at a kitchen table.',
			array(
				'Spring is the time to repot, when plants are about to grow and can settle into new soil quickly. Most only need a pot one size up, about two inches wider.',
				'- Water the day before, so the root ball slides out in one piece.' . "\n" . '- Tease out circling roots with your fingers or a chopstick.' . "\n" . '- Set the plant at the same depth it was before and fill around it.' . "\n" . '- Water well and keep it out of strong sun for a week.',
				'Lay down an old shower curtain or a tarp first. Everything you spill goes back in the bag.',
			),
		),
		'Notes from the potting bench' => array(
			'june', 'Shop News', array( 'shop' ), 'publish', 90, 'typewriter',
			'Three years in: what we have learned, what we got wrong and what is next for the shop.',
			array(
				'Larkspur & Fern opened three years ago with forty plants, a borrowed cash register and a bench that is still the heart of the shop.',
				'img:typewriter',
				'Since then we have added seeds, prints and workshops, and started shipping plants across the country. We got a few things wrong along the way, packing tropicals in January among them, and you were patient with us every time.',
				'Next up: a plant club with a new plant every month, and more workshops for kids. Thank you for being here.',
			),
		),
		'Overwintering hibiscus indoors' => array(
			'theo', 'Seasonal', array( 'tropicals', 'winter' ), 'draft', 1, 'red-hibiscus-closeup',
			'',
			array(
				'Tropical hibiscus will not survive a frost, but it makes a fine houseplant for the winter if you bring it in before nights drop below fifty degrees.',
				'Check the leaves for aphids and whitefly before it comes inside, cut it back by a third, and give it the sunniest window you have.',
			),
		),
		'Terrarium basics for beginners' => array(
			'hana', 'Workshops', array( 'terrariums', 'beginner-friendly' ), 'pending', 2, 'coral-square',
			'',
			array(
				'A closed terrarium is a small water cycle: moisture rises from the soil, condenses on the glass and runs back down.',
				'- A layer of gravel for drainage.' . "\n" . '- A thin layer of activated charcoal.' . "\n" . '- Two inches of mix, then small plants that like humidity: fittonia, baby tears, moss.',
			),
		),
		'Fall bulbs to plant before the first frost' => array(
			'theo', 'Seasonal', array( 'bulbs', 'autumn' ), 'future', -5, 'northern-buttercups-flowers',
			'Tulips, crocus and alliums go in now for a spring that starts in February.',
			array(
				'The best spring gardens are planted in autumn. Bulbs need a cold spell to flower, so they go in the ground once the soil has cooled, usually from late October.',
				'- Plant three times as deep as the bulb is tall, pointy end up.' . "\n" . '- Plant in groups of seven or more for a natural look.' . "\n" . '- Water once after planting, then let the rain do the rest.',
			),
		),
	);
	$n = 0;
	foreach ( $posts as $title => $p ) {
		list( $who, $cat, $tags, $status, $days, $image, $excerpt, $body ) = $p;
		$demo->as_user( $who );
		$ts = $demo->ago( $days, 8 + $n % 9, ( $n * 23 ) % 60 );
		$id = wp_insert_post(
			array(
				'post_title'    => $title,
				'post_content'  => $blocks( $body ),
				'post_excerpt'  => $excerpt,
				'post_status'   => $status,
				'post_author'   => $demo->id( 'users', $who ),
				'post_category' => array( $cats[ $cat ] ),
				'tags_input'    => $tags,
				'post_date'     => $demo->local( $ts ),
				'post_date_gmt' => 'draft' === $status || 'pending' === $status ? '0000-00-00 00:00:00' : $demo->gmt( $ts ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			WP_CLI::error( $title . ': ' . $id->get_error_message() );
		}
		if ( $image ) {
			set_post_thumbnail( $id, $demo->image( $image ) );
		}
		$demo->remember( 'posts', sanitize_title( $title ), $id );
		++$n;
	}

	// title => body, parent slug, menu order
	$pages = array(
		'About'              => array( array( 'Larkspur & Fern is a small plant shop on Station Road in Portland. We grow most of our perennials ourselves, pack every order by hand, and answer every plant question that comes our way.', 'img:location', '## The people', 'June runs the shop, Theo writes the journal, Priya teaches the workshops and Mateo packs the orders. Hana writes about plants for small spaces.' ), '', 1 ),
		'Visit the Shop'     => array( array( '112 Station Road, Portland, Oregon.', '- Tuesday to Friday, 10am to 6pm' . "\n" . '- Saturday, 9am to 5pm' . "\n" . '- Sunday and Monday, closed', 'Street parking is free on weekends. The shop is step-free from the side door.' ), '', 2 ),
		'Workshops'          => array( array( 'Small classes on Saturday mornings: terrariums, propagation, kokedama and a sunflower morning for kids. Every session includes materials and a plant to take home.', 'img:coral-square', 'Book a seat in the shop, or join the waitlist for a full session.' ), '', 3 ),
		'Plant Club'         => array( array( 'A new plant every month, chosen by us, with a care card and a pot that fits. Members also get ten percent off in the shop and first pick of workshop seats.' ), '', 4 ),
		'Wholesale'          => array( array( 'We supply florists, cafes and offices around Portland with plants and weekly care visits. Tell us about your space and we will put together a quote.' ), '', 5 ),
		'FAQ'                => array( array( '## Do you ship live plants?', 'Yes, anywhere in the continental US, Monday to Wednesday so nothing sits in a warehouse over a weekend.', '## Can I return a plant?', 'If a plant arrives damaged, send us a photo within three days and we will replace it.' ), '', 6 ),
		'Shipping & Returns' => array( array( 'Orders ship within two business days. Shipping is a flat eight dollars and free on orders over seventy-five.', 'Seeds, prints and gift cards can be returned unopened within thirty days.' ), 'faq', 7 ),
		'Contact'            => array( array( 'Questions about an order, a plant or a workshop? Write to hello@larkspur.example or use the form below, and we will answer within a day.' ), '', 8 ),
	);
	$demo->as_user( 'june' );
	foreach ( $pages as $title => $p ) {
		list( $body, $parent, $order ) = $p;
		$id = wp_insert_post(
			array(
				'post_title'   => $title,
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => $blocks( $body ),
				'post_parent'  => $parent ? $demo->id( 'pages', $parent ) : 0,
				'menu_order'   => $order,
				'post_author'  => $demo->id( 'users', 'june' ),
				'post_date'    => $demo->local( $demo->ago( 120 - $order * 3, 9, $order * 7 ) ),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			WP_CLI::error( $title . ': ' . $id->get_error_message() );
		}
		$demo->remember( 'pages', sanitize_title( $title ), $id );
	}

	// Comments: post => [ who, days ago, text, status, replies [ who, hours later, text ] ]
	$threads = array(
		'how-often-should-you-really-water-a-cactus' => array(
			array( 'olivia', 2.6, 'This explains so much. I was watering mine every Sunday out of habit and it went soft at the base last winter.', '1', array( array( 'theo', 3, 'That is the most common story we hear! Next time try the skewer test before each watering.' ) ) ),
			array( 'marcus', 2.1, 'Does the same go for the small succulents in the mixed bowls?', '1', array( array( 'theo', 5, 'Mostly, yes. Bowls without a drainage hole need even less: a few spoonfuls at the base every couple of weeks.' ) ) ),
			array( 'kenji', 0.4, 'What about a cactus under a grow light in winter? Mine still seems to be growing.', '0', array() ),
		),
		'five-houseplants-that-forgive-a-missed-watering' => array(
			array( 'aisha', 8.2, 'Can confirm the peace lily drama. Mine faints every time I travel and is fine an hour after I get home.', '1', array() ),
			array( 'grace', 6.5, 'Would love a follow-up on plants that are safe for cats.', '1', array( array( 'hana', 20, 'Great idea, it is on the list for next month.' ) ) ),
		),
		'the-spring-workshop-calendar-is-open' => array(
			array( 'sofia', 12.3, 'Booked the terrarium session! Is there parking nearby?', '1', array( array( 'priya', 2, 'Free street parking on Saturdays, and the side door is step-free. See you there!' ) ) ),
			array( 'nadia', 0.8, 'Will the kokedama class run again in the summer? The March date is full.', '0', array() ),
		),
		'meet-the-anthurium-a-tropical-that-blooms-all-year' => array(
			array( 'liam', 19.4, 'Mine has not bloomed since I bought it. I think it needs more light, it is in a north window.', '1', array( array( 'theo', 6, 'That is likely it. Try a spot closer to an east window and give it a month.' ) ) ),
		),
		'sowing-sunflowers-with-kids' => array(
			array( 'ben', 27.1, 'We did this in May and the kids measured them every week. The tallest got to nine feet.', '1', array() ),
			array( 'ethan', 25.5, 'Best cheap crop for a five year old, agreed.', '1', array() ),
		),
		'why-we-switched-to-peat-free-potting-mix' => array(
			array( 'mia', 38.0, 'Thank you for doing this. Is the mix fine for seedlings too?', '1', array( array( 'june', 4, 'It is! Sieve it for very fine seed, and keep the surface a little damper.' ) ) ),
		),
	);
	$c = 0;
	foreach ( $threads as $post_slug => $comments ) {
		$post_id = $demo->id( 'posts', $post_slug );
		foreach ( $comments as $row ) {
			list( $slug, $days, $text, $status, $replies ) = $row;
			$p  = $demo->person( $slug );
			$ts = $demo->ago( $days, 7 + $c % 14, ( $c * 13 ) % 60 );
			$id = wp_insert_comment(
				array(
					'comment_post_ID'      => $post_id,
					'comment_author'       => $p['name'],
					'comment_author_email' => $p['email'],
					'comment_author_IP'    => '192.0.2.' . ( 10 + $c ),
					'comment_agent'        => 'Mozilla/5.0',
					'comment_content'      => $text,
					'comment_approved'     => $status,
					'user_id'              => $p['id'],
					'comment_date'         => $demo->local( $ts ),
					'comment_date_gmt'     => $demo->gmt( $ts ),
				)
			);
			++$c;
			foreach ( $replies as $reply ) {
				list( $who, $hours, $answer ) = $reply;
				$staff = $demo->person( $who );
				$rts   = $ts + $hours * HOUR_IN_SECONDS;
				wp_insert_comment(
					array(
						'comment_post_ID'      => $post_id,
						'comment_parent'       => $id,
						'comment_author'       => $staff['name'],
						'comment_author_email' => $staff['email'],
						'comment_content'      => $answer,
						'comment_approved'     => '1',
						'user_id'              => $staff['id'],
						'comment_date'         => $demo->local( $rts ),
						'comment_date_gmt'     => $demo->gmt( $rts ),
					)
				);
				++$c;
			}
		}
	}
	$spam_ts = $demo->ago( 1, 3, 12 );
	wp_insert_comment(
		array(
			'comment_post_ID'      => $demo->id( 'posts', 'how-often-should-you-really-water-a-cactus' ),
			'comment_author'       => 'Best SEO Deals',
			'comment_author_email' => 'deals@seo-offers.invalid',
			'comment_author_url'   => 'https://seo-offers.invalid/',
			'comment_author_IP'    => '198.51.100.23',
			'comment_content'      => 'Great post!!! Get 10,000 backlinks today for only $9, guaranteed first page on Google.',
			'comment_approved'     => 'spam',
			'comment_date'         => $demo->local( $spam_ts ),
			'comment_date_gmt'     => $demo->gmt( $spam_ts ),
		)
	);
	foreach ( $demo->ids( 'posts' ) as $id ) {
		wp_update_comment_count_now( $id );
	}
	$demo->log( count( $demo->ids( 'posts' ) ) . ' posts, ' . count( $demo->ids( 'pages' ) ) . ' pages, ' . ( $c + 1 ) . ' comments' );
};
