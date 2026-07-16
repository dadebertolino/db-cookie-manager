<?php
/**
 * DBCM_Settings — Core data layer per le impostazioni.
 *
 * Responsabilità:
 *  - Definire le 5 categorie standard WP Consent API.
 *  - Esporre i default e fornire un'API get/update unificata.
 *  - NON gestire l'UI admin (verrà aggiunta in uno step successivo).
 *
 * Le categorie sono allineate alle 5 standard della WP Consent API:
 *   functional            → sempre true (necessari, niente toggle)
 *   preferences           → preferenze utente non essenziali
 *   statistics            → analytics con identificatori (es. GA4)
 *   statistics-anonymous  → analytics aggregati cookieless (Plausible, Umami)
 *   marketing             → tracking pubblicitario, retargeting, social pixel
 *
 * @package DBCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DBCM_Settings' ) ) {

	class DBCM_Settings {

		/**
		 * Nome dell'option WP che contiene tutte le impostazioni del plugin.
		 * Usiamo una singola option (array) anziché molte chiavi sparse:
		 *  - meno query (1 sola get_option per pagina vista)
		 *  - più facile fare backup/restore
		 *  - allineamento con il pattern degli altri plugin DB (DBSEO_Core::get_settings)
		 */
		const OPTION_KEY = 'dbcm_settings';

		/**
		 * Nome del cookie scritto dal banner. Allineato a 2.x per non rompere
		 * letture esterne, ma il FORMATO del valore è cambiato (vedi class-banner).
		 */
		const COOKIE_NAME = 'dbcm_consent';

		/**
		 * Versione dello schema del cookie.
		 * 2.x usava { necessary, performance, analytics, marketing }.
		 * 3.x usa le 5 chiavi standard WP Consent API.
		 *
		 * Il banner JS usa questo numero per decidere se accettare un cookie
		 * preesistente o richiedere nuovamente il consenso.
		 */
		const COOKIE_SCHEMA_VERSION = 3;

		/**
		 * Le 5 categorie standard WP Consent API.
		 *
		 * 'functional' è speciale: sempre concessa, non ha un toggle UI,
		 * include i cookie strettamente necessari (sessione, autenticazione,
		 * preferenze del banner stesso, CSRF).
		 *
		 * @return array
		 */
		public static function categories() {
			return array(
				'functional',
				'preferences',
				'statistics',
				'statistics-anonymous',
				'marketing',
			);
		}

		/**
		 * Categorie esposte all'utente nel banner (escluso 'functional').
		 *
		 * @return array
		 */
		public static function categories_optional() {
			return array(
				'preferences',
				'statistics',
				'statistics-anonymous',
				'marketing',
			);
		}

		/**
		 * Verifica che una stringa sia una categoria valida.
		 *
		 * @param string $cat
		 * @return bool
		 */
		public static function is_valid_category( $cat ) {
			return in_array( $cat, self::categories(), true );
		}

		/**
		 * Default delle impostazioni.
		 *
		 * Questi default vengono uniti (array_merge ricorsivo conservativo)
		 * con il valore salvato in DB, così aggiunte future di nuove chiavi
		 * non rompono le installazioni esistenti.
		 *
		 * @return array
		 */
		public static function defaults() {
			return array(
				/* ---- Banner: visibilità e comportamento ---- */
				'banner_enabled'        => true,
				'banner_layout'         => 'box',          // box | bar
				'banner_position'       => 'bottom-right', // bottom-right | bottom-left | bottom-center
				'banner_overlay'        => false,           // sfondo scuro semitrasparente
				'banner_theme'          => 'light',         // light | dark | auto
				'show_reopen_btn'       => true,
				'reopen_position'       => 'bottom-left',

				/* ---- Consenso ---- */
				'consent_duration'      => 365,             // giorni di validità del cookie

				/* ---- Versione del consenso (3.5.0) ----
				 * Contatore manuale che identifica la configurazione dei
				 * trattamenti al momento della scelta (Art. 4(11) + 6(1)(a):
				 * il consenso è specifico e informato rispetto a ciò che era
				 * presentato). L'admin lo incrementa deliberatamente dal
				 * bottone "Richiedi nuovo consenso" quando mutano in modo
				 * significativo le condizioni del trattamento (Linee guida
				 * Garante 10/6/2021 §5): i cookie con versione diversa
				 * vengono trattati come assenza di consenso (rigoroso).
				 * NOTA: cookie senza campo 'cv' = versione 1, così
				 * l'aggiornamento del plugin da solo non ri-prompta nessuno.
				 * Sostituisce il vecchio 'reconsent_on_change' (mai cablato). */
				'consent_version'       => 1,

				/* ---- Default delle categorie opzionali (true = pre-selezionate) ----
				 * GDPR-compliant: tutte false. L'utente deve esprimere consenso esplicito.
				 * (Il SEO Manager ha lo stesso default DENIED.) */
				'default_preferences'           => false,
				'default_statistics'            => false,
				'default_statistics_anonymous'  => false,
				'default_marketing'             => false,

				/* ---- Lingue ---- */
				'banner_languages'      => array( 'it', 'en' ),
				'banner_default_lang'   => 'it',

				/* ---- Blocker preventivo ---- */
				'auto_block'            => true,            // blocca automaticamente script noti

				/* ---- Consent log ---- */
				'consent_log_enabled'   => true,
				'consent_log_retention' => 365,             // giorni
				'consent_log_user_agent' => 'aggregate',    // none | aggregate | full

				/* ---- Cookie Policy ---- */
				'policy_page_id'        => 0,               // ID pagina con la cookie policy

				/* ---- Aspetto banner (colori) ---- */
				'banner_color_bg'        => '#ffffff',
				'banner_color_text'      => '#1d2327',
				'banner_color_btn'       => '#2271b1',
				'banner_color_btn_text'  => '#ffffff',
				'banner_credits'         => true,           // mostra "Powered by DB Cookie Manager"
				'banner_custom_css'      => '',

				/* ---- Segnali browser (priorità 3 — verranno implementati negli step successivi) ---- */
				'respect_dnt'            => false,
				'respect_gpc'            => true,           // GPC è uno standard più solido di DNT

				/* ---- Geo-targeting (priorità 4 — verrà implementato negli step successivi) ---- */
				'geo_targeting'          => false,          // false = banner sempre, true = solo UE

				/* ---- Google Consent Mode v2 (opt-in) ----
				 * OFF di default: GCM ha senso solo per chi usa tag Google
				 * (GA4/Google Ads). Attivarlo per tutti inietterebbe gtag
				 * inutilmente e potrebbe confliggere con implementazioni GCM
				 * esistenti (es. via GTM). Quando ON, il default è 'denied' su
				 * tutti i segnali (privacy by default, Art. 25). */
				'gcm_enabled'            => false,

				/* ---- Microsoft UET Consent Mode (opt-in) ----
				 * OFF di default, come GCM: serve solo a chi usa tag UET
				 * (Microsoft Advertising / Bing Ads). Quando ON, il default
				 * è ad_storage 'denied' emesso prima del tag (privacy by
				 * default, Art. 25; enforcement Microsoft in EEA/UK/CH). */
				'uet_enabled'            => false,

				/* ---- Microsoft Clarity ConsentV2 (opt-in) ----
				 * OFF di default: serve solo a chi usa Clarity. Quando ON,
				 * ad_Storage e analytics_Storage partono 'denied'; l'update
				 * segue le categorie statistics/marketing (enforcement
				 * Microsoft dal 31/10/2025 in EEA/UK/CH). */
				'clarity_enabled'        => false,

				/* ---- Localizzazione Google Fonts (opt-in) ----
				 * OFF di default. Quando ON, i <link> verso fonts.googleapis.com
				 * vengono rimossi dall'HTML: il browser non contatta più i server
				 * Google al caricamento della pagina, quindi l'IP dell'utente non
				 * viene trasmesso a Google (nessuna base giuridica richiesta —
				 * la trasmissione avverrebbe prima di ogni consenso). Il sito
				 * ripiega sui font di sistema definiti nel fallback CSS. */
				'localize_google_fonts'  => false,
			);
		}

		/**
		 * Restituisce TUTTE le impostazioni, con i default applicati.
		 *
		 * @return array
		 */
		public static function all() {
			$saved = get_option( self::OPTION_KEY, array() );
			if ( ! is_array( $saved ) ) {
				$saved = array();
			}
			return array_merge( self::defaults(), $saved );
		}

		/**
		 * Restituisce una singola impostazione.
		 *
		 * @param string $key
		 * @param mixed  $fallback
		 * @return mixed
		 */
		public static function get( $key, $fallback = null ) {
			$all = self::all();
			if ( array_key_exists( $key, $all ) ) {
				return $all[ $key ];
			}
			return $fallback;
		}

		/**
		 * Versione corrente del consenso, sempre >= 1.
		 *
		 * Sorgente di verità unica per consent-api (validazione cookie),
		 * banner (config JS) e consent-log (colonna consent_version).
		 * Il clamp a 1 protegge da valori corrotti in DB.
		 *
		 * @since 3.5.0
		 * @return int
		 */
		public static function consent_version() {
			return max( 1, (int) self::get( 'consent_version', 1 ) );
		}

		/**
		 * Aggiorna una singola impostazione.
		 *
		 * @param string $key
		 * @param mixed  $value
		 * @return bool
		 */
		public static function update( $key, $value ) {
			$all         = self::all();
			$all[ $key ] = $value;
			return update_option( self::OPTION_KEY, $all );
		}

		/**
		 * Sostituisce in blocco le impostazioni (mantenendo i default).
		 * Da usare in import/restore.
		 *
		 * @param array $new
		 * @return bool
		 */
		public static function replace_all( $new ) {
			if ( ! is_array( $new ) ) {
				return false;
			}
			$merged = array_merge( self::defaults(), $new );
			return update_option( self::OPTION_KEY, $merged );
		}

		/**
		 * Inizializzazione: chiamata da DBCM_Plugin->init_modules().
		 * Per ora non registra hook (la UI admin arriverà in uno step
		 * successivo). Mantengo il pattern per coerenza con gli altri moduli.
		 */
		public static function init() {
			// Stub — verrà popolato negli step successivi (admin UI, language switcher, ecc.).
		}
	}
}
