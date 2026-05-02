<?php
/**
 * DBCM_Blocker — Blocco preventivo di script e iframe terzi.
 *
 * Strategia: gli script di tracking vengono "neutralizzati" prima che il
 * browser li esegua, sostituendo type="text/javascript" con type="text/plain"
 * e aggiungendo data-dbcm-blocked="true" + data-dbcm-category="...". Il banner
 * JS, dopo che l'utente accetta una categoria, riattiva gli script clonando
 * i tag con il type corretto (vedi banner.js → activateBlockedScripts()).
 *
 * Differenze rispetto a 2.0.1:
 *  - Categorie allineate alle 5 standard WP Consent API.
 *  - Cloudflare CDN (cdnjs/cdn/ajax.cloudflare.com) NON è più bloccato:
 *    è infrastruttura, non tracciamento. Solo challenges.cloudflare.com
 *    (Turnstile) resta in marketing.
 *  - Plausible e Umami spostati in 'statistics-anonymous' (sono cookieless
 *    e aggregati per design). Il SEO Manager riconosce questa categoria.
 *  - connect.facebook.net solo in marketing (in 2.0.1 era in entrambe).
 *  - Placeholder iframe traducibile via filtro dbcm_blocker_placeholder_text
 *    (in 2.0.1 era hardcoded in italiano).
 *  - Decision via DBCM_Consent_API::has_consent() — usa wp_has_consent()
 *    se la WP Consent API è installata, altrimenti il cookie del banner.
 *
 * Due meccanismi:
 *  1. Filtro 'script_loader_tag' (priorità 100) — copre wp_enqueue_script.
 *  2. Output buffering su 'template_redirect' priorità 1 — copre script
 *     inline e iframe hardcoded nei template/contenuti.
 *
 * @package DBCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DBCM_Blocker' ) ) {

	class DBCM_Blocker {

		/**
		 * Cache della pattern map (calcolata al primo uso).
		 *
		 * @var array|null
		 */
		private static $patterns_cache = null;

		/**
		 * Inizializzazione — chiamata da DBCM_Plugin->init_modules().
		 */
		public static function init() {
			if ( is_admin() ) {
				return;
			}
			if ( ! DBCM_Settings::get( 'banner_enabled', true ) ) {
				return;
			}
			if ( ! DBCM_Settings::get( 'auto_block', true ) ) {
				return;
			}

			// Meccanismo 1: filtro su script_loader_tag (script enqueued).
			add_filter( 'script_loader_tag', array( __CLASS__, 'filter_script_tag' ), 100, 3 );

			// Meccanismo 2: output buffering (script inline + iframe).
			add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ), 1 );
		}

		/* =====================================================================
		 * PATTERN MAP
		 * ================================================================== */

		/**
		 * Restituisce la mappa dei pattern conosciuti, raggruppati per
		 * categoria WP Consent API standard e tipo (script | iframe | both).
		 *
		 * Estensibile via filtro 'dbcm_blocker_patterns'. Tema/altri plugin
		 * possono aggiungere domini specifici senza dover hookare ogni regex.
		 *
		 * Forma: array di gruppi { category, type, patterns[] }.
		 *  - category: una delle 5 standard WP Consent API.
		 *  - type:     'script' | 'iframe' | 'both' (default 'script').
		 *  - patterns: array di stringhe matchate via stripos su src/contenuto.
		 *
		 * @return array
		 */
		public static function get_patterns() {
			if ( null !== self::$patterns_cache ) {
				return self::$patterns_cache;
			}

			$patterns = array(

				/* ---------------------------------------------------------------
				 * STATISTICS — analytics CON identificatori personali.
				 * Cookie con user_id, GA client_id, fingerprint, ecc.
				 * ------------------------------------------------------------ */
				array(
					'category' => 'statistics',
					'type'     => 'script',
					'patterns' => array(
						'google-analytics.com/analytics',
						'google-analytics.com/ga.js',
						'google-analytics.com/urchin.js',
						'googletagmanager.com/gtag',
						'googletagmanager.com/gtm.js',
						'static.hotjar.com',
						'script.hotjar.com',
						'cdn.mxpnl.com',                  // Mixpanel
						'cdn.heapanalytics.com',
						'cdn.amplitude.com',
						'fullstory.com/s/fs.js',
						'static.cloudflareinsights.com',  // Cloudflare Insights (con UID)
						// Matomo / Piwik self-hosted: il pattern dipende dal sito;
						// qui prendiamo i pattern più comuni nei file statici.
						'matomo.js',
						'matomo.php',
						'piwik.js',
						'piwik.php',
						// Jetpack Stats / WordPress.com Stats
						'stats.wp.com',
					),
				),

				/* ---------------------------------------------------------------
				 * STATISTICS-ANONYMOUS — analytics aggregati cookieless.
				 * Plausible e Umami sono privacy-by-design: nessun cookie,
				 * nessun ID utente, solo eventi aggregati. Il SEO Manager
				 * supporta esplicitamente questa categoria.
				 * ------------------------------------------------------------ */
				array(
					'category' => 'statistics-anonymous',
					'type'     => 'script',
					'patterns' => array(
						'plausible.io/js/plausible',
						'plausible.io/js/script',
						'umami.is/script.js',
						'cdn.usefathom.com',
						'simpleanalytics.com/latest.js',
						'sa.davidebertolino.it',         // sentinella locale (esempio)
					),
				),

				/* ---------------------------------------------------------------
				 * MARKETING — pixel pubblicitari, retargeting, social tracking.
				 * ------------------------------------------------------------ */
				array(
					'category' => 'marketing',
					'type'     => 'script',
					'patterns' => array(
						// Meta / Facebook
						'connect.facebook.net',
						'fbevents.js',
						// Google Ads / DoubleClick / AdSense
						'googleadservices.com',
						'googlesyndication.com',
						'doubleclick.net',
						'adservice.google',
						// LinkedIn Insight Tag
						'snap.licdn.com',
						'ads.linkedin.com',
						'linkedin.com/px',
						// X (Twitter) Ads
						'static.ads-twitter.com',
						'platform.twitter.com/oct.js',
						'ads.twitter.com',
						// TikTok Pixel
						'analytics.tiktok.com',
						// Pinterest Tag
						'ct.pinterest.com',
						'pintrk',
						// Reddit Pixel
						'redditstatic.com/ads',
						// Amazon Ads
						'amazon-adsystem.com',
						// Microsoft / Bing Ads (UET)
						'bat.bing.com',
						'clarity.ms',                    // MS Clarity (session replay)
						// Cloudflare Turnstile (captcha) — NON cdnjs/cdn/ajax.
						'challenges.cloudflare.com',
					),
				),

				/* ---------------------------------------------------------------
				 * MARKETING — iframe (embed video, mappe).
				 *
				 * Nota: youtube-nocookie.com È stato lasciato in marketing
				 * per coerenza con la behavior 2.x (l'utente che usa
				 * -nocookie sta già scegliendo una variante più pulita ma
				 * il consenso è ancora richiesto da molte autorità garanti).
				 * Filtrabile per chi vuole considerarlo cookieless.
				 * ------------------------------------------------------------ */
				array(
					'category' => 'marketing',
					'type'     => 'iframe',
					'patterns' => array(
						'youtube.com/embed',
						'youtube-nocookie.com/embed',
						'player.vimeo.com',
						'maps.google.com',
						'google.com/maps/embed',
						'google.com/maps/d/embed',
						'instagram.com/p/',
						'instagram.com/reel/',
						'instagram.com/tv/',
						'platform.twitter.com/embed',
						'open.spotify.com/embed',
						'soundcloud.com/player',
					),
				),
			);

			/**
			 * Permette ad altri plugin/tema di aggiungere o modificare i
			 * pattern del blocker. Il filtro riceve l'array completo.
			 *
			 * Esempio: aggiungere un pixel custom in marketing
			 *   add_filter('dbcm_blocker_patterns', function($p){
			 *     $p[] = array(
			 *       'category' => 'marketing',
			 *       'type'     => 'script',
			 *       'patterns' => array('mio-cdn.example.com/pixel.js'),
			 *     );
			 *     return $p;
			 *   });
			 *
			 * @param array $patterns
			 */
			$patterns = apply_filters( 'dbcm_blocker_patterns', $patterns );

			// Validazione: ogni gruppo deve avere category valida + patterns array.
			$patterns = array_filter(
				$patterns,
				function ( $group ) {
					return is_array( $group )
						&& isset( $group['category'], $group['patterns'] )
						&& DBCM_Settings::is_valid_category( $group['category'] )
						&& is_array( $group['patterns'] )
						&& ! empty( $group['patterns'] );
				}
			);

			self::$patterns_cache = array_values( $patterns );
			return self::$patterns_cache;
		}

		/* =====================================================================
		 * MATCHING
		 * ================================================================== */

		/**
		 * Restituisce la categoria di un URL/contenuto script, o null se
		 * non c'è match.
		 *
		 * @param string $haystack URL src oppure contenuto inline.
		 * @return string|null
		 */
		private static function match_script( $haystack ) {
			if ( '' === $haystack || null === $haystack ) {
				return null;
			}
			foreach ( self::get_patterns() as $group ) {
				$type = $group['type'] ?? 'script';
				if ( 'iframe' === $type ) {
					continue;
				}
				foreach ( $group['patterns'] as $pattern ) {
					if ( false !== stripos( $haystack, $pattern ) ) {
						return $group['category'];
					}
				}
			}
			return null;
		}

		/**
		 * Restituisce la categoria di un iframe src, o null.
		 *
		 * @param string $src
		 * @return string|null
		 */
		private static function match_iframe( $src ) {
			if ( '' === $src || null === $src ) {
				return null;
			}
			foreach ( self::get_patterns() as $group ) {
				$type = $group['type'] ?? 'script';
				if ( 'iframe' !== $type && 'both' !== $type ) {
					continue;
				}
				foreach ( $group['patterns'] as $pattern ) {
					if ( false !== stripos( $src, $pattern ) ) {
						return $group['category'];
					}
				}
			}
			return null;
		}

		/**
		 * Verifica se l'utente ha già concesso una categoria.
		 *
		 * Delega a DBCM_Consent_API::has_consent() — strategia a 3 livelli
		 * (WP Consent API → cookie → false). Niente più parsing diretto del
		 * cookie come faceva 2.0.1.
		 *
		 * @param string $category
		 * @return bool
		 */
		private static function has_consent( $category ) {
			return DBCM_Consent_API::has_consent( $category );
		}

		/* =====================================================================
		 * MECCANISMO 1 — script_loader_tag
		 * ================================================================== */

		/**
		 * Filtra il tag <script> generato da wp_enqueue_script.
		 *
		 * @param string $tag    Markup HTML completo del tag.
		 * @param string $handle Handle WordPress.
		 * @param string $src    URL src.
		 * @return string
		 */
		public static function filter_script_tag( $tag, $handle, $src ) {
			$category = self::match_script( $src );
			if ( ! $category ) {
				return $tag;
			}
			if ( self::has_consent( $category ) ) {
				return $tag;
			}
			return self::neutralize_script_tag( $tag, $category );
		}

		/**
		 * Trasforma un tag <script> attivo in uno bloccato.
		 *
		 * @param string $tag
		 * @param string $category
		 * @return string
		 */
		private static function neutralize_script_tag( $tag, $category ) {
			// Sostituisce o aggiunge type="text/plain".
			if ( preg_match( '/\stype\s*=\s*["\'][^"\']*["\']/i', $tag ) ) {
				$tag = preg_replace( '/\stype\s*=\s*["\'][^"\']*["\']/i', ' type="text/plain"', $tag, 1 );
			} else {
				$tag = preg_replace( '/<script\b/i', '<script type="text/plain"', $tag, 1 );
			}

			// Aggiunge i data attributes (una sola volta).
			if ( false === stripos( $tag, 'data-dbcm-blocked' ) ) {
				$tag = preg_replace(
					'/<script\b/i',
					'<script data-dbcm-blocked="true" data-dbcm-category="' . esc_attr( $category ) . '"',
					$tag,
					1
				);
			}

			return $tag;
		}

		/* =====================================================================
		 * MECCANISMO 2 — output buffering
		 * ================================================================== */

		public static function start_buffer() {
			ob_start( array( __CLASS__, 'process_buffer' ) );
		}

		/**
		 * Pipeline di processing del buffer: prima script, poi iframe.
		 *
		 * @param string $html
		 * @return string
		 */
		public static function process_buffer( $html ) {
			if ( '' === $html || null === $html ) {
				return $html;
			}
			$html = self::block_inline_scripts( $html );
			$html = self::block_iframes( $html );
			return $html;
		}

		/**
		 * Cerca <script> nel buffer e li blocca se matchano un pattern.
		 *
		 * @param string $html
		 * @return string
		 */
		private static function block_inline_scripts( $html ) {
			return preg_replace_callback(
				'/<script\b([^>]*)>(.*?)<\/script>/is',
				array( __CLASS__, 'process_script_match' ),
				$html
			);
		}

		/**
		 * Callback del preg_replace_callback per gli script.
		 *
		 * @param array $m
		 * @return string
		 */
		private static function process_script_match( $m ) {
			$attrs   = $m[1];
			$content = $m[2];

			// Skip: già bloccato (dal meccanismo 1 o da un'altra passata).
			if ( false !== stripos( $attrs, 'data-dbcm-blocked' ) ) {
				return $m[0];
			}
			// Skip: il nostro stesso banner.js.
			if ( false !== stripos( $attrs, 'dbcm-banner' ) ) {
				return $m[0];
			}

			// Determina la categoria: prima dal src, poi dal contenuto inline.
			$category = null;
			if ( preg_match( '/\ssrc\s*=\s*["\']([^"\']+)["\']/i', $attrs, $src_match ) ) {
				$category = self::match_script( $src_match[1] );
			}
			if ( ! $category && '' !== $content ) {
				$category = self::match_script( $content );
			}

			if ( ! $category ) {
				return $m[0];
			}
			if ( self::has_consent( $category ) ) {
				return $m[0];
			}

			// Sostituisce o aggiunge type="text/plain".
			$blocked = $attrs;
			if ( preg_match( '/\stype\s*=\s*["\'][^"\']*["\']/i', $blocked ) ) {
				$blocked = preg_replace( '/\stype\s*=\s*["\'][^"\']*["\']/i', ' type="text/plain"', $blocked, 1 );
			} else {
				$blocked = ' type="text/plain"' . $blocked;
			}
			$blocked .= ' data-dbcm-blocked="true" data-dbcm-category="' . esc_attr( $category ) . '"';

			return '<script' . $blocked . '>' . $content . '</script>';
		}

		/**
		 * Cerca <iframe> nel buffer e li sostituisce con placeholder se
		 * matchano un pattern (e l'utente non ha consentito).
		 *
		 * @param string $html
		 * @return string
		 */
		private static function block_iframes( $html ) {
			// Match anche di iframe self-closing (raro ma possibile).
			return preg_replace_callback(
				'/<iframe\b([^>]*?)(?:\/?>(?:(.*?)<\/iframe>)?)/is',
				array( __CLASS__, 'process_iframe_match' ),
				$html
			);
		}

		/**
		 * Callback del preg_replace_callback per gli iframe.
		 *
		 * @param array $m
		 * @return string
		 */
		private static function process_iframe_match( $m ) {
			$attrs = $m[1];

			if ( ! preg_match( '/\ssrc\s*=\s*["\']([^"\']+)["\']/i', $attrs, $src_match ) ) {
				return $m[0];
			}
			$src = $src_match[1];

			$category = self::match_iframe( $src );
			if ( ! $category ) {
				return $m[0];
			}
			if ( self::has_consent( $category ) ) {
				return $m[0];
			}

			return self::build_iframe_placeholder( $src, $attrs, $category );
		}

		/**
		 * Costruisce il placeholder visivo che sostituisce l'iframe bloccato.
		 *
		 * Il messaggio è generato via filtro dbcm_blocker_placeholder_text
		 * così tema/altri plugin possono tradurlo o personalizzarlo.
		 * Default in italiano per coerenza con la lingua principale del
		 * plugin, ma fornito anche in inglese come fallback se nessuno
		 * sovrascrive il filtro.
		 *
		 * @param string $src      URL dell'iframe originale.
		 * @param string $attrs    Attributi dell'iframe (per width/height).
		 * @param string $category Categoria WP Consent API.
		 * @return string
		 */
		private static function build_iframe_placeholder( $src, $attrs, $category ) {
			// Estrai dimensioni per evitare layout shift.
			$width  = '100%';
			$height = '400px';
			if ( preg_match( '/\swidth\s*=\s*["\']?(\d+)/i', $attrs, $w ) ) {
				$width = $w[1] . 'px';
			}
			if ( preg_match( '/\sheight\s*=\s*["\']?(\d+)/i', $attrs, $h ) ) {
				$height = $h[1] . 'px';
			}

			// Identifica il servizio per il messaggio (opzionale, decorativo).
			$service = self::detect_service( $src );

			// Testo del placeholder, traducibile.
			$default_text = sprintf(
				/* translators: 1: nome servizio (es. YouTube), 2: nome categoria (es. marketing) */
				__( 'Questo contenuto (%1$s) richiede il consenso ai cookie di %2$s.', 'db-cookie-manager' ),
				$service,
				self::category_label( $category )
			);
			$text = apply_filters(
				'dbcm_blocker_placeholder_text',
				$default_text,
				$service,
				$category,
				$src
			);

			$btn_label = apply_filters(
				'dbcm_blocker_placeholder_btn_label',
				__( 'Modifica preferenze', 'db-cookie-manager' ),
				$category
			);

			// Markup. data-dbcm-src e data-dbcm-attrs conservano i dati
			// originali per future estensioni (es. "carica solo questo
			// iframe senza accettare la categoria intera"). Per ora il
			// click apre il modal preferenze via window.DBCM.openPreferences().
			$out  = '<div class="dbcm-iframe-placeholder"';
			$out .= ' style="width:' . esc_attr( $width ) . ';max-width:100%;height:' . esc_attr( $height ) . ';"';
			$out .= ' data-dbcm-category="' . esc_attr( $category ) . '"';
			$out .= ' data-dbcm-src="' . esc_attr( $src ) . '"';
			$out .= '>';
			$out .= '<div class="dbcm-iframe-placeholder__inner">';
			$out .= '<p class="dbcm-iframe-placeholder__text">' . esc_html( $text ) . '</p>';
			$out .= '<button type="button" class="dbcm-iframe-placeholder__btn"';
			$out .= ' onclick="if(window.DBCM&amp;&amp;window.DBCM.openPreferences){window.DBCM.openPreferences();}">';
			$out .= esc_html( $btn_label );
			$out .= '</button>';
			$out .= '</div>';
			$out .= '</div>';

			return $out;
		}

		/**
		 * Riconosce il nome del servizio dall'URL per il messaggio del
		 * placeholder. Decorativo: serve solo a rendere il messaggio più
		 * chiaro ("Questo contenuto (YouTube) richiede...").
		 *
		 * @param string $src
		 * @return string
		 */
		private static function detect_service( $src ) {
			$map = array(
				'youtube'           => 'YouTube',
				'vimeo'             => 'Vimeo',
				'google.com/maps'   => 'Google Maps',
				'maps.google'       => 'Google Maps',
				'instagram.com'     => 'Instagram',
				'twitter.com'       => 'X (Twitter)',
				'spotify.com'       => 'Spotify',
				'soundcloud.com'    => 'SoundCloud',
			);
			foreach ( $map as $needle => $label ) {
				if ( false !== stripos( $src, $needle ) ) {
					return $label;
				}
			}
			return __( 'contenuto esterno', 'db-cookie-manager' );
		}

		/**
		 * Etichetta human-readable per una categoria, usata nel testo
		 * del placeholder iframe.
		 *
		 * @param string $category
		 * @return string
		 */
		private static function category_label( $category ) {
			$labels = array(
				'functional'           => __( 'tecnici', 'db-cookie-manager' ),
				'preferences'          => __( 'preferenze', 'db-cookie-manager' ),
				'statistics'           => __( 'statistiche', 'db-cookie-manager' ),
				'statistics-anonymous' => __( 'statistiche anonime', 'db-cookie-manager' ),
				'marketing'            => __( 'marketing', 'db-cookie-manager' ),
			);
			return $labels[ $category ] ?? $category;
		}
	}
}
