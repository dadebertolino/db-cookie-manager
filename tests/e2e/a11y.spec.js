// @ts-check
const { test, expect } = require( '@playwright/test' );
const AxeBuilder = require( '@axe-core/playwright' ).default;
const { FIXTURE_WP, resetState, interceptThirdParty, setConsentCookie } = require( './helpers' );

/**
 * Accessibilità del frontend (obiettivo WCAG 2.1 AA): analisi axe-core di
 * banner, modal preferenze, placeholder e pulsante 🍪, più l'uso da tastiera.
 */

const BANNER = '#dbcm-banner-root .dbcm-banner[role="dialog"]:not(.dbcm-banner--preferences)';
const PREFS = '#dbcm-banner-root .dbcm-banner--preferences[role="dialog"]';
const WCAG = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa' ];

/**
 * Violazioni axe nella porzione di pagina indicata, in forma leggibile.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} selector
 */
async function axeViolations( page, selector ) {
	const results = await new AxeBuilder( { page } ).include( selector ).withTags( WCAG ).analyze();
	return results.violations.map( ( v ) => `${ v.id }: ${ v.help } (${ v.nodes.map( ( n ) => n.target.join( ' ' ) ).join( ', ' ) })` );
}

/**
 * True se l'elemento attivo è dentro il selettore indicato.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} selector
 */
function focusIsInside( page, selector ) {
	return page.evaluate( ( sel ) => {
		const box = document.querySelector( sel );
		return !! box && box.contains( document.activeElement );
	}, selector );
}

test.beforeEach( async ( { page, context, request } ) => {
	await resetState( request );
	await context.clearCookies();
	await interceptThirdParty( page );
} );

test.describe( 'Analisi axe-core (WCAG 2.1 A/AA)', () => {

	test( 'banner', async ( { page } ) => {
		await page.goto( FIXTURE_WP );
		await expect( page.locator( BANNER ) ).toBeVisible();
		expect( await axeViolations( page, '#dbcm-banner-root' ) ).toEqual( [] );
	} );

	test( 'banner con tema scuro', async ( { page, request } ) => {
		await resetState( request, { settings: { banner_theme: 'dark' } } );
		await page.goto( FIXTURE_WP );
		await expect( page.locator( BANNER ) ).toBeVisible();
		expect( await axeViolations( page, '#dbcm-banner-root' ) ).toEqual( [] );
	} );

	test( 'modal preferenze', async ( { page } ) => {
		await page.goto( FIXTURE_WP );
		await page.locator( `${ BANNER } .dbcm-btn--ghost` ).click();
		await expect( page.locator( PREFS ) ).toBeVisible();
		expect( await axeViolations( page, '#dbcm-banner-root' ) ).toEqual( [] );
	} );

	test( 'placeholder degli embed bloccati', async ( { page } ) => {
		await page.goto( FIXTURE_WP );
		await page.locator( `${ BANNER } .dbcm-btn--secondary` ).click();
		expect( await axeViolations( page, '.dbcm-iframe-placeholder' ) ).toEqual( [] );
	} );

	test( 'pulsante 🍪', async ( { page, context } ) => {
		await setConsentCookie( context, {} );
		await page.goto( FIXTURE_WP );
		await expect( page.locator( '.dbcm-reopen' ) ).toBeVisible();
		expect( await axeViolations( page, '#dbcm-banner-root' ) ).toEqual( [] );
	} );

} );

test.describe( 'Tastiera', () => {

	test( 'le caselle delle categorie hanno un nome accessibile (regressione 3.8.3)', async ( { page } ) => {
		await page.goto( FIXTURE_WP );
		await page.locator( `${ BANNER } .dbcm-btn--ghost` ).click();

		// Browser in inglese (locale di default di Playwright).
		for ( const name of [ 'Preferences', 'Statistics', 'Marketing' ] ) {
			await expect( page.getByRole( 'checkbox', { name, exact: true } ) ).toBeVisible();
		}
	} );

	test( 'il banner si usa solo da tastiera', async ( { page } ) => {
		await page.goto( FIXTURE_WP );
		await page.locator( `${ BANNER } .dbcm-btn--primary` ).focus();
		await page.keyboard.press( 'Enter' );

		await expect( page.locator( BANNER ) ).toHaveCount( 0 );
		// Il focus non si perde sul <body>: va sul pulsante 🍪.
		await expect( page.locator( '.dbcm-reopen' ) ).toBeFocused();
	} );

	test( 'aprendo le preferenze il focus entra nel modal (regressione 3.8.3)', async ( { page } ) => {
		await page.goto( FIXTURE_WP );
		await page.locator( `${ BANNER } .dbcm-btn--ghost` ).focus();
		await page.keyboard.press( 'Enter' );

		await expect( page.locator( PREFS ) ).toBeVisible();
		expect( await focusIsInside( page, PREFS ) ).toBe( true );

		// Spazio attiva la casella con il focus.
		await page.keyboard.press( 'Space' );
		await expect( page.locator( `${ PREFS } .dbcm-toggle__input` ).first() ).toBeChecked();
	} );

	test( 'Tab e Maiusc+Tab restano dentro il modal (regressione 3.8.3)', async ( { page } ) => {
		await page.goto( FIXTURE_WP );
		await page.locator( `${ BANNER } .dbcm-btn--ghost` ).click();

		for ( let i = 0; i < 10; i++ ) {
			await page.keyboard.press( 'Tab' );
			expect( await focusIsInside( page, PREFS ), `Tab #${ i + 1 }` ).toBe( true );
		}
		for ( let i = 0; i < 10; i++ ) {
			await page.keyboard.press( 'Shift+Tab' );
			expect( await focusIsInside( page, PREFS ), `Maiusc+Tab #${ i + 1 }` ).toBe( true );
		}
	} );

	test( 'Esc senza scelta salvata torna al banner (regressione 3.8.3)', async ( { page, context } ) => {
		await page.goto( FIXTURE_WP );
		await page.locator( `${ BANNER } .dbcm-btn--ghost` ).click();
		await page.keyboard.press( 'Escape' );

		await expect( page.locator( PREFS ) ).toHaveCount( 0 );
		await expect( page.locator( BANNER ) ).toBeVisible();
		expect( await focusIsInside( page, BANNER ) ).toBe( true );
		// Nessuna scelta registrata.
		expect( ( await context.cookies() ).some( ( c ) => 'dbcm_consent' === c.name ) ).toBe( false );
	} );

	test( 'Esc con scelta salvata chiude e riporta il focus sul pulsante 🍪 (regressione 3.8.3)', async ( { page, context } ) => {
		await setConsentCookie( context, { statistics: true } );
		await page.goto( FIXTURE_WP );

		await page.locator( '.dbcm-reopen' ).focus();
		await page.keyboard.press( 'Enter' );
		await expect( page.locator( PREFS ) ).toBeVisible();

		await page.keyboard.press( 'Escape' );
		await expect( page.locator( PREFS ) ).toHaveCount( 0 );
		await expect( page.locator( '.dbcm-reopen' ) ).toBeFocused();
	} );

	test( 'chiudendo le preferenze aperte dallo shortcode il focus torna allo shortcode', async ( { page, context } ) => {
		await setConsentCookie( context, {} );
		await page.goto( FIXTURE_WP );

		await page.locator( '#fixture-prefs' ).focus();
		await page.keyboard.press( 'Enter' );
		await expect( page.locator( PREFS ) ).toBeVisible();

		await page.locator( `${ PREFS } .dbcm-btn--primary` ).focus();
		await page.keyboard.press( 'Enter' );
		await expect( page.locator( '#fixture-prefs' ) ).toBeFocused();
	} );

} );
