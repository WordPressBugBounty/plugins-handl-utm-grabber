<?php
namespace Handl\UtmrabberFree\TrackingDoctor;

if ( ! defined( 'ABSPATH' ) ) exit;

/** WooCommerce checkout attribution: samples recent orders for HandL order meta */
class WooCommerce_Check extends Handl_Doctor_Check {

	const ORDER_SAMPLE_SIZE = 10;

	private static $captured_fields = array(
		'utm_source', 'utm_medium', 'utm_term', 'utm_content', 'utm_campaign',
		'gclid', 'handl_original_ref', 'handl_landing_page', 'handl_ip', 'handl_ref', 'handl_url',
	);

	public function get_id() {
		return 'woocommerce';
	}

	public function get_label() {
		return 'WooCommerce checkout';
	}

	public function scope_note() {
		return 'ONLY review WooCommerce checkout and order-meta attribution: order meta fields, recent order samples, and the cookie-to-order flow. The free plugin stores attribution on orders; DISPLAYING it (order-screen metabox, admin order emails, CSV export) and click IDs beyond gclid are premium HandL features — recommend the upgrade for those, do not invent free settings. Do NOT mention form plugins, hidden form fields, Contact Form 7, Gravity Forms, Ninja Forms, or Elementor forms.';
	}

	/** Free stores attribution on orders; premium is what makes it visible. */
	private function premium_fix() {
		return array(
			'label' => 'See attribution on orders',
			'url'   => handl_v3_generate_links( 'tracking_doctor_woocommerce', '', 'tracking_doctor' ),
			'type'  => 'premium',
		);
	}

	public function run() {
		$this->ensure_plugin_functions();

		if ( ! is_plugin_active( 'woocommerce/woocommerce.php' ) ) {
			return $this->build_check( 'skip', 'WooCommerce is not installed.' );
		}

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return $this->build_check(
				'pass',
				'WooCommerce is active and HandL appends UTM cookies to order meta on checkout.',
				array( 'captured_fields' => self::$captured_fields ),
				$this->premium_fix()
			);
		}

		$orders = wc_get_orders(
			array(
				'limit'   => self::ORDER_SAMPLE_SIZE,
				'orderby' => 'date',
				'order'   => 'DESC',
			)
		);
		$orders = is_array( $orders ) ? $orders : array();

		$recent_total    = 0;
		$recent_with_utm = 0;
		$order_stats     = array();

		foreach ( $orders as $order ) {
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
				continue;
			}
			++$recent_total;

			$fields = array();
			foreach ( self::$captured_fields as $field ) {
				$value            = $order->get_meta( $field, true );
				$fields[ $field ] = is_scalar( $value ) && (string) $value !== '';
			}
			$order_stats[] = array(
				'order_id' => (int) $order->get_id(),
				'fields'   => $fields,
			);

			if ( $fields['utm_source'] || $fields['gclid'] ) {
				++$recent_with_utm;
			}
		}

		$details = array(
			'recent_orders_sampled'    => $recent_total,
			'orders_with_attribution'  => $recent_with_utm,
			'captured_fields'          => self::$captured_fields,
			'recent_order_field_stats' => $order_stats,
			// Where premium surfaces this data (free only stores it).
			'premium_display_surfaces' => array( 'Order screen metabox', 'Admin order emails', 'CSV export' ),
		);

		if ( $recent_total > 0 && $recent_with_utm === 0 ) {
			return $this->build_check(
				'warn',
				'Recent orders have no UTM or GCLID order meta. Checkout may be missing attribution cookies.',
				$details,
				array(
					'label' => 'Fix this',
					'url'   => 'https://docs.utmgrabber.com/books/woocommerce-integration',
					'type'  => 'docs',
				)
			);
		}

		return $this->build_check(
			'pass',
			$recent_total > 0
				? sprintf( '%d of %d recent orders include attribution data. Upgrade to see it on order screens, order emails, and exports.', $recent_with_utm, $recent_total )
				: 'WooCommerce is active and HandL appends UTM cookies to order meta on checkout.',
			$details,
			$this->premium_fix()
		);
	}
}
