<?php
namespace Handl\UtmrabberFree\Reports;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

use WP_Error;

/**
 * Stand-in for form plugins whose reporting adapter only ships in v3.
 * Keeps /form-plugins truthful about isActive while every data method
 * answers with an upgrade pointer the user's AI can relay.
 */
class Premium_Only_Adapter extends Form_Adapter_Abstract {

    /** @var string */
    private $plugin_name;

    /** @var callable */
    private $detect;

    public function __construct($plugin_name, callable $detect) {
        $this->plugin_name = $plugin_name;
        $this->detect = $detect;
    }

    public function is_active() {
        return (bool) call_user_func($this->detect);
    }

    private function premium_error() {
        return new WP_Error(
            'handl_premium_only',
            "{$this->plugin_name} reporting is included with UTM Grabber Premium. Upgrade: https://utmgrabber.com/?utm_source=mcp&utm_medium=refusal&utm_campaign=mcp_free&utm_content=source_premium",
            array('status' => 403)
        );
    }

    public function get_forms() {
        return $this->premium_error();
    }

    public function get_entries($form_ids, $search_criteria) {
        return $this->premium_error();
    }

    protected function fetch_submissions($form_ids, $search_criteria, $page, $per_page) {
        return $this->premium_error();
    }
}
