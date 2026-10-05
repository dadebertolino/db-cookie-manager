<?php
/**
 * Plugin Name: DBCM E2E Fixture
 * Description: Costruisce condizioni di test deterministiche per gli E2E di
 *              DB Cookie Manager. Attivo SOLO in ambiente wp-env (mu-plugin).
 *              NON fa parte del pacchetto distribuito.
 *
 * Fornisce:
 *  - ?dbcm_e2e=1  → pagina GREZZA (niente wp_head/wp_footer): l'HTML arriva
 *                   al blocker esattamente come scritto, senza passare da
 *                   the_content/wpautop/wp_kses che rimuovono gli iframe.
 *                   banner.js è emesso a mano con una config minima.
 *  - ?dbcm_e2e=wp → stessa pagina ma con wp_head()/wp_footer(): config reale
 *                   del banner, CSS inline, snippet GCM/UET/Clarity, gate
 *                   Meta Pixel, script enqueued (filtro script_loader_tag).
 *  - REST POST /dbcm-e2e/v1/reset → riporta il plugin allo stato baseline
 *                   (vedi dbcm_e2e_reset_state()). Chiamato dai test.
 *
 * Le opzioni NON vengono più riscritte a ogni richiesta: lo stato baseline
 * si imposta solo via reset (setup-e2e.sh e beforeEach dei test), così i
 * test admin possono salvare impostazioni e firme senza essere sovrascritti.
 *
 * @package DBCM\Tests\Fixtures
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Firma custom deterministica per il test di cancellazione reattiva: il
 * cookie '_mypix' è requires_consent + reactive_cleanup in 'marketing'.
 *
 * @return array
 */
function dbcm_e2e_baseline_signatures() {
	return array(
		array(
			'slug'             => 'e2e-mypix',
			'service'          => 'E2E Pixel',
			'category'         => 'marketing',
			'requires_consent' => true,
			'reactive_cleanup' => true,
			'cookies'          => array(
				array( 'name' => '_mypix' ),
			),
		),
	);
}

/**
 * Impostazioni baseline: i default del plugin + la localizzazione Google
 * Fonts attiva (la verifica blocking.spec.js).
 *
 * @return array
 */
function dbcm_e2e_baseline_settings() {
	return array(
		'localize_google_fonts' => true,
	);
}

/**
 * Riporta il plugin a uno stato noto.
 *
 * $args (tutti opzionali):
 *  - settings   array  Chiavi che sovrascrivono la baseline.
 *  - signatures array  Firme custom (default: baseline).
 *  - rate_limit int    Limite dbcm_set_consent per IP (default 0 = disattivo:
 *                      tutti i test arrivano dallo stesso IP).
 *  - country    string Paese restituito dal filtro dbcm_visitor_country_code
 *                      (simula una geolocalizzazione GeoIP; default nessuno).
 *  - seed_log   array  Righe da inserire nel registro consensi:
 *                      [{type, consent:{cat:bool}, count, days_ago}].
 *
 * @param array $args
 * @return array Stato risultante (settings + conteggio log).
 */
function dbcm_e2e_reset_state( $args = array() ) {
	global $wpdb;

	$args = is_array( $args ) ? $args : array();

	// Impostazioni: default + baseline + override del test.
	$settings = array_merge(
		DBCM_Settings::defaults(),
		dbcm_e2e_baseline_settings(),
		isset( $args['settings'] ) && is_array( $args['settings'] ) ? $args['settings'] : array()
	);
	update_option( DBCM_Settings::OPTION_KEY, $settings );

	// Firme custom.
	$signatures = isset( $args['signatures'] ) && is_array( $args['signatures'] )
		? $args['signatures']
		: dbcm_e2e_baseline_signatures();
	DBCM_Signatures::save_custom( $signatures );

	// Filtri runtime persistiti per le richieste successive.
	update_option(
		'dbcm_e2e_filters',
		array(
			'rate_limit' => isset( $args['rate_limit'] ) ? (int) $args['rate_limit'] : 0,
			'country'    => isset( $args['country'] ) ? (string) $args['country'] : '',
		)
	);

	// Registro consensi e risultati scanner.
	// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
	$wpdb->query( 'TRUNCATE TABLE ' . DBCM_Consent_Log::table_name() );
	$wpdb->query( 'TRUNCATE TABLE ' . DBCM_Scanner::table_name() );
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE '\\_transient\\_dbcm\\_rl\\_%'
		    OR option_name LIKE '\\_transient\\_timeout\\_dbcm\\_rl\\_%'"
	);
	// phpcs:enable

	foreach ( array( DBCM_Declared_Services::OPTION, 'dbcm_last_scan', 'dbcm_scan_previous', 'dbcm_google_fonts_detected', 'dbcm_external_services_detected' ) as $option ) {
		delete_option( $option );
	}
	DBCM_Declared_Services::reset_request_cache();

	// WooCommerce può rimettere il negozio in "Coming soon" al primo accesso
	// admin (onboarding): i test del carrello devono vedere il negozio aperto.
	if ( class_exists( 'WooCommerce' ) ) {
		update_option( 'woocommerce_coming_soon', 'no' );
	}

	// Seed del registro consensi (paginazione, filtri, export).
	if ( ! empty( $args['seed_log'] ) && is_array( $args['seed_log'] ) ) {
		foreach ( $args['seed_log'] as $seed ) {
			$type     = isset( $seed['type'] ) ? (string) $seed['type'] : 'custom';
			$consent  = isset( $seed['consent'] ) && is_array( $seed['consent'] ) ? $seed['consent'] : array();
			// Come nel flusso reale: ajax_set_consent() forza functional.
			$consent['functional'] = true;
			$count    = isset( $seed['count'] ) ? max( 1, (int) $seed['count'] ) : 1;
			$days_ago = isset( $seed['days_ago'] ) ? max( 0, (int) $seed['days_ago'] ) : 0;
			for ( $i = 0; $i < $count; $i++ ) {
				$id = DBCM_Consent_Log::insert( $type, $consent );
				if ( $id && $days_ago > 0 ) {
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
					$wpdb->query( $wpdb->prepare( 'UPDATE ' . DBCM_Consent_Log::table_name() . ' SET consent_date = DATE_SUB(consent_date, INTERVAL %d DAY) WHERE id = %d', $days_ago, $id ) );
				}
			}
		}
	}

	return array(
		'settings' => DBCM_Settings::all(),
		'log'      => DBCM_Consent_Log::count(),
	);
}

/**
 * Stato corrente per le asserzioni lato server: impostazioni, numero di
 * righe del registro consensi e ultima riga (tipo + consenso decodificato).
 *
 * @return array
 */
function dbcm_e2e_get_state() {
	$last = null;
	$rows = DBCM_Consent_Log::get_results(
		array(
			'page'     => 1,
			'per_page' => 1,
			'order'    => 'DESC',
		)
	);
	if ( ! empty( $rows ) ) {
		$last = array(
			'type'            => $rows[0]->consent_type,
			'consent'         => json_decode( $rows[0]->consent_data, true ),
			'consent_version' => (int) $rows[0]->consent_version,
		);
	}
	return array(
		'settings' => DBCM_Settings::all(),
		'log'      => DBCM_Consent_Log::count(),
		'last_log' => $last,
	);
}

/**
 * Endpoint REST di reset. Senza autenticazione di proposito: il mu-plugin è
 * montato solo da .wp-env.json e non esiste nel pacchetto distribuito.
 * Usare ?rest_route= nei test, così non dipende dai permalink.
 */
add_action( 'rest_api_init', function () {
	register_rest_route(
		'dbcm-e2e/v1',
		'/reset',
		array(
			'methods'             => 'POST',
			'permission_callback' => '__return_true',
			'callback'            => function ( WP_REST_Request $request ) {
				return rest_ensure_response( dbcm_e2e_reset_state( (array) $request->get_json_params() ) );
			},
		)
	);
	register_rest_route(
		'dbcm-e2e/v1',
		'/state',
		array(
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => function () {
				return rest_ensure_response( dbcm_e2e_get_state() );
			},
		)
	);
} );

/**
 * Filtri runtime impostati dal reset.
 */
add_filter( 'dbcm_consent_rate_limit', function ( $limit ) {
	$filters = get_option( 'dbcm_e2e_filters', array() );
	return isset( $filters['rate_limit'] ) ? (int) $filters['rate_limit'] : $limit;
} );

add_filter( 'dbcm_visitor_country_code', function ( $country ) {
	$filters = get_option( 'dbcm_e2e_filters', array() );
	return ! empty( $filters['country'] ) ? (string) $filters['country'] : $country;
} );

add_filter( 'query_vars', function ( $vars ) {
	$vars[] = 'dbcm_e2e';
	return $vars;
} );

/**
 * Pagina 'wp': GA4 fittizio caricato via wp_enqueue_script, così il test
 * copre anche il meccanismo 1 del blocker (script_loader_tag).
 */
add_action( 'wp_enqueue_scripts', function () {
	if ( 'wp' !== (string) get_query_var( 'dbcm_e2e' ) ) {
		return;
	}
	// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
	wp_enqueue_script( 'dbcm-e2e-gtag', 'https://www.googletagmanager.com/gtag/js?id=G-TEST0000000', array(), null, false );
} );

/**
 * Contenuto comune alle due pagine: embed YouTube/Maps, link WhatsApp.
 *
 * @return void
 */
function dbcm_e2e_print_body() {
	echo '<h2>Video</h2>' . "\n";
	echo '<iframe id="fixture-youtube" width="560" height="315" src="https://www.youtube.com/embed/dQw4w9WgXcQ" title="YouTube" frameborder="0" allowfullscreen></iframe>' . "\n";

	echo '<h2>Mappa</h2>' . "\n";
	echo '<iframe id="fixture-maps" width="600" height="450" src="https://www.google.com/maps/embed?pb=fake" style="border:0;" loading="lazy"></iframe>' . "\n";

	echo '<h2>Contatti</h2>' . "\n";
	echo '<a id="fixture-whatsapp" href="https://wa.me/393331234567">Scrivici su WhatsApp</a>' . "\n";

	// Embed di un servizio sconosciuto alle firme di serie, solo su richiesta
	// (&dbcm_widget=1): lo blocca una firma creata dall'admin nei test.
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['dbcm_widget'] ) ) {
		echo '<iframe id="fixture-widget" width="300" height="200" src="https://widget.example.test/embed/1" title="Widget"></iframe>' . "\n";
	}
}

/**
 * Snippet GA4 inline fittizio (measurement ID finto, nessun dato reale).
 *
 * @return void
 */
function dbcm_e2e_print_gtag_inline() {
	echo "<script>\nwindow.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','G-TEST0000000');\n</script>\n";
}

/**
 * Rende la pagina richiesta e termina. Gira dopo il blocker DBCM, che ha già
 * avviato ob_start() su template_redirect priorità 1.
 */
add_action( 'template_redirect', function () {
	$mode = (string) get_query_var( 'dbcm_e2e' );
	if ( '1' !== $mode && 'wp' !== $mode ) {
		return;
	}

	status_header( 200 );
	nocache_headers();

	if ( 'wp' === $mode ) {
		echo "<!DOCTYPE html>\n<html><head>\n";
		echo '<meta charset="utf-8"><title>DBCM E2E (wp)</title>' . "\n";
		wp_head();
		dbcm_e2e_print_gtag_inline();
		echo "</head><body>\n";
		wp_body_open();
		dbcm_e2e_print_body();
		echo '<p>' . do_shortcode( '[dbcm_preferences id="fixture-prefs"]' ) . '</p>' . "\n";
		wp_footer();
		echo "</body></html>";
		exit;
	}

	echo "<!DOCTYPE html>\n<html><head>\n";
	echo '<meta charset="utf-8"><title>DBCM E2E</title>' . "\n";
	echo '<script async src="https://www.googletagmanager.com/gtag/js?id=G-TEST0000000"></script>' . "\n";
	// Google Fonts remoto: con localize_google_fonts attivo, questo <link>
	// deve essere rimosso dall'HTML servito (nessuna richiesta a Google).
	echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
	echo '<link id="fixture-gfont" rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto&display=swap">' . "\n";
	dbcm_e2e_print_gtag_inline();
	echo "</head><body>\n";

	dbcm_e2e_print_body();

	echo '<div id="dbcm-banner-root"></div>' . "\n";

	// Senza wp_head/wp_footer banner.js non viene accodato: lo emettiamo a
	// mano con una config minima (banner chiuso, nessun pulsante "Riapri")
	// per testare la logica client (riattivazione, click-to-load,
	// cancellazione reattiva) isolata dal rendering del banner.
	$cfg = array(
		'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
		'nonce'              => wp_create_nonce( 'dbcm_consent_nonce' ),
		'cookieName'         => DBCM_Settings::COOKIE_NAME,
		'cookieSchema'       => DBCM_Settings::COOKIE_SCHEMA_VERSION,
		'consentVersion'     => DBCM_Settings::consent_version(),
		'reactiveCleanup'    => DBCM_Signatures::reactive_cleanup_list(),
		'categories'         => DBCM_Settings::categories(),
		'categoriesOptional' => DBCM_Settings::categories_optional(),
		'translations'       => array(),
		'activeLangs'        => array( 'it' ),
		'defaultLang'        => 'it',
		'autoOpen'           => false,
		'showReopenBtn'      => false,
		'respectGpc'         => false,
		'respectDnt'         => false,
	);
	echo '<script>window.dbcmBanner=' . wp_json_encode( $cfg ) . ';</script>' . "\n";
	echo '<script src="' . esc_url( DBCM_URL . 'assets/js/banner.js' ) . '?ver=' . rawurlencode( DBCM_VERSION ) . '"></script>' . "\n";

	echo "</body></html>";
	exit;
}, 20 );
