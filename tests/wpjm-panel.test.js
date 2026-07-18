/**
 * WP Job Manager editor panel — the "Job listing" door on job_listing posts
 * (adapters/wp-job-manager.php). Schema is read live from WPJM's own
 * get_job_listing_fields(); writes prefer each field's own declared
 * sanitize_callback. Server truth is read back through the dedicated
 * minn_wpjm field after a real UI save.
 */
const { launch, login, reporter, BASE } = require( './helpers' );

( async () => {
	const t = reporter( 'wpjm-panel' );
	const { browser, page, errors } = await launch();
	await login( page );
	await page.goto( BASE + '/minn-admin/', { waitUntil: 'domcontentloaded' } );
	await page.waitForFunction( () => window.MINN, null, { timeout: 20000 } );

	const postId = await page.evaluate( async () => {
		const r = await fetch( window.MINN.restUrl + 'wp/v2/job-listings', {
			method: 'POST', credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			body: JSON.stringify( { title: 'Minn Suite Job', status: 'draft' } ),
		} );
		return ( await r.json() ).id;
	} );
	t.check( 'listing created over core REST', !! postId, String( postId ) );

	const readJob = () => page.evaluate( async ( pid ) => {
		const r = await fetch( window.MINN.restUrl + `wp/v2/job-listings/${ pid }?context=edit&_fields=minn_wpjm&_cb=` + Math.random(), {
			headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
		} );
		return ( await r.json() ).minn_wpjm;
	}, postId );

	try {
		await page.goto( BASE + '/minn-admin/editor/job-listings/' + postId, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-side-door="panel:wpjm"]', { timeout: 20000 } );
		const door = await page.$eval( '[data-side-door="panel:wpjm"]', ( el ) => el.textContent );
		t.check( 'Job listing door renders with the WPJM badge', /Job listing/.test( door ) && /WP Job Manager/.test( door ), door.trim().replace( /\s+/g, ' ' ) );

		await page.click( '[data-side-door="panel:wpjm"]' );
		await page.waitForSelector( '.minn-editor-side-modal [data-pf="wpjm:_job_location"]', { timeout: 10000 } );
		t.check( 'schema fields render from WPJM\'s own schema',
			!! ( await page.$( '[data-pf="wpjm:_company_name"]' ) ) && !! ( await page.$( '[data-pf="wpjm:_job_salary_unit"]' ) ) );

		await page.fill( '[data-pf="wpjm:_job_location"]', 'Lancaster, PA' );
		await page.fill( '[data-pf="wpjm:_company_name"]', 'Anchor Hosting' );
		await page.fill( '[data-pf="wpjm:_application"]', 'jobs@example.com' );
		await page.selectOption( '[data-pf="wpjm:_job_salary_unit"]', 'YEAR' );
		await page.evaluate( () => {
			const el = document.querySelector( '[data-pf="wpjm:_remote_position"]' );
			if ( el ) el.click();
		} );
		await page.keyboard.press( 'Meta+s' );

		let job = null;
		for ( let i = 0; i < 20; i++ ) {
			job = await readJob();
			if ( job && job._job_location === 'Lancaster, PA' && job._remote_position === true ) break;
			await page.waitForTimeout( 500 );
		}
		t.check( 'listing details persisted through WPJM conventions',
			!! job && job._job_location === 'Lancaster, PA' && job._company_name === 'Anchor Hosting'
				&& job._application === 'jobs@example.com' && job._job_salary_unit === 'YEAR' && job._remote_position === true,
			JSON.stringify( job ) );

		// Their own sanitizer rules the writes: a junk expiry date stores ''.
		const sanitized = await page.evaluate( async ( pid ) => {
			const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
			await fetch( window.MINN.restUrl + 'wp/v2/job-listings/' + pid, {
				method: 'POST', headers: h, credentials: 'same-origin',
				body: JSON.stringify( { minn_wpjm: { _job_expires: 'not a date' } } ),
			} );
			const r = await fetch( window.MINN.restUrl + `wp/v2/job-listings/${ pid }?context=edit&_fields=minn_wpjm&_cb=` + Math.random(), {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return ( await r.json() ).minn_wpjm._job_expires;
		}, postId );
		t.check( 'WPJM\'s own date sanitizer rules the expiry write', sanitized === '', JSON.stringify( sanitized ) );
	} finally {
		await page.evaluate( async ( pid ) => {
			await fetch( window.MINN.restUrl + 'wp/v2/job-listings/' + pid + '?force=true', {
				method: 'DELETE', headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} ).catch( () => {} );
		}, postId ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
