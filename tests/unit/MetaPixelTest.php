<?php
/**
 * Test unit di DBCM_Meta_Pixel — Modulo Meta Pixel nativo.
 *
 * Copre i punti di conformità del modulo:
 *  - Privacy by default (Art. 25): OFF di default, inerte senza ID valido.
 *  - Validazione Pixel ID: solo 15–16 cifre, tutto il resto scartato.
 *  - Trasparenza (Art. 13): registrazione nel registro servizi dichiarati,
 *    idratata dalla firma statica, senza duplicati.
 *  - Gating by-design: lo snippet emesso porta data-dbcm-own (skip blocker),
 *    non contiene mai lo script "nudo" e include il gate su 'marketing'.
 *
 * @package DBCM\Tests
 */

use PHPUnit\Framework\TestCase;

final class MetaPixelTest extends TestCase {

	protected function setUp(): void {
		dbcm_test_reset();
	}

	protected function tearDown(): void {
		dbcm_test_reset();
	}

	/* ---- Privacy by default (Art. 25) ---- */

	public function test_disabled_by_default(): void {
		$this->assertFalse( DBCM_Meta_Pixel::is_enabled(), 'Il Meta Pixel deve essere OFF di default (opt-in).' );
		$this->assertFalse( DBCM_Meta_Pixel::is_active() );
	}

	public function test_capi_handoff_disabled_by_default(): void {
		$this->assertFalse( DBCM_Meta_Pixel::capi_handoff_enabled() );
	}

	public function test_not_active_without_valid_id(): void {
		update_option( 'dbcm_settings', array( 'meta_pixel_enabled' => true, 'meta_pixel_id' => '' ) );
		$this->assertTrue( DBCM_Meta_Pixel::is_enabled() );
		$this->assertFalse( DBCM_Meta_Pixel::is_active(), 'Toggle ON ma ID vuoto → modulo inerte.' );
	}

	public function test_not_active_without_toggle(): void {
		update_option( 'dbcm_settings', array( 'meta_pixel_enabled' => false, 'meta_pixel_id' => '123456789012345' ) );
		$this->assertFalse( DBCM_Meta_Pixel::is_active(), 'ID valido ma toggle OFF → modulo inerte.' );
	}

	public function test_active_with_toggle_and_valid_id(): void {
		update_option( 'dbcm_settings', array( 'meta_pixel_enabled' => true, 'meta_pixel_id' => '123456789012345' ) );
		$this->assertTrue( DBCM_Meta_Pixel::is_active() );
	}

	/* ---- Validazione Pixel ID ---- */

	public function test_sanitize_accepts_15_digits(): void {
		$this->assertSame( '123456789012345', DBCM_Meta_Pixel::sanitize_pixel_id( '123456789012345' ) );
	}

	public function test_sanitize_accepts_16_digits(): void {
		$this->assertSame( '1234567890123456', DBCM_Meta_Pixel::sanitize_pixel_id( '1234567890123456' ) );
	}

	public function test_sanitize_strips_non_digits(): void {
		// Spazi e trattini incollati dall'admin: si accetta se il residuo è valido.
		$this->assertSame( '123456789012345', DBCM_Meta_Pixel::sanitize_pixel_id( ' 123-456 789012345 ' ) );
	}

	public function test_sanitize_rejects_too_short(): void {
		$this->assertSame( '', DBCM_Meta_Pixel::sanitize_pixel_id( '12345678901234' ) ); // 14 cifre.
	}

	public function test_sanitize_rejects_too_long(): void {
		$this->assertSame( '', DBCM_Meta_Pixel::sanitize_pixel_id( '12345678901234567' ) ); // 17 cifre.
	}

	public function test_sanitize_rejects_script_injection(): void {
		$this->assertSame( '', DBCM_Meta_Pixel::sanitize_pixel_id( '<script>alert(1)</script>' ) );
	}

	public function test_sanitize_rejects_non_string(): void {
		$this->assertSame( '', DBCM_Meta_Pixel::sanitize_pixel_id( array( '123456789012345' ) ) );
		$this->assertSame( '', DBCM_Meta_Pixel::sanitize_pixel_id( null ) );
	}

	public function test_get_pixel_id_resanitizes_stored_value(): void {
		// Difesa in profondità: option scritta fuori dal flusso admin.
		update_option( 'dbcm_settings', array( 'meta_pixel_id' => 'evil"payload' ) );
		$this->assertSame( '', DBCM_Meta_Pixel::get_pixel_id() );
	}

	/* ---- Registro servizi dichiarati (Art. 13) ---- */

	public function test_register_declared_service_adds_entry_from_signature(): void {
		$grouped = DBCM_Meta_Pixel::register_declared_service( array() );
		$this->assertArrayHasKey( 'marketing', $grouped );

		$slugs = array_column( $grouped['marketing'], 'slug' );
		$this->assertContains( 'meta-pixel', $slugs );

		$entry = $grouped['marketing'][ array_search( 'meta-pixel', $slugs, true ) ];
		// Idratazione dalla firma statica (fonte unica di verità).
		$this->assertSame( 'Meta Pixel', $entry['service'] );
		$this->assertSame( 'Meta Platforms Ireland Ltd.', $entry['provider'] );
		$this->assertStringContainsString( '_fbp', $entry['cookies_text'] );
		$this->assertStringContainsString( '_fbc', $entry['cookies_text'] );
		$this->assertNotSame( '', $entry['policy_url'] );
	}

	public function test_register_declared_service_deduplicates_by_slug(): void {
		$grouped = array(
			'marketing' => array(
				array( 'slug' => 'meta-pixel', 'service' => 'Meta Pixel (già presente)' ),
			),
		);
		$out = DBCM_Meta_Pixel::register_declared_service( $grouped );
		$this->assertCount( 1, $out['marketing'], 'Voce già presente (manuale o auto) → nessun duplicato.' );
	}

	public function test_register_declared_service_tolerates_non_array(): void {
		$out = DBCM_Meta_Pixel::register_declared_service( 'garbage' );
		$this->assertIsArray( $out );
		$this->assertArrayHasKey( 'marketing', $out );
	}

	/* ---- Snippet gated ---- */

	private function capture_snippet(): string {
		ob_start();
		DBCM_Meta_Pixel::print_snippet();
		return (string) ob_get_clean();
	}

	public function test_snippet_empty_without_valid_id(): void {
		update_option( 'dbcm_settings', array( 'meta_pixel_enabled' => true, 'meta_pixel_id' => 'abc' ) );
		$this->assertSame( '', $this->capture_snippet(), 'Nessun output con ID invalido: nessuna superficie di attacco.' );
	}

	public function test_snippet_contains_own_marker_and_gate(): void {
		update_option( 'dbcm_settings', array( 'meta_pixel_enabled' => true, 'meta_pixel_id' => '123456789012345' ) );
		$html = $this->capture_snippet();

		$this->assertStringContainsString( 'data-dbcm-own="1"', $html, 'Marcatore skip-blocker obbligatorio: senza, il blocker neutralizza il gate.' );
		$this->assertStringContainsString( "'123456789012345'", $html );
		$this->assertStringContainsString( "hasConsent", $html, 'Il gate deve interrogare l\'API pubblica del consenso.' );
		$this->assertStringContainsString( "'marketing'", $html, 'La categoria gated deve essere marketing.' );
		$this->assertStringContainsString( 'dbcm:consent', $html, 'Deve reagire al cambio consenso senza reload.' );
		$this->assertStringContainsString( "'revoke'", $html, 'Art. 7.3: revoca efficace a runtime.' );
		$this->assertStringContainsString( 'CAPI_HANDOFF = false', $html, 'Handoff OFF di default.' );
	}

	public function test_snippet_capi_handoff_flag(): void {
		update_option( 'dbcm_settings', array(
			'meta_pixel_enabled'      => true,
			'meta_pixel_id'           => '123456789012345',
			'meta_pixel_capi_handoff' => true,
		) );
		$html = $this->capture_snippet();
		$this->assertStringContainsString( 'CAPI_HANDOFF = true', $html );
		$this->assertStringContainsString( 'DBCM_META_LAST_EVENT_ID', $html, 'event_id esposto per la deduplicazione CAPI.' );
		$this->assertStringContainsString( 'eventID', $html );
	}

	public function test_snippet_never_ships_naked_pixel(): void {
		update_option( 'dbcm_settings', array( 'meta_pixel_enabled' => true, 'meta_pixel_id' => '123456789012345' ) );
		$html = $this->capture_snippet();
		// Il caricamento di fbevents.js deve stare DENTRO la funzione gated,
		// mai come <script src=...> diretto.
		$this->assertStringNotContainsString( '<script src=', $html );
		$this->assertStringNotContainsString( "src=\"https://connect.facebook.net", $html );
	}
}
