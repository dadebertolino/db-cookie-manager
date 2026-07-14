<?php
/**
 * Test di INTEGRAZIONE di DBCM_Consent_Log.
 *
 * Girano contro un MySQL reale via WP_UnitTestCase. Verificano che la
 * registrazione del consenso funzioni end-to-end e — soprattutto — che
 * rispetti la privacy: l'IP non deve MAI finire in chiaro nel database.
 *
 * @package DBCM\Tests\Integration
 */

class ConsentLogIntegrationTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		DBCM_Consent_Log::create_table();
		global $wpdb;
		$table = DBCM_Consent_Log::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );
		// Log abilitato di default per i test.
		DBCM_Settings::update( 'consent_log_enabled', true );
	}

	private function last_row() {
		global $wpdb;
		$table = DBCM_Consent_Log::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 1" );
	}

	private function row_count() {
		global $wpdb;
		$table = DBCM_Consent_Log::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	/* ===================================================================== */

	public function test_insert_creates_a_row(): void {
		$id = DBCM_Consent_Log::insert( 'custom', array(
			'statistics' => true,
			'marketing'  => false,
		) );

		$this->assertIsInt( $id );
		$this->assertGreaterThan( 0, $id );
		$this->assertSame( 1, $this->row_count() );
	}

	/**
	 * PRIVACY (GDPR): l'IP del client viene HASHATO, mai memorizzato in chiaro.
	 * Simuliamo un IP noto e verifichiamo che quella stringa NON compaia nel
	 * DB, mentre l'hash (lunghezza fissa, diverso dall'IP) sì.
	 */
	public function test_ip_is_hashed_not_stored_plaintext(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.42';

		DBCM_Consent_Log::insert( 'accept_all', array( 'statistics' => true ) );
		$row = $this->last_row();

		$this->assertNotEmpty( $row->ip_hash );
		$this->assertStringNotContainsString(
			'203.0.113.42',
			$row->ip_hash,
			'L\'IP non deve MAI essere memorizzato in chiaro.'
		);
		// Un hash ha lunghezza fissa e non contiene i punti dell'IP.
		$this->assertStringNotContainsString( '.', $row->ip_hash );

		unset( $_SERVER['REMOTE_ADDR'] );
	}

	/**
	 * Il payload JSON registrato contiene tutte le 5 categorie standard più lo
	 * schema version, e riflette le scelte passate.
	 */
	public function test_payload_contains_all_categories(): void {
		DBCM_Consent_Log::insert( 'custom', array(
			'statistics' => true,
			'marketing'  => false,
		) );

		$row     = $this->last_row();
		$payload = json_decode( $row->consent_data, true );

		$this->assertIsArray( $payload );
		$this->assertArrayHasKey( 'v', $payload, 'Il payload deve includere lo schema version.' );
		foreach ( DBCM_Settings::categories() as $cat ) {
			$this->assertArrayHasKey( $cat, $payload, "Il payload deve includere '{$cat}'." );
		}
		$this->assertTrue( $payload['statistics'] );
		$this->assertFalse( $payload['marketing'] );
	}

	/**
	 * END-TO-END: sparare l'action 'dbcm_consent_set' (come fa l'AJAX handler)
	 * deve produrre una riga di log, tramite on_consent_set().
	 */
	public function test_action_dbcm_consent_set_logs_end_to_end(): void {
		$this->assertSame( 0, $this->row_count() );

		do_action( 'dbcm_consent_set', 'accept_all', array(
			'statistics' => true,
			'marketing'  => true,
		) );

		$this->assertSame( 1, $this->row_count(), 'L\'action dbcm_consent_set deve registrare una riga.' );
		$row = $this->last_row();
		$this->assertSame( 'accept_all', $row->consent_type );
	}

	/**
	 * Rispetto della configurazione: se il log è disabilitato, on_consent_set
	 * NON registra nulla.
	 */
	public function test_disabled_setting_skips_logging(): void {
		DBCM_Settings::update( 'consent_log_enabled', false );

		DBCM_Consent_Log::on_consent_set( 'custom', array( 'statistics' => true ) );

		$this->assertSame( 0, $this->row_count(), 'Con log disabilitato, nessuna riga deve essere scritta.' );
	}

	/**
	 * consent_type viene sanificato con whitelist: un tipo arbitrario/sporco
	 * non finisce grezzo nel database, ma viene forzato a 'custom'.
	 */
	public function test_consent_type_is_sanitized(): void {
		DBCM_Consent_Log::insert( '<script>alert(1)</script>', array( 'statistics' => true ) );
		$row = $this->last_row();

		$this->assertStringNotContainsString( '<script>', $row->consent_type, 'Il tipo non deve finire grezzo nel DB.' );
		$this->assertSame( 'custom', $row->consent_type, 'Un tipo fuori whitelist deve diventare "custom".' );
	}

	/**
	 * I tre tipi validi vengono conservati come sono.
	 */
	public function test_valid_consent_types_preserved(): void {
		foreach ( array( 'accept_all', 'reject_all', 'custom' ) as $type ) {
			DBCM_Consent_Log::insert( $type, array( 'statistics' => true ) );
			$row = $this->last_row();
			$this->assertSame( $type, $row->consent_type );
		}
	}
}
