/**
 * Static demo replay: boots the real app.js from a recording, with no
 * WordPress behind it. Loads after window.MINN_DEMO (the recording, from
 * data.js) and window.MINN_DEMO_BASE (the bundle's URL), before app.js.
 *
 * The REST root sits under the demo's own path, so a request that ever got
 * past the fetch stand-in would 404 on the host site rather than reach its
 * real API. Reads answer from the recording (exact URL first, then the same
 * route with any query); writes answer with a refusal the app shows as an
 * ordinary error. Links into the recorded site (wp-admin, front-end pages)
 * point at example.com after scrubbing, so clicks on them stay in the page.
 */
( function () {
	'use strict';

	var D = window.MINN_DEMO;
	var base = window.MINN_DEMO_BASE || './';
	var here = location.origin + location.pathname.replace( /\/?$/, '/' );
	var uploads = base + 'uploads';
	var fill = function ( s ) {
		return s.split( '{{uploads}}' ).join( uploads );
	};

	var B = JSON.parse( fill( JSON.stringify( D.boot ) ) );
	B.appUrl = here;
	B.restUrl = here + 'wp-json/';
	B.ajaxUrl = here + 'wp-admin/admin-ajax.php';
	B.progressUrl = here + 'progress.php';
	B.pretty = false;
	B.nonce = 'demo';
	window.MINN = B;

	var exact = {};
	var byRoute = {};
	Object.keys( D.responses ).forEach( function ( key ) {
		var r = D.responses[ key ];
		exact[ key ] = r;
		var route = key.split( '?' )[ 0 ];
		if ( ! byRoute[ route ] ) byRoute[ route ] = r;
	} );

	var reply = function ( status, body, headers ) {
		var h = { 'Content-Type': 'application/json' };
		Object.keys( headers || {} ).forEach( function ( name ) {
			h[ name ] = headers[ name ];
		} );
		return Promise.resolve( new Response( body, { status: status, headers: h } ) );
	};
	var refuse = function ( code, message ) {
		return reply( 'minn_demo' === code ? 403 : 404, JSON.stringify( { code: code, message: message, data: { status: 'minn_demo' === code ? 403 : 404 } } ) );
	};

	var realFetch = window.fetch.bind( window );
	window.fetch = function ( input, init ) {
		var url = typeof input === 'string' ? input : ( input && input.url ) || String( input );
		var abs = new URL( url, location.href ).href;
		if ( abs.indexOf( here ) !== 0 ) return realFetch( input, init );
		var method = String( ( init && init.method ) || ( input && input.method ) || 'GET' ).toUpperCase();
		if ( 'GET' !== method && 'HEAD' !== method ) {
			return refuse( 'minn_demo', 'This is a demo, so nothing is saved.' );
		}
		var key = null;
		if ( abs.indexOf( B.restUrl ) === 0 ) key = 'rest:' + abs.slice( B.restUrl.length );
		else if ( abs.indexOf( B.ajaxUrl ) === 0 ) key = 'ajax:' + abs.slice( B.ajaxUrl.length );
		var hit = key && ( exact[ key ] || byRoute[ key.split( '?' )[ 0 ] ] );
		if ( ! hit ) return refuse( 'minn_demo_missing', 'This view is not part of the demo.' );
		return reply( hit.status, fill( hit.body ), hit.headers );
	};

	// Links into the recorded site lead nowhere real: say where they go instead.
	var note = null;
	var say = function ( text ) {
		if ( ! note ) {
			note = document.createElement( 'div' );
			note.setAttribute( 'role', 'status' );
			note.style.cssText = 'position:fixed;left:50%;bottom:24px;transform:translateX(-50%);z-index:2147483647;max-width:min(520px,calc(100vw - 32px));padding:10px 16px;border-radius:10px;background:var(--panel,#1c1c22);color:var(--text,#fff);border:1px solid var(--border,#333);font:500 14px/1.4 system-ui,sans-serif;box-shadow:0 8px 30px rgba(0,0,0,.3);transition:opacity .2s';
			document.body.appendChild( note );
		}
		note.textContent = text;
		note.style.opacity = '1';
		clearTimeout( say.t );
		say.t = setTimeout( function () {
			note.style.opacity = '0';
		}, 2600 );
	};
	var outside = function ( href ) {
		try {
			var u = new URL( href, location.href );
			return 'example.com' === u.host ? u : null;
		} catch ( e ) {
			return null;
		}
	};
	var explain = function ( u ) {
		say( /^\/wp-admin\//.test( u.pathname ) ? 'In your site, this opens wp-admin. The demo stays in Minn.' : 'In your site, this opens the page itself. The demo stays in Minn.' );
	};
	document.addEventListener( 'click', function ( e ) {
		var a = e.target.closest && e.target.closest( 'a[href]' );
		var u = a && outside( a.getAttribute( 'href' ) );
		if ( u ) {
			e.preventDefault();
			e.stopPropagation();
			explain( u );
		}
	}, true );
	var realOpen = window.open;
	window.open = function ( href ) {
		var u = href && outside( href );
		if ( u ) {
			explain( u );
			return null;
		}
		return realOpen.apply( window, arguments );
	};
} )();
