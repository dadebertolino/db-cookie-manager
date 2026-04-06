<?php
/**
 * Cookie Scanner
 *
 * @package db-cookie-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DBCM_Scanner {

    /**
     * Custom table name
     */
    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'dbcm_cookies';
    }

    /**
     * Create database table
     */
    public static function create_table() {
        global $wpdb;
        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            cookie_name varchar(100) NOT NULL,
            cookie_domain varchar(100) DEFAULT '',
            cookie_path varchar(255) DEFAULT '/',
            cookie_duration varchar(100) DEFAULT '',
            cookie_secure tinyint(1) DEFAULT 0,
            cookie_httponly tinyint(1) DEFAULT 0,
            cookie_samesite varchar(20) DEFAULT '',
            category varchar(50) DEFAULT 'sconosciuto',
            description text,
            provider varchar(100) DEFAULT '',
            found_on varchar(500) DEFAULT '',
            scan_date timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY cookie_unique (cookie_name, cookie_domain)
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Run a full scan - preparation only (clears old data, returns URLs)
     */
    public static function run_scan_prepare() {
        global $wpdb;
        $table = self::table_name();
        $wpdb->query( "TRUNCATE TABLE {$table}" );
        delete_option( 'dbcm_google_fonts_detected' );

        $urls = self::get_scan_urls();
        update_option( 'dbcm_scan_urls', $urls );
        update_option( 'dbcm_scan_urls_count', count( $urls ) );
        update_option( 'dbcm_scan_progress', 0 );

        return $urls;
    }

    /**
     * Scan a single URL and save results
     */
    public static function run_scan_single( $url ) {
        global $wpdb;
        $table = self::table_name();

        $all_cookies = array();

        // HTTP header cookies
        $found = self::scan_url( $url );
        foreach ( $found as $cookie ) {
            $key = $cookie['name'] . '|' . $cookie['domain'];
            if ( ! isset( $all_cookies[ $key ] ) ) {
                $cookie['found_on'] = $url;
                $all_cookies[ $key ] = $cookie;
            }
        }

        // HTML-detected cookies
        $html_cookies = self::detect_from_html( array( $url ) );
        foreach ( $html_cookies as $cookie ) {
            $key = $cookie['name'] . '|' . $cookie['domain'];
            if ( ! isset( $all_cookies[ $key ] ) ) {
                $all_cookies[ $key ] = $cookie;
            }
        }

        // Save to database
        foreach ( $all_cookies as $cookie ) {
            $info = DBCM_Cookie_Database::identify_cookie( $cookie['name'] );

            $wpdb->replace( $table, array(
                'cookie_name'     => $cookie['name'],
                'cookie_domain'   => $cookie['domain'],
                'cookie_path'     => $cookie['path'],
                'cookie_duration' => ! empty( $info['duration'] ) ? $info['duration'] : $cookie['duration'],
                'cookie_secure'   => $cookie['secure'],
                'cookie_httponly' => $cookie['httponly'],
                'cookie_samesite' => $cookie['samesite'],
                'category'        => $info['category'],
                'description'     => $info['description'],
                'provider'        => $info['provider'],
                'found_on'        => $cookie['found_on'],
                'scan_date'       => current_time( 'mysql' ),
            ), array(
                '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s',
            ) );
        }

        return count( $all_cookies );
    }

    /**
     * Finalize scan
     */
    public static function run_scan_finalize() {
        update_option( 'dbcm_last_scan', current_time( 'mysql' ) );
        delete_option( 'dbcm_scan_urls' );
        delete_option( 'dbcm_scan_progress' );
        $results = self::get_results();
        return count( $results );
    }

    /**
     * Get URLs to scan
     */
    private static function get_scan_urls() {
        $urls = array( home_url( '/' ) );

        // Front page
        $front_id = get_option( 'page_on_front' );
        if ( $front_id ) {
            $urls[] = get_permalink( $front_id );
        }

        // Blog page
        $blog_id = get_option( 'page_for_posts' );
        if ( $blog_id ) {
            $urls[] = get_permalink( $blog_id );
        }

        // Sample of published pages (max 10)
        $pages = get_posts( array(
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => 10,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ) );
        foreach ( $pages as $page ) {
            $urls[] = get_permalink( $page->ID );
        }

        // Sample of published posts (max 5)
        $posts = get_posts( array(
            'post_type'      => 'post',
            'post_status'    => 'publish',
            'posts_per_page' => 5,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ) );
        foreach ( $posts as $post ) {
            $urls[] = get_permalink( $post->ID );
        }

        // WooCommerce pages
        if ( function_exists( 'wc_get_page_id' ) ) {
            $woo_pages = array( 'shop', 'cart', 'checkout', 'myaccount' );
            foreach ( $woo_pages as $woo_page ) {
                $id = wc_get_page_id( $woo_page );
                if ( $id > 0 ) {
                    $urls[] = get_permalink( $id );
                }
            }
        }

        return array_unique( array_filter( $urls ) );
    }

    /**
     * Scan a single URL for cookies via HTTP response headers
     */
    private static function scan_url( $url ) {
        $cookies = array();

        $response = wp_remote_get( $url, array(
            'timeout'    => 5,
            'sslverify'  => false,
            'cookies'    => array(),
            'user-agent' => 'DBCookieManager/' . DBCM_VERSION,
        ) );

        if ( is_wp_error( $response ) ) {
            return $cookies;
        }

        // Parse Set-Cookie headers
        $headers = wp_remote_retrieve_headers( $response );
        $set_cookies = array();

        if ( $headers instanceof \WpOrg\Requests\Utility\CaseInsensitiveDictionary || $headers instanceof \Requests_Utility_CaseInsensitiveDictionary ) {
            $all = $headers->getAll();
            if ( isset( $all['set-cookie'] ) ) {
                $set_cookies = (array) $all['set-cookie'];
            }
        } elseif ( is_array( $headers ) ) {
            if ( isset( $headers['set-cookie'] ) ) {
                $set_cookies = (array) $headers['set-cookie'];
            }
        }

        foreach ( $set_cookies as $header ) {
            $parsed = self::parse_set_cookie( $header );
            if ( $parsed ) {
                $parsed['found_on'] = $url;
                $cookies[] = $parsed;
            }
        }

        // Also check WP response cookies
        $wp_cookies = wp_remote_retrieve_cookies( $response );
        foreach ( $wp_cookies as $cookie_obj ) {
            $cookies[] = array(
                'name'     => $cookie_obj->name,
                'domain'   => $cookie_obj->domain,
                'path'     => $cookie_obj->path,
                'duration' => '',
                'secure'   => false,
                'httponly'  => false,
                'samesite' => '',
                'found_on' => $url,
            );
        }

        return $cookies;
    }

    /**
     * Parse a Set-Cookie header string
     */
    private static function parse_set_cookie( $header ) {
        $parts = explode( ';', $header );
        
        if ( empty( $parts[0] ) ) {
            return null;
        }

        $name_value = explode( '=', trim( $parts[0] ), 2 );
        if ( count( $name_value ) < 2 ) {
            return null;
        }

        $cookie = array(
            'name'     => trim( $name_value[0] ),
            'domain'   => '',
            'path'     => '/',
            'duration' => '',
            'secure'   => false,
            'httponly'  => false,
            'samesite' => '',
            'found_on' => '',
        );

        for ( $i = 1; $i < count( $parts ); $i++ ) {
            $attr = trim( $parts[ $i ] );
            $attr_parts = explode( '=', $attr, 2 );
            $attr_name = strtolower( trim( $attr_parts[0] ) );
            $attr_value = isset( $attr_parts[1] ) ? trim( $attr_parts[1] ) : '';

            switch ( $attr_name ) {
                case 'domain':
                    $cookie['domain'] = $attr_value;
                    break;
                case 'path':
                    $cookie['path'] = $attr_value;
                    break;
                case 'max-age':
                    $seconds = (int) $attr_value;
                    $cookie['duration'] = self::seconds_to_human( $seconds );
                    break;
                case 'expires':
                    $time = strtotime( $attr_value );
                    if ( $time ) {
                        $diff = $time - time();
                        $cookie['duration'] = self::seconds_to_human( $diff );
                    }
                    break;
                case 'secure':
                    $cookie['secure'] = true;
                    break;
                case 'httponly':
                    $cookie['httponly'] = true;
                    break;
                case 'samesite':
                    $cookie['samesite'] = $attr_value;
                    break;
            }
        }

        if ( empty( $cookie['duration'] ) ) {
            $cookie['duration'] = 'Sessione';
        }

        return $cookie;
    }

    /**
     * Detect cookies from HTML content (scripts, embeds)
     */
    private static function detect_from_html( $urls ) {
        $cookies = array();
        $site_host = wp_parse_url( home_url(), PHP_URL_HOST );

        foreach ( array_slice( $urls, 0, 5 ) as $url ) {
            $response = wp_remote_get( $url, array(
                'timeout'   => 5,
                'sslverify' => false,
            ) );

            if ( is_wp_error( $response ) ) {
                continue;
            }

            $html = wp_remote_retrieve_body( $response );

            // DB Cookie Manager banner
            if ( preg_match( '/dbcm_consent/', $html ) ) {
                $cookies[] = self::make_detected_cookie( 'dbcm_consent', $site_host, $url );
            }

            // Starter Cookie Banner (legacy)
            if ( preg_match( '/starter_cookie_consent/', $html ) ) {
                $cookies[] = self::make_detected_cookie( 'starter_cookie_consent', $site_host, $url );
            }

            // Google Analytics
            if ( preg_match( '/google-analytics\.com\/analytics|googletagmanager\.com\/gtag|gtag\(|ga\(/', $html ) ) {
                $cookies[] = self::make_detected_cookie( '_ga', '.'. $site_host, $url );
                $cookies[] = self::make_detected_cookie( '_gid', '.' . $site_host, $url );
            }

            // Facebook Pixel
            if ( preg_match( '/fbq\(|connect\.facebook\.net\/.*\/fbevents/', $html ) ) {
                $cookies[] = self::make_detected_cookie( '_fbp', '.' . $site_host, $url );
            }

            // Hotjar
            if ( preg_match( '/static\.hotjar\.com|hj\(/', $html ) ) {
                $cookies[] = self::make_detected_cookie( '_hjSessionUser_', '.' . $site_host, $url );
            }

            // YouTube embed
            if ( preg_match( '/youtube\.com\/embed|youtube-nocookie\.com/', $html ) ) {
                $cookies[] = self::make_detected_cookie( 'YSC', '.youtube.com', $url );
                $cookies[] = self::make_detected_cookie( 'VISITOR_INFO1_LIVE', '.youtube.com', $url );
            }

            // Google Fonts
            if ( preg_match( '/fonts\.googleapis\.com|fonts\.gstatic\.com/', $html ) ) {
                // Google Fonts doesn't set cookies but makes external requests
                // We note it for the policy generator
                update_option( 'dbcm_google_fonts_detected', true );
            }

            // HubSpot
            if ( preg_match( '/js\.hs-scripts\.com|hs-analytics/', $html ) ) {
                $cookies[] = self::make_detected_cookie( '__hstc', '.' . $site_host, $url );
                $cookies[] = self::make_detected_cookie( 'hubspotutk', '.' . $site_host, $url );
            }

            // LinkedIn Insight
            if ( preg_match( '/snap\.licdn\.com|linkedin\.com\/px/', $html ) ) {
                $cookies[] = self::make_detected_cookie( 'li_sugr', '.linkedin.com', $url );
            }

            // TikTok Pixel
            if ( preg_match( '/analytics\.tiktok\.com/', $html ) ) {
                $cookies[] = self::make_detected_cookie( '_ttp', '.' . $site_host, $url );
            }
        }

        return $cookies;
    }

    /**
     * Make a detected cookie array
     */
    private static function make_detected_cookie( $name, $domain, $url ) {
        return array(
            'name'     => $name,
            'domain'   => $domain,
            'path'     => '/',
            'duration' => '',
            'secure'   => false,
            'httponly'  => false,
            'samesite' => '',
            'found_on' => $url,
        );
    }

    /**
     * Convert seconds to human readable duration
     */
    private static function seconds_to_human( $seconds ) {
        if ( $seconds <= 0 ) {
            return 'Sessione';
        }

        $minutes = round( $seconds / 60 );
        $hours   = round( $seconds / 3600 );
        $days    = round( $seconds / 86400 );
        $months  = round( $seconds / 2592000 );
        $years   = round( $seconds / 31536000 );

        if ( $years >= 1 ) {
            return $years === 1 ? '1 anno' : $years . ' anni';
        }
        if ( $months >= 1 ) {
            return $months === 1 ? '1 mese' : $months . ' mesi';
        }
        if ( $days >= 1 ) {
            return $days === 1 ? '1 giorno' : $days . ' giorni';
        }
        if ( $hours >= 1 ) {
            return $hours === 1 ? '1 ora' : $hours . ' ore';
        }
        if ( $minutes >= 1 ) {
            return $minutes === 1 ? '1 minuto' : $minutes . ' minuti';
        }
        return $seconds . ' secondi';
    }

    /**
     * Get scan results from database
     */
    public static function get_results() {
        global $wpdb;
        $table = self::table_name();
        return $wpdb->get_results( "SELECT * FROM {$table} ORDER BY category ASC, cookie_name ASC" );
    }

    /**
     * Get results grouped by category
     */
    public static function get_results_grouped() {
        $results = self::get_results();
        $grouped = array();

        foreach ( $results as $row ) {
            $grouped[ $row->category ][] = $row;
        }

        // Sort by priority
        $order = array( 'tecnico', 'prestazioni', 'analitica', 'marketing', 'sconosciuto' );
        $sorted = array();
        foreach ( $order as $cat ) {
            if ( isset( $grouped[ $cat ] ) ) {
                $sorted[ $cat ] = $grouped[ $cat ];
            }
        }

        return $sorted;
    }
}
