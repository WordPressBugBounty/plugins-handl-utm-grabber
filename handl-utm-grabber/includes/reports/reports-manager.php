<?php

namespace Handl\UtmrabberFree\Reports;


if (! defined('ABSPATH')) {
    exit;
}

require_once dirname(__FILE__) . '/form-adapter-factory.php';
use WP_Error;
use Handl\UtmrabberFree\Reports\Form_Adapter_Factory;

/**
 * Class to manage reports functionality
 */
class Reports_Manager
{
    /**
     * Get forms from the specified plugin
     *
     * @param string $form_plugin Form plugin identifier
     * @return array|WP_Error Array of forms or WP_Error if plugin not supported or no forms found
     */
    public function get_forms($form_plugin)
    {
        $adapter = Form_Adapter_Factory::get_adapter($form_plugin);

        if (is_wp_error($adapter)) {
            return $adapter;
        }

        return $adapter->get_forms();
    }

    /**
     * Get entries from the specified plugin and forms
     *
     * @param string $form_plugin Form plugin identifier
     * @param array $form_ids Form IDs to get entries from
     * @param array $search_criteria Search criteria for entries
     * @param int $limit Maximum number of entries to return. 0 = no limit.
     * @return array|WP_Error Array of entries or WP_Error if plugin not supported or no entries found
     */
    public function get_entries($form_plugin, $form_ids, $search_criteria, $limit = 75)
    {
        // Check for sandbox/test environment
        if (in_array(site_url(), ["https://handl-sandbox", "http://localhost/mywordpress"])) {
            $test_json = plugin_dir_path(dirname(dirname(__FILE__))) . "handl_report_insight_test.json";
            if (file_exists($test_json)) {
                $test_data = json_decode(file_get_contents($test_json), true);
                return [
                    "entries" => $test_data,
                    "field_labels" => array()
                ];
            }
        }

        $adapter = Form_Adapter_Factory::get_adapter($form_plugin);

        if (is_wp_error($adapter)) {
            return $adapter;
        }

        try {
            $entries_data = $adapter->get_entries($form_ids, $search_criteria);
        } catch (\Throwable $e) {
            return new WP_Error('handl-500', "Failed to fetch entries from {$form_plugin}: " . $e->getMessage());
        }

        if (is_wp_error($entries_data)) {
            return $entries_data;
        }

        if ($limit > 0 && isset($entries_data['entries'])) {
            $entries_data['entries'] = array_slice($entries_data['entries'], 0, $limit);
        }
        
        return $entries_data;
    }

    /**
     * Get raw, unstructured entries plus pagination metadata.
     *
     * Unlike get_entries(), this returns the submissions as the form plugin
     * stores them (all fields, no UTM normalization), paginated.
     *
     * @param string $form_plugin Form plugin identifier
     * @param array $form_ids Form IDs to get entries from
     * @param array $search_criteria Search criteria for entries
     * @param int $page 1-based page number
     * @param int $per_page Page size. 0 = no limit (all rows).
     * @return array|WP_Error ['entries' => array, 'pagination' => array] or WP_Error
     */
    public function get_full_entries($form_plugin, $form_ids, $search_criteria, $page, $per_page)
    {
        $adapter = Form_Adapter_Factory::get_adapter($form_plugin);

        if (is_wp_error($adapter)) {
            return $adapter;
        }

        try {
            return $adapter->get_full_entries($form_ids, $search_criteria, $page, $per_page);
        } catch (\Throwable $e) {
            return new WP_Error('handl-500', "Failed to fetch entries from {$form_plugin}: " . $e->getMessage());
        }
    }

    /**
     * Generate insight report
     *
     * @param string $form_plugin Form plugin identifier
     * @param array $form_ids Form IDs to get entries from
     * @param array $search_criteria Search criteria for entries
     * @return array Report data or error
     */
    public function generate_insight($form_plugin, $form_ids, $search_criteria)
    {
        $response = [];

        $report_name = $form_plugin . "-id-" . implode(",", $form_ids) . "-date-" . $search_criteria['start_date'] . "-" . $search_criteria['end_date'];
        $report_obj = $this->get_report_from_option("handl_report_" . $report_name);
        $available_forms = $this->get_forms($form_plugin);
        $form_names = array_map(function($form) {
            return $form['name'];
        }, array_filter($available_forms, function($form) use ($form_ids) {
            return in_array($form['value'], $form_ids);
        }));
        if (is_null($report_obj) || !$report_obj->option_value || !isset(maybe_unserialize($report_obj->option_value)['insight_report_parsed'])) {
            $entries_data = $this->get_entries($form_plugin, $form_ids, $search_criteria);

            if (!is_wp_error($entries_data)) {
                $entries_res = isset($entries_data['entries']) ? $entries_data['entries'] : array();
                
                if (!empty($entries_res)) {
                    $report_table = $this->object_to_table($entries_res);

                    $args = [
                        "body" => json_encode([
                            "question" => $report_table,
                            "report" => $report_name,
                            "license_key" => get_option('license_key_handl-utm-grabber-v3')
                        ]),
                        "timeout" => 900,
                        "headers" => [
                            "Content-Type" => "application/json"
                        ]
                    ];

                    $post_resp = wp_remote_post("https://report-insight.utmgrabber.com", $args);

                    if (is_wp_error($post_resp)) {
                        return new WP_Error(
                            'remote_post_error',
                            $post_resp->get_error_code() . ": " . $post_resp->get_error_message(),
                            array('status' => 500)
                        );
                    } else {
                        $response_code = wp_remote_retrieve_response_code($post_resp);
                        $post_response_body = wp_remote_retrieve_body($post_resp);
                        if ($response_code !== 200) {
                            $response_data = json_decode($post_response_body, true);
                            $error_message = isset($response_data['message']) 
                                ? $response_data['message'] 
                                : 'API returned status code: ' . $response_code;
                                
                            return new WP_Error(
                                'api_error',
                                $error_message,
                                array('status' => $response_code)
                            );
                        }
                    

                        $response_json = json_decode($post_response_body, true);
                        $data = [
                            "selected_form_plugin" => $form_plugin,
                            "selected_form_ids" => $form_ids,
                            "selected_form_names" => $form_names,
                            "selected_start_date" => $search_criteria['start_date'],
                            "selected_end_date" => $search_criteria['end_date'],
                            "insight_report_parsed" => $post_response_body,
                            "created_at" =>gmdate(DATE_ATOM),
                        ];

                        update_option("handl_report_" . $report_name, $data, false);

                        $report_obj = $this->get_report_from_option("handl_report_" . $report_name);

                        $response = [
                            "success" => true,
                            "report_name" => "handl_report_" . $report_name,
                            "insight_report_parsed" => $response_json,
                            "entries" => $entries_res
                        ];
                    }
                } else {
                    return new WP_Error(
                        'no_data',
                        'No data found',
                        array('status' => 404)
                    );
                }
            } else {
                return new WP_Error(
                    'entries_error',
                    $entries_data->get_error_message(),
                    array('status' => 400)
                );
            }
        } else {
            // Return existing report
            $report_data = maybe_unserialize($report_obj->option_value);
            $response = [
                "success" => true,
                "report_name" => "handl_report_" . $report_name,
                "insight_report_parsed" => json_decode($report_data['insight_report_parsed'], true),
                
            ];

        }

        if (isset($report_obj->option_id)) {
            $response["report_id"] = $report_obj->option_id;
        }

        if (!isset($response["error"])) {
            $response["success"] = true;
        }

        return $response;
    }

    /**
     * Delete a report
     *
     * @param string $report_id Report ID to delete
     * @return array Success or error response
     */
    public function delete_report($report_id)
    {
        global $wpdb;
        $response = [];

        if (empty($report_id)) {
            return new WP_Error(
                'no_report_id',
                'No report ID provided',
                array('status' => 400)
            );
        }

        $report = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT option_name FROM {$wpdb->prefix}options WHERE option_id = %d",
                $report_id
            )
        );

        if (!$report) {
            return new WP_Error(
                'report_not_found',
                'Report not found',
                array('status' => 404)
            );
        }

        delete_option($report->option_name);

        $response["success"] = true;
        $response["message"] = "Report deleted successfully";

        return $response;
    }

    /**
     * Convert entries object to table format
     *
     * @param array $entries Entries to convert
     * @return string Table formatted string
     */
    private function object_to_table($entries)
    {
        $table = "";
        foreach ($entries as $id => $entry) {
            if ($id == 0) {
                $table .= implode("\t", array_keys($entry)) . "\n";
            }
            $table .= implode("\t", array_values($entry)) . "\n";
        }

        return $table;
    }

    /**
     * Get report from WordPress options
     *
     * @param string $report_name Report name to retrieve
     * @return object|null Report object or null if not found
     */
    private function get_report_from_option($report_name)
    {
        global $wpdb;
        return $wpdb->get_row("SELECT * FROM {$wpdb->prefix}options WHERE option_name = '$report_name'", OBJECT);
    }

    /**
     * Get all saved reports
     *
     * @return array Array of saved reports with their details
     */
    public function get_saved_reports()
    {
        global $wpdb;
        $saved_reports = [];

        $all_options = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}options WHERE option_name LIKE 'handl_report_%'", OBJECT);

        foreach ($all_options as $opt) {
            preg_match('/^handl_report_(.*)-id-(.*)-date-(.*)/', $opt->option_name, $report_name_parts);

            if (count($report_name_parts) >= 4) {
                [$nothing_, $form_name, $form_ids, $date_range,] = $report_name_parts;
                $report_data = maybe_unserialize($opt->option_value);
                $saved_reports[] = [
                    'id' => $opt->option_id,
                    'name' => $opt->option_name,
                    'display_name' => ucwords(preg_replace('/-/', " ", $form_name)) . " " . $form_ids . " " . $date_range,
                    'selected_form_plugin' => $report_data['selected_form_plugin'],
                    'selected_form_ids' => $report_data['selected_form_ids'],
                    'selected_start_date' => $report_data['selected_start_date'],
                    'selected_end_date' => $report_data['selected_end_date'],
                    'selected_form_names' => $report_data['selected_form_names'],
                    'created_at' => isset($report_data['created_at']) ? $report_data['created_at'] : null
                ];
            }
        }

        // Sort reports by creation date (newest first)
        usort($saved_reports, function($a, $b) {
            $date_a = !empty($a['created_at']) ? strtotime($a['created_at']) : 0;
            $date_b = !empty($b['created_at']) ? strtotime($b['created_at']) : 0;
            return $date_b - $date_a;
        });

        return $saved_reports;
    }

    /**
     * Get a specific report by ID
     *
     * @param int $report_id Report ID to retrieve
     * @return array|WP_Error Report data or WP_Error if not found
     */
    public function get_report($report_id)
    {
        global $wpdb;

        $report_opt = $wpdb->get_row("SELECT * FROM {$wpdb->prefix}options WHERE option_id = " . intval($report_id), OBJECT);

        if (!$report_opt) {
            return new WP_Error('report_not_found', "Report not found", ['status' => 404]);
        }

        $report_data = maybe_unserialize($report_opt->option_value);

        if (!$report_data) {
            return new WP_Error('report_data_error', "Error retrieving report data", ['status' => 500]); 
        }

        // Add report metadata
        $report_data['report_id'] = $report_opt->option_id;
        $report_data['report_name'] = $report_opt->option_name;

        // Extract form name, IDs and date range from report name
        preg_match('/^handl_report_(.*)-id-(.*)-date-(.*)/', $report_opt->option_name, $report_name_parts);

        if (count($report_name_parts) >= 4) {
            [$nothing_, $form_name, $form_ids, $date_range] = $report_name_parts;
            $report_data['display_name'] = ucwords(preg_replace('/-/', " ", $form_name)) . " " . $form_ids . " " . $date_range;
            $report_data['insight_report_parsed'] = json_decode($report_data['insight_report_parsed'], true);
        }

        return $report_data;
    }

    /**
     * Get available form plugins and their activation status
     *
     * @return array List of available form plugins with their name, key, and activation status
     */
    public function get_available_plugins()
    {
        $supported_plugins = array(
            'gravity-form' => 'Gravity Forms',
            'fluent-forms' => 'Fluent Forms',
            'ninja-forms' => 'Ninja Forms',
            'wpforms' => 'WPForms',
            'elementor-pro' => 'Elementor Pro',
            'woocommerce' => 'WooCommerce',
            'contact-form-db-divi' => 'Divi Forms',
            'contact-form-cfdb7' => 'Contact Form 7 (CFDB7)',
            'contact-form-7-flamingo' => 'Contact Form 7 (Flamingo)',
            'formidable' => 'Formidable',
            'memberpress' => 'MemberPress',
            'ws-form' => 'WS Form',
            // Add more supported plugins here as they are implemented in Form_Adapter_Factory
        );
        
        $available_plugins = array();
        $active_plugins = array();
        $inactive_plugins = array();
        
        foreach ($supported_plugins as $plugin_key => $plugin_name) {
            $adapter = Form_Adapter_Factory::get_adapter($plugin_key);
            $is_active = !is_wp_error($adapter) && $adapter->is_active();

            $plugin_data = array(
                'pluginName' => $plugin_name,
                'key' => $plugin_key,
                'isActive' => $is_active
            );

            if ($adapter instanceof Premium_Only_Adapter) {
                $plugin_data['premiumOnly'] = true;
            }
            
            if ($is_active) {
                $active_plugins[] = $plugin_data;
            } else {
                $inactive_plugins[] = $plugin_data;
            }
        }
        
        $available_plugins = array_merge($active_plugins, $inactive_plugins);
        return $available_plugins;
    }
}
