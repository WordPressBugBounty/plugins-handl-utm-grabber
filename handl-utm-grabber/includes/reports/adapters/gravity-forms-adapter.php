<?php
namespace Handl\UtmrabberFree\Reports;
/**
 * Gravity Forms Adapter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once dirname( __FILE__ ) . '/form-adapter-base.php';
use Handl\UtmrabberFree\Reports\Form_Adapter_Abstract;
use WP_Error;
use GFAPI;
/**
 * Gravity Forms adapter implementation
 */
class Gravity_Forms_Adapter extends Form_Adapter_Abstract {
    /**
     * Check if Gravity Forms is active and API is available
     *
     * @return bool
     */
    public function is_active() {
        return is_plugin_active('gravityforms/gravityforms.php') && class_exists('GFAPI');
    }
    
    /**
     * Get forms from Gravity Forms
     *
     * @return array|WP_Error Array of forms or WP_Error if plugin not active
     */
    public function get_forms() {
        if (!$this->is_active()) {
            return new WP_Error('handl-404', "Gravity Forms is not active");
        }
        
        $forms = GFAPI::get_forms();
        
        $forms_res = [];
        foreach ($forms as $form) {
            $forms_res[] = [
                "value" => $form['id'], 
                "name" => $form['title'] . " (" . $form['id'] . ")"
            ];
        }
        
        return $forms_res;
    }
    
    /**
     * Get entries from Gravity Forms
     *
     * @param array $form_ids Form IDs to get entries from
     * @param array $search_criteria Search criteria for entries
     * @return array|WP_Error Array of entries or WP_Error if plugin not active
     */
    public function get_entries($form_ids, $search_criteria) {
        if (!$this->is_active()) {
            return new WP_Error('handl-404', "Gravity Forms plugin is not active");
        }

        $fetched = $this->fetch_submissions($form_ids, $search_criteria, 1, 0);

        if (is_wp_error($fetched)) {
            return $fetched;
        }

        $entries_res = [];
        $form_fields = [];

        foreach ($fetched['rows'] as $entry) {
            $form_id = $entry["form_id"];

            if (!isset($form_fields[$form_id])) {
                $form = GFAPI::get_form($form_id);

                $cur_form_fields = [];
                foreach ($form["fields"] as $field) {
                    $cur_form_fields[$field["id"]] = $field["inputName"];
                }

                $form_fields[$form_id] = $cur_form_fields;
            }

            $cur_data = [];
            $cur_data['date'] = $entry["date_created"];
            foreach ($this->get_fields() as $field) {
                $field_index = array_search($field, $form_fields[$form_id]);
                $cur_data[$field] = isset($entry[$field_index]) ? $entry[$field_index] : "";
            }
            $entries_res[] = $cur_data;
        }

        return [
            'entries' => $entries_res,
            'field_labels' => $this->get_field_labels()
        ];
    }

    /**
     * Fetch raw Gravity Forms entries, paginated.
     *
     * @param array $form_ids
     * @param array $search_criteria
     * @param int $page
     * @param int $per_page
     * @return array|WP_Error
     */
    protected function fetch_submissions($form_ids, $search_criteria, $page, $per_page) {
        if (!$this->is_active()) {
            return new WP_Error('handl-404', "Gravity Forms plugin is not active");
        }

        $paging = $per_page > 0
            ? array('offset' => ($page - 1) * $per_page, 'page_size' => $per_page)
            : array('offset' => 0, 'page_size' => 0);

        $total = 0;
        $entries = GFAPI::get_entries($form_ids, $search_criteria, null, $paging, $total);

        if (is_wp_error($entries)) {
            return $entries;
        }

        return ['rows' => $entries, 'total' => (int) $total];
    }

    /**
     * Field id -> label map per form. Entries are keyed by numeric field IDs,
     * so labels are needed for interpretation.
     *
     * @param array $form_ids
     * @return array [ form_id => [ field_id => label ] ]
     */
    protected function get_field_label_map($form_ids) {
        $labels = [];

        foreach ($form_ids as $form_id) {
            $form = GFAPI::get_form($form_id);
            if (!$form || empty($form['fields'])) {
                continue;
            }

            $form_labels = [];
            foreach ($form['fields'] as $field) {
                if (isset($field['id'])) {
                    $form_labels[(string) $field['id']] = isset($field['label']) ? $field['label'] : '';
                }

                // Expand multi-input fields (e.g. name, address) keyed as "1.3"
                if (!empty($field['inputs']) && is_array($field['inputs'])) {
                    foreach ($field['inputs'] as $input) {
                        if (isset($input['id'])) {
                            $form_labels[(string) $input['id']] = isset($input['label']) ? $input['label'] : '';
                        }
                    }
                }
            }

            $labels[(string) $form_id] = $form_labels;
        }

        return $labels;
    }
} 