// @ts-check
const { test, expect } = require( '@playwright/test' );
const { ADMIN_STATE, resetState } = require( './helpers' );

/**
 * Scanner: flusso dell'interfaccia (avvio, avanzamento, risultati),
 * correzione manuale della categoria, eliminazione di un cookie.
 *
 * In wp-env la richiesta del server verso se stesso (localhost:8888) di
 * norma non arriva: la scansione si completa con i soli cookie WordPress e
 * del plugin, inseriti sempre. Basta per verificare il flusso.
 */

const SCANNER_PAGE = '/wp-admin/admin.php?page=dbcm-scanner';
const ROWS = 'tr[data-cookie-id]';

test.use( { storageState: ADMIN_STATE } );

test.beforeEach( async ( { request } ) => {
	await resetState( request );
} );

/**
 * Avvia la scansione dall'interfaccia e attende il ricaricamento con
 * l'avviso finale.
 *
 * @param {import('@playwright/test').Page} page
 */
async function runScan( page ) {
	await page.goto( SCANNER_PAGE );
	await page.locator( '#dbcm-scan-start' ).click();
	await expect( page.locator( '#dbcm-scan-progress-wrap' ) ).toBeVisible();
	await page.waitForURL( /dbcm_msg=scan_done/, { timeout: 60000 } );
}

/**
 * Riga dei risultati di un cookie, per nome esatto.
 *
 * @param {import('@playwright/test').Page} page
 * @param {string} name
 */
function cookieRow( page, name ) {
	return page.locator( ROWS, { has: page.locator( 'code', { hasText: new RegExp( `^${ name.replace( /[*]/g, '\\*' ) }$` ) } ) } );
}

test( 'prima della scansione la pagina mostra lo stato vuoto', async ( { page } ) => {
	await page.goto( SCANNER_PAGE );
	await expect( page.getByText( 'Nessuna scansione eseguita ancora' ) ).toBeVisible();
	await expect( page.locator( ROWS ) ).toHaveCount( 0 );
} );

test( 'la scansione si completa con avviso e risultati (regressione 3.8.1)', async ( { page } ) => {
	test.setTimeout( 90000 );
	await runScan( page );

	await expect( page.locator( '.notice-success' ) ).toContainText( 'Scansione completata.' );
	await expect( cookieRow( page, 'dbcm_consent' ) ).toHaveCount( 1 );
	await expect( cookieRow( page, 'wordpress_logged_in_*' ) ).toHaveCount( 1 );
	await expect( page.getByText( 'Nessuna scansione eseguita ancora' ) ).toHaveCount( 0 );
} );

test( 'la durata di dbcm_consent segue l\'impostazione (regressione 3.8.1)', async ( { page, request } ) => {
	test.setTimeout( 90000 );
	await resetState( request, { settings: { consent_duration: 180 } } );
	await runScan( page );

	await expect( cookieRow( page, 'dbcm_consent' ) ).toContainText( '180 giorni' );
} );

test( 'la categoria corretta a mano resta dopo il ricaricamento', async ( { page } ) => {
	test.setTimeout( 90000 );
	await runScan( page );

	const select = cookieRow( page, 'wp-settings-*' ).locator( 'select.dbcm-cookie-cat' );
	const saved = page.waitForResponse( ( r ) => r.url().includes( 'admin-ajax.php' ) );
	await select.selectOption( 'preferences' );
	expect( ( await saved ).status() ).toBe( 200 );

	await page.reload();
	await expect( cookieRow( page, 'wp-settings-*' ).locator( 'select.dbcm-cookie-cat' ) ).toHaveValue( 'preferences' );
} );

test( 'eliminazione di un cookie con conferma', async ( { page } ) => {
	test.setTimeout( 90000 );
	await runScan( page );

	page.once( 'dialog', ( d ) => d.accept() );
	await cookieRow( page, 'wordpress_test_cookie' ).locator( 'button.dbcm-cookie-delete' ).click();
	await expect( cookieRow( page, 'wordpress_test_cookie' ) ).toHaveCount( 0 );

	await page.reload();
	await expect( cookieRow( page, 'wordpress_test_cookie' ) ).toHaveCount( 0 );
} );

test( 'override ed eliminazione di un id inesistente rispondono 404 (regressione 3.8.1)', async ( { page } ) => {
	await page.goto( SCANNER_PAGE );

	const statuses = await page.evaluate( async () => {
		const send = ( action, extra ) => {
			const body = new FormData();
			body.append( 'action', action );
			body.append( 'nonce', window.dbcmAdmin.scannerNonce );
			Object.keys( extra ).forEach( ( k ) => body.append( k, extra[ k ] ) );
			return fetch( window.dbcmAdmin.ajaxUrl, { method: 'POST', body, credentials: 'same-origin' } ).then( ( r ) => r.status );
		};
		return {
			override: await send( 'dbcm_cookie_override', { id: '999999', category: 'marketing' } ),
			remove: await send( 'dbcm_cookie_delete', { id: '999999' } ),
		};
	} );

	expect( statuses ).toEqual( { override: 404, remove: 404 } );
} );

test( 'senza nonce valido le azioni AJAX sono rifiutate', async ( { page } ) => {
	await page.goto( SCANNER_PAGE );

	const status = await page.evaluate( async () => {
		const body = new FormData();
		body.append( 'action', 'dbcm_scan_prepare' );
		body.append( 'nonce', 'nonvalido' );
		const r = await fetch( window.dbcmAdmin.ajaxUrl, { method: 'POST', body, credentials: 'same-origin' } );
		return r.status;
	} );

	expect( status ).toBe( 403 );
} );
