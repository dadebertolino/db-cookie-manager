<?php
/**
 * Plugin Settings
 *
 * @package db-cookie-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DBCM_Settings {

    /**
     * Default settings
     */
    public static function get_defaults() {
        return array(

            // === ASPETTO ===
            'banner_layout'       => 'bar',        // bar | card | fullscreen
            'banner_position'     => 'bottom',     // bottom | top
            'banner_theme'        => 'dark',       // dark | light
            'banner_color_bg'     => '#1e293b',
            'banner_color_text'   => '#f8fafc',
            'banner_color_btn'    => '#2563eb',
            'banner_color_btn_text' => '#ffffff',
            'banner_overlay'      => true,         // sfondo scuro dietro il banner
            'banner_credits'      => true,         // mostra "Powered by" nel banner
            'banner_custom_css'   => '',

            // === COMPORTAMENTO ===
            'banner_enabled'      => true,
            'consent_duration'    => 365,          // giorni
            'reconsent_on_change' => false,        // richiedi nuovo consenso se cambiano i cookie
            'show_reopen_btn'     => true,         // icona riapertura preferenze
            'reopen_position'     => 'bottom-left', // bottom-left | bottom-right
            'block_scripts'       => true,         // blocco preventivo attivo
            'auto_block'          => true,         // blocco automatico script noti
            'default_analytics'   => false,        // toggle analitici attivo di default
            'default_performance' => false,        // toggle prestazioni attivo di default
            'default_marketing'   => false,        // toggle marketing attivo di default

            // === TESTI (defaults per la lingua primaria IT) ===
            'banner_languages'    => array( 'it', 'en' ), // lingue attive
            'banner_default_lang' => 'it',                // lingua fallback

            // === COOKIE POLICY ===
            'policy_page_id'      => 0,

            // === REGISTRO CONSENSI ===
            'consent_log_enabled' => true,
            'consent_log_retention' => 12,
        );
    }

    /**
     * Available languages
     */
    public static function get_available_languages() {
        return array(
            'it' => 'Italiano',
            'en' => 'English',
            'fr' => 'Français',
            'de' => 'Deutsch',
            'es' => 'Español',
            'pt' => 'Português',
        );
    }

    /**
     * Get active languages
     */
    public static function get_active_languages() {
        $active = get_option( 'dbcm_banner_languages', array( 'it', 'en' ) );
        if ( ! is_array( $active ) ) {
            $active = array( 'it', 'en' );
        }
        return $active;
    }

    /**
     * Text keys with defaults per language
     */
    public static function get_text_defaults() {
        return array(
            'text_title' => array(
                'it' => 'Questo sito utilizza i cookie',
                'en' => 'This website uses cookies',
                'fr' => 'Ce site utilise des cookies',
                'de' => 'Diese Website verwendet Cookies',
                'es' => 'Este sitio web utiliza cookies',
                'pt' => 'Este site utiliza cookies',
            ),
            'text_description' => array(
                'it' => 'Utilizziamo cookie tecnici necessari al funzionamento del sito e, con il tuo consenso, cookie di analisi e marketing per offrirti un\'esperienza migliore.',
                'en' => 'We use technical cookies necessary for the website to function and, with your consent, analytics and marketing cookies to offer you a better experience.',
                'fr' => 'Nous utilisons des cookies techniques nécessaires au fonctionnement du site et, avec votre consentement, des cookies d\'analyse et de marketing.',
                'de' => 'Wir verwenden technische Cookies, die für den Betrieb der Website erforderlich sind, und mit Ihrer Zustimmung Analyse- und Marketing-Cookies.',
                'es' => 'Utilizamos cookies técnicas necesarias para el funcionamiento del sitio y, con su consentimiento, cookies de análisis y marketing.',
                'pt' => 'Utilizamos cookies técnicos necessários ao funcionamento do site e, com o seu consentimento, cookies de análise e marketing.',
            ),
            'text_accept_all' => array(
                'it' => 'Accetta tutto',
                'en' => 'Accept all',
                'fr' => 'Tout accepter',
                'de' => 'Alle akzeptieren',
                'es' => 'Aceptar todo',
                'pt' => 'Aceitar tudo',
            ),
            'text_reject_all' => array(
                'it' => 'Solo necessari',
                'en' => 'Necessary only',
                'fr' => 'Nécessaires uniquement',
                'de' => 'Nur notwendige',
                'es' => 'Solo necesarias',
                'pt' => 'Apenas necessários',
            ),
            'text_customize' => array(
                'it' => 'Personalizza',
                'en' => 'Customize',
                'fr' => 'Personnaliser',
                'de' => 'Anpassen',
                'es' => 'Personalizar',
                'pt' => 'Personalizar',
            ),
            'text_save' => array(
                'it' => 'Salva preferenze',
                'en' => 'Save preferences',
                'fr' => 'Enregistrer',
                'de' => 'Einstellungen speichern',
                'es' => 'Guardar preferencias',
                'pt' => 'Guardar preferências',
            ),
            'text_policy_link' => array(
                'it' => 'Cookie Policy',
                'en' => 'Cookie Policy',
                'fr' => 'Politique de cookies',
                'de' => 'Cookie-Richtlinie',
                'es' => 'Política de cookies',
                'pt' => 'Política de cookies',
            ),
            'text_close' => array(
                'it' => 'Chiudi',
                'en' => 'Close',
                'fr' => 'Fermer',
                'de' => 'Schließen',
                'es' => 'Cerrar',
                'pt' => 'Fechar',
            ),
            'text_cat_necessary' => array(
                'it' => 'Necessari',
                'en' => 'Necessary',
                'fr' => 'Nécessaires',
                'de' => 'Notwendig',
                'es' => 'Necesarias',
                'pt' => 'Necessários',
            ),
            'text_cat_necessary_desc' => array(
                'it' => 'Cookie essenziali per il funzionamento del sito. Non possono essere disattivati.',
                'en' => 'Essential cookies for the website to function. They cannot be disabled.',
                'fr' => 'Cookies essentiels au fonctionnement du site. Ils ne peuvent pas être désactivés.',
                'de' => 'Wesentliche Cookies für den Betrieb der Website. Sie können nicht deaktiviert werden.',
                'es' => 'Cookies esenciales para el funcionamiento del sitio. No se pueden desactivar.',
                'pt' => 'Cookies essenciais para o funcionamento do site. Não podem ser desativados.',
            ),
            'text_cat_performance' => array(
                'it' => 'Prestazioni',
                'en' => 'Performance',
                'fr' => 'Performance',
                'de' => 'Leistung',
                'es' => 'Rendimiento',
                'pt' => 'Desempenho',
            ),
            'text_cat_performance_desc' => array(
                'it' => 'Cookie utilizzati per migliorare le prestazioni del sito, come CDN, caching e bilanciamento del carico.',
                'en' => 'Cookies used to improve website performance, such as CDN, caching and load balancing.',
                'fr' => 'Cookies utilisés pour améliorer les performances du site, comme le CDN et la mise en cache.',
                'de' => 'Cookies zur Verbesserung der Website-Leistung, wie CDN, Caching und Lastverteilung.',
                'es' => 'Cookies utilizadas para mejorar el rendimiento del sitio, como CDN, caché y balanceo de carga.',
                'pt' => 'Cookies utilizados para melhorar o desempenho do site, como CDN, cache e balanceamento de carga.',
            ),
            'text_cat_analytics' => array(
                'it' => 'Analitici',
                'en' => 'Analytics',
                'fr' => 'Analytiques',
                'de' => 'Analytisch',
                'es' => 'Analíticas',
                'pt' => 'Analíticos',
            ),
            'text_cat_analytics_desc' => array(
                'it' => 'Cookie che ci permettono di misurare il traffico e analizzare il comportamento degli utenti per migliorare il servizio.',
                'en' => 'Cookies that allow us to measure traffic and analyse user behaviour to improve the service.',
                'fr' => 'Cookies qui nous permettent de mesurer le trafic et d\'analyser le comportement des utilisateurs.',
                'de' => 'Cookies, die es uns ermöglichen, den Datenverkehr zu messen und das Nutzerverhalten zu analysieren.',
                'es' => 'Cookies que nos permiten medir el tráfico y analizar el comportamiento de los usuarios.',
                'pt' => 'Cookies que nos permitem medir o tráfego e analisar o comportamento dos utilizadores.',
            ),
            'text_cat_marketing' => array(
                'it' => 'Marketing',
                'en' => 'Marketing',
                'fr' => 'Marketing',
                'de' => 'Marketing',
                'es' => 'Marketing',
                'pt' => 'Marketing',
            ),
            'text_cat_marketing_desc' => array(
                'it' => 'Cookie utilizzati per mostrare pubblicità pertinente ai tuoi interessi, anche su altri siti.',
                'en' => 'Cookies used to show you relevant ads based on your interests, including on other websites.',
                'fr' => 'Cookies utilisés pour vous montrer des publicités pertinentes, y compris sur d\'autres sites.',
                'de' => 'Cookies, die verwendet werden, um Ihnen relevante Werbung anzuzeigen, auch auf anderen Websites.',
                'es' => 'Cookies utilizadas para mostrarte publicidad relevante, incluso en otros sitios web.',
                'pt' => 'Cookies utilizados para mostrar publicidade relevante, incluindo em outros sites.',
            ),
            'text_always_on' => array(
                'it' => 'Sempre attivi',
                'en' => 'Always active',
                'fr' => 'Toujours actifs',
                'de' => 'Immer aktiv',
                'es' => 'Siempre activas',
                'pt' => 'Sempre ativos',
            ),
        );
    }

    /**
     * Get text for a specific key and language
     */
    public static function get_text( $key, $lang = null ) {
        if ( ! $lang ) {
            $lang = get_option( 'dbcm_banner_default_lang', 'it' );
        }
        $saved = get_option( 'dbcm_' . $key . '_' . $lang );
        if ( $saved !== false && $saved !== '' ) {
            return $saved;
        }
        // Fallback to defaults
        $defaults = self::get_text_defaults();
        if ( isset( $defaults[ $key ][ $lang ] ) ) {
            return $defaults[ $key ][ $lang ];
        }
        // Fallback to IT
        if ( isset( $defaults[ $key ]['it'] ) ) {
            return $defaults[ $key ]['it'];
        }
        return '';
    }

    /**
     * Get all texts for all active languages (for JS)
     */
    public static function get_all_texts_for_js() {
        $langs = self::get_active_languages();
        $text_keys = array_keys( self::get_text_defaults() );
        $result = array();

        foreach ( $langs as $lang ) {
            $result[ $lang ] = array();
            foreach ( $text_keys as $key ) {
                // Convert text_title to textTitle for JS
                $js_key = lcfirst( str_replace( '_', '', ucwords( $key, '_' ) ) );
                $result[ $lang ][ $js_key ] = self::get_text( $key, $lang );
            }
        }

        return $result;
    }

    /**
     * Get a single setting
     */
    public static function get( $key ) {
        $defaults = self::get_defaults();
        $value = get_option( 'dbcm_' . $key );

        if ( $value === false && isset( $defaults[ $key ] ) ) {
            return $defaults[ $key ];
        }

        // Cast booleans
        if ( isset( $defaults[ $key ] ) && is_bool( $defaults[ $key ] ) ) {
            return (bool) $value;
        }

        // Cast integers
        if ( isset( $defaults[ $key ] ) && is_int( $defaults[ $key ] ) ) {
            return (int) $value;
        }

        return $value;
    }

    /**
     * Get all settings as array
     */
    public static function get_all() {
        $defaults = self::get_defaults();
        $settings = array();

        foreach ( $defaults as $key => $default ) {
            $settings[ $key ] = self::get( $key );
        }

        return $settings;
    }

    /**
     * Save settings from POST data
     */
    public static function save( $data ) {
        $defaults = self::get_defaults();
        $section = isset( $data['dbcm_section'] ) ? sanitize_text_field( $data['dbcm_section'] ) : '';

        // Map checkboxes to their sections
        $section_checkboxes = array(
            'aspetto'       => array( 'banner_overlay', 'banner_credits' ),
            'comportamento' => array( 'banner_enabled', 'reconsent_on_change', 'show_reopen_btn', 'block_scripts', 'auto_block', 'default_analytics', 'default_performance', 'default_marketing' ),
            'registro'      => array( 'consent_log_enabled' ),
        );

        // Which checkboxes are on this page?
        $active_checkboxes = isset( $section_checkboxes[ $section ] ) ? $section_checkboxes[ $section ] : array();

        // Save active languages (only from testi section)
        if ( $section === 'testi' && isset( $data['dbcm_banner_languages'] ) && is_array( $data['dbcm_banner_languages'] ) ) {
            $langs = array_map( 'sanitize_text_field', $data['dbcm_banner_languages'] );
            update_option( 'dbcm_banner_languages', $langs );
            if ( ! empty( $langs ) ) {
                update_option( 'dbcm_banner_default_lang', $langs[0] );
            }
        }

        // Save text fields for the current language (only from testi section)
        if ( $section === 'testi' && isset( $data['dbcm_current_lang'] ) ) {
            $lang = sanitize_text_field( $data['dbcm_current_lang'] );
            $text_keys = array_keys( self::get_text_defaults() );

            foreach ( $text_keys as $key ) {
                if ( isset( $data[ 'dbcm_' . $key ] ) ) {
                    $value = sanitize_textarea_field( $data[ 'dbcm_' . $key ] );
                    update_option( 'dbcm_' . $key . '_' . $lang, $value );
                }
            }
        }

        // Save non-text settings
        foreach ( $defaults as $key => $default ) {
            // Skip text and language keys
            if ( strpos( $key, 'text_' ) === 0 || $key === 'banner_languages' || $key === 'banner_default_lang' ) {
                continue;
            }

            if ( in_array( $key, $active_checkboxes, true ) ) {
                // Checkbox on this section: unchecked = 0
                $value = isset( $data[ 'dbcm_' . $key ] ) ? 1 : 0;
            } elseif ( isset( $data[ 'dbcm_' . $key ] ) ) {
                $value = $data[ 'dbcm_' . $key ];
            } else {
                continue; // Not in POST and not a checkbox on this section — skip
            }

            // Sanitize
            if ( is_bool( $default ) ) {
                $value = (bool) $value;
            } elseif ( is_int( $default ) ) {
                $value = absint( $value );
            } elseif ( $key === 'banner_custom_css' ) {
                $value = wp_strip_all_tags( $value );
            } elseif ( strpos( $key, 'color' ) !== false ) {
                $value = sanitize_hex_color( $value ) ?: $default;
            } else {
                $value = sanitize_text_field( $value );
            }

            update_option( 'dbcm_' . $key, $value );
        }
    }

    /**
     * Render settings page
     */
    public static function render() {
        $s = self::get_all();
        $section = isset( $_GET['section'] ) ? sanitize_text_field( $_GET['section'] ) : 'aspetto';

        // Get pages for Cookie Policy dropdown
        $pages = get_pages( array( 'sort_column' => 'post_title', 'sort_order' => 'ASC' ) );
        ?>

        <!-- Sub-navigation -->
        <div class="scs-subnav">
            <?php
            $sections = array(
                'aspetto'       => __( 'Aspetto', 'db-cookie-manager' ),
                'comportamento' => __( 'Comportamento', 'db-cookie-manager' ),
                'testi'         => __( 'Testi', 'db-cookie-manager' ),
                'policy'        => __( 'Cookie Policy', 'db-cookie-manager' ),
                'registro'      => __( 'Registro consensi', 'db-cookie-manager' ),
            );
            foreach ( $sections as $sec_key => $sec_label ) :
            ?>
                <a href="?page=db-cookie-manager&tab=settings&section=<?php echo esc_attr( $sec_key ); ?>"
                   class="scs-subnav__item <?php echo $section === $sec_key ? 'is-active' : ''; ?>">
                    <?php echo esc_html( $sec_label ); ?>
                </a>
            <?php endforeach; ?>
        </div>

        <form method="post">
            <?php wp_nonce_field( 'dbcm_save_settings' ); ?>
            <input type="hidden" name="dbcm_section" value="<?php echo esc_attr( $section ); ?>">

            <?php
            switch ( $section ) {
                case 'comportamento':
                    self::render_section_behavior( $s );
                    break;
                case 'testi':
                    self::render_section_texts( $s );
                    break;
                case 'policy':
                    self::render_section_policy( $s, $pages );
                    break;
                case 'registro':
                    self::render_section_log( $s );
                    break;
                default:
                    self::render_section_appearance( $s );
                    break;
            }
            ?>

            <p class="submit">
                <button type="submit" name="dbcm_save_settings_btn" class="button button-primary">
                    <?php _e( 'Salva impostazioni', 'db-cookie-manager' ); ?>
                </button>
            </p>
        </form>

        <style>
            .scs-subnav { display:flex; gap:0; margin-bottom:20px; border-bottom:1px solid #c3c4c7; }
            .scs-subnav__item { padding:10px 16px; text-decoration:none; color:#646970; font-weight:500; border-bottom:2px solid transparent; margin-bottom:-1px; }
            .scs-subnav__item:hover { color:#1d2327; }
            .scs-subnav__item.is-active { color:#1d2327; border-bottom-color:#2563eb; }
            .scs-field { margin-bottom:20px; }
            .scs-field label { display:block; font-weight:600; margin-bottom:6px; color:#1d2327; }
            .scs-field .description { font-size:12px; color:#646970; margin-top:4px; }
            .scs-field input[type="text"],
            .scs-field input[type="number"],
            .scs-field textarea,
            .scs-field select { width:100%; max-width:500px; }
            .scs-field textarea { min-height:80px; }
            .scs-field-inline { display:flex; align-items:center; gap:8px; }
            .scs-color-row { display:flex; gap:20px; flex-wrap:wrap; }
            .scs-color-item { text-align:center; }
            .scs-color-item label { font-size:13px; }
            .scs-color-item input[type="color"] { width:60px; height:36px; border:1px solid #c3c4c7; border-radius:4px; cursor:pointer; padding:2px; }
            .scs-preview-box { background:#f0f0f1; border:1px solid #c3c4c7; border-radius:8px; padding:20px; margin-top:20px; }
        </style>
        <?php
    }

    // =========================================================================
    // ASPETTO
    // =========================================================================
    private static function render_section_appearance( $s ) {
        ?>
        <div class="scs-card">
            <h2><?php _e( 'Layout banner', 'db-cookie-manager' ); ?></h2>

            <div class="scs-field">
                <label><?php _e( 'Tipo di layout', 'db-cookie-manager' ); ?></label>
                <select name="dbcm_banner_layout">
                    <option value="bar" <?php selected( $s['banner_layout'], 'bar' ); ?>><?php _e( 'Barra (piena larghezza)', 'db-cookie-manager' ); ?></option>
                    <option value="card" <?php selected( $s['banner_layout'], 'card' ); ?>><?php _e( 'Card (floating)', 'db-cookie-manager' ); ?></option>
                    <option value="fullscreen" <?php selected( $s['banner_layout'], 'fullscreen' ); ?>><?php _e( 'Fullscreen (modale)', 'db-cookie-manager' ); ?></option>
                </select>
            </div>

            <div class="scs-field">
                <label><?php _e( 'Posizione', 'db-cookie-manager' ); ?></label>
                <select name="dbcm_banner_position">
                    <option value="bottom" <?php selected( $s['banner_position'], 'bottom' ); ?>><?php _e( 'In basso', 'db-cookie-manager' ); ?></option>
                    <option value="top" <?php selected( $s['banner_position'], 'top' ); ?>><?php _e( 'In alto', 'db-cookie-manager' ); ?></option>
                </select>
                <p class="description"><?php _e( 'Non si applica al layout Fullscreen.', 'db-cookie-manager' ); ?></p>
            </div>

            <div class="scs-field">
                <label>
                    <input type="checkbox" name="dbcm_banner_overlay" value="1" <?php checked( $s['banner_overlay'] ); ?>>
                    <?php _e( 'Sfondo scuro (overlay) dietro il banner', 'db-cookie-manager' ); ?>
                </label>
            </div>

            <div class="scs-field">
                <label>
                    <input type="checkbox" name="dbcm_banner_credits" value="1" <?php checked( $s['banner_credits'] ); ?>>
                    <?php _e( 'Mostra "Powered by DB Cookie Manager" nel banner', 'db-cookie-manager' ); ?>
                </label>
                <p class="description"><?php _e( 'Un piccolo link discreto in fondo al banner. Aiuta a far conoscere il progetto.', 'db-cookie-manager' ); ?></p>
            </div>
        </div>

        <div class="scs-card">
            <h2><?php _e( 'Tema', 'db-cookie-manager' ); ?></h2>
            <p class="description" style="margin-bottom:16px;"><?php _e( 'Scegli un tema di partenza. Puoi poi personalizzare i singoli colori sotto.', 'db-cookie-manager' ); ?></p>

            <div class="scs-field">
                <div style="display:flex;gap:12px;flex-wrap:wrap;">
                    <label class="scs-theme-option">
                        <input type="radio" name="dbcm_banner_theme" value="dark" <?php checked( $s['banner_theme'], 'dark' ); ?>
                            data-preset-bg="#1e293b" data-preset-text="#f8fafc" data-preset-btn="#2563eb" data-preset-btn-text="#ffffff">
                        <span class="scs-theme-swatch" style="background:#1e293b;color:#f8fafc;border-color:#334155;">
                            <strong>Scuro</strong>
                            <span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:#2563eb;vertical-align:middle;margin-left:6px;"></span>
                        </span>
                    </label>
                    <label class="scs-theme-option">
                        <input type="radio" name="dbcm_banner_theme" value="light" <?php checked( $s['banner_theme'], 'light' ); ?>
                            data-preset-bg="#ffffff" data-preset-text="#1e293b" data-preset-btn="#2563eb" data-preset-btn-text="#ffffff">
                        <span class="scs-theme-swatch" style="background:#ffffff;color:#1e293b;border-color:#d1d5db;">
                            <strong>Chiaro</strong>
                            <span style="display:inline-block;width:14px;height:14px;border-radius:3px;background:#2563eb;vertical-align:middle;margin-left:6px;"></span>
                        </span>
                    </label>
                </div>
            </div>
        </div>

        <div class="scs-card">
            <h2><?php _e( 'Colori personalizzati', 'db-cookie-manager' ); ?></h2>
            <p class="description" style="margin-bottom:16px;"><?php _e( 'Sovrascrivono il tema selezionato. Banner e pannello dettagli usano gli stessi colori.', 'db-cookie-manager' ); ?></p>

            <div class="scs-color-row">
                <div class="scs-color-item">
                    <label><?php _e( 'Sfondo', 'db-cookie-manager' ); ?></label><br>
                    <input type="color" name="dbcm_banner_color_bg" value="<?php echo esc_attr( $s['banner_color_bg'] ); ?>">
                </div>
                <div class="scs-color-item">
                    <label><?php _e( 'Testo', 'db-cookie-manager' ); ?></label><br>
                    <input type="color" name="dbcm_banner_color_text" value="<?php echo esc_attr( $s['banner_color_text'] ); ?>">
                </div>
                <div class="scs-color-item">
                    <label><?php _e( 'Bottone primario', 'db-cookie-manager' ); ?></label><br>
                    <input type="color" name="dbcm_banner_color_btn" value="<?php echo esc_attr( $s['banner_color_btn'] ); ?>">
                </div>
                <div class="scs-color-item">
                    <label><?php _e( 'Testo bottone', 'db-cookie-manager' ); ?></label><br>
                    <input type="color" name="dbcm_banner_color_btn_text" value="<?php echo esc_attr( $s['banner_color_btn_text'] ); ?>">
                </div>
            </div>

            <!-- Anteprima -->
            <div class="scs-preview-box" id="scs-preview">
                <p style="font-size:13px;color:#646970;margin:0 0 12px;"><?php _e( 'Anteprima:', 'db-cookie-manager' ); ?></p>
                <div id="scs-preview-banner" style="padding:20px 24px;border-radius:8px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;background:<?php echo esc_attr( $s['banner_color_bg'] ); ?>;color:<?php echo esc_attr( $s['banner_color_text'] ); ?>;border:1px solid rgba(128,128,128,0.2);">
                    <div>
                        <div style="font-size:15px;font-weight:600;margin-bottom:4px;"><?php echo esc_html( $s['text_title'] ); ?></div>
                        <div id="scs-preview-desc" style="font-size:13px;opacity:0.7;"><?php echo esc_html( mb_substr( $s['text_description'], 0, 60 ) ); ?>…</div>
                    </div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;">
                        <span id="scs-preview-btn-accept" style="padding:9px 18px;border-radius:6px;font-size:13px;font-weight:600;cursor:default;background:<?php echo esc_attr( $s['banner_color_btn'] ); ?>;color:<?php echo esc_attr( $s['banner_color_btn_text'] ); ?>;"><?php echo esc_html( $s['text_accept_all'] ); ?></span>
                        <span id="scs-preview-btn-reject" style="padding:9px 18px;border-radius:6px;font-size:13px;font-weight:600;cursor:default;border:1px solid rgba(128,128,128,0.3);background:transparent;"><?php echo esc_html( $s['text_reject_all'] ); ?></span>
                    </div>
                </div>
            </div>
        </div>

        <div class="scs-card">
            <h2><?php _e( 'CSS Personalizzato', 'db-cookie-manager' ); ?></h2>
            <div class="scs-field">
                <textarea name="dbcm_banner_custom_css" rows="6" style="font-family:monospace;font-size:13px;" placeholder="/* Il tuo CSS qui */"><?php echo esc_textarea( $s['banner_custom_css'] ); ?></textarea>
                <p class="description"><?php _e( 'CSS aggiuntivo applicato al banner cookie. Le classi usano il prefisso .dbcm-banner', 'db-cookie-manager' ); ?></p>
            </div>
        </div>

        <style>
            .scs-theme-option { cursor:pointer; display:inline-block; }
            .scs-theme-option input[type="radio"] { display:none; }
            .scs-theme-swatch { display:inline-flex; align-items:center; padding:10px 18px; border-radius:6px; border:2px solid; font-size:13px; transition:box-shadow 0.15s ease; }
            .scs-theme-option input:checked + .scs-theme-swatch { box-shadow:0 0 0 2px #2563eb; }
        </style>

        <script>
        (function() {
            var inputs = {
                bg: document.querySelector('[name="dbcm_banner_color_bg"]'),
                text: document.querySelector('[name="dbcm_banner_color_text"]'),
                btn: document.querySelector('[name="dbcm_banner_color_btn"]'),
                btnText: document.querySelector('[name="dbcm_banner_color_btn_text"]')
            };
            var banner = document.getElementById('scs-preview-banner');
            var btnAccept = document.getElementById('scs-preview-btn-accept');
            var btnReject = document.getElementById('scs-preview-btn-reject');

            function updatePreview() {
                banner.style.background = inputs.bg.value;
                banner.style.color = inputs.text.value;
                btnAccept.style.background = inputs.btn.value;
                btnAccept.style.color = inputs.btnText.value;
                btnReject.style.color = inputs.text.value;
            }

            Object.values(inputs).forEach(function(inp) {
                inp.addEventListener('input', updatePreview);
            });

            // Theme presets
            document.querySelectorAll('[name="dbcm_banner_theme"]').forEach(function(radio) {
                radio.addEventListener('change', function() {
                    inputs.bg.value = this.dataset.presetBg;
                    inputs.text.value = this.dataset.presetText;
                    inputs.btn.value = this.dataset.presetBtn;
                    inputs.btnText.value = this.dataset.presetBtnText;
                    updatePreview();
                });
            });
        })();
        </script>
        <?php
    }

    // =========================================================================
    // COMPORTAMENTO
    // =========================================================================
    private static function render_section_behavior( $s ) {
        ?>
        <div class="scs-card">
            <h2><?php _e( 'Generale', 'db-cookie-manager' ); ?></h2>

            <div class="scs-field">
                <label>
                    <input type="checkbox" name="dbcm_banner_enabled" value="1" <?php checked( $s['banner_enabled'] ); ?>>
                    <?php _e( 'Attiva il banner cookie sul sito', 'db-cookie-manager' ); ?>
                </label>
                <p class="description"><?php _e( 'Se disattivato, il banner non verrà mostrato ai visitatori.', 'db-cookie-manager' ); ?></p>
            </div>

            <div class="scs-field">
                <label><?php _e( 'Durata del consenso (giorni)', 'db-cookie-manager' ); ?></label>
                <input type="number" name="dbcm_consent_duration" value="<?php echo esc_attr( $s['consent_duration'] ); ?>" min="1" max="730" style="width:100px;">
                <p class="description"><?php _e( 'Dopo questo periodo il banner verrà mostrato nuovamente. Il Garante italiano raccomanda massimo 6 mesi (180 giorni) per i cookie di profilazione.', 'db-cookie-manager' ); ?></p>
            </div>

            <div class="scs-field">
                <label>
                    <input type="checkbox" name="dbcm_reconsent_on_change" value="1" <?php checked( $s['reconsent_on_change'] ); ?>>
                    <?php _e( 'Richiedi nuovo consenso se i cookie rilevati cambiano', 'db-cookie-manager' ); ?>
                </label>
                <p class="description"><?php _e( 'Se dopo una nuova scansione vengono trovati cookie diversi, il banner verrà mostrato di nuovo a tutti.', 'db-cookie-manager' ); ?></p>
            </div>
        </div>

        <div class="scs-card">
            <h2><?php _e( 'Icona riapertura', 'db-cookie-manager' ); ?></h2>

            <div class="scs-field">
                <label>
                    <input type="checkbox" name="dbcm_show_reopen_btn" value="1" <?php checked( $s['show_reopen_btn'] ); ?>>
                    <?php _e( 'Mostra icona per riaprire le preferenze cookie', 'db-cookie-manager' ); ?>
                </label>
                <p class="description"><?php _e( 'Un piccolo bottone flottante (icona cookie) che permette di modificare le preferenze in qualsiasi momento.', 'db-cookie-manager' ); ?></p>
            </div>

            <div class="scs-field">
                <label><?php _e( 'Posizione icona', 'db-cookie-manager' ); ?></label>
                <select name="dbcm_reopen_position">
                    <option value="bottom-left" <?php selected( $s['reopen_position'], 'bottom-left' ); ?>><?php _e( 'Basso a sinistra', 'db-cookie-manager' ); ?></option>
                    <option value="bottom-right" <?php selected( $s['reopen_position'], 'bottom-right' ); ?>><?php _e( 'Basso a destra', 'db-cookie-manager' ); ?></option>
                </select>
            </div>
        </div>

        <div class="scs-card">
            <h2><?php _e( 'Stato predefinito dei toggle', 'db-cookie-manager' ); ?></h2>
            <p class="description" style="margin-bottom:16px;"><?php _e( 'Scegli se i toggle nel pannello "Personalizza" partono attivi o spenti. Il Garante italiano raccomanda che siano disattivati di default (opt-in).', 'db-cookie-manager' ); ?></p>

            <div class="scs-field">
                <label>
                    <input type="checkbox" name="dbcm_default_analytics" value="1" <?php checked( $s['default_analytics'] ); ?>>
                    <?php _e( 'Cookie analitici attivi di default', 'db-cookie-manager' ); ?>
                </label>
            </div>

            <div class="scs-field">
                <label>
                    <input type="checkbox" name="dbcm_default_performance" value="1" <?php checked( $s['default_performance'] ); ?>>
                    <?php _e( 'Cookie prestazioni attivi di default', 'db-cookie-manager' ); ?>
                </label>
            </div>

            <div class="scs-field">
                <label>
                    <input type="checkbox" name="dbcm_default_marketing" value="1" <?php checked( $s['default_marketing'] ); ?>>
                    <?php _e( 'Cookie marketing attivi di default', 'db-cookie-manager' ); ?>
                </label>
            </div>
        </div>

        <div class="scs-card">
            <h2><?php _e( 'Blocco preventivo', 'db-cookie-manager' ); ?></h2>

            <div class="scs-field">
                <label>
                    <input type="checkbox" name="dbcm_block_scripts" value="1" <?php checked( $s['block_scripts'] ); ?>>
                    <?php _e( 'Attiva il blocco preventivo degli script', 'db-cookie-manager' ); ?>
                </label>
                <p class="description"><?php _e( 'Blocca gli script di analisi e marketing fino a quando l\'utente non dà il consenso. Richiesto dal GDPR.', 'db-cookie-manager' ); ?></p>
            </div>

            <div class="scs-field">
                <label>
                    <input type="checkbox" name="dbcm_auto_block" value="1" <?php checked( $s['auto_block'] ); ?>>
                    <?php _e( 'Blocco automatico degli script noti', 'db-cookie-manager' ); ?>
                </label>
                <p class="description"><?php _e( 'Il plugin riconosce e blocca automaticamente Google Analytics, Facebook Pixel, Hotjar, YouTube embed e altri. Se disattivato, dovrai configurare manualmente quali script bloccare.', 'db-cookie-manager' ); ?></p>
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // TESTI
    // =========================================================================
    private static function render_section_texts( $s ) {
        $available = self::get_available_languages();
        $active = self::get_active_languages();
        $text_defaults = self::get_text_defaults();
        $current_lang = isset( $_GET['lang'] ) ? sanitize_text_field( $_GET['lang'] ) : $active[0];
        if ( ! in_array( $current_lang, $active, true ) ) {
            $current_lang = $active[0];
        }
        ?>

        <!-- Lingue attive -->
        <div class="scs-card">
            <h2><?php _e( 'Lingue attive', 'db-cookie-manager' ); ?></h2>
            <p class="description" style="margin-bottom:12px;"><?php _e( 'Seleziona le lingue per cui vuoi configurare i testi del banner. La prima lingua selezionata è il fallback.', 'db-cookie-manager' ); ?></p>

            <div style="display:flex;gap:16px;flex-wrap:wrap;">
                <?php foreach ( $available as $code => $label ) : ?>
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;">
                    <input type="checkbox" name="dbcm_banner_languages[]" value="<?php echo esc_attr( $code ); ?>"
                        <?php checked( in_array( $code, $active, true ) ); ?>>
                    <?php echo esc_html( $label ); ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Tab lingue per editare i testi -->
        <div class="scs-card">
            <h2><?php _e( 'Testi del banner', 'db-cookie-manager' ); ?></h2>

            <div style="display:flex;gap:0;border-bottom:1px solid #c3c4c7;margin-bottom:20px;">
                <?php foreach ( $active as $lang_code ) : ?>
                <a href="?page=db-cookie-manager&tab=settings&section=testi&lang=<?php echo esc_attr( $lang_code ); ?>"
                   style="padding:8px 16px;text-decoration:none;font-weight:500;border-bottom:2px solid <?php echo $current_lang === $lang_code ? '#2563eb' : 'transparent'; ?>;color:<?php echo $current_lang === $lang_code ? '#1d2327' : '#646970'; ?>;margin-bottom:-1px;">
                    <?php echo esc_html( isset( $available[ $lang_code ] ) ? $available[ $lang_code ] : strtoupper( $lang_code ) ); ?>
                </a>
                <?php endforeach; ?>
            </div>

            <input type="hidden" name="dbcm_current_lang" value="<?php echo esc_attr( $current_lang ); ?>">

            <?php
            // Text fields for banner
            $banner_fields = array(
                'text_title'       => __( 'Titolo', 'db-cookie-manager' ),
                'text_description' => __( 'Descrizione', 'db-cookie-manager' ),
                'text_accept_all'  => __( 'Bottone "Accetta tutto"', 'db-cookie-manager' ),
                'text_reject_all'  => __( 'Bottone "Solo necessari"', 'db-cookie-manager' ),
                'text_customize'   => __( 'Bottone "Personalizza"', 'db-cookie-manager' ),
                'text_save'        => __( 'Bottone "Salva preferenze"', 'db-cookie-manager' ),
                'text_policy_link' => __( 'Testo link Cookie Policy', 'db-cookie-manager' ),
                'text_close'       => __( 'Bottone "Chiudi"', 'db-cookie-manager' ),
            );

            foreach ( $banner_fields as $key => $label ) :
                $value = self::get_text( $key, $current_lang );
                $is_textarea = ( $key === 'text_description' );
            ?>
            <div class="scs-field">
                <label for="dbcm_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
                <?php if ( $is_textarea ) : ?>
                    <textarea name="dbcm_<?php echo esc_attr( $key ); ?>" id="dbcm_<?php echo esc_attr( $key ); ?>"><?php echo esc_textarea( $value ); ?></textarea>
                <?php else : ?>
                    <input type="text" name="dbcm_<?php echo esc_attr( $key ); ?>" id="dbcm_<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>">
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="scs-card">
            <h2><?php _e( 'Testi categorie (pannello dettagli)', 'db-cookie-manager' ); ?></h2>
            <p class="description" style="margin-bottom:16px;"><?php _e( 'Questi testi appaiono nel pannello "Personalizza".', 'db-cookie-manager' ); ?></p>

            <?php
            $cats = array( 'necessary', 'performance', 'analytics', 'marketing' );
            foreach ( $cats as $cat_key ) :
                $name_val = self::get_text( 'text_cat_' . $cat_key, $current_lang );
                $desc_val = self::get_text( 'text_cat_' . $cat_key . '_desc', $current_lang );
            ?>
            <div style="background:#f6f7f7;padding:16px;border-radius:6px;margin-bottom:12px;">
                <div class="scs-field" style="margin-bottom:10px;">
                    <label><?php printf( __( 'Nome categoria "%s"', 'db-cookie-manager' ), ucfirst( $cat_key ) ); ?></label>
                    <input type="text" name="dbcm_text_cat_<?php echo esc_attr( $cat_key ); ?>" value="<?php echo esc_attr( $name_val ); ?>">
                </div>
                <div class="scs-field" style="margin-bottom:0;">
                    <label><?php printf( __( 'Descrizione "%s"', 'db-cookie-manager' ), ucfirst( $cat_key ) ); ?></label>
                    <textarea name="dbcm_text_cat_<?php echo esc_attr( $cat_key ); ?>_desc" rows="2"><?php echo esc_textarea( $desc_val ); ?></textarea>
                </div>
            </div>
            <?php endforeach; ?>

            <?php
            // Also always_on text
            $always_on_val = self::get_text( 'text_always_on', $current_lang );
            ?>
            <div class="scs-field">
                <label><?php _e( 'Etichetta "Sempre attivi"', 'db-cookie-manager' ); ?></label>
                <input type="text" name="dbcm_text_always_on" value="<?php echo esc_attr( $always_on_val ); ?>">
            </div>
        </div>
        <?php
    }

    // =========================================================================
    // COOKIE POLICY
    // =========================================================================
    private static function render_section_policy( $s, $pages ) {
        ?>
        <div class="scs-card">
            <h2><?php _e( 'Pagina Cookie Policy', 'db-cookie-manager' ); ?></h2>

            <div class="scs-field">
                <label for="dbcm_policy_page_id"><?php _e( 'Seleziona la pagina Cookie Policy', 'db-cookie-manager' ); ?></label>
                <select name="dbcm_policy_page_id" id="dbcm_policy_page_id">
                    <option value="0"><?php _e( '— Nessuna pagina selezionata —', 'db-cookie-manager' ); ?></option>
                    <?php foreach ( $pages as $page ) : ?>
                        <option value="<?php echo esc_attr( $page->ID ); ?>" <?php selected( $s['policy_page_id'], $page->ID ); ?>>
                            <?php echo esc_html( $page->post_title ); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <p class="description"><?php _e( 'Il link nel banner punterà a questa pagina. Il contenuto puoi generarlo dalla tab "Genera Policy".', 'db-cookie-manager' ); ?></p>
            </div>

            <?php if ( $s['policy_page_id'] > 0 ) : ?>
                <p>
                    <a href="<?php echo esc_url( get_edit_post_link( $s['policy_page_id'] ) ); ?>" class="button" target="_blank">
                        <?php _e( 'Modifica pagina Cookie Policy', 'db-cookie-manager' ); ?>
                    </a>
                    <a href="<?php echo esc_url( get_permalink( $s['policy_page_id'] ) ); ?>" class="button" target="_blank">
                        <?php _e( 'Visualizza', 'db-cookie-manager' ); ?>
                    </a>
                </p>
            <?php endif; ?>
        </div>

        <div class="scs-card">
            <h2><?php _e( 'Suggerimento', 'db-cookie-manager' ); ?></h2>
            <p><?php _e( 'Se non hai ancora una pagina Cookie Policy:', 'db-cookie-manager' ); ?></p>
            <ol>
                <li><?php _e( 'Crea una nuova pagina WordPress (Pagine → Aggiungi nuova)', 'db-cookie-manager' ); ?></li>
                <li><?php _e( 'Vai alla tab "Genera Policy" di questo plugin e copia l\'HTML generato', 'db-cookie-manager' ); ?></li>
                <li><?php _e( 'Incollalo nella pagina usando un blocco HTML', 'db-cookie-manager' ); ?></li>
                <li><?php _e( 'Torna qui e seleziona la pagina dal menu sopra', 'db-cookie-manager' ); ?></li>
            </ol>
        </div>
        <?php
    }

    // =========================================================================
    // REGISTRO CONSENSI
    // =========================================================================
    private static function render_section_log( $s ) {
        ?>
        <div class="scs-card">
            <h2><?php _e( 'Registro consensi', 'db-cookie-manager' ); ?></h2>

            <div class="scs-field">
                <label>
                    <input type="checkbox" name="dbcm_consent_log_enabled" value="1" <?php checked( $s['consent_log_enabled'] ); ?>>
                    <?php _e( 'Registra i consensi degli utenti', 'db-cookie-manager' ); ?>
                </label>
                <p class="description"><?php _e( 'Salva un log anonimizzato di ogni consenso raccolto, come richiesto dall\'art. 7(1) del GDPR per dimostrare la validità del consenso.', 'db-cookie-manager' ); ?></p>
            </div>

            <div class="scs-field">
                <label><?php _e( 'Conservazione dati (mesi)', 'db-cookie-manager' ); ?></label>
                <input type="number" name="dbcm_consent_log_retention" value="<?php echo esc_attr( $s['consent_log_retention'] ); ?>" min="1" max="60" style="width:100px;">
                <p class="description"><?php _e( 'I record più vecchi verranno cancellati automaticamente. Consigliato: 12 mesi.', 'db-cookie-manager' ); ?></p>
            </div>
        </div>

        <div class="scs-card">
            <h2><?php _e( 'Informazioni', 'db-cookie-manager' ); ?></h2>
            <p><?php _e( 'Il registro salva per ogni consenso:', 'db-cookie-manager' ); ?></p>
            <ul style="list-style:disc;padding-left:20px;">
                <li><?php _e( 'Hash anonimizzato dell\'IP (non l\'IP in chiaro)', 'db-cookie-manager' ); ?></li>
                <li><?php _e( 'Data e ora del consenso', 'db-cookie-manager' ); ?></li>
                <li><?php _e( 'Categorie accettate/rifiutate', 'db-cookie-manager' ); ?></li>
                <li><?php _e( 'User Agent del browser', 'db-cookie-manager' ); ?></li>
            </ul>
            <p class="description"><?php _e( 'La tab "Registro" con la consultazione e l\'export CSV sarà disponibile dopo l\'attivazione del banner.', 'db-cookie-manager' ); ?></p>
        </div>
        <?php
    }
}
