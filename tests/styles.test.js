/**
 * Design → Styles (/minn-admin/styles): the theme's style variations as
 * swatch cards, applied with a real Undo.
 *
 * The load-bearing checks go through to the FRONT END: applying Midnight must
 * change the site's rendered base color for a logged-out visitor, Undo must
 * put the previous config back verbatim, and the picker must list the
 * Browse-styles set (top-level variations only — never the color/typography
 * partials that duplicate titles).
 *
 * Activates twentytwentyfive for the run; snapshots and restores the user
 * global-styles config and the previous theme in finally.
 */
const { launch, login, reporter, BASE, autoConfirm, activateClassicTheme } = require( './helpers' );

( async () => {
	const t = reporter( 'styles' );
	const { browser, page, errors } = await launch();
	await login( page );
	await autoConfirm( page );

	const rest = ( path, opts = {} ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.path, {
			method: a.method || 'GET',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			credentials: 'same-origin',
			...( a.body ? { body: JSON.stringify( a.body ) } : {} ),
		} );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, { path, method: opts.method, body: opts.body } );
	// The site as a visitor sees it — cache-busted, no cookies.
	const frontBaseColor = () => page.evaluate( async () => {
		const r = await fetch( '/?minnstyleprobe=' + Date.now(), { credentials: 'omit' } );
		const m = ( await r.text() ).match( /--wp--preset--color--base:\s*([^;]+);/ );
		return m ? m[ 1 ].trim() : 'none';
	} );

	const open = async () => {
		await page.goto( BASE + '/minn-admin/styles', { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-style], .minn-empty', { timeout: 20000 } );
		await page.waitForTimeout( 300 );
	};
	const cards = () => page.evaluate( () => [ ...document.querySelectorAll( '[data-style]' ) ].map( ( c ) => ( {
		id: c.dataset.style,
		title: c.querySelector( '.minn-style-name' ).textContent.trim(),
		active: c.classList.contains( 'is-active' ),
		swatches: c.querySelectorAll( '.minn-style-swatches span' ).length,
	} ) ) );

	let prevTheme = '';
	let gsId = 0;
	let snapshot = null;

	try {
		prevTheme = ( await rest( 'wp/v2/themes?status=active&_fields=stylesheet' ) ).body[ 0 ].stylesheet;

		/* ===== Classic theme explains itself =====
		 * The dev site starts classic; the bare next-core site starts on a
		 * block theme, so activate a classic one rather than assume. */
		if ( await activateClassicTheme( page ) ) {
			await page.goto( BASE + '/minn-admin/styles', { waitUntil: 'domcontentloaded' } );
			await page.waitForSelector( '.minn-empty', { timeout: 20000 } );
			t.check( 'a classic theme explains that variations do not apply',
				await page.evaluate( () => /classic theme/i.test( document.querySelector( '#minn-view' ).textContent ) ) );
		} else {
			t.check( 'no classic theme installed to check the classic path', true, 'skipped' );
		}

		await rest( 'minn-admin/v1/themes/activate', { method: 'POST', body: { stylesheet: 'twentytwentyfive' } } );

		// Snapshot the user config so finally can put everything back.
		const meta = ( await rest( 'minn-admin/v1/styles/variations' ) ).body;
		gsId = meta.userStylesId;
		snapshot = meta.current;
		t.check( 'the endpoint names the user global-styles post', gsId > 0, String( gsId ) );

		/* ===== The Browse-styles set, deduped ===== */
		await open();
		const list = await cards();
		const titles = list.map( ( c ) => c.title.replace( /Active$/, '' ).trim() );
		t.check( 'the Design item hosts a Styles tab',
			await page.evaluate( () => !! document.querySelector( '[data-designtab="styles"].active' )
				&& !! document.querySelector( '.minn-nav-btn[data-nav="templates"].active' ) ) );
		t.check( 'Twenty Twenty-Five lists its eight variations plus Default',
			list.length === 9, `${ list.length } cards` );
		t.check( 'no duplicate titles leak in from the partial variations',
			new Set( titles ).size === titles.length, JSON.stringify( titles ) );
		t.check( 'no typography preset is offered as a whole site style',
			! titles.some( ( x ) => /&/.test( x ) ), JSON.stringify( titles ) );
		t.check( 'cards carry swatches', list.every( ( c ) => c.swatches > 0 ) );
		t.check( 'Default is first and marked active on an uncustomized site',
			list[ 0 ].id === 'default' && list[ 0 ].active, JSON.stringify( list[ 0 ] ) );

		/* ===== Apply changes the real site ===== */
		const before = await frontBaseColor();
		const midnight = list.find( ( c ) => /Midnight/.test( c.title ) );
		t.check( 'Midnight is one of the offered styles', !! midnight );
		await page.evaluate( ( id ) => document.querySelector( `[data-style="${ CSS.escape( id ) }"]` ).click(), midnight.id );
		await page.waitForFunction( () =>
			[ ...document.querySelectorAll( '[data-style].is-active' ) ].some( ( c ) => /Midnight/.test( c.textContent ) ),
		null, { timeout: 20000 } );
		const after = await frontBaseColor();
		t.check( 'the front end changed for visitors', after !== before && after !== 'none', `${ before } → ${ after }` );
		t.check( 'the applied card is marked active and Default no longer is',
			( await cards() ).filter( ( c ) => c.active ).length === 1 );

		/* ===== Current look card + history (new this cycle) ===== */
		const look = await page.evaluate( () => {
			const card = document.querySelector( '.minn-look' );
			if ( ! card ) return null;
			return {
				rows: [ ...card.querySelectorAll( '.minn-look-row' ) ].map( ( r ) => r.dataset.look ),
				swatches: card.querySelectorAll( '[data-look="colors"] .minn-look-swatch' ).length,
				fonts: ( card.querySelector( '[data-look="fonts"] .minn-look-value' ) || {} ).textContent || '',
				doors: card.querySelectorAll( 'button.minn-look-row[data-lookopen]' ).length,
				shadowsOut: !! card.querySelector( 'a.minn-look-row[data-look="shadows"][href*="site-editor.php"]' ),
				changes: [ ...card.querySelectorAll( '#minn-look-changes li' ) ].map( ( li ) => li.textContent ),
				reset: !! card.querySelector( '#minn-look-reset' ),
				history: ( card.querySelector( '#minn-look-history' ) || {} ).textContent || '',
			};
		} );
		t.check( 'Current look card lists colors, fonts, sizes, layout, background and shadows',
			look && [ 'colors', 'fonts', 'sizes', 'layout', 'background', 'shadows' ].every( ( k ) => look.rows.includes( k ) ), JSON.stringify( look && look.rows ) );
		t.check( 'look card shows the effective palette and a font', look && look.swatches > 0 && look.fonts.trim().length > 0, look && `${ look.swatches } swatches, fonts "${ look.fonts.trim() }"` );
		t.check( 'look rows are Minn doorways; only Shadows links out to the Site Editor', look && look.doors === 5 && look.shadowsOut, look && `${ look.doors } doors` );
		t.check( 'applying Midnight is described in words on the card', look && look.changes.length > 0, JSON.stringify( look && look.changes.slice( 0, 3 ) ) );
		t.check( 'Reset to theme defaults is offered once customized', look && look.reset );
		t.check( 'History button counts the saved versions', look && /History \(\d+\)/.test( look.history ), look && look.history );

		// The Apply toast (and its Undo) dismisses itself after a few
		// seconds, so the history dialog is inspected AFTER the Undo step.

		/* ===== Undo restores the previous look ===== */
		await page.evaluate( () => {
			const b = document.querySelector( '.minn-toast button, [data-toast-action]' );
			if ( b ) b.click();
		} );
		await page.waitForFunction( () => {
			const first = document.querySelector( '[data-style="default"]' );
			return first && first.classList.contains( 'is-active' );
		}, null, { timeout: 20000 } );
		const undone = await frontBaseColor();
		t.check( 'Undo puts the visitor-facing site back', undone === before, `${ undone } vs ${ before }` );
		const cfg = ( await rest( `wp/v2/global-styles/${ gsId }?context=edit&_fields=settings,styles` ) ).body;
		t.check( 'Undo restored the stored config, not an approximation',
			JSON.stringify( cfg.settings || {} ) === JSON.stringify( snapshot.settings || {} )
			&& JSON.stringify( cfg.styles || {} ) === JSON.stringify( snapshot.styles || {} ) );

		/* ===== History describes every save; Restore brings one back ===== */
		await page.click( '#minn-look-history' );
		await page.waitForSelector( '[data-gsrestore]', { timeout: 20000 } );
		const hist = await page.evaluate( () => [ ...document.querySelectorAll( '.minn-gs-hist-row' ) ].map( ( r ) => ( {
			id: r.dataset.gsrev,
			current: r.classList.contains( 'is-current' ),
			changes: [ ...r.querySelectorAll( '.minn-gs-hist-changes li:not(.minn-look-muted)' ) ].map( ( li ) => li.textContent ),
			restore: !! r.querySelector( '[data-gsrestore]' ),
		} ) ) );
		t.check( 'the newest version is marked current with no Restore', hist.length >= 2 && hist[ 0 ].current && ! hist[ 0 ].restore, JSON.stringify( hist[ 0 ] ) );
		t.check( 'the Undo save reads as resets and the Midnight save as changes',
			hist[ 0 ].changes.every( ( c ) => /reset to theme/.test( c ) ) && hist.some( ( r ) => r.restore && r.changes.some( ( c ) => /: \d+ settings?|→/.test( c ) ) ),
			JSON.stringify( hist.slice( 0, 2 ).map( ( r ) => r.changes.slice( 0, 2 ) ) ) );
		// The newest non-current row is the Midnight save (Undo wrote the
		// default config back as a newer revision).
		const target = await page.evaluate( () => {
			// The Midnight save: restorable, and described by real changes
			// (the row after it, from Undo, only says what was reset).
			const row = [ ...document.querySelectorAll( '.minn-gs-hist-row' ) ].find( ( r ) => r.querySelector( '[data-gsrestore]' )
				&& [ ...r.querySelectorAll( 'li' ) ].some( ( li ) => li.textContent.trim() && ! /reset to theme/.test( li.textContent ) ) );
			if ( ! row ) return null;
			row.querySelector( '[data-gsrestore]' ).click();
			return row.dataset.gsrev;
		} );
		t.check( 'a restorable earlier version is listed', !! target );
		await page.waitForFunction( () => ! document.querySelector( '.minn-gs-hist-row' ) && document.querySelector( '.minn-look' ), null, { timeout: 20000 } );
		await page.waitForFunction( ( b ) => {
			const first = document.querySelector( '[data-style="default"]' );
			return first && ! first.classList.contains( 'is-active' );
		}, null, { timeout: 20000 } );
		const restoredFront = await frontBaseColor();
		t.check( 'restoring that version changes the visitor-facing site again', restoredFront === after, `${ restoredFront } vs ${ after }` );

		/* ===== Reset to theme defaults ===== */
		await page.click( '#minn-look-reset' );
		await page.waitForFunction( () => {
			const first = document.querySelector( '[data-style="default"]' );
			return first && first.classList.contains( 'is-active' ) && ! document.querySelector( '#minn-look-reset' );
		}, null, { timeout: 20000 } );
		const resetFront = await frontBaseColor();
		t.check( 'Reset returns the theme defaults for visitors and hides itself', resetFront === before, `${ resetFront } vs ${ before }` );
		const resetChanges = await page.evaluate( () => document.querySelectorAll( '#minn-look-changes li' ).length );
		t.check( 'the card reports nothing customized after a reset', resetChanges === 0 );

		/* ===== Edit look: direct edits with validation, save and Undo ===== */
		// A row opens the editor at its own section, caret seated there.
		await page.click( '[data-lookopen="type"]' );
		await page.waitForSelector( '.minn-look-form', { timeout: 10000 } );
		const opened = await page.evaluate( () => ( {
			focus: !! document.querySelector( '[data-looksec="type"].is-focus' ),
			active: !! ( document.activeElement && document.activeElement.closest( '[data-looksec="type"]' ) ),
		} ) );
		t.check( 'the Fonts row opens the editor at the Type section with focus there', opened.focus && opened.active, JSON.stringify( opened ) );
		await page.click( '#minn-look-cancel' );
		await page.waitForSelector( '.minn-look-form', { state: 'detached', timeout: 10000 } );
		await page.click( '#minn-look-edit' );
		await page.waitForSelector( '.minn-look-form', { timeout: 10000 } );
		const form = await page.evaluate( () => ( {
			fields: document.querySelectorAll( '.minn-look-field' ).length,
			chips: document.querySelectorAll( '[data-lookrow="styles.color.background"] .minn-look-chip' ).length,
			widths: !! document.querySelector( '[data-lookfield="settings.layout.contentSize"]' ),
			placeholder: ( document.querySelector( '[data-lookfield="styles.typography.lineHeight"]' ) || {} ).placeholder || '',
			saveDisabled: document.querySelector( '#minn-look-save' ).disabled,
		} ) );
		t.check( 'the edit form offers colors, type and layout fields with palette chips', form.fields >= 15 && form.chips >= 3, JSON.stringify( form ) );
		t.check( 'width fields show for an unfiltered_html admin', form.widths );
		t.check( 'empty fields carry the theme value as placeholder', /^\d/.test( form.placeholder ), form.placeholder );
		t.check( 'Save is disabled until something changes', form.saveDisabled );
		// A palette chip for the background, a custom hex for text, a line height.
		const chosen = await page.evaluate( () => { const c = document.querySelectorAll( '[data-lookrow="styles.color.background"] .minn-look-chip' )[ 1 ]; c.click(); return c.dataset.color; } );
		await page.fill( '[data-lookfield="styles.color.text"]', '#123456' );
		await page.fill( '[data-lookfield="styles.typography.lineHeight"]', '1.75' );
		await page.fill( '[data-lookfield="settings.layout.contentSize"]', 'not-a-width' );
		await page.click( '#minn-look-save' );
		// Earlier toasts (the Reset one) may still be on screen: wait for the
		// refusal itself, not the first toast in the DOM.
		await page.waitForFunction( () => [ ...document.querySelectorAll( '.minn-toast' ) ].some( ( el ) => /CSS length/.test( el.textContent ) ), null, { timeout: 10000 } );
		const rejected = await page.evaluate( () => [ ...document.querySelectorAll( '.minn-toast' ) ].map( ( el ) => el.textContent.trim() ).find( ( x ) => /CSS length/.test( x ) ) || '' );
		t.check( 'an invalid width is refused with the reason', /CSS length/.test( rejected ), rejected );
		const cfgAfterReject = ( await rest( `wp/v2/global-styles/${ gsId }?context=edit&_fields=styles` ) ).body;
		t.check( 'the refused save wrote nothing', ! ( cfgAfterReject.styles && cfgAfterReject.styles.color ) );
		await page.fill( '[data-lookfield="settings.layout.contentSize"]', '700px' );
		await page.click( '#minn-look-save' );
		await page.waitForFunction( () => ! document.querySelector( '.minn-look-form' ) && document.querySelector( '#minn-look-changes li' ), null, { timeout: 20000 } );
		const saved = ( await rest( `wp/v2/global-styles/${ gsId }?context=edit&_fields=settings,styles` ) ).body;
		t.check( 'the saved config carries exactly the edited paths',
			saved.styles && saved.styles.color && saved.styles.color.background === chosen && saved.styles.color.text === '#123456'
			&& saved.styles.typography && saved.styles.typography.lineHeight === '1.75'
			&& saved.settings && saved.settings.layout && saved.settings.layout.contentSize === '700px',
			JSON.stringify( { styles: saved.styles, layout: saved.settings && saved.settings.layout } ) );
		const words = await page.evaluate( () => [ ...document.querySelectorAll( '#minn-look-changes li' ) ].map( ( li ) => li.textContent ) );
		t.check( 'the card describes the edits in words', words.some( ( w ) => /Background color →/.test( w ) ) && words.some( ( w ) => /Content width → 700px/.test( w ) ), JSON.stringify( words ) );
		// Clear one field back to the theme.
		await page.click( '#minn-look-edit' );
		await page.waitForSelector( '.minn-look-form', { timeout: 10000 } );
		await page.click( '[data-lookclear="styles.typography.lineHeight"]' );
		await page.waitForSelector( '#minn-look-save:not([disabled])', { timeout: 10000 } );
		await page.click( '#minn-look-save' );
		await page.waitForFunction( () => ! document.querySelector( '.minn-look-form' ), null, { timeout: 20000 } );
		const cleared = ( await rest( `wp/v2/global-styles/${ gsId }?context=edit&_fields=styles` ) ).body;
		t.check( 'clearing a field hands it back to the theme and prunes the empty branch', ! ( cleared.styles && cleared.styles.typography ) && cleared.styles.color.text === '#123456', JSON.stringify( cleared.styles ) );

		/* ===== Mixing: a color palette merged over the current look ===== */
		await page.evaluate( ( id ) => fetch( window.MINN.restUrl + 'wp/v2/global-styles/' + id, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin', body: JSON.stringify( { settings: {}, styles: {} } ) } ), gsId );
		await open();
		const mix = await page.evaluate( () => ( {
			palettes: document.querySelectorAll( '[data-mix="colors"]' ).length,
			typesets: document.querySelectorAll( '[data-mix="typography"]' ).length,
		} ) );
		t.check( 'Twenty Twenty-Five offers its color palettes and typesets for mixing', mix.palettes >= 5 && mix.typesets >= 5, JSON.stringify( mix ) );
		const beforeMix = await frontBaseColor();
		const mixTitle = await page.evaluate( () => { const b = document.querySelector( '[data-mix="colors"]' ); b.click(); return b.querySelector( '.minn-mix-name' ).textContent; } );
		await page.waitForFunction( () => document.querySelector( '#minn-look-changes li' ), null, { timeout: 20000 } );
		const afterMix = await frontBaseColor();
		t.check( 'applying a palette changes the visitor-facing colors', afterMix !== beforeMix, `${ mixTitle }: ${ beforeMix } → ${ afterMix }` );
		const mixed = ( await rest( `wp/v2/global-styles/${ gsId }?context=edit&_fields=settings,styles` ) ).body;
		t.check( 'a palette mix touches colors only, never fonts', !! ( mixed.settings && mixed.settings.color ) && ! ( mixed.settings && mixed.settings.typography && mixed.settings.typography.fontFamilies ), JSON.stringify( Object.keys( mixed.settings || {} ) ) );
	} catch ( e ) {
		t.check( 'suite ran without throwing', false, e.message );
	} finally {
		if ( gsId && snapshot ) {
			await rest( `wp/v2/global-styles/${ gsId }`, { method: 'POST', body: snapshot } ).catch( () => {} );
		}
		if ( prevTheme ) await rest( 'minn-admin/v1/themes/activate', { method: 'POST', body: { stylesheet: prevTheme } } ).catch( () => {} );
	}
	await t.done( browser, errors );
} )();
