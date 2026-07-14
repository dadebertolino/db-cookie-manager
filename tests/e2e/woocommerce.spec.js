// @ts-check
const { test, expect } = require( '@playwright/test' );
const { hasCookiePrefix } = require( './helpers' );

/**
 * WooCommerce funzionante con consenso NEGATO — spec §9.1, §9.2.
 *
 * Il carrello e la sessione WC usano cookie tecnici (functional): devono
 * restare operativi anche se l'utente rifiuta statistics/marketing. È il
 * requisito che ha motivato la whitelist dei cookie tecnici nel db firme.
 */
test.describe( 'WooCommerce sopravvive al rifiuto del consenso', () => {

	test.beforeEach( async ( { context } ) => {
		await context.clearCookies();
	} );

	test( '§9.1 — il carrello mantiene il prodotto dopo il rifiuto', async ( { page, context } ) => {
		// 1. Vai alla scheda prodotto di test.
		await page.goto( '/?product=prodotto-test-dbcm' );
		// Fallback: se il permalink prodotto differisce, passa dallo shop.
		if ( ! ( await page.locator( '.single_add_to_cart_button' ).count() ) ) {
			await page.goto( '/shop/' );
			await page.locator( 'a.add_to_cart_button' ).first().click();
			await page.waitForLoadState( 'networkidle' );
		} else {
			await page.locator( '.single_add_to_cart_button' ).click();
			await page.waitForLoadState( 'networkidle' );
		}

		// 2. Il cookie di sessione WooCommerce deve essere presente (tecnico).
		const hasSession = await hasCookiePrefix( context, 'wp_woocommerce_session_' );
		expect( hasSession, 'La sessione WooCommerce (cookie tecnico) deve esistere.' ).toBeTruthy();

		// 3. Il carrello deve contenere l'articolo.
		await page.goto( '/cart/' );
		const cartItems = await page.locator( '.woocommerce-cart-form .cart_item, .wc-block-cart-items__row' ).count();
		expect( cartItems, 'Il carrello deve contenere almeno un articolo.' ).toBeGreaterThan( 0 );
	} );

} );
