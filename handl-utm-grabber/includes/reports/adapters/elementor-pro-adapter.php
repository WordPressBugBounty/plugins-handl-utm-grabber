<?php
namespace Handl\UtmrabberFree\Reports;
/**
 * Elementor Pro Adapter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once dirname( __FILE__ ) . '/form-adapter-base.php';
use Handl\UtmrabberFree\Reports\Form_Adapter_Abstract;
use WP_Error;

/**
 * Elementor Pro adapter implementation
 */
class Elementor_Pro_Adapter extends Form_Adapter_Abstract {
    /**
     * Check if Elementor Pro is active and API is available
     *
     * @return bool
     */
    public function is_active() {
        return is_plugin_active('elementor-pro/elementor-pro.php');
    }
    
    /**
     * Get forms from Elementor Pro
     *
     * @return array|WP_Error Array of forms or WP_Error if plugin not active
     */
    public function get_forms() {
        if (!$this->is_active()) {
            return new WP_Error('handl-404', "Elementor Pro is not active");
        }
        
        global $wpdb;
        $forms = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}postmeta WHERE meta_key = '__elementor_forms_snapshot'", OBJECT);
        
        $forms_res = [];
        foreach ($forms as $form) {
            $cur_values = json_decode($form->meta_value)[0];
            $forms_res[] = [
                "value" => $form->post_id, 
                "name" => $cur_values->name . " (" . $cur_values->id . ")"
            ];
        }
        
        return $forms_res;
    }
    
    /**
     * Get entries from Elementor Pro
     *
     * @param array $form_ids Form IDs to get entries from
     * @param array $search_criteria Search criteria for entries
     * @return array|WP_Error Array of entries or WP_Error if plugin not active
     */
    public function get_entries($form_ids, $search_criteria) {
        if (!$this->is_active()) {
            return new WP_Error('handl-404', "Elementor Pro plugin is not active");
        }

        $fetched = $this->fetch_submissions($form_ids, $search_criteria, 1, 0);

        if (is_wp_error($fetched)) {
            return $fetched;
        }

        $entries_res = [];

        foreach ($fetched['rows'] as $row) {
            $cur_data = [];
            $cur_data['date'] = $row["date"];

            foreach ($row['fields'] as $key => $value) {
                if (in_array($key, $this->get_fields())) {
                    $cur_data[$key] = $value;
                }
            }

            $entries_res[] = $cur_data;
        }

        return [
            'entries' => $entries_res,
            'field_labels' => $this->get_field_labels()
        ];
    }

    /**
     * Fetch raw Elementor Pro submissions, paginated.
     *
     * @param array $form_ids
     * @param array $search_criteria
     * @param int $page
     * @param int $per_page
     * @return array|WP_Error
     */
    protected function fetch_submissions($form_ids, $search_criteria, $page, $per_page) {
        if (!$this->is_active()) {
            return new WP_Error('handl-404', "Elementor Pro plugin is not active");
        }

        global $wpdb;

        $start_date = $search_criteria['start_date'];
        $end_date = $search_criteria['end_date'];
        $placeholders = implode(',', array_fill(0, count($form_ids), '%d'));

        $where_args = array_merge(array_map('intval', $form_ids), [$start_date, $end_date]);

        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}e_submissions 
                WHERE post_id IN ($placeholders) 
                AND created_at BETWEEN %s AND DATE_ADD(%s, INTERVAL 1 DAY)",
                $where_args
            )
        );

        $sql = "SELECT * FROM {$wpdb->prefix}e_submissions 
                WHERE post_id IN ($placeholders) 
                AND created_at BETWEEN %s AND DATE_ADD(%s, INTERVAL 1 DAY)
                ORDER BY id DESC";
        $query_args = $where_args;

        if ($per_page > 0) {
            $sql .= " LIMIT %d OFFSET %d";
            $query_args = array_merge($query_args, [$per_page, ($page - 1) * $per_page]);
        }

        $entries = $wpdb->get_results($wpdb->prepare($sql, $query_args), ARRAY_A);

        $rows = [];
        foreach ($entries as $entry) {
            $values = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$wpdb->prefix}e_submissions_values WHERE submission_id = %d", intval($entry["id"])),
                ARRAY_A
            );

            $fields = [];
            foreach ($values as $field) {
                $fields[$field["key"]] = $field["value"];
            }

            $rows[] = [
                'date'    => $entry["created_at"],
                'form_id' => $entry["post_id"],
                'fields'  => $fields,
            ];
        }

        return ['rows' => $rows, 'total' => $total];
    }
} 