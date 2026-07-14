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
	}

	protected function tearDown(): void {
		dbcm_test_reset_consent();
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
}
