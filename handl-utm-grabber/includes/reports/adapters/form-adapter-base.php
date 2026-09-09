<?php
namespace Handl\UtmrabberFree\Reports;
/**
 * Form Adapter Interface
 *
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Abstract class for form adapters
 */
abstract class Form_Adapter_Abstract implements Form_Adapter_Interface {
    public function get_fields() {
        return [
            'email',
            'utm_campaign',
            'utm_source',
            'utm_medium',
            'utm_content',
            'utm_term',
            'traffic_source'
        ];
    }
    public function get_field_labels() {
        return [
            'email' => 'Email Address',
            'utm_campaign' => 'UTM Campaign',
            'utm_source' => 'UTM Source',
            'utm_medium' => 'UTM Medium',
            'utm_content' => 'UTM Content',
            'utm_term' => 'UTM Term',
            'traffic_source' => 'Traffic Source'
        ];
    }
    abstract public function is_active();
    
    abstract public function get_forms();
    
    abstract public function get_entries($form_ids, $search_criteria);

    /**
     * Fetch raw submissions for the given forms, paginated.
     *
     * @param array $form_ids Form IDs to fetch submissions from
     * @param array $search_criteria Search criteria (start_date, end_date)
     * @param int $page 1-based page number
     * @param int $per_page Page size. 0 = no limit (all rows).
     * @return array|WP_Error ['rows' => array, 'total' => int] or WP_Error if plugin not active
     */
    abstract protected function fetch_submissions($form_ids, $search_criteria, $page, $per_page);

    /**
     * Map of field keys to human-readable labels. Overridden by adapters
     * whose entries are keyed by opaque identifiers (e.g. numeric field IDs).
     *
     * @param array $form_ids
     * @return array [ form_id => [ field_key => label ] ]
     */
    protected function get_field_label_map($form_ids) {
        return [];
    }

    /**
     * Get raw, unstructured submissions plus pagination metadata.
     *
     * @param array $form_ids Form IDs to fetch submissions from
     * @param array $search_criteria Search criteria (start_date, end_date)
     * @param int $page 1-based page number
     * @param int $per_page Page size. 0 = no limit (all rows).
     * @return array|WP_Error ['entries' => array, 'field_labels' => array, 'pagination' => array] or WP_Error
     */
    public function get_full_entries($form_ids, $search_criteria, $page, $per_page) {
        $result = $this->fetch_submissions($form_ids, $search_criteria, $page, $per_page);

        if (is_wp_error($result)) {
            return $result;
        }

        $total = (int) $result['total'];

        return [
            'entries'      => $result['rows'],
            'field_labels' => $this->get_field_label_map($form_ids),
            'pagination'   => [
                'page'        => $page,
                'per_page'    => $per_page,
                'total'       => $total,
                'total_pages' => $per_page > 0 ? (int) ceil($total / $per_page) : 1,
            ],
        ];
    }
} 



/**
 * Interface for form adapters
 */
interface Form_Adapter_Interface {
    /**
     * Check if the plugin is active
     *
     * @return bool
     */
    public function is_active();
    
    /**
     * Get forms from the plugin
     *
     * @return array|WP_Error Array of forms or WP_Error if plugin not active or no forms found
     */
    public function get_forms();
    
    /**
     * Get entries from the forms
     *
     * @param array $form_ids Form IDs to get entries from
     * @param array $search_criteria Search criteria for entries
     * @return array|WP_Error Array of entries or WP_Error if plugin not active or no entries found
     */
    public function get_entries($form_ids, $search_criteria);

    /**
     * Get raw, unstructured submissions plus pagination metadata.
     *
     * @param array $form_ids Form IDs to get entries from
     * @param array $search_criteria Search criteria for entries
     * @param int $page 1-based page number
     * @param int $per_page Page size. 0 = no limit (all rows).
     * @return array|WP_Error ['entries' => array, 'pagination' => array] or WP_Error
     */
    public function get_full_entries($form_ids, $search_criteria, $page, $per_page);
    
    /**
     * Get fields to extract from entries
     *
     * @return array Array of fields
     */
    public function get_fields();
    
    /**
     * Get human-readable labels for fields
     *
     * @return array Associative array mapping field names to human-readable labels
     */
    public function get_field_labels();
}

/**
 * Interface for adapters that expose datasets
 */
interface Dataset_Provider_Interface {
    /**
     * Get datasets exposed by the adapter
     *
     * @return array Array of dataset keys and names
     */
    public function get_datasets();

    /**
     * Get a paginated dataset
     *
     * @param string $dataset_key Dataset key to fetch
     * @param array $search_criteria Search criteria for the dataset
     * @param int $page 1-based page number
     * @param int $per_page Page size
     * @return array|WP_Error Dataset rows and pagination metadata or WP_Error
     */
    public function get_dataset($dataset_key, $search_criteria, $page, $per_page);
}
