<?php
/**
 * Cookie Banner Frontend
 *
 * @package db-cookie-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DBCM_Banner {

    /**
     * Initialize
     */
    public static function init() {
        // Don't show banner in admin or if disabled
        if ( is_admin() ) {
            return;
        }

        if ( ! DBCM_Settings::get( 'banner_enabled' ) ) {
            return;
        }

        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'wp_footer', array( __CLASS__, 'render_banner' ), 100 );
    }

    /**
     * Enqueue banner CSS and JS
     */
    public static function enqueue_assets() {
        wp_enqueue_style(
            'dbcm-banner',
            DBCM_URL . 'assets/css/banner.css',
            array(),
            DBCM_VERSION
        );

        wp_enqueue_script(
            'dbcm-banner',
            DBCM_URL . 'assets/js/banner.js',
            array(),
            DBCM_VERSION,
            true
        );

        // Pass settings to JS
        $s = DBCM_Settings::get_all();

        // Get cookie list grouped by category for the details panel
        $cookie_list = self::get_cookie_list();

        // Policy page URL
        $policy_url = '';
        if ( $s['policy_page_id'] > 0 ) {
            $policy_url = get_permalink( $s['policy_page_id'] );
        }

        wp_localize_script( 'dbcm-banner', 'dbcmBanner', array(
            // Layout
            'layout'         => $s['banner_layout'],
            'position'       => $s['banner_position'],
            'overlay'        => $s['banner_overlay'],
            'showCredits'    => $s['banner_credits'],
            'showReopen'     => $s['show_reopen_btn'],
            'reopenPosition' => $s['reopen_position'],
            'theme'          => $s['banner_theme'],

            // Colors
            'colorBg'        => $s['banner_color_bg'],
            'colorText'      => $s['banner_color_text'],
            'colorBtn'       => $s['banner_color_btn'],
            'colorBtnText'   => $s['banner_color_btn_text'],

            // Texts (all languages)
            'translations'   => DBCM_Settings::get_all_texts_for_js(),
            'defaultLang'    => get_option( 'dbcm_banner_default_lang', 'it' ),
            'activeLangs'    => DBCM_Settings::get_active_languages(),

            // Behavior
            'consentDuration'  => $s['consent_duration'],
            'policyUrl'        => $policy_url,
            'defaultAnalytics'  => $s['default_analytics'],
            'defaultPerformance' => $s['default_performance'],
            'defaultMarketing'  => $s['default_marketing'],

            // Cookie data for details panel
            'cookieList'      => $cookie_list,

            // Custom CSS
            'customCss'       => $s['banner_custom_css'],

            // AJAX for consent logging
            'ajaxurl'         => admin_url( 'admin-ajax.php' ),
        ) );
    }

    /**
     * Get cookie list from scan results, grouped for the details panel
     */
    private static function get_cookie_list() {
        $grouped = DBCM_Scanner::get_results_grouped();
        $list = array(
            'necessary'   => array(),
            'performance' => array(),
            'analytics'   => array(),
            'marketing'   => array(),
        );

        // Map DB categories to banner categories
        $category_map = array(
            'tecnico'      => 'necessary',
            'prestazioni'  => 'performance',
            'analitica'    => 'analytics',
            'marketing'    => 'marketing',
            'sconosciuto'  => 'marketing', // Unknown = treat as marketing (safer)
        );

        foreach ( $grouped as $category => $cookies ) {
            $banner_cat = isset( $category_map[ $category ] ) ? $category_map[ $category ] : 'marketing';
            foreach ( $cookies as $cookie ) {
                $list[ $banner_cat ][] = array(
                    'name'     => $cookie->cookie_name,
                    'provider' => $cookie->provider,
                    'desc'     => $cookie->description,
                    'duration' => $cookie->cookie_duration,
                );
            }
        }

        // Always include dbcm_consent in necessary
        $has_dbcm = false;
        foreach ( $list['necessary'] as $c ) {
            if ( $c['name'] === 'dbcm_consent' ) {
                $has_dbcm = true;
                break;
            }
        }
        if ( ! $has_dbcm ) {
            array_unshift( $list['necessary'], array(
                'name'     => 'dbcm_consent',
                'provider' => 'DB Cookie Manager',
                'desc'     => 'Memorizza la scelta dell\'utente sui cookie.',
                'duration' => DBCM_Settings::get( 'consent_duration' ) . ' giorni',
            ) );
        }

        return $list;
    }

    /**
     * Render banner HTML shell (content built by JS for performance)
     */
    public static function render_banner() {
        ?>
        <!-- DB Cookie Manager Banner -->
        <div id="dbcm-banner-wrap" style="display:none;"></div>
        <div id="dbcm-reopen-wrap" style="display:none;"></div>
        <?php
    }
}
