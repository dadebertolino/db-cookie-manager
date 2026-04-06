<?php
/**
 * Cookie Policy Generator
 *
 * @package db-cookie-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DBCM_Policy_Generator {

    /**
     * Generate Cookie Policy HTML
     */
    public static function generate() {
        $grouped  = DBCM_Scanner::get_results_grouped();
        $site     = get_bloginfo( 'name' );
        $site_url = home_url();
        $email    = get_option( 'admin_email' );
        $date     = date_i18n( get_option( 'date_format' ) );
        $google_fonts = get_option( 'dbcm_google_fonts_detected', false );

        ob_start();
        ?>
<h2>Cookie Policy</h2>

<p><strong>Ultimo aggiornamento:</strong> <?php echo esc_html( $date ); ?></p>

<p>La presente Cookie Policy descrive l'utilizzo dei cookie e tecnologie simili sul sito <strong><?php echo esc_html( $site ); ?></strong> (<?php echo esc_url( $site_url ); ?>), ai sensi dell'art. 122 del D.Lgs. 196/2003, del Regolamento (UE) 2016/679 (GDPR) e delle Linee Guida del Garante Privacy del 10 giugno 2021.</p>

<h3>1. Cosa sono i cookie</h3>

<p>I cookie sono piccoli file di testo che i siti web visitati inviano al browser dell'utente, dove vengono memorizzati per essere ritrasmessi agli stessi siti alla visita successiva.</p>

<h3>2. Cookie utilizzati su questo sito</h3>

<?php if ( ! empty( $grouped ) ) : ?>
<p>Dalla scansione automatica del sito sono stati rilevati i seguenti cookie:</p>

<?php foreach ( $grouped as $category => $cookies ) : ?>

<h4><?php echo esc_html( DBCM_Cookie_Database::get_category_label( $category ) ); ?></h4>

<?php if ( $category === 'tecnico' ) : ?>
<p>Questi cookie sono essenziali per il corretto funzionamento del sito e non richiedono il consenso dell'utente.</p>
<?php elseif ( $category === 'prestazioni' ) : ?>
<p>Questi cookie migliorano le prestazioni del sito tramite servizi come CDN, caching e bilanciamento del carico. Richiedono il consenso dell'utente.</p>
<?php elseif ( $category === 'analitica' ) : ?>
<p>Questi cookie raccolgono informazioni sull'utilizzo del sito in forma aggregata per migliorare il servizio. Richiedono il consenso dell'utente.</p>
<?php elseif ( $category === 'marketing' ) : ?>
<p>Questi cookie sono utilizzati per tracciare la navigazione dell'utente e mostrare pubblicità personalizzata. Richiedono il consenso esplicito dell'utente.</p>
<?php else : ?>
<p>Cookie non ancora classificati. Verificare la finalità e aggiornare la policy.</p>
<?php endif; ?>

<table style="width:100%;border-collapse:collapse;">
<thead>
<tr>
<th style="border:1px solid #ddd;padding:8px;text-align:left;background:#f5f5f5;">Cookie</th>
<th style="border:1px solid #ddd;padding:8px;text-align:left;background:#f5f5f5;">Fornitore</th>
<th style="border:1px solid #ddd;padding:8px;text-align:left;background:#f5f5f5;">Finalità</th>
<th style="border:1px solid #ddd;padding:8px;text-align:left;background:#f5f5f5;">Durata</th>
</tr>
</thead>
<tbody>
<?php foreach ( $cookies as $cookie ) : ?>
<tr>
<td style="border:1px solid #ddd;padding:8px;"><code><?php echo esc_html( $cookie->cookie_name ); ?></code></td>
<td style="border:1px solid #ddd;padding:8px;"><?php echo esc_html( $cookie->provider ); ?></td>
<td style="border:1px solid #ddd;padding:8px;"><?php echo esc_html( $cookie->description ); ?></td>
<td style="border:1px solid #ddd;padding:8px;"><?php echo esc_html( $cookie->cookie_duration ); ?></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

<?php endforeach; ?>

<?php else : ?>

<h4>Cookie tecnici (necessari)</h4>

<p>Questi cookie sono essenziali per il corretto funzionamento del sito e non richiedono il consenso dell'utente.</p>

<table style="width:100%;border-collapse:collapse;">
<thead>
<tr>
<th style="border:1px solid #ddd;padding:8px;text-align:left;background:#f5f5f5;">Cookie</th>
<th style="border:1px solid #ddd;padding:8px;text-align:left;background:#f5f5f5;">Fornitore</th>
<th style="border:1px solid #ddd;padding:8px;text-align:left;background:#f5f5f5;">Finalità</th>
<th style="border:1px solid #ddd;padding:8px;text-align:left;background:#f5f5f5;">Durata</th>
</tr>
</thead>
<tbody>
<tr>
<td style="border:1px solid #ddd;padding:8px;"><code>dbcm_consent</code></td>
<td style="border:1px solid #ddd;padding:8px;">DB Cookie Manager</td>
<td style="border:1px solid #ddd;padding:8px;">Memorizza la scelta dell'utente sui cookie (accetta/necessari/rifiuta)</td>
<td style="border:1px solid #ddd;padding:8px;">365 giorni</td>
</tr>
</tbody>
</table>

<?php endif; ?>

<?php if ( $google_fonts ) : ?>
<h4>Servizi esterni senza cookie</h4>

<p><strong>Google Fonts</strong> — Il sito carica font da Google Fonts CDN. Google può raccogliere dati tecnici (indirizzo IP) durante il caricamento. Questi non sono cookie, ma comportano una connessione a server di terze parti.<br>
Informativa: <a href="https://policies.google.com/privacy" target="_blank" rel="noopener">Google Privacy Policy</a></p>
<?php endif; ?>

<h3>3. Come gestire i cookie</h3>

<p>Puoi gestire le preferenze sui cookie tramite il banner cookie presente sul sito o tramite le impostazioni del tuo browser:</p>

<ul>
<li><a href="https://support.google.com/chrome/answer/95647" target="_blank" rel="noopener">Google Chrome</a></li>
<li><a href="https://support.mozilla.org/it/kb/Gestione%20dei%20cookie" target="_blank" rel="noopener">Mozilla Firefox</a></li>
<li><a href="https://support.apple.com/it-it/guide/safari/sfri11471/" target="_blank" rel="noopener">Safari</a></li>
<li><a href="https://support.microsoft.com/it-it/microsoft-edge/eliminare-i-cookie-in-microsoft-edge-63947406-40ac-c3b8-57b9-2a946a29ae09" target="_blank" rel="noopener">Microsoft Edge</a></li>
</ul>

<p><strong>Nota:</strong> la disabilitazione dei cookie tecnici potrebbe compromettere il funzionamento del sito.</p>

<h3>4. Titolare del trattamento</h3>

<p><strong>[NOME COMPLETO]</strong><br>
Email: <?php echo esc_html( $email ); ?></p>

<h3>5. Aggiornamenti</h3>

<p>La presente Cookie Policy può essere soggetta a modifiche. La data dell'ultimo aggiornamento è indicata in alto.</p>

<p><em>Testo generato automaticamente da DB Cookie Manager in base alla scansione del <?php echo esc_html( $date ); ?>. Verificare con un professionista prima della pubblicazione.</em></p>
        <?php
        return ob_get_clean();
    }
}
