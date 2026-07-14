<?php
/**
 * Test di INTEGRAZIONE di DBCM_Scanner.
 *
 * Girano contro un MySQL reale via WP_UnitTestCase. Verificano l'I/O su
 * database che gli unit test non possono toccare: creazione tabella, vincolo
 * UNIQUE, query di raggruppamento e ordinamento per categoria.
 *
 * @package DBCM\Tests\Integration
 */

class ScannerIntegrationTest extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		// Assicura la tabella dei cookie pulita per ogni test.
		DBCM_Scanner::create_table();
		global $wpdb;
		$table = DBCM_Scanner::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );
	}

	/**
	 * Inserisce una riga cookie direttamente (helper).
	 */
	private function insert_cookie( $name, $domain, $category, $provider = '', $duration = '' ) {
		global $wpdb;
		$wpdb->insert(
			DBCM_Scanner::table_name(),
			array(
				'cookie_name'     => $name,
				'cookie_domain'   => $domain,
				'category'        => $category,
				'provider'        => $provider,
				'cookie_duration' => $duration,
				'description'     => '',
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		return $wpdb->insert_id;
	}

	/* ===================================================================== */

	public function test_create_table_creates_the_table(): void {
		global $wpdb;
		$table = DBCM_Scanner::table_name();

		// Non usiamo SHOW TABLES (né LIKE): sotto la transazione della
		// WordPress test suite i metadati delle tabelle create nel setUp non
		// sono elencati in modo affidabile, anche se la tabella È interrogabile
		// (lo dimostrano gli altri test che vi fanno INSERT/SELECT). Verifichiamo
		// quindi che una query sulla tabella riesca: se non esistesse, get_var
		// tornerebbe null e $wpdb->last_error sarebbe valorizzato.
		$wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result    = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$has_error = ! empty( $wpdb->last_error );
		$wpdb->suppress_errors( false );

		$this->assertFalse( $has_error, "La tabella {$table} deve essere interrogabile (nessun errore SQL)." );
		$this->assertNotNull( $result, 'Una COUNT(*) sulla tabella esistente deve restituire un valore.' );
	}

	public function test_table_has_expected_columns(): void {
		global $wpdb;
		$table   = DBCM_Scanner::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$columns = $wpdb->get_col( "DESC {$table}", 0 );

		foreach ( array( 'id', 'cookie_name', 'cookie_domain', 'category', 'provider', 'cookie_duration' ) as $col ) {
			$this->assertContains( $col, $columns, "Colonna mancante: {$col}" );
		}
	}

	/**
	 * Il vincolo UNIQUE(cookie_name, cookie_domain) impedisce doppioni dello
	 * stesso cookie sullo stesso dominio.
	 */
	public function test_unique_constraint_prevents_duplicates(): void {
		global $wpdb;
		$this->insert_cookie( '_ga', '.esempio.it', 'statistics' );

		// Secondo insert identico: deve fallire (o non aggiungere una riga).
		$wpdb->suppress_errors( true );
		$wpdb->insert(
			DBCM_Scanner::table_name(),
			array( 'cookie_name' => '_ga', 'cookie_domain' => '.esempio.it', 'category' => 'statistics' ),
			array( '%s', '%s', '%s' )
		);
		$wpdb->suppress_errors( false );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . DBCM_Scanner::table_name() . " WHERE cookie_name = '_ga'" );
		$this->assertSame( 1, $count, 'Il vincolo UNIQUE deve impedire il doppione (name, domain).' );
	}

	/**
	 * Stesso nome cookie ma dominio diverso: sono due cookie distinti,
	 * entrambi ammessi.
	 */
	public function test_same_name_different_domain_allowed(): void {
		$this->insert_cookie( '_ga', '.esempio.it', 'statistics' );
		$this->insert_cookie( '_ga', '.altro.com', 'statistics' );

		global $wpdb;
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . DBCM_Scanner::table_name() . " WHERE cookie_name = '_ga'" );
		$this->assertSame( 2, $count, 'Stesso nome su domini diversi = due cookie distinti.' );
	}

	/**
	 * get_results_grouped raggruppa per categoria e rispetta l'ordine canonico
	 * (functional prima, marketing dopo).
	 */
	public function test_grouped_results_ordered_by_category(): void {
		$this->insert_cookie( '_fbp', '.esempio.it', 'marketing', 'Meta' );
		$this->insert_cookie( 'wp_session', '.esempio.it', 'functional', 'Sito' );
		$this->insert_cookie( '_ga', '.esempio.it', 'statistics', 'Google' );

		$grouped = DBCM_Scanner::get_results_grouped();
		$keys    = array_keys( $grouped );

		// functional deve precedere statistics, che precede marketing.
		$this->assertSame(
			array( 'functional', 'statistics', 'marketing' ),
			$keys,
			'Le categorie devono seguire l\'ordine canonico WP Consent API.'
		);
		$this->assertCount( 1, $grouped['functional'] );
		$this->assertSame( '_fbp', $grouped['marketing'][0]->cookie_name );
	}

	/**
	 * count_by_category conta correttamente i cookie per categoria.
	 */
	public function test_count_by_category(): void {
		$this->insert_cookie( '_ga', '.esempio.it', 'statistics' );
		$this->insert_cookie( '_gid', '.esempio.it', 'statistics' );
		$this->insert_cookie( '_fbp', '.esempio.it', 'marketing' );

		$counts = DBCM_Scanner::count_by_category();
		$this->assertSame( 2, (int) $counts['statistics'] );
		$this->assertSame( 1, (int) $counts['marketing'] );
	}

	/**
	 * store_notice* è un cookie tecnico di WooCommerce: lo scanner deve
	 * classificarlo come functional, non come marketing (era un falso positivo).
	 */
	public function test_store_notice_is_functional(): void {
		$info = DBCM_Cookie_Database::identify_cookie( 'store_notice12345' );
		$this->assertSame( 'functional', $info['category'], 'store_notice* deve essere tecnico, non marketing.' );
	}

	/**
	 * Un cookie noto SOLO al database firme (non elencato esplicitamente in
	 * get_known_cookies) viene comunque classificato tramite il fallback che
	 * consulta DBCM_Signatures, invece di ripiegare su marketing.
	 */
	public function test_identify_cookie_consults_signatures(): void {
		// _fbp è nelle firme come marketing: confermiamo che la classificazione
		// derivi (categoria valida, non il fallback "Sconosciuta").
		$info = DBCM_Cookie_Database::identify_cookie( '_fbp' );
		$this->assertTrue(
			DBCM_Settings::is_valid_category( $info['category'] ),
			'Un cookie noto deve avere una categoria valida.'
		);
		$this->assertNotSame(
			'Cookie non identificato — verificare manualmente la finalità prima della pubblicazione.',
			$info['description'],
			'Un cookie noto non deve cadere nel fallback "non identificato".'
		);
	}
}