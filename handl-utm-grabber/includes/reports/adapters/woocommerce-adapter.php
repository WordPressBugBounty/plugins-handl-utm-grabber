<?php
namespace Handl\UtmrabberFree\Reports;
/**
 * WooCommerce Adapter
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once dirname( __FILE__ ) . '/form-adapter-base.php';
use Handl\UtmrabberFree\Reports\Form_Adapter_Abstract;
use Handl\UtmrabberFree\Reports\Dataset_Provider_Interface;
use WP_Error;

/**
 * WooCommerce adapter implementation
 */
class WooCommerce_Adapter extends Form_Adapter_Abstract implements Dataset_Provider_Interface {
    /**
     * Check if WooCommerce is active
     *
     * @return bool
     */
    public function is_active() {
        return is_plugin_active('woocommerce/woocommerce.php');
    }

    /**
     * Get "forms" from WooCommerce - for WooCommerce this is just a single option representing all orders
     *
     * @return array|WP_Error Array of forms or WP_Error if plugin not active
     */
    public function get_forms() {
        if (!$this->is_active()) {
            return new WP_Error('handl-404', "WooCommerce is not active");
        }

        // WooCommerce doesn't have forms, so we just return a single option for all orders
        return [
            [
                "value" => "all",
                "name" => "All Orders"
            ]
        ];
    }

    /**
     * Get datasets exposed by WooCommerce
     *
     * @return array Array of dataset keys and names
     */
    public function get_datasets() {
        return [
            ['key' => 'orders', 'name' => 'Orders'],
            ['key' => 'customers', 'name' => 'Customers'],
        ];
    }

    /**
     * Get a paginated WooCommerce dataset
     *
     * @param string $dataset_key Dataset key to fetch
     * @param array $search_criteria Search criteria for the dataset
     * @param int $page 1-based page number
     * @param int $per_page Page size
     * @return array|WP_Error Dataset rows and pagination metadata or WP_Error
     */
    public function get_dataset($dataset_key, $search_criteria, $page, $per_page) {
        $dataset_keys = wp_list_pluck($this->get_datasets(), 'key');

        if (!in_array($dataset_key, $dataset_keys, true)) {
            return new WP_Error(
                'handl-404',
                "Unknown WooCommerce dataset '{$dataset_key}'. Available datasets: " . implode(', ', $dataset_keys) . ".",
                ['status' => 404]
            );
        }

        if (!$this->is_active()) {
            return new WP_Error(
                'handl-404',
                "WooCommerce plugin is not active",
                ['status' => 404]
            );
        }

        if ($dataset_key === 'orders') {
            return $this->get_orders_dataset($search_criteria, $page, $per_page);
        }

        return $this->get_customers_dataset($search_criteria, $page, $per_page);
    }

    /**
     * Get entries (orders) normalized to email + UTM fields (legacy reports path).
     *
     * @param array $form_ids Form IDs to get entries from (ignored for WooCommerce)
     * @param array $search_criteria Search criteria for entries
     * @return array|WP_Error Array of entries or WP_Error if plugin not active
     */
    public function get_entries($form_ids, $search_criteria) {
        if (!$this->is_active()) {
            return new WP_Error('handl-404', "WooCommerce plugin is not active");
        }

        $result = $this->query_orders($search_criteria, 1, 0);

        $entries_res = [];

        foreach ($result['orders'] as $order) {
            // Skip refunds and other non-order objects.
            if (!$order instanceof \WC_Order) {
                continue;
            }

            $fields = [];
            $fields['email'] = $order->get_billing_email();

            foreach ($this->get_fields() as $field) {
                if ($field !== 'email') {
                    $fields[$field] = $order->get_meta($field) ?: "";
                }
            }

            $entries_res[] = array_merge(['date' => $order->get_date_created()->date('Y-m-d H:i:s')], $fields);
        }

        return [
            'entries' => $entries_res,
            'field_labels' => $this->get_field_labels()
        ];
    }

    /**
     * Fetch orders, paginated, with the full raw order as fields
     * plus top-level UTM keys (MCP path).
     *
     * @param array $form_ids
     * @param array $search_criteria
     * @param int $page
     * @param int $per_page
     * @return array|WP_Error
     */
    protected function fetch_submissions($form_ids, $search_criteria, $page, $per_page) {
        if (!$this->is_active()) {
            return new WP_Error('handl-404', "WooCommerce plugin is not active");
        }

        $result = $this->query_orders($search_criteria, $page, $per_page);

        $rows = [];
        foreach ($result['orders'] as $order) {
            // Skip refunds and other non-order objects.
            if (!$order instanceof \WC_Order) {
                continue;
            }

            $rows[] = [
                'date'    => $order->get_date_created()->date('Y-m-d H:i:s'),
                'form_id' => 'all',
                'fields'  => $this->enriched_order_fields($order, $this->get_fields()),
            ];
        }

        return ['rows' => $rows, 'total' => (int) $result['total']];
    }

    /**
     * Normalized order data with the given UTM keys overlaid on top.
     *
     * @param \WC_Order $order
     * @param array $utm_fields
     * @return array
     */
    private function enriched_order_fields($order, $utm_fields) {
        $fields = $this->normalize_wc_data($order->get_data());

        // Partial refunds keep status and total unchanged; net revenue needs the refunded amount.
        $fields['total_refunded'] = wc_format_decimal($order->get_total_refunded(), wc_get_price_decimals());

        foreach ($utm_fields as $field) {
            if ($field !== 'email') {
                $fields[$field] = $order->get_meta($field) ?: "";
            }
        }

        return $fields;
    }

    /**
     * Query orders for a date range, paginated.
     *
     * @param array $search_criteria
     * @param int $page
     * @param int $per_page 0 = no limit
     * @return array ['orders' => \WC_Order[], 'total' => int]
     */
    private function query_orders($search_criteria, $page, $per_page) {
        $result = wc_get_orders(array(
            'type'         => 'shop_order',
            'limit'        => $per_page > 0 ? $per_page : -1,
            'page'         => $page,
            'paginate'     => true,
            'orderby'      => 'date',
            'order'        => 'DESC',
            'date_created' => $search_criteria['start_date'] . '...' . $search_criteria['end_date'],
        ));

        return ['orders' => $result->orders, 'total' => (int) $result->total];
    }

    /**
     * Orders dataset: enriched order rows for a date range, paginated.
     *
     * @param array $search_criteria
     * @param int $page
     * @param int $per_page
     * @return array
     */
    private function get_orders_dataset($search_criteria, $page, $per_page) {
        $result = $this->query_orders($search_criteria, $page, $per_page);
        $total = (int) $result['total'];

        // wc_get_orders reports 0 for pages past the end, which reads as "no orders at all".
        if (!$total && $page > 1) {
            $total = (int) $this->query_orders($search_criteria, 1, 1)['total'];
        }

        $utm_fields = generateUTMFields();
        $rows = [];

        foreach ($result['orders'] as $order) {
            // Skip refunds and other non-order objects.
            if (!$order instanceof \WC_Order) {
                continue;
            }

            $date = $order->get_date_created();
            $rows[] = [
                'date'   => $date ? $date->date('Y-m-d H:i:s') : '',
                'fields' => $this->enriched_order_fields($order, $utm_fields),
            ];
        }

        return $this->dataset_result($rows, $total, $page, $per_page);
    }

    /**
     * Customers dataset: users registered in the date range, paginated.
     *
     * @param array $search_criteria
     * @param int $page
     * @param int $per_page
     * @return array
     */
    private function get_customers_dataset($search_criteria, $page, $per_page) {
        $query = new \WP_User_Query([
            'date_query' => [
                [
                    'after'     => $search_criteria['start_date'] . ' 00:00:00',
                    'before'    => $search_criteria['end_date'] . ' 23:59:59',
                    'inclusive' => true,
                    'column'    => 'user_registered',
                ],
            ],
            'orderby'     => 'registered',
            'order'       => 'DESC',
            'number'      => $per_page,
            'offset'      => ($page - 1) * $per_page,
            'count_total' => true,
        ]);

        $users = $query->get_results();

        update_meta_cache('user', wp_list_pluck($users, 'ID'));

        $utm_fields = generateUTMFields();
        $rows = [];

        foreach ($users as $user) {
            $data = (new \WC_Customer($user->ID))->get_data();
            // Raw user meta can carry session tokens and other plugins' private data.
            unset($data['meta_data']);

            $fields = $this->normalize_wc_data($data);
            $fields['date_last_active'] = get_user_meta($user->ID, 'wc_last_active', true) ?: '';

            foreach ($utm_fields as $field) {
                $fields[$field] = get_user_meta($user->ID, $field, true) ?: '';
            }

            $rows[] = [
                'date'   => $user->user_registered,
                'fields' => $fields,
            ];
        }

        return $this->dataset_result($rows, (int) $query->get_total(), $page, $per_page);
    }

    /**
     * Build the common dataset response shape.
     *
     * @param array $rows
     * @param int $total
     * @param int $page
     * @param int $per_page
     * @return array
     */
    private function dataset_result($rows, $total, $page, $per_page) {
        return [
            'rows'       => $rows,
            'pagination' => [
                'page'        => $page,
                'per_page'    => $per_page,
                'total'       => $total,
                'total_pages' => $per_page > 0 ? (int) ceil($total / $per_page) : 1,
            ],
        ];
    }

    /**
     * Recursively convert WooCommerce objects (WC_Data, WC_Meta_Data,
     * WC_DateTime don't json_encode cleanly) into plain values.
     *
     * @param mixed $value
     * @return mixed
     */
    private function normalize_wc_data($value) {
        if ($value instanceof \WC_DateTime) {
            return $value->date('Y-m-d H:i:s');
        }

        if ($value instanceof \WC_Meta_Data || $value instanceof \WC_Data) {
            return $this->normalize_wc_data($value->get_data());
        }

        if (is_array($value)) {
            if ($this->is_meta_data_list($value)) {
                return $this->flatten_meta_data($value);
            }
            return array_map([$this, 'normalize_wc_data'], $value);
        }

        return $value;
    }

    /**
     * Whether the array is a non-empty list of WC_Meta_Data objects.
     *
     * @param array $value
     * @return bool
     */
    private function is_meta_data_list($value) {
        if (empty($value)) {
            return false;
        }
        foreach ($value as $item) {
            if (!$item instanceof \WC_Meta_Data) {
                return false;
            }
        }
        return true;
    }

    /**
     * Flatten meta into a {key: value} map, dropping internal _-prefixed keys.
     *
     * @param \WC_Meta_Data[] $meta_list
     * @return array
     */
    private function flatten_meta_data($meta_list) {
        $map = [];
        foreach ($meta_list as $meta) {
            $data = $meta->get_data();
            if (strpos($data['key'], '_') === 0) {
                continue;
            }
            $map[$data['key']] = $this->normalize_wc_data($data['value']);
        }
        return $map;
    }
}
