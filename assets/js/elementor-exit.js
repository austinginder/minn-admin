/**
 * Elementor editor: add "Exit to Minn Admin" next to "Exit to WordPress".
 *
 * The top-bar hamburger is Elementor's v2 app-bar (`mainMenu.registerLink`).
 * The older panel hamburger is PanelMenu.addItem. Both destinations are the
 * same PHP-built URL. The app-bar Link control always renders its icon as a
 * React component, so a missing icon throws instead of drawing the row.
 */
( function () {
	var cfg = window.MINN_ELEMENTOR_EXIT;
	if ( ! cfg || ! cfg.url ) {
		return;
	}

	function icon() {
		var React = window.React;
		if ( ! React ) {
			return function () { return null; };
		}
		return function MinnMark() {
			return React.createElement(
				'span',
				{
					'aria-hidden': 'true',
					style: {
						display: 'inline-flex',
						alignItems: 'center',
						justifyContent: 'center',
						width: 18,
						height: 18,
						fontWeight: 700,
						fontSize: 13,
						lineHeight: 1,
						letterSpacing: '-0.04em',
					},
				},
				'm'
			);
		};
	}

	function registerAppBar() {
		var bar = window.elementorV2 && window.elementorV2.editorAppBar;
		if ( ! bar || ! bar.mainMenu || typeof bar.mainMenu.registerLink !== 'function' ) {
			return false;
		}
		if ( bar.mainMenu._minnExit ) {
			return true;
		}
		bar.mainMenu._minnExit = true;
		bar.mainMenu.registerLink( {
			id: 'exit-to-minn-admin',
			group: 'exits',
			priority: ( cfg.prefer === true || cfg.prefer === 1 || cfg.prefer === '1' ) ? 15 : 25,
			useProps: function () {
				return {
					title: cfg.title,
					href: cfg.url,
					icon: icon(),
				};
			},
		} );
		return true;
	}

	function registerPanel() {
		try {
			var menu = window.elementor.getPanelView().getPages( 'menu' ).view.constructor;
			if ( ! menu || typeof menu.addItem !== 'function' || menu._minnExit ) {
				return;
			}
			menu._minnExit = true;
			menu.addItem(
				{
					name: 'exit-minn',
					icon: 'eicon-exit',
					title: cfg.title,
					type: 'link',
					link: cfg.url,
				},
				'navigate_from_page'
			);
		} catch ( e ) {
			// Panel pages are not mounted until preview:loaded.
		}
	}

	if ( ! registerAppBar() ) {
		document.addEventListener( 'DOMContentLoaded', registerAppBar );
	}

	function onElementorReady() {
		if ( ! window.elementor || typeof window.elementor.on !== 'function' ) {
			return;
		}
		window.elementor.on( 'preview:loaded', registerPanel );
		registerPanel();
	}
	if ( window.elementor ) {
		onElementorReady();
	} else if ( window.jQuery ) {
		window.jQuery( window ).on( 'elementor:init', onElementorReady );
	}
} )();
