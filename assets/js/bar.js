/* Minn Bar — front-end behavior. Deliberately claims NO global keyboard
 * shortcuts: front-end pages own the keyboard (themes and apps bind their
 * own ⌘K), so the palette opens from the search icon via a one-shot
 * sessionStorage intent the app consumes on boot. Escape is scoped: it only
 * closes things the bar itself opened. */
( function () {
	'use strict';
	const CFG = window.MINN_BAR || {};
	const root = document.getElementById( 'minn-bar-root' );
	if ( ! root ) return;

	/* Same guard app.js applies at its navigation sinks. A command's value can
	 * carry a page-builder edit_url, which the minn_admin_page_builders filter
	 * lets third-party code populate, so it is not first-party by construction.
	 * Scheme allowlist plus an origin guard: the leading-slash branch refuses a
	 * second slash or a backslash, which would otherwise resolve off-origin. */
	const safeHref = ( u ) => ( /^(https?:\/\/|\/(?![/\\]))/i.test( String( u == null ? '' : u ).trim() ) ? String( u ).trim() : '' );

	/* ===== Theme: follow the SPA's saved preference; system when unset ===== */
	function applyTheme() {
		let t;
		try {
			t = localStorage.getItem( 'minn-theme' );
		} catch ( e ) {}
		if ( t !== 'dark' && t !== 'light' ) {
			t = window.matchMedia && matchMedia( '(prefers-color-scheme: light)' ).matches ? 'light' : 'dark';
		}
		root.setAttribute( 'data-minn-theme', t );
	}
	applyTheme();
	if ( window.matchMedia ) {
		matchMedia( '(prefers-color-scheme: light)' ).addEventListener( 'change', applyTheme );
	}

	/* ===== Menus ===== */
	const menus = Array.from( root.querySelectorAll( '.minn-bar-menu' ) );
	const triggers = Array.from( root.querySelectorAll( '[data-barmenu]' ) );
	const markButton = root.querySelector( '.minn-bar-markbtn' );
	const mobileBar = window.matchMedia( '(max-width: 782px)' );

	function closeTouchReveal() {
		root.classList.remove( 'minn-bar-touch-open' );
		if ( markButton ) markButton.setAttribute( 'aria-expanded', 'false' );
		const focused = document.activeElement;
		if ( focused && root.contains( focused ) && focused.blur ) focused.blur();
	}

	function closeMenus() {
		menus.forEach( ( m ) => { m.hidden = true; } );
		triggers.forEach( ( b ) => b.setAttribute( 'aria-expanded', 'false' ) );
		root.classList.remove( 'minn-bar-menu-open' );
	}

	function positionMenu( menu, button ) {
		const r = button.getBoundingClientRect();
		menu.style.top = ( r.bottom + 7 ) + 'px';
		let left = Math.min( Math.max( 8, r.left ), innerWidth - menu.offsetWidth - 8 );
		if ( r.right > innerWidth / 2 ) left = Math.max( 8, r.right - menu.offsetWidth );
		menu.style.left = left + 'px';
	}

	triggers.forEach( ( button ) => button.addEventListener( 'click', ( event ) => {
		event.stopPropagation();
		const menu = document.getElementById( button.dataset.barmenu );
		if ( ! menu ) return;
		const opening = menu.hidden;
		closeMenus();
		if ( ! opening ) return;
		menu.hidden = false;
		button.setAttribute( 'aria-expanded', 'true' );
		root.classList.add( 'minn-bar-menu-open' );
		positionMenu( menu, button );
		if ( 'minn-bar-menu-notif' === menu.id ) loadNotifications();
	} ) );
	if ( markButton ) {
		root.addEventListener( 'focusin', () => {
			if ( mobileBar.matches ) markButton.setAttribute( 'aria-expanded', 'true' );
		} );
		root.addEventListener( 'focusout', () => {
			setTimeout( () => {
				if ( mobileBar.matches && ! root.contains( document.activeElement )
					&& ! root.classList.contains( 'minn-bar-touch-open' ) ) {
					markButton.setAttribute( 'aria-expanded', 'false' );
				}
			}, 0 );
		} );
		markButton.addEventListener( 'click', ( event ) => {
			if ( ! mobileBar.matches ) return;
			event.preventDefault();
			event.stopPropagation();
			const opening = ! root.classList.contains( 'minn-bar-touch-open' );
			closeMenus();
			if ( opening ) {
				root.classList.add( 'minn-bar-touch-open' );
				markButton.setAttribute( 'aria-expanded', 'true' );
			} else {
				closeTouchReveal();
			}
		} );
	}
	document.addEventListener( 'click', () => {
		closeMenus();
		closeTouchReveal();
	} );
	menus.forEach( ( m ) => m.addEventListener( 'click', ( e ) => e.stopPropagation() ) );
	addEventListener( 'resize', () => {
		closeMenus();
		closeTouchReveal();
	} );
	addEventListener( 'scroll', () => {
		closeMenus();
		closeTouchReveal();
	}, { passive: true } );

	/* ===== Stand down for full-screen overlays ===== */
	// The bar deliberately outranks WordPress's own toolbar, and that also puts
	// it above image lightboxes, whose whole job is to blank the page. Lightbox
	// z-indexes in the wild run from 200 to 999999, so no fixed value sits
	// under all of them and above ordinary page chrome. Ask the document
	// instead: whatever is topmost in the middle of the viewport. If that sits
	// inside a fixed element covering the screen, and it is not ours, something
	// has taken over and the bar belongs behind it. Decorative full-screen
	// layers do not trip this, because pointer-events:none elements are never
	// returned by elementFromPoint.
	const corner = document.getElementById( 'minn-cornerbar' );
	let overlayFrame = 0;
	function screenIsTaken() {
		const el = document.elementFromPoint( innerWidth / 2, innerHeight / 2 );
		if ( ! el || ! corner || corner.contains( el ) ) return false;
		for ( let n = el; n && n !== document.body && n !== document.documentElement; n = n.parentElement ) {
			if ( 'fixed' !== getComputedStyle( n ).position ) continue;
			const r = n.getBoundingClientRect();
			if ( r.width >= innerWidth * 0.9 && r.height >= innerHeight * 0.9 ) return true;
		}
		return false;
	}
	function syncOverlay() {
		overlayFrame = 0;
		if ( corner ) corner.classList.toggle( 'minn-bar-behind', screenIsTaken() );
	}
	function checkOverlay() {
		if ( ! overlayFrame ) overlayFrame = requestAnimationFrame( syncOverlay );
	}
	// Overlays open on a click or a key and fade in, so sample after the
	// animation too rather than only on the frame the input landed.
	function checkOverlaySoon() {
		checkOverlay();
		setTimeout( checkOverlay, 90 );
		setTimeout( checkOverlay, 420 );
	}
	document.addEventListener( 'click', checkOverlaySoon, true );
	document.addEventListener( 'keyup', checkOverlaySoon, true );
	addEventListener( 'resize', checkOverlay );
	addEventListener( 'scroll', checkOverlay, { passive: true } );
	if ( window.MutationObserver && document.body ) {
		// Most lightboxes append their container to the body as they open.
		new MutationObserver( checkOverlaySoon ).observe( document.body, { childList: true } );
	}
	checkOverlay();
	document.addEventListener( 'keydown', ( event ) => {
		if ( 'Escape' !== event.key ) return;
		if ( menus.some( ( m ) => ! m.hidden ) ) closeMenus();
		if ( root.classList.contains( 'minn-bar-touch-open' )
			|| ( mobileBar.matches && root.contains( document.activeElement ) ) ) {
			closeTouchReveal();
		}
	} );

	/* ===== Intents: hand off to the app (palette, create, notifications) ===== */
	function goWithIntent( intent ) {
		try {
			sessionStorage.setItem( 'minn-intent', intent );
		} catch ( e ) {}
		location.href = CFG.app || '/minn-admin/';
	}
	root.querySelectorAll( '[data-barintent]' ).forEach( ( b ) =>
		b.addEventListener( 'click', () => goWithIntent( b.dataset.barintent ) ) );

	/* ===== REST helper ===== */
	function api( path, opts ) {
		return fetch( CFG.rest + path, Object.assign( {
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': CFG.nonce },
		}, opts ) ).then( ( r ) => {
			if ( ! r.ok ) throw new Error( 'HTTP ' + r.status );
			return r.json();
		} );
	}

	/* ===== Status chip fix ===== */
	const fixBtn = document.getElementById( 'minn-bar-status-fix' );
	if ( fixBtn && CFG.fix ) {
		fixBtn.addEventListener( 'click', async () => {
			fixBtn.disabled = true;
			try {
				if ( 'provider' === CFG.fix.kind ) {
					await api( 'minn-admin/v1/visibility/toggle', {
						method: 'POST',
						body: JSON.stringify( { id: CFG.fix.id, on: false } ),
					} );
				} else {
					await api( 'wp/v2/settings', {
						method: 'POST',
						body: JSON.stringify( CFG.fix.body ),
					} );
				}
				closeMenus();
				// The slot is exception-only: a fixed state means no chip.
				const slot = root.querySelector( '.minn-bar-status' );
				const divider = slot && slot.previousElementSibling;
				if ( slot ) slot.remove();
				if ( divider && divider.classList.contains( 'minn-bar-divider' ) ) divider.remove();
			} catch ( e ) {
				fixBtn.disabled = false;
			}
		} );
	}

	/* ===== Notifications: lazy dot + a peek of the newest items ===== */
	let notifItems = null;
	function esc( s ) {
		return String( s == null ? '' : s ).replace( /[&<>"']/g, ( c ) => ( {
			'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
		}[ c ] ) );
	}
	// Where a notification takes you — the same kind/id mapping the app's own
	// panel uses. Tab preselects (themes, translations) ride the intent flag;
	// plain routes are plain URLs. appPath reuses editorBase so the pretty and
	// plain-permalink forms both come out right.
	const appPath = ( p ) => String( CFG.editorBase || '' ).replace( /editor$/, p );
	function notifDestination( n ) {
		const id = String( n.id || '' );
		if ( 'comments' === n.kind ) return { url: appPath( 'comments' ) };
		if ( id.indexOf( 'theme-' ) === 0 ) return { intent: 'ext:themes' };
		if ( id.indexOf( 'translations-' ) === 0 ) return { intent: 'ext:translations' };
		if ( 'updates' === n.kind || id.indexOf( 'core-' ) === 0 ) return { url: appPath( 'extensions' ) };
		if ( id.indexOf( 'user-' ) === 0 ) return { url: appPath( 'users' ) };
		// Notices act through their links in the app's panel — open it there.
		return { intent: 'notifications' };
	}
	function renderNotifItems() {
		const wrap = document.getElementById( 'minn-bar-notif-items' );
		if ( ! wrap ) return;
		const items = ( notifItems || [] ).slice( 0, 4 );
		wrap.innerHTML = items.length ? items.map( ( n, i ) => `
			<button type="button" class="minn-bar-menu-item" role="menuitem" data-barnotif="${ i }">
				<span class="minn-bar-notif-icon">${ esc( n.icon ) }</span>
				<span class="minn-bar-menu-copy">
					<span class="minn-bar-menu-title">${ esc( n.title ) }</span>
					<span class="minn-bar-menu-sub">${ esc( n.ago ) }</span>
				</span>
				${ n.unread ? '<span class="minn-bar-notif-unread"></span>' : '' }
			</button>` ).join( '' )
			: '<div class="minn-bar-menu-item minn-bar-menu-static"><span class="minn-bar-menu-copy"><span class="minn-bar-menu-sub">' + esc( CFG.emptyNotifs || 'All caught up.' ) + '</span></span></div>';
		wrap.querySelectorAll( '[data-barnotif]' ).forEach( ( row ) => row.addEventListener( 'click', () => {
			const n = ( notifItems || [] )[ Number( row.dataset.barnotif ) ];
			if ( ! n ) return;
			if ( n.unread ) {
				n.unread = false;
				api( 'minn-admin/v1/notifications/read', { method: 'POST', body: JSON.stringify( { id: n.id } ) } ).catch( () => {} );
			}
			const dest = notifDestination( n );
			if ( dest.intent ) goWithIntent( dest.intent );
			else location.href = dest.url;
		} ) );
	}
	function loadNotifications() {
		if ( notifItems ) return Promise.resolve();
		return api( 'minn-admin/v1/notifications' ).then( ( d ) => {
			notifItems = ( d && d.items ) || [];
			const dot = document.getElementById( 'minn-bar-notif-dot' );
			if ( dot ) dot.hidden = ! notifItems.some( ( n ) => n.unread );
			renderNotifItems();
		} ).catch( () => {
			notifItems = [];
			renderNotifItems();
		} );
	}
	// Fetch once per pageload after the page has settled, for the unread dot.
	setTimeout( loadNotifications, 2500 );

	/* ===== Front-end command palette =====
	 * Opens from the search icon (click only — never a global shortcut).
	 * Static commands come server-built with capability checks already
	 * applied (CFG.commands); below them, core wp/v2/search finds your own
	 * content and a picked row opens the Minn editor. Arrows / Enter / Escape
	 * work only while the palette input is focused. */
	const ICONS = {
		grid: '<rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/>',
		doc: '<path d="M6 3h9l3 3v15H6z"/><path d="M9 11h6M9 15h6"/>',
		image: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m5 17 5-5 3 3 2-2 4 4"/>',
		gear: '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M2 12h3M19 12h3M4.9 19.1 7 17M17 7l2.1-2.1"/>',
		pencil: '<path d="M4 20h4L19 9l-4-4L4 16v4Z"/><path d="m13.5 6.5 4 4"/>',
		plus: '<path d="M12 5v14M5 12h14"/>',
		moon: '<path d="M20 15.5A8.5 8.5 0 0 1 8.5 4 8.5 8.5 0 1 0 20 15.5Z"/>',
		refresh: '<path d="M18.4 5.6a9 9 0 1 0 .8 8.4"/><path d="M19 3v5h-5"/>',
		wp: '<path fill="currentColor" stroke-width="0" d="M21.469 6.825c.84 1.537 1.318 3.3 1.318 5.175 0 3.979-2.156 7.456-5.363 9.325l3.295-9.527c.615-1.54.82-2.771.82-3.864 0-.405-.026-.78-.07-1.11m-7.981.105c.647-.03 1.232-.105 1.232-.105.582-.075.514-.93-.067-.899 0 0-1.755.135-2.88.135-1.064 0-2.85-.15-2.85-.15-.585-.03-.661.855-.075.885 0 0 .54.061 1.125.09l1.68 4.605-2.37 7.08L5.354 6.9c.649-.03 1.234-.1 1.234-.1.585-.075.516-.93-.065-.896 0 0-1.746.138-2.874.138-.2 0-.438-.008-.69-.015C4.911 3.15 8.235 1.215 12 1.215c2.809 0 5.365 1.072 7.286 2.833-.046-.003-.091-.009-.141-.009-1.06 0-1.812.923-1.812 1.914 0 .89.513 1.643 1.06 2.531.411.72.89 1.643.89 2.977 0 .915-.354 1.994-.821 3.479l-1.075 3.585-3.9-11.61.001.014zM12 22.784c-1.059 0-2.081-.153-3.048-.437l3.237-9.406 3.315 9.087c.024.053.05.101.078.149-1.12.393-2.325.607-3.582.607M1.211 12c0-1.564.336-3.05.935-4.39L7.29 21.709C3.694 19.96 1.212 16.271 1.211 12M12 0C5.385 0 0 5.385 0 12s5.385 12 12 12 12-5.385 12-12S18.615 0 12 0"/>',
	};
	const icon = ( key ) => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' + ( ICONS[ key ] || ICONS.doc ) + '</svg>';
	const I18N = CFG.i18n || {};

	let pal = null;
	let palRows = [];
	let palSel = 0;
	let contentRows = [];
	let searchSeq = 0;
	let searchTimer = 0;

	function buildPalette() {
		pal = document.createElement( 'div' );
		pal.id = 'minn-bar-palette';
		pal.innerHTML = `
			<div class="minn-bar-pal" role="dialog" aria-modal="true">
				<div class="minn-bar-pal-search">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
					<input id="minn-bar-pal-input" autocomplete="off" placeholder="${ esc( I18N.placeholder || '' ) }">
					<span class="minn-bar-pal-key">esc</span>
				</div>
				<div class="minn-bar-pal-results" id="minn-bar-pal-results"></div>
				<div class="minn-bar-pal-foot">
					<span>Minn Admin</span>
					<span class="minn-bar-pal-keys"><span><i class="minn-bar-pal-key">↑↓</i> ${ esc( I18N.navigate || '' ) }</span><span><i class="minn-bar-pal-key">↵</i> ${ esc( I18N.open || '' ) }</span></span>
				</div>
			</div>`;
		root.appendChild( pal );
		pal.addEventListener( 'mousedown', ( e ) => { if ( e.target === pal ) closePalette(); } );
		pal.addEventListener( 'click', ( e ) => e.stopPropagation() );
		const input = pal.querySelector( '#minn-bar-pal-input' );
		input.addEventListener( 'input', () => {
			palSel = 0;
			scheduleContentSearch( input.value.trim() );
			renderPalette();
		} );
		input.addEventListener( 'keydown', ( e ) => {
			if ( 'ArrowDown' === e.key ) { e.preventDefault(); palSel = palRows.length ? ( palSel + 1 ) % palRows.length : 0; renderPalette(); }
			else if ( 'ArrowUp' === e.key ) { e.preventDefault(); palSel = palRows.length ? ( palSel - 1 + palRows.length ) % palRows.length : 0; renderPalette(); }
			else if ( 'Enter' === e.key ) { e.preventDefault(); runCommand( palRows[ palSel ] ); }
		} );
	}

	function filteredCommands() {
		const q = ( pal.querySelector( '#minn-bar-pal-input' ).value || '' ).trim().toLowerCase();
		const statics = ( CFG.commands || [] ).filter( ( c ) =>
			! q || ( c.title + ' ' + ( c.hint || '' ) ).toLowerCase().includes( q ) );
		return statics.concat( q.length >= 2 ? contentRows : [] );
	}

	function renderPalette() {
		palRows = filteredCommands();
		if ( palSel >= palRows.length ) palSel = Math.max( 0, palRows.length - 1 );
		const wrap = pal.querySelector( '#minn-bar-pal-results' );
		if ( ! palRows.length ) {
			wrap.innerHTML = '<div class="minn-bar-pal-empty">' + esc( I18N.empty || '' ) + '</div>';
			return;
		}
		let lastGroup = '';
		wrap.innerHTML = palRows.map( ( c, i ) => {
			const label = c.group !== lastGroup ? '<div class="minn-bar-pal-group">' + esc( c.group ) + '</div>' : '';
			lastGroup = c.group;
			return label + `
				<button type="button" class="minn-bar-pal-row${ i === palSel ? ' sel' : '' }" data-pi="${ i }">
					<span class="minn-bar-pal-icon">${ icon( c.icon ) }</span>
					<span class="minn-bar-menu-copy"><span class="minn-bar-menu-title">${ esc( c.title ) }</span>${ c.hint ? '<span class="minn-bar-menu-sub">' + esc( c.hint ) + '</span>' : '' }</span>
					<span class="minn-bar-menu-meta">↵</span>
				</button>`;
		} ).join( '' );
		wrap.querySelectorAll( '.minn-bar-pal-row' ).forEach( ( row ) => {
			row.addEventListener( 'mouseenter', () => {
				palSel = Number( row.dataset.pi );
				wrap.querySelectorAll( '.minn-bar-pal-row' ).forEach( ( r ) => r.classList.toggle( 'sel', r === row ) );
			} );
			row.addEventListener( 'click', () => runCommand( palRows[ Number( row.dataset.pi ) ] ) );
		} );
	}

	function scheduleContentSearch( q ) {
		clearTimeout( searchTimer );
		if ( q.length < 2 ) { contentRows = []; return; }
		const seq = ++searchSeq;
		searchTimer = setTimeout( () => {
			api( 'wp/v2/search?per_page=6&_fields=id,title,subtype&search=' + encodeURIComponent( q ) ).then( ( items ) => {
				if ( seq !== searchSeq ) return;
				// Search titles arrive entity-encoded; decode before esc() so
				// an apostrophe doesn't render as its entity.
				const dec = document.createElement( 'textarea' );
				const decode = ( s ) => { dec.innerHTML = String( s || '' ); return dec.value; };
				contentRows = ( items || [] )
					.filter( ( it ) => ( CFG.types || {} )[ it.subtype ] )
					.map( ( it ) => ( {
						group: I18N.content || 'Your content',
						icon: 'doc',
						title: decode( it.title ),
						hint: it.subtype,
						kind: 'url',
						value: CFG.editorBase.includes( '#' )
							? CFG.editorBase + '/' + CFG.types[ it.subtype ] + '/' + it.id
							: CFG.editorBase.replace( /\/$/, '' ) + '/' + CFG.types[ it.subtype ] + '/' + it.id,
					} ) );
				renderPalette();
			} ).catch( () => {} );
		}, 250 );
	}

	function toggleThemePref() {
		const cur = root.getAttribute( 'data-minn-theme' ) === 'light' ? 'dark' : 'light';
		try {
			localStorage.setItem( 'minn-theme', cur );
		} catch ( e ) {}
		root.setAttribute( 'data-minn-theme', cur );
	}

	/* ===== Toast: transient feedback under the bar ===== */
	let toastTimer = 0;
	function barToast( msg ) {
		let t = document.getElementById( 'minn-bar-toast' );
		if ( ! t ) {
			t = document.createElement( 'div' );
			t.id = 'minn-bar-toast';
			t.setAttribute( 'role', 'status' );
			root.appendChild( t );
		}
		t.textContent = msg;
		t.classList.add( 'show' );
		clearTimeout( toastTimer );
		toastTimer = setTimeout( () => t.classList.remove( 'show' ), 4500 );
	}

	/* ===== Cache purge: the app's one-request-per-provider discipline.
	 * A purge that resets OPcache recycles the PHP worker and drops the
	 * browser's kept-alive sockets, so each provider gets its own request,
	 * a network drop retries once on a fresh socket, and a second drop
	 * counts as purged (the purge itself is what killed the reply). */
	async function runCachePurge() {
		const providers = CFG.purge || [];
		if ( ! providers.length ) return;
		barToast( I18N.purging || 'Clearing cache…' );
		const purged = [];
		const failed = [];
		for ( const p of providers ) {
			const attempt = () => api( 'minn-admin/v1/cache/purge', { method: 'POST', body: JSON.stringify( { provider: p.id } ) } );
			try {
				const r = await attempt();
				( r.purged.length ? purged : failed ).push( p.name );
			} catch ( e ) {
				if ( ! ( e instanceof TypeError ) ) { failed.push( p.name ); continue; }
				await new Promise( ( res ) => setTimeout( res, 1200 ) );
				try {
					const r = await attempt();
					( r.purged.length ? purged : failed ).push( p.name );
				} catch ( e2 ) {
					purged.push( p.name );
				}
			}
		}
		barToast( failed.length
			? ( I18N.purgeFail || 'Cache cleared (%1$s); failed: %2$s' ).replace( '%1$s', purged.join( ', ' ) ).replace( '%2$s', failed.join( ', ' ) )
			: ( I18N.purged || 'Cache cleared (%s)' ).replace( '%s', purged.join( ', ' ) ) );
	}

	function runCommand( c ) {
		if ( ! c ) return;
		if ( 'theme' === c.kind ) { closePalette(); toggleThemePref(); return; }
		if ( 'purge' === c.kind ) { closePalette(); runCachePurge(); return; }
		if ( 'intent' === c.kind ) { goWithIntent( c.value ); return; }
		const dest = safeHref( c.value );
		if ( ! dest ) return;
		location.href = dest;
	}

	function openPalette() {
		closeMenus();
		if ( ! pal ) buildPalette();
		pal.classList.add( 'open' );
		const input = pal.querySelector( '#minn-bar-pal-input' );
		input.value = '';
		contentRows = [];
		palSel = 0;
		renderPalette();
		requestAnimationFrame( () => input.focus() );
	}

	function closePalette() {
		if ( pal ) pal.classList.remove( 'open' );
		const btn = document.getElementById( 'minn-bar-search' );
		if ( btn ) btn.focus( { preventScroll: true } );
	}

	const search = document.getElementById( 'minn-bar-search' );
	if ( search ) search.addEventListener( 'click', ( e ) => {
		e.stopPropagation();
		( pal && pal.classList.contains( 'open' ) ) ? closePalette() : openPalette();
	} );
	document.addEventListener( 'keydown', ( event ) => {
		if ( 'Escape' === event.key && pal && pal.classList.contains( 'open' ) ) closePalette();
	} );
	document.addEventListener( 'click', () => { if ( pal && pal.classList.contains( 'open' ) ) closePalette(); } );
} )();
