<?php
/**
 * Test unit di DBCM_Policy_Generator — generatore della Cookie Policy.
 *
 * L'output finisce in una PAGINA LEGALE. Un bug qui non rompe una funzione:
 * produce un documento di conformità sbagliato (cookie omesso, categoria
 * errata, o peggio XSS da un nome cookie malevolo rilevato dallo scanner).
 *
 * @package DBCM\Tests
 */

use PHPUnit\Framework\TestCase;

final class PolicyGeneratorTest extends TestCase {

	protected function setUp(): void {
		// Il generatore ora consulta anche le firme (link informative
		// fornitori): resettiamo option e cache per isolare i test.
		dbcm_test_reset();
		dbcm_test_set_grouped_results( array() );
	}

	protected function tearDown(): void {
		dbcm_test_set_grouped_results( array() );
	}

	/* =====================================================================
	 * get_sections() — contratto stabile verso il Privacy Hub
	 * ================================================================== */

	/**
	 * Le 8 chiavi di sezione sono un contratto stabile: il DB Privacy Hub le
	 * importa per comporre la Privacy Policy. Se una sparisce, l'Hub si rompe.
	 */
	public function test_sections_have_stable_keys(): void {
		$sections = DBCM_Policy_Generator::get_sections();
		$expected = array(
			'header',
			'what_are_cookies',
			'cookies_used',
			'external_services',
			'browser_management',
			'data_controller',
			'updates',
			'footer',
		);
		foreach ( $expected as $key ) {
			$this->assertArrayHasKey( $key, $sections, "Manca la sezione stabile '{$key}'." );
		}
	}

	public function test_sections_are_non_empty_html(): void {
		$sections = DBCM_Policy_Generator::get_sections();
		foreach ( array( 'header', 'what_are_cookies', 'cookies_used', 'browser_management' ) as $key ) {
			$this->assertNotSame( '', trim( (string) $sections[ $key ] ), "La sezione '{$key}' non deve essere vuota." );
		}
	}

	/* =====================================================================
	 * generate() — documento completo
	 * ================================================================== */

	public function test_generate_returns_html_string(): void {
		$html = DBCM_Policy_Generator::generate();
		$this->assertIsString( $html );
		$this->assertStringContainsString( 'Cookie Policy', $html );
	}

	/* =====================================================================
	 * Fallback senza scansione
	 * ================================================================== */

	/**
	 * Senza scansione, la policy NON è vuota: mostra almeno il cookie tecnico
	 * dbcm_consent, così il documento è comunque valido.
	 */
	public function test_fallback_shows_technical_cookie(): void {
		dbcm_test_set_grouped_results( array() ); // nessuna scansione.
		$html = DBCM_Policy_Generator::generate();
		$this->assertStringContainsString( 'dbcm_consent', $html, 'Il fallback deve elencare il cookie tecnico.' );
	}

	/* =====================================================================
	 * Completezza: ogni cookie scansionato compare nella policy
	 * ================================================================== */

	/**
	 * Un cookie rilevato dallo scanner DEVE comparire nel documento con nome,
	 * fornitore, finalità e durata. Un cookie omesso = policy non conforme.
	 */
	public function test_scanned_cookie_appears_in_policy(): void {
		dbcm_test_set_grouped_results( array(
			'statistics' => array(
				dbcm_test_cookie_row( '_ga', 'Google', 'Statistiche di utilizzo', '2 anni' ),
			),
		) );

		$html = DBCM_Policy_Generator::generate();
		$this->assertStringContainsString( '_ga', $html, 'Il nome cookie deve comparire.' );
		$this->assertStringContainsString( 'Google', $html, 'Il fornitore deve comparire.' );
		$this->assertStringContainsString( 'Statistiche di utilizzo', $html, 'La finalità deve comparire.' );
		$this->assertStringContainsString( '2 anni', $html, 'La durata deve comparire.' );
	}

	/**
	 * Cookie di categorie diverse finiscono in blocchi diversi, ognuno con la
	 * propria etichetta di categoria.
	 */
	public function test_cookies_grouped_by_category(): void {
		dbcm_test_set_grouped_results( array(
			'statistics' => array( dbcm_test_cookie_row( '_ga', 'Google', 'Analytics', '2 anni' ) ),
			'marketing'  => array( dbcm_test_cookie_row( '_fbp', 'Meta', 'Advertising', '3 mesi' ) ),
		) );

		$html = DBCM_Policy_Generator::generate();
		// Entrambe le etichette di categoria (dallo stub Cookie_Database).
		$this->assertStringContainsString( 'Statistici', $html );
		$this->assertStringContainsString( 'Marketing', $html );
		// Entrambi i cookie.
		$this->assertStringContainsString( '_ga', $html );
		$this->assertStringContainsString( '_fbp', $html );
	}

	/**
	 * Più cookie nella stessa categoria compaiono tutti.
	 */
	public function test_multiple_cookies_same_category(): void {
		dbcm_test_set_grouped_results( array(
			'statistics' => array(
				dbcm_test_cookie_row( '_ga', 'Google', 'A', '2 anni' ),
				dbcm_test_cookie_row( '_gid', 'Google', 'B', '1 giorno' ),
				dbcm_test_cookie_row( '_gat', 'Google', 'C', '1 minuto' ),
			),
		) );

		$html = DBCM_Policy_Generator::generate();
		$this->assertStringContainsString( '_ga', $html );
		$this->assertStringContainsString( '_gid', $html );
		$this->assertStringContainsString( '_gat', $html );
	}

	/* =====================================================================
	 * SICUREZZA: escaping XSS dei dati scansionati
	 * ================================================================== */

	/**
	 * I dati dei cookie vengono dallo scan (potenzialmente da domini terzi):
	 * un nome cookie malevolo NON deve produrre HTML eseguibile nella policy.
	 * Deve essere escaped.
	 */
	public function test_malicious_cookie_name_is_escaped(): void {
		dbcm_test_set_grouped_results( array(
			'marketing' => array(
				dbcm_test_cookie_row(
					'<script>alert(1)</script>',
					'<img src=x onerror=alert(2)>',
					'Descrizione',
					'1 anno'
				),
			),
		) );

		$html = DBCM_Policy_Generator::generate();
		// Il tag <script> grezzo NON deve comparire: deve essere escaped in &lt;script&gt;.
		$this->assertStringNotContainsString( '<script>alert(1)</script>', $html, 'Il nome cookie malevolo deve essere escaped.' );
		$this->assertStringContainsString( '&lt;script&gt;', $html, 'Deve comparire la versione escaped.' );
		// Anche il fornitore malevolo escaped.
		$this->assertStringNotContainsString( '<img src=x onerror=alert(2)>', $html );
	}

	/* =====================================================================
	 * TRASFERIMENTO EXTRA-UE (colonna + nota)
	 * ================================================================== */

	/**
	 * La tabella cookie ha una colonna "Trasferimento".
	 */
	public function test_policy_has_transfer_column(): void {
		dbcm_test_set_grouped_results( array(
			'statistics' => array( dbcm_test_cookie_row( '_ga', 'Google Analytics', 'Analytics', '2 anni' ) ),
		) );
		$html = DBCM_Policy_Generator::generate();
		$this->assertStringContainsString( 'Trasferimento', $html, 'La colonna Trasferimento deve esserci.' );
	}

	/**
	 * Un provider USA (Google) è marcato con trasferimento extra-UE e la nota
	 * sulle garanzie del Capo V compare.
	 */
	public function test_us_provider_flagged_extra_eu(): void {
		dbcm_test_set_grouped_results( array(
			'statistics' => array( dbcm_test_cookie_row( '_ga', 'Google Analytics', 'Analytics', '2 anni' ) ),
		) );
		$html = DBCM_Policy_Generator::generate();
		$this->assertStringContainsString( 'USA', $html, 'Google deve risultare trasferimento USA.' );
		$this->assertStringContainsString( 'Capo V', $html, 'La nota sulle garanzie deve comparire.' );
	}

	/**
	 * Un provider UE non attiva la nota extra-UE.
	 */
	public function test_eu_provider_no_extra_eu_note(): void {
		dbcm_test_set_grouped_results( array(
			'functional' => array( dbcm_test_cookie_row( 'dbcm_consent', 'DB Cookie Manager', 'Consenso', '365 giorni' ) ),
		) );
		$html = DBCM_Policy_Generator::generate();
		$this->assertStringContainsString( 'UE/SEE', $html, 'Un provider UE deve risultare UE/SEE.' );
		$this->assertStringNotContainsString( 'Capo V', $html, 'Senza provider extra-UE, niente nota trasferimento.' );
	}

	/* =====================================================================
	 * Link informative fornitori (trasparenza Art. 13(1)(e)-(f))
	 * ================================================================== */

	/**
	 * Se il fornitore è noto alle firme, il suo nome nella tabella cookie
	 * linka l'informativa privacy (target _blank + rel noopener).
	 */
	public function test_known_provider_is_linked(): void {
		dbcm_test_set_grouped_results( array(
			'statistics' => array( dbcm_test_cookie_row( '_ga', 'Google Analytics', 'Analytics', '2 anni' ) ),
		) );
		$html = DBCM_Policy_Generator::generate();
		$this->assertStringContainsString( '<a href="https://policies.google.com/privacy"', $html, 'Il fornitore noto deve linkare la sua informativa.' );
		$this->assertStringContainsString( 'rel="noopener">Google Analytics</a>', $html, 'Il testo del link è il nome del fornitore.' );
	}

	/**
	 * Un fornitore ignoto (o self-hosted) resta testo semplice: nessun link
	 * inventato.
	 */
	public function test_unknown_provider_is_plain_text(): void {
		dbcm_test_set_grouped_results( array(
			'functional' => array( dbcm_test_cookie_row( 'dbcm_consent', 'DB Cookie Manager', 'Consenso', '365 giorni' ) ),
		) );
		$html = DBCM_Policy_Generator::generate();
		$this->assertStringNotContainsString( 'rel="noopener">DB Cookie Manager</a>', $html, 'Un fornitore senza informativa nota non deve essere linkato.' );
	}

}