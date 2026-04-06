<?php
/**
 * Consent Log
 *
 * Logs every consent action for GDPR compliance (art. 7(1)).
 * IP is stored as a salted hash for anonymization.
 *
 * @package db-cookie-manager
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DBCM_Consent_Log {

    /**
     * Table name
     */
    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . 'dbcm_consent_log';
    }

    /**
     * Create table
     */
    public static function create_table() {
        global $wpdb;
        $table = self::table_name();

        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            ip_hash varchar(64) NOT NULL,
            consent_data varchar(500) NOT NULL,
            consent_type varchar(20) DEFAULT 'custom',
            user_agent varchar(500) DEFAULT '',
            consent_date timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY consent_date (consent_date),
            KEY ip_hash (ip_hash)
        ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Initialize
     */
    public static function init() {
        // AJAX handler for frontend logging
        add_action( 'wp_ajax_dbcm_log_consent', array( __CLASS__, 'ajax_log' ) );
        add_action( 'wp_ajax_nopriv_dbcm_log_consent', array( __CLASS__, 'ajax_log' ) );

        // Schedule cleanup cron
        if ( ! wp_next_scheduled( 'dbcm_cleanup_consent_log' ) ) {
            wp_schedule_event( time(), 'daily', 'dbcm_cleanup_consent_log' );
        }
        add_action( 'dbcm_cleanup_consent_log', array( __CLASS__, 'cleanup' ) );

        // CSV export handler
        add_action( 'admin_init', array( __CLASS__, 'handle_export' ) );
    }

    /**
     * Hash IP address with a site-specific salt
     */
    private static function hash_ip( $ip ) {
        $salt = wp_salt( 'auth' );
        return hash( 'sha256', $ip . $salt );
    }

    /**
     * AJAX: log consent from frontend
     */
    public static function ajax_log() {
        if ( ! DBCM_Settings::get( 'consent_log_enabled' ) ) {
            wp_send_json_success();
            return;
        }

        global $wpdb;
        $table = self::table_name();

        $consent_data = isset( $_POST['consent'] ) ? sanitize_text_field( $_POST['consent'] ) : '';
        $consent_type = isset( $_POST['type'] ) ? sanitize_text_field( $_POST['type'] ) : 'custom';
        $user_agent   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ), 0, 500 ) : '';
        $ip           = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : '';

        $wpdb->insert( $table, array(
            'ip_hash'      => self::hash_ip( $ip ),
            'consent_data' => $consent_data,
            'consent_type' => $consent_type,
            'user_agent'   => $user_agent,
        ), array( '%s', '%s', '%s', '%s' ) );

        wp_send_json_success();
    }

    /**
     * Cleanup old records based on retention setting
     */
    public static function cleanup() {
        if ( ! DBCM_Settings::get( 'consent_log_enabled' ) ) {
            return;
        }

        global $wpdb;
        $table = self::table_name();
        $months = DBCM_Settings::get( 'consent_log_retention' );

        if ( $months < 1 ) {
            $months = 12;
        }

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE consent_date < DATE_SUB(NOW(), INTERVAL %d MONTH)",
            $months
        ) );
    }

    /**
     * Get total count (with optional filters)
     */
    public static function get_count( $type_filter = '', $date_from = '', $date_to = '' ) {
        global $wpdb;
        $table = self::table_name();

        $where = '1=1';
        $params = array();

        if ( $type_filter ) {
            $where .= ' AND consent_type = %s';
            $params[] = $type_filter;
        }
        if ( $date_from ) {
            $where .= ' AND consent_date >= %s';
            $params[] = $date_from . ' 00:00:00';
        }
        if ( $date_to ) {
            $where .= ' AND consent_date <= %s';
            $params[] = $date_to . ' 23:59:59';
        }

        if ( ! empty( $params ) ) {
            return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $params ) );
        }
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
    }

    /**
     * Get paginated results
     */
    public static function get_results( $page = 1, $per_page = 25, $type_filter = '', $date_from = '', $date_to = '' ) {
        global $wpdb;
        $table = self::table_name();

        $where = '1=1';
        $params = array();

        if ( $type_filter ) {
            $where .= ' AND consent_type = %s';
            $params[] = $type_filter;
        }
        if ( $date_from ) {
            $where .= ' AND consent_date >= %s';
            $params[] = $date_from . ' 00:00:00';
        }
        if ( $date_to ) {
            $where .= ' AND consent_date <= %s';
            $params[] = $date_to . ' 23:59:59';
        }

        $offset = ( $page - 1 ) * $per_page;
        $params[] = $per_page;
        $params[] = $offset;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$where} ORDER BY consent_date DESC LIMIT %d OFFSET %d",
            $params
        ) );
    }

    /**
     * Handle CSV export
     */
    public static function handle_export() {
        if ( ! isset( $_GET['dbcm_export_csv'] ) || ! current_user_can( 'manage_options' ) ) {
            return;
        }

        check_admin_referer( 'dbcm_export_csv' );

        global $wpdb;
        $table = self::table_name();

        $results = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY consent_date DESC", ARRAY_A );

        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=consent-log-' . date( 'Y-m-d' ) . '.csv' );

        $output = fopen( 'php://output', 'w' );

        // UTF-8 BOM for Excel
        fprintf( $output, chr(0xEF) . chr(0xBB) . chr(0xBF) );

        // Header row
        fputcsv( $output, array( 'ID', 'IP Hash', 'Consenso', 'Tipo', 'User Agent', 'Data' ), ';' );

        foreach ( $results as $row ) {
            fputcsv( $output, array(
                $row['id'],
                $row['ip_hash'],
                $row['consent_data'],
                $row['consent_type'],
                $row['user_agent'],
                $row['consent_date'],
            ), ';' );
        }

        fclose( $output );
        exit;
    }

    /**
     * Render admin tab
     */
    public static function render() {
        if ( ! DBCM_Settings::get( 'consent_log_enabled' ) ) {
            ?>
            <div class="scs-card">
                <h2><?php _e( 'Registro consensi disattivato', 'db-cookie-manager' ); ?></h2>
                <p><?php _e( 'Attiva il registro consensi nelle Impostazioni → Registro consensi.', 'db-cookie-manager' ); ?></p>
            </div>
            <?php
            return;
        }

        // Check table exists
        global $wpdb;
        $table = self::table_name();
        if ( $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ) !== $table ) {
            self::create_table();
        }

        // Filters
        $type_filter = isset( $_GET['consent_type'] ) ? sanitize_text_field( $_GET['consent_type'] ) : '';
        $date_from   = isset( $_GET['date_from'] ) ? sanitize_text_field( $_GET['date_from'] ) : '';
        $date_to     = isset( $_GET['date_to'] ) ? sanitize_text_field( $_GET['date_to'] ) : '';
        $current_page = isset( $_GET['log_page'] ) ? max( 1, absint( $_GET['log_page'] ) ) : 1;
        $per_page = 25;

        $total = self::get_count( $type_filter, $date_from, $date_to );
        $total_pages = ceil( $total / $per_page );
        $results = self::get_results( $current_page, $per_page, $type_filter, $date_from, $date_to );

        // Stats
        $total_all      = self::get_count();
        $total_accept   = self::get_count( 'all' );
        $total_reject   = self::get_count( 'necessary' );
        $total_custom   = self::get_count( 'custom' );
        ?>

        <!-- Stats -->
        <div class="scs-stats">
            <div class="scs-stat">
                <div class="scs-stat__number"><?php echo $total_all; ?></div>
                <div class="scs-stat__label"><?php _e( 'Totale', 'db-cookie-manager' ); ?></div>
            </div>
            <div class="scs-stat">
                <div class="scs-stat__number" style="color:#22c55e;"><?php echo $total_accept; ?></div>
                <div class="scs-stat__label"><?php _e( 'Accetta tutto', 'db-cookie-manager' ); ?></div>
            </div>
            <div class="scs-stat">
                <div class="scs-stat__number" style="color:#ef4444;"><?php echo $total_reject; ?></div>
                <div class="scs-stat__label"><?php _e( 'Solo necessari', 'db-cookie-manager' ); ?></div>
            </div>
            <div class="scs-stat">
                <div class="scs-stat__number" style="color:#3b82f6;"><?php echo $total_custom; ?></div>
                <div class="scs-stat__label"><?php _e( 'Personalizzato', 'db-cookie-manager' ); ?></div>
            </div>
        </div>

        <!-- Filters -->
        <div class="scs-card">
            <form method="get" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
                <input type="hidden" name="page" value="db-cookie-manager">
                <input type="hidden" name="tab" value="registro">

                <div>
                    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;"><?php _e( 'Tipo', 'db-cookie-manager' ); ?></label>
                    <select name="consent_type" style="min-width:140px;">
                        <option value=""><?php _e( 'Tutti', 'db-cookie-manager' ); ?></option>
                        <option value="all" <?php selected( $type_filter, 'all' ); ?>><?php _e( 'Accetta tutto', 'db-cookie-manager' ); ?></option>
                        <option value="necessary" <?php selected( $type_filter, 'necessary' ); ?>><?php _e( 'Solo necessari', 'db-cookie-manager' ); ?></option>
                        <option value="custom" <?php selected( $type_filter, 'custom' ); ?>><?php _e( 'Personalizzato', 'db-cookie-manager' ); ?></option>
                    </select>
                </div>

                <div>
                    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;"><?php _e( 'Da', 'db-cookie-manager' ); ?></label>
                    <input type="date" name="date_from" value="<?php echo esc_attr( $date_from ); ?>">
                </div>

                <div>
                    <label style="font-size:12px;font-weight:600;display:block;margin-bottom:4px;"><?php _e( 'A', 'db-cookie-manager' ); ?></label>
                    <input type="date" name="date_to" value="<?php echo esc_attr( $date_to ); ?>">
                </div>

                <button type="submit" class="button"><?php _e( 'Filtra', 'db-cookie-manager' ); ?></button>

                <?php if ( $type_filter || $date_from || $date_to ) : ?>
                    <a href="?page=db-cookie-manager&tab=registro" class="button"><?php _e( 'Reset', 'db-cookie-manager' ); ?></a>
                <?php endif; ?>

                <div style="margin-left:auto;">
                    <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?dbcm_export_csv=1' ), 'dbcm_export_csv' ) ); ?>" class="button">
                        <span class="dashicons dashicons-download" style="margin-top:4px;margin-right:2px;"></span>
                        <?php _e( 'Esporta CSV', 'db-cookie-manager' ); ?>
                    </a>
                </div>
            </form>
        </div>

        <!-- Results table -->
        <div class="scs-card">
            <?php if ( empty( $results ) ) : ?>
                <p><?php _e( 'Nessun consenso registrato.', 'db-cookie-manager' ); ?></p>
            <?php else : ?>
                <table class="scs-table">
                    <thead>
                        <tr>
                            <th style="width:50px;">#</th>
                            <th><?php _e( 'Data', 'db-cookie-manager' ); ?></th>
                            <th><?php _e( 'Tipo', 'db-cookie-manager' ); ?></th>
                            <th><?php _e( 'Categorie', 'db-cookie-manager' ); ?></th>
                            <th><?php _e( 'IP (hash)', 'db-cookie-manager' ); ?></th>
                            <th><?php _e( 'Browser', 'db-cookie-manager' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $results as $row ) :
                            $consent = json_decode( $row->consent_data, true );
                            $type_label = self::get_type_label( $row->consent_type );
                            $type_color = self::get_type_color( $row->consent_type );
                            $browser = self::parse_user_agent( $row->user_agent );
                        ?>
                        <tr>
                            <td style="color:#94a3b8;font-size:12px;"><?php echo esc_html( $row->id ); ?></td>
                            <td>
                                <div style="font-size:13px;"><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $row->consent_date ) ) ); ?></div>
                                <div style="font-size:11px;color:#94a3b8;"><?php echo esc_html( date_i18n( 'H:i:s', strtotime( $row->consent_date ) ) ); ?></div>
                            </td>
                            <td>
                                <span class="scs-badge" style="background:<?php echo esc_attr( $type_color ); ?>;">
                                    <?php echo esc_html( $type_label ); ?>
                                </span>
                            </td>
                            <td style="font-size:12px;">
                                <?php if ( is_array( $consent ) ) : ?>
                                    <?php foreach ( array( 'necessary', 'performance', 'analytics', 'marketing' ) as $cat ) :
                                        if ( ! isset( $consent[ $cat ] ) ) continue;
                                        $on = $consent[ $cat ];
                                    ?>
                                        <span style="display:inline-block;margin-right:6px;color:<?php echo $on ? '#22c55e' : '#94a3b8'; ?>;">
                                            <?php echo $on ? '●' : '○'; ?> <?php echo esc_html( ucfirst( $cat ) ); ?>
                                        </span>
                                    <?php endforeach; ?>
                                <?php else : ?>
                                    <code style="font-size:11px;"><?php echo esc_html( $row->consent_data ); ?></code>
                                <?php endif; ?>
                            </td>
                            <td><code style="font-size:10px;word-break:break-all;"><?php echo esc_html( substr( $row->ip_hash, 0, 16 ) ); ?>…</code></td>
                            <td style="font-size:12px;color:#64748b;"><?php echo esc_html( $browser ); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <!-- Pagination -->
                <?php if ( $total_pages > 1 ) : ?>
                <div style="margin-top:16px;display:flex;justify-content:space-between;align-items:center;">
                    <span style="font-size:13px;color:#64748b;">
                        <?php printf(
                            __( '%d-%d di %d risultati', 'db-cookie-manager' ),
                            ( $current_page - 1 ) * $per_page + 1,
                            min( $current_page * $per_page, $total ),
                            $total
                        ); ?>
                    </span>
                    <div style="display:flex;gap:4px;">
                        <?php
                        $base_url = add_query_arg( array(
                            'page'         => 'db-cookie-manager',
                            'tab'          => 'registro',
                            'consent_type' => $type_filter,
                            'date_from'    => $date_from,
                            'date_to'      => $date_to,
                        ), admin_url( 'tools.php' ) );

                        // Previous
                        if ( $current_page > 1 ) :
                        ?>
                            <a href="<?php echo esc_url( add_query_arg( 'log_page', $current_page - 1, $base_url ) ); ?>" class="button">‹</a>
                        <?php endif;

                        // Page numbers (show max 7)
                        $start = max( 1, $current_page - 3 );
                        $end   = min( $total_pages, $current_page + 3 );
                        for ( $i = $start; $i <= $end; $i++ ) :
                        ?>
                            <a href="<?php echo esc_url( add_query_arg( 'log_page', $i, $base_url ) ); ?>"
                               class="button <?php echo $i === $current_page ? 'button-primary' : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endfor;

                        // Next
                        if ( $current_page < $total_pages ) :
                        ?>
                            <a href="<?php echo esc_url( add_query_arg( 'log_page', $current_page + 1, $base_url ) ); ?>" class="button">›</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

            <?php endif; ?>
        </div>

        <!-- Info -->
        <div class="scs-card">
            <p style="font-size:12px;color:#64748b;margin:0;">
                <?php printf(
                    __( 'Conservazione: %d mesi. L\'IP viene salvato come hash irreversibile. Pulizia automatica giornaliera.', 'db-cookie-manager' ),
                    DBCM_Settings::get( 'consent_log_retention' )
                ); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Type label
     */
    private static function get_type_label( $type ) {
        $labels = array(
            'all'       => __( 'Accetta tutto', 'db-cookie-manager' ),
            'necessary' => __( 'Solo necessari', 'db-cookie-manager' ),
            'custom'    => __( 'Personalizzato', 'db-cookie-manager' ),
        );
        return isset( $labels[ $type ] ) ? $labels[ $type ] : $type;
    }

    /**
     * Type color
     */
    private static function get_type_color( $type ) {
        $colors = array(
            'all'       => '#22c55e',
            'necessary' => '#ef4444',
            'custom'    => '#3b82f6',
        );
        return isset( $colors[ $type ] ) ? $colors[ $type ] : '#94a3b8';
    }

    /**
     * Simple user agent parser
     */
    private static function parse_user_agent( $ua ) {
        if ( empty( $ua ) ) return '—';

        if ( stripos( $ua, 'Chrome' ) !== false && stripos( $ua, 'Edg' ) !== false ) {
            return 'Edge';
        }
        if ( stripos( $ua, 'Chrome' ) !== false && stripos( $ua, 'Safari' ) !== false ) {
            return 'Chrome';
        }
        if ( stripos( $ua, 'Firefox' ) !== false ) {
            return 'Firefox';
        }
        if ( stripos( $ua, 'Safari' ) !== false && stripos( $ua, 'Chrome' ) === false ) {
            return 'Safari';
        }
        if ( stripos( $ua, 'MSIE' ) !== false || stripos( $ua, 'Trident' ) !== false ) {
            return 'IE';
        }

        // Mobile detection
        if ( stripos( $ua, 'Mobile' ) !== false ) {
            return 'Mobile';
        }

        return 'Altro';
    }
}
