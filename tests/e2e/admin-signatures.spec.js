// @ts-check
const fs = require( 'fs' );
const { test, expect } = require( '@playwright/test' );
const { FIXTURE_WP, ADMIN_STATE, resetState, interceptThirdParty } = require( './helpers' );

/**
 * Firme personalizzate: aggiunta, modifica, eliminazione, import/export e
 * effetto reale sul blocco del sito.
 *
 * Baseline della fixture: una firma "E2E Pixel" (marketing, cookie _mypix).
 */

const SIG_PAGE = '/wp-admin/admin.php?page=dbcm-signatures';
const ROWS = '.dbcm-wrap table.db-ui-table tbody tr';

test.use( { storageState: ADMIN_STATE } );

test.beforeEach( async ( { request } ) => {
	await resetState( request );
} );

/**
 * Compila e invia il form di aggiunta firma.
 *
 * @param {import('@playwright/test').Page} page
 * @param {{service: string, category?: string, cookie?: string, block?: string}} sig
 */
async function addSignature( page, sig ) {
	await page.goto( SIG_PAGE );
	await page.locator( '#dbcm-sig_service' ).fill( sig.service );
	if ( sig.category ) {
		await page.locator( '#dbcm-sig_category' ).selectOption( sig.category );
	}
	if ( sig.cookie ) {
		await page.locator( 'input[name="sig_cookies[]"]' ).first().fill( sig.cookie );
	}
	if ( sig.block ) {
		await page.locator( '#dbcm-sig_block_source' ).fill( sig.block );
	}
	await page.locator( '#dbcm-signatures-form button[type="submit"]' ).click();
	await page.waitForLoadState( 'domcontentloaded' );
}

test( 'la firma baseline è elencata', async ( { page } ) => {
	await page.goto( SIG_PAGE );
	await expect( page.locator( ROWS ) ).toHaveCount( 1 );
	await expect( page.locator( ROWS ).first() ).toContainText( 'E2E Pixel' );
	await expect( page.locator( ROWS ).first() ).toContainText( '_mypix' );
} );

test( 'una nuova firma con fonte di blocco blocca il servizio sul sito', async ( { page, browser } ) => {
	await addSignature( page, {
		service: 'Widget Test',
		category: 'marketing',
		cookie: '_wt',
		block: 'widget.example.test',
	} );

	await expect( page.locator( '.notice-success' ) ).toContainText( 'Firma personalizzata salvata.' );
	await expect( page.locator( ROWS ) ).toHaveCount( 2 );
	await expect( page.locator( ROWS, { hasText: 'Widget Test' } ) ).toContainText( '_wt' );

	// Visitatore anonimo: l'embed del servizio è sostituito da un placeholder.
	const visitor = await browser.newContext( { storageState: { cookies: [], origins: [] } } );
	const front = await visitor.newPage();
	const hits = await interceptThirdParty( front );
	await front.goto( `${ FIXTURE_WP }&dbcm_widget=1`, { waitUntil: 'networkidle' } );

	await expect( front.locator( '#fixture-widget' ) ).toHaveCount( 0 );
	await expect( front.locator( '.dbcm-iframe-placeholder[data-dbcm-src*="widget.example.test"]' ) ).toHaveCount( 1 );
	expect( hits.filter( ( u ) => u.includes( 'widget.example.test' ) ) ).toHaveLength( 0 );
	await visitor.close();
} );

test( 'senza nome del servizio la firma non viene salvata', async ( { page } ) => {
	await addSignature( page, { service: '', cookie: '_x' } );

	await expect( page.locator( '.notice-error' ) ).toContainText( 'Dati della firma non validi' );
	await expect( page.locator( ROWS ) ).toHaveCount( 1 );
} );

test( 'modifica: la categoria cambia senza duplicare la firma', async ( { page } ) => {
	await page.goto( SIG_PAGE );
	await page.locator( ROWS ).first().getByRole( 'link', { name: 'Modifica' } ).click();

	await expect( page.locator( '#dbcm-sig_service' ) ).toHaveValue( 'E2E Pixel' );
	await page.locator( '#dbcm-sig_category' ).selectOption( 'statistics' );
	await page.locator( '#dbcm-signatures-form button[type="submit"]' ).click();

	await expect( page.locator( '.notice-success' ) ).toContainText( 'Firma personalizzata salvata.' );
	await expect( page.locator( ROWS ) ).toHaveCount( 1 );
	await expect( page.locator( ROWS ).first() ).toContainText( 'statistics' );
} );

test( 'eliminazione con conferma', async ( { page } ) => {
	await page.goto( SIG_PAGE );
	page.once( 'dialog', ( d ) => d.accept() );
	await page.locator( ROWS ).first().getByRole( 'link', { name: 'Elimina' } ).click();

	await expect( page.locator( '.notice-success' ) ).toContainText( 'Firma personalizzata eliminata.' );
	await expect( page.getByText( 'Nessuna firma personalizzata.' ) ).toBeVisible();
} );

test( 'eliminare una firma già eliminata mostra un errore (regressione 3.8.1)', async ( { page } ) => {
	await page.goto( SIG_PAGE );
	const href = await page.locator( ROWS ).first().getByRole( 'link', { name: 'Elimina' } ).getAttribute( 'href' );

	await page.goto( href );
	await expect( page.locator( '.notice-success' ) ).toContainText( 'Firma personalizzata eliminata.' );

	// Stesso link, per esempio da una seconda scheda rimasta aperta.
	await page.goto( href );
	await expect( page.locator( '.notice-error' ) ).toContainText( 'Firma non trovata' );
} );

test( 'avviso su espressione regolare non valida', async ( { page } ) => {
	await page.goto( SIG_PAGE );
	await page.locator( '#dbcm-sig_block_is_regex' ).check();
	const warning = page.locator( '#dbcm-regex-warning' );

	await page.locator( '#dbcm-sig_block_source' ).fill( '([' );
	await expect( warning ).toBeVisible();

	await page.locator( '#dbcm-sig_block_source' ).fill( '^https://pixel\\.example\\.' );
	await expect( warning ).toBeHidden();
} );

test.describe( 'Import / Export', () => {

	test( 'l\'export scarica le firme in JSON', async ( { page } ) => {
		await page.goto( SIG_PAGE );
		const download = page.waitForEvent( 'download' );
		await page.getByRole( 'button', { name: 'Esporta JSON' } ).click();
		const file = await download;

		expect( file.suggestedFilename() ).toMatch( /^dbcm-signatures-\d{8}\.json$/ );
		const data = JSON.parse( fs.readFileSync( await file.path(), 'utf8' ) );
		expect( data.signatures ).toHaveLength( 1 );
		expect( data.signatures[ 0 ] ).toMatchObject( { service: 'E2E Pixel', category: 'marketing' } );
	} );

	test( 'l\'import sostituisce le firme esistenti', async ( { page } ) => {
		await page.goto( SIG_PAGE );
		await page.locator( 'input[name="dbcm_import_file"]' ).setInputFiles( {
			name: 'firme.json',
			mimeType: 'application/json',
			buffer: Buffer.from( JSON.stringify( {
				signatures: [ { service: 'Importata', category: 'statistics', cookies: [ '_imp' ] } ],
			} ) ),
		} );
		await page.getByRole( 'button', { name: 'Importa JSON' } ).click();

		await expect( page.locator( '.notice-success' ) ).toContainText( 'Firme importate con successo.' );
		await expect( page.locator( ROWS ) ).toHaveCount( 1 );
		await expect( page.locator( ROWS ).first() ).toContainText( 'Importata' );
	} );

	test( 'un file non valido non tocca le firme', async ( { page } ) => {
		await page.goto( SIG_PAGE );
		await page.locator( 'input[name="dbcm_import_file"]' ).setInputFiles( {
			name: 'rotto.json',
			mimeType: 'application/json',
			buffer: Buffer.from( '{ non è json' ),
		} );
		await page.getByRole( 'button', { name: 'Importa JSON' } ).click();

		await expect( page.locator( '.notice-error' ) ).toContainText( 'Import fallito' );
		await expect( page.locator( ROWS ).first() ).toContainText( 'E2E Pixel' );
	} );

} );
