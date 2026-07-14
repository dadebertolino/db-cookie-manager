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

		// Usa la query var diretta (?dbcm_e2e=1): non dipende dalle rewrite
		// rule pretty, quindi è deterministica anche se il flush non è avvenuto.
		await page.goto( '/?dbcm_e2e=1', { waitUntil: 'networkidle' } );

		// Il blocker deve aver neutralizzato GA4 e gli embed: zero hit.
		expect(
			tracker.hits,
			`Richieste terze parti inattese:\n${ tracker.hits.join( '\n' ) }`
		).toHaveLength( 0 );
	} );

	test( '§9.1 — lo script GA4 è neutralizzato (type text/plain)', async ( { page } ) => {
		await page.goto( '/?dbcm_e2e=1', { waitUntil: 'domcontentloaded' } );

		// Lo snippet GA4 fittizio deve essere stato riscritto a text/plain
		// con i data-attribute del blocker.
		const blocked = await page.locator(
			'script[data-dbcm-blocked="true"][data-dbcm-category="statistics"]'
		).count();
		expect( blocked, 'GA4 deve essere bloccato come statistics.' ).toBeGreaterThan( 0 );
	} );

	test( '§9.3 — il link WhatsApp è presente e cliccabile senza consenso', async ( { page } ) => {
		await page.goto( '/?dbcm_e2e=1', { waitUntil: 'domcontentloaded' } );

		const wa = page.locator( '#fixture-whatsapp' );
		await expect( wa ).toBeVisible();
		await expect( wa ).toHaveAttribute( 'href', /wa\.me/ );
		// Non deve essere sostituito da placeholder né disabilitato.
	} );

	test( '§4 — i Google Fonts remoti sono rimossi dall\'HTML', async ( { page } ) => {
		// Con localize_google_fonts attivo (impostato dalla fixture), i <link>
		// verso fonts.googleapis.com/gstatic.com non devono comparire nell'HTML
		// servito: il browser non contatta Google, l'IP dell'utente non è
		// trasmesso.
		const response = await page.goto( '/?dbcm_e2e=1', { waitUntil: 'domcontentloaded' } );
		const html = await response.text();

		expect( html ).not.toContain( 'fonts.googleapis.com' );
		expect( html ).not.toContain( 'fonts.gstatic.com' );
		// Il link con id noto non deve esistere nel DOM.
		expect( await page.locator( '#fixture-gfont' ).count() ).toBe( 0 );
	} );

} );