// @ts-check
const { test, expect } = require( '@playwright/test' );
const { FIXTURE_WP, resetState, interceptThirdParty, setConsentCookie } = require( './helpers' );

/**
 * Google Consent Mode v2, Microsoft UET Consent Mode, Microsoft Clarity
 * ConsentV2 (DBCM_Consent_Signals + banner.js).
 *
 * Default 'denied' emesso nel <head> prima di qualsiasi tag; 'update' da
 * banner.js secondo il mapping categoria → segnale, al commit e al boot.
 */

/**
 * Comandi accodati da una coda in stile gtag (arguments → array).
 *
 * @param {import('@playwright/test').Page} page
 * @param {'dataLayer'|'uetq'|'clarity'} queue
 */
function readQueue( page, queue ) {
	return page.evaluate( ( name ) => {
		if ( 'dataLayer' === name ) {
			return ( window.dataLayer || [] ).map( ( a ) => Array.from( a ) );
		}
		if ( 'uetq' === name ) {
			// uetq.push('consent', mode, payload): elementi piatti in sequenza.
			const q = window.uetq || [];
			const out = [];
			for ( let i = 0; i < q.length; i++ ) {
				if ( 'consent' === q[ i ] ) {
					out.push( [ q[ i ], q[ i + 1 ], q[ i + 2 ] ] );
				}
			}
			return out;
		}
		return ( ( window.clarity && window.clarity.q ) || [] ).map( ( a ) => Array.from( a ) );
	}, queue );
}

const GCM_DENIED = {
	analytics_storage: 'denied',
	ad_storage: 'denied',
	ad_user_data: 'denied',
	ad_personalization: 'denied',
};

test.beforeEach( async ( { page, context, request } ) => {
	await resetState( request, { settings: { gcm_enabled: true, uet_enabled: true, clarity_enabled: true } } );
	await context.clearCookies();
	await interceptThirdParty( page );
} );

test.describe( 'Default denied nel <head>', () => {

	test( 'GCM, UET e Clarity partono tutti negati', async ( { page } ) => {
		await page.goto( FIXTURE_WP );

		const gcm = ( await readQueue( page, 'dataLayer' ) ).filter( ( c ) => 'consent' === c[ 0 ] );
		expect( gcm[ 0 ] ).toEqual( [ 'consent', 'default', { ...GCM_DENIED, wait_for_update: 500 } ] );

		const uet = await readQueue( page, 'uetq' );
		expect( uet[ 0 ] ).toEqual( [ 'consent', 'default', { ad_storage: 'denied' } ] );

		const clarity = await readQueue( page, 'clarity' );
		expect( clarity[ 0 ] ).toEqual( [ 'consentv2', { ad_Storage: 'denied', analytics_Storage: 'denied' } ] );
	} );

	test( 'il default precede il tag GA4 nell\'HTML', async ( { page } ) => {
		const html = await ( await page.goto( FIXTURE_WP ) ).text();
		const defaultAt = html.indexOf( 'gtag("consent","default"' );
		const tagAt = html.indexOf( 'googletagmanager.com/gtag/js' );

		expect( defaultAt ).toBeGreaterThan( -1 );
		expect( defaultAt ).toBeLessThan( tagAt );
	} );

	test( 'con i moduli disattivati nessuno snippet viene emesso', async ( { page, request } ) => {
		await resetState( request );
		const html = await ( await page.goto( FIXTURE_WP ) ).text();

		expect( html ).not.toContain( 'gtag("consent","default"' );
		expect( html ).not.toContain( 'uetq.push("consent"' );
		expect( html ).not.toContain( 'clarity("consentv2"' );
	} );

} );

test.describe( 'Update al commit', () => {

	test( '"Accetta tutto" concede ogni segnale', async ( { page } ) => {
		await page.goto( FIXTURE_WP );
		await page.locator( '.dbcm-banner .dbcm-btn--primary' ).click();

		const gcm = ( await readQueue( page, 'dataLayer' ) ).filter( ( c ) => 'consent' === c[ 0 ] && 'update' === c[ 1 ] );
		expect( gcm.at( -1 )[ 2 ] ).toEqual( {
			analytics_storage: 'granted',
			ad_storage: 'granted',
			ad_user_data: 'granted',
			ad_personalization: 'granted',
		} );

		expect( ( await readQueue( page, 'uetq' ) ).at( -1 ) ).toEqual( [ 'consent', 'update', { ad_storage: 'granted' } ] );
		expect( ( await readQueue( page, 'clarity' ) ).at( -1 ) ).toEqual(
			[ 'consentv2', { ad_Storage: 'granted', analytics_Storage: 'granted' } ]
		);
	} );

	test( 'solo statistiche: analytics concesso, pubblicità negata', async ( { page } ) => {
		await page.goto( FIXTURE_WP );
		await page.evaluate( () => window.DBCM.setConsent( { statistics: true } ) );

		const gcm = ( await readQueue( page, 'dataLayer' ) ).filter( ( c ) => 'consent' === c[ 0 ] && 'update' === c[ 1 ] );
		expect( gcm.at( -1 )[ 2 ] ).toEqual( { ...GCM_DENIED, analytics_storage: 'granted' } );
		expect( ( await readQueue( page, 'uetq' ) ).at( -1 ) ).toEqual( [ 'consent', 'update', { ad_storage: 'denied' } ] );
		expect( ( await readQueue( page, 'clarity' ) ).at( -1 ) ).toEqual(
			[ 'consentv2', { ad_Storage: 'denied', analytics_Storage: 'granted' } ]
		);
	} );

	test( 'la revoca riporta i segnali a denied', async ( { page } ) => {
		await page.goto( FIXTURE_WP );
		await page.locator( '.dbcm-banner .dbcm-btn--primary' ).click();
		await page.locator( '.dbcm-reopen' ).click();
		await page.locator( '.dbcm-banner--preferences .dbcm-btn--secondary' ).click();

		const gcm = ( await readQueue( page, 'dataLayer' ) ).filter( ( c ) => 'consent' === c[ 0 ] && 'update' === c[ 1 ] );
		expect( gcm.at( -1 )[ 2 ] ).toEqual( GCM_DENIED );
		expect( ( await readQueue( page, 'uetq' ) ).at( -1 ) ).toEqual( [ 'consent', 'update', { ad_storage: 'denied' } ] );
	} );

} );

test( 'al caricamento con consenso salvato l\'update riallinea i segnali', async ( { page, context } ) => {
	await setConsentCookie( context, { marketing: true } );
	await page.goto( FIXTURE_WP );

	const gcm = ( await readQueue( page, 'dataLayer' ) ).filter( ( c ) => 'consent' === c[ 0 ] );
	expect( gcm.map( ( c ) => c[ 1 ] ) ).toEqual( [ 'default', 'update' ] );
	expect( gcm[ 1 ][ 2 ] ).toEqual( {
		analytics_storage: 'denied',
		ad_storage: 'granted',
		ad_user_data: 'granted',
		ad_personalization: 'granted',
	} );
} );
