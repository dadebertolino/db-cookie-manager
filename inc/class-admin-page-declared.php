<?php
/**
 * DBCM_Admin_Page_Declared — Servizi dichiarati (previo consenso).
 *
 * Pagina admin del registro dei servizi dichiarati (3.6.0): mostra le voci
 * auto-registrate dal blocker (sola lettura, con ultimo rilevamento) e le
 * voci manuali (aggiunta/eliminazione). Segue l'architettura dello split
 * 3.5.1: una classe per pagina, helper condivisi in DBCM_Admin, handler
 * dedicati su admin-post con nonce + capability.
 *
 * @package DBCM
 * @since 3.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DBCM_Admin_Page_Declared' ) ) {

	class DBCM_Admin_Page_Declared {

		/**
		 * Render della pagina.
		 *
		 * @return void
		 */
		public static function render_declared() {
			if ( ! current_user_can( DBCM_Admin::CAP ) ) {
				return;
			}

			DBCM_Admin::open_wrap(
				__( 'Servizi dichiarati', 'db-cookie-manager' ),
				__( 'Servizi attivi previo consenso che compaiono nella Cookie Policy anche se lo scanner non può rilevarli (perché il blocco li ferma prima).', 'db-cookie-manager' )
			);

			self::render_intro_card();
			self::render_coherence_card();
			self::render_entries_table();
			self::render_add_form();

			DBCM_Admin::close_wrap();
		}

		/**
		 * Card introduttiva: spiega perché esiste il registro.
		 *
		 * @return void
		 */
		private static function render_intro_card() {
			?>
			<div class="db-ui-card">
				<div class="db-ui-card-header"><h3><?php esc_html_e( 'Come funziona', 'db-cookie-manager' ); ?></h3></div>
				<div class="db-ui-card-body">
					<p><?php esc_html_e( 'Lo scanner non può vedere gli embed bloccati (YouTube, Maps, ...) proprio perché il blocco funziona: quando lo scanner richiede la pagina, gli iframe sono già stati sostituiti dai segnaposto. Il registro colma il vuoto in due modi:', 'db-cookie-manager' ); ?></p>
					<p><strong><?php esc_html_e( 'Automatico', 'db-cookie-manager' ); ?></strong> — <?php echo esc_html( sprintf( /* translators: %d: giorni */ __( 'ogni volta che il blocco riconosce un servizio su una pagina, lo registra qui. Le voci automatiche compaiono in policy se rilevate negli ultimi %d giorni: rimosso l\'embed dal sito, la voce decade da sola.', 'db-cookie-manager' ), DBCM_Declared_Services::ttl_days() ) ); ?></p>
					<p><strong><?php esc_html_e( 'Manuale', 'db-cookie-manager' ); ?></strong> — <?php esc_html_e( 'per i casi non coperti dalle firme puoi dichiarare un servizio qui sotto. Le voci manuali non scadono mai.', 'db-cookie-manager' ); ?></p>
				</div>
			</div>
			<?php
		}

		/**
		 * Card di validazione coerenza banner ↔ policy.
		 *
		 * @return void
		 */
		private static function render_coherence_card() {
			$uncovered = DBCM_Declared_Services::coherence_warnings();
			if ( empty( $uncovered ) ) {
				return;
			}
			$labels = array();
			foreach ( $uncovered as $category ) {
				$labels[] = DBCM_Cookie_Database::get_category_label( $category );
			}
			?>
			<div class="db-ui-card">
				<div class="db-ui-card-body">
					<div class="db-ui-alert db-ui-alert-warning">
						<span class="db-ui-alert-icon" aria-hidden="true">⚠️</span>
						<span>
							<?php
							printf(
								/* translators: %s: elenco categorie */
								esc_html__( 'Il banner richiede il consenso per le categorie %s, ma la policy non contiene né cookie rilevati né servizi dichiarati per esse. Una richiesta di consenso non giustificata è essa stessa una criticità: dichiara il servizio mancante qui sotto, oppure valuta se la categoria è davvero necessaria.', 'db-cookie-manager' ),
								'<strong>' . esc_html( implode( ', ', $labels ) ) . '</strong>'
							);
							?>
						</span>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Tabella delle voci correnti (auto + manuali).
		 *
		 * @return void
		 */
		private static function render_entries_table() {
			$auto   = DBCM_Declared_Services::auto_entries();
			$manual = DBCM_Declared_Services::manual_entries();
			?>
			<div class="db-ui-card">
				<div class="db-ui-card-header"><h3><?php esc_html_e( 'Voci del registro', 'db-cookie-manager' ); ?></h3></div>
				<div class="db-ui-card-body">
					<?php if ( empty( $auto ) && empty( $manual ) ) : ?>
						<p><?php esc_html_e( 'Nessun servizio nel registro. Le voci automatiche compaiono quando il blocco incontra un embed o uno script noto sulle pagine del sito: visita le pagine con gli embed, o lancia una scansione (la richiesta dello scanner passa dal blocco e popola il registro).', 'db-cookie-manager' ); ?></p>
					<?php else : ?>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Servizio', 'db-cookie-manager' ); ?></th>
									<th><?php esc_html_e( 'Fornitore', 'db-cookie-manager' ); ?></th>
									<th><?php esc_html_e( 'Categoria', 'db-cookie-manager' ); ?></th>
									<th><?php esc_html_e( 'Origine', 'db-cookie-manager' ); ?></th>
									<th><?php esc_html_e( 'Ultimo rilevamento', 'db-cookie-manager' ); ?></th>
									<th></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( array_merge( $manual, $auto ) as $entry ) : ?>
									<tr>
										<td><strong><?php echo esc_html( $entry['service'] ); ?></strong></td>
										<td><?php echo esc_html( $entry['provider'] ); ?></td>
										<td><?php echo esc_html( DBCM_Cookie_Database::get_category_label( $entry['category'] ) ); ?></td>
										<td>
											<?php
											echo 'manual' === $entry['origin']
												? esc_html__( 'Manuale', 'db-cookie-manager' )
												: esc_html__( 'Automatica (dal blocco)', 'db-cookie-manager' );
											?>
										</td>
										<td><?php echo esc_html( '' !== $entry['last_seen'] ? $entry['last_seen'] : '—' ); ?></td>
										<td>
											<?php if ( 'manual' === $entry['origin'] ) : ?>
												<?php
												$delete_url = wp_nonce_url(
													admin_url( 'admin-post.php?action=dbcm_delete_declared_service&entry=' . rawurlencode( $entry['id'] ) ),
													'dbcm_delete_declared_service'
												);
												?>
												<a class="db-ui-btn db-ui-btn-sm db-ui-btn-danger" href="<?php echo esc_url( $delete_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Eliminare questa voce dichiarata?', 'db-cookie-manager' ) ); ?>');"><?php esc_html_e( 'Elimina', 'db-cookie-manager' ); ?></a>
											<?php endif; ?>
										</td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<p style="font-size:13px;color:var(--db-text-muted);margin-top:8px">
							<?php esc_html_e( 'Le voci automatiche non si eliminano: decadono da sole quando il servizio non viene più rilevato entro la finestra di validità. I dettagli (fornitore, cookie tipici, informativa) provengono dal database firme e restano aggiornati con il plugin.', 'db-cookie-manager' ); ?>
						</p>
					<?php endif; ?>
				</div>
			</div>
			<?php
		}

		/**
		 * Form di aggiunta manuale: pick-list dalle firme note + campi liberi.
		 *
		 * @return void
		 */
		private static function render_add_form() {
			// Pick-list: firme che richiedono consenso, non già dichiarate.
			$already = array();
			foreach ( array_merge( DBCM_Declared_Services::auto_entries(), DBCM_Declared_Services::manual_entries() ) as $entry ) {
				if ( '' !== $entry['slug'] ) {
					$already[ $entry['slug'] ] = true;
				}
			}
			$choices = array();
			foreach ( DBCM_Signatures::all() as $slug => $sig ) {
				if ( empty( $sig['requires_consent'] ) || isset( $already[ $slug ] ) ) {
					continue;
				}
				$choices[ $slug ] = $sig['service'];
			}
			asort( $choices );
			?>
			<div class="db-ui-card">
				<div class="db-ui-card-header"><h3><?php esc_html_e( 'Dichiara un servizio', 'db-cookie-manager' ); ?></h3></div>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<div class="db-ui-card-body">
						<input type="hidden" name="action" value="dbcm_add_declared_service">
						<?php wp_nonce_field( 'dbcm_add_declared_service', 'dbcm_declared_nonce' ); ?>

						<p>
							<label for="dbcm-declared-slug"><strong><?php esc_html_e( 'Da firma nota (consigliato)', 'db-cookie-manager' ); ?></strong></label><br>
							<select id="dbcm-declared-slug" name="slug">
								<option value=""><?php esc_html_e( '— Servizio personalizzato (compila i campi sotto) —', 'db-cookie-manager' ); ?></option>
								<?php foreach ( $choices as $slug => $service ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $service ); ?></option>
								<?php endforeach; ?>
							</select><br>
							<span style="font-size:13px;color:var(--db-text-muted)"><?php esc_html_e( 'Scegliendo una firma nota, fornitore, cookie tipici, durate e informativa vengono compilati automaticamente.', 'db-cookie-manager' ); ?></span>
						</p>

						<p>
							<label for="dbcm-declared-service"><?php esc_html_e( 'Nome del servizio', 'db-cookie-manager' ); ?></label><br>
							<input type="text" id="dbcm-declared-service" name="service" class="regular-text" placeholder="<?php esc_attr_e( 'Es. Vimeo', 'db-cookie-manager' ); ?>">
						</p>
						<p>
							<label for="dbcm-declared-provider"><?php esc_html_e( 'Fornitore', 'db-cookie-manager' ); ?></label><br>
							<input type="text" id="dbcm-declared-provider" name="provider" class="regular-text" placeholder="<?php esc_attr_e( 'Es. Vimeo Inc.', 'db-cookie-manager' ); ?>">
						</p>
						<p>
							<label for="dbcm-declared-category"><?php esc_html_e( 'Categoria di consenso', 'db-cookie-manager' ); ?></label><br>
							<select id="dbcm-declared-category" name="category">
								<?php foreach ( DBCM_Settings::categories_optional() as $category ) : ?>
									<option value="<?php echo esc_attr( $category ); ?>" <?php selected( 'marketing', $category ); ?>><?php echo esc_html( DBCM_Cookie_Database::get_category_label( $category ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>
						<p>
							<label for="dbcm-declared-cookies"><?php esc_html_e( 'Cookie tipici (separati da virgola)', 'db-cookie-manager' ); ?></label><br>
							<input type="text" id="dbcm-declared-cookies" name="cookies_text" class="regular-text" placeholder="vuid, __cf_bm">
						</p>
						<p>
							<label for="dbcm-declared-duration"><?php esc_html_e( 'Durata', 'db-cookie-manager' ); ?></label><br>
							<input type="text" id="dbcm-declared-duration" name="duration_text" class="regular-text" placeholder="<?php esc_attr_e( 'Es. fino a 2 anni', 'db-cookie-manager' ); ?>">
						</p>
						<p>
							<label for="dbcm-declared-policy-url"><?php esc_html_e( 'URL informativa del fornitore', 'db-cookie-manager' ); ?></label><br>
							<input type="url" id="dbcm-declared-policy-url" name="policy_url" class="regular-text" placeholder="https://...">
						</p>
					</div>
					<div class="db-ui-card-footer">
						<button type="submit" class="db-ui-btn db-ui-btn-primary"><?php esc_html_e( 'Aggiungi al registro', 'db-cookie-manager' ); ?></button>
					</div>
				</form>
			</div>
			<?php
		}

		/* =====================================================================
		 * HANDLER admin-post
		 * ================================================================== */

		/**
		 * Handler 'admin_post_dbcm_add_declared_service'.
		 *
		 * @return void (esce con redirect)
		 */
		public static function handle_add_declared_service() {
			self::guard( 'dbcm_add_declared_service', 'dbcm_declared_nonce' );

			// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce verificato in guard().
			$id = DBCM_Declared_Services::save_manual(
				array(
					'slug'          => isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '',
					'service'       => isset( $_POST['service'] ) ? sanitize_text_field( wp_unslash( $_POST['service'] ) ) : '',
					'provider'      => isset( $_POST['provider'] ) ? sanitize_text_field( wp_unslash( $_POST['provider'] ) ) : '',
					'category'      => isset( $_POST['category'] ) ? sanitize_key( wp_unslash( $_POST['category'] ) ) : '',
					'cookies_text'  => isset( $_POST['cookies_text'] ) ? sanitize_text_field( wp_unslash( $_POST['cookies_text'] ) ) : '',
					'duration_text' => isset( $_POST['duration_text'] ) ? sanitize_text_field( wp_unslash( $_POST['duration_text'] ) ) : '',
					'policy_url'    => isset( $_POST['policy_url'] ) ? esc_url_raw( wp_unslash( $_POST['policy_url'] ) ) : '',
				)
			);
			// phpcs:enable

			self::redirect( false !== $id ? 'declared_added' : 'declared_error' );
		}

		/**
		 * Handler 'admin_post_dbcm_delete_declared_service'.
		 *
		 * @return void (esce con redirect)
		 */
		public static function handle_delete_declared_service() {
			self::guard( 'dbcm_delete_declared_service', '_wpnonce' );

			// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonce verificato in guard().
			$id = isset( $_GET['entry'] ) ? sanitize_text_field( wp_unslash( $_GET['entry'] ) ) : '';
			// phpcs:enable
			$ok = DBCM_Declared_Services::delete_manual( $id );

			self::redirect( $ok ? 'declared_deleted' : 'declared_error' );
		}

		/**
		 * Capability + nonce, come guard_signatures_request.
		 *
		 * @param string $action      Action del nonce.
		 * @param string $nonce_field Nome del campo nonce.
		 * @return void
		 */
		private static function guard( $action, $nonce_field ) {
			if ( ! current_user_can( DBCM_Admin::CAP ) ) {
				wp_die( esc_html__( 'Permessi insufficienti.', 'db-cookie-manager' ), '', array( 'response' => 403 ) );
			}
			$nonce = isset( $_REQUEST[ $nonce_field ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $nonce_field ] ) ) : '';
			if ( ! wp_verify_nonce( $nonce, $action ) ) {
				wp_die( esc_html__( 'Token di sicurezza scaduto. Ricarica la pagina e riprova.', 'db-cookie-manager' ), '', array( 'response' => 403 ) );
			}
		}

		/**
		 * Redirect alla pagina del registro con flash message.
		 *
		 * @param string $msg Codice messaggio.
		 * @return void
		 */
		private static function redirect( $msg ) {
			wp_safe_redirect(
				add_query_arg(
					'dbcm_msg',
					$msg,
					admin_url( 'admin.php?page=' . DBCM_Admin::MENU_SLUG . '-declared' )
				)
			);
			exit;
		}
	}
}
