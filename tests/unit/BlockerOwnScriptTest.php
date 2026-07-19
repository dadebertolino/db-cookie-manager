<?php
/**
 * Test unit di DBCM_Blocker — skip degli script interni (data-dbcm-own).
 *
 * Il gate del Meta Pixel contiene le stringhe 'connect.facebook.net' e
 * 'fbevents.js' nel proprio contenuto inline: senza lo skip esplicito, il
 * blocker lo neutralizzerebbe (type="text/plain") impedendo al pixel di
 * partire anche DOPO il consenso — auto-blocco. Questi test fissano il
 * contratto: contenuto identico, con il marcatore passa, senza viene bloccato.
 *
 * @package DBCM\Tests
 */

use PHPUnit\Framework\TestCase;

final class BlockerOwnScriptTest extends TestCase {

	protected function setUp(): void {
		dbcm_test_reset();
		dbcm_test_reset_consent();
		// Nessun cookie di consenso → marketing NON concesso.
	}

	protected function tearDown(): void {
		dbcm_test_reset();
		dbcm_test_reset_consent();
	}

	/**
	 * Pagina minimale con uno script inline che contiene i pattern Meta.
	 *
	 * @param string $attrs Attributi extra del tag script.
	 * @return string
	 */
	private function page_with_inline_script( $attrs = '' ) {
		return '<html><head><script' . $attrs . '>
			(function(){ var u = "https://connect.facebook.net/en_US/fbevents.js"; })();
		</script></head><body></body></html>';
	}

	public function test_inline_script_with_meta_patterns_is_blocked_without_consent(): void {
		$out = DBCM_Blocker::process_buffer( $this->page_with_inline_script() );
		$this->assertStringContainsString( 'type="text/plain"', $out, 'Controllo: senza marcatore, il contenuto inline con pattern Meta viene neutralizzato.' );
		$this->assertStringContainsString( 'data-dbcm-blocked="true"', $out );
	}

	public function test_own_marked_script_is_never_rewritten(): void {
		$html = $this->page_with_inline_script( ' id="dbcm-meta-pixel" data-dbcm-own="1"' );
		$out  = DBCM_Blocker::process_buffer( $html );
		$this->assertSame( $html, $out, 'Il gate interno (data-dbcm-own) non deve mai essere riscritto: è già gated by-design.' );
	}

	public function test_actual_meta_pixel_snippet_passes_the_blocker(): void {
		// Non un fac-simile: lo snippet REALE emesso dal modulo.
		update_option( 'dbcm_settings', array( 'meta_pixel_enabled' => true, 'meta_pixel_id' => '123456789012345' ) );
		ob_start();
		DBCM_Meta_Pixel::print_snippet();
		$snippet = (string) ob_get_clean();

		$page = '<html><head>' . $snippet . '</head><body></body></html>';
		$out  = DBCM_Blocker::process_buffer( $page );

		$this->assertStringNotContainsString( 'data-dbcm-blocked', $out, 'Lo snippet reale del modulo non deve essere marcato come bloccato.' );
		$this->assertStringContainsString( 'data-dbcm-own="1"', $out );
		// Il tag non deve essere stato degradato a text/plain.
		$this->assertStringNotContainsString( '<script type="text/plain" id="dbcm-meta-pixel"', $out );
	}

	public function test_third_party_pixel_still_blocked_alongside_own_gate(): void {
		// Scenario "Meta for WooCommerce" attivo insieme al modulo nativo:
		// il pixel di terzi resta gated dal blocker, il nostro passa.
		update_option( 'dbcm_settings', array( 'meta_pixel_enabled' => true, 'meta_pixel_id' => '123456789012345' ) );
		ob_start();
		DBCM_Meta_Pixel::print_snippet();
		$snippet = (string) ob_get_clean();

		$third = '<script src="https://connect.facebook.net/en_US/fbevents.js"></script>';
		$page  = '<html><head>' . $snippet . $third . '</head><body></body></html>';
		$out   = DBCM_Blocker::process_buffer( $page );

		$this->assertStringContainsString( 'data-dbcm-blocked="true"', $out, 'Lo script di terzi deve restare bloccato.' );
		$this->assertStringNotContainsString( '<script type="text/plain" id="dbcm-meta-pixel"', $out, 'Il gate interno deve restare intatto.' );
	}
}
