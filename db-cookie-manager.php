<?php
/**
 * Plugin Name: DB Cookie Manager
 * Plugin URI:  https://www.davidebertolino.it/progetti/db-cookie-manager
 * Description: Gestione completa dei cookie per WordPress: scanner automatico, banner GDPR multilingua con blocco preventivo, integrazione WP Consent API, generatore Cookie Policy e registro consensi.
 * Version:     3.8.3
 * Author:      Davide Bertolino
 * Author URI:  https://www.davidebertolino.it
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: db-cookie-manager
 * Requires at least: 5.9
 * Requires PHP: 7.4
 *
 * @package DBCM
 */

/**
 * Privacy capabilities (per references/PRIVACY-INTEGRATION.md):
 *  - Personal data:        YES — registro consensi wp_dbcm_consent_log (hash
 *                          SHA-256 salato dell'IP, UA aggregato, scelta, data)
 *  - Third-party scripts:  YES — blocco preventivo di tracker/iframe noti,
 *                          riattivati solo lato client dopo il consenso;
 *                          Meta Pixel nativo opt-in (gated by-design)
 *  - User consent:         YES — banner cookie (consent manager della WP
 *                          Consent API), prova art. 7.1 nel registro consensi
 *  - DSAR-aware:           NO  — il registro contiene solo l'hash dell'IP, non
 *                          collegabile a un'email o a un account: una
 *                          richiesta DSAR non può individuare le righe
 *                          dell'interessato. DBCM_DSAR_AVAILABLE NON definita.
 *  - Hub-aware:            YES — dbph_processing_register (+ legacy
 *                          dbseo_processing_register), dbph_consents_register,
 *                          dbph_policy_destinatari, dbcm_declared_services_register
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* =============================================================================
 * Costanti
 * ========================================================================== */

define( 'DBCM_VERSION', '3.8.3' );
define( 'DBCM_FILE', __FILE__ );
define( 'DBCM_DIR', plugin_dir_path( __FILE__ ) );
define( 'DBCM_URL', plugin_dir_url( __FILE__ ) );
define( 'DBCM_BASENAME', plugin_basename( __FILE__ ) );
define( 'DBCM_SLUG', 'db-cookie-manager' );

/* =============================================================================
 * Bootstrap singleton
 *
 * La classe DBCM_Plugin è il punto d'ingresso unico. Tutti i moduli vengono
 * caricati qui in un ordine deterministico così che le dipendenze fra classi
 * siano risolte prima del primo hook 'init'.
 *
 * Il bootstrap NON istanzia direttamente i moduli funzionali (Banner, Blocker,
 * Admin, ecc.) — quelli verranno aggiunti nei prossimi step. Per ora carica
 * solo:
 *   - inc/class-updater.php → GitHub Auto-Updater (skill condivisa)
 *
 * Quando arriveranno i moduli, basterà aggiungere il loro require_once nel
 * metodo load_dependencies() e l'init nel metodo init_modules().
 * ========================================================================== */

if ( ! class_exists( 'DBCM_Plugin' ) ) {

	final class DBCM_Plugin {

		/**
		 * @var DBCM_Plugin|null
		 */
		private static $instance = null;

		/**
		 * Restituisce l'istanza singleton.
		 *
		 * @return DBCM_Plugin
		 */
		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Costruttore privato — ingresso solo via instance().
		 */
		private function __construct() {
			$this->load_dependencies();
			$this->register_hooks();
		}

		/**
		 * Carica tutti i file delle classi del plugin.
		 *
		 * Ordine: il GitHub Updater è autonomo. I moduli funzionali verranno
		 * aggiunti nei prossimi step in ordine di dipendenza.
		 */
		private function load_dependencies() {
			require_once DBCM_DIR . 'inc/class-updater.php';

			// Step 2 — Settings + WP Consent API + Banner.
			require_once DBCM_DIR . 'inc/class-settings.php';
			require_once DBCM_DIR . 'inc/class-consent-api.php';
			require_once DBCM_DIR . 'inc/class-banner.php';

			// v3.3.0 — Google Consent Mode v2 (opt-in). Inietta lo snippet
			// default 'denied' nel <head> e il mapping categoria→segnale.
			require_once DBCM_DIR . 'inc/class-consent-signals.php';

			// v3.7.0 — Meta Pixel nativo (gated by-design, opt-in).
			require_once DBCM_DIR . 'inc/class-meta-pixel.php';

			// v3.3.0 — Database firme locale (sorgente unica per blocker e scanner).
			require_once DBCM_DIR . 'inc/data/signatures.php';
			require_once DBCM_DIR . 'inc/class-signatures.php';

			// 3.6.0 — Registro servizi dichiarati (previo consenso): popolato
			// dal blocker al momento del match, consumato dal generatore
			// policy e da DB Privacy Hub via filtro.
			require_once DBCM_DIR . 'inc/class-declared-services.php';

			// Step 3 — Blocker preventivo.
			require_once DBCM_DIR . 'inc/class-blocker.php';

			// Step 4 — Consent log.
			require_once DBCM_DIR . 'inc/class-consent-log.php';

			// Step 5 — Cookie database statico, scanner, policy generator.
			require_once DBCM_DIR . 'inc/class-cookie-database.php';
			require_once DBCM_DIR . 'inc/class-scanner.php';
			require_once DBCM_DIR . 'inc/class-policy-generator.php';

			// Admin: guscio comune (menu, dispatcher, render helper) + una
			// classe per pagina (split 3.5.1 — refactor meccanico).
			require_once DBCM_DIR . 'inc/class-admin.php';
			require_once DBCM_DIR . 'inc/class-admin-page-dashboard.php';
			require_once DBCM_DIR . 'inc/class-admin-page-banner.php';
			require_once DBCM_DIR . 'inc/class-admin-page-scanner.php';
			require_once DBCM_DIR . 'inc/class-admin-page-signatures.php';
			require_once DBCM_DIR . 'inc/class-admin-page-policy.php';
			require_once DBCM_DIR . 'inc/class-admin-page-log.php';
			require_once DBCM_DIR . 'inc/class-admin-page-advanced.php';
			require_once DBCM_DIR . 'inc/class-admin-page-declared.php';

			// Step 7 — Shortcode [dbcm_preferences].
			require_once DBCM_DIR . 'inc/class-shortcode.php';

			// Privacy declarations: dichiara i trattamenti al registro privacy
			// unificato di DB Privacy Hub (dbph_processing_register, + filter
			// legacy dbseo_processing_register del SEO Manager 1.2.x) e i
			// destinatari (dbph_policy_destinatari). Inerte senza Hub/SEO.
			require_once DBCM_DIR . 'inc/class-privacy-declarations.php';
		}

		/**
		 * Registra gli hook di base del plugin.
		 */
		private function register_hooks() {
			// Inizializzazione dei moduli — chiamata su 'plugins_loaded' priorità 5
			// così altri plugin possono hookare in 10 senza problemi di ordine.
			add_action( 'plugins_loaded', array( $this, 'init_modules' ), 5 );

			// Translations (per future estensioni i18n; oggi il banner è multilingua interno).
			add_action( 'init', array( $this, 'load_textdomain' ) );

			// 3.8.0 — Cron di retention: fino alla 3.7.1 era schedulato solo
			// all'attivazione, quindi mancava su aggiornamenti via file,
			// attivazioni di rete multisite e dopo un reset di WP-Cron → la
			// cancellazione automatica dei consensi (art. 5.1.e) non girava.
			add_action( 'init', array( __CLASS__, 'ensure_cron' ) );

			// 3.8.0 — Multisite: nuovi siti creati con il plugin attivo in
			// rete ricevono subito tabelle e cron.
			add_action( 'wp_initialize_site', array( __CLASS__, 'on_initialize_site' ), 20 );

			// Activation / deactivation.
			register_activation_hook( DBCM_FILE, array( __CLASS__, 'on_activation' ) );
			register_deactivation_hook( DBCM_FILE, array( __CLASS__, 'on_deactivation' ) );
		}

		/**
		 * Inizializza il GitHub Auto-Updater e (in futuro) gli altri moduli.
		 */
		public function init_modules() {
			// GitHub Auto-Updater — standard per tutti i plugin DB.
			if ( class_exists( 'DB_GitHub_Updater' ) ) {
				new DB_GitHub_Updater( DBCM_FILE, 'dadebertolino', 'db-cookie-manager' );
			}

			// Settings (data layer): nessun hook, solo init.
			DBCM_Settings::init();

			// WP Consent API integration: registra il plugin come consent
			// manager, espone AJAX endpoint, hydrate cookie su page load.
			DBCM_Consent_API::init();

			// Banner frontend: enqueue assets, render markup.
			DBCM_Banner::init();

			// GCM v2: inerte se gcm_enabled = false (default).
			DBCM_Consent_Signals::init();

			// Meta Pixel nativo: inerte se meta_pixel_enabled = false
			// (default) o senza Pixel ID valido. Quando attivo emette il
			// gate JS in wp_head e registra il servizio nel registro
			// dichiarati (art. 13).
			DBCM_Meta_Pixel::init();

			// v3.3.0 — Database firme: aggancia le viste blocker/scanner ai
			// filtri esistenti PRIMA che Blocker e Scanner girino. Additivo:
			// se fallisse, il core continua con i suoi pattern hard-coded.
			DBCM_Signatures::init();

			// Blocker preventivo: neutralizza script e iframe di tracking
			// finché l'utente non concede la categoria.
			DBCM_Blocker::init();

			// Consent log: si aggancia all'action 'dbcm_consent_set' e
			// registra ogni cambio di consenso (IP hashato, UA aggregato).
			DBCM_Consent_Log::init();

			// Scanner: AJAX endpoint per la scansione cookie del sito + schema
			// upgrade just-in-time. Cookie database e policy generator sono
			// classi statiche pure: non hanno init().
			DBCM_Scanner::init();

			// Admin scaffold: menu di primo livello + sottopagine + dispatcher
			// di salvataggio centralizzato. Le pagine Banner/Scanner/Policy/Log/
			// Avanzate sono stub nello step 6a — verranno popolate in 6b/6c/6d.
			DBCM_Admin::init();

			// Shortcode [dbcm_preferences] — pulsante "Modifica preferenze"
			// posizionabile ovunque nel content del sito.
			DBCM_Shortcode::init();

			// Privacy declarations — dichiara i trattamenti del Cookie
			// Manager al registro di DB Privacy Hub (dbph_processing_register;
			// legacy dbseo_processing_register per SEO Manager 1.2.x) e il
			// destinatario Meta quando il Meta Pixel è attivo
			// (dbph_policy_destinatari). Inerte se Hub/SEO non installati.
			DBCM_Privacy_Declarations::init();
		}

		/**
		 * Carica il text domain.
		 */
		public function load_textdomain() {
			load_plugin_textdomain(
				'db-cookie-manager',
				false,
				dirname( DBCM_BASENAME ) . '/languages'
			);
		}

		/* ---------------------------------------------------------------------
		 * Activation / Deactivation
		 * ------------------------------------------------------------------ */

		/**
		 * Eseguito una sola volta all'attivazione del plugin.
		 *
		 * Crea le tabelle DB e imposta le opzioni di default. La logica vera
		 * verrà aggiunta dai moduli (DBCM_Consent_Log::create_table(),
		 * DBCM_Scanner::create_table(), DBCM_Settings::set_defaults()) — qui
		 * lasciamo lo schema dell'hook in modo che il flusso di attivazione
		 * sia chiaro fin da subito.
		 */
		public static function on_activation() {
			// Marker che indica che questa è una nuova installazione 3.0.0
			// (utile per future migrazioni e per uninstall.php).
			if ( false === get_option( 'dbcm_version' ) ) {
				add_option( 'dbcm_version', DBCM_VERSION );
			} else {
				update_option( 'dbcm_version', DBCM_VERSION );
			}

			// Schedule cron giornaliero per cleanup consent log.
			if ( ! wp_next_scheduled( 'dbcm_daily_cleanup' ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'dbcm_daily_cleanup' );
			}

			// Crea / aggiorna le tabelle DB dei moduli che ne hanno bisogno.
			if ( class_exists( 'DBCM_Consent_Log' ) ) {
				DBCM_Consent_Log::create_table();
			}
			if ( class_exists( 'DBCM_Scanner' ) ) {
				DBCM_Scanner::create_table();
			}
		}

		/**
		 * Garantisce che il cron giornaliero di pulizia sia schedulato.
		 * Costo: una lettura dell'array cron (già in cache) per richiesta.
		 *
		 * @since 3.8.0
		 * @return void
		 */
		public static function ensure_cron() {
			if ( ! wp_next_scheduled( 'dbcm_daily_cleanup' ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'dbcm_daily_cleanup' );
			}
		}

		/**
		 * Multisite: prepara un nuovo sito se il plugin è attivo in rete.
		 *
		 * @since 3.8.0
		 * @param WP_Site $site Nuovo sito.
		 * @return void
		 */
		public static function on_initialize_site( $site ) {
			if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			if ( ! is_plugin_active_for_network( DBCM_BASENAME ) ) {
				return;
			}
			switch_to_blog( (int) $site->blog_id );
			self::ensure_cron();
			if ( class_exists( 'DBCM_Consent_Log' ) ) {
				DBCM_Consent_Log::create_table();
			}
			if ( class_exists( 'DBCM_Scanner' ) ) {
				DBCM_Scanner::create_table();
			}
			restore_current_blog();
		}

		/**
		 * Eseguito alla disattivazione del plugin.
		 *
		 * NB: la disattivazione NON cancella dati. La cancellazione completa
		 * avviene in uninstall.php solo se l'utente disinstalla il plugin.
		 */
		public static function on_deactivation() {
			// Rimuovi gli eventi cron schedulati.
			$timestamp = wp_next_scheduled( 'dbcm_daily_cleanup' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'dbcm_daily_cleanup' );
			}
		}

		/* ---------------------------------------------------------------------
		 * Anti-clone / anti-unserialize (singleton hardening)
		 * ------------------------------------------------------------------ */

		public function __clone() {
			_doing_it_wrong( __FUNCTION__, esc_html__( 'Cloning DBCM_Plugin is not allowed.', 'db-cookie-manager' ), '3.0.0' );
		}

		public function __wakeup() {
			_doing_it_wrong( __FUNCTION__, esc_html__( 'Unserializing DBCM_Plugin is not allowed.', 'db-cookie-manager' ), '3.0.0' );
		}
	}
}

/* =============================================================================
 * Avvio del plugin
 * ========================================================================== */

DBCM_Plugin::instance();
