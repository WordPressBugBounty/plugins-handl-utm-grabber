<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WP Consent API Integration for HandL UTM Grabber
 *
 * This integration allows HandL UTM Grabber to work with any cookie consent plugin
 * that supports the WP Consent API standard.
 *
 * @see https://wordpress.org/plugins/wp-consent-api/
 */

if ( ! class_exists( 'HandL_WP_Consent_API' ) ) {

    class HandL_WP_Consent_API {

        public function __construct() {
            add_action( 'plugins_loaded', array( $this, 'declare_compliance' ) );
            add_action( 'plugins_loaded', array( $this, 'register_cookies' ) );
            add_filter( 'is_ok_to_capture_utms', array( $this, 'check_consent' ), 10, 1 );
            add_filter( 'handl_gdpr_add_plugin_support', array( $this, 'add_to_gdpr_list' ), 10, 1 );
        }

        /**
         * Declare compliance with WP Consent API
         */
        public function declare_compliance() {
            $plugin = plugin_basename( dirname( __DIR__ ) . '/handl-utm-grabber.php' );
            add_filter( "wp_consent_api_registered_{$plugin}", '__return_true' );
        }

        /**
         * Register cookies with WP Consent API
         */
        public function register_cookies() {
            if ( ! function_exists( 'wp_add_cookie_info' ) ) {
                return; // WP Consent API not active
            }

            $fields = generateUTMFields();
            $duration_text = sprintf( __( '%d days' ), 30 );

            // Register all cookies as marketing cookies
            foreach ( $fields as $field ) {
                wp_add_cookie_info(
                    $field,
                    'HandL UTM Grabber',
                    'marketing',
                    $duration_text,
                    sprintf( __( 'Stores %s for marketing attribution and tracking.' ), $field )
                );
            }
        }

        /**
         * Check consent status via WP Consent API
         * Hooks into HandL's existing consent filter
         *
         * @param array $good2go The consent status array
         * @return array Modified consent status
         */
        public function check_consent( $good2go ) {
            $PLUGIN = 'wp-consent-api/wp-consent-api.php';

            if ( function_exists( 'wp_has_consent' ) &&
                 function_exists( 'wp_get_consent_type' ) &&
                 $good2go['good2go'] === 1 &&
                 getHandLGDPRPluginStatus( $PLUGIN ) ) {

                $consent_type = wp_get_consent_type();
                if ( $consent_type === false ) {
                    return $good2go; // No consent management plugin active, don't interfere
                }

                // A consent management plugin is active, check for marketing consent
                if ( wp_has_consent( 'marketing' ) ) {
                    $good2go['good2go'] = 1;
                } else {
                    $good2go['good2go'] = 0;
                }
            }

            return $good2go;
        }

        /**
         * Add WP Consent API to supported GDPR plugins list
         *
         * @param array $plugins List of GDPR plugins
         * @return array Modified list
         */
        public function add_to_gdpr_list( $plugins ) {
            $PLUGIN = 'wp-consent-api/wp-consent-api.php';
            if ( ! function_exists( 'is_plugin_active' ) ) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            if ( is_plugin_active( $PLUGIN ) ) {
                $plugins[] = $PLUGIN;
            }
            return $plugins;
        }
    }

    new HandL_WP_Consent_API();
}
