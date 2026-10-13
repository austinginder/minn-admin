<?php
/**
 * The media library: photos from Twenty Twenty-Five's bundled set, all CC0
 * (see that theme's readme.txt). Nothing is downloaded, so the seed runs
 * offline and every run imports the same files.
 *
 * @package minn-admin
 */

return function ( Minn_Demo_Seed $demo ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	foreach ( $demo->ids( 'images' ) as $id ) {
		wp_delete_attachment( $id, true );
	}
	$demo->forget( 'images' );

	$dir = get_theme_root() . '/twentytwentyfive/assets/images/';
	if ( ! is_dir( $dir ) ) {
		WP_CLI::error( 'The media part reads its photos from Twenty Twenty-Five, which this site does not have.' );
	}
	// file => title, alt text, caption, who uploaded it, days ago.
	$photos = array(
		'category-anthuriums'            => array( 'Anthurium, red heart', 'A single red anthurium flower against dark leaves', 'Our best-selling anthurium, photographed in the shop window.', 'mateo', 80 ),
		'botany-flowers'                 => array( 'Peace lilies on the bench', 'Peace lilies with white spathes and long green leaves', '', 'mateo', 79 ),
		'botany-flowers-closeup'         => array( 'Calla lilies, close', 'Close view of calla lily blooms opening', '', 'mateo', 79 ),
		'category-cactus'                => array( 'Golden saguaro', 'A tall ribbed cactus in black and white', '', 'mateo', 78 ),
		'category-sunflowers'            => array( 'Teddy Bear sunflower', 'A soft yellow sunflower on a pale background', '', 'mateo', 78 ),
		'red-hibiscus-closeup'           => array( 'Red hibiscus', 'A red hibiscus flower with a long stamen', '', 'mateo', 77 ),
		'grid-flower-1'                  => array( 'Hibiscus in the greenhouse', 'Red hibiscus bloom against a dark background', '', 'theo', 70 ),
		'dallas-creek-square'            => array( 'Wild columbine', 'Red and yellow columbine flowers nodding on thin stems', '', 'mateo', 76 ),
		'malibu-plantlife'               => array( 'Lupine, Gallery Blue', 'Purple lupine flowers up close', '', 'mateo', 76 ),
		'akaka-falls-state-park-flora'   => array( 'Pink impatiens', 'Bright pink impatiens with green leaves', '', 'mateo', 75 ),
		'northern-buttercups-flowers'    => array( 'Iceland poppies', 'A yellow poppy among grasses', 'Grown from our own seed packets.', 'theo', 74 ),
		'flower-meadow-square'           => array( 'Wildflower meadow', 'A meadow of yellow and red wildflowers under a single tree', 'What a season of the meadow mix looks like.', 'theo', 66 ),
		'coming-soon-bg-image'           => array( 'Meadow in June', 'A wide meadow of wildflowers with a tree on the horizon', '', 'theo', 62 ),
		'campanula-alliariifolia-flower' => array( 'Botanical print: bellflower', 'Black and white photograph of a bellflower bloom', 'From the archive print series.', 'june', 64 ),
		'delphinium-flowers'             => array( 'Botanical print: delphinium', 'Black and white photograph of delphinium stems', 'From the archive print series.', 'june', 64 ),
		'star-thristle-flower'           => array( 'Botanical print: thistle', 'Black and white photograph of a dried thistle head', 'From the archive print series.', 'june', 64 ),
		'grid-flower-2'                  => array( 'Botanical print: phacelia', 'Black and white photograph of a curling phacelia stem', 'From the archive print series.', 'june', 64 ),
		'404-image'                      => array( 'Wind-shaped tree', 'A lone tree bent by the wind on a grassy ridge', '', 'theo', 58 ),
		'marshland-birds-square'         => array( 'Marsh at sunset', 'Two wading birds silhouetted against an orange sky', '', 'theo', 45 ),
		'typewriter'                     => array( 'The potting bench typewriter', 'A person typing at a manual typewriter', '', 'theo', 40 ),
		'location'                       => array( 'Map of the neighborhood', 'An old street map of a city center', '', 'june', 90 ),
		'coral-square'                   => array( 'Moss and fern study', 'Green and blue underwater plant life', '', 'priya', 30 ),
		'link-in-bio-image'              => array( 'Garden path in winter', 'Two people walking down a tree-lined path', '', 'priya', 21 ),
		'hero-podcast'                   => array( 'Episode art: The Potting Bench', 'Portrait for the podcast episode cover', '', 'priya', 12 ),
	);
	$n = 0;
	foreach ( $photos as $file => $p ) {
		list( $title, $alt, $caption, $who, $days ) = $p;
		$demo->as_user( $who );
		// media_handle_sideload moves the file it is given: hand it a copy.
		$tmp = wp_tempnam( $file . '.webp' );
		copy( $dir . $file . '.webp', $tmp );
		$id = media_handle_sideload(
			array(
				'name'     => $file . '.webp',
				'tmp_name' => $tmp,
			),
			0,
			$title,
			array(
				'post_excerpt' => $caption,
				'post_author'  => get_current_user_id(),
				'post_date'    => $demo->local( $demo->ago( $days, 11, $n * 3 ) ),
			)
		);
		if ( is_wp_error( $id ) ) {
			WP_CLI::error( $file . ': ' . $id->get_error_message() );
		}
		update_post_meta( $id, '_wp_attachment_image_alt', $alt );
		$demo->remember( 'images', $file, $id );
		++$n;
	}
	$demo->log( $n . ' images' );
};
