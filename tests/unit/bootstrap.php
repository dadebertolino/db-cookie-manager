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

// Carica i sorgenti sotto test.
require_once DBCM_TEST_ROOT . '/inc/data/signatures.php';
require_once DBCM_TEST_ROOT . '/inc/class-signatures.php';

// Autoload Composer per PHPUnit.
require_once DBCM_TEST_ROOT . '/vendor/autoload.php';
