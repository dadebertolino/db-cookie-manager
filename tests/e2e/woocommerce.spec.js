// @ts-check
const { test, expect } = require( '@playwright/test' );
const { hasCookiePrefix } = require( './helpers' );

/**
 * WooCommerce funzionante con consenso NEGATO — spec §9.1, §9.2.
 *
 * Il carrello e la sessione WC usano cookie tecnici (functional): devono
 * restare operativi anche se l'utente rifiuta statistics/marketing.
 */
test.describe( 'WooCommerce sopravvive al rifiuto del consenso', () => {

	test.beforeEach( async ( { context } ) => {
		await context.clearCookies();
	} );

	test( '§9.1 — il carrello mantiene il prodotto dopo il rifiuto', async ( { page, context } ) => {
		// Scheda prodotto per slug (creato dal setup con slug fisso).
		await page.goto( '/prodotto-test-dbcm/', { waitUntil: 'domcontentloaded' } );

		// Il pulsante "aggiungi al carrello" della scheda prodotto singola.
		const addBtn = page.locator( '.single_add_to_cart_button' );
		await expect(
			addBtn,
			'La scheda prodotto deve mostrare il pulsante aggiungi al carrello.'
		).toBeVisible( { timeout: 15000 } );
		await addBtn.click();
		await page.waitForLoadState( 'domcontentloaded' );

		// Il cookie di sessione WooCommerce deve esistere (tecnico, functional).
		const hasSession = await hasCookiePrefix( context, 'wp_woocommerce_session_' );
		expect(
			hasSession,
			'La sessione WooCommerce (cookie tecnico) deve esistere anche senza consenso.'
		).toBeTruthy();

		// Il carrello deve contenere l'articolo.
		await page.goto( '/cart/', { waitUntil: 'domcontentloaded' } );
		const cartItems = await page.locator(
			'.woocommerce-cart-form .cart_item, .wc-block-cart-items__row'
		).count();
		expect( cartItems, 'Il carrello deve contenere almeno un articolo.' ).toBeGreaterThan( 0 );
	} );

} );
