<?php
/**
 * Test unit di DBCM_Signatures.
 *
 * Porta in forma versionata i controlli eseguiti a mano durante lo sviluppo
 * dello Step 1 (db firme): merge statiche+custom, viste blocker/scanner,
 * classificazione wildcard, degradazione sicura delle regex, cancellazione
 * reattiva che esclude i cookie tecnici.
 *
 * @package DBCM\Tests
 */

use PHPUnit\Framework\TestCase;

final class SignaturesTest extends TestCase {

	protected function setUp(): void {
		dbcm_test_reset();
	}

	/* ---- Sorgente statica ---- */

	public function test_static_signatures_load(): void {
		$all = DBCM_Signatures::all();
		$this->assertGreaterThanOrEqual( 40, count( $all ), 'Attesi almeno 40 servizi nel db firme statico.' );
		$this->assertArrayHasKey( 'google-analytics', $all );
		$this->assertArrayHasKey( 'woocommerce', $all );
		$this->assertArrayHasKey( 'whatsapp-click', $all );
	}

	/* ---- Vista blocker ---- */

	public function test_blocker_view_excludes_non_blockable(): void {
		DBCM_Signatures::init();
		$patterns = apply_filters( 'dbcm_blocker_patterns', array() );

		$sigs = array_column( $patterns, '_dbcm_sig' );
		$this->assertNotContains( 'paypal', $sigs, 'PayPal (antifrode) NON deve essere bloccabile di default.' );
		$this->assertNotContains( 'stripe', $sigs, 'Stripe (pagamento) NON deve essere bloccabile.' );
		$this->assertNotContains( 'google-fonts', $sigs, 'Google Fonts si localizza, non si blocca.' );
	}

	public function test_blocker_view_excludes_report_only(): void {
		DBCM_Signatures::init();
		$patterns = apply_filters( 'dbcm_blocker_patterns', array() );
		$sigs     = array_column( $patterns, '_dbcm_sig' );

		$this->assertNotContains( 'whatsapp-click', $sigs, 'WhatsApp click-to-chat non va mai bloccato.' );
		$this->assertNotContains( 'telegram-click', $sigs );
	}

	public function test_blocker_view_includes_trackers(): void {
		DBCM_Signatures::init();
		$patterns = apply_filters( 'dbcm_blocker_patterns', array() );
		$sigs     = array_column( $patterns, '_dbcm_sig' );

		$this->assertContains( 'google-analytics', $sigs, 'GA4 deve essere bloccabile.' );
		$this->assertContains( 'meta-pixel', $sigs );
	}

	public function test_youtube_iframe_pattern_present(): void {
		DBCM_Signatures::init();
		$patterns = apply_filters( 'dbcm_blocker_patterns', array() );

		$found = false;
		foreach ( $patterns as $g ) {
			if ( ( $g['_dbcm_sig'] ?? '' ) === 'youtube' && ( $g['type'] ?? '' ) === 'iframe' ) {
				$found = true;
			}
		}
		$this->assertTrue( $found, 'YouTube deve avere un gruppo pattern di tipo iframe.' );
	}

	/* ---- Vista scanner ---- */

	public function test_scanner_view_resolves_self_domains(): void {
		DBCM_Signatures::init();
		$det = apply_filters( 'dbcm_scanner_html_detections', array(), 'gatdus.example' );

		$ga_dotted = false;   // _ga → .gatdus.example
		$wc_plain  = false;   // woocommerce_cart_hash → gatdus.example
		foreach ( $det as $e ) {
			foreach ( $e['cookies'] as $c ) {
				if ( '_ga' === $c['name'] && '.gatdus.example' === $c['domain'] ) {
					$ga_dotted = true;
				}
				if ( 'woocommerce_cart_hash' === $c['name'] && 'gatdus.example' === $c['domain'] ) {
					$wc_plain = true;
				}
			}
		}
		$this->assertTrue( $ga_dotted, '@self. deve risolvere a .dominio.' );
		$this->assertTrue( $wc_plain, '@self deve risolvere a dominio.' );
	}

	/* ---- Classificazione cookie con wildcard ---- */

	public function test_classify_cookie_wildcard(): void {
		$ga = DBCM_Signatures::classify_cookie( '_ga_ABC123XYZ' );
		$this->assertNotNull( $ga );
		$this->assertSame( 'statistics', $ga['category'] );
		$this->assertSame( 'Google Analytics 4', $ga['service'] );
	}

	public function test_classify_technical_cookie_no_consent(): void {
		$wc = DBCM_Signatures::classify_cookie( 'wp_woocommerce_session_9f8e7d6c' );
		$this->assertNotNull( $wc );
		$this->assertFalse( $wc['requires_consent'], 'I cookie di sessione WooCommerce sono tecnici.' );
	}

	public function test_classify_unknown_cookie_returns_null(): void {
		$this->assertNull(
			DBCM_Signatures::classify_cookie( 'cookie_sconosciuto_xyz' ),
			'Un cookie non in firma resta "Da classificare".'
		);
	}

	/* ---- Firme custom per-sito ---- */

	public function test_custom_signature_enters_blocker(): void {
		update_option( 'dbcm_custom_signatures', array(
			array(
				'service'          => 'Mio Pixel',
				'category'         => 'marketing',
				'requires_consent' => true,
				'block_source'     => 'mio-cdn.example.com/pixel.js',
				'cookies'          => array( array( 'name' => '_mypix' ) ),
			),
		) );
		DBCM_Signatures::flush_cache();
		DBCM_Signatures::init();

		$patterns = apply_filters( 'dbcm_blocker_patterns', array() );
		$found    = false;
		foreach ( $patterns as $g ) {
			if ( strpos( (string) ( $g['_dbcm_sig'] ?? '' ), 'custom-mio-pixel' ) === 0 ) {
				$found = true;
			}
		}
		$this->assertTrue( $found, 'La firma custom deve entrare nella vista blocker.' );
	}

	public function test_malformed_custom_regex_is_degraded(): void {
		update_option( 'dbcm_custom_signatures', array(
			array(
				'service'        => 'Regex Rotta',
				'category'       => 'statistics',
				'block_source'   => '/[invalid(regex/',
				'block_is_regex' => true,
				'cookies'        => array(),
			),
		) );
		DBCM_Signatures::flush_cache();

		$norm = DBCM_Signatures::get_custom_normalized();
		$row  = null;
		foreach ( $norm as $r ) {
			if ( 'Regex Rotta' === $r['service'] ) {
				$row = $r;
			}
		}
		$this->assertNotNull( $row );
		$this->assertSame( '', $row['_regex_source'], 'Una regex malformata va degradata, non usata come regex.' );
	}

	/* ---- Cancellazione reattiva ---- */

	public function test_reactive_cleanup_excludes_technical(): void {
		$list  = DBCM_Signatures::reactive_cleanup_list();
		$names = array_column( $list, 'name' );
		$this->assertNotContains(
			'woocommerce_cart_hash',
			$names,
			'I cookie tecnici non vanno mai nella lista di cancellazione.'
		);
	}

	public function test_reactive_cleanup_includes_custom_optin(): void {
		update_option( 'dbcm_custom_signatures', array(
			array(
				'service'          => 'Mio Pixel',
				'category'         => 'marketing',
				'requires_consent' => true,
				'block_source'     => 'mio-cdn.example.com/pixel.js',
				'cookies'          => array( array( 'name' => '_mypix' ) ),
				'reactive_cleanup' => true,
			),
		) );
		DBCM_Signatures::flush_cache();

		$list  = DBCM_Signatures::reactive_cleanup_list();
		$names = array_column( $list, 'name' );
		$this->assertContains( '_mypix', $names );
	}

	/* ---- Sicurezza regex ---- */

	public function test_is_valid_regex(): void {
		$this->assertTrue( DBCM_Signatures::is_valid_regex( '/abc/' ) );
		$this->assertFalse( DBCM_Signatures::is_valid_regex( '/[/' ) );
		$this->assertFalse( DBCM_Signatures::is_valid_regex( '' ) );
	}

	/* ---- Matching nomi cookie ---- */

	public function test_cookie_name_matches(): void {
		$this->assertTrue( DBCM_Signatures::cookie_name_matches( '_ga_*', '_ga_ABC' ) );
		$this->assertTrue( DBCM_Signatures::cookie_name_matches( 'store_notice*', 'store_notice_x' ) );
		$this->assertTrue( DBCM_Signatures::cookie_name_matches( '_fbp', '_fbp' ) );
		$this->assertFalse( DBCM_Signatures::cookie_name_matches( '_ga_*', '_gid' ) );
		$this->assertFalse( DBCM_Signatures::cookie_name_matches( '', 'anything' ) );
	}

	/* ---- Round-trip UI aggiunta manuale (Step: UI firme) ---- */

	/**
	 * I campi prodotti dal form admin (handle_save_signatures) sopravvivono a
	 * save_custom -> get_custom_normalized con i valori attesi.
	 */
	public function test_manual_signature_roundtrip(): void {
		$row = array(
			'service'          => 'Il Mio Pixel',
			'provider'         => 'Esempio S.r.l.',
			'category'         => 'marketing',
			'requires_consent' => true,
			'block_source'     => 'esempio-cdn.com/pixel.js',
			'block_is_regex'   => false,
			'reactive_cleanup' => true,
			'cookies'          => array( array( 'name' => '_mypix' ), array( 'name' => '_mypix_sess' ) ),
		);
		DBCM_Signatures::save_custom( array( $row ) );

		$norm = DBCM_Signatures::get_custom_normalized();
		$this->assertCount( 1, $norm );
		$sig = array_values( $norm )[0];

		$this->assertSame( 'Il Mio Pixel', $sig['service'] );
		$this->assertSame( 'marketing', $sig['category'] );
		$this->assertTrue( $sig['requires_consent'] );
		$this->assertTrue( $sig['reactive_cleanup'] );
		$this->assertContains( 'esempio-cdn.com/pixel.js', $sig['script_patterns'] );
		$names = array_column( $sig['cookies'], 'name' );
		$this->assertContains( '_mypix', $names );
		$this->assertContains( '_mypix_sess', $names );
	}

	/**
	 * La forma di import { "signatures": [...] } passa per save_custom e viene
	 * sanificata come l'aggiunta manuale (nessun campo grezzo persiste).
	 */
	public function test_import_shape_is_sanitized(): void {
		$imported = array(
			array(
				'service'          => 'Servizio Importato',
				'category'         => 'statistics',
				'requires_consent' => true,
				'reactive_cleanup' => true,
				'cookies'          => array( array( 'name' => '_imp' ) ),
				// Campo estraneo che NON deve sopravvivere alla normalizzazione.
				'evil'             => '<script>alert(1)</script>',
			),
		);
		DBCM_Signatures::save_custom( array_values( $imported ) );

		$norm = DBCM_Signatures::get_custom_normalized();
		$this->assertCount( 1, $norm );
		$sig = array_values( $norm )[0];
		$this->assertSame( 'Servizio Importato', $sig['service'] );
		$this->assertSame( 'statistics', $sig['category'] );
		$this->assertArrayNotHasKey( 'evil', $sig, 'I campi estranei non devono sopravvivere alla normalizzazione.' );
	}
}