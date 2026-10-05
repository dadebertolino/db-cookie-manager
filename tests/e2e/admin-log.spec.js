// @ts-check
const { test, expect } = require( '@playwright/test' );
const { ADMIN_STATE, resetState } = require( './helpers' );

/**
 * Pagina Registro consensi: filtri, paginazione (25 per pagina), totale.
 * Il registro è popolato dal seed della fixture, non dal banner.
 */

const LOG_PAGE = '/wp-admin/admin.php?page=dbcm-log';
const ROWS = '.dbcm-wrap table.db-ui-table tbody tr';

/** Data locale YYYY-MM-DD di N giorni fa (il filtro usa giorni interi). */
function daysAgo( n ) {
	const d = new Date( Date.now() - n * 86400000 );
	return d.toISOString().slice( 0, 10 );
}

test.use( { storageState: ADMIN_STATE } );

test.beforeEach( async ( { request } ) => {
	await resetState( request, {
		seed_log: [
			{ type: 'accept_all', consent: { marketing: true }, count: 20 },
			{ type: 'reject_all', count: 8 },
			{ type: 'custom', consent: { statistics: true }, count: 2, days_ago: 40 },
		],
	} );
} );

test( 'paginazione: 25 righe per pagina, la seconda contiene il resto', async ( { page } ) => {
	await page.goto( LOG_PAGE );
	await expect( page.locator( ROWS ) ).toHaveCount( 25 );
	await expect( page.getByText( /pagina 1 di 2/ ) ).toBeVisible();

	await page.getByRole( 'link', { name: /Successiva/ } ).click();
	await expect( page.locator( ROWS ) ).toHaveCount( 5 );
	await expect( page.getByText( /pagina 2 di 2/ ) ).toBeVisible();
} );

test( 'filtro per tipo: solo i rifiuti, il filtro resta nei link', async ( { page } ) => {
	await page.goto( LOG_PAGE );
	await page.locator( '#dbcm-filter-type' ).selectOption( 'reject_all' );
	await page.getByRole( 'button', { name: 'Applica filtri' } ).click();

	await expect( page.locator( ROWS ) ).toHaveCount( 8 );
	for ( const badge of await page.locator( `${ ROWS } .db-ui-badge` ).allTextContents() ) {
		expect( badge ).toBe( 'Rifiuta tutto' );
	}
	await expect( page.getByRole( 'link', { name: 'Pulisci' } ) ).toBeVisible();
	await expect( page.getByRole( 'link', { name: 'Scarica CSV' } ) ).toHaveAttribute( 'href', /type=reject_all/ );
} );

test( 'filtro con tipo sconosciuto: nessuna riga (regressione 3.8.1)', async ( { page } ) => {
	await page.goto( `${ LOG_PAGE }&type=inventato` );

	await expect( page.locator( ROWS ) ).toHaveCount( 0 );
	await expect( page.getByText( 'Nessun consenso trovato per i filtri applicati.' ) ).toBeVisible();
} );

test( 'filtro per data: esclude le righe più vecchie', async ( { page } ) => {
	await page.goto( `${ LOG_PAGE }&date_from=${ daysAgo( 7 ) }` );
	await expect( page.getByText( /di 28 consensi/ ) ).toBeVisible();

	await page.goto( `${ LOG_PAGE }&date_to=${ daysAgo( 30 ) }` );
	await expect( page.locator( ROWS ) ).toHaveCount( 2 );
} );

test( '"Consensi totali" conta l\'intero registro anche con un filtro (regressione 3.8.1)', async ( { page } ) => {
	await page.goto( `${ LOG_PAGE }&type=reject_all` );

	const total = page.locator( '.db-ui-stat', { hasText: 'Consensi totali' } ).locator( '.db-ui-stat-value' );
	await expect( total ).toHaveText( '30' );
} );

test( 'le statistiche a 30 giorni escludono le righe più vecchie', async ( { page } ) => {
	await page.goto( LOG_PAGE );

	const stat = ( label ) => page.locator( '.db-ui-stat', { hasText: label } ).locator( '.db-ui-stat-value' );
	await expect( stat( 'Accetta tutto (30gg)' ) ).toHaveText( '20' );
	await expect( stat( 'Rifiuta tutto (30gg)' ) ).toHaveText( '8' );
	await expect( stat( 'Personalizzato (30gg)' ) ).toHaveText( '0' );
} );
