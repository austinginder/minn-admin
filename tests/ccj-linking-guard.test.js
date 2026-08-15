/**
 * Custom CSS & JS: promoting a snippet from external to internal linking is a
 * code write, and must answer to the same unfiltered_html gate as authoring.
 *
 * minn_admin_ccj_can_write_code() lets a caller without unfiltered_html store
 * raw bytes in exactly one shape: linking=external css, which loads through
 * wp_enqueue_style and cannot break out of its element. Every other shape is
 * inlined by rebuild_tree() between literal <style>/<script> tags. The PUT
 * handler re-checks on language and side changes; before this suite it did not
 * re-check on linking, so the inert shape could be created, activated, and then
 * promoted into the inlined sink by an options-only edit that skipped the gate.
 *
 * The controls matter as much as the block: refusing a rename, or refusing an
 * administrator, would be its own regression.
 *
 * SKIPs (exit 0) when the Custom CSS & JS plugin is not active.
 */
const { BASE, launch, login, reporter } = require( './helpers' );

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'ccj-linking-guard' );
	await login( page );

	const active = await page.evaluate( () =>
		( window.MINN.surfaces || [] ).some( ( s ) => s.id === 'custom-css-js' )
	);
	if ( ! active ) {
		console.log( 'SKIP: Custom CSS & JS not active' );
		await t.done( browser, errors );
		return;
	}

	// The payload only has to prove it escaped the <style> element. Core's kses
	// strips event handlers for this principal, which is the second line of
	// defense; this suite is about the first one holding on its own.
	const PAYLOAD = '</style><svg onload=alert(1)></svg>';

	const run = async ( noUnfiltered ) =>
		page.evaluate(
			async ( { payload, drop } ) => {
				const hdrs = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
				const api = window.MINN.restUrl;
				const setFlag = ( on ) =>
					fetch( api + 'wp/v2/settings', {
						method: 'POST',
						headers: hdrs,
						body: JSON.stringify( { minn_test_no_unfiltered_html: on ? '1' : '' } ),
					} );

				await setFlag( drop );
				const out = {};
				try {
					// Create the one shape a caller without unfiltered_html may store raw.
					const cRes = await fetch( api + 'minn-admin/v1/ccj/snippets', {
						method: 'POST',
						headers: hdrs,
						body: JSON.stringify( {
							name: 'minn ccj linking guard',
							code: payload,
							language: 'css',
							linking: 'external',
							side: 'frontend',
						} ),
					} );
					const created = await cRes.json();
					out.created = cRes.ok;
					if ( ! cRes.ok ) return out;
					const id = created.id;

					const aRes = await fetch( api + 'minn-admin/v1/ccj/snippets/' + id + '/active', {
						method: 'POST',
						headers: hdrs,
						body: JSON.stringify( { active: true } ),
					} );
					out.activated = aRes.ok;

					// A rename must stay open: this edit makes nothing execute.
					const rRes = await fetch( api + 'minn-admin/v1/ccj/snippets/' + id, {
						method: 'PUT',
						headers: hdrs,
						body: JSON.stringify( { name: 'minn ccj renamed' } ),
					} );
					out.renamed = rRes.ok;

					// The promotion: options-only, no code, no language/side change.
					const pRes = await fetch( api + 'minn-admin/v1/ccj/snippets/' + id, {
						method: 'PUT',
						headers: hdrs,
						body: JSON.stringify( { linking: 'internal' } ),
					} );
					out.promoted = pRes.ok;

					const after = await ( await fetch( api + 'minn-admin/v1/ccj/snippets/' + id, { headers: hdrs } ) ).json();
					out.linking = ( after.options && after.options.linking ) || after.linking || '';
					out.id = id;
				} finally {
					// Restore the capability BEFORE cleaning up: deleting a snippet
					// answers to the same gate as activating one, so a caller
					// without unfiltered_html cannot remove an inlined snippet and
					// the row would leak whenever this suite runs against code that
					// lets the promotion through.
					await setFlag( false );
					if ( out.id ) {
						await fetch( api + 'minn-admin/v1/ccj/snippets/' + out.id, { method: 'DELETE', headers: hdrs } );
					}
				}
				return out;
			},
			{ payload: PAYLOAD, drop: noUnfiltered }
		);

	/* ===== Without unfiltered_html: the gate must hold ===== */
	const low = await run( true );
	t.check( 'inert external css snippet is still creatable', low.created === true, JSON.stringify( low ) );
	t.check( 'inert external css snippet is still activatable', low.activated === true, JSON.stringify( low ) );
	t.check( 'renaming stays open (no over-block)', low.renamed === true, JSON.stringify( low ) );
	t.check( 'linking external -> internal is REFUSED', low.promoted === false, JSON.stringify( low ) );
	t.check( 'snippet stays external after the refusal', low.linking === 'external', JSON.stringify( low ) );

	/* ===== With unfiltered_html: the feature still works ===== */
	const admin = await run( false );
	t.check( 'administrator may still promote to internal', admin.promoted === true, JSON.stringify( admin ) );
	t.check( 'administrator promotion actually lands', admin.linking === 'internal', JSON.stringify( admin ) );

	await t.done( browser, errors );
} )();
