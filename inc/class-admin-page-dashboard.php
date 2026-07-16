<?php
/**
 * DBCM_Admin_Page_Dashboard — Dashboard.
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

if ( ! class_exists( 'DBCM_Admin_Page_Dashboard' ) ) {

	class DBCM_Admin_Page_Dashboard {

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
			if ( ! current_user_can( DBCM_Admin::CAP ) ) {
				wp_die( esc_html__( 'Permessi insufficienti.', 'db-cookie-manager' ) );
			}

			DBCM_Admin::open_wrap(
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
				foreach ( DBCM_Admin::pages() as $slug => $page ) {
					if ( 'dashboard' === $slug ) {
						continue;
					}
					$url = admin_url( 'admin.php?page=' . DBCM_Admin::MENU_SLUG . '-' . $slug );
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
			DBCM_Admin::close_wrap();
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

	}
}
