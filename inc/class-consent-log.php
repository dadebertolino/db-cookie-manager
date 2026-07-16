<?php
/**
 * DBCM_Consent_Log — Registro consensi (GDPR art. 7(1)).
 *
 * Strategia:
 *  - Si aggancia all'action 'dbcm_consent_set' emessa da DBCM_Consent_API
 *    quando l'utente cambia consenso. Non c'è un AJAX dedicato: il payload
 *    è già stato sanificato dal layer Consent API.
 *  - IP anonimizzato via SHA256 + wp_salt('auth') (irreversibile in pratica
 *    per IPv4/IPv6 individualmente — l'hash di un IP noto resta uguale, quindi
 *    permette correlazioni "stesso visitatore" senza rivelare l'IP originale).
 *  - User-agent: tre modalità configurabili (none | aggregate | full).
 *    Default 3.0 = 'aggregate': salviamo solo il browser principale
 *    (Chrome/Firefox/Safari/Edge/Mobile/Altro). 2.0.1 salvava UA completo:
 *    troppo dato per la giustificazione "prova del consenso art. 7".
 *  - Export CSV e JSON.
 *  - Cleanup giornaliero via cron 'dbcm_daily_cleanup' (registrato dal
 *    bootstrap plugin in attivazione, non da qui).
 *
 * Niente UI rendering qui — arriverà nello step 6 admin UI.
 *
 * @package DBCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DBCM_Consent_Log' ) ) {

	class DBCM_Consent_Log {

		/**
		 * Versione dello schema della tabella. Incrementare se cambiano le
		 * colonne così che maybe_upgrade_schema() possa intervenire.
		 */
		const SCHEMA_VERSION = 3;

		/**
		 * Nome dell'option che traccia la versione dello schema installata.
		 */
		const SCHEMA_OPTION = 'dbcm_consent_log_schema';

		/**
		 * Restituisce il nome completo (con prefix) della tabella.
		 *
		 * @return string
		 */
		public static function table_name() {
			global $wpdb;
			return $wpdb->prefix . 'dbcm_consent_log';
		}

		/**
		 * Crea o aggiorna la tabella tramite dbDelta.
		 *
		 * Schema:
		 *   id            BIGINT auto-increment
		 *   ip_hash       VARCHAR(64) — SHA256 + wp_salt('auth')
		 *   consent_data  VARCHAR(500) — JSON {functional,preferences,statistics,statistics-anonymous,marketing,v}
		 *   consent_type  VARCHAR(20) — accept_all | reject_all | custom
		 *   ua_summary    VARCHAR(64) — etichetta browser aggregata, oppure UA full troncato a 64
		 *   policy_version BIGINT — ID snapshot Privacy Hub (3.2.0+, 0 se Hub assente)
		 *   consent_version INT — versione del consenso DBCM (3.5.0+, 0 = riga pre-3.5)
		 *   consent_date  TIMESTAMP — DEFAULT CURRENT_TIMESTAMP
		 *
		 * Schema 2 (3.2.0): aggiunta colonna `policy_version` per linkare il
		 * consenso alla versione esatta della Privacy Policy in vigore al
		 * momento. dbDelta è additivo: aggiunge la colonna alle installazioni
		 * 3.1.x preservando i dati. Le righe pre-esistenti hanno policy_version=0.
		 *
		 * Schema 3 (3.5.0): aggiunta colonna `consent_version` — la versione
		 * della configurazione dei trattamenti sotto cui la scelta è stata
		 * espressa (valore probatorio, Art. 7(1): dimostrare il consenso
		 * significa dimostrare A COSA). Complementare a policy_version:
		 * quella è la versione del DOCUMENTO informativa (Privacy Hub),
		 * questa la versione della CONFIGURAZIONE DBCM. Righe pre-esistenti
		 * a 0 (= versione non tracciata).
		 *
		 * @return void
		 */
		public static function create_table() {
			global $wpdb;
			$table   = self::table_name();
			$charset = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE {$table} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				ip_hash VARCHAR(64) NOT NULL DEFAULT '',
				consent_data VARCHAR(500) NOT NULL DEFAULT '',
				consent_type VARCHAR(20) NOT NULL DEFAULT 'custom',
				ua_summary VARCHAR(64) NOT NULL DEFAULT '',
				policy_version BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
				consent_version INT(10) UNSIGNED NOT NULL DEFAULT 0,
				consent_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				KEY consent_date (consent_date),
				KEY consent_type (consent_type),
				KEY ip_hash (ip_hash),
				KEY policy_version (policy_version),
				KEY consent_version (consent_version)
			) {$charset};";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );

			update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );
		}

		/**
		 * Verifica che la tabella esista e che lo schema sia aggiornato.
		 * Chiamata difensivamente in init() perché register_activation_hook
		 * può non scattare in alcuni hosting (es. installazioni mu-plugins).
		 *
		 * @return void
		 */
		public static function maybe_upgrade_schema() {
			global $wpdb;
			$table = self::table_name();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) === $table;
			$installed = (int) get_option( self::SCHEMA_OPTION, 0 );

			if ( ! $exists || $installed < self::SCHEMA_VERSION ) {
				self::create_table();
			}
		}

		/* =====================================================================
		 * INIT
		 * ================================================================== */

		public static function init() {
			// Aggancio all'evento di consenso emesso da DBCM_Consent_API.
			add_action( 'dbcm_consent_set', array( __CLASS__, 'on_consent_set' ), 10, 2 );

			// Cron handler per il cleanup giornaliero.
			add_action( 'dbcm_daily_cleanup', array( __CLASS__, 'cleanup' ) );

			// Export CSV/JSON: gestito su admin_init prima dell'output.
			add_action( 'admin_init', array( __CLASS__, 'handle_export' ) );

			// Schema check (just-in-time, una volta per request).
			add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade_schema' ) );

			// 3.2.0: dichiara la fonte consensi al Privacy Hub via filter pubblico.
			// Inerte se l'Hub non è installato (il filter non viene applicato).
			add_filter( 'dbph_consents_register', array( __CLASS__, 'declare_consents_source' ) );
		}

		/* =====================================================================
		 * INTEGRAZIONE PRIVACY HUB (3.2.0)
		 * ================================================================== */

		/**
		 * Dichiara la fonte "consensi cookie" al filter `dbph_consents_register`.
		 *
		 * @param array $sources
		 * @return array
		 */
		public static function declare_consents_source( $sources ) {
			$sources['dbcm_cookie_consents'] = array(
				'label'  => __( 'Cookie Manager — Consensi cookie', 'db-cookie-manager' ),
				'icon'   => 'admin-network',
				'count'  => array( __CLASS__, 'hub_count' ),
				'query'  => array( __CLASS__, 'hub_query' ),
				'export' => array( __CLASS__, 'hub_query' ), // stesso payload, l'Hub lo serializza
			);
			return $sources;
		}

		/**
		 * Conta consensi che corrispondono ai filtri (per la card riepilogativa).
		 *
		 * @param array $args date_from, date_to, subject
		 * @return int
		 */
		public static function hub_count( $args = array() ) {
			global $wpdb;
			$table = self::table_name();
			list( $where_sql, $where_params ) = self::build_hub_where( $args );

			if ( ! empty( $where_params ) ) {
				$sql = "SELECT COUNT(*) FROM {$table} {$where_sql}";
				return (int) $wpdb->get_var( $wpdb->prepare( $sql, $where_params ) );
			}
			return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} {$where_sql}" );
		}

		/**
		 * Restituisce le righe normalizzate per il Registro consensi del Hub.
		 *
		 * @param array $args date_from, date_to, subject
		 * @return array<array>
		 */
		public static function hub_query( $args = array() ) {
			global $wpdb;
			$table = self::table_name();
			list( $where_sql, $where_params ) = self::build_hub_where( $args );

			$limit = isset( $args['_internal_limit'] ) ? (int) $args['_internal_limit'] : 1000;
			$limit = max( 1, min( 50000, $limit ) );

			$sql = "SELECT * FROM {$table} {$where_sql} ORDER BY consent_date DESC LIMIT {$limit}";
			$rows = ! empty( $where_params )
				? $wpdb->get_results( $wpdb->prepare( $sql, $where_params ) )
				: $wpdb->get_results( $sql );

			$out = array();
			foreach ( (array) $rows as $r ) {
				$out[] = array(
					'id'             => 'dbcm-' . (int) $r->id,
					'timestamp'      => $r->consent_date,
					'subject'        => 'IP-hash:' . substr( $r->ip_hash, 0, 12 ) . '…',
					'consent_type'   => 'cookie:' . $r->consent_type,
					'consent_text'   => self::format_consent_data_for_display( $r->consent_data, $r->consent_type ),
					'policy_version' => (int) $r->policy_version,
					'extra'          => array(
						'ip_hash_full'    => $r->ip_hash,
						'ua_summary'      => $r->ua_summary,
						'consent_data'    => $r->consent_data,
						'consent_version' => isset( $r->consent_version ) ? (int) $r->consent_version : 0,
					),
				);
			}
			return $out;
		}

		/**
		 * Costruisce la WHERE clause per le query Hub.
		 *
		 * @param array $args
		 * @return array{0:string,1:array} [WHERE_SQL, params]
		 */
		private static function build_hub_where( $args ) {
			$where  = array();
			$params = array();

			if ( ! empty( $args['date_from'] ) ) {
				$where[] = 'consent_date >= %s';
				$params[] = $args['date_from'] . ' 00:00:00';
			}
			if ( ! empty( $args['date_to'] ) ) {
				$where[] = 'consent_date <= %s';
				$params[] = $args['date_to'] . ' 23:59:59';
			}
			if ( ! empty( $args['subject'] ) ) {
				// Il "subject" lato cookie è IP-hash:abc…; permettiamo match parziale sul prefisso hash.
				$where[] = 'ip_hash LIKE %s';
				$params[] = '%' . $args['subject'] . '%';
			}

			$sql = empty( $where ) ? '' : ( 'WHERE ' . implode( ' AND ', $where ) );
			return array( $sql, $params );
		}

		/**
		 * Formatta consent_data JSON in stringa leggibile per il Registro consensi.
		 */
		private static function format_consent_data_for_display( $json, $type ) {
			if ( $type === 'accept_all' )  return __( 'Tutte le categorie cookie accettate', 'db-cookie-manager' );
			if ( $type === 'reject_all' )  return __( 'Tutte le categorie cookie rifiutate (solo necessari)', 'db-cookie-manager' );

			$data = json_decode( (string) $json, true );
			if ( ! is_array( $data ) ) return __( 'Configurazione personalizzata', 'db-cookie-manager' );

			$accepted = array();
			foreach ( $data as $cat => $val ) {
				if ( $cat === 'v' ) continue;
				if ( $val ) $accepted[] = $cat;
			}
			return empty( $accepted )
				? __( 'Nessuna categoria opzionale accettata', 'db-cookie-manager' )
				: sprintf(
					/* translators: %s: lista categorie accettate */
					__( 'Categorie accettate: %s', 'db-cookie-manager' ),
					implode( ', ', $accepted )
				);
		}

		/* =====================================================================
		 * INSERIMENTO
		 * ================================================================== */

		/**
		 * Callback per l'action 'dbcm_consent_set'.
		 *
		 * @param string $type    'accept_all' | 'reject_all' | 'custom'.
		 * @param array  $consent Mappa categoria → bool (già validata da
		 *                        DBCM_Consent_API::sanitize_consent_payload).
		 * @return void
		 */
		public static function on_consent_set( $type, $consent ) {
			if ( ! DBCM_Settings::get( 'consent_log_enabled', true ) ) {
				return;
			}
			self::insert( $type, $consent );
		}

		/**
		 * Inserisce una riga nel log.
		 *
		 * @param string $type
		 * @param array  $consent
		 * @return int|false ID inserito, o false su fallimento.
		 */
		public static function insert( $type, $consent ) {
			global $wpdb;
			$table = self::table_name();

			// Costruisce il payload JSON con le 5 chiavi standard + meta.
			// Aggiungiamo lo schema version per future migrazioni e la
			// versione del consenso per righe auto-contenute.
			$payload = array(
				'v'  => DBCM_Settings::COOKIE_SCHEMA_VERSION,
				'cv' => DBCM_Settings::consent_version(),
			);
			foreach ( DBCM_Settings::categories() as $cat ) {
				$payload[ $cat ] = ! empty( $consent[ $cat ] );
			}

			$consent_data = wp_json_encode( $payload );
			if ( false === $consent_data || strlen( $consent_data ) > 500 ) {
				// Edge case: payload sopra il limite VARCHAR(500). Tronca
				// segnando esplicitamente che il dato è incompleto.
				$consent_data = substr( (string) $consent_data, 0, 497 ) . '...';
			}

			// 3.2.0: linka il consenso alla versione Privacy Policy in vigore.
			// Se il Privacy Hub non è installato, restiamo a 0 senza errori.
			$policy_version = 0;
			if ( class_exists( 'DBPH_Policy_Archive' ) && method_exists( 'DBPH_Policy_Archive', 'get_current_version_id' ) ) {
				$policy_version = (int) DBPH_Policy_Archive::get_current_version_id();
			}

			$row = array(
				'ip_hash'         => self::hash_ip( self::get_client_ip() ),
				'consent_data'    => $consent_data,
				'consent_type'    => self::sanitize_type( $type ),
				'ua_summary'      => self::summarize_user_agent(),
				'policy_version'  => $policy_version,
				// 3.5.0: versione del consenso letta dal SETTING lato server,
				// non dal payload client → non falsificabile (Art. 7(1)).
				'consent_version' => DBCM_Settings::consent_version(),
				// consent_date: lasciato a DEFAULT CURRENT_TIMESTAMP.
			);

			$result = $wpdb->insert(
				$table,
				$row,
				array( '%s', '%s', '%s', '%s', '%d', '%d' )
			);

			return ( false !== $result ) ? (int) $wpdb->insert_id : false;
		}

		/**
		 * Restringe il consent_type ai valori validi.
		 *
		 * @param string $type
		 * @return string
		 */
		private static function sanitize_type( $type ) {
			$type = sanitize_key( $type );
			return in_array( $type, array( 'accept_all', 'reject_all', 'custom' ), true )
				? $type
				: 'custom';
		}

		/* =====================================================================
		 * IP HASHING
		 * ================================================================== */

		/**
		 * Recupera l'IP client tenendo conto di proxy/CDN.
		 *
		 * Ordine di priorità: REMOTE_ADDR (più affidabile), poi gli header
		 * di forwarding. Se più di un IP è presente in X-Forwarded-For,
		 * prendiamo il primo (l'origine reale del client).
		 *
		 * Il valore restituito non viene mai salvato in chiaro: passa sempre
		 * per hash_ip() prima dell'INSERT.
		 *
		 * @return string
		 */
		private static function get_client_ip() {
			// REMOTE_ADDR è quello a cui il server risponde direttamente.
			if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
				$ip = self::sanitize_ip( $_SERVER['REMOTE_ADDR'] );
				if ( $ip ) {
					return $ip;
				}
			}

			// Fallback proxy/CDN headers — solo se l'admin ha esplicitamente
			// dichiarato di stare dietro a un proxy fidato (filtro).
			if ( apply_filters( 'dbcm_trust_proxy_headers', false ) ) {
				$candidates = array( 'HTTP_X_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP' );
				foreach ( $candidates as $header ) {
					if ( ! empty( $_SERVER[ $header ] ) ) {
						$first = trim( explode( ',', (string) $_SERVER[ $header ] )[0] );
						$ip    = self::sanitize_ip( $first );
						if ( $ip ) {
							return $ip;
						}
					}
				}
			}

			return '';
		}

		/**
		 * Valida un IP (v4 o v6) e lo restituisce normalizzato, o ''.
		 *
		 * @param string $ip
		 * @return string
		 */
		private static function sanitize_ip( $ip ) {
			$ip = trim( (string) $ip );
			return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
		}

		/**
		 * Hash IP con salt site-specifico (irreversibile in pratica).
		 *
		 * @param string $ip
		 * @return string
		 */
		private static function hash_ip( $ip ) {
			if ( '' === $ip ) {
				return '';
			}
			return hash( 'sha256', $ip . wp_salt( 'auth' ) );
		}

		/* =====================================================================
		 * USER AGENT
		 * ================================================================== */

		/**
		 * Restituisce la rappresentazione dell'UA da salvare, secondo la
		 * modalità configurata in settings.
		 *
		 *   'none'      → ''
		 *   'aggregate' → 'Chrome' | 'Firefox' | ... | 'Altro' (default 3.0)
		 *   'full'      → primi 64 char dello User-Agent grezzo
		 *
		 * @return string
		 */
		private static function summarize_user_agent() {
			$mode = DBCM_Settings::get( 'consent_log_user_agent', 'aggregate' );
			if ( 'none' === $mode ) {
				return '';
			}

			$raw = isset( $_SERVER['HTTP_USER_AGENT'] )
				? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] )
				: '';
			if ( '' === $raw ) {
				return '';
			}

			if ( 'full' === $mode ) {
				return mb_substr( sanitize_text_field( $raw ), 0, 64 );
			}

			// aggregate (default).
			return self::aggregate_ua( $raw );
		}

		/**
		 * Riconoscimento browser a granularità minima sufficiente per
		 * statistiche aggregate. L'ordine dei check conta (Edge contiene
		 * "Chrome" nello UA, quindi va testato prima).
		 *
		 * @param string $ua
		 * @return string
		 */
		public static function aggregate_ua( $ua ) {
			if ( '' === $ua ) {
				return 'Altro';
			}
			if ( false !== stripos( $ua, 'Edg' ) ) {
				return 'Edge';
			}
			if ( false !== stripos( $ua, 'OPR' ) || false !== stripos( $ua, 'Opera' ) ) {
				return 'Opera';
			}
			if ( false !== stripos( $ua, 'Firefox' ) ) {
				return 'Firefox';
			}
			if ( false !== stripos( $ua, 'Chrome' ) ) {
				// Dopo aver escluso Edge e Opera, "Chrome" è davvero Chrome.
				return 'Chrome';
			}
			if ( false !== stripos( $ua, 'Safari' ) ) {
				return 'Safari';
			}
			if ( false !== stripos( $ua, 'Trident' ) || false !== stripos( $ua, 'MSIE' ) ) {
				return 'IE';
			}
			if ( false !== stripos( $ua, 'Mobile' ) || false !== stripos( $ua, 'Android' ) ) {
				return 'Mobile';
			}
			if ( false !== stripos( $ua, 'bot' ) || false !== stripos( $ua, 'crawler' ) || false !== stripos( $ua, 'spider' ) ) {
				return 'Bot';
			}
			return 'Altro';
		}

		/* =====================================================================
		 * QUERY API
		 * ================================================================== */

		/**
		 * Conta le righe del log con filtri opzionali.
		 *
		 * @param array $args {
		 *     @type string $type       Filtro per consent_type ('' = tutti).
		 *     @type string $date_from  YYYY-MM-DD.
		 *     @type string $date_to    YYYY-MM-DD.
		 * }
		 * @return int
		 */
		public static function count( $args = array() ) {
			global $wpdb;
			$where = self::build_where( $args );
			$table = self::table_name();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = "SELECT COUNT(*) FROM {$table} {$where['sql']}";
			if ( ! empty( $where['params'] ) ) {
				$sql = $wpdb->prepare( $sql, $where['params'] );
			}
			return (int) $wpdb->get_var( $sql );
		}

		/**
		 * Restituisce un set paginato di righe.
		 *
		 * @param array $args {
		 *     @type int    $page       Pagina 1-based.
		 *     @type int    $per_page   Default 25.
		 *     @type string $type       Filtro per consent_type.
		 *     @type string $date_from
		 *     @type string $date_to
		 *     @type string $order      ASC | DESC. Default DESC.
		 * }
		 * @return array Lista di stdClass.
		 */
		public static function get_results( $args = array() ) {
			global $wpdb;
			$page     = max( 1, (int) ( $args['page'] ?? 1 ) );
			$per_page = max( 1, min( 1000, (int) ( $args['per_page'] ?? 25 ) ) );
			$offset   = ( $page - 1 ) * $per_page;
			$order    = ( strtoupper( $args['order'] ?? 'DESC' ) === 'ASC' ) ? 'ASC' : 'DESC';

			$where = self::build_where( $args );
			$table = self::table_name();

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = "SELECT id, ip_hash, consent_data, consent_type, ua_summary, policy_version, consent_version, consent_date
			        FROM {$table}
			        {$where['sql']}
			        ORDER BY consent_date {$order}, id {$order}
			        LIMIT %d OFFSET %d";

			$params   = $where['params'];
			$params[] = $per_page;
			$params[] = $offset;

			return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		}

		/**
		 * Costruisce la clausola WHERE comune a count/get_results.
		 *
		 * @param array $args
		 * @return array { sql: string, params: array }
		 */
		private static function build_where( $args ) {
			$conditions = array();
			$params     = array();

			if ( ! empty( $args['type'] ) ) {
				$type = self::sanitize_type( $args['type'] );
				$conditions[] = 'consent_type = %s';
				$params[]     = $type;
			}
			if ( ! empty( $args['date_from'] ) ) {
				$conditions[] = 'consent_date >= %s';
				$params[]     = sanitize_text_field( $args['date_from'] ) . ' 00:00:00';
			}
			if ( ! empty( $args['date_to'] ) ) {
				$conditions[] = 'consent_date <= %s';
				$params[]     = sanitize_text_field( $args['date_to'] ) . ' 23:59:59';
			}

			$sql = empty( $conditions ) ? '' : 'WHERE ' . implode( ' AND ', $conditions );
			return array( 'sql' => $sql, 'params' => $params );
		}

		/* =====================================================================
		 * CLEANUP
		 * ================================================================== */

		/**
		 * Cancella le righe più vecchie di consent_log_retention giorni.
		 * Chiamato dal cron 'dbcm_daily_cleanup'.
		 *
		 * @return int Righe cancellate.
		 */
		public static function cleanup() {
			global $wpdb;
			$days = (int) DBCM_Settings::get( 'consent_log_retention', 365 );
			if ( $days <= 0 ) {
				return 0; // 0 = nessuna scadenza.
			}
			$table = self::table_name();
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return (int) $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE consent_date < %s",
					$cutoff
				)
			);
		}

		/* =====================================================================
		 * EXPORT
		 * ================================================================== */

		/**
		 * Handler 'admin_init' per gli export. Reagisce a:
		 *   /wp-admin/admin.php?page=...&dbcm_export=csv
		 *   /wp-admin/admin.php?page=...&dbcm_export=json
		 *
		 * Verifica nonce + capability + emette il file con i giusti header.
		 * I filtri date_from/date_to/type vengono propagati dalla querystring.
		 */
		public static function handle_export() {
			if ( empty( $_GET['dbcm_export'] ) ) {
				return;
			}
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'dbcm_export_log' ) ) {
				return;
			}

			$format = sanitize_key( wp_unslash( $_GET['dbcm_export'] ) );
			if ( ! in_array( $format, array( 'csv', 'json' ), true ) ) {
				return;
			}

			$args = array(
				'page'     => 1,
				'per_page' => 1000,
				'type'     => isset( $_GET['type'] ) ? sanitize_text_field( wp_unslash( $_GET['type'] ) ) : '',
				'date_from' => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '',
				'date_to'   => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '',
				'order'    => 'DESC',
			);

			if ( 'csv' === $format ) {
				self::export_csv( $args );
			} else {
				self::export_json( $args );
			}
			// export_* terminano con exit.
		}

		/**
		 * Esporta in CSV. Streamming a chunks per gestire log grandi senza
		 * caricare tutto in memoria.
		 *
		 * @param array $args
		 * @return void
		 */
		private static function export_csv( $args ) {
			$filename = 'dbcm-consent-log-' . gmdate( 'Y-m-d-His' ) . '.csv';
			nocache_headers();
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

			$out = fopen( 'php://output', 'w' );
			// BOM UTF-8 per Excel.
			fwrite( $out, "\xEF\xBB\xBF" );
			fputcsv( $out, array( 'id', 'date', 'type', 'consent', 'ua_summary', 'ip_hash', 'policy_version', 'consent_version' ) );

			$page = 1;
			do {
				$args['page'] = $page;
				$rows = self::get_results( $args );
				foreach ( $rows as $row ) {
					fputcsv( $out, array(
						$row->id,
						$row->consent_date,
						$row->consent_type,
						$row->consent_data,
						$row->ua_summary,
						$row->ip_hash,
						isset( $row->policy_version ) ? (int) $row->policy_version : 0,
						isset( $row->consent_version ) ? (int) $row->consent_version : 0,
					) );
				}
				++$page;
			} while ( count( $rows ) === $args['per_page'] );

			fclose( $out );
			exit;
		}

		/**
		 * Esporta in JSON. Stesso pattern a chunks ma costruisce un array,
		 * dato che JSON non si può streammare riga-per-riga in modo standard
		 * senza usare format ad-hoc tipo NDJSON.
		 *
		 * @param array $args
		 * @return void
		 */
		private static function export_json( $args ) {
			$filename = 'dbcm-consent-log-' . gmdate( 'Y-m-d-His' ) . '.json';
			nocache_headers();
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

			$collected = array();
			$page = 1;
			do {
				$args['page'] = $page;
				$rows = self::get_results( $args );
				foreach ( $rows as $row ) {
					// consent_data è un JSON: lo decodifichiamo per
					// produrre un export "navigabile" invece di una
					// stringa annidata.
					$decoded = json_decode( (string) $row->consent_data, true );
					$collected[] = array(
						'id'              => (int) $row->id,
						'date'            => $row->consent_date,
						'type'            => $row->consent_type,
						'consent'         => is_array( $decoded ) ? $decoded : null,
						'ua_summary'      => $row->ua_summary,
						'ip_hash'         => $row->ip_hash,
						'policy_version'  => isset( $row->policy_version ) ? (int) $row->policy_version : 0,
						'consent_version' => isset( $row->consent_version ) ? (int) $row->consent_version : 0,
					);
				}
				++$page;
			} while ( count( $rows ) === $args['per_page'] );

			$envelope = array(
				'exported_at'    => gmdate( 'c' ),
				'plugin_version' => DBCM_VERSION,
				'schema'         => self::SCHEMA_VERSION,
				'count'          => count( $collected ),
				'records'        => $collected,
			);

			echo wp_json_encode( $envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
			exit;
		}

		/**
		 * URL pronto per un export, con nonce. Userà quello la pagina admin.
		 *
		 * @param string $format 'csv' | 'json'
		 * @param array  $args   Filtri opzionali (type, date_from, date_to)
		 * @return string
		 */
		public static function export_url( $format = 'csv', $args = array() ) {
			$base = add_query_arg(
				array_filter( array(
					'page'        => 'dbcm-consent-log',
					'dbcm_export' => $format,
					'type'        => $args['type'] ?? '',
					'date_from'   => $args['date_from'] ?? '',
					'date_to'     => $args['date_to'] ?? '',
				) ),
				admin_url( 'admin.php' )
			);
			return wp_nonce_url( $base, 'dbcm_export_log' );
		}
	}
}
