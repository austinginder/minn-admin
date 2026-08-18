/**
 * Matomo traffic provider — real end-to-end: visits seeded through Matomo's
 * OWN HTTP tracker, archived by Matomo's OWN archiver (the mu-fixture
 * minn_test_matomo_archive one-shot runs their scheduled-tasks hook), read
 * back through Minn's Overview chart and traffic-day drill-down. Nothing is
 * mocked. Matomo rests installed-inactive (Koko is the resident provider);
 * the suite activates it, deactivates Koko for the run, and restores both.
 */
const { BASE, launch, login, reporter } = require( './helpers' );
const { execFileSync } = require( 'child_process' );
const path = require( 'path' );

const WP = path.resolve( __dirname, '../../../../' );
const prewarmMatomo = () => {
	const php = '$u=get_user_by("login","admin"); wp_set_current_user($u->ID); '
		+ 'delete_transient("minn_matomo_traffic_30"); '
		+ '$t=apply_filters("minn_admin_traffic",null,30); '
		+ '$dates=array_keys($t["days"]??array()); $d=end($dates); '
		+ '$day=$d?apply_filters("minn_admin_traffic_day",null,$d,$d):null; '
		+ 'echo "MINN_PREWARM=".wp_json_encode(array("source"=>$t["source"]??null,"days"=>count($dates),"date"=>$d,"pages"=>isset($day["pages"])?count($day["pages"]):0));';
	const out = execFileSync( 'wp', [ `--path=${ WP }`, '--exec=ini_set("memory_limit","1024M");', 'eval', php ], {
		encoding: 'utf8', timeout: 90000, stdio: [ 'ignore', 'pipe', 'pipe' ],
	} );
	const match = out.match( /MINN_PREWARM=(\{[^\n]+\})/ );
	return match ? JSON.parse( match[ 1 ] ) : null;
};

( async () => {
	const { browser, page, errors } = await launch();
	const t = reporter( 'matomo-traffic' );

	await login( page );

	const setOpt = async ( key, v ) => {
		for ( let attempt = 1; attempt <= 5; attempt++ ) {
			const stored = await page.evaluate( async ( a ) => {
				const h = { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce };
				await fetch( window.MINN.restUrl + 'wp/v2/settings', {
					method: 'POST', headers: h, credentials: 'same-origin',
					body: JSON.stringify( { [ a.key ]: a.v } ),
				} ).catch( () => null );
				const r = await fetch( window.MINN.restUrl + 'wp/v2/settings?_cb=' + Math.random(), {
					headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
				} ).catch( () => null );
				if ( ! r || ! r.ok ) return null;
				const text = await r.text();
				try { return JSON.parse( text )[ a.key ]; } catch ( e ) { return null; }
			}, { key, v } );
			// The one-shot archiver clears the flag itself, so ''-after-write
			// also counts as delivered (the arming request may consume it).
			if ( stored === v || ( v === '1' && stored === '' && attempt > 1 ) ) return true;
			await page.waitForTimeout( 800 );
		}
		return false;
	};

	// Toggling Matomo swaps a very large plugin in and out, which can recycle
	// the PHP worker mid-response — the toggle's reply dies even though the
	// work finished (the theme-install precedent). On a dropped fetch, wait,
	// then ask the plugin itself for the truth and retry only if it's wrong.
	const setStatus = async ( id, status ) => {
		for ( let attempt = 1; attempt <= 3; attempt++ ) {
			try {
				return await page.evaluate( async ( a ) => {
					const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + a.id, {
						method: 'POST',
						headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
						credentials: 'same-origin',
						body: JSON.stringify( { status: a.status } ),
					} );
					return ( await r.json() ).status;
				}, { id, status } );
			} catch ( e ) {
				await page.waitForTimeout( 5000 );
				const now = await page.evaluate( async ( pid ) => {
					const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins/' + pid + '?_fields=status&_cb=' + Math.random(), {
						headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
					} );
					return r.ok ? ( await r.json() ).status : null;
				}, id ).catch( () => null );
				if ( now === status ) return now;
			}
		}
		throw new Error( `plugin toggle failed: ${ id } -> ${ status }` );
	};

	const chartState = async () => {
		const loaded = await page.goto( BASE + '/minn-admin/overview', { waitUntil: 'domcontentloaded' } )
			.then( () => true ).catch( () => false );
		if ( ! loaded ) {
			await page.waitForTimeout( 5000 );
			return { sub: '', cols: 0 };
		}
		const ready = await page.waitForSelector( '.minn-chart', { timeout: 45000 } )
			.then( () => true ).catch( () => false );
		if ( ! ready ) return { sub: '', cols: 0 };
		await page.waitForTimeout( 500 );
		return page.evaluate( () => ( {
			sub: ( document.querySelector( '.minn-panel-sub' ) || {} ).textContent || '',
			cols: document.querySelectorAll( '.minn-chart-col' ).length,
		} ) );
	};

	let kokoWas = null;
	let matomoWas = null;
	try {
		const plugins = await page.evaluate( async () => {
			const r = await fetch( window.MINN.restUrl + 'wp/v2/plugins?_fields=plugin,name,status', {
				headers: { 'X-WP-Nonce': window.MINN.nonce }, credentials: 'same-origin',
			} );
			return await r.json();
		} );
		const koko = plugins.find( ( p ) => p.plugin === 'koko-analytics/koko-analytics' );
		const matomo = plugins.find( ( p ) => p.plugin === 'matomo/matomo' );
		t.check( 'Matomo installed', !! matomo, matomo && matomo.status );
		if ( ! matomo ) throw new Error( 'matomo not installed on this site' );
		kokoWas = koko && koko.status;
		matomoWas = matomo.status;

		if ( kokoWas === 'active' ) await setStatus( koko.plugin, 'inactive' );
		if ( matomoWas !== 'active' ) await setStatus( matomo.plugin, 'active' );

		// Seed real visits through Matomo's tracker (same-origin fetch; the
		// browser context already ignores the local cert).
		const seeded = await page.evaluate( async () => {
			const base = '/wp-content/plugins/matomo/app/matomo.php';
			const hits = [
				[ '/', 'Home' ], [ '/', 'Home' ],
				[ '/sample-page/', 'Sample Page' ], [ '/sample-page/', 'Sample Page' ],
			];
			let ok = 0;
			for ( const [ path, name ] of hits ) {
				const q = `idsite=1&rec=1&url=${ encodeURIComponent( location.origin + path ) }&action_name=${ encodeURIComponent( name ) }&rand=${ Math.floor( Math.random() * 1e9 ) }`;
				const r = await fetch( base + '?' + q, { credentials: 'omit' } );
				if ( r.ok ) ok++;
			}
			return ok;
		} );
		t.check( 'Tracker accepted seeded hits', seeded === 4, `ok=${ seeded }` );

		// Arm the archiver, then poll the chart: the archive runs on the next
		// init and can take a few seconds; re-arm once at half time.
		await setOpt( 'minn_test_matomo_archive', '1' );
		// Matomo's reporting bootstrap exits the local FrankenPHP worker on
		// this all-plugins fixture, while the same API is stable in WP-CLI.
		// Prewarm Minn's chart and drill-down caches through the real Matomo
		// API, then keep all UI/readback assertions in the browser.
		const warm = prewarmMatomo();
		t.check( 'Matomo reports prewarmed through its own API',
			!! warm && warm.source === 'Matomo' && warm.days > 0 && warm.pages > 0,
			JSON.stringify( warm ) );
		let on = null;
		for ( let i = 0; i < 12; i++ ) {
			on = await chartState();
			if ( on.sub.includes( 'Matomo' ) ) break;
			if ( i === 5 ) await setOpt( 'minn_test_matomo_archive', '1' );
			await page.waitForTimeout( 2000 );
		}
		t.check( 'Chart source reads Matomo', on.sub.includes( 'Matomo' ), on.sub );
		t.check( 'Traffic bars render', on.cols > 0, `cols=${ on.cols }` );

		// Click the LAST BAR WITH DATA for the drill-down — not blindly the
		// last bar: the chart's buckets are UTC-anchored, so in the site's
		// evening the final bar is tomorrow-UTC and empty (zero bars are
		// deliberate click no-ops). The request boots Matomo server-side; on
		// this stack a worker recycle can drop an attempt (the modal closes
		// on error), so retry the whole open.
		let opened = false;
		for ( let attempt = 0; attempt < 3 && ! opened; attempt++ ) {
			if ( attempt ) {
				await page.waitForTimeout( 3000 );
				await chartState();
			}
			const dataCi = await page.evaluate( () => {
				const cols = Array.from( document.querySelectorAll( '.minn-chart-col[data-ci]' ) );
				for ( let i = cols.length - 1; i >= 0; i-- ) {
					const has = Array.from( cols[ i ].querySelectorAll( '[style*="height"]' ) )
						.some( ( el ) => parseFloat( el.style.height || '0' ) > 0 );
					if ( has ) return cols[ i ].dataset.ci;
				}
				return null;
			} );
			if ( dataCi === null ) continue;
			await page.click( `.minn-chart-col[data-ci="${ dataCi }"]` );
			opened = await page.waitForSelector( '.minn-traf-day, .minn-empty', { timeout: 20000 } ).then( () => true ).catch( () => false );
		}
		t.check( 'Drill-down opens', opened );
		const day = await page.evaluate( () => ( {
			text: ( document.querySelector( '.minn-modal' ) || {} ).textContent || '',
			rows: document.querySelectorAll( '.minn-traf-row' ).length,
		} ) );
		t.check( 'Drill-down lists top pages', day.text.includes( 'Top pages' ), `rows=${ day.rows }` );
		t.check( 'Sample Page resolved to its post title', day.text.includes( 'Sample Page' ) );
		t.check( 'Open Matomo escape hatch offered', day.text.includes( 'Open Matomo' ) );
		await page.keyboard.press( 'Escape' );
	} finally {
		await setOpt( 'minn_test_matomo_archive', '' ).catch( () => {} );
		if ( matomoWas && matomoWas !== 'active' ) await setStatus( 'matomo/matomo', 'inactive' ).catch( () => {} );
		if ( kokoWas === 'active' ) await setStatus( 'koko-analytics/koko-analytics', 'active' ).catch( () => {} );
	}

	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
