<?php
/**
 * DBCM_Banner — Rendering del banner cookie sul frontend.
 *
 * Responsabilità:
 *  - Decidere se mostrare il banner (cookie già presente? geo? DNT/GPC?).
 *  - Enqueue di banner.js e banner.css.
 *  - Localize della config: categorie, traduzioni, nonce, AJAX URL.
 *  - Render del markup HTML (banner principale + modal preferenze).
 *
 * Non gestisce:
 *  - La scrittura del cookie (la fa banner.js).
 *  - La propagazione a WP Consent API (la fa DBCM_Consent_API via AJAX).
 *  - Il blocco preventivo degli script (lo farà DBCM_Blocker — step 3).
 *
 * @package DBCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DBCM_Banner' ) ) {

	class DBCM_Banner {

		/**
		 * Inizializzazione — chiamata da DBCM_Plugin->init_modules().
		 */
		public static function init() {
			if ( is_admin() ) {
				return;
			}
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
			add_action( 'wp_footer', array( __CLASS__, 'render' ), 5 );
		}

		/**
		 * Decide se il banner deve essere mostrato a questa richiesta.
		 *
		 * Punti decisionali:
		 *  - Banner disabilitato in settings → no.
		 *  - geo_targeting=true e visitatore fuori UE/EEA/UK → no.
		 *  - Filtro dbcm_should_render_banner restituisce false → no.
		 *
		 * 3.8.0 — il cookie di consenso NON è più letto qui: il valore finisce
		 * nella config JS (autoOpen) e quindi nell'HTML, che una cache di
		 * pagina servirebbe a tutti. Se il visitatore ha già scelto lo decide
		 * banner.js in boot() leggendo il proprio cookie (readCookie()).
		 * Il geo-targeting (opt-in, default off) resta server-side ed è
		 * dichiarato incompatibile con la cache di pagina completa (vedi
		 * Avanzate → Geo-targeting e README).
		 *
		 * GPC e DNT non sono gestiti qui ma lato JS al boot (vedi banner.js):
		 * il banner viene reso ma JS scrive subito un cookie "reject_all"
		 * e nasconde il banner senza interazione utente. Questo permette
		 * il logging del consenso "rifiutato per GPC/DNT" ai fini di prova.
		 *
		 * @return bool
		 */
		public static function should_render() {
			if ( ! DBCM_Settings::get( 'banner_enabled', true ) ) {
				return false;
			}

			// Geo-targeting: se attivo, mostra solo a UE/EEA/UK.
			if ( DBCM_Settings::get( 'geo_targeting', false ) && ! self::is_eu_visitor() ) {
				return false;
			}

			/**
			 * Permette ad altri plugin/temi di sopprimere il banner
			 * (es. su pagine specifiche).
			 *
			 * @param bool $render
			 */
			return (bool) apply_filters( 'dbcm_should_render_banner', true );
		}

		/**
		 * Determina se il visitatore corrente è UE/EEA/UK.
		 *
		 * Strategia (in ordine di affidabilità):
		 *  1. Header CF-IPCountry (Cloudflare, ISO-3166 a 2 lettere) — affidabile.
		 *  2. Header CloudFront-Viewer-Country (AWS) — affidabile.
		 *  3. Filtro 'dbcm_visitor_country_code' — chi usa MaxMind o GeoIP locale
		 *     può fornire il codice via filtro.
		 *  4. Fallback debole su Accept-Language (es. "it-IT" → IT) — molto
		 *     impreciso ma meglio di niente quando nessuna geolocation è disponibile.
		 *
		 * Default in caso di rilevamento fallito: true (mostra il banner).
		 * Meglio mostrare il banner a qualcuno fuori UE che nasconderlo a
		 * un utente UE — il rischio legale è asimmetrico.
		 *
		 * @return bool
		 */
		private static function is_eu_visitor() {
			$country = '';

			// 1. Cloudflare. 'XX' (paese sconosciuto) e 'T1' (rete Tor) non
			// sono paesi: valgono come "non rilevato" (3.8.2; prima nascondevano
			// il banner come a un visitatore extra UE).
			if ( ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
				$country = strtoupper( substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ), 0, 2 ) );
				if ( in_array( $country, array( 'XX', 'T1' ), true ) ) {
					$country = '';
				}
			}

			// 2. CloudFront.
			if ( '' === $country && ! empty( $_SERVER['HTTP_CLOUDFRONT_VIEWER_COUNTRY'] ) ) {
				$country = strtoupper( substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_CLOUDFRONT_VIEWER_COUNTRY'] ) ), 0, 2 ) );
			}

			// 3. Filtro per integrazione GeoIP locale.
			$country = (string) apply_filters( 'dbcm_visitor_country_code', $country );
			$country = strtoupper( substr( $country, 0, 2 ) );

			// 4. Fallback su Accept-Language (debole).
			if ( '' === $country && ! empty( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
				$lang = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) );
				if ( preg_match( '/[a-z]{2}-([A-Z]{2})/', $lang, $m ) ) {
					$country = strtoupper( $m[1] );
				}
			}

			// Se ancora niente, default permissivo (mostra banner).
			if ( '' === $country ) {
				return true;
			}

			return in_array( $country, self::eu_country_codes(), true );
		}

		/**
		 * Codici ISO-3166 alpha-2 dei paesi UE/EEA/UK + filtri.
		 *
		 * @return array
		 */
		private static function eu_country_codes() {
			$codes = array(
				// EU 27.
				'AT',
				'BE',
				'BG',
				'HR',
				'CY',
				'CZ',
				'DK',
				'EE',
				'FI',
				'FR',
				'DE',
				'GR',
				'HU',
				'IE',
				'IT',
				'LV',
				'LT',
				'LU',
				'MT',
				'NL',
				'PL',
				'PT',
				'RO',
				'SK',
				'SI',
				'ES',
				'SE',
				// EEA non UE.
				'IS',
				'LI',
				'NO',
				// UK (post-Brexit GDPR-equivalent).
				'GB',
			);
			/**
			 * Permette di personalizzare la lista dei paesi che mostrano il
			 * banner quando geo_targeting è attivo. Esempio: aggiungere CH
			 * (Svizzera) o rimuovere GB.
			 *
			 * @param array $codes
			 */
			return (array) apply_filters( 'dbcm_eu_country_codes', $codes );
		}

		/* =====================================================================
		 * Asset enqueue
		 * ================================================================== */

		public static function enqueue_assets() {
			// CSS del banner — sempre caricato (anche quando il banner non
			// si mostra, perché il pulsante "Riapri preferenze" può essere
			// presente comunque).
			wp_enqueue_style(
				'dbcm-banner',
				DBCM_URL . 'assets/css/banner.css',
				array(),
				DBCM_VERSION
			);

			// JS del banner.
			wp_enqueue_script(
				'dbcm-banner',
				DBCM_URL . 'assets/js/banner.js',
				array(),
				DBCM_VERSION,
				true // in footer
			);

			// 3.8.0: applica le impostazioni di aspetto (colori, tema auto,
			// CSS personalizzato), prima salvate ma mai emesse.
			$inline_css = self::build_inline_css();
			if ( '' !== $inline_css ) {
				wp_add_inline_style( 'dbcm-banner', $inline_css );
			}

			wp_localize_script( 'dbcm-banner', 'dbcmBanner', self::build_config() );
		}

		/**
		 * CSS inline generato dalle impostazioni "Tema & colori" e "CSS
		 * personalizzato".
		 *
		 * - Bottone primario/link: --dbcm-primary, --dbcm-primary-h (versione
		 *   scurita) e --dbcm-on-primary valgono per ogni tema.
		 * - Sfondo/testo: --dbcm-bg e --dbcm-text valgono per il tema chiaro
		 *   e per "auto" in modalità chiara; il tema scuro mantiene la sua
		 *   palette (i colori personalizzati sono pensati per il chiaro).
		 * - Tema "auto": con prefers-color-scheme: dark usa la palette scura
		 *   (stessi valori di [data-theme="dark"] in banner.css).
		 * - CSS personalizzato: già privato dei tag al salvataggio; qui
		 *   ripassato da wp_strip_all_tags() e privato di '</' per non poter
		 *   chiudere il blocco <style>.
		 *
		 * @since 3.8.0
		 * @return string
		 */
		private static function build_inline_css() {
			$s    = DBCM_Settings::all();
			$bg   = sanitize_hex_color( (string) $s['banner_color_bg'] );
			$text = sanitize_hex_color( (string) $s['banner_color_text'] );
			$btn  = sanitize_hex_color( (string) $s['banner_color_btn'] );
			$on   = sanitize_hex_color( (string) $s['banner_color_btn_text'] );

			$css = '';

			$root_vars = '';
			if ( $btn ) {
				$root_vars .= '--dbcm-primary:' . $btn . ';--dbcm-primary-h:' . self::darken_hex( $btn, 0.15 ) . ';';
			}
			if ( $on ) {
				$root_vars .= '--dbcm-on-primary:' . $on . ';';
			}
			if ( '' !== $root_vars ) {
				$css .= '#dbcm-banner-root{' . $root_vars . '}';
			}

			$light_vars = '';
			if ( $bg ) {
				$light_vars .= '--dbcm-bg:' . $bg . ';';
			}
			if ( $text ) {
				$light_vars .= '--dbcm-text:' . $text . ';';
			}
			if ( '' !== $light_vars ) {
				$css .= '#dbcm-banner-root[data-theme="light"],#dbcm-banner-root[data-theme="auto"]{' . $light_vars . '}';
			}

			// Tema auto: palette scura quando il sistema la richiede. Stessa
			// specificità del blocco sopra ma dichiarata dopo → vince.
			$css .= '@media (prefers-color-scheme: dark){#dbcm-banner-root[data-theme="auto"]{--dbcm-bg:#1d2327;--dbcm-text:#f0f0f1;--dbcm-muted:#a7aaad;--dbcm-border:#3c434a;}}';

			$custom = trim( wp_strip_all_tags( (string) $s['banner_custom_css'] ) );
			if ( '' !== $custom ) {
				$css .= "\n" . str_replace( '</', '<\\/', $custom );
			}

			/**
			 * Filtra il CSS inline del banner (colori, tema auto, CSS custom).
			 *
			 * @since 3.8.0
			 * @param string $css
			 * @param array  $settings
			 */
			return (string) apply_filters( 'dbcm_banner_inline_css', $css, $s );
		}

		/**
		 * Scurisce un colore esadecimale di una frazione (0..1). Usato per lo
		 * stato hover del bottone primario (--dbcm-primary-h).
		 *
		 * @since 3.8.0
		 * @param string $hex    #rgb o #rrggbb (già validato).
		 * @param float  $amount Frazione di scurimento.
		 * @return string
		 */
		private static function darken_hex( $hex, $amount ) {
			$hex = ltrim( $hex, '#' );
			if ( 3 === strlen( $hex ) ) {
				$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
			}
			$out = '#';
			for ( $i = 0; $i < 3; $i++ ) {
				$c    = hexdec( substr( $hex, $i * 2, 2 ) );
				$c    = (int) max( 0, min( 255, round( $c * ( 1 - $amount ) ) ) );
				$out .= str_pad( dechex( $c ), 2, '0', STR_PAD_LEFT );
			}
			return $out;
		}

		/**
		 * Costruisce l'oggetto di configurazione passato a banner.js.
		 *
		 * @return array
		 */
		private static function build_config() {
			$s = DBCM_Settings::all();

			return array(
				/* ---- AJAX ---- */
				'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
				'nonce'             => wp_create_nonce( 'dbcm_consent_nonce' ),

				/* ---- Cookie ---- */
				'cookieName'        => DBCM_Settings::COOKIE_NAME,
				'cookieSchema'      => DBCM_Settings::COOKIE_SCHEMA_VERSION,
				'consentDuration'   => (int) $s['consent_duration'],
				/* Versione del consenso (3.5.0): banner.js la scrive nel
				 * campo 'cv' del cookie e invalida i cookie con versione
				 * diversa → l'utente rivede il banner dopo un bump admin. */
				'consentVersion'    => DBCM_Settings::consent_version(),

				/* ---- Categorie ----
				 * Sole 5 categorie standard WP Consent API. 'functional'
				 * è sempre true: il banner JS non deve nemmeno permettere
				 * di toccarla. */
				'categories'        => DBCM_Settings::categories(),
				'categoriesOptional' => DBCM_Settings::categories_optional(),

				/* ---- Default delle categorie opzionali ----
				 * Stato iniziale dei toggle nel modal "Personalizza".
				 * GDPR: tutti false. */
				'defaults'          => array(
					'functional'           => true,
					'preferences'          => (bool) $s['default_preferences'],
					'statistics'           => (bool) $s['default_statistics'],
					'statistics-anonymous' => (bool) $s['default_statistics_anonymous'],
					'marketing'            => (bool) $s['default_marketing'],
				),

				/* ---- Aspetto / posizione ---- */
				'layout'            => $s['banner_layout'],
				'position'          => $s['banner_position'],
				'overlay'           => (bool) $s['banner_overlay'],
				'theme'             => $s['banner_theme'],
				'showReopenBtn'     => (bool) $s['show_reopen_btn'],
				'reopenPosition'    => $s['reopen_position'],
				/* Credit "Powered by" (3.8.0: prima salvato ma mai mostrato). */
				'credits'           => (bool) $s['banner_credits'],
				'creditsUrl'        => 'https://www.davidebertolino.it/progetti/db-cookie-manager',

				/* ---- Lingue ---- */
				'activeLangs'       => array_values( (array) $s['banner_languages'] ),
				'defaultLang'       => $s['banner_default_lang'],
				'translations'      => self::translations(),

				/* ---- Cookie policy link ---- */
				'policyUrl'         => self::policy_url(),

				/* ---- Segnali browser (priorità 3 — saranno gestiti negli step successivi) ---- */
				'respectGpc'        => (bool) $s['respect_gpc'],
				'respectDnt'        => (bool) $s['respect_dnt'],

				/* ---- Cancellazione reattiva ----
				 * Lista { name, category } di cookie da rimuovere lato client
				 * quando manca il consenso della categoria (Art. 7(3), 17, 5(1)(e)).
				 * Esclude i tecnici per costruzione. name può contenere '*'. */
				'reactiveCleanup'   => DBCM_Signatures::reactive_cleanup_list(),

				/* ---- Google Consent Mode v2 ----
				 * Se attivo, banner.js invia gtag('consent','update',...) al
				 * commit, traducendo le categorie concesse nei segnali GCM
				 * secondo il mapping. Inerte se gcmEnabled = false. */
				'gcmEnabled'        => DBCM_Consent_Signals::is_enabled(),
				'gcmMapping'        => DBCM_Consent_Signals::is_enabled() ? DBCM_Consent_Signals::mapping() : array(),
				'gcmSignals'        => DBCM_Consent_Signals::SIGNALS,
				/* Microsoft UET Consent Mode e Clarity ConsentV2: stessi
				 * meccanismi di GCM (default denied nel <head>, update da
				 * banner.js secondo il mapping). Inerti se disattivati. */
				'uetEnabled'        => DBCM_Consent_Signals::uet_enabled(),
				'uetMapping'        => DBCM_Consent_Signals::uet_enabled() ? DBCM_Consent_Signals::uet_mapping() : array(),
				'uetSignals'        => DBCM_Consent_Signals::UET_SIGNALS,
				'clarityEnabled'    => DBCM_Consent_Signals::clarity_enabled(),
				'clarityMapping'    => DBCM_Consent_Signals::clarity_enabled() ? DBCM_Consent_Signals::clarity_mapping() : array(),
				'claritySignals'    => DBCM_Consent_Signals::CLARITY_SIGNALS,

				/* ---- Render decision ----
				 * Se false, il banner JS sa di non auto-mostrarsi (ma lascia
				 * comunque il pulsante "Riapri" disponibile). Indipendente dal
				 * cookie del visitatore (3.8.0, cache-safe): il consenso già
				 * espresso lo verifica banner.js in boot(). */
				'autoOpen'          => self::should_render(),
			);
		}

		/**
		 * URL della Cookie Policy se l'admin ha selezionato una pagina,
		 * stringa vuota altrimenti.
		 *
		 * @return string
		 */
		private static function policy_url() {
			$page_id = (int) DBCM_Settings::get( 'policy_page_id', 0 );
			if ( $page_id > 0 ) {
				$url = get_permalink( $page_id );
				return $url ? $url : '';
			}
			return '';
		}

		/**
		 * Traduzioni del banner per le 6 lingue precompilate.
		 *
		 * Sistema custom interno (non WP i18n) — coerente con lo standard
		 * DB Cookie Manager (vedi skill: "built-in multilingual support
		 * via settings with language tabs").
		 *
		 * Le label sono allineate alle 5 categorie WP Consent API standard.
		 *
		 * @return array
		 */
		private static function translations() {
			$base = array(
				'it' => array(
					'title'        => 'Rispettiamo la tua privacy',
					'message'      => 'Usiamo cookie tecnici per il funzionamento del sito e, con il tuo consenso, cookie di preferenze, statistiche e marketing.',
					'accept_all'   => 'Accetta tutto',
					'reject_all'   => 'Rifiuta',
					'customize'    => 'Personalizza',
					'save'         => 'Salva preferenze',
					'reopen'       => 'Modifica preferenze',
					'policy_link'  => 'Cookie Policy',
					'cat_functional'           => 'Tecnici',
					'cat_functional_desc'      => 'Necessari al funzionamento del sito. Sempre attivi.',
					'cat_preferences'          => 'Preferenze',
					'cat_preferences_desc'     => 'Memorizzano scelte come lingua o regione per migliorare l\'esperienza.',
					'cat_statistics'           => 'Statistiche',
					'cat_statistics_desc'      => 'Misurano l\'uso del sito con identificatori personali (es. Google Analytics).',
					'cat_statistics-anonymous' => 'Statistiche anonime',
					'cat_statistics-anonymous_desc' => 'Misurano l\'uso in forma aggregata, senza identificare l\'utente.',
					'cat_marketing'            => 'Marketing',
					'cat_marketing_desc'       => 'Tracciamento pubblicitario, retargeting e social plugin.',
				),
				'en' => array(
					'title'        => 'We respect your privacy',
					'message'      => 'We use technical cookies to make the site work and, with your consent, preference, analytics, and marketing cookies.',
					'accept_all'   => 'Accept all',
					'reject_all'   => 'Reject',
					'customize'    => 'Customize',
					'save'         => 'Save preferences',
					'reopen'       => 'Change preferences',
					'policy_link'  => 'Cookie Policy',
					'cat_functional'           => 'Functional',
					'cat_functional_desc'      => 'Required for the site to work. Always active.',
					'cat_preferences'          => 'Preferences',
					'cat_preferences_desc'     => 'Remember choices like language or region to improve your experience.',
					'cat_statistics'           => 'Statistics',
					'cat_statistics_desc'      => 'Measure site usage with personal identifiers (e.g. Google Analytics).',
					'cat_statistics-anonymous' => 'Anonymous statistics',
					'cat_statistics-anonymous_desc' => 'Measure aggregate site usage without identifying the user.',
					'cat_marketing'            => 'Marketing',
					'cat_marketing_desc'       => 'Advertising tracking, retargeting, and social plugins.',
				),
				'fr' => array(
					'title'        => 'Nous respectons votre vie privée',
					'message'      => 'Nous utilisons des cookies techniques pour faire fonctionner le site et, avec votre consentement, des cookies de préférences, statistiques et marketing.',
					'accept_all'   => 'Tout accepter',
					'reject_all'   => 'Refuser',
					'customize'    => 'Personnaliser',
					'save'         => 'Enregistrer',
					'reopen'       => 'Modifier les préférences',
					'policy_link'  => 'Politique des cookies',
					'cat_functional'           => 'Techniques',
					'cat_functional_desc'      => 'Nécessaires au fonctionnement du site. Toujours actifs.',
					'cat_preferences'          => 'Préférences',
					'cat_preferences_desc'     => 'Mémorisent vos choix (langue, région) pour améliorer l\'expérience.',
					'cat_statistics'           => 'Statistiques',
					'cat_statistics_desc'      => 'Mesurent l\'utilisation du site avec des identifiants personnels.',
					'cat_statistics-anonymous' => 'Statistiques anonymes',
					'cat_statistics-anonymous_desc' => 'Mesurent l\'utilisation de manière agrégée, sans identifier l\'utilisateur.',
					'cat_marketing'            => 'Marketing',
					'cat_marketing_desc'       => 'Suivi publicitaire, retargeting et plugins sociaux.',
				),
				'de' => array(
					'title'        => 'Wir respektieren Ihre Privatsphäre',
					'message'      => 'Wir verwenden technische Cookies für die Funktion der Website und, mit Ihrer Einwilligung, Cookies für Präferenzen, Statistiken und Marketing.',
					'accept_all'   => 'Alle akzeptieren',
					'reject_all'   => 'Ablehnen',
					'customize'    => 'Anpassen',
					'save'         => 'Einstellungen speichern',
					'reopen'       => 'Einstellungen ändern',
					'policy_link'  => 'Cookie-Richtlinie',
					'cat_functional'           => 'Technisch',
					'cat_functional_desc'      => 'Erforderlich für die Funktion der Website. Immer aktiv.',
					'cat_preferences'          => 'Präferenzen',
					'cat_preferences_desc'     => 'Speichern Auswahlen wie Sprache oder Region.',
					'cat_statistics'           => 'Statistiken',
					'cat_statistics_desc'      => 'Messen die Nutzung der Website mit persönlichen Identifikatoren.',
					'cat_statistics-anonymous' => 'Anonyme Statistiken',
					'cat_statistics-anonymous_desc' => 'Messen die Nutzung aggregiert, ohne den Benutzer zu identifizieren.',
					'cat_marketing'            => 'Marketing',
					'cat_marketing_desc'       => 'Werbe-Tracking, Retargeting und Social-Plugins.',
				),
				'es' => array(
					'title'        => 'Respetamos tu privacidad',
					'message'      => 'Usamos cookies técnicas para el funcionamiento del sitio y, con tu consentimiento, cookies de preferencias, estadísticas y marketing.',
					'accept_all'   => 'Aceptar todo',
					'reject_all'   => 'Rechazar',
					'customize'    => 'Personalizar',
					'save'         => 'Guardar preferencias',
					'reopen'       => 'Cambiar preferencias',
					'policy_link'  => 'Política de cookies',
					'cat_functional'           => 'Técnicas',
					'cat_functional_desc'      => 'Necesarias para el funcionamiento del sitio. Siempre activas.',
					'cat_preferences'          => 'Preferencias',
					'cat_preferences_desc'     => 'Recuerdan elecciones como idioma o región.',
					'cat_statistics'           => 'Estadísticas',
					'cat_statistics_desc'      => 'Miden el uso del sitio con identificadores personales.',
					'cat_statistics-anonymous' => 'Estadísticas anónimas',
					'cat_statistics-anonymous_desc' => 'Miden el uso de forma agregada, sin identificar al usuario.',
					'cat_marketing'            => 'Marketing',
					'cat_marketing_desc'       => 'Seguimiento publicitario, retargeting y plugins sociales.',
				),
				'pt' => array(
					'title'        => 'Respeitamos a sua privacidade',
					'message'      => 'Usamos cookies técnicos para o funcionamento do site e, com o seu consentimento, cookies de preferências, estatísticas e marketing.',
					'accept_all'   => 'Aceitar tudo',
					'reject_all'   => 'Rejeitar',
					'customize'    => 'Personalizar',
					'save'         => 'Guardar preferências',
					'reopen'       => 'Alterar preferências',
					'policy_link'  => 'Política de cookies',
					'cat_functional'           => 'Técnicos',
					'cat_functional_desc'      => 'Necessários ao funcionamento do site. Sempre ativos.',
					'cat_preferences'          => 'Preferências',
					'cat_preferences_desc'     => 'Memorizam escolhas como idioma ou região.',
					'cat_statistics'           => 'Estatísticas',
					'cat_statistics_desc'      => 'Medem o uso do site com identificadores pessoais.',
					'cat_statistics-anonymous' => 'Estatísticas anónimas',
					'cat_statistics-anonymous_desc' => 'Medem o uso de forma agregada, sem identificar o utilizador.',
					'cat_marketing'            => 'Marketing',
					'cat_marketing_desc'       => 'Rastreio publicitário, retargeting e plugins sociais.',
				),
			);

			/**
			 * Permette ad altri plugin/tema di sovrascrivere o aggiungere
			 * lingue alle traduzioni del banner.
			 *
			 * @param array $translations
			 */
			return apply_filters( 'dbcm_banner_translations', $base );
		}

		/* =====================================================================
		 * Render
		 * ================================================================== */

		/**
		 * Stampa il markup base nel footer.
		 *
		 * Lo step 2 ha un markup MINIMALE — sufficiente per testare il
		 * flusso end-to-end. Lo step UI dedicato lo arricchirà con il
		 * full design.
		 */
		public static function render() {
			// Il container è sempre presente nel DOM (anche se autoOpen=false)
			// così il pulsante "Riapri preferenze" può aprirlo via JS senza
			// dover ricaricare la pagina o iniettare il markup runtime.
			?>
			<div id="dbcm-banner-root" data-theme="<?php echo esc_attr( DBCM_Settings::get( 'banner_theme', 'light' ) ); ?>"></div>
			<?php
		}
	}
}