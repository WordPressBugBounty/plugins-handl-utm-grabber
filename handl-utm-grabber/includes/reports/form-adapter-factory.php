<?php
namespace Handl\UtmrabberFree\Reports;
/**
 * Form Adapter Factory
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once dirname( __FILE__ ) . '/adapters/form-adapter-base.php';
require_once dirname( __FILE__ ) . '/adapters/gravity-forms-adapter.php';
require_once dirname( __FILE__ ) . '/adapters/ninja-forms-adapter.php';
require_once dirname( __FILE__ ) . '/adapters/woocommerce-adapter.php';
require_once dirname( __FILE__ ) . '/adapters/elementor-pro-adapter.php';
require_once dirname( __FILE__ ) . '/adapters/contact-form-7-cfdb7-adapter.php';
require_once dirname( __FILE__ ) . '/adapters/contact-form-7-flamingo-adapter.php';
require_once dirname( __FILE__ ) . '/adapters/premium-only-adapter.php';
use WP_Error;
use Handl\UtmrabberFree\Reports\Gravity_Forms_Adapter;
use Handl\UtmrabberFree\Reports\Ninja_Forms_Adapter;
use Handl\UtmrabberFree\Reports\WooCommerce_Adapter;
use Handl\UtmrabberFree\Reports\Elementor_Pro_Adapter;
use Handl\UtmrabberFree\Reports\Contact_Form_7_CFDB7_Adapter;
use Handl\UtmrabberFree\Reports\Contact_Form_7_Flamingo_Adapter;
use Handl\UtmrabberFree\Reports\Premium_Only_Adapter;

/**
 * Factory class for form adapters
 */
class Form_Adapter_Factory {
    /**
     * Get adapter for the specified form plugin
     *
     * @param string $form_plugin Form plugin identifier
     * @return Form_Adapter_Interface|WP_Error Adapter instance or WP_Error if adapter not found
     */
    public static function get_adapter($form_plugin) {
        switch ($form_plugin) {
            case 'gravity-form':
                return new Gravity_Forms_Adapter();

            case 'fluent-forms':
                return new Premium_Only_Adapter('Fluent Forms', function () {
                    return is_plugin_active('fluentform/fluentform.php') && function_exists('wpFluent');
                });

            case 'ninja-forms':
                return new Ninja_Forms_Adapter();

            case 'wpforms':
                return new Premium_Only_Adapter('WPForms', function () {
                    return is_plugin_active('wpforms/wpforms.php') || is_plugin_active('wpforms-lite/wpforms.php');
                });

            case 'woocommerce':
                return new WooCommerce_Adapter();

            case 'elementor-pro':
                return new Elementor_Pro_Adapter();

            case 'contact-form-db-divi':
                return new Premium_Only_Adapter('Divi Forms', function () {
                    return is_plugin_active('contact-form-db-divi/index.php');
                });

            case 'contact-form-cfdb7':
                return new Contact_Form_7_CFDB7_Adapter();

            case 'contact-form-7-flamingo':
                return new Contact_Form_7_Flamingo_Adapter();

            case 'formidable':
                return new Premium_Only_Adapter('Formidable', function () {
                    return is_plugin_active('formidable/formidable.php');
                });

            case 'memberpress':
                return new Premium_Only_Adapter('MemberPress', function () {
                    return is_plugin_active('memberpress/memberpress.php') && class_exists('MeprProduct') && class_exists('MeprTransaction');
                });

            case 'ws-form':
                return new Premium_Only_Adapter('WS Form', function () {
                    return class_exists('WS_Form') || class_exists('WS_Form_PRO');
                });

            // Add more adapters here as they are implemented

            default:
                return new WP_Error('handl-404', $form_plugin . " is not supported yet. Please contact with us");
        }
    }
}