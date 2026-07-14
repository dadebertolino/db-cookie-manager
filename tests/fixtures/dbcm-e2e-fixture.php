<?php
/**
 * Plugin Name: DBCM E2E Fixture
 * Description: Costruisce condizioni di test deterministiche per gli E2E di
 *              DB Cookie Manager. Attivo SOLO in ambiente wp-env (mu-plugin).
 *              NON fa parte del pacchetto distribuito.
 *
 * Strategia: invece di creare una pagina WP (il cui contenuto passa da
 * the_content/wpautop/wp_kses, che RIMUOVONO gli iframe e alterano il markup),
 * la fixture intercetta l'URL /dbcm-test/ e stampa HTML GREZZO. Così iframe,
 * link e snippet GA4 arrivano al browser esattamente come scritti, e il blocker
 * di DBCM opera sull'output buffer come farebbe su un tema reale.
 *
 * @package DBCM\Tests\Fixtures
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registra la query var e la rewrite per /dbcm-test/.
 */
add_action( 'init', function () {
	add_rewrite_rule( '^dbcm-test/?$', 'index.php?dbcm_e2e=1', 'top' );
} );

add_filter( 'query_vars', function ( $vars ) {
	$vars[] = 'dbcm_e2e';
	return $vars;
} );

/**
 * Firma custom deterministica per il test di cancellazione reattiva:
 * il cookie '_mypix' è marcato requires_consent + reactive_cleanup in
 * categoria 'marketing'. Senza consenso 'marketing', banner.js deve
 * rimuoverlo al load.
 *
 * NB: NON usiamo il filtro 'option_dbcm_custom_signatures' perché
 * DBCM_Signatures::all() ha un cache statico che può popolarsi (durante il
 * boot di blocker/scanner) PRIMA che la pagina fixture giri, cristallizzando
 * la lista senza la nostra firma. Scriviamo invece l'option reale nel DB a
 * 'init' prio 1 e invalidiamo il cache, così qualunque lettura successiva la
 * include.
 */
add_action( 'init', function () {
	$sigs = array(
		'e2e-mypix' => array(
			'service'          => 'E2E Pixel',
			'category'         => 'marketing',
			'requires_consent' => true,
			'reactive_cleanup' => true,
			'cookies'          => array(
				array( 'name' => '_mypix' ),
			),
		),
	);
	update_option( 'dbcm_custom_signatures', $sigs );

	// Invalida il cache statico se esposto, così la lista viene ricalcolata.
	if ( class_exists( 'DBCM_Signatures' ) && method_exists( 'DBCM_Signatures', 'flush_cache' ) ) {
		DBCM_Signatures::flush_cache();
	}

	// Attiva la localizzazione Google Fonts per l'e2e (verifica che i <link>
	// verso fonts.googleapis.com vengano rimossi dall'HTML della pagina test).
	add_filter( 'option_dbcm_settings', function ( $value ) {
		$value = is_array( $value ) ? $value : array();
		$value['localize_google_fonts'] = true;
		return $value;
	} );
}, 1 );

/**
 * Flush delle rewrite una sola volta (all'attivazione del mu-plugin non c'è
 * hook di attivazione, quindi usiamo un flag in option).
 */
add_action( 'init', function () {
	if ( 'done' !== get_option( 'dbcm_e2e_rewrite_flushed' ) ) {
		add_rewrite_rule( '^dbcm-test/?$', 'index.php?dbcm_e2e=1', 'top' );
		flush_rewrite_rules( false );
		update_option( 'dbcm_e2e_rewrite_flushed', 'done' );
	}
}, 99 );

/**
 * Quando la query var è presente, stampa la pagina di test grezza e termina.
 * Passa comunque dall'output buffer del blocker DBCM (che si aggancia su
 * template_redirect priorità 1, prima di questo che gira più tardi).
 */
add_action( 'template_redirect', function () {
	if ( '1' !== (string) get_query_var( 'dbcm_e2e' ) ) {
		return;
	}

	// Header minimo; il blocker DBCM ha già avviato ob_start() a priorità 1.
	status_header( 200 );
	nocache_headers();

	echo "<!DOCTYPE html>\n<html><head>\n";
	echo '<meta charset="utf-8"><title>DBCM E2E</title>' . "\n";
	// GA4 fittizio (measurement ID finto): il blocker deve neutralizzarlo
	// quando manca il consenso 'statistics'. Nessun dato reale trasmesso.
	echo '<script async src="https://www.googletagmanager.com/gtag/js?id=G-TEST0000000"></script>' . "\n";
	// Google Fonts remoto: con localize_google_fonts attivo, questo <link>
	// deve essere rimosso dall'HTML servito (nessuna richiesta a Google).
	echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
	echo '<link id="fixture-gfont" rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Roboto&display=swap">' . "\n";
	echo "<script>\nwindow.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','G-TEST0000000');\n</script>\n";
	echo "</head><body>\n";

	echo '<h2>Video</h2>' . "\n";
	echo '<iframe id="fixture-youtube" width="560" height="315" src="https://www.youtube.com/embed/dQw4w9WgXcQ" title="YouTube" frameborder="0" allowfullscreen></iframe>' . "\n";

	echo '<h2>Mappa</h2>' . "\n";
	echo '<iframe id="fixture-maps" width="600" height="450" src="https://www.google.com/maps/embed?pb=fake" style="border:0;" loading="lazy"></iframe>' . "\n";

	echo '<h2>Contatti</h2>' . "\n";
	echo '<a id="fixture-whatsapp" href="https://wa.me/393331234567">Scrivici su WhatsApp</a>' . "\n";

	echo '<div id="dbcm-banner-root"></div>' . "\n";

	// La pagina fixture serve HTML grezzo con exit e NON passa da wp_head/
	// wp_footer, quindi DBCM_Banner::enqueue_assets() non viene eseguito e
	// banner.js non sarebbe presente. Per testare la cancellazione reattiva
	// (che è logica di banner.js) emettiamo qui manualmente la config e lo
	// script, riusando la lista reale dal backend.
	$reactive = class_exists( 'DBCM_Signatures' ) ? DBCM_Signatures::reactive_cleanup_list() : array();
	$cfg      = array(
		'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
		'nonce'           => wp_create_nonce( 'dbcm_consent_nonce' ),
		'cookieName'      => defined( 'DBCM_Settings::COOKIE_NAME' ) ? DBCM_Settings::COOKIE_NAME : 'dbcm_consent',
		'reactiveCleanup' => $reactive,
		'categories'      => array( 'functional', 'preferences', 'statistics', 'statistics-anonymous', 'marketing' ),
		'categoriesOptional' => array( 'preferences', 'statistics', 'statistics-anonymous', 'marketing' ),
		'defaults'        => array(
			'functional'           => true,
			'preferences'          => false,
			'statistics'           => false,
			'statistics-anonymous' => false,
			'marketing'            => false,
		),
		'translations'    => array(),
		'activeLangs'     => array( 'it' ),
		'defaultLang'     => 'it',
		'autoOpen'        => false,
		'showReopenBtn'   => false,
		'respectGpc'      => false,
		'respectDnt'      => false,
	);
	echo '<script>window.dbcmBanner=' . wp_json_encode( $cfg ) . ';</script>' . "\n";
	echo '<script src="' . esc_url( DBCM_URL . 'assets/js/banner.js' ) . '?ver=' . rawurlencode( DBCM_VERSION ) . '"></script>' . "\n";

	echo "</body></html>";
	exit;
}, 20 );