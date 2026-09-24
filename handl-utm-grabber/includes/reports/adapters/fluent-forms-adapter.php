<?php
namespace Handl\UtmrabberFree\Reports;
/**
 * Fluent Forms Adapter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once dirname( __FILE__ ) . '/form-adapter-base.php';
require_once dirname( __FILE__ ) . '/../../integrations/class-integration.php';
require_once dirname( __FILE__ ) . '/../../integrations/fluent-forms/class-fluent-forms-integration.php';
use Handl\UtmrabberFree\Reports\Form_Adapter_Abstract;
use Handl\UtmrabberFree\Integrations\Fluent_Forms_Integration;
use WP_Error;

/**
 * Fluent Forms adapter implementation
 */
class Fluent_Forms_Adapter extends Form_Adapter_Abstract {
    /**
     * Check if Fluent Forms is active
     *
     * @return bool
     */
    public function is_active() {
        return is_plugin_active('fluentform/fluentform.php') && function_exists('wpFluent');
    }

    /**
     * Get forms from Fluent Forms
     *
     * @return array|WP_Error Array of forms or WP_Error if plugin not active
     */
    public function get_forms() {
        if (!$this->is_active()) {
            return new WP_Error('handl-404', "Fluent Forms is not active");
        }

        $forms_res = [];

        try {
            $forms = wpFluent()->table('fluentform_forms')->where('status', 'published')->get();

            if (empty($forms)) {
                return new WP_Error('handl-404', "No forms found");
            }

            foreach ($forms as $form) {
                $forms_res[] = [
                    "value" => $form->id,
                    "name" => $form->title . " (" . $form->id . ")"
                ];
            }

            return $forms_res;
        } catch (\Exception $e) {
            return new WP_Error('handl-500', $e->getMessage());
        }
    }

    /**
     * Get entries from Fluent Forms
     *
     * @param array $form_ids Form IDs to get entries from
     * @param array $search_criteria Search criteria for entries
     * @return array|WP_Error Array of entries or WP_Error if plugin not active
     */
    public function get_entries($form_ids, $search_criteria) {
        if (!$this->is_active()) {
            return new WP_Error('handl-404', "Fluent Forms plugin is not active");
        }

        $fetched = $this->fetch_submissions($form_ids, $search_criteria, 1, 0);

        if (is_wp_error($fetched)) {
            return $fetched;
        }

        $entries_res = [];
        $name_map    = $this->get_name_map($form_ids);

        foreach ($fetched['rows'] as $row) {
            $cur_data = [];
            $cur_data['date'] = $row['date'];

            $response_data = is_array($row['fields']) ? $row['fields'] : [];
            $form_map      = isset($name_map[(string) $row['form_id']]) ? $name_map[(string) $row['form_id']] : [];

            // Smart code by field name; the field name itself for fields the form no longer has.
            foreach ($this->get_fields() as $field) {
                $name = isset($form_map[$field]) ? $form_map[$field] : $field;
                $cur_data[$field] = isset($response_data[$name]) && is_scalar($response_data[$name]) ? (string) $response_data[$name] : "";
            }

            $entries_res[] = $cur_data;
        }

        return [
            'entries' => $entries_res,
            'field_labels' => $this->get_field_labels()
        ];
    }

    /**
     * Fetch raw Fluent Forms submissions, paginated.
     *
     * @param array $form_ids
     * @param array $search_criteria
     * @param int $page
     * @param int $per_page
     * @return array|WP_Error
     */
    protected function fetch_submissions($form_ids, $search_criteria, $page, $per_page) {
        if (!$this->is_active()) {
            return new WP_Error('handl-404', "Fluent Forms plugin is not active");
        }

        $start_date = isset($search_criteria['start_date']) ? $search_criteria['start_date'] : '';
        $end_date = isset($search_criteria['end_date']) ? $search_criteria['end_date'] : '';

        try {
            $build_query = function () use ($form_ids, $start_date, $end_date) {
                return wpFluent()->table('fluentform_submissions')
                    ->whereIn('form_id', $form_ids)
                    ->where('created_at', '>=', $start_date . ' 00:00:00')
                    ->where('created_at', '<=', $end_date . ' 23:59:59');
            };

            $total = (int) $build_query()->count();

            $query = $build_query()->orderBy('id', 'DESC');

            if ($per_page > 0) {
                $query->limit($per_page)->offset(($page - 1) * $per_page);
            }

            $submissions = $query->get();

            $rows = [];
            foreach ($submissions as $submission) {
                $rows[] = [
                    'date'    => $submission->created_at,
                    'form_id' => $submission->form_id,
                    'fields'  => json_decode($submission->response, true),
                ];
            }

            return ['rows' => $rows, 'total' => $total];
        } catch (\Exception $e) {
            return new WP_Error('handl-500', $e->getMessage());
        }
    }

    /**
     * Tracked param -> field name per form, from the `{cookie.param}` value in the form definition.
     *
     * @param array $form_ids
     * @return array [ form_id => [ param => field_name ] ]
     */
    protected function get_name_map($form_ids) {
        $map = [];

        foreach ($form_ids as $form_id) {
            $form_map = [];
            foreach ($this->form_fields($form_id) as $field) {
                $param = Fluent_Forms_Integration::param_for_field($field);
                if ($param !== null && !isset($form_map[$param]) && isset($field['attributes']['name'])) {
                    $form_map[$param] = (string) $field['attributes']['name'];
                }
            }
            $map[(string) $form_id] = $form_map;
        }

        return $map;
    }

    /**
     * Labels come from the form definition (hidden fields only carry an admin label).
     *
     * @param array $form_ids
     * @return array [ form_id => [ field_name => label ] ]
     */
    protected function get_field_label_map($form_ids) {
        $labels = [];

        foreach ($form_ids as $form_id) {
            $form_labels = [];
            foreach ($this->form_fields($form_id) as $field) {
                if (!isset($field['attributes']['name'])) {
                    continue;
                }
                $label = !empty($field['settings']['admin_field_label'])
                    ? $field['settings']['admin_field_label']
                    : (isset($field['settings']['label']) ? $field['settings']['label'] : '');
                if ($label !== '') {
                    $form_labels[(string) $field['attributes']['name']] = $label;
                }
            }
            $labels[(string) $form_id] = $form_labels;
        }

        return $labels;
    }

    /**
     * @param int|string $form_id
     * @return array[] flattened field definitions; empty if not a Fluent Forms form
     */
    private function form_fields($form_id) {
        $form = Fluent_Forms_Integration::load_form($form_id);
        $data = $form ? json_decode((string) $form->form_fields, true) : null;
        return is_array($data) ? Fluent_Forms_Integration::fields_of($data) : [];
    }
}
