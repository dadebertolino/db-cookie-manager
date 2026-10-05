// @ts-check
const { test, expect } = require( '@playwright/test' );
const {
	FIXTURE_WP,
	ADMIN_STATE,
	getConsentCookie,
	resetState,
	getState,
	interceptThirdParty,
	setConsentCookie,
} = require( './helpers' );

/**
 * Versione del consenso (3.5.0): il pulsante admin "Richiedi nuovo consenso"
 * incrementa la versione; i consensi raccolti sotto la versione precedente
 * valgono come assenti e il banner si ripresenta.
 */

const BANNER = '#dbcm-banner-root .dbcm-banner[role="dialog"]:not(.dbcm-banner--preferences)';
const BANNER_PAGE = '/wp-admin/admin.php?page=dbcm-banner';
const BUMP_LINK = 'Richiedi nuovo consenso a tutti gli utenti';

test.beforeEach( async ( { request } ) => {
	await resetState( request );
} );

test.describe( 'Pulsante admin', () => {
	test.use( { storageState: ADMIN_STATE } );

	test( 'chiede conferma e incrementa la versione', async ( { page, request } ) => {
		await page.goto( BANNER_PAGE );
		await expect( page.getByText( 'Versione corrente: 1.' ) ).toBeVisible();

		page.once( 'dialog', ( dialog ) => dialog.accept() );
		await page.getByRole( 'link', { name: BUMP_LINK } ).click();

		await expect( page.locator( '.notice-success' ) ).toContainText( 'Versione del consenso incrementata' );
		await expect( page.getByText( 'Versione corrente: 2.' ) ).toBeVisible();
		expect( ( await getState( request ) ).settings.consent_version ).toBe( 2 );
	} );

	test( 'annullando la conferma la versione non cambia', async ( { page, request } ) => {
		await page.goto( BANNER_PAGE );

		page.once( 'dialog', ( dialog ) => dialog.dismiss() );
		await page.getByRole( 'link', { name: BUMP_LINK } ).click();

		await expect( page.getByText( 'Versione corrente: 1.' ) ).toBeVisible();
		expect( ( await getState( request ) ).settings.consent_version ).toBe( 1 );
	} );
} );

test.describe( 'Effetto sui visitatori', () => {

	test( 'dopo l\'incremento il consenso precedente non vale più', async ( { page, context, request } ) => {
		await context.clearCookies();
		await interceptThirdParty( page );
		await setConsentCookie( context, { marketing: true }, { type: 'accept_all' } );

		await page.goto( FIXTURE_WP );
		await expect( page.locator( BANNER ) ).toHaveCount( 0 );

		await resetState( request, { settings: { consent_version: 2 } } );
		await page.reload();

		await expect( page.locator( BANNER ) ).toBeVisible();
		expect( await page.evaluate( () => window.DBCM.hasConsent( 'marketing' ) ) ).toBe( false );
		// Gli embed marketing restano bloccati.
		await expect( page.locator( '.dbcm-iframe-placeholder' ) ).toHaveCount( 2 );
	} );

	test( 'la nuova scelta è salvata e registrata con la nuova versione', async ( { page, context, request } ) => {
		await resetState( request, { settings: { consent_version: 2 } } );
		await context.clearCookies();
		await interceptThirdParty( page );

		await page.goto( FIXTURE_WP );
		const sync = page.waitForResponse(
			( r ) => r.url().includes( 'admin-ajax.php' ) && r.request().method() === 'POST'
		);
		await page.locator( `${ BANNER } .dbcm-btn--primary` ).click();
		await sync;

		expect( await getConsentCookie( context ) ).toMatchObject( { cv: 2 } );
		const { last_log: last } = await getState( request );
		expect( last.consent_version ).toBe( 2 );
		expect( last.consent.cv ).toBe( 2 );
	} );

} );
