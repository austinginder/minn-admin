/**
 * SureMails + Site Mailer mail-log adapters (v0.18.0, Wave B). Both log only
 * while they are the active mailer and rest installed-inactive (FluentSMTP is
 * the active mail resident), so the suite activates each, seeds a sent + a
 * failed row directly into its own table (the Post SMTP / WPMS seeding
 * pattern), drives list / tabs / search / detail (sandboxed HTML body) /
 * status card / delete, then restores inactive. Timestamps are stored in the
 * DB session zone and normalized to UTC by the adapter — the suite asserts the
 * emitted date is an absolute Z string.
 */
const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { BASE, launch, login, reporter } = require( './helpers' );

const WP_PATH = path.resolve( __dirname, '../../../..' );
const wp = ( args ) => execSync(
	`wp --path=${ JSON.stringify( WP_PATH ) } ${ args } 2>/dev/null`,
	{ encoding: 'utf8', timeout: 60000 }
).trim();
// PHP goes through a temp eval-file, NOT `wp eval "…"`: an inline
// double-quoted argument lets the shell expand $wpdb before wp-cli sees it.
// Retry across FrankenPHP restart windows (activation churn crashes the
// worker; the ~45s rebind window looks like a failed exec).
const evalPhp = ( php ) => {
	const file = path.join( os.tmpdir(), `minn-wb-${ process.pid }.php` );
	fs.writeFileSync( file, '<?php ' + php );
	try {
		for ( let attempt = 1; attempt <= 4; attempt++ ) {
			try {
				return execSync(
					`wp --path=${ JSON.stringify( WP_PATH ) } eval-file ${ JSON.stringify( file ) } 2>/dev/null`,
					{ encoding: 'utf8', timeout: 60000 }
				).trim();
			} catch ( e ) {
				if ( attempt === 4 ) return ( e.stdout || '' ).trim();
				execSync( 'sleep 3' );
			}
		}
	} finally {
		try { fs.unlinkSync( file ); } catch ( e ) { /* ignore */ }
	}
	return '';
};

const PROVIDERS = [
	{
		slug: 'suremails',
		base: 'minn-admin/v1/suremails',
		sentStatus: 'sent',
		failedStatus: 'failed',
		sentTab: 'sent',
		seed: ( subj, status ) => `global $wpdb; $wpdb->insert($wpdb->prefix."suremails_email_log", array("email_from"=>"site@example.com","email_to"=>maybe_serialize(array("dana@example.com")),"subject"=>"${ subj }","body"=>"<h1>Hi</h1><p>body</p>","status"=>"${ status }","connection"=>"SMTP","headers"=>maybe_serialize(""),"response"=>maybe_serialize(""),"attachments"=>maybe_serialize(array()))); echo $wpdb->insert_id;`,
		cleanup: 'global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}suremails_email_log WHERE subject LIKE \'minn-wb-%\'");',
	},
	{
		slug: 'site-mailer',
		base: 'minn-admin/v1/site-mailer',
		sentStatus: 'sent',
		failedStatus: 'failed',
		sentTab: 'delivered',
		seed: ( subj, status ) => `global $wpdb; $wpdb->insert($wpdb->prefix."site_mail_logs", array("to"=>"dana@example.com","subject"=>"${ subj }","message"=>"<h1>Hi</h1><p>body</p>","status"=>"${ status }","source"=>"wp_mail","headers"=>"Content-Type: text/html")); echo $wpdb->insert_id;`,
		cleanup: 'global $wpdb; $wpdb->query("DELETE FROM {$wpdb->prefix}site_mail_logs WHERE subject LIKE \'minn-wb-%\'");',
	},
];

( async () => {
	const t = reporter( 'wave-b-mail' );
	const { browser, page, errors } = await launch();
	await login( page );

	const api = ( p, opts ) => page.evaluate( async ( [ pathArg, o ] ) => {
		const r = await fetch( window.MINN.restUrl + pathArg + ( pathArg.includes( '?' ) ? '&' : '?' ) + '_cb=' + Math.random(), {
			method: ( o && o.method ) || 'GET',
			headers: { 'X-WP-Nonce': window.MINN.nonce, 'Content-Type': 'application/json' },
			credentials: 'same-origin',
		} );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, [ p, opts || null ] );

	for ( const pr of PROVIDERS ) {
		let wasActive = true;
		try {
			try {
				execSync( `wp --path=${ JSON.stringify( WP_PATH ) } plugin is-active ${ pr.slug }`, { stdio: 'ignore', timeout: 30000 } );
			} catch ( e ) {
				wasActive = false;
			}
			if ( ! wasActive ) wp( `plugin activate ${ pr.slug }` );

			const sentId = parseInt( evalPhp( pr.seed( 'minn-wb-sent', pr.sentStatus ) ), 10 );
			evalPhp( pr.seed( 'minn-wb-fail', pr.failedStatus ) );

			const list = await api( `${ pr.base }/emails` );
			t.check( `${ pr.slug }: list answers with the seeded rows`, list.status === 200 && ( list.body.total || 0 ) >= 2, JSON.stringify( { s: list.status, total: list.body && list.body.total } ) );
			const seeded = ( list.body.items || [] ).find( ( i ) => /minn-wb/.test( i.subject ) );
			t.check( `${ pr.slug }: row carries subject, to, pill status, absolute UTC date`,
				!! seeded && seeded.to.includes( '@' ) && seeded.status && /^\d{4}-\d\d-\d\dT.*Z$/.test( seeded.date || seeded.created_at || '' ),
				JSON.stringify( seeded ) );

			const sentTab = await api( `${ pr.base }/emails?status=${ pr.sentTab }` );
			t.check( `${ pr.slug }: status tab narrows the list`, sentTab.status === 200 && ( sentTab.body.total || 0 ) >= 1 && ( sentTab.body.total || 0 ) <= list.body.total, JSON.stringify( { total: sentTab.body && sentTab.body.total } ) );

			const search = await api( `${ pr.base }/emails?search=minn-wb-sent` );
			t.check( `${ pr.slug }: search finds the seeded subject`, search.status === 200 && ( search.body.total || 0 ) >= 1, JSON.stringify( { total: search.body && search.body.total } ) );

			const view = await api( `${ pr.base }/emails/${ sentId }/view` );
			const titles = ( view.body && view.body.sections || [] ).map( ( s ) => s.title );
			const msg = ( view.body && view.body.sections || [] ).find( ( s ) => s.title === 'Message' );
			const bodyRow = msg && msg.rows.find( ( r ) => r.label === 'Body' );
			t.check( `${ pr.slug }: detail has Delivery + Message with a status pill and sandboxed HTML body`,
				view.status === 200 && titles.includes( 'Delivery' ) && titles.includes( 'Message' )
				&& view.body.sections[ 0 ].rows[ 0 ].type === 'pill' && bodyRow && bodyRow.type === 'html-preview',
				JSON.stringify( { titles, bodyType: bodyRow && bodyRow.type } ) );

			const st = await api( `${ pr.base }/status` );
			t.check( `${ pr.slug }: status card has rows + a chart`, st.status === 200 && ( st.body.rows || [] ).length >= 1 && !! st.body.chart, JSON.stringify( { rows: ( st.body.rows || [] ).length, chart: !! st.body.chart } ) );

			// Delete the sent row, confirm it is gone.
			const del = await api( `${ pr.base }/emails/${ sentId }`, { method: 'DELETE' } );
			const gone = await api( `${ pr.base }/emails/${ sentId }/view` );
			t.check( `${ pr.slug }: delete removes the row`, del.status === 200 && del.body && del.body.deleted && gone.status === 404, JSON.stringify( { del: del.status, gone: gone.status } ) );

			// Browser: the surface renders under the mail family.
			await page.goto( `${ BASE }/minn-admin/${ pr.slug }`, { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( '.minn-surface-status', { timeout: 30000 } );
			t.check( `${ pr.slug }: surface renders card + a row`, await page.evaluate( () =>
				!! document.querySelector( '.minn-surface-status' ) && !! document.querySelector( '.minn-table-row' ) ) );
		} finally {
			try { evalPhp( pr.cleanup ); } catch ( e ) { /* best effort */ }
			if ( ! wasActive ) wp( `plugin deactivate ${ pr.slug }` );
		}
	}

	await t.done( browser, errors );
} )();
