<?php
/**
 * Code snippets the shop really runs, spread across every snippet plugin the
 * demo has: Code Snippets, WPCode, FluentSnippets, Simple Custom CSS and JS
 * and Header Footer Code Manager. Workshop and checkout tweaks, product grid
 * CSS, a privacy-friendly analytics tag, a shipping notice, XML-RPC off, a
 * workshop countdown shortcode; some running, some switched off.
 *
 * Every active PHP snippet is a closure on a filter or action that only
 * changes shop output, never the admin, REST or login, so the site and Minn
 * keep working however often this runs. No named functions: Code Snippets
 * executes active PHP once while saving, in this very process.
 *
 * Storage facts worth knowing on a rerun:
 * - Code Snippets saves through save_snippet(), which stamps `modified` with
 *   now (UTC); the history is written back afterwards. Its four bundled
 *   examples belong to the plugin: kept, only dated back to opening day.
 * - WPCode snippets are `wpcode` posts (draft = inactive) with their type and
 *   location as terms; the auto-insert cache option is rebuilt after. Saving
 *   an active PHP snippet test-runs it, unslashing the code only when its
 *   form field is in $_POST, so that is set for the save (or it stays off).
 * - FluentSnippets keeps each snippet as a PHP file in
 *   wp-content/fluent-snippet-storage with its settings in a docblock header,
 *   plus an index it rebuilds; its create helper always saves a draft, so the
 *   running ones get their header's status flipped, as its toggle does.
 * - Simple Custom CSS and JS only saves from its own edit screen; the files
 *   and search tree are written with the same calls Minn's adapter uses.
 * - Header Footer Code Manager has no API: rows in {prefix}hfcm_scripts,
 *   dates site-local.
 *
 * @package minn-admin
 */

return function ( Minn_Demo_Seed $demo ) {
	global $wpdb;
	$p    = $wpdb->prefix;
	$list = function ( $key ) use ( $demo ) {
		$v = $demo->id( 'snippets', $key );
		return is_array( $v ) ? $v : array();
	};

	// Clear what an earlier run made.
	if ( function_exists( 'Code_Snippets\delete_snippet' ) ) {
		foreach ( array_filter( array_map( 'intval', $list( 'code_snippets' ) ) ) as $id ) {
			\Code_Snippets\delete_snippet( $id );
		}
	}
	foreach ( array_filter( array_map( 'intval', $list( 'wpcode' ) ) ) as $id ) {
		if ( 'wpcode' === get_post_type( $id ) ) {
			wp_delete_post( $id, true );
		}
	}
	if ( class_exists( 'FluentSnippets\App\Model\Snippet' ) ) {
		$model = new \FluentSnippets\App\Model\Snippet();
		foreach ( $list( 'fluent' ) as $file ) {
			if ( is_string( $file ) && preg_match( '/^[0-9]+-[a-z0-9-]+\.php$/', $file ) ) {
				$model->deleteSnippet( $file );
			}
		}
	}
	foreach ( array_filter( array_map( 'intval', $list( 'ccj' ) ) ) as $id ) {
		if ( 'custom-css-js' === get_post_type( $id ) ) {
			if ( function_exists( 'minn_admin_ccj_drop_files' ) ) {
				minn_admin_ccj_drop_files( $id );
			}
			wp_delete_post( $id, true );
		}
	}
	$hfcm_old = array_filter( array_map( 'intval', $list( 'hfcm' ) ) );
	if ( $hfcm_old && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $p . 'hfcm_scripts' ) ) ) {
		$wpdb->query( "DELETE FROM {$p}hfcm_scripts WHERE script_id IN (" . implode( ',', $hfcm_old ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
	$demo->forget( 'snippets' );

	/*
	 * Code Snippets: the shop's PHP tweaks, kept by Mateo.
	 */
	if ( function_exists( 'Code_Snippets\save_snippet' ) && class_exists( 'Code_Snippets\Model\Snippet' ) ) {
		// name, description, scope, active, priority, tags, modified (days ago), author, code
		$snippets = array(
			array(
				'Sold-out workshops cannot go in the cart',
				'Workshop dates stay on the site when every seat is taken, but the Add to cart button goes away until a seat opens up.',
				'front-end',
				true,
				10,
				array( 'workshops', 'woocommerce' ),
				6,
				'mateo',
				"add_filter( 'woocommerce_is_purchasable', function ( \$purchasable, \$product ) {\n\tif ( \$product && has_term( 'workshops', 'product_cat', \$product->get_id() ) && ! \$product->is_in_stock() ) {\n\t\treturn false;\n\t}\n\treturn \$purchasable;\n}, 10, 2 );",
			),
			array(
				'Free local delivery notice in the cart',
				'Tells shoppers how much more they need for free delivery in Portland (orders over $75).',
				'front-end',
				true,
				10,
				array( 'shipping', 'woocommerce' ),
				19,
				'mateo',
				"add_action( 'woocommerce_before_cart', function () {\n\t\$threshold = 75;\n\t\$subtotal  = WC()->cart ? (float) WC()->cart->get_displayed_subtotal() : 0;\n\tif ( \$subtotal > 0 && \$subtotal < \$threshold ) {\n\t\twc_print_notice( sprintf( 'Add %s more for free local delivery.', wc_price( \$threshold - \$subtotal ) ), 'notice' );\n\t}\n} );",
			),
			array(
				'Turn off XML-RPC',
				'Nothing we use needs it, and it is the address most login guessing goes to.',
				'global',
				true,
				10,
				array( 'security' ),
				87,
				'june',
				"add_filter( 'xmlrpc_enabled', '__return_false' );",
			),
			array(
				'Workshop countdown shortcode',
				'[workshop_countdown date="2026-11-07"] prints how long until a workshop, for the journal and the workshops page.',
				'global',
				true,
				10,
				array( 'workshops', 'shortcode' ),
				33,
				'priya',
				"add_shortcode( 'workshop_countdown', function ( \$atts ) {\n\t\$atts = shortcode_atts( array( 'date' => '' ), \$atts, 'workshop_countdown' );\n\t\$when = strtotime( \$atts['date'] );\n\tif ( ! \$when || \$when < time() ) {\n\t\treturn '';\n\t}\n\treturn '<span class=\"workshop-countdown\">' . esc_html( human_time_diff( time(), \$when ) ) . ' to go</span>';\n} );",
			),
			array(
				'Shop phone number in the admin footer',
				'Was handy while the part-time staff were learning the back office. Switched off for now.',
				'admin',
				false,
				10,
				array( 'admin' ),
				58,
				'june',
				"add_filter( 'admin_footer_text', function () {\n\treturn 'Larkspur &amp; Fern back office. Shop phone (503) 555-0100.';\n} );",
			),
			array(
				'Gift message field at checkout',
				'Renames the order notes field so people use it for gift messages. Testing on the holiday orders first.',
				'front-end',
				false,
				20,
				array( 'checkout', 'woocommerce' ),
				2,
				'mateo',
				"add_filter( 'woocommerce_checkout_fields', function ( \$fields ) {\n\tif ( isset( \$fields['order']['order_comments'] ) ) {\n\t\t\$fields['order']['order_comments']['label']       = 'Gift message or delivery notes';\n\t\t\$fields['order']['order_comments']['placeholder'] = 'We will write it on a card for you.';\n\t}\n\treturn \$fields;\n} );",
			),
		);
		$made = array();
		foreach ( $snippets as $s ) {
			$demo->as_user( $s[7] );
			$snippet = new \Code_Snippets\Model\Snippet(
				array(
					'name'     => $s[0],
					'desc'     => $s[1],
					'scope'    => $s[2],
					'active'   => $s[3],
					'priority' => $s[4],
					'tags'     => $s[5],
					'code'     => $s[8],
				)
			);
			$saved = \Code_Snippets\save_snippet( $snippet );
			if ( ! $saved || ! $saved->id ) {
				WP_CLI::warning( "Code Snippets would not save {$s[0]}" );
				continue;
			}
			$wpdb->update( "{$p}snippets", array( 'modified' => $demo->gmt( $demo->ago( $s[6], 15, 40 ) ) ), array( 'id' => (int) $saved->id ) );
			$made[] = (int) $saved->id;
		}
		// Its four bundled examples carry the moment it was activated; date
		// them to when the shop opened, like 99-finish does for plugin pages.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$p}snippets SET modified = %s WHERE name IN ('Make upload filenames lowercase', 'Disable admin bar', 'Allow smilies', 'Current year') AND modified > %s",
				$demo->gmt( $demo->ago( 150, 9, 20 ) ),
				$demo->gmt( $demo->ago( 150, 9, 20 ) )
			)
		);
		if ( function_exists( 'Code_Snippets\clean_snippets_cache' ) ) {
			\Code_Snippets\clean_snippets_cache( "{$p}snippets" );
		}
		wp_cache_flush();
		$demo->remember( 'snippets', 'code_snippets', $made );
		$demo->log( count( $made ) . ' Code Snippets snippets' );
	}

	/*
	 * WPCode: markup and small scripts, the head and footer kind.
	 */
	if ( class_exists( 'WPCode_Snippet' ) ) {
		$demo->as_user( 'june' );
		// title, note, type, location, active, priority, tags, modified (days ago), code
		$snippets = array(
			array(
				'Privacy-friendly analytics',
				'Cookie-free page counts. No banner needed.',
				'html',
				'site_wide_header',
				true,
				10,
				array( 'analytics' ),
				44,
				'<script defer data-domain="larkspur.example" src="https://analytics.example.com/js/script.js"></script>',
			),
			array(
				'Product grid: two columns on phones',
				'The default single column made the shop page a very long scroll on a phone.',
				'css',
				'site_wide_header',
				true,
				10,
				array( 'shop', 'mobile' ),
				12,
				"@media (max-width: 600px) {\n\tul.products {\n\t\tdisplay: grid;\n\t\tgrid-template-columns: 1fr 1fr;\n\t\tgap: 12px;\n\t}\n\tul.products li.product {\n\t\twidth: auto;\n\t\tmargin: 0;\n\t}\n}",
			),
			array(
				'Seats left on workshop pages',
				'Shows "Only 3 seats left" on a workshop once four or fewer remain.',
				'php',
				'frontend_only',
				true,
				10,
				array( 'workshops' ),
				27,
				"add_action( 'woocommerce_single_product_summary', function () {\n\tglobal \$product;\n\tif ( ! \$product || ! has_term( 'workshops', 'product_cat', \$product->get_id() ) || ! \$product->managing_stock() ) {\n\t\treturn;\n\t}\n\t\$left = (int) \$product->get_stock_quantity();\n\tif ( \$left > 0 && \$left <= 4 ) {\n\t\techo '<p class=\"workshop-seats\">Only ' . esc_html( \$left ) . ' seats left.</p>';\n\t}\n}, 25 );",
			),
			array(
				'Free local delivery banner',
				'Above the content on every page. Off until the new banner artwork is ready.',
				'html',
				'before_content',
				false,
				10,
				array( 'shipping' ),
				9,
				'<div class="larkspur-banner">Free local delivery in Portland on orders over $75.</div>',
			),
			array(
				'Thanksgiving closure notice',
				'Turn on the week before Thanksgiving.',
				'text',
				'after_content',
				false,
				10,
				array( 'holidays' ),
				63,
				'The shop is closed Thursday and Friday for Thanksgiving. Orders placed then ship on Monday.',
			),
		);
		$made = array();
		foreach ( $snippets as $s ) {
			$snippet = new WPCode_Snippet(
				array(
					'title'       => wp_slash( $s[0] ),
					'note'        => $s[1],
					'code_type'   => $s[2],
					'location'    => $s[3],
					'auto_insert' => 1,
					'active'      => $s[4],
					'priority'    => $s[5],
					'tags'        => $s[6],
					'code'        => wp_slash( $s[8] ),
				)
			);
			// Its PHP runner test-runs an active snippet while saving and
			// unslashes the code only when its own form field is posted.
			$_POST['wpcode_snippet_code'] = (string) $snippet->get_code(); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$id                           = $snippet->save();
			unset( $_POST['wpcode_snippet_code'] );
			if ( ! $id ) {
				WP_CLI::warning( "WPCode would not save {$s[0]}" );
				continue;
			}
			$ts = $demo->ago( $s[7], 11, 5 );
			$wpdb->update(
				$wpdb->posts,
				array(
					'post_date'         => $demo->local( $ts ),
					'post_date_gmt'     => $demo->gmt( $ts ),
					'post_modified'     => $demo->local( $ts ),
					'post_modified_gmt' => $demo->gmt( $ts ),
				),
				array( 'ID' => (int) $id )
			);
			clean_post_cache( (int) $id );
			$made[] = (int) $id;
		}
		if ( function_exists( 'wpcode' ) && isset( wpcode()->cache ) ) {
			wpcode()->cache->cache_all_loaded_snippets();
		}
		$demo->remember( 'snippets', 'wpcode', $made );
		$demo->log( count( $made ) . ' WPCode snippets' );
	}

	/*
	 * FluentSnippets: file-based, so these live in its storage folder.
	 */
	if ( class_exists( 'FluentSnippets\App\Helpers\Helper' ) ) {
		$demo->as_user( 'theo' );
		// name, description, type, run at, published, priority, tags, created (days ago), updated (days ago), code
		$snippets = array(
			array(
				'Hide the WordPress version',
				'Keeps the version number out of feeds and the page head.',
				'PHP',
				'all',
				true,
				10,
				'security',
				120,
				120,
				"add_filter( 'the_generator', '__return_empty_string' );",
			),
			array(
				'Journal headings in the shop serif',
				'Matches the journal headings to the printed catalogue.',
				'css',
				'wp_head',
				true,
				10,
				'journal, typography',
				47,
				15,
				".single-post .wp-block-heading {\n\tfont-family: Georgia, 'Times New Roman', serif;\n\tletter-spacing: 0.01em;\n}",
			),
			array(
				'Read time on journal posts',
				'Adds "4 minute read" above each journal post.',
				'PHP',
				'frontend',
				true,
				10,
				'journal',
				36,
				8,
				"add_filter( 'the_content', function ( \$content ) {\n\tif ( ! is_singular( 'post' ) || ! is_main_query() ) {\n\t\treturn \$content;\n\t}\n\t\$minutes = max( 1, (int) round( str_word_count( wp_strip_all_tags( \$content ) ) / 220 ) );\n\treturn '<p class=\"read-time\">' . esc_html( \$minutes ) . ' minute read</p>' . \$content;\n} );",
			),
			array(
				'Open workshop booking links in a new tab',
				'Draft. Priya wants to try it on the workshops page first.',
				'js',
				'wp_footer',
				false,
				10,
				'workshops',
				4,
				1,
				"document.querySelectorAll( '.workshops a[href*=\"/product/workshop\"]' ).forEach( function ( link ) {\n\tlink.setAttribute( 'target', '_blank' );\n\tlink.setAttribute( 'rel', 'noopener' );\n} );",
			),
		);
		$made = array();
		$dir  = \FluentSnippets\App\Helpers\Helper::getStorageDir();
		foreach ( $snippets as $s ) {
			$result = \FluentSnippets\App\Helpers\Helper::createSnippet(
				array(
					'meta' => array(
						'name'         => $s[0],
						'description'  => $s[1],
						'type'         => $s[2],
						'run_at'       => $s[3],
						'status'       => 'draft',
						'priority'     => $s[5],
						'tags'         => $s[6],
						'group'        => '',
						'load_as_file' => '',
						'created_at'   => $demo->gmt( $demo->ago( $s[7], 10, 30 ) ),
						'updated_at'   => $demo->gmt( $demo->ago( $s[8], 16, 5 ) ),
						'condition'    => array( 'status' => 'no', 'run_if' => 'assertive', 'items' => array( array() ) ),
					),
					'code' => $s[9],
				)
			);
			if ( is_wp_error( $result ) || ! is_string( $result ) ) {
				WP_CLI::warning( "FluentSnippets would not save {$s[0]}: " . ( is_wp_error( $result ) ? $result->get_error_message() : 'no file' ) );
				continue;
			}
			if ( $s[4] ) {
				$file     = $dir . '/' . $result;
				$contents = (string) file_get_contents( $file );
				file_put_contents( $file, preg_replace( '/^\* @status: draft$/m', '* @status: published', $contents, 1 ) );
				\FluentSnippets\App\Helpers\Helper::invalidateOpcache( $file );
			}
			$made[] = $result;
		}
		\FluentSnippets\App\Helpers\Helper::cacheSnippetIndex();
		$demo->remember( 'snippets', 'fluent', $made );
		$demo->log( count( $made ) . ' FluentSnippets snippets' );
	}

	/*
	 * Simple Custom CSS and JS: Hana's styling, plus one script and a bit of
	 * markup.
	 */
	if ( post_type_exists( 'custom-css-js' ) && function_exists( 'minn_admin_ccj_write_file' ) && function_exists( 'minn_admin_ccj_rebuild_tree' ) ) {
		// title, language, where (header/footer), side, linking, priority, active, modified (days ago), code
		$snippets = array(
			array( 'Product grid spacing', 'css', 'header', 'frontend', 'internal', 5, true, 22, "ul.products {\n\trow-gap: 28px;\n}\nul.products li.product .woocommerce-loop-product__title {\n\tfont-size: 1.05rem;\n\tline-height: 1.35;\n}" ),
			array( 'Sale badge in fern green', 'css', 'header', 'frontend', 'internal', 5, true, 40, ".woocommerce span.onsale {\n\tbackground: #4f6f52;\n\tcolor: #fff;\n\tborder-radius: 999px;\n\tpadding: 0.2em 0.8em;\n}" ),
			array( 'Login page in shop colours', 'css', 'header', 'login', 'internal', 5, true, 95, "body.login {\n\tbackground: #f4f1ea;\n}" ),
			array( 'Gift message character counter', 'js', 'footer', 'frontend', 'internal', 5, false, 3, "( function () {\n\tvar field = document.getElementById( 'order_comments' );\n\tif ( ! field ) {\n\t\treturn;\n\t}\n\tvar note = document.createElement( 'small' );\n\tfield.insertAdjacentElement( 'afterend', note );\n\tfield.addEventListener( 'input', function () {\n\t\tnote.textContent = ( 200 - field.value.length ) + ' characters left on the card';\n\t} );\n} )();" ),
			array( 'Workshop announcement bar', 'html', 'footer', 'frontend', 'both', 5, false, 30, '<div class="announcement-bar">Spring workshops are open. <a href="/workshops/">Book a seat</a></div>' ),
		);
		// Its admin screen makes the upload folder on first visit.
		if ( defined( 'CCJ_UPLOAD_DIR' ) && ! is_dir( CCJ_UPLOAD_DIR ) ) {
			wp_mkdir_p( CCJ_UPLOAD_DIR );
		}
		$made = array();
		$demo->as_user( 'hana' );
		$author = $demo->id( 'users', 'hana' );
		foreach ( $snippets as $s ) {
			$id = wp_insert_post(
				wp_slash(
					array(
						'post_type'    => 'custom-css-js',
						'post_title'   => $s[0],
						'post_content' => $s[8],
						'post_status'  => 'publish',
						'post_author'  => $author ? $author : 1,
					)
				),
				true
			);
			if ( is_wp_error( $id ) || ! $id ) {
				continue;
			}
			update_post_meta(
				$id,
				'options',
				array(
					'type'     => $s[2],
					'linking'  => $s[4],
					'side'     => $s[3],
					'priority' => $s[5],
					'language' => $s[1],
				)
			);
			update_post_meta( $id, '_active', $s[6] ? 'yes' : 'no' );
			$ts = $demo->ago( $s[7], 13, 25 );
			$wpdb->update(
				$wpdb->posts,
				array(
					'post_date'         => $demo->local( $ts ),
					'post_date_gmt'     => $demo->gmt( $ts ),
					'post_modified'     => $demo->local( $ts ),
					'post_modified_gmt' => $demo->gmt( $ts ),
				),
				array( 'ID' => (int) $id )
			);
			clean_post_cache( (int) $id );
			minn_admin_ccj_write_file( (int) $id );
			$made[] = (int) $id;
		}
		minn_admin_ccj_rebuild_tree();
		$demo->remember( 'snippets', 'ccj', $made );
		$demo->log( count( $made ) . ' Simple Custom CSS and JS snippets' );
	}

	/*
	 * Header Footer Code Manager: page-specific bits.
	 */
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $p . 'hfcm_scripts' ) ) ) {
		$workshops = (int) $demo->id( 'pages', 'workshops' );
		// name, type, location, device, display on, pages, status, created by, created (days ago), revised (days ago), code
		$snippets = array(
			array( 'Call the shop button on phones', 'html', 'footer', 'mobile', 'All', '', 'active', 'mateo', 75, 21, "<a class=\"call-shop\" href=\"tel:+15035550100\">Call the shop</a>\n<style>.call-shop{position:fixed;right:16px;bottom:16px;padding:10px 16px;border-radius:999px;background:#4f6f52;color:#fff;text-decoration:none;}</style>" ),
			array( 'Workshop page booking table styles', 'css', 'header', 'both', $workshops ? 's_pages' : 'All', $workshops ? wp_json_encode( array( (string) $workshops ) ) : '', 'active', 'priya', 50, 14, ".workshop-dates td {\n\tpadding: 8px 12px;\n}\n.workshop-dates .sold-out {\n\tcolor: #8a8a8a;\n\ttext-decoration: line-through;\n}" ),
			array( 'Newsletter sign-up form embed', 'js', 'footer', 'both', 'All', '', 'inactive', 'june', 110, 60, '<script src="https://forms.example.com/embed/larkspur-plant-club.js" async></script>' ),
			array( 'Holiday closure notice', 'html', 'before_content', 'both', 'All', '', 'inactive', 'june', 64, 64, '<p class="holiday-notice">We are closed on Thanksgiving and the day after. Online orders ship on Monday.</p>' ),
		);
		$made = array();
		foreach ( $snippets as $s ) {
			$ok = $wpdb->insert(
				"{$p}hfcm_scripts",
				array(
					'name'               => $s[0],
					'snippet'            => $s[10],
					'snippet_type'       => $s[1],
					'device_type'        => $s[3],
					'location'           => $s[2],
					'display_on'         => $s[4],
					's_pages'            => $s[5],
					'status'             => $s[6],
					'created_by'         => $s[7],
					'last_modified_by'   => $s[7],
					'created'            => $demo->local( $demo->ago( $s[8], 9, 50 ) ),
					'last_revision_date' => $demo->local( $demo->ago( $s[9], 17, 15 ) ),
				)
			);
			if ( $ok ) {
				$made[] = (int) $wpdb->insert_id;
			}
		}
		$demo->remember( 'snippets', 'hfcm', $made );
		$demo->log( count( $made ) . ' Header Footer Code Manager snippets' );
	}
};
