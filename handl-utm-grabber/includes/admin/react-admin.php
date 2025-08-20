<?php
namespace Handl\UtmrabberFree\Admin;

class Handl_React_Pages_Manager
{
    private $plugin_path;

    private $plugin_url;

    public function __construct()
    {
        $this->plugin_path = plugin_dir_path(__FILE__);
        $this->plugin_url  = plugin_dir_url(__FILE__);

        // Register admin page
        add_action('admin_menu', [$this, 'add_react_menu_pages'],1);

        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
    }

    public function add_react_menu_pages()
    {
        add_menu_page(
            'HandL UTM Grabber',
            'UTM',
            'manage_options',
            'handl-utm-grabber.php',
            [ $this, 'render_settings_page' ],
            get_icon_svg_handl(),
            '99.3875'
        );
        // How to create a menu page with link in the admin sidebar
        
        // add_submenu_page(
        //     'handl-utm-grabber.php',
        //     "Handl Test",
        //     "Handl Test",
        //     'manage_options',
        //     'handl_test',
        // );

        $analytics_menu_title = 'Analytics <span class="update-plugins" style="background-color: #0160BF;"><span class="update-count">premium</span></span>';
        add_submenu_page(
            'handl-utm-grabber.php',
            'Analytics', 
            $analytics_menu_title,
            'manage_options',
            'handl_analytics',
            [ $this, 'render_analytics_page' ],
        );

    }

    /**
     * Renders the container for the Home page React app.
     */
    public function render_settings_page()
    {
        $this->render_react_container('handl_utm_grabber');
    }
    public function render_analytics_page() {
        $this->render_react_container( 'handl_analytics' );
    }
    private function render_react_container($container_id)
    {
        printf(
            '<div style="display: contents;" id="handl-react-root"><div id="%s"></div></div>',
            esc_attr($container_id)
        );
    }

    /**
     * Conditionally enqueue React build assets on only the relevant admin pages.
     *
     * @param string $hook_suffix  The current page hook.
     */
    public function enqueue_admin_scripts($hook_suffix)
    {

        $allowed_hooks = [
            'toplevel_page_handl-utm-grabber',
            'utm_page_handl_analytics',
        ];
        if (! in_array($hook_suffix, $allowed_hooks, true)) {
            return;
        }


        $script_asset_path = $this->plugin_path . '../../client/build/index.asset.php';
        if (! file_exists($script_asset_path)) {
            return;
        }

        $script_asset = require $script_asset_path;

        wp_enqueue_script(
            'handl-react-main-script',
            $this->plugin_url . '../../client/build/index.js',
            array_merge(['wp-api-fetch'], $script_asset['dependencies']),
            $script_asset['version'],
            true
        );
        // Enqueue the runtime script if running hot refresh mode
        $runtime_script_path = $this->plugin_path . '../../client/build/runtime.asset.php';
        if (file_exists($runtime_script_path)) {
            $runtime_asset = require $runtime_script_path;
            wp_enqueue_script(
                'handl-react-runtime-script',
                $this->plugin_url . '../../client/build/runtime.js',
                $runtime_asset['dependencies'],
                $runtime_asset['version'],
                true
            );
        }

        wp_enqueue_style(
            'handl-react-main-style',
            $this->plugin_url . '../../client/build/index.css',
            array_filter(
                $script_asset['dependencies'],
                function ($handle) {
                    return wp_style_is($handle, 'registered');
                }
            ),
            $script_asset['version']
        );
        wp_localize_script('handl-react-main-script', 'wpAPIProps', [
            'ajax_url' => admin_url('admin-ajax.php'),
            // 'nonce'    => array(
            //     'license_nonce' => wp_create_nonce('license_nonce'),
            // ),
        ]);
        // wp_localize_script('handl-react-main-script', 'appProps', [
        //     'license_key' => get_option('license_key_handl-utm-grabber-v3'),
        //     'handl_active' => $GLOBALS['handl_active'] ? "true" : "false",
        // ]);
    }
}
