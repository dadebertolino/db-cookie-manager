// @ts-check
const fs = require( 'fs' );
const { test, expect } = require( '@playwright/test' );
const { FIXTURE_WP, ADMIN_STATE, resetState, getState } = require( './helpers' );

/**
 * Registro consensi (art. 7.1): la scelta fatta da un visitatore nel banner
 * arriva al registro ed è visibile ed esportabile dall'admin.
 */

const BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8888';
const LOG_PAGE = '/wp-admin/admin.php?page=dbcm-log';

/**
 * Un visitatore anonimo apre la pagina e fa una scelta nel banner.
 *
 * @param {import('@playwright/test').Browser} browser
 * @param {string} button Classe del pulsante (.dbcm-btn--primary = accetta).
 */
async function visitorChooses( browser, button ) {
	const context = await browser.newContext();
	const page = await context.newPage();
	await page.route( ( url ) => ! url.href.startsWith( BASE_URL ), ( route ) => route.abort() );
	await page.goto( FIXTURE_WP );
	const sync = page.waitForResponse(
		( r ) => r.url().includes( 'admin-ajax.php' ) && r.request().method() === 'POST'
	);
	await page.locator( `.dbcm-banner ${ button }` ).click();
	await sync;
	await context.close();
}

test.use( { storageState: ADMIN_STATE } );

test.beforeEach( async ( { request } ) => {
	await resetState( request );
} );

test( 'la scelta del visitatore compare nel registro', async ( { browser, page } ) => {
	await visitorChooses( browser, '.dbcm-btn--primary' );

	await page.goto( LOG_PAGE );
	const rows = page.locator( '.dbcm-wrap table.db-ui-table tbody tr' );
	await expect( rows ).toHaveCount( 1 );
	await expect( rows.first().locator( '.db-ui-badge' ) ).toHaveText( 'Accetta tutto' );
	// IP solo come hash troncato, mai in chiaro.
	await expect( rows.first().locator( 'code' ) ).toHaveText( /^[0-9a-f]{12}…$/ );
} );

test( 'con il registro disattivato la scelta non viene salvata', async ( { browser, request } ) => {
	await resetState( request, { settings: { consent_log_enabled: false } } );

	await visitorChooses( browser, '.dbcm-btn--secondary' );

	expect( ( await getState( request ) ).log ).toBe( 0 );
} );

test.describe( 'Export', () => {

	test( 'i link di export puntano alla pagina del registro (regressione 3.8.1)', async ( { page } ) => {
		await page.goto( LOG_PAGE );

		for ( const label of [ 'Scarica CSV', 'Scarica JSON' ] ) {
			const href = await page.getByRole( 'link', { name: label } ).getAttribute( 'href' );
			expect( href, label ).toContain( 'page=dbcm-log' );
			expect( href, label ).not.toContain( 'dbcm-consent-log' );
		}
	} );

	test( 'il CSV contiene intestazione e righe del registro', async ( { page, request } ) => {
		await resetState( request, {
			seed_log: [
				{ type: 'accept_all', consent: { marketing: true }, count: 2 },
				{ type: 'reject_all', count: 1 },
			],
		} );
		await page.goto( LOG_PAGE );

		const download = page.waitForEvent( 'download' );
		await page.getByRole( 'link', { name: 'Scarica CSV' } ).click();
		const file = await download;
		expect( file.suggestedFilename() ).toMatch( /^dbcm-consent-log-.+\.csv$/ );

		const csv = fs.readFileSync( await file.path(), 'utf8' ).replace( /^﻿/, '' );
		const lines = csv.trim().split( /\r?\n/ );
		expect( lines[ 0 ] ).toBe( 'id,date,type,consent,ua_summary,ip_hash,policy_version,consent_version' );
		expect( lines ).toHaveLength( 4 );
	} );

	test( 'il JSON ha l\'envelope documentato', async ( { page, request } ) => {
		await resetState( request, { seed_log: [ { type: 'custom', consent: { statistics: true }, count: 3 } ] } );
		await page.goto( LOG_PAGE );

		const download = page.waitForEvent( 'download' );
		await page.getByRole( 'link', { name: 'Scarica JSON' } ).click();
		const data = JSON.parse( fs.readFileSync( await ( await download ).path(), 'utf8' ) );

		expect( data ).toMatchObject( { schema: 3, count: 3 } );
		expect( data ).toHaveProperty( 'exported_at' );
		expect( data ).toHaveProperty( 'plugin_version' );
		expect( data.records ).toHaveLength( 3 );
		expect( data.records[ 0 ] ).toMatchObject( { type: 'custom', consent: { statistics: true, functional: true } } );
	} );

	test( 'con nonce non valido l\'export mostra un errore chiaro', async ( { page } ) => {
		const res = await page.goto( `${ LOG_PAGE }&dbcm_export=csv&_wpnonce=nonvalido` );
		expect( res.status() ).toBe( 403 );
		await expect( page.locator( 'body' ) ).toContainText( 'Token di sicurezza scaduto' );
	} );

} );
