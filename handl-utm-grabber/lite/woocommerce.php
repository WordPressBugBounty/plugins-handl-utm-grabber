<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WooCommerce order attribution (lite): copies HandL cookies onto order meta
 * at checkout.
 */

if ( ! function_exists( 'handl_woo_is_hpos_enabled' ) ) {
	function handl_woo_is_hpos_enabled() {
		return class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
	}
}

if ( ! function_exists( 'HandLUTMGrabberWooCommerceUpdateOrderMeta' ) ) {
	function HandLUTMGrabberWooCommerceUpdateOrderMeta( $order_id ) {
		$fields = array('utm_source','utm_medium','utm_term', 'utm_content', 'utm_campaign', 'gclid', 'handl_original_ref', 'handl_landing_page', 'handl_ip', 'handl_ref', 'handl_url');

		// Under HPOS, order meta lives in Woo's own tables; post-meta writes would be invisible.
		$order = handl_woo_is_hpos_enabled() && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

		foreach ($fields as $field){
			if (isset($_COOKIE[$field]) && $_COOKIE[$field] != ''){
				if ( $order ) {
					$order->update_meta_data( $field, esc_attr($_COOKIE[$field]) );
				} else {
					update_post_meta( $order_id, $field, esc_attr($_COOKIE[$field]) );
				}
			}
		}

		if ( $order ) {
			$order->save();
		}
	}
}
add_action('woocommerce_checkout_update_order_meta', 'HandLUTMGrabberWooCommerceUpdateOrderMeta');

// The blocks checkout (Store API) never fires the classic hook; it passes the order object.
if ( ! function_exists( 'HandLUTMGrabberWooCommerceStoreApiUpdateOrderMeta' ) ) {
	function HandLUTMGrabberWooCommerceStoreApiUpdateOrderMeta( $order ) {
		if ( is_object( $order ) && method_exists( $order, 'get_id' ) ) {
			HandLUTMGrabberWooCommerceUpdateOrderMeta( $order->get_id() );
		}
	}
}
add_action('woocommerce_store_api_checkout_update_order_meta', 'HandLUTMGrabberWooCommerceStoreApiUpdateOrderMeta');
