<?php
/**
 * DBCM_Admin_Page_Policy — Cookie Policy (anteprima + creazione pagina).
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

if ( ! class_exists( 'DBCM_Admin_Page_Policy' ) ) {

	class DBCM_Admin_Page_Policy {

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
			if ( ! current_user_can( DBCM_Admin::CAP ) ) {
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

			$redirect = wp_get_referer() ?: admin_url( 'admin.php?page=' . DBCM_Admin::MENU_SLUG . '-policy' );

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

		public static function render_policy() {
			if ( ! current_user_can( DBCM_Admin::CAP ) ) {
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

			DBCM_Admin::open_wrap(
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
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . DBCM_Admin::MENU_SLUG . '-scanner' ) ); ?>"><?php esc_html_e( 'Apri Scanner', 'db-cookie-manager' ); ?></a>
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

			DBCM_Admin::close_wrap();
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

	}
}
