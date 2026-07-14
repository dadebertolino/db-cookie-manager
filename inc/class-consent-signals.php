<?php
/**
 * DBCM_Consent_Signals — Google Consent Mode v2 (GCM v2).
 *
 * Responsabilità:
 *  - Iniettare, il prima possibile nel <head>, il comando di DEFAULT di
 *    Google Consent Mode con TUTTI i segnali a 'denied' (privacy by default,
 *    GDPR Art. 25). Questo deve girare PRIMA che qualsiasi tag Google si
 *    carichi, altrimenti il tag parte già in tracking.
 *  - Esporre alla config del banner il mapping categoria WP Consent API →
 *    segnale GCM, così banner.js può inviare 'update' al consenso.
 *
 * Non gestisce:
 *  - L'invio di gtag('consent','update',...) → lo fa banner.js al commit del
 *    consenso, usando il mapping esposto qui.
 *  - Il caricamento di GA4/Google Ads → resta responsabilità dell'utente
 *    (o del blocker, se lo script è bloccato finché manca il consenso).
 *
 * Attivo SOLO se l'impostazione 'gcm_enabled' è true (opt-in dall'admin).
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
		 * Inizializzazione — chiamata da DBCM_Plugin->init_modules().
		 *
		 * Lo snippet default va emesso il prima possibile: usiamo wp_head con
		 * priorità 1 (più bassa possibile) così precede l'enqueue di GA4 e
		 * qualsiasi altro tag Google iniettato via wp_head.
		 *
		 * @return void
		 */
		public static function init() {
			if ( ! self::is_enabled() ) {
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
		 * Stampa lo snippet di DEFAULT di Consent Mode nel <head>.
		 *
		 * Tutti i segnali a 'denied'. Aggiunge anche i parametri di supporto
		 * consigliati da Google (wait_for_update per dare tempo all'update, e
		 * i comportamenti di modellazione lato Google restano attivi).
		 *
		 * @return void
		 */
		public static function print_default_snippet() {
			$denied = array();
			foreach ( self::SIGNALS as $signal ) {
				$denied[ $signal ] = 'denied';
			}
			// wait_for_update: ms di attesa prima che i tag agiscano, per dare
			// tempo a banner.js di inviare l'eventuale 'update' se l'utente ha
			// già un consenso salvato al reload.
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
	}
}