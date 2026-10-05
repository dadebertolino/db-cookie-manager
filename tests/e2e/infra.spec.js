// @ts-check
const { test, expect } = require( '@playwright/test' );
const { FIXTURE_WP, ADMIN_STATE, resetState } = require( './helpers' );

/**
 * Smoke test dell'infrastruttura E2E: se questi falliscono, i fallimenti
 * degli altri spec non sono attendibili.
 */
test.describe( 'Infrastruttura E2E', () => {

	test( 'il reset riporta alla baseline e accetta override e seed', async ( { request } ) => {
		const base = await resetState( request );
		expect( base.settings.localize_google_fonts ).toBe( true );
		expect( base.settings.consent_duration ).toBe( 365 );
		expect( base.log ).toBe( 0 );

		const custom = await resetState( request, {
			settings: { consent_duration: 30 },
			seed_log: [ { type: 'accept_all', consent: { marketing: true }, count: 3 } ],
		} );
		expect( custom.settings.consent_duration ).toBe( 30 );
		expect( custom.log ).toBe( 3 );

		// Lo stato non deve trascinarsi al test successivo.
		const again = await resetState( request );
		expect( again.settings.consent_duration ).toBe( 365 );
		expect( again.log ).toBe( 0 );
	} );

	test.describe( 'pagina fixture con wp_head/wp_footer', () => {

		test.beforeEach( async ( { context, request } ) => {
			await resetState( request );
			await context.clearCookies();
		} );

		test( 'carica la config reale del banner e lo apre', async ( { page } ) => {
			await page.goto( FIXTURE_WP );

			const cfg = await page.evaluate( () => window.dbcmBanner );
			expect( cfg.cookieName ).toBe( 'dbcm_consent' );
			expect( cfg.cookieSchema ).toBe( 3 );
			expect( cfg.autoOpen ).toBe( true );

			await expect( page.locator( '#dbcm-banner-root .dbcm-banner[role="dialog"]' ) ).toBeVisible();
			await expect( page.locator( '#fixture-prefs.dbcm-prefs-btn' ) ).toBeVisible();
		} );

		test( 'lo script GA4 accodato è neutralizzato da script_loader_tag', async ( { page } ) => {
			await page.goto( FIXTURE_WP, { waitUntil: 'domcontentloaded' } );

			await expect(
				page.locator( 'script[src*="googletagmanager.com/gtag/js"][data-dbcm-blocked="true"]' )
			).toHaveCount( 1 );
		} );

	} );

	test.describe( 'sessione admin', () => {
		test.use( { storageState: ADMIN_STATE } );

		test( 'la dashboard del plugin è raggiungibile', async ( { page } ) => {
			await page.goto( '/wp-admin/admin.php?page=dbcm' );
			await expect( page.locator( '.dbcm-wrap .db-ui-page-header h1' ) ).toHaveText( /DB Cookie Manager/ );
		} );
	} );

} );
