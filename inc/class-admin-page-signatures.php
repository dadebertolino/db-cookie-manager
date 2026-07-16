<?php
/**
 * DBCM_Admin_Page_Signatures — Firme personalizzate (CRUD + import/export).
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

if ( ! class_exists( 'DBCM_Admin_Page_Signatures' ) ) {

	class DBCM_Admin_Page_Signatures {

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
			if ( ! current_user_can( DBCM_Admin::CAP ) ) {
				wp_die( esc_html__( 'Permessi insufficienti.', 'db-cookie-manager' ) );
			}

			$rows = DBCM_Signatures::get_custom_raw();
			$edit = self::signatures_row_being_edited( $rows );

			DBCM_Admin::open_wrap(
				__( 'Firme personalizzate', 'db-cookie-manager' ),
				__( 'Aggiungi manualmente servizi e cookie non coperti dal database interno. Utile per script proprietari, CDN aziendali o integrazioni di nicchia.', 'db-cookie-manager' )
			);

			self::render_signatures_table( $rows );
			self::render_signatures_form( $edit );
			self::render_signatures_import_export();

			DBCM_Admin::close_wrap();
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
						'page'      => DBCM_Admin::MENU_SLUG . '-signatures',
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
				DBCM_Admin::field_text(
					'sig_service',
					$service,
					__( 'Nome del servizio *', 'db-cookie-manager' ),
					__( 'Es. "Il Mio Pixel Pubblicitario". Obbligatorio.', 'db-cookie-manager' )
				);

				DBCM_Admin::field_text(
					'sig_provider',
					$provider,
					__( 'Fornitore', 'db-cookie-manager' ),
					__( 'Es. "Esempio S.r.l." o il dominio del fornitore. Opzionale, appare nella Cookie Policy.', 'db-cookie-manager' )
				);

				DBCM_Admin::field_text(
					'sig_privacy_url',
					$privacy_url,
					__( 'URL informativa privacy', 'db-cookie-manager' ),
					__( 'Link all\'informativa privacy del fornitore (es. https://esempio.com/privacy). Opzionale: se presente, nella Cookie Policy il nome del fornitore diventa un link (trasparenza GDPR Art. 13).', 'db-cookie-manager' )
				);

				DBCM_Admin::field_select(
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
				DBCM_Admin::field_text(
					'sig_block_source',
					$block_src,
					__( 'Pattern di blocco', 'db-cookie-manager' ),
					__( 'Stringa o espressione regolare per riconoscere e bloccare lo script/iframe del servizio nell\'HTML (es. il dominio "esempio-cdn.com/pixel.js"). Lascia vuoto se il servizio non va bloccato preventivamente.', 'db-cookie-manager' )
				);

				DBCM_Admin::field_checkbox(
					'sig_block_is_regex',
					$is_regex,
					__( 'Il pattern di blocco è un\'espressione regolare', 'db-cookie-manager' ),
					__( 'Se attivo, il pattern viene interpretato come regex. Una regex non valida viene automaticamente trattata come stringa semplice per non compromettere il sito.', 'db-cookie-manager' )
				);

				DBCM_Admin::field_checkbox(
					'sig_requires_consent',
					$requires,
					__( 'Richiede consenso', 'db-cookie-manager' ),
					__( 'Se attivo (predefinito), il servizio viene bloccato finché l\'utente non concede il consenso della categoria. Disattiva solo per servizi strettamente tecnici.', 'db-cookie-manager' )
				);

				DBCM_Admin::field_checkbox(
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
						<a class="db-ui-btn db-ui-btn-lg" href="<?php echo esc_url( add_query_arg( 'page', DBCM_Admin::MENU_SLUG . '-signatures', admin_url( 'admin.php' ) ) ); ?>">
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

			$redirect = add_query_arg( 'page', DBCM_Admin::MENU_SLUG . '-signatures', admin_url( 'admin.php' ) );

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
			if ( ! current_user_can( DBCM_Admin::CAP ) ) {
				wp_die( esc_html__( 'Permessi insufficienti.', 'db-cookie-manager' ), '', array( 'response' => 403 ) );
			}
			$idx   = isset( $_GET['index'] ) ? (int) $_GET['index'] : -1;
			$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, 'dbcm_delete_signature_' . $idx ) ) {
				wp_die( esc_html__( 'Token di sicurezza scaduto.', 'db-cookie-manager' ), '', array( 'response' => 403 ) );
			}

			$redirect = add_query_arg( 'page', DBCM_Admin::MENU_SLUG . '-signatures', admin_url( 'admin.php' ) );

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

			$redirect = add_query_arg( 'page', DBCM_Admin::MENU_SLUG . '-signatures', admin_url( 'admin.php' ) );

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
			if ( ! current_user_can( DBCM_Admin::CAP ) ) {
				wp_die( esc_html__( 'Permessi insufficienti.', 'db-cookie-manager' ), '', array( 'response' => 403 ) );
			}
			$nonce = isset( $_POST[ $nonce_field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $nonce_field ] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, $action ) ) {
				wp_die( esc_html__( 'Token di sicurezza scaduto. Ricarica la pagina e riprova.', 'db-cookie-manager' ), '', array( 'response' => 403 ) );
			}
		}

	}
}
