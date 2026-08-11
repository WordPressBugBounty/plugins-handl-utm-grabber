<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * GDPR plugin registry
 */

if ( ! function_exists( 'getHandLGDPRPlugins' ) ) {
	function getHandLGDPRPlugins(){
		return get_option( 'handl_gdpr_plugins' ) ? get_option( 'handl_gdpr_plugins' ) : array();
	}
}

if ( ! function_exists( 'getAllGDPRPlugins' ) ) {
	function getAllGDPRPlugins(){
		$plugins = [];
		$plugins = apply_filters('handl_gdpr_add_plugin_support',$plugins);

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$res_plugins = [];
		foreach ($plugins as $plugin){
			$plugin_data = get_plugin_data(WP_PLUGIN_DIR."/".$plugin);
			$res_plugins[$plugin] = $plugin_data;
		}
		return $res_plugins;
	}
}

if ( ! function_exists( 'getHandLGDPRPluginStatus' ) ) {
	function getHandLGDPRPluginStatus($plugin){
		$GDPRParams = getHandLGDPRPlugins();
		return isset($GDPRParams[$plugin]) && $GDPRParams[$plugin] ? true : false;
	}
}
