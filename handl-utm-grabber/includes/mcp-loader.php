<?php

if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'mcp/mcp-signature-verifier.php';
require_once plugin_dir_path(__FILE__) . 'mcp/mcp-api-routes.php';

add_action('rest_api_init', function () {
    // REST requests don't load is_plugin_active(); same guard as v3's reports-loader.
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    \Handl\UtmrabberFree\MCP\API_Routes::init();
});

if (is_admin()) {
    require_once plugin_dir_path(__FILE__) . 'mcp/class-mcp-connection.php';
    new \Handl\UtmrabberFree\MCP\McpConnection();
}
