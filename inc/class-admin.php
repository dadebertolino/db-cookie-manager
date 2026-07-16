<?php
/**
 * DBCM_Admin — Guscio comune dell'area di amministrazione.
 *
 * Dal refactor 3.5.1 (split meccanico) questa classe contiene SOLO
 * l'infrastruttura condivisa: registrazione menu, enqueue assets,
 * dispatcher di salvataggio centralizzato (handle_save + schema map di
 * sanificazione), flash notices e i render helper (open_wrap, form_*,
 * field_*) usati da tutte le pagine.
 *
 * Le pagine vere e proprie vivono in una classe ciascuna:
 *   - DBCM_Admin_Page_Dashboard   (class-admin-page-dashboard.php)
 *   - DBCM_Admin_Page_Banner      (class-admin-page-banner.php)
 *   - DBCM_Admin_Page_Scanner     (class-admin-page-scanner.php)
 *   - DBCM_Admin_Page_Signatures  (class-admin-page-signatures.php)
 *   - DBCM_Admin_Page_Policy      (class-admin-page-policy.php)
 *   - DBCM_Admin_Page_Log         (class-admin-page-log.php)
 *   - DBCM_Admin_Page_Advanced    (class-admin-page-advanced.php)
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
			add_action( 'admin_post_dbcm_create_policy_page', array( 'DBCM_Admin_Page_Policy', 'handle_create_policy_page' ) );

			// Firme personalizzate (aggiunta manuale + import/export). Non sono
			// settings ma una option separata gestita da DBCM_Signatures::save_custom(),
			// quindi hanno handler dedicati invece di passare da handle_save().
			add_action( 'admin_post_dbcm_save_signatures', array( 'DBCM_Admin_Page_Signatures', 'handle_save_signatures' ) );
			add_action( 'admin_post_dbcm_delete_signature', array( 'DBCM_Admin_Page_Signatures', 'handle_delete_signature' ) );
			add_action( 'admin_post_dbcm_import_signatures', array( 'DBCM_Admin_Page_Signatures', 'handle_import_signatures' ) );
			add_action( 'admin_post_dbcm_export_signatures', array( 'DBCM_Admin_Page_Signatures', 'handle_export_signatures' ) );

			// 3.5.0: bump della versione del consenso. Handler dedicato (non
			// passa da handle_save): incremento server-side, mai un valore
			// arbitrario dal form → non decrementabile né falsificabile.
			add_action( 'admin_post_dbcm_bump_consent_version', array( 'DBCM_Admin_Page_Banner', 'handle_bump_consent_version' ) );

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
				array( 'DBCM_Admin_Page_Dashboard', 'render_dashboard' ),
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
					$page['callback']
				);
			}
		}

		/**
		 * Mappa delle pagine. Ogni voce ha:
		 *  - title    → <title> della pagina
		 *  - menu     → label nel menu sidebar
		 *  - callback → array( classe pagina, metodo render_* )
		 *
		 * @return array
		 */
		public static function pages() {
			return array(
				'dashboard' => array(
					'title'    => __( 'Cookie Manager — Dashboard', 'db-cookie-manager' ),
					'menu'     => __( 'Dashboard', 'db-cookie-manager' ),
					'callback' => array( 'DBCM_Admin_Page_Dashboard', 'render_dashboard' ),
				),
				'banner' => array(
					'title'    => __( 'Cookie Manager — Banner & aspetto', 'db-cookie-manager' ),
					'menu'     => __( 'Banner & aspetto', 'db-cookie-manager' ),
					'callback' => array( 'DBCM_Admin_Page_Banner', 'render_banner' ),
				),
				'scanner' => array(
					'title'    => __( 'Cookie Manager — Scanner', 'db-cookie-manager' ),
					'menu'     => __( 'Scanner', 'db-cookie-manager' ),
					'callback' => array( 'DBCM_Admin_Page_Scanner', 'render_scanner' ),
				),
				'signatures' => array(
					'title'    => __( 'Cookie Manager — Firme personalizzate', 'db-cookie-manager' ),
					'menu'     => __( 'Firme personalizzate', 'db-cookie-manager' ),
					'callback' => array( 'DBCM_Admin_Page_Signatures', 'render_signatures' ),
				),
				'policy' => array(
					'title'    => __( 'Cookie Manager — Cookie Policy', 'db-cookie-manager' ),
					'menu'     => __( 'Cookie Policy', 'db-cookie-manager' ),
					'callback' => array( 'DBCM_Admin_Page_Policy', 'render_policy' ),
				),
				'log' => array(
					'title'    => __( 'Cookie Manager — Registro consensi', 'db-cookie-manager' ),
					'menu'     => __( 'Registro consensi', 'db-cookie-manager' ),
					'callback' => array( 'DBCM_Admin_Page_Log', 'render_log' ),
				),
				'advanced' => array(
					'title'    => __( 'Cookie Manager — Avanzate', 'db-cookie-manager' ),
					'menu'     => __( 'Avanzate', 'db-cookie-manager' ),
					'callback' => array( 'DBCM_Admin_Page_Advanced', 'render_advanced' ),
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
					'uet_enabled'    => 'bool',
					'clarity_enabled' => 'bool',
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
				'consent_version_bumped' => array( 'success', __( 'Versione del consenso incrementata: tutti i visitatori vedranno di nuovo il banner alla prossima visita.', 'db-cookie-manager' ) ),
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

	}
}