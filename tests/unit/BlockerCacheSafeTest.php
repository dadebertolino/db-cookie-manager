<?php
/**
 * Test unit di DBCM_Blocker — HTML indipendente dal consenso (3.8.0).
 *
 * Con una cache di pagina, l'HTML generato per un visitatore che ha già
 * accettato veniva servito a tutti con i tracker attivi. Per i visitatori
 * anonimi il blocker deve quindi neutralizzare SEMPRE gli script/iframe
 * riconosciuti, anche se il cookie di consenso concede la categoria: la
 * riattivazione avviene lato client (banner.js).
 *
 * @package DBCM\Tests
 */

use PHPUnit\Framework\TestCase;

final class BlockerCacheSafeTest extends TestCase {

	protected function setUp(): void {
		dbcm_test_reset();
		dbcm_test_reset_consent();
	}

	protected function tearDown(): void {
		dbcm_test_reset();
		dbcm_test_reset_consent();
	}

	public function test_anonymous_with_consent_still_gets_neutralized_script(): void {
		dbcm_test_set_consent_cookie( array( 'marketing' => true, 'statistics' => true ) );
		$this->assertTrue( DBCM_Consent_API::has_consent( 'marketing' ), 'Controllo: il cookie concede marketing.' );

		$page = '<html><head><script src="https://connect.facebook.net/en_US/fbevents.js"></script></head><body></body></html>';
		$out  = DBCM_Blocker::process_buffer( $page );

		$this->assertStringContainsString( 'type="text/plain"', $out, 'Anonimo con consenso: lo script resta neutralizzato (HTML cache-safe).' );
		$this->assertStringContainsString( 'data-dbcm-category="marketing"', $out );
	}

	public function test_anonymous_with_consent_still_gets_iframe_placeholder(): void {
		dbcm_test_set_consent_cookie( array( 'marketing' => true ) );

		$page = '<p><iframe src="https://www.youtube.com/embed/abc" title="Video" width="560" height="315"></iframe></p>';
		$out  = DBCM_Blocker::process_buffer( $page );

		$this->assertStringContainsString( 'dbcm-iframe-placeholder', $out );
		$this->assertStringNotContainsString( '<iframe', $out, 'L\'iframe reale non deve comparire nell\'HTML servito agli anonimi.' );
		$this->assertStringContainsString( 'data-dbcm-attrs=', $out, 'Gli attributi originali restano disponibili per il ripristino client-side.' );
	}

	public function test_module_type_is_preserved_for_reactivation(): void {
		$page = '<script type="module" src="https://cdn.amplitude.com/lib.js"></script>';
		$out  = DBCM_Blocker::process_buffer( $page );

		$this->assertStringContainsString( 'type="text/plain"', $out );
		$this->assertStringContainsString( 'data-dbcm-type="module"', $out, 'banner.js deve poter ripristinare type="module".' );
	}

	public function test_classic_script_gets_no_type_marker(): void {
		$page = '<script type="text/javascript" src="https://static.hotjar.com/c/hotjar.js"></script>';
		$out  = DBCM_Blocker::process_buffer( $page );

		$this->assertStringContainsString( 'data-dbcm-blocked="true"', $out );
		$this->assertStringNotContainsString( 'data-dbcm-type', $out );
	}
}
