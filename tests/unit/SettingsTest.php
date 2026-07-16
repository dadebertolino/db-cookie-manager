<?php
/**
 * Test unit di DBCM_Settings — la spina dorsale della configurazione.
 *
 * Ogni modulo legge da qui. Un bug nel merge dei default, nel fallback o
 * nell'import/restore si propaga a tutto il plugin. I test bloccano i
 * comportamenti su cui gli altri moduli fanno affidamento.
 *
 * @package DBCM\Tests
 */

use PHPUnit\Framework\TestCase;

final class SettingsTest extends TestCase {

	protected function setUp(): void {
		// Azzera l'option settings simulata fra i test.
		$GLOBALS['__dbcm_options'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['__dbcm_options'] = array();
	}

	/* =====================================================================
	 * all() — merge default + salvati
	 * ================================================================== */

	/**
	 * Senza nulla di salvato, all() restituisce i default puri.
	 */
	public function test_all_returns_defaults_when_empty(): void {
		$all      = DBCM_Settings::all();
		$defaults = DBCM_Settings::defaults();
		$this->assertSame( $defaults, $all );
	}

	/**
	 * I valori salvati sovrascrivono i default corrispondenti.
	 */
	public function test_saved_values_override_defaults(): void {
		update_option( 'dbcm_settings', array( 'banner_layout' => 'bar' ) );

		$this->assertSame( 'bar', DBCM_Settings::get( 'banner_layout' ) );
		// Le altre chiavi restano ai default.
		$this->assertSame(
			DBCM_Settings::defaults()['consent_duration'],
			DBCM_Settings::get( 'consent_duration' )
		);
	}

	/**
	 * RETROCOMPATIBILITÀ FORWARD: una chiave presente nei default ma NON nel
	 * salvato (perché aggiunta in una versione successiva) compare comunque
	 * in all(). È la promessa esplicita del codice per non rompere le
	 * installazioni esistenti.
	 */
	public function test_new_default_key_appears_for_old_installs(): void {
		// Simula un'installazione vecchia: salvato senza la chiave "banner_theme".
		update_option( 'dbcm_settings', array( 'banner_layout' => 'bar' ) );

		$all = DBCM_Settings::all();
		$this->assertArrayHasKey(
			'banner_theme',
			$all,
			'Una chiave di default nuova deve comparire anche nelle installazioni vecchie.'
		);
	}

	/**
	 * Valori "falsy" salvati (false, 0, '') NON vengono persi dal merge:
	 * array_merge li mantiene (a differenza di un merge basato su ! empty).
	 */
	public function test_falsy_saved_values_are_preserved(): void {
		update_option( 'dbcm_settings', array(
			'banner_enabled' => false,
			'consent_duration' => 0,
			'reopen_position'  => '',
		) );

		$this->assertFalse( DBCM_Settings::get( 'banner_enabled' ), 'false salvato deve restare false.' );
		$this->assertSame( 0, DBCM_Settings::get( 'consent_duration' ), '0 salvato deve restare 0.' );
		$this->assertSame( '', DBCM_Settings::get( 'reopen_position' ), "'' salvato deve restare ''." );
	}

	/**
	 * DIFESA DB CORROTTO: se l'option non è un array (corruzione, valore
	 * scalare inatteso), all() degrada ai default senza crashare.
	 */
	public function test_corrupted_option_falls_back_to_defaults(): void {
		update_option( 'dbcm_settings', 'questo-non-e-un-array' );
		$all = DBCM_Settings::all();
		$this->assertSame( DBCM_Settings::defaults(), $all, 'Option corrotta → default puliti.' );
	}

	/* =====================================================================
	 * get() — singola impostazione con fallback
	 * ================================================================== */

	public function test_get_returns_fallback_for_unknown_key(): void {
		$this->assertSame(
			'valore-di-default',
			DBCM_Settings::get( 'chiave_inesistente', 'valore-di-default' )
		);
	}

	public function test_get_fallback_defaults_to_null(): void {
		$this->assertNull( DBCM_Settings::get( 'chiave_inesistente' ) );
	}

	public function test_get_existing_key_ignores_fallback(): void {
		$this->assertSame(
			DBCM_Settings::defaults()['banner_layout'],
			DBCM_Settings::get( 'banner_layout', 'FALLBACK-NON-USATO' )
		);
	}

	/* =====================================================================
	 * update() — persistenza
	 * ================================================================== */

	public function test_update_persists_and_reads_back(): void {
		DBCM_Settings::update( 'banner_position', 'bottom-center' );
		$this->assertSame( 'bottom-center', DBCM_Settings::get( 'banner_position' ) );
	}

	public function test_update_does_not_lose_other_keys(): void {
		DBCM_Settings::update( 'banner_layout', 'bar' );
		DBCM_Settings::update( 'banner_position', 'bottom-left' );

		$this->assertSame( 'bar', DBCM_Settings::get( 'banner_layout' ) );
		$this->assertSame( 'bottom-left', DBCM_Settings::get( 'banner_position' ) );
	}

	/* =====================================================================
	 * replace_all() — import / restore
	 * ================================================================== */

	/**
	 * replace_all rifiuta input non-array: non deve scrivere spazzatura.
	 */
	public function test_replace_all_rejects_non_array(): void {
		$this->assertFalse( DBCM_Settings::replace_all( 'stringa' ) );
		$this->assertFalse( DBCM_Settings::replace_all( 42 ) );
		$this->assertFalse( DBCM_Settings::replace_all( null ) );
	}

	/**
	 * replace_all con un import PARZIALE preserva i default per le chiavi non
	 * fornite: un backup incompleto non azzera il resto della configurazione.
	 */
	public function test_replace_all_preserves_defaults_for_missing_keys(): void {
		DBCM_Settings::replace_all( array( 'banner_layout' => 'bar' ) );

		$this->assertSame( 'bar', DBCM_Settings::get( 'banner_layout' ) );
		// Una chiave non fornita nell'import resta al default.
		$this->assertSame(
			DBCM_Settings::defaults()['consent_duration'],
			DBCM_Settings::get( 'consent_duration' ),
			'Le chiavi non fornite nell\'import restano ai default.'
		);
	}

	/* =====================================================================
	 * Categorie
	 * ================================================================== */

	public function test_categories_contains_five_standard(): void {
		$cats = DBCM_Settings::categories();
		$this->assertCount( 5, $cats );
		foreach ( array( 'functional', 'preferences', 'statistics', 'statistics-anonymous', 'marketing' ) as $c ) {
			$this->assertContains( $c, $cats );
		}
	}

	/**
	 * functional è nelle categorie totali ma NON in quelle opzionali: non ha
	 * un toggle nel banner (è sempre attivo).
	 */
	public function test_functional_not_in_optional_categories(): void {
		$this->assertContains( 'functional', DBCM_Settings::categories() );
		$this->assertNotContains( 'functional', DBCM_Settings::categories_optional() );
	}

	public function test_is_valid_category(): void {
		$this->assertTrue( DBCM_Settings::is_valid_category( 'marketing' ) );
		$this->assertTrue( DBCM_Settings::is_valid_category( 'statistics-anonymous' ) );
		$this->assertFalse( DBCM_Settings::is_valid_category( 'inesistente' ) );
		$this->assertFalse( DBCM_Settings::is_valid_category( '' ) );
	}

	/* =====================================================================
	 * consent_version() — versione del consenso (3.5.0)
	 * ================================================================== */

	/**
	 * Il default è 1: gli installati esistenti partono dalla versione 1,
	 * coerente con "cookie senza cv = versione 1" (nessun re-prompt
	 * all'aggiornamento del plugin).
	 */
	public function test_consent_version_defaults_to_1(): void {
		$this->assertSame( 1, DBCM_Settings::consent_version() );
		$this->assertSame( 1, DBCM_Settings::defaults()['consent_version'] );
	}

	/**
	 * Legge il valore salvato.
	 */
	public function test_consent_version_reads_saved_value(): void {
		update_option( DBCM_Settings::OPTION_KEY, array( 'consent_version' => 7 ) );
		$this->assertSame( 7, DBCM_Settings::consent_version() );
	}

	/**
	 * Clamp a >= 1: valori corrotti (0, negativi, non numerici) non devono
	 * mai produrre una versione invalida — un cookie forgiato con cv=0
	 * non deve poter combaciare con un setting corrotto a 0.
	 */
	public function test_consent_version_clamps_to_minimum_1(): void {
		update_option( DBCM_Settings::OPTION_KEY, array( 'consent_version' => 0 ) );
		$this->assertSame( 1, DBCM_Settings::consent_version() );

		update_option( DBCM_Settings::OPTION_KEY, array( 'consent_version' => -5 ) );
		$this->assertSame( 1, DBCM_Settings::consent_version() );

		update_option( DBCM_Settings::OPTION_KEY, array( 'consent_version' => 'garbage' ) );
		$this->assertSame( 1, DBCM_Settings::consent_version() );
	}

	/**
	 * Valori numerici in forma stringa (option round-trip) sono accettati.
	 */
	public function test_consent_version_casts_numeric_strings(): void {
		update_option( DBCM_Settings::OPTION_KEY, array( 'consent_version' => '4' ) );
		$this->assertSame( 4, DBCM_Settings::consent_version() );
	}
}
