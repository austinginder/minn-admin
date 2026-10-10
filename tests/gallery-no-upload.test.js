/**
 * A gallery, image or file field is read-only for someone who cannot use the
 * media library (Remove stays: a clear needs no library).
 *
 * The save takes a gallery's pictures through the media-library gate (upload
 * rights) and keeps the stored set when it refuses, so the editor offered to a
 * Contributor reported "Gallery updated" and the change was dropped. Without
 * upload rights the field now shows its pictures and says why there is no
 * editor. The suite turns the boot payload's upload capability off in the
 * page (the render branch reads it there) rather than logging in as a
 * Contributor, and turns it back on for the control.
 *
 * Fixture: one draft, created and removed through the helpers.
 */
const { launch, login, reporter, createPost, deletePost, openEditor } = require( './helpers' );

( async () => {
	const t = reporter( 'gallery-no-upload' );
	const { browser, page, errors } = await launch();
	await login( page );
	let id = null;
	const galSel = '[data-pf$=":photo_gallery"][data-ftype="gallery"]';
	const imgSel = '[data-pf$=":slideshow_cover"][data-ftype="image"]';
	const openPanel = async () => {
		await page.waitForSelector( '[data-side-door="panel:acf"]', { timeout: 15000 } );
		await page.click( '[data-side-door="panel:acf"]' );
		await page.waitForSelector( galSel, { timeout: 15000 } );
		const gal = await page.$eval( galSel, ( el ) => ( { edit: !! el.querySelector( '[data-gal-edit]' ), note: ( el.textContent || '' ).trim() } ) );
		const img = await page.$eval( imgSel, ( el ) => ( { pick: !! el.querySelector( '[data-img-pick]' ), note: ( el.textContent || '' ).trim() } ) );
		return Object.assign( gal, { img } );
	};
	try {
		id = await createPost( page, { title: 'Gallery without uploads', content: '<p>x</p>', status: 'draft' } );
		await openEditor( page, id );
		await page.evaluate( () => { window.MINN.caps.upload = false; } );
		const off = await openPanel();
		t.check( 'without upload rights the gallery offers no editor', ! off.edit, JSON.stringify( off ) );
		t.check( '...and says why', /media library/i.test( off.note ), off.note );
		t.check( 'an image field offers no Set or Replace either, and says why', ! off.img.pick && /media library/i.test( off.img.note ), JSON.stringify( off.img ) );
		await page.keyboard.press( 'Escape' );
		await openEditor( page, id );
		await page.evaluate( () => { window.MINN.caps.upload = true; } );
		const on = await openPanel();
		t.check( 'with upload rights the editor is offered (control)', on.edit, JSON.stringify( on ) );
		t.check( '...and an image field offers Set image (control)', on.img.pick, JSON.stringify( on.img ) );
	} finally {
		if ( id ) await deletePost( page, id ).catch( () => null );
	}
	await t.done( browser, errors );
} )().catch( ( e ) => {
	console.error( e );
	process.exit( 1 );
} );
