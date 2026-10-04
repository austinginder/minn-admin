/**
 * Gravity Forms form builder (/gravity-forms/form/{id}).
 *
 * A new form from the Forms view lands on its builder; palette clicks add
 * fields and focus their label; choices, values, width, Name parts and
 * conditional logic edit in the panel; a grip drag reorders; one Save goes
 * through Gravity Forms' own editor save and everything reads back from
 * their storage. Also: the unsaved-changes guard, removing a stored field
 * (its answers go with it, as in their editor), the refusal when the form
 * changed elsewhere, and Gravity Forms' own front-end render of the result.
 *
 * Fixtures: none standing. The suite creates its own form through the UI
 * and deletes it in finally.
 */
const { execSync } = require( 'child_process' );
const fs = require( 'fs' );
const os = require( 'os' );
const path = require( 'path' );
const { BASE, launch, login, reporter } = require( './helpers' );

const WP_PATH = path.resolve( __dirname, '../../../..' );
// PHP rides a file (eval-file), never the command line: the shell would
// expand its $variables.
const wpEval = ( php ) => {
	const file = path.join( os.tmpdir(), `minn-gf-builder-${ process.pid }.php` );
	fs.writeFileSync( file, '<?php ' + php );
	try {
		return execSync( `wp --path=${ JSON.stringify( WP_PATH ) } eval-file ${ JSON.stringify( file ) } 2>/dev/null`, { encoding: 'utf8', timeout: 90000 } ).trim();
	} finally {
		fs.unlinkSync( file );
	}
};

( async () => {
	const t = reporter( 'gf-builder' );
	const { browser, page, errors } = await launch();
	await login( page );
	// The builder asks before unloading with unsaved changes; reloads in
	// this suite mean to go.
	page.on( 'dialog', ( d ) => d.accept().catch( () => {} ) );

	const rest = ( route, opts = {} ) => page.evaluate( async ( a ) => {
		const r = await fetch( window.MINN.restUrl + a.route, {
			method: a.method || 'GET',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': window.MINN.nonce },
			...( a.body ? { body: JSON.stringify( a.body ) } : {} ),
		} );
		return { status: r.status, body: await r.json().catch( () => null ) };
	}, { route, ...opts } );
	const cells = () => page.$$eval( '.minn-gfb-cell', ( els ) => els.map( ( el ) => el.querySelector( '.minn-gfb-clabel' ).textContent.replace( /\*$/, '' ).trim() ) );
	const typeLabel = async ( text ) => {
		await page.waitForFunction( () => document.activeElement && document.activeElement.dataset.gf === 'label' );
		await page.keyboard.type( text );
	};
	const pickCombo = async ( sel, text ) => {
		await page.click( `${ sel } .minn-ac-input` );
		await page.keyboard.press( 'Meta+A' );
		await page.keyboard.type( text );
		await page.waitForTimeout( 150 );
		await page.keyboard.press( 'Enter' );
		await page.waitForTimeout( 250 );
	};
	const saveForm = async () => {
		await page.click( '#minn-gfb-save' );
		await page.waitForFunction( () => ! document.querySelector( '.minn-gfb .minn-fgb-dirty' ) && ! document.querySelector( '#minn-gfb-save[disabled]' ), null, { timeout: 60000 } );
		await page.waitForTimeout( 200 );
	};

	let formId = 0;
	try {
		// A new form from the Forms view opens in the builder.
		const title = 'Builder suite ' + Date.now();
		await page.goto( `${ BASE }/minn-admin/gravity-forms`, { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '[data-sview="manage"]', { timeout: 60000 } );
		await page.click( '[data-sview="manage"]' );
		await page.waitForSelector( '#minn-surface-add', { timeout: 30000 } );
		await page.click( '#minn-surface-add' );
		await page.waitForSelector( '[data-createfield="title"]' );
		await page.fill( '[data-createfield="title"]', title );
		await page.click( '#minn-surface-create' );
		await page.waitForURL( /\/gravity-forms\/form\/\d+/, { timeout: 60000 } );
		formId = Number( page.url().match( /form\/(\d+)/ )[ 1 ] );
		await page.waitForSelector( '.minn-gfb-empty', { timeout: 60000 } );
		t.check( 'a new form opens on its builder', formId > 0 && await page.$eval( '#minn-gfb-title', ( el ) => el.value ) === title );
		t.check( 'the sidebar keeps Forms lit', !! await page.$( '.minn-nav-btn.active[data-nav="gravity-forms"], .minn-nav-btn.active[data-family="forms"]' ) );

		// Palette clicks add a field at the end and put the cursor in its label.
		await page.click( '[data-gfbadd="text"]' );
		await typeLabel( 'Full name' );
		t.check( 'typing the label shows on the form as you type', ( await cells() )[ 0 ] === 'Full name', JSON.stringify( await cells() ) );
		await page.click( '[data-gfw="6"]' );
		await page.click( '[data-gfbclose]' );

		await page.click( '[data-gfbadd="select"]' );
		await typeLabel( 'Topic' );
		await page.fill( '[data-ctext="0"]', 'Hosting' );
		await page.click( '[data-gfsw="enableChoiceValue"]' );
		await page.waitForSelector( '[data-cval="0"]' );
		await page.fill( '[data-cval="0"]', 'hosting' );
		await page.click( '[data-cadd]' );
		await page.keyboard.type( 'Security' );
		t.check( 'a choice added in the panel shows on the field', ( await page.$$( '#minn-gfb-panel [data-ctext]' ) ).length === 4 );
		await page.click( '[data-cdef="1"]' );
		await page.click( '[data-gfbclose]' );

		await page.click( '[data-gfbadd="email"]' );
		await typeLabel( 'Work email' );
		await page.click( '[data-gfw="6"]' );
		await page.click( '[data-gflogic]' );
		await page.waitForSelector( '[data-glf="0"]' );
		await pickCombo( '[data-glf="0"]', 'Topic' );
		t.check( 'a rule on a choice field offers its choices as values', !! await page.$( '[data-glv="0"]' ) && await page.$eval( '[data-glv="0"] .minn-ac-input', ( el ) => el.value ) === 'Hosting' );
		await page.click( '[data-gfbclose]' );

		await page.click( '[data-gfbadd="checkbox"]' );
		await typeLabel( 'Interests' );
		await page.click( '[data-gfbclose]' );

		await page.click( '[data-gfbadd="name"]' );
		await typeLabel( 'Your name' );
		const middleOn = await page.$eval( '[data-gsubsw="2"]', ( el ) => el.classList.contains( 'on' ) );
		await page.click( '[data-gsubsw="2"]' );
		t.check( 'Name parts toggle in the panel', ! middleOn && await page.$eval( '[data-gsubsw="2"]', ( el ) => el.classList.contains( 'on' ) ) );
		await page.click( '[data-gfbclose]' );

		t.check( 'five fields on the canvas in add order', JSON.stringify( await cells() ) === JSON.stringify( [ 'Full name', 'Topic', 'Work email', 'Interests', 'Your name' ] ), JSON.stringify( await cells() ) );
		t.check( 'half-width fields share a row', await page.$$eval( '.minn-gfb-cell', ( els ) => Math.abs( els[ 0 ].getBoundingClientRect().width - els[ 2 ].getBoundingClientRect().width ) < 2 && els[ 0 ].getBoundingClientRect().width < els[ 1 ].getBoundingClientRect().width * 0.6 ) );

		// Drag Work email by its grip onto the left half of Full name.
		const grip = await page.locator( '.minn-gfb-cell' ).nth( 2 ).locator( '.minn-gfb-grip' );
		await page.locator( '.minn-gfb-cell' ).nth( 2 ).hover();
		const g = await grip.boundingBox();
		const target = await page.locator( '.minn-gfb-cell' ).nth( 0 ).boundingBox();
		const sx = g.x + g.width / 2, sy = g.y + g.height / 2;
		const tx = target.x + 20, ty = target.y + target.height / 2;
		await page.mouse.move( sx, sy );
		await page.mouse.down();
		for ( let k = 1; k <= 14; k++ ) {
			await page.mouse.move( sx + ( tx - sx ) * k / 14, sy + ( ty - sy ) * k / 14 );
			await page.waitForTimeout( 25 );
		}
		await page.mouse.up();
		await page.waitForTimeout( 400 );
		t.check( 'dragging a field before another reorders the form', ( await cells() ).slice( 0, 2 ).join( '|' ) === 'Work email|Full name', JSON.stringify( await cells() ) );
		t.check( 'unsaved changes are flagged', !! await page.$( '.minn-gfb .minn-fgb-dirty' ) );

		await saveForm();
		let b = ( await rest( `minn-admin/v1/gf/forms/${ formId }/builder` ) ).body;
		const byLabel = ( l ) => b.fields.find( ( f ) => f.label === l );
		t.check( 'the saved order matches the canvas', b.fields.map( ( f ) => f.label ).join( '|' ) === 'Work email|Full name|Topic|Interests|Your name', b.fields.map( ( f ) => f.label ).join( '|' ) );
		t.check( 'widths saved', byLabel( 'Work email' ).span === 6 && byLabel( 'Full name' ).span === 6 && byLabel( 'Topic' ).span === 12 );
		const topic = byLabel( 'Topic' );
		t.check( 'choices, values and the default saved', topic.enableChoiceValue && topic.choices[ 0 ].text === 'Hosting' && topic.choices[ 0 ].value === 'hosting' && topic.choices[ 3 ].text === 'Security' && topic.choices[ 3 ].value === 'Security' && topic.choices[ 1 ].isSelected && ! topic.choices[ 0 ].isSelected, JSON.stringify( topic.choices ) );
		const cl = byLabel( 'Work email' ).conditionalLogic;
		t.check( 'the rule points at the new Topic field by its saved id', cl && cl.rules.length === 1 && cl.rules[ 0 ].fieldId === String( topic.id ) && cl.rules[ 0 ].value === 'hosting', JSON.stringify( cl ) );
		const interests = byLabel( 'Interests' );
		t.check( 'checkboxes got one input per choice', interests.inputs && interests.inputs.length === 3 && interests.inputs[ 0 ].id === interests.id + '.1', JSON.stringify( interests.inputs ) );
		const nm = byLabel( 'Your name' );
		t.check( 'the Name field kept its parts with Middle shown', nm.inputs && nm.inputs.find( ( i ) => /\.4$/.test( i.id ) ).isHidden === false && nm.inputs.find( ( i ) => /\.2$/.test( i.id ) ).isHidden === true );

		// Gravity Forms renders what was built.
		const html = wpEval( `echo GFFormDisplay::get_form( ${ formId }, false, false );` );
		t.check( 'Gravity Forms renders the saved form', html.includes( 'Work email' ) && html.includes( 'gfield--width-half' ) && html.includes( 'value=\'hosting\'' ), html.slice( 0, 160 ) );

		// A reload reads the same form back.
		await page.reload( { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-gfb-cell', { timeout: 60000 } );
		t.check( 'a reload shows the saved form, nothing pending', ( await cells() ).length === 5 && ! await page.$( '.minn-gfb .minn-fgb-dirty' ) );
		t.check( 'the conditional field wears its chip', await page.$$eval( '.minn-gfb-cell', ( els ) => els[ 0 ].textContent.includes( 'Conditional' ) ) );

		// Leaving with unsaved changes asks first.
		await page.fill( '#minn-gfb-title', title + ' edited' );
		await page.click( '#minn-gfb-back' );
		await page.waitForSelector( '.minn-confirm-modal', { timeout: 5000 } );
		t.check( 'leaving with unsaved changes asks first', !! await page.$( '.minn-confirm-modal' ) );
		await page.keyboard.press( 'Escape' );
		await page.waitForTimeout( 300 );
		t.check( 'staying keeps the page and the edit', /gravity-forms\/form\//.test( page.url() ) && await page.$eval( '#minn-gfb-title', ( el ) => el.value ) === title + ' edited' );

		// Removing a stored field warns that its answers go with it.
		wpEval( `GFAPI::add_entry( array( 'form_id' => ${ formId }, '${ byLabel( 'Full name' ).id }' => 'Dana' ) );` );
		await page.reload( { waitUntil: 'domcontentloaded' } );
		await page.waitForSelector( '.minn-gfb-cell', { timeout: 60000 } );
		await page.locator( '.minn-gfb-cell' ).nth( 3 ).click();
		await page.click( '.minn-gfb-pfoot [data-gfbdel]' );
		await page.waitForSelector( '.minn-confirm-modal', { timeout: 5000 } );
		const warn = await page.$eval( '.minn-confirm-modal', ( el ) => el.textContent );
		t.check( 'the remove warning names the answers that go with it', /deletes “Interests” along with its answers in this form’s 1 entry/.test( warn ), warn.slice( 0, 200 ) );
		await page.click( '.minn-confirm-modal [data-ok]' );
		await page.waitForTimeout( 300 );
		t.check( 'the field leaves the canvas', ! ( await cells() ).includes( 'Interests' ) );
		await saveForm();
		b = ( await rest( `minn-admin/v1/gf/forms/${ formId }/builder` ) ).body;
		t.check( 'the removal saved, other fields untouched', b.fields.length === 4 && ! b.fields.some( ( f ) => f.label === 'Interests' ) && byLabel( 'Work email' ).conditionalLogic );

		// A field added elsewhere since the page loaded: refuse, don't delete it.
		wpEval( `$f = GFAPI::get_form( ${ formId } ); $f['fields'][] = array( 'id' => 50, 'type' => 'text', 'label' => 'Added elsewhere' ); GFAPI::update_form( $f );` );
		await page.locator( '.minn-gfb-cell' ).nth( 1 ).click();
		await page.fill( '#minn-gfb-panel [data-gf="label"]', 'Full name changed' );
		await page.click( '#minn-gfb-save' );
		await page.waitForTimeout( 2500 );
		b = ( await rest( `minn-admin/v1/gf/forms/${ formId }/builder` ) ).body;
		t.check( 'a form changed elsewhere refuses the save', b.fields.some( ( f ) => f.label === 'Added elsewhere' ) && b.fields.some( ( f ) => f.label === 'Full name' ) && !! await page.$( '.minn-gfb .minn-fgb-dirty' ) );

		// Only form editors reach the route.
		const anon = await page.evaluate( async ( u ) => ( await fetch( u, { credentials: 'omit' } ) ).status, `${ BASE }/wp-json/minn-admin/v1/gf/forms/${ formId }/builder` );
		t.check( 'anonymous requests are refused', 401 === anon, String( anon ) );
	} finally {
		if ( formId ) wpEval( `GFAPI::delete_form( ${ formId } );` );
	}

	await t.done( browser, errors );
} )();
