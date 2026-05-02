<?php
/**
 * DBCM_Scanner — Scansione automatica dei cookie del sito.
 *
 * Flusso a 3 fasi (asincrono via AJAX dall'admin UI):
 *   prepare()  → svuota tabella, calcola URL da scansionare, salva stato
 *   scan_url() → per ogni URL: HTTP GET + parse Set-Cookie + detect HTML
 *   finalize() → injection cookie WP core + Google Fonts detection + cleanup
 *
 * Cambi rispetto a 2.0.1:
 *  - Schema tabella allineato alle 5 categorie WP Consent API standard.
 *    Aggiunta colonna `notes` per testo libero usato dal policy generator.
 *  - Schema versionato in opzione `dbcm_scanner_schema` per migrazioni
 *    just-in-time (stesso pattern del consent log step 4).
 *  - HTML detection esteso ai servizi moderni del blocker step 3:
 *    Microsoft Clarity, Bing UET, TikTok, Pinterest, MS Clarity, ecc.
 *  - WooCommerce session detection se WC è attivo (cookie non visibile
 *    a uno scanner anonimo).
 *  - inject_core_cookies include `dbcm_consent` (il nostro!) come
 *    functional, così la cookie policy generata lo elenca correttamente.
 *  - User-Agent dello scanner ora dichiara esplicitamente il bot per
 *    permettere agli admin di riconoscere il traffico di self-scanning
 *    nei log server.
 *
 * @package DBCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DBCM_Scanner' ) ) {

	class DBCM_Scanner {

		const SCHEMA_VERSION = 1;
		const SCHEMA_OPTION  = 'dbcm_scanner_schema';

		/* =====================================================================
		 * INIT
		 * ================================================================== */

		public static function init() {
			// AJAX endpoint per la scansione (admin only).
			add_action( 'wp_ajax_dbcm_scan_prepare', array( __CLASS__, 'ajax_prepare' ) );
			add_action( 'wp_ajax_dbcm_scan_url', array( __CLASS__, 'ajax_scan_url' ) );
			add_action( 'wp_ajax_dbcm_scan_finalize', array( __CLASS__, 'ajax_finalize' ) );

			// AJAX endpoint per override manuale + delete (step 6c).
			add_action( 'wp_ajax_dbcm_cookie_override', array( __CLASS__, 'ajax_cookie_override' ) );
			add_action( 'wp_ajax_dbcm_cookie_delete', array( __CLASS__, 'ajax_cookie_delete' ) );

			// Just-in-time schema upgrade.
			add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade_schema' ) );
		}

		/* =====================================================================
		 * SCHEMA
		 * ================================================================== */

		public static function table_name() {
			global $wpdb;
			return $wpdb->prefix . 'dbcm_cookies';
		}

		public static function create_table() {
			global $wpdb;
			$table   = self::table_name();
			$charset = $wpdb->get_charset_collate();

			$sql = "CREATE TABLE {$table} (
				id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
				cookie_name VARCHAR(100) NOT NULL,
				cookie_domain VARCHAR(100) NOT NULL DEFAULT '',
				cookie_path VARCHAR(255) NOT NULL DEFAULT '/',
				cookie_duration VARCHAR(100) NOT NULL DEFAULT '',
				cookie_secure TINYINT(1) NOT NULL DEFAULT 0,
				cookie_httponly TINYINT(1) NOT NULL DEFAULT 0,
				cookie_samesite VARCHAR(20) NOT NULL DEFAULT '',
				category VARCHAR(50) NOT NULL DEFAULT 'marketing',
				description TEXT,
				provider VARCHAR(100) NOT NULL DEFAULT '',
				found_on VARCHAR(500) NOT NULL DEFAULT '',
				notes TEXT,
				scan_date TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (id),
				UNIQUE KEY cookie_unique (cookie_name, cookie_domain),
				KEY category (category)
			) {$charset};";

			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			dbDelta( $sql );

			update_option( self::SCHEMA_OPTION, self::SCHEMA_VERSION );
		}

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
		 * AJAX endpoints
		 * ================================================================== */

		public static function ajax_prepare() {
			check_ajax_referer( 'dbcm_scanner_nonce', 'nonce' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Unauthorized', 403 );
			}
			$urls = self::run_scan_prepare();
			wp_send_json_success(
				array(
					'urls'  => array_values( $urls ),
					'total' => count( $urls ),
				)
			);
		}

		public static function ajax_scan_url() {
			check_ajax_referer( 'dbcm_scanner_nonce', 'nonce' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Unauthorized', 403 );
			}
			$url = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
			if ( '' === $url ) {
				wp_send_json_error( 'No URL', 400 );
			}
			$count = self::run_scan_single( $url );
			wp_send_json_success(
				array(
					'cookies_found' => $count,
					'url'           => $url,
				)
			);
		}

		public static function ajax_finalize() {
			check_ajax_referer( 'dbcm_scanner_nonce', 'nonce' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Unauthorized', 403 );
			}
			$total = self::run_scan_finalize();
			wp_send_json_success(
				array(
					'total_cookies' => $total,
				)
			);
		}

		/**
		 * AJAX: sovrascrive manualmente la categoria di un singolo cookie.
		 * Usato dalla pagina admin Scanner per correggere classificazioni
		 * (es. cookie unmatched mappato a 'marketing' di default che in
		 * realtà è 'functional').
		 *
		 * Accetta POST: id (int), category (string fra le 5 standard).
		 *
		 * @return void
		 */
		public static function ajax_cookie_override() {
			check_ajax_referer( 'dbcm_scanner_nonce', 'nonce' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Unauthorized', 403 );
			}

			$id       = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
			$category = isset( $_POST['category'] )
				? sanitize_key( wp_unslash( $_POST['category'] ) )
				: '';

			if ( $id <= 0 || ! DBCM_Settings::is_valid_category( $category ) ) {
				wp_send_json_error( 'Invalid input', 400 );
			}

			global $wpdb;
			$table   = self::table_name();
			$updated = $wpdb->update(
				$table,
				array( 'category' => $category ),
				array( 'id' => $id ),
				array( '%s' ),
				array( '%d' )
			);

			if ( false === $updated ) {
				wp_send_json_error( 'DB error', 500 );
			}

			wp_send_json_success(
				array(
					'id'       => $id,
					'category' => $category,
					'label'    => DBCM_Cookie_Database::get_category_label( $category ),
					'color'    => DBCM_Cookie_Database::get_category_color( $category ),
				)
			);
		}

		/**
		 * AJAX: cancella un singolo cookie dalla tabella.
		 *
		 * @return void
		 */
		public static function ajax_cookie_delete() {
			check_ajax_referer( 'dbcm_scanner_nonce', 'nonce' );
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( 'Unauthorized', 403 );
			}

			$id = isset( $_POST['id'] ) ? (int) $_POST['id'] : 0;
			if ( $id <= 0 ) {
				wp_send_json_error( 'Invalid id', 400 );
			}

			global $wpdb;
			$table   = self::table_name();
			$deleted = $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );

			if ( false === $deleted ) {
				wp_send_json_error( 'DB error', 500 );
			}

			wp_send_json_success( array( 'id' => $id ) );
		}

		/* =====================================================================
		 * Fase 1: PREPARE
		 * ================================================================== */

		public static function run_scan_prepare() {
			global $wpdb;
			$table = self::table_name();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "TRUNCATE TABLE {$table}" );

			delete_option( 'dbcm_google_fonts_detected' );
			delete_option( 'dbcm_external_services_detected' );

			$urls = self::get_scan_urls();
			update_option( 'dbcm_scan_urls', $urls );
			update_option( 'dbcm_scan_urls_count', count( $urls ) );
			update_option( 'dbcm_scan_progress', 0 );

			return $urls;
		}

		/**
		 * Restituisce le URL del sito da scansionare. Mix di home, front
		 * page, blog, top 10 pagine, top 5 post, eventuali pagine WC.
		 *
		 * @return array<string>
		 */
		private static function get_scan_urls() {
			$urls = array( home_url( '/' ) );

			$front_id = (int) get_option( 'page_on_front' );
			if ( $front_id ) {
				$urls[] = get_permalink( $front_id );
			}
			$blog_id = (int) get_option( 'page_for_posts' );
			if ( $blog_id ) {
				$urls[] = get_permalink( $blog_id );
			}

			$pages = get_posts( array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 10,
				'orderby'        => 'date',
				'order'          => 'DESC',
			) );
			foreach ( $pages as $p ) {
				$urls[] = get_permalink( $p->ID );
			}

			$posts = get_posts( array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => 5,
				'orderby'        => 'date',
				'order'          => 'DESC',
			) );
			foreach ( $posts as $p ) {
				$urls[] = get_permalink( $p->ID );
			}

			if ( function_exists( 'wc_get_page_id' ) ) {
				foreach ( array( 'shop', 'cart', 'checkout', 'myaccount' ) as $woo_page ) {
					$id = wc_get_page_id( $woo_page );
					if ( $id > 0 ) {
						$urls[] = get_permalink( $id );
					}
				}
			}

			/**
			 * Permette di personalizzare la lista delle URL da scansionare.
			 *
			 * @param array $urls
			 */
			$urls = apply_filters( 'dbcm_scan_urls', array_unique( array_filter( $urls ) ) );
			return array_values( $urls );
		}

		/* =====================================================================
		 * Fase 2: SCAN URL singola
		 * ================================================================== */

		public static function run_scan_single( $url ) {
			global $wpdb;
			$table = self::table_name();

			$collected = array();

			// Cookie da Set-Cookie header.
			foreach ( self::scan_url_headers( $url ) as $cookie ) {
				$cookie['found_on'] = $url;
				$key = $cookie['name'] . '|' . $cookie['domain'];
				if ( ! isset( $collected[ $key ] ) ) {
					$collected[ $key ] = $cookie;
				}
			}

			// Cookie inferiti dal contenuto HTML (script di terze parti).
			foreach ( self::detect_from_html( $url ) as $cookie ) {
				$key = $cookie['name'] . '|' . $cookie['domain'];
				if ( ! isset( $collected[ $key ] ) ) {
					$collected[ $key ] = $cookie;
				}
			}

			// Persistenza: replace su (cookie_name, cookie_domain).
			foreach ( $collected as $cookie ) {
				$info = DBCM_Cookie_Database::identify_cookie( $cookie['name'] );

				$wpdb->replace(
					$table,
					array(
						'cookie_name'     => $cookie['name'],
						'cookie_domain'   => $cookie['domain'],
						'cookie_path'     => $cookie['path'],
						'cookie_duration' => ! empty( $info['duration'] ) ? $info['duration'] : $cookie['duration'],
						'cookie_secure'   => ! empty( $cookie['secure'] ) ? 1 : 0,
						'cookie_httponly' => ! empty( $cookie['httponly'] ) ? 1 : 0,
						'cookie_samesite' => $cookie['samesite'],
						'category'        => $info['category'],
						'description'     => $info['description'],
						'provider'        => $info['provider'],
						'found_on'        => $cookie['found_on'],
						'notes'           => '',
						'scan_date'       => current_time( 'mysql' ),
					),
					array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
				);
			}

			return count( $collected );
		}

		/**
		 * Esegue una richiesta HTTP all'URL e estrae i cookie dai
		 * Set-Cookie header e dall'oggetto WP_HTTP_Cookie.
		 *
		 * @param string $url
		 * @return array<array>
		 */
		private static function scan_url_headers( $url ) {
			$cookies = array();

			$response = wp_remote_get(
				$url,
				array(
					'timeout'    => 8,
					'sslverify'  => false, // localhost / dev environments.
					'cookies'    => array(),
					'user-agent' => 'DBCookieManager/' . DBCM_VERSION . ' (+self-scanner)',
				)
			);
			if ( is_wp_error( $response ) ) {
				return $cookies;
			}

			$set_cookies = self::extract_set_cookie_headers( $response );
			foreach ( $set_cookies as $header ) {
				$parsed = self::parse_set_cookie( $header );
				if ( $parsed ) {
					$cookies[] = $parsed;
				}
			}

			// WP HTTP API normalizza ulteriormente i cookie ricevuti.
			$wp_cookies = wp_remote_retrieve_cookies( $response );
			foreach ( $wp_cookies as $obj ) {
				$cookies[] = array(
					'name'     => $obj->name,
					'domain'   => $obj->domain,
					'path'     => $obj->path,
					'duration' => '',
					'secure'   => false,
					'httponly' => false,
					'samesite' => '',
				);
			}

			return $cookies;
		}

		/**
		 * Estrae l'array di Set-Cookie header indipendentemente dal tipo
		 * di Headers oggetto restituito da Requests / WpOrg\Requests.
		 *
		 * @param array $response Risultato di wp_remote_get.
		 * @return array<string>
		 */
		private static function extract_set_cookie_headers( $response ) {
			$headers = wp_remote_retrieve_headers( $response );

			// Vecchio Requests (WP < 6.2) o nuovo WpOrg\Requests (WP >= 6.2).
			$dict_class_old = '\Requests_Utility_CaseInsensitiveDictionary';
			$dict_class_new = '\WpOrg\Requests\Utility\CaseInsensitiveDictionary';

			if ( ( class_exists( $dict_class_new ) && $headers instanceof $dict_class_new )
				|| ( class_exists( $dict_class_old ) && $headers instanceof $dict_class_old ) ) {
				$all = $headers->getAll();
				if ( isset( $all['set-cookie'] ) ) {
					return (array) $all['set-cookie'];
				}
			} elseif ( is_array( $headers ) && isset( $headers['set-cookie'] ) ) {
				return (array) $headers['set-cookie'];
			}

			return array();
		}

		/**
		 * Parsa un singolo header Set-Cookie.
		 *
		 * @param string $header
		 * @return array|null
		 */
		private static function parse_set_cookie( $header ) {
			$parts = explode( ';', (string) $header );
			if ( empty( $parts[0] ) ) {
				return null;
			}

			$nv = explode( '=', trim( $parts[0] ), 2 );
			if ( count( $nv ) < 2 ) {
				return null;
			}

			$cookie = array(
				'name'     => trim( $nv[0] ),
				'domain'   => '',
				'path'     => '/',
				'duration' => '',
				'secure'   => false,
				'httponly' => false,
				'samesite' => '',
			);

			$count = count( $parts );
			for ( $i = 1; $i < $count; $i++ ) {
				$attr_parts = explode( '=', trim( $parts[ $i ] ), 2 );
				$key        = strtolower( trim( $attr_parts[0] ) );
				$value      = isset( $attr_parts[1] ) ? trim( $attr_parts[1] ) : '';

				switch ( $key ) {
					case 'domain':
						$cookie['domain'] = $value;
						break;
					case 'path':
						$cookie['path'] = $value;
						break;
					case 'max-age':
						$cookie['duration'] = self::seconds_to_human( (int) $value );
						break;
					case 'expires':
						$ts = strtotime( $value );
						if ( $ts ) {
							$cookie['duration'] = self::seconds_to_human( $ts - time() );
						}
						break;
					case 'secure':
						$cookie['secure'] = true;
						break;
					case 'httponly':
						$cookie['httponly'] = true;
						break;
					case 'samesite':
						$cookie['samesite'] = $value;
						break;
				}
			}

			if ( '' === $cookie['duration'] ) {
				$cookie['duration'] = __( 'Sessione', 'db-cookie-manager' );
			}

			return $cookie;
		}

		/**
		 * Detection HTML-based: cerca firme di servizi noti nel body
		 * e aggiunge i cookie corrispondenti che il servizio scriverebbe.
		 *
		 * Le firme sono allineate a quelle del blocker preventivo step 3
		 * per coerenza: quello che il blocker neutralizza, lo scanner lo
		 * rileva qui per documentarlo nella policy.
		 *
		 * @param string $url
		 * @return array<array>
		 */
		private static function detect_from_html( $url ) {
			$cookies   = array();
			$site_host = wp_parse_url( home_url(), PHP_URL_HOST );

			$response = wp_remote_get(
				$url,
				array(
					'timeout'    => 8,
					'sslverify'  => false,
					'user-agent' => 'DBCookieManager/' . DBCM_VERSION . ' (+self-scanner)',
				)
			);
			if ( is_wp_error( $response ) ) {
				return $cookies;
			}

			$html = (string) wp_remote_retrieve_body( $response );

			// Mappa firma → cookie da aggiungere. Ogni firma può aggiungere
			// più cookie. Le firme regex sono coerenti col blocker step 3.
			$detections = self::html_detection_map( $site_host );
			foreach ( $detections as $entry ) {
				if ( preg_match( $entry['signature'], $html ) ) {
					foreach ( $entry['cookies'] as $cookie ) {
						$cookies[] = self::make_detected_cookie(
							$cookie['name'],
							$cookie['domain'],
							$url
						);
					}
					if ( ! empty( $entry['flag'] ) ) {
						update_option( $entry['flag'], true );
					}
				}
			}

			return $cookies;
		}

		/**
		 * Restituisce la mappa delle detection HTML.
		 *
		 * Estensibile via filtro 'dbcm_scanner_html_detections'.
		 *
		 * @param string $site_host
		 * @return array<array>
		 */
		private static function html_detection_map( $site_host ) {
			$dot = '.' . ltrim( (string) $site_host, '.' );

			$map = array(
				// DB Cookie Manager (il nostro stesso banner).
				array(
					'signature' => '/dbcm_consent|dbcm-banner-root/',
					'cookies'   => array(
						array( 'name' => 'dbcm_consent', 'domain' => $site_host ),
					),
				),

				// Google Analytics / GTM / GA4.
				array(
					'signature' => '/google-analytics\.com\/analytics|googletagmanager\.com\/(gtag|gtm)|gtag\(|\bga\(/',
					'cookies'   => array(
						array( 'name' => '_ga',  'domain' => $dot ),
						array( 'name' => '_gid', 'domain' => $dot ),
					),
				),

				// Meta Pixel.
				array(
					'signature' => '/fbq\(|connect\.facebook\.net\/.*\/fbevents/',
					'cookies'   => array(
						array( 'name' => '_fbp', 'domain' => $dot ),
					),
				),

				// Hotjar.
				array(
					'signature' => '/static\.hotjar\.com|script\.hotjar\.com|\bhj\(/',
					'cookies'   => array(
						array( 'name' => '_hjSessionUser_*', 'domain' => $dot ),
					),
				),

				// Microsoft Clarity.
				array(
					'signature' => '/clarity\.ms\/tag/',
					'cookies'   => array(
						array( 'name' => '_clck', 'domain' => $dot ),
						array( 'name' => '_clsk', 'domain' => $dot ),
					),
				),

				// Bing UET (Microsoft Advertising).
				array(
					'signature' => '/bat\.bing\.com/',
					'cookies'   => array(
						array( 'name' => '_uetsid', 'domain' => $dot ),
						array( 'name' => '_uetvid', 'domain' => $dot ),
						array( 'name' => 'MUID',    'domain' => '.bing.com' ),
					),
				),

				// LinkedIn Insight.
				array(
					'signature' => '/snap\.licdn\.com|linkedin\.com\/px/',
					'cookies'   => array(
						array( 'name' => 'li_sugr', 'domain' => '.linkedin.com' ),
						array( 'name' => 'lidc',    'domain' => '.linkedin.com' ),
					),
				),

				// TikTok Pixel.
				array(
					'signature' => '/analytics\.tiktok\.com/',
					'cookies'   => array(
						array( 'name' => '_ttp', 'domain' => $dot ),
					),
				),

				// Pinterest Tag.
				array(
					'signature' => '/ct\.pinterest\.com|pintrk\(/',
					'cookies'   => array(
						array( 'name' => '_pinterest_*', 'domain' => $dot ),
					),
				),

				// HubSpot.
				array(
					'signature' => '/js\.hs-scripts\.com|hs-analytics/',
					'cookies'   => array(
						array( 'name' => '__hstc',    'domain' => $dot ),
						array( 'name' => 'hubspotutk', 'domain' => $dot ),
					),
				),

				// YouTube embed (anche nocookie: rilevabile, e va comunque
				// in marketing — c'è il consenso "aggiornato" sul player).
				array(
					'signature' => '/youtube\.com\/embed|youtube-nocookie\.com/',
					'cookies'   => array(
						array( 'name' => 'YSC',                'domain' => '.youtube.com' ),
						array( 'name' => 'VISITOR_INFO1_LIVE', 'domain' => '.youtube.com' ),
					),
				),

				// Vimeo embed.
				array(
					'signature' => '/player\.vimeo\.com/',
					'cookies'   => array(
						array( 'name' => 'vuid', 'domain' => '.vimeo.com' ),
					),
				),

				// Stripe (pagamento).
				array(
					'signature' => '/js\.stripe\.com|api\.stripe\.com/',
					'cookies'   => array(
						array( 'name' => '__stripe_mid', 'domain' => $dot ),
						array( 'name' => '__stripe_sid', 'domain' => $dot ),
					),
				),

				// Plausible (cookieless: no cookie da aggiungere ma flag servizio).
				array(
					'signature' => '/plausible\.io\/js\/(plausible|script)/',
					'cookies'   => array(),
					'flag'      => 'dbcm_external_services_detected',
				),

				// Umami (cookieless).
				array(
					'signature' => '/umami\.is\/script\.js/',
					'cookies'   => array(),
					'flag'      => 'dbcm_external_services_detected',
				),

				// Google Fonts (no cookie ma chiamata a server esterno → policy).
				array(
					'signature' => '/fonts\.googleapis\.com|fonts\.gstatic\.com/',
					'cookies'   => array(),
					'flag'      => 'dbcm_google_fonts_detected',
				),
			);

			/**
			 * Permette di aggiungere/sovrascrivere le detection HTML.
			 *
			 * @param array  $map
			 * @param string $site_host
			 */
			return apply_filters( 'dbcm_scanner_html_detections', $map, $site_host );
		}

		/**
		 * Costruisce un cookie "dedotto" dalla detection HTML.
		 *
		 * @param string $name
		 * @param string $domain
		 * @param string $url
		 * @return array
		 */
		private static function make_detected_cookie( $name, $domain, $url ) {
			return array(
				'name'     => $name,
				'domain'   => $domain,
				'path'     => '/',
				'duration' => '',
				'secure'   => false,
				'httponly' => false,
				'samesite' => '',
				'found_on' => $url,
			);
		}

		/* =====================================================================
		 * Fase 3: FINALIZE
		 * ================================================================== */

		public static function run_scan_finalize() {
			self::inject_core_cookies();
			self::detect_google_fonts_from_styles();
			self::detect_woocommerce_session();

			update_option( 'dbcm_last_scan', current_time( 'mysql' ) );
			delete_option( 'dbcm_scan_urls' );
			delete_option( 'dbcm_scan_progress' );

			return count( self::get_results() );
		}

		/**
		 * Inserisce i cookie WP core e quelli del nostro stesso banner.
		 * Uno scanner anonimo non li vedrebbe (richiedono login o
		 * un'interazione utente).
		 *
		 * Cambio rispetto a 2.0.1: aggiunto dbcm_consent. È il NOSTRO
		 * cookie e va in policy come functional, sennò la cookie policy
		 * generata risulta incoerente ("dichiari di scrivere un cookie
		 * di consenso ma non lo elenchi").
		 *
		 * @return void
		 */
		private static function inject_core_cookies() {
			global $wpdb;
			$table = self::table_name();
			$host  = wp_parse_url( home_url(), PHP_URL_HOST );

			$core = array(
				array(
					'name'     => 'dbcm_consent',
					'category' => 'functional',
					'desc'     => __( 'Memorizza la scelta dell\'utente sul consenso ai cookie.', 'db-cookie-manager' ),
					'duration' => '365 giorni',
					'provider' => 'DB Cookie Manager',
					'samesite' => 'Lax',
					'httponly' => 0,
				),
				array(
					'name'     => 'wordpress_sec_*',
					'category' => 'functional',
					'desc'     => __( 'Cookie di autenticazione per utenti registrati WordPress.', 'db-cookie-manager' ),
					'duration' => 'Sessione / 14 giorni',
					'provider' => 'WordPress',
					'samesite' => 'Lax',
					'httponly' => 1,
				),
				array(
					'name'     => 'wordpress_logged_in_*',
					'category' => 'functional',
					'desc'     => __( 'Indica se l\'utente è autenticato in WordPress.', 'db-cookie-manager' ),
					'duration' => 'Sessione / 14 giorni',
					'provider' => 'WordPress',
					'samesite' => 'Lax',
					'httponly' => 1,
				),
				array(
					'name'     => 'wp-settings-*',
					'category' => 'functional',
					'desc'     => __( 'Salva le preferenze dell\'interfaccia admin di WordPress.', 'db-cookie-manager' ),
					'duration' => '1 anno',
					'provider' => 'WordPress',
					'samesite' => 'Lax',
					'httponly' => 0,
				),
				array(
					'name'     => 'wordpress_test_cookie',
					'category' => 'functional',
					'desc'     => __( 'Verifica se il browser accetta i cookie.', 'db-cookie-manager' ),
					'duration' => 'Sessione',
					'provider' => 'WordPress',
					'samesite' => 'Lax',
					'httponly' => 0,
				),
			);

			foreach ( $core as $cookie ) {
				// Skip se già presente (insert "if not exists").
				$exists = (int) $wpdb->get_var(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						"SELECT COUNT(*) FROM {$table} WHERE cookie_name = %s",
						$cookie['name']
					)
				);
				if ( $exists ) {
					continue;
				}

				$wpdb->insert(
					$table,
					array(
						'cookie_name'     => $cookie['name'],
						'cookie_domain'   => $host,
						'cookie_path'     => '/',
						'cookie_duration' => $cookie['duration'],
						'cookie_secure'   => 0,
						'cookie_httponly' => $cookie['httponly'],
						'cookie_samesite' => $cookie['samesite'],
						'category'        => $cookie['category'],
						'description'     => $cookie['desc'],
						'provider'        => $cookie['provider'],
						'found_on'        => __( 'WordPress Core (sempre presente)', 'db-cookie-manager' ),
						'notes'           => '',
						'scan_date'       => current_time( 'mysql' ),
					),
					array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
				);
			}
		}

		/**
		 * Fallback per Google Fonts: se gli style enqueued contengono
		 * fonts.googleapis.com il flag viene impostato anche se la
		 * scansione HTTP non ha catturato l'header.
		 */
		private static function detect_google_fonts_from_styles() {
			if ( get_option( 'dbcm_google_fonts_detected', false ) ) {
				return;
			}
			global $wp_styles;
			if ( ! ( $wp_styles instanceof WP_Styles ) ) {
				return;
			}
			foreach ( $wp_styles->registered as $style ) {
				$src = isset( $style->src ) ? (string) $style->src : '';
				if ( false !== stripos( $src, 'fonts.googleapis.com' ) ) {
					update_option( 'dbcm_google_fonts_detected', true );
					return;
				}
			}
		}

		/**
		 * Se WooCommerce è attivo, aggiunge il cookie di sessione (che
		 * uno scanner anonimo non riceverebbe — viene creato solo dopo
		 * la prima azione carrello).
		 */
		private static function detect_woocommerce_session() {
			if ( ! function_exists( 'wc_get_page_id' ) ) {
				return;
			}
			global $wpdb;
			$table = self::table_name();
			$host  = wp_parse_url( home_url(), PHP_URL_HOST );

			$exists = (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT COUNT(*) FROM {$table} WHERE cookie_name = %s",
					'wp_woocommerce_session_*'
				)
			);
			if ( $exists ) {
				return;
			}

			$wpdb->insert(
				$table,
				array(
					'cookie_name'     => 'wp_woocommerce_session_*',
					'cookie_domain'   => $host,
					'cookie_path'     => '/',
					'cookie_duration' => '2 giorni',
					'cookie_secure'   => 0,
					'cookie_httponly' => 1,
					'cookie_samesite' => 'Lax',
					'category'        => 'functional',
					'description'     => __( 'Identificatore di sessione WooCommerce per gestire carrello e checkout.', 'db-cookie-manager' ),
					'provider'        => 'WooCommerce',
					'found_on'        => __( 'WooCommerce attivo (sessione carrello)', 'db-cookie-manager' ),
					'notes'           => '',
					'scan_date'       => current_time( 'mysql' ),
				),
				array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}

		/* =====================================================================
		 * Helper duration
		 * ================================================================== */

		/**
		 * Converte secondi in stringa human-readable.
		 *
		 * @param int $seconds
		 * @return string
		 */
		private static function seconds_to_human( $seconds ) {
			if ( $seconds <= 0 ) {
				return __( 'Sessione', 'db-cookie-manager' );
			}

			$years   = (int) round( $seconds / YEAR_IN_SECONDS );
			$months  = (int) round( $seconds / MONTH_IN_SECONDS );
			$days    = (int) round( $seconds / DAY_IN_SECONDS );
			$hours   = (int) round( $seconds / HOUR_IN_SECONDS );
			$minutes = (int) round( $seconds / MINUTE_IN_SECONDS );

			if ( $years   >= 1 ) return sprintf( _n( '%d anno',   '%d anni',   $years,   'db-cookie-manager' ), $years );
			if ( $months  >= 1 ) return sprintf( _n( '%d mese',   '%d mesi',   $months,  'db-cookie-manager' ), $months );
			if ( $days    >= 1 ) return sprintf( _n( '%d giorno', '%d giorni', $days,    'db-cookie-manager' ), $days );
			if ( $hours   >= 1 ) return sprintf( _n( '%d ora',    '%d ore',    $hours,   'db-cookie-manager' ), $hours );
			if ( $minutes >= 1 ) return sprintf( _n( '%d minuto', '%d minuti', $minutes, 'db-cookie-manager' ), $minutes );

			/* translators: %d: numero secondi */
			return sprintf( _n( '%d secondo', '%d secondi', $seconds, 'db-cookie-manager' ), $seconds );
		}

		/* =====================================================================
		 * Query API
		 * ================================================================== */

		/**
		 * Restituisce tutti i cookie scansionati, ordinati per categoria
		 * (priorità WP Consent API) poi per nome.
		 *
		 * @return array Lista di stdClass.
		 */
		public static function get_results() {
			global $wpdb;
			$table = self::table_name();

			// FIELD() definisce l'ordine custom delle categorie.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = "SELECT * FROM {$table}
			        ORDER BY FIELD(category, 'functional', 'preferences', 'statistics', 'statistics-anonymous', 'marketing'),
			                 cookie_name ASC";
			return $wpdb->get_results( $sql );
		}

		/**
		 * Stessa cosa, ma raggruppato per categoria.
		 *
		 * @return array<string, array>
		 */
		public static function get_results_grouped() {
			$results = self::get_results();
			$grouped = array();

			foreach ( $results as $row ) {
				$cat = $row->category;
				if ( ! isset( $grouped[ $cat ] ) ) {
					$grouped[ $cat ] = array();
				}
				$grouped[ $cat ][] = $row;
			}

			// Ordine canonico WP Consent API.
			$ordered = array();
			foreach ( DBCM_Settings::categories() as $cat ) {
				if ( isset( $grouped[ $cat ] ) ) {
					$ordered[ $cat ] = $grouped[ $cat ];
				}
			}
			return $ordered;
		}

		/**
		 * Conta i cookie per categoria. Utile per la dashboard admin.
		 *
		 * @return array<string, int>
		 */
		public static function count_by_category() {
			global $wpdb;
			$table = self::table_name();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( "SELECT category, COUNT(*) AS n FROM {$table} GROUP BY category" );

			$counts = array_fill_keys( DBCM_Settings::categories(), 0 );
			foreach ( $rows as $row ) {
				if ( isset( $counts[ $row->category ] ) ) {
					$counts[ $row->category ] = (int) $row->n;
				}
			}
			return $counts;
		}

		/**
		 * Restituisce i cookie scansionati il cui campo `provider` contiene
		 * la keyword indicata (case-insensitive, LIKE substring).
		 *
		 * Pensato per integrazioni cross-plugin DB. Esempio d'uso dal SEO
		 * Manager: l'audit homepage rileva l'host `googletagmanager.com`,
		 * lo identifica come "Google Tag Manager" e chiama questo metodo
		 * con keyword = "Google Tag Manager" per scoprire quali cookie ha
		 * effettivamente trovato lo scanner durante la scansione del sito.
		 *
		 * Perché LIKE su `provider` e non match esatto su un host: la
		 * tabella scanner non memorizza l'host del servizio ma una label
		 * umana ("Google Analytics", "Stripe", "Cloudflare"). Il chiamante
		 * passa la propria label di riferimento, noi facciamo LIKE: chi
		 * vuole un match più rigoroso può filtrare i risultati lato suo.
		 *
		 * Strategia di sicurezza:
		 *  - keyword sanificata: tutto ciò che non è alfanumerico, spazio,
		 *    trattino o underscore viene rimosso. Niente caratteri %_ LIKE
		 *    nell'input → niente injection di pattern.
		 *  - keyword troppo corta (< 2 char) → array vuoto. Evita che un
		 *    chiamante restituisca tutta la tabella per errore.
		 *  - usa $wpdb->prepare con $wpdb->esc_like per il pattern.
		 *
		 * @param string $keyword Substring del campo `provider` da cercare.
		 * @param int    $limit   Numero massimo di righe (default 50, hard cap 200).
		 * @return array Lista di stdClass nello stesso formato di get_results().
		 *               Array vuoto se keyword non valida o nessun match.
		 */
		public static function get_cookies_by_provider_keyword( $keyword, $limit = 50 ) {
			$keyword = is_string( $keyword ) ? trim( $keyword ) : '';
			// Sanifica: solo caratteri "innocui" per il match LIKE.
			$keyword = preg_replace( '/[^a-zA-Z0-9 _\-]/', '', $keyword );
			if ( strlen( $keyword ) < 2 ) {
				return array();
			}

			$limit = max( 1, min( 200, (int) $limit ) );

			global $wpdb;
			$table = self::table_name();
			$pattern = '%' . $wpdb->esc_like( $keyword ) . '%';

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = $wpdb->prepare(
				"SELECT * FROM {$table}
				 WHERE provider LIKE %s
				 ORDER BY FIELD(category, 'functional', 'preferences', 'statistics', 'statistics-anonymous', 'marketing'),
				          cookie_name ASC
				 LIMIT %d",
				$pattern,
				$limit
			);

			return $wpdb->get_results( $sql );
		}
	}
}
