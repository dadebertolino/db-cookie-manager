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
 * rimuoverlo al load. Iniettata via option così reactive_cleanup_list()
 * la include senza toccare le firme statiche.
 */
add_filter( 'option_dbcm_custom_signatures', function ( $value ) {
	$value = is_array( $value ) ? $value : array();
	$value['e2e-mypix'] = array(
		'service'          => 'E2E Pixel',
		'category'         => 'marketing',
		'requires_consent' => true,
		'reactive_cleanup' => true,
		'cookies'          => array(
			array( 'name' => '_mypix' ),
		),
	);
	return $value;
} );


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
	echo "<script>\nwindow.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','G-TEST0000000');\n</script>\n";
	echo "</head><body>\n";

	echo '<h2>Video</h2>' . "\n";
	echo '<iframe id="fixture-youtube" width="560" height="315" src="https://www.youtube.com/embed/dQw4w9WgXcQ" title="YouTube" frameborder="0" allowfullscreen></iframe>' . "\n";

	echo '<h2>Mappa</h2>' . "\n";
	echo '<iframe id="fixture-maps" width="600" height="450" src="https://www.google.com/maps/embed?pb=fake" style="border:0;" loading="lazy"></iframe>' . "\n";

	echo '<h2>Contatti</h2>' . "\n";
	echo '<a id="fixture-whatsapp" href="https://wa.me/393331234567">Scrivici su WhatsApp</a>' . "\n";

	echo "</body></html>";
	exit;
}, 20 );