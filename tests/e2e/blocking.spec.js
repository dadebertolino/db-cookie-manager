// @ts-check
const { test, expect } = require( '@playwright/test' );
const { trackThirdParty } = require( './helpers' );

/**
 * Blocco preventivo con consenso NEGATO — spec §9.1, §9.3, §9.4.
 *
 * Precondizione: il visitatore non ha ancora espresso consenso (nessun cookie
 * dbcm_consent), quindi il default GDPR è "tutto negato".
 */
test.describe( 'Blocco con consenso negato', () => {

	test.beforeEach( async ( { context } ) => {
		// Parte pulita: nessun consenso pregresso.
		await context.clearCookies();
	} );

	test( '§9.1 — nessuna richiesta verso terze parti', async ( { page } ) => {
		const tracker = trackThirdParty( page );

		await page.goto( '/dbcm-test/', { waitUntil: 'networkidle' } );

		// Il blocker deve aver neutralizzato GA4 e gli embed: zero hit.
		expect(
			tracker.hits,
			`Richieste terze parti inattese:\n${ tracker.hits.join( '\n' ) }`
		).toHaveLength( 0 );
	} );

	test( '§9.1 — lo script GA4 è neutralizzato (type text/plain)', async ( { page } ) => {
		await page.goto( '/dbcm-test/' );

		// Lo snippet GA4 fittizio deve essere stato riscritto a text/plain
		// con i data-attribute del blocker.
		const blocked = await page.locator(
			'script[data-dbcm-blocked="true"][data-dbcm-category="statistics"]'
		).count();
		expect( blocked, 'GA4 deve essere bloccato come statistics.' ).toBeGreaterThan( 0 );
	} );

	test( '§9.3 — il link WhatsApp è presente e cliccabile senza consenso', async ( { page } ) => {
		await page.goto( '/dbcm-test/' );

		const wa = page.locator( '#fixture-whatsapp' );
		await expect( wa ).toBeVisible();
		await expect( wa ).toHaveAttribute( 'href', /wa\.me/ );
		// Non deve essere sostituito da placeholder né disabilitato.
	} );

} );
