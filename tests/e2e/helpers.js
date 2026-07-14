// @ts-check
/**
 * Helper condivisi per gli E2E di DB Cookie Manager.
 */

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

module.exports = { THIRD_PARTY_HOSTS, trackThirdParty, getConsentCookie, hasCookiePrefix };
