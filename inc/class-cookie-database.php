<?php
/**
 * Known Cookies Database
 *
 * @package db-cookie-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DBCM_Cookie_Database {

    /**
     * Get known cookies with classification
     */
    public static function get_known_cookies() {
        return array(

            // =============================================
            // WORDPRESS CORE - Tecnici
            // =============================================
            'wordpress_sec_*' => array(
                'category'    => 'tecnico',
                'description' => 'Cookie di autenticazione per utenti registrati WordPress.',
                'duration'    => 'Sessione / 14 giorni',
                'provider'    => 'WordPress',
            ),
            'wordpress_logged_in_*' => array(
                'category'    => 'tecnico',
                'description' => 'Indica se l\'utente è autenticato in WordPress.',
                'duration'    => 'Sessione / 14 giorni',
                'provider'    => 'WordPress',
            ),
            'wp-settings-*' => array(
                'category'    => 'tecnico',
                'description' => 'Salva le preferenze dell\'interfaccia admin di WordPress.',
                'duration'    => '1 anno',
                'provider'    => 'WordPress',
            ),
            'wp-settings-time-*' => array(
                'category'    => 'tecnico',
                'description' => 'Timestamp associato alle preferenze admin WordPress.',
                'duration'    => '1 anno',
                'provider'    => 'WordPress',
            ),
            'wordpress_test_cookie' => array(
                'category'    => 'tecnico',
                'description' => 'Verifica se il browser accetta i cookie.',
                'duration'    => 'Sessione',
                'provider'    => 'WordPress',
            ),
            'comment_author_*' => array(
                'category'    => 'tecnico',
                'description' => 'Memorizza il nome dell\'autore di un commento.',
                'duration'    => '347 giorni',
                'provider'    => 'WordPress',
            ),
            'comment_author_email_*' => array(
                'category'    => 'tecnico',
                'description' => 'Memorizza l\'email dell\'autore di un commento.',
                'duration'    => '347 giorni',
                'provider'    => 'WordPress',
            ),
            'comment_author_url_*' => array(
                'category'    => 'tecnico',
                'description' => 'Memorizza l\'URL dell\'autore di un commento.',
                'duration'    => '347 giorni',
                'provider'    => 'WordPress',
            ),
            'wp_lang' => array(
                'category'    => 'tecnico',
                'description' => 'Memorizza la lingua selezionata dall\'utente.',
                'duration'    => 'Sessione',
                'provider'    => 'WordPress',
            ),
            'PHPSESSID' => array(
                'category'    => 'tecnico',
                'description' => 'Identificatore di sessione PHP lato server.',
                'duration'    => 'Sessione',
                'provider'    => 'PHP',
            ),

            // =============================================
            // WOOCOMMERCE - Tecnici
            // =============================================
            'woocommerce_cart_hash' => array(
                'category'    => 'tecnico',
                'description' => 'Hash del carrello WooCommerce per cache.',
                'duration'    => 'Sessione',
                'provider'    => 'WooCommerce',
            ),
            'woocommerce_items_in_cart' => array(
                'category'    => 'tecnico',
                'description' => 'Indica se ci sono prodotti nel carrello.',
                'duration'    => 'Sessione',
                'provider'    => 'WooCommerce',
            ),
            'wp_woocommerce_session_*' => array(
                'category'    => 'tecnico',
                'description' => 'Sessione WooCommerce con dati carrello e checkout.',
                'duration'    => '2 giorni',
                'provider'    => 'WooCommerce',
            ),
            'wc_cart_created' => array(
                'category'    => 'tecnico',
                'description' => 'Timestamp di creazione del carrello.',
                'duration'    => 'Sessione',
                'provider'    => 'WooCommerce',
            ),

            // =============================================
            // GOOGLE ANALYTICS - Analitica
            // =============================================
            '_ga' => array(
                'category'    => 'analitica',
                'description' => 'Cookie principale Google Analytics per distinguere gli utenti.',
                'duration'    => '2 anni',
                'provider'    => 'Google Analytics',
            ),
            '_ga_*' => array(
                'category'    => 'analitica',
                'description' => 'Cookie Google Analytics 4 per mantenere lo stato della sessione.',
                'duration'    => '2 anni',
                'provider'    => 'Google Analytics',
            ),
            '_gid' => array(
                'category'    => 'analitica',
                'description' => 'Cookie Google Analytics per distinguere gli utenti (24h).',
                'duration'    => '24 ore',
                'provider'    => 'Google Analytics',
            ),
            '_gat' => array(
                'category'    => 'analitica',
                'description' => 'Limita la frequenza delle richieste a Google Analytics.',
                'duration'    => '1 minuto',
                'provider'    => 'Google Analytics',
            ),
            '_gat_gtag_*' => array(
                'category'    => 'analitica',
                'description' => 'Throttling richieste Google Analytics (gtag.js).',
                'duration'    => '1 minuto',
                'provider'    => 'Google Analytics',
            ),
            '__utma' => array(
                'category'    => 'analitica',
                'description' => 'Cookie Google Analytics (Universal) per utenti e sessioni.',
                'duration'    => '2 anni',
                'provider'    => 'Google Analytics',
            ),
            '__utmb' => array(
                'category'    => 'analitica',
                'description' => 'Cookie Google Analytics (Universal) per nuove sessioni.',
                'duration'    => '30 minuti',
                'provider'    => 'Google Analytics',
            ),
            '__utmc' => array(
                'category'    => 'analitica',
                'description' => 'Cookie Google Analytics (Universal) di sessione.',
                'duration'    => 'Sessione',
                'provider'    => 'Google Analytics',
            ),
            '__utmz' => array(
                'category'    => 'analitica',
                'description' => 'Cookie Google Analytics (Universal) per sorgente traffico.',
                'duration'    => '6 mesi',
                'provider'    => 'Google Analytics',
            ),
            '__utmt' => array(
                'category'    => 'analitica',
                'description' => 'Limita la frequenza delle richieste (Universal Analytics).',
                'duration'    => '10 minuti',
                'provider'    => 'Google Analytics',
            ),

            // =============================================
            // GOOGLE TAG MANAGER
            // =============================================
            '_gcl_au' => array(
                'category'    => 'marketing',
                'description' => 'Google Ads conversion linker per attribuzione conversioni.',
                'duration'    => '90 giorni',
                'provider'    => 'Google Ads',
            ),

            // =============================================
            // FACEBOOK / META - Marketing
            // =============================================
            '_fbp' => array(
                'category'    => 'marketing',
                'description' => 'Cookie Facebook Pixel per tracciare visite e conversioni.',
                'duration'    => '3 mesi',
                'provider'    => 'Meta / Facebook',
            ),
            '_fbc' => array(
                'category'    => 'marketing',
                'description' => 'Cookie Facebook per click su inserzioni.',
                'duration'    => '2 anni',
                'provider'    => 'Meta / Facebook',
            ),
            'fr' => array(
                'category'    => 'marketing',
                'description' => 'Cookie Facebook per pubblicità mirata.',
                'duration'    => '3 mesi',
                'provider'    => 'Meta / Facebook',
            ),

            // =============================================
            // HOTJAR - Analitica
            // =============================================
            '_hj*' => array(
                'category'    => 'analitica',
                'description' => 'Cookie Hotjar per analisi comportamento utente (heatmap, recording).',
                'duration'    => 'Variabile',
                'provider'    => 'Hotjar',
            ),
            '_hjSessionUser_*' => array(
                'category'    => 'analitica',
                'description' => 'Identifica l\'utente nella sessione Hotjar.',
                'duration'    => '1 anno',
                'provider'    => 'Hotjar',
            ),

            // =============================================
            // CLOUDFLARE - Prestazioni
            // =============================================
            '__cf_bm' => array(
                'category'    => 'prestazioni',
                'description' => 'Cookie Cloudflare Bot Management per distinguere umani da bot.',
                'duration'    => '30 minuti',
                'provider'    => 'Cloudflare',
            ),
            'cf_clearance' => array(
                'category'    => 'prestazioni',
                'description' => 'Cookie Cloudflare per superare il challenge di sicurezza.',
                'duration'    => '30 minuti',
                'provider'    => 'Cloudflare',
            ),
            '__cfduid' => array(
                'category'    => 'prestazioni',
                'description' => 'Cookie Cloudflare per identificazione del client (deprecato).',
                'duration'    => '30 giorni',
                'provider'    => 'Cloudflare',
            ),

            // =============================================
            // HUBSPOT - Marketing
            // =============================================
            '__hssc' => array(
                'category'    => 'analitica',
                'description' => 'Cookie HubSpot per tracciamento sessioni.',
                'duration'    => '30 minuti',
                'provider'    => 'HubSpot',
            ),
            '__hssrc' => array(
                'category'    => 'analitica',
                'description' => 'Cookie HubSpot per determinare nuova sessione.',
                'duration'    => 'Sessione',
                'provider'    => 'HubSpot',
            ),
            '__hstc' => array(
                'category'    => 'analitica',
                'description' => 'Cookie principale HubSpot per tracciamento visitatori.',
                'duration'    => '13 mesi',
                'provider'    => 'HubSpot',
            ),
            'hubspotutk' => array(
                'category'    => 'marketing',
                'description' => 'Identifica il visitatore per HubSpot CRM.',
                'duration'    => '13 mesi',
                'provider'    => 'HubSpot',
            ),

            // =============================================
            // COOKIE CONSENT / GDPR PLUGINS
            // =============================================
            'dbcm_consent' => array(
                'category'    => 'tecnico',
                'description' => 'Memorizza la scelta dell\'utente sui cookie (DB Cookie Manager).',
                'duration'    => '365 giorni',
                'provider'    => 'DB Cookie Manager',
            ),
            'starter_cookie_consent' => array(
                'category'    => 'tecnico',
                'description' => 'Memorizza la scelta dell\'utente sui cookie (tema Starter).',
                'duration'    => '365 giorni',
                'provider'    => 'Starter Theme',
            ),
            'cookielawinfo-*' => array(
                'category'    => 'tecnico',
                'description' => 'Cookie del plugin CookieYes/GDPR Cookie Consent.',
                'duration'    => '1 anno',
                'provider'    => 'CookieYes',
            ),
            'CookieLawInfoConsent' => array(
                'category'    => 'tecnico',
                'description' => 'Memorizza il consenso cookie (CookieYes).',
                'duration'    => '1 anno',
                'provider'    => 'CookieYes',
            ),
            'cmplz_*' => array(
                'category'    => 'tecnico',
                'description' => 'Cookie del plugin Complianz per gestione consenso.',
                'duration'    => '365 giorni',
                'provider'    => 'Complianz',
            ),

            // =============================================
            // CACHING PLUGINS
            // =============================================
            'wp-wpml_current_language' => array(
                'category'    => 'tecnico',
                'description' => 'Lingua corrente selezionata (WPML).',
                'duration'    => 'Sessione',
                'provider'    => 'WPML',
            ),
            'pll_language' => array(
                'category'    => 'tecnico',
                'description' => 'Lingua corrente selezionata (Polylang).',
                'duration'    => '1 anno',
                'provider'    => 'Polylang',
            ),

            // =============================================
            // YOUTUBE EMBED
            // =============================================
            'YSC' => array(
                'category'    => 'marketing',
                'description' => 'Cookie YouTube per tracciare visualizzazioni video embed.',
                'duration'    => 'Sessione',
                'provider'    => 'Google / YouTube',
            ),
            'VISITOR_INFO1_LIVE' => array(
                'category'    => 'marketing',
                'description' => 'Cookie YouTube per stimare la larghezza di banda.',
                'duration'    => '6 mesi',
                'provider'    => 'Google / YouTube',
            ),
            'CONSENT' => array(
                'category'    => 'tecnico',
                'description' => 'Cookie Google per stato del consenso.',
                'duration'    => '2 anni',
                'provider'    => 'Google',
            ),

            // =============================================
            // LINKEDIN
            // =============================================
            'li_sugr' => array(
                'category'    => 'marketing',
                'description' => 'Cookie LinkedIn Insight Tag per tracciamento conversioni.',
                'duration'    => '3 mesi',
                'provider'    => 'LinkedIn',
            ),
            'bcookie' => array(
                'category'    => 'marketing',
                'description' => 'Cookie LinkedIn browser ID.',
                'duration'    => '1 anno',
                'provider'    => 'LinkedIn',
            ),
            'lidc' => array(
                'category'    => 'tecnico',
                'description' => 'Cookie LinkedIn per routing datacenter.',
                'duration'    => '24 ore',
                'provider'    => 'LinkedIn',
            ),

            // =============================================
            // STRIPE
            // =============================================
            '__stripe_mid' => array(
                'category'    => 'tecnico',
                'description' => 'Cookie Stripe per prevenzione frodi nei pagamenti.',
                'duration'    => '1 anno',
                'provider'    => 'Stripe',
            ),
            '__stripe_sid' => array(
                'category'    => 'tecnico',
                'description' => 'Cookie Stripe di sessione per pagamenti.',
                'duration'    => '30 minuti',
                'provider'    => 'Stripe',
            ),

            // =============================================
            // MAILCHIMP
            // =============================================
            'mailchimp_landing_site' => array(
                'category'    => 'marketing',
                'description' => 'Traccia la pagina di arrivo per Mailchimp.',
                'duration'    => '28 giorni',
                'provider'    => 'Mailchimp',
            ),

            // =============================================
            // TIKTOK
            // =============================================
            '_ttp' => array(
                'category'    => 'marketing',
                'description' => 'Cookie TikTok Pixel per tracciamento conversioni.',
                'duration'    => '13 mesi',
                'provider'    => 'TikTok',
            ),
        );
    }

    /**
     * Match a cookie name against known patterns
     */
    public static function identify_cookie( $name ) {
        $known = self::get_known_cookies();

        // Exact match first
        if ( isset( $known[ $name ] ) ) {
            return $known[ $name ];
        }

        // Wildcard match
        foreach ( $known as $pattern => $info ) {
            if ( strpos( $pattern, '*' ) !== false ) {
                $regex_pattern = '/^' . str_replace( '\*', '.*', preg_quote( $pattern, '/' ) ) . '$/';
                if ( preg_match( $regex_pattern, $name ) ) {
                    return $info;
                }
            }
        }

        // Unknown cookie
        return array(
            'category'    => 'sconosciuto',
            'description' => 'Cookie non identificato. Verificare manualmente la finalità.',
            'duration'    => 'Sconosciuta',
            'provider'    => self::guess_provider( $name ),
        );
    }

    /**
     * Try to guess provider from cookie name
     */
    private static function guess_provider( $name ) {
        $prefixes = array(
            '_ga'        => 'Google Analytics',
            '_gid'       => 'Google Analytics',
            '_fbp'       => 'Meta / Facebook',
            '_hj'        => 'Hotjar',
            '__hs'       => 'HubSpot',
            'wp-'        => 'WordPress',
            'wordpress'  => 'WordPress',
            'woo'        => 'WooCommerce',
            'cmplz'      => 'Complianz',
            'cookielaw'  => 'CookieYes',
            '__cf'       => 'Cloudflare',
            '__stripe'   => 'Stripe',
            'li_'        => 'LinkedIn',
            '_tt'        => 'TikTok',
        );

        foreach ( $prefixes as $prefix => $provider ) {
            if ( stripos( $name, $prefix ) === 0 ) {
                return $provider;
            }
        }

        return 'Sconosciuto';
    }

    /**
     * Get category label
     */
    public static function get_category_label( $category ) {
        $labels = array(
            'tecnico'      => __( 'Tecnico (necessario)', 'db-cookie-manager' ),
            'prestazioni'  => __( 'Prestazioni', 'db-cookie-manager' ),
            'analitica'    => __( 'Analitica', 'db-cookie-manager' ),
            'marketing'    => __( 'Marketing / Profilazione', 'db-cookie-manager' ),
            'sconosciuto'  => __( 'Non classificato', 'db-cookie-manager' ),
        );
        return isset( $labels[ $category ] ) ? $labels[ $category ] : $category;
    }

    /**
     * Get category color for admin badge
     */
    public static function get_category_color( $category ) {
        $colors = array(
            'tecnico'      => '#22c55e',
            'prestazioni'  => '#f59e0b',
            'analitica'    => '#3b82f6',
            'marketing'    => '#ef4444',
            'sconosciuto'  => '#94a3b8',
        );
        return isset( $colors[ $category ] ) ? $colors[ $category ] : '#94a3b8';
    }
}
