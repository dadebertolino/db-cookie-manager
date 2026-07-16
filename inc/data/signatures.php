<?php
/**
 * DB Cookie Manager — Database firme locale.
 *
 * Sorgente UNICA e statica (nessuna chiamata remota) che descrive i servizi
 * di terze parti riconosciuti dal plugin. Da questa struttura service-centrica
 * vengono derivate, a runtime, le due viste storiche:
 *
 *   - vista BLOCKER  → pattern {category, type, patterns[]} per neutralizzare
 *                       script/iframe prima dell'esecuzione (stripos substring).
 *   - vista SCANNER  → detection {signature(regex), cookies[], flag?} per dedurre
 *                       quali cookie un servizio imposta.
 *
 * NON viene mai caricata direttamente: passa sempre da DBCM_Signatures, che la
 * fonde con le firme personalizzate per-sito (option dbcm_custom_signatures) e
 * la espone alle due viste. Vedi inc/class-signatures.php.
 *
 * FILOSOFIA (DB Plugin Suite): zero dipendenze, zero rete, database locale.
 *
 * ---------------------------------------------------------------------------
 * SCHEMA DI OGNI VOCE (chiave = slug servizio, univoco):
 *
 *   'service'          (string)  Nome leggibile del servizio (per policy/UI/placeholder).
 *   'provider'         (string)  Titolare/fornitore (per policy).
 *   'privacy_url'      (string)  Opzionale. URL dell'informativa privacy del
 *                                fornitore, linkata nella Cookie Policy
 *                                (trasparenza GDPR Art. 13(1)(e)-(f)). Assente
 *                                per i servizi self-hosted.
 *   'category'         (string)  Categoria WP Consent API:
 *                                functional | preferences | statistics |
 *                                statistics-anonymous | marketing.
 *   'requires_consent' (bool)    false = tecnico/necessario, MAI bloccabile.
 *   'blockable'        (bool)    Opzionale. Se false, non entra MAI nella vista
 *                                blocker anche se requires_consent è true
 *                                (es. PayPal SDK antifrode: bloccarlo rompe il
 *                                checkout → default non bloccato, configurabile).
 *                                Default: uguale a requires_consent.
 *   'script_patterns'  (array)   Substring matchate su src/contenuto script (stripos).
 *   'iframe_patterns'  (array)   Substring matchate su src iframe (stripos).
 *   'scan_signature'   (string)  Regex per la detection HTML lato scanner. Se
 *                                assente, viene derivata dai pattern.
 *   'cookies'          (array)   Cookie impostati dal servizio. Ogni cookie:
 *                                  'name'     (string)  nome o prefisso ('_ga_*').
 *                                  'domain'   (string)  '@self' = dominio del sito,
 *                                                       '@self.' = .dominio-sito,
 *                                                       oppure dominio esplicito.
 *                                  'duration' (string)  durata leggibile (IT).
 *                                  'desc'     (string)  descrizione IT per la policy.
 *   'flag'             (string)  Opzionale. Option da settare a true se rilevato
 *                                (es. dbcm_google_fonts_detected).
 *   'notes'            (string)  Opzionale. Nota consulenziale per il report scan
 *                                (es. WhatsApp: "collegamento diretto, no dati").
 *   'report_only'      (bool)    Opzionale. Se true: rilevato e riportato nello
 *                                scan, ma MAI bloccato e SENZA consenso
 *                                (es. click-to-chat WhatsApp/Telegram).
 *   'warning'          (string)  Opzionale. Avviso admin (es. GTM può veicolare
 *                                marketing; PayPal Pay Later è promozionale).
 * ---------------------------------------------------------------------------
 *
 * @package DBCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Restituisce il database firme statico.
 *
 * Funzione pura (nessun side effect, nessuna option): il merge con le firme
 * custom e i filtri avviene in DBCM_Signatures. Tenuta come funzione (non
 * costante) per poter usare le funzioni i18n sulle descrizioni.
 *
 * @return array<string,array> Mappa slug => definizione servizio.
 */
function dbcm_signatures_data() {
	return array(

		/* =====================================================================
		 * NECESSARY / TECNICI — requires_consent = false, MAI bloccabili.
		 * WooCommerce, WordPress core, il nostro stesso banner.
		 * ================================================================== */

		'woocommerce' => array(
			'service'          => 'WooCommerce',
			'provider'         => __( 'Sito stesso (self-hosted)', 'db-cookie-manager' ),
			'category'         => 'functional',
			'requires_consent' => false,
			'script_patterns'  => array(),
			'iframe_patterns'  => array(),
			// Marcatori HTML affidabili della presenza di WooCommerce: classe
			// body, prefisso asset, variabile JS di params. Serve al rilevamento
			// via scanner runtime; via scan server-side i cookie tecnici sono
			// comunque iniettati dal core.
			'scan_signature'   => '/class="[^"]*\bwoocommerce\b|\/plugins\/woocommerce\/|wc_add_to_cart_params/',
			'cookies'          => array(
				array(
					'name'     => 'woocommerce_cart_hash',
					'domain'   => '@self',
					'duration' => __( 'sessione', 'db-cookie-manager' ),
					'desc'     => __( 'Mantiene lo stato del carrello. Tecnico, indispensabile per l\'e-commerce.', 'db-cookie-manager' ),
				),
				array(
					'name'     => 'woocommerce_items_in_cart',
					'domain'   => '@self',
					'duration' => __( 'sessione', 'db-cookie-manager' ),
					'desc'     => __( 'Conteggio articoli nel carrello. Tecnico.', 'db-cookie-manager' ),
				),
				array(
					'name'     => 'wp_woocommerce_session_*',
					'domain'   => '@self',
					'duration' => __( '2 giorni', 'db-cookie-manager' ),
					'desc'     => __( 'Sessione WooCommerce dell\'utente (carrello, checkout). Tecnico.', 'db-cookie-manager' ),
				),
				array(
					'name'     => 'woocommerce_recently_viewed',
					'domain'   => '@self',
					'duration' => __( 'sessione', 'db-cookie-manager' ),
					'desc'     => __( 'Prodotti visti di recente. Tecnico.', 'db-cookie-manager' ),
				),
				array(
					'name'     => 'store_notice*',
					'domain'   => '@self',
					'duration' => __( 'sessione', 'db-cookie-manager' ),
					'desc'     => __( 'Memorizza la chiusura dell\'avviso store. Tecnico.', 'db-cookie-manager' ),
				),
			),
		),

		'wordpress-core' => array(
			'service'          => 'WordPress',
			'provider'         => __( 'Sito stesso (self-hosted)', 'db-cookie-manager' ),
			'category'         => 'functional',
			'requires_consent' => false,
			'script_patterns'  => array(),
			'iframe_patterns'  => array(),
			'cookies'          => array(
				array(
					'name'     => 'wordpress_test_cookie',
					'domain'   => '@self',
					'duration' => __( 'sessione', 'db-cookie-manager' ),
					'desc'     => __( 'Verifica che il browser accetti i cookie. Tecnico.', 'db-cookie-manager' ),
				),
				array(
					'name'     => 'wordpress_logged_in_*',
					'domain'   => '@self',
					'duration' => __( 'sessione / 14 giorni', 'db-cookie-manager' ),
					'desc'     => __( 'Mantiene l\'autenticazione dell\'utente loggato. Tecnico.', 'db-cookie-manager' ),
				),
				array(
					'name'     => 'wordpress_sec_*',
					'domain'   => '@self',
					'duration' => __( 'sessione / 14 giorni', 'db-cookie-manager' ),
					'desc'     => __( 'Sicurezza della sessione autenticata. Tecnico.', 'db-cookie-manager' ),
				),
				array(
					'name'     => 'wp-settings-*',
					'domain'   => '@self',
					'duration' => __( '1 anno', 'db-cookie-manager' ),
					'desc'     => __( 'Preferenze di visualizzazione dell\'area admin. Tecnico.', 'db-cookie-manager' ),
				),
				array(
					'name'     => 'wp-settings-time-*',
					'domain'   => '@self',
					'duration' => __( '1 anno', 'db-cookie-manager' ),
					'desc'     => __( 'Timestamp delle preferenze admin. Tecnico.', 'db-cookie-manager' ),
				),
			),
		),

		'dbcm' => array(
			'service'          => 'DB Cookie Manager',
			'provider'         => __( 'Sito stesso (self-hosted)', 'db-cookie-manager' ),
			'category'         => 'functional',
			'requires_consent' => false,
			'script_patterns'  => array(),
			'iframe_patterns'  => array(),
			'scan_signature'   => '/dbcm_consent|dbcm-banner-root/',
			'cookies'          => array(
				array(
					'name'     => 'dbcm_consent',
					'domain'   => '@self',
					'duration' => __( '6 mesi', 'db-cookie-manager' ),
					'desc'     => __( 'Memorizza le preferenze di consenso ai cookie espresse dall\'utente. Tecnico.', 'db-cookie-manager' ),
				),
			),
		),

		/* =====================================================================
		 * FUNCTIONAL — richiesti dall'utente / sicurezza pagamento.
		 * PayPal SDK antifrode: bloccabile NO di default (romperebbe il checkout).
		 * ================================================================== */

		'paypal' => array(
			'service'          => 'PayPal',
			'provider'         => 'PayPal (Europe) S.à r.l. et Cie, S.C.A.',
			'privacy_url'      => 'https://www.paypal.com/it/legalhub/privacy-full',
			'category'         => 'functional',
			'requires_consent' => false,
			'blockable'        => false, // Antifrode: bloccarlo rompe il checkout. Configurabile in admin.
			'script_patterns'  => array(
				'www.paypal.com/sdk/js',
				'www.paypalobjects.com',
				'c.paypal.com',
			),
			'iframe_patterns'  => array(),
			'scan_signature'   => '/paypal\.com\/sdk\/js|paypalobjects\.com|c\.paypal\.com/',
			'cookies'          => array(
				array(
					'name'     => 'ts',
					'domain'   => '.paypal.com',
					'duration' => __( '3 anni', 'db-cookie-manager' ),
					'desc'     => __( 'Sicurezza e prevenzione frodi durante il pagamento. Funzionale.', 'db-cookie-manager' ),
				),
				array(
					'name'     => 'ts_c',
					'domain'   => '.paypal.com',
					'duration' => __( '3 anni', 'db-cookie-manager' ),
					'desc'     => __( 'Prevenzione frodi PayPal. Funzionale.', 'db-cookie-manager' ),
				),
				array(
					'name'     => 'tsrce',
					'domain'   => '.paypal.com',
					'duration' => __( '3 giorni', 'db-cookie-manager' ),
					'desc'     => __( 'Prevenzione frodi PayPal. Funzionale.', 'db-cookie-manager' ),
				),
				array(
					'name'     => 'x-pp-s',
					'domain'   => '.paypal.com',
					'duration' => __( 'sessione', 'db-cookie-manager' ),
					'desc'     => __( 'Sessione checkout PayPal. Funzionale.', 'db-cookie-manager' ),
				),
				array(
					'name'     => 'l7_az',
					'domain'   => '.paypal.com',
					'duration' => __( 'sessione', 'db-cookie-manager' ),
					'desc'     => __( 'Bilanciamento di carico infrastruttura PayPal. Funzionale.', 'db-cookie-manager' ),
				),
				array(
					'name'     => 'enforce_policy',
					'domain'   => '.paypal.com',
					'duration' => __( '1 anno', 'db-cookie-manager' ),
					'desc'     => __( 'Applica le policy di sicurezza PayPal. Funzionale.', 'db-cookie-manager' ),
				),
				array(
					'name'     => 'cookie_check',
					'domain'   => '.paypal.com',
					'duration' => __( 'sessione', 'db-cookie-manager' ),
					'desc'     => __( 'Verifica il supporto ai cookie. Funzionale.', 'db-cookie-manager' ),
				),
			),
			// Sotto-componente promozionale: se rilevato il messaging "Pay Later"
			// va segnalato come marketing. DBCM_Signatures gestisce lo split.
			'pay_later_signature' => '/paypal\.com\/sdk\/js[^"\']*components=[^"\']*messages/',
			'warning'             => __( 'Rilevato PayPal Pay Later messaging: componente promozionale, valutare disattivazione o consenso marketing.', 'db-cookie-manager' ),
		),

		/* =====================================================================
		 * STATISTICS — analytics con identificatori personali.
		 * ================================================================== */

		'google-analytics' => array(
			'service'          => 'Google Analytics 4',
			'provider'         => 'Google Ireland Ltd.',
			'privacy_url'      => 'https://policies.google.com/privacy',
			'category'         => 'statistics',
			'requires_consent' => true,
			'script_patterns'  => array(
				'google-analytics.com/analytics',
				'google-analytics.com/ga.js',
				'google-analytics.com/urchin.js',
				'googletagmanager.com/gtag',
			),
			'iframe_patterns'  => array(),
			'scan_signature'   => '/google-analytics\.com\/analytics|googletagmanager\.com\/gtag|gtag\(|\bga\(/',
			'cookies'          => array(
				array(
					'name'     => '_ga',
					'domain'   => '@self.',
					'duration' => __( '2 anni', 'db-cookie-manager' ),
					'desc'     => __( 'Identifica in modo univoco il visitatore per le statistiche. Analitico.', 'db-cookie-manager' ),
				),
				array(
					'name'     => '_ga_*',
					'domain'   => '@self.',
					'duration' => __( '2 anni', 'db-cookie-manager' ),
					'desc'     => __( 'Persiste lo stato della sessione GA4. Analitico.', 'db-cookie-manager' ),
				),
				array(
					'name'     => '_gid',
					'domain'   => '@self.',
					'duration' => __( '24 ore', 'db-cookie-manager' ),
					'desc'     => __( 'Distingue i visitatori nelle statistiche. Analitico.', 'db-cookie-manager' ),
				),
				array(
					'name'     => '_gat*',
					'domain'   => '@self.',
					'duration' => __( '1 minuto', 'db-cookie-manager' ),
					'desc'     => __( 'Limita la frequenza delle richieste. Analitico.', 'db-cookie-manager' ),
				),
			),
		),

		'google-tag-manager' => array(
			'service'          => 'Google Tag Manager',
			'provider'         => 'Google Ireland Ltd.',
			'privacy_url'      => 'https://policies.google.com/privacy',
			'category'         => 'statistics',
			'requires_consent' => true,
			'script_patterns'  => array(
				'googletagmanager.com/gtm.js',
			),
			'iframe_patterns'  => array(),
			'scan_signature'   => '/googletagmanager\.com\/gtm\.js/',
			'cookies'          => array(),
			'warning'          => __( 'Google Tag Manager può veicolare tag di marketing: verificare i tag effettivamente caricati.', 'db-cookie-manager' ),
		),

		'matomo' => array(
			'service'          => 'Matomo',
			'provider'         => __( 'Self-hosted / InnoCraft', 'db-cookie-manager' ),
			'privacy_url'      => 'https://matomo.org/privacy-policy/',
			'category'         => 'statistics',
			'requires_consent' => true,
			'script_patterns'  => array(
				'matomo.js',
				'matomo.php',
				'piwik.js',
				'piwik.php',
			),
			'iframe_patterns'  => array(),
			'scan_signature'   => '/matomo\.(js|php)|piwik\.(js|php)/',
			'cookies'          => array(
				array(
					'name'     => '_pk_id*',
					'domain'   => '@self.',
					'duration' => __( '13 mesi', 'db-cookie-manager' ),
					'desc'     => __( 'Identifica il visitatore per le statistiche Matomo. Analitico.', 'db-cookie-manager' ),
				),
				array(
					'name'     => '_pk_ses*',
					'domain'   => '@self.',
					'duration' => __( '30 minuti', 'db-cookie-manager' ),
					'desc'     => __( 'Sessione Matomo. Analitico.', 'db-cookie-manager' ),
				),
			),
		),

		'hotjar' => array(
			'service'          => 'Hotjar',
			'provider'         => 'Hotjar Ltd.',
			'privacy_url'      => 'https://www.hotjar.com/legal/policies/privacy/',
			'category'         => 'statistics',
			'requires_consent' => true,
			'script_patterns'  => array(
				'static.hotjar.com',
				'script.hotjar.com',
			),
			'iframe_patterns'  => array(),
			'scan_signature'   => '/static\.hotjar\.com|script\.hotjar\.com|\bhj\(/',
			'cookies'          => array(
				array(
					'name'     => '_hjSessionUser_*',
					'domain'   => '@self.',
					'duration' => __( '1 anno', 'db-cookie-manager' ),
					'desc'     => __( 'Session replay e heatmap: identifica l\'utente tra le sessioni. Analitico.', 'db-cookie-manager' ),
				),
			),
		),

		'clarity' => array(
			'service'          => 'Microsoft Clarity',
			'provider'         => 'Microsoft Corporation',
			'privacy_url'      => 'https://privacy.microsoft.com/privacystatement',
			'category'         => 'statistics',
			'requires_consent' => true,
			'script_patterns'  => array(
				'clarity.ms',
			),
			'iframe_patterns'  => array(),
			'scan_signature'   => '/clarity\.ms\/tag/',
			'cookies'          => array(
				array(
					'name'     => '_clck',
					'domain'   => '@self.',
					'duration' => __( '1 anno', 'db-cookie-manager' ),
					'desc'     => __( 'Session replay Clarity: ID utente persistente. Analitico.', 'db-cookie-manager' ),
				),
				array(
					'name'     => '_clsk',
					'domain'   => '@self.',
					'duration' => __( '1 giorno', 'db-cookie-manager' ),
					'desc'     => __( 'Collega le pagine viste nella sessione Clarity. Analitico.', 'db-cookie-manager' ),
				),
			),
		),

		'jetpack-stats' => array(
			'service'          => 'Jetpack / WordPress.com Stats',
			'provider'         => 'Automattic Inc.',
			'privacy_url'      => 'https://automattic.com/privacy/',
			'category'         => 'statistics',
			'requires_consent' => true,
			'script_patterns'  => array(
				'stats.wp.com',
			),
			'iframe_patterns'  => array(),
			'scan_signature'   => '/stats\.wp\.com/',
			'cookies'          => array(),
		),

		'mixpanel' => array(
			'service'          => 'Mixpanel',
			'provider'         => 'Mixpanel Inc.',
			'privacy_url'      => 'https://mixpanel.com/legal/privacy-policy/',
			'category'         => 'statistics',
			'requires_consent' => true,
			'script_patterns'  => array( 'cdn.mxpnl.com' ),
			'iframe_patterns'  => array(),
			'scan_signature'   => '/cdn\.mxpnl\.com/',
			'cookies'          => array(),
		),

		'heap' => array(
			'service'          => 'Heap Analytics',
			'provider'         => 'Heap Inc.',
			'privacy_url'      => 'https://heap.io/privacy',
			'category'         => 'statistics',
			'requires_consent' => true,
			'script_patterns'  => array( 'cdn.heapanalytics.com' ),
			'iframe_patterns'  => array(),
			'scan_signature'   => '/cdn\.heapanalytics\.com/',
			'cookies'          => array(),
		),

		'amplitude' => array(
			'service'          => 'Amplitude',
			'provider'         => 'Amplitude Inc.',
			'privacy_url'      => 'https://amplitude.com/privacy',
			'category'         => 'statistics',
			'requires_consent' => true,
			'script_patterns'  => array( 'cdn.amplitude.com' ),
			'iframe_patterns'  => array(),
			'scan_signature'   => '/cdn\.amplitude\.com/',
			'cookies'          => array(),
		),

		'fullstory' => array(
			'service'          => 'FullStory',
			'provider'         => 'FullStory Inc.',
			'privacy_url'      => 'https://www.fullstory.com/legal/privacy-policy/',
			'category'         => 'statistics',
			'requires_consent' => true,
			'script_patterns'  => array( 'fullstory.com/s/fs.js' ),
			'iframe_patterns'  => array(),
			'scan_signature'   => '/fullstory\.com\/s\/fs\.js/',
			'cookies'          => array(),
		),

		'cloudflare-insights' => array(
			'service'          => 'Cloudflare Web Analytics',
			'provider'         => 'Cloudflare Inc.',
			'privacy_url'      => 'https://www.cloudflare.com/privacypolicy/',
			'category'         => 'statistics',
			'requires_consent' => true,
			'script_patterns'  => array( 'static.cloudflareinsights.com' ),
			'iframe_patterns'  => array(),
			'scan_signature'   => '/static\.cloudflareinsights\.com/',
			'cookies'          => array(),
		),

		'hubspot' => array(
			'service'          => 'HubSpot',
			'provider'         => 'HubSpot Inc.',
			'privacy_url'      => 'https://legal.hubspot.com/privacy-policy',
			'category'         => 'statistics',
			'requires_consent' => true,
			'script_patterns'  => array( 'js.hs-scripts.com', 'hs-analytics' ),
			'iframe_patterns'  => array(),
			'scan_signature'   => '/js\.hs-scripts\.com|hs-analytics/',
			'cookies'          => array(
				array(
					'name'     => '__hstc',
					'domain'   => '@self.',
					'duration' => __( '6 mesi', 'db-cookie-manager' ),
					'desc'     => __( 'Traccia il visitatore per HubSpot Analytics. Analitico.', 'db-cookie-manager' ),
				),
				array(
					'name'     => 'hubspotutk',
					'domain'   => '@self.',
					'duration' => __( '6 mesi', 'db-cookie-manager' ),
					'desc'     => __( 'Token utente HubSpot. Analitico.', 'db-cookie-manager' ),
				),
			),
		),

		/* =====================================================================
		 * STATISTICS-ANONYMOUS — analytics aggregati cookieless.
		 * ================================================================== */

		'plausible' => array(
			'service'          => 'Plausible Analytics',
			'provider'         => __( 'Plausible Insights OÜ / self-hosted', 'db-cookie-manager' ),
			'privacy_url'      => 'https://plausible.io/privacy',
			'category'         => 'statistics-anonymous',
			'requires_consent' => false, // cookieless per design: nessun consenso richiesto.
			'script_patterns'  => array(
				'plausible.io/js/plausible',
				'plausible.io/js/script',
			),
			'iframe_patterns'  => array(),
			'scan_signature'   => '/plausible\.io\/js\/(plausible|script)/',
			'cookies'          => array(),
			'flag'             => 'dbcm_external_services_detected',
			'notes'            => __( 'Statistiche aggregate senza cookie: non richiede consenso.', 'db-cookie-manager' ),
		),

		'umami' => array(
			'service'          => 'Umami',
			'provider'         => __( 'Self-hosted / Umami Software', 'db-cookie-manager' ),
			'privacy_url'      => 'https://umami.is/privacy',
			'category'         => 'statistics-anonymous',
			'requires_consent' => false,
			'script_patterns'  => array( 'umami.is/script.js' ),
			'iframe_patterns'  => array(),
			'scan_signature'   => '/umami\.is\/script\.js/',
			'cookies'          => array(),
			'flag'             => 'dbcm_external_services_detected',
			'notes'            => __( 'Statistiche aggregate senza cookie: non richiede consenso.', 'db-cookie-manager' ),
		),

		'fathom' => array(
			'service'          => 'Fathom Analytics',
			'provider'         => 'Conva Ventures Inc.',
			'privacy_url'      => 'https://usefathom.com/privacy',
			'category'         => 'statistics-anonymous',
			'requires_consent' => false,
			'script_patterns'  => array( 'cdn.usefathom.com' ),
			'iframe_patterns'  => array(),
			'scan_signature'   => '/cdn\.usefathom\.com/',
			'cookies'          => array(),
			'flag'             => 'dbcm_external_services_detected',
			'notes'            => __( 'Statistiche aggregate senza cookie: non richiede consenso.', 'db-cookie-manager' ),
		),

		'simple-analytics' => array(
			'service'          => 'Simple Analytics',
			'provider'         => 'Simple Analytics B.V.',
			'privacy_url'      => 'https://www.simpleanalytics.com/privacy-policy',
			'category'         => 'statistics-anonymous',
			'requires_consent' => false,
			'script_patterns'  => array( 'simpleanalytics.com/latest.js' ),
			'iframe_patterns'  => array(),
			'scan_signature'   => '/simpleanalytics\.com\/latest\.js/',
			'cookies'          => array(),
			'flag'             => 'dbcm_external_services_detected',
			'notes'            => __( 'Statistiche aggregate senza cookie: non richiede consenso.', 'db-cookie-manager' ),
		),

		/* =====================================================================
		 * MARKETING — pixel pubblicitari, retargeting, social tracking.
		 * ================================================================== */

		'meta-pixel' => array(
			'service'          => 'Meta Pixel',
			'provider'         => 'Meta Platforms Ireland Ltd.',
			'privacy_url'      => 'https://www.facebook.com/privacy/policy/',
			'category'         => 'marketing',
			'requires_consent' => true,
			'script_patterns'  => array(
				'connect.facebook.net',
				'fbevents.js',
			),
			'iframe_patterns'  => array(),
			'scan_signature'   => '/fbq\(|connect\.facebook\.net\/.*\/fbevents/',
			'cookies'          => array(
				array(
					'name'     => '_fbp',
					'domain'   => '@self.',
					'duration' => __( '3 mesi', 'db-cookie-manager' ),
					'desc'     => __( 'Identifica il browser per pubblicità e retargeting Meta. Marketing.', 'db-cookie-manager' ),
				),
				array(
					'name'     => 'fr',
					'domain'   => '.facebook.com',
					'duration' => __( '3 mesi', 'db-cookie-manager' ),
					'desc'     => __( 'Pubblicità Facebook e misurazione. Marketing.', 'db-cookie-manager' ),
				),
			),
		),

		'google-ads' => array(
			'service'          => 'Google Ads',
			'provider'         => 'Google Ireland Ltd.',
			'privacy_url'      => 'https://policies.google.com/privacy',
			'category'         => 'marketing',
			'requires_consent' => true,
			'script_patterns'  => array(
				'googleadservices.com',
				'googlesyndication.com',
				'doubleclick.net',
				'adservice.google',
			),
			'iframe_patterns'  => array(),
			'scan_signature'   => '/googleadservices\.com|googlesyndication\.com|doubleclick\.net/',
			'cookies'          => array(
				array(
					'name'     => '_gcl_*',
					'domain'   => '@self.',
					'duration' => __( '3 mesi', 'db-cookie-manager' ),
					'desc'     => __( 'Conversioni Google Ads. Marketing.', 'db-cookie-manager' ),
				),
				array(
					'name'     => 'IDE',
					'domain'   => '.doubleclick.net',
					'duration' => __( '1 anno', 'db-cookie-manager' ),
					'desc'     => __( 'Retargeting e misurazione DoubleClick. Marketing.', 'db-cookie-manager' ),
				),
				array(
					'name'     => 'test_cookie',
					'domain'   => '.doubleclick.net',
					'duration' => __( '15 minuti', 'db-cookie-manager' ),
					'desc'     => __( 'Verifica il supporto ai cookie. Marketing.', 'db-cookie-manager' ),
				),
			),
		),

		'tiktok-pixel' => array(
			'service'          => 'TikTok Pixel',
			'provider'         => 'TikTok Technology Ltd.',
			'privacy_url'      => 'https://www.tiktok.com/legal/privacy-policy',
			'category'         => 'marketing',
			'requires_consent' => true,
			'script_patterns'  => array( 'analytics.tiktok.com' ),
			'iframe_patterns'  => array(),
			'scan_signature'   => '/analytics\.tiktok\.com/',
			'cookies'          => array(
				array(
					'name'     => '_ttp',
					'domain'   => '@self.',
					'duration' => __( '13 mesi', 'db-cookie-manager' ),
					'desc'     => __( 'Traccia conversioni e retargeting TikTok. Marketing.', 'db-cookie-manager' ),
				),
			),
		),

		'linkedin-insight' => array(
			'service'          => 'LinkedIn Insight Tag',
			'provider'         => 'LinkedIn Ireland Unlimited Company',
			'privacy_url'      => 'https://www.linkedin.com/legal/privacy-policy',
			'category'         => 'marketing',
			'requires_consent' => true,
			'script_patterns'  => array(
				'snap.licdn.com',
				'ads.linkedin.com',
				'linkedin.com/px',
			),
			'iframe_patterns'  => array(),
			'scan_signature'   => '/snap\.licdn\.com|linkedin\.com\/px/',
			'cookies'          => array(
				array(
					'name'     => 'li_sugr',
					'domain'   => '.linkedin.com',
					'duration' => __( '3 mesi', 'db-cookie-manager' ),
					'desc'     => __( 'Identificazione browser LinkedIn per pubblicità. Marketing.', 'db-cookie-manager' ),
				),
				array(
					'name'     => 'lidc',
					'domain'   => '.linkedin.com',
					'duration' => __( '1 giorno', 'db-cookie-manager' ),
					'desc'     => __( 'Instradamento data center LinkedIn. Marketing.', 'db-cookie-manager' ),
				),
			),
		),

		'twitter-ads' => array(
			'service'          => 'X (Twitter) Ads',
			'provider'         => 'X Corp.',
			'privacy_url'      => 'https://x.com/privacy',
			'category'         => 'marketing',
			'requires_consent' => true,
			'script_patterns'  => array(
				'static.ads-twitter.com',
				'platform.twitter.com/oct.js',
				'ads.twitter.com',
			),
			'iframe_patterns'  => array(),
			'scan_signature'   => '/static\.ads-twitter\.com|ads\.twitter\.com/',
			'cookies'          => array(),
		),

		'pinterest-tag' => array(
			'service'          => 'Pinterest Tag',
			'provider'         => 'Pinterest Europe Ltd.',
			'privacy_url'      => 'https://policy.pinterest.com/privacy-policy',
			'category'         => 'marketing',
			'requires_consent' => true,
			'script_patterns'  => array( 'ct.pinterest.com', 'pintrk' ),
			'iframe_patterns'  => array(),
			'scan_signature'   => '/ct\.pinterest\.com|pintrk\(/',
			'cookies'          => array(
				array(
					'name'     => '_pinterest_*',
					'domain'   => '@self.',
					'duration' => __( '1 anno', 'db-cookie-manager' ),
					'desc'     => __( 'Conversioni e retargeting Pinterest. Marketing.', 'db-cookie-manager' ),
				),
			),
		),

		'reddit-pixel' => array(
			'service'          => 'Reddit Pixel',
			'provider'         => 'Reddit Inc.',
			'privacy_url'      => 'https://www.reddit.com/policies/privacy-policy',
			'category'         => 'marketing',
			'requires_consent' => true,
			'script_patterns'  => array( 'redditstatic.com/ads' ),
			'iframe_patterns'  => array(),
			'scan_signature'   => '/redditstatic\.com\/ads/',
			'cookies'          => array(),
		),

		'amazon-ads' => array(
			'service'          => 'Amazon Advertising',
			'provider'         => 'Amazon Europe Core S.à r.l.',
			'privacy_url'      => 'https://advertising.amazon.com/legal/privacy-notice',
			'category'         => 'marketing',
			'requires_consent' => true,
			'script_patterns'  => array( 'amazon-adsystem.com' ),
			'iframe_patterns'  => array(),
			'scan_signature'   => '/amazon-adsystem\.com/',
			'cookies'          => array(),
		),

		'bing-uet' => array(
			'service'          => 'Microsoft Advertising (UET)',
			'provider'         => 'Microsoft Corporation',
			'privacy_url'      => 'https://privacy.microsoft.com/privacystatement',
			'category'         => 'marketing',
			'requires_consent' => true,
			'script_patterns'  => array( 'bat.bing.com' ),
			'iframe_patterns'  => array(),
			'scan_signature'   => '/bat\.bing\.com/',
			'cookies'          => array(
				array(
					'name'     => '_uetsid',
					'domain'   => '@self.',
					'duration' => __( '1 giorno', 'db-cookie-manager' ),
					'desc'     => __( 'Sessione Universal Event Tracking Microsoft. Marketing.', 'db-cookie-manager' ),
				),
				array(
					'name'     => '_uetvid',
					'domain'   => '@self.',
					'duration' => __( '13 mesi', 'db-cookie-manager' ),
					'desc'     => __( 'Visitatore UET Microsoft per retargeting. Marketing.', 'db-cookie-manager' ),
				),
				array(
					'name'     => 'MUID',
					'domain'   => '.bing.com',
					'duration' => __( '13 mesi', 'db-cookie-manager' ),
					'desc'     => __( 'Identificatore utente Microsoft cross-site. Marketing.', 'db-cookie-manager' ),
				),
			),
		),

		'cloudflare-turnstile' => array(
			'service'          => 'Cloudflare Turnstile',
			'provider'         => 'Cloudflare Inc.',
			'privacy_url'      => 'https://www.cloudflare.com/privacypolicy/',
			'category'         => 'marketing',
			'requires_consent' => true,
			'script_patterns'  => array( 'challenges.cloudflare.com' ),
			'iframe_patterns'  => array(),
			'scan_signature'   => '/challenges\.cloudflare\.com/',
			'cookies'          => array(),
			'notes'            => __( 'CAPTCHA. Alcuni garanti lo considerano funzionale se strettamente anti-abuso: valutare caso per caso.', 'db-cookie-manager' ),
		),

		/* =====================================================================
		 * EMBED DI TERZE PARTI — iframe. Categoria e placeholder click-to-load.
		 * ================================================================== */

		'youtube' => array(
			'service'          => 'YouTube',
			'provider'         => 'Google Ireland Ltd.',
			'privacy_url'      => 'https://policies.google.com/privacy',
			'category'         => 'marketing',
			'requires_consent' => true,
			'script_patterns'  => array(),
			'iframe_patterns'  => array(
				'youtube.com/embed',
				'youtube-nocookie.com/embed',
			),
			'scan_signature'   => '/youtube\.com\/embed|youtube-nocookie\.com/',
			'cookies'          => array(
				array(
					'name'     => 'YSC',
					'domain'   => '.youtube.com',
					'duration' => __( 'sessione', 'db-cookie-manager' ),
					'desc'     => __( 'Statistiche di visualizzazione video YouTube. Marketing.', 'db-cookie-manager' ),
				),
				array(
					'name'     => 'VISITOR_INFO1_LIVE',
					'domain'   => '.youtube.com',
					'duration' => __( '6 mesi', 'db-cookie-manager' ),
					'desc'     => __( 'Preferenze e stima banda del player YouTube. Marketing.', 'db-cookie-manager' ),
				),
			),
			// Se in modalità nocookie, DBCM_Signatures declassa a functional.
			'nocookie_pattern' => 'youtube-nocookie.com',
		),

		'vimeo' => array(
			'service'          => 'Vimeo',
			'provider'         => 'Vimeo Inc.',
			'privacy_url'      => 'https://vimeo.com/privacy',
			'category'         => 'functional',
			'requires_consent' => true,
			'script_patterns'  => array(),
			'iframe_patterns'  => array( 'player.vimeo.com' ),
			'scan_signature'   => '/player\.vimeo\.com/',
			'cookies'          => array(
				array(
					'name'     => 'vuid',
					'domain'   => '.vimeo.com',
					'duration' => __( '2 anni', 'db-cookie-manager' ),
					'desc'     => __( 'Statistiche di riproduzione video Vimeo. Funzionale.', 'db-cookie-manager' ),
				),
			),
		),

		'google-maps' => array(
			'service'          => 'Google Maps',
			'provider'         => 'Google Ireland Ltd.',
			'privacy_url'      => 'https://policies.google.com/privacy',
			'category'         => 'functional',
			'requires_consent' => true,
			'script_patterns'  => array( 'maps.googleapis.com' ),
			'iframe_patterns'  => array(
				'google.com/maps/embed',
				'google.com/maps/d/embed',
				'maps.google.com',
			),
			'scan_signature'   => '/google\.com\/maps\/(embed|d\/embed)|maps\.googleapis\.com/',
			'cookies'          => array(),
		),

		'instagram' => array(
			'service'          => 'Instagram',
			'provider'         => 'Meta Platforms Ireland Ltd.',
			'privacy_url'      => 'https://www.facebook.com/privacy/policy/',
			'category'         => 'marketing',
			'requires_consent' => true,
			'script_patterns'  => array( 'cdninstagram.com' ),
			'iframe_patterns'  => array(
				'instagram.com/embed',
				'instagram.com/p/',
				'instagram.com/reel/',
				'instagram.com/tv/',
			),
			'scan_signature'   => '/instagram\.com\/(embed|p\/|reel\/|tv\/)|cdninstagram\.com/',
			'cookies'          => array(),
		),

		'spotify' => array(
			'service'          => 'Spotify',
			'provider'         => 'Spotify AB',
			'privacy_url'      => 'https://www.spotify.com/legal/privacy-policy/',
			'category'         => 'marketing',
			'requires_consent' => true,
			'script_patterns'  => array(),
			'iframe_patterns'  => array( 'open.spotify.com/embed' ),
			'scan_signature'   => '/open\.spotify\.com\/embed/',
			'cookies'          => array(),
		),

		'soundcloud' => array(
			'service'          => 'SoundCloud',
			'provider'         => 'SoundCloud Ltd.',
			'privacy_url'      => 'https://soundcloud.com/pages/privacy',
			'category'         => 'marketing',
			'requires_consent' => true,
			'script_patterns'  => array(),
			'iframe_patterns'  => array( 'soundcloud.com/player', 'w.soundcloud.com/player' ),
			'scan_signature'   => '/soundcloud\.com\/player/',
			'cookies'          => array(),
		),

		'twitter-embed' => array(
			'service'          => 'X (Twitter) Embed',
			'provider'         => 'X Corp.',
			'privacy_url'      => 'https://x.com/privacy',
			'category'         => 'marketing',
			'requires_consent' => true,
			'script_patterns'  => array( 'platform.twitter.com/widgets' ),
			'iframe_patterns'  => array( 'platform.twitter.com/embed' ),
			'scan_signature'   => '/platform\.twitter\.com\/(embed|widgets)/',
			'cookies'          => array(),
		),

		/* =====================================================================
		 * PAGAMENTI — Stripe (functional, richiesto dall'utente).
		 * ================================================================== */

		'stripe' => array(
			'service'          => 'Stripe',
			'provider'         => 'Stripe Payments Europe Ltd.',
			'privacy_url'      => 'https://stripe.com/privacy',
			'category'         => 'functional',
			'requires_consent' => false,
			'blockable'        => false, // Bloccarlo rompe il pagamento.
			'script_patterns'  => array( 'js.stripe.com', 'api.stripe.com' ),
			'iframe_patterns'  => array( 'js.stripe.com' ),
			'scan_signature'   => '/js\.stripe\.com|api\.stripe\.com/',
			'cookies'          => array(
				array(
					'name'     => '__stripe_mid',
					'domain'   => '@self.',
					'duration' => __( '1 anno', 'db-cookie-manager' ),
					'desc'     => __( 'Prevenzione frodi Stripe. Funzionale.', 'db-cookie-manager' ),
				),
				array(
					'name'     => '__stripe_sid',
					'domain'   => '@self.',
					'duration' => __( '30 minuti', 'db-cookie-manager' ),
					'desc'     => __( 'Sessione antifrode Stripe. Funzionale.', 'db-cookie-manager' ),
				),
			),
		),

		/* =====================================================================
		 * GOOGLE FONTS — caso speciale: localizzazione, non blocco (v3.3.0 §4).
		 * ================================================================== */

		'google-fonts' => array(
			'service'          => 'Google Fonts',
			'provider'         => 'Google Ireland Ltd.',
			'privacy_url'      => 'https://policies.google.com/privacy',
			'category'         => 'functional',
			'requires_consent' => true,
			'blockable'        => false, // Bloccare i font rompe la grafica: si localizza.
			'script_patterns'  => array(),
			'iframe_patterns'  => array(),
			'scan_signature'   => '/fonts\.googleapis\.com|fonts\.gstatic\.com/',
			'cookies'          => array(),
			'flag'             => 'dbcm_google_fonts_detected',
			'notes'            => __( 'Nessun cookie ma trasmette l\'IP a Google. Soluzione consigliata: localizzare i font (self-hosting).', 'db-cookie-manager' ),
		),

		/* =====================================================================
		 * CLICK-TO-CHAT — report_only: rilevato ma MAI bloccato, no consenso.
		 * Collegamento diretto: nessun dato trasmesso prima del click.
		 * ================================================================== */

		'whatsapp-click' => array(
			'service'          => 'WhatsApp (click-to-chat)',
			'provider'         => 'Meta Platforms Ireland Ltd.',
			'privacy_url'      => 'https://www.whatsapp.com/legal/privacy-policy-eea',
			'category'         => 'functional',
			'requires_consent' => false,
			'report_only'      => true,
			'script_patterns'  => array(),
			'iframe_patterns'  => array(),
			'scan_signature'   => '/(wa\.me\/|api\.whatsapp\.com\/send)/',
			'cookies'          => array(),
			'notes'            => __( 'Collegamento diretto, nessun dato trasmesso prima del click — dichiarare in Privacy Policy, non in Cookie Policy.', 'db-cookie-manager' ),
		),

		'telegram-click' => array(
			'service'          => 'Telegram (click-to-chat)',
			'provider'         => 'Telegram FZ-LLC',
			'privacy_url'      => 'https://telegram.org/privacy',
			'category'         => 'functional',
			'requires_consent' => false,
			'report_only'      => true,
			'script_patterns'  => array(),
			'iframe_patterns'  => array(),
			'scan_signature'   => '/(\bt\.me\/|telegram\.me\/)/',
			'cookies'          => array(),
			'notes'            => __( 'Collegamento diretto, nessun dato trasmesso prima del click — dichiarare in Privacy Policy, non in Cookie Policy.', 'db-cookie-manager' ),
		),

		'messenger-click' => array(
			'service'          => 'Messenger (click-to-chat)',
			'provider'         => 'Meta Platforms Ireland Ltd.',
			'privacy_url'      => 'https://www.facebook.com/privacy/policy/',
			'category'         => 'functional',
			'requires_consent' => false,
			'report_only'      => true,
			'script_patterns'  => array(),
			'iframe_patterns'  => array(),
			'scan_signature'   => '/\bm\.me\//',
			'cookies'          => array(),
			'notes'            => __( 'Collegamento diretto, nessun dato trasmesso prima del click — dichiarare in Privacy Policy, non in Cookie Policy.', 'db-cookie-manager' ),
		),
	);
}
