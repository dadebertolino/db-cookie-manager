<?php
/**
 * DBCM_Admin_Page_Log — Registro dei consensi (tabella + filtri + export).
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

if ( ! class_exists( 'DBCM_Admin_Page_Log' ) ) {

	class DBCM_Admin_Page_Log {

		public static function render_log() {
			if ( ! current_user_can( DBCM_Admin::CAP ) ) {
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

			DBCM_Admin::open_wrap(
				__( 'Registro consensi', 'db-cookie-manager' ),
				__( 'Storico dei consensi raccolti dal banner. IP hashato (irreversibile), user-agent aggregato per default.', 'db-cookie-manager' )
			);

			self::render_log_status_card( $total );
			self::render_log_settings_form( $s );
			self::render_log_filters_and_export( $filters );
			self::render_log_table( $rows, $total, $paged, $per_page, $filters );

			DBCM_Admin::close_wrap();
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
			DBCM_Admin::form_open( 'log_settings' );
			?>
			<div class="db-ui-card">
				<div class="db-ui-card-header"><h3><?php esc_html_e( 'Impostazioni del registro', 'db-cookie-manager' ); ?></h3></div>
				<div class="db-ui-card-body">
					<?php
					DBCM_Admin::field_checkbox(
						'consent_log_enabled',
						$s['consent_log_enabled'],
						__( 'Registra i consensi nel database', 'db-cookie-manager' ),
						__( 'Quando attivo, ogni consenso viene salvato come prova ai sensi dell\'art. 7(1) GDPR. Disabilitare solo se hai un sistema esterno di logging.', 'db-cookie-manager' )
					);

					DBCM_Admin::field_number(
						'consent_log_retention',
						(int) $s['consent_log_retention'],
						__( 'Conserva per (giorni)', 'db-cookie-manager' ),
						__( 'I consensi più vecchi vengono cancellati automaticamente dal cron giornaliero. Imposta a 0 per disattivare la cancellazione automatica. Default: 365 giorni.', 'db-cookie-manager' ),
						0,
						3650
					);

					DBCM_Admin::field_select(
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
			DBCM_Admin::form_close( __( 'Salva impostazioni log', 'db-cookie-manager' ) );
		}

		/**
		 * Card "Filtri ed export": form GET con select type + date range,
		 * + bottoni export CSV/JSON che usano DBCM_Consent_Log::export_url().
		 *
		 * @param array $filters
		 * @return void
		 */
		private static function render_log_filters_and_export( $filters ) {
			$base_url = admin_url( 'admin.php?page=' . DBCM_Admin::MENU_SLUG . '-log' );
			$csv_url  = DBCM_Consent_Log::export_url( 'csv',  $filters );
			$json_url = DBCM_Consent_Log::export_url( 'json', $filters );
			?>
			<div class="db-ui-card">
				<div class="db-ui-card-header"><h3><?php esc_html_e( 'Filtri ed export', 'db-cookie-manager' ); ?></h3></div>
				<div class="db-ui-card-body">

					<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" style="display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end">
						<input type="hidden" name="page" value="<?php echo esc_attr( DBCM_Admin::MENU_SLUG . '-log' ); ?>">

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
									<th title="<?php esc_attr_e( 'Versione del consenso sotto cui la scelta è stata espressa', 'db-cookie-manager' ); ?>"><?php esc_html_e( 'Ver.', 'db-cookie-manager' ); ?></th>
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
										<td><?php echo esc_html( ! empty( $row->consent_version ) ? (string) (int) $row->consent_version : '—' ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>

						<?php if ( $pages > 1 ) : ?>
							<div style="display:flex;justify-content:center;gap:6px;margin-top:14px">
								<?php
								$base_args = array_merge( $filters, array( 'page' => DBCM_Admin::MENU_SLUG . '-log' ) );

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

	}
}
