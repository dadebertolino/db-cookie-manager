// @ts-check
const { test, expect } = require( '@playwright/test' );
const { FIXTURE_WP, resetState, interceptThirdParty, setConsentCookie } = require( './helpers' );

/**
 * Meta Pixel nativo (DBCM_Meta_Pixel, 3.7.0): gated by-design sul consenso
 * marketing. fbevents.js è richiesto solo dopo il consenso; la revoca invia
 * fbq('consent','revoke') senza ricaricare la pagina (art. 7.3).
 *
 * fbevents.js è intercettato con un corpo vuoto: lo stub fbq resta e la sua
 * coda mostra i comandi inviati.
 */

const PIXEL_ID = '123456789012345';
const FBEVENTS = 'connect.facebook.net/en_US/fbevents.js';

/**
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<any[][]>} Comandi nella coda di fbq.
 */
function fbqQueue( page ) {
	return page.evaluate( () => ( ( window.fbq && window.fbq.queue ) || [] ).map( ( a ) => Array.from( a ) ) );
}

test.beforeEach( async ( { context, request } ) => {
	await resetState( request, { settings: { meta_pixel_enabled: true, meta_pixel_id: PIXEL_ID } } );
	await context.clearCookies();
} );

test( 'lo snippet è presente e non viene neutralizzato dal blocker', async ( { page } ) => {
	await interceptThirdParty( page );
	await page.goto( FIXTURE_WP );

	const snippet = page.locator( 'script#dbcm-meta-pixel' );
	await expect( snippet ).toHaveCount( 1 );
	await expect( snippet ).not.toHaveAttribute( 'type', 'text/plain' );
	await expect( snippet ).not.toHaveAttribute( 'data-dbcm-blocked', /.*/ );
} );

test( 'senza consenso marketing fbevents.js non viene richiesto', async ( { page } ) => {
	const hits = await interceptThirdParty( page );
	await page.goto( FIXTURE_WP, { waitUntil: 'networkidle' } );
	await page.evaluate( () => window.DBCM.setConsent( { statistics: true } ) );

	expect( hits.filter( ( u ) => u.includes( 'facebook' ) ) ).toHaveLength( 0 );
	expect( await page.evaluate( () => typeof window.fbq ) ).toBe( 'undefined' );
} );

test( 'accettando il marketing il pixel parte senza ricaricare', async ( { page } ) => {
	const hits = await interceptThirdParty( page );
	await page.goto( FIXTURE_WP );
	await page.locator( '.dbcm-banner .dbcm-btn--primary' ).click();

	await expect.poll( () => hits.some( ( u ) => u.includes( FBEVENTS ) ) ).toBe( true );
	expect( await fbqQueue( page ) ).toEqual( [
		[ 'consent', 'grant' ],
		[ 'init', PIXEL_ID ],
		[ 'track', 'PageView' ],
	] );
} );

test( 'con consenso marketing salvato il pixel parte al caricamento', async ( { page, context } ) => {
	await setConsentCookie( context, { marketing: true } );
	const hits = await interceptThirdParty( page );
	await page.goto( FIXTURE_WP );

	await expect.poll( () => hits.some( ( u ) => u.includes( FBEVENTS ) ) ).toBe( true );
	expect( ( await fbqQueue( page ) )[ 1 ] ).toEqual( [ 'init', PIXEL_ID ] );
} );

test( 'la revoca invia fbq("consent","revoke") subito', async ( { page } ) => {
	await interceptThirdParty( page );
	await page.goto( FIXTURE_WP );
	await page.locator( '.dbcm-banner .dbcm-btn--primary' ).click();

	await page.locator( '.dbcm-reopen' ).click();
	await page.locator( '.dbcm-banner--preferences .dbcm-btn--secondary' ).click();

	expect( ( await fbqQueue( page ) ).at( -1 ) ).toEqual( [ 'consent', 'revoke' ] );
} );

test( 'con l\'handoff CAPI il PageView porta un eventID', async ( { page, request } ) => {
	await resetState( request, {
		settings: { meta_pixel_enabled: true, meta_pixel_id: PIXEL_ID, meta_pixel_capi_handoff: true },
	} );
	await interceptThirdParty( page );
	await page.goto( FIXTURE_WP );
	await page.locator( '.dbcm-banner .dbcm-btn--primary' ).click();

	const pageView = ( await fbqQueue( page ) ).find( ( c ) => 'track' === c[ 0 ] );
	expect( pageView[ 3 ].eventID ).toMatch( /^pv_\d+_[a-z0-9]+$/ );
	expect( await page.evaluate( () => window.DBCM_META_LAST_EVENT_ID ) ).toBe( pageView[ 3 ].eventID );
} );

test( 'un Pixel ID non valido disattiva il modulo', async ( { page, request } ) => {
	await resetState( request, { settings: { meta_pixel_enabled: true, meta_pixel_id: '12345' } } );
	await interceptThirdParty( page );
	await page.goto( FIXTURE_WP );

	await expect( page.locator( 'script#dbcm-meta-pixel' ) ).toHaveCount( 0 );
} );
