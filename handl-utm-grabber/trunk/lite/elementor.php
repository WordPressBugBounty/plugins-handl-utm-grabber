<?php
if ( ! defined( 'ABSPATH' ) ) exit;

#we do not need this any more, because \Elementor\Core\DynamicTags\Tag does the same thing, but still do not remove yes for compatibility issues.
 add_filter( "elementor_pro/forms/render/item/hidden", function( $item, $item_index, $form ){
    $lite_params = handl_lite_tracking_params();
    $new_version = true;
    if (isset($item['custom_id']) && $item['custom_id'] != ""){
        $field = $item['custom_id'];
    }elseif (isset($item['_id']) && $item['_id'] != ""){
        $field = $item['_id'];
        $new_version = false;
    }

    // Only process if field is in the lite params list
    if (in_array($field, $lite_params) && isset($_COOKIE[$field]) && $_COOKIE[$field] != ''){
        $item['field_value'] = $_COOKIE[$field];
        if ($new_version)
            $form->add_render_attribute( 'input' . $item_index, 'value', $item['field_value'] );
    }
    return $item;
}, 10, 3 );


if (class_exists('\Elementor\Core\DynamicTags\Tag')) {
	class Cookies extends \Elementor\Core\DynamicTags\Tag {
		public function get_name() {
			return 'cookies';
		}

		public function get_title() {
			return __( 'Parameters', 'elementor-pro' );
		}

		public function get_group() {
			return 'cookie-variables';
		}

		public function get_categories() {
			return [
				\Elementor\Modules\DynamicTags\Module::TEXT_CATEGORY,
				\Elementor\Modules\DynamicTags\Module::NUMBER_CATEGORY,
				\Elementor\Modules\DynamicTags\Module::URL_CATEGORY,
				\Elementor\Modules\DynamicTags\Module::POST_META_CATEGORY,
			];
		}

		protected function _register_controls() {
			$lite_params = handl_lite_tracking_params();
			$options = array_combine($lite_params, $lite_params);

			$this->add_control(
				'cookies',
				[
					'label'   => __( 'Parameter', 'handl-utm-grabber' ),
					'type'    => \Elementor\Controls_Manager::SELECT,
					'options' => $options,
					'default' => 'utm_source',
				]
			);

			$upgrade_url = handl_v3_generate_links( 'HandL_Go_Premium_Link', 'WordPress_FREE', 'elementor_dynamic_tag' );

			$this->add_control(
				'upgrade_notice',
				[
					'type' => \Elementor\Controls_Manager::RAW_HTML,
					'raw'  => '<div style="margin-top: 16px; padding: 14px; background: rgba(59, 130, 246, 0.08); border: 1px solid rgba(59, 130, 246, 0.25); border-radius: 8px;">
						<div style="font-size: 13px; font-weight: 600; color: #3b82f6; margin-bottom: 8px; display: flex; align-items: center; gap: 6px;">
							<span>🚀</span> Need more parameters?
						</div>
						<div style="font-size: 12px; color: #64748b; line-height: 1.5; margin-bottom: 12px;">
							Track <strong style="color: #475569;">fbclid</strong>, <strong style="color: #475569;">msclkid</strong>, <strong style="color: #475569;">landing page</strong>, <strong style="color: #475569;">referrer</strong>, <strong style="color: #475569;">first/last touch</strong>, <strong style="color: #475569;">custom parameters</strong> & more!
						</div>
						<a href="' . esc_url( $upgrade_url ) . '" target="_blank" style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; background: #3b82f6; color: #fff; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 12px; transition: background 0.2s;">
							Upgrade to v3 <span style="font-size: 14px;">→</span>
						</a>
					</div>',
					'content_classes' => 'handl-elementor-upgrade-notice',
				]
			);
		}

		public function render() {
			$settings = $this->get_settings();
			$lite_params = handl_lite_tracking_params();

			if ( empty( $settings['cookies'] ) ) {
				return;
			}

			$param = $settings['cookies'];

			// Only output if parameter is in the allowed lite list
			if ( !in_array($param, $lite_params) ) {
				return;
			}

			$value = isset( $_COOKIE[ $param ] ) ? $_COOKIE[ $param ] : '';

			echo esc_html( $value );
		}
	}

	add_action( 'elementor/dynamic_tags/register_tags', function ( $dynamic_tags ) {

		\Elementor\Plugin::$instance->dynamic_tags->register_group( 'cookie-variables', [
			'title' => 'HandL UTM Grabber v2'
		] );

		// Finally register the tag
		$dynamic_tags->register_tag( 'Cookies' );
	} );
}