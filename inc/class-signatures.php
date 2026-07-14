<?php
/**
 * DBCM_Signatures — Punto di accesso unico al database firme.
 *
 * Fonde tre sorgenti in una vista coerente:
 *   1. Firme statiche del plugin  → inc/data/signatures.php (condivise, read-only).
 *   2. Firme custom per-sito      → option 'dbcm_custom_signatures' (layer separato).
 *   3. Filtri di terze parti      → 'dbcm_blocker_patterns' / 'dbcm_scanner_html_detections'
 *                                    (restano indipendenti, gestiti dal core esistente).
 *
 * Da queste firme deriva le due VISTE storiche già usate dal core, così che
 * blocker e scanner non cambino la loro logica interna ma solo la sorgente dei
 * dati. L'aggancio avviene via i filtri già esistenti (strategia additiva:
 * rischio di regressione minimo — se questa classe fallisce, il core continua
 * a funzionare con i suoi array hard-coded).
 *
 * Le firme CUSTOM per-sito NON passano dai filtri di terze parti: sono un layer
 * separato, letto direttamente qui. Questo le tiene ispezionabili e distinte da
 * ciò che tema/plugin iniettano via filtro.
 *
 * ---------------------------------------------------------------------------
 * SCHEMA di una firma CUSTOM (option dbcm_custom_signatures = array di righe):
 *
 *   array(
 *     'slug'             => 'custom-xyz',        // generato se assente
 *     'service'          => 'Nome servizio',
 *     'provider'         => '',                  // opzionale
 *     'category'         => 'marketing',         // categoria WP Consent API
 *     'requires_consent' => true,
 *     'block_source'     => 'esempio.com/pixel', // dominio o regex della fonte
 *     'block_is_regex'   => false,               // true se block_source è regex
 *     'cookies'          => array(               // nomi/prefissi cookie associati
 *        array( 'name' => '_xyz', 'duration' => '', 'desc' => '' ),
 *     ),
 *     'reactive_cleanup' => true,                // cancella i cookie lato client
 *                                                //   se manca consenso (rete di sicurezza)
 *   )
 *
 * Un'unica riga serve entrambi i meccanismi:
 *   - 'block_source' valorizzato  → blocco preventivo della fonte.
 *   - 'cookies' valorizzati       → policy + cancellazione reattiva (se reactive_cleanup).
 * ---------------------------------------------------------------------------
 *
 * @package DBCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DBCM_Signatures' ) ) {

	class DBCM_Signatures {

		/**
		 * Option che contiene le firme personalizzate per-sito.
		 */
		const CUSTOM_OPTION = 'dbcm_custom_signatures';

		/**
		 * Cache in-memory delle firme fuse (statiche + custom).
		 *
		 * @var array|null
		 */
		private static $cache = null;

		/**
		 * Inizializzazione: aggancia le viste ai filtri esistenti del core.
		 * Chiamata da DBCM_Plugin->init_modules() PRIMA di Blocker/Scanner init.
		 */
		public static function init() {
			// Inietta la vista blocker nei pattern del blocker (priorità 5:
			// prima di eventuali filtri di terze parti a priorità 10+).
			add_filter( 'dbcm_blocker_patterns', array( __CLASS__, 'inject_blocker_patterns' ), 5 );

			// Inietta la vista scanner nelle detection HTML.
			add_filter( 'dbcm_scanner_html_detections', array( __CLASS__, 'inject_scanner_detections' ), 5, 2 );
		}

		/**
		 * Invalida la cache (dopo un salvataggio delle firme custom).
		 */
		public static function flush_cache() {
			self::$cache = null;
		}

		/* =====================================================================
		 * SORGENTE FUSA
		 * ================================================================== */

		/**
		 * Restituisce TUTTE le firme (statiche + custom per-sito), indicizzate
		 * per slug. Le custom con slug uguale a una statica la sovrascrivono.
		 *
		 * @return array<string,array>
		 */
		public static function all() {
			if ( null !== self::$cache ) {
				return self::$cache;
			}

			// 1. Firme statiche del plugin.
			if ( ! function_exists( 'dbcm_signatures_data' ) ) {
				$path = defined( 'DBCM_DIR' ) ? DBCM_DIR . 'inc/data/signatures.php' : __DIR__ . '/data/signatures.php';
				if ( is_readable( $path ) ) {
					require_once $path;
				}
			}
			$static = function_exists( 'dbcm_signatures_data' ) ? dbcm_signatures_data() : array();

			// 2. Firme custom per-sito, normalizzate.
			$custom = self::get_custom_normalized();

			// Merge: le custom sovrascrivono/aggiungono per slug.
			$merged = array_merge( $static, $custom );

			self::$cache = $merged;
			return self::$cache;
		}

		/**
		 * Restituisce solo le firme custom per-sito, normalizzate allo schema
		 * interno comune (stesso formato delle statiche, così le viste non
		 * devono distinguere l'origine).
		 *
		 * @return array<string,array>
		 */
		public static function get_custom_normalized() {
			$raw = get_option( self::CUSTOM_OPTION, array() );
			if ( ! is_array( $raw ) ) {
				return array();
			}

			$out = array();
			foreach ( $raw as $i => $row ) {
				if ( ! is_array( $row ) || empty( $row['service'] ) ) {
					continue;
				}

				$category = isset( $row['category'] ) ? (string) $row['category'] : 'marketing';
				if ( ! DBCM_Settings::is_valid_category( $category ) ) {
					$category = 'marketing';
				}

				$slug = ! empty( $row['slug'] )
					? sanitize_key( $row['slug'] )
					: 'custom-' . sanitize_key( $row['service'] ) . '-' . $i;

				$is_regex = ! empty( $row['block_is_regex'] );
				$source   = isset( $row['block_source'] ) ? trim( (string) $row['block_source'] ) : '';

				// Una fonte regex malformata farebbe fallire preg_match su OGNI
				// pagina: la validiamo qui e la degradiamo a substring se rotta.
				if ( $is_regex && '' !== $source && ! self::is_valid_regex( $source ) ) {
					$is_regex = false;
				}

				$script_patterns = array();
				$iframe_patterns = array();
				if ( '' !== $source && ! $is_regex ) {
					// Substring semplice: la usiamo sia per script sia per iframe,
					// il matching per tipo lo fa comunque il blocker.
					$script_patterns[] = $source;
					$iframe_patterns[] = $source;
				}

				$cookies = array();
				if ( ! empty( $row['cookies'] ) && is_array( $row['cookies'] ) ) {
					foreach ( $row['cookies'] as $c ) {
						if ( is_array( $c ) && ! empty( $c['name'] ) ) {
							$cookies[] = array(
								'name'     => (string) $c['name'],
								'domain'   => isset( $c['domain'] ) && '' !== $c['domain'] ? (string) $c['domain'] : '@self',
								'duration' => isset( $c['duration'] ) ? (string) $c['duration'] : '',
								'desc'     => isset( $c['desc'] ) ? (string) $c['desc'] : '',
							);
						} elseif ( is_string( $c ) && '' !== $c ) {
							$cookies[] = array(
								'name'     => $c,
								'domain'   => '@self',
								'duration' => '',
								'desc'     => '',
							);
						}
					}
				}

				$out[ $slug ] = array(
					'service'          => (string) $row['service'],
					'provider'         => isset( $row['provider'] ) ? (string) $row['provider'] : '',
					'category'         => $category,
					'requires_consent' => isset( $row['requires_consent'] ) ? (bool) $row['requires_consent'] : true,
					'script_patterns'  => $script_patterns,
					'iframe_patterns'  => $iframe_patterns,
					'cookies'          => $cookies,
					'_custom'          => true,
					'_regex_source'    => $is_regex ? $source : '',
					'reactive_cleanup' => ! empty( $row['reactive_cleanup'] ),
				);

				if ( ! empty( $row['scan_signature'] ) && self::is_valid_regex( (string) $row['scan_signature'] ) ) {
					$out[ $slug ]['scan_signature'] = (string) $row['scan_signature'];
				}
			}

			return $out;
		}

		/* =====================================================================
		 * VISTA BLOCKER — iniettata in 'dbcm_blocker_patterns'
		 * ================================================================== */

		/**
		 * Converte le firme in gruppi pattern per il blocker e li aggiunge a
		 * quelli esistenti.
		 *
		 * Regole:
		 *  - Salta le firme con requires_consent = false, TRANNE se hanno
		 *    blockable esplicito a true.
		 *  - Salta 'blockable' = false (PayPal SDK, Stripe, Google Fonts).
		 *  - Salta 'report_only' (WhatsApp ecc.: mai bloccate).
		 *  - YouTube nocookie: gestito dal core come marketing (il declassamento
		 *    a functional è una scelta admin, non automatica qui).
		 *
		 * @param array $patterns Pattern esistenti passati dal filtro.
		 * @return array
		 */
		public static function inject_blocker_patterns( $patterns ) {
			if ( ! is_array( $patterns ) ) {
				$patterns = array();
			}

			foreach ( self::all() as $slug => $sig ) {
				if ( ! empty( $sig['report_only'] ) ) {
					continue;
				}

				$blockable = self::is_blockable( $sig );
				if ( ! $blockable ) {
					continue;
				}

				$category = $sig['category'];
				if ( ! DBCM_Settings::is_valid_category( $category ) ) {
					continue;
				}

				// Gruppo script.
				$script_patterns = isset( $sig['script_patterns'] ) ? (array) $sig['script_patterns'] : array();
				// Fonte regex custom → pattern dedicato con marcatore regex.
				if ( ! empty( $sig['_regex_source'] ) ) {
					$patterns[] = array(
						'category'  => $category,
						'type'      => 'both',
						'patterns'  => array( $sig['_regex_source'] ),
						'is_regex'  => true,
						'_dbcm_sig' => $slug,
					);
				}
				if ( ! empty( $script_patterns ) ) {
					$patterns[] = array(
						'category'  => $category,
						'type'      => 'script',
						'patterns'  => array_values( $script_patterns ),
						'_dbcm_sig' => $slug,
					);
				}

				// Gruppo iframe.
				$iframe_patterns = isset( $sig['iframe_patterns'] ) ? (array) $sig['iframe_patterns'] : array();
				if ( ! empty( $iframe_patterns ) ) {
					$patterns[] = array(
						'category'  => $category,
						'type'      => 'iframe',
						'patterns'  => array_values( $iframe_patterns ),
						'_dbcm_sig' => $slug,
					);
				}
			}

			return $patterns;
		}

		/* =====================================================================
		 * VISTA SCANNER — iniettata in 'dbcm_scanner_html_detections'
		 * ================================================================== */

		/**
		 * Converte le firme in detection HTML per lo scanner e le aggiunge a
		 * quelle esistenti.
		 *
		 * @param array  $map       Detection esistenti dal filtro.
		 * @param string $site_host Host del sito (per risolvere @self).
		 * @return array
		 */
		public static function inject_scanner_detections( $map, $site_host = '' ) {
			if ( ! is_array( $map ) ) {
				$map = array();
			}
			if ( '' === $site_host ) {
				$site_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			}

			foreach ( self::all() as $slug => $sig ) {
				$signature = self::derive_scan_signature( $sig );
				if ( '' === $signature ) {
					// Servizio "solo cookie" senza pattern di rilevamento HTML
					// (es. WooCommerce, WordPress core): non è rilevabile via
					// firma nell'HTML. I suoi cookie tecnici vengono iniettati
					// direttamente dal core (DBCM_Scanner::inject_core_cookies),
					// quindi qui lo saltiamo per non generare detection vuote o
					// duplicati. Comportamento intenzionale.
					continue;
				}

				$cookies = array();
				foreach ( ( isset( $sig['cookies'] ) ? (array) $sig['cookies'] : array() ) as $cookie ) {
					if ( empty( $cookie['name'] ) ) {
						continue;
					}
					$cookies[] = array(
						'name'   => $cookie['name'],
						'domain' => self::resolve_domain(
							isset( $cookie['domain'] ) ? $cookie['domain'] : '@self',
							$site_host
						),
					);
				}

				$entry = array(
					'signature' => $signature,
					'cookies'   => $cookies,
				);
				if ( ! empty( $sig['flag'] ) ) {
					$entry['flag'] = $sig['flag'];
				}

				$map[] = $entry;
			}

			return $map;
		}

		/* =====================================================================
		 * LOOKUP — classificazione di un singolo cookie
		 * ================================================================== */

		/**
		 * Data una firma, dice se è bloccabile dal motore.
		 *
		 * @param array $sig
		 * @return bool
		 */
		public static function is_blockable( $sig ) {
			// 'blockable' esplicito ha la precedenza.
			if ( isset( $sig['blockable'] ) ) {
				return (bool) $sig['blockable'];
			}
			// Altrimenti: bloccabile solo se richiede consenso.
			return ! empty( $sig['requires_consent'] );
		}

		/**
		 * Classifica un cookie per nome/prefisso: restituisce categoria +
		 * metadati del servizio, o null se sconosciuto. Usato dallo scanner e
		 * dalla cancellazione reattiva.
		 *
		 * @param string $cookie_name
		 * @return array|null { slug, service, category, requires_consent, reactive_cleanup }
		 */
		public static function classify_cookie( $cookie_name ) {
			$cookie_name = (string) $cookie_name;
			if ( '' === $cookie_name ) {
				return null;
			}

			foreach ( self::all() as $slug => $sig ) {
				foreach ( ( isset( $sig['cookies'] ) ? (array) $sig['cookies'] : array() ) as $cookie ) {
					if ( empty( $cookie['name'] ) ) {
						continue;
					}
					if ( self::cookie_name_matches( $cookie['name'], $cookie_name ) ) {
						return array(
							'slug'             => $slug,
							'service'          => $sig['service'],
							'category'         => $sig['category'],
							'requires_consent' => ! empty( $sig['requires_consent'] ),
							'reactive_cleanup' => ! empty( $sig['reactive_cleanup'] ),
						);
					}
				}
			}
			return null;
		}

		/**
		 * Restituisce la lista di cookie da cancellare lato client quando manca
		 * il consenso per la loro categoria (rete di sicurezza). Include:
		 *  - firme custom con reactive_cleanup = true;
		 *  - firme statiche che richiedono consenso e hanno cookie noti.
		 *
		 * Formato: array di { name, category } (name può contenere '*').
		 *
		 * @return array<array{name:string,category:string}>
		 */
		public static function reactive_cleanup_list() {
			$list = array();
			foreach ( self::all() as $sig ) {
				if ( empty( $sig['requires_consent'] ) ) {
					continue;
				}
				// Per le statiche: solo se esplicitamente richiesto a livello globale?
				// Qui includiamo custom (opt-in per riga) + statiche con cookie noti.
				$include = ! empty( $sig['_custom'] ) ? ! empty( $sig['reactive_cleanup'] ) : true;
				if ( ! $include ) {
					continue;
				}
				foreach ( ( isset( $sig['cookies'] ) ? (array) $sig['cookies'] : array() ) as $cookie ) {
					if ( ! empty( $cookie['name'] ) ) {
						$list[] = array(
							'name'     => $cookie['name'],
							'category' => $sig['category'],
						);
					}
				}
			}
			return $list;
		}

		/* =====================================================================
		 * HELPER
		 * ================================================================== */

		/**
		 * Deriva una regex di detection scanner da una firma. Usa
		 * 'scan_signature' se presente, altrimenti la costruisce dai pattern.
		 *
		 * @param array $sig
		 * @return string Regex completa (con delimitatori) o '' se non derivabile.
		 */
		private static function derive_scan_signature( $sig ) {
			if ( ! empty( $sig['scan_signature'] ) && self::is_valid_regex( $sig['scan_signature'] ) ) {
				return $sig['scan_signature'];
			}

			$needles = array();
			foreach ( array( 'script_patterns', 'iframe_patterns' ) as $key ) {
				if ( ! empty( $sig[ $key ] ) ) {
					foreach ( (array) $sig[ $key ] as $p ) {
						$needles[] = preg_quote( $p, '/' );
					}
				}
			}
			if ( ! empty( $sig['_regex_source'] ) ) {
				// Fonte già regex: la incapsuliamo com'è (già validata).
				return '/' . str_replace( '/', '\/', $sig['_regex_source'] ) . '/';
			}
			if ( empty( $needles ) ) {
				return '';
			}
			return '/' . implode( '|', $needles ) . '/';
		}

		/**
		 * Risolve i token di dominio:
		 *   '@self'   → host del sito (es. esempio.com)
		 *   '@self.'  → .host del sito (es. .esempio.com)
		 *   altro     → invariato.
		 *
		 * @param string $domain
		 * @param string $site_host
		 * @return string
		 */
		private static function resolve_domain( $domain, $site_host ) {
			if ( '@self' === $domain ) {
				return $site_host;
			}
			if ( '@self.' === $domain ) {
				return '.' . ltrim( $site_host, '.' );
			}
			return $domain;
		}

		/**
		 * Confronta un pattern-nome-cookie (che può contenere '*') con un nome
		 * reale. '_ga_*' matcha '_ga_ABC123'; 'store_notice*' matcha
		 * 'store_notice_xyz'.
		 *
		 * @param string $pattern Nome/prefisso dalla firma.
		 * @param string $actual  Nome reale del cookie.
		 * @return bool
		 */
		public static function cookie_name_matches( $pattern, $actual ) {
			$pattern = (string) $pattern;
			$actual  = (string) $actual;
			if ( '' === $pattern ) {
				return false;
			}
			if ( false === strpos( $pattern, '*' ) ) {
				return $pattern === $actual;
			}
			$regex = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/';
			return (bool) preg_match( $regex, $actual );
		}

		/**
		 * Verifica che una stringa sia una regex PHP valida (con delimitatori).
		 * Sopprime il warning: una regex rotta non deve fatalizzare.
		 *
		 * @param string $pattern
		 * @return bool
		 */
		public static function is_valid_regex( $pattern ) {
			if ( ! is_string( $pattern ) || '' === $pattern ) {
				return false;
			}
			// set_error_handler evita che un warning finisca nell'output.
			set_error_handler( function () { return true; } );
			$valid = false !== @preg_match( $pattern, '' );
			restore_error_handler();
			return $valid;
		}

		/* =====================================================================
		 * CRUD firme custom (usato dall'admin UI, step successivo)
		 * ================================================================== */

		/**
		 * Restituisce le firme custom RAW (come salvate), per l'editing admin.
		 *
		 * @return array
		 */
		public static function get_custom_raw() {
			$raw = get_option( self::CUSTOM_OPTION, array() );
			return is_array( $raw ) ? $raw : array();
		}

		/**
		 * Salva l'intero set di firme custom (sostituzione). Sanifica e valida
		 * ogni riga; scarta le regex malformate degradandole a substring.
		 *
		 * @param array $rows
		 * @return bool
		 */
		public static function save_custom( $rows ) {
			if ( ! is_array( $rows ) ) {
				return false;
			}
			$clean = array();
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) || empty( $row['service'] ) ) {
					continue;
				}
				$clean[] = array(
					'slug'             => ! empty( $row['slug'] ) ? sanitize_key( $row['slug'] ) : '',
					'service'          => sanitize_text_field( $row['service'] ),
					'provider'         => isset( $row['provider'] ) ? sanitize_text_field( $row['provider'] ) : '',
					'category'         => isset( $row['category'] ) && DBCM_Settings::is_valid_category( $row['category'] )
						? $row['category'] : 'marketing',
					'requires_consent' => ! empty( $row['requires_consent'] ),
					'block_source'     => isset( $row['block_source'] ) ? trim( wp_strip_all_tags( $row['block_source'] ) ) : '',
					'block_is_regex'   => ! empty( $row['block_is_regex'] ),
					'reactive_cleanup' => ! empty( $row['reactive_cleanup'] ),
					'cookies'          => self::sanitize_custom_cookies( isset( $row['cookies'] ) ? $row['cookies'] : array() ),
				);
			}
			$saved = update_option( self::CUSTOM_OPTION, $clean );
			self::flush_cache();
			return $saved;
		}

		/**
		 * Sanifica la lista cookie di una firma custom.
		 *
		 * @param mixed $cookies
		 * @return array
		 */
		private static function sanitize_custom_cookies( $cookies ) {
			if ( ! is_array( $cookies ) ) {
				return array();
			}
			$out = array();
			foreach ( $cookies as $c ) {
				if ( is_string( $c ) && '' !== trim( $c ) ) {
					$out[] = array( 'name' => sanitize_text_field( $c ), 'domain' => '@self', 'duration' => '', 'desc' => '' );
				} elseif ( is_array( $c ) && ! empty( $c['name'] ) ) {
					$out[] = array(
						'name'     => sanitize_text_field( $c['name'] ),
						'domain'   => isset( $c['domain'] ) && '' !== $c['domain'] ? sanitize_text_field( $c['domain'] ) : '@self',
						'duration' => isset( $c['duration'] ) ? sanitize_text_field( $c['duration'] ) : '',
						'desc'     => isset( $c['desc'] ) ? sanitize_text_field( $c['desc'] ) : '',
					);
				}
			}
			return $out;
		}
	}
}
