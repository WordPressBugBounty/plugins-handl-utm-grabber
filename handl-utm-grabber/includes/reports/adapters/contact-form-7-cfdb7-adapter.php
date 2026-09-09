<?php
namespace Handl\UtmrabberFree\Reports;
/**
 * Contact Form 7 Database Addon – CFDB7 Adapter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once dirname( __FILE__ ) . '/form-adapter-base.php';
use Handl\UtmrabberFree\Reports\Form_Adapter_Abstract;
use WP_Error;
use WPCF7_ContactForm;

/**
 * Contact Form 7 Database Addon – CFDB7 adapter implementation
 */
class Contact_Form_7_CFDB7_Adapter extends Form_Adapter_Abstract {
    /**
     * Check if Contact Form 7 and CFDB7 are active
     *
     * @return bool
     */
    public function is_active() {
        return is_plugin_active('contact-form-7/wp-contact-form-7.php') && 
               is_plugin_active('contact-form-cfdb7/contact-form-cfdb-7.php') && 
               class_exists('WPCF7_ContactForm');
    }
    
    /**
     * Get forms from Contact Form 7
     *
     * @return array|WP_Error Array of forms or WP_Error if plugin not active
     */
    public function get_forms() {
        if (!$this->is_active()) {
            return new WP_Error('handl-404', "Contact Form 7 or Contact Form 7 Database Addon – CFDB7 is not active");
        }
        
        $forms = WPCF7_ContactForm::find();
        
        $forms_res = [];
        foreach ($forms as $form) {
            $forms_res[] = [
                "value" => $form->id(), 
                "name" => $form->title()
            ];
        }
        
        return $forms_res;
    }
    
    /**
     * Get entries from Contact Form 7 via CFDB7
     *
     * @param array $form_ids Form IDs to get entries from
     * @param array $search_criteria Search criteria for entries
     * @return array|WP_Error Array of entries or WP_Error if plugin not active
     */
    public function get_entries($form_ids, $search_criteria) {
        if (!$this->is_active()) {
            return new WP_Error('handl-404', "Contact Form 7 or CFDB7 plugin is not active");
        }

        $fetched = $this->fetch_submissions($form_ids, $search_criteria, 1, 0);

        if (is_wp_error($fetched)) {
            return $fetched;
        }

        $entries_res = [];

        foreach ($fetched['rows'] as $row) {
            $data = is_array($row['fields']) ? $row['fields'] : [];
            $cur_data = [];
            $cur_data['date'] = $row["date"];

            // Get email field
            $cur_data['email'] = isset($data["your-email"]) ? $data["your-email"] : '';

            // Get UTM fields
            foreach ($this->get_fields() as $field) {
                $cur_data[$field] = '';
                // Check for direct match or fields starting with the UTM parameter
                foreach ($data as $key => $value) {
                    if ($key === $field || strpos($key, $field) === 0) {
                        $cur_data[$field] = $value;
                        break;
                    }
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
     * Fetch raw CFDB7 submissions, paginated.
     *
     * @param array $form_ids
     * @param array $search_criteria
     * @param int $page
     * @param int $per_page
     * @return array|WP_Error
     */
    protected function fetch_submissions($form_ids, $search_criteria, $page, $per_page) {
        if (!$this->is_active()) {
            return new WP_Error('handl-404', "Contact Form 7 or CFDB7 plugin is not active");
        }

        global $wpdb;

        $start_date = $search_criteria['start_date'];
        $end_date = $search_criteria['end_date'];
        $placeholders = implode(',', array_fill(0, count($form_ids), '%d'));

        $where_args = array_merge(array_map('intval', $form_ids), [$start_date, $end_date]);

        $total = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}db7_forms 
                WHERE form_post_id IN ($placeholders) 
                AND DATE(form_date) >= %s AND DATE(form_date) <= %s",
                $where_args
            )
        );

        $sql = "SELECT * FROM {$wpdb->prefix}db7_forms 
                WHERE form_post_id IN ($placeholders) 
                AND DATE(form_date) >= %s AND DATE(form_date) <= %s
                ORDER BY form_id DESC";
        $query_args = $where_args;

        if ($per_page > 0) {
            $sql .= " LIMIT %d OFFSET %d";
            $query_args = array_merge($query_args, [$per_page, ($page - 1) * $per_page]);
        }

        $results = $wpdb->get_results($wpdb->prepare($sql, $query_args), ARRAY_A);

        $rows = [];
        foreach ($results as $row) {
            $rows[] = [
                'date'    => $row["form_date"],
                'form_id' => $row["form_post_id"],
                'fields'  => unserialize($row["form_value"]),
            ];
        }

        return ['rows' => $rows, 'total' => $total];
    }
} 