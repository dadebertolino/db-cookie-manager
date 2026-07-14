<?php
/**
 * Bootstrap dei test di INTEGRAZIONE.
 *
 * A differenza degli unit test (che stubbano WordPress), questi girano contro
 * un WordPress VERO con un database MySQL reale, tramite la WordPress test
 * suite (WP_UnitTestCase). Servono a verificare l'I/O su database di
 * DBCM_Scanner e DBCM_Consent_Log: creazione tabelle, insert/update/delete,
 * vincoli UNIQUE, query di raggruppamento — cose che gli unit test non toccano.
 *
 * Il percorso della test suite arriva da WP_TESTS_DIR (impostato dallo script
 * di installazione bin/install-wp-tests.sh, eseguito nel job CI di integrazione).
 *
 * @package DBCM\Tests\Integration
 */

$_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $_tests_dir ) {
	$_tests_dir = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
}

$_phpunit_polyfills = getenv( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' );
if ( false !== $_phpunit_polyfills ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', $_phpunit_polyfills );
}

if ( ! file_exists( "{$_tests_dir}/includes/functions.php" ) ) {
	echo "Impossibile trovare {$_tests_dir}/includes/functions.php" . PHP_EOL;
	echo "Esegui prima bin/install-wp-tests.sh." . PHP_EOL;
	exit( 1 );
}

// Carica le funzioni per creare i test.
require_once "{$_tests_dir}/includes/functions.php";

/**
 * Carica il plugin DB Cookie Manager prima che WordPress finisca il bootstrap,
 * così le sue classi sono disponibili e gli hook di attivazione registrati.
 */
function _dbcm_manually_load_plugin() {
	require dirname( __DIR__, 2 ) . '/db-cookie-manager.php';
}
tests_add_filter( 'muplugins_loaded', '_dbcm_manually_load_plugin' );

// Avvia la WordPress test suite.
require "{$_tests_dir}/includes/bootstrap.php";
