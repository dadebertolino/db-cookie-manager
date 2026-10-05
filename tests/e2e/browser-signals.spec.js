// @ts-check
const { test, expect } = require( '@playwright/test' );
const { FIXTURE_WP, getConsentCookie, resetState, getState, interceptThirdParty } = require( './helpers' );

/**
 * Segnali del browser (GPC, DNT) e geo-targeting.
 *
 * Con un segnale di opt-out e nessuna scelta salvata, banner.js registra un
 * rifiuto (prova art. 7.1 nel registro), non mostra il banner e lascia il
 * pulsante per cambiare idea.
 */

const BANNER = '#dbcm-banner-root .dbcm-banner[role="dialog"]:not(.dbcm-banner--preferences)';

/**
 * Simula Global Privacy Control attivo nel browser.
 *
 * @param {import('@playwright/test').Page} page
 */
function enableGpc( page ) {
	return page.addInitScript( () => {
		Object.defineProperty( navigator, 'globalPrivacyControl', { get: () => true, configurable: true } );
	} );
}

/**
 * Simula Do Not Track attivo nel browser.
 *
 * @param {import('@playwright/test').Page} page
 */
function enableDnt( page ) {
	return page.addInitScript( () => {
		Object.defineProperty( navigator, 'doNotTrack', { get: () => '1', configurable: true } );
	} );
}

/**
 * Verifica il rifiuto automatico: cookie reject_all, registro, niente banner.
 *
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').BrowserContext} context
 * @param {import('@playwright/test').APIRequestContext} request
 */
async function expectAutoReject( page, context, request ) {
	await expect( page.locator( '.dbcm-reopen' ) ).toBeVisible();
	await expect( page.locator( BANNER ) ).toHaveCount( 0 );

	const consent = await getConsentCookie( context );
	expect( consent ).toMatchObject( {
		type: 'reject_all',
		functional: true,
		preferences: false,
		statistics: false,
		'statistics-anonymous': false,
		marketing: false,
	} );

	await expect.poll( async () => ( await getState( request ) ).last_log?.type ).toBe( 'reject_all' );
}

test.beforeEach( async ( { page, context, request } ) => {
	await resetState( request );
	await context.clearCookies();
	await interceptThirdParty( page );
} );

test.describe( 'Global Privacy Control', () => {

	test( 'attivo di default: rifiuto automatico registrato, banner non mostrato', async ( { page, context, request } ) => {
		await enableGpc( page );
		await page.goto( FIXTURE_WP );

		await expectAutoReject( page, context, request );
	} );

	test( 'l\'utente può comunque cambiare idea dal pulsante 🍪', async ( { page, context } ) => {
		await enableGpc( page );
		await page.goto( FIXTURE_WP );

		await page.locator( '.dbcm-reopen' ).click();
		await page.locator( '.dbcm-banner--preferences .dbcm-pref__row[data-category="statistics"] .dbcm-toggle' ).click();
		await page.locator( '.dbcm-banner--preferences .dbcm-btn--primary' ).click();

		expect( await getConsentCookie( context ) ).toMatchObject( { type: 'custom', statistics: true } );
	} );

	test( 'una scelta già salvata prevale sul segnale', async ( { page, context } ) => {
		await page.goto( FIXTURE_WP );
		await page.locator( `${ BANNER } .dbcm-btn--primary` ).click();

		await enableGpc( page );
		await page.reload();

		expect( await getConsentCookie( context ) ).toMatchObject( { type: 'accept_all', marketing: true } );
	} );

	test( 'con "Rispetta GPC" disattivato il banner viene mostrato', async ( { page, context, request } ) => {
		await resetState( request, { settings: { respect_gpc: false } } );
		await enableGpc( page );
		await page.goto( FIXTURE_WP );

		await expect( page.locator( BANNER ) ).toBeVisible();
		expect( await getConsentCookie( context ) ).toBeNull();
	} );

} );

test.describe( 'Do Not Track', () => {

	test( 'ignorato di default', async ( { page } ) => {
		await enableDnt( page );
		await page.goto( FIXTURE_WP );

		await expect( page.locator( BANNER ) ).toBeVisible();
	} );

	test( 'con "Rispetta DNT" attivo: rifiuto automatico', async ( { page, context, request } ) => {
		await resetState( request, { settings: { respect_dnt: true } } );
		await enableDnt( page );
		await page.goto( FIXTURE_WP );

		await expectAutoReject( page, context, request );
	} );

} );

test.describe( 'Geo-targeting', () => {

	test.beforeEach( async ( { request } ) => {
		await resetState( request, { settings: { geo_targeting: true } } );
	} );

	test.describe( 'visitatore extra UE', () => {
		test.use( { extraHTTPHeaders: { 'CF-IPCountry': 'US' } } );

		test( 'il banner non si apre, il pulsante preferenze resta', async ( { page } ) => {
			await page.goto( FIXTURE_WP );

			await expect( page.locator( '.dbcm-reopen' ) ).toBeVisible();
			await expect( page.locator( BANNER ) ).toHaveCount( 0 );
		} );
	} );

	test.describe( 'visitatore UE', () => {
		test.use( { extraHTTPHeaders: { 'CF-IPCountry': 'IT' } } );

		test( 'il banner si apre', async ( { page } ) => {
			await page.goto( FIXTURE_WP );
			await expect( page.locator( BANNER ) ).toBeVisible();
		} );
	} );

	// Regressione 3.8.2: Cloudflare usa XX (sconosciuto) e T1 (Tor), che
	// venivano trattati come paesi extra UE e nascondevano il banner. Browser
	// en-US di proposito: la lingua non deve decidere al posto dell'header.
	for ( const code of [ 'XX', 'T1' ] ) {
		test.describe( `paese non rilevabile (CF-IPCountry: ${ code })`, () => {
			test.use( { locale: 'en-US', extraHTTPHeaders: { 'CF-IPCountry': code } } );

			test( 'nel dubbio il banner si apre', async ( { page } ) => {
				await page.goto( FIXTURE_WP );
				await expect( page.locator( BANNER ) ).toBeVisible();
			} );
		} );
	}

	// Regressione 3.8.2: senza geolocalizzazione reale la lingua del browser
	// non conta. Prima en-US (default di molti browser europei) nascondeva
	// il banner.
	for ( const locale of [ 'en-US', 'it-IT' ] ) {
		test.describe( `senza header di geolocalizzazione, browser ${ locale }`, () => {
			test.use( { locale } );

			test( 'il banner si apre', async ( { page } ) => {
				await page.goto( FIXTURE_WP );
				await expect( page.locator( BANNER ) ).toBeVisible();
			} );
		} );
	}

	test.describe( 'paese fornito dal filtro dbcm_visitor_country_code (GeoIP)', () => {
		test.use( { locale: 'it-IT' } );

		test( 'paese extra UE: il banner non si apre', async ( { page, request } ) => {
			await resetState( request, { settings: { geo_targeting: true }, country: 'US' } );
			await page.goto( FIXTURE_WP );

			await expect( page.locator( '.dbcm-reopen' ) ).toBeVisible();
			await expect( page.locator( BANNER ) ).toHaveCount( 0 );
		} );

		test( 'paese UE: il banner si apre', async ( { page, request } ) => {
			await resetState( request, { settings: { geo_targeting: true }, country: 'DE' } );
			await page.goto( FIXTURE_WP );

			await expect( page.locator( BANNER ) ).toBeVisible();
		} );
	} );

} );
