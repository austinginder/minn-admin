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

	// WP REST site-local timestamps (date, modified, comment date, …) have no
	// zone suffix. Appending Z treated them as UTC and skewed every "time ago"
	// label by the site's gmt_offset (America/New_York → everything looked 4h
	// old). Absolute ISO strings (with Z/offset) and our own toISOString()
	// outputs pass through unchanged.
	//
	// UTC sources without a suffix (Code Snippets `modified` via gmdate,
	// Stream/WSAL/Aryo shims, `*_gmt` keys) MUST be flagged: pass
	// { utc: true } or a key ending in `_gmt`, or emit a trailing Z server-side.
	// Otherwise parseWpDate treats them as site-local → "in 4h" on EDT.
	function parseWpDate( dateStr ) {
		if ( dateStr == null || dateStr === '' ) return new Date( NaN );
		const s = String( dateStr );
		if ( /Z|[+-]\d\d:?\d\d$/.test( s ) ) return new Date( s );
		const off = ( typeof B.gmtOffset === 'number' ) ? B.gmtOffset : 0;
		const sign = off >= 0 ? '+' : '-';
		const abs = Math.abs( off );
		const hh = String( Math.floor( abs ) ).padStart( 2, '0' );
		const mm = String( Math.round( ( abs % 1 ) * 60 ) ).padStart( 2, '0' );
		return new Date( s + sign + hh + ':' + mm );
	}

	// Normalize a timestamp for parseWpDate/timeAgo.
	// opts.utc / keys ending _gmt → force UTC (append Z when bare).
	function normalizeTimeInput( dateStr, opts ) {
		let s = String( dateStr == null ? '' : dateStr ).trim();
		if ( ! s || /^0{4}-0{2}-0{2}/.test( s ) ) return '';
		// Unix seconds / ms (session login times, some plugins).
		if ( /^-?\d+(\.\d+)?$/.test( s ) ) {
			const n = Number( s );
			const ms = n < 1e12 ? n * 1000 : n;
			const d = new Date( ms );
			return isNaN( d.getTime() ) ? '' : d.toISOString();
		}
		s = s.replace( ' ', 'T' );
		if ( /Z|[+-]\d{2}:?\d{2}$/.test( s ) ) return s;
		const key = ( opts && opts.key ) || '';
		const utc = !!( opts && opts.utc ) || /_gmt$/i.test( key );
		if ( utc ) return s + 'Z';
		return s;
	}

	function timeAgo( dateStr, opts ) {
		const s = normalizeTimeInput( dateStr, opts && typeof opts === 'object' ? opts : null );
		if ( ! s ) return '—';
		const d = parseWpDate( s );
		if ( isNaN( d.getTime() ) ) return '—';
		const diff = Math.round( ( Date.now() - d.getTime() ) / 1000 );
		// Future dates (scheduled posts) mirror the past buckets as "in …".
		if ( diff < -30 ) {
			const f = -diff;
			if ( f < 3600 ) return 'in ' + Math.max( 1, Math.round( f / 60 ) ) + ' min';
			if ( f < 86400 ) return 'in ' + Math.round( f / 3600 ) + 'h';
			if ( f < 86400 * 7 ) return 'in ' + Math.round( f / 86400 ) + 'd';
			return d.toLocaleDateString( undefined, { month: 'short', day: 'numeric' } );
		}
		const sec = Math.max( 1, diff );
		if ( sec < 60 ) return 'just now';
		if ( sec < 3600 ) return Math.round( sec / 60 ) + ' min ago';
		if ( sec < 86400 ) return Math.round( sec / 3600 ) + 'h ago';
		if ( sec < 86400 * 7 ) return Math.round( sec / 86400 ) + 'd ago';
		return d.toLocaleDateString( undefined, { month: 'short', day: 'numeric' } );
	}

	// Surface list cells: pass field key + optional col.utc.
	function timeAgoForKey( dateStr, key, utc ) {
		return timeAgo( dateStr, { key: key || '', utc: !! utc } );
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

	/* ===== Pager (shared numbered pagination) ===== */

	// Quiet toolbar meta: "N thing(s)" — live feedback for the filters and
	// search beside it. Page position deliberately lives at the bottom with
	// the pager (the control that changes it), not up here.
	function metaLabel( count, noun ) {
		const plural = /[^aeiou]y$/.test( noun ) ? noun.slice( 0, -1 ) + 'ies' : noun + 's';
		return `${ count } ${ count === 1 ? noun : plural }`;
	}

	// ‹ 1 … 4 [5] 6 … 20 › — first, last and a window around the current page.
	// Pass count/noun and the row carries "N things · page X of Y" on the left.
	function pagerHtml( page, totalPages, count, noun ) {
		if ( ! totalPages || totalPages <= 1 ) return '';
		const wanted = [ 1, totalPages, page - 2, page - 1, page, page + 1, page + 2 ];
		const list = [ ...new Set( wanted.filter( ( p ) => p >= 1 && p <= totalPages ) ) ].sort( ( a, b ) => a - b );
		let last = 0;
		const parts = list.map( ( p ) => {
			const gap = p - last > 1 ? '<span class="minn-pager-gap">…</span>' : '';
			last = p;
			return gap + `<button class="minn-pager-btn${ p === page ? ' active' : '' }" data-pg="${ p }">${ p }</button>`;
		} ).join( '' );
		return `<div class="minn-pager" role="navigation" aria-label="Pagination">
			${ count != null ? `<div class="minn-pager-meta">${ metaLabel( count, noun ) } · page ${ page } of ${ totalPages }</div>` : '' }
			<button class="minn-pager-btn nav" data-pg="${ page - 1 }"${ page <= 1 ? ' disabled' : '' } aria-label="Previous page">‹</button>
			${ parts }
			<button class="minn-pager-btn nav" data-pg="${ page + 1 }"${ page >= totalPages ? ' disabled' : '' } aria-label="Next page">›</button>
		</div>`;
	}

	// Wire the pager: `load(p)` fetches page p, then the caller's render runs.
	// The list dims while loading and the view scrolls back to the top after.
	function bindPager( view, current, load, render ) {
		$$( '.minn-pager [data-pg]', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', async () => {
				const p = parseInt( btn.dataset.pg, 10 );
				if ( ! p || p === current || btn.disabled ) return;
				const tbl = $( '.minn-card, .minn-media-grid', view );
				if ( tbl ) tbl.classList.add( 'minn-busy' );
				await load( p ).catch( showErr );
				render();
				const scroll = $( '.minn-scroll' );
				if ( scroll ) scroll.scrollTop = 0;
			} )
		);
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
		extFilter: 'all',
		extSearch: '',
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
		settingsSection: 'Site',
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
			themeUpdates: {},
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
		terms: [ 'Terms', 'Categories & Tags' ],
		menus: [ 'Menus', 'Navigation' ],
		widgets: [ 'Widgets', 'Sidebars & footers' ],
		extensions: [ 'Extensions', 'Installed' ],
		posttypes: [ 'Structure', 'Post types, taxonomies & terms' ],
		settings: [ 'Settings', 'Site' ],
		system: [ 'System', 'Diagnostics' ],
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

	// A toast with an action button (e.g. "Removed — Undo"). Used to make
	// structural block deletions recoverable without touching the browser undo
	// stack (see the undo-completeness decision in docs/editor-roadmap.md).
	// While the toast is up, ⌘Z / Ctrl+Z also runs the action — island delete
	// is direct-DOM so the browser undo stack never saw it (Austin: toast Undo
	// worked, keyboard didn't).
	let pendingToastUndo = null; // { run: fn, el }

	function clearPendingToastUndo( el ) {
		if ( pendingToastUndo && ( ! el || pendingToastUndo.el === el ) ) pendingToastUndo = null;
	}

	function toastAction( msg, actionLabel, onAction, duration = 7000 ) {
		$$( '.minn-toast' ).forEach( ( t ) => t.remove() );
		clearTimeout( toastTimer );
		clearPendingToastUndo();
		const el = document.createElement( 'div' );
		el.className = 'minn-toast minn-toast-action';
		el.innerHTML = `
			<div class="minn-toast-icon"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="3"><path d="M20 6 9 17l-5-5"/></svg></div>
			<div class="minn-toast-msg">${ esc( msg ) }</div>
			<button class="minn-toast-btn" type="button">${ esc( actionLabel ) }</button>`;
		document.body.appendChild( el );
		const dismiss = () => {
			clearTimeout( toastTimer );
			clearPendingToastUndo( el );
			if ( el.isConnected ) el.remove();
		};
		const run = () => {
			// Clear first so a re-entrant ⌘Z can't double-fire.
			clearPendingToastUndo( el );
			clearTimeout( toastTimer );
			if ( el.isConnected ) el.remove();
			onAction();
		};
		el.querySelector( '.minn-toast-btn' ).addEventListener( 'click', run );
		// Only arm keyboard undo for Undo-style actions (block remove today).
		if ( /^undo$/i.test( String( actionLabel || '' ).trim() ) ) {
			pendingToastUndo = { run, el };
		}
		toastTimer = setTimeout( dismiss, duration );
	}

	// True when a structural Undo toast is live — callers intercept ⌘Z.
	function runPendingToastUndo() {
		if ( ! pendingToastUndo ) return false;
		pendingToastUndo.run();
		return true;
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

	// Surfaces that share a `family` (Snippets / Redirects / Activity Log…) collapse
	// to ONE sidebar item. Preference for which member is active sticks in
	// localStorage; the topbar badge becomes an autocomplete switcher when
	// more than one member is installed.
	function surfacesInFamily( family ) {
		if ( ! family ) return [];
		return ( B.surfaces || [] ).filter( ( s ) => s.family === family );
	}

	function preferredSurfaceId( family ) {
		const members = surfacesInFamily( family );
		if ( ! members.length ) return null;
		try {
			const pref = localStorage.getItem( 'minn-sf-' + family );
			if ( pref && members.some( ( m ) => m.id === pref ) ) return pref;
		} catch ( e ) { /* private mode */ }
		return members[ 0 ].id;
	}

	function setPreferredSurface( family, id ) {
		try { localStorage.setItem( 'minn-sf-' + family, id ); } catch ( e ) { /* private mode */ }
	}

	// Nav entries: first-seen family wins (resolved to the preferred member).
	// `group` places the entry: 'workspace' for inbox-shaped surfaces the
	// descriptor opts into (form entries), everything else defaults to the
	// Tools group so the prime real estate stays curated.
	function surfaceNavItems() {
		const out = [];
		const seen = new Set();
		( B.surfaces || [] ).forEach( ( s ) => {
			if ( s.family ) {
				if ( seen.has( s.family ) ) return;
				seen.add( s.family );
				const id = preferredSurfaceId( s.family );
				const primary = surfaceById( id ) || s;
				out.push( { id: primary.id, label: primary.label, icon: primary.icon || 'plug', family: s.family, group: ( primary.group || s.group ) === 'workspace' ? 'workspace' : 'tools' } );
			} else {
				out.push( { id: s.id, label: s.label, icon: s.icon || 'plug', group: s.group === 'workspace' ? 'workspace' : 'tools' } );
			}
		} );
		return out;
	}

	function newContent( type ) {
		// Navigate to a blank editor for `type` (posts/pages/…). Don't pre-clear
		// editorId/editorType here — onRouteChange compares the previous target
		// to the new one. Nulling id first made editor/posts/1451 → editor/pages
		// look like null → null (no change), so the URL updated but the open
		// post stayed on screen (Austin). Flush + lock release run in onRouteChange.
		go( 'editor/' + type );
	}

	// window.MINN.builders is a boot-time snapshot; a builder toggled during
	// the session (Extensions) makes it stale — the + New menu and the content
	// chips would show the old set. Re-poll after any plugin/theme change.
	async function refreshBuilders() {
		try {
			B.builders = await api( 'minn-admin/v1/builders' );
		} catch ( e ) { /* leave the snapshot as-is */ }
		state.cache.content = null; // chips ride the minn_builder field — refetch
	}

	// Same idea for the editor's block surface: insertBlocks / blockForms /
	// design-library flags / patterns / preview CSS are all boot snapshots.
	// Activating Otter (or any block plugin) mid-session left the slash menu
	// and block picker empty of its blocks until a full page refresh.
	async function refreshEditorBlocks() {
		try {
			const r = await api( 'minn-admin/v1/editor-blocks' );
			B.insertBlocks = Array.isArray( r.insertBlocks ) ? r.insertBlocks : [];
			B.blockForms = r.blockForms && typeof r.blockForms === 'object' ? r.blockForms : {};
			B.designs = Array.isArray( r.designs ) ? r.designs : [];
			// Disable Comments (and friends) strip post-type support mid-session.
			if ( typeof r.comments === 'boolean' ) B.comments = r.comments;
		} catch ( e ) { /* leave the snapshot as-is */ }
		// Design lists + patterns are in-flight promises — drop them so the
		// next slash-menu open refetches against the new plugin set.
		Object.keys( designSourcePromises ).forEach( ( k ) => { delete designSourcePromises[ k ]; } );
		blockPatternsPromise = null;
		// Preview CSS was collected once at first island render; a newly
		// activated block plugin's styles would be missing until reload.
		editorStylesPromise = null;
		const css = document.getElementById( 'minn-frontend-css' );
		if ( css ) css.remove();
		// Editor already open: re-render so bindSlashMenu rebuilds its items
		// array from the fresh B.insertBlocks / B.blockForms (dirty-adopt
		// keeps unsaved body/title — hard-won rule 18).
		if ( state.route === 'editor' && state.editor ) {
			renderEditor();
			ensureEditorStyles();
		}
	}

	// Surfaces (Redirects / Snippets / Forms / Activity Log…) are also a
	// boot snapshot. Deactivating Safe Redirect Manager used to leave its
	// nav item until a hard refresh — re-poll and rebuild just the nav.
	async function refreshSurfaces() {
		const prevIds = new Set( ( B.surfaces || [] ).map( ( s ) => s.id ) );
		try {
			const list = await api( 'minn-admin/v1/surfaces' );
			B.surfaces = Array.isArray( list ) ? list : [];
		} catch ( e ) { /* leave the snapshot as-is */ }

		// If the open view is a surface that just vanished, leave it.
		const stillThere = !! surfaceById( state.route );
		const wasSurface = prevIds.has( state.route )
			|| ( ! TITLES[ state.route ] && state.route !== 'editor' && ! String( state.route ).startsWith( 'editor/' ) );
		if ( wasSurface && ! stillThere && state.route !== 'extensions' ) {
			// Prefer staying in Extensions if the user just toggled from there;
			// otherwise drop to Overview rather than a blank/error view.
			go( state.route === 'extensions' ? 'extensions' : 'overview' );
		}

		renderNavWorkspace();
		// Family switcher may appear/disappear when the member count changes.
		if ( surfaceById( state.route ) ) renderTopbar();
	}

	// Builders + editor blocks + surfaces all go stale on plugin/theme flips
	// — one call site keeps the Extensions handlers honest.
	async function refreshAfterPluginChange() {
		await Promise.all( [ refreshBuilders(), refreshEditorBlocks(), refreshSurfaces() ] );
		// Settings caches provider-derived data (the Spam page most of all) —
		// toggling a spam/SEO plugin mid-session left it stale until reload.
		state.cache.settings = null;
		// Comments nav may have appeared/vanished (Disable Comments toggle).
		renderNavWorkspace();
		if ( state.route === 'comments' && ! commentsAvailable() ) go( 'overview' );
	}

	// Cap + site feature both required (Disable Comments strips the feature
	// while leaving moderate_comments on the role).
	function commentsAvailable() {
		return !!( B.caps && B.caps.moderate && B.comments !== false );
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
		// Active page builders each get a "Page in ⟨builder⟩" entry: one POST
		// creates a prepared draft, then the browser hands over to the
		// builder's own editing surface (docs/page-builders.md).
		const builderRows = ( B.builders || [] ).length && B.caps.editPages
			? `<div class="minn-new-menu-label">Page in…</div>` + B.builders.map( ( b ) =>
				`<button data-newbuilder="${ esc( b.id ) }"><span class="minn-row-icon">${ icon( 'file' ) }</span> ${ esc( b.name ) }</button>` ).join( '' )
			: '';
		menu.innerHTML = `
			<button data-newtype="posts"><span class="minn-row-icon">${ icon( 'pilcrow' ) }</span> Post</button>
			<button data-newtype="pages"><span class="minn-row-icon">${ icon( 'file' ) }</span> Page</button>
			${ builderRows }`;
		const r = btn.getBoundingClientRect();
		menu.style.top = ( r.bottom + 6 ) + 'px';
		menu.style.right = Math.max( 8, window.innerWidth - r.right ) + 'px';
		document.body.appendChild( menu );
		$$( 'button[data-newtype]', menu ).forEach( ( b ) =>
			b.addEventListener( 'click', () => {
				menu.remove();
				newContent( b.dataset.newtype );
			} )
		);
		$$( 'button[data-newbuilder]', menu ).forEach( ( b ) =>
			b.addEventListener( 'click', async () => {
				menu.remove();
				// Name from the registry, not button textContent — that would
				// drag the row-icon glyph into the toast.
				const reg = ( B.builders || [] ).find( ( x ) => x.id === b.dataset.newbuilder );
				toast( `Creating page in ${ reg ? reg.name : 'builder' }…` );
				try {
					const r = await api( 'minn-admin/v1/builders/new', {
						method: 'POST',
						body: JSON.stringify( { builder: b.dataset.newbuilder, type: 'pages' } ),
					} );
					// Hand the tab to the builder — its surface is another app.
					location.href = r.edit_url;
				} catch ( e ) {
					toast( e.message, true );
				}
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
		const prevType = state.editorType;
		parseHash();
		// Editor "target" = type + id. New Post/Page sets id null and may only
		// change type (posts → pages); id-only comparison missed that and also
		// missed newContent() pre-clearing id before go (fixed above).
		const editorTargetChanged = prevRoute === 'editor' && state.route === 'editor'
			&& ( prevId !== state.editorId || prevType !== state.editorType );
		// Leaving the editor (or switching posts/types) fires any pending
		// autosave now, while the editor DOM is still on screen to serialize —
		// and hands the edit lock back.
		if ( prevRoute === 'editor' && ( state.route !== 'editor' || editorTargetChanged ) ) {
			flushAutosave();
			if ( state.editor ) releaseLock( state.editor );
		}
		if ( state.route !== 'editor' || prevRoute !== 'editor' || editorTargetChanged ) {
			// Drop the loaded post when entering the editor or changing target
			// so renderEditor/loadEditor builds the blank (or next) document.
			if ( state.route === 'editor' && ( prevRoute !== 'editor' || editorTargetChanged ) ) {
				state.editor = null;
			}
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
			power: '<path d="M12 2v10"/><path d="M18.4 6.6a9 9 0 1 1-12.77.04"/>',
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
			key: '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6"/><path d="m15.5 7.5 3 3L22 7l-3-3"/>',
			tag: '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/>',
			wrench: '<path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/>',
			shuffle: '<path d="M2 18h1.4c1.3 0 2.5-.6 3.3-1.7l6.6-8.6c.8-1.1 2-1.7 3.3-1.7H22"/><path d="m18 2 4 4-4 4"/><path d="M2 6h1.9c1.5 0 2.9.9 3.6 2.2"/><path d="M22 18h-5.9c-1.4 0-2.6-.7-3.4-1.8l-.5-.8"/><path d="m18 14 4 4-4 4"/>',
			trash: '<path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
			upload: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5"/><path d="M12 3v12"/>',
			logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
			globe: '<circle cx="12" cy="12" r="10"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/><path d="M2 12h20"/>',
			help: '<circle cx="12" cy="12" r="10"/><text x="12" y="16.5" text-anchor="middle" font-size="12.5" font-weight="650" font-family="inherit" fill="currentColor" stroke-width="0">?</text>',
			// Editor toolbar + slash menu glyphs (same lucide/feather family).
			bold: '<path d="M6 12h9a4 4 0 0 1 0 8H7a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h7a4 4 0 0 1 0 8"/>',
			italic: '<line x1="19" y1="4" x2="10" y2="4"/><line x1="14" y1="20" x2="5" y2="20"/><line x1="15" y1="4" x2="9" y2="20"/>',
			code: '<polyline points="16 18 22 12 16 6"/><polyline points="8 6 2 12 8 18"/>',
			braces: '<path d="M8 3H7a2 2 0 0 0-2 2v5a2 2 0 0 1-2 2 2 2 0 0 1 2 2v5c0 1.1.9 2 2 2h1"/><path d="M16 21h1a2 2 0 0 0 2-2v-5c0-1.1.9-2 2-2a2 2 0 0 1-2-2V5a2 2 0 0 0-2-2h-1"/>',
			h2: '<path d="M4 12h8"/><path d="M4 18V6"/><path d="M12 18V6"/><path d="M21 18h-4c0-4 4-3 4-6 0-1.5-2-2.5-4-1"/>',
			h3: '<path d="M4 12h8"/><path d="M4 18V6"/><path d="M12 18V6"/><path d="M17.5 10.5c1.7-1 3.5 0 3.5 1.5a2 2 0 0 1-2 2"/><path d="M17 17.5c2 1.5 4 .3 4-1.5a2 2 0 0 0-2-2"/>',
			quote: '<path d="M16 3a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2 1 1 0 0 1 1 1v1a2 2 0 0 1-2 2 1 1 0 0 0-1 1v2a1 1 0 0 0 1 1 6 6 0 0 0 6-6V5a2 2 0 0 0-2-2z"/><path d="M5 3a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2 1 1 0 0 1 1 1v1a2 2 0 0 1-2 2 1 1 0 0 0-1 1v2a1 1 0 0 0 1 1 6 6 0 0 0 6-6V5a2 2 0 0 0-2-2z"/>',
			link: '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
			pilcrow: '<path d="M13 4v16"/><path d="M17 4v16"/><path d="M19 4H9.5a4.5 4.5 0 0 0 0 9H13"/>',
			olist: '<line x1="10" y1="6" x2="21" y2="6"/><line x1="10" y1="12" x2="21" y2="12"/><line x1="10" y1="18" x2="21" y2="18"/><path d="M4 6h1v4"/><path d="M4 10h2"/><path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/>',
			table: '<path d="M12 3v18"/><rect width="18" height="18" x="3" y="3" rx="2"/><path d="M3 9h18"/><path d="M3 15h18"/>',
			minus: '<path d="M5 12h14"/>',
			play: '<polygon points="6 3 20 12 6 21 6 3"/>',
			gallery: '<path d="M18 22H4a2 2 0 0 1-2-2V6"/><path d="m22 13-1.3-1.3a2.4 2.4 0 0 0-3.4 0L11 18"/><circle cx="12" cy="8" r="2"/><rect width="16" height="16" x="6" y="2" rx="2"/>',
			strike: '<path d="M16 4H9a3 3 0 0 0-2.83 4"/><path d="M14 12a4 4 0 0 1 0 8H6"/><line x1="4" y1="12" x2="20" y2="12"/>',
			eraser: '<path d="M4 7V4h16v3"/><path d="M5 20h6"/><path d="M13 4 8 20"/><path d="m15 15 5 5"/><path d="m20 15-5 5"/>',
			alignCenter: '<line x1="21" y1="6" x2="3" y2="6"/><line x1="17" y1="12" x2="7" y2="12"/><line x1="19" y1="18" x2="5" y2="18"/>',
			// Content-list row markers (a single page vs. a blog post).
			file: '<path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z"/><path d="M9 13h6M9 17h4"/>',
			block: '<path d="M12 2 2 7l10 5 10-5-10-5Z"/><path d="m2 17 10 5 10-5"/><path d="m2 12 10 5 10-5"/>',
			// System page — nav + section headers.
			activity: '<path d="M22 12h-4l-3 9L9 3l-3 9H2"/>',
			cpu: '<rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/><path d="M9 1v3M15 1v3M9 20v3M15 20v3M20 9h3M20 14h3M1 9h3M1 14h3"/>',
			database: '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/>',
			server: '<rect x="2" y="3" width="20" height="8" rx="2"/><rect x="2" y="13" width="20" height="8" rx="2"/><path d="M6 7h.01M6 17h.01"/>',
			// wp + php are the real brand marks (Simple Icons, CC0) — filled paths,
			// not strokes, hence the per-element overrides on the stroke-based frame.
			focus: '<circle cx="12" cy="12" r="3"/><path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2"/>',
			grip: '<g fill="currentColor" stroke-width="0"><circle cx="9" cy="5.5" r="1.6"/><circle cx="15" cy="5.5" r="1.6"/><circle cx="9" cy="12" r="1.6"/><circle cx="15" cy="12" r="1.6"/><circle cx="9" cy="18.5" r="1.6"/><circle cx="15" cy="18.5" r="1.6"/></g>',
			toc: '<path d="M4 5h16M9 10h11M9 14h11M4 19h16"/>',
			wp: '<path fill="currentColor" stroke-width="0" d="M21.469 6.825c.84 1.537 1.318 3.3 1.318 5.175 0 3.979-2.156 7.456-5.363 9.325l3.295-9.527c.615-1.54.82-2.771.82-3.864 0-.405-.026-.78-.07-1.11m-7.981.105c.647-.03 1.232-.105 1.232-.105.582-.075.514-.93-.067-.899 0 0-1.755.135-2.88.135-1.064 0-2.85-.15-2.85-.15-.585-.03-.661.855-.075.885 0 0 .54.061 1.125.09l1.68 4.605-2.37 7.08L5.354 6.9c.649-.03 1.234-.1 1.234-.1.585-.075.516-.93-.065-.896 0 0-1.746.138-2.874.138-.2 0-.438-.008-.69-.015C4.911 3.15 8.235 1.215 12 1.215c2.809 0 5.365 1.072 7.286 2.833-.046-.003-.091-.009-.141-.009-1.06 0-1.812.923-1.812 1.914 0 .89.513 1.643 1.06 2.531.411.72.89 1.643.89 2.977 0 .915-.354 1.994-.821 3.479l-1.075 3.585-3.9-11.61.001.014zM12 22.784c-1.059 0-2.081-.153-3.048-.437l3.237-9.406 3.315 9.087c.024.053.05.101.078.149-1.12.393-2.325.607-3.582.607M1.211 12c0-1.564.336-3.05.935-4.39L7.29 21.709C3.694 19.96 1.212 16.271 1.211 12M12 0C5.385 0 0 5.385 0 12s5.385 12 12 12 12-5.385 12-12S18.615 0 12 0"/>',
			php: '<path fill="currentColor" stroke-width="0" d="M7.01 10.207h-.944l-.515 2.648h.838c.556 0 .97-.105 1.242-.314.272-.21.455-.559.55-1.049.092-.47.05-.802-.124-.995-.175-.193-.523-.29-1.047-.29zM12 5.688C5.373 5.688 0 8.514 0 12s5.373 6.313 12 6.313S24 15.486 24 12c0-3.486-5.373-6.312-12-6.312zm-3.26 7.451c-.261.25-.575.438-.917.551-.336.108-.765.164-1.285.164H5.357l-.327 1.681H3.652l1.23-6.326h2.65c.797 0 1.378.209 1.744.628.366.418.476 1.002.33 1.752a2.836 2.836 0 0 1-.305.847c-.143.255-.33.49-.561.703zm4.024.715l.543-2.799c.063-.318.039-.536-.068-.651-.107-.116-.336-.174-.687-.174H11.46l-.704 3.625H9.388l1.23-6.327h1.367l-.327 1.682h1.218c.767 0 1.295.134 1.586.401s.378.7.263 1.299l-.572 2.944h-1.389zm7.597-2.265a2.782 2.782 0 0 1-.305.847c-.143.255-.33.49-.561.703a2.44 2.44 0 0 1-.917.551c-.336.108-.765.164-1.286.164h-1.18l-.327 1.682h-1.378l1.23-6.326h2.649c.797 0 1.378.209 1.744.628.366.417.477 1.001.331 1.751zm-2.605-1.901h-.943l-.516 2.648h.838c.557 0 .971-.105 1.242-.314.272-.21.455-.559.551-1.049.092-.47.049-.802-.125-.995s-.524-.29-1.047-.29z"/>',
			check: '<path d="M20 6 9 17l-5-5"/>',
			chev: '<path d="m6 9 6 6 6-6"/>',
			warn: '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4M12 17h.01"/>',
			x: '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6M9 9l6 6"/>',
			clipboard: '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>',
			bug: '<path d="M8 2l1.5 1.5M16 2l-1.5 1.5"/><path d="M9 7h6a3 3 0 0 1 3 3v3a6 6 0 0 1-12 0v-3a3 3 0 0 1 3-3Z"/><path d="M3 13h3M18 13h3M4 8l2.5 1.5M20 8l-2.5 1.5M4 18l2.5-1.5M20 18l-2.5-1.5M12 19v3"/>',
			alignRight: '<line x1="21" y1="6" x2="3" y2="6"/><line x1="21" y1="12" x2="9" y2="12"/><line x1="21" y1="18" x2="7" y2="18"/>',
		};
		return `<svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">${ icons[ name ] || '' }</svg>`;
	}

	function navBtnHtml( n ) {
		return `
			<button class="minn-nav-btn" data-nav="${ n.id }"${ n.family ? ` data-family="${ esc( n.family ) }"` : '' }>
				${ icon( n.icon ) }<span>${ esc( n.label ) }</span>
				${ n.count ? '<span class="minn-nav-count" id="minn-content-count" hidden></span>' : '' }
				${ n.commentCount ? '<span class="minn-nav-count" id="minn-comments-count" hidden></span>' : '' }
				${ n.orderCount ? '<span class="minn-nav-count" id="minn-orders-count" hidden></span>' : '' }
				${ n.dot ? '<span class="minn-nav-dot" id="minn-plugin-dot" hidden></span>' : '' }
			</button>`;
	}

	// Workspace nav items: the act-on-it set (Overview, content, incoming
	// human stuff) plus surfaces that opted into group: 'workspace'.
	// Rebuilt when surfaces re-poll so plugin toggles don't leave stale
	// sidebar entries.
	function workspaceNavItems() {
		const navItems = [
			{ id: 'overview', label: 'Overview', icon: 'grid' },
			{ id: 'content', label: 'Content', icon: 'doc', count: true },
			{ id: 'media', label: 'Media', icon: 'img' },
		];
		if ( commentsAvailable() ) {
			navItems.push( { id: 'comments', label: 'Comments', icon: 'chat', commentCount: true } );
		}
		if ( B.wc && B.caps.orders ) {
			navItems.push( { id: 'orders', label: 'Orders', icon: 'cart', orderCount: true } );
		}
		surfaceNavItems().filter( ( s ) => s.group === 'workspace' ).forEach( ( s ) =>
			navItems.push( { id: s.id, label: s.label, icon: s.icon || 'plug', family: s.family || '' } )
		);
		return navItems;
	}

	// Tools nav items: site plumbing (logs, redirects, snippets, backups) —
	// where surface families land unless their descriptor claims workspace.
	function toolsNavItems() {
		return surfaceNavItems().filter( ( s ) => s.group !== 'workspace' )
			.map( ( s ) => ( { id: s.id, label: s.label, icon: s.icon || 'plug', family: s.family || '' } ) );
	}

	function manageNavItems() {
		const manageItems = [];
		if ( B.caps.plugins ) {
			manageItems.push( { id: 'extensions', label: 'Extensions', icon: 'plug', dot: true } );
		}
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
		// One "Structure" item covers Post Types, Taxonomies and Terms as tabs.
		// Admins get all three; an editor (manage_categories only, no
		// manage_options) gets a "Terms" item that opens the same page with
		// just the Terms tab. Different route id per role so the nav highlight
		// and route gating stay correct.
		if ( B.caps.settings ) {
			manageItems.push( { id: 'posttypes', label: 'Structure', icon: 'grid' } );
		} else if ( B.caps.terms ) {
			manageItems.push( { id: 'terms', label: 'Terms', icon: 'tag' } );
		}
		if ( B.caps.settings ) {
			manageItems.push( { id: 'system', label: 'System', icon: 'activity' } );
			manageItems.push( { id: 'settings', label: 'Settings', icon: 'gear' } );
		}
		return manageItems;
	}

	function bindNavClicks( root ) {
		$$( '.minn-nav-btn', root || document ).forEach( ( btn ) => {
			if ( btn._minnNavBound ) return;
			btn._minnNavBound = true;
			btn.addEventListener( 'click', () => go( btn.dataset.nav ) );
		} );
	}

	// Collapsible nav groups — the label row toggles its group's items and
	// the choice persists per group ('minn-nav-collapsed' map).
	function navCollapsedMap() {
		try { return JSON.parse( localStorage.getItem( 'minn-nav-collapsed' ) || '{}' ) || {}; } catch ( e ) { return {}; }
	}

	function navGroupHtml( key, label, items, later ) {
		const collapsed = !! navCollapsedMap()[ key ];
		return `
			<div class="minn-nav-group" id="minn-navgrp-${ key }"${ items.length ? '' : ' hidden' }>
				<button class="minn-nav-label${ later ? ' later' : '' }${ collapsed ? ' collapsed' : '' }" data-navgroup="${ key }" type="button" aria-expanded="${ ! collapsed }">${ esc( label ) }${ icon( 'chev' ) }</button>
				<div id="minn-nav-${ key }"${ collapsed ? ' hidden' : '' }>${ items.map( navBtnHtml ).join( '' ) }</div>
			</div>`;
	}

	function bindNavGroupToggles( root ) {
		$$( '[data-navgroup]', root || document ).forEach( ( btn ) => {
			if ( btn._minnGrpBound ) return;
			btn._minnGrpBound = true;
			btn.addEventListener( 'click', () => {
				const key = btn.dataset.navgroup;
				const body = $( '#minn-nav-' + key );
				const map = navCollapsedMap();
				const collapsed = ! map[ key ];
				map[ key ] = collapsed;
				try { localStorage.setItem( 'minn-nav-collapsed', JSON.stringify( map ) ); } catch ( e ) { /* private mode */ }
				btn.classList.toggle( 'collapsed', collapsed );
				btn.setAttribute( 'aria-expanded', String( ! collapsed ) );
				if ( body ) body.hidden = collapsed;
			} );
		} );
	}

	// Rebuild all nav groups from the current B.surfaces without wiping
	// #minn-view — used after plugin activate/deactivate. Empty groups keep
	// their (hidden) wrapper so a later toggle has somewhere to land.
	function renderNavWorkspace() {
		const ws = $( '#minn-nav-workspace' );
		if ( ! ws ) return;
		// Preserve count/dot badges' filled state across the rebuild by
		// reading current text/hidden flags, then re-applying after.
		const prev = {
			content: $( '#minn-content-count' )?.textContent || '',
			contentHidden: $( '#minn-content-count' )?.hidden,
			comments: $( '#minn-comments-count' )?.textContent || '',
			commentsHidden: $( '#minn-comments-count' )?.hidden,
			orders: $( '#minn-orders-count' )?.textContent || '',
			ordersHidden: $( '#minn-orders-count' )?.hidden,
			dotHidden: $( '#minn-plugin-dot' )?.hidden,
		};
		[ [ 'workspace', workspaceNavItems() ], [ 'tools', toolsNavItems() ], [ 'manage', manageNavItems() ] ].forEach( ( [ key, items ] ) => {
			const body = $( '#minn-nav-' + key );
			if ( ! body ) return;
			body.innerHTML = items.map( navBtnHtml ).join( '' );
			bindNavClicks( body );
			const wrap = $( '#minn-navgrp-' + key );
			if ( wrap ) wrap.hidden = ! items.length;
		} );
		// Restore badges.
		const cc = $( '#minn-content-count' );
		if ( cc && prev.content ) { cc.textContent = prev.content; cc.hidden = !! prev.contentHidden; }
		const cm = $( '#minn-comments-count' );
		if ( cm && prev.comments ) { cm.textContent = prev.comments; cm.hidden = !! prev.commentsHidden; }
		const oc = $( '#minn-orders-count' );
		if ( oc && prev.orders ) { oc.textContent = prev.orders; oc.hidden = !! prev.ordersHidden; }
		const dot = $( '#minn-plugin-dot' );
		if ( dot && prev.dotHidden === false ) dot.hidden = false;
		// Active highlight for the current route.
		$$( '.minn-nav-btn' ).forEach( ( btn ) => {
			const surface = surfaceById( state.route );
			const on = btn.dataset.nav === state.route
				// Structure folds Terms in: an admin on the 'terms' route (deep
				// link / ⌘K) keeps the 'posttypes' Structure item highlighted.
				|| ( 'terms' === state.route && 'posttypes' === btn.dataset.nav )
				|| ( surface && surface.family && btn.dataset.family === surface.family );
			btn.classList.toggle( 'active', on );
		} );
	}

	function renderShell() {
		const manageItems = manageNavItems();

		$( '#minn-app' ).innerHTML = `
		<div class="minn-shell">
			<aside class="minn-sidebar">
				<div class="minn-logo">
					<button class="minn-logo-home" id="minn-logo-home" title="Overview">
						<span class="minn-logo-mark">m</span>
						<span class="minn-logo-name">minn</span>
					</button>
					<button class="minn-logo-ver" id="minn-ver-btn" title="What's new — full changelog">v${ esc( B.version ) }</button>
				</div>
				<button class="minn-search-btn" id="minn-open-palette">
					${ icon( 'search' ) }<span>Search…</span><span class="minn-kbd">⌘K</span>
				</button>
				<div class="minn-nav-scroll">
					${ navGroupHtml( 'workspace', 'Workspace', workspaceNavItems() ) }
					${ navGroupHtml( 'tools', 'Tools', toolsNavItems(), true ) }
					${ navGroupHtml( 'manage', 'Manage', manageItems, true ) }
				</div>
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
						<button class="minn-vis-chip" id="minn-vis-chip" hidden title="Your site is not fully public">${ icon( 'warn' ) }<span id="minn-vis-chip-text"></span></button>
						<button class="minn-core-chip" id="minn-core-chip" hidden title="A WordPress update is available">${ icon( 'refresh' ) }<span id="minn-core-chip-text"></span></button>
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

		bindNavClicks();
		bindNavGroupToggles();
		$( '#minn-open-palette' ).addEventListener( 'click', openPalette );
		$( '#minn-logo-home' ).addEventListener( 'click', () => go( 'overview' ) );
		$( '#minn-ver-btn' ).addEventListener( 'click', openChangelog );

		// Global nav show/hide — a slim tab pinned to the left edge (it must
		// live OUTSIDE the sidebar it hides). Persists; sits under the focus
		// dim so zen keeps its calm.
		const navTab = document.createElement( 'button' );
		navTab.id = 'minn-nav-tab';
		navTab.type = 'button';
		navTab.title = 'Show / hide navigation';
		document.body.appendChild( navTab );
		try {
			if ( localStorage.getItem( 'minn-nav-hidden' ) ) document.body.classList.add( 'minn-nav-hidden' );
		} catch ( e ) { /* private mode */ }
		navTab.addEventListener( 'click', () => {
			const hidden = document.body.classList.toggle( 'minn-nav-hidden' );
			try { localStorage.setItem( 'minn-nav-hidden', hidden ? '1' : '' ); } catch ( e ) { /* private mode */ }
		} );
		$( '#minn-user-area' ).addEventListener( 'click', ( e ) => {
			if ( e.target.closest( 'a' ) ) return; // logout link
			openUserModal( B.user.id );
		} );
		$( '#minn-theme-btn' ).addEventListener( 'click', toggleTheme );
		// Until the user toggles explicitly, the theme follows the OS; the
		// pre-paint script fires this when the system setting flips live.
		document.addEventListener( 'minn-theme-change', renderThemeBtn );
		$( '#minn-help-btn' ).addEventListener( 'click', () => { state.modal = { type: 'help' }; renderOverlays(); } );
		$( '#minn-notif-btn' ).addEventListener( 'click', toggleNotif );
		// Core updates outrank everything else — the chip is visible on every
		// route while one pends and lands on the Overview banner's button.
		$( '#minn-core-chip' ).addEventListener( 'click', () => go( 'overview' ) );
		$( '#minn-vis-chip' ).addEventListener( 'click', ( e ) => openVisibilityPopover( e.currentTarget ) );
		$( '#minn-new-btn' ).addEventListener( 'click', ( e ) => {
			e.stopPropagation();
			if ( B.caps.editPages ) toggleNewMenu( e.currentTarget );
			else newContent( 'posts' );
		} );
		renderThemeBtn();
	}

	// "Page"/"Post"/CPT singular-ish noun for the thing the editor holds.
	function editorNoun( ed ) {
		if ( ! ed ) return 'Post';
		if ( ed.type === 'pages' ) return 'Page';
		if ( ed.type === 'posts' ) return 'Post';
		const t = ( state.cache.types || [] ).find( ( x ) => x.restBase === ed.type );
		return t ? t.name.replace( /s$/, '' ) : 'Post';
	}

	function renderTopbar() {
		const surface = surfaceById( state.route );
		const [ title, sub ] = surface ? [ surface.label, surface.sub || '' ] : ( TITLES[ state.route ] || [ 'minn', '' ] );
		$( '#minn-title' ).textContent = title;
		updateVisChip();
		const subEl = $( '#minn-sub' );
		// The editor pill says WHAT you're editing, not just its status — a
		// blank new page and a blank new post are otherwise indistinguishable.
		if ( state.route === 'editor' && state.editor ) {
			subEl.textContent = state.editor.id
				? `${ editorNoun( state.editor ) } · ${ STATUS_LABELS[ state.editor.status ] || 'Draft' }`
				: `New ${ editorNoun( state.editor ).toLowerCase() }`;
		} else if ( state.route === 'settings' ) {
			subEl.textContent = state.settingsSection || '';
		} else if ( surface && surface.family && surfacesInFamily( surface.family ).length > 1 ) {
			// Multiple adapters of the same family → topbar badge is a switcher.
			const members = surfacesInFamily( surface.family );
			subEl.innerHTML = `
				<div class="minn-ac minn-surface-switch" id="minn-surface-switch" title="Switch provider">
					<input class="minn-input minn-ac-input minn-surface-switch-input" autocomplete="off" spellcheck="false" role="combobox" aria-expanded="false" aria-label="Provider">
					<div class="minn-ac-panel" hidden></div>
				</div>`;
			bindAutocomplete( $( '#minn-surface-switch' ), members.map( ( m ) => ( {
				value: m.id,
				label: m.sub || m.id,
			} ) ), {
				strict: true,
				value: surface.id,
				onPick: ( id ) => {
					if ( id === surface.id ) return;
					setPreferredSurface( surface.family, id );
					// Keep the single sidebar button pointed at the pick so
					// the next click (and active highlight) land correctly.
					const btn = document.querySelector( `.minn-nav-btn[data-family="${ surface.family }"]` );
					if ( btn ) btn.dataset.nav = id;
					go( id );
				},
			} );
		} else {
			subEl.textContent = sub;
		}
		// Family members all light the same sidebar item.
		const activeFamily = surface && surface.family ? surface.family : '';
		$$( '.minn-nav-btn' ).forEach( ( btn ) => {
			const on = btn.dataset.nav === state.route
				// Structure folds Terms in — an admin on the 'terms' route keeps
				// the 'posttypes' Structure item highlighted.
				|| ( 'terms' === state.route && 'posttypes' === btn.dataset.nav )
				|| ( activeFamily && btn.dataset.family === activeFamily );
			btn.classList.toggle( 'active', on );
		} );
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
		// Core updates are important enough for the front page — lazy-load the
		// offer and re-render once when one is pending (same as Extensions).
		if ( B.caps.core && ! state.cache.core ) {
			loadCoreStatus().then( () => { if ( state.route === 'overview' && state.cache.core && state.cache.core.update ) renderOverview(); } );
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
		${ visibilityBannerHtml() }
		${ coreBannerHtml() }
		<div class="minn-stats">
			${ o.stats.map( ( s ) => {
				// Each stat is a door to its view, not just a number.
				const goto = { 'Published posts': 'content:posts', 'Pages': 'content:pages', 'Comments': 'comments', 'Media files': 'media', 'Users': 'users' }[ s.label ] || '';
				return `
				<div class="minn-card minn-stat${ goto ? ' clickable' : '' }"${ goto ? ` data-goto="${ esc( goto ) }" role="link" tabindex="0"` : '' }>
					<div class="minn-stat-label">${ esc( s.label ) }</div>
					<div class="minn-stat-value">${ esc( s.value ) }</div>
					<div class="minn-stat-delta${ deltaCls( s.up ) }">${ esc( s.delta ) }</div>
				</div>`;
			} ).join( '' ) }
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
		bindCoreBanner( view );
		const visBanner = $( '.minn-vis-banner', view );
		if ( visBanner ) {
			const controls = visibilityFixControls();
			$$( '[data-vistoggle]', visBanner ).forEach( ( btn ) =>
				btn.addEventListener( 'click', () => runVisToggle( controls[ +btn.dataset.vistoggle ], btn ) ) );
		}
		$$( '.minn-stat[data-goto]', view ).forEach( ( card ) => {
			const open = () => {
				const [ route, filter ] = card.dataset.goto.split( ':' );
				if ( filter ) { state.filter = filter; state.cache.content = null; }
				go( route );
			};
			card.addEventListener( 'click', open );
			card.addEventListener( 'keydown', ( e ) => { if ( e.key === 'Enter' ) open(); } );
		} );

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
		date: p.date || p.modified,
		modified: p.modified,
		link: p.link || '',
		builder: p.minn_builder || null,
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
		// orderby=date puts scheduled posts (future dates) first, then everything
		// else newest-published first — the list reads as a publishing timeline.
		let q = `context=edit&status=${ statuses }&per_page=25&orderby=date`
			+ `&_embed=author&_fields=id,title,slug,status,date,modified,link,author,minn_builder,_links,_embedded&page=${ page }`;
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
	const contentCtx = () => [ state.filter || 'all', state.contentTrash ? 't' : '', state.contentSearch || '', state.contentCat || '', state.contentTag || '' ].join( '|' );

	async function loadCpt( page = 1 ) {
		const t = currentCpt();
		if ( ! t ) return;
		const ctx = contentCtx();
		const r = await apiPaged( `wp/v2/${ t.restBase }?` + contentQuery( page ) );
		if ( ctx !== contentCtx() ) return; // context changed mid-flight — discard
		state.cache.cptContent[ t.restBase ] = {
			items: r.items.map( mapContentItem( t.restBase ) ),
			page,
			totalPages: r.totalPages,
			total: r.total,
		};
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

	// Posts and pages are separate REST collections, so the merged "All" tab
	// fetches page N of EACH source and shows them merge-sorted — page count is
	// the larger of the two. The type tabs narrow to a single source server-side.
	async function loadContent( page = 1 ) {
		const ctx = contentCtx();
		// Category/tag filters are post-only taxonomies, so suppress pages while one is active.
		const taxFilter = !! ( state.contentCat || state.contentTag );
		const prev = state.cache.content;
		const wantPosts = state.filter === 'all' || state.filter === 'posts';
		const wantPages = ( state.filter === 'all' || state.filter === 'pages' ) && !! B.caps.editPages && ! taxFilter;
		const c = { items: [], page, postPages: 0, pagePages: 0, postTotal: 0, pageTotal: 0 };
		const jobs = [];
		// Requesting a page beyond a source's last one is a REST error, so skip
		// the shorter source once its page count is known from a previous load.
		if ( wantPosts && ! ( prev && page > 1 && page > prev.postPages ) ) {
			jobs.push( apiPaged( 'wp/v2/posts?' + contentQuery( page ) ).then( ( r ) => {
				c.postPages = r.totalPages;
				c.postTotal = r.total;
				c.items.push( ...r.items.map( mapContentItem( 'posts' ) ) );
			} ).catch( ( e ) => { if ( page === 1 ) throw e; } ) );
		} else if ( prev ) {
			c.postPages = prev.postPages;
			c.postTotal = prev.postTotal;
		}
		if ( wantPages && ! ( prev && page > 1 && page > prev.pagePages ) ) {
			jobs.push( apiPaged( 'wp/v2/pages?' + contentQuery( page ) ).then( ( r ) => {
				c.pagePages = r.totalPages;
				c.pageTotal = r.total;
				c.items.push( ...r.items.map( mapContentItem( 'pages' ) ) );
			} ).catch( ( e ) => { if ( page === 1 ) throw e; } ) );
		} else if ( prev && wantPages ) {
			c.pagePages = prev.pagePages;
			c.pageTotal = prev.pageTotal;
		}
		await Promise.all( jobs );
		if ( ctx !== contentCtx() ) return; // context changed mid-flight — discard
		c.items.sort( ( a, b ) => ( a.date < b.date ? 1 : -1 ) );
		c.total = ( c.postTotal || 0 ) + ( c.pageTotal || 0 );
		c.totalPages = Math.max( c.postPages || 0, c.pagePages || 0, 1 );
		state.cache.content = c;

		// The sidebar badge is the ALL-content count — only a filterless load knows it.
		const badge = $( '#minn-content-count' );
		if ( badge && state.filter === 'all' && ! state.contentSearch && ! state.contentTrash && ! taxFilter ) {
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
		// Type tabs narrow the query server-side now — items arrive pre-filtered.
		const filtered = c.items;
		const tabs = [ [ 'all', 'All' ], [ 'posts', 'Posts' ],
			...( B.caps.editPages ? [ [ 'pages', 'Pages' ] ] : [] ),
			...( state.cache.types || [] ).map( ( t ) => [ t.restBase, t.name ] ) ];
		const rowIcon = ( p ) => icon( p.type === 'pages' ? 'file' : ( p.type === 'posts' ? 'pilcrow' : 'block' ) );
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
			<div class="minn-tabs minn-tabs-aux">
				<button class="minn-tab${ state.contentTrash ? ' active' : '' }" id="minn-content-trash" title="${ state.contentTrash ? 'Back to content' : 'View trash' }">Trash</button>
			</div>
			${ showTax ? taxCombo( 'cat', 'All categories' ) : '' }
			${ showTax ? taxCombo( 'tag', 'All tags' ) : '' }
			<input class="minn-input minn-toolbar-search" id="minn-content-search" placeholder="Search content…" value="${ esc( state.contentSearch || '' ) }">
			<div class="minn-toolbar-meta">${ metaLabel( c.total, 'item' ) }</div>
		</div>
		<div id="minn-bulk-slot"></div>
		<div class="minn-card minn-table">
			<div class="minn-table-head minn-content-cols${ state.contentTrash ? ' trash' : '' }">
				<div><input type="checkbox" class="minn-cb" id="minn-sel-all"${ filtered.length && filtered.every( ( p ) => sel.has( p.id ) ) ? ' checked' : '' }></div>
				<div></div><div>Title</div><div>Status</div><div>Author</div><div>Date</div><div></div>
			</div>
			${ filtered.length ? filtered.map( ( p ) => `
				<div class="minn-table-row minn-content-cols${ state.contentTrash ? ' trash' : '' }${ sel.has( p.id ) ? ' sel' : '' }" data-id="${ p.id }" data-type="${ esc( p.type ) }" data-status="${ esc( p.status ) }" data-link="${ esc( p.link || '' ) }">
					<div class="minn-cbcell"><input type="checkbox" class="minn-cb minn-row-cb" data-cbid="${ p.id }"${ sel.has( p.id ) ? ' checked' : '' }></div>
					<div class="minn-row-icon">${ rowIcon( p ) }</div>
					<div class="minn-cell-clip">
						<div class="minn-row-title">${ esc( p.title ) }</div>
						<div class="minn-row-slug">
							<span class="minn-row-slug-text">${ esc( p.slug ) }</span>
							${ p.builder ? `<span class="minn-builder-chip" title="Managed with ${ esc( p.builder.name ) }">${ esc( p.builder.name ) }</span>` : '' }
						</div>
					</div>
					<div><span class="minn-status ${ esc( p.status ) }">${ STATUS_LABELS[ p.status ] || esc( p.status ) }</span></div>
					<div class="minn-row-meta">${ esc( p.author ) }</div>
					<div class="minn-row-meta" title="${ esc( parseWpDate( p.date ).toLocaleString() ) }">${ timeAgo( p.date ) }</div>
					${ state.contentTrash ? `
					<div class="minn-row-actions">
						<button class="minn-btn-soft" data-restore="${ p.id }">Restore</button>
						<button class="minn-btn-soft danger" data-fdelete="${ p.id }">Delete</button>
					</div>` : `<div class="minn-row-end"><button class="minn-row-more" data-more="${ p.id }" type="button" title="Actions">⋯</button><span class="minn-row-arrow">›</span></div>` }
				</div>` ).join( '' ) : `<div class="minn-empty">${ state.contentSearch ? 'No matches for “' + esc( state.contentSearch ) + '”.' : ( state.contentTrash ? 'Trash is empty.' : 'Nothing here yet. Hit <b>New</b> to write something.' ) }</div>` }
		</div>
		${ pagerHtml( c.page, c.totalPages, c.total, 'item' ) }`;

		$$( '.minn-tab[data-filter]', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', () => {
				const nf = btn.dataset.filter;
				// Leaving the posts context clears post-only taxonomy filters.
				if ( ( nf === 'pages' || ( state.cache.types || [] ).some( ( t ) => t.restBase === nf ) ) && ( state.contentCat || state.contentTag ) ) {
					state.contentCat = null;
					state.contentTag = null;
				}
				state.filter = nf;
				// Tabs are a server-side query now — refetch from page 1.
				state.cache.content = null;
				state.cache.cptContent = {};
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
		// Row actions — right-click a row (or its hover ⋯) for quick moves
		// without opening the editor: the bounce-audit's top friction item,
		// re-imagined as Minn's context-menu pattern instead of wp-admin's
		// hover-link litter.
		let rowMenu = null;
		const hideRowMenu = () => {
			if ( rowMenu ) rowMenu.remove();
			rowMenu = null;
			document.removeEventListener( 'mousedown', rowMenuAway, true );
		};
		const rowMenuAway = ( e ) => { if ( rowMenu && ! rowMenu.contains( e.target ) ) hideRowMenu(); };
		const rowQuick = async ( p, body, msg, method ) => {
			hideRowMenu();
			try {
				await api( `wp/v2/${ p.type }/${ p.id }`, {
					method: method || 'POST',
					body: body ? JSON.stringify( body ) : undefined,
				} );
				toast( msg );
				sel.clear();
				state.cache.content = null;
				state.cache.cptContent = {};
				await ( currentCpt() ? loadCpt() : loadContent() ).catch( showErr );
				if ( state.route === 'content' ) renderContent();
			} catch ( e ) {
				toast( e.message, true );
			}
		};
		const openRowMenu = ( x, y, p ) => {
			hideRowMenu();
			const viewUrl = p.link ? ( p.status === 'publish' ? p.link : p.link + ( p.link.includes( '?' ) ? '&' : '?' ) + 'preview=true' ) : '';
			rowMenu = document.createElement( 'div' );
			rowMenu.className = 'minn-new-menu minn-row-menu';
			rowMenu.innerHTML = `
				<button type="button" data-ract="open">Open in Minn</button>
				${ viewUrl ? `<a href="${ esc( viewUrl ) }" target="_blank" rel="noopener">${ p.status === 'publish' ? 'View on site' : 'Preview draft' } ↗</a>` : '' }
				<a href="${ esc( B.site.adminUrl ) }post.php?post=${ p.id }&action=edit" target="_blank" rel="noopener">Edit in block editor ↗</a>
				<button type="button" data-ract="duplicate">Duplicate</button>
				<div class="minn-new-menu-label">Status</div>
				${ p.status !== 'publish' ? '<button type="button" data-ract="publish">Publish now</button>' : '' }
				${ p.status !== 'draft' ? '<button type="button" data-ract="draft">Move to draft</button>' : '' }
				<button type="button" data-ract="trash" class="danger">Move to trash</button>`;
			document.body.appendChild( rowMenu );
			rowMenu.style.left = Math.max( 10, Math.min( x, window.innerWidth - rowMenu.offsetWidth - 10 ) ) + 'px';
			rowMenu.style.top = Math.max( 10, Math.min( y, window.innerHeight - rowMenu.offsetHeight - 10 ) ) + 'px';
			$$( '[data-ract]', rowMenu ).forEach( ( b ) => b.addEventListener( 'click', () => {
				const act = b.dataset.ract;
				if ( act === 'open' ) { hideRowMenu(); go( `editor/${ p.type }/${ p.id }` ); }
				else if ( act === 'duplicate' ) {
					hideRowMenu();
					api( `minn-admin/v1/posts/${ p.id }/duplicate`, { method: 'POST', body: '{}' } )
						.then( async ( r ) => {
							state.cache.content = null;
							state.cache.cptContent = {};
							await ( currentCpt() ? loadCpt() : loadContent() ).catch( () => {} );
							if ( state.route === 'content' ) renderContent();
							toastAction( `Duplicated as draft “${ r.title }”`, 'Open', () => go( `editor/${ p.type }/${ r.id }` ) );
						} )
						.catch( ( e ) => toast( e.message, true ) );
				}
				else if ( act === 'publish' ) rowQuick( p, { status: 'publish' }, 'Published' );
				else if ( act === 'draft' ) rowQuick( p, { status: 'draft' }, 'Moved to draft' );
				else if ( act === 'trash' ) rowQuick( p, null, 'Moved to trash', 'DELETE' );
			} ) );
			$$( 'a', rowMenu ).forEach( ( a ) => a.addEventListener( 'click', hideRowMenu ) );
			document.addEventListener( 'mousedown', rowMenuAway, true );
		};
		// The menu's inputs ride the row's own data attrs — no cache coupling.
		const rowItem = ( el ) => ( {
			id: parseInt( el.dataset.id, 10 ),
			type: el.dataset.type,
			status: el.dataset.status,
			link: el.dataset.link,
		} );
		if ( ! state.contentTrash ) {
			$$( '.minn-table-row[data-id]', view ).forEach( ( row ) => {
				row.addEventListener( 'contextmenu', ( e ) => {
					const p = rowItem( row );
					if ( ! p ) return;
					e.preventDefault();
					openRowMenu( e.clientX, e.clientY, p );
				} );
				const more = row.querySelector( '.minn-row-more' );
				if ( more ) more.addEventListener( 'click', ( e ) => {
					e.stopPropagation(); // never open the editor
					const p = rowItem( row );
					if ( ! p ) return;
					const r = more.getBoundingClientRect();
					openRowMenu( r.left - 150, r.bottom + 6, p );
				} );
			} );
		}

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
		bindPager( view, c.page, ( p ) => ( cpt ? loadCpt( p ) : loadContent( p ) ), () => { if ( state.route === 'content' ) renderContent(); } );
	}

	/* ===== Media ===== */

	const MEDIA_TYPES = [ [ '', 'All' ], [ 'image', 'Images' ], [ 'video', 'Video' ], [ 'audio', 'Audio' ], [ 'application', 'Docs' ] ];

	// Like contentCtx: a load started before the search/type filter changed
	// must not land its rows into the new context.
	const mediaCtx = () => ( state.mediaSearch || '' ) + '|' + ( state.mediaType || '' );

	async function loadMedia( page = 1 ) {
		const ctx = mediaCtx();
		let q = `wp/v2/media?per_page=48&orderby=date&order=desc&_fields=id,title,mime_type,source_url,media_details,date,alt_text&page=${ page }`;
		if ( state.mediaSearch ) q += '&search=' + encodeURIComponent( state.mediaSearch );
		if ( state.mediaType ) q += '&media_type=' + encodeURIComponent( state.mediaType );
		const r = await apiPaged( q );
		if ( ctx !== mediaCtx() ) return; // filter changed mid-flight — discard
		state.cache.media = { items: r.items, page, totalPages: r.totalPages, total: r.total };
	}

	/* ===== Image editor (rotate + crop over core's media/{id}/edit) ===== */
	// Core's REST image editor does all the pixel work server-side and saves
	// a NEW copy — Minn only draws the preview (canvas, rotation-aware) and a
	// drag crop box. Crop percentages are relative to the POST-rotation image,
	// exactly what the modifiers contract expects (applied in order).
	function bindImageEditor( m ) {
		const it = m.item;
		const stage = $( '#minn-imged-stage' );
		const canvas = $( '#minn-imged-canvas' );
		const box = $( '#minn-imged-crop' );
		if ( ! stage || ! canvas ) return;
		m.rot = m.rot || 0;
		m.crop = m.crop || null; // { x, y, w, h } percentages of the canvas

		const draw = () => {
			const img = m._img;
			if ( ! img ) return;
			const rotated = m.rot % 180 !== 0;
			const iw = rotated ? img.naturalHeight : img.naturalWidth;
			const ih = rotated ? img.naturalWidth : img.naturalHeight;
			const scale = Math.min( 640 / iw, 420 / ih, 1 );
			canvas.width = Math.round( iw * scale );
			canvas.height = Math.round( ih * scale );
			const cx = canvas.getContext( '2d' );
			cx.save();
			cx.translate( canvas.width / 2, canvas.height / 2 );
			cx.rotate( ( m.rot * Math.PI ) / 180 );
			cx.drawImage( img, -img.naturalWidth * scale / 2, -img.naturalHeight * scale / 2, img.naturalWidth * scale, img.naturalHeight * scale );
			cx.restore();
			positionBox();
		};

		const positionBox = () => {
			if ( ! m.crop ) { box.hidden = true; return; }
			box.hidden = false;
			const r = canvas.getBoundingClientRect();
			const s = stage.getBoundingClientRect();
			box.style.left = ( r.left - s.left + ( m.crop.x / 100 ) * r.width ) + 'px';
			box.style.top = ( r.top - s.top + ( m.crop.y / 100 ) * r.height ) + 'px';
			box.style.width = ( ( m.crop.w / 100 ) * r.width ) + 'px';
			box.style.height = ( ( m.crop.h / 100 ) * r.height ) + 'px';
		};

		if ( m._img ) draw();
		else {
			const img = new Image();
			img.onload = () => { m._img = img; draw(); };
			img.src = it.url;
		}

		// Crop interactions: drag on the canvas starts a new box; drag the box
		// moves it; corner handles resize. All math in canvas percentages.
		const pctOf = ( e ) => {
			const r = canvas.getBoundingClientRect();
			return {
				x: Math.max( 0, Math.min( 100, ( ( e.clientX - r.left ) / r.width ) * 100 ) ),
				y: Math.max( 0, Math.min( 100, ( ( e.clientY - r.top ) / r.height ) * 100 ) ),
			};
		};
		let drag = null;
		stage.addEventListener( 'pointerdown', ( e ) => {
			const h = e.target.dataset && e.target.dataset.h;
			const p = pctOf( e );
			if ( h ) drag = { mode: h, orig: { ...m.crop } };
			else if ( e.target === box ) drag = { mode: 'move', start: p, orig: { ...m.crop } };
			else if ( e.target === canvas ) { drag = { mode: 'new', start: p }; m.crop = { x: p.x, y: p.y, w: 0, h: 0 }; }
			else return;
			e.preventDefault();
			stage.setPointerCapture && stage.setPointerCapture( e.pointerId );
		} );
		stage.addEventListener( 'pointermove', ( e ) => {
			if ( ! drag || ! m.crop ) return;
			const p = pctOf( e );
			const c = m.crop;
			if ( drag.mode === 'new' ) {
				c.x = Math.min( drag.start.x, p.x );
				c.y = Math.min( drag.start.y, p.y );
				c.w = Math.abs( p.x - drag.start.x );
				c.h = Math.abs( p.y - drag.start.y );
			} else if ( drag.mode === 'move' ) {
				c.x = Math.max( 0, Math.min( 100 - c.w, drag.orig.x + ( p.x - drag.start.x ) ) );
				c.y = Math.max( 0, Math.min( 100 - c.h, drag.orig.y + ( p.y - drag.start.y ) ) );
			} else {
				const o = drag.orig;
				const right = o.x + o.w;
				const bottom = o.y + o.h;
				if ( drag.mode.includes( 'w' ) ) { c.x = Math.min( p.x, right - 2 ); c.w = right - c.x; }
				if ( drag.mode.includes( 'e' ) ) { c.w = Math.max( 2, p.x - o.x ); }
				if ( drag.mode.includes( 'n' ) ) { c.y = Math.min( p.y, bottom - 2 ); c.h = bottom - c.y; }
				if ( drag.mode.includes( 's' ) ) { c.h = Math.max( 2, p.y - o.y ); }
			}
			positionBox();
		} );
		stage.addEventListener( 'pointerup', () => {
			if ( drag && m.crop && ( m.crop.w < 2 || m.crop.h < 2 ) ) { m.crop = null; positionBox(); }
			drag = null;
		} );

		$( '#minn-imged-rl' ).addEventListener( 'click', () => { m.rot = ( m.rot + 270 ) % 360; m.crop = null; draw(); } );
		$( '#minn-imged-rr' ).addEventListener( 'click', () => { m.rot = ( m.rot + 90 ) % 360; m.crop = null; draw(); } );
		$( '#minn-imged-reset' ).addEventListener( 'click', () => { m.rot = 0; m.crop = null; draw(); } );
		$( '#minn-imged-cancel' ).addEventListener( 'click', () => { m.editing = false; renderOverlays(); } );
		$( '#minn-imged-save' ).addEventListener( 'click', async ( e ) => {
			const modifiers = [];
			if ( m.rot ) modifiers.push( { type: 'rotate', args: { angle: m.rot } } );
			if ( m.crop ) modifiers.push( { type: 'crop', args: { left: m.crop.x, top: m.crop.y, width: m.crop.w, height: m.crop.h } } );
			if ( ! modifiers.length ) { toast( 'Nothing to save — rotate or crop first', true ); return; }
			const btn = e.currentTarget;
			btn.disabled = true;
			btn.textContent = 'Saving…';
			try {
				const fresh = await api( `wp/v2/media/${ it.id }/edit`, {
					method: 'POST',
					body: JSON.stringify( { src: it.url, modifiers } ),
				} );
				const mapped = mapMediaItem( fresh );
				// Editing the featured image: adopt the new copy as featured so
				// the post doesn't keep pointing at the pre-edit original.
				if ( m.from === 'featured' && state.editor ) {
					state.editor.featuredMedia = mapped.id;
					state.editor.featuredThumb = mapped.thumb || mapped.url;
					state.editor.featuredDirty = true;
					renderEditorSide();
					if ( state.editor.id ) scheduleAutosave();
					toast( 'Edited copy saved · set as featured image' );
				} else {
					toast( 'Edited copy saved' );
				}
				state.cache.media = null;
				if ( state.route === 'media' ) renderMedia();
				// Land on the new copy's preview (keep featured context).
				state.modal = { type: 'media', item: mapped, from: m.from || null };
				renderOverlays();
			} catch ( err ) {
				toast( err.message, true );
				btn.disabled = false;
				btn.textContent = 'Save as copy';
			}
		} );
	}

	// Bulk delete for the media library's selection (force=true — attachments
	// have no trash by default). One confirm, then per-item so one failure
	// never aborts the rest.
	async function bulkDeleteMedia( btn ) {
		const ids = Array.from( state.mediaSel || [] );
		if ( ! ids.length ) return;
		if ( ! confirm( `Delete ${ ids.length } file${ ids.length === 1 ? '' : 's' } permanently? This cannot be undone.` ) ) return;
		btn.disabled = true;
		btn.textContent = 'Deleting…';
		let ok = 0, fail = 0;
		for ( const id of ids ) {
			try { await api( `wp/v2/media/${ id }?force=true`, { method: 'DELETE' } ); ok++; }
			catch ( e ) { fail++; }
		}
		state.mediaSel.clear();
		state.mediaLastIdx = null;
		state.cache.media = null;
		toast( fail ? `Deleted ${ ok }, ${ fail } failed` : `Deleted ${ ok } file${ ok === 1 ? '' : 's' }`, fail > 0 && ok === 0 );
		if ( state.route === 'media' ) renderMedia();
	}

	// Shared by the preview modal's Delete and the grid context menu.
	async function deleteMediaItem( it ) {
		if ( ! confirm( `Delete “${ it.name }” permanently?` ) ) return;
		try {
			await api( `wp/v2/media/${ it.id }?force=true`, { method: 'DELETE' } );
			toast( 'File deleted' );
			// If this was the current featured image, clear it so the sidebar
			// doesn't keep a broken thumb.
			if ( state.editor && state.editor.featuredMedia === it.id ) {
				state.editor.featuredMedia = 0;
				state.editor.featuredThumb = null;
				state.editor.featuredDirty = true;
				renderEditorSide();
				if ( state.editor.id ) scheduleAutosave();
			}
			if ( state.modal && state.modal.type === 'media' ) closeModal();
			state.cache.media = null;
			if ( state.route === 'media' ) renderMedia();
		} catch ( e ) {
			toast( e.message, true );
		}
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
			// caption/description are edit-context raw, filled lazily when the
			// detail modal opens (the list fetch stays view-context + light).
			caption: ( m.caption && typeof m.caption.raw === 'string' ) ? m.caption.raw : '',
			description: ( m.description && typeof m.description.raw === 'string' ) ? m.description.raw : '',
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
		const countLabel = metaLabel( c.total, 'file' );
		const thumbStyle = ( m ) => m.thumb
			? `background-image:url('${ esc( m.thumb ) }')`
			: `background:${ m.grad }`;

		view.innerHTML = `
		<div class="minn-toolbar">
			<div class="minn-tabs">
				${ MEDIA_TYPES.map( ( [ id, label ] ) =>
					`<button class="minn-tab${ ( state.mediaType || '' ) === id ? ' active' : '' }" data-mtype="${ id }">${ label }</button>` ).join( '' ) }
			</div>
			<input class="minn-input minn-toolbar-search" id="minn-media-search" placeholder="Search files…" value="${ esc( state.mediaSearch || '' ) }">
			<div class="minn-toolbar-meta">${ countLabel }</div>
			<div class="minn-view-tabs" style="margin-left:0;">
				<button class="minn-view-tab${ state.mediaView === 'grid' ? ' active' : '' }" data-view="grid" title="Grid">${ icon( 'grid' ) }</button>
				<button class="minn-view-tab${ state.mediaView === 'list' ? ' active' : '' }" data-view="list" title="List">${ icon( 'list' ) }</button>
			</div>
			${ B.caps.upload ? `<button class="minn-btn-soft" id="minn-upload-btn">${ icon( 'upload' ) } Upload</button><input type="file" id="minn-upload-input" multiple hidden>` : '' }
		</div>
		<div id="minn-media-bulk-slot"></div>
		${ state.uploadOpen && B.caps.upload ? `
		<div class="minn-dropzone" id="minn-dropzone">
			${ icon( 'upload' ) }
			<div class="minn-dropzone-title">Drag &amp; drop files here</div>
			<div class="minn-dropzone-sub">or <b>browse your computer</b></div>
		</div>` : '' }
		${ ! mapped.length ? `<div class="minn-card minn-empty">${ state.mediaSearch || state.mediaType ? 'No files match.' : 'The media library is empty. Drop files anywhere to upload.' }</div>` : state.mediaView === 'grid' ? `
		<div class="minn-media-grid">
			${ mapped.map( ( m ) => `
				<div class="minn-media-card" data-media="${ m.id }">
					${ B.caps.upload ? `<label class="minn-media-check" title="Select"><input type="checkbox" class="minn-media-cb" data-cbid="${ m.id }"></label>` : '' }
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
					${ B.caps.upload ? `<label class="minn-media-check row" title="Select"><input type="checkbox" class="minn-media-cb" data-cbid="${ m.id }"></label>` : '' }
					<div class="minn-media-thumb-sm" style="${ thumbStyle( m ) }"></div>
					<div class="minn-media-col">
						<div class="minn-row-title">${ esc( m.name ) }</div>
						<div class="minn-row-slug">${ m.kind }</div>
					</div>
					<div class="minn-media-dims">${ esc( m.dims ) }</div>
					<div class="minn-media-size">${ esc( m.size ) }</div>
				</div>` ).join( '' ) }
		</div>` }
		${ pagerHtml( c.page, c.totalPages, c.total, 'file' ) }`;

		$$( '.minn-view-tab', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', () => { state.mediaView = btn.dataset.view; renderMedia(); } )
		);
		$$( '[data-mtype]', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', () => {
				state.mediaType = btn.dataset.mtype || null;
				state.cache.media = null;
				renderMedia();
			} )
		);
		const mediaSearch = $( '#minn-media-search', view );
		if ( mediaSearch ) {
			let t = null;
			mediaSearch.addEventListener( 'input', () => {
				clearTimeout( t );
				t = setTimeout( async () => {
					state.mediaSearch = mediaSearch.value.trim();
					state.cache.media = null;
					const grid = $( '.minn-media-grid, .minn-media-list', view );
					if ( grid ) grid.classList.add( 'minn-busy' );
					await loadMedia().catch( showErr );
					if ( state.route === 'media' ) {
						renderMedia();
						const again = $( '#minn-media-search' );
						if ( again ) { again.focus(); again.setSelectionRange( again.value.length, again.value.length ); }
					}
				}, 350 );
			} );
		}
		$$( '[data-media]', view ).forEach( ( el ) => {
			const itemOf = () => mapped.find( ( x ) => x.id === parseInt( el.dataset.media, 10 ) );
			el.addEventListener( 'click', () => {
				const m = itemOf();
				if ( m ) { state.modal = { type: 'media', item: m }; renderOverlays(); }
			} );
			// Right-click: the item's verbs without opening the preview.
			el.addEventListener( 'contextmenu', ( e ) => {
				const m = itemOf();
				if ( ! m ) return;
				e.preventDefault();
				openMinnMenu( e.clientX, e.clientY, [
					{ label: 'Preview', run: () => { state.modal = { type: 'media', item: m }; renderOverlays(); } },
					{ label: 'Copy URL', run: async () => {
						try { await navigator.clipboard.writeText( m.url ); toast( 'URL copied' ); }
						catch ( err ) { toast( 'Could not copy', true ); }
					} },
					{ label: 'Open ↗', href: m.url },
					...( m.kind === 'IMG' ? [ { label: 'Edit image', run: () => { state.modal = { type: 'media', item: m, editing: true }; renderOverlays(); } } ] : [] ),
					{ label: 'Delete', danger: true, run: () => deleteMediaItem( m ) },
				] );
			} );
		} );
		// Bulk select + delete — mirrors the content-list pattern (shift-range,
		// select-count bar in a slot updated in place, no full re-render on toggle).
		if ( B.caps.upload ) {
			const msel = state.mediaSel || ( state.mediaSel = new Set() );
			const syncMediaBulk = () => {
				const slot = $( '#minn-media-bulk-slot', view );
				if ( ! slot ) return;
				if ( ! msel.size ) { slot.innerHTML = ''; return; }
				if ( ! $( '.minn-bulkbar', slot ) ) {
					slot.innerHTML = `
						<div class="minn-bulkbar">
							<span class="minn-bulk-count">${ msel.size } selected</span>
							<button class="minn-btn-soft danger" id="minn-media-bulk-delete">${ icon( 'trash' ) } Delete permanently</button>
							<button class="minn-btn-soft" id="minn-media-bulk-clear" style="margin-left:auto;">Clear</button>
						</div>`;
					$( '#minn-media-bulk-delete', slot ).addEventListener( 'click', ( e ) => bulkDeleteMedia( e.currentTarget ) );
					$( '#minn-media-bulk-clear', slot ).addEventListener( 'click', () => {
						msel.clear();
						$$( '.minn-media-cb', view ).forEach( ( c ) => { c.checked = false; c.closest( '[data-media]' ).classList.remove( 'sel' ); } );
						syncMediaBulk();
					} );
				} else {
					$( '.minn-bulk-count', slot ).textContent = msel.size + ' selected';
				}
			};
			// Reflect a selection that survived a re-render (paginate, search).
			$$( '.minn-media-cb', view ).forEach( ( cb ) => {
				const id = parseInt( cb.dataset.cbid, 10 );
				if ( msel.has( id ) ) { cb.checked = true; cb.closest( '[data-media]' ).classList.add( 'sel' ); }
			} );
			syncMediaBulk();
			const setMediaSel = ( m, on ) => {
				if ( on ) msel.add( m.id ); else msel.delete( m.id );
				const cb = view.querySelector( `.minn-media-cb[data-cbid="${ m.id }"]` );
				if ( cb ) { cb.checked = on; cb.closest( '[data-media]' ).classList.toggle( 'sel', on ); }
			};
			$$( '.minn-media-cb', view ).forEach( ( cb ) =>
				cb.addEventListener( 'click', ( e ) => {
					e.stopPropagation(); // never open the preview modal from the checkbox
					const id = parseInt( cb.dataset.cbid, 10 );
					const idx = mapped.findIndex( ( m ) => m.id === id );
					if ( e.shiftKey && state.mediaLastIdx != null && state.mediaLastIdx !== idx && mapped[ state.mediaLastIdx ] ) {
						const lo = Math.min( state.mediaLastIdx, idx ), hi = Math.max( state.mediaLastIdx, idx );
						for ( let i = lo; i <= hi; i++ ) setMediaSel( mapped[ i ], cb.checked );
					} else {
						setMediaSel( mapped[ idx ], cb.checked );
					}
					state.mediaLastIdx = idx;
					syncMediaBulk();
				} )
			);
		}
		bindPager( view, c.page, loadMedia, () => { if ( state.route === 'media' ) renderMedia(); } );
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

	// Post-title lookups survive page changes — comments on the same posts
	// recur across pages, so keep the map for the session.
	const commentPostTitles = {};

	async function loadComments( page = 1 ) {
		const tab = state.commentTab;
		const r = await apiPaged( `wp/v2/comments?context=edit&status=${ tab }&per_page=25&page=${ page }&_fields=id,author_name,author_avatar_urls,content,date,post` );
		if ( tab !== state.commentTab ) return; // tab changed mid-flight — discard
		const c = { items: r.items, page, totalPages: r.totalPages, total: r.total, postTitles: commentPostTitles };
		// Resolve post titles in one cheap request (no content rendering).
		const ids = [ ...new Set( r.items.map( ( cm ) => cm.post ).filter( ( id ) => id && ! commentPostTitles[ id ] ) ) ];
		if ( ids.length ) {
			try {
				const posts = await api( `wp/v2/posts?include=${ ids.join( ',' ) }&per_page=${ ids.length }&_fields=id,title&status=publish,future,draft,pending,private&context=edit` );
				posts.forEach( ( p ) => { commentPostTitles[ p.id ] = decodeEntities( p.title.rendered ); } );
			} catch ( e ) {}
		}
		state.cache.comments = c;
	}

	async function refreshCommentBadge() {
		if ( ! commentsAvailable() ) return;
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

	// Bulk moderation for the comments selection. Verbs are the current tab's
	// own row actions, so the batch can never apply a status the tab doesn't
	// offer. Per-item so one failure never aborts the rest.
	async function runCommentBulk( status, label, btn ) {
		const ids = Array.from( state.commentSel || [] );
		if ( ! ids.length ) return;
		if ( 'delete' === status && ! confirm( `Delete ${ ids.length } comment${ ids.length === 1 ? '' : 's' } permanently?` ) ) return;
		btn.disabled = true;
		btn.textContent = 'Working…';
		let ok = 0, fail = 0;
		for ( const id of ids ) {
			try {
				if ( 'delete' === status ) await api( `wp/v2/comments/${ id }?force=true`, { method: 'DELETE' } );
				else await api( `wp/v2/comments/${ id }`, { method: 'POST', body: JSON.stringify( { status } ) } );
				ok++;
			} catch ( e ) { fail++; }
		}
		state.commentSel.clear();
		state.commentLastIdx = null;
		state.cache.comments = null;
		refreshCommentBadge();
		toast( fail ? `${ label }: ${ ok } done, ${ fail } failed` : `${ label } (${ ok })`, fail > 0 && ok === 0 );
		if ( state.route === 'comments' ) renderComments();
	}

	function renderComments() {
		const view = $( '#minn-view' );
		if ( ! commentsAvailable() ) {
			view.innerHTML = '<div class="minn-empty">Comments are disabled on this site.</div>';
			return;
		}
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
			postId: cm.post,
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
			<label class="minn-selall-inline"${ rows.length ? '' : ' hidden' }><input type="checkbox" id="minn-comment-selall"> Select page</label>
			<div class="minn-toolbar-meta">${ metaLabel( c.total, 'comment' ) }</div>
		</div>
		<div id="minn-comment-bulk-slot"></div>
		<div class="minn-card">
			${ rows.length ? rows.map( ( r ) => `
				<div class="minn-comment-row" data-crow="${ r.id }">
					<label class="minn-comment-check" title="Select"><input type="checkbox" class="minn-comment-cb" data-cbid="${ r.id }"></label>
					${ r.avatar ? `<img class="minn-comment-avatar" src="${ esc( r.avatar ) }" alt="">` : '<div class="minn-comment-avatar"></div>' }
					<div class="minn-comment-body">
						<div class="minn-comment-head">
							<span class="minn-comment-author">${ esc( r.author ) }</span>
							<span class="minn-comment-on">on ${ esc( r.post ) }</span>
							<span class="minn-comment-time">${ timeAgo( r.date ) }</span>
						</div>
						<div class="minn-comment-text">${ esc( r.excerpt ) }</div>
						<div class="minn-comment-actions">
							${ [ 'hold', 'approve' ].includes( state.commentTab ) ? `<button class="minn-comment-action" data-creply="${ r.id }">${ state.commentReply === r.id ? 'Close' : 'Reply' }</button>` : '' }
							${ actionsFor().map( ( [ st, label ] ) =>
								`<button class="minn-comment-action${ st === 'trash' || st === 'delete' ? ' danger' : '' }" data-cid="${ r.id }" data-cstatus="${ st }">${ label }</button>` ).join( '' ) }
						</div>
						${ state.commentReply === r.id ? `
						<div class="minn-comment-replybox">
							<textarea class="minn-input" id="minn-reply-text" rows="3" placeholder="Reply as ${ esc( B.user.name ) }…"></textarea>
							<div style="display:flex; gap:8px; margin-top:8px;">
								<button class="minn-btn-primary" id="minn-reply-send" data-post="${ r.postId }" data-parent="${ r.id }">${ state.commentTab === 'hold' ? 'Reply & approve' : 'Reply' }</button>
								<button class="minn-btn-soft" id="minn-reply-cancel">Cancel</button>
							</div>
						</div>` : '' }
					</div>
				</div>` ).join( '' ) : `<div class="minn-empty">No ${ ( COMMENT_TABS.find( ( t ) => t[ 0 ] === state.commentTab ) || [ '', '' ] )[ 1 ].toLowerCase() } comments.</div>` }
		</div>
		${ pagerHtml( c.page, c.totalPages, c.total, 'comment' ) }`;

		$$( '[data-ctab]', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', () => {
				state.commentTab = btn.dataset.ctab;
				state.cache.comments = null;
				renderComments();
			} )
		);
		// Right-click a comment: the row's own action buttons as a menu —
		// built FROM the buttons, so it can never drift from the tab's verbs.
		$$( '.minn-comment-row[data-crow]', view ).forEach( ( row ) =>
			row.addEventListener( 'contextmenu', ( e ) => {
				const btns = $$( '.minn-comment-action', row );
				if ( ! btns.length ) return;
				e.preventDefault();
				openMinnMenu( e.clientX, e.clientY, btns.map( ( b ) => ( {
					label: b.textContent.trim(),
					danger: b.classList.contains( 'danger' ),
					run: () => b.click(),
				} ) ) );
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
		$$( '[data-creply]', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', () => {
				const id = parseInt( btn.dataset.creply, 10 );
				state.commentReply = state.commentReply === id ? null : id;
				renderComments();
				const box = $( '#minn-reply-text' );
				if ( box ) box.focus();
			} )
		);

		// Bulk moderation — mirrors the content-list selection (shift-range,
		// select-page, a bar in the slot updated in place). Bar verbs are the
		// tab's own actions, so the same labels/toasts apply.
		const csel = state.commentSel || ( state.commentSel = new Set() );
		const bulkLabels = { approved: 'Approved', hold: state.commentTab === 'hold' ? 'Held' : ( state.commentTab === 'spam' ? 'Marked not spam' : 'Restored' ), spam: 'Marked as spam', trash: 'Trashed', delete: 'Deleted' };
		const syncCommentBulk = () => {
			const slot = $( '#minn-comment-bulk-slot', view );
			if ( ! slot ) return;
			if ( ! csel.size ) { slot.innerHTML = ''; }
			else if ( ! $( '.minn-bulkbar', slot ) ) {
				slot.innerHTML = `
					<div class="minn-bulkbar">
						<span class="minn-bulk-count">${ csel.size } selected</span>
						${ actionsFor().map( ( [ st, label ] ) =>
							`<button class="minn-btn-soft${ st === 'trash' || st === 'delete' ? ' danger' : '' }" data-cbulk="${ st }">${ label }</button>` ).join( '' ) }
						<button class="minn-btn-soft" id="minn-comment-bulk-clear" style="margin-left:auto;">Clear</button>
					</div>`;
				$$( '[data-cbulk]', slot ).forEach( ( b ) =>
					b.addEventListener( 'click', ( e ) => runCommentBulk( e.currentTarget.dataset.cbulk, bulkLabels[ e.currentTarget.dataset.cbulk ] || 'Updated', e.currentTarget ) ) );
				$( '#minn-comment-bulk-clear', slot ).addEventListener( 'click', () => {
					csel.clear();
					$$( '.minn-comment-cb', view ).forEach( ( c ) => { c.checked = false; c.closest( '.minn-comment-row' ).classList.remove( 'sel' ); } );
					const sa = $( '#minn-comment-selall', view );
					if ( sa ) sa.checked = false;
					syncCommentBulk();
				} );
			} else {
				$( '.minn-bulk-count', slot ).textContent = csel.size + ' selected';
			}
			const sa = $( '#minn-comment-selall', view );
			if ( sa ) sa.checked = rows.length > 0 && rows.every( ( r ) => csel.has( r.id ) );
		};
		// Reflect a selection that survived a re-render.
		$$( '.minn-comment-cb', view ).forEach( ( cb ) => {
			if ( csel.has( parseInt( cb.dataset.cbid, 10 ) ) ) { cb.checked = true; cb.closest( '.minn-comment-row' ).classList.add( 'sel' ); }
		} );
		syncCommentBulk();
		const setCommentSel = ( id, on ) => {
			if ( on ) csel.add( id ); else csel.delete( id );
			const cb = view.querySelector( `.minn-comment-cb[data-cbid="${ id }"]` );
			if ( cb ) { cb.checked = on; cb.closest( '.minn-comment-row' ).classList.toggle( 'sel', on ); }
		};
		$$( '.minn-comment-cb', view ).forEach( ( cb ) =>
			cb.addEventListener( 'click', ( e ) => {
				const id = parseInt( cb.dataset.cbid, 10 );
				const idx = rows.findIndex( ( r ) => r.id === id );
				if ( e.shiftKey && state.commentLastIdx != null && state.commentLastIdx !== idx && rows[ state.commentLastIdx ] ) {
					const lo = Math.min( state.commentLastIdx, idx ), hi = Math.max( state.commentLastIdx, idx );
					for ( let i = lo; i <= hi; i++ ) setCommentSel( rows[ i ].id, cb.checked );
				} else {
					setCommentSel( id, cb.checked );
				}
				state.commentLastIdx = idx;
				syncCommentBulk();
			} )
		);
		const cselAll = $( '#minn-comment-selall', view );
		if ( cselAll ) cselAll.addEventListener( 'change', () => {
			rows.forEach( ( r ) => setCommentSel( r.id, cselAll.checked ) );
			syncCommentBulk();
		} );
		const replySend = $( '#minn-reply-send', view );
		if ( replySend ) replySend.addEventListener( 'click', async () => {
			const text = ( $( '#minn-reply-text' ) || {} ).value || '';
			if ( ! text.trim() ) {
				toast( 'Write a reply first', true );
				return;
			}
			replySend.disabled = true;
			const parent = parseInt( replySend.dataset.parent, 10 );
			const wasPending = state.commentTab === 'hold';
			try {
				await api( 'wp/v2/comments', { method: 'POST', body: JSON.stringify( {
					post: parseInt( replySend.dataset.post, 10 ),
					parent,
					content: text.trim(),
				} ) } );
				// Same behavior as wp-admin: replying to a pending comment approves it.
				if ( wasPending ) {
					await api( `wp/v2/comments/${ parent }`, { method: 'POST', body: JSON.stringify( { status: 'approved' } ) } ).catch( () => {} );
				}
				toast( wasPending ? 'Reply posted, comment approved' : 'Reply posted' );
				state.commentReply = null;
				state.cache.comments = null;
				refreshCommentBadge();
				if ( state.route === 'comments' ) renderComments();
			} catch ( e ) {
				toast( e.message, true );
				replySend.disabled = false;
			}
		} );
		const replyCancel = $( '#minn-reply-cancel', view );
		if ( replyCancel ) replyCancel.addEventListener( 'click', () => {
			state.commentReply = null;
			renderComments();
		} );
		bindPager( view, c.page, loadComments, () => { if ( state.route === 'comments' ) renderComments(); } );
	}

	/* ===== Orders (WooCommerce) ===== */

	const ORDER_TABS = [ [ 'any', 'All' ], [ 'processing', 'Processing' ], [ 'completed', 'Completed' ], [ 'on-hold', 'On hold' ], [ 'refunded', 'Refunded' ] ];
	const ORDER_STATUS_STYLE = {
		processing: 'future', completed: 'publish', 'on-hold': 'private', pending: 'private',
		cancelled: 'trash-status', refunded: 'draft', failed: 'trash-status',
	};

	async function loadOrders( page = 1 ) {
		const tab = state.orderTab;
		const r = await apiPaged( `wc/v3/orders?per_page=25&page=${ page }&status=${ tab }&_fields=id,number,status,total,currency_symbol,date_created,billing,line_items` );
		if ( tab !== state.orderTab ) return; // tab changed mid-flight — discard
		state.cache.orders = { items: r.items, page, totalPages: r.totalPages, total: r.total };
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
			<div class="minn-toolbar-meta">${ metaLabel( c.total, 'order' ) }</div>
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
		${ pagerHtml( c.page, c.totalPages, c.total, 'order' ) }`;

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
		bindPager( view, c.page, loadOrders, () => { if ( state.route === 'orders' ) renderOrders(); } );
	}

	/* ===== Terms (categories, tags, custom taxonomies) ===== */

	// The Terms manager: rename, re-slug, re-parent, describe, delete and
	// MERGE terms in any REST-enabled taxonomy. Term CRUD rides core's own
	// wp/v2 routes (core enforces each taxonomy's capabilities); merge is
	// minn-admin/v1/terms/merge. Hierarchical taxonomies render as a full
	// tree (all terms fetched, capped); flat ones stay server-paginated.

	const termsCtx = () => ( state.termsTax || '' ) + '|' + ( state.termSearch || '' );

	const termTaxes = () => state.cache.termTaxes || [];
	const currentTermTax = () => termTaxes().find( ( t ) => t.slug === state.termsTax ) || termTaxes()[ 0 ] || null;

	async function loadTermTaxes() {
		if ( ! state.cache.termTaxes ) {
			state.cache.termTaxes = await api( 'minn-admin/v1/term-taxonomies' );
		}
		return state.cache.termTaxes;
	}

	// Depth-first order with depth annotations — the tree reads as an
	// indented list. Orphans (parent outside the fetched set) surface at
	// the root rather than vanishing.
	function termTreeOrder( items ) {
		const byParent = new Map();
		const ids = new Set( items.map( ( t ) => t.id ) );
		items.forEach( ( t ) => {
			const p = t.parent && ids.has( t.parent ) ? t.parent : 0;
			if ( ! byParent.has( p ) ) byParent.set( p, [] );
			byParent.get( p ).push( t );
		} );
		const out = [];
		const walk = ( parent, depth ) => {
			( byParent.get( parent ) || [] ).forEach( ( t ) => {
				t.depth = depth;
				out.push( t );
				walk( t.id, depth + 1 );
			} );
		};
		walk( 0, 0 );
		return out;
	}

	async function loadTerms( page = 1 ) {
		await loadTermTaxes();
		const tax = currentTermTax();
		if ( ! tax ) {
			state.cache.terms = { items: [], page: 1, totalPages: 0, total: 0, tree: false };
			return;
		}
		const ctx = termsCtx();
		const fields = '_fields=id,name,slug,parent,count,description,link';
		if ( tax.hierarchical && ! state.termSearch && tax.count <= 500 ) {
			let items = [];
			let p = 1;
			let totalPages = 1;
			let total = 0;
			do {
				const r = await apiPaged( `wp/v2/${ tax.rest }?context=edit&per_page=100&orderby=name&page=${ p }&${ fields }` );
				items = items.concat( r.items );
				totalPages = r.totalPages;
				total = r.total;
				p++;
			} while ( p <= totalPages && p <= 5 );
			if ( ctx !== termsCtx() ) return;
			state.cache.terms = { items: termTreeOrder( items ), page: 1, totalPages: 1, total, tree: true };
			return;
		}
		let q = `wp/v2/${ tax.rest }?context=edit&per_page=100&orderby=name&page=${ page }&${ fields }`;
		if ( state.termSearch ) q += '&search=' + encodeURIComponent( state.termSearch );
		const r = await apiPaged( q );
		if ( ctx !== termsCtx() ) return;
		state.cache.terms = { items: r.items.map( ( t ) => Object.assign( t, { depth: 0 } ) ), page, totalPages: r.totalPages, total: r.total, tree: false };
	}

	let termSearchTimer = null;

	async function reloadTerms( page ) {
		state.cache.terms = null;
		state.cache.termTaxes = null; // counts changed
		await loadTerms( page || 1 ).catch( showErr );
		if ( onStructure() ) renderStructure();
	}

	// The Structure page unifies Post Types, Taxonomies and Terms under one
	// nav item. Post Types + Taxonomies need manage_options; Terms needs only
	// manage_categories, so the tabs are gated INDIVIDUALLY — an editor sees
	// just Terms, an admin sees all three. Tab switching stays in-page
	// (state.ptTab); the route is 'posttypes' for admins, 'terms' for
	// editors and deep links.
	const onStructure = () => 'posttypes' === state.route || 'terms' === state.route;

	function structureTabsHtml( active ) {
		const defs = [];
		if ( B.caps.settings ) { defs.push( [ 'types', 'Post Types' ] ); defs.push( [ 'taxonomies', 'Taxonomies' ] ); }
		if ( B.caps.terms ) defs.push( [ 'terms', 'Terms' ] );
		if ( defs.length < 2 ) return ''; // a single available tab needs no bar
		return `<div class="minn-tabs">${ defs.map( ( [ id, label ] ) =>
			`<button class="minn-tab${ active === id ? ' active' : '' }" data-structtab="${ id }">${ label }</button>` ).join( '' ) }</div>`;
	}

	function structureActiveTab() {
		let active = 'terms' === state.route ? 'terms' : ( state.ptTab || 'types' );
		// Clamp to a tab the user can actually see — but only redirect to one
		// that's AVAILABLE. A user with neither cap keeps the requested tab so
		// its own permission message renders (author hitting /terms).
		if ( ( 'types' === active || 'taxonomies' === active ) && ! B.caps.settings && B.caps.terms ) active = 'terms';
		if ( 'terms' === active && ! B.caps.terms && B.caps.settings ) active = 'types';
		return active;
	}

	function renderStructure() {
		const view = $( '#minn-view' );
		const active = structureActiveTab();
		const tabsHtml = structureTabsHtml( active );
		if ( 'terms' === active ) renderStructureTerms( view, tabsHtml );
		else renderStructureTypes( view, tabsHtml, 'taxonomies' === active );
		// Shared tab switch — in-page, no route change (admins stay on
		// 'posttypes'; editors have only the Terms tab).
		$$( '[data-structtab]', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', () => { state.ptTab = btn.dataset.structtab; renderStructure(); } ) );
	}

	// Route to the Terms tab from anywhere: admins land on the Structure
	// page's Terms tab (route stays 'posttypes'), editors on the 'terms' route.
	function goTerms() {
		if ( B.caps.settings ) { state.ptTab = 'terms'; go( 'posttypes' ); }
		else { go( 'terms' ); }
	}

	function renderStructureTerms( view, tabsHtml ) {
		if ( ! B.caps.terms ) {
			view.innerHTML = '<div class="minn-empty">You need permission to manage terms.</div>';
			return;
		}
		const c = state.cache.terms;
		if ( ! c ) {
			view.innerHTML = '<div class="minn-loading">Loading terms…</div>';
			loadTerms().then( () => { if ( onStructure() ) renderStructure(); } ).catch( showErr );
			return;
		}
		const tax = currentTermTax();
		if ( ! tax ) {
			view.innerHTML = `<div class="minn-toolbar">${ tabsHtml }</div><div class="minn-empty">No manageable taxonomies on this site.</div>`;
			return;
		}
		const taxes = termTaxes();
		const linkable = 'category' === tax.slug || 'post_tag' === tax.slug;
		view.innerHTML = `
		<div class="minn-toolbar">
			${ tabsHtml }
			${ taxes.length > 1 ? `<div class="minn-ac minn-tax-select" data-taxcombo>
				<input class="minn-input minn-ac-input" placeholder="${ esc( tax.label ) }" autocomplete="off" spellcheck="false" role="combobox" aria-expanded="false">
				<div class="minn-ac-panel" hidden></div>
			</div>` : '' }
			<input class="minn-input minn-toolbar-search" id="minn-term-search" placeholder="Search ${ esc( tax.label.toLowerCase() ) }…" value="${ esc( state.termSearch || '' ) }">
			<div class="minn-toolbar-meta">${ metaLabel( c.total, tax.item ) }</div>
			${ tax.canEdit ? `<button class="minn-btn-soft" id="minn-add-term" style="margin-left:0;">${ icon( 'plus' ) } Add ${ esc( tax.item ) }</button>` : '' }
		</div>
		<div class="minn-card minn-table" id="minn-terms-table">
			<div class="minn-table-head minn-term-cols">
				<div>Name</div><div>Slug</div><div>Posts</div><div></div>
			</div>
			<div id="minn-term-create-slot"></div>
			${ c.items.length ? c.items.map( ( t ) => `
				<div class="minn-table-row minn-term-cols" data-term="${ t.id }">
					<div class="minn-row-title minn-cell-clip"${ t.depth ? ` style="padding-left:${ t.depth * 22 }px"` : '' }>${ t.depth ? '<span class="minn-term-twig">└</span> ' : '' }${ esc( t.name ) }</div>
					<div class="minn-row-meta minn-cell-clip mono">${ esc( t.slug ) }</div>
					<div class="minn-row-meta">${ linkable && t.count ? `<button type="button" class="minn-term-count" data-count="${ t.id }" title="View these posts">${ t.count }</button>` : ( t.count || '—' ) }</div>
					<div class="minn-row-actions">
						<button type="button" class="minn-row-more" title="Actions" aria-label="Term actions">⋯</button>
					</div>
				</div>` ).join( '' ) : `<div class="minn-empty">${ state.termSearch ? 'No matches.' : 'No ' + esc( tax.label.toLowerCase() ) + ' yet.' }</div>` }
		</div>
		${ c.tree ? '' : pagerHtml( c.page, c.totalPages, c.total, tax.item ) }`;

		const taxWrap = view.querySelector( '[data-taxcombo]' );
		if ( taxWrap ) bindAutocomplete( taxWrap,
			taxes.map( ( t ) => ( { value: t.slug, label: `${ t.label } (${ t.count })` } ) ), {
				strict: true,
				value: tax.slug,
				onPick: async ( v ) => {
					state.termsTax = v || tax.slug;
					state.termSearch = '';
					state.cache.terms = null;
					await loadTerms().catch( showErr );
					if ( onStructure() ) renderStructure();
				},
			} );

		const search = $( '#minn-term-search', view );
		search.addEventListener( 'input', () => {
			clearTimeout( termSearchTimer );
			termSearchTimer = setTimeout( async () => {
				state.termSearch = search.value.trim();
				state.cache.terms = null;
				await loadTerms().catch( showErr );
				if ( onStructure() ) {
					renderStructure();
					const el = $( '#minn-term-search' );
					el.focus();
					el.setSelectionRange( el.value.length, el.value.length );
				}
			}, 350 );
		} );

		const addBtn = $( '#minn-add-term', view );
		if ( addBtn ) addBtn.addEventListener( 'click', () => openTermEditor( null ) );

		const termFromRow = ( row ) => ( c.items || [] ).find( ( x ) => x.id === parseInt( row.dataset.term, 10 ) );
		const viewPosts = ( t ) => {
			if ( 'category' === tax.slug ) state.contentCat = String( t.id );
			else state.contentTag = String( t.id );
			state.cache.content = null;
			go( 'content' );
		};
		const openTermMenu = ( x, y, t ) => {
			openMinnMenu( x, y, [
				...( tax.canEdit ? [ { label: 'Edit ' + tax.item, run: () => openTermEditor( t ) } ] : [] ),
				...( linkable && t.count ? [ { label: 'View posts', run: () => viewPosts( t ) } ] : [] ),
				...( t.link ? [ { label: 'Open archive ↗', href: t.link } ] : [] ),
				...( tax.canDelete && tax.canEdit ? [ { label: 'Merge into…', run: () => openTermMerge( t ) } ] : [] ),
				...( tax.canDelete ? [
					{ heading: 'Danger' },
					{ label: 'Delete ' + tax.item + '…', danger: true, run: () => deleteTerm( t ) },
				] : [] ),
			] );
		};

		$$( '[data-term]', view ).forEach( ( row ) => {
			row.addEventListener( 'click', ( e ) => {
				if ( e.target.closest( '.minn-row-more' ) || e.target.closest( '.minn-term-count' ) || e.target.closest( '.minn-term-edit' ) ) return;
				const t = termFromRow( row );
				if ( t && tax.canEdit ) openTermEditor( t );
			} );
			row.addEventListener( 'contextmenu', ( e ) => {
				const t = termFromRow( row );
				if ( ! t ) return;
				e.preventDefault();
				openTermMenu( e.clientX, e.clientY, t );
			} );
			const more = row.querySelector( '.minn-row-more' );
			if ( more ) more.addEventListener( 'click', ( e ) => {
				e.stopPropagation();
				const t = termFromRow( row );
				if ( ! t ) return;
				const r = more.getBoundingClientRect();
				openTermMenu( r.left - 160, r.bottom + 6, t );
			} );
		} );
		$$( '[data-count]', view ).forEach( ( btn ) => btn.addEventListener( 'click', () => {
			const t = ( c.items || [] ).find( ( x ) => x.id === parseInt( btn.dataset.count, 10 ) );
			if ( t ) viewPosts( t );
		} ) );
		if ( ! c.tree ) bindPager( view, c.page, loadTerms, () => { if ( onStructure() ) renderStructure(); } );
	}

	// Inline editor row: create (null term) mounts under the table head,
	// edit mounts under the term's own row. One editor at a time.
	function openTermEditor( term ) {
		const tax = currentTermTax();
		const c = state.cache.terms;
		if ( ! tax || ! c ) return;
		$$( '.minn-term-edit' ).forEach( ( el ) => el.remove() );
		// Parent choices exclude the term itself and its descendants (a
		// term cannot live under its own subtree).
		const excluded = new Set();
		if ( term && c.tree ) {
			excluded.add( term.id );
			let adding = false;
			let depth = 0;
			c.items.forEach( ( t ) => {
				if ( t.id === term.id ) { adding = true; depth = t.depth; return; }
				if ( adding ) {
					if ( t.depth > depth ) excluded.add( t.id );
					else adding = false;
				}
			} );
		}
		const parentField = tax.hierarchical && c.tree ? `
			<label class="minn-term-field"><span>Parent</span>
				<div class="minn-ac" data-parentcombo>
					<input class="minn-input minn-ac-input" placeholder="None (top level)" autocomplete="off" spellcheck="false" role="combobox" aria-expanded="false">
					<div class="minn-ac-panel" hidden></div>
				</div>
			</label>` : '';
		const editor = document.createElement( 'div' );
		editor.className = 'minn-term-edit';
		editor.innerHTML = `
			<div class="minn-term-edit-grid">
				<label class="minn-term-field"><span>Name</span><input class="minn-input" data-tf="name" value="${ esc( term ? term.name : '' ) }" placeholder="${ esc( tax.item.charAt( 0 ).toUpperCase() + tax.item.slice( 1 ) ) } name"></label>
				<label class="minn-term-field"><span>Slug</span><input class="minn-input mono" data-tf="slug" value="${ esc( term ? term.slug : '' ) }" placeholder="auto from name"></label>
				${ parentField }
			</div>
			<label class="minn-term-field"><span>Description</span><textarea class="minn-input" data-tf="description" rows="2">${ esc( term && term.description ? term.description : '' ) }</textarea></label>
			<div class="minn-term-edit-actions">
				<button type="button" class="minn-btn-primary" data-tsave>${ term ? 'Save' : 'Add ' + esc( tax.item ) }</button>
				<button type="button" class="minn-btn-soft" data-tcancel>Cancel</button>
			</div>`;
		const slot = term ? $( `[data-term="${ term.id }"]` ) : $( '#minn-term-create-slot' );
		if ( ! slot ) return;
		if ( term ) slot.after( editor );
		else slot.appendChild( editor );

		let parentVal = term ? term.parent || 0 : 0;
		const pWrap = editor.querySelector( '[data-parentcombo]' );
		if ( pWrap ) bindAutocomplete( pWrap,
			[ { value: '0', label: 'None (top level)' } ].concat(
				c.items.filter( ( t ) => ! excluded.has( t.id ) )
					.map( ( t ) => ( { value: String( t.id ), label: `${ ' '.repeat( t.depth * 2 ) }${ t.name }` } ) ) ), {
				strict: true,
				value: String( parentVal ),
				onPick: ( v ) => { parentVal = parseInt( v || '0', 10 ); },
			} );

		const nameInput = editor.querySelector( '[data-tf="name"]' );
		nameInput.focus();
		const save = async () => {
			const name = nameInput.value.trim();
			if ( ! name ) { nameInput.focus(); return; }
			const btn = editor.querySelector( '[data-tsave]' );
			btn.disabled = true;
			const body = {
				name,
				description: editor.querySelector( '[data-tf="description"]' ).value,
			};
			const slug = editor.querySelector( '[data-tf="slug"]' ).value.trim();
			if ( slug || term ) body.slug = slug;
			if ( pWrap ) body.parent = parentVal;
			try {
				await api( `wp/v2/${ tax.rest }${ term ? '/' + term.id : '' }`, { method: 'POST', body: JSON.stringify( body ) } );
				toast( term ? 'Saved' : `Added “${ name }”` );
				await reloadTerms( c.page );
			} catch ( e ) {
				toast( e.message, true );
				btn.disabled = false;
			}
		};
		editor.querySelector( '[data-tsave]' ).addEventListener( 'click', save );
		editor.querySelector( '[data-tcancel]' ).addEventListener( 'click', () => editor.remove() );
		editor.addEventListener( 'keydown', ( e ) => {
			if ( 'Enter' === e.key && e.target.tagName !== 'TEXTAREA' ) { e.preventDefault(); save(); }
			if ( 'Escape' === e.key ) editor.remove();
		} );
	}

	async function deleteTerm( t ) {
		const tax = currentTermTax();
		if ( ! tax ) return;
		const msg = tax.hierarchical
			? `Delete “${ t.name }”? Any children move up a level, and posts keep their other ${ tax.label.toLowerCase() }.`
			: `Delete “${ t.name }”? It will be removed from ${ t.count } post${ t.count === 1 ? '' : 's' }.`;
		if ( ! confirm( msg ) ) return;
		try {
			await api( `wp/v2/${ tax.rest }/${ t.id }?force=true`, { method: 'DELETE' } );
			toast( `Deleted “${ t.name }”` );
			await reloadTerms( ( state.cache.terms || {} ).page );
		} catch ( e ) {
			toast( e.message, true );
		}
	}

	// Merge: pick a surviving term (async search — tags run to thousands),
	// then one server request moves every post and deletes the source.
	function openTermMerge( t ) {
		const tax = currentTermTax();
		if ( ! tax ) return;
		$$( '.minn-term-edit' ).forEach( ( el ) => el.remove() );
		const row = $( `[data-term="${ t.id }"]` );
		if ( ! row ) return;
		const editor = document.createElement( 'div' );
		editor.className = 'minn-term-edit';
		editor.innerHTML = `
			<div class="minn-term-merge-copy">Merge <b>${ esc( t.name ) }</b> into another ${ esc( tax.item ) }: its posts move over, then “${ esc( t.name ) }” is deleted.</div>
			<div class="minn-term-edit-grid">
				<label class="minn-term-field"><span>Merge into</span>
					<div class="minn-ac" data-mergecombo>
						<input class="minn-input minn-ac-input" placeholder="Type to search ${ esc( tax.label.toLowerCase() ) }…" autocomplete="off" spellcheck="false">
						<div class="minn-ac-panel" hidden></div>
					</div>
				</label>
			</div>
			<div class="minn-term-edit-actions">
				<button type="button" class="minn-btn-primary" data-tmerge disabled>Merge</button>
				<button type="button" class="minn-btn-soft" data-tcancel>Cancel</button>
			</div>`;
		row.after( editor );
		const input = editor.querySelector( '.minn-ac-input' );
		const panel = editor.querySelector( '.minn-ac-panel' );
		const mergeBtn = editor.querySelector( '[data-tmerge]' );
		let target = null;
		let mergeTimer = null;
		input.focus();
		input.addEventListener( 'input', () => {
			target = null;
			mergeBtn.disabled = true;
			clearTimeout( mergeTimer );
			mergeTimer = setTimeout( async () => {
				const q = input.value.trim();
				if ( ! q ) { panel.hidden = true; return; }
				try {
					const items = await api( `wp/v2/${ tax.rest }?search=${ encodeURIComponent( q ) }&exclude=${ t.id }&per_page=20&_fields=id,name,count` );
					panel.innerHTML = items.length
						? items.map( ( x ) => `<button type="button" class="minn-ac-item" data-mid="${ x.id }">${ esc( x.name ) } <span class="minn-ac-hint">${ x.count } post${ x.count === 1 ? '' : 's' }</span></button>` ).join( '' )
						: '<div class="minn-ac-empty">No matches</div>';
					panel.hidden = false;
					$$( '[data-mid]', panel ).forEach( ( b ) => b.addEventListener( 'mousedown', ( e ) => {
						e.preventDefault();
						target = { id: parseInt( b.dataset.mid, 10 ), name: b.textContent.replace( /\s+\d+ posts?$/, '' ).trim() };
						input.value = target.name;
						panel.hidden = true;
						mergeBtn.disabled = false;
					} ) );
				} catch ( e ) { /* search hiccup — keep typing */ }
			}, 250 );
		} );
		mergeBtn.addEventListener( 'click', async () => {
			if ( ! target ) return;
			if ( ! confirm( `Move everything in “${ t.name }” into “${ target.name }”, then delete “${ t.name }”? This cannot be undone.` ) ) return;
			mergeBtn.disabled = true;
			mergeBtn.textContent = 'Merging…';
			try {
				const res = await api( 'minn-admin/v1/terms/merge', {
					method: 'POST',
					body: JSON.stringify( { taxonomy: tax.slug, from: t.id, into: target.id } ),
				} );
				toast( `Merged into “${ res.into }” — ${ res.moved } post${ res.moved === 1 ? '' : 's' } moved` );
				await reloadTerms( ( state.cache.terms || {} ).page );
			} catch ( e ) {
				toast( e.message, true );
				mergeBtn.disabled = false;
				mergeBtn.textContent = 'Merge';
			}
		} );
		editor.querySelector( '[data-tcancel]' ).addEventListener( 'click', () => editor.remove() );
		editor.addEventListener( 'keydown', ( e ) => {
			if ( 'Escape' === e.key ) editor.remove();
		} );
	}

	/* ===== Users ===== */

	const usersCtx = () => ( state.userSearch || '' ) + '|' + ( state.userRole || '_all' );

	async function loadUsers( page = 1 ) {
		const ctx = usersCtx();
		// minn_switch_url only exists while User Switching is active — an
		// unregistered field in _fields is silently absent (safe).
		let q = `wp/v2/users?context=edit&per_page=50&orderby=registered_date&order=desc&_fields=id,name,email,roles,registered_date,avatar_urls,minn_switch_url&page=${ page }`;
		if ( state.userSearch ) q += '&search=' + encodeURIComponent( state.userSearch );
		if ( state.userRole && state.userRole !== '_all' ) q += '&roles=' + encodeURIComponent( state.userRole );
		const r = await apiPaged( q );
		if ( ctx !== usersCtx() ) return; // filter changed mid-flight — discard
		state.cache.users = { items: r.items, page, totalPages: r.totalPages, total: r.total };
	}

	let userSearchTimer = null;

	// Bulk role change for the users selection. The current user is skipped —
	// demoting yourself mid-batch can lock you out, and WP would reject the
	// last-admin demotion anyway — with a note when that happens.
	async function runUserBulkRole( role, roleLabel, btn ) {
		const ids = Array.from( state.userSel || [] );
		if ( ! ids.length || ! role ) return;
		const targets = ids.filter( ( id ) => id !== B.user.id );
		const skippedSelf = targets.length !== ids.length;
		if ( ! targets.length ) {
			toast( 'Pick users other than yourself to change roles in bulk.', true );
			return;
		}
		btn.disabled = true;
		btn.textContent = 'Working…';
		let ok = 0, fail = 0;
		for ( const id of targets ) {
			try { await api( `wp/v2/users/${ id }`, { method: 'POST', body: JSON.stringify( { roles: [ role ] } ) } ); ok++; }
			catch ( e ) { fail++; }
		}
		state.userSel.clear();
		state.userLastIdx = null;
		state.cache.users = null;
		const tail = skippedSelf ? ' (your own account was skipped)' : '';
		toast( fail ? `Set ${ roleLabel }: ${ ok } done, ${ fail } failed${ tail }` : `Set ${ ok } user${ ok === 1 ? '' : 's' } to ${ roleLabel }${ tail }`, fail > 0 && ok === 0 );
		if ( state.route === 'users' ) renderUsers();
	}

	function renderUsers() {
		const view = $( '#minn-view' );
		const c = state.cache.users;
		if ( ! c ) {
			view.innerHTML = '<div class="minn-loading">Loading users…</div>';
			loadUsers().then( renderIfCurrent( 'users' ) ).catch( showErr );
			return;
		}
		// One searchable combobox, not a tab per role — real sites (Woo,
		// memberships, LMS) carry 10+ roles and the tab row overflowed.
		const roles = Object.entries( B.roles || {} );
		// Bulk role change is gated on edit-users; lower roles get no checkboxes.
		const bulkUsers = !! B.caps.editUsers && roles.length > 0;
		const userSel = state.userSel || ( state.userSel = new Set() );
		view.innerHTML = `
		<div class="minn-toolbar">
			${ roles.length > 1 ? `<div class="minn-ac minn-tax-select" data-rolecombo>
				<input class="minn-input minn-ac-input" placeholder="All roles" autocomplete="off" spellcheck="false" role="combobox" aria-expanded="false">
				<div class="minn-ac-panel" hidden></div>
			</div>` : '' }
			<input class="minn-input minn-toolbar-search" id="minn-user-search" placeholder="Search users…" value="${ esc( state.userSearch || '' ) }">
			<div class="minn-toolbar-meta">${ metaLabel( c.total, 'user' ) }</div>
			${ B.caps.createUsers ? `<button class="minn-btn-soft" id="minn-add-user" style="margin-left:0;">${ icon( 'plus' ) } Add user</button>` : '' }
		</div>
		${ bulkUsers ? '<div id="minn-user-bulk-slot"></div>' : '' }
		<div class="minn-card minn-table">
			<div class="minn-table-head minn-user-cols${ bulkUsers ? ' has-cb' : '' }">
				${ bulkUsers ? `<div><input type="checkbox" class="minn-cb" id="minn-user-selall"></div>` : '' }
				<div></div><div>Name</div><div>Email</div><div>Role</div><div>Registered</div><div></div>
			</div>
			${ c.items.length ? c.items.map( ( u ) => `
				<div class="minn-table-row minn-user-cols${ bulkUsers ? ' has-cb' : '' }${ userSel.has( u.id ) ? ' sel' : '' }" data-user="${ u.id }" data-uname="${ esc( u.name || '' ) }" data-uemail="${ esc( u.email || '' ) }" data-uroles="${ esc( ( u.roles || [] ).join( ',' ) ) }">
					${ bulkUsers ? `<div class="minn-cbcell"><input type="checkbox" class="minn-cb minn-user-cb" data-cbid="${ u.id }"${ userSel.has( u.id ) ? ' checked' : '' }></div>` : '' }
					<img class="minn-user-row-avatar" src="${ esc( ( u.avatar_urls && ( u.avatar_urls[ '48' ] || Object.values( u.avatar_urls )[ 0 ] ) ) || '' ) }" alt="">
					<div class="minn-row-title minn-cell-clip">${ esc( u.name ) }</div>
					<div class="minn-row-meta minn-cell-clip">${ esc( u.email || '—' ) }</div>
					<div class="minn-row-meta">${ esc( ( u.roles || [] ).map( ( r ) => r.charAt( 0 ).toUpperCase() + r.slice( 1 ) ).join( ', ' ) || '—' ) }</div>
					<div class="minn-row-meta">${ u.registered_date ? timeAgo( u.registered_date ) : '—' }</div>
					<div class="minn-row-actions">
						<button type="button" class="minn-row-more" title="Actions" aria-label="User actions">⋯</button>
					</div>
				</div>` ).join( '' ) : '<div class="minn-empty">No users found.</div>' }
		</div>
		${ pagerHtml( c.page, c.totalPages, c.total, 'user' ) }`;

		const roleWrap = view.querySelector( '[data-rolecombo]' );
		if ( roleWrap ) bindAutocomplete( roleWrap,
			[ { value: '', label: 'All roles' } ].concat( roles.map( ( [ slug, label ] ) => ( { value: slug, label } ) ) ), {
				strict: true,
				value: state.userRole && state.userRole !== '_all' ? state.userRole : '',
				onPick: async ( v ) => {
					state.userRole = v || null;
					state.cache.users = null;
					// Keep the toolbar in place — dim only the table while loading.
					const tbl = $( '.minn-table', view );
					if ( tbl ) tbl.classList.add( 'minn-busy' );
					await loadUsers().catch( showErr );
					if ( state.route === 'users' ) renderUsers();
				},
			} );
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

		const userFromRow = ( row ) => {
			const id = parseInt( row.dataset.user, 10 );
			const cached = ( c.items || [] ).find( ( x ) => x.id === id );
			return cached || {
				id,
				name: row.dataset.uname || '',
				email: row.dataset.uemail || '',
				roles: ( row.dataset.uroles || '' ).split( ',' ).filter( Boolean ),
			};
		};
		const openUserMenu = ( x, y, u ) => {
			const isSelf = u.id === B.user.id;
			const entries = [
				{ label: 'View user', run: () => openUserModal( u.id ) },
				...( u.email ? [ {
					label: 'Copy email',
					run: async () => {
						try { await navigator.clipboard.writeText( u.email ); toast( 'Email copied' ); }
						catch ( err ) { toast( 'Could not copy', true ); }
					},
				} ] : [] ),
				{ heading: 'Email' },
				...( B.caps.editUsers ? [
					{
						label: 'Send password reset',
						run: () => sendUserPasswordReset( u ),
					},
					{
						label: 'Send email…',
						run: () => openUserEmailModal( u ),
					},
				] : [] ),
				{ heading: 'Access' },
				// User Switching's own nonce URL (adapters/user-switching.php) —
				// same-tab navigation is the point: you become that user.
				...( u.minn_switch_url ? [ {
					label: 'Switch to this user',
					run: () => { window.location.href = u.minn_switch_url; },
				} ] : [] ),
				...( B.caps.editUsers || isSelf ? [ {
					label: isSelf ? 'Sign out other sessions' : 'Sign out all sessions',
					run: () => killUserSessions( u ),
				} ] : [] ),
				{
					label: 'Edit in wp-admin ↗',
					href: B.site.adminUrl + 'user-edit.php?user_id=' + u.id,
				},
				...( B.caps.deleteUsers && ! isSelf ? [
					{ heading: 'Danger' },
					{
						label: 'Delete user…',
						danger: true,
						run: () => openUserDeleteModal( u ),
					},
				] : [] ),
			];
			openMinnMenu( x, y, entries );
		};

		$$( '[data-user]', view ).forEach( ( row ) => {
			row.addEventListener( 'click', ( e ) => {
				if ( e.target.closest( '.minn-row-more' ) ) return;
				if ( B.caps.editUsers ) openUserModal( parseInt( row.dataset.user, 10 ) );
				else window.open( B.site.adminUrl + 'user-edit.php?user_id=' + row.dataset.user, '_blank' );
			} );
			row.addEventListener( 'contextmenu', ( e ) => {
				e.preventDefault();
				openUserMenu( e.clientX, e.clientY, userFromRow( row ) );
			} );
			const more = row.querySelector( '.minn-row-more' );
			if ( more ) more.addEventListener( 'click', ( e ) => {
				e.stopPropagation();
				const r = more.getBoundingClientRect();
				openUserMenu( r.left - 160, r.bottom + 6, userFromRow( row ) );
			} );
		} );

		// Bulk role change — same selection shape as the content list.
		if ( bulkUsers ) {
			const rows = c.items;
			const syncUserBulk = () => {
				const slot = $( '#minn-user-bulk-slot', view );
				if ( ! slot ) return;
				if ( ! userSel.size ) { slot.innerHTML = ''; }
				else if ( ! $( '.minn-bulkbar', slot ) ) {
					slot.innerHTML = `
						<div class="minn-bulkbar">
							<span class="minn-bulk-count">${ userSel.size } selected</span>
							<span class="minn-bulk-label">Change role to</span>
							<div class="minn-ac minn-tax-select" data-userbulkrole>
								<input class="minn-input minn-ac-input" placeholder="Choose a role…" autocomplete="off" spellcheck="false" role="combobox" aria-expanded="false">
								<div class="minn-ac-panel" hidden></div>
							</div>
							<button class="minn-btn-soft" id="minn-user-bulk-apply">Apply</button>
							<button class="minn-btn-soft" id="minn-user-bulk-clear" style="margin-left:auto;">Clear</button>
						</div>`;
					// Strict combobox (matches the role filter + profile picker):
					// the picked slug rides input.dataset.acValue, blank until chosen.
					const roleWrap = $( '[data-userbulkrole]', slot );
					bindAutocomplete( roleWrap,
						[ { value: '', label: 'Choose a role…' } ].concat( roles.map( ( [ slug, label ] ) => ( { value: slug, label } ) ) ),
						{ strict: true, value: '' } );
					$( '#minn-user-bulk-apply', slot ).addEventListener( 'click', ( e ) => {
						const acInput = $( '.minn-ac-input', roleWrap );
						const role = acInput ? acInput.dataset.acValue : '';
						if ( ! role ) { toast( 'Choose a role first', true ); return; }
						const entry = roles.find( ( r ) => r[ 0 ] === role );
						runUserBulkRole( role, entry ? entry[ 1 ] : role, e.currentTarget );
					} );
					$( '#minn-user-bulk-clear', slot ).addEventListener( 'click', () => {
						userSel.clear();
						$$( '.minn-user-cb', view ).forEach( ( c2 ) => { c2.checked = false; c2.closest( '.minn-table-row' ).classList.remove( 'sel' ); } );
						const sa = $( '#minn-user-selall', view );
						if ( sa ) sa.checked = false;
						syncUserBulk();
					} );
				} else {
					$( '.minn-bulk-count', slot ).textContent = userSel.size + ' selected';
				}
				const sa = $( '#minn-user-selall', view );
				if ( sa ) sa.checked = rows.length > 0 && rows.every( ( u ) => userSel.has( u.id ) );
			};
			syncUserBulk();
			const setUserSel = ( id, on ) => {
				if ( on ) userSel.add( id ); else userSel.delete( id );
				const cb = view.querySelector( `.minn-user-cb[data-cbid="${ id }"]` );
				if ( cb ) { cb.checked = on; cb.closest( '.minn-table-row' ).classList.toggle( 'sel', on ); }
			};
			$$( '.minn-user-cb', view ).forEach( ( cb ) =>
				cb.addEventListener( 'click', ( e ) => {
					e.stopPropagation(); // don't open the user modal
					const id = parseInt( cb.dataset.cbid, 10 );
					const idx = rows.findIndex( ( u ) => u.id === id );
					if ( e.shiftKey && state.userLastIdx != null && state.userLastIdx !== idx && rows[ state.userLastIdx ] ) {
						const lo = Math.min( state.userLastIdx, idx ), hi = Math.max( state.userLastIdx, idx );
						for ( let i = lo; i <= hi; i++ ) setUserSel( rows[ i ].id, cb.checked );
					} else {
						setUserSel( id, cb.checked );
					}
					state.userLastIdx = idx;
					syncUserBulk();
				} )
			);
			const uSelAll = $( '#minn-user-selall', view );
			if ( uSelAll ) uSelAll.addEventListener( 'change', () => {
				rows.forEach( ( u ) => setUserSel( u.id, uSelAll.checked ) );
				syncUserBulk();
			} );
		}
		bindPager( view, c.page, loadUsers, () => { if ( state.route === 'users' ) renderUsers(); } );
	}

	async function sendUserPasswordReset( u ) {
		if ( ! u || ! u.id ) return;
		if ( ! confirm( `Send a password-reset email to ${ u.name || 'this user' }${ u.email ? ' (' + u.email + ')' : '' }?` ) ) return;
		try {
			const r = await api( `minn-admin/v1/users/${ u.id }/reset-password`, { method: 'POST', body: '{}' } );
			toast( 'Reset email sent' + ( r && r.email ? ' to ' + r.email : '' ) );
		} catch ( e ) {
			toast( e.message, true );
		}
	}

	async function killUserSessions( u ) {
		if ( ! u || ! u.id ) return;
		const isSelf = u.id === B.user.id;
		const msg = isSelf
			? 'Sign out of every other browser and device? This session stays signed in.'
			: `Sign ${ u.name || 'this user' } out of every active session?`;
		if ( ! confirm( msg ) ) return;
		try {
			await api( `minn-admin/v1/users/${ u.id }/sessions`, { method: 'DELETE' } );
			toast( isSelf ? 'Other sessions signed out' : 'All sessions signed out' );
		} catch ( e ) {
			toast( e.message, true );
		}
	}

	function openUserEmailModal( u ) {
		state.modal = {
			type: 'user-email',
			user: u,
			subject: '',
			message: '',
		};
		renderOverlays();
	}

	// "Name · email", but collapse to a single value when a user has no
	// distinct display name (WordPress defaults display_name to the login,
	// which for imported accounts is often the email — hence "email · email").
	function fmtUserLabel( name, email ) {
		name = ( name || '' ).trim();
		email = ( email || '' ).trim();
		if ( ! name || name.toLowerCase() === email.toLowerCase() ) return email || name;
		if ( ! email ) return name;
		return name + ' · ' + email;
	}

	function openUserDeleteModal( u ) {
		state.modal = {
			type: 'user-delete',
			user: u,
			reassign: String( B.user.id ),
			candidates: null,
		};
		renderOverlays();
		// Load a pick list for reassignment (exclude the user being deleted).
		api( 'wp/v2/users?context=edit&per_page=100&orderby=name&order=asc&_fields=id,name,email' )
			.then( ( list ) => {
				if ( state.modal && state.modal.type === 'user-delete' ) {
					state.modal.candidates = ( Array.isArray( list ) ? list : [] )
						.filter( ( x ) => x.id !== u.id );
					renderOverlays();
				}
			} )
			.catch( () => {
				if ( state.modal && state.modal.type === 'user-delete' ) {
					state.modal.candidates = [ { id: B.user.id, name: B.user.name || 'You', email: '' } ];
					renderOverlays();
				}
			} );
	}

	/* ===== Surfaces (declarative third-party plugin views) ===== */

	function surfaceState( id ) {
		if ( ! state.surface[ id ] ) {
			state.surface[ id ] = { tab: '_all', cache: null, tabs: null, labels: {}, q: '', view: 'main', status: null };
		}
		return state.surface[ id ];
	}

	// Optional status card above a surface's list (descriptor key `status`):
	// the route returns a SERVER-BUILT display model — stat rows, an optional
	// copyable command, and action buttons — so adapters format values
	// server-side and the client stays generic. Disembark is the reference.
	async function loadSurfaceStatus( s ) {
		const ss = surfaceState( s.id );
		if ( ! s.status || ! s.status.route ) return;
		try {
			ss.status = await api( s.status.route );
		} catch ( e ) {
			ss.status = { rows: [] };
		}
	}

	function surfaceStatusHtml( st ) {
		if ( ! st ) return '';
		const rows = ( st.rows || [] ).map( ( r ) => `
			<div class="minn-sstat">
				<div class="minn-sstat-label">${ esc( r.label ) }</div>
				<div class="minn-sstat-value">${ esc( r.value ) }</div>
				${ r.hint ? `<div class="minn-sstat-hint">${ esc( r.hint ) }</div>` : '' }
			</div>` ).join( '' );
		const cmd = st.command ? `
			<div class="minn-sstat-cmd">
				${ st.command.label ? `<div class="minn-sstat-label">${ esc( st.command.label ) }</div>` : '' }
				<button type="button" class="minn-sstat-cmd-box" id="minn-sstat-copy" title="Copy command">
					<code>${ esc( st.command.text ) }</code>${ icon( 'clipboard' ) }
				</button>
				${ st.command.hint ? `<div class="minn-sstat-hint">${ esc( st.command.hint ) }</div>` : '' }
			</div>` : '';
		const actions = ( st.actions || [] ).length ? `
			<div class="minn-sstat-actions">
				${ st.actions.map( ( a, i ) => a.href
					? `<a class="minn-btn-soft" href="${ esc( a.href ) }" target="_blank" rel="noopener">${ esc( a.label ) }</a>`
					: `<button type="button" class="minn-btn-soft${ a.danger ? ' danger' : '' }" data-sstatact="${ i }">${ esc( a.label ) }</button>` ).join( '' ) }
			</div>` : '';
		if ( ! rows && ! cmd && ! actions ) return '';
		return `<div class="minn-card minn-surface-status">
			<div class="minn-sstat-rows">${ rows }</div>
			${ cmd }
			${ actions }
		</div>`;
	}

	function bindSurfaceStatus( s, view ) {
		const ss = surfaceState( s.id );
		const st = ss.status;
		if ( ! st ) return;
		const copy = $( '#minn-sstat-copy', view );
		if ( copy && st.command ) copy.addEventListener( 'click', async () => {
			try {
				await navigator.clipboard.writeText( st.command.text );
				toast( 'Command copied' );
			} catch ( e ) {
				toast( 'Copy failed — select the text instead', true );
			}
		} );
		$$( '[data-sstatact]', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', async () => {
				const a = ( st.actions || [] )[ parseInt( btn.dataset.sstatact, 10 ) ];
				if ( ! a || ( a.confirm && ! confirm( a.confirm ) ) ) return;
				btn.disabled = true;
				try {
					await api( a.route, { method: a.method || 'POST' } );
					toast( a.label + ' — done' );
					ss.status = null;
					ss.cache = null;
					if ( state.route === s.id ) renderSurface( s );
				} catch ( e ) {
					toast( e.message, true );
					btn.disabled = false;
				}
			} )
		);
	}

	// A surface may declare a second collection under `manage` (e.g. Gravity
	// Forms: entries by default, the forms themselves in the Manage view).
	// Everything below renders whichever collection the current view resolves.
	function surfaceColl( s, ss ) {
		return ss.view === 'manage' && s.manage ? s.manage : s.collection;
	}

	function surfaceRoute( s, ss, page ) {
		const col = surfaceColl( s, ss );
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
		const tabs = surfaceColl( s, ss ).tabs;
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

	async function loadSurfaceItems( s, page = 1 ) {
		const ss = surfaceState( s.id );
		const col = surfaceColl( s, ss );
		const ctx = ss.tab + '|' + ( ss.q || '' );
		const res = await apiRes( surfaceRoute( s, ss, page ) );
		const body = await res.json();
		if ( ctx !== ss.tab + '|' + ( ss.q || '' ) ) return; // filter changed mid-flight
		const items = col.itemsKey
			? ( body[ col.itemsKey ] || [] )
			: ( Array.isArray( body ) ? body : Object.values( body ) );
		const total = col.totalKey
			? parseInt( body[ col.totalKey ] || 0, 10 )
			: parseInt( res.headers.get( 'X-WP-Total' ) || String( items.length ), 10 );
		// Adapters declare page size inside their own pageQuery template, so
		// derive it from the first full page instead of parsing the template.
		const perPage = page === 1 ? items.length : ( ss.cache && ss.cache.perPage ) || items.length;
		ss.cache = {
			items,
			page,
			total,
			perPage,
			totalPages: perPage > 0 ? Math.max( page, Math.ceil( total / perPage ) ) : 1,
		};
	}

	const PILL_STYLES = {
		green: [ 'sent', 'active', 'completed', 'publish', 'approved', 'success', 'read', 'received' ],
		red: [ 'failed', 'spam', 'error', 'cancelled' ],
		// inactive (Code Snippets / GF forms) is a quiet draft-like state.
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
	// field values as { "1": "...", "2.3": "..." }). Prefer a short contact
	// line (name · email · first short field) so the list doesn't look like a
	// dump of every answer.
	function entrySummary( item ) {
		// Adapter-provided summary (Fluent / Elementor shims).
		if ( item.summary ) return String( item.summary ).slice( 0, 90 );
		const vals = Object.keys( item )
			.filter( ( k ) => /^\d+(\.\d+)?$/.test( k ) )
			.sort( ( a, b ) => parseFloat( a ) - parseFloat( b ) )
			.map( ( k ) => String( item[ k ] || '' ).trim() )
			.filter( Boolean );
		// Drop multi-line / long answers from the list line — they belong in the detail body.
		const short = vals.filter( ( v ) => ! v.includes( '\n' ) && v.length <= 60 );
		const pick = ( short.length ? short : vals ).slice( 0, 3 );
		return pick.join( ' · ' ).slice( 0, 90 ) || '(empty entry)';
	}

	// Contact-form entry layout: identity (name/email) → message body → other
	// answers → quiet meta. Used for the forms family instead of the generic
	// right-aligned key/value dump that reads like raw data.
	function renderEntryDetail( sec ) {
		const groups = sec.sections || [];
		const answers = ( groups.find( ( g ) => /response/i.test( g.title || '' ) ) || groups[ 0 ] || {} ).rows || [];
		const meta = ( groups.find( ( g ) => /submission|meta|detail/i.test( g.title || '' ) ) || groups[ 1 ] || {} ).rows || [];

		const isEmail = ( r ) => r.type === 'email' || /e-?mail/i.test( r.label || '' ) || isEmailish( r.value );
		const isName = ( r ) => r.type === 'name' || /^(full\s*)?name$|your name|first name|last name/i.test( r.label || '' );
		const isBody = ( r ) => r.type === 'textarea' || r.type === 'post_content'
			|| /message|comment|how can|tell us|description|details|note/i.test( r.label || '' )
			|| ( String( r.value || '' ).includes( '\n' ) )
			|| ( String( r.value || '' ).length > 120 );

		function isEmailish( v ) {
			return typeof v === 'string' && /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test( v.trim() );
		}

		let nameRow = answers.find( isName );
		let emailRow = answers.find( isEmail );
		// Heuristic fallback: first short non-email string as name if no labeled name.
		if ( ! nameRow ) {
			nameRow = answers.find( ( r ) => ! isEmail( r ) && ! isBody( r )
				&& String( r.value || '' ).trim().split( /\s+/ ).length <= 5
				&& String( r.value || '' ).length <= 60 );
		}
		const bodyRows = answers.filter( ( r ) => r !== nameRow && r !== emailRow && isBody( r ) );
		const fieldRows = answers.filter( ( r ) => r !== nameRow && r !== emailRow && ! bodyRows.includes( r ) );

		const hero = ( nameRow || emailRow ) ? `
			<div class="minn-entry-hero">
				${ nameRow ? `<div class="minn-entry-name">${ esc( String( nameRow.value ) ) }</div>` : '' }
				${ emailRow ? `<a class="minn-entry-email" href="mailto:${ esc( String( emailRow.value ).trim() ) }">${ esc( String( emailRow.value ) ) }</a>` : '' }
			</div>` : '';

		const bodies = bodyRows.map( ( r ) => `
			<div class="minn-entry-body">
				${ r.label ? `<div class="minn-entry-field-label">${ esc( r.label ) }</div>` : '' }
				<div class="minn-entry-message">${ esc( String( r.value == null ? '' : r.value ) ) }</div>
			</div>` ).join( '' );

		const fields = fieldRows.length ? `
			<div class="minn-entry-fields">
				${ fieldRows.map( ( r ) => {
					const raw = String( r.value == null ? '' : r.value );
					let val;
					if ( r.type === 'url' && /^https?:\/\//.test( raw ) ) {
						val = `<a href="${ esc( raw ) }" target="_blank" rel="noopener">${ esc( raw ) }</a>`;
					} else if ( isEmailish( raw ) ) {
						val = `<a href="mailto:${ esc( raw.trim() ) }">${ esc( raw ) }</a>`;
					} else {
						val = esc( raw );
					}
					return `<div class="minn-entry-field">
						<div class="minn-entry-field-label">${ esc( r.label || '' ) }</div>
						<div class="minn-entry-field-value">${ val }</div>
					</div>`;
				} ).join( '' ) }
			</div>` : '';

		// Meta as a single quiet line of chips (date · source · IP), not a second form.
		const metaBits = meta.map( ( r ) => {
			const raw = String( r.value == null ? '' : r.value );
			if ( ! raw ) return '';
			if ( r.type === 'url' && /^https?:\/\//.test( raw ) ) {
				let host = raw;
				try { host = new URL( raw ).pathname.replace( /\/$/, '' ) || '/'; } catch ( e ) { /* keep */ }
				return `<a class="minn-entry-meta-chip" href="${ esc( raw ) }" target="_blank" rel="noopener" title="${ esc( raw ) }">${ esc( host ) }</a>`;
			}
			const label = /submitted|date|when/i.test( r.label || '' ) ? '' : ( r.label ? r.label + ' ' : '' );
			return `<span class="minn-entry-meta-chip">${ esc( label + raw ) }</span>`;
		} ).filter( Boolean ).join( '<span class="minn-entry-meta-dot">·</span>' );

		const metaHtml = metaBits ? `<div class="minn-entry-meta">${ metaBits }</div>` : '';

		if ( ! hero && ! bodies && ! fields ) {
			// Empty entry — fall back to a simple note.
			return `<div class="minn-entry"><div class="minn-entry-empty">No answers on this entry.</div>${ metaHtml }</div>`;
		}

		return `<div class="minn-entry">${ hero }${ bodies }${ fields }${ metaHtml }</div>`;
	}

	// Activity-log event layout (Simple History / WSAL / Aryo / Stream): who →
	// event message → short context fields → quiet meta chips. Same visual
	// language as form entries so audit events don't read as a raw key dump.
	function renderActivityDetail( item, sec ) {
		const it = item || {};
		const groups = ( sec && sec.sections ) || [];

		// --- Message (the event) ---
		let message = it.message || it.summary || ( sec && sec.title ) || '';
		if ( ! message && groups.length ) {
			// WSAL-style sections: first non-empty "Event" row.
			const eventGroup = groups.find( ( g ) => /event/i.test( g.title || '' ) ) || groups[ 0 ];
			const first = ( eventGroup && eventGroup.rows || [] ).find( ( r ) => r.value );
			if ( first ) message = first.value;
		}
		message = stripTags( String( message || '' ) ).trim();

		// --- Who ---
		let who = surfaceValue( it, 'initiator_data.user_display_name' )
			|| surfaceValue( it, 'initiator_data.user_login' )
			|| it.username || it.who || '';
		if ( ! who && groups.length ) {
			const userRow = groups.flatMap( ( g ) => g.rows || [] )
				.find( ( r ) => /^(user|who|actor)$/i.test( r.label || '' ) );
			if ( userRow ) who = userRow.value;
		}
		if ( ! who || who === 'wp_user' ) {
			// Fall back to initiator slug only when nothing human is available.
			who = who || it.initiator || 'System';
		}
		if ( who === 'wp_user' ) who = 'User';

		// --- Level / severity / action pill text ---
		let level = it.loglevel || it.severity || it.action || '';
		if ( ! level && groups.length ) {
			const lvl = groups.flatMap( ( g ) => g.rows || [] )
				.find( ( r ) => /severity|level|action/i.test( r.label || '' ) );
			if ( lvl ) level = lvl.value;
		}

		// --- When ---
		// Prefer site-local when both exist (SH); only fall back to *_gmt with
		// an explicit UTC key so parseWpDate does not shift by gmt_offset.
		let when = it.date_local || it.date || it.date_gmt || '';
		let whenKey = it.date_local || it.date ? 'date' : ( it.date_gmt ? 'date_gmt' : '' );
		if ( ! when && groups.length ) {
			const wr = groups.flatMap( ( g ) => g.rows || [] )
				.find( ( r ) => /when|date|time/i.test( r.label || '' ) );
			if ( wr ) { when = wr.value; whenKey = /gmt/i.test( wr.label || '' ) ? 'date_gmt' : 'date'; }
		}
		const whenLabel = when ? timeAgoForKey( when, whenKey ) : '';

		// --- Short context fields (not the message / who / when) ---
		const fieldRows = [];
		const pushField = ( label, value ) => {
			const v = value == null ? '' : String( value ).trim();
			if ( ! v || ! label ) return;
			if ( fieldRows.some( ( f ) => f.label === label ) ) return;
			// Skip multi-kB blobs (content diffs, full post bodies).
			if ( v.length > 280 || ( v.match( /\n/g ) || [] ).length > 4 ) {
				fieldRows.push( { label, value: 'Changed' } );
				return;
			}
			fieldRows.push( { label, value: v } );
		};

		if ( it.logger ) pushField( 'Logger', humanizeLogger( it.logger ) );
		if ( it.connector ) pushField( 'Source', it.connector );
		if ( it.object && it.object !== message ) pushField( 'Object', it.object );
		if ( it.type && it.type !== level ) pushField( 'Type', it.type );
		if ( it.context && typeof it.context === 'string' ) pushField( 'Context', it.context );
		if ( it.message_key ) pushField( 'Key', it.message_key );

		// Simple History details_data: compact name / prev → new pairs.
		( Array.isArray( it.details_data ) ? it.details_data : [] ).forEach( ( group ) => {
			( group.items || [] ).forEach( ( d ) => {
				const name = d.name || group.title || 'Detail';
				const nv = d.new_value != null ? String( d.new_value ) : '';
				const pv = d.prev_value != null ? String( d.prev_value ) : '';
				if ( ! nv && ! pv ) return;
				if ( nv.length > 200 || pv.length > 200 ) {
					pushField( name, 'Changed' );
				} else if ( pv && nv && pv !== nv ) {
					pushField( name, pv + ' → ' + nv );
				} else {
					pushField( name, nv || pv );
				}
			} );
		} );

		// Useful short keys from SH context object (skip content blobs).
		const ctx = it.context && typeof it.context === 'object' && ! Array.isArray( it.context ) ? it.context : null;
		if ( ctx ) {
			const keep = {
				post_title: 'Post',
				post_type: 'Post type',
				post_id: 'Post ID',
				plugin_slug: 'Plugin',
				plugin_name: 'Plugin',
				theme_name: 'Theme',
				user_login: 'User',
				option: 'Option',
			};
			Object.keys( keep ).forEach( ( k ) => {
				if ( ctx[ k ] != null && String( ctx[ k ] ).trim() ) {
					pushField( keep[ k ], ctx[ k ] );
				}
			} );
		}

		// WSAL / shim sections: Context group → fields; Event rows already used for message.
		if ( groups.length ) {
			groups.forEach( ( g ) => {
				const isMeta = /event|meta|when|user/i.test( g.title || '' ) && ! /context/i.test( g.title || '' );
				( g.rows || [] ).forEach( ( r ) => {
					if ( ! r || r.value == null || r.value === '' ) return;
					const lab = r.label || '';
					if ( /^(event|when|date|user|who|severity|level)$/i.test( lab ) ) return;
					if ( String( r.value ) === message || String( r.value ) === who ) return;
					// Event group with only the headline was already used.
					if ( isMeta && /event/i.test( g.title || '' ) && String( r.value ) === message ) return;
					pushField( lab || g.title || 'Detail', r.value );
				} );
			} );
		}

		if ( level ) pushField( 'Level', level );

		// --- IP ---
		let ip = it.ip || it.client_ip || '';
		if ( ! ip && it.ip_addresses && typeof it.ip_addresses === 'object' ) {
			const ips = Object.keys( it.ip_addresses );
			if ( ips.length ) ip = ips[ 0 ];
		}
		if ( ! ip && groups.length ) {
			const ipRow = groups.flatMap( ( g ) => g.rows || [] ).find( ( r ) => /^ip/i.test( r.label || '' ) );
			if ( ipRow ) ip = ipRow.value;
		}

		const hero = who ? `
			<div class="minn-entry-hero">
				<div class="minn-entry-name">${ esc( String( who ) ) }</div>
				${ level ? `<div class="minn-entry-email" style="color:var(--text3); pointer-events:none;">${ esc( String( level ) ) }</div>` : '' }
			</div>` : '';

		const body = message ? `
			<div class="minn-entry-body">
				<div class="minn-entry-field-label">Event</div>
				<div class="minn-entry-message">${ esc( message ) }</div>
			</div>` : '';

		const fields = fieldRows.length ? `
			<div class="minn-entry-fields">
				${ fieldRows.map( ( r ) => `
					<div class="minn-entry-field">
						<div class="minn-entry-field-label">${ esc( r.label ) }</div>
						<div class="minn-entry-field-value">${ esc( r.value ) }</div>
					</div>` ).join( '' ) }
			</div>` : '';

		// Footer already has "Open in Simple History" — keep meta chips quiet
		// (when / IP only; no redundant open link).
		const metaBits = [];
		if ( whenLabel ) metaBits.push( `<span class="minn-entry-meta-chip" title="${ esc( String( when ) ) }">${ esc( whenLabel ) }</span>` );
		if ( ip ) metaBits.push( `<span class="minn-entry-meta-chip">${ esc( 'IP ' + ip ) }</span>` );
		const metaHtml = metaBits.length
			? `<div class="minn-entry-meta">${ metaBits.join( '<span class="minn-entry-meta-dot">·</span>' ) }</div>`
			: '';

		if ( ! hero && ! body && ! fields ) {
			return `<div class="minn-entry"><div class="minn-entry-empty">No details for this event.</div>${ metaHtml }</div>`;
		}
		return `<div class="minn-entry minn-activity-entry">${ hero }${ body }${ fields }${ metaHtml }</div>`;
	}

	// SimplePostLogger → "Post", PluginLogger → "Plugin", etc.
	function humanizeLogger( logger ) {
		const s = String( logger || '' )
			.replace( /^Simple/, '' )
			.replace( /Logger$/i, '' )
			.replace( /([a-z])([A-Z])/g, '$1 $2' )
			.trim();
		return s || String( logger || '' );
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

	// Shared field markup for surface create + detail.edit. Supports text
	// (default), number, textarea, and select (options: [[value, label], …]).
	// data-edittype rides so the save path can coerce tags/numbers correctly.
	function surfaceFieldHtml( f, val, dataAttr ) {
		const attr = dataAttr || 'data-editfield';
		const cls = `minn-input${ f.mono ? ' mono' : '' }${ f.type === 'textarea' ? ' minn-surface-textarea' : '' }`;
		const type = f.type || 'text';
		const v = val == null ? '' : val;
		if ( type === 'textarea' ) {
			const rows = f.rows || ( f.mono ? 12 : 3 );
			return `<textarea class="${ cls }" ${ attr }="${ esc( f.key ) }" data-edittype="textarea" rows="${ rows }" placeholder="${ esc( f.placeholder || '' ) }">${ esc( String( v ) ) }</textarea>`;
		}
		if ( type === 'select' ) {
			const opts = ( f.options || [] ).map( ( o ) => {
				const value = Array.isArray( o ) ? o[ 0 ] : o;
				const label = Array.isArray( o ) ? ( o[ 1 ] != null ? o[ 1 ] : o[ 0 ] ) : o;
				return `<option value="${ esc( String( value ) ) }"${ String( value ) === String( v ) ? ' selected' : '' }>${ esc( String( label ) ) }</option>`;
			} ).join( '' );
			return `<select class="${ cls }" ${ attr }="${ esc( f.key ) }" data-edittype="select">${ opts }</select>`;
		}
		if ( type === 'tags' ) {
			const shown = Array.isArray( v ) ? v.join( ', ' ) : String( v );
			return `<input class="${ cls }" ${ attr }="${ esc( f.key ) }" data-edittype="tags" value="${ esc( shown ) }" placeholder="${ esc( f.placeholder || 'tag-one, tag-two' ) }">`;
		}
		return `<input class="${ cls }" ${ attr }="${ esc( f.key ) }" data-edittype="${ esc( type ) }"${ type === 'number' ? ' type="number"' : '' } value="${ esc( String( v ) ) }" placeholder="${ esc( f.placeholder || '' ) }">`;
	}

	function surfaceFieldValue( el ) {
		let v = el.value;
		const kind = el.dataset.edittype || el.type || 'text';
		if ( kind === 'number' ) return v === '' ? null : Number( v );
		if ( kind === 'tags' ) return v.split( /,\s*/ ).map( ( t ) => t.trim() ).filter( Boolean );
		return v;
	}

	function surfaceCell( item, colDef ) {
		let v = surfaceValue( item, colDef.key );
		if ( ( v == null || v === '' ) && colDef.altKey ) v = surfaceValue( item, colDef.altKey );
		// Booleans (Code Snippets' `active`) and string arrays (tags) need a
		// human form before they hit a pill or text cell.
		if ( typeof v === 'boolean' ) v = v ? 'active' : 'inactive';
		if ( Array.isArray( v ) ) v = v.join( ', ' );
		switch ( colDef.format ) {
			case 'ago': {
				// Guard empty and zero timestamps (Redirection stores 0000-00-00
				// for a never-hit redirect). colDef.utc / *_gmt keys force UTC
				// (Code Snippets gmdate, Stream/WSAL, etc. — else "in 4h" on EDT).
				const raw = String( v || '' );
				const label = timeAgoForKey( raw, colDef.key || colDef.altKey || '', !! colDef.utc );
				return `<div class="minn-row-meta minn-cell-clip" title="${ esc( raw ) }">${ esc( label ) }</div>`;
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
		const coll = surfaceColl( s, ss );
		if ( ! ss.cache || ( coll.tabs && ! ss.tabs ) || ( s.status && ! ss.status ) ) {
			view.innerHTML = '<div class="minn-loading">Loading…</div>';
			Promise.all( [ loadSurfaceTabs( s ), loadSurfaceItems( s ), loadSurfaceStatus( s ) ] )
				.then( renderIfCurrent( s.id ) )
				.catch( showErr );
			return;
		}
		const c = ss.cache;
		const cols = coll.columns || [];
		// Column widths: an adapter's explicit `width` wins; otherwise size by
		// role — flexible for the title/text columns, fixed and narrow for the
		// short ones (codes, counts, dates, pills) so long values get the room.
		const FIXED = { ago: '128px', pill: '110px', mono: '84px', num: '84px' };
		const gridCols = cols.map( ( col, i ) =>
			col.width || FIXED[ col.format ] || ( i === 0 ? 'minmax(0,1.6fr)' : 'minmax(0,1fr)' )
		).join( ' ' ) + ' 30px';

		view.innerHTML = `
		${ ss.view !== 'manage' ? surfaceStatusHtml( ss.status ) : '' }
		<div class="minn-toolbar">
			${ s.manage ? `
			<div class="minn-tabs minn-view-switch">
				<button class="minn-tab${ ss.view !== 'manage' ? ' active' : '' }" data-sview="main">${ esc( s.collection.viewLabel || 'Entries' ) }</button>
				<button class="minn-tab${ ss.view === 'manage' ? ' active' : '' }" data-sview="manage">${ esc( s.manage.viewLabel || 'Manage' ) }</button>
			</div>` : '' }
			${ ss.tabs && ss.tabs.length > 1 ? `
			<div class="minn-tabs">
				${ ss.tabs.map( ( [ id, label ] ) =>
					`<button class="minn-tab${ ss.tab === id ? ' active' : '' }" data-stab="${ esc( id ) }">${ esc( label ) }</button>` ).join( '' ) }
			</div>` : '' }
			${ coll.search ? `<input class="minn-input minn-toolbar-search" id="minn-surface-search" placeholder="Search ${ esc( ( coll.viewLabel || 'items' ).toLowerCase() ) }…" value="${ esc( ss.q || '' ) }">` : '' }
			<div class="minn-toolbar-meta">${ metaLabel( c.total, 'item' ) }</div>
			${ coll.create ? `<button class="minn-btn-soft" id="minn-surface-add">${ icon( 'plus' ) } ${ esc( coll.create.label || 'Add' ) }</button>` : '' }
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
		${ pagerHtml( c.page, c.totalPages, c.total, 'item' ) }`;

		$$( '[data-stab]', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', () => {
				ss.tab = btn.dataset.stab;
				ss.cache = null;
				renderSurface( s );
			} )
		);
		$$( '[data-sview]', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', () => {
				if ( ss.view === btn.dataset.sview ) return;
				ss.view = btn.dataset.sview;
				// The views are different collections — nothing carries over.
				ss.cache = null;
				ss.tabs = null;
				ss.tab = '_all';
				ss.q = '';
				renderSurface( s );
			} )
		);
		$$( '[data-sitem]', view ).forEach( ( row ) =>
			row.addEventListener( 'click', () => {
				const item = c.items[ parseInt( row.dataset.sitem, 10 ) ];
				if ( item ) openSurfaceDetail( s, item );
			} )
		);
		bindSurfaceStatus( s, view );
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
		bindPager( view, c.page, ( p ) => loadSurfaceItems( s, p ), () => { if ( state.route === s.id ) renderSurface( s ); } );
	}

	async function openSurfaceDetail( s, item ) {
		const coll = surfaceColl( s, surfaceState( s.id ) );
		state.modal = { type: 'surface', surface: s, coll, item, labels: null, sections: null, loading: true };
		renderOverlays();
		const detail = ( coll.detail || {} );
		try {
			if ( detail.detailRoute ) {
				state.modal.item = await api( detail.detailRoute.replace( '{id}', item.id ) );
			}
			// A sections route returns the whole display model (grouped rows,
			// labels resolved server-side) — no client label mapping needed.
			if ( detail.sectionsRoute ) {
				state.modal.sections = await api( detail.sectionsRoute.replace( '{id}', item.id ) );
			} else if ( detail.labels ) {
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

	// Position of the open surface detail within the loaded list page, for
	// prev/next (←/→) — same idea as the media modal. Scoped to the current
	// cache so we never invent off-page ids.
	function surfaceModalContext() {
		const m = state.modal;
		if ( ! m || m.type !== 'surface' || ! m.item || ! m.surface ) return null;
		const ss = surfaceState( m.surface.id );
		const items = ss.cache && ss.cache.items;
		if ( ! items || ! items.length ) return null;
		const idx = items.findIndex( ( x ) => String( x.id ) === String( m.item.id ) );
		return idx === -1 ? null : { items, idx, surface: m.surface };
	}

	function surfaceModalNav( dir ) {
		const m = state.modal;
		if ( m && m.loading ) return; // don't stack mid-flight detail loads
		const ctx = surfaceModalContext();
		if ( ! ctx ) return;
		const next = ctx.idx + dir;
		if ( next < 0 || next >= ctx.items.length ) return;
		openSurfaceDetail( ctx.surface, ctx.items[ next ] );
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
			<div class="minn-toolbar-meta">${ metaLabel( flat.length, 'item' ) }</div>
			<button class="minn-btn-soft" id="minn-menu-new">${ icon( 'plus' ) } New menu</button>
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
				<span class="minn-menu-grip" draggable="true" title="Drag to reorder">${ icon( 'grip' ) }</span>
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
		// Drag to reorder: the grip drags its row; dropping on another row
		// makes the dragged item that row's SIBLING, above or below its
		// midpoint. Children travel with their parent (the tree is
		// parent-keyed); indent stays on the ⇤⇥ buttons.
		let dragId = null;
		$$( '.minn-menu-grip', view ).forEach( ( grip ) => {
			const row = grip.closest( '[data-mi]' );
			grip.addEventListener( 'click', ( e ) => e.stopPropagation() ); // never open the editor
			grip.addEventListener( 'dragstart', ( e ) => {
				dragId = parseInt( row.dataset.mi, 10 );
				row.classList.add( 'dragging' );
				e.dataTransfer.effectAllowed = 'move';
				e.dataTransfer.setData( 'text/plain', String( dragId ) );
			} );
			grip.addEventListener( 'dragend', () => {
				dragId = null;
				$$( '.minn-menu-row', view ).forEach( ( r ) => r.classList.remove( 'dragging', 'drop-above', 'drop-below' ) );
			} );
		} );
		$$( '.minn-menu-row[data-mi]', view ).forEach( ( row ) => {
			row.addEventListener( 'dragover', ( e ) => {
				if ( dragId === null || parseInt( row.dataset.mi, 10 ) === dragId ) return;
				e.preventDefault();
				e.dataTransfer.dropEffect = 'move';
				const r = row.getBoundingClientRect();
				const below = e.clientY > r.top + r.height / 2;
				row.classList.toggle( 'drop-below', below );
				row.classList.toggle( 'drop-above', ! below );
			} );
			row.addEventListener( 'dragleave', () => row.classList.remove( 'drop-above', 'drop-below' ) );
			row.addEventListener( 'drop', ( e ) => {
				e.preventDefault();
				const targetId = parseInt( row.dataset.mi, 10 );
				const below = row.classList.contains( 'drop-below' );
				row.classList.remove( 'drop-above', 'drop-below' );
				if ( dragId === null || targetId === dragId ) return;
				const movedId = dragId;
				menuShapeAction( ms, () => {
					const it = findItem( movedId );
					const target = findItem( targetId );
					// A parent can't become its own descendant's sibling.
					for ( let p = target; p; p = findItem( p.parent ) ) {
						if ( p.id === it.id ) throw new Error( 'Can’t drop an item inside itself.' );
					}
					it.parent = target.parent;
					it.order = target.order + ( below ? 0.5 : -0.5 );
				} );
			} );
		} );

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
					${ items.length > 1 ? `<span class="minn-menu-grip" draggable="true" title="Drag to reorder">${ icon( 'grip' ) }</span>` : '' }
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
		// Drag to reorder within a sidebar — same grip UX as menus. Dropping
		// on another row puts the dragged widget above/below its midpoint;
		// cross-sidebar moves stay on the "Move to…" select.
		$$( '[data-sidebar]', view ).forEach( ( area ) => {
			let dragWid = null;
			$$( '.minn-menu-grip', area ).forEach( ( grip ) => {
				const row = grip.closest( '[data-widget]' );
				grip.addEventListener( 'dragstart', ( e ) => {
					dragWid = row.dataset.widget;
					row.classList.add( 'dragging' );
					e.dataTransfer.effectAllowed = 'move';
					e.dataTransfer.setData( 'text/plain', dragWid );
				} );
				grip.addEventListener( 'dragend', () => {
					dragWid = null;
					$$( '.minn-widget-row', area ).forEach( ( r ) => r.classList.remove( 'dragging', 'drop-above', 'drop-below' ) );
				} );
			} );
			$$( '.minn-widget-row[data-widget]', area ).forEach( ( row ) => {
				row.addEventListener( 'dragover', ( e ) => {
					if ( ! dragWid || row.dataset.widget === dragWid ) return;
					e.preventDefault();
					e.dataTransfer.dropEffect = 'move';
					const r = row.getBoundingClientRect();
					const below = e.clientY > r.top + r.height / 2;
					row.classList.toggle( 'drop-below', below );
					row.classList.toggle( 'drop-above', ! below );
				} );
				row.addEventListener( 'dragleave', () => row.classList.remove( 'drop-above', 'drop-below' ) );
				row.addEventListener( 'drop', async ( e ) => {
					e.preventDefault();
					const targetId = row.dataset.widget;
					const below = row.classList.contains( 'drop-below' );
					row.classList.remove( 'drop-above', 'drop-below' );
					if ( ! dragWid || targetId === dragWid ) return;
					const movedId = dragWid;
					const sid = area.dataset.sidebar;
					const s = ws.sidebars.find( ( x ) => x.id === sid );
					if ( ! s ) return;
					const arr = [ ...( s.widgets || [] ) ];
					const from = arr.indexOf( movedId );
					if ( from === -1 ) return;
					arr.splice( from, 1 );
					const to = arr.indexOf( targetId );
					if ( to === -1 ) return;
					arr.splice( below ? to + 1 : to, 0, movedId );
					area.classList.add( 'minn-busy' );
					try {
						await api( `wp/v2/sidebars/${ sid }`, { method: 'POST', body: JSON.stringify( { widgets: arr } ) } );
					} catch ( err ) {
						toast( err.message, true );
					}
					reloadWidgets();
				} );
			} );
		} );
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

	// De-duplicated like loadTypes: concurrent callers (boot warm-up + a
	// navigation + a re-render) share ONE fetch and therefore ONE plugins
	// array. Without this, a late fetch could replace state.cache.plugins
	// AFTER a render had already bound the toggle handlers to the older array
	// — the click then mutated the stale array while the re-render read the
	// new one, so the switch appeared not to move (the live plugin state was
	// correct, only the card lagged). The promise clears on settle so an
	// explicit reload (cache set null after activate/delete) still refetches.
	let pluginsPromise = null;
	function loadPlugins() {
		if ( pluginsPromise ) return pluginsPromise;
		pluginsPromise = ( async () => {
			const jobs = [ api( 'wp/v2/plugins' ) ];
			if ( B.caps.update ) {
				jobs.push( api( 'minn-admin/v1/plugin-updates' ).catch( () => ( {} ) ) );
			}
			// wp.org icons + directory links; tolerant — cards fall back to
			// letter avatars without it.
			const metaJob = state.cache.pluginMeta
				? Promise.resolve( state.cache.pluginMeta )
				: api( 'minn-admin/v1/plugin-meta' ).catch( () => ( {} ) );
			const [ plugins, upd ] = await Promise.all( jobs );
			state.cache.pluginMeta = await metaJob;
			state.cache.plugins = plugins;
			state.cache.pluginUpdates = ( upd && upd.updates ) || {};
			// Pending THEME updates ({stylesheet: new_version}) count toward
			// the Extensions dot too — per-theme badges only render inside
			// the Themes tab. The map also feeds Update everything.
			state.cache.themeUpdates = ( upd && upd.themes ) || {};
			const dot = $( '#minn-plugin-dot' );
			if ( dot ) dot.hidden = ! Object.keys( state.cache.pluginUpdates ).length && ! Object.keys( state.cache.themeUpdates ).length;
		} )().finally( () => { pluginsPromise = null; } );
		return pluginsPromise;
	}

	const extTabsHtml = () => B.caps.themes ? `
			<div class="minn-tabs">
				<button class="minn-tab${ state.extTab === 'plugins' ? ' active' : '' }" data-xtab="plugins">Plugins</button>
				<button class="minn-tab${ state.extTab === 'themes' ? ' active' : '' }" data-xtab="themes">Themes</button>
			</div>` : '';

	function bindExtTabs( view ) {
		$$( '[data-xtab]', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', () => {
				if ( state.extTab === btn.dataset.xtab ) return;
				state.extTab = btn.dataset.xtab;
				// Plugins and Themes have different filter sets — reset when switching.
				state.extFilter = 'all';
				state.extSearch = '';
				renderExtensions();
			} )
		);
	}

	// Shared filter/search bar for both Extensions tabs. `counts` is a map of
	// filter id → count; only ids present get a pill. Wires the pills + search
	// (client-side; the full plugin/theme set is already cached) to re-render.
	function extFilterBarHtml( filters, counts, placeholder ) {
		const pills = filters
			.filter( ( [ id ] ) => id === 'all' || counts[ id ] )
			.map( ( [ id, label ] ) =>
				`<button class="minn-tab${ state.extFilter === id ? ' active' : '' }" data-xfilter="${ id }">${ esc( label ) }${ counts[ id ] != null ? ` <span class="minn-tab-count">${ counts[ id ] }</span>` : '' }</button>` )
			.join( '' );
		return `
			<div class="minn-tabs minn-ext-filters">${ pills }</div>
			<input class="minn-input minn-toolbar-search" id="minn-ext-search" placeholder="${ esc( placeholder ) }" value="${ esc( state.extSearch || '' ) }">`;
	}

	function bindExtFilterBar( view ) {
		$$( '[data-xfilter]', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', () => {
				if ( state.extFilter === btn.dataset.xfilter ) return;
				state.extFilter = btn.dataset.xfilter;
				renderExtensions();
			} )
		);
		const search = $( '#minn-ext-search', view );
		if ( search ) {
			search.addEventListener( 'input', () => {
				state.extSearch = search.value;
				renderExtensions();
				const s = $( '#minn-ext-search' );
				if ( s ) { s.focus(); s.setSelectionRange( s.value.length, s.value.length ); }
			} );
		}
	}

	// Core version + update offer, for the Overview/Extensions banners and
	// the persistent topbar chip.
	async function loadCoreStatus() {
		if ( ! B.caps.core || state.cache.core ) return;
		state.cache.core = await api( 'minn-admin/v1/core' ).catch( () => null );
		updateCoreChip();
	}

	function updateCoreChip() {
		const chip = $( '#minn-core-chip' );
		if ( ! chip ) return;
		const u = state.cache.core && state.cache.core.update;
		chip.hidden = ! u;
		if ( u ) $( '#minn-core-chip-text' ).textContent = `WordPress ${ u.version }`;
	}

	// The live visibility state (state.visibility) falls back to the boot
	// snapshot until the first refresh. refreshVisibility re-reads it after a
	// maintenance/search toggle so the banner and chip update WITHOUT a page
	// reload (Austin's report: they were stale until refresh).
	const visState = () => state.visibility || B.visibility;
	async function refreshVisibility() {
		try { state.visibility = await api( 'minn-admin/v1/visibility' ); } catch ( e ) { /* keep the last state */ }
		updateVisChip();
		// The System page's "Site visibility" health check is server-derived
		// from the same posture — bust it so the row appears/disappears with
		// the toggle instead of going stale (Austin's report).
		state.cache.system = null;
		if ( state.route === 'overview' ) renderOverview();
		// The Settings page's Visibility toggles read from state.cache.settings,
		// so a toggle from the chip/banner must re-render it too — otherwise the
		// switch there shows stale (Austin's report).
		else if ( state.route === 'settings' ) renderSettings();
		else if ( state.route === 'system' ) {
			// Load first, then swap with the scroll kept — a null-cache render
			// collapses to the loading state and clamps the scroller (rule
			// from the license-activate flow).
			const scroller = $( '.minn-scroll' );
			const keepTop = scroller ? scroller.scrollTop : 0;
			await loadSystem();
			if ( state.route !== 'system' ) return;
			renderSystem();
			const sc = $( '.minn-scroll' );
			if ( sc ) sc.scrollTop = keepTop;
		}
	}

	// Persistent amber chip on EVERY route when the site is not fully public —
	// it follows the owner around until they fix it, since a hidden or
	// unindexed site is easy to forget and expensive to leave broken. Clicking
	// it opens a popover with the actual controls, not a dump to Settings.
	function updateVisChip() {
		const chip = $( '#minn-vis-chip' );
		if ( ! chip ) return;
		const v = visState();
		const hide = ! v || v.public;
		chip.hidden = hide;
		if ( hide ) { closeVisPopover(); return; }
		const label = 'hidden' === v.state ? 'Site hidden'
			: 'password' === v.state ? 'Password gated'
			: 'partial' === v.state ? 'Partly hidden'
			: 'Not indexed';
		$( '#minn-vis-chip-text' ).textContent = label;
	}

	// The controls that actually FIX the current visibility state, shared by
	// the banner and the chip popover. Minn-owned settings (its own maintenance
	// mode, the search-engine toggle) get an inline switch; third-party
	// maintenance/coming-soon/password plugins can only be linked out to.
	function visibilityFixControls() {
		const v = visState();
		const out = [];
		( v.providers || [] ).forEach( ( p ) => {
			if ( p.minn ) {
				out.push( { type: 'toggle', label: 'Maintenance mode', setting: 'minn_admin_maintenance', on: true } );
			} else {
				out.push( { type: 'link', label: p.name, url: p.url } );
			}
		} );
		if ( v.searchDiscouraged ) {
			out.push( { type: 'toggle', label: 'Search engine visibility', setting: 'blog_public', on: false } );
		}
		return out;
	}
	function visControlHtml( c, i ) {
		if ( 'toggle' === c.type ) {
			return `<div class="minn-vis-ctl">
				<span class="minn-toggle-label">${ esc( c.label ) }</span>
				<button class="minn-switch${ c.on ? ' on' : '' }" data-vistoggle="${ i }" role="switch" aria-checked="${ c.on }" aria-label="${ esc( c.label ) }"><span class="minn-switch-knob"></span></button>
			</div>`;
		}
		return `<a class="minn-btn-soft" href="${ esc( c.url || B.site.adminUrl ) }" target="_blank" rel="noopener">${ esc( c.label ) } ↗</a>`;
	}
	// Flip a Minn-owned visibility setting and refresh live.
	async function runVisToggle( c, btn ) {
		if ( btn ) btn.disabled = true;
		const next = ! c.on;
		const val = 'blog_public' === c.setting ? ( next ? 1 : 0 ) : next;
		try {
			await api( 'wp/v2/settings', { method: 'POST', body: JSON.stringify( { [ c.setting ]: val } ) } );
			// Keep the Settings page's cached toggle state in sync so it's
			// correct whether or not it's the current view.
			if ( state.cache.settings && state.cache.settings.values ) {
				state.cache.settings.values[ c.setting ] = val;
			}
			await refreshVisibility();
			toast( 'minn_admin_maintenance' === c.setting
				? ( next ? 'Maintenance mode on' : 'Maintenance mode off — the site is public' )
				: ( next ? 'Search engines can index the site' : 'Search engines discouraged' ) );
			// If the site is now fully public, the popover has nothing left to
			// show; otherwise refresh its controls in place.
			if ( visState().public ) closeVisPopover();
			else refreshVisPopover();
		} catch ( e ) {
			toast( e.message, true );
			if ( btn ) btn.disabled = false;
		}
	}

	function closeVisPopover() {
		const pop = $( '#minn-vis-pop' );
		if ( pop ) pop.remove();
		document.removeEventListener( 'mousedown', visPopOutside );
	}
	function visPopOutside( e ) {
		const pop = $( '#minn-vis-pop' );
		if ( pop && ! pop.contains( e.target ) && ! e.target.closest( '#minn-vis-chip' ) ) closeVisPopover();
	}
	function visPopInnerHtml() {
		const v = visState();
		const title = 'password' === v.state ? 'Site password-protected'
			: 'search-discouraged' === v.state ? 'Search engines discouraged'
			: 'partial' === v.state ? 'Part of the site is hidden'
			: 'Site hidden from the public';
		const sub = 'hidden' === v.state ? 'Visitors see a maintenance or coming-soon page.'
			: 'password' === v.state ? 'Visitors must enter a password to see any page.'
			: 'partial' === v.state ? 'Some pages show a coming-soon page instead of their content.'
			: 'The site is public but asks search engines not to index it.';
		return `<div class="minn-vis-pop-title">${ esc( title ) }</div>
			<div class="minn-vis-pop-sub">${ esc( sub ) }</div>
			${ visibilityFixControls().map( visControlHtml ).join( '' ) }`;
	}
	function bindVisPop( pop ) {
		const controls = visibilityFixControls();
		$$( '[data-vistoggle]', pop ).forEach( ( btn ) =>
			btn.addEventListener( 'click', () => runVisToggle( controls[ +btn.dataset.vistoggle ], btn ) ) );
	}
	function refreshVisPopover() {
		const pop = $( '#minn-vis-pop' );
		if ( ! pop ) return;
		pop.innerHTML = visPopInnerHtml();
		bindVisPop( pop );
	}
	function openVisibilityPopover( anchor ) {
		if ( $( '#minn-vis-pop' ) ) { closeVisPopover(); return; }
		const pop = document.createElement( 'div' );
		pop.className = 'minn-vis-pop';
		pop.id = 'minn-vis-pop';
		pop.innerHTML = visPopInnerHtml();
		document.body.appendChild( pop );
		const r = anchor.getBoundingClientRect();
		pop.style.top = ( r.bottom + 6 ) + 'px';
		pop.style.right = Math.max( 8, window.innerWidth - r.right ) + 'px';
		bindVisPop( pop );
		setTimeout( () => document.addEventListener( 'mousedown', visPopOutside ), 0 );
	}

	// "Your site is hidden from the public" — the loudest thing Minn can warn
	// about, on Overview. Minn-owned states get inline controls right in the
	// banner; third-party ones link out.
	function visibilityBannerHtml() {
		const v = visState();
		if ( ! v || v.public ) return '';
		const names = ( v.providers || [] ).map( ( p ) => p.name );
		let title, desc;
		if ( 'hidden' === v.state ) {
			title = 'Your site is hidden from the public';
			desc = `Visitors can't see the site — ${ names.join( ', ' ) } ${ names.length === 1 ? 'is' : 'are' } showing a maintenance or coming-soon page instead.`;
		} else if ( 'password' === v.state ) {
			title = 'Your site is password-protected';
			desc = `The whole site is behind a password (${ names.join( ', ' ) }). Visitors must enter it before seeing any page.`;
		} else if ( 'partial' === v.state ) {
			// Provider notes say WHICH part (WooCommerce: "Only store pages…").
			const notes = ( v.providers || [] ).map( ( p ) => p.note ).filter( Boolean );
			title = 'Part of your site is hidden';
			desc = `${ names.join( ', ' ) } ${ names.length === 1 ? 'is' : 'are' } showing a coming-soon page on some pages${ notes.length ? ': ' + notes.join( '; ' ).toLowerCase() : '' }.`;
		} else {
			title = 'Search engines are discouraged';
			desc = 'The site is public, but "Discourage search engines" is on in Settings → Reading, so it asks not to be indexed.';
		}
		return `
		<div class="minn-card minn-vis-banner block">
			<div class="minn-vis-info">
				<div class="minn-panel-title">${ icon( 'warn' ) } ${ esc( title ) }</div>
				<div class="minn-toggle-desc">${ esc( desc ) }</div>
			</div>
			<div class="minn-vis-actions">${ visibilityFixControls().map( visControlHtml ).join( '' ) }</div>
		</div>`;
	}

	function coreBannerHtml() {
		const core = state.cache.core;
		if ( ! core || ! core.update ) return '';
		return `
		<div class="minn-card minn-core-banner">
			<div class="minn-core-info">
				<div class="minn-panel-title">WordPress ${ esc( core.update.version ) } is available</div>
				<div class="minn-toggle-desc">You're on ${ esc( core.version ) }. The site enters maintenance mode for a few seconds while core updates.</div>
			</div>
			<button class="minn-btn-primary" id="minn-core-update">${ icon( 'refresh' ) } Update WordPress</button>
		</div>`;
	}

	// Run the offered core update; resolves the new version. Replacing core
	// files recycles the PHP worker serving the update request, so its
	// response often never arrives even when the update succeeds. The POLL
	// is the reliable completion signal; the request's own response is just
	// a fast-path. A false "update failed" on the scariest button in the
	// app costs more trust than the update earns.
	function runCoreUpdate( offered ) {
		return new Promise( ( resolve, reject ) => {
			const started = Date.now();
			let settled = false;
			const finish = ( version ) => {
				if ( settled ) return;
				settled = true;
				clearInterval( poll );
				resolve( version );
			};
			const fail = ( msg ) => {
				if ( settled ) return;
				settled = true;
				clearInterval( poll );
				reject( new Error( msg ) );
			};
			api( 'minn-admin/v1/core/update', { method: 'POST', body: '{}' } )
				.then( ( r ) => {
					state.cache.core = null;
					return loadCoreStatus().then( () => finish( r.version ) );
				} )
				.catch( ( e ) => {
					// Our Error = the server actually answered with a failure.
					// A TypeError is the dropped connection — the poll decides.
					if ( ! ( e instanceof TypeError ) ) fail( e.message );
				} );
			const poll = setInterval( async () => {
				if ( settled ) return;
				if ( Date.now() - started > 5 * 60 * 1000 ) {
					fail( 'Still updating after 5 minutes. Give it a moment, then check Extensions.' );
					return;
				}
				try {
					const s = await api( 'minn-admin/v1/core' );
					// Maintenance mode 503s while files copy; the offered
					// version with no remaining offer means the update landed.
					if ( s && s.version === offered && ! s.update ) {
						// The dropped request may also have skipped the DB
						// migration step — run it before declaring done.
						if ( s.dbUpgrade ) {
							await fetch( B.site.adminUrl + 'upgrade.php?step=1', { credentials: 'same-origin' } ).catch( () => {} );
						}
						state.cache.core = s;
						finish( s.version );
					}
				} catch ( e ) { /* mid-update requests are expected to fail */ }
			}, 8000 );
		} );
	}

	function bindCoreBanner( view ) {
		const btn = $( '#minn-core-update', view );
		if ( ! btn ) return;
		btn.addEventListener( 'click', async () => {
			const core = state.cache.core;
			if ( ! confirm( `Update WordPress to ${ core.update.version }? Visitors see a maintenance notice for a few seconds while files are replaced.` ) ) return;
			btn.disabled = true;
			btn.textContent = 'Updating WordPress…';
			try {
				const version = await runCoreUpdate( core.update.version );
				toast( `WordPress updated to ${ version }` );
				state.cache.notifications = null;
				updateCoreChip();
				if ( state.route === 'extensions' ) renderExtensions();
				else if ( state.route === 'overview' ) renderOverview();
			} catch ( e ) {
				toast( e.message, true );
				btn.disabled = false;
				btn.textContent = 'Update WordPress';
			}
		} );
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
		if ( B.caps.core && ! state.cache.core ) {
			loadCoreStatus().then( () => { if ( state.route === 'extensions' && state.cache.core && state.cache.core.update ) renderExtensions(); } );
		}
		// Toggling/updating/deleting a plugin re-renders this whole view — the
		// innerHTML swap can clamp the scroller to the top mid-list. Restore.
		const scroller = $( '.minn-scroll' );
		const keepScrollTop = scroller ? scroller.scrollTop : 0;
		const updates = state.cache.pluginUpdates;
		const updateCount = Object.keys( updates ).length;
		const active = plugins.filter( ( p ) => p.status === 'active' ).length;
		const hasUpd = ( p ) => !! updates[ p.plugin + '.php' ];

		// Client-side filter + search over the already-cached plugin set.
		const q = ( state.extSearch || '' ).trim().toLowerCase();
		const matchesFilter = ( p ) =>
			state.extFilter === 'active' ? p.status === 'active'
			: state.extFilter === 'inactive' ? p.status !== 'active'
			: state.extFilter === 'updates' ? hasUpd( p )
			: true;
		const matchesSearch = ( p ) => ! q ||
			cleanPluginName( p.name ).toLowerCase().includes( q ) ||
			stripTags( p.description && p.description.rendered ).toLowerCase().includes( q );
		const visible = plugins.filter( ( p ) => matchesFilter( p ) && matchesSearch( p ) );

		const filterDefs = [ [ 'all', 'All' ], [ 'active', 'Active' ], [ 'inactive', 'Inactive' ] ];
		if ( B.caps.update ) filterDefs.push( [ 'updates', 'Updates' ] );
		const counts = { all: plugins.length, active, inactive: plugins.length - active, updates: updateCount };

		view.innerHTML = `
		${ coreBannerHtml() }
		<div class="minn-toolbar">
			${ extTabsHtml() }
			${ extFilterBarHtml( filterDefs, counts, 'Search plugins…' ) }
			${ B.caps.install ? `
				<button class="minn-btn-soft" id="minn-add-plugin" style="margin-left:auto;">${ icon( 'plus' ) } Add plugin</button>` : '' }
			${ updateCount && B.caps.update ? `
				<button class="minn-btn-soft" id="minn-update-all"${ B.caps.install ? '' : ' style="margin-left:auto;"' }>
					${ icon( 'refresh' ) } Update all (${ updateCount })
				</button>` : '' }
		</div>
		${ visible.length ? `
		<div class="minn-plugin-grid">
			${ visible.map( ( p ) => {
				const name = cleanPluginName( p.name );
				const hasUpdate = !! updates[ p.plugin + '.php' ];
				const on = p.status === 'active';
				// wp.org plugins wear their real icon, and the icon links to
				// their directory page; everything else keeps the letter tile.
				const meta = ( state.cache.pluginMeta || {} )[ p.plugin + '.php' ];
				const tile = `<div class="minn-plugin-icon" style="background:${ colorFor( name ) }">${ esc( name.charAt( 0 ) ) }${ meta && meta.icon ? `<img src="${ esc( meta.icon ) }" alt="" loading="lazy">` : '' }</div>`;
				return `
				<div class="minn-card minn-plugin" data-plugin="${ esc( p.plugin ) }">
					${ meta && meta.url ? `<a class="minn-plugin-icon-link" href="${ esc( meta.url ) }" target="_blank" rel="noopener" title="${ /wordpress\.org/.test( meta.url ) ? `View ${ esc( name ) } on WordPress.org` : `${ esc( name ) } plugin page` }">${ tile }</a>` : tile }
					<div class="minn-plugin-body">
						<div class="minn-plugin-head">
							<div class="minn-plugin-name">${ esc( name ) }</div>
							${ hasUpdate ? ( B.caps.update
								? `<button class="minn-badge-update as-btn" data-update="${ esc( p.plugin ) }" title="Update to ${ esc( updates[ p.plugin + '.php' ] ) }">Update → ${ esc( updates[ p.plugin + '.php' ] ) }</button>`
								: `<span class="minn-badge-update">Update</span>` ) : '' }
						</div>
						<div class="minn-plugin-desc">${ esc( stripTags( ( ( p.description && p.description.rendered ) || '' ).replace( /<cite>[\s\S]*?<\/cite>/, '' ) ) ) }</div>
						${ p.author ? `<div class="minn-plugin-author">by ${ p.author_uri
							? `<a href="${ esc( p.author_uri ) }" target="_blank" rel="noopener">${ esc( decodeEntities( stripTags( p.author ) ) ) }</a>`
							: esc( decodeEntities( stripTags( p.author ) ) ) }</div>` : '' }
						<div class="minn-plugin-foot">
							<div class="minn-plugin-ver">v${ esc( p.version || '?' ) }</div>
							<button class="minn-switch${ on ? ' on' : '' }" data-toggle="${ esc( p.plugin ) }" role="switch" aria-checked="${ on }" aria-label="Toggle ${ esc( name ) }"><span class="minn-switch-knob"></span></button>
							<span class="minn-state-label${ on ? ' on' : '' }">${ on ? 'Active' : 'Inactive' }</span>
							${ ! on && B.caps.delete ? `<button class="minn-plugin-delete" data-del="${ esc( p.plugin ) }" title="Delete ${ esc( name ) }">${ icon( 'trash' ) }</button>` : '' }
						</div>
					</div>
				</div>`;
			} ).join( '' ) }
		</div>` : `<div class="minn-card minn-empty">${ q ? 'No plugins match “' + esc( state.extSearch.trim() ) + '”.' : 'No ' + state.extFilter + ' plugins.' }</div>` }`;

		if ( scroller ) scroller.scrollTop = keepScrollTop;
		bindCoreBanner( view );
		bindExtFilterBar( view );
		// Broken icon URLs fall back to the letter tile underneath.
		$$( '.minn-plugin-icon img', view ).forEach( ( img ) =>
			img.addEventListener( 'error', () => img.remove() )
		);
		$$( '[data-toggle]', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', async () => {
				const file = btn.dataset.toggle;
				const plugin = plugins.find( ( p ) => p.plugin === file );
				const activating = plugin.status !== 'active';
				if ( ! activating && file === 'minn-admin/minn-admin' ) {
					// Turning Minn off ejects the user — that deserves a real
					// modal and a readable landing, not a native confirm() and
					// an instant yank to wp-admin (the bounce-audit P1).
					state.modal = { type: 'minn-off', file, done: false };
					renderOverlays();
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
					// traffic provider on Overview, registered post types, CPT
					// tabs, page builders, and which blocks/patterns the
					// editor can insert (Otter et al. only registered after
					// activate — boot snapshot would stay empty until reload).
					state.cache.overview = null;
					bustTypeCaches();
					await refreshAfterPluginChange();
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
					// Self-update replaces app.js / CSS / boot payload — a soft
					// re-render keeps the old SPA in memory. Hard-reload so the
					// version badge, new routes, and cache-busted assets land.
					if ( isMinnAdminPluginFile( file ) ) {
						reloadAfterMinnSelfUpdate( r && r.version );
						return;
					}
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
					await refreshAfterPluginChange();
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
				state.modal = { type: 'plugin-install', q: '', category: null, results: null, searching: false, page: 1, pages: 1, total: 0 };
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
		const activeCount = themes.filter( ( t ) => t.active ).length;
		const updateCount = themes.filter( ( t ) => t.update ).length;

		// Client-side filter + search; keep the original index for data-tact refs.
		const q = ( state.extSearch || '' ).trim().toLowerCase();
		const visible = themes
			.map( ( t, i ) => ( { t, i } ) )
			.filter( ( { t } ) =>
				state.extFilter === 'active' ? t.active
				: state.extFilter === 'updates' ? !! t.update
				: true )
			.filter( ( { t } ) => ! q ||
				( t.name || '' ).toLowerCase().includes( q ) ||
				( t.author || '' ).toLowerCase().includes( q ) );

		const filterDefs = [ [ 'all', 'All' ], [ 'active', 'Active' ] ];
		if ( B.caps.updateThemes ) filterDefs.push( [ 'updates', 'Updates' ] );
		const counts = { all: themes.length, active: activeCount, updates: updateCount };

		view.innerHTML = `
		<div class="minn-toolbar">
			${ extTabsHtml() }
			${ extFilterBarHtml( filterDefs, counts, 'Search themes…' ) }
			${ B.caps.installThemes ? `<button class="minn-btn-soft" id="minn-add-theme" style="margin-left:auto;">${ icon( 'plus' ) } Add theme</button>` : '' }
		</div>
		${ visible.length ? `
		<div class="minn-theme-grid">
			${ visible.map( ( { t, i } ) => `
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
		</div>` : `<div class="minn-card minn-empty">${ q ? 'No themes match “' + esc( state.extSearch.trim() ) + '”.' : 'No ' + state.extFilter + ' themes.' }</div>` }`;

		bindExtTabs( view );
		bindExtFilterBar( view );
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
					// Bricks and Divi are THEMES — a theme switch can add or
					// remove a builder. Theme patterns + block styles also
					// change with the active theme.
					if ( 'activate' === action ) await refreshAfterPluginChange();
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
			const updated = r.updated || [];
			const n = updated.length;
			if ( r.failed && r.failed.length ) {
				toast( `${ n } updated, ${ r.failed.length } failed`, true );
			} else {
				toast( n ? `${ n } plugin${ n === 1 ? '' : 's' } updated` : 'Everything is up to date' );
			}
			// Bulk path can include Minn itself — same hard-reload need as a
			// single-plugin self-update (new features + version in boot payload).
			if ( updated.some( isMinnAdminPluginFile ) ) {
				reloadAfterMinnSelfUpdate();
				return;
			}
		} catch ( e ) {
			toast( e.message, true );
		}
		state.cache.plugins = null;
		if ( state.route === 'extensions' ) renderExtensions();
	}

	// Plugin file keys appear as "minn-admin/minn-admin" (list rows) or
	// "minn-admin/minn-admin.php" (update API / bulk results).
	function isMinnAdminPluginFile( file ) {
		const f = String( file || '' ).replace( /\.php$/, '' );
		return f === 'minn-admin/minn-admin';
	}

	function reloadAfterMinnSelfUpdate( version ) {
		toast( version
			? `Minn Admin updated to v${ version } — reloading…`
			: 'Minn Admin updated — reloading…' );
		// Brief beat so the toast paints before navigation tears the SPA down.
		setTimeout( () => { window.location.reload(); }, 700 );
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

	function renderStructureTypes( view, tabsHtml, taxTab ) {
		const c = state.cache.postTypes;
		const tx = state.cache.taxonomies;
		if ( ! c || ( taxTab && ! tx ) ) {
			view.innerHTML = '<div class="minn-loading">Loading…</div>';
			Promise.all( [ c ? null : loadPostTypes(), taxTab && ! tx ? loadTaxonomies() : null ] )
				.then( () => { if ( onStructure() ) renderStructure(); } ).catch( showErr );
			return;
		}
		const tabs = tabsHtml;

		if ( taxTab ) {
			// Attached-to labels resolve through the types list.
			const typeLabel = ( slug ) => {
				const t = c.types.find( ( x ) => x.slug === slug );
				return t ? t.plural : slug;
			};
			view.innerHTML = `
			<div class="minn-toolbar">
				${ tabs }
				<div class="minn-toolbar-meta">${ metaLabel( tx.taxonomies.length, 'taxonomy' ) }</div>
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
					<div class="minn-row-meta">${ B.caps.terms && t.count ? `<button type="button" class="minn-term-count" data-managetax="${ esc( t.slug ) }" title="Manage terms">${ t.count }</button>` : t.count }</div>
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
			$$( '[data-managetax]', view ).forEach( ( btn ) => btn.addEventListener( 'click', ( e ) => {
				e.stopPropagation();
				state.termsTax = btn.dataset.managetax;
				state.cache.terms = null;
				goTerms();
			} ) );
		} else {
			view.innerHTML = `
			<div class="minn-toolbar">
				${ tabs }
				<div class="minn-toolbar-meta">${ metaLabel( c.types.length, 'post type' ) }</div>
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

	}

	// A definition changed — the Content view's type tabs must refetch.
	function bustTypeCaches() {
		state.cache.postTypes = null;
		state.cache.taxonomies = null;
		typesPromise = null;
		state.cache.types = null;
		state.cache.cptContent = {};
	}

	/* ===== System (developer diagnostics) ===== */

	function loadSystem() {
		return api( 'minn-admin/v1/system' ).then( ( s ) => { state.cache.system = s; } );
	}

	// A flat, copy-pasteable report of everything on the page — what a
	// developer drops into a support ticket or a gist.
	function systemReportText( s ) {
		const lines = [ `# System report — ${ esc( B.site.name ) }`, '' ];
		s.checks.forEach( ( c ) => lines.push( `${ c.status === 'pass' ? '✓' : c.status === 'warn' ? '!' : '✗' } ${ c.label}: ${ c.detail }` ) );
		if ( s.licenses && s.licenses.items.length ) {
			lines.push( '', '## Licenses (stored state, not live)' );
			s.licenses.items.forEach( ( it ) => lines.push(
				`- ${ it.name }${ it.kind === 'theme' ? ' [theme]' : '' }: ${ it.state }`
				+ ( it.expires ? ` (${ it.expires === 'lifetime' ? 'lifetime' : 'expires ' + it.expires })` : '' )
				+ ( it.note ? ` — ${ it.note }` : '' )
			) );
		}
		s.groups.forEach( ( g ) => {
			lines.push( '', `## ${ g.title }` );
			g.rows.forEach( ( r ) => lines.push( `- ${ r.key }: ${ r.value || '—' }` ) );
			if ( g.tables && g.tables.length ) {
				lines.push( '', 'Largest tables:' );
				g.tables.forEach( ( t ) => lines.push( `- ${ t.name } — ${ t.size } (${ t.rows } rows)` ) );
			}
			if ( g.autoload ) {
				lines.push( '', `Autoloaded options (${ g.autoload.count } · ${ g.autoload.size_human } on every request):` );
				g.autoload.top.forEach( ( t ) => lines.push( `- ${ t.name } — ${ t.size }` ) );
			}
		} );
		const ext = s.extensions;
		if ( ext ) {
			lines.push( '', `## Plugins (${ ext.active_plugins } active of ${ ext.plugins.length })` );
			ext.plugins.forEach( ( p ) => lines.push( `- ${ p.name } — ${ p.version }${ p.active ? '' : ' (inactive)' }` ) );
			if ( ext.mu_plugins.length ) {
				lines.push( '', '## Must-use plugins' );
				ext.mu_plugins.forEach( ( p ) => lines.push( `- ${ p.name }${ p.version ? ' — ' + p.version : '' }` ) );
			}
			lines.push( '', '## Themes' );
			ext.themes.forEach( ( th ) => lines.push( `- ${ th.name } — ${ th.version }${ th.active ? ' (active)' : '' }${ th.parent ? ' [child of ' + th.parent + ']' : '' }` ) );
		}
		const intg = s.integrations;
		if ( intg ) {
			const probs = ( r ) => ( r.problems && r.problems.length ? ` — PROBLEMS: ${ r.problems.join( '; ' ) }` : '' );
			lines.push( '', '## Integrations' );
			intg.surfaces.forEach( ( r ) => lines.push( `- Surface ${ r.id } (${ r.label }) — ${ r.owner }${ probs( r ) }` ) );
			intg.panels.forEach( ( r ) => lines.push( `- Editor panel ${ r.id } (${ r.label }) — ${ r.owner }${ probs( r ) }` ) );
			intg.designs.forEach( ( r ) => lines.push( `- Design source ${ r.id } (${ r.label }) — ${ r.owner }${ probs( r ) }` ) );
			intg.cache.forEach( ( r ) => lines.push( `- Cache purger ${ r.id } (${ r.label }) — ${ r.owner }` ) );
			( intg.spam || [] ).forEach( ( r ) => lines.push( `- Spam filter ${ r.id } (${ r.label }) — ${ r.owner }` ) );
			( intg.licenses || [] ).forEach( ( r ) => lines.push( `- License reader ${ r.id } (${ r.label }) — ${ r.owner }` ) );
			intg.builders.forEach( ( r ) => lines.push( `- Page builder ${ r.id } (${ r.label }) — ${ r.owner }` ) );
			intg.blockForms.forEach( ( r ) => lines.push( `- Block forms: ${ r.owner } — ${ r.count } block${ r.count === 1 ? '' : 's' }` ) );
			intg.listeners.forEach( ( l ) => lines.push( `- ${ l.hook }: ${ l.owners.join( ', ' ) }` ) );
		}
		return lines.join( '\n' );
	}

	function renderSystem() {
		const view = $( '#minn-view' );
		const s = state.cache.system;
		if ( ! s ) {
			view.innerHTML = '<div class="minn-loading">Reading the system…</div>';
			loadSystem().then( renderIfCurrent( 'system' ) ).catch( showErr );
			return;
		}
		const dot = ( st ) => `<span class="minn-sys-dot ${ esc( st ) }">${ icon( st === 'pass' ? 'check' : st === 'warn' ? 'warn' : 'x' ) }</span>`;
		const counts = { pass: 0, warn: 0, fail: 0 };
		s.checks.forEach( ( c ) => { counts[ c.status ] = ( counts[ c.status ] || 0 ) + 1; } );
		const groupCard = ( g ) => `
			<div class="minn-card minn-sys-card">
				<div class="minn-sys-card-head">${ icon( g.icon ) }<span>${ esc( g.title ) }</span></div>
				<div class="minn-sys-rows">
					${ g.rows.map( ( r ) => r.key === 'Cron' ? `
						<div class="minn-sys-row minn-sys-link" data-sysdetail="cron" role="button" tabindex="0" title="View every scheduled event">
							<span class="minn-sys-key">${ esc( r.key ) }</span>
							<span class="minn-sys-val mono" title="${ esc( r.value ) }">${ r.value ? esc( r.value ) : '—' } <span class="minn-sys-more">›</span></span>
						</div>` : `
						<div class="minn-sys-row">
							<span class="minn-sys-key">${ esc( r.key ) }</span>
							<span class="minn-sys-val mono" title="${ esc( r.value ) }">${ r.value ? esc( r.value ) : '—' }</span>
						</div>` ).join( '' ) }
				</div>
				${ g.tables && g.tables.length ? `
				<div class="minn-sys-tables">
					<div class="minn-sys-tables-head">Largest tables</div>
					${ g.tables.map( ( t ) => `
						<div class="minn-sys-trow">
							<span class="minn-sys-tname mono">${ esc( t.name ) }</span>
							<span class="minn-sys-tsize mono">${ esc( t.size ) }</span>
							<span class="minn-sys-trows">${ esc( t.rows ) } rows</span>
						</div>` ).join( '' ) }
				</div>` : '' }
				${ g.autoload ? `
				<div class="minn-sys-tables">
					<div class="minn-sys-tables-head minn-sys-link" data-sysdetail="autoload" role="button" tabindex="0" title="View every autoloaded option">Autoloaded options — ${ esc( String( g.autoload.count ) ) } options · ${ esc( g.autoload.size_human ) } on every request <span class="minn-sys-more">view all ›</span></div>
					${ g.autoload.top.map( ( t ) => `
						<div class="minn-sys-trow">
							<span class="minn-sys-tname mono">${ esc( t.name ) }</span>
							<span class="minn-sys-tsize mono">${ esc( t.size ) }</span>
						</div>` ).join( '' ) }
				</div>` : '' }
			</div>`;

		// Debug tools — wp-config toggles (only when wp-config is writable) and
		// a debug-log viewer (whenever the log exists). Absent when neither.
		const cfg = s.config;
		const log = cfg && cfg.log;
		const showDebug = cfg && ( cfg.editable || ( log && log.exists ) );
		const debugCard = showDebug ? `
			<div class="minn-card minn-sys-debug" id="minn-sys-debug">
				<div class="minn-sys-card-head">${ icon( 'bug' ) }<span>Debug tools</span>
					${ cfg.editable ? '<span class="minn-sys-debug-hint">writes wp-config.php</span>' : '' }
				</div>
				${ cfg.editable ? `
				<div class="minn-sys-toggles">
					${ cfg.constants.map( ( c ) => `
						<div class="minn-sys-toggle">
							<div class="minn-sys-toggle-text">
								<div class="minn-sys-toggle-label">${ esc( c.label ) } <span class="mono minn-sys-const">${ esc( c.name ) }</span></div>
								<div class="minn-sys-toggle-desc">${ esc( c.desc ) }</div>
							</div>
							${ c.locked
		? '<span class="minn-sys-locked">defined elsewhere</span>'
		: `<button class="minn-switch${ c.value ? ' on' : '' }" data-const="${ esc( c.name ) }" role="switch" aria-checked="${ c.value }" aria-label="Toggle ${ esc( c.label ) }"><span class="minn-switch-knob"></span></button>` }
						</div>` ).join( '' ) }
				</div>` : '' }
				${ log && log.exists ? `
				<button class="minn-sys-logrow" id="minn-view-log">
					${ icon( 'file' ) }
					<span class="mono minn-sys-logpath">${ esc( log.path ) }</span>
					<span class="minn-sys-logsize">${ esc( log.size_human ) }</span>
					<span class="minn-sys-logopen">View log →</span>
				</button>` : '' }
			</div>` : '';

		// Installed extensions manifest — plugins (active first), must-use, themes.
		const ext = s.extensions;
		const extItem = ( it, activeBadge ) => `
			<div class="minn-sys-ext-item${ it.active ? '' : ' off' }">
				<span class="minn-sys-ext-name">${ esc( it.name ) }${ it.parent ? ` <span class="minn-sys-ext-parent">child of ${ esc( it.parent ) }</span>` : '' }${ activeBadge && it.active ? ' <span class="minn-sys-ext-active">active</span>' : '' }</span>
				<span class="minn-sys-ext-ver mono">${ esc( it.version || '—' ) }</span>
			</div>`;
		const extSection = ( label, items, activeBadge ) => items.length ? `
			<div class="minn-sys-ext-section">
				<div class="minn-sys-ext-label">${ esc( label ) }</div>
				<div class="minn-sys-ext-grid">${ items.map( ( it ) => extItem( it, activeBadge ) ).join( '' ) }</div>
			</div>` : '';
		const extCard = ext ? `
			<div class="minn-card minn-sys-ext" id="minn-sys-extensions">
				<div class="minn-sys-card-head">${ icon( 'plug' ) }<span>Extensions</span>
					<span class="minn-sys-debug-hint">${ ext.active_plugins } active · ${ ext.plugins.length } plugins · ${ ext.themes.length } themes</span>
				</div>
				${ extSection( 'Plugins', ext.plugins, false ) }
				${ extSection( 'Must-use', ext.mu_plugins, false ) }
				${ extSection( 'Themes', ext.themes, true ) }
			</div>` : '';

		// Licenses — read-only visibility over every paid component's stored
		// license state. Rows come server-classified (adapters/licenses.php);
		// nothing here can activate, retry or otherwise touch a vendor.
		const lic = s.licenses;
		const licLabel = { valid: 'Valid', expired: 'Expired', invalid: 'Invalid', missing: 'No license', unknown: 'Unknown' };
		const licRow = ( it ) => {
			const meta = [
				// With a Turn on button right below, the long hint is noise.
				it.off ? ( it.turnOn ? 'not active' : `not active; activate the ${ it.kind === 'theme' ? 'theme' : 'plugin' } to manage its license` ) : '',
				it.note,
				it.expires === 'lifetime' ? 'lifetime license' : ( it.expires ? ( it.state === 'expired' ? 'expired ' : 'renews ' ) + it.expires : '' ),
				it.stale ? 'may be stale' : '',
			].filter( Boolean ).join( ' · ' );
			// Phase-1 controls, only for actions the provider's ACTIVE vendor
			// code declared. Paste-to-activate: the key rides one request and
			// is never stored or echoed back.
			const can = it.can || [];
			const controls = [
				// Inactive component: one small "turn it back on" control. The
				// vendor's license actions only exist while its code is loaded,
				// so activating is what reveals them (the card re-renders from
				// a fresh /system fetch afterwards).
				it.off && it.turnOn
					? `<button data-lic="turnon" data-component="${ esc( it.turnOn ) }" data-name="${ esc( it.name ) }" title="${ it.turnOn.startsWith( 'theme:' ) ? 'Switch the site to this theme' : 'Activate this plugin' }">${ icon( 'power' ) } Turn on</button>` : '',
				can.includes( 'activate' ) && it.state !== 'valid'
					? `<button data-lic="activate" data-provider="${ esc( it.source ) }" data-secret="${ esc( it.secret || 'License key' ) }"${ it.secretFields ? ` data-fields="${ esc( JSON.stringify( it.secretFields ) ) }"` : '' }>Activate…</button>` : '',
				! can.includes( 'activate' ) && it.activateUrl && it.state !== 'valid'
					? `<button data-lic="href" data-href="${ esc( it.activateUrl ) }">Activate ↗</button>` : '',
				can.includes( 'deactivate' ) && it.state === 'valid'
					? `<button data-lic="deactivate" data-provider="${ esc( it.source ) }" data-name="${ esc( it.name ) }">Deactivate</button>` : '',
				can.includes( 'verify' ) && it.key
					? `<button data-lic="verify" data-provider="${ esc( it.source ) }">Re-verify</button>` : '',
			].filter( Boolean ).join( '' );
			return `
			<div class="minn-sys-ext-item minn-lic-item${ it.off ? ' off' : '' }">
				<span class="minn-sys-ext-name">${ esc( it.name ) }${ it.kind === 'theme' ? ' <span class="minn-sys-ext-parent">theme</span>' : '' }
					${ meta ? `<div class="minn-sys-lic-meta">${ esc( meta ) }</div>` : '' }
					${ controls ? `<div class="minn-lic-actions">${ controls }</div>` : '' }
				</span>
				<span class="minn-lic-pill ${ esc( it.state ) }">${ licLabel[ it.state ] || esc( it.state ) }</span>
			</div>`;
		};
		const licCard = lic && lic.items.length ? `
			<div class="minn-card minn-sys-ext" id="minn-sys-licenses">
				<div class="minn-sys-card-head">${ icon( 'key' ) }<span>Licenses</span>
					<span class="minn-sys-debug-hint">${ lic.items.length } paid component${ lic.items.length === 1 ? '' : 's' } · states read from stored state, never the network</span>
				</div>
				<div class="minn-sys-ext-section">
					<div class="minn-sys-ext-grid">${ lic.items.map( licRow ).join( '' ) }</div>
				</div>
				<div class="minn-sys-lic-foot">Each state is the vendor's own last-recorded check, not a live lookup. Activate and deactivate run through the vendor's own code; a pasted key is used once and never stored.</div>
			</div>` : '';

		// Integrations — the live registry of everything hooked into Minn:
		// each entry attributed to the plugin that registered it, with
		// descriptor-contract problems flagged (the author feedback loop —
		// a malformed descriptor fails silently in the app, loudly here).
		const intg = s.integrations;
		const intProblems = intg
			? [ ...intg.surfaces, ...intg.panels, ...intg.designs ].reduce( ( n, r ) => n + ( r.problems || [] ).length, 0 )
			: 0;
		const intRow = ( r, meta ) => `
			<div class="minn-sys-ext-item">
				<span class="minn-sys-ext-name">${ esc( r.label || r.id ) } <span class="minn-sys-ext-parent mono">${ esc( r.id ) }</span>${ meta ? ` <span class="minn-sys-ext-parent">${ esc( meta ) }</span>` : '' }
					${ ( r.problems || [] ).map( ( p ) => `<div class="minn-sys-int-problem">${ icon( 'warn' ) }${ esc( p ) }</div>` ).join( '' ) }
				</span>
				<span class="minn-sys-ext-ver">${ esc( r.owner || '' ) }</span>
			</div>`;
		const intSection = ( label, rows, metaFn ) => rows && rows.length ? `
			<div class="minn-sys-ext-section">
				<div class="minn-sys-ext-label">${ esc( label ) }</div>
				<div class="minn-sys-ext-grid">${ rows.map( ( r ) => intRow( r, metaFn ? metaFn( r ) : '' ) ).join( '' ) }</div>
			</div>` : '';
		const intPlain = ( label, rows ) => rows && rows.length ? `
			<div class="minn-sys-ext-section">
				<div class="minn-sys-ext-label">${ esc( label ) }</div>
				<div class="minn-sys-ext-grid">${ rows.map( ( r ) => `
					<div class="minn-sys-ext-item">
						<span class="minn-sys-ext-name${ r.mono ? ' mono' : '' }">${ esc( r.a ) }</span>
						<span class="minn-sys-ext-ver">${ esc( r.b ) }</span>
					</div>` ).join( '' ) }</div>
			</div>` : '';
		const intCard = intg ? `
			<div class="minn-card minn-sys-ext" id="minn-sys-integrations">
				<div class="minn-sys-card-head">${ icon( 'grid' ) }<span>Integrations</span>
					<span class="minn-sys-debug-hint">${ intg.surfaces.length } surfaces · ${ intg.panels.length } panels · ${ intg.designs.length } design sources${ intProblems ? ` · <span class="minn-sys-int-warn">${ intProblems } problem${ intProblems === 1 ? '' : 's' }</span>` : '' }</span>
				</div>
				${ intSection( 'Surfaces', intg.surfaces, ( r ) => [ r.family ? 'family: ' + r.family : '', 'cap: ' + r.cap ].filter( Boolean ).join( ' · ' ) ) }
				${ intSection( 'Editor panels', intg.panels, ( r ) => 'cap: ' + r.cap ) }
				${ intSection( 'Design sources', intg.designs ) }
				${ intSection( 'Cache purgers', intg.cache ) }
				${ intSection( 'Spam filters', intg.spam || [] ) }
				${ intSection( 'License readers', intg.licenses || [] ) }
				${ intSection( 'Page builders', intg.builders ) }
				${ intPlain( 'Block inspector forms', intg.blockForms.map( ( r ) => ( { a: r.owner, b: r.count + ' block' + ( r.count === 1 ? '' : 's' ) } ) ) ) }
				${ intPlain( 'Hook listeners', intg.listeners.map( ( l ) => ( { a: l.hook, b: l.owners.join( ', ' ), mono: true } ) ) ) }
			</div>` : '';

		view.innerHTML = `
			<div class="minn-sys-topbar">
				<div class="minn-sys-summary">
					${ counts.fail ? `<span class="minn-sys-pill fail">${ counts.fail } failing</span>` : '' }
					${ counts.warn ? `<span class="minn-sys-pill warn">${ counts.warn } to review</span>` : '' }
					<span class="minn-sys-pill pass">${ counts.pass } healthy</span>
				</div>
				<button class="minn-btn-soft" id="minn-sys-copy">${ icon( 'clipboard' ) } Copy report</button>
			</div>
			<div class="minn-sys-jump" id="minn-sys-jump">
				${ [
					[ 'Health', 'minn-sys-sec-health' ],
					licCard ? [ 'Licenses', 'minn-sys-licenses' ] : null,
					debugCard ? [ 'Debug', 'minn-sys-debug' ] : null,
					[ 'System', 'minn-sys-grid' ],
					extCard ? [ 'Extensions', 'minn-sys-extensions' ] : null,
					intCard ? [ 'Integrations', 'minn-sys-integrations' ] : null,
				].filter( Boolean ).map( ( [ label, id ] ) => `<button data-jump="${ id }">${ label }</button>` ).join( '' ) }
			</div>
			<div class="minn-sys-checks" id="minn-sys-sec-health">
				${ s.checks.map( ( c ) => `
					<div class="minn-sys-check ${ esc( c.status ) }">
						${ dot( c.status ) }
						<div class="minn-sys-check-body">
							<div class="minn-sys-check-label">${ esc( c.label ) }</div>
							<div class="minn-sys-check-detail">${ esc( c.detail ) }</div>
						</div>
					</div>` ).join( '' ) }
			</div>
			${ licCard }
			${ debugCard }
			<div class="minn-sys-grid" id="minn-sys-grid">
				${ s.groups.map( groupCard ).join( '' ) }
				${ B.caps.settings ? `
				<div class="minn-card minn-sys-card" id="minn-sys-tools">
					<div class="minn-sys-card-head">${ icon( 'wrench' ) }<span>Tools</span>
						<span class="minn-sys-debug-hint">one-shot jobs, in wp-admin</span>
					</div>
					<div class="minn-sys-rows">
						${ [
		[ 'Site Health', 'site-health.php', "Core's full test suite and status" ],
		[ 'Export content', 'export.php', 'Download a WXR of posts, pages and media' ],
		[ 'Import content', 'import.php', 'Bring content in from another site' ],
		[ 'Export personal data', 'export-personal-data.php', 'Answer a GDPR data request' ],
		[ 'Erase personal data', 'erase-personal-data.php', 'Handle a GDPR erasure request' ],
	].map( ( [ label, path, hint ] ) => `
						<a class="minn-sys-row minn-sys-tool" href="${ esc( B.site.adminUrl + path ) }" target="_blank" rel="noopener">
							<span class="minn-sys-key">${ esc( label ) } ↗</span>
							<span class="minn-sys-val">${ esc( hint ) }</span>
						</a>` ).join( '' ) }
					</div>
				</div>` : '' }
			</div>
			${ extCard }
			${ intCard }
			<div class="minn-sys-foot">Generated ${ esc( timeAgo( s.generated ) ) }</div>`;

		// Sticky jump bar: smooth-scroll to a section, scroll-spy highlights
		// the one under the bar. The handler lives on the scroller (which
		// outlives view swaps), so replace any previous one instead of
		// stacking listeners across re-renders.
		const jumpBar = $( '#minn-sys-jump', view );
		const jumpScroller = $( '.minn-scroll' );
		if ( jumpBar && jumpScroller ) {
			const jumpBtns = $$( '[data-jump]', jumpBar );
			jumpBtns.forEach( ( b ) => b.addEventListener( 'click', () => {
				const el = document.getElementById( b.dataset.jump );
				if ( el ) jumpScroller.scrollTo( { top: Math.max( 0, el.offsetTop - jumpBar.offsetHeight - 34 ), behavior: 'smooth' } );
			} ) );
			let spyRaf = 0;
			const spy = () => {
				spyRaf = 0;
				if ( ! document.getElementById( 'minn-sys-jump' ) ) return; // left the page
				const line = jumpScroller.scrollTop + jumpBar.offsetHeight + 60;
				let cur = jumpBtns[ 0 ];
				jumpBtns.forEach( ( b ) => {
					const el = document.getElementById( b.dataset.jump );
					if ( el && el.offsetTop <= line ) cur = b;
				} );
				jumpBtns.forEach( ( b ) => b.classList.toggle( 'active', b === cur ) );
			};
			if ( jumpScroller._minnSysSpy ) jumpScroller.removeEventListener( 'scroll', jumpScroller._minnSysSpy );
			jumpScroller._minnSysSpy = () => {
				if ( ! spyRaf ) spyRaf = requestAnimationFrame( spy );
			};
			jumpScroller.addEventListener( 'scroll', jumpScroller._minnSysSpy, { passive: true } );
			spy();
		}

		$$( '[data-const]', view ).forEach( ( btn ) => btn.addEventListener( 'click', async () => {
			const name = btn.dataset.const;
			const next = btn.getAttribute( 'aria-checked' ) !== 'true';
			btn.disabled = true;
			try {
				await api( 'minn-admin/v1/system/config', { method: 'POST', body: JSON.stringify( { constant: name, value: next } ) } );
				btn.classList.toggle( 'on', next );
				btn.setAttribute( 'aria-checked', String( next ) );
				const c = cfg.constants.find( ( x ) => x.name === name );
				if ( c ) c.value = next;
				state.cache.system = null; // re-read fresh next visit
				toast( `${ name } ${ next ? 'enabled' : 'disabled' } — applies on the next page load` );
			} catch ( e ) {
				toast( e.message, true );
			}
			btn.disabled = false;
		} ) );

		const viewLog = $( '#minn-view-log', view );
		if ( viewLog ) viewLog.addEventListener( 'click', openDebugLog );

		// Autoload + Cron summary rows open their full-detail modals.
		$$( '[data-sysdetail]', view ).forEach( ( el ) =>
			el.addEventListener( 'click', () => openSysDetail( el.dataset.sysdetail ) ) );

		// License actions (Phase 1): activate swaps in an inline paste field;
		// the secret rides one request and is never stored or echoed back.
		// Failures never auto-retry (a retried activation can burn a paid
		// seat), and the whole card repaints from fresh server state after.
		const licRun = async ( provider, action, payload, btn ) => {
			const label = btn.textContent;
			btn.disabled = true;
			btn.textContent = action === 'activate' ? 'Activating…' : action === 'deactivate' ? 'Deactivating…' : 'Checking…';
			// A failed activate must NOT re-render: the paste field stays put
			// (key selected for a quick retype) and nothing moves. Re-renders
			// happen only when server state changed, with the fresh /system
			// loaded FIRST so the view never collapses to a loading state and
			// clamps the scroller to the top (Austin's bad-key repro; same
			// scrollTop-restore rule as renderExtensions).
			const keepForm = () => {
				btn.disabled = false;
				btn.textContent = label;
				const input = btn.closest( '.minn-lic-actions' )?.querySelector( '.minn-lic-key' );
				if ( input ) {
					input.focus( { preventScroll: true } );
					input.select();
				}
			};
			try {
				const res = await api( 'minn-admin/v1/licenses/action', {
					method: 'POST',
					body: JSON.stringify( { provider, action, ...( payload || {} ) } ),
				} );
				if ( res.ok ) {
					toast( action === 'activate' ? 'License activated' : action === 'deactivate' ? 'License deactivated' : 'License re-verified' );
				} else if ( res.code === 'site_limit' ) {
					toast( 'No activations left on this license. Free a seat with the vendor first — Minn never retries an activation.', true );
				} else {
					toast( res.message || ( action === 'activate' ? 'Activation failed — check the key' : 'The vendor reported a problem' ), true );
				}
				if ( 'activate' === action && ! res.ok ) {
					keepForm();
					return;
				}
				const scroller = $( '.minn-scroll' );
				const keepTop = scroller ? scroller.scrollTop : 0;
				state.cache.system = null; // rows + health check are server-derived
				await loadSystem();
				if ( state.route !== 'system' ) return;
				renderSystem();
				const sc = $( '.minn-scroll' );
				if ( sc ) sc.scrollTop = keepTop;
			} catch ( e ) {
				toast( e.message, true );
				keepForm();
			}
		};
		// Turn an inactive licensed component back on from its row. Plugins
		// flip via core's plugins endpoint; a theme SWITCHES the site's active
		// theme, so it confirms first. The fresh /system fetch afterwards is
		// what reveals the vendor's license controls (action callables only
		// attach while its code is loaded).
		const licTurnOn = async ( btn ) => {
			const component = btn.dataset.component;
			const orig = btn.innerHTML;
			btn.disabled = true;
			btn.textContent = 'Turning on…';
			try {
				if ( component.startsWith( 'theme:' ) ) {
					await api( 'minn-admin/v1/themes/activate', { method: 'POST', body: JSON.stringify( { stylesheet: component.slice( 6 ) } ) } );
				} else {
					await api( 'wp/v2/plugins/' + component.replace( /\.php$/, '' ), { method: 'PUT', body: JSON.stringify( { status: 'active' } ) } );
				}
				toast( `${ btn.dataset.name } turned on` );
				await refreshAfterPluginChange();
				const scroller = $( '.minn-scroll' );
				const keepTop = scroller ? scroller.scrollTop : 0;
				state.cache.system = null;
				await loadSystem();
				if ( state.route !== 'system' ) return;
				renderSystem();
				const sc = $( '.minn-scroll' );
				if ( sc ) sc.scrollTop = keepTop;
			} catch ( e ) {
				toast( e.message, true );
				btn.disabled = false;
				btn.innerHTML = orig;
			}
		};
		$$( '[data-lic]', view ).forEach( ( btn ) => btn.addEventListener( 'click', () => {
			const action = btn.dataset.lic;
			const provider = btn.dataset.provider;
			if ( 'turnon' === action ) {
				if ( btn.dataset.component.startsWith( 'theme:' )
					&& ! confirm( `Activate the ${ btn.dataset.name } theme? It becomes the site's active theme and the current theme turns off.` ) ) return;
				licTurnOn( btn );
				return;
			}
			if ( 'href' === action ) {
				window.open( btn.dataset.href, '_blank', 'noopener' );
				return;
			}
			if ( 'deactivate' === action ) {
				if ( ! confirm( `Deactivate the ${ btn.dataset.name } license on this site? The seat frees up, and the plugin may stop receiving updates until a license is activated again.` ) ) return;
				licRun( provider, 'deactivate', null, btn );
				return;
			}
			if ( 'verify' === action ) {
				licRun( provider, 'verify', null, btn );
				return;
			}
			// Multi-secret vendors (Divi's username + API key) declare their
			// fields; everyone else gets the single paste field. Plain text,
			// not type=password: a license key isn't a credential, the value
			// is used once and never stored, and the password type summons
			// 1Password/LastPass/Bitwarden over the field (Austin's report;
			// the data-*-ignore attributes are each manager's documented
			// opt-out for non-login fields).
			const fields = btn.dataset.fields ? JSON.parse( btn.dataset.fields ) : null;
			const wrap = btn.closest( '.minn-lic-actions' );
			wrap.innerHTML = `
				${ fields
		? fields.map( ( f ) => `<input type="text" class="minn-lic-key" data-sid="${ esc( f.id ) }" placeholder="${ esc( f.label ) }" autocomplete="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-bwignore="true">` ).join( '' )
		: `<input type="text" class="minn-lic-key" placeholder="${ esc( btn.dataset.secret ) }" autocomplete="off" spellcheck="false" data-1p-ignore data-lpignore="true" data-bwignore="true">` }
				<button data-lic-go>Activate</button>
				<button data-lic-cancel>Cancel</button>`;
			const inputs = $$( '.minn-lic-key', wrap );
			inputs[ 0 ].focus();
			$( '[data-lic-go]', wrap ).addEventListener( 'click', () => {
				let payload;
				if ( fields ) {
					const secrets = {};
					for ( const i of inputs ) {
						if ( ! i.value.trim() ) {
							i.focus();
							return;
						}
						secrets[ i.dataset.sid ] = i.value.trim();
					}
					payload = { secrets };
				} else {
					if ( ! inputs[ 0 ].value.trim() ) {
						inputs[ 0 ].focus();
						return;
					}
					payload = { secret: inputs[ 0 ].value.trim() };
				}
				licRun( provider, 'activate', payload, $( '[data-lic-go]', wrap ) );
			} );
			inputs.forEach( ( i ) => i.addEventListener( 'keydown', ( e ) => {
				if ( 'Enter' === e.key ) $( '[data-lic-go]', wrap ).click();
			} ) );
			$( '[data-lic-cancel]', wrap ).addEventListener( 'click', () => renderSystem() );
		} ) );

		$( '#minn-sys-copy', view ).addEventListener( 'click', async () => {
			const text = systemReportText( s );
			try {
				await navigator.clipboard.writeText( text );
				toast( 'System report copied' );
			} catch ( e ) {
				// execCommand fallback for non-secure contexts.
				const ta = document.createElement( 'textarea' );
				ta.value = text;
				document.body.appendChild( ta );
				ta.select();
				try { document.execCommand( 'copy' ); toast( 'System report copied' ); } catch ( e2 ) { toast( 'Copy failed', true ); }
				ta.remove();
			}
		} );
	}

	// Autoload / cron detail modal: the System page's summary rows expand to
	// the full picture (every autoloaded option by size; every scheduled
	// event with its next run and recurrence).
	function openSysDetail( kind ) {
		state.modal = { type: 'sys-detail', kind, data: null };
		renderOverlays();
		api( 'minn-admin/v1/system/' + ( kind === 'cron' ? 'cron' : 'autoload' ) )
			.then( ( data ) => {
				if ( state.modal && state.modal.type === 'sys-detail' && state.modal.kind === kind ) {
					state.modal.data = data;
					renderOverlays();
				}
			} )
			.catch( ( e ) => { toast( e.message, true ); closeModal(); } );
	}

	function renderSysDetailModal( m ) {
		const d = m.data;
		const isAuto = m.kind === 'autoload';
		let body = '<div class="minn-loading">Loading…</div>';
		if ( d && isAuto ) {
			body = `
			<div class="minn-sysd">
				<div class="minn-sysd-sub">${ esc( String( d.count ) ) } autoloaded options load on every request (${ esc( d.size_human ) } total)${ d.count > d.shown ? `; showing the ${ esc( String( d.shown ) ) } largest` : '' }.</div>
				<div class="minn-sysd-row head"><span>Option</span><span>Size</span><span>Autoload</span></div>
				${ d.items.map( ( it ) => `
				<div class="minn-sysd-row">
					<span class="mono minn-cell-clip" title="${ esc( it.name ) }">${ esc( it.name ) }</span>
					<span class="mono">${ esc( it.sizeh ) }</span>
					<span class="minn-sysd-dim">${ esc( it.autoload ) }</span>
				</div>` ).join( '' ) }
			</div>`;
		} else if ( d ) {
			body = `
			<div class="minn-sysd">
				<div class="minn-sysd-sub">${ esc( String( d.items.length ) ) } scheduled event${ d.items.length === 1 ? '' : 's' }.${ d.disabled ? ' WP-Cron is disabled (DISABLE_WP_CRON); a system cron is expected to run these.' : '' }</div>
				<div class="minn-sysd-row head"><span>Hook</span><span>Next run</span><span>Recurrence</span></div>
				${ d.items.map( ( it ) => `
				<div class="minn-sysd-row">
					<span class="mono minn-cell-clip" title="${ esc( it.hook ) }">${ esc( it.hook ) }</span>
					<span class="${ it.overdue ? 'minn-sysd-overdue' : '' }">${ it.overdue ? 'overdue · ' : '' }${ esc( timeAgo( new Date( it.next * 1000 ).toISOString() ) ) }</span>
					<span class="minn-sysd-dim">${ esc( it.recurrence ) }</span>
				</div>` ).join( '' ) }
			</div>`;
		}
		return `
		<div class="minn-modal-overlay" id="minn-modal-overlay">
			<div class="minn-modal wide">
				<div class="minn-modal-head">
					<div class="minn-modal-title">${ isAuto ? 'Autoloaded options' : 'Scheduled cron events' }</div>
					<button class="minn-x-btn" id="minn-modal-close">×</button>
				</div>
				${ body }
			</div>
		</div>`;
	}

	// Full-screen debug-log viewer: the tail of the log in a scrollable
	// monospace pane, with Refresh / Copy / Clear. Fetches on open and reload.
	function openDebugLog() {
		const overlay = document.createElement( 'div' );
		overlay.className = 'minn-modal-overlay minn-log-overlay';
		overlay.innerHTML = `
			<div class="minn-modal minn-log-modal">
				<div class="minn-modal-head">
					<span class="minn-modal-title">${ icon( 'file' ) } Debug log <span class="minn-log-meta" id="minn-log-meta"></span></span>
					<div class="minn-log-actions">
						<button class="minn-btn-soft" id="minn-log-refresh" title="Refresh">${ icon( 'refresh' ) }</button>
						<button class="minn-btn-soft" id="minn-log-copy" title="Copy all">${ icon( 'clipboard' ) }</button>
						<button class="minn-btn-soft danger" id="minn-log-clear">Clear</button>
						<button class="minn-x-btn" data-close type="button">×</button>
					</div>
				</div>
				<pre class="minn-log-body" id="minn-log-body"><span class="minn-log-loading">Reading log…</span></pre>
			</div>`;
		document.body.appendChild( overlay );
		let raw = '';

		const close = () => { overlay.remove(); document.removeEventListener( 'keydown', onKey ); };
		const onKey = ( e ) => { if ( e.key === 'Escape' ) close(); };
		document.addEventListener( 'keydown', onKey );
		overlay.addEventListener( 'mousedown', ( e ) => { if ( e.target === overlay ) close(); } );
		overlay.querySelector( '[data-close]' ).addEventListener( 'click', close );

		const load = async () => {
			const body = $( '#minn-log-body', overlay );
			const meta = $( '#minn-log-meta', overlay );
			try {
				const r = await api( 'minn-admin/v1/system/debug-log' );
				raw = r.content || '';
				meta.textContent = r.exists ? `${ r.path } · ${ r.size_human }${ r.truncated ? ' · showing last 256 KB' : '' }` : r.path + ' · empty';
				if ( ! raw.trim() ) {
					body.innerHTML = '<span class="minn-log-loading">The log is empty.</span>';
				} else {
					body.textContent = raw;
					body.scrollTop = body.scrollHeight; // newest at the bottom
				}
			} catch ( e ) {
				body.innerHTML = `<span class="minn-log-loading">Couldn’t read the log: ${ esc( e.message ) }</span>`;
			}
		};

		$( '#minn-log-refresh', overlay ).addEventListener( 'click', load );
		$( '#minn-log-copy', overlay ).addEventListener( 'click', async () => {
			try { await navigator.clipboard.writeText( raw ); toast( 'Log copied' ); } catch ( e ) { toast( 'Copy failed', true ); }
		} );
		$( '#minn-log-clear', overlay ).addEventListener( 'click', async () => {
			if ( ! confirm( 'Empty the debug log? This can’t be undone.' ) ) return;
			try {
				await api( 'minn-admin/v1/system/debug-log', { method: 'DELETE' } );
				state.cache.system = null; // size changed
				toast( 'Debug log cleared' );
				load();
			} catch ( e ) {
				toast( e.message, true );
			}
		} );
		load();
	}

	/* ===== Settings ===== */

	// Grouped by the job you're doing, not WordPress's historical tabs:
	// identity/locale (Site), who-can-see-and-join (Visibility), the front
	// page (Homepage), content defaults + URLs (Content), and everything
	// about comments incl. spam (Comments).
	const SETTINGS_SECTIONS = [ 'Site', 'Visibility', 'Homepage', 'Design', 'Content', 'Comments' ];
	// Design (Additional CSS) needs core's edit_css — hidden, not disabled,
	// for everyone else (matching how the Customizer hides the panel).
	const settingsSections = () => SETTINGS_SECTIONS.filter( ( s ) => s !== 'Design' || B.caps.editCss );
	const POST_FORMATS = [ 'standard', 'aside', 'chat', 'gallery', 'link', 'image', 'quote', 'status', 'video', 'audio' ];
	const PERMALINK_PRESETS = [
		[ '', 'Plain' ],
		[ '/%year%/%monthnum%/%day%/%postname%/', 'Day and name' ],
		[ '/%year%/%monthnum%/%postname%/', 'Month and name' ],
		[ '/archives/%post_id%', 'Numeric' ],
		[ '/%postname%/', 'Post name' ],
	];

	async function loadSettings() {
		const [ values, categories, pages, permalinks, spam, customCss ] = await Promise.all( [
			api( 'wp/v2/settings' ),
			api( 'wp/v2/categories?per_page=100&_fields=id,name' ).catch( () => [] ),
			api( 'wp/v2/pages?per_page=100&status=publish&orderby=title&order=asc&_fields=id,title' ).catch( () => [] ),
			api( 'minn-admin/v1/permalinks' ).catch( () => null ),
			api( 'minn-admin/v1/spam' ).catch( () => null ),
			B.caps.editCss ? api( 'minn-admin/v1/custom-css' ).catch( () => null ) : Promise.resolve( null ),
		] );
		const siteIcon = values.site_icon
			? await api( `wp/v2/media/${ values.site_icon }?_fields=id,source_url,media_details` )
				.then( ( m ) => ( { url: ( m.media_details && m.media_details.sizes && m.media_details.sizes.thumbnail && m.media_details.sizes.thumbnail.source_url ) || m.source_url } ) )
				.catch( () => null )
			: null;
		state.cache.settings = { values, categories, pages, permalinks, spam, siteIcon, customCss };
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
		// An in-flow absolute panel is clipped by any scroll/overflow ancestor
		// (a modal's overflow-y:auto, or .minn-shell's overflow:hidden) —
		// absolutely-positioned panels add no scroll height, so options past
		// the ancestor's edge become unreachable (the modal role dropdown lost
		// its lower roles). Only WHEN a panel would actually clip do we anchor
		// it fixed to the viewport (flipping above if there's no room below);
		// panels that fit keep the default in-flow behavior untouched, so the
		// surface switcher's right-aligned panel and every other combobox are
		// unchanged.
		const nearestClip = () => {
			let n = wrap.parentElement;
			while ( n && n !== document.body ) {
				const cs = getComputedStyle( n );
				if ( [ 'auto', 'scroll', 'hidden' ].includes( cs.overflowY ) || [ 'auto', 'scroll', 'hidden' ].includes( cs.overflowX ) ) return n;
				n = n.parentElement;
			}
			return null;
		};
		let repositionBound = null;
		let escaped = false;
		const resetPanelStyle = () => {
			escaped = false;
			[ 'position', 'left', 'right', 'width', 'top', 'bottom', 'maxHeight' ].forEach( ( p ) => { panel.style[ p ] = ''; } );
		};
		const placeFixed = () => {
			const r = input.getBoundingClientRect();
			const MAXH = 260;
			const below = window.innerHeight - r.bottom - 10;
			const above = r.top - 10;
			panel.style.position = 'fixed';
			panel.style.left = r.left + 'px';
			panel.style.width = r.width + 'px';
			panel.style.right = 'auto';
			if ( below < 160 && above > below ) {
				panel.style.top = 'auto';
				panel.style.bottom = ( window.innerHeight - r.top + 4 ) + 'px';
				panel.style.maxHeight = Math.min( MAXH, above ) + 'px';
			} else {
				panel.style.bottom = 'auto';
				panel.style.top = ( r.bottom + 4 ) + 'px';
				panel.style.maxHeight = Math.min( MAXH, below ) + 'px';
			}
		};
		// Measure the natural (in-flow) panel; escape to fixed only if it
		// spills past its nearest clipping ancestor.
		const placePanel = () => {
			if ( ! escaped ) resetPanelStyle();
			const anc = nearestClip();
			if ( ! anc ) return;
			const pr = panel.getBoundingClientRect();
			const ar = anc.getBoundingClientRect();
			if ( escaped || pr.bottom > ar.bottom - 2 || pr.top < ar.top + 2 ) {
				escaped = true;
				placeFixed();
			}
		};
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
			placePanel();
			if ( ! repositionBound ) {
				// Keep the panel anchored while its input scrolls; capture:true
				// so a modal's own scroll (not just window) reanchors it. Only
				// matters once escaped, but cheap to leave attached while open.
				repositionBound = () => { if ( escaped ) placeFixed(); };
				window.addEventListener( 'scroll', repositionBound, true );
				window.addEventListener( 'resize', repositionBound );
			}
			input.setAttribute( 'aria-expanded', 'true' );
			const cur = $( '.minn-ac-item.current', panel );
			if ( cur ) cur.scrollIntoView( { block: 'nearest' } );
		};
		const close = () => {
			panel.hidden = true;
			idx = -1;
			input.setAttribute( 'aria-expanded', 'false' );
			if ( repositionBound ) {
				window.removeEventListener( 'scroll', repositionBound, true );
				window.removeEventListener( 'resize', repositionBound );
				repositionBound = null;
			}
			resetPanelStyle();
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
		// Strict displays are labels, not free text — select on focus so the
		// first keystroke REPLACES the label (typing into "All roles" must
		// filter on "e", not on "All rolese").
		input.addEventListener( 'focus', () => { render( true ); if ( opts.strict ) input.select(); } );
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
		// Permalink fields live on the Content tab now, but they save through
		// their own endpoint (rewrite-rule rebuild), so they carry data-permakey
		// — the wp/v2/settings save (which sweeps data-key) must not pick them up.
		const permaText = ( key, label, value, mono ) => `
			<div>
				<div class="minn-field-label">${ label }</div>
				<input class="minn-input${ mono ? ' mono' : '' }" data-permakey="${ key }" value="${ esc( value == null ? '' : value ) }">
			</div>`;
		const permaSelect = ( key, label, options, current ) => `
			<div>
				<div class="minn-field-label">${ label }</div>
				<select class="minn-input" data-permakey="${ key }">
					${ options.map( ( [ v, l ] ) => `<option value="${ esc( v ) }"${ String( v ) === String( current ) ? ' selected' : '' }>${ esc( l ) }</option>` ).join( '' ) }
				</select>
			</div>`;
		const subhead = ( label ) => `<div class="minn-fields-sub">${ label }</div>`;
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
			case 'Site': return {
				sub: 'Your site’s identity, locale and admin.',
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
					+ select( 'start_of_week', 'Week starts on', DAYS.map( ( d, i ) => [ i, d ] ), s.start_of_week ),
				toggles: [
					{ id: 'minn_admin_default', label: 'Minn is the default admin', desc: 'After signing in, land here instead of wp-admin (deep links still work; classic stays available).', on: !! s.minn_admin_default },
				].map( toggle ).join( '' ),
			};
			case 'Visibility': {
				// Everything about who can see, index and join the site — the
				// group WordPress scatters across Reading and General. The
				// toggles above only control what Minn owns; when a PLUGIN is
				// also hiding the site (WooCommerce coming soon, SeedProd, …)
				// list it here so the section tells the whole truth.
				const v = visState();
				const others = v && ! v.public ? ( v.providers || [] ).filter( ( p ) => ! p.minn ) : [];
				const posture = others.length ? subhead( 'Also limiting visibility' ) + others.map( ( p ) => `
					<div class="minn-toggle-row">
						<div class="minn-toggle-info">
							<div class="minn-toggle-label">${ esc( p.name ) }</div>
							<div class="minn-toggle-desc">${ esc( p.note || ( 'password' === p.kind ? 'The site is behind a password.' : 'Visitors see a maintenance or coming-soon page.' ) ) }</div>
						</div>
						${ p.url ? `<a class="minn-btn-soft" href="${ esc( p.url ) }" target="_blank" rel="noopener">Open ↗</a>` : '' }
					</div>` ).join( '' ) : '';
				return {
					sub: 'Who can see, index and join your site.',
					fields: roleOptions.length ? combo( 'default_role', 'New user default role', roleOptions, s.default_role || 'subscriber' ) : '',
					toggles: [
						{ id: 'blog_public', label: 'Search engine visibility', desc: 'Allow search engines to index this site.', on: !! s.blog_public },
						{ id: 'minn_admin_maintenance', label: 'Maintenance mode', desc: 'Show a coming-soon page to visitors instead of the site.', on: !! s.minn_admin_maintenance },
						{ id: 'users_can_register', label: 'Membership', desc: 'Anyone can register an account (with the default role above).', on: !! s.users_can_register },
					].map( toggle ).join( '' ),
					after: posture,
				};
			}
			case 'Homepage': return {
				sub: 'What visitors land on, and how much shows.',
				fields: select( 'show_on_front', 'Your homepage displays', [ [ 'posts', 'Latest posts' ], [ 'page', 'A static page' ] ], s.show_on_front )
					+ ( s.show_on_front === 'page' ? combo( 'page_on_front', 'Homepage', pageOptions, s.page_on_front ) + combo( 'page_for_posts', 'Posts page', pageOptions, s.page_for_posts ) : '' )
					+ text( 'posts_per_page', 'Blog pages show at most', s.posts_per_page ),
				toggles: '',
			};
			case 'Design': {
				// Core's per-theme custom_css post — the Customizer's
				// "Additional CSS", the last daily Customizer gap.
				const cc = cache.customCss;
				return {
					sub: 'Site-wide CSS on top of the active theme.',
					fields: cc ? `
						<div>
							<div class="minn-field-label">Additional CSS</div>
							<textarea class="minn-input mono minn-css-editor" id="minn-custom-css" rows="18" spellcheck="false" placeholder="/* CSS added here loads on every page, after the theme's own stylesheets. */">${ esc( cc.css || '' ) }</textarea>
							<div class="minn-toggle-desc">The same stylesheet the Customizer's "Additional CSS" edits, loaded after the theme's own styles on every page. It belongs to the active theme (${ esc( cc.theme || '' ) }); switching themes keeps a separate one per theme.</div>
						</div>`
						: `<div class="minn-editor-locked-note">Custom CSS couldn't be loaded. <a href="${ esc( B.site.adminUrl ) }customize.php" target="_blank" rel="noopener">Open the Customizer ↗</a></div>`,
					toggles: '',
				};
			}
			case 'Content': {
				// Content defaults plus the URL structure. Permalinks save
				// through their own endpoint (data-permakey), rendered after the
				// content toggles via the `after` slot.
				const pl = cache.permalinks;
				let perma;
				if ( ! pl ) {
					perma = subhead( 'URLs' ) + `<div class="minn-editor-locked-note">Permalink settings couldn’t be loaded. <a href="${ esc( B.site.adminUrl ) }options-permalink.php">Open in the classic admin ↗</a></div>`;
				} else {
					const isPreset = PERMALINK_PRESETS.some( ( [ v ] ) => v === pl.structure );
					perma = subhead( 'URLs' )
						+ `<div class="minn-fields">`
						+ permaSelect( '_preset', 'Permalink structure', [ ...PERMALINK_PRESETS, [ '_custom', 'Custom structure' ] ], isPreset ? pl.structure : '_custom' )
						+ permaText( 'structure', 'Custom structure', pl.structure, true )
						+ `<div class="minn-toggle-desc">Tags: %year% %monthnum% %day% %postname% %post_id% %category% %author%. With Plain permalinks, Minn itself moves from /minn-admin/ to ?minn_admin=1 and reloads after saving.</div>`
						+ permaText( 'category_base', 'Category base (optional)', pl.category_base, true )
						+ permaText( 'tag_base', 'Tag base (optional)', pl.tag_base, true )
						+ `</div>`;
				}
				return {
					sub: 'Defaults for new content, and the URL structure.',
					fields: combo( 'default_category', 'Default post category', cache.categories.map( ( c ) => [ c.id, decodeEntities( c.name ) ] ), s.default_category )
						+ select( 'default_post_format', 'Default post format', POST_FORMATS.map( ( f ) => [ f, f.charAt( 0 ).toUpperCase() + f.slice( 1 ) ] ), s.default_post_format || 'standard' ),
					toggles: [ { id: 'use_smilies', label: 'Convert emoticons', desc: 'Turn :-) and :-P into graphics when displayed.', on: !! s.use_smilies } ].map( toggle ).join( '' ),
					after: perma,
				};
			}
			case 'Comments': {
				// Comment behavior plus spam (they're the same job). The spam
				// section (provider cards from adapters/spam.php + the core
				// blocklist) renders after the toggles and saves through the
				// spam endpoint; see the composable save handler.
				const commentToggles = [
					{ id: 'default_comment_status', label: 'Allow comments', desc: 'Let readers respond to new posts.', on: s.default_comment_status === 'open' },
					{ id: 'default_ping_status', label: 'Allow pingbacks & trackbacks', desc: 'Accept link notifications from other blogs on new posts.', on: s.default_ping_status === 'open' },
					{ id: 'comment_moderation', label: 'Moderate all comments', desc: 'Every comment must be manually approved before it appears.', on: !! s.comment_moderation },
					{ id: 'comment_registration', label: 'Registered users only', desc: 'Users must be registered and logged in to comment.', on: !! s.comment_registration },
					{ id: 'show_avatars', label: 'Show avatars', desc: 'Display profile pictures next to comments.', on: !! s.show_avatars },
				].map( toggle ).join( '' );
				const sp = cache.spam;
				let spamHtml;
				if ( ! sp ) {
					spamHtml = subhead( 'Spam' ) + `<div class="minn-editor-locked-note">Spam protection couldn’t be loaded. <a href="${ esc( B.site.adminUrl ) }plugins.php">Open plugins ↗</a></div>`;
				} else {
					const cards = sp.providers.map( ( p ) => `
						<div class="minn-spam-provider">
							<div class="minn-spam-head">
								<span class="minn-spam-name">${ esc( p.name ) }</span>
								<span class="minn-spam-pill${ p.configured ? ' ok' : ' warn' }">${ p.configured ? 'Active' : 'Needs setup' }</span>
								${ p.blocked ? `<span class="minn-spam-blocked">${ esc( String( p.blocked ) ) } blocked all-time</span>` : '' }
								${ p.adminUrl ? `<a class="minn-spam-link" href="${ esc( p.adminUrl ) }" target="_blank" rel="noopener">Full settings ↗</a>` : '' }
							</div>
							<div class="minn-toggle-desc">${ esc( p.note ) }</div>
							${ p.toggles.length ? `<div class="minn-toggle-rows">${ p.toggles.map( ( t ) => `
								<div class="minn-toggle-row">
									<div class="minn-toggle-info">
										<div class="minn-toggle-label">${ esc( t.label ) }</div>
										<div class="minn-toggle-desc">${ esc( t.desc ) }</div>
									</div>
									<button class="minn-switch${ t.on ? ' on' : '' }" data-spamtog="${ esc( p.id ) }:${ esc( t.id ) }" role="switch" aria-checked="${ t.on }"><span class="minn-switch-knob"></span></button>
								</div>` ).join( '' ) }</div>` : '' }
						</div>` ).join( '' );
					const empty = sp.providers.length ? '' : `
						<div class="minn-editor-locked-note">No spam filter plugin is active. Install Akismet, Antispam Bee or CleanTalk from <a href="#" id="minn-spam-ext">Extensions</a> and it appears here. Core's blocklist below still works on its own.</div>`;
					// The queue row follows the app's comments detection (Disable
					// Comments and friends) — a Review button must never navigate
					// to a route the nav itself hides.
					const queue = B.comments ? `
						<div class="minn-spam-queue">
							<span>${ sp.queue.spam } comment${ sp.queue.spam === 1 ? '' : 's' } in the spam queue${ sp.queue.pending ? ` · ${ sp.queue.pending } pending review` : '' }</span>
							<button class="minn-btn-soft" id="minn-spam-queue" type="button">Review spam →</button>
						</div>` : `
						<div class="minn-spam-queue">
							<span>Commenting is disabled on this site, so there is no comment spam queue to review.</span>
						</div>`;
					spamHtml = subhead( 'Spam' ) + cards + empty + queue + `
						<div>
							<div class="minn-field-label">Disallowed comment keys</div>
							<textarea class="minn-input mono minn-surface-textarea" id="minn-spam-keys" rows="5" placeholder="one word, IP, email or URL fragment per line">${ esc( sp.disallowed_keys ) }</textarea>
							<div class="minn-toggle-desc">Core’s built-in filter: comments containing any of these go straight to the spam folder. One entry per line.</div>
						</div>`;
				}
				return {
					sub: 'How comments behave, and how spam is handled.',
					fields: '',
					toggles: commentToggles,
					after: spamHtml,
				};
			}
			default: return { sub: '', fields: '', toggles: '' };
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
				${ settingsSections().map( ( label ) =>
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
				${ section.after ? `<div class="minn-divider"></div>${ section.after }` : '' }
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

		// Spam section: provider switches just flip locally (all states are
		// collected at save); the queue button jumps to the Comments spam tab.
		$$( '[data-spamtog]', view ).forEach( ( btn ) =>
			btn.addEventListener( 'click', () => {
				btn.classList.toggle( 'on' );
				btn.setAttribute( 'aria-checked', btn.classList.contains( 'on' ) );
			} )
		);
		const spamQueueBtn = $( '#minn-spam-queue', view );
		if ( spamQueueBtn ) {
			spamQueueBtn.addEventListener( 'click', () => {
				state.commentTab = 'spam';
				state.cache.comments = null;
				go( 'comments' );
			} );
		}
		const spamExt = $( '#minn-spam-ext', view );
		if ( spamExt ) spamExt.addEventListener( 'click', ( e ) => { e.preventDefault(); go( 'extensions' ); } );

		// Design tab: Tab indents inside the CSS editor instead of leaving it
		// (execCommand insertText keeps the browser's undo stack intact).
		const cssEditor = $( '#minn-custom-css', view );
		if ( cssEditor ) cssEditor.addEventListener( 'keydown', ( e ) => {
			if ( e.key === 'Tab' && ! e.shiftKey ) {
				e.preventDefault();
				document.execCommand( 'insertText', false, '  ' );
			}
		} );

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
		const presetSel = $( '[data-permakey="_preset"]', view );
		if ( presetSel ) {
			const structInput = $( '[data-permakey="structure"]', view );
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
				// A tab can carry any mix of core settings, the spam config and
				// the permalink structure (each with its own endpoint), so the
				// save runs whichever the current tab actually shows — detected
				// by the elements present, not the tab name. Permalinks go LAST
				// because a routing-mode flip reloads the page.
				let okAll = true;

				// --- Core settings (wp/v2/settings): [data-setting] toggles +
				// [data-key] fields. Permalink fields carry data-permakey, so
				// they're excluded here. ---
				const NUMERIC = [ 'default_category', 'posts_per_page', 'page_on_front', 'page_for_posts', 'start_of_week' ];
				const payload = { ...pending };
				$$( '[data-key]', view ).forEach( ( input ) => {
					const key = input.dataset.key;
					let value = input.dataset.acValue !== undefined ? input.dataset.acValue : input.value;
					if ( key === 'url' && value.trim() === s.url ) return;
					if ( NUMERIC.includes( key ) ) value = parseInt( value, 10 ) || 0;
					payload[ key ] = value;
				} );
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
				if ( Object.keys( payload ).length ) {
					try {
						cache.values = await api( 'wp/v2/settings', { method: 'POST', body: JSON.stringify( payload ) } );
						// Maintenance mode / search-engine visibility live here —
						// refresh the banner + chip without a reload.
						if ( 'minn_admin_maintenance' in payload || 'blog_public' in payload ) refreshVisibility();
					} catch ( err ) { toast( err.message, true ); okAll = false; }
				}

				// --- Spam (minn-admin/v1/spam): only when the Comments tab shows it. ---
				if ( $( '[data-spamtog]', view ) || $( '#minn-spam-keys', view ) ) {
					const toggles = {};
					$$( '[data-spamtog]', view ).forEach( ( btn ) => {
						const [ pid, tid ] = btn.dataset.spamtog.split( ':' );
						( toggles[ pid ] = toggles[ pid ] || {} )[ tid ] = btn.classList.contains( 'on' );
					} );
					const body = { toggles };
					const keysEl = $( '#minn-spam-keys', view );
					if ( keysEl ) body.disallowed_keys = keysEl.value;
					try {
						cache.spam = await api( 'minn-admin/v1/spam', { method: 'POST', body: JSON.stringify( body ) } );
					} catch ( err ) { toast( err.message, true ); okAll = false; }
				}

				// --- Custom CSS (minn-admin/v1/custom-css): the Design tab.
				// Structural validation happens server-side (balanced braces,
				// closed comments — the Customizer's refusal, mirrored). ---
				const cssEl = $( '#minn-custom-css', view );
				if ( cssEl ) {
					try {
						cache.customCss = await api( 'minn-admin/v1/custom-css', { method: 'POST', body: JSON.stringify( { css: cssEl.value } ) } );
					} catch ( err ) {
						// Early return: the tail re-render would rebuild the
						// textarea from the cached (old) CSS and wipe the typed
						// fix-in-progress. The Design tab carries nothing else.
						toast( err.message, true );
						saveBtn.disabled = false;
						return;
					}
				}

				// --- Permalinks (minn-admin/v1/permalinks): the Content tab, last. ---
				if ( $( '[data-permakey]', view ) ) {
					const pl = {};
					$$( '[data-permakey]', view ).forEach( ( input ) => {
						if ( input.dataset.permakey !== '_preset' ) pl[ input.dataset.permakey ] = input.value;
					} );
					try {
						const r = await api( 'minn-admin/v1/permalinks', { method: 'POST', body: JSON.stringify( pl ) } );
						cache.permalinks = r;
						if ( r.pretty !== !! B.pretty ) {
							// Routing mode flipped (path ↔ ?minn_admin=1) — reload at the app's new home.
							toast( 'Settings saved — reloading…' );
							setTimeout( () => { window.location.href = r.app_url + ( r.pretty ? 'settings' : '#/settings' ); }, 700 );
							return;
						}
					} catch ( err ) { toast( err.message, true ); okAll = false; }
				}

				if ( okAll ) toast( 'Settings saved' );
				renderSettings();
				saveBtn.disabled = false;
			} );
		}
	}

	/* ===== Editor ===== */

	// Blocks whose markup survives a contenteditable round-trip. Anything else
	// (embeds, columns, custom blocks…) becomes an atomic non-editable island:
	// preserved byte-for-byte on save, editable text around it.
	// details is intentionally NOT here — free contenteditable <details> traps
	// the caret and blocks typing after it in Blink. It inserts as an island
	// (summary/body editable via the inspector text runs).
	const SIMPLE_BLOCKS = [ 'paragraph', 'heading', 'quote', 'pullquote', 'code', 'preformatted', 'verse', 'list', 'list-item', 'image', 'table', 'html', 'separator', 'more', 'video', 'audio' ];

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
		// An empty post has nothing classic mode would preserve — open it in
		// the native blocks mode. Without this, a new post reloaded before its
		// first content save (the title-only autosave draft) degraded to
		// classic permanently, hiding embeds/galleries/custom blocks.
		if ( ! String( raw || '' ).trim() ) return 'blocks';
		if ( ! /<!--\s*wp:/.test( raw ) ) return 'classic';
		return tokenizeBlocks( raw ) ? 'blocks' : 'locked';
	}

	// Attributes the serializer reproduces faithfully; any other attribute on a
	// simple block turns it into an island so nothing is silently dropped.
	// Every attr here MUST be reproducible from the live DOM at serialize time
	// (alignment rides the has-text-align-* class; list numbering rides real
	// start/reversed/type attributes on the <ol>).
	const EDITABLE_ATTRS = {
		paragraph: [ 'align' ],
		heading: [ 'level', 'textAlign' ],
		list: [ 'ordered', 'start', 'reversed', 'type' ],
		table: [ 'hasFixedLayout' ],
		code: [ 'language' ],
	};

	// Blocks whose attributes ride through editing verbatim: the comment JSON is
	// parked on the element as data-minn-attrs and re-emitted byte-faithfully on
	// serialize. Only non-text-flow blocks — typing can't split them, so the
	// attribute marker can't be duplicated by contenteditable. This is what lets
	// real Gutenberg images ({"id":…,"sizeSlug":…}) stay editable instead of
	// becoming islands.
	const PASSTHROUGH_BLOCKS = [ 'image', 'table', 'quote', 'pullquote', 'separator', 'verse', 'preformatted', 'video', 'audio' ];

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
	// Special interactive islands (shortcode, details, buttons) host live
	// fields and commit into ed.islands[idx] on every edit — serialize never
	// reads the fields themselves, and renderIslandPreviews must not overwrite them.
	function islandHtml( idx, name, raw ) {
		const short = String( name || '' ).replace( /^core\//, '' );
		if ( short === 'shortcode' ) {
			const code = stripBlockComments( raw || '' ).trim();
			return `<div class="minn-block-island minn-shortcode-island" contenteditable="false" data-island="${ idx }" data-block="${ esc( name ) }">
				<button class="minn-island-chip" data-inspect="${ idx }" title="Configure block" type="button">⚙ shortcode</button>
				<label class="minn-shortcode-label" for="minn-sc-${ idx }">Shortcode</label>
				<input id="minn-sc-${ idx }" class="minn-shortcode-input" type="text" data-shortcode="${ idx }" value="${ esc( code ) }" placeholder="[shortcode attr=&quot;value&quot;]" spellcheck="false" autocomplete="off">
			</div>`;
		}
		if ( short === 'details' ) {
			// Interactive details: native open/close + editable summary/body.
			// Free <details> in the main contenteditable traps the caret; nesting
			// the widget inside contenteditable=false keeps expand/type safe.
			const parts = parseDetailsRaw( raw );
			const bodyInner = parts.bodyHtml && parts.bodyHtml.trim()
				? parts.bodyHtml
				: '<p><br></p>';
			return `<div class="minn-block-island minn-details-island" contenteditable="false" data-island="${ idx }" data-block="${ esc( name ) }">
				<button class="minn-island-chip" data-inspect="${ idx }" title="Configure block" type="button">⚙ details</button>
				<details class="minn-details-edit" open>
					<summary class="minn-details-sum-row">
						<input type="text" class="minn-details-summary" data-details-summary="${ idx }" value="${ esc( parts.summary ) }" placeholder="Details" spellcheck="true" autocomplete="off">
					</summary>
					<div class="minn-details-body" contenteditable="true" data-details-body="${ idx }" data-placeholder="Write the hidden content…">${ bodyInner }</div>
				</details>
			</div>`;
		}
		if ( short === 'buttons' ) {
			// CTA row: label + URL per button, add/remove, optional new-tab + outline.
			const parts = parseButtonsRaw( raw );
			const rows = parts.buttons.map( ( b, i ) => buttonsRowHtml( i, b ) ).join( '' );
			const wrapData = parts.wrapAttrs
				? ` data-btn-wrap-attrs="${ esc( JSON.stringify( parts.wrapAttrs ) ) }"`
				: '';
			return `<div class="minn-block-island minn-buttons-island" contenteditable="false" data-island="${ idx }" data-block="${ esc( name ) }" data-btn-stamped="1"${ wrapData }>
				<button class="minn-island-chip" data-inspect="${ idx }" title="Configure block" type="button">⚙ buttons</button>
				<div class="minn-buttons-rows">${ rows }</div>
				<button type="button" class="minn-btn-soft minn-buttons-add">+ Add button</button>
			</div>`;
		}
		const inner = stripBlockComments( raw ).trim();
		return `<div class="minn-block-island" contenteditable="false" data-island="${ idx }" data-block="${ esc( name ) }">
			<button class="minn-island-chip" data-inspect="${ idx }" title="Configure block" type="button">⚙ ${ esc( short || name ) }</button>
			<div class="minn-island-preview" data-preview="${ idx }">${ inner || '<div class="minn-island-empty">Dynamic block — rendered on the site</div>' }</div>
		</div>`;
	}

	const stripBlockComments = ( raw ) => raw.replace( /<!--\s*\/?wp:[\s\S]*?-->\n?/g, '' );

	// Pull summary + body HTML + attrs out of a core/details island raw string.
	function parseDetailsRaw( raw ) {
		const str = String( raw || '' );
		const openM = str.match( /<!--\s*wp:details((?:(?!-->)[\s\S])*?)\s*-->/ );
		let attrs = null;
		if ( openM && openM[ 1 ] && openM[ 1 ].trim() ) {
			try { attrs = JSON.parse( openM[ 1 ].trim() ); } catch ( e ) { attrs = null; }
		}
		const html = stripBlockComments( str ).trim();
		const wrap = document.createElement( 'div' );
		wrap.innerHTML = html;
		const det = wrap.querySelector( 'details' );
		if ( ! det ) {
			return { attrs, summary: 'Details', bodyHtml: '<p></p>' };
		}
		const sum = det.querySelector( ':scope > summary' );
		const summary = sum ? ( sum.textContent || '' ).replace( /\s+/g, ' ' ).trim() || 'Details' : 'Details';
		let bodyHtml = '';
		if ( sum ) {
			const parts = [];
			for ( let n = sum.nextSibling; n; n = n.nextSibling ) {
				if ( n.nodeType === 1 ) parts.push( n.outerHTML );
				else if ( n.nodeType === 3 && n.textContent.trim() ) {
					parts.push( '<p>' + esc( n.textContent.trim() ) + '</p>' );
				}
			}
			bodyHtml = parts.join( '' ) || '<p></p>';
		} else {
			bodyHtml = det.innerHTML || '<p></p>';
		}
		return { attrs, summary, bodyHtml };
	}

	// Rebuild core/details markup from the island fields. Preserves any attrs
	// (e.g. showContent) that were on the original block comment.
	function buildDetailsRaw( summary, bodyHtml, attrs ) {
		const s = ( summary != null ? String( summary ) : 'Details' ).replace( /\s+/g, ' ' ).trim() || 'Details';
		let body = ( bodyHtml != null ? String( bodyHtml ) : '' ).trim();
		// contenteditable empty husks → a single empty paragraph (Gutenberg shape).
		const plain = body.replace( /<br\s*\/?>/gi, '' ).replace( /<p>\s*<\/p>/gi, '' ).replace( /\s+/g, '' );
		if ( ! plain ) body = '<p></p>';
		else if ( ! /^</.test( body ) ) {
			// Plain text path (textarea fallback): one <p> per blank-line split.
			body = body.split( /\n\n+/ ).map( ( line ) => `<p>${ esc( line ) }</p>` ).join( '' ) || '<p></p>';
		}
		const sa = attrs && typeof attrs === 'object' && ! Array.isArray( attrs ) ? attrs : null;
		const openComment = sa && Object.keys( sa ).length
			? `<!-- wp:details${ serializeBlockAttrs( sa ) } -->`
			: '<!-- wp:details -->';
		const openAttr = sa && sa.showContent ? ' open' : '';
		return `${ openComment }\n<details class="wp-block-details"${ openAttr }><summary>${ esc( s ) }</summary>${ body }</details>\n<!-- /wp:details -->`;
	}

	// Keep ed.islands in sync with the in-island shortcode field. Serialize
	// reads islands[] (not DOM), so every keystroke must land here.
	// opts.silent: skip scheduleAutosave (used by serialize flush so a save
	// doesn't re-arm the idle timer).
	function commitShortcodeInput( input, opts ) {
		const ed = state.editor;
		if ( ! ed || ! ed.islands || ! input ) return;
		const idx = parseInt( input.dataset.shortcode, 10 );
		if ( ! Number.isFinite( idx ) || ed.islands[ idx ] == null ) return;
		const next = shortcodeTemplate( input.value );
		if ( ed.islands[ idx ] === next ) return;
		ed.islands[ idx ] = next;
		if ( ! ( opts && opts.silent ) ) scheduleAutosave();
	}

	function commitDetailsIsland( islandEl, opts ) {
		const ed = state.editor;
		if ( ! ed || ! ed.islands || ! islandEl ) return;
		const idx = parseInt( islandEl.dataset.island, 10 );
		if ( ! Number.isFinite( idx ) || ed.islands[ idx ] == null ) return;
		const sum = islandEl.querySelector( '.minn-details-summary' );
		const body = islandEl.querySelector( '.minn-details-body' );
		if ( ! sum || ! body ) return;
		const prev = parseDetailsRaw( ed.islands[ idx ] );
		const next = buildDetailsRaw( sum.value, body.innerHTML, prev.attrs );
		if ( ed.islands[ idx ] === next ) return;
		ed.islands[ idx ] = next;
		if ( ! ( opts && opts.silent ) ) scheduleAutosave();
	}

	function focusShortcodeIsland( islandEl ) {
		const input = islandEl && islandEl.querySelector( '.minn-shortcode-input' );
		if ( ! input ) return;
		// Defer so the slash menu / picker teardown doesn't steal focus.
		requestAnimationFrame( () => {
			if ( ! input.isConnected ) return;
			input.focus( { preventScroll: true } );
			// Fresh insert is "[]" — select all so the first keystroke replaces.
			if ( input.value === '[]' || ! input.value.trim() ) input.select();
		} );
	}

	function focusDetailsIsland( islandEl ) {
		if ( ! islandEl ) return;
		const det = islandEl.querySelector( 'details.minn-details-edit' );
		if ( det ) det.open = true;
		const sum = islandEl.querySelector( '.minn-details-summary' );
		requestAnimationFrame( () => {
			if ( ! sum || ! sum.isConnected ) return;
			sum.focus( { preventScroll: true } );
			// Fresh insert defaults to "Details" — select so typing replaces.
			if ( sum.value === 'Details' ) sum.select();
		} );
	}

	/* ===== Buttons island (core/buttons + nested core/button) ===== */

	function buttonsRowHtml( i, b ) {
		const btn = b || { text: 'Button', url: '', newTab: false, outline: false, attrs: {} };
		const attrsJson = esc( JSON.stringify( btn.attrs && typeof btn.attrs === 'object' && ! Array.isArray( btn.attrs ) ? btn.attrs : {} ) );
		return `<div class="minn-btn-row" data-btn-i="${ i }" data-btn-attrs="${ attrsJson }">
			<input type="text" class="minn-btn-label" value="${ esc( btn.text || '' ) }" placeholder="Label" spellcheck="true" autocomplete="off">
			<input type="url" class="minn-btn-url" value="${ esc( btn.url || '' ) }" placeholder="https://…" spellcheck="false" autocomplete="off">
			<label class="minn-btn-opt" title="Open in a new tab"><input type="checkbox" class="minn-btn-newtab"${ btn.newTab ? ' checked' : '' }><span>New tab</span></label>
			<label class="minn-btn-opt" title="Outline style"><input type="checkbox" class="minn-btn-outline"${ btn.outline ? ' checked' : '' }><span>Outline</span></label>
			<button type="button" class="minn-btn-row-del" title="Remove button" aria-label="Remove button">×</button>
		</div>`;
	}

	// Parse a core/buttons island into editable rows. Preserves each button's
	// full attr object so colors/width/etc. survive a label/URL edit.
	function parseButtonsRaw( raw ) {
		const str = String( raw || '' );
		const openM = str.match( /<!--\s*wp:buttons((?:(?!-->)[\s\S])*?)\s*-->/ );
		let wrapAttrs = null;
		if ( openM && openM[ 1 ] && openM[ 1 ].trim() ) {
			try { wrapAttrs = JSON.parse( openM[ 1 ].trim() ); } catch ( e ) { wrapAttrs = null; }
		}
		const buttons = [];
		// (?![a-z-]) so "wp:button" does not match the start of "wp:buttons".
		const re = /<!--\s*wp:button(?![a-z-])((?:(?!-->)[\s\S])*?)\s*-->\s*([\s\S]*?)<!--\s*\/wp:button\s*-->/g;
		let m;
		while ( ( m = re.exec( str ) ) ) {
			let attrs = {};
			if ( m[ 1 ] && m[ 1 ].trim() ) {
				try { attrs = JSON.parse( m[ 1 ].trim() ) || {}; } catch ( e ) { attrs = {}; }
			}
			// Attrs may be an empty array from some serializers — normalize.
			if ( Array.isArray( attrs ) ) attrs = {};
			const wrap = document.createElement( 'div' );
			wrap.innerHTML = m[ 2 ];
			const a = wrap.querySelector( 'a' );
			const div = wrap.querySelector( '.wp-block-button' ) || wrap.firstElementChild;
			const text = ( a ? a.textContent : ( attrs.text || 'Button' ) || 'Button' )
				.replace( /\s+/g, ' ' ).trim() || 'Button';
			const url = ( a && a.getAttribute( 'href' ) ) || attrs.url || '';
			const newTab = ( a && a.getAttribute( 'target' ) === '_blank' )
				|| attrs.linkTarget === '_blank';
			const classBlob = [ attrs.className || '', div && div.className || '', a && a.className || '' ].join( ' ' );
			const outline = /\bis-style-outline\b/.test( classBlob );
			buttons.push( { attrs, text, url, newTab, outline } );
		}
		if ( ! buttons.length ) {
			buttons.push( { attrs: {}, text: 'Button', url: '', newTab: false, outline: false } );
		}
		return { wrapAttrs, buttons };
	}

	function buildButtonsRaw( buttons, wrapAttrs ) {
		const list = ( buttons && buttons.length )
			? buttons
			: [ { attrs: {}, text: 'Button', url: '', newTab: false, outline: false } ];
		const inner = list.map( ( b ) => {
			const text = ( b.text != null ? String( b.text ) : 'Button' ).replace( /\s+/g, ' ' ).trim() || 'Button';
			const url = ( b.url != null ? String( b.url ) : '' ).trim();
			const attrs = {};
			// Preserve non-edited attrs (colors, width, style, …).
			const prev = b.attrs && typeof b.attrs === 'object' && ! Array.isArray( b.attrs ) ? b.attrs : {};
			Object.keys( prev ).forEach( ( k ) => {
				if ( [ 'url', 'linkTarget', 'rel', 'className', 'text', 'placeholder' ].includes( k ) ) return;
				if ( prev[ k ] === '' || prev[ k ] == null ) return;
				attrs[ k ] = prev[ k ];
			} );
			if ( url ) attrs.url = url;
			if ( b.newTab ) {
				attrs.linkTarget = '_blank';
				attrs.rel = ( prev.rel && prev.rel !== 'noreferrer noopener' ) ? prev.rel : 'noreferrer noopener';
			}
			// Outline class on the wrapper div (Gutenberg convention).
			let cn = typeof prev.className === 'string' ? prev.className : '';
			cn = cn.replace( /\bis-style-outline\b/g, '' ).replace( /\s+/g, ' ' ).trim();
			if ( b.outline ) cn = ( cn + ' is-style-outline' ).trim();
			if ( cn ) attrs.className = cn;

			const divClass = cn ? `wp-block-button ${ cn }` : 'wp-block-button';
			let aOpen = '<a class="wp-block-button__link wp-element-button"';
			if ( url ) aOpen += ` href="${ esc( url ) }"`;
			if ( b.newTab ) {
				aOpen += ` target="_blank" rel="${ esc( attrs.rel || 'noreferrer noopener' ) }"`;
			}
			aOpen += '>';
			return `<!-- wp:button${ serializeBlockAttrs( attrs ) } -->\n`
				+ `<div class="${ divClass }">${ aOpen }${ esc( text ) }</a></div>\n`
				+ `<!-- /wp:button -->`;
		} ).join( '' );

		const wa = wrapAttrs && typeof wrapAttrs === 'object' && ! Array.isArray( wrapAttrs ) ? wrapAttrs : null;
		const open = wa && Object.keys( wa ).length
			? `<!-- wp:buttons${ serializeBlockAttrs( wa ) } -->`
			: '<!-- wp:buttons -->';
		return `${ open }\n<div class="wp-block-buttons">${ inner }</div>\n<!-- /wp:buttons -->`;
	}

	function buttonsTemplate( label, url ) {
		return buildButtonsRaw( [ {
			attrs: {},
			text: label != null ? String( label ) : 'Button',
			url: url != null ? String( url ) : '',
			newTab: false,
			outline: false,
		} ], null );
	}

	function collectButtonsFromIsland( islandEl ) {
		if ( ! islandEl ) return [];
		return $$( '.minn-btn-row', islandEl ).map( ( row ) => {
			const label = row.querySelector( '.minn-btn-label' );
			const url = row.querySelector( '.minn-btn-url' );
			const nt = row.querySelector( '.minn-btn-newtab' );
			const ol = row.querySelector( '.minn-btn-outline' );
			// Stash prior attrs on the row so rebuild can preserve colors etc.
			let attrs = {};
			try {
				if ( row.dataset.btnAttrs ) attrs = JSON.parse( row.dataset.btnAttrs ) || {};
			} catch ( e ) { attrs = {}; }
			return {
				attrs,
				text: label ? label.value : 'Button',
				url: url ? url.value : '',
				newTab: !!( nt && nt.checked ),
				outline: !!( ol && ol.checked ),
			};
		} );
	}

	function stampButtonsRowAttrs( islandEl, parsed ) {
		// Park each button's original attrs on its row so commits don't drop
		// colors/width that the form doesn't expose.
		const rows = $$( '.minn-btn-row', islandEl );
		( parsed.buttons || [] ).forEach( ( b, i ) => {
			if ( rows[ i ] ) rows[ i ].dataset.btnAttrs = JSON.stringify( b.attrs || {} );
		} );
		if ( parsed.wrapAttrs ) {
			islandEl.dataset.btnWrapAttrs = JSON.stringify( parsed.wrapAttrs );
		} else {
			delete islandEl.dataset.btnWrapAttrs;
		}
	}

	function commitButtonsIsland( islandEl, opts ) {
		const ed = state.editor;
		if ( ! ed || ! ed.islands || ! islandEl ) return;
		const idx = parseInt( islandEl.dataset.island, 10 );
		if ( ! Number.isFinite( idx ) || ed.islands[ idx ] == null ) return;
		let wrapAttrs = null;
		try {
			if ( islandEl.dataset.btnWrapAttrs ) wrapAttrs = JSON.parse( islandEl.dataset.btnWrapAttrs );
		} catch ( e ) { wrapAttrs = null; }
		// First commit after load: stamp attrs from current raw if not yet.
		if ( ! islandEl.dataset.btnStamped ) {
			const parsed = parseButtonsRaw( ed.islands[ idx ] );
			stampButtonsRowAttrs( islandEl, parsed );
			wrapAttrs = parsed.wrapAttrs;
			islandEl.dataset.btnStamped = '1';
		}
		const next = buildButtonsRaw( collectButtonsFromIsland( islandEl ), wrapAttrs );
		if ( ed.islands[ idx ] === next ) return;
		ed.islands[ idx ] = next;
		if ( ! ( opts && opts.silent ) ) scheduleAutosave();
	}

	function focusButtonsIsland( islandEl ) {
		const input = islandEl && islandEl.querySelector( '.minn-btn-label' );
		if ( ! input ) return;
		requestAnimationFrame( () => {
			if ( ! input.isConnected ) return;
			input.focus( { preventScroll: true } );
			if ( input.value === 'Button' || ! input.value.trim() ) input.select();
		} );
	}

	function addButtonsRow( islandEl ) {
		const box = islandEl && islandEl.querySelector( '.minn-buttons-rows' );
		if ( ! box ) return;
		const i = box.children.length;
		box.insertAdjacentHTML( 'beforeend', buttonsRowHtml( i, {
			attrs: {}, text: 'Button', url: '', newTab: false, outline: false,
		} ) );
		const row = box.lastElementChild;
		if ( row ) row.dataset.btnAttrs = '{}';
		commitButtonsIsland( islandEl );
		const label = row && row.querySelector( '.minn-btn-label' );
		if ( label ) {
			label.focus( { preventScroll: true } );
			label.select();
		}
	}

	function removeButtonsRow( row ) {
		const island = row && row.closest( '.minn-buttons-island' );
		if ( ! island || ! row ) return;
		const box = island.querySelector( '.minn-buttons-rows' );
		if ( ! box ) return;
		// Keep at least one row so the island never goes empty mid-edit.
		if ( box.children.length <= 1 ) {
			const label = row.querySelector( '.minn-btn-label' );
			const url = row.querySelector( '.minn-btn-url' );
			const nt = row.querySelector( '.minn-btn-newtab' );
			const ol = row.querySelector( '.minn-btn-outline' );
			if ( label ) label.value = 'Button';
			if ( url ) url.value = '';
			if ( nt ) nt.checked = false;
			if ( ol ) ol.checked = false;
			row.dataset.btnAttrs = '{}';
			commitButtonsIsland( island );
			if ( label ) { label.focus( { preventScroll: true } ); label.select(); }
			return;
		}
		row.remove();
		commitButtonsIsland( island );
	}

	// Live-field islands (shortcode/details/buttons) — never overwrite with a
	// server render; their DOM is the source of truth until commit.
	function isLiveFieldIsland( island ) {
		if ( ! island ) return false;
		const b = island.dataset.block || '';
		return /(?:^|\/)(shortcode|details|buttons)$/.test( b );
	}

	/* ===== Embeds & galleries (inserted as islands) ===== */

	// Hosts WordPress core oEmbeds that we auto-convert a pasted lone URL for.
	// The slash-menu Embed accepts ANY url — explicit intent needs no allowlist.
	const EMBED_PROVIDERS = [
		[ /(^|\.)youtube\.com$|(^|\.)youtu\.be$/, 'youtube', 'video' ],
		[ /(^|\.)vimeo\.com$/, 'vimeo', 'video' ],
		[ /(^|\.)dailymotion\.com$/, 'dailymotion', 'video' ],
		[ /(^|\.)wordpress\.tv$/, 'wordpress-tv', 'video' ],
		[ /(^|\.)videopress\.com$/, 'videopress', 'video' ],
		[ /(^|\.)tiktok\.com$/, 'tiktok', 'video' ],
		[ /(^|\.)ted\.com$/, 'ted', 'video' ],
		[ /(^|\.)twitter\.com$|^x\.com$/, 'twitter', 'rich' ],
		[ /(^|\.)instagram\.com$/, 'instagram', 'rich' ],
		[ /(^|\.)spotify\.com$/, 'spotify', 'rich' ],
		[ /(^|\.)soundcloud\.com$/, 'soundcloud', 'rich' ],
		[ /(^|\.)mixcloud\.com$/, 'mixcloud', 'rich' ],
		[ /(^|\.)reddit\.com$/, 'reddit', 'rich' ],
		[ /(^|\.)flickr\.com$/, 'flickr', 'rich' ],
		[ /(^|\.)tumblr\.com$/, 'tumblr', 'rich' ],
	];

	function embedProviderFor( url ) {
		try {
			const host = new URL( url ).hostname.replace( /^www\./, '' );
			const hit = EMBED_PROVIDERS.find( ( [ re ] ) => re.test( host ) );
			return hit ? { slug: hit[ 1 ], type: hit[ 2 ] } : null;
		} catch ( e ) {
			return null;
		}
	}

	// Gutenberg-faithful core/embed markup: attrs in the comment, the raw URL
	// on its own line in the wrapper. Videos get the aspect classes so the
	// front end renders them responsive, like Gutenberg does.
	function embedTemplate( url ) {
		const p = embedProviderFor( url );
		const attrs = { url };
		const classes = [ 'wp-block-embed' ];
		if ( p ) {
			attrs.type = p.type;
			attrs.providerNameSlug = p.slug;
			classes.push( 'is-type-' + p.type, 'is-provider-' + p.slug, 'wp-block-embed-' + p.slug );
		}
		if ( p && p.type === 'video' ) {
			attrs.responsive = true;
			attrs.className = 'wp-embed-aspect-16-9 wp-has-aspect-ratio';
			classes.push( 'wp-embed-aspect-16-9', 'wp-has-aspect-ratio' );
		}
		return `<!-- wp:embed${ serializeBlockAttrs( attrs ) } -->\n<figure class="${ classes.join( ' ' ) }"><div class="wp-block-embed__wrapper">\n${ url }\n</div></figure>\n<!-- /wp:embed -->`;
	}

	// Spacer — self-closing-ish island; height lives in attrs + inline style.
	function spacerTemplate( height ) {
		const h = ( height && String( height ).trim() ) || '40px';
		const px = /px$|%$|em$|rem$/.test( h ) ? h : h + 'px';
		return `<!-- wp:spacer${ serializeBlockAttrs( { height: px } ) } -->\n<div style="height:${ px }" aria-hidden="true" class="wp-block-spacer"></div>\n<!-- /wp:spacer -->`;
	}

	// File download block. `item` is a media-picker pick { id, url/name }.
	function fileTemplate( item ) {
		const id = item.id;
		const url = item.url || item.source_url || '';
		const name = item.name || item.title || 'Download';
		const attrs = { id, href: url };
		return `<!-- wp:file${ serializeBlockAttrs( attrs ) } -->\n<div class="wp-block-file"><a id="wp-block-file--media-${ id }" href="${ esc( url ) }">${ esc( name ) }</a><a href="${ esc( url ) }" class="wp-block-file__button wp-element-button" download>Download</a></div>\n<!-- /wp:file -->`;
	}

	// Shortcode island — free text between the comments is the shortcode body.
	// Empty/whitespace collapses to "[]" so the block stays a valid placeholder
	// the writer can select-and-replace in the island field.
	function shortcodeTemplate( code ) {
		const body = String( code == null ? '' : code ).trim() || '[]';
		return `<!-- wp:shortcode -->\n${ body }\n<!-- /wp:shortcode -->`;
	}

	// Modern (5.9+) gallery: nested core/image blocks inside core/gallery.
	function galleryTemplate( items ) {
		const inner = items.map( ( it ) => {
			const src = it.large || it.url;
			const slug = it.large && it.large !== it.url ? 'large' : 'full';
			return `<!-- wp:image${ serializeBlockAttrs( { id: it.id, sizeSlug: slug, linkDestination: 'none' } ) } -->\n`
				+ `<figure class="wp-block-image size-${ slug }"><img src="${ esc( src ) }" alt="${ esc( it.alt || '' ) }" class="wp-image-${ it.id }"/></figure>\n`
				+ `<!-- /wp:image -->`;
		} ).join( '\n\n' );
		return `<!-- wp:gallery${ serializeBlockAttrs( { linkTo: 'none' } ) } -->\n<figure class="wp-block-gallery has-nested-images columns-default is-cropped">${ inner }</figure>\n<!-- /wp:gallery -->`;
	}

	// Insert a new island before `anchor` (or at the caret's top-level block,
	// or appended to the body) and fetch its rendered preview.
	function insertIsland( anchor, blockName, template ) {
		const body = $( '#minn-editor-body' );
		const ed = state.editor;
		if ( ! body || ! ed ) return null;
		if ( ! anchor || ! anchor.isConnected ) {
			const sel = window.getSelection();
			let node = sel && sel.anchorNode;
			while ( node && node.parentNode !== body ) node = node.parentNode;
			anchor = node && node.parentNode === body ? node : null;
		}
		if ( ! ed.islands ) ed.islands = [];
		const idx = ed.islands.push( template ) - 1;
		const html = islandHtml( idx, blockName, template );
		if ( anchor ) anchor.insertAdjacentHTML( 'beforebegin', html );
		else body.insertAdjacentHTML( 'beforeend', html );
		const islandEl = body.querySelector( `.minn-block-island[data-island="${ idx }"]` );
		api( 'minn-admin/v1/render-blocks', { method: 'POST', body: JSON.stringify( { blocks: [ template ], post: ( state.editor && state.editor.id ) || 0 } ) } )
			.then( ( r ) => {
				injectPreviewStyles( r && r.styles );
				const rendered = r && r.rendered && r.rendered[ 0 ];
				const prev = islandEl && islandEl.querySelector( '.minn-island-preview' );
				if ( prev && rendered && rendered.trim() ) prev.innerHTML = rendered;
				updateEditorStats();
			} )
			.catch( () => {} );
		scheduleAutosave();
		return islandEl;
	}

	// Remove an island (embed/gallery/custom block) with an Undo toast. Island
	// deletion is direct-DOM, so it's outside the browser undo stack (see the
	// undo-completeness decision in docs/editor-roadmap.md) — this makes it
	// recoverable. Restoring re-inserts the node AND its islands[] entry, which
	// is nulled (not spliced) on remove so sibling data-island indices hold.
	function removeIslandWithUndo( island ) {
		if ( ! island || ! island.parentNode ) return;
		const ed = state.editor;
		const idx = parseInt( island.dataset.island, 10 );
		const template = ed && ed.islands ? ed.islands[ idx ] : null;
		const parent = island.parentNode;
		const next = island.nextSibling;
		if ( ed && ed.islands && ed.islands[ idx ] != null ) ed.islands[ idx ] = null;
		island.remove();
		updateEditorStats();
		if ( ed && ed.id ) scheduleAutosave();
		toastAction( 'Block removed · ⌘Z', 'Undo', () => {
			if ( ! parent.isConnected ) return;
			if ( ed && ed.islands && template != null ) ed.islands[ idx ] = template;
			parent.insertBefore( island, next && next.isConnected && next.parentNode === parent ? next : null );
			updateEditorStats();
			scheduleAutosave();
		} );
	}

	// Same undo toast for non-island atomic blocks (empty code <pre>, HR,
	// image figures) that bindIslandGuards arms with the red outline.
	function removeAtomicBlockWithUndo( el ) {
		if ( ! el || ! el.parentNode ) return;
		if ( el.classList && el.classList.contains( 'minn-block-island' ) ) {
			removeIslandWithUndo( el );
			return;
		}
		const parent = el.parentNode;
		const next = el.nextSibling;
		el.classList.remove( 'minn-island-armed' );
		el.remove();
		updateEditorStats();
		scheduleAutosave();
		toastAction( 'Block removed · ⌘Z', 'Undo', () => {
			if ( ! parent.isConnected ) return;
			parent.insertBefore( el, next && next.isConnected && next.parentNode === parent ? next : null );
			updateEditorStats();
			scheduleAutosave();
		} );
	}

	/* ===== Front-end styles for island previews ===== */

	// Islands render real block HTML, but Minn's standalone document never
	// loads the site's block/theme CSS — previews looked like bare text.
	// Collect the same stylesheets the block editor loads into its canvas
	// (minn-admin/v1/editor-styles), scope every rule to the preview
	// containers, and inject once per session. Failure just means previews
	// stay unstyled.
	let editorStylesPromise = null;

	function ensureEditorStyles() {
		if ( editorStylesPromise ) return editorStylesPromise;
		editorStylesPromise = ( async () => {
			try {
				const r = await api( 'minn-admin/v1/editor-styles' );
				const texts = await Promise.all( ( r.urls || [] ).map( ( u ) =>
					fetch( u, { credentials: 'omit' } )
						.then( ( x ) => ( x.ok ? x.text() : '' ) )
						.then( ( css ) => absolutizeCssUrls( css, u ) )
						.catch( () => '' )
				) );
				texts.push( r.inline || '' );
				const scoped = scopeCssToPreviews( texts.join( '\n' ) );
				if ( ! scoped ) return;
				const el = document.createElement( 'style' );
				el.id = 'minn-frontend-css';
				el.textContent = scoped;
				document.head.appendChild( el );
			} catch ( e ) { /* previews simply stay unstyled */ }
		} )();
		return editorStylesPromise;
	}

	// Lazy-CSS plugins (Stackable's optimizer, Kadence, GenerateBlocks) only
	// enqueue their stylesheets while one of their blocks RENDERS — the
	// editor-styles sweep can't see those, so render-blocks reports what the
	// render enqueued and this injects it, scoped, exactly once per source.
	const injectedPreviewCss = new Set();
	// Some preview CSS only exists after the PAGE runs in a browser (Otter's
	// atomic-wind compiles Tailwind client-side, and clears its cache on
	// every save). When the server says so (styles.warm), load the page in a
	// hidden iframe — the plugin's own compiler runs and persists — then
	// re-fetch the styles. One attempt per URL per session.
	const warmedPreviewUrls = new Set();
	function warmPreviewStyles( url ) {
		if ( warmedPreviewUrls.has( url ) ) return;
		warmedPreviewUrls.add( url );
		const frame = document.createElement( 'iframe' );
		frame.style.cssText = 'position:fixed;width:10px;height:10px;left:-9999px;top:-9999px;visibility:hidden;';
		frame.src = url;
		document.body.appendChild( frame );
		let tries = 0;
		const poll = () => {
			tries++;
			api( 'minn-admin/v1/render-blocks', { method: 'POST', body: JSON.stringify( { blocks: [], post: ( state.editor && state.editor.id ) || 0 } ) } )
				.then( ( r ) => {
					if ( r && r.styles && r.styles.inline ) {
						frame.remove();
						injectPreviewStyles( { urls: r.styles.urls, inline: r.styles.inline } );
					} else if ( tries < 8 ) {
						setTimeout( poll, 1500 );
					} else {
						frame.remove();
					}
				} )
				.catch( () => frame.remove() );
		};
		setTimeout( poll, 3500 );
	}
	async function injectPreviewStyles( styles ) {
		if ( ! styles ) return;
		if ( styles.warm ) warmPreviewStyles( styles.warm );
		const urls = ( styles.urls || [] ).filter( ( u ) => ! injectedPreviewCss.has( u ) );
		urls.forEach( ( u ) => injectedPreviewCss.add( u ) );
		const inlineKey = styles.inline ? 'inline:' + styles.inline.length + ':' + styles.inline.slice( 0, 80 ) : '';
		const inline = inlineKey && ! injectedPreviewCss.has( inlineKey ) ? styles.inline : '';
		if ( inlineKey ) injectedPreviewCss.add( inlineKey );
		if ( ! urls.length && ! inline ) return;
		try {
			const texts = await Promise.all( urls.map( ( u ) =>
				fetch( u, { credentials: 'omit' } )
					.then( ( x ) => ( x.ok ? x.text() : '' ) )
					.then( ( css ) => absolutizeCssUrls( css, u ) )
					.catch( () => '' )
			) );
			texts.push( inline );
			const scoped = scopeCssToPreviews( texts.join( '\n' ) );
			if ( ! scoped ) return;
			const el = document.createElement( 'style' );
			el.className = 'minn-preview-css';
			el.textContent = scoped;
			document.head.appendChild( el );
		} catch ( e ) { /* previews simply stay unstyled */ }
	}

	// Relative url(...) references break once CSS moves into an inline <style>
	// on the admin document — rewrite them against the stylesheet's own URL.
	function absolutizeCssUrls( css, base ) {
		return css.replace( /url\(\s*(['"]?)([^'")]+)\1\s*\)/g, ( m, q, path ) => {
			if ( /^(data:|https?:|\/\/|#|\/)/i.test( path ) ) return m;
			try {
				return `url(${ q }${ new URL( path, base ).href }${ q })`;
			} catch ( e ) {
				return m;
			}
		} );
	}

	// Prefix every selector with the preview scope. html/body/:root map onto
	// the preview container itself, so theme custom properties and
	// `body:not(.wp-admin)`-style gates land there. Canvas paints on body
	// (background) are STRIPPED from shell selectors — otherwise the site's
	// light page background becomes a white plate behind every island in
	// dark mode. @font-face and @keyframes pass through globally. Anything
	// unrecognized is dropped rather than leaked unscoped.
	function scopeCssToPreviews( cssText ) {
		let sheet;
		try {
			sheet = new CSSStyleSheet();
			sheet.replaceSync( cssText );
		} catch ( e ) {
			return '';
		}
		const SCOPE = '.minn-island-preview';
		const scopeSelector = ( selectorText ) => selectorText.split( ',' ).map( ( sel ) => {
			let s = sel.trim();
			if ( ! s ) return s;
			s = s.replace( /(^|[\s>+~])(:root|html|body)(?![\w-])/gi, ( m0, pre ) => pre + '&' );
			s = s.replace( /^&(\s*&)*/, SCOPE ); // ":root body …" chains collapse
			s = s.replace( /&/g, SCOPE );
			return s.startsWith( SCOPE ) ? s : SCOPE + ' ' + s;
		} ).join( ', ' );
		// True when every comma-branch of the scoped selector is the preview
		// shell itself (body / html / :root alone, not "body .card").
		const isPreviewShell = ( scoped ) => scoped.split( ',' ).every( ( s ) => {
			const t = s.trim().replace( /\s+/g, ' ' );
			return t === SCOPE || /^\.minn-island-preview(?::[\w-]+(?:\([^)]*\))?)*$/.test( t );
		} );
		const stripShellCanvas = ( declBlock ) => declBlock
			// background / background-color / background-image … (not -clip etc. alone)
			.replace( /(?:^|[;{\s])background(?:-color|-image|-size|-position|-repeat|-attachment|-origin|-clip|-blend-mode)?\s*:[^;}{]+;?/gi, ( m ) => {
				// Keep the leading delimiter (space/{/;) so the next property stays valid.
				const lead = m.match( /^[;{\s]/ );
				return lead ? lead[ 0 ] : '';
			} );
		const walk = ( rules ) => {
			let out = '';
			Array.from( rules ).forEach( ( rule ) => {
				if ( rule instanceof CSSStyleRule ) {
					// Emit the FULL rule body (not just declarations) so CSS
					// nesting survives — nested selectors are &-relative, so
					// scoping the parent selector scopes them too.
					let body = rule.cssText.slice( rule.cssText.indexOf( '{' ) );
					const scoped = scopeSelector( rule.selectorText );
					if ( isPreviewShell( scoped ) ) body = stripShellCanvas( body );
					out += scoped + ' ' + body + '\n';
				} else if ( rule instanceof CSSMediaRule ) {
					out += '@media ' + rule.conditionText + ' {\n' + walk( rule.cssRules ) + '}\n';
				} else if ( rule instanceof CSSSupportsRule ) {
					out += '@supports ' + rule.conditionText + ' {\n' + walk( rule.cssRules ) + '}\n';
				} else if ( window.CSSLayerBlockRule && rule instanceof CSSLayerBlockRule ) {
					// Compiled Tailwind (atomic-wind et al) wraps everything in
					// @layer — unwrap and scope the contents. Losing the layer
					// raises specificity, which is what a preview wants anyway.
					out += walk( rule.cssRules );
				} else if ( window.CSSLayerStatementRule && rule instanceof CSSLayerStatementRule ) {
					// @layer ordering statement — nothing to scope.
				} else if (
					rule instanceof CSSFontFaceRule
					|| ( window.CSSKeyframesRule && rule instanceof CSSKeyframesRule )
					|| ( window.CSSPropertyRule && rule instanceof CSSPropertyRule )
				) {
					// Resource definitions, not element styles — pass through.
					out += rule.cssText + '\n';
				}
			} );
			return out;
		};
		return walk( sheet.cssRules );
	}

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
	// Chrome's editing engine litters nbsp around inline elements (it guards
	// spaces that "might collapse" pessimistically, and our boundary-escape
	// typing does the same at block edges). Where an nbsp touches an inline
	// element and its other neighbour is a real character, a plain space
	// renders identically — store that instead. Trailing/leading nbsp (still
	// load-bearing) and everything inside <pre> are left alone.
	function cleanBoundaryNbsp( root ) {
		$$( 'code, strong, em, s, b, i, a', root ).forEach( ( el ) => {
			if ( el.closest( 'pre' ) ) return;
			const prev = el.previousSibling;
			if ( prev && prev.nodeType === Node.TEXT_NODE && /\S\u00A0$/.test( prev.textContent ) ) {
				prev.textContent = prev.textContent.slice( 0, -1 ) + ' ';
			}
			const next = el.nextSibling;
			if ( next && next.nodeType === Node.TEXT_NODE && /^\u00A0\S/.test( next.textContent ) ) {
				next.textContent = ' ' + next.textContent.slice( 1 );
			}
		} );
	}

	// A block-level paste splits the caret paragraph and Chrome hands the
	// split-off tail a leading nbsp. At paragraph start a plain space renders
	// as nothing while an nbsp shows as an indent — store the space. (Fixing
	// the live DOM instead would corrupt undo: Chrome replays recorded text
	// offsets, and an out-of-stack text edit misapplies them — probed, a
	// character vanished mid-word.)
	function cleanLeadingNbsp( blockEl ) {
		const t = blockEl.firstChild;
		if ( t && t.nodeType === Node.TEXT_NODE && /^\u00A0\S/.test( t.textContent ) ) {
			t.textContent = ' ' + t.textContent.slice( 1 );
		}
	}

	// Chrome's insertUnordered/OrderedList sometimes nests the new list INSIDE
	// the source paragraph — lift it to the top level or the serializer would
	// emit <p><ul>… (invalid markup). Shared by the toolbar, the slash menu
	// and the markdown "- " prefix.
	function liftNestedLists( body ) {
		$$( ':scope > p > ul, :scope > p > ol', body ).forEach( ( l ) => {
			const p = l.parentNode;
			if ( p.textContent === l.textContent ) p.replaceWith( l );
		} );
	}

	// execCommand('strikeThrough') writes the obsolete <strike> tag — store the
	// standard <s> instead (what Gutenberg and the ~~markdown~~ rule produce).
	function modernizeStrikes( root ) {
		$$( 'strike', root ).forEach( ( el ) => {
			const s = document.createElement( 's' );
			while ( el.firstChild ) s.appendChild( el.firstChild );
			el.replaceWith( s );
		} );
	}

	function serializeToBlocks( root, islands ) {
		// Flush in-island live fields so a save mid-keystroke (or before the
		// input event lands) still persists what the writer sees.
		if ( root ) {
			$$( '.minn-shortcode-input', root ).forEach( ( el ) => commitShortcodeInput( el, { silent: true } ) );
			$$( '.minn-details-island', root ).forEach( ( el ) => commitDetailsIsland( el, { silent: true } ) );
			$$( '.minn-buttons-island', root ).forEach( ( el ) => commitButtonsIsland( el, { silent: true } ) );
		}
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
			// A figure whose upload hasn't landed only holds a blob: URL —
			// meaningless outside this tab. Skip it; the post-upload swap
			// triggers another save that includes the real attachment.
			if ( n.nodeType === Node.ELEMENT_NODE && n.dataset && n.dataset.minnUpload ) return;
			const tag = n.tagName.toLowerCase();
			const el = n.cloneNode( true );
			el.removeAttribute( 'style' );
			// A redo can resurrect an empty paste-bracket paragraph (see
			// pasteBlocksInsert) — typed-into, it must not leak its marker.
			el.removeAttribute( 'data-minn-bkt' );
			// The inline-caption affordance is editor chrome until it has text.
			$$( 'figcaption', el ).forEach( ( fc ) => {
				if ( ! fc.textContent.trim() ) fc.remove();
			} );
			cleanBoundaryNbsp( el );
			cleanLeadingNbsp( el );
			modernizeStrikes( el );

			const alignOf = ( node ) => {
				const m = node.className.match( /has-text-align-(left|center|right)/ );
				return m ? m[ 1 ] : null;
			};
			if ( tag === 'p' ) {
				if ( ! el.textContent.trim() && ! el.querySelector( 'img' ) ) return;
				const align = alignOf( el );
				pushBlock( 'paragraph', align ? { align } : null, el.outerHTML );
			} else if ( /^h[1-6]$/.test( tag ) ) {
				el.classList.add( 'wp-block-heading' );
				const textAlign = alignOf( el );
				const hAttrs = { level: parseInt( tag[ 1 ], 10 ) };
				if ( textAlign ) hAttrs.textAlign = textAlign;
				pushBlock( 'heading', hAttrs, el.outerHTML );
			} else if ( tag === 'blockquote' ) {
				const pa = takeMinnAttrs( el );
				el.classList.add( 'wp-block-quote' );
				const cite = el.querySelector( ':scope > cite' );
				const paras = Array.from( el.children ).filter( ( ch ) => ch.tagName === 'P' );
				const inner = paras.length
					? paras.map( ( p ) => `<!-- wp:paragraph -->\n${ p.outerHTML }\n<!-- /wp:paragraph -->` ).join( '' )
					: `<!-- wp:paragraph -->\n<p>${ cite ? '' : el.innerHTML }</p>\n<!-- /wp:paragraph -->`;
				pushBlock( 'quote', pa, `<blockquote class="${ el.className }">${ inner }${ cite ? cite.outerHTML : '' }</blockquote>` );
			} else if ( tag === 'figure' && ( el.classList.contains( 'wp-block-pullquote' ) || ( el.querySelector( ':scope > blockquote' ) && ! el.querySelector( 'img, video, audio, table' ) ) ) ) {
				// Pullquote: figure > blockquote > p + optional cite. Distinct
				// from image/table figures and from bare blockquote quotes.
				const pa = takeMinnAttrs( el );
				el.classList.add( 'wp-block-pullquote' );
				const bq = el.querySelector( ':scope > blockquote' ) || el;
				if ( bq !== el && ! bq.querySelector( 'p' ) && bq.textContent.trim() ) {
					// Flatten bare text into a paragraph so Gutenberg stays happy.
					const p = document.createElement( 'p' );
					p.textContent = bq.textContent;
					const cite = bq.querySelector( ':scope > cite' );
					bq.textContent = '';
					bq.appendChild( p );
					if ( cite ) bq.appendChild( cite );
				}
				pushBlock( 'pullquote', pa, el.outerHTML );
			} else if ( tag === 'details' ) {
				// Details/summary — native HTML, free typing in summary + body.
				const pa = takeMinnAttrs( el );
				el.classList.add( 'wp-block-details' );
				// Editor-only expanded state must not stick in saved markup.
				el.removeAttribute( 'open' );
				// Drop empty body paragraphs; keep at least one so structure is valid.
				$$( ':scope > p', el ).forEach( ( p ) => {
					if ( ! p.textContent.trim() && ! p.querySelector( 'img' ) ) p.remove();
				} );
				if ( ! el.querySelector( ':scope > p, :scope > ul, :scope > ol, :scope > blockquote' ) ) {
					const p = document.createElement( 'p' );
					el.appendChild( p );
				}
				pushBlock( 'details', pa, el.outerHTML );
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
				const la = tag === 'ol' ? { ordered: true } : null;
				let listHtmlAttrs = '';
				if ( tag === 'ol' ) {
					const start = parseInt( el.getAttribute( 'start' ), 10 );
					const type = el.getAttribute( 'type' );
					if ( start ) { la.start = start; listHtmlAttrs += ` start="${ start }"`; }
					if ( el.hasAttribute( 'reversed' ) ) { la.reversed = true; listHtmlAttrs += ' reversed'; }
					if ( type ) { la.type = type; listHtmlAttrs += ` type="${ esc( type ) }"`; }
				}
				const items = Array.from( el.querySelectorAll( ':scope > li' ) )
					.map( ( li ) => `<!-- wp:list-item -->\n${ li.outerHTML }\n<!-- /wp:list-item -->` ).join( '' );
				pushBlock( 'list', la, `<${ tag }${ listHtmlAttrs } class="${ el.className }">${ items }</${ tag }>` );
			} else if ( tag === 'figure' && ! el.querySelector( 'img, video, audio, table, iframe' ) && ! el.textContent.trim() ) {
				return; // husk left behind by an undoable image delete
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
			const p = await api( `wp/v2/${ state.editorType }/${ state.editorId }?context=edit&_fields=id,title,content.raw,status,slug,link,categories,tags,date,modified,featured_media,parent,menu_order,template,excerpt,comment_status,ping_status,password,sticky,format,minn_builder${ extraKeys }` );
			const raw = ( p.content && p.content.raw ) || '';
			// A builder that OWNS the canvas (Elementor/Beaver/Brizy/Divi-4:
			// canonical content lives outside post_content) forces locked mode —
			// a Minn edit to the stale copy would silently never render. Block-
			// native builders (Etch, Divi 5) stay editable; islands protect them.
			const builder = p.minn_builder || null;
			const mode = builder && builder.owns_content ? 'locked' : editorModeFor( raw );
			state.editor = {
				id: p.id,
				type: state.editorType,
				title: decodeEntities( ( p.title && ( p.title.raw != null ? p.title.raw : p.title.rendered ) ) || '' ),
				content: '',
				islands: [],
				mode,
				builder,
				editUrl: B.site.adminUrl + 'post.php?post=' + p.id + '&action=edit',
				status: p.status,
				date: p.date || null,
				newDate: null,
				slug: '/' + ( p.slug || '' ),
				slugValue: p.slug || '',
				link: p.link,
				savedAt: null,
				// Discussion + visibility ride wp/v2's native fields (verified
				// round-trip in context=edit). Visibility is derived: a private
				// status wins, else a set password, else public.
				commentStatus: p.comment_status || 'open',
				pingStatus: p.ping_status || 'open',
				password: p.password || '',
				visibility: p.status === 'private' ? 'private' : ( p.password ? 'password' : 'public' ),
				sticky: !! p.sticky,
				serverSticky: !! p.sticky,
				supportsSticky: 'sticky' in p,
				supportsDiscussion: 'comment_status' in p,
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
				excerpt: ( p.excerpt && ( p.excerpt.raw != null ? p.excerpt.raw : '' ) ) || '',
				supportsExcerpt: 'excerpt' in p,
				// Post format: present only when the type supports post-formats
				// AND the active theme declares formats (B.postFormats non-empty),
				// mirroring wp-admin's Format box.
				format: p.format || 'standard',
				formatDirty: false,
				supportsFormat: ( 'format' in p ) && !! ( B.postFormats && Object.keys( B.postFormats ).length ),
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
			acquireLock( state.editor, false );
			// Local crash net: a snapshot differing from what the server just
			// returned means a session ended before its work was saved (crash,
			// killed tab, dismissed unload warning). Identical means stale.
			try {
				const stored = localStorage.getItem( localNetKey( state.editor ) );
				if ( stored ) {
					const snap = JSON.parse( stored );
					if ( ( snap.content != null && snap.content !== raw ) || snap.title !== state.editor.title ) {
						state.editor.localNet = snap;
					} else {
						localStorage.removeItem( localNetKey( state.editor ) );
					}
				}
			} catch ( e ) {}
			loadEditorPanels( state.editor, p );
			loadPageAttrs( state.editor );
			loadEditorRevisions( state.editor );
			// A crash or an abandoned session leaves an autosave revision newer
			// than the post — surface it instead of silently forgetting it.
			api( `wp/v2/${ state.editorType }/${ p.id }/autosaves?_fields=id,modified` )
				.then( ( revs ) => {
					const latest = Array.isArray( revs ) && revs[ 0 ];
					if ( latest && p.modified && latest.modified > p.modified
						&& state.editor && state.editor.id === p.id ) {
						state.editor.backup = { id: latest.id, modified: latest.modified };
						if ( state.route === 'editor' ) renderBackupNotice();
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
								updateEditorStats();
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
				date: null, newDate: null, slug: '', slugValue: '', link: '', savedAt: null, categoryIds: new Set(),
				tagIds: new Set(), tags: [],
				revisions: null, panels: null,
				commentStatus: 'open', pingStatus: 'open', password: '', visibility: 'public',
				sticky: false, serverSticky: false, supportsSticky: newType === 'posts', supportsDiscussion: true,
				supportsThumb: true, featuredMedia: 0, featuredThumb: null,
				parent: 0, menuOrder: 0, template: '', supportsParent: newType === 'pages', supportsOrder: newType === 'pages', templates: null, parentPick: null,
				excerpt: '', supportsExcerpt: newType === 'posts',
			};
			// Crash net for never-saved drafts — anything under the new-post
			// key is by definition work that never reached the server.
			try {
				const stored = localStorage.getItem( localNetKey( state.editor ) );
				if ( stored ) state.editor.localNet = JSON.parse( stored );
			} catch ( e ) {}
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
	let autosaveMaxTimer = null;
	let saveChain = Promise.resolve();
	// Idle: save this long after the last edit stops. Max: while editing never
	// pauses, still save this often. Calm, not keystroke-chasing.
	const AUTOSAVE_IDLE = 15000;
	const AUTOSAVE_MAX = 60000;
	// Statuses whose content is (or is about to be) live. Autosave never writes
	// to the post itself here — it backs up to a WP autosave revision instead,
	// exactly like Gutenberg; only Update/⌘S applies changes to the live post.
	const LIVE_STATUSES = [ 'publish', 'future', 'private' ];

	// Saves are serialized on a chain: a Publish click during an in-flight
	// autosave waits for it instead of being silently dropped. The payload is
	// captured synchronously so a flush-on-navigate reads the editor DOM
	// before the next view replaces it.
	function saveEditor( extra = {} ) {
		const ed = state.editor;
		if ( ! ed ) return Promise.resolve();
		// A lost (or never-held) lock means the other session's copy is
		// canonical — no write path may fire until the lock is taken back.
		if ( ed.lockState === 'taken' || ed.lockState === 'blocked' ) return Promise.resolve();
		// WP rejects a password on a sticky post and validates against the
		// CURRENT sticky state, so un-sticking and setting a password in one
		// request 400s. Commit the un-stick in its own chained request first.
		if ( ed.id && ed.serverSticky && ed.passwordDirty && ed.visibility === 'password' && ed.password ) {
			saveChain = saveChain.then( () => api( `wp/v2/${ ed.type }/${ ed.id }`, { method: 'POST', body: JSON.stringify( { sticky: false } ) } )
				.then( () => { ed.serverSticky = false; } ).catch( () => {} ) );
		}
		const payload = buildSavePayload( ed, extra );
		const capturedAt = Date.now();
		saveChain = saveChain.then( () => doSaveEditor( ed, payload, capturedAt ) );
		return saveChain;
	}

	function buildSavePayload( ed, extra = {} ) {
		// _explicit marks a user-initiated save (Publish / Update / ⌘S / Save
		// draft) vs. an autosave — it gates the private-publish below and must
		// never reach REST.
		const { _explicit, ...rest } = extra;
		const payload = {
			title: $( '#minn-editor-title' ) ? $( '#minn-editor-title' ).value : ed.title,
			...rest,
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
		if ( ed.excerptDirty ) payload.excerpt = ed.excerpt;
		if ( ed.formatDirty ) payload.format = ed.format || 'standard';
		if ( ed.slugDirty ) payload.slug = ed.slugValue;
		if ( ed.commentDirty ) payload.comment_status = ed.commentStatus;
		if ( ed.pingDirty ) payload.ping_status = ed.pingStatus;
		// Never send sticky with password protection — they're mutually
		// exclusive and the un-stick is committed separately (see saveEditor).
		if ( ed.stickyDirty && ed.visibility !== 'password' ) payload.sticky = ed.sticky;
		// Password rides its own field; a private post must have no password
		// (the two are mutually exclusive in WordPress).
		if ( ed.passwordDirty ) payload.password = ed.visibility === 'password' ? ed.password : '';
		// "Private" is a STATUS, not a field. Only an EXPLICIT save applies it —
		// otherwise a draft's autosave would silently publish it private
		// (private is a live status), breaking "autosave never auto-publishes".
		if ( _explicit && ed.visibilityDirty && ed.visibility === 'private' ) {
			payload.status = 'private';
		} else if ( _explicit && ed.visibilityDirty && ed.status === 'private' && ed.visibility !== 'private' ) {
			// Leaving private on an already-private post → back to published.
			payload.status = 'publish';
		}
		return payload;
	}

	async function doSaveEditor( ed, payload, capturedAt ) {
		state.saving = true;
		try {
			let p;
			if ( ed.id ) {
				p = await api( `wp/v2/${ ed.type }/${ ed.id }`, { method: 'POST', body: JSON.stringify( payload ) } );
			} else {
				payload.status = payload.status || 'draft';
				p = await api( `wp/v2/${ ed.type }`, { method: 'POST', body: JSON.stringify( payload ) } );
				// The crash-net snapshot written under the new-post key follows
				// the post to its permanent key before ed.id changes the key.
				try {
					const newKey = localNetKey( ed );
					const snap = localStorage.getItem( newKey );
					ed.id = p.id;
					if ( snap ) {
						localStorage.setItem( localNetKey( ed ), snap );
						localStorage.removeItem( newKey );
					}
				} catch ( e ) {
					ed.id = p.id;
				}
				acquireLock( ed, false );
				// Only rewrite the URL if this editor is still on screen — a
				// flushed autosave may resolve after the user navigated away.
				if ( state.route === 'editor' && state.editor === ed ) {
					state.editorId = p.id;
					setPath( `editor/${ ed.type }/${ p.id }`, true );
				}
			}
			ed.status = p.status;
			ed.slug = '/' + ( p.slug || '' );
			if ( 'slug' in p ) ed.slugValue = p.slug || '';
			if ( 'comment_status' in p ) ed.commentStatus = p.comment_status;
			if ( 'ping_status' in p ) ed.pingStatus = p.ping_status;
			if ( 'password' in p ) ed.password = p.password || '';
			if ( 'sticky' in p ) { ed.sticky = !! p.sticky; ed.serverSticky = !! p.sticky; }
			if ( 'format' in p ) ed.format = p.format || 'standard';
			ed.visibility = p.status === 'private' ? 'private' : ( ed.password ? 'password' : 'public' );
			ed.link = p.link;
			if ( p.date ) ed.date = p.date;
			if ( payload.date ) ed.newDate = null;
			ed.savedAt = Date.now();
			ed.dirty = false;
			localNetClear( ed, capturedAt );
			ed.panelDirty = {};
			ed.featuredDirty = false;
			ed.parentDirty = false;
			ed.templateDirty = false;
			ed.orderDirty = false;
			ed.excerptDirty = false;
			ed.formatDirty = false;
			ed.slugDirty = false;
			ed.commentDirty = false;
			ed.pingDirty = false;
			ed.passwordDirty = false;
			ed.stickyDirty = false;
			ed.visibilityDirty = false;
			state.cache.content = null;
			// Manual save/update/publish creates a WP revision — refresh the
			// History card so it appears without a full page reload.
			if ( ed.id ) loadEditorRevisions( ed );
			renderEditorSide();
			renderTopbar();
		} catch ( e ) {
			toast( e.message, true );
		}
		state.saving = false;
	}

	// History card rows from WP's newest-first revisions list.
	//
	// WP stores a revision of the post AFTER each save, so revs[0] is always a
	// mirror of the live post when the editor is clean — hide it (identical
	// diffs). When dirty, keep it as "last save" vs unsaved edits.
	//
	// Labels use when the version was *superseded*, not when it was written:
	// revs[i] was replaced by revs[i-1], so revs[i-1].modified is the moment
	// that change landed. After a save, the top visible row therefore reads
	// "just now" instead of the previous save's age (Austin, 2026-07-09).
	function historyRowsFor( ed ) {
		const revs = ed.revisions;
		if ( ! revs || ! revs.length ) return [];
		const rows = [];
		for ( let i = 0; i < revs.length; i++ ) {
			if ( i === 0 && ! ed.dirty ) continue;
			rows.push( {
				id: revs[ i ].id,
				author: revs[ i ].author,
				when: i === 0 ? revs[ i ].modified : revs[ i - 1 ].modified,
			} );
		}
		return rows;
	}

	// Revision history for the History sidebar card. Types without revision
	// support 404 — that's fine. Revisions expose an `author` ID but no
	// author link, so _embed can't resolve names — look them up via users.
	// Called on editor open AND after each successful save so new revisions
	// land without a refresh.
	function loadEditorRevisions( ed ) {
		if ( ! ed || ! ed.id ) return Promise.resolve();
		const type = ed.type;
		const id = ed.id;
		return api( `wp/v2/${ type }/${ id }/revisions?per_page=6&_fields=id,modified,author` )
			.then( async ( revs ) => {
				if ( ! Array.isArray( revs ) ) revs = [];
				const names = {};
				if ( B.user && B.user.id ) names[ B.user.id ] = B.user.name;
				const unknown = [ ...new Set( revs.map( ( r ) => r.author ).filter( ( a ) => a > 0 && ! names[ a ] ) ) ];
				if ( unknown.length ) {
					await api( `wp/v2/users?include=${ unknown.join( ',' ) }&_fields=id,name` )
						.then( ( users ) => ( Array.isArray( users ) ? users : [] ).forEach( ( u ) => { names[ u.id ] = u.name; } ) )
						.catch( () => {} );
				}
				if ( state.editor && state.editor.id === id && state.editor.type === type ) {
					state.editor.revisions = revs.map( ( r ) => ( {
						id: r.id,
						modified: r.modified,
						author: names[ r.author ] || '',
					} ) );
					if ( state.route === 'editor' ) renderEditorSide();
				}
			} )
			.catch( () => {} );
	}

	// Classic-mode save: innerHTML, but with highlight spans stripped from code
	// blocks so decoration never reaches the database.
	function classicHtml( body ) {
		const clone = body.cloneNode( true );
		cleanBoundaryNbsp( clone );
		Array.from( clone.children ).forEach( cleanLeadingNbsp );
		modernizeStrikes( clone );
		$$( '[data-minn-bkt]', clone ).forEach( ( el ) => el.removeAttribute( 'data-minn-bkt' ) );
		// In-flight uploads hold only a blob: URL; empty captions are chrome.
		$$( '[data-minn-upload]', clone ).forEach( ( el ) => el.remove() );
		$$( 'figcaption', clone ).forEach( ( fc ) => {
			if ( ! fc.textContent.trim() ) fc.remove();
		} );
		// Chip hover-highlighting parks a border-color inline style on the
		// figure/table/pre — never store it.
		$$( ':scope > figure, :scope > table, :scope > pre', clone ).forEach( ( el ) => {
			el.style.borderColor = '';
			if ( ! el.getAttribute( 'style' ) ) el.removeAttribute( 'style' );
		} );
		// Media-less figure husks (undoable image deletes) serialize to nothing.
		$$( 'figure', clone ).forEach( ( f ) => {
			if ( ! f.querySelector( 'img, video, audio, table, iframe' ) && ! f.textContent.trim() ) f.remove();
		} );
		$$( 'pre', clone ).forEach( ( pre ) => {
			const lang = codeLangOf( pre );
			const text = codeTextOf( pre );
			pre.removeAttribute( 'data-hl' );
			pre.innerHTML = `<code${ lang !== 'auto' ? ` class="language-${ lang }"` : '' }>${ esc( text ) }</code>`;
		} );
		// The trailing click-affordance paragraph (ensureTrailingParagraph) is
		// chrome — a terminal empty paragraph never persists.
		const lastEl = clone.lastElementChild;
		if ( lastEl && lastEl.tagName === 'P' && ! lastEl.textContent.trim() && ! lastEl.querySelector( 'img' ) ) lastEl.remove();
		return clone.innerHTML;
	}

	function clearAutosaveTimers() {
		clearTimeout( autosaveTimer );
		clearTimeout( autosaveMaxTimer );
		autosaveTimer = autosaveMaxTimer = null;
	}

	function autosaveFire() {
		clearAutosaveTimers();
		autosaveNow();
	}

	function scheduleAutosave() {
		const ed = state.editor;
		if ( ! ed ) return;
		ed.dirty = true;
		ed.editedAt = Date.now();
		updateSavedRow();
		updateEditorStats();
		clearTimeout( autosaveTimer );
		autosaveTimer = setTimeout( autosaveFire, AUTOSAVE_IDLE );
		if ( ! autosaveMaxTimer ) autosaveMaxTimer = setTimeout( autosaveFire, AUTOSAVE_MAX );
		localNetSchedule();
	}

	// A pending autosave leaves with the user — fired immediately on SPA
	// navigation away from the editor (browser unload warns instead).
	function flushAutosave() {
		if ( ! autosaveTimer && ! autosaveMaxTimer ) return;
		clearAutosaveTimers();
		autosaveNow();
	}

	function autosaveNow() {
		const ed = state.editor;
		if ( ! ed ) return;
		// A lost lock stops every write path — see saveEditor.
		if ( ed.lockState === 'taken' || ed.lockState === 'blocked' ) return;
		// Never auto-publish, never touch live content: published/scheduled/
		// private posts back up to an autosave revision; drafts save in place.
		if ( ed.id && LIVE_STATUSES.includes( ed.status ) ) return autosaveBackup( ed );
		return saveEditor();
	}

	async function autosaveBackup( ed ) {
		// Locked bodies are never serialized, and a title-only autosave revision
		// would read as empty content if ever restored — skip; Update covers it.
		if ( ed.noAutosave || ed.mode === 'locked' ) return;
		const payload = { title: $( '#minn-editor-title' ) ? $( '#minn-editor-title' ).value : ed.title };
		const capturedAt = Date.now();
		const body = $( '#minn-editor-body' );
		if ( body ) payload.content = ed.mode === 'blocks' ? serializeToBlocks( body, ed.islands ) : classicHtml( body );
		if ( ed.excerptDirty ) payload.excerpt = ed.excerpt;
		try {
			await api( `wp/v2/${ ed.type }/${ ed.id }/autosaves`, { method: 'POST', body: JSON.stringify( payload ) } );
			ed.autosavedAt = Date.now();
			localNetClear( ed, capturedAt );
			updateSavedRow();
		} catch ( e ) {
			// Types without revision support have no autosaves route — from here
			// on this post only saves manually (Save/Update/⌘S).
			ed.noAutosave = true;
		}
	}

	function savedState( ed ) {
		if ( ed.dirty ) {
			// Live posts whose latest edits made it into an autosave revision
			// are crash-safe even though the live copy hasn't changed — say so.
			return ed.autosavedAt && ed.autosavedAt >= ( ed.editedAt || 0 )
				? { text: 'Unsaved · backed up', cls: 'amber' }
				: { text: 'Unsaved changes', cls: 'amber' };
		}
		if ( ed.savedAt ) return { text: timeAgo( new Date( ed.savedAt ).toISOString() ), cls: 'green' };
		return { text: ed.id ? '—' : 'Not yet', cls: 'green' };
	}

	function updateSavedRow() {
		const ed = state.editor;
		const el = $( '#minn-saved-state' );
		if ( ! el || ! ed ) return;
		const s = savedState( ed );
		el.textContent = s.text;
		el.className = 'minn-side-val ' + s.cls;
	}

	// Word count + reading time for the sticky pill under the editor body.
	// Island PREVIEWS count (they're real content); island chrome (the ⚙ chip,
	// the "dynamic block" placeholder) doesn't.
	// A pre/table/figure/island/quote as the LAST block traps the caret —
	// nothing below it to click to keep writing (typing ``` as the last act
	// converts the last paragraph INTO a pre and springs the trap). Keep one
	// empty paragraph after any terminal non-paragraph block; empty
	// paragraphs never serialize, so it's pure affordance. Node INSERTION is
	// undo-safe (unlike text mutation — rule at cleanLeadingNbsp).
	function ensureTrailingParagraph( body ) {
		if ( ! body || body.getAttribute( 'contenteditable' ) === 'false' ) return;
		// Empty body (new/blank posts, or after select-all + delete): Chrome
		// will put the next keystrokes as a bare text node under the
		// contenteditable. Slash-menu detection needs a real block element,
		// and serialize expects Gutenberg-shaped paragraphs — seed or wrap.
		const last = body.lastElementChild;
		if ( ! last ) {
			const p = document.createElement( 'p' );
			// Preserve any bare text the user already typed (don't wipe it on
			// the next stats tick); only seed <br> when truly empty.
			while ( body.firstChild ) p.appendChild( body.firstChild );
			if ( ! p.childNodes.length ) p.innerHTML = '<br>';
			body.appendChild( p );
			return;
		}
		// DETAILS too — if a live <details> ever lands outside an island, the
		// caret still needs a landing <p> after it (same for any non-typing
		// block tag we don't treat as prose).
		if ( /^(PRE|TABLE|FIGURE|HR|BLOCKQUOTE|DETAILS)$/.test( last.tagName )
			|| last.classList.contains( 'minn-block-island' )
			|| last.getAttribute( 'contenteditable' ) === 'false' ) {
			const p = document.createElement( 'p' );
			p.innerHTML = '<br>';
			body.appendChild( p );
		}
	}

	function updateEditorStats() {
		const el = $( '#minn-editor-stats' );
		const body = $( '#minn-editor-body' );
		if ( ! el || ! body ) return;
		ensureTrailingParagraph( body );
		const walker = document.createTreeWalker( body, NodeFilter.SHOW_TEXT, {
			acceptNode: ( n ) => n.parentNode.closest( '.minn-island-chip, .minn-island-empty, .minn-shortcode-label, .minn-shortcode-input' )
				? NodeFilter.FILTER_REJECT
				: NodeFilter.FILTER_ACCEPT,
		} );
		let text = '';
		while ( walker.nextNode() ) text += walker.currentNode.textContent + ' ';
		const words = ( text.match( /\S+/g ) || [] ).length;
		// 225 wpm — the middle of the usual 200–250 adult-reading estimates.
		const mins = Math.max( 1, Math.round( words / 225 ) );
		el.innerHTML = words
			? `<b>${ words.toLocaleString() }</b> words&nbsp;· <b>${ mins }</b> min read`
			: '<b>0</b> words';
		syncTableChips();
		updateOutline();
		// Focus mode and the find bar ride the same typing cadence.
		syncFocusDim();
		focusTypewriter();
		syncFindBar();
	}

	/* ===== Outline panel ===== */
	// Headings as a clickable ToC in the sidebar — structure feedback while
	// drafting. Rides the updateEditorStats cadence (typing, island swaps),
	// signature-gated so the list only rebuilds when headings actually change.
	function outlineHeads( body ) {
		return $$( 'h1,h2,h3,h4,h5,h6', body )
			.filter( ( h ) => ! h.closest( '.minn-island-chip, .minn-island-empty' ) && h.textContent.trim() );
	}

	function updateOutline() {
		const card = $( '#minn-outline-card' );
		const list = $( '#minn-outline' );
		const body = $( '#minn-editor-body' );
		if ( ! card || ! list || ! body ) return;
		const heads = outlineHeads( body );
		card.hidden = ! heads.length;
		if ( ! heads.length ) { list.dataset.sig = ''; list.innerHTML = ''; return; }
		const sig = heads.map( ( h ) => h.tagName + '|' + h.textContent.trim() ).join( '\n' );
		if ( list.dataset.sig === sig ) return;
		list.dataset.sig = sig;
		// Indent relative to the shallowest level present, so an h2-only post
		// isn't needlessly inset.
		const min = Math.min( ...heads.map( ( h ) => +h.tagName[ 1 ] ) );
		list.innerHTML = heads.map( ( h, i ) =>
			`<button class="minn-outline-row" style="--olvl:${ Math.min( +h.tagName[ 1 ] - min, 3 ) }" data-oi="${ i }" title="${ esc( h.tagName.toLowerCase() ) }">${ esc( h.textContent.trim() ) }</button>` ).join( '' );
	}

	/* ===== Focus mode ===== */
	// Fade everything but the current paragraph + typewriter scroll. The fade
	// is TWO fixed overlays on document.body above/below the caret block —
	// never a class or style on content (the typing surface serializes).
	// z-30: under the toolbar (35), stats pill (40) and chips (69+), so the
	// controls stay crisp while prose, sidebar and chrome-less areas dim.
	let focusDims = null, focusRaf = 0;

	function focusModeOn() {
		return !! ( state.editor && state.editor.focus && state.route === 'editor' );
	}

	function focusBlockOf() {
		const body = $( '#minn-editor-body' );
		const sel = window.getSelection();
		let n = sel && sel.rangeCount ? sel.anchorNode : null;
		while ( n && n.nodeType !== Node.ELEMENT_NODE ) n = n.parentNode;
		if ( ! n || ! body || ! body.contains( n ) || n === body ) return null;
		// Caret-line granularity: the nearest prose block, not the whole list
		// or quote; falls back to the top-level block for anything exotic.
		const c = n.closest( 'p, h1, h2, h3, h4, h5, h6, li, pre, figcaption, td, th, figure' );
		if ( c && body.contains( c ) && c !== body ) return c;
		let t = n;
		while ( t.parentNode && t.parentNode !== body ) t = t.parentNode;
		return t.parentNode === body ? t : null;
	}

	function removeFocusDim() {
		if ( focusDims ) { focusDims.forEach( ( d ) => d.remove() ); focusDims = null; }
		document.body.classList.remove( 'minn-focus-zen' );
	}

	// First block actually in view (under the sticky toolbar) — the band's
	// anchor when focus mode starts before the caret has landed anywhere.
	function firstVisibleBlock() {
		const body = $( '#minn-editor-body' );
		if ( ! body ) return null;
		const kids = Array.from( body.children );
		return kids.find( ( el ) => {
			const r = el.getBoundingClientRect();
			return r.height > 0 && r.bottom > 120;
		} ) || kids[ 0 ] || null;
	}

	// instant=true glues the band to the content with no transition — required
	// while SCROLLING (an animated band would chase the page and rubber-band);
	// caret moves keep the glide.
	function syncFocusDim( instant ) {
		if ( ! focusModeOn() ) { removeFocusDim(); return; }
		// No caret in the body yet (fresh toggle, or focus persisted across a
		// load) → band the first visible block so the mode never LOOKS broken.
		// Once a band exists, a caret elsewhere (palette, sidebar) holds it.
		const blk = focusBlockOf() || ( ! focusDims ? firstVisibleBlock() : null );
		if ( ! blk ) return;
		if ( ! focusDims ) {
			focusDims = [ document.createElement( 'div' ), document.createElement( 'div' ) ];
			focusDims.forEach( ( d ) => { d.className = 'minn-focus-dim'; document.body.appendChild( d ); } );
		}
		const r = blk.getBoundingClientRect();
		const pad = 8;
		focusDims.forEach( ( d ) => d.classList.toggle( 'instant', !! instant ) );
		focusDims[ 0 ].style.cssText = `top:0;height:${ Math.max( 0, r.top - pad ) }px;`;
		focusDims[ 1 ].style.cssText = `top:${ r.bottom + pad }px;height:${ Math.max( 0, innerHeight - r.bottom - pad ) }px;`;
	}

	let focusQueuedInstant = false;
	function queueFocusDim( instant ) {
		if ( ! focusDims ) return;
		focusQueuedInstant = focusQueuedInstant || instant === true;
		if ( focusRaf ) return;
		focusRaf = requestAnimationFrame( () => {
			focusRaf = 0;
			const i = focusQueuedInstant;
			focusQueuedInstant = false;
			syncFocusDim( i );
		} );
	}

	// Typewriter scroll: while typing, keep the caret block inside the middle
	// band of the scrollport. Runs on the input cadence only — reacting to
	// scroll events here would fight the reader's own scrolling.
	function focusTypewriter() {
		if ( ! focusModeOn() ) return;
		const sc = $( '.minn-scroll' );
		const blk = focusBlockOf();
		if ( ! sc || ! blk ) return;
		const port = sc.getBoundingClientRect();
		const r = blk.getBoundingClientRect();
		const center = port.top + port.height / 2;
		const blkCenter = ( r.top + r.bottom ) / 2;
		if ( Math.abs( blkCenter - center ) > port.height * 0.18 ) {
			// A capped instant step per keystroke, not scrollTo(smooth) —
			// Chrome's caret-reveal cancels smooth programmatic scrolls on
			// every insertion, freezing them at ~0 progress (probed). Small
			// steps converge exponentially across keystrokes and read as a
			// gentle drift; a hard full-delta jump was the "jumpy" complaint.
			const delta = blkCenter - center;
			sc.scrollTop += Math.max( -56, Math.min( 56, delta * 0.35 ) );
		}
	}

	function toggleFocusMode() {
		const ed = state.editor;
		if ( ! ed || ed.mode === 'locked' ) return;
		ed.focus = ! ed.focus;
		try { localStorage.setItem( 'minn-focus', ed.focus ? '1' : '' ); } catch ( e ) { /* private mode */ }
		// View modes are mutually exclusive — entering one exits the other
		// silently (zen hides the whole sidebar; outline mode under it is
		// meaningless). Only the entering mode toasts.
		if ( ed.focus && ed.outlineMode ) {
			ed.outlineMode = false;
			try { localStorage.setItem( 'minn-outline-mode', '' ); } catch ( e ) { /* private mode */ }
			removeOutlineMode();
		}
		toast( ed.focus ? 'Focus mode on — ⌘⇧D to leave' : 'Focus mode off' );
		if ( ed.focus ) {
			// Zen: collapse the nav and the editor sidebar — nothing but the
			// writing. The toolbar (with this toggle) and ⌘S stay.
			document.body.classList.add( 'minn-focus-zen' );
			// Toggled with no caret in the body: seat it at the first visible
			// block so the band appears AND typing starts there. Islands are
			// contenteditable=false — band-only for those, no caret.
			const body = $( '#minn-editor-body' );
			if ( body && ! focusBlockOf() ) {
				const blk = firstVisibleBlock();
				if ( blk && ! blk.closest( '.minn-block-island' ) && blk.getAttribute( 'contenteditable' ) !== 'false' ) {
					body.focus( { preventScroll: true } );
					const r = document.createRange();
					r.setStart( blk, 0 );
					r.collapse( true );
					const s = window.getSelection();
					s.removeAllRanges();
					s.addRange( r );
				}
			}
			syncFocusDim();
		} else {
			removeFocusDim();
		}
		// The collapse/restore transition (250ms) reflows everything the
		// fixed-position chrome is anchored to — re-sync after it settles.
		setTimeout( updateEditorStats, 300 );
	}

	document.addEventListener( 'selectionchange', () => queueFocusDim( false ) );
	document.addEventListener( 'scroll', () => queueFocusDim( true ), true );
	window.addEventListener( 'resize', () => queueFocusDim( true ) );

	/* ===== Outline mode ===== */
	// Focus mode's structural sibling: hide the nav and every sidebar card
	// except the Outline — just the writing and the document's shape. Pure
	// chrome (a body class), persisted like focus mode.
	function toggleOutlineMode() {
		const ed = state.editor;
		if ( ! ed ) return;
		ed.outlineMode = ! ed.outlineMode;
		try { localStorage.setItem( 'minn-outline-mode', ed.outlineMode ? '1' : '' ); } catch ( e ) { /* private mode */ }
		// Mutually exclusive with focus mode — see toggleFocusMode.
		if ( ed.outlineMode && ed.focus ) {
			ed.focus = false;
			try { localStorage.setItem( 'minn-focus', '' ); } catch ( e ) { /* private mode */ }
			removeFocusDim();
		}
		toast( ed.outlineMode ? 'Outline mode on — ⌘⇧O to leave' : 'Outline mode off' );
		document.body.classList.toggle( 'minn-outline-mode', ed.outlineMode );
	}

	function removeOutlineMode() {
		document.body.classList.remove( 'minn-outline-mode' );
	}

	/* ===== Find & replace (editor) ===== */
	// Searches the text writers SEE — text nodes across inline formatting —
	// never the underlying markup. Islands and any contenteditable=false
	// subtree are excluded (byte-identity is the islands contract), and a
	// match never crosses a block boundary. Highlights are overlay rects
	// INSIDE the scroller at content coordinates (the outline-ping rule:
	// nothing decorative may touch the typing surface, and overlays that
	// track content must ride the scroll natively). Replacements select the
	// match Range and go through execCommand insertText/delete, so every
	// replace lives on the native undo stack and ⌘Z walks back.
	let findState = null; // { query, replace, matchCase, matches: Range[], idx }
	let findRefreshTimer = 0;

	const FIND_BLOCKS = 'p,h1,h2,h3,h4,h5,h6,li,td,th,pre,figcaption,blockquote,summary';

	// Contiguous text runs grouped by nearest block, so "quick brown" matches
	// across qui<strong>ck bro</strong>wn but never across two paragraphs or
	// two table cells.
	function findSegments( body ) {
		const segs = [];
		let cur = null, curBlock = null;
		const walker = document.createTreeWalker( body, NodeFilter.SHOW_TEXT, {
			acceptNode: ( n ) => n.parentElement && ! n.parentElement.closest(
				'[contenteditable="false"], .minn-block-island, .minn-island-chip, .minn-island-empty' )
				? NodeFilter.FILTER_ACCEPT
				: NodeFilter.FILTER_REJECT,
		} );
		while ( walker.nextNode() ) {
			const node = walker.currentNode;
			if ( ! node.textContent.length ) continue;
			const block = node.parentElement.closest( FIND_BLOCKS ) || body;
			if ( ! cur || block !== curBlock ) {
				cur = { text: '', parts: [] };
				segs.push( cur );
				curBlock = block;
			}
			cur.parts.push( { node, start: cur.text.length } );
			cur.text += node.textContent;
		}
		return segs;
	}

	// Segment offsets → a live Range. End offsets sitting on a node boundary
	// bind to the EARLIER node so the range never leaks into a neighbor.
	function findSegRange( seg, from, to ) {
		const locate = ( off, isEnd ) => {
			for ( const p of seg.parts ) {
				const end = p.start + p.node.textContent.length;
				if ( isEnd ? off > p.start && off <= end : off >= p.start && off < end ) {
					return [ p.node, off - p.start ];
				}
			}
			return null;
		};
		const s = locate( from, false );
		const e = locate( to, true );
		if ( ! s || ! e ) return null;
		const r = document.createRange();
		r.setStart( s[ 0 ], s[ 1 ] );
		r.setEnd( e[ 0 ], e[ 1 ] );
		return r;
	}

	function findComputeMatches() {
		const f = findState;
		const body = $( '#minn-editor-body' );
		f.matches = [];
		const q = ( f.query || '' ).replace( /\u00a0/g, ' ' );
		if ( ! body || ! q ) return;
		// Escaped-literal regex: under 'i' the reported index stays true to
		// the original string, while lowercasing a haystack can CHANGE ITS
		// LENGTH for some Unicode and skew every offset after it.
		const rx = new RegExp( q.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ), f.matchCase ? 'g' : 'gi' );
		for ( const seg of findSegments( body ) ) {
			const hay = seg.text.replace( /\u00a0/g, ' ' ); // nbsp ≡ space (boundary artifacts)
			rx.lastIndex = 0;
			let m;
			while ( ( m = rx.exec( hay ) ) ) {
				const r = findSegRange( seg, m.index, m.index + m[ 0 ].length );
				if ( r ) f.matches.push( r );
			}
		}
	}

	function renderFindMarks() {
		const wrap = $( '#minn-find-marks' );
		const sc = $( '.minn-scroll' );
		if ( ! findState || ! findState.matches.length || ! sc ) {
			if ( wrap ) wrap.remove();
			return;
		}
		let w = wrap;
		if ( ! w ) {
			w = document.createElement( 'div' );
			w.id = 'minn-find-marks';
			sc.appendChild( w );
		}
		const scRect = sc.getBoundingClientRect();
		const cap = 300; // marks are chrome; past this only the count grows
		let html = '';
		const draw = ( r, i ) => {
			for ( const rect of r.getClientRects() ) {
				if ( rect.width < 1 && rect.height < 1 ) continue;
				html += `<div class="minn-find-mark${ i === findState.idx ? ' cur' : '' }" style="top:${ ( rect.top - scRect.top + sc.scrollTop ).toFixed( 1 ) }px;left:${ ( rect.left - scRect.left + sc.scrollLeft ).toFixed( 1 ) }px;width:${ rect.width.toFixed( 1 ) }px;height:${ rect.height.toFixed( 1 ) }px"></div>`;
			}
		};
		findState.matches.slice( 0, cap ).forEach( draw );
		if ( findState.idx >= cap ) draw( findState.matches[ findState.idx ], findState.idx );
		w.innerHTML = html;
	}

	function findUpdateCount() {
		const el = $( '#minn-find-count' );
		if ( ! el || ! findState ) return;
		const n = findState.matches.length;
		el.textContent = n ? `${ findState.idx + 1 }/${ n }` : ( findState.query ? '0' : '' );
	}

	function findScrollCurrent() {
		const sc = $( '.minn-scroll' );
		const m = findState && findState.matches[ findState.idx ];
		if ( ! sc || ! m ) return;
		const rect = m.getBoundingClientRect();
		const port = sc.getBoundingClientRect();
		// Instant step — smooth programmatic scrolls die to Chrome's
		// caret-reveal (rule at focusTypewriter), and the marks ride content
		// coordinates so no re-render is needed.
		if ( rect.top < port.top + 90 || rect.bottom > port.bottom - 60 ) {
			sc.scrollTop += rect.top - ( port.top + port.height * 0.35 );
		}
	}

	function findGoto( delta ) {
		const f = findState;
		if ( ! f || ! f.matches.length ) return;
		f.idx = ( f.idx + delta + f.matches.length ) % f.matches.length;
		renderFindMarks();
		findUpdateCount();
		findScrollCurrent();
	}

	// afterRange: land on the first match at/after that point — a replace
	// whose replacement contains the query ("cat" → "cats") must advance
	// past itself, not re-match in place forever.
	function findRefresh( afterRange ) {
		const f = findState;
		if ( ! f ) return;
		const prev = f.idx;
		findComputeMatches();
		if ( afterRange ) {
			f.idx = f.matches.findIndex( ( m ) => m.compareBoundaryPoints( Range.START_TO_START, afterRange ) >= 0 );
			if ( f.idx === -1 ) f.idx = 0;
		} else {
			f.idx = Math.min( prev, Math.max( 0, f.matches.length - 1 ) );
		}
		renderFindMarks();
		findUpdateCount();
	}

	function queueFindRefresh() {
		clearTimeout( findRefreshTimer );
		findRefreshTimer = setTimeout( () => findRefresh(), 120 );
	}

	// Rides the updateEditorStats cadence: typing reflows lines under the
	// marks and zen/nav transitions move the column the bar is anchored to.
	function syncFindBar() {
		if ( ! $( '#minn-find-bar' ) ) return;
		if ( state.route !== 'editor' || ! state.editor ) { closeFindBar(); return; }
		positionFindBar();
		queueFindRefresh();
	}

	function positionFindBar() {
		const bar = $( '#minn-find-bar' );
		const body = $( '#minn-editor-body' );
		if ( ! bar || ! body ) return;
		const tb = $( '.minn-editor-toolbar' );
		const bRect = body.getBoundingClientRect();
		const top = ( tb ? tb.getBoundingClientRect().bottom : bRect.top ) + 10;
		bar.style.top = `${ Math.max( 60, top ) }px`;
		bar.style.left = `${ Math.max( 12, bRect.right - bar.offsetWidth ) }px`;
	}

	function findEditableBody() {
		const body = $( '#minn-editor-body' );
		return body && body.getAttribute( 'contenteditable' ) !== 'false' ? body : null;
	}

	function openFindBar() {
		const ed = state.editor;
		if ( state.route !== 'editor' || ! ed || ed.mode === 'locked' ) return;
		let bar = $( '#minn-find-bar' );
		if ( ! bar ) {
			bar = document.createElement( 'div' );
			bar.id = 'minn-find-bar';
			bar.className = 'minn-find-bar';
			bar.innerHTML = `
				<div class="minn-find-row">
					<input id="minn-find-input" type="text" placeholder="Find in post" spellcheck="false" autocomplete="off">
					<span id="minn-find-count"></span>
					<button type="button" class="minn-find-btn" id="minn-find-prev" title="Previous match (⇧Enter)">↑</button>
					<button type="button" class="minn-find-btn" id="minn-find-next" title="Next match (Enter)">↓</button>
					<button type="button" class="minn-find-btn" id="minn-find-case" title="Match case">Aa</button>
					<button type="button" class="minn-find-btn" id="minn-find-close" title="Close (Esc)">×</button>
				</div>
				<div class="minn-find-row">
					<input id="minn-find-replace" type="text" placeholder="Replace with" spellcheck="false" autocomplete="off">
					<button type="button" class="minn-find-act" id="minn-find-rep">Replace</button>
					<button type="button" class="minn-find-act" id="minn-find-repall" title="Replace all matches">All</button>
				</div>`;
			document.body.appendChild( bar );
			$( '#minn-find-input' ).addEventListener( 'input', () => {
				findState.query = $( '#minn-find-input' ).value;
				findState.idx = 0;
				findRefresh();
				if ( findState.matches.length ) findScrollCurrent();
			} );
			$( '#minn-find-replace' ).addEventListener( 'input', () => {
				findState.replace = $( '#minn-find-replace' ).value;
			} );
			bar.addEventListener( 'keydown', ( e ) => {
				if ( e.key === 'Escape' ) {
					// Ours alone — the global Esc would also close modals.
					e.preventDefault();
					e.stopPropagation();
					closeFindBar( true );
					return;
				}
				if ( e.key !== 'Enter' ) return;
				e.preventDefault();
				if ( e.target.id === 'minn-find-replace' ) findReplaceCurrent();
				else findGoto( e.shiftKey ? -1 : 1 );
			} );
			$( '#minn-find-prev' ).addEventListener( 'click', () => findGoto( -1 ) );
			$( '#minn-find-next' ).addEventListener( 'click', () => findGoto( 1 ) );
			$( '#minn-find-case' ).addEventListener( 'click', () => {
				findState.matchCase = ! findState.matchCase;
				$( '#minn-find-case' ).classList.toggle( 'on', findState.matchCase );
				findState.idx = 0;
				findRefresh();
			} );
			$( '#minn-find-close' ).addEventListener( 'click', () => closeFindBar( true ) );
			$( '#minn-find-rep' ).addEventListener( 'click', findReplaceCurrent );
			$( '#minn-find-repall' ).addEventListener( 'click', findReplaceAll );
		}
		if ( ! findState ) findState = { query: '', replace: '', matchCase: false, matches: [], idx: 0 };
		// ⌘⇧F on selected text finds that text — muscle memory everywhere.
		const body = $( '#minn-editor-body' );
		const sel = window.getSelection();
		if ( body && sel.rangeCount && ! sel.isCollapsed && body.contains( sel.anchorNode ) ) {
			const t = sel.toString().replace( /\s+/g, ' ' ).trim();
			if ( t && t.length <= 120 ) findState.query = t;
		}
		const inp = $( '#minn-find-input' );
		inp.value = findState.query;
		$( '#minn-find-replace' ).value = findState.replace;
		$( '#minn-find-case' ).classList.toggle( 'on', findState.matchCase );
		positionFindBar();
		findRefresh();
		if ( findState.matches.length ) findScrollCurrent();
		inp.focus();
		inp.select();
	}

	function closeFindBar( toCaret ) {
		clearTimeout( findRefreshTimer );
		const bar = $( '#minn-find-bar' );
		const marks = $( '#minn-find-marks' );
		if ( bar ) bar.remove();
		if ( marks ) marks.remove();
		// Esc hands the current match to the editor selected — find a spot,
		// close, keep writing (or type right over it).
		const body = findEditableBody();
		const m = findState && findState.matches[ findState.idx ];
		if ( toCaret && m && body && m.startContainer.isConnected ) {
			body.focus( { preventScroll: true } );
			const sel = window.getSelection();
			sel.removeAllRanges();
			sel.addRange( m.cloneRange() );
		}
		findState = null;
	}

	function findReplaceCurrent() {
		const f = findState;
		const body = findEditableBody();
		if ( ! f || ! body || ! f.matches.length ) return;
		const m = f.matches[ f.idx ];
		if ( ! m.startContainer.isConnected ) { findRefresh(); return; } // stale after a re-render
		const focused = document.activeElement;
		const sel = window.getSelection();
		body.focus( { preventScroll: true } );
		sel.removeAllRanges();
		sel.addRange( m );
		if ( f.replace ) document.execCommand( 'insertText', false, f.replace );
		else document.execCommand( 'delete' );
		const after = sel.rangeCount ? sel.getRangeAt( 0 ).cloneRange() : null;
		scheduleAutosave();
		findRefresh( after );
		findScrollCurrent();
		if ( focused && focused.closest && focused.closest( '#minn-find-bar' ) ) focused.focus( { preventScroll: true } );
	}

	function findReplaceAll() {
		const f = findState;
		const body = findEditableBody();
		if ( ! f || ! body || ! f.matches.length ) return;
		const list = f.matches.filter( ( m ) => m.startContainer.isConnected );
		if ( ! list.length ) { findRefresh(); return; }
		const focused = document.activeElement;
		const sel = window.getSelection();
		body.focus( { preventScroll: true } );
		// Last-to-first keeps every earlier Range valid while later text
		// mutates; each step is one native undo entry, so ⌘Z walks back.
		for ( let i = list.length - 1; i >= 0; i-- ) {
			sel.removeAllRanges();
			sel.addRange( list[ i ] );
			if ( f.replace ) document.execCommand( 'insertText', false, f.replace );
			else document.execCommand( 'delete' );
		}
		scheduleAutosave();
		findRefresh();
		toast( `Replaced ${ list.length } match${ list.length === 1 ? '' : 'es' }` );
		if ( focused && focused.closest && focused.closest( '#minn-find-bar' ) ) focused.focus( { preventScroll: true } );
	}

	// The bar is fixed and anchored to the sticky toolbar; before the first
	// scroll the toolbar sits at its natural (lower) position, so the bar
	// follows it while the page scrolls. Marks need nothing here — they live
	// in content coordinates.
	let findBarRaf = 0;
	document.addEventListener( 'scroll', () => {
		if ( ! $( '#minn-find-bar' ) || findBarRaf ) return;
		findBarRaf = requestAnimationFrame( () => { findBarRaf = 0; positionFindBar(); } );
	}, true );
	window.addEventListener( 'resize', () => {
		if ( ! $( '#minn-find-bar' ) ) return;
		positionFindBar();
		queueFindRefresh(); // line wraps changed under the marks
	} );

	/* ===== Date-time picker (themed replacement for datetime-local) ===== */
	// Chrome's native calendar is unstyleable and clashes with both themes.
	// A readonly display input carries the machine value ("YYYY-MM-DDTHH:mm",
	// same shape datetime-local produced — the save path is untouched) on
	// input.dataset.dp; the popover writes both and fires onChange.
	let dpPop = null;

	function dpPad( n ) { return String( n ).padStart( 2, '0' ); }

	function dpMachine( d ) {
		return `${ d.getFullYear() }-${ dpPad( d.getMonth() + 1 ) }-${ dpPad( d.getDate() ) }T${ dpPad( d.getHours() ) }:${ dpPad( d.getMinutes() ) }`;
	}

	function dpPretty( machine ) {
		if ( ! machine ) return '';
		const d = new Date( machine );
		if ( isNaN( d ) ) return '';
		return d.toLocaleDateString( undefined, { month: 'short', day: 'numeric', year: 'numeric' } )
			+ ' · ' + d.toLocaleTimeString( undefined, { hour: 'numeric', minute: '2-digit' } );
	}

	// Lenient time parse: "7", "7:30", "7:30 pm", "19:30" → {h, m} (24h).
	function dpParseTime( s ) {
		const m = String( s ).trim().match( /^(\d{1,2})(?::(\d{2}))?\s*(am|pm)?$/i );
		if ( ! m ) return null;
		let h = +m[ 1 ];
		const min = +( m[ 2 ] || 0 );
		const ap = m[ 3 ] && m[ 3 ].toLowerCase();
		if ( min > 59 ) return null;
		if ( ap ) {
			if ( h < 1 || h > 12 ) return null;
			h = ( h % 12 ) + ( 'pm' === ap ? 12 : 0 );
		} else if ( h > 23 ) return null;
		return { h, m: min };
	}

	function hideDatePicker() {
		if ( dpPop ) dpPop.remove();
		dpPop = null;
		document.removeEventListener( 'mousedown', dpAway, true );
	}

	function dpAway( e ) {
		if ( dpPop && ! dpPop.contains( e.target ) && ! e.target.classList.contains( 'minn-dp-input' ) ) hideDatePicker();
	}

	function bindDatePicker( input, onChange ) {
		const commit = ( machine ) => {
			input.dataset.dp = machine || '';
			input.value = dpPretty( machine );
			onChange( machine || null );
		};

		const open = () => {
			hideDatePicker();
			// The selection (or now) seeds both the grid month and the time.
			const sel = input.dataset.dp ? new Date( input.dataset.dp ) : null;
			const seed = sel && ! isNaN( sel ) ? new Date( sel ) : new Date();
			let view = new Date( seed.getFullYear(), seed.getMonth(), 1 );

			dpPop = document.createElement( 'div' );
			dpPop.className = 'minn-dp-pop';
			document.body.appendChild( dpPop );

			const render = () => {
				const selKey = input.dataset.dp ? input.dataset.dp.slice( 0, 10 ) : '';
				const todayKey = dpMachine( new Date() ).slice( 0, 10 );
				const first = new Date( view );
				first.setDate( 1 - first.getDay() ); // back to Sunday
				let days = '';
				const d = new Date( first );
				for ( let i = 0; i < 42; i++ ) {
					const key = dpMachine( d ).slice( 0, 10 );
					days += `<button type="button" class="minn-dp-day${ d.getMonth() !== view.getMonth() ? ' out' : '' }${ key === selKey ? ' sel' : '' }${ key === todayKey ? ' today' : '' }" data-day="${ key }">${ d.getDate() }</button>`;
					d.setDate( d.getDate() + 1 );
				}
				dpPop.innerHTML = `
					<div class="minn-dp-head">
						<span class="minn-dp-month">${ view.toLocaleDateString( undefined, { month: 'long', year: 'numeric' } ) }</span>
						<button type="button" class="minn-dp-nav" data-nav="-1" title="Previous month">‹</button>
						<button type="button" class="minn-dp-nav" data-nav="1" title="Next month">›</button>
					</div>
					<div class="minn-dp-grid">
						${ [ 'S', 'M', 'T', 'W', 'T', 'F', 'S' ].map( ( w ) => `<span class="minn-dp-wd">${ w }</span>` ).join( '' ) }
						${ days }
					</div>
					<div class="minn-dp-time">
						<span class="minn-side-key">Time</span>
						<input type="text" class="minn-input minn-dp-time-input" value="${ esc( seed.toLocaleTimeString( undefined, { hour: 'numeric', minute: '2-digit' } ) ) }" spellcheck="false" autocomplete="off">
					</div>
					<div class="minn-dp-foot">
						<button type="button" class="minn-btn-soft" data-dp-now>Now</button>
						<button type="button" class="minn-btn-soft" data-dp-clear>Clear</button>
						<button type="button" class="minn-btn-primary" data-dp-done>Done</button>
					</div>`;
				const rect = input.getBoundingClientRect();
				const w = dpPop.offsetWidth || 260;
				dpPop.style.left = Math.max( 10, Math.min( rect.left, window.innerWidth - w - 12 ) ) + 'px';
				dpPop.style.top = Math.min( rect.bottom + 6, window.innerHeight - dpPop.offsetHeight - 10 ) + 'px';

				const timeOf = () => dpParseTime( $( '.minn-dp-time-input', dpPop ).value )
					|| { h: seed.getHours(), m: seed.getMinutes() };
				$$( '.minn-dp-nav', dpPop ).forEach( ( b ) => b.addEventListener( 'click', () => {
					view.setMonth( view.getMonth() + parseInt( b.dataset.nav, 10 ) );
					render();
				} ) );
				$$( '.minn-dp-day', dpPop ).forEach( ( b ) => b.addEventListener( 'click', () => {
					const t = timeOf();
					const picked = new Date( b.dataset.day + 'T00:00' );
					picked.setHours( t.h, t.m );
					seed.setTime( picked.getTime() );
					commit( dpMachine( picked ) );
					render(); // reflect the new selection; stays open for time tweaks
				} ) );
				const timeInput = $( '.minn-dp-time-input', dpPop );
				timeInput.addEventListener( 'change', () => {
					const t = dpParseTime( timeInput.value );
					if ( ! t ) { timeInput.value = seed.toLocaleTimeString( undefined, { hour: 'numeric', minute: '2-digit' } ); return; }
					seed.setHours( t.h, t.m );
					if ( input.dataset.dp ) commit( dpMachine( seed ) );
					timeInput.value = seed.toLocaleTimeString( undefined, { hour: 'numeric', minute: '2-digit' } );
				} );
				timeInput.addEventListener( 'keydown', ( e ) => { if ( e.key === 'Enter' ) { e.preventDefault(); timeInput.blur(); } } );
				$( '[data-dp-now]', dpPop ).addEventListener( 'click', () => {
					const now = new Date();
					seed.setTime( now.getTime() );
					commit( dpMachine( now ) );
					hideDatePicker();
				} );
				$( '[data-dp-clear]', dpPop ).addEventListener( 'click', () => {
					commit( '' );
					hideDatePicker();
				} );
				// Explicit accept: commits whatever the popover shows —
				// including time-field text that hasn't blurred yet — and
				// closes. On an empty field with nothing chosen, just closes.
				$( '[data-dp-done]', dpPop ).addEventListener( 'click', () => {
					if ( input.dataset.dp ) {
						const t = timeOf();
						seed.setHours( t.h, t.m );
						commit( dpMachine( seed ) );
					}
					hideDatePicker();
				} );
			};
			render();
			document.addEventListener( 'mousedown', dpAway, true );
		};

		input.addEventListener( 'click', () => { if ( ! dpPop ) open(); else hideDatePicker(); } );
		input.addEventListener( 'keydown', ( e ) => {
			if ( e.key === 'Enter' || e.key === ' ' ) { e.preventDefault(); if ( ! dpPop ) open(); }
			if ( e.key === 'Escape' ) hideDatePicker();
		} );
	}

	/* ===== Collapsible sidebar cards ===== */
	// Every editor sidebar card collapses from its title, remembered per-card
	// (localStorage map) — collapse everything but Outline once and that stays
	// your layout. All cards default to expanded.
	function bindSideCollapse( el ) {
		let saved = {};
		try { saved = JSON.parse( localStorage.getItem( 'minn-side-collapsed' ) || '{}' ); } catch ( e ) { /* private mode */ }
		$$( '.minn-side-card', el ).forEach( ( card ) => {
			const title = card.querySelector( ':scope > .minn-side-title' );
			if ( ! title ) return;
			const slug = title.textContent.trim().toLowerCase().replace( /[^a-z0-9]+/g, '-' );
			card.classList.add( 'collapsible' );
			card.classList.toggle( 'collapsed', !! saved[ slug ] );
			title.addEventListener( 'click', () => {
				const on = card.classList.toggle( 'collapsed' );
				try {
					const cur = JSON.parse( localStorage.getItem( 'minn-side-collapsed' ) || '{}' );
					if ( on ) cur[ slug ] = 1;
					else delete cur[ slug ];
					localStorage.setItem( 'minn-side-collapsed', JSON.stringify( cur ) );
				} catch ( e ) { /* private mode */ }
			} );
		} );
	}

	function bindOutline() {
		const list = $( '#minn-outline' );
		if ( ! list ) return;
		list.addEventListener( 'click', ( e ) => {
			const row = e.target.closest( '.minn-outline-row' );
			const body = $( '#minn-editor-body' );
			if ( ! row || ! body ) return;
			const heads = outlineHeads( body );
			const h = heads[ Math.min( +row.dataset.oi, heads.length - 1 ) ];
			if ( ! h ) return;
			h.scrollIntoView( { behavior: 'smooth', block: 'center' } );
			// Flash via an overlay — NEVER a class or style on the heading
			// itself: the typing surface serializes, chrome must not. The ping
			// lives INSIDE the scroller at absolute content coordinates, so it
			// rides the smooth scroll natively — a fixed overlay chasing the
			// heading from a timer visibly stutters (Austin's wiggle report).
			const sc = $( '.minn-scroll' );
			if ( ! sc ) return;
			const ping = document.createElement( 'div' );
			ping.className = 'minn-outline-ping';
			const r = h.getBoundingClientRect();
			const scRect = sc.getBoundingClientRect();
			ping.style.cssText = `top:${ r.top - scRect.top + sc.scrollTop - 4 }px;`
				+ `left:${ r.left - scRect.left + sc.scrollLeft - 8 }px;`
				+ `width:${ r.width + 16 }px;height:${ r.height + 8 }px;`;
			sc.appendChild( ping );
			setTimeout( () => ping.classList.add( 'out' ), 900 );
			setTimeout( () => ping.remove(), 1500 );
		} );
		updateOutline();
	}

	function scheduledInFuture( ed ) {
		return ed.newDate && new Date( ed.newDate ) > new Date();
	}

	function publishLabel( ed ) {
		if ( ed.status === 'future' || scheduledInFuture( ed ) ) return 'Schedule';
		// Private is a live status — its button updates in place, not "Publish"
		// (which would make it public).
		if ( ( ed.status === 'publish' || ed.status === 'private' ) && ! scheduledInFuture( ed ) ) return 'Update';
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
			updateSavedRow();
			return;
		}
		const saved = savedState( ed );
		const dateValue = ( ed.newDate || ( ed.date ? ed.date.slice( 0, 16 ) : '' ) );
		const cats = state.cache.categories;
		el.innerHTML = `
		<div class="minn-side-card">
			<div class="minn-side-title">Publish</div>
			<div class="minn-side-rows">
				<div class="minn-side-row"><span class="minn-side-key">Status</span><span class="minn-side-val${ ed.status === 'publish' ? ' green' : ' amber' }" style="font-weight:600;" id="minn-status-state">${ esc( statusLabel ) }</span></div>
				<div class="minn-side-row"><span class="minn-side-key">Visibility</span>
					<select class="minn-mini-select" id="minn-visibility">
						<option value="public"${ ed.visibility === 'public' ? ' selected' : '' }>Public</option>
						<option value="password"${ ed.visibility === 'password' ? ' selected' : '' }>Password protected</option>
						<option value="private"${ ed.visibility === 'private' ? ' selected' : '' }>Private</option>
					</select>
				</div>
				<div class="minn-side-row"><span class="minn-side-key">Saved</span><span class="minn-side-val ${ saved.cls }" id="minn-saved-state">${ esc( saved.text ) }</span></div>
			</div>
			${ ed.visibility === 'password' ? `<input type="text" class="minn-input minn-vis-extra" id="minn-password-input" placeholder="Enter a password" value="${ esc( ed.password ) }" autocomplete="off">` : '' }
			${ ed.supportsSticky && ed.visibility !== 'password' ? `<label class="minn-check-row minn-vis-extra"><input type="checkbox" id="minn-sticky"${ ed.sticky ? ' checked' : '' }> Stick to the top of the blog</label>` : '' }
			<div class="minn-schedule">
				<div class="minn-side-key" style="margin-bottom:5px;">${ ed.status === 'future' ? 'Scheduled for' : 'Publish time' }</div>
				<input type="text" readonly class="minn-input minn-dp-input" id="minn-schedule-input" data-dp="${ esc( dateValue ) }" value="${ esc( dpPretty( dateValue ) ) }" placeholder="Immediately">
			</div>
			<button class="minn-btn-primary" id="minn-publish-btn">${ publishLabel( ed ) }</button>
			${ LIVE_STATUSES.includes( ed.status ) ? '' : '<button class="minn-btn-soft minn-save-draft" id="minn-save-draft-btn">Save draft</button>' }
			${ ed.id && ed.link ? `<a class="minn-side-viewlink" href="${ esc( ed.status === 'publish' ? ed.link : ed.link + ( ed.link.includes( '?' ) ? '&' : '?' ) + 'preview=true' ) }" target="_blank" rel="noopener">${ ed.status === 'publish' ? 'View on site ↗' : 'Preview draft ↗' }</a>` : '' }
		</div>
		${ ed.supportsThumb ? `
		<div class="minn-side-card">
			<div class="minn-side-title">Featured image</div>
			${ ed.featuredMedia && ed.featuredThumb ? `
			<button type="button" class="minn-featured-thumb" id="minn-featured-preview" style="background-image:url('${ esc( ed.featuredThumb ) }')" title="Preview featured image" aria-label="Preview featured image"></button>
			<div style="display:flex; gap:8px; margin-top:10px;">
				<button class="minn-btn-soft" id="minn-featured-set">Replace</button>
				<button class="minn-btn-soft danger" id="minn-featured-remove">Remove</button>
			</div>` : ed.featuredMedia ? '<div class="minn-session-empty">Loading…</div>' : `
			<button class="minn-featured-empty" id="minn-featured-set">${ icon( 'img' ) } Set featured image</button>` }
		</div>` : '' }
		${ ( () => {
			const historyRows = historyRowsFor( ed );
			if ( ! historyRows.length ) return '';
			return `
		<div class="minn-side-card">
			<div class="minn-side-title">History</div>
			${ historyRows.map( ( r ) => `
				<button class="minn-history-row" data-rev="${ r.id }">
					<span class="minn-history-when">${ timeAgo( r.when ) }</span>
					<span class="minn-history-who">${ esc( r.author ) }</span>
				</button>` ).join( '' ) }
		</div>`;
		} )() }
		<div class="minn-side-card">
			<div class="minn-side-title">Settings</div>
			<div style="display:flex; flex-direction:column; gap:11px; font-size: 13.5px; color:var(--text2);">
				<div>Permalink
					<div class="minn-slug-field">
						<span class="minn-slug-prefix">/</span>
						<input class="minn-input minn-slug-input" id="minn-slug-input" value="${ esc( ed.slugValue ) }" placeholder="${ ed.id ? 'post-slug' : 'set on first save' }" autocomplete="off" spellcheck="false"${ ed.id ? '' : ' disabled' }>
					</div>
					${ LIVE_STATUSES.includes( ed.status ) ? '<div class="minn-slug-note">Changing this breaks the current URL.</div>' : '' }
				</div>
				${ ed.supportsFormat ? `<div>Format
					<select class="minn-input" id="minn-post-format">
						${ Object.entries( B.postFormats ).map( ( [ slug, label ] ) => `<option value="${ esc( slug ) }"${ ed.format === slug ? ' selected' : '' }>${ esc( label ) }</option>` ).join( '' ) }
					</select>
				</div>` : '' }
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
				${ ed.supportsExcerpt ? `<div>Excerpt
					<textarea class="minn-input minn-excerpt-input" id="minn-editor-excerpt" rows="3" placeholder="Optional summary for archives, feeds and shares…">${ esc( ed.excerpt ) }</textarea>
				</div>` : '' }
				${ ed.supportsDiscussion ? `<div>Discussion
					<label class="minn-check-row"><input type="checkbox" id="minn-comment-status"${ ed.commentStatus === 'open' ? ' checked' : '' }> Allow comments</label>
					<label class="minn-check-row"><input type="checkbox" id="minn-ping-status"${ ed.pingStatus === 'open' ? ' checked' : '' }> Allow pingbacks &amp; trackbacks</label>
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
		${ ed.id ? '<button class="minn-trash-link" id="minn-trash-post">Move to trash</button>' : '' }
		<div class="minn-side-card" id="minn-outline-card" hidden>
			<div class="minn-side-title">Outline</div>
			<div id="minn-outline"></div>
		</div>`;

		const excerptInput = $( '#minn-editor-excerpt', el );
		if ( excerptInput ) excerptInput.addEventListener( 'input', () => {
			ed.excerpt = excerptInput.value;
			ed.excerptDirty = true;
			if ( ed.id ) scheduleAutosave();
		} );
		const fmtSel = $( '#minn-post-format', el );
		if ( fmtSel ) fmtSel.addEventListener( 'change', () => {
			ed.format = fmtSel.value;
			ed.formatDirty = true;
			ed.dirty = true;
			if ( ed.id ) scheduleAutosave();
		} );
		const slugInput = $( '#minn-slug-input', el );
		if ( slugInput ) {
			// Sanitize toward a real slug (WP finishes the job on save). Kept in
			// ed.slugValue while typing; the field itself is normalized on blur
			// so the caret doesn't jump mid-edit (matches Gutenberg).
			slugInput.addEventListener( 'input', () => {
				ed.slugValue = slugInput.value.toLowerCase().replace( /[^a-z0-9\-_%]+/g, '-' ).replace( /-+/g, '-' );
				ed.slugDirty = true;
				if ( ed.id ) scheduleAutosave();
			} );
			slugInput.addEventListener( 'blur', () => { slugInput.value = ed.slugValue; } );
		}
		const visSel = $( '#minn-visibility', el );
		if ( visSel ) visSel.addEventListener( 'change', () => {
			ed.visibility = visSel.value;
			ed.visibilityDirty = true;
			ed.passwordDirty = true; // password is set or cleared by the choice
			// A password-protected post can't be sticky (WP rejects the pair) —
			// drop stickiness when password is chosen.
			if ( ed.visibility === 'password' && ed.sticky ) {
				ed.sticky = false;
				ed.stickyDirty = true;
			}
			// Re-render to show/hide the password field. renderEditorSide skips
			// a full render while ANY sidebar input is focused — blur whatever
			// holds focus (could be a checkbox the user just toggled, not the
			// select), or the password field would never appear.
			if ( document.activeElement && document.activeElement.blur ) document.activeElement.blur();
			renderEditorSide();
			if ( ed.id ) scheduleAutosave();
		} );
		const pwInput = $( '#minn-password-input', el );
		if ( pwInput ) pwInput.addEventListener( 'input', () => {
			ed.password = pwInput.value;
			ed.passwordDirty = true;
			if ( ed.id ) scheduleAutosave();
		} );
		const stickyBox = $( '#minn-sticky', el );
		if ( stickyBox ) stickyBox.addEventListener( 'change', () => {
			ed.sticky = stickyBox.checked;
			ed.stickyDirty = true;
			if ( ed.id ) scheduleAutosave();
		} );
		const commentBox = $( '#minn-comment-status', el );
		if ( commentBox ) commentBox.addEventListener( 'change', () => {
			ed.commentStatus = commentBox.checked ? 'open' : 'closed';
			ed.commentDirty = true;
			if ( ed.id ) scheduleAutosave();
		} );
		const pingBox = $( '#minn-ping-status', el );
		if ( pingBox ) pingBox.addEventListener( 'change', () => {
			ed.pingStatus = pingBox.checked ? 'open' : 'closed';
			ed.pingDirty = true;
			if ( ed.id ) scheduleAutosave();
		} );
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
				clearAutosaveTimers();
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

		bindSideCollapse( el );
		bindOutline();

		const pickFeatured = () => openMediaPicker( ( it ) => {
			ed.featuredMedia = it.id;
			ed.featuredThumb = it.thumb;
			ed.featuredDirty = true;
			renderEditorSide();
			if ( ed.id ) scheduleAutosave();
		} );
		const clearFeatured = () => {
			ed.featuredMedia = 0;
			ed.featuredThumb = null;
			ed.featuredDirty = true;
			renderEditorSide();
			if ( ed.id ) scheduleAutosave();
		};
		const featSet = $( '#minn-featured-set', el );
		if ( featSet ) featSet.addEventListener( 'click', pickFeatured );
		const featRemove = $( '#minn-featured-remove', el );
		if ( featRemove ) featRemove.addEventListener( 'click', clearFeatured );
		// Thumb opens the same media preview used in the library — full image,
		// Edit image, Copy URL, plus Replace/Remove featured from that context.
		const featPrev = $( '#minn-featured-preview', el );
		if ( featPrev ) {
			featPrev.addEventListener( 'click', async () => {
				if ( ! ed.featuredMedia ) return;
				featPrev.disabled = true;
				featPrev.classList.add( 'loading' );
				try {
					const raw = await api( `wp/v2/media/${ ed.featuredMedia }?_fields=id,title,mime_type,source_url,media_details,date,alt_text` );
					state.modal = { type: 'media', item: mapMediaItem( raw ), from: 'featured' };
					renderOverlays();
				} catch ( e ) {
					toast( e.message || 'Could not load image', true );
				} finally {
					featPrev.disabled = false;
					featPrev.classList.remove( 'loading' );
				}
			} );
		}

		bindDatePicker( $( '#minn-schedule-input', el ), ( v ) => {
			state.editor.newDate = v || null;
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

		const saveDraftBtn = $( '#minn-save-draft-btn', el );
		if ( saveDraftBtn ) {
			saveDraftBtn.addEventListener( 'click', async () => {
				saveDraftBtn.disabled = true;
				clearAutosaveTimers();
				await saveEditor( { _explicit: true } );
				saveDraftBtn.disabled = false;
				if ( state.editor && ! state.editor.dirty ) toast( 'Draft saved' );
			} );
		}

		$( '#minn-publish-btn', el ).addEventListener( 'click', async ( e ) => {
			const btn = e.currentTarget;
			btn.disabled = true;
			clearAutosaveTimers();
			const extra = { _explicit: true };
			// Keep a private post private on Update unless the visibility
			// control changed it (buildSavePayload applies that override).
			const liveStatus = ed.status === 'private' && ed.visibility === 'private' ? 'private' : 'publish';
			if ( ed.newDate ) {
				extra.date = ed.newDate.length === 16 ? ed.newDate + ':00' : ed.newDate;
				extra.status = scheduledInFuture( ed ) ? 'future' : liveStatus;
			} else {
				extra.status = ed.status === 'future' ? 'future' : liveStatus;
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

	// "A newer backup exists" — the flip side of autosave-to-revision:
	// offer the backup back after a crash or an abandoned editing session.
	function renderBackupNotice() {
		const ed = state.editor;
		const existing = $( '#minn-backup-note' );
		if ( existing ) existing.remove();
		// A pending local crash-net notice owns the banner slot first — it's
		// this browser's own last state, at least as fresh as any revision.
		if ( ! ed || ! ed.backup || ed.localNet ) return;
		const title = $( '#minn-editor-title' );
		if ( ! title ) return;
		title.insertAdjacentHTML( 'afterend', `
			<div class="minn-backup-note" id="minn-backup-note">
				<span>A newer backup of this ${ esc( editorNoun( ed ).toLowerCase() ) } exists (${ esc( timeAgo( ed.backup.modified ) ) }) \u2014 likely unsaved changes from an earlier session.</span>
				<button class="minn-btn-soft" id="minn-backup-restore" type="button">Restore backup</button>
				<button class="minn-x-btn" id="minn-backup-dismiss" type="button" title="Dismiss">\u00d7</button>
			</div>` );
		$( '#minn-backup-restore' ).addEventListener( 'click', restoreBackup );
		$( '#minn-backup-dismiss' ).addEventListener( 'click', () => {
			if ( state.editor ) state.editor.backup = null;
			renderBackupNotice();
		} );
	}

	async function restoreBackup() {
		const ed = state.editor;
		if ( ! ed || ! ed.backup ) return;
		try {
			const a = await api( `wp/v2/${ ed.type }/${ ed.id }/autosaves/${ ed.backup.id }?context=edit&_fields=title,content` );
			if ( state.editor !== ed || state.route !== 'editor' ) return;
			const raw = ( a.content && a.content.raw ) || '';
			ed.title = decodeEntities( ( a.title && ( a.title.raw != null ? a.title.raw : a.title.rendered ) ) || ed.title );
			ed.mode = editorModeFor( raw );
			ed.islands = [];
			ed.content = ed.mode === 'blocks' ? buildEditableContent( ed, raw )
				: ed.mode === 'classic' ? miniAutop( raw )
				: stripBlockComments( raw );
			ed.backup = null;
			// The restored ed.content is the truth \u2014 without this, the
			// dirty-editor guard in renderEditor would adopt the live DOM
			// and silently discard the restore. scheduleAutosave re-dirties.
			ed.dirty = false;
			renderEditor();
			scheduleAutosave();
			toast( 'Backup restored \u2014 review, then Save or Update to apply' );
		} catch ( e ) {
			toast( e.message, true );
		}
	}

	/* ===== Conflict safety: post locking (core's _edit_lock) ===== */

	// Locks live in core's own _edit_lock meta via wp_set_post_lock, so Minn,
	// the classic editor and Gutenberg all honor each other. Core's window is
	// 150s; wp-admin heartbeats every 15s, Minn refreshes at a calmer 30s \u2014
	// the refresh doubles as takeover detection.
	const LOCK_REFRESH = 30000;
	let lockTimer = null;

	function clearLockTimer() {
		clearTimeout( lockTimer );
		lockTimer = null;
	}

	// Acquire (or steal) the edit lock. Lock failures never block writing \u2014
	// a broken lock endpoint degrades to pre-locking behavior, not read-only.
	async function acquireLock( ed, takeOver ) {
		if ( ! ed.id ) return;
		let r;
		try {
			r = await api( `minn-admin/v1/posts/${ ed.id }/lock`, { method: 'POST', body: JSON.stringify( takeOver ? { take_over: true } : {} ) } );
		} catch ( e ) {
			return;
		}
		if ( state.editor !== ed ) {
			// Navigated away while the request was in flight \u2014 hand it back.
			if ( r.acquired ) api( `minn-admin/v1/posts/${ ed.id }/unlock`, { method: 'POST', body: '{}' } ).catch( () => {} );
			return;
		}
		if ( r.acquired ) {
			const wasReadonly = ed.lockState === 'taken' || ed.lockState === 'blocked';
			ed.lockState = 'held';
			ed.lockHolder = null;
			scheduleLockRefresh( ed );
			if ( wasReadonly && state.route === 'editor' ) {
				removeLockOverlay();
				setEditorWritable( true );
				renderLockNotice();
			}
		} else if ( ed.lockState === 'held' || ed.lockState === 'taken' ) {
			lockLost( ed, r.holder );
		} else {
			ed.lockState = 'blocked';
			ed.lockHolder = r.holder || null;
			if ( state.route === 'editor' ) renderLockOverlay();
		}
	}

	function scheduleLockRefresh( ed ) {
		clearLockTimer();
		lockTimer = setTimeout( () => {
			if ( state.editor === ed && state.route === 'editor' && ed.lockState === 'held' ) acquireLock( ed, false );
		}, LOCK_REFRESH );
	}

	// Someone took the lock while we were editing: their copy is canonical
	// now. Freeze the surface and stop every write path (autosave included) \u2014
	// saving would silently clobber their work.
	function lockLost( ed, holder ) {
		ed.lockState = 'taken';
		ed.lockHolder = holder || null;
		clearLockTimer();
		clearAutosaveTimers();
		if ( state.route === 'editor' ) {
			setEditorWritable( false );
			renderLockNotice();
		}
	}

	function setEditorWritable( on ) {
		const body = $( '#minn-editor-body' );
		const title = $( '#minn-editor-title' );
		if ( body && state.editor && state.editor.mode !== 'locked' ) body.contentEditable = on ? 'true' : 'false';
		if ( title ) title.disabled = ! on;
	}

	function releaseLock( ed ) {
		clearLockTimer();
		if ( ! ed || ! ed.id || ed.lockState !== 'held' ) return;
		ed.lockState = null;
		api( `minn-admin/v1/posts/${ ed.id }/unlock`, { method: 'POST', body: '{}' } ).catch( () => {} );
	}

	// Full-screen takeover dialog when the post is already open elsewhere \u2014
	// the wp-admin pattern: identify who, offer Take over or a way back.
	function renderLockOverlay() {
		const ed = state.editor;
		removeLockOverlay();
		if ( ! ed || ed.lockState !== 'blocked' || ! ed.lockHolder ) return;
		const el = document.createElement( 'div' );
		el.className = 'minn-modal-overlay minn-lock-overlay';
		el.id = 'minn-lock-overlay';
		el.innerHTML = `
			<div class="minn-modal minn-lock-card">
				${ ed.lockHolder.avatar ? `<img class="minn-lock-avatar" src="${ esc( ed.lockHolder.avatar ) }" alt="">` : '' }
				<h3>${ esc( ed.lockHolder.name ) } is currently editing</h3>
				<p>This ${ esc( editorNoun( ed ).toLowerCase() ) } is open in another editor session. Taking over will lock them out of saving until they take it back.</p>
				<div class="minn-lock-actions">
					<button class="minn-btn-soft" id="minn-lock-back" type="button">\u2039 Back to content</button>
					<button class="minn-btn-primary" id="minn-lock-take" type="button">Take over</button>
				</div>
			</div>`;
		document.body.appendChild( el );
		$( '#minn-lock-back' ).addEventListener( 'click', () => go( 'content' ) );
		$( '#minn-lock-take' ).addEventListener( 'click', () => acquireLock( ed, true ) );
	}

	function removeLockOverlay() {
		const el = $( '#minn-lock-overlay' );
		if ( el ) el.remove();
	}

	// Mid-session takeover banner \u2014 red sibling of the backup notice.
	function renderLockNotice() {
		const ed = state.editor;
		const existing = $( '#minn-lock-note' );
		if ( existing ) existing.remove();
		if ( ! ed || ed.lockState !== 'taken' ) return;
		const title = $( '#minn-editor-title' );
		if ( ! title ) return;
		const who = ed.lockHolder ? ed.lockHolder.name : 'Someone else';
		title.insertAdjacentHTML( 'afterend', `
			<div class="minn-backup-note minn-lock-note" id="minn-lock-note">
				<span><b>${ esc( who ) }</b> took over editing \u2014 this copy is read-only and won\u2019t save.</span>
				<button class="minn-btn-soft" id="minn-lock-retake" type="button">Take back</button>
			</div>` );
		$( '#minn-lock-retake' ).addEventListener( 'click', () => acquireLock( ed, true ) );
	}

	/* ===== Conflict safety: local crash net (localStorage) ===== */

	// Every edit also lands in localStorage within ~1.2s, so a crashed browser
	// loses at most that much \u2014 even before the first autosave. Snapshots are
	// the serializers' own output; recovery reuses the exact load path.
	const LOCAL_NET_DELAY = 1200;
	const LOCAL_NET_MAX = 12;
	let localNetTimer = null;

	const localNetKey = ( ed ) => 'minn-net-' + ( ed.id ? `${ ed.type }-${ ed.id }` : 'new-' + ed.type );

	function localNetSchedule() {
		clearTimeout( localNetTimer );
		localNetTimer = setTimeout( localNetWrite, LOCAL_NET_DELAY );
	}

	function localNetWrite() {
		clearTimeout( localNetTimer );
		localNetTimer = null;
		const ed = state.editor;
		// Locked bodies never serialize; read-only lock states must not
		// snapshot either \u2014 recovery would resurrect a clobbering copy.
		if ( ! ed || ed.mode === 'locked' || ed.lockState === 'taken' || ed.lockState === 'blocked' ) return;
		if ( state.route !== 'editor' ) return;
		const body = $( '#minn-editor-body' );
		const title = $( '#minn-editor-title' );
		if ( ! body || ! title ) return;
		const content = ed.mode === 'blocks' ? serializeToBlocks( body, ed.islands ) : classicHtml( body );
		try {
			localStorage.setItem( localNetKey( ed ), JSON.stringify( { t: Date.now(), title: title.value, content } ) );
			localNetPrune();
		} catch ( e ) { /* quota / private mode \u2014 the net is best-effort */ }
	}

	function localNetPrune() {
		const keys = [];
		for ( let i = 0; i < localStorage.length; i++ ) {
			const k = localStorage.key( i );
			if ( k && k.indexOf( 'minn-net-' ) === 0 ) keys.push( k );
		}
		if ( keys.length <= LOCAL_NET_MAX ) return;
		keys.map( ( k ) => {
			try {
				return [ k, JSON.parse( localStorage.getItem( k ) ).t || 0 ];
			} catch ( e ) {
				return [ k, 0 ];
			}
		} ).sort( ( a, b ) => a[ 1 ] - b[ 1 ] )
			.slice( 0, keys.length - LOCAL_NET_MAX )
			.forEach( ( pair ) => localStorage.removeItem( pair[ 0 ] ) );
	}

	// After a successful server write the snapshot is redundant \u2014 unless the
	// user kept typing after the payload was captured; the newer snapshot
	// still covers the unsaved tail and must survive.
	function localNetClear( ed, capturedAt ) {
		try {
			const key = localNetKey( ed );
			const stored = localStorage.getItem( key );
			if ( stored && capturedAt && JSON.parse( stored ).t > capturedAt ) return;
			localStorage.removeItem( key );
		} catch ( e ) {}
	}

	// Recovery banner \u2014 the crash-net twin of renderBackupNotice. When both a
	// local snapshot and a newer autosave revision exist, the local one takes
	// the slot (written on every edit in THIS browser, it's at least as
	// fresh); dismissing it lets the revision notice have its turn.
	function renderLocalNetNotice() {
		const ed = state.editor;
		const existing = $( '#minn-localnet-note' );
		if ( existing ) existing.remove();
		if ( ! ed || ! ed.localNet ) return;
		const title = $( '#minn-editor-title' );
		if ( ! title ) return;
		title.insertAdjacentHTML( 'afterend', `
			<div class="minn-backup-note" id="minn-localnet-note">
				<span>This browser has unsaved work on this ${ esc( editorNoun( ed ).toLowerCase() ) } from ${ esc( timeAgo( new Date( ed.localNet.t ).toISOString() ) ) } \u2014 a session ended before it reached the server.</span>
				<button class="minn-btn-soft" id="minn-localnet-restore" type="button">Restore</button>
				<button class="minn-x-btn" id="minn-localnet-dismiss" type="button" title="Dismiss">\u00d7</button>
			</div>` );
		$( '#minn-localnet-restore' ).addEventListener( 'click', restoreLocalNet );
		$( '#minn-localnet-dismiss' ).addEventListener( 'click', () => {
			if ( state.editor ) {
				try {
					localStorage.removeItem( localNetKey( state.editor ) );
				} catch ( e ) {}
				state.editor.localNet = null;
			}
			renderLocalNetNotice();
			renderBackupNotice();
		} );
	}

	function restoreLocalNet() {
		const ed = state.editor;
		if ( ! ed || ! ed.localNet ) return;
		const snap = ed.localNet;
		ed.localNet = null;
		ed.title = snap.title || '';
		if ( snap.content != null && ed.mode !== 'locked' ) {
			ed.mode = editorModeFor( snap.content );
			ed.islands = [];
			ed.content = ed.mode === 'blocks' ? buildEditableContent( ed, snap.content )
				: ed.mode === 'classic' ? miniAutop( snap.content )
				: stripBlockComments( snap.content );
		}
		// The restored content is the truth \u2014 see the matching note in
		// restoreBackup; scheduleAutosave re-dirties right after.
		ed.dirty = false;
		renderEditor();
		// Marks dirty AND re-snapshots within LOCAL_NET_DELAY \u2014 the removed
		// key is re-covered before a crash could lose the restored work.
		scheduleAutosave();
		toast( 'Recovered work restored \u2014 review, then save' );
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
		// A render while unsaved edits sit in the DOM must never eat them:
		// ed.content can be seconds stale (it's only rebuilt on load/restore),
		// so a late or stray re-render would silently revert live typing and
		// the next save would persist the reverted body. Adopt the live DOM
		// first. (Bit the paste suite as a heisenbug: paste → slow-server
		// late render wiped the body → ⌘S saved pre-paste content.)
		if ( ed.dirty && ! locked ) {
			const liveBody = $( '#minn-editor-body' );
			const liveTitle = $( '#minn-editor-title' );
			if ( liveBody ) ed.content = liveBody.innerHTML;
			if ( liveTitle ) ed.title = liveTitle.value;
		}
		view.innerHTML = `
		<div class="minn-editor">
			<div>
				<input class="minn-editor-title" id="minn-editor-title" placeholder="Untitled ${ esc( editorNoun( ed ).toLowerCase() ) }" value="${ esc( ed.title ) }">
				${ ed.builder && ed.builder.edit_url ? `
				<div class="minn-editor-locked-note minn-builder-note">
					<span>${ ed.builder.owns_content
		? `This ${ ed.type === 'pages' ? 'page' : 'post' }'s canvas is managed by <b>${ esc( ed.builder.name ) }</b> —
						its content lives in the builder, so the body below is a read-only preview.
						Title, status, URL and the side panel still save from here.`
		: `Built with <b>${ esc( ed.builder.name ) }</b> — its blocks are preserved exactly; the text around them is editable here.` }</span>
					<a class="minn-btn-primary minn-builder-open" href="${ esc( ed.builder.edit_url ) }">Edit in ${ esc( ed.builder.name ) }</a>
				</div>` : '' }
				${ locked && ! ( ed.builder && ed.builder.owns_content ) ? `
				<div class="minn-editor-locked-note">
					Minn couldn't safely parse this ${ ed.type === 'pages' ? 'page' : 'post' }'s block structure,
					so the body is read-only — the title can still be edited here.
					<button type="button" class="minn-linkish" id="minn-open-block-editor">Open in block editor ↗</button>
				</div>` : '' }
				${ locked ? `` : `
				<div class="minn-editor-toolbar">
					<button class="minn-tool b" data-cmd="bold" title="Bold">${ icon( 'bold' ) }</button>
					<button class="minn-tool i" data-cmd="italic" title="Italic">${ icon( 'italic' ) }</button>
					<button class="minn-tool" data-cmd="strikeThrough" title="Strikethrough — or wrap it in ~~tildes~~">${ icon( 'strike' ) }</button>
					<button class="minn-tool code" data-cmd="inline-code" title="Inline code — or wrap it in backticks">${ icon( 'code' ) }</button>
					<button class="minn-tool" data-block="h2" title="Heading 2">${ icon( 'h2' ) }</button>
					<button class="minn-tool" data-block="h3" title="Heading 3">${ icon( 'h3' ) }</button>
					<button class="minn-tool" data-block="blockquote" title="Quote">${ icon( 'quote' ) }</button>
					<button class="minn-tool" data-block="pre" title="Code block">${ icon( 'braces' ) }</button>
					<button class="minn-tool" data-cmd="insertUnorderedList" title="Bulleted list">${ icon( 'list' ) }</button>
					<button class="minn-tool" data-cmd="insertOrderedList" title="Numbered list">${ icon( 'olist' ) }</button>
					<button class="minn-tool" data-align="center" title="Center — press again to clear">${ icon( 'alignCenter' ) }</button>
					<button class="minn-tool" data-align="right" title="Align right — press again to clear">${ icon( 'alignRight' ) }</button>
					<button class="minn-tool" data-cmd="link" title="Link — or ⌘K">${ icon( 'link' ) }</button>
					<button class="minn-tool" data-cmd="image" title="Insert image">${ icon( 'img' ) }</button>
					<button class="minn-tool" data-block="p" title="Paragraph">${ icon( 'pilcrow' ) }</button>
					<button class="minn-tool" data-cmd="removeFormat" title="Clear formatting">${ icon( 'eraser' ) }</button>
					<span class="minn-tool-hint">type / for blocks</span>
				</div>` }
				<div class="minn-editor-body${ locked ? ' locked' : '' }" id="minn-editor-body" contenteditable="${ locked ? 'false' : 'true' }"></div>
				<div class="minn-editor-stats" id="minn-editor-stats" aria-live="off"></div>
			</div>
			<div class="minn-editor-side" id="minn-editor-side"></div>
		</div>`;

		const body = $( '#minn-editor-body', view );
		// Blank posts must not stay truly empty: a contenteditable with no
		// children puts the first keystrokes in a bare text node, and the
		// slash menu (and most block ops) need a top-level element.
		body.innerHTML = ed.content || ( locked ? '' : '<p><br></p>' );
		if ( ! locked ) seedImageCaptions( body );
		if ( ! locked ) ensureTrailingParagraph( body );
		highlightCodeBlocks( body );
		renderIslandPreviews( body, ed );
		// Focus mode persists across posts/sessions (localStorage), never in locked mode.
		if ( ! locked ) {
			try { ed.focus = ed.focus || !! localStorage.getItem( 'minn-focus' ); } catch ( e ) { /* private mode */ }
			document.body.classList.toggle( 'minn-focus-zen', !! ed.focus );
		}
		// Outline mode persists the same way (and works in locked mode — it's
		// pure chrome around a read-only body). Focus wins if both somehow
		// persisted — the toggles keep them mutually exclusive.
		try { ed.outlineMode = ed.outlineMode || !! localStorage.getItem( 'minn-outline-mode' ); } catch ( e ) { /* private mode */ }
		if ( ed.focus && ed.outlineMode ) ed.outlineMode = false;
		document.body.classList.toggle( 'minn-outline-mode', !! ed.outlineMode );
		updateEditorStats();
		ensureEditorStyles();
		renderBackupNotice();
		renderLocalNetNotice();
		renderLockNotice();
		renderLockOverlay();
		if ( ed.lockState === 'taken' || ed.lockState === 'blocked' ) setEditorWritable( false );
		// Image loads change layout under the fixed chips — reposition then.
		body.addEventListener( 'load', queueTableChips, true );
		// Island chips open the block inspector (works in locked mode too — read-only there is fine
		// because locked posts never send content, but islands only exist in blocks mode anyway).
		body.addEventListener( 'click', ( e ) => {
			const chip = e.target.closest( '.minn-island-chip' );
			if ( ! chip ) return;
			e.preventDefault();
			const island = chip.closest( '.minn-block-island' );
			if ( island ) openInspector( island );
		} );
		const openBe = $( '#minn-open-block-editor', view );
		if ( openBe ) openBe.addEventListener( 'click', () => openInBlockEditor( openBe ) );
		// Live-field islands (shortcode + details + buttons): type in the card,
		// commit into ed.islands (serialize never reads the fields).
		// stopPropagation so island guards / outer contenteditable don't treat
		// keystrokes as body edits. Summary input click preventDefault keeps
		// <details> from toggling while the writer focuses the summary field.
		if ( ! locked ) {
			// Stamp buttons-row attrs once the body is live so commits preserve
			// colors/width from the original markup.
			$$( '.minn-buttons-island', body ).forEach( ( island ) => {
				const idx = parseInt( island.dataset.island, 10 );
				const raw = ed.islands && ed.islands[ idx ];
				if ( raw == null || island.dataset.btnStamped ) return;
				stampButtonsRowAttrs( island, parseButtonsRaw( raw ) );
				island.dataset.btnStamped = '1';
			} );
			body.addEventListener( 'input', ( e ) => {
				const sc = e.target.closest && e.target.closest( '.minn-shortcode-input' );
				if ( sc ) { commitShortcodeInput( sc ); return; }
				const detField = e.target.closest && e.target.closest( '.minn-details-summary, .minn-details-body' );
				if ( detField ) {
					const island = detField.closest( '.minn-details-island' );
					if ( island ) commitDetailsIsland( island );
					return;
				}
				const btnField = e.target.closest && e.target.closest( '.minn-btn-label, .minn-btn-url' );
				if ( btnField ) {
					const island = btnField.closest( '.minn-buttons-island' );
					if ( island ) commitButtonsIsland( island );
				}
			} );
			body.addEventListener( 'change', ( e ) => {
				const sc = e.target.closest && e.target.closest( '.minn-shortcode-input' );
				if ( sc ) { commitShortcodeInput( sc ); return; }
				const detField = e.target.closest && e.target.closest( '.minn-details-summary, .minn-details-body' );
				if ( detField ) {
					const island = detField.closest( '.minn-details-island' );
					if ( island ) commitDetailsIsland( island );
					return;
				}
				const btnField = e.target.closest && e.target.closest( '.minn-btn-label, .minn-btn-url, .minn-btn-newtab, .minn-btn-outline' );
				if ( btnField ) {
					const island = btnField.closest( '.minn-buttons-island' );
					if ( island ) commitButtonsIsland( island );
				}
			} );
			body.addEventListener( 'keydown', ( e ) => {
				// Island live fields stopPropagation so contenteditable body
				// handlers don't treat typing as body edits — but ⌘/Ctrl
				// shortcuts (⌘S save, ⌘K link/palette, …) must still reach
				// the window listeners. Without this, ⌘S in a shortcode input
				// falls through to the browser's "Save Page As…" (Austin).
				const appShortcut = e.metaKey || e.ctrlKey;
				const sc = e.target.closest && e.target.closest( '.minn-shortcode-input' );
				if ( sc ) {
					// Shortcodes are single-line — Enter blurs instead of inserting.
					if ( e.key === 'Enter' && ! appShortcut ) {
						e.preventDefault();
						e.target.blur();
					}
					if ( ! appShortcut ) e.stopPropagation();
					return;
				}
				const detSum = e.target.closest && e.target.closest( '.minn-details-summary' );
				if ( detSum ) {
					// Enter in summary jumps into the body (and keeps details open).
					if ( e.key === 'Enter' && ! appShortcut ) {
						e.preventDefault();
						const island = detSum.closest( '.minn-details-island' );
						const det = island && island.querySelector( 'details.minn-details-edit' );
						const bodyEl = island && island.querySelector( '.minn-details-body' );
						if ( det ) det.open = true;
						if ( bodyEl ) {
							bodyEl.focus();
							const r = document.createRange();
							r.selectNodeContents( bodyEl );
							r.collapse( false );
							const sel = window.getSelection();
							sel.removeAllRanges();
							sel.addRange( r );
						}
					}
					if ( ! appShortcut ) e.stopPropagation();
					return;
				}
				const detBody = e.target.closest && e.target.closest( '.minn-details-body' );
				if ( detBody ) {
					if ( ! appShortcut ) e.stopPropagation();
					return;
				}
				const btnField = e.target.closest && e.target.closest( '.minn-btn-label, .minn-btn-url' );
				if ( btnField ) {
					if ( e.key === 'Enter' && ! appShortcut ) {
						e.preventDefault();
						// Label → URL; URL → next row label (or add row).
						if ( btnField.classList.contains( 'minn-btn-label' ) ) {
							const url = btnField.closest( '.minn-btn-row' )?.querySelector( '.minn-btn-url' );
							if ( url ) url.focus();
						} else {
							const row = btnField.closest( '.minn-btn-row' );
							const next = row && row.nextElementSibling;
							const island = btnField.closest( '.minn-buttons-island' );
							if ( next ) {
								const lab = next.querySelector( '.minn-btn-label' );
								if ( lab ) lab.focus();
							} else if ( island ) {
								addButtonsRow( island );
							}
						}
					}
					if ( ! appShortcut ) e.stopPropagation();
				}
			} );
			body.addEventListener( 'mousedown', ( e ) => {
				if ( e.target.closest && e.target.closest( '.minn-shortcode-input, .minn-details-summary, .minn-details-body, .minn-btn-label, .minn-btn-url, .minn-btn-opt, .minn-buttons-add, .minn-btn-row-del' ) ) {
					e.stopPropagation();
				}
			}, true );
			// Clicking the summary text field must not toggle <details> closed.
			body.addEventListener( 'click', ( e ) => {
				const sum = e.target.closest && e.target.closest( '.minn-details-summary' );
				if ( sum ) e.preventDefault();
				const addBtn = e.target.closest && e.target.closest( '.minn-buttons-add' );
				if ( addBtn ) {
					e.preventDefault();
					const island = addBtn.closest( '.minn-buttons-island' );
					if ( island ) addButtonsRow( island );
					return;
				}
				const delBtn = e.target.closest && e.target.closest( '.minn-btn-row-del' );
				if ( delBtn ) {
					e.preventDefault();
					removeButtonsRow( delBtn.closest( '.minn-btn-row' ) );
				}
			}, true );
		}
		// Clicking an editable image opens its controls popover.
		body.addEventListener( 'click', ( e ) => {
			const img = e.target.closest( 'img' );
			if ( img && body.contains( img ) && ! img.closest( '.minn-block-island' ) ) {
				openImgPop( img );
			}
		} );
		// Clicking a link opens its edit popover (links never navigate
		// inside contenteditable anyway).
		body.addEventListener( 'click', ( e ) => {
			const a = e.target.closest( 'a' );
			if ( a && body.contains( a ) && ! a.closest( '.minn-block-island' ) ) {
				e.preventDefault();
				openLinkPop( a );
			}
		} );
		// Right-click in a table cell → targeted row/column ops on THAT cell.
		body.addEventListener( 'contextmenu', ( e ) => {
			const cell = e.target.closest ? e.target.closest( 'td, th' ) : null;
			const table = cell ? cell.closest( 'table' ) : null;
			if ( ! cell || ! table || ! body.contains( table ) || table.closest( '.minn-block-island' ) ) return;
			e.preventDefault();
			openTableMenu( e.clientX, e.clientY, table, cell );
		} );

		// Hovering a chip-carrying block's box (edge included) highlights it
		// AND its chip — tables, images and code blocks all behave alike.
		let hotTable = null;
		body.addEventListener( 'mouseover', ( e ) => {
			const box = e.target.closest ? e.target.closest( '#minn-editor-body > figure.wp-block-table, #minn-editor-body > table, #minn-editor-body > figure.wp-block-image, #minn-editor-body > img, #minn-editor-body > pre:not(.wp-block-verse):not(.wp-block-preformatted)' ) : null;
			let t = null;
			if ( box ) t = box.tagName === 'TABLE' || box.tagName === 'IMG' || box.tagName === 'PRE' ? box : box.querySelector( 'table, img' );
			if ( t === hotTable ) return;
			if ( hotTable ) setTableHot( hotTable, false );
			hotTable = t;
			if ( hotTable ) setTableHot( hotTable, true );
		} );
		body.addEventListener( 'mouseleave', () => {
			if ( hotTable ) {
				setTableHot( hotTable, false );
				hotTable = null;
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

			const insertImage = () => {
				// Clicking inside the media picker modal destroys the editor
				// selection — capture the caret NOW and restore it before
				// inserting, or the image lands at the top of the document.
				const sel0 = window.getSelection();
				const saved = sel0.rangeCount && body.contains( sel0.anchorNode )
					? sel0.getRangeAt( 0 ).cloneRange()
					: null;
				openMediaPicker( ( it ) => {
					const b = $( '#minn-editor-body' );
					if ( ! b ) return;
					b.focus();
					if ( saved && saved.startContainer.isConnected && b.contains( saved.startContainer ) ) {
						const s = window.getSelection();
						s.removeAllRanges();
						s.addRange( saved );
					}
					document.execCommand( 'insertHTML', false, imageFigureHtml( it ) + '<p><br></p>' );
					scheduleAutosave();
				} );
			};

			$$( '.minn-tool', view ).forEach( ( btn ) =>
				btn.addEventListener( 'mousedown', ( e ) => {
					e.preventDefault(); // keep the selection in the editable region
					if ( btn.dataset.cmd === 'link' ) {
						const sel2 = window.getSelection();
						let n = sel2.rangeCount ? sel2.anchorNode : null;
						while ( n && n.nodeType !== Node.ELEMENT_NODE ) n = n.parentNode;
						const a = n && n.closest ? n.closest( 'a' ) : null;
						if ( a && body.contains( a ) ) openLinkPop( a );
						else if ( sel2.rangeCount && ! sel2.isCollapsed && body.contains( sel2.anchorNode ) ) openLinkPop( null, sel2.getRangeAt( 0 ) );
					} else if ( btn.dataset.cmd === 'inline-code' ) {
						toggleInlineCode( body );
					} else if ( btn.dataset.cmd === 'image' ) {
						insertImage();
					} else if ( btn.dataset.align ) {
						// Toggle alignment via the Gutenberg class (inline text-align
						// styles would be stripped at serialize). Paragraphs and
						// headings only. Pressing the active alignment clears it.
						const sel3 = window.getSelection();
						let blk = sel3.rangeCount ? sel3.anchorNode : null;
						while ( blk && blk.parentNode && blk.parentNode !== body ) blk = blk.parentNode;
						if ( blk && blk.nodeType === Node.ELEMENT_NODE && /^(P|H[1-6])$/.test( blk.tagName ) ) {
							const had = blk.classList.contains( 'has-text-align-' + btn.dataset.align );
							blk.classList.remove( 'has-text-align-left', 'has-text-align-center', 'has-text-align-right' );
							if ( ! had ) blk.classList.add( 'has-text-align-' + btn.dataset.align );
							if ( ! blk.className ) blk.removeAttribute( 'class' );
						}
					} else if ( btn.dataset.cmd ) {
						document.execCommand( btn.dataset.cmd, false, null );
						liftNestedLists( body );
					} else if ( btn.dataset.block ) {
						// Block buttons TOGGLE: pressing Quote (or H2…) while
						// already inside one converts back to a paragraph.
						// Without this the second press is a no-op that still
						// pushes a junk undo entry, making ⌘Z look broken.
						const want = btn.dataset.block;
						const sel2 = window.getSelection();
						let n = sel2.rangeCount ? sel2.anchorNode : null;
						while ( n && n.nodeType !== Node.ELEMENT_NODE ) n = n.parentNode;
						const inBlock = want !== 'p' && n && n.closest ? n.closest( want ) : null;
						if ( inBlock && body.contains( inBlock ) ) {
							if ( want === 'blockquote' ) {
								// outdent unwraps the blockquote (formatBlock can't),
								// but leaves a bare text node — re-wrap it.
								document.execCommand( 'outdent', false, null );
								document.execCommand( 'formatBlock', false, 'p' );
							} else {
								document.execCommand( 'formatBlock', false, 'p' );
							}
						} else {
							document.execCommand( 'formatBlock', false, want );
						}
					}
					scheduleAutosave();
				} )
			);

			bindIslandGuards( body );
			bindMarkdown( body );
			bindSlashMenu( body, insertImage );

			// Dropped image files land where the pointer released. Dragging
			// content WITHIN the editor carries no files and keeps Chrome's
			// native behavior. stopPropagation matters: the app-wide
			// drop-anywhere handler would otherwise ALSO catch this, navigate
			// to the Media view and upload a second copy (probed).
			body.addEventListener( 'dragover', ( e ) => {
				if ( e.dataTransfer && Array.from( e.dataTransfer.items || [] ).some( ( i ) => i.kind === 'file' ) ) {
					e.preventDefault();
					// The global "Drop files to upload" veil gets an
					// editor-specific label while the pointer is over us.
					document.body.classList.add( 'minn-drag-editor' );
				}
			} );
			body.addEventListener( 'dragleave', () => document.body.classList.remove( 'minn-drag-editor' ) );
			body.addEventListener( 'drop', ( e ) => {
				document.body.classList.remove( 'minn-drag-editor' );
				const all = Array.from( ( e.dataTransfer && e.dataTransfer.files ) || [] );
				if ( ! all.length ) return; // in-editor content drags keep native behavior
				// Claim EVERY file drop — the unhandled default navigates the
				// browser to the dropped file, destroying the editing session.
				e.preventDefault();
				e.stopPropagation();
				document.body.classList.remove( 'minn-dragging' );
				if ( ! B.caps.upload ) {
					toast( 'You aren’t allowed to upload files', true );
					return;
				}
				const files = all.filter( ( f ) => /^image\//.test( f.type ) );
				if ( ! files.length ) {
					toast( 'Only images can be dropped into the editor', true );
					return;
				}
				if ( document.caretRangeFromPoint ) {
					const r = document.caretRangeFromPoint( e.clientX, e.clientY );
					if ( r ) {
						const s = window.getSelection();
						s.removeAllRanges();
						s.addRange( r );
					}
				}
				body.focus( { preventScroll: true } );
				insertImageFiles( body, files );
			} );

			// Caption edges. Enter exits to the block after the figure —
			// Chrome's default would split the FIGURE, duplicating the image.
			// Backspace at the caption's start dissolves the figcaption into a
			// styled <span> (probed), and Delete at its end pulls the next
			// block's text in — both are no-ops, like Gutenberg.
			body.addEventListener( 'keydown', ( e ) => {
				if ( e.key !== 'Enter' && e.key !== 'Backspace' && e.key !== 'Delete' ) return;
				const s = window.getSelection();
				if ( ! s.rangeCount ) return;
				let n = s.anchorNode;
				while ( n && n.nodeType !== Node.ELEMENT_NODE ) n = n.parentNode;
				const fc = n && n.closest ? n.closest( 'figcaption' ) : null;
				if ( ! fc || ! body.contains( fc ) || fc.closest( '.minn-block-island' ) ) return;
				if ( e.key === 'Enter' ) {
					e.preventDefault();
					const fig = fc.closest( 'figure' );
					let next = fig && fig.nextElementSibling;
					if ( ! next || next.classList.contains( 'minn-block-island' ) ) {
						// Same manual-DOM pattern as the markdown --- divider.
						fig.insertAdjacentHTML( 'afterend', '<p><br></p>' );
						next = fig.nextElementSibling;
					}
					setCaret( next, 0 );
					return;
				}
				if ( ! s.isCollapsed ) return; // range deletes inside the caption are fine
				const edge = e.key === 'Backspace' ? 'start' : 'end';
				if ( caretAtCodeEdge( fc, s.anchorNode, s.anchorOffset, edge ) ) e.preventDefault();
			} );

			// Paste. Priority order: lone oEmbed URL into an empty block →
			// embed island (like Gutenberg); code contexts take the clipboard
			// TEXT (Chrome's default would insert the rich flavor — and its
			// insertText splits a pre into per-line <code>s); any rich HTML →
			// sanitized to the safe subset (see Paste cleanup section), never
			// inserted raw; multi-line plain text → real paragraphs. Single-line
			// plain text keeps Chrome's native handling.
			body.addEventListener( 'paste', ( e ) => {
				const ed2 = state.editor;
				const cd = e.clipboardData;
				if ( ! ed2 || ! cd ) return;
				const text = cd.getData( 'text/plain' ) || '';
				const trimmed = text.trim();
				if ( ed2.mode === 'blocks' && /^https?:\/\/\S+$/.test( trimmed ) && embedProviderFor( trimmed ) ) {
					const sel = window.getSelection();
					let node = sel && sel.anchorNode;
					while ( node && node.parentNode !== body ) node = node.parentNode;
					if ( node && node.parentNode === body ) {
						const existing = node.nodeType === 1 ? node.innerHTML : node.textContent;
						if ( stripTags( existing || '' ).trim() === '' ) {
							e.preventDefault();
							insertIsland( node, 'core/embed', embedTemplate( trimmed ) );
							return;
						}
					}
				}
				// Clipboard image FILES (a screenshot ⌘V) upload straight to the
				// library. When html rides along, prefer it unless it's just
				// the image's own tag — a Docs/Word copy of text-with-images
				// must keep its text, but a copied lone image should become a
				// self-hosted upload, not a hotlink.
				const imgFiles = B.caps.upload ? Array.from( cd.files || [] ).filter( ( f ) => /^image\//.test( f.type ) ) : [];
				const htmlFlavor = cd.getData( 'text/html' ) || '';
				if ( imgFiles.length && ( ! htmlFlavor.trim() || /^(?:<meta[^>]*>)?\s*<img[^>]*\/?>\s*$/i.test( htmlFlavor.trim() ) ) ) {
					e.preventDefault();
					insertImageFiles( body, imgFiles );
					return;
				}
				const sel = window.getSelection();
				const anchor = sel.rangeCount ? sel.anchorNode : null;
				if ( ! anchor || ! body.contains( anchor ) ) return;
				const anchorEl = anchor.nodeType === Node.ELEMENT_NODE ? anchor : anchor.parentNode;
				const html = cd.getData( 'text/html' );
				if ( anchorEl.closest( 'pre' ) || closestInlineCode( anchor ) ) {
					if ( ! text ) return;
					e.preventDefault();
					// insertHTML keeps newlines as literal text inside a pre
					// (probed); code chips are single-line, newlines flatten.
					const literal = anchorEl.closest( 'pre' )
						? text.replace( /\r\n?/g, '\n' )
						: text.replace( /\s*\n\s*/g, ' ' );
					document.execCommand( 'insertHTML', false, esc( literal ) );
					scheduleAutosave();
					return;
				}
				if ( html ) {
					// Rich flavor present: always ours from here — falling back
					// to the default would insert the raw vendor HTML.
					e.preventDefault();
					const payload = sanitizePastedHtml( html ) || ( trimmed ? pasteTextPayload( text ) : null );
					if ( payload ) pasteInsert( body, payload, anchorEl );
					scheduleAutosave();
					return;
				}
				if ( trimmed && /\n/.test( trimmed ) ) {
					e.preventDefault();
					const payload = pasteTextPayload( text );
					if ( payload ) pasteInsert( body, payload, anchorEl );
					scheduleAutosave();
				}
			} );
		}

		renderEditorSide();
		if ( ! ed.id ) $( '#minn-editor-title', view ).focus();
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
	// Raw camelCase attribute keys make poor field labels — "hasCustomCSS"
	// reads better as "Has custom CSS". Adapter labels always win.
	const humanizeAttrKey = ( key ) => {
		const words = String( key )
			.replace( /([a-z0-9])([A-Z])/g, '$1 $2' )
			.replace( /([A-Z]+)([A-Z][a-z])/g, '$1 $2' )
			.replace( /[_-]+/g, ' ' )
			.toLowerCase();
		return words.charAt( 0 ).toUpperCase() + words.slice( 1 );
	};
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

	// A child's TEXT lives in its saved HTML (c.tail), not its comment attrs —
	// core paragraph/heading "content" is a sourced attribute the schema form
	// rightly skips (rewriting it in the comment would do nothing). For
	// single-element text children, expose the inner HTML directly; the value
	// writes back verbatim so inline marks (<code>, <a>, <strong>) survive.
	const CHILD_TEXT_RE = /^(\s*<(p|h[1-6])(\s[^>]*)?>)([\s\S]*)(<\/\2>\s*<!--[\s\S]*)$/;
	function childTextOf( c ) {
		if ( c.selfClosing || ! c.tail ) return null;
		const m = c.tail.match( CHILD_TEXT_RE );
		return m ? { pre: m[ 1 ], inner: m[ 4 ], post: m[ 5 ] } : null;
	}

	/* Generic island text runs — the "I can't edit the content" answer for
	 * static-save blocks (Stackable et al). Their text lives as plain text
	 * nodes in saved HTML the schema form can't reach, however deeply the
	 * blocks nest. Scan a raw markup string for text nodes BY OFFSET (block
	 * comments, tags and style/script/svg subtrees skipped), edit each as a
	 * plain-text field, and splice changed runs back from last to first so
	 * offsets stay valid. Untouched runs are never rewritten — the island
	 * stays byte-identical except for the exact text the user changed. */
	const TEXTRUN_SKIP = [ 'style', 'script', 'svg', 'textarea' ];
	function decodeTextEntities( s ) {
		const el = document.createElement( 'textarea' );
		el.innerHTML = s;
		return el.value;
	}
	const escTextNode = ( s ) => String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' );
	function textRunsOf( str ) {
		const runs = [];
		if ( ! str ) return runs;
		const lower = str.toLowerCase();
		let i = 0;
		while ( i < str.length ) {
			if ( str.startsWith( '<!--', i ) ) {
				const end = str.indexOf( '-->', i );
				i = end === -1 ? str.length : end + 3;
			} else if ( str[ i ] === '<' ) {
				const end = str.indexOf( '>', i );
				if ( end === -1 ) break;
				const m = str.slice( i, end + 1 ).match( /^<\s*(\/?)\s*([a-zA-Z0-9-]+)/ );
				const tag = m ? m[ 2 ].toLowerCase() : '';
				if ( m && ! m[ 1 ] && TEXTRUN_SKIP.includes( tag ) && str[ end - 1 ] !== '/' ) {
					// Skip the whole subtree (icons, inline CSS) — nothing a
					// writer should edit lives in there.
					const close = lower.indexOf( '</' + tag, end );
					i = close === -1 ? str.length : Math.max( lower.indexOf( '>', close ) + 1, close + tag.length + 3 );
				} else {
					i = end + 1;
				}
			} else {
				let next = str.indexOf( '<', i );
				if ( next === -1 ) next = str.length;
				const raw = str.slice( i, next );
				if ( raw.trim() ) {
					const pre = raw.match( /^\s*/ )[ 0 ];
					const post = raw.match( /\s*$/ )[ 0 ];
					const core = raw.slice( pre.length, raw.length - post.length );
					runs.push( { start: i, end: next, pre, post, text: decodeTextEntities( core ), value: decodeTextEntities( core ) } );
				}
				i = next;
			}
		}
		return runs;
	}
	function spliceTextRuns( str, runs ) {
		if ( ! runs || ! runs.length ) return str;
		for ( let i = runs.length - 1; i >= 0; i-- ) {
			const r = runs[ i ];
			if ( r.value === r.text ) continue;
			str = str.slice( 0, r.start ) + r.pre + escTextNode( r.value ) + r.post + str.slice( r.end );
		}
		return str;
	}

	/* Island image swaps — the sibling of text runs for pictures. Static
	 * blocks mirror an image's URL between comment JSON (imageUrl /
	 * blockBackgroundMediaUrl) and saved HTML (img src, background-image
	 * style), so replacing the URL string everywhere keeps both in sync —
	 * the same surgery that localizes design-library images server-side.
	 * Best-effort extras: paired `XxxUrl`/`XxxId` media ids are retargeted
	 * inside the comment that carries the URL, and wp-image-N classes on
	 * swapped <img> tags follow the new attachment. */
	const ISLAND_IMG_RE = /https?:\/\/[^\s"'()\\<>]+\.(?:jpe?g|png|gif|webp|avif)/gi;
	// Server-authored markup (imported patterns, PHP wp_json_encode) may carry
	// JSON-escaped "https:\/\/…" forms — scan a slash-normalized copy too, so
	// those images still surface (Kadence's own importer handles the same
	// variant when it rewrites URLs).
	const islandImageUrls = ( raw ) => [ ...new Set( [
		...( raw.match( ISLAND_IMG_RE ) || [] ),
		...( raw.replace( /\\\//g, '/' ).match( ISLAND_IMG_RE ) || [] ),
	] ) ];
	const escRegex = ( s ) => s.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' );
	function swapIslandImage( raw, oldUrl, item ) {
		const newUrl = item && item.url;
		if ( ! newUrl || newUrl === oldUrl ) return raw;
		// serializeAttributes-escaped form (URLs containing "--" etc.).
		const encAttr = ( s ) => s.replace( /--/g, '\\u002d\\u002d' ).replace( /</g, '\\u003c' ).replace( />/g, '\\u003e' ).replace( /&/g, '\\u0026' );
		// PHP-side wp_json_encode escapes forward slashes — cover that form too.
		const slashEsc = ( s ) => s.split( '/' ).join( '\\/' );
		raw = raw.split( oldUrl ).join( newUrl );
		if ( encAttr( oldUrl ) !== oldUrl ) raw = raw.split( encAttr( oldUrl ) ).join( encAttr( newUrl ) );
		raw = raw.split( slashEsc( oldUrl ) ).join( slashEsc( newUrl ) );
		if ( item.id ) {
			const urlPat = '(?:' + escRegex( newUrl ) + '|' + escRegex( encAttr( newUrl ) ) + '|' + escRegex( slashEsc( newUrl ) ) + ')';
			raw = raw.replace( /<!--(?:(?!-->)[\s\S])*-->/g, ( comment ) => {
				let out = comment;
				// Named-key pairs, per-suite conventions (docs/block-suites.md):
				// imageUrl→imageId (EB), bgImg→bgImgID (Kadence),
				// backgroundImageURL→…ID — try both Id/ID casings.
				const keyRe = new RegExp( '"(\\w*?)(Url|URL|Src|Img|Image)":"' + urlPat + '"', 'g' );
				let m;
				while ( ( m = keyRe.exec( comment ) ) ) {
					const stems = /^(Img|Image)$/.test( m[ 2 ] )
						? [ m[ 1 ] + m[ 2 ] ]      // bgImg → bgImgID / bgImgId
						: [ m[ 1 ] ];               // imageUrl → imageId / imageID
					stems.forEach( ( stem ) => {
						out = out.replace( new RegExp( '"' + stem + '(Id|ID)":\\d+', 'g' ), '"' + stem + '$1":' + item.id );
					} );
				}
				// Media objects ({ "url": …, "id": N } in either order): bare
				// keys are only safe to retarget INSIDE the object that carries
				// the swapped URL.
				out = out.replace( new RegExp( '\\{[^{}]*"url":"' + urlPat + '"[^{}]*\\}', 'g' ), ( obj ) =>
					obj.replace( /"id":\d+/g, '"id":' + item.id ) );
				// GenerateBlocks: no URL attribute at all — mediaId pairs with
				// the src living in htmlAttributes/saved HTML. Comment-scoped:
				// a GB media comment carries exactly one mediaId.
				if ( new RegExp( urlPat ).test( comment ) ) {
					out = out.replace( /"mediaId":\d+/g, '"mediaId":' + item.id );
				}
				return out;
			} );
			// Attachment ids riding the swapped <img> tags themselves:
			// wp-image-N class (core convention), data-media-id (GB),
			// data-id (Otter slider) — either attribute order.
			const srcPat = 'src="' + escRegex( newUrl ) + '"';
			[ 'wp-image-', 'data-media-id="', 'data-id="' ].forEach( ( marker ) => {
				const mk = escRegex( marker );
				raw = raw.replace( new RegExp( '(<img[^>]*' + mk + ')\\d+([^>]*' + srcPat + ')', 'g' ), '$1' + item.id + '$2' );
				raw = raw.replace( new RegExp( '(<img[^>]*' + srcPat + '[^>]*' + mk + ')\\d+', 'g' ), '$1' + item.id );
			} );
		}
		return raw;
	}

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
			const label = esc( fd.label || humanizeAttrKey( key ) );
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
			const priority = ( attrs && key in attrs ) || ( Array.isArray( form.order ) && form.order.includes( key ) );
			const push = ( html ) => rows.push( { key, label, priority, html } );
			if ( control === 'select' && options ) {
				// An enum with no current value and no default must offer an
				// empty choice — otherwise the select forces its first option
				// and Apply injects an attr the block never had.
				const opts = ( cur == null && def.default === undefined && ! options.some( ( [ v ] ) => v === '' ) )
					? [ [ '', '—' ], ...options ] : options;
				push( `<div class="minn-field-label">${ label }</div>
				<select class="minn-input" data-insp="${ esc( id ) }">
					${ opts.map( ( [ v, l ] ) => `<option value="${ esc( v ) }"${ String( v ) === String( cur == null ? '' : cur ) ? ' selected' : '' }>${ esc( l ) }</option>` ).join( '' ) }
				</select>` );
			} else if ( control === 'checkbox' ) {
				push( `<label class="minn-insp-check"><input type="checkbox" class="minn-cb" data-insp="${ esc( id ) }" data-type="boolean"${ cur ? ' checked' : '' }> ${ label }</label>` );
			} else if ( control === 'number' ) {
				push( `<div class="minn-field-label">${ label }</div>
				<input type="number" class="minn-input" data-insp="${ esc( id ) }" data-type="number" value="${ cur == null ? '' : esc( cur ) }">` );
			} else if ( control === 'textarea' ) {
				push( `<div class="minn-field-label">${ label }</div>
				<textarea class="minn-input minn-insp-textarea" data-insp="${ esc( id ) }">${ esc( cur == null ? '' : String( cur ) ) }</textarea>` );
			} else {
				push( `<div class="minn-field-label">${ label }</div>
				<input class="minn-input" data-insp="${ esc( id ) }" value="${ esc( cur == null ? '' : String( cur ) ) }">` );
			}
		} );
		// SCALING: design suites register huge schemas (Spectra's post-grid:
		// 315 attributes) — a flat wall of fields is unusable. Fields the
		// block explicitly SETS (or an adapter ordered) stay in view; the
		// rest collapse behind "More settings" with a filter box. Small
		// schemas render flat, exactly as before.
		const MAX_FLAT = 16;
		if ( rows.length <= MAX_FLAT ) return rows.map( ( r ) => r.html ).join( '' );
		let head = rows.filter( ( r ) => r.priority );
		let rest = rows.filter( ( r ) => ! r.priority );
		if ( ! head.length ) {
			head = rest.slice( 0, 8 );
			rest = rest.slice( 8 );
		}
		if ( ! rest.length ) return head.map( ( r ) => r.html ).join( '' );
		return head.map( ( r ) => r.html ).join( '' )
			+ `<button type="button" class="minn-btn-soft minn-insp-more-btn" data-inspmore="${ esc( prefix ) }">More settings (${ rest.length })</button>`
			+ `<div class="minn-insp-more" data-inspmore-panel="${ esc( prefix ) }" hidden>
				<input class="minn-input minn-insp-filter" data-inspmore-filter placeholder="Filter settings…">
				${ rest.map( ( r ) => `<div class="minn-insp-row" data-fkey="${ esc( ( r.label + ' ' + r.key ).toLowerCase() ) }">${ r.html }</div>` ).join( '' ) }
			</div>`;
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

	// ONE placement rule for every block-config popover (island inspector,
	// table, image, code): BESIDE the block when it fits, else below its
	// bottom edge — never parked on top of the content being configured.
	function positionBlockPop( pop, anchorEl ) {
		const rect = anchorEl.getBoundingClientRect();
		const w = pop.offsetWidth || 320;
		const fitsRight = rect.right + 10 + w < window.innerWidth;
		pop.style.left = ( fitsRight ? rect.right + 10 : Math.max( 10, Math.min( rect.left, window.innerWidth - w - 12 ) ) ) + 'px';
		pop.style.top = Math.max( 10, Math.min( fitsRight ? rect.top : rect.bottom + 8, window.innerHeight - pop.offsetHeight - 10 ) ) + 'px';
	}

	function positionInspector( islandEl ) {
		if ( ! inspectorEl ) return;
		positionBlockPop( inspectorEl, islandEl );
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
			// Prototype per type: static children (saved HTML only their editor
			// JS can author) are added by CLONING a sibling — an empty
			// self-closing comment would render nothing and fail Gutenberg
			// validation (the hybrid-block trap). Attr-only children keep the
			// empty start; their schema form fills them in.
			model.addProto = {};
			model.children.forEach( ( c ) => {
				if ( ! model.addProto[ c.name ] ) {
					model.addProto[ c.name ] = { name: c.name, attrs: JSON.parse( JSON.stringify( c.attrs ) ), selfClosing: c.selfClosing, tail: c.tail };
				}
			} );
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
					// The matched text's offsets let the generic-run pass
					// below skip this string — without them the same text
					// rendered twice (labeled wrapperText field + a "Text"
					// run) and the two edits raced.
					const textStart = m.index + m[ 1 ].length;
					model.wt.push( { label: w.label || 'Text', pattern: w.pattern, loc, orig: m[ 2 ], value: m[ 2 ], start: textStart, end: textStart + m[ 2 ].length } );
					break;
				}
			}
		} );

		// Generic text runs: every text node in saved HTML becomes editable,
		// however deep the nesting (the Stackable case — schema-less children
		// whose content the attr form can't reach). Children that already get
		// the single-element text editor (childTextOf) are skipped, and runs
		// covered by a matched wrapperText pattern are dropped — the labeled
		// field wins — so no string is ever edited from two fields.
		const wtFilter = ( loc, runs ) => runs.filter( ( r ) =>
			! model.wt.some( ( w ) => w.loc === loc && r.start < w.end && r.end > w.start ) );
		model.children.forEach( ( c ) => {
			if ( ! c.selfClosing && ! childTextOf( c ) ) c.runs = textRunsOf( c.tail );
		} );
		if ( model.mode === 'structural' ) {
			model.headRuns = wtFilter( 'head', textRunsOf( model.head ) );
			model.tailRuns = wtFilter( 'tail', textRunsOf( model.tail ) );
		} else if ( model.mode === 'none' && ! parts.selfClosing ) {
			model.innerRuns = wtFilter( 'inner', textRunsOf( parts.inner ) );
		}
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
				} else if ( ! wasExplicit && (
					( def.default !== undefined && v === def.default )
					// No default and still empty/unchecked: an untouched form
					// row must not inject placeholder:"" / dropCap:false noise
					// into children it merely displayed.
					|| ( def.default === undefined && ( v === '' || v === false ) )
				) ) {
					// never present and still empty or at the default — skip
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
			const ta = inspectorEl.querySelector( `[data-insptext="${ i }"]` );
			if ( ta ) c.__text = ta.value;
		} );
		( insp.model.wt || [] ).forEach( ( w, i ) => {
			const input = inspectorEl.querySelector( `[data-insp="wt:${ i }"]` );
			if ( input ) w.value = input.value;
		} );
		// Generic text-run fields (head/inner/tail groups + per-child cN groups).
		$$( '[data-insprun]', inspectorEl ).forEach( ( input ) => {
			const [ group, j ] = input.dataset.insprun.split( ':' );
			const runs = group === 'head' ? insp.model.headRuns
				: group === 'inner' ? insp.model.innerRuns
				: group === 'tail' ? insp.model.tailRuns
				: ( insp.model.children[ Number( group.slice( 1 ) ) ] || {} ).runs;
			if ( runs && runs[ Number( j ) ] ) runs[ Number( j ) ].value = input.value;
		} );
	}

	function renderInspectorBody() {
		const insp = inspectorState;
		if ( ! insp || ! inspectorEl ) return;
		const { model, types } = insp;
		const ownType = types[ model.parts.name ];
		// Embed/gallery content lives in saved HTML — comment-attr edits alone
		// desync from it (a url field that "doesn't work"). Those blocks get
		// ONLY the rebuild actions below, never the generic form.
		const mediaRebuild = [ 'embed', 'gallery' ].includes( model.parts.name.replace( /^core\//, '' ) );
		// Generic text-run fields (saved-HTML text nodes, offset-addressed).
		const runRows = ( group, runs ) => ( runs || [] ).map( ( r, j ) => r.text.length > 40
			? `<textarea class="minn-input minn-insp-textarea" data-insprun="${ group }:${ j }">${ esc( r.value ) }</textarea>`
			: `<input class="minn-input" data-insprun="${ group }:${ j }" value="${ esc( r.value ) }">`
		).join( '' );
		const ownRunRows = mediaRebuild ? ''
			: runRows( 'head', model.headRuns ) + runRows( 'inner', model.innerRuns ) + runRows( 'tail', model.tailRuns );
		// Images anywhere in the island's markup — replaced via the media
		// picker (embed/gallery keep their dedicated rebuild flows instead).
		const imgSection = mediaRebuild || ! ( insp.images || [] ).length ? ''
			: `<div class="minn-field-label">Images</div>` + insp.images.map( ( u, i ) => `
				<div class="minn-insp-img-row">
					<img src="${ esc( u ) }" alt="" loading="lazy">
					<button class="minn-btn-soft" type="button" data-inspimg="${ i }">Replace…</button>
				</div>` ).join( '' );
		const ownFields = mediaRebuild ? '' : ( ownType && ownType.attributes ? inspectorFields( ownType.attributes, model.ownAttrs, 'own', model.parts.name ) : '' )
			+ ( model.wt || [] ).map( ( w, i ) => `<div class="minn-field-label">${ esc( w.label ) }</div>
			<input class="minn-input" data-insp="wt:${ i }" value="${ esc( w.value ) }">` ).join( '' )
			+ ( ownRunRows ? `<div class="minn-field-label">Text</div>${ ownRunRows }` : '' );
		const structural = model.mode === 'structural' && ! mediaRebuild;
		const childSections = mediaRebuild ? '' : model.children.map( ( c, i ) => {
			const t = types[ c.name ];
			const fields = t && t.attributes ? inspectorFields( t.attributes, c.attrs, String( i ), c.name ) : '';
			// The child's text (from its saved HTML) leads the section — it's
			// what a writer came to change; schema attrs follow.
			const ct = childTextOf( c );
			const textRow = ct ? `<div class="minn-field-label">text</div>
				<textarea class="minn-input minn-insp-textarea" data-insptext="${ i }">${ esc( ct.inner ) }</textarea>` : '';
			// Deep text runs for schema-less children (the Stackable case) —
			// mutually exclusive with the single-element text editor above.
			const runRowsC = ! textRow && c.runs && c.runs.length
				? `<div class="minn-field-label">text</div>${ runRows( 'c' + i, c.runs ) }` : '';
			if ( ! fields && ! textRow && ! runRowsC && ! structural ) return '';
			return `<div class="minn-insp-child">
				<div class="minn-insp-child-title">
					<span>${ i + 1 }. ${ esc( c.name.replace( /^core\//, '' ) ) }</span>
					${ structural ? `<span class="minn-insp-ctl">
						<button type="button" data-cmove="${ i }:-1" title="Move up"${ i === 0 ? ' disabled' : '' }>↑</button>
						<button type="button" data-cmove="${ i }:1" title="Move down"${ i === model.children.length - 1 ? ' disabled' : '' }>↓</button>
						<button type="button" data-cdel="${ i }" title="Remove">×</button>
					</span>` : '' }
				</div>
				${ textRow + runRowsC + fields || '<div class="minn-insp-note">No editable settings.</div>' }
			</div>`;
		} ).join( '' );
		// "+ Add" only for types whose schema we can form-edit.
		const addable = structural ? ( model.addTypes || [] ).filter( ( n ) => types[ n ] && types[ n ].attributes ) : [];
		const addRow = addable.length ? `<div class="minn-insp-add-row">
			${ addable.length > 1 ? `<select class="minn-input" id="minn-insp-add-type">${ addable.map( ( n ) => `<option value="${ esc( n ) }">${ esc( n.replace( /^core\//, '' ) ) }</option>` ).join( '' ) }</select>` : '' }
			<button class="minn-btn-soft" type="button" id="minn-insp-add"${ addable.length === 1 ? ` data-add-type="${ esc( addable[ 0 ] ) }"` : '' }>+ Add ${ addable.length === 1 ? esc( addable[ 0 ].split( '/' ).pop() ) : 'block' }</button>
		</div>` : '';

		// Embeds and galleries carry their content in saved HTML, so attribute
		// edits alone can't retarget them — offer a full rebuild through the
		// same templates that create them (URL swap / image re-pick).
		const short = model.parts.name.replace( /^core\//, '' );
		const special = short === 'embed' ? `
			<button class="minn-btn-soft" type="button" id="minn-insp-embed-url" style="width:100%; justify-content:center;">Change URL…</button>
			<div class="minn-insp-note">Rebuilds the embed for the new URL.</div>`
		: short === 'gallery' ? `
			<button class="minn-btn-soft" type="button" id="minn-insp-gallery" style="width:100%; justify-content:center;">Replace images…</button>
			<div class="minn-insp-note">Re-picks the gallery images; per-image captions and layout tweaks reset.</div>`
		: '';

		const editable = !! ( ownFields || childSections );
		inspectorEl.innerHTML = `
			<div class="minn-insp-head">
				<span class="minn-insp-title">${ esc( ( ownType && ownType.title ) || model.parts.name.replace( /^core\//, '' ) ) }</span>
				<button class="minn-x-btn" id="minn-insp-close" type="button">×</button>
			</div>
			<div class="minn-insp-body">
				${ special }
				${ imgSection }
				${ ownFields }
				${ childSections }
				${ addRow }
				${ editable || special || imgSection ? '' : `<div class="minn-insp-note">${ ownType
					? 'This block has no attributes a form can edit — its content lives in saved HTML. It stays preserved exactly as-is.'
					: 'This block type isn’t registered on this site, so its settings can’t be read. It stays preserved exactly as-is.' }</div>` }
			</div>
			<div class="minn-insp-actions">
				${ editable ? '<button class="minn-btn-primary" id="minn-insp-apply" type="button">Apply</button>' : '' }
				${ state.editor ? `<button type="button" class="minn-btn-soft" id="minn-insp-gutenberg" title="Design controls — layout, spacing, colors — live in the block editor. Saves this post first so unsaved blocks appear there.">Block editor&nbsp;↗</button>` : '' }
				<button class="minn-btn-soft danger" id="minn-insp-remove" type="button" title="Remove this block">${ icon( 'trash' ) }${ editable ? '' : ' Remove block' }</button>
			</div>`;
		positionInspector( insp.islandEl );
	}

	// Escape hatch to wp-admin's block editor. Always persists the live Minn
	// document first — islands/islands edits only exist in the browser until
	// save, so opening without save shows a stale post (Austin, 2026-07-09).
	async function openInBlockEditor( triggerEl ) {
		const ed = state.editor;
		if ( ! ed ) return;
		if ( ed.lockState === 'taken' || ed.lockState === 'blocked' ) {
			toast( 'Another session holds this post — take over before opening the block editor', true );
			return;
		}
		// Fold pending inspector field edits into the island so they ride the save
		// (clicking Block editor without Apply used to drop them).
		if ( inspectorState && inspectorEl && ed.islands && inspectorState.idx != null ) {
			const hasFields = inspectorEl.querySelector( '[data-insp], [data-insprun], [data-insptext], [data-inspimg]' );
			if ( hasFields || $( '#minn-insp-apply', inspectorEl ) ) {
				collectInspectorForms();
				const newRaw = buildInspectorRaw( inspectorState );
				if ( newRaw && ed.islands[ inspectorState.idx ] !== newRaw ) {
					ed.islands[ inspectorState.idx ] = newRaw;
					ed.dirty = true;
				}
			}
		}
		const label = triggerEl && ( triggerEl.textContent || '' ).trim();
		const setBusy = ( on ) => {
			if ( ! triggerEl ) return;
			triggerEl.disabled = !! on;
			if ( on ) triggerEl.textContent = 'Saving…';
			else if ( label ) triggerEl.textContent = label;
		};
		const go = ( id ) => {
			closeInspector();
			const url = B.site.adminUrl + 'post.php?post=' + id + '&action=edit';
			window.open( url, '_blank', 'noopener' );
		};
		// Clean and already has an id: nothing to flush.
		if ( ed.id && ! ed.dirty && ! state.saving ) {
			go( ed.id );
			return;
		}
		setBusy( true );
		try {
			await saveEditor( { _explicit: true } );
			if ( ! ed.id || ed.dirty ) {
				// doSaveEditor already toasted the error; leave the inspector open.
				return;
			}
			go( ed.id );
		} finally {
			setBusy( false );
		}
	}

	// Regenerate an island's stored markup wholesale (embed URL change,
	// gallery re-pick) and refresh its preview from a server render.
	function replaceIsland( idx, islandEl, template ) {
		const ed = state.editor;
		if ( ! ed || ! ed.islands || ed.islands[ idx ] == null ) return;
		ed.islands[ idx ] = template;
		const prev = islandEl && islandEl.querySelector( '.minn-island-preview' );
		if ( prev ) {
			const inner = stripBlockComments( template ).trim();
			if ( inner ) prev.innerHTML = inner;
		}
		api( 'minn-admin/v1/render-blocks', { method: 'POST', body: JSON.stringify( { blocks: [ template ], post: ( state.editor && state.editor.id ) || 0 } ) } )
			.then( ( r ) => {
				injectPreviewStyles( r && r.styles );
				const html = r && r.rendered && r.rendered[ 0 ];
				if ( prev && html && html.trim() ) prev.innerHTML = html;
				updateEditorStats();
			} )
			.catch( () => {} );
		toast( 'Block updated' );
		scheduleAutosave();
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

		inspectorState = { idx, model, types, islandEl, images: islandImageUrls( raw ) };
		renderInspectorBody();

		// Filter box inside a "More settings" panel — narrow by label/key.
		inspectorEl.addEventListener( 'input', ( e ) => {
			const f = e.target.closest( '[data-inspmore-filter]' );
			if ( ! f ) return;
			const q = f.value.trim().toLowerCase();
			$$( '.minn-insp-row', f.closest( '.minn-insp-more' ) ).forEach( ( row ) => {
				row.style.display = ! q || row.dataset.fkey.includes( q ) ? '' : 'none';
			} );
		} );

		// One delegated listener survives every structure-op re-render.
		inspectorEl.addEventListener( 'click', ( e ) => {
			const insp = inspectorState;
			if ( ! insp ) return;
			const moreBtn = e.target.closest( '[data-inspmore]' );
			if ( moreBtn ) {
				const panel = inspectorEl.querySelector( `[data-inspmore-panel="${ moreBtn.dataset.inspmore }"]` );
				if ( panel ) {
					panel.hidden = ! panel.hidden;
					moreBtn.textContent = panel.hidden
						? `More settings (${ panel.querySelectorAll( '.minn-insp-row' ).length })`
						: 'Fewer settings';
				}
				return;
			}
			if ( e.target.closest( '#minn-insp-close' ) ) { closeInspector(); return; }
			const gut = e.target.closest( '#minn-insp-gutenberg' );
			if ( gut ) {
				e.preventDefault();
				openInBlockEditor( gut );
				return;
			}
			if ( e.target.closest( '#minn-insp-remove' ) ) {
				const el = insp.islandEl;
				closeInspector();
				removeIslandWithUndo( el );
				return;
			}
			if ( e.target.closest( '#minn-insp-embed-url' ) ) {
				const current = ( insp.model.ownAttrs && insp.model.ownAttrs.url ) || '';
				const url = ( prompt( 'Embed URL (YouTube, tweet, audio…):', current ) || '' ).trim();
				if ( ! url || url === current ) return;
				if ( ! /^https?:\/\/\S+$/.test( url ) ) { toast( 'That doesn’t look like a URL', true ); return; }
				const { idx, islandEl: el } = insp;
				closeInspector();
				replaceIsland( idx, el, embedTemplate( url ) );
				return;
			}
			if ( e.target.closest( '#minn-insp-gallery' ) ) {
				// The media picker modal takes over the screen — close the
				// popover first and hold on to the island by index.
				const { idx, islandEl: el } = insp;
				closeInspector();
				openMediaPicker( ( picks ) => {
					if ( picks && picks.length ) replaceIsland( idx, el, galleryTemplate( picks ) );
				}, { multi: true } );
				return;
			}
			const rep = e.target.closest( '[data-inspimg]' );
			if ( rep ) {
				// Fold any pending field edits into the raw BEFORE the swap —
				// the picker modal closes the popover, and typed values must
				// not be lost with it.
				collectInspectorForms();
				const base = buildInspectorRaw( insp );
				const oldUrl = ( insp.images || [] )[ parseInt( rep.dataset.inspimg, 10 ) ];
				const { idx, islandEl: el } = insp;
				closeInspector();
				if ( ! oldUrl ) return;
				openMediaPicker( ( it ) => {
					if ( ! it || ! it.url ) return;
					replaceIsland( idx, el, swapIslandImage( base, oldUrl, it ) );
					toast( 'Image replaced' );
				} );
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
				const proto = name && insp.model.addProto && insp.model.addProto[ name ];
				if ( proto && ! proto.selfClosing ) {
					// Static child: clone the sibling prototype verbatim.
					// Duplicate uniqueIds are the plugin's own problem to heal
					// (Stackable regenerates them at render), and a clone
					// SHOULD look identical until edited.
					const clone = { name, attrs: JSON.parse( JSON.stringify( proto.attrs ) ), selfClosing: false, tail: proto.tail };
					if ( ! childTextOf( clone ) ) clone.runs = textRunsOf( clone.tail );
					insp.model.children.push( clone );
				} else if ( name ) {
					insp.model.children.push( { name, attrs: {}, selfClosing: true, tail: '' } );
				}
			}
			renderInspectorBody();
			if ( add ) {
				const body = $( '.minn-insp-body', inspectorEl );
				if ( body ) body.scrollTop = body.scrollHeight;
			}
		} );
	}

	// Rebuild an island's raw markup from the (already collected) inspector
	// model — shared by Apply and the image-replace flow, so pending field
	// edits are folded in either way and never lost.
	function buildInspectorRaw( insp ) {
		const { model } = insp;

		const childRaw = ( c ) => {
			const open = buildOpenComment( c.name, c.attrs, c.selfClosing );
			let tail = c.tail;
			// Edited child text splices into the saved HTML — only when it
			// actually changed, so untouched children stay byte-identical.
			if ( ! c.selfClosing && c.__text != null ) {
				const ct = childTextOf( c );
				if ( ct && ct.inner !== c.__text ) tail = ct.pre + c.__text + ct.post;
			}
			// Deep text runs (schema-less children): offsets were computed on
			// the pristine tail — mutually exclusive with the splice above.
			if ( c.runs ) tail = spliceTextRuns( tail, c.runs );
			return c.selfClosing ? open : open + tail;
		};

		let inner = model.parts.inner;
		// Offset-based text-run splices go FIRST — they address the pristine
		// strings; the regex wrapperText replacements below are
		// position-independent and follow safely.
		if ( model.innerRuns ) inner = spliceTextRuns( inner, model.innerRuns );
		if ( model.headRuns ) model.head = spliceTextRuns( model.head, model.headRuns );
		if ( model.tailRuns ) model.tail = spliceTextRuns( model.tail, model.tailRuns );
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
		let newRaw = model.parts.selfClosing ? open : open + inner + model.parts.close;
		// core/spacer keeps its height in BOTH the attrs and an inline style in
		// the saved HTML — regenerate the block so a height edit actually
		// applies (the embed/gallery lesson, in miniature).
		if ( model.parts.name === 'spacer' ) {
			let h = model.ownAttrs.height != null ? model.ownAttrs.height : '100px';
			if ( typeof h === 'number' ) h += 'px';
			h = String( h ).replace( /[^0-9a-z.%]/gi, '' ) || '100px';
			const sa = {};
			Object.keys( model.ownAttrs ).forEach( ( k ) => {
				if ( model.ownAttrs[ k ] !== '' && model.ownAttrs[ k ] != null ) sa[ k ] = model.ownAttrs[ k ];
			} );
			sa.height = h;
			newRaw = `<!-- wp:spacer${ serializeBlockAttrs( sa ) } -->\n<div style="height:${ h }" aria-hidden="true" class="wp-block-spacer"></div>\n<!-- /wp:spacer -->`;
		}
		return newRaw;
	}

	async function applyInspector( btn ) {
		const insp = inspectorState;
		const ed = state.editor;
		if ( ! insp || ! ed || ! inspectorEl ) return;
		collectInspectorForms();
		const newRaw = buildInspectorRaw( insp );

		btn.disabled = true;
		btn.textContent = 'Applying…';
		ed.islands[ insp.idx ] = newRaw;

		// Refresh the preview with a real server render; tolerate failure
		// (a misbehaving render callback must never break the editor).
		// Live-field islands (shortcode/details) use in-card editors instead of
		// a preview slot — sync those fields so an inspector Apply is visible.
		const islandEl = insp.islandEl || document.querySelector( `.minn-block-island[data-island="${ insp.idx }"]` );
		const scInput = islandEl && islandEl.querySelector( '.minn-shortcode-input' );
		if ( scInput ) scInput.value = stripBlockComments( newRaw ).trim();
		if ( islandEl && islandEl.classList.contains( 'minn-details-island' ) ) {
			const parts = parseDetailsRaw( newRaw );
			const sum = islandEl.querySelector( '.minn-details-summary' );
			const bodyEl = islandEl.querySelector( '.minn-details-body' );
			if ( sum ) sum.value = parts.summary;
			if ( bodyEl ) bodyEl.innerHTML = parts.bodyHtml && parts.bodyHtml.trim() ? parts.bodyHtml : '<p><br></p>';
		}
		const previewEl = document.querySelector( `.minn-island-preview[data-preview="${ insp.idx }"]` );
		try {
			const r = await api( 'minn-admin/v1/render-blocks', { method: 'POST', body: JSON.stringify( { blocks: [ newRaw ], post: ( state.editor && state.editor.id ) || 0 } ) } );
			injectPreviewStyles( r && r.styles );
			const html = r && r.rendered && r.rendered[ 0 ];
			if ( previewEl && html && html.trim() ) previewEl.innerHTML = html;
			updateEditorStats();
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
		// Shortcode islands host a live input (not a preview slot) — still
		// request a render for index alignment, but never overwrite the field.
		const blocks = ed.islands.map( ( r ) => ( r == null ? '' : r ) );
		api( 'minn-admin/v1/render-blocks', { method: 'POST', body: JSON.stringify( { blocks, post: ( state.editor && state.editor.id ) || 0 } ) } )
			.then( ( r ) => {
				injectPreviewStyles( r && r.styles );
				if ( ! r || ! Array.isArray( r.rendered ) || ! document.contains( body ) ) return;
				r.rendered.forEach( ( html, i ) => {
					const island = body.querySelector( `.minn-block-island[data-island="${ i }"]` );
					// Shortcode/details host live fields — never clobber them.
					if ( isLiveFieldIsland( island ) ) return;
					const el = body.querySelector( `.minn-island-preview[data-preview="${ i }"]` );
					if ( el && html && html.trim() ) el.innerHTML = html;
				} );
				// Preview text counts toward the word-count pill — recount now
				// that the real rendered content replaced the placeholders.
				updateEditorStats();
			} )
			.catch( () => {} );
	}

	/* ===== Table chip (row & column controls for editable tables) =====
	 * Same pattern as the code chip below: hover an editable table and a fixed
	 * chip appears; clicking it opens a popover with row/column/header ops that
	 * act on the CELL THE CARET SITS IN. Chip and popover live on
	 * document.body so they can never leak into serialized content. */

	let tableChipsBox = null;
	let tablePop = null;
	let tableChipsRaf = 0;

	function tablePopAway( e ) {
		if ( tablePop && ! tablePop.contains( e.target ) && ! ( e.target.closest && e.target.closest( '#minn-table-chips' ) ) ) hideTablePop();
	}

	function hideTablePop() {
		if ( tablePop ) tablePop.remove();
		tablePop = null;
		document.removeEventListener( 'mousedown', tablePopAway, true );
	}

	function clearTableChips() {
		if ( tableChipsBox ) tableChipsBox.remove();
		tableChipsBox = null;
		hideTablePop();
	}

	function tableChipFor( el ) {
		return tableChipsBox
			? Array.from( tableChipsBox.children ).find( ( c ) => c._target === el )
			: null;
	}

	function queueTableChips() {
		cancelAnimationFrame( tableChipsRaf );
		tableChipsRaf = requestAnimationFrame( syncTableChips );
	}

	// Chip and cutout highlight TOGETHER, like an island and its chip. The chip
	// lives outside the contenteditable so CSS :hover can't link them — done in
	// JS from both directions. The border ride is an inline style, stripped at
	// serialize (blocks mode drops top-level style attrs; classicHtml scrubs it).
	function setTableHot( el, on ) {
		if ( ! el || ! el.isConnected ) return;
		const box = el.closest( 'figure' ) || el;
		box.style.borderColor = on ? 'var(--accent)' : '';
		if ( ! box.getAttribute( 'style' ) ) box.removeAttribute( 'style' );
		const chip = tableChipFor( el );
		if ( chip ) chip.classList.toggle( 'hot', on );
	}

	// One persistent chip per top-level editable table, straddling the cutout
	// border like island chips do. Repositioned (not hidden) on scroll and
	// after every edit; hidden only when the table slides under the sticky
	// toolbar or out of the viewport.
	function syncTableChips() {
		const body = $( '#minn-editor-body' );
		const ed = state.editor;
		if ( ! body || ! ed || ed.mode === 'locked' || state.route !== 'editor' ) return clearTableChips();
		const targets = [];
		$$( ':scope > table, :scope > figure.wp-block-table table', body ).forEach( ( t ) => targets.push( { el: t, kind: 'table' } ) );
		$$( ':scope > figure.wp-block-image img, :scope > img', body ).forEach( ( i ) => targets.push( { el: i, kind: 'image' } ) );
		// Code blocks ride the same persistent chips as tables/images — the
		// old hover-shown chip flickered and only the chip itself was a hover
		// target. Verse/preformatted pres have no language to configure.
		$$( ':scope > pre:not(.wp-block-verse):not(.wp-block-preformatted)', body ).forEach( ( p ) => targets.push( { el: p, kind: 'code' } ) );
		if ( ! targets.length ) return clearTableChips();
		if ( ! tableChipsBox ) {
			tableChipsBox = document.createElement( 'div' );
			tableChipsBox.id = 'minn-table-chips';
			document.body.appendChild( tableChipsBox );
		}
		while ( tableChipsBox.children.length > targets.length ) tableChipsBox.lastChild.remove();
		const toolbar = $( '.minn-editor-toolbar' );
		const minTop = toolbar ? toolbar.getBoundingClientRect().bottom - 4 : 0;
		targets.forEach( ( t, i ) => {
			let chip = tableChipsBox.children[ i ];
			if ( ! chip ) {
				chip = document.createElement( 'button' );
				chip.type = 'button';
				chip.className = 'minn-code-chip';
				chip.addEventListener( 'mousedown', ( ev ) => ev.preventDefault() ); // keep the editor caret
				chip.addEventListener( 'click', () => {
					if ( ! chip._target || ! chip._target.isConnected ) return;
					if ( chip._kind === 'table' ) openTablePop( chip._target );
					else if ( chip._kind === 'code' ) openCodePop( chip._target );
					else openImgPop( chip._target );
				} );
				chip.addEventListener( 'mouseenter', () => setTableHot( chip._target, true ) );
				chip.addEventListener( 'mouseleave', () => setTableHot( chip._target, false ) );
				tableChipsBox.appendChild( chip );
			}
			chip._target = t.el;
			chip._kind = t.kind;
			// Code chips wear the language so a glance tells you what's set.
			chip.textContent = '\u2699 ' + ( t.kind === 'code'
				? ( codeLangOf( t.el ) === 'auto' ? 'code' : codeLangOf( t.el ) )
				: t.kind );
			chip.title = t.kind === 'table' ? 'Table \u2014 rows, columns, header'
				: t.kind === 'code' ? 'Code block \u2014 syntax highlighting'
				: 'Image \u2014 alt, caption, replace';
			const box = t.el.closest( 'figure' ) || t.el;
			const rect = box.getBoundingClientRect();
			const top = rect.top - 10;
			chip.style.top = top + 'px';
			chip.style.left = Math.max( 10, Math.min( rect.right - chip.offsetWidth - 12, window.innerWidth - chip.offsetWidth - 12 ) ) + 'px';
			chip.style.visibility = top < minTop || top > window.innerHeight ? 'hidden' : 'visible';
		} );
	}

	document.addEventListener( 'scroll', queueTableChips, true );
	window.addEventListener( 'resize', queueTableChips );

	// The cell the caret sits in; falls back to the table's first cell.
	function tableRefCell( table ) {
		const sel = window.getSelection();
		let n = sel.rangeCount ? sel.anchorNode : null;
		while ( n && n.nodeType !== Node.ELEMENT_NODE ) n = n.parentNode;
		const cell = n && n.closest ? n.closest( 'td, th' ) : null;
		return cell && table.contains( cell ) ? cell : table.querySelector( 'td, th' );
	}

	function tableNewCell( tag ) {
		const c = document.createElement( tag );
		c.innerHTML = '&nbsp;';
		return c;
	}

	/* ===== Shared context menu ===== */
	// One builder for right-click menus — entries are { label, run },
	// { label, href } (opens a new tab), or { heading }; danger: true tints
	// destructive items. Away-click closes; renderView sweeps strays.
	let minnMenuEl = null;

	function minnMenuAway( e ) {
		if ( minnMenuEl && ! minnMenuEl.contains( e.target ) ) hideMinnMenu();
	}

	function hideMinnMenu() {
		if ( minnMenuEl ) minnMenuEl.remove();
		minnMenuEl = null;
		document.removeEventListener( 'mousedown', minnMenuAway, true );
	}

	function openMinnMenu( x, y, entries ) {
		hideMinnMenu();
		minnMenuEl = document.createElement( 'div' );
		minnMenuEl.className = 'minn-new-menu minn-ctx-menu';
		minnMenuEl.innerHTML = entries.map( ( en, i ) =>
			en.heading != null
				? `<div class="minn-new-menu-label">${ esc( en.heading ) }</div>`
				: en.href
					? `<a href="${ esc( en.href ) }" target="_blank" rel="noopener"${ en.danger ? ' class="danger"' : '' }>${ esc( en.label ) }</a>`
					: `<button type="button" data-mi="${ i }"${ en.danger ? ' class="danger"' : '' }>${ esc( en.label ) }</button>`
		).join( '' );
		document.body.appendChild( minnMenuEl );
		minnMenuEl.style.left = Math.max( 10, Math.min( x, window.innerWidth - minnMenuEl.offsetWidth - 10 ) ) + 'px';
		minnMenuEl.style.top = Math.max( 10, Math.min( y, window.innerHeight - minnMenuEl.offsetHeight - 10 ) ) + 'px';
		$$( 'button[data-mi]', minnMenuEl ).forEach( ( b ) => b.addEventListener( 'click', () => {
			const en = entries[ parseInt( b.dataset.mi, 10 ) ];
			hideMinnMenu();
			if ( en && en.run ) en.run();
		} ) );
		$$( 'a', minnMenuEl ).forEach( ( a ) => a.addEventListener( 'click', hideMinnMenu ) );
		document.addEventListener( 'mousedown', minnMenuAway, true );
	}

	/* ===== Table context menu (right-click a cell) ===== */
	// The popover's ops act on the CARET cell — fine from the chip, awkward
	// mid-table. Right-click gives targeted ops on the cell under the pointer.
	let tableMenu = null;

	function tableMenuAway( e ) {
		if ( tableMenu && ! tableMenu.contains( e.target ) ) hideTableMenu();
	}

	function hideTableMenu() {
		if ( tableMenu ) tableMenu.remove();
		tableMenu = null;
		document.removeEventListener( 'mousedown', tableMenuAway, true );
	}

	function openTableMenu( x, y, table, cell ) {
		hideTableMenu();
		hideTablePop();
		tableMenu = document.createElement( 'div' );
		tableMenu.className = 'minn-new-menu minn-table-menu';
		tableMenu.innerHTML = `
			<button data-op="row-above" type="button">Add row above</button>
			<button data-op="row-below" type="button">Add row below</button>
			<button data-op="row-del" type="button" class="danger">Delete row</button>
			<div class="minn-new-menu-label">Column</div>
			<button data-op="col-left" type="button">Add column left</button>
			<button data-op="col-right" type="button">Add column right</button>
			<button data-op="col-del" type="button" class="danger">Delete column</button>`;
		document.body.appendChild( tableMenu );
		tableMenu.style.left = Math.max( 10, Math.min( x, window.innerWidth - tableMenu.offsetWidth - 10 ) ) + 'px';
		tableMenu.style.top = Math.max( 10, Math.min( y, window.innerHeight - tableMenu.offsetHeight - 10 ) ) + 'px';
		$$( 'button', tableMenu ).forEach( ( b ) => {
			b.addEventListener( 'mousedown', ( ev ) => ev.preventDefault() );
			b.addEventListener( 'click', () => {
				const op = b.dataset.op;
				hideTableMenu();
				// Seat the caret in the clicked cell so typing continues there.
				setCaret( cell, 0 );
				tableOp( table, op, cell );
			} );
		} );
		document.addEventListener( 'mousedown', tableMenuAway, true );
	}

	function openTablePop( table ) {
		hideTablePop();
		tablePop = document.createElement( 'div' );
		tablePop.className = 'minn-inspector minn-table-pop';
		tablePop.innerHTML = `
			<div class="minn-insp-head">
				<span class="minn-insp-title">Table</span>
				<button class="minn-x-btn" data-close type="button">\u00d7</button>
			</div>
			<div class="minn-insp-body">
				<div class="minn-field-label">Row \u2014 at the caret</div>
				<div class="minn-table-ops">
					<button class="minn-btn-soft" data-op="row-above" type="button">+ Above</button>
					<button class="minn-btn-soft" data-op="row-below" type="button">+ Below</button>
					<button class="minn-btn-soft danger" data-op="row-del" type="button">Delete</button>
				</div>
				<div class="minn-field-label">Column \u2014 at the caret</div>
				<div class="minn-table-ops">
					<button class="minn-btn-soft" data-op="col-left" type="button">+ Left</button>
					<button class="minn-btn-soft" data-op="col-right" type="button">+ Right</button>
					<button class="minn-btn-soft danger" data-op="col-del" type="button">Delete</button>
				</div>
				<div class="minn-table-ops">
					<button class="minn-btn-soft" data-op="header" type="button">${ table.tHead ? 'Remove header row' : 'Make first row a header' }</button>
					<button class="minn-btn-soft danger" data-op="table-del" type="button">Delete table</button>
				</div>
			</div>`;
		document.body.appendChild( tablePop );
		positionBlockPop( tablePop, table.closest( 'figure' ) || table );
		tablePop.querySelector( '[data-close]' ).addEventListener( 'click', hideTablePop );
		$$( '[data-op]', tablePop ).forEach( ( b ) => {
			b.addEventListener( 'mousedown', ( e ) => e.preventDefault() ); // the caret cell must survive the click
			b.addEventListener( 'click', () => tableOp( table, b.dataset.op ) );
		} );
		document.addEventListener( 'mousedown', tablePopAway, true );
	}

	// Apply a structural table mutation to a (detached) table. Returns
	// { deleteTable: true } when the table itself should go away.
	function applyTableMutation( table, op, cell ) {
		const row = cell ? cell.closest( 'tr' ) : null;
		const allRows = Array.from( table.querySelectorAll( 'tr' ) );
		if ( op === 'table-del' || ( op === 'row-del' && allRows.length <= 1 ) || ( op === 'col-del' && row && row.cells.length <= 1 ) ) {
			return { deleteTable: true };
		}
		if ( ! cell && op !== 'header' ) return null;
		if ( op === 'row-above' || op === 'row-below' ) {
			const tr = document.createElement( 'tr' );
			Array.from( row.cells ).forEach( () => tr.appendChild( tableNewCell( 'td' ) ) );
			if ( row.parentNode.tagName === 'THEAD' ) {
				// New rows are body rows — relative to a header they land at
				// the top of the body, never inside the thead.
				const tbody = table.tBodies[ 0 ];
				if ( tbody ) tbody.insertBefore( tr, tbody.firstChild );
				else table.appendChild( tr );
			} else {
				row.parentNode.insertBefore( tr, op === 'row-above' ? row : row.nextSibling );
			}
		} else if ( op === 'row-del' ) {
			const section = row.parentNode;
			row.remove();
			if ( section.tagName !== 'TABLE' && ! section.querySelector( 'tr' ) ) section.remove();
		} else if ( op === 'col-left' || op === 'col-right' ) {
			const idx = cell.cellIndex + ( op === 'col-right' ? 1 : 0 );
			allRows.forEach( ( r ) => {
				const tag = r.parentNode.tagName === 'THEAD' ? 'th' : 'td';
				r.insertBefore( tableNewCell( tag ), r.cells[ idx ] || null );
			} );
		} else if ( op === 'col-del' ) {
			const idx = cell.cellIndex;
			allRows.forEach( ( r ) => {
				if ( r.cells[ idx ] ) r.cells[ idx ].remove();
			} );
		} else if ( op === 'header' ) {
			if ( table.tHead ) {
				// The header row becomes the first body row — content kept.
				const hr = table.tHead.rows[ 0 ];
				const tr = document.createElement( 'tr' );
				Array.from( hr.cells ).forEach( ( c ) => {
					const td = document.createElement( 'td' );
					td.innerHTML = c.innerHTML;
					tr.appendChild( td );
				} );
				const tbody = table.tBodies[ 0 ];
				if ( tbody ) tbody.insertBefore( tr, tbody.firstChild );
				else table.appendChild( tr );
				table.tHead.remove();
			} else {
				const first = table.querySelector( 'tr' );
				if ( first ) {
					const thead = document.createElement( 'thead' );
					const tr = document.createElement( 'tr' );
					Array.from( first.cells ).forEach( ( c ) => {
						const th = document.createElement( 'th' );
						th.innerHTML = c.innerHTML;
						tr.appendChild( th );
					} );
					thead.appendChild( tr );
					first.remove();
					table.insertBefore( thead, table.firstChild );
				}
			}
		} else {
			return null;
		}
		return { deleteTable: false };
	}

	// Put a selection on a live block and run an execCommand so ⌘Z undoes it.
	// HARD-WON BLINK FACT: selectNode + insertHTML on a <figure> does NOT
	// replace the figure — it nests the payload inside (or leaves an empty
	// husk next to a sibling). For figures we selectNodeContents and swap
	// only the inner HTML; the shell stays. Bare tables accept selectNode.
	function commandOnBlock( liveEl, { contentsOnly, html, del } ) {
		const editorBody = $( '#minn-editor-body' );
		if ( ! editorBody || ! editorBody.contains( liveEl ) ) return false;
		editorBody.focus( { preventScroll: true } );
		const sel = window.getSelection();
		const r = document.createRange();
		if ( contentsOnly ) r.selectNodeContents( liveEl );
		else r.selectNode( liveEl );
		sel.removeAllRanges();
		sel.addRange( r );
		if ( del ) return document.execCommand( 'delete', false, null );
		return document.execCommand( 'insertHTML', false, html );
	}

	function tableOp( table, op, refCell ) {
		// refCell: the right-clicked cell from the context menu — beats the
		// caret cell, so ops land exactly where the pointer asked.
		const cell = ( refCell && refCell.isConnected ? refCell : null ) || tableRefCell( table );
		if ( ! cell && op !== 'table-del' ) return;
		const fig = table.closest( 'figure' ) || table;
		const editorBody = $( '#minn-editor-body' );
		if ( ! editorBody || ! editorBody.contains( fig ) ) return;

		// Mutate a detached clone, then swap via the browser command stack so
		// every row/col/header/delete change is a real ⌘Z step (see
		// docs/editor-roadmap.md). Direct DOM mutation is not undoable.
		const keepPop = !! tablePop;
		const isFigure = fig.tagName === 'FIGURE';
		const cloneRoot = fig.cloneNode( true );
		const cloneTable = isFigure ? cloneRoot.querySelector( 'table' ) : cloneRoot;
		if ( ! cloneTable && op !== 'table-del' ) return;

		let cloneCell = null;
		if ( cell && cloneTable ) {
			const liveRows = Array.from( table.querySelectorAll( 'tr' ) );
			const ri = liveRows.indexOf( cell.closest( 'tr' ) );
			const cloneRows = Array.from( cloneTable.querySelectorAll( 'tr' ) );
			cloneCell = ri >= 0 && cloneRows[ ri ] ? cloneRows[ ri ].cells[ cell.cellIndex ] : null;
		}

		const result = op === 'table-del'
			? { deleteTable: true }
			: applyTableMutation( cloneTable, op, cloneCell );
		if ( ! result ) return;

		if ( result.deleteTable ) {
			// Figure: delete contents (shell collapses; ⌘Z restores the table
			// into the previous figure position). Bare table: replace the node
			// with an empty paragraph.
			const ok = isFigure
				? commandOnBlock( fig, { contentsOnly: true, del: true } )
				: commandOnBlock( fig, { contentsOnly: false, html: '<p><br></p>' } );
			if ( ! ok ) return;
			hideTablePop();
			// Seat the caret in a real block if one remains (or the empty p).
			const landing = editorBody.querySelector( 'p, h1, h2, h3, h4, h5, h6, td, th' )
				|| editorBody.firstElementChild;
			if ( landing ) setCaret( landing, 0 );
			toast( 'Table deleted. ⌘Z restores it' );
		} else {
			// Figure shell stays; swap its inner HTML. Bare table swaps outer.
			const marker = 'minn-tbl-' + Date.now().toString( 36 );
			let ok;
			if ( isFigure ) {
				ok = commandOnBlock( fig, { contentsOnly: true, html: cloneRoot.innerHTML } );
			} else {
				cloneRoot.setAttribute( 'data-minn-tbl', marker );
				ok = commandOnBlock( fig, { contentsOnly: false, html: cloneRoot.outerHTML } );
			}
			if ( ! ok ) return;
			let newTable = null;
			if ( isFigure && fig.isConnected ) {
				// Live figure reference survives a contents-only swap.
				newTable = fig.querySelector( 'table' );
			} else {
				const marked = editorBody.querySelector( `[data-minn-tbl="${ marker }"]` );
				if ( marked ) {
					marked.removeAttribute( 'data-minn-tbl' );
					newTable = marked.tagName === 'TABLE' ? marked : marked.querySelector( 'table' );
				}
			}
			if ( newTable ) {
				const seat = newTable.querySelector( 'td, th' );
				if ( seat ) setCaret( seat, 0 );
				if ( keepPop ) openTablePop( newTable );
			} else {
				hideTablePop();
			}
			const destructiveMsg = {
				'row-del': 'Row deleted. ⌘Z restores it',
				'col-del': 'Column deleted. ⌘Z restores it',
			}[ op ];
			if ( destructiveMsg ) toast( destructiveMsg );
		}
		scheduleAutosave();
		updateEditorStats();
		// Geometry (and the header button label) changed — refresh chips.
		// Popover reopened above when keepPop and the table still exists.
		syncTableChips();
	}

	/* ===== Code block chip (config popout for editable code blocks) =====
	 * Islands get their ⚙ chip from the inspector; editable code blocks get an
	 * equivalent floating chip on hover (or caret-in, for keyboard/touch) that
	 * opens a small popover with the language picker. Both the chip and the
	 * popover live on document.body — never inside the contenteditable — so
	 * they can't leak into serialized content. */

	// Code blocks share the persistent chip system (syncTableChips) with
	// tables and images — only the config popover is code-specific.
	let codePop = null;

	function codePopAway( e ) {
		if ( codePop && ! codePop.contains( e.target ) && ! ( e.target.closest && e.target.closest( '#minn-table-chips' ) ) ) hideCodePop();
	}

	function hideCodePop() {
		if ( codePop ) codePop.remove();
		codePop = null;
		document.removeEventListener( 'mousedown', codePopAway, true );
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
		positionBlockPop( codePop, pre );
		codePop.querySelector( '[data-close]' ).addEventListener( 'click', hideCodePop );
		codePop.querySelector( '[data-lang]' ).addEventListener( 'change', ( e ) => {
			setCodeLang( pre, e.target.value );
			syncTableChips(); // refresh the chip's language label
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

	/* ===== Link popover (edit or create links in the editor) ===== */

	let linkPop = null;
	let linkPopSaved = null; // selection Range for create mode

	function linkPopAway( e ) {
		if ( linkPop && ! linkPop.contains( e.target ) ) hideLinkPop();
	}

	function hideLinkPop() {
		if ( linkPop ) linkPop.remove();
		linkPop = null;
		linkPopSaved = null;
		document.removeEventListener( 'mousedown', linkPopAway, true );
	}

	// Open on an existing <a> (edit/unlink) or on a selection Range (create).
	function openLinkPop( a, range ) {
		hideLinkPop();
		const body = $( '#minn-editor-body' );
		if ( ! body ) return;
		linkPopSaved = ! a && range ? range.cloneRange() : null;
		const href = a ? a.getAttribute( 'href' ) || '' : '';
		linkPop = document.createElement( 'div' );
		linkPop.className = 'minn-inspector minn-link-pop';
		linkPop.innerHTML = `
			<div class="minn-insp-head">
				<span class="minn-insp-title">Link</span>
				<button class="minn-x-btn" data-close type="button">\u00d7</button>
			</div>
			<div class="minn-insp-body">
				<div class="minn-field-label">URL or search</div>
				<input class="minn-input" data-link-url placeholder="https://\u2026 or search your content" value="${ esc( href ) }" spellcheck="false" autocomplete="off">
				<div class="minn-link-results" data-link-results hidden></div>
			</div>
			<div class="minn-insp-actions">
				<button class="minn-btn-primary" data-link-apply type="button">Apply</button>
				${ a ? '<button class="minn-btn-soft danger" data-link-remove type="button">Unlink</button>' : '' }
				${ a && href ? `<a class="minn-btn-soft" href="${ esc( href ) }" target="_blank" rel="noopener">Open \u2197</a>` : '' }
			</div>`;
		document.body.appendChild( linkPop );
		const rect = ( a || range ).getBoundingClientRect();
		const w = linkPop.offsetWidth || 320;
		linkPop.style.left = Math.max( 10, Math.min( rect.left, window.innerWidth - w - 12 ) ) + 'px';
		linkPop.style.top = Math.max( 10, Math.min( rect.bottom + 8, window.innerHeight - linkPop.offsetHeight - 10 ) ) + 'px';
		document.addEventListener( 'mousedown', linkPopAway, true );

		const urlInput = linkPop.querySelector( '[data-link-url]' );
		const apply = () => {
			const url = urlInput.value.trim();
			if ( a && a.isConnected ) {
				if ( url ) a.setAttribute( 'href', url );
				else unlink();
			} else if ( url && linkPopSaved && linkPopSaved.startContainer.isConnected ) {
				// preventScroll matters on every editor-body focus: focusing the
				// contenteditable scrolls its TOP into view BEFORE the saved
				// range is restored — Apply on a link deep in a long post
				// yanked the viewport to the start of the document.
				body.focus( { preventScroll: true } );
				const sel = window.getSelection();
				sel.removeAllRanges();
				sel.addRange( linkPopSaved );
				document.execCommand( 'createLink', false, url );
			}
			scheduleAutosave();
			hideLinkPop();
		};
		const unlink = () => {
			if ( a && a.isConnected ) {
				body.focus( { preventScroll: true } );
				const sel = window.getSelection();
				const r = document.createRange();
				r.selectNodeContents( a );
				sel.removeAllRanges();
				sel.addRange( r );
				document.execCommand( 'unlink', false, null );
			}
			scheduleAutosave();
			hideLinkPop();
		};
		linkPop.querySelector( '[data-close]' ).addEventListener( 'click', hideLinkPop );
		linkPop.querySelector( '[data-link-apply]' ).addEventListener( 'click', apply );

		// Internal link picker: anything that doesn't read as a URL searches
		// your own content (core wp/v2/search — posts, pages, CPTs with their
		// permalinks). Linking to your own writing beats pasting a URL.
		const results = linkPop.querySelector( '[data-link-results]' );
		let searchTimer = 0, resIdx = -1;
		const urlish = ( v ) => /^(https?:|mailto:|tel:|#|\/)/i.test( v ) || /^[\w-]+(\.[a-z]{2,})+(\/|$)/i.test( v );
		const renderResults = ( items ) => {
			resIdx = -1;
			results.hidden = ! items.length;
			results.innerHTML = items.map( ( r, i ) =>
				`<button type="button" class="minn-link-result" data-ri="${ i }" data-url="${ esc( r.url ) }">
					<span class="minn-link-result-title">${ esc( decodeEntities( r.title || '(no title)' ) ) }</span>
					<span class="minn-link-result-type">${ esc( r.subtype || r.type || '' ) }</span>
				</button>` ).join( '' );
		};
		urlInput.addEventListener( 'input', () => {
			clearTimeout( searchTimer );
			const q = urlInput.value.trim();
			if ( q.length < 2 || urlish( q ) ) { renderResults( [] ); return; }
			searchTimer = setTimeout( () => {
				api( 'wp/v2/search?per_page=6&_fields=id,title,url,type,subtype&search=' + encodeURIComponent( q ) )
					.then( ( items ) => {
						// The query may have changed while the request flew.
						if ( linkPop && urlInput.value.trim() === q ) renderResults( items );
					} )
					.catch( () => renderResults( [] ) );
			}, 250 );
		} );
		// mousedown (not click) + preventDefault — the editor selection must
		// survive into apply(), same rule as every popover in this file.
		results.addEventListener( 'mousedown', ( e ) => {
			const row = e.target.closest( '.minn-link-result' );
			if ( ! row ) return;
			e.preventDefault();
			urlInput.value = row.dataset.url;
			apply();
		} );
		urlInput.addEventListener( 'keydown', ( e ) => {
			const rows = $$( '.minn-link-result', results );
			if ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) {
				if ( results.hidden || ! rows.length ) return;
				e.preventDefault();
				resIdx = e.key === 'ArrowDown' ? Math.min( resIdx + 1, rows.length - 1 ) : Math.max( resIdx - 1, 0 );
				rows.forEach( ( el, i ) => el.classList.toggle( 'active', i === resIdx ) );
			} else if ( e.key === 'Enter' ) {
				e.preventDefault();
				if ( ! results.hidden && rows[ resIdx ] ) { urlInput.value = rows[ resIdx ].dataset.url; }
				apply();
			} else if ( e.key === 'Escape' ) {
				e.preventDefault();
				if ( ! results.hidden ) renderResults( [] );
				else hideLinkPop();
			}
		} );
		const rm = linkPop.querySelector( '[data-link-remove]' );
		if ( rm ) rm.addEventListener( 'click', unlink );
		if ( ! a ) urlInput.focus();
	}

	function hideImgPop() {
		if ( imgPop ) imgPop.remove();
		imgPop = null;
		imgPopTarget = null;
		document.removeEventListener( 'mousedown', imgPopAway, true );
	}

	function openImgPop( img ) {
		hideImgPop();
		hideCodePop();
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
		positionBlockPop( imgPop, img.closest( 'figure' ) || img );
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
				// Keep the (typable) caption element — empty ones are the
				// inline affordance and never serialize.
				fc.textContent = '';
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
			// No confirm dialog — deletion goes through the editing command
			// stack instead of element.remove(), so ⌘Z brings the image back.
			const target = img.closest( 'figure' ) || img;
			const editorBody = $( '#minn-editor-body' );
			if ( ! editorBody || ! editorBody.contains( target ) ) return hideImgPop();
			editorBody.focus( { preventScroll: true } );
			const sel = window.getSelection();
			const r = document.createRange();
			// Delete the figure's CONTENTS, not the figure: a selection spanning
			// the whole block makes Chrome merge the next paragraph into the
			// leftover husk. Contents-only deletion never crosses a block
			// boundary, ⌘Z restores into the same husk, and the serializer
			// drops media-less figure husks.
			if ( target.tagName === 'FIGURE' ) r.selectNodeContents( target );
			else r.selectNode( target );
			sel.removeAllRanges();
			sel.addRange( r );
			document.execCommand( 'delete', false, null );
			scheduleAutosave();
			toast( 'Image removed — ⌘Z restores it' );
			hideImgPop();
		} );
	}

	// Backspace/Delete arm-then-remove for atomic blocks (islands, empty code
	// <pre>, HR, media figures). Chrome treats contenteditable=false islands
	// as one deletable atom and would merge neighbors in a single keypress;
	// without an arm step, empty paragraphs after a shortcode also "jumped
	// over" the shortcode into the previous code block (Austin, 2026-07-09).
	//
	// One model everywhere:
	//   1st press at the edge → red outline (armed)
	//   2nd press             → remove with Undo toast
	// Live fields (shortcode input, details, buttons) join the same path when
	// the field is empty; otherwise typing stays normal text editing.
	function bindIslandGuards( body ) {
		let armed = null;
		const disarm = () => {
			if ( armed ) armed.classList.remove( 'minn-island-armed' );
			armed = null;
		};
		const isIsland = ( el ) => !!( el && el.classList && el.classList.contains( 'minn-block-island' ) );
		// Top-level blocks that arm/delete like islands when empty (or always
		// for HR). Not list/heading — those stay normal prose.
		const isAtomicEl = ( el ) => {
			if ( ! el || el.nodeType !== Node.ELEMENT_NODE || el.parentNode !== body ) return false;
			if ( isIsland( el ) ) return true;
			const t = el.tagName;
			if ( t === 'HR' ) return true;
			if ( t === 'PRE' ) return true;
			if ( t === 'FIGURE' && el.querySelector( 'img, video, audio, table' ) ) return true;
			if ( t === 'TABLE' ) return true;
			return false;
		};
		const isEmptyAtomic = ( el ) => {
			if ( ! isAtomicEl( el ) || isIsland( el ) ) return false;
			if ( el.tagName === 'HR' ) return true;
			if ( el.tagName === 'PRE' ) return ! el.textContent.replace( /\u00a0/g, ' ' ).trim();
			if ( el.tagName === 'FIGURE' ) {
				// Image husks (img removed, figure kept for undo) count as empty.
				return ! el.querySelector( 'img, video, audio' ) && ! ( el.querySelector( 'table' ) && el.textContent.trim() );
			}
			if ( el.tagName === 'TABLE' ) return ! el.textContent.trim();
			return false;
		};
		// Live-field islands: field is "done" / empty so Backspace/Delete should
		// arm the island instead of editing text.
		const liveFieldReady = ( el, key ) => {
			if ( ! el || ! el.closest ) return null;
			const field = el.closest( '.minn-shortcode-input, .minn-details-summary, .minn-details-body, .minn-btn-label, .minn-btn-url' );
			if ( ! field ) return null;
			const island = field.closest( '.minn-block-island' );
			if ( ! island || island.parentNode !== body ) return null;
			if ( field.matches( '.minn-details-body' ) ) {
				if ( field.textContent.replace( /\u00a0/g, ' ' ).trim() ) return null;
				return island;
			}
			const v = String( field.value != null ? field.value : '' );
			const t = v.replace( /\u00a0/g, ' ' ).trim();
			// Shortcode template seeds "[]" — treat bare brackets as empty so the
			// first Backspace arms the island rather than nibbling brackets forever.
			const empty = field.matches( '.minn-shortcode-input' )
				? ( ! t || t === '[]' || t === '[' || t === ']' )
				: ! t;
			if ( ! empty ) {
				// Non-empty: only arm when caret is at the field edge in the
				// delete direction (start+Backspace / end+Delete).
				if ( field.matches( '.minn-details-body' ) ) return null;
				const start = field.selectionStart;
				const end = field.selectionEnd;
				if ( start !== end ) return null;
				if ( key === 'Backspace' && start === 0 ) {
					// Prefer arming this island only when truly empty; at start
					// of a filled field, leave browser/default (no-op).
					return null;
				}
				if ( key === 'Delete' && end === v.length ) return null;
				return null;
			}
			return island;
		};
		const armOrRemove = ( target ) => {
			if ( ! target ) return;
			if ( armed === target ) {
				disarm();
				removeAtomicBlockWithUndo( target );
			} else {
				disarm();
				armed = target;
				target.classList.add( 'minn-island-armed' );
			}
		};

		// Capture phase so we see keydowns from shortcode/details inputs
		// (those fields stopPropagation on bubble for non-⌘ keys).
		body.addEventListener( 'keydown', ( e ) => {
			if ( e.key !== 'Backspace' && e.key !== 'Delete' ) {
				disarm();
				return;
			}
			if ( e.metaKey || e.ctrlKey || e.altKey ) return;

			// 1) Focus in a live-field island (shortcode input, etc.).
			const liveIsland = liveFieldReady( e.target, e.key );
			if ( liveIsland ) {
				e.preventDefault();
				e.stopPropagation();
				armOrRemove( liveIsland );
				return;
			}
			// Typing inside a non-empty live field: don't arm, don't steal the key.
			if ( e.target.closest && e.target.closest( '.minn-shortcode-input, .minn-details-summary, .minn-details-body, .minn-btn-label, .minn-btn-url' ) ) {
				disarm();
				return;
			}

			const sel = window.getSelection();
			if ( ! sel.rangeCount || ! sel.isCollapsed ) return;
			let block = sel.anchorNode;
			// Caret can land inside an island's chrome (rare) — walk out.
			if ( block && block.nodeType === Node.ELEMENT_NODE && block.closest ) {
				const inIsland = block.closest( '.minn-block-island' );
				if ( inIsland && inIsland.parentNode === body ) block = inIsland;
			}
			while ( block && block.parentNode !== body ) block = block.parentNode;
			if ( ! block || block.nodeType !== Node.ELEMENT_NODE ) return;

			// 2) Caret inside an empty atomic block (code <pre>, empty figure…):
			//    arm/remove THAT block — not the neighbor (was jumping from an
			//    empty code block to the Stackable island above).
			if ( isEmptyAtomic( block ) ) {
				e.preventDefault();
				armOrRemove( block );
				return;
			}
			// Caret inside a non-empty island (shouldn't type there, but if
			// focus landed on the island shell): arm/remove itself.
			if ( isIsland( block ) ) {
				e.preventDefault();
				armOrRemove( block );
				return;
			}

			// 3) Caret at the edge of a prose (or non-empty atomic) block,
			//    adjacent sibling is atomic → arm/remove the sibling.
			const back = e.key === 'Backspace';
			// Empty prose (just <br>/whitespace): both keys are "at the edge"
			// — range math around <br> is unreliable across browsers.
			const proseEmpty = ! block.textContent.replace( /\u00a0/g, ' ' ).trim()
				&& ! block.querySelector( 'img, table, ul, ol, pre, figure, video, audio' );
			let atEdge = proseEmpty;
			if ( ! atEdge ) {
				try {
					const caret = sel.getRangeAt( 0 );
					const edge = document.createRange();
					edge.selectNodeContents( block );
					if ( back ) edge.setEnd( caret.startContainer, caret.startOffset );
					else edge.setStart( caret.startContainer, caret.startOffset );
					atEdge = edge.toString() === '';
				} catch ( err ) {
					atEdge = false;
				}
			}
			if ( ! atEdge ) {
				disarm();
				return; // deletion stays inside the block — normal editing
			}
			const neighbor = back ? block.previousElementSibling : block.nextElementSibling;
			if ( ! isAtomicEl( neighbor ) ) {
				disarm();
				return;
			}
			// Non-empty code/figure: move the caret into it rather than arming
			// (user clears content first; empty atomic then arms on the next
			// press). Islands always arm — they aren't editable in place.
			if ( ! isIsland( neighbor ) && ! isEmptyAtomic( neighbor ) && neighbor.tagName !== 'HR' ) {
				if ( neighbor.tagName === 'PRE' || neighbor.tagName === 'FIGURE' || neighbor.tagName === 'TABLE' ) {
					e.preventDefault();
					const r = document.createRange();
					r.selectNodeContents( neighbor );
					r.collapse( ! back ); // backspace → end of previous; delete → start of next
					sel.removeAllRanges();
					sel.addRange( r );
					disarm();
					return;
				}
			}
			e.preventDefault();
			armOrRemove( neighbor );
		}, true );
		body.addEventListener( 'mousedown', disarm );
		body.addEventListener( 'blur', disarm );
	}

	/* ===== Inline code & markdown typing rules ===== */

	// A <code> that flows inside text — not a code block's <code> and not
	// anything inside an island.
	function closestInlineCode( node ) {
		while ( node && node.nodeType !== Node.ELEMENT_NODE ) node = node.parentNode;
		const code = node && node.closest ? node.closest( 'code' ) : null;
		if ( ! code || code.closest( 'pre' ) || code.closest( '.minn-block-island' ) ) return null;
		return code;
	}

	function setCaret( node, offset ) {
		const range = document.createRange();
		range.setStart( node, offset );
		range.collapse( true );
		const sel = window.getSelection();
		sel.removeAllRanges();
		sel.addRange( range );
	}

	function setCaretAfterInline( el ) {
		const parent = el.parentNode;
		setCaret( parent, Array.prototype.indexOf.call( parent.childNodes, el ) + 1 );
	}

	// True when a collapsed caret inside `code` has no text between itself and
	// the element's start/end — i.e. it sits on the boundary.
	function caretAtCodeEdge( code, container, offset, side ) {
		const r = document.createRange();
		r.selectNodeContents( code );
		if ( side === 'end' ) r.setStart( container, offset );
		else r.setEnd( container, offset );
		return r.toString() === '';
	}

	function toggleInlineCode( body ) {
		const sel = window.getSelection();
		if ( ! sel.rangeCount ) return;
		const range = sel.getRangeAt( 0 );
		if ( ! body.contains( range.commonAncestorContainer ) ) return;
		const existing = closestInlineCode( range.commonAncestorContainer );
		if ( existing ) {
			// Toggle off — the whole <code> becomes plain text again.
			const parent = existing.parentNode;
			while ( existing.firstChild ) parent.insertBefore( existing.firstChild, existing );
			parent.removeChild( existing );
			parent.normalize();
			return;
		}
		if ( range.collapsed ) return;
		// Only wrap within one top-level block — cross-block inline code isn't a thing.
		const blockOf = ( n ) => {
			while ( n && n.parentNode && n.parentNode !== body ) n = n.parentNode;
			return n;
		};
		if ( blockOf( range.startContainer ) !== blockOf( range.endContainer ) ) return;
		const code = document.createElement( 'code' );
		code.appendChild( range.extractContents() );
		// Any <code> swallowed by the selection collapses into the new one.
		$$( 'code', code ).forEach( ( c ) => {
			while ( c.firstChild ) c.parentNode.insertBefore( c.firstChild, c );
			c.remove();
		} );
		range.insertNode( code );
		setCaretAfterInline( code );
	}

	// Markdown typing rules + the inline-boundary escape, bound on the editor
	// body. Inline wraps fire on the closing delimiter's keydown, within one
	// text node: `code` · **bold** · *italic* · __bold__ · _italic_ ·
	// ~~strike~~ · [text](url). Block prefixes fire on space at the start of
	// a paragraph: #…###### headings · - * + bullets · 1. numbers · > quote —
	// plus ``` → code block and --- → divider. Wraps and prefix removals go
	// through execCommand so ⌘Z unwinds them back to the literal text.
	//
	// Boundary escape: contenteditable offers no caret position that types
	// OUTSIDE an inline element at its edges (Chrome extends the format), so
	// printable keys at a <code> edge are intercepted and inserted beside the
	// element — unconditionally for code chips, one-shot (mdEscape) for the
	// element a markdown wrap just created, so toolbar bold-then-keep-typing
	// still extends the bold run as users expect.
	function bindMarkdown( body ) {
		// The element the latest markdown wrap produced — typing at its end
		// boundary escapes outside once, then normal typing rules apply.
		let mdEscape = null;

		const topBlockOf = ( n ) => {
			while ( n && n.parentNode && n.parentNode !== body ) n = n.parentNode;
			return n && n.nodeType === Node.ELEMENT_NODE ? n : null;
		};

		// The element insertHTML just created, located from the collapsed caret.
		const justInserted = ( tag ) => {
			const s = window.getSelection();
			if ( ! s.rangeCount ) return null;
			const n = s.anchorNode;
			if ( n.nodeType === Node.ELEMENT_NODE && s.anchorOffset > 0 ) {
				const c = n.childNodes[ s.anchorOffset - 1 ];
				if ( c && c.nodeType === Node.ELEMENT_NODE && c.tagName === tag ) return c;
			}
			if ( n.nodeType === Node.TEXT_NODE && s.anchorOffset === 0 && n.previousSibling
				&& n.previousSibling.nodeType === Node.ELEMENT_NODE && n.previousSibling.tagName === tag ) {
				return n.previousSibling;
			}
			const el = n.nodeType === Node.ELEMENT_NODE ? n : n.parentNode;
			return el && el.closest ? el.closest( tag.toLowerCase() ) : null;
		};

		body.addEventListener( 'keydown', ( e ) => {
			if ( e.metaKey || e.ctrlKey || e.altKey || e.key.length !== 1 ) return;
			const sel = window.getSelection();
			if ( ! sel.rangeCount || ! sel.isCollapsed ) return;
			const node = sel.anchorNode;
			if ( ! node || ! body.contains( node ) ) return;

			// A plain space inserted at a block edge or beside existing
			// whitespace is collapsed — Chrome strips it on the next keystroke
			// and tucks the caret back inside the <code>, or rebalances it
			// destructively. Use nbsp there (as Chrome's own typing does);
			// against a non-space character a clean regular space is fine.
			const spaceFor = ( codeEl, side ) => {
				let blockEl = codeEl;
				while ( blockEl.parentNode && blockEl.parentNode !== body ) blockEl = blockEl.parentNode;
				const r = document.createRange();
				r.selectNodeContents( blockEl );
				if ( side === 'start' ) r.setEndBefore( codeEl );
				else r.setStartAfter( codeEl );
				const rest = r.toString();
				const adjacent = side === 'start' ? rest.slice( -1 ) : rest.charAt( 0 );
				return adjacent && ! /\s/.test( adjacent ) ? ' ' : ' ';
			};
			const keyText = ( codeEl, side ) =>
				document.createTextNode( e.key === ' ' ? spaceFor( codeEl, side ) : e.key );
			const typed = ( t ) => {
				setCaret( t, 1 );
				scheduleAutosave();
			};
			const escapeOutside = ( el, side ) => {
				e.preventDefault();
				const t = keyText( el, side );
				if ( side === 'start' ) el.before( t );
				else el.after( t );
				typed( t );
			};

			// Element-level caret directly after a boundary element (as left
			// by a wrap or the toolbar): type beside it, not into it.
			if ( node.nodeType === Node.ELEMENT_NODE ) {
				const before = node.childNodes[ sel.anchorOffset - 1 ];
				if ( before && before.nodeType === Node.ELEMENT_NODE ) {
					if ( before.tagName === 'CODE' && closestInlineCode( before ) === before ) return escapeOutside( before, 'end' );
					if ( before === mdEscape ) {
						mdEscape = null;
						return escapeOutside( before, 'end' );
					}
				}
				return;
			}

			// Inside an inline code chip: escape at the edges, otherwise type
			// freely — markdown delimiters stay literal inside code.
			const code = closestInlineCode( node );
			if ( code ) {
				if ( caretAtCodeEdge( code, node, sel.anchorOffset, 'end' ) ) return escapeOutside( code, 'end' );
				if ( caretAtCodeEdge( code, node, sel.anchorOffset, 'start' ) ) return escapeOutside( code, 'start' );
				return;
			}

			// One-shot escape at the end of a fresh markdown wrap.
			if ( mdEscape && mdEscape.contains( node ) && caretAtCodeEdge( mdEscape, node, sel.anchorOffset, 'end' ) ) {
				const el = mdEscape;
				mdEscape = null;
				return escapeOutside( el, 'end' );
			}

			if ( node.parentNode.closest( 'pre' ) ) return; // block code: literal

			const upto = node.textContent.slice( 0, sel.anchorOffset );

			// Inline wrap through the undo stack: the typed delimiter is never
			// inserted; the matched source text becomes the element.
			const wrapInline = ( startIdx, tag, inner, attrs = '' ) => {
				e.preventDefault();
				const r = document.createRange();
				r.setStart( node, startIdx );
				r.setEnd( node, sel.anchorOffset );
				if ( tag === 'code' ) {
					// Blink's insertHTML keeps strong/em/s/a but rewrites <code>
					// into a styled <span> — build it manually (this one wrap
					// sits outside the undo stack, the others don't).
					r.deleteContents();
					const codeEl = document.createElement( 'code' );
					codeEl.textContent = inner;
					r.insertNode( codeEl );
					setCaretAfterInline( codeEl );
					scheduleAutosave();
					return;
				}
				sel.removeAllRanges();
				sel.addRange( r );
				document.execCommand( 'insertHTML', false, `<${ tag }${ attrs }>${ esc( inner ) }</${ tag }>` );
				const el = justInserted( tag.toUpperCase() );
				if ( el ) {
					// insertHTML rebalances a preceding mid-sentence space into
					// nbsp — put the plain space back.
					const prev = el.previousSibling;
					if ( prev && prev.nodeType === Node.TEXT_NODE && /\S $/.test( prev.textContent ) ) {
						prev.textContent = prev.textContent.slice( 0, -1 ) + ' ';
					}
					mdEscape = el;
				}
				scheduleAutosave();
			};

			let m;
			if ( e.key === '`' ) {
				// ``` alone in a paragraph → code block.
				const blockEl = topBlockOf( node );
				if ( blockEl && blockEl.tagName === 'P' && blockEl.textContent.trim() === '``' && upto.endsWith( '``' ) ) {
					e.preventDefault();
					const r = document.createRange();
					r.selectNodeContents( blockEl );
					sel.removeAllRanges();
					sel.addRange( r );
					document.execCommand( 'delete', false, null );
					document.execCommand( 'formatBlock', false, 'pre' );
					scheduleAutosave();
					return;
				}
				m = /`([^`]+)$/.exec( upto );
				if ( m && m[ 1 ].trim() ) wrapInline( m.index, 'code', m[ 1 ] );
			} else if ( e.key === '*' ) {
				if ( ( m = /\*\*([^\s*](?:[^*]*[^\s*])?)\*$/.exec( upto ) ) ) wrapInline( m.index, 'strong', m[ 1 ] );
				else if ( ( m = /(^|[^*])\*([^\s*](?:[^*]*[^\s*])?)$/.exec( upto ) ) ) wrapInline( m.index + m[ 1 ].length, 'em', m[ 2 ] );
			} else if ( e.key === '_' ) {
				// Underscores fire on word boundaries only — snake_case stays.
				if ( ( m = /(^|[\s"'(])__([^\s_](?:[^_]*[^\s_])?)_$/.exec( upto ) ) ) wrapInline( m.index + m[ 1 ].length, 'strong', m[ 2 ] );
				else if ( ( m = /(^|[\s"'(])_([^\s_](?:[^_]*[^\s_])?)$/.exec( upto ) ) ) wrapInline( m.index + m[ 1 ].length, 'em', m[ 2 ] );
			} else if ( e.key === '~' ) {
				if ( ( m = /~~([^\s~](?:[^~]*[^\s~])?)~$/.exec( upto ) ) ) wrapInline( m.index, 's', m[ 1 ] );
			} else if ( e.key === ')' ) {
				// [text](url) — only for things that look like a destination,
				// so array[0](call) style prose never converts.
				m = /\[([^\[\]]+)\]\(((?:https?:\/\/|\/|#|mailto:)[^)\s]*)$/.exec( upto );
				if ( m ) wrapInline( m.index, 'a', m[ 1 ], ` href="${ esc( m[ 2 ] ) }"` );
			} else if ( e.key === ' ' ) {
				// Block prefixes at the start of a paragraph.
				const blockEl = topBlockOf( node );
				if ( ! blockEl || blockEl.tagName !== 'P' ) return;
				const r = document.createRange();
				r.selectNodeContents( blockEl );
				r.setEnd( node, sel.anchorOffset );
				const prefix = r.toString();
				let block = null;
				let list = null;
				if ( /^#{1,6}$/.test( prefix ) ) block = 'h' + prefix.length;
				else if ( /^[-*+]$/.test( prefix ) ) list = 'insertUnorderedList';
				else if ( /^1[.)]$/.test( prefix ) ) list = 'insertOrderedList';
				else if ( prefix === '>' ) block = 'blockquote';
				if ( ! block && ! list ) return;
				e.preventDefault();
				sel.removeAllRanges();
				sel.addRange( r );
				document.execCommand( 'delete', false, null );
				if ( list ) {
					document.execCommand( list, false, null );
					liftNestedLists( body );
				} else {
					document.execCommand( 'formatBlock', false, block );
				}
				scheduleAutosave();
			} else if ( e.key === '-' ) {
				// --- alone in a paragraph → divider, caret stays in the
				// (emptied) paragraph below it.
				const blockEl = topBlockOf( node );
				if ( blockEl && blockEl.tagName === 'P' && blockEl.textContent.trim() === '--' && upto.endsWith( '--' ) ) {
					e.preventDefault();
					blockEl.insertAdjacentHTML( 'beforebegin', '<hr>' );
					blockEl.textContent = '';
					blockEl.appendChild( document.createElement( 'br' ) );
					const r = document.createRange();
					r.selectNodeContents( blockEl );
					r.collapse( true );
					sel.removeAllRanges();
					sel.addRange( r );
					scheduleAutosave();
				}
			}
		} );
	}

	/* ===== Paste cleanup (Word / Google Docs / arbitrary HTML → safe subset) ===== */

	// Clipboard HTML is never inserted raw: it carries vendor styling by the
	// kilobyte, javascript: hrefs and on* handlers. sanitizePastedHtml() rewrites
	// it into exactly the vocabulary the serializers know how to store — p,
	// h1-h6, ul/ol/li, blockquote, pre>code, figure>img, figure>table, hr, with
	// strong/em/s/code/a/kbd/sub/sup/br inline. Everything else unwraps to its
	// text; nothing unknown passes through.

	const PASTE_DROP = new Set( [ 'SCRIPT', 'STYLE', 'LINK', 'META', 'TITLE', 'BASE', 'HEAD', 'IFRAME', 'FRAME', 'FRAMESET', 'OBJECT', 'EMBED', 'APPLET', 'svg', 'SVG', 'MATH', 'CANVAS', 'NOSCRIPT', 'TEMPLATE', 'BUTTON', 'INPUT', 'SELECT', 'OPTION', 'TEXTAREA', 'AUDIO', 'VIDEO', 'SOURCE', 'TRACK', 'DIALOG', 'XML' ] );
	const PASTE_INLINE_MAP = { STRONG: 'strong', B: 'strong', EM: 'em', I: 'em', S: 's', STRIKE: 's', DEL: 's', CODE: 'code', SAMP: 'code', TT: 'code', KBD: 'kbd', SUB: 'sub', SUP: 'sup' };
	const PASTE_BLOCK_SEL = 'p, h1, h2, h3, h4, h5, h6, ul, ol, pre, table, blockquote, hr, figure, li';

	// Vendor styling → intent. Google Docs marks bold/italic/mono via span
	// styles — and wraps whole payloads in <b style="font-weight:normal">,
	// which must NOT read as bold.
	function pasteMarksOf( el, ctx ) {
		const st = el.style || {};
		const w = String( st.fontWeight || '' );
		const marks = [];
		const mapped = PASTE_INLINE_MAP[ el.tagName ];
		const unbolded = /^(normal|[1-4]00)$/.test( w );
		if ( mapped && ! ( mapped === 'strong' && unbolded ) ) marks.push( mapped );
		if ( w === 'bold' || w === 'bolder' || parseInt( w, 10 ) >= 600 ) marks.push( 'strong' );
		if ( st.fontStyle === 'italic' ) marks.push( 'em' );
		if ( /line-through/.test( st.textDecoration || st.textDecorationLine || '' ) ) marks.push( 's' );
		if ( /mono|courier|consolas|menlo|jetbrains|source ?code/i.test( st.fontFamily || '' ) ) marks.push( 'code' );
		// Headings are bold already — a strong wrap would just be noise.
		return marks.filter( ( m, i ) => marks.indexOf( m ) === i && ! ctx.active.has( m ) && ! ( m === 'strong' && ctx.heading ) );
	}

	// Inline content of one node. Block children flow in with <br> boundaries
	// (Docs puts <p> inside <li>; Word puts <div>s in table cells). Images are
	// handed to ctx.onImage — a paragraph can't host a block-level image.
	function pasteInline( node, ctx ) {
		let out = '';
		Array.from( node.childNodes ).forEach( ( n ) => {
			if ( n.nodeType === Node.TEXT_NODE ) {
				out += esc( n.textContent.replace( / /g, ' ' ) );
				return;
			}
			if ( n.nodeType !== Node.ELEMENT_NODE || PASTE_DROP.has( n.tagName ) ) return;
			const tag = n.tagName;
			if ( tag === 'BR' ) {
				out += '<br>';
				return;
			}
			if ( tag === 'IMG' ) {
				if ( ctx.onImage ) ctx.onImage( n );
				return;
			}
			if ( tag === 'A' ) {
				const href = ( n.getAttribute( 'href' ) || '' ).trim();
				const inner = pasteInline( n, ctx );
				if ( ! inner ) return;
				out += /^(https?:|mailto:|tel:|#|\/)/i.test( href ) ? `<a href="${ esc( href ) }">${ inner }</a>` : inner;
				return;
			}
			if ( /^(P|DIV|H[1-6]|BLOCKQUOTE|SECTION|ARTICLE|TABLE|TR|PRE)$/.test( tag ) ) {
				const inner = pasteInline( n, ctx );
				if ( inner ) out += ( out ? '<br>' : '' ) + inner;
				return;
			}
			if ( tag === 'UL' || tag === 'OL' ) {
				out += pasteList( n, ctx );
				return;
			}
			if ( tag === 'LI' || tag === 'TD' || tag === 'TH' ) {
				const inner = pasteInline( n, ctx );
				if ( inner ) out += ( out ? '<br>' : '' ) + inner;
				return;
			}
			const marks = pasteMarksOf( n, ctx );
			marks.forEach( ( m ) => ctx.active.add( m ) );
			const inner = pasteInline( n, ctx );
			marks.forEach( ( m ) => ctx.active.delete( m ) );
			if ( ! inner ) return;
			out += marks.map( ( m ) => `<${ m }>` ).join( '' ) + inner + marks.slice().reverse().map( ( m ) => `</${ m }>` ).join( '' );
		} );
		return out;
	}

	const pasteHasInk = ( html ) => !! stripTags( html ).trim();
	const pasteAlignClass = ( el ) => {
		const m = ( el.className || '' ).match( /has-text-align-(center|right)/ )
			|| ( el.style && /^(center|right)$/.test( el.style.textAlign ) ? [ 0, el.style.textAlign ] : null );
		return m ? ` class="has-text-align-${ m[ 1 ] }"` : '';
	};

	function pasteList( el, ctx ) {
		const tag = el.tagName === 'OL' ? 'ol' : 'ul';
		let attrs = '';
		if ( tag === 'ol' ) {
			const start = parseInt( el.getAttribute( 'start' ), 10 );
			const type = el.getAttribute( 'type' );
			if ( start ) attrs += ` start="${ start }"`;
			if ( el.hasAttribute( 'reversed' ) ) attrs += ' reversed';
			if ( type && /^[1AaIi]$/.test( type ) ) attrs += ` type="${ type }"`;
		}
		const items = Array.from( el.children )
			.filter( ( c ) => c.tagName === 'LI' )
			.map( ( li ) => `<li>${ pasteInline( li, ctx ) }</li>` )
			.filter( ( li ) => pasteHasInk( li ) )
			.join( '' );
		return items ? `<${ tag }${ attrs }>${ items }</${ tag }>` : '';
	}

	// A pre's text with line structure intact: <br> and block-element
	// boundaries are newlines (GitHub-style line-per-div listings).
	function pasteCodeText( el ) {
		let out = '';
		const walk = ( node ) => {
			Array.from( node.childNodes ).forEach( ( n ) => {
				if ( n.nodeType === Node.TEXT_NODE ) {
					out += n.textContent.replace( / /g, ' ' );
					return;
				}
				if ( n.nodeType !== Node.ELEMENT_NODE || PASTE_DROP.has( n.tagName ) ) return;
				if ( n.tagName === 'BR' ) {
					out += '\n';
					return;
				}
				const block = /^(P|DIV|TR|LI)$/.test( n.tagName );
				if ( block && out && ! out.endsWith( '\n' ) ) out += '\n';
				walk( n );
				if ( block && ! out.endsWith( '\n' ) ) out += '\n';
			} );
		};
		walk( el );
		return out.replace( /\n$/, '' );
	}

	function pasteCodeBlock( el ) {
		const text = pasteCodeText( el );
		if ( ! text.trim() ) return '';
		const cls = ( ( el.className || '' ) + ' ' + ( ( el.querySelector( 'code' ) || {} ).className || '' ) ).match( /language-([a-z0-9+-]+)/i );
		return `<pre class="wp-block-code"><code${ cls ? ` class="language-${ cls[ 1 ].toLowerCase() }"` : '' }>${ esc( text ) }</code></pre>`;
	}

	function pasteTable( t, ctx ) {
		const cell = ( c ) => {
			const tag = c.tagName === 'TH' ? 'th' : 'td';
			const span = ( a ) => {
				const v = parseInt( c.getAttribute( a ), 10 );
				return v > 1 ? ` ${ a }="${ v }"` : '';
			};
			return `<${ tag }${ span( 'colspan' ) }${ span( 'rowspan' ) }>${ pasteInline( c, ctx ) }</${ tag }>`;
		};
		const row = ( tr ) => `<tr>${ Array.from( tr.cells ).map( cell ).join( '' ) }</tr>`;
		const head = t.tHead ? Array.from( t.tHead.rows ).map( row ).join( '' ) : '';
		const bodyRows = Array.from( t.rows ).filter( ( r ) => ! t.tHead || ! t.tHead.contains( r ) ).map( row ).join( '' );
		if ( ! head && ! bodyRows ) return '';
		return `<figure class="wp-block-table"><table>${ head ? `<thead>${ head }</thead>` : '' }<tbody>${ bodyRows }</tbody></table></figure>`;
	}

	function pasteImage( img, ctx, figureEl ) {
		const src = ( img.getAttribute( 'src' ) || '' ).trim();
		if ( ! /^https?:\/\//i.test( src ) ) return ''; // data:/file: payloads are dead weight
		const alt = img.getAttribute( 'alt' ) || '';
		const cap = figureEl && ctx ? ( () => {
			const fc = figureEl.querySelector( ':scope > figcaption' );
			const inner = fc ? pasteInline( fc, ctx ) : '';
			return inner ? `<figcaption class="wp-element-caption">${ inner }</figcaption>` : '';
		} )() : '';
		return `<figure class="wp-block-image"><img src="${ esc( src ) }" alt="${ esc( alt ) }">${ cap }</figure>`;
	}

	function pasteQuote( q, ctx ) {
		const inner = [];
		collectPasteBlocks( q, inner, ctx );
		const cite = q.querySelector( ':scope > cite' );
		const ps = inner
			.map( ( b ) => b.replace( /^<(?:h[1-6]|p)[^>]*>/, '<p>' ).replace( /<\/(?:h[1-6]|p)>$/, '</p>' ) )
			.filter( ( b ) => /^<p>/.test( b ) && pasteHasInk( b ) )
			.join( '' );
		if ( ! ps && ! cite ) return '';
		return `<blockquote class="wp-block-quote">${ ps || '<p></p>' }${ cite ? `<cite>${ pasteInline( cite, ctx ) }</cite>` : '' }</blockquote>`;
	}

	// Desktop Word emits list items as paragraphs carrying mso-list styles with
	// the bullet/number in a "mso-list:Ignore" marker span — no <ul>/<ol> at
	// all. Rebuild real (nested) lists in place before the generic walk.
	function rebuildWordLists( root ) {
		const isListPara = ( n ) => n && n.nodeType === Node.ELEMENT_NODE && n.tagName === 'P'
			&& /mso-list:/i.test( n.getAttribute( 'style' ) || '' );
		$$( 'p', root ).forEach( ( first ) => {
			if ( ! isListPara( first ) || first.dataset.msoDone ) return;
			const items = [];
			for ( let n = first; isListPara( n ); n = n.nextElementSibling ) {
				n.dataset.msoDone = '1';
				const style = n.getAttribute( 'style' ) || '';
				const lvl = parseInt( ( style.match( /level(\d+)/i ) || [] )[ 1 ], 10 ) || 1;
				// lN identifies the logical list — adjacent paragraphs from
				// DIFFERENT lists (a bullet run followed by a numbered run)
				// must not collapse into one.
				const listId = ( style.match( /mso-list:\s*l(\d+)/i ) || [] )[ 1 ] || '';
				// The marker span holds the literal bullet/number; a digit or
				// letter followed by . or ) means ordered.
				let ordered = false;
				const marker = Array.from( n.querySelectorAll( 'span' ) ).find( ( s ) =>
					/mso-list:\s*ignore/i.test( s.getAttribute( 'style' ) || '' ) );
				if ( marker ) {
					ordered = /^\s*\w{1,4}[.)]/.test( marker.textContent.replace( / /g, ' ' ) );
					marker.remove();
				}
				items.push( { lvl, listId, ordered, node: n } );
			}
			const doc = root.ownerDocument;
			const listFor = ( it ) => doc.createElement( it.ordered ? 'ol' : 'ul' );
			let stack = null; // [{lvl, list}] — reset per logical list
			items.forEach( ( it, i ) => {
				if ( ! stack || it.listId !== items[ i - 1 ].listId ) {
					const rootList = listFor( it );
					it.node.before( rootList );
					stack = [ { lvl: it.lvl, list: rootList } ];
				}
				while ( stack.length > 1 && it.lvl < stack[ stack.length - 1 ].lvl ) stack.pop();
				if ( it.lvl > stack[ stack.length - 1 ].lvl ) {
					const parentLi = stack[ stack.length - 1 ].list.lastElementChild;
					const sub = listFor( it );
					( parentLi || stack[ stack.length - 1 ].list ).appendChild( sub );
					stack.push( { lvl: it.lvl, list: sub } );
				}
				const li = doc.createElement( 'li' );
				while ( it.node.firstChild ) li.appendChild( it.node.firstChild );
				stack[ stack.length - 1 ].list.appendChild( li );
				it.node.remove();
			} );
		} );
	}

	// Walk a container's children into vocabulary blocks. Loose inline runs
	// between block elements collect into paragraphs; images lift to their own
	// figure; wrappers (div, section, Docs' <b> shell) recurse transparently.
	function collectPasteBlocks( container, out, ctx ) {
		let run = '';
		const figures = [];
		const innerCtx = { ...ctx, onImage: ( img ) => {
			const f = pasteImage( img );
			if ( f ) figures.push( f );
		} };
		const flush = () => {
			if ( pasteHasInk( run ) ) out.push( `<p>${ run.trim() }</p>` );
			run = '';
			figures.splice( 0 ).forEach( ( f ) => out.push( f ) );
		};
		Array.from( container.childNodes ).forEach( ( n ) => {
			if ( n.nodeType === Node.TEXT_NODE ) {
				run += esc( n.textContent.replace( / /g, ' ' ) );
				return;
			}
			if ( n.nodeType !== Node.ELEMENT_NODE || PASTE_DROP.has( n.tagName ) ) return;
			const tag = n.tagName;
			const emit = ( html ) => {
				flush();
				if ( html ) out.push( html );
			};
			if ( tag === 'P' ) {
				flush();
				const inner = pasteInline( n, innerCtx );
				if ( pasteHasInk( inner ) ) out.push( `<p${ pasteAlignClass( n ) }>${ inner.trim() }</p>` );
				figures.splice( 0 ).forEach( ( f ) => out.push( f ) );
			} else if ( /^H[1-6]$/.test( tag ) ) {
				flush();
				const inner = pasteInline( n, { ...innerCtx, heading: true } );
				if ( pasteHasInk( inner ) ) out.push( `<${ tag.toLowerCase() }${ pasteAlignClass( n ) }>${ inner.trim() }</${ tag.toLowerCase() }>` );
			} else if ( tag === 'UL' || tag === 'OL' ) {
				emit( pasteList( n, innerCtx ) );
			} else if ( tag === 'PRE' ) {
				emit( pasteCodeBlock( n ) );
			} else if ( tag === 'BLOCKQUOTE' ) {
				emit( pasteQuote( n, innerCtx ) );
			} else if ( tag === 'TABLE' ) {
				emit( pasteTable( n, innerCtx ) );
			} else if ( tag === 'HR' ) {
				emit( '<hr>' );
			} else if ( tag === 'IMG' ) {
				emit( pasteImage( n ) );
			} else if ( n.classList && n.classList.contains( 'minn-block-island' ) ) {
				// Copy-paste within this post: rebuild the island from its
				// registered raw markup (fresh chip, same index → same bytes on
				// save). Foreign islands fall back to their visible preview.
				const idx = parseInt( n.dataset.island, 10 );
				const ed = state.editor;
				if ( ed && ed.islands && ed.islands[ idx ] != null ) {
					emit( islandHtml( idx, n.dataset.block || 'block', ed.islands[ idx ] ) );
				} else {
					flush();
					const prev = n.querySelector( '.minn-island-preview' );
					if ( prev ) collectPasteBlocks( prev, out, ctx );
				}
			} else if ( tag === 'FIGURE' ) {
				const media = n.querySelector( 'table' ) ? pasteTable( n.querySelector( 'table' ), innerCtx )
					: n.querySelector( 'img' ) ? pasteImage( n.querySelector( 'img' ), innerCtx, n ) : '';
				if ( media ) emit( media );
				else {
					flush();
					collectPasteBlocks( n, out, ctx );
				}
			} else if ( n.querySelector && n.querySelector( PASTE_BLOCK_SEL ) ) {
				// Wrapper holding block content (div soup, Docs' <b> shell) —
				// recurse; a pure-inline wrapper joins the current run instead.
				flush();
				collectPasteBlocks( n, out, ctx );
			} else {
				run += pasteInline( n.parentNode === container ? wrapSingle( n ) : n, innerCtx );
			}
		} );
		flush();
	}

	// pasteInline works on a node's CHILDREN — to clean one element with its
	// own marks, hand it over inside a disposable wrapper.
	function wrapSingle( n ) {
		const w = n.ownerDocument.createElement( 'span' );
		n.before( w );
		w.appendChild( n );
		return w;
	}

	// Clipboard HTML → { kind: 'inline'|'blocks', html, list, allParagraphs }.
	// Returns null when nothing usable survives (caller falls back to text).
	function sanitizePastedHtml( html ) {
		let doc;
		try {
			doc = new DOMParser().parseFromString( html, 'text/html' );
		} catch ( e ) {
			return null;
		}
		if ( ! doc || ! doc.body ) return null;
		rebuildWordLists( doc.body );
		const out = [];
		collectPasteBlocks( doc.body, out, { active: new Set() } );
		if ( ! out.length ) return null;
		// A single plain paragraph pastes inline — it continues the sentence at
		// the caret instead of splitting the block (matches Gutenberg).
		if ( out.length === 1 && /^<p>/.test( out[ 0 ] ) ) {
			return { kind: 'inline', html: out[ 0 ].replace( /^<p>/, '' ).replace( /<\/p>$/, '' ) };
		}
		return {
			kind: 'blocks',
			html: out.join( '' ),
			list: out.length === 1 && /^<[ou]l[\s>]/.test( out[ 0 ] ) ? out[ 0 ] : null,
			allParagraphs: out.every( ( b ) => /^<p[\s>]/.test( b ) ),
		};
	}

	// Multi-line plain text → paragraphs (blank line = new paragraph, single
	// newline = <br>), single line → inline.
	function pasteTextPayload( text ) {
		const chunks = text.replace( /\r\n?/g, '\n' ).split( /\n{2,}/ ).map( ( c ) => c.trim() ).filter( Boolean );
		if ( ! chunks.length ) return null;
		if ( chunks.length === 1 && ! /\n/.test( chunks[ 0 ] ) ) return { kind: 'inline', html: esc( chunks[ 0 ] ) };
		return { kind: 'blocks', html: chunks.map( ( c ) => `<p>${ esc( c ).replace( /\n/g, '<br>' ) }</p>` ).join( '' ), allParagraphs: true };
	}

	const pasteFlatHtml = ( payload ) => esc( stripTags( payload.html.replace( /<\/(p|h[1-6]|li|tr|pre)>/g, ' ' ) ).replace( /\s+/g, ' ' ).trim() );

	// Block-level insertion. Chrome merges a payload's first and last blocks
	// into the blocks around the caret — fine when both are paragraphs, but a
	// merged-in <pre>/<h2>/<figure> gets shredded into styled spans (probed).
	// Empty marker paragraphs bracket the payload so nothing merges; the
	// brackets are removed after (outside the undo stack, like the markdown
	// nbsp fix — ⌘Z still reverts the whole paste in one step, and a redo's
	// resurrected empty bracket serializes to nothing).
	function pasteBlocksInsert( body, blocksHtml ) {
		const BKT = '<p data-minn-bkt="1"><br></p>';
		document.execCommand( 'insertHTML', false, BKT + blocksHtml + BKT );
		const sel = window.getSelection();
		$$( 'p[data-minn-bkt]', body ).forEach( ( p ) => {
			p.removeAttribute( 'data-minn-bkt' );
			if ( p.textContent.trim() ) return; // absorbed real text — it's a paragraph now
			const holdsCaret = sel.rangeCount && p.contains( sel.anchorNode );
			const next = p.nextElementSibling;
			if ( holdsCaret ) {
				if ( ! next || next.classList.contains( 'minn-block-island' ) ) return; // keep as the landing paragraph
				setCaret( next, 0 );
			}
			// Node removal is safe here; editing TEXT would be data loss —
			// Chrome's undo replays recorded offsets against the live DOM, and
			// an out-of-stack text mutation misapplies them (probed: a
			// character vanished mid-word). The split-off tail's leading nbsp
			// is handled at serialize instead (cleanLeadingNbsp).
			p.remove();
		} );
		liftNestedLists( body );
	}

	// Route a sanitized payload to the right insertion for the caret context.
	// Constrained containers can't take block splices (Chrome shreds blocks
	// pasted into headings/list items — probed): lists merge into lists
	// natively, paragraphs merge into quotes, anything else flattens to text.
	function pasteInsert( body, payload, anchorEl ) {
		if ( payload.kind === 'inline' ) {
			document.execCommand( 'insertHTML', false, payload.html );
			return;
		}
		if ( anchorEl.closest( 'li' ) ) {
			if ( payload.list ) {
				document.execCommand( 'insertHTML', false, payload.list );
				liftNestedLists( body );
			} else {
				document.execCommand( 'insertHTML', false, pasteFlatHtml( payload ) );
			}
			return;
		}
		if ( anchorEl.closest( 'h1,h2,h3,h4,h5,h6,td,th,figcaption' ) ) {
			document.execCommand( 'insertHTML', false, pasteFlatHtml( payload ) );
			return;
		}
		if ( anchorEl.closest( 'blockquote' ) ) {
			document.execCommand( 'insertHTML', false, payload.allParagraphs ? payload.html : pasteFlatHtml( payload ) );
			return;
		}
		pasteBlocksInsert( body, payload.html );
	}

	/* ===== Inline media flow (paste/drop image files → library at caret) ===== */

	// Upload one file to the media library; resolves {id, url, alt}.
	async function uploadImageFile( file ) {
		const fd = new FormData();
		fd.append( 'file', file, file.name || 'pasted-image.png' );
		const m = await api( 'wp/v2/media', { method: 'POST', body: fd } );
		return { id: m.id, url: m.source_url, alt: m.alt_text || '' };
	}

	// The standard editable image figure. Attachment attrs ride the parked
	// data-minn-attrs marker (PASSTHROUGH_BLOCKS) so the serializer emits a
	// true Gutenberg image block: {"id":N} + wp-image-N.
	const imageFigureHtml = ( it ) =>
		`<figure class="wp-block-image" data-minn-attrs="${ esc( JSON.stringify( { id: it.id } ) ) }"><img src="${ esc( it.url ) }" alt="${ esc( it.alt || '' ) }" class="wp-image-${ it.id }"><figcaption class="wp-element-caption"></figcaption></figure>`;

	// Insert pasted/dropped image files at the caret. The instant preview is a
	// local blob: URL inserted through the undo stack; the upload runs behind
	// it and the real URL + attachment id swap in when it lands. A figure
	// still marked data-minn-upload is skipped by both serializers, so a
	// mid-upload autosave (or crash-net snapshot) can never store a blob URL.
	let uploadSeq = 0;
	function insertImageFiles( body, files ) {
		// Figures can't live inside lists, headings, cells or code — hop the
		// caret into a fresh paragraph after the enclosing top-level block.
		// A bare element-level caret in the body doesn't work: Chrome
		// normalizes it back into the nearest text position (probed — the
		// figure landed inside the <li>). A real landing paragraph does; the
		// serializer drops it if it stays empty.
		const sel = window.getSelection();
		let node = sel.rangeCount ? sel.anchorNode : null;
		if ( ! node || ! body.contains( node ) ) return;
		const el = node.nodeType === Node.ELEMENT_NODE ? node : node.parentNode;
		if ( el.closest( 'li, td, th, h1, h2, h3, h4, h5, h6, pre, figcaption' ) ) {
			let top = node;
			while ( top.parentNode && top.parentNode !== body ) top = top.parentNode;
			const landing = document.createElement( 'p' );
			landing.appendChild( document.createElement( 'br' ) );
			top.after( landing );
			const r = document.createRange();
			r.selectNodeContents( landing );
			r.collapse( true );
			sel.removeAllRanges();
			sel.addRange( r );
		}
		files.forEach( ( file ) => {
			if ( ! /^image\//.test( file.type ) ) return;
			const key = 'u' + ( ++uploadSeq );
			const blobUrl = URL.createObjectURL( file );
			document.execCommand( 'insertHTML', false,
				`<figure class="wp-block-image" data-minn-upload="${ key }"><img src="${ blobUrl }" alt=""><figcaption class="wp-element-caption"></figcaption></figure><p><br></p>` );
			scheduleAutosave();
			uploadImageFile( file ).then( ( it ) => {
				URL.revokeObjectURL( blobUrl );
				const fig = document.querySelector( `figure[data-minn-upload="${ key }"]` );
				if ( ! fig ) return; // undone or navigated away — the upload stays in the library
				const img = fig.querySelector( 'img' );
				img.src = it.url;
				if ( it.alt ) img.alt = it.alt;
				img.className = 'wp-image-' + it.id;
				fig.dataset.minnAttrs = JSON.stringify( { id: it.id } );
				fig.removeAttribute( 'data-minn-upload' );
				state.cache.media = null;
				scheduleAutosave();
			} ).catch( ( err ) => {
				URL.revokeObjectURL( blobUrl );
				const fig = document.querySelector( `figure[data-minn-upload="${ key }"]` );
				if ( fig ) fig.remove();
				toast( `Upload failed: ${ err.message }`, true );
				scheduleAutosave();
			} );
		} );
	}

	// Seed a typable caption on every editable top-level image figure that
	// lacks one — the inline affordance ("Write a caption…" via CSS :empty).
	// Empty captions are scrubbed at serialize, so they never reach the
	// database; islands are divs, so their preview images stay untouched.
	function seedImageCaptions( body ) {
		$$( ':scope > figure', body ).forEach( ( fig ) => {
			if ( fig.querySelector( 'img' ) && ! fig.querySelector( ':scope > figcaption' ) && ! fig.querySelector( 'table' ) ) {
				const fc = document.createElement( 'figcaption' );
				fc.className = 'wp-element-caption';
				fig.appendChild( fc );
			}
		} );
	}

	/* ===== Slash command menu ===== */

	// Design libraries: adapters (bundled or third-party) register
	// { id, label, route } entries via the minn_admin_design_sources filter;
	// the boot payload / editor-blocks re-poll carry the active list
	// (B.designs). Each route serves a slim list (GET {route}) and an
	// insert-ready template with sideloaded images (POST {route}/{id}) —
	// full serialized save() markup, so every design inserts as one valid
	// island. Lists load lazily, deduped via shared in-flight promises
	// (the loadPlugins rule).
	const designSources = () => ( Array.isArray( B.designs ) ? B.designs : [] );
	const designSourcePromises = {};
	function loadDesigns( src ) {
		if ( ! designSourcePromises[ src.id ] ) {
			designSourcePromises[ src.id ] = api( src.route )
				.then( ( r ) => ( r && Array.isArray( r.designs ) ? r.designs : [] ) )
				.catch( () => [] );
		}
		return designSourcePromises[ src.id ];
	}

	// Server-registered block patterns (core, active theme, Otter, Essential
	// Blocks…): ready-made valid saved markup with zero adapter code — the
	// generic counterpart to plugin design libraries (docs/block-suites.md).
	let blockPatternsPromise = null;
	function loadBlockPatterns() {
		if ( ! blockPatternsPromise ) {
			blockPatternsPromise = api( 'minn-admin/v1/patterns' )
				.then( ( r ) => ( r && Array.isArray( r.patterns ) ? r.patterns : [] ) )
				.catch( () => [] );
		}
		return blockPatternsPromise;
	}

	// Insert a pattern's markup as one island per top-level block. Patterns
	// are ready-made SAVED markup, so islands are the safe landing: sections
	// stay verbatim (text runs and image swaps still apply), and a multi-root
	// pattern becomes a run of sibling islands. Stray top-level HTML between
	// blocks (rare, usually whitespace) is dropped.
	function insertPatternIslands( anchor, content ) {
		const body = $( '#minn-editor-body' );
		const ed = state.editor;
		if ( ! body || ! ed || ! anchor.isConnected ) return;
		const segs = tokenizeBlocks( content.trim() );
		if ( ! segs ) { toast( 'This pattern’s markup can’t be parsed safely', true ); return; }
		if ( ! ed.islands ) ed.islands = [];
		let count = 0;
		segs.forEach( ( seg ) => {
			if ( seg.type !== 'block' ) return;
			const idx = ed.islands.push( seg.raw ) - 1;
			anchor.insertAdjacentHTML( 'beforebegin', islandHtml( idx, seg.name.includes( '/' ) ? seg.name : 'core/' + seg.name, seg.raw ) );
			count++;
		} );
		if ( ! count ) { toast( 'Nothing insertable in this pattern', true ); return; }
		renderIslandPreviews( body, ed );
		updateEditorStats();
		scheduleAutosave();
	}

	// The curated quick-insert set — shared by the inline slash menu and the
	// full block picker. Embeds and galleries insert as islands, blocks mode
	// only (classic content already auto-embeds lone URLs server-side).
	function basicSlashItems( blocksMode ) {
		// Always list the full Basics set. Island inserts (embed/gallery/spacer/
		// file/shortcode) used to hide in classic mode, which made them look
		// "missing" in Browse all on classic-content posts. Insert promotes
		// classic → blocks via ensureBlocksMode() instead.
		void blocksMode;
		return [
			[ icon( 'h2' ), 'Heading 2', () => document.execCommand( 'formatBlock', false, 'h2' ) ],
			[ icon( 'h3' ), 'Heading 3', () => document.execCommand( 'formatBlock', false, 'h3' ) ],
			[ icon( 'quote' ), 'Quote', () => document.execCommand( 'formatBlock', false, 'blockquote' ) ],
			// Pullquote is prose-class; details is an island (contenteditable
			// <details> traps the caret and blocks typing after it in Blink).
			[ icon( 'quote' ), 'Pullquote', { html: '<figure class="wp-block-pullquote"><blockquote><p><br></p></blockquote></figure>' } ],
			[ icon( 'list' ), 'Details', { block: 'core/details', template: detailsTemplate( 'Details', '' ) } ],
			[ icon( 'braces' ), 'Code', () => document.execCommand( 'formatBlock', false, 'pre' ) ],
			[ icon( 'list' ), 'Bulleted list', () => document.execCommand( 'insertUnorderedList', false, null ) ],
			[ icon( 'olist' ), 'Numbered list', () => document.execCommand( 'insertOrderedList', false, null ) ],
			[ icon( 'img' ), 'Image', 'image' ],
			[ icon( 'table' ), 'Table', { html: '<figure class="wp-block-table"><table class="has-fixed-layout"><tbody><tr><td>&nbsp;</td><td>&nbsp;</td></tr><tr><td>&nbsp;</td><td>&nbsp;</td></tr></tbody></table></figure>' } ],
			[ icon( 'minus' ), 'Divider', { html: '<hr>' } ],
			[ icon( 'play' ), 'Embed — YouTube, tweet, audio…', 'embed' ],
			[ icon( 'gallery' ), 'Gallery', 'gallery' ],
			[ icon( 'minus' ), 'Spacer', 'spacer' ],
			[ icon( 'file' ), 'File', 'file' ],
			[ icon( 'braces' ), 'Shortcode', 'shortcode' ],
			// Buttons: live island with label/URL rows (not free HTML — nested
			// core/button markup must stay in the island raw store).
			[ icon( 'send' ), 'Buttons', { block: 'core/buttons', template: buttonsTemplate( 'Button', '' ) } ],
		];
	}

	function detailsTemplate( summary, body ) {
		const s = summary != null ? String( summary ) : 'Details';
		const b = body != null ? String( body ) : '';
		return buildDetailsRaw( s, b ? `<p>${ esc( b ) }</p>` : '<p></p>', null );
	}

	// Island inserts need blocks-mode serialization. Classic posts (no block
	// comments yet) promote cleanly: the live HTML body serializes via
	// serializeToBlocks. Locked layouts stay locked.
	function ensureBlocksMode() {
		const ed = state.editor;
		if ( ! ed ) return false;
		if ( ed.mode === 'blocks' ) return true;
		if ( ed.mode === 'locked' ) {
			toast( 'This layout is locked — open it in the block editor to add blocks', true );
			return false;
		}
		ed.mode = 'blocks';
		if ( ! ed.islands ) ed.islands = [];
		return true;
	}

	/* ===== Block picker — the full library, browsable ===== */
	// The inline slash menu stays curated (search-only entries hide until
	// typed for); this large modal shows EVERYTHING — basics, every plugin's
	// insertable blocks, design libraries and patterns — grouped by source
	// with a search box. Opened from the slash menu's "Browse all" row or ⌘/.
	let pickerInsertImage = null; // captured by bindSlashMenu (caret-preserving image flow)
	let pickerEl = null;

	function closeBlockPicker() {
		if ( pickerEl ) { pickerEl.remove(); pickerEl = null; }
	}

	const prettyNs = ( ns ) => ( {
		woocommerce: 'WooCommerce', uagb: 'Spectra', 'themeisle-blocks': 'Otter',
		'essential-blocks': 'Essential Blocks', generateblocks: 'GenerateBlocks',
		kadence: 'Kadence', stackable: 'Stackable', 'otter-blocks': 'Otter',
		core: 'WordPress', anchor: 'Anchor Blocks',
	}[ ns ] || ns.charAt( 0 ).toUpperCase() + ns.slice( 1 ) );

	async function openBlockPicker( targetBlock ) {
		const ed = state.editor;
		const body = $( '#minn-editor-body' );
		if ( ! ed || ! body || ed.mode === 'locked' ) return;
		closeBlockPicker();
		// Where the insert lands: the "/" block when opened from the slash
		// menu (replaceable by design), else after the caret's top-level
		// block — captured NOW, before the modal steals focus.
		let caretBlock = null;
		{
			const sel = window.getSelection();
			let n = sel.rangeCount ? sel.anchorNode : null;
			while ( n && n.parentNode && n.parentNode !== body ) n = n.parentNode;
			caretBlock = n && n.parentNode === body ? n : null;
		}

		pickerEl = document.createElement( 'div' );
		pickerEl.className = 'minn-block-picker';
		pickerEl.innerHTML = `
			<div class="minn-bp-backdrop"></div>
			<div class="minn-bp-panel" role="dialog" aria-label="Insert from the block library">
				<div class="minn-bp-head">
					<input class="minn-input" id="minn-bp-search" placeholder="Search blocks, designs and patterns…" autocomplete="off">
					<button class="minn-x-btn" id="minn-bp-close" type="button">×</button>
				</div>
				<div class="minn-bp-body"><div class="minn-loading" style="padding:24px;">Loading the library…</div></div>
			</div>`;
		document.body.appendChild( pickerEl );
		const searchInput = $( '#minn-bp-search', pickerEl );
		const bpBody = $( '.minn-bp-body', pickerEl );
		searchInput.focus();
		pickerEl.addEventListener( 'mousedown', ( e ) => {
			if ( e.target.classList.contains( 'minn-bp-backdrop' ) || e.target.closest( '#minn-bp-close' ) ) closeBlockPicker();
		} );
		pickerEl.addEventListener( 'keydown', ( e ) => {
			if ( e.key === 'Escape' ) { e.stopPropagation(); closeBlockPicker(); }
			else if ( e.key === 'Enter' && e.target === searchInput ) {
				const first = bpBody.querySelector( '[data-bp]' );
				if ( first ) first.click();
			}
		} );

		// Assemble the catalog: basics, per-namespace blocks (adapter
		// templates + auto-registered), design libraries, patterns.
		const blocksMode = ed.mode === 'blocks';
		const groups = [ {
			title: 'Basics',
			items: basicSlashItems( blocksMode ).map( ( [ ic, label, action ] ) => ( { ic, label, meta: '', action } ) ),
		} ];
		const byKey = {};
		const groupFor = ( key, title ) => {
			if ( ! byKey[ key ] ) { byKey[ key ] = { title, items: [] }; groups.push( byKey[ key ] ); }
			return byKey[ key ];
		};
		if ( blocksMode ) {
			Object.keys( B.blockForms || {} ).forEach( ( name ) => {
				const ins = ( B.blockForms[ name ] || {} ).insert;
				if ( ! ins || ! ins.template ) return;
				const ns = name.split( '/' )[ 0 ];
				groupFor( 'b:' + ns, prettyNs( ns ) + ' · blocks' ).items.push( {
					ic: icon( 'block' ), label: ins.label || name.split( '/' ).pop(), meta: '',
					action: { block: name, template: String( ins.template ) },
				} );
			} );
			( B.insertBlocks || [] ).forEach( ( b ) => {
				groupFor( 'b:' + b.ns, prettyNs( b.ns ) + ' · blocks' ).items.push( {
					ic: icon( 'block' ), label: b.title, meta: '',
					action: { block: b.name, template: `<!-- wp:${ b.name } /-->` },
				} );
			} );
			const sources = designSources();
			const [ designSets, pats ] = await Promise.all( [
				Promise.all( sources.map( ( src ) => loadDesigns( src ) ) ),
				loadBlockPatterns(),
			] );
			if ( ! pickerEl ) return; // closed while loading
			designSets.forEach( ( designs, si ) => {
				const src = sources[ si ];
				designs.forEach( ( d ) => {
					groupFor( 'd:' + src.id, ( src.label || prettyNs( src.id ) ) + ' · designs' ).items.push( {
						ic: icon( 'block' ), label: d.label, meta: d.category || '',
						action: { design: d.id, src: src.id },
					} );
				} );
			} );
			pats.forEach( ( pt ) => {
				if ( pt.postTypes && ed.type && ! pt.postTypes.includes( ed.type ) ) return;
				groupFor( 'p:' + pt.ns, prettyNs( pt.ns ) + ' · patterns' ).items.push( {
					ic: icon( 'block' ), label: pt.title, meta: '',
					action: { pattern: pt.name },
				} );
			} );
		}

		const renderGroups = ( q ) => {
			q = ( q || '' ).trim().toLowerCase();
			bpBody.innerHTML = groups.map( ( g, gi ) => {
				const vis = g.items
					.map( ( it, j ) => ( { it, j } ) )
					.filter( ( { it } ) => ! q || it.label.toLowerCase().includes( q )
						|| ( it.meta || '' ).toLowerCase().includes( q )
						|| g.title.toLowerCase().includes( q ) );
				if ( ! vis.length ) return '';
				return `<section class="minn-bp-group"><h3>${ esc( g.title ) } <span>${ vis.length }</span></h3>
					<div class="minn-bp-grid">${ vis.map( ( { it, j } ) => `
						<button type="button" class="minn-bp-item" data-bp="${ gi }:${ j }">
							<span class="minn-bp-ic">${ it.ic }</span>
							<span class="minn-bp-label">${ esc( it.label ) }</span>
							${ it.meta ? `<span class="minn-bp-meta">${ esc( it.meta ) }</span>` : '' }
						</button>` ).join( '' ) }</div></section>`;
			} ).join( '' ) || '<div class="minn-insp-note" style="padding:24px;">Nothing matches.</div>';
		};
		renderGroups( '' );
		searchInput.addEventListener( 'input', () => renderGroups( searchInput.value ) );

		bpBody.addEventListener( 'click', ( e ) => {
			const btn = e.target.closest( '[data-bp]' );
			if ( ! btn ) return;
			const [ gi, j ] = btn.dataset.bp.split( ':' ).map( Number );
			const found = groups[ gi ] && groups[ gi ].items[ j ];
			if ( ! found ) return;
			closeBlockPicker();
			const b = $( '#minn-editor-body' );
			if ( ! b || ! state.editor ) return;
			b.focus( { preventScroll: true } );
			let target;
			if ( targetBlock && targetBlock.isConnected && targetBlock.parentNode === b ) {
				// The slash menu's "/" block — replaceable by design.
				target = targetBlock;
			} else {
				// Fresh landing paragraph after the caret block (never
				// replace real content the caret happened to sit in).
				target = document.createElement( 'p' );
				target.appendChild( document.createElement( 'br' ) );
				if ( caretBlock && caretBlock.isConnected && caretBlock.parentNode === b ) caretBlock.after( target );
				else b.appendChild( target );
			}
			const range = document.createRange();
			range.selectNodeContents( target );
			range.collapse( true );
			const sel = window.getSelection();
			sel.removeAllRanges();
			sel.addRange( range );
			runSlashAction( found.action, target, b, pickerInsertImage || ( () => {} ) );
		} );
	}

	// Dispatch one insert action — shared by the inline slash menu and the
	// full block picker. `target` is the block to insert before/replace
	// (the emptied "/" block, or a fresh paragraph the picker created).
	function runSlashAction( action, target, body, insertImage ) {
		if ( action && action.design ) {
			// Stackable design: the template arrives async (the server may
			// be sideloading its CDN images), so swap the "/" block for a
			// clean paragraph now and island the markup when it lands.
			if ( ! ensureBlocksMode() ) return;
			const p = document.createElement( 'p' );
			p.appendChild( document.createElement( 'br' ) );
			target.replaceWith( p );
			const range = document.createRange();
			range.selectNodeContents( p );
			range.collapse( true );
			const sel = window.getSelection();
			sel.removeAllRanges();
			sel.addRange( range );
			toast( 'Inserting design…' );
			// Sources are looked up by id (not index) — the list can rebuild
			// between menu render and click (editor-blocks re-poll).
			const source = designSources().find( ( s ) => s.id === action.src );
			if ( ! source ) { toast( 'Design source unavailable', true ); return; }
			api( source.route + '/' + encodeURIComponent( action.design ), { method: 'POST' } )
				.then( ( r ) => {
					if ( ! r || ! r.template ) throw new Error( 'Design unavailable' );
					if ( ! p.isConnected || ! state.editor ) return;
					const islandEl = insertIsland( p, r.block || 'core/group', r.template );
					// The inspector's text-run fields are how the design's
					// placeholder copy gets replaced — open it right away.
					if ( islandEl ) openInspector( islandEl );
				} )
				.catch( ( e ) => toast( 'Design insert failed: ' + e.message, true ) );
			return;
		}
		if ( action && action.pattern ) {
			// Registered block pattern: same async placeholder dance.
			if ( ! ensureBlocksMode() ) return;
			const p = document.createElement( 'p' );
			p.appendChild( document.createElement( 'br' ) );
			target.replaceWith( p );
			const range = document.createRange();
			range.selectNodeContents( p );
			range.collapse( true );
			const sel = window.getSelection();
			sel.removeAllRanges();
			sel.addRange( range );
			api( 'minn-admin/v1/pattern?name=' + encodeURIComponent( action.pattern ) )
				.then( ( r ) => {
					if ( ! r || ! r.content ) throw new Error( 'Pattern unavailable' );
					insertPatternIslands( p, r.content );
				} )
				.catch( ( e ) => toast( 'Pattern insert failed: ' + e.message, true ) );
			return;
		}
		if ( action && action.block ) {
			// Insert a custom block as a new island: register the raw markup,
			// drop the card in place of the "/" block, render the real
			// preview, and open the inspector to configure it.
			if ( ! ensureBlocksMode() ) return;
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
			api( 'minn-admin/v1/render-blocks', { method: 'POST', body: JSON.stringify( { blocks: [ action.template ], post: ( state.editor && state.editor.id ) || 0 } ) } )
				.then( ( r ) => {
					injectPreviewStyles( r && r.styles );
					const html = r && r.rendered && r.rendered[ 0 ];
					const prev = islandEl && islandEl.querySelector( '.minn-island-preview' );
					if ( prev && html && html.trim() ) prev.innerHTML = html;
					updateEditorStats();
				} )
				.catch( () => {} );
			// Live-field islands focus an in-card field instead of opening the
			// inspector. Other custom blocks still open the inspector.
			if ( islandEl ) {
				const bn = action.block || '';
				if ( /(?:^|\/)details$/.test( bn ) ) focusDetailsIsland( islandEl );
				else if ( /(?:^|\/)buttons$/.test( bn ) ) {
					// Stamp empty attrs for the fresh insert row.
					stampButtonsRowAttrs( islandEl, parseButtonsRaw( action.template ) );
					islandEl.dataset.btnStamped = '1';
					focusButtonsIsland( islandEl );
				}
				else openInspector( islandEl );
			}
			scheduleAutosave();
			return;
		}
		if ( action && action.html ) {
			// Pullquote/table HTML needs blocks-mode serialization so the next
			// save re-emits <!-- wp:… --> comments (not freeform HTML). Details
			// always inserts as an island via action.block (never free HTML).
			if ( /wp-block-(pullquote|table)/.test( action.html ) ) ensureBlocksMode();
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
		else if ( action === 'embed' ) {
			if ( ! ensureBlocksMode() ) return;
			const url = ( prompt( 'Paste the URL to embed (YouTube, tweet, audio…):' ) || '' ).trim();
			if ( /^https?:\/\/\S+$/.test( url ) ) insertIsland( target.isConnected ? target : null, 'core/embed', embedTemplate( url ) );
			else if ( url ) toast( 'That doesn’t look like a URL', true );
		} else if ( action === 'gallery' ) {
			if ( ! ensureBlocksMode() ) return;
			const anchor = target;
			openMediaPicker( ( picks ) => {
				if ( picks && picks.length ) insertIsland( anchor, 'core/gallery', galleryTemplate( picks ) );
			}, { multi: true } );
		} else if ( action === 'spacer' ) {
			if ( ! ensureBlocksMode() ) return;
			insertIsland( target.isConnected ? target : null, 'core/spacer', spacerTemplate( '40px' ) );
		} else if ( action === 'file' ) {
			if ( ! ensureBlocksMode() ) return;
			const anchor = target;
			openMediaPicker( ( picks ) => {
				const item = Array.isArray( picks ) ? picks[ 0 ] : picks;
				if ( item ) insertIsland( anchor, 'core/file', fileTemplate( item ) );
			}, { multi: false, any: true } );
		} else if ( action === 'shortcode' ) {
			// Insert a blank shortcode island and focus its field — no prompt.
			// The writer types [shortcode …] directly in the island UI.
			if ( ! ensureBlocksMode() ) return;
			const el = insertIsland( target.isConnected ? target : null, 'core/shortcode', shortcodeTemplate( '' ) );
			focusShortcodeIsland( el );
		} else {
			action();
			liftNestedLists( body );
		}
		scheduleAutosave();
	}

	function bindSlashMenu( body, insertImage ) {
		pickerInsertImage = insertImage;
		let menu = null;
		let block = null;
		let selIdx = 0;
		let filtered = [];
		let query = '';
		const items = basicSlashItems( state.editor && state.editor.mode === 'blocks' );
		// Custom blocks that declared an `insert` template via
		// minn_admin_block_forms land as configurable islands — blocks mode
		// only (classic serialization would flatten them to plain HTML).
		if ( state.editor && state.editor.mode === 'blocks' ) {
			Object.keys( B.blockForms || {} ).forEach( ( name ) => {
				const ins = ( B.blockForms[ name ] || {} ).insert;
				if ( ! ins || ! ins.template ) return;
				items.push( [ ins.icon || '❖', ins.label || name.split( '/' ).pop(), { block: name, template: String( ins.template ) } ] );
			} );
			// Every other dynamic third-party block is insertable with no
			// adapter — a self-closing comment is always valid saved markup
			// for a server-rendered block (B.insertBlocks; static-save blocks
			// never make that list). SEARCH-ONLY (item[3]): they surface when
			// typing matches title or namespace ("/stackable" lists a whole
			// plugin), so the default menu stays curated.
			( B.insertBlocks || [] ).forEach( ( b ) => {
				items.push( [ icon( 'block' ), b.title, { block: b.name, template: `<!-- wp:${ b.name } /-->` }, true, b.ns ] );
			} );
			// Design-library sections (Stackable, Kadence…) — search-only like
			// the auto blocks ("/pricing", "/hero"…). Lists arrive async into
			// the live items array; applyQuery reads it fresh on every keyup.
			designSources().forEach( ( src ) => {
				loadDesigns( src ).then( ( designs ) => {
					designs.forEach( ( d ) => {
						items.push( [ icon( 'block' ), d.label, { design: d.id, src: src.id }, true, src.id ] );
					} );
				} );
			} );
			// Server-registered block patterns — search-only, filtered by the
			// post type being edited when the pattern declares postTypes.
			loadBlockPatterns().then( ( pats ) => {
				const type = state.editor && state.editor.type;
				pats.forEach( ( pt ) => {
					if ( pt.postTypes && type && ! pt.postTypes.includes( type ) ) return;
					items.push( [ icon( 'block' ), pt.title, { pattern: pt.name }, true, pt.ns ] );
				} );
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
			body.focus( { preventScroll: true } );
			runSlashAction( item[ 2 ], target, body, insertImage );
		};

		const renderItems = () => {
			// Search-only entries (auto-registered dynamic blocks) are invisible
			// until typed for — the Browse row is both the hint and the door
			// to the full picker.
			const hidden = query ? 0 : items.filter( ( it ) => it[ 3 ] ).length;
			menu.innerHTML = filtered.map( ( idx, i ) => `
				<div class="minn-slash-item${ i === selIdx ? ' selected' : '' }" data-slash="${ idx }">
					<span class="minn-slash-icon">${ items[ idx ][ 0 ] }</span>${ esc( items[ idx ][ 1 ] ) }${ items[ idx ][ 4 ] ? `<span class="minn-slash-ns">${ esc( items[ idx ][ 4 ] ) }</span>` : '' }
				</div>` ).join( '' )
				+ ( ! query ? `<div class="minn-slash-hint" data-browse>Browse all${ hidden ? ` — ${ hidden } more blocks…` : '…' }</div>` : '' );
			$$( '.minn-slash-item', menu ).forEach( ( el ) =>
				el.addEventListener( 'mousedown', ( e ) => { e.preventDefault(); run( parseInt( el.dataset.slash, 10 ) ); } )
			);
			const browse = menu.querySelector( '[data-browse]' );
			if ( browse ) browse.addEventListener( 'mousedown', ( e ) => {
				e.preventDefault();
				const t = block;
				close();
				openBlockPicker( t );
			} );
		};

		// Keep typing after the "/" to narrow the list — "/co" finds Code.
		// No matches left closes the menu (the "/" was probably literal text).
		// Search-only entries (auto-registered dynamic blocks, item[3]) need a
		// query; they match on title or namespace (item[4]).
		const applyQuery = ( q ) => {
			q = q.toLowerCase();
			query = q;
			filtered = items
				.map( ( it, i ) => i )
				.filter( ( i ) => {
					const it = items[ i ];
					if ( it[ 3 ] && ! q ) return false;
					return it[ 1 ].toLowerCase().includes( q ) || ( it[ 4 ] && it[ 4 ].toLowerCase().includes( q ) );
				} );
			filtered.sort( ( a, b ) =>
				Number( items[ b ][ 1 ].toLowerCase().startsWith( q ) ) - Number( items[ a ][ 1 ].toLowerCase().startsWith( q ) ) );
			selIdx = 0;
			if ( ! filtered.length ) return close();
			renderItems();
		};

		const position = ( rect ) => {
			const top = Math.min( rect.bottom + 6, window.innerHeight - menu.offsetHeight - 12 );
			menu.style.top = top + 'px';
			menu.style.left = Math.min( rect.left, window.innerWidth - menu.offsetWidth - 12 ) + 'px';
		};

		const open = ( blockEl ) => {
			close();
			block = blockEl;
			selIdx = 0;
			menu = document.createElement( 'div' );
			menu.className = 'minn-slash-menu';
			document.body.appendChild( menu );
		};

		body.addEventListener( 'keyup', ( e ) => {
			if ( [ 'ArrowDown', 'ArrowUp', 'Enter', 'Escape' ].includes( e.key ) ) return;
			const sel = window.getSelection();
			if ( ! sel.rangeCount ) return close();
			let node = sel.anchorNode;
			if ( ! node || ! body.contains( node ) ) return close();
			while ( node.parentNode && node.parentNode !== body ) node = node.parentNode;
			let blockEl = node.nodeType === Node.ELEMENT_NODE ? node : null;
			// Empty editor / after select-all+delete: Chrome parks a bare text
			// node as a direct child of the contenteditable. Promote it to a
			// <p> so slash detection, insert-as-replace, and serialize agree.
			if ( ! blockEl && node.nodeType === Node.TEXT_NODE && node.parentNode === body ) {
				const p = document.createElement( 'p' );
				body.insertBefore( p, node );
				p.appendChild( node );
				blockEl = p;
			}
			const text = ( ( blockEl ? blockEl.textContent : '' ) || '' ).trim();
			// A second "/" (e.g. typing a path like /wp-admin/) ends the menu.
			const m = blockEl && /^\/([^\/]{0,24})$/.exec( text );
			if ( m ) {
				const rect = sel.getRangeAt( 0 ).getBoundingClientRect();
				if ( ! menu || block !== blockEl ) open( blockEl );
				applyQuery( m[ 1 ] );
				if ( menu ) position( rect );
			} else {
				close();
			}
		} );

		body.addEventListener( 'keydown', ( e ) => {
			if ( ! menu ) return;
			if ( e.key === 'ArrowDown' ) { e.preventDefault(); selIdx = ( selIdx + 1 ) % filtered.length; highlight(); }
			else if ( e.key === 'ArrowUp' ) { e.preventDefault(); selIdx = ( selIdx - 1 + filtered.length ) % filtered.length; highlight(); }
			else if ( e.key === 'Enter' ) { e.preventDefault(); run( filtered[ selIdx ] ); }
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

	// Run a notice's own action link (allow / dismiss / opt-in) in the
	// background. The link points at the admin page the notice rendered on;
	// re-adding the capture params makes ONE request run the plugin's
	// handler (admin_init) AND return a fresh digest (in_admin_header), so
	// the panel reflects the result immediately.
	async function runNoticeAction( link, btn ) {
		if ( btn ) { btn.disabled = true; btn.textContent = 'Working…'; }
		try {
			const u = new URL( link.url );
			u.searchParams.set( 'minn_notices', '1' );
			// Our nonce rides its own param — the link may carry the
			// plugin's own _wpnonce, which its handler verifies.
			u.searchParams.set( 'minn_nonce', B.notices.nonce );
			const r = await fetch( u.toString(), { credentials: 'same-origin' } );
			let captured = false;
			try {
				captured = ( await r.json() ).ok === true;
			} catch ( e ) { /* the handler redirected before our capture ran */ }
			if ( ! captured ) {
				await fetch( B.notices.url, { credentials: 'same-origin' } ).catch( () => {} );
			}
			state.cache.notifications = null;
			await loadNotifications();
			if ( state.notifOpen ) renderOverlays();
			toast( `Done: ${ link.text }` );
		} catch ( e ) {
			toast( e.message, true );
			if ( btn ) { btn.disabled = false; btn.textContent = link.text; }
		}
	}

	// What "Update everything" would touch, gated on capabilities. Order
	// matters downstream: plugins, themes, core LAST (core swaps the files
	// under the running app).
	function pendingUpdateParts() {
		const parts = [];
		const p = Object.keys( state.cache.pluginUpdates || {} ).length;
		if ( p && B.caps.update ) parts.push( { kind: 'plugins', n: p, label: `${ p } plugin${ p === 1 ? '' : 's' }` } );
		const t = Object.keys( state.cache.themeUpdates || {} ).length;
		if ( t && B.caps.updateThemes ) parts.push( { kind: 'themes', n: t, label: `${ t } theme${ t === 1 ? '' : 's' }` } );
		const c = state.cache.core && state.cache.core.update;
		if ( c && B.caps.core ) parts.push( { kind: 'core', label: `WordPress ${ c.version }` } );
		return parts;
	}

	async function runUpdateEverything() {
		if ( state.updatingAll ) return;
		const parts = pendingUpdateParts();
		if ( ! parts.length ) return;
		const hasCore = parts.some( ( p ) => p.kind === 'core' );
		const summary = parts.map( ( p ) => p.label ).join( ', ' );
		if ( ! confirm( `Update ${ summary }?${ hasCore ? ' The site enters maintenance mode for a few seconds while WordPress core updates.' : '' }` ) ) return;
		const setPhase = ( label ) => { state.updatingAll = label; renderOverlays(); };
		const doneBits = [];
		const failures = [];
		if ( parts.some( ( p ) => p.kind === 'plugins' ) ) {
			setPhase( 'Updating plugins…' );
			try {
				const r = await api( 'minn-admin/v1/plugins/update-all', { method: 'POST', body: '{}' } );
				const n = ( r.updated || [] ).length;
				doneBits.push( `${ n } plugin${ n === 1 ? '' : 's' }` );
			} catch ( e ) {
				failures.push( 'plugins: ' + e.message );
			}
		}
		const themeMap = state.cache.themeUpdates || {};
		if ( parts.some( ( p ) => p.kind === 'themes' ) ) {
			let ok = 0;
			for ( const stylesheet of Object.keys( themeMap ) ) {
				const t = ( state.cache.themes || [] ).find( ( x ) => x.stylesheet === stylesheet );
				setPhase( `Updating ${ t ? t.name : stylesheet }…` );
				try {
					await api( 'minn-admin/v1/themes/update', { method: 'POST', body: JSON.stringify( { stylesheet } ) } );
					ok++;
				} catch ( e ) {
					failures.push( `${ t ? t.name : stylesheet }: ${ e.message }` );
				}
			}
			if ( ok ) doneBits.push( `${ ok } theme${ ok === 1 ? '' : 's' }` );
		}
		if ( hasCore ) {
			setPhase( 'Updating WordPress…' );
			try {
				const version = await runCoreUpdate( state.cache.core.update.version );
				doneBits.push( `WordPress ${ version }` );
			} catch ( e ) {
				failures.push( 'WordPress: ' + e.message );
			}
		}
		state.updatingAll = null;
		state.cache.plugins = null;
		state.cache.pluginUpdates = {};
		state.cache.themeUpdates = {};
		state.cache.themes = null;
		state.cache.core = null;
		state.cache.notifications = null;
		await Promise.all( [
			loadPlugins().catch( () => {} ),
			B.caps.core ? loadCoreStatus().catch( () => {} ) : Promise.resolve(),
			loadNotifications(),
		] );
		renderOverlays();
		if ( state.route === 'extensions' ) renderExtensions();
		if ( failures.length ) toast( `Some updates failed — ${ failures.join( ' · ' ) }`, true );
		else toast( `Updated ${ doneBits.join( ', ' ) }. Everything is current.` );
	}

	function renderNotifPanel() {
		const items = state.cache.notifications;
		const tabs = [
			[ 'all', 'All' ], [ 'comments', 'Comments' ], [ 'updates', 'Updates' ], [ 'notices', 'Notices' ], [ 'system', 'System' ],
		];
		const updParts = state.notifTab === 'updates' ? pendingUpdateParts() : [];
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
				${ updParts.length || state.updatingAll ? `
				<div class="minn-update-all-row">
					<button class="minn-btn-primary" id="minn-update-all"${ state.updatingAll ? ' disabled' : '' }>${ icon( 'refresh' ) } ${ esc( state.updatingAll || 'Update everything' ) }</button>
					${ ! state.updatingAll ? `<div class="minn-update-all-sub">${ esc( updParts.map( ( p ) => p.label ).join( ' · ' ) ) }</div>` : '' }
				</div>` : '' }
				<div class="minn-notif-scroll">
					${ items == null ? '<div class="minn-loading">Loading…</div>' : ! visible.length ? '<div class="minn-empty">You’re all caught up.</div>' : groups.map( ( g ) => `
						<div>
							<div class="minn-notif-group-label">${ esc( g.label ) }</div>
							${ g.items.map( ( n ) => `
								<div class="minn-notif-row${ n.unread ? ' unread' : '' }" data-nid="${ esc( n.id ) }">
									<div class="minn-notif-icon">${ esc( n.icon ) }</div>
									<div class="minn-notif-text">
										${ esc( n.title ) }
										${ ( n.links || [] ).length || n.kind === 'notices' ? `<div class="minn-notif-links">${ ( n.links || [] ).map( ( l, i ) =>
											`<button class="minn-notif-link" data-nid="${ esc( n.id ) }" data-li="${ i }">${ esc( l.text ) }${ l.action ? '' : ' ↗' }</button>` ).join( '' ) }${
											n.kind === 'notices' ? `<button class="minn-notif-hide" data-nid="${ esc( n.id ) }" title="Hide this notice from Minn">Hide</button>` : '' }</div>` : '' }
										<div class="minn-notif-time">${ esc( n.ago ) }</div>
									</div>
									${ n.unread ? '<div class="minn-notif-unread-dot"></div>' : '' }
								</div>` ).join( '' ) }
						</div>` ).join( '' ) }
				</div>
			</div>
		</div>`;
	}

	// Purge every active cache layer (page cache, CDN, object cache —
	// whatever the site's providers cover). One request PER provider: a
	// purge that resets OPcache recycles the PHP worker, which drops the
	// browser's kept-alive sockets — isolation keeps one layer's drop from
	// hiding the rest, and a retry on a fresh socket confirms the outcome.
	async function clearSiteCache() {
		const providers = B.cache || [];
		if ( ! providers.length ) return;
		toast( 'Clearing cache…' );
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
					// The request reached the server before the worker
					// recycled — the purge itself is what dropped the reply.
					purged.push( p.name );
				}
			}
		}
		if ( failed.length ) toast( `Cache cleared (${ purged.join( ', ' ) }); failed: ${ failed.join( ', ' ) }`, true );
		else toast( `Cache cleared (${ purged.join( ', ' ) })` );
	}

	// Start a backup through the provider's own background machinery; the
	// Backups surface and the System check reflect completion.
	async function runBackupNow() {
		try {
			await api( B.backup.route, { method: 'POST', body: '{}' } );
			toast( `Backup started. ${ B.backup.name } is working in the background.` );
		} catch ( e ) {
			toast( e.message, true );
		}
	}

	// Disembark is a connector: the backup runs from a terminal, so the
	// palette hands over the exact command. Fetched on demand — the command
	// carries the site token, which never rides the boot payload.
	async function copyDisembarkCommand() {
		try {
			const st = await api( 'minn-admin/v1/disembark/status' );
			await navigator.clipboard.writeText( st.command.text );
			toast( 'Disembark backup command copied' );
		} catch ( e ) {
			toast( 'Copy failed — the command is on the Backups page', true );
		}
	}

	/* ===== Command palette ===== */

	function paletteCommands() {
		const cmds = [];
		// View modes are palette commands, not toolbar buttons — the toolbar
		// is for formatting; focus/outline are how you LOOK at the document.
		if ( state.route === 'editor' && state.editor ) {
			if ( state.editor.mode !== 'locked' ) cmds.push( { label: 'Find & replace (⌘⇧F)', kind: 'view', icon: '⌕', run: () => openFindBar() } );
			cmds.push( { label: 'Toggle focus mode (⌘⇧D)', kind: 'view', icon: '◎', run: () => toggleFocusMode() } );
			cmds.push( { label: 'Toggle outline mode (⌘⇧O)', kind: 'view', icon: '☰', run: () => toggleOutlineMode() } );
		}
		cmds.push(
			{ label: 'Go to Overview', kind: 'nav', icon: '▦', run: () => go( 'overview' ) },
			{ label: 'Manage Content', kind: 'nav', icon: '¶', run: () => go( 'content' ) },
			{ label: 'Open Media Library', kind: 'nav', icon: '▣', run: () => go( 'media' ) }
		);
		if ( commentsAvailable() ) cmds.push( { label: 'Review Comments', kind: 'nav', icon: '💬', run: () => go( 'comments' ) } );
		if ( B.wc && B.caps.orders ) cmds.push( { label: 'View Orders', kind: 'nav', icon: '⬡', run: () => go( 'orders' ) } );
		if ( B.caps.users ) cmds.push( { label: 'Browse Users', kind: 'nav', icon: '◉', run: () => go( 'users' ) } );
		// One palette entry per surface family (preferred member); ungrouped
		// surfaces keep a single entry as before.
		surfaceNavItems().forEach( ( s ) => {
			const full = surfaceById( s.id ) || s;
			const n = surfacesInFamily( s.family || '' ).length;
			const badge = n > 1
				? ` (${ full.sub || full.id } · ${ n } providers)`
				: ( full.sub ? ' (' + full.sub + ')' : '' );
			cmds.push( {
				label: 'Open ' + full.label + badge,
				kind: 'nav',
				icon: '❖',
				run: () => go( preferredSurfaceId( s.family ) || s.id ),
			} );
		} );
		if ( B.caps.themeOptions && ! B.site.blockTheme ) {
			cmds.push( { label: 'Edit Menus', kind: 'nav', icon: '☰', run: () => go( 'menus' ) } );
			if ( B.site.hasSidebars ) cmds.push( { label: 'Manage Widgets', kind: 'nav', icon: '▥', run: () => go( 'widgets' ) } );
		}
		if ( B.caps.plugins ) cmds.push( { label: 'Manage Extensions', kind: 'nav', icon: '✦', run: () => go( 'extensions' ) } );
		if ( B.caps.settings ) cmds.push( { label: 'Manage Post Types', kind: 'nav', icon: '▦', run: () => go( 'posttypes' ) } );
		if ( B.caps.terms ) cmds.push( { label: 'Manage categories & tags', kind: 'nav', icon: '#', run: () => goTerms() } );
		if ( B.caps.settings ) cmds.push( { label: 'View System diagnostics', kind: 'nav', icon: '❤', run: () => go( 'system' ) } );
		if ( B.caps.settings ) cmds.push( { label: 'Open Settings', kind: 'nav', icon: '⚙', run: () => go( 'settings' ) } );
		cmds.push(
			{ label: 'Write new post', kind: 'action', icon: '✎', run: () => newContent( 'posts' ) },
			...( B.caps.editPages ? [ { label: 'Create new page', kind: 'action', icon: '▭', run: () => newContent( 'pages' ) } ] : [] ),
			{ label: 'Toggle dark / light theme', kind: 'action', icon: '◐', run: toggleTheme },
			{ label: 'View notifications', kind: 'action', icon: '◔', run: () => { state.notifOpen = true; renderOverlays(); loadNotifications().then( () => state.notifOpen && renderOverlays() ); } },
			...( ( B.cache || [] ).length ? [ {
				label: `Clear site cache (${ B.cache.map( ( c ) => c.name ).join( ', ' ) })`,
				kind: 'action',
				icon: '⟳',
				run: clearSiteCache,
			} ] : [] ),
			...( B.backup ? [ {
				label: `Back up site now (${ B.backup.name })`,
				kind: 'action',
				icon: '⛁',
				run: runBackupNow,
			} ] : [] ),
			...( B.disembark ? [ {
				label: 'Copy Disembark backup command',
				kind: 'action',
				icon: '⛁',
				run: copyDisembarkCommand,
			} ] : [] ),
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

		if ( m.type === 'media' && m.editing ) {
			const it = m.item;
			return `
			<div class="minn-modal-overlay" id="minn-modal-overlay">
				<div class="minn-modal media wide">
					<div class="minn-modal-head">
						<div class="minn-modal-title">Edit image · ${ esc( it.name ) }</div>
						<button class="minn-x-btn" id="minn-modal-close">×</button>
					</div>
					<div class="minn-imged-bar">
						<button class="minn-btn-soft" id="minn-imged-rl" title="Rotate left">⟲ Rotate</button>
						<button class="minn-btn-soft" id="minn-imged-rr" title="Rotate right">⟳ Rotate</button>
						<button class="minn-btn-soft" id="minn-imged-reset">Reset</button>
						<span class="minn-imged-hint">Drag on the image to crop</span>
					</div>
					<div class="minn-imged-stage" id="minn-imged-stage">
						<canvas id="minn-imged-canvas"></canvas>
						<div class="minn-imged-crop" id="minn-imged-crop" hidden>
							<span data-h="nw"></span><span data-h="ne"></span><span data-h="sw"></span><span data-h="se"></span>
						</div>
					</div>
					<div class="minn-modal-actions">
						<button class="minn-btn-primary" id="minn-imged-save">Save as copy</button>
						<button class="minn-btn-soft" id="minn-imged-cancel">Cancel</button>
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
							<div class="minn-field-label" style="margin-top:10px;">Caption</div>
							<textarea class="minn-input" id="minn-media-caption" rows="2" placeholder="Shown beneath the image…">${ esc( it.caption || '' ) }</textarea>
							<div class="minn-field-label" style="margin-top:10px;">Description</div>
							<textarea class="minn-input" id="minn-media-description" rows="3" placeholder="Longer detail, shown on the attachment page…">${ esc( it.description || '' ) }</textarea>
						</div>` : '' }
					</div>
					<div class="minn-modal-actions">
						${ canEdit ? `<button class="minn-btn-primary" id="minn-media-save">Save</button>` : '' }
						<button class="minn-btn-soft" id="minn-media-copy">${ icon( 'copy' ) } Copy URL</button>
						<button class="minn-btn-soft" id="minn-media-open">↗ Open</button>
						${ it.kind === 'IMG' ? `<button class="minn-btn-soft" id="minn-media-edit-image" type="button" title="Rotate and crop — saved as a new copy">✎ Edit image</button>` : '' }
						${ it.kind === 'IMG' && B.regenThumbs ? `<button class="minn-btn-soft" id="minn-media-regen" type="button" title="Rebuild every registered thumbnail size from the original (Regenerate Thumbnails)">↻ Thumbnails</button>` : '' }
						${ m.from === 'featured' && state.editor ? `
						<button class="minn-btn-soft" id="minn-media-feat-replace" type="button">Replace featured</button>
						<button class="minn-btn-soft danger" id="minn-media-feat-remove" type="button">Remove featured</button>` : '' }
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
					<div class="minn-media-edit minn-order-status">
						<div class="minn-field-label">Status</div>
						<div style="display:flex; gap:8px;">
							<select class="minn-input" id="minn-order-status">
								${ Object.keys( ORDER_STATUS_STYLE ).map( ( st ) => `<option value="${ st }"${ st === o.status ? ' selected' : '' }>${ esc( st.replace( '-', ' ' ) ) }</option>` ).join( '' ) }
							</select>
							<button class="minn-btn-primary" id="minn-order-save" style="flex-shrink:0;">Save</button>
						</div>
					</div>` : '' }
					<div class="minn-modal-actions">
						${ ( ( B.wcpdf && B.wcpdf.docs ) || [] ).map( ( d ) =>
							`<a class="minn-btn-soft" href="${ esc( `${ B.wcpdf.ajax }?action=generate_wpo_wcpdf&document_type=${ encodeURIComponent( d.type ) }&order_ids=${ o.id }&access_key=${ encodeURIComponent( B.wcpdf.nonce ) }` ) }" target="_blank" rel="noopener" title="Generated by PDF Invoices &amp; Packing Slips">⬇ ${ esc( d.title ) } (PDF)</a>` ).join( '' ) }
						<a class="minn-btn-soft" href="${ esc( B.site.adminUrl ) }edit.php?post_type=shop_order" target="_blank" rel="noopener">↗ Manage in WooCommerce</a>
					</div>
				</div>
			</div>`;
		}

		if ( m.type === 'surface' ) {
			const s = m.surface;
			const coll = m.coll || s.collection;
			const detail = coll.detail || {};
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
			// Actions can be conditional (when.key equals when.equals on the
			// item) or plain links (href with {id}); indexes stay stable for
			// the bind step. Skip an href that duplicates sections.adminUrl
			// (GF/Fluent/Elementor all used to show "Open in X" twice).
			const visibleActions = ( coll.actions || [] )
				.map( ( a, i ) => ( { a, i } ) )
				.filter( ( { a } ) => ! a.when || String( surfaceValue( it, a.when.key ) ) === String( a.when.equals ) )
				.filter( ( { a } ) => {
					if ( ! a.href || ! m.sections || ! m.sections.adminUrl ) return true;
					const resolved = String( a.href ).replace( /\{(\w+)\}/g, ( _, k ) => encodeURIComponent( it[ k ] ?? '' ) );
					return resolved !== m.sections.adminUrl
						&& ! resolved.includes( String( m.sections.adminUrl ).replace( /#.*$/, '' ) );
				} );
			const sec = m.sections;
			// Form entries (GF / Fluent / Elementor) get a contact-style layout;
			// activity-log events get the same treatment (who → message → meta).
			const isEntry = !!( sec && ( sec.kind === 'entry' || s.family === 'forms' ) );
			const isActivity = ! isEntry && ( s.family === 'activity-log' || ( sec && sec.kind === 'activity' ) );
			const isCard = isEntry || isActivity;
			const secRows = sec && ! isCard ? ( sec.sections || [] ).map( ( g ) => `
					<div class="minn-side-title" style="margin:12px 0 8px;">${ esc( g.title || '' ) }</div>
					${ ( g.rows || [] ).map( ( r ) => {
						const raw = String( r.value == null ? '' : r.value );
						const multi = raw.includes( '\n' ) || raw.length > 90;
						const val = r.type === 'url' && /^https?:\/\//.test( raw )
							? `<a class="minn-surface-val" href="${ esc( raw ) }" target="_blank" rel="noopener">${ esc( raw ) }</a>`
							: `<span class="minn-surface-val${ multi ? ' multi' : '' }">${ esc( raw ) }</span>`;
						return `<div class="minn-side-row${ multi ? ' multi' : '' }"><span class="minn-side-key">${ esc( r.label || '' ) }</span>${ val }</div>`;
					} ).join( '' ) }` ).join( '' ) : '';
			const entryHtml = isEntry && ! m.loading ? renderEntryDetail( sec ) : '';
			const activityHtml = isActivity && ! m.loading ? renderActivityDetail( it, sec ) : '';
			// SH action_links (Edit post, Preview, …) — surface as soft buttons.
			const activityLinks = isActivity && Array.isArray( it.action_links )
				? it.action_links.filter( ( l ) => l && l.url && l.label ).slice( 0, 4 )
				: [];
			// Prev/next within the loaded list page (←/→). Media modal uses the
			// same pattern over its preview; surface detail puts the controls
			// in the head because there's no preview stage.
			const sctx = surfaceModalContext();
			const canStep = sctx && sctx.items.length > 1;
			// Wide when the detail shows a message body, card layout, or any
			// textarea edit field (code snippets need room for the code editor).
			const needsWide = !! message || isCard || editFields.some( ( f ) => f.type === 'textarea' );
			// Entry title = form name; activity keeps the surface label (message is body).
			const headTitle = isActivity
				? ( s.label || 'Activity Log' )
				: ( sec && sec.title
					? sec.title
					: ( isEntry && it.form_name ? it.form_name : ( isEntry && it.form_title ? it.form_title : s.label ) ) );
			const headStatus = ( sec && sec.status ) || it.status
				|| ( isActivity ? ( it.loglevel || it.severity || it.action ) : null );
			const activityAdmin = ( sec && sec.adminUrl ) || it.permalink || it.link || '';
			return `
			<div class="minn-modal-overlay" id="minn-modal-overlay">
				<div class="minn-modal${ needsWide ? ' wide' : '' }${ isCard ? ' entry' : '' }">
					<div class="minn-modal-head">
						<div class="minn-modal-title-block">
							<div class="minn-modal-title">${ esc( headTitle ) }</div>
							${ isEntry ? `<div class="minn-modal-sub">Entry #${ esc( String( it.id ) ) }</div>` : '' }
							${ isActivity ? `<div class="minn-modal-sub">Event #${ esc( String( it.id ) ) }</div>` : '' }
						</div>
						${ ! isCard ? `<span class="minn-modal-id-tag">#${ esc( String( it.id ) ) }</span>` : '' }
						${ headStatus ? surfacePill( headStatus )
							: ( typeof it.active === 'boolean' ? surfacePill( it.active ? 'active' : 'inactive' ) : '' ) }
						${ canStep ? `<span class="minn-modal-count">${ sctx.idx + 1 } / ${ sctx.items.length }</span>
						<button class="minn-modal-step" id="minn-surface-prev" type="button" title="Previous (←)"${ sctx.idx <= 0 ? ' disabled' : '' }>‹</button>
						<button class="minn-modal-step" id="minn-surface-next" type="button" title="Next (→)"${ sctx.idx >= sctx.items.length - 1 ? ' disabled' : '' }>›</button>` : '' }
						<button class="minn-x-btn" id="minn-modal-close">×</button>
					</div>
					${ m.loading ? '<div class="minn-loading">Loading…</div>' : `
					${ isEntry ? entryHtml : isActivity ? activityHtml : `
					<div class="minn-modal-meta">
						${ sec ? secRows : rows.map( ( [ k, v ] ) => `<div class="minn-side-row"><span class="minn-side-key">${ esc( k ) }</span><span class="minn-surface-val">${ esc( stripTags( String( v ) ) ) }</span></div>` ).join( '' ) }
						${ editFields.length ? `<div class="minn-media-edit">
							${ editFields.map( ( f, i ) => {
								const val = surfaceValue( it, f.key );
								return `<div class="minn-field-label"${ i ? ' style="margin-top:10px;"' : '' }>${ esc( f.label ) }</div>
								${ surfaceFieldHtml( f, val, 'data-editfield' ) }`;
							} ).join( '' ) }
						</div>` : '' }
					</div>
					${ message ? ( isHtml
						? `<iframe class="minn-email-frame" id="minn-email-frame" sandbox="" title="Email preview" srcdoc="${ esc( String( message ) ) }"></iframe>`
						: `<pre class="minn-surface-message">${ esc( stripTags( String( message ) ) ) }</pre>` ) : '' }` }
					${ ( message || edit || visibleActions.length || ( sec && sec.adminUrl ) || activityAdmin || activityLinks.length ) ? `
					<div class="minn-modal-actions">
						${ edit ? `<button class="minn-btn-primary" id="minn-surface-save">Save</button>` : '' }
						${ message ? `<button class="minn-btn-soft" id="minn-surface-raw">↗ Open raw</button>` : '' }
						${ sec && sec.adminUrl && ! isActivity ? `<a class="minn-btn-soft" href="${ esc( sec.adminUrl ) }" target="_blank" rel="noopener">Open in ${ esc( s.sub || 'wp-admin' ) } ↗</a>` : '' }
						${ isActivity && activityAdmin ? `<a class="minn-btn-soft" href="${ esc( activityAdmin ) }" target="_blank" rel="noopener">Open in ${ esc( s.sub || 'log' ) } ↗</a>` : '' }
						${ activityLinks.map( ( l ) => `<a class="minn-btn-soft" href="${ esc( l.url ) }" target="_blank" rel="noopener">${ esc( l.label ) }</a>` ).join( '' ) }
						${ visibleActions.map( ( { a, i } ) => a.href
							? `<a class="minn-btn-soft" href="${ esc( String( a.href ).replace( '{id}', encodeURIComponent( it.id ) ) ) }" target="_blank" rel="noopener">${ esc( a.label ) }</a>`
							: `<button class="minn-btn-soft${ a.danger ? ' danger' : '' }" data-saction="${ i }">${ esc( a.label ) }</button>` ).join( '' ) }
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
							<div class="minn-ac" id="minn-uf-role-ac">
								<input class="minn-input minn-ac-input" id="minn-uf-role" autocomplete="off" spellcheck="false" role="combobox" aria-expanded="false">
								<div class="minn-ac-panel" hidden></div>
							</div>` : `
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
									<div class="minn-session-meta">${ esc( sess.ip || '—' ) } · signed in ${ sess.login ? esc( new Date( sess.login * 1000 ).toLocaleString( undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' } ) ) : '—' }</div>
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

		if ( m.type === 'user-email' ) {
			const u = m.user || {};
			return `
			<div class="minn-modal-overlay" id="minn-modal-overlay">
				<div class="minn-modal">
					<div class="minn-modal-head">
						<div class="minn-modal-title-block">
							<div class="minn-modal-title">Send email</div>
							<div class="minn-modal-sub">To ${ esc( fmtUserLabel( u.name, u.email ) || 'user' ) }</div>
						</div>
						<button class="minn-x-btn" id="minn-modal-close">×</button>
					</div>
					<div class="minn-modal-form">
						<div>
							<div class="minn-field-label">Subject</div>
							<input class="minn-input" id="minn-ue-subject" value="${ esc( m.subject || '' ) }" placeholder="A note from ${ esc( B.site.name || 'the site' ) }" autocomplete="off">
						</div>
						<div>
							<div class="minn-field-label">Message</div>
							<textarea class="minn-input minn-insp-textarea" id="minn-ue-message" rows="8" placeholder="Write your message…">${ esc( m.message || '' ) }</textarea>
							<div class="minn-toggle-desc" style="margin-top:8px;">Sent as a styled Minn Admin HTML email from the site. Blank lines become paragraphs.</div>
						</div>
					</div>
					<div class="minn-modal-actions">
						<button class="minn-btn-primary" id="minn-ue-send">${ icon( 'send' ) } Send email</button>
						<button class="minn-btn-soft" id="minn-modal-cancel">Cancel</button>
					</div>
				</div>
			</div>`;
		}

		if ( m.type === 'user-delete' ) {
			const u = m.user || {};
			const cands = m.candidates;
			return `
			<div class="minn-modal-overlay" id="minn-modal-overlay">
				<div class="minn-modal">
					<div class="minn-modal-head">
						<div class="minn-modal-title-block">
							<div class="minn-modal-title">Delete user</div>
							<div class="minn-modal-sub">${ esc( fmtUserLabel( u.name, u.email ) ) }</div>
						</div>
						<button class="minn-x-btn" id="minn-modal-close">×</button>
					</div>
					<div class="minn-help-body" style="border-bottom:0;padding-bottom:4px;">
						<p>This permanently removes the account. Content they authored must be reassigned to another user.</p>
					</div>
					<div class="minn-modal-form" style="padding-top:0;">
						<div>
							<div class="minn-field-label">Reassign content to</div>
							${ cands == null ? '<div class="minn-loading" style="padding:12px;">Loading users…</div>' : `
							<div class="minn-ac" id="minn-ud-reassign-ac">
								<input class="minn-input minn-ac-input" id="minn-ud-reassign" autocomplete="off" spellcheck="false" role="combobox" aria-expanded="false" placeholder="Search users…">
								<div class="minn-ac-panel" hidden></div>
							</div>` }
						</div>
					</div>
					<div class="minn-modal-actions">
						<button class="minn-btn-primary danger" id="minn-ud-confirm" ${ cands == null ? 'disabled' : '' }>Delete user</button>
						<button class="minn-btn-soft" id="minn-modal-cancel">Cancel</button>
					</div>
				</div>
			</div>`;
		}

		if ( m.type === 'revision' ) {
			return renderRevisionModal( m );
		}

		if ( m.type === 'sys-detail' ) {
			return renderSysDetailModal( m );
		}

		if ( m.type === 'minn-off' ) {
			return `
			<div class="minn-modal-overlay" id="minn-modal-overlay">
				<div class="minn-modal">
					<div class="minn-modal-head">
						<div class="minn-modal-title">${ m.done ? 'Minn Admin is off' : 'Deactivate Minn Admin?' }</div>
						${ m.done ? '' : '<button class="minn-x-btn" id="minn-modal-close">×</button>' }
					</div>
					<div class="minn-help-body" style="border-bottom:0;">
						${ m.done
							? `<p><b>Done.</b> Heading to the classic dashboard… Reactivate Minn any time from <b>Plugins</b>, and everything here — content, settings, markup — is exactly as you left it.</p>`
							: `<p>This turns off the <b>/minn-admin/</b> dashboard and returns you to the classic wp-admin.</p>
							<p>Nothing is lost: Minn writes native WordPress content and options, and reactivating from the Plugins screen brings this dashboard straight back.</p>` }
					</div>
					${ m.done ? '' : `
					<div class="minn-modal-actions">
						<button class="minn-btn-soft danger" id="minn-off-confirm">Deactivate and go to wp-admin</button>
						<button class="minn-btn-primary" id="minn-off-cancel">Keep Minn</button>
					</div>` }
				</div>
			</div>`;
		}

		if ( m.type === 'changelog' ) {
			return `
			<div class="minn-modal-overlay" id="minn-modal-overlay">
				<div class="minn-modal wide">
					<div class="minn-modal-head">
						<div class="minn-modal-title">What's new · v${ esc( B.version ) }</div>
						<button class="minn-x-btn" id="minn-modal-close">×</button>
					</div>
					${ m.md === null ? '<div class="minn-loading">Loading changelog…</div>'
						: `<div class="minn-changelog">${ changelogHtml( m.md ) }</div>` }
				</div>
			</div>`;
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
			const formWide = ( cr.fields || [] ).some( ( f ) => f.type === 'textarea' );
			return `
			<div class="minn-modal-overlay" id="minn-modal-overlay">
				<div class="minn-modal${ formWide ? ' wide' : '' }">
					<div class="minn-modal-head">
						<div class="minn-modal-title">${ esc( cr.label || 'Add' ) } — ${ esc( m.surface.label ) }</div>
						<button class="minn-x-btn" id="minn-modal-close">×</button>
					</div>
					<div class="minn-modal-form">
						${ cr.fields.map( ( f ) => `<div>
							<div class="minn-field-label">${ esc( f.label ) }</div>
							${ surfaceFieldHtml( f, f.value, 'data-createfield' ) }
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

						<h4>Keyboard shortcuts</h4>
						<div class="minn-help-keys">
							<span class="minn-kbd">⌘K</span><span>Command palette · with text selected in the editor: link</span>
							<span class="minn-kbd">⌘S</span><span>Save, keeping the current status</span>
							<span class="minn-kbd">⌘⏎</span><span>Publish, Update or Schedule</span>
							<span class="minn-kbd">⌘/</span><span>Block library: browse every block, design and pattern</span>
							<span class="minn-kbd">⌘⇧F</span><span>Find &amp; replace in the post</span>
							<span class="minn-kbd">⌘⇧D</span><span>Focus mode: fade all but the current paragraph</span>
							<span class="minn-kbd">⌘⇧O</span><span>Outline mode: just the writing and the outline</span>
							<span class="minn-kbd">⌘.</span><span>Show or hide the navigation</span>
							<span class="minn-kbd">← →</span><span>Previous / next item in a media or entry detail</span>
							<span class="minn-kbd">Esc</span><span>Close menus and dialogs</span>
						</div>
						<p class="minn-help-keys-note">On Windows and Linux, use <span class="minn-kbd">Ctrl</span> in place of <span class="minn-kbd">⌘</span>.</p>

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
			// Category chips always show — empty state is guided, and they stay
			// useful as one-click re-searches after results land.
			const catChips = PLUGIN_CATEGORIES.map( ( c ) =>
				`<button type="button" class="minn-pi-cat${ m.category === c.id ? ' active' : '' }" data-pi-cat="${ esc( c.id ) }" title="Search WordPress.org for “${ esc( c.q ) }”">${ esc( c.label ) }</button>`
			).join( '' );
			const emptyHint = m.results == null && ! m.q
				? `<div class="minn-pi-guide">
					<div class="minn-pi-guide-title">Not sure what you need?</div>
					<div class="minn-pi-guide-sub">Pick a category to browse popular plugins, or type a name above.</div>
				</div>`
				: '';
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
						<div class="minn-pi-cats" role="group" aria-label="Browse by category">
							<span class="minn-pi-cats-label">Browse</span>
							${ catChips }
						</div>
						<div class="minn-pi-results">
							${ m.searching ? '<div class="minn-loading">Searching…</div>'
							: m.results == null ? emptyHint || '<div class="minn-empty" style="padding:20px;">Search for a plugin, or drop a zip above.</div>'
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
			const any = !! m.any;
			return `
			<div class="minn-modal-overlay" id="minn-modal-overlay">
				<div class="minn-modal wide">
					<div class="minn-modal-head">
						<div class="minn-modal-title">${ m.multi ? 'Build a gallery' : ( any ? 'Insert file' : 'Insert image' ) }</div>
						${ m.multi ? '<span class="minn-modal-count" id="minn-picker-count">Pick images in order</span>' : '' }
						<button class="minn-x-btn" id="minn-modal-close">×</button>
					</div>
					${ B.caps.upload && ! any ? `
					<div class="minn-picker-drop" id="minn-picker-drop">
						${ icon( 'img' ) }
						<span>Drag &amp; drop an image here, or <b>browse</b>${ m.multi ? '' : ' — it\'s used right away' }</span>
						<input type="file" id="minn-picker-file" accept="image/*" hidden>
					</div>` : '' }
					${ items == null ? `<div class="minn-loading">Loading ${ any ? 'files' : 'images' }…</div>` : ! items.length ? `<div class="minn-empty">No ${ any ? 'files' : 'images' } in the library yet.</div>` : `
					<div class="minn-picker-grid${ any ? ' any' : '' }">
						${ items.map( ( it, i ) => it.thumb
							? `<div class="minn-picker-item${ m.picked && m.picked.includes( it.id ) ? ' sel' : '' }" data-pick="${ i }" style="background-image:url('${ esc( it.thumb ) }')" title="${ esc( it.name ) }"></div>`
							: `<div class="minn-picker-item file${ m.picked && m.picked.includes( it.id ) ? ' sel' : '' }" data-pick="${ i }" title="${ esc( it.name ) }"><span class="minn-picker-file-icon">${ icon( 'file' ) }</span><span class="minn-picker-file-name">${ esc( it.name ) }</span></div>`
						).join( '' ) }
					</div>` }
					${ m.multi ? `
					<div class="minn-modal-actions">
						<button class="minn-btn-primary" id="minn-picker-done" disabled>Insert gallery</button>
					</div>` : '' }
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
		const closeBtn = $( '#minn-modal-close' );
		if ( closeBtn ) closeBtn.addEventListener( 'click', closeModal );
		const cancelBtn = $( '#minn-modal-cancel' );
		if ( cancelBtn ) cancelBtn.addEventListener( 'click', closeModal );

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

		if ( m.type === 'media' && m.editing ) {
			bindImageEditor( m );
		} else if ( m.type === 'media' ) {
			const it = m.item;
			const editBtn = $( '#minn-media-edit-image' );
			if ( editBtn ) editBtn.addEventListener( 'click', () => {
				m.editing = true;
				m.rot = 0;
				m.crop = null;
				// keep m.from (e.g. 'featured') so Save as copy can adopt it
				renderOverlays();
			} );
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
			// Caption + description are edit-context raw; fetch them once when
			// the detail modal opens and fill the fields in place (the list
			// stays view-context). The inputs render from it.caption/it.description
			// which start empty, so this is the first real value.
			const capEl = $( '#minn-media-caption' );
			const descEl = $( '#minn-media-description' );
			if ( capEl && ! it._metaLoaded ) {
				it._metaLoaded = true;
				api( `wp/v2/media/${ it.id }?context=edit&_fields=caption,description` ).then( ( full ) => {
					it.caption = ( full.caption && full.caption.raw ) || '';
					it.description = ( full.description && full.description.raw ) || '';
					// Only overwrite if the user hasn't started typing.
					const c = $( '#minn-media-caption' );
					const d = $( '#minn-media-description' );
					if ( c && document.activeElement !== c && ! c.value ) c.value = it.caption;
					if ( d && document.activeElement !== d && ! d.value ) d.value = it.description;
				} ).catch( () => {} );
			}
			const saveBtn = $( '#minn-media-save' );
			if ( saveBtn ) saveBtn.addEventListener( 'click', async () => {
				const title = $( '#minn-media-title' ).value.trim();
				const alt = $( '#minn-media-alt' ).value;
				const caption = capEl ? capEl.value : '';
				const description = descEl ? descEl.value : '';
				saveBtn.disabled = true;
				saveBtn.textContent = 'Saving…';
				try {
					await api( `wp/v2/media/${ it.id }`, { method: 'POST', body: JSON.stringify( { title, alt_text: alt, caption, description } ) } );
					it.name = title || it.name;
					it.alt = alt;
					it.caption = caption;
					it.description = description;
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
			// Featured-image context: replace / remove without leaving the modal
			// flow for another sidebar click.
			const featRep = $( '#minn-media-feat-replace' );
			if ( featRep ) featRep.addEventListener( 'click', () => {
				const ed = state.editor;
				if ( ! ed ) return;
				closeModal();
				openMediaPicker( ( pick ) => {
					ed.featuredMedia = pick.id;
					ed.featuredThumb = pick.thumb;
					ed.featuredDirty = true;
					renderEditorSide();
					if ( ed.id ) scheduleAutosave();
					toast( 'Featured image updated' );
				} );
			} );
			const featRm = $( '#minn-media-feat-remove' );
			if ( featRm ) featRm.addEventListener( 'click', () => {
				const ed = state.editor;
				if ( ! ed ) return;
				ed.featuredMedia = 0;
				ed.featuredThumb = null;
				ed.featuredDirty = true;
				closeModal();
				renderEditorSide();
				if ( ed.id ) scheduleAutosave();
				toast( 'Featured image removed' );
			} );
			// Regenerate Thumbnails: the plugin's regenerator runs server-side
			// (adapters/regenerate-thumbnails.php); the modal stays open.
			const regenBtn = $( '#minn-media-regen' );
			if ( regenBtn ) regenBtn.addEventListener( 'click', async () => {
				regenBtn.disabled = true;
				regenBtn.textContent = 'Regenerating…';
				try {
					const r = await api( `minn-admin/v1/media/${ it.id }/regenerate`, { method: 'POST' } );
					toast( `Regenerated ${ r.sizes } thumbnail size${ r.sizes === 1 ? '' : 's' }` );
				} catch ( e ) {
					toast( e.message, true );
				}
				regenBtn.disabled = false;
				regenBtn.textContent = '↻ Thumbnails';
			} );
			$( '#minn-media-delete' ).addEventListener( 'click', () => deleteMediaItem( it ) );
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
					if ( onStructure() ) renderStructure();
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
					if ( onStructure() ) renderStructure();
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
					const v = surfaceFieldValue( input );
					const empty = v == null || v === '' || ( Array.isArray( v ) && ! v.length );
					// Number 0 is a real value; optional fields can set required:false.
					const field = ( cr.fields || [] ).find( ( f ) => f.key === input.dataset.createfield );
					if ( empty && ( ! field || field.required !== false ) && input.dataset.edittype !== 'number' ) missing = true;
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
					if ( onStructure() ) renderStructure();
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
					if ( onStructure() ) renderStructure();
				} catch ( e ) {
					toast( e.message, true );
				}
			} );
		}

		if ( m.type === 'picker' ) {
			const syncPickerBar = () => {
				const done = $( '#minn-picker-done' );
				const count = $( '#minn-picker-count' );
				if ( done ) {
					done.disabled = ! m.picked.length;
					done.textContent = m.picked.length ? `Insert gallery (${ m.picked.length })` : 'Insert gallery';
				}
				if ( count ) count.textContent = m.picked.length ? `${ m.picked.length } selected — in click order` : 'Pick images in order';
			};
			$$( '[data-pick]' ).forEach( ( el ) =>
				el.addEventListener( 'click', () => {
					const it = m.items[ parseInt( el.dataset.pick, 10 ) ];
					if ( ! it ) return;
					if ( m.multi ) {
						// Toggle in place — no re-render, so the grid keeps its scroll.
						const at = m.picked.indexOf( it.id );
						if ( at === -1 ) m.picked.push( it.id );
						else m.picked.splice( at, 1 );
						el.classList.toggle( 'sel', at === -1 );
						syncPickerBar();
						return;
					}
					const cb = m.callback;
					closeModal();
					if ( cb ) cb( it );
				} )
			);
			const done = $( '#minn-picker-done' );
			if ( done ) done.addEventListener( 'click', () => {
				const picks = m.picked
					.map( ( id ) => m.items.find( ( x ) => x.id === id ) )
					.filter( Boolean );
				const cb = m.callback;
				closeModal();
				if ( picks.length && cb ) cb( picks );
			} );
			if ( m.multi ) syncPickerBar();
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
							large: ( sizes && sizes.large && sizes.large.source_url ) || up.source_url,
						};
						if ( m.multi ) {
							// Add to the gallery selection and keep picking.
							m.items.unshift( it );
							m.picked.push( it.id );
							renderOverlays();
							toast( 'Image uploaded and selected' );
							return;
						}
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
			const prevBtn = $( '#minn-surface-prev' );
			const nextBtn = $( '#minn-surface-next' );
			if ( prevBtn ) prevBtn.addEventListener( 'click', () => surfaceModalNav( -1 ) );
			if ( nextBtn ) nextBtn.addEventListener( 'click', () => surfaceModalNav( 1 ) );
			const rawBtn = $( '#minn-surface-raw' );
			if ( rawBtn ) rawBtn.addEventListener( 'click', () => {
				const detail = ( m.coll || m.surface.collection ).detail || {};
				const msg = detail.messageKey ? m.item[ detail.messageKey ] : null;
				if ( msg == null ) return;
				// text/plain, never text/html — blob: URLs are same-origin, so scripts in
				// a logged email (which can carry user-submitted content) would run as the app.
				const blob = new Blob( [ String( msg ) ], { type: 'text/plain' } );
				window.open( URL.createObjectURL( blob ), '_blank' );
			} );
			const saveBtn = $( '#minn-surface-save' );
			if ( saveBtn ) saveBtn.addEventListener( 'click', async () => {
				const edit = ( ( m.coll || m.surface.collection ).detail || {} ).edit;
				if ( ! edit ) return;
				const body = {};
				// Carry the untouched fields so the plugin's sanitizer doesn't reset them.
				( edit.preserve || [] ).forEach( ( k ) => { const v = surfaceValue( m.item, k ); if ( v !== undefined ) body[ k ] = v; } );
				$$( '[data-editfield]' ).forEach( ( input ) => {
					setDeepPath( body, input.dataset.editfield, surfaceFieldValue( input ) );
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
					const action = ( m.coll || m.surface.collection ).actions[ parseInt( btn.dataset.saction, 10 ) ];
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
						// A row action can change what the status card reports
						// (Disembark: deleting a session shrinks the workspace).
						surfaceState( m.surface.id ).status = null;
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

		if ( m.type === 'user-email' ) {
			const sub = $( '#minn-ue-subject' );
			const msg = $( '#minn-ue-message' );
			if ( sub ) setTimeout( () => sub.focus(), 30 );
			$( '#minn-ue-send' ).addEventListener( 'click', async ( e ) => {
				const btn = e.currentTarget;
				const subject = ( sub && sub.value || '' ).trim();
				const message = ( msg && msg.value || '' ).trim();
				if ( ! subject || ! message ) {
					toast( 'Subject and message are required', true );
					return;
				}
				btn.disabled = true;
				try {
					const r = await api( `minn-admin/v1/users/${ m.user.id }/email`, {
						method: 'POST',
						body: JSON.stringify( { subject, message } ),
					} );
					toast( 'Email sent' + ( r && r.email ? ' to ' + r.email : '' ) );
					closeModal();
				} catch ( err ) {
					toast( err.message, true );
					btn.disabled = false;
				}
			} );
		}

		if ( m.type === 'user-delete' ) {
			const cands = m.candidates || [];
			const ac = $( '#minn-ud-reassign-ac' );
			if ( ac && cands.length ) {
				bindAutocomplete( ac, cands.map( ( u ) => ( {
					value: String( u.id ),
					label: fmtUserLabel( u.name, u.email ),
				} ) ), {
					strict: true,
					value: m.reassign || String( B.user.id ),
				} );
			}
			const confirmBtn = $( '#minn-ud-confirm' );
			if ( confirmBtn ) confirmBtn.addEventListener( 'click', async () => {
				const input = $( '#minn-ud-reassign' );
				const reassign = ( input && input.dataset.acValue ) || m.reassign || String( B.user.id );
				if ( ! reassign ) {
					toast( 'Pick a user to reassign content to', true );
					return;
				}
				if ( ! confirm( `Permanently delete ${ m.user.name || 'this user' }? This cannot be undone.` ) ) return;
				confirmBtn.disabled = true;
				try {
					await api( `wp/v2/users/${ m.user.id }?force=true&reassign=${ encodeURIComponent( reassign ) }`, { method: 'DELETE' } );
					toast( 'User deleted' );
					closeModal();
					state.cache.users = null;
					if ( state.route === 'users' ) renderUsers();
				} catch ( err ) {
					toast( err.message, true );
					confirmBtn.disabled = false;
				}
			} );
		}

		if ( m.type === 'revision' ) {
			bindRevisionModal( m );
		}

		if ( m.type === 'minn-off' && ! m.done ) {
			$( '#minn-off-cancel' ).addEventListener( 'click', closeModal );
			$( '#minn-off-confirm' ).addEventListener( 'click', async ( e ) => {
				e.currentTarget.disabled = true;
				try {
					await api( 'wp/v2/plugins/' + m.file, {
						method: 'PUT',
						body: JSON.stringify( { status: 'inactive' } ),
					} );
					m.done = true;
					renderOverlays();
					// A beat to read the landing before the classic dashboard.
					setTimeout( () => { window.location.href = B.site.adminUrl; }, 1600 );
				} catch ( err ) {
					toast( err.message, true );
					closeModal();
				}
			} );
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

	async function loadThemeResults( m, q ) {
		m.searching = true;
		renderOverlays();
		try {
			const r = await api( 'minn-admin/v1/themes/search?q=' + encodeURIComponent( q ) );
			if ( state.modal !== m ) return;
			m.results = r.themes;
		} catch ( e ) {
			toast( e.message, true );
			m.results = [];
		}
		m.searching = false;
		renderOverlays();
	}

	function bindThemeInstallModal( m ) {
		const input = $( '#minn-ti-search' );
		input.focus();
		input.setSelectionRange( input.value.length, input.value.length );
		// Preload the popular directory so the dialog isn't a blank box.
		if ( ! m.results && ! m.searching ) loadThemeResults( m, '' );
		input.addEventListener( 'input', () => {
			m.q = input.value.trim();
			clearTimeout( tiSearchTimer );
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
		zone._accept = uploadZip; // window-level drops route here while the modal is open
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

	// Guided starting points for the Add plugin dialog. Each chip is just a
	// plain wp.org search string — nothing curated or ranked, just a nudge
	// toward the kinds of plugins people usually look for. Keep queries short
	// and everyday so the directory's own ranking surfaces popular options.
	const PLUGIN_CATEGORIES = [
		{ id: 'seo', label: 'SEO', q: 'SEO' },
		{ id: 'forms', label: 'Forms', q: 'contact form' },
		{ id: 'ecommerce', label: 'Ecommerce', q: 'ecommerce' },
		{ id: 'security', label: 'Security', q: 'security' },
		{ id: 'backup', label: 'Backup', q: 'backup' },
		{ id: 'analytics', label: 'Analytics', q: 'analytics' },
		{ id: 'cache', label: 'Cache', q: 'cache' },
		{ id: 'email', label: 'Email / SMTP', q: 'SMTP' },
		{ id: 'redirects', label: 'Redirects', q: 'redirect' },
		{ id: 'devtools', label: 'Dev tools', q: 'developer' },
		{ id: 'spam', label: 'Spam', q: 'antispam' },
		{ id: 'blocks', label: 'Blocks', q: 'gutenberg blocks' },
	];

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

	// Shared by free typing and category chips. Re-renders the modal around
	// the in-flight search so the chip active state and input value stay in sync.
	async function runPluginSearch( m, q, categoryId ) {
		m.q = ( q || '' ).trim();
		m.category = categoryId || null;
		m.page = 1;
		m.pages = 1;
		m.total = 0;
		if ( ! m.q ) {
			m.results = null;
			m.searching = false;
			renderOverlays();
			return;
		}
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
	}

	function bindPluginInstallModal( m ) {
		const input = $( '#minn-pi-search' );
		// Don't steal focus when a category just filled the box (chip click
		// re-renders and would yank the caret); focus only on a blank open.
		if ( ! m.q ) {
			input.focus();
			input.setSelectionRange( 0, 0 );
		} else {
			input.focus( { preventScroll: true } );
			input.setSelectionRange( input.value.length, input.value.length );
		}
		input.addEventListener( 'input', () => {
			const q = input.value.trim();
			// Free typing clears the category highlight (query may diverge).
			m.category = null;
			clearTimeout( piSearchTimer );
			if ( ! q ) {
				m.q = '';
				m.results = null;
				m.searching = false;
				renderOverlays();
				return;
			}
			piSearchTimer = setTimeout( () => runPluginSearch( m, q, null ), 400 );
		} );

		// Category chips → same search path, with the chip marked active.
		$$( '[data-pi-cat]' ).forEach( ( btn ) =>
			btn.addEventListener( 'click', () => {
				const cat = PLUGIN_CATEGORIES.find( ( c ) => c.id === btn.dataset.piCat );
				if ( ! cat ) return;
				// Second click on the active category clears back to the guide.
				if ( m.category === cat.id ) {
					clearTimeout( piSearchTimer );
					runPluginSearch( m, '', null );
					return;
				}
				clearTimeout( piSearchTimer );
				runPluginSearch( m, cat.q, cat.id );
			} )
		);

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
						await refreshAfterPluginChange();
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
		zone._accept = uploadZip; // window-level drops route here while the modal is open
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

	/* ===== Revision diff ===== */
	// Writers think in "what changed", not revision IDs — the History card
	// opens a side-by-side diff of the revision against the CURRENT editor
	// content (live serializer output, so unsaved edits count). Block-level
	// LCS aligns paragraphs; changed pairs refine to word-level <del>/<ins>
	// marks on escaped TEXT (never marked-up HTML — honest and injection-safe;
	// unchanged rows render their real markup, dimmed).

	// Classic LCS keep-table over arrays of comparable strings.
	function lcsOps( a, b, key ) {
		const n = a.length, m = b.length;
		// Guard the O(n·m) table — beyond this, callers degrade gracefully.
		if ( n * m > 1200 * 1200 ) return null;
		const w = m + 1;
		const dp = new Uint16Array( ( n + 1 ) * w );
		for ( let i = n - 1; i >= 0; i-- ) {
			for ( let j = m - 1; j >= 0; j-- ) {
				dp[ i * w + j ] = key( a[ i ] ) === key( b[ j ] )
					? dp[ ( i + 1 ) * w + j + 1 ] + 1
					: Math.max( dp[ ( i + 1 ) * w + j ], dp[ i * w + j + 1 ] );
			}
		}
		const ops = [];
		let i = 0, j = 0;
		while ( i < n && j < m ) {
			if ( key( a[ i ] ) === key( b[ j ] ) ) { ops.push( [ 'same', i++, j++ ] ); }
			else if ( dp[ ( i + 1 ) * w + j ] >= dp[ i * w + j + 1 ] ) { ops.push( [ 'del', i++, -1 ] ); }
			else { ops.push( [ 'add', -1, j++ ] ); }
		}
		while ( i < n ) ops.push( [ 'del', i++, -1 ] );
		while ( j < m ) ops.push( [ 'add', -1, j++ ] );
		return ops;
	}

	// Word-level marks for one changed block pair; splits keep whitespace so
	// the rejoin is lossless. Falls back to whole-side marks past the cap.
	// sameRatio (shared words / larger side) lets callers refuse to pair
	// blocks that merely happen to sit in the same del/add run.
	function diffWords( oldText, newText ) {
		const a = oldText.split( /(\s+)/ ).filter( ( t ) => t !== '' );
		const b = newText.split( /(\s+)/ ).filter( ( t ) => t !== '' );
		const ops = lcsOps( a, b, ( t ) => t );
		if ( ! ops ) {
			return { left: `<del>${ esc( oldText ) }</del>`, right: `<ins>${ esc( newText ) }</ins>`, sameRatio: 0 };
		}
		let left = '', right = '', delBuf = '', addBuf = '', sameWords = 0;
		const flush = () => {
			if ( delBuf ) { left += `<del>${ esc( delBuf ) }</del>`; delBuf = ''; }
			if ( addBuf ) { right += `<ins>${ esc( addBuf ) }</ins>`; addBuf = ''; }
		};
		ops.forEach( ( [ op, i, j ] ) => {
			if ( op === 'same' ) { flush(); left += esc( a[ i ] ); right += esc( b[ j ] ); if ( a[ i ].trim() ) sameWords++; }
			else if ( op === 'del' ) { delBuf += a[ i ]; }
			else { addBuf += b[ j ]; }
		} );
		flush();
		const words = Math.max( a.filter( ( t ) => t.trim() ).length, b.filter( ( t ) => t.trim() ).length, 1 );
		return { left, right, sameRatio: sameWords / words };
	}

	// Top-level blocks of a rendered-ish HTML string (block comments already
	// meaningless to a writer — stripped like the old preview did).
	function diffBlocksOf( html ) {
		const div = document.createElement( 'div' );
		div.innerHTML = stripBlockComments( html || '' );
		const out = [];
		div.childNodes.forEach( ( n ) => {
			if ( n.nodeType === 1 ) {
				const text = ( n.textContent || '' ).replace( /\s+/g, ' ' ).trim();
				// Media/embed blocks have no prose — compare their markup so an
				// image swap still registers as a change.
				out.push( { text: text || n.outerHTML, html: n.outerHTML } );
			} else if ( n.nodeType === 3 && n.textContent.trim() ) {
				out.push( { text: n.textContent.replace( /\s+/g, ' ' ).trim(), html: esc( n.textContent ) } );
			}
		} );
		return out;
	}

	// rows: { kind: same|change|del|add, left, right } — left/right are HTML.
	function diffRevision( oldHtml, newHtml ) {
		const a = diffBlocksOf( oldHtml );
		const b = diffBlocksOf( newHtml );
		const ops = lcsOps( a, b, ( x ) => x.text );
		if ( ! ops ) return null; // beyond the cap — caller shows the raw preview
		const rows = [];
		let dels = [], adds = [];
		const flush = () => {
			// Pair up del/add runs index-wise as word-level changes — but only
			// when the pair genuinely shares words. Two unrelated paragraphs
			// that merely sit in the same run read better as a removal + an
			// addition than as one fully-marked "change".
			const pairs = Math.min( dels.length, adds.length );
			for ( let k = 0; k < pairs; k++ ) {
				const wd = diffWords( dels[ k ].text, adds[ k ].text );
				if ( wd.sameRatio >= 0.4 ) {
					rows.push( { kind: 'change', left: wd.left, right: wd.right } );
				} else {
					rows.push( { kind: 'del', left: `<del>${ esc( dels[ k ].text ) }</del>`, right: '' } );
					rows.push( { kind: 'add', left: '', right: `<ins>${ esc( adds[ k ].text ) }</ins>` } );
				}
			}
			dels.slice( pairs ).forEach( ( x ) => rows.push( { kind: 'del', left: `<del>${ esc( x.text ) }</del>`, right: '' } ) );
			adds.slice( pairs ).forEach( ( x ) => rows.push( { kind: 'add', left: '', right: `<ins>${ esc( x.text ) }</ins>` } ) );
			dels = []; adds = [];
		};
		ops.forEach( ( [ op, i, j ] ) => {
			if ( op === 'same' ) { flush(); rows.push( { kind: 'same', left: a[ i ].html, right: b[ j ].html } ); }
			else if ( op === 'del' ) { dels.push( a[ i ] ); }
			else { adds.push( b[ j ] ); }
		} );
		flush();
		return rows;
	}

	// The live "current" side — the same serializer the save path uses, so
	// unsaved edits diff truthfully; locked mode falls back to loaded content.
	function currentEditorContent( ed ) {
		const body = $( '#minn-editor-body' );
		if ( body && ed.mode === 'blocks' ) return serializeToBlocks( body, ed.islands );
		if ( body && ed.mode === 'classic' ) return classicHtml( body );
		return ed.content || '';
	}

	// The ordered revision ids the History card shows — the list ←/→ steps
	// through while the revision modal is open.
	function revisionNavIds() {
		const ed = state.editor;
		return ed ? historyRowsFor( ed ).map( ( r ) => r.id ) : [];
	}

	function revisionModalNav( dir ) {
		const m = state.modal;
		if ( ! m || m.type !== 'revision' || ! state.editor ) return;
		const ids = revisionNavIds();
		const idx = ids.indexOf( m.revId );
		const next = ids[ idx + dir ];
		if ( idx === -1 || next === undefined ) return;
		openRevision( state.editor, next );
	}

	function openRevision( ed, revId ) {
		// Capture the current side NOW — the serializer needs the editor DOM,
		// and the diff must reflect what the writer sees, unsaved edits included.
		state.modal = {
			type: 'revision', ed: { id: ed.id, type: ed.type }, revId, rev: null,
			current: currentEditorContent( ed ),
			currentTitle: $( '#minn-editor-title' ) ? $( '#minn-editor-title' ).value : ( ed.title || '' ),
		};
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

	/* ===== Changelog modal (the version badge) ===== */
	// A tiny renderer for OUR changelog's markdown vocabulary — headings,
	// bullets, bold, code, links. Everything is escaped first; inline marks
	// are rebuilt on the escaped text, so nothing in the file can inject.
	function changelogInline( s ) {
		return esc( s )
			.replace( /\*\*([^*]+)\*\*/g, '<b>$1</b>' )
			.replace( /`([^`]+)`/g, '<code>$1</code>' )
			.replace( /\[([^\]]+)\]\((https?:\/\/[^)\s]+|[\w./-]+)\)/g, ( m0, t, u ) =>
				/^https?:/.test( u ) ? `<a href="${ u }" target="_blank" rel="noopener">${ t }</a>` : t );
	}

	function changelogHtml( md ) {
		const out = [];
		let list = null;
		const flushList = () => { if ( list ) { out.push( `<ul>${ list.join( '' ) }</ul>` ); list = null; } };
		String( md ).split( /\r?\n/ ).forEach( ( line ) => {
			const l = line.trim();
			if ( /^# /.test( l ) ) { flushList(); return; } // the file's own "# Changelog" title — the modal has one
			if ( /^## /.test( l ) ) { flushList(); out.push( `<h3>${ changelogInline( l.slice( 3 ) ) }</h3>` ); return; }
			if ( /^### /.test( l ) ) { flushList(); out.push( `<h4>${ changelogInline( l.slice( 4 ) ) }</h4>` ); return; }
			if ( /^[*-] /.test( l ) ) { ( list = list || [] ).push( `<li>${ changelogInline( l.slice( 2 ) ) }</li>` ); return; }
			if ( ! l ) { flushList(); return; }
			flushList();
			out.push( `<p>${ changelogInline( l ) }</p>` );
		} );
		flushList();
		return out.join( '' ) || '<div class="minn-empty">No changelog found.</div>';
	}

	function openChangelog() {
		state.modal = { type: 'changelog', md: null };
		renderOverlays();
		api( 'minn-admin/v1/changelog' )
			.then( ( r ) => {
				if ( state.modal && state.modal.type === 'changelog' ) {
					state.modal.md = r.markdown || '';
					renderOverlays();
				}
			} )
			.catch( ( e ) => { toast( e.message, true ); closeModal(); } );
	}

	function renderRevisionModal( m ) {
		const rev = m.rev;
		let bodyHtml = '';
		if ( rev ) {
			const revTitle = decodeEntities( ( rev.title && ( rev.title.raw != null ? rev.title.raw : rev.title.rendered ) ) || '' );
			const revContent = ( rev.content && ( rev.content.raw != null ? rev.content.raw : rev.content.rendered ) ) || '';
			const rows = diffRevision( revContent, m.current || '' );
			const changed = rows ? rows.filter( ( r ) => r.kind !== 'same' ).length : 0;
			const titleDiff = revTitle !== ( m.currentTitle || '' )
				? diffWords( revTitle || '(no title)', m.currentTitle || '(no title)' )
				: null;
			const summary = rows
				? ( changed || titleDiff ? `${ changed } block${ changed === 1 ? '' : 's' } differ${ changed === 1 ? 's' : '' } from the current content${ titleDiff ? ' · title changed' : '' }` : 'Identical to the current content' )
				: 'Post too large to diff — showing the revision as saved';
			bodyHtml = `
				<div class="minn-modal-meta">
					${ titleDiff ? `<div class="minn-side-row"><span class="minn-side-key">Title</span><span class="minn-diff-inline">${ titleDiff.left } → ${ titleDiff.right }</span></div>`
						: `<div class="minn-side-row"><span class="minn-side-key">Title</span><span class="minn-surface-val">${ esc( revTitle || '(no title)' ) }</span></div>` }
					<div class="minn-side-row"><span class="minn-side-key">Saved</span><span>${ timeAgo( rev.modified ) }</span></div>
					<div class="minn-side-row"><span class="minn-side-key">Changes</span><span>${ esc( summary ) }</span></div>
				</div>
				${ rows ? `
				<div class="minn-diff" id="minn-diff">
					<div class="minn-diff-headrow"><div>This revision</div><div>Current</div></div>
					${ rows.map( ( r ) => `
					<div class="minn-diff-row ${ r.kind }">
						<div class="minn-diff-cell${ r.left ? '' : ' empty' }">${ r.left }</div>
						<div class="minn-diff-cell${ r.right ? '' : ' empty' }">${ r.right }</div>
					</div>` ).join( '' ) }
				</div>` : '<div class="minn-revision-preview" id="minn-revision-preview"></div>' }
				<div class="minn-modal-actions">
					<button class="minn-btn-primary" id="minn-restore-rev">Restore this revision</button>
				</div>`;
		}
		// ←/→ steps through the History card's revisions (the surface-detail
	// pattern: count + step buttons in the head, arrows on the keyboard).
	const ids = revisionNavIds();
	const idx = ids.indexOf( m.revId );
	const canStep = idx !== -1 && ids.length > 1;
	return `
		<div class="minn-modal-overlay" id="minn-modal-overlay">
			<div class="minn-modal xl">
				<div class="minn-modal-head">
					<div class="minn-modal-title">${ rev ? 'Revision · ' + timeAgo( rev.modified ) : 'Revision' }</div>
					${ canStep ? `<span class="minn-modal-count">${ idx + 1 } / ${ ids.length }</span>
					<button class="minn-modal-step" id="minn-rev-prev" type="button" title="Newer (←)"${ idx <= 0 ? ' disabled' : '' }>‹</button>
					<button class="minn-modal-step" id="minn-rev-next" type="button" title="Older (→)"${ idx >= ids.length - 1 ? ' disabled' : '' }>›</button>` : '' }
					<button class="minn-x-btn" id="minn-modal-close">×</button>
				</div>
				${ ! rev ? '<div class="minn-loading">Loading revision…</div>' : bodyHtml }
			</div>
		</div>`;
	}

	function bindRevisionModal( m ) {
		// Step buttons live in the head, present even while the revision body
		// is still loading — bind them before the loading-state early return.
		const prev = $( '#minn-rev-prev' );
		const next = $( '#minn-rev-next' );
		if ( prev ) prev.addEventListener( 'click', () => revisionModalNav( -1 ) );
		if ( next ) next.addEventListener( 'click', () => revisionModalNav( 1 ) );
		const rev = m.rev;
		if ( ! rev ) return;
		// Raw-preview fallback only exists when the post was too large to diff.
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

		// Role rides the strict combobox (same as the Users filter) — real
		// sites stack 10+ roles and a bare select doesn't type-to-filter.
		const roleAc = $( '#minn-uf-role-ac' );
		if ( roleAc ) {
			const current = m.user && m.user.roles && m.user.roles[ 0 ] ? m.user.roles[ 0 ] : 'subscriber';
			bindAutocomplete( roleAc, Object.entries( B.roles || {} ).map( ( [ v, l ] ) => ( { value: v, label: l } ) ), {
				strict: true,
				value: current,
			} );
		}

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
			// Strict combobox: the picked slug rides dataset.acValue, the
			// input's visible value is the display label.
			const roleSel = $( '#minn-uf-role' );
			if ( B.caps.promoteUsers && roleSel && roleSel.dataset.acValue ) payload.roles = [ roleSel.dataset.acValue ];
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
			// Same reassign flow as the users row menu.
			del.addEventListener( 'click', () => {
				if ( ! m.user ) return;
				closeModal();
				openUserDeleteModal( m.user );
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

	function openMediaPicker( callback, opts = {} ) {
		state.modal = { type: 'picker', items: null, callback, multi: !! opts.multi, picked: [], any: !! opts.any };
		renderOverlays();
		// File block needs any attachment type; image/gallery stay image-only.
		const typeQ = opts.any ? '' : ( opts.mediaType ? '&media_type=' + encodeURIComponent( opts.mediaType ) : '&media_type=image' );
		api( 'wp/v2/media?per_page=48&orderby=date&order=desc&_fields=id,title,source_url,media_details,alt_text,mime_type' + typeQ )
			.then( ( items ) => {
				if ( ! state.modal || state.modal.type !== 'picker' ) return;
				state.modal.items = items.map( ( it ) => {
					const sizes = it.media_details && it.media_details.sizes;
					const isImg = ( it.mime_type || '' ).startsWith( 'image/' );
					return {
						id: it.id,
						name: decodeEntities( it.title.rendered ),
						url: it.source_url,
						alt: it.alt_text || '',
						thumb: isImg
							? ( ( sizes && sizes.medium && sizes.medium.source_url ) || it.source_url )
							: '',
						large: isImg
							? ( ( sizes && sizes.large && sizes.large.source_url ) || it.source_url )
							: it.source_url,
						mime: it.mime_type || '',
					};
				} );
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
			const updAll = $( '#minn-update-all' );
			if ( updAll ) updAll.addEventListener( 'click', runUpdateEverything );
			$$( '.minn-notif-link' ).forEach( ( b ) =>
				b.addEventListener( 'click', ( e ) => {
					e.stopPropagation();
					const item = ( state.cache.notifications || [] ).find( ( n ) => n.id === b.dataset.nid );
					const link = item && ( item.links || [] )[ parseInt( b.dataset.li, 10 ) ];
					if ( ! link ) return;
					if ( link.action ) runNoticeAction( link, b );
					else window.open( link.url, '_blank' );
				} )
			);
			// Hide clears the notice from Minn's OWN digest — for nags whose
			// real dismissal is plugin-specific admin-ajax JS Minn can't
			// replay. Ids are content-stable so it survives re-captures.
			$$( '.minn-notif-hide' ).forEach( ( b ) =>
				b.addEventListener( 'click', async ( e ) => {
					e.stopPropagation();
					const nid = b.dataset.nid;
					const hash = nid.replace( /^notice-/, '' );
					state.cache.notifications = ( state.cache.notifications || [] ).filter( ( n ) => n.id !== nid );
					renderOverlays();
					updateUnreadDot();
					try {
						await api( 'minn-admin/v1/notices/hide', { method: 'POST', body: JSON.stringify( { id: hash } ) } );
						toastAction( 'Notice hidden', 'Undo', async () => {
							await api( 'minn-admin/v1/notices/unhide', { method: 'POST', body: JSON.stringify( { id: hash } ) } ).catch( () => {} );
							state.cache.notifications = null;
							await loadNotifications();
							if ( state.notifOpen ) renderOverlays();
						} );
					} catch ( err ) {
						toast( err.message, true );
					}
				} )
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
					// Notices act through their link buttons — the row itself
					// only marks read and the panel stays open.
					if ( item.kind === 'notices' ) {
						renderOverlays();
						return;
					}
					state.notifOpen = false;
					renderOverlays();
					// Take the user to the thing the notification is about.
					if ( item.kind === 'comments' && B.caps.moderate ) go( 'comments' );
					else if ( item.id.startsWith( 'theme-' ) && B.caps.themes ) { state.extTab = 'themes'; go( 'extensions' ); }
					else if ( item.kind === 'updates' && B.caps.plugins ) go( 'extensions' );
					else if ( item.id.startsWith( 'user-' ) && B.caps.users ) go( 'users' );
					else if ( item.id.startsWith( 'core-' ) && B.caps.core ) go( 'extensions' );
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
	// appear only when the strip actually overflows (and only on the side you can
	// scroll). Aux groups (a pinned slice like Content's Trash) are skipped —
	// they must never scroll out of sight with the main strip.
	function enhanceTabStrips() {
		$$( '.minn-tabs:not([data-scroll]):not(.minn-tabs-aux)' ).forEach( ( tabs ) => {
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
			// Active tab may be off-screen on first paint — reveal it by moving
			// ONLY the strip's own scrollLeft. scrollIntoView propagates to
			// every scroll ancestor and yanked the page vertically to the tab
			// bar on each re-render (the plugin-toggle scroll bug).
			const active = tabs.querySelector( '.minn-tab.active' );
			if ( active ) {
				const target = active.offsetLeft - tabs.clientWidth / 2 + active.clientWidth / 2;
				tabs.scrollLeft = Math.max( 0, Math.min( target, tabs.scrollWidth - tabs.clientWidth ) );
			}
			update();
		} );
	}

	function renderView() {
		renderTopbar();
		closeInspector();
		closeBlockPicker();
		hideCodePop();
		hideTableMenu();
		hideDatePicker();
		clearTableChips();
		removeFocusDim();
		removeOutlineMode();
		closeFindBar();
		hideMinnMenu();
		$$( '.minn-row-menu' ).forEach( ( el ) => el.remove() );
		hideImgPop();
		hideLinkPop();
		closeVisPopover();
		removeLockOverlay();
		const tip = $( '#minn-chart-tip' );
		if ( tip ) tip.hidden = true;
		switch ( state.route ) {
			case 'content': return renderContent();
			case 'media': return renderMedia();
			case 'comments': return renderComments();
			case 'orders': return renderOrders();
			case 'users': return renderUsers();
			case 'terms': return renderStructure();
			case 'menus': return renderMenus();
			case 'widgets': return renderWidgets();
			case 'extensions': return renderExtensions();
			case 'posttypes': return renderStructure();
			case 'settings': return renderSettings();
			case 'system': return renderSystem();
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

		// Closing the tab with unsaved editor changes gets the standard warning.
		window.addEventListener( 'beforeunload', ( e ) => {
			if ( state.route === 'editor' && state.editor && state.editor.dirty ) {
				e.preventDefault();
				e.returnValue = '';
			}
		} );

		// Leaving for real: land any pending crash-net snapshot synchronously
		// (the last defense the net offers on a clean-ish close), and hand the
		// edit lock back — sendBeacon is the only reliable transport here; it
		// can't set headers, so the REST nonce rides as a query param.
		window.addEventListener( 'pagehide', () => {
			if ( localNetTimer ) localNetWrite();
			const ed = state.editor;
			if ( ed && ed.id && ed.lockState === 'held' && navigator.sendBeacon ) {
				navigator.sendBeacon( `${ B.restUrl }minn-admin/v1/posts/${ ed.id }/unlock?_wpnonce=${ encodeURIComponent( B.nonce ) }`, '' );
			}
		} );

		// Capture phase so structural toast-Undo wins over contenteditable's
		// native undo (island delete never entered the browser undo stack).
		window.addEventListener( 'keydown', ( e ) => {
			if ( ( e.metaKey || e.ctrlKey ) && ! e.shiftKey && ! e.altKey && e.key.toLowerCase() === 'z' ) {
				if ( runPendingToastUndo() ) {
					e.preventDefault();
					e.stopPropagation();
				}
			}
		}, true );

		// Capture-phase ⌘S: island live fields (shortcode/details/buttons)
		// stopPropagation on keydown so body handlers don't see typing — that
		// also blocked the bubble-phase save handler and let Chrome's
		// "Save Page As…" win. Capture runs before the target, so save always
		// reaches us and preventDefault kills the browser dialog.
		window.addEventListener( 'keydown', ( e ) => {
			if ( ( e.metaKey || e.ctrlKey ) && ! e.shiftKey && ! e.altKey && e.key.toLowerCase() === 's' && state.route === 'editor' && state.editor ) {
				e.preventDefault();
				e.stopPropagation();
				const ed = state.editor;
				clearAutosaveTimers();
				// Commit the focused live-field island before serialize.
				const t = e.target;
				if ( t && t.closest ) {
					const sc = t.closest( '.minn-shortcode-input' );
					if ( sc ) commitShortcodeInput( sc );
					else {
						const det = t.closest( '.minn-details-summary, .minn-details-body' );
						if ( det ) {
							const island = det.closest( '.minn-details-island' );
							if ( island ) commitDetailsIsland( island );
						} else {
							const btn = t.closest( '.minn-btn-label, .minn-btn-url, .minn-btn-newtab, .minn-btn-outline' );
							if ( btn ) {
								const island = btn.closest( '.minn-buttons-island' );
								if ( island ) commitButtonsIsland( island );
							}
						}
					}
				}
				saveEditor( { _explicit: true } ).then( () => {
					if ( state.editor === ed && ! ed.dirty ) {
						toast( LIVE_STATUSES.includes( ed.status ) ? 'Updated' : 'Draft saved' );
					}
				} );
			}
		}, true );

		window.addEventListener( 'keydown', ( e ) => {
			if ( ( e.metaKey || e.ctrlKey ) && e.key.toLowerCase() === 'k' ) {
				e.preventDefault();
				// In the editor with text selected (or the caret in a link), ⌘K
				// means link — muscle memory from every other editor. The
				// command palette keeps ⌘K everywhere else.
				const ebody = $( '#minn-editor-body' );
				const esel = window.getSelection();
				if ( state.route === 'editor' && ebody && esel.rangeCount && ebody.contains( esel.anchorNode ) ) {
					let n = esel.anchorNode;
					while ( n && n.nodeType !== Node.ELEMENT_NODE ) n = n.parentNode;
					const a = n && n.closest ? n.closest( 'a' ) : null;
					if ( a && ebody.contains( a ) && ! a.closest( '.minn-block-island' ) ) return openLinkPop( a );
					if ( ! esel.isCollapsed ) return openLinkPop( null, esel.getRangeAt( 0 ) );
				}
				state.paletteOpen = ! state.paletteOpen;
				renderOverlays();
			}
			// ⌘. toggles the navigation (⌘\ works too, but 1Password's Quick
			// Access owns ⌘\ at the OS level for many users, and ⌘B is bold in
			// an editor). Routed through the edge tab: one source of truth for
			// the class + localStorage persistence.
			if ( ( e.metaKey || e.ctrlKey ) && ! e.shiftKey && ( e.key === '.' || e.key === '\\' ) ) {
				e.preventDefault();
				const tab = $( '#minn-nav-tab' );
				if ( tab ) tab.click();
			}
			// ⌘⇧D toggles focus mode — classic WP's distraction-free lineage.
			if ( ( e.metaKey || e.ctrlKey ) && ! e.shiftKey && ! e.altKey && e.key === '/' && state.route === 'editor' && state.editor ) {
				e.preventDefault();
				openBlockPicker( null );
				return;
			}
			// ⌘⇧F finds within the post (the VS Code advanced-find gesture;
			// fits the ⌘⇧D / ⌘⇧O family). Plain ⌘F deliberately stays the
			// BROWSER'S — developers reach for native find constantly, and
			// Minn's bar covers what it can't (count, step, replace, ignore
			// sidebar chrome). Locked mode falls through to native find too.
			if ( ( e.metaKey || e.ctrlKey ) && e.shiftKey && ! e.altKey && e.key.toLowerCase() === 'f' && state.route === 'editor' && state.editor && state.editor.mode !== 'locked' ) {
				e.preventDefault();
				openFindBar();
				return;
			}
			if ( ( e.metaKey || e.ctrlKey ) && e.shiftKey && ! e.altKey && e.key.toLowerCase() === 'd' && state.route === 'editor' && state.editor ) {
				e.preventDefault();
				toggleFocusMode();
			}
			// ⌘⇧O toggles outline mode — the writing plus the document's shape.
			if ( ( e.metaKey || e.ctrlKey ) && e.shiftKey && ! e.altKey && e.key.toLowerCase() === 'o' && state.route === 'editor' && state.editor ) {
				e.preventDefault();
				toggleOutlineMode();
			}
			// ⌘⏎ publishes/updates/schedules — the writing-tool standard.
			if ( ( e.metaKey || e.ctrlKey ) && e.key === 'Enter' && state.route === 'editor' && state.editor ) {
				const pubBtn = $( '#minn-publish-btn' );
				if ( pubBtn && ! pubBtn.disabled ) {
					e.preventDefault();
					pubBtn.click();
				}
			}
			// ←/→ steps through media or surface-detail items (GF entries,
			// activity events, etc.). Skip when typing in a field so arrow
			// keys keep moving the caret.
			if ( state.modal && ( e.key === 'ArrowLeft' || e.key === 'ArrowRight' ) ) {
				const t = e.target;
				const typing = t && ( t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable );
				if ( ! typing ) {
					const dir = e.key === 'ArrowLeft' ? -1 : 1;
					if ( state.modal.type === 'media' ) { e.preventDefault(); mediaModalNav( dir ); }
					else if ( state.modal.type === 'surface' ) { e.preventDefault(); surfaceModalNav( dir ); }
					else if ( state.modal.type === 'revision' ) { e.preventDefault(); revisionModalNav( dir ); }
				}
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
		// Admin-notice digest: when the last capture is stale, trigger a
		// hidden wp-admin pageload that Minn short-circuits into structured
		// notice data (never third-party HTML), then refresh notifications.
		if ( B.notices && B.notices.stale ) {
			fetch( B.notices.url, { credentials: 'same-origin' } )
				.then( ( r ) => ( r.ok ? r.json() : null ) )
				.then( ( d ) => {
					if ( ! d || ! d.ok ) return;
					state.cache.notifications = null;
					loadNotifications().then( () => state.notifOpen && renderOverlays() );
				} )
				.catch( () => {} );
		}
		if ( B.caps.plugins ) {
			loadPlugins().catch( () => {} );
		}
		// Core-update chip in the topbar — visible on every route while an
		// update pends. wp_version_check self-throttles, so this is cheap.
		if ( B.caps.core ) loadCoreStatus().catch( () => {} );
		refreshCommentBadge();
		loadTypes().catch( () => {} );
		if ( B.wc && B.caps.orders ) loadOrderSummary().catch( () => {} );
		// Warm the content cache so the sidebar count appears.
		if ( state.route !== 'content' ) loadContent().catch( () => {} );

		// Drag & drop upload from anywhere in the app. When an install modal
		// (Add plugin / Add theme) is open, its dropzone owns EVERY drop: a
		// zip aimed at the modal but landing a few pixels outside it must
		// never end up in the media library (Austin's wp-rocket_3.23 repro),
		// and the "Drop files to upload" veil stays hidden so the modal's own
		// zone is the only affordance. The zones expose their upload path as
		// zone._accept (the chips' _target/_kind convention).
		if ( B.caps.upload ) {
			const installDropZone = () => {
				const z = $( '#minn-pi-dropzone' ) || $( '#minn-ti-dropzone' );
				return z && z._accept ? z : null;
			};
			let dragDepth = 0;
			window.addEventListener( 'dragenter', ( e ) => {
				if ( e.dataTransfer && Array.from( e.dataTransfer.types ).includes( 'Files' ) ) {
					if ( installDropZone() ) return;
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
				if ( ! files.length ) return;
				const zone = installDropZone();
				if ( zone ) {
					zone._accept( files[ 0 ] );
					return;
				}
				if ( state.route !== 'media' ) go( 'media' );
				uploadFiles( files );
			} );
		}
	}

	boot();
}() );
