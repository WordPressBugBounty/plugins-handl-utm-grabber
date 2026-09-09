<?php

namespace Handl\UtmrabberFree\MCP;

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(dirname(__FILE__)) . '/utils/class-nudge-tracker.php';
require_once dirname(dirname(__FILE__)) . '/notices/class-notice-manager.php';
require_once dirname(dirname(__FILE__)) . '/onboarding/class-onboarding-manager.php';

use Handl\UtmrabberFree\PluginUtils\Nudge_Tracker;
use Handl\UtmrabberFree\Notices\Handl_Notice_Manager;
use Handl\UtmrabberFree\Onboarding\Handl_Onboarding_Manager;

class McpConnection
{

    // const MCP_BASE_URL = 'http://localhost:4000';
    const MCP_BASE_URL = 'https://api.utmgrabber.com';
    const OPTION_KEY = 'handl_mcp_credentials_free';

    public function __construct()
    {

        add_action('wp_ajax_handl_mcp_get_status', array($this, 'ajaxGetStatus'));
        add_action('wp_ajax_handl_mcp_connect_site', array($this, 'ajaxConnectSite'));
        add_action('wp_ajax_handl_mcp_disconnect_site', array($this, 'ajaxDisconnectSite'));
        add_action('wp_ajax_handl_mcp_get_usage', array($this, 'ajaxGetUsage'));

        // The SPA's hash routes are invisible to PHP, so ajaxGetStatus marks
        // the nudge seen instead of premium's watch().
        add_filter('handl_react_admin_localize', array($this, 'localizeNudgeState'));
        Nudge_Tracker::get_instance()->badge_menu('handl-utm-grabber.php', 'mcp');

        Handl_Notice_Manager::get_instance()->register('mcp_skill_launch', array(
            'title'   => 'Did you know? You can ask AI about your leads',
            'message' => 'UTM Grabber connects to Claude, Cursor, and Codex over MCP, and our free Reports Skill turns your UTM data into branded reports, forecasts, and plain English answers.',
            'actions' => array(
                array('label' => 'Set it up', 'url' => admin_url('admin.php?page=handl-utm-grabber.php#/mcp'), 'primary' => true),
                array('label' => 'See what it can do', 'url' => 'https://utmgrabber.com/skills/utm-grabber-reports/', 'target' => '_blank'),
            ),
        ));
    }

    public function localizeNudgeState($props)
    {
        $props['mcp_nudge_active'] = Nudge_Tracker::get_instance()->is_active('mcp');
        return $props;
    }

    private function buildMcpUrl($site_secret)
    {
        return self::MCP_BASE_URL . '/http/mcp/utmgrabber-report/free?access_code=' . $site_secret;
    }

    public function ajaxGetUsage()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access. Admin privileges required.');
            return;
        }

        $credentials = get_option(self::OPTION_KEY);

        if (empty($credentials) || !is_array($credentials) || empty($credentials['site_id']) || empty($credentials['site_secret'])) {
            wp_send_json_error('Not connected.');
            return;
        }

        $response = wp_remote_post(self::MCP_BASE_URL . '/http/mcp/usage/free', array(
            'body' => json_encode(array(
                'site_id' => $credentials['site_id'],
                'site_secret' => $credentials['site_secret'],
            )),
            'timeout' => 15,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            wp_send_json_error('Usage is unavailable right now.');
            return;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body) || !isset($body['day_pct'], $body['month_pct'])) {
            wp_send_json_error('Usage is unavailable right now.');
            return;
        }

        wp_send_json_success(array(
            'day_pct' => (int) $body['day_pct'],
            'day_resets_at' => $body['day_resets_at'] ?? null,
            'month_pct' => (int) $body['month_pct'],
            'month_resets_at' => $body['month_resets_at'] ?? null,
        ));
    }

    public function ajaxGetStatus()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access. Admin privileges required.');
            return;
        }

        Nudge_Tracker::get_instance()->mark_seen('mcp');

        $data = array();

        $credentials = get_option(self::OPTION_KEY);

        if (empty($credentials) || !is_array($credentials) || empty($credentials['site_secret'])) {
            $data['connected'] = false;
            wp_send_json_success($data);
            return;
        }

        $data['connected'] = true;
        $data['mcp_url'] = $this->buildMcpUrl($credentials['site_secret']);
        $data['connected_at'] = $credentials['connected_at'] ?? null;
        $data['email'] = $credentials['email'] ?? null;

        wp_send_json_success($data);
    }

    public function ajaxConnectSite()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access. Admin privileges required.');
            return;
        }

        $existing = get_option(self::OPTION_KEY);

        $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';

        if (empty($email) && is_array($existing) && !empty($existing['email'])) {
            $email = $existing['email'];
        }

        if (!is_email($email)) {
            wp_send_json_error('Please enter a valid email address.');
            return;
        }

        $site_url = site_url();

        $response = wp_remote_post(self::MCP_BASE_URL . '/http/mcp/connect-site/free', array(
            'body' => json_encode(array(
                'email' => $email,
                'site_url' => $site_url,
            )),
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
        ));

        if (is_wp_error($response)) {
            wp_send_json_error('Failed to connect to MCP server: ' . $response->get_error_message());
            return;
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status_code === 401 || $status_code === 403) {
            wp_send_json_error($body['error'] ?? 'Could not connect this site. Please try again.');
            return;
        }

        if ($status_code === 400) {
            $message = $body['error'] ?? 'Bad request.';
            if (!empty($body['details'])) {
                $detail_messages = array();
                foreach ($body['details'] as $field => $errors) {
                    $detail_messages[] = $field . ': ' . implode(', ', $errors);
                }
                $message .= ' ' . implode('; ', $detail_messages);
            }
            wp_send_json_error($message);
            return;
        }

        if ($status_code !== 200 || empty($body['site_id']) || empty($body['site_secret'])) {
            wp_send_json_error('Unexpected response from MCP server.');
            return;
        }

        $credentials = array(
            'site_id' => $body['site_id'],
            'site_secret' => $body['site_secret'],
            'site_url' => $body['site_url'],
            'connected_at' => $body['connected_at'],
            'email' => $email,
        );

        update_option(self::OPTION_KEY, $credentials);

        // Fire-and-forget signup + SendGrid sync; mcp_connected keys the
        // auto-enrolled MCP tips sequence.
        Handl_Onboarding_Manager::enroll($email, 'plugin_free_mcp', array('mcp_connected' => true));

        // Connect is create-only backend-side; retire the old credential.
        if (is_array($existing) && !empty($existing['site_id']) && !empty($existing['site_secret'])
            && $existing['site_secret'] !== $credentials['site_secret']) {
            wp_remote_post(self::MCP_BASE_URL . '/http/mcp/disconnect-site/free', array(
                'blocking' => false,
                'timeout' => 5,
                'headers' => array('Content-Type' => 'application/json'),
                'body' => json_encode(array(
                    'site_id' => $existing['site_id'],
                    'site_secret' => $existing['site_secret'],
                )),
            ));
        }

        wp_send_json_success(array(
            'connected' => true,
            'mcp_url' => $this->buildMcpUrl($credentials['site_secret']),
            'connected_at' => $credentials['connected_at'],
        ));
    }
    public function ajaxDisconnectSite()
    {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized access. Admin privileges required.');
            return;
        }

        $credentials = get_option(self::OPTION_KEY);

        if (empty($credentials) || !is_array($credentials) || empty($credentials['site_id']) || empty($credentials['site_secret'])) {
            // Already disconnected, clear option to be safe and return success
            delete_option(self::OPTION_KEY);
            wp_send_json_success(array('disconnected' => true));
            return;
        }

        $response = wp_remote_post(self::MCP_BASE_URL . '/http/mcp/disconnect-site/free', array(
            'body' => json_encode(array(
                'site_id' => $credentials['site_id'],
                'site_secret' => $credentials['site_secret'],
            )),
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
            ),
        ));

        if (is_wp_error($response)) {
            wp_send_json_error('Failed to reach MCP server: ' . $response->get_error_message());
            return;
        }

        $status_code = wp_remote_retrieve_response_code($response);

        if ($status_code !== 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            wp_send_json_error($body['error'] ?? 'Failed to disconnect from MCP server.');
            return;
        }

        delete_option(self::OPTION_KEY);

        wp_send_json_success(array('disconnected' => true));
    }
}
