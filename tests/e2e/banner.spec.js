// @ts-check
const { test, expect } = require( '@playwright/test' );
const { FIXTURE_WP, getConsentCookie, resetState } = require( './helpers' );

/**
 * Banner reale (pagina fixture con wp_head/wp_footer): scelte del visitatore,
 * cookie di consenso, sincronizzazione col server, riapertura, lingue.
 */

const BANNER = '#dbcm-banner-root .dbcm-banner[role="dialog"]:not(.dbcm-banner--preferences)';
const PREFS = '#dbcm-banner-root .dbcm-banner--preferences[role="dialog"]';
const OPTIONAL = [ 'preferences', 'statistics', 'statistics-anonymous', 'marketing' ];
const BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8888';

/**
 * Attende la POST di sincronizzazione del consenso (dbcm_set_consent).
 *
 * @param {import('@playwright/test').Page} page
 */
function waitForConsentSync( page ) {
	return page.waitForResponse(
		( r ) => r.url().includes( 'admin-ajax.php' ) && r.request().method() === 'POST'
	);
}

/**
 * Scrive un cookie di consenso già espresso, come lo troverebbe il browser.
 *
 * @param {import('@playwright/test').BrowserContext} context
 * @param {object} data
 */
async function setConsentCookie( context, data ) {
	await context.addCookies( [ {
		name: 'dbcm_consent',
		value: encodeURIComponent( JSON.stringify( data ) ),
		url: BASE_URL,
	} ] );
}

test.beforeEach( async ( { page, context, request } ) => {
	await resetState( request );
	await context.clearCookies();
	// Dopo l'accettazione banner.js riattiva GA4 ed embed: nessuna richiesta
	// reale verso terze parti dalla CI.
	await page.route( ( url ) => ! url.href.startsWith( BASE_URL ), ( route ) => route.abort() );
} );

test.describe( 'Prima visita', () => {

	test( 'il banner si apre con le tre azioni e nessun cookie di consenso', async ( { page, context } ) => {
		await page.goto( FIXTURE_WP );

		const banner = page.locator( BANNER );
		await expect( banner ).toBeVisible();
		await expect( banner.locator( '.dbcm-btn--primary' ) ).toBeVisible();
		await expect( banner.locator( '.dbcm-btn--secondary' ) ).toBeVisible();
		await expect( banner.locator( '.dbcm-btn--ghost' ) ).toBeVisible();

		expect( await getConsentCookie( context ) ).toBeNull();
	} );

} );

test.describe( 'Scelte del visitatore', () => {

	test( '"Accetta tutto" concede ogni categoria, sincronizza e non ripropone il banner', async ( { page, context } ) => {
		await page.goto( FIXTURE_WP );

		const sync = waitForConsentSync( page );
		await page.locator( `${ BANNER } .dbcm-btn--primary` ).click();
		const res = await sync;
		expect( res.status() ).toBe( 200 );
		expect( ( await res.json() ).success ).toBe( true );

		await expect( page.locator( BANNER ) ).toHaveCount( 0 );
		await expect( page.locator( '.dbcm-reopen' ) ).toBeVisible();

		const consent = await getConsentCookie( context );
		expect( consent ).toMatchObject( { v: 3, cv: 1, type: 'accept_all', functional: true } );
		for ( const cat of OPTIONAL ) {
			expect( consent[ cat ], cat ).toBe( true );
		}

		await page.reload();
		await expect( page.locator( '.dbcm-reopen' ) ).toBeVisible();
		await expect( page.locator( BANNER ) ).toHaveCount( 0 );
	} );

	test( '"Rifiuta" nega ogni categoria opzionale e non ripropone il banner', async ( { page, context } ) => {
		await page.goto( FIXTURE_WP );

		const sync = waitForConsentSync( page );
		await page.locator( `${ BANNER } .dbcm-btn--secondary` ).click();
		expect( ( await ( await sync ).json() ).success ).toBe( true );

		const consent = await getConsentCookie( context );
		expect( consent ).toMatchObject( { type: 'reject_all', functional: true } );
		for ( const cat of OPTIONAL ) {
			expect( consent[ cat ], cat ).toBe( false );
		}

		await page.reload();
		await expect( page.locator( '.dbcm-reopen' ) ).toBeVisible();
		await expect( page.locator( BANNER ) ).toHaveCount( 0 );
	} );

	test( '"Personalizza" parte con le categorie opzionali disattivate (no pre-spunta)', async ( { page } ) => {
		await page.goto( FIXTURE_WP );
		await page.locator( `${ BANNER } .dbcm-btn--ghost` ).click();

		const prefs = page.locator( PREFS );
		await expect( prefs ).toBeVisible();
		for ( const cat of OPTIONAL ) {
			await expect( prefs.locator( `.dbcm-toggle__input[data-category="${ cat }"]` ), cat ).not.toBeChecked();
		}
	} );

	test( '"Personalizza" salva solo le categorie scelte', async ( { page, context } ) => {
		await page.goto( FIXTURE_WP );
		await page.locator( `${ BANNER } .dbcm-btn--ghost` ).click();

		const prefs = page.locator( PREFS );
		// L'input è visivamente nascosto: si clicca lo slider, come l'utente.
		await prefs.locator( '.dbcm-pref__row[data-category="statistics"] .dbcm-toggle' ).click();
		await expect( prefs.locator( '.dbcm-toggle__input[data-category="statistics"]' ) ).toBeChecked();

		const sync = waitForConsentSync( page );
		await prefs.locator( '.dbcm-btn--primary' ).click();
		await sync;

		await expect( prefs ).toHaveCount( 0 );
		const consent = await getConsentCookie( context );
		expect( consent ).toMatchObject( {
			type: 'custom',
			functional: true,
			preferences: false,
			statistics: true,
			'statistics-anonymous': false,
			marketing: false,
		} );
	} );

} );

test.describe( 'Riapertura delle preferenze', () => {

	test( 'il pulsante 🍪 riapre le preferenze con la scelta salvata', async ( { page } ) => {
		await page.goto( FIXTURE_WP );
		await page.locator( `${ BANNER } .dbcm-btn--primary` ).click();

		await page.locator( '.dbcm-reopen' ).click();
		const prefs = page.locator( PREFS );
		await expect( prefs ).toBeVisible();
		for ( const cat of OPTIONAL ) {
			await expect( prefs.locator( `.dbcm-toggle__input[data-category="${ cat }"]` ), cat ).toBeChecked();
		}
	} );

	test( 'lo shortcode [dbcm_preferences] apre le preferenze', async ( { page } ) => {
		await page.goto( FIXTURE_WP );
		await page.locator( `${ BANNER } .dbcm-btn--secondary` ).click();

		await page.locator( '#fixture-prefs' ).click();
		await expect( page.locator( PREFS ) ).toBeVisible();
	} );

} );

test.describe( 'Cookie già presente', () => {

	// Regressione 3.8.1: wp_localize_script passa lo schema come "3"; fino
	// alla 3.8.0 il confronto stretto scartava i cookie con v numerico.
	for ( const v of [ 3, '3' ] ) {
		test( `un consenso valido con v=${ JSON.stringify( v ) } è riconosciuto`, async ( { page, context } ) => {
			await setConsentCookie( context, {
				v,
				cv: 1,
				ts: Date.now(),
				type: 'custom',
				functional: true,
				preferences: false,
				statistics: false,
				'statistics-anonymous': false,
				marketing: true,
			} );

			await page.goto( FIXTURE_WP );
			await expect( page.locator( '.dbcm-reopen' ) ).toBeVisible();
			await expect( page.locator( BANNER ) ).toHaveCount( 0 );
			expect( await page.evaluate( () => window.DBCM.hasConsent( 'marketing' ) ) ).toBe( true );
			expect( await page.evaluate( () => window.DBCM.hasConsent( 'statistics' ) ) ).toBe( false );
		} );
	}

	test( 'un consenso con versione superata ripropone il banner', async ( { page, context, request } ) => {
		await resetState( request, { settings: { consent_version: 2 } } );
		await setConsentCookie( context, {
			v: 3,
			cv: 1,
			ts: Date.now(),
			type: 'accept_all',
			functional: true,
			preferences: true,
			statistics: true,
			'statistics-anonymous': true,
			marketing: true,
		} );

		await page.goto( FIXTURE_WP );
		await expect( page.locator( BANNER ) ).toBeVisible();
		expect( await page.evaluate( () => window.DBCM.hasConsent( 'marketing' ) ) ).toBe( false );
	} );

} );

test.describe( 'Lingua del banner', () => {

	test.describe( 'browser in italiano', () => {
		test.use( { locale: 'it-IT' } );

		test( 'mostra i testi italiani', async ( { page } ) => {
			await page.goto( FIXTURE_WP );
			await expect( page.locator( `${ BANNER } .dbcm-banner__title` ) ).toHaveText( 'Rispettiamo la tua privacy' );
			await expect( page.locator( `${ BANNER } .dbcm-btn--primary` ) ).toHaveText( 'Accetta tutto' );
		} );
	} );

	test.describe( 'browser in inglese', () => {
		test.use( { locale: 'en-GB' } );

		test( 'mostra i testi inglesi', async ( { page } ) => {
			await page.goto( FIXTURE_WP );
			await expect( page.locator( `${ BANNER } .dbcm-banner__title` ) ).toHaveText( 'We respect your privacy' );
		} );
	} );

	test.describe( 'lingua del browser non attiva', () => {
		test.use( { locale: 'fr-FR' } );

		test( 'ripiega sulla lingua predefinita del sito', async ( { page } ) => {
			// Baseline: lingue attive it + en, predefinita it.
			await page.goto( FIXTURE_WP );
			await expect( page.locator( `${ BANNER } .dbcm-banner__title` ) ).toHaveText( 'Rispettiamo la tua privacy' );
		} );
	} );

} );
