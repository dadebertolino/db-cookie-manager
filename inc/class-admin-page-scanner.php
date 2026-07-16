<?php
/**
 * DBCM_Admin_Page_Scanner — Scanner cookie (risultati + report differenziale).
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

if ( ! class_exists( 'DBCM_Admin_Page_Scanner' ) ) {

	class DBCM_Admin_Page_Scanner {

		public static function render_scanner() {
			if ( ! current_user_can( DBCM_Admin::CAP ) ) {
				wp_die( esc_html__( 'Permessi insufficienti.', 'db-cookie-manager' ) );
			}

			$s         = DBCM_Settings::all();
			$last_scan = get_option( 'dbcm_last_scan', '' );
			$by_cat    = DBCM_Scanner::count_by_category();
			$total     = array_sum( $by_cat );
			$grouped   = DBCM_Scanner::get_results_grouped();

			DBCM_Admin::open_wrap(
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
			DBCM_Admin::form_open( 'scanner_settings' );
			?>
			<div class="db-ui-card">
				<div class="db-ui-card-header"><h3><?php esc_html_e( 'Impostazioni scanner', 'db-cookie-manager' ); ?></h3></div>
				<div class="db-ui-card-body">
					<?php
					DBCM_Admin::field_checkbox(
						'auto_block',
						$s['auto_block'],
						__( 'Blocco preventivo degli script di tracking', 'db-cookie-manager' ),
						__( 'Quando attivo, gli script di analytics/marketing vengono bloccati prima che il browser li esegua. Disabilitare solo se hai un altro consent manager attivo.', 'db-cookie-manager' )
					);
					?>
				</div>
			</div>
			<?php
			DBCM_Admin::form_close( __( 'Salva impostazioni scanner', 'db-cookie-manager' ) );

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

			DBCM_Admin::close_wrap();
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

	}
}
