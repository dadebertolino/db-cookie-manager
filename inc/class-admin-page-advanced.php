<?php
/**
 * DBCM_Admin_Page_Advanced — Impostazioni avanzate (GCM, segnali Microsoft, API).
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

if ( ! class_exists( 'DBCM_Admin_Page_Advanced' ) ) {

	class DBCM_Admin_Page_Advanced {

		public static function render_advanced() {
			if ( ! current_user_can( DBCM_Admin::CAP ) ) {
				wp_die( esc_html__( 'Permessi insufficienti.', 'db-cookie-manager' ) );
			}

			$s = DBCM_Settings::all();

			DBCM_Admin::open_wrap(
				__( 'Impostazioni avanzate', 'db-cookie-manager' ),
				__( 'Segnali browser, geo-targeting e API JavaScript per gli sviluppatori.', 'db-cookie-manager' )
			);

			self::render_advanced_form( $s );
			self::render_advanced_api_card();

			DBCM_Admin::close_wrap();
		}

		/**
		 * Form impostazioni avanzate: respect_dnt, respect_gpc, geo_targeting.
		 *
		 * @param array $s
		 * @return void
		 */
		private static function render_advanced_form( $s ) {
			DBCM_Admin::form_open( 'advanced' );
			?>

			<div class="db-ui-card">
				<div class="db-ui-card-header"><h3><?php esc_html_e( 'Segnali del browser', 'db-cookie-manager' ); ?></h3></div>
				<div class="db-ui-card-body">
					<p style="margin:0 0 14px;font-size:13px;color:var(--db-text-muted)">
						<?php esc_html_e( 'Quando il browser invia un segnale di rifiuto del tracking, il banner può rispettarlo automaticamente senza chiedere all\'utente. L\'implementazione completa di questi segnali sarà attivata nello step 7.', 'db-cookie-manager' ); ?>
					</p>

					<?php
					DBCM_Admin::field_checkbox(
						'respect_dnt',
						$s['respect_dnt'],
						__( 'Rispetta Do Not Track (DNT)', 'db-cookie-manager' ),
						__( 'Se il browser invia l\'header DNT:1, considera l\'utente come "rifiuta tutto" senza mostrare il banner. DNT è oggi poco usato ma alcuni browser e configurazioni privacy lo abilitano.', 'db-cookie-manager' )
					);

					DBCM_Admin::field_checkbox(
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
					DBCM_Admin::field_checkbox(
						'geo_targeting',
						$s['geo_targeting'],
						__( 'Mostra il banner solo ai visitatori dell\'Unione Europea', 'db-cookie-manager' ),
						__( 'Quando attivo, il banner viene mostrato solo se il visitatore proviene da un paese UE/EEA o UK (rilevato tramite header Cloudflare CF-IPCountry o accept-language). Sconsigliato se il sito è italiano: meglio mostrare il banner a tutti per evitare incoerenze di esperienza.', 'db-cookie-manager' )
					);
					?>

					<div class="db-ui-alert db-ui-alert-info" style="margin-top:14px">
						<span class="db-ui-alert-icon" aria-hidden="true">ℹ️</span>
						<span>
						<?php
						esc_html_e(
							'Il geo-targeting riduce il banner per visitatori extra-UE ma non li esclude del tutto: il blocco preventivo degli script tracking continua a funzionare per coerenza tecnica. Per disattivare completamente il banner per alcune regioni, considera di disabilitarlo via codice tramite il filtro dbcm_should_render_banner.',
							'db-cookie-manager'
						);
						?>
						</span>
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
					DBCM_Admin::field_checkbox(
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
				<div class="db-ui-card-header"><h3><?php esc_html_e( 'Segnali di consenso Microsoft', 'db-cookie-manager' ); ?></h3></div>
				<div class="db-ui-card-body">
					<p style="margin:0 0 14px;font-size:13px;color:var(--db-text-muted)">
						<?php esc_html_e( 'Come Google, anche Microsoft richiede un segnale di consenso esplicito per i visitatori da SEE, Regno Unito e Svizzera: UET Consent Mode per i tag pubblicitari (Microsoft Advertising / Bing Ads) e ConsentV2 per Clarity. Attiva solo i segnali dei servizi che usi: il plugin emette il default negato prima del caricamento dei tag e invia l\'aggiornamento quando l\'utente sceglie.', 'db-cookie-manager' ); ?>
					</p>
					<?php
					DBCM_Admin::field_checkbox(
						'uet_enabled',
						$s['uet_enabled'],
						__( 'Attiva Microsoft UET Consent Mode', 'db-cookie-manager' ),
						__( 'Segnale ad_storage legato alla categoria Marketing. Personalizzabile dagli sviluppatori via filtro dbcm_uet_mapping.', 'db-cookie-manager' )
					);
					DBCM_Admin::field_checkbox(
						'clarity_enabled',
						$s['clarity_enabled'],
						__( 'Attiva Microsoft Clarity ConsentV2', 'db-cookie-manager' ),
						__( 'analytics_Storage → Statistiche, ad_Storage → Marketing. La categoria "Statistiche anonime" non è mappata: le registrazioni di sessione di Clarity non sono statistica anonima. Personalizzabile via filtro dbcm_clarity_mapping.', 'db-cookie-manager' )
					);
					?>
					<div class="db-ui-alert db-ui-alert-info" style="margin-top:14px">
						<span class="db-ui-alert-icon" aria-hidden="true">ℹ️</span>
						<span><?php esc_html_e( 'Alla revoca del consenso Clarity riceve il segnale negato, elimina i propri cookie e prosegue in modalità senza consenso.', 'db-cookie-manager' ); ?></span>
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
					DBCM_Admin::field_checkbox(
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

			<div class="db-ui-card">
				<div class="db-ui-card-header"><h3><?php esc_html_e( 'Meta Pixel', 'db-cookie-manager' ); ?></h3></div>
				<div class="db-ui-card-body">
					<p style="margin:0 0 14px;font-size:13px;color:var(--db-text-muted)">
						<?php esc_html_e( 'Modulo Meta Pixel nativo: il plugin che raccoglie il consenso emette anche il pixel, quindi il caricamento è vincolato per costruzione alla categoria Marketing. Lo script di Meta (fbevents.js) non viene contattato finché l\'utente non accetta; all\'accettazione il pixel parte senza reload, e alla revoca riceve il segnale di revoca e i cookie vengono rimossi.', 'db-cookie-manager' ); ?>
					</p>
					<?php
					DBCM_Admin::field_checkbox(
						'meta_pixel_enabled',
						$s['meta_pixel_enabled'],
						__( 'Attiva Meta Pixel', 'db-cookie-manager' ),
						__( 'Richiede un Pixel ID valido. Se usi già un altro plugin che inserisce il Meta Pixel (es. Meta for WooCommerce), disattivalo per evitare doppi conteggi: quello resterebbe comunque bloccato dal blocker fino al consenso.', 'db-cookie-manager' )
					);
					DBCM_Admin::field_text(
						'meta_pixel_id',
						$s['meta_pixel_id'],
						__( 'Pixel ID', 'db-cookie-manager' ),
						__( '15–16 cifre. Lo trovi in Meta Gestione eventi → Origini dei dati. Un valore non numerico o di lunghezza errata viene scartato al salvataggio.', 'db-cookie-manager' )
					);
					DBCM_Admin::field_checkbox(
						'meta_pixel_capi_handoff',
						$s['meta_pixel_capi_handoff'],
						__( 'Handoff event_id per Conversions API', 'db-cookie-manager' ),
						__( 'Attiva solo se usi DB Meta Events per la Conversions API server-side: il PageView viene inviato con un eventID condiviso (esposto in window.DBCM_META_LAST_EVENT_ID) per la deduplicazione browser/server.', 'db-cookie-manager' )
					);
					?>
					<div class="db-ui-alert db-ui-alert-info" style="margin-top:14px">
						<span class="db-ui-alert-icon" aria-hidden="true">ℹ️</span>
						<span><?php esc_html_e( 'Conformità: l\'uso del Meta Pixel comporta contitolarità con Meta (art. 26 GDPR) e trasferimento verso gli USA in Data Privacy Framework. Il plugin dichiara automaticamente cookie e trattamento nella Cookie Policy e, via DB Privacy Hub, nella Privacy Policy; verifica di aver accettato le Condizioni per gli strumenti business di Meta.', 'db-cookie-manager' ); ?></span>
					</div>
				</div>
			</div>

			<?php
			DBCM_Admin::form_close( __( 'Salva impostazioni avanzate', 'db-cookie-manager' ) );
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
					'code'  => <<<'JS'
if (window.DBCM && window.DBCM.hasConsent('statistics')) {
    var s = document.createElement('script');
    s.src = 'https://www.googletagmanager.com/gtag/js?id=G-XXXX';
    document.head.appendChild(s);
}
JS
					,
				),
				'on-consent' => array(
					'title' => __( 'Reagire al cambio di consenso', 'db-cookie-manager' ),
					'desc'  => __( 'Esegue il callback ogni volta che l\'utente cambia le proprie preferenze.', 'db-cookie-manager' ),
					'code'  => <<<'JS'
window.DBCM.onConsent(function(consent, type) {
    console.log('Tipo:', type); // 'accept_all' | 'reject_all' | 'custom'
    console.log('Marketing:', consent.marketing);
    if (consent.marketing) {
        // attiva pixel pubblicitari
    }
});
JS
					,
				),
				'open-prefs' => array(
					'title' => __( 'Aprire il pannello preferenze da un link', 'db-cookie-manager' ),
					'desc'  => __( 'Aggancia un click handler a un link "Modifica preferenze" nel footer.', 'db-cookie-manager' ),
					'code'  => <<<'JS'
document.querySelectorAll('.modifica-cookie').forEach(function(el) {
    el.addEventListener('click', function(e) {
        e.preventDefault();
        window.DBCM.openPreferences();
    });
});
JS
					,
				),
				'event' => array(
					'title' => __( 'Ascoltare l\'evento dbcm:consent', 'db-cookie-manager' ),
					'desc'  => __( 'Alternativa a onConsent: usa il sistema standard di eventi DOM.', 'db-cookie-manager' ),
					'code'  => <<<'JS'
document.addEventListener('dbcm:consent', function(ev) {
    var consent = ev.detail.consent;
    var type    = ev.detail.type;
    // ...
});
JS
					,
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
	}
}
