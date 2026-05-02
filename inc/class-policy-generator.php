<?php
/**
 * DBCM_Policy_Generator — Genera la Cookie Policy.
 *
 * Output: HTML pronto da incollare in una pagina WordPress, basato sui
 * cookie scansionati (DBCM_Scanner) e sulle 5 categorie WP Consent API
 * standard (DBCM_Cookie_Database::get_category_label/description).
 *
 * Differenze rispetto a 2.0.1:
 *  - Categorie aggiornate (functional/preferences/statistics/
 *    statistics-anonymous/marketing).
 *  - Riferimenti normativi: art. 122 D.Lgs. 196/2003, GDPR (UE) 2016/679
 *    e Linee Guida del Garante 10 giugno 2021 (invariato), ma con un
 *    inciso sul consenso esplicito per statistics.
 *  - Servizi esterni rilevati (Google Fonts, Plausible, Umami) trattati
 *    in una sezione dedicata "Servizi senza cookie".
 *  - Sezioni esposte come metodi separati e filtrabili — uno step
 *    successivo (shortcode preferenze) potrà comporre solo alcune sezioni.
 *  - Se non c'è ancora alcuna scansione, mostra un fallback realistico
 *    (functional dbcm_consent + invito a scansionare).
 *
 * @package DBCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DBCM_Policy_Generator' ) ) {

	class DBCM_Policy_Generator {

		/**
		 * Genera la Cookie Policy come HTML.
		 *
		 * @return string
		 */
		public static function generate() {
			$context = self::build_context();

			$sections = array(
				self::section_header( $context ),
				self::section_what_are_cookies(),
				self::section_cookies_used( $context ),
				self::section_external_services( $context ),
				self::section_browser_management(),
				self::section_data_controller( $context ),
				self::section_updates( $context ),
				self::section_footer( $context ),
			);

			/**
			 * Filtra l'array completo delle sezioni HTML prima del concat.
			 * Permette ad altri plugin/temi di rimuovere o riordinare sezioni.
			 *
			 * @param array $sections
			 * @param array $context
			 */
			$sections = apply_filters( 'dbcm_policy_sections', $sections, $context );

			$html = implode( "\n", array_filter( array_map( 'trim', $sections ) ) );

			/**
			 * Filtra l'HTML finale della Cookie Policy.
			 *
			 * @param string $html
			 * @param array  $context
			 */
			return apply_filters( 'dbcm_policy_html', $html, $context );
		}

		/* =====================================================================
		 * CONTEXT — dati comuni a tutte le sezioni
		 * ================================================================== */

		/**
		 * Costruisce l'array di contesto usato dalle sezioni.
		 *
		 * @return array
		 */
		private static function build_context() {
			$grouped = class_exists( 'DBCM_Scanner' )
				? DBCM_Scanner::get_results_grouped()
				: array();

			return array(
				'site_name'      => get_bloginfo( 'name' ),
				'site_url'       => home_url(),
				'admin_email'    => get_option( 'admin_email' ),
				'date'           => date_i18n( get_option( 'date_format' ) ),
				'last_scan'      => get_option( 'dbcm_last_scan', '' ),
				'grouped'        => $grouped,
				'has_scan'       => ! empty( $grouped ),
				'google_fonts'   => (bool) get_option( 'dbcm_google_fonts_detected', false ),
				'external_svcs'  => (bool) get_option( 'dbcm_external_services_detected', false ),
			);
		}

		/* =====================================================================
		 * SEZIONI
		 * ================================================================== */

		private static function section_header( $context ) {
			$site = esc_html( $context['site_name'] );
			$url  = esc_url( $context['site_url'] );
			$date = esc_html( $context['date'] );

			$html = '<h2>' . esc_html__( 'Cookie Policy', 'db-cookie-manager' ) . '</h2>';
			$html .= '<p><strong>' . esc_html__( 'Ultimo aggiornamento:', 'db-cookie-manager' ) . '</strong> ' . $date . '</p>';
			$html .= '<p>' . sprintf(
				/* translators: 1: nome sito, 2: URL sito */
				esc_html__( 'La presente Cookie Policy descrive l\'utilizzo dei cookie e di tecnologie simili sul sito %1$s (%2$s), ai sensi dell\'art. 122 del D.Lgs. 196/2003, del Regolamento (UE) 2016/679 (GDPR) e delle Linee Guida del Garante per la protezione dei dati personali del 10 giugno 2021.', 'db-cookie-manager' ),
				'<strong>' . $site . '</strong>',
				$url
			) . '</p>';

			return apply_filters( 'dbcm_policy_section_header', $html, $context );
		}

		private static function section_what_are_cookies() {
			$html  = '<h3>' . esc_html__( '1. Cosa sono i cookie', 'db-cookie-manager' ) . '</h3>';
			$html .= '<p>' . esc_html__(
				'I cookie sono piccoli file di testo che i siti web visitati inviano al browser dell\'utente, dove vengono memorizzati per essere ritrasmessi agli stessi siti alla visita successiva. Tecnologie simili come i pixel, i web beacon e gli identificatori del browser svolgono funzioni analoghe e sono soggetti alle stesse regole.',
				'db-cookie-manager'
			) . '</p>';

			return apply_filters( 'dbcm_policy_section_definitions', $html );
		}

		private static function section_cookies_used( $context ) {
			$html = '<h3>' . esc_html__( '2. Cookie utilizzati su questo sito', 'db-cookie-manager' ) . '</h3>';

			if ( ! $context['has_scan'] ) {
				$html .= self::fallback_no_scan();
				return apply_filters( 'dbcm_policy_section_cookies', $html, $context );
			}

			$html .= '<p>' . esc_html__( 'Dalla scansione automatica del sito sono stati rilevati i seguenti cookie, raggruppati per categoria:', 'db-cookie-manager' ) . '</p>';

			foreach ( $context['grouped'] as $category => $cookies ) {
				$html .= self::render_category_block( $category, $cookies );
			}

			return apply_filters( 'dbcm_policy_section_cookies', $html, $context );
		}

		/**
		 * Render del singolo blocco di categoria con tabella cookie.
		 *
		 * @param string $category
		 * @param array  $cookies
		 * @return string
		 */
		private static function render_category_block( $category, $cookies ) {
			$label = DBCM_Cookie_Database::get_category_label( $category );
			$desc  = DBCM_Cookie_Database::get_category_description( $category );

			$html  = '<h4>' . esc_html( $label ) . '</h4>';
			if ( $desc ) {
				$html .= '<p>' . esc_html( $desc ) . '</p>';
			}

			$html .= '<table style="width:100%;border-collapse:collapse;margin-bottom:1.5em">';
			$html .= '<thead><tr>';
			$html .= '<th style="border:1px solid #ddd;padding:8px;text-align:left;background:#f5f5f5">' . esc_html__( 'Cookie', 'db-cookie-manager' ) . '</th>';
			$html .= '<th style="border:1px solid #ddd;padding:8px;text-align:left;background:#f5f5f5">' . esc_html__( 'Fornitore', 'db-cookie-manager' ) . '</th>';
			$html .= '<th style="border:1px solid #ddd;padding:8px;text-align:left;background:#f5f5f5">' . esc_html__( 'Finalità', 'db-cookie-manager' ) . '</th>';
			$html .= '<th style="border:1px solid #ddd;padding:8px;text-align:left;background:#f5f5f5">' . esc_html__( 'Durata', 'db-cookie-manager' ) . '</th>';
			$html .= '</tr></thead>';
			$html .= '<tbody>';
			foreach ( $cookies as $cookie ) {
				$html .= '<tr>';
				$html .= '<td style="border:1px solid #ddd;padding:8px"><code>' . esc_html( $cookie->cookie_name ) . '</code></td>';
				$html .= '<td style="border:1px solid #ddd;padding:8px">' . esc_html( $cookie->provider ) . '</td>';
				$html .= '<td style="border:1px solid #ddd;padding:8px">' . esc_html( $cookie->description ) . '</td>';
				$html .= '<td style="border:1px solid #ddd;padding:8px">' . esc_html( $cookie->cookie_duration ) . '</td>';
				$html .= '</tr>';
			}
			$html .= '</tbody></table>';

			return $html;
		}

		/**
		 * Sezione mostrata se l'admin non ha ancora eseguito una scansione.
		 *
		 * @return string
		 */
		private static function fallback_no_scan() {
			$html  = '<p>' . esc_html__( 'Non è ancora stata eseguita una scansione automatica. Di seguito il cookie tecnico sempre presente del sistema di gestione del consenso. Si consiglia di eseguire una scansione dall\'area amministrativa per generare un elenco completo.', 'db-cookie-manager' ) . '</p>';

			$html .= '<h4>' . esc_html__( 'Tecnici (necessari)', 'db-cookie-manager' ) . '</h4>';
			$html .= '<p>' . esc_html( DBCM_Cookie_Database::get_category_description( 'functional' ) ) . '</p>';

			$html .= '<table style="width:100%;border-collapse:collapse">';
			$html .= '<thead><tr>';
			$html .= '<th style="border:1px solid #ddd;padding:8px;text-align:left;background:#f5f5f5">' . esc_html__( 'Cookie', 'db-cookie-manager' ) . '</th>';
			$html .= '<th style="border:1px solid #ddd;padding:8px;text-align:left;background:#f5f5f5">' . esc_html__( 'Fornitore', 'db-cookie-manager' ) . '</th>';
			$html .= '<th style="border:1px solid #ddd;padding:8px;text-align:left;background:#f5f5f5">' . esc_html__( 'Finalità', 'db-cookie-manager' ) . '</th>';
			$html .= '<th style="border:1px solid #ddd;padding:8px;text-align:left;background:#f5f5f5">' . esc_html__( 'Durata', 'db-cookie-manager' ) . '</th>';
			$html .= '</tr></thead>';
			$html .= '<tbody><tr>';
			$html .= '<td style="border:1px solid #ddd;padding:8px"><code>dbcm_consent</code></td>';
			$html .= '<td style="border:1px solid #ddd;padding:8px">DB Cookie Manager</td>';
			$html .= '<td style="border:1px solid #ddd;padding:8px">' . esc_html__( 'Memorizza la scelta dell\'utente sui cookie (accetta / rifiuta / personalizza).', 'db-cookie-manager' ) . '</td>';
			$html .= '<td style="border:1px solid #ddd;padding:8px">365 ' . esc_html__( 'giorni', 'db-cookie-manager' ) . '</td>';
			$html .= '</tr></tbody></table>';

			return $html;
		}

		private static function section_external_services( $context ) {
			if ( empty( $context['google_fonts'] ) && empty( $context['external_svcs'] ) ) {
				return '';
			}

			$html = '<h3>' . esc_html__( '3. Servizi esterni senza cookie', 'db-cookie-manager' ) . '</h3>';
			$html .= '<p>' . esc_html__(
				'Il sito utilizza alcuni servizi di terze parti che non installano cookie ma comportano comunque una connessione a server esterni. Questi servizi possono raccogliere dati tecnici (come l\'indirizzo IP) durante il caricamento.',
				'db-cookie-manager'
			) . '</p>';

			$html .= '<ul>';

			if ( $context['google_fonts'] ) {
				$html .= '<li><strong>Google Fonts</strong> — ' . esc_html__( 'caricamento di font web da CDN Google. Google può registrare la richiesta nei log dei server.', 'db-cookie-manager' );
				$html .= ' <a href="https://policies.google.com/privacy" target="_blank" rel="noopener">' . esc_html__( 'Informativa Google', 'db-cookie-manager' ) . '</a></li>';
			}

			if ( $context['external_svcs'] ) {
				$html .= '<li><strong>' . esc_html__( 'Analytics anonimi', 'db-cookie-manager' ) . '</strong> — ' . esc_html__( 'sono stati rilevati servizi di statistica cookieless (Plausible, Umami, Fathom o simili). Questi servizi raccolgono dati aggregati e anonimi sull\'utilizzo del sito senza identificare il singolo utente.', 'db-cookie-manager' ) . '</li>';
			}

			$html .= '</ul>';

			return apply_filters( 'dbcm_policy_section_external', $html, $context );
		}

		private static function section_browser_management() {
			$html = '<h3>' . esc_html__( '4. Come gestire i cookie', 'db-cookie-manager' ) . '</h3>';
			$html .= '<p>' . esc_html__(
				'Le preferenze sui cookie possono essere modificate in qualsiasi momento attraverso il banner cookie o il pulsante "Modifica preferenze" presente sul sito. È inoltre possibile gestire i cookie direttamente dalle impostazioni del proprio browser:',
				'db-cookie-manager'
			) . '</p>';

			$html .= '<ul>';
			$html .= '<li><a href="https://support.google.com/chrome/answer/95647" target="_blank" rel="noopener">Google Chrome</a></li>';
			$html .= '<li><a href="https://support.mozilla.org/it/kb/Gestione%20dei%20cookie" target="_blank" rel="noopener">Mozilla Firefox</a></li>';
			$html .= '<li><a href="https://support.apple.com/it-it/guide/safari/sfri11471/" target="_blank" rel="noopener">Safari</a></li>';
			$html .= '<li><a href="https://support.microsoft.com/it-it/microsoft-edge" target="_blank" rel="noopener">Microsoft Edge</a></li>';
			$html .= '</ul>';

			$html .= '<p><strong>' . esc_html__( 'Nota:', 'db-cookie-manager' ) . '</strong> ' . esc_html__( 'la disabilitazione dei cookie tecnici potrebbe compromettere il funzionamento di alcune parti del sito.', 'db-cookie-manager' ) . '</p>';

			return apply_filters( 'dbcm_policy_section_browser', $html );
		}

		private static function section_data_controller( $context ) {
			$email = esc_html( $context['admin_email'] );

			$html  = '<h3>' . esc_html__( '5. Titolare del trattamento', 'db-cookie-manager' ) . '</h3>';
			$html .= '<p><strong>[' . esc_html__( 'NOME COMPLETO / RAGIONE SOCIALE', 'db-cookie-manager' ) . ']</strong><br>';
			$html .= '<strong>[' . esc_html__( 'INDIRIZZO', 'db-cookie-manager' ) . ']</strong><br>';
			$html .= esc_html__( 'Email:', 'db-cookie-manager' ) . ' ' . $email . '</p>';
			$html .= '<p><em>' . esc_html__( 'Sostituire i campi tra parentesi con i dati reali del titolare prima di pubblicare la pagina.', 'db-cookie-manager' ) . '</em></p>';

			return apply_filters( 'dbcm_policy_section_controller', $html, $context );
		}

		private static function section_updates( $context ) {
			$html  = '<h3>' . esc_html__( '6. Aggiornamenti', 'db-cookie-manager' ) . '</h3>';
			$html .= '<p>' . esc_html__( 'La presente Cookie Policy può essere soggetta a modifiche. La data dell\'ultimo aggiornamento è indicata in alto. Si raccomanda di consultare periodicamente questa pagina.', 'db-cookie-manager' ) . '</p>';

			return apply_filters( 'dbcm_policy_section_updates', $html, $context );
		}

		private static function section_footer( $context ) {
			$date = esc_html( $context['date'] );

			$html  = '<hr>';
			$html .= '<p style="font-size:0.85em;color:#666"><em>';
			$html .= sprintf(
				/* translators: %s: data scansione */
				esc_html__( 'Testo generato automaticamente da DB Cookie Manager in base alla scansione del %s. Verificare con un professionista prima della pubblicazione.', 'db-cookie-manager' ),
				$date
			);
			$html .= '</em></p>';

			return apply_filters( 'dbcm_policy_section_footer', $html, $context );
		}
	}
}
