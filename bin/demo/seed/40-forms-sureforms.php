<?php
/**
 * SureForms: the community garden donation request (schools, community
 * gardens and seed libraries asking for seeds, starts or a workshop visit),
 * with seven requests (four still unread) and one staff test sent to the trash.
 *
 * A SureForms form is a sureforms_form post of srfm/* blocks. Each
 * submission is one srfm_entries row: `form_data` is JSON keyed per block as
 * "srfm-<block type>-<block id>-lbl-<base64 label>-<slug>" (the label is read
 * back out of the key), `status` is unread, read or trash, and `logs` holds
 * the entry's activity (the notification email lines). created_at is a
 * TIMESTAMP column that SureForms fills with current_time( 'mysql' ), so the
 * string it holds is SITE-LOCAL time. Block ids are fixed here so the keys
 * are the same on every run. Rows go in through their Entries::add, which
 * writes the row and sends nothing.
 *
 * The Forms view shows views and conversion only when SureForms' own view
 * tracking is on: this part switches it on (srfm_general_settings_options),
 * opens the counting window 60 days back and gives the form its view count.
 *
 * @package minn-admin
 */

return function ( Minn_Demo_Seed $demo ) {
	if ( ! class_exists( 'SRFM\Inc\Database\Tables\Entries' ) ) {
		$demo->log( 'SureForms is not active: no SureForms forms' );
		return;
	}
	global $wpdb;
	$table = $wpdb->prefix . 'srfm_entries';
	$group = 'forms-sureforms';
	foreach ( $demo->ids( $group ) as $form_id ) {
		$wpdb->delete( $table, array( 'form_id' => (int) $form_id ) );
		wp_delete_post( (int) $form_id, true );
	}
	$demo->forget( $group );
	$demo->as_user( 'june' );

	// slug => block type, label, extra attributes.
	$blocks = array(
		'your-name'     => array( 'input', 'Your name', array( 'required' => true ) ),
		'email'         => array( 'email', 'Email', array( 'required' => true ) ),
		'phone'         => array( 'phone', 'Phone', array() ),
		'organization'  => array( 'input', 'School or organization', array( 'required' => true ) ),
		'org-type'      => array( 'dropdown', 'What kind of group?', array( 'options' => array( 'School', 'Community garden', 'Library or nonprofit', 'Something else' ) ) ),
		'request'       => array( 'dropdown', 'What would help most?', array( 'options' => array( 'Wildflower meadow seed mix', 'Sunflower seeds', 'Perennial starts', 'A workshop visit from Priya' ) ) ),
		'people'        => array( 'number', 'How many people will it reach?', array() ),
		'project'       => array( 'textarea', 'Tell us about the project', array( 'required' => true ) ),
	);
	$content = '';
	$keys    = array();
	foreach ( $blocks as $slug => $b ) {
		$block_id = substr( md5( 'garden-donation-' . $slug ), 0, 8 );
		$attrs    = array_merge( array( 'block_id' => $block_id, 'label' => $b[1], 'slug' => $slug ), $b[2] );
		if ( isset( $attrs['options'] ) ) {
			$attrs['options'] = array_map(
				function ( $label ) {
					return array( 'label' => $label, 'icon' => '' );
				},
				$attrs['options']
			);
		}
		$content      .= serialize_block( array( 'blockName' => 'srfm/' . $b[0], 'attrs' => $attrs, 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) ) . "\n";
		$keys[ $slug ] = 'srfm-' . $b[0] . '-' . $block_id . '-lbl-' . rtrim( base64_encode( $b[1] ), '=' ) . '-' . $slug; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- SureForms' own label encoding (Helper::encode).
	}
	$made    = $demo->ago( 90, 14, 25 );
	$form_id = wp_insert_post(
		array(
			'post_type'     => 'sureforms_form',
			'post_status'   => 'publish',
			'post_title'    => 'Community garden donation request',
			'post_content'  => $content,
			'post_author'   => $demo->id( 'users', 'june' ),
			'post_date'     => $demo->local( $made ),
			'post_date_gmt' => $demo->gmt( $made ),
		)
	);
	if ( ! $form_id || is_wp_error( $form_id ) ) {
		WP_CLI::warning( 'SureForms: could not create the donation form' );
		return;
	}
	if ( class_exists( 'SRFM\Inc\Create_New_Form' ) && method_exists( 'SRFM\Inc\Create_New_Form', 'get_default_meta_keys' ) ) {
		foreach ( \SRFM\Inc\Create_New_Form::get_default_meta_keys() as $key => $value ) {
			update_post_meta( $form_id, $key, $value );
		}
	}
	update_post_meta( $form_id, '_srfm_submit_button_text', 'Send request' );
	$demo->remember( $group, 'garden-donation', (int) $form_id );

	// Their view tracking, so the Forms view has views and a conversion rate.
	$general = get_option( 'srfm_general_settings_options' );
	$general = is_array( $general ) ? $general : array(
		'srfm_ip_log'             => false,
		'srfm_form_analytics'     => false,
		'srfm_admin_notification' => true,
		'srfm_enable_logs'        => true,
	);
	$general['srfm_form_views_tracking'] = true;
	update_option( 'srfm_general_settings_options', $general );
	update_option( 'srfm_form_views_tracking_started_at', $demo->ago( 60, 9, 0 ), false );
	update_post_meta( $form_id, '_srfm_form_views', 386 );

	// People who are not in the cast.
	$extra = array(
		'rosa'  => array( 'Rosa Delgado', 'rosa.delgado@example.org', '(503) 555-0171', 'Cully Community Garden' ),
		'hal'   => array( 'Hal Benson', 'hbenson@sunnyside-school.example', '(503) 555-0172', 'Sunnyside Elementary Garden Club' ),
		'imani' => array( 'Imani Brooks', 'imani.brooks@example.com', '(503) 555-0173', 'Northeast Seed Library' ),
		'gus'   => array( 'Gus Lindgren', 'gus@foodbankgarden.example', '(541) 555-0174', 'Willamette Food Bank Garden' ),
		'hana'  => array( 'Hana Sato', 'hana@larkspur.example', '(503) 555-0104', 'Larkspur & Fern' ),
	);
	// who, days ago, hour, group type, request, people, project, status.
	$requests = array(
		array( 'rosa', 52, 18, 'Community garden', 'Sunflower seeds', 60, 'Cully Community Garden has sixty plots and a long fence that faces south. We would love to plant a wall of sunflowers along it with the kids from the apartment complex next door. Any donation helps, even a few packets.', 'read' ),
		array( 'hal', 40, 7, 'School', 'A workshop visit from Priya', 85, "Our garden club has 85 kids from kindergarten to fifth grade. A few parents told us about Priya's Kids Sunflower Morning. Would she ever come to the school and run something like it in our courtyard? We can cover materials.", 'read' ),
		array( 'imani', 31, 12, 'Library or nonprofit', 'Wildflower meadow seed mix', 300, 'The Northeast Seed Library lends seeds to anyone with a library card. Native wildflowers are our most requested and we always run out by April. Would you consider donating a few bags of the meadow mix?', 'read' ),
		array( 'hana', 26, 16, 'Something else', 'Sunflower seeds', 1, 'Test entry from the shop to check the form sends. Please ignore.', 'trash' ),
		array( 'gus', 19, 9, 'Community garden', 'Perennial starts', 40, 'We grow vegetables for the Willamette food bank and want a pollinator border to help the squash and beans. Columbine and lupine starts would be ideal, and we are happy to pick them up in Portland.', 'unread' ),
		array( 'ben', 11, 20, 'Community garden', 'Sunflower seeds', 25, 'I help run a small community garden in the Old North End of Burlington. I bought sunflower seeds from you last spring and they did beautifully. Do you ship donated seed out of state? We would cover postage.', 'unread' ),
		array( 'nadia', 5, 14, 'School', 'Wildflower meadow seed mix', 120, 'My daughter teaches second grade in Eugene and her class is turning a strip of lawn into a meadow. Could the shop spare a bag of the meadow mix? I am happy to buy the second one.', 'unread' ),
		array( 'chloe', 0.9, 20, 'School', 'Wildflower meadow seed mix', 120, 'Our third graders planted a pollinator bed with the columbine and lupine I ordered from you, and the hummingbirds found it on day two. Next we would like to seed the strip along the fence. Any leftover meadow mix would make their spring.', 'unread' ),
	);
	$count = 0;
	foreach ( $requests as $n => $r ) {
		if ( isset( $extra[ $r[0] ] ) ) {
			list( $name, $email, $phone, $org ) = $extra[ $r[0] ];
			$user_id = 'hana' === $r[0] ? (int) $demo->id( 'users', 'hana' ) : 0;
		} else {
			$p       = $demo->person( $r[0] );
			$name    = $p['name'];
			$email   = $p['email'];
			$phone   = $p['phone'];
			$org     = array( 'ben' => 'Old North End Community Garden', 'nadia' => 'Edison Elementary, second grade', 'chloe' => 'Montford Elementary, third grade' )[ $r[0] ];
			$user_id = (int) $p['id'];
		}
		$data = array(
			$keys['your-name']    => $name,
			$keys['email']        => $email,
			$keys['phone']        => $phone,
			$keys['organization'] => $org,
			$keys['org-type']     => $r[3],
			$keys['request']      => $r[4],
			$keys['people']       => (string) $r[5],
			$keys['project']      => $r[6],
		);
		$ts    = $demo->ago( $r[1], $r[2], ( $n * 19 ) % 60 );
		$local = $demo->local( $ts );
		$sent  = $demo->local( $ts + 4 );
		$row   = array(
			'form_id'         => $form_id,
			'user_id'         => $user_id,
			'form_data'       => $data,
			'submission_info' => array(
				'user_ip'        => '198.51.100.' . ( 70 + $n ),
				'browser_name'   => 0 === $n % 2 ? 'Chrome' : 'Safari',
				'device_name'    => 0 === $n % 3 ? 'iPhone' : 'Macintosh',
				'submission_url' => 'https://larkspur.example/community/',
			),
			'logs'            => array(
				array(
					'title'     => 'Email notification passed to the sending server',
					'messages'  => array( 'Email notification recipient: hello@larkspur.example' ),
					'timestamp' => $sent,
				),
			),
			'status'          => $r[7],
			'created_at'      => $local,
		);
		$id = \SRFM\Inc\Database\Tables\Entries::add( $row );
		if ( $id ) {
			// Their schema leaves updated_at to the column default (now).
			$wpdb->update( $table, array( 'updated_at' => $local ), array( 'ID' => $id ) );
			++$count;
		}
	}
	$demo->log( '1 SureForms form, ' . $count . ' entries' );
};
