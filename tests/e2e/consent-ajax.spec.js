// @ts-check
const { test, expect } = require( '@playwright/test' );
const { FIXTURE_WP, ADMIN_STATE, resetState, getState } = require( './helpers' );

/**
 * Endpoint AJAX dbcm_set_consent (DBCM_Consent_API::ajax_set_consent).
 *
 * Anonimi (3.7.1): niente nonce, controllo di origine + rate limit per IP.
 * Loggati: nonce obbligatorio. In entrambi i casi il payload è ridotto alle
 * 5 categorie standard, con 'functional' sempre true.
 */

const BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8888';
const AJAX = '/wp-admin/admin-ajax.php';

/**
 * @param {object} consent
 * @param {object} [extra] Campi aggiuntivi del form (type, nonce, ...).
 */
function form( consent, extra = {} ) {
	return {
		action: 'dbcm_set_consent',
		type: 'custom',
		consent: JSON.stringify( consent ),
		...extra,
	};
}

test.beforeEach( async ( { request } ) => {
	await resetState( request );
} );

test.describe( 'Visitatore anonimo', () => {

	test( 'stessa origine: consenso accettato e registrato', async ( { request } ) => {
		const res = await request.post( AJAX, {
			form: form( { statistics: true }, { type: 'accept_all' } ),
			headers: { Origin: BASE_URL },
		} );
		expect( res.status() ).toBe( 200 );
		expect( ( await res.json() ).success ).toBe( true );

		const state = await getState( request );
		expect( state.log ).toBe( 1 );
		expect( state.last_log.type ).toBe( 'accept_all' );
	} );

	test( 'il Referer dello stesso sito basta in assenza di Origin', async ( { request } ) => {
		const res = await request.post( AJAX, {
			form: form( {} ),
			headers: { Referer: `${ BASE_URL }/qualsiasi-pagina/` },
		} );
		expect( res.status() ).toBe( 200 );
	} );

	test( 'payload sanificato: solo le 5 categorie, functional sempre true', async ( { request } ) => {
		await request.post( AJAX, {
			form: form(
				{ functional: false, marketing: 'yes', statistics: 0, evil: true },
				{ type: 'hack' }
			),
			headers: { Origin: BASE_URL },
		} );

		const { last_log: last } = await getState( request );
		expect( last.type ).toBe( 'custom' );
		expect( last.consent ).toEqual( {
			v: 3,
			cv: 1,
			functional: true,
			preferences: false,
			statistics: false,
			'statistics-anonymous': false,
			marketing: true,
		} );
	} );

	test( 'origine estranea: 403 bad_origin, nulla registrato', async ( { request } ) => {
		const res = await request.post( AJAX, {
			form: form( { marketing: true } ),
			headers: { Origin: 'https://evil.example' },
		} );
		expect( res.status() ).toBe( 403 );
		expect( ( await res.json() ).data.code ).toBe( 'bad_origin' );
		expect( ( await getState( request ) ).log ).toBe( 0 );
	} );

	test( 'senza Origin né Referer: 403', async ( { request } ) => {
		const res = await request.post( AJAX, { form: form( { marketing: true } ) } );
		expect( res.status() ).toBe( 403 );
		expect( ( await getState( request ) ).log ).toBe( 0 );
	} );

	test( 'oltre il limite per IP: 429 rate_limited', async ( { request } ) => {
		await resetState( request, { rate_limit: 2 } );
		const send = () => request.post( AJAX, { form: form( {} ), headers: { Origin: BASE_URL } } );

		expect( ( await send() ).status() ).toBe( 200 );
		expect( ( await send() ).status() ).toBe( 200 );
		const blocked = await send();
		expect( blocked.status() ).toBe( 429 );
		expect( ( await blocked.json() ).data.code ).toBe( 'rate_limited' );
		expect( ( await getState( request ) ).log ).toBe( 2 );
	} );

} );

test.describe( 'Utente loggato', () => {
	test.use( { storageState: ADMIN_STATE } );

	test( 'senza nonce la richiesta è rifiutata', async ( { page } ) => {
		const res = await page.request.post( AJAX, {
			form: form( { marketing: true } ),
			headers: { Origin: BASE_URL },
		} );
		expect( res.status() ).toBe( 403 );
		expect( ( await getState( page.request ) ).log ).toBe( 0 );
	} );

	test( 'con il nonce della pagina la richiesta è accettata', async ( { page } ) => {
		await page.route( ( url ) => ! url.href.startsWith( BASE_URL ), ( route ) => route.abort() );
		await page.goto( FIXTURE_WP );
		const nonce = await page.evaluate( () => window.dbcmBanner.nonce );

		const res = await page.request.post( AJAX, {
			form: form( { marketing: true }, { nonce } ),
			headers: { Origin: BASE_URL },
		} );
		expect( res.status() ).toBe( 200 );
		expect( ( await getState( page.request ) ).log ).toBe( 1 );
	} );
} );
