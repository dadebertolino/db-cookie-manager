// @ts-check
const { test, expect } = require( '@playwright/test' );
const {
	BASE_URL,
	FIXTURE_WP,
	ADMIN_STATE,
	resetState,
	getState,
	interceptThirdParty,
	setConsentCookie,
} = require( './helpers' );

/**
 * Integrazione con il plugin WP Consent API: DB Cookie Manager è il consent
 * manager, propaga le scelte ai cookie wp_consent_* (server e browser) e
 * notifica gli altri plugin con l'evento wp_listen_for_consent_change.
 *
 * Il plugin WP Consent API è attivato solo per questo spec (opzione
 * consent_api del reset); gli altri spec girano senza.
 */

const BANNER = '#dbcm-banner-root .dbcm-banner[role="dialog"]:not(.dbcm-banner--preferences)';
const OPTIONAL = [ 'preferences', 'statistics', 'statistics-anonymous', 'marketing' ];

/**
 * @param {import('@playwright/test').BrowserContext} context
 * @returns {Promise<Record<string, string>>} Valori dei cookie wp_consent_*.
 */
async function wpConsentCookies( context ) {
	const out = {};
	for ( const c of await context.cookies() ) {
		if ( c.name.startsWith( 'wp_consent_' ) ) {
			out[ c.name.replace( 'wp_consent_', '' ) ] = c.value;
		}
	}
	return out;
}

test.beforeEach( async ( { request } ) => {
	await resetState( request, { consent_api: true } );
} );

test( 'DB Cookie Manager è registrato come consent manager opt-in', async ( { request } ) => {
	const state = await getState( request );
	expect( state.consent_api ).toBe( true );
	expect( state.consent_type ).toBe( 'optin' );
} );

test.describe( 'Visitatore', () => {

	test.beforeEach( async ( { page, context } ) => {
		await context.clearCookies();
		await interceptThirdParty( page );
	} );

	test( '"Accetta tutto" imposta i cookie wp_consent_* e notifica gli altri plugin', async ( { page, context } ) => {
		await page.addInitScript( () => {
			window.__wpConsentEvents = [];
			document.addEventListener( 'wp_listen_for_consent_change', ( e ) => {
				window.__wpConsentEvents.push( Object.assign( {}, e.detail ) );
			} );
		} );
		await page.goto( FIXTURE_WP );
		expect( await page.evaluate( () => typeof window.wp_set_consent ) ).toBe( 'function' );

		await page.locator( `${ BANNER } .dbcm-btn--primary` ).click();

		const cookies = await wpConsentCookies( context );
		for ( const cat of OPTIONAL ) {
			expect( cookies[ cat ], cat ).toBe( 'allow' );
		}
		const events = await page.evaluate( () => window.__wpConsentEvents );
		expect( events ).toContainEqual( { marketing: 'allow' } );
		expect( await page.evaluate( () => window.wp_has_consent( 'marketing' ) ) ).toBe( true );
	} );

	test( '"Rifiuta" imposta i cookie wp_consent_* a deny', async ( { page, context } ) => {
		await page.goto( FIXTURE_WP );
		await page.locator( `${ BANNER } .dbcm-btn--secondary` ).click();

		const cookies = await wpConsentCookies( context );
		for ( const cat of OPTIONAL ) {
			expect( cookies[ cat ], cat ).toBe( 'deny' );
		}
		expect( await page.evaluate( () => window.wp_has_consent( 'marketing' ) ) ).toBe( false );
	} );

	test( 'consenso salvato senza cookie wp_consent_*: il server li allinea una volta sola', async ( { page, context } ) => {
		await setConsentCookie( context, { marketing: true } );

		const first = await page.goto( FIXTURE_WP );
		const setCookies = ( await first.headersArray() )
			.filter( ( h ) => 'set-cookie' === h.name.toLowerCase() && h.value.startsWith( 'wp_consent_' ) );
		expect( setCookies.length ).toBeGreaterThan( 0 );

		const cookies = await wpConsentCookies( context );
		expect( cookies.marketing ).toBe( 'allow' );
		expect( cookies.statistics ).toBe( 'deny' );

		// Cookie già allineati: nessun Set-Cookie ripetuto (3.8.0).
		const second = await page.reload();
		const again = ( await second.headersArray() )
			.filter( ( h ) => 'set-cookie' === h.name.toLowerCase() && h.value.startsWith( 'wp_consent_' ) );
		expect( again ).toHaveLength( 0 );
	} );

	test( 'versione del consenso superata: i cookie wp_consent_* tornano deny', async ( { page, context, request } ) => {
		await resetState( request, { consent_api: true, settings: { consent_version: 2 } } );
		await setConsentCookie( context, { marketing: true }, { cv: 1 } );
		await context.addCookies( [ { name: 'wp_consent_marketing', value: 'allow', url: BASE_URL } ] );

		await page.goto( FIXTURE_WP );

		expect( ( await wpConsentCookies( context ) ).marketing ).toBe( 'deny' );
		await expect( page.locator( BANNER ) ).toBeVisible();
	} );

} );

test.describe( 'Decisione lato server (utente loggato)', () => {
	test.use( { storageState: ADMIN_STATE } );

	test.beforeEach( async ( { page } ) => {
		await page.route( ( url ) => ! url.href.startsWith( BASE_URL ), ( route ) => route.abort() );
	} );

	test( 'con il consenso DBCM la pagina esce sbloccata', async ( { page, context } ) => {
		await setConsentCookie( context, { marketing: true } );

		const html = await ( await page.goto( FIXTURE_WP ) ).text();
		expect( html ).toMatch( /<iframe[^>]*youtube\.com\/embed/ );
	} );

	test( 'cookie wp_consent_* senza consenso DBCM non sbloccano nulla', async ( { page, context } ) => {
		await context.addCookies( [ { name: 'wp_consent_marketing', value: 'allow', url: BASE_URL } ] );

		const html = await ( await page.goto( FIXTURE_WP ) ).text();
		expect( html ).not.toMatch( /<iframe[^>]*youtube\.com\/embed/ );
		expect( html ).toContain( 'class="dbcm-iframe-placeholder"' );
	} );
} );
