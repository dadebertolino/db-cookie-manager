<?php
/**
 * Test unit di DBCM_Declared_Services — il registro dei servizi dichiarati.
 *
 * Il registro risolve un problema strutturale di conformità: la Cookie
 * Policy generata deve elencare anche i servizi che lo scanner non può
 * vedere perché il blocker li ferma prima (Art. 5(1)(a): la policy riflette
 * i trattamenti effettivi). I test coprono registrazione, TTL, idratazione
 * dalle firme, CRUD manuale, merge e validazione di coerenza.
 *
 * @package DBCM\Tests
 */

use PHPUnit\Framework\TestCase;

final class DeclaredServicesTest extends TestCase {

	protected function setUp(): void {
		dbcm_test_reset();
		DBCM_Declared_Services::reset_request_cache();
	}

	protected function tearDown(): void {
		dbcm_test_reset();
		DBCM_Declared_Services::reset_request_cache();
	}

	/**
	 * Scorciatoia: scrive una voce auto direttamente in option.
	 */
	private function seed_auto( $slug, $last_seen ) {
		$data = get_option( DBCM_Declared_Services::OPTION, array() );
		if ( ! is_array( $data ) ) {
			$data = array();
		}
		$data['auto'][ $slug ] = array( 'last_seen' => $last_seen );
		update_option( DBCM_Declared_Services::OPTION, $data );
	}

	/* =====================================================================
	 * record_from_url() — registrazione auto dal blocker
	 * ================================================================== */

	/**
	 * Un URL riconducibile a una firma (iframe YouTube) viene registrato
	 * con last_seen = oggi. È il meccanismo che risolve la circolarità
	 * scanner/blocker.
	 */
	public function test_record_resolves_known_url_to_signature(): void {
		DBCM_Declared_Services::record_from_url( 'https://www.youtube.com/embed/abc123' );

		$entries = DBCM_Declared_Services::auto_entries();
		$this->assertCount( 1, $entries );
		$this->assertSame( 'youtube', $entries[0]['slug'] );
		$this->assertSame( 'YouTube', $entries[0]['service'] );
		$this->assertSame( gmdate( 'Y-m-d' ), $entries[0]['last_seen'] );
	}

	/**
	 * URL non riconducibile ad alcuna firma → nessuna voce (niente rumore:
	 * i casi non coperti si dichiarano a mano). La verifica è a livello
	 * STORAGE: il guard deve impedire proprio la scrittura, non solo la
	 * comparsa in policy — altrimenti la option accumula spazzatura a ogni
	 * pageview con script di terze parti sconosciute.
	 */
	public function test_record_ignores_unknown_url(): void {
		DBCM_Declared_Services::record_from_url( 'https://cdn.servizio-ignoto.example/player.js' );
		$this->assertCount( 0, DBCM_Declared_Services::auto_entries() );

		$stored = get_option( DBCM_Declared_Services::OPTION, array() );
		$this->assertTrue(
			empty( $stored['auto'] ),
			'Un URL ignoto non deve produrre alcuna scrittura nel registro.'
		);
	}

	/**
	 * URL vuoto → no-op senza errori.
	 */
	public function test_record_ignores_empty_url(): void {
		DBCM_Declared_Services::record_from_url( '' );
		$this->assertCount( 0, DBCM_Declared_Services::auto_entries() );
	}

	/**
	 * Lo stesso servizio matchato più volte nella stessa richiesta (es. 10
	 * video YouTube in pagina) produce UNA voce, non dieci.
	 */
	public function test_record_deduplicates_within_request(): void {
		DBCM_Declared_Services::record_from_url( 'https://www.youtube.com/embed/uno' );
		DBCM_Declared_Services::record_from_url( 'https://www.youtube.com/embed/due' );
		DBCM_Declared_Services::record_from_url( 'https://www.youtube.com/embed/tre' );

		$this->assertCount( 1, DBCM_Declared_Services::auto_entries() );
	}

	/* =====================================================================
	 * auto_entries() — TTL e idratazione dalle firme
	 * ================================================================== */

	/**
	 * Le voci auto sono idratate dal DB firme: fornitore, categoria,
	 * informativa e cookie tipici arrivano dalla firma, non dalla option
	 * (fonte unica di verità: aggiornare le firme aggiorna la policy).
	 */
	public function test_auto_entries_hydrated_from_signature(): void {
		$this->seed_auto( 'youtube', gmdate( 'Y-m-d' ) );

		$entries = DBCM_Declared_Services::auto_entries();
		$this->assertCount( 1, $entries );
		$e = $entries[0];
		$this->assertSame( 'Google Ireland Ltd.', $e['provider'] );
		$this->assertSame( 'marketing', $e['category'] );
		$this->assertStringContainsString( 'policies.google.com', $e['policy_url'] );
		$this->assertStringContainsString( 'YSC', $e['cookies_text'] );
		$this->assertSame( 'auto', $e['origin'] );
	}

	/**
	 * STALENESS: una voce fuori finestra TTL non entra in policy — embed
	 * rimosso dal sito → la dichiarazione decade da sola.
	 */
	public function test_auto_entries_exclude_stale(): void {
		$this->seed_auto( 'youtube', gmdate( 'Y-m-d', time() - 60 * DAY_IN_SECONDS ) );
		$this->assertCount( 0, DBCM_Declared_Services::auto_entries() );
	}

	/**
	 * Il TTL è filtrabile: con finestra allargata a 90 giorni la stessa
	 * voce a -60gg torna valida.
	 */
	public function test_ttl_is_filterable(): void {
		$this->seed_auto( 'youtube', gmdate( 'Y-m-d', time() - 60 * DAY_IN_SECONDS ) );

		add_filter( 'dbcm_declared_services_ttl', function () {
			return 90;
		} );

		$this->assertCount( 1, DBCM_Declared_Services::auto_entries() );
	}

	/**
	 * Slug la cui firma non esiste più (es. firma custom eliminata) vengono
	 * ignorati senza errori.
	 */
	public function test_auto_entries_skip_missing_signature(): void {
		$this->seed_auto( 'servizio-che-non-esiste', gmdate( 'Y-m-d' ) );
		$this->assertCount( 0, DBCM_Declared_Services::auto_entries() );
	}

	/* =====================================================================
	 * Voci manuali — CRUD
	 * ================================================================== */

	/**
	 * Modalità pick-list: passando solo lo slug di una firma nota, tutti i
	 * dati vengono precompilati dalla firma (un click, zero digitazione).
	 */
	public function test_save_manual_prefills_from_signature(): void {
		$id = DBCM_Declared_Services::save_manual( array( 'slug' => 'youtube' ) );
		$this->assertIsString( $id );

		$entries = DBCM_Declared_Services::manual_entries();
		$this->assertCount( 1, $entries );
		$this->assertSame( 'YouTube', $entries[0]['service'] );
		$this->assertSame( 'Google Ireland Ltd.', $entries[0]['provider'] );
		$this->assertSame( 'marketing', $entries[0]['category'] );
		$this->assertStringContainsString( 'YSC', $entries[0]['cookies_text'] );
	}

	/**
	 * Voce libera: basta il nome del servizio; i campi espliciti vincono.
	 */
	public function test_save_manual_free_form(): void {
		$id = DBCM_Declared_Services::save_manual( array(
			'service'    => 'Vimeo',
			'provider'   => 'Vimeo Inc.',
			'category'   => 'marketing',
			'policy_url' => 'https://vimeo.com/privacy',
		) );
		$this->assertIsString( $id );

		$entries = DBCM_Declared_Services::manual_entries();
		$this->assertSame( 'Vimeo', $entries[0]['service'] );
		$this->assertSame( 'https://vimeo.com/privacy', $entries[0]['policy_url'] );
	}

	/**
	 * Senza nome servizio (né slug risolvibile) il salvataggio fallisce.
	 */
	public function test_save_manual_requires_service_name(): void {
		$this->assertFalse( DBCM_Declared_Services::save_manual( array( 'provider' => 'Qualcuno' ) ) );
		$this->assertCount( 0, DBCM_Declared_Services::manual_entries() );
	}

	/**
	 * delete_manual rimuove la voce; id inesistente → false.
	 */
	public function test_delete_manual(): void {
		$id = DBCM_Declared_Services::save_manual( array( 'service' => 'Vimeo' ) );
		$this->assertTrue( DBCM_Declared_Services::delete_manual( $id ) );
		$this->assertCount( 0, DBCM_Declared_Services::manual_entries() );
		$this->assertFalse( DBCM_Declared_Services::delete_manual( 'id-inesistente' ) );
	}

	/**
	 * Le voci dichiarate rappresentano servizi PREVIO CONSENSO: una
	 * categoria non valida o 'functional' ripiega su marketing (fallback
	 * conservativo: mai dichiarare consenso-esente ciò che non lo è).
	 */
	public function test_invalid_category_falls_back_to_marketing(): void {
		DBCM_Declared_Services::save_manual( array(
			'service'  => 'Servizio X',
			'category' => 'functional',
		) );
		$entries = DBCM_Declared_Services::manual_entries();
		$this->assertSame( 'marketing', $entries[0]['category'] );
	}

	/* =====================================================================
	 * grouped_for_policy() — merge e filtro DBPH
	 * ================================================================== */

	/**
	 * Merge auto+manuale raggruppato per categoria; a parità di slug la
	 * voce manuale vince (l'admin può aver rifinito i dati).
	 */
	public function test_grouped_merges_and_manual_wins_on_duplicate(): void {
		$this->seed_auto( 'youtube', gmdate( 'Y-m-d' ) );
		DBCM_Declared_Services::save_manual( array(
			'slug'     => 'youtube',
			'provider' => 'Fornitore Rifinito',
		) );

		$grouped = DBCM_Declared_Services::grouped_for_policy();
		$this->assertArrayHasKey( 'marketing', $grouped );
		$this->assertCount( 1, $grouped['marketing'], 'Stesso slug: una sola voce.' );
		$this->assertSame( 'Fornitore Rifinito', $grouped['marketing'][0]['provider'] );
		$this->assertSame( 'manual', $grouped['marketing'][0]['origin'] );
	}

	/**
	 * Il registro è esposto via filtro per DB Privacy Hub (fonte unica di
	 * verità per la sezione "Contenuti incorporati" della Privacy Policy).
	 */
	public function test_register_filter_is_applied(): void {
		$this->seed_auto( 'youtube', gmdate( 'Y-m-d' ) );

		add_filter( 'dbcm_declared_services_register', function ( $grouped ) {
			$grouped['__visto_da_dbph'] = true;
			return $grouped;
		} );

		$grouped = DBCM_Declared_Services::grouped_for_policy();
		$this->assertArrayHasKey( '__visto_da_dbph', $grouped );
	}

	/* =====================================================================
	 * coherence_warnings() — validazione banner ↔ policy
	 * ================================================================== */

	/**
	 * Registro vuoto e nessun cookie rilevato: tutte e 4 le categorie
	 * opzionali risultano scoperte (richiesta di consenso non giustificata).
	 */
	public function test_coherence_flags_all_optional_when_empty(): void {
		$uncovered = DBCM_Declared_Services::coherence_warnings();
		$this->assertCount( 4, $uncovered );
		$this->assertContains( 'marketing', $uncovered );
		$this->assertNotContains( 'functional', $uncovered, 'functional non richiede consenso: mai in warning.' );
	}

	/**
	 * Dichiarare un servizio marketing toglie marketing dai warning.
	 */
	public function test_coherence_cleared_by_declared_entry(): void {
		$this->seed_auto( 'youtube', gmdate( 'Y-m-d' ) );
		$uncovered = DBCM_Declared_Services::coherence_warnings();
		$this->assertNotContains( 'marketing', $uncovered );
		$this->assertContains( 'statistics', $uncovered );
	}
}
