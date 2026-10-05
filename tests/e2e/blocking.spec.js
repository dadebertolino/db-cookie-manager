// @ts-check
const { test, expect } = require( '@playwright/test' );
const { FIXTURE_RAW, trackThirdParty, resetState } = require( './helpers' );

/**
 * Blocco preventivo con consenso NEGATO — spec §3, §4, §9.1, §9.3, §9.4, §9.8.
 *
 * Precondizione: il visitatore non ha ancora espresso consenso (nessun cookie
 * dbcm_consent), quindi il default GDPR è "tutto negato".
 */
test.beforeEach( async ( { context, request } ) => {
	await resetState( request );
	// Parte pulita: nessun consenso pregresso.
	await context.clearCookies();
} );

test.describe( 'Blocco con consenso negato', () => {

	test( '§9.1 — nessuna richiesta verso terze parti', async ( { page } ) => {
		const tracker = trackThirdParty( page );

		await page.goto( FIXTURE_RAW, { waitUntil: 'networkidle' } );

		// Il blocker deve aver neutralizzato GA4 e gli embed: zero hit.
		expect(
			tracker.hits,
			`Richieste terze parti inattese:\n${ tracker.hits.join( '\n' ) }`
		).toHaveLength( 0 );
	} );

	test( '§9.1 — lo script GA4 è neutralizzato (type text/plain)', async ( { page } ) => {
		await page.goto( FIXTURE_RAW, { waitUntil: 'domcontentloaded' } );

		// Lo snippet GA4 fittizio deve essere stato riscritto a text/plain
		// con i data-attribute del blocker.
		const blocked = await page.locator(
			'script[data-dbcm-blocked="true"][data-dbcm-category="statistics"]'
		).count();
		expect( blocked, 'GA4 deve essere bloccato come statistics.' ).toBeGreaterThan( 0 );
	} );

	test( '§9.3 — il link WhatsApp è presente e cliccabile senza consenso', async ( { page } ) => {
		await page.goto( FIXTURE_RAW, { waitUntil: 'domcontentloaded' } );

		const wa = page.locator( '#fixture-whatsapp' );
		await expect( wa ).toBeVisible();
		await expect( wa ).toHaveAttribute( 'href', /wa\.me/ );
		// Non deve essere sostituito da placeholder né disabilitato.
	} );

	test( '§4 — i Google Fonts remoti sono rimossi dall\'HTML', async ( { page } ) => {
		// Con localize_google_fonts attivo (baseline della fixture), i <link>
		// verso fonts.googleapis.com/gstatic.com non devono comparire nell'HTML
		// servito: il browser non contatta Google, l'IP dell'utente non è
		// trasmesso.
		const response = await page.goto( FIXTURE_RAW, { waitUntil: 'domcontentloaded' } );
		const html = await response.text();

		expect( html ).not.toContain( 'fonts.googleapis.com' );
		expect( html ).not.toContain( 'fonts.gstatic.com' );
		// Il link con id noto non deve esistere nel DOM.
		expect( await page.locator( '#fixture-gfont' ).count() ).toBe( 0 );
	} );

} );

test.describe( 'Placeholder click-to-load (§3, §9.4, §9.8)', () => {

	test( '§9.4 — gli embed YouTube/Maps sono sostituiti da placeholder', async ( { page } ) => {
		await page.goto( FIXTURE_RAW );

		// L'iframe è sostituito da un placeholder accessibile con lo stesso ingombro.
		await expect( page.locator( '.dbcm-iframe-placeholder' ) ).toHaveCount( 2 ); // YouTube + Maps
		await expect( page.locator( 'iframe' ) ).toHaveCount( 0 );
	} );

	test( '§9.4 — click-to-load carica il contenuto senza consenso di categoria', async ( { page, context } ) => {
		// Nessuna richiesta reale a YouTube: basta verificare l'iframe nel DOM.
		await page.route( /youtube\.com/, ( route ) => route.fulfill( { status: 200, body: '' } ) );
		await page.goto( FIXTURE_RAW );

		await page.locator( '.dbcm-iframe-placeholder__load' ).first().click();

		await expect( page.locator( 'iframe[src*="youtube.com/embed"]' ) ).toHaveCount( 1 );
		// Consenso puntuale: nessun cookie di consenso scritto.
		const cookies = await context.cookies();
		expect( cookies.find( ( c ) => c.name === 'dbcm_consent' ) ).toBeUndefined();
	} );

	test( '§9.8 — il placeholder è navigabile da tastiera e annunciato', async ( { page } ) => {
		await page.route( /youtube\.com/, ( route ) => route.fulfill( { status: 200, body: '' } ) );
		await page.goto( FIXTURE_RAW );

		const region = page.locator( '.dbcm-iframe-placeholder[role="region"]' ).first();
		await expect( region ).toHaveAttribute( 'aria-label', /.+/ );

		// Il pulsante "Carica" è il primo elemento raggiungibile via Tab e si
		// attiva con Enter.
		await page.keyboard.press( 'Tab' );
		await expect( page.locator( '.dbcm-iframe-placeholder__load' ).first() ).toBeFocused();
		await page.keyboard.press( 'Enter' );
		await expect( page.locator( 'iframe[src*="youtube.com/embed"]' ) ).toHaveCount( 1 );
	} );

} );

test.describe( 'Cancellazione reattiva', () => {

	test( 'un cookie in lista cleanup viene rimosso senza consenso', async ( { page, context } ) => {
		// La firma baseline della fixture marca '_mypix' come marketing +
		// reactive_cleanup: senza consenso marketing banner.js lo elimina al load.
		await context.addCookies( [ {
			name: '_mypix',
			value: '1',
			url: process.env.WP_BASE_URL || 'http://localhost:8888',
		} ] );

		await page.goto( FIXTURE_RAW );

		await expect.poll( async () => {
			const cookies = await context.cookies();
			return cookies.some( ( c ) => c.name === '_mypix' );
		} ).toBe( false );
	} );

} );
