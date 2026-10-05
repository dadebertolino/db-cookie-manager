<?php
/**
 * Test unit di DBCM_Blocker — Consent Mode v2 base/avanzato (3.9.0) e fonti
 * regex delle firme personalizzate.
 *
 * Regola: con Consent Mode in modalità base (default) i tag Google restano
 * bloccati fino al consenso; solo con la modalità avanzata, attivata
 * esplicitamente e con GCM attivo, gtag.js e gtm.js passano intatti. Gli
 * altri tracker restano bloccati in ogni caso.
 *
 * @package DBCM\Tests
 */

use PHPUnit\Framework\TestCase;

final class BlockerConsentModeTest extends TestCase {

	/** Snippet ufficiale di Google Tag Manager (inline). */
	const GTM_INLINE = "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src='https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);})(window,document,'script','dataLayer','GTM-TEST000');</script>";

	/** Loader gtag.js di GA4. */
	const GTAG_SRC = '<script async src="https://www.googletagmanager.com/gtag/js?id=G-TEST0000000"></script>';

	/** Meta Pixel incollato a mano nel tema (non il modulo nativo). */
	const META_PIXEL = '<script async src="https://connect.facebook.net/en_US/fbevents.js"></script>';

	protected function setUp(): void {
		dbcm_test_reset();
		dbcm_test_reset_consent();
		self::flush_blocker_cache();
		DBCM_Declared_Services::reset_request_cache();
	}

	protected function tearDown(): void {
		dbcm_test_reset();
		dbcm_test_reset_consent();
		self::flush_blocker_cache();
	}

	/**
	 * Svuota la cache dei pattern del blocker (proprietà statica privata).
	 */
	private static function flush_blocker_cache() {
		$ref = new ReflectionProperty( 'DBCM_Blocker', 'patterns_cache' );
		if ( PHP_VERSION_ID < 80100 ) {
			$ref->setAccessible( true );
		}
		$ref->setValue( null, null );
	}

	/**
	 * @param array $settings
	 */
	private function settings( array $settings ) {
		update_option( 'dbcm_settings', $settings );
	}

	private function assertBlocked( $out, $category, $message = '' ) {
		$this->assertStringContainsString( 'type="text/plain"', $out, $message );
		$this->assertStringContainsString( 'data-dbcm-category="' . $category . '"', $out, $message );
	}

	private function assertIntact( $in, $out, $message = '' ) {
		$this->assertSame( $in, $out, $message );
	}

	/* ------------------------------------------------------------------
	 * Modalità base (default): Google bloccato fino al consenso.
	 * --------------------------------------------------------------- */

	public function test_gcm_off_blocks_gtm_and_gtag(): void {
		$this->assertBlocked( DBCM_Blocker::process_buffer( self::GTM_INLINE ), 'statistics', 'GTM inline senza GCM.' );
		$this->assertBlocked( DBCM_Blocker::process_buffer( self::GTAG_SRC ), 'statistics', 'gtag.js senza GCM.' );
	}

	public function test_gcm_on_basic_mode_still_blocks_google_tags(): void {
		$this->settings( array( 'gcm_enabled' => true ) );

		$this->assertBlocked( DBCM_Blocker::process_buffer( self::GTM_INLINE ), 'statistics', 'Modalità base: GTM bloccato.' );
		$this->assertBlocked( DBCM_Blocker::process_buffer( self::GTAG_SRC ), 'statistics', 'Modalità base: gtag.js bloccato.' );
	}

	public function test_advanced_without_gcm_has_no_effect(): void {
		$this->settings( array( 'gcm_enabled' => false, 'gcm_advanced' => true ) );

		$this->assertBlocked( DBCM_Blocker::process_buffer( self::GTAG_SRC ), 'statistics' );
	}

	/* ------------------------------------------------------------------
	 * Modalità avanzata: Google passa, il resto no.
	 * --------------------------------------------------------------- */

	public function test_advanced_mode_lets_google_tags_through(): void {
		$this->settings( array( 'gcm_enabled' => true, 'gcm_advanced' => true ) );

		$this->assertIntact( self::GTM_INLINE, DBCM_Blocker::process_buffer( self::GTM_INLINE ), 'GTM inline intatto.' );
		$this->assertIntact( self::GTAG_SRC, DBCM_Blocker::process_buffer( self::GTAG_SRC ), 'gtag.js intatto.' );
	}

	public function test_advanced_mode_filter_script_tag_keeps_enqueued_gtag(): void {
		$this->settings( array( 'gcm_enabled' => true, 'gcm_advanced' => true ) );

		$src = 'https://www.googletagmanager.com/gtag/js?id=G-TEST0000000';
		$tag = '<script src="' . $src . '" id="gtag-js"></script>';
		$this->assertSame( $tag, DBCM_Blocker::filter_script_tag( $tag, 'gtag', $src ) );
	}

	public function test_advanced_mode_keeps_third_party_meta_pixel_blocked(): void {
		$this->settings( array( 'gcm_enabled' => true, 'gcm_advanced' => true ) );

		$this->assertBlocked( DBCM_Blocker::process_buffer( self::META_PIXEL ), 'marketing', 'Regressione: il Meta Pixel di terzi resta bloccato.' );
	}

	public function test_advanced_mode_keeps_non_google_statistics_blocked(): void {
		$this->settings( array( 'gcm_enabled' => true, 'gcm_advanced' => true ) );

		$this->assertBlocked(
			DBCM_Blocker::process_buffer( '<script src="https://static.hotjar.com/c/hotjar-1.js"></script>' ),
			'statistics',
			'Hotjar non è un tag Google: resta bloccato.'
		);
	}

	public function test_advanced_mode_inline_with_google_and_pixel_is_blocked(): void {
		$this->settings( array( 'gcm_enabled' => true, 'gcm_advanced' => true ) );

		$mixed = "<script>j.src='https://www.googletagmanager.com/gtm.js?id=GTM-X';t.src='https://connect.facebook.net/en_US/fbevents.js';</script>";
		$this->assertBlocked( DBCM_Blocker::process_buffer( $mixed ), 'marketing', 'L\'esenzione Google non sblocca un pixel nello stesso script.' );
	}

	public function test_advanced_mode_still_declares_google_service(): void {
		$this->settings( array( 'gcm_enabled' => true, 'gcm_advanced' => true ) );

		DBCM_Blocker::process_buffer( self::GTAG_SRC );

		$declared = get_option( DBCM_Declared_Services::OPTION, array() );
		$this->assertArrayHasKey( 'google-analytics', $declared['auto'] ?? array(), 'GA4 resta dichiarato nella Cookie Policy.' );
	}

	/* ------------------------------------------------------------------
	 * Fonti regex delle firme personalizzate (bug fino alla 3.8.x: il
	 * blocker le confrontava come testo letterale).
	 * --------------------------------------------------------------- */

	/**
	 * @param string $source
	 */
	private function custom_regex_signature( $source ) {
		update_option(
			'dbcm_custom_signatures',
			array(
				array(
					'service'          => 'Pixel Regex',
					'category'         => 'marketing',
					'requires_consent' => true,
					'block_source'     => $source,
					'block_is_regex'   => true,
				),
			)
		);
		DBCM_Signatures::flush_cache();
		DBCM_Signatures::init();
		self::flush_blocker_cache();
	}

	public function test_regex_without_delimiters_blocks(): void {
		$this->custom_regex_signature( '^https://pixel\.example\.' );

		$this->assertBlocked( DBCM_Blocker::process_buffer( '<script src="https://pixel.example.com/p.js"></script>' ), 'marketing' );
	}

	public function test_regex_with_delimiters_blocks(): void {
		$this->custom_regex_signature( '/pixel\.example\.(com|net)/i' );

		$this->assertBlocked( DBCM_Blocker::process_buffer( '<script src="https://cdn.PIXEL.example.net/p.js"></script>' ), 'marketing' );
	}

	public function test_regex_does_not_overmatch(): void {
		$this->custom_regex_signature( '^https://pixel\.example\.' );

		$in = '<script src="https://cdn.example.com/pixel.example.js"></script>';
		$this->assertIntact( $in, DBCM_Blocker::process_buffer( $in ), 'Ancoraggio ^ rispettato: nessun falso positivo.' );
	}

	public function test_invalid_regex_degrades_to_substring_without_errors(): void {
		$this->custom_regex_signature( 'pixel.example([' );

		$this->assertBlocked( DBCM_Blocker::process_buffer( '<script src="https://x.test/pixel.example([.js"></script>' ), 'marketing' );
		$in = '<script src="https://other.test/a.js"></script>';
		$this->assertIntact( $in, DBCM_Blocker::process_buffer( $in ) );
	}
}
