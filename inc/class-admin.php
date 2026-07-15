<?php
/**
 * DBCM_Admin — Scaffold dell'area di amministrazione.
 *
 * Step 6a: questa classe registra il menu, gestisce il routing fra le
 * sottopagine, espone i metodi di rendering helper (open_wrap, form_open,
 * field_*) e il dispatcher di salvataggio centralizzato.
 *
 * Le pagine vere e proprie (Banner, Scanner, Cookie Policy, Consent Log,
 * Avanzate) sono qui come STUB — il rendering completo arriva negli step
 * 6b, 6c, 6d. La Dashboard è già funzionale perché mostra solo statistiche
 * lette dai dati esistenti (count_by_category dello scanner, count del
 * consent log) e link alle altre sezioni.
 *
 * Sicurezza:
 *  - Tutte le pagine: capability_check 'manage_options'.
 *  - Tutti i form: nonce.
 *  - Dispatcher: nonce + capability + sanitize per chiave secondo una
 *    schema map (sanitize_section()).
 *  - Salvataggio via admin-post.php → redirect via wp_safe_redirect con
 *    flash message in querystring (no echo prima dei header).
 *
 * @package DBCM
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DBCM_Admin' ) ) {

	class DBCM_Admin {

		/**
		 * Slug del menu di primo livello.
		 */
		const MENU_SLUG = 'dbcm';

		/**
		 * Capability necessaria per accedere a tutte le pagine.
		 */
		const CAP = 'manage_options';

		/**
		 * Nome dell'action nonce per il salvataggio.
		 */
		const NONCE_ACTION = 'dbcm_save_settings';

		/**
		 * Nome del campo nonce nei form.
		 */
		const NONCE_FIELD = 'dbcm_settings_nonce';

		/**
		 * Nome dell'admin-post action per il salvataggio.
		 */
		const SAVE_ACTION = 'dbcm_save_settings';

		/**
		 * Inizializzazione — chiamata da DBCM_Plugin->init_modules().
		 */
		public static function init() {
			if ( ! is_admin() ) {
				return;
			}
			add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
			add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
			add_action( 'admin_post_' . self::SAVE_ACTION, array( __CLASS__, 'handle_save' ) );

			// Step 6c: handler dedicato per creare/aggiornare la pagina della Cookie Policy.
			add_action( 'admin_post_dbcm_create_policy_page', array( __CLASS__, 'handle_create_policy_page' ) );

			// Firme personalizzate (aggiunta manuale + import/export). Non sono
			// settings ma una option separata gestita da DBCM_Signatures::save_custom(),
			// quindi hanno handler dedicati invece di passare da handle_save().
			add_action( 'admin_post_dbcm_save_signatures', array( __CLASS__, 'handle_save_signatures' ) );
			add_action( 'admin_post_dbcm_delete_signature', array( __CLASS__, 'handle_delete_signature' ) );
			add_action( 'admin_post_dbcm_import_signatures', array( __CLASS__, 'handle_import_signatures' ) );
			add_action( 'admin_post_dbcm_export_signatures', array( __CLASS__, 'handle_export_signatures' ) );

			// Notice di attivazione plugin (banner setup hint).
			add_action( 'admin_notices', array( __CLASS__, 'render_flash_notices' ) );
		}

		/* =====================================================================
		 * MENU
		 * ================================================================== */

		/**
		 * Registra il menu di primo livello + sottopagine.
		 *
		 * Scelgo "manage_options" e l'icona dashicon "shield-alt" (privacy).
		 * Le sottopagine sono in ordine d'uso atteso, NON alfabetico.
		 *
		 * @return void
		 */
		public static function register_menu() {
			$pages = self::pages();

			// Top-level menu.
			add_menu_page(
				__( 'DB Cookie Manager', 'db-cookie-manager' ),
				__( 'Cookie Manager', 'db-cookie-manager' ),
				self::CAP,
				self::MENU_SLUG,
				array( __CLASS__, 'render_dashboard' ),
				'dashicons-shield-alt',
				81 // dopo "Impostazioni" (80).
			);

			// Sotto-pagine. La prima viene rinominata da WordPress
			// (altrimenti il menu mostrerebbe "DB Cookie Manager" duplicato).
			foreach ( $pages as $slug => $page ) {
				$menu_slug = ( 'dashboard' === $slug ) ? self::MENU_SLUG : self::MENU_SLUG . '-' . $slug;
				add_submenu_page(
					self::MENU_SLUG,
					$page['title'],
					$page['menu'],
					self::CAP,
					$menu_slug,
					array( __CLASS__, $page['callback'] )
				);
			}
		}

		/**
		 * Mappa delle pagine. Ogni voce ha:
		 *  - title    → <title> della pagina
		 *  - menu     → label nel menu sidebar
		 *  - callback → metodo di render_*
		 *
		 * @return array
		 */
		public static function pages() {
			return array(
				'dashboard' => array(
					'title'    => __( 'Cookie Manager — Dashboard', 'db-cookie-manager' ),
					'menu'     => __( 'Dashboard', 'db-cookie-manager' ),
					'callback' => 'render_dashboard',
				),
				'banner' => array(
					'title'    => __( 'Cookie Manager — Banner & aspetto', 'db-cookie-manager' ),
					'menu'     => __( 'Banner & aspetto', 'db-cookie-manager' ),
					'callback' => 'render_banner',
				),
				'scanner' => array(
					'title'    => __( 'Cookie Manager — Scanner', 'db-cookie-manager' ),
					'menu'     => __( 'Scanner', 'db-cookie-manager' ),
					'callback' => 'render_scanner',
				),
				'signatures' => array(
					'title'    => __( 'Cookie Manager — Firme personalizzate', 'db-cookie-manager' ),
					'menu'     => __( 'Firme personalizzate', 'db-cookie-manager' ),
					'callback' => 'render_signatures',
				),
				'policy' => array(
					'title'    => __( 'Cookie Manager — Cookie Policy', 'db-cookie-manager' ),
					'menu'     => __( 'Cookie Policy', 'db-cookie-manager' ),
					'callback' => 'render_policy',
				),
				'log' => array(
					'title'    => __( 'Cookie Manager — Registro consensi', 'db-cookie-manager' ),
					'menu'     => __( 'Registro consensi', 'db-cookie-manager' ),
					'callback' => 'render_log',
				),
				'advanced' => array(
					'title'    => __( 'Cookie Manager — Avanzate', 'db-cookie-manager' ),
					'menu'     => __( 'Avanzate', 'db-cookie-manager' ),
					'callback' => 'render_advanced',
				),
			);
		}

		/* =====================================================================
		 * ASSETS
		 * ================================================================== */

		/**
		 * Enqueue di db-admin-ui.css + admin.css + admin.js.
		 * Carica solo sulle nostre pagine (l'hook contiene MENU_SLUG).
		 *
		 * @param string $hook
		 * @return void
		 */
		public static function enqueue_assets( $hook ) {
			if ( false === strpos( (string) $hook, self::MENU_SLUG ) ) {
				return;
			}

			// Design system condiviso.
			wp_enqueue_style(
				'db-admin-ui',
				DBCM_URL . 'assets/css/db-admin-ui.css',
				array(),
				'1.0.0'
			);

			// CSS specifico del plugin (override di db-admin-ui dove serve).
			wp_enqueue_style(
				'dbcm-admin',
				DBCM_URL . 'assets/css/admin.css',
				array( 'db-admin-ui' ),
				DBCM_VERSION
			);

			// JS admin.
			wp_enqueue_script(
				'dbcm-admin',
				DBCM_URL . 'assets/js/admin.js',
				array( 'jquery' ),
				DBCM_VERSION,
				true
			);

			wp_localize_script(
				'dbcm-admin',
				'dbcmAdmin',
				array(
					'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
					'scannerNonce' => wp_create_nonce( 'dbcm_scanner_nonce' ),
					'i18n'         => array(
						'confirmDelete'   => __( 'Confermi l\'eliminazione?', 'db-cookie-manager' ),
						'scanInProgress'  => __( 'Scansione in corso…', 'db-cookie-manager' ),
						'scanComplete'    => __( 'Scansione completata.', 'db-cookie-manager' ),
						'scanError'       => __( 'Errore durante la scansione.', 'db-cookie-manager' ),
						'copied'          => __( 'Copiato negli appunti.', 'db-cookie-manager' ),
					),
				)
			);
		}

		/* =====================================================================
		 * SAVE DISPATCHER
		 *
		 * Tutti i form puntano a admin-post.php?action=dbcm_save_settings.
		 * Il dispatcher verifica nonce + capability, sanifica i campi della
		 * sezione indicata, salva via DBCM_Settings, redirige con flash.
		 * ================================================================== */

		/**
		 * Handler di admin_post_dbcm_save_settings.
		 *
		 * @return void (esce con wp_safe_redirect + exit)
		 */
		public static function handle_save() {
			if ( ! current_user_can( self::CAP ) ) {
				wp_die(
					esc_html__( 'Permessi insufficienti.', 'db-cookie-manager' ),
					'',
					array( 'response' => 403 )
				);
			}

			$nonce = isset( $_POST[ self::NONCE_FIELD ] )
				? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) )
				: '';
			if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
				wp_die(
					esc_html__( 'Token di sicurezza scaduto. Ricarica la pagina e riprova.', 'db-cookie-manager' ),
					'',
					array( 'response' => 403 )
				);
			}

			$section = isset( $_POST['section'] )
				? sanitize_key( wp_unslash( $_POST['section'] ) )
				: '';

			$redirect = wp_get_referer() ?: admin_url( 'admin.php?page=' . self::MENU_SLUG );

			// Sanifica e salva.
			$raw       = wp_unslash( $_POST );
			$sanitized = self::sanitize_section( $section, $raw );

			if ( false === $sanitized ) {
				wp_safe_redirect( add_query_arg( 'dbcm_msg', 'invalid_section', $redirect ) );
				exit;
			}

			// Merge nelle settings esistenti.
			$current = DBCM_Settings::all();
			foreach ( $sanitized as $k => $v ) {
				$current[ $k ] = $v;
			}
			DBCM_Settings::replace_all( $current );

			wp_safe_redirect( add_query_arg( 'dbcm_msg', 'saved', $redirect ) );
			exit;
		}

		/**
		 * Handler 'admin_post_dbcm_create_policy_page'.
		 *
		 * Crea o aggiorna la pagina WP della Cookie Policy:
		 *  - Se esiste già una pagina referenziata in 'policy_page_id' e
		 *    pubblicata → la aggiorna con il contenuto generato (flash 'policy_updated').
		 *  - Altrimenti → crea una nuova pagina pubblicata con titolo
		 *    "Cookie Policy", la salva in 'policy_page_id' e flash 'policy_created'.
		 *
		 * @return void
		 */
		public static function handle_create_policy_page() {
			if ( ! current_user_can( self::CAP ) ) {
				wp_die(
					esc_html__( 'Permessi insufficienti.', 'db-cookie-manager' ),
					'',
					array( 'response' => 403 )
				);
			}
			$nonce = isset( $_POST['_wpnonce'] )
				? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) )
				: '';
			if ( ! wp_verify_nonce( $nonce, 'dbcm_create_policy_page' ) ) {
				wp_die(
					esc_html__( 'Token di sicurezza scaduto. Ricarica la pagina e riprova.', 'db-cookie-manager' ),
					'',
					array( 'response' => 403 )
				);
			}

			$redirect = wp_get_referer() ?: admin_url( 'admin.php?page=' . self::MENU_SLUG . '-policy' );

			if ( ! class_exists( 'DBCM_Policy_Generator' ) ) {
				wp_safe_redirect( add_query_arg( 'dbcm_msg', 'policy_error', $redirect ) );
				exit;
			}

			$content    = DBCM_Policy_Generator::generate();
			$current_id = (int) DBCM_Settings::get( 'policy_page_id', 0 );

			// Se la pagina referenziata esiste e non è in cestino → update.
			if ( $current_id > 0 ) {
				$post = get_post( $current_id );
				if ( $post && 'page' === $post->post_type && 'trash' !== $post->post_status ) {
					$updated = wp_update_post(
						array(
							'ID'           => $current_id,
							'post_content' => $content,
							'post_status'  => 'publish',
						),
						true
					);
					if ( is_wp_error( $updated ) || 0 === $updated ) {
						wp_safe_redirect( add_query_arg( 'dbcm_msg', 'policy_error', $redirect ) );
						exit;
					}
					wp_safe_redirect( add_query_arg( 'dbcm_msg', 'policy_updated', $redirect ) );
					exit;
				}
			}

			// Crea una nuova pagina.
			$new_id = wp_insert_post(
				array(
					'post_type'    => 'page',
					'post_status'  => 'publish',
					'post_title'   => __( 'Cookie Policy', 'db-cookie-manager' ),
					'post_content' => $content,
					'post_author'  => get_current_user_id(),
				),
				true
			);

			if ( is_wp_error( $new_id ) || 0 === $new_id ) {
				wp_safe_redirect( add_query_arg( 'dbcm_msg', 'policy_error', $redirect ) );
				exit;
			}

			DBCM_Settings::update( 'policy_page_id', (int) $new_id );

			wp_safe_redirect( add_query_arg( 'dbcm_msg', 'policy_created', $redirect ) );
			exit;
		}

		/**
		 * Sanitizza una sezione delle settings.
		 *
		 * Ogni sezione dichiara esplicitamente le chiavi che accetta e il
		 * loro tipo. Chiavi non in lista vengono ignorate — questo evita
		 * mass-assignment: un form "banner_appearance" non può sovrascrivere
		 * un'opzione di "log_settings" anche se viene manipolato il POST.
		 *
		 * @param string $section
		 * @param array  $raw     Il $_POST raw.
		 * @return array|false Array di chiavi sanitizzate, o false se sezione invalida.
		 */
		private static function sanitize_section( $section, $raw ) {
			$schema = self::sections_schema();
			if ( ! isset( $schema[ $section ] ) ) {
				return false;
			}

			$out = array();
			foreach ( $schema[ $section ] as $key => $type ) {
				$value = $raw[ $key ] ?? null;
				$out[ $key ] = self::sanitize_value( $value, $type, $key );
			}
			return $out;
		}

		/**
		 * Schema di tutte le sezioni: chiave → tipo.
		 *
		 * Tipi supportati: text, textarea, html, bool, int, float, color,
		 * select:val1|val2|val3, page_id, lang_array, ua_mode.
		 *
		 * @return array
		 */
		private static function sections_schema() {
			return array(

				/* ----------------------------------------------------------
				 * BANNER & aspetto (UI step 6b)
				 * ---------------------------------------------------------- */
				'banner_appearance' => array(
					'banner_enabled'        => 'bool',
					'banner_layout'         => 'select:box|bar',
					'banner_position'       => 'select:bottom-right|bottom-left|bottom-center',
					'banner_overlay'        => 'bool',
					'banner_theme'          => 'select:light|dark|auto',
					'banner_color_bg'       => 'color',
					'banner_color_text'     => 'color',
					'banner_color_btn'      => 'color',
					'banner_color_btn_text' => 'color',
					'banner_credits'        => 'bool',
					'banner_custom_css'     => 'textarea',
					'show_reopen_btn'       => 'bool',
					'reopen_position'       => 'select:bottom-left|bottom-right|top-left|top-right',
				),

				'banner_content' => array(
					'banner_languages'              => 'lang_array',
					'banner_default_lang'           => 'select:it|en|fr|de|es|pt',
					'consent_duration'              => 'int',
					'reconsent_on_change'           => 'bool',
					'default_preferences'           => 'bool',
					'default_statistics'            => 'bool',
					'default_statistics_anonymous'  => 'bool',
					'default_marketing'             => 'bool',
					'policy_page_id'                => 'page_id',
				),

				/* ----------------------------------------------------------
				 * SCANNER (UI step 6c)
				 * ---------------------------------------------------------- */
				'scanner_settings' => array(
					'auto_block' => 'bool',
				),

				/* ----------------------------------------------------------
				 * CONSENT LOG (UI step 6d)
				 * ---------------------------------------------------------- */
				'log_settings' => array(
					'consent_log_enabled'    => 'bool',
					'consent_log_retention'  => 'int',
					'consent_log_user_agent' => 'ua_mode',
				),

				/* ----------------------------------------------------------
				 * AVANZATE (UI step 6d)
				 * ---------------------------------------------------------- */
				'advanced' => array(
					'respect_dnt'    => 'bool',
					'respect_gpc'    => 'bool',
					'geo_targeting'  => 'bool',
					'gcm_enabled'    => 'bool',
					'localize_google_fonts' => 'bool',
				),
			);
		}

		/**
		 * Sanitizza un singolo valore in base al tipo.
		 *
		 * @param mixed  $value
		 * @param string $type
		 * @param string $key   Nome chiave (per debugging/validazione contestuale).
		 * @return mixed
		 */
		private static function sanitize_value( $value, $type, $key = '' ) {
			// "select:a|b|c"
			if ( 0 === strpos( $type, 'select:' ) ) {
				$allowed = explode( '|', substr( $type, 7 ) );
				$value   = is_string( $value ) ? sanitize_key( $value ) : '';
				return in_array( $value, $allowed, true ) ? $value : $allowed[0];
			}

			switch ( $type ) {
				case 'bool':
					// Checkbox non spuntate non arrivano in $_POST — null = false.
					return ! empty( $value );

				case 'int':
					$n = is_numeric( $value ) ? (int) $value : 0;
					// Clamp specifici per chiave.
					if ( 'consent_duration' === $key ) {
						return max( 1, min( 730, $n ) ); // 1..730 giorni
					}
					if ( 'consent_log_retention' === $key ) {
						return max( 0, min( 3650, $n ) ); // 0..10 anni
					}
					return $n;

				case 'float':
					return is_numeric( $value ) ? (float) $value : 0.0;

				case 'text':
					return is_string( $value ) ? sanitize_text_field( $value ) : '';

				case 'textarea':
					// kses con whitelist permissiva (per custom CSS, niente HTML).
					return is_string( $value ) ? wp_strip_all_tags( $value ) : '';

				case 'html':
					return is_string( $value ) ? wp_kses_post( $value ) : '';

				case 'color':
					if ( ! is_string( $value ) ) {
						return '#000000';
					}
					$value = sanitize_hex_color( $value );
					return $value ? $value : '#000000';

				case 'page_id':
					$id = (int) $value;
					return ( $id > 0 && get_post_status( $id ) ) ? $id : 0;

				case 'ua_mode':
					$value = is_string( $value ) ? sanitize_key( $value ) : '';
					return in_array( $value, array( 'none', 'aggregate', 'full' ), true ) ? $value : 'aggregate';

				case 'lang_array':
					// Array di codici lingua a 2 char fra quelli supportati.
					$allowed = array( 'it', 'en', 'fr', 'de', 'es', 'pt' );
					if ( ! is_array( $value ) ) {
						return array( 'it' );
					}
					$out = array();
					foreach ( $value as $v ) {
						$v = is_string( $v ) ? sanitize_key( $v ) : '';
						if ( in_array( $v, $allowed, true ) && ! in_array( $v, $out, true ) ) {
							$out[] = $v;
						}
					}
					return ! empty( $out ) ? $out : array( 'it' );

				default:
					// Tipo sconosciuto: per sicurezza, sanitize_text_field.
					return is_string( $value ) ? sanitize_text_field( $value ) : '';
			}
		}

		/* =====================================================================
		 * FLASH MESSAGES (querystring → admin_notice)
		 * ================================================================== */

		/**
		 * Renderizza i flash message dopo un redirect post-save.
		 *
		 * @return void
		 */
		public static function render_flash_notices() {
			// Mostra i flash solo sulle nostre pagine.
			$screen = get_current_screen();
			if ( ! $screen || false === strpos( (string) $screen->id, self::MENU_SLUG ) ) {
				return;
			}

			$msg = isset( $_GET['dbcm_msg'] ) ? sanitize_key( wp_unslash( $_GET['dbcm_msg'] ) ) : '';
			if ( '' === $msg ) {
				return;
			}

			$messages = array(
				'saved'           => array( 'success', __( 'Impostazioni salvate.', 'db-cookie-manager' ) ),
				'invalid_section' => array( 'error',   __( 'Sezione non valida.', 'db-cookie-manager' ) ),
				'scan_done'       => array( 'success', __( 'Scansione completata.', 'db-cookie-manager' ) ),
				'policy_created'  => array( 'success', __( 'Pagina della Cookie Policy creata e collegata al banner.', 'db-cookie-manager' ) ),
				'policy_updated'  => array( 'success', __( 'Pagina della Cookie Policy aggiornata.', 'db-cookie-manager' ) ),
				'policy_error'    => array( 'error',   __( 'Errore nella creazione della pagina della Cookie Policy.', 'db-cookie-manager' ) ),
				'sig_saved'       => array( 'success', __( 'Firma personalizzata salvata.', 'db-cookie-manager' ) ),
				'sig_deleted'     => array( 'success', __( 'Firma personalizzata eliminata.', 'db-cookie-manager' ) ),
				'sig_imported'    => array( 'success', __( 'Firme importate con successo.', 'db-cookie-manager' ) ),
				'sig_error'       => array( 'error',   __( 'Dati della firma non validi. Controlla i campi e riprova.', 'db-cookie-manager' ) ),
				'sig_import_error' => array( 'error',  __( 'Import fallito: JSON non valido o struttura non riconosciuta.', 'db-cookie-manager' ) ),
			);

			if ( ! isset( $messages[ $msg ] ) ) {
				return;
			}
			list( $type, $text ) = $messages[ $msg ];
			printf(
				'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
				esc_attr( $type ),
				esc_html( $text )
			);
		}

		/* =====================================================================
		 * RENDER HELPER (riutilizzabili dalle pagine)
		 *
		 * Convenzione: i metodi open_*  e form_* echo direttamente; i field_*
		 * sono helper di basso livello richiamabili dentro un .db-ui-card-body.
		 * ================================================================== */

		/**
		 * Apre il <div class="wrap"> con header standard.
		 *
		 * @param string $title
		 * @param string $subtitle
		 * @param string $actions_html  HTML opzionale di azioni nel header.
		 * @return void
		 */
		public static function open_wrap( $title, $subtitle = '', $actions_html = '' ) {
			echo '<div class="wrap dbcm-wrap">';
			echo '<div class="db-ui-page-header">';
			echo '<div>';
			echo '<h1>' . esc_html( $title ) . '</h1>';
			if ( '' !== $subtitle ) {
				echo '<p class="description" style="margin:4px 0 0">' . esc_html( $subtitle ) . '</p>';
			}
			echo '</div>';
			if ( '' !== $actions_html ) {
				// $actions_html è costruito dall'admin (mai input utente).
				echo '<div class="db-ui-actions">' . $actions_html . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</div>';
		}

		public static function close_wrap() {
			echo '</div>'; // .wrap
		}

		/**
		 * Apre un <form> verso admin-post.php con nonce e section nascosti.
		 *
		 * @param string $section Nome della sezione (deve esistere in sections_schema).
		 * @param string $css_class Classe extra.
		 * @return void
		 */
		public static function form_open( $section, $css_class = 'dbcm-form' ) {
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="<?php echo esc_attr( $css_class ); ?>">
				<input type="hidden" name="action"  value="<?php echo esc_attr( self::SAVE_ACTION ); ?>">
				<input type="hidden" name="section" value="<?php echo esc_attr( $section ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
			<?php
		}

		/**
		 * Chiude il form con il bottone di salvataggio standard.
		 *
		 * @param string $label
		 * @return void
		 */
		public static function form_close( $label = '' ) {
			$label = '' === $label ? __( 'Salva impostazioni', 'db-cookie-manager' ) : $label;
			echo '<p style="margin-top:14px"><button type="submit" class="db-ui-btn db-ui-btn-primary db-ui-btn-lg">';
			echo esc_html( $label );
			echo '</button></p>';
			echo '</form>';
		}

		/* ---------------- field_* helpers ---------------- */

		public static function field_text( $name, $value, $label, $help = '' ) {
			?>
			<div class="db-ui-field">
				<label for="dbcm-<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label>
				<input type="text" id="dbcm-<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
				<?php if ( '' !== $help ) : ?>
					<span class="db-ui-field-help"><?php echo esc_html( $help ); ?></span>
				<?php endif; ?>
			</div>
			<?php
		}

		public static function field_number( $name, $value, $label, $help = '', $min = null, $max = null ) {
			?>
			<div class="db-ui-field">
				<label for="dbcm-<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label>
				<input type="number"
					id="dbcm-<?php echo esc_attr( $name ); ?>"
					name="<?php echo esc_attr( $name ); ?>"
					value="<?php echo esc_attr( $value ); ?>"
					<?php if ( null !== $min ) : ?>min="<?php echo esc_attr( $min ); ?>"<?php endif; ?>
					<?php if ( null !== $max ) : ?>max="<?php echo esc_attr( $max ); ?>"<?php endif; ?>>
				<?php if ( '' !== $help ) : ?>
					<span class="db-ui-field-help"><?php echo esc_html( $help ); ?></span>
				<?php endif; ?>
			</div>
			<?php
		}

		public static function field_textarea( $name, $value, $label, $help = '', $rows = 4 ) {
			?>
			<div class="db-ui-field">
				<label for="dbcm-<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label>
				<textarea id="dbcm-<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" rows="<?php echo (int) $rows; ?>"><?php echo esc_textarea( $value ); ?></textarea>
				<?php if ( '' !== $help ) : ?>
					<span class="db-ui-field-help"><?php echo esc_html( $help ); ?></span>
				<?php endif; ?>
			</div>
			<?php
		}

		public static function field_checkbox( $name, $value, $label, $help = '' ) {
			?>
			<div class="db-ui-field">
				<label style="display:flex;align-items:center;gap:8px;font-weight:500">
					<input type="checkbox" id="dbcm-<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( ! empty( $value ) ); ?>>
					<span><?php echo esc_html( $label ); ?></span>
				</label>
				<?php if ( '' !== $help ) : ?>
					<span class="db-ui-field-help" style="margin-left:24px"><?php echo esc_html( $help ); ?></span>
				<?php endif; ?>
			</div>
			<?php
		}

		public static function field_select( $name, $value, $label, $options, $help = '' ) {
			?>
			<div class="db-ui-field">
				<label for="dbcm-<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label>
				<select id="dbcm-<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>">
					<?php foreach ( $options as $opt_value => $opt_label ) : ?>
						<option value="<?php echo esc_attr( $opt_value ); ?>" <?php selected( $value, $opt_value ); ?>>
							<?php echo esc_html( $opt_label ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php if ( '' !== $help ) : ?>
					<span class="db-ui-field-help"><?php echo esc_html( $help ); ?></span>
				<?php endif; ?>
			</div>
			<?php
		}

		public static function field_color( $name, $value, $label, $help = '' ) {
			?>
			<div class="db-ui-field">
				<label for="dbcm-<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label>
				<input type="color" id="dbcm-<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" style="height:36px;width:80px;padding:2px">
				<?php if ( '' !== $help ) : ?>
					<span class="db-ui-field-help"><?php echo esc_html( $help ); ?></span>
				<?php endif; ?>
			</div>
			<?php
		}

		/**
		 * Campo per selezionare una pagina pubblicata.
		 *
		 * @param string $name
		 * @param int    $value
		 * @param string $label
		 * @param string $help
		 * @return void
		 */
		public static function field_page_select( $name, $value, $label, $help = '' ) {
			$pages = get_pages( array( 'post_status' => 'publish' ) );
			?>
			<div class="db-ui-field">
				<label for="dbcm-<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label>
				<select id="dbcm-<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>">
					<option value="0"><?php esc_html_e( '— Nessuna —', 'db-cookie-manager' ); ?></option>
					<?php foreach ( $pages as $p ) : ?>
						<option value="<?php echo (int) $p->ID; ?>" <?php selected( (int) $value, (int) $p->ID ); ?>>
							<?php echo esc_html( $p->post_title ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php if ( '' !== $help ) : ?>
					<span class="db-ui-field-help"><?php echo esc_html( $help ); ?></span>
				<?php endif; ?>
			</div>
			<?php
		}

		/**
		 * Campo "lingue attive" — checkbox group con le 6 lingue supportate.
		 *
		 * @param array  $value
		 * @param string $label
		 * @param string $help
		 * @return void
		 */
		public static function field_lang_array( $value, $label, $help = '' ) {
			$langs = array(
				'it' => 'Italiano',
				'en' => 'English',
				'fr' => 'Français',
				'de' => 'Deutsch',
				'es' => 'Español',
				'pt' => 'Português',
			);
			$value = is_array( $value ) ? $value : array( 'it' );
			?>
			<div class="db-ui-field">
				<label><?php echo esc_html( $label ); ?></label>
				<div style="display:flex;flex-wrap:wrap;gap:8px 16px;margin-top:4px">
					<?php foreach ( $langs as $code => $name ) : ?>
						<label style="display:flex;align-items:center;gap:6px;font-weight:400">
							<input type="checkbox" name="banner_languages[]" value="<?php echo esc_attr( $code ); ?>"
								<?php checked( in_array( $code, $value, true ) ); ?>>
							<span><?php echo esc_html( $name ); ?> <code><?php echo esc_html( $code ); ?></code></span>
						</label>
					<?php endforeach; ?>
				</div>
				<?php if ( '' !== $help ) : ?>
					<span class="db-ui-field-help"><?php echo esc_html( $help ); ?></span>
				<?php endif; ?>
			</div>
			<?php
		}

		/* =====================================================================
		 * RENDER PAGINE
		 * ================================================================== */

		/**
		 * Dashboard: già funzionale nello step 6a perché legge solo dati esistenti.
		 *
		 * Mostra: stat box (cookie scansionati per categoria, consensi
		 * registrati, ultimo scan), card link alle altre sezioni.
		 *
		 * @return void
		 */
		public static function render_dashboard() {
			if ( ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Permessi insufficienti.', 'db-cookie-manager' ) );
			}

			self::open_wrap(
				__( 'DB Cookie Manager', 'db-cookie-manager' ),
				__( 'Gestione cookie GDPR-compliant: banner, scanner, registro consensi e cookie policy.', 'db-cookie-manager' )
			);

			// Stat: cookie per categoria.
			$by_cat = class_exists( 'DBCM_Scanner' )
				? DBCM_Scanner::count_by_category()
				: array_fill_keys( DBCM_Settings::categories(), 0 );
			$total_cookies = array_sum( $by_cat );

			// Stat: consensi negli ultimi 30 giorni.
			$consents_30d = 0;
			if ( class_exists( 'DBCM_Consent_Log' ) ) {
				$consents_30d = DBCM_Consent_Log::count(
					array(
						'date_from' => gmdate( 'Y-m-d', time() - ( 30 * DAY_IN_SECONDS ) ),
					)
				);
			}

			$last_scan = get_option( 'dbcm_last_scan', '' );

			?>
			<div class="dbcm-dashboard-stats" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:14px;margin:0 0 20px">

				<div class="db-ui-stat">
					<div class="db-ui-stat-icon db-ui-stat-icon-primary" aria-hidden="true">🍪</div>
					<div>
						<div class="db-ui-stat-value"><?php echo (int) $total_cookies; ?></div>
						<div class="db-ui-stat-label"><?php esc_html_e( 'Cookie scansionati', 'db-cookie-manager' ); ?></div>
					</div>
				</div>

				<div class="db-ui-stat">
					<div class="db-ui-stat-icon db-ui-stat-icon-success" aria-hidden="true">✓</div>
					<div>
						<div class="db-ui-stat-value"><?php echo (int) $consents_30d; ?></div>
						<div class="db-ui-stat-label"><?php esc_html_e( 'Consensi (30 giorni)', 'db-cookie-manager' ); ?></div>
					</div>
				</div>

				<div class="db-ui-stat">
					<div class="db-ui-stat-icon db-ui-stat-icon-warning" aria-hidden="true">⏱</div>
					<div>
						<div class="db-ui-stat-value" style="font-size:14px;line-height:1.3">
							<?php echo $last_scan ? esc_html( mysql2date( get_option( 'date_format' ), $last_scan ) ) : esc_html__( 'Mai', 'db-cookie-manager' ); ?>
						</div>
						<div class="db-ui-stat-label"><?php esc_html_e( 'Ultima scansione', 'db-cookie-manager' ); ?></div>
					</div>
				</div>

				<div class="db-ui-stat">
					<div class="db-ui-stat-icon db-ui-stat-icon-primary" aria-hidden="true">🔌</div>
					<div>
						<div class="db-ui-stat-value" style="font-size:14px;line-height:1.3">
							<?php echo function_exists( 'wp_set_consent' ) ? esc_html__( 'Attiva', 'db-cookie-manager' ) : esc_html__( 'Non rilevata', 'db-cookie-manager' ); ?>
						</div>
						<div class="db-ui-stat-label">WP Consent API</div>
					</div>
				</div>

			</div>

			<div class="db-ui-alert db-ui-alert-info">
				<span class="db-ui-alert-icon" aria-hidden="true">ℹ️</span>
				<span><?php esc_html_e(
					'Tutti i cookie di terze parti (analytics, marketing, embed) sono bloccati preventivamente finché l\'utente non concede il consenso. Il banner usa le 5 categorie standard WP Consent API.',
					'db-cookie-manager'
				); ?></span>
			</div>

			<h2 style="margin-top:24px"><?php esc_html_e( 'Sezioni', 'db-cookie-manager' ); ?></h2>

			<div class="dbcm-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px">
				<?php
				foreach ( self::pages() as $slug => $page ) {
					if ( 'dashboard' === $slug ) {
						continue;
					}
					$url = admin_url( 'admin.php?page=' . self::MENU_SLUG . '-' . $slug );
					$desc = self::page_description( $slug );
					?>
					<a href="<?php echo esc_url( $url ); ?>" class="db-ui-card dbcm-dash-card" style="text-decoration:none;color:inherit;display:block">
						<div class="db-ui-card-body">
							<strong style="font-size:15px"><?php echo esc_html( $page['menu'] ); ?></strong>
							<p class="description" style="margin:6px 0 0"><?php echo esc_html( $desc ); ?></p>
						</div>
					</a>
					<?php
				}
				?>
			</div>

			<?php
			self::close_wrap();
		}

		/**
		 * Descrizione breve di una pagina, per le card della dashboard.
		 *
		 * @param string $slug
		 * @return string
		 */
		private static function page_description( $slug ) {
			$desc = array(
				'banner'   => __( 'Aspetto, posizione, lingue, durata cookie e default delle categorie.', 'db-cookie-manager' ),
				'scanner'  => __( 'Scansione automatica dei cookie del sito e classificazione.', 'db-cookie-manager' ),
				'policy'   => __( 'Genera la Cookie Policy basata sui cookie rilevati.', 'db-cookie-manager' ),
				'log'      => __( 'Registro dei consensi raccolti, con export CSV e JSON.', 'db-cookie-manager' ),
				'advanced' => __( 'Segnali browser (DNT, GPC), geo-targeting e altre opzioni.', 'db-cookie-manager' ),
			);
			return $desc[ $slug ] ?? '';
		}

		/* ---------------------------------------------------------------------
		 * Stub delle altre pagine — verranno implementate negli step 6b/6c/6d
		 *
		 * Ognuno di questi stub mostra un placeholder pulito ma utile: la
		 * pagina è già accessibile, l'utente sa che esiste, niente errori
		 * fatali. Il salvataggio funziona già: una volta che 6b/6c/6d
		 * popoleranno render_*, i form salveranno via il dispatcher centrale.
		 * ------------------------------------------------------------------ */

		public static function render_banner() {
			if ( ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Permessi insufficienti.', 'db-cookie-manager' ) );
			}

			$s = DBCM_Settings::all();

			self::open_wrap(
				__( 'Banner & aspetto', 'db-cookie-manager' ),
				__( 'Configura come appare il banner ai visitatori e cosa viene loro chiesto.', 'db-cookie-manager' )
			);

			self::render_banner_appearance_form( $s );
			self::render_banner_content_form( $s );

			self::close_wrap();
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
			self::form_open( 'banner_appearance' );
			?>

			<div class="db-ui-card">
				<div class="db-ui-card-header"><h3><?php esc_html_e( 'Visibilità & layout', 'db-cookie-manager' ); ?></h3></div>
				<div class="db-ui-card-body">
					<?php
					self::field_checkbox(
						'banner_enabled',
						$s['banner_enabled'],
						__( 'Abilita il banner cookie', 'db-cookie-manager' ),
						__( 'Se disabilitato il banner non viene mostrato. Il blocco preventivo degli script di tracking continua a funzionare in base al cookie esistente.', 'db-cookie-manager' )
					);

					self::field_select(
						'banner_layout',
						$s['banner_layout'],
						__( 'Layout', 'db-cookie-manager' ),
						array(
							'box' => __( 'Riquadro flottante (consigliato)', 'db-cookie-manager' ),
							'bar' => __( 'Barra a tutta larghezza', 'db-cookie-manager' ),
						),
						__( 'Il riquadro flottante è meno invasivo. La barra a tutta larghezza ha più visibilità ma copre più contenuto.', 'db-cookie-manager' )
					);

					self::field_select(
						'banner_position',
						$s['banner_position'],
						__( 'Posizione (solo layout "riquadro")', 'db-cookie-manager' ),
						array(
							'bottom-right'  => __( 'In basso a destra', 'db-cookie-manager' ),
							'bottom-left'   => __( 'In basso a sinistra', 'db-cookie-manager' ),
							'bottom-center' => __( 'In basso al centro', 'db-cookie-manager' ),
						)
					);

					self::field_checkbox(
						'banner_overlay',
						$s['banner_overlay'],
						__( 'Mostra sfondo scuro semitrasparente dietro al banner', 'db-cookie-manager' ),
						__( 'Aumenta la visibilità ma blocca l\'interazione con il sito finché l\'utente non sceglie. Sconsigliato per UX in stile soft-paywall.', 'db-cookie-manager' )
					);

					self::field_checkbox(
						'show_reopen_btn',
						$s['show_reopen_btn'],
						__( 'Mostra il pulsante flottante "Modifica preferenze"', 'db-cookie-manager' ),
						__( 'Permette all\'utente di riaprire il banner in qualsiasi momento. Lo step 7 aggiungerà uno shortcode per posizionarlo dove vuoi (es. nel footer).', 'db-cookie-manager' )
					);

					self::field_select(
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
					self::field_select(
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
						self::field_color(
							'banner_color_bg',
							$s['banner_color_bg'],
							__( 'Sfondo banner', 'db-cookie-manager' )
						);
						self::field_color(
							'banner_color_text',
							$s['banner_color_text'],
							__( 'Testo banner', 'db-cookie-manager' )
						);
						self::field_color(
							'banner_color_btn',
							$s['banner_color_btn'],
							__( 'Sfondo bottone primario', 'db-cookie-manager' )
						);
						self::field_color(
							'banner_color_btn_text',
							$s['banner_color_btn_text'],
							__( 'Testo bottone primario', 'db-cookie-manager' )
						);
						?>
					</div>

					<?php
					self::field_checkbox(
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
					self::field_textarea(
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
			self::form_close( __( 'Salva aspetto', 'db-cookie-manager' ) );
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
			self::form_open( 'banner_content' );
			?>

			<div class="db-ui-card">
				<div class="db-ui-card-header"><h3><?php esc_html_e( 'Lingue', 'db-cookie-manager' ); ?></h3></div>
				<div class="db-ui-card-body">
					<?php
					self::field_lang_array(
						(array) $s['banner_languages'],
						__( 'Lingue attive nel banner', 'db-cookie-manager' ),
						__( 'Il banner sceglie la lingua in base a navigator.language del browser. Se la lingua del visitatore non è tra quelle attive, viene usata la lingua di default qui sotto.', 'db-cookie-manager' )
					);

					self::field_select(
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
					self::field_number(
						'consent_duration',
						(int) $s['consent_duration'],
						__( 'Durata del cookie di consenso (giorni)', 'db-cookie-manager' ),
						__( 'Dopo questo periodo il banner viene mostrato di nuovo. Il Garante consiglia 6 mesi (180 giorni); il default è 365.', 'db-cookie-manager' ),
						1,
						730
					);

					self::field_checkbox(
						'reconsent_on_change',
						$s['reconsent_on_change'],
						__( 'Richiedi nuovo consenso quando cambia la cookie policy', 'db-cookie-manager' ),
						__( 'Quando aggiorni la lista dei cookie tramite lo Scanner, gli utenti vedranno di nuovo il banner per riconfermare le preferenze.', 'db-cookie-manager' )
					);
					?>
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
					self::field_checkbox(
						'default_preferences',
						$s['default_preferences'],
						__( 'Preferenze pre-selezionata', 'db-cookie-manager' )
					);
					self::field_checkbox(
						'default_statistics',
						$s['default_statistics'],
						__( 'Statistiche pre-selezionata', 'db-cookie-manager' )
					);
					self::field_checkbox(
						'default_statistics_anonymous',
						$s['default_statistics_anonymous'],
						__( 'Statistiche anonime pre-selezionata', 'db-cookie-manager' ),
						__( 'Statistics-anonymous (Plausible, Umami) per alcune autorità non richiede consenso esplicito.', 'db-cookie-manager' )
					);
					self::field_checkbox(
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
					self::field_page_select(
						'policy_page_id',
						(int) $s['policy_page_id'],
						__( 'Pagina della Cookie Policy', 'db-cookie-manager' ),
						__( 'Il banner mostrerà un link a questa pagina. Crea o seleziona una pagina dedicata; la sezione "Cookie Policy" ti permetterà di generarne il contenuto basato sui cookie rilevati.', 'db-cookie-manager' )
					);
					?>
				</div>
			</div>

			<?php
			self::form_close( __( 'Salva contenuto', 'db-cookie-manager' ) );
		}

		public static function render_scanner() {
			if ( ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Permessi insufficienti.', 'db-cookie-manager' ) );
			}

			$s         = DBCM_Settings::all();
			$last_scan = get_option( 'dbcm_last_scan', '' );
			$by_cat    = DBCM_Scanner::count_by_category();
			$total     = array_sum( $by_cat );
			$grouped   = DBCM_Scanner::get_results_grouped();

			self::open_wrap(
				__( 'Scanner cookie', 'db-cookie-manager' ),
				__( 'Scansiona automaticamente il sito per identificare i cookie installati e classificarli.', 'db-cookie-manager' )
			);

			?>

			<div class="db-ui-card">
				<div class="db-ui-card-header"><h3><?php esc_html_e( 'Stato', 'db-cookie-manager' ); ?></h3></div>
				<div class="db-ui-card-body">
					<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px">

						<div class="db-ui-stat">
							<div class="db-ui-stat-icon db-ui-stat-icon-primary" aria-hidden="true">🍪</div>
							<div>
								<div class="db-ui-stat-value"><?php echo (int) $total; ?></div>
								<div class="db-ui-stat-label"><?php esc_html_e( 'Cookie totali', 'db-cookie-manager' ); ?></div>
							</div>
						</div>

						<div class="db-ui-stat">
							<div class="db-ui-stat-icon db-ui-stat-icon-warning" aria-hidden="true">⏱</div>
							<div>
								<div class="db-ui-stat-value" style="font-size:14px;line-height:1.3">
									<?php echo $last_scan ? esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_scan ) ) : esc_html__( 'Mai', 'db-cookie-manager' ); ?>
								</div>
								<div class="db-ui-stat-label"><?php esc_html_e( 'Ultima scansione', 'db-cookie-manager' ); ?></div>
							</div>
						</div>
					</div>

					<?php if ( $total > 0 ) : ?>
					<div style="margin-top:14px;display:flex;flex-wrap:wrap;gap:6px">
						<?php foreach ( $by_cat as $cat => $n ) : if ( 0 === $n ) continue; ?>
							<span class="db-ui-badge" style="background:<?php echo esc_attr( DBCM_Cookie_Database::get_category_color( $cat ) ); ?>;color:#fff">
								<?php echo esc_html( DBCM_Cookie_Database::get_category_label( $cat ) ); ?>: <?php echo (int) $n; ?>
							</span>
						<?php endforeach; ?>
					</div>
					<?php endif; ?>
				</div>
			</div>

			<?php self::render_scan_diff_card(); ?>

			<div class="db-ui-card">
				<div class="db-ui-card-header"><h3><?php esc_html_e( 'Avvia scansione', 'db-cookie-manager' ); ?></h3></div>
				<div class="db-ui-card-body">
					<p><?php esc_html_e( 'La scansione visita le pagine principali del sito (homepage, ultime pagine e post, eventuali pagine WooCommerce) e analizza i cookie scritti tramite Set-Cookie header e quelli inferiti dal contenuto HTML (script di analytics, embed video, ecc.).', 'db-cookie-manager' ); ?></p>

					<button type="button" id="dbcm-scan-start" class="db-ui-btn db-ui-btn-primary db-ui-btn-lg">
						<span class="dashicons dashicons-update" style="vertical-align:text-bottom"></span>
						<?php esc_html_e( 'Scansiona ora', 'db-cookie-manager' ); ?>
					</button>

					<div id="dbcm-scan-progress-wrap" style="margin-top:14px;display:none">
						<div class="db-ui-progress">
							<div class="db-ui-progress-fill" id="dbcm-scan-progress-bar" style="width:0%"></div>
						</div>
						<p id="dbcm-scan-status" class="db-ui-field-help" style="margin-top:6px"></p>
					</div>

					<div id="dbcm-scan-error" class="db-ui-alert db-ui-alert-danger" style="display:none;margin-top:14px">
						<span class="db-ui-alert-icon" aria-hidden="true">⚠️</span>
						<span id="dbcm-scan-error-msg"></span>
					</div>
				</div>
			</div>

			<?php
			// Form impostazioni scanner (auto_block).
			self::form_open( 'scanner_settings' );
			?>
			<div class="db-ui-card">
				<div class="db-ui-card-header"><h3><?php esc_html_e( 'Impostazioni scanner', 'db-cookie-manager' ); ?></h3></div>
				<div class="db-ui-card-body">
					<?php
					self::field_checkbox(
						'auto_block',
						$s['auto_block'],
						__( 'Blocco preventivo degli script di tracking', 'db-cookie-manager' ),
						__( 'Quando attivo, gli script di analytics/marketing vengono bloccati prima che il browser li esegua. Disabilitare solo se hai un altro consent manager attivo.', 'db-cookie-manager' )
					);
					?>
				</div>
			</div>
			<?php
			self::form_close( __( 'Salva impostazioni scanner', 'db-cookie-manager' ) );

			// Tabella risultati raggruppata per categoria.
			if ( ! empty( $grouped ) ) {
				self::render_scanner_results_table( $grouped );
			} elseif ( '' === $last_scan ) {
				?>
				<div class="db-ui-card">
					<div class="db-ui-card-body">
						<div class="db-ui-empty">
							<span class="db-ui-empty-icon" aria-hidden="true">🔍</span>
							<span class="db-ui-empty-text">
								<?php esc_html_e( 'Nessuna scansione eseguita ancora. Premi "Scansiona ora" per iniziare.', 'db-cookie-manager' ); ?>
							</span>
						</div>
					</div>
				</div>
				<?php
			}

			self::close_wrap();
		}

		private static function render_scan_diff_card() {
			if ( ! class_exists( 'DBCM_Scanner' ) || ! method_exists( 'DBCM_Scanner', 'get_scan_diff' ) ) {
				return;
			}
			$diff = DBCM_Scanner::get_scan_diff();
			if ( empty( $diff['has_previous'] ) ) {
				return;
			}
			$added   = isset( $diff['added'] ) ? $diff['added'] : array();
			$removed = isset( $diff['removed'] ) ? $diff['removed'] : array();

			echo '<div class="db-ui-card">';
			echo '<div class="db-ui-card-header"><h3>' . esc_html__( 'Modifiche dall\'ultima scansione', 'db-cookie-manager' ) . '</h3></div>';
			echo '<div class="db-ui-card-body">';

			if ( empty( $added ) && empty( $removed ) ) {
				echo '<div class="db-ui-alert db-ui-alert-success" style="margin:0"><span class="db-ui-alert-icon" aria-hidden="true">&#10003;</span><span>' . esc_html__( 'Nessuna modifica: gli stessi cookie della scansione precedente.', 'db-cookie-manager' ) . '</span></div>';
				echo '</div></div>';
				return;
			}

			echo '<p style="margin:0 0 12px;font-size:13px;color:#646970">';
			printf(
				/* translators: 1: numero cookie nuovi, 2: numero cookie rimossi */
				esc_html__( '%1$d nuovi, %2$d rimossi rispetto alla scansione precedente.', 'db-cookie-manager' ),
				count( $added ),
				count( $removed )
			);
			echo '</p>';

			if ( ! empty( $added ) ) {
				echo '<h4 style="margin:8px 0 6px;color:#b32d2e">' . esc_html__( 'Cookie nuovi', 'db-cookie-manager' ) . '</h4>';
				self::render_scan_diff_list( $added, 'added' );
			}
			if ( ! empty( $removed ) ) {
				echo '<h4 style="margin:14px 0 6px;color:#207a30">' . esc_html__( 'Cookie rimossi', 'db-cookie-manager' ) . '</h4>';
				self::render_scan_diff_list( $removed, 'removed' );
			}

			echo '</div></div>';
		}

		private static function render_scan_diff_list( $items, $kind ) {
			$border = ( 'added' === $kind ) ? '#b32d2e' : '#207a30';
			echo '<ul style="margin:0;padding:0;list-style:none">';
			foreach ( $items as $item ) {
				$name     = isset( $item['name'] ) ? (string) $item['name'] : '';
				$category = isset( $item['category'] ) ? (string) $item['category'] : '';
				$provider = isset( $item['provider'] ) ? (string) $item['provider'] : '';
				echo '<li style="padding:6px 10px;margin:4px 0;border-left:3px solid ' . esc_attr( $border ) . ';background:#f6f7f7">';
				echo '<code>' . esc_html( $name ) . '</code>';
				if ( '' !== $category ) {
					echo ' <span class="db-ui-badge" style="background:' . esc_attr( DBCM_Cookie_Database::get_category_color( $category ) ) . ';color:#fff">' . esc_html( DBCM_Cookie_Database::get_category_label( $category ) ) . '</span>';
				}
				if ( '' !== $provider ) {
					echo ' <span style="color:#646970;font-size:12px">&mdash; ' . esc_html( $provider ) . '</span>';
				}
				echo '</li>';
			}
			echo '</ul>';
		}

		/**
		 * Tabella risultati dello scanner, raggruppata per categoria.
		 *
		 * Per ogni cookie permette di:
		 *  - Sovrascrivere manualmente la categoria (dropdown → AJAX dbcm_cookie_override)
		 *  - Cancellare la riga (bottone → AJAX dbcm_cookie_delete)
		 *
		 * @param array $grouped Mappa categoria → array di stdClass.
		 * @return void
		 */
		private static function render_scanner_results_table( $grouped ) {
			$categories = DBCM_Settings::categories();
			?>
			<div class="db-ui-card">
				<div class="db-ui-card-header">
					<h3><?php esc_html_e( 'Risultati', 'db-cookie-manager' ); ?></h3>
				</div>
				<div class="db-ui-card-body">
					<p class="db-ui-field-help" style="margin-top:0">
						<?php esc_html_e( 'Puoi correggere manualmente la categoria di un cookie usando il selettore nella colonna "Categoria". Le modifiche sono immediate e persistono fino alla prossima scansione.', 'db-cookie-manager' ); ?>
					</p>

					<?php foreach ( $grouped as $category => $cookies ) :
						$label = DBCM_Cookie_Database::get_category_label( $category );
						$color = DBCM_Cookie_Database::get_category_color( $category );
						?>
						<h4 style="margin-top:18px;display:flex;align-items:center;gap:8px">
							<span class="db-ui-badge" style="background:<?php echo esc_attr( $color ); ?>;color:#fff">
								<?php echo esc_html( $label ); ?>
							</span>
							<span style="color:var(--db-text-muted);font-weight:400;font-size:13px">
								<?php
								/* translators: %d: numero cookie nella categoria */
								echo esc_html( sprintf( _n( '%d cookie', '%d cookie', count( $cookies ), 'db-cookie-manager' ), count( $cookies ) ) );
								?>
							</span>
						</h4>

						<table class="db-ui-table" style="margin-bottom:14px">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Nome', 'db-cookie-manager' ); ?></th>
									<th><?php esc_html_e( 'Fornitore', 'db-cookie-manager' ); ?></th>
									<th><?php esc_html_e( 'Durata', 'db-cookie-manager' ); ?></th>
									<th><?php esc_html_e( 'Dominio', 'db-cookie-manager' ); ?></th>
									<th style="width:200px"><?php esc_html_e( 'Categoria', 'db-cookie-manager' ); ?></th>
									<th style="width:60px"></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $cookies as $cookie ) : ?>
									<tr data-cookie-id="<?php echo (int) $cookie->id; ?>">
										<td>
											<code><?php echo esc_html( $cookie->cookie_name ); ?></code>
											<?php if ( ! empty( $cookie->description ) ) : ?>
												<div class="db-ui-field-help" style="margin-top:4px"><?php echo esc_html( $cookie->description ); ?></div>
											<?php endif; ?>
										</td>
										<td><?php echo esc_html( $cookie->provider ); ?></td>
										<td><?php echo esc_html( $cookie->cookie_duration ); ?></td>
										<td><code style="font-size:11px"><?php echo esc_html( $cookie->cookie_domain ); ?></code></td>
										<td>
											<select class="dbcm-cookie-cat" data-cookie-id="<?php echo (int) $cookie->id; ?>">
												<?php foreach ( $categories as $cat ) : ?>
													<option value="<?php echo esc_attr( $cat ); ?>" <?php selected( $cookie->category, $cat ); ?>>
														<?php echo esc_html( DBCM_Cookie_Database::get_category_label( $cat ) ); ?>
													</option>
												<?php endforeach; ?>
											</select>
										</td>
										<td>
											<button type="button"
												class="db-ui-btn db-ui-btn-icon db-ui-btn-sm db-ui-btn-danger dbcm-cookie-delete"
												data-cookie-id="<?php echo (int) $cookie->id; ?>"
												aria-label="<?php esc_attr_e( 'Elimina cookie dalla lista', 'db-cookie-manager' ); ?>"
												title="<?php esc_attr_e( 'Elimina', 'db-cookie-manager' ); ?>">✕</button>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endforeach; ?>
				</div>
			</div>
			<?php
		}

		public static function render_policy() {
			if ( ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Permessi insufficienti.', 'db-cookie-manager' ) );
			}

			$policy_html = class_exists( 'DBCM_Policy_Generator' )
				? DBCM_Policy_Generator::generate()
				: '';

			$current_id = (int) DBCM_Settings::get( 'policy_page_id', 0 );
			$current    = ( $current_id > 0 ) ? get_post( $current_id ) : null;
			$has_page   = $current && 'page' === $current->post_type && 'trash' !== $current->post_status;

			$last_scan = get_option( 'dbcm_last_scan', '' );
			$has_scan  = ! empty( $last_scan );

			self::open_wrap(
				__( 'Cookie Policy', 'db-cookie-manager' ),
				__( 'Genera la Cookie Policy basata sui cookie rilevati dallo scanner. Il testo è una bozza: rivedere prima della pubblicazione.', 'db-cookie-manager' )
			);

			// Avviso se non c'è ancora una scansione: la policy generata
			// avrà solo il fallback (dbcm_consent + invito a scansionare).
			if ( ! $has_scan ) {
				?>
				<div class="db-ui-alert db-ui-alert-warning">
					<span class="db-ui-alert-icon" aria-hidden="true">⚠️</span>
					<span>
						<?php esc_html_e( 'Non hai ancora eseguito una scansione. La policy generata sarà incompleta. Vai alla pagina Scanner per analizzare i cookie del sito.', 'db-cookie-manager' ); ?>
						&nbsp;
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '-scanner' ) ); ?>"><?php esc_html_e( 'Apri Scanner', 'db-cookie-manager' ); ?></a>
					</span>
				</div>
				<?php
			}

			// Card "Pagina collegata".
			?>
			<div class="db-ui-card">
				<div class="db-ui-card-header"><h3><?php esc_html_e( 'Pagina collegata', 'db-cookie-manager' ); ?></h3></div>
				<div class="db-ui-card-body">
					<?php if ( $has_page ) : ?>
						<p>
							<?php esc_html_e( 'Pagina attualmente collegata al banner:', 'db-cookie-manager' ); ?>
							<strong><?php echo esc_html( $current->post_title ); ?></strong>
						</p>
						<p style="display:flex;flex-wrap:wrap;gap:8px">
							<a class="db-ui-btn" href="<?php echo esc_url( get_permalink( $current->ID ) ); ?>" target="_blank" rel="noopener">
								<?php esc_html_e( 'Visualizza pagina pubblica', 'db-cookie-manager' ); ?>
								<span class="screen-reader-text"><?php esc_html_e( '(si apre in una nuova finestra)', 'db-cookie-manager' ); ?></span>
							</a>
							<a class="db-ui-btn" href="<?php echo esc_url( get_edit_post_link( $current->ID ) ); ?>">
								<?php esc_html_e( 'Modifica nell\'editor WordPress', 'db-cookie-manager' ); ?>
							</a>
						</p>
					<?php else : ?>
						<div class="db-ui-empty">
							<span class="db-ui-empty-icon" aria-hidden="true">📄</span>
							<span class="db-ui-empty-text">
								<?php esc_html_e( 'Nessuna pagina collegata. Usa il pulsante qui sotto per crearne una automaticamente, oppure seleziona una pagina esistente nella sezione "Banner & aspetto".', 'db-cookie-manager' ); ?>
							</span>
						</div>
					<?php endif; ?>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:14px">
						<input type="hidden" name="action" value="dbcm_create_policy_page">
						<?php wp_nonce_field( 'dbcm_create_policy_page' ); ?>
						<button type="submit" class="db-ui-btn db-ui-btn-primary">
							<?php
							echo esc_html(
								$has_page
									? __( 'Aggiorna pagina con il testo generato', 'db-cookie-manager' )
									: __( 'Crea la pagina automaticamente', 'db-cookie-manager' )
							);
							?>
						</button>
						<span class="db-ui-field-help" style="display:block;margin-top:6px">
							<?php
							echo esc_html(
								$has_page
									? __( 'Sovrascrive il contenuto della pagina esistente con la nuova versione generata. Il titolo, lo slug e gli altri metadati restano invariati.', 'db-cookie-manager' )
									: __( 'Crea una nuova pagina pubblicata "Cookie Policy" e la collega automaticamente al banner.', 'db-cookie-manager' )
							);
							?>
						</span>
					</form>
				</div>
			</div>
			<?php

			// Card "Anteprima".
			?>
			<div class="db-ui-card">
				<div class="db-ui-card-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px">
					<h3 style="margin:0"><?php esc_html_e( 'Anteprima del testo generato', 'db-cookie-manager' ); ?></h3>
					<button type="button" class="db-ui-btn db-ui-btn-sm" data-dbcm-copy="#dbcm-policy-raw">
						<?php esc_html_e( 'Copia HTML', 'db-cookie-manager' ); ?>
					</button>
				</div>
				<div class="db-ui-card-body">
					<p class="db-ui-field-help" style="margin-top:0">
						<?php esc_html_e( 'L\'anteprima viene renderizzata in un iframe isolato per non interferire con lo stile dell\'admin. Il testo HTML completo è disponibile nel campo qui sotto, copiabile con un click.', 'db-cookie-manager' ); ?>
					</p>

					<iframe
						id="dbcm-policy-preview"
						srcdoc="<?php echo esc_attr( self::wrap_policy_for_iframe( $policy_html ) ); ?>"
						style="width:100%;min-height:520px;border:1px solid var(--db-border);border-radius:var(--db-radius);background:#fff"
						sandbox="allow-same-origin"
						title="<?php esc_attr_e( 'Anteprima Cookie Policy', 'db-cookie-manager' ); ?>"></iframe>

					<details style="margin-top:14px">
						<summary style="cursor:pointer;font-weight:600"><?php esc_html_e( 'Codice HTML completo', 'db-cookie-manager' ); ?></summary>
						<textarea
							id="dbcm-policy-raw"
							readonly
							rows="10"
							style="width:100%;margin-top:8px;font-family:ui-monospace,Consolas,monospace;font-size:11px"><?php echo esc_textarea( $policy_html ); ?></textarea>
					</details>
				</div>
			</div>
			<?php

			self::close_wrap();
		}

		/**
		 * Wrappa l'HTML della policy in un documento HTML completo per
		 * l'anteprima in iframe sandboxed. Lo stile è leggero, leggibile,
		 * non eredita CSS dall'admin (l'iframe è un contesto isolato).
		 *
		 * @param string $body
		 * @return string
		 */
		private static function wrap_policy_for_iframe( $body ) {
			$css = '
				body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; line-height: 1.6; color: #1d2327; padding: 20px; max-width: 800px; margin: 0 auto; }
				h2 { margin-top: 0; }
				h3, h4 { margin-top: 1.5em; }
				table { width: 100%; border-collapse: collapse; margin: 1em 0; }
				th, td { padding: 8px; border: 1px solid #ddd; text-align: left; vertical-align: top; }
				th { background: #f5f5f5; }
				code { background: #f0f0f1; padding: 2px 6px; border-radius: 3px; font-size: 0.9em; }
				a { color: #2271b1; }
				ul { padding-left: 1.5em; }
			';
			return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>' . $css . '</style></head><body>' . $body . '</body></html>';
		}

		public static function render_log() {
			if ( ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Permessi insufficienti.', 'db-cookie-manager' ) );
			}

			$s = DBCM_Settings::all();

			// Filtri da querystring (GET).
			$filters = array(
				'type'      => isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '',
				'date_from' => isset( $_GET['date_from'] ) ? sanitize_text_field( wp_unslash( $_GET['date_from'] ) ) : '',
				'date_to'   => isset( $_GET['date_to'] ) ? sanitize_text_field( wp_unslash( $_GET['date_to'] ) ) : '',
			);

			$paged    = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
			$per_page = 25;
			$total    = DBCM_Consent_Log::count( $filters );
			$rows     = DBCM_Consent_Log::get_results( array_merge( $filters, array(
				'page'     => $paged,
				'per_page' => $per_page,
				'order'    => 'DESC',
			) ) );

			self::open_wrap(
				__( 'Registro consensi', 'db-cookie-manager' ),
				__( 'Storico dei consensi raccolti dal banner. IP hashato (irreversibile), user-agent aggregato per default.', 'db-cookie-manager' )
			);

			self::render_log_status_card( $total );
			self::render_log_settings_form( $s );
			self::render_log_filters_and_export( $filters );
			self::render_log_table( $rows, $total, $paged, $per_page, $filters );

			self::close_wrap();
		}

		/**
		 * Card Stato: total + breakdown per type negli ultimi 30 giorni.
		 *
		 * @param int $total
		 * @return void
		 */
		private static function render_log_status_card( $total ) {
			// Breakdown per type negli ultimi 30 giorni.
			$since   = gmdate( 'Y-m-d', time() - ( 30 * DAY_IN_SECONDS ) );
			$accept  = DBCM_Consent_Log::count( array( 'type' => 'accept_all', 'date_from' => $since ) );
			$reject  = DBCM_Consent_Log::count( array( 'type' => 'reject_all', 'date_from' => $since ) );
			$custom  = DBCM_Consent_Log::count( array( 'type' => 'custom',     'date_from' => $since ) );
			$last30  = $accept + $reject + $custom;
			?>
			<div class="db-ui-card">
				<div class="db-ui-card-header"><h3><?php esc_html_e( 'Stato', 'db-cookie-manager' ); ?></h3></div>
				<div class="db-ui-card-body">
					<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:12px">

						<div class="db-ui-stat">
							<div class="db-ui-stat-icon db-ui-stat-icon-primary" aria-hidden="true">📊</div>
							<div>
								<div class="db-ui-stat-value"><?php echo (int) $total; ?></div>
								<div class="db-ui-stat-label"><?php esc_html_e( 'Consensi totali', 'db-cookie-manager' ); ?></div>
							</div>
						</div>

						<div class="db-ui-stat">
							<div class="db-ui-stat-icon db-ui-stat-icon-success" aria-hidden="true">✓</div>
							<div>
								<div class="db-ui-stat-value"><?php echo (int) $accept; ?></div>
								<div class="db-ui-stat-label"><?php esc_html_e( 'Accetta tutto (30gg)', 'db-cookie-manager' ); ?></div>
							</div>
						</div>

						<div class="db-ui-stat">
							<div class="db-ui-stat-icon db-ui-stat-icon-danger" aria-hidden="true">✕</div>
							<div>
								<div class="db-ui-stat-value"><?php echo (int) $reject; ?></div>
								<div class="db-ui-stat-label"><?php esc_html_e( 'Rifiuta tutto (30gg)', 'db-cookie-manager' ); ?></div>
							</div>
						</div>

						<div class="db-ui-stat">
							<div class="db-ui-stat-icon db-ui-stat-icon-warning" aria-hidden="true">⚙</div>
							<div>
								<div class="db-ui-stat-value"><?php echo (int) $custom; ?></div>
								<div class="db-ui-stat-label"><?php esc_html_e( 'Personalizzato (30gg)', 'db-cookie-manager' ); ?></div>
							</div>
						</div>
					</div>

					<?php if ( $last30 > 0 ) : ?>
						<?php
						$accept_pct = round( ( $accept / $last30 ) * 100 );
						$reject_pct = round( ( $reject / $last30 ) * 100 );
						$custom_pct = max( 0, 100 - $accept_pct - $reject_pct );
						?>
						<p style="margin:14px 0 4px;font-size:13px;color:var(--db-text-muted)">
							<?php esc_html_e( 'Distribuzione ultimi 30 giorni:', 'db-cookie-manager' ); ?>
						</p>
						<div style="display:flex;height:10px;border-radius:4px;overflow:hidden;background:var(--db-bg-subtle)">
							<div style="background:#1d6e3f;width:<?php echo (int) $accept_pct; ?>%" title="<?php echo esc_attr( $accept_pct . '% accept_all' ); ?>"></div>
							<div style="background:#d63638;width:<?php echo (int) $reject_pct; ?>%" title="<?php echo esc_attr( $reject_pct . '% reject_all' ); ?>"></div>
							<div style="background:#7a5d00;width:<?php echo (int) $custom_pct; ?>%" title="<?php echo esc_attr( $custom_pct . '% custom' ); ?>"></div>
						</div>
					<?php endif; ?>
				</div>
			</div>
			<?php
		}

		/**
		 * Form impostazioni log: enable/retention/UA mode.
		 *
		 * @param array $s
		 * @return void
		 */
		private static function render_log_settings_form( $s ) {
			self::form_open( 'log_settings' );
			?>
			<div class="db-ui-card">
				<div class="db-ui-card-header"><h3><?php esc_html_e( 'Impostazioni del registro', 'db-cookie-manager' ); ?></h3></div>
				<div class="db-ui-card-body">
					<?php
					self::field_checkbox(
						'consent_log_enabled',
						$s['consent_log_enabled'],
						__( 'Registra i consensi nel database', 'db-cookie-manager' ),
						__( 'Quando attivo, ogni consenso viene salvato come prova ai sensi dell\'art. 7(1) GDPR. Disabilitare solo se hai un sistema esterno di logging.', 'db-cookie-manager' )
					);

					self::field_number(
						'consent_log_retention',
						(int) $s['consent_log_retention'],
						__( 'Conserva per (giorni)', 'db-cookie-manager' ),
						__( 'I consensi più vecchi vengono cancellati automaticamente dal cron giornaliero. Imposta a 0 per disattivare la cancellazione automatica. Default: 365 giorni.', 'db-cookie-manager' ),
						0,
						3650
					);

					self::field_select(
						'consent_log_user_agent',
						$s['consent_log_user_agent'],
						__( 'User-agent salvato', 'db-cookie-manager' ),
						array(
							'none'      => __( 'Nessuno (massima privacy)', 'db-cookie-manager' ),
							'aggregate' => __( 'Aggregato — solo nome browser (consigliato)', 'db-cookie-manager' ),
							'full'      => __( 'Completo — primi 64 caratteri dello UA', 'db-cookie-manager' ),
						),
						__( 'L\'aggregato salva solo "Chrome", "Firefox", "Safari", ecc. Sufficiente per statistiche; minimizza il rischio di fingerprinting.', 'db-cookie-manager' )
					);
					?>
				</div>
			</div>
			<?php
			self::form_close( __( 'Salva impostazioni log', 'db-cookie-manager' ) );
		}

		/**
		 * Card "Filtri ed export": form GET con select type + date range,
		 * + bottoni export CSV/JSON che usano DBCM_Consent_Log::export_url().
		 *
		 * @param array $filters
		 * @return void
		 */
		private static function render_log_filters_and_export( $filters ) {
			$base_url = admin_url( 'admin.php?page=' . self::MENU_SLUG . '-log' );
			$csv_url  = DBCM_Consent_Log::export_url( 'csv',  $filters );
			$json_url = DBCM_Consent_Log::export_url( 'json', $filters );
			?>
			<div class="db-ui-card">
				<div class="db-ui-card-header"><h3><?php esc_html_e( 'Filtri ed export', 'db-cookie-manager' ); ?></h3></div>
				<div class="db-ui-card-body">

					<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end">
						<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG . '-log' ); ?>">

						<div class="db-ui-field" style="margin:0">
							<label for="dbcm-filter-type"><?php esc_html_e( 'Tipo', 'db-cookie-manager' ); ?></label>
							<select id="dbcm-filter-type" name="type">
								<option value=""><?php esc_html_e( '— Tutti —', 'db-cookie-manager' ); ?></option>
								<option value="accept_all" <?php selected( $filters['type'], 'accept_all' ); ?>><?php esc_html_e( 'Accetta tutto', 'db-cookie-manager' ); ?></option>
								<option value="reject_all" <?php selected( $filters['type'], 'reject_all' ); ?>><?php esc_html_e( 'Rifiuta tutto', 'db-cookie-manager' ); ?></option>
								<option value="custom"     <?php selected( $filters['type'], 'custom' ); ?>><?php esc_html_e( 'Personalizzato', 'db-cookie-manager' ); ?></option>
							</select>
						</div>

						<div class="db-ui-field" style="margin:0">
							<label for="dbcm-filter-from"><?php esc_html_e( 'Dal', 'db-cookie-manager' ); ?></label>
							<input type="date" id="dbcm-filter-from" name="date_from" value="<?php echo esc_attr( $filters['date_from'] ); ?>">
						</div>

						<div class="db-ui-field" style="margin:0">
							<label for="dbcm-filter-to"><?php esc_html_e( 'Al', 'db-cookie-manager' ); ?></label>
							<input type="date" id="dbcm-filter-to" name="date_to" value="<?php echo esc_attr( $filters['date_to'] ); ?>">
						</div>

						<div style="display:flex;gap:8px">
							<button type="submit" class="db-ui-btn db-ui-btn-primary"><?php esc_html_e( 'Applica filtri', 'db-cookie-manager' ); ?></button>
							<?php if ( $filters['type'] || $filters['date_from'] || $filters['date_to'] ) : ?>
								<a class="db-ui-btn" href="<?php echo esc_url( $base_url ); ?>"><?php esc_html_e( 'Pulisci', 'db-cookie-manager' ); ?></a>
							<?php endif; ?>
						</div>
					</form>

					<hr style="margin:16px 0;border:none;border-top:1px solid var(--db-border)">

					<p style="margin:0 0 8px;font-size:13px;color:var(--db-text-muted)">
						<?php esc_html_e( 'Esporta il log filtrato. CSV per Excel/Numbers (BOM UTF-8), JSON con envelope (exported_at, version, schema, count).', 'db-cookie-manager' ); ?>
					</p>
					<p style="display:flex;gap:8px;margin:0">
						<a class="db-ui-btn" href="<?php echo esc_url( $csv_url ); ?>"><?php esc_html_e( 'Scarica CSV', 'db-cookie-manager' ); ?></a>
						<a class="db-ui-btn" href="<?php echo esc_url( $json_url ); ?>"><?php esc_html_e( 'Scarica JSON', 'db-cookie-manager' ); ?></a>
					</p>
				</div>
			</div>
			<?php
		}

		/**
		 * Tabella paginata dei consensi.
		 *
		 * @param array $rows
		 * @param int   $total
		 * @param int   $paged
		 * @param int   $per_page
		 * @param array $filters
		 * @return void
		 */
		private static function render_log_table( $rows, $total, $paged, $per_page, $filters ) {
			$pages = max( 1, (int) ceil( $total / $per_page ) );
			?>
			<div class="db-ui-card">
				<div class="db-ui-card-header"><h3><?php esc_html_e( 'Consensi raccolti', 'db-cookie-manager' ); ?></h3></div>
				<div class="db-ui-card-body">

					<?php if ( empty( $rows ) ) : ?>
						<div class="db-ui-empty">
							<span class="db-ui-empty-icon" aria-hidden="true">📭</span>
							<span class="db-ui-empty-text">
								<?php
								if ( $filters['type'] || $filters['date_from'] || $filters['date_to'] ) {
									esc_html_e( 'Nessun consenso trovato per i filtri applicati.', 'db-cookie-manager' );
								} else {
									esc_html_e( 'Nessun consenso registrato. Quando un visitatore farà la sua scelta nel banner, comparirà qui.', 'db-cookie-manager' );
								}
								?>
							</span>
						</div>
					<?php else : ?>

						<p style="margin:0 0 10px;font-size:13px;color:var(--db-text-muted)">
							<?php
							/* translators: 1: numero di righe in pagina, 2: totale, 3: pagina, 4: totale pagine */
							printf(
								esc_html__( 'Mostrando %1$d di %2$d consensi · pagina %3$d di %4$d', 'db-cookie-manager' ),
								(int) count( $rows ),
								(int) $total,
								(int) $paged,
								(int) $pages
							);
							?>
						</p>

						<table class="db-ui-table">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Data', 'db-cookie-manager' ); ?></th>
									<th><?php esc_html_e( 'Tipo', 'db-cookie-manager' ); ?></th>
									<th><?php esc_html_e( 'Consenso', 'db-cookie-manager' ); ?></th>
									<th><?php esc_html_e( 'Browser', 'db-cookie-manager' ); ?></th>
									<th><?php esc_html_e( 'IP (hash)', 'db-cookie-manager' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $rows as $row ) : ?>
									<tr>
										<td style="white-space:nowrap"><?php echo esc_html( $row->consent_date ); ?></td>
										<td><?php echo self::format_consent_type_badge( $row->consent_type ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
										<td><?php echo self::format_consent_data( $row->consent_data ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
										<td><?php echo esc_html( $row->ua_summary ?: '—' ); ?></td>
										<td><code style="font-size:11px"><?php echo esc_html( $row->ip_hash ? substr( $row->ip_hash, 0, 12 ) . '…' : '—' ); ?></code></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>

						<?php if ( $pages > 1 ) : ?>
							<div style="display:flex;justify-content:center;gap:6px;margin-top:14px">
								<?php
								$base_args = array_merge( $filters, array( 'page' => self::MENU_SLUG . '-log' ) );

								// Prev.
								if ( $paged > 1 ) {
									$prev_url = add_query_arg(
										array_merge( $base_args, array( 'paged' => $paged - 1 ) ),
										admin_url( 'admin.php' )
									);
									echo '<a class="db-ui-btn db-ui-btn-sm" href="' . esc_url( $prev_url ) . '">‹ ' . esc_html__( 'Precedente', 'db-cookie-manager' ) . '</a>';
								}

								// Numeri (limitati a un range intorno alla corrente).
								$start = max( 1, $paged - 2 );
								$end   = min( $pages, $paged + 2 );
								for ( $i = $start; $i <= $end; $i++ ) {
									$cls = ( $i === $paged ) ? 'db-ui-btn db-ui-btn-sm db-ui-btn-primary' : 'db-ui-btn db-ui-btn-sm';
									$url = add_query_arg(
										array_merge( $base_args, array( 'paged' => $i ) ),
										admin_url( 'admin.php' )
									);
									echo '<a class="' . esc_attr( $cls ) . '" href="' . esc_url( $url ) . '">' . (int) $i . '</a>';
								}

								// Next.
								if ( $paged < $pages ) {
									$next_url = add_query_arg(
										array_merge( $base_args, array( 'paged' => $paged + 1 ) ),
										admin_url( 'admin.php' )
									);
									echo '<a class="db-ui-btn db-ui-btn-sm" href="' . esc_url( $next_url ) . '">' . esc_html__( 'Successiva', 'db-cookie-manager' ) . ' ›</a>';
								}
								?>
							</div>
						<?php endif; ?>

					<?php endif; ?>

				</div>
			</div>
			<?php
		}

		/**
		 * Restituisce il badge HTML per un consent_type.
		 *
		 * @param string $type
		 * @return string HTML escaped.
		 */
		private static function format_consent_type_badge( $type ) {
			$map = array(
				'accept_all' => array( __( 'Accetta tutto', 'db-cookie-manager' ),  '#1d6e3f' ),
				'reject_all' => array( __( 'Rifiuta tutto', 'db-cookie-manager' ),  '#d63638' ),
				'custom'     => array( __( 'Personalizzato', 'db-cookie-manager' ), '#7a5d00' ),
			);
			$entry = $map[ $type ] ?? array( $type, '#646970' );
			return '<span class="db-ui-badge" style="background:'
				. esc_attr( $entry[1] ) . ';color:#fff">'
				. esc_html( $entry[0] ) . '</span>';
		}

		/**
		 * Decodifica il JSON di consent_data e restituisce una rappresentazione
		 * compatta delle categorie concesse.
		 *
		 * Esempio output: "functional ✓ · statistics ✓ · marketing ✗"
		 *
		 * @param string $json
		 * @return string HTML escaped.
		 */
		private static function format_consent_data( $json ) {
			$data = json_decode( (string) $json, true );
			if ( ! is_array( $data ) ) {
				return '<code style="font-size:11px">' . esc_html( substr( (string) $json, 0, 50 ) ) . '</code>';
			}

			$parts = array();
			foreach ( DBCM_Settings::categories() as $cat ) {
				$on    = ! empty( $data[ $cat ] );
				$icon  = $on ? '✓' : '✗';
				$color = $on ? '#1d6e3f' : '#646970';
				$short = self::short_category_label( $cat );
				$parts[] = '<span style="color:' . esc_attr( $color ) . '">' . esc_html( $short ) . ' ' . $icon . '</span>';
			}
			return '<span style="font-size:12px">' . implode( ' · ', $parts ) . '</span>';
		}

		/**
		 * Etichetta breve di una categoria, per la cella consenso compatta.
		 *
		 * @param string $cat
		 * @return string
		 */
		private static function short_category_label( $cat ) {
			$map = array(
				'functional'           => 'F',
				'preferences'          => 'P',
				'statistics'           => 'S',
				'statistics-anonymous' => 'Sa',
				'marketing'            => 'M',
			);
			return $map[ $cat ] ?? $cat;
		}

		public static function render_advanced() {
			if ( ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Permessi insufficienti.', 'db-cookie-manager' ) );
			}

			$s = DBCM_Settings::all();

			self::open_wrap(
				__( 'Impostazioni avanzate', 'db-cookie-manager' ),
				__( 'Segnali browser, geo-targeting e API JavaScript per gli sviluppatori.', 'db-cookie-manager' )
			);

			self::render_advanced_form( $s );
			self::render_advanced_api_card();

			self::close_wrap();
		}

		/**
		 * Form impostazioni avanzate: respect_dnt, respect_gpc, geo_targeting.
		 *
		 * @param array $s
		 * @return void
		 */
		private static function render_advanced_form( $s ) {
			self::form_open( 'advanced' );
			?>

			<div class="db-ui-card">
				<div class="db-ui-card-header"><h3><?php esc_html_e( 'Segnali del browser', 'db-cookie-manager' ); ?></h3></div>
				<div class="db-ui-card-body">
					<p style="margin:0 0 14px;font-size:13px;color:var(--db-text-muted)">
						<?php esc_html_e( 'Quando il browser invia un segnale di rifiuto del tracking, il banner può rispettarlo automaticamente senza chiedere all\'utente. L\'implementazione completa di questi segnali sarà attivata nello step 7.', 'db-cookie-manager' ); ?>
					</p>

					<?php
					self::field_checkbox(
						'respect_dnt',
						$s['respect_dnt'],
						__( 'Rispetta Do Not Track (DNT)', 'db-cookie-manager' ),
						__( 'Se il browser invia l\'header DNT:1, considera l\'utente come "rifiuta tutto" senza mostrare il banner. DNT è oggi poco usato ma alcuni browser e configurazioni privacy lo abilitano.', 'db-cookie-manager' )
					);

					self::field_checkbox(
						'respect_gpc',
						$s['respect_gpc'],
						__( 'Rispetta Global Privacy Control (GPC)', 'db-cookie-manager' ),
						__( 'GPC è il successore moderno di DNT, supportato da Firefox, Brave e DuckDuckGo. Quando attivo, sec-gpc:1 viene trattato come opt-out automatico delle categorie opzionali.', 'db-cookie-manager' )
					);
					?>
				</div>
			</div>

			<div class="db-ui-card">
				<div class="db-ui-card-header"><h3><?php esc_html_e( 'Geo-targeting', 'db-cookie-manager' ); ?></h3></div>
				<div class="db-ui-card-body">
					<?php
					self::field_checkbox(
						'geo_targeting',
						$s['geo_targeting'],
						__( 'Mostra il banner solo ai visitatori dell\'Unione Europea', 'db-cookie-manager' ),
						__( 'Quando attivo, il banner viene mostrato solo se il visitatore proviene da un paese UE/EEA o UK (rilevato tramite header Cloudflare CF-IPCountry o accept-language). Sconsigliato se il sito è italiano: meglio mostrare il banner a tutti per evitare incoerenze di esperienza.', 'db-cookie-manager' )
					);
					?>

					<div class="db-ui-alert db-ui-alert-info" style="margin-top:14px">
						<span class="db-ui-alert-icon" aria-hidden="true">ℹ️</span>
						<span><?php esc_html_e(
							'Il geo-targeting riduce il banner per visitatori extra-UE ma non li esclude del tutto: il blocco preventivo degli script tracking continua a funzionare per coerenza tecnica. Per disattivare completamente il banner per alcune regioni, considera di disabilitarlo via codice tramite il filtro dbcm_should_render_banner.',
							'db-cookie-manager'
						); ?></span>
					</div>
				</div>
			</div>

			<div class="db-ui-card">
				<div class="db-ui-card-header"><h3><?php esc_html_e( 'Google Consent Mode v2', 'db-cookie-manager' ); ?></h3></div>
				<div class="db-ui-card-body">
					<p style="margin:0 0 14px;font-size:13px;color:var(--db-text-muted)">
						<?php esc_html_e( 'Google Consent Mode v2 comunica lo stato del consenso ai tag Google (GA4, Google Ads). Attivalo solo se usi tag Google: il plugin inietterà il comando di default con tutti i segnali negati (privacy by default) e invierà l\'aggiornamento quando l\'utente concede il consenso.', 'db-cookie-manager' ); ?>
					</p>
					<?php
					self::field_checkbox(
						'gcm_enabled',
						$s['gcm_enabled'],
						__( 'Attiva Google Consent Mode v2', 'db-cookie-manager' ),
						__( 'Mappa predefinita: Statistiche → analytics_storage; Marketing → ad_storage, ad_user_data, ad_personalization. Personalizzabile dagli sviluppatori via filtro dbcm_gcm_mapping.', 'db-cookie-manager' )
					);
					?>
					<div class="db-ui-alert db-ui-alert-info" style="margin-top:14px">
						<span class="db-ui-alert-icon" aria-hidden="true">ℹ️</span>
						<span><?php esc_html_e( 'Se già gestisci Consent Mode tramite Google Tag Manager, lascia questa opzione disattivata per evitare doppie inizializzazioni.', 'db-cookie-manager' ); ?></span>
					</div>
				</div>
			</div>

			<div class="db-ui-card">
				<div class="db-ui-card-header"><h3><?php esc_html_e( 'Google Fonts', 'db-cookie-manager' ); ?></h3></div>
				<div class="db-ui-card-body">
					<p style="margin:0 0 14px;font-size:13px;color:var(--db-text-muted)">
						<?php esc_html_e( 'Quando una pagina carica un font da fonts.googleapis.com, il browser dell\'utente contatta i server di Google trasmettendo il suo indirizzo IP — prima ancora del banner cookie. Attivando questa opzione, i riferimenti remoti a Google Fonts vengono rimossi dall\'HTML: il sito ripiega sui font di sistema e nessun dato viene inviato a Google.', 'db-cookie-manager' ); ?>
					</p>
					<?php
					self::field_checkbox(
						'localize_google_fonts',
						$s['localize_google_fonts'],
						__( 'Rimuovi i Google Fonts remoti', 'db-cookie-manager' ),
						__( 'Rimuove i tag <link> verso fonts.googleapis.com e fonts.gstatic.com e gli @import correlati. Se il tuo tema dipende molto da un font Google specifico, valuta prima l\'aspetto del sito: senza il font remoto verrà usato il fallback CSS (di solito font di sistema).', 'db-cookie-manager' )
					);
					?>
					<div class="db-ui-alert db-ui-alert-info" style="margin-top:14px">
						<span class="db-ui-alert-icon" aria-hidden="true">ℹ️</span>
						<span><?php esc_html_e( 'Questa opzione non fa self-hosting dei font (download e riscrittura locale): li rimuove soltanto. Per mantenere l\'aspetto identico self-hostando i font, usa un plugin dedicato o carica i font localmente nel tema.', 'db-cookie-manager' ); ?></span>
					</div>
				</div>
			</div>

			<?php
			self::form_close( __( 'Salva impostazioni avanzate', 'db-cookie-manager' ) );
		}

		/**
		 * Card API: documentazione di window.DBCM con esempi copiabili.
		 *
		 * @return void
		 */
		private static function render_advanced_api_card() {
			$examples = array(
				'has-consent' => array(
					'title' => __( 'Verificare il consenso a una categoria', 'db-cookie-manager' ),
					'desc'  => __( 'Carica uno script di analytics solo se l\'utente ha concesso "statistics".', 'db-cookie-manager' ),
					'code'  => <<<JS
if (window.DBCM && window.DBCM.hasConsent('statistics')) {
    var s = document.createElement('script');
    s.src = 'https://www.googletagmanager.com/gtag/js?id=G-XXXX';
    document.head.appendChild(s);
}
JS,
				),
				'on-consent' => array(
					'title' => __( 'Reagire al cambio di consenso', 'db-cookie-manager' ),
					'desc'  => __( 'Esegue il callback ogni volta che l\'utente cambia le proprie preferenze.', 'db-cookie-manager' ),
					'code'  => <<<JS
window.DBCM.onConsent(function(consent, type) {
    console.log('Tipo:', type); // 'accept_all' | 'reject_all' | 'custom'
    console.log('Marketing:', consent.marketing);
    if (consent.marketing) {
        // attiva pixel pubblicitari
    }
});
JS,
				),
				'open-prefs' => array(
					'title' => __( 'Aprire il pannello preferenze da un link', 'db-cookie-manager' ),
					'desc'  => __( 'Aggancia un click handler a un link "Modifica preferenze" nel footer.', 'db-cookie-manager' ),
					'code'  => <<<JS
document.querySelectorAll('.modifica-cookie').forEach(function(el) {
    el.addEventListener('click', function(e) {
        e.preventDefault();
        window.DBCM.openPreferences();
    });
});
JS,
				),
				'event' => array(
					'title' => __( 'Ascoltare l\'evento dbcm:consent', 'db-cookie-manager' ),
					'desc'  => __( 'Alternativa a onConsent: usa il sistema standard di eventi DOM.', 'db-cookie-manager' ),
					'code'  => <<<JS
document.addEventListener('dbcm:consent', function(ev) {
    var consent = ev.detail.consent;
    var type    = ev.detail.type;
    // ...
});
JS,
				),
			);
			?>
			<div class="db-ui-card">
				<div class="db-ui-card-header"><h3><?php esc_html_e( 'API JavaScript pubblica', 'db-cookie-manager' ); ?></h3></div>
				<div class="db-ui-card-body">
					<p><?php esc_html_e( 'Il banner espone un oggetto', 'db-cookie-manager' ); ?> <code>window.DBCM</code> <?php esc_html_e( 'con cui puoi controllare il consenso da JavaScript.', 'db-cookie-manager' ); ?></p>

					<table class="db-ui-table" style="margin:10px 0 18px">
						<thead>
							<tr>
								<th style="width:30%"><?php esc_html_e( 'Metodo', 'db-cookie-manager' ); ?></th>
								<th><?php esc_html_e( 'Descrizione', 'db-cookie-manager' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<tr><td><code>DBCM.hasConsent(cat)</code></td><td><?php esc_html_e( 'true se l\'utente ha concesso la categoria. cat = una delle 5 standard.', 'db-cookie-manager' ); ?></td></tr>
							<tr><td><code>DBCM.getConsent()</code></td><td><?php esc_html_e( 'Mappa { categoria: bool } completa, o null se nessun consenso ancora dato.', 'db-cookie-manager' ); ?></td></tr>
							<tr><td><code>DBCM.setConsent(cat, on)</code></td><td><?php esc_html_e( 'Cambia programmaticamente il consenso a una categoria.', 'db-cookie-manager' ); ?></td></tr>
							<tr><td><code>DBCM.openPreferences()</code></td><td><?php esc_html_e( 'Apre il modal preferenze.', 'db-cookie-manager' ); ?></td></tr>
							<tr><td><code>DBCM.onConsent(fn)</code></td><td><?php esc_html_e( 'Callback chiamato a ogni cambio. fn(consent, type).', 'db-cookie-manager' ); ?></td></tr>
							<tr><td><code>DBCM.categories</code></td><td><?php esc_html_e( 'Array delle 5 categorie standard.', 'db-cookie-manager' ); ?></td></tr>
						</tbody>
					</table>

					<h4 style="margin:18px 0 8px"><?php esc_html_e( 'Esempi', 'db-cookie-manager' ); ?></h4>

					<?php foreach ( $examples as $key => $ex ) : ?>
						<details style="margin-bottom:10px;border:1px solid var(--db-border);border-radius:var(--db-radius);padding:10px 12px">
							<summary style="cursor:pointer;font-weight:600"><?php echo esc_html( $ex['title'] ); ?></summary>
							<p style="font-size:13px;color:var(--db-text-muted);margin:8px 0"><?php echo esc_html( $ex['desc'] ); ?></p>
							<div style="display:flex;justify-content:flex-end;margin-bottom:6px">
								<button type="button" class="db-ui-btn db-ui-btn-sm" data-dbcm-copy="#dbcm-api-<?php echo esc_attr( $key ); ?>"><?php esc_html_e( 'Copia', 'db-cookie-manager' ); ?></button>
							</div>
							<pre style="background:var(--db-bg-subtle);padding:12px;border-radius:6px;margin:0;overflow:auto;font-size:12px"><code id="dbcm-api-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $ex['code'] ); ?></code></pre>
						</details>
					<?php endforeach; ?>

					<p style="margin-top:14px;font-size:13px;color:var(--db-text-muted)">
						<?php esc_html_e( 'Il plugin espone anche l\'integrazione con WP Consent API: chiamate a wp_has_consent() lato PHP rispondono in base al consenso del visitatore corrente.', 'db-cookie-manager' ); ?>
					</p>
				</div>
			</div>
			<?php
		}

		/* =====================================================================
		 * FIRME PERSONALIZZATE (aggiunta manuale + import/export)
		 * ==================================================================== */

		/**
		 * Pagina "Firme personalizzate": tabella firme esistenti, form di
		 * aggiunta/modifica, sezione import/export JSON.
		 *
		 * Le firme custom finiscono nel path del blocker (pattern applicati
		 * all'HTML di ogni pagina) e nella lista di cancellazione reattiva:
		 * per questo ogni scrittura passa da DBCM_Signatures::save_custom(),
		 * che sanifica riga per riga e degrada le regex malformate a substring.
		 *
		 * @return void
		 */
		public static function render_signatures() {
			if ( ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Permessi insufficienti.', 'db-cookie-manager' ) );
			}

			$rows = DBCM_Signatures::get_custom_raw();
			$edit = self::signatures_row_being_edited( $rows );

			self::open_wrap(
				__( 'Firme personalizzate', 'db-cookie-manager' ),
				__( 'Aggiungi manualmente servizi e cookie non coperti dal database interno. Utile per script proprietari, CDN aziendali o integrazioni di nicchia.', 'db-cookie-manager' )
			);

			self::render_signatures_table( $rows );
			self::render_signatures_form( $edit );
			self::render_signatures_import_export();

			self::close_wrap();
		}

		/**
		 * Se ?dbcm_edit=<index> è presente e valido, restituisce quella riga
		 * (con la sua chiave) per pre-popolare il form; altrimenti null.
		 *
		 * @param array $rows
		 * @return array|null  { index:int, row:array } oppure null.
		 */
		private static function signatures_row_being_edited( $rows ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lettura idempotente, nessuna azione.
			$idx = isset( $_GET['dbcm_edit'] ) ? (int) $_GET['dbcm_edit'] : -1;
			if ( $idx >= 0 && isset( $rows[ $idx ] ) && is_array( $rows[ $idx ] ) ) {
				return array(
					'index' => $idx,
					'row'   => $rows[ $idx ],
				);
			}
			return null;
		}

		/**
		 * Tabella delle firme personalizzate esistenti con azioni modifica/elimina.
		 *
		 * @param array $rows
		 * @return void
		 */
		private static function render_signatures_table( $rows ) {
			echo '<div class="db-ui-card">';
			echo '<div class="db-ui-card-header"><h3>' . esc_html__( 'Firme esistenti', 'db-cookie-manager' ) . '</h3></div>';
			echo '<div class="db-ui-card-body">';

			if ( empty( $rows ) ) {
				echo '<p style="margin:0;color:var(--db-text-muted)">' . esc_html__( 'Nessuna firma personalizzata. Aggiungine una col form qui sotto.', 'db-cookie-manager' ) . '</p>';
				echo '</div></div>';
				return;
			}

			echo '<table class="db-ui-table" style="width:100%">';
			echo '<thead><tr>';
			echo '<th>' . esc_html__( 'Servizio', 'db-cookie-manager' ) . '</th>';
			echo '<th>' . esc_html__( 'Categoria', 'db-cookie-manager' ) . '</th>';
			echo '<th>' . esc_html__( 'Cookie', 'db-cookie-manager' ) . '</th>';
			echo '<th>' . esc_html__( 'Blocco', 'db-cookie-manager' ) . '</th>';
			echo '<th>' . esc_html__( 'Pulizia reattiva', 'db-cookie-manager' ) . '</th>';
			echo '<th style="text-align:right">' . esc_html__( 'Azioni', 'db-cookie-manager' ) . '</th>';
			echo '</tr></thead><tbody>';

			foreach ( $rows as $i => $row ) {
				$service  = isset( $row['service'] ) ? (string) $row['service'] : '';
				$category = isset( $row['category'] ) ? (string) $row['category'] : 'marketing';
				$cookies  = array();
				if ( ! empty( $row['cookies'] ) && is_array( $row['cookies'] ) ) {
					foreach ( $row['cookies'] as $c ) {
						if ( is_array( $c ) && ! empty( $c['name'] ) ) {
							$cookies[] = (string) $c['name'];
						} elseif ( is_string( $c ) && '' !== $c ) {
							$cookies[] = $c;
						}
					}
				}
				$block = '';
				if ( ! empty( $row['block_source'] ) ) {
					$block = ! empty( $row['block_is_regex'] )
						? esc_html__( 'regex', 'db-cookie-manager' )
						: esc_html__( 'substring', 'db-cookie-manager' );
				} else {
					$block = '—';
				}
				$reactive = ! empty( $row['reactive_cleanup'] )
					? esc_html__( 'Sì', 'db-cookie-manager' )
					: esc_html__( 'No', 'db-cookie-manager' );

				$edit_url = add_query_arg(
					array(
						'page'      => self::MENU_SLUG . '-signatures',
						'dbcm_edit' => (int) $i,
					),
					admin_url( 'admin.php' )
				);

				$delete_url = wp_nonce_url(
					add_query_arg(
						array(
							'action' => 'dbcm_delete_signature',
							'index'  => (int) $i,
						),
						admin_url( 'admin-post.php' )
					),
					'dbcm_delete_signature_' . (int) $i
				);

				echo '<tr>';
				echo '<td><strong>' . esc_html( $service ) . '</strong></td>';
				echo '<td>' . esc_html( $category ) . '</td>';
				echo '<td>' . ( $cookies ? esc_html( implode( ', ', $cookies ) ) : '—' ) . '</td>';
				echo '<td>' . esc_html( $block ) . '</td>';
				echo '<td>' . esc_html( $reactive ) . '</td>';
				echo '<td style="text-align:right;white-space:nowrap">';
				echo '<a class="db-ui-btn db-ui-btn-sm" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Modifica', 'db-cookie-manager' ) . '</a> ';
				echo '<a class="db-ui-btn db-ui-btn-sm db-ui-btn-danger" href="' . esc_url( $delete_url ) . '" onclick="return confirm(\'' . esc_js( __( 'Eliminare questa firma?', 'db-cookie-manager' ) ) . '\')">' . esc_html__( 'Elimina', 'db-cookie-manager' ) . '</a>';
				echo '</td>';
				echo '</tr>';
			}

			echo '</tbody></table>';
			echo '</div></div>';
		}

		/**
		 * Form di aggiunta/modifica di una firma personalizzata.
		 *
		 * @param array|null $edit  Riga in modifica { index, row } o null (nuova).
		 * @return void
		 */
		private static function render_signatures_form( $edit ) {
			$row   = $edit ? $edit['row'] : array();
			$index = $edit ? (int) $edit['index'] : -1;

			$service     = isset( $row['service'] ) ? (string) $row['service'] : '';
			$provider    = isset( $row['provider'] ) ? (string) $row['provider'] : '';
			$privacy_url = isset( $row['privacy_url'] ) ? (string) $row['privacy_url'] : '';
			$category  = isset( $row['category'] ) ? (string) $row['category'] : 'marketing';
			$requires  = isset( $row['requires_consent'] ) ? ! empty( $row['requires_consent'] ) : true;
			$block_src = isset( $row['block_source'] ) ? (string) $row['block_source'] : '';
			$is_regex  = ! empty( $row['block_is_regex'] );
			$reactive  = ! empty( $row['reactive_cleanup'] );

			$cookie_names = array();
			if ( ! empty( $row['cookies'] ) && is_array( $row['cookies'] ) ) {
				foreach ( $row['cookies'] as $c ) {
					if ( is_array( $c ) && ! empty( $c['name'] ) ) {
						$cookie_names[] = (string) $c['name'];
					} elseif ( is_string( $c ) && '' !== $c ) {
						$cookie_names[] = $c;
					}
				}
			}
			if ( empty( $cookie_names ) ) {
				$cookie_names[] = '';
			}

			$title = $edit
				? __( 'Modifica firma', 'db-cookie-manager' )
				: __( 'Aggiungi firma', 'db-cookie-manager' );

			echo '<div class="db-ui-card" id="dbcm-signature-form">';
			echo '<div class="db-ui-card-header"><h3>' . esc_html( $title ) . '</h3></div>';
			echo '<div class="db-ui-card-body">';
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dbcm-form" id="dbcm-signatures-form">
				<input type="hidden" name="action" value="dbcm_save_signatures">
				<input type="hidden" name="edit_index" value="<?php echo esc_attr( (string) $index ); ?>">
				<?php wp_nonce_field( 'dbcm_save_signatures', 'dbcm_sig_nonce' ); ?>

				<?php
				self::field_text(
					'sig_service',
					$service,
					__( 'Nome del servizio *', 'db-cookie-manager' ),
					__( 'Es. "Il Mio Pixel Pubblicitario". Obbligatorio.', 'db-cookie-manager' )
				);

				self::field_text(
					'sig_provider',
					$provider,
					__( 'Fornitore', 'db-cookie-manager' ),
					__( 'Es. "Esempio S.r.l." o il dominio del fornitore. Opzionale, appare nella Cookie Policy.', 'db-cookie-manager' )
				);

				self::field_text(
					'sig_privacy_url',
					$privacy_url,
					__( 'URL informativa privacy', 'db-cookie-manager' ),
					__( 'Link all\'informativa privacy del fornitore (es. https://esempio.com/privacy). Opzionale: se presente, nella Cookie Policy il nome del fornitore diventa un link (trasparenza GDPR Art. 13).', 'db-cookie-manager' )
				);

				self::field_select(
					'sig_category',
					$category,
					__( 'Categoria di consenso', 'db-cookie-manager' ),
					array(
						'functional'           => __( 'Tecnici (functional)', 'db-cookie-manager' ),
						'preferences'          => __( 'Preferenze', 'db-cookie-manager' ),
						'statistics'           => __( 'Statistiche', 'db-cookie-manager' ),
						'statistics-anonymous' => __( 'Statistiche anonime', 'db-cookie-manager' ),
						'marketing'            => __( 'Marketing', 'db-cookie-manager' ),
					),
					__( 'Determina sotto quale toggle di consenso ricade questo servizio.', 'db-cookie-manager' )
				);
				?>

				<div class="db-ui-field">
					<label><?php esc_html_e( 'Nomi dei cookie', 'db-cookie-manager' ); ?></label>
					<span class="db-ui-field-help"><?php esc_html_e( 'Uno o più cookie impostati da questo servizio. Il carattere jolly * è supportato (es. _ga_* per tutti i cookie che iniziano con _ga_).', 'db-cookie-manager' ); ?></span>
					<div id="dbcm-cookie-rows">
						<?php foreach ( $cookie_names as $cn ) : ?>
							<div class="dbcm-cookie-row" style="display:flex;gap:8px;margin:6px 0">
								<input type="text" name="sig_cookies[]" value="<?php echo esc_attr( $cn ); ?>" placeholder="_es_cookie" style="flex:1">
								<button type="button" class="db-ui-btn db-ui-btn-sm dbcm-remove-cookie" aria-label="<?php esc_attr_e( 'Rimuovi', 'db-cookie-manager' ); ?>">&times;</button>
							</div>
						<?php endforeach; ?>
					</div>
					<button type="button" class="db-ui-btn db-ui-btn-sm" id="dbcm-add-cookie">+ <?php esc_html_e( 'Aggiungi cookie', 'db-cookie-manager' ); ?></button>
				</div>

				<?php
				self::field_text(
					'sig_block_source',
					$block_src,
					__( 'Pattern di blocco', 'db-cookie-manager' ),
					__( 'Stringa o espressione regolare per riconoscere e bloccare lo script/iframe del servizio nell\'HTML (es. il dominio "esempio-cdn.com/pixel.js"). Lascia vuoto se il servizio non va bloccato preventivamente.', 'db-cookie-manager' )
				);

				self::field_checkbox(
					'sig_block_is_regex',
					$is_regex,
					__( 'Il pattern di blocco è un\'espressione regolare', 'db-cookie-manager' ),
					__( 'Se attivo, il pattern viene interpretato come regex. Una regex non valida viene automaticamente trattata come stringa semplice per non compromettere il sito.', 'db-cookie-manager' )
				);

				self::field_checkbox(
					'sig_requires_consent',
					$requires,
					__( 'Richiede consenso', 'db-cookie-manager' ),
					__( 'Se attivo (predefinito), il servizio viene bloccato finché l\'utente non concede il consenso della categoria. Disattiva solo per servizi strettamente tecnici.', 'db-cookie-manager' )
				);

				self::field_checkbox(
					'sig_reactive_cleanup',
					$reactive,
					__( 'Pulizia reattiva dei cookie', 'db-cookie-manager' ),
					__( 'Se attivo, i cookie elencati vengono rimossi dal browser quando manca il consenso della categoria (anche se scritti da terzi o in precedenza). Consigliato per marketing e statistiche.', 'db-cookie-manager' )
				);
				?>

				<div id="dbcm-regex-warning" class="db-ui-alert db-ui-alert-warning" style="display:none;margin-top:12px">
					<span class="db-ui-alert-icon" aria-hidden="true">⚠️</span>
					<span><?php esc_html_e( 'L\'espressione regolare inserita non sembra valida. Verrà trattata come stringa semplice.', 'db-cookie-manager' ); ?></span>
				</div>

				<p style="margin-top:14px">
					<button type="submit" class="db-ui-btn db-ui-btn-primary db-ui-btn-lg">
						<?php echo esc_html( $edit ? __( 'Salva modifiche', 'db-cookie-manager' ) : __( 'Aggiungi firma', 'db-cookie-manager' ) ); ?>
					</button>
					<?php if ( $edit ) : ?>
						<a class="db-ui-btn db-ui-btn-lg" href="<?php echo esc_url( add_query_arg( 'page', self::MENU_SLUG . '-signatures', admin_url( 'admin.php' ) ) ); ?>">
							<?php esc_html_e( 'Annulla', 'db-cookie-manager' ); ?>
						</a>
					<?php endif; ?>
				</p>
			</form>
			<?php
			echo '</div></div>';
		}

		/**
		 * Sezione import/export JSON delle firme personalizzate.
		 *
		 * @return void
		 */
		private static function render_signatures_import_export() {
			echo '<div class="db-ui-card">';
			echo '<div class="db-ui-card-header"><h3>' . esc_html__( 'Import / Export', 'db-cookie-manager' ) . '</h3></div>';
			echo '<div class="db-ui-card-body">';

			// Export.
			echo '<p style="margin:0 0 10px;font-size:13px;color:var(--db-text-muted)">';
			echo esc_html__( 'Esporta tutte le firme personalizzate in un file JSON, o importa un file esportato in precedenza. L\'import sostituisce le firme correnti.', 'db-cookie-manager' );
			echo '</p>';

			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline-block;margin-right:12px">';
			echo '<input type="hidden" name="action" value="dbcm_export_signatures">';
			wp_nonce_field( 'dbcm_export_signatures', 'dbcm_export_nonce' );
			echo '<button type="submit" class="db-ui-btn">' . esc_html__( 'Esporta JSON', 'db-cookie-manager' ) . '</button>';
			echo '</form>';

			// Import.
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data" style="display:inline-block">';
			echo '<input type="hidden" name="action" value="dbcm_import_signatures">';
			wp_nonce_field( 'dbcm_import_signatures', 'dbcm_import_nonce' );
			echo '<input type="file" name="dbcm_import_file" accept="application/json,.json" required style="margin-right:8px">';
			echo '<button type="submit" class="db-ui-btn">' . esc_html__( 'Importa JSON', 'db-cookie-manager' ) . '</button>';
			echo '</form>';

			echo '</div></div>';
		}

		/* ---------------------------------------------------------------------
		 * Handler admin-post per le firme personalizzate
		 * ------------------------------------------------------------------ */

		/**
		 * Raccoglie i campi POST di UNA firma e la fonde con le firme esistenti
		 * (aggiornando la riga edit_index se >= 0, altrimenti aggiungendo).
		 * Passa sempre da DBCM_Signatures::save_custom() per la sanitizzazione.
		 *
		 * @return void
		 */
		public static function handle_save_signatures() {
			self::guard_signatures_request( 'dbcm_save_signatures', 'dbcm_sig_nonce' );

			$redirect = add_query_arg( 'page', self::MENU_SLUG . '-signatures', admin_url( 'admin.php' ) );

			$service = isset( $_POST['sig_service'] ) ? sanitize_text_field( wp_unslash( $_POST['sig_service'] ) ) : '';
			if ( '' === $service ) {
				wp_safe_redirect( add_query_arg( 'dbcm_msg', 'sig_error', $redirect ) );
				exit;
			}

			$cookies_in = isset( $_POST['sig_cookies'] ) ? (array) wp_unslash( $_POST['sig_cookies'] ) : array();
			$cookies    = array();
			foreach ( $cookies_in as $name ) {
				$name = sanitize_text_field( $name );
				if ( '' !== $name ) {
					$cookies[] = array( 'name' => $name );
				}
			}

			$new_row = array(
				'service'          => $service,
				'provider'         => isset( $_POST['sig_provider'] ) ? sanitize_text_field( wp_unslash( $_POST['sig_provider'] ) ) : '',
				'privacy_url'      => isset( $_POST['sig_privacy_url'] ) ? esc_url_raw( wp_unslash( $_POST['sig_privacy_url'] ) ) : '',
				'category'         => isset( $_POST['sig_category'] ) ? sanitize_key( wp_unslash( $_POST['sig_category'] ) ) : 'marketing',
				'requires_consent' => ! empty( $_POST['sig_requires_consent'] ),
				'block_source'     => isset( $_POST['sig_block_source'] ) ? sanitize_text_field( wp_unslash( $_POST['sig_block_source'] ) ) : '',
				'block_is_regex'   => ! empty( $_POST['sig_block_is_regex'] ),
				'reactive_cleanup' => ! empty( $_POST['sig_reactive_cleanup'] ),
				'cookies'          => $cookies,
			);

			$rows = DBCM_Signatures::get_custom_raw();
			$idx  = isset( $_POST['edit_index'] ) ? (int) $_POST['edit_index'] : -1;
			if ( $idx >= 0 && isset( $rows[ $idx ] ) ) {
				$rows[ $idx ] = $new_row;
			} else {
				$rows[] = $new_row;
			}

			// save_custom() re-indicizza, sanifica riga per riga, degrada regex
			// malformate e invalida il cache.
			DBCM_Signatures::save_custom( array_values( $rows ) );

			wp_safe_redirect( add_query_arg( 'dbcm_msg', 'sig_saved', $redirect ) );
			exit;
		}

		/**
		 * Elimina una firma per indice.
		 *
		 * @return void
		 */
		public static function handle_delete_signature() {
			if ( ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Permessi insufficienti.', 'db-cookie-manager' ), '', array( 'response' => 403 ) );
			}
			$idx   = isset( $_GET['index'] ) ? (int) $_GET['index'] : -1;
			$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'dbcm_delete_signature_' . $idx ) ) {
				wp_die( esc_html__( 'Token di sicurezza scaduto.', 'db-cookie-manager' ), '', array( 'response' => 403 ) );
			}

			$redirect = add_query_arg( 'page', self::MENU_SLUG . '-signatures', admin_url( 'admin.php' ) );

			$rows = DBCM_Signatures::get_custom_raw();
			if ( isset( $rows[ $idx ] ) ) {
				unset( $rows[ $idx ] );
				DBCM_Signatures::save_custom( array_values( $rows ) );
			}

			wp_safe_redirect( add_query_arg( 'dbcm_msg', 'sig_deleted', $redirect ) );
			exit;
		}

		/**
		 * Importa firme da un file JSON caricato. Il JSON viene decodificato e
		 * passato a save_custom() (mai scritto grezzo): la sanitizzazione è la
		 * stessa dell'aggiunta manuale.
		 *
		 * @return void
		 */
		public static function handle_import_signatures() {
			self::guard_signatures_request( 'dbcm_import_signatures', 'dbcm_import_nonce' );

			$redirect = add_query_arg( 'page', self::MENU_SLUG . '-signatures', admin_url( 'admin.php' ) );

			if ( empty( $_FILES['dbcm_import_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['dbcm_import_file']['tmp_name'] ) ) {
				wp_safe_redirect( add_query_arg( 'dbcm_msg', 'sig_import_error', $redirect ) );
				exit;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- file caricato via form, lettura locale.
			$contents = file_get_contents( $_FILES['dbcm_import_file']['tmp_name'] );
			$data     = json_decode( (string) $contents, true );

			if ( ! is_array( $data ) ) {
				wp_safe_redirect( add_query_arg( 'dbcm_msg', 'sig_import_error', $redirect ) );
				exit;
			}

			// Accetta sia { "signatures": [...] } sia un array diretto di righe.
			$rows = isset( $data['signatures'] ) && is_array( $data['signatures'] ) ? $data['signatures'] : $data;
			if ( ! is_array( $rows ) ) {
				wp_safe_redirect( add_query_arg( 'dbcm_msg', 'sig_import_error', $redirect ) );
				exit;
			}

			DBCM_Signatures::save_custom( array_values( $rows ) );

			wp_safe_redirect( add_query_arg( 'dbcm_msg', 'sig_imported', $redirect ) );
			exit;
		}

		/**
		 * Esporta le firme personalizzate come download JSON.
		 *
		 * @return void
		 */
		public static function handle_export_signatures() {
			self::guard_signatures_request( 'dbcm_export_signatures', 'dbcm_export_nonce' );

			$payload = array(
				'plugin'     => 'db-cookie-manager',
				'version'    => defined( 'DBCM_VERSION' ) ? DBCM_VERSION : '',
				'exported'   => gmdate( 'c' ),
				'signatures' => DBCM_Signatures::get_custom_raw(),
			);

			nocache_headers();
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="dbcm-signatures-' . gmdate( 'Ymd' ) . '.json"' );
			echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			exit;
		}

		/**
		 * Guard comune agli handler delle firme: capability + nonce.
		 *
		 * @param string $action      Action del nonce.
		 * @param string $nonce_field Nome del campo nonce in POST.
		 * @return void
		 */
		private static function guard_signatures_request( $action, $nonce_field ) {
			if ( ! current_user_can( self::CAP ) ) {
				wp_die( esc_html__( 'Permessi insufficienti.', 'db-cookie-manager' ), '', array( 'response' => 403 ) );
			}
			$nonce = isset( $_POST[ $nonce_field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $nonce_field ] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, $action ) ) {
				wp_die( esc_html__( 'Token di sicurezza scaduto. Ricarica la pagina e riprova.', 'db-cookie-manager' ), '', array( 'response' => 403 ) );
			}
		}
	}
}