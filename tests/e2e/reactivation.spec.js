// @ts-check
const { test, expect } = require( '@playwright/test' );
const { BASE_URL, FIXTURE_WP, resetState, interceptThirdParty, setConsentCookie } = require( './helpers' );

/**
 * Riattivazione lato client (3.8.0, cache-safe): per i visitatori anonimi
 * l'HTML esce sempre bloccato e banner.js riattiva script e iframe nel
 * browser, al commit della scelta o al boot se il consenso è già salvato.
 */

const GA4 = 'googletagmanager.com/gtag/js';

test.beforeEach( async ( { context, request } ) => {
	await resetState( request );
	await context.clearCookies();
} );

test.describe( 'Riattivazione al momento della scelta', () => {

	test( 'senza scelta nessuna richiesta parte verso terze parti', async ( { page } ) => {
		const hits = await interceptThirdParty( page );
		await page.goto( FIXTURE_WP, { waitUntil: 'networkidle' } );

		expect( hits, hits.join( '\n' ) ).toHaveLength( 0 );
		await expect( page.locator( '.dbcm-iframe-placeholder' ) ).toHaveCount( 2 );
	} );

	test( '"Accetta tutto" riattiva GA4 e ripristina gli embed', async ( { page } ) => {
		const hits = await interceptThirdParty( page );
		await page.goto( FIXTURE_WP );

		await page.locator( '.dbcm-banner .dbcm-btn--primary' ).click();

		await expect( page.locator( 'script[data-dbcm-blocked]' ) ).toHaveCount( 0 );
		await expect( page.locator( '.dbcm-iframe-placeholder' ) ).toHaveCount( 0 );
		await expect( page.locator( 'iframe[src*="youtube.com/embed"]' ) ).toHaveCount( 1 );
		await expect( page.locator( 'iframe[src*="google.com/maps/embed"]' ) ).toHaveCount( 1 );
		await expect.poll( () => hits.some( ( u ) => u.includes( GA4 ) ) ).toBe( true );
	} );

	test( 'una scelta parziale riattiva solo le categorie concesse', async ( { page } ) => {
		const hits = await interceptThirdParty( page );
		await page.goto( FIXTURE_WP );

		// Solo statistiche, via API pubblica.
		await page.evaluate( () => window.DBCM.setConsent( { statistics: true } ) );

		await expect.poll( () => hits.some( ( u ) => u.includes( GA4 ) ) ).toBe( true );
		// Gli embed sono marketing: restano placeholder.
		await expect( page.locator( '.dbcm-iframe-placeholder' ) ).toHaveCount( 2 );
		expect( hits.filter( ( u ) => u.includes( 'youtube' ) || u.includes( 'google.com/maps' ) ) ).toHaveLength( 0 );
	} );

	test( 'l\'iframe ripristinato conserva gli attributi originali', async ( { page } ) => {
		await interceptThirdParty( page );
		await page.goto( FIXTURE_WP );

		await page.evaluate( () => window.DBCM.setConsent( { marketing: true } ) );

		const yt = page.locator( 'iframe[src*="youtube.com/embed"]' );
		await expect( yt ).toHaveAttribute( 'id', 'fixture-youtube' );
		await expect( yt ).toHaveAttribute( 'title', 'YouTube' );
		await expect( yt ).toHaveAttribute( 'allowfullscreen', '' );
	} );

} );

test.describe( 'Riattivazione al caricamento con consenso salvato', () => {

	test( 'consenso marketing salvato: embed ripristinati, GA4 resta bloccato', async ( { page, context } ) => {
		await setConsentCookie( context, { marketing: true } );
		const hits = await interceptThirdParty( page );

		await page.goto( FIXTURE_WP, { waitUntil: 'networkidle' } );

		await expect( page.locator( '.dbcm-iframe-placeholder' ) ).toHaveCount( 0 );
		await expect( page.locator( 'iframe[src*="youtube.com/embed"]' ) ).toHaveCount( 1 );
		await expect( page.locator( 'script[data-dbcm-blocked][data-dbcm-category="statistics"]' ) ).not.toHaveCount( 0 );
		expect( hits.filter( ( u ) => u.includes( GA4 ) ) ).toHaveLength( 0 );
	} );

	test( 'consenso statistiche salvato: GA4 riattivato senza riaprire il banner', async ( { page, context } ) => {
		await setConsentCookie( context, { statistics: true } );
		const hits = await interceptThirdParty( page );

		await page.goto( FIXTURE_WP );

		await expect.poll( () => hits.some( ( u ) => u.includes( GA4 ) ) ).toBe( true );
		await expect( page.locator( '.dbcm-banner:not(.dbcm-banner--preferences)' ) ).toHaveCount( 0 );
	} );

} );

test.describe( 'Revoca', () => {

	test( 'revocare il marketing elimina subito i cookie della categoria', async ( { page, context } ) => {
		await interceptThirdParty( page );
		await page.goto( FIXTURE_WP );
		await page.locator( '.dbcm-banner .dbcm-btn--primary' ).click();

		// Cookie marketing scritto mentre il consenso c'era (firma baseline
		// '_mypix': marketing + cancellazione reattiva).
		await context.addCookies( [ { name: '_mypix', value: '1', url: BASE_URL } ] );

		await page.locator( '.dbcm-reopen' ).click();
		await page.locator( '.dbcm-banner--preferences .dbcm-btn--secondary' ).click();

		await expect.poll( async () => ( await context.cookies() ).some( ( c ) => c.name === '_mypix' ) ).toBe( false );
	} );

} );
