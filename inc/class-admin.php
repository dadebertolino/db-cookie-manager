<?php
/**
 * Admin Page
 *
 * @package db-cookie-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DBCM_Admin {

    /**
     * Initialize
     */
    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
        add_action( 'admin_init', array( __CLASS__, 'handle_actions' ) );
    }

    /**
     * Add admin menu
     */
    public static function add_menu() {
        add_management_page(
            __( 'Cookie Manager', 'db-cookie-manager' ),
            __( 'Cookie Manager', 'db-cookie-manager' ),
            'manage_options',
            'db-cookie-manager',
            array( __CLASS__, 'render_page' )
        );
    }

    /**
     * Handle form actions
     */
    public static function handle_actions() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Output AJAX vars on our page
        add_action( 'admin_footer', function() {
            $screen = get_current_screen();
            if ( ! $screen || $screen->id !== 'tools_page_db-cookie-manager' ) {
                return;
            }
            ?>
            <script>
            var dbcmAjax = {
                ajaxurl: '<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>',
                nonce: '<?php echo wp_create_nonce( 'dbcm_ajax_nonce' ); ?>'
            };
            </script>
            <?php
        } );

        // Save settings
        if ( isset( $_POST['dbcm_save_settings_btn'] ) && check_admin_referer( 'dbcm_save_settings' ) ) {
            DBCM_Settings::save( $_POST );
            add_settings_error( 'dbcm_messages', 'dbcm_saved',
                __( 'Impostazioni salvate.', 'db-cookie-manager' ), 'success'
            );
        }

        // Update cookie classification
        if ( isset( $_POST['dbcm_update_cookie'] ) && check_admin_referer( 'dbcm_update_action' ) ) {
            global $wpdb;
            $table = DBCM_Scanner::table_name();
            
            $id          = absint( $_POST['cookie_id'] );
            $category    = sanitize_text_field( $_POST['cookie_category'] );
            $description = sanitize_textarea_field( $_POST['cookie_description'] );
            $provider    = sanitize_text_field( $_POST['cookie_provider'] );

            $wpdb->update( $table, 
                array( 'category' => $category, 'description' => $description, 'provider' => $provider ),
                array( 'id' => $id ),
                array( '%s', '%s', '%s' ),
                array( '%d' )
            );

            add_settings_error( 'dbcm_messages', 'dbcm_updated', 
                __( 'Cookie aggiornato.', 'db-cookie-manager' ), 'success' 
            );
        }
    }

    /**
     * Render admin page
     */
    public static function render_page() {
        $tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'scan';
        $last_scan = get_option( 'dbcm_last_scan', '' );
        $urls_count = get_option( 'dbcm_scan_urls_count', 0 );
        ?>
        <div class="wrap">
            <h1>
                <span class="dashicons dashicons-shield" style="font-size:1.3em;margin-right:5px;vertical-align:middle;"></span>
                <?php _e( 'DB Cookie Manager', 'db-cookie-manager' ); ?>
            </h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=db-cookie-manager&tab=scan" class="nav-tab <?php echo $tab === 'scan' ? 'nav-tab-active' : ''; ?>">
                    <?php _e( 'Scansione', 'db-cookie-manager' ); ?>
                </a>
                <a href="?page=db-cookie-manager&tab=results" class="nav-tab <?php echo $tab === 'results' ? 'nav-tab-active' : ''; ?>">
                    <?php _e( 'Risultati', 'db-cookie-manager' ); ?>
                </a>
                <a href="?page=db-cookie-manager&tab=policy" class="nav-tab <?php echo $tab === 'policy' ? 'nav-tab-active' : ''; ?>">
                    <?php _e( 'Genera Policy', 'db-cookie-manager' ); ?>
                </a>
                <a href="?page=db-cookie-manager&tab=settings" class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <?php _e( 'Impostazioni', 'db-cookie-manager' ); ?>
                </a>
                <a href="?page=db-cookie-manager&tab=registro" class="nav-tab <?php echo $tab === 'registro' ? 'nav-tab-active' : ''; ?>">
                    <?php _e( 'Registro', 'db-cookie-manager' ); ?>
                </a>
            </nav>

            <div style="margin-top:20px;">
                <?php settings_errors( 'dbcm_messages' ); ?>

                <?php
                switch ( $tab ) {
                    case 'results':
                        self::render_results();
                        break;
                    case 'policy':
                        self::render_policy();
                        break;
                    case 'settings':
                        DBCM_Settings::render();
                        break;
                    case 'registro':
                        DBCM_Consent_Log::render();
                        break;
                    default:
                        self::render_scan( $last_scan, $urls_count );
                        break;
                }
                ?>
            </div>
        </div>

        <style>
            .scs-card { background:#fff; border:1px solid #c3c4c7; border-radius:8px; padding:24px; margin-bottom:20px; }
            .scs-card h2 { margin-top:0; }
            .scs-badge { display:inline-block; padding:3px 10px; border-radius:12px; font-size:12px; font-weight:600; color:#fff; }
            .scs-stats { display:flex; gap:20px; margin-bottom:20px; flex-wrap:wrap; }
            .scs-stat { background:#fff; border:1px solid #c3c4c7; border-radius:8px; padding:16px 24px; text-align:center; min-width:120px; }
            .scs-stat__number { font-size:2em; font-weight:700; color:#1d2327; }
            .scs-stat__label { color:#646970; font-size:13px; margin-top:4px; }
            .scs-table { width:100%; border-collapse:collapse; }
            .scs-table th { background:#f0f0f1; padding:10px 12px; text-align:left; font-weight:600; border-bottom:1px solid #c3c4c7; }
            .scs-table td { padding:10px 12px; border-bottom:1px solid #f0f0f1; vertical-align:top; }
            .scs-table tr:hover td { background:#f6f7f7; }
            .scs-table code { background:#f0f0f1; padding:2px 6px; border-radius:3px; font-size:12px; }
            .scs-policy-output { background:#fff; border:1px solid #c3c4c7; padding:24px; border-radius:8px; max-height:500px; overflow-y:auto; }
            .scs-policy-output h2 { font-size:1.5em; }
            .scs-policy-output h3 { font-size:1.2em; margin-top:1.5em; }
            .scs-policy-output h4 { font-size:1em; margin-top:1.2em; }
            .scs-policy-output table { width:100%; margin:1em 0; }
            .scs-edit-form { display:none; background:#f6f7f7; padding:12px; margin-top:8px; border-radius:4px; }
            .scs-edit-form.is-open { display:block; }
        </style>
        <?php
    }

    /**
     * Render Scan tab
     */
    private static function render_scan( $last_scan, $urls_count ) {
        $results = DBCM_Scanner::get_results();
        $grouped = DBCM_Scanner::get_results_grouped();
        ?>
        <div class="scs-card">
            <h2><?php _e( 'Scansiona il tuo sito', 'db-cookie-manager' ); ?></h2>
            <p><?php _e( 'Il plugin scansiona le pagine del sito una alla volta, rilevando cookie da HTTP header e script di tracking.', 'db-cookie-manager' ); ?></p>

            <?php if ( $last_scan ) : ?>
                <p>
                    <strong><?php _e( 'Ultima scansione:', 'db-cookie-manager' ); ?></strong> 
                    <?php echo esc_html( $last_scan ); ?> 
                    — <?php printf( __( '%d pagine scansionate', 'db-cookie-manager' ), $urls_count ); ?>
                </p>
            <?php endif; ?>

            <p>
                <button type="button" class="button button-primary button-hero" id="scs-start-scan">
                    <span class="dashicons dashicons-search" style="margin-top:4px;margin-right:4px;"></span>
                    <?php _e( 'Avvia scansione', 'db-cookie-manager' ); ?>
                </button>
            </p>

            <div id="scs-progress-wrap" style="display:none;margin-top:16px;">
                <div style="background:#f0f0f1;border-radius:4px;overflow:hidden;height:24px;position:relative;">
                    <div id="scs-progress-bar" style="background:#2563eb;height:100%;width:0%;transition:width .3s ease;border-radius:4px;"></div>
                    <span id="scs-progress-text" style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:600;color:#1d2327;"></span>
                </div>
                <p id="scs-progress-url" style="font-size:12px;color:#646970;margin-top:6px;"></p>
            </div>

            <div id="scs-scan-result" style="display:none;margin-top:16px;">
                <div class="notice notice-success inline">
                    <p id="scs-result-text"></p>
                </div>
            </div>
        </div>

        <?php if ( ! empty( $results ) ) : ?>
        <div class="scs-stats">
            <div class="scs-stat">
                <div class="scs-stat__number"><?php echo count( $results ); ?></div>
                <div class="scs-stat__label"><?php _e( 'Cookie totali', 'db-cookie-manager' ); ?></div>
            </div>
            <?php foreach ( $grouped as $cat => $cookies ) : ?>
            <div class="scs-stat">
                <div class="scs-stat__number" style="color:<?php echo esc_attr( DBCM_Cookie_Database::get_category_color( $cat ) ); ?>">
                    <?php echo count( $cookies ); ?>
                </div>
                <div class="scs-stat__label"><?php echo esc_html( DBCM_Cookie_Database::get_category_label( $cat ) ); ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <script>
        (function() {
            var btn = document.getElementById('scs-start-scan');
            var progressWrap = document.getElementById('scs-progress-wrap');
            var progressBar = document.getElementById('scs-progress-bar');
            var progressText = document.getElementById('scs-progress-text');
            var progressUrl = document.getElementById('scs-progress-url');
            var resultWrap = document.getElementById('scs-scan-result');
            var resultText = document.getElementById('scs-result-text');

            btn.addEventListener('click', function() {
                btn.disabled = true;
                btn.textContent = 'Preparazione...';
                progressWrap.style.display = 'block';
                resultWrap.style.display = 'none';

                // Step 1: Prepare
                fetch(dbcmAjax.ajaxurl, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: 'action=dbcm_prepare_scan&nonce=' + dbcmAjax.nonce
                })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data.success) throw new Error('Prepare failed');
                    var urls = data.data.urls;
                    var total = urls.length;
                    var current = 0;

                    btn.textContent = 'Scansione in corso...';

                    // Step 2: Scan URLs one by one
                    function scanNext() {
                        if (current >= total) {
                            // Step 3: Finalize
                            progressText.textContent = 'Finalizzazione...';
                            fetch(dbcmAjax.ajaxurl, {
                                method: 'POST',
                                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                                body: 'action=dbcm_finalize_scan&nonce=' + dbcmAjax.nonce
                            })
                            .then(function(r) { return r.json(); })
                            .then(function(fdata) {
                                progressBar.style.width = '100%';
                                progressText.textContent = '100%';
                                progressUrl.textContent = '';
                                resultWrap.style.display = 'block';
                                resultText.textContent = 'Scansione completata! Trovati ' + fdata.data.total_cookies + ' cookie.';
                                btn.textContent = 'Avvia scansione';
                                btn.disabled = false;
                                setTimeout(function() { location.reload(); }, 1500);
                            });
                            return;
                        }

                        var url = urls[current];
                        var pct = Math.round(((current + 1) / total) * 100);
                        progressBar.style.width = pct + '%';
                        progressText.textContent = (current + 1) + ' / ' + total;
                        progressUrl.textContent = url;

                        fetch(dbcmAjax.ajaxurl, {
                            method: 'POST',
                            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                            body: 'action=dbcm_scan_url&nonce=' + dbcmAjax.nonce + '&url=' + encodeURIComponent(url)
                        })
                        .then(function(r) { return r.json(); })
                        .then(function() {
                            current++;
                            scanNext();
                        })
                        .catch(function() {
                            // Skip failed URL, continue
                            current++;
                            scanNext();
                        });
                    }

                    scanNext();
                })
                .catch(function(err) {
                    alert('Errore: ' + err.message);
                    btn.textContent = 'Avvia scansione';
                    btn.disabled = false;
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * Render Results tab
     */
    private static function render_results() {
        $last_scan = get_option( 'dbcm_last_scan', '' );
        $grouped = DBCM_Scanner::get_results_grouped();
        $google_fonts = get_option( 'dbcm_google_fonts_detected', false );

        if ( empty( $last_scan ) ) {
            echo '<div class="scs-card"><p>' . __( 'Nessuna scansione eseguita. Vai alla tab Scansione per avviarne una.', 'db-cookie-manager' ) . '</p></div>';
            return;
        }

        if ( empty( $grouped ) && ! $google_fonts ) {
            echo '<div class="scs-card">';
            echo '<h2>' . __( 'Nessun cookie rilevato', 'db-cookie-manager' ) . '</h2>';
            echo '<p>' . sprintf( __( 'La scansione del %s non ha trovato cookie. Questo è normale se il sito utilizza solo cookie tecnici inviati agli utenti autenticati (la scansione avviene come visitatore anonimo).', 'db-cookie-manager' ), esc_html( $last_scan ) ) . '</p>';
            echo '<p><strong>' . __( 'Il tuo banner cookie imposta:', 'db-cookie-manager' ) . '</strong></p>';
            echo '<table class="scs-table"><thead><tr><th>Cookie</th><th>Fornitore</th><th>Finalità</th><th>Durata</th></tr></thead><tbody>';
            echo '<tr><td><code>dbcm_consent</code></td><td>DB Cookie Manager</td><td>Memorizza la scelta dell\'utente sui cookie</td><td>365 giorni</td></tr>';
            echo '</tbody></table>';
            echo '</div>';
            
            if ( $google_fonts ) {
                echo '<div class="scs-card">';
                echo '<h3>' . __( 'Servizi esterni rilevati', 'db-cookie-manager' ) . '</h3>';
                echo '<p><strong>Google Fonts</strong> — Il sito carica font da Google Fonts CDN. Non imposta cookie ma trasmette l\'indirizzo IP a Google.</p>';
                echo '</div>';
            }
            return;
        }

        foreach ( $grouped as $category => $cookies ) :
            $color = DBCM_Cookie_Database::get_category_color( $category );
            $label = DBCM_Cookie_Database::get_category_label( $category );
        ?>
        <div class="scs-card">
            <h2>
                <span class="scs-badge" style="background:<?php echo esc_attr( $color ); ?>;">
                    <?php echo esc_html( $label ); ?>
                </span>
                <span style="font-size:14px;color:#646970;font-weight:400;margin-left:8px;">
                    <?php printf( __( '(%d cookie)', 'db-cookie-manager' ), count( $cookies ) ); ?>
                </span>
            </h2>

            <table class="scs-table">
                <thead>
                    <tr>
                        <th><?php _e( 'Nome', 'db-cookie-manager' ); ?></th>
                        <th><?php _e( 'Fornitore', 'db-cookie-manager' ); ?></th>
                        <th><?php _e( 'Descrizione', 'db-cookie-manager' ); ?></th>
                        <th><?php _e( 'Durata', 'db-cookie-manager' ); ?></th>
                        <th><?php _e( 'Dominio', 'db-cookie-manager' ); ?></th>
                        <th><?php _e( 'Azioni', 'db-cookie-manager' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $cookies as $cookie ) : ?>
                    <tr>
                        <td><code><?php echo esc_html( $cookie->cookie_name ); ?></code></td>
                        <td><?php echo esc_html( $cookie->provider ); ?></td>
                        <td><?php echo esc_html( $cookie->description ); ?></td>
                        <td><?php echo esc_html( $cookie->cookie_duration ); ?></td>
                        <td><small><?php echo esc_html( $cookie->cookie_domain ); ?></small></td>
                        <td>
                            <button type="button" class="button button-small scs-edit-toggle" data-id="<?php echo esc_attr( $cookie->id ); ?>">
                                <?php _e( 'Modifica', 'db-cookie-manager' ); ?>
                            </button>
                        </td>
                    </tr>
                    <tr>
                        <td colspan="6" style="padding:0;">
                            <div class="scs-edit-form" id="scs-edit-<?php echo esc_attr( $cookie->id ); ?>">
                                <form method="post" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
                                    <?php wp_nonce_field( 'dbcm_update_action' ); ?>
                                    <input type="hidden" name="cookie_id" value="<?php echo esc_attr( $cookie->id ); ?>">
                                    
                                    <label style="flex:0 0 150px;">
                                        <small><?php _e( 'Categoria', 'db-cookie-manager' ); ?></small><br>
                                        <select name="cookie_category" style="width:100%;">
                                            <option value="tecnico" <?php selected( $cookie->category, 'tecnico' ); ?>><?php _e( 'Tecnico', 'db-cookie-manager' ); ?></option>
                                            <option value="prestazioni" <?php selected( $cookie->category, 'prestazioni' ); ?>><?php _e( 'Prestazioni', 'db-cookie-manager' ); ?></option>
                                            <option value="analitica" <?php selected( $cookie->category, 'analitica' ); ?>><?php _e( 'Analitica', 'db-cookie-manager' ); ?></option>
                                            <option value="marketing" <?php selected( $cookie->category, 'marketing' ); ?>><?php _e( 'Marketing', 'db-cookie-manager' ); ?></option>
                                            <option value="sconosciuto" <?php selected( $cookie->category, 'sconosciuto' ); ?>><?php _e( 'Non classificato', 'db-cookie-manager' ); ?></option>
                                        </select>
                                    </label>
                                    
                                    <label style="flex:0 0 150px;">
                                        <small><?php _e( 'Fornitore', 'db-cookie-manager' ); ?></small><br>
                                        <input type="text" name="cookie_provider" value="<?php echo esc_attr( $cookie->provider ); ?>" style="width:100%;">
                                    </label>
                                    
                                    <label style="flex:1;min-width:200px;">
                                        <small><?php _e( 'Descrizione', 'db-cookie-manager' ); ?></small><br>
                                        <input type="text" name="cookie_description" value="<?php echo esc_attr( $cookie->description ); ?>" style="width:100%;">
                                    </label>
                                    
                                    <button type="submit" name="dbcm_update_cookie" class="button button-primary">
                                        <?php _e( 'Salva', 'db-cookie-manager' ); ?>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endforeach; ?>

        <?php if ( get_option( 'dbcm_google_fonts_detected', false ) ) : ?>
        <div class="scs-card">
            <h2>
                <span class="scs-badge" style="background:#f59e0b;">Servizi esterni</span>
            </h2>
            <table class="scs-table">
                <thead>
                    <tr>
                        <th><?php _e( 'Servizio', 'db-cookie-manager' ); ?></th>
                        <th><?php _e( 'Tipo', 'db-cookie-manager' ); ?></th>
                        <th><?php _e( 'Descrizione', 'db-cookie-manager' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><strong>Google Fonts</strong></td>
                        <td>CDN esterno (no cookie)</td>
                        <td>Carica font da Google. Trasmette indirizzo IP a Google. Non imposta cookie ma va dichiarato nella Cookie Policy.</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <script>
        document.querySelectorAll('.scs-edit-toggle').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var id = this.dataset.id;
                var form = document.getElementById('scs-edit-' + id);
                form.classList.toggle('is-open');
            });
        });
        </script>
        <?php
    }

    /**
     * Render Policy tab
     */
    private static function render_policy() {
        $last_scan = get_option( 'dbcm_last_scan', '' );
        $results = DBCM_Scanner::get_results();

        if ( empty( $last_scan ) ) {
            echo '<div class="scs-card"><p>' . __( 'Nessuna scansione eseguita. Vai alla tab Scansione per avviarne una.', 'db-cookie-manager' ) . '</p></div>';
            return;
        }

        $policy_html = DBCM_Policy_Generator::generate();
        ?>
        <div class="scs-card">
            <h2><?php _e( 'Cookie Policy generata', 'db-cookie-manager' ); ?></h2>
            <p><?php _e( 'Testo generato automaticamente in base ai cookie rilevati. Copia il contenuto e incollalo nella tua pagina Cookie Policy.', 'db-cookie-manager' ); ?></p>
            
            <p>
                <button type="button" class="button button-primary" id="scs-copy-policy">
                    <span class="dashicons dashicons-clipboard" style="margin-top:4px;margin-right:4px;"></span>
                    <?php _e( 'Copia HTML', 'db-cookie-manager' ); ?>
                </button>
                <span id="scs-copy-feedback" style="display:none;color:#00a32a;margin-left:10px;">
                    ✓ <?php _e( 'Copiato!', 'db-cookie-manager' ); ?>
                </span>
            </p>
        </div>

        <div class="scs-card">
            <h3><?php _e( 'Anteprima', 'db-cookie-manager' ); ?></h3>
            <div class="scs-policy-output" id="scs-policy-content">
                <?php echo $policy_html; ?>
            </div>
        </div>

        <textarea id="scs-policy-raw" style="display:none;"><?php echo esc_textarea( $policy_html ); ?></textarea>

        <script>
        document.getElementById('scs-copy-policy').addEventListener('click', function() {
            var raw = document.getElementById('scs-policy-raw');
            raw.style.display = 'block';
            raw.select();
            document.execCommand('copy');
            raw.style.display = 'none';
            
            var feedback = document.getElementById('scs-copy-feedback');
            feedback.style.display = 'inline';
            setTimeout(function() { feedback.style.display = 'none'; }, 2000);
        });
        </script>

        <div class="scs-card">
            <h3><?php _e( 'Nota importante', 'db-cookie-manager' ); ?></h3>
            <p><?php _e( 'Questo testo è generato automaticamente e NON costituisce consulenza legale. Verificare sempre con un professionista prima della pubblicazione. Ricorda di:', 'db-cookie-manager' ); ?></p>
            <ul>
                <li><?php _e( 'Sostituire [NOME COMPLETO] con i tuoi dati', 'db-cookie-manager' ); ?></li>
                <li><?php _e( 'Verificare che tutti i cookie rilevati siano correttamente classificati', 'db-cookie-manager' ); ?></li>
                <li><?php _e( 'Ripetere la scansione periodicamente o dopo modifiche al sito', 'db-cookie-manager' ); ?></li>
            </ul>
        </div>
        <?php
    }
}
