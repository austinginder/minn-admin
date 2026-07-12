/**
 * Editor writing stats: session delta freezes after first edit, word goal
 * rides localStorage and paints on the sticky pill, click opens the setter.
 */
const { launch, login, reporter, createPost, deletePost, openEditor } = require( './helpers' );

( async () => {
	const t = reporter( 'writing-stats' );
	const { browser, page, errors } = await launch();
	await login( page );

	let postId = 0;
	try {
		postId = await createPost( page, {
			title: 'Writing stats probe',
			content: '<!-- wp:paragraph -->\n<p>One two three four five.</p>\n<!-- /wp:paragraph -->',
			status: 'draft',
		} );

		await page.evaluate( () => {
			try { localStorage.removeItem( 'minn-writing-goal' ); } catch ( e ) { /* ignore */ }
		} );

		await openEditor( page, postId );
		await page.waitForSelector( '#minn-editor-stats', { timeout: 15000 } );
		await page.waitForSelector( '#minn-editor-body', { timeout: 10000 } );

		// Baseline: loaded content has 5 words, no session delta yet (still clean).
		const baseline = await page.evaluate( () => {
			const el = document.querySelector( '#minn-editor-stats' );
			return {
				text: ( el && el.textContent || '' ).replace( /\s+/g, ' ' ).trim(),
				hasSession: /session/i.test( el && el.textContent || '' ),
			};
		} );
		t.check( 'pill shows word count on load', /\d+\s*words/.test( baseline.text ), baseline.text );
		t.check( 'no session delta while clean', ! baseline.hasSession, baseline.text );

		// Type more words → session should appear.
		await page.click( '#minn-editor-body' );
		// Move to end of body content.
		await page.keyboard.press( 'End' );
		await page.keyboard.press( 'Enter' );
		await page.keyboard.type( 'alpha beta gamma delta epsilon' );
		// Wait for dirty + stats tick (input/debounce).
		await page.waitForFunction( () => {
			const el = document.querySelector( '#minn-editor-stats' );
			return el && /session/i.test( el.textContent );
		}, null, { timeout: 10000 } );

		const after = await page.evaluate( () => {
			const el = document.querySelector( '#minn-editor-stats' );
			const text = ( el && el.textContent || '' ).replace( /\s+/g, ' ' ).trim();
			const m = text.match( /\+(\d+)\s*session/ );
			return { text, session: m ? parseInt( m[ 1 ], 10 ) : 0 };
		} );
		t.check( 'session delta appears after typing', after.session >= 4, after.text );

		// Set a goal via the popover.
		await page.click( '#minn-editor-stats' );
		await page.waitForSelector( '#minn-stats-goal-pop', { timeout: 5000 } );
		await page.fill( '#minn-stats-goal-input', '500' );
		await page.click( '#minn-stats-goal-save' );
		await page.waitForFunction( () => {
			const el = document.querySelector( '#minn-editor-stats' );
			return el && /\/\s*500/.test( el.textContent.replace( /\s+/g, ' ' ) );
		}, null, { timeout: 5000 } );

		const withGoal = await page.evaluate( () => {
			const el = document.querySelector( '#minn-editor-stats' );
			const stored = localStorage.getItem( 'minn-writing-goal' );
			return {
				text: ( el && el.textContent || '' ).replace( /\s+/g, ' ' ).trim(),
				stored,
				hasGoalClass: el && el.classList.contains( 'has-goal' ),
			};
		} );
		t.check( 'goal persists in localStorage', withGoal.stored === '500', withGoal.stored );
		t.check( 'pill shows total / goal', /\/\s*500/.test( withGoal.text ), withGoal.text );
		t.check( 'pill has has-goal class', withGoal.hasGoalClass );

		// Clear goal.
		await page.click( '#minn-editor-stats' );
		await page.waitForSelector( '#minn-stats-goal-pop', { timeout: 5000 } );
		await page.click( '#minn-stats-goal-clear' );
		await page.waitForFunction( () => {
			const el = document.querySelector( '#minn-editor-stats' );
			return el && ! /\/\s*\d+/.test( el.textContent ) && ! el.classList.contains( 'has-goal' );
		}, null, { timeout: 5000 } );
		const cleared = await page.evaluate( () => localStorage.getItem( 'minn-writing-goal' ) );
		t.check( 'goal cleared from storage', ! cleared, String( cleared ) );

		// Meet a low goal → goal-met styling.
		await page.evaluate( () => localStorage.setItem( 'minn-writing-goal', '3' ) );
		await page.evaluate( () => {
			// Force a stats refresh the way typing would.
			const body = document.querySelector( '#minn-editor-body' );
			if ( body ) body.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		} );
		// updateEditorStats rides many paths; click save or wait and invoke via typing space
		await page.click( '#minn-editor-body' );
		await page.keyboard.type( ' ' );
		await page.waitForFunction( () => {
			const el = document.querySelector( '#minn-editor-stats' );
			return el && el.classList.contains( 'goal-met' );
		}, null, { timeout: 8000 } );
		t.check( 'goal-met class when words exceed goal', true );

		await page.evaluate( () => localStorage.removeItem( 'minn-writing-goal' ) );
		await t.done( browser, errors );
	} catch ( e ) {
		console.error( e );
		await t.done( browser, errors.concat( [ String( e ) ] ) );
		process.exit( 1 );
	} finally {
		if ( postId ) await deletePost( page, postId ).catch( () => {} );
	}
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
