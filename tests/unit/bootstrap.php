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
	$ref->setAccessible( true );
	return $ref->invokeArgs( null, $args );
}

// Carica i sorgenti sotto test.
require_once DBCM_TEST_ROOT . '/inc/data/signatures.php';
require_once DBCM_TEST_ROOT . '/inc/class-signatures.php';
require_once DBCM_TEST_ROOT . '/inc/class-consent-api.php';

// Autoload Composer per PHPUnit.
require_once DBCM_TEST_ROOT . '/vendor/autoload.php';
