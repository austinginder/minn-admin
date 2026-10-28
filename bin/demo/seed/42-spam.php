<?php
/**
 * Spam protection: three weeks of Breakwater's log. The shop ran it in
 * monitor mode for its first week (spam was logged and let through, the
 * comparison it shows before anyone trusts it) and in protect mode since,
 * so newer spam is held. Customers' real questions on the contact form and
 * comments on the journal were let through clean.
 *
 * Rows go in through Breakwater's own Log::insert(), so each carries the
 * fingerprint, bands and payload Breakwater itself would write, then each
 * row's created_at is moved back to its day (insert stamps the run's time).
 * Spam comes from documentation IPs and reserved domains like everything
 * else here; the signals are Breakwater's own codes and wording.
 *
 * @package minn-admin
 */

return function ( Minn_Demo_Seed $demo ) {
	global $wpdb;
	if ( ! class_exists( '\Breakwater\Log' ) ) {
		WP_CLI::error( 'Breakwater is not active.' );
	}
	\Breakwater\Log::install();
	$table = \Breakwater\Log::table();

	// Clear what an earlier run made.
	$made = array_values( array_filter( array_map( 'intval', $demo->ids( 'spam' ) ) ) );
	if ( $made ) {
		$wpdb->query( "DELETE FROM {$table} WHERE id IN (" . implode( ',', $made ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
	$demo->forget( 'spam' );

	$gf_ids = array_values( array_filter( array_map( 'intval', $demo->ids( 'forms-gravity' ) ) ) );
	$gf     = $gf_ids && class_exists( 'GFAPI' ) ? GFAPI::get_form( $gf_ids[0] ) : null;
	$form   = $gf ? $gf['id'] . ': ' . $gf['title'] : '1: Contact us';
	$posts  = array_values( array_filter( array_map( 'intval', $demo->ids( 'posts' ) ) ) );
	$post   = function ( $i ) use ( $posts ) {
		return $posts ? $posts[ $i % count( $posts ) ] : 1;
	};

	$agents = array(
		'safari' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
		'iphone' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
		'chrome' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36',
		'bot'    => 'python-requests/2.31.0',
		'old'    => 'Mozilla/5.0 (Windows NT 6.1; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/88.0.4324.150 Safari/537.36',
		'none'   => '',
	);
	$customers = $demo->people( 'customer' );
	$cust      = function ( $i ) use ( $customers ) {
		$p = $customers[ $i % count( $customers ) ];
		return array( $p['first'] . ' ' . $p['last'], $p['email'] );
	};

	// days ago, hour, source, verdict (spam?), action, score, signals, ip, agent, name, email, message, extra field.
	list( $ana, $ana_mail ) = $cust( 0 );
	list( $ben, $ben_mail ) = $cust( 1 );
	list( $cal, $cal_mail ) = $cust( 2 );
	$rows = array(
		array( 20, 9, 'gravityforms', true, 'logged', 9, array( 'many_links' => array( 3, '4 links' ), 'markup_links' => array( 4, 'links written as [url] or HTML' ), 'too_fast' => array( 3, 'sent 2 seconds after the page loaded' ) ), '203.0.113.41', 'old', 'Rank Higher Today', 'seo@rankhigher.example', 'We can put larkspur.example on page one of Google in 30 days. [url=https://rankhigher.example]See our packages[/url] https://rankhigher.example/a https://rankhigher.example/b https://rankhigher.example/c', '' ),
		array( 19, 14, 'comment', true, 'logged', 8, array( 'no_js' => array( 6, 'the form was sent without the page\'s script' ), 'link_in_name' => array( 3, 'a link in the name field' ) ), '198.51.100.73', 'bot', 'https://cheap-pots.example', 'pots@cheap-pots.example', 'Great article!! Visit us for the cheapest planters online.', '' ),
		array( 18, 11, 'gravityforms', false, 'allowed', 1, array( 'links' => array( 1, '1 link' ) ), '192.0.2.140', 'safari', $ana, $ana_mail, 'Hi! My fiddle leaf fig from your shop is dropping leaves since I repotted it. I followed the care card at https://larkspur.example/care but no luck. Any ideas?', '(503) 555-0142' ),
		array( 17, 16, 'comment', false, 'allowed', 0, array(), '192.0.2.151', 'iphone', $ben, $ben_mail, 'This guide to pothos propagation was so clear. Mine finally rooted after two weeks in water.', '' ),
		array( 15, 3, 'gravityforms', true, 'logged', 11, array( 'foreign_script' => array( 4, 'mostly Cyrillic in an English form' ), 'many_links' => array( 3, '5 links' ), 'disposable_email' => array( 3, 'a throwaway email domain' ) ), '203.0.113.88', 'chrome', 'Crypto Desk', 'desk@mailinator.test', 'Заработок на криптовалюте без вложений https://coin.example/1 https://coin.example/2 https://coin.example/3 https://coin.example/4 https://coin.example/5', '' ),
		// Protect mode from here on: spam is held.
		array( 12, 10, 'gravityforms', true, 'blocked', 13, array( 'honeypot' => array( 10, 'the hidden field was filled in' ), 'too_fast' => array( 3, 'sent 1 second after the page loaded' ) ), '203.0.113.17', 'bot', 'Mark', 'mark.leads@example.net', 'Hello, I would like to offer you our lead generation services for your business.', '' ),
		array( 11, 13, 'comment', true, 'blocked', 10, array( 'many_links' => array( 3, '6 links' ), 'repeat_spam' => array( 3, 'the same message was caught on 3 other sites today' ), 'no_agent' => array( 3, 'no browser named itself' ), 'links' => array( 1, '6 links' ) ), '198.51.100.12', 'none', 'Loans Online', 'fast@loans.example', 'Need money fast? https://loans.example/1 https://loans.example/2 https://loans.example/3 https://loans.example/4 https://loans.example/5 https://loans.example/6', '' ),
		array( 10, 15, 'gravityforms', false, 'allowed', 0, array(), '192.0.2.163', 'chrome', $cal, $cal_mail, 'Do you still have the speckled ceramic pots in the large size? I would like to pick two up on Saturday.', '' ),
		array( 8, 8, 'woocommerce', true, 'blocked', 9, array( 'bot_name' => array( 3, 'a name made of random letters' ), 'dead_email_domain' => array( 3, 'the email domain takes no mail' ), 'webdriver' => array( 4, 'driven by browser automation' ) ), '203.0.113.201', 'chrome', 'xkqvbnwe', 'xkqvbnwe@nomail.invalid', '', '' ),
		array( 7, 19, 'comment', true, 'blocked', 7, array( 'markup_links' => array( 4, 'links written as [url] or HTML' ), 'spam_ip' => array( 2, '4 other spam from this IP in the last hour' ), 'links' => array( 1, '2 links' ) ), '198.51.100.45', 'old', 'Garden Supplies', 'sales@gardensupply.example', '<a href="https://gardensupply.example">garden supplies</a> best prices <a href="https://gardensupply.example/sale">sale</a>', '' ),
		array( 5, 12, 'gravityforms', true, 'blocked', 8, array( 'injection' => array( 6, 'code meant for the database or the page' ), 'no_interaction' => array( 2, 'no typing or pointer movement before sending' ) ), '203.0.113.66', 'bot', 'admin', 'test@example.org', "1' OR '1'='1'; DROP TABLE wp_users; -- <script>alert(1)</script>", '' ),
		array( 4, 11, 'comment', false, 'allowed', 1, array( 'all_caps' => array( 1, 'mostly capital letters' ) ), '192.0.2.144', 'iphone', $ana, $ana_mail, 'THANK YOU for the repotting tips, the fig has new leaves!!', '' ),
		array( 3, 9, 'gravityforms', true, 'blocked', 12, array( 'pow_invalid' => array( 8, 'the browser check failed' ), 'bot_agent' => array( 4, 'a crawler or script named itself' ) ), '198.51.100.99', 'bot', 'Partnership', 'partner@outreach.example', 'Dear site owner, we would like to publish a guest post on your blog with a do-follow link.', '' ),
		array( 2, 17, 'comment', true, 'blocked', 9, array( 'repeat_spam' => array( 3, 'the same message was caught on 5 other sites today' ), 'many_links' => array( 3, '4 links' ), 'too_fast' => array( 3, 'sent 3 seconds after the page loaded' ) ), '203.0.113.150', 'chrome', 'Best Replica Watches', 'watches@replica.example', 'Top quality replica watches https://replica.example/a https://replica.example/b https://replica.example/c https://replica.example/d', '' ),
		array( 1, 10, 'gravityforms', false, 'allowed', 0, array(), '192.0.2.170', 'safari', $ben, $ben_mail, 'Is the plant club workshop on the 14th still open? I would like to bring a friend.', '' ),
		array( 0.2, 8, 'comment', true, 'blocked', 11, array( 'no_js' => array( 6, 'the form was sent without the page\'s script' ), 'disposable_email' => array( 3, 'a throwaway email domain' ), 'links' => array( 1, '1 link' ) ), '198.51.100.201', 'bot', 'Ella', 'ella@mailinator.test', 'nice post, check my profile https://dating.example/ella', '' ),
	);

	$server = $_SERVER;
	try {
		foreach ( $rows as $i => $r ) {
			list( $days, $hour, $source, $spam, $action, $score, $signals, $ip, $agent, $name, $email, $message, $phone ) = $r;
			$is_comment              = 'comment' === $source;
			$where                   = $is_comment ? 'post ' . $post( $i ) : ( 'woocommerce' === $source ? 'Register' : $form );
			$_SERVER['HTTP_USER_AGENT'] = $agents[ $agent ];
			$_SERVER['REQUEST_URI']  = $is_comment ? '/?p=' . $post( $i ) : ( 'woocommerce' === $source ? '/my-account/' : '/contact/' );
			$_SERVER['REMOTE_ADDR']  = $ip;
			$fields                  = array(
				array( 'type' => 'name', 'label' => $is_comment ? 'author' : 'Name', 'value' => $name ),
				array( 'type' => 'email', 'label' => $is_comment ? 'email' : 'Email', 'value' => $email ),
			);
			if ( '' !== $phone ) {
				$fields[] = array( 'type' => 'phone', 'label' => 'Phone', 'value' => $phone );
			}
			if ( '' !== $message ) {
				$fields[] = array( 'type' => 'textarea', 'label' => $is_comment ? 'comment' : 'Message', 'value' => $message );
			}
			$s        = \Breakwater\Submission::fromFields( $source, $where, $fields );
			$s->ip    = $ip;
			$s->agent = $agents[ $agent ];
			$id       = \Breakwater\Log::insert( $s, new \Breakwater\Verdict( $spam, $score, $signals ), $action );
			if ( $id ) {
				$wpdb->update( $table, array( 'created_at' => $demo->gmt( $demo->ago( $days, $hour, ( $i * 7 ) % 60 ) ) ), array( 'id' => $id ) );
				$demo->remember( 'spam', (string) $i, $id );
			}
		}
	} finally {
		$_SERVER = $server;
	}

	update_option( 'breakwater', array_merge( (array) get_option( 'breakwater', array() ), array( 'mode' => 'protect' ) ) );
	$demo->log( count( $rows ) . ' Breakwater log rows, protect mode on' );
};
