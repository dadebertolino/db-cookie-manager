<?php
/**
 * Test unit di DBCM_Consent_API — la logica di decisione del consenso.
 *
 * Questo è il cuore GDPR del plugin: un bug qui significa tracciare un utente
 * che ha detto "no", o bloccare cookie tecnici necessari. I test bloccano
 * contro regressioni i comportamenti a rilevanza legale.
 *
 * @package DBCM\Tests
 */

use PHPUnit\Framework\TestCase;

final class ConsentApiTest extends TestCase {

	protected function setUp(): void {
		dbcm_test_reset_consent();
		$GLOBALS['__dbcm_options'] = array();
	}

	protected function tearDown(): void {
		dbcm_test_reset_consent();
		$GLOBALS['__dbcm_options'] = array();
	}

	/* =====================================================================
	 * has_consent() — la decisione centrale
	 * ================================================================== */

	/**
	 * DEFAULT NEGO: senza cookie e senza WP Consent API, ogni categoria
	 * opzionale è negata. È il comportamento GDPR fondamentale.
	 */
	public function test_default_denies_all_optional_categories(): void {
		foreach ( array( 'preferences', 'statistics', 'statistics-anonymous', 'marketing' ) as $cat ) {
			$this->assertFalse(
				DBCM_Consent_API::has_consent( $cat ),
				"Senza consenso, '{$cat}' deve essere negata (default GDPR)."
			);
		}
	}

	/**
	 * functional è SEMPRE concesso, anche senza alcun cookie. I cookie
	 * tecnici non devono mai essere bloccati (carrello, sessione, CSRF).
	 */
	public function test_functional_always_granted(): void {
		$this->assertTrue(
			DBCM_Consent_API::has_consent( 'functional' ),
			'functional deve essere sempre concesso, anche senza cookie.'
		);
	}

	/**
	 * Una categoria non valida non deve mai concedere consenso né sollevare
	 * errori: deve negare in modo sicuro.
	 */
	public function test_invalid_category_denies_safely(): void {
		$this->assertFalse( DBCM_Consent_API::has_consent( 'inesistente' ) );
		$this->assertFalse( DBCM_Consent_API::has_consent( '' ) );
	}

	/**
	 * Con un cookie valido, le categorie accettate risultano concesse e
	 * quelle rifiutate negate.
	 */
	public function test_cookie_grants_accepted_categories(): void {
		dbcm_test_set_consent_cookie( array(
			'statistics' => true,
			'marketing'  => false,
		) );

		$this->assertTrue( DBCM_Consent_API::has_consent( 'statistics' ), 'statistics accettato deve risultare concesso.' );
		$this->assertFalse( DBCM_Consent_API::has_consent( 'marketing' ), 'marketing rifiutato deve risultare negato.' );
	}

	/**
	 * Cookie con schema di versione diversa (es. un vecchio cookie 2.x) va
	 * IGNORATO: l'utente rivede il banner, non si interpretano consensi con
	 * lo schema sbagliato.
	 */
	public function test_wrong_schema_cookie_is_ignored(): void {
		dbcm_test_set_consent_cookie( array( 'statistics' => true ), 2 ); // schema 2, non 3.

		$this->assertFalse(
			DBCM_Consent_API::has_consent( 'statistics' ),
			'Un cookie con schema incompatibile deve essere ignorato (nego).'
		);
	}

	/**
	 * Cookie JSON malformato non deve crashare né concedere consensi fantasma.
	 */
	public function test_malformed_cookie_denies(): void {
		dbcm_test_set_consent_cookie( '{questo non e json valido', null );
		$this->assertNull( DBCM_Consent_API::read_cookie(), 'Cookie malformato → read_cookie null.' );
		$this->assertFalse( DBCM_Consent_API::has_consent( 'statistics' ) );
	}

	/* =====================================================================
	 * Versione del consenso (3.5.0) — Art. 4(11): il consenso è specifico
	 * rispetto ai trattamenti presentati al momento della scelta.
	 * NOTA convenzione suite: questi test usano il fallback cookie di
	 * has_consent() e devono stare PRIMA dei test che abilitano la finta
	 * WP Consent API (una volta definita, wp_has_consent resta definita
	 * e ha priorità sul cookie).
	 * ================================================================== */

	/**
	 * RETROCOMPATIBILITÀ CRITICA: un cookie pre-3.5.0 (senza campo 'cv')
	 * equivale a versione 1. Con il setting alla versione 1 di default,
	 * l'aggiornamento del plugin da solo NON invalida i consensi esistenti.
	 */
	public function test_cookie_without_cv_is_valid_at_version_1(): void {
		dbcm_test_set_consent_cookie( array( 'statistics' => true ) ); // niente 'cv'.
		$this->assertIsArray( DBCM_Consent_API::read_cookie() );
		$this->assertTrue( DBCM_Consent_API::has_consent( 'statistics' ) );
	}

	/**
	 * Cookie con cv corrispondente al setting → valido.
	 */
	public function test_cookie_with_matching_cv_is_valid(): void {
		update_option( DBCM_Settings::OPTION_KEY, array( 'consent_version' => 3 ) );
		dbcm_test_set_consent_cookie( array( 'statistics' => true, 'cv' => 3 ) );

		$this->assertTrue( DBCM_Consent_API::has_consent( 'statistics' ) );
	}

	/**
	 * MISMATCH = NO-CONSENT (rigoroso): dopo un bump admin, il cookie
	 * pre-bump non copre i trattamenti correnti. read_cookie → null,
	 * has_consent → false, il banner viene ri-mostrato.
	 */
	public function test_stale_cv_cookie_is_treated_as_no_consent(): void {
		update_option( DBCM_Settings::OPTION_KEY, array( 'consent_version' => 2 ) );
		dbcm_test_set_consent_cookie( array( 'statistics' => true, 'cv' => 1 ) );

		$this->assertNull( DBCM_Consent_API::read_cookie(), 'Cookie con versione stantia → null.' );
		$this->assertFalse( DBCM_Consent_API::has_consent( 'statistics' ), 'Versione stantia = assenza di consenso.' );
	}

	/**
	 * Il mismatch vale anche all'indietro: un cookie senza 'cv' (= v1) è
	 * stantio se il setting è stato bumpato a 2.
	 */
	public function test_cookie_without_cv_is_stale_after_bump(): void {
		update_option( DBCM_Settings::OPTION_KEY, array( 'consent_version' => 2 ) );
		dbcm_test_set_consent_cookie( array( 'statistics' => true ) ); // pre-3.5, niente cv.

		$this->assertNull( DBCM_Consent_API::read_cookie() );
		$this->assertFalse( DBCM_Consent_API::has_consent( 'statistics' ) );
	}

	/**
	 * Un cv "dal futuro" (> corrente) è comunque un mismatch: solo
	 * l'uguaglianza esatta è valida (niente >= che accetterebbe cookie
	 * forgiati con versioni arbitrarie).
	 */
	public function test_future_cv_cookie_is_invalid(): void {
		update_option( DBCM_Settings::OPTION_KEY, array( 'consent_version' => 2 ) );
		dbcm_test_set_consent_cookie( array( 'statistics' => true, 'cv' => 99 ) );

		$this->assertNull( DBCM_Consent_API::read_cookie() );
	}

	/**
	 * Se la WP Consent API è installata, è la sorgente di verità: has_consent
	 * delega a wp_has_consent() e NON al cookie.
	 */
	public function test_wp_consent_api_is_source_of_truth(): void {
		dbcm_test_set_consent_api( true );
		wp_set_consent( 'statistics', 'allow' );
		wp_set_consent( 'marketing', 'deny' );

		$this->assertTrue( DBCM_Consent_API::has_consent( 'statistics' ) );
		$this->assertFalse( DBCM_Consent_API::has_consent( 'marketing' ) );
	}

	/**
	 * Con WP Consent API attiva ma un cookie che direbbe il contrario, vince
	 * la Consent API (priorità 1 sul cookie).
	 */
	public function test_wp_consent_api_takes_precedence_over_cookie(): void {
		dbcm_test_set_consent_api( true );
		wp_set_consent( 'statistics', 'deny' );
		dbcm_test_set_consent_cookie( array( 'statistics' => true ) ); // cookie direbbe "sì".

		$this->assertFalse(
			DBCM_Consent_API::has_consent( 'statistics' ),
			'La WP Consent API ha priorità sul cookie.'
		);
	}

	/* =====================================================================
	 * read_cookie() — parsing e normalizzazione
	 * ================================================================== */

	public function test_read_cookie_null_when_absent(): void {
		$this->assertNull( DBCM_Consent_API::read_cookie() );
	}

	/**
	 * read_cookie normalizza SEMPRE a tutte le 5 categorie standard, anche se
	 * il cookie ne contiene solo alcune.
	 */
	public function test_read_cookie_normalizes_all_categories(): void {
		dbcm_test_set_consent_cookie( array( 'statistics' => true ) );
		$cookie = DBCM_Consent_API::read_cookie();

		$this->assertIsArray( $cookie );
		foreach ( DBCM_Settings::categories() as $cat ) {
			$this->assertArrayHasKey( $cat, $cookie, "read_cookie deve includere '{$cat}'." );
			$this->assertIsBool( $cookie[ $cat ], "Il valore di '{$cat}' deve essere booleano." );
		}
		$this->assertTrue( $cookie['statistics'] );
		$this->assertFalse( $cookie['marketing'] );
	}

	/* =====================================================================
	 * propagate_consent() — verso la WP Consent API
	 * ================================================================== */

	/**
	 * propagate_consent forza functional='allow' a prescindere dall'input:
	 * i cookie tecnici non si possono negare.
	 */
	public function test_propagate_forces_functional_allow(): void {
		dbcm_test_set_consent_api( true );

		DBCM_Consent_API::propagate_consent( array(
			'functional' => false, // anche se l'input dicesse no...
			'statistics' => true,
			'marketing'  => false,
		) );

		$this->assertTrue( wp_has_consent( 'functional' ), 'functional deve essere sempre allow.' );
		$this->assertTrue( wp_has_consent( 'statistics' ) );
		$this->assertFalse( wp_has_consent( 'marketing' ) );
	}

	/**
	 * Senza WP Consent API, propagate_consent non deve fare nulla né crashare.
	 */
	public function test_propagate_noop_without_consent_api(): void {
		dbcm_test_set_consent_api( false );
		// Non deve sollevare errori.
		DBCM_Consent_API::propagate_consent( array( 'statistics' => true ) );
		$this->assertTrue( true );
	}

	/* =====================================================================
	 * declare_consent_type() — cortesia verso altri consent manager
	 * ================================================================== */

	/**
	 * Se un altro plugin ha già dichiarato un tipo di consenso, DBCM non lo
	 * sovrascrive (rispetta l'ordine di caricamento).
	 */
	public function test_declare_consent_type_respects_existing(): void {
		$this->assertSame(
			'altro-manager',
			DBCM_Consent_API::declare_consent_type( 'altro-manager' ),
			'Non deve sovrascrivere un tipo già dichiarato.'
		);
	}

	/**
	 * Se nessuno ha dichiarato nulla, DBCM dichiara 'optin' (GDPR).
	 */
	public function test_declare_consent_type_defaults_optin(): void {
		$this->assertSame( 'optin', DBCM_Consent_API::declare_consent_type( '' ) );
		$this->assertSame( 'optin', DBCM_Consent_API::declare_consent_type( null ) );
	}

	/* =====================================================================
	 * Versione del consenso (3.5.0) — hydrate al mismatch
	 * ================================================================== */

	/**
	 * HYDRATE al mismatch: non basta ignorare il cookie stantio — la WP
	 * Consent API ha i SUOI cookie che terrebbero vivo il consenso pre-bump
	 * per gli altri plugin. hydrate deve propagare un deny esplicito su
	 * tutte le categorie opzionali (functional resta allow).
	 */
	public function test_hydrate_resets_wp_consent_api_on_stale_version(): void {
		dbcm_test_set_consent_api( true );
		// Stato pre-bump nella WP Consent API: statistics era concesso.
		wp_set_consent( 'statistics', 'allow' );

		update_option( DBCM_Settings::OPTION_KEY, array( 'consent_version' => 2 ) );
		dbcm_test_set_consent_cookie( array( 'statistics' => true, 'cv' => 1 ) );

		DBCM_Consent_API::hydrate_consent_from_cookie();

		$this->assertFalse( wp_has_consent( 'statistics' ), 'Il consenso stantio deve essere resettato a deny.' );
		$this->assertTrue( wp_has_consent( 'functional' ), 'functional resta sempre allow.' );
	}

	/**
	 * HYDRATE con versione corrispondente: propaga normalmente le scelte.
	 */
	public function test_hydrate_propagates_when_version_matches(): void {
		dbcm_test_set_consent_api( true );
		update_option( DBCM_Settings::OPTION_KEY, array( 'consent_version' => 2 ) );
		dbcm_test_set_consent_cookie( array( 'statistics' => true, 'marketing' => false, 'cv' => 2 ) );

		DBCM_Consent_API::hydrate_consent_from_cookie();

		$this->assertTrue( wp_has_consent( 'statistics' ) );
		$this->assertFalse( wp_has_consent( 'marketing' ) );
	}

	/**
	 * HYDRATE senza cookie: non deve propagare nulla (né crashare).
	 */
	public function test_hydrate_noop_without_cookie(): void {
		dbcm_test_set_consent_api( true );
		DBCM_Consent_API::hydrate_consent_from_cookie();
		$this->assertFalse( wp_has_consent( 'statistics' ), 'Senza cookie lo stato resta il default (deny).' );
	}
}
