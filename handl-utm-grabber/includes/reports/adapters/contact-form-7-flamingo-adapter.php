<?php
namespace Handl\UtmrabberFree\Reports;
/**
 * Contact Form 7 with Flamingo Adapter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once dirname( __FILE__ ) . '/form-adapter-base.php';
use Handl\UtmrabberFree\Reports\Form_Adapter_Abstract;
use WP_Error;
use WPCF7_ContactForm;

/**
 * Contact Form 7 with Flamingo adapter implementation
 */
class Contact_Form_7_Flamingo_Adapter extends Form_Adapter_Abstract {
    /**
     * Check if Contact Form 7 and Flamingo are active
     *
     * @return bool
     */
    public function is_active() {
        return is_plugin_active('contact-form-7/wp-contact-form-7.php') && 
               is_plugin_active('flamingo/flamingo.php') && 
               class_exists('WPCF7_ContactForm') &&
               class_exists('Flamingo_Inbound_Message');
    }
    
    /**
     * Get forms from Contact Form 7
     *
     * @return array|WP_Error Array of forms or WP_Error if plugin not active
     */
    public function get_forms() {
        if (!$this->is_active()) {
            return new WP_Error('handl-404', "Contact Form 7 or Flamingo is not active");
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
     * Get entries from Contact Form 7 via Flamingo
     *
     * @param array $form_ids Form IDs to get entries from
     * @param array $search_criteria Search criteria for entries
     * @return array|WP_Error Array of entries or WP_Error if plugin not active
     */
    public function get_entries($form_ids, $search_criteria) {
        if (!$this->is_active()) {
            return new WP_Error('handl-404', "Contact Form 7 or Flamingo plugin is not active");
        }

        $fetched = $this->fetch_submissions($form_ids, $search_criteria, 1, 0);

        if (is_wp_error($fetched)) {
            return $fetched;
        }

        $entries_res = [];

        foreach ($fetched['rows'] as $row) {
            $cur_data = [];
            $cur_data['date'] = $row['date'];
            $cur_data['email'] = isset($row['from_email']) ? $row['from_email'] : '';

            $message_fields = is_array($row['fields']) ? $row['fields'] : [];

            foreach ($this->get_fields() as $field) {
                if ($field === 'email') {
                    continue;
                }

                $cur_data[$field] = '';

                // Check in the fields array first
                if (isset($message_fields[$field])) {
                    $cur_data[$field] = $message_fields[$field];
                } else {
                    // Check for fields that start with the UTM parameter
                    foreach ($message_fields as $key => $value) {
                        if (strpos($key, $field) === 0) {
                            $cur_data[$field] = $value;
                            break;
                        }
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
     * Fetch raw Flamingo inbound messages, paginated.
     *
     * @param array $form_ids
     * @param array $search_criteria
     * @param int $page
     * @param int $per_page
     * @return array|WP_Error
     */
    protected function fetch_submissions($form_ids, $search_criteria, $page, $per_page) {
        if (!$this->is_active()) {
            return new WP_Error('handl-404', "Contact Form 7 or Flamingo plugin is not active");
        }

        $start_date = $search_criteria['start_date'];
        $end_date = $search_criteria['end_date'];

        // Get channel term IDs for the selected forms, and keep a reverse
        // map (channel term id => CF7 form id) so each message can be traced
        // back to its originating form.
        $channel_ids = [];
        $channel_to_form = [];
        foreach ($form_ids as $form_id) {
            $form = WPCF7_ContactForm::get_instance($form_id);
            if ($form) {
                $post_meta = get_post_meta($form_id, '_flamingo', true);
                if (isset($post_meta['channel'])) {
                    $channel_id = (int) $post_meta['channel'];
                    $channel_ids[] = $channel_id;
                    $channel_to_form[$channel_id] = (string) $form_id;
                }
            }
        }

        if (empty($channel_ids)) {
            return ['rows' => [], 'total' => 0];
        }

        $tax_query = array(
            array(
                'taxonomy' => \Flamingo_Inbound_Message::channel_taxonomy,
                'terms'    => $channel_ids,
                'field'    => 'term_id',
                'operator' => 'IN'
            )
        );
        $date_query = array(
            array(
                'after'     => $start_date,
                'before'    => $end_date,
                'inclusive' => true
            )
        );

        // Total count via a lightweight ids-only WP_Query with the same filters
        $count_query = new \WP_Query(array(
            'post_type'      => \Flamingo_Inbound_Message::post_type,
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => 1,
            'tax_query'      => $tax_query,
            'date_query'     => $date_query,
        ));
        $total = (int) $count_query->found_posts;

        $find_args = array(
            'posts_per_page' => $per_page > 0 ? $per_page : -1,
            'offset'         => $per_page > 0 ? ($page - 1) * $per_page : 0,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'tax_query'      => $tax_query,
            'date_query'     => $date_query,
        );

        $messages = \Flamingo_Inbound_Message::find($find_args);

        $rows = [];
        foreach ($messages as $message) {
            $post_date = get_post_datetime($message->id());

            // Resolve the message back to its CF7 form via its channel term.
            $form_id = 'flamingo';
            $message_channels = wp_get_post_terms(
                $message->id(),
                \Flamingo_Inbound_Message::channel_taxonomy,
                array('fields' => 'ids')
            );
            if (!is_wp_error($message_channels)) {
                foreach ($message_channels as $term_id) {
                    if (isset($channel_to_form[(int) $term_id])) {
                        $form_id = $channel_to_form[(int) $term_id];
                        break;
                    }
                }
            }

            $rows[] = [
                'date'       => $post_date ? $post_date->format('Y-m-d H:i:s') : '',
                'form_id'    => $form_id,
                'from_email' => $message->from_email ?: '',
                'fields'     => $message->fields,
            ];
        }

        return ['rows' => $rows, 'total' => $total];
    }
} 