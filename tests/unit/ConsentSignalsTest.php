<?php
/**
 * Test unit di DBCM_Consent_Signals (Google Consent Mode v2).
 *
 * Verifica: opt-in (default OFF), mapping categoria→segnale, personalizzazione
 * via filtro, e che il default resti denied-by-default.
 *
 * @package DBCM\Tests
 */

use PHPUnit\Framework\TestCase;

final class ConsentSignalsTest extends TestCase {

	protected function setUp(): void {
		dbcm_test_reset();
	}

	/* ---- Opt-in ---- */

	public function test_gcm_disabled_by_default(): void {
		$this->assertFalse( DBCM_Consent_Signals::is_enabled(), 'GCM deve essere OFF di default (opt-in).' );
	}

	public function test_gcm_enabled_when_setting_on(): void {
		update_option( 'dbcm_settings', array( 'gcm_enabled' => true ) );
		$this->assertTrue( DBCM_Consent_Signals::is_enabled() );
	}

	/* ---- Mapping ---- */

	public function test_default_mapping_statistics_to_analytics(): void {
		$map = DBCM_Consent_Signals::mapping();
		$this->assertArrayHasKey( 'statistics', $map );
		$this->assertContains( 'analytics_storage', $map['statistics'] );
	}

	public function test_default_mapping_marketing_to_ad_signals(): void {
		$map = DBCM_Consent_Signals::mapping();
		$this->assertArrayHasKey( 'marketing', $map );
		$this->assertContains( 'ad_storage', $map['marketing'] );
		$this->assertContains( 'ad_user_data', $map['marketing'] );
		$this->assertContains( 'ad_personalization', $map['marketing'] );
	}

	public function test_mapping_is_filterable(): void {
		add_filter( 'dbcm_gcm_mapping', function ( $map ) {
			$map['preferences'] = array( 'functionality_storage' );
			return $map;
		} );
		$map = DBCM_Consent_Signals::mapping();
		$this->assertArrayHasKey( 'preferences', $map );
		$this->assertContains( 'functionality_storage', $map['preferences'] );
	}

	/* ---- Segnali GCM v2 ---- */

	public function test_v2_signals_present(): void {
		$signals = DBCM_Consent_Signals::SIGNALS;
		// I due segnali aggiunti dalla v2 devono esserci.
		$this->assertContains( 'ad_user_data', $signals );
		$this->assertContains( 'ad_personalization', $signals );
		$this->assertContains( 'analytics_storage', $signals );
		$this->assertContains( 'ad_storage', $signals );
	}

	/* ---- Default snippet: denied-by-default ---- */

	public function test_default_snippet_denies_all_signals(): void {
		update_option( 'dbcm_settings', array( 'gcm_enabled' => true ) );

		ob_start();
		DBCM_Consent_Signals::print_default_snippet();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'consent', $html );
		$this->assertStringContainsString( 'default', $html );
		// Ogni segnale deve comparire come denied.
		foreach ( DBCM_Consent_Signals::SIGNALS as $signal ) {
			$this->assertMatchesRegularExpression(
				'/"' . preg_quote( $signal, '/' ) . '"\s*:\s*"denied"/',
				$html,
				$signal . ' deve essere denied nel default snippet.'
			);
		}
		// Non deve esserci alcun granted nel default.
		$this->assertStringNotContainsString( 'granted', $html, 'Il default non deve concedere nulla.' );
	}
}