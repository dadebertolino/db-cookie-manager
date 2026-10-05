// @ts-check
const { test, expect } = require( '@playwright/test' );
const { FIXTURE_WP, getConsentCookie, resetState, interceptThirdParty, setConsentCookie } = require( './helpers' );

/**
 * Banner su telefono (progetto "mobile" di playwright.config.js: Pixel 7,
 * 412×915, touch). Verifica che banner, modal, placeholder e pulsante 🍪
 * restino nello schermo, si usino al tocco e non creino scroll orizzontale.
 */

const BANNER = '#dbcm-banner-root .dbcm-banner[role="dialog"]:not(.dbcm-banner--preferences)';
const PREFS = '#dbcm-banner-root .dbcm-banner--preferences';

/**
 * Verifica che l'elemento sia interamente dentro la viewport.
 *
 * @param {import('@playwright/test').Page} page
 * @param {import('@playwright/test').Locator} locator
 * @param {string} label
 */
async function expectInViewport( page, locator, label ) {
	const box = await locator.boundingBox();
	const vp = page.viewportSize();
	expect( box, `${ label }: non visibile` ).not.toBeNull();
	expect( box.x, `${ label }: esce a sinistra` ).toBeGreaterThanOrEqual( 0 );
	expect( box.y, `${ label }: esce in alto` ).toBeGreaterThanOrEqual( 0 );
	expect( box.x + box.width, `${ label }: esce a destra` ).toBeLessThanOrEqual( vp.width + 1 );
	expect( box.y + box.height, `${ label }: esce in basso` ).toBeLessThanOrEqual( vp.height + 1 );
}

/**
 * Nessuno scroll orizzontale nella pagina.
 *
 * @param {import('@playwright/test').Page} page
 */
async function expectNoHorizontalScroll( page ) {
	const { scroll, viewport } = await page.evaluate( () => ( {
		scroll: document.documentElement.scrollWidth,
		viewport: window.innerWidth,
	} ) );
	expect( scroll, 'scroll orizzontale' ).toBeLessThanOrEqual( viewport );
}

/**
 * Altezza e larghezza minime di un bersaglio al tocco.
 *
 * @param {import('@playwright/test').Locator} locator
 * @param {number} min
 * @param {string} label
 */
async function expectTargetSize( locator, min, label ) {
	const box = await locator.boundingBox();
	expect( box.height, `${ label }: altezza` ).toBeGreaterThanOrEqual( min );
	expect( box.width, `${ label }: larghezza` ).toBeGreaterThanOrEqual( min );
}

test.use( { reducedMotion: 'reduce' } );

test.beforeEach( async ( { page, context, request } ) => {
	await resetState( request );
	await context.clearCookies();
	await interceptThirdParty( page );
} );

test( 'il banner sta nello schermo e i pulsanti sono bersagli da 44 px', async ( { page } ) => {
	await page.goto( FIXTURE_WP );
	const banner = page.locator( BANNER );

	await expectInViewport( page, banner, 'banner' );
	for ( const cls of [ '.dbcm-btn--primary', '.dbcm-btn--secondary', '.dbcm-btn--ghost' ] ) {
		const btn = banner.locator( cls );
		await expectInViewport( page, btn, cls );
		await expectTargetSize( btn, 44, cls );
	}
	await expectNoHorizontalScroll( page );
} );

test( 'al tocco "Accetta tutto" registra il consenso', async ( { page, context } ) => {
	await page.goto( FIXTURE_WP );
	await page.locator( `${ BANNER } .dbcm-btn--primary` ).tap();

	await expect( page.locator( BANNER ) ).toHaveCount( 0 );
	expect( await getConsentCookie( context ) ).toMatchObject( { type: 'accept_all', marketing: true } );
} );

test( 'il layout a barra occupa la larghezza senza uscire dallo schermo', async ( { page, request } ) => {
	await resetState( request, { settings: { banner_layout: 'bar' } } );
	await page.goto( FIXTURE_WP );

	await expectInViewport( page, page.locator( BANNER ), 'barra' );
	await expectNoHorizontalScroll( page );
} );

test( 'il modal preferenze sta nello schermo e "Salva" si raggiunge scorrendo', async ( { page, context } ) => {
	await page.goto( FIXTURE_WP );
	await page.locator( `${ BANNER } .dbcm-btn--ghost` ).tap();
	const prefs = page.locator( PREFS );

	await expectInViewport( page, prefs, 'modal' );
	await expectNoHorizontalScroll( page );

	await prefs.locator( '.dbcm-pref__row[data-category="statistics"] .dbcm-toggle' ).tap();
	const save = prefs.locator( '.dbcm-btn--primary' );
	await save.scrollIntoViewIfNeeded();
	await expectInViewport( page, save, 'Salva' );
	await save.tap();

	expect( await getConsentCookie( context ) ).toMatchObject( { type: 'custom', statistics: true, marketing: false } );
} );

test( 'gli interruttori delle categorie sono bersagli da almeno 24 px (WCAG 2.5.8)', async ( { page } ) => {
	await page.goto( FIXTURE_WP );
	await page.locator( `${ BANNER } .dbcm-btn--ghost` ).tap();

	for ( const cat of [ 'preferences', 'statistics', 'statistics-anonymous', 'marketing' ] ) {
		await expectTargetSize( page.locator( `${ PREFS } .dbcm-pref__row[data-category="${ cat }"] .dbcm-toggle` ), 24, cat );
	}
} );

test( 'il pulsante 🍪 è un bersaglio da 44 px dentro lo schermo', async ( { page, context } ) => {
	await setConsentCookie( context, {} );
	await page.goto( FIXTURE_WP );
	const reopen = page.locator( '.dbcm-reopen' );

	await expectInViewport( page, reopen, 'pulsante 🍪' );
	await expectTargetSize( reopen, 44, 'pulsante 🍪' );
	await reopen.tap();
	await expect( page.locator( PREFS ) ).toBeVisible();
} );

test( 'i placeholder degli embed larghi non escono dallo schermo', async ( { page, context } ) => {
	await setConsentCookie( context, {} );
	await page.goto( FIXTURE_WP );

	const placeholders = page.locator( '.dbcm-iframe-placeholder' );
	await expect( placeholders ).toHaveCount( 2 );
	for ( let i = 0; i < 2; i++ ) {
		const box = await placeholders.nth( i ).boundingBox();
		expect( box.x + box.width, `placeholder ${ i }` ).toBeLessThanOrEqual( page.viewportSize().width + 1 );
		await expectTargetSize( placeholders.nth( i ).locator( '.dbcm-iframe-placeholder__load' ), 44, `"Carica" ${ i }` );
	}
	await expectNoHorizontalScroll( page );
} );

test.describe( 'telefono in orizzontale', () => {
	test.use( { viewport: { width: 915, height: 412 } } );

	test( 'i pulsanti del banner restano raggiungibili', async ( { page } ) => {
		await page.goto( FIXTURE_WP );
		for ( const cls of [ '.dbcm-btn--primary', '.dbcm-btn--secondary', '.dbcm-btn--ghost' ] ) {
			await expectInViewport( page, page.locator( `${ BANNER } ${ cls }` ), cls );
		}
	} );

	test( 'il modal preferenze si scorre fino a "Salva"', async ( { page } ) => {
		await page.goto( FIXTURE_WP );
		await page.locator( `${ BANNER } .dbcm-btn--ghost` ).tap();

		const save = page.locator( `${ PREFS } .dbcm-btn--primary` );
		await save.scrollIntoViewIfNeeded();
		await expectInViewport( page, save, 'Salva' );
	} );
} );
