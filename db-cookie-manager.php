<?php
/**
 * Plugin Name: DB Cookie Manager
 * Plugin URI: https://www.davidebertolino.it/progetti/db-cookie-manager
 * Description: Gestione completa dei cookie per WordPress: scansione automatica, banner GDPR con blocco preventivo, classificazione, generatore Cookie Policy e registro consensi.
 * Version: 2.0.1
 * Author: Davide "The Prof." Bertolino
 * Author URI: https://www.davidebertolino.it
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: db-cookie-manager
 * Requires at least: 5.9
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'DBCM_VERSION', '2.0.1' );
define( 'DBCM_DIR', plugin_dir_path( __FILE__ ) );
define( 'DBCM_URL', plugin_dir_url( __FILE__ ) );

require_once DBCM_DIR . 'inc/class-cookie-database.php';
require_once DBCM_DIR . 'inc/class-scanner.php';
require_once DBCM_DIR . 'inc/class-settings.php';
require_once DBCM_DIR . 'inc/class-policy-generator.php';
require_once DBCM_DIR . 'inc/class-admin.php';
require_once DBCM_DIR . 'inc/class-banner.php';
require_once DBCM_DIR . 'inc/class-blocker.php';
require_once DBCM_DIR . 'inc/class-consent-log.php';

// Initialize
add_action( 'plugins_loaded', function() {
    DBCM_Admin::init();
    DBCM_Consent_Log::init();
    // Auto-create tables if missing
    global $wpdb;
    $table = $wpdb->prefix . 'dbcm_cookies';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
        DBCM_Scanner::create_table();
    }
    $log_table = $wpdb->prefix . 'dbcm_consent_log';
    if ( $wpdb->get_var( "SHOW TABLES LIKE '{$log_table}'" ) !== $log_table ) {
        DBCM_Consent_Log::create_table();
    }
} );

// Init banner and blocker on frontend
add_action( 'init', function() {
    if ( ! is_admin() ) {
        DBCM_Banner::init();
        DBCM_Blocker::init();
    }
} );

// AJAX handlers
add_action( 'wp_ajax_dbcm_prepare_scan', function() {
    check_ajax_referer( 'dbcm_ajax_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }
    $urls = DBCM_Scanner::run_scan_prepare();
    wp_send_json_success( array( 'urls' => array_values( $urls ), 'total' => count( $urls ) ) );
} );

add_action( 'wp_ajax_dbcm_scan_url', function() {
    check_ajax_referer( 'dbcm_ajax_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }
    $url = isset( $_POST['url'] ) ? esc_url_raw( $_POST['url'] ) : '';
    if ( empty( $url ) ) {
        wp_send_json_error( 'No URL' );
    }
    $count = DBCM_Scanner::run_scan_single( $url );
    wp_send_json_success( array( 'cookies_found' => $count, 'url' => $url ) );
} );

add_action( 'wp_ajax_dbcm_finalize_scan', function() {
    check_ajax_referer( 'dbcm_ajax_nonce', 'nonce' );
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( 'Unauthorized' );
    }
    $total = DBCM_Scanner::run_scan_finalize();
    wp_send_json_success( array( 'total_cookies' => $total ) );
} );

// Create DB table on activation
register_activation_hook( __FILE__, function() {
    DBCM_Scanner::create_table();
    DBCM_Consent_Log::create_table();
} );
