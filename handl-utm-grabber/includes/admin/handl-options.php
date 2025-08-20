<?php
namespace Handl\UtmrabberFree\Admin;
// add_action('admin_init', function() {
// 	delete_option("hug_httponly_cookies");
// 	delete_option("hug_append_all");
// 	delete_option("hug_zapier_url");
	// delete_option("hug_zapier_log");
// });
//! Instead of boolean, has to be "1" and "" for falsey due to legacy settings 
function handl_register_legacy_options() {
	// legacy
	register_setting(
		'handl-utm-grabber-settings-group-new',
		'hug_append_all',
		[
			'type'              => 'string',
			'default'           => '',
			'show_in_rest'      => [ 'schema' => [ 'type' => 'string' ] ],
			'description'       => 'Append all UTM parameters to all links',
		],
	);

	register_setting(
		'handl-utm-grabber-settings-group-new',
		'hug_zapier_url',
		[
			'type'              => 'string',
			'default'           => '',
			'show_in_rest'      => [
				'schema' => [ 'type' => 'string', 'format' => 'uri' ],
			],
			'description'       => 'Zapier URL',
		]
	);
	// legacy
	register_setting(
		'handl-utm-grabber-settings-group-new',
		'hug_httponly_cookies',
		[
			'type'              => 'string',
			'default'           => '',
			'show_in_rest'      => [ 'schema' => [ 'type' => 'string' ] ],
			'description'       => 'HTTP Only Cookies',
		]
	);
}
function handl_get_zapier_log() {
	function handl_recursive_json_decode($data, $depth = 0, $max_depth = 10) {
		// Prevent infinite recursion - bail out if we've gone too deep or a circular reference is detected
		if ($depth >= $max_depth) {
			return $data;
		}
		
		if (is_string($data)) {
			$decoded = json_decode($data, true);
			return $decoded !== null ? $decoded : $data;
		}
		if (is_array($data)) {
			$result = [];
			foreach ($data as $key => $value) {
				$result[$key] = handl_recursive_json_decode($value, $depth + 1, $max_depth); 
			}
			return $result;
		}
		if (is_object($data)) {
			$result = [];
			foreach ((array)$data as $key => $value) {
				$result[$key] = handl_recursive_json_decode($value, $depth + 1, $max_depth);
			}
			return $result;
		}
		return $data;
	}
    $response = [];
    
    // Check if user is admin and request is valid
    if (is_admin() && current_user_can('administrator')) {
        $zapier_log = get_option('hug_zapier_log', null);


        $response['success'] = true;
        $response['data'] = handl_recursive_json_decode($zapier_log);
    } else {
        $response['success'] = false;
    }
    
    wp_send_json($response);
    exit();
}
add_action('wp_ajax_handl_get_zapier_log', __NAMESPACE__ . '\handl_get_zapier_log');
// Backward compatibility for old settings
add_action( 'admin_init',    __NAMESPACE__ . '\handl_register_legacy_options' );
add_action( 'rest_api_init', __NAMESPACE__ . '\handl_register_legacy_options', 5 );