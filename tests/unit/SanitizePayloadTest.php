<?php
/**
 * Test unit di sanitize_consent_payload() — sanificazione dell'input NON FIDATO
 * ricevuto via AJAX quando l'utente cambia consenso.
 *
 * È la frontiera fra il browser (non fidato) e lo stato di consenso del server.
 * Un bug qui = un client malevolo che inietta chiavi arbitrarie o valori
 * sporchi nello stato di consenso. Il metodo è privato: lo invochiamo via
 * Reflection (dbcm_test_call_private) per non alterare la visibilità in
 * produzione.
 *
 * @package DBCM\Tests
 */

use PHPUnit\Framework\TestCase;

final class SanitizePayloadTest extends TestCase {

	/**
	 * Helper: chiama il metodo privato.
	 *
	 * @param mixed $raw
	 * @return array
	 */
	private function sanitize( $raw ) {
		return dbcm_test_call_private(
			'DBCM_Consent_API',
			'sanitize_consent_payload',
			array( $raw )
		);
	}

	/* =====================================================================
	 * Struttura dell'output: sempre 5 chiavi booleane
	 * ================================================================== */

	public function test_always_returns_all_five_categories(): void {
		$out = $this->sanitize( array() );
		$this->assertCount( 5, $out, 'L\'output deve avere sempre 5 categorie.' );
		foreach ( DBCM_Settings::categories() as $cat ) {
			$this->assertArrayHasKey( $cat, $out );
			$this->assertIsBool( $out[ $cat ], "'{$cat}' deve essere booleano." );
		}
	}

	public function test_empty_input_all_false(): void {
		$out = $this->sanitize( array() );
		foreach ( $out as $cat => $val ) {
			$this->assertFalse( $val, "Input vuoto: '{$cat}' deve essere false." );
		}
	}

	/* =====================================================================
	 * SICUREZZA: whitelist rigida delle chiavi
	 * ================================================================== */

	/**
	 * Il test di sicurezza chiave: chiavi NON valide inviate dal client
	 * vengono SCARTATE. Un browser malevolo non deve poter iniettare
	 * categorie arbitrarie nello stato di consenso.
	 */
	public function test_unknown_keys_are_dropped(): void {
		$out = $this->sanitize( array(
			'statistics'    => true,
			'admin'         => true,      // non è una categoria
			'<script>'      => true,      // tentativo di iniezione
			'is_superuser'  => true,      // chiave arbitraria
			'functional; DROP' => true,
		) );

		$this->assertSame(
			DBCM_Settings::categories(),
			array_keys( $out ),
			'Solo le 5 categorie valide devono comparire, nell\'ordine canonico.'
		);
		$this->assertArrayNotHasKey( 'admin', $out );
		$this->assertArrayNotHasKey( '<script>', $out );
		$this->assertArrayNotHasKey( 'is_superuser', $out );
	}

	/**
	 * Le categorie valide presenti nell'input malevolo sopravvivono
	 * correttamente (la whitelist non butta via anche il buono).
	 */
	public function test_valid_keys_survive_alongside_junk(): void {
		$out = $this->sanitize( array(
			'statistics' => true,
			'garbage'    => true,
			'marketing'  => false,
		) );
		$this->assertTrue( $out['statistics'] );
		$this->assertFalse( $out['marketing'] );
	}

	/* =====================================================================
	 * Coercizione dei valori a booleano
	 * ================================================================== */

	/**
	 * Valori "truthy" di vario tipo diventano true.
	 */
	public function test_truthy_values_become_true(): void {
		$out = $this->sanitize( array(
			'preferences'          => 1,
			'statistics'           => '1',
			'statistics-anonymous' => 'yes',
			'marketing'            => true,
		) );
		$this->assertTrue( $out['preferences'] );
		$this->assertTrue( $out['statistics'] );
		$this->assertTrue( $out['statistics-anonymous'] );
		$this->assertTrue( $out['marketing'] );
	}

	/**
	 * Valori "falsy" (inclusi i casi insidiosi come "0", 0, '', null, [])
	 * diventano false. Nota: ! empty("0") è false — comportamento voluto.
	 */
	public function test_falsy_values_become_false(): void {
		$out = $this->sanitize( array(
			'preferences'          => 0,
			'statistics'           => '0',   // ! empty('0') === false
			'statistics-anonymous' => '',
			'marketing'            => null,
		) );
		$this->assertFalse( $out['preferences'] );
		$this->assertFalse( $out['statistics'], '"0" deve risultare false (! empty).' );
		$this->assertFalse( $out['statistics-anonymous'] );
		$this->assertFalse( $out['marketing'] );
	}

	/* =====================================================================
	 * Doppio formato: array oppure JSON string
	 * ================================================================== */

	public function test_accepts_json_string(): void {
		$out = $this->sanitize( '{"statistics":true,"marketing":false}' );
		$this->assertTrue( $out['statistics'] );
		$this->assertFalse( $out['marketing'] );
	}

	public function test_malformed_json_all_false(): void {
		$out = $this->sanitize( '{rotto: non valido' );
		$this->assertCount( 5, $out );
		foreach ( $out as $val ) {
			$this->assertFalse( $val, 'JSON malformato → tutto false, nessun crash.' );
		}
	}

	/**
	 * Input di tipo inatteso (non array, non stringa JSON valida) non deve
	 * crashare: degrada a tutto false.
	 */
	public function test_unexpected_input_types(): void {
		foreach ( array( null, 42, 3.14, true, false ) as $weird ) {
			$out = $this->sanitize( $weird );
			$this->assertCount( 5, $out, 'Anche input strani → 5 categorie.' );
			foreach ( $out as $val ) {
				$this->assertFalse( $val );
			}
		}
	}

	/**
	 * Un array JSON annidato/malevolo non deve propagare struttura: i valori
	 * complessi vengono coerciti a booleano.
	 */
	public function test_nested_values_coerced(): void {
		$out = $this->sanitize( array(
			'statistics' => array( 'nested' => 'evil' ), // array non vuoto → truthy
			'marketing'  => array(),                       // array vuoto → falsy
		) );
		$this->assertTrue( $out['statistics'], 'Array non vuoto → true.' );
		$this->assertFalse( $out['marketing'], 'Array vuoto → false.' );
		$this->assertIsBool( $out['statistics'] );
	}
}
