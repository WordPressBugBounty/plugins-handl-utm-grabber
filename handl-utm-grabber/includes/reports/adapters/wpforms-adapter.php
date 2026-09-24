<?php
namespace Handl\UtmrabberFree\Reports;
/**
 * WPForms Adapter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once dirname( __FILE__ ) . '/form-adapter-base.php';
require_once dirname( __FILE__ ) . '/../../integrations/class-integration.php';
require_once dirname( __FILE__ ) . '/../../integrations/wpforms/class-wpforms-integration.php';
use Handl\UtmrabberFree\Reports\Form_Adapter_Abstract;
use Handl\UtmrabberFree\Integrations\WPForms_Integration;
use WP_Error;

/**
 * WPForms adapter implementation (Pro only: entries exist in Pro)
 */
class WPForms_Adapter extends Form_Adapter_Abstract {
    /**
     * Check if WPForms Pro is active
     *
     * @return bool
     */
    public function is_active() {
        return is_plugin_active('wpforms/wpforms.php') && function_exists('wpforms');
    }

    /**
     * Get forms from WPForms
     *
     * @return array|WP_Error Array of forms or WP_Error if plugin not active
     */
    public function get_forms() {
        if (!$this->is_active()) {
            return new WP_Error('handl-404', "WPForms is not active");
        }

        $forms_res = [];
        foreach ((array) wpforms()->form->get('', ['orderby' => 'title', 'order' => 'ASC']) as $form) {
            $forms_res[] = [
                "value" => $form->ID,
                "name" => $form->post_title . " (" . $form->ID . ")"
            ];
        }

        return $forms_res;
    }

    /**
     * Get entries from WPForms
     *
     * @param array $form_ids Form IDs to get entries from
     * @param array $search_criteria Search criteria for entries
     * @return array|WP_Error Array of entries or WP_Error if plugin not active
     */
    public function get_entries($form_ids, $search_criteria) {
        if (!$this->is_active()) {
            return new WP_Error('handl-404', "WPForms plugin is not active");
        }

        $fetched = $this->fetch_submissions($form_ids, $search_criteria, 1, 0);

        if (is_wp_error($fetched)) {
            return $fetched;
        }

        $entries_res = [];
        $param_map   = $this->get_param_map($form_ids);

        foreach ($fetched['rows'] as $row) {
            $cur_data = [];
            $cur_data['date'] = $row['date'];
            $form_map = isset($param_map[(string) $row['form_id']]) ? $param_map[(string) $row['form_id']] : [];

            // Smart tag by field id; stored label for fields the form no longer has.
            foreach ($row['fields'] as $field_id => $field) {
                $field_name_norm = isset($form_map[(string) $field_id])
                    ? $form_map[(string) $field_id]
                    : strtolower(str_replace(" ", "_", $field["name"]));
                if (in_array($field_name_norm, $this->get_fields())) {
                    $cur_data[$field_name_norm] = $field["value"];
                }

                // Look for email field
                if ($field_name_norm === 'email' || $field["type"] === 'email') {
                    $cur_data['email'] = $field["value"];
                }
            }

            // Make sure all required fields are present, even if empty
            foreach ($this->get_fields() as $field) {
                if (!isset($cur_data[$field])) {
                    $cur_data[$field] = '';
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
     * Fetch raw WPForms submissions, paginated, through the entry handler.
     *
     * @param array $form_ids
     * @param array $search_criteria
     * @param int $page
     * @param int $per_page 0 = no limit
     * @return array|WP_Error
     */
    protected function fetch_submissions($form_ids, $search_criteria, $page, $per_page) {
        if (!$this->is_active()) {
            return new WP_Error('handl-404', "WPForms plugin is not active");
        }

        $args = [
            'form_id' => array_map('intval', (array) $form_ids),
            'date'    => [$search_criteria['start_date'], $search_criteria['end_date']],
            'number'  => $per_page > 0 ? $per_page : 0,
            'offset'  => $per_page > 0 ? ($page - 1) * $per_page : 0,
        ];

        $total = (int) wpforms()->entry->get_entries($args, true);

        $rows = [];
        foreach ((array) wpforms()->entry->get_entries($args) as $entry) {
            $rows[] = [
                'date'    => $entry->date,
                'form_id' => $entry->form_id,
                'fields'  => json_decode($entry->fields, true),
            ];
        }

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Field id -> tracked param per form, from the smart tag in the form definition.
     *
     * @param array $form_ids
     * @return array [ form_id => [ field_id => param ] ]
     */
    protected function get_param_map($form_ids) {
        $map = [];

        foreach ($form_ids as $form_id) {
            $form_map = [];
            foreach ($this->form_fields($form_id) as $field_id => $field) {
                $param = is_array($field) ? WPForms_Integration::param_for_field($field) : null;
                if ($param !== null) {
                    $form_map[(string) $field_id] = $param;
                }
            }
            $map[(string) $form_id] = $form_map;
        }

        return $map;
    }

    /**
     * Labels come from the form definition.
     *
     * @param array $form_ids
     * @return array [ form_id => [ field_id => label ] ]
     */
    protected function get_field_label_map($form_ids) {
        $labels = [];

        foreach ($form_ids as $form_id) {
            $form_labels = [];
            foreach ($this->form_fields($form_id) as $field_id => $field) {
                if (isset($field['label'])) {
                    $form_labels[(string) $field_id] = $field['label'];
                }
            }
            $labels[(string) $form_id] = $form_labels;
        }

        return $labels;
    }

    /**
     * @param int|string $form_id
     * @return array field id => field definition; empty if not a WPForms form
     */
    private function form_fields($form_id) {
        $form = wpforms()->form->get((int) $form_id, ['content_only' => true]);
        return (!empty($form['fields']) && is_array($form['fields'])) ? $form['fields'] : [];
    }
}
