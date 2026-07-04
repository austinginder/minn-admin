/**
 * Minn Admin — a reimagined WordPress admin.
 * Vanilla JS single-page app talking to the WP REST API.
 */
( function () {
	'use strict';

	const B = window.MINN;

	/* ===== Utilities ===== */

	const $  = ( sel, ctx ) => ( ctx || document ).querySelector( sel );
	const $$ = ( sel, ctx ) => Array.from( ( ctx || document ).querySelectorAll( sel ) );

	const esc = ( s ) => String( s == null ? '' : s ).replace( /[&<>"']/g, ( c ) => ( {
		'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
	}[ c ] ) );

	const stripTags = ( html ) => {
		const d = document.createElement( 'div' );
		d.innerHTML = html || '';
		return d.textContent || '';
	};

	const decodeEntities = stripTags;

	async function apiRes( path, opts = {} ) {
		const url = /^https?:/.test( path ) ? path : B.restUrl + path.replace( /^\//, '' );
		const headers = { 'X-WP-Nonce': B.nonce };
		if ( opts.body && ! ( opts.body instanceof FormData ) ) {
			headers[ 'Content-Type' ] = 'application/json';
		}
		const res = await fetch( url, { credentials: 'same-origin', ...opts, headers: { ...headers, ...( opts.headers || {} ) } } );
		if ( ! res.ok ) {
			let msg = res.status + ' ' + res.statusText;
			try {
				const j = await res.json();
				if ( j.message ) msg = stripTags( j.message );
			} catch ( e ) {}
			throw new Error( msg );
		}
		return res;
	}

	async function api( path, opts = {} ) {
		return ( await apiRes( path, opts ) ).json();
	}

	// Like api() but also returns the collection pagination headers.
	async function apiPaged( path, opts = {} ) {
		const res = await apiRes( path, opts );
		return {
			items: await res.json(),
			total: parseInt( res.headers.get( 'X-WP-Total' ) || '0', 10 ),
			totalPages: parseInt( res.headers.get( 'X-WP-TotalPages' ) || '1', 10 ),
		};
	}

	function timeAgo( dateStr ) {
		const d = new Date( dateStr + ( /Z|[+-]\d\d:?\d\d$/.test( dateStr ) ? '' : 'Z' ) );
		const s = Math.max( 1, Math.round( ( Date.now() - d.getTime() ) / 1000 ) );
		if ( s < 60 ) return 'just now';
		if ( s < 3600 ) return Math.round( s / 60 ) + ' min ago';
		if ( s < 86400 ) return Math.round( s / 3600 ) + 'h ago';
		if ( s < 86400 * 7 ) return Math.round( s / 86400 ) + 'd ago';
		return d.toLocaleDateString( undefined, { month: 'short', day: 'numeric' } );
	}

	function fmtBytes( n ) {
		if ( ! n ) return '—';
		const units = [ 'B', 'KB', 'MB', 'GB' ];
		let i = 0;
		while ( n >= 1024 && i < units.length - 1 ) { n /= 1024; i++; }
		return ( n >= 10 || i === 0 ? Math.round( n ) : n.toFixed( 1 ) ) + ' ' + units[ i ];
	}

	// wp.org plugin titles are keyword-stuffed ("Rank Math SEO – AI SEO Tools
	// to Dominate…"). Keep everything before the first separator.
	function cleanPluginName( name ) {
		return decodeEntities( name || '' ).split( /\s+[–—|:]\s*|\s+-\s+|\s*[({]/ )[ 0 ].trim() || name;
	}

	const PALETTE_COLORS = [ '#46b881', '#5b9be0', '#e0a458', '#d073c0', '#8a80f8', '#e46b6b' ];
	const colorFor = ( s ) => {
		let h = 0;
		for ( let i = 0; i < s.length; i++ ) h = ( h * 31 + s.charCodeAt( i ) ) >>> 0;
		return PALETTE_COLORS[ h % PALETTE_COLORS.length ];
	};

	const GRADS = {
		VID: 'linear-gradient(135deg,#1b1b1f,#5b9be0)',
		AUD: 'linear-gradient(135deg,#e46b6b,#e0a458)',
		PDF: 'linear-gradient(135deg,#46b881,#5b9be0)',
		ZIP: 'linear-gradient(135deg,#e0a458,#d073c0)',
		FILE: 'linear-gradient(135deg,#8a80f8,#6e62f5)',
		IMG: 'linear-gradient(135deg,#6e62f5,#d073c0)',
	};

	function mediaKind( mime ) {
		if ( ! mime ) return 'FILE';
		if ( mime.startsWith( 'image/svg' ) ) return 'SVG';
		if ( mime.startsWith( 'image/' ) ) return 'IMG';
		if ( mime.startsWith( 'video/' ) ) return 'VID';
		if ( mime.startsWith( 'audio/' ) ) return 'AUD';
		if ( mime === 'application/pdf' ) return 'PDF';
		if ( mime.includes( 'zip' ) || mime.includes( 'compressed' ) ) return 'ZIP';
		return 'FILE';
	}

	/* ===== State ===== */

	const state = {
		route: 'overview',
		editorId: null,
		editorType: 'posts',
		filter: 'all',
		contentSearch: '',
		mediaView: 'grid',
		uploadOpen: false,
		commentTab: 'hold',
		extTab: 'plugins',
		orderTab: 'any',
		userSearch: '',
		range: 30,
		modal: null,
		surface: {},
		notifOpen: false,
		notifTab: 'all',
		paletteOpen: false,
		paletteSel: 0,
		saving: false,
		editor: null,
		settingsSection: 'General',
		cache: {
			overview: null,
			content: null,
			cptContent: {},
			types: null,
			media: null,
			comments: null,
			themes: null,
			orders: null,
			orderSummary: null,
			users: null,
			categories: null,
			plugins: null,
			pluginUpdates: {},
			settings: null,
			notifications: null,
		},
	};

	const TITLES = {
		overview: [ 'Overview', 'Dashboard' ],
		content: [ 'Content', 'Posts & Pages' ],
		media: [ 'Media', 'Library' ],
		comments: [ 'Comments', 'Moderation' ],
		orders: [ 'Orders', 'WooCommerce' ],
		users: [ 'Users', 'People' ],
		extensions: [ 'Extensions', 'Installed' ],
		settings: [ 'Settings', 'General' ],
		editor: [ 'Editor', 'Draft' ],
	};

	/* ===== Toast ===== */

	let toastTimer = null;
	function toast( msg, isError ) {
		$$( '.minn-toast' ).forEach( ( el ) => el.remove() );
		const el = document.createElement( 'div' );
		el.className = 'minn-toast';
		el.innerHTML = `
			<div class="minn-toast-icon${ isError ? ' err' : '' }">
				<svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3">
					${ isError ? '<path d="M18 6 6 18M6 6l12 12"/>' : '<path d="M20 6 9 17l-5-5"/>' }
				</svg>
			</div>
			<div class="minn-toast-msg">${ esc( msg ) }</div>`;
		document.body.appendChild( el );
		clearTimeout( toastTimer );
		toastTimer = setTimeout( () => el.remove(), 2600 );
	}

	/* ===== Routing =====
	 * Path-based ( /minn-admin/content ) when pretty permalinks are on,
	 * falling back to hash routing ( #/content ) on plain permalinks. */

	const BASE = new URL( B.appUrl ).pathname;
	const PATH_MODE = !! B.pretty;

	function currentPath() {
		if ( PATH_MODE && location.pathname.startsWith( BASE ) ) {
			return decodeURIComponent( location.pathname.slice( BASE.length ) ).replace( /\/+$/, '' );
		}
		return location.hash.replace( /^#\/?/, '' );
	}

	function setPath( p, replace ) {
		const url = PATH_MODE ? BASE + p : ( p ? '#/' + p : location.pathname );
		history[ replace ? 'replaceState' : 'pushState' ]( null, '', url );
	}

	const surfaceById = ( id ) => ( B.surfaces || [] ).find( ( s ) => s.id === id ) || null;

	function parseHash() {
		const h = currentPath();
		const parts = h.split( '/' ).filter( Boolean );
		const route = parts[ 0 ] || 'overview';
		if ( route === 'editor' ) {
			// #/editor · #/editor/123 · #/editor/<rest_base>/123
			if ( parts[ 1 ] && /^\d+$/.test( parts[ 1 ] ) ) {
				state.editorType = 'posts';
				state.editorId = parseInt( parts[ 1 ], 10 );
			} else {
				state.editorType = parts[ 1 ] || 'posts';
				state.editorId = parts[ 2 ] ? parseInt( parts[ 2 ], 10 ) : null;
			}
			state.route = 'editor';
		} else if ( TITLES[ route ] || surfaceById( route ) ) {
			state.route = route;
		} else {
			state.route = 'overview';
		}
	}

	function onRouteChange() {
		const prevRoute = state.route;
		const prevId = state.editorId;
		parseHash();
		if ( state.route !== 'editor' || prevRoute !== 'editor' || prevId !== state.editorId ) {
			if ( state.route === 'editor' && prevRoute !== 'editor' ) state.editor = null;
			renderView();
		}
	}

	function go( route ) {
		setPath( route );
		onRouteChange();
	}

	/* ===== Shell ===== */

	function icon( name ) {
		const icons = {
			grid: '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
			doc: '<path d="M5 4h14a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1Z"/><path d="M8 8h8M8 12h8M8 16h5"/>',
			img: '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="m21 15-5-5L5 21"/>',
			plug: '<path d="M14 7V5a2 2 0 0 0-2-2 2 2 0 0 0-2 2v2H7a1 1 0 0 0-1 1v3h2a2 2 0 0 1 0 4H6v3a1 1 0 0 0 1 1h3v-2a2 2 0 0 1 4 0v2h3a1 1 0 0 0 1-1v-3h-2a2 2 0 0 1 0-4h2V8a1 1 0 0 0-1-1Z"/>',
			gear: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1Z"/>',
			search: '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
			bell: '<path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
			moon: '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/>',
			sun: '<circle cx="12" cy="12" r="4.5"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
			plus: '<path d="M12 5v14M5 12h14"/>',
			refresh: '<path d="M3 12a9 9 0 0 1 15-6.7L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-15 6.7L3 16"/><path d="M8 16H3v5"/>',
			list: '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
			chat: '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>',
			cart: '<circle cx="9" cy="21" r="1.5"/><circle cx="19" cy="21" r="1.5"/><path d="M2.05 2.05h2l2.66 12.42a2 2 0 0 0 2 1.58h9.78a2 2 0 0 0 1.95-1.57l1.65-7.43H5.12"/>',
			users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
			copy: '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
			inbox: '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
			send: '<path d="m22 2-7 20-4-9-9-4Z"/><path d="M22 2 11 13"/>',
			clock: '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
			shuffle: '<path d="M2 18h1.4c1.3 0 2.5-.6 3.3-1.7l6.6-8.6c.8-1.1 2-1.7 3.3-1.7H22"/><path d="m18 2 4 4-4 4"/><path d="M2 6h1.9c1.5 0 2.9.9 3.6 2.2"/><path d="M22 18h-5.9c-1.4 0-2.6-.7-3.4-1.8l-.5-.8"/><path d="m18 14 4 4-4 4"/>',
			trash: '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
			upload: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>',
			logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
			globe: '<circle cx="12" cy="12" r="10"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/><path d="M2 12h20"/>',
			help: '<circle cx="12" cy="12" r="10"/><text x="12" y="16.5" text-anchor="middle" font-size="12.5" font-weight="650" font-family="inherit" fill="currentColor" stroke-width="0">?</text>',
		};
		return `<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">${ icons[ name ] || '' }</svg>`;
	}

	function renderShell() {
		const navItems = [
			{ id: 'overview', label: 'Overview', icon: 'grid' },
			{ id: 'content', label: 'Content', icon: 'doc', count: true },
			{ id: 'media', label: 'Media', icon: 'img' },
		];
		if ( B.caps.moderate ) {
			navItems.push( { id: 'comments', label: 'Comments', icon: 'chat', commentCount: true } );
		}
		if ( B.wc && B.caps.orders ) {
			navItems.push( { id: 'orders', label: 'Orders', icon: 'cart', orderCount: true } );
		}
		( B.surfaces || [] ).forEach( ( s ) =>
			navItems.push( { id: s.id, label: s.label, icon: s.icon || 'plug' } )
		);
		if ( B.caps.plugins ) {
			navItems.push( { id: 'extensions', label: 'Extensions', icon: 'plug', dot: true } );
		}
		const manageItems = [];
		if ( B.caps.users ) {
			manageItems.push( { id: 'users', label: 'Users', icon: 'users' } );
		}
		if ( B.caps.settings ) {
			manageItems.push( { id: 'settings', label: 'Settings', icon: 'gear' } );
		}

		const navBtn = ( n ) => `
			<button class="minn-nav-btn" data-nav="${ n.id }">
				${ icon( n.icon ) }<span>${ esc( n.label ) }</span>
				${ n.count ? '<span class="minn-nav-count" id="minn-content-count" hidden></span>' : '' }
				${ n.commentCount ? '<span class="minn-nav-count" id="minn-comments-count" hidden></span>' : '' }
				${ n.orderCount ? '<span class="minn-nav-count" id="minn-orders-count" hidden></span>' : '' }
				${ n.dot ? '<span class="minn-nav-dot" id="minn-plugin-dot" hidden></span>' : '' }
			</button>`;

		$( '#minn-app' ).innerHTML = `
		<div class="minn-shell">
			<aside class="minn-sidebar">
				<div class="minn-logo">
					<button class="minn-logo-home" id="minn-logo-home" title="Overview">
						<span class="minn-logo-mark">m</span>
						<span class="minn-logo-name">minn</span>
					</button>
					<div class="minn-logo-ver">v${ esc( B.version.split( '.' ).slice( 0, 2 ).join( '.' ) ) }</div>
				</div>
				<button class="minn-search-btn" id="minn-open-palette">
					${ icon( 'search' ) }<span>Search…</span><span class="minn-kbd">⌘K</span>
				</button>
				<div class="minn-nav-label">Workspace</div>
				${ navItems.map( navBtn ).join( '' ) }
				${ manageItems.length ? '<div class="minn-nav-label later">Manage</div>' + manageItems.map( navBtn ).join( '' ) : '' }
				<div class="minn-user" id="minn-user-area" title="Your account">
					<img class="minn-user-avatar" src="${ esc( B.user.avatar ) }" alt="">
					<div style="min-width:0;">
						<div class="minn-user-name">${ esc( B.user.name ) }</div>
						<div class="minn-user-role">${ esc( B.user.role ) }</div>
					</div>
					<a class="minn-user-logout" href="${ esc( B.site.logout ) }" title="Log out">${ icon( 'logout' ) }</a>
				</div>
			</aside>
			<main class="minn-main">
				<header class="minn-topbar">
					<div class="minn-topbar-title" id="minn-title"></div>
					<div class="minn-topbar-sub" id="minn-sub"></div>
					<div class="minn-topbar-actions">
						<a class="minn-icon-btn" id="minn-view-site" href="${ esc( B.site.url ) }" target="_blank" rel="noopener" title="View site">${ icon( 'globe' ) }</a>
						<button class="minn-icon-btn" id="minn-help-btn" title="About Minn">${ icon( 'help' ) }</button>
						<button class="minn-icon-btn" id="minn-theme-btn" title="Toggle theme"></button>
						<button class="minn-icon-btn" id="minn-notif-btn" title="Notifications">
							${ icon( 'bell' ) }<span class="minn-unread-dot" id="minn-unread-dot" hidden></span>
						</button>
						<button class="minn-btn-primary" id="minn-new-btn">${ icon( 'plus' ) } New</button>
					</div>
				</header>
				<div class="minn-scroll"><div class="minn-page" id="minn-view"></div></div>
			</main>
		</div>
		<div id="minn-overlays"></div>`;

		$$( '.minn-nav-btn' ).forEach( ( btn ) =>
			btn.addEventListener( 'click', () => go( btn.dataset.nav ) )
		);
		$( '#minn-open-palette' ).addEventListener( 'click', openPalette );
		$( '#minn-logo-home' ).addEventListener( 'click', () => go( 'overview' ) );
		$( '#minn-user-area' ).addEventListener( 'click', ( e ) => {
			if ( e.target.closest( 'a' ) ) return; // logout link
			openUserModal( B.user.id );
		} );
		$( '#minn-theme-btn' ).addEventListener( 'click', toggleTheme );
		$( '#minn-help-btn' ).addEventListener( 'click', () => { state.modal = { type: 'help' }; renderOverlays(); } );
		$( '#minn-notif-btn' ).addEventListener( 'click', toggleNotif );
		$( '#minn-new-btn' ).addEventListener( 'click', () => { state.editorId = null; state.editorType = 'posts'; go( 'editor' ); } );
		renderThemeBtn();
	}

	function renderTopbar() {
		const surface = surfaceById( state.route );
		const [ title, sub ] = surface ? [ surface.label, surface.sub || '' ] : ( TITLES[ state.route ] || [ 'minn', '' ] );
		$( '#minn-title' ).textContent = title;
		$( '#minn-sub' ).textContent = state.route === 'editor' && state.editor
			? ( STATUS_LABELS[ state.editor.status ] || 'Draft' )
			: ( state.route === 'settings' ? state.settingsSection : sub );
		$$( '.minn-nav-btn' ).forEach( ( btn ) =>
			btn.classList.toggle( 'active', btn.dataset.nav === state.route )
		);
	}

	function renderThemeBtn() {
		const dark = document.documentElement.getAttribute( 'data-theme' ) !== 'light';
		$( '#minn-theme-btn' ).innerHTML = icon( dark ? 'moon' : 'sun' );
	}

	function toggleTheme() {
		const next = document.documentElement.getAttribute( 'data-theme' ) === 'light' ? 'dark' : 'light';
		document.documentElement.setAttribute( 'data-theme', next );
		try { localStorage.setItem( 'minn-theme', next ); } catch ( e ) {}
		renderThemeBtn();
	}

	/* ===== Overview ===== */

	async function loadOverview() {
		state.cache.overview = await api( `minn-admin/v1/overview?days=${ state.range }` );
	}

	function renderOverview() {
		const view = $( '#minn-view' );
		const o = state.cache.overview;
		if ( ! o ) {
			view.innerHTML = '<div class="minn-loading">Loading overview…</div>';
			loadOverview().then( renderIfCurrent( 'overview' ) ).catch( showErr );
			return;
		}
		const chartData = o.traffic ? o.traffic.chart : o.chart;
		// Traffic bars stack pageviews (soft) behind visitors (solid), so the
		// scale runs to the pageview max like Koko's own widget.
		const max = Math.max( 1, ...chartData.map( ( c ) => o.traffic ? ( c.views || c.value ) : c.value ) );
		const pct = ( n ) => Math.max( n > 0 ? 2 : 0, Math.round( ( n / max ) * 100 ) );
		const deltaCls = ( up ) => up === true ? ' up' : ( up === 'warn' ? ' warn' : ( up === 'down' ? ' down' : '' ) );
		view.innerHTML = `
		<div class="minn-dash-head">
			<div>
				<div class="minn-dash-greeting">${ esc( o.greeting ) }, ${ esc( B.user.name.split( ' ' )[ 0 ] ) }</div>
				<div class="minn-dash-sub">Here's what's happening across your site today.</div>
			</div>
		</div>
		<div class="minn-stats">
			${ o.stats.map( ( s ) => `
				<div class="minn-card minn-stat">
					<div class="minn-stat-label">${ esc( s.label ) }</div>
					<div class="minn-stat-value">${ esc( s.value ) }</div>
					<div class="minn-stat-delta${ deltaCls( s.up ) }">${ esc( s.delta ) }</div>
				</div>` ).join( '' ) }
		</div>
		<div class="minn-dash-grid">
			<div class="minn-card minn-panel-pad">
				<div class="minn-chart-head">
					<div class="minn-panel-title">${ o.traffic ? 'Traffic' : 'Activity' }${ o.traffic ? ` <span class="minn-panel-sub">${ esc( o.traffic.source ) }</span>` : '' }</div>
					<div class="minn-range-tabs">
						${ [ 7, 30, 90 ].map( ( d ) => `<button class="minn-range-tab${ state.range === d ? ' active' : '' }" data-range="${ d }">${ d }d</button>` ).join( '' ) }
					</div>
				</div>
				<div class="minn-chart" id="minn-chart">
					${ chartData.map( ( c, i ) => o.traffic ? `
						<div class="minn-chart-col" data-ci="${ i }">
							<div class="minn-chart-views" style="height:${ pct( c.views || 0 ) }%"></div>
							<div class="minn-chart-visitors" style="height:${ pct( c.value ) }%"></div>
						</div>` : `
						<div class="minn-chart-col" data-ci="${ i }">
							<div class="minn-chart-bar${ i === chartData.length - 1 ? ' last' : '' }" style="height:${ Math.max( 3, pct( c.value ) ) }%"></div>
						</div>` ).join( '' ) }
				</div>
			</div>
			<div class="minn-card minn-panel-pad">
				<div class="minn-panel-title">Recent activity</div>
				<div class="minn-activity">
					${ o.activity.length ? o.activity.map( ( a ) => `
						<div class="minn-activity-row">
							<div class="minn-activity-dot dot-${ esc( a.color ) }"></div>
							<div style="min-width:0;">
								<div class="minn-activity-text">${ esc( a.text ) }</div>
								<div class="minn-activity-time">${ esc( a.time ) }</div>
							</div>
						</div>` ).join( '' ) : '<div class="minn-empty">No activity yet.</div>' }
				</div>
			</div>
		</div>`;

		$$( '.minn-range-tab', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', async () => {
				state.range = parseInt( btn.dataset.range, 10 );
				state.cache.overview = null;
				renderOverview();
			} )
		);
		bindChartTooltip( $( '#minn-chart', view ), chartData, !! o.traffic );
	}

	// Koko-style hover card: date on top, visitors/pageviews (or events) below.
	function chartTip() {
		let tip = $( '#minn-chart-tip' );
		if ( ! tip ) {
			tip = document.createElement( 'div' );
			tip.id = 'minn-chart-tip';
			tip.hidden = true;
			document.body.appendChild( tip );
		}
		return tip;
	}

	function bindChartTooltip( chart, data, isTraffic ) {
		if ( ! chart ) return;
		const tip = chartTip();
		let current = -1;

		const hide = () => {
			tip.hidden = true;
			current = -1;
			$$( '.minn-chart-col.hover', chart ).forEach( ( el ) => el.classList.remove( 'hover' ) );
		};

		chart.addEventListener( 'mousemove', ( e ) => {
			const col = e.target.closest( '[data-ci]' );
			if ( ! col ) return hide();
			const i = parseInt( col.dataset.ci, 10 );
			if ( i !== current ) {
				current = i;
				const c = data[ i ];
				if ( ! c ) return hide();
				tip.innerHTML = `
					<div class="minn-chart-tip-date">${ esc( c.label ) }</div>
					<div class="minn-chart-tip-stats">
						${ isTraffic ? `
						<div><b>${ Number( c.value ).toLocaleString() }</b><span>Visitors</span></div>
						<div><b>${ Number( c.views || 0 ).toLocaleString() }</b><span>Pageviews</span></div>` : `
						<div><b>${ Number( c.value ).toLocaleString() }</b><span>Event${ c.value === 1 ? '' : 's' }</span></div>` }
					</div>`;
				$$( '.minn-chart-col.hover', chart ).forEach( ( el ) => el.classList.remove( 'hover' ) );
				col.classList.add( 'hover' );
				tip.hidden = false;
			}
			const rect = col.getBoundingClientRect();
			const tw = tip.offsetWidth;
			tip.style.left = Math.min( Math.max( 8, rect.left + rect.width / 2 - tw / 2 ), window.innerWidth - tw - 8 ) + 'px';
			tip.style.top = Math.max( 8, rect.top - tip.offsetHeight - 10 ) + 'px';
		} );
		chart.addEventListener( 'mouseleave', hide );
	}

	/* ===== Content ===== */

	const mapContentItem = ( type ) => ( p ) => ( {
		id: p.id,
		type,
		title: decodeEntities( p.title.rendered ) || '(no title)',
		slug: '/' + ( p.slug || '' ),
		status: p.status,
		author: ( p._embedded && p._embedded.author && p._embedded.author[ 0 ] && p._embedded.author[ 0 ].name ) || '—',
		modified: p.modified,
	} );

	function contentQuery( page ) {
		// _fields keeps WP from running the_content on every row — much faster on
		// large sites, and immune to render-time fatals from other plugins.
		// "private" requires read_private_posts — requesting it without the cap 403s.
		const statuses = 'publish,future,draft,pending' + ( B.caps.readPrivate ? ',private' : '' );
		let q = `context=edit&status=${ statuses }&per_page=25&orderby=modified`
			+ `&_embed=author&_fields=id,title,slug,status,modified,author,_links,_embedded&page=${ page }`;
		if ( state.contentSearch ) q += '&search=' + encodeURIComponent( state.contentSearch );
		// categories/tags are post taxonomies — never send them for a custom post type.
		if ( ! currentCpt() ) {
			if ( state.contentCat ) q += '&categories=' + encodeURIComponent( state.contentCat );
			if ( state.contentTag ) q += '&tags=' + encodeURIComponent( state.contentTag );
		}
		return q;
	}

	// Custom post types with REST support, beyond post/page/attachment.
	// Plugin-internal CPTs that would be noise (or dangerous) as Content tabs.
	const HIDDEN_TYPES = [ 'post', 'page', 'attachment', 'elementor_library', 'e-floating-buttons', 'e-landing-page' ];
	let typesPromise = null;
	function loadTypes() {
		if ( ! typesPromise ) {
			// No _fields here: the types response is an associative object, and the
			// server-level _fields filter would strip it to {} over HTTP.
			typesPromise = api( 'wp/v2/types?context=edit' ).then( ( types ) => {
				state.cache.types = Object.values( types )
					.filter( ( t ) => t.viewable && t.rest_base && ! HIDDEN_TYPES.includes( t.slug ) )
					.map( ( t ) => ( { slug: t.slug, restBase: t.rest_base, name: t.name } ) );
				return state.cache.types;
			} );
		}
		return typesPromise;
	}

	const currentCpt = () => ( state.cache.types || [] ).find( ( t ) => t.restBase === state.filter ) || null;

	async function loadCpt( more ) {
		const t = currentCpt();
		if ( ! t ) return;
		const cache = state.cache.cptContent;
		const c = more && cache[ t.restBase ] ? cache[ t.restBase ] : { items: [], page: 0, totalPages: 1, total: 0 };
		const r = await apiPaged( `wp/v2/${ t.restBase }?` + contentQuery( c.page + 1 ) );
		c.page++;
		c.totalPages = r.totalPages;
		c.total = r.total;
		c.items.push( ...r.items.map( mapContentItem( t.restBase ) ) );
		cache[ t.restBase ] = c;
	}

	async function loadPostTerms() {
		if ( state.cache.postTerms ) return;
		const [ cats, tags ] = await Promise.all( [
			api( 'wp/v2/categories?per_page=100&orderby=count&order=desc&_fields=id,name,count' ).catch( () => [] ),
			api( 'wp/v2/tags?per_page=100&orderby=count&order=desc&_fields=id,name,count' ).catch( () => [] ),
		] );
		const clean = ( list ) => ( Array.isArray( list ) ? list : [] )
			.filter( ( t ) => t.count > 0 )
			.map( ( t ) => ( { id: t.id, name: decodeEntities( t.name ), count: t.count } ) );
		state.cache.postTerms = { categories: clean( cats ), tags: clean( tags ) };
	}

	async function loadContent( more ) {
		// Category/tag filters are post-only taxonomies, so suppress pages while one is active.
		const taxFilter = !! ( state.contentCat || state.contentTag );
		const c = more && state.cache.content ? state.cache.content : {
			// Authors can't edit pages — requesting draft/pending page statuses 400s.
			items: [], postPage: 0, pagePage: 0, morePosts: true, morePages: ! taxFilter && !! B.caps.editPages, total: 0,
		};
		const jobs = [];
		if ( c.morePosts ) {
			jobs.push( apiPaged( 'wp/v2/posts?' + contentQuery( c.postPage + 1 ) ).then( ( r ) => {
				c.postPage++;
				c.morePosts = c.postPage < r.totalPages;
				c.postTotal = r.total;
				c.items.push( ...r.items.map( mapContentItem( 'posts' ) ) );
			} ) );
		}
		if ( c.morePages ) {
			jobs.push( apiPaged( 'wp/v2/pages?' + contentQuery( c.pagePage + 1 ) ).then( ( r ) => {
				c.pagePage++;
				c.morePages = c.pagePage < r.totalPages;
				c.pageTotal = r.total;
				c.items.push( ...r.items.map( mapContentItem( 'pages' ) ) );
			} ) );
		}
		await Promise.all( jobs );
		c.items.sort( ( a, b ) => ( a.modified < b.modified ? 1 : -1 ) );
		c.total = ( c.postTotal || 0 ) + ( c.pageTotal || 0 );
		state.cache.content = c;

		const badge = $( '#minn-content-count' );
		if ( badge && ! state.contentSearch ) {
			badge.textContent = c.total > 999 ? ( Math.round( c.total / 100 ) / 10 ) + 'k' : c.total;
			badge.hidden = ! c.total;
		}
	}

	const STATUS_LABELS = { publish: 'Published', draft: 'Draft', future: 'Scheduled', pending: 'Pending', private: 'Private' };

	let contentSearchTimer = null;

	async function runBulk( sel, btn, op, doneMsg ) {
		const entries = Array.from( sel.entries() );
		if ( ! entries.length ) return;
		btn.disabled = true;
		btn.textContent = 'Working…';
		let ok = 0, fail = 0;
		for ( const [ id, type ] of entries ) {
			try { await op( type, id ); ok++; }
			catch ( e ) { fail++; }
		}
		sel.clear();
		state.cache.content = null;
		state.cache.cptContent = {};
		toast( fail ? `${ doneMsg }: ${ ok } done, ${ fail } failed` : `${ doneMsg } (${ ok })`, fail > 0 && ok === 0 );
		await ( currentCpt() ? loadCpt() : loadContent() ).catch( showErr );
		if ( state.route === 'content' ) renderContent();
	}

	function renderContent() {
		const view = $( '#minn-view' );
		if ( ! state.cache.types ) {
			loadTypes().then( () => { if ( state.route === 'content' ) renderContent(); } ).catch( () => {} );
		}
		const cpt = currentCpt();
		const c = cpt ? state.cache.cptContent[ cpt.restBase ] : state.cache.content;
		if ( ! c ) {
			view.innerHTML = '<div class="minn-loading">Loading content…</div>';
			( cpt ? loadCpt() : loadContent() ).then( renderIfCurrent( 'content' ) ).catch( showErr );
			return;
		}
		const filtered = cpt ? c.items : c.items.filter( ( p ) =>
			state.filter === 'all' || p.type === state.filter
		);
		const hasMore = cpt ? c.page < c.totalPages : ( c.morePosts || c.morePages );
		const tabs = [ [ 'all', 'All' ], [ 'posts', 'Posts' ],
			...( B.caps.editPages ? [ [ 'pages', 'Pages' ] ] : [] ),
			...( state.cache.types || [] ).map( ( t ) => [ t.restBase, t.name ] ) ];
		const rowIcon = ( p ) => p.type === 'pages' ? '▭' : ( p.type === 'posts' ? '¶' : '◆' );
		// Category/tag filters are post taxonomies — show them for the core posts context only.
		const showTax = ! cpt && state.filter !== 'pages';
		if ( showTax && ! state.cache.postTerms ) {
			loadPostTerms().then( () => { if ( state.route === 'content' ) renderContent(); } ).catch( () => {} );
		}
		const terms = state.cache.postTerms || { categories: [], tags: [] };
		const sel = state.contentSel || ( state.contentSel = new Map() );
		const taxSelect = ( id, cur, label, list ) => `<select class="minn-input minn-tax-select" id="${ id }">
			<option value="">${ label }</option>
			${ list.map( ( t ) => `<option value="${ t.id }"${ String( cur ) === String( t.id ) ? ' selected' : '' }>${ esc( t.name ) }${ t.count != null ? ' (' + t.count + ')' : '' }</option>` ).join( '' ) }
		</select>`;
		view.innerHTML = `
		<div class="minn-toolbar">
			<div class="minn-tabs">
				${ tabs.map( ( [ id, label ] ) =>
					`<button class="minn-tab${ state.filter === id ? ' active' : '' }" data-filter="${ esc( id ) }">${ esc( label ) }</button>` ).join( '' ) }
			</div>
			${ showTax ? taxSelect( 'minn-cat-filter', state.contentCat || '', 'All categories', terms.categories ) : '' }
			${ showTax ? taxSelect( 'minn-tag-filter', state.contentTag || '', 'All tags', terms.tags ) : '' }
			<input class="minn-input minn-toolbar-search" id="minn-content-search" placeholder="Filter by title…" value="${ esc( state.contentSearch || '' ) }">
			<div class="minn-toolbar-meta">${ filtered.length }${ hasMore ? ' of ' + c.total : '' } item${ c.total === 1 ? '' : 's' }</div>
		</div>
		<div id="minn-bulk-slot"></div>
		<div class="minn-card minn-table">
			<div class="minn-table-head minn-content-cols">
				<div><input type="checkbox" class="minn-cb" id="minn-sel-all"${ filtered.length && filtered.every( ( p ) => sel.has( p.id ) ) ? ' checked' : '' }></div>
				<div></div><div>Title</div><div>Status</div><div>Author</div><div>Modified</div><div></div>
			</div>
			${ filtered.length ? filtered.map( ( p ) => `
				<div class="minn-table-row minn-content-cols${ sel.has( p.id ) ? ' sel' : '' }" data-id="${ p.id }" data-type="${ esc( p.type ) }">
					<div class="minn-cbcell"><input type="checkbox" class="minn-cb minn-row-cb" data-cbid="${ p.id }"${ sel.has( p.id ) ? ' checked' : '' }></div>
					<div class="minn-row-icon">${ rowIcon( p ) }</div>
					<div class="minn-cell-clip">
						<div class="minn-row-title">${ esc( p.title ) }</div>
						<div class="minn-row-slug">${ esc( p.slug ) }</div>
					</div>
					<div><span class="minn-status ${ esc( p.status ) }">${ STATUS_LABELS[ p.status ] || esc( p.status ) }</span></div>
					<div class="minn-row-meta">${ esc( p.author ) }</div>
					<div class="minn-row-meta">${ timeAgo( p.modified ) }</div>
					<div class="minn-row-arrow">›</div>
				</div>` ).join( '' ) : `<div class="minn-empty">${ state.contentSearch ? 'No matches for “' + esc( state.contentSearch ) + '”.' : 'Nothing here yet. Hit <b>New</b> to write something.' }</div>` }
		</div>
		${ hasMore ? '<button class="minn-load-more" id="minn-content-more">Load more</button>' : '' }`;

		$$( '.minn-tab', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', () => {
				const nf = btn.dataset.filter;
				// Leaving the posts context clears post-only taxonomy filters (and their cache).
				if ( ( nf === 'pages' || ( state.cache.types || [] ).some( ( t ) => t.restBase === nf ) ) && ( state.contentCat || state.contentTag ) ) {
					state.contentCat = null;
					state.contentTag = null;
					state.cache.content = null;
				}
				state.filter = nf;
				sel.clear();
				renderContent();
			} )
		);
		const reloadContent = async () => {
			sel.clear();
			state.cache.content = null;
			state.cache.cptContent = {};
			view.innerHTML = '<div class="minn-loading">Loading content…</div>';
			await ( currentCpt() ? loadCpt() : loadContent() ).catch( showErr );
			if ( state.route === 'content' ) renderContent();
		};
		const catSel = $( '#minn-cat-filter', view );
		if ( catSel ) catSel.addEventListener( 'change', () => { state.contentCat = catSel.value || null; reloadContent(); } );
		const tagSel = $( '#minn-tag-filter', view );
		if ( tagSel ) tagSel.addEventListener( 'change', () => { state.contentTag = tagSel.value || null; reloadContent(); } );
		const search = $( '#minn-content-search', view );
		search.addEventListener( 'input', () => {
			clearTimeout( contentSearchTimer );
			contentSearchTimer = setTimeout( async () => {
				state.contentSearch = search.value.trim();
				sel.clear();
				state.cache.content = null;
				state.cache.cptContent = {};
				await ( currentCpt() ? loadCpt() : loadContent() ).catch( showErr );
				if ( state.route === 'content' ) {
					renderContent();
					const s = $( '#minn-content-search' );
					s.focus();
					s.setSelectionRange( s.value.length, s.value.length );
				}
			}, 350 );
		} );
		$$( '.minn-table-row', view ).forEach( ( row ) =>
			row.addEventListener( 'click', ( e ) => {
				if ( e.target.closest( '.minn-cbcell' ) ) return; // checkbox handles its own clicks
				go( `editor/${ row.dataset.type }/${ row.dataset.id }` );
			} )
		);
		// Bulk-selection UI updates the bar in place — no full list re-render, so
		// checkbox state stays put while you tick multiple rows.
		const syncBulkBar = () => {
			const slot = $( '#minn-bulk-slot', view );
			if ( ! slot ) return;
			if ( ! sel.size ) { slot.innerHTML = ''; }
			else if ( ! $( '.minn-bulkbar', slot ) ) {
				slot.innerHTML = `
				<div class="minn-bulkbar">
					<span class="minn-bulk-count">${ sel.size } selected</span>
					<select class="minn-input" id="minn-bulk-status">
						<option value="">Set status…</option>
						<option value="publish">Published</option>
						<option value="draft">Draft</option>
						<option value="pending">Pending</option>
						${ B.caps.readPrivate ? '<option value="private">Private</option>' : '' }
					</select>
					<button class="minn-btn-soft" id="minn-bulk-apply">Apply</button>
					<button class="minn-btn-soft danger" id="minn-bulk-trash">${ icon( 'trash' ) } Trash</button>
					<button class="minn-btn-soft" id="minn-bulk-clear" style="margin-left:auto;">Clear</button>
				</div>`;
				$( '#minn-bulk-apply', slot ).addEventListener( 'click', ( e ) => {
					const status = $( '#minn-bulk-status', slot ).value;
					if ( ! status ) { toast( 'Pick a status first', true ); return; }
					runBulk( sel, e.currentTarget, ( type, id ) => api( `wp/v2/${ type }/${ id }`, { method: 'POST', body: JSON.stringify( { status } ) } ), 'Status updated' );
				} );
				$( '#minn-bulk-trash', slot ).addEventListener( 'click', ( e ) => {
					if ( ! confirm( `Move ${ sel.size } item${ sel.size === 1 ? '' : 's' } to trash?` ) ) return;
					runBulk( sel, e.currentTarget, ( type, id ) => api( `wp/v2/${ type }/${ id }`, { method: 'DELETE' } ), 'Moved to trash' );
				} );
				$( '#minn-bulk-clear', slot ).addEventListener( 'click', () => {
					sel.clear();
					$$( '.minn-row-cb', view ).forEach( ( c ) => { c.checked = false; c.closest( '.minn-table-row' ).classList.remove( 'sel' ); } );
					const sa = $( '#minn-sel-all', view );
					if ( sa ) sa.checked = false;
					syncBulkBar();
				} );
			} else {
				$( '.minn-bulk-count', slot ).textContent = sel.size + ' selected';
			}
			const sa = $( '#minn-sel-all', view );
			if ( sa ) sa.checked = filtered.length > 0 && filtered.every( ( p ) => sel.has( p.id ) );
		};
		syncBulkBar();

		$$( '.minn-row-cb', view ).forEach( ( cb ) =>
			cb.addEventListener( 'change', () => {
				const row = cb.closest( '.minn-table-row' );
				const id = parseInt( cb.dataset.cbid, 10 );
				if ( cb.checked ) sel.set( id, row.dataset.type );
				else sel.delete( id );
				row.classList.toggle( 'sel', cb.checked );
				syncBulkBar();
			} )
		);
		const selAll = $( '#minn-sel-all', view );
		if ( selAll ) selAll.addEventListener( 'change', () => {
			filtered.forEach( ( p ) => { if ( selAll.checked ) sel.set( p.id, p.type ); else sel.delete( p.id ); } );
			$$( '.minn-row-cb', view ).forEach( ( c ) => {
				c.checked = selAll.checked;
				c.closest( '.minn-table-row' ).classList.toggle( 'sel', selAll.checked );
			} );
			syncBulkBar();
		} );
		const more = $( '#minn-content-more', view );
		if ( more ) {
			more.addEventListener( 'click', async () => {
				more.disabled = true;
				more.textContent = 'Loading…';
				await ( cpt ? loadCpt( true ) : loadContent( true ) ).catch( showErr );
				if ( state.route === 'content' ) renderContent();
			} );
		}
	}

	/* ===== Media ===== */

	async function loadMedia( more ) {
		const c = more && state.cache.media ? state.cache.media : { items: [], page: 0, totalPages: 1, total: 0 };
		const r = await apiPaged( `wp/v2/media?per_page=48&orderby=date&order=desc&_fields=id,title,mime_type,source_url,media_details,date,alt_text&page=${ c.page + 1 }` );
		c.page++;
		c.totalPages = r.totalPages;
		c.total = r.total;
		c.items.push( ...r.items );
		state.cache.media = c;
	}

	function mapMediaItem( m ) {
		const kind = mediaKind( m.mime_type );
		const md = m.media_details || {};
		const thumb = ( md.sizes && md.sizes.medium && md.sizes.medium.source_url ) || ( kind === 'IMG' || kind === 'SVG' ? m.source_url : null );
		return {
			id: m.id,
			name: decodeEntities( m.title.rendered ) || ( m.source_url || '' ).split( '/' ).pop(),
			kind,
			mime: m.mime_type,
			url: m.source_url,
			thumb,
			grad: GRADS[ kind ] || GRADS.FILE,
			dims: md.width ? `${ md.width }×${ md.height }` : '—',
			size: fmtBytes( md.filesize ),
			date: m.date,
			alt: m.alt_text || '',
		};
	}

	async function uploadFiles( files ) {
		if ( ! files.length ) return;
		let done = 0;
		toast( `Uploading ${ files.length } file${ files.length === 1 ? '' : 's' }…` );
		for ( const file of files ) {
			const fd = new FormData();
			fd.append( 'file', file );
			try {
				await api( 'wp/v2/media', { method: 'POST', body: fd } );
				done++;
			} catch ( e ) {
				toast( `${ file.name }: ${ e.message }`, true );
			}
		}
		if ( done ) toast( `Uploaded ${ done } file${ done === 1 ? '' : 's' }` );
		state.cache.media = null;
		state.uploadOpen = false;
		if ( state.route === 'media' ) renderMedia();
	}

	function renderMedia() {
		const view = $( '#minn-view' );
		const c = state.cache.media;
		if ( ! c ) {
			view.innerHTML = '<div class="minn-loading">Loading media…</div>';
			loadMedia().then( renderIfCurrent( 'media' ) ).catch( showErr );
			return;
		}
		const items = c.items;
		const mapped = items.map( mapMediaItem );
		const countLabel = `${ mapped.length }${ c.page < c.totalPages ? ' of ' + c.total : '' } file${ c.total === 1 ? '' : 's' }`;
		const thumbStyle = ( m ) => m.thumb
			? `background-image:url('${ esc( m.thumb ) }')`
			: `background:${ m.grad }`;

		view.innerHTML = `
		<div class="minn-toolbar">
			<div class="minn-toolbar-meta" style="margin-left:0;">${ countLabel }</div>
			${ B.caps.upload ? `<button class="minn-btn-soft" id="minn-upload-btn" style="margin-left:auto;">${ icon( 'upload' ) } Upload</button><input type="file" id="minn-upload-input" multiple hidden>` : '' }
			<div class="minn-view-tabs"${ B.caps.upload ? ' style="margin-left:0;"' : '' }>
				<button class="minn-view-tab${ state.mediaView === 'grid' ? ' active' : '' }" data-view="grid" title="Grid">${ icon( 'grid' ) }</button>
				<button class="minn-view-tab${ state.mediaView === 'list' ? ' active' : '' }" data-view="list" title="List">${ icon( 'list' ) }</button>
			</div>
		</div>
		${ state.uploadOpen && B.caps.upload ? `
		<div class="minn-dropzone" id="minn-dropzone">
			${ icon( 'upload' ) }
			<div class="minn-dropzone-title">Drag &amp; drop files here</div>
			<div class="minn-dropzone-sub">or <b>browse your computer</b></div>
		</div>` : '' }
		${ ! mapped.length ? '<div class="minn-card minn-empty">The media library is empty. Drop files anywhere to upload.</div>' : state.mediaView === 'grid' ? `
		<div class="minn-media-grid">
			${ mapped.map( ( m ) => `
				<div class="minn-media-card" data-media="${ m.id }">
					<div class="minn-media-thumb" style="${ thumbStyle( m ) }"><span class="minn-media-badge">${ m.kind }</span></div>
					<div class="minn-media-info">
						<div class="minn-media-name">${ esc( m.name ) }</div>
						<div class="minn-media-meta">${ esc( m.dims === '—' ? m.size : m.dims ) }</div>
					</div>
				</div>` ).join( '' ) }
		</div>` : `
		<div class="minn-card minn-media-list">
			${ mapped.map( ( m ) => `
				<div class="minn-media-row" data-media="${ m.id }">
					<div class="minn-media-thumb-sm" style="${ thumbStyle( m ) }"></div>
					<div class="minn-media-col">
						<div class="minn-row-title">${ esc( m.name ) }</div>
						<div class="minn-row-slug">${ m.kind }</div>
					</div>
					<div class="minn-media-dims">${ esc( m.dims ) }</div>
					<div class="minn-media-size">${ esc( m.size ) }</div>
				</div>` ).join( '' ) }
		</div>` }
		${ c.page < c.totalPages ? '<button class="minn-load-more" id="minn-media-more">Load more</button>' : '' }`;

		$$( '.minn-view-tab', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', () => { state.mediaView = btn.dataset.view; renderMedia(); } )
		);
		$$( '[data-media]', view ).forEach( ( el ) =>
			el.addEventListener( 'click', () => {
				const m = mapped.find( ( x ) => x.id === parseInt( el.dataset.media, 10 ) );
				if ( m ) { state.modal = { type: 'media', item: m }; renderOverlays(); }
			} )
		);
		const more = $( '#minn-media-more', view );
		if ( more ) {
			more.addEventListener( 'click', async () => {
				more.disabled = true;
				more.textContent = 'Loading…';
				await loadMedia( true ).catch( showErr );
				if ( state.route === 'media' ) renderMedia();
			} );
		}
		const uploadBtn = $( '#minn-upload-btn', view );
		if ( uploadBtn ) {
			const input = $( '#minn-upload-input', view );
			uploadBtn.addEventListener( 'click', () => {
				state.uploadOpen = ! state.uploadOpen;
				renderMedia();
			} );
			input.addEventListener( 'change', () => uploadFiles( Array.from( input.files ) ) );
			const zone = $( '#minn-dropzone', view );
			if ( zone ) {
				zone.addEventListener( 'click', () => $( '#minn-upload-input' ).click() );
				zone.addEventListener( 'dragover', ( e ) => { e.preventDefault(); zone.classList.add( 'over' ); } );
				zone.addEventListener( 'dragleave', () => zone.classList.remove( 'over' ) );
				zone.addEventListener( 'drop', ( e ) => {
					e.preventDefault();
					e.stopPropagation();
					zone.classList.remove( 'over' );
					uploadFiles( Array.from( ( e.dataTransfer && e.dataTransfer.files ) || [] ) );
				} );
			}
		}
	}

	/* ===== Comments ===== */

	const COMMENT_TABS = [ [ 'hold', 'Pending' ], [ 'approve', 'Approved' ], [ 'spam', 'Spam' ], [ 'trash', 'Trash' ] ];

	async function loadComments( more ) {
		const c = more && state.cache.comments ? state.cache.comments : { items: [], page: 0, totalPages: 1, total: 0, postTitles: {} };
		const r = await apiPaged( `wp/v2/comments?context=edit&status=${ state.commentTab }&per_page=25&page=${ c.page + 1 }&_fields=id,author_name,author_avatar_urls,content,date,post` );
		c.page++;
		c.totalPages = r.totalPages;
		c.total = r.total;
		c.items.push( ...r.items );
		// Resolve post titles in one cheap request (no content rendering).
		const ids = [ ...new Set( r.items.map( ( cm ) => cm.post ).filter( ( id ) => id && ! c.postTitles[ id ] ) ) ];
		if ( ids.length ) {
			try {
				const posts = await api( `wp/v2/posts?include=${ ids.join( ',' ) }&per_page=${ ids.length }&_fields=id,title&status=publish,future,draft,pending,private&context=edit` );
				posts.forEach( ( p ) => { c.postTitles[ p.id ] = decodeEntities( p.title.rendered ); } );
			} catch ( e ) {}
		}
		state.cache.comments = c;
	}

	async function refreshCommentBadge() {
		if ( ! B.caps.moderate ) return;
		try {
			const r = await apiPaged( 'wp/v2/comments?status=hold&per_page=1' );
			const badge = $( '#minn-comments-count' );
			if ( badge ) {
				badge.textContent = r.total;
				badge.hidden = ! r.total;
			}
		} catch ( e ) {}
	}

	async function setCommentStatus( id, status, label ) {
		try {
			if ( status === 'delete' ) {
				await api( `wp/v2/comments/${ id }?force=true`, { method: 'DELETE' } );
			} else {
				await api( `wp/v2/comments/${ id }`, { method: 'POST', body: JSON.stringify( { status } ) } );
			}
			toast( label );
			state.cache.comments = null;
			refreshCommentBadge();
			if ( state.route === 'comments' ) renderComments();
		} catch ( e ) {
			toast( e.message, true );
		}
	}

	function renderComments() {
		const view = $( '#minn-view' );
		const c = state.cache.comments;
		if ( ! c ) {
			view.innerHTML = '<div class="minn-loading">Loading comments…</div>';
			loadComments().then( renderIfCurrent( 'comments' ) ).catch( showErr );
			return;
		}
		const rows = c.items.map( ( cm ) => ( {
			id: cm.id,
			author: cm.author_name || 'Anonymous',
			avatar: cm.author_avatar_urls && ( cm.author_avatar_urls[ '48' ] || Object.values( cm.author_avatar_urls )[ 0 ] ),
			excerpt: stripTags( cm.content && cm.content.rendered ).slice( 0, 160 ),
			post: c.postTitles[ cm.post ] || '#' + cm.post,
			date: cm.date,
		} ) );
		const actionsFor = () => {
			switch ( state.commentTab ) {
				case 'hold': return [ [ 'approved', 'Approve' ], [ 'spam', 'Spam' ], [ 'trash', 'Trash' ] ];
				case 'approve': return [ [ 'hold', 'Unapprove' ], [ 'spam', 'Spam' ], [ 'trash', 'Trash' ] ];
				case 'spam': return [ [ 'hold', 'Not spam' ], [ 'trash', 'Trash' ] ];
				default: return [ [ 'hold', 'Restore' ], [ 'delete', 'Delete forever' ] ];
			}
		};
		view.innerHTML = `
		<div class="minn-toolbar">
			<div class="minn-tabs">
				${ COMMENT_TABS.map( ( [ id, label ] ) =>
					`<button class="minn-tab${ state.commentTab === id ? ' active' : '' }" data-ctab="${ id }">${ label }</button>` ).join( '' ) }
			</div>
			<div class="minn-toolbar-meta">${ c.total } comment${ c.total === 1 ? '' : 's' }</div>
		</div>
		<div class="minn-card">
			${ rows.length ? rows.map( ( r ) => `
				<div class="minn-comment-row">
					${ r.avatar ? `<img class="minn-comment-avatar" src="${ esc( r.avatar ) }" alt="">` : '<div class="minn-comment-avatar"></div>' }
					<div class="minn-comment-body">
						<div class="minn-comment-head">
							<span class="minn-comment-author">${ esc( r.author ) }</span>
							<span class="minn-comment-on">on ${ esc( r.post ) }</span>
							<span class="minn-comment-time">${ timeAgo( r.date ) }</span>
						</div>
						<div class="minn-comment-text">${ esc( r.excerpt ) }</div>
						<div class="minn-comment-actions">
							${ actionsFor().map( ( [ st, label ] ) =>
								`<button class="minn-comment-action${ st === 'trash' || st === 'delete' ? ' danger' : '' }" data-cid="${ r.id }" data-cstatus="${ st }">${ label }</button>` ).join( '' ) }
						</div>
					</div>
				</div>` ).join( '' ) : `<div class="minn-empty">No ${ ( COMMENT_TABS.find( ( t ) => t[ 0 ] === state.commentTab ) || [ '', '' ] )[ 1 ].toLowerCase() } comments.</div>` }
		</div>
		${ c.page < c.totalPages ? '<button class="minn-load-more" id="minn-comments-more">Load more</button>' : '' }`;

		$$( '[data-ctab]', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', () => {
				state.commentTab = btn.dataset.ctab;
				state.cache.comments = null;
				renderComments();
			} )
		);
		$$( '[data-cstatus]', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', () => {
				const st = btn.dataset.cstatus;
				if ( st === 'delete' && ! confirm( 'Delete this comment permanently?' ) ) return;
				const labels = { approved: 'Comment approved', hold: state.commentTab === 'hold' ? 'Comment held' : 'Comment restored', spam: 'Marked as spam', trash: 'Moved to trash', delete: 'Comment deleted' };
				setCommentStatus( parseInt( btn.dataset.cid, 10 ), st, labels[ st ] );
			} )
		);
		const more = $( '#minn-comments-more', view );
		if ( more ) {
			more.addEventListener( 'click', async () => {
				more.disabled = true;
				more.textContent = 'Loading…';
				await loadComments( true ).catch( showErr );
				if ( state.route === 'comments' ) renderComments();
			} );
		}
	}

	/* ===== Orders (WooCommerce) ===== */

	const ORDER_TABS = [ [ 'any', 'All' ], [ 'processing', 'Processing' ], [ 'completed', 'Completed' ], [ 'on-hold', 'On hold' ], [ 'refunded', 'Refunded' ] ];
	const ORDER_STATUS_STYLE = {
		processing: 'future', completed: 'publish', 'on-hold': 'private', pending: 'private',
		cancelled: 'trash-status', refunded: 'draft', failed: 'trash-status',
	};

	async function loadOrders( more ) {
		const c = more && state.cache.orders ? state.cache.orders : { items: [], page: 0, totalPages: 1, total: 0 };
		const r = await apiPaged( `wc/v3/orders?per_page=25&page=${ c.page + 1 }&status=${ state.orderTab }&_fields=id,number,status,total,currency_symbol,date_created,billing,line_items` );
		c.page++;
		c.totalPages = r.totalPages;
		c.total = r.total;
		c.items.push( ...r.items );
		state.cache.orders = c;
	}

	async function loadOrderSummary() {
		const summary = { month: null, processing: null };
		await Promise.all( [
			api( 'wc/v3/reports/sales?period=month' )
				.then( ( r ) => { summary.month = r && r[ 0 ] ? r[ 0 ] : null; } )
				.catch( () => {} ),
			apiPaged( 'wc/v3/orders?status=processing&per_page=1&_fields=id' )
				.then( ( r ) => { summary.processing = r.total; } )
				.catch( () => {} ),
		] );
		state.cache.orderSummary = summary;
		const badge = $( '#minn-orders-count' );
		if ( badge && summary.processing != null ) {
			badge.textContent = summary.processing;
			badge.hidden = ! summary.processing;
		}
	}

	function customerName( o ) {
		const b = o.billing || {};
		return ( ( b.first_name || '' ) + ' ' + ( b.last_name || '' ) ).trim() || b.email || 'Guest';
	}

	function renderOrders() {
		const view = $( '#minn-view' );
		const c = state.cache.orders;
		if ( ! c ) {
			view.innerHTML = '<div class="minn-loading">Loading orders…</div>';
			Promise.all( [ loadOrders(), state.cache.orderSummary ? null : loadOrderSummary() ] )
				.then( renderIfCurrent( 'orders' ) ).catch( showErr );
			return;
		}
		const s = state.cache.orderSummary || {};
		const sym = ( c.items[ 0 ] && c.items[ 0 ].currency_symbol ) || '$';
		const summaryCards = [];
		if ( s.month ) {
			summaryCards.push( [ 'Orders this month', s.month.total_orders ?? '—', '' ] );
			summaryCards.push( [ 'Revenue this month', sym + Number( s.month.total_sales || 0 ).toLocaleString(), 'net ' + sym + Number( s.month.net_sales || 0 ).toLocaleString() ] );
		}
		if ( s.processing != null ) summaryCards.push( [ 'Awaiting fulfillment', s.processing, 'processing' ] );

		view.innerHTML = `
		${ summaryCards.length ? `<div class="minn-stats" style="grid-template-columns:repeat(${ summaryCards.length },1fr);">
			${ summaryCards.map( ( [ label, value, delta ] ) => `
				<div class="minn-card minn-stat">
					<div class="minn-stat-label">${ esc( label ) }</div>
					<div class="minn-stat-value">${ esc( String( value ) ) }</div>
					${ delta ? `<div class="minn-stat-delta">${ esc( delta ) }</div>` : '' }
				</div>` ).join( '' ) }
		</div>` : '' }
		<div class="minn-toolbar">
			<div class="minn-tabs">
				${ ORDER_TABS.map( ( [ id, label ] ) =>
					`<button class="minn-tab${ state.orderTab === id ? ' active' : '' }" data-otab="${ id }">${ label }</button>` ).join( '' ) }
			</div>
			<div class="minn-toolbar-meta">${ c.total } order${ c.total === 1 ? '' : 's' }</div>
		</div>
		<div class="minn-card minn-table">
			<div class="minn-table-head minn-order-cols">
				<div>Order</div><div>Customer</div><div>Status</div><div>Items</div><div>Total</div><div></div>
			</div>
			${ c.items.length ? c.items.map( ( o ) => `
				<div class="minn-table-row minn-order-cols" data-order="${ o.id }">
					<div class="minn-cell-clip">
						<div class="minn-row-title">#${ esc( o.number ) }</div>
						<div class="minn-row-slug">${ timeAgo( o.date_created ) }</div>
					</div>
					<div class="minn-row-meta minn-cell-clip">${ esc( customerName( o ) ) }</div>
					<div><span class="minn-status ${ ORDER_STATUS_STYLE[ o.status ] || 'draft' }">${ esc( o.status.replace( '-', ' ' ) ) }</span></div>
					<div class="minn-row-meta">${ ( o.line_items || [] ).reduce( ( n, li ) => n + ( li.quantity || 0 ), 0 ) }</div>
					<div class="minn-row-meta" style="font-variant-numeric:tabular-nums;">${ esc( ( o.currency_symbol || sym ) + o.total ) }</div>
					<div class="minn-row-arrow">›</div>
				</div>` ).join( '' ) : '<div class="minn-empty">No orders here.</div>' }
		</div>
		${ c.page < c.totalPages ? '<button class="minn-load-more" id="minn-orders-more">Load more</button>' : '' }`;

		$$( '[data-otab]', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', () => {
				state.orderTab = btn.dataset.otab;
				state.cache.orders = null;
				renderOrders();
			} )
		);
		$$( '[data-order]', view ).forEach( ( row ) =>
			row.addEventListener( 'click', () => {
				const o = c.items.find( ( x ) => x.id === parseInt( row.dataset.order, 10 ) );
				if ( o ) { state.modal = { type: 'order', order: o }; renderOverlays(); }
			} )
		);
		const more = $( '#minn-orders-more', view );
		if ( more ) {
			more.addEventListener( 'click', async () => {
				more.disabled = true;
				more.textContent = 'Loading…';
				await loadOrders( true ).catch( showErr );
				if ( state.route === 'orders' ) renderOrders();
			} );
		}
	}

	/* ===== Users ===== */

	async function loadUsers( more ) {
		const c = more && state.cache.users ? state.cache.users : { items: [], page: 0, totalPages: 1, total: 0 };
		let q = `wp/v2/users?context=edit&per_page=50&orderby=registered_date&order=desc&_fields=id,name,email,roles,registered_date,avatar_urls&page=${ c.page + 1 }`;
		if ( state.userSearch ) q += '&search=' + encodeURIComponent( state.userSearch );
		if ( state.userRole && state.userRole !== '_all' ) q += '&roles=' + encodeURIComponent( state.userRole );
		const r = await apiPaged( q );
		c.page++;
		c.totalPages = r.totalPages;
		c.total = r.total;
		c.items.push( ...r.items );
		state.cache.users = c;
	}

	let userSearchTimer = null;

	function renderUsers() {
		const view = $( '#minn-view' );
		const c = state.cache.users;
		if ( ! c ) {
			view.innerHTML = '<div class="minn-loading">Loading users…</div>';
			loadUsers().then( renderIfCurrent( 'users' ) ).catch( showErr );
			return;
		}
		const roleTabs = [ [ '_all', 'All' ], ...Object.entries( B.roles || {} ) ];
		view.innerHTML = `
		<div class="minn-toolbar">
			${ roleTabs.length > 2 ? `<div class="minn-tabs">
				${ roleTabs.map( ( [ id, label ] ) =>
					`<button class="minn-tab${ ( state.userRole || '_all' ) === id ? ' active' : '' }" data-role="${ esc( id ) }">${ esc( label ) }</button>` ).join( '' ) }
			</div>` : '' }
			<input class="minn-input minn-toolbar-search" id="minn-user-search" placeholder="Search users…" value="${ esc( state.userSearch || '' ) }">
			<div class="minn-toolbar-meta">${ c.total } user${ c.total === 1 ? '' : 's' }</div>
			${ B.caps.createUsers ? `<button class="minn-btn-soft" id="minn-add-user" style="margin-left:0;">${ icon( 'plus' ) } Add user</button>` : '' }
		</div>
		<div class="minn-card minn-table">
			<div class="minn-table-head minn-user-cols">
				<div></div><div>Name</div><div>Email</div><div>Role</div><div>Registered</div><div></div>
			</div>
			${ c.items.length ? c.items.map( ( u ) => `
				<div class="minn-table-row minn-user-cols" data-user="${ u.id }">
					<img class="minn-user-row-avatar" src="${ esc( ( u.avatar_urls && ( u.avatar_urls[ '48' ] || Object.values( u.avatar_urls )[ 0 ] ) ) || '' ) }" alt="">
					<div class="minn-row-title minn-cell-clip">${ esc( u.name ) }</div>
					<div class="minn-row-meta minn-cell-clip">${ esc( u.email || '—' ) }</div>
					<div class="minn-row-meta">${ esc( ( u.roles || [] ).map( ( r ) => r.charAt( 0 ).toUpperCase() + r.slice( 1 ) ).join( ', ' ) || '—' ) }</div>
					<div class="minn-row-meta">${ u.registered_date ? timeAgo( u.registered_date ) : '—' }</div>
					<div class="minn-row-arrow">›</div>
				</div>` ).join( '' ) : '<div class="minn-empty">No users found.</div>' }
		</div>
		${ c.page < c.totalPages ? '<button class="minn-load-more" id="minn-users-more">Load more</button>' : '' }`;

		$$( '.minn-tab', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', async () => {
				if ( ( state.userRole || '_all' ) === btn.dataset.role ) return;
				state.userRole = btn.dataset.role;
				state.cache.users = null;
				view.innerHTML = '<div class="minn-loading">Loading users…</div>';
				await loadUsers().catch( showErr );
				if ( state.route === 'users' ) renderUsers();
			} )
		);
		const search = $( '#minn-user-search', view );
		search.addEventListener( 'input', () => {
			clearTimeout( userSearchTimer );
			userSearchTimer = setTimeout( async () => {
				state.userSearch = search.value.trim();
				state.cache.users = null;
				await loadUsers().catch( showErr );
				if ( state.route === 'users' ) {
					renderUsers();
					const el = $( '#minn-user-search' );
					el.focus();
					el.setSelectionRange( el.value.length, el.value.length );
				}
			}, 350 );
		} );
		const addBtn = $( '#minn-add-user', view );
		if ( addBtn ) addBtn.addEventListener( 'click', () => openUserModal( null ) );
		$$( '[data-user]', view ).forEach( ( row ) =>
			row.addEventListener( 'click', () => {
				if ( B.caps.editUsers ) openUserModal( parseInt( row.dataset.user, 10 ) );
				else window.open( B.site.adminUrl + 'user-edit.php?user_id=' + row.dataset.user, '_blank' );
			} )
		);
		const more = $( '#minn-users-more', view );
		if ( more ) {
			more.addEventListener( 'click', async () => {
				more.disabled = true;
				more.textContent = 'Loading…';
				await loadUsers( true ).catch( showErr );
				if ( state.route === 'users' ) renderUsers();
			} );
		}
	}

	/* ===== Surfaces (declarative third-party plugin views) ===== */

	function surfaceState( id ) {
		if ( ! state.surface[ id ] ) {
			state.surface[ id ] = { tab: '_all', cache: null, tabs: null, labels: {} };
		}
		return state.surface[ id ];
	}

	function surfaceRoute( s, ss, page ) {
		const col = s.collection;
		let route = ss.tab === '_all'
			? ( col.allRoute || col.route )
			: col.route.replace( '{tab}', encodeURIComponent( ss.tab ) );
		const parts = [];
		if ( col.query ) parts.push( col.query );
		if ( col.tabs && col.tabs.param && ss.tab !== '_all' ) {
			parts.push( col.tabs.param + '=' + encodeURIComponent( ss.tab ) );
		}
		// {page} is 1-based; {page0} serves APIs that count pages from zero.
		parts.push( ( col.pageQuery || 'per_page=25&page={page}' ).replace( '{page}', page ).replace( '{page0}', page - 1 ) );
		return route + ( route.includes( '?' ) ? '&' : '?' ) + parts.join( '&' );
	}

	async function loadSurfaceTabs( s ) {
		const ss = surfaceState( s.id );
		const tabs = s.collection.tabs;
		if ( ! tabs || ss.tabs ) return;
		const all = [ [ '_all', tabs.allLabel || 'All' ] ];
		if ( tabs.static ) {
			ss.tabs = all.concat( tabs.static );
		} else if ( tabs.route ) {
			const body = await api( tabs.route );
			const items = Array.isArray( body ) ? body : Object.values( body );
			ss.tabs = all.concat( items.map( ( it ) => [ String( it[ tabs.valueKey ] ), stripTags( String( it[ tabs.labelKey ] || it[ tabs.valueKey ] ) ) ] ) );
		} else {
			ss.tabs = all;
		}
	}

	async function loadSurfaceItems( s, more ) {
		const ss = surfaceState( s.id );
		const col = s.collection;
		const c = more && ss.cache ? ss.cache : { items: [], page: 0, total: 0 };
		const res = await apiRes( surfaceRoute( s, ss, c.page + 1 ) );
		const body = await res.json();
		const items = col.itemsKey
			? ( body[ col.itemsKey ] || [] )
			: ( Array.isArray( body ) ? body : Object.values( body ) );
		c.total = col.totalKey
			? parseInt( body[ col.totalKey ] || 0, 10 )
			: parseInt( res.headers.get( 'X-WP-Total' ) || String( items.length ), 10 );
		c.page++;
		c.items.push( ...items );
		ss.cache = c;
	}

	const PILL_STYLES = {
		green: [ 'sent', 'active', 'completed', 'publish', 'approved', 'success', 'read' ],
		red: [ 'failed', 'spam', 'error', 'cancelled' ],
		amber: [ 'sandboxed', 'pending', 'hold', 'on-hold', 'unread' ],
	};

	function surfacePill( value ) {
		const v = String( value || '' ).toLowerCase();
		let cls = 'draft';
		if ( PILL_STYLES.green.includes( v ) ) cls = 'publish';
		else if ( PILL_STYLES.red.includes( v ) ) cls = 'trash-status';
		else if ( PILL_STYLES.amber.includes( v ) ) cls = 'private';
		return `<span class="minn-status ${ cls }">${ esc( v || '—' ) }</span>`;
	}

	// First few scalar values stored under numeric-ish keys (GF entries store
	// field values as { "1": "...", "2.3": "..." }).
	function entrySummary( item ) {
		const vals = Object.keys( item )
			.filter( ( k ) => /^\d+(\.\d+)?$/.test( k ) )
			.sort( ( a, b ) => parseFloat( a ) - parseFloat( b ) )
			.map( ( k ) => String( item[ k ] || '' ).trim() )
			.filter( Boolean );
		return vals.slice( 0, 3 ).join( ' · ' ).slice( 0, 90 ) || '(empty entry)';
	}

	// Dot-path lookup ("initiator_data.user_login") with an optional fallback.
	function surfaceValue( item, key ) {
		if ( ! key ) return undefined;
		return key.split( '.' ).reduce( ( o, k ) => ( o && typeof o === 'object' ? o[ k ] : undefined ), item );
	}

	function surfaceCell( item, colDef ) {
		let v = surfaceValue( item, colDef.key );
		if ( ( v == null || v === '' ) && colDef.altKey ) v = surfaceValue( item, colDef.altKey );
		switch ( colDef.format ) {
			case 'ago': return `<div class="minn-row-meta">${ v ? timeAgo( String( v ).replace( ' ', 'T' ) ) : '—' }</div>`;
			case 'pill': return `<div>${ surfacePill( v ) }</div>`;
			case 'title': return `<div class="minn-row-title minn-cell-clip">${ esc( stripTags( String( v || '—' ) ) ) }</div>`;
			case 'entry-summary': return `<div class="minn-row-title minn-cell-clip">${ esc( entrySummary( item ) ) }</div>`;
			case 'mono': return `<div class="minn-row-meta" style="font-family:'JetBrains Mono',monospace;">${ esc( String( v || '—' ) ) }</div>`;
			default: return `<div class="minn-row-meta minn-cell-clip">${ esc( stripTags( String( v == null || v === '' ? '—' : v ) ) ) }</div>`;
		}
	}

	function renderSurface( s ) {
		const view = $( '#minn-view' );
		const ss = surfaceState( s.id );
		if ( ! ss.cache || ( s.collection.tabs && ! ss.tabs ) ) {
			view.innerHTML = '<div class="minn-loading">Loading…</div>';
			Promise.all( [ loadSurfaceTabs( s ), loadSurfaceItems( s ) ] )
				.then( renderIfCurrent( s.id ) )
				.catch( showErr );
			return;
		}
		const c = ss.cache;
		const cols = s.collection.columns || [];
		const gridCols = cols.map( ( col, i ) => i === 0 ? 'minmax(0,2fr)' : ( col.format === 'ago' || col.format === 'pill' ? '120px' : 'minmax(0,1fr)' ) ).join( ' ' ) + ' 30px';

		view.innerHTML = `
		<div class="minn-toolbar">
			${ ss.tabs && ss.tabs.length > 1 ? `
			<div class="minn-tabs">
				${ ss.tabs.map( ( [ id, label ] ) =>
					`<button class="minn-tab${ ss.tab === id ? ' active' : '' }" data-stab="${ esc( id ) }">${ esc( label ) }</button>` ).join( '' ) }
			</div>` : '' }
			<div class="minn-toolbar-meta">${ c.total } item${ c.total === 1 ? '' : 's' }</div>
		</div>
		<div class="minn-card minn-table">
			<div class="minn-table-head" style="grid-template-columns:${ gridCols };">
				${ cols.map( ( col ) => `<div>${ esc( col.label ) }</div>` ).join( '' ) }<div></div>
			</div>
			${ c.items.length ? c.items.map( ( item, i ) => `
				<div class="minn-table-row" style="grid-template-columns:${ gridCols };" data-sitem="${ i }">
					${ cols.map( ( col ) => surfaceCell( item, col ) ).join( '' ) }
					<div class="minn-row-arrow">›</div>
				</div>` ).join( '' ) : '<div class="minn-empty">Nothing here.</div>' }
		</div>
		${ c.items.length < c.total ? '<button class="minn-load-more" id="minn-surface-more">Load more</button>' : '' }`;

		$$( '[data-stab]', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', () => {
				ss.tab = btn.dataset.stab;
				ss.cache = null;
				renderSurface( s );
			} )
		);
		$$( '[data-sitem]', view ).forEach( ( row ) =>
			row.addEventListener( 'click', () => {
				const item = c.items[ parseInt( row.dataset.sitem, 10 ) ];
				if ( item ) openSurfaceDetail( s, item );
			} )
		);
		const more = $( '#minn-surface-more', view );
		if ( more ) {
			more.addEventListener( 'click', async () => {
				more.disabled = true;
				more.textContent = 'Loading…';
				await loadSurfaceItems( s, true ).catch( showErr );
				if ( state.route === s.id ) renderSurface( s );
			} );
		}
	}

	async function openSurfaceDetail( s, item ) {
		state.modal = { type: 'surface', surface: s, item, labels: null, loading: true };
		renderOverlays();
		const detail = ( s.collection.detail || {} );
		try {
			if ( detail.detailRoute ) {
				state.modal.item = await api( detail.detailRoute.replace( '{id}', item.id ) );
			}
			if ( detail.labels ) {
				const ss = surfaceState( s.id );
				const route = detail.labels.route.replace( /\{(\w+)\}/g, ( _, k ) => item[ k ] );
				if ( ! ss.labels[ route ] ) {
					const body = await api( route );
					const defs = detail.labels.itemsKey ? ( body[ detail.labels.itemsKey ] || [] ) : body;
					const map = {};
					( Array.isArray( defs ) ? defs : Object.values( defs ) ).forEach( ( d ) => {
						map[ String( d[ detail.labels.valueKey ] ) ] = stripTags( String( d[ detail.labels.labelKey ] || '' ) );
						( d.inputs || [] ).forEach( ( inp ) => {
							map[ String( inp.id ) ] = stripTags( String( inp.label || '' ) );
						} );
					} );
					ss.labels[ route ] = map;
				}
				state.modal.labels = ss.labels[ route ];
			}
		} catch ( e ) { /* show what we have */ }
		if ( state.modal && state.modal.type === 'surface' ) {
			state.modal.loading = false;
			renderOverlays();
		}
	}

	/* ===== Extensions ===== */

	async function loadPlugins() {
		const jobs = [ api( 'wp/v2/plugins' ) ];
		if ( B.caps.update ) {
			jobs.push( api( 'minn-admin/v1/plugin-updates' ).then( ( r ) => r.updates ).catch( () => ( {} ) ) );
		}
		const [ plugins, updates ] = await Promise.all( jobs );
		state.cache.plugins = plugins;
		state.cache.pluginUpdates = updates || {};
		const dot = $( '#minn-plugin-dot' );
		if ( dot ) dot.hidden = ! Object.keys( state.cache.pluginUpdates ).length;
	}

	const extTabsHtml = () => B.caps.themes ? `
			<div class="minn-tabs">
				<button class="minn-tab${ state.extTab === 'plugins' ? ' active' : '' }" data-xtab="plugins">Plugins</button>
				<button class="minn-tab${ state.extTab === 'themes' ? ' active' : '' }" data-xtab="themes">Themes</button>
			</div>` : '';

	function bindExtTabs( view ) {
		$$( '[data-xtab]', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', () => {
				state.extTab = btn.dataset.xtab;
				renderExtensions();
			} )
		);
	}

	function renderExtensions() {
		if ( state.extTab === 'themes' && B.caps.themes ) return renderThemes();
		const view = $( '#minn-view' );
		const plugins = state.cache.plugins;
		if ( ! plugins ) {
			view.innerHTML = '<div class="minn-loading">Loading extensions…</div>';
			loadPlugins().then( renderIfCurrent( 'extensions' ) ).catch( showErr );
			return;
		}
		const updates = state.cache.pluginUpdates;
		const updateCount = Object.keys( updates ).length;
		const active = plugins.filter( ( p ) => p.status === 'active' ).length;

		view.innerHTML = `
		<div class="minn-toolbar">
			${ extTabsHtml() }
			<div class="minn-toolbar-meta" style="margin-left:0;">${ active } active · ${ plugins.length } installed</div>
			${ B.caps.install ? `
				<button class="minn-btn-soft" id="minn-add-plugin" style="margin-left:auto;">${ icon( 'plus' ) } Add plugin</button>` : '' }
			${ updateCount && B.caps.update ? `
				<button class="minn-btn-soft" id="minn-update-all"${ B.caps.install ? '' : ' style="margin-left:auto;"' }>
					${ icon( 'refresh' ) } Update all (${ updateCount })
				</button>` : '' }
		</div>
		<div class="minn-plugin-grid">
			${ plugins.map( ( p ) => {
				const name = cleanPluginName( p.name );
				const hasUpdate = !! updates[ p.plugin + '.php' ];
				const on = p.status === 'active';
				return `
				<div class="minn-card minn-plugin" data-plugin="${ esc( p.plugin ) }">
					<div class="minn-plugin-icon" style="background:${ colorFor( name ) }">${ esc( name.charAt( 0 ) ) }</div>
					<div class="minn-plugin-body">
						<div class="minn-plugin-head">
							<div class="minn-plugin-name">${ esc( name ) }</div>
							${ hasUpdate ? ( B.caps.update
								? `<button class="minn-badge-update as-btn" data-update="${ esc( p.plugin ) }" title="Update to ${ esc( updates[ p.plugin + '.php' ] ) }">Update → ${ esc( updates[ p.plugin + '.php' ] ) }</button>`
								: `<span class="minn-badge-update">Update</span>` ) : '' }
						</div>
						<div class="minn-plugin-desc">${ esc( stripTags( p.description && p.description.rendered ) ) }</div>
						<div class="minn-plugin-foot">
							<div class="minn-plugin-ver">v${ esc( p.version || '?' ) }</div>
							<button class="minn-switch${ on ? ' on' : '' }" data-toggle="${ esc( p.plugin ) }" role="switch" aria-checked="${ on }" aria-label="Toggle ${ esc( name ) }"><span class="minn-switch-knob"></span></button>
							<span class="minn-state-label${ on ? ' on' : '' }">${ on ? 'Active' : 'Inactive' }</span>
							${ ! on && B.caps.delete ? `<button class="minn-plugin-delete" data-del="${ esc( p.plugin ) }" title="Delete ${ esc( name ) }">${ icon( 'trash' ) }</button>` : '' }
						</div>
					</div>
				</div>`;
			} ).join( '' ) }
		</div>`;

		$$( '[data-toggle]', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', async () => {
				const file = btn.dataset.toggle;
				const plugin = plugins.find( ( p ) => p.plugin === file );
				const activating = plugin.status !== 'active';
				if ( ! activating && file === 'minn-admin/minn-admin' &&
					! confirm( 'Deactivating Minn Admin will close this dashboard. Continue?' ) ) {
					return;
				}
				btn.disabled = true;
				const card = btn.closest( '.minn-plugin' );
				if ( card ) card.classList.add( 'minn-busy' );
				toast( `${ activating ? 'Activating' : 'Deactivating' } ${ cleanPluginName( plugin.name ) }…` );
				try {
					await api( 'wp/v2/plugins/' + file, {
						method: 'PUT',
						body: JSON.stringify( { status: activating ? 'active' : 'inactive' } ),
					} );
					plugin.status = activating ? 'active' : 'inactive';
					toast( cleanPluginName( plugin.name ) + ( activating ? ' activated' : ' deactivated' ) );
					if ( file === 'minn-admin/minn-admin' && ! activating ) {
						window.location.href = B.site.adminUrl;
						return;
					}
				} catch ( e ) {
					toast( e.message, true );
				}
				renderExtensions();
			} )
		);

		$$( '[data-update]', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', async () => {
				const file = btn.dataset.update;
				const plugin = plugins.find( ( p ) => p.plugin === file );
				const name = cleanPluginName( plugin.name );
				btn.disabled = true;
				const card = btn.closest( '.minn-plugin' );
				if ( card ) card.classList.add( 'minn-busy' );
				toast( `Updating ${ name }…` );
				try {
					const r = await api( 'minn-admin/v1/plugins/update', {
						method: 'POST',
						body: JSON.stringify( { plugin: file + '.php' } ),
					} );
					toast( `${ name } updated${ r.version ? ' to v' + r.version : '' }` );
				} catch ( e ) {
					toast( e.message, true );
				}
				state.cache.plugins = null;
				await loadPlugins().catch( () => {} );
				if ( state.route === 'extensions' ) renderExtensions();
			} )
		);

		$$( '[data-del]', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', async () => {
				const file = btn.dataset.del;
				const plugin = plugins.find( ( p ) => p.plugin === file );
				const name = cleanPluginName( plugin.name );
				if ( ! confirm( `Delete “${ name }”? This removes its files from the server.` ) ) return;
				btn.disabled = true;
				const card = btn.closest( '.minn-plugin' );
				if ( card ) card.classList.add( 'minn-busy' );
				toast( `Deleting ${ name }…` );
				try {
					await api( 'wp/v2/plugins/' + file, { method: 'DELETE' } );
					toast( name + ' deleted' );
					state.cache.plugins = null;
					await loadPlugins().catch( () => {} );
				} catch ( e ) {
					toast( e.message, true );
					if ( card ) card.classList.remove( 'minn-busy' );
				}
				if ( state.route === 'extensions' ) renderExtensions();
			} )
		);

		const updateAllBtn = $( '#minn-update-all', view );
		if ( updateAllBtn ) {
			updateAllBtn.addEventListener( 'click', () => updateAllPlugins( updateAllBtn ) );
		}
		const addBtn = $( '#minn-add-plugin', view );
		if ( addBtn ) {
			addBtn.addEventListener( 'click', () => {
				state.modal = { type: 'plugin-install', q: '', results: null, searching: false };
				renderOverlays();
			} );
		}
		bindExtTabs( view );
	}

	/* ===== Themes ===== */

	async function loadThemes() {
		state.cache.themes = ( await api( 'minn-admin/v1/themes' ) ).themes;
	}

	function renderThemes() {
		const view = $( '#minn-view' );
		const themes = state.cache.themes;
		if ( ! themes ) {
			view.innerHTML = '<div class="minn-loading">Loading themes…</div>';
			loadThemes().then( renderIfCurrent( 'extensions' ) ).catch( showErr );
			return;
		}
		view.innerHTML = `
		<div class="minn-toolbar">
			${ extTabsHtml() }
			<div class="minn-toolbar-meta">${ themes.length } installed</div>
			${ B.caps.installThemes ? `<button class="minn-btn-soft" id="minn-add-theme" style="margin-left:auto;">${ icon( 'plus' ) } Add theme</button>` : '' }
		</div>
		<div class="minn-theme-grid">
			${ themes.map( ( t, i ) => `
				<div class="minn-card minn-theme${ t.active ? ' is-active' : '' }" data-theme="${ i }">
					<div class="minn-theme-shot"${ t.screenshot ? ` style="background-image:url('${ esc( t.screenshot ) }')"` : '' }>
						${ t.active ? '<span class="minn-status publish minn-theme-badge">Active</span>' : '' }
						${ t.update ? `<span class="minn-badge-update minn-theme-badge-u">Update ${ esc( t.update ) }</span>` : '' }
					</div>
					<div class="minn-theme-info">
						<div class="minn-row-title">${ esc( t.name ) }</div>
						<div class="minn-pi-meta">v${ esc( t.version ) }${ t.author ? ' · ' + esc( t.author ) : '' }${ t.parent ? ' · child of ' + esc( t.parent ) : '' }</div>
						<div class="minn-theme-actions">
							${ ! t.active ? `<button class="minn-btn-soft" data-tact="activate:${ i }">Activate</button>` : '' }
							${ t.update && B.caps.updateThemes ? `<button class="minn-badge-update as-btn" data-tact="update:${ i }">Update → ${ esc( t.update ) }</button>` : '' }
							${ ! t.active && B.caps.deleteThemes ? `<button class="minn-plugin-delete" data-tact="delete:${ i }" title="Delete ${ esc( t.name ) }">${ icon( 'trash' ) }</button>` : '' }
						</div>
					</div>
				</div>` ).join( '' ) }
		</div>`;

		bindExtTabs( view );
		const addTheme = $( '#minn-add-theme', view );
		if ( addTheme ) {
			addTheme.addEventListener( 'click', () => {
				state.modal = { type: 'theme-install', q: '', results: null, searching: false };
				renderOverlays();
			} );
		}
		$$( '[data-tact]', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', async () => {
				const [ action, idx ] = btn.dataset.tact.split( ':' );
				const t = themes[ parseInt( idx, 10 ) ];
				if ( ! t ) return;
				const confirms = {
					activate: `Switch the site's theme to “${ t.name }”? This changes how the whole site looks.`,
					delete: `Delete “${ t.name }”? This removes its files from the server.`,
				};
				if ( confirms[ action ] && ! confirm( confirms[ action ] ) ) return;
				btn.disabled = true;
				const card = btn.closest( '.minn-theme' );
				if ( card ) card.classList.add( 'minn-busy' );
				const verbs = { activate: 'Activating', delete: 'Deleting', update: 'Updating' };
				toast( `${ verbs[ action ] } ${ t.name }…` );
				try {
					const r = await api( 'minn-admin/v1/themes/' + action, {
						method: 'POST',
						body: JSON.stringify( { stylesheet: t.stylesheet } ),
					} );
					const done = { activate: `${ t.name } is now the active theme`, delete: `${ t.name } deleted`, update: `${ t.name } updated${ r.version ? ' to v' + r.version : '' }` };
					toast( done[ action ] );
				} catch ( e ) {
					toast( e.message, true );
				}
				state.cache.themes = null;
				if ( state.route === 'extensions' ) renderExtensions();
			} )
		);
	}

	async function updateAllPlugins( btn ) {
		if ( btn ) {
			btn.disabled = true;
			btn.textContent = 'Updating…';
		}
		toast( 'Updating plugins — this can take a minute…' );
		try {
			const r = await api( 'minn-admin/v1/plugins/update-all', { method: 'POST', body: '{}' } );
			const n = ( r.updated || [] ).length;
			if ( r.failed && r.failed.length ) {
				toast( `${ n } updated, ${ r.failed.length } failed`, true );
			} else {
				toast( n ? `${ n } plugin${ n === 1 ? '' : 's' } updated` : 'Everything is up to date' );
			}
		} catch ( e ) {
			toast( e.message, true );
		}
		state.cache.plugins = null;
		if ( state.route === 'extensions' ) renderExtensions();
	}

	/* ===== Settings ===== */

	const SETTINGS_SECTIONS = [ 'General', 'Writing', 'Reading', 'Discussion', 'Permalinks' ];
	const POST_FORMATS = [ 'standard', 'aside', 'chat', 'gallery', 'link', 'image', 'quote', 'status', 'video', 'audio' ];

	async function loadSettings() {
		const [ values, categories, pages ] = await Promise.all( [
			api( 'wp/v2/settings' ),
			api( 'wp/v2/categories?per_page=100&_fields=id,name' ).catch( () => [] ),
			api( 'wp/v2/pages?per_page=100&status=publish&orderby=title&order=asc&_fields=id,title' ).catch( () => [] ),
		] );
		state.cache.settings = { values, categories, pages };
	}

	function settingsFields( section, s, cache ) {
		const text = ( key, label, value, mono ) => `
			<div>
				<div class="minn-field-label">${ label }</div>
				<input class="minn-input${ mono ? ' mono' : '' }" data-key="${ key }" value="${ esc( value == null ? '' : value ) }">
			</div>`;
		const select = ( key, label, options, current ) => `
			<div>
				<div class="minn-field-label">${ label }</div>
				<select class="minn-input" data-key="${ key }">
					${ options.map( ( [ v, l ] ) => `<option value="${ esc( v ) }"${ String( v ) === String( current ) ? ' selected' : '' }>${ esc( l ) }</option>` ).join( '' ) }
				</select>
			</div>`;
		const toggle = ( t ) => `
			<div class="minn-toggle-row">
				<div class="minn-toggle-info">
					<div class="minn-toggle-label">${ t.label }</div>
					<div class="minn-toggle-desc">${ t.desc }</div>
				</div>
				<button class="minn-switch${ t.on ? ' on' : '' }" data-setting="${ t.id }" role="switch" aria-checked="${ t.on }"><span class="minn-switch-knob"></span></button>
			</div>`;
		const pageOptions = [ [ 0, '— Select —' ], ...cache.pages.map( ( p ) => [ p.id, decodeEntities( p.title.rendered ) ] ) ];

		const DAYS = [ 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' ];
		const timezones = ( () => {
			try {
				const zones = Intl.supportedValuesOf( 'timeZone' );
				if ( s.timezone && ! zones.includes( s.timezone ) ) zones.unshift( s.timezone );
				return [ [ 'UTC', 'UTC' ], ...zones.map( ( z ) => [ z, z.replace( /_/g, ' ' ) ] ) ];
			} catch ( e ) {
				return [ [ 'UTC', 'UTC' ], ...( s.timezone ? [ [ s.timezone, s.timezone ] ] : [] ) ];
			}
		} )();

		switch ( section ) {
			case 'General': return {
				sub: 'Basic information about your site.',
				fields: text( 'title', 'Site title', s.title )
					+ text( 'description', 'Tagline', s.description )
					+ text( 'url', 'Site address', s.url, true )
					+ text( 'email', 'Administration email', s.email, true )
					+ select( 'timezone', 'Timezone', timezones, s.timezone || 'UTC' )
					+ text( 'date_format', 'Date format', s.date_format, true )
					+ text( 'time_format', 'Time format', s.time_format, true )
					+ select( 'start_of_week', 'Week starts on', DAYS.map( ( d, i ) => [ i, d ] ), s.start_of_week ),
				toggles: [ { id: 'minn_admin_maintenance', label: 'Maintenance mode', desc: 'Show a coming-soon page to visitors.', on: !! s.minn_admin_maintenance } ].map( toggle ).join( '' ),
			};
			case 'Writing': return {
				sub: 'Defaults for new posts.',
				fields: select( 'default_category', 'Default post category', cache.categories.map( ( c ) => [ c.id, decodeEntities( c.name ) ] ), s.default_category )
					+ select( 'default_post_format', 'Default post format', POST_FORMATS.map( ( f ) => [ f, f.charAt( 0 ).toUpperCase() + f.slice( 1 ) ] ), s.default_post_format || 'standard' ),
				toggles: '',
			};
			case 'Reading': return {
				sub: 'What visitors see, and who else can see it.',
				fields: select( 'show_on_front', 'Your homepage displays', [ [ 'posts', 'Latest posts' ], [ 'page', 'A static page' ] ], s.show_on_front )
					+ ( s.show_on_front === 'page' ? select( 'page_on_front', 'Homepage', pageOptions, s.page_on_front ) + select( 'page_for_posts', 'Posts page', pageOptions, s.page_for_posts ) : '' )
					+ text( 'posts_per_page', 'Blog pages show at most', s.posts_per_page ),
				toggles: [ { id: 'blog_public', label: 'Search engine visibility', desc: 'Allow search engines to index this site.', on: !! s.blog_public } ].map( toggle ).join( '' ),
			};
			case 'Discussion': return {
				sub: 'How comments and pingbacks behave on new posts.',
				fields: '',
				toggles: [
					{ id: 'default_comment_status', label: 'Allow comments', desc: 'Let readers respond to new posts.', on: s.default_comment_status === 'open' },
					{ id: 'default_ping_status', label: 'Allow pingbacks & trackbacks', desc: 'Accept link notifications from other blogs on new posts.', on: s.default_ping_status === 'open' },
				].map( toggle ).join( '' ),
			};
			default: return {
				sub: 'Permalink structure isn’t exposed over the REST API yet.',
				fields: `<div class="minn-editor-locked-note">Changing the permalink structure requires a rewrite-rule flush, so for now it lives in the classic admin. <a href="${ esc( B.site.adminUrl ) }options-permalink.php">Open permalink settings ↗</a></div>`,
				toggles: '',
				noSave: true,
			};
		}
	}

	function renderSettings() {
		const view = $( '#minn-view' );
		const cache = state.cache.settings;
		if ( ! cache ) {
			view.innerHTML = '<div class="minn-loading">Loading settings…</div>';
			loadSettings().then( renderIfCurrent( 'settings' ) ).catch( showErr );
			return;
		}
		const s = cache.values;
		const section = settingsFields( state.settingsSection, s, cache );

		view.innerHTML = `
		<div class="minn-settings">
			<div class="minn-settings-nav">
				${ SETTINGS_SECTIONS.map( ( label ) =>
					`<button class="minn-settings-nav-item${ label === state.settingsSection ? ' active' : '' }" data-section="${ label }">${ label }</button>` ).join( '' ) }
			</div>
			<div class="minn-settings-body">
				<div>
					<div class="minn-settings-title">${ state.settingsSection }</div>
					<div class="minn-settings-sub">${ section.sub }</div>
				</div>
				${ section.fields ? `<div class="minn-fields">${ section.fields }</div>` : '' }
				${ section.fields && section.toggles ? '<div class="minn-divider"></div>' : '' }
				${ section.toggles ? `<div class="minn-toggle-rows">${ section.toggles }</div>` : '' }
				${ section.noSave ? '' : '<div><button class="minn-btn-primary" id="minn-save-settings">Save changes</button></div>' }
			</div>
		</div>`;

		$$( '.minn-settings-nav-item', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', () => {
				state.settingsSection = btn.dataset.section;
				renderTopbar();
				renderSettings();
			} )
		);

		const pending = {};
		const OPEN_CLOSED = [ 'default_comment_status', 'default_ping_status' ];
		$$( '[data-setting]', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', () => {
				btn.classList.toggle( 'on' );
				const on = btn.classList.contains( 'on' );
				btn.setAttribute( 'aria-checked', on );
				const id = btn.dataset.setting;
				pending[ id ] = OPEN_CLOSED.includes( id ) ? ( on ? 'open' : 'closed' )
					: ( id === 'blog_public' ? ( on ? 1 : 0 ) : on );
			} )
		);

		// Re-render Reading when the homepage mode flips so page pickers appear.
		const showOnFront = $( '[data-key="show_on_front"]', view );
		if ( showOnFront ) {
			showOnFront.addEventListener( 'change', () => {
				cache.values.show_on_front = showOnFront.value;
				renderSettings();
			} );
		}

		const saveBtn = $( '#minn-save-settings', view );
		if ( saveBtn ) {
			saveBtn.addEventListener( 'click', async () => {
				saveBtn.disabled = true;
				const NUMERIC = [ 'default_category', 'posts_per_page', 'page_on_front', 'page_for_posts', 'start_of_week' ];
				const payload = { ...pending };
				$$( '[data-key]', view ).forEach( ( input ) => {
					const key = input.dataset.key;
					let value = input.value;
					if ( key === 'url' && value.trim() === s.url ) return;
					if ( NUMERIC.includes( key ) ) value = parseInt( value, 10 ) || 0;
					payload[ key ] = value;
				} );
				try {
					cache.values = await api( 'wp/v2/settings', { method: 'POST', body: JSON.stringify( payload ) } );
					toast( 'Settings saved' );
				} catch ( err ) {
					toast( err.message, true );
				}
				saveBtn.disabled = false;
			} );
		}
	}

	/* ===== Editor ===== */

	// Blocks whose markup survives a contenteditable round-trip. Anything else
	// (embeds, columns, custom blocks…) becomes an atomic non-editable island:
	// preserved byte-for-byte on save, editable text around it.
	const SIMPLE_BLOCKS = [ 'paragraph', 'heading', 'quote', 'code', 'preformatted', 'verse', 'list', 'list-item', 'image', 'table', 'html', 'separator', 'more' ];

	// Split raw post content into top-level segments: {type:'block',name,raw}
	// and {type:'html',raw} (freeform chunks). Returns null when the comment
	// structure is unbalanced or the segments don't reassemble the original —
	// the caller falls back to locked mode.
	function tokenizeBlocks( raw ) {
		const re = /<!--\s*(\/)?wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)((?:(?!-->)[\s\S])*?)(\/)?\s*-->/g;
		const segments = [];
		let depth = 0;
		let segStart = 0;
		let blockStart = 0;
		let blockName = '';
		let m;
		while ( ( m = re.exec( raw ) ) ) {
			const closing = !! m[ 1 ];
			const selfClosing = !! m[ 4 ];
			if ( closing ) {
				if ( depth === 0 ) return null;
				depth--;
				if ( depth === 0 ) {
					segments.push( { type: 'block', name: blockName, raw: raw.slice( blockStart, m.index + m[ 0 ].length ) } );
					segStart = m.index + m[ 0 ].length;
				}
			} else if ( selfClosing ) {
				if ( depth === 0 ) {
					if ( m.index > segStart ) segments.push( { type: 'html', raw: raw.slice( segStart, m.index ) } );
					segments.push( { type: 'block', name: m[ 2 ], raw: m[ 0 ] } );
					segStart = m.index + m[ 0 ].length;
				}
			} else {
				if ( depth === 0 ) {
					if ( m.index > segStart ) segments.push( { type: 'html', raw: raw.slice( segStart, m.index ) } );
					blockStart = m.index;
					blockName = m[ 2 ];
				}
				depth++;
			}
		}
		if ( depth !== 0 ) return null;
		if ( segStart < raw.length ) segments.push( { type: 'html', raw: raw.slice( segStart ) } );
		if ( segments.map( ( s ) => s.raw ).join( '' ) !== raw ) return null;
		return segments;
	}

	function editorModeFor( raw ) {
		if ( ! /<!--\s*wp:/.test( raw ) ) return 'classic';
		return tokenizeBlocks( raw ) ? 'blocks' : 'locked';
	}

	// Attributes the serializer reproduces faithfully; any other attribute on a
	// simple block turns it into an island so nothing is silently dropped.
	const EDITABLE_ATTRS = { heading: [ 'level' ], list: [ 'ordered' ], table: [ 'hasFixedLayout' ] };

	function segmentEditable( seg ) {
		const name = seg.name.replace( /^core\//, '' );
		if ( ! SIMPLE_BLOCKS.includes( name ) ) return false;
		const m = seg.raw.match( /^<!--\s*wp:[a-z0-9\/_-]+\s+(\{[\s\S]*?\})\s*\/?-->/ );
		if ( ! m ) return true;
		try {
			const attrs = JSON.parse( m[ 1 ] );
			const allowed = EDITABLE_ATTRS[ name ] || [];
			return Object.keys( attrs ).every( ( k ) => allowed.includes( k ) );
		} catch ( e ) {
			return false;
		}
	}

	// Build the contenteditable HTML: simple blocks stripped of comments,
	// complex blocks as atomic islands whose raw markup is stored verbatim.
	function buildEditableContent( ed, raw ) {
		const segments = tokenizeBlocks( raw ) || [];
		ed.islands = [];
		return segments.map( ( seg ) => {
			if ( seg.type === 'html' ) return seg.raw;
			if ( segmentEditable( seg ) ) return stripBlockComments( seg.raw );
			const idx = ed.islands.push( seg.raw ) - 1;
			const inner = stripBlockComments( seg.raw ).trim();
			return `<div class="minn-block-island" contenteditable="false" data-island="${ idx }">
				<span class="minn-island-chip">${ esc( seg.name.replace( /^core\//, '' ) ) }</span>
				${ inner ? `<div class="minn-island-preview">${ inner }</div>` : '<div class="minn-island-empty">Dynamic block — rendered on the site</div>' }
			</div>`;
		} ).join( '\n' );
	}

	const stripBlockComments = ( raw ) => raw.replace( /<!--\s*\/?wp:[\s\S]*?-->\n?/g, '' );

	// Minimal wpautop for editing classic content.
	function miniAutop( raw ) {
		if ( ! raw.trim() ) return '';
		if ( /<p[\s>]/i.test( raw ) ) return raw;
		return raw.split( /\n{2,}/ ).map( ( c ) => {
			c = c.trim();
			if ( ! c ) return '';
			return /^<(h[1-6]|ul|ol|pre|blockquote|figure|table|div|img|hr|!--)/i.test( c )
				? c : '<p>' + c.replace( /\n/g, '<br>' ) + '</p>';
		} ).join( '\n' );
	}

	// Serialize the edited DOM back to Gutenberg block markup.
	function serializeToBlocks( root, islands ) {
		const out = [];
		const pushBlock = ( name, attrs, html ) =>
			out.push( `<!-- wp:${ name }${ attrs ? ' ' + JSON.stringify( attrs ) : '' } -->\n${ html }\n<!-- /wp:${ name } -->` );

		Array.from( root.childNodes ).forEach( ( n ) => {
			if ( n.nodeType === Node.TEXT_NODE ) {
				const t = n.textContent.trim();
				if ( t ) pushBlock( 'paragraph', null, `<p>${ esc( t ) }</p>` );
				return;
			}
			if ( n.nodeType !== Node.ELEMENT_NODE ) return;
			// Islands pass through byte-for-byte from the original markup.
			if ( n.classList.contains( 'minn-block-island' ) ) {
				const raw = islands && islands[ parseInt( n.dataset.island, 10 ) ];
				if ( raw != null ) out.push( raw.trim() );
				return;
			}
			const tag = n.tagName.toLowerCase();
			const el = n.cloneNode( true );
			el.removeAttribute( 'style' );

			if ( tag === 'p' ) {
				if ( ! el.textContent.trim() && ! el.querySelector( 'img' ) ) return;
				pushBlock( 'paragraph', null, el.outerHTML );
			} else if ( /^h[1-6]$/.test( tag ) ) {
				el.classList.add( 'wp-block-heading' );
				pushBlock( 'heading', { level: parseInt( tag[ 1 ], 10 ) }, el.outerHTML );
			} else if ( tag === 'blockquote' ) {
				el.classList.add( 'wp-block-quote' );
				const cite = el.querySelector( ':scope > cite' );
				const paras = Array.from( el.children ).filter( ( ch ) => ch.tagName === 'P' );
				const inner = paras.length
					? paras.map( ( p ) => `<!-- wp:paragraph -->\n${ p.outerHTML }\n<!-- /wp:paragraph -->` ).join( '' )
					: `<!-- wp:paragraph -->\n<p>${ cite ? '' : el.innerHTML }</p>\n<!-- /wp:paragraph -->`;
				pushBlock( 'quote', null, `<blockquote class="wp-block-quote">${ inner }${ cite ? cite.outerHTML : '' }</blockquote>` );
			} else if ( tag === 'pre' && el.classList.contains( 'wp-block-verse' ) ) {
				pushBlock( 'verse', null, `<pre class="wp-block-verse">${ el.innerHTML }</pre>` );
			} else if ( tag === 'pre' && el.classList.contains( 'wp-block-preformatted' ) ) {
				pushBlock( 'preformatted', null, `<pre class="wp-block-preformatted">${ el.innerHTML }</pre>` );
			} else if ( tag === 'pre' ) {
				// Plain text so syntax-highlight spans never reach the database;
				// the language class is preserved (Prism-style, theme-compatible).
				const code = el.querySelector( 'code' );
				const text = codeTextOf( code || el );
				const lang = codeLangOf( el );
				pushBlock( 'code', null, `<pre class="wp-block-code"><code${ lang !== 'auto' ? ` class="language-${ lang }"` : '' }>${ esc( text ) }</code></pre>` );
			} else if ( ( tag === 'figure' && el.querySelector( 'table' ) ) || tag === 'table' ) {
				const table = tag === 'table' ? el : el.querySelector( 'table' );
				table.removeAttribute( 'data-hl' );
				const fixed = table.classList.contains( 'has-fixed-layout' );
				const caption = tag === 'figure' ? el.querySelector( ':scope > figcaption' ) : null;
				pushBlock(
					'table',
					fixed ? { hasFixedLayout: true } : null,
					`<figure class="wp-block-table"><table${ fixed ? ' class="has-fixed-layout"' : '' }>${ table.innerHTML }</table>${ caption ? caption.outerHTML : '' }</figure>`
				);
			} else if ( tag === 'ul' || tag === 'ol' ) {
				el.classList.add( 'wp-block-list' );
				const items = Array.from( el.querySelectorAll( ':scope > li' ) )
					.map( ( li ) => `<!-- wp:list-item -->\n${ li.outerHTML }\n<!-- /wp:list-item -->` ).join( '' );
				pushBlock( 'list', tag === 'ol' ? { ordered: true } : null, `<${ tag } class="${ el.className }">${ items }</${ tag }>` );
			} else if ( tag === 'figure' && el.querySelector( 'img' ) ) {
				el.classList.add( 'wp-block-image' );
				pushBlock( 'image', null, el.outerHTML );
			} else if ( tag === 'img' ) {
				pushBlock( 'image', null, `<figure class="wp-block-image">${ el.outerHTML }</figure>` );
			} else if ( tag === 'hr' ) {
				pushBlock( 'separator', null, '<hr class="wp-block-separator has-alpha-channel-opacity"/>' );
			} else if ( tag === 'div' || tag === 'section' ) {
				// contenteditable wraps things in divs — serialize their children
				// as if they were top-level instead of dumping raw HTML.
				const inner = serializeToBlocks( el, islands );
				if ( inner ) out.push( inner );
			} else {
				pushBlock( 'paragraph', null, `<p>${ el.outerHTML }</p>` );
			}
		} );
		return out.join( '\n\n' );
	}

	// Extra response keys editor panels read their values from (e.g. "acf").
	const panelValueKeys = () => ( B.editorPanels || [] ).map( ( p ) => p.valuesKey ).filter( Boolean );

	async function loadEditorPanels( ed, post ) {
		ed.panels = [];
		ed.panelValues = {};
		ed.panelDirty = {};
		await Promise.all( ( B.editorPanels || [] ).map( async ( desc ) => {
			if ( ! desc.fieldsRoute ) return;
			try {
				const route = desc.fieldsRoute.replace( '{id}', ed.id || 0 ).replace( '{type}', ed.type );
				const r = await api( route );
				const groups = ( r.groups || [] ).filter( ( g ) => g.fields.length || g.locked );
				if ( ! groups.length ) return;
				ed.panels.push( { desc, groups } );
				ed.panelValues[ desc.id ] = ( post && desc.valuesKey && post[ desc.valuesKey ] ) ? { ...post[ desc.valuesKey ] } : {};
			} catch ( e ) { /* panel just doesn't render */ }
		} ) );
		if ( ed.panels.length && state.route === 'editor' && state.editor === ed ) renderEditorSide();
	}

	/* ===== Code syntax highlighting (no dependencies) ===== */

	// The language rides on the <code> element as a Prism-style class
	// (language-php), which survives serialization and is what most theme
	// highlighters key on.
	const CODE_LANGS = [ 'auto', 'php', 'js', 'html', 'css', 'bash', 'json', 'python', 'sql' ];

	const HL_KEYWORD_SETS = {
		php: 'function|return|if|else|elseif|endif|for|foreach|endforeach|while|do|switch|case|default|break|continue|class|interface|trait|extends|implements|new|public|private|protected|static|final|abstract|echo|print|use|namespace|require|require_once|include|include_once|try|catch|finally|throw|true|false|null|array|isset|empty|unset|global|const|fn|match|as|self|parent|add_action|add_filter',
		js: 'function|return|if|else|for|while|do|switch|case|default|break|continue|class|extends|new|const|let|var|async|await|try|catch|finally|throw|typeof|instanceof|in|of|true|false|null|undefined|this|import|from|export|yield|delete|void',
		css: 'important|inherit|initial|unset|auto|none|flex|grid|block|inline|absolute|relative|fixed|sticky|solid|dashed|hover|focus|before|after|root|media|keyframes',
		bash: 'if|then|else|elif|fi|for|in|do|done|while|case|esac|function|echo|export|local|return|exit|true|false|sudo|cd|rm|cp|mv|grep|curl|wp',
		json: 'true|false|null',
		python: 'def|return|if|elif|else|for|while|break|continue|class|import|from|as|try|except|finally|raise|with|lambda|True|False|None|and|or|not|in|is|pass|yield|async|await|print|self',
		sql: 'SELECT|FROM|WHERE|AND|OR|NOT|INSERT|INTO|VALUES|UPDATE|SET|DELETE|JOIN|LEFT|RIGHT|INNER|OUTER|ON|GROUP|BY|ORDER|LIMIT|OFFSET|AS|CREATE|TABLE|ALTER|DROP|INDEX|NULL|LIKE|EXISTS|UNION|DISTINCT|COUNT|SUM|AVG|MIN|MAX|HAVING|DESC|ASC',
	};
	HL_KEYWORD_SETS.auto = [ HL_KEYWORD_SETS.php, HL_KEYWORD_SETS.js, HL_KEYWORD_SETS.python, HL_KEYWORD_SETS.bash ].join( '|' );

	function highlightHtml( text ) {
		return esc( text )
			.replace( /(&lt;!--[\s\S]*?--&gt;)/g, '<span class="tok-com">$1</span>' )
			.replace( /(&lt;\/?)([a-zA-Z][a-zA-Z0-9-]*)/g, '$1<span class="tok-kw">$2</span>' )
			.replace( /(&quot;[^&\n]*?&quot;)/g, '<span class="tok-str">$1</span>' );
	}

	function highlightCode( text, lang ) {
		lang = CODE_LANGS.includes( lang ) ? lang : 'auto';
		if ( lang === 'html' ) return highlightHtml( text );
		const kw = new RegExp( '\\b(' + ( HL_KEYWORD_SETS[ lang ] || HL_KEYWORD_SETS.auto ) + ')\\b', lang === 'sql' ? 'gi' : 'g' );
		const phpish = lang === 'php' || lang === 'auto';
		const out = [];
		const re = /(\/\/[^\n]*|\/\*[\s\S]*?\*\/|<!--[\s\S]*?-->|(?:^|(?<=\s))#[^\n]*)|("(?:[^"\\\n]|\\.)*"|'(?:[^'\\\n]|\\.)*'|`(?:[^`\\]|\\.)*`)|(\$[a-zA-Z_][a-zA-Z0-9_]*)|\b(\d+(?:\.\d+)?)\b/g;
		const plain = ( s ) => {
			s = esc( s ).replace( kw, '<span class="tok-kw">$1</span>' );
			if ( phpish ) s = s.replace( /(&lt;\?php|&lt;\?=|\?&gt;)/g, '<span class="tok-kw">$1</span>' );
			return s;
		};
		let last = 0;
		let m;
		while ( ( m = re.exec( text ) ) ) {
			out.push( plain( text.slice( last, m.index ) ) );
			if ( m[ 1 ] ) out.push( `<span class="tok-com">${ esc( m[ 1 ] ) }</span>` );
			else if ( m[ 2 ] ) out.push( `<span class="tok-str">${ esc( m[ 2 ] ) }</span>` );
			else if ( m[ 3 ] ) out.push( `<span class="tok-var">${ esc( m[ 3 ] ) }</span>` );
			else out.push( `<span class="tok-num">${ esc( m[ 4 ] ) }</span>` );
			last = m.index + m[ 0 ].length;
		}
		out.push( plain( text.slice( last ) ) );
		return out.join( '' );
	}

	const codeLangOf = ( pre ) => {
		const code = pre.querySelector( 'code' );
		return ( code && ( code.className.match( /language-([a-z0-9]+)/ ) || [] )[ 1 ] ) || 'auto';
	};

	// textContent drops <br> line breaks (contenteditable inserts them on
	// Enter) — convert them to newlines before reading code text.
	function codeTextOf( el ) {
		const clone = el.cloneNode( true );
		clone.querySelectorAll( 'br' ).forEach( ( br ) => br.replaceWith( '\n' ) );
		clone.querySelectorAll( 'div, p' ).forEach( ( d ) => d.prepend( '\n' ) );
		return clone.textContent.replace( /^\n/, '' );
	}

	// Re-render code blocks with highlight spans. Skips the block holding the
	// caret (unless forced); spans never persist — serialization stores pre
	// blocks via textContent.
	function highlightCodeBlocks( container, force ) {
		const sel = window.getSelection();
		const anchorEl = sel && sel.anchorNode
			? ( sel.anchorNode.nodeType === Node.ELEMENT_NODE ? sel.anchorNode : sel.anchorNode.parentElement )
			: null;
		$$( 'pre', container ).forEach( ( pre ) => {
			if ( ! force && anchorEl && pre.contains( anchorEl ) ) return;
			if ( pre.closest( '.minn-block-island' ) ) return; // islands stay verbatim
			// Verse and preformatted blocks are prose, not code.
			if ( pre.classList.contains( 'wp-block-verse' ) || pre.classList.contains( 'wp-block-preformatted' ) ) return;
			let code = pre.querySelector( 'code' );
			const lang = codeLangOf( pre );
			const text = codeTextOf( code || pre );
			if ( pre.dataset.hl === lang + '|' + text ) return;
			if ( ! code ) {
				pre.textContent = '';
				code = document.createElement( 'code' );
				pre.appendChild( code );
			}
			code.innerHTML = highlightCode( text, lang );
			pre.dataset.hl = lang + '|' + text;
		} );
	}

	async function loadEditor() {
		if ( state.editorId ) {
			// content.raw only — asking for content.rendered would run the_content,
			// which can be slow or fatal if another plugin misbehaves.
			const extraKeys = panelValueKeys().map( ( k ) => ',' + k ).join( '' );
			const p = await api( `wp/v2/${ state.editorType }/${ state.editorId }?context=edit&_fields=id,title,content.raw,status,slug,link,categories,tags,date,featured_media${ extraKeys }` );
			const raw = ( p.content && p.content.raw ) || '';
			const mode = editorModeFor( raw );
			state.editor = {
				id: p.id,
				type: state.editorType,
				title: decodeEntities( ( p.title && ( p.title.raw != null ? p.title.raw : p.title.rendered ) ) || '' ),
				content: '',
				islands: [],
				mode,
				editUrl: B.site.adminUrl + 'post.php?post=' + p.id + '&action=edit',
				status: p.status,
				date: p.date || null,
				newDate: null,
				slug: '/' + ( p.slug || '' ),
				link: p.link,
				savedAt: null,
				categoryIds: new Set( p.categories || [] ),
				tagIds: new Set( p.tags || [] ),
				tags: [],
				revisions: null,
				panels: null,
				supportsThumb: 'featured_media' in p,
				featuredMedia: p.featured_media || 0,
				featuredThumb: null,
			};
			if ( state.editorType === 'posts' && ( p.tags || [] ).length ) {
				api( `wp/v2/tags?include=${ p.tags.join( ',' ) }&per_page=100&_fields=id,name` )
					.then( ( t ) => {
						if ( state.editor && state.editor.id === p.id ) {
							state.editor.tags = ( Array.isArray( t ) ? t : [] ).map( ( x ) => ( { id: x.id, name: decodeEntities( x.name ) } ) );
							if ( state.route === 'editor' ) renderEditorSide();
						}
					} )
					.catch( () => {} );
			}
			if ( p.featured_media ) {
				api( `wp/v2/media/${ p.featured_media }?_fields=id,source_url,media_details` )
					.then( ( mItem ) => {
						if ( state.editor && state.editor.id === p.id ) {
							state.editor.featuredThumb = ( mItem.media_details && mItem.media_details.sizes && mItem.media_details.sizes.medium && mItem.media_details.sizes.medium.source_url ) || mItem.source_url;
							renderEditorSide();
						}
					} )
					.catch( () => {} );
			}
			state.editor.content = mode === 'blocks' ? buildEditableContent( state.editor, raw )
				: mode === 'classic' ? miniAutop( raw )
				: stripBlockComments( raw );
			loadEditorPanels( state.editor, p );
			// Revision history (types without revision support 404 — that's fine).
			api( `wp/v2/${ state.editorType }/${ p.id }/revisions?per_page=6&_fields=id,modified,_links,_embedded&_embed=author` )
				.then( ( revs ) => {
					if ( state.editor && state.editor.id === p.id ) {
						state.editor.revisions = revs.map( ( r ) => ( {
							id: r.id,
							modified: r.modified,
							author: ( r._embedded && r._embedded.author && r._embedded.author[ 0 ] && r._embedded.author[ 0 ].name ) || '',
						} ) );
						if ( state.route === 'editor' ) renderEditorSide();
					}
				} )
				.catch( () => {} );
			if ( mode === 'locked' ) {
				// Try to upgrade the read-only preview to fully rendered markup;
				// fall back to the stripped raw markup if rendering fails.
				api( `wp/v2/${ state.editorType }/${ p.id }?_fields=content.rendered` )
					.then( ( r ) => {
						if ( state.editor && state.editor.id === p.id && r.content && r.content.rendered ) {
							state.editor.content = r.content.rendered;
							const body = $( '#minn-editor-body' );
							if ( body ) {
								body.innerHTML = r.content.rendered;
								highlightCodeBlocks( body );
							}
						}
					} )
					.catch( () => {} );
			}
		} else {
			state.editor = {
				id: null, type: 'posts', title: '', content: '', status: 'draft', mode: 'blocks',
				date: null, newDate: null, slug: '', link: '', savedAt: null, categoryIds: new Set(),
				tagIds: new Set(), tags: [],
				revisions: null, panels: null,
				supportsThumb: true, featuredMedia: 0, featuredThumb: null,
			};
			loadEditorPanels( state.editor, null );
		}
		// All categories for the sidebar picker (posts only), cached per session.
		if ( state.editor.type === 'posts' && ! state.cache.categories ) {
			api( 'wp/v2/categories?per_page=100&orderby=count&order=desc&_fields=id,name' )
				.then( ( cats ) => {
					state.cache.categories = cats.map( ( c ) => ( { id: c.id, name: decodeEntities( c.name ) } ) );
					if ( state.route === 'editor' ) renderEditorSide();
				} )
				.catch( () => {} );
		}
		// Tag suggestions (shared with the Content filters), cached per session.
		if ( state.editor.type === 'posts' && ! state.cache.postTerms ) {
			loadPostTerms().then( () => { if ( state.route === 'editor' ) renderEditorSide(); } ).catch( () => {} );
		}
	}

	let autosaveTimer = null;

	async function saveEditor( extra = {} ) {
		const ed = state.editor;
		if ( ! ed || state.saving ) return;
		state.saving = true;
		const payload = {
			title: $( '#minn-editor-title' ) ? $( '#minn-editor-title' ).value : ed.title,
			...extra,
		};
		// Locked mode never touches the body — complex block markup stays intact.
		if ( ed.mode !== 'locked' ) {
			const body = $( '#minn-editor-body' );
			if ( body ) {
				payload.content = ed.mode === 'blocks' ? serializeToBlocks( body, ed.islands ) : classicHtml( body );
			}
		}
		if ( ed.type === 'posts' && ed.catsDirty ) {
			payload.categories = Array.from( ed.categoryIds );
		}
		if ( ed.type === 'posts' && ed.tagsDirty ) {
			payload.tags = Array.from( ed.tagIds );
		}
		( ed.panels || [] ).forEach( ( p ) => {
			if ( ed.panelDirty && ed.panelDirty[ p.desc.id ] && p.desc.writeKey ) {
				payload[ p.desc.writeKey ] = ed.panelValues[ p.desc.id ];
			}
		} );
		if ( ed.featuredDirty ) {
			payload.featured_media = ed.featuredMedia || 0;
		}
		try {
			let p;
			if ( ed.id ) {
				p = await api( `wp/v2/${ ed.type }/${ ed.id }`, { method: 'POST', body: JSON.stringify( payload ) } );
			} else {
				payload.status = payload.status || 'draft';
				p = await api( `wp/v2/${ ed.type }`, { method: 'POST', body: JSON.stringify( payload ) } );
				ed.id = p.id;
				state.editorId = p.id;
				setPath( `editor/${ ed.type }/${ p.id }`, true );
			}
			ed.status = p.status;
			ed.slug = '/' + ( p.slug || '' );
			ed.link = p.link;
			if ( p.date ) ed.date = p.date;
			if ( payload.date ) ed.newDate = null;
			ed.savedAt = Date.now();
			ed.panelDirty = {};
			ed.featuredDirty = false;
			state.cache.content = null;
			renderEditorSide();
			renderTopbar();
		} catch ( e ) {
			toast( e.message, true );
		}
		state.saving = false;
	}

	// Classic-mode save: innerHTML, but with highlight spans stripped from code
	// blocks so decoration never reaches the database.
	function classicHtml( body ) {
		const clone = body.cloneNode( true );
		$$( 'pre', clone ).forEach( ( pre ) => {
			const lang = codeLangOf( pre );
			const text = codeTextOf( pre );
			pre.removeAttribute( 'data-hl' );
			pre.innerHTML = `<code${ lang !== 'auto' ? ` class="language-${ lang }"` : '' }>${ esc( text ) }</code>`;
		} );
		return clone.innerHTML;
	}

	function scheduleAutosave() {
		clearTimeout( autosaveTimer );
		autosaveTimer = setTimeout( () => {
			// Never auto-publish: autosave keeps the current status.
			saveEditor();
		}, 2500 );
	}

	function scheduledInFuture( ed ) {
		return ed.newDate && new Date( ed.newDate ) > new Date();
	}

	function publishLabel( ed ) {
		if ( ed.status === 'publish' && ! scheduledInFuture( ed ) ) return 'Update';
		if ( ed.status === 'future' || scheduledInFuture( ed ) ) return 'Schedule';
		return 'Publish';
	}

	function panelInput( pid, f, value ) {
		const key = `${ pid }:${ f.name }`;
		const v = value == null ? '' : value;
		switch ( f.type ) {
			case 'textarea':
				return `<textarea class="minn-input" rows="3" data-pf="${ esc( key ) }">${ esc( String( v ) ) }</textarea>`;
			case 'number':
			case 'range':
				return `<input class="minn-input" type="number" data-pf="${ esc( key ) }" value="${ esc( String( v ) ) }"${ f.min != null ? ` min="${ esc( String( f.min ) ) }"` : '' }${ f.max != null ? ` max="${ esc( String( f.max ) ) }"` : '' }>`;
			case 'select':
			case 'radio': {
				const choices = Object.entries( f.choices || {} );
				return `<select class="minn-input" data-pf="${ esc( key ) }">
					<option value=""${ v === '' ? ' selected' : '' }>—</option>
					${ choices.map( ( [ val, label ] ) => `<option value="${ esc( val ) }"${ String( v ) === String( val ) ? ' selected' : '' }>${ esc( String( label ) ) }</option>` ).join( '' ) }
				</select>`;
			}
			case 'true_false':
				return `<button class="minn-switch${ v ? ' on' : '' }" data-pftoggle="${ esc( key ) }" role="switch" aria-checked="${ !! v }"><span class="minn-switch-knob"></span></button>`;
			default:
				return `<input class="minn-input" data-pf="${ esc( key ) }" value="${ esc( String( v ) ) }">`;
		}
	}

	function panelCard( ed, p ) {
		const pid = p.desc.id;
		const values = ed.panelValues[ pid ] || {};
		const lockedTotal = p.groups.reduce( ( n, g ) => n + ( g.locked || 0 ), 0 );
		return `
		<div class="minn-side-card">
			<div class="minn-side-title">${ esc( p.desc.label ) }${ p.desc.sub ? ` <span class="minn-panel-sub">${ esc( p.desc.sub ) }</span>` : '' }</div>
			<div class="minn-panel-fields">
				${ p.groups.map( ( g ) => `
					${ p.groups.length > 1 ? `<div class="minn-panel-group">${ esc( g.group ) }</div>` : '' }
					${ g.fields.map( ( f ) => `
						<div class="minn-panel-field${ f.type === 'true_false' ? ' inline' : '' }">
							<div class="minn-field-label">${ esc( f.label ) }</div>
							${ panelInput( pid, f, values[ f.name ] ) }
						</div>` ).join( '' ) }` ).join( '' ) }
				${ lockedTotal && ed.id ? `<div class="minn-panel-locked">${ lockedTotal } advanced field${ lockedTotal === 1 ? '' : 's' } — <a href="${ esc( B.site.adminUrl ) }post.php?post=${ ed.id }&action=edit">edit in wp-admin ↗</a></div>` : '' }
			</div>
		</div>`;
	}

	function tagChipsHtml( ed ) {
		return ( ed.tags || [] ).map( ( t ) => `<button class="minn-chip sel" data-tagchip="${ t.id }" title="Remove tag">${ esc( t.name ) } ×</button>` ).join( '' )
			|| '<span class="minn-tag-empty">No tags yet</span>';
	}

	function tagSuggestHtml() {
		const tags = state.cache.postTerms ? state.cache.postTerms.tags : [];
		return tags.map( ( t ) => `<option value="${ esc( t.name ) }"></option>` ).join( '' );
	}

	function refreshTagChips() {
		const box = $( '#minn-editor-tags' );
		if ( ! box ) return;
		box.innerHTML = tagChipsHtml( state.editor );
		$$( '[data-tagchip]', box ).forEach( ( ch ) =>
			ch.addEventListener( 'click', () => removeEditorTag( parseInt( ch.dataset.tagchip, 10 ) ) )
		);
	}

	function removeEditorTag( id ) {
		const ed = state.editor;
		if ( ! ed ) return;
		ed.tagIds.delete( id );
		ed.tags = ( ed.tags || [] ).filter( ( t ) => t.id !== id );
		ed.tagsDirty = true;
		refreshTagChips();
		if ( ed.id ) scheduleAutosave();
	}

	async function addEditorTag( name ) {
		name = ( name || '' ).replace( /,/g, '' ).trim();
		const ed = state.editor;
		if ( ! name || ! ed ) return;
		if ( ( ed.tags || [] ).some( ( t ) => t.name.toLowerCase() === name.toLowerCase() ) ) return;
		let match = ( state.cache.postTerms ? state.cache.postTerms.tags : [] ).find( ( t ) => t.name.toLowerCase() === name.toLowerCase() );
		try {
			if ( ! match ) {
				const found = await api( 'wp/v2/tags?search=' + encodeURIComponent( name ) + '&per_page=20&_fields=id,name' ).catch( () => [] );
				match = ( Array.isArray( found ) ? found : [] )
					.map( ( x ) => ( { id: x.id, name: decodeEntities( x.name ) } ) )
					.find( ( t ) => t.name.toLowerCase() === name.toLowerCase() );
				if ( ! match ) {
					const created = await api( 'wp/v2/tags', { method: 'POST', body: JSON.stringify( { name } ) } );
					match = { id: created.id, name: decodeEntities( created.name ) };
					if ( state.cache.postTerms ) state.cache.postTerms.tags.unshift( { id: match.id, name: match.name, count: 1 } );
				}
			}
			if ( ! state.editor || state.route !== 'editor' ) return;
			state.editor.tagIds.add( match.id );
			if ( ! state.editor.tags.some( ( t ) => t.id === match.id ) ) state.editor.tags.push( match );
			state.editor.tagsDirty = true;
			refreshTagChips();
			if ( state.editor.id ) scheduleAutosave();
		} catch ( e ) {
			toast( e.message, true );
		}
	}

	function renderEditorSide() {
		const ed = state.editor;
		const el = $( '#minn-editor-side' );
		if ( ! el || ! ed ) return;
		const statusLabel = STATUS_LABELS[ ed.status ] || ed.status;
		// Don't clobber an input the user is actively typing in — just refresh
		// the status and save-time rows in place.
		if ( el.contains( document.activeElement ) && document.activeElement.matches( 'input, textarea, select' ) ) {
			const statusEl = $( '#minn-status-state', el );
			if ( statusEl ) statusEl.textContent = statusLabel;
			const savedEl = $( '#minn-saved-state', el );
			if ( savedEl && ed.savedAt ) savedEl.textContent = timeAgo( new Date( ed.savedAt ).toISOString() );
			return;
		}
		const dateValue = ( ed.newDate || ( ed.date ? ed.date.slice( 0, 16 ) : '' ) );
		const cats = state.cache.categories;
		el.innerHTML = `
		<div class="minn-side-card">
			<div class="minn-side-title">Publish</div>
			<div class="minn-side-rows">
				<div class="minn-side-row"><span class="minn-side-key">Status</span><span class="minn-side-val${ ed.status === 'publish' ? ' green' : ' amber' }" style="font-weight:600;" id="minn-status-state">${ esc( statusLabel ) }</span></div>
				<div class="minn-side-row"><span class="minn-side-key">Visibility</span><span>Public</span></div>
				<div class="minn-side-row"><span class="minn-side-key">${ ed.savedAt ? 'Autosaved' : 'Saved' }</span><span class="minn-side-val green" id="minn-saved-state">${ ed.savedAt ? timeAgo( new Date( ed.savedAt ).toISOString() ) : ( ed.id ? '—' : 'Not yet' ) }</span></div>
			</div>
			<div class="minn-schedule">
				<div class="minn-side-key" style="margin-bottom:5px;">${ ed.status === 'future' ? 'Scheduled for' : 'Publish time' }</div>
				<input type="datetime-local" class="minn-input" id="minn-schedule-input" value="${ esc( dateValue ) }">
			</div>
			<button class="minn-btn-primary" id="minn-publish-btn">${ publishLabel( ed ) }</button>
			${ ed.id && ed.link ? `<a class="minn-side-viewlink" href="${ esc( ed.status === 'publish' ? ed.link : ed.link + ( ed.link.includes( '?' ) ? '&' : '?' ) + 'preview=true' ) }" target="_blank" rel="noopener">${ ed.status === 'publish' ? 'View on site ↗' : 'Preview draft ↗' }</a>` : '' }
		</div>
		${ ed.supportsThumb ? `
		<div class="minn-side-card">
			<div class="minn-side-title">Featured image</div>
			${ ed.featuredMedia && ed.featuredThumb ? `
			<div class="minn-featured-thumb" style="background-image:url('${ esc( ed.featuredThumb ) }')"></div>
			<div style="display:flex; gap:8px; margin-top:10px;">
				<button class="minn-btn-soft" id="minn-featured-set">Replace</button>
				<button class="minn-btn-soft danger" id="minn-featured-remove">Remove</button>
			</div>` : ed.featuredMedia ? '<div class="minn-session-empty">Loading…</div>' : `
			<button class="minn-featured-empty" id="minn-featured-set">${ icon( 'img' ) } Set featured image</button>` }
		</div>` : '' }
		${ ed.revisions && ed.revisions.length ? `
		<div class="minn-side-card">
			<div class="minn-side-title">History</div>
			${ ed.revisions.map( ( r ) => `
				<button class="minn-history-row" data-rev="${ r.id }">
					<span class="minn-history-when">${ timeAgo( r.modified ) }</span>
					<span class="minn-history-who">${ esc( r.author ) }</span>
				</button>` ).join( '' ) }
		</div>` : '' }
		<div class="minn-side-card">
			<div class="minn-side-title">Settings</div>
			<div style="display:flex; flex-direction:column; gap:11px; font-size: 13.5px; color:var(--text2);">
				<div>Permalink<div class="minn-permalink">${ esc( ed.slug || '—' ) }</div></div>
				${ ed.type === 'posts' ? `<div>Categories<div class="minn-chips" id="minn-editor-cats">${
					cats == null ? '<span class="minn-chip">Loading…</span>'
					: cats.map( ( c ) => `<button class="minn-chip pick${ ed.categoryIds.has( c.id ) ? ' sel' : '' }" data-cat="${ c.id }">${ esc( c.name ) }</button>` ).join( '' )
				}</div></div>` : '' }
				${ ed.type === 'posts' ? `<div>Tags
					<div class="minn-chips" id="minn-editor-tags">${ tagChipsHtml( ed ) }</div>
					<input class="minn-input minn-tag-input" id="minn-editor-tag-input" placeholder="Add a tag, press Enter" list="minn-tag-suggest" autocomplete="off">
					<datalist id="minn-tag-suggest">${ tagSuggestHtml() }</datalist>
				</div>` : '' }
				${ ed.link && ed.status === 'publish' ? `<div><a href="${ esc( ed.link ) }" target="_blank" rel="noopener">View ${ ed.type === 'pages' ? 'page' : 'post' } ↗</a></div>` : '' }
			</div>
		</div>
		${ ( ed.panels || [] ).map( ( p ) => panelCard( ed, p ) ).join( '' ) }
		${ ed.id ? '<button class="minn-trash-link" id="minn-trash-post">Move to trash</button>' : '' }`;

		const trashBtn = $( '#minn-trash-post', el );
		if ( trashBtn ) {
			trashBtn.addEventListener( 'click', async () => {
				const noun = ed.type === 'pages' ? 'page' : 'post';
				if ( ! confirm( `Move this ${ noun } to trash?` ) ) return;
				trashBtn.disabled = true;
				clearTimeout( autosaveTimer );
				try {
					await api( `wp/v2/${ ed.type }/${ ed.id }`, { method: 'DELETE' } );
					toast( `Moved to trash` );
					state.cache.content = null;
					state.editor = null;
					go( 'content' );
				} catch ( e ) {
					toast( e.message, true );
					trashBtn.disabled = false;
				}
			} );
		}

		$$( '[data-pf]', el ).forEach( ( input ) => {
			input.addEventListener( 'input', () => {
				const [ pid, name ] = input.dataset.pf.split( ':' );
				const val = input.type === 'number' ? ( input.value === '' ? null : Number( input.value ) ) : input.value;
				( state.editor.panelValues[ pid ] = state.editor.panelValues[ pid ] || {} )[ name ] = val;
				state.editor.panelDirty[ pid ] = true;
				scheduleAutosave();
			} );
		} );
		$$( '[data-pftoggle]', el ).forEach( ( btn ) =>
			btn.addEventListener( 'click', () => {
				const [ pid, name ] = btn.dataset.pftoggle.split( ':' );
				btn.classList.toggle( 'on' );
				const on = btn.classList.contains( 'on' );
				btn.setAttribute( 'aria-checked', on );
				( state.editor.panelValues[ pid ] = state.editor.panelValues[ pid ] || {} )[ name ] = on;
				state.editor.panelDirty[ pid ] = true;
				scheduleAutosave();
			} )
		);

		$$( '[data-rev]', el ).forEach( ( btn ) =>
			btn.addEventListener( 'click', () => openRevision( ed, parseInt( btn.dataset.rev, 10 ) ) )
		);

		const featSet = $( '#minn-featured-set', el );
		if ( featSet ) {
			featSet.addEventListener( 'click', () => openMediaPicker( ( it ) => {
				ed.featuredMedia = it.id;
				ed.featuredThumb = it.thumb;
				ed.featuredDirty = true;
				renderEditorSide();
				if ( ed.id ) scheduleAutosave();
			} ) );
		}
		const featRemove = $( '#minn-featured-remove', el );
		if ( featRemove ) {
			featRemove.addEventListener( 'click', () => {
				ed.featuredMedia = 0;
				ed.featuredThumb = null;
				ed.featuredDirty = true;
				renderEditorSide();
				if ( ed.id ) scheduleAutosave();
			} );
		}

		$( '#minn-schedule-input', el ).addEventListener( 'change', ( e ) => {
			state.editor.newDate = e.target.value || null;
			const btn = $( '#minn-publish-btn' );
			if ( btn ) btn.textContent = publishLabel( state.editor );
		} );

		$$( '[data-cat]', el ).forEach( ( chip ) =>
			chip.addEventListener( 'click', () => {
				const id = parseInt( chip.dataset.cat, 10 );
				if ( ed.categoryIds.has( id ) ) ed.categoryIds.delete( id );
				else ed.categoryIds.add( id );
				ed.catsDirty = true;
				chip.classList.toggle( 'sel' );
				if ( ed.id ) scheduleAutosave();
			} )
		);

		$$( '[data-tagchip]', el ).forEach( ( ch ) =>
			ch.addEventListener( 'click', () => removeEditorTag( parseInt( ch.dataset.tagchip, 10 ) ) )
		);
		const tagInput = $( '#minn-editor-tag-input', el );
		if ( tagInput ) {
			tagInput.addEventListener( 'keydown', ( e ) => {
				if ( e.key === 'Enter' || e.key === ',' ) {
					e.preventDefault();
					const val = tagInput.value;
					tagInput.value = '';
					addEditorTag( val );
				} else if ( e.key === 'Backspace' && ! tagInput.value && ed.tags && ed.tags.length ) {
					removeEditorTag( ed.tags[ ed.tags.length - 1 ].id );
				}
			} );
		}

		$( '#minn-publish-btn', el ).addEventListener( 'click', async ( e ) => {
			const btn = e.currentTarget;
			btn.disabled = true;
			clearTimeout( autosaveTimer );
			const extra = {};
			if ( ed.newDate ) {
				extra.date = ed.newDate.length === 16 ? ed.newDate + ':00' : ed.newDate;
				extra.status = scheduledInFuture( ed ) ? 'future' : 'publish';
			} else {
				extra.status = ed.status === 'future' ? 'future' : 'publish';
			}
			await saveEditor( extra );
			btn.disabled = false;
			const noun = state.editor && state.editor.type === 'pages' ? 'Page' : 'Post';
			if ( state.editor && state.editor.status === 'future' ) {
				toast( `${ noun } scheduled` );
			} else if ( state.editor && state.editor.status === 'publish' ) {
				toast( `${ noun } published` );
			}
		} );
	}

	function renderEditor() {
		const view = $( '#minn-view' );
		if ( ! state.editor || ( state.editorId && state.editor.id !== state.editorId ) || ( ! state.editorId && state.editor.id ) ) {
			state.editor = null;
			view.innerHTML = '<div class="minn-loading">Loading editor…</div>';
			loadEditor().then( renderIfCurrent( 'editor' ) ).catch( showErr );
			return;
		}
		const ed = state.editor;
		const locked = ed.mode === 'locked';
		view.innerHTML = `
		<div class="minn-editor">
			<div>
				<input class="minn-editor-title" id="minn-editor-title" placeholder="Untitled" value="${ esc( ed.title ) }">
				${ locked ? `
				<div class="minn-editor-locked-note">
					Minn couldn't safely parse this ${ ed.type === 'pages' ? 'page' : 'post' }'s block structure,
					so the body is read-only — the title can still be edited here.
					<a href="${ esc( ed.editUrl ) }">Open in block editor ↗</a>
				</div>` : `
				<div class="minn-editor-toolbar">
					<button class="minn-tool b" data-cmd="bold" title="Bold">B</button>
					<button class="minn-tool i" data-cmd="italic" title="Italic">i</button>
					<button class="minn-tool" data-block="h2" title="Heading 2">H2</button>
					<button class="minn-tool" data-block="h3" title="Heading 3">H3</button>
					<button class="minn-tool" data-block="blockquote" title="Quote">“ ”</button>
					<button class="minn-tool" data-block="pre" title="Code">{ }</button>
					<button class="minn-tool" data-cmd="link" title="Link">🔗</button>
					<button class="minn-tool" data-cmd="image" title="Insert image">🖼</button>
					<button class="minn-tool" data-block="p" title="Paragraph">¶</button>
					<select class="minn-input minn-code-lang" id="minn-code-lang" title="Code language" hidden>
						${ CODE_LANGS.map( ( l ) => `<option value="${ l }">${ l === 'auto' ? 'language: auto' : l }</option>` ).join( '' ) }
					</select>
					<span class="minn-tool-hint">type / for blocks</span>
				</div>` }
				<div class="minn-editor-body${ locked ? ' locked' : '' }" id="minn-editor-body" contenteditable="${ locked ? 'false' : 'true' }"></div>
			</div>
			<div class="minn-editor-side" id="minn-editor-side"></div>
		</div>`;

		const body = $( '#minn-editor-body', view );
		body.innerHTML = ed.content;
		highlightCodeBlocks( body );

		$( '#minn-editor-title', view ).addEventListener( 'input', scheduleAutosave );
		if ( ! locked ) {
			let hlTimer = null;
			body.addEventListener( 'input', () => {
				scheduleAutosave();
				clearTimeout( hlTimer );
				hlTimer = setTimeout( () => highlightCodeBlocks( body ), 900 );
			} );
			body.addEventListener( 'blur', () => highlightCodeBlocks( body ) );

			const insertImage = () => openMediaPicker( ( it ) => {
				const b = $( '#minn-editor-body' );
				if ( ! b ) return;
				b.focus();
				document.execCommand( 'insertHTML', false,
					`<figure class="wp-block-image"><img src="${ esc( it.url ) }" alt="${ esc( it.alt ) }"></figure><p><br></p>` );
				scheduleAutosave();
			} );

			$$( '.minn-tool', view ).forEach( ( btn ) =>
				btn.addEventListener( 'mousedown', ( e ) => {
					e.preventDefault(); // keep the selection in the editable region
					if ( btn.dataset.cmd === 'link' ) {
						const url = prompt( 'Link URL:' );
						if ( url ) document.execCommand( 'createLink', false, url );
					} else if ( btn.dataset.cmd === 'image' ) {
						insertImage();
					} else if ( btn.dataset.cmd ) {
						document.execCommand( btn.dataset.cmd, false, null );
					} else if ( btn.dataset.block ) {
						document.execCommand( 'formatBlock', false, btn.dataset.block );
					}
					scheduleAutosave();
				} )
			);

			bindSlashMenu( body, insertImage );
			bindCodeLangPicker( body );
		}

		renderEditorSide();
		if ( ! ed.id ) $( '#minn-editor-title', view ).focus();
	}

	/* ===== Code language picker (shows when the caret is in a code block) ===== */

	function bindCodeLangPicker( body ) {
		const select = $( '#minn-code-lang' );
		if ( ! select ) return;
		let currentPre = null;

		const sync = () => {
			if ( ! document.contains( body ) ) {
				document.removeEventListener( 'selectionchange', sync );
				return;
			}
			if ( document.activeElement === select ) return; // interacting with the picker itself
			const sel = window.getSelection();
			let el = sel && sel.anchorNode
				? ( sel.anchorNode.nodeType === Node.ELEMENT_NODE ? sel.anchorNode : sel.anchorNode.parentElement )
				: null;
			const pre = el && body.contains( el ) ? el.closest( 'pre' ) : null;
			currentPre = pre && ! pre.closest( '.minn-block-island' ) ? pre : null;
			select.hidden = ! currentPre;
			if ( currentPre ) select.value = codeLangOf( currentPre );
		};

		if ( window._minnLangSync ) document.removeEventListener( 'selectionchange', window._minnLangSync );
		window._minnLangSync = sync;
		document.addEventListener( 'selectionchange', sync );

		select.addEventListener( 'change', () => {
			if ( ! currentPre ) return;
			const pre = currentPre;
			let code = pre.querySelector( 'code' );
			if ( ! code ) {
				const text = codeTextOf( pre );
				pre.textContent = '';
				code = document.createElement( 'code' );
				code.textContent = text;
				pre.appendChild( code );
			}
			code.className = select.value === 'auto' ? '' : 'language-' + select.value;
			delete pre.dataset.hl;
			highlightCodeBlocks( body, true );
			// Re-highlighting rebuilds the block — put the caret back at its end.
			const range = document.createRange();
			range.selectNodeContents( pre.querySelector( 'code' ) || pre );
			range.collapse( false );
			const s = window.getSelection();
			s.removeAllRanges();
			s.addRange( range );
			scheduleAutosave();
		} );
	}

	/* ===== Slash command menu ===== */

	function bindSlashMenu( body, insertImage ) {
		let menu = null;
		let block = null;
		let selIdx = 0;
		const items = [
			[ 'H2', 'Heading 2', () => document.execCommand( 'formatBlock', false, 'h2' ) ],
			[ 'H3', 'Heading 3', () => document.execCommand( 'formatBlock', false, 'h3' ) ],
			[ '“ ”', 'Quote', () => document.execCommand( 'formatBlock', false, 'blockquote' ) ],
			[ '{ }', 'Code', () => document.execCommand( 'formatBlock', false, 'pre' ) ],
			[ '•', 'Bulleted list', () => document.execCommand( 'insertUnorderedList', false, null ) ],
			[ '1.', 'Numbered list', () => document.execCommand( 'insertOrderedList', false, null ) ],
			[ '🖼', 'Image', 'image' ],
			[ '▦', 'Table', { html: '<figure class="wp-block-table"><table class="has-fixed-layout"><tbody><tr><td>&nbsp;</td><td>&nbsp;</td></tr><tr><td>&nbsp;</td><td>&nbsp;</td></tr></tbody></table></figure>' } ],
			[ '—', 'Divider', { html: '<hr>' } ],
		];

		const close = () => {
			if ( menu ) { menu.remove(); menu = null; }
			block = null;
		};

		const highlight = () => {
			if ( ! menu ) return;
			$$( '.minn-slash-item', menu ).forEach( ( el, i ) =>
				el.classList.toggle( 'selected', i === selIdx ) );
		};

		const run = ( idx ) => {
			const item = items[ idx ];
			if ( ! item || ! block ) return close();
			const target = block;
			close();
			body.focus();
			const action = item[ 2 ];
			if ( action && action.html ) {
				// Replace the "/" block outright so the inserted markup lands at
				// the top level (never wrapped inside the block's div).
				target.insertAdjacentHTML( 'beforebegin', action.html );
				const p = document.createElement( 'p' );
				p.appendChild( document.createElement( 'br' ) );
				target.replaceWith( p );
				const range = document.createRange();
				range.selectNodeContents( p );
				range.collapse( true );
				const sel = window.getSelection();
				sel.removeAllRanges();
				sel.addRange( range );
				scheduleAutosave();
				return;
			}
			// Clear the "/" and put the caret back in the emptied block.
			target.textContent = '';
			if ( ! target.childNodes.length ) target.appendChild( document.createElement( 'br' ) );
			const range = document.createRange();
			range.selectNodeContents( target );
			range.collapse( true );
			const sel = window.getSelection();
			sel.removeAllRanges();
			sel.addRange( range );
			if ( action === 'image' ) insertImage();
			else action();
			scheduleAutosave();
		};

		const open = ( rect, blockEl ) => {
			close();
			block = blockEl;
			selIdx = 0;
			menu = document.createElement( 'div' );
			menu.className = 'minn-slash-menu';
			menu.innerHTML = items.map( ( [ ic, label ], i ) => `
				<div class="minn-slash-item${ i === 0 ? ' selected' : '' }" data-slash="${ i }">
					<span class="minn-slash-icon">${ ic }</span>${ label }
				</div>` ).join( '' );
			document.body.appendChild( menu );
			const top = Math.min( rect.bottom + 6, window.innerHeight - menu.offsetHeight - 12 );
			menu.style.top = top + 'px';
			menu.style.left = Math.min( rect.left, window.innerWidth - menu.offsetWidth - 12 ) + 'px';
			$$( '.minn-slash-item', menu ).forEach( ( el ) =>
				el.addEventListener( 'mousedown', ( e ) => { e.preventDefault(); run( parseInt( el.dataset.slash, 10 ) ); } )
			);
		};

		body.addEventListener( 'keyup', ( e ) => {
			if ( [ 'ArrowDown', 'ArrowUp', 'Enter', 'Escape' ].includes( e.key ) ) return;
			const sel = window.getSelection();
			if ( ! sel.rangeCount ) return close();
			let node = sel.anchorNode;
			if ( ! node || ! body.contains( node ) ) return close();
			while ( node.parentNode && node.parentNode !== body ) node = node.parentNode;
			const blockEl = node.nodeType === Node.ELEMENT_NODE ? node : null;
			const text = ( blockEl ? blockEl.textContent : node.textContent ) || '';
			if ( text.trim() === '/' && blockEl ) {
				open( sel.getRangeAt( 0 ).getBoundingClientRect(), blockEl );
			} else {
				close();
			}
		} );

		body.addEventListener( 'keydown', ( e ) => {
			if ( ! menu ) return;
			if ( e.key === 'ArrowDown' ) { e.preventDefault(); selIdx = ( selIdx + 1 ) % items.length; highlight(); }
			else if ( e.key === 'ArrowUp' ) { e.preventDefault(); selIdx = ( selIdx - 1 + items.length ) % items.length; highlight(); }
			else if ( e.key === 'Enter' ) { e.preventDefault(); run( selIdx ); }
			else if ( e.key === 'Escape' ) { e.stopPropagation(); close(); }
		} );

		body.addEventListener( 'blur', () => setTimeout( close, 150 ) );
	}

	/* ===== Notifications ===== */

	async function loadNotifications() {
		try {
			state.cache.notifications = ( await api( 'minn-admin/v1/notifications' ) ).items;
		} catch ( e ) {
			state.cache.notifications = [];
		}
		updateUnreadDot();
	}

	function unreadCount( kind ) {
		const items = state.cache.notifications || [];
		return items.filter( ( n ) => n.unread && ( ! kind || kind === 'all' || n.kind === kind ) ).length;
	}

	function updateUnreadDot() {
		const dot = $( '#minn-unread-dot' );
		if ( dot ) dot.hidden = unreadCount() === 0;
	}

	function toggleNotif() {
		state.notifOpen = ! state.notifOpen;
		renderOverlays();
		if ( state.notifOpen ) loadNotifications().then( () => state.notifOpen && renderOverlays() );
	}

	function renderNotifPanel() {
		const items = state.cache.notifications;
		const tabs = [
			[ 'all', 'All' ], [ 'comments', 'Comments' ], [ 'updates', 'Updates' ], [ 'system', 'System' ],
		];
		const visible = ( items || [] ).filter( ( n ) => state.notifTab === 'all' || n.kind === state.notifTab );
		const groups = [];
		visible.forEach( ( n ) => {
			let g = groups.find( ( x ) => x.label === n.group );
			if ( ! g ) { g = { label: n.group, items: [] }; groups.push( g ); }
			g.items.push( n );
		} );

		return `
		<div class="minn-overlay" id="minn-notif-overlay">
			<div class="minn-notif-panel">
				<div class="minn-notif-head">
					<div class="minn-notif-title">Notifications</div>
					<button class="minn-link-btn" id="minn-mark-read">Mark all read</button>
					<button class="minn-x-btn" id="minn-notif-close">×</button>
				</div>
				<div class="minn-notif-tabs">
					${ tabs.map( ( [ id, label ] ) => {
						const c = unreadCount( id );
						return `<button class="minn-notif-tab${ state.notifTab === id ? ' active' : '' }" data-tab="${ id }">${ label }${ c ? `<span class="minn-notif-tab-count">${ c }</span>` : '' }</button>`;
					} ).join( '' ) }
				</div>
				<div class="minn-notif-scroll">
					${ items == null ? '<div class="minn-loading">Loading…</div>' : ! visible.length ? '<div class="minn-empty">You’re all caught up.</div>' : groups.map( ( g ) => `
						<div>
							<div class="minn-notif-group-label">${ esc( g.label ) }</div>
							${ g.items.map( ( n ) => `
								<div class="minn-notif-row${ n.unread ? ' unread' : '' }" data-nid="${ esc( n.id ) }">
									<div class="minn-notif-icon">${ esc( n.icon ) }</div>
									<div class="minn-notif-text">
										${ esc( n.title ) }
										<div class="minn-notif-time">${ esc( n.ago ) }</div>
									</div>
									${ n.unread ? '<div class="minn-notif-unread-dot"></div>' : '' }
								</div>` ).join( '' ) }
						</div>` ).join( '' ) }
				</div>
			</div>
		</div>`;
	}

	/* ===== Command palette ===== */

	function paletteCommands() {
		const cmds = [
			{ label: 'Go to Overview', kind: 'nav', icon: '▦', run: () => go( 'overview' ) },
			{ label: 'Manage Content', kind: 'nav', icon: '¶', run: () => go( 'content' ) },
			{ label: 'Open Media Library', kind: 'nav', icon: '▣', run: () => go( 'media' ) },
		];
		if ( B.caps.moderate ) cmds.push( { label: 'Review Comments', kind: 'nav', icon: '💬', run: () => go( 'comments' ) } );
		if ( B.wc && B.caps.orders ) cmds.push( { label: 'View Orders', kind: 'nav', icon: '⬡', run: () => go( 'orders' ) } );
		if ( B.caps.users ) cmds.push( { label: 'Browse Users', kind: 'nav', icon: '◉', run: () => go( 'users' ) } );
		( B.surfaces || [] ).forEach( ( s ) =>
			cmds.push( { label: 'Open ' + s.label + ( s.sub ? ' (' + s.sub + ')' : '' ), kind: 'nav', icon: '❖', run: () => go( s.id ) } )
		);
		if ( B.caps.plugins ) cmds.push( { label: 'Manage Extensions', kind: 'nav', icon: '✦', run: () => go( 'extensions' ) } );
		if ( B.caps.settings ) cmds.push( { label: 'Open Settings', kind: 'nav', icon: '⚙', run: () => go( 'settings' ) } );
		cmds.push(
			{ label: 'Write new post', kind: 'action', icon: '✎', run: () => { state.editorId = null; state.editorType = 'posts'; state.editor = null; go( 'editor' ); } },
			{ label: 'Toggle dark / light theme', kind: 'action', icon: '◐', run: toggleTheme },
			{ label: 'View notifications', kind: 'action', icon: '◔', run: () => { state.notifOpen = true; renderOverlays(); loadNotifications().then( () => state.notifOpen && renderOverlays() ); } },
		);
		if ( B.caps.update && Object.keys( state.cache.pluginUpdates ).length ) {
			cmds.push( { label: 'Update all plugins', kind: 'action', icon: '⟳', run: () => updateAllPlugins( null ) } );
		}
		cmds.push(
			{ label: 'About Minn — philosophy & help', kind: 'link', icon: '?', run: () => { state.modal = { type: 'help' }; renderOverlays(); } },
			{ label: 'Visit site', kind: 'link', icon: '↗', run: () => window.open( B.site.url, '_blank' ) },
			{ label: 'Classic wp-admin', kind: 'link', icon: 'W', run: () => window.open( B.site.adminUrl, '_blank' ) },
			{ label: 'Log out', kind: 'link', icon: '⎋', run: () => { window.location.href = B.site.logout; } },
		);
		return cmds;
	}

	function openPalette() {
		state.paletteOpen = true;
		state.paletteSel = 0;
		renderOverlays();
	}

	function renderPalette() {
		return `
		<div class="minn-palette-overlay" id="minn-palette-overlay">
			<div class="minn-palette">
				<div class="minn-palette-head">
					${ icon( 'search' ) }
					<input class="minn-palette-input" id="minn-palette-input" placeholder="Search or run a command…" autocomplete="off">
					<span class="minn-kbd">esc</span>
				</div>
				<div class="minn-palette-list" id="minn-palette-list"></div>
			</div>
		</div>`;
	}

	function renderPaletteList( query ) {
		const list = $( '#minn-palette-list' );
		if ( ! list ) return;
		const q = ( query || '' ).trim().toLowerCase();
		const filtered = paletteCommands().filter( ( c ) => ! q || c.label.toLowerCase().includes( q ) );
		state.paletteFiltered = filtered;
		if ( state.paletteSel >= filtered.length ) state.paletteSel = 0;
		list.innerHTML = filtered.length ? filtered.map( ( c, i ) => `
			<div class="minn-palette-item${ i === state.paletteSel ? ' selected' : '' }" data-idx="${ i }">
				<div class="minn-palette-icon">${ esc( c.icon ) }</div>
				<div class="minn-palette-label">${ esc( c.label ) }</div>
				<div class="minn-palette-kind">${ esc( c.kind ) }</div>
			</div>` ).join( '' )
			: `<div class="minn-palette-empty">No results for “${ esc( query ) }”</div>`;

		$$( '.minn-palette-item', list ).forEach( ( el ) =>
			el.addEventListener( 'click', () => runPaletteItem( parseInt( el.dataset.idx, 10 ) ) )
		);
	}

	function runPaletteItem( idx ) {
		const cmd = ( state.paletteFiltered || [] )[ idx ];
		state.paletteOpen = false;
		renderOverlays();
		if ( cmd ) cmd.run();
	}

	/* ===== Modals (media preview · order detail · media picker) ===== */

	function closeModal() {
		state.modal = null;
		renderOverlays();
	}

	// Position of the open media item within the loaded library, for prev/next.
	function mediaModalContext() {
		const m = state.modal;
		if ( ! m || m.type !== 'media' || ! state.cache.media ) return null;
		const items = state.cache.media.items.map( mapMediaItem );
		const idx = items.findIndex( ( x ) => x.id === m.item.id );
		return idx === -1 ? null : { items, idx };
	}

	function mediaModalNav( dir ) {
		const ctx = mediaModalContext();
		if ( ! ctx ) return;
		const next = ctx.idx + dir;
		if ( next < 0 || next >= ctx.items.length ) return;
		state.modal = { type: 'media', item: ctx.items[ next ] };
		renderOverlays();
	}

	function renderModal() {
		const m = state.modal;
		if ( ! m ) return '';

		if ( m.type === 'media' ) {
			const it = m.item;
			const ctx = mediaModalContext();
			const preview = it.kind === 'IMG' || it.kind === 'SVG'
				? `<img src="${ esc( it.url ) }" alt="${ esc( it.alt ) }">`
				: it.kind === 'VID' ? `<video src="${ esc( it.url ) }" controls></video>`
				: it.kind === 'AUD' ? `<audio src="${ esc( it.url ) }" controls></audio>`
				: `<div class="minn-modal-filecard" style="background:${ it.grad }">${ it.kind }</div>`;
			const rows = [
				[ 'Type', it.mime || it.kind ],
				[ 'Dimensions', it.dims ],
				[ 'Size', it.size ],
				[ 'Uploaded', it.date ? timeAgo( it.date ) : '—' ],
			];
			const canEdit = it.kind === 'IMG' || it.kind === 'SVG';
			return `
			<div class="minn-modal-overlay" id="minn-modal-overlay">
				<div class="minn-modal media${ canEdit ? ' wide' : '' }">
					<div class="minn-modal-head">
						<div class="minn-modal-title">${ esc( it.name ) }</div>
						${ ctx ? `<span class="minn-modal-count">${ ctx.idx + 1 } / ${ ctx.items.length }</span>` : '' }
						<button class="minn-x-btn" id="minn-modal-close">×</button>
					</div>
					<div class="minn-modal-preview media-lg">
						${ preview }
						${ ctx && ctx.idx > 0 ? '<button class="minn-modal-nav prev" id="minn-media-prev" title="Previous (←)">‹</button>' : '' }
						${ ctx && ctx.idx < ctx.items.length - 1 ? '<button class="minn-modal-nav next" id="minn-media-next" title="Next (→)">›</button>' : '' }
					</div>
					<div class="minn-modal-meta">
						${ rows.map( ( [ k, v ] ) => `<div class="minn-side-row"><span class="minn-side-key">${ k }</span><span>${ esc( v ) }</span></div>` ).join( '' ) }
						<div class="minn-modal-url"><span class="minn-permalink">${ esc( it.url ) }</span></div>
						${ canEdit ? `
						<div class="minn-media-edit">
							<div class="minn-field-label">Title</div>
							<input class="minn-input" id="minn-media-title" value="${ esc( it.name ) }">
							<div class="minn-field-label" style="margin-top:10px;">Alt text</div>
							<input class="minn-input" id="minn-media-alt" placeholder="Describe this image…" value="${ esc( it.alt || '' ) }">
						</div>` : '' }
					</div>
					<div class="minn-modal-actions">
						${ canEdit ? `<button class="minn-btn-primary" id="minn-media-save">Save</button>` : '' }
						<button class="minn-btn-soft" id="minn-media-copy">${ icon( 'copy' ) } Copy URL</button>
						<button class="minn-btn-soft" id="minn-media-open">↗ Open</button>
						<button class="minn-btn-soft danger" id="minn-media-delete">${ icon( 'trash' ) } Delete</button>
					</div>
				</div>
			</div>`;
		}

		if ( m.type === 'order' ) {
			const o = m.order;
			const b = o.billing || {};
			const sym = o.currency_symbol || '$';
			return `
			<div class="minn-modal-overlay" id="minn-modal-overlay">
				<div class="minn-modal">
					<div class="minn-modal-head">
						<div class="minn-modal-title">Order #${ esc( o.number ) }</div>
						<span class="minn-status ${ ORDER_STATUS_STYLE[ o.status ] || 'draft' }">${ esc( o.status.replace( '-', ' ' ) ) }</span>
						<button class="minn-x-btn" id="minn-modal-close">×</button>
					</div>
					<div class="minn-modal-meta">
						<div class="minn-side-row"><span class="minn-side-key">Customer</span><span>${ esc( customerName( o ) ) }</span></div>
						${ b.email ? `<div class="minn-side-row"><span class="minn-side-key">Email</span><span>${ esc( b.email ) }</span></div>` : '' }
						<div class="minn-side-row"><span class="minn-side-key">Placed</span><span>${ timeAgo( o.date_created ) }</span></div>
					</div>
					<div class="minn-order-items">
						${ ( o.line_items || [] ).map( ( li ) => `
							<div class="minn-order-item">
								<span class="minn-order-qty">${ li.quantity }×</span>
								<span class="minn-cell-clip">${ esc( li.name ) }</span>
								<span class="minn-order-line-total">${ esc( sym + li.total ) }</span>
							</div>` ).join( '' ) }
						<div class="minn-order-item total">
							<span></span><span>Total</span>
							<span class="minn-order-line-total">${ esc( sym + o.total ) }</span>
						</div>
					</div>
					${ B.caps.orders ? `
					<div class="minn-media-edit">
						<div class="minn-field-label">Status</div>
						<div style="display:flex; gap:8px;">
							<select class="minn-input" id="minn-order-status">
								${ Object.keys( ORDER_STATUS_STYLE ).map( ( st ) => `<option value="${ st }"${ st === o.status ? ' selected' : '' }>${ esc( st.replace( '-', ' ' ) ) }</option>` ).join( '' ) }
							</select>
							<button class="minn-btn-primary" id="minn-order-save" style="flex-shrink:0;">Save</button>
						</div>
					</div>` : '' }
					<div class="minn-modal-actions">
						<a class="minn-btn-soft" href="${ esc( B.site.adminUrl ) }edit.php?post_type=shop_order" target="_blank" rel="noopener">↗ Manage in WooCommerce</a>
					</div>
				</div>
			</div>`;
		}

		if ( m.type === 'surface' ) {
			const s = m.surface;
			const detail = s.collection.detail || {};
			const it = m.item;
			const skip = new Set( [ 'id', ...( detail.skip || [] ) ] );
			const labels = m.labels || {};
			const rows = m.loading ? [] : Object.keys( it )
				.filter( ( k ) => ! skip.has( k ) && k !== detail.messageKey && ! k.startsWith( '_' ) )
				.map( ( k ) => [ labels[ k ] || k.replace( /_/g, ' ' ), it[ k ] ] )
				.filter( ( [ , v ] ) => v != null && v !== '' && typeof v !== 'object' )
				.slice( 0, 24 );
			const message = ! m.loading && detail.messageKey ? it[ detail.messageKey ] : null;
			const isHtml = message != null && /<\/?[a-z][\s\S]*>/i.test( String( message ) );
			return `
			<div class="minn-modal-overlay" id="minn-modal-overlay">
				<div class="minn-modal${ message ? ' wide' : '' }">
					<div class="minn-modal-head">
						<div class="minn-modal-title">${ esc( s.label ) } #${ esc( String( it.id ) ) }</div>
						${ it.status ? surfacePill( it.status ) : '' }
						<button class="minn-x-btn" id="minn-modal-close">×</button>
					</div>
					${ m.loading ? '<div class="minn-loading">Loading…</div>' : `
					<div class="minn-modal-meta">
						${ rows.map( ( [ k, v ] ) => `<div class="minn-side-row"><span class="minn-side-key">${ esc( k ) }</span><span class="minn-surface-val">${ esc( stripTags( String( v ) ) ) }</span></div>` ).join( '' ) }
					</div>
					${ message ? ( isHtml
						? `<iframe class="minn-email-frame" id="minn-email-frame" sandbox="" title="Email preview" srcdoc="${ esc( String( message ) ) }"></iframe>`
						: `<pre class="minn-surface-message">${ esc( stripTags( String( message ) ) ) }</pre>` ) : '' }
					${ ( message || ( s.collection.actions || [] ).length ) ? `
					<div class="minn-modal-actions">
						${ message ? `<button class="minn-btn-soft" id="minn-surface-raw">↗ Open raw</button>` : '' }
						${ ( s.collection.actions || [] ).map( ( a, i ) => `<button class="minn-btn-soft${ a.danger ? ' danger' : '' }" data-saction="${ i }">${ esc( a.label ) }</button>` ).join( '' ) }
					</div>` : '' }` }
				</div>
			</div>`;
		}

		if ( m.type === 'user' ) {
			const u = m.user;
			const isNew = ! m.userId;
			const roles = Object.entries( B.roles || {} );
			if ( ! isNew && ! u ) {
				return `<div class="minn-modal-overlay" id="minn-modal-overlay"><div class="minn-modal"><div class="minn-modal-head"><div class="minn-modal-title">Edit user</div><button class="minn-x-btn" id="minn-modal-close">×</button></div><div class="minn-loading">Loading…</div></div></div>`;
			}
			const role = u && u.roles && u.roles[ 0 ] ? u.roles[ 0 ] : 'subscriber';
			return `
			<div class="minn-modal-overlay" id="minn-modal-overlay">
				<div class="minn-modal">
					<div class="minn-modal-head">
						<div class="minn-modal-title">${ isNew ? 'Add user' : 'Edit ' + esc( u.name ) }</div>
						<button class="minn-x-btn" id="minn-modal-close">×</button>
					</div>
					<div class="minn-modal-form">
						${ isNew ? `
						<div>
							<div class="minn-field-label">Username</div>
							<input class="minn-input mono" id="minn-uf-username" autocomplete="off">
						</div>` : '' }
						<div>
							<div class="minn-field-label">Display name</div>
							<input class="minn-input" id="minn-uf-name" value="${ esc( u ? u.name : '' ) }">
						</div>
						<div>
							<div class="minn-field-label">Email</div>
							<input class="minn-input mono" id="minn-uf-email" value="${ esc( u ? u.email : '' ) }">
						</div>
						<div>
							<div class="minn-field-label">Role</div>
							<select class="minn-input" id="minn-uf-role" ${ B.caps.promoteUsers ? '' : 'disabled' }>
								${ roles.map( ( [ slug, label ] ) => `<option value="${ esc( slug ) }"${ slug === role ? ' selected' : '' }>${ esc( label ) }</option>` ).join( '' ) }
							</select>
						</div>
						<div>
							<div class="minn-field-label">${ isNew ? 'Password' : 'New password (leave blank to keep)' }</div>
							<div style="display:flex; gap:8px;">
								<input class="minn-input mono" id="minn-uf-password" autocomplete="new-password">
								<button class="minn-btn-soft" id="minn-uf-genpass" style="flex-shrink:0;">Generate</button>
							</div>
						</div>
					</div>
					${ ! isNew && m.userId === B.user.id ? `
					<div class="minn-sessions">
						<div class="minn-side-title" style="margin:0 0 4px;">AI Access <span class="minn-panel-sub">application passwords</span></div>
						<div class="minn-toggle-desc" style="margin-bottom:8px;">Give an AI agent its own revocable credential instead of your login. It authenticates against the REST API with HTTP Basic auth.</div>
						${ m.newAppPassword ? `
						<div class="minn-app-reveal">
							<div class="minn-field-label">“${ esc( m.newAppPassword.name ) }” created — copy it now, it won't be shown again</div>
							<code id="minn-app-secret">${ esc( m.newAppPassword.password ) }</code>
							<div style="display:flex; gap:8px; margin-top:9px; flex-wrap:wrap;">
								<button class="minn-btn-soft" id="minn-app-copy">${ icon( 'copy' ) } Copy password</button>
								<button class="minn-btn-soft" id="minn-app-copy-curl">${ icon( 'copy' ) } Copy curl example</button>
							</div>
						</div>` : '' }
						${ m.appPasswords == null ? '<div class="minn-session-empty">Loading…</div>'
							: ! m.appPasswords.length ? '<div class="minn-session-empty">No application passwords yet.</div>'
							: m.appPasswords.map( ( ap ) => `
							<div class="minn-session-row">
								<div class="minn-session-info">
									<div class="minn-session-ua">${ esc( ap.name ) }</div>
									<div class="minn-session-meta">created ${ ap.created ? timeAgo( ap.created ) : '—' } · ${ ap.last_used ? 'last used ' + timeAgo( ap.last_used ) : 'never used' }</div>
								</div>
								<button class="minn-comment-action danger" data-appdel="${ esc( ap.uuid ) }">Revoke</button>
							</div>` ).join( '' ) }
						<div style="display:flex; gap:8px; margin-top:10px;">
							<input class="minn-input" id="minn-app-name" placeholder="AI Agent" style="font-size:13px;">
							<button class="minn-btn-soft" id="minn-app-create" style="flex-shrink:0;">${ icon( 'plus' ) } New password</button>
						</div>
						<div style="display:flex; gap:8px; margin-top:10px; flex-wrap:wrap;">
							<button class="minn-btn-soft" id="minn-guide-copy">${ icon( 'copy' ) } Copy agent guide</button>
							<button class="minn-btn-soft" id="minn-guide-download">↓ Download agent-guide.md</button>
						</div>
					</div>` : '' }
					${ ! isNew ? `
					<div class="minn-sessions" id="minn-uf-sessions">
						<div class="minn-side-title" style="margin:0 0 4px;">Sessions</div>
						${ m.sessions == null ? '<div class="minn-loading" style="padding:14px;">Loading sessions…</div>'
							: ! m.sessions.length ? '<div class="minn-session-empty">No active sessions.</div>'
							: m.sessions.map( ( sess ) => `
							<div class="minn-session-row">
								<div class="minn-session-info">
									<div class="minn-session-ua">${ esc( uaSummary( sess.ua ) ) }${ sess.current ? ' <span class="minn-session-current">this session</span>' : '' }</div>
									<div class="minn-session-meta">${ esc( sess.ip || '—' ) } · signed in ${ sess.login ? timeAgo( new Date( sess.login * 1000 ).toISOString() ) : '—' }</div>
								</div>
								<button class="minn-comment-action danger" data-kill="${ esc( sess.verifier ) }">Sign out</button>
							</div>` ).join( '' ) }
						${ m.sessions && m.sessions.length ? '<button class="minn-comment-action danger" id="minn-uf-killall" style="margin:10px 0 0;">Sign out everywhere</button>' : '' }
					</div>` : '' }
					<div class="minn-modal-actions">
						<button class="minn-btn-primary" id="minn-uf-save">${ isNew ? 'Create user' : 'Save changes' }</button>
						${ ! isNew && B.caps.deleteUsers && u && u.id !== B.user.id ? '<button class="minn-btn-soft danger" id="minn-uf-delete">Delete user</button>' : '' }
					</div>
				</div>
			</div>`;
		}

		if ( m.type === 'revision' ) {
			return renderRevisionModal( m );
		}

		if ( m.type === 'help' ) {
			return `
			<div class="minn-modal-overlay" id="minn-modal-overlay">
				<div class="minn-modal">
					<div class="minn-modal-head">
						<div class="minn-modal-title">About Minn</div>
						<span class="minn-panel-sub">v${ esc( B.version ) }</span>
						<button class="minn-x-btn" id="minn-modal-close">×</button>
					</div>
					<div class="minn-help-body">
						<p><b>Minn is a reimagined WordPress admin.</b> It is a calm, fast surface for the
						work you actually do every day: writing, moderating, uploading, and keeping an eye
						on the site. The classic wp-admin stays fully available. Minn is additive, never a cage.</p>

						<h4>Get out of the way</h4>
						<p>No boxes within boxes, no meta panels fighting for attention. The daily loop is one
						click away and visually quiet. Press <span class="minn-kbd">⌘K</span> anywhere.</p>

						<h4>Configuration belongs to your AI agent</h4>
						<p>Minn deliberately doesn't rebuild every settings screen. Need to configure ACF,
						Gravity Forms, or an SEO plugin? Open <b>your account → AI Access</b>, generate an
						application password, and hand your agent the generated guide. The agent does the
						fiddly work over the REST API using its own revocable credential, never your login,
						while Minn stays minimal.</p>

						<h4>Nothing is ever locked in</h4>
						<p>Everything Minn writes is native WordPress: real Gutenberg block markup, core
						options, core REST calls. Complex block layouts are preserved byte for byte as
						read-only islands while you edit the text around them. Deactivate the plugin and
						nothing is lost.</p>

						<h4>Extensible by description, not code</h4>
						<p>Plugins add views and editor panels with a single PHP filter. No JavaScript, no
						build step. Gravity Forms, Gravity SMTP and ACF adapters ship built in.</p>
					</div>
					<div class="minn-modal-actions">
						<a class="minn-btn-soft" href="https://github.com/austinginder/minn-admin" target="_blank" rel="noopener">↗ GitHub</a>
						<a class="minn-btn-soft" href="https://github.com/austinginder/minn-admin/blob/main/docs/goals.md" target="_blank" rel="noopener">↗ Project goals</a>
						<a class="minn-btn-soft" href="https://github.com/austinginder/minn-admin/blob/main/docs/for-plugin-authors.md" target="_blank" rel="noopener">↗ For plugin authors</a>
					</div>
				</div>
			</div>`;
		}

		if ( m.type === 'plugin-install' ) {
			const installedNow = ( slug ) => ( state.cache.plugins || [] ).find( ( p ) => p.plugin.split( '/' )[ 0 ] === slug );
			return `
			<div class="minn-modal-overlay" id="minn-modal-overlay">
				<div class="minn-modal wide">
					<div class="minn-modal-head">
						<div class="minn-modal-title">Add plugin</div>
						<button class="minn-x-btn" id="minn-modal-close">×</button>
					</div>
					<div class="minn-pi-body">
						<div class="minn-dropzone compact" id="minn-pi-dropzone">
							${ icon( 'upload' ) }
							<div class="minn-dropzone-sub">Drop a plugin <b>.zip</b> here or <b>browse</b></div>
							<input type="file" id="minn-pi-file" accept=".zip" hidden>
						</div>
						<input class="minn-input" id="minn-pi-search" placeholder="Search the WordPress.org directory…" value="${ esc( m.q ) }" autocomplete="off">
						<div class="minn-pi-results">
							${ m.searching ? '<div class="minn-loading">Searching…</div>'
							: m.results == null ? '<div class="minn-empty" style="padding:20px;">Search for a plugin, or drop a zip above.</div>'
							: ! m.results.length ? `<div class="minn-empty" style="padding:20px;">No results for “${ esc( m.q ) }”.</div>`
							: m.results.map( ( p, i ) => {
								const local = installedNow( p.slug );
								const stateLabel = local && local.status === 'active' ? 'Active'
									: ( local || p.installed ) ? 'Activate' : 'Install';
								return `
								<div class="minn-pi-row">
									${ p.icon ? `<img class="minn-pi-icon" src="${ esc( p.icon ) }" alt="">` : '<div class="minn-pi-icon"></div>' }
									<div class="minn-pi-info">
										<div class="minn-row-title" title="${ esc( p.name ) }">${ esc( cleanPluginName( p.name ) ) }</div>
										<div class="minn-pi-meta">${ p.installs ? Number( p.installs ).toLocaleString() + '+ installs · ' : '' }v${ esc( p.version ) }</div>
										<div class="minn-pi-desc">${ esc( p.description ) }</div>
									</div>
									<button class="minn-btn-soft" data-pi="${ i }" ${ stateLabel === 'Active' ? 'disabled' : '' }>${ stateLabel }</button>
								</div>`;
							} ).join( '' ) }
						</div>
					</div>
				</div>
			</div>`;
		}

		if ( m.type === 'theme-install' ) {
			return `
			<div class="minn-modal-overlay" id="minn-modal-overlay">
				<div class="minn-modal wide">
					<div class="minn-modal-head">
						<div class="minn-modal-title">Add theme</div>
						<button class="minn-x-btn" id="minn-modal-close">×</button>
					</div>
					<div class="minn-pi-body">
						<div class="minn-dropzone compact" id="minn-ti-dropzone">
							${ icon( 'upload' ) }
							<div class="minn-dropzone-sub">Drop a theme <b>.zip</b> here or <b>browse</b></div>
							<input type="file" id="minn-ti-file" accept=".zip" hidden>
						</div>
						<input class="minn-input" id="minn-ti-search" placeholder="Search the WordPress.org theme directory…" value="${ esc( m.q ) }" autocomplete="off">
						<div class="minn-pi-results">
							${ m.searching ? '<div class="minn-loading">Searching…</div>'
							: m.results == null ? '<div class="minn-empty" style="padding:20px;">Search for a theme, or drop a zip above.</div>'
							: ! m.results.length ? `<div class="minn-empty" style="padding:20px;">No results for “${ esc( m.q ) }”.</div>`
							: `<div class="minn-ti-grid">
								${ m.results.map( ( t, i ) => `
								<div class="minn-ti-card">
									<div class="minn-theme-shot"${ t.screenshot ? ` style="background-image:url('${ esc( t.screenshot ) }')"` : '' }></div>
									<div class="minn-ti-info">
										<div class="minn-row-title">${ esc( t.name ) }</div>
										<div class="minn-pi-meta">${ t.installs ? Number( t.installs ).toLocaleString() + '+ installs · ' : '' }v${ esc( t.version ) }</div>
										<button class="minn-btn-soft" data-ti="${ i }" ${ t.active ? 'disabled' : '' }>${ t.active ? 'Active' : t.installed ? 'Activate' : 'Install' }</button>
									</div>
								</div>` ).join( '' ) }
							</div>` }
						</div>
					</div>
				</div>
			</div>`;
		}

		if ( m.type === 'picker' ) {
			const items = m.items;
			return `
			<div class="minn-modal-overlay" id="minn-modal-overlay">
				<div class="minn-modal wide">
					<div class="minn-modal-head">
						<div class="minn-modal-title">Insert image</div>
						<button class="minn-x-btn" id="minn-modal-close">×</button>
					</div>
					${ items == null ? '<div class="minn-loading">Loading images…</div>' : ! items.length ? '<div class="minn-empty">No images in the library yet.</div>' : `
					<div class="minn-picker-grid">
						${ items.map( ( it, i ) => `
							<div class="minn-picker-item" data-pick="${ i }" style="background-image:url('${ esc( it.thumb ) }')" title="${ esc( it.name ) }"></div>` ).join( '' ) }
					</div>` }
				</div>
			</div>`;
		}
		return '';
	}

	function bindModal() {
		const m = state.modal;
		if ( ! m ) return;
		$( '#minn-modal-overlay' ).addEventListener( 'click', ( e ) => {
			if ( e.target.id === 'minn-modal-overlay' ) closeModal();
		} );
		$( '#minn-modal-close' ).addEventListener( 'click', closeModal );

		if ( m.type === 'media' ) {
			const it = m.item;
			const prev = $( '#minn-media-prev' );
			const next = $( '#minn-media-next' );
			if ( prev ) prev.addEventListener( 'click', () => mediaModalNav( -1 ) );
			if ( next ) next.addEventListener( 'click', () => mediaModalNav( 1 ) );
			$( '#minn-media-copy' ).addEventListener( 'click', async () => {
				try {
					await navigator.clipboard.writeText( it.url );
					toast( 'URL copied' );
				} catch ( e ) {
					toast( 'Could not copy', true );
				}
			} );
			$( '#minn-media-open' ).addEventListener( 'click', () => window.open( it.url, '_blank' ) );
			const saveBtn = $( '#minn-media-save' );
			if ( saveBtn ) saveBtn.addEventListener( 'click', async () => {
				const title = $( '#minn-media-title' ).value.trim();
				const alt = $( '#minn-media-alt' ).value;
				saveBtn.disabled = true;
				saveBtn.textContent = 'Saving…';
				try {
					await api( `wp/v2/media/${ it.id }`, { method: 'POST', body: JSON.stringify( { title, alt_text: alt } ) } );
					it.name = title || it.name;
					it.alt = alt;
					// Reflect the change in the cached list without a full refetch.
					const cached = state.cache.media && state.cache.media.items.find( ( x ) => x.id === it.id );
					if ( cached ) { cached.title = { rendered: title }; cached.alt_text = alt; }
					toast( 'Saved' );
					if ( state.route === 'media' ) renderMedia();
					renderOverlays();
				} catch ( e ) {
					toast( e.message, true );
					saveBtn.disabled = false;
					saveBtn.textContent = 'Save';
				}
			} );
			$( '#minn-media-delete' ).addEventListener( 'click', async () => {
				if ( ! confirm( `Delete “${ it.name }” permanently?` ) ) return;
				try {
					await api( `wp/v2/media/${ it.id }?force=true`, { method: 'DELETE' } );
					toast( 'File deleted' );
					closeModal();
					state.cache.media = null;
					if ( state.route === 'media' ) renderMedia();
				} catch ( e ) {
					toast( e.message, true );
				}
			} );
		}

		if ( m.type === 'order' ) {
			const saveBtn = $( '#minn-order-save' );
			if ( saveBtn ) saveBtn.addEventListener( 'click', async () => {
				const status = $( '#minn-order-status' ).value;
				if ( status === m.order.status ) { closeModal(); return; }
				saveBtn.disabled = true;
				saveBtn.textContent = 'Saving…';
				try {
					const updated = await api( `wc/v3/orders/${ m.order.id }`, { method: 'PUT', body: JSON.stringify( { status } ) } );
					m.order.status = updated.status || status;
					toast( 'Order updated' );
					state.cache.orders = null;
					if ( state.route === 'orders' ) renderOrders();
					renderOverlays();
				} catch ( e ) {
					toast( e.message, true );
					saveBtn.disabled = false;
					saveBtn.textContent = 'Save';
				}
			} );
		}

		if ( m.type === 'picker' ) {
			$$( '[data-pick]' ).forEach( ( el ) =>
				el.addEventListener( 'click', () => {
					const it = m.items[ parseInt( el.dataset.pick, 10 ) ];
					const cb = m.callback;
					closeModal();
					if ( it && cb ) cb( it );
				} )
			);
		}

		if ( m.type === 'surface' ) {
			const rawBtn = $( '#minn-surface-raw' );
			if ( rawBtn ) rawBtn.addEventListener( 'click', () => {
				const detail = m.surface.collection.detail || {};
				const msg = detail.messageKey ? m.item[ detail.messageKey ] : null;
				if ( msg == null ) return;
				const blob = new Blob( [ String( msg ) ], { type: 'text/html' } );
				window.open( URL.createObjectURL( blob ), '_blank' );
			} );
			$$( '[data-saction]' ).forEach( ( btn ) =>
				btn.addEventListener( 'click', async () => {
					const action = m.surface.collection.actions[ parseInt( btn.dataset.saction, 10 ) ];
					if ( ! action ) return;
					if ( action.confirm && ! confirm( action.confirm ) ) return;
					btn.disabled = true;
					try {
						await api( action.route.replace( '{id}', m.item.id ), {
							method: action.method || 'POST',
							...( action.body ? { body: JSON.stringify( action.body ) } : {} ),
						} );
						toast( action.label + ' — done' );
						surfaceState( m.surface.id ).cache = null;
						closeModal();
						if ( state.route === m.surface.id ) renderSurface( m.surface );
					} catch ( e ) {
						toast( e.message, true );
						btn.disabled = false;
					}
				} )
			);
		}

		if ( m.type === 'user' ) {
			bindUserModal( m );
		}

		if ( m.type === 'revision' ) {
			bindRevisionModal( m );
		}

		if ( m.type === 'plugin-install' ) {
			bindPluginInstallModal( m );
		}

		if ( m.type === 'theme-install' ) {
			bindThemeInstallModal( m );
		}
	}

	/* ===== Theme install modal (wp.org search + zip upload) ===== */

	let tiSearchTimer = null;

	function bindThemeInstallModal( m ) {
		const input = $( '#minn-ti-search' );
		input.focus();
		input.setSelectionRange( input.value.length, input.value.length );
		input.addEventListener( 'input', () => {
			m.q = input.value.trim();
			clearTimeout( tiSearchTimer );
			if ( ! m.q ) return;
			tiSearchTimer = setTimeout( async () => {
				m.searching = true;
				renderOverlays();
				try {
					const r = await api( 'minn-admin/v1/themes/search?q=' + encodeURIComponent( m.q ) );
					if ( state.modal !== m ) return;
					m.results = r.themes;
				} catch ( e ) {
					toast( e.message, true );
					m.results = [];
				}
				m.searching = false;
				renderOverlays();
			}, 400 );
		} );

		$$( '[data-ti]' ).forEach( ( btn ) =>
			btn.addEventListener( 'click', async () => {
				const t = m.results[ parseInt( btn.dataset.ti, 10 ) ];
				if ( ! t ) return;
				const activating = btn.textContent.trim() === 'Activate';
				if ( activating && ! confirm( `Switch the site's theme to “${ t.name }”? This changes how the whole site looks.` ) ) return;
				btn.disabled = true;
				btn.textContent = activating ? 'Activating…' : 'Installing…';
				try {
					if ( activating ) {
						await api( 'minn-admin/v1/themes/activate', { method: 'POST', body: JSON.stringify( { stylesheet: t.slug } ) } );
						toast( `${ t.name } is now the active theme` );
						t.active = true;
					} else {
						await api( 'minn-admin/v1/themes/install', { method: 'POST', body: JSON.stringify( { slug: t.slug } ) } );
						toast( `${ t.name } installed` );
						t.installed = true;
					}
					state.cache.themes = null;
					if ( state.route === 'extensions' ) renderExtensions();
					renderOverlays();
				} catch ( e ) {
					toast( e.message, true );
					renderOverlays();
				}
			} )
		);

		const zone = $( '#minn-ti-dropzone' );
		const file = $( '#minn-ti-file' );
		const uploadZip = async ( f ) => {
			if ( ! f ) return;
			if ( ! /\.zip$/i.test( f.name ) ) {
				toast( 'Theme uploads must be .zip files', true );
				return;
			}
			zone.classList.add( 'minn-busy' );
			toast( `Installing ${ f.name }…` );
			const fd = new FormData();
			fd.append( 'file', f );
			try {
				await api( 'minn-admin/v1/themes/upload', { method: 'POST', body: fd } );
				toast( 'Theme installed — activate it from the Themes tab' );
				state.cache.themes = null;
				closeModal();
				if ( state.route === 'extensions' ) renderExtensions();
			} catch ( e ) {
				toast( e.message, true );
				zone.classList.remove( 'minn-busy' );
			}
		};
		zone.addEventListener( 'click', () => file.click() );
		file.addEventListener( 'change', () => uploadZip( file.files[ 0 ] ) );
		zone.addEventListener( 'dragover', ( e ) => { e.preventDefault(); e.stopPropagation(); zone.classList.add( 'over' ); } );
		zone.addEventListener( 'dragleave', () => zone.classList.remove( 'over' ) );
		zone.addEventListener( 'drop', ( e ) => {
			e.preventDefault();
			e.stopPropagation();
			zone.classList.remove( 'over' );
			uploadZip( e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[ 0 ] );
		} );
	}

	/* ===== Plugin install modal (wp.org search + zip upload) ===== */

	let piSearchTimer = null;

	function bindPluginInstallModal( m ) {
		const input = $( '#minn-pi-search' );
		input.focus();
		input.setSelectionRange( input.value.length, input.value.length );
		input.addEventListener( 'input', () => {
			m.q = input.value.trim();
			clearTimeout( piSearchTimer );
			if ( ! m.q ) return;
			piSearchTimer = setTimeout( async () => {
				m.searching = true;
				renderOverlays();
				try {
					const r = await api( 'minn-admin/v1/plugins/search?q=' + encodeURIComponent( m.q ) );
					if ( state.modal !== m ) return;
					m.results = r.plugins;
				} catch ( e ) {
					toast( e.message, true );
					m.results = [];
				}
				m.searching = false;
				renderOverlays();
			}, 400 );
		} );

		$$( '[data-pi]' ).forEach( ( btn ) =>
			btn.addEventListener( 'click', async () => {
				const p = m.results[ parseInt( btn.dataset.pi, 10 ) ];
				if ( ! p ) return;
				btn.disabled = true;
				const activating = btn.textContent.trim() === 'Activate';
				btn.textContent = activating ? 'Activating…' : 'Installing…';
				try {
					if ( activating ) {
						const local = ( state.cache.plugins || [] ).find( ( x ) => x.plugin.split( '/' )[ 0 ] === p.slug );
						const file = local ? local.plugin : ( p.installed ? p.installed.replace( /\.php$/, '' ) : null );
						await api( 'wp/v2/plugins/' + file, { method: 'PUT', body: JSON.stringify( { status: 'active' } ) } );
						toast( p.name + ' activated' );
					} else {
						await api( 'wp/v2/plugins', { method: 'POST', body: JSON.stringify( { slug: p.slug } ) } );
						toast( p.name + ' installed' );
					}
					state.cache.plugins = null;
					await loadPlugins().catch( () => {} );
					if ( state.route === 'extensions' ) renderExtensions();
					renderOverlays(); // refresh button states in the modal
				} catch ( e ) {
					toast( e.message, true );
					renderOverlays();
				}
			} )
		);

		const zone = $( '#minn-pi-dropzone' );
		const file = $( '#minn-pi-file' );
		const uploadZip = async ( f ) => {
			if ( ! f ) return;
			if ( ! /\.zip$/i.test( f.name ) ) {
				toast( 'Plugin uploads must be .zip files', true );
				return;
			}
			zone.classList.add( 'minn-busy' );
			toast( `Installing ${ f.name }…` );
			const fd = new FormData();
			fd.append( 'file', f );
			try {
				await api( 'minn-admin/v1/plugins/upload', { method: 'POST', body: fd } );
				toast( 'Plugin installed — activate it from the list' );
				state.cache.plugins = null;
				await loadPlugins().catch( () => {} );
				closeModal();
				if ( state.route === 'extensions' ) renderExtensions();
			} catch ( e ) {
				toast( e.message, true );
				zone.classList.remove( 'minn-busy' );
			}
		};
		zone.addEventListener( 'click', () => file.click() );
		file.addEventListener( 'change', () => uploadZip( file.files[ 0 ] ) );
		zone.addEventListener( 'dragover', ( e ) => { e.preventDefault(); e.stopPropagation(); zone.classList.add( 'over' ); } );
		zone.addEventListener( 'dragleave', () => zone.classList.remove( 'over' ) );
		zone.addEventListener( 'drop', ( e ) => {
			e.preventDefault();
			e.stopPropagation();
			zone.classList.remove( 'over' );
			uploadZip( e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[ 0 ] );
		} );
	}

	/* ===== Revision modal ===== */

	function openRevision( ed, revId ) {
		state.modal = { type: 'revision', ed: { id: ed.id, type: ed.type }, revId, rev: null };
		renderOverlays();
		api( `wp/v2/${ ed.type }/${ ed.id }/revisions/${ revId }?context=edit&_fields=id,modified,title,content` )
			.then( ( rev ) => {
				if ( state.modal && state.modal.type === 'revision' && state.modal.revId === revId ) {
					state.modal.rev = rev;
					renderOverlays();
				}
			} )
			.catch( ( e ) => { toast( e.message, true ); closeModal(); } );
	}

	function renderRevisionModal( m ) {
		const rev = m.rev;
		return `
		<div class="minn-modal-overlay" id="minn-modal-overlay">
			<div class="minn-modal wide">
				<div class="minn-modal-head">
					<div class="minn-modal-title">${ rev ? 'Revision · ' + timeAgo( rev.modified ) : 'Revision' }</div>
					<button class="minn-x-btn" id="minn-modal-close">×</button>
				</div>
				${ ! rev ? '<div class="minn-loading">Loading revision…</div>' : `
				<div class="minn-modal-meta">
					<div class="minn-side-row"><span class="minn-side-key">Title</span><span class="minn-surface-val">${ esc( decodeEntities( ( rev.title && ( rev.title.raw != null ? rev.title.raw : rev.title.rendered ) ) || '(no title)' ) ) }</span></div>
					<div class="minn-side-row"><span class="minn-side-key">Saved</span><span>${ timeAgo( rev.modified ) }</span></div>
				</div>
				<div class="minn-revision-preview" id="minn-revision-preview"></div>
				<div class="minn-modal-actions">
					<button class="minn-btn-primary" id="minn-restore-rev">Restore this revision</button>
				</div>` }
			</div>
		</div>`;
	}

	function bindRevisionModal( m ) {
		const rev = m.rev;
		if ( ! rev ) return;
		const preview = $( '#minn-revision-preview' );
		if ( preview ) {
			const raw = ( rev.content && ( rev.content.raw != null ? rev.content.raw : rev.content.rendered ) ) || '';
			preview.innerHTML = stripBlockComments( raw ) || '<span style="color:var(--text3);">(empty)</span>';
			highlightCodeBlocks( preview );
		}
		const restore = $( '#minn-restore-rev' );
		if ( restore ) {
			restore.addEventListener( 'click', async () => {
				if ( ! confirm( 'Replace the current content with this revision? The current state is saved as its own revision first.' ) ) return;
				restore.disabled = true;
				restore.textContent = 'Restoring…';
				try {
					await api( `wp/v2/${ m.ed.type }/${ m.ed.id }`, {
						method: 'POST',
						body: JSON.stringify( {
							title: ( rev.title && ( rev.title.raw != null ? rev.title.raw : rev.title.rendered ) ) || '',
							content: ( rev.content && rev.content.raw ) || '',
						} ),
					} );
					toast( 'Revision restored' );
					closeModal();
					state.cache.content = null;
					state.editor = null; // reload the editor with the restored content
					if ( state.route === 'editor' ) renderEditor();
				} catch ( e ) {
					toast( e.message, true );
					restore.disabled = false;
					restore.textContent = 'Restore this revision';
				}
			} );
		}
	}

	/* ===== User modal (create / edit / sessions) ===== */

	function uaSummary( ua ) {
		if ( ! ua ) return 'Unknown device';
		const browser = /Edg\//.test( ua ) ? 'Edge' : /OPR\//.test( ua ) ? 'Opera' : /Chrome\//.test( ua ) ? 'Chrome'
			: /Safari\//.test( ua ) && /Version\//.test( ua ) ? 'Safari' : /Firefox\//.test( ua ) ? 'Firefox' : 'Browser';
		const os = /Windows/.test( ua ) ? 'Windows' : /iPhone|iPad/.test( ua ) ? 'iOS' : /Android/.test( ua ) ? 'Android'
			: /Mac OS X/.test( ua ) ? 'macOS' : /Linux/.test( ua ) ? 'Linux' : '';
		return browser + ( os ? ' · ' + os : '' );
	}

	function openUserModal( userId ) {
		state.modal = { type: 'user', userId: userId || null, user: null, sessions: null, appPasswords: null, newAppPassword: null };
		renderOverlays();
		if ( ! userId ) return;
		if ( userId === B.user.id ) {
			api( 'wp/v2/users/me/application-passwords' )
				.then( ( list ) => {
					if ( state.modal && state.modal.type === 'user' && state.modal.userId === userId ) {
						state.modal.appPasswords = list;
						renderOverlays();
					}
				} )
				.catch( () => {
					if ( state.modal && state.modal.type === 'user' ) {
						state.modal.appPasswords = [];
						renderOverlays();
					}
				} );
		}
		api( `wp/v2/users/${ userId }?context=edit&_fields=id,name,email,roles,username` )
			.then( ( u ) => {
				if ( state.modal && state.modal.type === 'user' && state.modal.userId === userId ) {
					state.modal.user = u;
					renderOverlays();
				}
			} )
			.catch( ( e ) => { toast( e.message, true ); closeModal(); } );
		api( `minn-admin/v1/users/${ userId }/sessions` )
			.then( ( r ) => {
				if ( state.modal && state.modal.type === 'user' && state.modal.userId === userId ) {
					state.modal.sessions = r.sessions;
					renderOverlays();
				}
			} )
			.catch( () => {
				if ( state.modal && state.modal.type === 'user' ) {
					state.modal.sessions = [];
					renderOverlays();
				}
			} );
	}

	function generatePassword() {
		const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*';
		const buf = new Uint32Array( 20 );
		crypto.getRandomValues( buf );
		return Array.from( buf, ( n ) => chars[ n % chars.length ] ).join( '' );
	}

	// Markdown reference an AI agent needs to work this site over REST,
	// tailored to what's actually installed.
	function buildAgentGuide() {
		const L = [];
		L.push(
			`# ${ B.site.name } — AI agent access`,
			'',
			`Base URL: \`${ B.restUrl }\``,
			'',
			'## Authentication',
			'',
			`HTTP Basic auth with the WordPress username \`${ B.user.login }\` and an application`,
			'password (create/revoke them in Minn Admin → your account → AI Access).',
			'',
			'```bash',
			`curl -u '${ B.user.login }:APPLICATION-PASSWORD' '${ B.restUrl }wp/v2/posts?per_page=5'`,
			'```',
			'',
			'## Core resources (`wp/v2/`)',
			'',
			'- `posts`, `pages`, `media`, `users`, `comments`, `categories`, `tags`, `settings`, `plugins`, `types`',
			'- Lists take `?per_page=&page=`; totals come back in the `X-WP-Total` header',
			'- Use `context=edit` for raw (unrendered) content; prefer `_fields=` to keep responses small',
			'- Posts are stored as Gutenberg block markup (`<!-- wp:paragraph -->…`)',
			'',
			'## Detected on this site',
			''
		);
		const detected = [];
		if ( B.wc ) detected.push( '- WooCommerce: `wc/v3/` (orders, products, customers, reports)' );
		( B.surfaces || [] ).forEach( ( s ) => {
			if ( s.id === 'gravity-forms' ) detected.push( '- Gravity Forms: `gf/v2/` (forms, entries; pagination via `paging[page_size]`/`paging[current_page]`)' );
			else if ( s.id === 'gravity-smtp' ) detected.push( '- Gravity SMTP email log (read-only): `minn-admin/v1/gravity-smtp/events`' );
			else if ( s.collection && s.collection.route ) detected.push( `- ${ s.label }${ s.sub ? ' (' + s.sub + ')' : '' }: \`${ s.collection.route }\`` );
		} );
		( B.editorPanels || [] ).forEach( ( p ) => {
			if ( p.id === 'acf' ) detected.push( '- ACF: field groups with “Show in REST API” read/write through the `acf` key on post responses' );
		} );
		L.push( ...( detected.length ? detected : [ '- (nothing beyond core detected)' ] ) );
		L.push(
			'',
			'## Minn Admin extras (`minn-admin/v1/`)',
			'',
			'- `overview` — stats, activity chart and recent activity',
			'- `users/{id}/sessions` — active login sessions (DELETE to sign out)',
			'- `plugins/update` / `plugins/update-all` — run plugin updates',
			'',
			`_Generated by Minn Admin v${ B.version }. Site: ${ B.site.url }_`
		);
		return L.join( '\n' );
	}

	function bindUserModal( m ) {
		if ( ! $( '#minn-uf-save' ) ) return; // still in the loading state

		if ( m.userId === B.user.id ) {
			const copyText = async ( text, label ) => {
				try {
					await navigator.clipboard.writeText( text );
					toast( label );
				} catch ( e ) {
					toast( 'Could not copy', true );
				}
			};
			const createBtn = $( '#minn-app-create' );
			if ( createBtn ) {
				createBtn.addEventListener( 'click', async () => {
					createBtn.disabled = true;
					const name = $( '#minn-app-name' ).value.trim() || 'AI Agent';
					try {
						const ap = await api( 'wp/v2/users/me/application-passwords', { method: 'POST', body: JSON.stringify( { name } ) } );
						m.newAppPassword = { name: ap.name, password: ap.password };
						m.appPasswords = null;
						renderOverlays();
						api( 'wp/v2/users/me/application-passwords' ).then( ( list ) => {
							if ( state.modal === m ) { m.appPasswords = list; renderOverlays(); }
						} ).catch( () => {} );
					} catch ( e ) {
						toast( e.message, true );
						createBtn.disabled = false;
					}
				} );
			}
			const copyBtn = $( '#minn-app-copy' );
			if ( copyBtn ) copyBtn.addEventListener( 'click', () => copyText( m.newAppPassword.password, 'Password copied' ) );
			const curlBtn = $( '#minn-app-copy-curl' );
			if ( curlBtn ) curlBtn.addEventListener( 'click', () => copyText(
				`curl -u '${ B.user.login }:${ m.newAppPassword.password }' '${ B.restUrl }wp/v2/posts?per_page=5'`, 'curl example copied' ) );
			$$( '[data-appdel]' ).forEach( ( btn ) =>
				btn.addEventListener( 'click', async () => {
					if ( ! confirm( 'Revoke this application password? Anything using it loses access immediately.' ) ) return;
					btn.disabled = true;
					try {
						await api( 'wp/v2/users/me/application-passwords/' + btn.dataset.appdel, { method: 'DELETE' } );
						toast( 'Application password revoked' );
						m.appPasswords = m.appPasswords.filter( ( ap ) => ap.uuid !== btn.dataset.appdel );
						renderOverlays();
					} catch ( e ) {
						toast( e.message, true );
						btn.disabled = false;
					}
				} )
			);
			const guideCopy = $( '#minn-guide-copy' );
			if ( guideCopy ) guideCopy.addEventListener( 'click', () => copyText( buildAgentGuide(), 'Agent guide copied' ) );
			const guideDl = $( '#minn-guide-download' );
			if ( guideDl ) guideDl.addEventListener( 'click', () => {
				const blob = new Blob( [ buildAgentGuide() ], { type: 'text/markdown' } );
				const a = document.createElement( 'a' );
				a.href = URL.createObjectURL( blob );
				a.download = 'agent-guide.md';
				a.click();
				URL.revokeObjectURL( a.href );
			} );
		}
		const isNew = ! m.userId;
		const gen = $( '#minn-uf-genpass' );
		if ( gen ) {
			gen.addEventListener( 'click', () => {
				const input = $( '#minn-uf-password' );
				input.value = generatePassword();
				input.type = 'text';
			} );
		}

		$( '#minn-uf-save' ).addEventListener( 'click', async ( e ) => {
			const btn = e.currentTarget;
			btn.disabled = true;
			const payload = {
				name: $( '#minn-uf-name' ).value.trim(),
				email: $( '#minn-uf-email' ).value.trim(),
			};
			if ( B.caps.promoteUsers ) payload.roles = [ $( '#minn-uf-role' ).value ];
			const password = $( '#minn-uf-password' ).value;
			if ( password ) payload.password = password;
			try {
				if ( isNew ) {
					payload.username = $( '#minn-uf-username' ).value.trim();
					if ( ! payload.username || ! payload.email || ! password ) {
						throw new Error( 'Username, email and password are required.' );
					}
					await api( 'wp/v2/users', { method: 'POST', body: JSON.stringify( payload ) } );
					toast( 'User created' );
				} else {
					await api( `wp/v2/users/${ m.userId }`, { method: 'POST', body: JSON.stringify( payload ) } );
					toast( 'User updated' );
				}
				closeModal();
				state.cache.users = null;
				if ( state.route === 'users' ) renderUsers();
			} catch ( err ) {
				toast( err.message, true );
				btn.disabled = false;
			}
		} );

		const del = $( '#minn-uf-delete' );
		if ( del ) {
			del.addEventListener( 'click', async () => {
				const name = m.user ? m.user.name : 'this user';
				if ( ! confirm( `Delete ${ name }? Their content will be reassigned to you.` ) ) return;
				del.disabled = true;
				try {
					await api( `wp/v2/users/${ m.userId }?force=true&reassign=${ B.user.id }`, { method: 'DELETE' } );
					toast( 'User deleted' );
					closeModal();
					state.cache.users = null;
					if ( state.route === 'users' ) renderUsers();
				} catch ( err ) {
					toast( err.message, true );
					del.disabled = false;
				}
			} );
		}

		$$( '[data-kill]' ).forEach( ( btn ) =>
			btn.addEventListener( 'click', async () => {
				btn.disabled = true;
				try {
					await api( `minn-admin/v1/users/${ m.userId }/sessions/${ btn.dataset.kill }`, { method: 'DELETE' } );
					toast( 'Session signed out' );
					m.sessions = m.sessions.filter( ( sess ) => sess.verifier !== btn.dataset.kill );
					renderOverlays();
				} catch ( err ) {
					toast( err.message, true );
					btn.disabled = false;
				}
			} )
		);

		const killAll = $( '#minn-uf-killall' );
		if ( killAll ) {
			killAll.addEventListener( 'click', async () => {
				if ( ! confirm( 'Sign this user out of all sessions?' ) ) return;
				killAll.disabled = true;
				try {
					await api( `minn-admin/v1/users/${ m.userId }/sessions`, { method: 'DELETE' } );
					toast( 'Signed out everywhere' );
					m.sessions = m.sessions.filter( ( sess ) => sess.current );
					renderOverlays();
				} catch ( err ) {
					toast( err.message, true );
					killAll.disabled = false;
				}
			} );
		}
	}

	function openMediaPicker( callback ) {
		state.modal = { type: 'picker', items: null, callback };
		renderOverlays();
		api( 'wp/v2/media?media_type=image&per_page=48&orderby=date&order=desc&_fields=id,title,source_url,media_details,alt_text' )
			.then( ( items ) => {
				if ( ! state.modal || state.modal.type !== 'picker' ) return;
				state.modal.items = items.map( ( it ) => ( {
					id: it.id,
					name: decodeEntities( it.title.rendered ),
					url: it.source_url,
					alt: it.alt_text || '',
					thumb: ( it.media_details && it.media_details.sizes && it.media_details.sizes.medium && it.media_details.sizes.medium.source_url ) || it.source_url,
				} ) );
				renderOverlays();
			} )
			.catch( ( e ) => { toast( e.message, true ); closeModal(); } );
	}

	/* ===== Overlays ===== */

	function renderOverlays() {
		const root = $( '#minn-overlays' );
		// Remember what was already on screen: re-renders of an open panel/modal
		// must not replay their entrance animation (it reads as a flash).
		const had = {
			'.minn-notif-panel': !! $( '.minn-notif-panel', root ),
			'.minn-palette': !! $( '.minn-palette', root ),
			'.minn-modal': !! $( '.minn-modal', root ),
		};
		root.innerHTML = ( state.notifOpen ? renderNotifPanel() : '' ) + ( state.paletteOpen ? renderPalette() : '' ) + renderModal();
		Object.keys( had ).forEach( ( sel ) => {
			if ( ! had[ sel ] ) return;
			const el = $( sel, root );
			if ( el ) el.classList.add( 'no-anim' );
		} );
		bindModal();

		if ( state.notifOpen ) {
			$( '#minn-notif-overlay' ).addEventListener( 'click', ( e ) => {
				if ( e.target.id === 'minn-notif-overlay' ) { state.notifOpen = false; renderOverlays(); }
			} );
			$( '#minn-notif-close' ).addEventListener( 'click', () => { state.notifOpen = false; renderOverlays(); } );
			$( '#minn-mark-read' ).addEventListener( 'click', async () => {
				try {
					await api( 'minn-admin/v1/notifications/read', { method: 'POST', body: '{}' } );
					( state.cache.notifications || [] ).forEach( ( n ) => ( n.unread = false ) );
					updateUnreadDot();
					renderOverlays();
					toast( 'All notifications marked read' );
				} catch ( e ) {
					toast( e.message, true );
				}
			} );
			$$( '.minn-notif-tab' ).forEach( ( btn ) =>
				btn.addEventListener( 'click', () => { state.notifTab = btn.dataset.tab; renderOverlays(); } )
			);
			$$( '.minn-notif-row' ).forEach( ( row ) =>
				row.addEventListener( 'click', () => {
					const item = ( state.cache.notifications || [] ).find( ( n ) => n.id === row.dataset.nid );
					if ( ! item ) return;
					if ( item.unread ) {
						item.unread = false;
						api( 'minn-admin/v1/notifications/read', { method: 'POST', body: JSON.stringify( { id: item.id } ) } ).catch( () => {} );
						updateUnreadDot();
					}
					state.notifOpen = false;
					renderOverlays();
					// Take the user to the thing the notification is about.
					if ( item.kind === 'comments' && B.caps.moderate ) go( 'comments' );
					else if ( item.kind === 'updates' && B.caps.plugins ) go( 'extensions' );
					else if ( item.id.startsWith( 'user-' ) && B.caps.users ) go( 'users' );
					else if ( item.id.startsWith( 'core-' ) ) window.open( B.site.adminUrl + 'update-core.php', '_blank' );
				} )
			);
		}

		if ( state.paletteOpen ) {
			const input = $( '#minn-palette-input' );
			renderPaletteList( '' );
			input.focus();
			input.addEventListener( 'input', () => { state.paletteSel = 0; renderPaletteList( input.value ); } );
			input.addEventListener( 'keydown', ( e ) => {
				const n = ( state.paletteFiltered || [] ).length;
				if ( e.key === 'ArrowDown' ) { e.preventDefault(); state.paletteSel = ( state.paletteSel + 1 ) % Math.max( 1, n ); renderPaletteList( input.value ); }
				if ( e.key === 'ArrowUp' ) { e.preventDefault(); state.paletteSel = ( state.paletteSel - 1 + Math.max( 1, n ) ) % Math.max( 1, n ); renderPaletteList( input.value ); }
				if ( e.key === 'Enter' && n ) { runPaletteItem( state.paletteSel ); }
			} );
			$( '#minn-palette-overlay' ).addEventListener( 'click', ( e ) => {
				if ( e.target.id === 'minn-palette-overlay' ) { state.paletteOpen = false; renderOverlays(); }
			} );
		}
	}

	/* ===== View dispatch ===== */

	function renderIfCurrent( route ) {
		return () => { if ( state.route === route ) renderView(); };
	}

	function showErr( e ) {
		$( '#minn-view' ).innerHTML = `<div class="minn-card minn-empty">Something went wrong: ${ esc( e.message ) }</div>`;
	}

	function renderView() {
		renderTopbar();
		const tip = $( '#minn-chart-tip' );
		if ( tip ) tip.hidden = true;
		switch ( state.route ) {
			case 'content': return renderContent();
			case 'media': return renderMedia();
			case 'comments': return renderComments();
			case 'orders': return renderOrders();
			case 'users': return renderUsers();
			case 'extensions': return renderExtensions();
			case 'settings': return renderSettings();
			case 'editor': return renderEditor();
			default:
				if ( surfaceById( state.route ) ) return renderSurface( surfaceById( state.route ) );
				return renderOverview();
		}
	}

	/* ===== Boot ===== */

	function boot() {
		// Migrate legacy #/route links onto path routing.
		if ( PATH_MODE ) {
			if ( location.hash.startsWith( '#/' ) ) {
				setPath( location.hash.replace( /^#\//, '' ), true );
			} else if ( location.pathname + '/' === BASE ) {
				history.replaceState( null, '', BASE );
			}
		}

		renderShell();
		parseHash();
		renderView();

		window.addEventListener( 'popstate', onRouteChange );
		if ( ! PATH_MODE ) window.addEventListener( 'hashchange', onRouteChange );

		window.addEventListener( 'keydown', ( e ) => {
			if ( ( e.metaKey || e.ctrlKey ) && e.key.toLowerCase() === 'k' ) {
				e.preventDefault();
				state.paletteOpen = ! state.paletteOpen;
				renderOverlays();
			}
			if ( state.modal && state.modal.type === 'media' ) {
				if ( e.key === 'ArrowLeft' ) { e.preventDefault(); mediaModalNav( -1 ); }
				if ( e.key === 'ArrowRight' ) { e.preventDefault(); mediaModalNav( 1 ); }
			}
			if ( e.key === 'Escape' && ( state.paletteOpen || state.notifOpen || state.modal ) ) {
				state.paletteOpen = false;
				state.notifOpen = false;
				state.modal = null;
				renderOverlays();
			}
		} );

		// Background: unread indicator, plugin update dot, pending comment count.
		loadNotifications();
		if ( B.caps.plugins ) {
			loadPlugins().catch( () => {} );
		}
		refreshCommentBadge();
		loadTypes().catch( () => {} );
		if ( B.wc && B.caps.orders ) loadOrderSummary().catch( () => {} );
		// Warm the content cache so the sidebar count appears.
		if ( state.route !== 'content' ) loadContent().catch( () => {} );

		// Drag & drop upload from anywhere in the app.
		if ( B.caps.upload ) {
			let dragDepth = 0;
			window.addEventListener( 'dragenter', ( e ) => {
				if ( e.dataTransfer && Array.from( e.dataTransfer.types ).includes( 'Files' ) ) {
					dragDepth++;
					document.body.classList.add( 'minn-dragging' );
				}
			} );
			window.addEventListener( 'dragleave', () => {
				dragDepth = Math.max( 0, dragDepth - 1 );
				if ( ! dragDepth ) document.body.classList.remove( 'minn-dragging' );
			} );
			window.addEventListener( 'dragover', ( e ) => e.preventDefault() );
			window.addEventListener( 'drop', ( e ) => {
				e.preventDefault();
				dragDepth = 0;
				document.body.classList.remove( 'minn-dragging' );
				const files = Array.from( ( e.dataTransfer && e.dataTransfer.files ) || [] );
				if ( files.length ) {
					if ( state.route !== 'media' ) go( 'media' );
					uploadFiles( files );
				}
			} );
		}
	}

	boot();
}() );
