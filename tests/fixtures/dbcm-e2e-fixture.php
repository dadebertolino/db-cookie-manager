<?php
/**
 * Plugin Name: DBCM E2E Fixture
 * Description: Costruisce condizioni di test deterministiche per gli E2E di
 *              DB Cookie Manager. Attivo SOLO in ambiente wp-env (mu-plugin).
 *              NON fa parte del pacchetto distribuito.
 *
 * Cosa fa:
 *  - Crea (una volta) una pagina "DBCM Test" con: embed YouTube, link WhatsApp
 *    (wa.me), iframe Google Maps. Slug fisso 'dbcm-test' per i selettori E2E.
 *  - Inietta nel <head> del frontend uno snippet GA4 FITTIZIO (measurement ID
 *    finto): serve solo a far scattare il blocker/scanner, non invia dati reali.
 *  - Espone i cookie di consenso via una costante per i test.
 *
 * @package DBCM\Tests\Fixtures
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Contenuto HTML della pagina di test. Gli embed sono hardcoded nel content
 * così passano dall'output buffer del blocker (meccanismo 2), esattamente
 * come farebbero gli embed incollati a mano in un tema "fai da te".
 */
function dbcm_fixture_page_content() {
	return <<<HTML
<h2>Video</h2>
<iframe id="fixture-youtube" width="560" height="315"
	src="https://www.youtube.com/embed/dQw4w9WgXcQ"
	title="YouTube video" frameborder="0"
	allow="accelerometer; autoplay; clipboard-write; encrypted-media"
	allowfullscreen></iframe>

<h2>Mappa</h2>
<iframe id="fixture-maps" width="600" height="450"
	src="https://www.google.com/maps/embed?pb=fake"
	style="border:0;" loading="lazy"></iframe>

<h2>Contatti</h2>
<a id="fixture-whatsapp" href="https://wa.me/393331234567">Scrivici su WhatsApp</a>
HTML;
}

/**
 * Crea la pagina di test all'avvio, se non esiste già.
 */
add_action( 'init', function () {
	if ( get_page_by_path( 'dbcm-test' ) ) {
		return;
	}
	wp_insert_post( array(
		'post_title'   => 'DBCM Test',
		'post_name'    => 'dbcm-test',
		'post_status'  => 'publish',
		'post_type'    => 'page',
		'post_content' => dbcm_fixture_page_content(),
	) );
} );

/**
 * Inietta uno snippet GA4 FITTIZIO nel <head> del frontend.
 *
 * Measurement ID finto (G-TEST0000000): il blocker deve neutralizzarlo quando
 * manca il consenso 'statistics', e il test verifica che NON parta alcuna
 * richiesta verso googletagmanager.com. Nessun dato reale viene trasmesso.
 */
add_action( 'wp_head', function () {
	// Solo sulla pagina di test, per non inquinare il resto del sito.
	if ( ! is_page( 'dbcm-test' ) ) {
		return;
	}
	?>
<!-- DBCM fixture: GA4 fittizio -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-TEST0000000"></script>
<script>
	window.dataLayer = window.dataLayer || [];
	function gtag(){dataLayer.push(arguments);}
	gtag('js', new Date());
	gtag('config', 'G-TEST0000000');
</script>
	<?php
}, 1 );
