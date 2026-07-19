<?php
/**
 * DBCM_Meta_Pixel — Modulo Meta Pixel nativo con fire condizionato al consenso.
 *
 * Razionale (v3.7.0): il plugin che possiede il consenso possiede anche il
 * pixel → gating by-design. Lo snippet Meta non viene MAI emesso come tag
 * eseguibile "nudo": è wrappato in un gate JS che carica fbevents.js solo se
 * la categoria 'marketing' risulta concessa (art. 6.1.a + art. 7 GDPR), e
 * reagisce all'evento 'dbcm:consent' per partire al momento dell'accettazione
 * senza reload. Alla revoca invia fbq('consent','revoke') a runtime (art. 7.3:
 * revocare deve essere facile quanto concedere); la cancellazione del cookie
 * _fbp è garantita dalla cancellazione reattiva già esistente (la firma
 * 'meta-pixel' è inclusa in DBCM_Signatures::reactive_cleanup_list()).
 *
 * Privacy by default (art. 25): modulo OFF di default; nessun output finché
 * l'amministratore non attiva il toggle E inserisce un Pixel ID valido.
 *
 * Trasparenza (art. 13 + art. 26): quando attivo, il modulo registra lo slug
 * 'meta-pixel' nel registro dei servizi dichiarati (il nostro script non passa
 * mai dal blocker come "bloccato", quindi record_from_url() non scatterebbe) e
 * aggiunge la riga di trattamento in contitolarità con Meta al filter
 * 'dbph_processing_register' (via DBCM_Privacy_Declarations).
 *
 * Auto-blocco evitato: il gate contiene le stringhe 'connect.facebook.net' e
 * 'fbevents.js' che matcherebbero i pattern del blocker sul contenuto inline.
 * Il tag è quindi marcato con data-dbcm-own="1", che il blocker salta
 * esplicitamente (vedi DBCM_Blocker::process_script_match). Non è un bypass di
 * sicurezza: chiunque possa stampare <script> in pagina controlla già il sito.
 *
 * Handoff CAPI (Intervento 2): se attivo, il PageView è inviato con un
 * eventID condiviso esposto in window.DBCM_META_LAST_EVENT_ID, per la
 * deduplicazione con la futura Conversions API server-side (DB Meta Events).
 *
 * @package DBCM
 * @since   3.7.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'DBCM_Meta_Pixel' ) ) {

	class DBCM_Meta_Pixel {

		/**
		 * Slug della firma nel DB firme (fonte unica di verità per
		 * provider, privacy_url, cookie e categoria).
		 */
		const SLUG = 'meta-pixel';

		/**
		 * Inizializzazione — chiamata da DBCM_Plugin->init_modules().
		 *
		 * @return void
		 */
		public static function init() {
			if ( ! self::is_active() ) {
				return;
			}

			// Registro servizi dichiarati: anche in admin (la policy si
			// genera lì). Priorità 10, dopo la costruzione base.
			add_filter( 'dbcm_declared_services_register', array( __CLASS__, 'register_declared_service' ) );

			// Snippet gated: solo frontend, dopo i default dei consent
			// signals (prio 1) e dopo il grosso di wp_head.
			if ( ! is_admin() ) {
				add_action( 'wp_head', array( __CLASS__, 'print_snippet' ), 20 );
			}
		}

		/* =====================================================================
		 * STATO / IMPOSTAZIONI
		 * ================================================================== */

		/**
		 * True se il toggle è attivo nelle impostazioni.
		 *
		 * @return bool
		 */
		public static function is_enabled() {
			return (bool) DBCM_Settings::get( 'meta_pixel_enabled', false );
		}

		/**
		 * True se il modulo è operativo: toggle ON e Pixel ID valido.
		 *
		 * @return bool
		 */
		public static function is_active() {
			return self::is_enabled() && '' !== self::get_pixel_id();
		}

		/**
		 * Pixel ID validato (15–16 cifre) o stringa vuota.
		 *
		 * Ri-sanitizza in lettura (difesa in profondità: l'option potrebbe
		 * essere stata scritta fuori dal flusso admin).
		 *
		 * @return string
		 */
		public static function get_pixel_id() {
			return self::sanitize_pixel_id( DBCM_Settings::get( 'meta_pixel_id', '' ) );
		}

		/**
		 * True se l'handoff dell'event_id per la CAPI è attivo.
		 *
		 * @return bool
		 */
		public static function capi_handoff_enabled() {
			return (bool) DBCM_Settings::get( 'meta_pixel_capi_handoff', false );
		}

		/**
		 * Sanitizza un Pixel ID: solo cifre, lunghezza 15–16, altrimenti ''.
		 *
		 * Usata sia dal dispatcher di salvataggio admin sia dal getter.
		 *
		 * @param mixed $raw
		 * @return string ID valido o stringa vuota.
		 */
		public static function sanitize_pixel_id( $raw ) {
			if ( ! is_string( $raw ) && ! is_numeric( $raw ) ) {
				return '';
			}
			$digits = preg_replace( '/[^0-9]/', '', (string) $raw );
			$len    = strlen( $digits );
			return ( $len >= 15 && $len <= 16 ) ? $digits : '';
		}

		/* =====================================================================
		 * SERVIZI DICHIARATI — la policy deve dichiarare il pixel anche se
		 * il nostro script non passa mai dal blocker.
		 * ================================================================== */

		/**
		 * Aggiunge la voce 'meta-pixel' al registro raggruppato, idratandola
		 * dalla firma statica (fonte unica di verità). Non duplica se una
		 * voce con lo stesso slug è già presente (es. inserita a mano o
		 * registrata da un pixel di terzi bloccato).
		 *
		 * @param array $grouped categoria => voci normalizzate.
		 * @return array
		 */
		public static function register_declared_service( $grouped ) {
			if ( ! is_array( $grouped ) ) {
				$grouped = array();
			}
			if ( ! class_exists( 'DBCM_Signatures' ) ) {
				return $grouped;
			}

			// Dedup per slug su tutte le categorie.
			foreach ( $grouped as $entries ) {
				foreach ( (array) $entries as $entry ) {
					if ( isset( $entry['slug'] ) && self::SLUG === strtolower( (string) $entry['slug'] ) ) {
						return $grouped;
					}
				}
			}

			$all = DBCM_Signatures::all();
			if ( ! isset( $all[ self::SLUG ] ) ) {
				return $grouped;
			}
			$sig = $all[ self::SLUG ];

			$cookies  = array();
			$duration = array();
			foreach ( ( isset( $sig['cookies'] ) ? (array) $sig['cookies'] : array() ) as $cookie ) {
				if ( ! empty( $cookie['name'] ) ) {
					$cookies[] = $cookie['name'];
				}
				if ( ! empty( $cookie['duration'] ) ) {
					$duration[] = $cookie['duration'];
				}
			}

			$category = isset( $sig['category'] ) ? $sig['category'] : 'marketing';

			$grouped[ $category ][] = array(
				'id'            => 'module:' . self::SLUG,
				'origin'        => 'module',
				'slug'          => self::SLUG,
				'service'       => isset( $sig['service'] ) ? $sig['service'] : self::SLUG,
				'provider'      => isset( $sig['provider'] ) ? $sig['provider'] : '',
				'category'      => $category,
				'policy_url'    => isset( $sig['privacy_url'] ) ? $sig['privacy_url'] : '',
				'cookies_text'  => implode( ', ', array_unique( $cookies ) ),
				'duration_text' => implode( ' / ', array_unique( $duration ) ),
				'last_seen'     => gmdate( 'Y-m-d' ),
			);
			ksort( $grouped );

			return $grouped;
		}

		/* =====================================================================
		 * SNIPPET GATED
		 * ================================================================== */

		/**
		 * Emette il gate JS del Meta Pixel in wp_head.
		 *
		 * Il tag porta data-dbcm-own="1" (skip esplicito del blocker) e NON
		 * carica nulla finché window.DBCM.hasConsent('marketing') non è true:
		 *  - check iniziale a DOMContentLoaded (banner.js è in footer, quindi
		 *    a quel punto l'API pubblica esiste ed è già version-aware: un
		 *    consenso con consent_version obsoleta risulta non concesso);
		 *  - listener su 'dbcm:consent' per partire all'accettazione senza
		 *    reload e per inviare fbq('consent','revoke') alla revoca.
		 *
		 * @return void
		 */
		public static function print_snippet() {
			$pixel_id = self::get_pixel_id();
			if ( '' === $pixel_id ) {
				return;
			}
			$handoff = self::capi_handoff_enabled() ? 'true' : 'false';
			?>
<script id="dbcm-meta-pixel" data-dbcm-own="1">
(function () {
	'use strict';
	var PIXEL_ID = '<?php echo esc_js( $pixel_id ); ?>';
	var CAPI_HANDOFF = <?php echo $handoff; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literale bool controllato. ?>;
	var loaded = false;

	function loadPixel() {
		if (loaded) { return; }
		loaded = true;
		/* Snippet ufficiale Meta (fbevents.js). Caricato SOLO post-consenso. */
		!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
		n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
		n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
		t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}
		(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');

		window.fbq('consent', 'grant');
		window.fbq('init', PIXEL_ID);

		if (CAPI_HANDOFF) {
			/* event_id condiviso per la deduplicazione con la CAPI (Intervento 2). */
			var eid = 'pv_' + Date.now() + '_' + Math.random().toString(36).slice(2, 10);
			window.DBCM_META_LAST_EVENT_ID = eid;
			window.fbq('track', 'PageView', {}, { eventID: eid });
		} else {
			window.fbq('track', 'PageView');
		}
	}

	function marketingGranted() {
		return !!(window.DBCM && window.DBCM.hasConsent && window.DBCM.hasConsent('marketing'));
	}

	/* Consenso già salvato (reload): banner.js è in footer → check a DOMContentLoaded. */
	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', function () {
			if (marketingGranted()) { loadPixel(); }
		});
	} else if (marketingGranted()) {
		loadPixel();
	}

	/* Cambio consenso a runtime (accettazione o revoca, senza reload). */
	document.addEventListener('dbcm:consent', function (ev) {
		var c = ev.detail && ev.detail.consent;
		if (!c) { return; }
		if (c.marketing) {
			if (loaded && window.fbq) { window.fbq('consent', 'grant'); }
			loadPixel();
		} else if (loaded && window.fbq) {
			/* Art. 7.3: la revoca ha effetto immediato, non al prossimo reload.
				La rimozione di _fbp è gestita dalla cancellazione reattiva. */
			window.fbq('consent', 'revoke');
		}
	});
})();
</script>
			<?php
		}
	}
}
