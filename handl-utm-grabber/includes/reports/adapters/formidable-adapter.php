<?php
namespace Handl\UtmrabberFree\Reports;
/**
 * Formidable Forms Adapter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once dirname( __FILE__ ) . '/form-adapter-base.php';
require_once dirname( __FILE__ ) . '/../../integrations/class-integration.php';
require_once dirname( __FILE__ ) . '/../../integrations/formidable/class-formidable-integration.php';
use Handl\UtmrabberFree\Reports\Form_Adapter_Abstract;
use Handl\UtmrabberFree\Integrations\Formidable_Integration;
use WP_Error;

/**
 * Formidable Forms adapter implementation
 */
class Formidable_Forms_Adapter extends Form_Adapter_Abstract {
    /**
     * Check if Formidable Forms is active
     *
     * @return bool
     */
    public function is_active() {
        return is_plugin_active('formidable/formidable.php');
    }
    
    /**
     * Get forms from Formidable Forms
     *
     * @return array|WP_Error Array of forms or WP_Error if plugin not active
     */
    public function get_forms() {
        if (!$this->is_active()) {
            return new WP_Error('handl-404', "Formidable Forms is not active");
        }
        
        global $wpdb;
        $forms = $wpdb->get_results("SELECT id, name FROM {$wpdb->prefix}frm_forms WHERE is_template=0", OBJECT);
        
        $forms_res = [];
        foreach ($forms as $form) {
            $forms_res[] = [
                "value" => $form->id, 
                "name" => $form->name . " (" . $form->id . ")"
            ];
        }
        
        return $forms_res;
    }
    
    /**
     * Get entries from Formidable Forms
     *
     * @param array $form_ids Form IDs to get entries from
     * @param array $search_criteria Search criteria for entries
     * @return array|WP_Error Array of entries or WP_Error if plugin not active
     */
    public function get_entries($form_ids, $search_criteria) {
        if (!$this->is_active()) {
            return new WP_Error('handl-404', "Formidable Forms plugin is not active");
        }

        $fetched = $this->fetch_submissions($form_ids, $search_criteria, 1, 0);

        if (is_wp_error($fetched)) {
            return $fetched;
        }

        global $wpdb;
        $entries_res = [];
        $form_id_2_fields = [];

        foreach ($fetched['rows'] as $row) {
            $cur_data = [];
            $cur_data['date'] = $row["date"];
            $form_id = $row["form_id"];

            if (!isset($form_id_2_fields[$form_id])) {
                $utm_fields_arr = $wpdb->get_results(
                    $wpdb->prepare("SELECT * FROM {$wpdb->prefix}frm_fields WHERE form_id = %d", intval($form_id)),
                    ARRAY_A
                );

                $utm_fields_obj = [];
                foreach ($utm_fields_arr as $utm_field) {
                    $param = Formidable_Integration::param_for_key($utm_field["field_key"]);

                    if ($param !== null && in_array($param, $this->get_fields(), true)) {
                        $utm_fields_obj[$utm_field['id']] = $param;
                    } elseif ($utm_field["type"] == "email") {
                        $utm_fields_obj[$utm_field['id']] = "email";
                    }
                }

                $form_id_2_fields[$form_id] = $utm_fields_obj;
            }

            $utm_fields = $form_id_2_fields[$form_id];

            foreach ($row['fields'] as $field_id => $meta_value) {
                if (!isset($utm_fields[$field_id])) {
                    continue;
                }
                $cur_key = $utm_fields[$field_id];
                if (in_array($cur_key, $this->get_fields())) {
                    $cur_data[$cur_key] = $meta_value;
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
     * Fetch raw Formidable submissions, paginated.
     *
     * @param array $form_ids
     * @param array $search_criteria
     * @param int $page
     * @param int $per_page
     * @return array|WP_Error
     */
    protected function fetch_submissions($form_ids, $search_criteria, $page, $per_page) {
        if (!$this->is_active()) {
            return new WP_Error('handl-404', "Formidable Forms plugin is not active");
        }

        global $wpdb;

        $start_date = $search_criteria['start_date'];
        $end_date = $search_criteria['end_date'];
        $placeholders = implode(',', array_fill(0, count($form_ids), '%d'));

        $where_args = array_merge([$start_date, $end_date], array_map('intval', $form_ids));

        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}frm_items 
                WHERE created_at BETWEEN %s AND DATE_ADD(%s, INTERVAL 1 DAY) 
                AND form_id IN ($placeholders)",
                $where_args
            )
        );

        $sql = "SELECT * FROM {$wpdb->prefix}frm_items 
                WHERE created_at BETWEEN %s AND DATE_ADD(%s, INTERVAL 1 DAY) 
                AND form_id IN ($placeholders)
                ORDER BY id DESC";
        $query_args = $where_args;

        if ($per_page > 0) {
            $sql .= " LIMIT %d OFFSET %d";
            $query_args = array_merge($query_args, [$per_page, ($page - 1) * $per_page]);
        }

        $entries = $wpdb->get_results($wpdb->prepare($sql, $query_args), ARRAY_A);

        $rows = [];
        foreach ($entries as $entry) {
            $metas = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT field_id, meta_value FROM {$wpdb->prefix}frm_item_metas WHERE item_id = %d",
                    intval($entry["id"])
                ),
                ARRAY_A
            );

            $fields = [];
            foreach ($metas as $meta) {
                $fields[$meta["field_id"]] = $meta["meta_value"];
            }

            $rows[] = [
                'date'    => $entry["created_at"],
                'form_id' => $entry["form_id"],
                'item_id' => $entry["id"],
                'fields'  => $fields,
            ];
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * @param array $form_ids
     * @return array [ form_id => [ field_id => label ] ]
     */
    protected function get_field_label_map($form_ids) {
        if (empty($form_ids)) {
            return [];
        }

        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($form_ids), '%d'));
        $fields = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, form_id, name FROM {$wpdb->prefix}frm_fields WHERE form_id IN ($placeholders)",
                array_map('intval', $form_ids)
            ),
            ARRAY_A
        );

        $labels = [];
        foreach ($fields as $field) {
            $labels[(string) $field['form_id']][(string) $field['id']] = $field['name'];
        }

        return $labels;
    }
} 