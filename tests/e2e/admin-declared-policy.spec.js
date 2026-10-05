// @ts-check
const { test, expect } = require( '@playwright/test' );
const { FIXTURE_WP, ADMIN_STATE, resetState, getState, interceptThirdParty } = require( './helpers' );

/**
 * Servizi dichiarati (registro dei servizi previo consenso) e Cookie Policy
 * generata: i servizi bloccati, che lo scanner non può vedere, devono
 * comunque finire nella policy.
 */

const DECLARED_PAGE = '/wp-admin/admin.php?page=dbcm-declared';
const POLICY_PAGE = '/wp-admin/admin.php?page=dbcm-policy';
const DECLARED_ROWS = '.dbcm-wrap table.widefat tbody tr';

test.use( { storageState: ADMIN_STATE } );

test.beforeEach( async ( { request } ) => {
	await resetState( request );
} );

/**
 * Testo HTML della policy generata (campo "codice HTML" della pagina Policy).
 *
 * @param {import('@playwright/test').Page} page
 */
async function policyHtml( page ) {
	await page.goto( POLICY_PAGE );
	return page.locator( '#dbcm-policy-raw' ).inputValue();
}

test.describe( 'Servizi dichiarati', () => {

	test( 'una voce manuale compare nel registro e nella Cookie Policy', async ( { page } ) => {
		await page.goto( DECLARED_PAGE );
		await page.locator( '#dbcm-declared-service' ).fill( 'Servizio Test E2E' );
		await page.locator( '#dbcm-declared-provider' ).fill( 'Fornitore Test' );
		await page.locator( '#dbcm-declared-category' ).selectOption( 'statistics' );
		await page.locator( '#dbcm-declared-cookies' ).fill( '_ste2e' );
		await page.getByRole( 'button', { name: 'Aggiungi al registro' } ).click();

		await expect( page.locator( '.notice-success' ) ).toContainText( 'Servizio dichiarato aggiunto' );
		const row = page.locator( DECLARED_ROWS, { hasText: 'Servizio Test E2E' } );
		await expect( row ).toContainText( 'Manuale' );
		await expect( row ).toContainText( 'Fornitore Test' );

		const html = await policyHtml( page );
		expect( html ).toContain( 'Servizio Test E2E' );
		expect( html ).toContain( '_ste2e' );
	} );

	test( 'senza nome del servizio la voce non viene aggiunta', async ( { page } ) => {
		await page.goto( DECLARED_PAGE );
		await page.getByRole( 'button', { name: 'Aggiungi al registro' } ).click();

		await expect( page.locator( '.notice-error' ) ).toContainText( 'il nome del servizio è obbligatorio' );
	} );

	test( 'eliminazione, e secondo tentativo con errore specifico (regressione 3.8.1)', async ( { page } ) => {
		await page.goto( DECLARED_PAGE );
		await page.locator( '#dbcm-declared-service' ).fill( 'Da eliminare' );
		await page.getByRole( 'button', { name: 'Aggiungi al registro' } ).click();

		const href = await page.locator( DECLARED_ROWS, { hasText: 'Da eliminare' } )
			.getByRole( 'link', { name: 'Elimina' } ).getAttribute( 'href' );

		await page.goto( href );
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Voce dichiarata eliminata.' );
		await expect( page.locator( DECLARED_ROWS, { hasText: 'Da eliminare' } ) ).toHaveCount( 0 );

		await page.goto( href );
		await expect( page.locator( '.notice-error' ) ).toContainText( 'Eliminazione non riuscita' );
	} );

	test( 'un embed bloccato sul sito viene registrato in automatico', async ( { page, browser } ) => {
		const visitor = await browser.newContext( { storageState: { cookies: [], origins: [] } } );
		const front = await visitor.newPage();
		await interceptThirdParty( front );
		await front.goto( FIXTURE_WP );
		await visitor.close();

		await page.goto( DECLARED_PAGE );
		const auto = page.locator( DECLARED_ROWS, { hasText: 'Automatica (dal blocco)' } );
		await expect( auto.first() ).toBeVisible();
		await expect( page.locator( DECLARED_ROWS, { hasText: 'YouTube' } ) ).toContainText( 'Automatica (dal blocco)' );

		expect( await policyHtml( page ) ).toContain( 'YouTube' );
	} );

} );

test.describe( 'Cookie Policy', () => {

	test( 'senza scansione la pagina avvisa che la policy sarà incompleta', async ( { page } ) => {
		await page.goto( POLICY_PAGE );
		await expect( page.getByText( 'Non hai ancora eseguito una scansione.' ) ).toBeVisible();
	} );

	test( 'la durata di dbcm_consent nella policy segue l\'impostazione', async ( { page, request } ) => {
		await resetState( request, { settings: { consent_duration: 180 } } );
		const html = await policyHtml( page );

		expect( html ).toContain( 'dbcm_consent' );
		expect( html ).toContain( '180 giorni' );
	} );

	test( 'creazione della pagina: pubblicata, collegata al banner, poi aggiornabile', async ( { page, browser, request } ) => {
		await page.goto( POLICY_PAGE );
		await page.getByRole( 'button', { name: 'Crea la pagina automaticamente' } ).click();

		await expect( page.locator( '.notice-success' ) ).toContainText( 'Pagina della Cookie Policy creata e collegata al banner.' );
		const pageId = ( await getState( request ) ).settings.policy_page_id;
		expect( pageId ).toBeGreaterThan( 0 );

		const publicUrl = await page.getByRole( 'link', { name: 'Visualizza pagina pubblica' } ).getAttribute( 'href' );
		const visitor = await browser.newContext( { storageState: { cookies: [], origins: [] } } );
		const front = await visitor.newPage();
		await interceptThirdParty( front );

		await front.goto( publicUrl );
		await expect( front.getByRole( 'heading', { name: 'Cookie Policy', level: 2 } ) ).toBeVisible();

		// Il banner mostra il link alla policy appena creata.
		await front.goto( FIXTURE_WP );
		await expect( front.locator( '.dbcm-banner__policy a' ) ).toHaveAttribute( 'href', publicUrl );
		await visitor.close();

		// Secondo passaggio: aggiorna la stessa pagina, non ne crea un'altra.
		await page.getByRole( 'button', { name: 'Aggiorna pagina con il testo generato' } ).click();
		await expect( page.locator( '.notice-success' ) ).toContainText( 'Pagina della Cookie Policy aggiornata.' );
		expect( ( await getState( request ) ).settings.policy_page_id ).toBe( pageId );
	} );

} );
