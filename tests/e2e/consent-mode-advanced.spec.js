// @ts-check
const { test, expect } = require( '@playwright/test' );
const { FIXTURE_WP, resetState, interceptThirdParty } = require( './helpers' );

/**
 * Consent Mode v2: modalità base (default) e avanzata (3.9.0, opt-in).
 *
 * Pagina fixture con &dbcm_gtm=1: snippet GTM inline, gtag.js accodato e un
 * Meta Pixel incollato a mano nel tema. Le richieste verso Google e Meta
 * sono intercettate: si verifica che partano (o no), senza caricare i veri
 * script.
 */

const PAGE = `${ FIXTURE_WP }&dbcm_gtm=1`;
const BANNER = '#dbcm-banner-root .dbcm-banner[role="dialog"]:not(.dbcm-banner--preferences)';
const PREFS = '#dbcm-banner-root .dbcm-banner--preferences';

/**
 * Comandi consent nel dataLayer (gli arguments di gtag diventano array).
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} [mode] 'default' | 'update'
 */
function consentCommands( page, mode ) {
	return page.evaluate( ( m ) => ( window.dataLayer || [] )
		.filter( ( e ) => e && 'object' === typeof e && 'length' in e )
		.map( ( e ) => Array.from( e ) )
		.filter( ( e ) => 'consent' === e[ 0 ] && ( ! m || m === e[ 1 ] ) ), mode );
}

/**
 * Eventi personalizzati del plugin nel dataLayer.
 *
 * @param {import('@playwright/test').Page} page
 */
function dbcmEvents( page ) {
	return page.evaluate( () => ( window.dataLayer || [] ).filter( ( e ) => e && 'dbcm_consent_update' === e.event ) );
}

/**
 * Sceglie "solo Statistiche" dal modal preferenze.
 *
 * @param {import('@playwright/test').Page} page
 */
async function acceptStatisticsOnly( page ) {
	await page.locator( `${ BANNER } .dbcm-btn--ghost` ).click();
	await page.locator( `${ PREFS } .dbcm-pref__row[data-category="statistics"] .dbcm-toggle` ).click();
	await page.locator( `${ PREFS } .dbcm-btn--primary` ).click();
}

/**
 * Revoca dal pulsante "Modifica preferenze cookie" nel contenuto della pagina
 * (shortcode [dbcm_preferences], tipicamente nel footer).
 *
 * @param {import('@playwright/test').Page} page
 */
async function revokeFromShortcode( page ) {
	await page.locator( '#fixture-prefs' ).click();
	await page.locator( `${ PREFS } .dbcm-btn--secondary` ).click();
}

test.beforeEach( async ( { context } ) => {
	await context.clearCookies();
} );

test.describe( 'Modalità base (default): Google bloccato fino al consenso', () => {

	test.beforeEach( async ( { request } ) => {
		await resetState( request, { settings: { gcm_enabled: true } } );
	} );

	test( 'prima della scelta: segnali negati, nessun tag Google né cookie _ga', async ( { page, context } ) => {
		const hits = await interceptThirdParty( page );
		await page.goto( PAGE, { waitUntil: 'networkidle' } );

		const [ def ] = await consentCommands( page, 'default' );
		expect( def[ 2 ] ).toMatchObject( { analytics_storage: 'denied', ad_storage: 'denied' } );

		expect( hits.filter( ( u ) => u.includes( 'googletagmanager.com' ) ) ).toHaveLength( 0 );
		await expect( page.locator( 'script[src*="googletagmanager.com/gtag/js"][data-dbcm-blocked="true"]' ) ).toHaveCount( 1 );
		expect( ( await context.cookies() ).some( ( c ) => c.name.startsWith( '_ga' ) ) ).toBe( false );
	} );

	test( 'accettando le Statistiche il tag parte, e la revoca dal footer rimuove i cookie', async ( { page, context } ) => {
		await interceptThirdParty( page );
		// Stub di gtag.js che scrive _ga come farebbe il vero GA4 con
		// analytics_storage concesso: se il cookie compare, lo script è partito.
		await page.route( /googletagmanager\.com\/gtag\/js/, ( route ) => route.fulfill( {
			status: 200,
			contentType: 'application/javascript',
			body: 'document.cookie = "_ga=GA1.1.123.456; path=/";',
		} ) );
		await page.goto( PAGE );

		await acceptStatisticsOnly( page );
		await expect.poll( async () => ( await context.cookies() ).some( ( c ) => '_ga' === c.name ) ).toBe( true );

		await revokeFromShortcode( page );
		await expect.poll( async () => ( await context.cookies() ).some( ( c ) => '_ga' === c.name ) ).toBe( false );
		const updates = await consentCommands( page, 'update' );
		expect( updates.at( -1 )[ 2 ] ).toMatchObject( { analytics_storage: 'denied', ad_storage: 'denied' } );
	} );

} );

test.describe( 'Modalità avanzata (opt-in): Google caricato con segnali negati', () => {

	test.beforeEach( async ( { request } ) => {
		await resetState( request, { settings: { gcm_enabled: true, gcm_advanced: true } } );
	} );

	test( 'prima della scelta: GTM caricato, segnali negati, Meta Pixel di terzi bloccato', async ( { page, context } ) => {
		const hits = await interceptThirdParty( page );
		await page.goto( PAGE, { waitUntil: 'networkidle' } );

		const [ def ] = await consentCommands( page, 'default' );
		expect( def[ 2 ] ).toMatchObject( { analytics_storage: 'denied', ad_storage: 'denied' } );

		expect( hits.some( ( u ) => u.includes( 'googletagmanager.com/gtm.js' ) ) ).toBe( true );
		expect( hits.some( ( u ) => u.includes( 'googletagmanager.com/gtag/js' ) ) ).toBe( true );
		await expect( page.locator( 'script[src*="googletagmanager.com/gtag/js"]:not([data-dbcm-blocked])' ) ).toHaveCount( 1 );

		// Il Meta Pixel incollato nel tema resta neutralizzato.
		await expect( page.locator( '#fixture-third-party-pixel' ) ).toHaveAttribute( 'type', 'text/plain' );
		expect( hits.filter( ( u ) => u.includes( 'connect.facebook.net' ) ) ).toHaveLength( 0 );
		expect( ( await context.cookies() ).some( ( c ) => c.name.startsWith( '_ga' ) || '_fbp' === c.name ) ).toBe( false );
	} );

	test( 'il default di Consent Mode precede GTM nell\'HTML', async ( { page } ) => {
		await interceptThirdParty( page );
		const html = await ( await page.goto( PAGE ) ).text();

		const defaultAt = html.indexOf( 'gtag("consent","default"' );
		expect( defaultAt ).toBeGreaterThan( -1 );
		expect( defaultAt ).toBeLessThan( html.indexOf( 'googletagmanager.com/gtm.js' ) );
		expect( defaultAt ).toBeLessThan( html.indexOf( 'googletagmanager.com/gtag/js' ) );
	} );

	test( 'solo Statistiche: analytics granted, ad denied, evento nel dataLayer', async ( { page } ) => {
		await interceptThirdParty( page );
		await page.goto( PAGE );

		await acceptStatisticsOnly( page );

		const updates = await consentCommands( page, 'update' );
		expect( updates.at( -1 )[ 2 ] ).toMatchObject( {
			analytics_storage: 'granted',
			ad_storage: 'denied',
			ad_user_data: 'denied',
			ad_personalization: 'denied',
		} );

		const events = await dbcmEvents( page );
		expect( events.at( -1 ) ).toMatchObject( {
			event: 'dbcm_consent_update',
			dbcm_consent_type: 'custom',
			dbcm_consent_source: 'choice',
			dbcm_consent: { statistics: true, marketing: false, functional: true },
		} );
	} );

	test( 'revoca dal footer: segnali di nuovo negati', async ( { page } ) => {
		await interceptThirdParty( page );
		await page.goto( PAGE );
		await acceptStatisticsOnly( page );

		await revokeFromShortcode( page );

		const updates = await consentCommands( page, 'update' );
		expect( updates.at( -1 )[ 2 ] ).toMatchObject( { analytics_storage: 'denied', ad_storage: 'denied' } );
		expect( ( await dbcmEvents( page ) ).at( -1 ) ).toMatchObject( { dbcm_consent_type: 'reject_all' } );
	} );

	test( 'al caricamento con consenso salvato l\'evento parte con source "saved"', async ( { page } ) => {
		await interceptThirdParty( page );
		await page.goto( PAGE );
		await acceptStatisticsOnly( page );

		await page.reload();
		expect( ( await dbcmEvents( page ) ).at( -1 ) ).toMatchObject( {
			dbcm_consent_source: 'saved',
			dbcm_consent: { statistics: true },
		} );
	} );

} );

test( 'senza Consent Mode e senza dataLayer il plugin non crea il dataLayer', async ( { page, request } ) => {
	// Sito senza tag Google: GCM spento e nessun dataLayer. La fixture
	// stampa uno snippet gtag inline che lo crea, quindi lo si rimuove prima
	// della scelta.
	await resetState( request );
	await interceptThirdParty( page );
	await page.goto( FIXTURE_WP );
	await page.evaluate( () => { delete window.dataLayer; } );

	await page.evaluate( () => window.DBCM.setConsent( { preferences: true } ) );

	expect( await page.evaluate( () => typeof window.dataLayer ) ).toBe( 'undefined' );
} );
