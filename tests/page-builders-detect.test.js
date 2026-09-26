/**
 * SiteOrigin Page Builder + Oxygen detection (page-builders.php).
 *
 * Both keep the real page outside post_content (SiteOrigin's panels_data
 * postmeta with a rendered mirror in post_content; Oxygen's _ct_builder_json
 * or legacy shortcode meta), so Minn must lock the body and send the writer to
 * the builder instead of silently editing a copy that never renders.
 *
 * Proves: a SiteOrigin page locks with an "Edit in SiteOrigin Page Builder"
 * link to their Live Editor, and that link really opens it; with the plugin
 * off the page stays fenced and points at Extensions; an Oxygen 4 page
 * (installed-inactive fixture, including a legacy unprefixed-shortcode page)
 * is fenced the same way; a plain page is untouched; zero console errors.
 *
 * Fixtures: siteorigin-panels + oxygen 4.9.7 are installed-INACTIVE on
 * minnadmin. SiteOrigin is activated for part of the run and restored;
 * Oxygen is never activated here (an active Oxygen 4 takes over front-end
 * rendering site-wide).
 */
const { execSync } = require( 'child_process' );
const { BASE, WP, launch, login, reporter } = require( './helpers' );

const wp = ( args ) => execSync( `wp --path=${ JSON.stringify( WP ) } ${ args } 2>/dev/null` ).toString().trim();
const wpPhp = ( php ) => execSync( `wp --path=${ JSON.stringify( WP ) } eval-file - --user=admin 2>/dev/null`, { input: '<?php ' + php } )
	.toString().trim().split( '\n' ).pop();
const installed = ( slug ) => { try { wp( `plugin is-installed ${ slug }` ); return true; } catch ( e ) { return false; } };
const isActive = ( slug ) => { try { wp( `plugin is-active ${ slug }` ); return true; } catch ( e ) { return false; } };

( async () => {
	const t = reporter( 'page-builders-detect' );
	if ( ! installed( 'siteorigin-panels' ) || ! installed( 'oxygen' ) ) {
		console.log( 'SKIP  siteorigin-panels and oxygen fixtures are not both installed' );
		process.exit( 0 );
	}
	const soWasActive = isActive( 'siteorigin-panels' );
	if ( isActive( 'oxygen' ) ) {
		console.log( 'SKIP  Oxygen is active here; this suite only drives it installed-inactive' );
		process.exit( 0 );
	}
	const ids = JSON.parse( wpPhp( `
		$mk = function ( $title, $meta ) {
			$id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => $title, 'post_content' => '<p>Stale mirror copy</p>' ) );
			foreach ( $meta as $k => $v ) { update_post_meta( $id, $k, $v ); }
			return $id;
		};
		echo wp_json_encode( array(
			'so'    => $mk( 'Minn suite SiteOrigin page', array( 'panels_data' => array( 'widgets' => array( array( 'text' => 'Hello', 'panels_info' => array( 'class' => 'WP_Widget_Text', 'grid' => 0, 'cell' => 0, 'id' => 0 ) ) ), 'grids' => array( array( 'cells' => 1 ) ), 'grid_cells' => array( array( 'grid' => 0, 'weight' => 1 ) ) ) ) ),
			'ox'    => $mk( 'Minn suite Oxygen page', array( '_ct_builder_json' => '{"id":0,"name":"root","depth":0,"children":[]}' ) ),
			'oxold' => $mk( 'Minn suite Oxygen legacy page', array( 'ct_builder_shortcodes' => '[ct_section ct_id=1][/ct_section]' ) ),
			'plain' => $mk( 'Minn suite plain page', array() ),
		) );
	` ) );

	const { browser, page, errors } = await launch();
	const note = async ( id ) => {
		await page.goto( `${ BASE }/minn-admin/editor/pages/${ id }`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '#minn-editor-body', { timeout: 20000 } );
		await page.waitForTimeout( 800 );
		return page.evaluate( () => {
			const n = document.querySelector( '.minn-builder-note' );
			const body = document.querySelector( '#minn-editor-body' );
			return {
				note: n ? n.textContent.replace( /\s+/g, ' ' ).trim() : '',
				href: n && n.tagName === 'A' ? n.getAttribute( 'href' ) : '',
				locked: !! body && ( body.classList.contains( 'locked' ) || 'false' === body.getAttribute( 'contenteditable' ) ),
			};
		} );
	};

	try {
		await login( page );

		/* ===== SiteOrigin active: locked, one link to their Live Editor ===== */
		if ( ! soWasActive ) wp( 'plugin activate siteorigin-panels' );
		const so = await note( ids.so );
		t.check( 'SiteOrigin page: body locked, note links to their Live Editor',
			so.locked && /SiteOrigin Page Builder/.test( so.note ) && /post\.php\?post=\d+&action=edit&so_live_editor=1/.test( so.href ), JSON.stringify( so ) );
		const live = await page.evaluate( async ( href ) => {
			const r = await fetch( href, { credentials: 'same-origin' } );
			const html = await r.text();
			return { status: r.status, liveEditor: /so-panels-live-editor|siteorigin-panels-live-editor|live-editor/i.test( html ) };
		}, so.href );
		t.check( 'the Live Editor link opens their editor', 200 === live.status && live.liveEditor, JSON.stringify( live ) );

		/* ===== SiteOrigin off: still fenced, points at Extensions ===== */
		wp( 'plugin deactivate siteorigin-panels' );
		const soOff = await note( ids.so );
		t.check( 'SiteOrigin off: page stays read-only and says the builder is off',
			soOff.locked && /currently off/.test( soOff.note ) && /extensions/.test( soOff.href ), JSON.stringify( soOff ) );

		/* ===== Oxygen 4 (installed-inactive): both storage generations fenced ===== */
		const ox = await note( ids.ox );
		t.check( 'Oxygen page (_ct_builder_json) is fenced as an Oxygen page', ox.locked && /Oxygen/.test( ox.note ), JSON.stringify( ox ) );
		const oxOld = await note( ids.oxold );
		t.check( 'legacy Oxygen page (unprefixed shortcodes) is fenced too', oxOld.locked && /Oxygen/.test( oxOld.note ), JSON.stringify( oxOld ) );

		/* ===== Plain page untouched ===== */
		const plain = await note( ids.plain );
		t.check( 'a plain page gets no builder note and stays editable', ! plain.note && ! plain.locked, JSON.stringify( plain ) );
	} finally {
		try { wp( `post delete ${ Object.values( ids ).join( ' ' ) } --force` ); } catch ( e ) {}
		try { wp( soWasActive ? 'plugin activate siteorigin-panels' : 'plugin deactivate siteorigin-panels' ); } catch ( e ) {}
	}

	await t.done( browser, errors );
} )().catch( ( e ) => { console.error( e ); process.exit( 1 ); } );
