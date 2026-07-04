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
	// wp.org titles are stuffed with taglines ("UpdraftPlus: WP Backup &
	// Migration Plugin") — keep the product name. Cut at the first separator:
	// dashes/pipes/middots need surrounding space (WP-Optimize survives),
	// colon/semicolon/period/comma just a following space, parens always.
	// "X by Vendor" comes off only when a multi-word name remains, so
	// "Login by Auth0" survives while "GEO Plugin by Squirrly SEO" trims.
	// The full name stays available where it matters (title tooltip).
	function cleanPluginName( name ) {
		const full = decodeEntities( name || '' ).trim();
		let out = full.split( /\s+[–—|·]\s+|\s+-\s+|[:;.,]\s+|\s*[({]/ )[ 0 ].trim();
		const by = out.match( /^(.+?)\s+by\s+\S/i );
		if ( by && by[ 1 ].trim().includes( ' ' ) ) out = by[ 1 ].trim();
		return out.length >= 2 ? out : full;
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
		menus: [ 'Menus', 'Navigation' ],
		widgets: [ 'Widgets', 'Sidebars & footers' ],
		extensions: [ 'Extensions', 'Installed' ],
		posttypes: [ 'Post Types', 'Structure' ],
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

	function newContent( type ) {
		state.editor = null;
		state.editorId = null;
		state.editorType = type;
		go( 'editor/' + type );
	}

	// Small "Post / Page" menu under the + New button. Users who can't edit
	// pages skip the menu entirely and go straight to a new post.
	function toggleNewMenu( btn ) {
		const open = $( '#minn-new-menu' );
		if ( open ) {
			open.remove();
			return;
		}
		const menu = document.createElement( 'div' );
		menu.id = 'minn-new-menu';
		menu.className = 'minn-new-menu';
		menu.innerHTML = `
			<button data-newtype="posts"><span class="minn-row-icon">¶</span> Post</button>
			<button data-newtype="pages"><span class="minn-row-icon">▭</span> Page</button>`;
		const r = btn.getBoundingClientRect();
		menu.style.top = ( r.bottom + 6 ) + 'px';
		menu.style.right = Math.max( 8, window.innerWidth - r.right ) + 'px';
		document.body.appendChild( menu );
		$$( 'button', menu ).forEach( ( b ) =>
			b.addEventListener( 'click', () => {
				menu.remove();
				newContent( b.dataset.newtype );
			} )
		);
		setTimeout( () => {
			const close = ( ev ) => {
				if ( ! menu.contains( ev.target ) ) menu.remove();
				document.removeEventListener( 'click', close );
			};
			document.addEventListener( 'click', close );
		}, 0 );
	}

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
			columns: '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M12 3v18"/>',
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
		// Classic themes only — block themes manage navigation and widget areas
		// in the site editor, and wp-admin hides these screens the same way.
		if ( B.caps.themeOptions && ! B.site.blockTheme ) {
			manageItems.push( { id: 'menus', label: 'Menus', icon: 'list' } );
			if ( B.site.hasSidebars ) {
				manageItems.push( { id: 'widgets', label: 'Widgets', icon: 'columns' } );
			}
		}
		if ( B.caps.settings ) {
			manageItems.push( { id: 'posttypes', label: 'Post Types', icon: 'grid' } );
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
						<button class="minn-btn-primary" id="minn-new-btn" aria-label="New post">${ icon( 'plus' ) } New</button>
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
		$( '#minn-new-btn' ).addEventListener( 'click', ( e ) => {
			e.stopPropagation();
			if ( B.caps.editPages ) toggleNewMenu( e.currentTarget );
			else newContent( 'posts' );
		} );
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
		// Chart source: traffic (when an analytics adapter answered) or activity.
		// Traffic leads by default; the swap button cycles and the pick sticks.
		const sources = o.traffic ? [ 'traffic', 'activity' ] : [ 'activity' ];
		if ( ! state.chartSource ) state.chartSource = localStorage.getItem( 'minn-chart-source' ) || sources[ 0 ];
		if ( ! sources.includes( state.chartSource ) ) state.chartSource = sources[ 0 ];
		const isTraffic = state.chartSource === 'traffic';
		const chartData = isTraffic ? o.traffic.chart : o.chart;
		// Traffic bars stack pageviews (soft) behind visitors (solid), so the
		// scale runs to the pageview max like Koko's own widget.
		const max = Math.max( 1, ...chartData.map( ( c ) => isTraffic ? ( c.views || c.value ) : c.value ) );
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
					<div class="minn-panel-title">${ isTraffic ? 'Traffic' : 'Activity' }${ isTraffic ? ` <span class="minn-panel-sub">${ esc( o.traffic.source ) }</span>` : '' }</div>
					<div class="minn-chart-head-actions">
						${ sources.length > 1 ? `<button class="minn-icon-btn sm" id="minn-chart-swap" title="Show ${ isTraffic ? 'Activity' : 'Traffic' }">⇄</button>` : '' }
						<div class="minn-range-tabs">
							${ [ 7, 30, 90 ].map( ( d ) => `<button class="minn-range-tab${ state.range === d ? ' active' : '' }" data-range="${ d }">${ d }d</button>` ).join( '' ) }
						</div>
					</div>
				</div>
				<div class="minn-chart${ isTraffic ? '' : ' clickable' }" id="minn-chart">
					${ chartData.map( ( c, i ) => isTraffic ? `
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
		const swap = $( '#minn-chart-swap', view );
		if ( swap ) swap.addEventListener( 'click', () => {
			state.chartSource = isTraffic ? 'activity' : 'traffic';
			localStorage.setItem( 'minn-chart-source', state.chartSource );
			renderOverview();
		} );
		bindChartTooltip( $( '#minn-chart', view ), chartData, isTraffic );
		// Activity bars open the events behind them; traffic bars stay hover-only.
		if ( ! isTraffic ) {
			$$( '.minn-chart-col', view ).forEach( ( col ) =>
				col.addEventListener( 'click', () => {
					const c = chartData[ parseInt( col.dataset.ci, 10 ) ];
					if ( c && c.from && c.value > 0 ) openChartActivity( c );
				} )
			);
		}
	}

	async function openChartActivity( bucket ) {
		state.modal = { type: 'chart-activity', bucket, items: null };
		renderOverlays();
		try {
			const r = await api( `minn-admin/v1/overview/activity?from=${ encodeURIComponent( bucket.from ) }&to=${ encodeURIComponent( bucket.to ) }` );
			if ( state.modal && state.modal.type === 'chart-activity' && state.modal.bucket === bucket ) {
				state.modal.items = r.items || [];
				renderOverlays();
			}
		} catch ( e ) {
			toast( e.message, true );
			closeModal();
		}
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
					</div>
					${ ! isTraffic && c.value > 0 ? '<div class="minn-chart-tip-hint">Click for details</div>' : '' }`;
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
		// Trash mode swaps the whole list to status=trash (REST scopes it to what
		// the user can read, so authors see only their own trashed items).
		const statuses = state.contentTrash
			? 'trash'
			: 'publish,future,draft,pending' + ( B.caps.readPrivate ? ',private' : '' );
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

	// The query context a content load belongs to. A load started before a
	// context change (trash toggle, search, tax filter) must not land its rows
	// into the new context — in trash mode that would put Restore/Delete
	// buttons on live posts. Same-context loads may land freely (they fetch
	// identical data), so parallel startup loads can't starve each other.
	const contentCtx = () => [ state.contentTrash ? 't' : '', state.contentSearch || '', state.contentCat || '', state.contentTag || '' ].join( '|' );

	async function loadCpt( more ) {
		const t = currentCpt();
		if ( ! t ) return;
		const ctx = contentCtx();
		const cache = state.cache.cptContent;
		const c = more && cache[ t.restBase ] ? cache[ t.restBase ] : { items: [], page: 0, totalPages: 1, total: 0 };
		const r = await apiPaged( `wp/v2/${ t.restBase }?` + contentQuery( c.page + 1 ) );
		if ( ctx !== contentCtx() ) return; // context changed mid-flight — discard
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
		const ctx = contentCtx();
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
		if ( ctx !== contentCtx() ) return; // context changed mid-flight — discard
		c.items.sort( ( a, b ) => ( a.modified < b.modified ? 1 : -1 ) );
		c.total = ( c.postTotal || 0 ) + ( c.pageTotal || 0 );
		state.cache.content = c;

		const badge = $( '#minn-content-count' );
		if ( badge && ! state.contentSearch && ! state.contentTrash ) {
			badge.textContent = c.total > 999 ? ( Math.round( c.total / 100 ) / 10 ) + 'k' : c.total;
			badge.hidden = ! c.total;
		}
	}

	const STATUS_LABELS = { publish: 'Published', draft: 'Draft', future: 'Scheduled', pending: 'Pending', private: 'Private', trash: 'Trashed' };

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
		if ( ! sel.size ) state.contentLastIdx = null;
		// Searchable comboboxes (many terms outgrow a native select). The empty
		// value is the "All …" reset; options carry the term counts.
		const taxCombo = ( id, label ) => `<div class="minn-ac minn-tax-select" data-taxcombo="${ id }">
			<input class="minn-input minn-ac-input" placeholder="${ esc( label ) }" autocomplete="off" spellcheck="false" role="combobox" aria-expanded="false">
			<div class="minn-ac-panel" hidden></div>
		</div>`;
		const taxComboOptions = ( allLabel, list ) => [ { value: '', label: allLabel } ].concat(
			list.map( ( t ) => ( { value: String( t.id ), label: t.count != null ? `${ t.name } (${ t.count })` : t.name } ) )
		);
		view.innerHTML = `
		<div class="minn-toolbar">
			<div class="minn-tabs">
				${ tabs.map( ( [ id, label ] ) =>
					`<button class="minn-tab${ state.filter === id ? ' active' : '' }" data-filter="${ esc( id ) }">${ esc( label ) }</button>` ).join( '' ) }
			</div>
			${ showTax ? taxCombo( 'cat', 'All categories' ) : '' }
			${ showTax ? taxCombo( 'tag', 'All tags' ) : '' }
			<input class="minn-input minn-toolbar-search" id="minn-content-search" placeholder="Filter by title…" value="${ esc( state.contentSearch || '' ) }">
			<button class="minn-btn-soft minn-trash-toggle${ state.contentTrash ? ' active' : '' }" id="minn-content-trash" title="${ state.contentTrash ? 'Back to content' : 'View trash' }">${ icon( 'trash' ) } Trash</button>
			<div class="minn-toolbar-meta">${ filtered.length }${ hasMore ? ' of ' + c.total : '' } item${ c.total === 1 ? '' : 's' }</div>
		</div>
		<div id="minn-bulk-slot"></div>
		<div class="minn-card minn-table">
			<div class="minn-table-head minn-content-cols${ state.contentTrash ? ' trash' : '' }">
				<div><input type="checkbox" class="minn-cb" id="minn-sel-all"${ filtered.length && filtered.every( ( p ) => sel.has( p.id ) ) ? ' checked' : '' }></div>
				<div></div><div>Title</div><div>Status</div><div>Author</div><div>Modified</div><div></div>
			</div>
			${ filtered.length ? filtered.map( ( p ) => `
				<div class="minn-table-row minn-content-cols${ state.contentTrash ? ' trash' : '' }${ sel.has( p.id ) ? ' sel' : '' }" data-id="${ p.id }" data-type="${ esc( p.type ) }">
					<div class="minn-cbcell"><input type="checkbox" class="minn-cb minn-row-cb" data-cbid="${ p.id }"${ sel.has( p.id ) ? ' checked' : '' }></div>
					<div class="minn-row-icon">${ rowIcon( p ) }</div>
					<div class="minn-cell-clip">
						<div class="minn-row-title">${ esc( p.title ) }</div>
						<div class="minn-row-slug">${ esc( p.slug ) }</div>
					</div>
					<div><span class="minn-status ${ esc( p.status ) }">${ STATUS_LABELS[ p.status ] || esc( p.status ) }</span></div>
					<div class="minn-row-meta">${ esc( p.author ) }</div>
					<div class="minn-row-meta">${ timeAgo( p.modified ) }</div>
					${ state.contentTrash ? `
					<div class="minn-row-actions">
						<button class="minn-btn-soft" data-restore="${ p.id }">Restore</button>
						<button class="minn-btn-soft danger" data-fdelete="${ p.id }">Delete</button>
					</div>` : '<div class="minn-row-arrow">›</div>' }
				</div>` ).join( '' ) : `<div class="minn-empty">${ state.contentSearch ? 'No matches for “' + esc( state.contentSearch ) + '”.' : ( state.contentTrash ? 'Trash is empty.' : 'Nothing here yet. Hit <b>New</b> to write something.' ) }</div>` }
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
			const tbl = $( '.minn-table', view );
			if ( tbl ) tbl.classList.add( 'minn-busy' );
			await ( currentCpt() ? loadCpt() : loadContent() ).catch( showErr );
			if ( state.route === 'content' ) renderContent();
		};
		const catWrap = view.querySelector( '[data-taxcombo="cat"]' );
		if ( catWrap ) bindAutocomplete( catWrap, taxComboOptions( 'All categories', terms.categories ), {
			strict: true, value: state.contentCat || '',
			onPick: ( v ) => { state.contentCat = v || null; reloadContent(); },
		} );
		const tagWrap = view.querySelector( '[data-taxcombo="tag"]' );
		if ( tagWrap ) bindAutocomplete( tagWrap, taxComboOptions( 'All tags', terms.tags ), {
			strict: true, value: state.contentTag || '',
			onPick: ( v ) => { state.contentTag = v || null; reloadContent(); },
		} );
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
		const trashBtn = $( '#minn-content-trash', view );
		if ( trashBtn ) trashBtn.addEventListener( 'click', () => {
			state.contentTrash = ! state.contentTrash;
			sel.clear();
			state.cache.content = null;
			state.cache.cptContent = {};
			renderContent();
		} );
		$$( '.minn-table-row', view ).forEach( ( row ) =>
			row.addEventListener( 'click', ( e ) => {
				if ( e.target.closest( '.minn-cbcell' ) ) return; // checkbox handles its own clicks
				if ( state.contentTrash ) return; // trashed posts can't be edited — restore first
				go( `editor/${ row.dataset.type }/${ row.dataset.id }` );
			} )
		);
		const restoreOne = ( type, id ) => api( `minn-admin/v1/posts/${ id }/restore`, { method: 'POST', body: '{}' } );
		const deleteOne = ( type, id ) => api( `wp/v2/${ type }/${ id }?force=true`, { method: 'DELETE' } );
		$$( '[data-restore]', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', async ( e ) => {
				e.stopPropagation();
				const row = btn.closest( '.minn-table-row' );
				btn.disabled = true;
				btn.textContent = '…';
				try {
					const r = await restoreOne( row.dataset.type, row.dataset.id );
					toast( `Restored as ${ STATUS_LABELS[ r.status ] ? STATUS_LABELS[ r.status ].toLowerCase() : r.status }` );
					sel.delete( parseInt( row.dataset.id, 10 ) );
					state.cache.content = null;
					state.cache.cptContent = {};
					await ( currentCpt() ? loadCpt() : loadContent() ).catch( showErr );
					if ( state.route === 'content' ) renderContent();
				} catch ( err ) {
					toast( err.message, true );
					btn.disabled = false;
					btn.textContent = 'Restore';
				}
			} )
		);
		$$( '[data-fdelete]', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', async ( e ) => {
				e.stopPropagation();
				const row = btn.closest( '.minn-table-row' );
				if ( ! confirm( 'Delete this item permanently? This cannot be undone.' ) ) return;
				btn.disabled = true;
				btn.textContent = '…';
				try {
					await deleteOne( row.dataset.type, row.dataset.id );
					toast( 'Deleted permanently' );
					sel.delete( parseInt( row.dataset.id, 10 ) );
					state.cache.content = null;
					state.cache.cptContent = {};
					await ( currentCpt() ? loadCpt() : loadContent() ).catch( showErr );
					if ( state.route === 'content' ) renderContent();
				} catch ( err ) {
					toast( err.message, true );
					btn.disabled = false;
					btn.textContent = 'Delete';
				}
			} )
		);
		// Bulk-selection UI updates the bar in place — no full list re-render, so
		// checkbox state stays put while you tick multiple rows.
		const syncBulkBar = () => {
			const slot = $( '#minn-bulk-slot', view );
			if ( ! slot ) return;
			if ( ! sel.size ) { slot.innerHTML = ''; }
			else if ( ! $( '.minn-bulkbar', slot ) ) {
				slot.innerHTML = state.contentTrash ? `
				<div class="minn-bulkbar">
					<span class="minn-bulk-count">${ sel.size } selected</span>
					<button class="minn-btn-soft" id="minn-bulk-restore">Restore</button>
					<button class="minn-btn-soft danger" id="minn-bulk-delete">${ icon( 'trash' ) } Delete permanently</button>
					<button class="minn-btn-soft" id="minn-bulk-clear" style="margin-left:auto;">Clear</button>
				</div>` : `
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
				const bulkRestore = $( '#minn-bulk-restore', slot );
				if ( bulkRestore ) bulkRestore.addEventListener( 'click', ( e ) => {
					runBulk( sel, e.currentTarget, restoreOne, 'Restored' );
				} );
				const bulkDelete = $( '#minn-bulk-delete', slot );
				if ( bulkDelete ) bulkDelete.addEventListener( 'click', ( e ) => {
					if ( ! confirm( `Permanently delete ${ sel.size } item${ sel.size === 1 ? '' : 's' }? This cannot be undone.` ) ) return;
					runBulk( sel, e.currentTarget, deleteOne, 'Deleted permanently' );
				} );
				const bulkApply = $( '#minn-bulk-apply', slot );
				if ( bulkApply ) bulkApply.addEventListener( 'click', ( e ) => {
					const status = $( '#minn-bulk-status', slot ).value;
					if ( ! status ) { toast( 'Pick a status first', true ); return; }
					runBulk( sel, e.currentTarget, ( type, id ) => api( `wp/v2/${ type }/${ id }`, { method: 'POST', body: JSON.stringify( { status } ) } ), 'Status updated' );
				} );
				const bulkTrash = $( '#minn-bulk-trash', slot );
				if ( bulkTrash ) bulkTrash.addEventListener( 'click', ( e ) => {
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

		const setRowSel = ( p, on ) => {
			if ( on ) sel.set( p.id, p.type ); else sel.delete( p.id );
			const box = view.querySelector( `.minn-row-cb[data-cbid="${ p.id }"]` );
			if ( box ) { box.checked = on; box.closest( '.minn-table-row' ).classList.toggle( 'sel', on ); }
		};
		$$( '.minn-row-cb', view ).forEach( ( cb ) =>
			// Use click (not change) so we can read shiftKey and select a whole range.
			cb.addEventListener( 'click', ( e ) => {
				const id = parseInt( cb.dataset.cbid, 10 );
				const idx = filtered.findIndex( ( p ) => p.id === id );
				if ( e.shiftKey && state.contentLastIdx != null && state.contentLastIdx !== idx && filtered[ state.contentLastIdx ] ) {
					const lo = Math.min( state.contentLastIdx, idx ), hi = Math.max( state.contentLastIdx, idx );
					for ( let i = lo; i <= hi; i++ ) setRowSel( filtered[ i ], cb.checked );
				} else {
					setRowSel( filtered[ idx ], cb.checked );
				}
				state.contentLastIdx = idx;
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
				// Keep the toolbar in place — reflect the active tab now, dim only the table while loading.
				$$( '.minn-tab', view ).forEach( ( t ) => t.classList.toggle( 'active', t === btn ) );
				const tbl = $( '.minn-table', view );
				if ( tbl ) tbl.classList.add( 'minn-busy' );
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
			state.surface[ id ] = { tab: '_all', cache: null, tabs: null, labels: {}, q: '' };
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
		// Adapter-declared search: a query template with {q}, or { param, json }
		// for APIs that take search criteria as a JSON string (Gravity Forms).
		// split/join instead of replace so "$&"-style queries aren't mangled.
		if ( col.search && ss.q ) {
			if ( typeof col.search === 'string' ) {
				parts.push( col.search.split( '{q}' ).join( encodeURIComponent( ss.q ) ) );
			} else {
				const json = JSON.stringify( col.search.json ).split( '{q}' ).join( JSON.stringify( ss.q ).slice( 1, -1 ) );
				// Encoded twice: GF urldecodes the already-decoded param again.
				parts.push( col.search.param + '=' + encodeURIComponent( encodeURIComponent( json ) ) );
			}
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

	// Dot-path assignment ("action_data.url" → { action_data: { url } }).
	function setDeepPath( obj, key, val ) {
		const parts = key.split( '.' );
		let o = obj;
		for ( let i = 0; i < parts.length - 1; i++ ) {
			if ( ! o[ parts[ i ] ] || typeof o[ parts[ i ] ] !== 'object' ) o[ parts[ i ] ] = {};
			o = o[ parts[ i ] ];
		}
		o[ parts[ parts.length - 1 ] ] = val;
	}

	function surfaceCell( item, colDef ) {
		let v = surfaceValue( item, colDef.key );
		if ( ( v == null || v === '' ) && colDef.altKey ) v = surfaceValue( item, colDef.altKey );
		switch ( colDef.format ) {
			case 'ago': {
				// Guard empty and zero timestamps (Redirection stores 0000-00-00
				// for a never-hit redirect) so they read "—" not "Invalid Date".
				const raw = String( v || '' );
				const t = raw && ! /^0{4}/.test( raw ) ? Date.parse( raw.replace( ' ', 'T' ) ) : NaN;
				return `<div class="minn-row-meta minn-cell-clip">${ isNaN( t ) ? '—' : timeAgo( raw.replace( ' ', 'T' ) ) }</div>`;
			}
			case 'pill': return `<div>${ surfacePill( v ) }</div>`;
			case 'title': return `<div class="minn-row-title minn-cell-clip">${ esc( stripTags( String( v || '—' ) ) ) }</div>`;
			case 'entry-summary': return `<div class="minn-row-title minn-cell-clip">${ esc( entrySummary( item ) ) }</div>`;
			case 'num': return `<div class="minn-row-meta minn-num">${ esc( String( v == null || v === '' ? '—' : v ) ) }</div>`;
			case 'mono': return `<div class="minn-row-meta mono minn-cell-clip">${ esc( String( v || '—' ) ) }</div>`;
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
		// Column widths: an adapter's explicit `width` wins; otherwise size by
		// role — flexible for the title/text columns, fixed and narrow for the
		// short ones (codes, counts, dates, pills) so long values get the room.
		const FIXED = { ago: '128px', pill: '110px', mono: '84px', num: '84px' };
		const gridCols = cols.map( ( col, i ) =>
			col.width || FIXED[ col.format ] || ( i === 0 ? 'minmax(0,1.6fr)' : 'minmax(0,1fr)' )
		).join( ' ' ) + ' 30px';

		view.innerHTML = `
		<div class="minn-toolbar">
			${ ss.tabs && ss.tabs.length > 1 ? `
			<div class="minn-tabs">
				${ ss.tabs.map( ( [ id, label ] ) =>
					`<button class="minn-tab${ ss.tab === id ? ' active' : '' }" data-stab="${ esc( id ) }">${ esc( label ) }</button>` ).join( '' ) }
			</div>` : '' }
			${ s.collection.search ? `<input class="minn-input minn-toolbar-search" id="minn-surface-search" placeholder="Filter…" value="${ esc( ss.q || '' ) }">` : '' }
			<div class="minn-toolbar-meta">${ c.total } item${ c.total === 1 ? '' : 's' }</div>
			${ s.collection.create ? `<button class="minn-btn-soft" id="minn-surface-add">${ icon( 'plus' ) } ${ esc( s.collection.create.label || 'Add' ) }</button>` : '' }
		</div>
		<div class="minn-card minn-table">
			<div class="minn-table-head" style="grid-template-columns:${ gridCols };">
				${ cols.map( ( col ) => `<div${ col.format === 'num' ? ' class="minn-num"' : '' }>${ esc( col.label ) }</div>` ).join( '' ) }<div></div>
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
		const search = $( '#minn-surface-search', view );
		if ( search ) {
			let t = null;
			search.addEventListener( 'input', () => {
				clearTimeout( t );
				t = setTimeout( async () => {
					ss.q = search.value.trim();
					ss.cache = null;
					const tbl = $( '.minn-table', view );
					if ( tbl ) tbl.classList.add( 'minn-busy' );
					await loadSurfaceItems( s ).catch( showErr );
					if ( state.route === s.id ) {
						renderSurface( s );
						const again = $( '#minn-surface-search' );
						if ( again ) { again.focus(); again.setSelectionRange( again.value.length, again.value.length ); }
					}
				}, 350 );
			} );
		}
		const addBtn = $( '#minn-surface-add', view );
		if ( addBtn ) addBtn.addEventListener( 'click', () => {
			state.modal = { type: 'surface-form', surface: s };
			renderOverlays();
		} );
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

	/* ===== Menus (classic navigation) ===== */

	function menusState() {
		if ( ! state.menusData ) {
			state.menusData = { menus: null, sel: null, items: null, locations: null, pick: null, editing: null };
		}
		return state.menusData;
	}

	async function loadMenus() {
		const ms = menusState();
		const [ menus, locations ] = await Promise.all( [
			api( 'wp/v2/menus?context=edit&per_page=100&_fields=id,name,locations' ),
			api( 'wp/v2/menu-locations' ).catch( () => ( {} ) ),
		] );
		ms.menus = menus;
		ms.locations = locations;
		if ( ! ms.sel || ! menus.some( ( m ) => m.id === ms.sel ) ) {
			ms.sel = menus.length ? menus[ 0 ].id : null;
		}
	}

	async function loadMenuItems() {
		const ms = menusState();
		if ( ! ms.sel ) {
			ms.items = [];
			return;
		}
		const r = await apiPaged( `wp/v2/menu-items?menus=${ ms.sel }&per_page=100&context=edit&_fields=id,title,url,parent,menu_order,type,object,object_id` );
		ms.itemsTotal = r.total;
		ms.items = r.items.map( ( it ) => ( {
			id: it.id,
			// raw is the stored label (may be empty for post items — WP then
			// falls back to the post's own title, which is what rendered shows).
			label: decodeEntities( stripTags( ( it.title && ( it.title.rendered || it.title.raw ) ) || '' ) ) || '(no label)',
			rawLabel: decodeEntities( ( it.title && it.title.raw ) || '' ),
			url: it.url || '',
			parent: it.parent || 0,
			order: it.menu_order || 0,
			type: it.type,
			object: it.object,
		} ) );
	}

	// Pages + recent posts for the "add to menu" picker (loaded once per visit).
	async function loadMenuPick() {
		const ms = menusState();
		if ( ms.pick ) return;
		const [ pages, posts ] = await Promise.all( [
			api( 'wp/v2/pages?per_page=100&orderby=title&order=asc&_fields=id,title,link' ).catch( () => [] ),
			api( 'wp/v2/posts?per_page=100&_fields=id,title,link' ).catch( () => [] ),
		] );
		ms.pick = [
			...pages.map( ( p ) => ( { key: `page:${ p.id }`, object: 'page', id: p.id, title: decodeEntities( p.title.rendered ) || '(no title)', kind: 'Page' } ) ),
			...posts.map( ( p ) => ( { key: `post:${ p.id }`, object: 'post', id: p.id, title: decodeEntities( p.title.rendered ) || '(no title)', kind: 'Post' } ) ),
		];
	}

	// Ordered depth-first flattening of the item tree; orphans land at root.
	function menuTree( items ) {
		const byParent = new Map();
		items.forEach( ( it ) => {
			const k = it.parent || 0;
			if ( ! byParent.has( k ) ) byParent.set( k, [] );
			byParent.get( k ).push( it );
		} );
		const ids = new Set( items.map( ( it ) => it.id ) );
		byParent.forEach( ( list ) => list.sort( ( a, b ) => a.order - b.order ) );
		const flat = [];
		const walk = ( pid, depth ) => {
			( byParent.get( pid ) || [] ).forEach( ( it ) => {
				flat.push( { it, depth } );
				walk( it.id, depth + 1 );
			} );
		};
		walk( 0, 0 );
		items.forEach( ( it ) => {
			if ( it.parent && ! ids.has( it.parent ) && ! flat.some( ( f ) => f.it.id === it.id ) ) {
				flat.push( { it, depth: 0 } );
			}
		} );
		return flat;
	}

	// Persist the current tree shape: renumber depth-first and PATCH every item
	// whose order or parent differs from what the SERVER last knew (`before`) —
	// comparing against the mutated client value would skip items whose new
	// value happens to equal their final position. Sequential writes; WP
	// re-sorts on each one.
	async function saveMenuShape( ms, before ) {
		const flat = menuTree( ms.items );
		flat.forEach( ( f, i ) => { f.it.order = i + 1; } );
		const dirty = ms.items.filter( ( it ) => {
			const b = before.get( it.id );
			return ! b || b.order !== it.order || b.parent !== it.parent;
		} );
		for ( const it of dirty ) {
			await api( `wp/v2/menu-items/${ it.id }`, { method: 'POST', body: JSON.stringify( { menu_order: it.order, parent: it.parent } ) } );
		}
	}

	async function menuShapeAction( ms, mutate ) {
		const view = $( '#minn-view' );
		const tbl = $( '.minn-menu-items', view );
		if ( tbl ) tbl.classList.add( 'minn-busy' );
		const before = new Map( ms.items.map( ( it ) => [ it.id, { order: it.order, parent: it.parent } ] ) );
		try {
			mutate();
			await saveMenuShape( ms, before );
		} catch ( e ) {
			toast( e.message, true );
		}
		await loadMenuItems().catch( showErr );
		if ( state.route === 'menus' ) renderMenus();
	}

	function renderMenus() {
		const view = $( '#minn-view' );
		const ms = menusState();
		if ( ! ms.menus || ( ms.sel && ! ms.items ) ) {
			view.innerHTML = '<div class="minn-loading">Loading menus…</div>';
			// Single-flight: a re-render while loading must not start another
			// chain (a resolved-promise .then( render ) here would loop forever).
			if ( ! ms.loading ) {
				ms.loading = true;
				Promise.all( [ loadMenus().then( loadMenuItems ), loadMenuPick().catch( () => {} ) ] )
					.then( () => { ms.loading = false; } )
					.then( renderIfCurrent( 'menus' ) )
					.catch( ( e ) => { ms.loading = false; showErr( e ); } );
			}
			return;
		}
		const flat = menuTree( ms.items || [] );
		const locations = Object.entries( ms.locations || {} );
		const cur = ( ms.menus || [] ).find( ( m ) => m.id === ms.sel );
		const typeLabel = ( it ) => it.type === 'custom' ? 'Link' : ( it.type === 'taxonomy' ? ( it.object === 'category' ? 'Category' : it.object ) : ( it.object === 'page' ? 'Page' : it.object === 'post' ? 'Post' : it.object || it.type ) );
		view.innerHTML = `
		<div class="minn-toolbar">
			<div class="minn-tabs">
				${ ( ms.menus || [] ).map( ( m ) =>
					`<button class="minn-tab${ ms.sel === m.id ? ' active' : '' }" data-menu="${ m.id }">${ esc( m.name ) }</button>` ).join( '' ) }
			</div>
			<button class="minn-btn-soft" id="minn-menu-new">${ icon( 'plus' ) } New menu</button>
			<div class="minn-toolbar-meta">${ flat.length } item${ flat.length === 1 ? '' : 's' }</div>
		</div>
		${ ! ms.menus.length ? '<div class="minn-card minn-empty">No menus yet. Create one to build your site navigation.</div>' : `
		${ locations.length ? `
		<div class="minn-card minn-menu-locations">
			<div class="minn-panel-title">Theme locations</div>
			${ locations.map( ( [ slug, loc ] ) => `
				<div class="minn-loc-group">
					<span class="minn-side-key">${ esc( loc.description || loc.name || slug ) }</span>
					<div class="minn-ac minn-loc-ac" data-loc="${ esc( slug ) }">
						<input class="minn-input minn-ac-input" placeholder="— none —" autocomplete="off" spellcheck="false" role="combobox" aria-expanded="false">
						<div class="minn-ac-panel" hidden></div>
					</div>
				</div>` ).join( '' ) }
		</div>` : '' }
		<div class="minn-card minn-menu-items">
			${ flat.length ? flat.map( ( { it, depth } ) => ms.editing === it.id ? `
			<div class="minn-menu-row editing" style="padding-left:${ 16 + depth * 26 }px;">
				<div class="minn-menu-edit">
					<input class="minn-input" id="minn-mi-label" value="${ esc( it.rawLabel || it.label ) }" placeholder="Label">
					${ it.type === 'custom' ? `<input class="minn-input mono" id="minn-mi-url" value="${ esc( it.url ) }" placeholder="https://…">` : '' }
					<button class="minn-btn-primary" data-misave="${ it.id }">Save</button>
					<button class="minn-btn-soft" id="minn-mi-cancel">Cancel</button>
				</div>
			</div>` : `
			<div class="minn-menu-row" data-mi="${ it.id }" style="padding-left:${ 16 + depth * 26 }px;">
				<div class="minn-menu-info">
					<span class="minn-row-title">${ esc( it.label ) }</span>
					<span class="minn-menu-kind">${ esc( typeLabel( it ) ) }</span>
					<span class="minn-row-slug minn-cell-clip">${ esc( it.url.replace( B.site.url, '/' ) ) }</span>
				</div>
				<div class="minn-menu-ctrls">
					<button class="minn-icon-btn sm" data-mimove="up" title="Move up">↑</button>
					<button class="minn-icon-btn sm" data-mimove="down" title="Move down">↓</button>
					<button class="minn-icon-btn sm" data-mimove="out" title="Outdent"${ depth ? '' : ' disabled' }>⇤</button>
					<button class="minn-icon-btn sm" data-mimove="in" title="Make child of the item above">⇥</button>
					<button class="minn-icon-btn sm danger" data-midel="${ it.id }" title="Remove from menu">✕</button>
				</div>
			</div>` ).join( '' ) : '<div class="minn-empty">This menu is empty — add pages or links below.</div>' }
			${ ms.itemsTotal > 100 ? `<div class="minn-empty" style="padding:10px 0 2px;">Showing the first 100 of ${ ms.itemsTotal } items.</div>` : '' }
		</div>
		<div class="minn-card minn-panel-pad minn-menu-add">
			<div class="minn-panel-title" style="margin-bottom:10px;">Add to menu</div>
			<div class="minn-menu-add-row">
				<div class="minn-ac" id="minn-menu-pick">
					<input class="minn-input minn-ac-input" placeholder="${ ms.pick ? 'Find a page or post…' : 'Loading pages…' }" autocomplete="off" spellcheck="false" role="combobox" aria-expanded="false">
					<div class="minn-ac-panel" hidden></div>
				</div>
				<button class="minn-btn-soft" id="minn-menu-add-content">${ icon( 'plus' ) } Add</button>
			</div>
			<div class="minn-menu-add-row">
				<input class="minn-input" id="minn-menu-link-label" placeholder="Link label">
				<input class="minn-input mono" id="minn-menu-link-url" placeholder="https://…">
				<button class="minn-btn-soft" id="minn-menu-add-link">${ icon( 'plus' ) } Add link</button>
			</div>
		</div>
		<div class="minn-menu-manage">
			<button class="minn-btn-soft" id="minn-menu-rename">Rename “${ esc( cur ? cur.name : '' ) }”</button>
			<button class="minn-btn-soft danger" id="minn-menu-delete">${ icon( 'trash' ) } Delete menu</button>
		</div>` }`;

		$$( '[data-menu]', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', () => {
				ms.sel = parseInt( btn.dataset.menu, 10 );
				ms.items = null;
				ms.editing = null;
				renderMenus();
			} )
		);
		$( '#minn-menu-new' ).addEventListener( 'click', async () => {
			const name = prompt( 'Name for the new menu:' );
			if ( ! name || ! name.trim() ) return;
			try {
				const m = await api( 'wp/v2/menus', { method: 'POST', body: JSON.stringify( { name: name.trim() } ) } );
				toast( 'Menu created' );
				ms.menus = null;
				ms.sel = m.id;
				ms.items = null;
				renderMenus();
			} catch ( e ) {
				toast( e.message, true );
			}
		} );
		if ( ! ms.menus.length ) return;

		$$( '.minn-loc-ac', view ).forEach( ( wrap ) => {
			const slug = wrap.dataset.loc;
			const loc = ( ms.locations || {} )[ slug ] || {};
			bindAutocomplete( wrap,
				[ { value: '', label: '— none —' }, ...ms.menus.map( ( m ) => ( { value: String( m.id ), label: m.name } ) ) ],
				{
					strict: true,
					value: String( loc.menu || '' ),
					onPick: async ( v ) => {
						const target = parseInt( v, 10 ) || 0;
						const holder = ms.menus.find( ( m ) => ( m.locations || [] ).includes( slug ) );
						try {
							if ( target ) {
								const menu = ms.menus.find( ( m ) => m.id === target );
								await api( `wp/v2/menus/${ target }`, { method: 'POST', body: JSON.stringify( { locations: [ ...( menu.locations || [] ), slug ] } ) } );
							} else if ( holder ) {
								await api( `wp/v2/menus/${ holder.id }`, { method: 'POST', body: JSON.stringify( { locations: ( holder.locations || [] ).filter( ( l ) => l !== slug ) } ) } );
							}
							toast( 'Location updated' );
							ms.menus = null;
							renderMenus();
						} catch ( e ) {
							toast( e.message, true );
						}
					},
				}
			);
		} );

		const findItem = ( id ) => ms.items.find( ( x ) => x.id === id );
		$$( '[data-mimove]', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', () => {
				const id = parseInt( btn.closest( '[data-mi]' ).dataset.mi, 10 );
				const dirKey = btn.dataset.mimove;
				menuShapeAction( ms, () => {
					const it = findItem( id );
					const flatNow = menuTree( ms.items );
					const idx = flatNow.findIndex( ( f ) => f.it.id === id );
					if ( dirKey === 'up' || dirKey === 'down' ) {
						const sibs = flatNow.filter( ( f ) => f.it.parent === it.parent ).map( ( f ) => f.it );
						const i = sibs.indexOf( it );
						const j = i + ( dirKey === 'up' ? -1 : 1 );
						if ( j < 0 || j >= sibs.length ) throw new Error( 'Already at the edge.' );
						const tmp = sibs[ i ].order;
						sibs[ i ].order = sibs[ j ].order;
						sibs[ j ].order = tmp;
					} else if ( dirKey === 'in' ) {
						// New parent = the item visually above, one level shallower or equal.
						const above = flatNow.slice( 0, idx ).reverse().find( ( f ) => f.it.parent === it.parent && f.it.id !== it.id );
						if ( ! above ) throw new Error( 'Nothing above to nest under.' );
						it.parent = above.it.id;
						it.order = 9999;
					} else if ( dirKey === 'out' ) {
						const p = findItem( it.parent );
						if ( ! p ) throw new Error( 'Already top-level.' );
						it.parent = p.parent;
						it.order = p.order + 0.5;
					}
				} );
			} )
		);
		$$( '[data-midel]', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', async () => {
				const id = parseInt( btn.dataset.midel, 10 );
				const it = findItem( id );
				const kids = ms.items.filter( ( x ) => x.parent === id );
				if ( ! confirm( `Remove “${ it.label }” from the menu?${ kids.length ? ' Its sub-items move up a level.' : '' }` ) ) return;
				btn.disabled = true;
				try {
					// Reparent children first so they don't orphan to the root randomly.
					for ( const kid of kids ) {
						await api( `wp/v2/menu-items/${ kid.id }`, { method: 'POST', body: JSON.stringify( { parent: it.parent } ) } );
					}
					await api( `wp/v2/menu-items/${ id }?force=true`, { method: 'DELETE' } );
					toast( 'Removed from menu' );
				} catch ( e ) {
					toast( e.message, true );
				}
				await loadMenuItems().catch( showErr );
				if ( state.route === 'menus' ) renderMenus();
			} )
		);
		// Row click (not on a control) opens the inline label/URL editor.
		$$( '.minn-menu-row[data-mi]', view ).forEach( ( row ) =>
			row.addEventListener( 'click', ( e ) => {
				if ( e.target.closest( 'button' ) ) return;
				ms.editing = parseInt( row.dataset.mi, 10 );
				renderMenus();
			} )
		);
		const miCancel = $( '#minn-mi-cancel', view );
		if ( miCancel ) miCancel.addEventListener( 'click', () => { ms.editing = null; renderMenus(); } );
		const miSave = $( '[data-misave]', view );
		if ( miSave ) miSave.addEventListener( 'click', async () => {
			const id = parseInt( miSave.dataset.misave, 10 );
			const payload = { title: $( '#minn-mi-label' ).value.trim() };
			const urlInput = $( '#minn-mi-url' );
			if ( urlInput ) payload.url = urlInput.value.trim();
			miSave.disabled = true;
			try {
				await api( `wp/v2/menu-items/${ id }`, { method: 'POST', body: JSON.stringify( payload ) } );
				toast( 'Item updated' );
				ms.editing = null;
				await loadMenuItems();
			} catch ( e ) {
				toast( e.message, true );
			}
			if ( state.route === 'menus' ) renderMenus();
		} );

		const addItem = async ( payload, btn ) => {
			btn.disabled = true;
			try {
				await api( 'wp/v2/menu-items', { method: 'POST', body: JSON.stringify( {
					menus: ms.sel,
					status: 'publish',
					menu_order: ( ms.items.length ? Math.max( ...ms.items.map( ( x ) => x.order ) ) : 0 ) + 1,
					...payload,
				} ) } );
				toast( 'Added to menu' );
				await loadMenuItems();
			} catch ( e ) {
				toast( e.message, true );
			}
			if ( state.route === 'menus' ) renderMenus();
		};
		const pickWrap = $( '#minn-menu-pick', view );
		if ( pickWrap && ms.pick ) {
			bindAutocomplete( pickWrap, ms.pick.map( ( p ) => ( { value: p.key, label: `${ p.title } — ${ p.kind }` } ) ), { strict: true, value: '' } );
		}
		$( '#minn-menu-add-content' ).addEventListener( 'click', ( e ) => {
			const key = pickWrap && $( '.minn-ac-input', pickWrap ).dataset.acValue;
			const p = ( ms.pick || [] ).find( ( x ) => x.key === key );
			if ( ! p ) { toast( 'Pick a page or post first', true ); return; }
			// No title: WP then tracks the post's own title automatically.
			addItem( { type: 'post_type', object: p.object, object_id: p.id }, e.currentTarget );
		} );
		$( '#minn-menu-add-link' ).addEventListener( 'click', ( e ) => {
			const label = $( '#minn-menu-link-label' ).value.trim();
			const url = $( '#minn-menu-link-url' ).value.trim();
			if ( ! label || ! url ) { toast( 'A label and URL are both needed', true ); return; }
			addItem( { type: 'custom', title: label, url }, e.currentTarget );
		} );

		$( '#minn-menu-rename' ).addEventListener( 'click', async () => {
			const name = prompt( 'New name for this menu:', cur ? cur.name : '' );
			if ( ! name || ! name.trim() || ( cur && name.trim() === cur.name ) ) return;
			try {
				await api( `wp/v2/menus/${ ms.sel }`, { method: 'POST', body: JSON.stringify( { name: name.trim() } ) } );
				toast( 'Menu renamed' );
				ms.menus = null;
				renderMenus();
			} catch ( e ) {
				toast( e.message, true );
			}
		} );
		$( '#minn-menu-delete' ).addEventListener( 'click', async () => {
			if ( ! confirm( `Delete the menu “${ cur ? cur.name : '' }” and all its items? This cannot be undone.` ) ) return;
			try {
				await api( `wp/v2/menus/${ ms.sel }?force=true`, { method: 'DELETE' } );
				toast( 'Menu deleted' );
				ms.menus = null;
				ms.sel = null;
				ms.items = null;
				renderMenus();
			} catch ( e ) {
				toast( e.message, true );
			}
		} );
	}

	/* ===== Widgets (classic sidebars) ===== */

	// Widget types Minn can edit in place: instance.raw fields per id_base.
	const WIDGET_EDITABLE = {
		block: [ { key: 'content', label: 'Content (block markup or HTML)', tall: true } ],
		text: [ { key: 'title', label: 'Title' }, { key: 'text', label: 'Text', tall: true } ],
		custom_html: [ { key: 'title', label: 'Title' }, { key: 'content', label: 'HTML', tall: true } ],
	};

	function widgetsState() {
		if ( ! state.widgetsData ) {
			state.widgetsData = { sidebars: null, widgets: null, types: null };
		}
		return state.widgetsData;
	}

	async function loadWidgets() {
		const ws = widgetsState();
		const [ sidebars, widgets, types ] = await Promise.all( [
			api( 'wp/v2/sidebars?context=edit' ),
			api( 'wp/v2/widgets?context=edit&per_page=100' ),
			api( 'wp/v2/widget-types?_fields=id,name' ).catch( () => [] ),
		] );
		ws.sidebars = sidebars;
		ws.widgets = widgets;
		ws.types = {};
		( Array.isArray( types ) ? types : Object.values( types ) ).forEach( ( t ) => { ws.types[ t.id ] = t.name; } );
	}

	function widgetPreview( w ) {
		const raw = w.instance && w.instance.raw;
		if ( raw ) {
			const text = [ raw.title, raw.text || raw.content ].filter( Boolean ).join( ' — ' );
			if ( text ) return stripTags( text ).replace( /\s+/g, ' ' ).trim().slice( 0, 80 );
		}
		return stripTags( w.rendered || '' ).replace( /\s+/g, ' ' ).trim().slice( 0, 80 );
	}

	async function reloadWidgets() {
		const ws = widgetsState();
		ws.sidebars = null;
		await loadWidgets().catch( showErr );
		if ( state.route === 'widgets' ) renderWidgets();
	}

	function renderWidgets() {
		const view = $( '#minn-view' );
		const ws = widgetsState();
		if ( ! ws.sidebars ) {
			view.innerHTML = '<div class="minn-loading">Loading widgets…</div>';
			loadWidgets().then( renderIfCurrent( 'widgets' ) ).catch( showErr );
			return;
		}
		const active = ws.sidebars.filter( ( s ) => s.id !== 'wp_inactive_widgets' );
		const inactive = ws.sidebars.find( ( s ) => s.id === 'wp_inactive_widgets' );
		const widgetsOf = ( s ) => ( s.widgets || [] ).map( ( id ) => ws.widgets.find( ( w ) => w.id === id ) ).filter( Boolean );
		const moveTargets = ( fromId ) => ws.sidebars.filter( ( s ) => s.id !== fromId );
		const sidebarCard = ( s, isInactive ) => {
			const items = widgetsOf( s );
			return `
			<div class="minn-card minn-panel-pad minn-widget-area${ isInactive ? ' inactive' : '' }" data-sidebar="${ esc( s.id ) }">
				<div class="minn-widget-area-head">
					<div class="minn-panel-title">${ esc( s.name || s.id ) }</div>
					${ isInactive ? '' : `<button class="minn-btn-soft" data-wadd="${ esc( s.id ) }">${ icon( 'plus' ) } Add</button>` }
				</div>
				${ s.description && ! isInactive ? `<div class="minn-toggle-desc" style="margin:-4px 0 10px;">${ esc( stripTags( s.description ) ) }</div>` : '' }
				${ items.length ? items.map( ( w, i ) => `
				<div class="minn-widget-row" data-widget="${ esc( w.id ) }">
					<div class="minn-widget-info">
						<span class="minn-row-title">${ esc( ws.types[ w.id_base ] || w.id_base ) }</span>
						<span class="minn-row-slug minn-cell-clip">${ esc( widgetPreview( w ) || '—' ) }</span>
					</div>
					<div class="minn-menu-ctrls">
						<button class="minn-icon-btn sm" data-wmove="up" title="Move up"${ i === 0 ? ' disabled' : '' }>↑</button>
						<button class="minn-icon-btn sm" data-wmove="down" title="Move down"${ i === items.length - 1 ? ' disabled' : '' }>↓</button>
						<select class="minn-input minn-widget-moveto" title="Move to…">
							<option value="">Move to…</option>
							${ moveTargets( s.id ).map( ( t ) => `<option value="${ esc( t.id ) }">${ esc( t.name || t.id ) }</option>` ).join( '' ) }
						</select>
						${ WIDGET_EDITABLE[ w.id_base ] && w.instance && w.instance.raw ? `<button class="minn-btn-soft" data-wedit="${ esc( w.id ) }">Edit</button>` : '' }
						<button class="minn-icon-btn sm danger" data-wdel="${ esc( w.id ) }" title="Delete widget">✕</button>
					</div>
				</div>` ).join( '' ) : `<div class="minn-empty" style="padding:14px 0;">${ isInactive ? 'Nothing parked here.' : 'No widgets in this area.' }</div>` }
			</div>`;
		};
		view.innerHTML = `
		${ active.map( ( s ) => sidebarCard( s, false ) ).join( '' ) }
		${ inactive && ( inactive.widgets || [] ).length ? sidebarCard( inactive, true ) : '' }
		${ ! active.length ? '<div class="minn-card minn-empty">The active theme registers no widget areas.</div>' : '' }`;

		$$( '[data-wadd]', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', async () => {
				btn.disabled = true;
				try {
					// A block widget is the closest to "just write something" —
					// it takes any block markup or plain HTML. Created in TWO
					// requests: creating straight into a sidebar gets undone by
					// the same request's Allow-header permissions check, whose
					// retrieve_widgets() sweeps the not-yet-registered instance
					// to wp_inactive_widgets (core #53657 territory). A second
					// request sees the instance registered and the move sticks.
					const w = await api( 'wp/v2/widgets', { method: 'POST', body: JSON.stringify( {
						id_base: 'block',
						instance: { raw: { content: '' } },
					} ) } );
					const moved = await api( `wp/v2/widgets/${ w.id }`, { method: 'POST', body: JSON.stringify( { sidebar: btn.dataset.wadd } ) } );
					await reloadWidgets();
					state.modal = { type: 'widget', widget: moved };
					renderOverlays();
				} catch ( e ) {
					toast( e.message, true );
					btn.disabled = false;
				}
			} )
		);
		$$( '[data-wedit]', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', () => {
				const w = ws.widgets.find( ( x ) => x.id === btn.dataset.wedit );
				if ( w ) {
					state.modal = { type: 'widget', widget: w };
					renderOverlays();
				}
			} )
		);
		$$( '[data-wdel]', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', async () => {
				if ( ! confirm( 'Delete this widget? Its settings are lost.' ) ) return;
				btn.disabled = true;
				try {
					await api( `wp/v2/widgets/${ btn.dataset.wdel }?force=true`, { method: 'DELETE' } );
					toast( 'Widget deleted' );
				} catch ( e ) {
					toast( e.message, true );
				}
				reloadWidgets();
			} )
		);
		$$( '[data-wmove]', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', async () => {
				const sid = btn.closest( '[data-sidebar]' ).dataset.sidebar;
				const wid = btn.closest( '[data-widget]' ).dataset.widget;
				const s = ws.sidebars.find( ( x ) => x.id === sid );
				const arr = [ ...( s.widgets || [] ) ];
				const i = arr.indexOf( wid );
				const j = i + ( btn.dataset.wmove === 'up' ? -1 : 1 );
				if ( i === -1 || j < 0 || j >= arr.length ) return;
				[ arr[ i ], arr[ j ] ] = [ arr[ j ], arr[ i ] ];
				btn.disabled = true;
				try {
					await api( `wp/v2/sidebars/${ sid }`, { method: 'POST', body: JSON.stringify( { widgets: arr } ) } );
				} catch ( e ) {
					toast( e.message, true );
				}
				reloadWidgets();
			} )
		);
		$$( '.minn-widget-moveto', view ).forEach( ( sel ) =>
			sel.addEventListener( 'change', async () => {
				if ( ! sel.value ) return;
				const wid = sel.closest( '[data-widget]' ).dataset.widget;
				try {
					await api( `wp/v2/widgets/${ wid }`, { method: 'POST', body: JSON.stringify( { sidebar: sel.value } ) } );
					toast( 'Widget moved' );
				} catch ( e ) {
					toast( e.message, true );
				}
				reloadWidgets();
			} )
		);
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
					// A plugin flip can change what the app shows elsewhere —
					// traffic provider on Overview, registered post types, CPT tabs.
					state.cache.overview = null;
					bustTypeCaches();
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
					state.cache.overview = null;
					bustTypeCaches();
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
				state.modal = { type: 'plugin-install', q: '', results: null, searching: false, page: 1, pages: 1, total: 0 };
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

	/* ===== Post types =====
	 * Directory of registered post types with definition editing through
	 * whichever manager owns each one (ACF / CPT UI / Minn's own store —
	 * see class-minn-admin-cpt.php). Code-registered types are read-only. */

	const CPT_SOURCE_LABEL = { core: 'WordPress', code: 'Code', acf: 'ACF', cptui: 'CPT UI', minn: 'Minn' };
	const CPT_SUPPORTS = [
		[ 'title', 'Title' ], [ 'editor', 'Editor' ], [ 'thumbnail', 'Featured image' ],
		[ 'excerpt', 'Excerpt' ], [ 'custom-fields', 'Custom fields' ], [ 'comments', 'Comments' ],
		[ 'revisions', 'Revisions' ], [ 'page-attributes', 'Page attributes' ], [ 'author', 'Author' ],
	];

	async function loadPostTypes() {
		state.cache.postTypes = await api( 'minn-admin/v1/post-types' );
	}

	async function loadTaxonomies() {
		state.cache.taxonomies = await api( 'minn-admin/v1/taxonomies' );
	}

	function renderPostTypes() {
		const view = $( '#minn-view' );
		const c = state.cache.postTypes;
		const taxTab = state.ptTab === 'taxonomies';
		const tx = state.cache.taxonomies;
		if ( ! c || ( taxTab && ! tx ) ) {
			view.innerHTML = '<div class="minn-loading">Loading…</div>';
			Promise.all( [ c ? null : loadPostTypes(), taxTab && ! tx ? loadTaxonomies() : null ] )
				.then( renderIfCurrent( 'posttypes' ) ).catch( showErr );
			return;
		}
		const tabs = `<div class="minn-tabs">
			<button class="minn-tab${ taxTab ? '' : ' active' }" data-pttab="types">Post Types</button>
			<button class="minn-tab${ taxTab ? ' active' : '' }" data-pttab="taxonomies">Taxonomies</button>
		</div>`;

		if ( taxTab ) {
			// Attached-to labels resolve through the types list.
			const typeLabel = ( slug ) => {
				const t = c.types.find( ( x ) => x.slug === slug );
				return t ? t.plural : slug;
			};
			view.innerHTML = `
			<div class="minn-toolbar">
				${ tabs }
				<div class="minn-toolbar-meta">${ tx.taxonomies.length } taxonom${ tx.taxonomies.length === 1 ? 'y' : 'ies' }</div>
				<button class="minn-btn-soft" id="minn-add-tax">${ icon( 'plus' ) } Add taxonomy</button>
			</div>
			<div class="minn-card minn-table">
				<div class="minn-table-head minn-cpt-cols">
					<div>Name</div><div>Attached to</div><div>Managed by</div><div>Terms</div><div>Type</div><div></div>
				</div>
				${ tx.taxonomies.map( ( t ) => `
				<div class="minn-table-row minn-cpt-cols" data-tax="${ esc( t.slug ) }">
					<div class="minn-cell-clip">
						<div class="minn-row-title">${ esc( t.plural ) }</div>
						<div class="minn-row-slug">${ esc( t.slug ) }</div>
					</div>
					<div class="minn-row-meta minn-cell-clip">${ esc( t.object_types.map( typeLabel ).join( ', ' ) || '—' ) }</div>
					<div><span class="minn-status ${ t.editable ? 'publish' : 'draft' }">${ esc( CPT_SOURCE_LABEL[ t.source ] || t.source ) }</span></div>
					<div class="minn-row-meta">${ t.count }</div>
					<div class="minn-row-meta">${ t.hierarchical ? 'Categories' : 'Tags' }</div>
					<div class="minn-row-arrow">›</div>
				</div>` ).join( '' ) }
			</div>`;

			$( '#minn-add-tax', view ).addEventListener( 'click', () => {
				state.modal = { type: 'tax', item: null, backends: tx.backends, types: c.types };
				renderOverlays();
			} );
			$$( '.minn-table-row', view ).forEach( ( row ) =>
				row.addEventListener( 'click', () => {
					const t = tx.taxonomies.find( ( x ) => x.slug === row.dataset.tax );
					if ( t ) {
						state.modal = { type: 'tax', item: t, backends: tx.backends, types: c.types };
						renderOverlays();
					}
				} )
			);
		} else {
			view.innerHTML = `
			<div class="minn-toolbar">
				${ tabs }
				<div class="minn-toolbar-meta">${ c.types.length } post type${ c.types.length === 1 ? '' : 's' }</div>
				<button class="minn-btn-soft" id="minn-add-cpt">${ icon( 'plus' ) } Add post type</button>
			</div>
			<div class="minn-card minn-table">
				<div class="minn-table-head minn-cpt-cols">
					<div>Name</div><div>Slug</div><div>Managed by</div><div>Items</div><div>REST</div><div></div>
				</div>
				${ c.types.map( ( t ) => `
				<div class="minn-table-row minn-cpt-cols" data-cpt="${ esc( t.slug ) }">
					<div class="minn-cell-clip">
						<div class="minn-row-title">${ esc( t.plural ) }</div>
						<div class="minn-row-slug">${ esc( t.singular ) }</div>
					</div>
					<div class="minn-row-meta"><span class="minn-permalink">${ esc( t.slug ) }</span></div>
					<div><span class="minn-status ${ t.editable ? 'publish' : 'draft' }">${ esc( CPT_SOURCE_LABEL[ t.source ] || t.source ) }</span></div>
					<div class="minn-row-meta">${ t.count }</div>
					<div class="minn-row-meta" title="${ t.show_in_rest ? 'Available over the REST API (editable in Minn)' : 'Not exposed over REST — Minn can’t list or edit its content' }">${ t.show_in_rest ? '✓' : '—' }</div>
					<div class="minn-row-arrow">›</div>
				</div>` ).join( '' ) }
			</div>`;

			$( '#minn-add-cpt', view ).addEventListener( 'click', () => {
				state.modal = { type: 'cpt', item: null, backends: c.backends, catalog: c.taxCatalog || [] };
				renderOverlays();
			} );
			$$( '.minn-table-row', view ).forEach( ( row ) =>
				row.addEventListener( 'click', () => {
					const t = c.types.find( ( x ) => x.slug === row.dataset.cpt );
					if ( t ) {
						state.modal = { type: 'cpt', item: t, backends: c.backends, catalog: c.taxCatalog || [] };
						renderOverlays();
					}
				} )
			);
		}

		$$( '[data-pttab]', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', () => {
				state.ptTab = btn.dataset.pttab;
				renderPostTypes();
			} )
		);
	}

	// A definition changed — the Content view's type tabs must refetch.
	function bustTypeCaches() {
		state.cache.postTypes = null;
		state.cache.taxonomies = null;
		typesPromise = null;
		state.cache.types = null;
		state.cache.cptContent = {};
	}

	/* ===== Settings ===== */

	const SETTINGS_SECTIONS = [ 'General', 'Writing', 'Reading', 'Discussion', 'Permalinks' ];
	const POST_FORMATS = [ 'standard', 'aside', 'chat', 'gallery', 'link', 'image', 'quote', 'status', 'video', 'audio' ];
	const PERMALINK_PRESETS = [
		[ '', 'Plain' ],
		[ '/%year%/%monthnum%/%day%/%postname%/', 'Day and name' ],
		[ '/%year%/%monthnum%/%postname%/', 'Month and name' ],
		[ '/archives/%post_id%', 'Numeric' ],
		[ '/%postname%/', 'Post name' ],
	];

	async function loadSettings() {
		const [ values, categories, pages, permalinks ] = await Promise.all( [
			api( 'wp/v2/settings' ),
			api( 'wp/v2/categories?per_page=100&_fields=id,name' ).catch( () => [] ),
			api( 'wp/v2/pages?per_page=100&status=publish&orderby=title&order=asc&_fields=id,title' ).catch( () => [] ),
			api( 'minn-admin/v1/permalinks' ).catch( () => null ),
		] );
		const siteIcon = values.site_icon
			? await api( `wp/v2/media/${ values.site_icon }?_fields=id,source_url,media_details` )
				.then( ( m ) => ( { url: ( m.media_details && m.media_details.sizes && m.media_details.sizes.thumbnail && m.media_details.sizes.thumbnail.source_url ) || m.source_url } ) )
				.catch( () => null )
			: null;
		state.cache.settings = { values, categories, pages, permalinks, siteIcon };
	}

	/**
	 * Minimal combobox: a filtered option panel anchored in-flow directly
	 * below the input — it can never drift like the native datalist popup —
	 * that opens on focus/click even when the value is already complete.
	 * Arrow keys + Enter select; Escape/Tab/blur close. `options` are
	 * { value, label } pairs; matching normalizes _ and / to spaces so
	 * "new york" finds America/New_York.
	 *
	 * Two modes:
	 *   free   (default) the input IS the value — for open vocabularies like
	 *          timezone ids, where typing the exact value is legitimate.
	 *   strict a themed replacement for <select>: the input displays the
	 *          option LABEL while the picked VALUE rides on
	 *          input.dataset.acValue; typing only filters, and blurring
	 *          without a pick snaps the display back to the selection.
	 */
	function bindAutocomplete( wrap, options, opts = {} ) {
		const input = $( '.minn-ac-input', wrap );
		const panel = $( '.minn-ac-panel', wrap );
		if ( ! input || ! panel ) return;
		let idx = -1;
		const norm = ( v ) => String( v ).toLowerCase().replace( /[_/]/g, ' ' );
		const labelOf = ( v ) => {
			const o = options.find( ( x ) => String( x.value ) === String( v ) );
			return o ? o.label : String( v );
		};
		let selected = null;
		if ( opts.strict ) {
			selected = opts.value != null ? String( opts.value ) : ( options.length ? String( options[ 0 ].value ) : '' );
			input.value = labelOf( selected );
			input.dataset.acValue = selected;
		}
		const isCurrent = ( o ) => ( opts.strict ? String( o.value ) === selected : o.value === input.value );
		// Opening (focus/click) browses the FULL list with the current value
		// highlighted; filtering starts only once the user actually types.
		const render = ( browseAll ) => {
			const q = browseAll ? '' : norm( input.value.trim() );
			const matches = options.filter( ( o ) => ! q || norm( o.value ).includes( q ) || norm( o.label ).includes( q ) );
			idx = -1;
			panel.innerHTML = ( matches.slice( 0, 500 ).map( ( o ) =>
				`<div class="minn-ac-item${ isCurrent( o ) ? ' current' : '' }" data-acv="${ esc( o.value ) }">${ esc( o.label ) }</div>` ).join( '' )
				|| '<div class="minn-ac-empty">No matches</div>' )
				+ ( matches.length > 500 ? `<div class="minn-ac-empty">${ matches.length - 500 } more — keep typing…</div>` : '' );
			panel.hidden = false;
			input.setAttribute( 'aria-expanded', 'true' );
			const cur = $( '.minn-ac-item.current', panel );
			if ( cur ) cur.scrollIntoView( { block: 'nearest' } );
		};
		const close = () => {
			panel.hidden = true;
			idx = -1;
			input.setAttribute( 'aria-expanded', 'false' );
			// Strict mode never leaves free text behind.
			if ( opts.strict ) input.value = labelOf( selected );
		};
		const pick = ( v ) => {
			if ( opts.strict ) {
				selected = String( v );
				input.dataset.acValue = selected;
			} else {
				input.value = v;
			}
			close();
			if ( opts.onPick ) opts.onPick( v );
		};
		input.addEventListener( 'focus', () => render( true ) );
		input.addEventListener( 'click', () => { if ( panel.hidden ) render( true ); } );
		input.addEventListener( 'input', () => render( false ) );
		input.addEventListener( 'keydown', ( e ) => {
			if ( e.key === 'Escape' ) { close(); return; }
			if ( e.key === 'Tab' ) { close(); return; }
			const items = $$( '.minn-ac-item', panel );
			if ( panel.hidden || ! items.length ) return;
			if ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) {
				e.preventDefault();
				idx = e.key === 'ArrowDown' ? Math.min( idx + 1, items.length - 1 ) : Math.max( idx - 1, 0 );
				items.forEach( ( el, i ) => el.classList.toggle( 'active', i === idx ) );
				items[ idx ].scrollIntoView( { block: 'nearest' } );
			} else if ( e.key === 'Enter' ) {
				// enterPicksFirst:false (tags) — plain Enter submits the typed text
				// (a possibly-new term); only an arrowed-to item is picked here.
				const target = items[ idx ] || ( opts.enterPicksFirst === false ? null : items[ 0 ] );
				if ( ! target ) return;
				e.preventDefault();
				pick( target.dataset.acv );
			}
		} );
		// mousedown (not click) + preventDefault: select before blur can close the panel.
		panel.addEventListener( 'mousedown', ( e ) => {
			const item = e.target.closest( '.minn-ac-item' );
			if ( item ) { e.preventDefault(); pick( item.dataset.acv ); }
		} );
		input.addEventListener( 'blur', () => setTimeout( close, 120 ) );
	}

	// Options for the settings comboboxes, registered by settingsFields on
	// each render and consumed by renderSettings when binding.
	let settingsCombos = {};

	function settingsFields( section, s, cache ) {
		settingsCombos = {};
		// Strict combobox — a themed, searchable <select> replacement for
		// option lists that can grow unbounded (roles, categories, pages).
		const combo = ( key, label, options, current ) => {
			settingsCombos[ key ] = {
				options: options.map( ( [ v, l ] ) => ( { value: v, label: l } ) ),
				value: current,
			};
			return `<div>
				<div class="minn-field-label">${ label }</div>
				<div class="minn-ac" data-combo="${ esc( key ) }">
					<input class="minn-input minn-ac-input" data-key="${ esc( key ) }" autocomplete="off" spellcheck="false" role="combobox" aria-expanded="false">
					<div class="minn-ac-panel" hidden></div>
				</div>
			</div>`;
		};
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

		const iconUrl = cache.siteIcon && cache.siteIcon.url;
		const siteIconField = `
			<div>
				<div class="minn-field-label">Site icon</div>
				<div class="minn-icon-drop" id="minn-icon-drop">
					<img class="minn-icon-preview" id="minn-icon-img" alt="Site icon" src="${ esc( iconUrl || '' ) }"${ iconUrl ? '' : ' hidden' }>
					<div class="minn-icon-empty" id="minn-icon-empty"${ iconUrl ? ' hidden' : '' }>✦</div>
					<div class="minn-icon-info">
						<div class="minn-toggle-desc">Shown in browser tabs, bookmarks and app icons. Square image, 512×512 or larger. Drag &amp; drop an image here, or</div>
						<div class="minn-icon-btns">
							<button class="minn-btn-soft" id="minn-icon-pick" type="button">Choose image</button>
							<button class="minn-btn-soft danger" id="minn-icon-remove" type="button"${ iconUrl ? '' : ' hidden' }>Remove</button>
						</div>
					</div>
				</div>
			</div>`;
		const roleOptions = Object.entries( B.roles || {} );

		switch ( section ) {
			case 'General': return {
				sub: 'Basic information about your site.',
				fields: text( 'title', 'Site title', s.title )
					+ text( 'description', 'Tagline', s.description )
					+ siteIconField
					+ text( 'url', 'Site address', s.url, true )
					+ text( 'email', 'Administration email', s.email, true )
					// Custom autocomplete, not a select or datalist — 400+ zones need
					// type-to-filter, and the native datalist popup positions itself
					// erratically and vanishes once the value is complete. The panel
					// is anchored in-flow below the input (never shifts) and opens on
					// click even with a full value. Validated on save.
					+ `<div>
						<div class="minn-field-label">Timezone</div>
						<div class="minn-ac" id="minn-tz-ac">
							<input class="minn-input minn-ac-input" data-key="timezone" value="${ esc( s.timezone || 'UTC' ) }" placeholder="Start typing — e.g. Chicago, Berlin, UTC" autocomplete="off" spellcheck="false" role="combobox" aria-expanded="false">
							<div class="minn-ac-panel" hidden></div>
						</div>
					</div>`
					+ text( 'date_format', 'Date format', s.date_format, true )
					+ text( 'time_format', 'Time format', s.time_format, true )
					+ select( 'start_of_week', 'Week starts on', DAYS.map( ( d, i ) => [ i, d ] ), s.start_of_week )
					+ ( roleOptions.length ? combo( 'default_role', 'New user default role', roleOptions, s.default_role || 'subscriber' ) : '' ),
				toggles: [
					{ id: 'users_can_register', label: 'Membership', desc: 'Anyone can register an account.', on: !! s.users_can_register },
					{ id: 'minn_admin_maintenance', label: 'Maintenance mode', desc: 'Show a coming-soon page to visitors.', on: !! s.minn_admin_maintenance },
				].map( toggle ).join( '' ),
			};
			case 'Writing': return {
				sub: 'Defaults for new posts.',
				fields: combo( 'default_category', 'Default post category', cache.categories.map( ( c ) => [ c.id, decodeEntities( c.name ) ] ), s.default_category )
					+ select( 'default_post_format', 'Default post format', POST_FORMATS.map( ( f ) => [ f, f.charAt( 0 ).toUpperCase() + f.slice( 1 ) ] ), s.default_post_format || 'standard' ),
				toggles: [ { id: 'use_smilies', label: 'Convert emoticons', desc: 'Turn :-) and :-P into graphics when displayed.', on: !! s.use_smilies } ].map( toggle ).join( '' ),
			};
			case 'Reading': return {
				sub: 'What visitors see, and who else can see it.',
				fields: select( 'show_on_front', 'Your homepage displays', [ [ 'posts', 'Latest posts' ], [ 'page', 'A static page' ] ], s.show_on_front )
					+ ( s.show_on_front === 'page' ? combo( 'page_on_front', 'Homepage', pageOptions, s.page_on_front ) + combo( 'page_for_posts', 'Posts page', pageOptions, s.page_for_posts ) : '' )
					+ text( 'posts_per_page', 'Blog pages show at most', s.posts_per_page ),
				toggles: [ { id: 'blog_public', label: 'Search engine visibility', desc: 'Allow search engines to index this site.', on: !! s.blog_public } ].map( toggle ).join( '' ),
			};
			case 'Discussion': return {
				sub: 'How comments and pingbacks behave on new posts.',
				fields: '',
				toggles: [
					{ id: 'default_comment_status', label: 'Allow comments', desc: 'Let readers respond to new posts.', on: s.default_comment_status === 'open' },
					{ id: 'default_ping_status', label: 'Allow pingbacks & trackbacks', desc: 'Accept link notifications from other blogs on new posts.', on: s.default_ping_status === 'open' },
					{ id: 'comment_moderation', label: 'Moderate all comments', desc: 'Every comment must be manually approved before it appears.', on: !! s.comment_moderation },
					{ id: 'comment_registration', label: 'Registered users only', desc: 'Users must be registered and logged in to comment.', on: !! s.comment_registration },
					{ id: 'show_avatars', label: 'Show avatars', desc: 'Display profile pictures next to comments.', on: !! s.show_avatars },
				].map( toggle ).join( '' ),
			};
			default: {
				const pl = cache.permalinks;
				if ( ! pl ) return {
					sub: 'Permalink settings couldn’t be loaded.',
					fields: `<div class="minn-editor-locked-note">Manage the permalink structure in the classic admin instead. <a href="${ esc( B.site.adminUrl ) }options-permalink.php">Open permalink settings ↗</a></div>`,
					toggles: '',
					noSave: true,
				};
				const isPreset = PERMALINK_PRESETS.some( ( [ v ] ) => v === pl.structure );
				return {
					sub: 'URL structure for posts, categories and tags. Saving rebuilds the rewrite rules.',
					fields: select( '_preset', 'Structure', [ ...PERMALINK_PRESETS, [ '_custom', 'Custom structure' ] ], isPreset ? pl.structure : '_custom' )
						+ text( 'structure', 'Custom structure', pl.structure, true )
						+ `<div class="minn-toggle-desc">Tags: %year% %monthnum% %day% %postname% %post_id% %category% %author%. Note: with Plain permalinks, Minn itself moves from /minn-admin/ to ?minn_admin=1 — the app reloads there after saving.</div>`
						+ text( 'category_base', 'Category base (optional)', pl.category_base, true )
						+ text( 'tag_base', 'Tag base (optional)', pl.tag_base, true ),
					toggles: '',
				};
			}
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
		// Options stored as "0"/"1" strings in wp_options — registered as integer.
		const INT_TOGGLES = [ 'blog_public', 'users_can_register', 'comment_moderation', 'comment_registration', 'show_avatars' ];
		$$( '[data-setting]', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', () => {
				btn.classList.toggle( 'on' );
				const on = btn.classList.contains( 'on' );
				btn.setAttribute( 'aria-checked', on );
				const id = btn.dataset.setting;
				pending[ id ] = OPEN_CLOSED.includes( id ) ? ( on ? 'open' : 'closed' )
					: ( INT_TOGGLES.includes( id ) ? ( on ? 1 : 0 ) : on );
			} )
		);

		// Site icon: pick from the library, drag & drop an upload, or remove.
		// The chosen attachment ID rides the normal save as pending.site_icon.
		const iconDrop = $( '#minn-icon-drop', view );
		if ( iconDrop ) {
			const setIcon = ( id, url ) => {
				pending.site_icon = id;
				cache.siteIcon = url ? { url } : null;
				const img = $( '#minn-icon-img', view );
				const empty = $( '#minn-icon-empty', view );
				const rm = $( '#minn-icon-remove', view );
				if ( url ) { img.src = url; }
				img.hidden = ! url;
				empty.hidden = !! url;
				rm.hidden = ! url;
			};
			$( '#minn-icon-pick', view ).addEventListener( 'click', () => openMediaPicker( ( it ) => setIcon( it.id, it.thumb || it.url ) ) );
			$( '#minn-icon-remove', view ).addEventListener( 'click', () => setIcon( 0, null ) );
			const uploadIcon = async ( file ) => {
				if ( ! file || ! file.type.startsWith( 'image/' ) ) { toast( 'Drop an image file', true ); return; }
				iconDrop.classList.add( 'minn-busy' );
				try {
					const fd = new FormData();
					fd.append( 'file', file );
					const m = await api( 'wp/v2/media', { method: 'POST', body: fd } );
					const sizes = m.media_details && m.media_details.sizes;
					setIcon( m.id, ( sizes && sizes.thumbnail && sizes.thumbnail.source_url ) || m.source_url );
					toast( 'Icon uploaded — save to apply' );
				} catch ( e ) {
					toast( e.message, true );
				}
				iconDrop.classList.remove( 'minn-busy' );
			};
			// stopPropagation keeps the app-wide drop-to-media-library handler out of it.
			iconDrop.addEventListener( 'dragover', ( e ) => { e.preventDefault(); e.stopPropagation(); iconDrop.classList.add( 'over' ); } );
			iconDrop.addEventListener( 'dragleave', () => iconDrop.classList.remove( 'over' ) );
			iconDrop.addEventListener( 'drop', ( e ) => {
				e.preventDefault();
				e.stopPropagation();
				iconDrop.classList.remove( 'over' );
				document.body.classList.remove( 'minn-dragging' );
				uploadIcon( e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[ 0 ] );
			} );
		}

		// Re-render Reading when the homepage mode flips so page pickers appear.
		const showOnFront = $( '[data-key="show_on_front"]', view );
		if ( showOnFront ) {
			showOnFront.addEventListener( 'change', () => {
				cache.values.show_on_front = showOnFront.value;
				renderSettings();
			} );
		}

		// Timezone combobox (General section) — free mode, the id is the value.
		const tzWrap = $( '#minn-tz-ac', view );
		if ( tzWrap ) {
			let zones = [ 'UTC' ];
			try { zones = [ 'UTC', ...Intl.supportedValuesOf( 'timeZone' ) ]; } catch ( e ) {}
			const cur = cache.values.timezone;
			if ( cur && ! zones.includes( cur ) ) zones.unshift( cur );
			bindAutocomplete( tzWrap, zones.map( ( z ) => ( { value: z, label: z.replace( /_/g, ' ' ) } ) ) );
		}
		// Strict comboboxes (role, category, homepage pages) registered by settingsFields.
		$$( '[data-combo]', view ).forEach( ( wrap ) => {
			const def = settingsCombos[ wrap.dataset.combo ];
			if ( def ) bindAutocomplete( wrap, def.options, { strict: true, value: def.value } );
		} );

		// Permalinks: keep the preset select and the custom-structure input in sync.
		const presetSel = $( '[data-key="_preset"]', view );
		if ( presetSel ) {
			const structInput = $( '[data-key="structure"]', view );
			presetSel.addEventListener( 'change', () => {
				if ( presetSel.value !== '_custom' ) structInput.value = presetSel.value;
			} );
			structInput.addEventListener( 'input', () => {
				presetSel.value = PERMALINK_PRESETS.some( ( [ v ] ) => v === structInput.value ) ? structInput.value : '_custom';
			} );
		}

		const saveBtn = $( '#minn-save-settings', view );
		if ( saveBtn ) {
			saveBtn.addEventListener( 'click', async () => {
				saveBtn.disabled = true;
				if ( state.settingsSection === 'Permalinks' ) {
					const payload = {};
					$$( '[data-key]', view ).forEach( ( input ) => {
						if ( input.dataset.key !== '_preset' ) payload[ input.dataset.key ] = input.value;
					} );
					try {
						const r = await api( 'minn-admin/v1/permalinks', { method: 'POST', body: JSON.stringify( payload ) } );
						cache.permalinks = r;
						if ( r.pretty !== !! B.pretty ) {
							// Routing mode flipped (path ↔ ?minn_admin=1) — reload at the app's new home.
							toast( 'Permalinks saved — reloading…' );
							setTimeout( () => { window.location.href = r.app_url + ( r.pretty ? 'settings' : '#/settings' ); }, 700 );
							return;
						}
						toast( 'Permalinks saved' );
						renderSettings();
					} catch ( err ) {
						toast( err.message, true );
					}
					saveBtn.disabled = false;
					return;
				}
				const NUMERIC = [ 'default_category', 'posts_per_page', 'page_on_front', 'page_for_posts', 'start_of_week' ];
				const payload = { ...pending };
				$$( '[data-key]', view ).forEach( ( input ) => {
					const key = input.dataset.key;
					// Strict comboboxes display the label; the value rides on data-ac-value.
					let value = input.dataset.acValue !== undefined ? input.dataset.acValue : input.value;
					if ( key === 'url' && value.trim() === s.url ) return;
					if ( NUMERIC.includes( key ) ) value = parseInt( value, 10 ) || 0;
					payload[ key ] = value;
				} );
				// The timezone field is free-typed with datalist suggestions —
				// only let a real zone id (or a case-corrected match) through.
				if ( 'timezone' in payload ) {
					payload.timezone = payload.timezone.trim();
					let zones = [ 'UTC', s.timezone ];
					try { zones = zones.concat( Intl.supportedValuesOf( 'timeZone' ) ); } catch ( e2 ) {}
					const match = zones.find( ( z ) => z && ( z === payload.timezone || z.toLowerCase() === payload.timezone.toLowerCase().replace( / /g, '_' ) ) );
					if ( ! match ) {
						toast( `“${ payload.timezone }” isn’t a timezone — pick one from the suggestions.`, true );
						saveBtn.disabled = false;
						return;
					}
					payload.timezone = match;
				}
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
	const SIMPLE_BLOCKS = [ 'paragraph', 'heading', 'quote', 'code', 'preformatted', 'verse', 'list', 'list-item', 'image', 'table', 'html', 'separator', 'more', 'video', 'audio' ];

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
	const EDITABLE_ATTRS = { heading: [ 'level' ], list: [ 'ordered' ], table: [ 'hasFixedLayout' ], code: [ 'language' ] };

	// Blocks whose attributes ride through editing verbatim: the comment JSON is
	// parked on the element as data-minn-attrs and re-emitted byte-faithfully on
	// serialize. Only non-text-flow blocks — typing can't split them, so the
	// attribute marker can't be duplicated by contenteditable. This is what lets
	// real Gutenberg images ({"id":…,"sizeSlug":…}) stay editable instead of
	// becoming islands.
	const PASSTHROUGH_BLOCKS = [ 'image', 'table', 'quote', 'separator', 'verse', 'preformatted', 'video', 'audio' ];

	// Attributes JSON from a segment's opening block comment ({} when absent/invalid;
	// null distinguishes "invalid JSON" for segmentEditable's bail-out).
	function segmentAttrs( seg ) {
		const m = seg.raw.match( /^<!--\s*wp:[a-z0-9\/_-]+\s+(\{[\s\S]*?\})\s*\/?-->/ );
		if ( ! m ) return {};
		try {
			return JSON.parse( m[ 1 ] );
		} catch ( e ) {
			return null;
		}
	}

	function segmentEditable( seg ) {
		const name = seg.name.replace( /^core\//, '' );
		if ( ! SIMPLE_BLOCKS.includes( name ) ) return false;
		const attrs = segmentAttrs( seg );
		if ( attrs === null ) return false;
		if ( PASSTHROUGH_BLOCKS.includes( name ) ) return true; // attrs re-emitted verbatim
		const allowed = EDITABLE_ATTRS[ name ] || [];
		return Object.keys( attrs ).every( ( k ) => allowed.includes( k ) );
	}

	// Build the contenteditable HTML: simple blocks stripped of comments,
	// complex blocks as atomic islands whose raw markup is stored verbatim.
	function buildEditableContent( ed, raw ) {
		const segments = tokenizeBlocks( raw ) || [];
		ed.islands = [];
		return segments.map( ( seg ) => {
			if ( seg.type === 'html' ) return seg.raw;
			if ( segmentEditable( seg ) ) {
				let html = stripBlockComments( seg.raw );
				const name = seg.name.replace( /^core\//, '' );
				// Code blocks that keep their language in the comment attr (the
				// {"language":"sql"} dialect): surface it as a language-* class so the
				// picker and highlighter see it, and mark the pre so serialization
				// restores the attr dialect instead of persisting the class.
				if ( name === 'code' ) {
					const lang = String( ( segmentAttrs( seg ) || {} ).language || '' ).toLowerCase();
					if ( /^[a-z0-9-]+$/.test( lang ) ) {
						html = html.replace( '<pre class="wp-block-code"', '<pre data-lang-attr="1" class="wp-block-code"' );
						if ( ! /language-/.test( html ) ) html = html.replace( /<code(\s|>)/, `<code class="language-${ lang }"$1` );
					}
				} else if ( PASSTHROUGH_BLOCKS.includes( name ) ) {
					// Park the comment attrs on the element; serialization re-emits them.
					const attrs = segmentAttrs( seg );
					if ( attrs && Object.keys( attrs ).length ) {
						html = html.replace( /<([a-z][a-z0-9]*)/i, `<$1 data-minn-attrs="${ esc( JSON.stringify( attrs ) ) }"` );
					}
				}
				return html;
			}
			const idx = ed.islands.push( seg.raw ) - 1;
			return islandHtml( idx, seg.name, seg.raw );
		} ).join( '\n' );
	}

	// The contenteditable=false card an island renders as. Shared by content
	// loading and slash-menu insertion of custom blocks.
	function islandHtml( idx, name, raw ) {
		const inner = stripBlockComments( raw ).trim();
		return `<div class="minn-block-island" contenteditable="false" data-island="${ idx }" data-block="${ esc( name ) }">
			<button class="minn-island-chip" data-inspect="${ idx }" title="Configure block" type="button">⚙ ${ esc( name.replace( /^core\//, '' ) ) }</button>
			<div class="minn-island-preview" data-preview="${ idx }">${ inner || '<div class="minn-island-empty">Dynamic block — rendered on the site</div>' }</div>
		</div>`;
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
		// serializeBlockAttrs applies Gutenberg's comment-safe escaping ("--", <, >, &).
		const pushBlock = ( name, attrs, html ) =>
			out.push( `<!-- wp:${ name }${ serializeBlockAttrs( attrs && Object.keys( attrs ).length ? attrs : null ) } -->\n${ html }\n<!-- /wp:${ name } -->` );
		// Passthrough attrs parked by buildEditableContent; consumed off the clone
		// so the live DOM keeps its marker for later autosaves.
		const takeMinnAttrs = ( el ) => {
			const raw = el.dataset ? el.dataset.minnAttrs : null;
			if ( ! raw ) return null;
			el.removeAttribute( 'data-minn-attrs' );
			try {
				return JSON.parse( raw );
			} catch ( e ) {
				return null;
			}
		};

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
				const pa = takeMinnAttrs( el );
				el.classList.add( 'wp-block-quote' );
				const cite = el.querySelector( ':scope > cite' );
				const paras = Array.from( el.children ).filter( ( ch ) => ch.tagName === 'P' );
				const inner = paras.length
					? paras.map( ( p ) => `<!-- wp:paragraph -->\n${ p.outerHTML }\n<!-- /wp:paragraph -->` ).join( '' )
					: `<!-- wp:paragraph -->\n<p>${ cite ? '' : el.innerHTML }</p>\n<!-- /wp:paragraph -->`;
				pushBlock( 'quote', pa, `<blockquote class="${ el.className }">${ inner }${ cite ? cite.outerHTML : '' }</blockquote>` );
			} else if ( tag === 'pre' && el.classList.contains( 'wp-block-verse' ) ) {
				const pa = takeMinnAttrs( el );
				pushBlock( 'verse', pa, `<pre class="${ el.className }">${ el.innerHTML }</pre>` );
			} else if ( tag === 'pre' && el.classList.contains( 'wp-block-preformatted' ) ) {
				const pa = takeMinnAttrs( el );
				pushBlock( 'preformatted', pa, `<pre class="${ el.className }">${ el.innerHTML }</pre>` );
			} else if ( tag === 'pre' ) {
				// Plain text so syntax-highlight spans never reach the database;
				// the language class is preserved (Prism-style, theme-compatible).
				const code = el.querySelector( 'code' );
				const text = codeTextOf( code || el );
				const lang = codeLangOf( el );
				if ( el.dataset.langAttr === '1' ) {
					// Came in as {"language":…} in the comment — keep that dialect so the
					// block stays valid for whatever registered the attribute.
					pushBlock( 'code', lang !== 'auto' ? { language: lang } : null, `<pre class="wp-block-code"><code>${ esc( text ) }</code></pre>` );
				} else {
					pushBlock( 'code', null, `<pre class="wp-block-code"><code${ lang !== 'auto' ? ` class="language-${ lang }"` : '' }>${ esc( text ) }</code></pre>` );
				}
			} else if ( ( tag === 'figure' && el.querySelector( 'table' ) ) || tag === 'table' ) {
				const pa = takeMinnAttrs( el ) || {};
				const table = tag === 'table' ? el : el.querySelector( 'table' );
				table.removeAttribute( 'data-hl' );
				const fixed = table.classList.contains( 'has-fixed-layout' );
				// The class on the element is the live truth for hasFixedLayout —
				// but an explicitly-written false stays explicit (newer Gutenberg
				// defaults tables to fixed, so absent ≠ false).
				if ( fixed ) pa.hasFixedLayout = true;
				else if ( 'hasFixedLayout' in pa ) pa.hasFixedLayout = false;
				const figClass = tag === 'figure' && el.className ? el.className : 'wp-block-table';
				const caption = tag === 'figure' ? el.querySelector( ':scope > figcaption' ) : null;
				pushBlock(
					'table',
					pa,
					`<figure class="${ figClass }"><table${ table.className ? ` class="${ table.className }"` : '' }>${ table.innerHTML }</table>${ caption ? caption.outerHTML : '' }</figure>`
				);
			} else if ( tag === 'ul' || tag === 'ol' ) {
				el.classList.add( 'wp-block-list' );
				const items = Array.from( el.querySelectorAll( ':scope > li' ) )
					.map( ( li ) => `<!-- wp:list-item -->\n${ li.outerHTML }\n<!-- /wp:list-item -->` ).join( '' );
				pushBlock( 'list', tag === 'ol' ? { ordered: true } : null, `<${ tag } class="${ el.className }">${ items }</${ tag }>` );
			} else if ( tag === 'figure' && el.querySelector( 'video' ) ) {
				const pa = takeMinnAttrs( el );
				el.classList.add( 'wp-block-video' );
				pushBlock( 'video', pa, el.outerHTML );
			} else if ( tag === 'figure' && el.querySelector( 'audio' ) ) {
				const pa = takeMinnAttrs( el );
				el.classList.add( 'wp-block-audio' );
				pushBlock( 'audio', pa, el.outerHTML );
			} else if ( tag === 'video' ) {
				const pa = takeMinnAttrs( el );
				pushBlock( 'video', pa, `<figure class="wp-block-video">${ el.outerHTML }</figure>` );
			} else if ( tag === 'audio' ) {
				const pa = takeMinnAttrs( el );
				pushBlock( 'audio', pa, `<figure class="wp-block-audio">${ el.outerHTML }</figure>` );
			} else if ( tag === 'figure' && el.querySelector( 'img' ) ) {
				const pa = takeMinnAttrs( el );
				el.classList.add( 'wp-block-image' );
				pushBlock( 'image', pa, el.outerHTML );
			} else if ( tag === 'img' ) {
				const pa = takeMinnAttrs( el );
				pushBlock( 'image', pa, `<figure class="wp-block-image">${ el.outerHTML }</figure>` );
			} else if ( tag === 'hr' ) {
				const pa = takeMinnAttrs( el );
				pushBlock( 'separator', pa, pa ? el.outerHTML : '<hr class="wp-block-separator has-alpha-channel-opacity"/>' );
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
	const CODE_LANGS = [ 'auto', 'php', 'js', 'html', 'markup', 'css', 'bash', 'json', 'python', 'sql' ];

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
		// 'markup' is Prism's name for HTML/XML.
		if ( lang === 'html' || lang === 'markup' ) return highlightHtml( text );
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
			const p = await api( `wp/v2/${ state.editorType }/${ state.editorId }?context=edit&_fields=id,title,content.raw,status,slug,link,categories,tags,date,featured_media,parent,menu_order,template${ extraKeys }` );
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
				// Page attributes — presence in the (allowlisted) response tells
				// which of them this post type actually supports.
				parent: p.parent || 0,
				menuOrder: p.menu_order || 0,
				template: p.template || '',
				supportsParent: 'parent' in p,
				supportsOrder: 'menu_order' in p,
				templates: null,
				parentPick: null,
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
			loadPageAttrs( state.editor );
			// Revision history (types without revision support 404 — that's fine).
			// Revisions expose an `author` ID but no author link, so _embed can't
			// resolve names — look them up via the users endpoint instead.
			api( `wp/v2/${ state.editorType }/${ p.id }/revisions?per_page=6&_fields=id,modified,author` )
				.then( async ( revs ) => {
					const names = {};
					if ( B.user && B.user.id ) names[ B.user.id ] = B.user.name;
					const unknown = [ ...new Set( revs.map( ( r ) => r.author ).filter( ( a ) => a > 0 && ! names[ a ] ) ) ];
					if ( unknown.length ) {
						await api( `wp/v2/users?include=${ unknown.join( ',' ) }&_fields=id,name` )
							.then( ( users ) => ( Array.isArray( users ) ? users : [] ).forEach( ( u ) => { names[ u.id ] = u.name; } ) )
							.catch( () => {} );
					}
					if ( state.editor && state.editor.id === p.id ) {
						state.editor.revisions = revs.map( ( r ) => ( {
							id: r.id,
							modified: r.modified,
							author: names[ r.author ] || '',
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
			// New content — pages when the New menu (or /editor/pages) asked for
			// them and the user can edit pages; everything else starts as a post.
			const newType = state.editorType === 'pages' && B.caps.editPages ? 'pages' : 'posts';
			state.editor = {
				id: null, type: newType, title: '', content: '', status: 'draft', mode: 'blocks',
				date: null, newDate: null, slug: '', link: '', savedAt: null, categoryIds: new Set(),
				tagIds: new Set(), tags: [],
				revisions: null, panels: null,
				supportsThumb: true, featuredMedia: 0, featuredThumb: null,
				parent: 0, menuOrder: 0, template: '', supportsParent: newType === 'pages', supportsOrder: newType === 'pages', templates: null, parentPick: null,
			};
			loadEditorPanels( state.editor, null );
			loadPageAttrs( state.editor );
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
		if ( ed.parentDirty ) payload.parent = ed.parent || 0;
		if ( ed.templateDirty ) payload.template = ed.template || '';
		if ( ed.orderDirty ) payload.menu_order = ed.menuOrder || 0;
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
			ed.parentDirty = false;
			ed.templateDirty = false;
			ed.orderDirty = false;
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

	// Data behind the Page attributes card: theme templates for the post type
	// (cached per session) and, for hierarchical types, the parent candidates.
	async function loadPageAttrs( ed ) {
		const typeSlug = ed.type === 'pages' ? 'page'
			: ed.type === 'posts' ? 'post'
			: ( ( ( state.cache.types || [] ).find( ( t ) => t.restBase === ed.type ) || {} ).slug || ed.type );
		const jobs = [];
		state.cache.templates = state.cache.templates || {};
		if ( state.cache.templates[ typeSlug ] == null ) {
			jobs.push( api( `minn-admin/v1/templates?type=${ encodeURIComponent( typeSlug ) }` )
				.then( ( r ) => { state.cache.templates[ typeSlug ] = r.templates || []; } )
				.catch( () => { state.cache.templates[ typeSlug ] = []; } ) );
		}
		if ( ed.supportsParent ) {
			const statuses = 'publish,future,draft,pending' + ( B.caps.readPrivate ? ',private' : '' );
			jobs.push( api( `wp/v2/${ ed.type }?context=edit&status=${ statuses }&per_page=100&orderby=title&order=asc&_fields=id,title,parent` )
				.then( ( items ) => {
					ed.parentPick = ( Array.isArray( items ) ? items : [] ).map( ( x ) => ( {
						id: x.id,
						title: decodeEntities( ( x.title && ( x.title.raw != null ? x.title.raw : x.title.rendered ) ) || '' ) || '(no title)',
						parent: x.parent || 0,
					} ) );
				} )
				.catch( () => { ed.parentPick = []; } ) );
		}
		await Promise.all( jobs );
		ed.templates = state.cache.templates[ typeSlug ] || [];
		if ( state.route === 'editor' && state.editor === ed ) renderEditorSide();
	}

	// Parent choices as a depth-indented tree, excluding the post itself and
	// its descendants (assigning those would create a cycle).
	function parentOptions( ed ) {
		const items = ed.parentPick || [];
		const excluded = new Set( ed.id ? [ ed.id ] : [] );
		let grew = true;
		while ( grew ) {
			grew = false;
			items.forEach( ( it ) => {
				if ( ! excluded.has( it.id ) && excluded.has( it.parent ) ) {
					excluded.add( it.id );
					grew = true;
				}
			} );
		}
		const byParent = new Map();
		items.forEach( ( it ) => {
			if ( excluded.has( it.id ) ) return;
			if ( ! byParent.has( it.parent ) ) byParent.set( it.parent, [] );
			byParent.get( it.parent ).push( it );
		} );
		const opts = [ { value: '0', label: '— none —' } ];
		const walk = ( pid, depth ) => {
			( byParent.get( pid ) || [] ).forEach( ( it ) => {
				// Depth marker is an em space — ASCII spaces would collapse in the panel HTML.
				opts.push( { value: String( it.id ), label: ' '.repeat( depth ) + it.title } );
				walk( it.id, depth + 1 );
			} );
		};
		walk( 0, 0 );
		// Anything whose parent wasn't loaded (deep sites, >100 items) still shows, flat.
		items.forEach( ( it ) => {
			if ( ! excluded.has( it.id ) && ! opts.some( ( o ) => o.value === String( it.id ) ) ) {
				opts.push( { value: String( it.id ), label: it.title } );
			}
		} );
		return opts;
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
					<div class="minn-ac" id="minn-tag-ac">
						<input class="minn-input minn-ac-input minn-tag-input" id="minn-editor-tag-input" placeholder="Add a tag, press Enter" autocomplete="off" spellcheck="false">
						<div class="minn-ac-panel" hidden></div>
					</div>
				</div>` : '' }
				${ ed.link && ed.status === 'publish' ? `<div><a href="${ esc( ed.link ) }" target="_blank" rel="noopener">View ${ ed.type === 'pages' ? 'page' : 'post' } ↗</a></div>` : '' }
			</div>
		</div>
		${ ed.supportsParent || ( ed.templates && ed.templates.length ) ? `
		<div class="minn-side-card">
			<div class="minn-side-title">Page attributes</div>
			<div style="display:flex; flex-direction:column; gap:11px; font-size:13.5px; color:var(--text2);">
				${ ed.supportsParent ? `<div>Parent
					<div class="minn-ac" id="minn-parent-ac" style="margin-top:5px;">
						<input class="minn-input minn-ac-input" placeholder="${ ed.parentPick ? '— none —' : 'Loading…' }" autocomplete="off" spellcheck="false" role="combobox" aria-expanded="false">
						<div class="minn-ac-panel" hidden></div>
					</div>
				</div>` : '' }
				${ ed.templates && ed.templates.length ? `<div>Template
					<div class="minn-ac" id="minn-template-ac" style="margin-top:5px;">
						<input class="minn-input minn-ac-input" placeholder="Default template" autocomplete="off" spellcheck="false" role="combobox" aria-expanded="false">
						<div class="minn-ac-panel" hidden></div>
					</div>
				</div>` : '' }
				${ ed.supportsOrder ? `<div>Order
					<input type="number" class="minn-input" id="minn-order-input" value="${ ed.menuOrder }" style="margin-top:5px;">
				</div>` : '' }
			</div>
		</div>` : '' }
		${ ( ed.panels || [] ).map( ( p ) => panelCard( ed, p ) ).join( '' ) }
		${ ed.id ? '<button class="minn-trash-link" id="minn-trash-post">Move to trash</button>' : '' }`;

		const parentWrap = $( '#minn-parent-ac', el );
		if ( parentWrap && ed.parentPick ) {
			bindAutocomplete( parentWrap, parentOptions( ed ), {
				strict: true,
				value: String( ed.parent || 0 ),
				onPick: ( v ) => {
					ed.parent = parseInt( v, 10 ) || 0;
					ed.parentDirty = true;
					if ( ed.id ) scheduleAutosave();
				},
			} );
		}
		const tplWrap = $( '#minn-template-ac', el );
		if ( tplWrap ) {
			bindAutocomplete( tplWrap,
				[ { value: '', label: 'Default template' }, ...( ed.templates || [] ).map( ( t ) => ( { value: t.file, label: t.name } ) ) ],
				{
					strict: true,
					value: ed.template || '',
					onPick: ( v ) => {
						ed.template = v;
						ed.templateDirty = true;
						if ( ed.id ) scheduleAutosave();
					},
				}
			);
		}
		const orderInput = $( '#minn-order-input', el );
		if ( orderInput ) orderInput.addEventListener( 'input', () => {
			ed.menuOrder = parseInt( orderInput.value, 10 ) || 0;
			ed.orderDirty = true;
			if ( ed.id ) scheduleAutosave();
		} );

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
			const tagWrap = $( '#minn-tag-ac', el );
			const tagOptions = ( state.cache.postTerms ? state.cache.postTerms.tags : [] )
				.map( ( t ) => ( { value: t.name, label: t.count != null ? `${ t.name } (${ t.count })` : t.name } ) );
			bindAutocomplete( tagWrap, tagOptions, {
				enterPicksFirst: false,
				onPick: ( v ) => { tagInput.value = ''; addEditorTag( v ); },
			} );
			tagInput.addEventListener( 'keydown', ( e ) => {
				if ( e.defaultPrevented ) return; // the autocomplete picked an item
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
		renderIslandPreviews( body, ed );
		// Island chips open the block inspector (works in locked mode too — read-only there is fine
		// because locked posts never send content, but islands only exist in blocks mode anyway).
		body.addEventListener( 'click', ( e ) => {
			const chip = e.target.closest( '.minn-island-chip' );
			if ( ! chip ) return;
			e.preventDefault();
			const island = chip.closest( '.minn-block-island' );
			if ( island ) openInspector( island );
		} );
		// Clicking an editable image opens its controls popover.
		body.addEventListener( 'click', ( e ) => {
			const img = e.target.closest( 'img' );
			if ( img && body.contains( img ) && ! img.closest( '.minn-block-island' ) ) {
				openImgPop( img );
			}
		} );
		// Hovering an editable code block surfaces its config chip.
		body.addEventListener( 'mouseover', ( e ) => {
			const pre = e.target.closest( 'pre' );
			if ( pre && body.contains( pre ) && ! pre.closest( '.minn-block-island' )
				&& ! pre.classList.contains( 'wp-block-verse' ) && ! pre.classList.contains( 'wp-block-preformatted' ) ) {
				showCodeChip( pre );
			}
		} );

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
			if ( currentPre ) {
				select.value = codeLangOf( currentPre );
				showCodeChip( currentPre ); // keyboard/touch path to the config popout
			}
		};

		if ( window._minnLangSync ) document.removeEventListener( 'selectionchange', window._minnLangSync );
		window._minnLangSync = sync;
		document.addEventListener( 'selectionchange', sync );

		select.addEventListener( 'change', () => {
			if ( ! currentPre ) return;
			const pre = currentPre;
			setCodeLang( pre, select.value );
			// Re-highlighting rebuilds the block — put the caret back at its end.
			const range = document.createRange();
			range.selectNodeContents( pre.querySelector( 'code' ) || pre );
			range.collapse( false );
			const s = window.getSelection();
			s.removeAllRanges();
			s.addRange( range );
		} );
	}

	/* ===== Block inspector (islands) =====
	 * Islands stay atomic, but server-registered blocks can be configured in
	 * place: schema from wp/v2/block-types drives a generated form, edits
	 * rewrite the attributes JSON in the island's stored raw markup, and the
	 * preview refreshes via minn-admin/v1/render-blocks (do_blocks server-side).
	 * See docs/block-inspector.md. */

	// Gutenberg plumbing attrs a config form shouldn't expose.
	const BLOCK_ATTR_SKIP = [ 'lock', 'metadata', 'className', 'style', 'anchor' ];

	const fullBlockName = ( name ) => ( name.includes( '/' ) ? name : 'core/' + name );
	// Form refinements plugins registered via the minn_admin_block_forms filter.
	const blockFormFor = ( name ) => ( B.blockForms || {} )[ fullBlockName( name ) ] || {};

	async function blockTypeFor( name ) {
		const full = fullBlockName( name );
		const cache = state.cache.blockTypes || ( state.cache.blockTypes = {} );
		if ( ! ( full in cache ) ) {
			cache[ full ] = await api( 'wp/v2/block-types/' + full ).catch( () => null );
		}
		return cache[ full ];
	}

	// Split a block's raw markup into open comment / inner / close comment.
	// The open-comment regex mirrors tokenizeBlocks (attrs can't contain "-->",
	// Gutenberg escapes it to --).
	function blockParts( raw ) {
		const m = raw.match( /^<!--\s*wp:([a-z][a-z0-9_-]*(?:\/[a-z][a-z0-9_-]*)?)\s*((?:(?!-->)[\s\S])*?)(\/)?\s*-->/ );
		if ( ! m ) return null;
		let attrs = {};
		const json = m[ 2 ].trim();
		if ( json ) {
			try { attrs = JSON.parse( json ); } catch ( e ) { return null; }
		}
		if ( m[ 3 ] ) return { name: m[ 1 ], attrs, selfClosing: true, open: m[ 0 ], inner: '', close: '' };
		const close = raw.match( /<!--\s*\/wp:[a-z][a-z0-9_/-]*\s*-->\s*$/ );
		if ( ! close ) return null;
		return {
			name: m[ 1 ], attrs, selfClosing: false,
			open: m[ 0 ],
			inner: raw.slice( m[ 0 ].length, close.index ),
			close: raw.slice( close.index ),
		};
	}

	// Gutenberg's serializeAttributes escaping — keeps "-->" (and HTML-ish
	// chars) out of the comment so the block can't break the document.
	function serializeBlockAttrs( attrs ) {
		if ( ! attrs || ! Object.keys( attrs ).length ) return '';
		return ' ' + JSON.stringify( attrs )
			.replace( /--/g, '\\u002d\\u002d' )
			.replace( /</g, '\\u003c' )
			.replace( />/g, '\\u003e' )
			.replace( /&/g, '\\u0026' )
			.replace( /\\"/g, '\\u0022' );
	}

	const buildOpenComment = ( name, attrs, selfClosing ) =>
		`<!-- wp:${ name }${ serializeBlockAttrs( attrs ) } ${ selfClosing ? '/' : '' }-->`;

	// Form rows for one block's editable attributes. `prefix` namespaces the
	// inputs ("own" or a child index). A minn_admin_block_forms descriptor for
	// the block refines labels, controls, options, ordering and hiding.
	function inspectorFields( defs, attrs, prefix, blockName ) {
		const form = blockName ? blockFormFor( blockName ) : {};
		const fdefs = form.attributes || {};
		const rows = [];
		const keys = Object.keys( defs || {} );
		const ordered = [
			...( Array.isArray( form.order ) ? form.order.filter( ( k ) => keys.includes( k ) ) : [] ),
			...keys.filter( ( k ) => ! ( Array.isArray( form.order ) && form.order.includes( k ) ) ),
		];
		ordered.forEach( ( key ) => {
			const def = defs[ key ] || {};
			const fd = fdefs[ key ] || {};
			// Sourced attrs live in the block's saved HTML, not the comment —
			// rewriting them there would do nothing. A descriptor that explicitly
			// declares a field overrides the plumbing skip-list (anchor/callout's
			// "style" is its own string attribute, not Gutenberg's style object).
			if ( ( BLOCK_ATTR_SKIP.includes( key ) && ! fdefs[ key ] ) || def.source || fd.hide ) return;
			const type = Array.isArray( def.type ) ? def.type[ 0 ] : def.type;
			const cur = attrs && key in attrs ? attrs[ key ] : def.default;
			const id = `${ prefix }:${ key }`;
			const label = esc( fd.label || key );
			// Descriptor options are [value, label] pairs; schema enums are bare values.
			const options = Array.isArray( fd.options ) && fd.options.length
				? fd.options.map( ( o ) => ( Array.isArray( o ) ? o : [ o, o ] ) )
				: ( Array.isArray( def.enum ) && def.enum.length ? def.enum.map( ( v ) => [ v, v ] ) : null );
			const control = fd.control || ( options ? 'select'
				: ( type === 'boolean' ? 'checkbox'
				: ( type === 'number' || type === 'integer' ? 'number'
				: ( type === 'string' || type == null
					? ( key === 'content' || String( cur == null ? '' : cur ).length > 60 ? 'textarea' : 'text' )
					: null ) ) ) );
			if ( ! control ) return; // object / array attrs — too structural for a generic form
			if ( control === 'select' && options ) {
				rows.push( `<div class="minn-field-label">${ label }</div>
				<select class="minn-input" data-insp="${ esc( id ) }">
					${ options.map( ( [ v, l ] ) => `<option value="${ esc( v ) }"${ String( v ) === String( cur == null ? '' : cur ) ? ' selected' : '' }>${ esc( l ) }</option>` ).join( '' ) }
				</select>` );
			} else if ( control === 'checkbox' ) {
				rows.push( `<label class="minn-insp-check"><input type="checkbox" class="minn-cb" data-insp="${ esc( id ) }" data-type="boolean"${ cur ? ' checked' : '' }> ${ label }</label>` );
			} else if ( control === 'number' ) {
				rows.push( `<div class="minn-field-label">${ label }</div>
				<input type="number" class="minn-input" data-insp="${ esc( id ) }" data-type="number" value="${ cur == null ? '' : esc( cur ) }">` );
			} else if ( control === 'textarea' ) {
				rows.push( `<div class="minn-field-label">${ label }</div>
				<textarea class="minn-input minn-insp-textarea" data-insp="${ esc( id ) }">${ esc( cur == null ? '' : String( cur ) ) }</textarea>` );
			} else {
				rows.push( `<div class="minn-field-label">${ label }</div>
				<input class="minn-input" data-insp="${ esc( id ) }" value="${ esc( cur == null ? '' : String( cur ) ) }">` );
			}
		} );
		return rows.join( '' );
	}

	let inspectorEl = null;
	let inspectorState = null;
	let inspectorScrollFn = null;

	function closeInspector() {
		if ( inspectorEl ) inspectorEl.remove();
		inspectorEl = null;
		inspectorState = null;
		document.removeEventListener( 'mousedown', inspectorAway, true );
		if ( inspectorScrollFn ) {
			const scroller = document.querySelector( '.minn-scroll' );
			if ( scroller ) scroller.removeEventListener( 'scroll', inspectorScrollFn );
			inspectorScrollFn = null;
		}
	}

	function inspectorAway( e ) {
		if ( inspectorEl && ! inspectorEl.contains( e.target ) && ! e.target.closest( '.minn-island-chip' ) ) closeInspector();
	}

	function positionInspector( islandEl ) {
		if ( ! inspectorEl ) return;
		const rect = islandEl.getBoundingClientRect();
		const w = inspectorEl.offsetWidth || 320;
		const fitsRight = rect.right + 10 + w < window.innerWidth;
		inspectorEl.style.left = ( fitsRight ? rect.right + 10 : Math.max( 10, Math.min( rect.left, window.innerWidth - w - 12 ) ) ) + 'px';
		inspectorEl.style.top = Math.max( 10, Math.min( fitsRight ? rect.top : rect.bottom + 8, window.innerHeight - inspectorEl.offsetHeight - 10 ) ) + 'px';
	}

	/**
	 * Build the inspector's working model from an island's raw markup.
	 *
	 * mode 'structural': the interior is head-html + block children separated
	 * by whitespace + tail-html (the InnerBlocks shape) — safe to add/remove/
	 * reorder children, reassembling as head + children.join('\n\n') + tail.
	 * mode 'inplace': anything else with parseable children — child attrs are
	 * still editable via in-place comment rewrites, but structure is locked.
	 */
	function inspectorModel( raw ) {
		const parts = blockParts( raw );
		if ( ! parts ) return null;
		const model = { parts, ownAttrs: { ...parts.attrs }, head: '', tail: '', children: [], mode: 'none', segments: null };
		if ( ! parts.inner ) return model;
		const segments = tokenizeBlocks( parts.inner );
		if ( ! segments ) return model;
		model.segments = segments;
		let structural = true;
		segments.forEach( ( seg, i ) => {
			if ( seg.type === 'block' ) {
				const p = blockParts( seg.raw );
				if ( ! p ) { structural = false; return; }
				model.children.push( {
					name: p.name,
					attrs: { ...p.attrs },
					selfClosing: p.selfClosing,
					tail: seg.raw.slice( p.open.length ), // inner + close comment for wrapped children
					segIdx: i,
				} );
			} else if ( i === 0 ) {
				model.head = seg.raw;
			} else if ( i === segments.length - 1 ) {
				model.tail = seg.raw;
			} else if ( seg.raw.trim() !== '' ) {
				structural = false; // real HTML between children — don't reflow it
			}
		} );
		if ( model.children.length ) {
			model.mode = structural ? 'structural' : 'inplace';
			// Types a "+ Add" can create — captured now so removing the last child
			// doesn't strand the button.
			model.addTypes = [ ...new Set( model.children.map( ( c ) => c.name ) ) ];
		}

		// Declared wrapper-text edits (minn_admin_block_forms `wrapperText`):
		// each pattern is a regex with exactly three capture groups
		// (prefix)(text)(suffix); the text is replaced in place only when it
		// actually changed, so an untouched wrapper stays byte-identical.
		model.wt = [];
		( blockFormFor( parts.name ).wrapperText || [] ).forEach( ( w ) => {
			if ( ! w || ! w.pattern ) return;
			let re;
			try { re = new RegExp( w.pattern ); } catch ( e ) { return; }
			// inplace mode reassembles from segments, where a head/tail replace
			// wouldn't land — wrapper text is only offered where it can apply.
			const locs = model.mode === 'structural' ? [ [ 'head', model.head ], [ 'tail', model.tail ] ]
				: ( model.mode === 'none' ? [ [ 'inner', parts.inner ] ] : [] );
			for ( const [ loc, str ] of locs ) {
				const m = str && str.match( re );
				if ( m && m.length >= 4 ) {
					model.wt.push( { label: w.label || 'Text', pattern: w.pattern, loc, orig: m[ 2 ], value: m[ 2 ] } );
					break;
				}
			}
		} );
		return model;
	}

	// Fold form values back into attribute objects. Values equal to the schema
	// default are omitted ONLY if the attribute wasn't explicitly in the block
	// already — an untouched "color":"blue" survives an Apply byte-for-byte
	// even when blue is the default (nothing silently drops).
	function collectInspectorForms() {
		const insp = inspectorState;
		if ( ! insp || ! inspectorEl ) return;
		const fold = ( attrs, defs, target ) => {
			$$( '[data-insp]', inspectorEl ).forEach( ( input ) => {
				const [ t, key ] = input.dataset.insp.split( ':' );
				if ( t !== target ) return;
				let v;
				if ( input.dataset.type === 'boolean' ) v = input.checked;
				else if ( input.dataset.type === 'number' ) v = input.value === '' ? undefined : Number( input.value );
				else v = input.value;
				const def = ( defs || {} )[ key ] || {};
				const wasExplicit = key in attrs;
				if ( v === undefined ) {
					delete attrs[ key ]; // cleared field = remove the attribute
				} else if ( ! wasExplicit && def.default !== undefined && v === def.default ) {
					// never present and still at the default — don't add noise
				} else {
					attrs[ key ] = v;
				}
			} );
		};
		const ownType = insp.types[ insp.model.parts.name ];
		fold( insp.model.ownAttrs, ownType && ownType.attributes, 'own' );
		insp.model.children.forEach( ( c, i ) => {
			const t = insp.types[ c.name ];
			fold( c.attrs, t && t.attributes, String( i ) );
		} );
		( insp.model.wt || [] ).forEach( ( w, i ) => {
			const input = inspectorEl.querySelector( `[data-insp="wt:${ i }"]` );
			if ( input ) w.value = input.value;
		} );
	}

	function renderInspectorBody() {
		const insp = inspectorState;
		if ( ! insp || ! inspectorEl ) return;
		const { model, types } = insp;
		const ownType = types[ model.parts.name ];
		const ownFields = ( ownType && ownType.attributes ? inspectorFields( ownType.attributes, model.ownAttrs, 'own', model.parts.name ) : '' )
			+ ( model.wt || [] ).map( ( w, i ) => `<div class="minn-field-label">${ esc( w.label ) }</div>
			<input class="minn-input" data-insp="wt:${ i }" value="${ esc( w.value ) }">` ).join( '' );
		const structural = model.mode === 'structural';
		const childSections = model.children.map( ( c, i ) => {
			const t = types[ c.name ];
			const fields = t && t.attributes ? inspectorFields( t.attributes, c.attrs, String( i ), c.name ) : '';
			if ( ! fields && ! structural ) return '';
			return `<div class="minn-insp-child">
				<div class="minn-insp-child-title">
					<span>${ i + 1 }. ${ esc( c.name.replace( /^core\//, '' ) ) }</span>
					${ structural ? `<span class="minn-insp-ctl">
						<button type="button" data-cmove="${ i }:-1" title="Move up"${ i === 0 ? ' disabled' : '' }>↑</button>
						<button type="button" data-cmove="${ i }:1" title="Move down"${ i === model.children.length - 1 ? ' disabled' : '' }>↓</button>
						<button type="button" data-cdel="${ i }" title="Remove">×</button>
					</span>` : '' }
				</div>
				${ fields || '<div class="minn-insp-note">No editable settings.</div>' }
			</div>`;
		} ).join( '' );
		// "+ Add" only for types whose schema we can form-edit.
		const addable = structural ? ( model.addTypes || [] ).filter( ( n ) => types[ n ] && types[ n ].attributes ) : [];
		const addRow = addable.length ? `<div class="minn-insp-add-row">
			${ addable.length > 1 ? `<select class="minn-input" id="minn-insp-add-type">${ addable.map( ( n ) => `<option value="${ esc( n ) }">${ esc( n.replace( /^core\//, '' ) ) }</option>` ).join( '' ) }</select>` : '' }
			<button class="minn-btn-soft" type="button" id="minn-insp-add"${ addable.length === 1 ? ` data-add-type="${ esc( addable[ 0 ] ) }"` : '' }>+ Add ${ addable.length === 1 ? esc( addable[ 0 ].split( '/' ).pop() ) : 'block' }</button>
		</div>` : '';

		const editable = !! ( ownFields || childSections );
		inspectorEl.innerHTML = `
			<div class="minn-insp-head">
				<span class="minn-insp-title">${ esc( ( ownType && ownType.title ) || model.parts.name.replace( /^core\//, '' ) ) }</span>
				<button class="minn-x-btn" id="minn-insp-close" type="button">×</button>
			</div>
			<div class="minn-insp-body">
				${ ownFields }
				${ childSections }
				${ addRow }
				${ editable ? '' : `<div class="minn-insp-note">${ ownType
					? 'This block has no attributes a form can edit — its content lives in saved HTML. It stays preserved exactly as-is.'
					: 'This block type isn’t registered on this site, so its settings can’t be read. It stays preserved exactly as-is.' }</div>` }
			</div>
			<div class="minn-insp-actions">
				${ editable ? '<button class="minn-btn-primary" id="minn-insp-apply" type="button">Apply</button>' : '' }
				<button class="minn-btn-soft danger" id="minn-insp-remove" type="button" title="Remove this block">${ icon( 'trash' ) }${ editable ? '' : ' Remove block' }</button>
			</div>`;
		positionInspector( insp.islandEl );
	}

	async function openInspector( islandEl ) {
		const ed = state.editor;
		if ( ! ed ) return;
		closeInspector();
		const idx = parseInt( islandEl.dataset.island, 10 );
		const raw = ed.islands && ed.islands[ idx ];
		if ( raw == null ) return;
		const model = inspectorModel( raw );
		if ( ! model ) { toast( 'This block’s markup can’t be parsed safely.', true ); return; }

		// Placeholder while schemas load.
		inspectorEl = document.createElement( 'div' );
		inspectorEl.className = 'minn-inspector';
		inspectorEl.innerHTML = '<div class="minn-loading" style="padding:24px;">Loading block schema…</div>';
		document.body.appendChild( inspectorEl );
		positionInspector( islandEl );
		document.addEventListener( 'mousedown', inspectorAway, true );
		// Track the island while the editor scrolls under the fixed popover.
		inspectorScrollFn = () => positionInspector( islandEl );
		const scroller = document.querySelector( '.minn-scroll' );
		if ( scroller ) scroller.addEventListener( 'scroll', inspectorScrollFn, { passive: true } );

		const names = [ model.parts.name, ...model.children.map( ( c ) => c.name ) ];
		const types = {};
		await Promise.all( [ ...new Set( names ) ].map( async ( n ) => { types[ n ] = await blockTypeFor( n ); } ) );
		if ( ! inspectorEl ) return; // closed while loading

		inspectorState = { idx, model, types, islandEl };
		renderInspectorBody();

		// One delegated listener survives every structure-op re-render.
		inspectorEl.addEventListener( 'click', ( e ) => {
			const insp = inspectorState;
			if ( ! insp ) return;
			if ( e.target.closest( '#minn-insp-close' ) ) { closeInspector(); return; }
			if ( e.target.closest( '#minn-insp-remove' ) ) {
				if ( ! confirm( 'Remove this block?' ) ) return;
				const ed2 = state.editor;
				// Null (don't splice) so other islands' data-island indices stay valid.
				if ( ed2 && ed2.islands ) ed2.islands[ insp.idx ] = null;
				insp.islandEl.remove();
				closeInspector();
				toast( 'Block removed' );
				if ( ed2 && ed2.id ) scheduleAutosave();
				return;
			}
			const applyBtn = e.target.closest( '#minn-insp-apply' );
			if ( applyBtn ) { applyInspector( applyBtn ); return; }
			const move = e.target.closest( '[data-cmove]' );
			const del = e.target.closest( '[data-cdel]' );
			const add = e.target.closest( '#minn-insp-add' );
			if ( ! move && ! del && ! add ) return;
			collectInspectorForms(); // typed values survive the re-render
			if ( move ) {
				const [ i, dir ] = move.dataset.cmove.split( ':' ).map( Number );
				const j = i + dir;
				const kids = insp.model.children;
				if ( j >= 0 && j < kids.length ) [ kids[ i ], kids[ j ] ] = [ kids[ j ], kids[ i ] ];
			} else if ( del ) {
				insp.model.children.splice( parseInt( del.dataset.cdel, 10 ), 1 );
			} else if ( add ) {
				const typeSel = $( '#minn-insp-add-type', inspectorEl );
				const name = add.dataset.addType || ( typeSel && typeSel.value );
				if ( name ) insp.model.children.push( { name, attrs: {}, selfClosing: true, tail: '' } );
			}
			renderInspectorBody();
			if ( add ) {
				const body = $( '.minn-insp-body', inspectorEl );
				if ( body ) body.scrollTop = body.scrollHeight;
			}
		} );
	}

	async function applyInspector( btn ) {
		const insp = inspectorState;
		const ed = state.editor;
		if ( ! insp || ! ed || ! inspectorEl ) return;
		collectInspectorForms();
		const { model } = insp;

		const childRaw = ( c ) => {
			const open = buildOpenComment( c.name, c.attrs, c.selfClosing );
			return c.selfClosing ? open : open + c.tail;
		};

		let inner = model.parts.inner;
		// Declared wrapper-text edits: replace only when actually changed, so an
		// untouched wrapper stays byte-identical. Text-node escaping only (& < >).
		const escText = ( s ) => String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
		( model.wt || [] ).forEach( ( w ) => {
			if ( w.value === w.orig ) return;
			let re;
			try { re = new RegExp( w.pattern ); } catch ( e ) { return; }
			const rep = ( str ) => str.replace( re, ( m, p1, p2, p3 ) => p1 + escText( w.value ) + p3 );
			if ( w.loc === 'head' ) model.head = rep( model.head );
			else if ( w.loc === 'tail' ) model.tail = rep( model.tail );
			else inner = rep( inner );
		} );
		if ( model.mode === 'structural' ) {
			// Reassemble: wrapper head + children (Gutenberg's blank-line
			// separator) + wrapper tail. Interior whitespace was verified
			// insignificant when the model was built.
			inner = model.head + model.children.map( childRaw ).join( '\n\n' ) + model.tail;
		} else if ( model.mode === 'inplace' && model.segments ) {
			model.children.forEach( ( c ) => {
				model.segments[ c.segIdx ] = { ...model.segments[ c.segIdx ], raw: childRaw( c ) };
			} );
			inner = model.segments.map( ( s ) => s.raw ).join( '' );
		}
		const open = buildOpenComment( model.parts.name, model.ownAttrs, model.parts.selfClosing );
		const newRaw = model.parts.selfClosing ? open : open + inner + model.parts.close;

		btn.disabled = true;
		btn.textContent = 'Applying…';
		ed.islands[ insp.idx ] = newRaw;

		// Refresh the preview with a real server render; tolerate failure
		// (a misbehaving render callback must never break the editor).
		const previewEl = document.querySelector( `.minn-island-preview[data-preview="${ insp.idx }"]` );
		try {
			const r = await api( 'minn-admin/v1/render-blocks', { method: 'POST', body: JSON.stringify( { blocks: [ newRaw ] } ) } );
			const html = r && r.rendered && r.rendered[ 0 ];
			if ( previewEl && html && html.trim() ) previewEl.innerHTML = html;
		} catch ( e ) {
			if ( previewEl ) {
				const inner2 = stripBlockComments( newRaw ).trim();
				if ( inner2 ) previewEl.innerHTML = inner2;
			}
		}
		toast( 'Block updated' );
		closeInspector();
		if ( ed.id ) scheduleAutosave();
	}

	// Server-render island previews so dynamic blocks (and nested dynamic
	// children) show real content instead of an empty card. Best-effort.
	function renderIslandPreviews( body, ed ) {
		if ( ! ed.islands || ! ed.islands.length ) return;
		// Removed islands are nulled (indices stay stable) — send '' to keep the
		// response aligned by index and satisfy the endpoint's string schema.
		const blocks = ed.islands.map( ( r ) => ( r == null ? '' : r ) );
		api( 'minn-admin/v1/render-blocks', { method: 'POST', body: JSON.stringify( { blocks } ) } )
			.then( ( r ) => {
				if ( ! r || ! Array.isArray( r.rendered ) || ! document.contains( body ) ) return;
				r.rendered.forEach( ( html, i ) => {
					const el = body.querySelector( `.minn-island-preview[data-preview="${ i }"]` );
					if ( el && html && html.trim() ) el.innerHTML = html;
				} );
			} )
			.catch( () => {} );
	}

	/* ===== Code block chip (config popout for editable code blocks) =====
	 * Islands get their ⚙ chip from the inspector; editable code blocks get an
	 * equivalent floating chip on hover (or caret-in, for keyboard/touch) that
	 * opens a small popover with the language picker. Both the chip and the
	 * popover live on document.body — never inside the contenteditable — so
	 * they can't leak into serialized content. */

	let codeChip = null;
	let codeChipPre = null;
	let codePop = null;

	function codePopAway( e ) {
		if ( codePop && ! codePop.contains( e.target ) && e.target !== codeChip ) hideCodePop();
	}

	function hideCodePop() {
		if ( codePop ) codePop.remove();
		codePop = null;
		document.removeEventListener( 'mousedown', codePopAway, true );
	}

	function hideCodeChip() {
		if ( codeChip ) codeChip.hidden = true;
		codeChipPre = null;
		hideCodePop();
	}

	function ensureCodeChip() {
		if ( codeChip ) return;
		codeChip = document.createElement( 'button' );
		codeChip.type = 'button';
		codeChip.className = 'minn-code-chip';
		codeChip.hidden = true;
		codeChip.title = 'Code block settings';
		document.body.appendChild( codeChip );
		codeChip.addEventListener( 'mousedown', ( e ) => e.preventDefault() ); // keep the editor selection
		codeChip.addEventListener( 'click', () => codeChipPre && openCodePop( codeChipPre ) );
		// Leave the pre (and not onto the chip) → chip goes away.
		document.addEventListener( 'mouseover', ( e ) => {
			if ( ! codeChip || codeChip.hidden || codePop ) return;
			if ( e.target === codeChip || ( e.target.closest && e.target.closest( 'pre' ) === codeChipPre ) ) return;
			if ( ! ( e.target.closest && e.target.closest( 'pre' ) ) ) hideCodeChip();
		} );
		// Scrolling moves the pre out from under the fixed chip — just drop it.
		document.addEventListener( 'scroll', () => hideCodeChip(), true );
	}

	function showCodeChip( pre ) {
		ensureCodeChip();
		if ( codePop && codeChipPre !== pre ) hideCodePop();
		codeChipPre = pre;
		const lang = codeLangOf( pre );
		codeChip.textContent = '⚙ ' + ( lang === 'auto' ? 'code' : lang );
		codeChip.hidden = false;
		const rect = pre.getBoundingClientRect();
		codeChip.style.top = ( rect.top - 10 ) + 'px';
		codeChip.style.left = Math.max( 10, Math.min( rect.right - codeChip.offsetWidth - 12, window.innerWidth - codeChip.offsetWidth - 12 ) ) + 'px';
	}

	function openCodePop( pre ) {
		hideCodePop();
		codePop = document.createElement( 'div' );
		codePop.className = 'minn-inspector minn-code-pop';
		codePop.innerHTML = `
			<div class="minn-insp-head">
				<span class="minn-insp-title">Code block</span>
				<button class="minn-x-btn" data-close type="button">×</button>
			</div>
			<div class="minn-insp-body">
				<div class="minn-field-label">Syntax highlighting</div>
				<select class="minn-input" data-lang>
					${ CODE_LANGS.map( ( l ) => `<option value="${ l }"${ l === codeLangOf( pre ) ? ' selected' : '' }>${ l === 'auto' ? 'Auto detect' : l }</option>` ).join( '' ) }
				</select>
			</div>`;
		document.body.appendChild( codePop );
		const anchor = codeChip && ! codeChip.hidden ? codeChip : pre;
		const rect = anchor.getBoundingClientRect();
		const w = codePop.offsetWidth || 250;
		codePop.style.top = Math.min( rect.bottom + 8, window.innerHeight - codePop.offsetHeight - 10 ) + 'px';
		codePop.style.left = Math.max( 10, Math.min( rect.right - w, window.innerWidth - w - 12 ) ) + 'px';
		codePop.querySelector( '[data-close]' ).addEventListener( 'click', hideCodePop );
		codePop.querySelector( '[data-lang]' ).addEventListener( 'change', ( e ) => {
			setCodeLang( pre, e.target.value );
			if ( codeChipPre === pre ) showCodeChip( pre ); // refresh the chip label
		} );
		document.addEventListener( 'mousedown', codePopAway, true );
	}

	// Shared by the toolbar picker and the chip popover.
	function setCodeLang( pre, lang ) {
		let code = pre.querySelector( 'code' );
		if ( ! code ) {
			const text = codeTextOf( pre );
			pre.textContent = '';
			code = document.createElement( 'code' );
			code.textContent = text;
			pre.appendChild( code );
		}
		code.className = lang === 'auto' ? '' : 'language-' + lang;
		delete pre.dataset.hl;
		const body = $( '#minn-editor-body' );
		if ( body ) highlightCodeBlocks( body, true );
		const toolbarSel = $( '#minn-code-lang' );
		if ( toolbarSel ) toolbarSel.value = lang;
		scheduleAutosave();
	}

	/* ===== Image controls (click an image in the editor) =====
	 * Images are editable content now (attribute passthrough), but an <img>
	 * offers nothing to type into — clicking one opens a popover with alt
	 * text, caption, replace and remove. Replace keeps the parked block
	 * attributes honest: the {"id":…} attr and wp-image-N class follow the
	 * new attachment, and stale srcset/sizes/width/height are dropped. */

	let imgPop = null;
	let imgPopTarget = null;

	function imgPopAway( e ) {
		if ( imgPop && ! imgPop.contains( e.target ) && e.target !== imgPopTarget ) hideImgPop();
	}

	function hideImgPop() {
		if ( imgPop ) imgPop.remove();
		imgPop = null;
		imgPopTarget = null;
		document.removeEventListener( 'mousedown', imgPopAway, true );
	}

	function openImgPop( img ) {
		hideImgPop();
		hideCodeChip();
		closeInspector();
		imgPopTarget = img;
		const figure = img.closest( 'figure' );
		const figcap = figure ? figure.querySelector( ':scope > figcaption' ) : null;

		imgPop = document.createElement( 'div' );
		imgPop.className = 'minn-inspector minn-img-pop';
		imgPop.innerHTML = `
			<div class="minn-insp-head">
				<span class="minn-insp-title">Image</span>
				<button class="minn-x-btn" data-close type="button">×</button>
			</div>
			<div class="minn-insp-body">
				<div class="minn-field-label">Alt text</div>
				<input class="minn-input" data-img-alt placeholder="Describe this image…" value="${ esc( img.alt || '' ) }">
				<div class="minn-field-label">Caption</div>
				<input class="minn-input" data-img-caption placeholder="Optional caption" value="${ esc( figcap ? figcap.textContent : '' ) }">
			</div>
			<div class="minn-insp-actions">
				<button class="minn-btn-primary" data-img-apply type="button">Apply</button>
				<button class="minn-btn-soft" data-img-replace type="button">${ icon( 'img' ) } Replace</button>
				<button class="minn-btn-soft danger" data-img-remove type="button" title="Remove image">${ icon( 'trash' ) }</button>
			</div>`;
		document.body.appendChild( imgPop );
		const rect = img.getBoundingClientRect();
		const w = imgPop.offsetWidth || 300;
		const fitsRight = rect.right + 10 + w < window.innerWidth;
		imgPop.style.left = ( fitsRight ? rect.right + 10 : Math.max( 10, Math.min( rect.left, window.innerWidth - w - 12 ) ) ) + 'px';
		imgPop.style.top = Math.max( 10, Math.min( fitsRight ? rect.top : rect.bottom + 8, window.innerHeight - imgPop.offsetHeight - 10 ) ) + 'px';
		document.addEventListener( 'mousedown', imgPopAway, true );

		imgPop.querySelector( '[data-close]' ).addEventListener( 'click', hideImgPop );

		imgPop.querySelector( '[data-img-apply]' ).addEventListener( 'click', () => {
			img.alt = imgPop.querySelector( '[data-img-alt]' ).value.trim();
			const cap = imgPop.querySelector( '[data-img-caption]' ).value.trim();
			let fig = img.closest( 'figure' );
			let fc = fig ? fig.querySelector( ':scope > figcaption' ) : null;
			if ( cap ) {
				if ( ! fig ) {
					// Bare img — give it the standard figure wrapper so the caption has a home.
					fig = document.createElement( 'figure' );
					fig.className = 'wp-block-image';
					img.replaceWith( fig );
					fig.appendChild( img );
				}
				if ( ! fc ) {
					fc = document.createElement( 'figcaption' );
					fc.className = 'wp-element-caption';
					fig.appendChild( fc );
				}
				fc.textContent = cap;
			} else if ( fc ) {
				fc.remove();
			}
			scheduleAutosave();
			toast( 'Image updated' );
			hideImgPop();
		} );

		imgPop.querySelector( '[data-img-replace]' ).addEventListener( 'click', () => {
			hideImgPop();
			openMediaPicker( ( it ) => {
				img.src = it.url;
				if ( it.alt ) img.alt = it.alt;
				// Stale responsive/current-size hints don't survive a swap.
				[ 'srcset', 'sizes', 'width', 'height' ].forEach( ( a ) => img.removeAttribute( a ) );
				img.className = ( img.className.replace( /\bwp-image-\d+\b/, '' ).trim() + ' wp-image-' + it.id ).trim();
				// The parked block attrs follow the new attachment.
				const host = img.closest( 'figure' ) || img;
				if ( host.dataset.minnAttrs ) {
					try {
						const a = JSON.parse( host.dataset.minnAttrs );
						a.id = it.id;
						host.dataset.minnAttrs = JSON.stringify( a );
					} catch ( e ) {}
				}
				scheduleAutosave();
				toast( 'Image replaced' );
			} );
		} );

		imgPop.querySelector( '[data-img-remove]' ).addEventListener( 'click', () => {
			if ( ! confirm( 'Remove this image from the post?' ) ) return;
			( img.closest( 'figure' ) || img ).remove();
			scheduleAutosave();
			toast( 'Image removed' );
			hideImgPop();
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
		// Custom blocks that declared an `insert` template via
		// minn_admin_block_forms land as configurable islands — blocks mode
		// only (classic serialization would flatten them to plain HTML).
		if ( state.editor && state.editor.mode === 'blocks' ) {
			Object.keys( B.blockForms || {} ).forEach( ( name ) => {
				const ins = ( B.blockForms[ name ] || {} ).insert;
				if ( ! ins || ! ins.template ) return;
				items.push( [ ins.icon || '❖', ins.label || name.split( '/' ).pop(), { block: name, template: String( ins.template ) } ] );
			} );
		}

		const close = () => {
			if ( menu ) { menu.remove(); menu = null; }
			block = null;
		};

		const highlight = () => {
			if ( ! menu ) return;
			$$( '.minn-slash-item', menu ).forEach( ( el, i ) => {
				el.classList.toggle( 'selected', i === selIdx );
				if ( i === selIdx ) el.scrollIntoView( { block: 'nearest' } );
			} );
		};

		const run = ( idx ) => {
			const item = items[ idx ];
			if ( ! item || ! block ) return close();
			const target = block;
			close();
			body.focus();
			const action = item[ 2 ];
			if ( action && action.block ) {
				// Insert a custom block as a new island: register the raw markup,
				// drop the card in place of the "/" block, render the real
				// preview, and open the inspector to configure it.
				const ed = state.editor;
				if ( ! ed ) return;
				if ( ! ed.islands ) ed.islands = [];
				const idx = ed.islands.push( action.template ) - 1;
				target.insertAdjacentHTML( 'beforebegin', islandHtml( idx, action.block, action.template ) );
				const p = document.createElement( 'p' );
				p.appendChild( document.createElement( 'br' ) );
				target.replaceWith( p );
				const range = document.createRange();
				range.selectNodeContents( p );
				range.collapse( true );
				const sel = window.getSelection();
				sel.removeAllRanges();
				sel.addRange( range );
				const islandEl = body.querySelector( `.minn-block-island[data-island="${ idx }"]` );
				api( 'minn-admin/v1/render-blocks', { method: 'POST', body: JSON.stringify( { blocks: [ action.template ] } ) } )
					.then( ( r ) => {
						const html = r && r.rendered && r.rendered[ 0 ];
						const prev = islandEl && islandEl.querySelector( '.minn-island-preview' );
						if ( prev && html && html.trim() ) prev.innerHTML = html;
					} )
					.catch( () => {} );
				if ( islandEl ) openInspector( islandEl );
				scheduleAutosave();
				return;
			}
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
		if ( B.caps.themeOptions && ! B.site.blockTheme ) {
			cmds.push( { label: 'Edit Menus', kind: 'nav', icon: '☰', run: () => go( 'menus' ) } );
			if ( B.site.hasSidebars ) cmds.push( { label: 'Manage Widgets', kind: 'nav', icon: '▥', run: () => go( 'widgets' ) } );
		}
		if ( B.caps.plugins ) cmds.push( { label: 'Manage Extensions', kind: 'nav', icon: '✦', run: () => go( 'extensions' ) } );
		if ( B.caps.settings ) cmds.push( { label: 'Manage Post Types', kind: 'nav', icon: '▦', run: () => go( 'posttypes' ) } );
		if ( B.caps.settings ) cmds.push( { label: 'Open Settings', kind: 'nav', icon: '⚙', run: () => go( 'settings' ) } );
		cmds.push(
			{ label: 'Write new post', kind: 'action', icon: '✎', run: () => newContent( 'posts' ) },
			...( B.caps.editPages ? [ { label: 'Create new page', kind: 'action', icon: '▭', run: () => newContent( 'pages' ) } ] : [] ),
			{ label: 'Toggle dark / light theme', kind: 'action', icon: '◐', run: toggleTheme },
			{ label: 'View notifications', kind: 'action', icon: '◔', run: () => { state.notifOpen = true; renderOverlays(); loadNotifications().then( () => state.notifOpen && renderOverlays() ); } },
		);
		if ( B.caps.update && Object.keys( state.cache.pluginUpdates ).length ) {
			cmds.push( { label: 'Update all plugins', kind: 'action', icon: '⟳', run: () => updateAllPlugins( null ) } );
		}
		cmds.push(
			{ label: 'Your profile — name, email, password', kind: 'link', icon: '@', run: () => openUserModal( B.user.id ) },
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

		if ( m.type === 'chart-activity' ) {
			const items = m.items;
			return `
			<div class="minn-modal-overlay" id="minn-modal-overlay">
				<div class="minn-modal">
					<div class="minn-modal-head">
						<div class="minn-modal-title">${ esc( m.bucket.label ) }</div>
						<span class="minn-modal-count">${ Number( m.bucket.value ).toLocaleString() } event${ m.bucket.value === 1 ? '' : 's' }</span>
						<button class="minn-x-btn" id="minn-modal-close">×</button>
					</div>
					${ items == null ? '<div class="minn-loading">Loading events…</div>'
					: ! items.length ? '<div class="minn-empty">Nothing recorded in this period.</div>' : `
					<div class="minn-activity minn-modal-scroll">
						${ items.map( ( a, i ) => {
							const linked = a.kind === 'post' || ( a.kind === 'comment' && B.caps.moderate );
							return `
							<div class="minn-activity-row${ linked ? ' linked' : '' }"${ linked ? ` data-ca="${ i }"` : '' }>
								<div class="minn-activity-dot dot-${ esc( a.color ) }"></div>
								<div style="min-width:0;">
									<div class="minn-activity-text">${ esc( a.text ) }</div>
									<div class="minn-activity-time">${ esc( a.ago ) }</div>
								</div>
							</div>`;
						} ).join( '' ) }
						${ items.length < m.bucket.value ? `<div class="minn-empty" style="padding:10px 0 2px;">Showing the first ${ items.length } of ${ m.bucket.value }.</div>` : '' }
					</div>` }
				</div>
			</div>`;
		}

		if ( m.type === 'widget' ) {
			const w = m.widget;
			const ws = widgetsState();
			const fields = WIDGET_EDITABLE[ w.id_base ] || [];
			const raw = ( w.instance && w.instance.raw ) || {};
			return `
			<div class="minn-modal-overlay" id="minn-modal-overlay">
				<div class="minn-modal">
					<div class="minn-modal-head">
						<div class="minn-modal-title">${ esc( ( ws.types && ws.types[ w.id_base ] ) || w.id_base ) }</div>
						<button class="minn-x-btn" id="minn-modal-close">×</button>
					</div>
					<div class="minn-modal-form">
						${ fields.map( ( f ) => `<div>
							<div class="minn-field-label">${ esc( f.label ) }</div>
							${ f.tall
								? `<textarea class="minn-input mono minn-widget-textarea" data-wfield="${ esc( f.key ) }" rows="8">${ esc( raw[ f.key ] == null ? '' : String( raw[ f.key ] ) ) }</textarea>`
								: `<input class="minn-input" data-wfield="${ esc( f.key ) }" value="${ esc( raw[ f.key ] == null ? '' : String( raw[ f.key ] ) ) }">` }
						</div>` ).join( '' ) }
					</div>
					<div class="minn-modal-actions">
						<button class="minn-btn-primary" id="minn-widget-save">Save widget</button>
					</div>
				</div>
			</div>`;
		}

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
			const edit = detail.edit;
			const editFields = ! m.loading && edit ? edit.fields : [];
			// Fields shown as editable inputs shouldn't also appear as static rows.
			const skip = new Set( [ 'id', ...( detail.skip || [] ), ...editFields.map( ( f ) => f.key.split( '.' )[ 0 ] ) ] );
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
						${ editFields.length ? `<div class="minn-media-edit">
							${ editFields.map( ( f, i ) => {
								const val = surfaceValue( it, f.key );
								return `<div class="minn-field-label"${ i ? ' style="margin-top:10px;"' : '' }>${ esc( f.label ) }</div>
								<input class="minn-input${ f.mono ? ' mono' : '' }" data-editfield="${ esc( f.key ) }"${ f.type === 'number' ? ' type="number"' : '' } value="${ esc( val == null ? '' : val ) }">`;
							} ).join( '' ) }
						</div>` : '' }
					</div>
					${ message ? ( isHtml
						? `<iframe class="minn-email-frame" id="minn-email-frame" sandbox="" title="Email preview" srcdoc="${ esc( String( message ) ) }"></iframe>`
						: `<pre class="minn-surface-message">${ esc( stripTags( String( message ) ) ) }</pre>` ) : '' }
					${ ( message || edit || ( s.collection.actions || [] ).length ) ? `
					<div class="minn-modal-actions">
						${ edit ? `<button class="minn-btn-primary" id="minn-surface-save">Save</button>` : '' }
						${ message ? `<button class="minn-btn-soft" id="minn-surface-raw">↗ Open raw</button>` : '' }
						${ ( s.collection.actions || [] ).map( ( a, i ) => `<button class="minn-btn-soft${ a.danger ? ' danger' : '' }" data-saction="${ i }">${ esc( a.label ) }</button>` ).join( '' ) }
					</div>` : '' }` }
				</div>
			</div>`;
		}

		if ( m.type === 'user' ) {
			const u = m.user;
			const isNew = ! m.userId;
			const isSelf = ! isNew && m.userId === B.user.id;
			const roles = Object.entries( B.roles || {} );
			if ( ! isNew && ! u ) {
				return `<div class="minn-modal-overlay" id="minn-modal-overlay"><div class="minn-modal"><div class="minn-modal-head"><div class="minn-modal-title">${ isSelf ? 'Your profile' : 'Edit user' }</div><button class="minn-x-btn" id="minn-modal-close">×</button></div><div class="minn-loading">Loading…</div></div></div>`;
			}
			const role = u && u.roles && u.roles[ 0 ] ? u.roles[ 0 ] : 'subscriber';
			return `
			<div class="minn-modal-overlay" id="minn-modal-overlay">
				<div class="minn-modal">
					<div class="minn-modal-head">
						<div class="minn-modal-title">${ isNew ? 'Add user' : ( isSelf ? 'Your profile' : 'Edit ' + esc( u.name ) ) }</div>
						${ isSelf ? `<span class="minn-modal-count">@${ esc( B.user.login ) }</span>` : '' }
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
							${ roles.length && B.caps.promoteUsers ? `
							<select class="minn-input" id="minn-uf-role">
								${ roles.map( ( [ slug, label ] ) => `<option value="${ esc( slug ) }"${ slug === role ? ' selected' : '' }>${ esc( label ) }</option>` ).join( '' ) }
							</select>` : `
							<input class="minn-input" value="${ esc( isSelf ? B.user.role : role ) }" disabled>` }
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

		if ( m.type === 'cpt' ) {
			const t = m.item;
			const isNew = ! t;
			const editable = isNew || t.editable;
			const supports = new Set( t ? t.supports : [ 'title', 'editor', 'thumbnail' ] );
			const taxes = new Set( t ? t.taxonomies : [] );
			const flag = ( key, label, desc, on ) => `
				<label class="minn-insp-check" title="${ esc( desc ) }">
					<input type="checkbox" class="minn-cb" data-cptflag="${ key }"${ on ? ' checked' : '' }${ editable ? '' : ' disabled' }> ${ label }
				</label>`;
			const dis = editable ? '' : ' disabled';
			return `
			<div class="minn-modal-overlay" id="minn-modal-overlay">
				<div class="minn-modal">
					<div class="minn-modal-head">
						<div class="minn-modal-title">${ isNew ? 'Add post type' : esc( t.plural ) }</div>
						${ isNew ? '' : `<span class="minn-status ${ t.editable ? 'publish' : 'draft' }">${ esc( CPT_SOURCE_LABEL[ t.source ] || t.source ) }</span>` }
						<button class="minn-x-btn" id="minn-modal-close">×</button>
					</div>
					<div class="minn-modal-form">
						${ editable ? '' : `<div class="minn-editor-locked-note">${ t && t.source === 'core' ? 'Built into WordPress — shown for reference.' : 'Registered in code by a theme or plugin — shown for reference, editable only where it’s defined.' }</div>` }
						<div><div class="minn-field-label">Plural label</div>
						<input class="minn-input" data-cptfield="plural" value="${ esc( t ? t.plural : '' ) }" placeholder="Books"${ dis }></div>
						<div><div class="minn-field-label">Singular label</div>
						<input class="minn-input" data-cptfield="singular" value="${ esc( t ? t.singular : '' ) }" placeholder="Book"${ dis }></div>
						<div><div class="minn-field-label">Slug</div>
						<input class="minn-input mono" data-cptfield="slug" value="${ esc( t ? t.slug : '' ) }" placeholder="book" maxlength="20"${ isNew ? '' : ' disabled' }></div>
						<div><div class="minn-field-label">Description (optional)</div>
						<input class="minn-input" data-cptfield="description" value="${ esc( t ? t.description : '' ) }"${ dis }></div>
						${ isNew && m.backends.length > 1 ? `<div><div class="minn-field-label">Store definition in</div>
						<select class="minn-input" id="minn-cpt-backend">
							${ m.backends.map( ( b ) => `<option value="${ esc( b ) }">${ esc( CPT_SOURCE_LABEL[ b ] || b ) }</option>` ).join( '' ) }
						</select></div>` : '' }
						<div><div class="minn-field-label">Visibility</div>
						${ flag( 'public', 'Public', 'Visible on the front end with its own URLs', t ? t.public : true ) }
						${ flag( 'has_archive', 'Archive page', 'A listing page at /slug/', t ? t.has_archive : false ) }
						${ flag( 'hierarchical', 'Hierarchical', 'Like pages — items can have parents', t ? t.hierarchical : false ) }
						${ flag( 'show_in_rest', 'Show in REST API', 'Required for Minn (and the block editor) to list and edit content', t ? t.show_in_rest : true ) }</div>
						<div><div class="minn-field-label">Supports</div>
						<div class="minn-cpt-checks">${ CPT_SUPPORTS.map( ( [ id, label ] ) => `
							<label class="minn-insp-check"><input type="checkbox" class="minn-cb" data-cptsupport="${ id }"${ supports.has( id ) ? ' checked' : '' }${ dis }> ${ label }</label>` ).join( '' ) }</div></div>
						<div><div class="minn-field-label">Taxonomies</div>
						<div class="minn-cpt-checks">
							${ ( m.catalog && m.catalog.length ? m.catalog : [ { slug: 'category', label: 'Categories' }, { slug: 'post_tag', label: 'Tags' } ] ).map( ( tax ) => `
							<label class="minn-insp-check"><input type="checkbox" class="minn-cb" data-cpttax="${ esc( tax.slug ) }"${ taxes.has( tax.slug ) ? ' checked' : '' }${ dis }> ${ esc( tax.label ) }</label>` ).join( '' ) }
						</div></div>
					</div>
					<div class="minn-modal-actions">
						${ editable ? `<button class="minn-btn-primary" id="minn-cpt-save">${ isNew ? 'Create post type' : 'Save' }</button>` : '' }
						${ ! isNew && editable ? `<button class="minn-btn-soft danger" id="minn-cpt-delete">${ icon( 'trash' ) } Remove</button>` : '' }
					</div>
				</div>
			</div>`;
		}

		if ( m.type === 'surface-form' ) {
			const cr = m.surface.collection.create;
			return `
			<div class="minn-modal-overlay" id="minn-modal-overlay">
				<div class="minn-modal">
					<div class="minn-modal-head">
						<div class="minn-modal-title">${ esc( cr.label || 'Add' ) } — ${ esc( m.surface.label ) }</div>
						<button class="minn-x-btn" id="minn-modal-close">×</button>
					</div>
					<div class="minn-modal-form">
						${ cr.fields.map( ( f ) => `<div>
							<div class="minn-field-label">${ esc( f.label ) }</div>
							<input class="minn-input${ f.mono ? ' mono' : '' }" data-createfield="${ esc( f.key ) }"${ f.type === 'number' ? ' type="number"' : '' } value="${ esc( f.value == null ? '' : f.value ) }" placeholder="${ esc( f.placeholder || '' ) }">
						</div>` ).join( '' ) }
					</div>
					<div class="minn-modal-actions">
						<button class="minn-btn-primary" id="minn-surface-create">${ esc( cr.label || 'Add' ) }</button>
					</div>
				</div>
			</div>`;
		}

		if ( m.type === 'tax' ) {
			const t = m.item;
			const isNew = ! t;
			const editable = isNew || t.editable;
			const attached = new Set( t ? t.object_types : [ 'post' ] );
			const dis = editable ? '' : ' disabled';
			const flag = ( key, label, desc, on ) => `
				<label class="minn-insp-check" title="${ esc( desc ) }">
					<input type="checkbox" class="minn-cb" data-taxflag="${ key }"${ on ? ' checked' : '' }${ dis }> ${ label }
				</label>`;
			return `
			<div class="minn-modal-overlay" id="minn-modal-overlay">
				<div class="minn-modal">
					<div class="minn-modal-head">
						<div class="minn-modal-title">${ isNew ? 'Add taxonomy' : esc( t.plural ) }</div>
						${ isNew ? '' : `<span class="minn-status ${ t.editable ? 'publish' : 'draft' }">${ esc( CPT_SOURCE_LABEL[ t.source ] || t.source ) }</span>` }
						<button class="minn-x-btn" id="minn-modal-close">×</button>
					</div>
					<div class="minn-modal-form">
						${ editable ? '' : `<div class="minn-editor-locked-note">${ t && t.source === 'core' ? 'Built into WordPress — shown for reference.' : 'Registered in code by a theme or plugin — shown for reference, editable only where it’s defined.' }</div>` }
						<div><div class="minn-field-label">Plural label</div>
						<input class="minn-input" data-taxfield="plural" value="${ esc( t ? t.plural : '' ) }" placeholder="Genres"${ dis }></div>
						<div><div class="minn-field-label">Singular label</div>
						<input class="minn-input" data-taxfield="singular" value="${ esc( t ? t.singular : '' ) }" placeholder="Genre"${ dis }></div>
						<div><div class="minn-field-label">Slug</div>
						<input class="minn-input mono" data-taxfield="slug" value="${ esc( t ? t.slug : '' ) }" placeholder="genre" maxlength="32"${ isNew ? '' : ' disabled' }></div>
						${ isNew && m.backends.length > 1 ? `<div><div class="minn-field-label">Store definition in</div>
						<select class="minn-input" id="minn-tax-backend">
							${ m.backends.map( ( b ) => `<option value="${ esc( b ) }">${ esc( CPT_SOURCE_LABEL[ b ] || b ) }</option>` ).join( '' ) }
						</select></div>` : '' }
						<div><div class="minn-field-label">Behavior</div>
						${ flag( 'hierarchical', 'Hierarchical (like categories)', 'Terms can nest under parents; unchecked behaves like tags', t ? t.hierarchical : false ) }
						${ flag( 'public', 'Public', 'Visible on the front end with term archive URLs', t ? t.public : true ) }
						${ flag( 'show_in_rest', 'Show in REST API', 'Required for Minn and the block editor to assign terms', t ? t.show_in_rest : true ) }</div>
						<div><div class="minn-field-label">Attach to</div>
						<div class="minn-cpt-checks">
							${ ( m.types || [] ).map( ( pt ) => `
							<label class="minn-insp-check"><input type="checkbox" class="minn-cb" data-taxtype="${ esc( pt.slug ) }"${ attached.has( pt.slug ) ? ' checked' : '' }${ dis }> ${ esc( pt.plural ) }</label>` ).join( '' ) }
						</div></div>
					</div>
					<div class="minn-modal-actions">
						${ editable ? `<button class="minn-btn-primary" id="minn-tax-save">${ isNew ? 'Create taxonomy' : 'Save' }</button>` : '' }
						${ ! isNew && editable ? `<button class="minn-btn-soft danger" id="minn-tax-delete">${ icon( 'trash' ) } Remove</button>` : '' }
					</div>
				</div>
			</div>`;
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
							${ m.results && m.results.length && m.page < m.pages ? `<button class="minn-load-more" id="minn-pi-more" style="margin:10px 0 4px;">Load more · showing ${ m.results.length } of ${ Number( m.total ).toLocaleString() }</button>` : '' }
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
					${ B.caps.upload ? `
					<div class="minn-picker-drop" id="minn-picker-drop">
						${ icon( 'img' ) }
						<span>Drag &amp; drop an image here, or <b>browse</b> — it's used right away</span>
						<input type="file" id="minn-picker-file" accept="image/*" hidden>
					</div>` : '' }
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

		if ( m.type === 'widget' ) {
			$( '#minn-widget-save' ).addEventListener( 'click', async ( e ) => {
				const btn = e.currentTarget;
				btn.disabled = true;
				const raw = { ...( ( m.widget.instance && m.widget.instance.raw ) || {} ) };
				$$( '[data-wfield]' ).forEach( ( input ) => { raw[ input.dataset.wfield ] = input.value; } );
				try {
					await api( `wp/v2/widgets/${ m.widget.id }`, { method: 'POST', body: JSON.stringify( { instance: { raw } } ) } );
					toast( 'Widget saved' );
					closeModal();
					reloadWidgets();
				} catch ( err ) {
					toast( err.message, true );
					btn.disabled = false;
				}
			} );
		}

		if ( m.type === 'chart-activity' ) {
			$$( '[data-ca]' ).forEach( ( row ) =>
				row.addEventListener( 'click', () => {
					const it = ( m.items || [] )[ parseInt( row.dataset.ca, 10 ) ];
					if ( ! it ) return;
					closeModal();
					if ( it.kind === 'post' ) go( `editor/${ it.type }/${ it.id }` );
					else go( 'comments' );
				} )
			);
		}

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

		if ( m.type === 'cpt' ) {
			const saveBtn = $( '#minn-cpt-save' );
			if ( saveBtn ) saveBtn.addEventListener( 'click', async () => {
				// Scope to the modal — the list rows behind it carry data-cpt slugs.
				const modal = $( '.minn-modal' );
				const payload = { supports: [], taxonomies: [] };
				$$( '[data-cptfield]', modal ).forEach( ( i ) => { payload[ i.dataset.cptfield ] = i.value.trim(); } );
				$$( '[data-cptflag]', modal ).forEach( ( i ) => { payload[ i.dataset.cptflag ] = i.checked ? 1 : 0; } );
				$$( '[data-cptsupport]', modal ).forEach( ( i ) => { if ( i.checked ) payload.supports.push( i.dataset.cptsupport ); } );
				$$( '[data-cpttax]', modal ).forEach( ( i ) => { if ( i.checked ) payload.taxonomies.push( i.dataset.cpttax ); } );
				const backendSel = $( '#minn-cpt-backend' );
				if ( backendSel ) payload.backend = backendSel.value;
				saveBtn.disabled = true;
				saveBtn.textContent = 'Saving…';
				try {
					await api( 'minn-admin/v1/post-types' + ( m.item ? '/' + m.item.slug : '' ), { method: 'POST', body: JSON.stringify( payload ) } );
					toast( m.item ? 'Post type updated' : 'Post type created' );
					bustTypeCaches();
					closeModal();
					if ( state.route === 'posttypes' ) renderPostTypes();
				} catch ( e ) {
					toast( e.message, true );
					saveBtn.disabled = false;
					saveBtn.textContent = m.item ? 'Save' : 'Create post type';
				}
			} );
			const delBtn = $( '#minn-cpt-delete' );
			if ( delBtn ) delBtn.addEventListener( 'click', async () => {
				if ( ! confirm( `Remove the “${ m.item.plural }” post type? Existing content stays in the database.` ) ) return;
				try {
					await api( 'minn-admin/v1/post-types/' + m.item.slug, { method: 'DELETE' } );
					toast( 'Post type removed — content preserved' );
					bustTypeCaches();
					closeModal();
					if ( state.route === 'posttypes' ) renderPostTypes();
				} catch ( e ) {
					toast( e.message, true );
				}
			} );
		}

		if ( m.type === 'surface-form' ) {
			const createBtn = $( '#minn-surface-create' );
			if ( createBtn ) createBtn.addEventListener( 'click', async () => {
				const cr = m.surface.collection.create;
				// Defaults first, then the typed fields (dot paths supported).
				const body = JSON.parse( JSON.stringify( cr.defaults || {} ) );
				let missing = false;
				$$( '[data-createfield]', $( '.minn-modal' ) ).forEach( ( input ) => {
					let v = input.value.trim();
					if ( ! v && input.type !== 'number' ) missing = true;
					if ( input.type === 'number' ) v = v === '' ? null : Number( v );
					setDeepPath( body, input.dataset.createfield, v );
				} );
				if ( missing ) { toast( 'Fill in all fields first', true ); return; }
				createBtn.disabled = true;
				createBtn.textContent = 'Saving…';
				try {
					await api( cr.route, { method: cr.method || 'POST', body: JSON.stringify( body ) } );
					toast( ( m.surface.label || 'Item' ) + ' added' );
					surfaceState( m.surface.id ).cache = null;
					closeModal();
					if ( state.route === m.surface.id ) renderSurface( m.surface );
				} catch ( e ) {
					toast( e.message, true );
					createBtn.disabled = false;
					createBtn.textContent = cr.label || 'Add';
				}
			} );
		}

		if ( m.type === 'tax' ) {
			const saveBtn = $( '#minn-tax-save' );
			if ( saveBtn ) saveBtn.addEventListener( 'click', async () => {
				const modal = $( '.minn-modal' );
				const payload = { object_types: [] };
				$$( '[data-taxfield]', modal ).forEach( ( i ) => { payload[ i.dataset.taxfield ] = i.value.trim(); } );
				$$( '[data-taxflag]', modal ).forEach( ( i ) => { payload[ i.dataset.taxflag ] = i.checked ? 1 : 0; } );
				$$( '[data-taxtype]', modal ).forEach( ( i ) => { if ( i.checked ) payload.object_types.push( i.dataset.taxtype ); } );
				const backendSel = $( '#minn-tax-backend' );
				if ( backendSel ) payload.backend = backendSel.value;
				saveBtn.disabled = true;
				saveBtn.textContent = 'Saving…';
				try {
					await api( 'minn-admin/v1/taxonomies' + ( m.item ? '/' + m.item.slug : '' ), { method: 'POST', body: JSON.stringify( payload ) } );
					toast( m.item ? 'Taxonomy updated' : 'Taxonomy created' );
					bustTypeCaches();
					closeModal();
					if ( state.route === 'posttypes' ) renderPostTypes();
				} catch ( e ) {
					toast( e.message, true );
					saveBtn.disabled = false;
					saveBtn.textContent = m.item ? 'Save' : 'Create taxonomy';
				}
			} );
			const delBtn = $( '#minn-tax-delete' );
			if ( delBtn ) delBtn.addEventListener( 'click', async () => {
				if ( ! confirm( `Remove the “${ m.item.plural }” taxonomy? Existing terms stay in the database.` ) ) return;
				try {
					await api( 'minn-admin/v1/taxonomies/' + m.item.slug, { method: 'DELETE' } );
					toast( 'Taxonomy removed — terms preserved' );
					bustTypeCaches();
					closeModal();
					if ( state.route === 'posttypes' ) renderPostTypes();
				} catch ( e ) {
					toast( e.message, true );
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
			// Upload straight from the picker — the new image is handed to the
			// callback immediately (featured image / insertion), no second pick.
			const drop = $( '#minn-picker-drop' );
			if ( drop ) {
				const fileInput = $( '#minn-picker-file' );
				const uploadAndUse = async ( file ) => {
					if ( ! file || ! file.type.startsWith( 'image/' ) ) { toast( 'Drop an image file', true ); return; }
					drop.classList.add( 'minn-busy' );
					toast( 'Uploading…' );
					try {
						const fd = new FormData();
						fd.append( 'file', file );
						const up = await api( 'wp/v2/media', { method: 'POST', body: fd } );
						state.cache.media = null; // library changed
						const sizes = up.media_details && up.media_details.sizes;
						const it = {
							id: up.id,
							name: decodeEntities( ( up.title && up.title.rendered ) || file.name ),
							url: up.source_url,
							alt: up.alt_text || '',
							thumb: ( sizes && sizes.medium && sizes.medium.source_url ) || up.source_url,
						};
						const cb = m.callback;
						closeModal();
						if ( cb ) cb( it );
						toast( 'Image uploaded' );
					} catch ( e ) {
						toast( e.message, true );
						drop.classList.remove( 'minn-busy' );
					}
				};
				drop.addEventListener( 'click', () => fileInput.click() );
				fileInput.addEventListener( 'change', () => uploadAndUse( fileInput.files && fileInput.files[ 0 ] ) );
				// stopPropagation keeps the app-wide drop-to-media-library handler out of it.
				drop.addEventListener( 'dragover', ( e ) => { e.preventDefault(); e.stopPropagation(); drop.classList.add( 'over' ); } );
				drop.addEventListener( 'dragleave', () => drop.classList.remove( 'over' ) );
				drop.addEventListener( 'drop', ( e ) => {
					e.preventDefault();
					e.stopPropagation();
					drop.classList.remove( 'over' );
					document.body.classList.remove( 'minn-dragging' );
					uploadAndUse( e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[ 0 ] );
				} );
			}
		}

		if ( m.type === 'surface' ) {
			const rawBtn = $( '#minn-surface-raw' );
			if ( rawBtn ) rawBtn.addEventListener( 'click', () => {
				const detail = m.surface.collection.detail || {};
				const msg = detail.messageKey ? m.item[ detail.messageKey ] : null;
				if ( msg == null ) return;
				// text/plain, never text/html — blob: URLs are same-origin, so scripts in
				// a logged email (which can carry user-submitted content) would run as the app.
				const blob = new Blob( [ String( msg ) ], { type: 'text/plain' } );
				window.open( URL.createObjectURL( blob ), '_blank' );
			} );
			const saveBtn = $( '#minn-surface-save' );
			if ( saveBtn ) saveBtn.addEventListener( 'click', async () => {
				const edit = ( m.surface.collection.detail || {} ).edit;
				if ( ! edit ) return;
				const body = {};
				// Carry the untouched fields so the plugin's sanitizer doesn't reset them.
				( edit.preserve || [] ).forEach( ( k ) => { const v = surfaceValue( m.item, k ); if ( v !== undefined ) body[ k ] = v; } );
				$$( '[data-editfield]' ).forEach( ( input ) => {
					let v = input.value;
					if ( input.type === 'number' ) v = v === '' ? null : Number( v );
					setDeepPath( body, input.dataset.editfield, v );
				} );
				saveBtn.disabled = true;
				saveBtn.textContent = 'Saving…';
				try {
					await api( edit.route.replace( '{id}', m.item.id ), { method: edit.method || 'POST', body: JSON.stringify( body ) } );
					toast( 'Saved' );
					surfaceState( m.surface.id ).cache = null;
					closeModal();
					if ( state.route === m.surface.id ) renderSurface( m.surface );
				} catch ( e ) {
					toast( e.message, true );
					saveBtn.disabled = false;
					saveBtn.textContent = 'Save';
				}
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

	// One page of wp.org search results into the modal state; a null return
	// means the modal closed or the query changed mid-flight (discard).
	async function fetchPluginPage( m, page ) {
		const q = m.q;
		const r = await api( `minn-admin/v1/plugins/search?q=${ encodeURIComponent( q ) }&page=${ page }` );
		if ( state.modal !== m || m.q !== q ) return null;
		m.page = page;
		m.pages = r.pages || 1;
		m.total = r.total || ( r.plugins || [] ).length;
		return r.plugins || [];
	}

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
					const items = await fetchPluginPage( m, 1 );
					if ( items === null ) return;
					m.results = items;
				} catch ( e ) {
					toast( e.message, true );
					m.results = [];
				}
				m.searching = false;
				renderOverlays();
			}, 400 );
		} );

		// renderOverlays rebuilds the modal, which resets the results scroll —
		// put the reader back where they were.
		const renderKeepingScroll = () => {
			const list = $( '.minn-pi-results' );
			const top = list ? list.scrollTop : 0;
			renderOverlays();
			const again = $( '.minn-pi-results' );
			if ( again ) again.scrollTop = top;
		};
		const more = $( '#minn-pi-more' );
		if ( more ) more.addEventListener( 'click', async () => {
			more.disabled = true;
			more.textContent = 'Loading…';
			try {
				const next = await fetchPluginPage( m, m.page + 1 );
				if ( next === null ) return;
				// wp.org pagination occasionally repeats an item across pages.
				const seen = new Set( m.results.map( ( p ) => p.slug ) );
				m.results = m.results.concat( next.filter( ( p ) => ! seen.has( p.slug ) ) );
				renderKeepingScroll();
			} catch ( e ) {
				toast( e.message, true );
				renderKeepingScroll();
			}
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
					state.cache.overview = null;
					bustTypeCaches();
					await loadPlugins().catch( () => {} );
					if ( state.route === 'extensions' ) renderExtensions();
					renderKeepingScroll(); // refresh button states in the modal
				} catch ( e ) {
					toast( e.message, true );
					renderKeepingScroll();
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
			const roleSel = $( '#minn-uf-role' );
			if ( B.caps.promoteUsers && roleSel ) payload.roles = [ roleSel.value ];
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
					// Changing your own password rotates the session token, which
					// invalidates the REST nonce baked into this page — reload to
					// pick up fresh credentials before the next request 403s.
					if ( password && m.userId === B.user.id ) {
						toast( 'Password changed — refreshing…' );
						setTimeout( () => location.reload(), 600 );
						return;
					}
					toast( m.userId === B.user.id ? 'Profile updated' : 'User updated' );
					// Keep the sidebar's name in sync with a self display-name edit.
					if ( m.userId === B.user.id && payload.name ) {
						B.user.name = payload.name;
						const nameEl = $( '.minn-user-name' );
						if ( nameEl ) nameEl.textContent = payload.name;
					}
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

	// Wrap any not-yet-enhanced tab strip in a scroller with ‹ › buttons that
	// appear only when the strip actually overflows (and only on the side you can scroll).
	function enhanceTabStrips() {
		$$( '.minn-tabs:not([data-scroll])' ).forEach( ( tabs ) => {
			tabs.setAttribute( 'data-scroll', '1' );
			const wrap = document.createElement( 'div' );
			wrap.className = 'minn-tabscroll';
			const prev = document.createElement( 'button' );
			prev.type = 'button'; prev.className = 'minn-tabscroll-btn prev'; prev.textContent = '‹'; prev.setAttribute( 'aria-label', 'Scroll tabs left' );
			const next = document.createElement( 'button' );
			next.type = 'button'; next.className = 'minn-tabscroll-btn next'; next.textContent = '›'; next.setAttribute( 'aria-label', 'Scroll tabs right' );
			tabs.parentNode.insertBefore( wrap, tabs );
			wrap.appendChild( prev );
			wrap.appendChild( tabs );
			wrap.appendChild( next );
			const update = () => {
				const max = tabs.scrollWidth - tabs.clientWidth;
				prev.classList.toggle( 'show', max > 2 && tabs.scrollLeft > 2 );
				next.classList.toggle( 'show', max > 2 && tabs.scrollLeft < max - 2 );
			};
			const step = () => Math.max( 120, Math.round( tabs.clientWidth * 0.7 ) );
			prev.addEventListener( 'click', () => tabs.scrollBy( { left: -step(), behavior: 'smooth' } ) );
			next.addEventListener( 'click', () => tabs.scrollBy( { left: step(), behavior: 'smooth' } ) );
			tabs.addEventListener( 'scroll', update, { passive: true } );
			if ( 'ResizeObserver' in window ) new ResizeObserver( update ).observe( tabs );
			// active tab may be off-screen on first paint — bring it into view, then sync arrows.
			const active = tabs.querySelector( '.minn-tab.active' );
			if ( active && active.scrollIntoView ) active.scrollIntoView( { inline: 'nearest', block: 'nearest' } );
			update();
		} );
	}

	function renderView() {
		renderTopbar();
		closeInspector();
		hideCodeChip();
		hideImgPop();
		const tip = $( '#minn-chart-tip' );
		if ( tip ) tip.hidden = true;
		switch ( state.route ) {
			case 'content': return renderContent();
			case 'media': return renderMedia();
			case 'comments': return renderComments();
			case 'orders': return renderOrders();
			case 'users': return renderUsers();
			case 'menus': return renderMenus();
			case 'widgets': return renderWidgets();
			case 'extensions': return renderExtensions();
			case 'posttypes': return renderPostTypes();
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

		// Overflowing tab strips (e.g. every user role) get ‹ › scroll buttons that
		// show only when there's more to scroll. Runs for any view that renders tabs.
		const view = $( '#minn-view' );
		if ( view && 'MutationObserver' in window ) {
			let raf = 0;
			const obs = new MutationObserver( () => {
				cancelAnimationFrame( raf );
				raf = requestAnimationFrame( enhanceTabStrips );
			} );
			obs.observe( view, { childList: true, subtree: true } );
			enhanceTabStrips();
		}

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
			} else if ( e.key === 'Escape' && state.route === 'content' && state.contentSel && state.contentSel.size ) {
				state.contentSel.clear();
				renderContent();
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
