<?php
/**
 * DBCM_Privacy_Declarations — Dichiarazione dei trattamenti privacy del
 * Cookie Manager DB verso il registro privacy unificato.
 *
 * Pattern dell'ecosistema DB: ogni plugin DB dichiara i propri trattamenti
 * via il filter `dbph_processing_register`, raccolto dal plugin DB Privacy
 * Hub e mostrato nella sua pagina "Registro trattamenti".
 *
 * COMPATIBILITÀ: dalla 3.1.0 il filter di riferimento è cambiato da
 * `dbseo_processing_register` (SEO Manager 1.2.x) a `dbph_processing_register`
 * (DB Privacy Hub). Per non rompere installazioni con SEO Manager 1.2.x
 * ancora attivo, ci agganciamo a entrambi: l'Hub stesso ribalta i contenuti
 * del filter legacy quando è installato. Quando il SEO Manager passa a 1.3.0
 * il filter legacy non esiste più e l'unico canale è dbph_processing_register.
 *
 * Filosofia:
 *  - Ogni plugin conosce SOLO i propri trattamenti. Non sa cosa fanno gli
 *    altri.
 *  - Il Privacy Hub NON è obbligatorio: se non è installato, il filter
 *    semplicemente non viene mai chiamato — questa classe diventa silente
 *    senza errori.
 *  - Il Cookie Manager dichiara tre trattamenti corrispondenti ai suoi
 *    tre ruoli operativi:
 *      1. Raccolta del consenso (banner + AJAX endpoint)
 *      2. Registro consensi (consent log su DB con IP hashato)
 *      3. Scansione cookie (scanner che HEAD-fetcha le pagine del sito)
 *
 * Nota: il blocker preventivo NON è un trattamento dati separato —
 * neutralizza script di terzi prima che vengano eseguiti, non raccoglie
 * dati. Lo includiamo come parte del trattamento "Raccolta del consenso".
 *
 * @package DBCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DBCM_Privacy_Declarations' ) ) {

	class DBCM_Privacy_Declarations {

		/**
		 * Inizializzazione — chiamata da DBCM_Plugin->init_modules().
		 *
		 * Si aggancia al filter unificato del Privacy Hub e, per compatibilità
		 * retroattiva, anche al filter legacy del SEO Manager 1.2.x. Quando
		 * SEO Manager 1.3.0+ rimuove il filter legacy, solo il primo è attivo.
		 */
		public static function init() {
			add_filter( 'dbph_processing_register', array( __CLASS__, 'declare' ), 10, 1 );
			add_filter( 'dbseo_processing_register', array( __CLASS__, 'declare' ), 10, 1 );
		}

		/**
		 * Dichiara i trattamenti del Cookie Manager.
		 *
		 * Ogni voce segue il contratto del filter `dbph_processing_register`
		 * (vedi DBPH_Register::collect() nel DB Privacy Hub). Lo stesso
		 * contratto era usato dal vecchio filter `dbseo_processing_register`.
		 *
		 * @param array $register Registro corrente.
		 * @return array
		 */
		public static function declare( $register ) {
			if ( ! is_array( $register ) ) {
				$register = array();
			}

			$retention_days = (int) DBCM_Settings::get( 'consent_log_retention', 365 );
			$log_enabled    = (bool) DBCM_Settings::get( 'consent_log_enabled', true );
			$ua_mode        = (string) DBCM_Settings::get( 'consent_log_user_agent', 'aggregate' );

			/* ---- 1. Raccolta del consenso ---------------------------------- */
			$register[] = array(
				'id'             => 'dbcm_consent_collection',
				'label'          => __( 'Raccolta del consenso ai cookie (DB Cookie Manager)', 'db-cookie-manager' ),
				'status'         => 'active',
				'purpose'        => __( 'Mostrare il banner GDPR, registrare le scelte dell\'utente sulle categorie di cookie, propagare il consenso alla WP Consent API.', 'db-cookie-manager' ),
				'legal_basis'    => __( 'Adempimento di obblighi di legge (art. 6.1.c GDPR; provvedimento Garante "cookie e altri strumenti di tracciamento" 10 giugno 2021).', 'db-cookie-manager' ),
				'data_collected' => __( 'Cookie tecnico "dbcm_consent" sul browser dell\'utente: contiene un JSON con le 5 categorie WP Consent API (functional, preferences, statistics, statistics-anonymous, marketing), il timestamp e il tipo di scelta. Nessun identificatore personale.', 'db-cookie-manager' ),
				'retention'      => sprintf(
					/* translators: %d: durata cookie in giorni */
					__( 'Cookie del consenso valido %d giorni (configurabile). Allo scadere, il banner viene mostrato di nuovo.', 'db-cookie-manager' ),
					(int) DBCM_Settings::get( 'consent_duration', 365 )
				),
				'transfers'      => __( 'Nessuno. Il cookie è scritto e letto solo dal browser dell\'utente; non viene trasmesso a server esterni.', 'db-cookie-manager' ),
			);

			/* ---- 2. Registro consensi (consent log) ----------------------- */
			if ( $log_enabled ) {
				$ua_label = self::ua_mode_label( $ua_mode );

				$register[] = array(
					'id'             => 'dbcm_consent_log',
					'label'          => __( 'Registro consensi (DB Cookie Manager)', 'db-cookie-manager' ),
					'status'         => 'active',
					'purpose'        => __( 'Conservare evidenza dimostrativa del consenso prestato dall\'utente, come richiesto dall\'art. 7.1 GDPR ("il titolare del trattamento deve essere in grado di dimostrare che l\'interessato ha prestato il proprio consenso").', 'db-cookie-manager' ),
					'legal_basis'    => __( 'Adempimento di obblighi di legge (art. 7.1 GDPR — onere della prova).', 'db-cookie-manager' ),
					'data_collected' => sprintf(
						/* translators: %s: descrizione modalità user-agent */
						__( 'Per ogni evento di consenso: hash SHA-256 dell\'IP (con salt WP_AUTH_KEY, irreversibile), %s, mappa delle 5 categorie scelte, tipo di scelta (accept_all/reject_all/custom), timestamp.', 'db-cookie-manager' ),
						$ua_label
					),
					'retention'      => sprintf(
						/* translators: %d: giorni di retention */
						__( 'Pulizia automatica dopo %d giorni (configurabile; 0 = mai).', 'db-cookie-manager' ),
						$retention_days
					),
					'transfers'      => __( 'Nessuno. Salvato esclusivamente in tabella locale del database WordPress.', 'db-cookie-manager' ),
				);
			}

			/* ---- 3. Scanner cookie ---------------------------------------- */
			// Lo scanner gira solo on-demand dall'admin, ma il trattamento dei
			// cookie scoperti (memorizzati in tabella per il policy generator)
			// è permanente finché l'admin non li elimina.
			$register[] = array(
				'id'             => 'dbcm_cookie_scanner',
				'label'          => __( 'Scansione cookie del sito (DB Cookie Manager)', 'db-cookie-manager' ),
				'status'         => 'active',
				'purpose'        => __( 'Identificare empiricamente i cookie scritti dal sito e dai servizi terzi inclusi, per categorizzarli e generare la Cookie Policy.', 'db-cookie-manager' ),
				'legal_basis'    => __( 'Legittimo interesse del titolare (compliance tecnica e generazione della cookie policy).', 'db-cookie-manager' ),
				'data_collected' => __( 'Solo metadata dei cookie del sito stesso: nome, dominio, durata, secure/httponly/samesite, provider, descrizione. Nessun dato personale degli utenti finali. Le richieste HTTP dello scanner provengono dal server WP, non dai visitatori.', 'db-cookie-manager' ),
				'retention'      => __( 'Memorizzati in tabella locale finché l\'admin non li elimina o esegue una nuova scansione.', 'db-cookie-manager' ),
				'transfers'      => __( 'Nessuno. Lo scanner contatta solo URL pubblici dello stesso sito.', 'db-cookie-manager' ),
			);

			/* ---- 4. Meta Pixel nativo (solo se attivo) --------------------- */
			if ( class_exists( 'DBCM_Meta_Pixel' ) && DBCM_Meta_Pixel::is_active() ) {
				$register[] = array(
					'id'             => 'dbcm_meta_pixel',
					'label'          => __( 'Marketing via Meta Pixel (DB Cookie Manager)', 'db-cookie-manager' ),
					'status'         => 'active',
					'purpose'        => __( 'Misurazione delle performance delle sponsorizzazioni e remarketing su Facebook/Instagram. Il pixel viene caricato esclusivamente dopo il consenso alla categoria Marketing.', 'db-cookie-manager' ),
					'legal_basis'    => __( 'Consenso dell\'interessato (art. 6.1.a GDPR), revocabile in ogni momento con pari facilità (art. 7.3).', 'db-cookie-manager' ),
					'data_collected' => __( 'ID browser (cookie _fbp), click ID dell\'annuncio (cookie _fbc, se presente fbclid), evento PageView, indirizzo IP, user agent.', 'db-cookie-manager' ),
					'retention'      => __( 'Cookie lato client: fino a 3 mesi. Retention lato Meta definita dalle policy di Meta Platforms.', 'db-cookie-manager' ),
					'transfers'      => __( 'Meta Platforms Ireland Ltd. in contitolarità (art. 26 GDPR, sent. CGUE C-40/17 Fashion ID); trasferimento verso Meta Platforms Inc. (USA) sulla base del Data Privacy Framework.', 'db-cookie-manager' ),
				);
			}

			return $register;
		}

		/**
		 * Restituisce una descrizione user-friendly della modalità di
		 * registrazione user-agent (none/aggregate/full).
		 *
		 * Utile per la voce "Dati raccolti" del consent log: l'utente admin
		 * deve sapere ESATTAMENTE cosa stiamo loggando.
		 *
		 * @param string $mode
		 * @return string
		 */
		private static function ua_mode_label( $mode ) {
			switch ( $mode ) {
				case 'none':
					return __( 'nessun user-agent (campo vuoto)', 'db-cookie-manager' );
				case 'full':
					return __( 'user-agent completo dell\'utente', 'db-cookie-manager' );
				case 'aggregate':
				default:
					return __( 'famiglia browser aggregata (es. "Chrome", "Firefox", "Mobile") senza versione né dettagli', 'db-cookie-manager' );
			}
		}
	}
}
