// @ts-check
const { test, expect } = require( '@playwright/test' );

/**
 * Test per feature ANCORA DA IMPLEMENTARE (spec §3, §9.4, §9.8).
 *
 * Sono marcati test.skip di proposito: la specifica è già scritta come test
 * eseguibile (TDD), ma il codice non c'è ancora. Man mano che implementiamo
 * placeholder click-to-load, navigazione da tastiera e cancellazione reattiva,
 * si toglie lo .skip feature per feature. Così la CI resta verde e il debito
 * è esplicito e tracciato, invece di essere un test rosso che tutti ignorano.
 */
test.describe( 'Placeholder click-to-load (§3, §9.4) — DA IMPLEMENTARE', () => {

	test.skip( '§9.4 — gli embed YouTube/Maps sono sostituiti da placeholder', async ( { page, context } ) => {
		await context.clearCookies();
		await page.goto( '/dbcm-test/' );

		// Atteso, una volta implementato: l'iframe è sostituito da un placeholder
		// accessibile con lo stesso ingombro.
		const placeholders = await page.locator( '.dbcm-iframe-placeholder' ).count();
		expect( placeholders ).toBeGreaterThanOrEqual( 2 ); // YouTube + Maps
	} );

	test.skip( '§9.4 — click-to-load carica il contenuto e registra consenso granulare', async ( { page, context } ) => {
		await context.clearCookies();
		await page.goto( '/dbcm-test/' );

		const loadBtn = page.locator( '.dbcm-iframe-placeholder__load' ).first();
		await loadBtn.click();

		// Atteso: l'iframe reale viene inserito.
		await expect( page.locator( 'iframe[src*="youtube"]' ).first() ).toBeVisible();
	} );

	test.skip( '§9.8 — il placeholder è navigabile da tastiera e annunciato', async ( { page, context } ) => {
		await context.clearCookies();
		await page.goto( '/dbcm-test/' );

		const region = page.locator( '.dbcm-iframe-placeholder[role="region"]' ).first();
		await expect( region ).toHaveAttribute( 'aria-label', /.+/ );

		// Il pulsante deve essere raggiungibile via Tab e attivabile con Enter.
		await page.keyboard.press( 'Tab' );
		const focused = await page.evaluate( () => document.activeElement?.className || '' );
		expect( focused ).toContain( 'dbcm-iframe-placeholder' );
	} );

} );

test.describe( 'Cancellazione reattiva (§ aggiunta manuale) — DA IMPLEMENTARE', () => {

	test( 'un cookie in lista cleanup viene rimosso senza consenso', async ( { page, context } ) => {
		// Un cookie marcato per la cancellazione reattiva viene eliminato al
		// load se manca il consenso della sua categoria. La pagina fixture
		// /dbcm-test/ carica banner.js con la config reactiveCleanup, che
		// include _mypix (firma custom scritta dalla fixture).
		await context.clearCookies();
		await context.addCookies( [ {
			name: '_mypix',
			value: '1',
			url: process.env.WP_BASE_URL || 'http://localhost:8888',
		} ] );
		await page.goto( '/dbcm-test/' );
		await page.waitForTimeout( 800 );
		const cookies = await context.cookies();
		expect( cookies.find( ( c ) => c.name === '_mypix' ) ).toBeUndefined();
	} );

} );