<?php
/**
 * DBCM_Cookie_Database — Database statico dei cookie noti.
 *
 * Mappa nome cookie (esatto o wildcard con `*`) → metadata:
 *   - category: una delle 5 standard WP Consent API
 *   - description: finalità in linguaggio user-facing
 *   - duration: durata leggibile (es. "1 anno", "Sessione")
 *   - provider: fornitore (es. "Google Analytics", "WordPress")
 *
 * Differenze rispetto a 2.0.1:
 *  - Categorie riallineate alle 5 standard WP Consent API.
 *    Mapping: tecnico → functional, analitica → statistics,
 *    Plausible/Umami → statistics-anonymous, marketing → marketing.
 *    "prestazioni" (categoria custom) eliminata.
 *  - "sconosciuto" eliminato dalle entry note: i cookie unmatched
 *    cadono su default `marketing` con description esplicita
 *    "Cookie non identificato — verificare manualmente la finalità".
 *  - Aggiunte moderne: dbcm_consent (il nostro), GA4 _ga_<container>,
 *    cookie WooCommerce completi, Stripe, Mailchimp, ConvertKit,
 *    Cloudflare bot management distinto da Cloudflare CDN.
 *  - guess_provider esteso a TikTok, Pinterest, Bing, Microsoft Clarity.
 *
 * @package DBCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DBCM_Cookie_Database' ) ) {

	class DBCM_Cookie_Database {

		/**
		 * Restituisce la mappa completa dei cookie noti.
		 *
		 * Estensibile via filtro 'dbcm_known_cookies': tema/altri plugin
		 * possono aggiungere cookie specifici (es. il proprio plugin custom)
		 * senza patchare questo file.
		 *
		 * @return array Mappa pattern → array{category, description, duration, provider}.
		 */
		public static function get_known_cookies() {
			$cookies = array(

				/* =========================================================
				 * FUNCTIONAL — necessari, sempre concessi.
				 * ========================================================= */

				// DB Cookie Manager (il nostro stesso cookie).
				'dbcm_consent' => array(
					'category'    => 'functional',
					'description' => __( 'Memorizza la scelta dell\'utente sul consenso ai cookie.', 'db-cookie-manager' ),
					'duration'    => '365 giorni',
					'provider'    => 'DB Cookie Manager',
				),

				// WordPress Core.
				'wordpress_sec_*' => array(
					'category'    => 'functional',
					'description' => __( 'Cookie di autenticazione per utenti registrati WordPress.', 'db-cookie-manager' ),
					'duration'    => 'Sessione / 14 giorni',
					'provider'    => 'WordPress',
				),
				'wordpress_logged_in_*' => array(
					'category'    => 'functional',
					'description' => __( 'Indica se l\'utente è autenticato in WordPress.', 'db-cookie-manager' ),
					'duration'    => 'Sessione / 14 giorni',
					'provider'    => 'WordPress',
				),
				'wp-settings-*' => array(
					'category'    => 'functional',
					'description' => __( 'Salva le preferenze dell\'interfaccia admin di WordPress.', 'db-cookie-manager' ),
					'duration'    => '1 anno',
					'provider'    => 'WordPress',
				),
				'wp-settings-time-*' => array(
					'category'    => 'functional',
					'description' => __( 'Timestamp associato alle preferenze admin WordPress.', 'db-cookie-manager' ),
					'duration'    => '1 anno',
					'provider'    => 'WordPress',
				),
				'wordpress_test_cookie' => array(
					'category'    => 'functional',
					'description' => __( 'Verifica se il browser accetta i cookie.', 'db-cookie-manager' ),
					'duration'    => 'Sessione',
					'provider'    => 'WordPress',
				),
				'comment_author_*' => array(
					'category'    => 'functional',
					'description' => __( 'Memorizza il nome dell\'autore di un commento.', 'db-cookie-manager' ),
					'duration'    => '347 giorni',
					'provider'    => 'WordPress',
				),
				'comment_author_email_*' => array(
					'category'    => 'functional',
					'description' => __( 'Memorizza l\'email dell\'autore di un commento.', 'db-cookie-manager' ),
					'duration'    => '347 giorni',
					'provider'    => 'WordPress',
				),
				'comment_author_url_*' => array(
					'category'    => 'functional',
					'description' => __( 'Memorizza l\'URL dell\'autore di un commento.', 'db-cookie-manager' ),
					'duration'    => '347 giorni',
					'provider'    => 'WordPress',
				),
				'wp_lang' => array(
					'category'    => 'functional',
					'description' => __( 'Memorizza la lingua selezionata dall\'utente.', 'db-cookie-manager' ),
					'duration'    => 'Sessione',
					'provider'    => 'WordPress',
				),
				'PHPSESSID' => array(
					'category'    => 'functional',
					'description' => __( 'Identificatore di sessione PHP lato server.', 'db-cookie-manager' ),
					'duration'    => 'Sessione',
					'provider'    => 'PHP',
				),

				// WooCommerce.
				'woocommerce_cart_hash' => array(
					'category'    => 'functional',
					'description' => __( 'Hash del carrello — necessario per il funzionamento del checkout.', 'db-cookie-manager' ),
					'duration'    => 'Sessione',
					'provider'    => 'WooCommerce',
				),
				'woocommerce_items_in_cart' => array(
					'category'    => 'functional',
					'description' => __( 'Numero di articoli nel carrello.', 'db-cookie-manager' ),
					'duration'    => 'Sessione',
					'provider'    => 'WooCommerce',
				),
				'wp_woocommerce_session_*' => array(
					'category'    => 'functional',
					'description' => __( 'Identificatore di sessione WooCommerce per gestire carrello e checkout.', 'db-cookie-manager' ),
					'duration'    => '2 giorni',
					'provider'    => 'WooCommerce',
				),
				'woocommerce_recently_viewed' => array(
					'category'    => 'functional',
					'description' => __( 'Lista degli ultimi prodotti visualizzati per il widget "Visti di recente".', 'db-cookie-manager' ),
					'duration'    => 'Sessione',
					'provider'    => 'WooCommerce',
				),

				// Stripe (pagamento — funzionale).
				'__stripe_mid' => array(
					'category'    => 'functional',
					'description' => __( 'Prevenzione frodi durante il pagamento Stripe.', 'db-cookie-manager' ),
					'duration'    => '1 anno',
					'provider'    => 'Stripe',
				),
				'__stripe_sid' => array(
					'category'    => 'functional',
					'description' => __( 'Prevenzione frodi durante il pagamento Stripe (sessione).', 'db-cookie-manager' ),
					'duration'    => '30 minuti',
					'provider'    => 'Stripe',
				),

				// Cloudflare bot management (categoria functional: identifica
				// bot vs umani, è infrastruttura di sicurezza non tracking).
				'__cf_bm' => array(
					'category'    => 'functional',
					'description' => __( 'Cloudflare Bot Management — distingue bot da utenti umani.', 'db-cookie-manager' ),
					'duration'    => '30 minuti',
					'provider'    => 'Cloudflare',
				),
				'cf_clearance' => array(
					'category'    => 'functional',
					'description' => __( 'Cloudflare — verifica anti-DDoS / challenge superato.', 'db-cookie-manager' ),
					'duration'    => '1 anno',
					'provider'    => 'Cloudflare',
				),

				/* =========================================================
				 * STATISTICS — analytics CON identificatori personali.
				 * ========================================================= */

				// Google Analytics (Universal + GA4).
				'_ga' => array(
					'category'    => 'statistics',
					'description' => __( 'Google Analytics — identificatore univoco utente per misurare l\'utilizzo del sito.', 'db-cookie-manager' ),
					'duration'    => '2 anni',
					'provider'    => 'Google Analytics',
				),
				'_ga_*' => array(
					'category'    => 'statistics',
					'description' => __( 'Google Analytics 4 — stato di sessione per misurare l\'utilizzo del sito.', 'db-cookie-manager' ),
					'duration'    => '2 anni',
					'provider'    => 'Google Analytics',
				),
				'_gid' => array(
					'category'    => 'statistics',
					'description' => __( 'Google Analytics — identificatore univoco utente (durata breve).', 'db-cookie-manager' ),
					'duration'    => '24 ore',
					'provider'    => 'Google Analytics',
				),
				'_gat' => array(
					'category'    => 'statistics',
					'description' => __( 'Google Analytics — limita la frequenza delle richieste.', 'db-cookie-manager' ),
					'duration'    => '1 minuto',
					'provider'    => 'Google Analytics',
				),
				'_gat_*' => array(
					'category'    => 'statistics',
					'description' => __( 'Google Analytics — limita la frequenza delle richieste (proprietà).', 'db-cookie-manager' ),
					'duration'    => '1 minuto',
					'provider'    => 'Google Analytics',
				),
				'_dc_gtm_*' => array(
					'category'    => 'statistics',
					'description' => __( 'Google Tag Manager — controlla la frequenza delle richieste.', 'db-cookie-manager' ),
					'duration'    => '1 minuto',
					'provider'    => 'Google Tag Manager',
				),

				// Hotjar.
				'_hjSessionUser_*' => array(
					'category'    => 'statistics',
					'description' => __( 'Hotjar — identificatore utente persistente per heatmap e session recording.', 'db-cookie-manager' ),
					'duration'    => '1 anno',
					'provider'    => 'Hotjar',
				),
				'_hjSession_*' => array(
					'category'    => 'statistics',
					'description' => __( 'Hotjar — identificatore di sessione per heatmap e session recording.', 'db-cookie-manager' ),
					'duration'    => '30 minuti',
					'provider'    => 'Hotjar',
				),
				'_hjIncludedInSessionSample_*' => array(
					'category'    => 'statistics',
					'description' => __( 'Hotjar — flag per inclusione nel campione di session recording.', 'db-cookie-manager' ),
					'duration'    => '2 minuti',
					'provider'    => 'Hotjar',
				),
				'_hjAbsoluteSessionInProgress' => array(
					'category'    => 'statistics',
					'description' => __( 'Hotjar — rileva la prima pageview di una sessione.', 'db-cookie-manager' ),
					'duration'    => '30 minuti',
					'provider'    => 'Hotjar',
				),

				// Mixpanel / Heap / Amplitude / FullStory.
				'mp_*' => array(
					'category'    => 'statistics',
					'description' => __( 'Mixpanel — identificatore utente per analisi prodotto.', 'db-cookie-manager' ),
					'duration'    => '1 anno',
					'provider'    => 'Mixpanel',
				),
				'_mxpnl' => array(
					'category'    => 'statistics',
					'description' => __( 'Mixpanel — flag interno.', 'db-cookie-manager' ),
					'duration'    => '1 anno',
					'provider'    => 'Mixpanel',
				),
				'_hp2_*' => array(
					'category'    => 'statistics',
					'description' => __( 'Heap Analytics — identificatore sessione/utente.', 'db-cookie-manager' ),
					'duration'    => '1 anno',
					'provider'    => 'Heap Analytics',
				),
				'amplitude_id_*' => array(
					'category'    => 'statistics',
					'description' => __( 'Amplitude — identificatore utente per analisi prodotto.', 'db-cookie-manager' ),
					'duration'    => '10 anni',
					'provider'    => 'Amplitude',
				),
				'fs_uid' => array(
					'category'    => 'statistics',
					'description' => __( 'FullStory — identificatore utente per session replay.', 'db-cookie-manager' ),
					'duration'    => '1 anno',
					'provider'    => 'FullStory',
				),

				// Microsoft Clarity (session replay/heatmap).
				'_clck' => array(
					'category'    => 'statistics',
					'description' => __( 'Microsoft Clarity — identificatore utente per session replay e heatmap.', 'db-cookie-manager' ),
					'duration'    => '1 anno',
					'provider'    => 'Microsoft Clarity',
				),
				'_clsk' => array(
					'category'    => 'statistics',
					'description' => __( 'Microsoft Clarity — connette pageview multiple di una sessione.', 'db-cookie-manager' ),
					'duration'    => '1 giorno',
					'provider'    => 'Microsoft Clarity',
				),

				// Matomo / Piwik (self-hosted, configurato con cookie).
				'_pk_id*' => array(
					'category'    => 'statistics',
					'description' => __( 'Matomo / Piwik — identificatore utente persistente.', 'db-cookie-manager' ),
					'duration'    => '13 mesi',
					'provider'    => 'Matomo',
				),
				'_pk_ses*' => array(
					'category'    => 'statistics',
					'description' => __( 'Matomo / Piwik — identificatore di sessione.', 'db-cookie-manager' ),
					'duration'    => '30 minuti',
					'provider'    => 'Matomo',
				),

				/* =========================================================
				 * STATISTICS-ANONYMOUS — analytics aggregati cookieless.
				 *
				 * Plausible e Umami sono cookieless by design: in pratica
				 * NON dovresti vedere cookie da questi servizi. Le entry
				 * sotto coprono casi edge (versioni self-hosted con cookie
				 * di salt opzionali) e fungono da segnaposto per chi vuole
				 * estendere via filtro.
				 * ========================================================= */

				// (intenzionalmente vuoto: i pattern di matching sono
				// nel blocker; qui non aggiungiamo entry "fake" perché
				// produrrebbero falsi positivi nella tabella scansione.)

				/* =========================================================
				 * MARKETING — pixel pubblicitari, retargeting, social.
				 * ========================================================= */

				// Meta / Facebook.
				'_fbp' => array(
					'category'    => 'marketing',
					'description' => __( 'Meta Pixel — identificatore browser per pubblicità mirata e retargeting.', 'db-cookie-manager' ),
					'duration'    => '3 mesi',
					'provider'    => 'Meta / Facebook',
				),
				'_fbc' => array(
					'category'    => 'marketing',
					'description' => __( 'Meta Pixel — identificatore click pubblicitario per attribuzione.', 'db-cookie-manager' ),
					'duration'    => '3 mesi',
					'provider'    => 'Meta / Facebook',
				),
				'fr' => array(
					'category'    => 'marketing',
					'description' => __( 'Meta / Facebook — pubblicità mirata di terze parti.', 'db-cookie-manager' ),
					'duration'    => '3 mesi',
					'provider'    => 'Meta / Facebook',
				),

				// Google Ads / DoubleClick.
				'_gcl_au' => array(
					'category'    => 'marketing',
					'description' => __( 'Google Ads — identificatore conversione e attribuzione campagne.', 'db-cookie-manager' ),
					'duration'    => '3 mesi',
					'provider'    => 'Google Ads',
				),
				'IDE' => array(
					'category'    => 'marketing',
					'description' => __( 'Google DoubleClick — pubblicità mirata e retargeting.', 'db-cookie-manager' ),
					'duration'    => '13 mesi',
					'provider'    => 'Google DoubleClick',
				),
				'NID' => array(
					'category'    => 'marketing',
					'description' => __( 'Google — preferenze utente per pubblicità personalizzata.', 'db-cookie-manager' ),
					'duration'    => '6 mesi',
					'provider'    => 'Google',
				),
				'test_cookie' => array(
					'category'    => 'marketing',
					'description' => __( 'Google DoubleClick — verifica se il browser supporta i cookie.', 'db-cookie-manager' ),
					'duration'    => '15 minuti',
					'provider'    => 'Google DoubleClick',
				),

				// LinkedIn.
				'li_sugr' => array(
					'category'    => 'marketing',
					'description' => __( 'LinkedIn — identificatore browser per pubblicità mirata.', 'db-cookie-manager' ),
					'duration'    => '3 mesi',
					'provider'    => 'LinkedIn',
				),
				'lidc' => array(
					'category'    => 'marketing',
					'description' => __( 'LinkedIn — selezione data center per session affinity.', 'db-cookie-manager' ),
					'duration'    => '24 ore',
					'provider'    => 'LinkedIn',
				),
				'bcookie' => array(
					'category'    => 'marketing',
					'description' => __( 'LinkedIn — identificatore browser persistente.', 'db-cookie-manager' ),
					'duration'    => '1 anno',
					'provider'    => 'LinkedIn',
				),
				'UserMatchHistory' => array(
					'category'    => 'marketing',
					'description' => __( 'LinkedIn Ads — sincronizzazione ID utente per pubblicità.', 'db-cookie-manager' ),
					'duration'    => '30 giorni',
					'provider'    => 'LinkedIn',
				),

				// X (Twitter).
				'personalization_id' => array(
					'category'    => 'marketing',
					'description' => __( 'X (Twitter) — pubblicità personalizzata e analisi cross-site.', 'db-cookie-manager' ),
					'duration'    => '2 anni',
					'provider'    => 'X (Twitter)',
				),
				'guest_id' => array(
					'category'    => 'marketing',
					'description' => __( 'X (Twitter) — identifica visitatori non loggati per pubblicità mirata.', 'db-cookie-manager' ),
					'duration'    => '2 anni',
					'provider'    => 'X (Twitter)',
				),

				// TikTok.
				'_ttp' => array(
					'category'    => 'marketing',
					'description' => __( 'TikTok Pixel — tracciamento conversioni e attribuzione campagne.', 'db-cookie-manager' ),
					'duration'    => '13 mesi',
					'provider'    => 'TikTok',
				),

				// Pinterest.
				'_pinterest_*' => array(
					'category'    => 'marketing',
					'description' => __( 'Pinterest Tag — tracciamento conversioni e pubblicità mirata.', 'db-cookie-manager' ),
					'duration'    => '1 anno',
					'provider'    => 'Pinterest',
				),

				// Microsoft Bing Ads (UET).
				'MUID' => array(
					'category'    => 'marketing',
					'description' => __( 'Microsoft — identificatore univoco per pubblicità Bing/LinkedIn.', 'db-cookie-manager' ),
					'duration'    => '13 mesi',
					'provider'    => 'Microsoft Advertising',
				),
				'_uetsid' => array(
					'category'    => 'marketing',
					'description' => __( 'Bing Universal Event Tracking — identificatore di sessione.', 'db-cookie-manager' ),
					'duration'    => '24 ore',
					'provider'    => 'Microsoft Advertising',
				),
				'_uetvid' => array(
					'category'    => 'marketing',
					'description' => __( 'Bing Universal Event Tracking — identificatore visitatore persistente.', 'db-cookie-manager' ),
					'duration'    => '13 mesi',
					'provider'    => 'Microsoft Advertising',
				),

				// HubSpot.
				'__hstc' => array(
					'category'    => 'marketing',
					'description' => __( 'HubSpot — tracciamento visitatori (sorgente, prima/ultima visita).', 'db-cookie-manager' ),
					'duration'    => '6 mesi',
					'provider'    => 'HubSpot',
				),
				'hubspotutk' => array(
					'category'    => 'marketing',
					'description' => __( 'HubSpot — identificatore visitatore univoco.', 'db-cookie-manager' ),
					'duration'    => '6 mesi',
					'provider'    => 'HubSpot',
				),
				'__hssc' => array(
					'category'    => 'marketing',
					'description' => __( 'HubSpot — identificatore di sessione.', 'db-cookie-manager' ),
					'duration'    => '30 minuti',
					'provider'    => 'HubSpot',
				),
				'__hssrc' => array(
					'category'    => 'marketing',
					'description' => __( 'HubSpot — flag riavvio del browser durante una sessione.', 'db-cookie-manager' ),
					'duration'    => 'Sessione',
					'provider'    => 'HubSpot',
				),

				// YouTube embed.
				'YSC' => array(
					'category'    => 'marketing',
					'description' => __( 'YouTube — tracciamento visualizzazioni video embedded.', 'db-cookie-manager' ),
					'duration'    => 'Sessione',
					'provider'    => 'YouTube',
				),
				'VISITOR_INFO1_LIVE' => array(
					'category'    => 'marketing',
					'description' => __( 'YouTube — preferenze visualizzazione video e larghezza di banda.', 'db-cookie-manager' ),
					'duration'    => '6 mesi',
					'provider'    => 'YouTube',
				),
				'VISITOR_PRIVACY_METADATA' => array(
					'category'    => 'marketing',
					'description' => __( 'YouTube — gestione metadati privacy del visitatore.', 'db-cookie-manager' ),
					'duration'    => '6 mesi',
					'provider'    => 'YouTube',
				),

				// Vimeo.
				'vuid' => array(
					'category'    => 'marketing',
					'description' => __( 'Vimeo — identificatore visitatore per analytics video.', 'db-cookie-manager' ),
					'duration'    => '2 anni',
					'provider'    => 'Vimeo',
				),
			);

			/**
			 * Filtra l'elenco dei cookie noti.
			 *
			 * @param array $cookies Mappa pattern → metadata.
			 */
			return apply_filters( 'dbcm_known_cookies', $cookies );
		}

		/* =====================================================================
		 * MATCHING
		 * ================================================================== */

		/**
		 * Identifica un cookie dato il suo nome.
		 *
		 * Strategia: exact match → wildcard match (`*` glob-style) →
		 * fallback `marketing` con descrizione esplicita "non identificato".
		 *
		 * Cambio rispetto a 2.0.1: il fallback non è più 'sconosciuto' ma
		 * 'marketing'. Coerente col blocker (cookie sconosciuto = trattato
		 * come pubblicitario per default, "safer"). La descrizione segnala
		 * comunque che richiede revisione manuale.
		 *
		 * @param string $name
		 * @return array
		 */
		public static function identify_cookie( $name ) {
			$known = self::get_known_cookies();

			// Exact match.
			if ( isset( $known[ $name ] ) ) {
				return $known[ $name ];
			}

			// Wildcard match.
			foreach ( $known as $pattern => $info ) {
				if ( false === strpos( $pattern, '*' ) ) {
					continue;
				}
				$regex = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/';
				if ( preg_match( $regex, $name ) ) {
					return $info;
				}
			}

			// Fallback: cookie non identificato → marketing (safer-by-default).
			return array(
				'category'    => 'marketing',
				'description' => __( 'Cookie non identificato — verificare manualmente la finalità prima della pubblicazione.', 'db-cookie-manager' ),
				'duration'    => __( 'Sconosciuta', 'db-cookie-manager' ),
				'provider'    => self::guess_provider( $name ),
			);
		}

		/**
		 * Tenta di indovinare il provider dal nome cookie.
		 *
		 * Esteso rispetto a 2.0.1: aggiunti TikTok, Pinterest, Microsoft,
		 * Stripe, Mailchimp, ConvertKit, Intercom, Drift, Crisp.
		 *
		 * @param string $name
		 * @return string
		 */
		private static function guess_provider( $name ) {
			$prefixes = array(
				'_ga'         => 'Google Analytics',
				'_gid'        => 'Google Analytics',
				'_gat'        => 'Google Analytics',
				'_gcl'        => 'Google Ads',
				'_dc_gtm'     => 'Google Tag Manager',
				'_fbp'        => 'Meta / Facebook',
				'_fbc'        => 'Meta / Facebook',
				'_hj'         => 'Hotjar',
				'__hs'        => 'HubSpot',
				'__hssrc'     => 'HubSpot',
				'wp-'         => 'WordPress',
				'wordpress'   => 'WordPress',
				'comment_'    => 'WordPress',
				'woo'         => 'WooCommerce',
				'cmplz'       => 'Complianz',
				'cookielaw'   => 'CookieYes',
				'__cf'        => 'Cloudflare',
				'cf_'         => 'Cloudflare',
				'__stripe'    => 'Stripe',
				'li_'         => 'LinkedIn',
				'lidc'        => 'LinkedIn',
				'bcookie'     => 'LinkedIn',
				'_tt'         => 'TikTok',
				'_pinterest'  => 'Pinterest',
				'_uet'        => 'Microsoft Advertising',
				'MUID'        => 'Microsoft',
				'_clck'       => 'Microsoft Clarity',
				'_clsk'       => 'Microsoft Clarity',
				'mp_'         => 'Mixpanel',
				'_hp2'        => 'Heap Analytics',
				'amplitude_'  => 'Amplitude',
				'mc_'         => 'Mailchimp',
				'ck_'         => 'ConvertKit',
				'intercom'    => 'Intercom',
				'drift_'      => 'Drift',
				'crisp-'      => 'Crisp',
				'_pk_'        => 'Matomo',
			);

			foreach ( $prefixes as $prefix => $provider ) {
				if ( 0 === stripos( $name, $prefix ) ) {
					return $provider;
				}
			}

			return __( 'Sconosciuto', 'db-cookie-manager' );
		}

		/* =====================================================================
		 * HELPER per UI / policy generator
		 * ================================================================== */

		/**
		 * Etichetta human-readable di una categoria, per badge admin
		 * e tabelle della cookie policy.
		 *
		 * @param string $category
		 * @return string
		 */
		public static function get_category_label( $category ) {
			$labels = array(
				'functional'           => __( 'Tecnici (necessari)', 'db-cookie-manager' ),
				'preferences'          => __( 'Preferenze', 'db-cookie-manager' ),
				'statistics'           => __( 'Statistiche', 'db-cookie-manager' ),
				'statistics-anonymous' => __( 'Statistiche anonime', 'db-cookie-manager' ),
				'marketing'            => __( 'Marketing / Profilazione', 'db-cookie-manager' ),
			);
			return $labels[ $category ] ?? $category;
		}

		/**
		 * Colore badge per UI admin. Stile flat moderno coerente con
		 * il design system DB Admin UI.
		 *
		 * @param string $category
		 * @return string
		 */
		public static function get_category_color( $category ) {
			$colors = array(
				'functional'           => '#1d6e3f', // verde (db-success)
				'preferences'          => '#7a5d00', // ocra (db-warning-dark)
				'statistics'           => '#2271b1', // blu (db-primary)
				'statistics-anonymous' => '#3858a8', // blu più tenue
				'marketing'            => '#d63638', // rosso (db-danger)
			);
			return $colors[ $category ] ?? '#646970';
		}

		/**
		 * Descrizione breve per categoria, usata nella cookie policy.
		 *
		 * @param string $category
		 * @return string
		 */
		public static function get_category_description( $category ) {
			$descriptions = array(
				'functional' => __(
					'Questi cookie sono essenziali per il corretto funzionamento del sito (autenticazione, carrello, sessione, anti-frode). Non richiedono il consenso dell\'utente ai sensi dell\'art. 122 del D.Lgs. 196/2003.',
					'db-cookie-manager'
				),
				'preferences' => __(
					'Questi cookie memorizzano scelte dell\'utente come la lingua o la regione, non strettamente necessarie ma utili a migliorare l\'esperienza. Richiedono il consenso dell\'utente.',
					'db-cookie-manager'
				),
				'statistics' => __(
					'Questi cookie raccolgono informazioni sull\'utilizzo del sito utilizzando identificatori personali (es. Google Analytics, Hotjar). Richiedono il consenso esplicito dell\'utente.',
					'db-cookie-manager'
				),
				'statistics-anonymous' => __(
					'Questi servizi misurano l\'utilizzo del sito in forma aggregata, senza identificare l\'utente (es. Plausible, Umami). Per le linee guida del Garante, possono essere assimilati ai cookie tecnici se anonimizzati propriamente; alcune autorità richiedono comunque il consenso.',
					'db-cookie-manager'
				),
				'marketing' => __(
					'Questi cookie sono utilizzati per profilare l\'utente, mostrare pubblicità mirata e tracciare la navigazione tra siti diversi (retargeting, social pixel, embed video). Richiedono il consenso esplicito dell\'utente.',
					'db-cookie-manager'
				),
			);
			return $descriptions[ $category ] ?? '';
		}
	}
}
