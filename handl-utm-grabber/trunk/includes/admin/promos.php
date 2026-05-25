<?php
namespace Handl\UtmrabberFree\Admin;

if ( ! defined( 'ABSPATH' ) ) exit;

class Handl_Promos_Manager
{
    private const PROMOS_ENDPOINT = 'https://api.utmgrabber.com/http/plugin-promos';
    private const TRANSIENT_KEY = 'handl_plugin_promos';
    private const INSTALL_DATE_OPTION = 'handl_utm_grabber_install_date';
    private const DISMISSALS_USER_META_KEY = 'handl_promo_dismissals';
    private const CACHE_DURATION = 6 * HOUR_IN_SECONDS;

    private const VALID_LOCATIONS = [
        'plugin_banner',
        'admin_notice', 
        'dashboard_widget',
        'admin_bar',
        'sidebar_badge',
    ];

    public function __construct()
    {
        add_action( 'wp_ajax_handl_get_promos', [ $this, 'ajax_get_promos' ] );
        add_action( 'wp_ajax_handl_dismiss_promo', [ $this, 'ajax_dismiss_promo' ] );
        add_action( 'admin_init', [ $this, 'maybe_set_install_date' ] );
        
        add_action( 'admin_notices', [ $this, 'render_admin_notice' ] );
        add_action( 'wp_dashboard_setup', [ $this, 'register_dashboard_widget' ] );
        add_action( 'admin_bar_menu', [ $this, 'render_admin_bar_item' ], 100 );
        add_action( 'admin_head', [ $this, 'render_admin_styles' ] );
        add_action( 'admin_footer', [ $this, 'render_dismiss_script' ] );
        
        add_filter( 'handl_promo_menu_badge', [ $this, 'get_sidebar_badge_html' ] );
    }

    public function maybe_set_install_date(): void
    {
        if ( ! get_option( self::INSTALL_DATE_OPTION ) ) {
            update_option( self::INSTALL_DATE_OPTION, current_time( 'mysql' ) );
        }
    }

    public function get_install_date(): string
    {
        $install_date = get_option( self::INSTALL_DATE_OPTION );
        if ( ! $install_date ) {
            $install_date = current_time( 'mysql' );
            update_option( self::INSTALL_DATE_OPTION, $install_date );
        }
        return $install_date;
    }

    public function get_days_since_install(): int
    {
        $install_date = $this->get_install_date();
        $install_timestamp = strtotime( $install_date );
        $now = current_time( 'timestamp' );
        return (int) floor( ( $now - $install_timestamp ) / DAY_IN_SECONDS );
    }

    // =========================================================================
    // Dismissal Management
    // =========================================================================

    public function get_user_dismissals(): array
    {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return [];
        }
        $dismissals = get_user_meta( $user_id, self::DISMISSALS_USER_META_KEY, true );
        return is_array( $dismissals ) ? $dismissals : [];
    }

    public function dismiss_promo( string $campaign_key ): bool
    {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return false;
        }
        $dismissals = $this->get_user_dismissals();
        $dismissals[ $campaign_key ] = current_time( 'mysql' );
        return update_user_meta( $user_id, self::DISMISSALS_USER_META_KEY, $dismissals );
    }

    public function is_promo_dismissed( string $campaign_key, ?array $promo = null ): bool
    {
        $dismissals = $this->get_user_dismissals();
        if ( ! isset( $dismissals[ $campaign_key ] ) ) {
            return false;
        }
        
        $dismissed_at = $dismissals[ $campaign_key ];
        
        if ( $promo && isset( $promo['plugin_conditions']['dismissed_cooldown_days'] ) ) {
            $cooldown_days = (int) $promo['plugin_conditions']['dismissed_cooldown_days'];
            $dismissed_timestamp = strtotime( $dismissed_at );
            $now = current_time( 'timestamp' );
            $days_since_dismissal = floor( ( $now - $dismissed_timestamp ) / DAY_IN_SECONDS );
            return $days_since_dismissal < $cooldown_days;
        }
        
        return true;
    }

    public function clear_user_dismissals(): bool
    {
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            return false;
        }
        return delete_user_meta( $user_id, self::DISMISSALS_USER_META_KEY );
    }

    // =========================================================================
    // AJAX Endpoints
    // =========================================================================

    public function ajax_get_promos(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        $promos = $this->get_promos();
        $install_days = $this->get_days_since_install();
        $filtered_promos = $this->filter_promos( $promos, $install_days );

        wp_send_json_success( [
            'promos' => $filtered_promos,
        ] );
    }

    public function ajax_dismiss_promo(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'Unauthorized' ], 403 );
        }

        $campaign_key = isset( $_POST['campaign_key'] ) ? sanitize_text_field( $_POST['campaign_key'] ) : '';
        
        if ( empty( $campaign_key ) ) {
            wp_send_json_error( [ 'message' => 'Missing campaign_key' ], 400 );
        }

        $result = $this->dismiss_promo( $campaign_key );
        
        if ( $result ) {
            wp_send_json_success( [ 'dismissed' => $campaign_key ] );
        } else {
            wp_send_json_error( [ 'message' => 'Failed to dismiss promo' ], 500 );
        }
    }

    // =========================================================================
    // Promo Fetching & Filtering
    // =========================================================================

    private function get_promos(): array
    {
        $cached = get_transient( self::TRANSIENT_KEY );
        if ( $cached !== false ) {
            return $cached;
        }

        $promos = $this->fetch_remote_promos();
        set_transient( self::TRANSIENT_KEY, $promos, self::CACHE_DURATION );
        return $promos;
    }

    private function fetch_remote_promos(): array
    {
        $response = wp_remote_get( self::PROMOS_ENDPOINT, [
            'timeout' => 10,
            'headers' => [ 'Accept' => 'application/json' ],
        ] );

        if ( is_wp_error( $response ) ) {
            return [];
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        if ( $status_code !== 200 ) {
            return [];
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        return is_array( $data ) ? $data : [];
    }

    private function filter_promos( array $promos, int $install_days, ?string $location = null ): array
    {
        $now = current_time( 'timestamp' );
        
        $filtered = array_filter( $promos, function( $promo ) use ( $now, $install_days, $location ) {
            if ( ! isset( $promo['plugin_enabled'] ) || ! $promo['plugin_enabled'] ) {
                return false;
            }

            if ( isset( $promo['date_end'] ) ) {
                $end_timestamp = strtotime( $promo['date_end'] );
                if ( $end_timestamp && $now > $end_timestamp ) {
                    return false;
                }
            }

            if ( isset( $promo['date_start'] ) ) {
                $start_timestamp = strtotime( $promo['date_start'] );
                if ( $start_timestamp && $now < $start_timestamp ) {
                    return false;
                }
            }

            if ( isset( $promo['plugin_conditions'] ) && is_array( $promo['plugin_conditions'] ) ) {
                $conditions = $promo['plugin_conditions'];
                if ( isset( $conditions['min_install_days'] ) && $install_days < (int) $conditions['min_install_days'] ) {
                    return false;
                }
                if ( isset( $conditions['max_install_days'] ) && $install_days > (int) $conditions['max_install_days'] ) {
                    return false;
                }
            }

            if ( $this->is_promo_dismissed( $promo['campaign_key'], $promo ) ) {
                return false;
            }

            if ( $location !== null ) {
                $locations = $promo['display_locations'] ?? [];
                if ( ! in_array( $location, $locations, true ) ) {
                    return false;
                }
            }

            return true;
        } );

        return array_values( $filtered );
    }

    public function get_active_promo_for_location( string $location ): ?array
    {
        if ( ! in_array( $location, self::VALID_LOCATIONS, true ) ) {
            return null;
        }

        $promos = $this->get_promos();
        $install_days = $this->get_days_since_install();
        $filtered = $this->filter_promos( $promos, $install_days, $location );
        
        if ( empty( $filtered ) ) {
            return null;
        }

        usort( $filtered, function( $a, $b ) {
            $date_a = strtotime( $a['date_end'] ?? '9999-12-31' );
            $date_b = strtotime( $b['date_end'] ?? '9999-12-31' );
            return $date_a - $date_b;
        } );

        return $filtered[0];
    }

    public function has_active_promo_for_location( string $location ): bool
    {
        return $this->get_active_promo_for_location( $location ) !== null;
    }

    // =========================================================================
    // URL Generation
    // =========================================================================

    public function get_promo_settings_url( string $campaign_key ): string
    {
        return admin_url( 'admin.php?page=handl-utm-grabber.php#/handl-options?promo=' . urlencode( $campaign_key ) );
    }

    // =========================================================================
    // Admin Notice
    // =========================================================================

    public function render_admin_notice(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $promo = $this->get_active_promo_for_location( 'admin_notice' );
        if ( ! $promo ) {
            return;
        }

        $settings_url = $this->get_promo_settings_url( $promo['campaign_key'] );
        ?>
        <div class="notice notice-info is-dismissible handl-promo-notice" data-campaign-key="<?php echo esc_attr( $promo['campaign_key'] ); ?>">
            <p>
                <strong>🔥 <?php echo esc_html( $promo['title'] ); ?></strong> — 
                <?php echo wp_kses_post( $promo['body'] ); ?>
                <a href="<?php echo esc_url( $settings_url ); ?>" class="button button-primary" style="margin-left: 10px;">Learn More</a>
            </p>
        </div>
        <?php
    }

    // =========================================================================
    // Dashboard Widget
    // =========================================================================

    public function register_dashboard_widget(): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $promo = $this->get_active_promo_for_location( 'dashboard_widget' );
        if ( ! $promo ) {
            return;
        }

        wp_add_dashboard_widget(
            'handl_promo_widget',
            '🔥 ' . esc_html( $promo['title'] ) . ' — HandL UTM Grabber',
            [ $this, 'render_dashboard_widget_content' ]
        );
    }

    public function render_dashboard_widget_content(): void
    {
        $promo = $this->get_active_promo_for_location( 'dashboard_widget' );
        if ( ! $promo ) {
            return;
        }

        $settings_url = $this->get_promo_settings_url( $promo['campaign_key'] );
        ?>
        <div class="handl-promo-widget" data-campaign-key="<?php echo esc_attr( $promo['campaign_key'] ); ?>">
            <p><?php echo wp_kses_post( $promo['body'] ); ?></p>
            <p>
                <a href="<?php echo esc_url( $settings_url ); ?>" class="button button-primary">Learn More</a>
                <button type="button" class="button handl-promo-dismiss" data-campaign-key="<?php echo esc_attr( $promo['campaign_key'] ); ?>" style="margin-left: 5px;">Dismiss</button>
            </p>
        </div>
        <?php
    }

    // =========================================================================
    // Admin Bar
    // =========================================================================

    public function render_admin_bar_item( \WP_Admin_Bar $admin_bar ): void
    {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $promo = $this->get_active_promo_for_location( 'admin_bar' );
        if ( ! $promo ) {
            return;
        }

        $settings_url = $this->get_promo_settings_url( $promo['campaign_key'] );

        $title_html = sprintf(
            '<span class="handl-promo-bar-content">🔥 %s</span><span class="handl-promo-bar-dismiss" data-campaign-key="%s" title="Dismiss">&times;</span>',
            esc_html( $promo['title'] ),
            esc_attr( $promo['campaign_key'] )
        );

        $admin_bar->add_node( [
            'id'    => 'handl-promo',
            'title' => $title_html,
            'href'  => $settings_url,
            'meta'  => [
                'class' => 'handl-promo-admin-bar',
                'title' => $promo['title'],
            ],
        ] );
    }

    // =========================================================================
    // Sidebar Badge
    // =========================================================================

    public function get_sidebar_badge_html(): string
    {
        $promo = $this->get_active_promo_for_location( 'sidebar_badge' );
        if ( ! $promo ) {
            return '';
        }

        return '<span class="handl-promo-badge" title="' . esc_attr( $promo['title'] ) . '"></span>';
    }

    // =========================================================================
    // Admin Styles & Scripts
    // =========================================================================

    public function render_admin_styles(): void
    {
        ?>
        <style>
            .handl-promo-badge {
                display: inline-block;
                width: 8px;
                height: 8px;
                background-color: #dc3545;
                border-radius: 50%;
                margin-left: 5px;
                vertical-align: middle;
                animation: handl-promo-pulse 2s infinite;
            }
            @keyframes handl-promo-pulse {
                0%, 100% { opacity: 1; }
                50% { opacity: 0.5; }
            }
            #wp-admin-bar-handl-promo > .ab-item {
                background: linear-gradient(90deg, #dc3545 0%, #ff6b35 100%) !important;
                color: #fff !important;
                font-weight: 600 !important;
                animation: handl-admin-bar-glow 2s ease-in-out infinite;
            }
            #wp-admin-bar-handl-promo > .ab-item:hover {
                background: linear-gradient(90deg, #c82333 0%, #e55a2b 100%) !important;
            }
            @keyframes handl-admin-bar-glow {
                0%, 100% { box-shadow: 0 0 5px rgba(220, 53, 69, 0.5); }
                50% { box-shadow: 0 0 15px rgba(220, 53, 69, 0.8); }
            }
            #wp-admin-bar-handl-promo .handl-promo-bar-content {
                margin-right: 5px !important;
            }
            #wp-admin-bar-handl-promo .handl-promo-bar-dismiss {
                display: inline-block !important;
                width: 18px !important;
                height: 18px !important;
                line-height: 16px !important;
                text-align: center !important;
                background: rgba(255, 255, 255, 0.2) !important;
                border-radius: 50% !important;
                font-size: 14px !important;
                font-weight: bold !important;
                cursor: pointer !important;
                color: #fff !important;
                transition: background 0.2s !important;
                vertical-align: middle !important;
            }
            #wp-admin-bar-handl-promo .handl-promo-bar-dismiss:hover {
                background: rgba(255, 255, 255, 0.4) !important;
            }
        </style>
        <?php
    }

    public function render_dismiss_script(): void
    {
        ?>
        <script>
        (function($) {
            function dismissPromo(campaignKey, callback) {
                $.post(ajaxurl, {
                    action: 'handl_dismiss_promo',
                    campaign_key: campaignKey
                }, function(response) {
                    if (response.success && callback) {
                        callback();
                    }
                });
            }

            $(document).on('click', '.handl-promo-notice .notice-dismiss', function() {
                var $notice = $(this).closest('.handl-promo-notice');
                var campaignKey = $notice.data('campaign-key');
                if (campaignKey) {
                    dismissPromo(campaignKey);
                }
            });

            $(document).on('click', '.handl-promo-dismiss', function() {
                var campaignKey = $(this).data('campaign-key');
                var $widget = $(this).closest('.handl-promo-widget');
                if (campaignKey) {
                    dismissPromo(campaignKey, function() {
                        $widget.closest('.postbox').fadeOut(300, function() {
                            $(this).remove();
                        });
                    });
                }
            });

            $(document).on('click', '.handl-promo-bar-dismiss', function(e) {
                e.preventDefault();
                e.stopPropagation();
                var campaignKey = $(this).data('campaign-key');
                if (campaignKey) {
                    dismissPromo(campaignKey, function() {
                        $('#wp-admin-bar-handl-promo').fadeOut(200, function() {
                            $(this).remove();
                        });
                    });
                }
            });
        })(jQuery);
        </script>
        <?php
    }

    public static function clear_cache(): bool
    {
        return delete_transient( self::TRANSIENT_KEY );
    }
}
