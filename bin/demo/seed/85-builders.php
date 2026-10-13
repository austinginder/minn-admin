<?php
/**
 * Builders, agents and automation: Elementor saved templates and Theme
 * Builder parts (plus a Floating Buttons widget), JetThemeCore templates,
 * Novamira and Elementor MCP agent access (switched on, with connections a
 * shop would have made), and OttoKit's outgoing webhook log.
 *
 * Storage worth knowing:
 *  - Elementor templates are elementor_library posts made through their
 *    Documents_Manager::create(); the tree is saved with Document::save().
 *    Theme Builder conditions go through Pro's Conditions_Manager::
 *    save_conditions(), which also rebuilds the conditions cache. Popups only
 *    register with an active Pro licence, so there are none.
 *  - JetThemeCore templates come from their create_template(); "Active" is
 *    membership of the jet_site_conditions option, which only their
 *    update_template_conditions() writes. Block-editor canvas, so the content
 *    is plain post_content.
 *  - Novamira's OAuth tables exist only after its schema installer has run
 *    (it runs on boot once abilities are on). A connected OAuth app is a
 *    client row plus an access token and an unexpired, unrevoked refresh
 *    token for the user; client ids made from the admin screen carry
 *    admin_created = 1. Its application passwords are any whose name starts
 *    with "Novamira". Token rows here hold hashes of random strings no one
 *    has, so they open nothing. Memories need Novamira Pro's licence (the
 *    post type is not registered without it).
 *  - Elementor MCP lists core application passwords named
 *    "Elementor MCP - <Client> (<UTC date>)", the name their setup writes.
 *  - OttoKit's log is {prefix}suretriggers_webhook_requests with a site-local
 *    created_at. Its cron retries failed rows with fewer than 5 attempts
 *    every 30 minutes by POSTing to request_url, so every failed row here
 *    has used up its 5 retries and the cron leaves it alone. Success rows
 *    older than 30 days are pruned daily, so the log stays within 3 weeks.
 *    The account stays unconnected: connecting needs OttoKit's secret key.
 *
 * @package minn-admin
 */

return function ( Minn_Demo_Seed $demo ) {
	global $wpdb;

	$june = (int) $demo->id( 'users', 'june' );

	/* ===== Clear what the last run made ===== */

	foreach ( $demo->ids( 'builders_posts' ) as $id ) {
		if ( function_exists( 'jet_theme_core' ) && 'jet-theme-core' === get_post_type( $id ) ) {
			jet_theme_core()->template_conditions_manager->remove_post_from_site_conditions( (int) $id );
		}
		wp_delete_post( (int) $id, true );
	}
	foreach ( $demo->ids( 'builders_passwords' ) as $key => $uuid ) {
		list( $uid ) = explode( ':', $key );
		WP_Application_Passwords::delete_application_password( (int) $uid, $uuid );
	}
	$oauth = $wpdb->prefix . 'novamira_oauth_';
	foreach ( $demo->ids( 'builders_oauth' ) as $client_id ) {
		if ( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $oauth . 'clients' ) ) ) {
			break;
		}
		$hashes = $wpdb->get_col( $wpdb->prepare( "SELECT identifier_hash FROM {$oauth}access_tokens WHERE client_id = %s", $client_id ) );
		foreach ( $hashes as $hash ) {
			$wpdb->delete( $oauth . 'refresh_tokens', array( 'access_token_hash' => $hash ) );
		}
		$wpdb->delete( $oauth . 'access_tokens', array( 'client_id' => $client_id ) );
		$wpdb->delete( $oauth . 'clients', array( 'client_id' => $client_id ) );
	}
	$otto = $wpdb->prefix . 'suretriggers_webhook_requests';
	foreach ( $demo->ids( 'builders_ottokit' ) as $id ) {
		$wpdb->delete( $otto, array( 'id' => (int) $id ) );
	}
	foreach ( array( 'builders_posts', 'builders_passwords', 'builders_oauth', 'builders_ottokit' ) as $group ) {
		$demo->forget( $group );
	}
	$demo->as_user( 'june' );

	// Back-date a post the way the shop's history reads (99-finish sets modified = date).
	$date_post = function ( $id, $ts, $author = 0 ) use ( $wpdb, $demo ) {
		$row = array(
			'post_date'         => $demo->local( $ts ),
			'post_date_gmt'     => $demo->gmt( $ts ),
			'post_modified'     => $demo->local( $ts ),
			'post_modified_gmt' => $demo->gmt( $ts ),
		);
		if ( $author ) {
			$row['post_author'] = $author;
		}
		$wpdb->update( $wpdb->posts, $row, array( 'ID' => $id ) );
		clean_post_cache( $id );
	};

	/* ===== Elementor: saved templates, Theme Builder parts, a floating button ===== */

	if ( defined( 'ELEMENTOR_VERSION' ) && class_exists( '\Elementor\Plugin' ) ) {
		$n   = 0;
		$eid = function () use ( &$n ) {
			++$n;
			return substr( md5( 'larkspur-elementor-' . $n ), 0, 7 );
		};
		$w   = function ( $type, $settings ) use ( $eid ) {
			return array( 'id' => $eid(), 'elType' => 'widget', 'widgetType' => $type, 'settings' => $settings, 'elements' => array() );
		};
		$box = function ( $children, $settings = array() ) use ( $eid ) {
			return array( 'id' => $eid(), 'elType' => 'container', 'settings' => $settings + array( 'content_width' => 'boxed' ), 'elements' => $children, 'isInner' => false );
		};
		$h   = function ( $text, $size = 'h2', $align = '' ) use ( $w ) {
			return $w( 'heading', array_filter( array( 'title' => $text, 'header_size' => $size, 'align' => $align ) ) );
		};
		$p   = function ( $html ) use ( $w ) {
			return $w( 'text-editor', array( 'editor' => $html ) );
		};
		$btn = function ( $text, $url ) use ( $w ) {
			return $w( 'button', array( 'text' => $text, 'link' => array( 'url' => $url, 'is_external' => '', 'nofollow' => '' ) ) );
		};
		$img = function ( $name ) use ( $w, $demo ) {
			$id = $demo->image( $name );
			return $w( 'image', array( 'image' => array( 'id' => $id, 'url' => (string) wp_get_attachment_url( $id ) ), 'image_size' => 'large' ) );
		};
		$links = function ( $items ) use ( $w ) {
			$list = array();
			foreach ( $items as $i => $label ) {
				$list[] = array( '_id' => substr( md5( $label ), 0, 7 ), 'text' => $label, 'link' => array( 'url' => 'https://larkspur.example/' . sanitize_title( $label ) . '/' ) );
			}
			return $w( 'icon-list', array( 'view' => 'inline', 'icon_list' => $list ) );
		};
		$row = array( 'flex_direction' => 'row', 'flex_justify_content' => 'space-between', 'flex_align_items' => 'center' );

		$templates = array(
			// title, type, days ago, hour, author, status, conditions, tree
			array( 'Site header', 'header', 58, 10, 'june', 'publish', array( 'include/general' ), array(
				$box( array( $h( 'Larkspur & Fern', 'h1' ), $links( array( 'Shop', 'Workshops', 'Journal', 'Plant Club', 'Visit' ) ), $btn( 'Book a workshop', 'https://larkspur.example/workshops/' ) ), $row ),
			) ),
			array( 'Site footer', 'footer', 58, 11, 'june', 'publish', array( 'include/general' ), array(
				$box(
					array(
						$box( array( $h( 'Visit us', 'h4' ), $p( '<p>112 Station Road, Portland OR 97214<br>Tuesday to Sunday, 10 to 6</p>' ) ) ),
						$box( array( $h( 'Say hello', 'h4' ), $p( '<p>hello@larkspur.example<br>(503) 555-0100</p>' ) ) ),
						$box( array( $h( 'Shop', 'h4' ), $links( array( 'Shipping & Returns', 'Wholesale', 'FAQ' ) ) ) ),
					),
					$row
				),
			) ),
			array( 'Journal post', 'single-post', 45, 15, 'june', 'publish', array( 'include/singular/post' ), array(
				$box( array( $w( 'theme-post-title', array( 'header_size' => 'h1' ) ), $w( 'post-info', array() ), $w( 'theme-post-featured-image', array() ), $w( 'theme-post-content', array() ) ) ),
				$box( array( $h( 'More from the journal', 'h3' ), $w( 'posts', array( 'posts_per_page' => 3 ) ) ) ),
			) ),
			array( 'Journal archive', 'archive', 44, 16, 'june', 'publish', array( 'include/archive' ), array(
				$box( array( $w( 'theme-archive-title', array( 'header_size' => 'h1' ) ), $w( 'archive-posts', array( 'archive_classic_columns' => '3' ) ) ) ),
			) ),
			array( 'Plant product page', 'product', 40, 14, 'june', 'publish', array( 'include/product' ), array(
				$box( array( $w( 'woocommerce-product-images', array() ), $box( array( $w( 'woocommerce-product-title', array() ), $w( 'woocommerce-product-price', array() ), $w( 'woocommerce-product-short-description', array() ), $w( 'woocommerce-product-add-to-cart', array() ) ) ) ), $row ),
				$box( array( $h( 'Care at a glance', 'h3' ), $p( '<p>Every plant ships with a care card. Questions after it arrives? Write to hello@larkspur.example and Theo will answer.</p>' ) ) ),
			) ),
			array( 'Shop archive', 'product-archive', 40, 15, 'june', 'publish', array( 'include/product_archive' ), array(
				$box( array( $w( 'woocommerce-archive-products', array( 'columns' => 4, 'rows' => 3 ) ) ) ),
			) ),
			array( 'Page not found', 'error-404', 52, 9, 'june', 'publish', array( 'include/singular/not_found404' ), array(
				$box( array( $img( '404-image' ), $h( 'This page has gone to seed', 'h1', 'center' ), $p( '<p style="text-align:center">Try the shop, or tell us what you were looking for.</p>' ), $btn( 'Back to the shop', 'https://larkspur.example/shop/' ) ) ),
			) ),
			array( 'Product card', 'loop-item', 38, 13, 'june', 'publish', array(), array(
				$box( array( $w( 'theme-post-featured-image', array() ), $w( 'woocommerce-product-title', array() ), $w( 'woocommerce-product-price', array() ) ) ),
			) ),
			array( 'Holiday header', 'header', 0.2, 9, 'june', 'draft', array(), array(
				$box( array( $h( 'Larkspur & Fern', 'h1' ), $p( '<p>Holiday hours: open every day in December, 9 to 7. Wreath workshops on Saturdays.</p>' ), $btn( 'See wreath dates', 'https://larkspur.example/workshops/' ) ), $row ),
			) ),
			array( 'Workshop banner', 'section', 12, 17, 'priya', 'publish', array(), array(
				$box( array( $img( 'coral-square' ), $box( array( $h( 'Saturday workshops', 'h2' ), $p( '<p>Terrariums, propagation, kokedama and a kids sunflower morning. Two hours, all materials included.</p>' ), $btn( 'See the dates', 'https://larkspur.example/workshops/' ) ) ) ), $row ),
			) ),
			array( 'Product grid header', 'section', 20, 11, 'june', 'publish', array(), array(
				$box( array( $h( 'New in the greenhouse', 'h2' ), $p( '<p>Fresh this week: anthuriums, golden saguaros and the first calla bulbs of the season.</p>' ) ) ),
			) ),
			array( 'Newsletter footer', 'container', 26, 14, 'june', 'publish', array(), array(
				$box( array( $h( 'Plant notes, once a month', 'h3' ), $p( '<p>Care tips, new arrivals and workshop dates. No more than one letter a month.</p>' ), $w( 'form', array( 'form_name' => 'Newsletter', 'button_text' => 'Sign me up' ) ) ) ),
			) ),
			array( 'Plant care FAQ', 'page', 30, 10, 'june', 'publish', array(), array(
				$box( array( $h( 'Plant care questions', 'h1' ) ) ),
				$box( array( $w( 'accordion', array( 'tabs' => array(
					array( '_id' => 'f1', 'tab_title' => 'How often should I water?', 'tab_content' => '<p>When the top inch of soil is dry. Most houseplants want less water in winter.</p>' ),
					array( '_id' => 'f2', 'tab_title' => 'Which plants are safe for cats?', 'tab_content' => '<p>Calatheas, peperomias and most ferns. Peace lilies and anthuriums are not.</p>' ),
					array( '_id' => 'f3', 'tab_title' => 'Do you ship in winter?', 'tab_content' => '<p>Yes, Monday to Wednesday with a heat pack when it is below 40 degrees.</p>' ),
				) ) ) ) ),
			) ),
			array( 'Spring Terrarium Workshop landing', 'page', 1, 16, 'priya', 'draft', array(), array(
				$box( array( $img( 'category-cactus' ), $h( 'Build a terrarium in two hours', 'h1' ), $p( '<p>Saturday mornings in the back room. Glass, soil, moss and three small plants included. $55.</p>' ), $btn( 'Save a seat', 'https://larkspur.example/product/workshop/' ) ) ),
			) ),
		);

		$conditions = null;
		if ( class_exists( '\ElementorPro\Modules\ThemeBuilder\Module' ) ) {
			$conditions = \ElementorPro\Modules\ThemeBuilder\Module::instance()->get_conditions_manager();
		}
		foreach ( $templates as $i => $t ) {
			list( $title, $type, $days, $hour, $author, $status, $conds, $tree ) = $t;
			$document = \Elementor\Plugin::$instance->documents->create(
				$type,
				array(
					'post_title'  => wp_slash( $title ),
					'post_status' => $status,
					'post_type'   => \Elementor\TemplateLibrary\Source_Local::CPT,
				)
			);
			if ( is_wp_error( $document ) || ! $document ) {
				$demo->log( 'Elementor could not make ' . $title . ': ' . ( is_wp_error( $document ) ? $document->get_error_message() : 'no document' ) );
				continue;
			}
			$id = (int) $document->get_main_id();
			$demo->remember( 'builders_posts', 'el-' . sanitize_title( $title ), $id );
			$document->save( array( 'elements' => $tree, 'settings' => array( 'post_status' => $status ) ) );
			if ( $conds && $conditions ) {
				$rows = array();
				foreach ( $conds as $c ) {
					$parts  = array_pad( explode( '/', $c ), 4, '' );
					$rows[] = array( 'type' => $parts[0], 'name' => $parts[1], 'sub_name' => $parts[2], 'sub_id' => $parts[3] );
				}
				$conditions->save_conditions( $id, $rows );
			}
			$date_post( $id, $demo->ago( $days, $hour, ( $i * 7 ) % 60 ), $demo->id( 'users', $author ) );
		}

		// Floating Buttons: their own post type, typed by meta. No display
		// condition, so it waits in the library rather than showing on the site.
		if ( class_exists( '\Elementor\Modules\FloatingButtons\Module' ) ) {
			$fb = \Elementor\Modules\FloatingButtons\Module::class;
			$document = \Elementor\Plugin::$instance->documents->create(
				$fb::FLOATING_BUTTONS_DOCUMENT_TYPE,
				array(
					'post_title'  => 'Text the shop',
					'post_status' => 'publish',
					'post_type'   => $fb::CPT_FLOATING_BUTTONS,
				)
			);
			if ( $document && ! is_wp_error( $document ) ) {
				$id = (int) $document->get_main_id();
				$demo->remember( 'builders_posts', 'el-text-the-shop', $id );
				update_post_meta( $id, $fb::FLOATING_ELEMENTS_TYPE_META_KEY, 'floating-buttons' );
				$document->save(
					array(
						'elements' => array(
							$box(
								array(
									$w(
										'contact-buttons',
										array(
											'top_bar_title'        => 'Larkspur & Fern',
											'top_bar_subtitle'     => 'Usually replies within the hour',
											'message_bubble_name'  => 'June',
											'message_bubble_body'  => 'Hi! A question about a plant or a workshop? Send us a text.',
											'chat_button_platform' => 'sms',
											'chat_button_number'   => '+15035550100',
										)
									),
								)
							),
						),
						'settings' => array( 'post_status' => 'publish' ),
					)
				);
				$date_post( $id, $demo->ago( 15, 12, 20 ) );
			}
		}
		$demo->log( count( preg_grep( '/^el-/', array_keys( $demo->ids( 'builders_posts' ) ) ) ) . ' Elementor templates' );
	}

	/* ===== JetThemeCore: theme parts on the block editor ===== */

	// Only header, footer, single and archive take display conditions; the
	// WooCommerce, page and section structures answer "Not available for this
	// type" and are placed through JetThemeCore's Theme Builder instead.

	if ( function_exists( 'jet_theme_core' ) && post_type_exists( 'jet-theme-core' ) ) {
		$entire = array( array( 'id' => '_lfentire', 'include' => 'true', 'group' => 'entire', 'subGroup' => 'entire', 'subGroupValue' => '' ) );
		$header = '<!-- wp:group {"layout":{"type":"flex","justifyContent":"space-between"}} --><div class="wp-block-group"><!-- wp:site-title /--><!-- wp:paragraph --><p><a href="https://larkspur.example/shop/">Shop</a> · <a href="https://larkspur.example/workshops/">Workshops</a> · <a href="https://larkspur.example/journal/">Journal</a> · <a href="https://larkspur.example/visit-the-shop/">Visit</a></p><!-- /wp:paragraph --></div><!-- /wp:group -->';
		$jtc    = array(
			// title, structure, canvas, days ago, hour, status, conditions, content
			array( 'Larkspur header', 'jet_header', 'default', 34, 10, 'publish', $entire, $header ),
			array( 'Larkspur footer', 'jet_footer', 'default', 34, 11, 'publish', $entire, '<!-- wp:paragraph --><p>Larkspur &amp; Fern, 112 Station Road, Portland OR. Open Tuesday to Sunday, 10 to 6.</p><!-- /wp:paragraph -->' ),
			array( 'Journal post', 'jet_single', 'default', 31, 15, 'publish', array( array( 'id' => '_lfsingle', 'include' => 'true', 'group' => 'singular', 'subGroup' => 'singular-post', 'subGroupValue' => '' ) ), '<!-- wp:post-title {"level":1} /--><!-- wp:post-featured-image /--><!-- wp:post-content /-->' ),
			array( 'Journal archive', 'jet_archive', 'default', 31, 16, 'publish', array( array( 'id' => '_lfarchive', 'include' => 'true', 'group' => 'archive', 'subGroup' => 'archive-all-post', 'subGroupValue' => '' ) ), '<!-- wp:query-title {"type":"archive"} /--><!-- wp:query {"inherit":true} --><div class="wp-block-query"><!-- wp:post-template --><!-- wp:post-title {"isLink":true} /--><!-- wp:post-excerpt /--><!-- /wp:post-template --></div><!-- /wp:query -->' ),
			array( 'Product page', 'jet_single_product', 'default', 27, 13, 'publish', array(), '<!-- wp:post-title {"level":1} /--><!-- wp:post-featured-image /--><!-- wp:post-excerpt /-->' ),
			array( 'Shop archive', 'jet_products_archive', 'default', 27, 14, 'publish', array(), '<!-- wp:query-title {"type":"archive"} /-->' ),
			array( 'Workshop signup section', 'jet_section', 'default', 9, 17, 'publish', array(), '<!-- wp:heading --><h2 class="wp-block-heading">Saturday workshops</h2><!-- /wp:heading --><!-- wp:paragraph --><p>Two hours, all materials included. Ask Priya about private groups.</p><!-- /wp:paragraph -->' ),
			array( 'My account', 'jet_account_page', 'default', 22, 12, 'publish', array(), '<!-- wp:paragraph --><p>Your orders, workshop seats and Plant Club membership.</p><!-- /wp:paragraph -->' ),
			array( 'Checkout', 'jet_products_checkout', 'elementor', 18, 10, 'publish', array(), '' ),
			array( 'Winter header', 'jet_header', 'default', 0.1, 8, 'draft', array(), '<!-- wp:paragraph --><p>Open every day in December, 9 to 7.</p><!-- /wp:paragraph -->' . $header ),
		);
		foreach ( $jtc as $i => $t ) {
			list( $title, $structure, $canvas, $days, $hour, $status, $conds, $content ) = $t;
			$res = jet_theme_core()->templates->create_template( $structure, $canvas, $title, array() );
			if ( ! is_array( $res ) || empty( $res['newTemplateId'] ) ) {
				$demo->log( 'JetThemeCore could not make ' . $title . ': ' . ( is_array( $res ) ? wp_strip_all_tags( (string) $res['message'] ) : 'no answer' ) );
				continue;
			}
			$id = (int) $res['newTemplateId'];
			$demo->remember( 'builders_posts', 'jtc-' . sanitize_title( $title ), $id );
			$update = array( 'ID' => $id, 'post_status' => $status );
			if ( '' !== $content ) {
				$update['post_content'] = $content;
			}
			wp_update_post( wp_slash( $update ) );
			if ( $conds ) {
				jet_theme_core()->template_conditions_manager->update_template_conditions( $id, $conds );
			}
			$date_post( $id, $demo->ago( $days, $hour, ( $i * 11 ) % 60 ) );
		}
		$demo->log( count( preg_grep( '/^jtc-/', array_keys( $demo->ids( 'builders_posts' ) ) ) ) . ' JetThemeCore templates' );
	}

	/* ===== Application passwords: the agents' keys (never shown, never kept) ===== */

	// name, user, created days ago, last used days ago (null: never), last IP
	$add_password = function ( $key, $name, $user, $created, $used, $ip ) use ( $demo ) {
		$uid  = (int) $demo->id( 'users', $user );
		$made = WP_Application_Passwords::create_new_application_password( $uid, array( 'name' => $name ) );
		if ( is_wp_error( $made ) ) {
			$demo->log( $name . ': ' . $made->get_error_message() );
			return;
		}
		$uuid = $made[1]['uuid'];
		$all  = WP_Application_Passwords::get_user_application_passwords( $uid );
		foreach ( $all as &$pw ) {
			if ( $pw['uuid'] === $uuid ) {
				$pw['created']   = $created;
				$pw['last_used'] = $used;
				$pw['last_ip']   = $used ? $ip : null;
			}
		}
		unset( $pw );
		update_user_meta( $uid, WP_Application_Passwords::USERMETA_KEY_APPLICATION_PASSWORDS, $all );
		$demo->remember( 'builders_passwords', $uid . ':' . $key, $uuid );
	};

	/* ===== Novamira: abilities on, three ways in, two abilities kept off ===== */

	if ( function_exists( 'novamira_enable_ai_abilities' ) ) {
		if ( ! novamira_enable_ai_abilities() ) {
			$demo->log( 'Novamira would not switch on: ' . (string) novamira_get_mcp_dependency_error() );
		}
		$schema = WP_PLUGIN_DIR . '/novamira/includes/oauth/schema.php';
		if ( file_exists( $schema ) ) {
			require_once $schema;
			\Novamira\OAuth\Schema\maybe_install();
		}
		if ( function_exists( '\Novamira\OAuth\Connections\load_client_repository' ) && \Novamira\OAuth\Connections\load_client_repository() ) {
			$repo = new \Novamira\OAuth\Repositories\ClientRepository();
			// name, admin-made, created days ago, last used days ago, connected (refresh token) days ago
			$clients = array(
				array( 'Claude', false, 26, 0.1, 6 ),
				array( 'ChatGPT', false, 19, 3, 19 ),
				array( 'Claude.ai team connector', true, 21, 2, null ),
				array( 'Shop laptop', true, 4, null, null ),
			);
			foreach ( $clients as $i => $c ) {
				list( $name, $admin, $created, $used, $connected ) = $c;
				$client_id = $repo->create( $name, array( 'https://example.com/oauth/callback' ), '203.0.113.' . ( 20 + $i ), $admin );
				if ( ! $client_id ) {
					continue;
				}
				$demo->remember( 'builders_oauth', sanitize_title( $name ), $client_id );
				$wpdb->update(
					$oauth . 'clients',
					array(
						'created_at'   => $demo->gmt( $demo->ago( $created, 9 + $i, 14 ) ),
						'last_used_at' => null === $used ? null : $demo->gmt( $demo->ago( $used, 11 + $i, 40 ) ),
					),
					array( 'client_id' => $client_id )
				);
				if ( null !== $connected ) {
					// The token pair a finished OAuth sign-in leaves: hashes only.
					$access = hash( 'sha256', wp_generate_password( 40, false ) );
					$wpdb->insert(
						$oauth . 'access_tokens',
						array(
							'identifier_hash' => $access,
							'client_id'       => $client_id,
							'user_id'         => $june,
							'expires_at'      => $demo->gmt( $demo->ago( $used, 12, 40 ) ),
							'scopes'          => '["mcp"]',
							'revoked'         => 0,
						)
					);
					$wpdb->insert(
						$oauth . 'refresh_tokens',
						array(
							'identifier_hash'   => hash( 'sha256', wp_generate_password( 40, false ) ),
							'access_token_hash' => $access,
							'expires_at'        => $demo->gmt( $demo->ago( $connected - 30, 12, 0 ) ),
							'revoked'           => 0,
						)
					);
				}
			}
		}
		$add_password( 'novamira-claude-code', 'Novamira: Claude Code', 'june', $demo->ago( 33, 14, 5 ), $demo->ago( 0.4, 8, 52 ), '203.0.113.24' );
		$add_password( 'novamira-cursor', 'Novamira: Cursor', 'june', $demo->ago( 12, 16, 30 ), $demo->ago( 9, 10, 15 ), '198.51.100.40' );

		// June keeps the two riskiest abilities off; everything else stays callable.
		$rules = array();
		foreach ( array( 'novamira/execute-php', 'novamira/delete-file' ) as $name ) {
			$rules[ $name ] = array( 'disabled' => true );
		}
		novamira_update_ability_rules( $rules );

		if ( function_exists( '\Novamira\Context\instructions_update_content' ) ) {
			\Novamira\Context\instructions_update_content(
				"This is Larkspur & Fern, a small plant shop at 112 Station Road in Portland, Oregon.\n\n"
				. "- The shop runs on WooCommerce. Never change prices, stock or orders without asking June first.\n"
				. "- The journal is edited by Theo. Draft new posts; do not publish them.\n"
				. "- Workshops (terrariums, propagation, kokedama, the kids sunflower morning) are Priya's. Seat counts live on the workshop products.\n"
				. "- Write like the shop talks: warm, plain, short sentences, no exclamation marks in product copy.\n"
				. "- Plant names: common name first, botanical name in parentheses the first time.\n"
				. "- Shipping is Monday to Wednesday only, so plants never sit in a depot over a weekend.\n"
			);
		}
		$demo->log( 'Novamira switched on, ' . count( $demo->ids( 'builders_oauth' ) ) . ' OAuth clients' );
	}

	/* ===== Elementor MCP: on, with the clients June set up ===== */

	if ( class_exists( '\Elementor\MCP\Composer\Admin\McpSettingsController' ) ) {
		$opt = \Elementor\MCP\Composer\Admin\McpSettingsController::OPTION_NAME;
		if ( '__missing__' === get_option( $opt, '__missing__' ) ) {
			add_option( $opt, true, '', false );
		} else {
			update_option( $opt, true );
		}
		// client, created days ago, last used days ago (null: never), IP
		$mcp = array(
			array( 'Claude Code', 9, 0.05, '198.51.100.17' ),
			array( 'Cursor', 27, 16, '203.0.113.58' ),
			array( 'Claude Desktop', 2, null, '' ),
		);
		foreach ( $mcp as $i => $c ) {
			$created = $demo->ago( $c[1], 15 + $i, 12 );
			$add_password( 'elementor-mcp-' . sanitize_title( $c[0] ), 'Elementor MCP - ' . $c[0] . ' (' . gmdate( 'Y-m-d H:i:s', $created ) . ')', 'june', $created, null === $c[2] ? null : $demo->ago( $c[2], 9, 30 + $i ), $c[3] );
		}
		$demo->log( 'Elementor MCP switched on, ' . count( $mcp ) . ' connections' );
	}

	/* ===== OttoKit: three weeks of webhooks to its cloud ===== */

	if ( defined( 'SURE_TRIGGERS_FILE' ) && class_exists( '\SureTriggers\Controllers\WebhookRequestsController' ) ) {
		\SureTriggers\Controllers\WebhookRequestsController::suretriggers_maybe_create_table();
		$site     = preg_replace( '/^https?:\/\//', '', esc_url_raw( get_site_url() ) );
		$endpoint = 'https://webhook.example.com/wordpress/webhook';
		$orders   = array_values( $demo->ids( 'orders' ) );
		$order_context = function ( $k ) use ( $orders ) {
			$order = $orders ? wc_get_order( $orders[ $k % count( $orders ) ] ) : null;
			if ( ! $order ) {
				return array();
			}
			$items = array();
			foreach ( $order->get_items() as $item ) {
				$items[] = array( 'name' => $item->get_name(), 'quantity' => $item->get_quantity(), 'total' => $item->get_total() );
			}
			return array(
				'order_id'           => $order->get_id(),
				'status'             => $order->get_status(),
				'total'              => $order->get_total(),
				'currency'           => $order->get_currency(),
				'billing_first_name' => $order->get_billing_first_name(),
				'billing_last_name'  => $order->get_billing_last_name(),
				'billing_email'      => $order->get_billing_email(),
				'billing_city'       => $order->get_billing_city(),
				'products'           => $items,
			);
		};
		$person = function ( $slug ) use ( $demo ) {
			$p = $demo->person( $slug );
			return array( 'first_name' => $p['first'], 'last_name' => $p['last'], 'user_email' => $p['email'] );
		};
		// trigger, context, days ago, hour, minute, response code, error
		$log = array(
			array( 'wc_order_created', array( 'status' => 'processing' ) + $order_context( 0 ), 20, 9, 14, 200, '' ),
			array( 'user_submits_gravityform', array( 'form_title' => 'Workshop enquiry', 'name' => 'Ruth Abernathy', 'email' => 'ruth.abernathy@example.com', 'message' => 'Could Lovejoy Hall book a private terrarium workshop for twelve in November?' ), 18, 13, 2, 200, '' ),
			array( 'wc_order_created', array( 'status' => 'processing' ) + $order_context( 1 ), 16, 11, 47, 200, '' ),
			array( 'wc_order_status_changed', $order_context( 2 ) + array( 'from_status' => 'processing', 'to_status' => 'completed' ), 14, 15, 30, 200, '' ),
			array( 'wc_order_created', array( 'status' => 'processing' ) + $order_context( 3 ), 11, 10, 5, 0, 'Service not available' ),
			array( 'wc_review_product', array( 'product_name' => "Anthurium 'Red Heart'", 'rating' => 5, 'review' => 'Arrived glossy and blooming. Packed better than anything I have ordered online.' ) + $person( 'olivia' ), 9, 18, 21, 200, '' ),
			array( 'user_submits_gravityform', array( 'form_title' => 'Wholesale application', 'name' => 'Samuel Ortiz', 'email' => 'samuel@ortizflorals.example', 'company' => 'Ortiz Florals' ), 8, 9, 50, 502, 'Bad Gateway' ),
			array( 'wc_order_created', array( 'status' => 'processing' ) + $order_context( 4 ), 6, 12, 18, 200, '' ),
			array( 'amelia_new_appointment_booked', array( 'service' => 'In-home Plant Consultation', 'provider' => 'June Alder', 'customer' => 'Kenji Watanabe', 'customer_email' => 'kenji.watanabe@example.net', 'starts' => wp_date( 'Y-m-d 10:00', $demo->ago( -3 ) ) ), 4, 16, 44, 200, '' ),
			array( 'wc_order_status_changed', $order_context( 5 ) + array( 'from_status' => 'pending', 'to_status' => 'processing' ), 3, 8, 37, 429, 'Too Many Requests' ),
			array( 'wc_order_created', array( 'status' => 'processing' ) + $order_context( 6 ), 1, 14, 9, 200, '' ),
			array( 'user_submits_gravityform', array( 'form_title' => 'Plant question', 'name' => 'Nadia Petrova', 'email' => 'nadia.petrova@example.org', 'message' => 'My fiddle leaf is dropping its lower leaves. Too much water or not enough light?' ), 0.2, 9, 26, 0, 'Service not available' ),
		);
		foreach ( $log as $i => $row ) {
			list( $trigger, $context, $days, $hour, $minute, $code, $error ) = $row;
			$sent    = $demo->ago( $days, $hour, $minute );
			$payload = array(
				'headers' => array(
					'Referer'        => get_site_url(),
					'RefererRestUrl' => str_replace( '/wp-json/', '', get_rest_url() ),
				),
				'body'    => array(
					'trigger'                => $trigger,
					'context'                => $context,
					'wordpress_webhook_uuid' => md5( 'larkspur-ottokit-' . $i ) . '_' . rawurlencode( $site ),
				),
				'timeout' => 60,
			);
			$failed  = 200 !== $code;
			$wpdb->insert(
				$otto,
				array(
					'request_method' => 'POST',
					'request_url'    => $endpoint,
					'request_data'   => (string) wp_json_encode( $payload ),
					'response_code'  => $code,
					'status'         => $failed ? 'failed' : 'success',
					'error_info'     => $error,
					// Failed rows have used every retry, so OttoKit's cron does not send them again.
					'retry_attempts' => $failed ? 5 : 0,
					'processed_at'   => $failed ? $demo->local( $sent + 150 * MINUTE_IN_SECONDS ) : null,
					'created_at'     => $demo->local( $sent ),
					'updated_at'     => $demo->local( $failed ? $sent + 150 * MINUTE_IN_SECONDS : $sent ),
				)
			);
			$demo->remember( 'builders_ottokit', (string) $i, (int) $wpdb->insert_id );
		}
		$demo->log( count( $log ) . ' OttoKit requests' );
	}
};
