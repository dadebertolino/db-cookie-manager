<?php
/**
 * DBCM_Consent_API — Integrazione con WP Consent API.
 *
 * La WP Consent API (https://github.com/rlankhorst/wp-consent-level-api)
 * è uno standard "umbrella" tra plugin: definisce una funzione
 * wp_set_consent($category, $value) che notifica TUTTI i plugin attivi
 * della scelta dell'utente. Plugin come Site Kit, MonsterInsights, Yoast,
 * e DB SEO Manager la leggono via wp_has_consent().
 *
 * Senza questa integrazione, DB Cookie Manager era un sistema chiuso:
 * il SEO Manager non vedeva le scelte fatte nel banner, e GA4 non si
 * caricava nemmeno dopo "Accetta tutto" (vedi diagnosi 2.0.1, sez. 2.8).
 *
 * Questa classe risolve il problema su tre livelli:
 *
 * 1. REGISTRAZIONE: dichiara DB Cookie Manager come consent manager
 *    attivo (filtro 'wp_get_consent_type'). Tutti gli altri plugin
 *    sanno che c'è qualcuno che gestisce il consenso e usano i nostri
 *    valori invece di assumere "accept all".
 *
 * 2. SCRITTURA: quando l'utente clicca un bottone del banner, JS chiama
 *    AJAX dbcm_set_consent → questa classe invoca wp_set_consent() per
 *    ogni categoria. Lato client espone window.DBCM.setConsent().
 *
 * 3. LETTURA: helper has_consent($category) che usa wp_has_consent() se
 *    disponibile, altrimenti legge il cookie come fallback. Permette ad
 *    altri plugin DB di interrogare il consenso anche se la WP Consent
 *    API non è installata.
 *
 * @package DBCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DBCM_Consent_API' ) ) {

	class DBCM_Consent_API {

		/**
		 * Inizializzazione — chiamata da DBCM_Plugin->init_modules().
		 */
		public static function init() {
			// 1. Registra il plugin come consent manager attivo.
			add_filter( 'wp_get_consent_type', array( __CLASS__, 'declare_consent_type' ) );

			// 2. AJAX endpoint per sincronizzare il consenso lato server
			// quando il banner JS aggiorna le scelte. Disponibile sia
			// per utenti loggati sia per visitatori anonimi.
			add_action( 'wp_ajax_dbcm_set_consent', array( __CLASS__, 'ajax_set_consent' ) );
			add_action( 'wp_ajax_nopriv_dbcm_set_consent', array( __CLASS__, 'ajax_set_consent' ) );

			// 3. Hook per "spingere" il consenso letto dal cookie nella
			// WP Consent API a ogni page load. Questo è importante
			// perché wp_set_consent() salva i valori in cookie a vita
			// di sessione: vogliamo che ogni nuova page-view sappia
			// immediatamente quali categorie sono concesse, senza
			// aspettare che JS le ri-imposti.
			add_action( 'init', array( __CLASS__, 'hydrate_consent_from_cookie' ), 5 );
		}

		/* =====================================================================
		 * 1. REGISTRAZIONE
		 * ================================================================== */

		/**
		 * Dichiara il tipo di consenso gestito da questo plugin.
		 *
		 * Valori possibili (WP Consent API spec):
		 *   'optin'  → GDPR / EU: consenso esplicito richiesto prima di tracciare
		 *   'optout' → CCPA / US: tracking attivo finché l'utente non rifiuta
		 *
		 * Default: 'optin' (GDPR-compliant, allineato col target italiano/UE).
		 * Filtrabile via 'dbcm_consent_type' per casi avanzati.
		 *
		 * @param string $current_type Valore attuale (vuoto se nessun manager registrato).
		 * @return string
		 */
		public static function declare_consent_type( $current_type ) {
			// Se un altro plugin ha già dichiarato un tipo, non sovrascriviamo:
			// rispettiamo l'ordine di caricamento.
			if ( ! empty( $current_type ) ) {
				return $current_type;
			}
			return apply_filters( 'dbcm_consent_type', 'optin' );
		}

		/* =====================================================================
		 * 2. SCRITTURA — propagazione del consenso
		 * ================================================================== */

		/**
		 * Propaga le scelte dell'utente alla WP Consent API.
		 *
		 * Chiama wp_set_consent() per ognuna delle 5 categorie standard.
		 * 'functional' è sempre 'allow' (cookie tecnici sempre consentiti).
		 *
		 * @param array $consent Mappa categoria → bool. Es:
		 *                       array(
		 *                           'functional'           => true,
		 *                           'preferences'          => false,
		 *                           'statistics'           => true,
		 *                           'statistics-anonymous' => true,
		 *                           'marketing'            => false,
		 *                       )
		 * @return void
		 */
		public static function propagate_consent( $consent ) {
			if ( ! function_exists( 'wp_set_consent' ) ) {
				// WP Consent API non installata: niente da propagare.
				// (Il banner continua a funzionare via cookie diretto.)
				return;
			}

			foreach ( DBCM_Settings::categories() as $category ) {
				$value = ( 'functional' === $category )
					? 'allow'
					: ( ! empty( $consent[ $category ] ) ? 'allow' : 'deny' );
				wp_set_consent( $category, $value );
			}

			/**
			 * Hook dopo la propagazione del consenso.
			 *
			 * @param array $consent
			 */
			do_action( 'dbcm_consent_propagated', $consent );
		}

		/**
		 * AJAX handler chiamato dal banner JS quando l'utente cambia consenso.
		 *
		 * Verifica nonce, sanifica i valori, chiama propagate_consent() e
		 * (se abilitato) registra l'evento nel consent log.
		 *
		 * Risposta: { success: true, consent: { ... } }
		 */
		public static function ajax_set_consent() {
			check_ajax_referer( 'dbcm_consent_nonce', 'nonce' );

			$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : 'custom';
			if ( ! in_array( $type, array( 'accept_all', 'reject_all', 'custom' ), true ) ) {
				$type = 'custom';
			}

			$raw     = isset( $_POST['consent'] ) ? wp_unslash( $_POST['consent'] ) : '';
			$consent = self::sanitize_consent_payload( $raw );

			// Forza 'functional' sempre true (sicurezza lato server).
			$consent['functional'] = true;

			// Propaga alla WP Consent API.
			self::propagate_consent( $consent );

			/**
			 * Hook chiamato quando il consenso viene aggiornato via AJAX.
			 * Il consent log si aggancia qui per registrare la scelta.
			 *
			 * @param string $type    'accept_all' | 'reject_all' | 'custom'
			 * @param array  $consent Mappa categoria → bool.
			 */
			do_action( 'dbcm_consent_set', $type, $consent );

			wp_send_json_success(
				array(
					'consent' => $consent,
					'type'    => $type,
				)
			);
		}

		/**
		 * Sanifica il payload del consenso ricevuto via AJAX.
		 *
		 * Accetta sia un oggetto JSON stringificato sia un array. Filtra le
		 * chiavi per accettare SOLO categorie valide e converte i valori a
		 * boolean. Risultato: array sicuro con tutte le 5 chiavi sempre
		 * presenti (false di default).
		 *
		 * @param mixed $raw
		 * @return array
		 */
		private static function sanitize_consent_payload( $raw ) {
			if ( is_string( $raw ) ) {
				$decoded = json_decode( $raw, true );
				$raw     = is_array( $decoded ) ? $decoded : array();
			}
			if ( ! is_array( $raw ) ) {
				$raw = array();
			}

			$out = array();
			foreach ( DBCM_Settings::categories() as $category ) {
				$out[ $category ] = ! empty( $raw[ $category ] );
			}
			return $out;
		}

		/* =====================================================================
		 * 3. LETTURA — helper unificato
		 * ================================================================== */

		/**
		 * Restituisce true se l'utente ha concesso la categoria indicata.
		 *
		 * Strategia di fallback in ordine di priorità:
		 *   1. wp_has_consent() se disponibile (sorgente di verità unica
		 *      quando la WP Consent API è installata).
		 *   2. Cookie dbcm_consent (parsing diretto del JSON).
		 *   3. false (default GDPR-compliant: nessun consenso = nego).
		 *
		 * @param string $category Una delle 5 categorie standard.
		 * @return bool
		 */
		public static function has_consent( $category ) {
			if ( ! DBCM_Settings::is_valid_category( $category ) ) {
				return false;
			}

			// 'functional' è sempre concessa per definizione.
			if ( 'functional' === $category ) {
				return true;
			}

			// Strada 1: WP Consent API.
			if ( function_exists( 'wp_has_consent' ) ) {
				return (bool) wp_has_consent( $category );
			}

			// Strada 2: cookie del banner (fallback).
			$cookie = self::read_cookie();
			if ( null === $cookie ) {
				return false;
			}
			return ! empty( $cookie[ $category ] );
		}

		/**
		 * Legge e parsa il cookie del banner.
		 *
		 * Restituisce un array normalizzato con le 5 chiavi standard, o
		 * null se il cookie non esiste / è malformato / ha schema
		 * incompatibile.
		 *
		 * @return array|null
		 */
		public static function read_cookie() {
			$decoded = self::decode_cookie();
			if ( null === $decoded ) {
				return null;
			}

			// Versione del consenso (3.5.0): un cookie scritto sotto una
			// versione diversa da quella corrente NON copre i trattamenti
			// attuali (Art. 4(11): il consenso è specifico e informato
			// rispetto a ciò che era presentato al momento della scelta).
			// Trattato come assenza di consenso → banner ri-mostrato.
			if ( self::cookie_version_is_stale( $decoded ) ) {
				return null;
			}

			return self::normalize_categories( $decoded );
		}

		/**
		 * Decodifica il cookie grezzo: esiste + JSON valido + schema 3.x.
		 * NON valida la versione del consenso (compito di read_cookie /
		 * hydrate, che al mismatch reagiscono in modo diverso).
		 *
		 * @since 3.5.0
		 * @return array|null Payload decodificato o null.
		 */
		private static function decode_cookie() {
			$name = DBCM_Settings::COOKIE_NAME;
			if ( empty( $_COOKIE[ $name ] ) ) {
				return null;
			}

			$raw     = wp_unslash( $_COOKIE[ $name ] );
			$raw     = urldecode( $raw );
			$decoded = json_decode( $raw, true );
			if ( ! is_array( $decoded ) ) {
				return null;
			}

			// Schema check: solo 3.x è supportato in lettura. Cookie con
			// schema diverso vengono ignorati → l'utente vedrà di nuovo
			// il banner. Coerente con la decisione "no retrocompatibilità"
			// del rebuild 3.0.0.
			$schema = isset( $decoded['v'] ) ? (int) $decoded['v'] : 0;
			if ( DBCM_Settings::COOKIE_SCHEMA_VERSION !== $schema ) {
				return null;
			}

			return $decoded;
		}

		/**
		 * True se il cookie è stato scritto sotto una versione del consenso
		 * diversa da quella corrente.
		 *
		 * Retrocompatibilità: 'cv' assente = versione 1. Così l'update del
		 * plugin da 3.4.x NON invalida i consensi esistenti; solo il bump
		 * esplicito dell'admin lo fa.
		 *
		 * @since 3.5.0
		 * @param array $decoded Payload del cookie già decodificato.
		 * @return bool
		 */
		private static function cookie_version_is_stale( $decoded ) {
			$cv = isset( $decoded['cv'] ) ? (int) $decoded['cv'] : 1;
			return DBCM_Settings::consent_version() !== $cv;
		}

		/**
		 * Riduce il payload alle 5 categorie standard (bool, sempre presenti).
		 *
		 * @since 3.5.0
		 * @param array $decoded
		 * @return array
		 */
		private static function normalize_categories( $decoded ) {
			$out = array();
			foreach ( DBCM_Settings::categories() as $category ) {
				$out[ $category ] = ! empty( $decoded[ $category ] );
			}
			return $out;
		}

		/* =====================================================================
		 * 4. HYDRATE — propagazione automatica al page load
		 * ================================================================== */

		/**
		 * A ogni page load, se c'è già un cookie di consenso valido,
		 * propaga le scelte alla WP Consent API.
		 *
		 * Senza questo, plugin esterni che leggono wp_has_consent() al primo
		 * hit (prima ancora che il banner JS abbia caricato) vedrebbero
		 * tutto a 'deny' anche se l'utente ha già accettato in una sessione
		 * precedente.
		 *
		 * Eseguito su 'init' priorità 5 così è disponibile prima del
		 * normale priority 10 dove la maggior parte dei plugin hooka.
		 */
		public static function hydrate_consent_from_cookie() {
			if ( is_admin() ) {
				return;
			}
			if ( ! function_exists( 'wp_set_consent' ) ) {
				return;
			}
			$decoded = self::decode_cookie();
			if ( null === $decoded ) {
				return;
			}

			// Versione del consenso (3.5.0): il cookie pre-bump non copre i
			// trattamenti correnti. Non basta ignorarlo: la WP Consent API
			// ha i SUOI cookie (wp_consent_*) che sopravvivrebbero al bump
			// e terrebbero il consenso stantio attivo per gli altri plugin.
			// Reset esplicito: deny su tutte le opzionali finché l'utente
			// non fa una nuova scelta (mismatch = no-consent, rigoroso).
			if ( self::cookie_version_is_stale( $decoded ) ) {
				$deny = array();
				foreach ( DBCM_Settings::categories() as $category ) {
					$deny[ $category ] = ( 'functional' === $category );
				}
				self::propagate_consent( $deny );
				return;
			}

			self::propagate_consent( self::normalize_categories( $decoded ) );
		}
	}
}
