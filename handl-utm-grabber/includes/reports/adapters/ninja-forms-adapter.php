<?php
namespace Handl\UtmrabberFree\Reports;
/**
 * Ninja Forms Adapter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once dirname( __FILE__ ) . '/form-adapter-base.php';
use Handl\UtmrabberFree\Reports\Form_Adapter_Abstract;
use WP_Error;

/**
 * Ninja Forms adapter implementation
 */
class Ninja_Forms_Adapter extends Form_Adapter_Abstract {
    /**
     * Check if Ninja Forms is active
     *
     * @return bool
     */
    public function is_active() {
        return is_plugin_active('ninja-forms/ninja-forms.php');
    }
    
    /**
     * Get forms from Ninja Forms
     *
     * @return array|WP_Error Array of forms or WP_Error if plugin not active
     */
    public function get_forms() {
        if (!$this->is_active()) {
            return new WP_Error('handl-404', "Ninja Forms is not active");
        }
        
        global $wpdb;
        $forms = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}nf3_forms", OBJECT);
        
        $forms_res = [];
        foreach ($forms as $form) {
            $forms_res[] = [
                "value" => $form->id, 
                "name" => $form->title . " (" . $form->id . ")"
            ];
        }
        
        return $forms_res;
    }
    
    /**
     * Get entries from Ninja Forms
     *
     * @param array $form_ids Form IDs to get entries from
     * @param array $search_criteria Search criteria for entries
     * @return array|WP_Error Array of entries or WP_Error if plugin not active
     */
    public function get_entries($form_ids, $search_criteria) {
        if (!$this->is_active()) {
            return new WP_Error('handl-404', "Ninja Forms plugin is not active");
        }

        $fetched = $this->fetch_submissions($form_ids, $search_criteria, 1, 0);

        if (is_wp_error($fetched)) {
            return $fetched;
        }

        global $wpdb;
        $entries_res = [];
        $form_id_2_fields = [];

        foreach ($fetched['rows'] as $row) {
            $form_id = $row["form_id"];

            // Cache field mapping for performance
            if (!isset($form_id_2_fields[$form_id])) {
                $fields_arr = $wpdb->get_results(
                    $wpdb->prepare("SELECT * FROM {$wpdb->prefix}nf3_fields WHERE parent_id = %d", intval($form_id)),
                    ARRAY_A
                );

                $field_mapping = [];
                foreach ($fields_arr as $field) {
                    $default_value_clean = str_replace(array('{', '}', 'handl:'), '', $field["default_value"]);
                    if (in_array($default_value_clean, $this->get_fields())) {
                        $field_mapping[$field['id']] = $default_value_clean;
                    } elseif ($field["type"] == "email") {
                        $field_mapping[$field['id']] = "email";
                    }
                }

                $form_id_2_fields[$form_id] = $field_mapping;
            }

            $field_mapping = $form_id_2_fields[$form_id];

            // Build entry data
            $entry_data = [];
            $entry_data['date'] = $row["date"];

            foreach ($row['fields'] as $field_id => $value) {
                if (!isset($field_mapping[$field_id])) {
                    continue;
                }
                $field_key = $field_mapping[$field_id];
                if (in_array($field_key, $this->get_fields())) {
                    $entry_data[$field_key] = $value;
                }
            }

            // Ensure all expected fields are present (even if empty)
            foreach ($this->get_fields() as $field) {
                if (!isset($entry_data[$field])) {
                    $entry_data[$field] = "";
                }
            }

            $entries_res[] = $entry_data;
        }

        return [
            'entries' => $entries_res,
            'field_labels' => $this->get_field_labels()
        ];
    }

    /**
     * Fetch raw Ninja Forms submissions, paginated.
     *
     * Form filtering is done at the SQL level (JOIN on the _form_id postmeta)
     * so that LIMIT/OFFSET and COUNT(*) operate on the correct row set.
     *
     * @param array $form_ids
     * @param array $search_criteria
     * @param int $page
     * @param int $per_page
     * @return array|WP_Error
     */
    protected function fetch_submissions($form_ids, $search_criteria, $page, $per_page) {
        if (!$this->is_active()) {
            return new WP_Error('handl-404', "Ninja Forms plugin is not active");
        }

        global $wpdb;

        $start_date = $search_criteria['start_date'];
        $end_date = $search_criteria['end_date'];
        $form_placeholders = implode(',', array_fill(0, count($form_ids), '%s'));

        $where_args = array_merge([$start_date, $end_date], array_map('strval', $form_ids));

        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}posts p 
                INNER JOIN {$wpdb->prefix}postmeta pm ON pm.post_id = p.ID 
                WHERE p.post_type = 'nf_sub' 
                AND p.post_date BETWEEN %s AND DATE_ADD(%s, INTERVAL 1 DAY) 
                AND pm.meta_key = '_form_id' 
                AND pm.meta_value IN ($form_placeholders)",
                $where_args
            )
        );

        $sql = "SELECT p.ID, p.post_date, pm.meta_value AS form_id 
                FROM {$wpdb->prefix}posts p 
                INNER JOIN {$wpdb->prefix}postmeta pm ON pm.post_id = p.ID 
                WHERE p.post_type = 'nf_sub' 
                AND p.post_date BETWEEN %s AND DATE_ADD(%s, INTERVAL 1 DAY) 
                AND pm.meta_key = '_form_id' 
                AND pm.meta_value IN ($form_placeholders)
                ORDER BY p.ID DESC";
        $query_args = $where_args;

        if ($per_page > 0) {
            $sql .= " LIMIT %d OFFSET %d";
            $query_args = array_merge($query_args, [$per_page, ($page - 1) * $per_page]);
        }

        $submissions = $wpdb->get_results($wpdb->prepare($sql, $query_args), ARRAY_A);

        $rows = [];
        foreach ($submissions as $submission) {
            $field_values = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT meta_key, meta_value FROM {$wpdb->prefix}postmeta 
                    WHERE post_id = %d AND meta_key LIKE %s",
                    intval($submission["ID"]),
                    $wpdb->esc_like('_field_') . '%'
                ),
                ARRAY_A
            );

            $fields = [];
            foreach ($field_values as $field_value) {
                $field_id = (int) str_replace("_field_", "", $field_value["meta_key"]);
                $fields[$field_id] = $field_value["meta_value"];
            }

            $rows[] = [
                'date'    => $submission["post_date"],
                'form_id' => $submission["form_id"],
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
                "SELECT id, parent_id, label FROM {$wpdb->prefix}nf3_fields WHERE parent_id IN ($placeholders)",
                array_map('intval', $form_ids)
            ),
            ARRAY_A
        );

        $labels = [];
        foreach ($fields as $field) {
            $labels[(string) $field['parent_id']][(string) $field['id']] = $field['label'];
        }

        return $labels;
    }
} 