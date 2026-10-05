// @ts-check
const { test, expect } = require( '@playwright/test' );
const { FIXTURE_WP, ADMIN_STATE, resetState, getState, interceptThirdParty } = require( './helpers' );

/**
 * Salvataggio impostazioni (DBCM_Admin::handle_save): dispatcher unico su
 * admin-post.php, schema per sezione, clamp e coerenze lato server.
 */

const BANNER_PAGE = '/wp-admin/admin.php?page=dbcm-banner';
const LOG_PAGE = '/wp-admin/admin.php?page=dbcm-log';
const ADVANCED_PAGE = '/wp-admin/admin.php?page=dbcm-advanced';

/**
 * Invia il form che contiene il campo indicato.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} field Selettore di un campo del form.
 */
async function submitFormOf( page, field ) {
	await page.locator( 'form', { has: page.locator( field ) } ).locator( 'button[type="submit"]' ).click();
	await page.waitForLoadState( 'domcontentloaded' );
}

/**
 * Toglie min/max a un campo numerico: simula una richiesta manipolata, per
 * verificare il clamp lato server invece della validazione del browser.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} field
 */
function dropNumberLimits( page, field ) {
	return page.locator( field ).evaluate( ( el ) => {
		el.removeAttribute( 'min' );
		el.removeAttribute( 'max' );
	} );
}

test.use( { storageState: ADMIN_STATE } );

test.beforeEach( async ( { request } ) => {
	await resetState( request );
} );

test.describe( 'Banner', () => {

	test( 'salvare l\'aspetto mostra la conferma e rende persistenti i valori', async ( { page, request } ) => {
		await page.goto( BANNER_PAGE );
		await page.locator( '#dbcm-banner_layout' ).selectOption( 'bar' );
		await page.locator( '#dbcm-banner_overlay' ).check();
		await page.locator( '#dbcm-show_reopen_btn' ).uncheck();
		await submitFormOf( page, '#dbcm-banner_layout' );

		await expect( page.locator( '.notice-success' ) ).toContainText( 'Impostazioni salvate.' );
		await expect( page.locator( '#dbcm-banner_layout' ) ).toHaveValue( 'bar' );

		const { settings } = await getState( request );
		expect( settings ).toMatchObject( { banner_layout: 'bar', banner_overlay: true, show_reopen_btn: false } );
	} );

	test( 'il form aspetto non tocca le impostazioni del form contenuto', async ( { page, request } ) => {
		await resetState( request, { settings: { consent_duration: 90 } } );
		await page.goto( BANNER_PAGE );
		await submitFormOf( page, '#dbcm-banner_layout' );

		expect( ( await getState( request ) ).settings.consent_duration ).toBe( 90 );
	} );

	for ( const [ input, expected ] of [ [ '900', 730 ], [ '0', 1 ] ] ) {
		test( `durata del consenso ${ input } → ${ expected } giorni (clamp lato server)`, async ( { page, request } ) => {
			await page.goto( BANNER_PAGE );
			await dropNumberLimits( page, '#dbcm-consent_duration' );
			await page.locator( '#dbcm-consent_duration' ).fill( input );
			await submitFormOf( page, '#dbcm-consent_duration' );

			expect( ( await getState( request ) ).settings.consent_duration ).toBe( expected );
		} );
	}

	test( 'la lingua predefinita resta fra quelle attive (regressione 3.8.1)', async ( { page, request } ) => {
		await page.goto( BANNER_PAGE );
		await page.locator( 'input[name="banner_languages[]"][value="it"]' ).uncheck();
		await page.locator( 'input[name="banner_languages[]"][value="en"]' ).check();
		await page.locator( '#dbcm-banner_default_lang' ).selectOption( 'it' );
		await submitFormOf( page, '#dbcm-banner_default_lang' );

		const { settings } = await getState( request );
		expect( settings.banner_languages ).toEqual( [ 'en' ] );
		expect( settings.banner_default_lang ).toBe( 'en' );
	} );

	test( 'il CSS personalizzato è salvato senza tag e applicato al sito', async ( { page, browser, request } ) => {
		await page.goto( BANNER_PAGE );
		await page.locator( '#dbcm-banner_custom_css' ).fill( '.dbcm-banner{outline:3px solid red}<script>alert(1)</script>' );
		await submitFormOf( page, '#dbcm-banner_custom_css' );

		const css = ( await getState( request ) ).settings.banner_custom_css;
		expect( css ).toContain( '.dbcm-banner{outline:3px solid red}' );
		expect( css ).not.toContain( '<script>' );

		// Frontend, visitatore anonimo.
		const visitor = await browser.newContext( { storageState: { cookies: [], origins: [] } } );
		const front = await visitor.newPage();
		await interceptThirdParty( front );
		const html = await ( await front.goto( FIXTURE_WP ) ).text();
		expect( html ).toContain( '.dbcm-banner{outline:3px solid red}' );
		await visitor.close();
	} );

	test( 'l\'id del nonce non è duplicato fra i due form (regressione 3.8.1)', async ( { page } ) => {
		await page.goto( BANNER_PAGE );
		await expect( page.locator( 'input[name="dbcm_settings_nonce"]' ) ).toHaveCount( 2 );
		await expect( page.locator( '#dbcm_settings_nonce' ) ).toHaveCount( 0 );
	} );

} );

test.describe( 'Registro consensi', () => {

	test( 'conservazione più breve della durata del consenso: portata alla durata, con avviso', async ( { page, request } ) => {
		await page.goto( LOG_PAGE );
		await page.locator( '#dbcm-consent_log_retention' ).fill( '30' );
		await submitFormOf( page, '#dbcm-consent_log_retention' );

		await expect( page.locator( '.notice-warning' ) ).toContainText( 'La conservazione del registro consensi è stata portata' );
		expect( ( await getState( request ) ).settings.consent_log_retention ).toBe( 365 );
	} );

	test( 'conservazione 0 (illimitata) è sempre accettata', async ( { page, request } ) => {
		await page.goto( LOG_PAGE );
		await page.locator( '#dbcm-consent_log_retention' ).fill( '0' );
		await submitFormOf( page, '#dbcm-consent_log_retention' );

		await expect( page.locator( '.notice-success' ) ).toContainText( 'Impostazioni salvate.' );
		expect( ( await getState( request ) ).settings.consent_log_retention ).toBe( 0 );
	} );

} );

test.describe( 'Avanzate', () => {

	test( 'Pixel ID non valido viene svuotato, valido viene salvato', async ( { page, request } ) => {
		await page.goto( ADVANCED_PAGE );
		await page.locator( '#dbcm-meta_pixel_id' ).fill( 'abc-123' );
		await submitFormOf( page, '#dbcm-meta_pixel_id' );
		expect( ( await getState( request ) ).settings.meta_pixel_id ).toBe( '' );

		await page.locator( '#dbcm-meta_pixel_id' ).fill( '1234 5678 9012 345' );
		await submitFormOf( page, '#dbcm-meta_pixel_id' );
		expect( ( await getState( request ) ).settings.meta_pixel_id ).toBe( '123456789012345' );
	} );

	test( 'una checkbox deselezionata viene salvata come disattivata', async ( { page, request } ) => {
		// Baseline: respect_gpc attivo, localize_google_fonts attivo.
		await page.goto( ADVANCED_PAGE );
		await page.locator( '#dbcm-respect_gpc' ).uncheck();
		await submitFormOf( page, '#dbcm-respect_gpc' );

		const { settings } = await getState( request );
		expect( settings.respect_gpc ).toBe( false );
		// Le altre checkbox del form restano come erano.
		expect( settings.localize_google_fonts ).toBe( true );
	} );

} );

test.describe( 'Sicurezza del dispatcher', () => {

	test( 'nonce non valido: 403, nulla salvato', async ( { page, request } ) => {
		await page.goto( BANNER_PAGE );
		await page.locator( 'form', { has: page.locator( '#dbcm-banner_layout' ) } )
			.locator( 'input[name="dbcm_settings_nonce"]' )
			.evaluate( ( el ) => { el.value = 'nonvalido'; } );
		await page.locator( '#dbcm-banner_layout' ).selectOption( 'bar' );

		const [ res ] = await Promise.all( [
			page.waitForResponse( ( r ) => r.url().includes( 'admin-post.php' ) ),
			page.locator( 'form', { has: page.locator( '#dbcm-banner_layout' ) } ).locator( 'button[type="submit"]' ).click(),
		] );
		expect( res.status() ).toBe( 403 );
		expect( ( await getState( request ) ).settings.banner_layout ).toBe( 'box' );
	} );

	test( 'una sezione non può scrivere chiavi di un\'altra (mass assignment)', async ( { page, request } ) => {
		await page.goto( BANNER_PAGE );
		const form = page.locator( 'form', { has: page.locator( '#dbcm-banner_layout' ) } );
		// Campo iniettato: appartiene alla sezione 'log_settings'.
		await form.evaluate( ( el ) => {
			const extra = document.createElement( 'input' );
			extra.type = 'hidden';
			extra.name = 'consent_log_enabled';
			extra.value = '0';
			el.appendChild( extra );
		} );
		await submitFormOf( page, '#dbcm-banner_layout' );

		expect( ( await getState( request ) ).settings.consent_log_enabled ).toBe( true );
	} );

} );

test( 'dashboard: ogni sezione ha la sua descrizione (regressione 3.8.1)', async ( { page } ) => {
	await page.goto( '/wp-admin/admin.php?page=dbcm' );
	const cards = page.locator( 'a.dbcm-dash-card' );
	await expect( cards ).toHaveCount( 7 );
	for ( const desc of await cards.locator( 'p.description' ).allTextContents() ) {
		expect( desc.trim() ).not.toBe( '' );
	}
} );
