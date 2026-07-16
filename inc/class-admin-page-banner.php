<?php
/**
 * DBCM_Admin_Page_Banner — Banner (aspetto + contenuto + versione del consenso).
 *
 * Estratta da class-admin.php nel refactor meccanico 3.5.1 (una classe per
 * pagina/schermata). Nessun cambio di comportamento: i metodi sono identici,
 * i riferimenti agli helper condivisi (open_wrap, form_*, field_*, costanti
 * CAP/MENU_SLUG) puntano a DBCM_Admin, che resta il guscio comune (menu,
 * assets, dispatcher di salvataggio, flash notices).
 *
 * @package DBCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DBCM_Admin_Page_Banner' ) ) {

	class DBCM_Admin_Page_Banner {

		/**
		 * Handler 'admin_post_dbcm_bump_consent_version'.
		 *
		 * Incrementa di 1 la versione del consenso. Da quel momento tutti i
		 * cookie dbcm_consent con versione precedente vengono trattati come
		 * assenza di consenso (client e server) e il banner viene ri-mostrato
		 * (Art. 4(11): il consenso è specifico rispetto ai trattamenti
		 * presentati al momento della scelta).
		 *
		 * L'incremento è sempre +1 calcolato server-side: il client non può
		 * fornire un valore arbitrario (né decrementare per "resuscitare"
		 * consensi vecchi).
		 *
		 * @since 3.5.0
		 * @return void (esce con wp_safe_redirect + exit)
		 */
		public static function handle_bump_consent_version() {
			if ( ! current_user_can( DBCM_Admin::CAP ) ) {
				wp_die(
					esc_html__( 'Permessi insufficienti.', 'db-cookie-manager' ),
					'',
					array( 'response' => 403 )
				);
			}
			$nonce = isset( $_REQUEST['_wpnonce'] )
				? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) )
				: '';
			if ( ! wp_verify_nonce( $nonce, 'dbcm_bump_consent_version' ) ) {
				wp_die(
					esc_html__( 'Token di sicurezza scaduto. Ricarica la pagina e riprova.', 'db-cookie-manager' ),
					'',
					array( 'response' => 403 )
				);
			}

			$redirect = wp_get_referer() ?: admin_url( 'admin.php?page=' . DBCM_Admin::MENU_SLUG . '-banner' );

			DBCM_Settings::update( 'consent_version', DBCM_Settings::consent_version() + 1 );

			wp_safe_redirect( add_query_arg( 'dbcm_msg', 'consent_version_bumped', $redirect ) );
			exit;
		}

		public static function render_banner() {
			if ( ! current_user_can( DBCM_Admin::CAP ) ) {
				wp_die( esc_html__( 'Permessi insufficienti.', 'db-cookie-manager' ) );
			}

			$s = DBCM_Settings::all();

			DBCM_Admin::open_wrap(
				__( 'Banner & aspetto', 'db-cookie-manager' ),
				__( 'Configura come appare il banner ai visitatori e cosa viene loro chiesto.', 'db-cookie-manager' )
			);

			self::render_banner_appearance_form( $s );
			self::render_banner_content_form( $s );

			DBCM_Admin::close_wrap();
		}

		/**
		 * Form 1 — Aspetto: layout, posizione, tema, colori, custom CSS,
		 * pulsante "Riapri preferenze".
		 *
		 * Salva nella sezione 'banner_appearance' (vedi sections_schema()).
		 *
		 * @param array $s Settings correnti (DBCM_Settings::all()).
		 * @return void
		 */
		private static function render_banner_appearance_form( $s ) {
			DBCM_Admin::form_open( 'banner_appearance' );
			?>

			<div class="db-ui-card">
				<div class="db-ui-card-header"><h3><?php esc_html_e( 'Visibilità & layout', 'db-cookie-manager' ); ?></h3></div>
				<div class="db-ui-card-body">
					<?php
					DBCM_Admin::field_checkbox(
						'banner_enabled',
						$s['banner_enabled'],
						__( 'Abilita il banner cookie', 'db-cookie-manager' ),
						__( 'Se disabilitato il banner non viene mostrato. Il blocco preventivo degli script di tracking continua a funzionare in base al cookie esistente.', 'db-cookie-manager' )
					);

					DBCM_Admin::field_select(
						'banner_layout',
						$s['banner_layout'],
						__( 'Layout', 'db-cookie-manager' ),
						array(
							'box' => __( 'Riquadro flottante (consigliato)', 'db-cookie-manager' ),
							'bar' => __( 'Barra a tutta larghezza', 'db-cookie-manager' ),
						),
						__( 'Il riquadro flottante è meno invasivo. La barra a tutta larghezza ha più visibilità ma copre più contenuto.', 'db-cookie-manager' )
					);

					DBCM_Admin::field_select(
						'banner_position',
						$s['banner_position'],
						__( 'Posizione (solo layout "riquadro")', 'db-cookie-manager' ),
						array(
							'bottom-right'  => __( 'In basso a destra', 'db-cookie-manager' ),
							'bottom-left'   => __( 'In basso a sinistra', 'db-cookie-manager' ),
							'bottom-center' => __( 'In basso al centro', 'db-cookie-manager' ),
						)
					);

					DBCM_Admin::field_checkbox(
						'banner_overlay',
						$s['banner_overlay'],
						__( 'Mostra sfondo scuro semitrasparente dietro al banner', 'db-cookie-manager' ),
						__( 'Aumenta la visibilità ma blocca l\'interazione con il sito finché l\'utente non sceglie. Sconsigliato per UX in stile soft-paywall.', 'db-cookie-manager' )
					);

					DBCM_Admin::field_checkbox(
						'show_reopen_btn',
						$s['show_reopen_btn'],
						__( 'Mostra il pulsante flottante "Modifica preferenze"', 'db-cookie-manager' ),
						__( 'Permette all\'utente di riaprire il banner in qualsiasi momento. Lo step 7 aggiungerà uno shortcode per posizionarlo dove vuoi (es. nel footer).', 'db-cookie-manager' )
					);

					DBCM_Admin::field_select(
						'reopen_position',
						$s['reopen_position'],
						__( 'Posizione del pulsante "Modifica preferenze"', 'db-cookie-manager' ),
						array(
							'bottom-left'  => __( 'In basso a sinistra', 'db-cookie-manager' ),
							'bottom-right' => __( 'In basso a destra', 'db-cookie-manager' ),
							'top-left'     => __( 'In alto a sinistra', 'db-cookie-manager' ),
							'top-right'    => __( 'In alto a destra', 'db-cookie-manager' ),
						)
					);
					?>
				</div>
			</div>

			<div class="db-ui-card">
				<div class="db-ui-card-header"><h3><?php esc_html_e( 'Tema & colori', 'db-cookie-manager' ); ?></h3></div>
				<div class="db-ui-card-body">
					<?php
					DBCM_Admin::field_select(
						'banner_theme',
						$s['banner_theme'],
						__( 'Tema', 'db-cookie-manager' ),
						array(
							'light' => __( 'Chiaro', 'db-cookie-manager' ),
							'dark'  => __( 'Scuro', 'db-cookie-manager' ),
							'auto'  => __( 'Auto (segue le preferenze del sistema)', 'db-cookie-manager' ),
						),
						__( 'In modalità "auto" il banner segue prefers-color-scheme del browser dell\'utente.', 'db-cookie-manager' )
					);
					?>

					<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px">
						<?php
						DBCM_Admin::field_color(
							'banner_color_bg',
							$s['banner_color_bg'],
							__( 'Sfondo banner', 'db-cookie-manager' )
						);
						DBCM_Admin::field_color(
							'banner_color_text',
							$s['banner_color_text'],
							__( 'Testo banner', 'db-cookie-manager' )
						);
						DBCM_Admin::field_color(
							'banner_color_btn',
							$s['banner_color_btn'],
							__( 'Sfondo bottone primario', 'db-cookie-manager' )
						);
						DBCM_Admin::field_color(
							'banner_color_btn_text',
							$s['banner_color_btn_text'],
							__( 'Testo bottone primario', 'db-cookie-manager' )
						);
						?>
					</div>

					<?php
					DBCM_Admin::field_checkbox(
						'banner_credits',
						$s['banner_credits'],
						__( 'Mostra "Powered by DB Cookie Manager" nel banner', 'db-cookie-manager' ),
						__( 'Niente affiliazioni o tracking — solo un piccolo credit che aiuta altri sviluppatori a scoprire il plugin.', 'db-cookie-manager' )
					);
					?>
				</div>
			</div>

			<div class="db-ui-card">
				<div class="db-ui-card-header"><h3><?php esc_html_e( 'CSS personalizzato', 'db-cookie-manager' ); ?></h3></div>
				<div class="db-ui-card-body">
					<?php
					DBCM_Admin::field_textarea(
						'banner_custom_css',
						$s['banner_custom_css'],
						__( 'CSS extra applicato al banner', 'db-cookie-manager' ),
						__( 'Si applica solo all\'interno di #dbcm-banner-root. Usa le variabili --dbcm-* per personalizzazioni avanzate. I tag HTML vengono rimossi al salvataggio.', 'db-cookie-manager' ),
						6
					);
					?>
					<details style="margin-top:8px">
						<summary style="cursor:pointer;font-size:13px;color:var(--db-text-muted)"><?php esc_html_e( 'Esempio: bordo arrotondato e ombra più morbida', 'db-cookie-manager' ); ?></summary>
						<pre style="background:var(--db-bg-subtle);padding:10px;border-radius:6px;font-size:12px;margin-top:6px;overflow:auto"><code>#dbcm-banner-root .dbcm-banner {
  border-radius: 16px;
  box-shadow: 0 12px 40px rgba(0, 0, 0, .18);
}
#dbcm-banner-root .dbcm-btn--primary {
  border-radius: 999px; /* pillola */
}</code></pre>
					</details>
				</div>
			</div>

			<?php
			DBCM_Admin::form_close( __( 'Salva aspetto', 'db-cookie-manager' ) );
		}

		/**
		 * Form 2 — Contenuto & comportamento: lingue, durata cookie,
		 * default delle categorie, pagina della Cookie Policy.
		 *
		 * Salva nella sezione 'banner_content' (vedi sections_schema()).
		 *
		 * @param array $s
		 * @return void
		 */
		private static function render_banner_content_form( $s ) {
			DBCM_Admin::form_open( 'banner_content' );
			?>

			<div class="db-ui-card">
				<div class="db-ui-card-header"><h3><?php esc_html_e( 'Lingue', 'db-cookie-manager' ); ?></h3></div>
				<div class="db-ui-card-body">
					<?php
					DBCM_Admin::field_lang_array(
						(array) $s['banner_languages'],
						__( 'Lingue attive nel banner', 'db-cookie-manager' ),
						__( 'Il banner sceglie la lingua in base a navigator.language del browser. Se la lingua del visitatore non è tra quelle attive, viene usata la lingua di default qui sotto.', 'db-cookie-manager' )
					);

					DBCM_Admin::field_select(
						'banner_default_lang',
						$s['banner_default_lang'],
						__( 'Lingua di default', 'db-cookie-manager' ),
						array(
							'it' => 'Italiano',
							'en' => 'English',
							'fr' => 'Français',
							'de' => 'Deutsch',
							'es' => 'Español',
							'pt' => 'Português',
						),
						__( 'Usata se la lingua del browser non è tra quelle attive. Deve essere una delle lingue attivate sopra.', 'db-cookie-manager' )
					);
					?>
				</div>
			</div>

			<div class="db-ui-card">
				<div class="db-ui-card-header"><h3><?php esc_html_e( 'Durata e comportamento del consenso', 'db-cookie-manager' ); ?></h3></div>
				<div class="db-ui-card-body">
					<?php
					DBCM_Admin::field_number(
						'consent_duration',
						(int) $s['consent_duration'],
						__( 'Durata del cookie di consenso (giorni)', 'db-cookie-manager' ),
						__( 'Dopo questo periodo il banner viene mostrato di nuovo. Il Garante consiglia 6 mesi (180 giorni); il default è 365.', 'db-cookie-manager' ),
						1,
						730
					);
					?>
				</div>
			</div>

			<div class="db-ui-card">
				<div class="db-ui-card-header"><h3><?php esc_html_e( 'Versione del consenso', 'db-cookie-manager' ); ?></h3></div>
				<div class="db-ui-card-body">
					<p>
						<?php
						printf(
							/* translators: %d: versione corrente del consenso */
							esc_html__( 'Versione corrente: %d. Ogni scelta degli utenti viene salvata e registrata sotto questa versione.', 'db-cookie-manager' ),
							(int) DBCM_Settings::consent_version()
						);
						?>
					</p>
					<p style="font-size:13px;color:var(--db-text-muted)">
						<?php esc_html_e( 'Il GDPR richiede che il consenso sia specifico e informato rispetto ai trattamenti presentati al momento della scelta (Art. 4(11)). Se aggiungi un nuovo tracker o cambi in modo significativo i trattamenti, i consensi già raccolti non coprono le novità: incrementa la versione per richiedere una nuova scelta a tutti i visitatori. I consensi con versione precedente vengono trattati come assenza di consenso finché l\'utente non sceglie di nuovo.', 'db-cookie-manager' ); ?>
					</p>
					<div class="db-ui-alert db-ui-alert-warning">
						<span class="db-ui-alert-icon" aria-hidden="true">⚠️</span>
						<span><?php esc_html_e( 'Usa il bottone solo per cambiamenti significativi dei trattamenti: ri-presentare il banner senza motivo genera assuefazione al consenso (consent fatigue), anch\'essa una criticità per il Garante.', 'db-cookie-manager' ); ?></span>
					</div>
				</div>
				<div class="db-ui-card-footer">
					<?php
					$bump_url = wp_nonce_url(
						admin_url( 'admin-post.php?action=dbcm_bump_consent_version' ),
						'dbcm_bump_consent_version'
					);
					?>
					<a class="db-ui-btn db-ui-btn-danger" href="<?php echo esc_url( $bump_url ); ?>"
						onclick="return confirm('<?php echo esc_js( __( 'Tutti i visitatori vedranno di nuovo il banner e dovranno esprimere una nuova scelta. I consensi esistenti verranno trattati come assenti fino ad allora. Procedere?', 'db-cookie-manager' ) ); ?>');">
						<?php esc_html_e( 'Richiedi nuovo consenso a tutti gli utenti', 'db-cookie-manager' ); ?>
					</a>
				</div>
			</div>

			<div class="db-ui-card">
				<div class="db-ui-card-header"><h3><?php esc_html_e( 'Default categorie nel pannello "Personalizza"', 'db-cookie-manager' ); ?></h3></div>
				<div class="db-ui-card-body">
					<div class="db-ui-alert db-ui-alert-warning">
						<span class="db-ui-alert-icon" aria-hidden="true">⚠️</span>
						<span><?php esc_html_e(
							'Per essere conformi al GDPR i toggle delle categorie opzionali devono essere disattivati di default. Attivarli pre-selezionati equivale a un consenso non valido.',
							'db-cookie-manager'
						); ?></span>
					</div>

					<?php
					DBCM_Admin::field_checkbox(
						'default_preferences',
						$s['default_preferences'],
						__( 'Preferenze pre-selezionata', 'db-cookie-manager' )
					);
					DBCM_Admin::field_checkbox(
						'default_statistics',
						$s['default_statistics'],
						__( 'Statistiche pre-selezionata', 'db-cookie-manager' )
					);
					DBCM_Admin::field_checkbox(
						'default_statistics_anonymous',
						$s['default_statistics_anonymous'],
						__( 'Statistiche anonime pre-selezionata', 'db-cookie-manager' ),
						__( 'Statistics-anonymous (Plausible, Umami) per alcune autorità non richiede consenso esplicito.', 'db-cookie-manager' )
					);
					DBCM_Admin::field_checkbox(
						'default_marketing',
						$s['default_marketing'],
						__( 'Marketing pre-selezionata', 'db-cookie-manager' )
					);
					?>
				</div>
			</div>

			<div class="db-ui-card">
				<div class="db-ui-card-header"><h3><?php esc_html_e( 'Cookie Policy', 'db-cookie-manager' ); ?></h3></div>
				<div class="db-ui-card-body">
					<?php
					DBCM_Admin::field_page_select(
						'policy_page_id',
						(int) $s['policy_page_id'],
						__( 'Pagina della Cookie Policy', 'db-cookie-manager' ),
						__( 'Il banner mostrerà un link a questa pagina. Crea o seleziona una pagina dedicata; la sezione "Cookie Policy" ti permetterà di generarne il contenuto basato sui cookie rilevati.', 'db-cookie-manager' )
					);
					?>
				</div>
			</div>

			<?php
			DBCM_Admin::form_close( __( 'Salva contenuto', 'db-cookie-manager' ) );
		}

	}
}
