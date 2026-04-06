<?php
/**
 * Script Blocker
 *
 * Blocks analytics and marketing scripts until the user gives consent.
 * Two mechanisms:
 * 1. script_loader_tag filter for wp_enqueue_script scripts
 * 2. Output buffering for inline/hardcoded scripts and iframes
 *
 * @package db-cookie-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DBCM_Blocker {

    /**
     * Known script patterns mapped to consent categories.
     * 'pattern' is matched against script src or inline content.
     */
    private static $known_patterns = array(

        // === ANALYTICS ===
        array(
            'category' => 'analytics',
            'patterns' => array(
                'google-analytics.com/analytics',
                'googletagmanager.com/gtag',
                'google-analytics.com/ga.js',
                'google-analytics.com/urchin.js',
                'plausible.io/js',
                'static.hotjar.com',
                'cdn.mxpnl.com',          // Mixpanel
                'matomo',
                'piwik',
                'stats.wp.com',            // WordPress.com Stats / Jetpack
                'connect.facebook.net',    // FB SDK can be analytics too
            ),
        ),

        // === PERFORMANCE ===
        array(
            'category' => 'performance',
            'patterns' => array(
                'cdnjs.cloudflare.com',
                'cdn.cloudflare.com',
                'challenges.cloudflare.com',
                'ajax.cloudflare.com',
            ),
        ),

        // === MARKETING ===
        array(
            'category' => 'marketing',
            'patterns' => array(
                'fbevents.js',
                'fbq(',
                'connect.facebook.net',
                'snap.licdn.com',
                'linkedin.com/px',
                'ads.linkedin.com',
                'analytics.tiktok.com',
                'googleadservices.com',
                'googlesyndication.com',
                'doubleclick.net',
                'adservice.google',
                'amazon-adsystem.com',
                'twitter.com/uwt.js',
                'platform.twitter.com/widgets',
                'ads.twitter.com',
            ),
        ),

        // === MARKETING IFRAMES ===
        array(
            'category'  => 'marketing',
            'type'      => 'iframe',
            'patterns'  => array(
                'youtube.com/embed',
                'youtube-nocookie.com/embed',
                'player.vimeo.com',
                'maps.google.com',
                'google.com/maps/embed',
            ),
        ),
    );

    /**
     * Initialize blocker
     */
    public static function init() {
        if ( is_admin() ) {
            return;
        }

        if ( ! DBCM_Settings::get( 'banner_enabled' ) ) {
            return;
        }

        if ( ! DBCM_Settings::get( 'block_scripts' ) ) {
            return;
        }

        // Filter enqueued scripts
        add_filter( 'script_loader_tag', array( __CLASS__, 'filter_script_tag' ), 100, 3 );

        // Output buffering for inline scripts and iframes
        if ( DBCM_Settings::get( 'auto_block' ) ) {
            add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ), 1 );
        }
    }

    /**
     * Check if user has already given consent for a category
     * (reads the cookie server-side)
     */
    private static function has_consent( $category ) {
        if ( ! isset( $_COOKIE['dbcm_consent'] ) ) {
            return false;
        }

        $consent = json_decode( stripslashes( $_COOKIE['dbcm_consent'] ), true );
        if ( ! is_array( $consent ) ) {
            return false;
        }

        return ! empty( $consent[ $category ] );
    }

    /**
     * Get the category a script URL belongs to.
     * Returns null if not a known pattern.
     */
    private static function get_script_category( $src ) {
        if ( empty( $src ) ) {
            return null;
        }

        foreach ( self::$known_patterns as $group ) {
            // Skip iframe-only patterns for script matching
            if ( isset( $group['type'] ) && $group['type'] === 'iframe' ) {
                continue;
            }
            foreach ( $group['patterns'] as $pattern ) {
                if ( stripos( $src, $pattern ) !== false ) {
                    return $group['category'];
                }
            }
        }

        return null;
    }

    /**
     * Get the category an iframe src belongs to.
     */
    private static function get_iframe_category( $src ) {
        if ( empty( $src ) ) {
            return null;
        }

        foreach ( self::$known_patterns as $group ) {
            if ( ! isset( $group['type'] ) || $group['type'] !== 'iframe' ) {
                // Also check marketing patterns for iframes
                if ( $group['category'] !== 'marketing' ) {
                    continue;
                }
            }
            foreach ( $group['patterns'] as $pattern ) {
                if ( stripos( $src, $pattern ) !== false ) {
                    return $group['category'];
                }
            }
        }

        return null;
    }

    // =========================================================================
    // MECHANISM 1: script_loader_tag filter
    // =========================================================================

    /**
     * Filter script tags loaded via wp_enqueue_script
     */
    public static function filter_script_tag( $tag, $handle, $src ) {
        $category = self::get_script_category( $src );

        if ( ! $category ) {
            return $tag; // Not a known script, don't touch it
        }

        // If user already consented, let it through
        if ( self::has_consent( $category ) ) {
            return $tag;
        }

        // Block it: change type to text/plain and add data attributes
        $tag = str_replace( "type='text/javascript'", "type='text/plain'", $tag );
        $tag = str_replace( 'type="text/javascript"', 'type="text/plain"', $tag );

        // If no type attribute was present, add one
        if ( stripos( $tag, 'type=' ) === false ) {
            $tag = str_replace( '<script ', '<script type="text/plain" ', $tag );
        }

        // Add data attributes for JS to re-enable
        $tag = str_replace( '<script ', '<script data-dbcm-category="' . esc_attr( $category ) . '" data-dbcm-blocked="true" ', $tag );

        return $tag;
    }

    // =========================================================================
    // MECHANISM 2: Output buffering for inline/hardcoded scripts and iframes
    // =========================================================================

    /**
     * Start output buffering
     */
    public static function start_buffer() {
        ob_start( array( __CLASS__, 'process_buffer' ) );
    }

    /**
     * Process the output buffer
     */
    public static function process_buffer( $html ) {
        if ( empty( $html ) ) {
            return $html;
        }

        // Block inline/external scripts
        $html = self::block_scripts_in_html( $html );

        // Block iframes
        $html = self::block_iframes_in_html( $html );

        return $html;
    }

    /**
     * Block scripts in HTML output
     */
    private static function block_scripts_in_html( $html ) {
        // Match all <script> tags
        return preg_replace_callback(
            '/<script\b([^>]*)>(.*?)<\/script>/is',
            function ( $matches ) {
                $attrs   = $matches[1];
                $content = $matches[2];

                // Skip if already blocked by mechanism 1
                if ( stripos( $attrs, 'data-dbcm-blocked' ) !== false ) {
                    return $matches[0];
                }

                // Skip if it's our own banner script
                if ( stripos( $attrs, 'dbcm-banner' ) !== false ) {
                    return $matches[0];
                }

                // Determine category from src attribute
                $category = null;
                if ( preg_match( '/src=["\']([^"\']+)["\']/i', $attrs, $src_match ) ) {
                    $category = self::get_script_category( $src_match[1] );
                }

                // If no category from src, check inline content
                if ( ! $category && ! empty( $content ) ) {
                    foreach ( self::$known_patterns as $group ) {
                        if ( isset( $group['type'] ) && $group['type'] === 'iframe' ) {
                            continue;
                        }
                        foreach ( $group['patterns'] as $pattern ) {
                            if ( stripos( $content, $pattern ) !== false ) {
                                $category = $group['category'];
                                break 2;
                            }
                        }
                    }
                }

                if ( ! $category ) {
                    return $matches[0]; // Not a known script
                }

                // If user already consented, let it through
                if ( self::has_consent( $category ) ) {
                    return $matches[0];
                }

                // Block: change type and add data attributes
                $blocked_attrs = $attrs;

                // Replace or add type
                if ( preg_match( '/type=["\'][^"\']*["\']/i', $blocked_attrs ) ) {
                    $blocked_attrs = preg_replace( '/type=["\'][^"\']*["\']/i', 'type="text/plain"', $blocked_attrs );
                } else {
                    $blocked_attrs = ' type="text/plain"' . $blocked_attrs;
                }

                $blocked_attrs .= ' data-dbcm-category="' . esc_attr( $category ) . '" data-dbcm-blocked="true"';

                return '<script' . $blocked_attrs . '>' . $content . '</script>';
            },
            $html
        );
    }

    /**
     * Block iframes in HTML output
     */
    private static function block_iframes_in_html( $html ) {
        return preg_replace_callback(
            '/<iframe\b([^>]*)>(.*?)<\/iframe>/is',
            function ( $matches ) {
                $attrs = $matches[1];

                // Get src
                if ( ! preg_match( '/src=["\']([^"\']+)["\']/i', $attrs, $src_match ) ) {
                    return $matches[0];
                }

                $src = $src_match[1];
                $category = self::get_iframe_category( $src );

                if ( ! $category ) {
                    return $matches[0];
                }

                if ( self::has_consent( $category ) ) {
                    return $matches[0];
                }

                // Extract width/height for placeholder sizing
                $width = '100%';
                $height = '400px';
                if ( preg_match( '/width=["\']?(\d+)/i', $attrs, $w ) ) {
                    $width = $w[1] . 'px';
                }
                if ( preg_match( '/height=["\']?(\d+)/i', $attrs, $h ) ) {
                    $height = $h[1] . 'px';
                }

                // Determine service name
                $service = 'contenuto esterno';
                if ( stripos( $src, 'youtube' ) !== false ) {
                    $service = 'YouTube';
                } elseif ( stripos( $src, 'vimeo' ) !== false ) {
                    $service = 'Vimeo';
                } elseif ( stripos( $src, 'google.com/maps' ) !== false || stripos( $src, 'maps.google' ) !== false ) {
                    $service = 'Google Maps';
                }

                // Build placeholder
                $placeholder = '<div class="dbcm-iframe-placeholder" '
                    . 'style="width:' . esc_attr( $width ) . ';max-width:100%;height:' . esc_attr( $height ) . ';" '
                    . 'data-dbcm-category="' . esc_attr( $category ) . '" '
                    . 'data-dbcm-src="' . esc_attr( $src ) . '" '
                    . 'data-dbcm-attrs="' . esc_attr( $attrs ) . '">'
                    . '<div class="dbcm-iframe-placeholder__inner">'
                    . '<p class="dbcm-iframe-placeholder__text">'
                    . esc_html( sprintf( 'Questo contenuto (%s) richiede il consenso ai cookie di %s.',
                        $service,
                        $category === 'analytics' ? 'analisi' : 'marketing'
                    ) )
                    . '</p>'
                    . '<button class="dbcm-iframe-placeholder__btn" onclick="document.dispatchEvent(new CustomEvent(\'dbcm:requestConsent\',{detail:{category:\'' . esc_attr( $category ) . '\'}}))">'
                    . 'Accetta e carica'
                    . '</button>'
                    . '</div>'
                    . '</div>';

                return $placeholder;
            },
            $html
        );
    }
}
