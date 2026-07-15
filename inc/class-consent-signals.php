<?php
/**
 * DBCM_Consent_Signals — segnali di consenso verso piattaforme terze.
 *
 * Provider supportati (tutti opt-in, OFF di default):
 *  - Google Consent Mode v2 (GCM)      — setting 'gcm_enabled'
 *  - Microsoft UET Consent Mode        — setting 'uet_enabled'
 *  - Microsoft Clarity ConsentV2       — setting 'clarity_enabled'
 *
 * Responsabilità:
 *  - Iniettare, il prima possibile nel <head>, i comandi di DEFAULT con
 *    tutti i segnali a 'denied' (privacy by default, GDPR Art. 25), PRIMA
 *    che i rispettivi tag si carichino.
 *  - Esporre alla config del banner i mapping categoria WP Consent API →
 *    segnale, così banner.js può inviare l'update al consenso (e alla
 *    revoca, Art. 7(3): la revoca riporta i segnali a 'denied').
 *
 * Non gestisce:
 *  - L'invio degli update → lo fa banner.js al commit del consenso.
 *  - Il caricamento dei tag GA4/UET/Clarity → resta responsabilità
 *    dell'utente (o del blocker, se lo script è bloccato senza consenso).
 *
 * Riferimenti normativi/tecnici:
 *  - UET: Microsoft applica il Consent Mode in EEA/UK/CH; unico segnale
 *    'ad_storage', default 'denied' da emettere prima del tag UET.
 *  - Clarity: dal 31/10/2025 Microsoft applica i segnali di consenso in
 *    EEA/UK/CH; API 'consentv2' con chiavi camelCase 'ad_Storage' e
 *    'analytics_Storage' (la vecchia clarity('consent') è deprecata).
 *
 * @package DBCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DBCM_Consent_Signals' ) ) {

	class DBCM_Consent_Signals {

		/**
		 * I quattro segnali di GCM v2. ad_user_data e ad_personalization sono
		 * i due aggiunti dalla v2 (obbligatori per remarketing/audience Ads
		 * nello SEE dal marzo 2024).
		 *
		 * @var string[]
		 */
		const SIGNALS = array(
			'analytics_storage',
			'ad_storage',
			'ad_user_data',
			'ad_personalization',
		);

		/**
		 * Segnali Microsoft UET Consent Mode. UET supporta un solo segnale.
		 *
		 * @var string[]
		 */
		const UET_SIGNALS = array(
			'ad_storage',
		);

		/**
		 * Segnali Microsoft Clarity ConsentV2. Il camelCase con la S
		 * maiuscola è quello richiesto dall'API ufficiale — NON normalizzare.
		 *
		 * @var string[]
		 */
		const CLARITY_SIGNALS = array(
			'ad_Storage',
			'analytics_Storage',
		);

		/**
		 * Inizializzazione — chiamata da DBCM_Plugin->init_modules().
		 *
		 * Gli snippet di default vanno emessi il prima possibile: wp_head con
		 * priorità 1, così precedono l'enqueue dei tag Google/Microsoft.
		 *
		 * @return void
		 */
		public static function init() {
			if ( ! self::is_enabled() && ! self::uet_enabled() && ! self::clarity_enabled() ) {
				return;
			}
			// Priorità 1: prima di tutto il resto in <head>.
			add_action( 'wp_head', array( __CLASS__, 'print_default_snippet' ), 1 );
		}

		/**
		 * True se GCM v2 è attivo nelle impostazioni.
		 *
		 * @return bool
		 */
		public static function is_enabled() {
			return (bool) DBCM_Settings::get( 'gcm_enabled', false );
		}

		/**
		 * True se Microsoft UET Consent Mode è attivo nelle impostazioni.
		 *
		 * @return bool
		 */
		public static function uet_enabled() {
			return (bool) DBCM_Settings::get( 'uet_enabled', false );
		}

		/**
		 * True se Microsoft Clarity ConsentV2 è attivo nelle impostazioni.
		 *
		 * @return bool
		 */
		public static function clarity_enabled() {
			return (bool) DBCM_Settings::get( 'clarity_enabled', false );
		}

		/**
		 * Mapping categoria WP Consent API → segnali GCM v2.
		 *
		 * Un consenso concesso alla categoria pone i segnali associati a
		 * 'granted'; l'assenza di consenso li lascia a 'denied'.
		 *
		 * Default sensato, personalizzabile via filtro 'dbcm_gcm_mapping':
		 *  - statistics / statistics-anonymous → analytics_storage
		 *  - marketing                         → ad_storage, ad_user_data,
		 *                                        ad_personalization
		 *
		 * @return array<string,string[]>  categoria => [segnali]
		 */
		public static function mapping() {
			$map = array(
				'statistics'           => array( 'analytics_storage' ),
				'statistics-anonymous' => array( 'analytics_storage' ),
				'marketing'            => array( 'ad_storage', 'ad_user_data', 'ad_personalization' ),
			);

			/**
			 * Personalizza il mapping categoria → segnali GCM.
			 *
			 * @param array $map
			 */
			$map = apply_filters( 'dbcm_gcm_mapping', $map );

			return is_array( $map ) ? $map : array();
		}

		/**
		 * Mapping categoria WP Consent API → segnali UET.
		 *
		 * UET ha il solo 'ad_storage' (tracciamento pubblicitario Microsoft
		 * Advertising) → legato alla categoria marketing.
		 * Personalizzabile via filtro 'dbcm_uet_mapping'.
		 *
		 * @return array<string,string[]>  categoria => [segnali]
		 */
		public static function uet_mapping() {
			$map = array(
				'marketing' => array( 'ad_storage' ),
			);

			/**
			 * Personalizza il mapping categoria → segnali UET.
			 *
			 * @param array $map
			 */
			$map = apply_filters( 'dbcm_uet_mapping', $map );

			return is_array( $map ) ? $map : array();
		}

		/**
		 * Mapping categoria WP Consent API → segnali Clarity ConsentV2.
		 *
		 * Scelta conservativa: 'statistics-anonymous' NON è mappata.
		 * Clarity registra sessioni individuali (recording, heatmap per
		 * utente) — non è assimilabile a statistica anonima/aggregata, a
		 * differenza di un GA4 configurato in modalità anonima. Chi ritiene
		 * il proprio uso di Clarity effettivamente anonimo può estendere il
		 * mapping via filtro 'dbcm_clarity_mapping'.
		 *
		 * @return array<string,string[]>  categoria => [segnali]
		 */
		public static function clarity_mapping() {
			$map = array(
				'statistics' => array( 'analytics_Storage' ),
				'marketing'  => array( 'ad_Storage' ),
			);

			/**
			 * Personalizza il mapping categoria → segnali Clarity.
			 *
			 * @param array $map
			 */
			$map = apply_filters( 'dbcm_clarity_mapping', $map );

			return is_array( $map ) ? $map : array();
		}

		/**
		 * Stampa gli snippet di DEFAULT dei provider attivi nel <head>.
		 *
		 * Tutti i segnali a 'denied' (privacy by default). Ogni blocco è
		 * emesso solo se il rispettivo provider è attivo nelle impostazioni.
		 *
		 * @return void
		 */
		public static function print_default_snippet() {
			if ( self::is_enabled() ) {
				self::print_gcm_default();
			}
			if ( self::uet_enabled() ) {
				self::print_uet_default();
			}
			if ( self::clarity_enabled() ) {
				self::print_clarity_default();
			}
		}

		/**
		 * Snippet di default GCM v2: gtag('consent','default',{...denied}).
		 *
		 * Aggiunge wait_for_update per dare tempo a banner.js di inviare
		 * l'eventuale 'update' se l'utente ha già un consenso salvato.
		 *
		 * @return void
		 */
		public static function print_gcm_default() {
			$denied = array();
			foreach ( self::SIGNALS as $signal ) {
				$denied[ $signal ] = 'denied';
			}
			$default = array_merge(
				$denied,
				array( 'wait_for_update' => 500 )
			);

			/**
			 * Permette di modificare il payload del default (es. impostare
			 * 'region' o aggiungere segnali). Deve restare denied-by-default.
			 *
			 * @param array $default
			 */
			$default = (array) apply_filters( 'dbcm_gcm_default_payload', $default );

			echo "<!-- DB Cookie Manager: Google Consent Mode v2 (default) -->\n";
			echo "<script>\n";
			echo "window.dataLayer = window.dataLayer || [];\n";
			echo "function gtag(){dataLayer.push(arguments);}\n";
			echo 'gtag("consent","default",' . wp_json_encode( $default ) . ");\n";
			echo "</script>\n";
		}

		/**
		 * Snippet di default Microsoft UET: ad_storage denied sulla coda
		 * uetq, PRIMA che il tag UET si carichi (requisito Microsoft per
		 * EEA/UK/CH).
		 *
		 * @return void
		 */
		public static function print_uet_default() {
			$default = array();
			foreach ( self::UET_SIGNALS as $signal ) {
				$default[ $signal ] = 'denied';
			}

			/**
			 * Permette di modificare il payload del default UET.
			 * Deve restare denied-by-default.
			 *
			 * @param array $default
			 */
			$default = (array) apply_filters( 'dbcm_uet_default_payload', $default );

			echo "<!-- DB Cookie Manager: Microsoft UET Consent Mode (default) -->\n";
			echo "<script>\n";
			echo "window.uetq = window.uetq || [];\n";
			echo 'window.uetq.push("consent","default",' . wp_json_encode( $default ) . ");\n";
			echo "</script>\n";
		}

		/**
		 * Snippet di default Microsoft Clarity: stub della coda clarity +
		 * consentv2 con tutti i segnali denied.
		 *
		 * Senza segnale Clarity gira comunque in no-consent mode, ma il
		 * denied esplicito rende lo stato deterministico anche su progetti
		 * con cookie automatici attivi.
		 *
		 * @return void
		 */
		public static function print_clarity_default() {
			$default = array();
			foreach ( self::CLARITY_SIGNALS as $signal ) {
				$default[ $signal ] = 'denied';
			}

			/**
			 * Permette di modificare il payload del default Clarity.
			 * Deve restare denied-by-default.
			 *
			 * @param array $default
			 */
			$default = (array) apply_filters( 'dbcm_clarity_default_payload', $default );

			echo "<!-- DB Cookie Manager: Microsoft Clarity ConsentV2 (default) -->\n";
			echo "<script>\n";
			echo 'window.clarity = window.clarity || function(){(window.clarity.q = window.clarity.q || []).push(arguments);};' . "\n";
			echo 'window.clarity("consentv2",' . wp_json_encode( $default ) . ");\n";
			echo "</script>\n";
		}
	}
}