<?php
/**
 * Bootstrap dei test UNIT.
 *
 * La logica di DBCM_Signatures è PHP puro: dipende solo da poche funzioni
 * WordPress (get_option, __, sanitize_*, home_url, i filtri). Le stubbiamo qui
 * così il job unit resta leggero — niente MySQL, niente WP test suite. Gli
 * scenari che richiedono WordPress vero sono coperti dai test E2E.
 *
 * @package DBCM\Tests
 */

// Marcatore per i file del plugin che controllano ABSPATH.
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', sys_get_temp_dir() . '/' );
}

// Percorso dei sorgenti del plugin (root del repo).
define( 'DBCM_TEST_ROOT', dirname( __DIR__, 2 ) );

if ( ! defined( 'DBCM_DIR' ) ) {
	define( 'DBCM_DIR', DBCM_TEST_ROOT . '/' );
}
if ( ! defined( 'DBCM_VERSION' ) ) {
	define( 'DBCM_VERSION', 'test' );
}

/* -----------------------------------------------------------------------------
 * Stub minimale delle funzioni WordPress usate da DBCM_Signatures.
 * Uno store globale simula l'option table.
 * -------------------------------------------------------------------------- */

$GLOBALS['__dbcm_options'] = array();
$GLOBALS['__dbcm_filters'] = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return array_key_exists( $key, $GLOBALS['__dbcm_options'] )
			? $GLOBALS['__dbcm_options'][ $key ]
			: $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value ) {
		$GLOBALS['__dbcm_options'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( str_replace( ' ', '-', (string) $key ) ) );
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}
if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $str ) {
		return strip_tags( (string) $str );
	}
}
if ( ! function_exists( 'home_url' ) ) {
	function home_url() {
		return 'https://gatdus.example';
	}
}
if ( ! function_exists( 'wp_parse_url' ) ) {
	function wp_parse_url( $url, $component = -1 ) {
		return parse_url( $url, $component );
	}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $cb, $priority = 10, $args = 1 ) {
		$GLOBALS['__dbcm_filters'][ $hook ][] = $cb;
		return true;
	}
}
if ( ! function_exists( 'apply_filters' ) ) {
	function apply_filters( $hook, $value ) {
		$args = array_slice( func_get_args(), 1 );
		if ( ! empty( $GLOBALS['__dbcm_filters'][ $hook ] ) ) {
			foreach ( $GLOBALS['__dbcm_filters'][ $hook ] as $cb ) {
				$args[0] = call_user_func_array( $cb, $args );
			}
		}
		return $args[0];
	}
}

/**
 * Reset dello stato globale fra un test e l'altro.
 */
function dbcm_test_reset() {
	$GLOBALS['__dbcm_options'] = array();
	$GLOBALS['__dbcm_filters'] = array();
	DBCM_Signatures::flush_cache();
}

/* -----------------------------------------------------------------------------
 * DBCM_Settings minimale: DBCM_Signatures usa solo is_valid_category().
 * Se il vero class-settings.php è presente, usiamo quello.
 * -------------------------------------------------------------------------- */

if ( ! class_exists( 'DBCM_Settings' ) ) {
	require_once DBCM_TEST_ROOT . '/inc/class-settings.php';
}

/* -----------------------------------------------------------------------------
 * Stub aggiuntivi per testare DBCM_Consent_API.
 *
 * La WP Consent API (wp_has_consent/wp_set_consent) è OPZIONALE: il plugin
 * funziona sia con sia senza. Per testare entrambi i rami la rendiamo
 * attivabile a runtime via un flag globale, invece di definirla sempre.
 * -------------------------------------------------------------------------- */

$GLOBALS['__dbcm_wp_consent_api']   = false;      // WP Consent API installata?
$GLOBALS['__dbcm_wp_consent_store'] = array();    // stato consensi (se attiva)

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return ! empty( $GLOBALS['__dbcm_is_admin'] );
	}
}
if ( ! function_exists( 'do_action' ) ) {
	function do_action( $hook ) {
		// no-op negli unit test.
	}
}

/**
 * Attiva/disattiva la finta WP Consent API per un test.
 *
 * @param bool $enabled
 */
function dbcm_test_set_consent_api( $enabled ) {
	$GLOBALS['__dbcm_wp_consent_api']   = (bool) $enabled;
	$GLOBALS['__dbcm_wp_consent_store'] = array();

	if ( $enabled ) {
		if ( ! function_exists( 'wp_has_consent' ) ) {
			function wp_has_consent( $category ) {
				return isset( $GLOBALS['__dbcm_wp_consent_store'][ $category ] )
					&& 'allow' === $GLOBALS['__dbcm_wp_consent_store'][ $category ];
			}
		}
		if ( ! function_exists( 'wp_set_consent' ) ) {
			function wp_set_consent( $category, $value ) {
				$GLOBALS['__dbcm_wp_consent_store'][ $category ] = $value;
			}
		}
	}
}

/**
 * Imposta il cookie di consenso simulato ($_COOKIE) con schema corretto.
 * Passare null per rimuoverlo.
 *
 * @param array|string|null $categories Mappa categoria=>bool, JSON grezzo, o null.
 * @param int|null          $schema     Versione schema (default: quella corrente).
 */
function dbcm_test_set_consent_cookie( $categories, $schema = null ) {
	$name = DBCM_Settings::COOKIE_NAME;
	if ( null === $categories ) {
		unset( $_COOKIE[ $name ] );
		return;
	}
	if ( is_string( $categories ) ) {
		$_COOKIE[ $name ] = $categories; // payload grezzo (per test malformati).
		return;
	}
	$payload      = $categories;
	$payload['v'] = ( null === $schema ) ? DBCM_Settings::COOKIE_SCHEMA_VERSION : $schema;
	$_COOKIE[ $name ] = wp_json_encode( $payload );
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data ) {
		return json_encode( $data );
	}
}

/**
 * Reset completo per DBCM_Consent_API fra i test.
 */
function dbcm_test_reset_consent() {
	$GLOBALS['__dbcm_is_admin'] = false;
	dbcm_test_set_consent_api( false );
	unset( $_COOKIE[ DBCM_Settings::COOKIE_NAME ] );
}

/**
 * Invoca un metodo privato/protetto statico via Reflection. Serve a testare
 * logica critica non pubblica (es. sanitize_consent_payload) senza modificare
 * la visibilità nel codice di produzione.
 *
 * @param string $class
 * @param string $method
 * @param array  $args
 * @return mixed
 */
function dbcm_test_call_private( $class, $method, array $args = array() ) {
	$ref = new ReflectionMethod( $class, $method );
	if ( PHP_VERSION_ID < 80100 ) {
		$ref->setAccessible( true );
	}
	return $ref->invokeArgs( null, $args );
}

/* -----------------------------------------------------------------------------
 * Stub per testare DBCM_Policy_Generator.
 *
 * Il generator produce HTML da: i risultati raggruppati dello scanner e le
 * etichette/descrizioni del cookie database. Rendiamo entrambi controllabili
 * via variabili globali, e stubbiamo le funzioni di escaping/output WP.
 * -------------------------------------------------------------------------- */

$GLOBALS['__dbcm_grouped_results'] = array();

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $t ) {
		return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $t, $d = 'default' ) {
		return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $u ) {
		return htmlspecialchars( (string) $u, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $u ) {
		$u = trim( (string) $u );
		// Stub minimale: accetta solo http/https, come il comportamento WP di default.
		if ( '' === $u || ! preg_match( '#^https?://#i', $u ) ) {
			return '';
		}
		return $u;
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $t ) {
		return htmlspecialchars( (string) $t, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_js' ) ) {
	function esc_js( $t ) {
		// Stub minimale: sufficiente per un Pixel ID numerico.
		return addslashes( (string) $t );
	}
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $key = 'name' ) {
		return 'Sito Di Test';
	}
}
if ( ! function_exists( 'date_i18n' ) ) {
	function date_i18n( $format, $ts = null ) {
		return gmdate( $format ?: 'Y-m-d', $ts ?? time() );
	}
}

/**
 * Imposta i risultati scansione raggruppati che DBCM_Scanner::get_results_grouped
 * restituirà nei test. Formato: array<categoria, array<oggetto-cookie>>.
 *
 * @param array $grouped
 */
function dbcm_test_set_grouped_results( $grouped ) {
	$GLOBALS['__dbcm_grouped_results'] = $grouped;
}

/**
 * Crea un oggetto cookie come lo produce lo scanner (stdClass con le proprietà
 * usate dal policy generator).
 *
 * @param string $name
 * @param string $provider
 * @param string $description
 * @param string $duration
 * @return stdClass
 */
function dbcm_test_cookie_row( $name, $provider = '', $description = '', $duration = '' ) {
	$o                  = new stdClass();
	$o->cookie_name     = $name;
	$o->provider        = $provider;
	$o->description     = $description;
	$o->cookie_duration = $duration;
	return $o;
}

// Stub di DBCM_Scanner (solo il metodo usato dal generator).
if ( ! class_exists( 'DBCM_Scanner' ) ) {
	class DBCM_Scanner {
		public static function get_results_grouped() {
			return $GLOBALS['__dbcm_grouped_results'];
		}
	}
}

// Stub di DBCM_Cookie_Database (etichette/descrizioni categoria).
if ( ! class_exists( 'DBCM_Cookie_Database' ) ) {
	class DBCM_Cookie_Database {
		public static function get_category_label( $category ) {
			$labels = array(
				'functional'           => 'Tecnici (necessari)',
				'preferences'          => 'Preferenze',
				'statistics'           => 'Statistici',
				'statistics-anonymous' => 'Statistici anonimi',
				'marketing'            => 'Marketing',
			);
			return $labels[ $category ] ?? $category;
		}
		public static function get_category_description( $category ) {
			return 'Descrizione categoria ' . $category;
		}
		public static function get_transfer_info( $provider ) {
			$provider = strtolower( (string) $provider );
			$us = array( 'google', 'youtube', 'meta', 'facebook', 'instagram', 'microsoft', 'clarity', 'linkedin', 'tiktok', 'pinterest', 'hotjar', 'hubspot', 'cloudflare', 'stripe', 'mixpanel', 'heap', 'amplitude', 'mailchimp', 'convertkit', 'intercom', 'drift', 'twitter' );
			foreach ( $us as $k ) {
				if ( false !== strpos( $provider, $k ) ) {
					return array( 'location' => 'USA (extra-UE)', 'country' => 'US' );
				}
			}
			return array( 'location' => '', 'country' => '' );
		}
	}
}

// Carica i sorgenti sotto test.
require_once DBCM_TEST_ROOT . '/inc/data/signatures.php';
require_once DBCM_TEST_ROOT . '/inc/class-signatures.php';
require_once DBCM_TEST_ROOT . '/inc/class-declared-services.php';
require_once DBCM_TEST_ROOT . '/inc/class-consent-api.php';
require_once DBCM_TEST_ROOT . '/inc/class-consent-signals.php';
require_once DBCM_TEST_ROOT . '/inc/class-policy-generator.php';
require_once DBCM_TEST_ROOT . '/inc/class-blocker.php';
require_once DBCM_TEST_ROOT . '/inc/class-meta-pixel.php';

// Autoload Composer per PHPUnit.
require_once DBCM_TEST_ROOT . '/vendor/autoload.php';