// @ts-check
const { test, expect } = require( '@playwright/test' );
const { FIXTURE_WP, ADMIN_STATE, resetState } = require( './helpers' );

/**
 * HTML indipendente dal consenso (3.8.0). Con una cache di pagina la
 * risposta generata per un visitatore viene servita a tutti: per gli
 * anonimi l'HTML deve quindi essere identico con o senza consenso, con
 * tracker sempre neutralizzati. Solo per gli utenti loggati (pagine non
 * cachate) la decisione resta lato server.
 */

const BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8888';

const ACCEPT_ALL = encodeURIComponent( JSON.stringify( {
	v: 3,
	cv: 1,
	ts: Date.now(),
	type: 'accept_all',
	functional: true,
	preferences: true,
	statistics: true,
	'statistics-anonymous': true,
	marketing: true,
} ) );

const GA4_BLOCKED = /<script[^>]*src="https:\/\/www\.googletagmanager\.com\/gtag\/js[^"]*"[^>]*data-dbcm-blocked="true"|<script[^>]*data-dbcm-blocked="true"[^>]*src="https:\/\/www\.googletagmanager\.com\/gtag\/js/;

test.beforeEach( async ( { request } ) => {
	await resetState( request );
} );

test.describe( 'Visitatore anonimo', () => {

	test( 'l\'HTML è identico con e senza consenso', async ( { request } ) => {
		const without = await ( await request.get( FIXTURE_WP ) ).text();
		const withConsent = await ( await request.get( FIXTURE_WP, {
			headers: { Cookie: `dbcm_consent=${ ACCEPT_ALL }` },
		} ) ).text();

		expect( withConsent ).toBe( without );
	} );

	test( 'con consenso pieno l\'HTML servito resta bloccato', async ( { request } ) => {
		const html = await ( await request.get( FIXTURE_WP, {
			headers: { Cookie: `dbcm_consent=${ ACCEPT_ALL }` },
		} ) ).text();

		expect( html ).toMatch( GA4_BLOCKED );
		expect( html ).toContain( 'dbcm-iframe-placeholder' );
		expect( html ).not.toMatch( /<iframe[^>]*youtube\.com\/embed/ );
		// La decisione di aprire il banner non dipende dal cookie.
		expect( html ).toContain( '"autoOpen":"1"' );
	} );

} );

test.describe( 'Utente loggato', () => {
	test.use( { storageState: ADMIN_STATE } );

	test( 'con consenso la pagina esce già sbloccata dal server', async ( { page, context } ) => {
		await context.addCookies( [ { name: 'dbcm_consent', value: ACCEPT_ALL, url: BASE_URL } ] );
		// Nessuna richiesta reale verso terze parti.
		await page.route( ( url ) => ! url.href.startsWith( BASE_URL ), ( route ) => route.abort() );

		const html = await ( await page.goto( FIXTURE_WP ) ).text();

		expect( html ).not.toMatch( GA4_BLOCKED );
		expect( html ).toMatch( /<iframe[^>]*youtube\.com\/embed/ );
		expect( html ).not.toContain( 'class="dbcm-iframe-placeholder"' );
	} );

	test( 'senza consenso la pagina esce bloccata anche per l\'admin', async ( { page } ) => {
		await page.route( ( url ) => ! url.href.startsWith( BASE_URL ), ( route ) => route.abort() );

		const html = await ( await page.goto( FIXTURE_WP ) ).text();

		expect( html ).toMatch( GA4_BLOCKED );
		expect( html ).toContain( 'class="dbcm-iframe-placeholder"' );
	} );
} );
