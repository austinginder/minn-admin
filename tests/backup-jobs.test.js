/**
 * The other backup engines on the background-job primitive: UpdraftPlus,
 * BackWPup and Duplicator each start a SMALL run (database only) through
 * their status-card action route, answer a job descriptor, report progress
 * on the status route and reach done; every archive the suite makes is
 * deleted after. WPvivid proves the start/stop half only: its compressor
 * runs in WP-Cron and on this stack the worker dies mid-run, so a full run
 * cannot be pinned here.
 */
const { BASE, launch, login, reporter } = require( './helpers' );
const { evalPhp, apiFor } = require( './_jet-common' );
( async () => {
	const t = reporter( 'backup-jobs' );
	const { browser, page, errors } = await launch();
	await login( page );
	const api = apiFor( page );
	const poll = async ( job, max ) => {
		const t0 = Date.now();
		let last = null;
		while ( Date.now() - t0 < max ) {
			await page.waitForTimeout( 2000 );
			try {
				const r = await api( job.statusRoute, { method: job.statusMethod || 'GET' } );
				last = r.body;
				if ( last && last.status && last.status !== 'running' ) return last;
			} catch ( e ) { /* a dropped poll is fine, the app tolerates them too */ }
		}
		return last;
	};
	const updraftBefore = evalPhp( 'bj-u0', 'echo wp_json_encode( array_keys( (array) UpdraftPlus_Backup_History::get_history() ) );' );
	let backwpupBefore = [];
	let dupBefore = [];
	let wpvividTask = '';
	try {
		/* ===== UpdraftPlus ===== */
		const ust = await api( 'minn-admin/v1/updraft/card' );
		const uact = ( ust.body.actions || [] ).filter( ( a ) => a.job === true );
		t.check( 'the UpdraftPlus card offers its backup actions as jobs', uact.length >= 2 && uact.some( ( a ) => a.body && a.body.what === 'db' ), JSON.stringify( uact.map( ( a ) => a.label ) ) );
		const us = await api( 'minn-admin/v1/updraft/backup-now', { method: 'POST', body: { what: 'db' } } );
		t.check( 'a database-only UpdraftPlus backup starts with a job descriptor', us.status === 200 && us.body.job && us.body.job.statusRoute && us.body.job.stopRoute, JSON.stringify( us.body ) );
		if ( us.body.job ) {
			const end = await poll( us.body.job, 240000 );
			t.check( 'UpdraftPlus reaches done', !! end && end.status === 'done', JSON.stringify( end ) );
			const busy = await api( 'minn-admin/v1/updraft/backups' );
			t.check( 'the new set is in the backups list', busy.status === 200 && ( busy.body.items || [] ).length >= 1, String( busy.status ) );
		}

		/* ===== BackWPup ===== */
		backwpupBefore = ( ( await api( 'minn-admin/v1/backwpup/backups' ) ).body.items || [] ).map( ( i ) => i.id );
		const bst = await api( 'minn-admin/v1/backwpup/status' );
		const bact = ( bst.body.actions || [] ).find( ( a ) => a.job === true );
		t.check( 'the BackWPup card offers Run job now as a job', !! bact && /Run job/.test( bact.label ), JSON.stringify( bact ) );
		const bs = await api( 'minn-admin/v1/backwpup/run', { method: 'POST', body: { jobid: 2 } } );
		t.check( 'running the database job answers a job descriptor', bs.status === 200 && bs.body.job && bs.body.job.statusRoute, JSON.stringify( bs.body ) );
		if ( bs.body.job ) {
			const end = await poll( bs.body.job, 180000 );
			t.check( 'BackWPup reaches done (their lastrun stamp is a site-local epoch, compared in UTC)', !! end && end.status === 'done', JSON.stringify( end ) );
			const made = ( ( await api( 'minn-admin/v1/backwpup/backups' ) ).body.items || [] ).filter( ( i ) => ! backwpupBefore.includes( i.id ) );
			t.check( 'the run left a new archive in the backups list', made.length >= 1, JSON.stringify( made.map( ( i ) => i.id ) ) );
			for ( const i of made ) {
				const del = await api( `minn-admin/v1/backwpup/backups/${ encodeURIComponent( i.id ) }`, { method: 'DELETE' } );
				t.check( `the archive ${ i.id } deletes through Minn`, del.status === 200, String( del.status ) );
			}
		}

		/* ===== Duplicator ===== */
		dupBefore = ( ( await api( 'minn-admin/v1/duplicator/packages' ) ).body.items || [] ).map( ( i ) => String( i.id ) );
		const dst = await api( 'minn-admin/v1/duplicator/status' );
		const dact = ( dst.body.actions || [] ).find( ( a ) => a.job === true );
		t.check( 'the Duplicator card offers Build a package as a job with name + database-only', !! dact && ( dact.fields || [] ).some( ( f ) => f.key === 'db_only' ), JSON.stringify( dact ) );
		const ds = await api( 'minn-admin/v1/duplicator/build', { method: 'POST', body: { name: 'minn-suite', db_only: true } } );
		t.check( 'a database-only build starts with a POST-polled job descriptor', ds.status === 200 && ds.body.job && ds.body.job.statusMethod === 'POST', JSON.stringify( ds.body ) );
		if ( ds.body.job ) {
			const end = await poll( ds.body.job, 240000 );
			t.check( 'Duplicator reaches done', !! end && end.status === 'done', JSON.stringify( end ) );
			const made = ( ( await api( 'minn-admin/v1/duplicator/packages' ) ).body.items || [] ).filter( ( i ) => ! dupBefore.includes( String( i.id ) ) );
			// Duplicator 5.0 names requested packages itself (the field is a
			// note there, and the card says so); 1.5 takes the name, with its
			// sanitizer turning the dash into an underscore.
			const noteOnly = ( dact.fields || [] ).some( ( f ) => f.key === 'name' && /note/i.test( f.label || '' ) );
			t.check( noteOnly ? 'the build left a new package (Duplicator 5.0 names it)' : 'the build left a package named minn-suite',
				made.length >= 1 && ( noteOnly || made.some( ( i ) => /minn[-_]suite/.test( i.name || i.title || '' ) ) ), JSON.stringify( made.map( ( i ) => [ i.id, i.name ] ) ) );
			for ( const i of made ) {
				const del = await api( `minn-admin/v1/duplicator/packages/${ i.id }`, { method: 'DELETE' } );
				t.check( `package ${ i.id } deletes through Minn`, del.status === 200, String( del.status ) );
			}
		}

		/* ===== WPvivid: start and stop ===== */
		const ws = await api( 'minn-admin/v1/wpvivid/backup-now', { method: 'POST', body: { what: 'db' } } );
		t.check( 'a WPvivid backup starts with a job descriptor', ws.status === 200 && ws.body.job && ws.body.job.statusRoute && ws.body.job.stopRoute, JSON.stringify( ws.body ) );
		if ( ws.body.job ) {
			wpvividTask = ws.body.job.id;
			const first = await api( ws.body.job.statusRoute );
			t.check( 'its status route answers running with a step message', first.status === 200 && first.body.status === 'running' && typeof first.body.message === 'string', JSON.stringify( first.body ) );
			const stop = await api( ws.body.job.stopRoute, { method: 'DELETE' } );
			t.check( 'Stop answers canceled', stop.status === 200 && stop.body.status === 'canceled', JSON.stringify( stop.body ) );
		}

		/* ===== The UI shows the job actions on the Backups card ===== */
		await page.goto( `${ BASE }/minn-admin/updraftplus`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-surface-status [data-sstatact]', { timeout: 30000 } );
		const labels = await page.$$eval( '.minn-surface-status [data-sstatact]', ( els ) => els.map( ( e ) => e.textContent.trim() ) );
		t.check( 'the UpdraftPlus Backups card paints its backup actions', labels.some( ( l ) => /Database only/.test( l ) ), JSON.stringify( labels ) );
	} catch ( e ) {
		t.check( 'the suite ran to its end without an exception', false, String( e && e.message || e ) );
	} finally {
		// UpdraftPlus keeps no delete route; drop the sets this run made
		// (history entry + files) the way their own delete does.
		evalPhp( 'bj-u1', `global $updraftplus; $before = json_decode( ${ JSON.stringify( updraftBefore || '[]' ) }, true ); $h = (array) UpdraftPlus_Backup_History::get_history(); $dir = $updraftplus->backups_dir_location(); foreach ( $h as $ts => $set ) { if ( in_array( $ts, $before ) ) continue; foreach ( glob( $dir . '/backup_*_' . ( $set['nonce'] ?? 'none' ) . '-*' ) as $f ) { @unlink( $f ); } unset( $h[ $ts ] ); } UpdraftPlus_Backup_History::save_history( $h ); echo count( $h );` );
		if ( wpvividTask ) {
			evalPhp( 'bj-w1', `WPvivid_Setting::delete_task( ${ JSON.stringify( wpvividTask ) } ); wp_unschedule_hook( 'wpvivid_resume_schedule_event' ); wp_unschedule_hook( 'minn_admin_wpvivid_run' ); echo 'ok';` );
		}
		await t.done( browser, errors );
	}
} )();
