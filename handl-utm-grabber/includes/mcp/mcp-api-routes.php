<?php

namespace Handl\UtmrabberFree\MCP;

if (!defined('ABSPATH')) {
    exit;
}

require_once dirname(dirname(__FILE__)) . '/reports/reports-manager.php';

use Handl\UtmrabberFree\Reports\Reports_Manager;
use Handl\UtmrabberFree\Reports\Dataset_Provider_Interface;
use Handl\UtmrabberFree\Reports\Form_Adapter_Factory;
use WP_Error;
use WP_REST_Request;

class API_Routes
{
    const NAMESPACE = 'utmgrabber/v1/mcp';

    public static function init()
    {
        $credentials = get_option('handl_mcp_credentials_free');

        if (empty($credentials) || !is_array($credentials) || empty($credentials['site_secret'])) {
            return;
        }

        $instance = new self();
        $instance->register_routes();
    }

    public function register_routes()
    {
        $permission = array(Signature_Verifier::class, 'verify');

        register_rest_route(self::NAMESPACE, '/health', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'health'),
            'permission_callback' => $permission,
        ));

        register_rest_route(self::NAMESPACE, '/form-plugins', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'form_plugins'),
            'permission_callback' => $permission,
        ));

        register_rest_route(self::NAMESPACE, '/list-forms', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'list_forms'),
            'permission_callback' => $permission,
            'args' => array(
                'selected_form_plugin' => array(
                    'required' => true,
                    'type'     => 'string',
                ),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/get-entries', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'get_entries'),
            'permission_callback' => $permission,
            'args' => array(
                'selected_form_plugin' => array(
                    'required' => true,
                    'type'     => 'string',
                ),
                'selected_form_ids' => array(
                    'required' => true,
                    'type'     => 'array',
                ),
                'selected_start_date' => array(
                    'required' => true,
                    'type'     => 'string',
                ),
                'selected_end_date' => array(
                    'required' => true,
                    'type'     => 'string',
                ),
                'page' => array(
                    'required' => false,
                    'type'     => 'integer',
                    'default'  => 1,
                    'minimum'  => 1,
                ),
                'per_page' => array(
                    'required' => false,
                    'type'     => 'integer',
                    'default'  => 50,
                    'minimum'  => 1,
                    'maximum'  => 100,
                ),
            ),
        ));

        register_rest_route(self::NAMESPACE, '/data-sources', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'data_sources'),
            'permission_callback' => $permission,
        ));

        register_rest_route(self::NAMESPACE, '/get-dataset', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'get_dataset'),
            'permission_callback' => $permission,
            'args'                => array_merge(
                array(
                    'source' => array(
                        'required' => true,
                        'type'     => 'string',
                    ),
                    'dataset' => array(
                        'required' => true,
                        'type'     => 'string',
                    ),
                ),
                $this->dataset_args()
            ),
        ));
    }

    public function health()
    {
        $plugin_data = get_file_data(
            plugin_dir_path(dirname(dirname(__FILE__))) . 'handl-utm-grabber.php',
            array('Version' => 'Version')
        );

        return rest_ensure_response(array(
            'status'         => 'ok',
            'plugin_version' => $plugin_data['Version'] ?? 'unknown',
        ));
    }

    public function form_plugins()
    {
        $manager = new Reports_Manager();
        $plugins = array_filter($manager->get_available_plugins(), function($plugin) {
            $adapter = Form_Adapter_Factory::get_adapter($plugin['key']);
            return !($adapter instanceof Dataset_Provider_Interface);
        });

        return rest_ensure_response(array_values($plugins));
    }

    public function list_forms(WP_REST_Request $request)
    {
        $plugin  = $request->get_param('selected_form_plugin');
        $error   = $this->dataset_source_error($plugin);

        if (is_wp_error($error)) {
            return $error;
        }

        $manager = new Reports_Manager();

        $forms = $manager->get_forms($plugin);

        if (is_wp_error($forms)) {
            return $forms;
        }

        return rest_ensure_response($forms);
    }

    public function get_entries(WP_REST_Request $request)
    {
        $plugin     = $request->get_param('selected_form_plugin');
        $error      = $this->dataset_source_error($plugin);

        if (is_wp_error($error)) {
            return $error;
        }

        $manager    = new Reports_Manager();
        $form_ids   = $request->get_param('selected_form_ids');
        $start_date = $request->get_param('selected_start_date');
        $end_date   = $request->get_param('selected_end_date');
        $page       = max(1, intval($request->get_param('page')));
        $per_page   = min(100, max(1, intval($request->get_param('per_page'))));

        $search_criteria = array(
            'start_date' => $start_date,
            'end_date'   => $end_date,
        );

        $entries = $manager->get_full_entries($plugin, $form_ids, $search_criteria, $page, $per_page);

        if (is_wp_error($entries)) {
            return $entries;
        }

        return rest_ensure_response($entries);
    }

    public function data_sources()
    {
        $manager = new Reports_Manager();
        $sources = array();

        foreach ($manager->get_available_plugins() as $plugin) {
            $adapter = Form_Adapter_Factory::get_adapter($plugin['key']);

            if (!($adapter instanceof Dataset_Provider_Interface)) {
                continue;
            }

            $sources[] = array(
                'source'   => $plugin['key'],
                'name'     => $plugin['pluginName'],
                'isActive' => $plugin['isActive'],
                'datasets' => $adapter->get_datasets(),
            );
        }

        return rest_ensure_response($sources);
    }

    public function get_dataset(WP_REST_Request $request)
    {
        return $this->dataset_response(
            $request->get_param('source'),
            $request->get_param('dataset'),
            $request
        );
    }

    private function dataset_args()
    {
        return array(
            'selected_start_date' => array(
                'required' => true,
                'type'     => 'string',
            ),
            'selected_end_date' => array(
                'required' => true,
                'type'     => 'string',
            ),
            'page' => array(
                'required' => false,
                'type'     => 'integer',
                'default'  => 1,
                'minimum'  => 1,
            ),
            'per_page' => array(
                'required' => false,
                'type'     => 'integer',
                'default'  => 50,
                'minimum'  => 1,
                'maximum'  => 100,
            ),
        );
    }

    private function dataset_response($source, $dataset, WP_REST_Request $request)
    {
        $adapter = Form_Adapter_Factory::get_adapter($source);

        if (is_wp_error($adapter) || !($adapter instanceof Dataset_Provider_Interface)) {
            return new WP_Error(
                'handl-404',
                "{$source} does not expose datasets. Call /data-sources to list available sources.",
                array('status' => 404)
            );
        }

        $page     = max(1, intval($request->get_param('page')));
        $per_page = min(100, max(1, intval($request->get_param('per_page'))));

        $search_criteria = array(
            'start_date' => $request->get_param('selected_start_date'),
            'end_date'   => $request->get_param('selected_end_date'),
        );

        $result = $adapter->get_dataset($dataset, $search_criteria, $page, $per_page);

        if (is_wp_error($result)) {
            return $result;
        }

        return rest_ensure_response(array(
            $dataset     => $result['rows'],
            'pagination' => $result['pagination'],
        ));
    }

    private function dataset_source_error($plugin)
    {
        $adapter = Form_Adapter_Factory::get_adapter($plugin);

        if ($adapter instanceof Dataset_Provider_Interface) {
            return new WP_Error(
                'handl_mcp_dataset_source',
                "{$plugin} is not available via list-forms/get-entries. Use /get-dataset with source={$plugin} instead; call /data-sources to see its datasets.",
                array('status' => 400)
            );
        }

        return null;
    }
}
