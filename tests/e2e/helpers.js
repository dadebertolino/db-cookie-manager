// @ts-check
/**
 * Helper condivisi per gli E2E di DB Cookie Manager.
 */
const path = require( 'path' );

/**
 * Pagine della fixture (tests/fixtures/dbcm-e2e-fixture.php). Query var
 * dirette: non dipendono dalle rewrite rule.
 *  - RAW: HTML grezzo, banner.js con config minima (banner chiuso).
 *  - WP:  passa da wp_head()/wp_footer(), config reale del banner.
 */
const FIXTURE_RAW = '/?dbcm_e2e=1';
const FIXTURE_WP = '/?dbcm_e2e=wp';

/**
 * Sessione admin salvata da auth.setup.js. Da usare negli spec admin con
 * test.use( { storageState: ADMIN_STATE } ).
 */
const ADMIN_STATE = path.join( __dirname, '.auth', 'admin.json' );

const BASE_URL = process.env.WP_BASE_URL || 'http://localhost:8888';

/**
 * Domini di terze parti che NON devono ricevere richieste quando il consenso
 * è negato (spec §9.1). La lista è volutamente ristretta ai servizi presenti
 * nella pagina fixture.
 */
const THIRD_PARTY_HOSTS = [
	'googletagmanager.com',
	'google-analytics.com',
	'connect.facebook.net',
	'youtube.com',
	'youtube-nocookie.com',
	'google.com/maps',
];

/**
 * Registra un listener che accumula le richieste verso host di terze parti.
 * Ritorna un oggetto con .hits (array di URL) da ispezionare dopo il load.
 *
 * @param {import('@playwright/test').Page} page
 */
function trackThirdParty( page ) {
	const state = { hits: [] };
	page.on( 'request', ( req ) => {
		const url = req.url();
		for ( const host of THIRD_PARTY_HOSTS ) {
			if ( url.includes( host ) ) {
				state.hits.push( url );
				break;
			}
		}
	} );
	return state;
}

/**
 * Legge il cookie di consenso DBCM dal contesto del browser.
 *
 * @param {import('@playwright/test').BrowserContext} context
 * @returns {Promise<object|null>}
 */
async function getConsentCookie( context ) {
	const cookies = await context.cookies();
	const c = cookies.find( ( x ) => x.name === 'dbcm_consent' );
	if ( ! c ) {
		return null;
	}
	try {
		return JSON.parse( decodeURIComponent( c.value ) );
	} catch ( e ) {
		return { raw: c.value };
	}
}

/**
 * Verifica la presenza di un cookie per nome (anche parziale/prefisso).
 *
 * @param {import('@playwright/test').BrowserContext} context
 * @param {string} prefix
 * @returns {Promise<boolean>}
 */
async function hasCookiePrefix( context, prefix ) {
	const cookies = await context.cookies();
	return cookies.some( ( c ) => c.name.startsWith( prefix ) );
}

/**
 * Riporta il plugin allo stato baseline via endpoint REST della fixture.
 * Opzioni: settings, signatures, rate_limit, seed_log (vedi
 * dbcm_e2e_reset_state() nella fixture).
 *
 * @param {import('@playwright/test').APIRequestContext} request
 * @param {object} [opts]
 * @returns {Promise<{settings: object, log: number}>}
 */
async function resetState( request, opts = {} ) {
	const res = await request.post( '/?rest_route=/dbcm-e2e/v1/reset', { data: opts } );
	if ( ! res.ok() ) {
		throw new Error( `Reset E2E fallito (HTTP ${ res.status() }): ${ await res.text() }` );
	}
	return res.json();
}

/**
 * Legge lo stato lato server (impostazioni, registro consensi).
 *
 * @param {import('@playwright/test').APIRequestContext} request
 * @returns {Promise<{settings: object, log: number, last_log: ?{type: string, consent: object, consent_version: number}}>}
 */
async function getState( request ) {
	const res = await request.get( '/?rest_route=/dbcm-e2e/v1/state' );
	if ( ! res.ok() ) {
		throw new Error( `Lettura stato E2E fallita (HTTP ${ res.status() }): ${ await res.text() }` );
	}
	return res.json();
}

/**
 * Intercetta ogni richiesta verso terze parti: la registra e risponde con un
 * corpo vuoto, così nulla esce dalla CI ma si vede cosa il browser ha chiesto.
 *
 * @param {import('@playwright/test').Page} page
 * @returns {Promise<string[]>} URL richieste (si riempie durante il test).
 */
async function interceptThirdParty( page ) {
	const hits = [];
	await page.route( ( url ) => ! url.href.startsWith( BASE_URL ), ( route ) => {
		hits.push( route.request().url() );
		return route.fulfill( { status: 200, body: '' } );
	} );
	return hits;
}

/**
 * Scrive un cookie di consenso già espresso (schema 3), come lo troverebbe
 * banner.js. Le categorie non indicate sono negate.
 *
 * @param {import('@playwright/test').BrowserContext} context
 * @param {object} categories Es. { marketing: true }.
 * @param {object} [meta]     Sovrascrive v, cv, type.
 */
async function setConsentCookie( context, categories, meta = {} ) {
	const data = {
		v: 3,
		cv: 1,
		ts: Date.now(),
		type: 'custom',
		functional: true,
		preferences: false,
		statistics: false,
		'statistics-anonymous': false,
		marketing: false,
		...categories,
		...meta,
	};
	await context.addCookies( [ {
		name: 'dbcm_consent',
		value: encodeURIComponent( JSON.stringify( data ) ),
		url: BASE_URL,
	} ] );
}

module.exports = {
	BASE_URL,
	FIXTURE_RAW,
	FIXTURE_WP,
	ADMIN_STATE,
	THIRD_PARTY_HOSTS,
	trackThirdParty,
	getConsentCookie,
	hasCookiePrefix,
	resetState,
	getState,
	interceptThirdParty,
	setConsentCookie,
};
