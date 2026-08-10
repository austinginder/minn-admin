<?php
/**
 * Jetpack Tiled Gallery — image-block adapter.
 *
 * The tiled gallery keeps a LAYOUT, not a list: photos are packed into columns
 * whose widths come from their aspect ratios, and that packing is Jetpack's
 * rule, not ours. Minn's generic editing can reorder and replace within the
 * openings a gallery already has (the layout never moves), but adding or
 * removing a photo means laying the whole thing out again — which is what this
 * adapter does, and why it exists at all.
 *
 * The layout written here is the honest subset of what Jetpack's editor
 * produces: one photo per column, rows of at most `columns`, column widths in
 * proportion to each photo's aspect ratio so a row fills its width exactly.
 * Jetpack's own editor sometimes stacks two photos in one column; that variant
 * is theirs to make, and a gallery built there keeps whatever it has — Minn
 * only rebuilds when the writer adds or removes a photo.
 *
 * @package Minn_Admin
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the block with Minn's image-block extension point.
 *
 * Only when the block is actually registered: without Jetpack the markup would
 * have nothing to render it.
 */
add_filter(
	'minn_admin_image_blocks',
	function ( $blocks ) {
		if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
			return $blocks;
		}
		$registry = WP_Block_Type_Registry::get_instance();
		if ( ! $registry->get_registered( 'jetpack/tiled-gallery' ) ) {
			return $blocks;
		}
		$blocks['jetpack/tiled-gallery'] = array(
			'label'   => __( 'Tiled Gallery', 'minn-admin' ),
			'insert'  => true,
			'rebuild' => 'minn_admin_tiled_gallery_rebuild',
		);
		return $blocks;
	}
);

/**
 * Attributes worth carrying from an existing gallery into its rebuilt self.
 *
 * Everything here is a CHOICE the writer made (style, filter, link behaviour);
 * the layout attributes are recomputed and deliberately not carried.
 *
 * @param string $raw Existing block markup, empty when creating one.
 * @return array{attrs:array,class:string}
 */
function minn_admin_tiled_gallery_existing( $raw ) {
	$out = array(
		'attrs' => array(),
		'class' => 'wp-block-jetpack-tiled-gallery aligncenter is-style-rectangular',
	);
	if ( '' === trim( (string) $raw ) ) {
		return $out;
	}
	if ( ! preg_match( '#^<!--\s*wp:jetpack/tiled-gallery\s*(\{.*?\})?\s*-->#s', $raw, $m ) ) {
		return $out;
	}
	$attrs = ( isset( $m[1] ) && '' !== $m[1] ) ? json_decode( $m[1], true ) : array();
	if ( ! is_array( $attrs ) ) {
		$attrs = array();
	}
	$keep = array( 'align', 'className', 'columns', 'imageFilter', 'linkTo', 'roundedCorners', 'style', 'backgroundColor', 'gradient' );
	foreach ( $keep as $k ) {
		if ( isset( $attrs[ $k ] ) ) {
			$out['attrs'][ $k ] = $attrs[ $k ];
		}
	}
	// The wrapper's own classes are markup, not attributes — reuse them so a
	// rebuilt gallery keeps the style it was wearing.
	if ( preg_match( '#<div class="(wp-block-jetpack-tiled-gallery[^"]*)"#', $raw, $cm ) ) {
		$out['class'] = $cm[1];
	}
	return $out;
}

/**
 * Jetpack's own row/column chooser, ported.
 *
 * The block's save() recomputes this layout from the images every time, and
 * the block editor compares saved markup against that output byte for byte —
 * so anything Minn writes has to make the SAME choices. This is a transcription
 * of the chooser in Jetpack's editor bundle (extensions/blocks/tiled-gallery),
 * kept deliberately literal: same shape vocabulary, same order of tests, same
 * thresholds. tests/jetpack-tiled-gallery.oracle.js checks it against the real
 * thing by asking the block editor what it would have saved.
 *
 * @param float[] $ratios  Width/height per image, in order.
 * @param bool    $is_wide Wide or full alignment.
 * @return array[] One entry per row; each row is a list of column sizes.
 */
function minn_admin_tiled_gallery_shapes( $ratios, $is_wide ) {
	// Ratio predicates.
	$landscape = function ( $r ) { return $r >= 1 && $r < 2; };            // E
	$portrait  = function ( $r ) { return $r < 1; };                       // N
	$squarish  = function ( $r ) { return $r >= 0.9 && $r < 2; };          // overEvery( T(.9), B(2) )
	$narrow    = function ( $r ) { return $r < 1.6; };                     // B(1.6)
	$panorama  = function ( $r ) { return $r >= 2; };
	// The next images match these predicates, in order.
	$next = function ( $preds ) use ( &$ratios ) {
		return function ( $rest ) use ( $preds ) {
			if ( count( $rest ) < count( $preds ) ) {
				return false;
			}
			foreach ( $preds as $i => $pred ) {
				if ( ! $pred( $rest[ $i ] ) ) {
					return false;
				}
			}
			return true;
		};
	};
	// This shape has not been used in the last $n rows.
	$fresh = function ( $shape, $n ) {
		return function ( $used ) use ( $shape, $n ) {
			foreach ( array_slice( $used, -$n ) as $prev ) {
				if ( $prev === $shape ) {
					return false;
				}
			}
			return true;
		};
	};

	$two_one_two   = $fresh( array( 2, 1, 2 ), 5 );
	$is_ll_p_ll    = $next( array( $landscape, $landscape, $portrait, $landscape, $landscape ) );
	$is_lll_p_lll  = $next( array( $landscape, $landscape, $landscape, $portrait, $landscape, $landscape, $landscape ) );
	$three_one_three = $fresh( array( 3, 1, 3 ), 5 );
	$is_p_ll_p     = $next( array( $portrait, $landscape, $landscape, $portrait ) );
	$one_two_one   = $fresh( array( 1, 2, 1 ), 5 );
	$is_p_lll      = $next( array( $portrait, $landscape, $landscape, $landscape ) );
	$one_three     = $fresh( array( 1, 3 ), 3 );
	$is_lll_p      = $next( array( $landscape, $landscape, $landscape, $portrait ) );
	$three_one     = $fresh( array( 3, 1 ), 3 );
	$is_n_sq_sq    = $next( array( $narrow, $squarish, $squarish ) );
	$one_two       = $fresh( array( 1, 2 ), 3 );
	$five_ones     = $fresh( array( 1, 1, 1, 1, 1 ), 1 );
	$four_ones     = $fresh( array( 1, 1, 1, 1 ), 1 );
	$three_ones    = $fresh( array( 1, 1, 1 ), 3 );
	$is_sq_sq_n    = $next( array( $squarish, $squarish, $narrow ) );
	$two_one       = $fresh( array( 2, 1 ), 3 );
	$is_panorama   = $next( array( $panorama ) );

	$sum = function ( $arr ) {
		return array_sum( $arr );
	};

	$used = array();
	$rest = array_values( $ratios );
	// A runaway guard: every branch consumes at least one image, but the loop
	// bound keeps a malformed set from spinning.
	$guard = 0;
	while ( $rest && $guard++ < 1000 ) {
		$n = count( $rest );
		if ( $n > 15 && $is_ll_p_ll( $rest ) && $two_one_two( $used ) ) {
			$shape = array( 2, 1, 2 );
		} elseif ( $n > 15 && $is_lll_p_lll( $rest ) && $three_one_three( $used ) ) {
			$shape = array( 3, 1, 3 );
		} elseif ( 5 !== $n && $is_p_ll_p( $rest ) && $one_two_one( $used ) ) {
			$shape = array( 1, 2, 1 );
		} elseif ( $is_p_lll( $rest ) && $one_three( $used ) ) {
			$shape = array( 1, 3 );
		} elseif ( $is_lll_p( $rest ) && $three_one( $used ) ) {
			$shape = array( 3, 1 );
		} elseif ( $is_n_sq_sq( $rest ) && $one_two( $used ) ) {
			$shape = array( 1, 2 );
		} elseif ( $is_wide && ( 5 === $n || ( 10 !== $n && $n > 6 ) ) && $five_ones( $used ) && $sum( array_slice( $rest, 0, 5 ) ) < 5 ) {
			$shape = array( 1, 1, 1, 1, 1 );
		} elseif ( ( $four_ones( $used ) && $sum( array_slice( $rest, 0, 4 ) ) < 3.5 && $n > 5 ) || ( $sum( array_slice( $rest, 0, 4 ) ) < 7 && 4 === $n ) ) {
			$shape = array( 1, 1, 1, 1 );
		} elseif ( $n >= 3 && 4 !== $n && 6 !== $n && $three_ones( $used )
			&& ( $sum( array_slice( $rest, 0, 3 ) ) < 2.5
				|| ( $sum( array_slice( $rest, 0, 3 ) ) < 5 && $n >= 3 && $rest[0] === $rest[2] )
				|| $is_wide ) ) {
			$shape = array( 1, 1, 1 );
		} elseif ( $is_sq_sq_n( $rest ) && $two_one( $used ) ) {
			$shape = array( 2, 1 );
		} elseif ( $is_panorama( $rest ) ) {
			$shape = array( 1 );
		} elseif ( $n > 3 ) {
			$shape = array( 1, 1 );
		} else {
			$shape = array_fill( 0, $n, 1 );
		}
		$used[] = $shape;
		$rest   = array_slice( $rest, $sum( $shape ) );
	}
	return $used;
}

/**
 * The "columns" layout style: even-ish rows of `columns`, by cumulative ratio.
 *
 * @param float[] $ratios  Width/height per image.
 * @param int     $columns Column count.
 * @return array[] One row.
 */
function minn_admin_tiled_gallery_column_shapes( $ratios, $columns ) {
	$n = count( $ratios );
	if ( $n <= $columns ) {
		return array( array_fill( 0, $n, 1 ) );
	}
	$per   = array_sum( $ratios ) / $columns;
	$rest  = array_values( $ratios );
	$shape = array();
	$acc   = 0;
	for ( $c = 0; $c < $columns - 1; $c++ ) {
		$take = 0;
		foreach ( $rest as $r ) {
			if ( $acc <= ( $c + 1 ) * $per ) {
				$acc += $r;
				$take++;
				continue;
			}
			break;
		}
		$shape[] = $take;
		$rest    = array_slice( $rest, $take );
	}
	$shape[] = count( $rest );
	return array( $shape );
}

/**
 * A column's aspect: the harmonic-style combination its own images give it.
 *
 * @param float[] $ratios Ratios of the images stacked in this column.
 * @return float
 */
function minn_admin_tiled_gallery_col_ratio( $ratios ) {
	$inv = 0;
	foreach ( $ratios as $r ) {
		$inv += $r > 0 ? ( 1 / $r ) : 1;
	}
	return $inv > 0 ? ( 1 / $inv ) : 1;
}

/**
 * Build tiled-gallery markup for a set of images.
 *
 * @param array  $images Resolved attachments: id, url, width, height, alt, link.
 * @param string $raw    The gallery being replaced, or '' when creating one.
 * @return string Whole block markup.
 */
function minn_admin_tiled_gallery_rebuild( $images, $raw = '' ) {
	$prev    = minn_admin_tiled_gallery_existing( $raw );
	$attrs   = $prev['attrs'];
	$columns = isset( $attrs['columns'] ) ? max( 1, min( 6, (int) $attrs['columns'] ) ) : 3;
	$filter  = isset( $attrs['imageFilter'] ) && is_string( $attrs['imageFilter'] ) ? $attrs['imageFilter'] : '';
	$link_to = isset( $attrs['linkTo'] ) && is_string( $attrs['linkTo'] ) ? $attrs['linkTo'] : '';

	// Ratios drive everything: which images share a row, how a row divides.
	$ratios = array();
	foreach ( $images as $img ) {
		$w        = (int) $img['width'];
		$h        = (int) $img['height'];
		$ratios[] = ( $w > 0 && $h > 0 ) ? ( $w / $h ) : 1.0;
	}
	$is_wide = in_array( isset( $attrs['align'] ) ? $attrs['align'] : '', array( 'wide', 'full' ), true );
	$is_cols = false !== strpos( $prev['class'], 'is-style-columns' );
	$shapes  = $is_cols
		? minn_admin_tiled_gallery_column_shapes( $ratios, $columns )
		: minn_admin_tiled_gallery_shapes( $ratios, $is_wide );

	$widths    = array();
	$rows_html = '';
	$at        = 0;
	foreach ( $shapes as $shape ) {
		$col_ratios = array();
		$col_images = array();
		foreach ( $shape as $size ) {
			$slice        = array_slice( $images, $at, $size );
			$col_images[] = $slice;
			$col_ratios[] = minn_admin_tiled_gallery_col_ratio( array_slice( $ratios, $at, $size ) );
			$at          += $size;
		}
		$total      = array_sum( $col_ratios );
		$total      = $total > 0 ? $total : count( $col_ratios );
		$row_widths = array();
		$cols_html  = '';
		foreach ( $col_images as $i => $slice ) {
			$pct          = ( $col_ratios[ $i ] / $total ) * 100;
			$pct_s        = number_format( $pct, 5, '.', '' );
			$row_widths[] = $pct_s;
			$items        = '';
			foreach ( $slice as $img ) {
				$items .= minn_admin_tiled_gallery_item( $img, $filter, $link_to );
			}
			$cols_html .= '<div class="tiled-gallery__col" style="flex-basis:' . $pct_s . '%">' . $items . '</div>';
		}
		$widths[]   = $row_widths;
		$rows_html .= '<div class="tiled-gallery__row">' . $cols_html . '</div>';
	}

	$attrs['columns']      = $columns;
	$attrs['columnWidths'] = $widths;
	$attrs['ids']          = array_map( 'intval', wp_list_pluck( $images, 'id' ) );
	ksort( $attrs );

	// serialize_block_attributes applies Gutenberg's own comment-safe escaping
	// ("--", <, >, &), which is what the block editor compares against.
	$json = serialize_block_attributes( $attrs );

	return '<!-- wp:jetpack/tiled-gallery ' . $json . " -->\n"
		. '<div class="' . esc_attr( $prev['class'] ) . '"><div class=""><div class="tiled-gallery__gallery">'
		. $rows_html
		. '</div></div></div>' . "\n"
		. '<!-- /wp:jetpack/tiled-gallery -->';
}

/**
 * One photo in its opening, in the attribute order Jetpack's editor writes.
 *
 * @param array  $img     Resolved attachment.
 * @param string $filter  imageFilter attribute, '' for none.
 * @param string $link_to linkTo attribute.
 * @return string
 */
function minn_admin_tiled_gallery_item( $img, $filter, $link_to ) {
	$class = 'tiled-gallery__item' . ( $filter ? ' filter__' . sanitize_html_class( $filter ) : '' );
	$tag   = '<img alt="' . esc_attr( $img['alt'] ) . '"'
		. ' data-height="' . (int) $img['height'] . '"'
		. ' data-id="' . (int) $img['id'] . '"'
		. ' data-link="' . esc_url( $img['link'] ) . '"'
		. ' data-url="' . esc_url( $img['url'] ) . '"'
		. ' data-width="' . (int) $img['width'] . '"'
		. ' src="' . esc_url( $img['url'] ) . '"'
		// Jetpack's own save writes this on every image; the block editor
		// compares saved markup against that output byte for byte, so an
		// omission here is an "unexpected or invalid content" warning.
		. ' data-amp-layout="responsive"/>';
	if ( 'attachment' === $link_to ) {
		$tag = '<a href="' . esc_url( $img['link'] ) . '">' . $tag . '</a>';
	} elseif ( 'media' === $link_to ) {
		$tag = '<a href="' . esc_url( $img['url'] ) . '">' . $tag . '</a>';
	}
	return '<figure class="' . esc_attr( $class ) . '">' . $tag . '</figure>';
}
